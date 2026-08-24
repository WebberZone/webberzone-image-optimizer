<?php
/**
 * Bulk conversion screen.
 *
 * @package WebberZone\Image_Optimizer\Admin
 */

namespace WebberZone\Image_Optimizer\Admin;

use WebberZone\Image_Optimizer\Capabilities;
use WebberZone\Image_Optimizer\Converter;
use WebberZone\Image_Optimizer\Database;
use WebberZone\Image_Optimizer\Processor;
use WebberZone\Image_Optimizer\Queue;
use WebberZone\Image_Optimizer\Scanner;
use WebberZone\Image_Optimizer\Util\Helpers;
use WebberZone\Image_Optimizer\Util\Hook_Registry;

if ( ! defined( 'WPINC' ) ) {
	exit;
}

/**
 * Provides the resumable Bulk Optimize screen and AJAX endpoints.
 *
 * @since 1.0.0
 */
class Bulk_Page {


	/**
	 * Menu slug.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const SLUG = 'wzio-bulk';

	/**
	 * Capability required to run a bulk conversion.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		// Run after the settings menu so this item appears below it.
		Hook_Registry::add_action( 'admin_menu', array( $this, 'register_page' ), 12 );
		Hook_Registry::add_action( 'wp_ajax_wzio_bulk_scan', array( $this, 'ajax_scan' ) );
		Hook_Registry::add_action( 'wp_ajax_wzio_bulk_step', array( $this, 'ajax_step' ) );
		Hook_Registry::add_action( 'wp_ajax_wzio_bulk_status', array( $this, 'ajax_status' ) );
		Hook_Registry::add_action( 'wp_ajax_wzio_bulk_reset', array( $this, 'ajax_reset' ) );
	}

	/**
	 * Register the screen under the Media menu.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_page(): void {
		$hook = add_submenu_page(
			'upload.php',
			esc_html__( 'Bulk Optimize Images', 'webberzone-image-optimizer' ),
			esc_html__( 'Bulk Optimize', 'webberzone-image-optimizer' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);

		if ( $hook ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		}
	}

	/**
	 * Enqueue the screen assets.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ): void {
		if ( 'media_page_' . self::SLUG !== $hook_suffix ) {
			return;
		}

		$minimize = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

		wp_enqueue_style(
			'wzio-bulk',
			WZIO_PLUGIN_URL . 'includes/admin/css/bulk' . $minimize . '.css',
			array(),
			WZIO_VERSION
		);

		wp_enqueue_script(
			'wzio-bulk',
			WZIO_PLUGIN_URL . 'includes/admin/js/bulk' . $minimize . '.js',
			array(),
			WZIO_VERSION,
			true
		);

		wp_localize_script(
			'wzio-bulk',
			'wzioBulk',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wzio_bulk' ),
				'strings' => array(
					'scanning'  => esc_html__( 'Building the queue…', 'webberzone-image-optimizer' ),
					'running'   => esc_html__( 'Optimizing…', 'webberzone-image-optimizer' ),
					'paused'    => esc_html__( 'Paused. Nothing is lost — press Start to carry on.', 'webberzone-image-optimizer' ),
					'done'      => esc_html__( 'All done.', 'webberzone-image-optimizer' ),
					'nothing'   => esc_html__( 'Every image is already optimized.', 'webberzone-image-optimizer' ),
					'error'     => esc_html__( 'Something went wrong. The queue is intact; try again.', 'webberzone-image-optimizer' ),
					'busy'      => esc_html__( 'Another process is working through the queue. Waiting…', 'webberzone-image-optimizer' ),
					'confirm'   => esc_html__( 'Clear the queue? Images already optimized stay optimized.', 'webberzone-image-optimizer' ),
					'remaining' => esc_html__( 'remaining', 'webberzone-image-optimizer' ),
					'saved'     => esc_html__( 'saved', 'webberzone-image-optimizer' ),
					/* translators: 1: original total size, 2: percentage saved. */
					'savedOf'   => esc_html__( 'Bandwidth saved of %1$s originally (%2$s%)', 'webberzone-image-optimizer' ),
				),
			)
		);
	}

	/**
	 * Render the screen.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to optimize images.', 'webberzone-image-optimizer' ) );
		}

		Database::maybe_upgrade();

		$stats   = self::get_stats();
		$formats = Converter::get_args()['formats'];

		?>
		<div class="wrap wzio-bulk">
			<h1><?php esc_html_e( 'Bulk Optimize Images', 'webberzone-image-optimizer' ); ?></h1>

		<?php if ( empty( Capabilities::get_supported_formats() ) ) : ?>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'This server cannot encode WebP or AVIF. Ask your host to enable the Imagick extension, or GD compiled with WebP support.', 'webberzone-image-optimizer' ); ?></p>
				</div>
			<?php elseif ( empty( $formats ) ) : ?>
				<div class="notice notice-warning">
					<p>
				<?php
				printf(
				/* translators: %s: settings page URL. */
					wp_kses_post( __( 'No output formats are selected, so there is nothing to generate. <a href="%s">Choose a format</a> first.', 'webberzone-image-optimizer' ) ),
					esc_url( admin_url( 'upload.php?page=wzio-settings' ) )
				);
				?>
					</p>
				</div>
			<?php endif; ?>

			<p class="wzio-lede">
		<?php
		printf(
		/* translators: %s: comma separated list of formats. */
			esc_html__( 'Generating: %s. Your original images are never modified — every optimized copy is written alongside the original.', 'webberzone-image-optimizer' ),
			esc_html( empty( $formats ) ? esc_html__( 'nothing', 'webberzone-image-optimizer' ) : strtoupper( implode( ', ', $formats ) ) )
		);
		?>
			</p>

			<div class="wzio-cards">
				<div class="wzio-card">
					<span class="wzio-card__value" id="wzio-stat-total"><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></span>
					<span class="wzio-card__label"><?php esc_html_e( 'Images in the library', 'webberzone-image-optimizer' ); ?></span>
				</div>
				<div class="wzio-card">
					<span class="wzio-card__value" id="wzio-stat-optimized"><?php echo esc_html( number_format_i18n( $stats['optimized'] ) ); ?></span>
					<span class="wzio-card__label"><?php esc_html_e( 'Already optimized', 'webberzone-image-optimizer' ); ?></span>
				</div>
				<div class="wzio-card">
					<span class="wzio-card__value" id="wzio-stat-remaining"><?php echo esc_html( number_format_i18n( $stats['remaining'] ) ); ?></span>
					<span class="wzio-card__label"><?php esc_html_e( 'Waiting in the queue', 'webberzone-image-optimizer' ); ?></span>
				</div>
				<div class="wzio-card">
					<span class="wzio-card__value" id="wzio-stat-saved"><?php echo esc_html( $stats['saved_human'] ); ?></span>
					<span class="wzio-card__label" id="wzio-stat-saved-label">
			<?php
			if ( $stats['source'] > 0 ) {
				printf(
					/* translators: 1: original total size, 2: percentage saved. */
					esc_html__( 'Bandwidth saved of %1$s originally (%2$s%%)', 'webberzone-image-optimizer' ),
					esc_html( $stats['source_human'] ),
					esc_html( (string) $stats['saved_percent'] )
				);
			} else {
				esc_html_e( 'Bandwidth saved', 'webberzone-image-optimizer' );
			}
			?>
					</span>
				</div>
			</div>

			<div class="wzio-progress" id="wzio-progress" hidden>
				<div class="wzio-progress__bar"><span id="wzio-progress-fill"></span></div>
				<p class="wzio-progress__text" id="wzio-progress-text"></p>
			</div>

			<p class="wzio-actions">
				<button type="button" class="button button-primary" id="wzio-start"><?php esc_html_e( 'Start optimizing', 'webberzone-image-optimizer' ); ?></button>
				<button type="button" class="button" id="wzio-pause" hidden><?php esc_html_e( 'Pause', 'webberzone-image-optimizer' ); ?></button>
				<button type="button" class="button button-link-delete" id="wzio-reset"><?php esc_html_e( 'Clear queue', 'webberzone-image-optimizer' ); ?></button>
				<label class="wzio-force">
					<input type="checkbox" id="wzio-force" />
		<?php esc_html_e( 'Re-optimize images that are already done', 'webberzone-image-optimizer' ); ?>
				</label>
			</p>

		<?php $failures = Queue::get_failures(); ?>
		<?php if ( ! empty( $failures ) ) : ?>
				<h2><?php esc_html_e( 'Images that could not be optimized', 'webberzone-image-optimizer' ); ?></h2>
				<table class="widefat striped wzio-failures">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Image', 'webberzone-image-optimizer' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Reason', 'webberzone-image-optimizer' ); ?></th>
						</tr>
					</thead>
					<tbody>
			<?php foreach ( $failures as $failure ) : ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( (string) get_edit_post_link( (int) $failure->attachment_id ) ); ?>">
				<?php echo esc_html( get_the_title( (int) $failure->attachment_id ) ); ?>
								</a>
							</td>
							<td><?php echo esc_html( $failure->error ); ?></td>
						</tr>
			<?php endforeach; ?>
					</tbody>
				</table>
		<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Collect the numbers shown on the screen.
	 *
	 * @since 1.0.0
	 *
	 * @return array{total: int, optimized: int, remaining: int, saved: int, saved_human: string, source: int, source_human: string, saved_percent: float, done: int} Stats.
	 */
	public static function get_stats(): array {
		$counts = Queue::get_counts();
		$saved  = (int) ( $counts['bytes_saved'] ?? 0 );
		$source = (int) ( $counts['bytes_source'] ?? 0 );

		return array(
			'total'         => Scanner::count_candidates(),
			'optimized'     => Scanner::count_optimized(),
			'remaining'     => Processor::get_remaining(),
			'done'          => (int) $counts[ Queue::DONE ] + (int) $counts[ Queue::SKIPPED ],
			'saved'         => $saved,
			'saved_human'   => Helpers::format_bytes( $saved ),
			'source'        => $source,
			'source_human'  => Helpers::format_bytes( $source ),
			'saved_percent' => $source > 0 ? round( ( $saved / $source ) * 100, 1 ) : 0.0,
		);
	}

	/**
	 * Verify the request before touching anything.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function verify_request(): void {
		check_ajax_referer( 'wzio_bulk', 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to optimize images.', 'webberzone-image-optimizer' ) ), 403 );
		}
	}

	/**
	 * Build the queue.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function ajax_scan(): void {
		$this->verify_request();

		Database::maybe_upgrade();

		// The nonce and capability are both verified in verify_request() above.
     // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$force  = isset( $_POST['force'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['force'] ) );
		$queued = Scanner::enqueue_all( $force );

		wp_send_json_success( array_merge( self::get_stats(), array( 'queued' => $queued ) ) );
	}

	/**
	 * Process one batch.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function ajax_step(): void {
		$this->verify_request();

		$result = Processor::run_batch();

		wp_send_json_success( array_merge( self::get_stats(), $result ) );
	}

	/**
	 * Report the current numbers.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function ajax_status(): void {
		$this->verify_request();

		wp_send_json_success( self::get_stats() );
	}

	/**
	 * Empty the queue.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function ajax_reset(): void {
		$this->verify_request();

		Queue::clear_pending();
		Processor::unschedule();

		wp_send_json_success( self::get_stats() );
	}
}
