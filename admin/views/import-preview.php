<?php
/**
 * Import step 3: analysis summary, run, result.
 *
 * @var array{
 *   page: \IBG\Outreach\Admin\Pages\Import_Page,
 *   session: \IBG\Outreach\Import\Import_Session,
 *   cancel: string,
 *   strategies: array<string, string>,
 *   contacts_url: string
 * } $data
 *
 * @package IBG\Outreach
 */

use IBG\Outreach\Contacts\Contact;

defined( 'ABSPATH' ) || exit;

$ibg_session  = $data['session'];
$ibg_a        = $ibg_session->analysis;
$ibg_p        = $ibg_session->progress;
$ibg_done     = $ibg_session->is_done();
$ibg_started  = (int) ( $ibg_p['processed'] ?? 0 ) > 0;
$ibg_url      = $data['page']->get_url( array( 'token' => $ibg_session->token ) );
$ibg_options  = $ibg_session->options;
$ibg_expected = (int) $ibg_a['to_create'] + (int) $ibg_a['to_update'];

$ibg_stats = array(
	array( __( 'Rows in file', 'ibg-client-outreach' ), $ibg_a['total'], '' ),
	array( __( 'Valid', 'ibg-client-outreach' ), $ibg_a['valid'], 'ok' ),
	array( __( 'Invalid', 'ibg-client-outreach' ), $ibg_a['invalid'], $ibg_a['invalid'] ? 'error' : '' ),
	array( __( 'Duplicates in file', 'ibg-client-outreach' ), $ibg_a['duplicates'], $ibg_a['duplicates'] ? 'warning' : '' ),
	array( __( 'Already in contacts', 'ibg-client-outreach' ), $ibg_a['existing'], '' ),
	array( __( 'Previously unsubscribed', 'ibg-client-outreach' ), $ibg_a['suppressed_new'], $ibg_a['suppressed_new'] ? 'warning' : '' ),
);
?>
<div class="wrap ibg-outreach-wrap">
	<h1><?php esc_html_e( 'Import Contacts', 'ibg-client-outreach' ); ?></h1>

	<p class="ibg-steps-indicator">
		<span class="ibg-step ibg-step-done">1. <?php esc_html_e( 'Upload', 'ibg-client-outreach' ); ?></span>
		<span class="ibg-step ibg-step-done">2. <?php esc_html_e( 'Map columns', 'ibg-client-outreach' ); ?></span>
		<span class="ibg-step ibg-step-active">3. <?php esc_html_e( 'Review & import', 'ibg-client-outreach' ); ?></span>
	</p>

	<div class="ibg-card">
		<h2><?php echo esc_html( $ibg_session->original_name ); ?></h2>

		<div class="ibg-stat-grid">
			<?php foreach ( $ibg_stats as list( $ibg_label, $ibg_value, $ibg_state ) ) : ?>
				<div class="ibg-stat <?php echo $ibg_state ? esc_attr( 'ibg-stat-' . $ibg_state ) : ''; ?>">
					<span class="ibg-stat-value"><?php echo esc_html( number_format_i18n( (int) $ibg_value ) ); ?></span>
					<span class="ibg-stat-label"><?php echo esc_html( $ibg_label ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>

		<table class="widefat ibg-plan-table">
			<tbody>
				<tr>
					<th><?php esc_html_e( 'Will be created', 'ibg-client-outreach' ); ?></th>
					<td>
						<strong><?php echo esc_html( number_format_i18n( (int) $ibg_a['to_create'] ) ); ?></strong>
						<?php
						printf(
							/* translators: 1: contact status, 2: marketing status */
							esc_html__( 'as %1$s / %2$s', 'ibg-client-outreach' ),
							esc_html( Contact::label( Contact::contact_statuses(), (string) $ibg_options['contact_status'] ) ),
							esc_html( Contact::label( Contact::marketing_statuses(), (string) $ibg_options['marketing_status'] ) )
						);
						if ( (int) $ibg_a['suppressed_new'] > 0 ) {
							printf(
								' — <em>%s</em>',
								esc_html(
									sprintf(
										/* translators: %d: number of contacts */
										_n( '%d of these previously unsubscribed and will be created as Unsubscribed.', '%d of these previously unsubscribed and will be created as Unsubscribed.', (int) $ibg_a['suppressed_new'], 'ibg-client-outreach' ),
										(int) $ibg_a['suppressed_new']
									)
								)
							);
						}
						?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Will be updated', 'ibg-client-outreach' ); ?></th>
					<td>
						<strong><?php echo esc_html( number_format_i18n( (int) $ibg_a['to_update'] ) ); ?></strong>
						<span class="description"><?php echo esc_html( $data['strategies'][ $ibg_options['duplicate_strategy'] ] ?? '' ); ?></span>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Will be skipped', 'ibg-client-outreach' ); ?></th>
					<td>
						<strong><?php echo esc_html( number_format_i18n( (int) $ibg_a['invalid'] + (int) $ibg_a['duplicates'] + (int) $ibg_a['to_skip'] ) ); ?></strong>
						<span class="description"><?php esc_html_e( 'invalid emails, duplicates within the file, and existing contacts when the strategy is "Skip".', 'ibg-client-outreach' ); ?></span>
					</td>
				</tr>
			</tbody>
		</table>

		<?php if ( ! empty( $ibg_a['samples'] ) ) : ?>
			<details class="ibg-details">
				<summary><?php esc_html_e( 'Show skipped rows (first 20)', 'ibg-client-outreach' ); ?></summary>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Row', 'ibg-client-outreach' ); ?></th><th><?php esc_html_e( 'Email cell', 'ibg-client-outreach' ); ?></th><th><?php esc_html_e( 'Reason', 'ibg-client-outreach' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $ibg_a['samples'] as $ibg_sample ) : ?>
						<tr>
							<td><?php echo (int) $ibg_sample['row']; ?></td>
							<td><code><?php echo esc_html( $ibg_sample['email'] ); ?></code></td>
							<td><?php echo esc_html( $ibg_sample['reason'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</details>
		<?php endif; ?>
	</div>

	<div class="ibg-card" id="ibg-import-run" data-total="<?php echo esc_attr( (string) $ibg_a['total'] ); ?>" data-done="<?php echo $ibg_done ? '1' : '0'; ?>">
		<h2><?php esc_html_e( 'Import', 'ibg-client-outreach' ); ?></h2>

		<div id="ibg-import-controls" <?php echo $ibg_done ? 'hidden' : ''; ?>>
			<?php if ( 0 === $ibg_expected ) : ?>
				<p><?php esc_html_e( 'Nothing to import: no new or updatable contacts were found in this file.', 'ibg-client-outreach' ); ?></p>
			<?php else : ?>
				<p>
					<button type="button" class="button button-primary button-hero" id="ibg-import-start">
						<?php
						echo esc_html(
							$ibg_started
								? __( 'Resume import', 'ibg-client-outreach' )
								: sprintf(
									/* translators: %s: number of contacts */
									__( 'Import %s contacts', 'ibg-client-outreach' ),
									number_format_i18n( $ibg_expected )
								)
						);
						?>
					</button>
				</p>
				<p class="description"><?php esc_html_e( 'Rows are processed in small batches. Keep this tab open until it finishes; you can resume if it is interrupted.', 'ibg-client-outreach' ); ?></p>
			<?php endif; ?>
		</div>

		<div id="ibg-import-progress" <?php echo $ibg_started && ! $ibg_done ? '' : 'hidden'; ?>>
			<div class="ibg-progress"><div class="ibg-progress-bar" id="ibg-import-bar" style="width: <?php echo esc_attr( (string) ( $ibg_a['total'] ? round( 100 * (int) $ibg_p['processed'] / (int) $ibg_a['total'] ) : 0 ) ); ?>%"></div></div>
			<p id="ibg-import-status" aria-live="polite"></p>
		</div>

		<div id="ibg-import-result" <?php echo $ibg_done ? '' : 'hidden'; ?>>
			<h3 id="ibg-import-result-title"><?php echo $ibg_done ? esc_html__( 'Import complete.', 'ibg-client-outreach' ) : ''; ?></h3>
			<table class="widefat ibg-plan-table">
				<tbody>
					<tr><th><?php esc_html_e( 'Created', 'ibg-client-outreach' ); ?></th><td id="ibg-r-created"><?php echo (int) $ibg_p['created']; ?></td></tr>
					<tr><th><?php esc_html_e( 'Updated', 'ibg-client-outreach' ); ?></th><td id="ibg-r-updated"><?php echo (int) $ibg_p['updated']; ?></td></tr>
					<tr><th><?php esc_html_e( 'Skipped (invalid / duplicate in file)', 'ibg-client-outreach' ); ?></th><td id="ibg-r-skipped_invalid"><?php echo (int) $ibg_p['skipped_invalid']; ?></td></tr>
					<tr><th><?php esc_html_e( 'Skipped (existing)', 'ibg-client-outreach' ); ?></th><td id="ibg-r-skipped_existing"><?php echo (int) $ibg_p['skipped_existing']; ?></td></tr>
					<tr><th><?php esc_html_e( 'Suppression preserved', 'ibg-client-outreach' ); ?></th><td id="ibg-r-preserved"><?php echo (int) $ibg_p['preserved']; ?></td></tr>
					<tr><th><?php esc_html_e( 'Failed', 'ibg-client-outreach' ); ?></th><td id="ibg-r-failed"><?php echo (int) $ibg_p['failed']; ?></td></tr>
					<?php if ( ! empty( $ibg_options['list_id'] ) ) : ?>
						<tr><th><?php esc_html_e( 'Added to list', 'ibg-client-outreach' ); ?></th><td id="ibg-r-listed"><?php echo (int) ( $ibg_p['listed'] ?? 0 ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>
			<ul id="ibg-import-errors" class="ibg-error-list">
				<?php foreach ( (array) $ibg_p['errors'] as $ibg_error ) : ?>
					<li><?php echo esc_html( sprintf( '#%d %s — %s', (int) $ibg_error['row'], (string) $ibg_error['email'], (string) $ibg_error['message'] ) ); ?></li>
				<?php endforeach; ?>
			</ul>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $data['contacts_url'] ); ?>"><?php esc_html_e( 'View contacts', 'ibg-client-outreach' ); ?></a>
				<a class="button" href="<?php echo esc_url( $data['page']->get_url() ); ?>"><?php esc_html_e( 'Import another file', 'ibg-client-outreach' ); ?></a>
			</p>
		</div>
	</div>

	<?php if ( ! $ibg_done ) : ?>
		<form method="post" action="<?php echo esc_url( $ibg_url ); ?>" class="ibg-inline-form" id="ibg-import-cancel">
			<?php wp_nonce_field( $data['cancel'] ); ?>
			<input type="hidden" name="ibg_action" value="cancel">
			<button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'Cancel and delete uploaded file', 'ibg-client-outreach' ); ?></button>
		</form>
	<?php endif; ?>
</div>
