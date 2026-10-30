<?php
/**
 * Tests for the Abilities API integration.
 *
 * @package WebberZone\Image_Optimizer
 */

use WebberZone\Image_Optimizer\Options_API;

/**
 * Abilities that let AI assistants and other clients run the plugin.
 */
class AbilitiesTest extends WP_UnitTestCase
{

    /**
     * Settings stored before each test.
     *
     * @var mixed
     */
    private $saved_settings;

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

        wp_set_current_user(self::factory()->user->create(array( 'role' => 'administrator' )));
    }

    /**
     * Restore the settings.
     */
    public function tear_down()
    {
        update_option('wzio_settings', $this->saved_settings);
        Options_API::flush_cache();

        parent::tear_down();
    }

    /**
     * Every ability is registered, and refused to users who cannot manage the site.
     */
    public function test_abilities_are_registered_for_administrators_only()
    {
        $names = array( 'wzio/get-status', 'wzio/queue-bulk', 'wzio/run-batch', 'wzio/optimize-attachment', 'wzio/get-settings', 'wzio/update-settings' );

        foreach ( $names as $name ) {
            $this->assertInstanceOf(WP_Ability::class, wp_get_ability($name), $name . ' is not registered.');
        }

        wp_set_current_user(self::factory()->user->create(array( 'role' => 'author' )));

        $this->assertWPError(wp_get_ability('wzio/update-settings')->execute(array( 'quality_webp' => 70 )));
    }

    /**
     * Valid settings are saved and others kept; invalid or unexposed settings are rejected.
     */
    public function test_update_settings()
    {
        update_option('wzio_settings', array( 'quality_avif' => 45 ));
        Options_API::flush_cache();

        $ability = wp_get_ability('wzio/update-settings');
        $result  = $ability->execute(
            array(
                'quality_webp'   => 70,
                'strip_metadata' => false,
            )
        );

        $this->assertNotWPError($result);

        $stored = get_option('wzio_settings');

        $this->assertSame(70, $stored['quality_webp']);
        $this->assertSame(0, $stored['strip_metadata']);
        $this->assertSame(45, $stored['quality_avif']);

        $this->assertWPError($ability->execute(array( 'quality_webp' => 150 )));
        $this->assertWPError($ability->execute(array( 'compress_originals' => true )));
    }
}
