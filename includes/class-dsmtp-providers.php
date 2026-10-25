<?php
/**
 * Provider registry.
 *
 * @package Demfilz_SMTP_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Knows every mailer this plugin can send with.
 */
class DSMTP_Providers {

	/**
	 * Instantiated providers, keyed by id.
	 *
	 * @var array
	 */
	private static $instances = array();

	/**
	 * Provider id to class name.
	 *
	 * @return array
	 */
	public static function classes() {
		$classes = array(
			'php'            => 'DSMTP_Provider_PHP',
			'smtp'           => 'DSMTP_Provider_SMTP',
			'microsoft'      => 'DSMTP_Provider_Microsoft',
			'microsoft-smtp' => 'DSMTP_Provider_Microsoft_SMTP',
			'gmail'          => 'DSMTP_Provider_Gmail',
			'sendgrid'       => 'DSMTP_Provider_SendGrid',
			'mailgun'        => 'DSMTP_Provider_Mailgun',
			'brevo'          => 'DSMTP_Provider_Brevo',
			'postmark'       => 'DSMTP_Provider_Postmark',
			'resend'         => 'DSMTP_Provider_Resend',
		);

		/**
		 * Filters the available mailers.
		 *
		 * Add on classes must extend DSMTP_Provider.
		 *
		 * @since 1.0.0
		 *
		 * @param array $classes Provider id to class name.
		 */
		return apply_filters( 'dsmtp_providers', $classes );
	}

	/**
	 * Whether a provider id is known.
	 *
	 * @param string $provider_id Provider identifier.
	 * @return bool
	 */
	public static function exists( $provider_id ) {
		$classes = self::classes();

		return isset( $classes[ $provider_id ] ) && class_exists( $classes[ $provider_id ] );
	}

	/**
	 * Get a provider instance.
	 *
	 * @param string $provider_id Provider identifier.
	 * @return DSMTP_Provider|null
	 */
	public static function get( $provider_id ) {
		if ( ! self::exists( $provider_id ) ) {
			return null;
		}

		if ( ! isset( self::$instances[ $provider_id ] ) ) {
			$classes                         = self::classes();
			$class                           = $classes[ $provider_id ];
			self::$instances[ $provider_id ] = new $class();
		}

		return self::$instances[ $provider_id ];
	}

	/**
	 * Drop cached instances, so fresh settings are read.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$instances = array();
	}

	/**
	 * The provider currently selected for sending.
	 *
	 * @return DSMTP_Provider
	 */
	public static function active() {
		$provider = self::get( (string) DSMTP_Settings::get( 'mailer', 'php' ) );

		if ( ! $provider ) {
			$provider = self::get( 'php' );
		}

		return $provider;
	}

	/**
	 * The configured backup provider, if any.
	 *
	 * @return DSMTP_Provider|null
	 */
	public static function backup() {
		$id = (string) DSMTP_Settings::get( 'backup_mailer', '' );

		if ( '' === $id || (string) DSMTP_Settings::get( 'mailer', 'php' ) === $id ) {
			return null;
		}

		return self::get( $id );
	}

	/**
	 * Field definitions for a provider, normalised.
	 *
	 * @param string $provider_id Provider identifier.
	 * @return array
	 */
	public static function fields( $provider_id ) {
		$classes = self::classes();

		if ( ! isset( $classes[ $provider_id ] ) || ! class_exists( $classes[ $provider_id ] ) ) {
			return array();
		}

		$fields = call_user_func( array( $classes[ $provider_id ], 'fields' ) );
		$out    = array();

		foreach ( (array) $fields as $key => $field ) {
			$out[ $key ] = array_merge(
				array(
					'label'       => $key,
					'type'        => 'text',
					'default'     => '',
					'secret'      => false,
					'description' => '',
					'placeholder' => '',
					'options'     => array(),
					'required'    => false,
					'depends'     => array(),
				),
				(array) $field
			);
		}

		return $out;
	}

	/**
	 * Provider ids grouped for the settings screen.
	 *
	 * @return array Group label to list of ids.
	 */
	public static function grouped() {
		$groups = array();

		foreach ( array_keys( self::classes() ) as $id ) {
			$provider = self::get( $id );

			if ( ! $provider ) {
				continue;
			}

			$group = $provider::group();

			if ( ! isset( $groups[ $group ] ) ) {
				$groups[ $group ] = array();
			}

			$groups[ $group ][] = $id;
		}

		return $groups;
	}

	/**
	 * Id to label map.
	 *
	 * @return array
	 */
	public static function labels() {
		$labels = array();

		foreach ( array_keys( self::classes() ) as $id ) {
			$provider = self::get( $id );

			if ( $provider ) {
				$labels[ $id ] = $provider::label();
			}
		}

		return $labels;
	}
}
