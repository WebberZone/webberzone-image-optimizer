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
 * Stores per-file conversion results keyed by basename for each attachment.
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
	 * Post meta key holding an interrupted background conversion.
	 *
	 * Kept separate from the completed record so scanners and delivery never
	 * mistake a partially processed attachment for a finished one.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const PROGRESS_META_KEY = '_wzio_progress';

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
		return self::get_record( $attachment_id, self::META_KEY );
	}

	/**
	 * Get an interrupted background conversion.
	 *
	 * @since 1.1.0
	 *
	 * @param  int $attachment_id Attachment ID.
	 * @return array{v: int, updated: int, files: array<string, array<string, mixed>>, context?: string, processed: array<int, string>, ...} Record.
	 */
	public static function get_progress( int $attachment_id ): array {
		$data              = self::get_record( $attachment_id, self::PROGRESS_META_KEY );
		$data['processed'] = array_values( array_unique( array_map( 'strval', (array) ( $data['processed'] ?? array() ) ) ) );

		return $data;
	}

	/**
	 * Read and normalise a stored record.
	 *
	 * @since 1.1.0
	 *
	 * @param  int    $attachment_id Attachment ID.
	 * @param  string $meta_key      Post meta key.
	 * @return array{v: int, updated: int, files: array<string, array<string, mixed>>, ...} Record.
	 */
	private static function get_record( int $attachment_id, string $meta_key ): array {
		$data = get_post_meta( $attachment_id, $meta_key, true );

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
	 * Store an interrupted background conversion.
	 *
	 * @since 1.1.0
	 *
	 * @param int                                                                       $attachment_id Attachment ID.
	 * @param array{v?: int, updated?: int, files: array<string, array<string, mixed>>} $record        Partial record.
	 * @param string                                                                    $context       Conversion context fingerprint.
	 * @param array<int, string>                                                        $processed     Basenames completed in this pass.
	 * @return void
	 */
	public static function set_progress( int $attachment_id, array $record, string $context, array $processed ): void {
		$record['v']         = self::SCHEMA;
		$record['updated']   = time();
		$record['context']   = $context;
		$record['processed'] = array_values( array_unique( array_map( 'strval', $processed ) ) );

		update_post_meta( $attachment_id, self::PROGRESS_META_KEY, $record );
	}

	/**
	 * Remove an interrupted background conversion.
	 *
	 * @since 1.1.0
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public static function delete_progress( int $attachment_id ): void {
		delete_post_meta( $attachment_id, self::PROGRESS_META_KEY );
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
		self::delete_progress( $attachment_id );
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
	 * @since 1.1.0 Added the optional quality value.
	 *
	 * @param int      $bytes   Sidecar size in bytes.
	 * @param int|null $quality Effective lossy quality, when known.
	 * @param bool     $reduced Whether the copy needed the lower-quality retry.
	 * @return array{bytes: int, quality?: int, reduced?: bool} Entry.
	 */
	public static function converted_entry( int $bytes, ?int $quality = null, bool $reduced = false ): array {
		$entry = array( 'bytes' => $bytes );

		if ( null !== $quality ) {
			$entry['quality'] = max( 1, min( 100, $quality ) );
		}

		if ( $reduced ) {
			$entry['reduced'] = true;
		}

		return $entry;
	}

	/**
	 * Build the record entry for a file that was deliberately not converted.
	 *
	 * @since 1.0.0
	 * @since 1.1.0 Added the optional quality value.
	 *
	 * @param string   $reason  Machine-readable reason.
	 * @param int|null $quality Final lossy quality attempted, when applicable.
	 * @return array{skip: string, quality?: int} Entry.
	 */
	public static function skipped_entry( string $reason, ?int $quality = null ): array {
		$entry = array( 'skip' => $reason );

		if ( null !== $quality ) {
			$entry['quality'] = max( 1, min( 100, $quality ) );
		}

		return $entry;
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
	 * @return array{source: int, converted: int, saved: int, files: int, reduced: int, formats: array<string, int>} Totals.
	 */
	public static function get_totals( int $attachment_id ): array {
		$record = self::get( $attachment_id );

		$totals = array(
			'source'    => 0,
			'converted' => 0,
			'saved'     => 0,
			'files'     => 0,
			'reduced'   => 0,
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

				if ( ! empty( $file_record[ $format ]['reduced'] ) ) {
					++$totals['reduced'];
				}

				$totals['formats'][ $format ] = ( $totals['formats'][ $format ] ?? 0 ) + $bytes;

				// Measure savings against the smallest delivered sidecar.
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
