<?php
/**
 * Queue table schema management.
 *
 * @package WebberZone\Image_Optimizer
 */

namespace WebberZone\Image_Optimizer;

if ( ! defined( 'WPINC' ) ) {
	exit;
}

/**
 * Manages the per-site conversion queue table.
 *
 * @since 0.9.0
 */
class Database {


	/**
	 * Option holding the installed schema version.
	 *
	 * @since 0.9.0
	 * @var   string
	 */
	const VERSION_OPTION = 'wzio_db_version';

	/**
	 * Current schema version.
	 *
	 * @since 0.9.0
	 * @var   string
	 */
	const VERSION = '1.1';

	/**
	 * Get the queue table name for the current site.
	 *
	 * @since 0.9.0
	 *
	 * @return string Table name.
	 */
	public static function get_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'wzio_queue';
	}

	/**
	 * The CREATE TABLE statement for the queue table.
	 *
	 * @since 0.9.0
	 *
	 * @return string SQL.
	 */
	public static function get_schema(): string {
		global $wpdb;

		$table           = self::get_table();
		$charset_collate = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) UNSIGNED NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			attempts tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
			source_bytes bigint(20) NOT NULL DEFAULT 0,
			saved bigint(20) NOT NULL DEFAULT 0,
			error varchar(255) NOT NULL DEFAULT '',
			created datetime NOT NULL,
			updated datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY attachment_id (attachment_id),
			KEY status_id (status, id)
		) {$charset_collate};";
	}

	/**
	 * Create or update the queue table on the current site.
	 *
	 * @since 0.9.0
	 *
	 * @return void
	 */
	public static function install(): void {
		include_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( self::get_schema() );

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Create the table on every site of the network, or just the current site.
	 *
	 * `register_activation_hook` fires once for a network activation, so without
	 * this loop every site except the one activating would be left without a
	 * table.
	 *
	 * @since 0.9.0
	 *
	 * @param  bool $network_wide Whether the plugin was network activated.
	 * @return void
	 */
	public static function install_all( bool $network_wide = false ): void {
		if ( ! $network_wide || ! is_multisite() ) {
			self::install();
			return;
		}

		foreach ( self::get_site_ids() as $site_id ) {
			switch_to_blog( $site_id );
			self::install();
			restore_current_blog();
		}
	}

	/**
	 * Get every site ID in the network.
	 *
	 * @since 0.9.0
	 *
	 * @return array<int, int> Site IDs.
	 */
	public static function get_site_ids(): array {
		if ( ! is_multisite() ) {
			return array( get_current_blog_id() );
		}

		$ids = get_sites(
			array(
				'fields'                 => 'ids',
				'number'                 => 0,
				'update_site_meta_cache' => false,
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Create the table when a new site is added to the network.
	 *
	 * @since 0.9.0
	 *
	 * @param  \WP_Site $site New site object.
	 * @return void
	 */
	public static function on_new_site( $site ): void {
		if ( ! is_plugin_active_for_network( WZIO_PLUGIN_BASENAME ) ) {
			return;
		}

		switch_to_blog( (int) $site->blog_id );
		self::install();
		restore_current_blog();
	}

	/**
	 * Create the table if the stored schema version is missing or stale.
	 *
	 * @since 0.9.0
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::VERSION_OPTION ) === self::VERSION && self::is_installed() ) {
			return;
		}

		self::install();
	}

	/**
	 * Whether the queue table exists on the current site.
	 *
	 * @since 0.9.0
	 *
	 * @return bool True when installed.
	 */
	public static function is_installed(): bool {
		global $wpdb;

		static $cache = array();

		$table = self::get_table();

		if ( isset( $cache[ $table ] ) ) {
			return $cache[ $table ];
		}

     // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		$cache[ $table ] = ( $found === $table );

		return $cache[ $table ];
	}

	/**
	 * Drop the queue table on the current site.
	 *
	 * @since 0.9.0
	 *
	 * @return void
	 */
	public static function drop_table(): void {
		global $wpdb;

		$table = self::get_table();

     // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );

		delete_option( self::VERSION_OPTION );
	}
}
