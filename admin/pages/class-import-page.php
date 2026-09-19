<?php
/**
 * Import Contacts page: upload → map → preview/run wizard.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Admin\Pages;

use IBG\Outreach\Capabilities;
use IBG\Outreach\Contacts\Contact;
use IBG\Outreach\Import\Contact_Importer;
use IBG\Outreach\Import\CSV_Reader;
use IBG\Outreach\Import\Import_Session;
use IBG\Outreach\Import\Import_Storage;
use IBG\Outreach\Lists\Contact_List;

defined( 'ABSPATH' ) || exit;

/**
 * Class Import_Page
 */
final class Import_Page extends Abstract_Page {

	public const SLUG = 'ibg-outreach-import';

	private const NONCE_UPLOAD = 'ibg_import_upload';
	private const NONCE_MAP    = 'ibg_import_map';
	private const NONCE_CANCEL = 'ibg_import_cancel';
	private const NONCE_AJAX   = 'ibg_import_batch';
	private const AJAX_ACTION  = 'ibg_outreach_import_batch';

	/** @inheritDoc */
	public function get_slug(): string {
		return self::SLUG;
	}

	/** @inheritDoc */
	public function get_page_title(): string {
		return __( 'Import Contacts', 'ibg-client-outreach' );
	}

	/** @inheritDoc */
	public function get_menu_title(): string {
		return __( 'Import Contacts', 'ibg-client-outreach' );
	}

	/** @inheritDoc */
	public function get_capability(): string {
		return Capabilities::IMPORT_CONTACTS;
	}

	/** @inheritDoc */
	public function get_position(): int {
		return 30;
	}

	/** @inheritDoc */
	public function register_hooks(): void {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_batch' ) );
	}

