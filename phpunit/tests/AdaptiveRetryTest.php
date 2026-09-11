<?php
/**
 * Tests for adaptive conversion retries.
 *
 * @package WebberZone\Image_Optimizer
 */

use WebberZone\Image_Optimizer\Attachment_Meta;
use WebberZone\Image_Optimizer\Capabilities;
use WebberZone\Image_Optimizer\Converter;
use WebberZone\Image_Optimizer\Drivers\Driver;
use WebberZone\Image_Optimizer\Util\Helpers;

/**
 * Deterministic driver for exercising the adaptive retry path.
 */
class WZIO_Retry_Test_Driver extends Driver
{
    /**
     * Sizes returned by conversion attempts.
     *
     * @var array<int, int>
     */
    public static $sizes = array();

    /**
     * Arguments received by conversion attempts.
     *
     * @var array<int, array<string, mixed>>
     */
    public static $calls = array();

    /**
     * Whether the driver rejects output at the supplied byte ceiling.
     *
     * @var bool
     */
    public static $honours_limit = true;

    /**
     * Reset the driver between tests.
     *
     * @param array<int, int> $sizes         Simulated output sizes.
     * @param bool            $honours_limit Whether to reject oversized output.
     */
    public static function reset( array $sizes = array(), $honours_limit = true )
    {
        self::$sizes         = $sizes;
        self::$calls         = array();
        self::$honours_limit = $honours_limit;
    }

    /**
     * The test driver is always available.
     */
    public static function is_available(): bool
    {
        return true;
    }

    /**
     * Driver name.
     */
    public static function get_name(): string
    {
        return 'retry-test';
    }

    /**
     * WebP and AVIF are simulated.
     *
     * @param string $format Target format.
     */
    public function supports( string $format ): bool
    {
        return in_array($format, array( 'webp', 'avif' ), true);
    }

    /**
     * Simulate atomic acceptance or rejection by output size.
     *
     * @param string               $source      Source path.
     * @param string               $destination Destination path.
     * @param string               $format      Target format.
     * @param array<string, mixed> $args        Conversion arguments.
     * @return true|WP_Error Conversion result.
     */
    public function convert( string $source, string $destination, string $format, array $args )
    {
        $args = $this->parse_args($args);

        // Capability probes do not set a byte ceiling and are not test attempts.
        if (0 === $args['max_bytes'] ) {
            return true;
        }

        $args['format'] = $format;
        self::$calls[]  = $args;
        $bytes          = array_shift(self::$sizes);

        if (! is_int($bytes) ) {
            return new WP_Error('wzio_test_no_result', 'No simulated result was configured.');
        }

        if (self::$honours_limit && $bytes >= $args['max_bytes'] ) {
            return new WP_Error('wzio_encode_larger', 'The converted image was not small enough to keep.');
        }

        file_put_contents($destination, str_repeat('x', $bytes));
        clearstatcache(true, $destination);

        return true;
    }
}

/**
 * Adaptive retry behavior with a deterministic encoder.
 */
class AdaptiveRetryTest extends WP_UnitTestCase
{
    /**
     * Source image path.
     *
     * @var string
     */
    private $source = '';

    /**
     * Set up.
     */
    public function set_up()
    {
        parent::set_up();

        $binary       = base64_decode(Capabilities::PROBE_IMAGE, true);
        $temp         = wp_tempnam('wzio-retry-source');
        $this->source = $temp ? $temp . '.png' : '';

        if ($temp) {
            Helpers::delete_file($temp);
        }

        if (false === $binary || '' === $this->source || false === file_put_contents($this->source, $binary) ) {
            $this->fail('Could not create the retry test image.');
        }

        wzio_update_option('sidecar_naming', 'append');

        add_filter('wzio_driver_classes', array( __CLASS__, 'retry_driver_classes' ));
        Capabilities::flush();
        Capabilities::get(true);
    }

