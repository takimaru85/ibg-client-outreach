<?php
/**
 * Campaign create/edit view with pre-flight panel and status actions.
 *
 * @var array{
 *   page: \IBG\Outreach\Admin\Pages\Campaigns_Page,
 *   campaign: \IBG\Outreach\Campaigns\Campaign|null,
 *   values: array<string, mixed>,
 *   error: \WP_Error|null,
 *   nonce: string, state_nonce: string, test_nonce: string,
 *   templates: array<int, string>, template_names: array<int, string>,
 *   lists: array<int, \IBG\Outreach\Lists\Contact_List>, segments: array<int, \IBG\Outreach\Lists\Contact_List>,
 *   summary: array<string, mixed>|\WP_Error|null,
 *   preflight: array<int, array<string, mixed>>,
 *   can_manage: bool, can_send: bool, test_email: string,
 *   sample_contacts: \IBG\Outreach\Contacts\Contact[],
 *   list_url: string, templates_url: string, settings_url: string, delete_url: string
 * } $data
 *
 * @package IBG\Outreach
 */

use IBG\Outreach\Campaigns\Campaign;
use IBG\Outreach\Formatting;

defined( 'ABSPATH' ) || exit;

$ibg_campaign = $data['campaign'];
$ibg_values   = $data['values'];
$ibg_is_edit  = null !== $ibg_campaign;
$ibg_editable = $ibg_is_edit ? $ibg_campaign->is_editable() && $data['can_manage'] : $data['can_manage'];
$ibg_form_url = $ibg_is_edit
	? $data['page']->get_url( array( 'view' => 'edit', 'id' => $ibg_campaign->id ) )
	: $data['page']->get_url( array( 'view' => 'add' ) );