	/** @inheritDoc */
	public function enqueue_assets(): void {
		wp_enqueue_script(
			'ibg-outreach-import',
			IBG_OUTREACH_URL . 'admin/js/import.js',
			array( 'ibg-outreach-admin' ),
			IBG_OUTREACH_VERSION,
			true
		);

		wp_localize_script(
			'ibg-outreach-import',
			'ibgOutreachImport',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_ACTION,
				'nonce'   => wp_create_nonce( self::NONCE_AJAX ),
				'token'   => $this->requested_token(),
				'i18n'    => array(
					'importing' => __( 'Importing… %1$s of %2$s rows', 'ibg-client-outreach' ),
					'done'      => __( 'Import complete.', 'ibg-client-outreach' ),
					'error'     => __( 'The import stopped because of an error. Reload the page to resume.', 'ibg-client-outreach' ),
					'leave'     => __( 'An import is running. Leaving this page will pause it.', 'ibg-client-outreach' ),
				),
			)
		);
	}

	/** @inheritDoc */
	public function load(): void {
		$this->require_capability();

		$action = isset( $_POST['ibg_action'] ) ? sanitize_key( wp_unslash( $_POST['ibg_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- each handler verifies its own nonce.

		switch ( $action ) {
			case 'upload':
				$this->handle_upload();
				break;
			case 'map':
				$this->handle_map();
				break;
			case 'cancel':
				$this->handle_cancel();
				break;
		}
	}

	/** @inheritDoc */
	public function render(): void {
		$this->require_capability();

		$session = $this->current_session();

		if ( ! $session ) {
			$this->render_view(
				'import-upload',
				array(
					'page'       => $this,
					'nonce'      => self::NONCE_UPLOAD,
					'max_size'   => Import_Storage::max_size(),
					'strategies' => Contact_Importer::strategies(),
					'lists'      => $this->plugin->get( 'lists' )->all( Contact_List::TYPE_STATIC ),
					'lists_url'  => add_query_arg( 'page', Lists_Page::SLUG, admin_url( 'admin.php' ) ),
				)
			);
			return;
		}

		if ( Import_Session::STEP_MAP === $session->step ) {
			$this->render_view(
				'import-map',
				array(
					'page'    => $this,
					'session' => $session,
					'nonce'   => self::NONCE_MAP,
					'cancel'  => self::NONCE_CANCEL,
					'fields'  => Contact_Importer::mappable_fields(),
				)
			);
			return;
		}

		$this->render_view(
			'import-preview',
			array(
				'page'         => $this,
				'session'      => $session,
				'cancel'       => self::NONCE_CANCEL,
				'strategies'   => Contact_Importer::strategies(),
				'contacts_url' => add_query_arg( 'page', Contacts_Page::SLUG, admin_url( 'admin.php' ) ),
			)
		);
	}

	/**
	 * AJAX: process one batch.
	 *
	 * @return void
	 */
	public function ajax_batch(): void {
		check_ajax_referer( self::NONCE_AJAX, 'nonce' );

		if ( ! current_user_can( Capabilities::IMPORT_CONTACTS ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'ibg-client-outreach' ) ), 403 );
		}

		$token   = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$session = Import_Session::load( $token );

		if ( ! $session || ! $session->is_owned_by_current_user() || Import_Session::STEP_PREVIEW !== $session->step ) {
			wp_send_json_error( array( 'message' => __( 'Import session not found or expired.', 'ibg-client-outreach' ) ), 404 );
		}

		if ( ! $session->is_done() && ! is_readable( $session->file_path ) ) {
			wp_send_json_error( array( 'message' => __( 'The uploaded file is no longer available.', 'ibg-client-outreach' ) ), 410 );
		}

		/**
		 * Filter the number of CSV rows imported per AJAX request.
		 *
		 * @param int $batch_size Default 100.
		 */
		$batch_size = max( 1, (int) apply_filters( 'ibg_outreach_import_batch_size', 100 ) );

		$progress = $this->plugin->get( 'importer' )->import_batch( $session, $batch_size );

		wp_send_json_success(
			array(
				'progress' => $progress,
				'total'    => (int) ( $session->analysis['total'] ?? 0 ),
			)
		);
	}

	/**
	 * Step 1: validate the upload, store the file, guess the mapping.
	 *
	 * @return void
	 */
	private function handle_upload(): void {
		check_admin_referer( self::NONCE_UPLOAD );

		$options = $this->read_options();
		if ( is_wp_error( $options ) ) {
			$this->fail( $options->get_error_message() );
		}

		$session = Import_Session::create( get_current_user_id() );

		$file = isset( $_FILES['ibg_csv'] ) && is_array( $_FILES['ibg_csv'] ) ? $_FILES['ibg_csv'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated in Import_Storage.
		$path = Import_Storage::store_upload( $file, $session->token );
		if ( is_wp_error( $path ) ) {
			$this->fail( $path->get_error_message() );
		}

		$delimiter = CSV_Reader::detect_delimiter( $path );
		$reader    = new CSV_Reader( $path, $delimiter );
		$headers   = $reader->get_headers();

		if ( empty( $headers ) ) {
			Import_Storage::delete( $path );
			$this->fail( __( 'The file has no header row.', 'ibg-client-outreach' ) );
		}

		$session->step          = Import_Session::STEP_MAP;
		$session->file_path     = $path;
		$session->original_name = sanitize_file_name( (string) ( $file['name'] ?? 'import.csv' ) );
		$session->delimiter     = $delimiter;
		$session->headers       = $headers;
		$session->samples       = $reader->read( 0, 3 );
		$session->mapping       = Contact_Importer::guess_mapping( $headers );
		$session->options       = $options;
		$session->save();

		wp_safe_redirect( $this->get_url( array( 'token' => $session->token ) ) );
		exit;
	}

	/**
	 * Step 2: store the mapping and run the analysis.
	 *
	 * @return void
	 */
	private function handle_map(): void {
		check_admin_referer( self::NONCE_MAP );

		$session = $this->current_session();
		if ( ! $session ) {
			$this->fail( __( 'Import session not found or expired. Please upload the file again.', 'ibg-client-outreach' ) );
		}

		$raw     = isset( $_POST['mapping'] ) && is_array( $_POST['mapping'] ) ? wp_unslash( $_POST['mapping'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.
		$allowed = array_keys( Contact_Importer::mappable_fields() );
		$mapping = array();
		$used    = array();

		foreach ( array_keys( $session->headers ) as $index ) {
			$field = isset( $raw[ $index ] ) ? sanitize_key( (string) $raw[ $index ] ) : '';
			if ( '' === $field || ! in_array( $field, $allowed, true ) ) {
				$mapping[ $index ] = '';
				continue;
			}
			if ( isset( $used[ $field ] ) ) {
				$this->fail(
					sprintf(
						/* translators: %s: field label */
						__( 'The field "%s" is mapped to more than one column.', 'ibg-client-outreach' ),
						Contact_Importer::mappable_fields()[ $field ]
					),
					$session->token
				);
			}
			$mapping[ $index ] = $field;
			$used[ $field ]    = true;
		}

		if ( ! isset( $used['email'] ) ) {
			$this->fail( __( 'You must map a column to Email.', 'ibg-client-outreach' ), $session->token );
		}

		$session->mapping  = $mapping;
		$session->analysis = $this->plugin->get( 'importer' )->analyze( $session );
		$session->step     = Import_Session::STEP_PREVIEW;
		$session->progress = Import_Session::empty_progress();
		$session->save();

		wp_safe_redirect( $this->get_url( array( 'token' => $session->token ) ) );
		exit;
	}

	/**
	 * Cancel: delete the session and file.
	 *
	 * @return void
	 */
	private function handle_cancel(): void {
		check_admin_referer( self::NONCE_CANCEL );

		$session = $this->current_session();
		if ( $session ) {
			$session->destroy();
		}

		$this->notices->add( __( 'Import cancelled. The uploaded file was deleted.', 'ibg-client-outreach' ), 'info' );
		wp_safe_redirect( $this->get_url() );
		exit;
	}

	/**
	 * Read and validate import-wide options from the upload form.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function read_options(): array|\WP_Error {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by caller.
		$contact_status   = isset( $_POST['contact_status'] ) ? sanitize_key( wp_unslash( $_POST['contact_status'] ) ) : Contact::STATUS_LEAD;
		$marketing_status = isset( $_POST['marketing_status'] ) ? sanitize_key( wp_unslash( $_POST['marketing_status'] ) ) : Contact::MARKETING_PENDING;
		$consent_basis    = isset( $_POST['consent_basis'] ) ? sanitize_key( wp_unslash( $_POST['consent_basis'] ) ) : '';
		$source           = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : '';
		$strategy         = isset( $_POST['duplicate_strategy'] ) ? sanitize_key( wp_unslash( $_POST['duplicate_strategy'] ) ) : Contact_Importer::STRATEGY_SKIP;
		$confirm          = ! empty( $_POST['confirm_consent'] );
		$list_id          = isset( $_POST['list_id'] ) ? absint( wp_unslash( $_POST['list_id'] ) ) : 0;
		// phpcs:enable

		if ( $list_id > 0 ) {
			$list = $this->plugin->get( 'lists' )->find( $list_id );
			if ( ! $list || $list->is_segment() ) {
				return new \WP_Error( 'invalid', __( 'Invalid list.', 'ibg-client-outreach' ) );
			}
		}

		if ( ! array_key_exists( $contact_status, Contact::contact_statuses() ) ) {
			return new \WP_Error( 'invalid', __( 'Invalid contact status.', 'ibg-client-outreach' ) );
		}
		if ( ! in_array( $marketing_status, array( Contact::MARKETING_PENDING, Contact::MARKETING_SUBSCRIBED ), true ) ) {
			return new \WP_Error( 'invalid', __( 'Invalid marketing status.', 'ibg-client-outreach' ) );
		}
		if ( Contact::MARKETING_SUBSCRIBED === $marketing_status && ! $confirm ) {
			return new \WP_Error( 'confirm', __( 'To import contacts as Subscribed you must confirm that you hold explicit opt-in records for every address in the file.', 'ibg-client-outreach' ) );
		}
		if ( ! array_key_exists( $consent_basis, Contact::consent_bases() ) ) {
			return new \WP_Error( 'invalid', __( 'Invalid lawful basis.', 'ibg-client-outreach' ) );
		}
		if ( ! array_key_exists( $strategy, Contact_Importer::strategies() ) ) {
			return new \WP_Error( 'invalid', __( 'Invalid duplicate strategy.', 'ibg-client-outreach' ) );
		}

		return array(
			'contact_status'     => $contact_status,
			'marketing_status'   => $marketing_status,
			'consent_basis'      => $consent_basis,
			'source'             => mb_substr( $source, 0, Contact::MAX_LENGTHS['source'] ),
			'duplicate_strategy' => $strategy,
			'list_id'            => $list_id,
		);
	}

	/**
	 * Token from the query string.
	 *
	 * @return string
	 */
	private function requested_token(): string {
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return preg_match( '/^[a-f0-9]{32}$/', $token ) ? $token : '';
	}

	/**
	 * The session for the current request, if valid and owned by this user.
	 *
	 * @return Import_Session|null
	 */
	private function current_session(): ?Import_Session {
		$token = $this->requested_token();
		if ( '' === $token ) {
			return null;
		}
		$session = Import_Session::load( $token );
		if ( ! $session || ! $session->is_owned_by_current_user() ) {
			return null;
		}
		return $session;
	}

	/**
	 * Flash an error and redirect (back to the given step or the upload form).
	 *
	 * @param string $message Message.
	 * @param string $token   Session token to return to.
	 * @return never
	 */
	private function fail( string $message, string $token = '' ): never {
		$this->notices->add( $message, 'error' );
		wp_safe_redirect( $this->get_url( '' !== $token ? array( 'token' => $token ) : array() ) );
		exit;
	}
}
