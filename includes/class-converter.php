<?php
/**
 * Image conversion orchestration.
 *
 * @package WebberZone\Image_Optimizer
 */

namespace WebberZone\Image_Optimizer;

use WebberZone\Image_Optimizer\Frontend\Resolver;
use WebberZone\Image_Optimizer\Util\Helpers;

if ( ! defined( 'WPINC' ) ) {
	exit;
}

/**
 * Creates reversible sidecar images without modifying originals.
 *
 * @since 1.0.0
 */
class Converter {


	/**
	 * Resolve the conversion arguments, layering overrides over the settings.
	 *
	 * @since 1.0.0
	 *
	 * @param  array<string, mixed> $overrides Argument overrides.
	 * @return array<string, mixed> Arguments.
	 */
	public static function get_args( array $overrides = array() ): array {
		// Multicheck settings are stored as a comma separated string.
		$formats = wp_parse_list( \wzio_get_option( 'formats', 'webp' ) );
		$formats = array_values( array_intersect( Helpers::get_formats(), $formats ) );

		$args = wp_parse_args(
			$overrides,
			array(
				'formats'     => $formats,
				'force'       => false,
				'strip'       => (bool) \wzio_get_option( 'strip_metadata', true ),
				'effort_webp' => (int) \wzio_get_option( 'effort_webp', 6 ),
				'effort_avif' => (int) \wzio_get_option( 'effort_avif', 4 ),
				'min_saving'  => (int) \wzio_get_option( 'min_saving', 5 ),
				'quality'     => array(
					'webp' => (int) \wzio_get_option( 'quality_webp', 82 ),
					'avif' => (int) \wzio_get_option( 'quality_avif', 50 ),
				),
				'lossless'    => (bool) \wzio_get_option( 'lossless_png', true ),
			)
		);

		/**
		 * Filter the conversion arguments.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $args      Conversion arguments.
		 * @param array<string, mixed> $overrides Overrides supplied by the caller.
		 */
		return (array) apply_filters( 'wzio_conversion_args', $args, $overrides );
	}

	/**
	 * Convert every served attachment file, excluding the unserved big-image original.
	 *
	 * @since 1.0.0
	 *
	 * @param  int                       $attachment_id Attachment ID.
	 * @param  array<string, mixed>      $overrides     Argument overrides.
	 * @param  array<string, mixed>|null $meta          Attachment metadata, when it is not yet stored.
	 * @return array{files: int, converted: int, skipped: int, failed: int, source: int, saved: int, errors: array<int, string>}|\WP_Error Summary or error.
	 */
	public static function convert_attachment( int $attachment_id, array $overrides = array(), ?array $meta = null ) {
		if ( ! self::is_convertible_attachment( $attachment_id ) ) {
			return new \WP_Error(
				'wzio_not_convertible',
				__( 'This attachment is not an image the plugin can convert.', 'webberzone-image-optimizer' )
			);
		}

		$args  = self::get_args( $overrides );
		$files = self::get_attachment_files( $attachment_id, $meta );

		if ( empty( $files ) ) {
			return new \WP_Error(
				'wzio_no_files',
				__( 'No image files were found on disk for this attachment.', 'webberzone-image-optimizer' )
			);
		}

		$record = Attachment_Meta::get( $attachment_id );

		foreach ( $files as $basename => $path ) {
			$existing = $record['files'][ $basename ] ?? array();

			$record['files'][ $basename ] = self::convert_file( $path, $args, $existing );
			// Saved per file so a timeout mid-loop doesn't discard finished work.
			Attachment_Meta::set( $attachment_id, $record );
			Resolver::invalidate_path( $path );
		}

		$summary = self::summarise( $record, $files, $args['formats'] );

		self::prune_orphans( $attachment_id, $record, $files );

		/**
		 * Fires after an attachment has been processed.
		 *
		 * @since 1.0.0
		 *
		 * @param int                  $attachment_id Attachment ID.
		 * @param array<string, mixed> $summary       Conversion summary.
		 * @param array<string, mixed> $args          Conversion arguments used.
		 */
		do_action( 'wzio_attachment_converted', $attachment_id, $summary, $args );

		return $summary;
	}

