<?php
/**
 * Site Analytics — the part that has to run at mu-plugin time.
 *
 * Loaded by the suite's mu-loader, not by WordPress directly, so it carries no
 * Plugin Name header and defines no WPDS_MU_VERSION — the loader owns that
 * constant and is already inside its own guard by the time this runs.
 *
 * A regular plugin cannot time the plugins that load before it, so collection
 * has to start here. Everything writes into a global array that the module's
 * Collector reads on shutdown. Keep this file dependency-free: it runs before
 * WordPress has loaded anything else of ours.
 *
 * @package WP_Dev_Suit\Modules\Site_Analytics
 */

defined( 'ABSPATH' ) || exit;

if ( isset( $GLOBALS['wpds_site_analytics'] ) ) {
	return;
}

$GLOBALS['wpds_site_analytics'] = array(
	// REQUEST_TIME_FLOAT is the only value that predates PHP executing our code,
	// so it is the honest start of the request. microtime() here would already
	// have missed the whole of wp-load.php and wp-settings.php up to this point.
	'start'   => isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true ),
	'marks'   => array(),
	'plugins' => array(),
	'init'    => array(),
	'cursor'  => array( microtime( true ), count( get_included_files() ), memory_get_usage() ),
);

/**
 * Close the current measurement window and open a new one.
 *
 * @param string $label Human-readable name for the window that just ended.
 * @return void
 */
function wpds_sa_mark( $label ) {
	if ( ! isset( $GLOBALS['wpds_site_analytics'] ) ) {
		return;
	}

	$sa    = &$GLOBALS['wpds_site_analytics'];
	$now   = microtime( true );
	$files = count( get_included_files() );
	$mem   = memory_get_usage();

	$sa['marks'][] = array(
		'label' => $label,
		'ms'    => ( $now - $sa['cursor'][0] ) * 1000,
		'files' => $files - $sa['cursor'][1],
		'mem'   => $mem - $sa['cursor'][2],
		'at'    => ( $now - $sa['start'] ) * 1000,
	);

	$sa['cursor'] = array( $now, $files, $mem );
}

/**
 * Resolve which plugin, theme or core directory a callback was defined in.
 *
 * @param mixed $callback Anything acceptable to call_user_func().
 * @return array{0:string,1:string} Owner slug and a printable callback name.
 */
function wpds_sa_owner( $callback ) {
	try {
		if ( $callback instanceof Closure ) {
			$reflector = new ReflectionFunction( $callback );
			$name      = 'Closure';
		} elseif ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
			$reflector = new ReflectionMethod( $callback );
			$name      = $callback;
		} elseif ( is_string( $callback ) && function_exists( $callback ) ) {
			$reflector = new ReflectionFunction( $callback );
			$name      = $callback . '()';
		} elseif ( is_array( $callback ) && 2 === count( $callback ) ) {
			$class     = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			$reflector = new ReflectionMethod( $class, (string) $callback[1] );
			$name      = $class . '::' . $callback[1];
		} elseif ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
			$reflector = new ReflectionMethod( $callback, '__invoke' );
			$name      = get_class( $callback ) . '::__invoke';
		} else {
			return array( 'unknown', 'unknown' );
		}
	} catch ( Throwable $e ) {
		return array( 'unknown', 'unknown' );
	}

	$file = str_replace( '\\', '/', (string) $reflector->getFileName() );

	if ( preg_match( '~/mu-plugins/([^/]+)~', $file, $matches ) ) {
		$owner = 'mu:' . preg_replace( '~\.php$~', '', $matches[1] );
	} elseif ( preg_match( '~/plugins/([^/]+)~', $file, $matches ) ) {
		$owner = preg_replace( '~\.php$~', '', $matches[1] );
	} elseif ( preg_match( '~/themes/([^/]+)~', $file, $matches ) ) {
		$owner = 'theme:' . $matches[1];
	} elseif ( false !== strpos( $file, '/wp-includes/' ) || false !== strpos( $file, '/wp-admin/' ) ) {
		$owner = 'wp-core';
	} else {
		$owner = 'unknown';
	}

	return array( $owner, $name );
}

// One entry per plugin file, measuring only its include cost. Most plugins do
// almost nothing here and spend their real time on init — that is why the init
// profiler below exists as well.
add_action(
	'plugin_loaded',
	function ( $plugin ) {
		$sa    = &$GLOBALS['wpds_site_analytics'];
		$now   = microtime( true );
		$files = count( get_included_files() );
		$mem   = memory_get_usage();

		$slug = basename( dirname( (string) $plugin ) );
		if ( '.' === $slug || 'plugins' === $slug ) {
			$slug = basename( (string) $plugin, '.php' );
		}

		$sa['plugins'][ $slug ] = array(
			'ms'    => ( $now - $sa['cursor'][0] ) * 1000,
			'files' => $files - $sa['cursor'][1],
			'mem'   => $mem - $sa['cursor'][2],
		);

		$sa['cursor'] = array( $now, $files, $mem );
	},
	0
);

add_action( 'muplugins_loaded', function () { wpds_sa_mark( 'mu-plugins' ); }, -PHP_INT_MAX );
add_action( 'plugins_loaded', function () { wpds_sa_mark( 'plugin include loop' ); }, -PHP_INT_MAX );
add_action( 'setup_theme', function () { wpds_sa_mark( 'plugins_loaded hooks' ); }, -PHP_INT_MAX );
add_action( 'after_setup_theme', function () { wpds_sa_mark( 'theme functions.php' ); }, -PHP_INT_MAX );
add_action( 'init', function () { wpds_sa_mark( 'after_setup_theme hooks' ); }, -PHP_INT_MAX );
add_action( 'wp_loaded', function () { wpds_sa_mark( 'init hooks' ); }, -PHP_INT_MAX );
add_action( 'template_redirect', function () { wpds_sa_mark( 'query + template select' ); }, -PHP_INT_MAX );
add_action( 'shutdown', function () { wpds_sa_mark( 'render + response' ); }, PHP_INT_MAX - 1 );

// Per-callback attribution for `init`. This is the phase that dominates most
// installs, and a phase total alone does not name the plugin responsible. Each
// registered callback is swapped for a timing wrapper before any of them run.
// Gated on an option because wrapping several hundred callbacks is not free.
add_action(
	'init',
	function () {
		$settings = get_option( 'wpds_settings', array() );
		if ( empty( $settings['profile_init'] ) ) {
			return;
		}

		global $wp_filter;
		if ( empty( $wp_filter['init'] ) ) {
			return;
		}

		foreach ( $wp_filter['init']->callbacks as $priority => $callbacks ) {
			// Skip our own wrapper pass, which is registered at this priority.
			if ( (int) $priority === -PHP_INT_MAX ) {
				continue;
			}

			foreach ( $callbacks as $id => $registered ) {
				$original = $registered['function'];
				$resolved = wpds_sa_owner( $original );

				$wp_filter['init']->callbacks[ $priority ][ $id ]['function'] = function () use ( $original, $resolved, $priority ) {
					$started   = microtime( true );
					$mem_start = memory_get_usage();

					$result = call_user_func_array( $original, func_get_args() );

					$GLOBALS['wpds_site_analytics']['init'][] = array(
						'ms'    => ( microtime( true ) - $started ) * 1000,
						'mem'   => memory_get_usage() - $mem_start,
						'owner' => $resolved[0],
						'name'  => $resolved[1],
						'prio'  => $priority,
					);

					return $result;
				};
			}
		}
	},
	-PHP_INT_MAX
);
