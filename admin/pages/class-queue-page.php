<?php
/**
 * Email Queue page.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Admin\Pages;

use IBG\Outreach\Admin\Tables\Queue_List_Table;
use IBG\Outreach\Campaigns\Campaign;
use IBG\Outreach\Capabilities;
use IBG\Outreach\Queue\Cron;

defined( 'ABSPATH' ) || exit;

/**
 * Class Queue_Page
 */
final class Queue_Page extends Abstract_Page {

	public const SLUG = 'ibg-outreach-queue';

	private const NONCE_RUN = 'ibg_run_queue';

	/**
	 * Table.
	 *
	 * @var Queue_List_Table|null
	 */
	private ?Queue_List_Table $table = null;

	/** @inheritDoc */
	public function get_slug(): string {
		return self::SLUG;
	}

	/** @inheritDoc */
	public function get_page_title(): string {
		return __( 'Email Queue', 'ibg-client-outreach' );
	}

	/** @inheritDoc */
	public function get_menu_title(): string {
		return __( 'Email Queue', 'ibg-client-outreach' );
	}

	/** @inheritDoc */
	public function get_capability(): string {
		return Capabilities::VIEW;
	}

	/** @inheritDoc */
	public function get_position(): int {
		return 70;
	}

	/** @inheritDoc */
	public function register_hooks(): void {
		add_filter(
			'set_screen_option_' . Queue_List_Table::PER_PAGE_OPTION,
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
				'label'   => __( 'Queue rows per page', 'ibg-client-outreach' ),
				'default' => 50,
				'option'  => Queue_List_Table::PER_PAGE_OPTION,
			)
		);

		$this->table = new Queue_List_Table(
			$this->plugin->get( 'queue' ),
			$this->campaign_names(),
			$this,
			current_user_can( Capabilities::SEND_CAMPAIGNS )
		);

		$this->handle_run_now();
		$this->handle_bulk_action();
		$this->redirect_clean_filter_form();
	}

	/** @inheritDoc */
	public function render(): void {
		$this->require_capability();
		$this->table->prepare_items();

		$this->render_view(
			'queue',
			array(
				'page'     => $this,
				'table'    => $this->table,
				'counts'   => $this->plugin->get( 'queue' )->count_by_status(),
				'cron'     => Cron::get_status(),
				'can_run'  => current_user_can( Capabilities::SEND_CAMPAIGNS ),
				'run_url'  => wp_nonce_url( $this->get_url( array( 'action' => 'run_now' ) ), self::NONCE_RUN ),
				'settings' => $this->plugin->get( 'settings' ),
			)
		);
	}

	/**
	 * Campaign id => name (all statuses).
	 *
	 * @return array<int, string>
	 */
	private function campaign_names(): array {
		$names = array();
		$items = $this->plugin->get( 'campaigns' )->query( array( 'per_page' => 500, 'orderby' => 'created_at' ) )['items'];
		foreach ( $items as $campaign ) {
			/** @var Campaign $campaign */
			$names[ $campaign->id ] = $campaign->name;
		}
		return $names;
	}

	/**
	 * "Run queue now" button.
	 *
	 * @return void
	 */
	private function handle_run_now(): void {
		if ( ! isset( $_GET['action'] ) || 'run_now' !== $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$this->require_capability( Capabilities::SEND_CAMPAIGNS );
		check_admin_referer( self::NONCE_RUN );

		$this->plugin->get( 'cron' )->dispatch_scheduled();
		$stats = $this->plugin->get( 'queue_worker' )->run( true );

		$this->notices->add(
			sprintf(
				/* translators: 1: claimed, 2: sent, 3: failed, 4: retried, 5: skipped, 6: seconds */
				__( 'Queue run finished: %1$d claimed, %2$d sent, %3$d failed, %4$d scheduled for retry, %5$d skipped (%6$ss).', 'ibg-client-outreach' ),
				(int) $stats['claimed'],
				(int) $stats['sent'],
				(int) $stats['failed'],
				(int) $stats['retried'],
				(int) $stats['skipped'],
				(string) $stats['duration']
			),
			(int) $stats['failed'] > 0 ? 'warning' : 'success'
		);

		wp_safe_redirect( $this->get_url( $this->table->get_filter_args() ) );
		exit;
	}

	/**
	 * Bulk retry / skip / delete.
	 *
	 * @return void
	 */
	private function handle_bulk_action(): void {
		$action = $this->table->current_action();
		if ( ! $action ) {
			return;
		}

		$this->require_capability( Capabilities::SEND_CAMPAIGNS );
		check_admin_referer( 'bulk-queue_items' );

		$ids   = isset( $_REQUEST['queue_item'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['queue_item'] ) ) : array();
		$queue = $this->plugin->get( 'queue' );

		switch ( $action ) {
			case 'retry':
				$count = $queue->retry( $ids );
				/* translators: %d: number */
				$this->notices->add( sprintf( _n( '%d email queued for retry.', '%d emails queued for retry.', $count, 'ibg-client-outreach' ), $count ), 'success' );
				break;
			case 'skip':
				$count = $queue->skip( $ids );
				/* translators: %d: number */
				$this->notices->add( sprintf( _n( '%d email skipped.', '%d emails skipped.', $count, 'ibg-client-outreach' ), $count ), 'success' );
				break;
			case 'delete':
				$count = $queue->delete_many( $ids );
				/* translators: %d: number */
				$this->notices->add( sprintf( _n( '%d queue row deleted.', '%d queue rows deleted.', $count, 'ibg-client-outreach' ), $count ), 'success' );
				break;
		}

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
