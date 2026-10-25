<?php
/**
 * The default PHP mail() transport.
 *
 * @package Demfilz_SMTP_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Leaves sending to the web server, exactly as WordPress does out of the box.
 *
 * Selecting it still gives you the from address controls, the email log and the
 * failure alerts, which makes it a useful starting point.
 */
class DSMTP_Provider_PHP extends DSMTP_Provider {

	/**
	 * Provider identifier.
	 *
	 * @return string
	 */
	public static function id() {
		return 'php';
	}

	/**
	 * Human readable name.
	 *
	 * @return string
	 */
	public static function label() {
		return __( 'Default (PHP mail)', 'demfilz-smtp-mailer' );
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
		return self::TYPE_NATIVE;
	}

	/**
	 * One line summary.
	 *
	 * @return string
	 */
	public static function description() {
		return __( 'Hands the message to the web server. Cheap, but most hosts and inboxes treat it as unauthenticated mail.', 'demfilz-smtp-mailer' );
	}

	/**
	 * Setup notes.
	 *
	 * @return string
	 */
	public static function setup_notes() {
		return __( 'Nothing to configure. Mail goes out through the PHP mail function, which is what WordPress does with no mail plugin installed. Pick any other mailer below to authenticate properly and stop landing in spam folders.', 'demfilz-smtp-mailer' );
	}

	/**
	 * Configure PHPMailer.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer instance.
	 * @return void
	 */
	public function configure( $phpmailer ) {
		$phpmailer->isMail();
	}
}
