<?php

use WebberZone\Image_Optimizer\Attachment_Meta;
use WebberZone\Image_Optimizer\Capabilities;
use WebberZone\Image_Optimizer\Converter;
use WebberZone\Image_Optimizer\Original_Backups;
use WebberZone\Image_Optimizer\Original_Optimizer;
use WebberZone\Image_Optimizer\Drivers\Original_Driver;
use WebberZone\Image_Optimizer\Util\Helpers;

class OriginalOptimizationTest extends WP_UnitTestCase {
    private $ids = array();
    private $filters = array();

    public static function set_up_before_class() {
        parent::set_up_before_class();
        \WebberZone\Image_Optimizer\Database::install();
    }

    public static function tear_down_after_class() {
        \WebberZone\Image_Optimizer\Database::drop_table();
        parent::tear_down_after_class();
    }

    public function tear_down() {
        foreach ( $this->filters as $hook => $callback ) {
            remove_filter( $hook, $callback );
        }
        foreach ( $this->ids as $id ) {
            Original_Optimizer::delete_backups( $id );
            wp_delete_attachment( $id, true );
        }
        parent::tear_down();
    }

    private function setting( $name, $value ) {
        $hook = 'wzio_get_option_' . $name;
        if ( isset( $this->filters[$hook] ) ) {
            remove_filter( $hook, $this->filters[$hook] );
        }
        $callback = static function () use ( $value ) { return $value; };
        $this->filters[$hook] = $callback;
        add_filter( $hook, $callback );
    }

    private function attachment( $format = 'jpeg' ) {
        if ( ! function_exists( 'imagejpeg' ) ) {
            $this->markTestSkipped( 'GD is required to create the JPEG fixture.' );
        }
        $this->setting( 'compress_originals', false );
        $upload = wp_upload_bits( wp_unique_filename( wp_get_upload_dir()['path'], 'original.' . $format ), null, 'fixture' );
        $image = imagecreatetruecolor( 640, 480 );
        for ( $y = 0; $y < 480; ++$y ) {
            for ( $x = 0; $x < 640; ++$x ) {
                imagesetpixel( $image, $x, $y, ( ( $x * 13 + $y * 7 ) % 256 ) << 16 | ( ( $y * 5 ) % 256 ) << 8 | ( ( $x * 3 ) % 256 ) );
            }
        }
        if ( 'png' === $format ) { imagepng( $image, $upload['file'], 0 ); } else { imagejpeg( $image, $upload['file'], 98 ); }
        $id = wp_insert_attachment( array( 'post_mime_type' => 'image/' . $format, 'post_title' => 'Original fixture', 'post_status' => 'inherit' ), $upload['file'] );
        update_attached_file( $id, $upload['file'] );
        update_post_meta( $id, '_wp_attachment_metadata', array( 'file' => _wp_relative_upload_path( $upload['file'] ), 'width' => 640, 'height' => 480, 'filesize' => filesize( $upload['file'] ), 'sizes' => array() ) );
        $this->ids[] = $id;
        return array( $id, $upload['file'] );
    }

    private function process( $id, $path ) {
        $this->setting( 'compress_originals', true );
        $this->setting( 'original_jpeg_quality', 65 );
        $this->setting( 'min_saving', 0 );
        return Original_Backups::locked( $id, static function () use ( $id, $path ) {
            return Original_Optimizer::process_file( $id, $path );
        } );
    }

    public function test_first_backup_is_immutable_and_sidecar_deletion_preserves_recovery() {
        list( $id, $path ) = $this->attachment();
        $hash = hash_file( 'sha256', $path );
        $entry = $this->process( $id, $path );
        $this->assertArrayHasKey( 'after', $entry );
        $relative = Original_Backups::relative( $path );
        $backup = Original_Backups::verified_path( $relative, $entry );
        $this->assertSame( $hash, hash_file( 'sha256', $backup ) );
        $again = $this->process( $id, $path );
        $this->assertSame( $entry['output_hash'], $again['output_hash'] );
        $this->assertSame( $hash, hash_file( 'sha256', $backup ) );
        Attachment_Meta::set( $id, array( 'files' => array( basename( $path ) => array( 'size' => filesize( $path ), 'original' => $entry ) ) ) );
        Converter::delete_sidecars( $id );
        $this->assertNotEmpty( Original_Backups::get( $id ) );
        $this->assertArrayHasKey( 'original', Attachment_Meta::get( $id )['files'][basename( $path )] );
    }

