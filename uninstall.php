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
		$uploads = wp_get_upload_dir();
		$basedir = empty( $uploads['error'] ) ? $uploads['basedir'] : '';

		if ( $basedir && is_dir( $basedir ) ) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $basedir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);

			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}

				// Only the appended-extension sidecars this plugin wrote, never
				// a WebP or AVIF the user uploaded themselves.
				if ( preg_match( '/\.(jpe?g|png|gif)\.(webp|avif)$/i', $file->getFilename() ) ) {
					wp_delete_file( $file->getPathname() );
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
