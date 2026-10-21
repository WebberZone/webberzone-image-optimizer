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
	 * MySQL advisory lock name guarding against two workers running at once.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const LOCK = 'wzio_processor';

	/**
	 * Wall-clock batch budget, checked between attachments without `set_time_limit()`.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const MAX_BATCH_SECONDS = 20;

	/**
	 * PHP error types that end the request.
	 *
	 * @since 1.3.0
	 * @var int
	 */
	const FATAL_ERRORS = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

	/**
	 * Queue row being converted, so a fatal error can be recorded against it.
	 *
	 * @since 1.3.0
	 * @var int
	 */
	private static $current_row = 0;

	/**
	 * Whether the fatal error handlers are registered for this request.
	 *
	 * @since 1.3.0
	 * @var bool
	 */
	private static $fatal_handlers = false;

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
	 * @return array{processed: int, converted: int, failed: int, skipped: int, deferred: int, saved: int, remaining: int, locked: bool} Result.
	 */
	public static function run_batch( ?int $limit = null ): array {
		// Cron can run before any admin request has applied the schema upgrade.
		Database::maybe_upgrade();

		$result = array(
			'processed' => 0,
			'converted' => 0,
			'failed'    => 0,
			'skipped'   => 0,
			'deferred'  => 0,
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
				Queue::PENDING => 'deferred',
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

				$outcome = self::process_row(
					$rows[0],
					array(
						'force'    => false,
						'deadline' => $deadline,
					)
				);

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

			if ( $result['processed'] > 0 ) {
				Scanner::flush_counts();
			}
		}

		return $result;
	}

	/**
	 * Convert one attachment outside the batch cadence, for the "Optimize" action.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
	 *
	 * @param  object               $row       Claimed queue row.
	 * @param  array<string, mixed> $overrides Conversion argument overrides.
	 * @return array{status: string, saved: int, error: string} Result.
	 */
	private static function process_row( $row, array $overrides = array() ): array {
		$attachment_id = (int) $row->attachment_id;

		if ( 2 === (int) ( $row->reencode ?? 0 ) ) {
			$overrides['outdated'] = true;
		} elseif ( ! empty( $row->reencode ) ) {
			$overrides['force'] = true;
		}

		// The attachment may have been deleted since it was queued.
		if ( ! Converter::is_convertible_attachment( $attachment_id ) ) {
			Queue::complete( (int) $row->id, Queue::SKIPPED );

			return array(
				'status' => Queue::SKIPPED,
				'saved'  => 0,
				'error'  => '',
			);
		}

		self::watch_fatal_errors( (int) $row->id );

		try {
			$summary = Converter::convert_attachment( $attachment_id, $overrides );
		} catch ( \Throwable $e ) {
			// A driver exception would otherwise end the request as a fatal error.
			$summary = new \WP_Error( 'wzio_exception', $e->getMessage() );
		}

		self::unwatch_fatal_errors();

		if ( is_wp_error( $summary ) ) {
			Queue::complete( (int) $row->id, Queue::FAILED, 0, $summary->get_error_message() );

			return array(
				'status' => Queue::FAILED,
				'saved'  => 0,
				'error'  => $summary->get_error_message(),
			);
		}

		// The worker ran out of time part way through, so the row waits rather than
		// reporting an outcome it has not reached yet.
		if ( empty( $summary['complete'] ) ) {
			Queue::defer( (int) $row->id );

			return array(
				'status' => Queue::PENDING,
				'saved'  => 0,
				'error'  => '',
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
	 * Record a fatal error against the row being converted.
	 *
	 * A memory or time limit kills the request mid-image. Without this the row
	 * stays claimed until it goes stale, and the admin never learns why.
	 *
	 * @since 1.3.0
	 *
	 * @param  int $row_id Queue row ID.
	 * @return void
	 */
	public static function watch_fatal_errors( int $row_id ): void {
		self::$current_row = $row_id;

		if ( self::$fatal_handlers ) {
			return;
		}

		self::$fatal_handlers = true;

		// WordPress's own handler exits, so later shutdown functions never run. Its
		// filter fires first; the shutdown function covers WP_DISABLE_FATAL_ERROR_HANDLER.
		add_filter( 'wp_should_handle_php_error', array( __CLASS__, 'filter_fatal_error' ), 10, 2 );
		register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );
	}

	/**
	 * Stop recording fatal errors once the conversion has returned.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public static function unwatch_fatal_errors(): void {
		self::$current_row = 0;
	}

	/**
	 * Record the fatal error WordPress is about to handle.
	 *
	 * @since 1.3.0
	 *
	 * @param  bool                 $should_handle Whether WordPress handles the error.
	 * @param  array<string, mixed> $error         Error from error_get_last().
	 * @return bool Unchanged decision.
	 */
	public static function filter_fatal_error( $should_handle, $error ) {
		self::fail_current_row( is_array( $error ) ? $error : null );

		return $should_handle;
	}

	/**
	 * Record a fatal error when WordPress's handler is disabled.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public static function on_shutdown(): void {
		self::fail_current_row( error_get_last() );
	}

	/**
	 * Mark the row being converted as failed with the fatal error's message.
	 *
	 * @since 1.3.0
	 *
	 * @param  array<string, mixed>|null $error Error from error_get_last().
	 * @return bool True when a row was marked.
	 */
	public static function fail_current_row( ?array $error ): bool {
		if ( 0 === self::$current_row || empty( $error['type'] ) || ! ( (int) $error['type'] & self::FATAL_ERRORS ) ) {
			return false;
		}

		$row_id            = self::$current_row;
		self::$current_row = 0;

		// Keep the cause, not the stack trace or file path.
		$message = strtok( (string) ( $error['message'] ?? '' ), "\n" );
		$message = trim( (string) preg_replace( '/^(.*) in .+(?: on line \d+|:\d+)$/', '$1', (string) $message ) );

		Queue::complete(
			$row_id,
			Queue::FAILED,
			0,
			/* translators: %s: PHP error message. */
			sprintf( __( 'PHP stopped while converting this image: %s', 'webberzone-image-optimizer' ), $message )
		);

		return true;
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

		Cron_Health::record_run();

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
	 * Acquire a connection-scoped MySQL lock without waiting.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private static function release_lock(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', self::LOCK ) );
	}
}
