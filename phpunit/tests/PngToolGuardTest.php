<?php
/**
 * Tests for keeping PNG compression off without a PNG tool.
 *
 * @package WebberZone\Image_Optimizer
 */

use WebberZone\Image_Optimizer\Capabilities;
use WebberZone\Image_Optimizer\Options_API;
use WebberZone\Image_Optimizer\Original_Optimizer;

/**
 * PNG compression set outside the settings screen, such as with WP-CLI.
 */
class PngToolGuardTest extends WP_UnitTestCase
{

    /**
     * Hide every PNG tool so the server reads as having none.
     */
    public function set_up()
    {
        parent::set_up();

        add_filter('wzio_png_tool_paths', '__return_empty_array');
        Capabilities::get_originals(true);
        Options_API::flush_cache();
    }

    /**
     * Restore tool detection.
     */
    public function tear_down()
    {
        remove_filter('wzio_png_tool_paths', '__return_empty_array');
        Capabilities::get_originals(true);
        Options_API::flush_cache();

        parent::tear_down();
    }

    /**
     * Writing the option directly, as `wp option patch` does, stores PNG compression as off.
     */
    public function test_direct_writes_cannot_enable_png_compression()
    {
        $this->assertFalse(Capabilities::get_originals()['png']);

        update_option(
            'wzio_settings',
            array(
                'compress_originals'     => 1,
                'compress_png_originals' => 1,
            )
        );

        $stored = get_option('wzio_settings');

        $this->assertSame(0, $stored['compress_png_originals']);
        $this->assertSame(1, $stored['compress_originals']);
    }

    /**
     * A value stored before the tool went missing is read as off.
     */
    public function test_a_stored_value_is_read_as_off()
    {
        remove_filter('pre_update_option_wzio_settings', array( Original_Optimizer::class, 'guard_png_setting' ));
        update_option('wzio_settings', array( 'compress_png_originals' => 1 ));
        add_filter('pre_update_option_wzio_settings', array( Original_Optimizer::class, 'guard_png_setting' ));
        Options_API::flush_cache();

        $this->assertEmpty(\wzio_get_option('compress_png_originals', false));
        $this->assertFalse(Original_Optimizer::settings()['png']);
    }
}
