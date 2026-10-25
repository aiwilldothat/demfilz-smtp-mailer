<?php
/**
 * Routing, sender control, logging and retries.
 *
 * @package Demfilz_SMTP_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The part of the plugin that stands between wp_mail() and the world.
 */
class DSMTP_Mailer {

	/**
	 * Arguments of the message being sent.
	 *
	 * @var array
	 */
	private static $atts = array();

	/**
	 * Log row being updated instead of inserted, when retrying.
	 *
	 * @var int
	 */
	private static $retry_id = 0;

	/**
	 * Whether the current message has already been written to the log.
	 *
	 * @var bool
	 */
	private static $recorded = false;

	/**
	 * Collected SMTP conversation, used by the test tool.
	 *
	 * @var array
	 */
	private static $debug = array();

	/**
	 * Whether to collect the SMTP conversation for this message.
	 *
	 * @var bool
	 */
	private static $capture_debug = false;

	/**
	 * Whether our own PHPMailer subclass is in place.
	 *
	 * @var bool
	 */
	private static $catcher_ready = false;

	/**
	 * Guard so a failure alert cannot trigger another failure alert.
	 *
	 * @var bool
	 */
	private static $sending_alert = false;

	/**
	 * Hook everything up.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'pre_wp_mail', array( __CLASS__, 'pre_wp_mail' ), 1, 2 );
		add_action( 'phpmailer_init', array( __CLASS__, 'configure' ), 1000 );
		add_filter( 'wp_mail_from', array( __CLASS__, 'filter_from_email' ), 1000 );
		add_filter( 'wp_mail_from_name', array( __CLASS__, 'filter_from_name' ), 1000 );
		add_action( 'wp_mail_failed', array( __CLASS__, 'on_failed' ), 10, 1 );
	}

	// Sending.

	/**
	 * Remember the message and put our mailer object in place.
	 *
	 * @param null|bool $short_circuit Whatever an earlier filter decided.
	 * @param array     $atts          Arguments passed to wp_mail().
	 * @return null|bool
	 */
	public static function pre_wp_mail( $short_circuit, $atts ) {
		if ( null !== $short_circuit ) {
			return $short_circuit;
		}

		self::$atts     = is_array( $atts ) ? $atts : array();
		self::$recorded = false;
		self::$debug    = array();

		if ( DSMTP_Controls::is_suppressed( self::$atts ) ) {
			self::$atts = array();

			return true;
		}

		/*
		 * The suppression list has already removed dead addresses from the
		 * recipients (on the wp_mail filter). If nothing is left, record the
		 * message as blocked rather than letting PHPMailer fail on an empty
		 * recipient list.
		 */
		if ( DSMTP_Suppression::stripped() && self::recipients_empty( self::$atts ) ) {
			self::record_blocked();

			return true;
		}

		self::install_catcher();

		return null;
	}

	/**
	 * Whether a wp_mail() call has no recipients left.
	 *
	 * @param array $atts Arguments passed to wp_mail().
	 * @return bool
	 */
	private static function recipients_empty( $atts ) {
		$to = isset( $atts['to'] ) ? $atts['to'] : '';

		if ( is_array( $to ) ) {
			$to = implode( ',', array_map( 'strval', $to ) );
		}

		if ( '' !== trim( (string) $to ) ) {
			return false;
		}

		$headers = isset( $atts['headers'] ) ? $atts['headers'] : '';
		$headers = is_array( $headers ) ? implode( "\n", array_map( 'strval', $headers ) ) : (string) $headers;

		return ! preg_match( '/^\s*(cc|bcc)\s*:\s*\S/im', $headers );
	}

	/**
	 * Log a message that was dropped because every recipient is suppressed.
	 *
	 * @return void
	 */
	private static function record_blocked() {
		self::$recorded = true;

		$stripped = DSMTP_Suppression::stripped();
		$row      = self::row_from_mail( null );

		$row['status']   = DSMTP_Log::STATUS_BLOCKED;
		$row['to_email'] = implode( ', ', $stripped );
		$row['error']    = sprintf(
			/* translators: %s: comma separated addresses. */
			__( 'Not sent: every recipient is on the suppression list (%s).', 'demfilz-smtp-mailer' ),
			implode( ', ', $stripped )
		);
		$row['retry_at'] = null;

		DSMTP_Suppression::reset();

		if ( DSMTP_Settings::get( 'log_enabled', 1 ) ) {
			DSMTP_Log::insert( $row );
		}
	}

