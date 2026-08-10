<?php
/**
 * Queue processing.
 *
 * @package WebberZone\Image_Optimizer
 */

namespace WebberZone\Image_Optimizer;

use WebberZone\Image_Optimizer\Util\Hook_Registry;

if ( ! defined( 'WPINC' ) ) {
	exit;
}

/**
 * Processes the conversion queue in bounded batches for all entry points.
 *
 * @since 0.9.0
 */
class Processor {

	/**
	 * Cron hook that advances the queue.
	 *
	 * @since 0.9.0
	 * @var string
	 */
	const CRON_HOOK = 'wzio_process_queue';

	/**
	 * MySQL advisory lock name guarding against two workers running at once.
	 *
	 * @since 0.9.0
	 * @var string
	 */
	const LOCK = 'wzio_processor';

	/**
	 * Wall-clock batch budget, checked between attachments without `set_time_limit()`.
	 *
	 * @since 0.9.0
	 * @var int
	 */
	const MAX_BATCH_SECONDS = 20;

	/**
	 * Constructor.
	 *
	 * @since 0.9.0
	 */
	public function __construct() {
		Hook_Registry::add_action( self::CRON_HOOK, array( __CLASS__, 'run_cron' ) );
		Hook_Registry::add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
	}

	/**
	 * Register the one minute schedule used by the background worker.
	 *
	 * @since 0.9.0
	 *
	 * @param array<string, array<string, mixed>> $schedules Registered schedules.
	 * @return array<string, array<string, mixed>> Schedules.
	 */
	public static function add_cron_schedule( $schedules ) {
		if ( ! isset( $schedules['wzio_minute'] ) ) {
			$schedules['wzio_minute'] = array(
				'interval' => MINUTE_IN_SECONDS,
				'display'  => esc_html__( 'Every minute (WebberZone Image Optimizer)', 'webberzone-image-optimizer' ),
			);
		}

		return $schedules;
	}

	/**
	 * Process one batch, sized from the settings.
	 *
	 * @since 0.9.0
	 *
	 * @param int|null $limit Batch size, or null to read it from the settings.
	 * @return array{processed: int, converted: int, failed: int, skipped: int, saved: int, remaining: int, locked: bool} Result.
	 */
	public static function run_batch( ?int $limit = null ): array {
		$result = array(
			'processed' => 0,
			'converted' => 0,
			'failed'    => 0,
			'skipped'   => 0,
			'saved'     => 0,
			'remaining' => 0,
			'locked'    => false,
		);

		if ( ! self::acquire_lock() ) {
			$result['locked']    = true;
			$result['remaining'] = self::get_remaining();

			return $result;
		}

		try {
			Queue::release_stale();

			$limit = null === $limit ? (int) \wzio_get_option( 'batch_size', 10 ) : $limit;
			$limit = max( 1, min( 200, $limit ) );

			$result_keys = array(
				Queue::DONE    => 'converted',
				Queue::FAILED  => 'failed',
				Queue::SKIPPED => 'skipped',
			);

			// Claim individually so the deadline leaves remaining rows pending.
			$deadline = microtime( true ) + self::MAX_BATCH_SECONDS;

			for ( $claimed = 0; $claimed < $limit; $claimed++ ) {
				if ( microtime( true ) >= $deadline ) {
					break;
				}

				$rows = Queue::claim( 1 );

				if ( empty( $rows ) ) {
					break;
				}

				++$result['processed'];

				$outcome = self::process_row( $rows[0], array( 'force' => false ) );

				++$result[ $result_keys[ $outcome['status'] ] ];
				$result['saved'] += $outcome['saved'];
			}
		} finally {
			self::release_lock();
		}

		$result['remaining'] = self::get_remaining();

		if ( $result['remaining'] > 0 ) {
			self::maybe_schedule();
		} else {
			self::unschedule();
		}

		return $result;
	}

