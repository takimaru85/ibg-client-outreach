<?php
/**
 * Contacts list view.
 *
 * One GET form carries search, filters, sorting and bulk actions (the same
 * pattern as core's edit.php). Bulk actions are still nonce-checked.
 *
 * @var array{
 *   page: \IBG\Outreach\Admin\Pages\Contacts_Page,
 *   table: \IBG\Outreach\Admin\Tables\Contacts_List_Table,
 *   can_manage: bool
 * } $data
 *
 * @package IBG\Outreach
 */

defined( 'ABSPATH' ) || exit;

$ibg_page  = $data['page'];
$ibg_table = $data['table'];
?>
<div class="wrap ibg-outreach-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Contacts', 'ibg-client-outreach' ); ?></h1>
	<?php if ( $data['can_manage'] ) : ?>
		<a href="<?php echo esc_url( $ibg_page->get_url( array( 'view' => 'add' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'ibg-client-outreach' ); ?></a>
		<a href="<?php echo esc_url( $data['export_url'] ); ?>" class="page-title-action" title="<?php esc_attr_e( 'Exports the contacts matching the current filters', 'ibg-client-outreach' ); ?>"><?php esc_html_e( 'Export CSV', 'ibg-client-outreach' ); ?></a>
	<?php endif; ?>
	<hr class="wp-header-end">

	<?php $ibg_table->views(); ?>

	<form method="get" id="ibg-contacts-filter">
		<input type="hidden" name="page" value="<?php echo esc_attr( $ibg_page->get_slug() ); ?>">
		<?php
		// Keep the current sort when filtering/searching.
		foreach ( array( 'orderby', 'order' ) as $ibg_key ) {
			if ( ! empty( $_GET[ $ibg_key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				printf( '<input type="hidden" name="%s" value="%s">', esc_attr( $ibg_key ), esc_attr( sanitize_text_field( wp_unslash( $_GET[ $ibg_key ] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}
		$ibg_table->search_box( __( 'Search contacts', 'ibg-client-outreach' ), 'ibg-contact' );
		$ibg_table->display();
		?>
	</form>
</div>
