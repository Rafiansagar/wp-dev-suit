<?php
/**
 * Profile storage.
 *
 * @package WP_Dev_Suit\Modules\Plugin_Switcher
 */

namespace WP_Dev_Suit\Modules\Plugin_Switcher;

defined( 'ABSPATH' ) || exit;

/**
 * Named sets of plugins to keep loaded.
 *
 * A profile is a keep-list, never a disable-list. Listing what to keep means a
 * newly installed plugin is excluded by default rather than silently joining
 * every profile — the safer direction to be wrong in for a tool whose whole job
 * is reducing what loads.
 */
class Profiles {

	const OPTION        = 'wpds_plugin_profiles';
	const ACTIVE_OPTION = 'wpds_plugin_profile';

	/**
	 * All stored profiles, keyed by id.
	 *
	 * @return array<string,array{label:string,plugins:array<int,string>}>
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * One profile.
	 *
	 * @param string $id Profile id.
	 * @return array{label:string,plugins:array<int,string>}|null
	 */
	public static function get( $id ) {
		$all = self::all();

		return $all[ $id ] ?? null;
	}

	/**
	 * Create or update a profile.
	 *
	 * @param string             $id      Profile id. Generated from the label when empty.
	 * @param string             $label   Human-readable name.
	 * @param array<int,string>  $plugins Plugin basenames to keep loaded.
	 * @return string The id written.
	 */
	public static function save( $id, $label, array $plugins ) {
		$all   = self::all();
		$label = trim( wp_strip_all_tags( $label ) );
		$label = '' !== $label ? $label : __( 'Untitled', 'wp-dev-suit' );

		if ( '' === $id ) {
			$id   = sanitize_key( $label );
			$id   = '' !== $id ? $id : 'profile';
			$base = $id;
			$n    = 2;

			while ( isset( $all[ $id ] ) ) {
				$id = $base . '-' . $n;
				++$n;
			}
		}

		$all[ $id ] = array(
			'label'   => $label,
			// Intersected against what is really installed, so a profile cannot
			// accumulate references to plugins that have since been deleted.
			'plugins' => array_values( array_intersect( self::installed(), $plugins ) ),
		);

		update_option( self::OPTION, $all, false );

		return $id;
	}

	/**
	 * Delete a profile, clearing it as active if it was.
	 *
	 * @param string $id Profile id.
	 * @return void
	 */
	public static function delete( $id ) {
		$all = self::all();

		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );

		if ( self::active_id() === $id ) {
			self::set_active( '' );
		}
	}

	/**
	 * Id of the profile currently being applied, or '' for none.
	 *
	 * @return string
	 */
	public static function active_id() {
		return (string) get_option( self::ACTIVE_OPTION, '' );
	}

	/**
	 * Apply a profile, or pass '' to stop filtering entirely.
	 *
	 * @param string $id Profile id.
	 * @return void
	 */
	public static function set_active( $id ) {
		$id = (string) $id;

		if ( '' !== $id && null === self::get( $id ) ) {
			return;
		}

		update_option( self::ACTIVE_OPTION, $id, true );
	}

	/**
	 * Every installed plugin's basename.
	 *
	 * @return array<int,string>
	 */
	public static function installed() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return array_keys( get_plugins() );
	}

	/**
	 * The real active-plugin list, with our own filter stood down.
	 *
	 * Reading the option directly would return the filtered list and a "snapshot
	 * what is active now" button would then capture the profile it is already
	 * applying, quietly making the reduced set permanent.
	 *
	 * @return array<int,string>
	 */
	public static function really_active() {
		$GLOBALS['wpds_plugin_switcher_bypass'] = true;

		$active = (array) get_option( 'active_plugins', array() );

		unset( $GLOBALS['wpds_plugin_switcher_bypass'] );

		return $active;
	}
}
