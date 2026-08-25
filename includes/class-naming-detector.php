<?php
/**
 * Detection of optimized copies left behind by another plugin.
 *
 * @package WebberZone\Image_Optimizer
 */

namespace WebberZone\Image_Optimizer;

use WebberZone\Image_Optimizer\Util\Helpers;

if ( ! defined( 'WPINC' ) ) {
	exit;
}

/**
 * Samples the media library for sidecars this site is not serving.
 *
 * Every plugin that writes local WebP or AVIF files next to the original uses
 * one of the two naming strategies this plugin supports, and decides delivery
 * on file existence alone. Matching the naming is therefore the whole of the
 * migration: the files already on disk start being served, and the next
 * conversion run adopts or replaces them in place.
 *
 * @since 1.0.1
 */
class Naming_Detector {

	/**
	 * Where the report is cached.
	 *
	 * @since 1.0.1
	 * @var string
	 */
	const TRANSIENT = 'wzio_foreign_naming';

	/**
	 * How many attachments to examine.
	 *
	 * @since 1.0.1
	 * @var int
	 */
	const SAMPLE = 100;

	/**
	 * How long a report is trusted.
	 *
	 * @since 1.0.1
	 * @var int
	 */
	const TTL = WEEK_IN_SECONDS;

	/**
	 * How many images must be affected before a switch is worth offering.
	 *
	 * @since 1.0.1
	 * @var int
	 */
	const MINIMUM = 5;

	/**
	 * The stored report, when one applies to the site as it is now.
	 *
	 * @since 1.0.1
	 *
	 * @return array<string, mixed>|null Report, or null when a scan is needed.
	 */
	public static function get_cached_report(): ?array {
		$report = get_transient( self::TRANSIENT );

		if ( ! is_array( $report ) || WZIO_VERSION !== ( $report['version'] ?? '' ) ) {
			return null;
		}

		if ( ( $report['configured'] ?? '' ) !== (string) \wzio_get_option( 'sidecar_naming', 'append' ) ) {
			return null;
		}

		return $report;
	}

	/**
	 * Keep a report for later requests.
	 *
	 * @since 1.0.1
	 *
	 * @param  array<string, mixed> $report Report to store.
	 * @return void
	 */
	public static function store( array $report ): void {
		set_transient( self::TRANSIENT, $report, self::TTL );
	}

	/**
	 * Examine a sample of attachments for sidecars under either naming.
	 *
	 * @since 1.0.1
	 *
	 * @return array<string, mixed> Report.
	 */
	public static function scan(): array {
		$report = array(
			'version'    => WZIO_VERSION,
			'configured' => (string) \wzio_get_option( 'sidecar_naming', 'append' ),
			'sampled'    => 0,
			'images'     => array(
				'append'  => 0,
				'replace' => 0,
			),
			'files'      => array(
				'append'  => 0,
				'replace' => 0,
			),
		);

		// Attachments carrying a record are skipped, so only foreign files count.
		$ids = Scanner::get_candidate_ids( self::SAMPLE );

		$report['sampled'] = count( $ids );

		foreach ( $ids as $id ) {
			$meta  = wp_get_attachment_metadata( $id );
			$meta  = is_array( $meta ) ? $meta : array();
			$files = Converter::get_attachment_files( $id, $meta );

			if ( empty( $files ) ) {
				continue;
			}

			$dimensions = self::source_dimensions( $meta );
			$hits       = array(
				'append'  => 0,
				'replace' => 0,
			);

			foreach ( $files as $basename => $path ) {
				foreach ( array( 'append', 'replace' ) as $naming ) {
					foreach ( Helpers::get_formats() as $format ) {
						$sidecar = Helpers::sidecar_path( $path, $format, $naming );

						if ( $sidecar === $path || ! file_exists( $sidecar ) ) {
							continue;
						}

						if ( ! self::is_sidecar( $sidecar, $naming, $dimensions[ $basename ] ?? null ) ) {
							continue;
						}

						++$hits[ $naming ];
					}
				}
			}

			foreach ( $hits as $naming => $count ) {
				if ( $count > 0 ) {
					++$report['images'][ $naming ];
					$report['files'][ $naming ] += $count;
				}
			}
		}

		return $report;
	}

	/**
	 * Which naming the site should switch to, if any.
	 *
	 * @since 1.0.1
	 *
	 * @param  array<string, mixed> $report Report from get_report().
	 * @return string Naming slug, or an empty string when no switch is warranted.
	 */
	public static function get_suggestion( array $report ): string {
		$configured = (string) ( $report['configured'] ?? 'append' );
		$other      = 'append' === $configured ? 'replace' : 'append';

		$found   = (int) ( $report['images'][ $other ] ?? 0 );
		$serving = (int) ( $report['images'][ $configured ] ?? 0 );

		if ( $found < self::MINIMUM || $found <= $serving ) {
			return '';
		}

		return $other;
	}

	/**
	 * Discard the cached report.
	 *
	 * @since 1.0.1
	 *
	 * @return void
	 */
	public static function forget(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Map each source basename to the dimensions recorded for it.
	 *
	 * @since 1.0.1
	 *
	 * @param  array<string, mixed> $meta Attachment metadata.
	 * @return array<string, array{0: int, 1: int}> Dimensions keyed by basename.
	 */
	private static function source_dimensions( array $meta ): array {
		$dimensions = array();

		if ( ! empty( $meta['file'] ) && ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
			$dimensions[ wp_basename( (string) $meta['file'] ) ] = array( (int) $meta['width'], (int) $meta['height'] );
		}

		if ( empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
			return $dimensions;
		}

		foreach ( $meta['sizes'] as $size ) {
			if ( empty( $size['file'] ) || empty( $size['width'] ) || empty( $size['height'] ) ) {
				continue;
			}

			$dimensions[ wp_basename( (string) $size['file'] ) ] = array( (int) $size['width'], (int) $size['height'] );
		}

		return $dimensions;
	}

	/**
	 * Whether a candidate file is a sidecar for the source rather than an upload.
	 *
	 * Append naming needs no test: nothing but a sidecar is called `photo.jpg.webp`.
	 * Replace naming turns `photo.jpg` into `photo.webp`, which is also what a
	 * separately uploaded WebP image would be called, so it has to be proven. A
	 * sidecar always keeps the dimensions of its source; a candidate whose size
	 * cannot be read, or whose source dimensions are unknown, stays uncounted
	 * rather than inflating the case for a switch that is hard to undo.
	 *
	 * @since 1.0.1
	 *
	 * @param  string                     $sidecar Candidate sidecar path.
	 * @param  string                     $naming  Naming the candidate was built with.
	 * @param  array{0: int, 1: int}|null $source  Source dimensions, when known.
	 * @return bool True when the candidate is a sidecar.
	 */
	private static function is_sidecar( string $sidecar, string $naming, ?array $source ): bool {
		if ( 'append' === $naming ) {
			return true;
		}

		if ( null === $source ) {
			return false;
		}

		$size = @getimagesize( $sidecar ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! is_array( $size ) || empty( $size[0] ) || empty( $size[1] ) ) {
			return false;
		}

		return (int) $size[0] === $source[0] && (int) $size[1] === $source[1];
	}
}
