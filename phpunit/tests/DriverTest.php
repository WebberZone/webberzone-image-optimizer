<?php
/**
 * Tests for the driver base class.
 *
 * @package WebberZone\Image_Optimizer
 */

use WebberZone\Image_Optimizer\Capabilities;

/**
 * The atomic write that every driver shares.
 */
class DriverTest extends WP_UnitTestCase
{

    /**
     * Working directory for a test.
     *
     * @var string
     */
    private $base = '';

    /**
     * Set up.
     */
    public function set_up()
    {
        parent::set_up();

        if (! Capabilities::supports('webp') ) {
            $this->markTestSkipped('This server cannot encode WebP.');
        }

        $this->base = trailingslashit(get_temp_dir()) . 'wzio-driver-' . wp_generate_password(8, false);

        wp_mkdir_p($this->base);
    }

    /**
     * Tear down.
     */
    public function tear_down()
    {
        if ('' !== $this->base && is_dir($this->base) ) {
            // Restore any permissions a test tightened, then remove the tree.
            foreach ( array( $this->base . '/parent', $this->base ) as $dir ) {
                if (is_dir($dir) ) {
                    chmod($dir, 0777); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
                }
            }

            $this->remove_tree($this->base);
        }

        parent::tear_down();
    }

    /**
     * Recursively delete a directory.
     *
     * @param  string $dir Directory path.
     * @return void
     */
    private function remove_tree( $dir )
    {
        foreach ( (array) glob($dir . '/*') as $item ) {
            if (is_dir($item) ) {
                $this->remove_tree($item);
            } else {
                wp_delete_file($item);
            }
        }

        @rmdir($dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
    }

    /**
     * Write the bundled probe image somewhere and return its path.
     *
     * @param  string $dir Directory to write into.
     * @return string Absolute path.
     */
    private function write_source( $dir )
    {
        wp_mkdir_p($dir);

        $path   = trailingslashit($dir) . 'source.png';
        $binary = base64_decode(Capabilities::PROBE_IMAGE, true); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

     // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents($path, $binary);

        return $path;
    }

    /**
     * Get a working driver.
     *
     * @return \WebberZone\Image_Optimizer\Drivers\Driver Driver.
     */
    private function driver()
    {
        return Capabilities::get_driver('webp');
    }

    /**
     * Conversion works when only the destination directory itself is writable.
     *
     * The temporary file has to be created inside that directory. Building the
     * path by concatenating a directory with no trailing slash puts it beside
     * the directory instead, which fails outright wherever the parent is locked
     * down and silently writes to the wrong place wherever it is not.
     */
    public function test_conversion_works_when_the_parent_directory_is_read_only()
    {
        $parent = $this->base . '/parent';
        $child  = $parent . '/child';

        $source = $this->write_source($child);

        chmod($parent, 0555); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
        clearstatcache();

        if (is_writable($parent) ) {
            $this->markTestSkipped('This process can write to a read-only directory, so the guard cannot be exercised.');
        }

        $destination = $child . '/source.png.webp';
        $result      = $this->driver()->convert($source, $destination, 'webp', array( 'quality' => 70 ));

        chmod($parent, 0777); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod

        $this->assertTrue($result, is_wp_error($result) ? $result->get_error_message() : '');
        $this->assertFileExists($destination);
        $this->assertGreaterThan(0, filesize($destination));
    }

    /**
     * Nothing is left beside the destination directory.
     */
    public function test_no_stray_files_are_written_next_to_the_directory()
    {
        $child  = $this->base . '/child';
        $source = $this->write_source($child);

        $before = glob($this->base . '/*');

        $result = $this->driver()->convert($source, $child . '/source.png.webp', 'webp', array( 'quality' => 70 ));

        $this->assertTrue($result, is_wp_error($result) ? $result->get_error_message() : '');
        $this->assertSame($before, glob($this->base . '/*'));
    }

    /**
     * A failed encode leaves no partial file at the destination.
     */
    public function test_a_failed_encode_leaves_no_partial_file()
    {
        $child       = $this->base . '/child';
        $destination = $child . '/broken.png.webp';

        $this->write_source($child);

        // A source that is not an image at all.
        $bad = trailingslashit($child) . 'not-an-image.png';

     // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents($bad, 'this is not an image');

        $result = $this->driver()->convert($bad, $destination, 'webp', array( 'quality' => 70 ));

        $this->assertWPError($result);
        $this->assertFileDoesNotExist($destination);
    }
}
