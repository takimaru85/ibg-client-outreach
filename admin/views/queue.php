<?php
/**
 * Email queue view.
 *
 * @var array{
 *   page: \IBG\Outreach\Admin\Pages\Queue_Page,
 *   table: \IBG\Outreach\Admin\Tables\Queue_List_Table,
 *   counts: array<string, int>,
 *   cron: array{disabled:bool, next_run:int|false, last_run:int, last_stats:array<string, mixed>},
 *   can_run: bool,
 *   run_url: string,
 *   settings: \IBG\Outreach\Settings
 * } $data
 *
 * @package IBG\Outreach
 */

use IBG\Outreach\Queue\Queue_Item;

defined( 'ABSPATH' ) || exit;

$ibg_page = $data['page'];
$ibg_cron = $data['cron'];
$ibg_fmt  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
?>
<div class="wrap ibg-outreach-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Email Queue', 'ibg-client-outreach' ); ?></h1>
	<?php if ( $data['can_run'] ) : ?>
		<a href="<?php echo esc_url( $data['run_url'] ); ?>" class="page-title-action"><?php esc_html_e( 'Run queue now', 'ibg-client-outreach' ); ?></a>
	<?php endif; ?>
	<hr class="wp-header-end">

	<div class="ibg-stat-grid ibg-queue-stats">
		<?php foreach ( Queue_Item::statuses() as $ibg_status => $ibg_label ) : ?>
			<div class="ibg-stat ibg-stat-queue-<?php echo esc_attr( $ibg_status ); ?>">
				<span class="ibg-stat-value"><?php echo esc_html( number_format_i18n( $data['counts'][ $ibg_status ] ) ); ?></span>
				<span class="ibg-stat-label"><?php echo esc_html( $ibg_label ); ?></span>
			</div>
		<?php endforeach; ?>
	</div>

	<div class="ibg-card ibg-card-narrow ibg-cron-card">
		<p>
			<strong><?php esc_html_e( 'Processing:', 'ibg-client-outreach' ); ?></strong>
			<?php
			printf(
				/* translators: 1: batch size, 2: seconds, 3: retries, 4: minutes */
				esc_html__( '%1$d emails per run, at most one run every %2$d seconds, %3$d retries with a %4$d-minute backoff.', 'ibg-client-outreach' ),
				(int) $data['settings']->get( 'batch_size' ),
				max( 60, (int) $data['settings']->get( 'batch_delay' ) ),
				(int) $data['settings']->get( 'max_retries' ),
				(int) $data['settings']->get( 'retry_delay' )
			);
			?>
			<br>
			<strong><?php esc_html_e( 'WP-Cron:', 'ibg-client-outreach' ); ?></strong>
			<?php if ( $ibg_cron['disabled'] ) : ?>
				<?php esc_html_e( 'DISABLE_WP_CRON is set — make sure a system cron calls wp-cron.php every minute.', 'ibg-client-outreach' ); ?>
			<?php else : ?>
				<?php esc_html_e( 'triggered by site traffic (a system cron is more reliable).', 'ibg-client-outreach' ); ?>
			<?php endif; ?>
			<br>
			<strong><?php esc_html_e( 'Next run:', 'ibg-client-outreach' ); ?></strong>
			<?php echo $ibg_cron['next_run'] ? esc_html( wp_date( $ibg_fmt, (int) $ibg_cron['next_run'] ) ) : esc_html__( 'not scheduled', 'ibg-client-outreach' ); ?>
			&nbsp;·&nbsp;
			<strong><?php esc_html_e( 'Last run:', 'ibg-client-outreach' ); ?></strong>
			<?php echo $ibg_cron['last_run'] ? esc_html( wp_date( $ibg_fmt, (int) $ibg_cron['last_run'] ) ) : esc_html__( 'never', 'ibg-client-outreach' ); ?>
			<?php if ( ! empty( $ibg_cron['last_stats']['ran'] ) ) : ?>
				<span class="ibg-subject">
					<?php
					printf(
						/* translators: 1: sent, 2: failed, 3: skipped */
						esc_html__( '(%1$d sent, %2$d failed, %3$d skipped)', 'ibg-client-outreach' ),
						(int) $ibg_cron['last_stats']['sent'],
						(int) $ibg_cron['last_stats']['failed'],
						(int) $ibg_cron['last_stats']['skipped']
					);
					?>
				</span>
			<?php endif; ?>
		</p>
	</div>

	<?php $data['table']->views(); ?>

	<form method="get">
		<input type="hidden" name="page" value="<?php echo esc_attr( $ibg_page->get_slug() ); ?>">
		<?php
		$data['table']->search_box( __( 'Search by email', 'ibg-client-outreach' ), 'ibg-queue' );
		$data['table']->display();
		?>
	</form>
</div>
