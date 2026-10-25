<?php
/**
 * Mailgun API transport.
 *
 * @package Demfilz_SMTP_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Posts the finished MIME message to Mailgun, so nothing is lost in translation.
 */
class DSMTP_Provider_Mailgun extends DSMTP_Provider {

	/**
	 * Provider identifier.
	 *
	 * @return string
	 */
	public static function id() {
		return 'mailgun';
	}

	/**
	 * Human readable name.
	 *
	 * @return string
	 */
	public static function label() {
		return __( 'Mailgun', 'demfilz-smtp-mailer' );
	}

	/**
	 * Group heading.
	 *
	 * @return string
	 */
	public static function group() {
		return __( 'Sending services', 'demfilz-smtp-mailer' );
	}

	/**
	 * One line summary.
	 *
	 * @return string
	 */
	public static function description() {
		return __( 'Good deliverability and detailed logs. Needs a verified sending domain.', 'demfilz-smtp-mailer' );
	}

	/**
	 * Setup notes.
	 *
	 * @return string
	 */
	public static function setup_notes() {
		return __( 'Copy a sending API key from the Mailgun dashboard and enter the domain you verified there. Pick the European region if your Mailgun account was created in the EU.', 'demfilz-smtp-mailer' );
	}

	/**
	 * Connection fields.
	 *
	 * @return array
	 */
	public static function fields() {
		return array(
			'api_key'     => array(
				'label'    => __( 'API key', 'demfilz-smtp-mailer' ),
				'type'     => 'password',
				'secret'   => true,
				'required' => true,
			),
			'domain'      => array(
				'label'       => __( 'Sending domain', 'demfilz-smtp-mailer' ),
				'type'        => 'text',
				'required'    => true,
				'placeholder' => 'mg.example.com',
			),
			'region'      => array(
				'label'   => __( 'Region', 'demfilz-smtp-mailer' ),
				'type'    => 'select',
				'default' => 'us',
				'options' => array(
					'us' => __( 'United States', 'demfilz-smtp-mailer' ),
					'eu' => __( 'Europe', 'demfilz-smtp-mailer' ),
				),
			),
			'webhook_key' => array(
				'label'       => __( 'Webhook signing key', 'demfilz-smtp-mailer' ),
				'type'        => 'password',
				'secret'      => true,
				'description' => __( 'Optional. From Webhooks in the Mailgun dashboard. When set, delivery events are also checked against this signature, not just the secret in the webhook address.', 'demfilz-smtp-mailer' ),
			),
		);
	}

	/**
	 * The messages endpoint for the configured region and domain.
	 *
	 * @return string
	 */
	protected function endpoint() {
		$host = 'eu' === (string) $this->option( 'region', 'us' ) ? 'api.eu.mailgun.net' : 'api.mailgun.net';

		return 'https://' . $host . '/v3/' . rawurlencode( (string) $this->option( 'domain', '' ) ) . '/messages.mime';
	}

	/**
	 * Send the message.
	 *
	 * @param DSMTP_Mail_Catcher $mail Prepared mailer.
	 * @return true|WP_Error
	 */
	public function send( $mail ) {
		$valid = $this->validate();

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$recipients = array();

		foreach ( $mail->getAllRecipientAddresses() as $email => $unused ) {
			$recipients[] = $email;
		}

		if ( ! $recipients ) {
			return new WP_Error( 'dsmtp_no_recipients', __( 'The message has no recipients.', 'demfilz-smtp-mailer' ) );
		}

		$boundary = 'dsmtp' . wp_generate_password( 24, false );
		$body     = '';

		foreach ( $recipients as $recipient ) {
			$body .= '--' . $boundary . "\r\n";
			$body .= 'Content-Disposition: form-data; name="to"' . "\r\n\r\n";
			$body .= $recipient . "\r\n";
		}

		$body .= '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="message"; filename="message.mime"' . "\r\n";
		$body .= 'Content-Type: application/octet-stream' . "\r\n\r\n";
		$body .= $mail->getSentMIMEMessage() . "\r\n";
		$body .= '--' . $boundary . "--\r\n";

		$response = $this->request(
			$this->endpoint(),
			array(
				'headers' => array(
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP basic authentication encoding.
					'Authorization' => 'Basic ' . base64_encode( 'api:' . $this->option( 'api_key', '' ) ),
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = $this->decode( $response );

		$this->last_message_id = isset( $data['id'] ) ? trim( (string) $data['id'], '<>' ) : '';

		return true;
	}
}
