<?php
/**
 * Shared helper functions.
 *
 * @package WebberZone\Image_Optimizer
 */

namespace WebberZone\Image_Optimizer\Util;

if ( ! defined( 'WPINC' ) ) {
	exit;
}

/**
 * Helper utilities used across the plugin.
 *
 * @since 0.9.0
 */
class Helpers {

	/**
	 * Source MIME types the plugin is able to convert.
	 *
	 * @since 0.9.0
	 * @var array<int, string>
	 */
	const SOURCE_MIME_TYPES = array( 'image/jpeg', 'image/png', 'image/gif' );

	/**
	 * Target formats keyed by format slug.
	 *
	 * @since 0.9.0
	 * @var array<string, string>
	 */
	const TARGET_MIME_TYPES = array(
		'avif' => 'image/avif',
		'webp' => 'image/webp',
	);

	/**
	 * Get target formats in `<picture>` delivery preference order.
	 *
	 * @since 0.9.0
	 *
	 * @return array<int, string> Format slugs.
	 */
	public static function get_formats(): array {
		return array_keys( self::TARGET_MIME_TYPES );
	}

	/**
	 * Get the MIME type for a target format.
	 *
	 * @since 0.9.0
	 *
	 * @param string $format Format slug.
	 * @return string MIME type, or an empty string when unknown.
	 */
	public static function get_mime_type( string $format ): string {
		return self::TARGET_MIME_TYPES[ $format ] ?? '';
	}

	/**
	 * Get the uploads base directory, without the year/month subdirectory.
	 *
	 * @since 0.9.0
	 *
	 * @return string Absolute path with no trailing slash, or an empty string on failure.
	 */
	public static function get_upload_basedir(): string {
		$uploads = wp_get_upload_dir();

		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		return untrailingslashit( $uploads['basedir'] );
	}

	/**
	 * Get the uploads base URL, without the year/month subdirectory.
	 *
	 * @since 0.9.0
	 *
	 * @return string URL with no trailing slash, or an empty string on failure.
	 */
	public static function get_upload_baseurl(): string {
		$uploads = wp_get_upload_dir();

		if ( ! empty( $uploads['error'] ) || empty( $uploads['baseurl'] ) ) {
			return '';
		}

		return untrailingslashit( $uploads['baseurl'] );
	}

	/**
	 * Convert an uploads URL to a path, normalizing scheme differences.
	 *
	 * @since 0.9.0
	 *
	 * @param string $url Image URL.
	 * @return string Absolute path, or an empty string when the URL is not local.
	 */
	public static function url_to_path( string $url ): string {
		$baseurl = self::get_upload_baseurl();
		$basedir = self::get_upload_basedir();

		if ( '' === $baseurl || '' === $basedir || '' === $url ) {
			return '';
		}

		// Drop any query string or fragment.
		$url = substr( $url, 0, strcspn( $url, '?#' ) );

		if ( '' === $url ) {
			return '';
		}

		// Normalise the scheme so http/https/protocol-relative all compare equal.
		$normalise = static function ( string $value ): string {
			return preg_replace( '#^(https?:)?//#i', '//', $value ) ?? $value;
		};

		$normalised_url     = $normalise( $url );
		$normalised_baseurl = $normalise( $baseurl );

		if ( 0 !== strpos( $normalised_url, $normalised_baseurl . '/' ) ) {
			return '';
		}

		$relative = substr( $normalised_url, strlen( $normalised_baseurl ) + 1 );
		$relative = self::sanitize_relative_path( rawurldecode( $relative ) );

		if ( '' === $relative ) {
			return '';
		}

		return $basedir . '/' . $relative;
	}

