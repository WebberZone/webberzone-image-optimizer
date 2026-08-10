<?php
/**
 * Media library integration.
 *
 * @package WebberZone\Image_Optimizer\Admin
 */

namespace WebberZone\Image_Optimizer\Admin;

use WebberZone\Image_Optimizer\Attachment_Meta;
use WebberZone\Image_Optimizer\Converter;
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
		Hook_Registry::add_action( 'admin_post_wzio_optimize_attachment', array( $this, 'handle_optimize' ) );
		Hook_Registry::add_action( 'admin_post_wzio_restore_attachment', array( $this, 'handle_restore' ) );
		Hook_Registry::add_action( 'admin_notices', array( $this, 'render_notice' ) );
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
		$message = isset( $_GET['wzio_message'] ) ? sanitize_key( wp_unslash( $_GET['wzio_message'] ) ) : '';

		$notices = array(
			'optimized' => array( 'success', __( 'Optimized copies regenerated. The original image was not modified.', 'webberzone-image-optimizer' ) ),
			'restored'  => array( 'success', __( 'Optimized copies deleted. The original image is untouched and is being served again.', 'webberzone-image-optimizer' ) ),
			'failed'    => array( 'error', __( 'That image could not be optimized. Check the Bulk Optimize screen for the reason.', 'webberzone-image-optimizer' ) ),
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
			'<a href="%s">%s</a>',
			esc_url( self::get_action_url( 'wzio_optimize_attachment', (int) $post->ID ) ),
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
	 * Convert a single attachment and return to the media library.
	 *
	 * @since 0.9.0
	 *
	 * @return void
	 */
	public function handle_optimize(): void {
		$attachment_id = $this->validate_action( 'wzio_optimize_attachment' );

		$summary = Converter::convert_attachment( $attachment_id, array( 'force' => true ) );

		$this->redirect_back( is_wp_error( $summary ) ? 'failed' : 'optimized' );
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
