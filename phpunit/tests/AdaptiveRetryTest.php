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
     * A lossless miss falls back to one lossy encode at the configured quality.
     */
    public function test_a_lossless_miss_falls_back_to_lossy()
    {
        $max_bytes = (int) ( filesize($this->source) * 0.95 );
        $args = self::conversion_args();
        $args['lossless'] = true;
        $args['png_lossy'] = 82;

        WZIO_Retry_Test_Driver::reset(array( $max_bytes + 1, $max_bytes - 1 ));

        $record = Converter::convert_file($this->source, $args);

        $this->assertCount(2, WZIO_Retry_Test_Driver::$calls);
        $this->assertTrue(WZIO_Retry_Test_Driver::$calls[0]['lossless']);
        $this->assertFalse(WZIO_Retry_Test_Driver::$calls[1]['lossless']);
        $this->assertSame(82, WZIO_Retry_Test_Driver::$calls[1]['quality']);
        $this->assertSame($max_bytes - 1, $record['webp']['bytes']);
        $this->assertSame(82, $record['webp']['quality']);
        $this->assertTrue($record['webp']['reduced']);
    }
}
