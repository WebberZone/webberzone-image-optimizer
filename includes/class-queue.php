<?php
/**
 * Conversion queue.
 *
 * @package WebberZone\Image_Optimizer
 */

namespace WebberZone\Image_Optimizer;

if ( ! defined( 'WPINC' ) ) {
	exit;
}

/**
 * Manages one queue row per attachment and its files.
 *
 * @since 0.9.0
 */
class Queue {


	/**
	 * Waiting to be processed.
	 *
	 * @since 0.9.0
	 * @var   string
	 */
	const PENDING = 'pending';

	/**
	 * Claimed by a worker.
	 *
	 * @since 0.9.0
	 * @var   string
	 */
	const PROCESSING = 'processing';

	/**
	 * Finished successfully.
	 *
	 * @since 0.9.0
	 * @var   string
	 */
	const DONE = 'done';

	/**
	 * Finished with an error.
	 *
	 * @since 0.9.0
	 * @var   string
	 */
	const FAILED = 'failed';

	/**
	 * Nothing to do for this attachment.
	 *
	 * @since 0.9.0
	 * @var   string
	 */
	const SKIPPED = 'skipped';

	/**
	 * Maximum times a failing attachment is retried before it is left alone.
	 *
	 * @since 0.9.0
	 * @var   int
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * Add attachments, resetting existing rows only when forced.
	 *
	 * @since 0.9.0
	 *
	 * @param  array<int, int> $attachment_ids Attachment IDs.
	 * @param  bool            $force          Whether to requeue rows that already finished.
	 * @return int Number of rows written.
	 */
	public static function add( array $attachment_ids, bool $force = false ): int {
		global $wpdb;

		$attachment_ids = array_values( array_unique( array_filter( array_map( 'intval', $attachment_ids ) ) ) );

		if ( empty( $attachment_ids ) || ! Database::is_installed() ) {
			return 0;
		}

		$table = Database::get_table();
		$now   = current_time( 'mysql' );
		$rows  = array();

		foreach ( $attachment_ids as $id ) {
			$rows[] = $wpdb->prepare( '(%d, %s, 0, 0, %s, %s, %s)', $id, self::PENDING, '', $now, $now );
		}

		$values = implode( ',', $rows );

		$sql = "INSERT INTO `{$table}` (attachment_id, status, attempts, saved, error, created, updated) VALUES {$values} ";

		if ( $force ) {
			$sql .= 'ON DUPLICATE KEY UPDATE status = VALUES(status), attempts = 0, error = VALUES(error), updated = VALUES(updated)';
		} else {
			// Touching `id` is the standard no-op that keeps a duplicate from erroring.
			$sql .= 'ON DUPLICATE KEY UPDATE id = id';
		}

     // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$result = $wpdb->query( $sql );

		self::flush_counts();

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Claim the next batch of pending rows.
	 *
	 * @since 0.9.0
	 *
	 * @param  int $limit Maximum rows to claim.
	 * @return array<int, object> Claimed rows.
	 */
	public static function claim( int $limit ): array {
		global $wpdb;

		if ( $limit < 1 || ! Database::is_installed() ) {
			return array();
		}

		$table = Database::get_table();

     // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM `{$table}` WHERE status = %s ORDER BY id ASC LIMIT %d",
				self::PENDING,
				$limit
			)
		);

		if ( empty( $ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// The status guard lets only the first concurrent InnoDB update claim rows.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET status = %s, updated = %s WHERE id IN ({$placeholders}) AND status = %s",
				array_merge( array( self::PROCESSING, current_time( 'mysql' ) ), $ids, array( self::PENDING ) )
			)
		);

