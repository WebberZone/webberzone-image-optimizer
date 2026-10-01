<?php

use WebberZone\Image_Optimizer\Attachment_Hooks;
use WebberZone\Image_Optimizer\Admin\Settings;

class UploadDimensionTest extends WP_UnitTestCase {

    public function test_dimension_filter_modes(): void {
        $hooks = new Attachment_Hooks();
        foreach ( array( 0, '', 1920, array() ) as $value ) {
            $filter = static function () use ( $value ) { return $value; };
            add_filter( 'wzio_get_option_maximum_image_dimension', $filter );
            $this->assertSame( 1920 === $value ? 1920 : 3000, $hooks->maximum_image_dimension( 3000 ) );
            $this->assertSame( 1920 === $value ? 1920 : false, $hooks->maximum_image_dimension( false ) );
            remove_filter( 'wzio_get_option_maximum_image_dimension', $filter );
        }
        add_filter( 'wzio_get_option_disable_image_scaling', '__return_true' );
        $this->assertFalse( $hooks->maximum_image_dimension( 2560 ) );
        remove_filter( 'wzio_get_option_disable_image_scaling', '__return_true' );
    }

    public function test_later_site_filter_can_override(): void {
        $hooks = new Attachment_Hooks();
        $filter = static function () { return 1440; };
        add_filter( 'big_image_size_threshold', $filter, 20 );
        $this->assertSame( 1440, apply_filters( 'big_image_size_threshold', 2560 ) );
        remove_filter( 'big_image_size_threshold', $filter, 20 );
    }

    public function test_dimension_sanitization(): void {
        $settings = ( new ReflectionClass( Settings::class ) )->newInstanceWithoutConstructor();
        foreach ( array( '' => 0, '-1920' => 1920, '2560' => 2560 ) as $input => $expected ) {
            $result = $settings->change_settings_on_save( array( 'maximum_image_dimension' => $input ) );
            $this->assertSame( $expected, $result['maximum_image_dimension'] );
        }
        $result = $settings->change_settings_on_save( array( 'maximum_image_dimension' => array( 1920 ) ) );
        $this->assertSame( 0, $result['maximum_image_dimension'] );
    }
    public function test_existing_attachment_regeneration_keeps_incoming_threshold(): void {
        $id = self::factory()->post->create( array( 'post_type' => 'attachment' ) );
        update_post_meta( $id, '_wp_attachment_metadata', array( 'width' => 3000, 'height' => 2000 ) );
        $filter = static function () { return 1920; };
        add_filter( 'wzio_get_option_maximum_image_dimension', $filter );
        $hooks = new Attachment_Hooks();
        $this->assertSame( 2560, $hooks->maximum_image_dimension( 2560, array(), '', $id ) );
        remove_filter( 'wzio_get_option_maximum_image_dimension', $filter );
    }

}
