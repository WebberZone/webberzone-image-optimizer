<?php
/**
 * Tests for background queue cron diagnostics.
 *
 * @package WebberZone\Image_Optimizer
 */

use WebberZone\Image_Optimizer\Cron_Health;
use WebberZone\Image_Optimizer\Database;
use WebberZone\Image_Optimizer\Queue;

/**
 * Cron health detection and Bulk Optimize warning output.
 */
class CronHealthTest extends WP_UnitTestCase
{

    /**
     * Install the queue table outside the per-test database transaction.
     */
    public static function set_up_before_class()
    {
        parent::set_up_before_class();

        Database::install();
    }

    /**
     * Remove the queue table after all tests in this class have run.
     */
    public static function tear_down_after_class()
    {
        Database::drop_table();

        parent::tear_down_after_class();
    }

    /**
     * Start every test with an empty queue and no recorded history.
     */
    public function set_up()
    {
        parent::set_up();

        Queue::clear();
        delete_option(Cron_Health::LAST_RUN);
        delete_option(Cron_Health::OBSERVED);
    }

    /**
     * Leave no queue rows or recorded history behind.
     */
    public function tear_down()
    {
        Queue::clear();
        delete_option(Cron_Health::LAST_RUN);
        delete_option(Cron_Health::OBSERVED);

        parent::tear_down();
    }
    /**
     * Work still queued a whole window later, with no run, is a stall.
     */
    public function test_window_elapsed_without_a_run_is_stalled(): void
    {
        $observed_at = 10_000;

        $this->assertSame(
            Cron_Health::STALLED,
            Cron_Health::determine_status(false, 1, 0, $observed_at, $observed_at + Cron_Health::STALL_AFTER)
        );
    }
    /**
     * DISABLE_WP_CRON names the reason once a stall is established.
     */
    public function test_stall_with_wp_cron_disabled_reports_disabled(): void
    {
        $observed_at = 10_000;

        $this->assertSame(
            Cron_Health::DISABLED,
            Cron_Health::determine_status(true, 1, 0, $observed_at, $observed_at + Cron_Health::STALL_AFTER)
        );
    }
}
