<?php
/**
 * Campaigns list view.
 *
 * @var array{
 *   page: \IBG\Outreach\Admin\Pages\Campaigns_Page,
 *   table: \IBG\Outreach\Admin\Tables\Campaigns_List_Table,
 *   can_manage: bool
 * } $data
 *
 * @package IBG\Outreach
 */

defined( 'ABSPATH' ) || exit;

$ibg_page = $data['page'];
?>
<div class="wrap ibg-outreach-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Campaigns', 'ibg-client-outreach' ); ?></h1>
	<?php if ( $data['can_manage'] ) : ?>
		<a href="<?php echo esc_url( $ibg_page->get_url( array( 'view' => 'add' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'ibg-client-outreach' ); ?></a>
	<?php endif; ?>
	<hr class="wp-header-end">

	<?php $data['table']->views(); ?>

	<form method="get">
		<input type="hidden" name="page" value="<?php echo esc_attr( $ibg_page->get_slug() ); ?>">
		<?php
		$data['table']->search_box( __( 'Search campaigns', 'ibg-client-outreach' ), 'ibg-campaign' );
		$data['table']->display();
		?>
	</form>
</div>
