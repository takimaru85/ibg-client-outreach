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
			<?php foreach ( $ibg_section['fields'] as $ibg_key => $ibg_field ) : ?>
				<tr>
					<th scope="row">
						<label for="<?php echo esc_attr( 'ibg-setting-' . $ibg_key ); ?>">
							<?php echo esc_html( (string) $ibg_field['label'] ); ?>
							<?php if ( ! empty( $ibg_field['required'] ) ) : ?>
								<span class="ibg-required" aria-hidden="true">*</span>
							<?php endif; ?>
						</label>
					</th>
					<td>
						<?php $ibg_page->render_field( $ibg_key, $ibg_field, $data['values'][ $ibg_key ] ?? null ); ?>
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
</div>
