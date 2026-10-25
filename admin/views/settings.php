<?php
/**
 * The settings screen.
 *
 * @package Demfilz_SMTP_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab navigation only.
$dsmtp_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';

$dsmtp_tabs = array(
	'general'  => __( 'Sending', 'demfilz-smtp-mailer' ),
	'log'      => __( 'Log and alerts', 'demfilz-smtp-mailer' ),
	'controls' => __( 'Email controls', 'demfilz-smtp-mailer' ),
	'advanced' => __( 'Advanced', 'demfilz-smtp-mailer' ),
);

if ( ! isset( $dsmtp_tabs[ $dsmtp_tab ] ) ) {
	$dsmtp_tab = 'general';
}

$dsmtp_settings = DSMTP_Settings::all();
$dsmtp_active   = (string) DSMTP_Settings::get( 'mailer', 'php' );
$dsmtp_provider = DSMTP_Providers::active();
$dsmtp_state    = $dsmtp_provider->validate();
?>
<div class="wrap dsmtp-wrap">
	<h1><?php esc_html_e( 'Mail settings', 'demfilz-smtp-mailer' ); ?></h1>

	<p class="dsmtp-status <?php echo is_wp_error( $dsmtp_state ) ? 'is-warning' : 'is-good'; ?>">
		<?php
		if ( is_wp_error( $dsmtp_state ) ) {
			echo esc_html( $dsmtp_state->get_error_message() );
		} else {
			printf(
				/* translators: %s: mailer name. */
				esc_html__( 'Sending through %s.', 'demfilz-smtp-mailer' ),
				esc_html( $dsmtp_provider::label() )
			);
		}
		?>
		<a href="<?php echo esc_url( DSMTP_Admin::page_url( 'dsmtp-tools' ) ); ?>"><?php esc_html_e( 'Send a test email', 'demfilz-smtp-mailer' ); ?></a>
	</p>

	<?php if ( DSMTP_Settings::is_network_mode() ) : ?>
		<p class="description"><?php esc_html_e( 'The plugin is network activated, so these settings apply to every site in the network.', 'demfilz-smtp-mailer' ); ?></p>
	<?php endif; ?>

	<nav class="nav-tab-wrapper">
		<?php foreach ( $dsmtp_tabs as $dsmtp_key => $dsmtp_label ) : ?>
			<a class="nav-tab <?php echo $dsmtp_key === $dsmtp_tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( DSMTP_Admin::page_url( 'dsmtp-settings', array( 'tab' => $dsmtp_key ) ) ); ?>">
				<?php echo esc_html( $dsmtp_label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<form method="post" action="<?php echo esc_url( DSMTP_Admin::post_url() ); ?>">
		<?php wp_nonce_field( 'dsmtp_save_settings' ); ?>
		<input type="hidden" name="action" value="dsmtp_save_settings">
		<input type="hidden" name="dsmtp_tab" value="<?php echo esc_attr( $dsmtp_tab ); ?>">

		<?php if ( 'general' === $dsmtp_tab ) : ?>

			<h2><?php esc_html_e( 'From address', 'demfilz-smtp-mailer' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="dsmtp-from-email"><?php esc_html_e( 'From email', 'demfilz-smtp-mailer' ); ?></label></th>
						<td>
							<input type="email" class="regular-text" id="dsmtp-from-email" name="dsmtp[from_email]" value="<?php echo esc_attr( $dsmtp_settings['from_email'] ); ?>">
							<p><label><input type="checkbox" name="dsmtp[force_from_email]" value="1" <?php checked( ! empty( $dsmtp_settings['force_from_email'] ) ); ?>> <?php esc_html_e( 'Use this address for every message, even when a plugin sets its own', 'demfilz-smtp-mailer' ); ?></label></p>
							<p class="description"><?php esc_html_e( 'Use an address at your own domain. Free mailbox addresses are rejected or filed as spam by most inboxes.', 'demfilz-smtp-mailer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dsmtp-from-name"><?php esc_html_e( 'From name', 'demfilz-smtp-mailer' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="dsmtp-from-name" name="dsmtp[from_name]" value="<?php echo esc_attr( $dsmtp_settings['from_name'] ); ?>">
							<p><label><input type="checkbox" name="dsmtp[force_from_name]" value="1" <?php checked( ! empty( $dsmtp_settings['force_from_name'] ) ); ?>> <?php esc_html_e( 'Use this name for every message', 'demfilz-smtp-mailer' ); ?></label></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dsmtp-reply-to"><?php esc_html_e( 'Reply to', 'demfilz-smtp-mailer' ); ?></label></th>
						<td>
							<input type="email" class="regular-text" id="dsmtp-reply-to" name="dsmtp[reply_to]" value="<?php echo esc_attr( $dsmtp_settings['reply_to'] ); ?>">
							<p class="description"><?php esc_html_e( 'Optional. Used only when the message does not set its own reply to address.', 'demfilz-smtp-mailer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Return path', 'demfilz-smtp-mailer' ); ?></th>
						<td>
							<label><input type="checkbox" name="dsmtp[set_return_path]" value="1" <?php checked( ! empty( $dsmtp_settings['set_return_path'] ) ); ?>> <?php esc_html_e( 'Send bounce messages to the from address', 'demfilz-smtp-mailer' ); ?></label>
						</td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Mailer', 'demfilz-smtp-mailer' ); ?></h2>

			<?php if ( DSMTP_Settings::is_constant( 'mailer' ) ) : ?>
				<p class="description"><?php esc_html_e( 'The mailer is set in wp-config.php and cannot be changed here.', 'demfilz-smtp-mailer' ); ?></p>
			<?php endif; ?>

			<div class="dsmtp-mailers">
				<?php foreach ( DSMTP_Providers::grouped() as $dsmtp_group => $dsmtp_ids ) : ?>
					<h3><?php echo esc_html( $dsmtp_group ); ?></h3>
					<div class="dsmtp-mailer-grid">
						<?php
						foreach ( $dsmtp_ids as $dsmtp_id ) :
							$dsmtp_item = DSMTP_Providers::get( $dsmtp_id );
							?>
							<label class="dsmtp-mailer-card <?php echo $dsmtp_id === $dsmtp_active ? 'is-selected' : ''; ?>">
								<input type="radio" name="dsmtp[mailer]" value="<?php echo esc_attr( $dsmtp_id ); ?>" <?php checked( $dsmtp_id, $dsmtp_active ); ?> data-dsmtp-mailer>
								<span class="dsmtp-mailer-name"><?php echo esc_html( $dsmtp_item::label() ); ?></span>
								<span class="dsmtp-mailer-desc"><?php echo esc_html( $dsmtp_item::description() ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>
			</div>

			<h2><?php esc_html_e( 'Connection', 'demfilz-smtp-mailer' ); ?></h2>

			<?php
			foreach ( array_keys( DSMTP_Providers::classes() ) as $dsmtp_id ) {
				DSMTP_Admin::provider_panel( $dsmtp_id, $dsmtp_active );
			}
			?>

			<h2><?php esc_html_e( 'Backup mailer', 'demfilz-smtp-mailer' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="dsmtp-backup"><?php esc_html_e( 'If the first mailer fails', 'demfilz-smtp-mailer' ); ?></label></th>
						<td>
							<select id="dsmtp-backup" name="dsmtp[backup_mailer]">
								<option value=""><?php esc_html_e( 'Do not try again with another mailer', 'demfilz-smtp-mailer' ); ?></option>
								<?php
								$dsmtp_backup_options = DSMTP_Providers::labels();

								unset( $dsmtp_backup_options['php'] );

								foreach ( $dsmtp_backup_options as $dsmtp_id => $dsmtp_label ) :
									?>
									<option value="<?php echo esc_attr( $dsmtp_id ); ?>" <?php selected( $dsmtp_settings['backup_mailer'], $dsmtp_id ); ?>><?php echo esc_html( $dsmtp_label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'The message is handed straight to the second mailer, so nothing is lost when the first one is down. Set the second mailer up first, and make sure it accepts the same from address.', 'demfilz-smtp-mailer' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>

		<?php elseif ( 'log' === $dsmtp_tab ) : ?>

			<h2><?php esc_html_e( 'Email log', 'demfilz-smtp-mailer' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Keep a log', 'demfilz-smtp-mailer' ); ?></th>
						<td>
							<label><input type="checkbox" name="dsmtp[log_enabled]" value="1" <?php checked( ! empty( $dsmtp_settings['log_enabled'] ) ); ?>> <?php esc_html_e( 'Record every message this site sends', 'demfilz-smtp-mailer' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Store message bodies', 'demfilz-smtp-mailer' ); ?></th>
						<td>
							<label><input type="checkbox" name="dsmtp[log_body]" value="1" <?php checked( ! empty( $dsmtp_settings['log_body'] ) ); ?>> <?php esc_html_e( 'Also keep the content of each message', 'demfilz-smtp-mailer' ); ?></label>
							<p class="description"><?php esc_html_e( 'Useful while you are testing. It also means password reset links and customer details sit in your database, so leave it off on a live site unless you need it.', 'demfilz-smtp-mailer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dsmtp-retention"><?php esc_html_e( 'Delete entries after', 'demfilz-smtp-mailer' ); ?></label></th>
						<td>
							<input type="number" class="small-text" id="dsmtp-retention" name="dsmtp[log_retention_days]" min="0" value="<?php echo esc_attr( (string) $dsmtp_settings['log_retention_days'] ); ?>">
							<?php esc_html_e( 'days', 'demfilz-smtp-mailer' ); ?>
							<p class="description"><?php esc_html_e( 'Zero keeps entries forever.', 'demfilz-smtp-mailer' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Retries', 'demfilz-smtp-mailer' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Try again after a failure', 'demfilz-smtp-mailer' ); ?></th>
						<td>
							<label><input type="checkbox" name="dsmtp[retry_enabled]" value="1" <?php checked( ! empty( $dsmtp_settings['retry_enabled'] ) ); ?>> <?php esc_html_e( 'Queue failed messages and send them again in the background', 'demfilz-smtp-mailer' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dsmtp-retry-max"><?php esc_html_e( 'Attempts', 'demfilz-smtp-mailer' ); ?></label></th>
						<td>
							<input type="number" class="small-text" id="dsmtp-retry-max" name="dsmtp[retry_max]" min="0" max="10" value="<?php echo esc_attr( (string) $dsmtp_settings['retry_max'] ); ?>">
							<p class="description"><?php esc_html_e( 'The wait doubles each time, starting at five minutes. Retries need WP Cron to be running.', 'demfilz-smtp-mailer' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Alerts', 'demfilz-smtp-mailer' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Tell me when mail fails', 'demfilz-smtp-mailer' ); ?></th>
						<td>
							<label><input type="checkbox" name="dsmtp[alerts_enabled]" value="1" <?php checked( ! empty( $dsmtp_settings['alerts_enabled'] ) ); ?>> <?php esc_html_e( 'Send an alert when a message cannot be delivered', 'demfilz-smtp-mailer' ); ?></label>
							<p class="description"><?php esc_html_e( 'At most one alert an hour. A failure notice always appears in the dashboard, whether alerts are on or not.', 'demfilz-smtp-mailer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dsmtp-alert-email"><?php esc_html_e( 'Alert email', 'demfilz-smtp-mailer' ); ?></label></th>
						<td>
							<input type="email" class="regular-text" id="dsmtp-alert-email" name="dsmtp[alert_email]" value="<?php echo esc_attr( $dsmtp_settings['alert_email'] ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>">
							<p class="description"><?php esc_html_e( 'A mailbox on a different service is the safest choice, since the alert travels through the mailer that just failed.', 'demfilz-smtp-mailer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dsmtp-alert-webhook"><?php esc_html_e( 'Alert webhook', 'demfilz-smtp-mailer' ); ?></label></th>
						<td>
							<input type="url" class="regular-text" id="dsmtp-alert-webhook" name="dsmtp[alert_webhook]" value="<?php echo esc_attr( $dsmtp_settings['alert_webhook'] ); ?>" placeholder="https://">
							<p class="description"><?php esc_html_e( 'The failure is posted as JSON, which suits a chat incoming webhook or your own endpoint.', 'demfilz-smtp-mailer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Weekly summary', 'demfilz-smtp-mailer' ); ?></th>
						<td>
							<label><input type="checkbox" name="dsmtp[summary_enabled]" value="1" <?php checked( ! empty( $dsmtp_settings['summary_enabled'] ) ); ?>> <?php esc_html_e( 'Email a summary of the last seven days once a week', 'demfilz-smtp-mailer' ); ?></label>
							<p><input type="email" class="regular-text" name="dsmtp[summary_email]" value="<?php echo esc_attr( $dsmtp_settings['summary_email'] ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>"></p>
						</td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Delivery feedback', 'demfilz-smtp-mailer' ); ?></h2>
			<p><?php esc_html_e( 'Find out what happened after the send. Bounces and spam complaints reported by your mailer are written back to the email log, and the addresses concerned go on the suppression list so they are never emailed again.', 'demfilz-smtp-mailer' ); ?></p>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Suppression', 'demfilz-smtp-mailer' ); ?></th>
						<td>
							<p><label><input type="checkbox" name="dsmtp[suppress_on_bounce]" value="1" <?php checked( ! empty( $dsmtp_settings['suppress_on_bounce'] ) ); ?>> <?php esc_html_e( 'Stop emailing an address after a hard bounce', 'demfilz-smtp-mailer' ); ?></label></p>
							<p><label><input type="checkbox" name="dsmtp[suppress_on_complaint]" value="1" <?php checked( ! empty( $dsmtp_settings['suppress_on_complaint'] ) ); ?>> <?php esc_html_e( 'Stop emailing an address after a spam complaint', 'demfilz-smtp-mailer' ); ?></label></p>
							<p class="description"><?php esc_html_e( 'Repeatedly sending to dead or unwilling addresses is what gets a domain throttled. Manage the list under Mail, Suppression list.', 'demfilz-smtp-mailer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Microsoft and Google mailboxes', 'demfilz-smtp-mailer' ); ?></th>
						<td>
							<label><input type="checkbox" name="dsmtp[read_bounces]" value="1" <?php checked( ! empty( $dsmtp_settings['read_bounces'] ) ); ?>> <?php esc_html_e( 'Read bounce reports from the connected mailbox every fifteen minutes', 'demfilz-smtp-mailer' ); ?></label>
							<p class="description"><?php esc_html_e( 'Microsoft and Google do not post events, but every bounce comes back to the sending mailbox as a delivery report. This reads those reports, and nothing else. It needs read permission on the mailbox: Mail.Read for Microsoft (an application permission when using client credentials) or gmail.readonly for Google, so reconnect the mailer after turning it on.', 'demfilz-smtp-mailer' ); ?></p>
							<?php foreach ( DSMTP_Bounces::providers() as $dsmtp_read_id ) : ?>
								<?php if ( DSMTP_OAuth::is_connected( $dsmtp_read_id ) ) : ?>
									<p class="description">
										<?php
										$dsmtp_read_labels = DSMTP_Providers::labels();

										if ( DSMTP_Bounces::has_read_scope( $dsmtp_read_id ) ) {
											printf(
												/* translators: %s: mailer name. */
												esc_html__( '%s: read permission granted.', 'demfilz-smtp-mailer' ),
												esc_html( $dsmtp_read_labels[ $dsmtp_read_id ] )
											);
										} else {
											printf(
												/* translators: %s: mailer name. */
												esc_html__( '%s: connected without read permission. Disconnect and connect again once this is switched on.', 'demfilz-smtp-mailer' ),
												esc_html( $dsmtp_read_labels[ $dsmtp_read_id ] )
											);
										}
										?>
									</p>
								<?php endif; ?>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Sending services', 'demfilz-smtp-mailer' ); ?></th>
						<td>
							<p class="description"><?php esc_html_e( 'SendGrid, Mailgun, Brevo, Postmark and Resend can post delivery, bounce and complaint events to your site. Add the matching address below as a webhook in that service, for bounce, dropped, spam complaint and (optionally) delivered events. The long secret in the address is what proves the call came from your service; keep it to yourself.', 'demfilz-smtp-mailer' ); ?></p>
							<?php foreach ( DSMTP_Events::webhook_providers() as $dsmtp_hook_id ) : ?>
								<?php $dsmtp_hook_url = DSMTP_Events::webhook_url( $dsmtp_hook_id ); ?>
								<p>
									<strong><?php echo esc_html( DSMTP_Providers::labels()[ $dsmtp_hook_id ] ); ?></strong><br>
									<code class="dsmtp-copy"><?php echo esc_html( $dsmtp_hook_url ); ?></code>
									<button type="button" class="button button-small dsmtp-copy-button" data-dsmtp-target="<?php echo esc_attr( $dsmtp_hook_url ); ?>"><?php esc_html_e( 'Copy', 'demfilz-smtp-mailer' ); ?></button>
								</p>
							<?php endforeach; ?>
							<p>
								<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'dsmtp_feedback_token' ), DSMTP_Admin::post_url() ), 'dsmtp_feedback_token' ) ); ?>" data-dsmtp-confirm="token"><?php esc_html_e( 'Create a new secret', 'demfilz-smtp-mailer' ); ?></a>
								<span class="description"><?php esc_html_e( 'Every address above changes, so update the services afterwards.', 'demfilz-smtp-mailer' ); ?></span>
							</p>
						</td>
					</tr>
				</tbody>
			</table>

		<?php elseif ( 'controls' === $dsmtp_tab ) : ?>

			<h2><?php esc_html_e( 'Email controls', 'demfilz-smtp-mailer' ); ?></h2>
			<p><?php esc_html_e( 'Tick a message to stop WordPress sending it. Everything is left on until you change it here.', 'demfilz-smtp-mailer' ); ?></p>

			<table class="form-table" role="presentation">
				<tbody>
					<?php foreach ( DSMTP_Controls::available() as $dsmtp_key => $dsmtp_control ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $dsmtp_control['label'] ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="dsmtp[controls][<?php echo esc_attr( $dsmtp_key ); ?>]" value="1" <?php checked( ! empty( $dsmtp_settings['controls'][ $dsmtp_key ] ) ); ?>>
									<?php esc_html_e( 'Do not send this message', 'demfilz-smtp-mailer' ); ?>
								</label>
								<?php if ( '' !== $dsmtp_control['description'] ) : ?>
									<p class="description"><?php echo esc_html( $dsmtp_control['description'] ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

		<?php else : ?>

			<h2><?php esc_html_e( 'Advanced', 'demfilz-smtp-mailer' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Debug output', 'demfilz-smtp-mailer' ); ?></th>
						<td>
							<label><input type="checkbox" name="dsmtp[debug_smtp]" value="1" <?php checked( ! empty( $dsmtp_settings['debug_smtp'] ) ); ?>> <?php esc_html_e( 'Record the SMTP conversation for every message', 'demfilz-smtp-mailer' ); ?></label>
							<p class="description"><?php esc_html_e( 'The test email tool always shows this conversation, so you rarely need to turn it on here.', 'demfilz-smtp-mailer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'On uninstall', 'demfilz-smtp-mailer' ); ?></th>
						<td>
							<label><input type="checkbox" name="dsmtp[delete_data]" value="1" <?php checked( ! empty( $dsmtp_settings['delete_data'] ) ); ?>> <?php esc_html_e( 'Delete the settings, the stored tokens and the email log when the plugin is deleted', 'demfilz-smtp-mailer' ); ?></label>
						</td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Keeping credentials out of the database', 'demfilz-smtp-mailer' ); ?></h2>
			<p><?php esc_html_e( 'Every setting can be pinned in wp-config.php instead. A pinned value takes priority and cannot be edited in the dashboard. Passwords, keys and secrets stored here are encrypted with your site salts.', 'demfilz-smtp-mailer' ); ?></p>
			<pre class="dsmtp-code">define( 'DSMTP_MAILER', 'microsoft' );
define( 'DSMTP_FROM_EMAIL', 'notifications@example.com' );
define( 'DSMTP_FROM_NAME', 'Example Ltd' );
define( 'DSMTP_MICROSOFT_CLIENT_ID', '...' );
define( 'DSMTP_MICROSOFT_CLIENT_SECRET', '...' );
define( 'DSMTP_SMTP_HOST', 'mail.example.com' );
define( 'DSMTP_SMTP_PASSWORD', '...' );</pre>
			<p class="description"><?php esc_html_e( 'The pattern is DSMTP_ followed by the mailer name and the setting name, both in capitals.', 'demfilz-smtp-mailer' ); ?></p>

		<?php endif; ?>

		<?php submit_button(); ?>
	</form>
</div>
