<?php
/**
 * Plugin Switcher admin screen.
 *
 * @package WP_Dev_Suit\Modules\Plugin_Switcher
 */

namespace WP_Dev_Suit\Modules\Plugin_Switcher;

use WP_Dev_Suit\Menu;

defined( 'ABSPATH' ) || exit;

/**
 * Lists profiles, applies them, and edits their membership.
 */
class Admin {

	/**
	 * Owning module.
	 *
	 * @var Plugin_Switcher
	 */
	protected $module;

	/**
	 * Hook the form handlers.
	 *
	 * @param Plugin_Switcher $module Owning module.
	 */
	public function __construct( Plugin_Switcher $module ) {
		$this->module = $module;

		add_action( 'admin_post_wpds_ps_apply', array( $this, 'handle_apply' ) );
		add_action( 'admin_post_wpds_ps_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_wpds_ps_delete', array( $this, 'handle_delete' ) );
	}

	/**
	 * Guard shared by every handler.
	 *
	 * @param string $nonce Nonce action.
	 * @return void
	 */
	protected function guard( $nonce ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'wp-dev-suit' ) );
		}

		check_admin_referer( $nonce );
	}

	/**
	 * Apply a profile, or clear back to everything.
	 *
	 * @return void
	 */
	public function handle_apply() {
		$this->guard( 'wpds_ps_apply' );

		$id = isset( $_POST['profile'] ) ? sanitize_key( wp_unslash( $_POST['profile'] ) ) : '';

		Profiles::set_active( $id );

		wp_safe_redirect( $this->page_url( array( 'applied' => '1' ) ) );
		exit;
	}

	/**
	 * Create or update a profile.
	 *
	 * @return void
	 */
	public function handle_save() {
		$this->guard( 'wpds_ps_save' );

		$id      = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		$label   = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		$plugins = isset( $_POST['plugins'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['plugins'] ) ) : array();

		// "Snapshot what is active now" posts no checkboxes; it reads the real
		// list server-side so it cannot capture an already-filtered set.
		if ( isset( $_POST['from_current'] ) ) {
			$plugins = Profiles::really_active();
		}

		$saved = Profiles::save( $id, $label, $plugins );

		wp_safe_redirect( $this->page_url( array( 'profile' => $saved, 'saved' => '1' ) ) );
		exit;
	}

	/**
	 * Delete a profile.
	 *
	 * @return void
	 */
	public function handle_delete() {
		$this->guard( 'wpds_ps_delete' );

		$id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';

		if ( '' !== $id ) {
			Profiles::delete( $id );
		}

		wp_safe_redirect( $this->page_url( array( 'deleted' => '1' ) ) );
		exit;
	}

	/**
	 * URL of this screen.
	 *
	 * @param array<string,string> $args Extra query args.
	 * @return string
	 */
	protected function page_url( array $args = array() ) {
		return Menu::url( $this->module, $args );
	}

	/**
	 * Open a form that posts to admin-post.
	 *
	 * @param string $action Action name, also used as the nonce.
	 * @return void
	 */
	protected function form_open( $action ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$editing = isset( $_GET['profile'] ) ? sanitize_key( wp_unslash( $_GET['profile'] ) ) : '';

		echo '<div class="wrap wpds-page"><div class="wpds-app">';

		$this->header();
		$this->notices();

		if ( '' !== $editing && Profiles::get( $editing ) ) {
			$this->panel_edit( $editing );
		} else {
			$this->panel_profiles();
			$this->panel_new();
		}

		echo '</div></div>';
	}

	/**
	 * Title and current state.
	 *
	 * @return void
	 */
	protected function header() {
		$active = Profiles::active_id();
		$label  = $active ? ( Profiles::get( $active )['label'] ?? $active ) : '';

		echo '<header class="wpds-head"><div>';
		echo '<h1>' . esc_html__( 'Plugin Switcher', 'wp-dev-suit' ) . '</h1>';
		echo '<p>' . esc_html__( 'Load a named subset of the active plugins. Nothing is really deactivated — no hooks fire, the stored plugin list is untouched, and clearing the profile puts everything back.', 'wp-dev-suit' ) . '</p>';
		echo '</div><div class="wpds-head-actions">';

		printf(
			'<span class="wpds-status %s">%s</span>',
			esc_attr( $active ? 'is-live' : '' ),
			esc_html(
				$active
					/* translators: %s: profile name */
					? sprintf( __( 'Applied · %s', 'wp-dev-suit' ), $label )
					: __( 'All plugins loading', 'wp-dev-suit' )
			)
		);

		if ( $active ) {
			$this->form_open( 'wpds_ps_apply' );
			echo '<input type="hidden" name="profile" value="" />';
			echo '<button type="submit" class="wpds-btn">' . esc_html__( 'Load everything', 'wp-dev-suit' ) . '</button>';
			echo '</form>';
		}

		echo '</div></header>';
	}

	/**
	 * Redirect-flag notices, plus the standing caveat about the plugins screen.
	 *
	 * @return void
	 */
	protected function notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		foreach ( array(
			'applied' => __( 'Profile applied.', 'wp-dev-suit' ),
			'saved'   => __( 'Profile saved.', 'wp-dev-suit' ),
			'deleted' => __( 'Profile deleted.', 'wp-dev-suit' ),
		) as $flag => $message ) {
			if ( ! empty( $_GET[ $flag ] ) ) {
				echo '<div class="wpds-note is-good">' . esc_html( $message ) . '</div>';
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( Profiles::active_id() ) {
			echo '<div class="wpds-note is-warn">' . esc_html__( 'The Plugins screen deliberately ignores the profile and loads everything, so it keeps showing what is genuinely activated. Judge speed anywhere else.', 'wp-dev-suit' ) . '</div>';
		}
	}

	/**
	 * The profile list.
	 *
	 * @return void
	 */
	protected function panel_profiles() {
		$profiles = Profiles::all();
		$active   = Profiles::active_id();
		$total    = count( Profiles::really_active() );

		echo '<section class="wpds-panel"><div class="wpds-panel-head"><div>';
		echo '<h2>' . esc_html__( 'Profiles', 'wp-dev-suit' ) . '</h2>';
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: %d: number of activated plugins */
					_n( '%d plugin is activated on this site.', '%d plugins are activated on this site.', $total, 'wp-dev-suit' ),
					$total
				)
			)
		);
		echo '</div></div>';

		if ( ! $profiles ) {
			echo '<div class="wpds-empty"><strong>' . esc_html__( 'No profiles yet', 'wp-dev-suit' ) . '</strong>';
			echo '<span>' . esc_html__( 'Create one below — start by snapshotting what is active right now.', 'wp-dev-suit' ) . '</span></div>';
			echo '</section>';
			return;
		}

		echo '<table class="wpds-table"><thead><tr>';
		echo '<th class="wpds-col-name">' . esc_html__( 'Profile', 'wp-dev-suit' ) . '</th>';
		echo '<th class="wpds-num wpds-col-sm">' . esc_html__( 'Loads', 'wp-dev-suit' ) . '</th>';
		echo '<th class="wpds-num wpds-col-sm">' . esc_html__( 'Suppresses', 'wp-dev-suit' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $profiles as $id => $profile ) {
			$loads      = count( array_intersect( Profiles::really_active(), $profile['plugins'] ) );
			$suppresses = max( 0, $total - $loads );
			$is_active  = ( $id === $active );

			echo '<tr>';
			echo '<td><span class="wpds-name">' . esc_html( $profile['label'] ) . '</span> ';
			if ( $is_active ) {
				echo '<span class="wpds-pill">' . esc_html__( 'applied', 'wp-dev-suit' ) . '</span>';
			}
			echo '</td>';
			echo '<td class="wpds-num wpds-strong">' . esc_html( number_format_i18n( $loads ) ) . '</td>';
			echo '<td class="wpds-num wpds-sub">' . esc_html( number_format_i18n( $suppresses ) ) . '</td>';

			echo '<td class="wpds-num"><span class="wpds-inline-num">';

			if ( ! $is_active ) {
				$this->form_open( 'wpds_ps_apply' );
				echo '<input type="hidden" name="profile" value="' . esc_attr( $id ) . '" />';
				echo '<button type="submit" class="wpds-btn wpds-btn-primary">' . esc_html__( 'Apply', 'wp-dev-suit' ) . '</button>';
				echo '</form>';
			}

			printf(
				'<a class="wpds-btn" href="%s">%s</a>',
				esc_url( $this->page_url( array( 'profile' => $id ) ) ),
				esc_html__( 'Edit', 'wp-dev-suit' )
			);

			$this->form_open( 'wpds_ps_delete' );
			echo '<input type="hidden" name="id" value="' . esc_attr( $id ) . '" />';
			echo '<button type="submit" class="wpds-btn wpds-btn-danger">' . esc_html__( 'Delete', 'wp-dev-suit' ) . '</button>';
			echo '</form>';

			echo '</span></td>';
			echo '</tr>';
		}

		echo '</tbody></table></section>';
	}

	/**
	 * Create-a-profile panel.
	 *
	 * @return void
	 */
	protected function panel_new() {
		echo '<section class="wpds-panel" style="margin-top:16px"><div class="wpds-panel-head"><div>';
		echo '<h2>' . esc_html__( 'New profile', 'wp-dev-suit' ) . '</h2>';
		echo '<p>' . esc_html__( 'Snapshot the current active set, then edit it down.', 'wp-dev-suit' ) . '</p>';
		echo '</div></div><div class="wpds-panel-body">';

		$this->form_open( 'wpds_ps_save' );
		echo '<div class="wpds-fields">';

		echo '<div class="wpds-field">';
		echo '<label class="wpds-field-title" for="wpds-ps-label">' . esc_html__( 'Name', 'wp-dev-suit' ) . '</label>';
		echo '<input type="text" id="wpds-ps-label" name="label" class="wpds-input" placeholder="' . esc_attr__( 'e.g. CF7 only', 'wp-dev-suit' ) . '" required />';
		echo '</div>';

		echo '<div>';
		echo '<input type="hidden" name="from_current" value="1" />';
		echo '<button type="submit" class="wpds-btn wpds-btn-primary">' . esc_html__( 'Create from current active plugins', 'wp-dev-suit' ) . '</button>';
		echo '</div>';

		echo '</div>';
		echo '</form>';
		echo '</div></section>';
	}

	/**
	 * Membership editor for one profile.
	 *
	 * @param string $id Profile id.
	 * @return void
	 */
	protected function panel_edit( $id ) {
		$profile = Profiles::get( $id );
		$active  = Profiles::really_active();
		$all     = Profiles::installed();

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$data = get_plugins();
		$self = plugin_basename( WPDS_FILE );

		echo '<section class="wpds-panel"><div class="wpds-panel-head"><div>';
		/* translators: %s: profile name */
		echo '<h2>' . esc_html( sprintf( __( 'Editing “%s”', 'wp-dev-suit' ), $profile['label'] ) ) . '</h2>';
		echo '<p>' . esc_html__( 'Ticked plugins load while this profile is applied. Plugins that are not currently activated are listed but have no effect until they are.', 'wp-dev-suit' ) . '</p>';
		echo '</div><div>';
		printf( '<a class="wpds-btn" href="%s">%s</a>', esc_url( $this->page_url() ), esc_html__( 'Back', 'wp-dev-suit' ) );
		echo '</div></div><div class="wpds-panel-body">';

		$this->form_open( 'wpds_ps_save' );
		echo '<input type="hidden" name="id" value="' . esc_attr( $id ) . '" />';

		echo '<div class="wpds-fields" style="max-width:none">';

		echo '<div class="wpds-field">';
		echo '<label class="wpds-field-title" for="wpds-ps-label">' . esc_html__( 'Name', 'wp-dev-suit' ) . '</label>';
		echo '<input type="text" id="wpds-ps-label" name="label" class="wpds-input" value="' . esc_attr( $profile['label'] ) . '" required />';
		echo '</div>';

		echo '<div class="wpds-field"><span class="wpds-field-title">' . esc_html__( 'Plugins to keep loaded', 'wp-dev-suit' ) . '</span>';
		echo '<div class="wpds-chips">';

		foreach ( $all as $basename ) {
			$name       = $data[ $basename ]['Name'] ?? $basename;
			$is_active  = in_array( $basename, $active, true );
			$is_self    = ( $basename === $self );
			$is_checked = $is_self || in_array( $basename, $profile['plugins'], true );

			echo '<label class="wpds-chip">';
			printf(
				'<input type="checkbox" name="plugins[]" value="%s" %s %s />',
				esc_attr( $basename ),
				checked( true, $is_checked, false ),
				// The suite must stay loaded or there is no screen left to undo
				// this from. Shown ticked and disabled, with a hidden input so the
				// value still posts.
				$is_self ? 'disabled' : ''
			);
			echo esc_html( $name );

			if ( ! $is_active ) {
				echo ' <span class="wpds-sub">' . esc_html__( '(inactive)', 'wp-dev-suit' ) . '</span>';
			}

			echo '</label>';

			if ( $is_self ) {
				echo '<input type="hidden" name="plugins[]" value="' . esc_attr( $basename ) . '" />';
			}
		}

		echo '</div></div>';

		echo '<div><button type="submit" class="wpds-btn wpds-btn-primary">' . esc_html__( 'Save profile', 'wp-dev-suit' ) . '</button></div>';
		echo '</div>';
		echo '</form>';
		echo '</div></section>';
	}
}
