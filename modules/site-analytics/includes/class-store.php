<?php
/**
 * Snapshot storage.
 *
 * @package WP_Dev_Suit\Modules\Site_Analytics
 */

namespace WP_Dev_Suit\Modules\Site_Analytics;

defined( 'ABSPATH' ) || exit;

/**
 * Persists request snapshots and derives aggregates from them.
 *
 * Snapshots live in a single non-autoloaded option rather than a custom table.
 * The log is capped, so the row stays small, and a profiler that needs its own
 * schema migration is a profiler people switch off.
 */
class Store {

	const LOG_OPTION      = 'wpds_log';
	const SETTINGS_OPTION = 'wpds_settings';

	/**
	 * Default settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'enabled'      => 0,
			'profile_init' => 1,
			'keep'         => 100,
			'sample'       => 100,
			'types'        => array( 'frontend', 'admin', 'ajax', 'rest', 'cron' ),
		);
	}

	/**
	 * Read merged settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function settings() {
		$stored = get_option( self::SETTINGS_OPTION, array() );

		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Persist settings.
	 *
	 * @param array<string,mixed> $settings Raw settings.
	 * @return void
	 */
	public static function save_settings( array $settings ) {
		$defaults = self::defaults();
		$clean    = array(
			'enabled'      => empty( $settings['enabled'] ) ? 0 : 1,
			'profile_init' => empty( $settings['profile_init'] ) ? 0 : 1,
			'keep'         => max( 10, min( 500, (int) ( $settings['keep'] ?? $defaults['keep'] ) ) ),
			'sample'       => max( 1, min( 100, (int) ( $settings['sample'] ?? $defaults['sample'] ) ) ),
			'types'        => array_values(
				array_intersect(
					$defaults['types'],
					isset( $settings['types'] ) && is_array( $settings['types'] ) ? $settings['types'] : array()
				)
			),
		);

		update_option( self::SETTINGS_OPTION, $clean, false );
	}

	/**
	 * Read the snapshot log, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function log() {
		$log = get_option( self::LOG_OPTION, array() );

		return is_array( $log ) ? $log : array();
	}

	/**
	 * Append a snapshot, trimming the log to the configured length.
	 *
	 * @param array<string,mixed> $snapshot Snapshot payload.
	 * @return void
	 */
	public static function add( array $snapshot ) {
		$settings = self::settings();
		$log      = self::log();

		array_unshift( $log, $snapshot );
		$log = array_slice( $log, 0, (int) $settings['keep'] );

		// Autoload off: this row can reach a few hundred KB and is only ever read
		// on our own admin screen.
		update_option( self::LOG_OPTION, $log, false );
	}

	/**
	 * Delete every stored snapshot.
	 *
	 * @return void
	 */
	public static function clear() {
		update_option( self::LOG_OPTION, array(), false );
	}

	/**
	 * Average boot cost per plugin across the log.
	 *
	 * Include time and init time are summed because a plugin that loads lazily
	 * and then does all its work on init is not cheaper than one that does the
	 * work up front — the request pays for both.
	 *
	 * @param array<int,array<string,mixed>> $log Snapshot log.
	 * @return array<int,array<string,mixed>> Rows sorted by total time descending.
	 */
	public static function by_plugin( array $log ) {
		$totals = array();

		// Load and init are counted over different populations. An owner can appear
		// in init attribution without ever appearing in the include loop (wp-core,
		// the active theme), and a plugin can be included on requests where it
		// registers nothing on init. Averaging both over one shared counter
		// inflated wp-core to the sum of every request instead of its mean.
		$row_template = array(
			'slug'         => '',
			'load'         => 0.0,
			'init'         => 0.0,
			'files'        => 0,
			'mem'          => 0,
			'load_samples' => 0,
			'init_samples' => 0,
		);

		foreach ( $log as $snapshot ) {
			foreach ( (array) ( $snapshot['plugins'] ?? array() ) as $slug => $row ) {
				if ( ! isset( $totals[ $slug ] ) ) {
					$totals[ $slug ]         = $row_template;
					$totals[ $slug ]['slug'] = $slug;
				}

				$totals[ $slug ]['load']         += (float) ( $row['ms'] ?? 0 );
				$totals[ $slug ]['files']        += (int) ( $row['files'] ?? 0 );
				$totals[ $slug ]['mem']          += (int) ( $row['mem'] ?? 0 );
				$totals[ $slug ]['load_samples'] += 1;
			}

			foreach ( (array) ( $snapshot['init_by_owner'] ?? array() ) as $owner => $ms ) {
				if ( ! isset( $totals[ $owner ] ) ) {
					$totals[ $owner ]         = $row_template;
					$totals[ $owner ]['slug'] = $owner;
				}

				$totals[ $owner ]['init']         += (float) $ms;
				$totals[ $owner ]['init_samples'] += 1;
			}
		}

		foreach ( $totals as $slug => $row ) {
			$load_samples = max( 1, (int) $row['load_samples'] );
			$init_samples = max( 1, (int) $row['init_samples'] );

			$totals[ $slug ]['load']  = $row['load'] / $load_samples;
			$totals[ $slug ]['init']  = $row['init'] / $init_samples;
			$totals[ $slug ]['files'] = (int) round( $row['files'] / $load_samples );
			$totals[ $slug ]['mem']   = (int) round( $row['mem'] / $load_samples );
			$totals[ $slug ]['total'] = $totals[ $slug ]['load'] + $totals[ $slug ]['init'];

			// Kept for the UI: how many of the logged requests this row is an
			// average over, so a plugin measured on 2 requests is not read as
			// confidently as one measured on 200.
			$totals[ $slug ]['samples'] = max( $load_samples, $init_samples );
		}

		uasort(
			$totals,
			function ( $a, $b ) {
				return $b['total'] <=> $a['total'];
			}
		);

		return array_values( $totals );
	}