	/**
	 * Replace the global PHPMailer object with ours.
	 *
	 * Backs off when another plugin has already installed its own, so two mail
	 * plugins never fight over the same message.
	 *
	 * @return void
	 */
	public static function install_catcher() {
		global $phpmailer;

		require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
		require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
		require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';

		if ( file_exists( ABSPATH . WPINC . '/class-wp-phpmailer.php' ) ) {
			require_once ABSPATH . WPINC . '/class-wp-phpmailer.php';
		}

		require_once DSMTP_DIR . 'includes/class-dsmtp-catcher.php';

		if ( $phpmailer instanceof DSMTP_Mail_Catcher ) {
			self::$catcher_ready = true;

			return;
		}

		if ( is_object( $phpmailer ) && ! in_array( get_class( $phpmailer ), array( 'WP_PHPMailer', 'PHPMailer\PHPMailer\PHPMailer' ), true ) ) {
			// Another mail plugin owns the transport. Leave it alone.
			self::$catcher_ready = false;
			set_transient( 'dsmtp_conflict', get_class( $phpmailer ), DAY_IN_SECONDS );

			return;
		}

		/*
		 * Core creates this same global itself when it is missing. Replacing it
		 * with a subclass is the supported way to take over delivery, and it is
		 * only done when the object is still the untouched core one.
		 */
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Deliberate, see above.
		$phpmailer = new DSMTP_Mail_Catcher( true );

		$phpmailer::$validator = static function ( $email ) {
			return (bool) is_email( $email );
		};

		self::$catcher_ready = true;
	}

	/**
	 * Configure PHPMailer for the selected mailer.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer instance.
	 * @return void
	 */
	public static function configure( $phpmailer ) {
		$provider = DSMTP_Providers::active();

		if ( DSMTP_Provider::TYPE_API === $provider::type() ) {
			if ( ! self::$catcher_ready ) {
				// Without our own mailer object an API mailer cannot deliver.
				return;
			}

			$phpmailer->isSMTP();
		}

		$provider->configure( $phpmailer );

		self::apply_sender( $phpmailer, $provider );

		$phpmailer->Timeout = (int) apply_filters( 'dsmtp_smtp_timeout', 30 );

		if ( self::$capture_debug || DSMTP_Settings::get( 'debug_smtp', 0 ) ) {
			$phpmailer->SMTPDebug   = 2;
			$phpmailer->Debugoutput = array( __CLASS__, 'collect_debug' );
		}

		/**
		 * Fires after the plugin has configured PHPMailer.
		 *
		 * @since 1.0.0
		 *
		 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer instance.
		 * @param DSMTP_Provider                $provider  Active mailer.
		 */
		do_action( 'dsmtp_after_configure', $phpmailer, $provider );
	}

	/**
	 * Apply the from address, reply to and return path rules.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer instance.
	 * @param DSMTP_Provider                $provider  Active mailer.
	 * @return void
	 */
	private static function apply_sender( $phpmailer, $provider ) {
		$forced = $provider->forced_from_email();

		/**
		 * Filters whether the mailbox a provider authenticated as replaces the from address.
		 *
		 * Microsoft and Google both refuse to send as an address the connected
		 * mailbox is not allowed to use, so this defaults to true.
		 *
		 * @since 1.0.0
		 *
		 * @param bool   $enforce     Whether to rewrite the from address.
		 * @param string $provider_id Active mailer id.
		 */
		$enforce = (bool) apply_filters( 'dsmtp_enforce_provider_from', true, $provider::id() );

		if ( $enforce && is_email( $forced ) && strtolower( $forced ) !== strtolower( $phpmailer->From ) ) {
			$original = $phpmailer->From;

			try {
				if ( is_email( $original ) && ! $phpmailer->getReplyToAddresses() ) {
					$phpmailer->addReplyTo( $original, $phpmailer->FromName );
				}

				$phpmailer->setFrom( $forced, $phpmailer->FromName, false );
			} catch ( Exception $e ) {
				// Keep the original sender if the provider address is unusable.
				unset( $e );
			}
		}

		$reply_to = (string) DSMTP_Settings::get( 'reply_to', '' );

		if ( is_email( $reply_to ) && ! $phpmailer->getReplyToAddresses() ) {
			try {
				$phpmailer->addReplyTo( $reply_to );
			} catch ( Exception $e ) {
				unset( $e );
			}
		}

		if ( DSMTP_Settings::get( 'set_return_path', 1 ) ) {
			$phpmailer->Sender = $phpmailer->From;
		}
	}

