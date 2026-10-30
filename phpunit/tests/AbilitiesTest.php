<?php
/**
 * Tests for the Abilities API integration.
 *
 * @package WebberZone\Image_Optimizer
 */

use WebberZone\Image_Optimizer\Database;
use WebberZone\Image_Optimizer\Processor;
use WebberZone\Image_Optimizer\Queue;

/**
 * Abilities that let AI assistants and other clients run the plugin.
 */
class AbilitiesTest extends WP_UnitTestCase
{

    /**
     * Every ability the plugin registers.
     *
     * @var array<int, string>
     */
    const ABILITIES = array(
        'wzio/get-status',
        'wzio/queue-bulk',
        'wzio/run-batch',
        'wzio/optimize-attachment',
        'wzio/get-settings',
        'wzio/update-settings',
    );

    /**
     * Settings stored before each test.
     *
     * @var mixed
     */
    private $saved_settings;

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
     * Skip on WordPress without the Abilities API and start as an administrator.
     */
    public function set_up()
    {
        parent::set_up();

        if (! function_exists('wp_get_ability') ) {
            $this->markTestSkipped('The Abilities API needs WordPress 6.9 or later.');
        }

        $this->saved_settings = get_option('wzio_settings');

        Queue::clear();
        Processor::unschedule();

        wp_set_current_user(self::factory()->user->create(array( 'role' => 'administrator' )));
    }

    /**
     * Restore the settings and leave no queue rows or schedule behind.
     */
    public function tear_down()
    {
        update_option('wzio_settings', $this->saved_settings);
        \WebberZone\Image_Optimizer\Options_API::flush_cache();

        Queue::clear();
        Processor::unschedule();

        parent::tear_down();
    }

    /**
     * Get a registered ability.
     *
     * @param  string $name Ability name.
     * @return WP_Ability
     */
    private function ability( $name )
    {
        $ability = wp_get_ability($name);

        $this->assertInstanceOf(WP_Ability::class, $ability, $name . ' is not registered.');

        return $ability;
    }

    /**
     * Every ability is registered in the plugin's category.
     */
    public function test_abilities_are_registered()
    {
        foreach ( self::ABILITIES as $name ) {
            $this->assertSame('wzio', $this->ability($name)->get_category());
        }
    }

    /**
     * Users who cannot manage the site are refused.
     */
    public function test_abilities_refuse_users_without_the_capability()
    {
        wp_set_current_user(self::factory()->user->create(array( 'role' => 'author' )));

        $this->assertNotTrue($this->ability('wzio/get-status')->check_permissions());
        $this->assertNotTrue($this->ability('wzio/update-settings')->check_permissions(array( 'quality_webp' => 70 )));
        $this->assertWPError($this->ability('wzio/queue-bulk')->execute(array()));
    }

    /**
     * The status ability returns a result that matches its output schema.
     */
    public function test_status_reports_the_queue()
    {
        $status = $this->ability('wzio/get-status')->execute();

        $this->assertNotWPError($status);
        $this->assertSame(0, $status['remaining']);
        $this->assertArrayHasKey('supported', $status['formats']);
    }

    /**
     * Queueing adds unhandled images and schedules the background worker.
     */
    public function test_queue_bulk_queues_images_and_schedules_the_worker()
    {
        $attachment_id = self::factory()->attachment->create_object(
            'wzio-ability.jpg',
            0,
            array( 'post_mime_type' => 'image/jpeg' )
        );

        $result = $this->ability('wzio/queue-bulk')->execute(array());

        $this->assertNotWPError($result);
        $this->assertTrue($result['done']);
        $this->assertGreaterThanOrEqual(1, $result['queued']);
        $this->assertSame(Queue::PENDING, Queue::get_status($attachment_id));
        $this->assertNotFalse(wp_next_scheduled(Processor::CRON_HOOK));
    }

    /**
     * Valid settings are saved as stored by the settings screen, and others kept.
     */
    public function test_update_settings_saves_only_the_given_settings()
    {
        update_option('wzio_settings', array( 'quality_avif' => 45 ));
        \WebberZone\Image_Optimizer\Options_API::flush_cache();

        $result = $this->ability('wzio/update-settings')->execute(
            array(
                'quality_webp'   => 70,
                'strip_metadata' => false,
            )
        );

        $this->assertNotWPError($result);
        $this->assertEqualSets(array( 'quality_webp', 'strip_metadata' ), $result['updated']);
        $this->assertSame(70, $result['settings']['quality_webp']);
        $this->assertFalse($result['settings']['strip_metadata']);

        $stored = get_option('wzio_settings');

        $this->assertSame(70, $stored['quality_webp']);
        $this->assertSame(0, $stored['strip_metadata']);
        $this->assertSame(45, $stored['quality_avif']);
    }

    /**
     * Out-of-range values and settings that are not exposed are rejected.
     */
    public function test_update_settings_rejects_invalid_input()
    {
        $ability = $this->ability('wzio/update-settings');

        $this->assertWPError($ability->execute(array( 'quality_webp' => 150 )));
        $this->assertWPError($ability->execute(array( 'formats' => array() )));
        $this->assertWPError($ability->execute(array( 'compress_originals' => true )));
        $this->assertWPError($ability->execute(array()));
    }

    /**
     * Settings are read back typed for JSON.
     */
    public function test_get_settings_returns_typed_values()
    {
        $settings = $this->ability('wzio/get-settings')->execute();

        $this->assertNotWPError($settings);
        $this->assertIsArray($settings['formats']);
        $this->assertIsBool($settings['enable_delivery']);
        $this->assertIsInt($settings['batch_size']);
        $this->assertArrayNotHasKey('compress_originals', $settings);
    }
}
