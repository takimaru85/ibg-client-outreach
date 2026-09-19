<?php
/**
 * Base class for admin pages.
 *
 * A page is a small controller: load() runs on the load-{hook} action before
 * any output (handle form posts, redirect), render() outputs the view.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Admin\Pages;

use IBG\Outreach\Admin\Notices;
use IBG\Outreach\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Class Abstract_Page
 */
abstract class Abstract_Page {

	/**
	 * Container.
	 *
	 * @var Plugin
	 */
	protected Plugin $plugin;

	/**
	 * Notices.
	 *
	 * @var Notices
	 */
	protected Notices $notices;

	/**
	 * Constructor.
	 *
	 * @param Plugin  $plugin  Container.
	 * @param Notices $notices Notices.
	 */
	public function __construct( Plugin $plugin, Notices $notices ) {
		$this->plugin  = $plugin;
		$this->notices = $notices;
	}

	/**
	 * Menu slug (unique).
	 *
	 * @return string
	 */
	abstract public function get_slug(): string;

	/**
	 * Browser/page title.
	 *
	 * @return string
	 */
	abstract public function get_page_title(): string;

	/**
	 * Menu label.
	 *
	 * @return string
	 */
	abstract public function get_menu_title(): string;

	/**
	 * Capability required to view the page.
	 *
	 * @return string
	 */
	abstract public function get_capability(): string;

	/**
	 * Output the page.
	 *
	 * @return void
	 */
	abstract public function render(): void;

	/**
	 * Menu ordering (lower first).
	 *
	 * @return int
	 */
	public function get_position(): int {
		return 50;
	}

	/**
	 * Register hooks that must exist regardless of the current screen
	 * (e.g. admin_init for the Settings API, admin_post handlers).
	 *
	 * @return void
	 */
	public function register_hooks(): void {}

	/**
	 * Runs on load-{hook}, before headers are sent. Handle actions here.
	 *
	 * @return void
	 */
	public function load(): void {}

	/**
	 * Enqueue page-specific scripts/styles. Runs only on this page.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {}

	/**
	 * URL of this page.
	 *
	 * @param array<string, mixed> $args Extra query args.
	 * @return string
	 */
	public function get_url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => $this->get_slug() ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Die unless the current user holds the page capability.
	 *
	 * @param string|null $capability Capability to check; defaults to the page capability.
	 * @return void
	 */
	protected function require_capability( ?string $capability = null ): void {
		if ( ! current_user_can( $capability ?? $this->get_capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'ibg-client-outreach' ), 403 );
		}
	}

	/**
	 * Include a view template from admin/views.
	 *
	 * @param string               $view View name without extension.
	 * @param array<string, mixed> $data Data available to the view as $data.
	 * @return void
	 */
	protected function render_view( string $view, array $data = array() ): void {
		$file = IBG_OUTREACH_PATH . 'admin/views/' . sanitize_file_name( $view ) . '.php';

		if ( ! is_readable( $file ) ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'View template not found.', 'ibg-client-outreach' ) );
			return;
		}

		include $file;
	}
}
