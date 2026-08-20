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
 * @since 0.9.0
 */
class Rewriter {

	/**
	 * Attachment IDs queued for conversion during this request.
	 *
	 * @since 0.9.0
	 * @var array<int, int>
	 */
	private $lazy_queue = array();

	/**
	 * Constructor.
	 *
	 * @since 0.9.0
	 */
	public function __construct() {
		Hook_Registry::add_action( 'template_redirect', array( $this, 'register_output_hooks' ), 1 );
		Hook_Registry::add_action( 'shutdown', array( $this, 'flush_lazy_queue' ) );
	}

	/**
	 * Attach the rewriting filters, once it is clear this is a page we serve.
	 *
	 * @since 0.9.0
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
	 * @since 0.9.0
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
	 * @since 0.9.0
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
		 * @since 0.9.0
		 *
		 * @param bool $enabled Whether rewriting is enabled.
		 */
		return (bool) apply_filters( 'wzio_delivery_enabled', $enabled );
	}

	/**
	 * Rewrite an image embedded in post content.
	 *
	 * @since 0.9.0
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
	 * @since 0.9.0
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
	 * @since 0.9.0
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
			if ( 0 === stripos( ltrim( $segment ), '<picture' ) ) {
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
	 * @since 0.9.0
	 *
	 * @param string $html          The `<img>` markup.
	 * @param int    $attachment_id Attachment ID, or 0 when unknown.
	 * @return string Markup, unchanged when no format applies.
	 */
	public function wrap( string $html, int $attachment_id ): string {
		if ( '' === $html || false === stripos( $html, '<img' ) ) {
			return $html;
		}

		$tags = new \WP_HTML_Tag_Processor( $html );

		if ( ! $tags->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
			return $html;
		}

		if ( null !== $tags->get_attribute( 'data-wzio-skip' ) || $tags->has_class( 'wzio-skip' ) ) {
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

		$sources = array();

		// Every format the plugin knows about, not just the ones this server can
		// encode: a sidecar is offered whenever the file is on disk.
		foreach ( Helpers::get_formats() as $format ) {
			$mapped = array();

			foreach ( $candidates as $candidate ) {
				$sidecar = Resolver::resolve( $candidate[0], $format );

				// Require every candidate to prevent requests for missing sizes.
				if ( '' === $sidecar ) {
					$mapped = array();
					break;
				}

				$mapped[] = trim( $sidecar . ' ' . $candidate[1] );
			}

			if ( empty( $mapped ) ) {
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

			$sources[] = $source . ' />';
		}

		if ( empty( $sources ) ) {
			$this->maybe_queue_lazily( $attachment_id );

			return $html;
		}

		return '<picture>' . implode( '', $sources ) . $html . '</picture>';
	}

	/**
	 * Split a `srcset` attribute into URL and descriptor pairs.
	 *
	 * @since 0.9.0
	 *
	 * @param string $srcset Attribute value.
	 * @return array<int, array{0: string, 1: string}> Candidates.
	 */
	public static function parse_srcset( string $srcset ): array {
		$candidates = array();

		foreach ( explode( ',', $srcset ) as $part ) {
			$part = trim( $part );

			if ( '' === $part ) {
				continue;
			}

			// A candidate is a URL, then optional whitespace and a descriptor.
			$pieces     = preg_split( '/\s+/', $part, 2 );
			$url        = is_array( $pieces ) ? trim( $pieces[0] ) : '';
			$descriptor = is_array( $pieces ) && isset( $pieces[1] ) ? trim( $pieces[1] ) : '';

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
	 * @since 0.9.0
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
	 * @since 0.9.0
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
