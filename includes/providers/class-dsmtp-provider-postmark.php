<?php
/**
 * Postmark API transport.
 *
 * @package Demfilz_SMTP_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends through the Postmark email API.
 */
class DSMTP_Provider_Postmark extends DSMTP_Provider {

	const ENDPOINT = 'https://api.postmarkapp.com/email';

	/**
	 * Provider identifier.
	 *
	 * @return string
	 */
	public static function id() {
		return 'postmark';
	}

	/**
	 * Human readable name.
	 *
	 * @return string
	 */
	public static function label() {
		return __( 'Postmark', 'demfilz-smtp-mailer' );
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
		return __( 'Built for transactional mail, with fast delivery and clear bounce reporting.', 'demfilz-smtp-mailer' );
	}

	/**
	 * Setup notes.
	 *
	 * @return string
	 */
	public static function setup_notes() {
		return __( 'Copy the Server API token from your Postmark server, and confirm the sender signature or domain for the from address you use.', 'demfilz-smtp-mailer' );
	}

	/**
	 * Connection fields.
	 *
	 * @return array
	 */
	public static function fields() {
		return array(
			'api_key' => array(
				'label'    => __( 'Server API token', 'demfilz-smtp-mailer' ),
				'type'     => 'password',
				'secret'   => true,
				'required' => true,
			),
			'stream'  => array(
				'label'       => __( 'Message stream', 'demfilz-smtp-mailer' ),
				'type'        => 'text',
				'default'     => 'outbound',
				'description' => __( 'Leave as outbound unless you created a different stream in Postmark.', 'demfilz-smtp-mailer' ),
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
		$to    = $this->address_list( $mail->getToAddresses() );

		if ( '' === $to ) {
			return new WP_Error( 'dsmtp_no_recipients', __( 'The message has no recipients.', 'demfilz-smtp-mailer' ) );
		}

		$body = array(
			'From'          => $this->format_address( $mail->From, $mail->FromName ),
			'To'            => $to,
			'Subject'       => $mail->Subject,
			'MessageStream' => (string) $this->option( 'stream', 'outbound' ),
		);

		$cc  = $this->address_list( $mail->getCcAddresses() );
		$bcc = $this->address_list( $mail->getBccAddresses() );

		if ( '' !== $cc ) {
			$body['Cc'] = $cc;
		}

		if ( '' !== $bcc ) {
			$body['Bcc'] = $bcc;
		}

		$reply_to = $this->address_list( $mail->getReplyToAddresses() );

		if ( '' !== $reply_to ) {
			$body['ReplyTo'] = $reply_to;
		}

		if ( '' !== $parts['html'] ) {
			$body['HtmlBody'] = $parts['html'];
		}

		if ( '' !== $parts['text'] ) {
			$body['TextBody'] = $parts['text'];
		}

		if ( empty( $body['HtmlBody'] ) && empty( $body['TextBody'] ) ) {
			$body['TextBody'] = ' ';
		}

		foreach ( $attachments as $attachment ) {
			$item = array(
				'Name'        => $attachment['name'],
				'Content'     => $attachment['content'],
				'ContentType' => $attachment['type'],
			);

			if ( 'inline' === $attachment['disposition'] && '' !== $attachment['cid'] ) {
				$item['ContentID'] = 'cid:' . $attachment['cid'];
			}

			$body['Attachments'][] = $item;
		}

		foreach ( $this->custom_headers( $mail ) as $name => $value ) {
			$body['Headers'][] = array(
				'Name'  => $name,
				'Value' => $value,
			);
		}

		$response = $this->request(
			self::ENDPOINT,
			array(
				'headers' => array(
					'X-Postmark-Server-Token' => (string) $this->option( 'api_key', '' ),
					'Content-Type'            => 'application/json',
					'Accept'                  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = $this->decode( $response );

		if ( isset( $data['ErrorCode'] ) && 0 !== (int) $data['ErrorCode'] ) {
			return new WP_Error(
				'dsmtp_api_error',
				sprintf(
					/* translators: 1: error code, 2: error message. */
					__( 'Postmark rejected the message (code %1$d): %2$s', 'demfilz-smtp-mailer' ),
					(int) $data['ErrorCode'],
					isset( $data['Message'] ) ? wp_strip_all_tags( (string) $data['Message'] ) : ''
				)
			);
		}

		$this->last_message_id = isset( $data['MessageID'] ) ? (string) $data['MessageID'] : '';

		return true;
	}
}
