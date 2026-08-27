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
	 * Discard the cached library-wide counts.
	 *
	 * @since 1.0.2
	 *
	 * @return void
	 */
	public static function flush_counts(): void {
		delete_transient( 'wzio_count_candidates' );
		delete_transient( 'wzio_count_optimized' );
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

			Queue::add( $ids, $force );

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