	/**
	 * Convert an absolute file path inside the uploads directory to a URL.
	 *
	 * @since 0.9.0
	 *
	 * @param string $path Absolute file path.
	 * @return string URL, or an empty string when the path is outside the uploads directory.
	 */
	public static function path_to_url( string $path ): string {
		$baseurl = self::get_upload_baseurl();
		$basedir = self::get_upload_basedir();

		if ( '' === $baseurl || '' === $basedir || '' === $path ) {
			return '';
		}

		$path = wp_normalize_path( $path );

		if ( 0 !== strpos( $path, wp_normalize_path( $basedir ) . '/' ) ) {
			return '';
		}

		$relative = substr( $path, strlen( wp_normalize_path( $basedir ) ) + 1 );

		return $baseurl . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $relative ) ) );
	}

	/**
	 * Strip traversal segments from a relative path.
	 *
	 * @since 0.9.0
	 *
	 * @param string $relative Relative path.
	 * @return string Sanitised relative path.
	 */
	public static function sanitize_relative_path( string $relative ): string {
		$relative = wp_normalize_path( $relative );
		$relative = ltrim( $relative, '/' );

		if ( '' === $relative || false !== strpos( $relative, '../' ) || false !== strpos( $relative, "\0" ) ) {
			return '';
		}

		return $relative;
	}

	/**
	 * Build the sidecar path for a source file and target format.
	 *
	 * Default is append (`photo.jpg` → `photo.jpg.webp`); see the `sidecar_naming` setting.
	 *
	 * @since 0.9.0
	 *
	 * @param string $file   Absolute path or filename of the source image.
	 * @param string $format Target format slug.
	 * @return string Sidecar path or filename.
	 */
	public static function sidecar_path( string $file, string $format ): string {
		return self::apply_sidecar_naming( $file, $format );
	}

	/**
	 * Apply shared sidecar naming so filesystem paths and URLs agree.
	 *
	 * @since 0.9.0
	 *
	 * @param string $path   Absolute path, filename, or URL path.
	 * @param string $format Target format slug.
	 * @return string Path or URL with the sidecar naming applied.
	 */
	public static function apply_sidecar_naming( string $path, string $format ): string {
		if ( 'replace' === \wzio_get_option( 'sidecar_naming', 'append' ) ) {
			return (string) preg_replace( '/\.[^.\/\\\\]+$/', '', $path ) . '.' . $format;
		}

		return $path . '.' . $format;
	}

	/**
	 * Get the PHP memory limit in bytes.
	 *
	 * @since 0.9.0
	 *
	 * @return int Memory limit in bytes. `0` means unlimited.
	 */
	public static function get_memory_limit(): int {
		$limit = (string) ini_get( 'memory_limit' );

		if ( '' === $limit ) {
			return 0;
		}

		$bytes = wp_convert_hr_to_bytes( $limit );

		return $bytes > 0 ? (int) $bytes : 0;
	}

	/**
	 * Estimate decode headroom, including intermediate bitmap copies.
	 *
	 * @since 0.9.0
	 *
	 * @param int $width  Image width in pixels.
	 * @param int $height Image height in pixels.
	 * @return bool True when there is enough headroom, false otherwise.
	 */
	public static function can_allocate_image( int $width, int $height ): bool {
		$limit = self::get_memory_limit();

		// Unlimited memory, or unknown dimensions: let the conversion try.
		if ( 0 === $limit || $width < 1 || $height < 1 ) {
			return true;
		}

		/**
		 * Filter the multiplier applied to the estimated bitmap size.
		 *
		 * @since 0.9.0
		 *
		 * @param float $multiplier Multiplier applied to width * height * 4 bytes.
		 */
		$multiplier = (float) apply_filters( 'wzio_memory_multiplier', 2.5 );

		$required  = (int) ( $width * $height * 4 * $multiplier );
		$available = $limit - memory_get_usage( true );

		return $available > $required;
	}

	/**
	 * Determine whether a GIF file contains more than one frame.
	 *
	 * @since 0.9.0
	 *
	 * @param string $file Absolute path to the file.
	 * @return bool True when the GIF is animated.
	 */
	public static function is_animated_gif( string $file ): bool {
		if ( ! is_readable( $file ) ) {
			return false;
		}

		$handle = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false === $handle ) {
			return false;
		}

		$count  = 0;
		$buffer = '';

		while ( ! feof( $handle ) && $count < 2 ) {
			// Keep the tail of the previous chunk so markers spanning a boundary are found.
			$buffer = substr( $buffer, -10 ) . fread( $handle, 102400 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$count += preg_match_all( '#\x00\x21\xF9\x04.{4}\x00(\x2C|\x21)#s', $buffer );
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $count > 1;
	}

	/**
	 * Format a byte count for display.
	 *
	 * @since 0.9.0
	 *
	 * @param int $bytes Number of bytes.
	 * @return string Human readable size.
	 */
	public static function format_bytes( int $bytes ): string {
		return size_format( max( 0, $bytes ), $bytes >= MB_IN_BYTES ? 2 : 0 );
	}

	/**
	 * Delete a file through the WordPress filesystem hooks.
	 *
	 * @since 0.9.0
	 *
	 * @param string $file Absolute path to the file.
	 * @return bool True when the file no longer exists.
	 */
	public static function delete_file( string $file ): bool {
		if ( '' === $file ) {
			return true;
		}

		wp_delete_file( $file );

		// The stat cache would otherwise report the file as still present.
		clearstatcache( true, $file );

		return ! file_exists( $file );
	}
}
