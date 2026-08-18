<?php
/**
 * Register Settings.
 *
 * @since 0.9.0
 *
 * @package WebberZone\Image_Optimizer
 */

namespace WebberZone\Image_Optimizer\Admin;

use WebberZone\Image_Optimizer\Capabilities;
use WebberZone\Image_Optimizer\Frontend\Server_Rules;
use WebberZone\Image_Optimizer\Util\Hook_Registry;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Registers plugin settings.
 *
 * @since 0.9.0
 */
class Settings {


	/**
	 * Settings API.
	 *
	 * @since 0.9.0
	 *
	 * @var object Settings API.
	 */
	public $settings_api;

	/**
	 * Prefix which is used for creating the unique filters and actions.
	 *
	 * Initialised at declaration rather than only in the constructor: the static
	 * methods on this class are reachable on the frontend where the Settings object
	 * is never instantiated, and a null prefix there fires `_settings_defaults`
	 * instead of `wzio_settings_defaults`.
	 *
	 * @since 0.9.0
	 *
	 * @var string Prefix.
	 */
	public static $prefix = 'wzio';

	/**
	 * Settings Key.
	 *
	 * @since 0.9.0
	 *
	 * @var string Settings Key.
	 */
	public $settings_key;

	/**
	 * The slug name to refer to this menu by.
	 *
	 * @since 0.9.0
	 *
	 * @var string Menu slug.
	 */
	public $menu_slug;

	/**
	 * Main constructor class.
	 *
	 * @since 0.9.0
	 */
	public function __construct() {
		$this->settings_key = 'wzio_settings';
		self::$prefix       = 'wzio';
		$this->menu_slug    = 'wzio-settings';

		Hook_Registry::add_action( 'admin_menu', array( $this, 'initialise_settings' ) );
		Hook_Registry::add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 11, 2 );
		Hook_Registry::add_filter( 'plugin_action_links_' . plugin_basename( WZIO_PLUGIN_FILE ), array( $this, 'plugin_actions_links' ) );

		Hook_Registry::add_filter( self::$prefix . '_settings_sanitize', array( $this, 'change_settings_on_save' ), 99 );

