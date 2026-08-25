<?php
/**
 * Offer to adopt optimized copies left by another plugin.
 *
 * @since 1.0.1
 *
 * @package WebberZone\Image_Optimizer\Admin
 */

namespace WebberZone\Image_Optimizer\Admin;

use WebberZone\Image_Optimizer\Frontend\Server_Rules;
use WebberZone\Image_Optimizer\Naming_Detector;
use WebberZone\Image_Optimizer\Util\Hook_Registry;

if ( ! defined( 'WPINC' ) ) {
	exit;
}

/**
 * Prompts to match the naming already used on disk.
 *
 * @since 1.0.1
 */
class Naming_Notice {

	/**
	 * Capability required to see and act on the notice.
	 *
	 * @since 1.0.1
	 * @var string
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * User meta recording the naming the notice was dismissed for.
	 *
	 * @since 1.0.1
	 * @var string
	 */
	const DISMISSED = 'wzio_naming_notice_dismissed';

	/**
	 * Whether this request needs a scan once the response has been sent.
	 *
	 * @since 1.0.1
	 *
	 * @var bool
	 */
	private bool $scan_needed = false;

	/**
	 * Constructor.
	 *
	 * @since 1.0.1
	 */
	public function __construct() {
		Hook_Registry::add_action( 'admin_notices', array( $this, 'render' ) );
		Hook_Registry::add_action( 'shutdown', array( $this, 'maybe_scan' ) );
		Hook_Registry::add_action( 'admin_post_wzio_switch_naming', array( $this, 'handle_switch' ) );
		Hook_Registry::add_action( 'admin_post_wzio_dismiss_naming', array( $this, 'handle_dismiss' ) );
	}

	/**
	 * Render the notice on the screens where it is actionable.
	 *
	 * @since 1.0.1
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) || ! $this->is_relevant_screen() ) {
			return;
		}

		$this->render_result();

		$report = Naming_Detector::get_cached_report();

		// Thousands of stat calls are not this page load's to pay for.
		if ( null === $report ) {
			$this->scan_needed = true;

			return;
		}

		$suggestion = Naming_Detector::get_suggestion( $report );

		if ( '' === $suggestion ) {
			return;
		}

		if ( get_user_meta( get_current_user_id(), self::DISMISSED, true ) === $suggestion ) {
			return;
		}

		$images = (int) $report['images'][ $suggestion ];
		$files  = (int) $report['files'][ $suggestion ];

		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong></p><p>%2$s</p>%3$s<p>%4$s %5$s</p></div>',
			esc_html__( 'Existing optimized images were found that are not being served.', 'webberzone-image-optimizer' ),
			esc_html( $this->describe( $images, $files, $suggestion, (int) $report['sampled'] ) ),
			'replace' === $suggestion ? '<p>' . esc_html__( 'Replace naming drops the original extension, so if a folder ever contains both photo.jpg and photo.png their optimized copies collide on one photo.webp file. Only switch if you are sure your uploads never share a filename across extensions.', 'webberzone-image-optimizer' ) . '</p>' : '',
			$this->action_link( $suggestion ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$this->dismiss_link( $suggestion ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	/**
	 * Scan once the response has been sent, when the notice found no report.
	 *
	 * @since 1.0.1
	 *
	 * @return void
	 */
	public function maybe_scan(): void {
		if ( ! $this->scan_needed ) {
			return;
		}

		$this->scan_needed = false;

		Naming_Detector::store( Naming_Detector::scan() );
	}

	/**
	 * Switch the naming setting and forget the report.
	 *
	 * @since 1.0.1
	 *
	 * @return void
	 */
	public function handle_switch(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to change this setting.', 'webberzone-image-optimizer' ) );
		}

		$naming = isset( $_GET['naming'] ) ? sanitize_key( wp_unslash( $_GET['naming'] ) ) : '';

		check_admin_referer( 'wzio_switch_naming_' . $naming );

		if ( ! in_array( $naming, array( 'append', 'replace' ), true ) ) {
			wp_die( esc_html__( 'Unknown naming strategy.', 'webberzone-image-optimizer' ) );
		}

		\wzio_update_option( 'sidecar_naming', $naming );

		// Resolver cache keys embed the sidecar path, so the old entries fall out of use.
		Naming_Detector::forget();
		delete_user_meta( get_current_user_id(), self::DISMISSED );

		// The generated rewrite rules only ever match appended names.
		$message = 'replace' === $naming && Server_Rules::is_apache_rules_installed()
			? 'naming_switched_rules'
			: 'naming_switched';

