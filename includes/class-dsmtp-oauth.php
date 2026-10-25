<?php
/**
 * OAuth 2.0 handling shared by the Microsoft and Google mailers.
 *
 * @package Demfilz_SMTP_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authorisation codes, refresh tokens and access tokens.
 *
 * Tokens live in their own option, encrypted, so they are never printed on the
 * settings screen and never travel with a settings export.
 */
class DSMTP_OAuth {

	const OPTION  = 'dsmtp_tokens';
	const REST_NS = 'dsmtp/v1';

	/**
	 * Hook the redirect endpoint.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register the endpoint the mail provider redirects back to.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NS,
			'/auth/(?P<provider>[a-z0-9\-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_callback' ),

				/*
				 * The mail provider sends the browser here as a plain redirect, so no
				 * REST nonce can be present. The single use state value created for a
				 * signed in administrator is what authorises the exchange, and it is
				 * checked first thing inside the callback.
				 */
				'permission_callback' => '__return_true',
				'args'                => array(
					'provider' => array(
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * The redirect URI a site owner registers with the mail provider.
	 *
	 * @param string $provider_id Provider identifier.
	 * @return string
	 */
	public static function redirect_uri( $provider_id ) {
		/**
		 * Filters the OAuth redirect URI.
		 *
		 * @since 1.0.0
		 *
		 * @param string $uri         Redirect URI.
		 * @param string $provider_id Provider identifier.
		 */
		return apply_filters( 'dsmtp_oauth_redirect_uri', rest_url( self::REST_NS . '/auth/' . $provider_id ), $provider_id );
	}

	/**
	 * Build the URL that starts the consent flow.
	 *
	 * @param string $provider_id Provider identifier.
	 * @return string|WP_Error
	 */
	public static function authorize_url( $provider_id ) {
		$provider = DSMTP_Providers::get( $provider_id );

		if ( ! $provider || ! method_exists( $provider, 'oauth_config' ) ) {
			return new WP_Error( 'dsmtp_oauth', __( 'That mailer does not use OAuth.', 'demfilz-smtp-mailer' ) );
		}

		$config = $provider->oauth_config();

		if ( empty( $config['client_id'] ) || empty( $config['authorize'] ) ) {
			return new WP_Error( 'dsmtp_oauth', __( 'Add the application id and secret first, then save, then connect.', 'demfilz-smtp-mailer' ) );
		}

		$state = wp_generate_password( 32, false );

		set_transient(
			'dsmtp_oauth_' . hash( 'sha256', $state ),
			array(
				'provider' => $provider_id,
				'user'     => get_current_user_id(),
			),
			15 * MINUTE_IN_SECONDS
		);

		$args = array_merge(
			array(
				'client_id'     => $config['client_id'],
				'response_type' => 'code',
				'redirect_uri'  => self::redirect_uri( $provider_id ),
				'scope'         => implode( ' ', (array) $config['scopes'] ),
				'state'         => $state,
			),
			isset( $config['extra_auth'] ) ? (array) $config['extra_auth'] : array()
		);

		return add_query_arg( array_map( 'rawurlencode', $args ), $config['authorize'] );
	}

	/**
	 * Handle the redirect back from the mail provider.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_callback( $request ) {
		$provider_id = sanitize_key( $request->get_param( 'provider' ) );
		$state       = (string) $request->get_param( 'state' );
		$key         = 'dsmtp_oauth_' . hash( 'sha256', $state );
		$stored      = '' === $state ? false : get_transient( $key );

		if ( ! is_array( $stored ) || empty( $stored['provider'] ) || $stored['provider'] !== $provider_id ) {
			return self::finish( $provider_id, 'state' );
		}

		delete_transient( $key );

		if ( ! user_can( (int) $stored['user'], 'manage_options' ) ) {
			return self::finish( $provider_id, 'permission' );
		}

		$error = (string) $request->get_param( 'error' );

		if ( '' !== $error ) {
			$description = (string) $request->get_param( 'error_description' );

			self::remember_error( $provider_id, '' !== $description ? $description : $error );

			return self::finish( $provider_id, 'denied' );
		}

		$code = (string) $request->get_param( 'code' );

		if ( '' === $code ) {
			return self::finish( $provider_id, 'nocode' );
		}

		$result = self::exchange_code( $provider_id, $code );

		if ( is_wp_error( $result ) ) {
			self::remember_error( $provider_id, $result->get_error_message() );

			return self::finish( $provider_id, 'failed' );
		}

		return self::finish( $provider_id, 'connected' );
	}

	/**
	 * Send the browser back to the settings screen.
	 *
	 * @param string $provider_id Provider identifier.
	 * @param string $result      Result slug.
	 * @return WP_REST_Response
	 */
	private static function finish( $provider_id, $result ) {
		$url = add_query_arg(
			array(
				'page'         => 'dsmtp-settings',
				'dsmtp_oauth'  => $result,
				'dsmtp_mailer' => $provider_id,
			),
			admin_url( 'admin.php' )
		);

		return new WP_REST_Response( null, 302, array( 'Location' => $url ) );
	}

	/**
	 * Store the last OAuth error so the settings screen can show it.
	 *
	 * @param string $provider_id Provider identifier.
	 * @param string $message     Error message.
	 * @return void
	 */
	private static function remember_error( $provider_id, $message ) {
		set_transient( 'dsmtp_oauth_error_' . $provider_id, wp_strip_all_tags( $message ), 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * The last OAuth error for a provider, cleared as it is read.
	 *
	 * @param string $provider_id Provider identifier.
	 * @return string
	 */
	public static function last_error( $provider_id ) {
		$message = get_transient( 'dsmtp_oauth_error_' . $provider_id );

		if ( false === $message ) {
			return '';
		}

		delete_transient( 'dsmtp_oauth_error_' . $provider_id );

		return (string) $message;
	}

	/**
	 * Swap an authorisation code for tokens.
	 *
	 * @param string $provider_id Provider identifier.
	 * @param string $code        Authorisation code.
	 * @return true|WP_Error
	 */
	public static function exchange_code( $provider_id, $code ) {
		$provider = DSMTP_Providers::get( $provider_id );

		if ( ! $provider || ! method_exists( $provider, 'oauth_config' ) ) {
			return new WP_Error( 'dsmtp_oauth', __( 'That mailer does not use OAuth.', 'demfilz-smtp-mailer' ) );
		}

		$config = $provider->oauth_config();

		$body = array(
			'client_id'     => $config['client_id'],
			'client_secret' => $config['client_secret'],
			'code'          => $code,
			'redirect_uri'  => self::redirect_uri( $provider_id ),
			'grant_type'    => 'authorization_code',
		);

		if ( ! empty( $config['scopes'] ) ) {
			$body['scope'] = implode( ' ', (array) $config['scopes'] );
		}

		$tokens = self::token_request( $config['token'], $body );

		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}

		if ( empty( $tokens['refresh_token'] ) ) {
			return new WP_Error( 'dsmtp_oauth', __( 'The mail provider did not return a refresh token. Check that offline access was requested and try again.', 'demfilz-smtp-mailer' ) );
		}

		$record = array(
			'access_token'  => $tokens['access_token'],
			'refresh_token' => $tokens['refresh_token'],
			'expires'       => time() + (int) ( isset( $tokens['expires_in'] ) ? $tokens['expires_in'] : 3600 ),
			'scope'         => isset( $tokens['scope'] ) ? $tokens['scope'] : '',
			'connected'     => time(),
			'account'       => '',
		);

		if ( method_exists( $provider, 'fetch_account' ) ) {
			$account = $provider->fetch_account( $tokens['access_token'] );

			if ( is_string( $account ) ) {
				$record['account'] = $account;
			}
		}

		self::store( $provider_id, $record );

		return true;
	}

	/**
	 * A usable access token, refreshed when needed.
	 *
	 * @param string $provider_id Provider identifier.
	 * @return string|WP_Error
	 */
	public static function access_token( $provider_id ) {
		$provider = DSMTP_Providers::get( $provider_id );

		if ( ! $provider || ! method_exists( $provider, 'oauth_config' ) ) {
			return new WP_Error( 'dsmtp_oauth', __( 'That mailer does not use OAuth.', 'demfilz-smtp-mailer' ) );
		}

		$config = $provider->oauth_config();
		$record = self::tokens( $provider_id );

		if ( ! empty( $record['access_token'] ) && ! empty( $record['expires'] ) && $record['expires'] > ( time() + 120 ) ) {
			return $record['access_token'];
		}

		if ( ! empty( $config['app_only'] ) ) {
			return self::client_credentials( $provider_id, $config );
		}

		if ( empty( $record['refresh_token'] ) ) {
			return new WP_Error(
				'dsmtp_oauth',
				sprintf(
					/* translators: %s: mailer name. */
					__( '%s is not connected yet. Open the plugin settings and press Connect.', 'demfilz-smtp-mailer' ),
					$provider::label()
				)
			);
		}

		$body = array(
			'client_id'     => $config['client_id'],
			'client_secret' => $config['client_secret'],
			'refresh_token' => $record['refresh_token'],
			'grant_type'    => 'refresh_token',
		);

		if ( ! empty( $config['scopes'] ) ) {
			$body['scope'] = implode( ' ', (array) $config['scopes'] );
		}

		$tokens = self::token_request( $config['token'], $body );

		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}

		$record['access_token'] = $tokens['access_token'];
		$record['expires']      = time() + (int) ( isset( $tokens['expires_in'] ) ? $tokens['expires_in'] : 3600 );

		if ( ! empty( $tokens['refresh_token'] ) ) {
			$record['refresh_token'] = $tokens['refresh_token'];
		}

		self::store( $provider_id, $record );

		return $record['access_token'];
	}

	/**
	 * Fetch an application only token (client credentials grant).
	 *
	 * @param string $provider_id Provider identifier.
	 * @param array  $config      OAuth configuration.
	 * @return string|WP_Error
	 */
	private static function client_credentials( $provider_id, $config ) {
		$tokens = self::token_request(
			$config['token'],
			array(
				'client_id'     => $config['client_id'],
				'client_secret' => $config['client_secret'],
				'scope'         => isset( $config['app_only_scope'] ) ? $config['app_only_scope'] : '',
				'grant_type'    => 'client_credentials',
			)
		);

		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}

		self::store(
			$provider_id,
			array(
				'access_token' => $tokens['access_token'],
				'expires'      => time() + (int) ( isset( $tokens['expires_in'] ) ? $tokens['expires_in'] : 3600 ),
				'connected'    => time(),
				'account'      => '',
			)
		);

		return $tokens['access_token'];
	}

	/**
	 * POST to a token endpoint and decode the answer.
	 *
	 * @param string $url  Token endpoint.
	 * @param array  $body Form fields.
	 * @return array|WP_Error
	 */
	private static function token_request( $url, $body ) {
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'dsmtp_oauth', $response->get_error_message() );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( ! is_array( $data ) ) {
			$data = array();
		}

		if ( $code < 200 || $code > 299 || empty( $data['access_token'] ) ) {
			$message = '';

			if ( ! empty( $data['error_description'] ) ) {
				$message = (string) $data['error_description'];
			} elseif ( ! empty( $data['error'] ) ) {
				$message = is_array( $data['error'] ) ? wp_json_encode( $data['error'] ) : (string) $data['error'];
			}

			if ( '' === $message ) {
				$message = sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The token request failed with HTTP %d.', 'demfilz-smtp-mailer' ),
					$code
				);
			}

			return new WP_Error( 'dsmtp_oauth', wp_strip_all_tags( $message ) );
		}

