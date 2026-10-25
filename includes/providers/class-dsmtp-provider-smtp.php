<?php
/**
 * Generic SMTP transport.
 *
 * @package Demfilz_SMTP_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends through any SMTP server with a user name and password.
 */
class DSMTP_Provider_SMTP extends DSMTP_Provider {

	/**
	 * Provider identifier.
	 *
	 * @return string
	 */
	public static function id() {
		return 'smtp';
	}

	/**
	 * Human readable name.
	 *
	 * @return string
	 */
	public static function label() {
		return __( 'Other SMTP', 'demfilz-smtp-mailer' );
	}

	/**
	 * Group heading.
	 *
	 * @return string
	 */
	public static function group() {
		return __( 'Built in', 'demfilz-smtp-mailer' );
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
		return __( 'Any mail server that speaks SMTP: your host, your mailbox provider, or a sending service that gives you SMTP credentials.', 'demfilz-smtp-mailer' );
	}

	/**
	 * Setup notes.
	 *
	 * @return string
	 */
	public static function setup_notes() {
		return __( 'Use the host, port and credentials your mail provider gave you. Port 587 with TLS suits almost every server. Port 465 needs SSL. If your provider has switched off password sign in, use one of the dedicated mailers instead.', 'demfilz-smtp-mailer' );
	}

	/**
	 * Connection fields.
	 *
	 * @return array
	 */
	public static function fields() {
		return array(
			'host'       => array(
				'label'       => __( 'SMTP host', 'demfilz-smtp-mailer' ),
				'type'        => 'text',
				'required'    => true,
				'placeholder' => 'mail.example.com',
			),
			'encryption' => array(
				'label'   => __( 'Encryption', 'demfilz-smtp-mailer' ),
				'type'    => 'select',
				'default' => 'tls',
				'options' => array(
					'none' => __( 'None', 'demfilz-smtp-mailer' ),
					'tls'  => __( 'STARTTLS (usually port 587)', 'demfilz-smtp-mailer' ),
					'ssl'  => __( 'SSL (usually port 465)', 'demfilz-smtp-mailer' ),
				),
			),
			'port'       => array(
				'label'    => __( 'SMTP port', 'demfilz-smtp-mailer' ),
				'type'     => 'number',
				'default'  => 587,
				'required' => true,
			),
			'auth'       => array(
				'label'       => __( 'Use authentication', 'demfilz-smtp-mailer' ),
				'type'        => 'checkbox',
				'default'     => 1,
				'description' => __( 'Almost always on. Turn it off only for a local relay that accepts mail without a login.', 'demfilz-smtp-mailer' ),
			),
			'username'   => array(
				'label'   => __( 'User name', 'demfilz-smtp-mailer' ),
				'type'    => 'text',
				'depends' => array( 'auth' => 1 ),
			),
			'password'   => array(
				'label'       => __( 'Password', 'demfilz-smtp-mailer' ),
				'type'        => 'password',
				'secret'      => true,
				'depends'     => array( 'auth' => 1 ),
				'description' => __( 'Stored encrypted. It can also live in wp-config.php, see the Advanced tab.', 'demfilz-smtp-mailer' ),
			),
			'autotls'    => array(
				'label'       => __( 'Upgrade to TLS automatically', 'demfilz-smtp-mailer' ),
				'type'        => 'checkbox',
				'default'     => 1,
				'description' => __( 'Encrypt the connection when the server offers it, even if Encryption is set to None.', 'demfilz-smtp-mailer' ),
			),
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

		if ( $this->option( 'auth', 1 ) && ( '' === (string) $this->option( 'username', '' ) || '' === (string) $this->option( 'password', '' ) ) ) {
			return new WP_Error( 'dsmtp_incomplete', __( 'SMTP authentication is on, so a user name and password are needed.', 'demfilz-smtp-mailer' ) );
		}

		return true;
	}

	/**
	 * Configure PHPMailer.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer instance.
	 * @return void
	 */
	public function configure( $phpmailer ) {
		$phpmailer->isSMTP();
		$phpmailer->Host        = (string) $this->option( 'host', '' );
		$phpmailer->Port        = (int) $this->option( 'port', 587 );
		$phpmailer->SMTPAuth    = (bool) $this->option( 'auth', 1 );
		$phpmailer->SMTPAutoTLS = (bool) $this->option( 'autotls', 1 );

		$encryption = (string) $this->option( 'encryption', 'tls' );

		if ( 'none' === $encryption ) {
			$phpmailer->SMTPSecure = '';
		} else {
			$phpmailer->SMTPSecure = $encryption;
		}

		if ( $phpmailer->SMTPAuth ) {
			$phpmailer->Username = (string) $this->option( 'username', '' );
			$phpmailer->Password = (string) $this->option( 'password', '' );
		}
	}
}
