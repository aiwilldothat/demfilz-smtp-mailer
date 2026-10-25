<?php
/**
 * Switches for the emails WordPress sends by itself.
 *
 * @package Demfilz_SMTP_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns individual WordPress notifications off.
 *
 * Most of these have a core filter that stops the message being built at all.
 * The few that do not are tagged with a header the plugin recognises, and are
 * dropped before they reach a mail server.
 */
class DSMTP_Controls {

	const MARKER = 'X-Dsmtp-Suppress';

	/**
	 * The controls this plugin offers, in display order.
	 *
	 * @return array
	 */
	public static function available() {
		return array(
			'new_user_admin'         => array(
				'label'       => __( 'New user, message to the site administrator', 'demfilz-smtp-mailer' ),
				'description' => __( 'Sent every time somebody registers.', 'demfilz-smtp-mailer' ),
			),
			'new_user'               => array(
				'label'       => __( 'New user, welcome message to the user', 'demfilz-smtp-mailer' ),
				'description' => __( 'Contains the password set link, so most sites should leave this on.', 'demfilz-smtp-mailer' ),
			),
			'password_reset'         => array(
				'label'       => __( 'Password reset link', 'demfilz-smtp-mailer' ),
				'description' => __( 'Turning this off means nobody can reset a forgotten password by email.', 'demfilz-smtp-mailer' ),
			),
			'password_changed'       => array(
				'label'       => __( 'Password was changed, message to the user', 'demfilz-smtp-mailer' ),
				'description' => '',
			),
			'password_changed_admin' => array(
				'label'       => __( 'Password was changed, message to the site administrator', 'demfilz-smtp-mailer' ),
				'description' => '',
			),
			'email_changed'          => array(
				'label'       => __( 'Email address was changed, message to the user', 'demfilz-smtp-mailer' ),
				'description' => '',
			),
			'comment_moderation'     => array(
				'label'       => __( 'Comment is waiting for moderation', 'demfilz-smtp-mailer' ),
				'description' => '',
			),
			'comment_published'      => array(
				'label'       => __( 'Comment was published', 'demfilz-smtp-mailer' ),
				'description' => '',
			),
			'auto_core_update'       => array(
				'label'       => __( 'Automatic WordPress update results', 'demfilz-smtp-mailer' ),
				'description' => '',
			),
			'auto_plugin_theme'      => array(
				'label'       => __( 'Automatic plugin and theme update results', 'demfilz-smtp-mailer' ),
				'description' => '',
			),
			'update_debug'           => array(
				'label'       => __( 'Automatic update debugging messages', 'demfilz-smtp-mailer' ),
				'description' => '',
			),
		);
	}

	/**
	 * Apply whichever controls are switched on.
	 *
	 * @return void
	 */
	public static function init() {
		$controls = (array) DSMTP_Settings::get( 'controls', array() );

		if ( ! empty( $controls['new_user_admin'] ) ) {
			add_filter( 'wp_new_user_notification_email_admin', array( __CLASS__, 'mark' ), 1000 );
		}

		if ( ! empty( $controls['new_user'] ) ) {
			add_filter( 'wp_new_user_notification_email', array( __CLASS__, 'mark' ), 1000 );
		}

		if ( ! empty( $controls['password_reset'] ) ) {
			add_filter( 'send_retrieve_password_email', '__return_false', 1000 );
		}

		if ( ! empty( $controls['password_changed'] ) ) {
			add_filter( 'send_password_change_email', '__return_false', 1000 );
		}

		if ( ! empty( $controls['password_changed_admin'] ) ) {
			add_filter( 'wp_password_change_notification_email', array( __CLASS__, 'mark' ), 1000 );
		}

		if ( ! empty( $controls['email_changed'] ) ) {
			add_filter( 'send_email_change_email', '__return_false', 1000 );
		}

		if ( ! empty( $controls['comment_moderation'] ) ) {
			add_filter( 'notify_moderator', '__return_false', 1000 );
		}

		if ( ! empty( $controls['comment_published'] ) ) {
			add_filter( 'notify_post_author', '__return_false', 1000 );
			add_filter( 'comment_notification_recipients', '__return_empty_array', 1000 );
		}

		if ( ! empty( $controls['auto_core_update'] ) ) {
			add_filter( 'auto_core_update_send_email', '__return_false', 1000 );
			add_filter( 'send_core_update_notification_email', '__return_false', 1000 );
		}

		if ( ! empty( $controls['auto_plugin_theme'] ) ) {
			add_filter( 'auto_plugin_theme_update_email', array( __CLASS__, 'mark' ), 1000 );
		}

		if ( ! empty( $controls['update_debug'] ) ) {
			add_filter( 'automatic_updates_send_debug_email', '__return_false', 1000 );
		}
	}

	/**
	 * Add the suppression header to a core notification email array.
	 *
	 * @param mixed $email Email arguments, usually an array.
	 * @return mixed
	 */
	public static function mark( $email ) {
		if ( ! is_array( $email ) ) {
			return $email;
		}

		$header = self::MARKER . ': 1';

		if ( empty( $email['headers'] ) ) {
			$email['headers'] = array( $header );
		} elseif ( is_array( $email['headers'] ) ) {
			$email['headers'][] = $header;
		} else {
			$email['headers'] = rtrim( (string) $email['headers'], "\r\n" ) . "\r\n" . $header;
		}

		return $email;
	}

	/**
	 * Whether a message carries the suppression header.
	 *
	 * @param array $atts Arguments passed to wp_mail().
	 * @return bool
	 */
	public static function is_suppressed( $atts ) {
		if ( empty( $atts['headers'] ) ) {
			return false;
		}

		$headers = is_array( $atts['headers'] ) ? implode( "\n", array_map( 'strval', $atts['headers'] ) ) : (string) $atts['headers'];

		return false !== stripos( $headers, self::MARKER . ':' );
	}
}
