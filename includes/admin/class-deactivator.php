<?php
/**
 * Plugin deactivation.
 *
 * @package WebberZone\Image_Optimizer
 */

namespace WebberZone\Image_Optimizer\Admin;

use WebberZone\Image_Optimizer\Database;
use WebberZone\Image_Optimizer\Processor;

if ( ! defined( 'WPINC' ) ) {
	exit;
}

/**
 * Runs on deactivation.
 *
 * @since 1.0.0
 */
class Deactivator {

	/**
	 * Stop the background worker.
	 *
	 * Nothing is deleted here. Deactivation should be reversible, and the
	 * generated files simply stop being served the moment the plugin is off.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $network_wide Whether the plugin was deactivated for the whole network.
	 * @return void
	 */
	public static function deactivate( $network_wide = false ): void {
		if ( $network_wide && is_multisite() ) {
			foreach ( Database::get_site_ids() as $site_id ) {
				switch_to_blog( $site_id );
				Processor::unschedule();
				restore_current_blog();
			}

			return;
		}

		Processor::unschedule();
	}
}
