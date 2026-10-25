<?php
/**
 * The email log list.
 *
 * @package Demfilz_SMTP_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read only filtering of a log list.
$dsmtp_search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$dsmtp_status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
$dsmtp_mailer = isset( $_GET['mailer'] ) ? sanitize_key( wp_unslash( $_GET['mailer'] ) ) : '';
$dsmtp_paged  = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
// phpcs:enable WordPress.Security.NonceVerification.Recommended

$dsmtp_per_page = 25;
$dsmtp_args     = array(
	'search'   => $dsmtp_search,
	'status'   => in_array( $dsmtp_status, array( 'sent', 'failed', 'queued', 'bounced', 'blocked' ), true ) ? $dsmtp_status : '',
	'mailer'   => $dsmtp_mailer,
	'per_page' => $dsmtp_per_page,
	'page'     => $dsmtp_paged,
);

$dsmtp_rows   = DSMTP_Log::query( $dsmtp_args );
$dsmtp_total  = DSMTP_Log::count( $dsmtp_args );
$dsmtp_pages  = (int) ceil( $dsmtp_total / $dsmtp_per_page );
$dsmtp_stats  = DSMTP_Log::stats( 7 );
$dsmtp_labels = DSMTP_Providers::labels();
?>
<div class="wrap dsmtp-wrap">
	<h1><?php esc_html_e( 'Email log', 'demfilz-smtp-mailer' ); ?></h1>

	<?php if ( ! DSMTP_Settings::get( 'log_enabled', 1 ) ) : ?>
		<div class="notice notice-warning inline"><p>
			<?php esc_html_e( 'Logging is switched off, so nothing new is being recorded.', 'demfilz-smtp-mailer' ); ?>
			<a href="<?php echo esc_url( DSMTP_Admin::page_url( 'dsmtp-settings', array( 'tab' => 'log' ) ) ); ?>"><?php esc_html_e( 'Turn it on', 'demfilz-smtp-mailer' ); ?></a>
		</p></div>
	<?php endif; ?>

	<div class="dsmtp-cards">
		<div class="dsmtp-card">
			<span class="dsmtp-card-value"><?php echo esc_html( number_format_i18n( $dsmtp_stats['sent'] ) ); ?></span>
			<span class="dsmtp-card-label"><?php esc_html_e( 'Delivered in the last 7 days', 'demfilz-smtp-mailer' ); ?></span>
		</div>
		<div class="dsmtp-card <?php echo $dsmtp_stats['failed'] > 0 ? 'is-bad' : ''; ?>">
			<span class="dsmtp-card-value"><?php echo esc_html( number_format_i18n( $dsmtp_stats['failed'] ) ); ?></span>
			<span class="dsmtp-card-label"><?php esc_html_e( 'Failed in the last 7 days', 'demfilz-smtp-mailer' ); ?></span>
		</div>
		<div class="dsmtp-card <?php echo $dsmtp_stats['bounced'] > 0 ? 'is-bad' : ''; ?>">
			<span class="dsmtp-card-value"><?php echo esc_html( number_format_i18n( $dsmtp_stats['bounced'] ) ); ?></span>
			<span class="dsmtp-card-label"><?php esc_html_e( 'Bounced in the last 7 days', 'demfilz-smtp-mailer' ); ?></span>
		</div>
		<div class="dsmtp-card">
			<span class="dsmtp-card-value"><?php echo esc_html( number_format_i18n( $dsmtp_total ) ); ?></span>
			<span class="dsmtp-card-label"><?php esc_html_e( 'Entries matching this view', 'demfilz-smtp-mailer' ); ?></span>
		</div>
	</div>

	<form method="get" class="dsmtp-filters">
		<input type="hidden" name="page" value="dsmtp-log">
		<p class="search-box">
			<label class="screen-reader-text" for="dsmtp-search"><?php esc_html_e( 'Search the log', 'demfilz-smtp-mailer' ); ?></label>
			<input type="search" id="dsmtp-search" name="s" value="<?php echo esc_attr( $dsmtp_search ); ?>">
			<select name="status">
				<option value=""><?php esc_html_e( 'Any status', 'demfilz-smtp-mailer' ); ?></option>
				<option value="sent" <?php selected( $dsmtp_args['status'], 'sent' ); ?>><?php esc_html_e( 'Delivered', 'demfilz-smtp-mailer' ); ?></option>
				<option value="failed" <?php selected( $dsmtp_args['status'], 'failed' ); ?>><?php esc_html_e( 'Failed', 'demfilz-smtp-mailer' ); ?></option>
				<option value="queued" <?php selected( $dsmtp_args['status'], 'queued' ); ?>><?php esc_html_e( 'Waiting to retry', 'demfilz-smtp-mailer' ); ?></option>
				<option value="bounced" <?php selected( $dsmtp_args['status'], 'bounced' ); ?>><?php esc_html_e( 'Bounced', 'demfilz-smtp-mailer' ); ?></option>
				<option value="blocked" <?php selected( $dsmtp_args['status'], 'blocked' ); ?>><?php esc_html_e( 'Blocked', 'demfilz-smtp-mailer' ); ?></option>
			</select>
			<select name="mailer">
				<option value=""><?php esc_html_e( 'Any mailer', 'demfilz-smtp-mailer' ); ?></option>
				<?php foreach ( DSMTP_Log::used_mailers() as $dsmtp_used ) : ?>
					<option value="<?php echo esc_attr( $dsmtp_used ); ?>" <?php selected( $dsmtp_mailer, $dsmtp_used ); ?>>
						<?php echo esc_html( isset( $dsmtp_labels[ $dsmtp_used ] ) ? $dsmtp_labels[ $dsmtp_used ] : $dsmtp_used ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'demfilz-smtp-mailer' ), '', '', false ); ?>
		</p>
	</form>

	<form method="post" action="<?php echo esc_url( DSMTP_Admin::post_url() ); ?>">
		<?php wp_nonce_field( 'dsmtp_log_action' ); ?>
		<input type="hidden" name="action" value="dsmtp_log_action">

		<div class="tablenav top">
			<div class="alignleft actions">
				<button type="submit" class="button" name="dsmtp_do" value="delete" data-dsmtp-confirm="delete"><?php esc_html_e( 'Delete selected', 'demfilz-smtp-mailer' ); ?></button>
				<button type="submit" class="button" name="dsmtp_do" value="clear" data-dsmtp-confirm="clear"><?php esc_html_e( 'Empty the log', 'demfilz-smtp-mailer' ); ?></button>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'dsmtp_export_log' ), DSMTP_Admin::post_url() ), 'dsmtp_export_log' ) ); ?>"><?php esc_html_e( 'Download CSV', 'demfilz-smtp-mailer' ); ?></a>
			</div>
			<?php if ( $dsmtp_pages > 1 ) : ?>
				<div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'current'   => $dsmtp_paged,
								'total'     => $dsmtp_pages,
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
							)
						)
					);
					?>
				</div>
			<?php endif; ?>
		</div>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<td class="check-column"><input type="checkbox" data-dsmtp-check-all></td>
					<th scope="col" class="dsmtp-col-date"><?php esc_html_e( 'Date', 'demfilz-smtp-mailer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'To', 'demfilz-smtp-mailer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Subject', 'demfilz-smtp-mailer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Sent by', 'demfilz-smtp-mailer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'demfilz-smtp-mailer' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $dsmtp_rows ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'Nothing here yet.', 'demfilz-smtp-mailer' ); ?></td></tr>
				<?php endif; ?>

				<?php foreach ( $dsmtp_rows as $dsmtp_row ) : ?>
					<tr>
						<th scope="row" class="check-column"><input type="checkbox" name="dsmtp_ids[]" value="<?php echo esc_attr( (string) $dsmtp_row['id'] ); ?>"></th>
						<td>
							<?php
							echo esc_html(
								get_date_from_gmt( $dsmtp_row['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
							);
							?>
						</td>
						<td><?php echo esc_html( $dsmtp_row['to_email'] ); ?></td>
						<td>
							<a href="<?php echo esc_url( DSMTP_Admin::page_url( 'dsmtp-log', array( 'view' => (int) $dsmtp_row['id'] ) ) ); ?>">
								<?php echo esc_html( '' !== $dsmtp_row['subject'] ? $dsmtp_row['subject'] : __( '(no subject)', 'demfilz-smtp-mailer' ) ); ?>
							</a>
							<?php if ( '' !== $dsmtp_row['source'] ) : ?>
								<span class="dsmtp-source"><?php echo esc_html( $dsmtp_row['source'] ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( isset( $dsmtp_labels[ $dsmtp_row['mailer'] ] ) ? $dsmtp_labels[ $dsmtp_row['mailer'] ] : $dsmtp_row['mailer'] ); ?></td>
						<td>
							<span class="dsmtp-pill dsmtp-pill-<?php echo esc_attr( $dsmtp_row['status'] ); ?>">
								<?php
								if ( 'sent' === $dsmtp_row['status'] ) {
									esc_html_e( 'Delivered', 'demfilz-smtp-mailer' );
								} elseif ( 'queued' === $dsmtp_row['status'] ) {
									esc_html_e( 'Waiting to retry', 'demfilz-smtp-mailer' );
								} elseif ( 'bounced' === $dsmtp_row['status'] ) {
									esc_html_e( 'Bounced', 'demfilz-smtp-mailer' );
								} elseif ( 'blocked' === $dsmtp_row['status'] ) {
									esc_html_e( 'Blocked', 'demfilz-smtp-mailer' );
								} else {
									esc_html_e( 'Failed', 'demfilz-smtp-mailer' );
								}
								?>
							</span>
							<?php if ( 'sent' === $dsmtp_row['status'] && ! empty( $dsmtp_row['delivered_at'] ) ) : ?>
								<span class="dsmtp-source"><?php esc_html_e( 'Delivery confirmed by the provider', 'demfilz-smtp-mailer' ); ?></span>
							<?php endif; ?>
							<?php if ( '' !== $dsmtp_row['error'] ) : ?>
								<span class="dsmtp-error-hint"><?php echo esc_html( wp_html_excerpt( $dsmtp_row['error'], 90, '...' ) ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</form>
</div>
