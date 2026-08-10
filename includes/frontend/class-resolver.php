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
 * Resolves sidecars by URL with request and object caching.
 *
 * File existence is authoritative because unusable sidecars are deleted.
 *
 * @since 0.9.0
 */
class Resolver {

	/**
	 * Object cache group.
	 *
	 * @since 0.9.0
	 * @var string
	 */
	const CACHE_GROUP = 'wzio_sidecar';

	/**
	 * How long a positive result is trusted.
	 *
	 * @since 0.9.0
	 * @var int
	 */
	const HIT_TTL = DAY_IN_SECONDS;

	/**
	 * How long a negative result is trusted before queued conversion may complete.
	 *
	 * @since 0.9.0
	 * @var int
	 */
	const MISS_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Request-level cache of resolved paths.
	 *
	 * @since 0.9.0
	 * @var array<string, string|false>
	 */
	private static $memo = array();

	/**
	 * Resolve a source image URL to its sidecar URL.
	 *
	 * @since 0.9.0
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

		// Append the extension before any query string or fragment.
		$url_path = substr( $url, 0, strcspn( $url, '?#' ) );
		$url_tail = substr( $url, strlen( $url_path ) );

		// Key by sidecar path to isolate naming strategies.
		$key = $format . ':' . Helpers::sidecar_path( $path, $format );

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
	 * @since 0.9.0
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
	 * @since 0.9.0
	 *
	 * @return void
	 */
	public static function flush_memo(): void {
		self::$memo = array();
	}

	/**
	 * Invalidate cached existence after sidecar creation or deletion.
	 *
	 * @since 0.9.0
	 *
	 * @param string $path Absolute source image path.
	 * @return void
	 */
	public static function invalidate_path( string $path ): void {
		foreach ( Helpers::get_formats() as $format ) {
			$key = $format . ':' . Helpers::sidecar_path( $path, $format );

			unset( self::$memo[ $key ] );
			wp_cache_delete( md5( $key ), self::CACHE_GROUP );
		}
	}
}
