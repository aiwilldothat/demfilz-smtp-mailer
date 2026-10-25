<?php
/**
 * SendGrid API transport.
 *
 * @package Demfilz_SMTP_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends through the SendGrid v3 mail API.
 */
class DSMTP_Provider_SendGrid extends DSMTP_Provider {

	const ENDPOINT = 'https://api.sendgrid.com/v3/mail/send';

	/**
	 * Provider identifier.
	 *
	 * @return string
	 */
	public static function id() {
		return 'sendgrid';
	}

	/**
	 * Human readable name.
	 *
	 * @return string
	 */
	public static function label() {
		return __( 'SendGrid', 'demfilz-smtp-mailer' );
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
		return __( 'A sending service with a free tier. Verify your sending domain in SendGrid first.', 'demfilz-smtp-mailer' );
	}

	/**
	 * Setup notes.
	 *
	 * @return string
	 */
	public static function setup_notes() {
		return __( 'Create an API key with the Mail Send permission in the SendGrid dashboard, then authenticate the domain your from address uses.', 'demfilz-smtp-mailer' );
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

		$parts           = $this->body_parts( $mail );
		$personalization = array();

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
				$personalization[ $key ] = $people;
			}
		}

		if ( empty( $personalization['to'] ) ) {
			return new WP_Error( 'dsmtp_no_recipients', __( 'The message has no recipients.', 'demfilz-smtp-mailer' ) );
		}

		$body = array(
			'personalizations' => array( $personalization ),
			'from'             => array(
				'email' => $mail->From,
				'name'  => $mail->FromName,
			),
			'subject'          => $mail->Subject,
			'content'          => array(),
		);

		if ( '' !== $parts['text'] ) {
			$body['content'][] = array(
				'type'  => 'text/plain',
				'value' => $parts['text'],
			);
		}

		if ( '' !== $parts['html'] ) {
			$body['content'][] = array(
				'type'  => 'text/html',
				'value' => $parts['html'],
			);
		}

		if ( ! $body['content'] ) {
			$body['content'][] = array(
				'type'  => 'text/plain',
				'value' => ' ',
			);
		}

		$reply_to = $this->addresses( $mail->getReplyToAddresses() );

		if ( $reply_to ) {
			$body['reply_to'] = array(
				'email' => $reply_to[0]['email'],
				'name'  => $reply_to[0]['name'],
			);
		}

		foreach ( $attachments as $attachment ) {
			$item = array(
				'content'     => $attachment['content'],
				'filename'    => $attachment['name'],
				'type'        => $attachment['type'],
				'disposition' => 'inline' === $attachment['disposition'] ? 'inline' : 'attachment',
			);

			if ( 'inline' === $attachment['disposition'] && '' !== $attachment['cid'] ) {
				$item['content_id'] = $attachment['cid'];
			}

			$body['attachments'][] = $item;
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

		$this->last_message_id = (string) wp_remote_retrieve_header( $response, 'x-message-id' );

		return true;
	}
}
