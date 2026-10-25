<?php
/**
 * Microsoft 365 over SMTP with modern authentication.
 *
 * @package Demfilz_SMTP_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Talks SMTP to Exchange Online, authenticating with OAuth rather than a password.
 *
 * Use this when a mailbox has SMTP AUTH enabled and you would rather keep the
 * SMTP path, for example because an application access policy already covers it.
 * Where SMTP AUTH is switched off tenant wide, use the Graph API mailer instead.
 */
class DSMTP_Provider_Microsoft_SMTP extends DSMTP_Provider {

	/**
	 * Provider identifier.
	 *
	 * @return string
	 */
	public static function id() {
		return 'microsoft-smtp';
	}

	/**
	 * Human readable name.
	 *
	 * @return string
	 */
	public static function label() {
		return __( 'Microsoft 365 SMTP (modern authentication)', 'demfilz-smtp-mailer' );
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
		return self::TYPE_SMTP;
	}

	/**
	 * One line summary.
	 *
	 * @return string
	 */
	public static function description() {
		return __( 'SMTP to smtp.office365.com, signed in with OAuth instead of a password. Needs SMTP AUTH enabled on the mailbox.', 'demfilz-smtp-mailer' );
	}

	/**
	 * Setup notes.
	 *
	 * @return string
	 */
	public static function setup_notes() {
		return __( 'Register an application in the Microsoft Entra admin centre, add the redirect URI below as a Web platform, grant the delegated permission SMTP.Send, create a client secret, then save and press Connect. SMTP AUTH must be enabled for the mailbox in the Microsoft 365 admin centre.', 'demfilz-smtp-mailer' );
	}

	/**
	 * Connection fields.
	 *
	 * @return array
	 */
	public static function fields() {
		return array(
			'tenant'        => array(
				'label'   => __( 'Directory (tenant) id', 'demfilz-smtp-mailer' ),
				'type'    => 'text',
				'default' => 'common',
			),
			'client_id'     => array(
				'label'    => __( 'Application (client) id', 'demfilz-smtp-mailer' ),
				'type'     => 'text',
				'required' => true,
			),
			'client_secret' => array(
				'label'    => __( 'Client secret value', 'demfilz-smtp-mailer' ),
				'type'     => 'password',
				'secret'   => true,
				'required' => true,
			),
			'mailbox'       => array(
				'label'       => __( 'Mailbox address', 'demfilz-smtp-mailer' ),
				'type'        => 'email',
				'required'    => true,
				'description' => __( 'The mailbox the site signs in as, for example notifications@example.com.', 'demfilz-smtp-mailer' ),
			),
			'host'          => array(
				'label'   => __( 'SMTP host', 'demfilz-smtp-mailer' ),
				'type'    => 'text',
				'default' => 'smtp.office365.com',
			),
			'port'          => array(
				'label'   => __( 'SMTP port', 'demfilz-smtp-mailer' ),
				'type'    => 'number',
				'default' => 587,
			),
		);
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
	 * OAuth endpoints and credentials.
	 *
	 * @return array
	 */
	public function oauth_config() {
		return array(
			'authorize'     => 'https://login.microsoftonline.com/' . rawurlencode( $this->tenant() ) . '/oauth2/v2.0/authorize',
			'token'         => 'https://login.microsoftonline.com/' . rawurlencode( $this->tenant() ) . '/oauth2/v2.0/token',
			'client_id'     => (string) $this->option( 'client_id', '' ),
			'client_secret' => (string) $this->option( 'client_secret', '' ),
			'scopes'        => array( 'offline_access', 'https://outlook.office.com/SMTP.Send' ),
			'extra_auth'    => array(
				'response_mode' => 'query',
				'prompt'        => 'consent',
				'login_hint'    => (string) $this->option( 'mailbox', '' ),
			),
			'app_only'      => false,
		);
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
			return new WP_Error( 'dsmtp_disconnected', __( 'Press Connect to sign in to the Microsoft mailbox.', 'demfilz-smtp-mailer' ) );
		}

		return true;
	}

	/**
	 * The address Exchange Online will accept as the sender.
	 *
	 * @return string
	 */
	public function forced_from_email() {
		$mailbox = (string) $this->option( 'mailbox', '' );

		return is_email( $mailbox ) ? $mailbox : '';
	}

	/**
	 * Configure PHPMailer for SMTP with an OAuth token.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer instance.
	 * @return void
	 */
	public function configure( $phpmailer ) {
		require_once ABSPATH . WPINC . '/PHPMailer/OAuthTokenProvider.php';
		require_once DSMTP_DIR . 'includes/class-dsmtp-xoauth2.php';

		$phpmailer->isSMTP();
		$phpmailer->Host        = (string) $this->option( 'host', 'smtp.office365.com' );
		$phpmailer->Port        = (int) $this->option( 'port', 587 );
		$phpmailer->SMTPSecure  = 'tls';
		$phpmailer->SMTPAuth    = true;
		$phpmailer->SMTPAutoTLS = true;
		$phpmailer->AuthType    = 'XOAUTH2';
		$phpmailer->Username    = (string) $this->option( 'mailbox', '' );

		$phpmailer->setOAuth( new DSMTP_XOAuth2( static::id(), (string) $this->option( 'mailbox', '' ) ) );
	}
}
