<?php
/**
 * Brevo API transport.
 *
 * @package Demfilz_SMTP_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends through the Brevo transactional email API.
 */
class DSMTP_Provider_Brevo extends DSMTP_Provider {

	const ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

	/**
	 * Provider identifier.
	 *
	 * @return string
	 */
	public static function id() {
		return 'brevo';
	}

	/**
	 * Human readable name.
	 *
	 * @return string
	 */
	public static function label() {
		return __( 'Brevo', 'demfilz-smtp-mailer' );
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
		return __( 'Formerly Sendinblue. A generous free tier for transactional email.', 'demfilz-smtp-mailer' );
	}

	/**
	 * Setup notes.
	 *
	 * @return string
	 */
	public static function setup_notes() {
		return __( 'Create an API key under SMTP and API in your Brevo account, and add your sending domain there so it is authenticated.', 'demfilz-smtp-mailer' );
	}

	/**
	 * Connection fields.
	 *
	 * @return array
	 */
	public static function fields() {
		return array(
			'api_key' => array(
				'label'    => __( 'API key', 'demfilz-smtp-mailer' ),
				'type'     => 'password',
				'secret'   => true,
				'required' => true,
			),
		);
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

		$attachments = $this->attachments( $mail );

		if ( is_wp_error( $attachments ) ) {
			return $attachments;
		}

		$parts = $this->body_parts( $mail );

		$body = array(
			'sender'  => array(
				'email' => $mail->From,
				'name'  => $mail->FromName,
			),
			'subject' => $mail->Subject,
		);

		foreach ( array(
			'to'  => $mail->getToAddresses(),
			'cc'  => $mail->getCcAddresses(),
			'bcc' => $mail->getBccAddresses(),
		) as $key => $list ) {
			$people = array();

			foreach ( $this->addresses( $list ) as $address ) {
				$person = array( 'email' => $address['email'] );

				if ( '' !== $address['name'] ) {
					$person['name'] = $address['name'];
				}

				$people[] = $person;
			}

			if ( $people ) {
				$body[ $key ] = $people;
			}
		}

		if ( empty( $body['to'] ) ) {
			return new WP_Error( 'dsmtp_no_recipients', __( 'The message has no recipients.', 'demfilz-smtp-mailer' ) );
		}

		if ( '' !== $parts['html'] ) {
			$body['htmlContent'] = $parts['html'];
		}

		if ( '' !== $parts['text'] ) {
			$body['textContent'] = $parts['text'];
		}

		if ( empty( $body['htmlContent'] ) && empty( $body['textContent'] ) ) {
			$body['textContent'] = ' ';
		}

		$reply_to = $this->addresses( $mail->getReplyToAddresses() );

		if ( $reply_to ) {
			$body['replyTo'] = array(
				'email' => $reply_to[0]['email'],
				'name'  => $reply_to[0]['name'],
			);
		}

		foreach ( $attachments as $attachment ) {
			$body['attachment'][] = array(
				'name'    => $attachment['name'],
				'content' => $attachment['content'],
			);
		}

		$headers = $this->custom_headers( $mail );

		if ( $headers ) {
			$body['headers'] = $headers;
		}

		$response = $this->request(
			self::ENDPOINT,
			array(
				'headers' => array(
					'api-key'      => (string) $this->option( 'api_key', '' ),
					'Content-Type' => 'application/json',
					'accept'       => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = $this->decode( $response );

		$this->last_message_id = isset( $data['messageId'] ) ? trim( (string) $data['messageId'], '<>' ) : '';

		return true;
	}
}
