<?php
/**
 * Microsoft 365 and Outlook.com sending through the Graph API.
 *
 * @package Demfilz_SMTP_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Modern authentication for Microsoft mailboxes.
 *
 * Microsoft has switched basic authentication off across Exchange Online, so a
 * user name and password no longer work for most tenants. This mailer signs in
 * with OAuth instead and posts the finished message to the Graph API, which
 * works whether or not SMTP AUTH is enabled on the mailbox.
 */
class DSMTP_Provider_Microsoft extends DSMTP_Provider {

	const GRAPH = 'https://graph.microsoft.com/v1.0';

	/**
	 * Provider identifier.
	 *
	 * @return string
	 */
	public static function id() {
		return 'microsoft';
	}

	/**
	 * Human readable name.
	 *
	 * @return string
	 */
	public static function label() {
		return __( 'Microsoft 365 and Outlook (Graph API)', 'demfilz-smtp-mailer' );
	}

	/**
	 * Group heading.
	 *
	 * @return string
	 */
	public static function group() {
		return __( 'Microsoft', 'demfilz-smtp-mailer' );
	}

	/**
	 * How this provider sends.
	 *
	 * @return string
	 */
	public static function type() {
		return self::TYPE_API;
	}

	/**
	 * One line summary.
	 *
	 * @return string
	 */
	public static function description() {
		return __( 'Recommended for Microsoft 365, Exchange Online, Outlook.com and Hotmail mailboxes. Uses modern authentication, so it keeps working after basic authentication and SMTP AUTH are switched off.', 'demfilz-smtp-mailer' );
	}

	/**
	 * Setup notes.
	 *
	 * @return string
	 */
	public static function setup_notes() {
		return __( 'Register an application in the Microsoft Entra admin centre (Azure Active Directory), add the redirect URI shown below as a Web platform, create a client secret, then come back, save, and press Connect.', 'demfilz-smtp-mailer' );
	}

	/**
	 * Connection fields.
	 *
	 * @return array
	 */
	public static function fields() {
		return array(
			'auth_mode'     => array(
				'label'       => __( 'Sign in method', 'demfilz-smtp-mailer' ),
				'type'        => 'select',
				'default'     => 'delegated',
				'options'     => array(
					'delegated' => __( 'Sign in to one mailbox (delegated permissions)', 'demfilz-smtp-mailer' ),
					'app'       => __( 'Application permissions, no sign in (client credentials)', 'demfilz-smtp-mailer' ),
				),
				'description' => __( 'Signing in suits a single mailbox and takes two minutes. Application permissions need an administrator to consent once, never expire until the secret does, and can send from any mailbox in the tenant. Pair them with an application access policy so the site can only send from the mailbox you intend.', 'demfilz-smtp-mailer' ),
			),
			'tenant'        => array(
				'label'       => __( 'Directory (tenant) id', 'demfilz-smtp-mailer' ),
				'type'        => 'text',
				'default'     => 'common',
				'placeholder' => 'contoso.onmicrosoft.com',
				'description' => __( 'Copy it from the application overview page. Leave it as common only for personal Outlook.com accounts.', 'demfilz-smtp-mailer' ),
			),
			'client_id'     => array(
				'label'    => __( 'Application (client) id', 'demfilz-smtp-mailer' ),
				'type'     => 'text',
				'required' => true,
			),
			'client_secret' => array(
				'label'       => __( 'Client secret value', 'demfilz-smtp-mailer' ),
				'type'        => 'password',
				'secret'      => true,
				'required'    => true,
				'description' => __( 'The secret Value, not the Secret ID. Stored encrypted. Note the expiry date Microsoft gives it, because mail stops when it lapses.', 'demfilz-smtp-mailer' ),
			),
			'mailbox'       => array(
				'label'       => __( 'Send from mailbox', 'demfilz-smtp-mailer' ),
				'type'        => 'email',
				'description' => __( 'Required for application permissions. With a signed in mailbox this is filled in for you when you connect.', 'demfilz-smtp-mailer' ),
			),
			'save_sent'     => array(
				'label'       => __( 'Keep a copy in Sent Items', 'demfilz-smtp-mailer' ),
				'type'        => 'checkbox',
				'default'     => 0,
				'description' => __( 'Off keeps the mailbox tidy when the site sends a lot of notifications. On files every message in Sent Items.', 'demfilz-smtp-mailer' ),
			),
		);
	}

