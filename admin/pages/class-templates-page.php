<?php
/**
 * Email Templates page: list, add/edit, preview, duplicate, test send.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Admin\Pages;

use IBG\Outreach\Admin\Tables\Templates_List_Table;
use IBG\Outreach\Capabilities;
use IBG\Outreach\Contacts\Contact;
use IBG\Outreach\Email\Email_Composer;
use IBG\Outreach\Email\Merge_Context;
use IBG\Outreach\Email\Merge_Tags;
use IBG\Outreach\Email\Provider_Registry;
use IBG\Outreach\Templates\Email_Template;
use IBG\Outreach\Templates\Template_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Class Templates_Page
 */
final class Templates_Page extends Abstract_Page {

	public const SLUG = 'ibg-outreach-templates';

	private const NONCE_SAVE     = 'ibg_save_template';
	private const NONCE_ACTION   = 'ibg_template_action_';
	private const NONCE_TEST     = 'ibg_template_test';
	private const NONCE_PREVIEW  = 'ibg_template_preview';
	private const PREVIEW_ACTION = 'ibg_outreach_template_preview';

	/**
	 * List table.
	 *
	 * @var Templates_List_Table|null
	 */
	private ?Templates_List_Table $table = null;

	/**
	 * Save error.
	 *
	 * @var \WP_Error|null
	 */
	private ?\WP_Error $form_error = null;

	/**
	 * Submitted values from a failed save.
	 *
	 * @var array<string, mixed>
	 */
	private array $form_data = array();

	/** @inheritDoc */
	public function get_slug(): string {
		return self::SLUG;
	}

	/** @inheritDoc */
	public function get_page_title(): string {
		return __( 'Email Templates', 'ibg-client-outreach' );
	}

	/** @inheritDoc */
	public function get_menu_title(): string {
		return __( 'Email Templates', 'ibg-client-outreach' );
	}

	/** @inheritDoc */
	public function get_capability(): string {
		return Capabilities::VIEW;
	}

	/** @inheritDoc */
	public function get_position(): int {
		return 60;
	}

	/** @inheritDoc */
	public function register_hooks(): void {
		add_action( 'admin_post_' . self::PREVIEW_ACTION, array( $this, 'render_preview' ) );
		add_filter(
			'set_screen_option_' . Templates_List_Table::PER_PAGE_OPTION,
			static fn( $status, $option, $value ): int => max( 1, min( 200, (int) $value ) ),
			10,
			3
		);
	}

	/** @inheritDoc */
	public function enqueue_assets(): void {
		if ( 'list' === $this->current_view() ) {
			return;
		}
		wp_enqueue_script(
			'ibg-outreach-templates',
			IBG_OUTREACH_URL . 'admin/js/templates.js',
			array( 'ibg-outreach-admin' ),
			IBG_OUTREACH_VERSION,
			true
		);
		wp_localize_script(
			'ibg-outreach-templates',
			'ibgOutreachTemplates',
			array(
				'i18n' => array(
					'showHtml' => __( 'Show HTML', 'ibg-client-outreach' ),
					'showText' => __( 'Show plain text', 'ibg-client-outreach' ),
				),
			)
		);
	}

	/**
	 * Nonce-protected action URL (duplicate / delete).
	 *
	 * @param string $action Action.
	 * @param int    $id     Template id.
	 * @return string
	 */
	public function get_action_url( string $action, int $id ): string {
		return wp_nonce_url(
			$this->get_url(
				array(
					'action' => $action,
					'id'     => $id,
				)
			),
			self::NONCE_ACTION . $action . '_' . $id
		);
	}

