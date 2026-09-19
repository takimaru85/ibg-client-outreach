<?php
/**
 * List / segment add-edit form.
 *
 * @var array{
 *   page: \IBG\Outreach\Admin\Pages\Lists_Page,
 *   list: \IBG\Outreach\Lists\Contact_List|null,
 *   values: array<string, mixed>,
 *   error: \WP_Error|null,
 *   nonce: string,
 *   count: int|null,
 *   static_lists: array<int, \IBG\Outreach\Lists\Contact_List>,
 *   options: array<string, array<string, string>>,
 *   list_url: string,
 *   contacts_url: string
 * } $data
 *
 * @package IBG\Outreach
 */

use IBG\Outreach\Lists\Contact_List;

defined( 'ABSPATH' ) || exit;

$ibg_list     = $data['list'];
$ibg_values   = $data['values'];
$ibg_criteria = (array) ( $ibg_values['criteria'] ?? array() );
$ibg_is_edit  = null !== $ibg_list;
$ibg_type     = (string) $ibg_values['type'];

$ibg_multi = array(
	'contact_status'   => __( 'Contact status', 'ibg-client-outreach' ),
	'marketing_status' => __( 'Marketing status', 'ibg-client-outreach' ),
	'consent_basis'    => __( 'Lawful basis', 'ibg-client-outreach' ),
	'industry'         => __( 'Industry', 'ibg-client-outreach' ),
	'country'          => __( 'Country', 'ibg-client-outreach' ),
	'source'           => __( 'Source', 'ibg-client-outreach' ),
);
?>
<div class="wrap ibg-outreach-wrap">
	<h1 class="wp-heading-inline">
		<?php echo $ibg_is_edit ? esc_html__( 'Edit List / Segment', 'ibg-client-outreach' ) : esc_html__( 'Add List / Segment', 'ibg-client-outreach' ); ?>
	</h1>
	<a href="<?php echo esc_url( $data['list_url'] ); ?>" class="page-title-action"><?php esc_html_e( 'Back to Lists', 'ibg-client-outreach' ); ?></a>
	<hr class="wp-header-end">

	<?php if ( $data['error'] instanceof WP_Error ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $data['error']->get_error_message() ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( $ibg_is_edit ? $data['page']->get_url( array( 'view' => 'edit', 'id' => $ibg_list->id ) ) : $data['page']->get_url( array( 'view' => 'add' ) ) ); ?>">
		<?php wp_nonce_field( $data['nonce'] ); ?>
		<input type="hidden" name="ibg_action" value="save_list">

		<div class="ibg-card ibg-card-narrow">
			<table class="form-table" role="presentation">
				<tbody>
				<tr>
					<th scope="row"><label for="ibg-list-name"><?php esc_html_e( 'Name', 'ibg-client-outreach' ); ?> <span class="ibg-required" aria-hidden="true">*</span></label></th>
					<td><input type="text" class="regular-text" id="ibg-list-name" name="list[name]" value="<?php echo esc_attr( (string) $ibg_values['name'] ); ?>" required></td>
				</tr>
				<tr>
					<th scope="row"><label for="ibg-list-description"><?php esc_html_e( 'Description', 'ibg-client-outreach' ); ?></label></th>
					<td><textarea id="ibg-list-description" name="list[description]" rows="3" class="large-text"><?php echo esc_textarea( (string) $ibg_values['description'] ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Type', 'ibg-client-outreach' ); ?></th>
					<td>
						<?php if ( $ibg_is_edit ) : ?>
							<strong><?php echo esc_html( Contact_List::types()[ $ibg_type ] ?? $ibg_type ); ?></strong>
							<input type="hidden" name="list[type]" value="<?php echo esc_attr( $ibg_type ); ?>">
							<p class="description"><?php esc_html_e( 'The type cannot be changed after creation.', 'ibg-client-outreach' ); ?></p>
						<?php else : ?>
							<fieldset id="ibg-list-type">
								<?php foreach ( Contact_List::types() as $ibg_value => $ibg_label ) : ?>
									<label class="ibg-radio-row">
										<input type="radio" name="list[type]" value="<?php echo esc_attr( $ibg_value ); ?>" <?php checked( $ibg_type, $ibg_value ); ?>>
										<?php echo esc_html( $ibg_label ); ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
						<?php endif; ?>
					</td>
				</tr>
				</tbody>
			</table>
		</div>

		<div class="ibg-card ibg-card-narrow" id="ibg-segment-criteria" <?php echo Contact_List::TYPE_SEGMENT === $ibg_type ? '' : 'hidden'; ?>>
			<h2><?php esc_html_e( 'Segment criteria', 'ibg-client-outreach' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Contacts must match every filter you set. Within one filter, any selected value matches. Leave a filter empty to ignore it.', 'ibg-client-outreach' ); ?></p>

			<table class="form-table" role="presentation">
				<tbody>
				<?php foreach ( $ibg_multi as $ibg_key => $ibg_label ) : ?>
					<?php
					$ibg_opts = $data['options'][ $ibg_key ] ?? array();
					if ( empty( $ibg_opts ) ) {
						continue;
					}
					$ibg_selected = array_map( 'strval', (array) ( $ibg_criteria[ $ibg_key ] ?? array() ) );
					?>
					<tr>
						<th scope="row"><label for="ibg-crit-<?php echo esc_attr( $ibg_key ); ?>"><?php echo esc_html( $ibg_label ); ?></label></th>
						<td>
							<select id="ibg-crit-<?php echo esc_attr( $ibg_key ); ?>" name="list[criteria][<?php echo esc_attr( $ibg_key ); ?>][]" multiple size="<?php echo (int) min( 6, max( 2, count( $ibg_opts ) ) ); ?>" class="ibg-multiselect">
								<?php foreach ( $ibg_opts as $ibg_value => $ibg_opt_label ) : ?>
									<option value="<?php echo esc_attr( (string) $ibg_value ); ?>" <?php selected( in_array( (string) $ibg_value, $ibg_selected, true ) ); ?>><?php echo esc_html( (string) $ibg_opt_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th scope="row"><label for="ibg-crit-list_id"><?php esc_html_e( 'Member of list', 'ibg-client-outreach' ); ?></label></th>
					<td>
						<select id="ibg-crit-list_id" name="list[criteria][list_id]">
							<option value="0"><?php esc_html_e( '— Any —', 'ibg-client-outreach' ); ?></option>
							<?php foreach ( $data['static_lists'] as $ibg_static ) : ?>
								<?php if ( $ibg_is_edit && $ibg_static->id === $ibg_list->id ) { continue; } ?>
								<option value="<?php echo (int) $ibg_static->id; ?>" <?php selected( (int) ( $ibg_criteria['list_id'] ?? 0 ), $ibg_static->id ); ?>><?php echo esc_html( $ibg_static->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Added between', 'ibg-client-outreach' ); ?></th>
					<td>
						<input type="date" name="list[criteria][date_from]" value="<?php echo esc_attr( (string) ( $ibg_criteria['date_from'] ?? '' ) ); ?>" aria-label="<?php esc_attr_e( 'From', 'ibg-client-outreach' ); ?>">
						–
						<input type="date" name="list[criteria][date_to]" value="<?php echo esc_attr( (string) ( $ibg_criteria['date_to'] ?? '' ) ); ?>" aria-label="<?php esc_attr_e( 'To', 'ibg-client-outreach' ); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ibg-crit-search"><?php esc_html_e( 'Text search', 'ibg-client-outreach' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="ibg-crit-search" name="list[criteria][search]" value="<?php echo esc_attr( (string) ( $ibg_criteria['search'] ?? '' ) ); ?>">
						<p class="description"><?php esc_html_e( 'Matches name, email, company or website.', 'ibg-client-outreach' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Deliverability', 'ibg-client-outreach' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="list[criteria][mailable_only]" value="1" <?php checked( ! empty( $ibg_criteria['mailable_only'] ) ); ?>>
							<?php esc_html_e( 'Only contacts that can receive campaigns (not unsubscribed / do-not-contact, valid email)', 'ibg-client-outreach' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Campaigns apply this exclusion automatically; tick it here to make the count on this page match what a campaign would send.', 'ibg-client-outreach' ); ?></p>
					</td>
				</tr>
				</tbody>
			</table>
		</div>

		<?php if ( $ibg_is_edit ) : ?>
			<p>
				<?php
				printf(
					/* translators: %s: number of contacts */
					esc_html( _n( 'Currently %s contact.', 'Currently %s contacts.', (int) $data['count'], 'ibg-client-outreach' ) ),
					'<strong>' . esc_html( number_format_i18n( (int) $data['count'] ) ) . '</strong>'
				);
				?>
				<a href="<?php echo esc_url( $data['contacts_url'] ); ?>"><?php esc_html_e( 'View them', 'ibg-client-outreach' ); ?></a>
			</p>
		<?php endif; ?>

		<p class="submit">
			<?php submit_button( $ibg_is_edit ? __( 'Update', 'ibg-client-outreach' ) : __( 'Create', 'ibg-client-outreach' ), 'primary', 'submit', false ); ?>
			<?php if ( $ibg_is_edit ) : ?>
				<a href="<?php echo esc_url( $data['page']->get_delete_url( $ibg_list->id ) ); ?>" class="button button-link-delete" data-ibg-confirm="<?php esc_attr_e( 'Delete this list? Contacts are not deleted.', 'ibg-client-outreach' ); ?>"><?php esc_html_e( 'Delete', 'ibg-client-outreach' ); ?></a>
			<?php endif; ?>
		</p>
	</form>
</div>
