<?php
/**
 * Tests for the conversion pipeline.
 *
 * @package WebberZone\Image_Optimizer
 */

use WebberZone\Image_Optimizer\Attachment_Meta;
use WebberZone\Image_Optimizer\Capabilities;
use WebberZone\Image_Optimizer\Converter;
use WebberZone\Image_Optimizer\Util\Helpers;

/**
 * End to end conversion of a real attachment.
 */
class ConverterTest extends WP_UnitTestCase
{

    /**
     * Attachment IDs created by a test.
     *
     * @var array<int, int>
     */
    private $attachments = array();

    /**
     * Set up.
     */
    public function set_up()
    {
        parent::set_up();

        if (! Capabilities::supports('webp') ) {
            // A silent skip here would let CI go green while testing nothing, so
            // say exactly what the server reported and why the probe rejected it.
            $this->markTestSkipped(
                'This server cannot encode WebP. ' . self::describe_capabilities()
            );
        }
    }

    /**
     * Summarise what the encoders reported, for a skip message worth reading.
     *
     * @return string Diagnostic line.
     */
    private static function describe_capabilities()
    {
        $report = Capabilities::get(true);

        $parts = array(
        'imagick_loaded=' . (int) extension_loaded('imagick'),
        'gd_loaded=' . (int) extension_loaded('gd'),
        'imagewebp=' . (int) function_exists('imagewebp'),
        'imageavif=' . (int) function_exists('imageavif'),
        'report=' . wp_json_encode($report['drivers']),
        self::describe_probe(),
        );

        if (class_exists('\Imagick') ) {
            try {
                $formats = array_intersect(array( 'WEBP', 'AVIF' ), array_map('strtoupper', \Imagick::queryFormats()));
                $parts[] = 'imagick_delegates=' . implode('/', $formats);
            } catch ( \Throwable $e ) {
                $parts[] = 'imagick_delegates=error:' . $e->getMessage();
            }
        }

        return implode(' ', $parts);
    }

	/**
	 * Re-run the probe by hand and report what each driver actually said.
	 *
	 * @return string Diagnostic line.
	 */
	private static function describe_probe() {
		$binary = base64_decode( \WebberZone\Image_Optimizer\Capabilities::PROBE_IMAGE, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $binary ) {
			return 'probe=base64_decode_failed';
		}

		$path = wp_tempnam( 'wzio-diag.png' );

		if ( ! $path ) {
			return 'probe=tempnam_failed';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = file_put_contents( $path, $binary );

		clearstatcache( true, $path );

		$size  = wp_getimagesize( $path );
		$parts = array(
			'probe_bytes=' . strlen( $binary ),
			'probe_written=' . var_export( $written, true ),
			'probe_dims=' . ( is_array( $size ) ? $size[0] . 'x' . $size[1] : 'unreadable' ),
		);

		$drivers = array(
			new \WebberZone\Image_Optimizer\Drivers\Imagick_Driver(),
			new \WebberZone\Image_Optimizer\Drivers\GD_Driver(),
		);

		foreach ( $drivers as $driver ) {
			$name = $driver::get_name();

			if ( ! $driver::is_available() ) {
				$parts[] = $name . '=unavailable';
				continue;
			}

			$target = $path . '.webp';
			$result = $driver->convert( $path, $target, 'webp', array( 'quality' => 70 ) );

			$parts[] = $name . '=' . ( true === $result ? 'ok' : $result->get_error_code() . ':' . $result->get_error_message() );

			if ( file_exists( $target ) ) {
				wp_delete_file( $target );
			}
		}

		wp_delete_file( $path );

		return implode( ' ', $parts );
	}

    /**
     * Tear down.
     */
    public function tear_down()
    {
        foreach ( $this->attachments as $id ) {
            Converter::delete_sidecars($id);
            wp_delete_attachment($id, true);
        }

        $this->attachments = array();

        parent::tear_down();
    }

    /**
     * Create a photographic JPEG that WebP can compress well.
     *
     * A flat colour block would compress to almost nothing in both formats and
     * would tell us nothing about whether the encoder actually ran.
     *
     * @param  int $width  Image width.
     * @param  int $height Image height.
     * @return int Attachment ID.
     */
    private function create_attachment( $width = 400, $height = 300 )
    {
        $image = imagecreatetruecolor($width, $height);

        for ( $x = 0; $x < $width; $x++ ) {
            for ( $y = 0; $y < $height; $y++ ) {
                imagesetpixel(
                    $image,
                    $x,
                    $y,
                    imagecolorallocate(
                        $image,
                        ( $x * 7 ) % 256,
                        ( $y * 5 ) % 256,
                        ( ( $x + $y ) * 3 ) % 256
                    )
                );
            }
        }

        $uploads = wp_upload_dir();
        $file    = $uploads['path'] . '/wzio-test-' . wp_generate_password(8, false) . '.jpg';

        wp_mkdir_p($uploads['path']);
        imagejpeg($image, $file, 92);
        imagedestroy($image);

        $attachment_id = $this->factory->attachment->create_object(
            $file,
            0,
            array(
            'post_mime_type' => 'image/jpeg',
            'post_title'     => 'WZIO test image',
            )
        );

        wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $file));