	/**
	 * Preview URL (admin-post endpoint, full HTML document).
	 *
	 * @param int $id         Template id.
	 * @param int $contact_id Sample contact id (0 = synthetic sample).
	 * @return string
	 */
	public function get_preview_url( int $id, int $contact_id = 0 ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => self::PREVIEW_ACTION,
					'id'      => $id,
					'contact' => $contact_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::NONCE_PREVIEW
		);
	}

	/** @inheritDoc */
	public function load(): void {
		$this->require_capability();

		$view = $this->current_view();

		if ( 'list' === $view ) {
			add_screen_option(
				'per_page',
				array(
					'label'   => __( 'Templates per page', 'ibg-client-outreach' ),
					'default' => 20,
					'option'  => Templates_List_Table::PER_PAGE_OPTION,
				)
			);
			$this->table = new Templates_List_Table( $this->plugin->get( 'templates' ), $this, current_user_can( Capabilities::MANAGE_CAMPAIGNS ) );

			$this->handle_row_action();
			$this->handle_bulk_action();
			$this->handle_create_example();
			return;
		}

		$this->require_capability( Capabilities::MANAGE_CAMPAIGNS );
		$this->handle_test_send( $view );
		$this->handle_save( $view );
	}

	/** @inheritDoc */
	public function render(): void {
		$this->require_capability();

		$view = $this->current_view();

		if ( 'list' === $view ) {
			$this->table->prepare_items();
			$this->render_view(
				'templates-list',
				array(
					'page'        => $this,
					'table'       => $this->table,
					'can_manage'  => current_user_can( Capabilities::MANAGE_CAMPAIGNS ),
					'example_url' => wp_nonce_url( $this->get_url( array( 'action' => 'create_example' ) ), self::NONCE_ACTION . 'create_example_0' ),
					'has_any'     => $this->plugin->get( 'templates' )->counts()['all'] > 0,
				)
			);
			return;
		}

		$this->require_capability( Capabilities::MANAGE_CAMPAIGNS );

		$template = null;
		if ( 'edit' === $view ) {
			$template = $this->plugin->get( 'templates' )->find( $this->requested_id() );
			if ( ! $template ) {
				wp_die( esc_html__( 'Template not found.', 'ibg-client-outreach' ), 404 );
			}
		}

		$values = array(
			'name'      => $template ? $template->name : '',
			'subject'   => $template ? $template->subject : '',
			'body_html' => $template ? $template->body_html : '',
			'body_text' => $template ? $template->body_text : '',
			'is_active' => $template ? $template->is_active : true,
		);
		if ( ! empty( $this->form_data ) ) {
			$values = array_merge( $values, $this->form_data );
		}

		/** @var Merge_Tags $tags */
		$tags = $this->plugin->get( 'merge_tags' );

		$sample_contacts = $this->plugin->get( 'contacts' )->query(
			array(
				'per_page' => 25,
				'orderby'  => 'updated_at',
			)
		)['items'];

		$this->render_view(
			'template-form',
			array(
				'page'            => $this,
				'template'        => $template,
				'values'          => $values,
				'error'           => $this->form_error,
				'nonce'           => self::NONCE_SAVE,
				'test_nonce'      => self::NONCE_TEST,
				'tag_groups'      => $tags->get_groups(),
				'sample_contacts' => $sample_contacts,
				'test_email'      => wp_get_current_user()->user_email,
				'can_send'        => current_user_can( Capabilities::SEND_CAMPAIGNS ),
				'list_url'        => $this->get_url(),
				'preview_url'     => $template ? $this->get_preview_url( $template->id ) : '',
			)
		);
	}

	/**
	 * admin-post: output a rendered preview as a standalone HTML document.
	 *
	 * @return void
	 */
	public function render_preview(): void {
		check_admin_referer( self::NONCE_PREVIEW );

		if ( ! current_user_can( Capabilities::VIEW ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'ibg-client-outreach' ), 403 );
		}

		$id         = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		$contact_id = isset( $_GET['contact'] ) ? absint( wp_unslash( $_GET['contact'] ) ) : 0;
		$format     = isset( $_GET['format'] ) && 'text' === $_GET['format'] ? 'text' : 'html';

		$template = $this->plugin->get( 'templates' )->find( $id );
		if ( ! $template ) {
			wp_die( esc_html__( 'Template not found.', 'ibg-client-outreach' ), 404 );
		}

		$message = $this->plugin->get( 'composer' )->compose(
			array(
				'to_email'  => 'preview@example.com',
				'subject'   => $template->subject,
				'body_html' => $template->body_html,
				'body_text' => $template->body_text,
				'context'   => $this->build_context( $contact_id ),
				'is_test'   => true,
			)
		);

		nocache_headers();
		header( 'X-Frame-Options: SAMEORIGIN' );
		// Belt and braces over wp_kses: no scripts, no external fetches except images, no form posts.
		header( "Content-Security-Policy: default-src 'none'; img-src * data:; style-src 'unsafe-inline'; font-src *; form-action 'none'; base-uri 'none'" );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );

		if ( 'text' === $format ) {
			header( 'Content-Type: text/plain; charset=' . get_option( 'blog_charset' ) );
			echo $message->get_text_body(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/plain response.
			exit;
		}

		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		// The HTML was sanitised by wp_kses on save and merge values are escaped by Merge_Tags.
		echo $message->get_html_body(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Current sub-view.
	 *
	 * @return string
	 */
	private function current_view(): string {
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $view, array( 'list', 'add', 'edit' ), true ) ? $view : 'list';
	}

	/**
	 * Requested id.
	 *
	 * @return int
	 */
	private function requested_id(): int {
		return isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Merge context for previews/tests: a real contact or the synthetic sample.
	 *
	 * @param int $contact_id Contact id or 0.
	 * @return Merge_Context
	 */
	private function build_context( int $contact_id ): Merge_Context {
		if ( $contact_id > 0 ) {
			$contact = $this->plugin->get( 'contacts' )->find( $contact_id );
			if ( $contact instanceof Contact ) {
				return new Merge_Context( $contact, 0, 0, true );
			}
		}
		return Merge_Context::sample();
	}

	/**
	 * Save handler.
	 *
	 * @param string $view add|edit.
	 * @return void
	 */
	private function handle_save( string $view ): void {
		if ( ! isset( $_POST['ibg_action'] ) || 'save_template' !== $_POST['ibg_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		check_admin_referer( self::NONCE_SAVE );

		$raw  = isset( $_POST['template'] ) && is_array( $_POST['template'] ) ? wp_unslash( $_POST['template'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by Template_Service.
		$data = array(
			'name'            => (string) ( $raw['name'] ?? '' ),
			'subject'         => (string) ( $raw['subject'] ?? '' ),
			'body_html'       => (string) ( $raw['body_html'] ?? '' ),
			'body_text'       => (string) ( $raw['body_text'] ?? '' ),
			'is_active'       => ! empty( $raw['is_active'] ),
			'regenerate_text' => ! empty( $raw['regenerate_text'] ),
		);

		/** @var Template_Service $service */
		$service = $this->plugin->get( 'template_service' );
		$result  = $service->save( $data, 'edit' === $view ? $this->requested_id() : 0 );

		if ( is_wp_error( $result ) ) {
			$this->form_error = $result;
			$this->form_data  = array(
				'name'      => sanitize_text_field( $data['name'] ),
				'subject'   => sanitize_text_field( $data['subject'] ),
				'body_html' => wp_kses( $data['body_html'], $service->allowed_html() ),
				'body_text' => sanitize_textarea_field( $data['body_text'] ),
				'is_active' => $data['is_active'],
			);
			return;
		}

		$this->notices->add(
			'edit' === $view ? __( 'Template updated.', 'ibg-client-outreach' ) : __( 'Template created.', 'ibg-client-outreach' ),
			'success'
		);
		foreach ( $service->take_warnings() as $warning ) {
			$this->notices->add( $warning, 'warning' );
		}

		wp_safe_redirect(
			$this->get_url(
				array(
					'view' => 'edit',
					'id'   => $result->id,
				)
			)
		);
		exit;
	}

	/**
	 * Send a test email from the edit screen.
	 *
	 * @param string $view add|edit.
	 * @return void
	 */
	private function handle_test_send( string $view ): void {
		if ( 'edit' !== $view || ! isset( $_POST['ibg_action'] ) || 'send_test' !== $_POST['ibg_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		check_admin_referer( self::NONCE_TEST );
		$this->require_capability( Capabilities::SEND_CAMPAIGNS );

		$template = $this->plugin->get( 'templates' )->find( $this->requested_id() );
		if ( ! $template ) {
			wp_die( esc_html__( 'Template not found.', 'ibg-client-outreach' ), 404 );
		}

		$to         = isset( $_POST['test_email'] ) ? sanitize_email( wp_unslash( $_POST['test_email'] ) ) : '';
		$contact_id = isset( $_POST['sample_contact'] ) ? absint( wp_unslash( $_POST['sample_contact'] ) ) : 0;
		$redirect   = $this->get_url(
			array(
				'view' => 'edit',
				'id'   => $template->id,
			)
		);

		if ( ! is_email( $to ) ) {
			$this->notices->add( __( 'Enter a valid email address for the test.', 'ibg-client-outreach' ), 'error' );
			wp_safe_redirect( $redirect );
			exit;
		}

		/** @var Email_Composer $composer */
		$composer = $this->plugin->get( 'composer' );
		/** @var Provider_Registry $providers */
		$providers = $this->plugin->get( 'providers' );

		$message = $composer->compose(
			array(
				'to_email'  => $to,
				'subject'   => $template->subject,
				'body_html' => $template->body_html,
				'body_text' => $template->body_text,
				'context'   => $this->build_context( $contact_id ),
				'is_test'   => true,
			)
		);

		$provider = $providers->get_active();
		$result   = $provider->send( $message );
		$this->plugin->get( 'logs' )->log_test( $message, $result, $provider->get_id() );

		if ( $result->is_success() ) {
			$this->notices->add(
				sprintf(
					/* translators: 1: email address, 2: provider name */
					__( 'Test email sent to %1$s via %2$s. The unsubscribe link in a test email opens an explanatory page and changes nothing.', 'ibg-client-outreach' ),
					$to,
					$provider->get_name()
				),
				'success'
			);
		} else {
			$this->notices->add(
				sprintf(
					/* translators: %s: error message */
					__( 'Test email failed: %s', 'ibg-client-outreach' ),
					$result->get_error()
				),
				'error'
			);
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Duplicate / delete row actions.
	 *
	 * @return void
	 */
	private function handle_row_action(): void {
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id     = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $action, array( 'duplicate', 'delete' ), true ) || $id <= 0 ) {
			return;
		}

		$this->require_capability( Capabilities::MANAGE_CAMPAIGNS );
		check_admin_referer( self::NONCE_ACTION . $action . '_' . $id );

		/** @var Template_Service $service */
		$service = $this->plugin->get( 'template_service' );

		if ( 'duplicate' === $action ) {
			$copy = $service->duplicate( $id );
			if ( is_wp_error( $copy ) ) {
				$this->notices->add( $copy->get_error_message(), 'error' );
				wp_safe_redirect( $this->get_url() );
			} else {
				$this->notices->add( __( 'Template duplicated (inactive copy).', 'ibg-client-outreach' ), 'success' );
				wp_safe_redirect(
					$this->get_url(
						array(
							'view' => 'edit',
							'id'   => $copy->id,
						)
					)
				);
			}
			exit;
		}

		$this->report_delete( $service->delete( array( $id ) ) );
		wp_safe_redirect( $this->get_url( $this->table->get_filter_args() ) );
		exit;
	}

	/**
	 * Bulk actions.
	 *
	 * @return void
	 */
	private function handle_bulk_action(): void {
		$action = $this->table->current_action();
		if ( ! $action ) {
			return;
		}

		$this->require_capability( Capabilities::MANAGE_CAMPAIGNS );
		check_admin_referer( 'bulk-templates' );

		$ids = isset( $_REQUEST['template'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['template'] ) ) : array();

		if ( 'delete' === $action ) {
			$this->report_delete( $this->plugin->get( 'template_service' )->delete( $ids ) );
		} elseif ( 'activate' === $action || 'deactivate' === $action ) {
			$count = $this->plugin->get( 'templates' )->set_active( $ids, 'activate' === $action );
			/* translators: %d: number of templates */
			$this->notices->add( sprintf( _n( '%d template updated.', '%d templates updated.', $count, 'ibg-client-outreach' ), $count ), 'success' );
		}

		wp_safe_redirect( $this->get_url( $this->table->get_filter_args() ) );
		exit;
	}

	/**
	 * Create the example template from the empty state.
	 *
	 * @return void
	 */
	private function handle_create_example(): void {
		if ( ! isset( $_GET['action'] ) || 'create_example' !== $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$this->require_capability( Capabilities::MANAGE_CAMPAIGNS );
		check_admin_referer( self::NONCE_ACTION . 'create_example_0' );

		/** @var Template_Service $service */
		$service = $this->plugin->get( 'template_service' );
		$result  = $service->save( $service->example_template_data() );

		if ( is_wp_error( $result ) ) {
			$this->notices->add( $result->get_error_message(), 'error' );
			wp_safe_redirect( $this->get_url() );
			exit;
		}

		$this->notices->add( __( 'Example template created. Edit it to match your voice.', 'ibg-client-outreach' ), 'success' );
		wp_safe_redirect(
			$this->get_url(
				array(
					'view' => 'edit',
					'id'   => $result->id,
				)
			)
		);
		exit;
	}

	/**
	 * Flash the outcome of a delete.
	 *
	 * @param array{deleted:int, blocked:int} $result Delete result.
	 * @return void
	 */
	private function report_delete( array $result ): void {
		if ( $result['deleted'] > 0 ) {
			/* translators: %d: number of templates */
			$this->notices->add( sprintf( _n( '%d template deleted.', '%d templates deleted.', $result['deleted'], 'ibg-client-outreach' ), $result['deleted'] ), 'success' );
		}
		if ( $result['blocked'] > 0 ) {
			/* translators: %d: number of templates */
			$this->notices->add( sprintf( _n( '%d template is used by a campaign and was not deleted. Deactivate it instead.', '%d templates are used by campaigns and were not deleted. Deactivate them instead.', $result['blocked'], 'ibg-client-outreach' ), $result['blocked'] ), 'warning' );
		}
	}
}
