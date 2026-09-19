<?php
/**
 * Dashboard view.
 *
 * @var array{
 *   status: array<int, array{label:string, value:string, state:string, help:string}>,
 *   has_missing_tables: bool,
 *   repair_url: string,
 *   can_repair: bool,
 *   settings_url: string,
 *   compliance_dismissed: bool,
 *   compliance_dismiss: string
 * } $data
 *
 * @package IBG\Outreach
 */

defined( 'ABSPATH' ) || exit;

$ibg_state_labels = array(
	'ok'      => __( 'OK', 'ibg-client-outreach' ),
	'warning' => __( 'Attention', 'ibg-client-outreach' ),
	'error'   => __( 'Action required', 'ibg-client-outreach' ),
	'info'    => __( 'Info', 'ibg-client-outreach' ),
);
?>
<div class="wrap ibg-outreach-wrap">
	<h1><?php esc_html_e( 'IBG Client Outreach', 'ibg-client-outreach' ); ?></h1>

	<?php if ( ! $data['compliance_dismissed'] ) : ?>
		<div class="notice notice-warning ibg-notice-compliance">
			<p>
				<strong><?php esc_html_e( 'Your responsibility as sender', 'ibg-client-outreach' ); ?></strong><br>
				<?php esc_html_e( 'This plugin sends email on your behalf. You are responsible for ensuring you have a lawful basis to contact every recipient, for identifying yourself and your business in each message, and for honouring unsubscribe requests. Applicable rules may include GDPR and PECR (EU/UK), CAN-SPAM (US), CASL (Canada) and your email provider\'s acceptable-use policy. Imported contacts are never treated as subscribed automatically.', 'ibg-client-outreach' ); ?>
			</p>
			<p>
				<a class="button button-secondary" href="<?php echo esc_url( $data['compliance_dismiss'] ); ?>"><?php esc_html_e( 'I understand', 'ibg-client-outreach' ); ?></a>
			</p>
		</div>
	<?php endif; ?>

	<div class="ibg-grid">
		<div class="ibg-card">
			<h2><?php esc_html_e( 'System status', 'ibg-client-outreach' ); ?></h2>
			<table class="widefat striped ibg-status-table">
				<tbody>
				<?php foreach ( $data['status'] as $row ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $row['label'] ); ?></th>
						<td>
							<span class="ibg-badge ibg-badge-<?php echo esc_attr( $row['state'] ); ?>"><?php echo esc_html( $ibg_state_labels[ $row['state'] ] ?? $row['state'] ); ?></span>
							<span class="ibg-status-value"><?php echo esc_html( $row['value'] ); ?></span>
							<?php if ( '' !== $row['help'] ) : ?>
								<p class="description"><?php echo esc_html( $row['help'] ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $data['can_repair'] ) : ?>
				<p>
					<a class="button <?php echo $data['has_missing_tables'] ? 'button-primary' : 'button-secondary'; ?>" href="<?php echo esc_url( $data['repair_url'] ); ?>">
						<?php esc_html_e( 'Verify / repair database tables', 'ibg-client-outreach' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>

		<div class="ibg-card">
			<h2><?php esc_html_e( 'Getting started', 'ibg-client-outreach' ); ?></h2>
			<ol class="ibg-steps">
				<li>
					<a href="<?php echo esc_url( $data['settings_url'] ); ?>"><?php esc_html_e( 'Set your sender identity', 'ibg-client-outreach' ); ?></a>
					— <?php esc_html_e( 'business name, From name and From email on a domain you control.', 'ibg-client-outreach' ); ?>
				</li>
				<li>
					<a href="<?php echo esc_url( add_query_arg( 'tab', 'compliance', $data['settings_url'] ) ); ?>"><?php esc_html_e( 'Complete the Compliance tab', 'ibg-client-outreach' ); ?></a>
					— <?php esc_html_e( 'postal address, why recipients are hearing from you, and your footer.', 'ibg-client-outreach' ); ?>
				</li>
				<li>
					<a href="<?php echo esc_url( add_query_arg( 'tab', 'email', $data['settings_url'] ) ); ?>"><?php esc_html_e( 'Choose a sending provider and batch size', 'ibg-client-outreach' ); ?></a>
					— <?php esc_html_e( 'keep batches small on shared hosting.', 'ibg-client-outreach' ); ?>
				</li>
				<li><?php esc_html_e( 'Configure SPF, DKIM and DMARC DNS records for your sending domain.', 'ibg-client-outreach' ); ?></li>
				<li><?php esc_html_e( 'Import or add contacts, then build a list and a template.', 'ibg-client-outreach' ); ?></li>
			</ol>
		</div>
	</div>
</div>
