<?php
/**
 * Import step 1: upload + options.
 *
 * @var array{
 *   page: \IBG\Outreach\Admin\Pages\Import_Page,
 *   nonce: string,
 *   max_size: int,
 *   strategies: array<string, string>
 * } $data
 *
 * @package IBG\Outreach
 */

use IBG\Outreach\Contacts\Contact;

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap ibg-outreach-wrap">
	<h1><?php esc_html_e( 'Import Contacts', 'ibg-client-outreach' ); ?></h1>

	<p class="ibg-steps-indicator">
		<span class="ibg-step ibg-step-active">1. <?php esc_html_e( 'Upload', 'ibg-client-outreach' ); ?></span>
		<span class="ibg-step">2. <?php esc_html_e( 'Map columns', 'ibg-client-outreach' ); ?></span>
		<span class="ibg-step">3. <?php esc_html_e( 'Review & import', 'ibg-client-outreach' ); ?></span>
	</p>

	<div class="notice notice-info inline">
		<p>
			<?php esc_html_e( 'Importing a list does not create consent. Contacts are imported as "Pending" unless you confirm you hold explicit opt-in records. Addresses that previously unsubscribed keep their suppressed status automatically.', 'ibg-client-outreach' ); ?>
		</p>
	</div>

	<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( $data['page']->get_url() ); ?>" class="ibg-import-form">
		<?php wp_nonce_field( $data['nonce'] ); ?>
		<input type="hidden" name="ibg_action" value="upload">
		<input type="hidden" name="MAX_FILE_SIZE" value="<?php echo esc_attr( (string) $data['max_size'] ); ?>">

		<div class="ibg-card">
			<h2><?php esc_html_e( 'CSV file', 'ibg-client-outreach' ); ?></h2>

			<div class="ibg-dropzone" id="ibg-dropzone" tabindex="0">
				<p class="ibg-dropzone-text">
					<span class="dashicons dashicons-upload" aria-hidden="true"></span>
					<?php esc_html_e( 'Drag a CSV file here, or', 'ibg-client-outreach' ); ?>
					<label for="ibg-csv" class="ibg-dropzone-browse"><?php esc_html_e( 'choose a file', 'ibg-client-outreach' ); ?></label>
				</p>
				<input type="file" name="ibg_csv" id="ibg-csv" accept=".csv,text/csv,text/plain" required>
				<p class="ibg-dropzone-file" id="ibg-dropzone-file" hidden></p>
			</div>

			<p class="description">
				<?php
				printf(
					/* translators: %s: max file size */
					esc_html__( 'First row must be a header. UTF-8 recommended; comma, semicolon or tab delimiters are detected automatically. Maximum size: %s.', 'ibg-client-outreach' ),
					esc_html( size_format( $data['max_size'] ) )
				);
				?>
				<br>
				<code>first_name,last_name,company,email,website,phone,industry,source</code>
			</p>
		</div>

		<div class="ibg-card">
			<h2><?php esc_html_e( 'Defaults for new contacts', 'ibg-client-outreach' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
				<tr>
					<th scope="row"><label for="ibg-import-contact_status"><?php esc_html_e( 'Contact Status', 'ibg-client-outreach' ); ?></label></th>
					<td>
						<select name="contact_status" id="ibg-import-contact_status">
							<?php foreach ( Contact::contact_statuses() as $ibg_value => $ibg_label ) : ?>
								<option value="<?php echo esc_attr( $ibg_value ); ?>"><?php echo esc_html( $ibg_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Marketing Status', 'ibg-client-outreach' ); ?></th>
					<td>
						<fieldset>
							<label>
								<input type="radio" name="marketing_status" value="<?php echo esc_attr( Contact::MARKETING_PENDING ); ?>" checked>
								<strong><?php esc_html_e( 'Pending', 'ibg-client-outreach' ); ?></strong>
								— <?php esc_html_e( 'you have a business basis to contact them but no explicit opt-in (recommended).', 'ibg-client-outreach' ); ?>
							</label><br>
							<label>
								<input type="radio" name="marketing_status" value="<?php echo esc_attr( Contact::MARKETING_SUBSCRIBED ); ?>" id="ibg-import-subscribed">
								<strong><?php esc_html_e( 'Subscribed', 'ibg-client-outreach' ); ?></strong>
								— <?php esc_html_e( 'every address in this file explicitly opted in to marketing email.', 'ibg-client-outreach' ); ?>
							</label>
							<p id="ibg-import-confirm" class="ibg-inline-warning" hidden>
								<label>
									<input type="checkbox" name="confirm_consent" value="1">
									<?php esc_html_e( 'I confirm I hold explicit opt-in records (date, source, wording) for every contact in this file and can produce them on request.', 'ibg-client-outreach' ); ?>
								</label>
							</p>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Existing contacts are never changed by this setting. Suppressed addresses stay suppressed.', 'ibg-client-outreach' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ibg-import-consent_basis"><?php esc_html_e( 'Lawful Basis', 'ibg-client-outreach' ); ?></label></th>
					<td>
						<select name="consent_basis" id="ibg-import-consent_basis">
							<?php foreach ( Contact::consent_bases() as $ibg_value => $ibg_label ) : ?>
								<option value="<?php echo esc_attr( $ibg_value ); ?>"><?php echo esc_html( $ibg_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ibg-import-source"><?php esc_html_e( 'Default Source', 'ibg-client-outreach' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" name="source" id="ibg-import-source" placeholder="<?php esc_attr_e( 'e.g. Chamber of Commerce directory 2026', 'ibg-client-outreach' ); ?>">
						<p class="description"><?php esc_html_e( 'Used when the file has no Source column or the cell is empty. Record where the list came from.', 'ibg-client-outreach' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ibg-import-list_id"><?php esc_html_e( 'Add to List', 'ibg-client-outreach' ); ?></label></th>
					<td>
						<select name="list_id" id="ibg-import-list_id">
							<option value="0"><?php esc_html_e( '— None —', 'ibg-client-outreach' ); ?></option>
							<?php foreach ( $data['lists'] as $ibg_list ) : ?>
								<option value="<?php echo (int) $ibg_list->id; ?>"><?php echo esc_html( $ibg_list->name ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Every valid row in the file (new or already existing) is added to this list.', 'ibg-client-outreach' ); ?>
							<a href="<?php echo esc_url( add_query_arg( 'view', 'add', $data['lists_url'] ) ); ?>"><?php esc_html_e( 'Create a list', 'ibg-client-outreach' ); ?></a>
						</p>
					</td>
				</tr>
				</tbody>
			</table>
		</div>

		<div class="ibg-card">
			<h2><?php esc_html_e( 'Duplicates', 'ibg-client-outreach' ); ?></h2>
			<p><?php esc_html_e( 'When an email address in the file already exists in your contacts:', 'ibg-client-outreach' ); ?></p>
			<fieldset>
				<?php $ibg_first = true; ?>
				<?php foreach ( $data['strategies'] as $ibg_value => $ibg_label ) : ?>
					<label class="ibg-radio-row">
						<input type="radio" name="duplicate_strategy" value="<?php echo esc_attr( $ibg_value ); ?>" <?php checked( $ibg_first ); ?>>
						<?php echo esc_html( $ibg_label ); ?>
					</label>
					<?php $ibg_first = false; ?>
				<?php endforeach; ?>
			</fieldset>
			<p class="description"><?php esc_html_e( 'Duplicates within the file itself are always skipped (first occurrence wins). Marketing status and contact status of existing contacts are never changed by an import.', 'ibg-client-outreach' ); ?></p>
		</div>

		<?php submit_button( __( 'Upload and continue', 'ibg-client-outreach' ) ); ?>
	</form>
</div>
