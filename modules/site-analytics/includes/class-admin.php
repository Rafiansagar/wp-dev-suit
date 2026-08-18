<?php
/**
 * Admin screen.
 *
 * @package WP_Dev_Suit\Modules\Site_Analytics
 */

namespace WP_Dev_Suit\Modules\Site_Analytics;

use WP_Dev_Suit\Menu;

defined( 'ABSPATH' ) || exit;

/**
 * Renders WP Dev Suit -> Site Analytics.
 *
 * Owns the screen and its form handlers only. Menu registration and asset
 * enqueueing belong to the suite, so that adding a second module cannot produce
 * a second top-level menu.
 */
class Admin {

	/**
	 * The module this screen belongs to, used to resolve its own URL.
	 *
	 * @var Site_Analytics
	 */
	protected $module;

	/**
	 * Hook the form handlers.
	 *
	 * @param Site_Analytics $module Owning module.
	 */
	public function __construct( Site_Analytics $module ) {
		$this->module = $module;

		add_action( 'admin_post_wpds_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_wpds_clear', array( $this, 'handle_clear' ) );
	}

	/**
	 * Persist the settings form.
	 *
	 * @return void
	 */
	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'wp-dev-suit' ) );
		}

		check_admin_referer( 'wpds_save' );

		Store::save_settings(
			array(
				'enabled'      => isset( $_POST['enabled'] ) ? 1 : 0,
				'profile_init' => isset( $_POST['profile_init'] ) ? 1 : 0,
				'keep'         => isset( $_POST['keep'] ) ? (int) $_POST['keep'] : 100,
				'sample'       => isset( $_POST['sample'] ) ? (int) $_POST['sample'] : 100,
				'types'        => isset( $_POST['types'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['types'] ) ) : array(),
			)
		);

		wp_safe_redirect( $this->page_url( array( 'tab' => 'settings', 'updated' => '1' ) ) );
		exit;
	}

	/**
	 * Empty the snapshot log.
	 *
	 * @return void
	 */
	public function handle_clear() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'wp-dev-suit' ) );
		}

		check_admin_referer( 'wpds_clear' );

		Store::clear();

		wp_safe_redirect( $this->page_url( array( 'cleared' => '1' ) ) );
		exit;
	}

	/**
	 * URL of this screen.
	 *
	 * @param array<string,string> $args Extra query args.
	 * @return string
	 */
	protected function page_url( array $args = array() ) {
		return add_query_arg(
			array_merge( array( 'page' => self::SLUG ), $args ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Format milliseconds, splitting the unit off so it can be styled down.
	 *
	 * @param float $ms    Milliseconds.
	 * @param bool  $small Wrap the unit in <small>.
	 * @return string Escaped HTML.
	 */
	protected function ms( $ms, $small = false ) {
		$ms     = (float) $ms;
		$digits = ( $ms < 10 ) ? 2 : 1;
		$number = esc_html( number_format_i18n( $ms, $digits ) );

		return $small ? $number . '<small>ms</small>' : $number . ' ms';
	}

	/**
	 * Format a byte count.
	 *
	 * @param int $bytes Bytes.
	 * @return string
	 */
	protected function mb( $bytes ) {
		return number_format_i18n( $bytes / 1048576, 2 ) . ' MB';
	}

	/**
	 * Severity class for a duration, so the bars carry meaning beyond length.
	 *
	 * @param float $ms Milliseconds.
	 * @return string
	 */
	protected function severity( $ms ) {
		if ( $ms >= 250 ) {
			return 'is-bad';
		}
		if ( $ms >= 80 ) {
			return 'is-warn';
		}
		if ( $ms < 15 ) {
			return 'is-good';
		}

		return '';
	}

	/**
	 * A proportional bar, so the ranking reads at a glance.
	 *
	 * @param float $value Row value.
	 * @param float $max   Largest value in the column.
	 * @return string Escaped HTML.
	 */
	protected function bar( $value, $max ) {
		$pct = $max > 0 ? max( 1, min( 100, ( $value / $max ) * 100 ) ) : 0;

		return sprintf(
			'<span class="sa-bar"><span class="sa-bar-fill %s" style="width:%s%%"></span></span>',
			esc_attr( $this->severity( $value ) ),
			esc_attr( (string) round( $pct, 1 ) )
		);
	}

	/**
	 * Inline sparkline for a series of durations.
	 *
	 * Drawn as a plain SVG path rather than a charting library — the whole point
	 * of this plugin is that it does not add weight to the pages it measures.
	 *
	 * @param array<int,float> $values Oldest value first.
	 * @return string Escaped HTML, or '' when there is too little data.
	 */
	protected function sparkline( array $values ) {
		$count = count( $values );

		if ( $count < 2 ) {
			return '';
		}

		$min   = min( $values );
		$max   = max( $values );
		$range = ( $max - $min ) ?: 1;

		$points = array();
		foreach ( array_values( $values ) as $i => $value ) {
			$x = ( $i / ( $count - 1 ) ) * 100;
			// Flat 3% padding top and bottom so the extremes are not clipped by
			// the stroke width.
			$y = 97 - ( ( $value - $min ) / $range ) * 94;

			$points[] = round( $x, 2 ) . ',' . round( $y, 2 );
		}

		$line = 'M' . implode( ' L', $points );
		$area = $line . ' L100,100 L0,100 Z';

		return sprintf(
			'<svg class="sa-spark" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true" focusable="false"><path class="sa-spark-area" d="%s"/><path d="%s"/></svg>',
			esc_attr( $area ),
			esc_attr( $line )
		);
	}

	/**
	 * Durations for one request type, oldest first, capped for the sparkline.
	 *
	 * @param array<int,array<string,mixed>> $log  Snapshot log, newest first.
	 * @param string                         $type Request type.
	 * @return array<int,float>
	 */
	protected function series( array $log, $type ) {
		$values = array();

		foreach ( $log as $snapshot ) {
			if ( ( $snapshot['type'] ?? '' ) === $type ) {
				$values[] = (float) ( $snapshot['total_ms'] ?? 0 );
			}
		}

		return array_reverse( array_slice( $values, 0, 40 ) );
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = Store::settings();
		$log      = Store::log();
		$tab      = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'plugins'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Settings is deliberately absent here — it is reached from the button in
		// the header instead. Listing it in both places was the same destination
		// offered twice.
		$tabs = array(
			'plugins'  => __( 'Plugins', 'wp-dev-suit' ),
			'phases'   => __( 'Boot phases', 'wp-dev-suit' ),
			'requests' => __( 'Requests', 'wp-dev-suit' ),
		);

		if ( 'settings' !== $tab && ! isset( $tabs[ $tab ] ) ) {
			$tab = 'plugins';
		}

		echo '<div class="wrap wp-dev-suit-page"><div class="sa-app">';

		$this->header( $settings, $log, $tab );
		$this->notices();
		$this->cards( $log );

		echo '<nav class="sa-tabs">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%s" class="sa-tab %s">%s</a>',
				esc_url( $this->page_url( array( 'tab' => $key ) ) ),
				esc_attr( $key === $tab ? 'is-active' : '' ),
				esc_html( $label )
			);
		}
		echo '</nav>';

		switch ( $tab ) {
			case 'phases':
				$this->tab_phases( $log );
				break;
			case 'requests':
				$this->tab_requests( $log );
				break;
			case 'settings':
				$this->tab_settings( $settings );
				break;
			default:
				$this->tab_plugins( $log, $settings );
		}

		echo '</div></div>';
	}

	/**
	 * Page title, description and live status.
	 *
	 * @param array<string,mixed>            $settings Settings.
	 * @param array<int,array<string,mixed>> $log      Snapshot log.
	 * @param string                         $tab      Active tab.
	 * @return void
	 */
	protected function header( array $settings, array $log, $tab = '' ) {
		$live = ! empty( $settings['enabled'] );

		echo '<header class="sa-head"><div>';
		echo '<h1>' . esc_html__( 'Site Analytics', 'wp-dev-suit' ) . '</h1>';
		echo '<p>' . esc_html__( 'Where each request actually spends its time — which plugin, which boot phase, which hook.', 'wp-dev-suit' ) . '</p>';
		echo '</div><div class="sa-head-actions">';

		printf(
			'<span class="sa-status %s">%s</span>',
			esc_attr( $live ? 'is-live' : '' ),
			esc_html(
				$live
					? sprintf(
						/* translators: %d: number of recorded requests */
						_n( 'Recording · %d request', 'Recording · %d requests', count( $log ), 'wp-dev-suit' ),
						count( $log )
					)
					: __( 'Paused', 'wp-dev-suit' )
			)
		);

		// Doubles as the active-state indicator now that Settings is not a tab.
		printf(
			'<a class="sa-btn %s" href="%s">%s</a>',
			esc_attr( 'settings' === $tab ? 'sa-btn-primary' : '' ),
			esc_url( $this->page_url( array( 'tab' => 'settings' ) ) ),
			esc_html__( 'Settings', 'wp-dev-suit' )
		);

		echo '</div></header>';
	}

	/**
	 * Notices for the redirect flags and the paused state.
	 *
	 * @return void
	 */
	protected function notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['updated'] ) ) {
			echo '<div class="sa-note is-good">' . esc_html__( 'Settings saved.', 'wp-dev-suit' ) . '</div>';
		}
		if ( ! empty( $_GET['cleared'] ) ) {
			echo '<div class="sa-note is-good">' . esc_html__( 'Log cleared.', 'wp-dev-suit' ) . '</div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Headline numbers per request type.
	 *
	 * @param array<int,array<string,mixed>> $log Snapshot log.
	 * @return void
	 */
	protected function cards( array $log ) {
		if ( ! $log ) {
			return;
		}

		$labels = array(
			'frontend' => __( 'Front end', 'wp-dev-suit' ),
			'admin'    => __( 'Admin', 'wp-dev-suit' ),
			'ajax'     => __( 'AJAX', 'wp-dev-suit' ),
			'rest'     => __( 'REST', 'wp-dev-suit' ),
			'cron'     => __( 'Cron', 'wp-dev-suit' ),
			'cli'      => __( 'WP-CLI', 'wp-dev-suit' ),
		);

		echo '<div class="sa-cards">';

		foreach ( Store::by_type( $log ) as $row ) {
			$type = (string) $row['type'];

			echo '<div class="sa-card">';
			echo '<span class="sa-card-label">' . esc_html( $labels[ $type ] ?? $type ) . '</span>';
			echo '<span class="sa-card-value">' . wp_kses_post( $this->ms( $row['ms'], true ) ) . '</span>';

			echo '<span class="sa-card-meta">';
			printf(
				/* translators: 1: fastest time, 2: slowest time */
				esc_html__( '%1$s – %2$s', 'wp-dev-suit' ),
				esc_html( $this->ms( $row['best'] ) ),
				esc_html( $this->ms( $row['worst'] ) )
			);
			echo '</span>';

			echo '<span class="sa-card-meta">';
			printf(
				/* translators: 1: request count, 2: query count, 3: peak memory */
				esc_html__( '%1$d req · %2$d queries · %3$s', 'wp-dev-suit' ),
				(int) $row['count'],
				(int) $row['queries'],
				esc_html( $this->mb( $row['mem'] ) )
			);
			echo '</span>';

			echo wp_kses(
				$this->sparkline( $this->series( $log, $type ) ),
				array(
					'svg'  => array(
						'class'               => true,
						'viewbox'             => true,
						'preserveaspectratio' => true,
						'aria-hidden'         => true,
						'focusable'           => true,
					),
					'path' => array(
						'class' => true,
						'd'     => true,
					),
				)
			);

			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Open a panel.
	 *
	 * @param string        $title Panel heading.
	 * @param string        $note  Optional explanatory line.
	 * @param callable|null $extra Optional callback that echoes the right-hand side.
	 * @return void
	 */
	protected function panel_open( $title, $note = '', $extra = null ) {
		echo '<section class="sa-panel"><div class="sa-panel-head"><div>';
		echo '<h2>' . esc_html( $title ) . '</h2>';

		if ( $note ) {
			echo '<p>' . esc_html( $note ) . '</p>';
		}

		echo '</div>';

		// A callback that echoes, not a string of HTML. Passing markup through
		// wp_kses_post() here silently ate the Clear log <form>, its nonce and its
		// hidden action field — $allowedposttags permits <button> but not <form>
		// or <input>, so the button rendered fine and submitted nothing.
		if ( is_callable( $extra ) ) {
			echo '<div>';
			$extra();
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Close a panel.
	 *
	 * @param bool $padded Wrap content in a padded body.
	 * @return void
	 */
	protected function panel_close( $padded = false ) {
		echo $padded ? '</div></section>' : '</section>';
	}

	/**
	 * Plugin cost ranking.
	 *
	 * @param array<int,array<string,mixed>> $log      Snapshot log.
	 * @param array<string,mixed>            $settings Settings.
	 * @return void
	 */
	protected function tab_plugins( array $log, array $settings ) {
		$rows = Store::by_plugin( $log );

		$this->panel_open(
			__( 'Cost per plugin', 'wp-dev-suit' ),
			__( 'Average per request. Load is the plugin\'s own include; Init is everything it runs on the init hook.', 'wp-dev-suit' )
		);

		if ( ! $rows ) {
			$this->empty_state();
			$this->panel_close();
			return;
		}

		if ( empty( $settings['profile_init'] ) ) {
			echo '<div class="sa-panel-body" style="padding-bottom:0">';
			echo '<div class="sa-note is-warn">' . esc_html__( 'The init profiler is off, so the Init column is empty and this ranking only reflects include cost. Most plugins do their real work on init.', 'wp-dev-suit' ) . '</div>';
			echo '</div>';
		}

		$max   = (float) $rows[0]['total'];
		$grand = 0.0;
		foreach ( $rows as $row ) {
			$grand += (float) $row['total'];
		}

		echo '<table class="sa-table"><thead><tr>';
		echo '<th class="sa-rank">#</th>';
		echo '<th class="sa-col-name">' . esc_html__( 'Plugin', 'wp-dev-suit' ) . '</th>';
		echo '<th class="sa-num sa-col-total">' . esc_html__( 'Total', 'wp-dev-suit' ) . '</th>';
		echo '<th class="sa-bar-col"></th>';
		echo '<th class="sa-num sa-col-sm">' . esc_html__( 'Share', 'wp-dev-suit' ) . '</th>';
		echo '<th class="sa-num sa-col-sm">' . esc_html__( 'Load', 'wp-dev-suit' ) . '</th>';
		echo '<th class="sa-num sa-col-sm">' . esc_html__( 'Init', 'wp-dev-suit' ) . '</th>';
		echo '<th class="sa-num sa-col-sm">' . esc_html__( 'Files', 'wp-dev-suit' ) . '</th>';
		echo '<th class="sa-num sa-col-sm">' . esc_html__( 'Memory', 'wp-dev-suit' ) . '</th>';
		echo '</tr></thead><tbody>';

		$rank = 0;
		foreach ( $rows as $row ) {
			++$rank;
			$share = $grand > 0 ? ( $row['total'] / $grand ) * 100 : 0;

			echo '<tr>';
			echo '<td class="sa-rank">' . esc_html( (string) $rank ) . '</td>';
			echo '<td class="sa-name">' . esc_html( $row['slug'] ) . '</td>';
			echo '<td class="sa-num sa-strong">' . esc_html( $this->ms( $row['total'] ) ) . '</td>';
			echo '<td class="sa-bar-col">' . wp_kses_post( $this->bar( $row['total'], $max ) ) . '</td>';
			echo '<td class="sa-num sa-sub">' . esc_html( number_format_i18n( $share, 1 ) ) . '%</td>';
			echo '<td class="sa-num">' . esc_html( $this->ms( $row['load'] ) ) . '</td>';
			echo '<td class="sa-num">' . esc_html( $this->ms( $row['init'] ) ) . '</td>';
			echo '<td class="sa-num sa-sub">' . esc_html( number_format_i18n( $row['files'] ) ) . '</td>';
			echo '<td class="sa-num sa-sub">' . esc_html( $this->mb( $row['mem'] ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		$this->panel_close();
	}

	/**
	 * Boot phase breakdown.
	 *
	 * @param array<int,array<string,mixed>> $log Snapshot log.
	 * @return void
	 */
	protected function tab_phases( array $log ) {
		$rows = Store::by_phase( $log );

		$this->panel_open(
			__( 'Boot phases', 'wp-dev-suit' ),
			__( 'Average time between consecutive milestones. Each row is the work that happened before that milestone was reached.', 'wp-dev-suit' )
		);

		if ( ! $rows ) {
			$this->empty_state();
			$this->panel_close();
			return;
		}

		$max   = 0.0;
		$grand = 0.0;
		foreach ( $rows as $row ) {
			$max    = max( $max, (float) $row['ms'] );
			$grand += (float) $row['ms'];
		}

		echo '<table class="sa-table"><thead><tr>';
		echo '<th class="sa-col-name">' . esc_html__( 'Phase', 'wp-dev-suit' ) . '</th>';
		echo '<th class="sa-num sa-col-total">' . esc_html__( 'Time', 'wp-dev-suit' ) . '</th>';
		echo '<th class="sa-bar-col"></th>';
		echo '<th class="sa-num sa-col-sm">' . esc_html__( 'Share', 'wp-dev-suit' ) . '</th>';
		echo '<th class="sa-num sa-col-sm">' . esc_html__( 'Files', 'wp-dev-suit' ) . '</th>';
		echo '<th class="sa-num sa-col-sm">' . esc_html__( 'Memory', 'wp-dev-suit' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$share = $grand > 0 ? ( $row['ms'] / $grand ) * 100 : 0;

			echo '<tr>';
			echo '<td>' . esc_html( $row['label'] ) . '</td>';
			echo '<td class="sa-num sa-strong">' . esc_html( $this->ms( $row['ms'] ) ) . '</td>';
			echo '<td class="sa-bar-col">' . wp_kses_post( $this->bar( $row['ms'], $max ) ) . '</td>';
			echo '<td class="sa-num sa-sub">' . esc_html( number_format_i18n( $share, 1 ) ) . '%</td>';
			echo '<td class="sa-num sa-sub">' . esc_html( number_format_i18n( $row['files'] ) ) . '</td>';
			echo '<td class="sa-num sa-sub">' . esc_html( $this->mb( $row['mem'] ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		$this->panel_close();
	}

	/**
	 * Individual request log, newest first.
	 *
	 * @param array<int,array<string,mixed>> $log Snapshot log.
	 * @return void
	 */
	protected function tab_requests( array $log ) {
		$clear = $log
			? function () {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( 'wpds_clear' );
				echo '<input type="hidden" name="action" value="wpds_clear" />';
				echo '<button type="submit" class="sa-btn sa-btn-danger">' . esc_html__( 'Clear log', 'wp-dev-suit' ) . '</button>';
				echo '</form>';
			}
			: null;

		$this->panel_open(
			__( 'Recent requests', 'wp-dev-suit' ),
			__( 'Newest first. Expand a row to see which callbacks dominated its init hook.', 'wp-dev-suit' ),
			$clear
		);

		if ( ! $log ) {
			$this->empty_state();
			$this->panel_close();
			return;
		}

		echo '<table class="sa-table"><thead><tr>';
		echo '<th>' . esc_html__( 'When', 'wp-dev-suit' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'wp-dev-suit' ) . '</th>';
		echo '<th>' . esc_html__( 'Request', 'wp-dev-suit' ) . '</th>';
		echo '<th class="sa-num">' . esc_html__( 'Total', 'wp-dev-suit' ) . '</th>';
		echo '<th class="sa-num">' . esc_html__( 'Queries', 'wp-dev-suit' ) . '</th>';
		echo '<th class="sa-num">' . esc_html__( 'Peak mem', 'wp-dev-suit' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $log as $row ) {
			$type  = (string) ( $row['type'] ?? '' );
			$label = ! empty( $row['action'] ) ? $row['action'] : ( $row['url'] ?? '' );

			echo '<tr>';
			echo '<td class="sa-sub">' . esc_html( wp_date( 'H:i:s', (int) $row['time'] ) ) . '</td>';
			echo '<td><span class="sa-pill is-' . esc_attr( $type ) . '">' . esc_html( $type ) . '</span></td>';

			echo '<td>';
			echo '<div class="sa-name">' . esc_html( (string) $label ) . '</div>';
			$this->request_detail( (array) ( $row['init_slowest'] ?? array() ) );
			echo '</td>';

			echo '<td class="sa-num sa-strong">' . esc_html( $this->ms( $row['total_ms'] ?? 0 ) ) . '</td>';
			echo '<td class="sa-num sa-sub">' . esc_html( number_format_i18n( (int) ( $row['queries'] ?? 0 ) ) ) . '</td>';
			echo '<td class="sa-num sa-sub">' . esc_html( $this->mb( (int) ( $row['peak_mem'] ?? 0 ) ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		$this->panel_close();
	}

	/**
	 * Collapsible init breakdown for one request row.
	 *
	 * @param array<int,array<string,mixed>> $slowest Slowest init callbacks.
	 * @return void
	 */
	protected function request_detail( array $slowest ) {
		if ( ! $slowest ) {
			return;
		}

		echo '<details class="sa-req-detail"><summary>';
		printf(
			/* translators: %d: number of callbacks listed */
			esc_html( _n( 'Top %d init callback', 'Top %d init callbacks', count( $slowest ), 'wp-dev-suit' ) ),
			count( $slowest )
		);
		echo '</summary><ul class="sa-req-list">';

		foreach ( $slowest as $entry ) {
			printf(
				'<li><b>%s</b> <span class="sa-pill">%s</span> <code>%s</code></li>',
				esc_html( $this->ms( $entry['ms'] ) ),
				esc_html( (string) $entry['owner'] ),
				esc_html( (string) $entry['name'] )
			);
		}

		echo '</ul></details>';
	}

	/**
	 * Settings form.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return void
	 */
	protected function tab_settings( array $settings ) {
		$types = array(
			'frontend' => __( 'Front end', 'wp-dev-suit' ),
			'admin'    => __( 'Admin', 'wp-dev-suit' ),
			'ajax'     => __( 'AJAX', 'wp-dev-suit' ),
			'rest'     => __( 'REST', 'wp-dev-suit' ),
			'cron'     => __( 'Cron', 'wp-dev-suit' ),
		);

		$this->panel_open( __( 'Settings', 'wp-dev-suit' ) );

		echo '<div class="sa-panel-body">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wpds_save' );
		echo '<input type="hidden" name="action" value="wpds_save" />';

		echo '<div class="sa-fields">';

		echo '<div class="sa-field">';
		echo '<label class="sa-check"><input type="checkbox" name="enabled" value="1" ' . checked( 1, (int) $settings['enabled'], false ) . ' /><span>';
		echo '<strong>' . esc_html__( 'Record request snapshots', 'wp-dev-suit' ) . '</strong>';
		echo '<span>' . esc_html__( 'Switch this off once you have what you need. The log is capped at the length below, but every recorded request rewrites the whole of it — roughly 5 KB per entry, so a full 100-entry log means a 500 KB write on each request.', 'wp-dev-suit' ) . '</span>';
		echo '</span></label>';
		echo '</div>';

		echo '<div class="sa-field">';
		echo '<label class="sa-check"><input type="checkbox" name="profile_init" value="1" ' . checked( 1, (int) $settings['profile_init'], false ) . ' /><span>';
		echo '<strong>' . esc_html__( 'Time every callback on the init hook', 'wp-dev-suit' ) . '</strong>';
		echo '<span>' . esc_html__( 'Required to attribute init time to a plugin. Wrapping several hundred callbacks costs a few ms of its own.', 'wp-dev-suit' ) . '</span>';
		echo '</span></label>';
		echo '</div>';

		echo '<div class="sa-field">';
		echo '<span class="sa-field-title">' . esc_html__( 'Request types', 'wp-dev-suit' ) . '</span>';
		echo '<div class="sa-chips">';
		foreach ( $types as $key => $label ) {
			echo '<label class="sa-chip"><input type="checkbox" name="types[]" value="' . esc_attr( $key ) . '" ';
			checked( in_array( $key, (array) $settings['types'], true ) );
			echo ' /> ' . esc_html( $label ) . '</label>';
		}
		echo '</div>';
		echo '<span class="sa-help">' . esc_html__( 'Only these kinds of request are recorded.', 'wp-dev-suit' ) . '</span>';
		echo '</div>';

		echo '<div class="sa-field">';
		echo '<label class="sa-field-title" for="sa-keep">' . esc_html__( 'Log length', 'wp-dev-suit' ) . '</label>';
		echo '<span class="sa-inline-num">';
		echo '<input type="number" id="sa-keep" name="keep" min="10" max="500" value="' . esc_attr( (string) $settings['keep'] ) . '" />';
		echo '<span class="sa-help" style="margin:0">' . esc_html__( 'requests kept — oldest drops off; ~5 KB each, and the full log is rewritten on every recorded request', 'wp-dev-suit' ) . '</span>';
		echo '</span>';
		echo '</div>';

		echo '<div class="sa-field">';
		echo '<label class="sa-field-title" for="sa-sample">' . esc_html__( 'Sample rate', 'wp-dev-suit' ) . '</label>';
		echo '<span class="sa-inline-num">';
		echo '<input type="number" id="sa-sample" name="sample" min="1" max="100" value="' . esc_attr( (string) $settings['sample'] ) . '" />';
		echo '<span class="sa-help" style="margin:0">' . esc_html__( '% of eligible requests — drop it on a busy site', 'wp-dev-suit' ) . '</span>';
		echo '</span>';
		echo '</div>';

		echo '<div>';
		echo '<button type="submit" class="sa-btn sa-btn-primary">' . esc_html__( 'Save settings', 'wp-dev-suit' ) . '</button>';
		echo '</div>';

		echo '</div>';
		echo '</form>';
		echo '</div>';

		$this->panel_close();
	}

	/**
	 * Shown when there is nothing to chart yet.
	 *
	 * @return void
	 */
	protected function empty_state() {
		echo '<div class="sa-empty">';
		echo '<strong>' . esc_html__( 'Nothing recorded yet', 'wp-dev-suit' ) . '</strong>';
		echo '<span>' . esc_html__( 'Turn recording on, then load a few pages and come back.', 'wp-dev-suit' ) . '</span>';
		echo '</div>';
	}
}
