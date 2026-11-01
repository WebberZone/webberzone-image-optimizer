<?php
/**
 * Tests for the Abilities API integration.
 *
 * @package WebberZone\Image_Optimizer
 */

/**
 * Abilities that let AI assistants and other clients run the plugin.
 */
class AbilitiesTest extends WP_UnitTestCase
{

    /**
     * Skip on WordPress without the Abilities API and start as an administrator.
     */
    public function set_up()
    {
        parent::set_up();

        if (! function_exists('wp_get_ability') ) {
            $this->markTestSkipped('The Abilities API needs WordPress 6.9 or later.');
        }

        wp_set_current_user(self::factory()->user->create(array( 'role' => 'administrator' )));
    }

    /**
     * Author users cannot run the abilities.
     */
    public function test_abilities_are_registered_for_administrators_only()
    {
        wp_set_current_user(self::factory()->user->create(array( 'role' => 'author' )));

        $this->assertWPError(wp_get_ability('wzio/update-settings')->execute(array( 'quality_webp' => 70 )));
    }
}
