<?php
/**
 * Admin notices: one-shot flash messages that survive a redirect, and
 * persistent notices a user can dismiss permanently.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Class Notices
 */
final class Notices {

	private const TRANSIENT_PREFIX = 'ibg_outreach_notices_';
	private const USER_META        = 'ibg_outreach_dismissed_notices';
	private const DISMISS_ACTION   = 'ibg_outreach_dismiss_notice';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_notices', array( $this, 'render_flash' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( $this, 'handle_dismiss' ) );
	}

	/**
	 * Queue a flash notice for the current user.
	 *
	 * @param string $message Plain-text message (escaped on output).
	 * @param string $type    success|error|warning|info.
	 * @return void
	 */
	public function add( string $message, string $type = 'success' ): void {
		$key     = $this->transient_key();
		$notices = get_transient( $key );
		$notices = is_array( $notices ) ? $notices : array();

		$notices[] = array(
			'message' => $message,
			'type'    => in_array( $type, array( 'success', 'error', 'warning', 'info' ), true ) ? $type : 'info',
		);

		set_transient( $key, $notices, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Output and clear queued flash notices. Only on plugin screens.
	 *
	 * @return void
	 */
	public function render_flash(): void {
		if ( ! $this->is_plugin_screen() ) {
			return;
		}

		$key     = $this->transient_key();
		$notices = get_transient( $key );
		if ( ! is_array( $notices ) || empty( $notices ) ) {
			return;
		}
		delete_transient( $key );

		foreach ( $notices as $notice ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $notice['type'] ),
				esc_html( $notice['message'] )
			);
		}
	}

	/**
	 * Whether the current user has permanently dismissed a notice.
	 *
	 * @param string $id Notice id.
	 * @return bool
	 */
	public function is_dismissed( string $id ): bool {
		$dismissed = get_user_meta( get_current_user_id(), self::USER_META, true );
		return is_array( $dismissed ) && in_array( $id, $dismissed, true );
	}

	/**
	 * URL that permanently dismisses a notice for the current user.
	 *
	 * @param string $id Notice id.
	 * @return string
	 */
	public function get_dismiss_url( string $id ): string {
		$url = add_query_arg(
			array(
				'action' => self::DISMISS_ACTION,
				'notice' => $id,
			),
			admin_url( 'admin-post.php' )
		);
		return wp_nonce_url( $url, self::DISMISS_ACTION . '_' . $id );
	}

	/**
	 * admin-post handler for permanent dismissal.
	 *
	 * @return void
	 */
	public function handle_dismiss(): void {
		$id = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';

		if ( '' === $id || ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Invalid request.', 'ibg-client-outreach' ), 400 );
		}

		check_admin_referer( self::DISMISS_ACTION . '_' . $id );

		$user_id   = get_current_user_id();
		$dismissed = get_user_meta( $user_id, self::USER_META, true );
		$dismissed = is_array( $dismissed ) ? $dismissed : array();

		if ( ! in_array( $id, $dismissed, true ) ) {
			$dismissed[] = $id;
			update_user_meta( $user_id, self::USER_META, $dismissed );
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=' . Menu::PARENT_SLUG ) );
		exit;
	}

	/**
	 * Whether the current screen belongs to the plugin.
	 *
	 * @return bool
	 */
	private function is_plugin_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen instanceof \WP_Screen && false !== strpos( $screen->id, Menu::PARENT_SLUG );
	}

	/**
	 * Per-user transient key.
	 *
	 * @return string
	 */
	private function transient_key(): string {
		return self::TRANSIENT_PREFIX . get_current_user_id();
	}
}
