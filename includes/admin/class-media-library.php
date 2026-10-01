<?php
/**
 * Media library integration.
 *
 * @package WebberZone\Image_Optimizer\Admin
 */

namespace WebberZone\Image_Optimizer\Admin;

use WebberZone\Image_Optimizer\Attachment_Meta;
use WebberZone\Image_Optimizer\Original_Backups;
use WebberZone\Image_Optimizer\Original_Optimizer;
use WebberZone\Image_Optimizer\Converter;
use WebberZone\Image_Optimizer\Capabilities;
use WebberZone\Image_Optimizer\Database;
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
 * @since 1.0.0
 */
class Media_Library {

	/**
	 * Query-string key for the optimization status filter.
	 *
	 * @since 1.1.0
	 * @var   string
	 */
	private const STATUS_FILTER = 'wzio_optimization_status';

	/**
	 * Internal query variable for the requested optimization status.
	 *
	 * @since 1.1.0
	 * @var   string
	 */
	private const STATUS_QUERY_VAR = 'wzio_filter_status';

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		Hook_Registry::add_filter( 'manage_media_columns', array( $this, 'add_column' ) );
		Hook_Registry::add_action( 'manage_media_custom_column', array( $this, 'render_column' ), 10, 2 );
		Hook_Registry::add_filter( 'media_row_actions', array( $this, 'add_row_actions' ), 10, 2 );
		Hook_Registry::add_filter( 'bulk_actions-upload', array( $this, 'add_bulk_actions' ) );
		Hook_Registry::add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk_restore' ), 10, 3 );
		Hook_Registry::add_action( 'restrict_manage_posts', array( $this, 'render_status_filter' ), 10, 2 );
		Hook_Registry::add_action( 'pre_get_posts', array( $this, 'filter_by_status' ) );
		Hook_Registry::add_filter( 'posts_where', array( $this, 'filter_attachments_by_status' ), 10, 2 );
		Hook_Registry::add_action( 'admin_post_wzio_optimize_attachment', array( $this, 'handle_optimize' ) );
		Hook_Registry::add_action( 'admin_post_wzio_restore_originals', array( $this, 'handle_restore_originals' ) );
		Hook_Registry::add_action( 'admin_post_wzio_restore_attachment', array( $this, 'handle_restore' ) );
		Hook_Registry::add_action( 'admin_post_wzio_retry_attachment', array( $this, 'handle_retry' ) );
		Hook_Registry::add_action( 'wp_ajax_wzio_optimize_attachment', array( $this, 'ajax_optimize' ) );
		Hook_Registry::add_action( 'admin_notices', array( $this, 'render_notice' ) );
		Hook_Registry::add_action( 'attachment_submitbox_misc_actions', array( $this, 'render_submitbox' ), 20 );
		Hook_Registry::add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue the script that drives the "Optimize" action over AJAX.
	 *
	 * @since 1.0.0
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

		wp_enqueue_style(
			'wzio-media',
			WZIO_PLUGIN_URL . 'includes/admin/css/media' . $minimize . '.css',
			array(),
			WZIO_VERSION
		);

