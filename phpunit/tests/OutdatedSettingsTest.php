<?php
/**
 * Tests for settings fingerprints and outdated selection.
 *
 * @package WebberZone\Image_Optimizer
 */

use WebberZone\Image_Optimizer\Attachment_Meta;
use WebberZone\Image_Optimizer\Converter;
use WebberZone\Image_Optimizer\Scanner;

/**
 * Covers the per-copy fingerprint, the schema migration and outdated detection.
 */
class OutdatedSettingsTest extends WP_UnitTestCase
{
    /**
     * Arguments with a given WebP quality.
     *
     * @param int $quality WebP quality.
     * @return array<string, mixed>
     */
    private function args( int $quality ): array
    {
        return array(
            'formats'     => array( 'webp' ),
            'strip'       => true,
            'effort_webp' => 6,
            'effort_avif' => 4,
            'quality'     => array(
                'webp' => $quality,
                'avif' => 50,
            ),
            'lossless'    => true,
            'png_lossy'   => 95,
        );
    }

    /**
     * A quality change alters the fingerprint, an unrelated setting does not.
     */
    public function test_fingerprint_tracks_encoding_settings(): void
    {
        $a = Converter::fingerprint( 'webp', $this->args( 82 ), 'image/jpeg' );

        $this->assertNotSame( $a, Converter::fingerprint( 'webp', $this->args( 70 ), 'image/jpeg' ) );

        $other              = $this->args( 82 );
        $other['png_lossy'] = 60;
        $other['min_saving'] = 20;
        $this->assertSame( $a, Converter::fingerprint( 'webp', $other, 'image/jpeg' ) );
    }

    /**
     * Lossless PNG ignores WebP quality but follows the lossy fallback.
     */
    public function test_lossless_png_fingerprint_ignores_quality(): void
    {
        $a = Converter::fingerprint( 'webp', $this->args( 82 ), 'image/png' );

        $this->assertSame( $a, Converter::fingerprint( 'webp', $this->args( 70 ), 'image/png' ) );

        $changed              = $this->args( 82 );
        $changed['png_lossy'] = 60;
        $this->assertNotSame( $a, Converter::fingerprint( 'webp', $changed, 'image/png' ) );
    }

    /**
     * Records with no fingerprint are unknown, never outdated.
     */
    public function test_missing_fingerprint_is_not_outdated(): void
    {
        $record = array(
            'files' => array(
                'a.jpg' => array(
                    'size' => 1000,
                    'webp' => Attachment_Meta::converted_entry( 400, 82 ),
                ),
            ),
        );

        $this->assertFalse( Attachment_Meta::is_outdated( $record, $this->args( 70 ), 'image/jpeg' ) );
    }

    /**
     * A differing fingerprint is outdated and a matching one is not.
     */
    public function test_differing_fingerprint_is_outdated(): void
    {
        $fp     = Converter::fingerprint( 'webp', $this->args( 82 ), 'image/jpeg' );
        $record = array(
            'files' => array(
                'a.jpg' => array(
                    'size' => 1000,
                    'webp' => Attachment_Meta::converted_entry( 400, 82, false, $fp ),
                ),
            ),
        );

        $this->assertFalse( Attachment_Meta::is_outdated( $record, $this->args( 82 ), 'image/jpeg' ) );
        $this->assertTrue( Attachment_Meta::is_outdated( $record, $this->args( 70 ), 'image/jpeg' ) );
    }

    /**
     * A schema 2 record still reads and gains the fingerprint on rewrite.
     */
    public function test_schema_two_record_migrates(): void
    {
        $id = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );

        update_post_meta(
            $id,
            Attachment_Meta::META_KEY,
            array(
                'v'       => 2,
                'updated' => 1,
                'files'   => array(
                    'a.jpg' => array(
                        'size' => 1000,
                        'webp' => array( 'bytes' => 400 ),
                    ),
                ),
            )
        );

        $record = Attachment_Meta::get( $id );
        $this->assertSame( 400, $record['files']['a.jpg']['webp']['bytes'] );

