<?php
/**
 * Core image editor with a clean regeneration source.
 *
 * @package WebberZone\Image_Optimizer
 */

namespace WebberZone\Image_Optimizer\Drivers;

if ( ! defined( 'WPINC' ) ) {
	exit;
}

require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
require_once ABSPATH . WPINC . '/class-wp-image-editor-imagick.php';

/**
 * Read the immutable backup without changing core's output destination.
 *
 * @since 1.2.0
 */
class Backup_Imagick extends \WP_Image_Editor_Imagick {

	/**
	 * Save through core, noting the written file for regeneration detection.
	 *
	 * @param \Imagick    $image     Image.
	 * @param string|null $filename  Destination.
	 * @param string|null $mime_type Mime type.
	 * @return array|\WP_Error Saved file data.
	 */
	protected function _save( $image, $filename = null, $mime_type = null ) { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore
		$saved = parent::_save( $image, $filename, $mime_type );
		if ( is_array( $saved ) && ! empty( $saved['path'] ) ) {
			\WebberZone\Image_Optimizer\Original_Optimizer::note_written( $saved['path'] );
		}
		return $saved;
	}

	/**
	 * Load clean pixels and restore the served path before any save.
	 *
	 * @return true|\WP_Error Load result.
	 */
	public function load() {
		$served     = $this->file;
		$this->file = \WebberZone\Image_Optimizer\Original_Optimizer::editor_source( $served );
		try {
			return parent::load();
		} finally {
			$this->file = $served;
		}
	}
}