		add_thickbox();
	}

	/**
	 * Confirm the outcome of a per-image action.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_notice(): void {
		$screen = get_current_screen();
		if ( $screen && current_user_can( 'manage_options' ) && \wzio_get_option( 'compress_originals', false ) && ( 'upload' === $screen->id || false !== strpos( $screen->id, 'wzio' ) ) ) {
			$plugins = array_merge( (array) get_option( 'active_plugins', array() ), array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
			foreach ( $plugins as $plugin ) {
				if ( preg_match( '~^(tiny-compress-images|shortpixel-image-optimiser|imagify|ewww-image-optimizer|wp-smushit)/~', $plugin ) ) {
					echo '<div class="notice notice-warning"><p>' . esc_html__( 'Another image optimizer is active. Enable original compression in only one optimizer to avoid repeated quality loss.', 'webberzone-image-optimizer' ) . '</p></div>';
					break;
				}
			}
			$encoders = Capabilities::get_originals();
			if ( ! $encoders['jpeg'] || ! $encoders['png'] ) {
				printf( '<div class="notice notice-info"><p>%s</p></div>', esc_html( sprintf( __( 'Original compression availability: JPEG %1$s; PNG %2$s. Unsupported originals are left unchanged.', 'webberzone-image-optimizer' ), $encoders['jpeg'] ? __( 'available', 'webberzone-image-optimizer' ) : __( 'unavailable', 'webberzone-image-optimizer' ), $encoders['png'] ? __( 'available', 'webberzone-image-optimizer' ) : __( 'unavailable', 'webberzone-image-optimizer' ) ) ) );
			}
		}

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
			'originals_restored' => array( 'success', __( 'Original files and dimensions restored. Modern copies will be rebuilt without recompressing originals.', 'webberzone-image-optimizer' ) ),
			'optimized'          => array( 'success', __( 'Image optimization completed.', 'webberzone-image-optimizer' ) ),
			'restored'           => array( 'success', __( 'Optimized copies deleted. The original image is untouched and is being served again.', 'webberzone-image-optimizer' ) ),
			'failed'             => array( 'error', __( 'That image could not be optimized. Check the Bulk Optimize screen for the reason.', 'webberzone-image-optimizer' ) ),
			'busy'               => array( 'warning', __( 'Another optimization run is in progress, so this image was left alone. Try again in a moment.', 'webberzone-image-optimizer' ) ),
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
	 * @since 1.0.0
	 *
	 * @param  array<string, string> $columns Existing columns.
	 * @return array<string, string> Columns.
	 */
	public function add_column( $columns ) {
		$columns['wzio'] = esc_html__( 'Optimized', 'webberzone-image-optimizer' );

		return $columns;
	}

	/**
	 * Add the optimization status filter to the media list table.
	 *
	 * @since 1.1.0
	 *
	 * @param string $post_type Current post type.
	 * @param string $which     Location of the filter controls.
	 * @return void
	 */
	public function render_status_filter( $post_type, $which ): void {
		if ( 'attachment' !== $post_type || 'bar' !== $which || ! self::can_view_status_filter() ) {
			return;
		}

		$selected = self::get_requested_status();
		$options  = array(
			''            => __( 'All optimization statuses', 'webberzone-image-optimizer' ),
			'optimized'   => __( 'Optimized', 'webberzone-image-optimizer' ),
			'unoptimized' => __( 'Not yet optimized', 'webberzone-image-optimizer' ),
			'skipped'     => __( 'Skipped', 'webberzone-image-optimizer' ),
			'failed'      => __( 'Failed', 'webberzone-image-optimizer' ),
		);
		?>
		<label for="wzio-optimization-status" class="screen-reader-text">
			<?php esc_html_e( 'Filter by optimization status', 'webberzone-image-optimizer' ); ?>
		</label>
		<select name="<?php echo esc_attr( self::STATUS_FILTER ); ?>" id="wzio-optimization-status">
			<?php foreach ( $options as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $selected, $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Filter the media library query by optimization status.
	 *
	 * @since 1.1.0
	 *
	 * @param \WP_Query $query Current query.
	 * @return void
	 */
	public function filter_by_status( $query ): void {
		global $pagenow;

		if (
			! is_admin()
			|| 'upload.php' !== $pagenow
			|| ! $query->is_main_query()
			|| 'attachment' !== $query->get( 'post_type' )
			|| ! current_user_can( 'upload_files' )
		) {
			return;
		}

		$status = self::get_requested_status();

		if ( '' !== $status ) {
			$query->set( self::STATUS_QUERY_VAR, $status );
		}
	}

	/**
	 * Limit a flagged media query to eligible attachments in the requested queue state.
	 *
	 * @since 1.1.0
	 *
	 * @param string    $where Current WHERE clause.
	 * @param \WP_Query $query Current query.
	 * @return string Filtered WHERE clause.
	 */
	public function filter_attachments_by_status( $where, $query ) {
		global $wpdb;

		$status = $query->get( self::STATUS_QUERY_VAR );

		if ( ! is_string( $status ) || ! in_array( $status, array( 'optimized', 'unoptimized', 'skipped', 'failed' ), true ) ) {
			return $where;
		}

		$mimes             = Helpers::SOURCE_MIME_TYPES;
		$mime_placeholders = implode( ',', array_fill( 0, count( $mimes ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$where .= $wpdb->prepare(
			" AND %i.post_mime_type IN ({$mime_placeholders})",
			array_merge( array( $wpdb->posts ), $mimes )
		);

		if ( 'unoptimized' === $status ) {
			$terminal_statuses = array( Queue::DONE, Queue::SKIPPED, Queue::FAILED );
			$placeholders      = implode( ',', array_fill( 0, count( $terminal_statuses ), '%s' ) );

			return $where . $wpdb->prepare(
				" AND %i.ID NOT IN (SELECT attachment_id FROM %i WHERE status IN ({$placeholders}))",
				array_merge( array( $wpdb->posts, Database::get_table() ), $terminal_statuses )
			);
		}

		$queue_status = array(
			'optimized' => Queue::DONE,
			'skipped'   => Queue::SKIPPED,
			'failed'    => Queue::FAILED,
		)[ $status ];

		return $where . $wpdb->prepare(
			' AND %i.ID IN (SELECT attachment_id FROM %i WHERE status = %s)',
			$wpdb->posts,
			Database::get_table(),
			$queue_status
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/**
	 * Whether the current user can use the status filter on this screen.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True when the optimization column is visible.
	 */
	private static function can_view_status_filter(): bool {
		$screen = get_current_screen();

		return current_user_can( 'upload_files' )
			&& $screen
			&& 'upload' === $screen->id
			&& ! in_array( 'wzio', get_hidden_columns( $screen ), true );
	}

	/**
	 * Get a valid requested optimization status.
	 *
	 * @since 1.1.0
	 *
	 * @return string Status, or an empty string when none is selected.
	 */
	private static function get_requested_status(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET[ self::STATUS_FILTER ] ) && is_string( $_GET[ self::STATUS_FILTER ] )
			? sanitize_key( wp_unslash( $_GET[ self::STATUS_FILTER ] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return in_array( $status, array( 'optimized', 'unoptimized', 'skipped', 'failed' ), true ) ? $status : '';
	}

	/**
	 * Render the status column.
	 *
	 * @since 1.0.0
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

		self::render_summary( $post_id );
	}

	/**
	 * Render the per-format summary, savings and details link for an attachment.
	 *
	 * @since 1.1.2
	 *
	 * @param  int $attachment_id Attachment ID.
	 * @return void
	 */
	private static function render_summary( int $attachment_id ): void {
		$originals       = Original_Backups::get( $attachment_id );
		$original_totals = Original_Backups::totals( $originals );
		if ( $original_totals['compressed'] ) {
			printf( '<span class="wzio-summary-line">%s</span>', esc_html( sprintf( _n( '%d size compressed', '%d sizes compressed', $original_totals['compressed'], 'webberzone-image-optimizer' ), $original_totals['compressed'] ) ) );
			foreach ( $originals as $entry ) {
				if ( ! empty( $entry['resized'] ) ) {
					printf( '<span class="wzio-summary-line">%s</span>', esc_html( implode( '×', $entry['resized']['from'] ) . ' → ' . implode( '×', $entry['resized']['to'] ) ) );
				}
			}
		}

		$totals = Attachment_Meta::get_totals( $attachment_id );
		$record = Attachment_Meta::get( $attachment_id );

		if ( 0 === $totals['files'] ) {
			$status = Queue::get_status( $attachment_id );

			if ( Queue::FAILED === $status ) {
				echo '<span class="wzio-status-failed">' . esc_html__( 'Failed', 'webberzone-image-optimizer' ) . '</span>';
			} elseif ( Queue::PENDING === $status || Queue::PROCESSING === $status ) {
				esc_html_e( 'Queued', 'webberzone-image-optimizer' );
			} elseif ( ! empty( $record['files'] ) ) {
				esc_html_e( 'Original kept: no copy came out smaller', 'webberzone-image-optimizer' );
			} else {
				esc_html_e( 'Not yet optimized', 'webberzone-image-optimizer' );
			}

			if ( ! empty( $record['files'] ) ) {
				echo '<br />';
				self::render_details( $attachment_id, $record, $totals );
			}

			return;
		}

		$percent = $totals['source'] > 0 ? round( ( $totals['saved'] / $totals['source'] ) * 100 ) : 0;

		echo '<div class="wzio-summary"><span class="dashicons dashicons-yes" aria-hidden="true"></span>';

		foreach ( self::get_format_counts( $record ) as $format => $count ) {
			printf(
				'<span class="wzio-summary-line">%s</span>',
				wp_kses(
					sprintf(
						/* translators: 1: number of image sizes, 2: format name such as WebP. */
						_n( '%1$s size converted to %2$s', '%1$s sizes converted to %2$s', $count, 'webberzone-image-optimizer' ),
						'<strong>' . (int) $count . '</strong>',
						esc_html( self::get_format_label( $format ) )
					),
					array( 'strong' => array() )
				)
			);
		}

		printf(
			'<span class="wzio-summary-line">%s</span>',
			esc_html(
				sprintf(
					/* translators: %d: percentage saved. */
					__( 'Total savings %d%%', 'webberzone-image-optimizer' ),
					(int) $percent
				)
			)
		);

		self::render_reduced_note( (int) $totals['reduced'], '<span class="wzio-reduced-note">%s</span>' );

		self::render_details( $attachment_id, $record, $totals );

		echo '</div>';
	}

	/**
	 * Count the converted files per target format.
	 *
	 * @since 1.1.2
	 *
	 * @param  array{files: array<string, array<string, mixed>>} $record Conversion record.
	 * @return array<string, int> Format to number of converted files, only formats with at least one.
	 */
	private static function get_format_counts( array $record ): array {
		$counts = array();

		foreach ( Helpers::get_formats() as $format ) {
			foreach ( $record['files'] as $file_record ) {
				if ( Attachment_Meta::is_converted( $file_record, $format ) ) {
					$counts[ $format ] = ( $counts[ $format ] ?? 0 ) + 1;
				}
			}
		}

		return $counts;
	}

	/**
	 * Human-readable name for a target format.
	 *
	 * @since 1.1.2
	 *
	 * @param  string $format Format slug.
	 * @return string Label.
	 */
	private static function get_format_label( string $format ): string {
		$labels = array(
			'webp' => 'WebP',
			'avif' => 'AVIF',
		);

		return $labels[ $format ] ?? strtoupper( $format );
	}

	/**
	 * Render the "Details" link and the hidden per-size table it opens in a Thickbox.
	 *
	 * @since 1.1.2
	 *
	 * @param  int                                                                         $attachment_id Attachment ID.
	 * @param  array{updated: int, files: array<string, array<string, mixed>>}             $record        Conversion record.
	 * @param  array{source: int, converted: int, saved: int, formats: array<string, int>} $totals        Totals.
	 * @return void
	 */
	private static function render_details( int $attachment_id, array $record, array $totals ): void {
		$box_id = 'wzio-details-' . $attachment_id;
		$title  = sprintf(
			/* translators: %s: file name. */
			__( 'Optimization details for %s', 'webberzone-image-optimizer' ),
			wp_basename( (string) get_attached_file( $attachment_id ) )
		);
		$formats = array();

		// Only show formats that were attempted, so a disabled format adds no empty column.
		foreach ( Helpers::get_formats() as $format ) {
			foreach ( $record['files'] as $file_record ) {
				if ( isset( $file_record[ $format ] ) ) {
					$formats[] = $format;
					break;
				}
			}
		}

		printf(
			'<a href="%1$s" class="thickbox wzio-details-link" title="%2$s">%3$s</a>',
			esc_url( '#TB_inline?width=760&height=560&inlineId=' . $box_id ),
			esc_attr( $title ),
			esc_html__( 'Details', 'webberzone-image-optimizer' )
		);

		$enabled_sizes = Converter::get_enabled_sizes();
		$source_total  = 0;

		foreach ( $record['files'] as $file_record ) {
			$source_total += (int) ( $file_record['size'] ?? 0 );
		}
		?>
		<div id="<?php echo esc_attr( $box_id ); ?>" hidden>
			<div class="wzio-details">
				<div class="wzio-details-table-wrap">
					<table class="widefat striped">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Size', 'webberzone-image-optimizer' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Dimensions', 'webberzone-image-optimizer' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Original', 'webberzone-image-optimizer' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Compressed', 'webberzone-image-optimizer' ); ?></th>
								<?php foreach ( $formats as $format ) : ?>
									<th scope="col"><?php echo esc_html( self::get_format_label( $format ) ); ?></th>
								<?php endforeach; ?>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( self::get_size_rows( $attachment_id ) as $row ) : ?>
								<?php $file_record = $record['files'][ $row['file'] ] ?? array(); ?>
								<tr>
									<td><?php echo esc_html( $row['label'] ); ?></td>
									<td><?php echo esc_html( $row['dimensions'] ); ?></td>
									<?php if ( empty( $file_record ) ) : ?>
										<td class="wzio-muted" colspan="<?php echo (int) ( count( $formats ) + 2 ); ?>">
											<?php
											echo ( '' !== $row['size'] && null !== $enabled_sizes && ! in_array( $row['size'], $enabled_sizes, true ) )
												? esc_html__( 'Size not selected in settings', 'webberzone-image-optimizer' )
												: esc_html__( 'Not converted', 'webberzone-image-optimizer' );
											?>
										</td>
									<?php else : ?>
										<?php $source = (int) ( $file_record['size'] ?? 0 ); ?>
										<td><?php echo $source > 0 ? esc_html( Helpers::format_bytes( $source ) ) : '&#8212;'; ?></td>
									<td><?php echo esc_html( self::original_label( (array) ( $file_record['original'] ?? array() ) ) ); ?></td>
										<?php foreach ( $formats as $format ) : ?>
											<?php self::render_format_cell( $file_record, $format, $source ); ?>
										<?php endforeach; ?>
									<?php endif; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
						<tfoot>
							<tr>
								<td colspan="2"><?php esc_html_e( 'Combined', 'webberzone-image-optimizer' ); ?></td>
								<td><?php echo esc_html( Helpers::format_bytes( $source_total ) ); ?></td>
								<td>&#8212;</td>
								<?php foreach ( $formats as $format ) : ?>
									<td><?php echo isset( $totals['formats'][ $format ] ) ? esc_html( Helpers::format_bytes( (int) $totals['formats'][ $format ] ) ) : '&#8212;'; ?></td>
								<?php endforeach; ?>
							</tr>
						</tfoot>
					</table>
				</div>
				<?php if ( $totals['source'] > 0 ) : ?>
					<p class="wzio-details-total">
						<?php
						printf(
							/* translators: 1: percentage saved, 2: bytes saved. */
							esc_html__( 'Total savings %1$d%% (%2$s)', 'webberzone-image-optimizer' ),
							(int) round( ( $totals['saved'] / $totals['source'] ) * 100 ),
							esc_html( Helpers::format_bytes( $totals['saved'] ) )
						);
						?>
					</p>
				<?php endif; ?>
				<?php if ( $record['updated'] > 0 ) : ?>
					<p class="wzio-details-updated">
						<?php
						printf(
							/* translators: %s: human-readable time difference, such as "7 minutes". */
							esc_html__( 'Last optimized %s ago.', 'webberzone-image-optimizer' ),
							esc_html( human_time_diff( (int) $record['updated'] ) )
						);
						?>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render one format's cell in the details table.
	 *
	 * @since 1.1.2
	 *
	 * @param  array<string, mixed> $file_record File record.
	 * @param  string               $format      Format slug.
	 * @param  int                  $source      Source file size in bytes.
	 * @return void
	 */
	private static function render_format_cell( array $file_record, string $format, int $source ): void {
		if ( Attachment_Meta::is_converted( $file_record, $format ) ) {
			$bytes = (int) $file_record[ $format ]['bytes'];
			$label = Helpers::format_bytes( $bytes );

			if ( $source > 0 ) {
				/* translators: 1: file size, 2: percentage saved. */
				$label = sprintf( __( '%1$s (−%2$d%%)', 'webberzone-image-optimizer' ), $label, (int) round( ( max( 0, $source - $bytes ) / $source ) * 100 ) );
			}

			echo '<td>' . esc_html( $label ) . '</td>';
			return;
		}

		$entry = (array) ( $file_record[ $format ] ?? array() );

		if ( isset( $entry['error'] ) ) {
			printf(
				'<td class="wzio-status-failed" title="%1$s">%2$s</td>',
				esc_attr( (string) $entry['error'] ),
				esc_html__( 'Failed', 'webberzone-image-optimizer' )
			);
			return;
		}

		$reasons = array(
			'larger'      => __( 'Larger than original', 'webberzone-image-optimizer' ),
			'animated'    => __( 'Animated, skipped', 'webberzone-image-optimizer' ),
			'unsupported' => __( 'Not supported', 'webberzone-image-optimizer' ),
			'occupied'    => __( 'Name already in use', 'webberzone-image-optimizer' ),
		);
		$skip    = (string) ( $entry['skip'] ?? '' );

		printf(
			'<td class="wzio-muted">%s</td>',
			isset( $reasons[ $skip ] ) ? esc_html( $reasons[ $skip ] ) : '&#8212;'
		);
	}

	/**
	 * List the original and each registered size of an attachment for the details table.
	 *
	 * @since 1.1.2
	 *
	 * @param  int $attachment_id Attachment ID.
	 * @return array<int, array{label: string, size: string, file: string, dimensions: string}> Rows.
	 */
	private static function get_size_rows( int $attachment_id ): array {
		$meta = wp_get_attachment_metadata( $attachment_id );
		$meta = is_array( $meta ) ? $meta : array();
		$main = (string) get_attached_file( $attachment_id );
		$rows = array();

		/** This filter is documented in wp-admin/includes/media.php */
		$names = (array) apply_filters(
			'image_size_names_choose',
			array(
				'thumbnail' => __( 'Thumbnail' ), // phpcs:ignore WordPress.WP.I18n.MissingArgDomain
				'medium'    => __( 'Medium' ), // phpcs:ignore WordPress.WP.I18n.MissingArgDomain
				'large'     => __( 'Large' ), // phpcs:ignore WordPress.WP.I18n.MissingArgDomain
			)
		);

		if ( '' !== $main ) {
			$rows[] = array(
				'label'      => __( 'Original', 'webberzone-image-optimizer' ),
				'size'       => '',
				'file'       => wp_basename( $main ),
				'dimensions' => self::format_dimensions( $meta ),
			);
		}

		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size_name => $size ) {
			if ( ! is_array( $size ) || empty( $size['file'] ) ) {
				continue;
			}

			$rows[] = array(
				'label'      => isset( $names[ $size_name ] ) ? (string) $names[ $size_name ] : (string) $size_name,
				'size'       => (string) $size_name,
				'file'       => wp_basename( (string) $size['file'] ),
				'dimensions' => self::format_dimensions( $size ),
			);
		}

		return $rows;
	}

	/**
	 * Format width and height from image metadata.
	 *
	 * @since 1.1.2
	 *
	 * @param  array<string, mixed> $data Metadata holding width and height.
	 * @return string Dimensions, or an em dash when unknown.
	 */
	private static function format_dimensions( array $data ): string {
		$width  = (int) ( $data['width'] ?? 0 );
		$height = (int) ( $data['height'] ?? 0 );

		return ( $width > 0 && $height > 0 ) ? $width . '×' . $height : '—';
	}

	/**
	 * Say how many copies had to drop below the configured quality to beat the original.
	 *
	 * @since 1.1.0
	 *
	 * @param  int    $reduced Number of copies re-encoded at a lower quality.
	 * @param  string $wrapper printf wrapper holding a single %s for the escaped text.
	 * @return void
	 */
	private static function render_reduced_note( int $reduced, string $wrapper ): void {
		if ( $reduced < 1 ) {
			return;
		}

		$text = sprintf(
			/* translators: %d: number of files. */
			_n(
				'%d copy needed a lower quality to come out smaller than the original.',
				'%d copies needed a lower quality to come out smaller than the original.',
				$reduced,
				'webberzone-image-optimizer'
			),
			$reduced
		);

		printf( $wrapper, esc_html( $text ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Add the per-image actions.
	 *
	 * @since 1.0.0
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

		if ( Original_Backups::get( (int) $post->ID ) ) {
			$actions['wzio_restore_originals'] = sprintf( '<a href="%s">%s</a>', esc_url( self::get_action_url( 'wzio_restore_originals', (int) $post->ID ) ), esc_html__( 'Restore originals', 'webberzone-image-optimizer' ) );
		}
		$record = Attachment_Meta::get( (int) $post->ID );

		if ( self::can_retry( (int) $post->ID, $record ) ) {
			$actions['wzio_retry'] = self::get_retry_link( (int) $post->ID );
		}

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
	 * @since 1.0.0
	 *
	 * @param  array<string, string> $actions Existing bulk actions.
	 * @return array<string, string> Bulk actions.
	 */
	public function add_bulk_actions( $actions ) {
		$actions['wzio_restore_originals'] = esc_html__( 'Restore originals', 'webberzone-image-optimizer' );
		$actions['wzio_restore']           = esc_html__( 'Delete optimized copies', 'webberzone-image-optimizer' );

		return $actions;
	}

	/**
	 * Handle deletion after WordPress verifies the bulk action.
	 *
	 * @since 1.0.0
	 *
	 * @param  string          $redirect_to Redirect URL.
	 * @param  string          $doaction    Bulk action name.
	 * @param  array<int, int> $post_ids  Selected attachment IDs.
	 * @return string Redirect URL.
	 */
	public function handle_bulk_restore( $redirect_to, $doaction, $post_ids ) {
		if ( 'wzio_restore_originals' === $doaction ) {
			check_admin_referer( 'bulk-media' );
			$failed = false;
			foreach ( $post_ids as $post_id ) {
				$post_id = (int) $post_id;
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					$failed = true;
					continue;
				}
				if ( Original_Backups::get( $post_id ) && is_wp_error( Original_Optimizer::restore( $post_id ) ) ) {
					$failed = true;
				}
			}
			return add_query_arg( 'wzio_message', $failed ? 'failed' : 'originals_restored', $redirect_to );
		}
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
	 * @since 1.0.0
	 *
	 * @param  \WP_Post $post Attachment.
	 * @return void
	 */
	public function render_submitbox( $post ): void {
		$attachment_id = (int) $post->ID;

		if ( ! Converter::is_convertible_attachment( $attachment_id ) ) {
			return;
		}

		$record = Attachment_Meta::get( $attachment_id );

		echo '<div class="misc-pub-section misc-pub-wzio">';
		echo '<strong>' . esc_html__( 'Image optimization', 'webberzone-image-optimizer' ) . '</strong><br />';

		self::render_summary( $attachment_id );

		echo '<p class="wzio-submitbox-actions">';
		if ( Original_Backups::get( $attachment_id ) && current_user_can( 'edit_post', $attachment_id ) ) {
			printf( '<a href="%s">%s</a> | ', esc_url( self::get_action_url( 'wzio_restore_originals', $attachment_id ) ), esc_html__( 'Restore originals', 'webberzone-image-optimizer' ) );
		}
		printf(
			'<a href="%s" class="wzio-optimize-attachment" data-id="%d">%s</a>',
			esc_url( self::get_action_url( 'wzio_optimize_attachment', $attachment_id ) ),
			(int) $attachment_id,
			esc_html__( 'Optimize', 'webberzone-image-optimizer' )
		);

		if ( self::can_retry( $attachment_id, $record ) ) {
			echo ' | ' . self::get_retry_link( $attachment_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

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
	 * Whether an attachment has skipped or failed copies worth another attempt.
	 *
	 * @since 1.1.2
	 *
	 * @param  int                                               $attachment_id Attachment ID.
	 * @param  array{files: array<string, array<string, mixed>>} $record        Conversion record.
	 * @return bool True when a retry is offered.
	 */
	private static function can_retry( int $attachment_id, array $record ): bool {
		return Attachment_Meta::count_retryable( $record ) > 0 || Queue::FAILED === Queue::get_status( $attachment_id );
	}

	/**
	 * Build the Retry link, which the AJAX script runs with a retry flag.
	 *
	 * @since 1.1.2
	 *
	 * @param  int $attachment_id Attachment ID.
	 * @return string Link markup.
	 */
	private static function get_retry_link( int $attachment_id ): string {
		return sprintf(
			'<a href="%1$s" class="wzio-optimize-attachment" data-id="%2$d" data-retry="1">%3$s</a>',
			esc_url( self::get_action_url( 'wzio_retry_attachment', $attachment_id ) ),
			$attachment_id,
			esc_html__( 'Retry', 'webberzone-image-optimizer' )
		);
	}

	/**
	 * Build a nonced admin-post URL for an attachment action.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * Convert one attachment as the no-JS fallback.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_optimize(): void {
		$attachment_id = $this->validate_action( 'wzio_optimize_attachment' );

		delete_post_meta( $attachment_id, '_wzio_originals_restored' );
		$outcome = Processor::process_attachment( $attachment_id, false );

		if ( $outcome['locked'] ) {
			$this->redirect_back( 'busy' );
		}

		$this->redirect_back( Queue::FAILED === $outcome['status'] ? 'failed' : 'optimized' );
	}

	/**
	 * Retry skipped and failed copies of one attachment as the no-JS fallback.
	 *
	 * @since 1.1.2
	 *
	 * @return void
	 */
	public function handle_retry(): void {
		$attachment_id = $this->validate_action( 'wzio_retry_attachment' );

		Attachment_Meta::clear_retryable( $attachment_id );

		$outcome = Processor::process_attachment( $attachment_id, false );

		if ( $outcome['locked'] ) {
			$this->redirect_back( 'busy' );
		}

		$this->redirect_back( Queue::FAILED === $outcome['status'] ? 'failed' : 'optimized' );
	}

	/**
	 * Convert the next file of an attachment over AJAX, one file per call.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function ajax_optimize(): void {
		check_ajax_referer( 'wzio_optimize', 'nonce' );

		$attachment_id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;

		if ( ! current_user_can( 'edit_post', $attachment_id ) || ! Converter::is_convertible_attachment( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to optimize this image.', 'webberzone-image-optimizer' ) ), 403 );
		}

		$explicit = false;

		if ( Queue::PROCESSING !== Queue::get_status( $attachment_id ) ) {
			$explicit = true;
			delete_post_meta( $attachment_id, '_wzio_originals_restored' );
			if ( ! empty( $_POST['retry'] ) ) {
				Attachment_Meta::clear_retryable( $attachment_id );
			}

			Queue::add( array( $attachment_id ), true );

			if ( null === Queue::claim_attachment( $attachment_id ) ) {
				wp_send_json_error( array( 'message' => __( 'Another optimization is already running. Try again in a moment.', 'webberzone-image-optimizer' ) ), 409 );
			}
		}

		$step = Converter::convert_next_file( $attachment_id, $explicit ? array( 'originals_explicit' => true ) : array() );

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
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_restore(): void {
		$attachment_id = $this->validate_action( 'wzio_restore_attachment' );

		Converter::delete_sidecars( $attachment_id );

		$this->redirect_back( 'restored' );
	}

	/**
	 * Redirect to the referring admin page.
	 *
	 * @since 1.0.0
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
	/**
	 * Restore originals for one authorized attachment.
	 *
	 * @return void
	 */
	public function handle_restore_originals(): void {
		$id     = $this->validate_action( 'wzio_restore_originals' );
		$result = Original_Optimizer::restore( $id );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}
		$this->redirect_back( 'originals_restored' );
	}

	/**
	 * Format original-file compression and resize details.
	 *
	 * @param array $entry Original result.
	 * @return string Label.
	 */
	private static function original_label( array $entry ): string {
		if ( isset( $entry['error'] ) ) {
			return (string) $entry['error'];
		}
		if ( ! isset( $entry['after'], $entry['before'] ) ) {
			$reasons = array(
				'quality'     => __( 'Already at target quality', 'webberzone-image-optimizer' ),
				'larger'      => __( 'Minimum saving not met', 'webberzone-image-optimizer' ),
				'unsupported' => __( 'Compression unavailable', 'webberzone-image-optimizer' ),
			);
			return $reasons[ $entry['skip'] ?? '' ] ?? '—';
		}
		$label = Helpers::format_bytes( (int) $entry['before'] ) . ' → ' . Helpers::format_bytes( (int) $entry['after'] );
		if ( (int) $entry['before'] > 0 ) {
			$label .= ' (−' . (int) round( 100 * max( 0, $entry['before'] - $entry['after'] ) / $entry['before'] ) . '%)';
		}
		if ( ! empty( $entry['resized'] ) ) {
			$label .= ' (' . implode( '×', $entry['resized']['from'] ) . ' → ' . implode( '×', $entry['resized']['to'] ) . ')';
		}
		return $label;
	}
}
