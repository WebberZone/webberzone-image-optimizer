<?php
/**
 * Base image conversion driver.
 *
 * @package WebberZone\Image_Optimizer
 */

namespace WebberZone\Image_Optimizer\Drivers;

if ( ! defined( 'WPINC' ) ) {
	exit;
}

/**
 * Contract implemented by every conversion backend.
 *
 * @since 0.9.0
 */
abstract class Driver {


	/**
	 * Whether the underlying PHP extension is loaded.
	 *
	 * @since 0.9.0
	 *
	 * @return bool True when the extension is present.
	 */
	abstract public static function is_available(): bool;

	/**
	 * Machine name of the driver.
	 *
	 * @since 0.9.0
	 *
	 * @return string Driver slug.
	 */
	abstract public static function get_name(): string;

	/**
	 * Whether this driver can encode the given target format.
	 *
	 * @since 0.9.0
	 *
	 * @param  string $format Target format slug.
	 * @return bool True when encoding is supported.
	 */
	abstract public function supports( string $format ): bool;

	/**
	 * Encode a source image into the target format.
	 *
	 * Implementations must preserve the source dimensions exactly. Resizing here
	 * would desynchronise the sidecar from the `srcset` candidate it replaces.
	 *
	 * @since 0.9.0
	 *
	 * @param  string               $source      Absolute path to the source image.
	 * @param  string               $destination Absolute path to write.
	 * @param  string               $format      Target format slug.
	 * @param  array<string, mixed> $args        Encoding arguments.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	abstract public function convert( string $source, string $destination, string $format, array $args );

	/**
	 * Normalise the encoding arguments.
	 *
	 * @since 0.9.0
	 *
	 * @param  array<string, mixed> $args Raw arguments.
	 * @return array<string, mixed> Normalised arguments.
	 */
	protected function parse_args( array $args ): array {
		$args = wp_parse_args(
			$args,
			array(
				'quality'       => 82,
				'lossless'      => false,
				'strip'         => true,
				'alpha_quality' => 90,
				'effort'        => 6,
			)
		);

		$args['quality']       = max( 1, min( 100, (int) $args['quality'] ) );
		$args['alpha_quality'] = max( 1, min( 100, (int) $args['alpha_quality'] ) );
		$args['effort']        = max( 0, min( 6, (int) $args['effort'] ) );
		$args['lossless']      = (bool) $args['lossless'];
		$args['strip']         = (bool) $args['strip'];

		return $args;
	}

	/**
	 * Write to a temporary file and move it into place atomically.
	 *
	 * A half-written sidecar served to a visitor is worse than no sidecar at
	 * all, so nothing lands at the destination path until the encode succeeded.
	 *
	 * @since 0.9.0
	 *
	 * @param  string   $destination Final destination path.
	 * @param  callable $writer      Receives the temporary path, returns bool.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	protected function write_atomic( string $destination, callable $writer ) {
		$dir = dirname( $destination );

		if ( ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error(
				'wzio_dir_not_writable',
				/* translators: %s: directory path. */
				sprintf( __( 'Could not create or write to the directory %s.', 'webberzone-image-optimizer' ), $dir )
			);
		}

		// wp_tempnam() concatenates its directory and filename with no separator,
		// so a directory without a trailing slash puts the temporary file beside
		// the target directory rather than inside it. That silently works where
		// the parent happens to be writable and fails outright where it is not.
		$temp = wp_tempnam( basename( $destination ), trailingslashit( $dir ) );

		if ( ! $temp ) {
			return new \WP_Error( 'wzio_tempfile_failed', __( 'Could not create a temporary file for the conversion.', 'webberzone-image-optimizer' ) );
		}

		$written = false;

		try {
			$written = (bool) $writer( $temp );
		} catch ( \Throwable $e ) {
			wp_delete_file( $temp );
			return new \WP_Error( 'wzio_encode_exception', $e->getMessage() );
		}

		if ( ! $written || ! file_exists( $temp ) || 0 === filesize( $temp ) ) {
			wp_delete_file( $temp );
			return new \WP_Error( 'wzio_encode_failed', __( 'The encoder did not produce an image.', 'webberzone-image-optimizer' ) );
		}

     // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		if ( ! rename( $temp, $destination ) ) {
			wp_delete_file( $temp );
			return new \WP_Error( 'wzio_move_failed', __( 'Could not move the converted file into place.', 'webberzone-image-optimizer' ) );
		}

		$permissions = fileperms( $destination );

		if ( false !== $permissions ) {
			// Match the permissions WordPress uses for uploaded files.
			chmod( $destination, ( defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		}

		clearstatcache( true, $destination );

		return true;
	}
}
