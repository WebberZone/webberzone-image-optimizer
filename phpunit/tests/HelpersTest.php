<?php
/**
 * Tests for the shared helpers.
 *
 * @package WebberZone\Image_Optimizer
 */

use WebberZone\Image_Optimizer\Util\Helpers;

/**
 * Path and URL mapping, and the guards around it.
 */
class HelpersTest extends WP_UnitTestCase {

	/**
	 * The sidecar extension is appended, never substituted.
	 */
	public function test_sidecar_path_appends_the_extension() {
		$this->assertSame( 'photo.jpg.webp', Helpers::sidecar_path( 'photo.jpg', 'webp' ) );
		$this->assertSame( 'photo.png.avif', Helpers::sidecar_path( 'photo.png', 'avif' ) );
	}

	/**
	 * Two sources sharing a stem must not resolve to the same sidecar.
	 */
	public function test_sidecar_paths_do_not_collide_across_source_types() {
		$this->assertNotSame(
			Helpers::sidecar_path( '/uploads/logo.jpg', 'webp' ),
			Helpers::sidecar_path( '/uploads/logo.png', 'webp' )
		);
	}

	/**
	 * A URL inside the uploads directory maps to a path and back again.
	 */
	public function test_url_and_path_round_trip() {
		$url  = Helpers::get_upload_baseurl() . '/2026/01/example.jpg';
		$path = Helpers::url_to_path( $url );

		$this->assertSame( Helpers::get_upload_basedir() . '/2026/01/example.jpg', $path );
		$this->assertSame( $url, Helpers::path_to_url( $path ) );
	}

	/**
	 * A query string is not part of the file path.
	 */
	public function test_url_to_path_drops_the_query_string() {
		$url = Helpers::get_upload_baseurl() . '/2026/01/example.jpg?ver=2';

		$this->assertSame( Helpers::get_upload_basedir() . '/2026/01/example.jpg', Helpers::url_to_path( $url ) );
	}

	/**
	 * A site moved between http and https still resolves its own uploads.
	 */
	public function test_url_to_path_ignores_the_scheme() {
		$baseurl = Helpers::get_upload_baseurl();
		$flipped = 0 === strpos( $baseurl, 'https://' )
			? 'http://' . substr( $baseurl, 8 )
			: 'https://' . substr( $baseurl, 7 );

		$this->assertSame(
			Helpers::get_upload_basedir() . '/2026/01/example.jpg',
			Helpers::url_to_path( $flipped . '/2026/01/example.jpg' )
		);
	}

	/**
	 * Anything outside the uploads directory is not ours to touch.
	 */
	public function test_url_to_path_rejects_remote_and_foreign_urls() {
		$this->assertSame( '', Helpers::url_to_path( 'https://cdn.example.com/2026/01/example.jpg' ) );
		$this->assertSame( '', Helpers::url_to_path( '' ) );
		$this->assertSame( '', Helpers::path_to_url( '/etc/passwd' ) );
	}

	/**
	 * Traversal segments never survive into a filesystem path.
	 */
	public function test_relative_paths_cannot_traverse_upwards() {
		$this->assertSame( '', Helpers::sanitize_relative_path( '../../wp-config.php' ) );
		$this->assertSame( '', Helpers::sanitize_relative_path( '2026/../../secret.jpg' ) );
		$this->assertSame( '2026/01/example.jpg', Helpers::sanitize_relative_path( '/2026/01/example.jpg' ) );
	}

	/**
	 * A traversal attempt through a URL resolves to nothing.
	 */
	public function test_url_to_path_rejects_traversal() {
		$url = Helpers::get_upload_baseurl() . '/../../wp-config.php';

		$this->assertSame( '', Helpers::url_to_path( $url ) );
	}

	/**
	 * The memory guard rejects an image that cannot fit in the limit.
	 */
	public function test_memory_guard_rejects_an_impossible_image() {
		if ( 0 === Helpers::get_memory_limit() ) {
			$this->markTestSkipped( 'Memory is unlimited on this environment.' );
		}

		$this->assertTrue( Helpers::can_allocate_image( 100, 100 ) );
		$this->assertFalse( Helpers::can_allocate_image( 100000, 100000 ) );
	}

	/**
	 * Unknown dimensions must not block a conversion attempt.
	 */
	public function test_memory_guard_allows_unknown_dimensions() {
		$this->assertTrue( Helpers::can_allocate_image( 0, 0 ) );
	}

	/**
	 * The two target formats are offered smallest first.
	 */
	public function test_avif_is_preferred_over_webp() {
		$this->assertSame( array( 'avif', 'webp' ), Helpers::get_formats() );
		$this->assertSame( 'image/webp', Helpers::get_mime_type( 'webp' ) );
		$this->assertSame( '', Helpers::get_mime_type( 'bmp' ) );
	}
}
