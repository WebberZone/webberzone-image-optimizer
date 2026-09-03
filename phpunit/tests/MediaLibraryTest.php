<?php
/**
 * Tests for the media library status filter.
 *
 * @package WebberZone\Image_Optimizer
 */

use WebberZone\Image_Optimizer\Admin\Media_Library;
use WebberZone\Image_Optimizer\Database;
use WebberZone\Image_Optimizer\Queue;

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
	 * Prepare an upload screen, queue table and media library instance without hooks.
	 */
	public function set_up() {
		parent::set_up();

		Database::install();
		Queue::clear();

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
		Queue::clear();

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
		$this->assertStringContainsString( 'value="skipped"', $output );
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
	 * Every status returns the matching eligible attachments and preserves other filters.
	 */
	public function test_status_filters_return_exact_attachment_ids() {
		$attachments = $this->seed_attachments();

		$this->assertSame(
			array( $attachments['optimized'] ),
			$this->get_filtered_attachment_ids( 'optimized' )
		);
		$this->assertSame(
			array( $attachments['unoptimized'], $attachments['pending'], $attachments['processing'] ),
			$this->get_filtered_attachment_ids( 'unoptimized' )
		);
		$this->assertSame(
			array( $attachments['skipped'] ),
			$this->get_filtered_attachment_ids( 'skipped' )
		);
		$this->assertSame(
			array( $attachments['failed'] ),
			$this->get_filtered_attachment_ids( 'failed' )
		);
	}

	/**
	 * Hiding the status column does not disable an active URL filter.
	 */
	public function test_hidden_status_column_does_not_disable_filtering() {
		$attachments = $this->seed_attachments();

		update_user_option( get_current_user_id(), 'manageuploadcolumnshidden', array( 'wzio' ) );

		$this->assertSame(
			array( $attachments['failed'] ),
			$this->get_filtered_attachment_ids( 'failed' )
		);
	}

	/**
	 * Seed eligible and unsupported attachments across every queue state.
	 *
	 * @return array<string, int> Attachment IDs keyed by scenario.
	 */
	private function seed_attachments(): array {
		$attachments = array(
			'optimized'            => $this->create_attachment( 'optimized.jpg', 'image/jpeg' ),
			'unoptimized'          => $this->create_attachment( 'unoptimized.jpg', 'image/jpeg' ),
			'skipped'              => $this->create_attachment( 'skipped.jpg', 'image/jpeg' ),
			'failed'               => $this->create_attachment( 'failed.jpg', 'image/jpeg' ),
			'pending'              => $this->create_attachment( 'pending.jpg', 'image/jpeg' ),
			'processing'           => $this->create_attachment( 'processing.jpg', 'image/jpeg' ),
			'unsupported'          => $this->create_attachment( 'unsupported.pdf', 'application/pdf' ),
			'optimized_png'        => $this->create_attachment( 'optimized.png', 'image/png' ),
			'optimized_next_month' => $this->create_attachment( 'optimized-next-month.jpg', 'image/jpeg', '2026-02-15 12:00:00' ),
		);

		$this->set_queue_status( $attachments['optimized'], Queue::DONE );
		$this->set_queue_status( $attachments['skipped'], Queue::SKIPPED );
		$this->set_queue_status( $attachments['failed'], Queue::FAILED );
		$this->set_queue_status( $attachments['pending'], Queue::PENDING );
		$this->set_queue_status( $attachments['processing'], Queue::PROCESSING );
		$this->set_queue_status( $attachments['optimized_png'], Queue::DONE );
		$this->set_queue_status( $attachments['optimized_next_month'], Queue::DONE );

		return $attachments;
	}

	/**
	 * Create an attachment fixture.
	 *
	 * @param string $file      Fixture filename.
	 * @param string $mime_type Attachment MIME type.
	 * @param string $date      Attachment date.
	 * @return int Attachment ID.
	 */
	private function create_attachment( string $file, string $mime_type, string $date = '2026-01-15 12:00:00' ): int {
		return (int) self::factory()->attachment->create(
			array(
				'file'           => $file,
				'post_title'     => $file,
				'post_mime_type' => $mime_type,
				'post_status'    => 'inherit',
				'post_date'      => $date,
				'post_date_gmt'  => $date,
			)
		);
	}

	/**
	 * Put an attachment into a queue state using the public queue API.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $status        Queue status.
	 * @return void
	 */
	private function set_queue_status( int $attachment_id, string $status ): void {
		Queue::add( array( $attachment_id ), true );

		if ( Queue::PROCESSING === $status ) {
			Queue::claim_attachment( $attachment_id );
		} elseif ( Queue::PENDING !== $status ) {
			$row_id = Queue::get_id( $attachment_id );

			if ( Queue::FAILED === $status ) {
				for ( $attempt = 0; $attempt < Queue::MAX_ATTEMPTS; ++$attempt ) {
					Queue::complete( $row_id, Queue::FAILED );
				}
			} else {
				Queue::complete( $row_id, $status );
			}
		}

		$this->assertSame( $status, Queue::get_status( $attachment_id ) );
	}

	/**
	 * Run a main media query with the requested status and existing MIME/date filters.
	 *
	 * @param string $status Requested status.
	 * @return array<int, int> Matching attachment IDs.
	 */
	private function get_filtered_attachment_ids( string $status ): array {
		$_GET['wzio_optimization_status'] = $status;

		$query = new WP_Query();
		$query->set( 'post_type', 'attachment' );
		$query->set( 'post_status', 'inherit' );
		$query->set( 'post_mime_type', 'image/jpeg' );
		$query->set( 'm', 202601 );
		$query->set( 'fields', 'ids' );
		$query->set( 'orderby', 'ID' );
		$query->set( 'order', 'ASC' );
		$query->set( 'posts_per_page', -1 );
		$query->set( 'no_found_rows', true );
		$query->set( 'suppress_filters', false );

		$GLOBALS['wp_the_query'] = $query;

		$this->media_library->filter_by_status( $query );
		add_filter( 'posts_where', array( $this->media_library, 'filter_attachments_by_status' ), 10, 2 );

		try {
			$attachment_ids = $query->get_posts();
		} finally {
			remove_filter( 'posts_where', array( $this->media_library, 'filter_attachments_by_status' ), 10 );
		}

		return array_map( 'intval', $attachment_ids );
	}
}