		wp_safe_redirect( add_query_arg( 'wzio_message', $message, $this->return_url() ) );
		exit;
	}

	/**
	 * Remember that the current suggestion was declined.
	 *
	 * @since 1.0.1
	 *
	 * @return void
	 */
	public function handle_dismiss(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to dismiss this notice.', 'webberzone-image-optimizer' ) );
		}

		$naming = isset( $_GET['naming'] ) ? sanitize_key( wp_unslash( $_GET['naming'] ) ) : '';

		check_admin_referer( 'wzio_dismiss_naming_' . $naming );

		update_user_meta( get_current_user_id(), self::DISMISSED, $naming );

		wp_safe_redirect( $this->return_url() );
		exit;
	}

	/**
	 * Whether the current screen is one where the notice belongs.
	 *
	 * @since 1.0.1
	 *
	 * @return bool True when the notice should be considered.
	 */
	private function is_relevant_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen ) {
			return false;
		}

		return in_array(
			$screen->id,
			array( 'upload', 'media_page_' . Settings::MENU_SLUG, 'media_page_' . Bulk_Page::SLUG ),
			true
		);
	}

	/**
	 * Sentence describing what was found.
	 *
	 * @since 1.0.1
	 *
	 * @param  int    $images  Images with sidecars under the other naming.
	 * @param  int    $files   Files found under the other naming.
	 * @param  string $naming  The naming those files use.
	 * @param  int    $sampled Attachments examined.
	 * @return string Message.
	 */
	private function describe( int $images, int $files, string $naming, int $sampled ): string {
		$example = 'replace' === $naming
			? __( 'photo.webp, with the original extension replaced', 'webberzone-image-optimizer' )
			: __( 'photo.jpg.webp, with the new extension appended', 'webberzone-image-optimizer' );

		return sprintf(
			/* translators: 1: number of files, 2: number of images, 3: number of attachments examined, 4: naming example. */
			__( '%1$d optimized files across %2$d of the %3$d images checked are named like %4$s, which is not the naming this site is set to use. They are being ignored. Switching makes them serve immediately, and the next optimization run keeps whichever copy is smaller.', 'webberzone-image-optimizer' ),
			$files,
			$images,
			$sampled,
			$example
		);
	}

	/**
	 * Button that performs the switch.
	 *
	 * @since 1.0.1
	 *
	 * @param  string $naming Naming to switch to.
	 * @return string Anchor markup.
	 */
	private function action_link( string $naming ): string {
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'wzio_switch_naming',
					'naming' => $naming,
				),
				admin_url( 'admin-post.php' )
			),
			'wzio_switch_naming_' . $naming
		);

		return sprintf(
			'<a href="%1$s" class="button button-primary">%2$s</a>',
			esc_url( $url ),
			'replace' === $naming
				? esc_html__( 'Switch to Replace naming', 'webberzone-image-optimizer' )
				: esc_html__( 'Switch to Append naming', 'webberzone-image-optimizer' )
		);
	}

	/**
	 * Link that hides the notice for this suggestion.
	 *
	 * @since 1.0.1
	 *
	 * @param  string $naming Naming being suggested.
	 * @return string Anchor markup.
	 */
	private function dismiss_link( string $naming ): string {
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'wzio_dismiss_naming',
					'naming' => $naming,
				),
				admin_url( 'admin-post.php' )
			),
			'wzio_dismiss_naming_' . $naming
		);

		return sprintf( '<a href="%1$s">%2$s</a>', esc_url( $url ), esc_html__( 'No thanks', 'webberzone-image-optimizer' ) );
	}

	/**
	 * Confirmation shown after a switch.
	 *
	 * @since 1.0.1
	 *
	 * @return void
	 */
	private function render_result(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$message = isset( $_GET['wzio_message'] ) ? sanitize_key( wp_unslash( $_GET['wzio_message'] ) ) : '';

		if ( 'naming_switched' !== $message && 'naming_switched_rules' !== $message ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'Optimized file naming updated. Existing copies that match are being served now. Run a bulk optimization to record them and to add any missing formats.', 'webberzone-image-optimizer' )
		);

		if ( 'naming_switched_rules' !== $message ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%1$s</p><p>%2$s</p></div>',
			esc_html__( 'The rewrite rules this plugin added to your .htaccess file only match names with the extension appended, so images referenced from stylesheets will no longer find an optimized copy.', 'webberzone-image-optimizer' ),
			sprintf(
				/* translators: %s: link to the Delivery settings tab. */
				esc_html__( 'Remove the block on the %s tab, or switch the naming back.', 'webberzone-image-optimizer' ),
				'<a href="' . esc_url( admin_url( 'upload.php?page=' . Settings::MENU_SLUG . '&tab=delivery' ) ) . '">' . esc_html__( 'Delivery', 'webberzone-image-optimizer' ) . '</a>'
			)
		);
	}

	/**
	 * Where to send the browser after an action.
	 *
	 * @since 1.0.1
	 *
	 * @return string Admin URL.
	 */
	private function return_url(): string {
		$referer = wp_get_referer();

		return $referer ? $referer : admin_url( 'upload.php?page=' . Settings::MENU_SLUG );
	}
}
