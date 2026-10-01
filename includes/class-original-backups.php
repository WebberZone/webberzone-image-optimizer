<?php
/**
 * Durable recovery for files changed by original optimization.
 *
 * @package WebberZone\Image_Optimizer
 */

namespace WebberZone\Image_Optimizer;

use WebberZone\Image_Optimizer\Util\Helpers;

if ( ! defined( 'WPINC' ) ) {
	exit;
}

/**
 * Owns immutable backups independently of disposable conversion records.
 *
 * @since 1.2.0
 */
class Original_Backups {

	/** Recovery metadata key. @var string */
	const META_KEY = '_wzio_originals';

	/**
	 * Read the recovery manifest.
	 *
	 * @param int $id Attachment ID.
	 * @return array<string, array<string, mixed>> Manifest.
	 */
	public static function get( int $id ): array {
		$data = get_post_meta( $id, self::META_KEY, true );
		return is_array( $data ) ? array_filter( $data, 'is_array' ) : array();
	}

	/**
	 * Persist recovery data and verify the database accepted it.
	 *
	 * @param int   $id Attachment ID.
	 * @param array $data Recovery manifest.
	 * @return bool Whether persisted.
	 */
	public static function save( int $id, array $data ): bool {
		update_post_meta( $id, self::META_KEY, wp_slash( $data ) );
		$totals = self::totals( $data );
		foreach ( $totals as $key => $value ) {
			update_post_meta( $id, '_wzio_original_' . $key, $value );
		}
		delete_transient( 'wzio_original_totals' );
		return self::get( $id ) === $data;
	}

	/**
	 * Resolve a regular upload file without following links outside uploads.
	 *
	 * @param string $path File path.
	 * @return string Relative path, or empty when unsafe.
	 */
	public static function relative( string $path ): string {
		$base = realpath( Helpers::get_upload_basedir() );
		$file = realpath( $path );
		if ( false === $base || false === $file || ! is_file( $file ) || is_link( $path ) ) {
			return '';
		}
		$base = trailingslashit( wp_normalize_path( $base ) );
		$file = wp_normalize_path( $file );
		if ( 0 !== strpos( $file, $base ) ) {
			return '';
		}
		$relative = substr( $file, strlen( $base ) );
		return 0 === validate_file( $relative ) && 0 !== strpos( $relative, 'wzio-originals/' ) ? $relative : '';
	}

