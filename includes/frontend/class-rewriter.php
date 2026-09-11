<?php
/**
 * Front end image delivery.
 *
 * @package WebberZone\Image_Optimizer
 */

namespace WebberZone\Image_Optimizer\Frontend;

use WebberZone\Image_Optimizer\Converter;
use WebberZone\Image_Optimizer\Processor;
use WebberZone\Image_Optimizer\Queue;
use WebberZone\Image_Optimizer\Util\Helpers;
use WebberZone\Image_Optimizer\Util\Hook_Registry;

if ( ! defined( 'WPINC' ) ) {
	exit;
}

/**
 * Uses `<picture>` so browser format selection remains safe behind caches.
 *
 * @since 1.0.0
 */
class Rewriter {

	/**
	 * Attachment IDs queued for conversion during this request.
	 *
	 * @since 1.0.0
	 * @var array<int, int>
	 */
	private $lazy_queue = array();

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		Hook_Registry::add_action( 'template_redirect', array( $this, 'register_output_hooks' ), 1 );
		Hook_Registry::add_action( 'shutdown', array( $this, 'flush_lazy_queue' ) );
	}

	/**
	 * Attach the rewriting filters, once it is clear this is a page we serve.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_output_hooks(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		if ( \wzio_get_option( 'rewrite_content', true ) ) {
			Hook_Registry::add_filter( 'wp_content_img_tag', array( $this, 'filter_content_img_tag' ), 20, 3 );
		}

		if ( \wzio_get_option( 'rewrite_template', true ) ) {
			Hook_Registry::add_filter( 'wp_get_attachment_image', array( $this, 'filter_attachment_image' ), 20, 2 );
		}

		if ( \wzio_get_option( 'rewrite_buffer', false ) && self::has_template_enhancement_buffer() ) {
			Hook_Registry::add_filter( 'wp_template_enhancement_output_buffer', array( $this, 'filter_buffer' ), 20 );
		}
	}

	/**
	 * Whether core provides the template enhancement output buffer.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True on WordPress 6.9 and later.
	 */
	public static function has_template_enhancement_buffer(): bool {
		return function_exists( 'wp_start_template_enhancement_output_buffer' );
	}

	/**
	 * Whether images should be rewritten for this request.
	 *
	 * Existing sidecars remain valid even if their encoder is unavailable.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when rewriting is on.
	 */
	public function is_enabled(): bool {
		$enabled = ! is_admin()
			&& ! is_feed()
			&& ! is_embed()
			&& ! is_preview()
			&& ! is_customize_preview()
			&& ! wp_is_json_request()
			&& ! ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			&& ! wp_doing_cron()
			&& (bool) \wzio_get_option( 'enable_delivery', true );

		/**
		 * Filter whether images are rewritten on this request.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $enabled Whether rewriting is enabled.
		 */
		return (bool) apply_filters( 'wzio_delivery_enabled', $enabled );
	}

	/**
	 * Rewrite an image embedded in post content.
	 *
	 * @since 1.0.0
	 *
	 * @param string $filtered_image The `<img>` markup.
	 * @param string $context        Filter context.
	 * @param int    $attachment_id  Attachment ID, or 0 when it could not be determined.
	 * @return string Markup.
	 */
	public function filter_content_img_tag( $filtered_image, $context, $attachment_id ) {
		unset( $context );

		return $this->wrap( (string) $filtered_image, (int) $attachment_id );
	}

	/**
	 * Rewrite an image rendered through `wp_get_attachment_image()`.
	 *
	 * @since 1.0.0
	 *
	 * @param string $html          The `<img>` markup.
	 * @param int    $attachment_id Attachment ID.
	 * @return string Markup.
	 */
	public function filter_attachment_image( $html, $attachment_id ) {
		return $this->wrap( (string) $html, (int) $attachment_id );
	}

	/**
	 * Rewrite buffered images outside existing `<picture>` elements.
	 *
	 * @since 1.0.0
	 *
	 * @param string $html Page markup.
	 * @return string Markup.
	 */
	public function filter_buffer( string $html ): string {
		if ( false === stripos( $html, '<img' ) ) {
			return $html;
		}

		$segments = preg_split(
			'#(<picture\b.*?</picture>)#is',
			$html,
			-1,
			PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
		);

		if ( ! is_array( $segments ) ) {
			return $html;
		}

		$out = '';

		foreach ( $segments as $segment ) {
			if ( 0 === stripos( ltrim( $segment, " \t\n\r\0\x0B" ), '<picture' ) ) {
				$out .= $segment;
				continue;
			}

			$replaced = preg_replace_callback(
				'#<img\b[^>]*>#i',
				function ( array $matches ): string {
					return $this->wrap( $matches[0], 0 );
				},
				$segment
			);

			$out .= null === $replaced ? $segment : $replaced;
		}

		return $out;
	}

	/**
	 * Wrap a single `<img>` tag in a `<picture>` element.
	 *
	 * @since 1.0.0
	 *
	 * @param string $html          The `<img>` markup.
	 * @param int    $attachment_id Attachment ID, or 0 when unknown.
	 * @return string Markup, unchanged when no format applies.
	 */
	public function wrap( string $html, int $attachment_id ): string {
		if ( '' === $html || false === stripos( $html, '<img' ) ) {
			return $html;
		}

		// Content filters can run more than once. Never nest a second picture.
		if ( false !== stripos( $html, '<picture' ) ) {
			return $html;
		}

		$tags = new \WP_HTML_Tag_Processor( $html );

		if ( ! $tags->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
			return $html;
		}

		if (
			null !== $tags->get_attribute( 'data-wzio-skip' )
			|| null !== $tags->get_attribute( 'data-wzio-processed' )
			|| $tags->has_class( 'wzio-skip' )
		) {
			return $html;
		}

		$src    = (string) ( $tags->get_attribute( 'src' ) ?? '' );
		$srcset = (string) ( $tags->get_attribute( 'srcset' ) ?? '' );
		$sizes  = (string) ( $tags->get_attribute( 'sizes' ) ?? '' );

		if ( '' === $src && '' === $srcset ) {
			return $html;
		}

		// Preserve descriptors so `<source>` remains aligned with `<img>`.
		$candidates = '' !== $srcset ? self::parse_srcset( $srcset ) : array( array( $src, '' ) );

		if ( empty( $candidates ) ) {
			return $html;
		}

		if ( ! Resolver::is_local( $candidates[0][0] ) ) {
			return $html;
		}

		$complete_sources           = array();
		$partial_sources            = array();
		$required_candidate_indexes = self::get_required_candidate_indexes( $candidates );

		// Every format the plugin knows about, not just the ones this server can
		// encode: a sidecar is offered whenever the file is on disk.
		foreach ( Helpers::get_formats() as $format ) {
			$mapped             = array();
			$missing_required   = false;
			$missing_candidates = false;

			foreach ( $candidates as $index => $candidate ) {
				$sidecar = Resolver::resolve( $candidate[0], $format );

				if ( '' === $sidecar ) {
					if ( in_array( $index, $required_candidate_indexes, true ) ) {
						$missing_required = true;
						break;
					}

					$missing_candidates = true;
					continue;
				}

				$mapped[] = trim( $sidecar . ' ' . $candidate[1], " \t\n\r\0\x0B" );
			}

			if ( $missing_required || empty( $mapped ) ) {
				continue;
			}

			$source = sprintf(
				'<source type="%1$s" srcset="%2$s"',
				esc_attr( Helpers::get_mime_type( $format ) ),
				esc_attr( implode( ', ', $mapped ) )
			);

			if ( '' !== $sizes ) {
				$source .= sprintf( ' sizes="%s"', esc_attr( $sizes ) );
			}

			if ( $missing_candidates ) {
				$partial_sources[] = $source . ' />';
			} else {
				$complete_sources[] = $source . ' />';
			}
		}

		// Complete formats must precede partial formats so a partial AVIF source
		// cannot shadow a complete WebP source in browsers that support both.
		$sources = array_merge( $complete_sources, $partial_sources );

		if ( empty( $sources ) ) {
			$this->maybe_queue_lazily( $attachment_id );

			return $html;
		}

		$tags->set_attribute( 'data-wzio-processed', '1' );
		$html = $tags->get_updated_html();

		return '<picture>' . implode( '', $sources ) . $html . '</picture>';
	}

	/**
	 * Identify the candidates required to preserve the original set's coverage.
	 *
	 * Missing intermediate candidates can be omitted because the browser selects
	 * from the URLs actually present in the optimized source. The smallest and
	 * widest or highest-density candidates must remain available to avoid
	 * over-downloading or serving an undersized image where the original set
	 * offered a better choice. Unrecognised or mixed descriptor sets retain the
	 * conservative all-candidates rule.
	 *
	 * @since 1.1.0
	 *
	 * @param array<int, array{0: string, 1: string}> $candidates Candidates.
	 * @return array<int, int> Required candidate indexes.
	 */
	private static function get_required_candidate_indexes( array $candidates ): array {
		$descriptor_type = '';
		$values          = array();

		foreach ( $candidates as $index => $candidate ) {
			$descriptor = $candidate[1];

			if ( preg_match( '/^([1-9][0-9]*)w$/D', $descriptor, $matches ) ) {
				$type  = 'width';
				$value = (float) $matches[1];
			} elseif ( preg_match( '/^([0-9]*\.?[0-9]+)x$/D', $descriptor, $matches ) && (float) $matches[1] > 0 ) {
				$type  = 'density';
				$value = (float) $matches[1];
			} else {
				return array_keys( $candidates );
			}

			if ( '' !== $descriptor_type && $descriptor_type !== $type ) {
				return array_keys( $candidates );
			}

			$descriptor_type  = $type;
			$values[ $index ] = $value;
		}

		if ( empty( $values ) ) {
			return array_keys( $candidates );
		}

		$minimum = min( $values );
		$maximum = max( $values );

		return array_keys(
			array_filter(
				$values,
				static function ( float $value ) use ( $minimum, $maximum ): bool {
					return $minimum === $value || $maximum === $value;
				}
			)
		);
	}

	/**
	 * Split a `srcset` attribute into URL and descriptor pairs.
	 *
	 * @since 1.0.0
	 *
	 * @param string $srcset Attribute value.
	 * @return array<int, array{0: string, 1: string}> Candidates.
	 */
	public static function parse_srcset( string $srcset ): array {
		$candidates = array();

		foreach ( explode( ',', $srcset ) as $part ) {
			$part = trim( $part, " \t\n\r\0\x0B" );

			if ( '' === $part ) {
				continue;
			}

			// A candidate is a URL, then optional whitespace and a descriptor.
			$pieces     = preg_split( '/\s+/', $part, 2 );
			$url        = is_array( $pieces ) ? trim( $pieces[0], " \t\n\r\0\x0B" ) : '';
			$descriptor = is_array( $pieces ) && isset( $pieces[1] ) ? trim( $pieces[1], " \t\n\r\0\x0B" ) : '';

			if ( '' === $url ) {
				return array();
			}

			$candidates[] = array( $url, $descriptor );
		}

		return $candidates;
	}

	/**
	 * Queue missing sidecars for background conversion after rendering.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	private function maybe_queue_lazily( int $attachment_id ): void {
		if ( $attachment_id < 1 || ! \wzio_get_option( 'lazy_convert', true ) ) {
			return;
		}

		if ( count( $this->lazy_queue ) >= 25 || in_array( $attachment_id, $this->lazy_queue, true ) ) {
			return;
		}

		$this->lazy_queue[] = $attachment_id;
	}

	/**
	 * Write the lazily noted attachments to the queue after the page is sent.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function flush_lazy_queue(): void {
		if ( empty( $this->lazy_queue ) ) {
			return;
		}

		$ids = array_filter( $this->lazy_queue, array( Converter::class, 'is_convertible_attachment' ) );

		$this->lazy_queue = array();

		if ( empty( $ids ) ) {
			return;
		}

		Queue::add( $ids );
		Processor::maybe_schedule();
	}
}