    /**
     * Tear down.
     */
    public function tear_down()
    {
        Helpers::delete_file(Helpers::sidecar_path($this->source, 'webp'));
        Helpers::delete_file(Helpers::sidecar_path($this->source, 'avif'));
        Helpers::delete_file($this->source);

        remove_filter('wzio_driver_classes', array( __CLASS__, 'retry_driver_classes' ));
        remove_filter('wzio_conversion_retry_step', array( __CLASS__, 'twenty_point_retry_step' ));
        remove_filter('wzio_conversion_retry_step', array( __CLASS__, 'eighty_one_point_retry_step' ));
        remove_filter('wzio_conversion_retry_step', array( __CLASS__, 'zero_retry_step' ));
        Capabilities::flush();

        parent::tear_down();
    }

    /**
     * Supply only the deterministic retry driver.
     *
     * @return array<int, class-string<Driver>> Driver classes.
     */
    public static function retry_driver_classes()
    {
        return array( WZIO_Retry_Test_Driver::class );
    }

    /**
     * Change the retry reduction for filter coverage.
     *
     * @return int Quality points.
     */
    public static function twenty_point_retry_step()
    {
        return 20;
    }

    /**
     * A step large enough to push the retry under the floor.
     *
     * @return int Quality points.
     */
    public static function eighty_one_point_retry_step()
    {
        return 81;
    }

    /**
     * The documented way to switch the retry off.
     *
     * @return int Quality points.
     */
    public static function zero_retry_step()
    {
        return 0;
    }

    /**
     * Conversion arguments shared by the retry tests.
     *
     * @return array<string, mixed> Conversion arguments.
     */
    private static function conversion_args()
    {
        return Converter::get_args(
            array(
                'formats'    => array( 'webp' ),
                'force'      => true,
                'lossless'   => false,
                'min_saving' => 5,
                'quality'    => array( 'webp' => 82 ),
            )
        );
    }

    /**
     * A successful first attempt records its configured quality.
     */
    public function test_the_first_attempt_records_its_effective_quality()
    {
        $max_bytes = (int) ( filesize($this->source) * 0.95 );

        WZIO_Retry_Test_Driver::reset(array( $max_bytes - 1 ));

        $record = Converter::convert_file($this->source, self::conversion_args());

        $this->assertCount(1, WZIO_Retry_Test_Driver::$calls);
        $this->assertSame(82, $record['webp']['quality']);
    }

    /**
     * When both attempts are rejected the usable old copy and its quality survive.
     */
    public function test_a_usable_existing_copy_keeps_its_recorded_quality()
    {
        $max_bytes = (int) ( filesize($this->source) * 0.95 );
        $sidecar   = Helpers::sidecar_path($this->source, 'webp');

        file_put_contents($sidecar, str_repeat('x', $max_bytes - 1));
        touch($sidecar, time() + 1);
        WZIO_Retry_Test_Driver::reset(array( $max_bytes + 1, $max_bytes + 1 ));

        $record = Converter::convert_file(
            $this->source,
            self::conversion_args(),
            array(
                'webp' => array(
                    'bytes'   => $max_bytes - 1,
                    'quality' => 67,
                ),
            )
        );

        $this->assertCount(2, WZIO_Retry_Test_Driver::$calls);
        $this->assertSame($max_bytes - 1, $record['webp']['bytes']);
        $this->assertSame(67, $record['webp']['quality']);
    }

    /**
     * An inherited sidecar does not hide the retry: if the retry wins, its file and
     * its quality are what get recorded.
     */
    public function test_an_inherited_sidecar_still_lets_the_retry_win()
    {
        $max_bytes = (int) ( filesize($this->source) * 0.95 );
        $sidecar   = Helpers::sidecar_path($this->source, 'webp');

        // A sidecar written by a version that recorded no quality at all.
        file_put_contents($sidecar, str_repeat('x', $max_bytes - 1));
        touch($sidecar, time() + 1);
        WZIO_Retry_Test_Driver::reset(array( $max_bytes + 1, $max_bytes - 20 ));

        $record = Converter::convert_file(
            $this->source,
            self::conversion_args(),
            array( 'webp' => array( 'bytes' => $max_bytes - 1 ) )
        );

        $this->assertCount(2, WZIO_Retry_Test_Driver::$calls);
        $this->assertSame($max_bytes - 20, $record['webp']['bytes']);
        $this->assertSame(70, $record['webp']['quality']);
    }

