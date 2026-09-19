<?php
/**
 * Settings view.
 *
 * @var array{
 *   page: \IBG\Outreach\Admin\Pages\Settings_Page,
 *   sections: array<string, array{title:string, description:string, fields:array<string, array<string, mixed>>}>,
 *   current_tab: string,
 *   values: array<string, mixed>,
 *   providers: array<string, \IBG\Outreach\Email\Providers\Email_Provider>,
 *   active_id: string
 * } $data
 *
 * @package IBG\Outreach
 */

use IBG\Outreach\Admin\Pages\Settings_Page;

defined( 'ABSPATH' ) || exit;

$ibg_page    = $data['page'];
$ibg_tab     = $data['current_tab'];
$ibg_section = $data['sections'][ $ibg_tab ];
?>
<div class="wrap ibg-outreach-wrap">
	<h1><?php esc_html_e( 'IBG Outreach Settings', 'ibg-client-outreach' ); ?></h1>

	<?php settings_errors(); ?>

	<nav class="nav-tab-wrapper">
		<?php foreach ( $data['sections'] as $ibg_slug => $ibg_s ) : ?>
			<a href="<?php echo esc_url( $ibg_page->get_url( array( 'tab' => $ibg_slug ) ) ); ?>"
				class="nav-tab <?php echo $ibg_slug === $ibg_tab ? 'nav-tab-active' : ''; ?>">
				<?php echo esc_html( $ibg_s['title'] ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<?php if ( 'compliance' === $ibg_tab ) : ?>
		<div class="notice notice-info inline">
			<p><?php echo esc_html( $ibg_section['description'] ); ?></p>
		</div>
	<?php elseif ( '' !== $ibg_section['description'] ) : ?>
		<p class="description ibg-section-description"><?php echo esc_html( $ibg_section['description'] ); ?></p>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
		<?php settings_fields( Settings_Page::OPTION_GROUP ); ?>
		<input type="hidden" name="<?php echo esc_attr( \IBG\Outreach\Settings::OPTION ); ?>[_tab]" value="<?php echo esc_attr( $ibg_tab ); ?>">

		<table class="form-table" role="presentation">
			<tbody>
			<?php $ibg_last_provider = null; ?>
			<?php foreach ( $ibg_section['fields'] as $ibg_key => $ibg_field ) : ?>
				<?php
				$ibg_provider = (string) ( $ibg_field['provider'] ?? '' );
				if ( '' !== $ibg_provider && $ibg_provider !== $ibg_last_provider ) :
					$ibg_last_provider = $ibg_provider;
					?>
					<tr class="ibg-provider-field ibg-provider-heading" data-provider="<?php echo esc_attr( $ibg_provider ); ?>">
						<th scope="row" colspan="2">
							<h3>
								<?php
								printf(
									/* translators: %s: provider name */
									esc_html__( '%s settings', 'ibg-client-outreach' ),
									esc_html( (string) ( $ibg_field['provider_label'] ?? $ibg_provider ) )
								);
								?>
							</h3>
						</th>
					</tr>
				<?php endif; ?>
				<tr <?php echo '' !== $ibg_provider ? 'class="ibg-provider-field" data-provider="' . esc_attr( $ibg_provider ) . '"' : ''; ?>>
					<th scope="row">
						<label for="<?php echo esc_attr( 'ibg-setting-' . $ibg_key ); ?>">
							<?php echo esc_html( (string) $ibg_field['label'] ); ?>
							<?php if ( ! empty( $ibg_field['required'] ) ) : ?>
								<span class="ibg-required" aria-hidden="true">*</span>
							<?php endif; ?>
						</label>
					</th>
					<td>
						<?php $ibg_page->render_field( $ibg_key, $ibg_field, $data['values'][ $ibg_key ] ?? ( $ibg_field['default'] ?? null ) ); ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( 'email' === $ibg_tab ) : ?>
			<h2><?php esc_html_e( 'Available providers', 'ibg-client-outreach' ); ?></h2>
			<table class="widefat striped ibg-providers-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Provider', 'ibg-client-outreach' ); ?></th>
						<th><?php esc_html_e( 'Status', 'ibg-client-outreach' ); ?></th>
						<th><?php esc_html_e( 'Description', 'ibg-client-outreach' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $data['providers'] as $ibg_provider ) : ?>
					<tr>
						<td>
							<strong><?php echo esc_html( $ibg_provider->get_name() ); ?></strong>
							<?php if ( $ibg_provider->get_id() === $data['active_id'] ) : ?>
								<span class="ibg-badge ibg-badge-ok"><?php esc_html_e( 'Active', 'ibg-client-outreach' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $ibg_provider->is_configured() ) : ?>
								<span class="ibg-badge ibg-badge-ok"><?php esc_html_e( 'Configured', 'ibg-client-outreach' ); ?></span>
							<?php else : ?>
								<span class="ibg-badge ibg-badge-error"><?php esc_html_e( 'Not configured', 'ibg-client-outreach' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $ibg_provider->get_description() ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php submit_button(); ?>
	</form>

	<?php if ( 'email' === $ibg_tab ) : ?>
		<div class="ibg-card ibg-card-narrow">
			<h2><?php esc_html_e( 'Send a delivery test', 'ibg-client-outreach' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Uses the saved settings above (save first). Sends a short message with your footer through the active provider and records the result in Email Logs.', 'ibg-client-outreach' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( $data['test_nonce'] ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( $data['test_action'] ); ?>">
				<label for="ibg-settings-test-email" class="screen-reader-text"><?php esc_html_e( 'Send to', 'ibg-client-outreach' ); ?></label>
				<input type="email" id="ibg-settings-test-email" name="test_email" class="regular-text" value="<?php echo esc_attr( $data['test_email'] ); ?>" required>
				<?php submit_button( __( 'Send test email', 'ibg-client-outreach' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>

		<div class="ibg-card ibg-card-narrow">
			<h2><?php esc_html_e( 'Delivery webhooks', 'ibg-client-outreach' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Providers that report bounces, complaints and (if enabled) opens/clicks post to this URL. Only providers that implement webhook verification accept events; the built-in wp_mail and SMTP providers do not report delivery events.', 'ibg-client-outreach' ); ?>
			</p>
			<code><?php echo esc_html( $data['webhook_url'] ); ?></code>
		</div>
	<?php endif; ?>
</div>
