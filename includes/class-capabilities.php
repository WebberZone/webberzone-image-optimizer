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
	 * @return array{version: string, drivers: array<string, array<string, bool>>, formats: array<string, string>, quality: array<string, bool>} Report.
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
	 * @return array{version: string, drivers: array<string, array<string, bool>>, formats: array<string, string>, quality: array<string, bool>} Report.
	 */
	private static function probe(): array {
		$report = array(
			'version' => WZIO_VERSION,
			'drivers' => array(),
			'formats' => array(),
			'quality' => array(),
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

					// A probe only answers yes or no, so it encodes at the cheapest effort.
					$result = $driver->convert(
						$source,
						$target,
						$format,
						array(
							'quality' => 70,
							'effort'  => 0,
						)
					);
					$works  = ( true === $result );

					Helpers::delete_file( $target );
				}

				$report['drivers'][ $name ][ $format ] = $works;

				// First driver that works owns the format.
				if ( $works && ! isset( $report['formats'][ $format ] ) ) {
					$report['formats'][ $format ] = $name;
					$report['quality'][ $format ] = self::quality_changes_output( $driver, $source, $format );
				}
			}
		}

		if ( '' !== $source ) {
			Helpers::delete_file( $source );
		}

		return $report;
	}

	/**
	 * Whether an encoder actually acts on the quality argument.
	 *
	 * Some ImageMagick builds ignore quality for AVIF entirely, which turns the
	 * lower-quality retry into a guaranteed second encode of an identical file.
	 *
	 * @since 1.1.0
	 *
	 * @param  Driver $driver Driver to test.
	 * @param  string $source Absolute path to the probe image.
	 * @param  string $format Target format slug.
	 * @return bool True when two qualities produce different output.
	 */
	private static function quality_changes_output( Driver $driver, string $source, string $format ): bool {
		$hashes = array();

		foreach ( array( 20, 90 ) as $quality ) {
			$target = $source . '.q' . $quality . '.' . $format;
			$result = $driver->convert(
				$source,
				$target,
				$format,
				array(
					'quality' => $quality,
					'effort'  => 0,
				)
			);

			clearstatcache( true, $target );

			if ( true !== $result || ! file_exists( $target ) ) {
				Helpers::delete_file( $target );

				// An inconclusive probe must not disable the retry.
				return true;
			}

			$hash = md5_file( $target );

			Helpers::delete_file( $target );

			if ( false === $hash ) {
				return true;
			}

			$hashes[] = $hash;
		}

		// Compared by content, not length: two qualities can land on the same
		// byte count while encoding differently, and only identical bytes prove
		// the encoder discarded the quality argument.
		return $hashes[0] !== $hashes[1];
	}

	/**
	 * Whether the driver serving a format acts on the quality argument.
	 *
	 * @since 1.1.0
	 *
	 * @param  string $format Target format slug.
	 * @return bool True when quality changes the encoded output.
	 */
	public static function quality_is_honoured( string $format ): bool {
		$report = self::get();

		// Absent from a report written before this probe existed.
		return (bool) ( $report['quality'][ $format ] ?? true );
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
