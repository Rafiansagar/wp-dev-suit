<?php
/**
 * Module registry.
 *
 * @package WP_Dev_Suit
 */

namespace WP_Dev_Suit;

defined( 'ABSPATH' ) || exit;

/**
 * Finds and holds the suite's modules.
 *
 * Registration is derived from the filesystem — drop a directory under modules/
 * with a module.php that returns a Module, and it is picked up. There is no
 * hand-written list to forget to update: a loaded-but-unregistered module would
 * fail silently, which is the worst failure mode available.
 */
class Modules {

	/**
	 * Loaded modules, keyed by id.
	 *
	 * @var array<string,Module>
	 */
	protected $modules = array();

	/**
	 * Discover and instantiate every module.
	 *
	 * @return void
	 */
	public function load() {
		foreach ( (array) glob( WPDS_DIR . 'modules/*/module.php' ) as $file ) {
			// The file returns its own instance rather than declaring a class we
			// then guess the name of. Explicit, and a typo fails loudly here
			// instead of silently registering nothing.
			$module = require $file;

			if ( ! $module instanceof Module ) {
				continue;
			}

			$module->set_path( dirname( $file ) );
			$this->modules[ $module->id() ] = $module;
		}

		uasort(
			$this->modules,
			function ( Module $a, Module $b ) {
				return array( $a->position(), $a->title() ) <=> array( $b->position(), $b->title() );
			}
		);
	}

	/**
	 * Boot every module.
	 *
	 * @return void
	 */
	public function boot() {
		foreach ( $this->modules as $module ) {
			$module->boot();
		}
	}

	/**
	 * All modules, in menu order.
	 *
	 * @return array<string,Module>
	 */
	public function all() {
		return $this->modules;
	}

	/**
	 * Modules that contribute an admin screen, in menu order.
	 *
	 * @return array<string,Module>
	 */
	public function with_screen() {
		return array_filter(
			$this->modules,
			function ( Module $module ) {
				return $module->has_screen();
			}
		);
	}

	/**
	 * One module by id.
	 *
	 * @param string $id Module id.
	 * @return Module|null
	 */
	public function get( $id ) {
		return $this->modules[ $id ] ?? null;
	}
}
