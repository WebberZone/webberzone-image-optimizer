<?php
/**
 * Plugin activation.
 *
 * @package WebberZone\Image_Optimizer
 */

namespace WebberZone\Image_Optimizer\Admin;

use WebberZone\Image_Optimizer\Capabilities;
use WebberZone\Image_Optimizer\Database;

if ( ! defined( 'WPINC' ) ) {
	exit;
}

/**
 * Runs on activation.
 *
 * @since 0.9.0
 */
class Activator {

	/**
	 * Prepare the plugin for use.
	 *
	 * @since 0.9.0
	 *
	 * @param bool $network_wide Whether the plugin was activated for the whole network.
	 * @return void
	 */
	public static function activate( $network_wide = false ): void {
		Database::install_all( (bool) $network_wide );

		if ( $network_wide && is_multisite() ) {
			foreach ( Database::get_site_ids() as $site_id ) {
				switch_to_blog( $site_id );
				self::seed_settings();
				restore_current_blog();
			}
		} else {
			self::seed_settings();
		}

		// Probe now so settings can report supported formats.
		Capabilities::get( true );
	}

	/**
	 * Write the default settings, without disturbing an existing configuration.
	 *
	 * @since 0.9.0
	 *
	 * @return void
	 */
	private static function seed_settings(): void {
		$existing = get_option( 'wzio_settings' );

		if ( is_array( $existing ) && ! empty( $existing ) ) {
			return;
		}

		$defaults = Settings::settings_defaults();

		// Default only to formats this server can encode.
		$supported = Capabilities::get_supported_formats();

		if ( ! empty( $supported ) ) {
			$defaults['formats'] = in_array( 'webp', $supported, true ) ? 'webp' : (string) reset( $supported );
		} else {
			$defaults['formats'] = '';
		}

		update_option( 'wzio_settings', $defaults );
	}
}