        Attachment_Meta::set( $id, $record );
        $this->assertSame( Attachment_Meta::SCHEMA, Attachment_Meta::get( $id )['v'] );
    }

    /**
     * The scanner selects only attachments whose fingerprint differs.
     */
    public function test_scanner_selects_outdated_attachments(): void
    {
        $args  = Converter::get_args();
        $stale = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );
        $fresh = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );
        $plain = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );

        $current = Converter::fingerprint( 'webp', $args, 'image/jpeg' );

        Attachment_Meta::set( $stale, array( 'files' => array( 'a.jpg' => array( 'size' => 1000, 'webp' => Attachment_Meta::converted_entry( 400, 82, false, 'deadbeef' ) ) ) ) );
        Attachment_Meta::set( $fresh, array( 'files' => array( 'a.jpg' => array( 'size' => 1000, 'webp' => Attachment_Meta::converted_entry( 400, 82, false, $current ) ) ) ) );
        Attachment_Meta::set( $plain, array( 'files' => array( 'a.jpg' => array( 'size' => 1000, 'webp' => Attachment_Meta::converted_entry( 400, 82 ) ) ) ) );

        $page = Scanner::get_outdated_ids( 500, 0 );

        $this->assertContains( $stale, $page['ids'] );
        $this->assertNotContains( $fresh, $page['ids'] );
        $this->assertNotContains( $plain, $page['ids'] );
    }

    /**
     * Per-format byte totals are summed across the library.
     */
    public function test_format_byte_totals_are_aggregated(): void
    {
        $id = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );

        Attachment_Meta::set(
            $id,
            array(
                'files' => array(
                    'a.jpg' => array(
                        'size' => 1000,
                        'webp' => Attachment_Meta::converted_entry( 400 ),
                        'avif' => Attachment_Meta::converted_entry( 300 ),
                    ),
                ),
            )
        );

        Scanner::flush_counts();
        $totals = Scanner::get_byte_totals();

        $this->assertSame( 400, $totals['formats']['webp'] );
        $this->assertSame( 300, $totals['formats']['avif'] );
        $this->assertSame( 700, $totals['copies'] );
    }

    public function test_effective_retry_quality_is_part_of_fingerprint(): void
    {
        $args = $this->args(82);
        $normal = Converter::fingerprint('webp', $args, 'image/jpeg');
        $retry = Converter::fingerprint('webp', $args, 'image/jpeg', 70, true);
        $this->assertNotSame($normal, $retry);
        $record = array('files' => array('a.jpg' => array('webp' => Attachment_Meta::converted_entry(400, 70, true, $retry))));
        $this->assertFalse(Attachment_Meta::is_outdated($record, $args, 'image/jpeg'));
        $this->assertTrue(Attachment_Meta::is_outdated($record, $this->args(75), 'image/jpeg'));
    }

    public function test_rejected_encode_preserves_the_surviving_fingerprint(): void
    {
        $source = wp_tempnam('wzio-source');
        $copy = wp_tempnam('wzio-copy');
        file_put_contents($source, str_repeat('a', 1000));
        file_put_contents($copy, str_repeat('b', 100));
        $method = new ReflectionMethod(Converter::class, 'resolve_sidecar');
        $method->setAccessible(true);
        try {
            $entry = $method->invoke(null, $source, $copy, 900, false, 90, 60, false, false, 'new', 'old');
            $this->assertSame('old', $entry['fp']);
            $this->assertSame(60, $entry['quality']);
        } finally {
            wp_delete_file($source);
            wp_delete_file($copy);
        }
    }

    public function test_outdated_conversion_leaves_unknown_and_unrequested_copies_alone(): void
    {
        $source = wp_tempnam('wzio-source');
        file_put_contents($source, base64_decode(\WebberZone\Image_Optimizer\Capabilities::PROBE_IMAGE));
        $existing = array('webp' => Attachment_Meta::converted_entry(100), 'avif' => Attachment_Meta::converted_entry(80));
        try {
            $args = $this->args(70);
            $args['outdated'] = true;
            $result = Converter::convert_file($source, $args, $existing);
            $this->assertSame($existing['webp'], $result['webp']);
            $this->assertSame($existing['avif'], $result['avif']);
        } finally {
            wp_delete_file($source);
        }
    }

    public function test_scanner_respects_format_overrides_and_advances_empty_pages(): void
    {
        $unknown = self::factory()->attachment->create(array('post_mime_type' => 'image/jpeg'));
        $stale = self::factory()->attachment->create(array('post_mime_type' => 'image/jpeg'));
        Attachment_Meta::set($unknown, array('files' => array('a.jpg' => array('webp' => Attachment_Meta::converted_entry(400)))));
        Attachment_Meta::set($stale, array('files' => array('a.jpg' => array('avif' => Attachment_Meta::converted_entry(300, 50, false, 'outdated')))));
        $page = Scanner::get_outdated_ids(1, $unknown - 1, array('formats' => array('avif')));
        $this->assertSame(array(), $page['ids']);
        $this->assertSame($unknown, $page['last']);
        $this->assertFalse($page['exhausted']);
        $page = Scanner::get_outdated_ids(1, $page['last'], array('formats' => array('avif')));
        $this->assertSame(array($stale), $page['ids']);
        $this->assertSame(array(), Scanner::get_outdated_ids(1, $unknown, array('formats' => array('webp')))['ids']);
    }

    public function test_queue_retains_selective_mode(): void
    {
        \WebberZone\Image_Optimizer\Database::install();
        $id = self::factory()->attachment->create(array('post_mime_type' => 'image/jpeg'));
        \WebberZone\Image_Optimizer\Queue::add(array($id), true, false, true);
        global $wpdb;
        $table = \WebberZone\Image_Optimizer\Database::get_table();
        $this->assertSame(2, (int) $wpdb->get_var($wpdb->prepare("SELECT reencode FROM {$table} WHERE attachment_id = %d", $id)));
        \WebberZone\Image_Optimizer\Queue::add(array($id), true, true);
        $this->assertSame(1, (int) $wpdb->get_var($wpdb->prepare("SELECT reencode FROM {$table} WHERE attachment_id = %d", $id)));
    }
    public function test_queue_keeps_pending_full_reencode_over_outdated(): void
    {
        \WebberZone\Image_Optimizer\Database::install();
        $id = self::factory()->attachment->create(array('post_mime_type' => 'image/jpeg'));
        \WebberZone\Image_Optimizer\Queue::add(array($id), true, true);
        \WebberZone\Image_Optimizer\Queue::add(array($id), true, false, true);
        global $wpdb;
        $table = \WebberZone\Image_Optimizer\Database::get_table();
        $this->assertSame(1, (int) $wpdb->get_var($wpdb->prepare("SELECT reencode FROM {$table} WHERE attachment_id = %d", $id)));
    }

    public function test_outdated_uses_each_file_type(): void
    {
        $args = $this->args(82);
        $fp = Converter::fingerprint('webp', $args, 'image/jpeg', 82);
        $record = array('files' => array('a-150x150.jpg' => array('webp' => Attachment_Meta::converted_entry(100, 82, false, $fp))));
        $this->assertFalse(Attachment_Meta::is_outdated($record, $args, 'image/png'));
    }

    public function test_outdated_conversion_processes_formats_never_attempted(): void
    {
        $temp = wp_tempnam('wzio-source');
        $source = $temp . '.png';
        file_put_contents($source, base64_decode(\WebberZone\Image_Optimizer\Capabilities::PROBE_IMAGE));
        try {
            $args = $this->args(70);
            $args['outdated'] = true;
            $result = Converter::convert_file($source, $args, array());
            $this->assertArrayHasKey('webp', $result);
        } finally {
            wp_delete_file($temp);
            wp_delete_file($source);
            wp_delete_file(\WebberZone\Image_Optimizer\Util\Helpers::sidecar_path($source, 'webp'));
        }
    }
}
