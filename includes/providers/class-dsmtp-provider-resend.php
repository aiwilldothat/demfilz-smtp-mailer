<?php
/**
 * Resend API transport.
 *
 * @package Demfilz_SMTP_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends through the Resend email API.
 */
class DSMTP_Provider_Resend extends DSMTP_Provider {

	const ENDPOINT = 'https://api.resend.com/emails';

	/**
	 * Provider identifier.
	 *
	 * @return string
	 */
	public static function id() {
		return 'resend';
	}

	/**
	 * Human readable name.
	 *
	 * @return string
	 */
	public static function label() {
		return __( 'Resend', 'demfilz-smtp-mailer' );
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
		return __( 'A simple modern sending API. Add and verify your domain in Resend first.', 'demfilz-smtp-mailer' );
	}

	/**
	 * Setup notes.
	 *
	 * @return string
	 */
	public static function setup_notes() {
		return __( 'Create an API key with sending access in the Resend dashboard, and verify the domain your from address belongs to.', 'demfilz-smtp-mailer' );
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
		$body  = array(
			'from'    => $this->format_address( $mail->From, $mail->FromName ),
			'subject' => $mail->Subject,
		);

		foreach ( array(
			'to'       => $mail->getToAddresses(),
			'cc'       => $mail->getCcAddresses(),
			'bcc'      => $mail->getBccAddresses(),
			'reply_to' => $mail->getReplyToAddresses(),
		) as $key => $list ) {
			$people = array();

			foreach ( $this->addresses( $list ) as $address ) {
				$people[] = $this->format_address( $address['email'], $address['name'] );
			}

			if ( $people ) {
				$body[ $key ] = $people;
			}
		}

		if ( empty( $body['to'] ) ) {
			return new WP_Error( 'dsmtp_no_recipients', __( 'The message has no recipients.', 'demfilz-smtp-mailer' ) );
		}

		if ( '' !== $parts['html'] ) {
			$body['html'] = $parts['html'];
		}

		if ( '' !== $parts['text'] ) {
			$body['text'] = $parts['text'];
		}

		if ( empty( $body['html'] ) && empty( $body['text'] ) ) {
			$body['text'] = ' ';
		}

		foreach ( $attachments as $attachment ) {
			$body['attachments'][] = array(
				'filename'     => $attachment['name'],
				'content'      => $attachment['content'],
				'content_type' => $attachment['type'],
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
					'Authorization' => 'Bearer ' . $this->option( 'api_key', '' ),
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = $this->decode( $response );

		$this->last_message_id = isset( $data['id'] ) ? (string) $data['id'] : '';

		return true;
	}
}