    public function test_resize_restore_round_trip_and_no_automatic_recompression() {
        list( $id, $path ) = $this->attachment();
        $hash = hash_file( 'sha256', $path );
        $this->setting( 'resize_existing_originals', true );
        $this->setting( 'maximum_image_dimension', 320 );
        $entry = $this->process( $id, $path );
        $this->assertSame( array( 320, 240 ), $entry['resized']['to'] );
        $this->assertTrue( Original_Optimizer::update_metadata( $id ) );
        $this->assertSame( 320, wp_get_attachment_metadata( $id )['width'] );
        $sources = array( 320 => array( 'descriptor' => 'w', 'value' => 320 ), 640 => array( 'descriptor' => 'w', 'value' => 640 ) );
        $this->assertCount( 1, Original_Optimizer::filter_srcset( $sources, array(), '', wp_get_attachment_metadata( $id ), $id ) );
        $this->assertSame( 1, Original_Optimizer::restore( $id, false ) );
        $this->assertSame( $hash, hash_file( 'sha256', $path ) );
        $this->assertSame( 640, wp_get_attachment_metadata( $id )['width'] );
        $this->assertSame( array(), Original_Backups::get( $id ) );
        $this->assertSame( array(), Original_Optimizer::process_file( $id, $path ) );
        $this->assertFalse( Original_Optimizer::$updating_metadata );
    }

    public function test_rejected_encode_keeps_source_and_corrupt_backup_blocks_restore() {
        list( $id, $path ) = $this->attachment();
        $hash = hash_file( 'sha256', $path );
        $result = ( new Original_Driver() )->convert( $path, $path, 'jpeg', array( 'quality' => 65, 'max_bytes' => 1 ) );
        $this->assertWPError( $result );
        $this->assertSame( $hash, hash_file( 'sha256', $path ) );
        $entry = Original_Backups::locked( $id, static function () use ( $id, $path ) { return Original_Backups::ensure( $id, $path ); } );
        $backup = Original_Backups::verified_path( Original_Backups::relative( $path ), $entry );
        file_put_contents( $backup, 'broken' );
        $this->assertWPError( Original_Optimizer::restore( $id, false ) );
        $this->assertNotEmpty( Original_Backups::get( $id ) );
        $this->assertSame( $hash, hash_file( 'sha256', $path ) );
        wp_delete_file( $backup );
    }

    public function test_schema_one_is_readable_and_locks_exclude_a_second_operation() {
        list( $id, $path ) = $this->attachment();
        update_post_meta( $id, Attachment_Meta::META_KEY, array( 'v' => 1, 'updated' => 1, 'files' => array( basename( $path ) => array( 'size' => 100, 'webp' => array( 'bytes' => 50 ) ) ) ) );
        $this->assertSame( 50, Attachment_Meta::get_totals( $id )['saved'] );
        Original_Backups::locked( $id, function () use ( $id ) {
            $result = Original_Backups::locked( $id, static function () { return 'unsafe'; } );
            $this->assertWPError( $result );
        } );
    }

    public function test_interrupted_replacement_recovers_and_retries_from_backup() {
        list( $id, $path ) = $this->attachment();
        $entry = $this->process( $id, $path );
        $relative = Original_Backups::relative( $path );
        $data = Original_Backups::get( $id );
        $data[$relative]['state'] = 'processing';
        Original_Backups::save( $id, $data );
        $again = $this->process( $id, $path );
        $this->assertSame( 'optimized', $again['state'] );
        $this->assertSame( $entry['hash'], $again['hash'] );
        $this->assertSame( $entry['output_hash'], $again['output_hash'] );
    }

    public function test_within_cap_keeps_dimensions_and_clean_editor_does_not_save_into_backups() {
        list( $id, $path ) = $this->attachment();
        $this->setting( 'resize_existing_originals', true );
        $this->setting( 'maximum_image_dimension', 1280 );
        $entry = $this->process( $id, $path );
        $this->assertArrayNotHasKey( 'resized', $entry );
        $editor = wp_get_image_editor( $path );
        $this->assertNotWPError( $editor );
        $this->assertNotWPError( $editor->resize( 100, 100, false ) );
        $result = $editor->save();
        $this->assertNotWPError( $result );
        $this->assertSame( dirname( $path ), dirname( $result['path'] ) );
        wp_delete_file( $result['path'] );
    }
    public function test_backup_path_traversal_and_external_symlink_are_rejected() {
        list( $id, $path ) = $this->attachment();
        $this->assertSame( '', Original_Backups::relative( __FILE__ ) );
        $this->assertWPError( Original_Backups::verified_path( '../../wp-config.php', array( 'hash' => 'bad' ) ) );
        $root = Original_Backups::root();
        $this->assertNotWPError( $root );
        $link = $root . '/unsafe-link.jpg';
        if ( function_exists( 'symlink' ) && symlink( $path, $link ) ) {
            $this->assertWPError( Original_Backups::verified_path( 'unsafe-link.jpg', array( 'hash' => hash_file( 'sha256', $path ) ) ) );
            unlink( $link );
        }
    }

