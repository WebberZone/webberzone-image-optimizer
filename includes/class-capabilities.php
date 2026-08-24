<?php
/**
 * Server capability detection.
 *
 * @package WebberZone\Image_Optimizer
 */

namespace WebberZone\Image_Optimizer;

use WebberZone\Image_Optimizer\Drivers\Driver;
use WebberZone\Image_Optimizer\Drivers\GD_Driver;
use WebberZone\Image_Optimizer\Drivers\Imagick_Driver;
use WebberZone\Image_Optimizer\Util\Helpers;

if ( ! defined( 'WPINC' ) ) {
	exit;
}

/**
 * Verifies and caches driver support by encoding a bundled probe image.
 *
 * @since 1.0.0
 */
class Capabilities {

	/**
	 * Option holding the cached probe results.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const OPTION = 'wzio_capabilities';

	/**
	 * A 64x64 PNG with an alpha channel, used to probe the encoders.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const PROBE_IMAGE = 'iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAYAAACqaXHeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAb0lEQVR42u3QwQkAIAwEwTOk'
		. '/1rsUMsI6Dz2vTAryelUBtuz/64k/wYAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'
		. 'AAAAAAAAAAAAAAAAAAAAAF7oAgQIJKOaCDNeAAAAAElFTkSuQmCC';

	/**
	 * In-request cache of the probe results.
	 *
	 * @since 1.0.0
	 * @var array<string, mixed>|null
	 */
	private static $cache = null;

	/**
	 * Get the capability report.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $force Whether to discard the cached report and probe again.
	 * @return array{version: string, drivers: array<string, array<string, bool>>, formats: array<string, string>} Report.
	 */
	public static function get( bool $force = false ): array {
		if ( ! $force && null !== self::$cache ) {
			return self::$cache;
		}

		$report = $force ? false : get_option( self::OPTION );

		if ( ! is_array( $report ) || ( $report['version'] ?? '' ) !== WZIO_VERSION ) {
			$report = self::probe();
			update_option( self::OPTION, $report, false );
		}

		self::$cache = $report;

		return $report;
	}

	/**
	 * Run the encoder probes.
	 *
	 * @since 1.0.0
	 *
	 * @return array{version: string, drivers: array<string, array<string, bool>>, formats: array<string, string>} Report.
	 */
	private static function probe(): array {
		$report = array(
			'version' => WZIO_VERSION,
			'drivers' => array(),
			'formats' => array(),
		);

		$source = self::write_probe_image();

		foreach ( self::get_driver_classes() as $class ) {
			if ( ! $class::is_available() ) {
				continue;
			}

			$driver = new $class();
			$name   = $class::get_name();

			$report['drivers'][ $name ] = array();

			foreach ( Helpers::get_formats() as $format ) {
				$works = false;

				if ( '' !== $source && $driver->supports( $format ) ) {
					$target = $source . '.' . $format;
					$result = $driver->convert( $source, $target, $format, array( 'quality' => 70 ) );
					$works  = ( true === $result );

					Helpers::delete_file( $target );
				}

				$report['drivers'][ $name ][ $format ] = $works;

				// First driver that works owns the format.
				if ( $works && ! isset( $report['formats'][ $format ] ) ) {
					$report['formats'][ $format ] = $name;
				}
			}
		}

		if ( '' !== $source ) {
			Helpers::delete_file( $source );
		}

		return $report;
	}

	/**
	 * Write the bundled probe image to a temporary file.
	 *
	 * @since 1.0.0
	 *
	 * @return string Absolute path, or an empty string on failure.
	 */
	private static function write_probe_image(): string {
		$binary = base64_decode( self::PROBE_IMAGE, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $binary ) {
			return '';
		}

		$path = wp_tempnam( 'wzio-probe.png' );

		if ( ! $path ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $path, $binary ) ) {
			wp_delete_file( $path );
			return '';
		}

		return $path;
	}

	/**
	 * Driver classes in order of preference.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, class-string<Driver>> Driver class names.
	 */
	private static function get_driver_classes(): array {
		/**
		 * Filter the conversion drivers and the order they are tried in.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, class-string<Driver>> $classes Driver class names.
		 */
		return (array) apply_filters(
			'wzio_driver_classes',
			array( Imagick_Driver::class, GD_Driver::class )
		);
	}

	/**
	 * Get a working driver for the given format.
	 *
	 * @since 1.0.0
	 *
	 * @param string $format Target format slug.
	 * @return Driver|null Driver instance, or null when the format is unsupported.
	 */
	public static function get_driver( string $format ): ?Driver {
		$report = self::get();
		$name   = $report['formats'][ $format ] ?? '';

		if ( '' === $name ) {
			return null;
		}

		foreach ( self::get_driver_classes() as $class ) {
			if ( $class::get_name() === $name && $class::is_available() ) {
				return new $class();
			}
		}

		return null;
	}

	/**
	 * Whether the server can encode the given format.
	 *
	 * @since 1.0.0
	 *
	 * @param string $format Target format slug.
	 * @return bool True when supported.
	 */
	public static function supports( string $format ): bool {
		$report = self::get();

		return ! empty( $report['formats'][ $format ] );
	}

	/**
	 * Formats this server can encode, in delivery preference order.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, string> Format slugs.
	 */
	public static function get_supported_formats(): array {
		return array_values( array_filter( Helpers::get_formats(), array( __CLASS__, 'supports' ) ) );
	}

	/**
	 * Discard the cached report so the next call probes again.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$cache = null;
		delete_option( self::OPTION );
	}
}