	/**
	 * The from address WordPress should use.
	 *
	 * @param string $from Address chosen by WordPress or another plugin.
	 * @return string
	 */
	public static function filter_from_email( $from ) {
		$configured = (string) DSMTP_Settings::get( 'from_email', '' );

		if ( ! is_email( $configured ) ) {
			return $from;
		}

		if ( DSMTP_Settings::get( 'force_from_email', 0 ) || self::is_default_from( $from ) ) {
			return $configured;
		}

		return $from;
	}

	/**
	 * The from name WordPress should use.
	 *
	 * @param string $name Name chosen by WordPress or another plugin.
	 * @return string
	 */
	public static function filter_from_name( $name ) {
		$configured = (string) DSMTP_Settings::get( 'from_name', '' );

		if ( '' === trim( $configured ) ) {
			return $name;
		}

		if ( DSMTP_Settings::get( 'force_from_name', 0 ) || 'WordPress' === $name ) {
			return $configured;
		}

		return $name;
	}

	/**
	 * Whether an address is the one WordPress makes up when nothing is set.
	 *
	 * @param string $from Address.
	 * @return bool
	 */
	private static function is_default_from( $from ) {
		$sitename = wp_parse_url( network_home_url(), PHP_URL_HOST );

		if ( ! $sitename ) {
			return false;
		}

		$sitename = strtolower( $sitename );

		if ( 0 === strpos( $sitename, 'www.' ) ) {
			$sitename = substr( $sitename, 4 );
		}

		return strtolower( (string) $from ) === 'wordpress@' . $sitename;
	}

	/**
	 * Collect one line of the SMTP conversation.
	 *
	 * @param string $line  Debug line.
	 * @param int    $level Debug level.
	 * @return void
	 */
	public static function collect_debug( $line, $level = 0 ) {
		unset( $level );

		$line = trim( (string) $line );

		if ( '' === $line ) {
			return;
		}

		if ( count( self::$debug ) < 200 ) {
			self::$debug[] = $line;
		}
	}

	/**
	 * The SMTP conversation collected for the last message.
	 *
	 * @return array
	 */
	public static function debug_lines() {
		return self::$debug;
	}

	// Logging.

	/**
	 * Record a delivered message. Called by the mailer object.
	 *
	 * @param DSMTP_Mail_Catcher $mail Mailer that just sent.
	 * @return void
	 */
	public static function record_success( $mail ) {
		if ( self::$recorded ) {
			return;
		}

		self::$recorded = true;

		if ( ! DSMTP_Settings::get( 'log_enabled', 1 ) && ! self::$retry_id ) {
			return;
		}

		$row               = self::row_from_mail( $mail );
		$row['status']     = DSMTP_Log::STATUS_SENT;
		$row['message_id'] = '' !== $mail->dsmtp_message_id ? $mail->dsmtp_message_id : trim( (string) $mail->getLastMessageID(), '<>' );
		$row['retry_at']   = null;

		if ( '' !== $mail->dsmtp_failover ) {
			// Keep any note row_from_mail() left, such as suppressed recipients.
			$row['error'] = trim(
				sprintf(
					/* translators: %s: error reported by the primary mailer. */
					__( 'Sent by the backup mailer after the first attempt failed: %s', 'demfilz-smtp-mailer' ),
					$mail->dsmtp_failover
				) . ( isset( $row['error'] ) && '' !== $row['error'] ? ' ' . $row['error'] : '' )
			);
		} elseif ( ! isset( $row['error'] ) ) {
			$row['error'] = '';
		}

		self::write( $row );
	}

