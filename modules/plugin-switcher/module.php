<?php
/**
 * Plugin Switcher — load a named subset of the active plugins.
 *
 * @package WP_Dev_Suit\Modules\Plugin_Switcher
 */

namespace WP_Dev_Suit\Modules\Plugin_Switcher;

use WP_Dev_Suit\Module;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-profiles.php';
require_once __DIR__ . '/includes/class-admin.php';

/**
 * Applies profiles by filtering the active plugin list at mu-plugin time.
 *
 * The filtering itself lives in this module's mu.php, because by the time a
 * regular plugin runs the plugin loop has already finished.
 */
class Plugin_Switcher extends Module {

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
		return 'plugin-switcher';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function title() {
		return __( 'Plugin Switcher', 'wp-dev-suit' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return int
	 */
	public function position() {
		return 20;
	}

	/**
	 * Switching what loads is an activate_plugins job, not a settings one.
	 *
	 * @return string
	 */
	public function capability() {
		return 'activate_plugins';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function boot() {
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
}

return new Plugin_Switcher();
