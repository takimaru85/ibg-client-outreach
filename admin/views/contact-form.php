<?php
/**
 * Contact add/edit form.
 *
 * @var array{
 *   page: \IBG\Outreach\Admin\Pages\Contacts_Page,
 *   contact: \IBG\Outreach\Contacts\Contact|null,
 *   values: array<string, mixed>,
 *   errors: \WP_Error|null,
 *   events: array<int, object>,
 *   nonce: string,
 *   list_url: string
 * } $data
 *
 * @package IBG\Outreach
 */

use IBG\Outreach\Contacts\Contact;
use IBG\Outreach\Formatting;

defined( 'ABSPATH' ) || exit;

$ibg_contact = $data['contact'];
$ibg_values  = $data['values'];
$ibg_errors  = $data['errors'];
$ibg_is_edit = null !== $ibg_contact;

$ibg_text_fields = array(
	'first_name' => array( __( 'First Name', 'ibg-client-outreach' ), 'text', '' ),
	'last_name'  => array( __( 'Last Name', 'ibg-client-outreach' ), 'text', '' ),
	'full_name'  => array( __( 'Full Name', 'ibg-client-outreach' ), 'text', __( 'Leave blank to use "First Last".', 'ibg-client-outreach' ) ),
	'company'    => array( __( 'Company', 'ibg-client-outreach' ), 'text', '' ),
	'email'      => array( __( 'Email', 'ibg-client-outreach' ), 'email', '' ),
	'website'    => array( __( 'Website', 'ibg-client-outreach' ), 'url', '' ),
	'phone'      => array( __( 'Phone', 'ibg-client-outreach' ), 'tel', '' ),
	'country'    => array( __( 'Country', 'ibg-client-outreach' ), 'text', '' ),
	'industry'   => array( __( 'Industry', 'ibg-client-outreach' ), 'text', __( 'e.g. Dental, Real Estate, Restaurant, Construction.', 'ibg-client-outreach' ) ),
	'source'     => array( __( 'Source', 'ibg-client-outreach' ), 'text', __( 'Where this contact came from, e.g. "Website form", "Referral", "Trade directory". Required for a defensible lawful basis.', 'ibg-client-outreach' ) ),
);

