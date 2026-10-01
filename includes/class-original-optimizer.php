<?php
/**
 * Reversible original-file optimization.
 *
 * @package WebberZone\Image_Optimizer
 */

namespace WebberZone\Image_Optimizer;

use WebberZone\Image_Optimizer\Drivers\Original_Driver;
use WebberZone\Image_Optimizer\Frontend\Resolver;
use WebberZone\Image_Optimizer\Util\Helpers;

if ( ! defined( 'WPINC' ) ) {
	exit;
}

/**
 * Coordinates source mutations and their recovery metadata.
 *
 * @since 1.2.0
 */
class Original_Optimizer {

	/**
	 * Metadata writes currently in progress.
	 *
	 * @var bool
	 */
	public static $updating_metadata = false;

	/**
	 * Locks retained until core finishes deleting attachments.
	 *
	 * @var array<string, resource>
	 */
	private static $deleting = array();

	/**
	 * Hashes of files saved by core image editors this request, keyed by real path.
	 *
	 * @var array<string, string>
	 */
	private static $written = array();


	/**
	 * Run a conversion under the same lock as source replacement and restore.
	 *
	 * @param int      $id Attachment ID.
	 * @param callable $callback Conversion.
	 * @return mixed Conversion result.
	 */
	public static function locked( int $id, callable $callback ) {
		if ( isset( self::$deleting[ get_current_blog_id() . ':' . $id ] ) || ( ! \wzio_get_option( 'compress_originals', false ) && ! Original_Backups::get( $id ) ) ) {
			return $callback();
		}
		return Original_Backups::locked( $id, $callback );
	}

	/**
	 * Get effective settings for fingerprinting and processing.
	 *
	 * @return array Settings.
	 */
	public static function settings(): array {
		return array(
			'quality'    => max( 1, min( 100, (int) \wzio_get_option( 'original_jpeg_quality', 82 ) ) ),
			'strip'      => (bool) \wzio_get_option( 'strip_metadata', true ),
			'min_saving' => max( 0, min( 90, (int) \wzio_get_option( 'min_saving', 5 ) ) ),
			'png'        => (bool) \wzio_get_option( 'compress_png_originals', false ),
			'dimension'  => \wzio_get_option( 'resize_existing_originals', false ) && ! \wzio_get_option( 'disable_image_scaling', false ) ? absint( \wzio_get_option( 'maximum_image_dimension', 0 ) ) : 0,
		);
	}

	/**
	 * Optimize a served source before its sidecars are created.
	 *
	 * @param int    $id Attachment ID.
	 * @param string $path Served source.
	 * @return array Original result.
	 */
	public static function process_file( int $id, string $path ): array {
		if ( ! \wzio_get_option( 'compress_originals', false ) || get_post_meta( $id, '_wzio_originals_restored', true ) ) {
			return array();
		}
		$mime = wp_get_image_mime( $path );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
			return array( 'skip' => 'disabled' );
		}
		$args = self::settings();
		if ( 'image/png' === $mime && ! $args['png'] ) {
			return array( 'skip' => 'disabled' );
		}
		$capabilities = Capabilities::get_originals();
		if ( empty( $capabilities[ 'image/png' === $mime ? 'png' : 'jpeg' ] ) ) {
			return Attachment_Meta::skipped_entry( 'unsupported' );
		}
		$relative = Original_Backups::relative( $path );
		if ( '' === $relative ) {
			return Attachment_Meta::error_entry( __( 'The original is not a safe local upload.', 'webberzone-image-optimizer' ) );
		}
		self::retire_regenerated( $id, array() );
		$manifest = Original_Backups::get( $id );
		$previous = $manifest[ $relative ] ?? array();
		if ( 'processing' === ( $previous['state'] ?? '' ) ) {
			$backup = Original_Backups::verified_path( $relative, $previous );
			if ( is_wp_error( $backup ) ) {
				return Attachment_Meta::error_entry( $backup->get_error_message() );
			}
			$recovered = ( new Original_Driver() )->restore( $backup, $path );
			if ( is_wp_error( $recovered ) ) {
				return Attachment_Meta::error_entry( $recovered->get_error_message() );
			}
			self::invalidate_sidecars( $id, $path );
			foreach ( array( 'output_hash', 'fingerprint', 'after', 'skip', 'error' ) as $key ) {
				unset( $previous[ $key ] );
			}
			$previous['state']     = 'backed_up';
			$manifest[ $relative ] = $previous;
			if ( ! Original_Backups::save( $id, $manifest ) ) {
				return Attachment_Meta::error_entry( __( 'Interrupted optimization could not be recovered.', 'webberzone-image-optimizer' ) );
			}
		}

