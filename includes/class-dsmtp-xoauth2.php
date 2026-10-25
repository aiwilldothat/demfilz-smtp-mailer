<?php
/**
 * XOAUTH2 token provider for SMTP servers that use modern authentication.
 *
 * Loaded only once PHPMailer itself is in memory, because it implements one of
 * PHPMailer's own interfaces.
 *
 * @package Demfilz_SMTP_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hands SMTP a bearer token instead of a password.
 */
class DSMTP_XOAuth2 implements PHPMailer\PHPMailer\OAuthTokenProvider {

	/**
	 * Provider the token belongs to.
	 *
	 * @var string
	 */
	private $provider_id;

	/**
	 * Mailbox address the token authenticates.
	 *
	 * @var string
	 */
	private $user;

	/**
	 * Constructor.
	 *
	 * @param string $provider_id Provider identifier.
	 * @param string $user        Mailbox address.
	 */
	public function __construct( $provider_id, $user ) {
		$this->provider_id = $provider_id;
		$this->user        = $user;
	}

	/**
	 * The base64 encoded XOAUTH2 authentication string.
	 *
	 * @return string
	 * @throws PHPMailer\PHPMailer\Exception When no access token can be obtained.
	 */
	public function getOauth64() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Required by the PHPMailer interface.
		$token = DSMTP_OAuth::access_token( $this->provider_id );

		if ( is_wp_error( $token ) ) {
			throw new PHPMailer\PHPMailer\Exception( esc_html( $token->get_error_message() ) );
		}

		return base64_encode( 'user=' . $this->user . "\1auth=Bearer " . $token . "\1\1" ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required SASL encoding.
	}
}
