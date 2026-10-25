<?php
/**
 * Base class every mailer extends.
 *
 * @package Demfilz_SMTP_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A single way of sending mail.
 *
 * Providers come in three shapes. Native providers leave PHPMailer alone, SMTP
 * providers configure PHPMailer and let it talk SMTP, and API providers take the
 * finished message and hand it to an HTTP API.
 */
abstract class DSMTP_Provider {

	const TYPE_NATIVE = 'native';
	const TYPE_SMTP   = 'smtp';
	const TYPE_API    = 'api';

	/**
	 * Connection settings for this provider.
	 *
	 * @var array
	 */
	protected $config = array();

	/**
	 * Read the stored settings for this provider.
	 */
	public function __construct() {
		$this->config = DSMTP_Settings::provider_config( static::id() );
	}

	/**
	 * Provider identifier.
	 *
	 * @return string
	 */
	abstract public static function id();

	/**
	 * Human readable name.
	 *
	 * @return string
	 */
	abstract public static function label();

	/**
	 * Group heading on the settings screen.
	 *
	 * @return string
	 */
	public static function group() {
		return __( 'Other services', 'demfilz-smtp-mailer' );
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
	 * One line summary shown under the provider name.
	 *
	 * @return string
	 */
	public static function description() {
		return '';
	}

	/**
	 * Setup notes shown above the connection fields.
	 *
	 * @return string
	 */
	public static function setup_notes() {
		return '';
	}

	/**
	 * Connection fields.
	 *
	 * @return array
	 */
	public static function fields() {
		return array();
	}

	/**
	 * Field keys that must be filled in before the provider can send.
	 *
	 * @return array
	 */
	public static function required_fields() {
		$required = array();

		foreach ( DSMTP_Providers::fields( static::id() ) as $key => $field ) {
			if ( ! empty( $field['required'] ) ) {
				$required[] = $key;
			}
		}

		return $required;
	}

	/**
	 * A stored setting for this provider.
	 *
	 * @param string $key      Field key.
	 * @param mixed  $fallback Value when unset.
	 * @return mixed
	 */
	public function option( $key, $fallback = '' ) {
		return isset( $this->config[ $key ] ) && '' !== $this->config[ $key ] ? $this->config[ $key ] : $fallback;
	}

	/**
	 * Whether the provider has everything it needs.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return ! is_wp_error( $this->validate() );
	}

	/**
	 * Check the configuration.
	 *
	 * @return true|WP_Error
	 */
	public function validate() {
		$fields  = DSMTP_Providers::fields( static::id() );
		$missing = array();

		foreach ( static::required_fields() as $key ) {
			if ( '' === (string) $this->option( $key, '' ) ) {
				$missing[] = isset( $fields[ $key ]['label'] ) ? $fields[ $key ]['label'] : $key;
			}
		}

		if ( $missing ) {
			return new WP_Error(
				'dsmtp_incomplete',
				sprintf(
					/* translators: 1: mailer name, 2: comma separated list of setting names. */
					__( '%1$s is not set up yet. Still needed: %2$s.', 'demfilz-smtp-mailer' ),
					static::label(),
					implode( ', ', $missing )
				)
			);
		}

		return true;
	}

	/**
	 * Configure PHPMailer before it sends. SMTP and native providers use this.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer instance.
	 * @return void
	 */
	public function configure( $phpmailer ) {} // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Overridden by subclasses.

	/**
	 * Send a prepared message through an API. API providers override this.
	 *
	 * @param DSMTP_Mail_Catcher $mail Prepared mailer, after preSend().
	 * @return true|WP_Error
	 */
	public function send( $mail ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Overridden by subclasses.
		return new WP_Error( 'dsmtp_not_implemented', __( 'This mailer cannot send messages.', 'demfilz-smtp-mailer' ) );
	}

	/**
	 * The address this provider must send from, when it enforces one.
	 *
	 * @return string Empty when the provider accepts any sender.
	 */
	public function forced_from_email() {
		return '';
	}

	/**
	 * Message id returned by the last successful API send.
	 *
	 * @var string
	 */
	protected $last_message_id = '';

	/**
	 * Message id from the last send.
	 *
	 * @return string
	 */
	public function last_message_id() {
		return $this->last_message_id;
	}

	// Helpers for API providers.

	/**
	 * Perform an HTTP request against a provider API.
	 *
	 * @param string $url  Endpoint.
	 * @param array  $args Arguments for wp_remote_request().
	 * @return array|WP_Error Response array on success.
	 */
	protected function request( $url, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'method'    => 'POST',
				'timeout'   => 30,
				'headers'   => array(),
				'sslverify' => true,
			)
		);

		/**
		 * Filters the HTTP arguments used for provider API calls.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $args        Request arguments.
		 * @param string $url         Endpoint.
		 * @param string $provider_id Provider identifier.
		 */
		$args = apply_filters( 'dsmtp_api_request_args', $args, $url, static::id() );

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'dsmtp_http_error',
				sprintf(
					/* translators: 1: mailer name, 2: error message. */
					__( 'Could not reach %1$s: %2$s', 'demfilz-smtp-mailer' ),
					static::label(),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code > 299 ) {
			return new WP_Error(
				'dsmtp_api_error',
				sprintf(
					/* translators: 1: mailer name, 2: HTTP status code, 3: response body. */
					__( '%1$s rejected the message (HTTP %2$d): %3$s', 'demfilz-smtp-mailer' ),
					static::label(),
					$code,
					$this->trim_body( wp_remote_retrieve_body( $response ) )
				),
				array( 'status' => $code )
			);
		}

