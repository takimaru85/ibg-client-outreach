<?php
/**
 * Import step 2: column mapping.
 *
 * @var array{
 *   page: \IBG\Outreach\Admin\Pages\Import_Page,
 *   session: \IBG\Outreach\Import\Import_Session,
 *   nonce: string,
 *   cancel: string,
 *   fields: array<string, string>
 * } $data
 *
 * @package IBG\Outreach
 */

defined( 'ABSPATH' ) || exit;

$ibg_session = $data['session'];
$ibg_url     = $data['page']->get_url( array( 'token' => $ibg_session->token ) );
?>
<div class="wrap ibg-outreach-wrap">
	<h1><?php esc_html_e( 'Import Contacts', 'ibg-client-outreach' ); ?></h1>

	<p class="ibg-steps-indicator">
		<span class="ibg-step ibg-step-done">1. <?php esc_html_e( 'Upload', 'ibg-client-outreach' ); ?></span>
		<span class="ibg-step ibg-step-active">2. <?php esc_html_e( 'Map columns', 'ibg-client-outreach' ); ?></span>
		<span class="ibg-step">3. <?php esc_html_e( 'Review & import', 'ibg-client-outreach' ); ?></span>
	</p>

	<p>
		<?php
		printf(
			/* translators: 1: file name, 2: number of columns */
			esc_html__( 'File: %1$s — %2$d columns detected. Match each CSV column to a contact field, or leave it as "Ignore".', 'ibg-client-outreach' ),
			'<strong>' . esc_html( $ibg_session->original_name ) . '</strong>',
			count( $ibg_session->headers )
		);
		?>
	</p>

	<form method="post" action="<?php echo esc_url( $ibg_url ); ?>">
		<?php wp_nonce_field( $data['nonce'] ); ?>
		<input type="hidden" name="ibg_action" value="map">

		<table class="widefat striped ibg-mapping-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'CSV column', 'ibg-client-outreach' ); ?></th>
					<th><?php esc_html_e( 'Sample values', 'ibg-client-outreach' ); ?></th>
					<th><?php esc_html_e( 'Import as', 'ibg-client-outreach' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $ibg_session->headers as $ibg_index => $ibg_header ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $ibg_header ); ?></strong></td>
					<td class="ibg-samples">
						<?php
						$ibg_values = array();
						foreach ( $ibg_session->samples as $ibg_row ) {
							$ibg_cell = trim( (string) ( $ibg_row[ $ibg_index ] ?? '' ) );
							if ( '' !== $ibg_cell ) {
								$ibg_values[] = mb_substr( $ibg_cell, 0, 40 );
							}
						}
						echo esc_html( $ibg_values ? implode( ' · ', $ibg_values ) : '—' );
						?>
					</td>
					<td>
						<label class="screen-reader-text" for="ibg-map-<?php echo (int) $ibg_index; ?>"><?php echo esc_html( $ibg_header ); ?></label>
						<select name="mapping[<?php echo (int) $ibg_index; ?>]" id="ibg-map-<?php echo (int) $ibg_index; ?>">
							<option value=""><?php esc_html_e( '— Ignore —', 'ibg-client-outreach' ); ?></option>
							<?php foreach ( $data['fields'] as $ibg_field => $ibg_label ) : ?>
								<option value="<?php echo esc_attr( $ibg_field ); ?>" <?php selected( $ibg_session->mapping[ $ibg_index ] ?? '', $ibg_field ); ?>><?php echo esc_html( $ibg_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<p class="description"><?php esc_html_e( 'Each field can be used once. Email is required. Marketing status, contact status and lawful basis come from the defaults you chose in step 1, never from the file.', 'ibg-client-outreach' ); ?></p>

		<p class="submit">
			<?php submit_button( __( 'Analyse file', 'ibg-client-outreach' ), 'primary', 'submit', false ); ?>
		</p>
	</form>

	<form method="post" action="<?php echo esc_url( $ibg_url ); ?>" class="ibg-inline-form">
		<?php wp_nonce_field( $data['cancel'] ); ?>
		<input type="hidden" name="ibg_action" value="cancel">
		<button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'Cancel and delete uploaded file', 'ibg-client-outreach' ); ?></button>
	</form>
</div>
