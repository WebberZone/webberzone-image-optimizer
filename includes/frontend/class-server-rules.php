<?php
/**
 * Web server rewrite rules for CSS background images.
 *
 * @package WebberZone\Image_Optimizer
 */

namespace WebberZone\Image_Optimizer\Frontend;

use WebberZone\Image_Optimizer\Util\Helpers;

if ( ! defined( 'WPINC' ) ) {
	exit;
}

/**
 * Generates optional `Vary: Accept` rules for stylesheet images.
 *
 * @since 1.0.0
 */
class Server_Rules {


	/**
	 * `.htaccess` marker name, per `insert_with_markers()`'s BEGIN/END convention.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const MARKER = 'WebberZone Image Optimizer';

	/**
	 * Build the Apache rule lines, without the surrounding marker comments.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, string> Rule lines.
	 */
	public static function get_apache_rule_lines(): array {
		$lines = array(
			'<IfModule mod_rewrite.c>',
			'	RewriteEngine On',
		);

		foreach ( Helpers::get_formats() as $format ) {
			$mime = Helpers::get_mime_type( $format );

			$lines[] = '';
			$lines[] = '	# Serve ' . strtoupper( $format ) . ' when the browser accepts it and a sidecar exists.';
			$lines[] = '	RewriteCond %{HTTP_ACCEPT} ' . preg_quote( $mime, '/' );
			$lines[] = '	RewriteCond %{REQUEST_FILENAME} \.(jpe?g|png|gif)$';
			$lines[] = '	RewriteCond %{REQUEST_FILENAME}.' . $format . ' -f';
			$lines[] = '	RewriteRule ^(.+)$ $1.' . $format . ' [T=' . $mime . ',L]';
		}

		$lines[] = '</IfModule>';
		$lines[] = '';
		$lines[] = '<IfModule mod_headers.c>';
		$lines[] = '	# Without this a shared cache can serve one visitor\'s format to everyone.';
		$lines[] = '	<FilesMatch "\.(jpe?g|png|gif)$">';
		$lines[] = '		Header append Vary Accept';
		$lines[] = '	</FilesMatch>';
		$lines[] = '</IfModule>';

		return $lines;
	}

	/**
	 * Build the Apache rules, including the marker comments, for display.
	 *
	 * @since 1.0.0
	 *
	 * @return string Rules block.
	 */
	public static function get_apache_rules(): string {
		$lines = array_merge(
			array( '# BEGIN ' . self::MARKER ),
			self::get_apache_rule_lines(),
			array( '# END ' . self::MARKER )
		);

		return implode( "\n", $lines );
	}

	/**
	 * Absolute path to the site's `.htaccess` file.
	 *
	 * @since 1.0.0
	 *
	 * @return string Path.
	 */
	public static function htaccess_path(): string {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		return get_home_path() . '.htaccess';
	}

	/**
	 * Whether this plugin's marker block is currently present in `.htaccess`.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when installed.
	 */
	public static function is_apache_rules_installed(): bool {
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		$path = self::htaccess_path();

		if ( ! file_exists( $path ) ) {
			return false;
		}

		return array() !== extract_from_markers( $path, self::MARKER );
	}

