<?php
/**
 * Lists / segments list view.
 *
 * @var array{
 *   page: \IBG\Outreach\Admin\Pages\Lists_Page,
 *   table: \IBG\Outreach\Admin\Tables\Lists_List_Table,
 *   can_manage: bool
 * } $data
 *
 * @package IBG\Outreach
 */

defined( 'ABSPATH' ) || exit;

$ibg_page = $data['page'];
?>
<div class="wrap ibg-outreach-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Lists & Segments', 'ibg-client-outreach' ); ?></h1>
	<?php if ( $data['can_manage'] ) : ?>
		<a href="<?php echo esc_url( $ibg_page->get_url( array( 'view' => 'add' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'ibg-client-outreach' ); ?></a>
	<?php endif; ?>
	<hr class="wp-header-end">

	<p class="description">
		<?php esc_html_e( 'A list has fixed members you add manually, in bulk or on import. A segment is a saved filter that is evaluated live, so it always reflects your current contacts. Campaigns can target either.', 'ibg-client-outreach' ); ?>
	</p>

	<?php $data['table']->views(); ?>

	<form method="get">
		<input type="hidden" name="page" value="<?php echo esc_attr( $ibg_page->get_slug() ); ?>">
		<?php $data['table']->display(); ?>
	</form>
</div>
