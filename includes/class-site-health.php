<?php
/**
 * Site Health tests and debug information.
 *
 * @package WebberZone\Image_Optimizer
 */

namespace WebberZone\Image_Optimizer;

use WebberZone\Image_Optimizer\Frontend\Server_Rules;
use WebberZone\Image_Optimizer\Util\Hook_Registry;

if ( ! defined( 'WPINC' ) ) {
	exit;
}

/**
 * Reports encoder, queue and delivery state through Tools > Site Health.
 *
 * Registered outside the admin so the weekly scheduled Site Health check sees the tests too.
 *
 * @since 1.3.0
 */
class Site_Health {

	/**
	 * Constructor.
	 *
	 * @since 1.3.0
	 */
	public function __construct() {
		Hook_Registry::add_filter( 'site_status_tests', array( $this, 'register_tests' ) );
		Hook_Registry::add_filter( 'debug_information', array( $this, 'debug_information' ) );
	}

	/**
	 * Register the direct tests.
	 *
	 * @since 1.3.0
	 *
	 * @param array $tests Site Health tests.
	 * @return array
	 */
	public function register_tests( $tests ) {
		$tests['direct']['wzio_encoders'] = array(
			'label' => __( 'Image Optimizer encoders', 'webberzone-image-optimizer' ),
			'test'  => array( $this, 'test_encoders' ),
		);

		$tests['direct']['wzio_queue_table'] = array(
			'label' => __( 'Image Optimizer queue table', 'webberzone-image-optimizer' ),
			'test'  => array( $this, 'test_queue_table' ),
		);

		$tests['direct']['wzio_background_queue'] = array(
			'label' => __( 'Image Optimizer background queue', 'webberzone-image-optimizer' ),
			'test'  => array( $this, 'test_background_queue' ),
		);

		return $tests;
	}

	/**
	 * Check that the server can encode the configured formats.
	 *
	 * @since 1.3.0
	 *
	 * @return array Test result.
	 */
	public function test_encoders(): array {
		$supported  = Capabilities::get_supported_formats();
		$configured = Converter::get_args()['formats'];
		$missing    = array_diff( $configured, $supported );

		if ( empty( $supported ) ) {
			return $this->result(
				'wzio_encoders',
				'critical',
				__( 'This server cannot encode WebP or AVIF', 'webberzone-image-optimizer' ),
				__( 'Neither Imagick nor GD produced a working WebP or AVIF file, so no images are being optimized. Ask your host to enable WebP support in Imagick or GD.', 'webberzone-image-optimizer' )
			);
		}

		if ( ! empty( $missing ) ) {
			return $this->result(
				'wzio_encoders',
				'recommended',
				__( 'Some selected image formats cannot be encoded', 'webberzone-image-optimizer' ),
				sprintf(
					/* translators: %s: comma-separated format names. */
					__( 'Formats to generate includes %s, which this server cannot encode. Those copies are skipped.', 'webberzone-image-optimizer' ),
					strtoupper( implode( ', ', $missing ) )
				)
			);
		}

		return $this->result(
			'wzio_encoders',
			'good',
			__( 'The server can encode every selected image format', 'webberzone-image-optimizer' ),
			sprintf(
				/* translators: %s: comma-separated format names. */
				__( 'Generating: %s.', 'webberzone-image-optimizer' ),
				strtoupper( implode( ', ', $configured ) )
			)
		);
	}

	/**
	 * Check that the queue table exists.
	 *
	 * @since 1.3.0
	 *
	 * @return array Test result.
	 */
	public function test_queue_table(): array {
		if ( ! Database::is_installed() ) {
			return $this->result(
				'wzio_queue_table',
				'critical',
				__( 'The Image Optimizer queue table is missing', 'webberzone-image-optimizer' ),
				__( 'Bulk Optimize and background conversion cannot run without it. Deactivate and reactivate the plugin to create it.', 'webberzone-image-optimizer' )
			);
		}

		return $this->result(
			'wzio_queue_table',
			'good',
			__( 'The Image Optimizer queue table exists', 'webberzone-image-optimizer' ),
			__( 'Bulk Optimize and background conversion can queue images.', 'webberzone-image-optimizer' )
		);
	}

