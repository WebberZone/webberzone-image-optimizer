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
 * Converts static images with the fallback GD extension.
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
	 * The encoder counts the other way: libavif treats 0 as the slowest and 10 as
	 * the fastest, though 9 and 10 produce identical output. Effort 4, the
	 * default, is the measured knee.
	 *
	 * @since 1.1.0
	 *
	 * @param  int  $effort       Plugin effort level, 0 to 6.
	 * @param  bool $source_alpha Whether the source can carry transparency.
	 * @return int libavif speed, 0 to 9.
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

		if ( ! $this->supports( $format ) ) {
			return new \WP_Error(
				'wzio_gd_unsupported',
				/* translators: %s: image format. */
				sprintf( __( 'GD on this server cannot encode %s.', 'webberzone-image-optimizer' ), strtoupper( $format ) )
			);
		}

		return $this->write_atomic(
			$destination,
			function ( string $temp ) use ( $source, $format, $args, $avif_speed ): bool {
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

					// The PHP 7 resource or PHP 8 GdImage is released with the closure.
					return imagewebp( $image, $temp, $quality );
				}

				// imageavif() arrived in PHP 8.1. supports() has already confirmed
				// it exists; calling it indirectly keeps older PHP parseable.
				return (bool) call_user_func( 'imageavif', $image, $temp, $args['quality'], $avif_speed );
			},
			$args['max_bytes']
		);
	}
}
