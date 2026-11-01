<?php
/**
 * Tests for stale queue claim recovery.
 *
 * @package WebberZone\Image_Optimizer
 */

use WebberZone\Image_Optimizer\Database;
use WebberZone\Image_Optimizer\Processor;
use WebberZone\Image_Optimizer\Queue;

/**
 * Releasing rows abandoned by a worker that never reported an outcome.
 */
class QueueStaleTest extends WP_UnitTestCase
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
     * Start every test with an empty queue.
     */
    public function set_up()
    {
        parent::set_up();

        Queue::clear();
    }

    /**
     * Leave no queue rows behind.
     */
    public function tear_down()
    {
        Queue::clear();

        parent::tear_down();
    }

    /**
     * Backdate a claimed row so it reads as abandoned.
     *
     * @param  int $id Queue row ID.
     * @return void
     */
    private function backdate( $id )
    {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                'UPDATE `' . Database::get_table() . '` SET updated = %s WHERE id = %d',
                gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS),
                $id
            )
        );
    }

    /**
     * Read one queue row.
     *
     * @param  int $id Queue row ID.
     * @return object Row.
     */
    private function get_row( $id )
    {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM `' . Database::get_table() . '` WHERE id = %d', $id)
        );
    }

    /**
     * A row whose worker died is released, and the lost attempt is counted.
     */
    public function test_a_stale_claim_is_released_and_counted()
    {
        Queue::add(array( 1 ), true);
        $rows = Queue::claim(1);
        $id   = (int) $rows[0]->id;
        $this->backdate($id);

        $this->assertSame(1, Queue::release_stale(600));

        $row = $this->get_row($id);

        $this->assertSame(Queue::PENDING, $row->status);
        $this->assertSame(1, (int) $row->attempts);
    }

    /**
     * A row that kills every worker it reaches stops being re-claimed.
     */
    public function test_a_row_that_never_reports_stops_after_the_retry_budget()
    {
        // Queued once: re-adding with force would reset the attempt counter.
        Queue::add(array( 2 ), true);

        $id = 0;

        for ($pass = 0; $pass < Queue::MAX_ATTEMPTS; $pass++) {
            $rows = Queue::claim(1);

            $this->assertNotEmpty($rows, 'The row should still be claimable on pass ' . $pass . '.');

            $id = (int) $rows[0]->id;
            $this->backdate($id);
            Queue::release_stale(600);
        }

        $row = $this->get_row($id);

        $this->assertSame(Queue::FAILED, $row->status);
        $this->assertSame(Queue::MAX_ATTEMPTS, (int) $row->attempts);
        $this->assertNotSame('', (string) $row->error);
        $this->assertEmpty(Queue::claim(1), 'A row that exhausted its budget must not be claimable.');
    }
    /**
     * Point the processor at a claimed row, as it does while converting.
     *
     * @param  int $id Queue row ID.
     * @return void
     */
    private function set_current_row( $id )
    {
        $property = new ReflectionProperty(Processor::class, 'current_row');
        $property->setAccessible(true);
        $property->setValue(null, $id);
    }

    /**
     * A fatal error mid-conversion fails the row at once, with its cause.
     */
    public function test_a_fatal_error_fails_the_current_row_with_its_message()
    {
        Queue::add(array( 4 ), true);
        $rows = Queue::claim(1);
        $id   = (int) $rows[0]->id;

        $this->set_current_row($id);

        $this->assertTrue(
            Processor::fail_current_row(
                array(
                    'type'    => E_ERROR,
                    'message' => 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes) in /var/www/wp-includes/x.php on line 12',
                )
            )
        );

        $row = $this->get_row($id);

        // The first failure goes back in line until the retry budget runs out.
        $this->assertSame(Queue::PENDING, $row->status);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertStringContainsString('Allowed memory size', $row->error);
        $this->assertStringNotContainsString('/var/www', $row->error);

        $this->assertFalse(
            Processor::fail_current_row(array( 'type' => E_ERROR, 'message' => 'Again' )),
            'The row is recorded only once.'
        );
    }
}