	/**
	 * Convert one unsettled attachment file per call for bounded progress.
	 *
	 * Force is unsupported because it would repeatedly select the first file.
	 *
	 * @since 1.0.0
	 *
	 * @param  int                  $attachment_id Attachment ID.
	 * @param  array<string, mixed> $overrides     Argument overrides.
	 * @return array{done: bool, index: int, total: int}|\WP_Error Progress, or error.
	 */
	public static function convert_next_file( int $attachment_id, array $overrides = array() ) {
		if ( ! self::is_convertible_attachment( $attachment_id ) ) {
			return new \WP_Error(
				'wzio_not_convertible',
				__( 'This attachment is not an image the plugin can convert.', 'webberzone-image-optimizer' )
			);
		}

		$args  = self::get_args( $overrides );
		$files = self::get_attachment_files( $attachment_id );

		if ( empty( $files ) ) {
			return new \WP_Error(
				'wzio_no_files',
				__( 'No image files were found on disk for this attachment.', 'webberzone-image-optimizer' )
			);
		}

		$record = Attachment_Meta::get( $attachment_id );
		$total  = count( $files );
		$index  = 0;

		foreach ( $files as $basename => $path ) {
			++$index;
			$existing = $record['files'][ $basename ] ?? array();

			if ( self::file_is_settled( $existing, $args['formats'] ) ) {
				continue;
			}

			$record['files'][ $basename ] = self::convert_file( $path, $args, $existing );
			Attachment_Meta::set( $attachment_id, $record );
			Resolver::invalidate_path( $path );

			return array(
				'done'  => false,
				'index' => $index,
				'total' => $total,
			);
		}

		self::prune_orphans( $attachment_id, $record, $files );

		/** This action is documented in includes/class-converter.php */
		do_action( 'wzio_attachment_converted', $attachment_id, self::summarise( $record, $files, $args['formats'] ), $args );

		return array(
			'done'  => true,
			'index' => $total,
			'total' => $total,
		);
	}

	/**
	 * Summarise current attachment files consistently across conversion paths.
	 *
	 * @since 1.0.0
	 *
	 * @param  array<string, mixed>  $record  Conversion record.
	 * @param  array<string, string> $files   Basename to path map.
	 * @param  array<int, string>    $formats Requested formats.
	 * @return array{files: int, converted: int, skipped: int, failed: int, source: int, saved: int, errors: array<int, string>} Summary.
	 */
	private static function summarise( array $record, array $files, array $formats ): array {
		$summary = array(
			'files'     => 0,
			'converted' => 0,
			'skipped'   => 0,
			'failed'    => 0,
			'source'    => 0,
			'saved'     => 0,
			'errors'    => array(),
		);

		foreach ( array_keys( $files ) as $basename ) {
			$result = $record['files'][ $basename ] ?? array();
			$source = (int) ( $result['size'] ?? 0 );
			$best   = 0;

			++$summary['files'];

			foreach ( $formats as $format ) {
				$entry = $result[ $format ] ?? array();

				if ( isset( $entry['bytes'] ) ) {
					++$summary['converted'];

					if ( 0 === $best || $entry['bytes'] < $best ) {
						$best = (int) $entry['bytes'];
					}
				} elseif ( isset( $entry['error'] ) ) {
					++$summary['failed'];
					$summary['errors'][] = $basename . ': ' . $entry['error'];
				} else {
					++$summary['skipped'];
				}
			}

			if ( $best > 0 ) {
				$summary['source'] += $source;
				$summary['saved']  += max( 0, $source - $best );
			}
		}

		return $summary;
	}

