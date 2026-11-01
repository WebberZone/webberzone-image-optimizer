<?php
/**
 * Tests for the front end delivery layer.
 *
 * @package WebberZone\Image_Optimizer
 */

use WebberZone\Image_Optimizer\Capabilities;
use WebberZone\Image_Optimizer\Frontend\Rewriter;
use WebberZone\Image_Optimizer\Util\Helpers;

/**
 * `<picture>` construction and the rules that stop it.
 */
class RewriterTest extends WP_UnitTestCase {

	/**
	 * Rewriter under test.
	 *
	 * @var Rewriter
	 */
	private $rewriter;

	/**
	 * Absolute paths written by a test, removed afterwards.
	 *
	 * @var array<int, string>
	 */
	private $temp_files = array();

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();

		$this->rewriter = new Rewriter();
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		foreach ( $this->temp_files as $file ) {
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}

		$this->temp_files = array();

		wp_cache_flush();

		parent::tear_down();
	}

	/**
	 * Create an empty file inside the uploads directory.
	 *
	 * @param string $relative Path relative to the uploads directory.
	 * @return string Absolute path.
	 */
	private function touch_upload( $relative ) {
		$path = Helpers::get_upload_basedir() . '/' . $relative;

		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$this->temp_files[] = $path;

		return $path;
	}

	/**
	 * Descriptors are carried across verbatim, never recalculated.
	 */
	public function test_parse_srcset_keeps_descriptors_verbatim() {
		$parsed = Rewriter::parse_srcset( 'a.jpg 300w, b.jpg 768w,  c.jpg 2x ' );

		$this->assertSame(
			array(
				array( 'a.jpg', '300w' ),
				array( 'b.jpg', '768w' ),
				array( 'c.jpg', '2x' ),
			),
			$parsed
		);
	}

	/**
	 * A single image with a sidecar gains one source of the right type.
	 */
	public function test_single_image_is_wrapped() {
		$this->touch_upload( '2026/02/single.jpg' );
		$this->touch_upload( '2026/02/single.jpg.webp' );

		$url  = Helpers::get_upload_baseurl() . '/2026/02/single.jpg';
		$html = '<img src="' . $url . '" alt="" />';

		$out = $this->rewriter->wrap( $html, 0 );

		$this->assertStringStartsWith( '<picture>', $out );
		$this->assertStringEndsWith( '</picture>', $out );
		$this->assertStringContainsString( 'type="image/webp"', $out );
		$this->assertStringContainsString( $url . '.webp', $out );

		// The original tag survives as the fallback, carrying the marker that stops a
		// repeat pass wrapping it again. Asserted per attribute, not as one string,
		// so the tag processor's attribute ordering is not baked into the test.
		$this->assertStringContainsString( 'src="' . $url . '"', $out );
		$this->assertStringContainsString( 'alt=""', $out );
		$this->assertStringContainsString( 'data-wzio-processed="1"', $out );
	}

	/**
	 * Repeated content filtering must not nest picture elements.
	 */
	public function test_existing_picture_is_not_wrapped_again() {
		$this->touch_upload( '2026/02/repeated.jpg' );
		$this->touch_upload( '2026/02/repeated.jpg.webp' );

		$url  = Helpers::get_upload_baseurl() . '/2026/02/repeated.jpg';
		$html = '<img src="' . $url . '" alt="" />';
		$once = $this->rewriter->wrap( $html, 0 );

		$this->assertSame( $once, $this->rewriter->wrap( $once, 0 ) );
		$this->assertSame( 1, substr_count( $once, '<picture>' ) );
		$this->assertStringContainsString( 'data-wzio-processed="1"', $once );

		$this->assertSame( 1, preg_match( '#<img\b[^>]*>#i', $once, $matches ) );

		$this->assertSame( $matches[0], $this->rewriter->wrap( $matches[0], 0 ) );
	}

	/**
	 * A missing widest candidate disqualifies the whole format.
	 */
	public function test_a_missing_widest_candidate_disqualifies_the_format() {
		$this->touch_upload( '2026/02/partial-300x200.jpg' );
		$this->touch_upload( '2026/02/partial-300x200.jpg.webp' );
		$this->touch_upload( '2026/02/partial.jpg' );
		// Deliberately no partial.jpg.webp.

		$base = Helpers::get_upload_baseurl() . '/2026/02/';
		$html = '<img src="' . $base . 'partial-300x200.jpg" srcset="'
			. $base . 'partial-300x200.jpg 300w, '
			. $base . 'partial.jpg 1200w" alt="" />';

		$this->assertSame( $html, $this->rewriter->wrap( $html, 0 ) );
	}

	/**
	 * Missing intermediate widths are omitted when the widest copy exists.
	 */
	public function test_a_missing_intermediate_width_is_omitted() {
		foreach ( array( 'partial-width-300x200.jpg', 'partial-width-768x512.jpg', 'partial-width.jpg' ) as $file ) {
			$this->touch_upload( '2026/02/' . $file );
		}

		$this->touch_upload( '2026/02/partial-width-300x200.jpg.webp' );
		$this->touch_upload( '2026/02/partial-width.jpg.webp' );

		$base = Helpers::get_upload_baseurl() . '/2026/02/';
		$html = '<img src="' . $base . 'partial-width-300x200.jpg" srcset="'
			. $base . 'partial-width-300x200.jpg 300w, '
			. $base . 'partial-width-768x512.jpg 768w, '
			. $base . 'partial-width.jpg 1200w" sizes="(max-width: 600px) 100vw, 600px" alt="" />';

		$out = $this->rewriter->wrap( $html, 0 );

		$this->assertStringContainsString( '<picture>', $out );
		$this->assertStringContainsString( 'partial-width-300x200.jpg.webp 300w', $out );
		$this->assertStringNotContainsString( 'partial-width-768x512.jpg.webp', $out );
		$this->assertStringContainsString( 'partial-width.jpg.webp 1200w', $out );
		$this->assertStringContainsString( 'sizes="(max-width: 600px) 100vw, 600px"', $out );
		$this->assertStringContainsString( 'partial-width-768x512.jpg 768w', $out );
	}

	/**
	 * Delivery does not depend on this server being able to encode.
	 *
	 * The optimized files may have been generated elsewhere, or the encoder may
	 * have been disabled since. Either way the files on disk are still valid and
	 * still worth serving.
	 */
	public function test_delivery_survives_without_an_encoder() {
		$this->touch_upload( '2026/02/noencoder.jpg' );
		$this->touch_upload( '2026/02/noencoder.jpg.webp' );

		$strip_drivers = static function () {
			return array();
		};

		add_filter( 'wzio_driver_classes', $strip_drivers );
		Capabilities::flush();

		$this->assertSame( array(), Capabilities::get( true )['formats'] );

		$url  = Helpers::get_upload_baseurl() . '/2026/02/noencoder.jpg';
		$html = '<img src="' . $url . '" alt="" />';
		$out  = $this->rewriter->wrap( $html, 0 );

		remove_filter( 'wzio_driver_classes', $strip_drivers );
		Capabilities::flush();

		$this->assertStringContainsString( '<picture>', $out );
		$this->assertStringContainsString( 'noencoder.jpg.webp', $out );
		$this->assertTrue( $this->rewriter->is_enabled() );
	}

	/**
	 * The opt-out class and attribute both suppress rewriting.
	 */
	public function test_opt_out_markers_are_respected() {
		$this->touch_upload( '2026/02/optout.jpg' );
		$this->touch_upload( '2026/02/optout.jpg.webp' );

		$url = Helpers::get_upload_baseurl() . '/2026/02/optout.jpg';

		$by_class     = '<img src="' . $url . '" class="wzio-skip" alt="" />';
		$by_attribute = '<img src="' . $url . '" data-wzio-skip="1" alt="" />';

		$this->assertSame( $by_class, $this->rewriter->wrap( $by_class, 0 ) );
		$this->assertSame( $by_attribute, $this->rewriter->wrap( $by_attribute, 0 ) );
	}
}
