<?php
/**
 * WebberZone Image Optimizer.
 *
 * Convert your media library to WebP and AVIF, and serve the best format each browser supports.
 *
 * @package   WebberZone\Image_Optimizer
 * @author    Ajay D'Souza
 * @license   GPL-2.0+
 * @link      https://webberzone.com
 * @copyright 2026 Ajay D'Souza
 *
 * @wordpress-plugin
 * Plugin Name: WebberZone Image Optimizer
 * Plugin URI: https://webberzone.github.io/webberzone-image-optimizer/
 * Description: Convert your media library to WebP and AVIF, and serve the best format each browser supports.
 * Version: 1.0.0
 * Author: WebberZone
 * Author URI: https://webberzone.com/
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: webberzone-image-optimizer
 * Domain Path: /languages
 * Requires at least: 6.6
 * Requires PHP: 7.4
 */

namespace WebberZone\Image_Optimizer;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'WZIO_VERSION', '1.0.0' );
define( 'WZIO_PLUGIN_FILE', __FILE__ );
define( 'WZIO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WZIO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WZIO_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Load the autoloader.
require_once WZIO_PLUGIN_DIR . 'includes/autoloader.php';

/**
 * Main plugin instance.
 *
 * @since 1.0.0
 * @return \WebberZone\Image_Optimizer\Main
 */
function wzio() {
	return Main::get_instance();
}

// Initialize the plugin.
wzio();

// Register the activation hook.
register_activation_hook( WZIO_PLUGIN_FILE, __NAMESPACE__ . '\Admin\Activator::activate' );

// Register the deactivation hook.
register_deactivation_hook( WZIO_PLUGIN_FILE, __NAMESPACE__ . '\Admin\Deactivator::deactivate' );
