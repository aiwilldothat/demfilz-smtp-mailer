<?php
/**
 * The suppression list screen.
 *
 * @package Demfilz_SMTP_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read only filtering of a list.
$dsmtp_search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$dsmtp_reason = isset( $_GET['reason'] ) ? sanitize_key( wp_unslash( $_GET['reason'] ) ) : '';
$dsmtp_paged  = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
// phpcs:enable WordPress.Security.NonceVerification.Recommended

$dsmtp_per_page = 50;
$dsmtp_args     = array(
	'search'   => $dsmtp_search,
	'reason'   => in_array( $dsmtp_reason, array( 'bounce', 'complaint', 'manual' ), true ) ? $dsmtp_reason : '',
	'per_page' => $dsmtp_per_page,
	'page'     => $dsmtp_paged,
);

$dsmtp_rows  = DSMTP_Suppression::query( $dsmtp_args );
$dsmtp_total = DSMTP_Suppression::count( $dsmtp_args );
$dsmtp_pages = (int) ceil( $dsmtp_total / $dsmtp_per_page );

$dsmtp_reason_labels = array(
	'bounce'    => __( 'Bounced', 'demfilz-smtp-mailer' ),
	'complaint' => __( 'Spam complaint', 'demfilz-smtp-mailer' ),
	'manual'    => __( 'Added by hand', 'demfilz-smtp-mailer' ),
);

$dsmtp_labels = DSMTP_Providers::labels();
?>
<div class="wrap dsmtp-wrap">
	<h1><?php esc_html_e( 'Suppression list', 'demfilz-smtp-mailer' ); ?></h1>

	<p><?php esc_html_e( 'Addresses on this list are never emailed. A message addressed only to them is recorded as blocked; a message with other recipients goes out without them. Hard bounces and spam complaints reported by your mailer land here automatically.', 'demfilz-smtp-mailer' ); ?></p>

	<div class="dsmtp-cards">
		<div class="dsmtp-card">
			<span class="dsmtp-card-value"><?php echo esc_html( number_format_i18n( DSMTP_Suppression::count( array( 'reason' => 'bounce' ) ) ) ); ?></span>
			<span class="dsmtp-card-label"><?php esc_html_e( 'Bounced addresses', 'demfilz-smtp-mailer' ); ?></span>
		</div>
		<div class="dsmtp-card">
			<span class="dsmtp-card-value"><?php echo esc_html( number_format_i18n( DSMTP_Suppression::count( array( 'reason' => 'complaint' ) ) ) ); ?></span>
			<span class="dsmtp-card-label"><?php esc_html_e( 'Spam complaints', 'demfilz-smtp-mailer' ); ?></span>
		</div>
		<div class="dsmtp-card">
			<span class="dsmtp-card-value"><?php echo esc_html( number_format_i18n( DSMTP_Log::stats( 7 )['blocked'] ) ); ?></span>
			<span class="dsmtp-card-label"><?php esc_html_e( 'Messages blocked in the last 7 days', 'demfilz-smtp-mailer' ); ?></span>
		</div>
	</div>

	<div class="dsmtp-panel">
		<h3><?php esc_html_e( 'Add an address', 'demfilz-smtp-mailer' ); ?></h3>
		<form method="post" action="<?php echo esc_url( DSMTP_Admin::post_url() ); ?>" class="dsmtp-inline-form">
			<?php wp_nonce_field( 'dsmtp_suppress_action' ); ?>
			<input type="hidden" name="action" value="dsmtp_suppress_action">
			<input type="hidden" name="dsmtp_do" value="add">
			<label class="screen-reader-text" for="dsmtp-suppress-email"><?php esc_html_e( 'Email address', 'demfilz-smtp-mailer' ); ?></label>
			<input type="email" id="dsmtp-suppress-email" name="dsmtp_email" class="regular-text" placeholder="someone@example.com" required>
			<label class="screen-reader-text" for="dsmtp-suppress-note"><?php esc_html_e( 'Note', 'demfilz-smtp-mailer' ); ?></label>
			<input type="text" id="dsmtp-suppress-note" name="dsmtp_note" class="regular-text" placeholder="<?php esc_attr_e( 'Why (optional)', 'demfilz-smtp-mailer' ); ?>">
			<?php submit_button( __( 'Add to the list', 'demfilz-smtp-mailer' ), 'secondary', 'dsmtp_suppress_submit', false ); ?>
		</form>
	</div>

	<form method="get" class="dsmtp-filters">
		<input type="hidden" name="page" value="dsmtp-suppress">
		<p class="search-box">
			<label class="screen-reader-text" for="dsmtp-search"><?php esc_html_e( 'Search the list', 'demfilz-smtp-mailer' ); ?></label>
			<input type="search" id="dsmtp-search" name="s" value="<?php echo esc_attr( $dsmtp_search ); ?>">
			<select name="reason">
				<option value=""><?php esc_html_e( 'Any reason', 'demfilz-smtp-mailer' ); ?></option>
				<?php foreach ( $dsmtp_reason_labels as $dsmtp_key => $dsmtp_label ) : ?>
					<option value="<?php echo esc_attr( $dsmtp_key ); ?>" <?php selected( $dsmtp_args['reason'], $dsmtp_key ); ?>><?php echo esc_html( $dsmtp_label ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'demfilz-smtp-mailer' ), '', 'dsmtp_filter_submit', false ); ?>
		</p>
	</form>

	<form method="post" action="<?php echo esc_url( DSMTP_Admin::post_url() ); ?>">
		<?php wp_nonce_field( 'dsmtp_suppress_action' ); ?>
		<input type="hidden" name="action" value="dsmtp_suppress_action">

		<div class="tablenav top">
			<div class="alignleft actions">
				<button type="submit" class="button" name="dsmtp_do" value="remove" data-dsmtp-confirm="delete"><?php esc_html_e( 'Remove selected', 'demfilz-smtp-mailer' ); ?></button>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'dsmtp_export_suppress' ), DSMTP_Admin::post_url() ), 'dsmtp_export_suppress' ) ); ?>"><?php esc_html_e( 'Download CSV', 'demfilz-smtp-mailer' ); ?></a>
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
					<th scope="col"><?php esc_html_e( 'Address', 'demfilz-smtp-mailer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Reason', 'demfilz-smtp-mailer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Detail', 'demfilz-smtp-mailer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Reported by', 'demfilz-smtp-mailer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Added', 'demfilz-smtp-mailer' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $dsmtp_rows ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'The list is empty. That is a good sign.', 'demfilz-smtp-mailer' ); ?></td></tr>
				<?php endif; ?>

				<?php foreach ( $dsmtp_rows as $dsmtp_row ) : ?>
					<tr>
						<th scope="row" class="check-column"><input type="checkbox" name="dsmtp_ids[]" value="<?php echo esc_attr( (string) $dsmtp_row['id'] ); ?>"></th>
						<td><?php echo esc_html( $dsmtp_row['email'] ); ?></td>
						<td>
							<span class="dsmtp-pill dsmtp-pill-<?php echo esc_attr( 'manual' === $dsmtp_row['reason'] ? 'queued' : 'failed' ); ?>">
								<?php echo esc_html( isset( $dsmtp_reason_labels[ $dsmtp_row['reason'] ] ) ? $dsmtp_reason_labels[ $dsmtp_row['reason'] ] : $dsmtp_row['reason'] ); ?>
							</span>
						</td>
						<td><?php echo esc_html( wp_html_excerpt( $dsmtp_row['detail'], 120, '...' ) ); ?></td>
						<td>
							<?php
							if ( 'manual' === $dsmtp_row['source'] ) {
								esc_html_e( 'Site administrator', 'demfilz-smtp-mailer' );
							} else {
								echo esc_html( isset( $dsmtp_labels[ $dsmtp_row['source'] ] ) ? $dsmtp_labels[ $dsmtp_row['source'] ] : $dsmtp_row['source'] );
							}
							?>
						</td>
						<td><?php echo esc_html( get_date_from_gmt( $dsmtp_row['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</form>
</div>
