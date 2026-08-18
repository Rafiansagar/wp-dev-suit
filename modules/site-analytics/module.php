<?php
/**
 * Site Analytics — request-level performance profiler.
 *
 * @package WP_Dev_Suit\Modules\Site_Analytics
 */

namespace WP_Dev_Suit\Modules\Site_Analytics;

use WP_Dev_Suit\Module;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-store.php';
require_once __DIR__ . '/includes/class-collector.php';
require_once __DIR__ . '/includes/class-admin.php';

/**
 * Attributes boot time to individual plugins, breaks the request into phases,
 * and logs AJAX and REST response times.
 */
class Site_Analytics extends Module {

	/**
	 * Admin screen, only built in the admin.
	 *
	 * @var Admin|null
	 */
	protected $admin = null;

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id() {
		return 'site-analytics';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function title() {
		return __( 'Site Analytics', 'wp-dev-suit' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return int
	 */
	public function position() {
		return 10;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function boot() {
		// The Collector only registers a shutdown handler, so registering it early
		// means a fatal later in the request still leaves a partial snapshot.
		new Collector();

		if ( is_admin() ) {
			$this->admin = new Admin( $this );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function has_screen() {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function render_screen() {
		if ( $this->admin ) {
			$this->admin->render();
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function enqueue() {
		$path = $this->path( 'assets/admin.css' );

		wp_enqueue_style(
			'wpds-site-analytics',
			$this->url( 'assets/admin.css' ),
			array(),
			// filemtime, not the plugin version: this is a dev tool whose CSS gets
			// edited far more often than the version constant gets bumped.
			file_exists( $path ) ? (string) filemtime( $path ) : WPDS_VERSION
		);
	}
}

return new Site_Analytics();
