<?php
/**
 * Settings storage, defaults and constant overrides.
 *
 * @package Demfilz_SMTP_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central settings access (option: dsmtp_settings).
 */
class DSMTP_Settings {

	const OPTION = 'dsmtp_settings';
	const MASK   = '********';

	/**
	 * Cached settings.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			/* Sender */
			'mailer'                => 'php',
			'from_email'            => '',
			'from_name'             => '',
			'force_from_email'      => 0,
			'force_from_name'       => 0,
			'set_return_path'       => 1,
			'reply_to'              => '',

			/* Reliability */
			'backup_mailer'         => '',
			'retry_enabled'         => 1,
			'retry_max'             => 3,

			/* Logging */
			'log_enabled'           => 1,
			'log_body'              => 0,
			'log_retention_days'    => 30,

			/* Alerts */
			'alerts_enabled'        => 0,
			'alert_email'           => '',
			'alert_webhook'         => '',
			'summary_enabled'       => 0,
			'summary_email'         => '',

			/* Delivery feedback */
			'feedback_token'        => '',
			'read_bounces'          => 0,
			'suppress_on_bounce'    => 1,
			'suppress_on_complaint' => 1,

			/* Email controls, 1 means the email is allowed to go out */
			'controls'              => array(),

			/* Advanced */
			'debug_smtp'            => 0,
			'delete_data'           => 0,

