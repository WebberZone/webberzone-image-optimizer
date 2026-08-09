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
 * Generates the optional server rules for content-negotiated delivery.
 *
 * The `<picture>` rewrite cannot reach an image referenced from a stylesheet,
 * because the browser never sees markup it could choose from. Content
 * negotiation on the `Accept` header covers that case, at the cost of caring
 * about caches: the rules below always send `Vary: Accept` so that a proxy
 * cannot hand a WebP response to a browser that did not ask for one.
 *
 * The rules are printed for the administrator to install by hand. Writing to
 * a server config file on the administrator's behalf is not something a plugin
 * should do silently.
 *
 * @since 1.0.0
 */
class Server_Rules {


	/**
	 * Build the Apache rules.
	 *
	 * @since 1.0.0
	 *
	 * @return string Rules block.
	 */
	public static function get_apache_rules(): string {
		$lines = array(
			'# BEGIN WebberZone Image Optimizer',
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
		$lines[] = '# END WebberZone Image Optimizer';

		return implode( "\n", $lines );
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
			'# WebberZone Image Optimizer - add to the server block.',
			'map $http_accept $wzio_suffix {',
			'	default "";',
		);

		// nginx uses the first matching entry, so list the preferred format first.
		foreach ( $formats as $format ) {
			$lines[] = '	"~*' . Helpers::get_mime_type( $format ) . '" ".' . $format . '";';
		}

		$lines[] = '}';
		$lines[] = '';
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

		$apache = '<p><strong>' . esc_html__( 'Apache or LiteSpeed — add above the WordPress rules in .htaccess', 'webberzone-image-optimizer' ) . '</strong></p>'
		. '<textarea rows="12" class="large-text code" readonly onclick="this.select();">' . esc_textarea( self::get_apache_rules() ) . '</textarea>';

		$nginx = '<p><strong>' . esc_html__( 'nginx — add to your server configuration and reload', 'webberzone-image-optimizer' ) . '</strong></p>'
		. '<textarea rows="10" class="large-text code" readonly onclick="this.select();">' . esc_textarea( self::get_nginx_rules() ) . '</textarea>';

		return $intro . $apache . $nginx;
	}
}
