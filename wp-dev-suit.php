<?php
/**
 * Plugin Name: WP Dev Suit
 * Description: Developer tooling for this site. Each tool is a self-contained module under modules/. Currently one — Site Analytics, a request-level performance profiler.
 * Version: 1.0.0
 * Author: RSTheme
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: wp-dev-suit
 *
 * @package WP_Dev_Suit
 */

namespace WP_Dev_Suit;

defined( 'ABSPATH' ) || exit;

define( 'WPDS_VERSION', '1.0.0' );
define( 'WPDS_FILE', __FILE__ );
define( 'WPDS_DIR', plugin_dir_path( __FILE__ ) );

// Filename is prefixed so it sorts first in the mu-plugins directory. Anything
// loading before it is invisible to tools that measure the boot.
define( 'WPDS_MU_TARGET', '000-wp-dev-suit.php' );

require_once WPDS_DIR . 'includes/abstracts/class-module.php';
require_once WPDS_DIR . 'includes/class-modules.php';
require_once WPDS_DIR . 'includes/class-menu.php';

/**
 * The module registry, shared by boot and the menu.
 *
 * @return Modules
 */
function modules() {
	static $modules = null;

	if ( null === $modules ) {
		$modules = new Modules();
		$modules->load();
	}

	return $modules;
}

/**
 * Absolute path the mu-loader is installed to.
 *
 * @return string
 */
function mu_target_path() {
	$dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';

	return $dir . '/' . WPDS_MU_TARGET;
}

/**
 * The mu-loader's contents with its placeholder resolved.
 *
 * The loader is copied, not included, so it cannot use WPDS_DIR at runtime. The
 * modules path is baked in at copy time instead, which keeps it working if the
 * plugin directory is ever renamed.
 *
 * @return string Rendered file contents, or '' when the source is unreadable.
 */
function mu_loader_contents() {
	$source = WPDS_DIR . 'mu-loader/wp-dev-suit-mu.php';

	if ( ! is_readable( $source ) ) {
		return '';
	}

	// wp_normalize_path gives forward slashes, which survive being written into a
	// single-quoted PHP string on Windows. A raw backslash path would not.
	return str_replace(
		'{{MODULES_DIR}}',
		wp_normalize_path( untrailingslashit( WPDS_DIR ) . '/modules' ),
		(string) file_get_contents( $source ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	);
}

/**
 * Write the mu-loader into place, creating the directory if needed.
 *
 * @return bool True when the loader is present and current afterwards.
 */
function install_mu_loader() {
	$contents = mu_loader_contents();

	if ( '' === $contents ) {
		return false;
	}

	$target = mu_target_path();
	$dir    = dirname( $target );

	if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
		return false;
	}

	// Compared against the rendered contents, not the source file. The source
	// still holds the placeholder, so comparing to it would never match and the
	// loader would be rewritten on every admin page load.
	if ( is_readable( $target ) && md5_file( $target ) === md5( $contents ) ) {
		return true;
	}

	return false !== file_put_contents( $target, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
}

/**
 * Remove the mu-loader.
 *
 * @return void
 */
function remove_mu_loader() {
	$target = mu_target_path();

	if ( file_exists( $target ) ) {
		wp_delete_file( $target );
	}
}

register_activation_hook( __FILE__, __NAMESPACE__ . '\\on_activate' );
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\on_deactivate' );

/**
 * Install the loader and migrate anything left by an earlier name.
 *
 * @return void
 */
function on_activate() {
	migrate_legacy_options();
	install_mu_loader();
}

/**
 * Take the loader back out. Leaving a collecting mu-plugin behind after the
 * plugin is switched off would be indistinguishable from a bug.
 *
 * @return void
 */
function on_deactivate() {
	remove_mu_loader();
}

/**
 * Carry data over from the plugin's former name.
 *
 * This shipped as "Site Analytics" with site_analytics_* option names. Without
 * this the rename silently resets the recording toggle back to off, which reads
 * as the profiler having broken rather than having been renamed.
 *
 * @return void
 */
function migrate_legacy_options() {
	$legacy = array(
		'site_analytics_settings' => 'wpds_settings',
		'site_analytics_log'      => 'wpds_log',
	);

	foreach ( $legacy as $old => $new ) {
		// A sentinel, not false: an empty log is a legitimate stored value.
		$value = get_option( $old, null );

		if ( null !== $value && null === get_option( $new, null ) ) {
			update_option( $new, $value, false );
		}

		delete_option( $old );
	}

	// The old loader filename is not the one deactivation knows about, so it
	// would otherwise stay behind and keep collecting into a global nothing reads.
	$stale = ( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins' ) . '/000-site-analytics.php';

	if ( file_exists( $stale ) ) {
		wp_delete_file( $stale );
	}
}

/**
 * Boot the suite.
 *
 * @return void
 */
function boot() {
	modules()->boot();

	if ( is_admin() ) {
		new Menu( modules() );

		// Self-heal: the loader can go missing when a site is copied between
		// environments, since mu-plugins is often excluded from a sync, and an
		// update never fires the activation hook.
		add_action( 'admin_init', __NAMESPACE__ . '\\install_mu_loader' );
		add_action( 'admin_notices', __NAMESPACE__ . '\\loader_notice' );
	}
}

/**
 * Warn when collection cannot happen because the loader is not installed.
 *
 * @return void
 */
function loader_notice() {
	if ( ! current_user_can( 'manage_options' ) || defined( 'WPDS_MU_VERSION' ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p><strong>WP Dev Suit:</strong> ';
	echo esc_html(
		sprintf(
			/* translators: %s: mu-plugin file path */
			__( 'could not install its loader at %s. Check that the directory is writable — modules that run before other plugins cannot work until it is.', 'wp-dev-suit' ),
			mu_target_path()
		)
	);
	echo '</p></div>';
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\\boot' );
