<?php
/**
 * Tests for resumable attachment deadlines.
 *
 * @package WebberZone\Image_Optimizer
 */

use WebberZone\Image_Optimizer\Attachment_Meta;
use WebberZone\Image_Optimizer\Capabilities;
use WebberZone\Image_Optimizer\Converter;
use WebberZone\Image_Optimizer\Drivers\Driver;
use WebberZone\Image_Optimizer\Scanner;

/**
 * Deterministic driver that fails one source and converts the other.
 */
class WZIO_Deadline_Test_Driver extends Driver
{
    /**
     * Source basenames passed to the driver.
     *
     * @var array<int, string>
     */
    public static $calls = array();

    /**
     * Source basename that should fail.
     *
     * @var string
     */
    public static $failing = '';

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
        return 'deadline-test';
    }

    /**
     * Only WebP is simulated.
     *
     * @param string $format Target format.
     */
    public function supports( string $format ): bool
    {
        return 'webp' === $format;
    }

    /**
     * Fail the selected source and write a tiny sidecar for the other.
     *
     * @param string               $source      Source path.
     * @param string               $destination Destination path.
     * @param string               $format      Target format.
     * @param array<string, mixed> $args        Encoding arguments.
     * @return true|WP_Error Conversion result.
     */
    public function convert( string $source, string $destination, string $format, array $args )
    {
        $args = $this->parse_args($args);

        if (0 === $args['max_bytes'] ) {
            return true;
        }

        $basename     = wp_basename($source);
        self::$calls[] = $basename;

        if (self::$failing === $basename ) {
            return new WP_Error('wzio_deadline_test_failure', 'Simulated conversion failure.');
        }

        file_put_contents($destination, 'x');
        clearstatcache(true, $destination);

        return true;
    }
}

/**
 * A deadline resumes after the last file attempted, including failures.
 */
class AttachmentDeadlineTest extends WP_UnitTestCase
{
    /**
     * Attachment created by the test.
     *
     * @var int
     */
    private $attachment_id = 0;

    /**
     * Source basenames in conversion order.
     *
     * @var array<int, string>
     */
    private $basenames = array();

    /**
     * Set up a two-file attachment and the deterministic driver.
     */
    public function set_up()
    {
        parent::set_up();

        $binary  = base64_decode(Capabilities::PROBE_IMAGE, true);
        $uploads = wp_upload_dir();
        $token   = wp_generate_password(8, false);
        $main    = $uploads['path'] . '/wzio-deadline-' . $token . '.png';
        $thumb   = $uploads['path'] . '/wzio-deadline-' . $token . '-64x64.png';

        wp_mkdir_p($uploads['path']);

        if (false === $binary || false === file_put_contents($main, $binary) || false === file_put_contents($thumb, $binary) ) {
            $this->fail('Could not create the deadline test images.');
        }

        $this->attachment_id = $this->factory->attachment->create_object(
            $main,
            0,
            array(
                'post_mime_type' => 'image/png',
                'post_title'     => 'WZIO deadline test image',
            )
        );
        $this->basenames     = array( wp_basename($main), wp_basename($thumb) );

        wp_update_attachment_metadata(
            $this->attachment_id,
            array(
                'file'   => wp_basename($main),
                'width'  => 64,
                'height' => 64,
                'sizes'  => array(
                    'thumbnail' => array(
                        'file'      => wp_basename($thumb),
                        'width'     => 64,
                        'height'    => 64,
                        'mime-type' => 'image/png',
                    ),
                ),
            )
        );

        WZIO_Deadline_Test_Driver::$calls   = array();
        WZIO_Deadline_Test_Driver::$failing = $this->basenames[0];

        add_filter('wzio_driver_classes', array( __CLASS__, 'deadline_driver_classes' ));
        Capabilities::flush();
        Capabilities::get(true);

        // Creating the attachment fires the on-upload conversion with the real
        // driver. Leaving those sidecars in place would let convert_file() reuse
        // them and this driver would never be called.
        Converter::delete_sidecars($this->attachment_id);
        Attachment_Meta::delete($this->attachment_id);
        Attachment_Meta::delete_progress($this->attachment_id);
        WZIO_Deadline_Test_Driver::$calls = array();
    }

    /**
     * Tear down generated files and state.
     */
    public function tear_down()
    {
        remove_filter('wzio_driver_classes', array( __CLASS__, 'deadline_driver_classes' ));
        Capabilities::flush();

        if ($this->attachment_id > 0 ) {
            Converter::delete_sidecars($this->attachment_id);
            wp_delete_attachment($this->attachment_id, true);
        }

        parent::tear_down();
    }

    /**
     * Supply only the deterministic deadline driver.
     *
     * @return array<int, class-string<Driver>> Driver classes.
     */
    public static function deadline_driver_classes()
    {
        return array( WZIO_Deadline_Test_Driver::class );
    }

    /**
     * A deferred pass resumes after an error and stays unhandled until complete.
     */
    public function test_a_deferred_attachment_resumes_after_the_last_attempted_file()
    {
        $args = array(
            'formats'  => array( 'webp' ),
            'lossless' => false,
            'deadline' => microtime(true) - 1,
        );

        $first = Converter::convert_attachment($this->attachment_id, $args);

        $this->assertNotWPError($first);
        $this->assertFalse($first['complete']);
        $this->assertSame(array( $this->basenames[0] ), WZIO_Deadline_Test_Driver::$calls);
        $this->assertSame('', get_post_meta($this->attachment_id, Attachment_Meta::META_KEY, true));
        $this->assertContains(
            $this->attachment_id,
            Scanner::get_candidate_ids(100, $this->attachment_id - 1, true)
        );

        $second = Converter::convert_attachment($this->attachment_id, $args);

        $this->assertNotWPError($second);
        $this->assertTrue($second['complete']);
        $this->assertSame($this->basenames, WZIO_Deadline_Test_Driver::$calls);
        $this->assertSame($this->basenames, array_keys(Attachment_Meta::get($this->attachment_id)['files']));
        $this->assertSame(array(), Attachment_Meta::get_progress($this->attachment_id)['files']);
    }
}