$ibg_summary  = $data['summary'];
$ibg_ready    = true;
foreach ( $data['preflight'] as $ibg_check ) {
	if ( $ibg_check['blocking'] && ! $ibg_check['ok'] ) {
		$ibg_ready = false;
	}
}
$ibg_disabled = $ibg_editable ? '' : 'disabled';
?>
<div class="wrap ibg-outreach-wrap">
	<h1 class="wp-heading-inline">
		<?php
		if ( ! $ibg_is_edit ) {
			esc_html_e( 'New Campaign', 'ibg-client-outreach' );
		} else {
			echo esc_html( $ibg_campaign->name );
			printf(
				' <span class="ibg-badge ibg-badge-campaign-%s">%s</span>',
				esc_attr( $ibg_campaign->status ),
				esc_html( Campaign::statuses()[ $ibg_campaign->status ] )
			);
		}
		?>
	</h1>
	<a href="<?php echo esc_url( $data['list_url'] ); ?>" class="page-title-action"><?php esc_html_e( 'Back to Campaigns', 'ibg-client-outreach' ); ?></a>
	<hr class="wp-header-end">

	<?php if ( $data['error'] instanceof WP_Error ) : ?>
		<div class="notice notice-error">
			<ul class="ibg-error-list">
				<?php foreach ( $data['error']->get_error_messages() as $ibg_msg ) : ?>
					<li><?php echo esc_html( $ibg_msg ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<div class="ibg-form-layout ibg-campaign-layout">
		<div class="ibg-form-main">
			<form method="post" action="<?php echo esc_url( $ibg_form_url ); ?>">
				<?php wp_nonce_field( $data['nonce'] ); ?>
				<input type="hidden" name="ibg_action" value="save_campaign">

				<div class="ibg-card">
					<h2><?php esc_html_e( 'Message', 'ibg-client-outreach' ); ?></h2>
					<table class="form-table" role="presentation">
						<tbody>
						<tr>
							<th scope="row"><label for="ibg-c-name"><?php esc_html_e( 'Campaign Name', 'ibg-client-outreach' ); ?> <span class="ibg-required">*</span></label></th>
							<td><input type="text" class="regular-text" id="ibg-c-name" name="campaign[name]" value="<?php echo esc_attr( (string) $ibg_values['name'] ); ?>" required <?php echo esc_attr( $ibg_disabled ); ?>>
								<p class="description"><?php esc_html_e( 'Internal name, e.g. "Dental clinics – March intro".', 'ibg-client-outreach' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><label for="ibg-c-template"><?php esc_html_e( 'Template', 'ibg-client-outreach' ); ?> <span class="ibg-required">*</span></label></th>
							<td>
								<?php if ( $ibg_is_edit && $ibg_campaign->has_started() ) : ?>
									<strong><?php echo esc_html( $data['template_names'][ (int) $ibg_campaign->template_id ] ?? __( '(deleted template)', 'ibg-client-outreach' ) ); ?></strong>
									<p class="description"><?php esc_html_e( 'Content was snapshotted when the campaign started; later template edits do not affect it.', 'ibg-client-outreach' ); ?></p>
								<?php else : ?>
									<select id="ibg-c-template" name="campaign[template_id]" <?php echo esc_attr( $ibg_disabled ); ?>>
										<option value="0"><?php esc_html_e( '— Choose a template —', 'ibg-client-outreach' ); ?></option>
										<?php foreach ( $data['templates'] as $ibg_tid => $ibg_tname ) : ?>
											<option value="<?php echo (int) $ibg_tid; ?>" <?php selected( (int) $ibg_values['template_id'], $ibg_tid ); ?>><?php echo esc_html( $ibg_tname ); ?></option>
										<?php endforeach; ?>
									</select>
									<p class="description">
										<?php esc_html_e( 'Only active templates are listed.', 'ibg-client-outreach' ); ?>
										<a href="<?php echo esc_url( $data['templates_url'] ); ?>"><?php esc_html_e( 'Manage templates', 'ibg-client-outreach' ); ?></a>
									</p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="ibg-c-subject"><?php esc_html_e( 'Subject', 'ibg-client-outreach' ); ?></label></th>
							<td><input type="text" class="large-text" id="ibg-c-subject" name="campaign[subject]" value="<?php echo esc_attr( (string) $ibg_values['subject'] ); ?>" <?php echo esc_attr( $ibg_disabled ); ?>>
								<p class="description"><?php esc_html_e( 'Leave empty to use the template subject. Merge tags allowed.', 'ibg-client-outreach' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><label for="ibg-c-from_name"><?php esc_html_e( 'From Name', 'ibg-client-outreach' ); ?></label></th>
							<td><input type="text" class="regular-text" id="ibg-c-from_name" name="campaign[from_name]" value="<?php echo esc_attr( (string) $ibg_values['from_name'] ); ?>" <?php echo esc_attr( $ibg_disabled ); ?>></td>
						</tr>
						<tr>
							<th scope="row"><label for="ibg-c-from_email"><?php esc_html_e( 'From Email', 'ibg-client-outreach' ); ?></label></th>
							<td><input type="email" class="regular-text" id="ibg-c-from_email" name="campaign[from_email]" value="<?php echo esc_attr( (string) $ibg_values['from_email'] ); ?>" <?php echo esc_attr( $ibg_disabled ); ?>>
								<p class="description"><?php esc_html_e( 'Blank = default from Settings. Must be on a domain you control with SPF/DKIM.', 'ibg-client-outreach' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><label for="ibg-c-reply_to"><?php esc_html_e( 'Reply-To', 'ibg-client-outreach' ); ?></label></th>
							<td><input type="email" class="regular-text" id="ibg-c-reply_to" name="campaign[reply_to]" value="<?php echo esc_attr( (string) $ibg_values['reply_to'] ); ?>" <?php echo esc_attr( $ibg_disabled ); ?>></td>
						</tr>
						</tbody>
					</table>
				</div>

				<div class="ibg-card">
					<h2><?php esc_html_e( 'Audience', 'ibg-client-outreach' ); ?></h2>
					<table class="form-table" role="presentation">
						<tbody>
						<tr>
							<th scope="row"><label for="ibg-c-list"><?php esc_html_e( 'Target', 'ibg-client-outreach' ); ?></label></th>
							<td>
								<select id="ibg-c-list" name="campaign[list_id]" <?php echo esc_attr( $ibg_disabled ); ?>>
									<option value="0" <?php selected( (int) $ibg_values['list_id'], 0 ); ?>><?php esc_html_e( 'All contacts', 'ibg-client-outreach' ); ?></option>
									<?php if ( ! empty( $data['lists'] ) ) : ?>
										<optgroup label="<?php esc_attr_e( 'Lists', 'ibg-client-outreach' ); ?>">
											<?php foreach ( $data['lists'] as $ibg_l ) : ?>
												<option value="<?php echo (int) $ibg_l->id; ?>" <?php selected( (int) $ibg_values['list_id'], $ibg_l->id ); ?>><?php echo esc_html( $ibg_l->name ); ?></option>
											<?php endforeach; ?>
										</optgroup>
									<?php endif; ?>
									<?php if ( ! empty( $data['segments'] ) ) : ?>
										<optgroup label="<?php esc_attr_e( 'Segments', 'ibg-client-outreach' ); ?>">
											<?php foreach ( $data['segments'] as $ibg_l ) : ?>
												<option value="<?php echo (int) $ibg_l->id; ?>" <?php selected( (int) $ibg_values['list_id'], $ibg_l->id ); ?>><?php echo esc_html( $ibg_l->name ); ?></option>
											<?php endforeach; ?>
										</optgroup>
									<?php endif; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Consent scope', 'ibg-client-outreach' ); ?></th>
							<td>
								<fieldset>
									<?php foreach ( Campaign::scopes() as $ibg_scope => $ibg_label ) : ?>
										<label class="ibg-radio-row">
											<input type="radio" name="campaign[scope]" value="<?php echo esc_attr( $ibg_scope ); ?>" <?php checked( $ibg_values['scope'], $ibg_scope ); ?> <?php echo esc_attr( $ibg_disabled ); ?>>
											<?php echo esc_html( $ibg_label ); ?>
										</label>
									<?php endforeach; ?>
								</fieldset>
								<p class="description"><?php esc_html_e( 'Unsubscribed, Do Not Contact and invalid addresses are always excluded. Including Pending contacts means you assert a lawful basis (for example a B2B business relationship or legitimate interest) for each of them; record that basis on the contacts.', 'ibg-client-outreach' ); ?></p>
							</td>
						</tr>
						</tbody>
					</table>
				</div>

				<?php if ( $ibg_editable ) : ?>
					<p class="submit">
						<?php submit_button( $ibg_is_edit ? __( 'Save Draft', 'ibg-client-outreach' ) : __( 'Create Draft', 'ibg-client-outreach' ), 'primary', 'submit', false ); ?>
						<?php if ( $ibg_is_edit && '' !== $data['delete_url'] ) : ?>
							<a href="<?php echo esc_url( $data['delete_url'] ); ?>" class="button button-link-delete" data-ibg-confirm="<?php esc_attr_e( 'Delete this campaign?', 'ibg-client-outreach' ); ?>"><?php esc_html_e( 'Delete', 'ibg-client-outreach' ); ?></a>
						<?php endif; ?>
					</p>
				<?php endif; ?>
			</form>

			<?php if ( $ibg_is_edit && $ibg_campaign->has_started() ) : ?>
				<div class="ibg-card">
					<h2><?php esc_html_e( 'Progress', 'ibg-client-outreach' ); ?></h2>
					<div class="ibg-stat-grid">
						<div class="ibg-stat"><span class="ibg-stat-value"><?php echo esc_html( number_format_i18n( $ibg_campaign->total_recipients ) ); ?></span><span class="ibg-stat-label"><?php esc_html_e( 'Recipients', 'ibg-client-outreach' ); ?></span></div>
						<div class="ibg-stat"><span class="ibg-stat-value"><?php echo esc_html( number_format_i18n( $ibg_campaign->total_excluded ) ); ?></span><span class="ibg-stat-label"><?php esc_html_e( 'Excluded', 'ibg-client-outreach' ); ?></span></div>
						<div class="ibg-stat ibg-stat-ok"><span class="ibg-stat-value"><?php echo esc_html( number_format_i18n( $ibg_campaign->total_sent ) ); ?></span><span class="ibg-stat-label"><?php esc_html_e( 'Sent', 'ibg-client-outreach' ); ?></span></div>
						<div class="ibg-stat <?php echo $ibg_campaign->total_failed ? 'ibg-stat-error' : ''; ?>"><span class="ibg-stat-value"><?php echo esc_html( number_format_i18n( $ibg_campaign->total_failed ) ); ?></span><span class="ibg-stat-label"><?php esc_html_e( 'Failed', 'ibg-client-outreach' ); ?></span></div>
						<div class="ibg-stat"><span class="ibg-stat-value"><?php echo esc_html( number_format_i18n( $ibg_campaign->total_skipped ) ); ?></span><span class="ibg-stat-label"><?php esc_html_e( 'Skipped', 'ibg-client-outreach' ); ?></span></div>
					</div>
					<p class="description">
						<?php
						printf(
							/* translators: 1: started date, 2: completed date */
							esc_html__( 'Started %1$s · Finished %2$s', 'ibg-client-outreach' ),
							esc_html( Formatting::datetime( $ibg_campaign->started_at ) ),
							esc_html( Formatting::datetime( $ibg_campaign->completed_at ) )
						);
						?>
					</p>
				</div>
			<?php endif; ?>
		</div>

		<div class="ibg-form-side">
			<?php if ( $ibg_is_edit ) : ?>
				<div class="ibg-card">
					<h2><?php esc_html_e( 'Before sending', 'ibg-client-outreach' ); ?></h2>

					<?php if ( is_wp_error( $ibg_summary ) ) : ?>
						<p class="ibg-text-error"><?php echo esc_html( $ibg_summary->get_error_message() ); ?></p>
					<?php elseif ( is_array( $ibg_summary ) && ! $ibg_campaign->has_started() ) : ?>
						<dl class="ibg-meta ibg-preflight-numbers">
							<dt><?php esc_html_e( 'Recipients', 'ibg-client-outreach' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $ibg_summary['recipients'] ) ); ?></dd>
							<dt><?php esc_html_e( 'Excluded', 'ibg-client-outreach' ); ?></dt>
							<dd>
								<?php echo esc_html( number_format_i18n( $ibg_summary['excluded'] ) ); ?>
								<?php
								$ibg_bits = array();
								$ibg_b    = $ibg_summary['breakdown'];
								if ( $ibg_b['unsubscribed'] ) { $ibg_bits[] = sprintf( /* translators: %s: number */ __( '%s unsubscribed', 'ibg-client-outreach' ), number_format_i18n( $ibg_b['unsubscribed'] ) ); }
								if ( $ibg_b['do_not_contact'] ) { $ibg_bits[] = sprintf( /* translators: %s: number */ __( '%s do not contact', 'ibg-client-outreach' ), number_format_i18n( $ibg_b['do_not_contact'] ) ); }
								if ( $ibg_b['pending'] ) { $ibg_bits[] = sprintf( /* translators: %s: number */ __( '%s pending (not in scope)', 'ibg-client-outreach' ), number_format_i18n( $ibg_b['pending'] ) ); }
								if ( $ibg_b['invalid_email'] ) { $ibg_bits[] = sprintf( /* translators: %s: number */ __( '%s invalid email', 'ibg-client-outreach' ), number_format_i18n( $ibg_b['invalid_email'] ) ); }
								if ( $ibg_bits ) {
									echo '<br><span class="description">' . esc_html( implode( ' · ', $ibg_bits ) ) . '</span>';
								}
								?>
							</dd>
							<dt><strong><?php esc_html_e( 'Estimated sends', 'ibg-client-outreach' ); ?></strong></dt><dd><strong><?php echo esc_html( number_format_i18n( $ibg_summary['sends'] ) ); ?></strong></dd>
						</dl>
					<?php endif; ?>

					<ul class="ibg-checklist ibg-preflight">
						<?php foreach ( $data['preflight'] as $ibg_check ) : ?>
							<li class="<?php echo $ibg_check['ok'] ? 'is-ok' : ( $ibg_check['blocking'] ? 'is-error' : 'is-warning' ); ?>">
								<span class="dashicons <?php echo $ibg_check['ok'] ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>" aria-hidden="true"></span>
								<strong><?php echo esc_html( $ibg_check['label'] ); ?></strong>
								<span class="ibg-preflight-msg"><?php echo esc_html( $ibg_check['message'] ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
					<?php if ( ! $ibg_ready && ! $ibg_campaign->has_started() ) : ?>
						<p class="description"><a href="<?php echo esc_url( $data['settings_url'] ); ?>"><?php esc_html_e( 'Open Settings', 'ibg-client-outreach' ); ?></a></p>
					<?php endif; ?>
				</div>

				<?php if ( $data['can_send'] && ! $ibg_campaign->is_finished() ) : ?>
					<div class="ibg-card ibg-actions-card">
						<h2><?php esc_html_e( 'Actions', 'ibg-client-outreach' ); ?></h2>
						<form method="post" action="<?php echo esc_url( $ibg_form_url ); ?>">
							<?php wp_nonce_field( $data['state_nonce'] ); ?>
							<input type="hidden" name="ibg_action" value="campaign_state">

							<?php if ( $ibg_campaign->is_editable() ) : ?>
								<?php if ( Campaign::STATUS_SCHEDULED === $ibg_campaign->status ) : ?>
									<p>
										<?php
										printf(
											/* translators: %s: date */
											esc_html__( 'Scheduled for %s (site time).', 'ibg-client-outreach' ),
											'<strong>' . esc_html( Formatting::datetime( $ibg_campaign->scheduled_at ) ) . '</strong>'
										);
										?>
									</p>
									<p><button type="submit" name="transition" value="unschedule" class="button"><?php esc_html_e( 'Unschedule (back to draft)', 'ibg-client-outreach' ); ?></button></p>
								<?php endif; ?>

								<p>
									<label for="ibg-c-scheduled_at"><?php esc_html_e( 'Schedule for (site time)', 'ibg-client-outreach' ); ?></label><br>
									<input type="datetime-local" id="ibg-c-scheduled_at" name="scheduled_at" value="<?php echo esc_attr( $ibg_campaign->scheduled_at ? get_date_from_gmt( $ibg_campaign->scheduled_at, 'Y-m-d\TH:i' ) : '' ); ?>">
									<button type="submit" name="transition" value="schedule" class="button" <?php disabled( ! $ibg_ready ); ?>><?php echo Campaign::STATUS_SCHEDULED === $ibg_campaign->status ? esc_html__( 'Reschedule', 'ibg-client-outreach' ) : esc_html__( 'Schedule', 'ibg-client-outreach' ); ?></button>
								</p>
								<p>
									<button type="submit" name="transition" value="start" class="button button-primary button-hero" <?php disabled( ! $ibg_ready ); ?> data-ibg-confirm="<?php echo esc_attr( sprintf( /* translators: %s: number */ __( 'Start sending to %s contacts now?', 'ibg-client-outreach' ), is_array( $ibg_summary ) ? number_format_i18n( $ibg_summary['sends'] ) : '?' ) ); ?>"><?php esc_html_e( 'Start Campaign', 'ibg-client-outreach' ); ?></button>
								</p>
								<?php if ( ! $ibg_ready ) : ?>
									<p class="description"><?php esc_html_e( 'Resolve the items above to enable scheduling and sending.', 'ibg-client-outreach' ); ?></p>
								<?php endif; ?>
							<?php endif; ?>

							<?php if ( Campaign::STATUS_PROCESSING === $ibg_campaign->status ) : ?>
								<p><button type="submit" name="transition" value="pause" class="button"><?php esc_html_e( 'Pause Campaign', 'ibg-client-outreach' ); ?></button></p>
							<?php elseif ( Campaign::STATUS_PAUSED === $ibg_campaign->status ) : ?>
								<p><button type="submit" name="transition" value="resume" class="button button-primary"><?php esc_html_e( 'Resume Campaign', 'ibg-client-outreach' ); ?></button></p>
							<?php endif; ?>

							<?php if ( in_array( $ibg_campaign->status, array( Campaign::STATUS_SCHEDULED, Campaign::STATUS_PROCESSING, Campaign::STATUS_PAUSED ), true ) ) : ?>
								<p><button type="submit" name="transition" value="cancel" class="button button-link-delete" data-ibg-confirm="<?php esc_attr_e( 'Cancel this campaign? Unsent emails will be skipped. This cannot be undone.', 'ibg-client-outreach' ); ?>"><?php esc_html_e( 'Cancel Campaign', 'ibg-client-outreach' ); ?></button></p>
							<?php endif; ?>
						</form>
					</div>
				<?php endif; ?>

				<?php if ( $data['can_send'] ) : ?>
					<div class="ibg-card">
						<h2><?php esc_html_e( 'Send test email', 'ibg-client-outreach' ); ?></h2>
						<form method="post" action="<?php echo esc_url( $ibg_form_url ); ?>">
							<?php wp_nonce_field( $data['test_nonce'] ); ?>
							<input type="hidden" name="ibg_action" value="send_test">
							<p>
								<label for="ibg-test-email"><?php esc_html_e( 'Send to', 'ibg-client-outreach' ); ?></label><br>
								<input type="email" id="ibg-test-email" name="test_email" class="regular-text" value="<?php echo esc_attr( $data['test_email'] ); ?>" required>
							</p>
							<p>
								<label for="ibg-test-contact"><?php esc_html_e( 'Using data from', 'ibg-client-outreach' ); ?></label><br>
								<select id="ibg-test-contact" name="sample_contact">
									<option value="0"><?php esc_html_e( 'Synthetic sample', 'ibg-client-outreach' ); ?></option>
									<?php foreach ( $data['sample_contacts'] as $ibg_contact ) : ?>
										<option value="<?php echo (int) $ibg_contact->id; ?>"><?php echo esc_html( $ibg_contact->get_display_name() ); ?></option>
									<?php endforeach; ?>
								</select>
							</p>
							<p class="description"><?php esc_html_e( 'Uses the campaign subject and sender with the saved template (or the snapshot once started).', 'ibg-client-outreach' ); ?></p>
							<?php submit_button( __( 'Send test', 'ibg-client-outreach' ), 'secondary', 'submit', false ); ?>
						</form>
					</div>
				<?php endif; ?>
			<?php else : ?>
				<div class="ibg-card">
					<h2><?php esc_html_e( 'How it works', 'ibg-client-outreach' ); ?></h2>
					<ol class="ibg-steps">
						<li><?php esc_html_e( 'Create the draft with a template and audience.', 'ibg-client-outreach' ); ?></li>
						<li><?php esc_html_e( 'Review the recipient count and pre-flight checks.', 'ibg-client-outreach' ); ?></li>
						<li><?php esc_html_e( 'Send yourself a test.', 'ibg-client-outreach' ); ?></li>
						<li><?php esc_html_e( 'Schedule it or start it now. Emails go out in small batches.', 'ibg-client-outreach' ); ?></li>
					</ol>
				</div>
			<?php endif; ?>
		</div>
	</div>
</div>
