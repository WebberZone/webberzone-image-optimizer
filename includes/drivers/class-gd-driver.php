<?php
/**
 * GD conversion driver.
 *
 * @package WebberZone\Image_Optimizer
 */

namespace WebberZone\Image_Optimizer\Drivers;

if ( ! defined( 'WPINC' ) ) {
	exit;
}

/**
 * Converts images using the GD extension.
 *
 * GD is the fallback for hosts without Imagick. It cannot preserve animation,
 * so animated GIFs are rejected rather than silently flattened to one frame.
 *
 * @since 1.0.0
 */
class GD_Driver extends Driver {

	/**
	 * Whether the GD extension is loaded.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when available.
	 */
	public static function is_available(): bool {
		return extension_loaded( 'gd' ) && function_exists( 'imagecreatefromstring' );
	}

	/**
	 * Machine name of the driver.
	 *
	 * @since 1.0.0
	 *
	 * @return string Driver slug.
	 */
	public static function get_name(): string {
		return 'gd';
	}

	/**
	 * Whether GD can encode the given format.
	 *
	 * @since 1.0.0
	 *
	 * @param string $format Target format slug.
	 * @return bool True when the encoder function exists.
	 */
	public function supports( string $format ): bool {
		if ( ! self::is_available() ) {
			return false;
		}

		switch ( $format ) {
			case 'webp':
				return function_exists( 'imagewebp' );
			case 'avif':
				return function_exists( 'imageavif' );
		}

		return false;
	}

	/**
	 * Encode a source image into the target format.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $source      Absolute path to the source image.
	 * @param string               $destination Absolute path to write.
	 * @param string               $format      Target format slug.
	 * @param array<string, mixed> $args        Encoding arguments.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function convert( string $source, string $destination, string $format, array $args ) {
		$args = $this->parse_args( $args );

		if ( ! $this->supports( $format ) ) {
			return new \WP_Error(
				'wzio_gd_unsupported',
				/* translators: %s: image format. */
				sprintf( __( 'GD on this server cannot encode %s.', 'webberzone-image-optimizer' ), strtoupper( $format ) )
			);
		}

		return $this->write_atomic(
			$destination,
			function ( string $temp ) use ( $source, $format, $args ): bool {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$contents = file_get_contents( $source );

				if ( false === $contents ) {
					return false;
				}

				$image = @imagecreatefromstring( $contents ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

				unset( $contents );

				if ( ! $image ) {
					return false;
				}

				// Palette images must become true colour before the alpha flags apply.
				if ( function_exists( 'imageistruecolor' ) && ! imageistruecolor( $image ) ) {
					imagepalettetotruecolor( $image );
				}

				imagealphablending( $image, false );
				imagesavealpha( $image, true );

				if ( 'webp' === $format ) {
					// IMG_WEBP_LOSSLESS only exists from PHP 8.1; its value is 101.
					$lossless = defined( 'IMG_WEBP_LOSSLESS' ) ? constant( 'IMG_WEBP_LOSSLESS' ) : 101;
					$quality  = $args['lossless'] ? $lossless : $args['quality'];

					// The image is released when this closure returns and the last
					// reference to it goes away, on both the PHP 7 resource and the
					// PHP 8 GdImage. imagedestroy() is deprecated from PHP 8.5.
					return imagewebp( $image, $temp, $quality );
				}

				// imageavif() arrived in PHP 8.1. supports() has already confirmed
				// it exists; calling it indirectly keeps older PHP parseable.
				return (bool) call_user_func( 'imageavif', $image, $temp, $args['lossless'] ? -1 : $args['quality'] );
			}
		);
	}
}