	/**
	 * Whether this connection uses application permissions.
	 *
	 * @return bool
	 */
	public function is_app_only() {
		return 'app' === (string) $this->option( 'auth_mode', 'delegated' );
	}

	/**
	 * The tenant segment for the login endpoints.
	 *
	 * @return string
	 */
	protected function tenant() {
		$tenant = trim( (string) $this->option( 'tenant', 'common' ) );

		return '' === $tenant ? 'common' : $tenant;
	}

	/**
	 * Scopes this mailer asks for.
	 *
	 * @return array
	 */
	protected function scopes() {
		$scopes = array( 'offline_access', 'openid', 'profile', 'email', 'https://graph.microsoft.com/Mail.Send', 'https://graph.microsoft.com/User.Read' );

		if ( DSMTP_Settings::get( 'read_bounces', 0 ) ) {
			// Needed to read delivery reports out of the mailbox.
			$scopes[] = 'https://graph.microsoft.com/Mail.Read';
		}

		return $scopes;
	}

	/**
	 * The scope used by the client credentials grant.
	 *
	 * @return string
	 */
	protected function app_scope() {
		return 'https://graph.microsoft.com/.default';
	}

	/**
	 * OAuth endpoints and credentials.
	 *
	 * @return array
	 */
	public function oauth_config() {
		return array(
			'authorize'      => 'https://login.microsoftonline.com/' . rawurlencode( $this->tenant() ) . '/oauth2/v2.0/authorize',
			'token'          => 'https://login.microsoftonline.com/' . rawurlencode( $this->tenant() ) . '/oauth2/v2.0/token',
			'client_id'      => (string) $this->option( 'client_id', '' ),
			'client_secret'  => (string) $this->option( 'client_secret', '' ),
			'scopes'         => $this->scopes(),
			'extra_auth'     => array(
				'response_mode' => 'query',
				'prompt'        => 'consent',
			),
			'app_only'       => $this->is_app_only(),
			'app_only_scope' => $this->app_scope(),
		);
	}

	/**
	 * Link an administrator can use to grant tenant wide consent.
	 *
	 * @return string
	 */
	public function admin_consent_url() {
		return add_query_arg(
			array(
				'client_id'    => rawurlencode( (string) $this->option( 'client_id', '' ) ),
				'redirect_uri' => rawurlencode( DSMTP_OAuth::redirect_uri( static::id() ) ),
			),
			'https://login.microsoftonline.com/' . rawurlencode( $this->tenant() ) . '/adminconsent'
		);
	}

