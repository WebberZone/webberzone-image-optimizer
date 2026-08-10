<?php
/**
 * Per-attachment conversion bookkeeping.
 *
 * @package WebberZone\Image_Optimizer
 */

namespace WebberZone\Image_Optimizer;

use WebberZone\Image_Optimizer\Util\Helpers;

if ( ! defined( 'WPINC' ) ) {
	exit;
}

/**
 * Reads and writes the conversion record stored against each attachment.
 *
 * The record tracks each file's conversion result for administration, cleanup
 * and repeat conversions. Delivery checks sidecar existence through Resolver's
 * request and object caches, avoiding repeated filesystem stats on a page.
 *
 * Records are keyed by file basename, because one attachment owns the scaled
 * original plus every registered sub-size, and each of those converts (or fails
 * to convert) independently.
 *
 * @since 1.0.0
 */
class Attachment_Meta {

	/**
	 * Post meta key holding the record.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const META_KEY = '_wzio_data';

	/**
	 * Current record schema version.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const SCHEMA = 1;

	/**
	 * Get the conversion record for an attachment.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{v: int, updated: int, files: array<string, array<string, mixed>>} Record.
	 */
	public static function get( int $attachment_id ): array {
		$data = get_post_meta( $attachment_id, self::META_KEY, true );

		if ( ! is_array( $data ) || ( $data['v'] ?? 0 ) !== self::SCHEMA ) {
			return self::empty_record();
		}

		// Normalise once here so every caller can trust the shape.
		$files = array();

		foreach ( (array) ( $data['files'] ?? array() ) as $basename => $file_record ) {
			if ( is_array( $file_record ) ) {
				$files[ (string) $basename ] = $file_record;
			}
		}

		$data['files'] = $files;

		return $data;
	}

	/**
	 * An empty record.
	 *
	 * @since 1.0.0
	 *
	 * @return array{v: int, updated: int, files: array<string, array<string, mixed>>} Record.
	 */
	public static function empty_record(): array {
		return array(
			'v'       => self::SCHEMA,
			'updated' => 0,
			'files'   => array(),
		);
	}

	/**
	 * Store the conversion record for an attachment.
	 *
	 * @since 1.0.0
	 *
	 * @param int                                                                       $attachment_id Attachment ID.
	 * @param array{v?: int, updated?: int, files: array<string, array<string, mixed>>} $record        Record.
	 * @return void
	 */
	public static function set( int $attachment_id, array $record ): void {
		$record['v']       = self::SCHEMA;
		$record['updated'] = time();

		update_post_meta( $attachment_id, self::META_KEY, $record );
	}

	/**
	 * Remove the conversion record for an attachment.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public static function delete( int $attachment_id ): void {
		delete_post_meta( $attachment_id, self::META_KEY );
	}

	/**
	 * Get the record for a single source file within an attachment.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $basename      Source file basename.
	 * @return array<string, mixed> File record, empty when unknown.
	 */
	public static function get_file( int $attachment_id, string $basename ): array {
		$record = self::get( $attachment_id );

		return $record['files'][ $basename ] ?? array();
	}

	/**
	 * Whether a usable sidecar was produced for a file and format.
	 *
	 * Skipped files (the sidecar came out larger than the source) and failures
	 * both return false: the delivery layer must fall back to the original.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $file_record File record.
	 * @param string               $format      Target format slug.
	 * @return bool True when a sidecar should be served.
	 */
	public static function is_converted( array $file_record, string $format ): bool {
		return isset( $file_record[ $format ]['bytes'] ) && $file_record[ $format ]['bytes'] > 0;
	}

	/**
	 * Build the record entry for a successful conversion.
	 *
	 * @since 1.0.0
	 *
	 * @param int $bytes Sidecar size in bytes.
	 * @return array{bytes: int} Entry.
	 */
	public static function converted_entry( int $bytes ): array {
		return array( 'bytes' => $bytes );
	}

	/**
	 * Build the record entry for a file that was deliberately not converted.
	 *
	 * @since 1.0.0
	 *
	 * @param string $reason Machine-readable reason.
	 * @return array{skip: string} Entry.
	 */
	public static function skipped_entry( string $reason ): array {
		return array( 'skip' => $reason );
	}

	/**
	 * Build the record entry for a failed conversion.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Error message.
	 * @return array{error: string} Entry.
	 */
	public static function error_entry( string $message ): array {
		return array( 'error' => mb_substr( $message, 0, 250 ) );
	}

	/**
	 * Summarise the bytes stored and saved for an attachment.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{source: int, converted: int, saved: int, files: int, formats: array<string, int>} Totals.
	 */
	public static function get_totals( int $attachment_id ): array {
		$record = self::get( $attachment_id );

		$totals = array(
			'source'    => 0,
			'converted' => 0,
			'saved'     => 0,
			'files'     => 0,
			'formats'   => array(),
		);

		foreach ( $record['files'] as $file_record ) {
			$source = (int) ( $file_record['size'] ?? 0 );
			$best   = 0;

			foreach ( Helpers::get_formats() as $format ) {
				if ( ! self::is_converted( $file_record, $format ) ) {
					continue;
				}

				$bytes = (int) $file_record[ $format ]['bytes'];

				$totals['formats'][ $format ] = ( $totals['formats'][ $format ] ?? 0 ) + $bytes;

				// The saving is measured against the format a browser actually
				// receives, which is the smallest sidecar it can decode.
				if ( 0 === $best || $bytes < $best ) {
					$best = $bytes;
				}
			}

			if ( 0 === $best ) {
				continue;
			}

			++$totals['files'];
			$totals['source']    += $source;
			$totals['converted'] += $best;
			$totals['saved']     += max( 0, $source - $best );
		}

		return $totals;
	}
}
