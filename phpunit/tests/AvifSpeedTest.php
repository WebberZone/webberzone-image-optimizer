<?php
/**
 * Tests for the per-driver AVIF speed mapping.
 *
 * @package WebberZone\Image_Optimizer
 */

use WebberZone\Image_Optimizer\Drivers\GD_Driver;
use WebberZone\Image_Optimizer\Drivers\Imagick_Driver;

/**
 * Exposes the Imagick mapping without adding it to the production API.
 */
class WZIO_Imagick_Speed_Probe extends Imagick_Driver
{

    /**
     * Public wrapper for the protected mapping.
     *
     * @param  int  $effort       Plugin effort level.
     * @param  bool $source_alpha Whether the source can carry transparency.
     * @return int Encoder speed.
     */
    public static function speed(int $effort, bool $source_alpha): int
    {
        return self::avif_speed($effort, $source_alpha);
    }

    /**
     * Public wrapper for the protected source test.
     *
     * @param  array $args Encoding arguments.
     * @return bool Whether the source can carry transparency.
     */
    public static function alpha(array $args): bool
    {
        return self::source_has_alpha($args);
    }
}

/**
 * Exposes the GD mapping without adding it to the production API.
 */
class WZIO_GD_Speed_Probe extends GD_Driver
{

    /**
     * Public wrapper for the protected mapping.
     *
     * @param  int  $effort       Plugin effort level.
     * @param  bool $source_alpha Whether the source can carry transparency.
     * @return int Encoder speed.
     */
    public static function speed(int $effort, bool $source_alpha): int
    {
        return self::avif_speed($effort, $source_alpha);
    }
}

/**
 * The mapping is pure arithmetic, so it needs no encoder to test.
 */
class AvifSpeedTest extends WP_UnitTestCase
{

    /**
     * The two built-in drivers agree on these operating points today, but each
     * owns its own copy because they are different libraries.
     *
     * @return array<string, array{0: int, 1: int}> Effort to expected speed.
     */
    public function opaque_efforts()
    {
        return array(
            'effort 0' => array( 0, 9 ),
            'effort 1' => array( 1, 9 ),
            'effort 2' => array( 2, 9 ),
            'effort 3' => array( 3, 8 ),
            'effort 4' => array( 4, 7 ),
            'effort 5' => array( 5, 6 ),
            'effort 6' => array( 6, 4 ),
        );
    }

    /**
     * Every effort maps to its measured speed on Imagick.
     *
     * @dataProvider opaque_efforts
     *
     * @param int $effort   Plugin effort level.
     * @param int $expected Expected encoder speed.
     */
    public function test_imagick_maps_every_effort(int $effort, int $expected)
    {
        $this->assertSame($expected, WZIO_Imagick_Speed_Probe::speed($effort, false));
    }

    /**
     * Every effort maps to its measured speed on GD.
     *
     * @dataProvider opaque_efforts
     *
     * @param int $effort   Plugin effort level.
     * @param int $expected Expected encoder speed.
     */
    public function test_gd_maps_every_effort(int $effort, int $expected)
    {
        $this->assertSame($expected, WZIO_GD_Speed_Probe::speed($effort, false));
    }

    /**
     * The default is the measured knee, not an end of the scale.
     */
    public function test_the_default_effort_maps_to_the_knee()
    {
        $default = (int) \WebberZone\Image_Optimizer\Admin\Settings::get_defaults()['effort_avif'];

        $this->assertSame(4, $default);
        $this->assertSame(7, WZIO_Imagick_Speed_Probe::speed($default, false));
        $this->assertSame(7, WZIO_GD_Speed_Probe::speed($default, false));
    }

    /**
     * Speed 9 inflates a transparent source by up to two fifths, so it is capped.
     */
    public function test_a_transparent_source_never_reaches_speed_nine()
    {
        foreach (range(0, 6) as $effort) {
            $this->assertLessThanOrEqual(8, WZIO_Imagick_Speed_Probe::speed($effort, true), "Imagick effort $effort");
            $this->assertLessThanOrEqual(8, WZIO_GD_Speed_Probe::speed($effort, true), "GD effort $effort");
        }
    }

