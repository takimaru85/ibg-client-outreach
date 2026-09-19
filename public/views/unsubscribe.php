<?php
/**
 * Standalone unsubscribe page.
 *
 * Copy to your theme as ibg-outreach/unsubscribe.php to customise.
 *
 * @var array{
 *   variant: string,
 *   masked_email: string,
 *   token: string,
 *   campaign_id: int,
 *   can_resubscribe: bool,
 *   business_name: string,
 *   business_url: string,
 *   privacy_url: string,
 *   action_url: string
 * } $data
 *
 * @package IBG\Outreach
 */

defined( 'ABSPATH' ) || exit;

$ibg_variant = $data['variant'];
$ibg_titles  = array(
	'confirm'      => __( 'Unsubscribe', 'ibg-client-outreach' ),
	'done'         => __( 'You have been unsubscribed', 'ibg-client-outreach' ),
	'already'      => __( 'Already unsubscribed', 'ibg-client-outreach' ),
	'resubscribed' => __( 'Welcome back', 'ibg-client-outreach' ),
	'test'         => __( 'Test email link', 'ibg-client-outreach' ),
	'invalid'      => __( 'Link not valid', 'ibg-client-outreach' ),
);
$ibg_title   = $ibg_titles[ $ibg_variant ] ?? $ibg_titles['invalid'];
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( $ibg_title . ' – ' . $data['business_name'] ); ?></title>
	<style>
		body { margin: 0; padding: 24px 16px; background: #f4f4f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #1d2327; line-height: 1.6; }
		.ibg-box { max-width: 520px; margin: 40px auto; background: #fff; border: 1px solid #e0e0e0; border-radius: 6px; padding: 32px; }
		h1 { font-size: 22px; margin: 0 0 16px; }
		p { margin: 0 0 16px; }
		.ibg-email { font-weight: 600; }
		.ibg-btn { display: inline-block; padding: 10px 20px; border: 0; border-radius: 4px; background: #1d2327; color: #fff; font-size: 15px; cursor: pointer; text-decoration: none; }
		.ibg-btn-secondary { background: #fff; color: #1d2327; border: 1px solid #c3c4c7; }
		form { margin: 0 0 12px; }
		.ibg-footer { margin-top: 24px; font-size: 12px; color: #646970; }
		.ibg-footer a { color: #646970; }
	</style>
</head>
<body>
	<div class="ibg-box">
		<h1><?php echo esc_html( $ibg_title ); ?></h1>

		<?php if ( 'confirm' === $ibg_variant ) : ?>
			<p>
				<?php
				printf(
					/* translators: 1: masked email, 2: business name */
					esc_html__( 'Stop receiving emails from %2$s at %1$s?', 'ibg-client-outreach' ),
					'<span class="ibg-email">' . esc_html( $data['masked_email'] ) . '</span>',
					esc_html( $data['business_name'] )
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( $data['action_url'] ); ?>">
				<input type="hidden" name="ibg_action" value="unsubscribe">
				<input type="hidden" name="c" value="<?php echo (int) $data['campaign_id']; ?>">
				<button type="submit" class="ibg-btn"><?php esc_html_e( 'Yes, unsubscribe me', 'ibg-client-outreach' ); ?></button>
			</form>
			<p class="ibg-footer"><?php esc_html_e( 'You will not receive further marketing emails. This takes effect immediately.', 'ibg-client-outreach' ); ?></p>

		<?php elseif ( 'done' === $ibg_variant ) : ?>
			<p>
				<?php
				printf(
					/* translators: %s: masked email */
					esc_html__( '%s will no longer receive marketing emails from us.', 'ibg-client-outreach' ),
					'<span class="ibg-email">' . esc_html( $data['masked_email'] ) . '</span>'
				);
				?>
			</p>
			<p><?php esc_html_e( 'Unsubscribed by mistake?', 'ibg-client-outreach' ); ?></p>
			<form method="post" action="<?php echo esc_url( $data['action_url'] ); ?>">
				<input type="hidden" name="ibg_action" value="resubscribe">
				<input type="hidden" name="c" value="<?php echo (int) $data['campaign_id']; ?>">
				<button type="submit" class="ibg-btn ibg-btn-secondary"><?php esc_html_e( 'Resubscribe', 'ibg-client-outreach' ); ?></button>
			</form>

		<?php elseif ( 'already' === $ibg_variant ) : ?>
			<p>
				<?php
				printf(
					/* translators: %s: masked email */
					esc_html__( '%s is already unsubscribed. No further action is needed.', 'ibg-client-outreach' ),
					'<span class="ibg-email">' . esc_html( $data['masked_email'] ) . '</span>'
				);
				?>
			</p>
			<?php if ( $data['can_resubscribe'] ) : ?>
				<form method="post" action="<?php echo esc_url( $data['action_url'] ); ?>">
					<input type="hidden" name="ibg_action" value="resubscribe">
					<input type="hidden" name="c" value="<?php echo (int) $data['campaign_id']; ?>">
					<button type="submit" class="ibg-btn ibg-btn-secondary"><?php esc_html_e( 'Resubscribe', 'ibg-client-outreach' ); ?></button>
				</form>
			<?php endif; ?>

		<?php elseif ( 'resubscribed' === $ibg_variant ) : ?>
			<p>
				<?php
				printf(
					/* translators: %s: masked email */
					esc_html__( '%s can receive emails from us again.', 'ibg-client-outreach' ),
					'<span class="ibg-email">' . esc_html( $data['masked_email'] ) . '</span>'
				);
				?>
			</p>

		<?php elseif ( 'test' === $ibg_variant ) : ?>
			<p><?php esc_html_e( 'This link came from a test email. Nothing has been changed. In a real campaign this page lets the recipient unsubscribe with one click.', 'ibg-client-outreach' ); ?></p>

		<?php else : ?>
			<p><?php esc_html_e( 'This unsubscribe link is invalid or has expired. If you still want to stop receiving emails, reply to the message you received and we will remove you manually.', 'ibg-client-outreach' ); ?></p>
		<?php endif; ?>

		<p class="ibg-footer">
			<a href="<?php echo esc_url( $data['business_url'] ); ?>"><?php echo esc_html( $data['business_name'] ); ?></a>
			<?php if ( '' !== $data['privacy_url'] ) : ?>
				· <a href="<?php echo esc_url( $data['privacy_url'] ); ?>"><?php esc_html_e( 'Privacy policy', 'ibg-client-outreach' ); ?></a>
			<?php endif; ?>
		</p>
	</div>
</body>
</html>
