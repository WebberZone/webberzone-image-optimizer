<?php
/**
 * Admin bootstrap.
 *
 * @since 1.0.0
 *
 * @package WebberZone\Image_Optimizer\Admin
 */

namespace WebberZone\Image_Optimizer\Admin;

if ( ! defined( 'WPINC' ) ) {
	exit;
}

/**
 * Loads the admin area components.
 *
 * @since 1.0.0
 */
class Admin {

	/**
	 * Settings screen.
	 *
	 * @since 1.0.0
	 *
	 * @var Settings
	 */
	public Settings $settings;

	/**
	 * Bulk conversion screen.
	 *
	 * @since 1.0.0
	 *
	 * @var Bulk_Page
	 */
	public Bulk_Page $bulk_page;

	/**
	 * Media library integration.
	 *
	 * @since 1.0.0
	 *
	 * @var Media_Library
	 */
	public Media_Library $media_library;

	/**
	 * Admin banner helper.
	 *
	 * @since 1.0.0
	 *
	 * @var Admin_Banner
	 */
	public Admin_Banner $admin_banner;

	/**
	 * Foreign sidecar naming prompt.
	 *
	 * @since 1.0.1
	 *
	 * @var Naming_Notice
	 */
	public Naming_Notice $naming_notice;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->settings      = new Settings();
		$this->bulk_page     = new Bulk_Page();
		$this->media_library = new Media_Library();
		$this->admin_banner  = new Admin_Banner( $this->get_admin_banner_config() );
		$this->naming_notice = new Naming_Notice();
	}

	/**
	 * Configuration for the admin banner.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed> Configuration.
	 */
	private function get_admin_banner_config(): array {
		return array(
			'capability' => 'manage_options',
			'prefix'     => 'wzio',
			'style'      => array(
				'version' => WZIO_VERSION,
			),
			'screen_ids' => array(
				'media_page_wzio-settings',
				'media_page_wzio-bulk',
			),
			'page_slugs' => array(
				'wzio-settings',
				'wzio-bulk',
			),
			'strings'    => array(
				'region_label' => esc_html__( 'WebberZone Image Optimizer quick links', 'webberzone-image-optimizer' ),
				'nav_label'    => esc_html__( 'WebberZone Image Optimizer admin shortcuts', 'webberzone-image-optimizer' ),
				'eyebrow'      => esc_html__( 'WebberZone Image Optimizer', 'webberzone-image-optimizer' ),
				'title'        => esc_html__( 'Smaller images, same picture.', 'webberzone-image-optimizer' ),
				'text'         => esc_html__( 'Convert your media library to WebP and AVIF and let every browser download the smallest file it can read. Your original images are never modified.', 'webberzone-image-optimizer' ),
			),
			'sections'   => array(
				'settings' => array(
					'label'      => esc_html__( 'Settings', 'webberzone-image-optimizer' ),
					'url'        => admin_url( 'upload.php?page=wzio-settings' ),
					'screen_ids' => array( 'media_page_wzio-settings' ),
					'page_slugs' => array( 'wzio-settings' ),
				),
				'bulk'     => array(
					'label'      => esc_html__( 'Bulk Optimize', 'webberzone-image-optimizer' ),
					'url'        => admin_url( 'upload.php?page=wzio-bulk' ),
					'screen_ids' => array( 'media_page_wzio-bulk' ),
					'page_slugs' => array( 'wzio-bulk' ),
				),
				'support'  => array(
					'label'  => esc_html__( 'Support', 'webberzone-image-optimizer' ),
					'url'    => 'https://webberzone.com/support/',
					'type'   => 'secondary',
					'target' => '_blank',
					'rel'    => 'noopener noreferrer',
				),
				'plugins'  => array(
					'label'  => esc_html__( 'More Plugins', 'webberzone-image-optimizer' ),
					'url'    => 'https://webberzone.com/plugins/',
					'type'   => 'secondary',
					'target' => '_blank',
					'rel'    => 'noopener noreferrer',
				),
			),
		);
	}
}