	/**
	 * Record a message that could not be delivered.
	 *
	 * @param WP_Error $error Error raised by wp_mail().
	 * @return void
	 */
	public static function on_failed( $error ) {
		if ( self::$recorded ) {
			return;
		}

		self::$recorded = true;

		global $phpmailer;

		$mail    = $phpmailer instanceof DSMTP_Mail_Catcher ? $phpmailer : null;
		$message = is_wp_error( $error ) ? $error->get_error_message() : (string) $error;
		$row     = self::row_from_mail( $mail );

		$row['status'] = DSMTP_Log::STATUS_FAILED;
		$row['error']  = wp_strip_all_tags( $message );

		$attempts  = self::$retry_id ? (int) self::attempts_of( self::$retry_id ) : 0;
		$max       = (int) DSMTP_Settings::get( 'retry_max', 3 );
		$can_retry = DSMTP_Settings::get( 'retry_enabled', 1 ) && $attempts < $max && ! empty( $row['payload'] ) && ! self::$sending_alert;

		if ( $can_retry ) {
			$row['status']   = DSMTP_Log::STATUS_QUEUED;
			$row['attempts'] = $attempts + 1;
			$row['retry_at'] = gmdate( 'Y-m-d H:i:s', time() + self::retry_delay( $attempts ) );
		} else {
			$row['attempts'] = $attempts;
			$row['retry_at'] = null;
		}

		$log_id = self::write( $row );

		if ( DSMTP_Log::STATUS_FAILED === $row['status'] && ! self::$sending_alert ) {
			self::$sending_alert = true;

			/**
			 * Fires when a message has finally failed, after any retries.
			 *
			 * @since 1.0.0
			 *
			 * @param string $message Error message.
			 * @param array  $row     Log row values.
			 * @param int    $log_id  Log row id, 0 when logging is off.
			 */
			do_action( 'dsmtp_send_failed', $row['error'], $row, $log_id );

			self::$sending_alert = false;
		}
	}

	/**
	 * How long to wait before the next attempt.
	 *
	 * @param int $attempts Attempts already made.
	 * @return int Seconds.
	 */
	private static function retry_delay( $attempts ) {
		$delay = 5 * MINUTE_IN_SECONDS * pow( 2, max( 0, (int) $attempts ) );

		return (int) min( $delay, 6 * HOUR_IN_SECONDS );
	}

	/**
	 * Attempts already recorded against a log row.
	 *
	 * @param int $log_id Row id.
	 * @return int
	 */
	private static function attempts_of( $log_id ) {
		$row = DSMTP_Log::get( $log_id );

		return $row ? (int) $row['attempts'] : 0;
	}

	/**
	 * Insert or update the log row for the current message.
	 *
	 * @param array $row Row values.
	 * @return int Row id, 0 when logging is off.
	 */
	private static function write( $row ) {
		if ( self::$retry_id ) {
			$id = self::$retry_id;

			DSMTP_Log::update( $id, $row );

			return $id;
		}

		if ( ! DSMTP_Settings::get( 'log_enabled', 1 ) && DSMTP_Log::STATUS_QUEUED !== $row['status'] ) {
			return 0;
		}

		return DSMTP_Log::insert( $row );
	}

	/**
	 * Build a log row from the mailer object, falling back to the wp_mail arguments.
	 *
	 * @param DSMTP_Mail_Catcher|null $mail Mailer object, when there is one.
	 * @return array
	 */
	private static function row_from_mail( $mail ) {
		$atts   = self::$atts;
		$active = DSMTP_Providers::active();

		$row = array(
			'created_at' => current_time( 'mysql', true ),
			'mailer'     => $mail && '' !== $mail->dsmtp_mailer ? $mail->dsmtp_mailer : $active::id(),
			'from_email' => '',
			'to_email'   => isset( $atts['to'] ) ? self::flatten( $atts['to'] ) : '',
			'cc_email'   => '',
			'bcc_email'  => '',
			'subject'    => isset( $atts['subject'] ) ? (string) $atts['subject'] : '',
			'headers'    => '',
			'body'       => '',
			'source'     => self::detect_source(),
			'initiator'  => get_current_user_id(),
		);

		if ( $mail ) {
			$row['from_email'] = $mail->From;
			$row['subject']    = $mail->Subject;
			$row['to_email']   = self::address_string( $mail->getToAddresses() );
			$row['cc_email']   = self::address_string( $mail->getCcAddresses() );
			$row['bcc_email']  = self::address_string( $mail->getBccAddresses() );
			$row['headers']    = self::header_string( $mail );

			$names = array();

			foreach ( $mail->getAttachments() as $attachment ) {
				$names[] = (string) $attachment[2];
			}

			$row['attachments'] = implode( ', ', $names );

			if ( DSMTP_Settings::get( 'log_body', 0 ) ) {
				$row['body'] = $mail->Body;
			}
		} elseif ( DSMTP_Settings::get( 'log_body', 0 ) && isset( $atts['message'] ) ) {
			$row['body'] = (string) $atts['message'];
		}

		if ( DSMTP_Settings::get( 'retry_enabled', 1 ) && $atts ) {
			$row['payload'] = wp_json_encode( self::payload( $atts ) );
		}

		$stripped = DSMTP_Suppression::stripped();

		if ( $stripped && $mail ) {
			$row['error'] = sprintf(
				/* translators: %s: comma separated addresses. */
				__( 'Suppressed recipients were left out: %s', 'demfilz-smtp-mailer' ),
				implode( ', ', $stripped )
			);

			DSMTP_Suppression::reset();
		}

		return $row;
	}

