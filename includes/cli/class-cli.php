<?php
/**
 * WP-CLI commands.
 *
 * @package WebberZone\Image_Optimizer
 */

namespace WebberZone\Image_Optimizer\CLI;

use WebberZone\Image_Optimizer\Attachment_Meta;
use WebberZone\Image_Optimizer\Capabilities;
use WebberZone\Image_Optimizer\Converter;
use WebberZone\Image_Optimizer\Processor;
use WebberZone\Image_Optimizer\Queue;
use WebberZone\Image_Optimizer\Scanner;
use WebberZone\Image_Optimizer\Util\Helpers;

if ( ! defined( 'WPINC' ) ) {
	exit;
}

/**
 * Manage WebP and AVIF conversion from the command line.
 *
 * @since 0.9.0
 */
class CLI {

	/**
	 * Show what this server can encode and how much of the library is done.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wzio status
	 *
	 * @since 0.9.0
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function status( $args, $assoc_args ) {
		unset( $args, $assoc_args );

		$report = Capabilities::get();

		\WP_CLI::log( 'Drivers:' );

		foreach ( $report['drivers'] as $driver => $formats ) {
			$parts = array();

			foreach ( $formats as $format => $works ) {
				$parts[] = $format . '=' . ( $works ? 'yes' : 'no' );
			}

			\WP_CLI::log( '  ' . str_pad( $driver, 10 ) . implode( '  ', $parts ) );
		}

		\WP_CLI::log( 'Active encoders: ' . ( empty( $report['formats'] ) ? 'none' : wp_json_encode( $report['formats'] ) ) );
		\WP_CLI::log( 'Configured formats: ' . implode( ', ', Converter::get_args()['formats'] ) );

		$counts = Queue::get_counts();

		\WP_CLI::log( '' );
		\WP_CLI::log( sprintf( 'Convertible attachments: %d', Scanner::count_candidates() ) );
		\WP_CLI::log( sprintf( 'With a conversion record: %d', Scanner::count_optimized() ) );
		\WP_CLI::log(
			sprintf(
				'Queue: %d pending, %d processing, %d done, %d failed, %d skipped',
				$counts[ Queue::PENDING ],
				$counts[ Queue::PROCESSING ],
				$counts[ Queue::DONE ],
				$counts[ Queue::FAILED ],
				$counts[ Queue::SKIPPED ]
			)
		);
		\WP_CLI::log( sprintf( 'Saved so far: %s', Helpers::format_bytes( (int) ( $counts['bytes_saved'] ?? 0 ) ) ) );
	}

	/**
	 * Convert one or more attachments immediately.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Attachment IDs to convert. Omit to convert everything not yet handled.
	 *
	 * [--force]
	 * : Re-encode even when an up-to-date optimized copy already exists.
	 *
	 * [--formats=<formats>]
	 * : Comma separated list of formats to generate, overriding the settings.
	 *
	 * [--dry-run]
	 * : Report what would be converted without writing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wzio convert 7214
	 *     wp wzio convert --formats=webp,avif --force
	 *
	 * @since 0.9.0
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function convert( $args, $assoc_args ) {
		$force   = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );
		$dry_run = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$formats = \WP_CLI\Utils\get_flag_value( $assoc_args, 'formats', '' );

		$overrides = array( 'force' => $force );

		if ( '' !== $formats ) {
			$overrides['formats'] = array_values(
				array_intersect( Helpers::get_formats(), wp_parse_list( $formats ) )
			);
		}

		$ids = array_map( 'intval', $args );

		if ( empty( $ids ) ) {
			$ids      = array();
			$after_id = 0;
			$found    = 0;

			do {
				$page  = Scanner::get_candidate_ids( 500, $after_id, ! $force );
				$found = count( $page );

				if ( 0 === $found ) {
					break;
				}

				$ids      = array_merge( $ids, $page );
				$after_id = (int) end( $page );
			} while ( 500 === $found );
		}

		if ( empty( $ids ) ) {
			\WP_CLI::success( 'Nothing to convert.' );
			return;
		}

		if ( $dry_run ) {
			\WP_CLI::success( sprintf( '%d attachment(s) would be converted.', count( $ids ) ) );
			return;
		}

		$progress = \WP_CLI\Utils\make_progress_bar( 'Converting', count( $ids ) );
		$saved    = 0;
		$failed   = 0;

		foreach ( $ids as $id ) {
			$summary = Converter::convert_attachment( $id, $overrides );

			if ( is_wp_error( $summary ) ) {
				++$failed;
				\WP_CLI::warning( sprintf( '#%d: %s', $id, $summary->get_error_message() ) );
			} else {
				$saved += (int) $summary['saved'];

				foreach ( $summary['errors'] as $error ) {
					\WP_CLI::warning( sprintf( '#%d: %s', $id, $error ) );
				}
			}

			$progress->tick();
		}

		$progress->finish();

		\WP_CLI::success(
			sprintf(
				'Processed %d attachment(s), %d failed, saved %s.',
				count( $ids ),
				$failed,
				Helpers::format_bytes( $saved )
			)
		);
	}

	/**
	 * Add every unconverted attachment to the background queue.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Requeue attachments that already have a conversion record.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wzio queue
	 *
	 * @since 0.9.0
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function queue( $args, $assoc_args ) {
		unset( $args );

		$force  = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );
		$queued = Scanner::enqueue_all( $force );

		Processor::maybe_schedule();

		\WP_CLI::success( sprintf( 'Queued %d attachment(s).', $queued ) );
	}

	/**
	 * Work through the queue.
	 *
	 * ## OPTIONS
	 *
	 * [--batch=<size>]
	 * : Attachments per batch. Defaults to the configured batch size.
	 *
	 * [--max-batches=<count>]
	 * : Stop after this many batches. Defaults to running until the queue is empty.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wzio run
	 *
	 * @since 0.9.0
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function run( $args, $assoc_args ) {
		unset( $args );

		$batch       = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'batch', 0 );
		$max_batches = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'max-batches', 0 );

		$batches = 0;
		$saved   = 0;

		do {
			$result = Processor::run_batch( $batch > 0 ? $batch : null );

			if ( $result['locked'] ) {
				\WP_CLI::error( 'Another worker holds the queue lock. Try again shortly.' );
			}

			$saved += (int) $result['saved'];
			++$batches;

			\WP_CLI::log(
				sprintf(
					'Batch %d: %d converted, %d skipped, %d failed, %d remaining.',
					$batches,
					$result['converted'],
					$result['skipped'],
					$result['failed'],
					$result['remaining']
				)
			);

			if ( 0 === $result['processed'] ) {
				break;
			}
		} while ( $result['remaining'] > 0 && ( 0 === $max_batches || $batches < $max_batches ) );

		\WP_CLI::success( sprintf( 'Finished %d batch(es), saved %s.', $batches, Helpers::format_bytes( $saved ) ) );
	}

	/**
	 * Delete the generated files for one or more attachments.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Attachment IDs. Omit to clean every attachment that has a record.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wzio clean 7214
	 *
	 * @since 0.9.0
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function clean( $args, $assoc_args ) {
		$ids = array_map( 'intval', $args );

		if ( empty( $ids ) ) {
			\WP_CLI::confirm( 'Delete every generated WebP and AVIF file? Original images are not touched.', $assoc_args );

			global $wpdb;

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids = array_map(
				'intval',
				(array) $wpdb->get_col(
					$wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", Attachment_Meta::META_KEY )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		$is_full_clean = empty( $args );

		$deleted = 0;

		foreach ( $ids as $id ) {
			$deleted += Converter::delete_sidecars( $id );

			if ( ! $is_full_clean ) {
				Queue::remove( $id );
			}
		}

		if ( $is_full_clean ) {
			Queue::clear();
		}

		\WP_CLI::success( sprintf( 'Deleted %d generated file(s).', $deleted ) );
	}
}