    /**
     * The retry never drops below the floor, however large the step.
     */
    public function test_the_retry_is_floored_at_the_minimum_quality()
    {
        $max_bytes = (int) ( filesize($this->source) * 0.95 );

        add_filter('wzio_conversion_retry_step', array( __CLASS__, 'eighty_one_point_retry_step' ));
        WZIO_Retry_Test_Driver::reset(array( $max_bytes + 1, $max_bytes - 1 ));

        $record = Converter::convert_file($this->source, self::conversion_args());

        $this->assertSame(
            array( 82, Converter::MIN_RETRY_QUALITY ),
            array_column(WZIO_Retry_Test_Driver::$calls, 'quality')
        );
        $this->assertSame(Converter::MIN_RETRY_QUALITY, $record['webp']['quality']);
    }

    /**
     * A zero step disables the retry, as the filter documents.
     */
    public function test_a_zero_step_disables_the_retry()
    {
        $max_bytes = (int) ( filesize($this->source) * 0.95 );

        add_filter('wzio_conversion_retry_step', array( __CLASS__, 'zero_retry_step' ));
        WZIO_Retry_Test_Driver::reset(array( $max_bytes + 1, $max_bytes - 1 ));

        $record = Converter::convert_file($this->source, self::conversion_args());

        $this->assertCount(1, WZIO_Retry_Test_Driver::$calls);
        $this->assertSame('larger', $record['webp']['skip']);
        $this->assertSame(82, $record['webp']['quality']);
    }

    /**
     * The default step is a share of the configured quality, not a flat number, so
     * formats whose scales differ drop by the same proportion.
     */
    public function test_the_default_step_is_proportional_to_the_configured_quality()
    {
        $max_bytes = (int) ( filesize($this->source) * 0.95 );
        $args      = Converter::get_args(
            array(
                'formats'    => array( 'avif' ),
                'force'      => true,
                'lossless'   => false,
                'min_saving' => 5,
                'quality'    => array( 'avif' => 50 ),
            )
        );

        WZIO_Retry_Test_Driver::reset(array( $max_bytes + 1, $max_bytes - 1 ));

        $record = Converter::convert_file($this->source, $args);

        // 15% of 50 is 8, where 15% of WebP's 82 is 12.
        $this->assertSame(array( 50, 42 ), array_column(WZIO_Retry_Test_Driver::$calls, 'quality'));
        $this->assertSame(42, $record['avif']['quality']);

        Helpers::delete_file(Helpers::sidecar_path($this->source, 'avif'));
    }

    /**
     * A `larger` skip recorded before the retry existed gets one more chance.
     */
    public function test_a_skip_recorded_before_the_retry_is_attempted_once_more()
    {
        $max_bytes = (int) ( filesize($this->source) * 0.95 );

        WZIO_Retry_Test_Driver::reset(array( $max_bytes + 1, $max_bytes - 1 ));

        $record = Converter::convert_file(
            $this->source,
            Converter::get_args(
                array(
                    'formats'    => array( 'webp' ),
                    'force'      => false,
                    'lossless'   => false,
                    'min_saving' => 5,
                    'quality'    => array( 'webp' => 82 ),
                )
            ),
            array( 'webp' => array( 'skip' => 'larger' ) )
        );

        $this->assertCount(2, WZIO_Retry_Test_Driver::$calls);
        $this->assertSame($max_bytes - 1, $record['webp']['bytes']);
        $this->assertSame(70, $record['webp']['quality']);
    }

    /**
     * Once the retry has recorded its quality the file settles for good.
     */
    public function test_a_skip_that_already_records_a_quality_is_not_attempted_again()
    {
        WZIO_Retry_Test_Driver::reset(array());

        $record = Converter::convert_file(
            $this->source,
            Converter::get_args(
                array(
                    'formats'    => array( 'webp' ),
                    'force'      => false,
                    'lossless'   => false,
                    'min_saving' => 5,
                    'quality'    => array( 'webp' => 82 ),
                )
            ),
            array(
                'webp' => array(
                    'skip'    => 'larger',
                    'quality' => 70,
                ),
            )
        );

        $this->assertCount(0, WZIO_Retry_Test_Driver::$calls);
        $this->assertSame('larger', $record['webp']['skip']);
    }

