<?php
/**
 * Media library scanning.
 *
 * @package WebberZone\Image_Optimizer
 */

namespace WebberZone\Image_Optimizer;

use WebberZone\Image_Optimizer\Util\Helpers;

if ( ! defined( 'WPINC' ) ) {
	exit;
}

/**
 * Finds convertible attachments via direct queries to avoid loading post objects.
 *
 * @since 1.0.0
 */
class Scanner {

	/**
	 * How many IDs to insert into the queue per statement.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const CHUNK = 500;

	/**
	 * Wall-clock budget for one scanning pass.
	 *
	 * @since 1.0.2
	 * @var int
	 */
	const MAX_SCAN_SECONDS = 10;

	/**
	 * How long the candidate count is trusted.
	 *
	 * @since 1.0.2
	 * @var int
	 */
	const CANDIDATE_TTL = HOUR_IN_SECONDS;

	/**
	 * How long the optimized count is trusted while a run is in progress.
	 *
	 * @since 1.0.2
	 * @var int
	 */
	const OPTIMIZED_TTL = MINUTE_IN_SECONDS;

	/**
	 * Count the attachments the plugin could convert.
	 *
	 * @since 1.0.0
	 *
	 * @return int Attachment count.
	 */
	public static function count_candidates(): int {
		global $wpdb;

		$cached = get_transient( 'wzio_count_candidates' );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$mimes        = Helpers::SOURCE_MIME_TYPES;
		$placeholders = implode( ',', array_fill( 0, count( $mimes ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ({$placeholders})",
				$mimes
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		set_transient( 'wzio_count_candidates', $count, self::CANDIDATE_TTL );

		return $count;
	}

	/**
	 * Count the attachments that already carry a conversion record.
	 *
	 * @since 1.0.0
	 *
	 * @return int Attachment count.
	 */
	public static function count_optimized(): int {
		global $wpdb;

		$cached = get_transient( 'wzio_count_optimized' );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				Attachment_Meta::META_KEY
			)
		);

		// Not invalidated per conversion: that would uncache it exactly during a bulk run.
		set_transient( 'wzio_count_optimized', $count, self::OPTIMIZED_TTL );

		return $count;
	}

	/**
	 * Sum the source bytes and bytes saved across every conversion record.
	 *
	 * @since 1.1.2
	 *
	 * @return array{source: int, saved: int} Byte totals.
	 */
	public static function get_byte_totals(): array {
		global $wpdb;

		$cached = get_transient( 'wzio_byte_totals' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$complete = self::backfill_byte_totals();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT SUM( CASE WHEN meta_key = %s THEN CAST( meta_value AS UNSIGNED ) ELSE 0 END ) AS source,
					SUM( CASE WHEN meta_key = %s THEN CAST( meta_value AS UNSIGNED ) ELSE 0 END ) AS saved
				FROM {$wpdb->postmeta} WHERE meta_key IN ( %s, %s )",
				Attachment_Meta::SOURCE_META_KEY,
				Attachment_Meta::SAVED_META_KEY,
				Attachment_Meta::SOURCE_META_KEY,
				Attachment_Meta::SAVED_META_KEY
			)
		);

		$totals = array(
			'source' => (int) ( $row->source ?? 0 ),
			'saved'  => (int) ( $row->saved ?? 0 ),
		);

		if ( $complete ) {
			set_transient( 'wzio_byte_totals', $totals, self::OPTIMIZED_TTL );
		}

		return $totals;
	}

