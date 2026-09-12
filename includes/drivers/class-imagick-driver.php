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
 * Converts images, including animations, with the preferred Imagick extension.
 *
 * @since 1.0.0
 */
class Imagick_Driver extends Driver {

	/**
	 * Cache of the formats reported by the ImageMagick delegate list.
	 *
	 * @since 1.0.0
	 * @var array<string, bool>|null
	 */
	private static $format_support = null;

	/**
	 * Whether the Imagick extension is loaded.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when available.
	 */
	public static function is_available(): bool {
		return extension_loaded( 'imagick' ) && class_exists( '\Imagick' ) && class_exists( '\ImagickPixel' );
	}

	/**
	 * Machine name of the driver.
	 *
	 * @since 1.0.0
	 *
	 * @return string Driver slug.
	 */
	public static function get_name(): string {
		return 'imagick';
	}

	/**
	 * Whether Imagick reports the format; capability probes verify actual encoding.
	 *
	 * @since 1.0.0
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
	 * Whether the source format can carry transparency.
	 *
	 * Declared here rather than on the shared base class, where it could collide
	 * with a name a third-party driver already uses.
	 *
	 * @since 1.1.0
	 *
	 * @param  array<string, mixed> $args Encoding arguments.
	 * @return bool True when the source may carry an alpha channel.
	 */
	protected static function source_has_alpha( array $args ): bool {
		// An unknown source takes the cautious path; only JPEG rules alpha out.
		return 'image/jpeg' !== ( $args['mime'] ?? '' );
	}

	/**
	 * Map the plugin's 0-6 effort onto the encoder's own speed scale.
	 *
	 * The encoder counts the other way: libheif treats 0 as the slowest and 9 as
	 * the fastest. Effort 4, the default, is the measured knee - past it files
	 * grow faster than the CPU saving justifies.
	 *
	 * @since 1.1.0
	 *
	 * @param  int  $effort       Plugin effort level, 0 to 6.
	 * @param  bool $source_alpha Whether the source can carry transparency.
	 * @return int libheif speed, 0 to 9.
	 */
	protected static function avif_speed( int $effort, bool $source_alpha ): int {
		$speeds = array( 9, 9, 9, 8, 7, 6, 4 );
		$speed  = $speeds[ max( 0, min( 6, $effort ) ) ];

		// Speed 9 inflates a transparent source by up to two fifths.
		return $source_alpha ? min( 8, $speed ) : $speed;
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

		$avif_speed = self::avif_speed( $args['effort'], self::source_has_alpha( $args ) );

		return $this->write_atomic(
			$destination,
			function ( string $temp ) use ( $source, $format, $args, $avif_speed ): bool {
				$image = new \Imagick();

				// Limit Imagick to one thread for predictable shared-host resource use.
				try {
					\Imagick::setResourceLimit( \Imagick::RESOURCETYPE_THREAD, 1 );
				} catch ( \Throwable $e ) {
					unset( $e );
				}

				try {
					$image->readImage( $source );

					$animated = $image->getNumberImages() > 1;

					if ( $animated ) {
						$image = $image->coalesceImages();
					}

					// Preserve colour profiles while stripping other metadata.
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
							$frame->setOption( 'heic:speed', (string) $avif_speed );
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
			},
			$args['max_bytes']
		);
	}
}