		return $data;
	}

	/**
	 * All stored tokens for a provider, decrypted.
	 *
	 * @param string $provider_id Provider identifier.
	 * @return array
	 */
	public static function tokens( $provider_id ) {
		$all    = DSMTP_Settings::is_network_mode() ? get_site_option( self::OPTION, array() ) : get_option( self::OPTION, array() );
		$record = isset( $all[ $provider_id ] ) && is_array( $all[ $provider_id ] ) ? $all[ $provider_id ] : array();

		foreach ( array( 'access_token', 'refresh_token' ) as $key ) {
			if ( isset( $record[ $key ] ) ) {
				$record[ $key ] = DSMTP_Crypto::decrypt( (string) $record[ $key ] );
			}
		}

		return $record;
	}

	/**
	 * Save tokens for a provider.
	 *
	 * @param string $provider_id Provider identifier.
	 * @param array  $record      Token record.
	 * @return void
	 */
	private static function store( $provider_id, array $record ) {
		$all = DSMTP_Settings::is_network_mode() ? get_site_option( self::OPTION, array() ) : get_option( self::OPTION, array() );

		if ( ! is_array( $all ) ) {
			$all = array();
		}

		foreach ( array( 'access_token', 'refresh_token' ) as $key ) {
			if ( ! empty( $record[ $key ] ) ) {
				$record[ $key ] = DSMTP_Crypto::encrypt( (string) $record[ $key ] );
			}
		}

		$all[ $provider_id ] = $record;

		if ( DSMTP_Settings::is_network_mode() ) {
			update_site_option( self::OPTION, $all );
		} else {
			update_option( self::OPTION, $all, false );
		}
	}

	/**
	 * Whether a provider currently holds tokens.
	 *
	 * @param string $provider_id Provider identifier.
	 * @return bool
	 */
	public static function is_connected( $provider_id ) {
		$record = self::tokens( $provider_id );

		return ! empty( $record['refresh_token'] ) || ! empty( $record['access_token'] );
	}

	/**
	 * Forget a provider's tokens.
	 *
	 * @param string $provider_id Provider identifier.
	 * @return void
	 */
	public static function disconnect( $provider_id ) {
		$all = DSMTP_Settings::is_network_mode() ? get_site_option( self::OPTION, array() ) : get_option( self::OPTION, array() );

		if ( ! is_array( $all ) ) {
			return;
		}

		unset( $all[ $provider_id ] );

		if ( DSMTP_Settings::is_network_mode() ) {
			update_site_option( self::OPTION, $all );
		} else {
			update_option( self::OPTION, $all, false );
		}
	}
}