		return $response;
	}

	/**
	 * Shorten an API response body for an error message.
	 *
	 * @param string $body Response body.
	 * @return string
	 */
	protected function trim_body( $body ) {
		$body = trim( wp_strip_all_tags( (string) $body ) );

		if ( strlen( $body ) > 500 ) {
			$body = substr( $body, 0, 500 ) . '...';
		}

		return '' === $body ? __( 'no details returned', 'demfilz-smtp-mailer' ) : $body;
	}

	/**
	 * Decode a JSON response body.
	 *
	 * @param array $response HTTP response.
	 * @return array
	 */
	protected function decode( $response ) {
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Turn PHPMailer address pairs into a simple list.
	 *
	 * @param array $addresses PHPMailer address arrays.
	 * @return array List of arrays with email and name keys.
	 */
	protected function addresses( $addresses ) {
		$out = array();

		foreach ( (array) $addresses as $address ) {
			if ( empty( $address[0] ) ) {
				continue;
			}

			$out[] = array(
				'email' => $address[0],
				'name'  => isset( $address[1] ) ? $address[1] : '',
			);
		}

		return $out;
	}

	/**
	 * Attachments as name, type and base64 content.
	 *
	 * @param DSMTP_Mail_Catcher $mail Prepared mailer.
	 * @return array|WP_Error
	 */
	protected function attachments( $mail ) {
		$out = array();

		foreach ( $mail->getAttachments() as $attachment ) {
			$content = '';

			if ( ! empty( $attachment[5] ) ) {
				$content = (string) $attachment[0];
			} else {
				$path = (string) $attachment[0];

				if ( ! is_readable( $path ) ) {
					return new WP_Error(
						'dsmtp_attachment',
						sprintf(
							/* translators: %s: file path. */
							__( 'Could not read the attachment %s.', 'demfilz-smtp-mailer' ),
							$path
						)
					);
				}

				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local attachment file, WP_Filesystem is not loaded during mail sending.
				$content = (string) file_get_contents( $path );
			}

			$out[] = array(
				'name'        => (string) $attachment[2],
				'type'        => (string) $attachment[4],
				'disposition' => (string) $attachment[6],
				'cid'         => (string) $attachment[7],
				'content'     => base64_encode( $content ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- API transport encoding.
			);
		}

		return $out;
	}

	/**
	 * Custom headers worth forwarding to a JSON API.
	 *
	 * Transport headers the API sets itself are dropped.
	 *
	 * @param DSMTP_Mail_Catcher $mail Prepared mailer.
	 * @return array Header name to value.
	 */
	protected function custom_headers( $mail ) {
		$skip = array( 'from', 'to', 'cc', 'bcc', 'reply-to', 'subject', 'date', 'message-id', 'content-type', 'mime-version', 'content-transfer-encoding', 'return-path' );
		$out  = array();

		foreach ( $mail->getCustomHeaders() as $header ) {
			$name = isset( $header[0] ) ? trim( (string) $header[0] ) : '';

			if ( '' === $name || in_array( strtolower( $name ), $skip, true ) ) {
				continue;
			}

			$out[ $name ] = isset( $header[1] ) ? (string) $header[1] : '';
		}

		return $out;
	}

	/**
	 * The message body split into HTML and plain text parts.
	 *
	 * @param DSMTP_Mail_Catcher $mail Prepared mailer.
	 * @return array With html and text keys.
	 */
	protected function body_parts( $mail ) {
		$is_html = 'text/html' === strtolower( $mail->ContentType );

		return array(
			'html' => $is_html ? $mail->Body : '',
			'text' => $is_html ? $mail->AltBody : $mail->Body,
		);
	}

	/**
	 * Format one address the way a mail header would.
	 *
	 * @param string $email Address.
	 * @param string $name  Display name.
	 * @return string
	 */
	protected function format_address( $email, $name = '' ) {
		$name = trim( (string) $name );

		if ( '' === $name ) {
			return (string) $email;
		}

		return '"' . str_replace( array( '"', "\r", "\n" ), '', $name ) . '" <' . $email . '>';
	}

	/**
	 * A comma separated address list.
	 *
	 * @param array $addresses PHPMailer address arrays.
	 * @return string
	 */
	protected function address_list( $addresses ) {
		$out = array();

		foreach ( $this->addresses( $addresses ) as $address ) {
			$out[] = $this->format_address( $address['email'], $address['name'] );
		}

		return implode( ', ', $out );
	}

	/**
	 * The raw MIME message, base64 encoded.
	 *
	 * @param DSMTP_Mail_Catcher $mail Prepared mailer.
	 * @return string
	 */
	protected function mime( $mail ) {
		return base64_encode( $mail->getSentMIMEMessage() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- API transport encoding.
	}
}