	/**
	 * Check that queued images are being picked up.
	 *
	 * @since 1.3.0
	 *
	 * @return array Test result.
	 */
	public function test_background_queue(): array {
		$status    = Cron_Health::get_status();
		$remaining = Processor::get_remaining();

		if ( Cron_Health::HEALTHY === $status ) {
			return $this->result(
				'wzio_background_queue',
				'good',
				__( 'The Image Optimizer background queue is running', 'webberzone-image-optimizer' ),
				sprintf(
					/* translators: %d: number of queued images. */
					_n( '%d image is waiting in the queue.', '%d images are waiting in the queue.', $remaining, 'webberzone-image-optimizer' ),
					$remaining
				)
			);
		}

		$reason = Cron_Health::DISABLED === $status
			? __( 'Page-load cron is switched off by DISABLE_WP_CRON, and nothing else has run the queue since it was last checked.', 'webberzone-image-optimizer' )
			: __( 'The queue has not moved since it was last checked, so WP-Cron or the loopback request it depends on may not be working.', 'webberzone-image-optimizer' );

		return $this->result(
			'wzio_background_queue',
			'recommended',
			__( 'Queued images are not being optimized in the background', 'webberzone-image-optimizer' ),
			$reason . ' ' . sprintf(
				/* translators: %d: number of queued images. */
				_n( '%d image is waiting.', '%d images are waiting.', $remaining, 'webberzone-image-optimizer' ),
				$remaining
			),
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'upload.php?page=' . Admin\Bulk_Page::SLUG ) ),
				esc_html__( 'Open Bulk Optimize', 'webberzone-image-optimizer' )
			)
		);
	}

	/**
	 * Add the plugin's section to the Site Health Info tab.
	 *
	 * @since 1.3.0
	 *
	 * @param array $info Debug information sections.
	 * @return array
	 */
	public function debug_information( $info ) {
		$report    = Capabilities::get();
		$originals = Capabilities::get_originals();
		$counts    = Queue::get_counts();
		$yes       = __( 'Yes', 'webberzone-image-optimizer' );
		$no        = __( 'No', 'webberzone-image-optimizer' );
		$fields    = array();

		$fields['version'] = array(
			'label' => __( 'Version', 'webberzone-image-optimizer' ),
			'value' => WZIO_VERSION,
		);

		foreach ( $report['drivers'] as $driver => $formats ) {
			$parts = array();

			foreach ( $formats as $format => $works ) {
				$parts[] = strtoupper( $format ) . ' ' . ( $works ? $yes : $no );
			}

			$fields[ 'driver_' . $driver ] = array(
				/* translators: %s: image library name, such as Imagick or GD. */
				'label' => sprintf( __( '%s encodes', 'webberzone-image-optimizer' ), 'gd' === $driver ? 'GD' : ucfirst( $driver ) ),
				'value' => implode( ', ', $parts ),
			);
		}

		$fields['encoders'] = array(
			'label' => __( 'Active encoders', 'webberzone-image-optimizer' ),
			'value' => empty( $report['formats'] ) ? __( 'None', 'webberzone-image-optimizer' ) : (string) wp_json_encode( $report['formats'] ),
		);

		$fields['original_encoders'] = array(
			'label' => __( 'Original compression available', 'webberzone-image-optimizer' ),
			'value' => sprintf( 'JPEG %1$s, PNG %2$s', $originals['jpeg'] ? $yes : $no, $originals['png'] ? $yes : $no ),
		);

		$fields['formats'] = array(
			'label' => __( 'Formats to generate', 'webberzone-image-optimizer' ),
			'value' => strtoupper( implode( ', ', Converter::get_args()['formats'] ) ),
		);

		$fields['naming'] = array(
			'label' => __( 'Optimized file naming', 'webberzone-image-optimizer' ),
			'value' => (string) \wzio_get_option( 'sidecar_naming', 'append' ),
		);

		$settings = array(
			'convert_on_upload'  => __( 'Convert new uploads', 'webberzone-image-optimizer' ),
			'compress_originals' => __( 'Compress original images', 'webberzone-image-optimizer' ),
			'enable_delivery'    => __( 'Serve optimized images', 'webberzone-image-optimizer' ),
			'rewrite_content'    => __( 'Post content images', 'webberzone-image-optimizer' ),
			'rewrite_template'   => __( 'Theme and block images', 'webberzone-image-optimizer' ),
			'rewrite_buffer'     => __( 'Whole page (buffered)', 'webberzone-image-optimizer' ),
			'background_queue'   => __( 'Process the queue in the background', 'webberzone-image-optimizer' ),
			'lazy_convert'       => __( 'Queue images on first view', 'webberzone-image-optimizer' ),
		);

		foreach ( $settings as $key => $label ) {
			$on             = (bool) \wzio_get_option( $key, false );
			$fields[ $key ] = array(
				'label' => $label,
				'value' => $on ? $yes : $no,
				'debug' => $on ? 'yes' : 'no',
			);
		}

		$fields['batch_size'] = array(
			'label' => __( 'Images per batch', 'webberzone-image-optimizer' ),
			'value' => (int) \wzio_get_option( 'batch_size', 10 ),
		);

		$fields['queue_table'] = array(
			'label' => __( 'Queue table installed', 'webberzone-image-optimizer' ),
			'value' => Database::is_installed() ? $yes : $no,
		);

		$fields['queue'] = array(
			'label' => __( 'Queue', 'webberzone-image-optimizer' ),
			'value' => sprintf(
				'pending %1$d, processing %2$d, done %3$d, failed %4$d, skipped %5$d',
				$counts[ Queue::PENDING ],
				$counts[ Queue::PROCESSING ],
				$counts[ Queue::DONE ],
				$counts[ Queue::FAILED ],
				$counts[ Queue::SKIPPED ]
			),
		);

		$fields['cron'] = array(
			'label' => __( 'Background queue status', 'webberzone-image-optimizer' ),
			'value' => Cron_Health::get_status(),
		);

		$fields['apache_rules'] = array(
			'label' => __( 'Apache rules installed', 'webberzone-image-optimizer' ),
			'value' => Server_Rules::is_apache_rules_installed() ? $yes : $no,
		);

		$info['webberzone-image-optimizer'] = array(
			'label'  => __( 'WebberZone Image Optimizer', 'webberzone-image-optimizer' ),
			'fields' => $fields,
		);

		return $info;
	}

	/**
	 * Build a Site Health test result.
	 *
	 * @since 1.3.0
	 *
	 * @param string $test        Test identifier.
	 * @param string $status      good, recommended or critical.
	 * @param string $label       Result heading.
	 * @param string $description Plain-text explanation.
	 * @param string $actions     Optional action links as HTML.
	 * @return array Test result.
	 */
	private function result( string $test, string $status, string $label, string $description, string $actions = '' ): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Performance', 'webberzone-image-optimizer' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => $actions,
			'test'        => $test,
		);
	}
}
