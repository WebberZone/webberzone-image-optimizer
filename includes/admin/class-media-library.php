<?php
/**
 * Media library integration.
 *
 * @package WebberZone\Image_Optimizer\Admin
 */

namespace WebberZone\Image_Optimizer\Admin;

use WebberZone\Image_Optimizer\Attachment_Meta;
use WebberZone\Image_Optimizer\Converter;
use WebberZone\Image_Optimizer\Processor;
use WebberZone\Image_Optimizer\Queue;
use WebberZone\Image_Optimizer\Util\Helpers;
use WebberZone\Image_Optimizer\Util\Hook_Registry;

if ( ! defined( 'WPINC' ) ) {
	exit;
}

/**
 * Adds per-image status and actions to the media list table.
 *
 * @since 0.9.0
 */
class Media_Library {


	/**
	 * Constructor.
	 *
	 * @since 0.9.0
	 */
	public function __construct() {
		Hook_Registry::add_filter( 'manage_media_columns', array( $this, 'add_column' ) );
		Hook_Registry::add_action( 'manage_media_custom_column', array( $this, 'render_column' ), 10, 2 );
		Hook_Registry::add_filter( 'media_row_actions', array( $this, 'add_row_actions' ), 10, 2 );
		Hook_Registry::add_filter( 'bulk_actions-upload', array( $this, 'add_bulk_actions' ) );
		Hook_Registry::add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk_restore' ), 10, 3 );
		Hook_Registry::add_action( 'admin_post_wzio_optimize_attachment', array( $this, 'handle_optimize' ) );
		Hook_Registry::add_action( 'admin_post_wzio_restore_attachment', array( $this, 'handle_restore' ) );
		Hook_Registry::add_action( 'wp_ajax_wzio_optimize_attachment', array( $this, 'ajax_optimize' ) );
		Hook_Registry::add_action( 'admin_notices', array( $this, 'render_notice' ) );
		Hook_Registry::add_action( 'attachment_submitbox_misc_actions', array( $this, 'render_submitbox' ) );
		Hook_Registry::add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue the script that drives the "Optimize" action over AJAX.
	 *
	 * @since 0.9.0
	 *
	 * @param  string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ): void {
		if ( 'upload.php' !== $hook_suffix && 'post.php' !== $hook_suffix ) {
			return;
		}

		if ( 'post.php' === $hook_suffix ) {
			$screen = get_current_screen();

			if ( ! $screen || 'attachment' !== $screen->id ) {
				return;
			}
		}

		$minimize = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

		wp_enqueue_script(
			'wzio-optimize',
			WZIO_PLUGIN_URL . 'includes/admin/js/optimize' . $minimize . '.js',
			array(),
			WZIO_VERSION,
			true
		);

		wp_localize_script(
			'wzio-optimize',
			'wzioOptimize',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wzio_optimize' ),
				'strings' => array(
					'optimizing' => esc_html__( 'Optimizing', 'webberzone-image-optimizer' ),
					'error'      => esc_html__( 'That image could not be optimized. Check the Bulk Optimize screen for the reason.', 'webberzone-image-optimizer' ),
					'timeout'    => esc_html__( 'Connection interrupted. Progress so far was saved — click Optimize again to continue.', 'webberzone-image-optimizer' ),
				),
			)
		);

