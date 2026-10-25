<?php
/**
 * A single log entry.
 *
 * @package Demfilz_SMTP_Mailer
 *
 * @var array|null $entry Log row, supplied by DSMTP_Admin::render_log().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<div class="wrap dsmtp-wrap">
	<h1><?php esc_html_e( 'Log entry', 'demfilz-smtp-mailer' ); ?></h1>

	<p><a href="<?php echo esc_url( DSMTP_Admin::page_url( 'dsmtp-log' ) ); ?>">&larr; <?php esc_html_e( 'Back to the email log', 'demfilz-smtp-mailer' ); ?></a></p>

	<?php if ( ! $entry ) : ?>
		<p><?php esc_html_e( 'That entry no longer exists.', 'demfilz-smtp-mailer' ); ?></p>
	<?php else : ?>
		<table class="widefat striped dsmtp-entry">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Date', 'demfilz-smtp-mailer' ); ?></th>
					<td><?php echo esc_html( get_date_from_gmt( $entry['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'demfilz-smtp-mailer' ); ?></th>
					<td>
						<span class="dsmtp-pill dsmtp-pill-<?php echo esc_attr( $entry['status'] ); ?>">
							<?php
							if ( 'sent' === $entry['status'] ) {
								esc_html_e( 'Delivered', 'demfilz-smtp-mailer' );
							} elseif ( 'queued' === $entry['status'] ) {
								esc_html_e( 'Waiting to retry', 'demfilz-smtp-mailer' );
							} elseif ( 'bounced' === $entry['status'] ) {
								esc_html_e( 'Bounced', 'demfilz-smtp-mailer' );
							} elseif ( 'blocked' === $entry['status'] ) {
								esc_html_e( 'Blocked', 'demfilz-smtp-mailer' );
							} else {
								esc_html_e( 'Failed', 'demfilz-smtp-mailer' );
							}
							?>
						</span>
						<?php if ( ! empty( $entry['delivered_at'] ) ) : ?>
							<?php
							printf(
								/* translators: %s: date and time. */
								esc_html__( 'Delivery confirmed by the provider at %s.', 'demfilz-smtp-mailer' ),
								esc_html( get_date_from_gmt( $entry['delivered_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) )
							);
							?>
						<?php endif; ?>
						<?php if ( (int) $entry['attempts'] > 0 ) : ?>
							<?php
							printf(
								/* translators: %d: number of attempts. */
								esc_html__( 'Attempts: %d', 'demfilz-smtp-mailer' ),
								(int) $entry['attempts']
							);
							?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Mailer', 'demfilz-smtp-mailer' ); ?></th>
					<td>
						<?php
						$dsmtp_entry_labels = DSMTP_Providers::labels();

						echo esc_html( isset( $dsmtp_entry_labels[ $entry['mailer'] ] ) ? $dsmtp_entry_labels[ $entry['mailer'] ] : $entry['mailer'] );
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'From', 'demfilz-smtp-mailer' ); ?></th>
					<td><?php echo esc_html( $entry['from_email'] ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'To', 'demfilz-smtp-mailer' ); ?></th>
					<td><?php echo esc_html( $entry['to_email'] ); ?></td>
				</tr>
				<?php if ( '' !== $entry['cc_email'] ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Cc', 'demfilz-smtp-mailer' ); ?></th>
						<td><?php echo esc_html( $entry['cc_email'] ); ?></td>
					</tr>
				<?php endif; ?>
				<?php if ( '' !== $entry['bcc_email'] ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Bcc', 'demfilz-smtp-mailer' ); ?></th>
						<td><?php echo esc_html( $entry['bcc_email'] ); ?></td>
					</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Subject', 'demfilz-smtp-mailer' ); ?></th>
					<td><?php echo esc_html( $entry['subject'] ); ?></td>
				</tr>
				<?php if ( '' !== $entry['attachments'] ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Attachments', 'demfilz-smtp-mailer' ); ?></th>
						<td><?php echo esc_html( $entry['attachments'] ); ?></td>
					</tr>
				<?php endif; ?>
				<?php if ( '' !== $entry['source'] ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Sent by', 'demfilz-smtp-mailer' ); ?></th>
						<td><?php echo esc_html( $entry['source'] ); ?></td>
					</tr>
				<?php endif; ?>
				<?php if ( '' !== $entry['message_id'] ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Message id', 'demfilz-smtp-mailer' ); ?></th>
						<td><code><?php echo esc_html( $entry['message_id'] ); ?></code></td>
					</tr>
				<?php endif; ?>
				<?php if ( '' !== $entry['error'] ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Reported problem', 'demfilz-smtp-mailer' ); ?></th>
						<td class="dsmtp-entry-error"><?php echo esc_html( $entry['error'] ); ?></td>
					</tr>
				<?php endif; ?>
				<?php if ( '' !== $entry['headers'] ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Headers', 'demfilz-smtp-mailer' ); ?></th>
						<td><pre class="dsmtp-code"><?php echo esc_html( $entry['headers'] ); ?></pre></td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( '' !== $entry['body'] ) : ?>
			<h2><?php esc_html_e( 'Message', 'demfilz-smtp-mailer' ); ?></h2>
			<pre class="dsmtp-code dsmtp-body"><?php echo esc_html( $entry['body'] ); ?></pre>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( DSMTP_Admin::post_url() ); ?>">
			<?php wp_nonce_field( 'dsmtp_log_action' ); ?>
			<input type="hidden" name="action" value="dsmtp_log_action">
			<input type="hidden" name="dsmtp_id" value="<?php echo esc_attr( (string) $entry['id'] ); ?>">
			<p>
				<button type="submit" class="button button-primary" name="dsmtp_do" value="resend"><?php esc_html_e( 'Send this message again', 'demfilz-smtp-mailer' ); ?></button>
				<button type="submit" class="button" name="dsmtp_do" value="delete"><?php esc_html_e( 'Delete this entry', 'demfilz-smtp-mailer' ); ?></button>
			</p>
			<input type="hidden" name="dsmtp_ids[]" value="<?php echo esc_attr( (string) $entry['id'] ); ?>">
			<?php if ( '' === $entry['payload'] ) : ?>
				<p class="description"><?php esc_html_e( 'This entry was recorded without the detail needed to send it again. Turn retries on to keep that detail in future.', 'demfilz-smtp-mailer' ); ?></p>
			<?php endif; ?>
		</form>
	<?php endif; ?>
</div>
