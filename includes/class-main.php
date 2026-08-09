<?php
/**
 * Main plugin class.
 *
 * @package WebberZone\Image_Optimizer
 * @since 1.0.0
 */

namespace WebberZone\Image_Optimizer;

use WebberZone\Image_Optimizer\Util\Hook_Registry;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bootstraps the plugin.
 *
 * @since 1.0.0
 */
class Main {

	/**
	 * Plugin instance.
	 *
	 * @since 1.0.0
	 * @var Main|null
	 */
	private static $instance = null;

	/**
	 * Admin instance.
	 *
	 * @since 1.0.0
	 * @var Admin\Admin|null
	 */
	public $admin = null;

	/**
	 * Attachment hooks instance.
	 *
	 * @since 1.0.0
	 * @var Attachment_Hooks|null
	 */
	public $attachment_hooks = null;

	/**
	 * Queue processor instance.
	 *
	 * @since 1.0.0
	 * @var Processor|null
	 */
	public $processor = null;

	/**
	 * Frontend rewriter instance.
	 *
	 * @since 1.0.0
	 * @var Frontend\Rewriter|null
	 */
	public $rewriter = null;

	/**
	 * Get the plugin instance.
	 *
	 * @since 1.0.0
	 *
	 * @return Main Instance.
	 */
	public static function get_instance(): Main {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		require_once WZIO_PLUGIN_DIR . 'includes/options-api.php';

		Hook_Registry::add_action( 'plugins_loaded', array( $this, 'init' ) );
		Hook_Registry::add_action( 'init', array( $this, 'init_admin' ) );
		Hook_Registry::add_action( 'admin_init', array( __NAMESPACE__ . '\Database', 'maybe_upgrade' ) );
		Hook_Registry::add_action( 'wp_initialize_site', array( __NAMESPACE__ . '\Database', 'on_new_site' ), 99 );
	}

	/**
	 * Initialise the plugin components.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function init(): void {
		$this->attachment_hooks = new Attachment_Hooks();
		$this->processor        = new Processor();
		$this->rewriter         = new Frontend\Rewriter();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'wzio', CLI\CLI::class );
		}
	}

	/**
	 * Initialise the admin components.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function init_admin(): void {
		if ( is_admin() ) {
			$this->admin = new Admin\Admin();
		}
	}
}