	/**
	 * Write byte totals for records stored before they were tracked.
	 *
	 * @since 1.1.2
	 *
	 * @param int $time_limit Seconds to spend before deferring the rest to a later call.
	 * @return bool True when no record is left without totals.
	 */
	public static function backfill_byte_totals( int $time_limit = 10 ): bool {
		global $wpdb;

		$started = microtime( true );

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT d.post_id FROM {$wpdb->postmeta} d
					LEFT JOIN {$wpdb->postmeta} s ON s.post_id = d.post_id AND s.meta_key = %s
					WHERE d.meta_key = %s AND s.meta_id IS NULL
					LIMIT 500",
					Attachment_Meta::SAVED_META_KEY,
					Attachment_Meta::META_KEY
				)
			);

			foreach ( $ids as $id ) {
				Attachment_Meta::set_byte_totals( (int) $id, Attachment_Meta::get( (int) $id ) );
			}

			if ( count( $ids ) < 500 ) {
				return true;
			}
		} while ( microtime( true ) - $started < $time_limit );

		return false;
	}

	/**
	 * Discard the cached library-wide counts.
	 *
	 * @since 1.0.2
	 *
	 * @return void
	 */
	public static function flush_counts(): void {
		delete_transient( 'wzio_count_candidates' );
		delete_transient( 'wzio_count_optimized' );
		delete_transient( 'wzio_byte_totals' );
	}

	/**
	 * Queue PNG attachments holding a skipped copy the lossy fallback may now rescue.
	 *
	 * Their queue rows are already done, so a normal scan would never revisit them.
	 *
	 * @since 1.1.1
	 *
	 * @return int Number of attachments queued.
	 */
	public static function requeue_png_skips(): int {
		global $wpdb;

		if ( ! \wzio_get_option( 'lossless_png', true ) || (int) \wzio_get_option( 'png_lossy_quality', 95 ) < 1 ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT m.post_id FROM {$wpdb->postmeta} m
				 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
				 WHERE m.meta_key = %s
				 AND p.post_type = 'attachment'
				 AND p.post_mime_type = 'image/png'
				 AND m.meta_value LIKE %s",
				Attachment_Meta::META_KEY,
				'%' . $wpdb->esc_like( 's:6:"larger"' ) . '%'
			)
		);

		$ids = array_map( 'intval', (array) $ids );

		if ( empty( $ids ) ) {
			return 0;
		}

		Queue::add( $ids, true );
		Processor::maybe_schedule();
		self::flush_counts();

		return count( $ids );
	}

	/**
	 * Queue attachments holding copies skipped for want of an encoder that now exists.
	 *
	 * @since 1.1.2
	 *
	 * @return int Number of attachments queued.
	 */
	public static function requeue_unsupported_skips(): int {
		global $wpdb;

		$available = array_filter(
			(array) Converter::get_args()['formats'],
			static function ( $format ): bool {
				return null !== Capabilities::get_driver( (string) $format );
			}
		);

		if ( empty( $available ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
				Attachment_Meta::META_KEY,
				'%' . $wpdb->esc_like( 's:11:"unsupported"' ) . '%'
			)
		);

		$ids = array_map( 'intval', (array) $ids );

		if ( empty( $ids ) ) {
			return 0;
		}

		Queue::add( $ids, true );
		Processor::maybe_schedule();
		self::flush_counts();

		return count( $ids );
	}

	/**
	 * Clear skipped and failed results and queue those attachments for another attempt.
	 *
	 * @since 1.1.2
	 *
	 * @return int Number of attachments queued.
	 */
	public static function requeue_retryable(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND ( meta_value LIKE %s OR meta_value LIKE %s )",
				Attachment_Meta::META_KEY,
				'%' . $wpdb->esc_like( 's:4:"skip"' ) . '%',
				'%' . $wpdb->esc_like( 's:5:"error"' ) . '%'
			)
		);

		$ids = array_values( array_unique( array_merge( array_map( 'intval', (array) $ids ), Queue::get_failed_ids() ) ) );

		if ( empty( $ids ) ) {
			return 0;
		}

		foreach ( $ids as $attachment_id ) {
			Attachment_Meta::clear_retryable( $attachment_id );
		}

		Queue::add( $ids, true );
		Processor::maybe_schedule();
		self::flush_counts();

		return count( $ids );
	}

	/**
	 * Get a page of attachment IDs that could be converted.
	 *
	 * @since 1.0.0
	 *
	 * @param int  $limit          Maximum IDs to return.
	 * @param int  $after_id       Only return IDs greater than this.
	 * @param bool $only_unhandled Whether to skip attachments that already have a record.
	 * @return array<int, int> Attachment IDs, ascending.
	 */
	public static function get_candidate_ids( int $limit = 500, int $after_id = 0, bool $only_unhandled = true ): array {
		global $wpdb;

		$mimes        = Helpers::SOURCE_MIME_TYPES;
		$placeholders = implode( ',', array_fill( 0, count( $mimes ), '%s' ) );

		$join  = '';
		$where = '';
		$args  = $mimes;

		if ( $only_unhandled ) {
			$join  = "LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s";
			$where = 'AND m.post_id IS NULL';
			$args  = array_merge( array( Attachment_Meta::META_KEY ), $args );
		}

		$args[] = $after_id;
		$args[] = $limit;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 {$join}
				 WHERE p.post_type = 'attachment'
				 AND p.post_mime_type IN ({$placeholders})
				 {$where}
				 AND p.ID > %d
				 ORDER BY p.ID ASC
				 LIMIT %d",
				$args
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Fill the queue with every attachment that still needs work.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $force Whether to include attachments that already have a record.
	 * @return int Number of attachments queued.
	 */
	public static function enqueue_all( bool $force = false ): int {
		$pass = self::enqueue_batch( 0, $force, INF );

		return $pass['queued'];
	}

	/**
	 * Queue one time-bounded page of attachments that still need work.
	 *
	 * @since 1.0.2
	 *
	 * @param int        $after_id Cursor; only attachments above this ID are considered.
	 * @param bool       $force    Whether to include attachments that already have a record.
	 * @param float|null $deadline Wall-clock deadline, or null for the default budget.
	 * @return array{queued: int, after_id: int, done: bool} Pass result.
	 */
	public static function enqueue_batch( int $after_id = 0, bool $force = false, ?float $deadline = null ): array {
		$deadline = null === $deadline ? microtime( true ) + self::MAX_SCAN_SECONDS : $deadline;
		$queued   = 0;
		$found    = 0;

		do {
			$ids   = self::get_candidate_ids( self::CHUNK, $after_id, ! $force );
			$found = count( $ids );

			if ( 0 === $found ) {
				break;
			}

			Queue::add( $ids, $force, $force );

			$queued  += $found;
			$after_id = (int) end( $ids );
		} while ( self::CHUNK === $found && microtime( true ) < $deadline );

		return array(
			'queued'   => $queued,
			'after_id' => $after_id,
			'done'     => self::CHUNK !== $found,
		);
	}
}
