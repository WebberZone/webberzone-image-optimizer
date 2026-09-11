<?php
/**
 * Background queue cron diagnostics.
 *
 * @package WebberZone\Image_Optimizer
 */

namespace WebberZone\Image_Optimizer;

if ( ! defined( 'WPINC' ) ) {
	exit;
}

/**
 * Detects conditions that can prevent the background queue from advancing.
 *
 * WordPress runs `_wp_cron()` on `shutdown`, so a screen reading the schedule
 * always sees it as it stood before this request spawned cron. An overdue event
 * therefore proves nothing on its own. This class instead records when the
 * worker last ran and when the queue was last observed with work in it, and
 * reports trouble only once cron has had a whole observation window to act.
 *
 * @since 1.1.0
 */
class Cron_Health {

	/**
	 * Cron is running, or no background worker is needed.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const HEALTHY = 'healthy';

	/**
	 * Work is queued, nothing has run it, and page-load cron is switched off.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const DISABLED = 'disabled';

	/**
	 * Work is queued and the worker has not run despite having the chance.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const STALLED = 'stalled';

	/**
	 * Option holding the last time the background worker ran.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const LAST_RUN = 'wzio_cron_last_run';

	/**
	 * Option holding the last time the queue was seen with work waiting in it.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const OBSERVED = 'wzio_cron_observed';

	/**
	 * How long the worker gets to act on an observation before it counts as stalled.
	 *
	 * Comfortably longer than the one minute worker interval and than a typical
	 * system cron interval, so a slow scheduler is not mistaken for a broken one.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	const STALL_AFTER = 15 * MINUTE_IN_SECONDS;

	/**
	 * Note that the background worker ran.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public static function record_run(): void {
		update_option( self::LAST_RUN, time(), false );
	}

	/**
	 * Get the current site's background queue cron status.
	 *
	 * Records an observation as a side effect, since a stall can only be told
	 * apart from a quiet site by comparing two looks at the queue.
	 *
	 * @since 1.1.0
	 *
	 * @return string One of the class status constants.
	 */
	public static function get_status(): string {
		if ( ! \wzio_get_option( 'background_queue', true ) ) {
			self::forget();
			return self::HEALTHY;
		}

		$remaining   = Processor::get_remaining();
		$now         = time();
		$last_run    = (int) get_option( self::LAST_RUN, 0 );
		$observed_at = (int) get_option( self::OBSERVED, 0 );

		$status = self::determine_status( self::is_wp_cron_disabled(), $remaining, $last_run, $observed_at, $now );

		self::remember( self::next_observation( $remaining, $last_run, $observed_at, $now ), $observed_at );

		return $status;
	}

	/**
	 * Discard any open observation.
	 *
	 * Called when the worker is switched off or the plugin is deactivated, so a
	 * window opened before that is not mistaken for a stall on the way back.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public static function forget(): void {
		self::remember( 0, (int) get_option( self::OBSERVED, 0 ) );
	}

	/**
	 * Whether page-load cron spawning is switched off.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True when DISABLE_WP_CRON is defined and truthy.
	 */
	public static function is_wp_cron_disabled(): bool {
		return defined( 'DISABLE_WP_CRON' ) && (bool) constant( 'DISABLE_WP_CRON' );
	}

	/**
	 * Determine cron health from an observed worker history and queue state.
	 *
	 * Kept separate from get_status() so Site Health and tests can apply the
	 * same rule without duplicating it.
	 *
	 * @since 1.1.0
	 *
	 * @param  bool $wp_cron_disabled Whether DISABLE_WP_CRON is truthy.
	 * @param  int  $remaining        Pending and processing queue rows.
	 * @param  int  $last_run         Unix timestamp of the last worker run, 0 when never.
	 * @param  int  $observed_at      Unix timestamp of the last observation, 0 when never.
	 * @param  int  $now              Current Unix timestamp.
	 * @return string One of the class status constants.
	 */
	public static function determine_status( bool $wp_cron_disabled, int $remaining, int $last_run, int $observed_at, int $now ): string {
		if ( $remaining < 1 || $observed_at < 1 || $last_run >= $observed_at ) {
			return self::HEALTHY;
		}

		if ( $now - $observed_at < self::STALL_AFTER ) {
			return self::HEALTHY;
		}

		return $wp_cron_disabled ? self::DISABLED : self::STALLED;
	}

	/**
	 * Work out the observation to carry forward.
	 *
	 * An observation is kept until the worker runs, so the window it opened
	 * measures cron's failure to act rather than the site's lack of traffic.
	 *
	 * @since 1.1.0
	 *
	 * @param  int $remaining   Pending and processing queue rows.
	 * @param  int $last_run    Unix timestamp of the last worker run, 0 when never.
	 * @param  int $observed_at Unix timestamp of the last observation, 0 when never.
	 * @param  int $now         Current Unix timestamp.
	 * @return int Observation timestamp to store, 0 to forget it.
	 */
	public static function next_observation( int $remaining, int $last_run, int $observed_at, int $now ): int {
		if ( $remaining < 1 ) {
			return 0;
		}

		if ( $observed_at < 1 || $last_run >= $observed_at ) {
			return $now;
		}

		return $observed_at;
	}

	/**
	 * Persist the observation when it changed.
	 *
	 * @since 1.1.0
	 *
	 * @param  int $observed_at Observation timestamp to store, 0 to forget it.
	 * @param  int $previous    Observation timestamp already stored.
	 * @return void
	 */
	private static function remember( int $observed_at, int $previous ): void {
		if ( $observed_at === $previous ) {
			return;
		}

		if ( $observed_at < 1 ) {
			delete_option( self::OBSERVED );
			return;
		}

		update_option( self::OBSERVED, $observed_at, false );
	}
}
