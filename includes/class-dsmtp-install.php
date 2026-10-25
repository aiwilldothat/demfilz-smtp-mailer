<?php
/**
 * Activation, upgrade and deactivation.
 *
 * @package Demfilz_SMTP_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sets the plugin up and keeps the schema current.
 */
class DSMTP_Install {

	const VERSION_OPTION = 'dsmtp_version';

	/**
	 * Run on activation.
	 *
	 * @return void
	 */
	public static function activate() {
		DSMTP_Log::create_table();
		DSMTP_Suppression::create_table();

		$settings = DSMTP_Settings::all();

		if ( '' === (string) $settings['from_email'] ) {
			$settings['from_email'] = (string) get_option( 'admin_email' );
			$settings['from_name']  = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );

			DSMTP_Settings::save( $settings );
		}

		DSMTP_Cron::schedule_events();

		update_option( self::VERSION_OPTION, DSMTP_VERSION, false );
	}

	/**
	 * Run on deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		DSMTP_Cron::clear_events();
	}

	/**
	 * Bring an existing install up to date.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$installed = (string) get_option( self::VERSION_OPTION, '' );

		if ( DSMTP_VERSION === $installed ) {
			return;
		}

		DSMTP_Log::create_table();
		DSMTP_Suppression::create_table();

		// Scheduling translates its labels, so it waits for init.
		add_action( 'init', array( 'DSMTP_Cron', 'schedule_events' ) );

		update_option( self::VERSION_OPTION, DSMTP_VERSION, false );
	}
}