		if ( 0 === (int) $claimed ) {
			self::flush_counts();

			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE id IN ({$placeholders}) AND status = %s ORDER BY id ASC",
				array_merge( $ids, array( self::PROCESSING ) )
			)
		);
     // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		self::flush_counts();

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get the current status of an attachment's row.
	 *
	 * @since 0.9.0
	 *
	 * @param  int $attachment_id Attachment ID.
	 * @return string Status, or an empty string when there is no row.
	 */
	public static function get_status( int $attachment_id ): string {
		global $wpdb;

		if ( ! Database::is_installed() ) {
			return '';
		}

		$table = Database::get_table();

     // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM `{$table}` WHERE attachment_id = %d", $attachment_id ) );

		return null === $status ? '' : (string) $status;
	}

	/**
	 * Get the row ID for an attachment.
	 *
	 * @since 0.9.0
	 *
	 * @param  int $attachment_id Attachment ID.
	 * @return int Row ID, or 0 when there is no row.
	 */
	public static function get_id( int $attachment_id ): int {
		global $wpdb;

		if ( ! Database::is_installed() ) {
			return 0;
		}

		$table = Database::get_table();

     // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE attachment_id = %d", $attachment_id ) );
	}

	/**
	 * Claim the pending row for one specific attachment, bypassing queue order.
	 *
	 * @since 0.9.0
	 *
	 * @param  int $attachment_id Attachment ID.
	 * @return object|null Claimed row, or null when nothing was pending for it.
	 */
	public static function claim_attachment( int $attachment_id ) {
		global $wpdb;

		if ( ! Database::is_installed() ) {
			return null;
		}

		$table = Database::get_table();

     // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET status = %s, updated = %s WHERE attachment_id = %d AND status = %s",
				self::PROCESSING,
				current_time( 'mysql' ),
				$attachment_id,
				self::PENDING
			)
		);

		if ( 0 === (int) $claimed ) {
			self::flush_counts();

			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE attachment_id = %d AND status = %s",
				$attachment_id,
				self::PROCESSING
			)
		);
     // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		self::flush_counts();

		return $row;
	}

	/**
	 * Record the outcome of a claimed row.
	 *
	 * @since 0.9.0
	 *
	 * @param  int    $id     Queue row ID.
	 * @param  string $status New status.
	 * @param  int    $saved  Bytes saved.
	 * @param  string $error  Error message, if any.
	 * @param  int    $source Total source bytes considered.
	 * @return void
	 */
	public static function complete( int $id, string $status, int $saved = 0, string $error = '', int $source = 0 ): void {
		global $wpdb;

		if ( ! Database::is_installed() ) {
			return;
		}

		$table = Database::get_table();

		if ( self::FAILED === $status ) {
         // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$attempts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT attempts FROM `{$table}` WHERE id = %d", $id ) );

			// Put it back in line until the retry budget runs out.
			$status = ( $attempts + 1 ) < self::MAX_ATTEMPTS ? self::PENDING : self::FAILED;

         // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$table}` SET status = %s, attempts = attempts + 1, error = %s, updated = %s WHERE id = %d",
					$status,
					mb_substr( $error, 0, 250 ),
					current_time( 'mysql' ),
					$id
				)
			);
         // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

			self::flush_counts();

			return;
		}

     // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'status'       => $status,
				'source_bytes' => $source,
				'saved'        => $saved,
				'error'        => mb_substr( $error, 0, 250 ),
				'updated'      => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%d', '%d', '%s', '%s' ),
			array( '%d' )
		);

		self::flush_counts();
	}

	/**
	 * Requeue stale claims left by terminated workers.
	 *
	 * @since 0.9.0
	 *
	 * @param  int $older_than_seconds Age in seconds after which a claim is stale.
	 * @return int Rows released.
	 */
	public static function release_stale( int $older_than_seconds = 600 ): int {
		global $wpdb;

		if ( ! Database::is_installed() ) {
			return 0;
		}

		$table  = Database::get_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', (int) current_time( 'timestamp' ) - $older_than_seconds ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested

     // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$released = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET status = %s WHERE status = %s AND updated < %s",
				self::PENDING,
				self::PROCESSING,
				$cutoff
			)
		);
     // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( $released ) {
			self::flush_counts();
		}

		return false === $released ? 0 : (int) $released;
	}

	/**
	 * Count the rows in each status.
	 *
	 * @since 0.9.0
	 *
	 * @return array<string, int> Status to count, plus a `total` key.
	 */
	public static function get_counts(): array {
		global $wpdb;

		$empty = array(
			self::PENDING    => 0,
			self::PROCESSING => 0,
			self::DONE       => 0,
			self::FAILED     => 0,
			self::SKIPPED    => 0,
			'total'          => 0,
		);

		if ( ! Database::is_installed() ) {
			return $empty;
		}

		$cached = wp_cache_get( 'queue_counts', 'wzio' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$table = Database::get_table();

     // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS num, SUM(saved) AS saved, SUM(source_bytes) AS source_bytes FROM `{$table}` GROUP BY status" );

		$counts                 = $empty;
		$counts['bytes_saved']  = 0;
		$counts['bytes_source'] = 0;

		foreach ( (array) $rows as $row ) {
			$status = (string) $row->status;

			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ] = (int) $row->num;
			}

			$counts['total']        += (int) $row->num;
			$counts['bytes_saved']  += (int) $row->saved;
			$counts['bytes_source'] += (int) $row->source_bytes;
		}

		wp_cache_set( 'queue_counts', $counts, 'wzio', MINUTE_IN_SECONDS );

		return $counts;
	}

	/**
	 * Discard the cached status counts.
	 *
	 * @since 0.9.0
	 *
	 * @return void
	 */
	public static function flush_counts(): void {
		wp_cache_delete( 'queue_counts', 'wzio' );
	}

	/**
	 * Whether any row is waiting to be processed.
	 *
	 * @since 0.9.0
	 *
	 * @return bool True when work remains.
	 */
	public static function has_pending(): bool {
		$counts = self::get_counts();

		return $counts[ self::PENDING ] > 0 || $counts[ self::PROCESSING ] > 0;
	}

	/**
	 * Remove rows for a single attachment.
	 *
	 * @since 0.9.0
	 *
	 * @param  int $attachment_id Attachment ID.
	 * @return void
	 */
	public static function remove( int $attachment_id ): void {
		global $wpdb;

		if ( ! Database::is_installed() ) {
			return;
		}

     // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Database::get_table(), array( 'attachment_id' => $attachment_id ), array( '%d' ) );

		self::flush_counts();
	}

	/**
	 * Empty the queue.
	 *
	 * @since 0.9.0
	 *
	 * @return void
	 */
	public static function clear(): void {
		global $wpdb;

		if ( ! Database::is_installed() ) {
			return;
		}

		$table = Database::get_table();

     // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( "DELETE FROM `{$table}`" );

		self::flush_counts();
	}

	/**
	 * Remove pending claims while retaining completed totals.
	 *
	 * @since 0.9.0
	 *
	 * @return void
	 */
	public static function clear_pending(): void {
		global $wpdb;

		if ( ! Database::is_installed() ) {
			return;
		}

		$table = Database::get_table();

     // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $table, array( 'status' => self::PENDING ), array( '%s' ) );
     // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $table, array( 'status' => self::PROCESSING ), array( '%s' ) );

		self::flush_counts();
	}

	/**
	 * Get the most recent failures.
	 *
	 * @since 0.9.0
	 *
	 * @param  int $limit Maximum rows to return.
	 * @return array<int, object> Failed rows.
	 */
	public static function get_failures( int $limit = 20 ): array {
		global $wpdb;

		if ( ! Database::is_installed() ) {
			return array();
		}

		$table = Database::get_table();

     // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT attachment_id, error, attempts, updated FROM `{$table}` WHERE status = %s ORDER BY updated DESC LIMIT %d",
				self::FAILED,
				$limit
			)
		);
     // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return is_array( $rows ) ? $rows : array();
	}
}