        $this->attachments[] = $attachment_id;

        return $attachment_id;
    }

    /**
     * Conversion writes a sidecar next to the source and leaves it in place.
     */
    public function test_conversion_writes_a_smaller_sidecar()
    {
        $id     = $this->create_attachment();
        $source = get_attached_file($id);

        $summary = Converter::convert_attachment($id, array( 'formats' => array( 'webp' ) ));

        $this->assertNotWPError($summary);
        $this->assertGreaterThan(0, $summary['converted']);

        $sidecar = Helpers::sidecar_path($source, 'webp');

        $this->assertFileExists($sidecar);
        $this->assertLessThan(filesize($source), filesize($sidecar));
    }

    /**
     * The original file is never modified.
     */
    public function test_the_original_is_untouched()
    {
        $id     = $this->create_attachment();
        $source = get_attached_file($id);
        $before = md5_file($source);

        Converter::convert_attachment($id, array( 'formats' => array( 'webp' ) ));

        $this->assertSame($before, md5_file($source));
    }

    /**
     * A record is written for every file that was processed.
     */
    public function test_a_record_is_written_for_each_file()
    {
        $id = $this->create_attachment();

        Converter::convert_attachment($id, array( 'formats' => array( 'webp' ) ));

        $record = Attachment_Meta::get($id);
        $files  = Converter::get_attachment_files($id);

        $this->assertNotEmpty($record['files']);
        $this->assertSame(array_keys($files), array_keys($record['files']));

        foreach ( $record['files'] as $file_record ) {
            $this->assertArrayHasKey('size', $file_record);
        }
    }

    /**
     * Totals reflect a real saving against the source bytes.
     */
    public function test_totals_report_a_saving()
    {
        $id = $this->create_attachment();

        Converter::convert_attachment($id, array( 'formats' => array( 'webp' ) ));

        $totals = Attachment_Meta::get_totals($id);

        $this->assertGreaterThan(0, $totals['files']);
        $this->assertGreaterThan(0, $totals['saved']);
        $this->assertLessThan($totals['source'], $totals['converted']);
    }

    /**
     * Re-running without force reuses the existing sidecar.
     */
    public function test_an_existing_sidecar_is_reused()
    {
        $id      = $this->create_attachment();
        $source  = get_attached_file($id);
        $sidecar = Helpers::sidecar_path($source, 'webp');

        Converter::convert_attachment($id, array( 'formats' => array( 'webp' ) ));

        $this->assertFileExists($sidecar);

        // Age the source, then stamp the sidecar in between: it is newer than
        // its source, so it is current and must be left alone.
        $now = time();
        touch($source, $now - 120);
        touch($sidecar, $now - 60);
        clearstatcache();

        Converter::convert_attachment($id, array( 'formats' => array( 'webp' ) ));

        clearstatcache();

        $this->assertSame($now - 60, filemtime($sidecar));
    }

    /**
     * A copy another plugin left behind is adopted instead of re-encoded.
     */
    public function test_a_foreign_sidecar_is_adopted_without_re_encoding()
    {
        $id      = $this->create_attachment();
        $source  = get_attached_file($id);
        $sidecar = Helpers::sidecar_path($source, 'webp');

        // Stands in for a copy another optimizer wrote, with no record of ours.
        file_put_contents($sidecar, str_repeat('x', 64));
        clearstatcache();

        Converter::convert_attachment($id, array( 'formats' => array( 'webp' ) ));

        clearstatcache();

        $this->assertSame(64, filesize($sidecar));

        $record = Attachment_Meta::get($id);

        $this->assertSame(64, $record['files'][ wp_basename($source) ]['webp']['bytes']);
    }

    /**
     * Forcing a run replaces a copy that would otherwise be adopted.
     */
    public function test_force_re_encodes_a_foreign_sidecar()
    {
        $id      = $this->create_attachment();
        $source  = get_attached_file($id);
        $sidecar = Helpers::sidecar_path($source, 'webp');

        file_put_contents($sidecar, str_repeat('x', 64));
        clearstatcache();

        Converter::convert_attachment($id, array( 'formats' => array( 'webp' ), 'force' => true ));

        clearstatcache();

        $this->assertNotSame(64, filesize($sidecar));
    }

    /**
     * A sidecar older than its source is stale and gets rebuilt.
     */
    public function test_a_stale_sidecar_is_regenerated()
    {
        $id      = $this->create_attachment();
        $source  = get_attached_file($id);
        $sidecar = Helpers::sidecar_path($source, 'webp');

        Converter::convert_attachment($id, array( 'formats' => array( 'webp' ) ));

        $now = time();
        touch($sidecar, $now - 120);
        touch($source, $now - 60);
        clearstatcache();

        Converter::convert_attachment($id, array( 'formats' => array( 'webp' ) ));

        clearstatcache();

        $this->assertGreaterThan($now - 120, filemtime($sidecar));
    }

    /**
     * A copy that is not smaller than its source is discarded, not kept.
     */
    public function test_a_larger_result_is_discarded_and_remembered()
    {
        $id = $this->create_attachment();

        // Demanding a 99% saving guarantees every result is rejected.
        Converter::convert_attachment(
            $id,
            array(
            'formats'    => array( 'webp' ),
            'min_saving' => 99,
            'force'      => true,
            )
        );

        $sidecar = Helpers::sidecar_path(get_attached_file($id), 'webp');

        $this->assertFileDoesNotExist($sidecar);

        $record = Attachment_Meta::get_file($id, wp_basename((string) get_attached_file($id)));

        $this->assertSame('larger', $record['webp']['skip']);
        $this->assertFalse(Attachment_Meta::is_converted($record, 'webp'));
    }

    /**
     * A rejected encode leaves a usable sidecar from elsewhere in place.
     */
    public function test_a_usable_sidecar_survives_a_rejected_encode()
    {
        $id      = $this->create_attachment();
        $source  = get_attached_file($id);
        $sidecar = Helpers::sidecar_path($source, 'webp');

        // Stands in for a sidecar another optimizer left behind.
        file_put_contents($sidecar, str_repeat('x', 32));

        Converter::convert_attachment(
            $id,
            array(
            'formats'    => array( 'webp' ),
            'min_saving' => 99,
            'force'      => true,
            )
        );

        clearstatcache();

        $this->assertFileExists($sidecar);
        $this->assertSame(32, filesize($sidecar));

        $record = Attachment_Meta::get_file($id, wp_basename((string) $source));

        $this->assertTrue(Attachment_Meta::is_converted($record, 'webp'));
        $this->assertSame(32, $record['webp']['bytes']);
    }

    /**
     * A sidecar older than its source is dropped even when the encode is rejected.
     */
    public function test_a_stale_sidecar_is_dropped_by_a_rejected_encode()
    {
        $id      = $this->create_attachment();
        $source  = get_attached_file($id);
        $sidecar = Helpers::sidecar_path($source, 'webp');

        file_put_contents($sidecar, str_repeat('x', 32));

        $now = time();
        touch($sidecar, $now - 120);
        touch($source, $now - 60);
        clearstatcache();

        Converter::convert_attachment(
            $id,
            array(
            'formats'    => array( 'webp' ),
            'min_saving' => 99,
            'force'      => true,
            )
        );

        $this->assertFileDoesNotExist($sidecar);

        $record = Attachment_Meta::get_file($id, wp_basename((string) $source));

        $this->assertSame('larger', $record['webp']['skip']);
    }

    /**
     * Deleting the copies removes the files and the record, not the original.
     */
    public function test_deleting_the_copies_leaves_the_original()
    {
        $id     = $this->create_attachment();
        $source = get_attached_file($id);

        Converter::convert_attachment($id, array( 'formats' => array( 'webp' ) ));

        $this->assertGreaterThan(0, Converter::delete_sidecars($id));

        $this->assertFileDoesNotExist(Helpers::sidecar_path($source, 'webp'));
        $this->assertFileExists($source);
        $this->assertEmpty(Attachment_Meta::get($id)['files']);
    }

    /**
     * Only image types the plugin understands are accepted.
     */
    public function test_only_supported_images_are_convertible()
    {
        $id = $this->create_attachment();

        $this->assertTrue(Converter::is_convertible_attachment($id));
        $this->assertFalse(Converter::is_convertible_attachment(0));
        $this->assertFalse(Converter::is_convertible_attachment(self::factory()->post->create()));
    }

    /**
     * An excluded path is skipped even though it is otherwise convertible.
     */
    public function test_excluded_paths_are_skipped()
    {
        $id = $this->create_attachment();

        $this->assertNotEmpty(Converter::get_attachment_files($id));

        // The stem without the extension, so the sub-size files match too.
        $fragment = pathinfo((string) get_attached_file($id), PATHINFO_FILENAME);

        wzio_update_option('exclude_paths', $fragment);

        $this->assertSame(array(), Converter::get_attachment_files($id));

        wzio_update_option('exclude_paths', '');
    }

    /**
     * An exclusion fragment only removes the files it actually matches.
     */
    public function test_exclusion_is_limited_to_matching_files()
    {
        $id    = $this->create_attachment();
        $files = Converter::get_attachment_files($id);

        if (count($files) < 2 ) {
            $this->markTestSkipped('This attachment produced no sub-sizes.');
        }

        // The full basename of the main file does not appear in a sub-size
        // name, because the dimensions sit before the extension.
        wzio_update_option('exclude_paths', wp_basename((string) get_attached_file($id)));

        $remaining = Converter::get_attachment_files($id);

        wzio_update_option('exclude_paths', '');

        $this->assertNotEmpty($remaining);
        $this->assertCount(count($files) - 1, $remaining);
    }
}
