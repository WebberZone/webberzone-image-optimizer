<?php
/**
 * Uninstall WebberZone Image Optimizer.
 *
 * Nothing here touches an original image. The generated WebP and AVIF files are
 * removed only when the administrator asked for that in the settings, because
 * regenerating a large library is expensive and a reinstall is common.
 *
 * @package WebberZone\Image_Optimizer
 */

// If uninstall is not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
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

	$delete_files = ! empty( $settings['delete_files_on_uninstall'] );
	$delete_data  = ! empty( $settings['delete_data_on_uninstall'] );

	if ( $delete_files ) {
		$sidecar_naming = ( $settings['sidecar_naming'] ?? 'append' ) === 'replace' ? 'replace' : 'append';

		// A filesystem-wide filename scan can remove a file created by another
		// plugin or uploaded by the administrator. Restrict removal to successful
		// conversion records created by this plugin instead.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$records = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
				'_wzio_data'
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

	if ( ! $delete_data ) {
		return;
	}

	delete_option( 'wzio_settings' );
	delete_option( 'wzio_capabilities' );
	delete_option( 'wzio_db_version' );

	delete_transient( 'wzio_count_candidates' );
	delete_transient( 'wzio_count_optimized' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_wzio_data' ) );

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
