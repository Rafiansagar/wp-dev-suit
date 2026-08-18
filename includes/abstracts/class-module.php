<?php
/**
 * The contract every tool in the suite implements.
 *
 * @package WP_Dev_Suit
 */

namespace WP_Dev_Suit;

defined( 'ABSPATH' ) || exit;

/**
 * A self-contained tool.
 *
 * Deliberately thin. It carries identity, a boot call and an optional admin
 * screen — nothing that forwards to several sub-behaviours on a subclass's
 * behalf. A module that needs more structure builds it inside its own directory
 * rather than pushing it up here, so opening one module's folder still shows
 * everything that module does.
 */
abstract class Module {

	/**
	 * Absolute path to this module's directory, set by the registry that loaded it.
	 *
	 * @var string
	 */
	protected $path = '';

	/**
	 * Admin page hook suffix, set by the Menu once the screen is registered.
	 *
	 * @var string
	 */
	protected $hook = '';

	/**
	 * Stable identifier. Must match the directory name.
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Human-readable name, shown in the menu.
	 *
	 * @return string
	 */
	abstract public function title();

	/**
	 * Register hooks. Called once, on plugins_loaded.
	 *
	 * @return void
	 */
	abstract public function boot();

	/**
	 * Does this module have an admin screen of its own?
	 *
	 * @return bool
	 */
	public function has_screen() {
		return false;
	}

	/**
	 * Render the admin screen.
	 *
	 * @return void
	 */
	public function render_screen() {}

	/**
	 * Enqueue assets for this module's screen. Only called on that screen.
	 *
	 * @return void
	 */
	public function enqueue() {}

	/**
	 * Capability required to see the screen.
	 *
	 * @return string
	 */
	public function capability() {
		return 'manage_options';
	}

	/**
	 * Sort order within the suite menu. Lower floats to the top.
	 *
	 * @return int
	 */
	public function position() {
		return 10;
	}

	/**
	 * Menu slug. The first module in the menu is re-slugged to the parent by the
	 * Menu so it becomes the landing page, so never assume this value is unique
	 * to the module until after registration.
	 *
	 * @return string
	 */
	public function menu_slug() {
		return 'wpds-' . $this->id();
	}

	/**
	 * Absolute path inside this module's directory.
	 *
	 * @param string $relative Path relative to the module root.
	 * @return string
	 */
	public function path( $relative = '' ) {
		return $this->path . ( $relative ? '/' . ltrim( $relative, '/' ) : '' );
	}

	/**
	 * Public URL of a file inside this module's directory.
	 *
	 * @param string $relative Path relative to the module root.
	 * @return string
	 */
	public function url( $relative = '' ) {
		return plugins_url( 'modules/' . $this->id() . '/' . ltrim( $relative, '/' ), WPDS_FILE );
	}

	/**
	 * Record where this module was loaded from.
	 *
	 * @param string $path Absolute directory path.
	 * @return void
	 */
	public function set_path( $path ) {
		$this->path = untrailingslashit( $path );
	}

	/**
	 * Record the admin page hook suffix for this module's screen.
	 *
	 * @param string $hook Hook suffix.
	 * @return void
	 */
	public function set_hook( $hook ) {
		$this->hook = (string) $hook;
	}

	/**
	 * The admin page hook suffix, once registered.
	 *
	 * @return string
	 */
	public function hook() {
		return $this->hook;
	}
}