    /**
     * A larger encode is retried once and records the quality that succeeded.
     */
    public function test_a_larger_result_is_retried_once_at_lower_quality()
    {
        $max_bytes = (int) ( filesize($this->source) * 0.95 );

        WZIO_Retry_Test_Driver::reset(array( $max_bytes, $max_bytes - 1 ));

        $record = Converter::convert_file($this->source, self::conversion_args());

        $this->assertCount(2, WZIO_Retry_Test_Driver::$calls);
        $this->assertSame(array( 82, 70 ), array_column(WZIO_Retry_Test_Driver::$calls, 'quality'));
        $this->assertSame($max_bytes - 1, $record['webp']['bytes']);
        $this->assertSame(70, $record['webp']['quality']);
        $this->assertLessThan($max_bytes, filesize(Helpers::sidecar_path($this->source, 'webp')));
    }

    /**
     * The converter backstops extension drivers that write an oversized file but
     * return success instead of the standard size-limit error.
     */
    public function test_an_oversized_success_from_a_custom_driver_is_retried()
    {
        $max_bytes = (int) ( filesize($this->source) * 0.95 );

        WZIO_Retry_Test_Driver::reset(array( $max_bytes + 1, $max_bytes - 1 ), false);

        $record = Converter::convert_file($this->source, self::conversion_args());

        $this->assertCount(2, WZIO_Retry_Test_Driver::$calls);
        $this->assertSame(array( 82, 70 ), array_column(WZIO_Retry_Test_Driver::$calls, 'quality'));
        $this->assertSame($max_bytes - 1, $record['webp']['bytes']);
        $this->assertTrue($record['webp']['reduced']);
    }

    /**
     * The retry step can be changed without allowing more than one retry.
     */
    public function test_the_retry_step_is_filterable()
    {
        $max_bytes = (int) ( filesize($this->source) * 0.95 );

        add_filter('wzio_conversion_retry_step', array( __CLASS__, 'twenty_point_retry_step' ));
        WZIO_Retry_Test_Driver::reset(array( $max_bytes, $max_bytes - 1 ));

        $record = Converter::convert_file($this->source, self::conversion_args());

        $this->assertCount(2, WZIO_Retry_Test_Driver::$calls);
        $this->assertSame(array( 82, 62 ), array_column(WZIO_Retry_Test_Driver::$calls, 'quality'));
        $this->assertSame(62, $record['webp']['quality']);
    }

    /**
     * A failed retry remains bounded by the minimum-saving threshold.
     */
    public function test_the_retry_runs_at_most_once_and_keeps_the_minimum_saving()
    {
        $max_bytes = (int) ( filesize($this->source) * 0.95 );

        WZIO_Retry_Test_Driver::reset(array( $max_bytes + 1, $max_bytes ));

        $record = Converter::convert_file($this->source, self::conversion_args());

        $this->assertCount(2, WZIO_Retry_Test_Driver::$calls);
        $this->assertSame('larger', $record['webp']['skip']);
        $this->assertSame(70, $record['webp']['quality']);
        $this->assertFileDoesNotExist(Helpers::sidecar_path($this->source, 'webp'));
    }

    /**
     * Each format gets its own retry budget and neither disturbs the other.
     */
    public function test_each_format_retries_independently()
    {
        $max_bytes = (int) ( filesize($this->source) * 0.95 );
        $args      = Converter::get_args(
            array(
                'formats'    => array( 'webp', 'avif' ),
                'force'      => true,
                'lossless'   => false,
                'min_saving' => 5,
                'quality'    => array(
                    'webp' => 82,
                    'avif' => 50,
                ),
            )
        );

        // WebP loses then wins on the retry; AVIF wins outright and never retries.
        WZIO_Retry_Test_Driver::reset(array( $max_bytes + 1, $max_bytes - 1, $max_bytes - 2 ));

        $record = Converter::convert_file($this->source, $args);

        $this->assertSame(
            array( 'webp', 'webp', 'avif' ),
            array_column(WZIO_Retry_Test_Driver::$calls, 'format')
        );
        $this->assertSame(array( 82, 70, 50 ), array_column(WZIO_Retry_Test_Driver::$calls, 'quality'));
        $this->assertSame(70, $record['webp']['quality']);
        $this->assertSame(50, $record['avif']['quality']);
        $this->assertSame($max_bytes - 1, $record['webp']['bytes']);
        $this->assertSame($max_bytes - 2, $record['avif']['bytes']);

        Helpers::delete_file(Helpers::sidecar_path($this->source, 'avif'));
    }

