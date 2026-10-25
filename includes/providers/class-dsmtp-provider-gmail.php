<?php
/**
 * Gmail and Google Workspace sending through the Gmail API.
 *
 * @package Demfilz_SMTP_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends as a Google mailbox with OAuth, no application password needed.
 */
class DSMTP_Provider_Gmail extends DSMTP_Provider {

	const ENDPOINT = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';

	/**
	 * Provider identifier.
	 *
	 * @return string
	 */
	public static function id() {
		return 'gmail';
	}

	/**
	 * Human readable name.
	 *
	 * @return string
	 */
	public static function label() {
		return __( 'Google Workspace and Gmail', 'demfilz-smtp-mailer' );
	}

	/**
	 * Group heading.
	 *
	 * @return string
	 */
	public static function group() {
		return __( 'Google', 'demfilz-smtp-mailer' );
	}

	/**
	 * One line summary.
	 *
	 * @return string
	 */
	public static function description() {
		return __( 'Sends through the Gmail API with OAuth, which avoids application passwords and the daily limits of the Gmail SMTP relay.', 'demfilz-smtp-mailer' );
	}

	/**
	 * Setup notes.
	 *
	 * @return string
	 */
	public static function setup_notes() {
		return __( 'In the Google Cloud console create a project, enable the Gmail API, configure the consent screen, then create an OAuth client id of type Web application with the redirect URI below. Paste the client id and secret here, save, and press Connect.', 'demfilz-smtp-mailer' );
	}

	/**
	 * Connection fields.
	 *
	 * @return array
	 */
	public static function fields() {
		return array(
			'client_id'     => array(
				'label'    => __( 'Client id', 'demfilz-smtp-mailer' ),
				'type'     => 'text',
				'required' => true,
			),
			'client_secret' => array(
				'label'    => __( 'Client secret', 'demfilz-smtp-mailer' ),
				'type'     => 'password',
				'secret'   => true,
				'required' => true,
			),
			'mailbox'       => array(
				'label'       => __( 'Mailbox address', 'demfilz-smtp-mailer' ),
				'type'        => 'email',
				'description' => __( 'Filled in for you when you connect.', 'demfilz-smtp-mailer' ),
			),
		);
	}

	/**
	 * Scopes this mailer asks for.
	 *
	 * @return array
	 */
	protected function scopes() {
		$scopes = array( 'https://www.googleapis.com/auth/gmail.send', 'openid', 'email' );

		if ( DSMTP_Settings::get( 'read_bounces', 0 ) ) {
			// Needed to read delivery reports out of the mailbox.
			$scopes[] = 'https://www.googleapis.com/auth/gmail.readonly';
		}

		return $scopes;
	}

	/**
	 * OAuth endpoints and credentials.
	 *
	 * @return array
	 */
	public function oauth_config() {
		return array(
			'authorize'     => 'https://accounts.google.com/o/oauth2/v2/auth',
			'token'         => 'https://oauth2.googleapis.com/token',
			'client_id'     => (string) $this->option( 'client_id', '' ),
			'client_secret' => (string) $this->option( 'client_secret', '' ),
			'scopes'        => $this->scopes(),
			'extra_auth'    => array(
				'access_type' => 'offline',
				'prompt'      => 'consent',
			),
			'app_only'      => false,
		);
	}

	/**
	 * Look up the connected mailbox address.
	 *
	 * @param string $access_token Access token.
	 * @return string
	 */
	public function fetch_account( $access_token ) {
		$response = wp_remote_get(
			'https://openidconnect.googleapis.com/v1/userinfo',
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$data    = json_decode( wp_remote_retrieve_body( $response ), true );
		$address = is_array( $data ) && ! empty( $data['email'] ) ? (string) $data['email'] : '';

		if ( is_email( $address ) && '' === (string) $this->option( 'mailbox', '' ) ) {
			DSMTP_Settings::set_provider_value( static::id(), 'mailbox', $address );
			$this->config['mailbox'] = $address;
		}

		return $address;
	}

	/**
	 * Check the configuration.
	 *
	 * @return true|WP_Error
	 */
	public function validate() {
		$base = parent::validate();

		if ( is_wp_error( $base ) ) {
			return $base;
		}

		if ( ! DSMTP_OAuth::is_connected( static::id() ) ) {
			return new WP_Error( 'dsmtp_disconnected', __( 'Press Connect to sign in to the Google account.', 'demfilz-smtp-mailer' ) );
		}

		return true;
	}

	/**
	 * The address Google will accept as the sender.
	 *
	 * @return string
	 */
	public function forced_from_email() {
		$mailbox = (string) $this->option( 'mailbox', '' );

		return is_email( $mailbox ) ? $mailbox : '';
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

		$token = DSMTP_OAuth::access_token( static::id() );

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$raw = rtrim( strtr( $this->mime( $mail ), '+/', '-_' ), '=' );

		$response = $this->request(
			self::ENDPOINT,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( array( 'raw' => $raw ) ),
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