		Hook_Registry::add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_htaccess_assets' ) );
		Hook_Registry::add_action( 'wp_ajax_wzio_install_htaccess', array( $this, 'ajax_install_htaccess' ) );
		Hook_Registry::add_action( 'wp_ajax_wzio_remove_htaccess', array( $this, 'ajax_remove_htaccess' ) );
	}

	/**
	 * Enqueue the .htaccess install/remove button script on the settings screen.
	 *
	 * @since 0.9.0
	 *
	 * @param  string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue_htaccess_assets( $hook_suffix ): void {
		if ( 'media_page_' . $this->menu_slug !== $hook_suffix ) {
			return;
		}

		$minimize = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

		wp_enqueue_script(
			'wzio-htaccess',
			WZIO_PLUGIN_URL . 'includes/admin/js/htaccess' . $minimize . '.js',
			array(),
			WZIO_VERSION,
			true
		);

		wp_localize_script(
			'wzio-htaccess',
			'wzioHtaccess',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'strings' => array(
					'installed' => esc_html__( 'Currently installed.', 'webberzone-image-optimizer' ),
					'working'   => esc_html__( 'Working…', 'webberzone-image-optimizer' ),
					'error'     => esc_html__( 'Something went wrong. Add the block by hand instead.', 'webberzone-image-optimizer' ),
				),
			)
		);
	}

	/**
	 * Write the Apache rules into `.htaccess`.
	 *
	 * @since 0.9.0
	 *
	 * @return void
	 */
	public function ajax_install_htaccess(): void {
		check_ajax_referer( 'wzio_htaccess', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'webberzone-image-optimizer' ) ), 403 );
		}

		if ( ! Server_Rules::install_apache_rules() ) {
			wp_send_json_error( array( 'message' => __( 'Could not write to .htaccess. Add the block above by hand instead.', 'webberzone-image-optimizer' ) ) );
		}

		wp_send_json_success();
	}

	/**
	 * Remove this plugin's block from `.htaccess`.
	 *
	 * @since 0.9.0
	 *
	 * @return void
	 */
	public function ajax_remove_htaccess(): void {
		check_ajax_referer( 'wzio_htaccess', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'webberzone-image-optimizer' ) ), 403 );
		}

		if ( ! Server_Rules::remove_apache_rules() ) {
			wp_send_json_error( array( 'message' => __( 'Could not update .htaccess. Remove the block by hand instead.', 'webberzone-image-optimizer' ) ) );
		}

		wp_send_json_success();
	}

	/**
	 * Initialise the settings API.
	 *
	 * @since 0.9.0
	 *
	 * @return void
	 */
	public function initialise_settings() {
		$props = array(
			'default_tab'       => 'general',
			'help_sidebar'      => $this->get_help_sidebar(),
			'help_tabs'         => $this->get_help_tabs(),
			'admin_footer_text' => $this->get_admin_footer_text(),
			'menus'             => $this->get_menus(),
		);

		$args = array(
			'props'               => $props,
			'translation_strings' => $this->get_translation_strings(),
			'settings_sections'   => $this->get_settings_sections(),
			'registered_settings' => $this->get_registered_settings(),
			'upgraded_settings'   => array(),
		);

		$this->settings_api = new Settings\Settings_API( $this->settings_key, self::$prefix, $args );
	}

	/**
	 * Get settings defaults.
	 *
	 * @since 0.9.0
	 *
	 * @return array Default settings.
	 */
	public static function settings_defaults() {
		$defaults = array();

		$settings      = self::get_registered_settings();
		$default_types = array(
			'color',
			'css',
			'csv',
			'file',
			'html',
			'multicheck',
			'number',
			'numbercsv',
			'password',
			'postids',
			'posttypes',
			'radio',
			'radiodesc',
			'repeater',
			'select',
			'sensitive',
			'taxonomies',
			'text',
			'textarea',
			'thumbsizes',
			'url',
			'wysiwyg',
		);

		foreach ( $settings as $section_settings ) {
			foreach ( $section_settings as $setting ) {
				if ( ! isset( $setting['id'] ) ) {
					continue;
				}

				$setting_id    = $setting['id'];
				$setting_type  = $setting['type'] ?? '';
				$default_value = '';

				if ( 'checkbox' === $setting_type ) {
					$default_value = isset( $setting['default'] ) ? (int) (bool) $setting['default'] : 0;
				} elseif ( isset( $setting['default'] ) && in_array( $setting_type, $default_types, true ) ) {
					$default_value = $setting['default'];
				}

				$defaults[ $setting_id ] = $default_value;
			}
		}

		return apply_filters( self::$prefix . '_settings_defaults', $defaults ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
	}

	/**
	 * Array containing the translation strings.
	 *
	 * @since 0.9.0
	 *
	 * @return array Translation strings.
	 */
	public function get_translation_strings() {
		$strings = array(
			'page_title'           => esc_html__( 'WebberZone Image Optimizer Settings', 'webberzone-image-optimizer' ),
			'menu_title'           => esc_html__( 'Settings', 'webberzone-image-optimizer' ),
			'page_header'          => esc_html__( 'WebberZone Image Optimizer Settings', 'webberzone-image-optimizer' ),
			'reset_message'        => esc_html__( 'Settings have been reset to their default values. Reload this page to view the updated settings.', 'webberzone-image-optimizer' ),
			'success_message'      => esc_html__( 'Settings updated.', 'webberzone-image-optimizer' ),
			'save_changes'         => esc_html__( 'Save Changes', 'webberzone-image-optimizer' ),
			'reset_settings'       => esc_html__( 'Reset all settings', 'webberzone-image-optimizer' ),
			'reset_button_confirm' => esc_html__( 'Do you really want to reset all these settings to their default values?', 'webberzone-image-optimizer' ),
			'modified_field'       => esc_html__( 'Modified from default setting', 'webberzone-image-optimizer' ),
			'modified_legend'      => esc_html__( 'Setting modified from its default value', 'webberzone-image-optimizer' ),
			'default_label'        => esc_html__( 'Default', 'webberzone-image-optimizer' ),
			'default_none'         => esc_html__( 'None', 'webberzone-image-optimizer' ),
			'button_label'         => esc_html__( 'Choose File', 'webberzone-image-optimizer' ),
			'previous_saved'       => esc_html__( 'Previously saved', 'webberzone-image-optimizer' ),
		);

		return apply_filters( self::$prefix . '_translation_strings', $strings ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
	}

	/**
	 * Get the admin menus.
	 *
	 * @since 0.9.0
	 *
	 * @return array Admin menus.
	 */
	public function get_menus() {
		$menus = array();

		$menus[] = array(
			'settings_page' => true,
			'type'          => 'submenu',
			'parent_slug'   => 'upload.php',
			'page_title'    => esc_html__( 'WebberZone Image Optimizer Settings', 'webberzone-image-optimizer' ),
			'menu_title'    => esc_html__( 'Image Optimizer', 'webberzone-image-optimizer' ),
			'menu_slug'     => $this->menu_slug,
		);

		return apply_filters( self::$prefix . '_settings_menus', $menus ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
	}

	/**
	 * Raw default values for every setting, keyed by option ID.
	 *
	 * Single source of truth for field defaults. Deliberately contains no
	 * translation calls so it is safe to invoke before `init` without triggering a
	 * "translation loading triggered too early" notice. Field definition methods
	 * below reference this array instead of duplicating literals.
	 *
	 * Values are pre-normalised: checkbox defaults use 1/0 rather than true/false
	 * so that they match what `settings_defaults()` produces after its
	 * `(int) (bool)` cast. This array is deliberately unfiltered — the
	 * `wzio_settings_defaults` filter is applied by the consumers
	 * (`settings_defaults()` and `Options_API::get_default_option()`) so that it
	 * runs exactly once on each path.
	 *
	 * @since 0.10.0
	 *
	 * @return array Raw default values keyed by option ID.
	 */
	public static function get_defaults() {
		return array(
			// General.
			'formats'                   => 'webp',
			'convert_on_upload'         => 1,
			'convert_sizes'             => '',
			'min_saving'                => 5,
			'sidecar_naming'            => 'append',

			// Quality.
			'quality_webp'              => 82,
			'quality_avif'              => 50,
			'effort_webp'               => 6,
			'effort_avif'               => 4,
			'strip_metadata'            => 1,
			'lossless_png'              => 1,

			// Delivery.
			'enable_delivery'           => 1,
			'rewrite_content'           => 1,
			'rewrite_template'          => 1,
			'rewrite_buffer'            => 0,

			// Advanced.
			'batch_size'                => 10,
			'background_queue'          => 1,
			'lazy_convert'              => 1,
			'exclude_paths'             => '',
			'delete_files_on_uninstall' => 0,
			'delete_data_on_uninstall'  => 0,
		);
	}

	/**
	 * Array containing the settings' sections.
	 *
	 * @since 0.9.0
	 *
	 * @return array Settings sections.
	 */
	public static function get_settings_sections() {
		$settings_sections = array(
			'general'  => esc_html__( 'General', 'webberzone-image-optimizer' ),
			'quality'  => esc_html__( 'Quality', 'webberzone-image-optimizer' ),
			'delivery' => esc_html__( 'Delivery', 'webberzone-image-optimizer' ),
			'advanced' => esc_html__( 'Advanced', 'webberzone-image-optimizer' ),
		);

		return apply_filters( self::$prefix . '_settings_sections', $settings_sections ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
	}

	/**
	 * Array containing the settings' fields.
	 *
	 * @since 0.9.0
	 *
	 * @return array Settings fields.
	 */
	public static function get_registered_settings() {
		$settings = array(
			'general'  => self::settings_general(),
			'quality'  => self::settings_quality(),
			'delivery' => self::settings_delivery(),
			'advanced' => self::settings_advanced(),
		);

		return apply_filters( self::$prefix . '_registered_settings', $settings ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
	}

	/**
	 * General settings.
	 *
	 * @since 0.9.0
	 *
	 * @return array General settings.
	 */
	public static function settings_general() {
		$defaults = self::get_defaults();
		$settings = array(
			'formats'           => array(
				'id'      => 'formats',
				'name'    => esc_html__( 'Formats to generate', 'webberzone-image-optimizer' ),
				'desc'    => esc_html__( 'AVIF files are smaller than WebP but take longer to encode and are understood by slightly fewer browsers. Generating both lets each visitor receive the smallest file their browser can read. Formats your server cannot encode are listed as unavailable.', 'webberzone-image-optimizer' ),
				'type'    => 'multicheck',
				'default' => $defaults['formats'],
				'options' => self::get_format_options(),
			),
			'convert_on_upload' => array(
				'id'      => 'convert_on_upload',
				'name'    => esc_html__( 'Convert new uploads', 'webberzone-image-optimizer' ),
				'desc'    => esc_html__( 'Generate the optimized copies as soon as an image is uploaded or its thumbnails are regenerated.', 'webberzone-image-optimizer' ),
				'type'    => 'checkbox',
				'default' => $defaults['convert_on_upload'],
			),
			'convert_sizes'     => array(
				'id'      => 'convert_sizes',
				'name'    => esc_html__( 'Image sizes to convert', 'webberzone-image-optimizer' ),
				'desc'    => esc_html__( 'Leave every box unchecked to convert all sizes, which is what you want unless disk space is tight. A responsive image only switches format when every size in its srcset has been converted, so excluding a size that appears in your themes markup disables the optimization for those images.', 'webberzone-image-optimizer' ),
				'type'    => 'multicheck',
				'default' => $defaults['convert_sizes'],
				'options' => self::get_size_options(),
			),
			'min_saving'        => array(
				'id'      => 'min_saving',
				'name'    => esc_html__( 'Minimum saving (%)', 'webberzone-image-optimizer' ),
				'desc'    => esc_html__( 'Discard an optimized copy unless it is at least this much smaller than the original. Small or already-compressed images frequently grow when re-encoded, and keeping those wastes disk space for no benefit.', 'webberzone-image-optimizer' ),
				'type'    => 'number',
				'default' => $defaults['min_saving'],
				'min'     => 0,
				'max'     => 90,
				'size'    => 'small',
			),
			'sidecar_naming'    => array(
				'id'      => 'sidecar_naming',
				'name'    => esc_html__( 'Optimized file naming', 'webberzone-image-optimizer' ),
				'desc'    => esc_html__( 'Append keeps the original extension and adds the new one, e.g. photo.jpg.webp — this is the safe default. Replace produces photo.webp instead, but if a folder ever contains both photo.jpg and photo.png, their optimized copies would collide on the same photo.webp file and one would silently overwrite the other. Only choose Replace if you are sure your uploads never share a filename across extensions.', 'webberzone-image-optimizer' ),
				'type'    => 'radio',
				'default' => $defaults['sidecar_naming'],
				'options' => array(
					'append'  => esc_html__( 'Append the new extension (photo.jpg.webp) — safe', 'webberzone-image-optimizer' ),
					'replace' => esc_html__( 'Replace the extension (photo.webp) — can collide', 'webberzone-image-optimizer' ),
				),
			),
		);

		return apply_filters( self::$prefix . '_settings_general', $settings ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
	}

	/**
	 * Quality settings.
	 *
	 * @since 0.9.0
	 *
	 * @return array Quality settings.
	 */
	public static function settings_quality() {
		$defaults = self::get_defaults();
		$settings = array(
			'quality_webp'   => array(
				'id'      => 'quality_webp',
				'name'    => esc_html__( 'WebP quality', 'webberzone-image-optimizer' ),
				'desc'    => esc_html__( 'Between 1 and 100. The default of 82 is visually indistinguishable from the original for most photographs. Values above 90 grow the file quickly for very little visible gain.', 'webberzone-image-optimizer' ),
				'type'    => 'number',
				'default' => $defaults['quality_webp'],
				'min'     => 1,
				'max'     => 100,
				'size'    => 'small',
			),
			'quality_avif'   => array(
				'id'      => 'quality_avif',
				'name'    => esc_html__( 'AVIF quality', 'webberzone-image-optimizer' ),
				'desc'    => esc_html__( 'Between 1 and 100. AVIF and WebP quality numbers are not comparable: AVIF at 50 looks about the same as WebP at 82 while producing a noticeably smaller file.', 'webberzone-image-optimizer' ),
				'type'    => 'number',
				'default' => $defaults['quality_avif'],
				'min'     => 1,
				'max'     => 100,
				'size'    => 'small',
			),
			'effort_webp'    => array(
				'id'      => 'effort_webp',
				'name'    => esc_html__( 'WebP encoder effort', 'webberzone-image-optimizer' ),
				'desc'    => esc_html__( 'Between 0 and 6. Higher values spend more CPU time searching for a smaller file at identical visual quality. Because conversion happens once and the result is served many times, the highest setting is usually the right trade. Lower it if bulk runs are timing out.', 'webberzone-image-optimizer' ),
				'type'    => 'number',
				'default' => $defaults['effort_webp'],
				'min'     => 0,
				'max'     => 6,
				'size'    => 'small',
			),
			'effort_avif'    => array(
				'id'      => 'effort_avif',
				'name'    => esc_html__( 'AVIF encoder effort', 'webberzone-image-optimizer' ),
				'desc'    => esc_html__( 'Between 0 and 6. AVIF encoding is much slower than WebP, so the default is lower. The plugin also automatically speeds up encoding for very large images where the quality gain per pixel is small. Raise this for smaller files at the cost of longer conversion times.', 'webberzone-image-optimizer' ),
				'type'    => 'number',
				'default' => $defaults['effort_avif'],
				'min'     => 0,
				'max'     => 6,
				'size'    => 'small',
			),
			'strip_metadata' => array(
				'id'      => 'strip_metadata',
				'name'    => esc_html__( 'Strip metadata', 'webberzone-image-optimizer' ),
				'desc'    => esc_html__( 'Remove EXIF, GPS and embedded thumbnails from the optimized copies. The colour profile is always kept, so colours will not shift. Your original files are never modified.', 'webberzone-image-optimizer' ),
				'type'    => 'checkbox',
				'default' => $defaults['strip_metadata'],
			),
			'lossless_png'   => array(
				'id'      => 'lossless_png',
				'name'    => esc_html__( 'Lossless for PNG sources', 'webberzone-image-optimizer' ),
				'desc'    => esc_html__( 'Encode PNG sources without any quality loss. This is the right choice for logos, screenshots and line art, but produces much larger files for photographs saved as PNG.', 'webberzone-image-optimizer' ),
				'type'    => 'checkbox',
				'default' => $defaults['lossless_png'],
			),
		);

		return apply_filters( self::$prefix . '_settings_quality', $settings ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
	}

	/**
	 * Delivery settings.
	 *
	 * @since 0.9.0
	 *
	 * @return array Delivery settings.
	 */
	public static function settings_delivery() {
		$defaults = self::get_defaults();
		$settings = array(
			'enable_delivery'  => array(
				'id'      => 'enable_delivery',
				'name'    => esc_html__( 'Serve optimized images', 'webberzone-image-optimizer' ),
				'desc'    => esc_html__( 'Wrap images in a picture element so the browser picks the best format it supports. Because the choice is made by the browser rather than the server, this works correctly behind page caches and CDNs.', 'webberzone-image-optimizer' ),
				'type'    => 'checkbox',
				'default' => $defaults['enable_delivery'],
			),
			'rewrite_content'  => array(
				'id'      => 'rewrite_content',
				'name'    => esc_html__( 'Post content images', 'webberzone-image-optimizer' ),
				'desc'    => esc_html__( 'Rewrite images embedded in post and page content.', 'webberzone-image-optimizer' ),
				'type'    => 'checkbox',
				'default' => $defaults['rewrite_content'],
			),
			'rewrite_template' => array(
				'id'      => 'rewrite_template',
				'name'    => esc_html__( 'Theme and block images', 'webberzone-image-optimizer' ),
				'desc'    => esc_html__( 'Rewrite featured images, gallery images and any image rendered by a theme or block through the WordPress image functions.', 'webberzone-image-optimizer' ),
				'type'    => 'checkbox',
				'default' => $defaults['rewrite_template'],
			),
			'rewrite_buffer'   => array(
				'id'      => 'rewrite_buffer',
				'name'    => esc_html__( 'Whole page (buffered)', 'webberzone-image-optimizer' ),
				'desc'    => esc_html__( 'Catch images printed directly by a page builder or a hard-coded template by buffering the entire page and rewriting it before it is sent. This catches the most images but costs a little memory on every request, so leave it off unless you can see images the two options above are missing.', 'webberzone-image-optimizer' ),
				'type'    => 'checkbox',
				'default' => $defaults['rewrite_buffer'],
			),
			'server_rules'     => array(
				'id'   => 'server_rules',
				'name' => esc_html__( 'CSS background images', 'webberzone-image-optimizer' ),
				'desc' => Server_Rules::get_settings_description(),
				'type' => 'descriptive_text',
			),
		);

		return apply_filters( self::$prefix . '_settings_delivery', $settings ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
	}

	/**
	 * Advanced settings.
	 *
	 * @since 0.9.0
	 *
	 * @return array Advanced settings.
	 */
	public static function settings_advanced() {
		$defaults = self::get_defaults();
		$settings = array(
			'batch_size'                => array(
				'id'      => 'batch_size',
				'name'    => esc_html__( 'Images per batch', 'webberzone-image-optimizer' ),
				'desc'    => esc_html__( 'How many attachments to process in a single bulk step. Lower this if your server times out during a bulk run; raise it to finish faster on a fast server.', 'webberzone-image-optimizer' ),
				'type'    => 'number',
				'default' => $defaults['batch_size'],
				'min'     => 1,
				'max'     => 200,
				'size'    => 'small',
			),
			'background_queue'          => array(
				'id'      => 'background_queue',
				'name'    => esc_html__( 'Process the queue in the background', 'webberzone-image-optimizer' ),
				'desc'    => esc_html__( 'Keep working through the queue on a schedule even when the bulk screen is closed. Turn this off if you would rather the queue only advance while you watch it.', 'webberzone-image-optimizer' ),
				'type'    => 'checkbox',
				'default' => $defaults['background_queue'],
			),
			'lazy_convert'              => array(
				'id'      => 'lazy_convert',
				'name'    => esc_html__( 'Queue images on first view', 'webberzone-image-optimizer' ),
				'desc'    => esc_html__( 'When a page references an image that has not been converted yet, serve the original immediately and add the image to the queue. Nothing is ever converted during a page render, so visitors never wait for an encode.', 'webberzone-image-optimizer' ),
				'type'    => 'checkbox',
				'default' => $defaults['lazy_convert'],
			),
			'exclude_paths'             => array(
				'id'      => 'exclude_paths',
				'name'    => esc_html__( 'Exclude paths', 'webberzone-image-optimizer' ),
				'desc'    => esc_html__( 'One fragment per line. Any image whose path inside the uploads folder contains one of these fragments is left alone, for example 2019/07 or /logos/.', 'webberzone-image-optimizer' ),
				'type'    => 'textarea',
				'default' => $defaults['exclude_paths'],
			),
			'delete_files_on_uninstall' => array(
				'id'      => 'delete_files_on_uninstall',
				'name'    => esc_html__( 'Delete optimized files on uninstall', 'webberzone-image-optimizer' ),
				'desc'    => esc_html__( 'Remove every generated WebP and AVIF file when the plugin is deleted. Your original images are never touched either way. Leave this off if you may reinstall later and would rather not convert everything again.', 'webberzone-image-optimizer' ),
				'type'    => 'checkbox',
				'default' => $defaults['delete_files_on_uninstall'],
			),
			'delete_data_on_uninstall'  => array(
				'id'      => 'delete_data_on_uninstall',
				'name'    => esc_html__( 'Delete settings and records on uninstall', 'webberzone-image-optimizer' ),
				'desc'    => esc_html__( 'Remove the settings, the queue table and the per-image conversion records when the plugin is deleted.', 'webberzone-image-optimizer' ),
				'type'    => 'checkbox',
				'default' => $defaults['delete_data_on_uninstall'],
			),
		);

		return apply_filters( self::$prefix . '_settings_advanced', $settings ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
	}

	/**
	 * Get the selectable target formats, flagging any this server cannot encode.
	 *
	 * @since 0.9.0
	 *
	 * @return array<string, string> Format slug to label.
	 */
	public static function get_format_options(): array {
		$labels = array(
			'webp' => esc_html__( 'WebP', 'webberzone-image-optimizer' ),
			'avif' => esc_html__( 'AVIF', 'webberzone-image-optimizer' ),
		);

		$options = array();

		foreach ( $labels as $format => $label ) {
			if ( ! Capabilities::supports( $format ) ) {
				/* translators: %s: image format name. */
				$label = sprintf( esc_html__( '%s (not available on this server)', 'webberzone-image-optimizer' ), $label );
			}

			$options[ $format ] = $label;
		}

		return $options;
	}

	/**
	 * Get the registered image sizes as checkbox options.
	 *
	 * @since 0.9.0
	 *
	 * @return array<string, string> Size name to label.
	 */
	public static function get_size_options(): array {
		$options = array();

		foreach ( get_intermediate_image_sizes() as $size ) {
			$options[ $size ] = $size;
		}

		ksort( $options );

		return $options;
	}

	/**
	 * Modify settings on save.
	 *
	 * @since 0.9.0
	 *
	 * @param  array $settings Settings array.
	 * @return array Modified settings array.
	 */
	public function change_settings_on_save( $settings ) {
		// A format the server cannot encode would queue work that always fails.
		if ( ! empty( $settings['formats'] ) ) {
			$requested = wp_parse_list( $settings['formats'] );
			$supported = array_intersect( $requested, Capabilities::get_supported_formats() );

			$settings['formats'] = implode( ',', $supported );
		}

		return $settings;
	}

	/**
	 * Get the help sidebar.
	 *
	 * @since 0.9.0
	 *
	 * @return string Help sidebar content.
	 */
	public function get_help_sidebar() {
		$help_sidebar =
		'<p><strong>' . esc_html__( 'For more information:', 'webberzone-image-optimizer' ) . '</strong></p>' .
		'<p><a href="https://webberzone.github.io/webberzone-image-optimizer/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Plugin Homepage', 'webberzone-image-optimizer' ) . '</a></p>' .
		'<p><a href="https://webberzone.com/support/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Support', 'webberzone-image-optimizer' ) . '</a></p>';

		return apply_filters( self::$prefix . '_settings_help_sidebar', $help_sidebar ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
	}

	/**
	 * Get the help tabs.
	 *
	 * @since 0.9.0
	 *
	 * @return array Help tabs.
	 */
	public function get_help_tabs() {
		$help_tabs = array(
			array(
				'id'      => 'wzio-settings-general',
				'title'   => esc_html__( 'General', 'webberzone-image-optimizer' ),
				'content' =>
				'<p>' . esc_html__( 'The plugin never modifies or replaces your original images. Each converted copy is written alongside the original with the new extension appended, so photo.jpg gains photo.jpg.webp.', 'webberzone-image-optimizer' ) . '</p>' .
				'<p>' . esc_html__( 'Turning the plugin off, or deactivating it, immediately returns your site to serving the original files.', 'webberzone-image-optimizer' ) . '</p>',
			),
			array(
				'id'      => 'wzio-settings-bulk',
				'title'   => esc_html__( 'Bulk conversion', 'webberzone-image-optimizer' ),
				'content' =>
							'<p>' . esc_html__( 'Existing images are converted from the Bulk Optimize screen under the Media menu. The run is resumable: you can close the page and come back, and nothing is converted twice.', 'webberzone-image-optimizer' ) . '</p>',
			),
		);

		return apply_filters( self::$prefix . '_settings_help_tabs', $help_tabs ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
	}

	/**
	 * Get the admin footer text.
	 *
	 * @since 0.9.0
	 *
	 * @return string Admin footer text.
	 */
	public function get_admin_footer_text() {
		return sprintf(
		/* translators: 1: Plugin homepage link, 2: GitHub repository link */
			__( 'Thank you for using <a href="%1$s" target="_blank" rel="noopener noreferrer">WebberZone Image Optimizer</a>! Please <a href="%2$s" target="_blank" rel="noopener noreferrer">star us</a> on GitHub', 'webberzone-image-optimizer' ),
			'https://webberzone.github.io/webberzone-image-optimizer/',
			'https://github.com/WebberZone/webberzone-image-optimizer'
		);
	}

	/**
	 * Add plugin action links.
	 *
	 * @since 0.9.0
	 *
	 * @param  array $links Array of links.
	 * @return array Modified array of links.
	 */
	public function plugin_actions_links( $links ) {
		return array_merge(
			array(
				'settings' => '<a href="' . admin_url( 'upload.php?page=' . $this->menu_slug ) . '">' . esc_html__( 'Settings', 'webberzone-image-optimizer' ) . '</a>',
				'bulk'     => '<a href="' . admin_url( 'upload.php?page=wzio-bulk' ) . '">' . esc_html__( 'Bulk Optimize', 'webberzone-image-optimizer' ) . '</a>',
			),
			$links
		);
	}

	/**
	 * Add plugin row meta.
	 *
	 * @since 0.9.0
	 *
	 * @param  array  $links Array of links.
	 * @param  string $file  Plugin file.
	 * @return array Modified array of links.
	 */
	public function plugin_row_meta( $links, $file ) {
		if ( false !== strpos( $file, 'webberzone-image-optimizer.php' ) ) {
			$new_links = array(
				'support' => '<a href="https://webberzone.com/support/" target="_blank">' . esc_html__( 'Support', 'webberzone-image-optimizer' ) . '</a>',
				'donate'  => '<a href="https://wzn.io/donate-wz" target="_blank">' . esc_html__( 'Donate', 'webberzone-image-optimizer' ) . '</a>',
			);

			$links = array_merge( $links, $new_links );
		}

		return $links;
	}
}
