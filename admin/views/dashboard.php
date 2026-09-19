<?php
/**
 * Dashboard view: statistics, activity, system status.
 *
 * @var array{
 *   contacts: array<string, int>, campaigns: array<string, int>, emails: array<string, int>,
 *   daily: array<string, int>, activity: array<int, object>, queue: array<string, int>,
 *   urls: array<string, string>,
 *   status: array<int, array{label:string, value:string, state:string, help:string}>,
 *   has_missing_tables: bool, repair_url: string, can_repair: bool, settings_url: string,
 *   compliance_dismissed: bool, compliance_dismiss: string
 * } $data
 *
 * @package IBG\Outreach
 */

use IBG\Outreach\Formatting;

defined( 'ABSPATH' ) || exit;

$ibg_c = $data['contacts'];
$ibg_k = $data['campaigns'];
$ibg_e = $data['emails'];

$ibg_state_labels = array(
	'ok'      => __( 'OK', 'ibg-client-outreach' ),
	'warning' => __( 'Attention', 'ibg-client-outreach' ),
	'error'   => __( 'Action required', 'ibg-client-outreach' ),
	'info'    => __( 'Info', 'ibg-client-outreach' ),
);

$ibg_activity_labels = array(
	'campaign.started'      => __( 'Campaign started', 'ibg-client-outreach' ),
	'campaign.completed'    => __( 'Campaign completed', 'ibg-client-outreach' ),
	'campaign.cancelled'    => __( 'Campaign cancelled', 'ibg-client-outreach' ),
	'campaign.scheduled'    => __( 'Campaign scheduled', 'ibg-client-outreach' ),
	'campaign.start_failed' => __( 'Scheduled campaign could not start', 'ibg-client-outreach' ),
	'contact.unsubscribed'  => __( 'Unsubscribed', 'ibg-client-outreach' ),
	'contact.resubscribed'  => __( 'Resubscribed', 'ibg-client-outreach' ),
	'email.bounced'         => __( 'Hard bounce', 'ibg-client-outreach' ),
	'email.complained'      => __( 'Spam complaint', 'ibg-client-outreach' ),
	'import.completed'      => __( 'Import completed', 'ibg-client-outreach' ),
);

$ibg_max_daily = max( 1, max( $data['daily'] ) );
$ibg_has_issue = false;
foreach ( $data['status'] as $ibg_row ) {
	if ( 'error' === $ibg_row['state'] ) {
		$ibg_has_issue = true;
	}
}

/**
 * Render one stat tile.
 *
 * @param string $label Label.
 * @param int    $value Value.
 * @param string $url   Link.
 * @param string $class Extra class.
 * @return void
 */
