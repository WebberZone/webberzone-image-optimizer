<?php
/**
 * WP-CLI commands.
 *
 * @package WebberZone\Image_Optimizer
 */

namespace WebberZone\Image_Optimizer\CLI;

use WebberZone\Image_Optimizer\Attachment_Meta;
use WebberZone\Image_Optimizer\Original_Backups;
use WebberZone\Image_Optimizer\Original_Optimizer;
use WebberZone\Image_Optimizer\Capabilities;
use WebberZone\Image_Optimizer\Converter;
use WebberZone\Image_Optimizer\Cron_Health;
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
 * @since 1.0.0
 */
class CLI {

	/**
	 * Show what this server can encode and how much of the library is done.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wzio status
	 *
	 * @since 1.0.0
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
		\WP_CLI::log( 'Original encoders: ' . wp_json_encode( Capabilities::get_originals() ) );
		$original_totals = Original_Backups::library_totals();
		\WP_CLI::log( 'Original bytes saved: ' . Helpers::format_bytes( $original_totals['saved'] ) );
		\WP_CLI::log( 'Backup disk usage: ' . Helpers::format_bytes( $original_totals['bytes'] ) );
		\WP_CLI::log( 'Main images resized: ' . $original_totals['resized'] );
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
		$totals = Scanner::get_byte_totals();

		\WP_CLI::log( sprintf( 'Saved so far: %s', Helpers::format_bytes( $totals['saved'] ) ) );
		\WP_CLI::log( sprintf( 'Optimized copies occupy: %s', Helpers::format_bytes( $totals['copies'] ) ) );

		foreach ( $totals['formats'] as $format => $bytes ) {
			\WP_CLI::log( sprintf( '  %s: %s', strtoupper( $format ), Helpers::format_bytes( $bytes ) ) );
		}
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
	 * [--outdated]
	 * : Re-encode only attachments whose copies were made with older settings. Copies from before settings were tracked are left alone.
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
	 * @since 1.0.0
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function convert( $args, $assoc_args ) {
		$force    = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );
		$dry_run  = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$outdated = ! $force && (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'outdated', false );
		$formats  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'formats', '' );

		$overrides = array(
			'force'    => $force,
			'outdated' => $outdated,
		);

		if ( '' !== $formats ) {
			$overrides['formats'] = array_values(
				array_intersect( Helpers::get_formats(), wp_parse_list( $formats ) )
			);
		}

		$ids = array_map( 'intval', $args );

		if ( empty( $ids ) && $outdated ) {
			$after_id = 0;

			do {
				$page     = Scanner::get_outdated_ids( 500, $after_id, $overrides );
				$ids      = array_merge( $ids, $page['ids'] );
				$after_id = $page['last'];
			} while ( ! $page['exhausted'] );
		} elseif ( empty( $ids ) ) {
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

		if ( $outdated && ! empty( $args ) ) {
			$conversion_args = Converter::get_args( $overrides );
			$ids             = array_values(
				array_filter(
					$ids,
					static function ( $id ) use ( $conversion_args ) {
						return Attachment_Meta::is_outdated( Attachment_Meta::get( $id ), $conversion_args, (string) get_post_mime_type( $id ) );
					}
				)
			);
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
		$reduced  = 0;

		foreach ( $ids as $id ) {
			$summary = Converter::convert_attachment( $id, $overrides );

			if ( is_wp_error( $summary ) ) {
				++$failed;
				\WP_CLI::warning( sprintf( '#%d: %s', $id, $summary->get_error_message() ) );
			} else {
				$saved   += (int) $summary['saved'];
				$reduced += (int) $summary['reduced'];

				foreach ( $summary['errors'] as $error ) {
					\WP_CLI::warning( sprintf( '#%d: %s', $id, $error ) );
				}
			}

			$progress->tick();
		}

		$progress->finish();

		if ( $reduced > 0 ) {
			\WP_CLI::log(
				sprintf(
					/* translators: %d: number of optimized copies. */
					_n(
						'%d optimized copy needed a lower quality than configured to come out smaller than the original.',
						'%d optimized copies needed a lower quality than configured to come out smaller than the original.',
						$reduced,
						'webberzone-image-optimizer'
					),
					$reduced
				)
			);
		}

		Scanner::flush_counts();

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
	 * [--outdated]
	 * : Requeue only attachments whose copies were made with older settings.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wzio queue
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function queue( $args, $assoc_args ) {
		unset( $args );

		$force  = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );
		$queued = Scanner::enqueue_all( $force, (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'outdated', false ) );

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
	 * @since 1.0.0
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

		// Counts as a worker run so a crontab calling this is not reported as a stalled queue.
		Cron_Health::record_run();

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
	 * @since 1.0.0
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
	/**
	 * Compress served JPEG/PNG originals with immutable backups.
	 *
	 * ## OPTIONS
	 *
	 * [--ids=<ids>]
	 * : Comma-separated attachment IDs. Omit for the whole library.
	 *
	 * [--dry-run]
	 * : List candidate attachments without changing files, records or the queue.
	 *
	 * [--resize]
	 * : Resize main files to the configured Maximum image dimension.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function compress( $args, $assoc_args ) {
		unset( $args );
		$resize = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'resize', false );
		if ( $resize && ( ! \wzio_get_option( 'maximum_image_dimension', 0 ) || \wzio_get_option( 'disable_image_scaling', false ) ) ) {
			\WP_CLI::error( 'Set a positive Maximum image dimension and enable scaling before using --resize.' );
		}
		$resize_filter = static function () use ( $resize ) {
			return $resize;
		};
		add_filter( 'wzio_get_option_compress_originals', '__return_true' );
		add_filter( 'wzio_get_option_resize_existing_originals', $resize_filter );
		$failed = 0;
		try {
			foreach ( $this->original_ids( $assoc_args ) as $id ) {
				if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false ) ) {
					\WP_CLI::log( sprintf( '#%d: candidate for original compression%s; eligibility and savings are checked during encoding.', $id, $resize ? ' and resizing' : '' ) );
					continue;
				}
				$result = Converter::convert_attachment( $id, array( 'originals_explicit' => true ) );
				if ( is_wp_error( $result ) || ! empty( $result['failed'] ) ) {
					++$failed;
					\WP_CLI::warning( sprintf( '#%d: %s', $id, is_wp_error( $result ) ? $result->get_error_message() : implode( '; ', $result['errors'] ) ) );
				}
			}
		} finally {
			remove_filter( 'wzio_get_option_compress_originals', '__return_true' );
			remove_filter( 'wzio_get_option_resize_existing_originals', $resize_filter );
		}
		Scanner::flush_counts();
		if ( $failed ) {
			\WP_CLI::error( sprintf( '%d attachment(s) failed; backups were retained.', $failed ) );
		}
		\WP_CLI::success( 'Original compression pass complete.' );
	}

	/**
	 * Restore original files and dimensions from backups.
	 *
	 * ## OPTIONS
	 *
	 * [--ids=<ids>]
	 * : Comma-separated attachment IDs.
	 *
	 * [--all]
	 * : Restore every attachment with backups.
	 *
	 * [--dry-run]
	 * : List attachments with backups without restoring them.
	 *
	 * @subcommand restore-originals
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function restore_originals( $args, $assoc_args ) {
		unset( $args );
		if ( empty( $assoc_args['ids'] ) && ! \WP_CLI\Utils\get_flag_value( $assoc_args, 'all', false ) ) {
			\WP_CLI::error( 'Specify --ids or --all.' );
		}
		$failed = 0;
		foreach ( $this->original_ids( $assoc_args, true ) as $id ) {
			if ( ! Original_Backups::get( $id ) ) {
				continue;
			}
			if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false ) ) {
				\WP_CLI::log( sprintf( '#%d: would restore originals.', $id ) );
				continue;
			}
			$result = Original_Optimizer::restore( $id );
			if ( is_wp_error( $result ) ) {
				++$failed;
				\WP_CLI::warning( sprintf( '#%d: %s', $id, $result->get_error_message() ) );
			}
		}
		Scanner::flush_counts();
		if ( $failed ) {
			\WP_CLI::error( sprintf( '%d attachment(s) could not be restored; recovery data was retained.', $failed ) );
		}
		\WP_CLI::success( 'Original restoration pass complete.' );
	}

	/**
	 * Stream candidate IDs without loading the whole library into memory.
	 *
	 * @param array $arguments Command arguments.
	 * @param bool  $backups_only Scan recorded backups instead of convertible attachments.
	 * @return \Generator<int> IDs.
	 */
	private function original_ids( array $arguments, bool $backups_only = false ): \Generator {
		if ( isset( $arguments['ids'] ) ) {
			$ids = wp_parse_list( $arguments['ids'] );
			if ( ! $ids ) {
				\WP_CLI::error( '--ids must contain positive attachment IDs.' );
			}
			foreach ( $ids as $id ) {
				if ( ! ctype_digit( (string) $id ) || (int) $id < 1 || ( 'attachment' !== get_post_type( (int) $id ) || ( ! $backups_only && ! Converter::is_convertible_attachment( (int) $id ) ) ) ) {
					\WP_CLI::error( 'Every --ids value must identify a supported image attachment.' );
				}
			}
			foreach ( array_unique( $ids ) as $id ) {
				yield (int) $id;
			}
			return;
		}
		$after = 0;
		do {
			if ( $backups_only ) {
				global $wpdb;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id > %d ORDER BY post_id LIMIT 250", Original_Backups::META_KEY, $after ) ) );
			} else {
				$ids = Scanner::get_candidate_ids( 250, $after, false );
			}
			$found = count( $ids );
			foreach ( $ids as $id ) {
				$after = (int) $id;
				yield $after;
			}
		} while ( 250 === $found );
	}
}
