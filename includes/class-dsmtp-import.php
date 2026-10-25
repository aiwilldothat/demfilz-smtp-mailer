<?php
/**
 * Import settings from other mail plugins.
 *
 * @package Demfilz_SMTP_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the settings other SMTP plugins left behind and reuses them.
 */
class DSMTP_Import {

	/**
	 * Plugins this class understands.
	 *
	 * @return array Source key to label.
	 */
	public static function sources() {
		return array(
			'wp_mail_smtp' => __( 'WP Mail SMTP', 'demfilz-smtp-mailer' ),
			'easy_wp_smtp' => __( 'Easy WP SMTP', 'demfilz-smtp-mailer' ),
			'post_smtp'    => __( 'Post SMTP', 'demfilz-smtp-mailer' ),
		);
	}

	/**
	 * Which of those plugins have settings on this site.
	 *
	 * @return array Source key to label.
	 */
	public static function available() {
		$found = array();

		foreach ( self::sources() as $key => $label ) {
			if ( self::read( $key ) ) {
				$found[ $key ] = $label;
			}
		}

		return $found;
	}

	/**
	 * The stored option for one source.
	 *
	 * @param string $source Source key.
	 * @return array|null
	 */
	private static function read( $source ) {
		$map = array(
			'wp_mail_smtp' => 'wp_mail_smtp',
			'easy_wp_smtp' => 'swpsmtp_options',
			'post_smtp'    => 'postman_options',
		);

		if ( ! isset( $map[ $source ] ) ) {
			return null;
		}

		$value = get_option( $map[ $source ] );

		return is_array( $value ) && $value ? $value : null;
	}

	/**
	 * Import one source into this plugin's settings.
	 *
	 * @param string $source Source key.
	 * @return array|WP_Error Summary of what was imported.
	 */
	public static function run( $source ) {
		$stored = self::read( $source );

		if ( ! $stored ) {
			return new WP_Error( 'dsmtp_import', __( 'No settings were found for that plugin.', 'demfilz-smtp-mailer' ) );
		}

		switch ( $source ) {
			case 'wp_mail_smtp':
				return self::from_wp_mail_smtp( $stored );

			case 'easy_wp_smtp':
				return self::from_easy_wp_smtp( $stored );

			case 'post_smtp':
				return self::from_post_smtp( $stored );
		}

		return new WP_Error( 'dsmtp_import', __( 'That plugin is not supported.', 'demfilz-smtp-mailer' ) );
	}

	/**
	 * Apply the shared parts of an import.
	 *
	 * @param array $sender From address and name.
	 * @param array $smtp   Generic SMTP connection values.
	 * @param bool  $has_password Whether a usable password came across.
	 * @return array
	 */
	private static function apply( $sender, $smtp, $has_password ) {
		$settings = DSMTP_Settings::all();

		if ( ! empty( $sender['from_email'] ) && is_email( $sender['from_email'] ) ) {
			$settings['from_email'] = sanitize_email( $sender['from_email'] );
		}

		if ( ! empty( $sender['from_name'] ) ) {
			$settings['from_name'] = sanitize_text_field( $sender['from_name'] );
		}

		if ( isset( $sender['force_from_email'] ) ) {
			$settings['force_from_email'] = empty( $sender['force_from_email'] ) ? 0 : 1;
		}

		if ( isset( $sender['force_from_name'] ) ) {
			$settings['force_from_name'] = empty( $sender['force_from_name'] ) ? 0 : 1;
		}

		DSMTP_Settings::save( $settings );

		if ( ! empty( $smtp['host'] ) ) {
			DSMTP_Settings::save_provider( 'smtp', $smtp );
			DSMTP_Providers::flush();
		}

		return array(
			'host'         => isset( $smtp['host'] ) ? $smtp['host'] : '',
			'from_email'   => $settings['from_email'],
			'has_password' => (bool) $has_password,
		);
	}

