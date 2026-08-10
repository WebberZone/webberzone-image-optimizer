<?php
/**
 * Sidecar lookup for the delivery layer.
 *
 * @package WebberZone\Image_Optimizer
 */

namespace WebberZone\Image_Optimizer\Frontend;

use WebberZone\Image_Optimizer\Util\Helpers;

if ( ! defined( 'WPINC' ) ) {
	exit;
}

/**
 * Answers "is there an optimized copy of this URL?" quickly and repeatedly.
 *
 * The delivery layer cannot rely on attachment metadata alone. `wp_content_img_tag`
 * only knows an attachment ID when the markup carries a `wp-image-{ID}` class,
 * and content written by other editors, older posts and many page builders does
 * not. So the lookup is keyed on the URL, backed by a request-level cache and
 * the object cache, and only touches the filesystem on a genuine miss.
 *
 * A sidecar that came out no smaller than its source is deleted rather than
 * kept, which is what makes `file_exists()` the single, self-consistent answer:
 * if the file is there, serving it is always the right call.
 *
 * @since 1.0.0
 */
class Resolver {

	/**
	 * Object cache group.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const CACHE_GROUP = 'wzio_sidecar';

	/**
	 * How long a positive result is trusted.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const HIT_TTL = DAY_IN_SECONDS;

	/**
	 * How long a negative result is trusted.
	 *
	 * Short, because a miss becomes a hit as soon as the queue reaches that
	 * image, and nobody wants to wait a day to see the result of a bulk run.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const MISS_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Request-level cache of resolved paths.
	 *
	 * @since 1.0.0
	 * @var array<string, string|false>
	 */
	private static $memo = array();

	/**
	 * Resolve a source image URL to its sidecar URL.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url    Source image URL.
	 * @param string $format Target format slug.
	 * @return string Sidecar URL, or an empty string when there is none.
	 */
	public static function resolve( string $url, string $format ): string {
		$path = Helpers::url_to_path( $url );

		if ( '' === $path ) {
			return '';
		}

		// Split the URL at the first query string or fragment so the extension
		// is appended to the filename, not after the query string. A cache-busted
		// URL like `photo.jpg?v=1` must become `photo.jpg.webp?v=1`, not
		// `photo.jpg?v=1.webp` — the latter points back at the original file.
		$url_path = substr( $url, 0, strcspn( $url, '?#' ) );
		$url_tail = substr( $url, strlen( $url_path ) );

		$key = $format . ':' . $path;

		if ( isset( self::$memo[ $key ] ) ) {
			return self::$memo[ $key ] ? Helpers::apply_sidecar_naming( $url_path, $format ) . $url_tail : '';
		}

		$cache_key = md5( $key );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			$exists = ( 'y' === $cached );
		} else {
			$exists = file_exists( Helpers::sidecar_path( $path, $format ) );

			wp_cache_set(
				$cache_key,
				$exists ? 'y' : 'n',
				self::CACHE_GROUP,
				$exists ? self::HIT_TTL : self::MISS_TTL
			);
		}

		self::$memo[ $key ] = $exists;

		return $exists ? Helpers::apply_sidecar_naming( $url_path, $format ) . $url_tail : '';
	}

	/**
	 * Whether the URL points at a file inside this site's uploads directory.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url Image URL.
	 * @return bool True when the URL is local.
	 */
	public static function is_local( string $url ): bool {
		return '' !== Helpers::url_to_path( $url );
	}

	/**
	 * Forget everything cached for the current request.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function flush_memo(): void {
		self::$memo = array();
	}
}