	/**
	 * The parts of a wp_mail() call needed to send it again.
	 *
	 * @param array $atts Arguments passed to wp_mail().
	 * @return array
	 */
	private static function payload( $atts ) {
		return array(
			'to'          => isset( $atts['to'] ) ? $atts['to'] : '',
			'subject'     => isset( $atts['subject'] ) ? $atts['subject'] : '',
			'message'     => isset( $atts['message'] ) ? $atts['message'] : '',
			'headers'     => isset( $atts['headers'] ) ? $atts['headers'] : '',
			'attachments' => isset( $atts['attachments'] ) ? $atts['attachments'] : array(),
		);
	}

	/**
	 * Turn a string or array of addresses into one string.
	 *
	 * @param mixed $value Address value.
	 * @return string
	 */
	private static function flatten( $value ) {
		if ( is_array( $value ) ) {
			return implode( ', ', array_map( 'strval', $value ) );
		}

		return (string) $value;
	}

	/**
	 * Format PHPMailer address pairs for the log.
	 *
	 * @param array $addresses PHPMailer address arrays.
	 * @return string
	 */
	private static function address_string( $addresses ) {
		$out = array();

		foreach ( (array) $addresses as $address ) {
			if ( empty( $address[0] ) ) {
				continue;
			}

			$out[] = empty( $address[1] ) ? $address[0] : $address[1] . ' <' . $address[0] . '>';
		}

		return implode( ', ', $out );
	}