	/**
	 * Average phase timings across the log.
	 *
	 * @param array<int,array<string,mixed>> $log Snapshot log.
	 * @return array<int,array<string,mixed>>
	 */
	public static function by_phase( array $log ) {
		$totals  = array();
		$samples = 0;

		foreach ( $log as $snapshot ) {
			$marks = (array) ( $snapshot['marks'] ?? array() );
			if ( ! $marks ) {
				continue;
			}

			++$samples;

			foreach ( $marks as $mark ) {
				$label = (string) ( $mark['label'] ?? '?' );

				if ( ! isset( $totals[ $label ] ) ) {
					$totals[ $label ] = array(
						'label' => $label,
						'ms'    => 0.0,
						'files' => 0,
						'mem'   => 0,
					);
				}

				$totals[ $label ]['ms']    += (float) ( $mark['ms'] ?? 0 );
				$totals[ $label ]['files'] += (int) ( $mark['files'] ?? 0 );
				$totals[ $label ]['mem']   += (int) ( $mark['mem'] ?? 0 );
			}
		}

		if ( ! $samples ) {
			return array();
		}

		foreach ( $totals as $label => $row ) {
			$totals[ $label ]['ms']    = $row['ms'] / $samples;
			$totals[ $label ]['files'] = (int) round( $row['files'] / $samples );
			$totals[ $label ]['mem']   = (int) round( $row['mem'] / $samples );
		}

		return array_values( $totals );
	}

	/**
	 * Headline averages across the log, split by request type.
	 *
	 * @param array<int,array<string,mixed>> $log Snapshot log.
	 * @return array<string,array<string,mixed>>
	 */
	public static function by_type( array $log ) {
		$totals = array();

		foreach ( $log as $snapshot ) {
			$type = (string) ( $snapshot['type'] ?? 'frontend' );

			if ( ! isset( $totals[ $type ] ) ) {
				$totals[ $type ] = array(
					'type'    => $type,
					'count'   => 0,
					'ms'      => 0.0,
					'worst'   => 0.0,
					'best'    => PHP_FLOAT_MAX,
					'queries' => 0,
					'mem'     => 0,
					'files'   => 0,
				);
			}

			$ms = (float) ( $snapshot['total_ms'] ?? 0 );

			++$totals[ $type ]['count'];
			$totals[ $type ]['ms']      += $ms;
			$totals[ $type ]['worst']    = max( $totals[ $type ]['worst'], $ms );
			$totals[ $type ]['best']     = min( $totals[ $type ]['best'], $ms );
			$totals[ $type ]['queries'] += (int) ( $snapshot['queries'] ?? 0 );
			$totals[ $type ]['mem']     += (int) ( $snapshot['peak_mem'] ?? 0 );
			$totals[ $type ]['files']   += (int) ( $snapshot['files'] ?? 0 );
		}

		foreach ( $totals as $type => $row ) {
			$count = max( 1, (int) $row['count'] );

			$totals[ $type ]['ms']      = $row['ms'] / $count;
			$totals[ $type ]['queries'] = (int) round( $row['queries'] / $count );
			$totals[ $type ]['mem']     = (int) round( $row['mem'] / $count );
			$totals[ $type ]['files']   = (int) round( $row['files'] / $count );

			if ( PHP_FLOAT_MAX === $totals[ $type ]['best'] ) {
				$totals[ $type ]['best'] = 0.0;
			}
		}

		return $totals;
	}
}
