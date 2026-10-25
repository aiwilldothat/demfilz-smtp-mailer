<?php
/**
 * Failure alerts and the weekly summary.
 *
 * @package Demfilz_SMTP_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tells somebody when mail stops going out.
 */
class DSMTP_Alerts {

	/**
	 * Hook the alert handlers.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'dsmtp_send_failed', array( __CLASS__, 'on_failure' ), 10, 3 );
		add_action( 'dsmtp_bounced', array( __CLASS__, 'on_bounce' ), 10, 2 );
	}

	/**
	 * React to a bounce or complaint reported by the provider.
	 *
	 * @param array      $event Normalised delivery event.
	 * @param array|null $row   Matching log row.
	 * @return void
	 */
	public static function on_bounce( $event, $row ) {
		if ( ! DSMTP_Settings::get( 'alerts_enabled', 0 ) || get_transient( 'dsmtp_alert_bounce' ) ) {
			return;
		}

		set_transient( 'dsmtp_alert_bounce', 1, HOUR_IN_SECONDS );

		$complaint = isset( $event['kind'] ) && DSMTP_Events::KIND_COMPLAINT === $event['kind'];
		$site      = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject   = $complaint
			? sprintf(
				/* translators: %s: site name. */
				__( 'A recipient marked mail from %s as spam', 'demfilz-smtp-mailer' ),
				$site
			)
			: sprintf(
				/* translators: %s: site name. */
				__( 'An email from %s bounced', 'demfilz-smtp-mailer' ),
				$site
			);

		$lines = array(
			sprintf(
				/* translators: %s: recipient address. */
				__( 'Recipient: %s', 'demfilz-smtp-mailer' ),
				$event['email']
			),
			sprintf(
				/* translators: %s: subject line. */
				__( 'Subject: %s', 'demfilz-smtp-mailer' ),
				$row && isset( $row['subject'] ) ? $row['subject'] : __( 'unknown', 'demfilz-smtp-mailer' )
			),
			sprintf(
				/* translators: %s: provider explanation. */
				__( 'Reason: %s', 'demfilz-smtp-mailer' ),
				$event['detail']
			),
			'',
			__( 'If suppression is on, the address will not be emailed again.', 'demfilz-smtp-mailer' ),
			admin_url( 'admin.php?page=dsmtp-suppress' ),
		);

		$recipient = (string) DSMTP_Settings::get( 'alert_email', '' );

		if ( ! is_email( $recipient ) ) {
			$recipient = (string) get_option( 'admin_email' );
		}

		if ( is_email( $recipient ) ) {
			wp_mail( $recipient, $subject, implode( PHP_EOL, $lines ) );
		}

		self::webhook(
			array(
				'event'   => $complaint ? 'complaint' : 'bounce',
				'site'    => home_url( '/' ),
				'mailer'  => $event['source'],
				'to'      => $event['email'],
				'subject' => $row && isset( $row['subject'] ) ? $row['subject'] : '',
				'error'   => $event['detail'],
				'text'    => $subject . ': ' . $event['email'] . ' - ' . $event['detail'],
			)
		);
	}

