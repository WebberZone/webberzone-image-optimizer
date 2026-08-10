<?php
/**
 * Attachment lifecycle integration.
 *
 * @package WebberZone\Image_Optimizer
 */

namespace WebberZone\Image_Optimizer;

use WebberZone\Image_Optimizer\Util\Hook_Registry;

if ( ! defined( 'WPINC' ) ) {
	exit;
}

/**
 * Keeps the sidecars in step with the attachments they belong to.
 *
 * @since 0.9.0
 */
class Attachment_Hooks {

	/**
	 * Constructor.
	 *
	 * @since 0.9.0
	 */
	public function __construct() {
		// Runs after an upload generates its sub-sizes, and again after an edit,
		// a restore, or a thumbnail regeneration replaces them.
		Hook_Registry::add_filter( 'wp_update_attachment_metadata', array( $this, 'on_metadata_updated' ), 9999, 2 );

		Hook_Registry::add_action( 'delete_attachment', array( $this, 'on_delete_attachment' ) );
	}

	/**
	 * Convert or queue an attachment whose files have just changed.
	 *
	 * The metadata is passed straight through: the sidecars live beside the
	 * originals and are never referenced from the attachment metadata, so there
	 * is nothing here to add to it.
	 *
	 * @since 0.9.0
	 *
	 * @param mixed $data          Attachment metadata about to be stored.
	 * @param int   $attachment_id Attachment ID.
	 * @return array<string, mixed> Unmodified metadata.
	 */
	public function on_metadata_updated( $data, $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		if ( ! is_array( $data ) || ! Converter::is_convertible_attachment( $attachment_id ) ) {
			return $data;
		}

		if ( ! \wzio_get_option( 'convert_on_upload', true ) ) {
			Queue::add( array( $attachment_id ) );
			Processor::maybe_schedule();

			return $data;
		}

		Converter::convert_attachment( $attachment_id, array(), $data );

		return $data;
	}

	/**
	 * Remove the sidecars and the queue row when an attachment is deleted.
	 *
	 * This fires before the original files are removed, which is exactly when
	 * the sidecar paths can still be derived from the attachment metadata.
	 *
	 * @since 0.9.0
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function on_delete_attachment( $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		Converter::delete_sidecars( $attachment_id );
		Queue::remove( $attachment_id );
	}
}
