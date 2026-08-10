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
 * @since 0.9.0
 */
class Scanner {

	/**
	 * How many IDs to insert into the queue per statement.
	 *
	 * @since 0.9.0
	 * @var int
	 */
	const CHUNK = 500;

	/**
	 * Count the attachments the plugin could convert.
	 *
	 * @since 0.9.0
	 *
	 * @return int Attachment count.
	 */
	public static function count_candidates(): int {
		global $wpdb;

		$mimes        = Helpers::SOURCE_MIME_TYPES;
		$placeholders = implode( ',', array_fill( 0, count( $mimes ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ({$placeholders})",
				$mimes
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Count the attachments that already carry a conversion record.
	 *
	 * @since 0.9.0
	 *
	 * @return int Attachment count.
	 */
	public static function count_optimized(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				Attachment_Meta::META_KEY
			)
		);
	}

	/**
	 * Get a page of attachment IDs that could be converted.
	 *
	 * @since 0.9.0
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
	 * @since 0.9.0
	 *
	 * @param bool $force Whether to include attachments that already have a record.
	 * @return int Number of attachments queued.
	 */
	public static function enqueue_all( bool $force = false ): int {
		$queued   = 0;
		$after_id = 0;
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
		} while ( self::CHUNK === $found );

		return $queued;
	}
}