			/* Per provider connection settings */
			'providers'             => array(),
		);
	}

	/**
	 * Whether settings are stored network wide.
	 *
	 * True when the plugin is network activated on multisite, so one set of
	 * credentials serves every site.
	 *
	 * @return bool
	 */
	public static function is_network_mode() {
		if ( ! is_multisite() ) {
			return false;
		}

		$active = get_site_option( 'active_sitewide_plugins', array() );

		return is_array( $active ) && isset( $active[ plugin_basename( DSMTP_FILE ) ] );
	}

	/**
	 * All settings, defaults merged in.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored = self::is_network_mode() ? get_site_option( self::OPTION, array() ) : get_option( self::OPTION, array() );

			if ( ! is_array( $stored ) ) {
				$stored = array();
			}

			self::$cache = array_merge( self::defaults(), $stored );

			if ( ! is_array( self::$cache['providers'] ) ) {
				self::$cache['providers'] = array();
			}

			if ( ! is_array( self::$cache['controls'] ) ) {
				self::$cache['controls'] = array();
			}
		}

		return self::$cache;
	}

	/**
	 * A single setting, with wp-config constants taking priority.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $fallback Value when the setting is unset.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		$constant = 'DSMTP_' . strtoupper( $key );

		if ( defined( $constant ) && ! in_array( $key, array( 'providers', 'controls' ), true ) ) {
			return constant( $constant );
		}

		$all = self::all();

		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}

		return $fallback;
	}

	/**
	 * Whether a setting is pinned by a wp-config constant.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function is_constant( $key ) {
		return defined( 'DSMTP_' . strtoupper( $key ) );
	}

	/**
	 * Persist settings.
	 *
	 * @param array $values Full settings array.
	 * @return void
	 */
	public static function save( array $values ) {
		self::$cache = null;

		if ( self::is_network_mode() ) {
			update_site_option( self::OPTION, $values );
		} else {
			update_option( self::OPTION, $values, false );
		}
	}

	/**
	 * Update a subset of top level settings.
	 *
	 * @param array $values Partial settings.
	 * @return void
	 */
	public static function update( array $values ) {
		self::save( array_merge( self::all(), $values ) );
	}

	/**
	 * Stored connection settings for one provider, decrypted, constants applied.
	 *
	 * @param string $provider_id Provider identifier.
	 * @return array
	 */
	public static function provider_config( $provider_id ) {
		$all    = self::all();
		$stored = isset( $all['providers'][ $provider_id ] ) && is_array( $all['providers'][ $provider_id ] ) ? $all['providers'][ $provider_id ] : array();
		$fields = DSMTP_Providers::fields( $provider_id );
		$config = array();

		foreach ( $fields as $key => $field ) {
			if ( 'info' === $field['type'] ) {
				continue;
			}

			$value = array_key_exists( $key, $stored ) ? $stored[ $key ] : $field['default'];

			if ( ! empty( $field['secret'] ) ) {
				$value = DSMTP_Crypto::decrypt( (string) $value );
			}

			$constant = self::provider_constant( $provider_id, $key );

			if ( defined( $constant ) ) {
				$value = constant( $constant );
			}

			$config[ $key ] = $value;
		}

		return $config;
	}

	/**
	 * Constant name that overrides one provider field.
	 *
	 * @param string $provider_id Provider identifier.
	 * @param string $key         Field key.
	 * @return string
	 */
	public static function provider_constant( $provider_id, $key ) {
		return 'DSMTP_' . strtoupper( str_replace( '-', '_', $provider_id ) ) . '_' . strtoupper( $key );
	}

	/**
	 * Whether a provider field currently holds a stored secret.
	 *
	 * @param string $provider_id Provider identifier.
	 * @param string $key         Field key.
	 * @return bool
	 */
	public static function has_secret( $provider_id, $key ) {
		$all = self::all();

		return ! empty( $all['providers'][ $provider_id ][ $key ] );
	}

	/**
	 * Save one provider's connection settings.
	 *
	 * Secret fields submitted as the mask keep their stored value.
	 *
	 * @param string $provider_id Provider identifier.
	 * @param array  $input       Raw, unslashed input.
	 * @return void
	 */
	public static function save_provider( $provider_id, array $input ) {
		$fields = DSMTP_Providers::fields( $provider_id );
		$all    = self::all();
		$stored = isset( $all['providers'][ $provider_id ] ) && is_array( $all['providers'][ $provider_id ] ) ? $all['providers'][ $provider_id ] : array();

		foreach ( $fields as $key => $field ) {
			if ( 'info' === $field['type'] ) {
				continue;
			}

			$raw = isset( $input[ $key ] ) ? $input[ $key ] : null;

			if ( ! empty( $field['secret'] ) ) {
				if ( ! empty( $input[ $key . '_clear' ] ) ) {
					$stored[ $key ] = '';
					continue;
				}

				if ( null === $raw || self::MASK === $raw || '' === $raw ) {
					continue;
				}

				$stored[ $key ] = DSMTP_Crypto::encrypt( sanitize_text_field( (string) $raw ) );
				continue;
			}

			$stored[ $key ] = self::sanitize_field( $field, $raw );
		}

		$all['providers'][ $provider_id ] = $stored;

		self::save( $all );
	}

	/**
	 * Write a single non secret provider value.
	 *
	 * @param string $provider_id Provider identifier.
	 * @param string $key         Field key.
	 * @param mixed  $value       Value to store.
	 * @return void
	 */
	public static function set_provider_value( $provider_id, $key, $value ) {
		$all = self::all();

		if ( ! isset( $all['providers'][ $provider_id ] ) || ! is_array( $all['providers'][ $provider_id ] ) ) {
			$all['providers'][ $provider_id ] = array();
		}

		$all['providers'][ $provider_id ][ $key ] = $value;

		self::save( $all );
	}

	/**
	 * Sanitize one field value against its declared type.
	 *
	 * @param array $field Field definition.
	 * @param mixed $raw   Raw value.
	 * @return mixed
	 */
	public static function sanitize_field( $field, $raw ) {
		switch ( $field['type'] ) {
			case 'checkbox':
				return empty( $raw ) ? 0 : 1;

			case 'number':
				return (int) $raw;

			case 'email':
				return sanitize_email( (string) $raw );

			case 'select':
				$options = isset( $field['options'] ) ? array_keys( $field['options'] ) : array();
				$value   = sanitize_text_field( (string) $raw );

				return in_array( $value, $options, true ) ? $value : $field['default'];

			case 'textarea':
				return sanitize_textarea_field( (string) $raw );

			default:
				return sanitize_text_field( (string) $raw );
		}
	}

	/**
	 * Reset the in memory cache.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$cache = null;
	}
}
