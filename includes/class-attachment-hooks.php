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
 * @since 1.0.0
 */
class Attachment_Hooks {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		Hook_Registry::add_filter( 'wp_calculate_image_srcset', array( Original_Optimizer::class, 'filter_srcset' ), 10, 5 );
		Hook_Registry::add_filter( 'wp_image_editors', array( Original_Optimizer::class, 'image_editors' ) );
		Hook_Registry::add_filter( 'big_image_size_threshold', array( $this, 'maximum_image_dimension' ), 10, 4 );
		// Runs after uploads and any operation that regenerates attachment files.
		Hook_Registry::add_filter( 'wp_update_attachment_metadata', array( $this, 'on_metadata_updated' ), 9999, 2 );

		Hook_Registry::add_filter( 'pre_delete_attachment', array( Original_Optimizer::class, 'prepare_delete' ), 10, 2 );
		Hook_Registry::add_action( 'delete_attachment', array( $this, 'on_delete_attachment' ) );
		Hook_Registry::add_action( 'deleted_post', array( Original_Optimizer::class, 'release_delete_lock' ) );
		Hook_Registry::add_action( 'add_attachment', array( Scanner::class, 'flush_counts' ) );
	}

	/**
	 * Apply the configured upload dimension without replacing the default filter value.
	 *
	 * @since 1.2.0
	 * @param int|false $threshold Incoming threshold.
	 * @param array     $imagesize Image dimensions.
	 * @param string    $file Upload path.
	 * @param int       $attachment_id Attachment ID.
	 * @return int|false Upload threshold.
	 */
	public function maximum_image_dimension( $threshold, $imagesize = array(), $file = '', $attachment_id = 0 ) {
		unset( $imagesize, $file );
		if ( $attachment_id && wp_get_attachment_metadata( (int) $attachment_id ) ) {
			return $threshold;
		}
		if ( \wzio_get_option( 'disable_image_scaling', false ) ) {
			return false;
		}

		$dimension = \wzio_get_option( 'maximum_image_dimension', 0 );
		$dimension = is_scalar( $dimension ) ? absint( $dimension ) : 0;

		return $dimension > 0 ? $dimension : $threshold;
	}

	/**
	 * Convert or queue changed files without modifying attachment metadata.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $data          Attachment metadata about to be stored.
	 * @param int   $attachment_id Attachment ID.
	 * @return array<string, mixed> Unmodified metadata.
	 */
	public function on_metadata_updated( $data, $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		if ( Original_Optimizer::$updating_metadata || ! is_array( $data ) || ! Converter::is_convertible_attachment( $attachment_id ) ) {
			return $data;
		}

		Original_Optimizer::locked(
			$attachment_id,
			static function () use ( $attachment_id, $data ) {
				Original_Optimizer::retire_regenerated( $attachment_id, $data );
			}
		);

		if ( ! \wzio_get_option( 'convert_on_upload', true ) ) {
			Queue::add( array( $attachment_id ) );
			Processor::maybe_schedule();

			return $data;
		}

		Converter::convert_attachment( $attachment_id, array(), $data );

		return Original_Optimizer::reconcile_metadata( $attachment_id, $data );
	}

	/**
	 * Remove sidecars and the queue row before attachment files disappear.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function on_delete_attachment( $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		Original_Optimizer::delete_backups( $attachment_id );
		Converter::delete_sidecars( $attachment_id );
		Queue::remove( $attachment_id );
		Scanner::flush_counts();
	}
}
