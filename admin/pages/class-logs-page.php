<?php
/**
 * Email Logs page.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Admin\Pages;

use IBG\Outreach\Admin\Tables\Logs_List_Table;
use IBG\Outreach\Campaigns\Campaign;
use IBG\Outreach\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Class Logs_Page
 */
final class Logs_Page extends Abstract_Page {

	public const SLUG = 'ibg-outreach-logs';

	private const NONCE_PURGE = 'ibg_purge_logs';

	/**
	 * Table.
	 *
	 * @var Logs_List_Table|null
	 */
	private ?Logs_List_Table $table = null;

	/** @inheritDoc */
	public function get_slug(): string {
		return self::SLUG;
	}

	/** @inheritDoc */
	public function get_page_title(): string {
		return __( 'Email Logs', 'ibg-client-outreach' );
	}

	/** @inheritDoc */
	public function get_menu_title(): string {
		return __( 'Email Logs', 'ibg-client-outreach' );
	}

	/** @inheritDoc */
	public function get_capability(): string {
		return Capabilities::VIEW;
	}

	/** @inheritDoc */
	public function get_position(): int {
		return 80;
	}

	/** @inheritDoc */
	public function register_hooks(): void {
		add_filter(
			'set_screen_option_' . Logs_List_Table::PER_PAGE_OPTION,
			static fn( $status, $option, $value ): int => max( 1, min( 500, (int) $value ) ),
			10,
			3
		);
	}

	/** @inheritDoc */
	public function load(): void {
		$this->require_capability();

		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Log rows per page', 'ibg-client-outreach' ),
				'default' => 50,
				'option'  => Logs_List_Table::PER_PAGE_OPTION,
			)
		);

		$names = array();
		foreach ( $this->plugin->get( 'campaigns' )->query( array( 'per_page' => 500, 'orderby' => 'created_at' ) )['items'] as $campaign ) {
			/** @var Campaign $campaign */
			$names[ $campaign->id ] = $campaign->name;
		}

		$this->table = new Logs_List_Table( $this->plugin->get( 'logs' ), $names, $this, current_user_can( Capabilities::MANAGE_SETTINGS ) );

		$this->handle_purge();
		$this->handle_bulk_delete();
		$this->redirect_clean_filter_form();
	}

	/** @inheritDoc */
	public function render(): void {
		$this->require_capability();
		$this->table->prepare_items();

		$retention = (int) $this->plugin->get( 'settings' )->get( 'log_retention_days', 90 );

		$this->render_view(
			'logs',
			array(
				'page'      => $this,
				'table'     => $this->table,
				'can_purge' => current_user_can( Capabilities::MANAGE_SETTINGS ),
				'purge_url' => wp_nonce_url( $this->get_url( array( 'action' => 'purge' ) ), self::NONCE_PURGE ),
				'retention' => $retention,
			)
		);
	}

	/**
	 * Apply the retention policy immediately.
	 *
	 * @return void
	 */
	private function handle_purge(): void {
		if ( ! isset( $_GET['action'] ) || 'purge' !== $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$this->require_capability( Capabilities::MANAGE_SETTINGS );
		check_admin_referer( self::NONCE_PURGE );

		$days  = (int) $this->plugin->get( 'settings' )->get( 'log_retention_days', 90 );
		$count = $this->plugin->get( 'logs' )->cleanup( $days );

		/* translators: 1: number of rows, 2: days */
		$this->notices->add( sprintf( __( '%1$d log entries older than %2$d days deleted.', 'ibg-client-outreach' ), $count, $days ), 'success' );

		wp_safe_redirect( $this->get_url() );
		exit;
	}

	/**
	 * Bulk delete.
	 *
	 * @return void
	 */
	private function handle_bulk_delete(): void {
		if ( 'delete' !== $this->table->current_action() ) {
			return;
		}

		$this->require_capability( Capabilities::MANAGE_SETTINGS );
		check_admin_referer( 'bulk-logs' );

		$ids   = isset( $_REQUEST['log'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['log'] ) ) : array();
		$count = $this->plugin->get( 'logs' )->delete_many( $ids );

		/* translators: %d: number */
		$this->notices->add( sprintf( _n( '%d log entry deleted.', '%d log entries deleted.', $count, 'ibg-client-outreach' ), $count ), 'success' );

		wp_safe_redirect( $this->get_url( $this->table->get_filter_args() ) );
		exit;
	}

	/**
	 * Clean the URL after a filter submit.
	 *
	 * @return void
	 */
	private function redirect_clean_filter_form(): void {
		if ( ! isset( $_GET['filter_action'] ) && ! isset( $_GET['_wp_http_referer'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		wp_safe_redirect( $this->get_url( $this->table->get_filter_args() ) );
		exit;
	}
}
