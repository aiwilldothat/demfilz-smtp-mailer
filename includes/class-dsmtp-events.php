<?php
/**
 * Delivery events: what the mail provider says happened after the send.
 *
 * @package Demfilz_SMTP_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Receives delivery, bounce and complaint events from the sending services
 * and applies them to the log and the suppression list.
 *
 * Every event, whether it arrived on a webhook or was read out of a mailbox,
 * is normalised to the same small shape before it is applied, so the log and
 * the suppression rules only have one thing to understand.
 */
class DSMTP_Events {

	const REST_NS = 'dsmtp/v1';

	const KIND_DELIVERED = 'delivered';
	const KIND_BOUNCE    = 'bounce';
	const KIND_SOFT      = 'soft';
	const KIND_COMPLAINT = 'complaint';

	/**
	 * Providers that can post events to the site.
	 *
	 * @var array
	 */
	private static $webhook_providers = array( 'sendgrid', 'mailgun', 'brevo', 'postmark', 'resend' );

	/**
	 * Hook the endpoint.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Providers that support event webhooks.
	 *
	 * @return array
	 */
	public static function webhook_providers() {
		return self::$webhook_providers;
	}

	/**
	 * Register the webhook route.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NS,
			'/events/(?P<provider>[a-z0-9\-]+)/(?P<token>[A-Za-z0-9]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_webhook' ),

				/*
				 * Mail providers post here from their own servers, with no
				 * WordPress session and no REST nonce. The long random token in
				 * the URL is the credential, and it is compared in constant time
				 * before anything else happens.
				 */
				'permission_callback' => '__return_true',
				'args'                => array(
					'provider' => array( 'sanitize_callback' => 'sanitize_key' ),
					'token'    => array( 'sanitize_callback' => 'sanitize_key' ),
				),
			)
		);
	}

	/**
	 * The secret that authenticates incoming webhooks, created on first use.
	 *
	 * @return string
	 */
	public static function token() {
		$token = (string) DSMTP_Settings::get( 'feedback_token', '' );

		if ( '' === $token ) {
			$token = self::regenerate_token();
		}

		return $token;
	}

	/**
	 * Replace the webhook secret. Existing webhook URLs stop working.
	 *
	 * @return string The new token.
	 */
	public static function regenerate_token() {
		$token = wp_generate_password( 40, false );

		DSMTP_Settings::update( array( 'feedback_token' => $token ) );

		return $token;
	}

	/**
	 * The URL a provider should post events to.
	 *
	 * @param string $provider_id Provider identifier.
	 * @return string
	 */
	public static function webhook_url( $provider_id ) {
		return rest_url( self::REST_NS . '/events/' . $provider_id . '/' . self::token() );
	}

	/**
	 * Handle a posted event.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_webhook( $request ) {
		$provider_id = sanitize_key( $request->get_param( 'provider' ) );
		$token       = (string) $request->get_param( 'token' );

		if ( ! hash_equals( self::token(), $token ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid token' ), 403 );
		}

		if ( ! in_array( $provider_id, self::$webhook_providers, true ) ) {
			return new WP_REST_Response( array( 'error' => 'unknown provider' ), 404 );
		}

		$body = json_decode( (string) $request->get_body(), true );

		if ( ! is_array( $body ) ) {
			$body = $request->get_params();
		}

		if ( 'mailgun' === $provider_id && ! self::verify_mailgun( $body ) ) {
			return new WP_REST_Response( array( 'error' => 'bad signature' ), 403 );
		}

		$events  = self::normalise( $provider_id, $body );
		$applied = 0;

		foreach ( $events as $event ) {
			if ( self::apply( $event ) ) {
				++$applied;
			}
		}

		return new WP_REST_Response(
			array(
				'received' => count( $events ),
				'applied'  => $applied,
			),
			200
		);
	}

	/**
	 * Check a Mailgun signature when a signing key has been entered.
	 *
	 * @param array $body Decoded payload.
	 * @return bool
	 */
	private static function verify_mailgun( $body ) {
		$key = (string) DSMTP_Settings::provider_config( 'mailgun' )['webhook_key'];

		if ( '' === $key ) {
			// Nothing to verify against; the URL token is the only credential.
			return true;
		}

		$signature = isset( $body['signature'] ) && is_array( $body['signature'] ) ? $body['signature'] : array();

		if ( empty( $signature['timestamp'] ) || empty( $signature['token'] ) || empty( $signature['signature'] ) ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $signature['timestamp'] . $signature['token'], $key );

		return hash_equals( $expected, (string) $signature['signature'] );
	}

	// Normalising each provider's payload.

	/**
	 * Turn a provider payload into a list of normalised events.
	 *
	 * @param string $provider_id Provider identifier.
	 * @param array  $body        Decoded payload.
	 * @return array Each with email, kind, detail, message_id and source keys.
	 */
	public static function normalise( $provider_id, $body ) {
		switch ( $provider_id ) {
			case 'sendgrid':
				return self::from_sendgrid( $body );
			case 'mailgun':
				return self::from_mailgun( $body );
			case 'brevo':
				return self::from_brevo( $body );
			case 'postmark':
				return self::from_postmark( $body );
			case 'resend':
				return self::from_resend( $body );
		}

		return array();
	}

	/**
	 * Build one normalised event.
	 *
	 * @param string $email      Recipient.
	 * @param string $kind       One of the KIND_ constants, or empty to skip.
	 * @param string $detail     Provider explanation.
	 * @param string $message_id Provider message id.
	 * @param string $source     Provider identifier.
	 * @return array|null
	 */
	public static function event( $email, $kind, $detail, $message_id, $source ) {
		$email = DSMTP_Suppression::normalise( $email );

		if ( '' === $email || '' === $kind ) {
			return null;
		}

		return array(
			'email'      => $email,
			'kind'       => $kind,
			'detail'     => trim( wp_strip_all_tags( (string) $detail ) ),
			'message_id' => trim( (string) $message_id, '<> ' ),
			'source'     => $source,
		);
	}

	/**
	 * SendGrid posts an array of event objects.
	 *
	 * @param array $body Decoded payload.
	 * @return array
	 */
	private static function from_sendgrid( $body ) {
		$out = array();

		if ( isset( $body['event'] ) ) {
			$body = array( $body );
		}

		foreach ( $body as $item ) {
			if ( ! is_array( $item ) || empty( $item['event'] ) ) {
				continue;
			}

			$kind = '';

			switch ( $item['event'] ) {
				case 'delivered':
					$kind = self::KIND_DELIVERED;
					break;
				case 'bounce':
					$kind = isset( $item['type'] ) && 'blocked' === $item['type'] ? self::KIND_SOFT : self::KIND_BOUNCE;
					break;
				case 'dropped':
					$kind = self::KIND_BOUNCE;
					break;
				case 'deferred':
					$kind = self::KIND_SOFT;
					break;
				case 'spamreport':
					$kind = self::KIND_COMPLAINT;
					break;
			}

			$event = self::event(
				isset( $item['email'] ) ? $item['email'] : '',
				$kind,
				isset( $item['reason'] ) ? $item['reason'] : ( isset( $item['response'] ) ? $item['response'] : '' ),
				isset( $item['sg_message_id'] ) ? $item['sg_message_id'] : '',
				'sendgrid'
			);

			if ( $event ) {
				$out[] = $event;
			}
		}

		return $out;
	}

	/**
	 * Mailgun posts one event wrapped in event-data.
	 *
	 * @param array $body Decoded payload.
	 * @return array
	 */
	private static function from_mailgun( $body ) {
		$data = isset( $body['event-data'] ) && is_array( $body['event-data'] ) ? $body['event-data'] : $body;

		if ( empty( $data['event'] ) ) {
			return array();
		}

		$kind = '';

		switch ( $data['event'] ) {
			case 'delivered':
				$kind = self::KIND_DELIVERED;
				break;
			case 'failed':
				$kind = isset( $data['severity'] ) && 'temporary' === $data['severity'] ? self::KIND_SOFT : self::KIND_BOUNCE;
				break;
			case 'complained':
				$kind = self::KIND_COMPLAINT;
				break;
		}

		$detail = '';

		if ( ! empty( $data['delivery-status']['message'] ) ) {
			$detail = $data['delivery-status']['message'];
		} elseif ( ! empty( $data['delivery-status']['description'] ) ) {
			$detail = $data['delivery-status']['description'];
		} elseif ( ! empty( $data['reason'] ) ) {
			$detail = $data['reason'];
		}

		$event = self::event(
			isset( $data['recipient'] ) ? $data['recipient'] : '',
			$kind,
			$detail,
			isset( $data['message']['headers']['message-id'] ) ? $data['message']['headers']['message-id'] : '',
			'mailgun'
		);

		return $event ? array( $event ) : array();
	}

	/**
	 * Brevo posts one flat event object.
	 *
	 * @param array $body Decoded payload.
	 * @return array
	 */
	private static function from_brevo( $body ) {
		if ( empty( $body['event'] ) ) {
			return array();
		}

		$kind = '';

		switch ( $body['event'] ) {
			case 'delivered':
				$kind = self::KIND_DELIVERED;
				break;
			case 'hard_bounce':
			case 'invalid_email':
			case 'blocked':
				$kind = self::KIND_BOUNCE;
				break;
			case 'soft_bounce':
			case 'deferred':
				$kind = self::KIND_SOFT;
				break;
			case 'spam':
			case 'complaint':
				$kind = self::KIND_COMPLAINT;
				break;
		}

		$event = self::event(
			isset( $body['email'] ) ? $body['email'] : '',
			$kind,
			isset( $body['reason'] ) ? $body['reason'] : '',
			isset( $body['message-id'] ) ? $body['message-id'] : '',
			'brevo'
		);

		return $event ? array( $event ) : array();
	}

	/**
	 * Postmark posts one record per call.
	 *
	 * @param array $body Decoded payload.
	 * @return array
	 */
	private static function from_postmark( $body ) {
		if ( empty( $body['RecordType'] ) ) {
			return array();
		}

		$kind   = '';
		$type   = isset( $body['Type'] ) ? (string) $body['Type'] : '';
		$detail = '';

		switch ( $body['RecordType'] ) {
			case 'Delivery':
				$kind = self::KIND_DELIVERED;
				break;
			case 'SpamComplaint':
				$kind = self::KIND_COMPLAINT;
				break;
			case 'Bounce':
				if ( in_array( $type, array( 'SpamComplaint', 'SpamNotification' ), true ) ) {
					$kind = self::KIND_COMPLAINT;
				} elseif ( in_array( $type, array( 'Transient', 'SoftBounce', 'DnsError', 'AutoResponder', 'ChallengeVerification', 'Unknown' ), true ) ) {
					$kind = self::KIND_SOFT;
				} else {
					$kind = self::KIND_BOUNCE;
				}
				break;
		}

		if ( ! empty( $body['Description'] ) ) {
			$detail = $body['Description'];
		} elseif ( ! empty( $body['Details'] ) ) {
			$detail = $body['Details'];
		}

		if ( '' !== $type && '' !== $detail ) {
			$detail = $type . ': ' . $detail;
		}

		$email = '';

		if ( ! empty( $body['Email'] ) ) {
			$email = $body['Email'];
		} elseif ( ! empty( $body['Recipient'] ) ) {
			$email = $body['Recipient'];
		}

		$event = self::event( $email, $kind, $detail, isset( $body['MessageID'] ) ? $body['MessageID'] : '', 'postmark' );

		return $event ? array( $event ) : array();
	}

	/**
	 * Resend posts a typed event with the recipients in a list.
	 *
	 * @param array $body Decoded payload.
	 * @return array
	 */
	private static function from_resend( $body ) {
		if ( empty( $body['type'] ) || empty( $body['data'] ) || ! is_array( $body['data'] ) ) {
			return array();
		}

		$data = $body['data'];
		$kind = '';

		switch ( $body['type'] ) {
			case 'email.delivered':
				$kind = self::KIND_DELIVERED;
				break;
			case 'email.bounced':
				$kind = isset( $data['bounce']['type'] ) && 'Transient' === $data['bounce']['type'] ? self::KIND_SOFT : self::KIND_BOUNCE;
				break;
			case 'email.complained':
				$kind = self::KIND_COMPLAINT;
				break;
		}

		$detail = isset( $data['bounce']['message'] ) ? $data['bounce']['message'] : '';
		$out    = array();

		foreach ( (array) ( isset( $data['to'] ) ? $data['to'] : array() ) as $recipient ) {
			$event = self::event( $recipient, $kind, $detail, isset( $data['email_id'] ) ? $data['email_id'] : '', 'resend' );

			if ( $event ) {
				$out[] = $event;
			}
		}

		return $out;
	}

	// Applying an event.

	/**
	 * Update the log and the suppression list for one event.
	 *
	 * @param array $event Normalised event.
	 * @return bool Whether anything was recorded.
	 */
	public static function apply( $event ) {
		if ( empty( $event['email'] ) || empty( $event['kind'] ) ) {
			return false;
		}

		$row = null;

		if ( ! empty( $event['message_id'] ) ) {
			$row = DSMTP_Log::find_by_message_id( $event['message_id'] );
		}

		if ( ! $row ) {
			$row = DSMTP_Log::find_recent_to( $event['email'], 14 );
		}

		$detail = '' !== $event['detail'] ? $event['detail'] : __( 'No detail supplied by the provider.', 'demfilz-smtp-mailer' );

		switch ( $event['kind'] ) {
			case self::KIND_DELIVERED:
				if ( $row && empty( $row['delivered_at'] ) ) {
					DSMTP_Log::update( (int) $row['id'], array( 'delivered_at' => current_time( 'mysql', true ) ) );
				}
				break;

			case self::KIND_SOFT:
				if ( $row ) {
					DSMTP_Log::update(
						(int) $row['id'],
						array(
							'error' => sprintf(
								/* translators: %s: provider explanation. */
								__( 'Temporary delivery problem reported: %s', 'demfilz-smtp-mailer' ),
								$detail
							),
						)
					);
				}
				break;

			case self::KIND_BOUNCE:
			case self::KIND_COMPLAINT:
				$is_complaint = self::KIND_COMPLAINT === $event['kind'];
				$message      = $is_complaint
					? sprintf(
						/* translators: %s: provider explanation. */
						__( 'Marked as spam by the recipient: %s', 'demfilz-smtp-mailer' ),
						$detail
					)
					: sprintf(
						/* translators: %s: provider explanation. */
						__( 'Bounced: %s', 'demfilz-smtp-mailer' ),
						$detail
					);

				if ( $row ) {
					DSMTP_Log::update(
						(int) $row['id'],
						array(
							'status'   => DSMTP_Log::STATUS_BOUNCED,
							'error'    => $message,
							'retry_at' => null,
						)
					);
				}

				$suppress = $is_complaint ? DSMTP_Settings::get( 'suppress_on_complaint', 1 ) : DSMTP_Settings::get( 'suppress_on_bounce', 1 );

				if ( $suppress ) {
					DSMTP_Suppression::add(
						$event['email'],
						$is_complaint ? DSMTP_Suppression::REASON_COMPLAINT : DSMTP_Suppression::REASON_BOUNCE,
						$detail,
						$event['source']
					);
				}

				/**
				 * Fires when a provider reports a bounce or a spam complaint.
				 *
				 * @since 1.1.0
				 *
				 * @param array      $event Normalised event.
				 * @param array|null $row   Matching log row, if one was found.
				 */
				do_action( 'dsmtp_bounced', $event, $row );
				break;

			default:
				return false;
		}

		/**
		 * Fires for every delivery event the plugin applies.
		 *
		 * @since 1.1.0
		 *
		 * @param array      $event Normalised event.
		 * @param array|null $row   Matching log row, if one was found.
		 */
		do_action( 'dsmtp_delivery_event', $event, $row );

		return true;
	}
}