	/**
	 * Create the backup root and access-control files.
	 *
	 * @return string|\WP_Error Root or error.
	 */
	public static function root() {
		$base = realpath( Helpers::get_upload_basedir() );
		if ( false === $base ) {
			return new \WP_Error( 'wzio_backup_root', __( 'The uploads directory is unavailable.', 'webberzone-image-optimizer' ) );
		}
		$root = $base . '/wzio-originals';
		if ( is_link( $root ) || ! wp_mkdir_p( $root ) || realpath( $root ) !== $root ) {
			return new \WP_Error( 'wzio_backup_root', __( 'The backup directory could not be created safely.', 'webberzone-image-optimizer' ) );
		}
		$guards = array(
			'index.php'  => "<?php\nexit;\n",
			'.htaccess'  => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			'web.config' => '<configuration><system.webServer><security><authorization><remove users="*" roles="" verbs="" /><add accessType="Deny" users="*" /></authorization></security></system.webServer></configuration>',
		);
		foreach ( $guards as $name => $contents ) {
			$file = $root . '/' . $name;
			if ( is_link( $file ) ) {
				return new \WP_Error( 'wzio_backup_protection', __( 'Backup protection could not be installed.', 'webberzone-image-optimizer' ) );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( ! file_exists( $file ) && false === file_put_contents( $file, $contents, LOCK_EX ) ) {
				return new \WP_Error( 'wzio_backup_protection', __( 'Backup protection could not be installed.', 'webberzone-image-optimizer' ) );
			}
		}
		return $root;
	}

	/**
	 * Acquire an OS lock which is also released on process exit.
	 *
	 * @param int $id Attachment ID.
	 * @return resource|\WP_Error Lock handle or error.
	 */
	public static function acquire( int $id ) {
		$root = self::root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$path = $root . '/attachment-' . $id . '.lock';
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			if ( is_link( $path ) ) {
				return new \WP_Error( 'wzio_original_locked', __( 'This attachment cannot be locked safely.', 'webberzone-image-optimizer' ) );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$handle = fopen( $path, 'c' );
			if ( false === $handle ) {
				return new \WP_Error( 'wzio_original_locked', __( 'This attachment could not be locked.', 'webberzone-image-optimizer' ) );
			}
			if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $handle );
				return new \WP_Error( 'wzio_original_locked', __( 'Another operation is processing this attachment.', 'webberzone-image-optimizer' ) );
			}
			clearstatcache( true, $path );
			$held = fstat( $handle );
			$disk = @stat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			// A releasing process unlinks the file while holding the lock, so a stale inode means we locked a deleted file.
			if ( $held && $disk && $held['ino'] === $disk['ino'] ) {
				return $handle;
			}
			flock( $handle, LOCK_UN );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $handle );
		}
		return new \WP_Error( 'wzio_original_locked', __( 'This attachment could not be locked.', 'webberzone-image-optimizer' ) );
	}

	/**
	 * Execute with automatic lock release.
	 *
	 * @param int      $id Attachment ID.
	 * @param callable $callback Operation.
	 * @return mixed Operation result.
	 */
	public static function locked( int $id, callable $callback ) {
		$handle = self::acquire( $id );
		if ( is_wp_error( $handle ) ) {
			return $handle;
		}
		try {
			return $callback();
		} finally {
			self::release( $handle );
		}
	}

	/**
	 * Release an acquired lock.
	 *
	 * @param resource $handle Lock handle.
	 * @return void
	 */
	public static function release( $handle ): void {
		$meta = stream_get_meta_data( $handle );
		if ( ! empty( $meta['uri'] ) && ! is_link( $meta['uri'] ) ) {
			@unlink( $meta['uri'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		flock( $handle, LOCK_UN );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );
	}

	/**
	 * Make and persist the first backup before modifying a file.
	 *
	 * Caller must hold the attachment lock.
	 *
	 * @param int    $id Attachment ID.
	 * @param string $path Source file.
	 * @return array|\WP_Error Backup entry or error.
	 */
	public static function ensure( int $id, string $path ) {
		$relative = self::relative( $path );
		if ( '' === $relative ) {
			return new \WP_Error( 'wzio_backup_path', __( 'The source is not a safe local upload.', 'webberzone-image-optimizer' ) );
		}
		$data = self::get( $id );
		if ( isset( $data[ $relative ] ) ) {
			$backup = self::verified_path( $relative, $data[ $relative ] );
			return is_wp_error( $backup ) ? $backup : $data[ $relative ];
		}
		$root = self::root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$backup   = $root . '/' . $relative;
		$dir      = dirname( $backup );
		$ancestor = $dir;
		while ( ! file_exists( $ancestor ) && dirname( $ancestor ) !== $ancestor ) {
			$ancestor = dirname( $ancestor );
		}
		$resolved = realpath( $ancestor );
		if ( false === $resolved || ( $resolved !== $root && 0 !== strpos( $resolved, $root . '/' ) ) ) {
			return new \WP_Error( 'wzio_backup_path', __( 'The backup path is unsafe.', 'webberzone-image-optimizer' ) );
		}

		if ( ! wp_mkdir_p( $dir ) || ( realpath( $dir ) !== $root && 0 !== strpos( (string) realpath( $dir ), $root . '/' ) ) || is_link( $backup ) ) {
			return new \WP_Error( 'wzio_backup_path', __( 'The backup path is unsafe or unavailable.', 'webberzone-image-optimizer' ) );
		}
		$hash = hash_file( 'sha256', $path );
		if ( false === $hash ) {
			return new \WP_Error( 'wzio_backup_read', __( 'The source could not be read.', 'webberzone-image-optimizer' ) );
		}
		if ( file_exists( $backup ) ) {
			// A file at the final path is always a complete copy, so it is never deleted, even when it differs from the source.
			if ( ! hash_equals( $hash, (string) hash_file( 'sha256', $backup ) ) ) {
				return new \WP_Error( 'wzio_backup_mismatch', __( 'A different backup already exists. It was kept and the source was not changed.', 'webberzone-image-optimizer' ) );
			}
		} else {
			$partial = $backup . '.partial';
			if ( is_link( $partial ) ) {
				return new \WP_Error( 'wzio_backup_path', __( 'The backup path is unsafe or unavailable.', 'webberzone-image-optimizer' ) );
			}
			if ( file_exists( $partial ) ) {
				Helpers::delete_file( $partial );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			if ( ! copy( $path, $partial ) || ! hash_equals( $hash, (string) hash_file( 'sha256', $partial ) ) ) {
				Helpers::delete_file( $partial );
				return new \WP_Error( 'wzio_backup_write', __( 'The backup could not be created. The source was not changed.', 'webberzone-image-optimizer' ) );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			if ( ! rename( $partial, $backup ) ) {
				Helpers::delete_file( $partial );
				return new \WP_Error( 'wzio_backup_write', __( 'The backup could not be created. The source was not changed.', 'webberzone-image-optimizer' ) );
			}
		}
		$dimensions        = wp_getimagesize( $path );
		$entry             = array(
			'backup' => $relative,
			'hash'   => $hash,
			'before' => (int) filesize( $backup ),
			'width'  => (int) ( $dimensions[0] ?? 0 ),
			'height' => (int) ( $dimensions[1] ?? 0 ),
			'state'  => 'backed_up',
		);
		$data[ $relative ] = $entry;
		if ( ! self::save( $id, $data ) ) {
			return new \WP_Error( 'wzio_backup_record', __( 'The recovery record could not be saved. The source was not changed.', 'webberzone-image-optimizer' ) );
		}
		return $entry;
	}

	/**
	 * Verify a manifest entry before reading a backup.
	 *
	 * @param string $relative Upload-relative source.
	 * @param array  $entry Manifest entry.
	 * @return string|\WP_Error Verified backup path.
	 */
	public static function verified_path( string $relative, array $entry ) {
		$base = realpath( Helpers::get_upload_basedir() );
		$root = false !== $base ? realpath( $base . '/wzio-originals' ) : false;
		if ( false === $base || $root !== $base . '/wzio-originals' || is_link( $base . '/wzio-originals' ) ) {
			return new \WP_Error( 'wzio_backup_invalid', __( 'The backup directory is unsafe or unavailable.', 'webberzone-image-optimizer' ) );
		}
		if ( '' === $relative || 0 !== validate_file( $relative ) || '/' === $relative[0] || false !== strpos( $relative, '\\' ) || 0 === strpos( $relative, 'wzio-originals/' ) ) {
			return new \WP_Error( 'wzio_backup_invalid', __( 'The backup path is invalid.', 'webberzone-image-optimizer' ) );
		}
		$path     = $root . '/' . $relative;
		$resolved = realpath( $path );
		if ( false === $resolved || 0 !== strpos( $resolved, $root . '/' ) || is_link( $path ) || ! is_file( $resolved ) || empty( $entry['hash'] ) || ! hash_equals( (string) $entry['hash'], (string) hash_file( 'sha256', $resolved ) ) ) {
			return new \WP_Error( 'wzio_backup_invalid', __( 'The backup is missing or failed verification. Recovery data has been kept.', 'webberzone-image-optimizer' ) );
		}
		return $resolved;
	}
	/**
	 * Calculate original savings separately from sidecar savings.
	 *
	 * @param array $data Manifest.
	 * @return array{saved: int, bytes: int, resized: int, compressed: int} Totals.
	 */
	public static function totals( array $data ): array {
		$totals = array(
			'saved'      => 0,
			'bytes'      => 0,
			'resized'    => 0,
			'compressed' => 0,
		);
		foreach ( $data as $entry ) {
			$totals['bytes'] += (int) ( $entry['before'] ?? 0 );
			if ( 'optimized' === ( $entry['state'] ?? '' ) ) {
				$totals['saved'] += max( 0, (int) $entry['before'] - (int) $entry['after'] );
				++$totals['compressed'];
				if ( ! empty( $entry['resized'] ) ) {
					++$totals['resized'];
				}
			}
		}
		return $totals;
	}

	/**
	 * Aggregate recorded source savings and backup disk use.
	 *
	 * @return array<string, int> Totals.
	 */
	public static function library_totals(): array {
		global $wpdb;
		$cached = get_transient( 'wzio_original_totals' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, SUM(CAST(meta_value AS UNSIGNED)) AS total FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s, %s, %s) GROUP BY meta_key",
				'_wzio_original_saved',
				'_wzio_original_bytes',
				'_wzio_original_resized',
				'_wzio_original_compressed'
			)
		);
		$totals = self::totals( array() );
		foreach ( $rows as $row ) {
			$totals[ substr( $row->meta_key, strlen( '_wzio_original_' ) ) ] = (int) $row->total;
		}
		set_transient( 'wzio_original_totals', $totals, 60 );
		return $totals;
	}
}