	/**
	 * Whether `.htaccess` exists and is writable, or can be created.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when the file can be written to.
	 */
	public static function is_htaccess_writable(): bool {
		$path = self::htaccess_path();

		return file_exists( $path )
			? is_writable( $path ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			: is_writable( dirname( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
	}

	/**
	 * Write this plugin's marker block into `.htaccess`.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True on success.
	 */
	public static function install_apache_rules(): bool {
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		return (bool) insert_with_markers( self::htaccess_path(), self::MARKER, self::get_apache_rule_lines() );
	}

	/**
	 * Remove this plugin's marker block from `.htaccess`, leaving the rest alone.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True on success.
	 */
	public static function remove_apache_rules(): bool {
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		if ( ! file_exists( self::htaccess_path() ) ) {
			return true;
		}

		return (bool) insert_with_markers( self::htaccess_path(), self::MARKER, array() );
	}

	/**
	 * Build the nginx rules.
	 *
	 * @since 1.0.0
	 *
	 * @return string Rules block.
	 */
	public static function get_nginx_rules(): string {
		$formats = Helpers::get_formats();
		$lines   = array(
			'# WebberZone Image Optimizer.',
			'# Add the map to the http block, then add the location block to the relevant server block.',
			'# http context',
			'map $http_accept $wzio_suffix {',
			'	default "";',
		);

		// nginx uses the first matching entry, so list the preferred format first.
		foreach ( $formats as $format ) {
			$lines[] = '	"~*' . Helpers::get_mime_type( $format ) . '" ".' . $format . '";';
		}

		$lines[] = '}';
		$lines[] = '';
		$lines[] = '# server context';
		$lines[] = 'location ~* ^(?<wzio_base>/.+\.(?:jpe?g|png|gif))$ {';
		$lines[] = '	add_header Vary Accept;';
		$lines[] = '	try_files $wzio_base$wzio_suffix $wzio_base =404;';
		$lines[] = '}';

		return implode( "\n", $lines );
	}

	/**
	 * The description rendered on the Delivery settings tab.
	 *
	 * @since 1.0.0
	 *
	 * @return string HTML.
	 */
	public static function get_settings_description(): string {
		$intro = '<p>' . esc_html__( 'Images referenced from a stylesheet cannot be rewritten in markup, because the browser is never offered a choice. If you want those optimized too, add one of the blocks below to your web server configuration. Everything else on this tab works without any server changes.', 'webberzone-image-optimizer' ) . '</p>'
		. '<p>' . esc_html__( 'Both blocks send a Vary: Accept header. Do not remove it: without it a CDN or page cache can hand a WebP file to a browser that cannot display it.', 'webberzone-image-optimizer' ) . '</p>';

		// Raw read: wzio_get_option() would recurse back into get_settings_defaults(), which builds this.
		$raw = get_option( \WebberZone\Image_Optimizer\Options_API::SETTINGS_OPTION, array() );

		if ( is_array( $raw ) && 'replace' === ( $raw['sidecar_naming'] ?? 'append' ) ) {
			$intro .= '<p><strong>' . esc_html__( 'These rules assume the "Append the new extension" file naming option. With "Replace the extension" selected, they will not find your optimized files.', 'webberzone-image-optimizer' ) . '</strong></p>';
		}

		$apache = '<p><strong>' . esc_html__( 'Apache or LiteSpeed — add above the WordPress rules in .htaccess', 'webberzone-image-optimizer' ) . '</strong></p>'
		. '<textarea rows="12" class="large-text code" readonly onclick="this.select();">' . esc_textarea( self::get_apache_rules() ) . '</textarea>'
		. self::get_htaccess_controls();

		$nginx = '<p><strong>' . esc_html__( 'nginx — add the map to the http block, the location to the relevant server block, then reload', 'webberzone-image-optimizer' ) . '</strong></p>'
		. '<textarea rows="10" class="large-text code" readonly onclick="this.select();">' . esc_textarea( self::get_nginx_rules() ) . '</textarea>'
		. '<p class="description">' . esc_html__( 'nginx cannot reload its own configuration from PHP, so this block has to be added and reloaded by hand — there is no one-click option here.', 'webberzone-image-optimizer' ) . '</p>';

		return $intro . $apache . $nginx;
	}

	/**
	 * The one-click install/remove controls shown under the Apache textarea.
	 *
	 * @since 1.0.0
	 *
	 * @return string HTML.
	 */
	private static function get_htaccess_controls(): string {
		if ( ! self::is_htaccess_writable() ) {
			return '<p class="description">' . esc_html__( 'The .htaccess file is not writable by PHP, so it has to be edited by hand using the block above.', 'webberzone-image-optimizer' ) . '</p>';
		}

		$installed = self::is_apache_rules_installed();

		return '<p class="wzio-htaccess-controls" data-nonce="' . esc_attr( wp_create_nonce( 'wzio_htaccess' ) ) . '">'
		. '<button type="button" class="button" id="wzio-htaccess-install"' . ( $installed ? ' hidden' : '' ) . '>' . esc_html__( 'Add to .htaccess', 'webberzone-image-optimizer' ) . '</button> '
		. '<button type="button" class="button" id="wzio-htaccess-remove"' . ( $installed ? '' : ' hidden' ) . '>' . esc_html__( 'Remove from .htaccess', 'webberzone-image-optimizer' ) . '</button> '
		. '<span id="wzio-htaccess-status">' . ( $installed ? esc_html__( 'Currently installed.', 'webberzone-image-optimizer' ) : '' ) . '</span>'
		. '</p>';
	}
}