	/**
	 * React to a message that could not be delivered.
	 *
	 * @param string $message Error message.
	 * @param array  $row     Log row values.
	 * @param int    $log_id  Log row id.
	 * @return void
	 */
	public static function on_failure( $message, $row, $log_id ) {
		set_transient(
			'dsmtp_last_failure',
			array(
				'message' => $message,
				'time'    => time(),
				'log_id'  => (int) $log_id,
			),
			WEEK_IN_SECONDS
		);

		if ( ! DSMTP_Settings::get( 'alerts_enabled', 0 ) ) {
			return;
		}

		if ( get_transient( 'dsmtp_alert_sent' ) ) {
			return;
		}

		set_transient( 'dsmtp_alert_sent', 1, HOUR_IN_SECONDS );

		$subject = sprintf(
			/* translators: %s: site name. */
			__( 'Email is failing on %s', 'demfilz-smtp-mailer' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);

		$provider = DSMTP_Providers::active();

		$lines = array(
			__( 'WordPress could not send an email.', 'demfilz-smtp-mailer' ),
			'',
			sprintf(
				/* translators: %s: mailer name. */
				__( 'Mailer: %s', 'demfilz-smtp-mailer' ),
				$provider::label()
			),
			sprintf(
				/* translators: %s: subject line of the failed message. */
				__( 'Subject: %s', 'demfilz-smtp-mailer' ),
				isset( $row['subject'] ) ? $row['subject'] : ''
			),
			sprintf(
				/* translators: %s: recipient. */
				__( 'To: %s', 'demfilz-smtp-mailer' ),
				isset( $row['to_email'] ) ? $row['to_email'] : ''
			),
			'',
			sprintf(
				/* translators: %s: error message. */
				__( 'Reason: %s', 'demfilz-smtp-mailer' ),
				$message
			),
			'',
			sprintf(
				/* translators: %s: admin URL. */
				__( 'Email log: %s', 'demfilz-smtp-mailer' ),
				admin_url( 'admin.php?page=dsmtp-log' )
			),
		);

		$body      = implode( "\n", $lines );
		$recipient = (string) DSMTP_Settings::get( 'alert_email', '' );

		if ( ! is_email( $recipient ) ) {
			$recipient = (string) get_option( 'admin_email' );
		}

		if ( is_email( $recipient ) ) {
			wp_mail( $recipient, $subject, $body );
		}

		self::webhook(
			array(
				'event'   => 'send_failed',
				'site'    => home_url( '/' ),
				'mailer'  => $provider::id(),
				'subject' => isset( $row['subject'] ) ? $row['subject'] : '',
				'to'      => isset( $row['to_email'] ) ? $row['to_email'] : '',
				'error'   => $message,
				'text'    => $subject . ': ' . $message,
			)
		);
	}

	/**
	 * POST an alert to the configured webhook.
	 *
	 * @param array $payload Alert data.
	 * @return void
	 */
	private static function webhook( $payload ) {
		$url = (string) DSMTP_Settings::get( 'alert_webhook', '' );

		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return;
		}

		wp_remote_post(
			$url,
			array(
				'timeout'  => 10,
				'blocking' => false,
				'headers'  => array( 'Content-Type' => 'application/json' ),
				'body'     => wp_json_encode( $payload ),
			)
		);
	}

	/**
	 * Send the weekly summary.
	 *
	 * @return void
	 */
	public static function send_summary() {
		if ( ! DSMTP_Settings::get( 'summary_enabled', 0 ) ) {
			return;
		}

		$stats     = DSMTP_Log::stats( 7 );
		$recipient = (string) DSMTP_Settings::get( 'summary_email', '' );

		if ( ! is_email( $recipient ) ) {
			$recipient = (string) get_option( 'admin_email' );
		}

		if ( ! is_email( $recipient ) ) {
			return;
		}

		$provider = DSMTP_Providers::active();

		$body = implode(
			"\n",
			array(
				sprintf(
					/* translators: %s: site name. */
					__( 'Email summary for %s, the last seven days.', 'demfilz-smtp-mailer' ),
					wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
				),
				'',
				sprintf(
					/* translators: %d: number of messages. */
					__( 'Delivered: %d', 'demfilz-smtp-mailer' ),
					$stats['sent']
				),
				sprintf(
					/* translators: %d: number of messages. */
					__( 'Failed: %d', 'demfilz-smtp-mailer' ),
					$stats['failed']
				),
				sprintf(
					/* translators: %s: mailer name. */
					__( 'Mailer: %s', 'demfilz-smtp-mailer' ),
					$provider::label()
				),
				'',
				admin_url( 'admin.php?page=dsmtp-log' ),
			)
		);

		wp_mail(
			$recipient,
			sprintf(
				/* translators: %s: site name. */
				__( 'Weekly email summary for %s', 'demfilz-smtp-mailer' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			),
			$body
		);
	}

	/**
	 * The most recent failure, for the admin notice.
	 *
	 * @return array|null
	 */
	public static function last_failure() {
		$failure = get_transient( 'dsmtp_last_failure' );

		return is_array( $failure ) ? $failure : null;
	}

	/**
	 * Clear the recorded failure.
	 *
	 * @return void
	 */
	public static function clear_failure() {
		delete_transient( 'dsmtp_last_failure' );
	}
}