    public function test_restore_cleanup_resumes_after_backup_already_deleted() {
        list( $id, $path ) = $this->attachment();
        $entry = $this->process( $id, $path );
        $relative = Original_Backups::relative( $path );
        $backup = Original_Backups::verified_path( $relative, $entry );
        $this->assertTrue( ( new Original_Driver() )->restore( $backup, $path ) );
        $data = Original_Backups::get( $id );
        $data[$relative]['state'] = 'restored';
        Original_Backups::save( $id, $data );
        wp_delete_file( $backup );
        $this->assertSame( 1, Original_Optimizer::restore( $id, false ) );
        $this->assertSame( array(), Original_Backups::get( $id ) );
    }

    public function test_detected_low_quality_jpeg_is_kept_without_a_backup() {
        if ( ! class_exists( 'Imagick' ) ) {
            $this->markTestSkipped( 'Imagick is needed for quality detection.' );
        }
        list( $id, $path ) = $this->attachment();
        $image = new Imagick( $path );
        $image->setImageCompressionQuality( 40 );
        $image->writeImage( $path );
        $image->clear();
        clearstatcache( true, $path );
        $hash = hash_file( 'sha256', $path );
        $entry = $this->process( $id, $path );
        $this->assertSame( 'quality', $entry['skip'] );
        $this->assertSame( array(), Original_Backups::get( $id ) );
        $this->assertSame( $hash, hash_file( 'sha256', $path ) );
    }

    public function test_disabling_resize_does_not_enlarge_an_already_resized_main_file() {
        list( $id, $path ) = $this->attachment();
        $this->setting( 'resize_existing_originals', true );
        $this->setting( 'maximum_image_dimension', 320 );
        $entry = $this->process( $id, $path );
        $this->assertSame( array( 320, 240 ), $entry['resized']['to'] );
        $this->setting( 'resize_existing_originals', false );
        $entry = $this->process( $id, $path );
        $this->assertSame( array( 320, 240 ), array_slice( wp_getimagesize( $path ), 0, 2 ) );
    }

    public function test_png_tool_compression_is_reversible() {
        if ( ! Capabilities::get_originals( true )['png'] ) {
            $this->markTestSkipped( 'A working PNG tool is required.' );
        }
        list( $id, $path ) = $this->attachment( 'png' );
        $this->setting( 'compress_png_originals', true );
        $hash = hash_file( 'sha256', $path );
        $entry = $this->process( $id, $path );
        $this->assertArrayHasKey( 'after', $entry );
        $this->assertLessThan( $entry['before'], $entry['after'] );
        $this->assertSame( 'image/png', wp_get_image_mime( $path ) );
        $this->assertSame( 1, Original_Optimizer::restore( $id, false ) );
        $this->assertSame( $hash, hash_file( 'sha256', $path ) );
    }

    public function test_gd_preserves_icc_segments_when_stripping_other_metadata() {
        list( $id, $path ) = $this->attachment();
        $icc = "ICC_PROFILE\0\1\1fixture-profile";
        $segment = "\xff\xe2" . pack( 'n', strlen( $icc ) + 2 ) . $icc;
        $bytes = file_get_contents( $path );
        file_put_contents( $path, substr( $bytes, 0, 2 ) . $segment . substr( $bytes, 2 ) );
        $target = $path . '.test.jpg';
        try {
            $this->assertTrue( ( new Original_Driver() )->convert( $path, $target, 'jpeg', array( 'gd' => true, 'quality' => 65, 'strip' => true ) ) );
            $this->assertStringContainsString( $segment, file_get_contents( $target ) );
        } finally {
            wp_delete_file( $target );
        }
    }