	/**
	 * The headers worth keeping in the log.
	 *
	 * @param DSMTP_Mail_Catcher $mail Mailer object.
	 * @return string
	 */
	private static function header_string( $mail ) {
		$lines = array();

		if ( '' !== $mail->From ) {
			$lines[] = 'From: ' . ( '' !== $mail->FromName ? $mail->FromName . ' <' . $mail->From . '>' : $mail->From );
		}

		$reply_to = self::address_string( $mail->getReplyToAddresses() );

		if ( '' !== $reply_to ) {
			$lines[] = 'Reply-To: ' . $reply_to;
		}

		$lines[] = 'Content-Type: ' . $mail->ContentType . '; charset=' . $mail->CharSet;

		foreach ( $mail->getCustomHeaders() as $header ) {
			if ( empty( $header[0] ) ) {
				continue;
			}

			$lines[] = $header[0] . ': ' . ( isset( $header[1] ) ? $header[1] : '' );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Work out which plugin or theme asked for this message.
	 *
	 * @return string
	 */
	private static function detect_source() {
		if ( ! function_exists( 'debug_backtrace' ) ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions -- Used to name the plugin that sent the message, never to display a path.
		$frames  = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 30 );
		$plugins = wp_normalize_path( WP_PLUGIN_DIR );
		$themes  = wp_normalize_path( get_theme_root() );

		foreach ( $frames as $frame ) {
			if ( empty( $frame['file'] ) ) {
				continue;
			}

			$file = wp_normalize_path( $frame['file'] );

			if ( 0 === strpos( $file, $plugins ) ) {
				$relative = trim( substr( $file, strlen( $plugins ) ), '/' );
				$folder   = strtok( $relative, '/' );

				if ( 'demfilz-smtp-mailer' === $folder ) {
					continue;
				}

				return 'plugin:' . $folder;
			}

			if ( 0 === strpos( $file, $themes ) ) {
				$relative = trim( substr( $file, strlen( $themes ) ), '/' );

				return 'theme:' . strtok( $relative, '/' );
			}
		}

		return 'WordPress';
	}

	// Retrying and testing.

	/**
	 * Send a logged message again.
	 *
	 * @param int  $log_id Log row id.
	 * @param bool $update Whether to update that row rather than add a new one.
	 * @return true|WP_Error
	 */
	public static function resend( $log_id, $update = false ) {
		$row = DSMTP_Log::get( $log_id );

		if ( ! $row ) {
			return new WP_Error( 'dsmtp_missing', __( 'That log entry no longer exists.', 'demfilz-smtp-mailer' ) );
		}

		$payload = json_decode( (string) $row['payload'], true );

		if ( ! is_array( $payload ) || empty( $payload['to'] ) ) {
			return new WP_Error( 'dsmtp_no_payload', __( 'This entry was logged without enough detail to send it again.', 'demfilz-smtp-mailer' ) );
		}

		self::$retry_id = $update ? (int) $log_id : 0;

		$sent = wp_mail(
			$payload['to'],
			isset( $payload['subject'] ) ? $payload['subject'] : '',
			isset( $payload['message'] ) ? $payload['message'] : '',
			isset( $payload['headers'] ) ? $payload['headers'] : '',
			isset( $payload['attachments'] ) ? (array) $payload['attachments'] : array()
		);

		self::$retry_id = 0;

		if ( ! $sent ) {
			return new WP_Error( 'dsmtp_failed', __( 'The message failed again. The log entry has the reason.', 'demfilz-smtp-mailer' ) );
		}

		return true;
	}

	/**
	 * Send a test message and report exactly what happened.
	 *
	 * @param string $to   Recipient.
	 * @param bool   $html Whether to send an HTML message.
	 * @return array With sent, error and debug keys.
	 */
	public static function send_test( $to, $html = true ) {
		self::$capture_debug = true;
		self::$debug         = array();

		$error = '';

		$capture = static function ( $wp_error ) use ( &$error ) {
			if ( is_wp_error( $wp_error ) ) {
				$error = $wp_error->get_error_message();
			}
		};

		add_action( 'wp_mail_failed', $capture, 5 );

		$content_type = static function () {
			return 'text/html';
		};

		if ( $html ) {
			add_filter( 'wp_mail_content_type', $content_type );
		}

		$sent = wp_mail(
			$to,
			sprintf(
				/* translators: %s: site name. */
				__( 'Test email from %s', 'demfilz-smtp-mailer' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			),
			self::test_body( $html ),
			array()
		);

		if ( $html ) {
			remove_filter( 'wp_mail_content_type', $content_type );
		}

		remove_action( 'wp_mail_failed', $capture, 5 );

		self::$capture_debug = false;

		return array(
			'sent'  => (bool) $sent,
			'error' => $error,
			'debug' => self::$debug,
		);
	}

	/**
	 * Body of the test message.
	 *
	 * @param bool $html Whether to build HTML.
	 * @return string
	 */
	private static function test_body( $html ) {
		$provider = DSMTP_Providers::active();

		$lines = array(
			__( 'This is a test message from the Demfilz SMTP Mailer plugin.', 'demfilz-smtp-mailer' ),
			sprintf(
				/* translators: %s: mailer name. */
				__( 'Mailer: %s', 'demfilz-smtp-mailer' ),
				$provider::label()
			),
			sprintf(
				/* translators: %s: site address. */
				__( 'Sent from: %s', 'demfilz-smtp-mailer' ),
				home_url( '/' )
			),
			sprintf(
				/* translators: %s: date and time. */
				__( 'Sent at: %s', 'demfilz-smtp-mailer' ),
				gmdate( 'Y-m-d H:i:s' ) . ' UTC'
			),
		);

		if ( ! $html ) {
			return implode( "\n", $lines );
		}

		$out = '<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#1f2933">';

		foreach ( $lines as $index => $line ) {
			$out .= 0 === $index ? '<p style="font-size:17px;font-weight:600">' . esc_html( $line ) . '</p>' : '<p>' . esc_html( $line ) . '</p>';
		}

		$out .= '<p style="color:#52606d">' . esc_html__( 'If you can read this, HTML email is working too.', 'demfilz-smtp-mailer' ) . '</p></div>';

		return $out;
	}

	/**
	 * Whether another mail plugin has taken over the transport.
	 *
	 * @return string Class name of the other mailer, empty when clear.
	 */
	public static function conflict() {
		$conflict = get_transient( 'dsmtp_conflict' );

		return is_string( $conflict ) ? $conflict : '';
	}

	/**
	 * Forget a recorded conflict.
	 *
	 * @return void
	 */
	public static function clear_conflict() {
		delete_transient( 'dsmtp_conflict' );
	}
}