		if ( get_attached_file( $id ) !== $path ) {
			$args['dimension'] = 0;
		}
		$fingerprint = hash( 'sha256', (string) wp_json_encode( $args ) );
		if ( ! empty( $previous['resized']['to'] ) && get_attached_file( $id ) === $path ) {
			$current_limit = max( array_map( 'intval', $previous['resized']['to'] ) );
			if ( $current_limit > 0 ) {
				$args['dimension'] = $args['dimension'] > 0 ? min( $args['dimension'], $current_limit ) : $current_limit;
			}
		}

		$hash = Helpers::file_hash( $path );
		if ( isset( $previous['output_hash'] ) && $hash !== $previous['output_hash'] && 'backed_up' !== ( $previous['state'] ?? '' ) ) {
			return Attachment_Meta::error_entry( __( 'The source was changed outside the optimizer. Restore or review its backup before compressing again.', 'webberzone-image-optimizer' ) );
		}
		if ( ( $previous['fingerprint'] ?? '' ) === $fingerprint && ( $previous['output_hash'] ?? '' ) === $hash ) {
			return $previous;
		}
		$source = $path;
		if ( $previous ) {
			$source = Original_Backups::verified_path( $relative, $previous );
			if ( is_wp_error( $source ) ) {
				return Attachment_Meta::error_entry( $source->get_error_message() );
			}
		}
		$dims = wp_getimagesize( $source );
		if ( ! $dims || ! Helpers::can_allocate_image( (int) $dims[0], (int) $dims[1] ) ) {
			return Attachment_Meta::error_entry( __( 'Not enough memory to process the original image.', 'webberzone-image-optimizer' ) );
		}
		$resize  = $args['dimension'] > 0 && max( $dims[0], $dims[1] ) > $args['dimension'];
		$quality = 0;
		if ( 'image/jpeg' === $mime && class_exists( '\Imagick' ) ) {
			try {
				$image   = new \Imagick( $source );
				$quality = $image->getImageCompressionQuality();
				$image->clear();
			} catch ( \Throwable $e ) {
				return Attachment_Meta::error_entry( $e->getMessage() );
			}
		}
		if ( ! $resize && $quality > 0 && $quality <= $args['quality'] ) {
			return Attachment_Meta::skipped_entry( 'quality', $quality );
		}
		if ( $quality > 0 ) {
			$args['quality'] = min( $quality, $args['quality'] );
		}
		$entry = Original_Backups::ensure( $id, $path );
		if ( is_wp_error( $entry ) ) {
			return Attachment_Meta::error_entry( $entry->get_error_message() );
		}
		$source = Original_Backups::verified_path( $relative, $entry );
		if ( is_wp_error( $source ) ) {
			return Attachment_Meta::error_entry( $source->get_error_message() );
		}
		$args['max_bytes']     = max( 1, (int) ( ( $entry['before'] ?? filesize( $path ) ) * ( 100 - $args['min_saving'] ) / 100 ) );
		$manifest              = Original_Backups::get( $id );
		$manifest[ $relative ] = array_merge( $entry, array( 'state' => 'processing' ) );
		if ( ! Original_Backups::save( $id, $manifest ) ) {
			return Attachment_Meta::error_entry( __( 'The pending operation could not be recorded. The source was not changed.', 'webberzone-image-optimizer' ) );
		}
		$driver = new Original_Driver();
		$result = $driver->convert( $source, $path, 'image/png' === $mime ? 'png' : 'jpeg', $args );
		if ( is_wp_error( $result ) && 'wzio_encode_larger' === $result->get_error_code() && ! $previous && hash_file( 'sha256', $path ) === $hash ) {
			unset( $manifest[ $relative ] );
			if ( Original_Backups::save( $id, $manifest ) ) {
				Helpers::delete_file( $source );
				return Attachment_Meta::skipped_entry( 'larger' );
			}
		}
		if ( is_wp_error( $result ) ) {
			unset( $entry['error'], $entry['skip'] );
			$entry[ 'wzio_encode_larger' === $result->get_error_code() ? 'skip' : 'error' ] = 'wzio_encode_larger' === $result->get_error_code() ? 'larger' : $result->get_error_message();
		} else {
			unset( $entry['skip'], $entry['error'] );
			$entry['state']   = 'optimized';
			$entry['after']   = (int) filesize( $path );
			$entry['quality'] = $args['quality'];
			$new_dims         = wp_getimagesize( $path );
			if ( $resize && $new_dims ) {
				$entry['resized'] = array(
					'from' => array( $entry['width'], $entry['height'] ),
					'to'   => array( $new_dims[0], $new_dims[1] ),
				);
			}
			self::invalidate_sidecars( $id, $path );
		}
		$entry['output_hash']  = (string) hash_file( 'sha256', $path );
		$entry['fingerprint']  = isset( $entry['error'] ) ? '' : $fingerprint;
		$manifest              = Original_Backups::get( $id );
		$manifest[ $relative ] = $entry;
		if ( ! Original_Backups::save( $id, $manifest ) ) {
			return Attachment_Meta::error_entry( __( 'The optimization result could not be recorded. The recovery backup has been kept.', 'webberzone-image-optimizer' ) );
		}
		return $entry;
	}

	/**
	 * Record a file written by a core image editor in this request.
	 *
	 * @param string $path Saved file.
	 * @return void
	 */
	public static function note_written( string $path ): void {
		$real = realpath( $path );
		if ( false !== $real ) {
			self::$written[ $real ] = (string) hash_file( 'sha256', $real );
		}
	}

	/**
	 * Retire backups of sub-sizes that core rewrote during a metadata update.
	 *
	 * The regenerated file, including a rebuilt -scaled main file, is the new source; unannounced writes are untouched.
	 * Caller should hold the attachment lock.
	 *
	 * @param int   $id Attachment ID.
	 * @param array $meta Incoming attachment metadata.
	 * @return void
	 */
	public static function retire_regenerated( int $id, array $meta ): void {
		$manifest = Original_Backups::get( $id );
		$main     = get_attached_file( $id );
		if ( ! $manifest || ! is_string( $main ) ) {
			return;
		}
		$main_relative = Original_Backups::relative( $main );
		$sizes         = array();
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
			if ( is_array( $size ) && ! empty( $size['file'] ) ) {
				$sizes[ wp_basename( $size['file'] ) ] = true;
			}
		}
		$base    = Helpers::get_upload_basedir();
		$retired = false;
		foreach ( $manifest as $relative => $entry ) {
			$relative = (string) $relative;
			if ( '' === $relative || 0 !== validate_file( $relative ) || '/' === $relative[0] || false !== strpos( $relative, '\\' ) ) {
				continue;
			}
			$path = $base . '/' . $relative;
			if ( 'retiring' !== ( $entry['state'] ?? '' ) ) {
				if ( ! in_array( $entry['state'] ?? '', array( 'optimized', 'backed_up' ), true ) || dirname( $relative ) !== dirname( $main_relative ) || ( $relative !== $main_relative && ! isset( $sizes[ wp_basename( $relative ) ] ) ) || ! is_file( $path ) ) {
					continue;
				}
				$known   = (string) ( $entry['output_hash'] ?? $entry['hash'] ?? '' );
				$current = (string) hash_file( 'sha256', $path );
				$written = self::$written[ (string) realpath( $path ) ] ?? '';
				// Only a file core's editor wrote this request and nobody touched since counts as regenerated.
				if ( '' === $known || hash_equals( $known, $current ) || '' === $written || ! hash_equals( $written, $current ) ) {
					continue;
				}
				$manifest[ $relative ]['state'] = 'retiring';
				if ( ! Original_Backups::save( $id, $manifest ) ) {
					continue;
				}
			}
			$backup = $base . '/wzio-originals/' . $relative;
			if ( is_file( $backup ) && ! Helpers::delete_file( $backup ) ) {
				continue;
			}
			unset( $manifest[ $relative ] );
			if ( ! Original_Backups::save( $id, $manifest ) ) {
				continue;
			}
			$record = Attachment_Meta::get( $id );
			unset( $record['files'][ wp_basename( $relative ) ]['original'] );
			Attachment_Meta::set( $id, $record );
			$retired = true;
		}
		if ( $retired ) {
			Scanner::flush_counts();
		}
	}

	/**
	 * Delete only recorded sidecars after source replacement.
	 *
	 * @param int    $id Attachment ID.
	 * @param string $path Served source.
	 * @return void
	 */
	public static function invalidate_sidecars( int $id, string $path ): void {
		$record   = Attachment_Meta::get( $id );
		$progress = Attachment_Meta::get_progress( $id );
		$basename = wp_basename( $path );
		$file     = array_merge( $record['files'][ $basename ] ?? array(), $progress['files'][ $basename ] ?? array() );
		foreach ( Helpers::get_formats() as $format ) {
			if ( ! empty( $file[ $format ]['bytes'] ) ) {
				Helpers::delete_file( Helpers::sidecar_path( $path, $format ) );
			}
			unset( $record['files'][ $basename ][ $format ] );
		}
		if ( isset( $record['files'][ $basename ] ) && is_file( $path ) ) {
			$record['files'][ $basename ]['size'] = (int) filesize( $path );
		}
		Attachment_Meta::set( $id, $record );
		Attachment_Meta::delete_progress( $id );
		Resolver::invalidate_path( $path );
	}

	/**
	 * Reconcile dimensions and file sizes only for files in the recovery manifest.
	 *
	 * @param int   $id Attachment ID.
	 * @param array $meta Attachment metadata.
	 * @return array Updated metadata.
	 */
	public static function reconcile_metadata( int $id, array $meta ): array {
		$manifest = Original_Backups::get( $id );
		$main     = get_attached_file( $id );
		if ( ! is_string( $main ) || ! $manifest ) {
			return $meta;
		}
		$update = static function ( string $path, array $data ) use ( $manifest ): array {
			$relative = Original_Backups::relative( $path );
			if ( ! isset( $manifest[ $relative ] ) ) {
				return $data;
			}
			clearstatcache( true, $path );
			$dims = wp_getimagesize( $path );
			if ( $dims ) {
				$data['width']    = $dims[0];
				$data['height']   = $dims[1];
				$data['filesize'] = (int) filesize( $path );
			}
			return $data;
		};
		$meta   = $update( $main, $meta );
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $name => $size ) {
			if ( is_array( $size ) && ! empty( $size['file'] ) ) {
				$meta['sizes'][ $name ] = $update( dirname( $main ) . '/' . wp_basename( $size['file'] ), $size );
			}
		}
		return $meta;
	}

	/**
	 * Persist dimensions without recursively invoking conversion.
	 *
	 * @param int $id Attachment ID.
	 * @return bool Whether metadata is persisted.
	 */
	public static function update_metadata( int $id ): bool {
		$meta = wp_get_attachment_metadata( $id );
		if ( ! is_array( $meta ) ) {
			return true;
		}
		$updated = self::reconcile_metadata( $id, $meta );
		if ( $updated !== $meta ) {
			self::$updating_metadata = true;
			try {
				wp_update_attachment_metadata( $id, $updated );
			} finally {
				self::$updating_metadata = false;
			}
		}
		return wp_get_attachment_metadata( $id ) === $updated;
	}

	/**
	 * Restore every backup for an attachment, retaining failures for another attempt.
	 *
	 * @param int  $id Attachment ID.
	 * @param bool $queue Whether to queue sidecar regeneration.
	 * @return int|\WP_Error Restored count or error.
	 */
	public static function restore( int $id, bool $queue = true ) {
		return Original_Backups::locked(
			$id,
			static function () use ( $id, $queue ) {
				$data = Original_Backups::get( $id );
				update_post_meta( $id, '_wzio_originals_restored', 1 );
				if ( ! get_post_meta( $id, '_wzio_originals_restored', true ) ) {
					return new \WP_Error( 'wzio_restore_guard', __( 'The restore guard could not be saved.', 'webberzone-image-optimizer' ) );
				}
				$count = 0;
				foreach ( $data as $relative => $entry ) {

					$destination = Helpers::get_upload_basedir() . '/' . $relative;
					if ( '' === $relative || 0 !== validate_file( $relative ) || '/' === $relative[0] || false !== strpos( $relative, '\\' ) || 0 === strpos( $relative, 'wzio-originals/' ) ) {
						return new \WP_Error( 'wzio_restore_path', __( 'The restore destination is unsafe.', 'webberzone-image-optimizer' ) );
					}
					$parent = realpath( dirname( $destination ) );
					$base   = trailingslashit( (string) realpath( Helpers::get_upload_basedir() ) );
					if ( false === $parent || 0 !== strpos( trailingslashit( $parent ), $base ) || is_link( $destination ) ) {
						return new \WP_Error( 'wzio_restore_path', __( 'The restore destination is unsafe or unavailable.', 'webberzone-image-optimizer' ) );
					}
					$source           = Original_Backups::verified_path( $relative, $entry );
					$already_restored = 'restored' === ( $entry['state'] ?? '' ) && is_file( $destination ) && hash_file( 'sha256', $destination ) === ( $entry['hash'] ?? '' );
					if ( is_wp_error( $source ) && ! $already_restored ) {
						return $source;
					}
					if ( ! $already_restored ) {
						$result = ( new Original_Driver() )->restore( $source, $destination );
						if ( is_wp_error( $result ) ) {
							return $result;
						}
					}
					self::invalidate_sidecars( $id, $destination );
					if ( ! self::update_metadata( $id ) ) {
						return new \WP_Error( 'wzio_restore_metadata', __( 'The restored dimensions could not be saved. The backup has been kept.', 'webberzone-image-optimizer' ) );
					}
					$data[ $relative ]['state'] = 'restored';
					if ( ! Original_Backups::save( $id, $data ) ) {
						return new \WP_Error( 'wzio_restore_record', __( 'The restore could not be recorded. The backup has been kept.', 'webberzone-image-optimizer' ) );
					}
					if ( ! is_wp_error( $source ) && ! Helpers::delete_file( $source ) ) {
						return new \WP_Error( 'wzio_restore_cleanup', __( 'The original was restored but its backup could not be removed.', 'webberzone-image-optimizer' ) );
					}
					unset( $data[ $relative ] );
					if ( ! Original_Backups::save( $id, $data ) ) {
						return new \WP_Error( 'wzio_restore_record', __( 'The restored file could not be removed from the recovery record.', 'webberzone-image-optimizer' ) );
					}
					++$count;
				}
				$record = Attachment_Meta::get( $id );
				foreach ( $record['files'] as &$file ) {
					unset( $file['original'] );
				}
				unset( $file );
				Attachment_Meta::set( $id, $record );
				if ( $queue && $count ) {
					Queue::add( array( $id ), true );
					Processor::maybe_schedule();
				}
				Scanner::flush_counts();
				return $count;
			}
		);
	}
	/**
	 * Determine whether settled sidecars still need the original-file stage.
	 *
	 * @param int    $id Attachment ID.
	 * @param array  $record File record.
	 * @param string $path Source path.
	 * @return bool Whether original settings have changed.
	 */
	public static function needs_processing( int $id, array $record, string $path ): bool {
		return \wzio_get_option( 'compress_originals', false )
			&& ! get_post_meta( $id, '_wzio_originals_restored', true )
			&& ( ( $record['original']['settings'] ?? '' ) !== hash( 'sha256', (string) wp_json_encode( self::settings() ) ) || ( is_readable( $path ) && ( $record['original']['source_hash'] ?? '' ) !== Helpers::file_hash( $path ) ) );
	}
	/**
	 * Keep oversized legacy thumbnails out of generated responsive candidates.
	 *
	 * @param array|false $sources Candidates.
	 * @param array       $size_array Requested size.
	 * @param string      $image_src Image URL.
	 * @param array       $image_meta Metadata.
	 * @param int         $attachment_id Attachment ID.
	 * @return array|false Candidates.
	 */
	public static function filter_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		unset( $size_array, $image_src );
		if ( ! is_array( $sources ) ) {
			return $sources;
		}
		$main     = get_attached_file( $attachment_id );
		$relative = is_string( $main ) ? Original_Backups::relative( $main ) : '';
		$data     = Original_Backups::get( (int) $attachment_id );
		if ( empty( $data[ $relative ]['resized'] ) || 'optimized' !== ( $data[ $relative ]['state'] ?? '' ) ) {
			return $sources;
		}
		foreach ( $sources as $key => $source ) {
			if ( 'w' === ( $source['descriptor'] ?? '' ) && (int) $source['value'] > (int) ( $image_meta['width'] ?? 0 ) ) {
				unset( $sources[ $key ] );
			}
		}
		return $sources;
	}

	/**
	 * Substitute core editors that read backups but keep output in uploads.
	 *
	 * @param array $editors Editor classes.
	 * @return array Editor classes.
	 */
	public static function image_editors( array $editors ): array {
		$map = array(
			'WP_Image_Editor_Imagick' => Drivers\Backup_Imagick::class,
			'WP_Image_Editor_GD'      => Drivers\Backup_GD::class,
		);
		return array_map(
			static function ( $editor ) use ( $map ) {
				return $map[ $editor ] ?? $editor;
			},
			$editors
		);
	}

	/**
	 * Resolve a verified main-file backup for core image editing.
	 *
	 * @param string $path Served file.
	 * @return string Input path.
	 */
	public static function editor_source( string $path ): string {
		$relative = Original_Backups::relative( $path );
		if ( '' === $relative || ! is_file( Helpers::get_upload_basedir() . '/wzio-originals/' . $relative ) ) {
			return $path;
		}
		$id = attachment_url_to_postid( Helpers::path_to_url( $path ) );
		if ( ! $id || get_attached_file( $id ) !== $path ) {
			return $path;
		}
		$data = Original_Backups::get( $id );
		if ( ! isset( $data[ $relative ] ) ) {
			return $path;
		}
		$backup = Original_Backups::verified_path( $relative, $data[ $relative ] );
		return is_wp_error( $backup ) ? $path : $backup;
	}

	/**
	 * Remove backups when their attachment is intentionally deleted.
	 *
	 * @param int $id Attachment ID.
	 * @return void
	 */
	public static function delete_backups( int $id ): void {
		if ( ! Original_Backups::get( $id ) ) {
			return;
		}
		self::locked(
			$id,
			static function () use ( $id ) {
				foreach ( Original_Backups::get( $id ) as $relative => $entry ) {
					$path = Original_Backups::verified_path( $relative, $entry );
					if ( ! is_wp_error( $path ) ) {
						Helpers::delete_file( $path );
					}
				}
			}
		);
	}
	/**
	 * Release the deletion lock and remove its file once core has deleted the attachment.
	 *
	 * @param int $id Attachment ID.
	 * @return void
	 */
	public static function release_delete_lock( $id ): void {
		$id  = (int) $id;
		$key = get_current_blog_id() . ':' . $id;
		if ( ! isset( self::$deleting[ $key ] ) ) {
			return;
		}
		Original_Backups::release( self::$deleting[ $key ] );
		unset( self::$deleting[ $key ] );
		Helpers::delete_file( Helpers::get_upload_basedir() . '/wzio-originals/attachment-' . $id . '.lock' );
	}

	/**
	 * Prevent deletion from discarding recovery data during another operation.
	 *
	 * @param mixed    $delete Prior filter decision.
	 * @param \WP_Post $post Attachment.
	 * @return mixed Prior decision, or false while busy.
	 */
	public static function prepare_delete( $delete, $post ) {
		if ( null !== $delete || ( ! \wzio_get_option( 'compress_originals', false ) && ! Original_Backups::get( (int) $post->ID ) ) ) {
			return $delete;
		}
		$id  = (int) $post->ID;
		$key = get_current_blog_id() . ':' . $id;
		if ( isset( self::$deleting[ $key ] ) ) {
			return $delete;
		}
		$handle = Original_Backups::acquire( $id );
		if ( is_wp_error( $handle ) ) {
			return false;
		}
		self::$deleting[ $key ] = $handle;
		register_shutdown_function(
			static function () use ( $key ) {
				if ( isset( self::$deleting[ $key ] ) ) {
					Original_Backups::release( self::$deleting[ $key ] );
					unset( self::$deleting[ $key ] );
				}
			}
		);
		return $delete;
	}
}