	/**
	 * Import from WP Mail SMTP.
	 *
	 * @param array $stored Stored option.
	 * @return array
	 */
	private static function from_wp_mail_smtp( $stored ) {
		$mail = isset( $stored['mail'] ) && is_array( $stored['mail'] ) ? $stored['mail'] : array();
		$smtp = isset( $stored['smtp'] ) && is_array( $stored['smtp'] ) ? $stored['smtp'] : array();

		$password = isset( $smtp['pass'] ) ? (string) $smtp['pass'] : '';

		return self::apply(
			array(
				'from_email'       => isset( $mail['from_email'] ) ? $mail['from_email'] : '',
				'from_name'        => isset( $mail['from_name'] ) ? $mail['from_name'] : '',
				'force_from_email' => ! empty( $mail['from_email_force'] ),
				'force_from_name'  => ! empty( $mail['from_name_force'] ),
			),
			array(
				'host'       => isset( $smtp['host'] ) ? $smtp['host'] : '',
				'port'       => isset( $smtp['port'] ) ? (int) $smtp['port'] : 587,
				'encryption' => isset( $smtp['encryption'] ) && in_array( $smtp['encryption'], array( 'none', 'ssl', 'tls' ), true ) ? $smtp['encryption'] : 'tls',
				'auth'       => empty( $smtp['auth'] ) ? 0 : 1,
				'autotls'    => empty( $smtp['autotls'] ) ? 0 : 1,
				'username'   => isset( $smtp['user'] ) ? $smtp['user'] : '',
				'password'   => $password,
			),
			'' !== $password
		);
	}

	/**
	 * Import from Easy WP SMTP.
	 *
	 * @param array $stored Stored option.
	 * @return array
	 */
	private static function from_easy_wp_smtp( $stored ) {
		$smtp     = isset( $stored['smtp_settings'] ) && is_array( $stored['smtp_settings'] ) ? $stored['smtp_settings'] : array();
		$password = '';

		if ( ! empty( $smtp['password'] ) ) {
			// Older versions stored the password base64 encoded rather than encrypted.
			$decoded = base64_decode( (string) $smtp['password'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Reading another plugin's stored format.

			if ( is_string( $decoded ) && '' !== $decoded && ctype_print( $decoded ) ) {
				$password = $decoded;
			}
		}

		$encryption = isset( $smtp['type_encryption'] ) ? (string) $smtp['type_encryption'] : 'tls';

		return self::apply(
			array(
				'from_email'       => isset( $stored['from_email_field'] ) ? $stored['from_email_field'] : '',
				'from_name'        => isset( $stored['from_name_field'] ) ? $stored['from_name_field'] : '',
				'force_from_email' => ! empty( $stored['force_from_name_replace'] ),
			),
			array(
				'host'       => isset( $smtp['host'] ) ? $smtp['host'] : '',
				'port'       => isset( $smtp['port'] ) ? (int) $smtp['port'] : 587,
				'encryption' => in_array( $encryption, array( 'none', 'ssl', 'tls' ), true ) ? $encryption : 'tls',
				'auth'       => empty( $smtp['autentication'] ) || 'no' === $smtp['autentication'] ? 0 : 1,
				'autotls'    => 1,
				'username'   => isset( $smtp['username'] ) ? $smtp['username'] : '',
				'password'   => $password,
			),
			'' !== $password
		);
	}

	/**
	 * Import from Post SMTP.
	 *
	 * @param array $stored Stored option.
	 * @return array
	 */
	private static function from_post_smtp( $stored ) {
		$password   = isset( $stored['basic_auth_password'] ) ? (string) $stored['basic_auth_password'] : '';
		$encryption = isset( $stored['enc_type'] ) ? (string) $stored['enc_type'] : 'tls';

		return self::apply(
			array(
				'from_email' => isset( $stored['sender_email'] ) ? $stored['sender_email'] : '',
				'from_name'  => isset( $stored['sender_name'] ) ? $stored['sender_name'] : '',
			),
			array(
				'host'       => isset( $stored['hostname'] ) ? $stored['hostname'] : '',
				'port'       => isset( $stored['port'] ) ? (int) $stored['port'] : 587,
				'encryption' => in_array( $encryption, array( 'none', 'ssl', 'tls' ), true ) ? $encryption : 'tls',
				'auth'       => empty( $stored['auth_type'] ) || 'none' === $stored['auth_type'] ? 0 : 1,
				'autotls'    => 1,
				'username'   => isset( $stored['basic_auth_username'] ) ? $stored['basic_auth_username'] : '',
				'password'   => $password,
			),
			'' !== $password
		);
	}
}
