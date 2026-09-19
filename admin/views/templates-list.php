<?php
/**
 * Templates list view.
 *
 * @var array{
 *   page: \IBG\Outreach\Admin\Pages\Templates_Page,
 *   table: \IBG\Outreach\Admin\Tables\Templates_List_Table,
 *   can_manage: bool,
 *   example_url: string,
 *   has_any: bool
 * } $data
 *
 * @package IBG\Outreach
 */

defined( 'ABSPATH' ) || exit;

$ibg_page = $data['page'];
?>
<div class="wrap ibg-outreach-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Email Templates', 'ibg-client-outreach' ); ?></h1>
	<?php if ( $data['can_manage'] ) : ?>
		<a href="<?php echo esc_url( $ibg_page->get_url( array( 'view' => 'add' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'ibg-client-outreach' ); ?></a>
	<?php endif; ?>
	<hr class="wp-header-end">

	<?php if ( ! $data['has_any'] && $data['can_manage'] ) : ?>
		<div class="ibg-card ibg-card-narrow ibg-empty-state">
			<h2><?php esc_html_e( 'No templates yet', 'ibg-client-outreach' ); ?></h2>
			<p><?php esc_html_e( 'A template holds the subject and body of an email. Merge tags like {{first_name}} and {{company}} are replaced per recipient. Your compliance footer from Settings is added to every email automatically.', 'ibg-client-outreach' ); ?></p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $data['example_url'] ); ?>"><?php esc_html_e( 'Create the example outreach template', 'ibg-client-outreach' ); ?></a>
				<a class="button" href="<?php echo esc_url( $ibg_page->get_url( array( 'view' => 'add' ) ) ); ?>"><?php esc_html_e( 'Start from scratch', 'ibg-client-outreach' ); ?></a>
			</p>
		</div>
	<?php endif; ?>

	<?php $data['table']->views(); ?>

	<form method="get">
		<input type="hidden" name="page" value="<?php echo esc_attr( $ibg_page->get_slug() ); ?>">
		<?php
		$data['table']->search_box( __( 'Search templates', 'ibg-client-outreach' ), 'ibg-template' );
		$data['table']->display();
		?>
	</form>
</div>