	/**
	 * Look up the signed in mailbox address.
	 *
	 * @param string $access_token Access token.
	 * @return string
	 */
	public function fetch_account( $access_token ) {
		$response = wp_remote_get(
			self::GRAPH . '/me',
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$data    = json_decode( wp_remote_retrieve_body( $response ), true );
		$address = '';

		if ( is_array( $data ) ) {
			if ( ! empty( $data['mail'] ) ) {
				$address = (string) $data['mail'];
			} elseif ( ! empty( $data['userPrincipalName'] ) ) {
				$address = (string) $data['userPrincipalName'];
			}
		}

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

		if ( $this->is_app_only() ) {
			if ( 'common' === $this->tenant() ) {
				return new WP_Error( 'dsmtp_incomplete', __( 'Application permissions need your real directory (tenant) id, not common.', 'demfilz-smtp-mailer' ) );
			}

			if ( ! is_email( (string) $this->option( 'mailbox', '' ) ) ) {
				return new WP_Error( 'dsmtp_incomplete', __( 'Enter the mailbox this site should send from.', 'demfilz-smtp-mailer' ) );
			}

			return true;
		}

		if ( ! DSMTP_OAuth::is_connected( static::id() ) ) {
			return new WP_Error( 'dsmtp_disconnected', __( 'Press Connect to sign in to the Microsoft mailbox.', 'demfilz-smtp-mailer' ) );
		}

		return true;
	}

	/**
	 * The address Microsoft will accept as the sender.
	 *
	 * @return string
	 */
	public function forced_from_email() {
		$mailbox = (string) $this->option( 'mailbox', '' );

		return is_email( $mailbox ) ? $mailbox : '';
	}

	/**
	 * The mailbox path used for the send request.
	 *
	 * @return string
	 */
	protected function send_path() {
		if ( $this->is_app_only() ) {
			return '/users/' . rawurlencode( (string) $this->option( 'mailbox', '' ) ) . '/sendMail';
		}

		return '/me/sendMail';
	}

	/**
	 * Send the message through Graph.
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

		$url = self::GRAPH . $this->send_path();

		if ( $this->option( 'save_sent', 0 ) ) {
			$args = $this->mime_request( $mail, $token );
		} else {
			$args = $this->json_request( $mail, $token );
		}

		if ( is_wp_error( $args ) ) {
			return $args;
		}

		$response = $this->request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$this->last_message_id = trim( (string) $mail->getLastMessageID() );

		return true;
	}

	/**
	 * Request arguments that post the raw MIME message.
	 *
	 * @param DSMTP_Mail_Catcher $mail  Prepared mailer.
	 * @param string             $token Access token.
	 * @return array|WP_Error
	 */
	protected function mime_request( $mail, $token ) {
		$mime = $this->mime( $mail );

		if ( strlen( $mime ) > 4 * MB_IN_BYTES ) {
			return new WP_Error( 'dsmtp_too_large', __( 'Microsoft accepts messages up to 4 MB through this route. Send the file as a link instead.', 'demfilz-smtp-mailer' ) );
		}

		return array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'text/plain',
			),
			'body'    => $mime,
		);
	}

	/**
	 * Request arguments that post a Graph message object.
	 *
	 * @param DSMTP_Mail_Catcher $mail  Prepared mailer.
	 * @param string             $token Access token.
	 * @return array|WP_Error
	 */
	protected function json_request( $mail, $token ) {
		$parts       = $this->body_parts( $mail );
		$attachments = $this->attachments( $mail );

		if ( is_wp_error( $attachments ) ) {
			return $attachments;
		}

		$message = array(
			'subject' => $mail->Subject,
			'body'    => array(
				'contentType' => '' !== $parts['html'] ? 'HTML' : 'Text',
				'content'     => '' !== $parts['html'] ? $parts['html'] : $parts['text'],
			),
		);

		foreach ( array(
			'toRecipients'  => $mail->getToAddresses(),
			'ccRecipients'  => $mail->getCcAddresses(),
			'bccRecipients' => $mail->getBccAddresses(),
			'replyTo'       => $mail->getReplyToAddresses(),
		) as $key => $list ) {
			$recipients = array();

			foreach ( $this->addresses( $list ) as $address ) {
				$recipients[] = array(
					'emailAddress' => array(
						'address' => $address['email'],
						'name'    => $address['name'],
					),
				);
			}

			if ( $recipients ) {
				$message[ $key ] = $recipients;
			}
		}

		if ( is_email( $mail->From ) ) {
			$message['from'] = array(
				'emailAddress' => array(
					'address' => $mail->From,
					'name'    => $mail->FromName,
				),
			);
		}

		if ( $attachments ) {
			$message['attachments'] = array();

			foreach ( $attachments as $attachment ) {
				$item = array(
					'@odata.type'  => '#microsoft.graph.fileAttachment',
					'name'         => $attachment['name'],
					'contentType'  => $attachment['type'],
					'contentBytes' => $attachment['content'],
				);

				if ( 'inline' === $attachment['disposition'] && '' !== $attachment['cid'] ) {
					$item['isInline']  = true;
					$item['contentId'] = $attachment['cid'];
				}

				$message['attachments'][] = $item;
			}
		}

		$headers = array();

		foreach ( $this->custom_headers( $mail ) as $name => $value ) {
			// Graph only accepts custom headers in the x- space, and at most five.
			if ( 0 !== stripos( $name, 'x-' ) || count( $headers ) >= 5 ) {
				continue;
			}

			$headers[] = array(
				'name'  => $name,
				'value' => $value,
			);
		}

		if ( $headers ) {
			$message['internetMessageHeaders'] = $headers;
		}

		return array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode(
				array(
					'message'         => $message,
					'saveToSentItems' => false,
				)
			),
		);
	}
}
