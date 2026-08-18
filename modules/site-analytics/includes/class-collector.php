<?php
/**
 * Turns the raw globals written by this module's mu.php into a stored snapshot.
 *
 * @package WP_Dev_Suit\Modules\Site_Analytics
 */

namespace WP_Dev_Suit\Modules\Site_Analytics;

defined( 'ABSPATH' ) || exit;

/**
 * Reads $GLOBALS['wpds'] on shutdown and hands a snapshot to the Store.
 */
class Collector {

	/**
	 * Hook the shutdown recorder.
	 */
	public function __construct() {
		// Late enough that the mu-loader's own final mark (PHP_INT_MAX - 1) has
		// already closed the render window.
		add_action( 'shutdown', array( $this, 'record' ), PHP_INT_MAX );
	}

	/**
	 * Classify the current request.
	 *
	 * @return string One of frontend, admin, ajax, rest, cron, cli.
	 */
	public static function request_type() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		if ( wp_doing_cron() ) {
			return 'cron';
		}
		if ( wp_doing_ajax() ) {
			return 'ajax';
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest';
		}
		if ( is_admin() ) {
			return 'admin';
		}

		return 'frontend';
	}

	/**
	 * Should this request be written to the log?
	 *
	 * @param array<string,mixed> $settings Plugin settings.
	 * @param string              $type     Request type.
	 * @return bool
	 */
	protected function should_record( array $settings, $type ) {
		if ( empty( $settings['enabled'] ) ) {
			return false;
		}

		if ( ! in_array( $type, (array) $settings['types'], true ) ) {
			return false;
		}

		// Profiling our own admin screen would measure the profiler, and every
		// page load would also rewrite the option it is reading.
		if ( isset( $_GET['page'] ) && 'wp-dev-suit' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}

		$sample = (int) $settings['sample'];
		if ( $sample < 100 && wp_rand( 1, 100 ) > $sample ) {
			return false;
		}

		return true;
	}

	/**
	 * Build and store the snapshot for this request.
	 *
	 * @return void
	 */
	public function record() {
		if ( empty( $GLOBALS['wpds'] ) || ! is_array( $GLOBALS['wpds'] ) ) {
			return;
		}

		$settings = Store::settings();
		$type     = self::request_type();

		if ( ! $this->should_record( $settings, $type ) ) {
			return;
		}

		$sa = $GLOBALS['wpds'];

		$init_by_owner = array();
		$init_slowest  = array();

		foreach ( (array) $sa['init'] as $row ) {
			$owner = (string) $row['owner'];

			$init_by_owner[ $owner ] = ( $init_by_owner[ $owner ] ?? 0 ) + (float) $row['ms'];
			$init_slowest[]          = $row;
		}

		arsort( $init_by_owner );

		usort(
			$init_slowest,
			function ( $a, $b ) {
				return $b['ms'] <=> $a['ms'];
			}
		);

		// Only the worst offenders are worth keeping — a full init trace is a few
		// hundred rows per request and the log holds up to 500 requests.
		$init_slowest = array_slice( $init_slowest, 0, 15 );

		$snapshot = array(
			'time'          => time(),
			'type'          => $type,
			'method'        => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET',
			'url'           => $this->current_url(),
			'action'        => $this->ajax_action( $type ),
			'total_ms'      => ( microtime( true ) - (float) $sa['start'] ) * 1000,
			'peak_mem'      => memory_get_peak_usage( true ),
			'files'         => count( get_included_files() ),
			'queries'       => function_exists( 'get_num_queries' ) ? get_num_queries() : 0,
			'query_ms'      => $this->query_time(),
			'marks'         => array_values( (array) $sa['marks'] ),
			'plugins'       => (array) $sa['plugins'],
			'init_by_owner' => $init_by_owner,
			'init_slowest'  => $init_slowest,
		);

		Store::add( $snapshot );
	}

	/**
	 * Requested URL, trimmed to a sane length.
	 *
	 * @return string
	 */
	protected function current_url() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

		return mb_substr( $uri, 0, 200 );
	}

	/**
	 * The ajax action or REST route, which is what identifies the request.
	 *
	 * A log full of identical `/wp-admin/admin-ajax.php` rows tells you nothing.
	 *
	 * @param string $type Request type.
	 * @return string
	 */
	protected function ajax_action( $type ) {
		if ( 'ajax' === $type ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$action = $_REQUEST['action'] ?? '';

			return is_string( $action ) ? sanitize_text_field( wp_unslash( $action ) ) : '';
		}

		if ( 'rest' === $type ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$route = $_GET['rest_route'] ?? '';

			return is_string( $route ) ? sanitize_text_field( wp_unslash( $route ) ) : '';
		}

		return '';
	}

	/**
	 * Total time spent in the database, when SAVEQUERIES is on.
	 *
	 * @return float Milliseconds, or 0 when query logging is off.
	 */
	protected function query_time() {
		global $wpdb;

		if ( ! defined( 'SAVEQUERIES' ) || ! SAVEQUERIES || empty( $wpdb->queries ) ) {
			return 0.0;
		}

		$total = 0.0;
		foreach ( $wpdb->queries as $query ) {
			$total += (float) ( $query[1] ?? 0 );
		}

		return $total * 1000;
	}
}
