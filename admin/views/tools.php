<?php
/**
 * Test email, domain records and diagnostics.
 *
 * @package Demfilz_SMTP_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dsmtp_provider = DSMTP_Providers::active();
$dsmtp_from     = (string) DSMTP_Settings::get( 'from_email', '' );
$dsmtp_result   = get_transient( 'dsmtp_test_result_' . get_current_user_id() );
$dsmtp_check    = false;

if ( isset( $_GET['dsmtp_check'] ) && check_admin_referer( 'dsmtp_check' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified on the line itself.
	$dsmtp_check = true;
}

if ( $dsmtp_result ) {
	delete_transient( 'dsmtp_test_result_' . get_current_user_id() );
}
?>
<div class="wrap dsmtp-wrap">
	<h1><?php esc_html_e( 'Mail tools', 'demfilz-smtp-mailer' ); ?></h1>

	<h2><?php esc_html_e( 'Send a test email', 'demfilz-smtp-mailer' ); ?></h2>

	<form method="post" action="<?php echo esc_url( DSMTP_Admin::post_url() ); ?>">
		<?php wp_nonce_field( 'dsmtp_test' ); ?>
		<input type="hidden" name="action" value="dsmtp_test">
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="dsmtp-to"><?php esc_html_e( 'Send to', 'demfilz-smtp-mailer' ); ?></label></th>
					<td>
						<input type="email" class="regular-text" id="dsmtp-to" name="dsmtp_to" value="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>" required>
						<p class="description">
							<?php
							printf(
								/* translators: %s: mailer name. */
								esc_html__( 'The message goes out through %s, exactly as a real one would.', 'demfilz-smtp-mailer' ),
								esc_html( $dsmtp_provider::label() )
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Format', 'demfilz-smtp-mailer' ); ?></th>
					<td><label><input type="checkbox" name="dsmtp_html" value="1" checked> <?php esc_html_e( 'Send as HTML', 'demfilz-smtp-mailer' ); ?></label></td>
				</tr>
			</tbody>
		</table>
		<?php submit_button( __( 'Send test email', 'demfilz-smtp-mailer' ) ); ?>
	</form>

	<?php if ( is_array( $dsmtp_result ) ) : ?>
		<div class="dsmtp-test-result <?php echo ! empty( $dsmtp_result['sent'] ) ? 'is-good' : 'is-bad'; ?>">
			<h3>
				<?php
				if ( ! empty( $dsmtp_result['sent'] ) ) {
					esc_html_e( 'The test message was accepted for delivery.', 'demfilz-smtp-mailer' );
				} else {
					esc_html_e( 'The test message could not be sent.', 'demfilz-smtp-mailer' );
				}
				?>
			</h3>

			<?php if ( ! empty( $dsmtp_result['error'] ) ) : ?>
				<p class="dsmtp-entry-error"><?php echo esc_html( $dsmtp_result['error'] ); ?></p>
			<?php elseif ( ! empty( $dsmtp_result['sent'] ) ) : ?>
				<p><?php esc_html_e( 'If it does not arrive, check the spam folder and then your domain records below.', 'demfilz-smtp-mailer' ); ?></p>
			<?php endif; ?>

			<?php if ( ! empty( $dsmtp_result['debug'] ) ) : ?>
				<details>
					<summary><?php esc_html_e( 'Show the conversation with the mail server', 'demfilz-smtp-mailer' ); ?></summary>
					<pre class="dsmtp-code"><?php echo esc_html( implode( "\n", (array) $dsmtp_result['debug'] ) ); ?></pre>
				</details>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php
	$dsmtp_readable = array();

	foreach ( DSMTP_Bounces::providers() as $dsmtp_read_id ) {
		if ( DSMTP_OAuth::is_connected( $dsmtp_read_id ) ) {
			$dsmtp_readable[] = $dsmtp_read_id;
		}
	}
	?>
	<?php if ( $dsmtp_readable && DSMTP_Settings::get( 'read_bounces', 0 ) ) : ?>
		<h2><?php esc_html_e( 'Bounce reports', 'demfilz-smtp-mailer' ); ?></h2>
		<p><?php esc_html_e( 'The connected mailbox is checked for delivery reports every fifteen minutes. Check it now to see the result straight away.', 'demfilz-smtp-mailer' ); ?></p>
		<p><a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'dsmtp_poll_bounces' ), DSMTP_Admin::post_url() ), 'dsmtp_poll_bounces' ) ); ?>"><?php esc_html_e( 'Check for bounce reports now', 'demfilz-smtp-mailer' ); ?></a></p>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Domain records', 'demfilz-smtp-mailer' ); ?></h2>
	<p><?php esc_html_e( 'Inboxes decide whether to trust your mail by looking up three records for your sending domain. Missing records are the usual reason a correctly configured mailer still lands in spam.', 'demfilz-smtp-mailer' ); ?></p>

	<form method="get">
		<input type="hidden" name="page" value="dsmtp-tools">
		<input type="hidden" name="dsmtp_check" value="1">
		<?php wp_nonce_field( 'dsmtp_check' ); ?>
		<?php submit_button( __( 'Check my domain', 'demfilz-smtp-mailer' ), 'secondary', 'dsmtp_check_submit', false ); ?>
	</form>

	<?php
	if ( $dsmtp_check ) :
		$dsmtp_dns = DSMTP_DNS::inspect( $dsmtp_from, $dsmtp_provider::id() );
		?>
		<?php if ( '' !== $dsmtp_dns['error'] ) : ?>
			<p class="dsmtp-entry-error"><?php echo esc_html( $dsmtp_dns['error'] ); ?></p>
		<?php else : ?>
			<table class="widefat striped dsmtp-entry">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Domain', 'demfilz-smtp-mailer' ); ?></th>
						<td><code><?php echo esc_html( $dsmtp_dns['domain'] ); ?></code></td>
					</tr>
					<tr>
						<th scope="row">SPF</th>
						<td>
							<?php if ( '' === $dsmtp_dns['spf'] ) : ?>
								<span class="dsmtp-pill dsmtp-pill-failed"><?php esc_html_e( 'Missing', 'demfilz-smtp-mailer' ); ?></span>
								<p><?php esc_html_e( 'No SPF record was found. Add one that lists whoever sends mail for this domain.', 'demfilz-smtp-mailer' ); ?></p>
							<?php else : ?>
								<span class="dsmtp-pill dsmtp-pill-<?php echo $dsmtp_dns['spf_ok'] ? 'sent' : 'queued'; ?>">
									<?php echo $dsmtp_dns['spf_ok'] ? esc_html__( 'Found', 'demfilz-smtp-mailer' ) : esc_html__( 'Check this', 'demfilz-smtp-mailer' ); ?>
								</span>
								<pre class="dsmtp-code"><?php echo esc_html( $dsmtp_dns['spf'] ); ?></pre>
								<?php if ( ! $dsmtp_dns['spf_ok'] && '' !== $dsmtp_dns['spf_expected'] ) : ?>
									<p>
										<?php
										printf(
											/* translators: %s: SPF include value, for example spf.protection.outlook.com. */
											esc_html__( 'This mailer normally needs include:%s in the record.', 'demfilz-smtp-mailer' ),
											esc_html( $dsmtp_dns['spf_expected'] )
										);
										?>
									</p>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">DKIM</th>
						<td>
							<?php if ( ! $dsmtp_dns['dkim_checked'] ) : ?>
								<p><?php esc_html_e( 'This mailer does not use a predictable DKIM selector, so nothing was looked up. Follow your provider instructions and confirm signing in their dashboard.', 'demfilz-smtp-mailer' ); ?></p>
							<?php elseif ( ! $dsmtp_dns['dkim'] ) : ?>
								<span class="dsmtp-pill dsmtp-pill-failed"><?php esc_html_e( 'Not found', 'demfilz-smtp-mailer' ); ?></span>
								<p>
									<?php
									printf(
										/* translators: %s: comma separated list of DKIM selectors. */
										esc_html__( 'Nothing answered for the usual selectors (%s). Turn DKIM signing on in your mail provider and publish the records they give you.', 'demfilz-smtp-mailer' ),
										esc_html( implode( ', ', $dsmtp_dns['dkim_checked'] ) )
									);
									?>
								</p>
							<?php else : ?>
								<span class="dsmtp-pill dsmtp-pill-sent"><?php esc_html_e( 'Found', 'demfilz-smtp-mailer' ); ?></span>
								<ul class="dsmtp-list">
									<?php foreach ( $dsmtp_dns['dkim'] as $dsmtp_selector => $dsmtp_value ) : ?>
										<li><code><?php echo esc_html( $dsmtp_selector ); ?></code> <?php echo esc_html( wp_html_excerpt( $dsmtp_value, 90, '...' ) ); ?></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">DMARC</th>
						<td>
							<?php if ( '' === $dsmtp_dns['dmarc'] ) : ?>
								<span class="dsmtp-pill dsmtp-pill-queued"><?php esc_html_e( 'Missing', 'demfilz-smtp-mailer' ); ?></span>
								<p><?php esc_html_e( 'Add a DMARC record at _dmarc on your domain. Start with v=DMARC1; p=none and tighten it once you know all your mail passes.', 'demfilz-smtp-mailer' ); ?></p>
							<?php else : ?>
								<span class="dsmtp-pill dsmtp-pill-sent"><?php esc_html_e( 'Found', 'demfilz-smtp-mailer' ); ?></span>
								<pre class="dsmtp-code"><?php echo esc_html( $dsmtp_dns['dmarc'] ); ?></pre>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">MX</th>
						<td>
							<?php if ( ! $dsmtp_dns['mx'] ) : ?>
								<p><?php esc_html_e( 'No MX records. That only matters if you also receive mail at this domain.', 'demfilz-smtp-mailer' ); ?></p>
							<?php else : ?>
								<ul class="dsmtp-list">
									<?php foreach ( $dsmtp_dns['mx'] as $dsmtp_host ) : ?>
										<li><code><?php echo esc_html( $dsmtp_host ); ?></code></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>
		<?php endif; ?>
	<?php endif; ?>

	<?php $dsmtp_sources = DSMTP_Import::available(); ?>
	<?php if ( $dsmtp_sources ) : ?>
		<h2><?php esc_html_e( 'Import from another plugin', 'demfilz-smtp-mailer' ); ?></h2>
		<p><?php esc_html_e( 'Settings from these plugins were found on this site. Importing copies the from address and the SMTP connection over. Passwords come across only when the other plugin stored them in a readable form.', 'demfilz-smtp-mailer' ); ?></p>
		<form method="post" action="<?php echo esc_url( DSMTP_Admin::post_url() ); ?>">
			<?php wp_nonce_field( 'dsmtp_import' ); ?>
			<input type="hidden" name="action" value="dsmtp_import">
			<p>
				<select name="source">
					<?php foreach ( $dsmtp_sources as $dsmtp_key => $dsmtp_label ) : ?>
						<option value="<?php echo esc_attr( $dsmtp_key ); ?>"><?php echo esc_html( $dsmtp_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Import settings', 'demfilz-smtp-mailer' ); ?></button>
			</p>
		</form>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Diagnostics', 'demfilz-smtp-mailer' ); ?></h2>
	<?php
	$dsmtp_reflection = '';

	if ( function_exists( 'wp_mail' ) ) {
		try {
			$dsmtp_ref        = new ReflectionFunction( 'wp_mail' );
			$dsmtp_reflection = wp_normalize_path( (string) $dsmtp_ref->getFileName() );
			$dsmtp_reflection = str_replace( wp_normalize_path( ABSPATH ), '', $dsmtp_reflection );
		} catch ( ReflectionException $e ) {
			$dsmtp_reflection = '';
		}
	}

	$dsmtp_rows = array(
		__( 'Selected mailer', 'demfilz-smtp-mailer' )    => $dsmtp_provider::label(),
		__( 'From address', 'demfilz-smtp-mailer' )       => '' !== $dsmtp_from ? $dsmtp_from : __( 'not set', 'demfilz-smtp-mailer' ),
		__( 'PHP version', 'demfilz-smtp-mailer' )        => PHP_VERSION,
		__( 'WordPress version', 'demfilz-smtp-mailer' )  => get_bloginfo( 'version' ),
		__( 'OpenSSL', 'demfilz-smtp-mailer' )            => extension_loaded( 'openssl' ) ? __( 'available', 'demfilz-smtp-mailer' ) : __( 'missing, TLS connections will fail', 'demfilz-smtp-mailer' ),
		__( 'cURL', 'demfilz-smtp-mailer' )               => function_exists( 'curl_version' ) ? __( 'available', 'demfilz-smtp-mailer' ) : __( 'missing', 'demfilz-smtp-mailer' ),
		__( 'DNS lookups', 'demfilz-smtp-mailer' )        => 'dns' === DSMTP_DNS::method() ? __( 'PHP resolver', 'demfilz-smtp-mailer' ) : __( 'over HTTPS', 'demfilz-smtp-mailer' ),
		__( 'Encryption at rest', 'demfilz-smtp-mailer' ) => function_exists( 'sodium_crypto_secretbox' ) || function_exists( 'openssl_encrypt' ) ? __( 'on', 'demfilz-smtp-mailer' ) : __( 'not available on this host', 'demfilz-smtp-mailer' ),
		__( 'wp_mail defined in', 'demfilz-smtp-mailer' ) => '' !== $dsmtp_reflection ? $dsmtp_reflection : __( 'unknown', 'demfilz-smtp-mailer' ),
		__( 'WP Cron', 'demfilz-smtp-mailer' )            => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? __( 'disabled, retries need a real cron job', 'demfilz-smtp-mailer' ) : __( 'running', 'demfilz-smtp-mailer' ),
		__( 'Next retry run', 'demfilz-smtp-mailer' )     => wp_next_scheduled( DSMTP_Cron::RETRY_HOOK ) ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) wp_next_scheduled( DSMTP_Cron::RETRY_HOOK ) ), get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : __( 'not scheduled', 'demfilz-smtp-mailer' ),
	);
	?>
	<table class="widefat striped dsmtp-entry">
		<tbody>
			<?php foreach ( $dsmtp_rows as $dsmtp_label => $dsmtp_value ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $dsmtp_label ); ?></th>
					<td><?php echo esc_html( (string) $dsmtp_value ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
