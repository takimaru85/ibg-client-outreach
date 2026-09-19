<?php
/**
 * Contacts list table.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Admin\Tables;

use IBG\Outreach\Admin\Pages\Contacts_Page;
use IBG\Outreach\Contacts\Contact;
use IBG\Outreach\Contacts\Contact_Repository;
use IBG\Outreach\Formatting;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class Contacts_List_Table
 */
final class Contacts_List_Table extends \WP_List_Table {

	public const PER_PAGE_OPTION = 'ibg_contacts_per_page';

	/**
	 * Query-string keys that carry filters (preserved across actions).
	 *
	 * @var string[]
	 */
	public const FILTER_KEYS = array(
		's',
		'contact_status',
		'marketing_status',
		'industry',
		'country',
		'source',
		'date_from',
		'date_to',
		'orderby',
		'order',
		'paged',
	);

	/**
	 * Contact repository.
	 *
	 * @var Contact_Repository
	 */
	private Contact_Repository $repository;

	/**
	 * Owning page (for URLs).
	 *
	 * @var Contacts_Page
	 */
	private Contacts_Page $page;

	/**
	 * Whether the current user may edit/delete.
	 *
	 * @var bool
	 */
	private bool $can_manage;

	/**
	 * Constructor.
	 *
	 * @param Contact_Repository $repository Repository.
	 * @param Contacts_Page      $page       Page.
	 * @param bool               $can_manage Whether the user can manage contacts.
	 */
	public function __construct( Contact_Repository $repository, Contacts_Page $page, bool $can_manage ) {
		parent::__construct(
			array(
				'singular' => 'contact',
				'plural'   => 'contacts',
				'ajax'     => false,
				'screen'   => get_current_screen(),
			)
		);

		$this->repository = $repository;
		$this->page       = $page;
		$this->can_manage = $can_manage;
	}

	/**
	 * Current filter values from the query string.
	 *
	 * Filtering is a read-only GET operation; no nonce is required.
	 *
	 * @return array<string, string>
	 */
	public function get_filter_args(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$args = array();
		foreach ( self::FILTER_KEYS as $key ) {
			if ( ! isset( $_GET[ $key ] ) ) {
				continue;
			}
			$value = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			if ( '' !== $value ) {
				$args[ $key ] = $value;
			}
		}
		// phpcs:enable
		return $args;
	}

	/** @inheritDoc */
	public function get_columns(): array {
		$columns = array();
		if ( $this->can_manage ) {
			$columns['cb'] = '<input type="checkbox" />';
		}
		return $columns + array(
			'name'              => __( 'Name', 'ibg-client-outreach' ),
			'email'             => __( 'Email', 'ibg-client-outreach' ),
			'company'           => __( 'Company', 'ibg-client-outreach' ),
			'contact_status'    => __( 'Status', 'ibg-client-outreach' ),
			'marketing_status'  => __( 'Marketing', 'ibg-client-outreach' ),
			'industry'          => __( 'Industry', 'ibg-client-outreach' ),
			'country'           => __( 'Country', 'ibg-client-outreach' ),
			'source'            => __( 'Source', 'ibg-client-outreach' ),
			'last_contacted_at' => __( 'Last Contacted', 'ibg-client-outreach' ),
			'created_at'        => __( 'Added', 'ibg-client-outreach' ),
		);
	}

	/** @inheritDoc */
	protected function get_sortable_columns(): array {
		return array(
			'name'              => array( 'last_name', false ),
			'email'             => array( 'email', false ),
			'company'           => array( 'company', false ),
			'contact_status'    => array( 'contact_status', false ),
			'marketing_status'  => array( 'marketing_status', false ),
			'industry'          => array( 'industry', false ),
			'country'           => array( 'country', false ),
			'source'            => array( 'source', false ),
			'last_contacted_at' => array( 'last_contacted_at', false ),
			'created_at'        => array( 'created_at', true ),
		);
	}

	/** @inheritDoc */
	protected function get_bulk_actions(): array {
		if ( ! $this->can_manage ) {
			return array();
		}

		$actions = array();
		foreach ( Contact::contact_statuses() as $value => $label ) {
			/* translators: %s: contact status label */
			$actions[ 'status_' . $value ] = sprintf( __( 'Set status: %s', 'ibg-client-outreach' ), $label );
		}
		$actions['do_not_contact'] = __( 'Mark Do Not Contact', 'ibg-client-outreach' );
		$actions['delete']         = __( 'Delete', 'ibg-client-outreach' );

		return $actions;
	}

