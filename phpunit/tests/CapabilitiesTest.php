<?php
/**
 * Tests for the capability probe.
 *
 * @package WebberZone\Image_Optimizer
 */

use WebberZone\Image_Optimizer\Capabilities;
use WebberZone\Image_Optimizer\Drivers\Driver;

/**
 * Records what the probe asks of a driver, without encoding anything.
 */
class WZIO_Recording_Driver extends Driver
{

    /**
     * Every convert() call the probe made.
     *
     * @var array<int, array<string, mixed>>
     */
    public static $calls = array();

    /**
     * Whether the fake encoder reacts to the quality argument.
     *
     * @var bool
     */
    public static $vary_quality = true;

    /**
     * Reset the recorder between tests.
     */
    public static function reset()
    {
        self::$calls        = array();
        self::$vary_quality = true;
    }

    /**
     * Always available, so the probe always reaches it.
     *
     * @return bool True.
     */
    public static function is_available(): bool
    {
        return true;
    }

    /**
     * Machine name.
     *
     * @return string Driver slug.
     */
    public static function get_name(): string
    {
        return 'recording';
    }

    /**
     * Claims every format so it owns them in the report.
     *
     * @param  string $format Target format slug.
     * @return bool True.
     */
    public function supports(string $format): bool
    {
        unset($format);

        return true;
    }

    /**
     * Record the arguments and write a file whose size may track quality.
     *
     * @param  string $source      Source path.
     * @param  string $destination Destination path.
     * @param  string $format      Target format slug.
     * @param  array  $args        Encoding arguments.
     * @return true Always succeeds.
     */
    public function convert(string $source, string $destination, string $format, array $args)
    {
        unset($source);

        self::$calls[] = array(
            'format' => $format,
            'args'   => $args,
        );

        $bytes = self::$vary_quality ? max(1, (int) ( $args['quality'] ?? 1 )) : 16;

     // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents($destination, str_repeat('x', $bytes));

        return true;
    }
}

/**
 * What the probe asks for, and what it concludes.
 */
class CapabilitiesTest extends WP_UnitTestCase
{

    /**
     * Set up.
     */
    public function set_up()
    {
        parent::set_up();

        WZIO_Recording_Driver::reset();

        add_filter('wzio_driver_classes', array( $this, 'use_recording_driver' ));
    }

    /**
     * Tear down.
     */
    public function tear_down()
    {
        remove_filter('wzio_driver_classes', array( $this, 'use_recording_driver' ));

        Capabilities::flush();

        parent::tear_down();
    }

    /**
     * Replace the built-in drivers with the recorder.
     *
     * @return array<int, string> Driver class names.
     */
    public function use_recording_driver()
    {
        return array( WZIO_Recording_Driver::class );
    }

    /**
     * The probe answers yes or no, so it must not encode at the slow end.
     *
     * Without an explicit effort the driver's own default of 6 applies, which
     * maps to the maximum-compression speed and makes capability detection the
     * most expensive encode the plugin ever runs.
     */
    public function test_the_probe_encodes_at_the_cheapest_effort()
    {
        Capabilities::get(true);

        $this->assertNotEmpty(WZIO_Recording_Driver::$calls);

        foreach (WZIO_Recording_Driver::$calls as $call) {
            $this->assertArrayHasKey('effort', $call['args'], 'The probe passed no effort at all.');
            $this->assertSame(0, $call['args']['effort']);
        }
    }

    /**
     * An encoder that reacts to quality keeps the lower-quality retry.
     */
    public function test_quality_is_reported_as_honoured_when_output_changes()
    {
        WZIO_Recording_Driver::$vary_quality = true;

        Capabilities::get(true);

        $this->assertTrue(Capabilities::quality_is_honoured('avif'));
        $this->assertTrue(Capabilities::quality_is_honoured('webp'));
    }

    /**
     * An encoder that ignores quality would retry for a byte-identical file.
     */
    public function test_quality_is_reported_as_ignored_when_output_is_identical()
    {
        WZIO_Recording_Driver::$vary_quality = false;

        Capabilities::get(true);

        $this->assertFalse(Capabilities::quality_is_honoured('avif'));
        $this->assertFalse(Capabilities::quality_is_honoured('webp'));
    }

    /**
     * A report written before this probe existed must not disable the retry.
     */
    public function test_an_older_report_is_treated_as_honouring_quality()
    {
        Capabilities::get(true);

        $report = get_option('wzio_capabilities');

        unset($report['quality']);

        // flush() drops the option as well as the cache, so restore it after.
        Capabilities::flush();
        update_option('wzio_capabilities', $report, false);

        $this->assertTrue(Capabilities::quality_is_honoured('avif'));
    }
}
