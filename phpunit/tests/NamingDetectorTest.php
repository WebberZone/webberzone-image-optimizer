<?php
/**
 * Tests for detection of sidecars left by another plugin.
 *
 * @package WebberZone\Image_Optimizer
 */

use WebberZone\Image_Optimizer\Converter;
use WebberZone\Image_Optimizer\Naming_Detector;
use WebberZone\Image_Optimizer\Util\Helpers;

/**
 * Sampling the media library for files the configured naming does not see.
 */
class NamingDetectorTest extends WP_UnitTestCase {

	/**
	 * Attachment IDs created by a test.
	 *
	 * @var array<int, int>
	 */
	private $attachments = array();

	/**
	 * Start every test on append naming with no stored report.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! function_exists( 'imagewebp' ) ) {
			$this->markTestSkipped( 'GD on this server cannot write WebP, so no fixture can be planted.' );
		}

		wzio_update_option( 'sidecar_naming', 'append' );

		// Fixtures must look like uploads another optimizer handled, not like
		// uploads this plugin converted: a record would remove them from the sample.
		wzio_update_option( 'convert_on_upload', 0 );

		Naming_Detector::forget();
	}

	/**
	 * Remove the attachments and settings a test created.
	 */
	public function tear_down() {
		foreach ( $this->attachments as $id ) {
			Converter::delete_sidecars( $id );
			wp_delete_attachment( $id, true );
		}

		$this->attachments = array();

		wzio_update_option( 'sidecar_naming', 'append' );
		Naming_Detector::forget();

		parent::tear_down();
	}

	/**
	 * A sidecar under the naming this site is not using is found and counted.
	 */
	public function test_a_sidecar_in_the_other_naming_is_counted() {
		$id     = $this->create_attachment();
		$source = get_attached_file( $id );

		$this->plant_webp( Helpers::sidecar_path( $source, 'webp', 'replace' ), 400, 300 );

		$report = Naming_Detector::scan();

		$this->assertSame( 1, $report['images']['replace'] );
		$this->assertSame( 1, $report['files']['replace'] );
		$this->assertSame( 0, $report['images']['append'] );
	}

	/**
	 * A separately uploaded image that merely shares the name is not a sidecar.
	 */
	public function test_an_upload_sharing_the_name_is_not_counted() {
		$id     = $this->create_attachment();
		$source = get_attached_file( $id );

		// The path a replace-named sidecar would take, holding a different image.
		$this->plant_webp( Helpers::sidecar_path( $source, 'webp', 'replace' ), 64, 64 );

		$report = Naming_Detector::scan();

		$this->assertSame( 0, $report['images']['replace'] );
	}

	/**
	 * A candidate that cannot be measured is left out rather than assumed.
	 */
	public function test_an_unreadable_candidate_is_not_counted() {
		$id     = $this->create_attachment();
		$source = get_attached_file( $id );

		file_put_contents( Helpers::sidecar_path( $source, 'webp', 'replace' ), 'not an image' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$report = Naming_Detector::scan();

		$this->assertSame( 0, $report['images']['replace'] );
	}

	/**
	 * Files this plugin already recorded are not reported as someone else's.
	 */
	public function test_a_recorded_attachment_is_not_sampled() {
		$id = $this->create_attachment();

		Converter::convert_attachment( $id, array( 'formats' => array( 'webp' ) ) );

		$report = Naming_Detector::scan();

		$this->assertSame( 0, $report['sampled'] );
		$this->assertSame( 0, $report['images']['append'] );
	}

	/**
	 * A switch is offered only when the other naming clearly holds more.
	 */
	public function test_a_switch_is_only_suggested_when_it_wins() {
		$this->assertSame( 'replace', Naming_Detector::get_suggestion( $this->report( 'append', 40, 0 ) ) );
		$this->assertSame( 'append', Naming_Detector::get_suggestion( $this->report( 'replace', 40, 0 ) ) );
		$this->assertSame( '', Naming_Detector::get_suggestion( $this->report( 'append', 3, 0 ) ) );
		$this->assertSame( '', Naming_Detector::get_suggestion( $this->report( 'append', 40, 60 ) ) );
		$this->assertSame( '', Naming_Detector::get_suggestion( $this->report( 'append', 0, 0 ) ) );
	}

	/**
	 * A stored report is discarded once the naming setting moves on.
	 */
	public function test_a_report_is_dropped_when_the_setting_changes() {
		Naming_Detector::store( Naming_Detector::scan() );

		$this->assertNotNull( Naming_Detector::get_cached_report() );

		wzio_update_option( 'sidecar_naming', 'replace' );

		$this->assertNull( Naming_Detector::get_cached_report() );
	}

	/**
	 * Build a report without touching the filesystem.
	 *
	 * @param  string $configured Naming the site is set to.
	 * @param  int    $other      Images found under the other naming.
	 * @param  int    $same       Images found under the configured naming.
	 * @return array<string, mixed> Report.
	 */
	private function report( $configured, $other, $same ) {
		$other_naming = 'append' === $configured ? 'replace' : 'append';

		return array(
			'configured' => $configured,
			'images'     => array(
				$configured   => $same,
				$other_naming => $other,
			),
		);
	}

	/**
	 * Write a WebP file of the given dimensions.
	 *
	 * @param  string $path   Where to write.
	 * @param  int    $width  Image width.
	 * @param  int    $height Image height.
	 * @return void
	 */
	private function plant_webp( $path, $width, $height ) {
		$image = imagecreatetruecolor( $width, $height );

		imagewebp( $image, $path, 80 );
		imagedestroy( $image );
	}

	/**
	 * Create a JPEG attachment with sub-sizes.
	 *
	 * @param  int $width  Image width.
	 * @param  int $height Image height.
	 * @return int Attachment ID.
	 */
	private function create_attachment( $width = 400, $height = 300 ) {
		$image = imagecreatetruecolor( $width, $height );

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
		$file    = $uploads['path'] . '/wzio-naming-' . wp_generate_password( 8, false ) . '.jpg';

		wp_mkdir_p( $uploads['path'] );
		imagejpeg( $image, $file, 92 );
		imagedestroy( $image );

		$attachment_id = $this->factory->attachment->create_object(
			$file,
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'WZIO naming test image',
			)
		);

		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $file ) );

		$this->attachments[] = $attachment_id;

		return $attachment_id;
	}
}
