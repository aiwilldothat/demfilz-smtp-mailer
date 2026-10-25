<?php
/**
 * Privacy exporter and eraser for the email log.
 *
 * @package Demfilz_SMTP_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets the site owner answer data requests about logged mail.
 */
class DSMTP_Privacy {

	/**
	 * Register with the core privacy tools.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'add_privacy_policy_content' ) );
	}

	/**
	 * Register the exporter.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['demfilz-smtp-mailer'] = array(
			'exporter_friendly_name' => __( 'Email log', 'demfilz-smtp-mailer' ),
			'callback'               => array( __CLASS__, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['demfilz-smtp-mailer'] = array(
			'eraser_friendly_name' => __( 'Email log', 'demfilz-smtp-mailer' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Export logged messages sent to an address.
	 *
	 * @param string $email Address.
	 * @param int    $page  Page number, from one.
	 * @return array
	 */
	public static function export( $email, $page = 1 ) {
		$page   = max( 1, (int) $page );
		$number = 50;
		$rows   = DSMTP_Log::find_for_email( $email, $number, ( $page - 1 ) * $number );
		$items  = array();

		foreach ( $rows as $row ) {
			$items[] = array(
				'group_id'    => 'dsmtp-log',
				'group_label' => __( 'Email log', 'demfilz-smtp-mailer' ),
				'item_id'     => 'dsmtp-log-' . (int) $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'Date', 'demfilz-smtp-mailer' ),
						'value' => $row['created_at'],
					),
					array(
						'name'  => __( 'Subject', 'demfilz-smtp-mailer' ),
						'value' => $row['subject'],
					),
					array(
						'name'  => __( 'From', 'demfilz-smtp-mailer' ),
						'value' => $row['from_email'],
					),
					array(
						'name'  => __( 'To', 'demfilz-smtp-mailer' ),
						'value' => $row['to_email'],
					),
					array(
						'name'  => __( 'Status', 'demfilz-smtp-mailer' ),
						'value' => $row['status'],
					),
				),
			);
		}

		return array(
			'data' => $items,
			'done' => count( $rows ) < $number,
		);
	}

	/**
	 * Delete logged messages involving an address.
	 *
	 * @param string $email Address.
	 * @param int    $page  Page number, from one.
	 * @return array
	 */
	public static function erase( $email, $page = 1 ) {
		unset( $page );

		$removed = DSMTP_Log::delete_for_email( $email );

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Suggested privacy policy text.
	 *
	 * @return void
	 */
	public static function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p>' . esc_html__( 'This site keeps a log of the emails it sends, which can include the recipient address, the subject line and, if enabled, the message body. Entries are deleted automatically after the retention period set in the mail settings.', 'demfilz-smtp-mailer' ) . '</p>';

		wp_add_privacy_policy_content( __( 'Demfilz SMTP Mailer', 'demfilz-smtp-mailer' ), $content );
	}
}
