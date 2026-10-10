<?php
/**
 * Optional local PNG compression tools.
 *
 * @package WebberZone\Image_Optimizer
 */

namespace WebberZone\Image_Optimizer;

if ( ! defined( 'WPINC' ) ) {
	exit;
}

/**
 * Executes verified PNG encoders with a bounded runtime.
 *
 * @since 1.2.0
 */
class Original_Tools {

	/**
	 * Whether the last lookup skipped a candidate because of open_basedir.
	 *
	 * @var bool
	 */
	private static $restricted = false;

	/**
	 * Locate a known PNG tool.
	 *
	 * @return array{name: string, path: string} Tool or empty values.
	 */
	public static function find(): array {
		$none             = array(
			'name' => '',
			'path' => '',
		);
		self::$restricted = false;
		if ( ! function_exists( 'proc_open' ) || ! function_exists( 'proc_get_status' ) || ! function_exists( 'proc_terminate' ) ) {
			return $none;
		}
		$paths = array();
		foreach ( array( 'oxipng', 'pngquant' ) as $name ) {
			foreach ( array( '/usr/bin', '/usr/local/bin', '/opt/homebrew/bin' ) as $directory ) {
				$paths[] = $directory . '/' . $name;
			}
		}

		/**
		 * Filters the absolute paths checked, in order, for a PNG compression tool.
		 *
		 * Each file must be named oxipng or pngquant.
		 *
		 * @since 1.2.1
		 *
		 * @param array $paths Candidate binary paths as strings.
		 */
		$paths = (array) apply_filters( 'wzio_png_tool_paths', $paths );
		foreach ( $paths as $path ) {
			if ( ! is_string( $path ) || 0 !== strpos( $path, '/' ) ) {
				continue;
			}
			$name = basename( $path );
			if ( ! in_array( $name, array( 'oxipng', 'pngquant' ), true ) ) {
				continue;
			}
			if ( ! self::is_path_allowed( $path ) ) {
				self::$restricted = true;
				continue;
			}
			if ( is_executable( $path ) && is_file( $path ) ) {
				self::$restricted = false;
				return array(
					'name' => $name,
					'path' => $path,
				);
			}
		}
		return $none;
	}

	/**
	 * Whether the last lookup found no tool and skipped paths outside open_basedir.
	 *
	 * @return bool Restricted by open_basedir.
	 */
	public static function blocked_by_open_basedir(): bool {
		return self::$restricted;
	}

	/**
	 * Check a path against open_basedir without touching the filesystem.
	 *
	 * @param string $path Absolute path.
	 * @return bool Path may be accessed.
	 */
	private static function is_path_allowed( string $path ): bool {
		$open_basedir = (string) ini_get( 'open_basedir' );
		if ( '' === $open_basedir ) {
			return true;
		}
		// String match only: realpath() and friends would trigger the same warning. Symlinks resolving outside still fail.
		foreach ( explode( PATH_SEPARATOR, $open_basedir ) as $directory ) {
			$directory = trim( $directory, " \t\n\r\0\x0B" );
			if ( '' === $directory || 0 !== strpos( $directory, '/' ) ) {
				continue;
			}
			if ( $path === $directory || 0 === strpos( $path, rtrim( $directory, '/' ) . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Compress a temporary PNG in place, never the served original.
	 *
	 * @param string $path Temporary PNG.
	 * @param bool   $strip Strip ancillary metadata.
	 * @return bool Successful encode.
	 */
	public static function compress( string $path, bool $strip ): bool {
		$tool = self::find();
		if ( '' === $tool['path'] ) {
			return false;
		}
		if ( 'oxipng' === $tool['name'] ) {
			$command = array( $tool['path'], '-o', '2', '--timeout', '15' );
			if ( $strip ) {
				$command = array_merge( $command, array( '--strip', 'safe' ) );
			}
			$command[] = '--';
			$command[] = $path;
		} else {
			$command = array( $tool['path'], '--force', '--output', $path, '--', $path );
		}
		return self::run( $command ) && 'image/png' === wp_get_image_mime( $path );
	}

	/**
	 * Execute without a shell and stop a hung encoder.
	 *
	 * @param array<int, string> $command Arguments.
	 * @return bool Zero exit status.
	 */
	private static function run( array $command ): bool {
		$pipes = array();
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open
		$process = proc_open(
			$command,
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		if ( ! is_resource( $process ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $pipes[0] );
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );
		$deadline = microtime( true ) + 20;
		$exit     = -1;
		do {
			stream_get_contents( $pipes[1], 8192 );
			stream_get_contents( $pipes[2], 8192 );
			$status = proc_get_status( $process );
			if ( ! $status['running'] ) {
				$exit = (int) $status['exitcode'];
				break;
			}
			if ( microtime( true ) >= $deadline ) {
				proc_terminate( $process, 9 );
				break;
			}
			usleep( 10000 );
		} while ( true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $pipes[1] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $pipes[2] );
		proc_close( $process );
		return 0 === $exit;
	}
}
