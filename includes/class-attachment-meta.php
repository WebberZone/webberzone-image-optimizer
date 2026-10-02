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
	 * Post meta key holding the source bytes of converted files, for SQL sums.
	 *
	 * @since 1.1.2
	 * @var string
	 */
	const SOURCE_META_KEY = '_wzio_source_bytes';

	/**
	 * Post meta key holding the bytes saved, for SQL sums.
	 *
	 * @since 1.1.2
	 * @var string
	 */
	const SAVED_META_KEY = '_wzio_saved_bytes';

	/**
	 * Current record schema version.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const SCHEMA = 3;

	/**
	 * Post meta key prefix holding the optimized copy bytes of one format.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const FORMAT_META_PREFIX = '_wzio_bytes_';

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

		if ( ! is_array( $data ) || ! in_array( ( $data['v'] ?? 0 ), array( 1, 2, self::SCHEMA ), true ) ) {
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
		self::set_byte_totals( $attachment_id, $record );
	}

	/**
	 * Store the record's byte totals as numeric meta so the library can be summed in SQL.
	 *
	 * @since 1.1.2
	 *
	 * @param int                                               $attachment_id Attachment ID.
	 * @param array{files: array<string, array<string, mixed>>} $record        Record.
	 * @return void
	 */
	public static function set_byte_totals( int $attachment_id, array $record ): void {
		$totals = self::summarise( $record );

		update_post_meta( $attachment_id, self::SOURCE_META_KEY, $totals['source'] );
		update_post_meta( $attachment_id, self::SAVED_META_KEY, $totals['saved'] );

		foreach ( Helpers::get_formats() as $format ) {
			update_post_meta( $attachment_id, self::FORMAT_META_PREFIX . $format, $totals['formats'][ $format ] ?? 0 );
		}
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
		delete_post_meta( $attachment_id, self::SOURCE_META_KEY );
		delete_post_meta( $attachment_id, self::SAVED_META_KEY );
		foreach ( Helpers::get_formats() as $format ) {
			delete_post_meta( $attachment_id, self::FORMAT_META_PREFIX . $format );
		}
		self::delete_progress( $attachment_id );
	}

	/**
	 * Count the format entries that were skipped or failed.
	 *
	 * @since 1.1.2
	 *
	 * @param array{files: array<string, array<string, mixed>>} $record Conversion record.
	 * @return int Number of retryable entries.
	 */
	public static function count_retryable( array $record ): int {
		$count = 0;

		foreach ( $record['files'] as $file_record ) {
			if ( isset( $file_record['original']['error'] ) ) {
				++$count;
			}
			foreach ( Helpers::get_formats() as $format ) {
				if ( isset( $file_record[ $format ]['skip'] ) || isset( $file_record[ $format ]['error'] ) ) {
					++$count;
				}
			}
		}

		return $count;
	}

	/**
	 * Forget skipped and failed entries so the next pass attempts them again.
	 *
	 * @since 1.1.2
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int Number of entries cleared.
	 */
	public static function clear_retryable( int $attachment_id ): int {
		$record  = self::get( $attachment_id );
		$cleared = 0;

		foreach ( $record['files'] as $basename => $file_record ) {
			if ( isset( $file_record['original']['error'] ) ) {
				unset( $record['files'][ $basename ]['original'] );
				++$cleared;
			}
			foreach ( Helpers::get_formats() as $format ) {
				if ( isset( $file_record[ $format ]['skip'] ) || isset( $file_record[ $format ]['error'] ) ) {
					unset( $record['files'][ $basename ][ $format ] );
					++$cleared;
				}
			}
		}

		if ( $cleared > 0 ) {
			self::set( $attachment_id, $record );
			self::delete_progress( $attachment_id );
		}

		return $cleared;
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
	 * @since 1.2.0 Added the optional settings fingerprint.
	 *
	 * @param int         $bytes       Sidecar size in bytes.
	 * @param int|null    $quality     Effective lossy quality, when known.
	 * @param bool        $reduced     Whether the copy needed the lower-quality retry.
	 * @param string|null $fingerprint Fingerprint of the settings that produced the copy, when known.
	 * @return array{bytes: int, quality?: int, reduced?: bool, fp?: string} Entry.
	 */
	public static function converted_entry( int $bytes, ?int $quality = null, bool $reduced = false, ?string $fingerprint = null ): array {
		$entry = array( 'bytes' => $bytes );

		if ( null !== $fingerprint && '' !== $fingerprint ) {
			$entry['fp'] = $fingerprint;
		}

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
		return self::summarise( self::get( $attachment_id ) );
	}

	/**
	 * Summarise the bytes stored and saved for a record.
	 *
	 * @since 1.1.2
	 *
	 * @param array{files: array<string, array<string, mixed>>} $record Record.
	 * @return array{source: int, converted: int, saved: int, files: int, reduced: int, formats: array<string, int>} Totals.
	 */
	public static function summarise( array $record ): array {
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

	/**
	 * Whether any optimized copy was made with settings other than the current ones.
	 *
	 * A copy with no fingerprint predates tracking and is never reported outdated.
	 *
	 * @since 1.2.0
	 *
	 * @param array{files: array<string, array<string, mixed>>} $record Record.
	 * @param array<string, mixed>                              $args   Current conversion arguments.
	 * @param string                                            $mime   Source MIME type.
	 * @return bool True when at least one copy is outdated.
	 */
	public static function is_outdated( array $record, array $args, string $mime ): bool {
		foreach ( $record['files'] as $file => $file_record ) {
			$file_mime = wp_check_filetype( (string) $file )['type'];
			$file_mime = $file_mime ? $file_mime : $mime;

			foreach ( (array) $args['formats'] as $format ) {
				$stored = $file_record[ $format ]['fp'] ?? '';

				if ( '' === $stored || ! self::is_converted( $file_record, (string) $format ) ) {
					continue;
				}

				if ( Converter::fingerprint( (string) $format, $args, $file_mime, isset( $file_record[ $format ]['quality'] ) ? (int) $file_record[ $format ]['quality'] : null, ! empty( $file_record[ $format ]['reduced'] ) ) !== $stored ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Drop disposable sidecar results while retaining original recovery summaries.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public static function delete_sidecar_records( int $attachment_id ): void {
		$record = self::get( $attachment_id );
		foreach ( $record['files'] as $name => $file ) {
			if ( isset( $file['original'] ) ) {
				$record['files'][ $name ] = array(
					'size'     => $file['size'] ?? 0,
					'original' => $file['original'],
				);
			} else {
				unset( $record['files'][ $name ] );
			}
		}
		if ( $record['files'] ) {
			self::set( $attachment_id, $record );
			self::delete_progress( $attachment_id );
		} else {
			self::delete( $attachment_id );
		}
	}
}
