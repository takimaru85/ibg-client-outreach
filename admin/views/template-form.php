<?php
/**
 * Template add/edit form with merge-tag legend, preview and test send.
 *
 * @var array{
 *   page: \IBG\Outreach\Admin\Pages\Templates_Page,
 *   template: \IBG\Outreach\Templates\Email_Template|null,
 *   values: array<string, mixed>,
 *   error: \WP_Error|null,
 *   nonce: string,
 *   test_nonce: string,
 *   tag_groups: array<string, array<string, string>>,
 *   sample_contacts: \IBG\Outreach\Contacts\Contact[],
 *   test_email: string,
 *   can_send: bool,
 *   list_url: string,
 *   preview_url: string
 * } $data
 *
 * @package IBG\Outreach
 */

defined( 'ABSPATH' ) || exit;

$ibg_template = $data['template'];
$ibg_values   = $data['values'];
$ibg_is_edit  = null !== $ibg_template;
$ibg_form_url = $ibg_is_edit
	? $data['page']->get_url( array( 'view' => 'edit', 'id' => $ibg_template->id ) )
	: $data['page']->get_url( array( 'view' => 'add' ) );

$ibg_group_labels = array(
	'contact'  => __( 'Contact', 'ibg-client-outreach' ),
	'business' => __( 'Your business', 'ibg-client-outreach' ),
	'links'    => __( 'Links', 'ibg-client-outreach' ),
	'other'    => __( 'Other', 'ibg-client-outreach' ),
);
?>
<div class="wrap ibg-outreach-wrap">
	<h1 class="wp-heading-inline">
		<?php echo $ibg_is_edit ? esc_html__( 'Edit Template', 'ibg-client-outreach' ) : esc_html__( 'Add Template', 'ibg-client-outreach' ); ?>
	</h1>
	<a href="<?php echo esc_url( $data['list_url'] ); ?>" class="page-title-action"><?php esc_html_e( 'Back to Templates', 'ibg-client-outreach' ); ?></a>
	<hr class="wp-header-end">

	<?php if ( $data['error'] instanceof WP_Error ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $data['error']->get_error_message() ); ?></p></div>
	<?php endif; ?>

	<div class="ibg-form-layout ibg-template-layout">
		<div class="ibg-form-main">
			<form method="post" action="<?php echo esc_url( $ibg_form_url ); ?>" id="ibg-template-form">
				<?php wp_nonce_field( $data['nonce'] ); ?>
				<input type="hidden" name="ibg_action" value="save_template">

				<div class="ibg-card">
					<table class="form-table" role="presentation">
						<tbody>
						<tr>
							<th scope="row"><label for="ibg-template-name"><?php esc_html_e( 'Template Name', 'ibg-client-outreach' ); ?> <span class="ibg-required">*</span></label></th>
							<td>
								<input type="text" class="regular-text" id="ibg-template-name" name="template[name]" value="<?php echo esc_attr( (string) $ibg_values['name'] ); ?>" required>
								<p class="description"><?php esc_html_e( 'Internal name; recipients never see it.', 'ibg-client-outreach' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="ibg-template-subject"><?php esc_html_e( 'Subject', 'ibg-client-outreach' ); ?> <span class="ibg-required">*</span></label></th>
							<td>
								<input type="text" class="large-text ibg-tag-target" id="ibg-template-subject" name="template[subject]" value="<?php echo esc_attr( (string) $ibg_values['subject'] ); ?>" required>
								<p class="description"><?php esc_html_e( 'Merge tags work here too, e.g. "Website help for {{company|your business}}".', 'ibg-client-outreach' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Status', 'ibg-client-outreach' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="template[is_active]" value="1" <?php checked( ! empty( $ibg_values['is_active'] ) ); ?>>
									<?php esc_html_e( 'Active (available to campaigns)', 'ibg-client-outreach' ); ?>
								</label>
							</td>
						</tr>
						</tbody>
					</table>
				</div>

				<div class="ibg-card">
					<h2><?php esc_html_e( 'HTML body', 'ibg-client-outreach' ); ?></h2>
					<?php
					wp_editor(
						(string) $ibg_values['body_html'],
						'ibg-template-body',
						array(
							'textarea_name' => 'template[body_html]',
							'textarea_rows' => 18,
							'media_buttons' => false,
							'wpautop'       => false,
							'quicktags'     => true,
							'tinymce'       => array(
								'toolbar1'                     => 'formatselect,bold,italic,underline,bullist,numlist,link,unlink,alignleft,aligncenter,removeformat,undo,redo,code',
								'toolbar2'                     => '',
								'convert_urls'                 => false,
								'forced_root_block'            => 'p',
								'remove_linebreaks'            => false,
								'paste_as_text'                => true,
								'wpautop'                      => false,
							),
						)
					);
					?>
					<p class="description"><?php esc_html_e( 'Write the message only. Sender identification, your postal address, the reason for contact and the unsubscribe link are appended from Settings → Compliance on every send.', 'ibg-client-outreach' ); ?></p>
				</div>

				<div class="ibg-card">
					<h2><?php esc_html_e( 'Plain-text version', 'ibg-client-outreach' ); ?></h2>
					<textarea id="ibg-template-text" name="template[body_text]" rows="10" class="large-text code ibg-tag-target"><?php echo esc_textarea( (string) $ibg_values['body_text'] ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'Sent as the text/plain alternative for clients that block HTML; also improves spam scores. Leave empty to generate it from the HTML on save.', 'ibg-client-outreach' ); ?>
					</p>
					<label>
						<input type="checkbox" name="template[regenerate_text]" value="1">
						<?php esc_html_e( 'Regenerate from HTML on save', 'ibg-client-outreach' ); ?>
					</label>
				</div>

				<p class="submit">
					<?php submit_button( $ibg_is_edit ? __( 'Update Template', 'ibg-client-outreach' ) : __( 'Create Template', 'ibg-client-outreach' ), 'primary', 'submit', false ); ?>
					<?php if ( $ibg_is_edit ) : ?>
						<a href="<?php echo esc_url( $data['preview_url'] ); ?>" class="button" target="_blank" rel="noopener"><?php esc_html_e( 'Open preview in new tab', 'ibg-client-outreach' ); ?></a>
					<?php endif; ?>
				</p>
			</form>

			<?php if ( $ibg_is_edit ) : ?>
				<div class="ibg-card" id="ibg-preview-card">
					<h2><?php esc_html_e( 'Preview', 'ibg-client-outreach' ); ?></h2>
					<p>
						<label for="ibg-preview-contact"><?php esc_html_e( 'Sample data:', 'ibg-client-outreach' ); ?></label>
						<select id="ibg-preview-contact" data-preview-base="<?php echo esc_url( $data['preview_url'] ); ?>">
							<option value="0"><?php esc_html_e( 'Synthetic sample (Jane Doe, Acme Dental)', 'ibg-client-outreach' ); ?></option>
							<?php foreach ( $data['sample_contacts'] as $ibg_contact ) : ?>
								<option value="<?php echo (int) $ibg_contact->id; ?>"><?php echo esc_html( $ibg_contact->get_display_name() . ' <' . $ibg_contact->email . '>' ); ?></option>
							<?php endforeach; ?>
						</select>
						<button type="button" class="button" id="ibg-preview-toggle" data-format="html"><?php esc_html_e( 'Show plain text', 'ibg-client-outreach' ); ?></button>
					</p>
					<iframe id="ibg-preview-frame" title="<?php esc_attr_e( 'Email preview', 'ibg-client-outreach' ); ?>" src="<?php echo esc_url( $data['preview_url'] ); ?>" sandbox="allow-same-origin"></iframe>
					<p class="description"><?php esc_html_e( 'Preview reflects the saved version. Save first to see changes.', 'ibg-client-outreach' ); ?></p>
				</div>
			<?php endif; ?>
		</div>

		<div class="ibg-form-side">
			<div class="ibg-card">
				<h2><?php esc_html_e( 'Merge tags', 'ibg-client-outreach' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Click a tag to insert it at the cursor. Add a fallback with a pipe: {{first_name|there}}.', 'ibg-client-outreach' ); ?></p>
				<?php foreach ( $data['tag_groups'] as $ibg_group => $ibg_tags ) : ?>
					<h3 class="ibg-tag-group"><?php echo esc_html( $ibg_group_labels[ $ibg_group ] ?? ucfirst( $ibg_group ) ); ?></h3>
					<ul class="ibg-tag-list">
						<?php foreach ( $ibg_tags as $ibg_tag => $ibg_label ) : ?>
							<li>
								<button type="button" class="button-link ibg-tag-insert" data-tag="{{<?php echo esc_attr( $ibg_tag ); ?>}}" title="<?php echo esc_attr( $ibg_label ); ?>"><code>{{<?php echo esc_html( $ibg_tag ); ?>}}</code></button>
								<span class="ibg-tag-label"><?php echo esc_html( $ibg_label ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endforeach; ?>
			</div>

			<?php if ( $ibg_is_edit && $data['can_send'] ) : ?>
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
						<p class="description"><?php esc_html_e( 'Sent through the active provider using the saved template. The subject is prefixed with [TEST].', 'ibg-client-outreach' ); ?></p>
						<?php submit_button( __( 'Send test', 'ibg-client-outreach' ), 'secondary', 'submit', false ); ?>
					</form>
				</div>
			<?php endif; ?>
		</div>
	</div>
</div>
