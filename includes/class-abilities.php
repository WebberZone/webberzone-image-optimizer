<?php
/**
 * Abilities API integration.
 *
 * @package WebberZone\Image_Optimizer
 */

namespace WebberZone\Image_Optimizer;

use WebberZone\Image_Optimizer\Admin\Settings;
use WebberZone\Image_Optimizer\Util\Hook_Registry;

if ( ! defined( 'WPINC' ) ) {
	exit;
}

/**
 * Registers abilities so AI assistants and other clients can run the plugin.
 *
 * The Abilities API ships with WordPress 6.9. On older versions its hooks never
 * fire, so nothing is registered.
 *
 * @since 1.3.0
 */
class Abilities {

	/**
	 * Ability category slug.
	 *
	 * @since 1.3.0
	 * @var string
	 */
	const CATEGORY = 'wzio';

	/**
	 * Capability needed to see the library status, run the queue and change settings.
	 *
	 * @since 1.3.0
	 * @var string
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Settings an ability may change.
	 *
	 * Compressing originals, the file naming switch and the uninstall clean-up options
	 * are left out: each changes or deletes files and should stay a deliberate choice
	 * on the settings screen.
	 *
	 * @since 1.3.0
	 * @var array<int, string>
	 */
	const SETTINGS = array(
		'formats',
		'convert_on_upload',
		'convert_sizes',
		'maximum_image_dimension',
		'disable_image_scaling',
		'min_saving',
		'quality_webp',
		'quality_avif',
		'effort_webp',
		'effort_avif',
		'strip_metadata',
		'lossless_png',
		'png_lossy_quality',
		'enable_delivery',
		'rewrite_content',
		'rewrite_template',
		'rewrite_buffer',
		'batch_size',
		'background_queue',
		'lazy_convert',
		'exclude_paths',
	);