$ibg_event_labels = array(
	'contact.created'                  => __( 'Contact created', 'ibg-client-outreach' ),
	'contact.updated'                  => __( 'Contact updated', 'ibg-client-outreach' ),
	'contact.status_changed'           => __( 'Status changed', 'ibg-client-outreach' ),
	'contact.marketing_status_changed' => __( 'Marketing status changed', 'ibg-client-outreach' ),
	'contact.suppression_preserved'    => __( 'Suppression preserved (change refused)', 'ibg-client-outreach' ),
	'contact.resubscribed'             => __( 'Resubscribed (confirmed by admin)', 'ibg-client-outreach' ),
	'contact.lists_changed'            => __( 'List membership changed', 'ibg-client-outreach' ),
);
?>
<div class="wrap ibg-outreach-wrap">
	<h1 class="wp-heading-inline">
		<?php echo $ibg_is_edit ? esc_html__( 'Edit Contact', 'ibg-client-outreach' ) : esc_html__( 'Add Contact', 'ibg-client-outreach' ); ?>
	</h1>
	<a href="<?php echo esc_url( $data['list_url'] ); ?>" class="page-title-action"><?php esc_html_e( 'Back to Contacts', 'ibg-client-outreach' ); ?></a>
	<hr class="wp-header-end">

	<?php if ( $ibg_errors instanceof WP_Error && $ibg_errors->has_errors() ) : ?>
		<div class="notice notice-error">
			<ul class="ibg-error-list">
				<?php foreach ( $ibg_errors->get_error_codes() as $ibg_code ) : ?>
					<li>
						<?php echo esc_html( $ibg_errors->get_error_message( $ibg_code ) ); ?>
						<?php
						$ibg_err_data = $ibg_errors->get_error_data( $ibg_code );
						if ( 'email_duplicate' === $ibg_code && is_array( $ibg_err_data ) && ! empty( $ibg_err_data['contact_id'] ) ) :
							?>
							<a href="<?php echo esc_url( $data['page']->get_url( array( 'view' => 'edit', 'id' => (int) $ibg_err_data['contact_id'] ) ) ); ?>"><?php esc_html_e( 'Open the existing contact', 'ibg-client-outreach' ); ?></a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( $ibg_is_edit ? $data['page']->get_url( array( 'view' => 'edit', 'id' => $ibg_contact->id ) ) : $data['page']->get_url( array( 'view' => 'add' ) ) ); ?>" class="ibg-contact-form">
		<?php wp_nonce_field( $data['nonce'] ); ?>
		<input type="hidden" name="ibg_action" value="save_contact">

		<div class="ibg-form-layout">
			<div class="ibg-form-main">
				<div class="ibg-card">
					<h2><?php esc_html_e( 'Details', 'ibg-client-outreach' ); ?></h2>
					<table class="form-table" role="presentation">
						<tbody>
						<?php foreach ( $ibg_text_fields as $ibg_key => list( $ibg_label, $ibg_type, $ibg_help ) ) : ?>
							<tr>
								<th scope="row">
									<label for="ibg-contact-<?php echo esc_attr( $ibg_key ); ?>">
										<?php echo esc_html( $ibg_label ); ?>
										<?php if ( 'email' === $ibg_key ) : ?><span class="ibg-required" aria-hidden="true">*</span><?php endif; ?>
									</label>
								</th>
								<td>
									<input type="<?php echo esc_attr( $ibg_type ); ?>" class="regular-text" id="ibg-contact-<?php echo esc_attr( $ibg_key ); ?>" name="contact[<?php echo esc_attr( $ibg_key ); ?>]" value="<?php echo esc_attr( (string) ( $ibg_values[ $ibg_key ] ?? '' ) ); ?>" <?php echo 'email' === $ibg_key ? 'required aria-required="true"' : ''; ?>>
									<?php if ( '' !== $ibg_help ) : ?>
										<p class="description"><?php echo esc_html( $ibg_help ); ?></p>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						<tr>
							<th scope="row"><label for="ibg-contact-notes"><?php esc_html_e( 'Notes', 'ibg-client-outreach' ); ?></label></th>
							<td><textarea id="ibg-contact-notes" name="contact[notes]" rows="6" class="large-text"><?php echo esc_textarea( (string) ( $ibg_values['notes'] ?? '' ) ); ?></textarea></td>
						</tr>
						</tbody>
					</table>
				</div>

				<div class="ibg-card">
					<h2><?php esc_html_e( 'Status & consent', 'ibg-client-outreach' ); ?></h2>
					<table class="form-table" role="presentation">
						<tbody>
						<tr>
							<th scope="row"><label for="ibg-contact-contact_status"><?php esc_html_e( 'Contact Status', 'ibg-client-outreach' ); ?></label></th>
							<td>
								<select id="ibg-contact-contact_status" name="contact[contact_status]">
									<?php foreach ( Contact::contact_statuses() as $ibg_value => $ibg_label ) : ?>
										<option value="<?php echo esc_attr( $ibg_value ); ?>" <?php selected( $ibg_values['contact_status'] ?? Contact::STATUS_LEAD, $ibg_value ); ?>><?php echo esc_html( $ibg_label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="ibg-contact-marketing_status"><?php esc_html_e( 'Marketing Status', 'ibg-client-outreach' ); ?></label></th>
							<td>
								<select id="ibg-contact-marketing_status" name="contact[marketing_status]">
									<?php foreach ( Contact::marketing_statuses() as $ibg_value => $ibg_label ) : ?>
										<option value="<?php echo esc_attr( $ibg_value ); ?>" <?php selected( $ibg_values['marketing_status'] ?? Contact::MARKETING_PENDING, $ibg_value ); ?>><?php echo esc_html( $ibg_label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Only choose "Subscribed" when the contact explicitly opted in. "Pending" means you have a business basis to contact them but no explicit opt-in. Unsubscribed and Do Not Contact are never emailed by campaigns.', 'ibg-client-outreach' ); ?></p>

								<?php if ( $ibg_is_edit && $ibg_contact->is_suppressed() ) : ?>
									<div class="ibg-inline-warning">
										<p>
											<strong><?php esc_html_e( 'This address is on the suppression list.', 'ibg-client-outreach' ); ?></strong>
											<?php
											printf(
												/* translators: %s: date */
												esc_html__( 'Suppressed since %s. Changing the status back to Pending or Subscribed requires confirmation and will be logged.', 'ibg-client-outreach' ),
												esc_html( Formatting::datetime( $ibg_contact->unsubscribed_at ) )
											);
											?>
										</p>
										<label>
											<input type="checkbox" name="confirm_resubscribe" value="1">
											<?php esc_html_e( 'I confirm this person has explicitly asked to receive emails again (or that the Do Not Contact flag was set in error).', 'ibg-client-outreach' ); ?>
										</label>
									</div>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="ibg-contact-consent_basis"><?php esc_html_e( 'Lawful Basis', 'ibg-client-outreach' ); ?></label></th>
							<td>
								<select id="ibg-contact-consent_basis" name="contact[consent_basis]">
									<?php foreach ( Contact::consent_bases() as $ibg_value => $ibg_label ) : ?>
										<option value="<?php echo esc_attr( $ibg_value ); ?>" <?php selected( $ibg_values['consent_basis'] ?? '', $ibg_value ); ?>><?php echo esc_html( $ibg_label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Why you are entitled to email this contact. Record it now; it is much harder to reconstruct later.', 'ibg-client-outreach' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="ibg-contact-consent_at"><?php esc_html_e( 'Consent / Basis Date', 'ibg-client-outreach' ); ?></label></th>
							<td>
								<input type="date" id="ibg-contact-consent_at" name="contact[consent_at]" value="<?php echo esc_attr( Formatting::utc_to_local_date( $ibg_values['consent_at'] ?? null ) ); ?>">
								<p class="description"><?php esc_html_e( 'Set automatically when the status becomes Subscribed, if left empty.', 'ibg-client-outreach' ); ?></p>
							</td>
						</tr>
						</tbody>
					</table>
				</div>

				<p class="submit">
					<?php submit_button( $ibg_is_edit ? __( 'Update Contact', 'ibg-client-outreach' ) : __( 'Add Contact', 'ibg-client-outreach' ), 'primary', 'submit', false ); ?>
					<?php if ( $ibg_is_edit ) : ?>
						<a href="<?php echo esc_url( $data['page']->get_delete_url( $ibg_contact->id ) ); ?>" class="button button-link-delete" data-ibg-confirm="<?php esc_attr_e( 'Delete this contact? This cannot be undone. If the address is suppressed, the suppression will be kept.', 'ibg-client-outreach' ); ?>"><?php esc_html_e( 'Delete', 'ibg-client-outreach' ); ?></a>
					<?php endif; ?>
				</p>
			</div>

			<div class="ibg-form-side">
				<div class="ibg-card">
					<h2><?php esc_html_e( 'Lists', 'ibg-client-outreach' ); ?></h2>
					<?php if ( empty( $data['lists'] ) ) : ?>
						<p class="description">
							<?php esc_html_e( 'No lists yet.', 'ibg-client-outreach' ); ?>
							<a href="<?php echo esc_url( add_query_arg( 'view', 'add', $data['lists_url'] ) ); ?>"><?php esc_html_e( 'Create one', 'ibg-client-outreach' ); ?></a>
						</p>
					<?php else : ?>
						<ul class="ibg-checklist">
							<?php foreach ( $data['lists'] as $ibg_list ) : ?>
								<li>
									<label>
										<input type="checkbox" name="contact_lists[]" value="<?php echo (int) $ibg_list->id; ?>" <?php checked( in_array( $ibg_list->id, $data['member_of'], true ) ); ?>>
										<?php echo esc_html( $ibg_list->name ); ?>
									</label>
								</li>
							<?php endforeach; ?>
						</ul>
						<p class="description"><?php esc_html_e( 'Segments are dynamic and cannot be assigned manually.', 'ibg-client-outreach' ); ?></p>
					<?php endif; ?>
				</div>

			<?php if ( $ibg_is_edit ) : ?>
					<div class="ibg-card">
						<h2><?php esc_html_e( 'Record', 'ibg-client-outreach' ); ?></h2>
						<dl class="ibg-meta">
							<dt><?php esc_html_e( 'ID', 'ibg-client-outreach' ); ?></dt><dd><?php echo (int) $ibg_contact->id; ?></dd>
							<dt><?php esc_html_e( 'Email status', 'ibg-client-outreach' ); ?></dt><dd><?php echo esc_html( Contact::label( Contact::email_statuses(), $ibg_contact->email_status ) ); ?></dd>
							<dt><?php esc_html_e( 'Added', 'ibg-client-outreach' ); ?></dt><dd><?php echo esc_html( Formatting::datetime( $ibg_contact->created_at ) ); ?></dd>
							<dt><?php esc_html_e( 'Updated', 'ibg-client-outreach' ); ?></dt><dd><?php echo esc_html( Formatting::datetime( $ibg_contact->updated_at ) ); ?></dd>
							<dt><?php esc_html_e( 'Last contacted', 'ibg-client-outreach' ); ?></dt><dd><?php echo esc_html( Formatting::datetime( $ibg_contact->last_contacted_at ) ); ?></dd>
							<dt><?php esc_html_e( 'Last opened', 'ibg-client-outreach' ); ?></dt><dd><?php echo esc_html( Formatting::datetime( $ibg_contact->last_opened_at ) ); ?></dd>
							<dt><?php esc_html_e( 'Last clicked', 'ibg-client-outreach' ); ?></dt><dd><?php echo esc_html( Formatting::datetime( $ibg_contact->last_clicked_at ) ); ?></dd>
							<dt><?php esc_html_e( 'Unsubscribed', 'ibg-client-outreach' ); ?></dt><dd><?php echo esc_html( Formatting::datetime( $ibg_contact->unsubscribed_at ) ); ?></dd>
						</dl>
					</div>

					<div class="ibg-card">
						<h2><?php esc_html_e( 'Activity', 'ibg-client-outreach' ); ?></h2>
						<?php if ( empty( $data['events'] ) ) : ?>
							<p class="description"><?php esc_html_e( 'No activity recorded.', 'ibg-client-outreach' ); ?></p>
						<?php else : ?>
							<ul class="ibg-activity">
								<?php foreach ( $data['events'] as $ibg_event ) : ?>
									<li>
										<span class="ibg-activity-time"><?php echo esc_html( Formatting::datetime( $ibg_event->created_at ) ); ?></span>
										<strong><?php echo esc_html( $ibg_event_labels[ $ibg_event->event_type ] ?? $ibg_event->event_type ); ?></strong>
										<?php
										$ibg_bits = array();
										if ( isset( $ibg_event->data['from'], $ibg_event->data['to'] ) ) {
											$ibg_bits[] = $ibg_event->data['from'] . ' → ' . $ibg_event->data['to'];
										} elseif ( isset( $ibg_event->data['to'] ) ) {
											$ibg_bits[] = '→ ' . $ibg_event->data['to'];
										}
										if ( isset( $ibg_event->data['requested'], $ibg_event->data['kept'] ) ) {
											/* translators: 1: requested status, 2: kept status */
											$ibg_bits[] = sprintf( __( 'requested %1$s, kept %2$s', 'ibg-client-outreach' ), $ibg_event->data['requested'], $ibg_event->data['kept'] );
										}
										if ( ! empty( $ibg_event->data['fields'] ) && is_array( $ibg_event->data['fields'] ) ) {
											$ibg_bits[] = implode( ', ', array_map( 'strval', $ibg_event->data['fields'] ) );
										}
										foreach ( array( 'added', 'removed' ) as $ibg_dir ) {
											if ( ! empty( $ibg_event->data[ $ibg_dir ] ) && is_array( $ibg_event->data[ $ibg_dir ] ) ) {
												$ibg_names = array();
												foreach ( $ibg_event->data[ $ibg_dir ] as $ibg_lid ) {
													$ibg_names[] = isset( $data['lists'][ (int) $ibg_lid ] ) ? $data['lists'][ (int) $ibg_lid ]->name : '#' . (int) $ibg_lid;
												}
												$ibg_bits[] = ( 'added' === $ibg_dir ? '+ ' : '− ' ) . implode( ', ', $ibg_names );
											}
										}
										if ( ! empty( $ibg_event->data['source'] ) ) {
											$ibg_bits[] = '[' . $ibg_event->data['source'] . ']';
										}
										if ( ! empty( $ibg_event->data['user_id'] ) ) {
											$ibg_user = get_userdata( (int) $ibg_event->data['user_id'] );
											if ( $ibg_user ) {
												$ibg_bits[] = $ibg_user->display_name;
											}
										}
										if ( ! empty( $ibg_bits ) ) :
											?>
											<span class="ibg-activity-detail"><?php echo esc_html( implode( ' · ', $ibg_bits ) ); ?></span>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</div>
			<?php endif; ?>
			</div>
		</div>
	</form>
</div>