    /**
     * The clamp only bites below the default; it must not slow the rest down.
     */
    public function test_the_clamp_does_nothing_at_or_above_the_default()
    {
        foreach (range(4, 6) as $effort) {
            $this->assertSame(
                WZIO_Imagick_Speed_Probe::speed($effort, false),
                WZIO_Imagick_Speed_Probe::speed($effort, true),
                "Imagick effort $effort"
            );
            $this->assertSame(
                WZIO_GD_Speed_Probe::speed($effort, false),
                WZIO_GD_Speed_Probe::speed($effort, true),
                "GD effort $effort"
            );
        }
    }

    /**
     * The clamp does bite below the default, which is the whole point of it.
     */
    public function test_the_clamp_bites_below_the_default()
    {
        $this->assertSame(9, WZIO_Imagick_Speed_Probe::speed(0, false));
        $this->assertSame(8, WZIO_Imagick_Speed_Probe::speed(0, true));
    }

    /**
     * An effort outside the stored range still lands on a real speed.
     */
    public function test_an_out_of_range_effort_is_clamped()
    {
        $this->assertSame(9, WZIO_Imagick_Speed_Probe::speed(-5, false));
        $this->assertSame(4, WZIO_Imagick_Speed_Probe::speed(99, false));
    }

    /**
     * Image dimensions no longer steer the encoder.
     *
     * AVIF effort used to be stepped down in megapixel bands, which pushed the
     * largest images towards a setting that made them bigger rather than merely
     * quicker. Nothing about the source size may reach the mapping now.
     */
    public function test_dimensions_do_not_affect_the_speed()
    {
        $reflection = new ReflectionMethod(Imagick_Driver::class, 'avif_speed');

        $this->assertSame(
            2,
            $reflection->getNumberOfParameters(),
            'avif_speed() takes effort and alpha only; a dimension argument would reintroduce banding.'
        );

        foreach (range(0, 6) as $effort) {
            $this->assertSame(
                WZIO_Imagick_Speed_Probe::speed($effort, false),
                WZIO_Imagick_Speed_Probe::speed($effort, false),
                "Imagick effort $effort is not stable"
            );
        }
    }

    /**
     * A driver must not read the dimensions the converter still passes.
     *
     * `dims` stays in the arguments for third-party drivers, so the guard is
     * that two very different sizes produce the same encoder speed.
     */
    public function test_the_dims_argument_no_longer_reaches_the_mapping()
    {
        $small = array( 'mime' => 'image/jpeg', 'dims' => array( 'width' => 100, 'height' => 100 ) );
        $huge  = array( 'mime' => 'image/jpeg', 'dims' => array( 'width' => 6000, 'height' => 6000 ) );

        $this->assertSame(
            WZIO_Imagick_Speed_Probe::alpha($small),
            WZIO_Imagick_Speed_Probe::alpha($huge)
        );

        $this->assertSame(
            WZIO_Imagick_Speed_Probe::speed(4, WZIO_Imagick_Speed_Probe::alpha($small)),
            WZIO_Imagick_Speed_Probe::speed(4, WZIO_Imagick_Speed_Probe::alpha($huge))
        );
    }

    /**
     * Only JPEG rules transparency out.
     */
    public function test_alpha_capability_is_decided_by_the_source_mime()
    {
        $this->assertFalse(WZIO_Imagick_Speed_Probe::alpha(array( 'mime' => 'image/jpeg' )));
        $this->assertTrue(WZIO_Imagick_Speed_Probe::alpha(array( 'mime' => 'image/png' )));
        $this->assertTrue(WZIO_Imagick_Speed_Probe::alpha(array( 'mime' => 'image/gif' )));
    }

    /**
     * A caller that passes no MIME takes the cautious path.
     */
    public function test_a_missing_mime_is_treated_as_alpha_capable()
    {
        $this->assertTrue(WZIO_Imagick_Speed_Probe::alpha(array()));
        $this->assertTrue(WZIO_Imagick_Speed_Probe::alpha(array( 'mime' => '' )));
    }
}