	/**
	 * Constructor.
	 *
	 * @since 1.3.0
	 */
	public function __construct() {
		Hook_Registry::add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		Hook_Registry::add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Register the ability category.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function register_category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Image Optimizer', 'webberzone-image-optimizer' ),
				'description' => __( 'Convert media library images to WebP and AVIF, and manage how they are served.', 'webberzone-image-optimizer' ),
			)
		);
	}

	/**
	 * Register the abilities.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function register_abilities(): void {
		$counts = array(
			'type'       => 'object',
			'properties' => array(
				'pending'    => array( 'type' => 'integer' ),
				'processing' => array( 'type' => 'integer' ),
				'done'       => array( 'type' => 'integer' ),
				'failed'     => array( 'type' => 'integer' ),
				'skipped'    => array( 'type' => 'integer' ),
			),
		);

		wp_register_ability(
			'wzio/get-status',
			array(
				'label'               => __( 'Get image optimization status', 'webberzone-image-optimizer' ),
				'description'         => __( 'Report how much of the media library has WebP or AVIF copies, the queue counts, bytes saved, which formats this server can encode, whether the background queue is running, and the most recent failures.', 'webberzone-image-optimizer' ),
				'category'            => self::CATEGORY,
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'candidates' => array(
							'type'        => 'integer',
							'description' => __( 'Attachments the plugin can convert.', 'webberzone-image-optimizer' ),
						),
						'optimized'  => array(
							'type'        => 'integer',
							'description' => __( 'Attachments with a conversion record.', 'webberzone-image-optimizer' ),
						),
						'remaining'  => array(
							'type'        => 'integer',
							'description' => __( 'Attachments pending or processing in the queue.', 'webberzone-image-optimizer' ),
						),
						'queue'      => $counts,
						'bytes'      => array(
							'type'       => 'object',
							'properties' => array(
								'source'        => array( 'type' => 'integer' ),
								'saved'         => array( 'type' => 'integer' ),
								'saved_percent' => array( 'type' => 'number' ),
								'copies'        => array( 'type' => 'integer' ),
								'formats'       => array(
									'type'                 => 'object',
									'additionalProperties' => array( 'type' => 'integer' ),
								),
							),
						),
						'formats'    => array(
							'type'       => 'object',
							'properties' => array(
								'configured' => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
								'supported'  => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
							),
						),
						'background' => array(
							'type'       => 'object',
							'properties' => array(
								'enabled' => array( 'type' => 'boolean' ),
								'status'  => array(
									'type'        => 'string',
									'enum'        => array( Cron_Health::HEALTHY, Cron_Health::DISABLED, Cron_Health::STALLED ),
									'description' => __( 'disabled means WP-Cron is turned off on this site; stalled means queued work has not been picked up for a while. Either way, call wzio/run-batch to work through the queue.', 'webberzone-image-optimizer' ),
								),
							),
						),
						'failures'   => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'attachment_id' => array( 'type' => 'integer' ),
									'error'         => array( 'type' => 'string' ),
									'attempts'      => array( 'type' => 'integer' ),
									'updated'       => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'execute_callback'    => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'meta'                => $this->meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wzio/queue-bulk',
			array(
				'label'               => __( 'Queue images for bulk optimization', 'webberzone-image-optimizer' ),
				'description'         => __( 'Add media library images that need WebP or AVIF copies to the background queue, the same as starting Bulk Optimize. Large libraries are scanned in time-limited passes: while done is false, call again with the returned after_id. Queued images are converted in the background by WP-Cron, so no browser needs to stay open. If the background queue is off or WP-Cron is not running, call wzio/run-batch until remaining is 0.', 'webberzone-image-optimizer' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'default'              => array(),
					'additionalProperties' => false,
					'properties'           => array(
						'force'    => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Re-encode every image, including ones already optimized.', 'webberzone-image-optimizer' ),
						),
						'outdated' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Queue only images whose copies were made with older settings, for example after changing quality.', 'webberzone-image-optimizer' ),
						),
						'retry'    => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Give failed and retryable skipped images another try. Applies to the first pass only.', 'webberzone-image-optimizer' ),
						),
						'after_id' => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'default'     => 0,
							'description' => __( 'Cursor returned by the previous pass. Use 0 to start a new scan.', 'webberzone-image-optimizer' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'queued'    => array(
							'type'        => 'integer',
							'description' => __( 'Images added to the queue in this pass.', 'webberzone-image-optimizer' ),
						),
						'after_id'  => array(
							'type'        => 'integer',
							'description' => __( 'Cursor for the next pass.', 'webberzone-image-optimizer' ),
						),
						'done'      => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the scan reached the end of the library.', 'webberzone-image-optimizer' ),
						),
						'remaining' => array(
							'type'        => 'integer',
							'description' => __( 'Images waiting in the queue.', 'webberzone-image-optimizer' ),
						),
					),
				),
				'execute_callback'    => array( $this, 'queue_bulk' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'meta'                => $this->meta( false, false, false ),
			)
		);

		wp_register_ability(
			'wzio/run-batch',
			array(
				'label'               => __( 'Run one optimization batch', 'webberzone-image-optimizer' ),
				'description'         => __( 'Convert the next batch of queued images now instead of waiting for the background queue. Each call is limited to about 20 seconds. Call repeatedly until remaining is 0. If locked is true, another worker is busy; wait a few seconds and try again.', 'webberzone-image-optimizer' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'default'              => array(),
					'additionalProperties' => false,
					'properties'           => array(
						'batch_size' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 200,
							'description' => __( 'Images to process. Defaults to the Images per batch setting.', 'webberzone-image-optimizer' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'processed' => array( 'type' => 'integer' ),
						'converted' => array( 'type' => 'integer' ),
						'failed'    => array( 'type' => 'integer' ),
						'skipped'   => array( 'type' => 'integer' ),
						'deferred'  => array( 'type' => 'integer' ),
						'saved'     => array(
							'type'        => 'integer',
							'description' => __( 'Bytes saved by this batch.', 'webberzone-image-optimizer' ),
						),
						'remaining' => array( 'type' => 'integer' ),
						'locked'    => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( $this, 'run_batch' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'meta'                => $this->meta( false, false, false ),
			)
		);

		wp_register_ability(
			'wzio/optimize-attachment',
			array(
				'label'               => __( 'Optimize one image', 'webberzone-image-optimizer' ),
				'description'         => __( 'Create WebP or AVIF copies of one media library image now.', 'webberzone-image-optimizer' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'attachment_id' ),
					'properties'           => array(
						'attachment_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Attachment ID.', 'webberzone-image-optimizer' ),
						),
						'force'         => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Re-encode files that already have an up-to-date copy.', 'webberzone-image-optimizer' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'status' => array(
							'type'        => 'string',
							'description' => __( 'done, skipped, failed, or pending when the work was deferred. Empty when locked.', 'webberzone-image-optimizer' ),
						),
						'saved'  => array( 'type' => 'integer' ),
						'error'  => array( 'type' => 'string' ),
						'locked' => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( $this, 'optimize_attachment' ),
				'permission_callback' => array( $this, 'can_optimize_attachment' ),
				'meta'                => $this->meta( false, false, true ),
			)
		);

		$settings = $this->settings_schema();

		wp_register_ability(
			'wzio/get-settings',
			array(
				'label'               => __( 'Get image optimizer settings', 'webberzone-image-optimizer' ),
				'description'         => __( 'Read the settings that wzio/update-settings can change, with their current values.', 'webberzone-image-optimizer' ),
				'category'            => self::CATEGORY,
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => self::loosen( $settings ),
				),
				'execute_callback'    => array( $this, 'get_settings' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'meta'                => $this->meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wzio/update-settings',
			array(
				'label'               => __( 'Update image optimizer settings', 'webberzone-image-optimizer' ),
				'description'         => __( 'Change one or more settings. Settings left out keep their values. New quality or format settings apply to images converted from now on; to redo existing images, call wzio/queue-bulk with outdated set to true.', 'webberzone-image-optimizer' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'minProperties'        => 1,
					'properties'           => $settings,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'updated'  => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'settings' => array(
							'type'       => 'object',
							'properties' => self::loosen( $settings ),
						),
					),
				),
				'execute_callback'    => array( $this, 'update_settings' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'meta'                => $this->meta( false, false, true ),
			)
		);
	}

	/**
	 * Whether the current user may manage the plugin.
	 *
	 * @since 1.3.0
	 *
	 * @return bool
	 */
	public function can_manage(): bool {
		return current_user_can( self::CAPABILITY );
	}

	/**
	 * Whether the current user may optimize the requested attachment.
	 *
	 * @since 1.3.0
	 *
	 * @param mixed $input Ability input.
	 * @return bool
	 */
	public function can_optimize_attachment( $input = null ): bool {
		$attachment_id = is_array( $input ) ? absint( $input['attachment_id'] ?? 0 ) : 0;

		return $attachment_id > 0 && current_user_can( 'upload_files' ) && current_user_can( 'edit_post', $attachment_id );
	}

	/**
	 * Report the library and queue status.
	 *
	 * @since 1.3.0
	 *
	 * @return array<string, mixed>
	 */
	public function get_status(): array {
		$counts = Queue::get_counts();
		$bytes  = Scanner::get_byte_totals();

		$failures = array();

		foreach ( Queue::get_failures( 5 ) as $row ) {
			$failures[] = array(
				'attachment_id' => (int) $row->attachment_id,
				'error'         => (string) $row->error,
				'attempts'      => (int) $row->attempts,
				'updated'       => (string) $row->updated,
			);
		}

		return array(
			'candidates' => Scanner::count_candidates(),
			'optimized'  => Scanner::count_optimized(),
			'remaining'  => Processor::get_remaining(),
			'queue'      => array(
				'pending'    => (int) $counts[ Queue::PENDING ],
				'processing' => (int) $counts[ Queue::PROCESSING ],
				'done'       => (int) $counts[ Queue::DONE ],
				'failed'     => (int) $counts[ Queue::FAILED ],
				'skipped'    => (int) $counts[ Queue::SKIPPED ],
			),
			'bytes'      => array(
				'source'        => (int) $bytes['source'],
				'saved'         => (int) $bytes['saved'],
				'saved_percent' => $bytes['source'] > 0 ? round( ( $bytes['saved'] / $bytes['source'] ) * 100, 1 ) : 0.0,
				'copies'        => (int) $bytes['copies'],
				'formats'       => array_map( 'intval', $bytes['formats'] ),
			),
			'formats'    => array(
				'configured' => Converter::get_args()['formats'],
				'supported'  => Capabilities::get_supported_formats(),
			),
			'background' => array(
				'enabled' => (bool) \wzio_get_option( 'background_queue', true ),
				'status'  => Cron_Health::get_status(),
			),
			'failures'   => $failures,
		);
	}

	/**
	 * Queue one time-limited pass of images that need work.
	 *
	 * @since 1.3.0
	 *
	 * @param mixed $input Ability input.
	 * @return array{queued: int, after_id: int, done: bool, remaining: int}
	 */
	public function queue_bulk( $input = null ): array {
		$input    = is_array( $input ) ? $input : array();
		$after_id = absint( $input['after_id'] ?? 0 );

		Database::maybe_upgrade();

		if ( 0 === $after_id ) {
			Scanner::flush_counts();

			if ( ! empty( $input['retry'] ) ) {
				Scanner::requeue_retryable();
			}
		}

		$pass = Scanner::enqueue_batch( $after_id, ! empty( $input['force'] ), null, ! empty( $input['outdated'] ) );

		Processor::maybe_schedule();

		$pass['remaining'] = Processor::get_remaining();

		return $pass;
	}

	/**
	 * Process one batch from the queue.
	 *
	 * @since 1.3.0
	 *
	 * @param mixed $input Ability input.
	 * @return array{processed: int, converted: int, failed: int, skipped: int, deferred: int, saved: int, remaining: int, locked: bool}
	 */
	public function run_batch( $input = null ): array {
		$batch_size = is_array( $input ) ? absint( $input['batch_size'] ?? 0 ) : 0;

		return Processor::run_batch( $batch_size > 0 ? $batch_size : null );
	}

	/**
	 * Convert one attachment.
	 *
	 * @since 1.3.0
	 *
	 * @param mixed $input Ability input.
	 * @return array{status: string, saved: int, error: string, locked: bool}|\WP_Error
	 */
	public function optimize_attachment( $input = null ) {
		$input         = is_array( $input ) ? $input : array();
		$attachment_id = absint( $input['attachment_id'] ?? 0 );

		if ( ! Converter::is_convertible_attachment( $attachment_id ) ) {
			return new \WP_Error( 'wzio_not_convertible', __( 'This attachment is not an image the plugin can convert.', 'webberzone-image-optimizer' ) );
		}

		Database::maybe_upgrade();

		$result = Processor::process_attachment( $attachment_id, ! empty( $input['force'] ) );

		Scanner::flush_counts();

		return $result;
	}

	/**
	 * Get the settings abilities may change, typed for JSON.
	 *
	 * @since 1.3.0
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		$schema = $this->settings_schema();
		$stored = Options_API::get_settings_with_defaults();
		$values = array();

		foreach ( $schema as $key => $property ) {
			$value = $stored[ $key ] ?? '';

			switch ( $property['type'] ) {
				case 'boolean':
					$values[ $key ] = ! empty( $value );
					break;
				case 'integer':
					$values[ $key ] = (int) $value;
					break;
				case 'array':
					$values[ $key ] = wp_parse_list( (string) $value );
					break;
				default:
					$values[ $key ] = (string) $value;
			}
		}

		return $values;
	}

	/**
	 * Change the requested settings.
	 *
	 * The input has already been validated against the schema, so only the
	 * conversions to the stored format remain.
	 *
	 * @since 1.3.0
	 *
	 * @param mixed $input Ability input.
	 * @return array{updated: array<int, string>, settings: array<string, mixed>}|\WP_Error
	 */
	public function update_settings( $input = null ) {
		$schema  = $this->settings_schema();
		$input   = is_array( $input ) ? array_intersect_key( $input, $schema ) : array();
		$changes = array();

		foreach ( $input as $key => $value ) {
			switch ( $schema[ $key ]['type'] ) {
				case 'boolean':
					$changes[ $key ] = $value ? 1 : 0;
					break;
				case 'integer':
					$changes[ $key ] = (int) $value;
					break;
				case 'array':
					$changes[ $key ] = implode( ',', array_map( 'sanitize_key', (array) $value ) );
					break;
				default:
					$changes[ $key ] = sanitize_textarea_field( (string) $value );
			}
		}

		// A format the server cannot encode would queue work that always fails.
		if ( isset( $changes['formats'] ) ) {
			$changes['formats'] = implode( ',', array_intersect( wp_parse_list( $changes['formats'] ), Capabilities::get_supported_formats() ) );

			if ( '' === $changes['formats'] ) {
				return new \WP_Error( 'wzio_unsupported_format', __( 'This server cannot encode any of the requested formats. Call wzio/get-status to see which it supports.', 'webberzone-image-optimizer' ) );
			}
		}

		$before = Options_API::get_settings_with_defaults();

		Options_API::update_settings( $changes );

		foreach ( array( 'lossless_png', 'png_lossy_quality' ) as $key ) {
			if ( isset( $changes[ $key ] ) && (string) ( $before[ $key ] ?? '' ) !== (string) $changes[ $key ] ) {
				Scanner::requeue_png_skips();
				break;
			}
		}

		if ( ! empty( $changes['background_queue'] ) && Processor::get_remaining() > 0 ) {
			Processor::maybe_schedule();
		}

		return array(
			'updated'  => array_keys( $changes ),
			'settings' => $this->get_settings(),
		);
	}

	/**
	 * Build the JSON schema for the settings abilities may change.
	 *
	 * Derived from the registered settings, so types, ranges and choices match
	 * the settings screen.
	 *
	 * @since 1.3.0
	 *
	 * @return array<string, array<string, mixed>> Property schemas keyed by setting.
	 */
	public function settings_schema(): array {
		$registered = array();

		foreach ( Settings::get_registered_settings() as $section ) {
			foreach ( $section as $setting ) {
				if ( isset( $setting['id'] ) ) {
					$registered[ $setting['id'] ] = $setting;
				}
			}
		}

		$properties = array();

		foreach ( self::SETTINGS as $key ) {
			if ( ! isset( $registered[ $key ] ) || ! empty( $registered[ $key ]['disabled'] ) ) {
				continue;
			}

			$setting  = $registered[ $key ];
			$property = array(
				'title'       => wp_specialchars_decode( (string) ( $setting['name'] ?? $key ), ENT_QUOTES ),
				'description' => wp_specialchars_decode( (string) ( $setting['desc'] ?? '' ), ENT_QUOTES ),
			);

			switch ( $setting['type'] ) {
				case 'checkbox':
					$property['type'] = 'boolean';
					break;
				case 'number':
					$property['type'] = 'integer';

					if ( isset( $setting['min'] ) ) {
						$property['minimum'] = (int) $setting['min'];
					}
					if ( isset( $setting['max'] ) ) {
						$property['maximum'] = (int) $setting['max'];
					}
					break;
				case 'radio':
				case 'select':
					$property['type'] = 'string';
					$property['enum'] = array_map( 'strval', array_keys( (array) $setting['options'] ) );
					break;
				case 'multicheck':
					$property['type']        = 'array';
					$property['uniqueItems'] = true;
					$property['items']       = array(
						'type' => 'string',
						'enum' => array_map( 'strval', array_keys( (array) $setting['options'] ) ),
					);
					break;
				default:
					$property['type'] = 'string';
			}

			$properties[ $key ] = $property;
		}

		// Leaving every format unticked would stop all conversion.
		if ( isset( $properties['formats'] ) ) {
			$properties['formats']['minItems'] = 1;
		}

		return $properties;
	}

	/**
	 * Drop the constraints from setting schemas used to describe output.
	 *
	 * A stored value can fall outside today's choices, such as an image size a
	 * theme no longer registers, and must not make reading the settings fail.
	 *
	 * @since 1.3.0
	 *
	 * @param array<string, array<string, mixed>> $properties Property schemas.
	 * @return array<string, array<string, mixed>>
	 */
	private static function loosen( array $properties ): array {
		foreach ( $properties as $key => $property ) {
			unset( $property['enum'], $property['minimum'], $property['maximum'], $property['minItems'], $property['uniqueItems'] );

			if ( isset( $property['items'] ) ) {
				$property['items'] = array( 'type' => 'string' );
			}

			$properties[ $key ] = $property;
		}

		return $properties;
	}

	/**
	 * Build the ability meta.
	 *
	 * @since 1.3.0
	 *
	 * @param bool $read_only   Whether the ability only reads.
	 * @param bool $destructive Whether the ability may destroy data.
	 * @param bool $idempotent  Whether repeating the call has no further effect.
	 * @return array<string, mixed>
	 */
	private function meta( bool $read_only, bool $destructive, bool $idempotent ): array {
		return array(
			'annotations'  => array(
				'readonly'    => $read_only,
				'destructive' => $destructive,
				'idempotent'  => $idempotent,
			),
			'show_in_rest' => true,
			'mcp'          => array(
				'public' => true,
			),
		);
	}
}
