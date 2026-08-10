<?php
/**
 * Imagick conversion driver.
 *
 * @package WebberZone\Image_Optimizer
 */

namespace WebberZone\Image_Optimizer\Drivers;

use WebberZone\Image_Optimizer\Util\Helpers;

if ( ! defined( 'WPINC' ) ) {
	exit;
}

/**
 * Converts images using the Imagick extension.
 *
 * Imagick is preferred over GD: it encodes AVIF, keeps animated frames, and
 * exposes the encoder tuning options that make the output competitive.
 *
 * @since 0.9.0
 */
class Imagick_Driver extends Driver {

	/**
	 * Cache of the formats reported by the ImageMagick delegate list.
	 *
	 * @since 0.9.0
	 * @var array<string, bool>|null
	 */
	private static $format_support = null;

	/**
	 * Whether the Imagick extension is loaded.
	 *
	 * @since 0.9.0
	 *
	 * @return bool True when available.
	 */
	public static function is_available(): bool {
		return extension_loaded( 'imagick' ) && class_exists( '\Imagick' ) && class_exists( '\ImagickPixel' );
	}

	/**
	 * Machine name of the driver.
	 *
	 * @since 0.9.0
	 *
	 * @return string Driver slug.
	 */
	public static function get_name(): string {
		return 'imagick';
	}

	/**
	 * Whether Imagick can encode the given format.
	 *
	 * `queryFormats()` reports registered delegates, which is necessary but not
	 * sufficient — the delegate may still fail at encode time. Treat this as a
	 * fast pre-filter; {@see Capabilities} performs the real encode probe.
	 *
	 * @since 0.9.0
	 *
	 * @param string $format Target format slug.
	 * @return bool True when the delegate is registered.
	 */
	public function supports( string $format ): bool {
		if ( ! self::is_available() ) {
			return false;
		}

		if ( null === self::$format_support ) {
			self::$format_support = array();

			try {
				$formats = array_map( 'strtoupper', \Imagick::queryFormats() );
			} catch ( \Throwable $e ) {
				$formats = array();
			}

			foreach ( Helpers::get_formats() as $slug ) {
				self::$format_support[ $slug ] = in_array( strtoupper( $slug ), $formats, true );
			}
		}

		return ! empty( self::$format_support[ $format ] );
	}

	/**
	 * Encode a source image into the target format.
	 *
	 * @since 0.9.0
	 *
	 * @param string               $source      Absolute path to the source image.
	 * @param string               $destination Absolute path to write.
	 * @param string               $format      Target format slug.
	 * @param array<string, mixed> $args        Encoding arguments.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function convert( string $source, string $destination, string $format, array $args ) {
		$args = $this->parse_args( $args );

		return $this->write_atomic(
			$destination,
			function ( string $temp ) use ( $source, $format, $args ): bool {
				$image = new \Imagick();

				try {
					$image->readImage( $source );

					$animated = $image->getNumberImages() > 1;

					if ( $animated ) {
						$image = $image->coalesceImages();
					}

					// Keep the colour profile so conversion does not shift colours,
					// but drop EXIF, IPTC and thumbnails.
					$profiles = array();

					if ( $args['strip'] ) {
						try {
							$profiles = $image->getImageProfiles( 'icc', true );
						} catch ( \Throwable $e ) {
							$profiles = array();
						}
					}

					foreach ( $image as $frame ) {
						$frame->setImageFormat( $format );

						if ( $args['strip'] ) {
							$frame->stripImage();

							if ( ! empty( $profiles['icc'] ) ) {
								$frame->profileImage( 'icc', $profiles['icc'] );
							}
						}

						$frame->setImageCompressionQuality( $args['quality'] );

						if ( 'webp' === $format ) {
							$frame->setOption( 'webp:method', (string) $args['effort'] );
							$frame->setOption( 'webp:alpha-quality', (string) $args['alpha_quality'] );
							$frame->setOption( 'webp:lossless', $args['lossless'] ? 'true' : 'false' );
						}

						if ( 'avif' === $format ) {
							$frame->setOption( 'heic:speed', (string) max( 0, 6 - $args['effort'] ) );

							if ( $args['lossless'] ) {
								$frame->setImageCompressionQuality( 100 );
							}
						}
					}

					$image->setFormat( $format );

					if ( $animated ) {
						$result = $image->writeImages( $temp, true );
					} else {
						$result = $image->writeImage( $temp );
					}

					return (bool) $result;
				} finally {
					$image->clear();
					$image->destroy();
				}
			}
		);
	}
}