		wp_add_inline_style( 'wp-admin', '.wzio-optimize-error{color:#b32d2e}' );
	}

	/**
	 * Confirm the outcome of a per-image action.
	 *
	 * @since 0.9.0
	 *
	 * @return void
	 */
	public function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['wzio_bulk_restored'] ) ) {
			$count = absint( wp_unslash( $_GET['wzio_bulk_restored'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of images. */
						_n( 'Optimized copies deleted for %d image. The originals are untouched.', 'Optimized copies deleted for %d images. The originals are untouched.', $count, 'webberzone-image-optimizer' ),
						$count
					)
				)
			);
		}

     // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$message = isset( $_GET['wzio_message'] ) ? sanitize_key( wp_unslash( $_GET['wzio_message'] ) ) : '';

		$notices = array(
			'optimized' => array( 'success', __( 'Optimized copies regenerated. The original image was not modified.', 'webberzone-image-optimizer' ) ),
			'restored'  => array( 'success', __( 'Optimized copies deleted. The original image is untouched and is being served again.', 'webberzone-image-optimizer' ) ),
			'failed'    => array( 'error', __( 'That image could not be optimized. Check the Bulk Optimize screen for the reason.', 'webberzone-image-optimizer' ) ),
			'busy'      => array( 'warning', __( 'Another optimization run is in progress, so this image was left alone. Try again in a moment.', 'webberzone-image-optimizer' ) ),
		);

		if ( ! isset( $notices[ $message ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $notices[ $message ][0] ),
			esc_html( $notices[ $message ][1] )
		);
	}

	/**
	 * Add the status column.
	 *
	 * @since 0.9.0
	 *
	 * @param  array<string, string> $columns Existing columns.
	 * @return array<string, string> Columns.
	 */
	public function add_column( $columns ) {
		$columns['wzio'] = esc_html__( 'Optimized', 'webberzone-image-optimizer' );

		return $columns;
	}

	/**
	 * Render the status column.
	 *
	 * @since 0.9.0
	 *
	 * @param  string $column_name Column key.
	 * @param  int    $post_id     Attachment ID.
	 * @return void
	 */
	public function render_column( $column_name, $post_id ) {
		if ( 'wzio' !== $column_name ) {
			return;
		}

		$post_id = (int) $post_id;

		if ( ! Converter::is_convertible_attachment( $post_id ) ) {
			echo '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">' . esc_html__( 'Not an image that can be optimized', 'webberzone-image-optimizer' ) . '</span>';
			return;
		}

		$totals = Attachment_Meta::get_totals( $post_id );

		if ( 0 === $totals['files'] ) {
			esc_html_e( 'Not yet', 'webberzone-image-optimizer' );
			return;
		}

		$percent = $totals['source'] > 0 ? round( ( $totals['saved'] / $totals['source'] ) * 100 ) : 0;

		printf(
		/* translators: 1: number of files, 2: bytes saved, 3: percentage saved. */
			esc_html__( '%1$d files, %2$s smaller (%3$d%%)', 'webberzone-image-optimizer' ),
			(int) $totals['files'],
			esc_html( Helpers::format_bytes( $totals['saved'] ) ),
			(int) $percent
		);
	}

	/**
	 * Add the per-image actions.
	 *
	 * @since 0.9.0
	 *
	 * @param  array<string, string> $actions Existing actions.
	 * @param  \WP_Post              $post    Attachment.
	 * @return array<string, string> Actions.
	 */
	public function add_row_actions( $actions, $post ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) || ! Converter::is_convertible_attachment( (int) $post->ID ) ) {
			return $actions;
		}

		$actions['wzio_optimize'] = sprintf(
			'<a href="%s" class="wzio-optimize-attachment" data-id="%d">%s</a>',
			esc_url( self::get_action_url( 'wzio_optimize_attachment', (int) $post->ID ) ),
			(int) $post->ID,
			esc_html__( 'Optimize', 'webberzone-image-optimizer' )
		);

		$record = Attachment_Meta::get( (int) $post->ID );

		if ( ! empty( $record['files'] ) ) {
			$actions['wzio_restore'] = sprintf(
				'<a href="%s" class="submitdelete">%s</a>',
				esc_url( self::get_action_url( 'wzio_restore_attachment', (int) $post->ID ) ),
				esc_html__( 'Delete optimized copies', 'webberzone-image-optimizer' )
			);
		}

		return $actions;
	}

	/**
	 * Add the bulk action to delete optimized copies.
	 *
	 * @since 0.9.0
	 *
	 * @param  array<string, string> $actions Existing bulk actions.
	 * @return array<string, string> Bulk actions.
	 */
	public function add_bulk_actions( $actions ) {
		$actions['wzio_restore'] = esc_html__( 'Delete optimized copies', 'webberzone-image-optimizer' );

		return $actions;
	}

	/**
	 * Handle the bulk action to delete optimized copies.
	 *
	 * The nonce and capability for the media list bulk actions are already
	 * verified by WordPress core before this filter runs.
	 *
	 * @since 0.9.0
	 *
	 * @param  string          $redirect_to Redirect URL.
	 * @param  string          $doaction    Bulk action name.
	 * @param  array<int, int> $post_ids  Selected attachment IDs.
	 * @return string Redirect URL.
	 */
	public function handle_bulk_restore( $redirect_to, $doaction, $post_ids ) {
		if ( 'wzio_restore' !== $doaction ) {
			return $redirect_to;
		}

		$count = 0;

		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;

			if ( ! current_user_can( 'edit_post', $post_id ) || ! Converter::is_convertible_attachment( $post_id ) ) {
				continue;
			}

			Converter::delete_sidecars( $post_id );
			++$count;
		}

		if ( 0 === $count ) {
			return $redirect_to;
		}

		return add_query_arg( 'wzio_bulk_restored', $count, $redirect_to );
	}

	/**
	 * Show optimization status and actions in the attachment edit Save box.
	 *
	 * @since 0.9.0
	 *
	 * @param  \WP_Post $post Attachment.
	 * @return void
	 */
	public function render_submitbox( $post ): void {
		$attachment_id = (int) $post->ID;

		if ( ! Converter::is_convertible_attachment( $attachment_id ) ) {
			return;
		}

		$totals = Attachment_Meta::get_totals( $attachment_id );
		$record = Attachment_Meta::get( $attachment_id );

		echo '<div class="misc-pub-section misc-pub-wzio">';
		echo '<strong>' . esc_html__( 'Image optimization', 'webberzone-image-optimizer' ) . '</strong><br />';

		if ( 0 === $totals['files'] ) {
			esc_html_e( 'Not yet optimized.', 'webberzone-image-optimizer' );
		} else {
			$percent = $totals['source'] > 0 ? round( ( $totals['saved'] / $totals['source'] ) * 100 ) : 0;

			printf(
			/* translators: 1: number of files, 2: bytes saved, 3: percentage saved. */
				esc_html__( '%1$d files, %2$s smaller (%3$d%%).', 'webberzone-image-optimizer' ),
				(int) $totals['files'],
				esc_html( Helpers::format_bytes( $totals['saved'] ) ),
				(int) $percent
			);

			if ( ! empty( $totals['formats'] ) ) {
				echo '<ul class="wzio-submitbox-formats">';
				foreach ( $totals['formats'] as $format => $bytes ) {
					printf(
						'<li>%1$s: %2$s</li>',
						esc_html( strtoupper( $format ) ),
						esc_html( Helpers::format_bytes( $bytes ) )
					);
				}
				echo '</ul>';
			}
		}

		echo '<p class="wzio-submitbox-actions">';
		printf(
			'<a href="%s" class="wzio-optimize-attachment" data-id="%d">%s</a>',
			esc_url( self::get_action_url( 'wzio_optimize_attachment', $attachment_id ) ),
			(int) $attachment_id,
			esc_html__( 'Optimize', 'webberzone-image-optimizer' )
		);

		if ( ! empty( $record['files'] ) ) {
			printf(
				' | <a href="%s" class="submitdelete">%s</a>',
				esc_url( self::get_action_url( 'wzio_restore_attachment', $attachment_id ) ),
				esc_html__( 'Delete optimized copies', 'webberzone-image-optimizer' )
			);
		}
		echo '</p>';

		echo '</div>';
	}

	/**
	 * Build a nonced admin-post URL for an attachment action.
	 *
	 * @since 0.9.0
	 *
	 * @param  string $action        Action name.
	 * @param  int    $attachment_id Attachment ID.
	 * @return string URL.
	 */
	private static function get_action_url( string $action, int $attachment_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => $action,
					'id'     => $attachment_id,
				),
				admin_url( 'admin-post.php' )
			),
			$action . '_' . $attachment_id
		);
	}

	/**
	 * Validate an incoming attachment action.
	 *
	 * @since 0.9.0
	 *
	 * @param  string $action Action name.
	 * @return int Attachment ID.
	 */
	private function validate_action( string $action ): int {
		$attachment_id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		check_admin_referer( $action . '_' . $attachment_id );

		if ( ! current_user_can( 'edit_post', $attachment_id ) || ! Converter::is_convertible_attachment( $attachment_id ) ) {
			wp_die( esc_html__( 'You do not have permission to optimize this image.', 'webberzone-image-optimizer' ) );
		}

		return $attachment_id;
	}

	/**
	 * Convert a single attachment and return to the media library. No-JS fallback.
	 *
	 * @since 0.9.0
	 *
	 * @return void
	 */
	public function handle_optimize(): void {
		$attachment_id = $this->validate_action( 'wzio_optimize_attachment' );

		$outcome = Processor::process_attachment( $attachment_id, false );

		if ( $outcome['locked'] ) {
			$this->redirect_back( 'busy' );
		}

		$this->redirect_back( Queue::FAILED === $outcome['status'] ? 'failed' : 'optimized' );
	}

	/**
	 * Convert the next file of an attachment over AJAX, one file per call.
	 *
	 * @since 0.9.0
	 *
	 * @return void
	 */
	public function ajax_optimize(): void {
		check_ajax_referer( 'wzio_optimize', 'nonce' );

		$attachment_id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;

		if ( ! current_user_can( 'edit_post', $attachment_id ) || ! Converter::is_convertible_attachment( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to optimize this image.', 'webberzone-image-optimizer' ) ), 403 );
		}

		if ( Queue::PROCESSING !== Queue::get_status( $attachment_id ) ) {
			Queue::add( array( $attachment_id ), true );

			if ( null === Queue::claim_attachment( $attachment_id ) ) {
				wp_send_json_error( array( 'message' => __( 'Another optimization is already running. Try again in a moment.', 'webberzone-image-optimizer' ) ), 409 );
			}
		}

		$step = Converter::convert_next_file( $attachment_id );

		if ( is_wp_error( $step ) ) {
			Queue::complete( Queue::get_id( $attachment_id ), Queue::FAILED, 0, $step->get_error_message() );
			wp_send_json_error( array( 'message' => $step->get_error_message() ) );
		}

		if ( $step['done'] ) {
			$totals = Attachment_Meta::get_totals( $attachment_id );
			Queue::complete( Queue::get_id( $attachment_id ), Queue::DONE, $totals['saved'], '', $totals['source'] );
		}

		wp_send_json_success( $step );
	}

	/**
	 * Delete the generated copies for a single attachment.
	 *
	 * @since 0.9.0
	 *
	 * @return void
	 */
	public function handle_restore(): void {
		$attachment_id = $this->validate_action( 'wzio_restore_attachment' );

		Converter::delete_sidecars( $attachment_id );

		$this->redirect_back( 'restored' );
	}

	/**
	 * Send the administrator back where they came from.
	 *
	 * @since 0.9.0
	 *
	 * @param  string $message Message key.
	 * @return void
	 */
	private function redirect_back( string $message ): void {
		$referer = wp_get_referer();
		$target  = $referer ? $referer : admin_url( 'upload.php' );

		wp_safe_redirect( add_query_arg( 'wzio_message', $message, $target ) );
		exit;
	}
}
