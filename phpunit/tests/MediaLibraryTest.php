<?php
/**
 * Tests for the media library status filter.
 *
 * @package WebberZone\Image_Optimizer
 */

use WebberZone\Image_Optimizer\Admin\Media_Library;
use WebberZone\Image_Optimizer\Attachment_Meta;
use WebberZone\Image_Optimizer\Database;

/**
 * Media library filtering controls and query clauses.
 */
class MediaLibraryTest extends WP_UnitTestCase {

	/**
	 * Media library integration under test.
	 *
	 * @var Media_Library
	 */
	private $media_library;

	/**
	 * Original admin page global.
	 *
	 * @var string|null
	 */
	private $original_pagenow;

	/**
	 * Original main query global.
	 *
	 * @var WP_Query|null
	 */
	private $original_main_query;

	/**
	 * Prepare an upload screen and a media library instance without hooks.
	 */
	public function set_up() {
		parent::set_up();

		$reflection          = new ReflectionClass( Media_Library::class );
		$this->media_library = $reflection->newInstanceWithoutConstructor();

		$this->original_pagenow    = $GLOBALS['pagenow'] ?? null;
		$this->original_main_query = $GLOBALS['wp_the_query'] ?? null;

		$GLOBALS['pagenow'] = 'upload.php';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'upload' );
	}

	/**
	 * Restore globals changed by a test.
	 */
	public function tear_down() {
		unset( $_GET['wzio_optimization_status'] );
		delete_user_option( get_current_user_id(), 'manageuploadcolumnshidden' );

		if ( null === $this->original_pagenow ) {
			unset( $GLOBALS['pagenow'] );
		} else {
			$GLOBALS['pagenow'] = $this->original_pagenow;
		}

		$GLOBALS['wp_the_query'] = $this->original_main_query;

		set_current_screen( 'front' );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * The list view renders every status and preserves the selection.
	 */
	public function test_status_dropdown_renders_on_the_media_list_view() {
		$_GET['wzio_optimization_status'] = 'failed';

		ob_start();
		$this->media_library->render_status_filter( 'attachment', 'bar' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="wzio_optimization_status"', $output );
		$this->assertStringContainsString( 'All optimization statuses', $output );
		$this->assertStringContainsString( 'Not yet optimized', $output );
		$this->assertStringContainsString( 'value="failed" selected', $output );
	}

	/**
	 * The dropdown stays hidden when its status column is hidden.
	 */
	public function test_status_dropdown_requires_a_visible_status_column() {
		update_user_option( get_current_user_id(), 'manageuploadcolumnshidden', array( 'wzio' ) );

		ob_start();
		$this->media_library->render_status_filter( 'attachment', 'bar' );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * Optimized filtering is added without replacing another meta query.
	 */
	public function test_optimized_filter_composes_with_existing_query_filters() {
		$existing_meta_query = array(
			array(
				'key'   => '_example_key',
				'value' => 'example-value',
			),
		);
		$query               = $this->get_filtered_query( 'optimized', $existing_meta_query );
		$meta_query          = $query->get( 'meta_query' );

		$this->assertSame( 'AND', $meta_query['relation'] );
		$this->assertSame( $existing_meta_query, $meta_query[0] );
		$this->assertSame( Attachment_Meta::META_KEY, $meta_query[1]['key'] );
		$this->assertSame( 'EXISTS', $meta_query[1]['compare'] );
		$this->assertSame( 'image/jpeg', $query->get( 'post_mime_type' ) );
		$this->assertSame( 202601, $query->get( 'm' ) );
	}

	/**
	 * Unoptimized filtering selects attachments without conversion metadata.
	 */
	public function test_unoptimized_filter_uses_a_not_exists_meta_query() {
		$query      = $this->get_filtered_query( 'unoptimized' );
		$meta_query = $query->get( 'meta_query' );

		$this->assertSame( Attachment_Meta::META_KEY, $meta_query[0]['key'] );
		$this->assertSame( 'NOT EXISTS', $meta_query[0]['compare'] );
	}

	/**
	 * Failed filtering adds a queue-table subquery to the existing WHERE clause.
	 */
	public function test_failed_filter_uses_the_queue_table() {
		$query = $this->get_filtered_query( 'failed' );
		$where = $this->media_library->filter_failed_attachments( ' WHERE 1=1', $query );

		$this->assertStringStartsWith( ' WHERE 1=1 AND ', $where );
		$this->assertStringContainsString( Database::get_table(), $where );
		$this->assertStringContainsString( 'attachment_id', $where );
		$this->assertStringContainsString( "status = 'failed'", $where );
	}

	/**
	 * Build a main media query and apply a requested status.
	 *
	 * @param string                    $status     Requested status.
	 * @param array<int|string, mixed> $meta_query Existing meta query.
	 * @return WP_Query Filtered query.
	 */
	private function get_filtered_query( $status, $meta_query = array() ) {
		$_GET['wzio_optimization_status'] = $status;

		$query = new WP_Query();
		$query->set( 'post_type', 'attachment' );
		$query->set( 'post_mime_type', 'image/jpeg' );
		$query->set( 'm', 202601 );

		if ( ! empty( $meta_query ) ) {
			$query->set( 'meta_query', $meta_query );
		}

		$GLOBALS['wp_the_query'] = $query;

		$this->media_library->filter_by_status( $query );

		return $query;
	}
}
