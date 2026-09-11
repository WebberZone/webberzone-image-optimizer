<?php
/**
 * Tests for background queue cron diagnostics.
 *
 * @package WebberZone\Image_Optimizer
 */

use WebberZone\Image_Optimizer\Admin\Bulk_Page;
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
     * An empty queue is healthy even when page-load cron is switched off.
     */
    public function test_empty_queue_is_healthy_with_wp_cron_disabled(): void
    {
        $this->assertSame(
            Cron_Health::HEALTHY,
            Cron_Health::determine_status(true, 0, 0, 10_000, 10_000 + Cron_Health::STALL_AFTER)
        );
    }

    /**
     * The first look at a queue cannot tell a stall from a quiet site.
     */
    public function test_first_observation_is_healthy(): void
    {
        $this->assertSame(
            Cron_Health::HEALTHY,
            Cron_Health::determine_status(false, 5, 0, 0, 10_000)
        );
    }

    /**
     * A worker run after the observation proves cron is working.
     *
     * This is the quiet low-traffic site: the scheduled event is long overdue
     * because nothing has loaded a page, not because cron is broken.
     */
    public function test_worker_run_since_the_observation_is_healthy(): void
    {
        $observed_at = 10_000;

        $this->assertSame(
            Cron_Health::HEALTHY,
            Cron_Health::determine_status(false, 4182, $observed_at + 1, $observed_at, $observed_at + ( 6 * HOUR_IN_SECONDS ))
        );
    }

    /**
     * Cron keeps the whole window to act on an observation.
     */
    public function test_observation_inside_the_window_is_healthy(): void
    {
        $observed_at = 10_000;

        $this->assertSame(
            Cron_Health::HEALTHY,
            Cron_Health::determine_status(false, 1, 0, $observed_at, $observed_at + Cron_Health::STALL_AFTER - 1)
        );
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
     * A run that predates the observation does not clear it.
     */
    public function test_run_before_the_observation_is_stalled(): void
    {
        $observed_at = 10_000;

        $this->assertSame(
            Cron_Health::STALLED,
            Cron_Health::determine_status(false, 1, $observed_at - 1, $observed_at, $observed_at + Cron_Health::STALL_AFTER)
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

    /**
     * An emptied queue forgets the observation.
     */
    public function test_empty_queue_forgets_the_observation(): void
    {
        $this->assertSame(0, Cron_Health::next_observation(0, 0, 10_000, 20_000));
    }

    /**
     * A queue seen for the first time opens a window.
     */
    public function test_first_sighting_opens_a_window(): void
    {
        $this->assertSame(20_000, Cron_Health::next_observation(1, 0, 0, 20_000));
    }

    /**
     * A worker run since the observation re-arms the window.
     */
    public function test_worker_run_rearms_the_window(): void
    {
        $this->assertSame(20_000, Cron_Health::next_observation(1, 10_001, 10_000, 20_000));
    }

    /**
     * An unanswered observation is kept, so its window keeps running.
     */
    public function test_unanswered_observation_is_kept(): void
    {
        $this->assertSame(10_000, Cron_Health::next_observation(1, 0, 10_000, 20_000));
    }

    /**
     * An empty queue records nothing and reports healthy.
     */
    public function test_get_status_ignores_an_empty_queue(): void
    {
        $this->assertSame(Cron_Health::HEALTHY, Cron_Health::get_status());
        $this->assertFalse(get_option(Cron_Health::OBSERVED));
    }

    /**
     * The first check of a queue with work opens a window without warning.
     */
    public function test_get_status_opens_a_window_on_first_check(): void
    {
        Queue::add(array( 101, 102 ));

        $this->assertSame(Cron_Health::HEALTHY, Cron_Health::get_status());
        $this->assertGreaterThan(0, (int) get_option(Cron_Health::OBSERVED));
    }

    /**
     * An unanswered window that has run its course is reported.
     */
    public function test_get_status_reports_a_stall_after_the_window(): void
    {
        Queue::add(array( 101 ));
        update_option(Cron_Health::OBSERVED, time() - Cron_Health::STALL_AFTER - 1, false);

        // Which of the two the wiring reports depends on the test host's own cron config.
        $expected = Cron_Health::is_wp_cron_disabled() ? Cron_Health::DISABLED : Cron_Health::STALLED;

        $this->assertSame($expected, Cron_Health::get_status());
    }

    /**
     * A recorded worker run clears the window and re-arms it.
     */
    public function test_recorded_run_clears_the_window(): void
    {
        Queue::add(array( 101 ));

        $observed_at = time() - Cron_Health::STALL_AFTER - 1;
        update_option(Cron_Health::OBSERVED, $observed_at, false);

        Cron_Health::record_run();

        $this->assertSame(Cron_Health::HEALTHY, Cron_Health::get_status());
        $this->assertGreaterThan($observed_at, (int) get_option(Cron_Health::OBSERVED));
    }

    /**
     * Draining the queue forgets the stored window.
     */
    public function test_get_status_forgets_the_window_when_the_queue_drains(): void
    {
        Queue::add(array( 101 ));
        update_option(Cron_Health::OBSERVED, time() - Cron_Health::STALL_AFTER - 1, false);

        Queue::clear();

        $this->assertSame(Cron_Health::HEALTHY, Cron_Health::get_status());
        $this->assertFalse(get_option(Cron_Health::OBSERVED));
    }

    /**
     * Switching the background queue off ends the check and its open window.
     */
    public function test_get_status_is_healthy_when_the_background_queue_is_off(): void
    {
        Queue::add(array( 101 ));
        update_option(Cron_Health::OBSERVED, time() - Cron_Health::STALL_AFTER - 1, false);

        add_filter('wzio_get_option_background_queue', '__return_zero');
        $status = Cron_Health::get_status();
        remove_filter('wzio_get_option_background_queue', '__return_zero');

        $this->assertSame(Cron_Health::HEALTHY, $status);
        $this->assertFalse(get_option(Cron_Health::OBSERVED));
    }

    /**
     * A window discarded on deactivation cannot fire on the way back.
     */
    public function test_forget_discards_an_open_window(): void
    {
        update_option(Cron_Health::OBSERVED, time() - Cron_Health::STALL_AFTER - 1, false);

        Cron_Health::forget();

        $this->assertFalse(get_option(Cron_Health::OBSERVED));
    }

    /**
     * The disabled warning names the constant and both recovery commands.
     */
    public function test_disabled_notice_includes_cli_and_system_cron_commands(): void
    {
        $output = $this->render_notice(Cron_Health::DISABLED);

        $this->assertStringContainsString('DISABLE_WP_CRON', $output);
        $this->assertStringContainsString('<code>wp wzio run</code>', $output);
        $this->assertStringContainsString('wp --path=/path/to/wordpress cron event run --due-now --quiet', $output);
    }

    /**
     * The stalled warning explains the likely cause.
     */
    public function test_stalled_notice_explains_the_failure(): void
    {
        $output = $this->render_notice(Cron_Health::STALLED);

        $this->assertStringContainsString('has not moved', $output);
        $this->assertStringContainsString('loopback request', $output);
        $this->assertStringContainsString('<code>wp wzio run</code>', $output);
    }

    /**
     * A healthy status does not render a notice.
     */
    public function test_healthy_status_renders_no_notice(): void
    {
        $this->assertSame('', $this->render_notice(Cron_Health::HEALTHY));
    }

    /**
     * Render the Bulk Optimize warning for one status.
     *
     * @param  string $status Cron health status.
     * @return string Rendered markup.
     */
    private function render_notice( string $status ): string
    {
        $reflection = new ReflectionClass(Bulk_Page::class);
        $page       = $reflection->newInstanceWithoutConstructor();

        ob_start();
        $page->render_cron_health_notice($status);

        return (string) ob_get_clean();
    }
}
