<?php
/**
 * Plugin Switcher — the part that has to run at mu-plugin time.
 *
 * Filters `option_active_plugins` so a profile takes effect before the plugin
 * loop runs. Nothing here writes to `active_plugins`: the stored option keeps
 * saying what is really activated, no activation or deactivation hooks fire, and
 * clearing the profile restores everything exactly. A tool that toggles plugins
 * by rewriting the option can corrupt a site it crashes halfway through; this
 * one cannot.
 *
 * @package WP_Dev_Suit\Modules\Plugin_Switcher
 */

defined( 'ABSPATH' ) || exit;

// Escape hatch. Put this in wp-config.php to get the real plugin list back if a
// profile ever leaves the admin unusable.
if ( defined( 'WPDS_DISABLE_PLUGIN_SWITCHER' ) && WPDS_DISABLE_PLUGIN_SWITCHER ) {
	return;
}

add_filter(
	'option_active_plugins',
	function ( $plugins ) {
		if ( ! is_array( $plugins ) ) {
			return $plugins;
		}

		// Set while the module reads the true list for a "snapshot what is active"
		// action. Without it that read would return the filtered list.
		if ( ! empty( $GLOBALS['wpds_plugin_switcher_bypass'] ) ) {
			return $plugins;
		}

		// The plugins screen has to tell the truth. Filtering there would show
		// suppressed plugins as inactive, and any click on Activate would write
		// the reduced set into the real option — making a temporary profile
		// permanent by accident. Everything loads on this one screen.
		if ( wpds_ps_is_plugins_screen() ) {
			return $plugins;
		}

		$id = get_option( 'wpds_plugin_profile', '' );

		if ( '' === $id ) {
			return $plugins;
		}

		$profiles = get_option( 'wpds_plugin_profiles', array() );

		if ( ! is_array( $profiles ) || empty( $profiles[ $id ]['plugins'] ) ) {
			return $plugins;
		}

		$keep = (array) $profiles[ $id ]['plugins'];

		// Never drop the suite itself, or the screen that turns the profile off
		// would be gone with it.
		if ( defined( 'WPDS_MU_SUITE_BASENAME' ) ) {
			$keep[] = WPDS_MU_SUITE_BASENAME;
		}

		// Intersect rather than replace: a profile can only ever be a subset of
		// what is genuinely activated, so it can never load something the user
		// did not activate.
		$filtered = array_values( array_intersect( $plugins, $keep ) );

		return $filtered ? $filtered : $plugins;
	},
	// Late, so a profile is applied on top of whatever anything else decided.
	PHP_INT_MAX
);

/**
 * Is this a request that must see the unfiltered plugin list?
 *
 * @return bool
 */
function wpds_ps_is_plugins_screen() {
	$script = isset( $_SERVER['PHP_SELF'] ) ? basename( (string) $_SERVER['PHP_SELF'] ) : '';

	if ( in_array( $script, array( 'plugins.php', 'plugin-install.php', 'plugin-editor.php', 'update.php', 'update-core.php' ), true ) ) {
		return true;
	}

	// Bulk actions on the plugins screen post to plugins.php, but the Ajax-driven
	// update and the activate/deactivate links route through admin-ajax.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$action = isset( $_REQUEST['action'] ) ? (string) $_REQUEST['action'] : '';

	return in_array( $action, array( 'update-plugin', 'activate-plugin', 'search-plugins', 'search-install-plugins' ), true );
}