    public function test_stripping_keeps_exif_orientation_only() {
        list( $id, $path ) = $this->attachment();
        $exif = "Exif\0\0II\x2a\0\x08\0\0\0\x02\0\x12\x01\x03\0\x01\0\0\0\x06\0\0\0\x0f\x01\x02\0\x05\0\0\0\x26\0\0\0\0\0\0\0Canon\0";
        $segment = "\xff\xe1" . pack( 'n', strlen( $exif ) + 2 ) . $exif;
        $bytes = file_get_contents( $path );
        file_put_contents( $path, substr( $bytes, 0, 2 ) . $segment . substr( $bytes, 2 ) );
        $expected = "\xff\xe1\x00\x22Exif\0\0MM\0\x2a\0\0\0\x08\0\x01\x01\x12\0\x03\0\0\0\x01\0\x06\0\0\0\0\0\0";
        $backends = array( 'gd' => true );
        if ( class_exists( '\Imagick' ) ) {
            $backends['imagick'] = false;
        }
        foreach ( $backends as $name => $gd ) {
            $target = $path . '.' . $name . '.jpg';
            try {
                $this->assertTrue( ( new Original_Driver() )->convert( $path, $target, 'jpeg', array( 'gd' => $gd, 'quality' => 65, 'strip' => true ) ), $name );
                $output = file_get_contents( $target );
                $this->assertStringContainsString( $expected, $output, $name );
                $this->assertStringNotContainsString( 'Canon', $output, $name );
            } finally {
                wp_delete_file( $target );
            }
        }
    }

    public function test_upright_source_gets_no_orientation_segment() {
        list( $id, $path ) = $this->attachment();
        $target = $path . '.test.jpg';
        try {
            $this->assertTrue( ( new Original_Driver() )->convert( $path, $target, 'jpeg', array( 'gd' => true, 'quality' => 65, 'strip' => true ) ) );
            $this->assertStringNotContainsString( "Exif\0\0", file_get_contents( $target ) );
        } finally {
            wp_delete_file( $target );
        }
    }

    public function test_metadata_write_failure_is_reported_by_both_conversion_paths() {
        $block = static function ( $check, $object_id, $meta_key ) {
            return '_wp_attachment_metadata' === $meta_key ? true : $check;
        };
        $this->setting( 'compress_originals', true );
        $this->setting( 'resize_existing_originals', true );
        $this->setting( 'maximum_image_dimension', 320 );
        $this->setting( 'original_jpeg_quality', 65 );
        $this->setting( 'min_saving', 0 );

        list( $id ) = $this->attachment();
        $this->setting( 'compress_originals', true );
        add_filter( 'update_post_metadata', $block, 10, 3 );
        try {
            $result = Converter::convert_attachment( $id, array( 'formats' => array() ) );
        } finally {
            remove_filter( 'update_post_metadata', $block, 10 );
        }
        $this->assertIsArray( $result );
        $this->assertGreaterThanOrEqual( 1, $result['failed'] );
        $this->assertNotEmpty( $result['errors'] );

        list( $id ) = $this->attachment();
        $this->setting( 'compress_originals', true );
        add_filter( 'update_post_metadata', $block, 10, 3 );
        try {
            $result = Converter::convert_next_file( $id, array( 'formats' => array() ) );
        } finally {
            remove_filter( 'update_post_metadata', $block, 10 );
        }
        $this->assertWPError( $result );
        $this->assertSame( 'wzio_metadata', $result->get_error_code() );
    }

    public function test_regenerated_subsize_is_retired_and_can_be_compressed_again() {
        list( $id, $path ) = $this->attachment();
        $thumb = dirname( $path ) . '/thumb-' . basename( $path );
        copy( $path, $thumb );
        $meta = wp_get_attachment_metadata( $id );
        $meta['sizes'] = array( 'thumbnail' => array( 'file' => basename( $thumb ), 'width' => 640, 'height' => 480 ) );
        update_post_meta( $id, '_wp_attachment_metadata', $meta );
        $entry = $this->process( $id, $thumb );
        $this->assertArrayHasKey( 'after', $entry );
        $relative = Original_Backups::relative( $thumb );

        $image = imagecreatetruecolor( 640, 480 );
        imagefill( $image, 0, 0, 0x336699 );
        imagejpeg( $image, $thumb, 90 );
        $again = $this->process( $id, $thumb );
        $this->assertArrayHasKey( 'error', $again, 'An unannounced rewrite is still detected.' );

        Original_Backups::locked( $id, static function () use ( $id, $meta ) { Original_Optimizer::retire_regenerated( $id, $meta ); } );
        $this->assertArrayHasKey( $relative, Original_Backups::get( $id ), 'An external modification must keep its backup.' );
        $backup = Original_Backups::verified_path( $relative, Original_Backups::get( $id )[ $relative ] );
        $this->assertNotWPError( $backup );

        Original_Optimizer::note_written( $thumb );
        Original_Backups::locked( $id, static function () use ( $id, $meta ) { Original_Optimizer::retire_regenerated( $id, $meta ); } );
        $this->assertArrayNotHasKey( $relative, Original_Backups::get( $id ) );
        $after = $this->process( $id, $thumb );
        $this->assertArrayNotHasKey( 'error', $after );
        wp_delete_file( $thumb );
    }

