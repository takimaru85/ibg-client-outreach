<?php
/**
 * Suppression list view.
 *
 * @var array{
 *   page: \IBG\Outreach\Admin\Pages\Suppressions_Page,
 *   table: \IBG\Outreach\Admin\Tables\Suppressions_List_Table,
 *   can_manage: bool,
 *   nonce: string,
 *   reasons: array<string, string>,
 *   export_url: string
 * } $data
 *
 * @package IBG\Outreach
 */

defined( 'ABSPATH' ) || exit;

$ibg_page = $data['page'];
?>
<div class="wrap ibg-outreach-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Suppression List', 'ibg-client-outreach' ); ?></h1>
	<?php if ( $data['can_manage'] ) : ?>
		<a href="<?php echo esc_url( $data['export_url'] ); ?>" class="page-title-action"><?php esc_html_e( 'Export CSV', 'ibg-client-outreach' ); ?></a>
	<?php endif; ?>
	<hr class="wp-header-end">

	<p class="description">
		<?php esc_html_e( 'Addresses on this list are never emailed by campaigns, even if they are re-imported or re-added as contacts. Entries survive contact deletion (kept as a hash) so an opt-out is honoured permanently.', 'ibg-client-outreach' ); ?>
	</p>

	<?php if ( $data['can_manage'] ) : ?>
		<details class="ibg-card ibg-card-narrow ibg-details">
			<summary><?php esc_html_e( 'Add addresses to the suppression list', 'ibg-client-outreach' ); ?></summary>
			<form method="post" action="<?php echo esc_url( $ibg_page->get_url() ); ?>">
				<?php wp_nonce_field( $data['nonce'] ); ?>
				<input type="hidden" name="ibg_action" value="add_suppressions">
				<p>
					<label for="ibg-supp-emails"><?php esc_html_e( 'Email addresses (one per line, or separated by commas)', 'ibg-client-outreach' ); ?></label><br>
					<textarea id="ibg-supp-emails" name="emails" rows="5" class="large-text code" required></textarea>
				</p>
				<p>
					<label for="ibg-supp-reason"><?php esc_html_e( 'Reason', 'ibg-client-outreach' ); ?></label>
					<select id="ibg-supp-reason" name="reason">
						<?php foreach ( $data['reasons'] as $ibg_value => $ibg_label ) : ?>
							<option value="<?php echo esc_attr( $ibg_value ); ?>"><?php echo esc_html( $ibg_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p class="description"><?php esc_html_e( 'Existing contacts are updated to the matching marketing status; unknown addresses are stored so they can never be imported as mailable. Use "Do Not Contact" for legal or complaint cases: it cannot be lifted by the recipient, only by an admin.', 'ibg-client-outreach' ); ?></p>
				<?php submit_button( __( 'Suppress addresses', 'ibg-client-outreach' ), 'secondary', 'submit', false ); ?>
			</form>
		</details>
	<?php endif; ?>

	<?php $data['table']->views(); ?>

	<form method="get">
		<input type="hidden" name="page" value="<?php echo esc_attr( $ibg_page->get_slug() ); ?>">
		<?php
		$data['table']->search_box( __( 'Search by email', 'ibg-client-outreach' ), 'ibg-supp' );
		$data['table']->display();
		?>
	</form>
</div>
