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
     * Speed 9 inflates a transparent source by up to two fifths, so it is capped.
     */
    public function test_a_transparent_source_never_reaches_speed_nine()
    {
        foreach (range(0, 6) as $effort) {
            $this->assertLessThanOrEqual(8, WZIO_Imagick_Speed_Probe::speed($effort, true), "Imagick effort $effort");
            $this->assertLessThanOrEqual(8, WZIO_GD_Speed_Probe::speed($effort, true), "GD effort $effort");
        }
    }
}
