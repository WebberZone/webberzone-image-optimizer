<?php
/**
 * Uninstall WebberZone Image Optimizer.
 *
 * Uninstalling never restores or rewrites images: compressed originals stay as they are.
 * Generated WebP and AVIF files, the plugin's data and the original backups are each
 * removed only when the administrator asked for that in the settings, because
 * regenerating a large library is expensive, a reinstall is common, and a backup is the
 * only pristine copy of an image.
 *
 * @package WebberZone\Image_Optimizer
 */

// If uninstall is not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove the original backup folder of the current site.
 *
 * Symbolic links are removed, never followed, and nothing outside the backup folder is touched.
 *
 * @since 1.2.0
 *
 * @return void
 */
function wzio_uninstall_purge_backups() {
	$uploads = wp_upload_dir( null, false );
	$base    = realpath( $uploads['basedir'] );
	$root    = false !== $base ? $base . '/wzio-originals' : '';

	if ( '' === $root || ! is_dir( $root ) || is_link( $root ) || realpath( $root ) !== $root ) {
		return;
	}

	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $items as $item ) {
		if ( $item->isLink() || $item->isFile() ) {
			wp_delete_file( $item->getPathname() );
		} elseif ( $item->isDir() ) {
			rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
	}

	rmdir( $root ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

/**
 * Remove the plugin's data from the current site.
 *
 * @since 1.0.0
 *
 * @return void
 */
function wzio_uninstall_site() {
	global $wpdb;

	$settings = get_option( 'wzio_settings' );
	$settings = is_array( $settings ) ? $settings : array();

	$delete_files   = ! empty( $settings['delete_files_on_uninstall'] );
	$delete_data    = ! empty( $settings['delete_data_on_uninstall'] );
	$delete_backups = ! empty( $settings['delete_backups_on_uninstall'] );

	if ( $delete_files ) {
		$sidecar_naming = ( $settings['sidecar_naming'] ?? 'append' ) === 'replace' ? 'replace' : 'append';

		// A filesystem-wide filename scan can remove a file created by another
		// plugin or uploaded by the administrator. Restrict removal to successful
		// conversion records created by this plugin instead.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$records = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s)",
				'_wzio_data',
				'_wzio_progress'
			)
		);

		foreach ( (array) $records as $row ) {
			$record = maybe_unserialize( $row->meta_value );
			$main   = get_attached_file( (int) $row->post_id );

			if ( ! is_array( $record ) || ! is_string( $main ) || '' === $main || empty( $record['files'] ) ) {
				continue;
			}

			foreach ( $record['files'] as $basename => $file_record ) {
				if ( ! is_array( $file_record ) ) {
					continue;
				}

				$source = dirname( $main ) . '/' . wp_basename( (string) $basename );

				foreach ( array( 'webp', 'avif' ) as $format ) {
					if ( empty( $file_record[ $format ]['bytes'] ) ) {
						continue;
					}

					$sidecar = 'replace' === $sidecar_naming
						? preg_replace( '/\.[^.\/\\\\]+$/', '', $source ) . '.' . $format
						: $source . '.' . $format;

					wp_delete_file( $sidecar );
				}
			}
		}
	}

	// The backups and their records go together: records without backups make every later compression fail.
	if ( $delete_backups ) {
		wzio_uninstall_purge_backups();

		foreach ( array( '_wzio_originals', '_wzio_originals_restored' ) as $meta_key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $meta_key ) );
		}
	}

	if ( ! $delete_data ) {
		return;
	}

	delete_option( 'wzio_settings' );
	delete_option( 'wzio_capabilities' );
	delete_option( 'wzio_original_capabilities' );
	delete_transient( 'wzio_original_totals' );
	delete_option( 'wzio_db_version' );
	delete_option( 'wzio_cron_last_run' );
	delete_option( 'wzio_cron_observed' );

	delete_transient( 'wzio_count_candidates' );
	delete_transient( 'wzio_count_optimized' );
	delete_transient( 'wzio_byte_totals' );

	// The _wzio_originals records are kept unless the backups were deleted, so a reinstall can still restore them.
	foreach ( array( '_wzio_data', '_wzio_progress', '_wzio_source_bytes', '_wzio_saved_bytes', '_wzio_original_saved', '_wzio_original_bytes', '_wzio_original_resized', '_wzio_original_compressed' ) as $meta_key ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $meta_key ) );
	}

	$table = $wpdb->prefix . 'wzio_queue';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );

	$timestamp = wp_next_scheduled( 'wzio_process_queue' );

	while ( false !== $timestamp ) {
		wp_unschedule_event( $timestamp, 'wzio_process_queue' );
		$timestamp = wp_next_scheduled( 'wzio_process_queue' );
	}
}

if ( is_multisite() ) {
	$wzio_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( (array) $wzio_sites as $wzio_site_id ) {
		switch_to_blog( (int) $wzio_site_id );
		wzio_uninstall_site();
		restore_current_blog();
	}
} else {
	wzio_uninstall_site();
}
