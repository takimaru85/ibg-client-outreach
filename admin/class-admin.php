<?php
/**
 * Admin bootstrap: pages, assets, plugin links, global notices.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Admin;

use IBG\Outreach\Activator;
use IBG\Outreach\Admin\Pages\Campaigns_Page;
use IBG\Outreach\Admin\Pages\Contacts_Page;
use IBG\Outreach\Admin\Pages\Dashboard_Page;
use IBG\Outreach\Admin\Pages\Import_Page;
use IBG\Outreach\Admin\Pages\Lists_Page;
use IBG\Outreach\Admin\Pages\Logs_Page;
use IBG\Outreach\Admin\Pages\Queue_Page;
use IBG\Outreach\Admin\Pages\Settings_Page;
use IBG\Outreach\Admin\Pages\Suppressions_Page;
use IBG\Outreach\Admin\Pages\Templates_Page;
use IBG\Outreach\Capabilities;
use IBG\Outreach\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin
 */
final class Admin {

	/**
	 * Container.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Menu / page registry.
	 *
	 * @var Menu
	 */
	private Menu $menu;

	/**
	 * Notices.
	 *
	 * @var Notices
	 */
	private Notices $notices;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin  = $plugin;
		$this->notices = new Notices();
		$this->menu    = new Menu();
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->menu->add_page( new Dashboard_Page( $this->plugin, $this->notices ) );
		$this->menu->add_page( new Contacts_Page( $this->plugin, $this->notices ) );
		$this->menu->add_page( new Import_Page( $this->plugin, $this->notices ) );
		$this->menu->add_page( new Lists_Page( $this->plugin, $this->notices ) );
		$this->menu->add_page( new Suppressions_Page( $this->plugin, $this->notices ) );
		$this->menu->add_page( new Campaigns_Page( $this->plugin, $this->notices ) );
		$this->menu->add_page( new Templates_Page( $this->plugin, $this->notices ) );
		$this->menu->add_page( new Queue_Page( $this->plugin, $this->notices ) );
		$this->menu->add_page( new Logs_Page( $this->plugin, $this->notices ) );
		$this->menu->add_page( new Settings_Page( $this->plugin, $this->notices ) );

		/**
		 * Register additional admin pages.
		 *
		 * @param Menu    $menu    Page registry.
		 * @param Plugin  $plugin  Container.
		 * @param Notices $notices Notices service.
		 */
		do_action( 'ibg_outreach_register_admin_pages', $this->menu, $this->plugin, $this->notices );

		$this->menu->init();
		$this->notices->init();

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'welcome_notice' ) );
		add_filter( 'plugin_action_links_' . IBG_OUTREACH_BASENAME, array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Menu / page registry.
	 *
	 * @return Menu
	 */
	public function menu(): Menu {
		return $this->menu;
	}

	/**
	 * Notices service.
	 *
	 * @return Notices
	 */
	public function notices(): Notices {
		return $this->notices;
	}

	/**
	 * Enqueue admin assets on plugin screens only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! $this->menu->is_plugin_hook( $hook_suffix ) ) {
			return;
		}

		wp_enqueue_style(
			'ibg-outreach-admin',
			IBG_OUTREACH_URL . 'admin/css/admin.css',
			array(),
			IBG_OUTREACH_VERSION
		);

		wp_enqueue_script(
			'ibg-outreach-admin',
			IBG_OUTREACH_URL . 'admin/js/admin.js',
			array(),
			IBG_OUTREACH_VERSION,
			true
		);

		wp_localize_script(
			'ibg-outreach-admin',
			'ibgOutreach',
			array(
				'i18n' => array(
					'confirm' => __( 'Are you sure?', 'ibg-client-outreach' ),
				),
			)
		);

		$page = $this->menu->get_page_by_hook( $hook_suffix );
		if ( $page ) {
			$page->enqueue_assets();
		}
	}

	/**
	 * One-time notice after activation.
	 *
	 * @return void
	 */
	public function welcome_notice(): void {
		if ( ! get_transient( Activator::TRANSIENT_WELCOME ) || ! current_user_can( Capabilities::VIEW ) ) {
			return;
		}
		delete_transient( Activator::TRANSIENT_WELCOME );

		$settings_url = $this->menu->get_page_url( Settings_Page::SLUG );
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'IBG Client Outreach is active. Before sending anything, add your business address and sender details.', 'ibg-client-outreach' ),
			esc_url( $settings_url ),
			esc_html__( 'Open Settings', 'ibg-client-outreach' )
		);
	}

	/**
	 * Add a Settings link on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function plugin_action_links( array $links ): array {
		if ( current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			array_unshift(
				$links,
				sprintf(
					'<a href="%s">%s</a>',
					esc_url( $this->menu->get_page_url( Settings_Page::SLUG ) ),
					esc_html__( 'Settings', 'ibg-client-outreach' )
				)
			);
		}
		return $links;
	}
}