    /**
     * The quality on a skip entry belongs to an attempt that failed, so a sidecar
     * left on disk must not be credited with it.
     */
    public function test_a_failed_skip_quality_is_not_inherited()
    {
        $max_bytes = (int) ( filesize($this->source) * 0.95 );
        $sidecar   = Helpers::sidecar_path($this->source, 'webp');

        file_put_contents($sidecar, str_repeat('x', $max_bytes - 1));
        touch($sidecar, time() + 1);
        WZIO_Retry_Test_Driver::reset(array( $max_bytes + 1, $max_bytes + 1 ));

        $record = Converter::convert_file(
            $this->source,
            self::conversion_args(),
            array(
                'webp' => array(
                    'skip'    => 'larger',
                    'quality' => 70,
                ),
            )
        );

        $this->assertSame($max_bytes - 1, $record['webp']['bytes']);
        $this->assertArrayNotHasKey('quality', $record['webp']);
    }

    /**
     * Totals report how many copies had to drop below the configured quality.
     */
    public function test_totals_count_copies_that_needed_a_lower_quality()
    {
        wzio_update_option('quality_webp', 82);

        $attachment_id = self::factory()->post->create(array( 'post_type' => 'attachment' ));

        Attachment_Meta::set(
            $attachment_id,
            array(
                'files' => array(
                    'full.jpg'  => array(
                        'size' => 1000,
                        'webp' => array(
                            'bytes'   => 700,
                            'quality' => 70,
                            'reduced' => true,
                        ),
                        'avif' => array(
                            'bytes'   => 650,
                            'quality' => 42,
                            'reduced' => true,
                        ),
                    ),
                    'large.jpg' => array(
                        'size' => 500,
                        'webp' => array(
                            'bytes'   => 300,
                            'quality' => 82,
                        ),
                    ),
                    'thumb.jpg' => array(
                        'size' => 100,
                        'webp' => array( 'bytes' => 60 ),
                    ),
                ),
            )
        );

        $totals = Attachment_Meta::get_totals($attachment_id);

        $this->assertSame(3, $totals['files']);
        $this->assertSame(2, $totals['reduced']);
    }

    /**
     * A filtered initial quality is not presented as an adaptive retry.
     */
    public function test_totals_do_not_infer_a_retry_from_quality_alone()
    {
        $attachment_id = self::factory()->post->create(array( 'post_type' => 'attachment' ));

        Attachment_Meta::set(
            $attachment_id,
            array(
                'files' => array(
                    'full.jpg' => array(
                        'size' => 1000,
                        'webp' => array(
                            'bytes'   => 700,
                            'quality' => 70,
                        ),
                    ),
                ),
            )
        );

        $totals = Attachment_Meta::get_totals($attachment_id);

        $this->assertSame(0, $totals['reduced']);
    }

    /**
     * Lossless output is not repeated because quality does not affect it.
     */
    public function test_a_lossless_result_is_not_retried()
    {
        $max_bytes = (int) ( filesize($this->source) * 0.95 );
        $args = self::conversion_args();
        $args['lossless'] = true;

        WZIO_Retry_Test_Driver::reset(array( $max_bytes + 1, $max_bytes - 1 ));

        $record = Converter::convert_file($this->source, $args);

        $this->assertCount(1, WZIO_Retry_Test_Driver::$calls);
        $this->assertSame('larger', $record['webp']['skip']);
        $this->assertArrayNotHasKey('quality', $record['webp']);
    }
}
