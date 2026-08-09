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
 * Works through the conversion queue in bounded batches.
 *
 * The same batch routine backs the bulk screen, the background cron and WP-CLI,
 * so all three share one definition of what a unit of work is and cannot
 * disagree about progress.
 *
 * @since 1.0.0
 */
class Processor {

	/**
	 * Cron hook that advances the queue.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const CRON_HOOK = 'wzio_process_queue';

	/**
	 * Transient guarding against two workers running at once.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const LOCK = 'wzio_processor_lock';

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		Hook_Registry::add_action( self::CRON_HOOK, array( __CLASS__, 'run_cron' ) );
		Hook_Registry::add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
	}

	/**
	 * Register the one minute schedule used by the background worker.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
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

			foreach ( Queue::claim( $limit ) as $row ) {
				++$result['processed'];

				$attachment_id = (int) $row->attachment_id;

				// The attachment may have been deleted since it was queued.
				if ( ! Converter::is_convertible_attachment( $attachment_id ) ) {
					Queue::complete( (int) $row->id, Queue::SKIPPED );
					++$result['skipped'];
					continue;
				}

				$summary = Converter::convert_attachment( $attachment_id );

				if ( is_wp_error( $summary ) ) {
					Queue::complete( (int) $row->id, Queue::FAILED, 0, $summary->get_error_message() );
					++$result['failed'];
					continue;
				}

				$error = ! empty( $summary['errors'] ) ? (string) reset( $summary['errors'] ) : '';

				if ( '' !== $error && 0 === $summary['converted'] ) {
					Queue::complete( (int) $row->id, Queue::FAILED, 0, $error );
					++$result['failed'];
					continue;
				}

				if ( 0 === $summary['converted'] ) {
					Queue::complete( (int) $row->id, Queue::SKIPPED, 0, $error );
					++$result['skipped'];
					continue;
				}

				Queue::complete( (int) $row->id, Queue::DONE, (int) $summary['saved'], $error );

				++$result['converted'];
				$result['saved'] += (int) $summary['saved'];
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
	 * Cron callback.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * Take the worker lock.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when the lock was acquired.
	 */
	private static function acquire_lock(): bool {
		if ( get_transient( self::LOCK ) ) {
			return false;
		}

		set_transient( self::LOCK, 1, 10 * MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * Release the worker lock.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private static function release_lock(): void {
		delete_transient( self::LOCK );
	}
}
