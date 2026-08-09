<?php
/**
 * Tests for the front end delivery layer.
 *
 * @package WebberZone\Image_Optimizer
 */

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
	 * A single candidate with no descriptor is still a candidate.
	 */
	public function test_parse_srcset_handles_a_bare_url() {
		$this->assertSame( array( array( 'a.jpg', '' ) ), Rewriter::parse_srcset( 'a.jpg' ) );
		$this->assertSame( array(), Rewriter::parse_srcset( '' ) );
	}

	/**
	 * With no optimized copy on disk the markup is returned untouched.
	 */
	public function test_markup_is_untouched_without_a_sidecar() {
		$this->touch_upload( '2026/02/plain.jpg' );

		$html = '<img src="' . Helpers::get_upload_baseurl() . '/2026/02/plain.jpg" alt="" />';

		$this->assertSame( $html, $this->rewriter->wrap( $html, 0 ) );
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

		// The original tag must survive intact as the fallback.
		$this->assertStringContainsString( $html, $out );
	}

	/**
	 * Every srcset candidate is mapped, with its width descriptor preserved.
	 */
	public function test_every_srcset_candidate_is_mapped() {
		foreach ( array( 'wide-300x200.jpg', 'wide-768x512.jpg', 'wide.jpg' ) as $file ) {
			$this->touch_upload( '2026/02/' . $file );
			$this->touch_upload( '2026/02/' . $file . '.webp' );
		}

		$base = Helpers::get_upload_baseurl() . '/2026/02/';
		$html = '<img src="' . $base . 'wide-300x200.jpg" srcset="'
			. $base . 'wide-300x200.jpg 300w, '
			. $base . 'wide-768x512.jpg 768w, '
			. $base . 'wide.jpg 1200w" sizes="(max-width: 300px) 100vw, 300px" alt="" />';

		$out = $this->rewriter->wrap( $html, 0 );

		$this->assertStringContainsString( 'wide-300x200.jpg.webp 300w', $out );
		$this->assertStringContainsString( 'wide-768x512.jpg.webp 768w', $out );
		$this->assertStringContainsString( 'wide.jpg.webp 1200w', $out );
		$this->assertStringContainsString( 'sizes="(max-width: 300px) 100vw, 300px"', $out );
	}

	/**
	 * One unconverted candidate disqualifies the whole format.
	 *
	 * A partial `<source>` would let the browser pick a width that has no file.
	 */
	public function test_a_missing_candidate_disqualifies_the_format() {
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
	 * Images served from somewhere else are left alone.
	 */
	public function test_remote_images_are_left_alone() {
		$html = '<img src="https://cdn.example.com/photo.jpg" alt="" />';

		$this->assertSame( $html, $this->rewriter->wrap( $html, 0 ) );
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

	/**
	 * Markup that is not an image is passed straight through.
	 */
	public function test_non_image_markup_is_passed_through() {
		$this->assertSame( '', $this->rewriter->wrap( '', 0 ) );
		$this->assertSame( '<p>Hello</p>', $this->rewriter->wrap( '<p>Hello</p>', 0 ) );
	}

	/**
	 * Buffered rewriting must not wrap an image that is already in a picture.
	 */
	public function test_buffered_output_does_not_double_wrap() {
		$this->touch_upload( '2026/02/buffered.jpg' );
		$this->touch_upload( '2026/02/buffered.jpg.webp' );

		$url  = Helpers::get_upload_baseurl() . '/2026/02/buffered.jpg';
		$page = '<div>' . $this->rewriter->wrap( '<img src="' . $url . '" alt="" />', 0 ) . '</div>';

		$out = $this->rewriter->filter_buffer( $page );

		$this->assertSame( 1, substr_count( $out, '<picture>' ) );
		$this->assertSame( 1, substr_count( $out, '</picture>' ) );
		$this->assertSame( 1, substr_count( $out, '<img' ) );
	}

	/**
	 * Buffered rewriting catches an image the markup filters never saw.
	 */
	public function test_buffered_output_wraps_a_bare_image() {
		$this->touch_upload( '2026/02/bare.jpg' );
		$this->touch_upload( '2026/02/bare.jpg.webp' );

		$url = Helpers::get_upload_baseurl() . '/2026/02/bare.jpg';
		$out = $this->rewriter->filter_buffer( '<div><img src="' . $url . '" alt=""></div>' );

		$this->assertSame( 1, substr_count( $out, '<picture>' ) );
		$this->assertStringContainsString( 'bare.jpg.webp', $out );
	}

	/**
	 * A page with no images is returned byte for byte.
	 */
	public function test_buffered_output_leaves_an_imageless_page_alone() {
		$page = '<html><body><p>Nothing to see.</p></body></html>';

		$this->assertSame( $page, $this->rewriter->filter_buffer( $page ) );
	}
}
