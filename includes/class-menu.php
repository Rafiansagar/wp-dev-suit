<?php
/**
 * The suite's admin menu.
 *
 * @package WP_Dev_Suit
 */

namespace WP_Dev_Suit;

defined( 'ABSPATH' ) || exit;

/**
 * Builds one top-level menu with a submenu per module that has a screen.
 *
 * Modules never call add_menu_page themselves. Two modules each registering a
 * top level would give the suite two entries in the sidebar, which is exactly
 * the mess this structure exists to avoid.
 */
class Menu {

	const SLUG = 'wp-dev-suit';

	/**
	 * Module registry.
	 *
	 * @var Modules
	 */
	protected $modules;

	/**
	 * Hook the menu and asset loading.
	 *
	 * @param Modules $modules Module registry.
	 */
	public function __construct( Modules $modules ) {
		$this->modules = $modules;

		add_action( 'admin_menu', array( $this, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Register the top-level menu and each module's screen beneath it.
	 *
	 * @return void
	 */
	public function register() {
		$screens = $this->modules->with_screen();

		if ( ! $screens ) {
			return;
		}

		$landing = reset( $screens );

		// The hook suffix has to come from add_menu_page. For a submenu whose slug
		// equals its parent's the two differ, and only the top-level form
		// ('toplevel_page_wp-dev-suit') is what admin_enqueue_scripts passes —
		// matching on the other one silently skips the stylesheet.
		$hook = add_menu_page(
			__( 'WP Dev Suit', 'wp-dev-suit' ),
			__( 'WP Dev Suit', 'wp-dev-suit' ),
			$landing->capability(),
			self::SLUG,
			array( $landing, 'render_screen' ),
			'dashicons-performance',
			80
		);

		foreach ( $screens as $module ) {
			// The first module takes the parent's own slug, which replaces the
			// duplicate "WP Dev Suit" child WordPress would otherwise generate
			// above the real tools. Every later module gets its own slug.
			$is_landing = ( $module === $landing );
			$slug       = $is_landing ? self::SLUG : $module->menu_slug();

			$submenu_hook = add_submenu_page(
				self::SLUG,
				$module->title(),
				$module->title(),
				$module->capability(),
				$slug,
				array( $module, 'render_screen' )
			);

			$module->set_hook( $is_landing ? $hook : (string) $submenu_hook );
		}
	}

	/**
	 * Let the module owning the current screen enqueue its assets.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function assets( $hook ) {
		foreach ( $this->modules->with_screen() as $module ) {
			if ( $module->hook() && $module->hook() === $hook ) {
				$module->enqueue();
				return;
			}
		}
	}

	/**
	 * URL of a module's screen.
	 *
	 * @param Module               $module Module.
	 * @param array<string,string> $args   Extra query args.
	 * @return string
	 */
	public static function url( Module $module, array $args = array() ) {
		// A module registered as the landing page answers on the parent slug, not
		// on its own, so read back what registration actually assigned.
		$slug = ( 'toplevel_page_' . self::SLUG === $module->hook() ) ? self::SLUG : $module->menu_slug();

		return add_query_arg(
			array_merge( array( 'page' => $slug ), $args ),
			admin_url( 'admin.php' )
		);
	}
}