	/**
	 * Whether every requested format has already been attempted for a file.
	 *
	 * @since 1.0.0
	 *
	 * @param  array<string, mixed> $existing Previous record for the file.
	 * @param  array<int, string>   $formats  Requested formats.
	 * @return bool True when nothing further would be attempted.
	 */
	private static function file_is_settled( array $existing, array $formats ): bool {
		foreach ( $formats as $format ) {
			if ( ! isset( $existing[ $format ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Delete sidecars for files no longer produced by the attachment, and save the record.
	 *
	 * @since 1.0.0
	 *
	 * @param  int                   $attachment_id Attachment ID.
	 * @param  array<string, mixed>  $record        Conversion record.
	 * @param  array<string, string> $files         Current basename to path map.
	 * @return void
	 */
	private static function prune_orphans( int $attachment_id, array $record, array $files ): void {
		// Remove sidecars for stale `-e{timestamp}` edit variants.
		$orphans = array_diff_key( $record['files'], $files );

		if ( empty( $orphans ) ) {
			Attachment_Meta::set( $attachment_id, $record );
			return;
		}

		$dir = dirname( (string) reset( $files ) );

		foreach ( array_keys( $orphans ) as $basename ) {
			$path = $dir . '/' . $basename;
			foreach ( Helpers::get_formats() as $format ) {
				Helpers::delete_file( Helpers::sidecar_path( $path, $format ) );
			}
			Resolver::invalidate_path( $path );
		}

		$record['files'] = array_intersect_key( $record['files'], $files );
		Attachment_Meta::set( $attachment_id, $record );
	}

	/**
	 * Convert one source file into every requested format.
	 *
	 * @since 1.0.0
	 *
	 * @param  string               $path     Absolute path to the source image.
	 * @param  array<string, mixed> $args     Conversion arguments.
	 * @param  array<string, mixed> $existing Previous record for this file.
	 * @return array<string, mixed> File record.
	 */
	public static function convert_file( string $path, array $args, array $existing = array() ): array {
		$record = array( 'size' => 0 );

		// Record every format so stepped conversion cannot retry this file forever.
		if ( ! is_readable( $path ) ) {
			foreach ( $args['formats'] as $format ) {
				$record[ $format ] = Attachment_Meta::error_entry(
					__( 'The source file could not be read.', 'webberzone-image-optimizer' )
				);
			}

			return $record;
		}

		$source_bytes   = (int) filesize( $path );
		$record['size'] = $source_bytes;

		$mime = self::get_mime_type( $path );
		$dims = wp_getimagesize( $path );

		$width  = is_array( $dims ) ? (int) $dims[0] : 0;
		$height = is_array( $dims ) ? (int) $dims[1] : 0;

		$animated = ( 'image/gif' === $mime ) && Helpers::is_animated_gif( $path );

		$max_bytes = (int) ( $source_bytes * ( 100 - (int) ( $args['min_saving'] ?? 5 ) ) / 100 );

		foreach ( $args['formats'] as $format ) {
			$destination = Helpers::sidecar_path( $path, $format );

			// Any up-to-date copy is kept, whoever wrote it. Re-encoding a file that
			// already serves costs a migrating site hours and can only make it worse.
			if ( empty( $args['force'] )
				&& file_exists( $destination )
				&& filemtime( $destination ) >= filemtime( $path )
			) {
				$bytes = (int) filesize( $destination );

				if ( $bytes > 0 && $bytes < $max_bytes ) {
					$record[ $format ] = Attachment_Meta::converted_entry( $bytes );
					continue;
				}
			}

			// A previous run decided this file is not worth converting.
			if ( empty( $args['force'] ) && isset( $existing[ $format ]['skip'] ) ) {
				$record[ $format ] = $existing[ $format ];
				continue;
			}

			$driver = Capabilities::get_driver( $format );

			if ( null === $driver ) {
				$record[ $format ] = Attachment_Meta::skipped_entry( 'unsupported' );
				continue;
			}

			if ( $animated && 'imagick' !== $driver::get_name() ) {
				$record[ $format ] = Attachment_Meta::skipped_entry( 'animated' );
				continue;
			}

			if ( ! Helpers::can_allocate_image( $width, $height ) ) {
				$record[ $format ] = Attachment_Meta::error_entry(
					__( 'Not enough memory to decode this image.', 'webberzone-image-optimizer' )
				);
				continue;
			}

			$driver_args = array(
				'quality'   => (int) ( $args['quality'][ $format ] ?? 82 ),
				'lossless'  => ! empty( $args['lossless'] ) && 'image/png' === $mime,
				'strip'     => ! empty( $args['strip'] ),
				'effort'    => (int) ( $args['effort_webp'] ?? 6 ),
				'dims'      => compact( 'width', 'height' ),
				'max_bytes' => $max_bytes,
			);

			if ( 'avif' === $format && isset( $args['effort_avif'] ) ) {
				$driver_args['effort'] = (int) $args['effort_avif'];
			}

			$result = $driver->convert(
				$path,
				$destination,
				$format,
				$driver_args
			);

			if ( is_wp_error( $result ) && 'wzio_encode_larger' !== $result->get_error_code() ) {
				$record[ $format ] = Attachment_Meta::error_entry( $result->get_error_message() );
				continue;
			}

			$record[ $format ] = self::resolve_sidecar( $path, $destination, $max_bytes, ! is_wp_error( $result ) );
		}

		return $record;
	}

	/**
	 * Judge the sidecar on disk once an encode has run to completion.
	 *
	 * Covers the copy this run wrote and the older one a rejected encode leaves
	 * in place, which may have come from another plugin. Both are kept only when
	 * small enough, and an inherited one only when no older than its source.
	 * Encoder errors return before this and leave the file alone, on the grounds
	 * that a transient failure is no reason to drop a working sidecar. This also
	 * backstops the size limit for drivers that write without honouring it.
	 *
	 * @since 1.0.1
	 *
	 * @param  string $path        Absolute path to the source image.
	 * @param  string $destination Sidecar path.
	 * @param  int    $max_bytes   Size the sidecar has to stay below.
	 * @param  bool   $written     Whether this run wrote the sidecar.
	 * @return array<string, mixed> Format record.
	 */
	private static function resolve_sidecar( string $path, string $destination, int $max_bytes, bool $written ): array {
		clearstatcache( true, $destination );

		if ( file_exists( $destination ) ) {
			$bytes = (int) filesize( $destination );

			// A future-dated source would otherwise condemn the encode just made.
			$fresh = $written || filemtime( $destination ) >= filemtime( $path );

			if ( $bytes > 0 && $bytes < $max_bytes && $fresh ) {
				return Attachment_Meta::converted_entry( $bytes );
			}

			Helpers::delete_file( $destination );
		}

		return Attachment_Meta::skipped_entry( 'larger' );
	}

	/**
	 * List the deliverable files belonging to an attachment.
	 *
	 * @since 1.0.0
	 *
	 * @param  int                       $attachment_id Attachment ID.
	 * @param  array<string, mixed>|null $meta          Attachment metadata, when it is not yet stored.
	 * @return array<string, string> Basename to absolute path.
	 */
	public static function get_attachment_files( int $attachment_id, ?array $meta = null ): array {
		$meta = null === $meta ? wp_get_attachment_metadata( $attachment_id ) : $meta;
		$main = get_attached_file( $attachment_id );

		$files = array();

		if ( is_string( $main ) && '' !== $main && file_exists( $main ) ) {
			$files[ wp_basename( $main ) ] = $main;
		}

		if ( ! is_array( $meta ) || empty( $meta['file'] ) ) {
			return self::filter_files( $files, $attachment_id );
		}

		$dir = '' !== (string) $main ? dirname( $main ) : dirname( Helpers::get_upload_basedir() . '/' . $meta['file'] );

		$allowed_sizes = self::get_enabled_sizes();

		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size_name => $size ) {
				if ( empty( $size['file'] ) ) {
					continue;
				}

				if ( null !== $allowed_sizes && ! in_array( (string) $size_name, $allowed_sizes, true ) ) {
					continue;
				}

				$basename = wp_basename( (string) $size['file'] );
				$path     = $dir . '/' . $basename;

				// Several registered sizes can resolve to one file on disk.
				if ( isset( $files[ $basename ] ) || ! file_exists( $path ) ) {
					continue;
				}

				$files[ $basename ] = $path;
			}
		}

		return self::filter_files( $files, $attachment_id );
	}

	/**
	 * Apply the source MIME allow-list and the exclusion filter to a file list.
	 *
	 * @since 1.0.0
	 *
	 * @param  array<string, string> $files         Basename to absolute path.
	 * @param  int                   $attachment_id Attachment ID.
	 * @return array<string, string> Filtered list.
	 */
	private static function filter_files( array $files, int $attachment_id ): array {
		foreach ( $files as $basename => $path ) {
			if ( ! in_array( self::get_mime_type( $path ), Helpers::SOURCE_MIME_TYPES, true ) || self::is_excluded( $path ) ) {
				unset( $files[ $basename ] );
			}
		}

		/**
		 * Filter the files that will be converted for an attachment.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $files         Basename to absolute path.
		 * @param int                   $attachment_id Attachment ID.
		 */
		return (array) apply_filters( 'wzio_attachment_files', $files, $attachment_id );
	}

	/**
	 * Whether a file matches one of the configured exclusion fragments.
	 *
	 * Matching is done against the path relative to the uploads directory so
	 * that a fragment such as `2019/07` behaves the same on every install.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $path Absolute file path.
	 * @return bool True when the file should be left alone.
	 */
	public static function is_excluded( string $path ): bool {
		$lines     = preg_split( '/\R/', (string) \wzio_get_option( 'exclude_paths', '' ) );
		$fragments = array();

		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			$line = trim( $line );

			if ( '' !== $line ) {
				$fragments[] = wp_normalize_path( $line );
			}
		}

		$excluded = false;

		if ( ! empty( $fragments ) ) {
			$basedir  = wp_normalize_path( Helpers::get_upload_basedir() );
			$relative = wp_normalize_path( $path );

			if ( '' !== $basedir && 0 === strpos( $relative, $basedir . '/' ) ) {
				$relative = substr( $relative, strlen( $basedir ) + 1 );
			}

			foreach ( $fragments as $fragment ) {
				if ( false !== stripos( $relative, $fragment ) ) {
					$excluded = true;
					break;
				}
			}
		}

		/**
		 * Filter whether a file is excluded from conversion.
		 *
		 * @since 1.0.0
		 *
		 * @param bool   $excluded Whether the file is excluded.
		 * @param string $path     Absolute file path.
		 */
		return (bool) apply_filters( 'wzio_is_excluded', $excluded, $path );
	}

	/**
	 * Get the image sizes selected in the settings.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, string>|null Size names, or null when every size is enabled.
	 */
	public static function get_enabled_sizes(): ?array {
		$sizes = wp_parse_list( \wzio_get_option( 'convert_sizes', '' ) );

		if ( empty( $sizes ) ) {
			return null;
		}

		return array_map( 'strval', $sizes );
	}

	/**
	 * Whether an attachment is an image the plugin can convert.
	 *
	 * @since 1.0.0
	 *
	 * @param  int $attachment_id Attachment ID.
	 * @return bool True when convertible.
	 */
	public static function is_convertible_attachment( int $attachment_id ): bool {
		if ( $attachment_id < 1 || 'attachment' !== get_post_type( $attachment_id ) ) {
			return false;
		}

		$mime = get_post_mime_type( $attachment_id );

		return is_string( $mime ) && in_array( $mime, Helpers::SOURCE_MIME_TYPES, true );
	}

	/**
	 * Read the MIME type of a file from its contents.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $path Absolute file path.
	 * @return string MIME type, or an empty string when it cannot be determined.
	 */
	private static function get_mime_type( string $path ): string {
		$type = wp_check_filetype( $path );

		if ( ! empty( $type['type'] ) ) {
			return (string) $type['type'];
		}

		return '';
	}

	/**
	 * Delete every sidecar belonging to an attachment and clear its record.
	 *
	 * @since 1.0.0
	 *
	 * @param  int $attachment_id Attachment ID.
	 * @return int Number of files deleted.
	 */
	public static function delete_sidecars( int $attachment_id ): int {
		$record  = Attachment_Meta::get( $attachment_id );
		$files   = self::get_attachment_files( $attachment_id );
		$deleted = 0;

		// Cover both the files still on disk and any recorded earlier.
		$basenames = array_unique( array_merge( array_keys( $files ), array_keys( $record['files'] ) ) );

		$dir = '';

		if ( ! empty( $files ) ) {
			$dir = dirname( (string) reset( $files ) );
		} else {
			$main = get_attached_file( $attachment_id );
			$dir  = is_string( $main ) && '' !== $main ? dirname( $main ) : '';
		}

		if ( '' === $dir ) {
			Attachment_Meta::delete( $attachment_id );
			return 0;
		}

		foreach ( $basenames as $basename ) {
			foreach ( Helpers::get_formats() as $format ) {
				$sidecar = Helpers::sidecar_path( $dir . '/' . $basename, $format );

				if ( file_exists( $sidecar ) && Helpers::delete_file( $sidecar ) ) {
					++$deleted;
				}
			}

			Resolver::invalidate_path( $dir . '/' . $basename );
		}

		Attachment_Meta::delete( $attachment_id );

		return $deleted;
	}
}