    public function test_original_failure_is_not_settled_and_explicit_retry_clears_it() {
        list( $id, $path ) = $this->attachment();
        $this->setting( 'compress_originals', true );
        $this->setting( 'original_jpeg_quality', 65 );
        $this->setting( 'min_saving', 0 );
        Attachment_Meta::set( $id, array( 'files' => array( basename( $path ) => array( 'size' => filesize( $path ), 'original' => Attachment_Meta::error_entry( 'boom' ) ) ) ) );
        $result = Converter::convert_next_file( $id, array( 'formats' => array() ) );
        $this->assertWPError( $result );
        $this->assertSame( 'wzio_original_failed', $result->get_error_code() );
        $retry = Converter::convert_next_file( $id, array( 'formats' => array(), 'originals_explicit' => true ) );
        $this->assertNotWPError( $retry );
        $record = Attachment_Meta::get( $id )['files'][ basename( $path ) ];
        $this->assertArrayNotHasKey( 'error', $record['original'] );
        $this->assertSame( 1, Attachment_Meta::count_retryable( array( 'files' => array( 'x' => array( 'original' => array( 'error' => 'e' ) ) ) ) ) );
    }

    public function test_existing_backup_is_kept_when_records_are_gone_and_the_source_changed() {
        list( $id, $path ) = $this->attachment();
        $relative = Original_Backups::relative( $path );
        $entry = Original_Backups::locked( $id, static function () use ( $id, $path ) { return Original_Backups::ensure( $id, $path ); } );
        $backup = Original_Backups::verified_path( $relative, $entry );
        $this->assertNotWPError( $backup );
        $pristine = hash_file( 'sha256', $backup );
        Original_Backups::save( $id, array() );
        $image = imagecreatetruecolor( 640, 480 );
        imagefill( $image, 0, 0, 0x224466 );
        imagejpeg( $image, $path, 60 );
        $again = Original_Backups::locked( $id, static function () use ( $id, $path ) { return Original_Backups::ensure( $id, $path ); } );
        $this->assertWPError( $again );
        $this->assertSame( 'wzio_backup_mismatch', $again->get_error_code() );
        $this->assertSame( $pristine, hash_file( 'sha256', $backup ), 'A pristine backup must never be deleted or replaced.' );
        $this->assertSame( array(), glob( $backup . '.partial' ) );
    }

    public function test_unscaled_original_is_not_processed_and_metadata_does_not_reenter() {
        list( $id, $path ) = $this->attachment();
        $unscaled = dirname( $path ) . '/unscaled-' . basename( $path );
        copy( $path, $unscaled );
        $hash = hash_file( 'sha256', $unscaled );
        $meta = wp_get_attachment_metadata( $id );
        $meta['original_image'] = basename( $unscaled );
        update_post_meta( $id, '_wp_attachment_metadata', $meta );
        $this->setting( 'compress_originals', true );
        $this->setting( 'resize_existing_originals', true );
        $this->setting( 'maximum_image_dimension', 320 );
        $this->setting( 'original_jpeg_quality', 65 );
        $runs = 0;
        $callback = static function () use ( &$runs ) { ++$runs; };
        add_action( 'wzio_attachment_converted', $callback );
        try {
            $result = Converter::convert_attachment( $id, array( 'formats' => array() ) );
            $this->assertNotWPError( $result );
            $this->assertSame( 1, $runs );
            $this->assertSame( 320, wp_get_attachment_metadata( $id )['width'] );
            $this->assertSame( $hash, hash_file( 'sha256', $unscaled ) );
            $this->assertCount( 1, Original_Backups::get( $id ) );
        } finally {
            remove_action( 'wzio_attachment_converted', $callback );
            wp_delete_file( $unscaled );
        }
    }

}