$ibg_tile = static function ( string $label, int $value, string $url = '', string $class = '' ): void {
	$inner = '<span class="ibg-stat-value">' . esc_html( number_format_i18n( $value ) ) . '</span><span class="ibg-stat-label">' . esc_html( $label ) . '</span>';
	if ( '' !== $url ) {
		printf( '<a class="ibg-stat ibg-stat-link %s" href="%s">%s</a>', esc_attr( $class ), esc_url( $url ), $inner ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
	} else {
		printf( '<div class="ibg-stat %s">%s</div>', esc_attr( $class ), $inner ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
	}
};
?>
<div class="wrap ibg-outreach-wrap">
	<h1><?php esc_html_e( 'IBG Client Outreach', 'ibg-client-outreach' ); ?></h1>

	<?php if ( ! $data['compliance_dismissed'] ) : ?>
		<div class="notice notice-warning ibg-notice-compliance">
			<p>
				<strong><?php esc_html_e( 'Your responsibility as sender', 'ibg-client-outreach' ); ?></strong><br>
				<?php esc_html_e( 'This plugin sends email on your behalf. You are responsible for ensuring you have a lawful basis to contact every recipient, for identifying yourself and your business in each message, and for honouring unsubscribe requests. Applicable rules may include GDPR and PECR (EU/UK), CAN-SPAM (US), CASL (Canada) and your email provider\'s acceptable-use policy. Imported contacts are never treated as subscribed automatically.', 'ibg-client-outreach' ); ?>
			</p>
			<p><a class="button button-secondary" href="<?php echo esc_url( $data['compliance_dismiss'] ); ?>"><?php esc_html_e( 'I understand', 'ibg-client-outreach' ); ?></a></p>
		</div>
	<?php endif; ?>

	<?php if ( $ibg_has_issue ) : ?>
		<div class="notice notice-error inline"><p><?php esc_html_e( 'Some setup items need attention before campaigns can be sent. See System status below.', 'ibg-client-outreach' ); ?></p></div>
	<?php endif; ?>

	<h2 class="ibg-section-title"><?php esc_html_e( 'Contacts', 'ibg-client-outreach' ); ?></h2>
	<div class="ibg-stat-grid ibg-dashboard-grid">
		<?php
		$ibg_tile( __( 'Total contacts', 'ibg-client-outreach' ), $ibg_c['total'], $data['urls']['contacts'] );
		$ibg_tile( __( 'Leads', 'ibg-client-outreach' ), $ibg_c['leads'], add_query_arg( 'contact_status', 'lead', $data['urls']['contacts'] ) );
		$ibg_tile( __( 'Customers', 'ibg-client-outreach' ), $ibg_c['customers'], add_query_arg( 'contact_status', 'customer', $data['urls']['contacts'] ) );
		$ibg_tile( __( 'Subscribed', 'ibg-client-outreach' ), $ibg_c['subscribed'], add_query_arg( 'marketing_status', 'subscribed', $data['urls']['contacts'] ), 'ibg-stat-ok' );
		$ibg_tile( __( 'Pending', 'ibg-client-outreach' ), $ibg_c['pending'], add_query_arg( 'marketing_status', 'pending', $data['urls']['contacts'] ), 'ibg-stat-warning' );
		$ibg_tile( __( 'Unsubscribed', 'ibg-client-outreach' ), $ibg_c['unsubscribed'], add_query_arg( 'marketing_status', 'unsubscribed', $data['urls']['contacts'] ), 'ibg-stat-error' );
		$ibg_tile( __( 'Do Not Contact', 'ibg-client-outreach' ), $ibg_c['do_not_contact'], add_query_arg( 'marketing_status', 'do_not_contact', $data['urls']['contacts'] ), 'ibg-stat-error' );
		?>
	</div>

	<h2 class="ibg-section-title"><?php esc_html_e( 'Campaigns', 'ibg-client-outreach' ); ?></h2>
	<div class="ibg-stat-grid ibg-dashboard-grid">
		<?php
		$ibg_tile( __( 'Total campaigns', 'ibg-client-outreach' ), $ibg_k['total'], $data['urls']['campaigns'] );
		$ibg_tile( __( 'Draft', 'ibg-client-outreach' ), $ibg_k['draft'], add_query_arg( 'status', 'draft', $data['urls']['campaigns'] ) );
		$ibg_tile( __( 'Scheduled', 'ibg-client-outreach' ), $ibg_k['scheduled'], add_query_arg( 'status', 'scheduled', $data['urls']['campaigns'] ) );
		$ibg_tile( __( 'Processing', 'ibg-client-outreach' ), $ibg_k['processing'], add_query_arg( 'status', 'processing', $data['urls']['campaigns'] ), 'ibg-stat-warning' );
		$ibg_tile( __( 'Completed', 'ibg-client-outreach' ), $ibg_k['completed'], add_query_arg( 'status', 'completed', $data['urls']['campaigns'] ), 'ibg-stat-ok' );
		?>
	</div>

	<h2 class="ibg-section-title"><?php esc_html_e( 'Emails', 'ibg-client-outreach' ); ?></h2>
	<div class="ibg-stat-grid ibg-dashboard-grid">
		<?php
		$ibg_tile( __( 'Sent', 'ibg-client-outreach' ), $ibg_e['sent'], add_query_arg( 'status', 'sent', $data['urls']['logs'] ), 'ibg-stat-ok' );
		$ibg_tile( __( 'Failed', 'ibg-client-outreach' ), $ibg_e['failed'], add_query_arg( 'status', 'failed', $data['urls']['logs'] ), $ibg_e['failed'] ? 'ibg-stat-error' : '' );
		$ibg_tile( __( 'Skipped', 'ibg-client-outreach' ), $ibg_e['skipped'], add_query_arg( 'status', 'skipped', $data['urls']['logs'] ) );
		$ibg_tile( __( 'Unsubscribes', 'ibg-client-outreach' ), $ibg_e['unsubscribed'] );
		$ibg_tile( __( 'Bounces', 'ibg-client-outreach' ), $ibg_e['bounced'] );
		$ibg_tile( __( 'In queue', 'ibg-client-outreach' ), $data['queue']['pending'] + $data['queue']['sending'], $data['urls']['queue'] );
		?>
	</div>

	<div class="ibg-grid">
		<div class="ibg-card">
			<h2><?php esc_html_e( 'Emails sent – last 14 days', 'ibg-client-outreach' ); ?></h2>
			<?php if ( 0 === array_sum( $data['daily'] ) ) : ?>
				<p class="description"><?php esc_html_e( 'No emails sent in the last 14 days.', 'ibg-client-outreach' ); ?></p>
			<?php else : ?>
				<div class="ibg-bars" role="img" aria-label="<?php esc_attr_e( 'Daily sends', 'ibg-client-outreach' ); ?>">
					<?php foreach ( $data['daily'] as $ibg_day => $ibg_count ) : ?>
						<div class="ibg-bar-col" title="<?php echo esc_attr( $ibg_day . ': ' . number_format_i18n( $ibg_count ) ); ?>">
							<div class="ibg-bar" style="height: <?php echo esc_attr( (string) round( 100 * $ibg_count / $ibg_max_daily ) ); ?>%"></div>
							<span class="ibg-bar-label"><?php echo esc_html( gmdate( 'j', strtotime( $ibg_day ) ) ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>

		<div class="ibg-card">
			<h2><?php esc_html_e( 'Recent activity', 'ibg-client-outreach' ); ?></h2>
			<?php if ( empty( $data['activity'] ) ) : ?>
				<p class="description">
					<?php esc_html_e( 'Nothing yet.', 'ibg-client-outreach' ); ?>
					<a href="<?php echo esc_url( $data['urls']['import'] ); ?>"><?php esc_html_e( 'Import contacts', 'ibg-client-outreach' ); ?></a>
				</p>
			<?php else : ?>
				<ul class="ibg-activity">
					<?php foreach ( $data['activity'] as $ibg_ev ) : ?>
						<li>
							<span class="ibg-activity-time"><?php echo esc_html( Formatting::relative( (string) $ibg_ev->created_at ) ); ?></span>
							<strong><?php echo esc_html( $ibg_activity_labels[ $ibg_ev->event_type ] ?? $ibg_ev->event_type ); ?></strong>
							<span class="ibg-activity-detail">
								<?php
								$ibg_bits = array();
								if ( ! empty( $ibg_ev->campaign_name ) ) {
									$ibg_bits[] = $ibg_ev->campaign_name;
								}
								if ( ! empty( $ibg_ev->contact_email ) ) {
									$ibg_bits[] = $ibg_ev->contact_email;
								}
								if ( 'import.completed' === $ibg_ev->event_type ) {
									/* translators: 1: created, 2: updated */
									$ibg_bits[] = sprintf( __( '%1$d created, %2$d updated', 'ibg-client-outreach' ), (int) ( $ibg_ev->data['created'] ?? 0 ), (int) ( $ibg_ev->data['updated'] ?? 0 ) );
								}
								if ( ! empty( $ibg_ev->data['error'] ) ) {
									$ibg_bits[] = (string) $ibg_ev->data['error'];
								}
								echo esc_html( implode( ' · ', $ibg_bits ) );
								?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	</div>

	<details class="ibg-card ibg-details ibg-status-details" <?php echo $ibg_has_issue ? 'open' : ''; ?>>
		<summary><?php esc_html_e( 'System status', 'ibg-client-outreach' ); ?></summary>
		<table class="widefat striped ibg-status-table">
			<tbody>
			<?php foreach ( $data['status'] as $ibg_row ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $ibg_row['label'] ); ?></th>
					<td>
						<span class="ibg-badge ibg-badge-<?php echo esc_attr( $ibg_row['state'] ); ?>"><?php echo esc_html( $ibg_state_labels[ $ibg_row['state'] ] ?? $ibg_row['state'] ); ?></span>
						<span class="ibg-status-value"><?php echo esc_html( $ibg_row['value'] ); ?></span>
						<?php if ( '' !== $ibg_row['help'] ) : ?>
							<p class="description"><?php echo esc_html( $ibg_row['help'] ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p>
			<a class="button" href="<?php echo esc_url( $data['settings_url'] ); ?>"><?php esc_html_e( 'Settings', 'ibg-client-outreach' ); ?></a>
			<?php if ( $data['can_repair'] ) : ?>
				<a class="button <?php echo $data['has_missing_tables'] ? 'button-primary' : ''; ?>" href="<?php echo esc_url( $data['repair_url'] ); ?>"><?php esc_html_e( 'Verify / repair database tables', 'ibg-client-outreach' ); ?></a>
			<?php endif; ?>
		</p>
	</details>
</div>