	/** @inheritDoc */
	protected function get_views(): array {
		$filters = $this->get_filter_args();
		$current = $filters['contact_status'] ?? '';

		unset( $filters['contact_status'], $filters['paged'] );
		$counts = $this->repository->count_by( 'contact_status', $this->to_query_args( $filters ) );
		$total  = array_sum( $counts );

		$views = array();

		$views['all'] = sprintf(
			'<a href="%s" %s>%s <span class="count">(%s)</span></a>',
			esc_url( $this->page->get_url( $filters ) ),
			'' === $current ? 'class="current" aria-current="page"' : '',
			esc_html__( 'All', 'ibg-client-outreach' ),
			esc_html( number_format_i18n( $total ) )
		);

		foreach ( Contact::contact_statuses() as $value => $label ) {
			$count = $counts[ $value ] ?? 0;
			if ( 0 === $count && $value !== $current ) {
				continue;
			}
			$views[ $value ] = sprintf(
				'<a href="%s" %s>%s <span class="count">(%s)</span></a>',
				esc_url( $this->page->get_url( array_merge( $filters, array( 'contact_status' => $value ) ) ) ),
				$value === $current ? 'class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $count ) )
			);
		}

		return $views;
	}

	/** @inheritDoc */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$filters = $this->get_filter_args();
		?>
		<div class="alignleft actions ibg-filters">
			<label class="screen-reader-text" for="ibg-filter-marketing"><?php esc_html_e( 'Filter by marketing status', 'ibg-client-outreach' ); ?></label>
			<select name="marketing_status" id="ibg-filter-marketing">
				<option value=""><?php esc_html_e( 'All marketing statuses', 'ibg-client-outreach' ); ?></option>
				<?php foreach ( Contact::marketing_statuses() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['marketing_status'] ?? '', $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>

			<?php
			$dropdowns = array(
				'industry' => __( 'All industries', 'ibg-client-outreach' ),
				'country'  => __( 'All countries', 'ibg-client-outreach' ),
				'source'   => __( 'All sources', 'ibg-client-outreach' ),
			);
			foreach ( $dropdowns as $column => $placeholder ) :
				$values = $this->repository->get_distinct( $column );
				if ( empty( $values ) ) {
					continue;
				}
				?>
				<label class="screen-reader-text" for="ibg-filter-<?php echo esc_attr( $column ); ?>"><?php echo esc_html( $placeholder ); ?></label>
				<select name="<?php echo esc_attr( $column ); ?>" id="ibg-filter-<?php echo esc_attr( $column ); ?>">
					<option value=""><?php echo esc_html( $placeholder ); ?></option>
					<?php foreach ( $values as $value ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters[ $column ] ?? '', $value ); ?>><?php echo esc_html( $value ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php endforeach; ?>

			<label class="screen-reader-text" for="ibg-filter-from"><?php esc_html_e( 'Added from', 'ibg-client-outreach' ); ?></label>
			<input type="date" name="date_from" id="ibg-filter-from" value="<?php echo esc_attr( $filters['date_from'] ?? '' ); ?>" title="<?php esc_attr_e( 'Added from', 'ibg-client-outreach' ); ?>">
			<label class="screen-reader-text" for="ibg-filter-to"><?php esc_html_e( 'Added to', 'ibg-client-outreach' ); ?></label>
			<input type="date" name="date_to" id="ibg-filter-to" value="<?php echo esc_attr( $filters['date_to'] ?? '' ); ?>" title="<?php esc_attr_e( 'Added to', 'ibg-client-outreach' ); ?>">

			<?php submit_button( __( 'Filter', 'ibg-client-outreach' ), 'secondary', 'filter_action', false ); ?>
			<?php if ( ! empty( array_diff_key( $filters, array_flip( array( 'orderby', 'order', 'paged' ) ) ) ) ) : ?>
				<a class="button" href="<?php echo esc_url( $this->page->get_url() ); ?>"><?php esc_html_e( 'Reset', 'ibg-client-outreach' ); ?></a>
			<?php endif; ?>
		</div>
		<?php
	}

	/** @inheritDoc */
	public function prepare_items(): void {
		$per_page = $this->get_items_per_page( self::PER_PAGE_OPTION, 20 );
		$filters  = $this->get_filter_args();

		$args             = $this->to_query_args( $filters );
		$args['orderby']  = $filters['orderby'] ?? 'created_at';
		$args['order']    = $filters['order'] ?? 'DESC';
		$args['per_page'] = $per_page;
		$args['page']     = $this->get_pagenum();

		$result = $this->repository->query( $args );

		$this->items = $result['items'];

		$this->_column_headers = array(
			$this->get_columns(),
			get_hidden_columns( $this->screen ),
			$this->get_sortable_columns(),
			'name',
		);

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $result['total'] / $per_page ),
			)
		);
	}

	/**
	 * Map query-string filters to repository args.
	 *
	 * @param array<string, string> $filters Filters.
	 * @return array<string, mixed>
	 */
	private function to_query_args( array $filters ): array {
		$args = array();
		foreach ( array( 'contact_status', 'marketing_status', 'industry', 'country', 'source', 'date_from', 'date_to' ) as $key ) {
			if ( ! empty( $filters[ $key ] ) ) {
				$args[ $key ] = $filters[ $key ];
			}
		}
		if ( ! empty( $filters['s'] ) ) {
			$args['search'] = $filters['s'];
		}
		return $args;
	}

	/** @inheritDoc */
	public function no_items(): void {
		if ( ! empty( array_diff_key( $this->get_filter_args(), array_flip( array( 'orderby', 'order', 'paged' ) ) ) ) ) {
			esc_html_e( 'No contacts match the current filters.', 'ibg-client-outreach' );
			return;
		}

		esc_html_e( 'No contacts yet.', 'ibg-client-outreach' );
		if ( $this->can_manage ) {
			printf(
				' <a href="%s">%s</a>',
				esc_url( $this->page->get_url( array( 'view' => 'add' ) ) ),
				esc_html__( 'Add your first contact', 'ibg-client-outreach' )
			);
		}
	}

	/**
	 * Checkbox column.
	 *
	 * @param Contact $item Contact.
	 * @return string
	 */
	public function column_cb( $item ): string {
		return sprintf(
			'<label class="screen-reader-text" for="cb-select-%1$d">%2$s</label><input type="checkbox" name="contact[]" id="cb-select-%1$d" value="%1$d" />',
			$item->id,
			/* translators: %s: contact name */
			esc_html( sprintf( __( 'Select %s', 'ibg-client-outreach' ), $item->get_display_name() ) )
		);
	}

	/**
	 * Name column with row actions.
	 *
	 * @param Contact $item Contact.
	 * @return string
	 */
	public function column_name( $item ): string {
		$edit_url = $this->page->get_url(
			array(
				'view' => 'edit',
				'id'   => $item->id,
			)
		);

		$name = $this->can_manage
			? sprintf( '<a class="row-title" href="%s">%s</a>', esc_url( $edit_url ), esc_html( $item->get_display_name() ) )
			: sprintf( '<strong>%s</strong>', esc_html( $item->get_display_name() ) );

		$actions = array();
		if ( $this->can_manage ) {
			$actions['edit']   = sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'ibg-client-outreach' ) );
			$actions['delete'] = sprintf(
				'<a href="%s" class="submitdelete" data-ibg-confirm="%s">%s</a>',
				esc_url( $this->page->get_delete_url( $item->id ) ),
				esc_attr__( 'Delete this contact? This cannot be undone.', 'ibg-client-outreach' ),
				esc_html__( 'Delete', 'ibg-client-outreach' )
			);
		}

		return $name . $this->row_actions( $actions );
	}

	/**
	 * Email column.
	 *
	 * @param Contact $item Contact.
	 * @return string
	 */
	public function column_email( $item ): string {
		$out = esc_html( $item->email );
		if ( Contact::EMAIL_VALID !== $item->email_status ) {
			$out .= ' <span class="ibg-badge ibg-badge-error">' . esc_html( Contact::label( Contact::email_statuses(), $item->email_status ) ) . '</span>';
		}
		return $out;
	}

	/**
	 * Company column with website link.
	 *
	 * @param Contact $item Contact.
	 * @return string
	 */
	public function column_company( $item ): string {
		$out = esc_html( $item->company );
		if ( '' !== $item->website ) {
			$out .= sprintf(
				' <a href="%s" target="_blank" rel="noopener noreferrer" class="ibg-website-link" title="%s"><span class="dashicons dashicons-external"></span></a>',
				esc_url( $item->website ),
				esc_attr( $item->website )
			);
		}
		return $out;
	}

	/**
	 * Contact status column.
	 *
	 * @param Contact $item Contact.
	 * @return string
	 */
	public function column_contact_status( $item ): string {
		return sprintf(
			'<span class="ibg-badge ibg-badge-status-%s">%s</span>',
			esc_attr( $item->contact_status ),
			esc_html( Contact::label( Contact::contact_statuses(), $item->contact_status ) )
		);
	}

	/**
	 * Marketing status column.
	 *
	 * @param Contact $item Contact.
	 * @return string
	 */
	public function column_marketing_status( $item ): string {
		return sprintf(
			'<span class="ibg-badge ibg-badge-marketing-%s">%s</span>',
			esc_attr( $item->marketing_status ),
			esc_html( Contact::label( Contact::marketing_statuses(), $item->marketing_status ) )
		);
	}

	/**
	 * Last contacted column.
	 *
	 * @param Contact $item Contact.
	 * @return string
	 */
	public function column_last_contacted_at( $item ): string {
		return esc_html( Formatting::relative( $item->last_contacted_at ) );
	}

	/**
	 * Added column.
	 *
	 * @param Contact $item Contact.
	 * @return string
	 */
	public function column_created_at( $item ): string {
		return sprintf(
			'<span title="%s">%s</span>',
			esc_attr( Formatting::datetime( $item->created_at ) ),
			esc_html( Formatting::datetime( $item->created_at, get_option( 'date_format' ) ) )
		);
	}

	/**
	 * Default column renderer.
	 *
	 * @param Contact $item        Contact.
	 * @param string  $column_name Column.
	 * @return string
	 */
	public function column_default( $item, $column_name ): string {
		return isset( $item->{$column_name} ) ? esc_html( (string) $item->{$column_name} ) : '';
	}
}
