<?php
/**
 * JPEG source encoding and verified atomic file replacement.
 *
 * @package WebberZone\Image_Optimizer
 */

namespace WebberZone\Image_Optimizer\Drivers;

if ( ! defined( 'WPINC' ) ) {
	exit;
}

/**
 * Original-file operations reuse the sidecar atomic writer.
 *
 * @since 1.2.0
 */
class Original_Driver extends Driver {


	/**
	 * Check encoder availability.
	 *
	 * @return bool Whether a JPEG encoder exists.
	 */
	public static function is_available(): bool {
		return class_exists( '\Imagick' ) || function_exists( 'imagejpeg' );
	}

	/**
	 * Identify the driver.
	 *
	 * @return string Driver slug.
	 */
	public static function get_name(): string {
		return 'original';
	}

	/**
	 * Check the target.
	 *
	 * @param  string $format Format.
	 * @return bool Whether supported.
	 */
	public function supports( string $format ): bool {
		return ( 'jpeg' === $format && self::is_available() ) || ( 'png' === $format && '' !== \WebberZone\Image_Optimizer\Original_Tools::find()['path'] );
	}

	/**
	 * Encode a source without replacing it until validation succeeds.
	 *
	 * @param  string $source      Input.
	 * @param  string $destination Output.
	 * @param  string $format      Target format.
	 * @param  array  $args        Encoding arguments.
	 * @return true|\WP_Error Result.
	 */
	public function convert( string $source, string $destination, string $format, array $args ) {
		if ( ! $this->supports( $format ) ) {
			return new \WP_Error( 'wzio_original_unsupported', __( 'JPEG compression is unavailable.', 'webberzone-image-optimizer' ) );
		}
		$args   = wp_parse_args(
			$args,
			array(
				'quality'   => 82,
				'strip'     => true,
				'dimension' => 0,
				'max_bytes' => 0,
			)
		);
		$result = $this->write_atomic(
			$destination,
			function ( string $temp ) use ( $source, $args, $format ): bool {
				if ( 'png' === $format ) {
					$dimensions = wp_getimagesize( $source );
					if ( ! $dimensions ) {
						return false;
					}
					if ( (int) $args['dimension'] > 0 && max( $dimensions[0], $dimensions[1] ) > (int) $args['dimension'] ) {
						$editor = wp_get_image_editor( $source );
						if ( is_wp_error( $editor ) || is_wp_error( $editor->resize( (int) $args['dimension'], (int) $args['dimension'], false ) ) ) {
							return false;
						}
						$saved = $editor->save( $temp, 'image/png' );
						if ( is_wp_error( $saved ) ) {
							return false;
						}
						if ( $saved['path'] !== $temp ) {
                               // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
							$moved = rename( $saved['path'], $temp );
							if ( ! $moved ) {
								wp_delete_file( $saved['path'] );
								return false;
							}
						}
					} elseif ( ! copy( $source, $temp ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
						return false;
					}
					return \WebberZone\Image_Optimizer\Original_Tools::compress( $temp, (bool) $args['strip'] ) && self::preserve_png_metadata( $source, $temp, (bool) $args['strip'] );
				}
				if ( class_exists( '\Imagick' ) && empty( $args['gd'] ) ) {
					$image = new \Imagick( $source );
					try {
						if ( $image->getNumberImages() > 1 ) {
							return false;
						}
						$dimension = (int) $args['dimension'];
						if ( $dimension > 0 && max( $image->getImageWidth(), $image->getImageHeight() ) > $dimension ) {
							$image->resizeImage( $dimension, $dimension, \Imagick::FILTER_LANCZOS, 1, true ); // thumbnailImage would drop the ICC profile and EXIF.
						}
						if ( $args['strip'] ) {
							$profiles = $image->getImageProfiles( 'icc', true );
							$image->stripImage();
							if ( isset( $profiles['icc'] ) ) {
								$image->profileImage( 'icc', $profiles['icc'] );
							}
						}
							$image->setImageFormat( 'jpeg' );
							$image->setImageCompressionQuality( max( 1, min( 100, (int) $args['quality'] ) ) );
							$image->setSamplingFactors( array( '2x2', '1x1', '1x1' ) );
							$image->setInterlaceScheme( \Imagick::INTERLACE_PLANE );
							return $image->writeImage( $temp ) && 'image/jpeg' === wp_get_image_mime( $temp ) && self::keep_orientation( $source, $temp, (bool) $args['strip'] );
					} finally {
						$image->clear();
					}
				}
				$image = 'image/png' === wp_get_image_mime( $source ) ? imagecreatefrompng( $source ) : imagecreatefromjpeg( $source );
				if ( false === $image ) {
					return false;
				}
				try {
					$dimension = (int) $args['dimension'];
					if ( $dimension > 0 && max( imagesx( $image ), imagesy( $image ) ) > $dimension ) {
						list( $width, $height ) = wp_constrain_dimensions( imagesx( $image ), imagesy( $image ), $dimension, $dimension );
						$resized                = imagescale( $image, $width, $height );
						if ( false === $resized ) {
							return false;
						}
						unset( $image );
						$image = $resized;
					}
					imageinterlace( $image, true );
					return imagejpeg( $image, $temp, (int) $args['quality'] ) && self::preserve_jpeg_metadata( $source, $temp, (bool) $args['strip'] ) && 'image/jpeg' === wp_get_image_mime( $temp );
				} finally {
					unset( $image );
				}
			},
			(int) $args['max_bytes']
		);
		if ( is_wp_error( $result ) && 'wzio_encode_larger' !== $result->get_error_code() && 'jpeg' === $format && class_exists( '\\Imagick' ) && empty( $args['gd'] ) && function_exists( 'imagejpeg' ) ) {
			$args['gd'] = true;
			return $this->convert( $source, $destination, $format, $args );
		}
		return $result;
	}

	/**
	 * Copy a verified image atomically for restore.
	 *
	 * @param  string $source      Backup file.
	 * @param  string $destination Served file.
	 * @return true|\WP_Error Result.
	 */
	public function restore( string $source, string $destination ) {
		return $this->write_atomic(
			$destination,
			static function ( string $temp ) use ( $source ): bool {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
				return copy( $source, $temp ) && hash_file( 'sha256', $source ) === hash_file( 'sha256', $temp );
			}
		);
	}
	/**
	 * Preserve JPEG color profiles, and other metadata when stripping is disabled.
	 *
	 * @param  string $source Original JPEG.
	 * @param  string $target GD output.
	 * @param  bool   $strip  Strip metadata except ICC.
	 * @return bool Whether output is valid.
	 */
	private static function preserve_jpeg_metadata( string $source, string $target, bool $strip ): bool {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$input = file_get_contents( $source );
		if ( false === $input ) {
			return false;
		}
		if ( substr( $input, 0, 2 ) !== "\xff\xd8" ) {
			return true;
		}
		$metadata = '';
		$offset   = 2;
		$length   = strlen( $input );
		while ( $offset + 4 <= $length && "\xff" === $input[ $offset ] ) {
			$marker = ord( $input[ $offset + 1 ] );
			if ( 0xda === $marker || 0xd9 === $marker ) {
				break;
			}
			$size = ( ord( $input[ $offset + 2 ] ) << 8 ) + ord( $input[ $offset + 3 ] );
			if ( $size < 2 || $offset + $size + 2 > $length ) {
				return false;
			}
			$icc = 0xe2 === $marker && 'ICC_PROFILE' === substr( $input, $offset + 4, 11 );
			if ( $icc || ( ! $strip && ( ( $marker >= 0xe1 && $marker <= 0xef ) || 0xfe === $marker ) ) ) {
				$metadata .= substr( $input, $offset, $size + 2 );
			}
			$offset += $size + 2;
		}
		if ( $strip ) {
			$metadata .= self::orientation_segment( $input );
		}
		if ( '' === $metadata ) {
			return true;
		}
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$output = file_get_contents( $target );
		if ( false === $output || substr( $output, 0, 2 ) !== "\xff\xd8" ) {
			return false;
		}
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return false !== file_put_contents( $target, substr( $output, 0, 2 ) . $metadata . substr( $output, 2 ) );
	}

	/**
	 * Re-embed the EXIF orientation of a JPEG whose metadata was stripped.
	 *
	 * @param  string $source Original JPEG.
	 * @param  string $target Encoded JPEG.
	 * @param  bool   $strip  Whether metadata was stripped.
	 * @return bool Whether output is valid.
	 */
	private static function keep_orientation( string $source, string $target, bool $strip ): bool {
		if ( ! $strip ) {
			return true;
		}
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$input = file_get_contents( $source );
		if ( false === $input ) {
			return false;
		}
		$segment = self::orientation_segment( $input );
		if ( '' === $segment ) {
			return true;
		}
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$output = file_get_contents( $target );
		if ( false === $output || substr( $output, 0, 2 ) !== "\xff\xd8" ) {
			return false;
		}
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return false !== file_put_contents( $target, substr( $output, 0, 2 ) . $segment . substr( $output, 2 ) );
	}

	/**
	 * Build an orientation-only EXIF APP1 segment when the source is rotated.
	 *
	 * @param  string $input JPEG bytes.
	 * @return string Segment, or empty when orientation is absent or normal.
	 */
	private static function orientation_segment( string $input ): string {
		$offset = 2;
		$length = strlen( $input );
		while ( $offset + 4 <= $length && "\xff" === $input[ $offset ] ) {
			$marker = ord( $input[ $offset + 1 ] );
			if ( 0xda === $marker || 0xd9 === $marker ) {
				break;
			}
			$size = ( ord( $input[ $offset + 2 ] ) << 8 ) + ord( $input[ $offset + 3 ] );
			if ( $size < 2 || $offset + $size + 2 > $length ) {
				return '';
			}
			if ( 0xe1 === $marker && "Exif\0\0" === substr( $input, $offset + 4, 6 ) ) {
				$tiff = substr( $input, $offset + 10, $size - 8 );
				$big  = 'MM' === substr( $tiff, 0, 2 );
				if ( ! $big && 'II' !== substr( $tiff, 0, 2 ) ) {
					return '';
				}
				$short = $big ? 'n' : 'v';
				$long  = $big ? 'N' : 'V';
				$ifd   = unpack( $long . 'o', substr( $tiff, 4, 4 ) );
				$pos   = $ifd ? (int) $ifd['o'] : 0;
				if ( $pos < 8 || $pos + 2 > strlen( $tiff ) ) {
					return '';
				}
				$count = unpack( $short . 'c', substr( $tiff, $pos, 2 ) );
				for ( $i = 0; $count && $i < (int) $count['c']; $i++ ) {
					$entry = $pos + 2 + $i * 12;
					if ( $entry + 12 > strlen( $tiff ) ) {
						return '';
					}
					$tag = unpack( $short . 't', substr( $tiff, $entry, 2 ) );
					if ( $tag && 0x0112 === (int) $tag['t'] ) {
						$value       = unpack( $short . 'v', substr( $tiff, $entry + 8, 2 ) );
						$orientation = $value ? (int) $value['v'] : 1;
						if ( $orientation < 2 || $orientation > 8 ) {
							return '';
						}
						return "\xff\xe1\x00\x22Exif\0\0MM\0\x2a\0\0\0\x08\0\x01\x01\x12\0\x03\0\0\0\x01\0" . chr( $orientation ) . "\0\0\0\0\0\0";
					}
				}
				return '';
			}
			$offset += $size + 2;
		}
		return '';
	}

	/**
	 * Preserve color profiles and reject animation before publishing tool output.
	 *
	 * @param  string $source Original PNG.
	 * @param  string $target Tool output.
	 * @param  bool   $strip  Remove descriptive metadata.
	 * @return bool Whether valid.
	 */
	private static function preserve_png_metadata( string $source, string $target, bool $strip ): bool {
		$inputs = array();
		foreach ( array( $source, $target ) as $path ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$bytes = file_get_contents( $path );
			if ( false === $bytes || "\x89PNG\r\n\x1a\n" !== substr( $bytes, 0, 8 ) ) {
				return false;
			}
			$chunks = array();
			$offset = 8;
			$length = strlen( $bytes );
			while ( $offset + 12 <= $length ) {
				$decoded = unpack( 'Nlength', substr( $bytes, $offset, 4 ) );
				$size    = $decoded['length'];
				if ( $size > $length - $offset - 12 ) {
					return false;
				}
				$type = substr( $bytes, $offset + 4, 4 );
				if ( 'acTL' === $type ) {
					return false;
				}
				$chunks[] = array(
					'type'  => $type,
					'bytes' => substr( $bytes, $offset, $size + 12 ),
				);
				$offset  += $size + 12;
				if ( 'IEND' === $type ) {
					break;
				}
			}
			$inputs[] = $chunks;
		}
		foreach ( $inputs[0] as $chunk ) {
			if ( 'iCCP' === $chunk['type'] && 'pngquant' === \WebberZone\Image_Optimizer\Original_Tools::find()['name'] ) {
				return false;
			}
		}
		$keep = array( 'iCCP' );
		if ( ! $strip ) {
			$keep = array_merge( $keep, array( 'eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME' ) );
		}
		$metadata = '';
		foreach ( $inputs[0] as $chunk ) {
			if ( in_array( $chunk['type'], $keep, true ) ) {
				$metadata .= $chunk['bytes'];
			}
		}
		$output = "\x89PNG\r\n\x1a\n";
		foreach ( $inputs[1] as $chunk ) {
			if ( in_array( $chunk['type'], array( 'iCCP', 'eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME' ), true ) ) {
				continue;
			}
			$output .= $chunk['bytes'];
			if ( 'IHDR' === $chunk['type'] ) {
				$output .= $metadata;
			}
		}
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return false !== file_put_contents( $target, $output );
	}
}