	/**
	 * Convert one attachment outside the batch cadence, for the "Optimize" action.
	 *
	 * @since 0.9.0
	 *
	 * @param  int  $attachment_id Attachment ID.
	 * @param  bool $force         Whether to re-encode files that already have a valid sidecar.
	 * @return array{status: string, saved: int, error: string, locked: bool} Result.
	 */
	public static function process_attachment( int $attachment_id, bool $force = false ): array {
		if ( ! self::acquire_lock() ) {
			return array(
				'status' => '',
				'saved'  => 0,
				'error'  => '',
				'locked' => true,
			);
		}

		try {
			Queue::release_stale();
			// Always requeue: an on-demand click, not a scan.
			Queue::add( array( $attachment_id ), true );

			$row = Queue::claim_attachment( $attachment_id );

			if ( null === $row ) {
				return array(
					'status' => '',
					'saved'  => 0,
					'error'  => '',
					'locked' => true,
				);
			}

			$outcome           = self::process_row( $row, array( 'force' => $force ) );
			$outcome['locked'] = false;

			return $outcome;
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Convert the attachment behind one claimed queue row and record the outcome.
	 *
	 * @since 0.9.0
	 *
	 * @param  object               $row       Claimed queue row.
	 * @param  array<string, mixed> $overrides Conversion argument overrides.
	 * @return array{status: string, saved: int, error: string} Result.
	 */
	private static function process_row( $row, array $overrides = array() ): array {
		$attachment_id = (int) $row->attachment_id;

		// The attachment may have been deleted since it was queued.
		if ( ! Converter::is_convertible_attachment( $attachment_id ) ) {
			Queue::complete( (int) $row->id, Queue::SKIPPED );

			return array(
				'status' => Queue::SKIPPED,
				'saved'  => 0,
				'error'  => '',
			);
		}

		$summary = Converter::convert_attachment( $attachment_id, $overrides );

		if ( is_wp_error( $summary ) ) {
			Queue::complete( (int) $row->id, Queue::FAILED, 0, $summary->get_error_message() );

			return array(
				'status' => Queue::FAILED,
				'saved'  => 0,
				'error'  => $summary->get_error_message(),
			);
		}

		$error = ! empty( $summary['errors'] ) ? (string) reset( $summary['errors'] ) : '';

		if ( '' !== $error && 0 === $summary['converted'] ) {
			Queue::complete( (int) $row->id, Queue::FAILED, 0, $error );

			return array(
				'status' => Queue::FAILED,
				'saved'  => 0,
				'error'  => $error,
			);
		}

		if ( 0 === $summary['converted'] ) {
			Queue::complete( (int) $row->id, Queue::SKIPPED, 0, $error );

			return array(
				'status' => Queue::SKIPPED,
				'saved'  => 0,
				'error'  => $error,
			);
		}

		Queue::complete( (int) $row->id, Queue::DONE, (int) $summary['saved'], $error, (int) $summary['source'] );

		return array(
			'status' => Queue::DONE,
			'saved'  => (int) $summary['saved'],
			'error'  => $error,
		);
	}

	/**
	 * Cron callback.
	 *
	 * @since 0.9.0
	 *
	 * @return void
	 */
	public static function run_cron(): void {
		if ( ! \wzio_get_option( 'background_queue', true ) ) {
			self::unschedule();
			return;
		}

		self::run_batch();
	}

	/**
	 * Number of attachments still waiting.
	 *
	 * @since 0.9.0
	 *
	 * @return int Remaining count.
	 */
	public static function get_remaining(): int {
		$counts = Queue::get_counts();

		return (int) $counts[ Queue::PENDING ] + (int) $counts[ Queue::PROCESSING ];
	}

	/**
	 * Schedule the background worker when there is work and it is enabled.
	 *
	 * @since 0.9.0
	 *
	 * @return void
	 */
	public static function maybe_schedule(): void {
		if ( ! \wzio_get_option( 'background_queue', true ) ) {
			return;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'wzio_minute', self::CRON_HOOK );
		}
	}

	/**
	 * Remove the background worker schedule.
	 *
	 * @since 0.9.0
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );

		while ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
			$timestamp = wp_next_scheduled( self::CRON_HOOK );
		}
	}

	/**
	 * Acquire a connection-scoped MySQL lock without waiting.
	 *
	 * @since 0.9.0
	 *
	 * @return bool True when the lock was acquired.
	 */
	private static function acquire_lock(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', self::LOCK ) );

		return '1' === (string) $result;
	}

	/**
	 * Release the worker lock.
	 *
	 * @since 0.9.0
	 *
	 * @return void
	 */
	private static function release_lock(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', self::LOCK ) );
	}
}
