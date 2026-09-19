<?php
/**
 * Admin menu and page registry.
 *
 * Pages register themselves as objects; the menu is built from the registry
 * so later phases add a page by adding one class, not by editing this file.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Admin;

use IBG\Outreach\Admin\Pages\Abstract_Page;
use IBG\Outreach\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Class Menu
 */
final class Menu {

	public const PARENT_SLUG = 'ibg-outreach';

	/**
	 * Registered pages keyed by slug.
	 *
	 * @var array<string, Abstract_Page>
	 */
	private array $pages = array();

	/**
	 * Hook suffix => page, filled by register_menu().
	 *
	 * @var array<string, Abstract_Page>
	 */
	private array $hooks = array();

	/**
	 * Register a page.
	 *
	 * @param Abstract_Page $page Page.
	 * @return void
	 */
	public function add_page( Abstract_Page $page ): void {
		$this->pages[ $page->get_slug() ] = $page;
		$page->register_hooks();
	}

	/**
	 * Get a registered page.
	 *
	 * @param string $slug Page slug.
	 * @return Abstract_Page|null
	 */
	public function get_page( string $slug ): ?Abstract_Page {
		return $this->pages[ $slug ] ?? null;
	}

	/**
	 * All registered pages ordered by position.
	 *
	 * @return array<string, Abstract_Page>
	 */
	public function get_pages(): array {
		$pages = $this->pages;
		uasort( $pages, static fn( Abstract_Page $a, Abstract_Page $b ): int => $a->get_position() <=> $b->get_position() );
		return $pages;
	}

	/**
	 * Admin URL for a page.
	 *
	 * @param string               $slug Page slug.
	 * @param array<string, mixed> $args Extra query args.
	 * @return string
	 */
	public function get_page_url( string $slug, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => $slug ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Hook into admin_menu.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	/**
	 * Build the top-level menu and submenus.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$pages = $this->get_pages();
		if ( empty( $pages ) ) {
			return;
		}

		add_menu_page(
			__( 'IBG Client Outreach', 'ibg-client-outreach' ),
			__( 'IBG Outreach', 'ibg-client-outreach' ),
			Capabilities::VIEW,
			self::PARENT_SLUG,
			'__return_null',
			'dashicons-email-alt',
			26
		);

		foreach ( $pages as $page ) {
			$hook = add_submenu_page(
				self::PARENT_SLUG,
				$page->get_page_title(),
				$page->get_menu_title(),
				$page->get_capability(),
				$page->get_slug(),
				array( $page, 'render' )
			);

			if ( false === $hook ) {
				continue;
			}

			$this->hooks[ $hook ] = $page;
			add_action( 'load-' . $hook, array( $page, 'load' ) );
		}
	}

	/**
	 * Whether an admin hook suffix belongs to one of our pages.
	 *
	 * @param string $hook_suffix Hook suffix from admin_enqueue_scripts.
	 * @return bool
	 */
	public function is_plugin_hook( string $hook_suffix ): bool {
		return isset( $this->hooks[ $hook_suffix ] );
	}

	/**
	 * The page registered for an admin hook suffix.
	 *
	 * @param string $hook_suffix Hook suffix.
	 * @return Abstract_Page|null
	 */
	public function get_page_by_hook( string $hook_suffix ): ?Abstract_Page {
		return $this->hooks[ $hook_suffix ] ?? null;
	}
}
