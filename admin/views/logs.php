<?php
/**
 * Email logs view.
 *
 * @var array{
 *   page: \IBG\Outreach\Admin\Pages\Logs_Page,
 *   table: \IBG\Outreach\Admin\Tables\Logs_List_Table,
 *   can_purge: bool,
 *   purge_url: string,
 *   retention: int
 * } $data
 *
 * @package IBG\Outreach
 */

defined( 'ABSPATH' ) || exit;

$ibg_page = $data['page'];
?>
<div class="wrap ibg-outreach-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Email Logs', 'ibg-client-outreach' ); ?></h1>
	<?php if ( $data['can_purge'] ) : ?>
		<a href="<?php echo esc_url( $data['purge_url'] ); ?>" class="page-title-action" data-ibg-confirm="<?php echo esc_attr( sprintf( /* translators: %d: days */ __( 'Delete all log entries older than %d days now?', 'ibg-client-outreach' ), $data['retention'] ) ); ?>">
			<?php printf( /* translators: %d: days */ esc_html__( 'Purge entries older than %d days', 'ibg-client-outreach' ), (int) $data['retention'] ); ?>
		</a>
	<?php endif; ?>
	<hr class="wp-header-end">

	<p class="description">
		<?php
		printf(
			/* translators: %d: days */
			esc_html__( 'One entry per send attempt, skip or test. Message bodies are not stored. Entries are deleted automatically after %d days (Settings → Privacy & Data).', 'ibg-client-outreach' ),
			(int) $data['retention']
		);
		?>
	</p>

	<?php $data['table']->views(); ?>

	<form method="get">
		<input type="hidden" name="page" value="<?php echo esc_attr( $ibg_page->get_slug() ); ?>">
		<?php
		$data['table']->search_box( __( 'Search email, subject or error', 'ibg-client-outreach' ), 'ibg-logs' );
		$data['table']->display();
		?>
	</form>
</div>
