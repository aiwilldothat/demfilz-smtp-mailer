<?php
/**
 * Scheduled work: retries, log clean up, weekly summary.
 *
 * @package Demfilz_SMTP_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Background tasks.
 */
class DSMTP_Cron {

	const RETRY_HOOK       = 'dsmtp_retry';
	const MAINTENANCE_HOOK = 'dsmtp_maintenance';
	const SUMMARY_HOOK     = 'dsmtp_summary';
	const BOUNCE_HOOK      = 'dsmtp_bounce_poll';

	/**
	 * Hook the schedules and handlers.
	 *
	 * @return void
	 */
	public static function init() {
		self::register_schedule();

		add_action( self::RETRY_HOOK, array( __CLASS__, 'process_retries' ) );
		add_action( self::MAINTENANCE_HOOK, array( __CLASS__, 'maintenance' ) );
		add_action( self::SUMMARY_HOOK, array( 'DSMTP_Alerts', 'send_summary' ) );
		add_action( self::BOUNCE_HOOK, array( 'DSMTP_Bounces', 'poll_all' ) );

		/*
		 * Activation happens after plugins_loaded has already fired, so the events
		 * can end up missing even though the plugin is active. Putting them back
		 * keeps retries working without asking anyone to reactivate. It waits for
		 * init because the schedule labels are translated.
		 */
		add_action( 'init', array( __CLASS__, 'ensure_events' ) );
	}

	/**
	 * Schedule the events if any have gone missing.
	 *
	 * @return void
	 */
	public static function ensure_events() {
		if ( wp_installing() ) {
			return;
		}

		if ( ! wp_next_scheduled( self::RETRY_HOOK ) || ! wp_next_scheduled( self::BOUNCE_HOOK ) ) {
			self::schedule_events();
		}
	}

	/**
	 * Make sure the five minute interval exists.
	 *
	 * @return void
	 */
	public static function register_schedule() {
		if ( ! has_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ) ) {
			add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval -- Delivery retries are time sensitive; the event returns immediately when the queue is empty.
		}
	}

	/**
	 * Add the five minute interval.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function schedules( $schedules ) {
		if ( ! isset( $schedules['dsmtp_five_minutes'] ) ) {
			$schedules['dsmtp_five_minutes'] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every five minutes (Demfilz SMTP Mailer)', 'demfilz-smtp-mailer' ),
			);
		}

		if ( ! isset( $schedules['dsmtp_fifteen_minutes'] ) ) {
			$schedules['dsmtp_fifteen_minutes'] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every fifteen minutes (Demfilz SMTP Mailer)', 'demfilz-smtp-mailer' ),
			);
		}

		return $schedules;
	}

	/**
	 * Put the events on the schedule.
	 *
	 * @return void
	 */
	public static function schedule_events() {
		// Activation runs too late for the filter added on plugins_loaded.
		self::register_schedule();

		if ( ! wp_next_scheduled( self::RETRY_HOOK ) ) {
			wp_schedule_event( time() + ( 5 * MINUTE_IN_SECONDS ), 'dsmtp_five_minutes', self::RETRY_HOOK );
		}

		if ( ! wp_next_scheduled( self::MAINTENANCE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::MAINTENANCE_HOOK );
		}

		if ( ! wp_next_scheduled( self::SUMMARY_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::SUMMARY_HOOK );
		}

		if ( ! wp_next_scheduled( self::BOUNCE_HOOK ) ) {
			wp_schedule_event( time() + ( 15 * MINUTE_IN_SECONDS ), 'dsmtp_fifteen_minutes', self::BOUNCE_HOOK );
		}
	}

	/**
	 * Take the events off the schedule.
	 *
	 * @return void
	 */
	public static function clear_events() {
		wp_clear_scheduled_hook( self::RETRY_HOOK );
		wp_clear_scheduled_hook( self::MAINTENANCE_HOOK );
		wp_clear_scheduled_hook( self::SUMMARY_HOOK );
		wp_clear_scheduled_hook( self::BOUNCE_HOOK );
	}

	/**
	 * Try queued messages again.
	 *
	 * @return void
	 */
	public static function process_retries() {
		if ( ! DSMTP_Settings::get( 'retry_enabled', 1 ) ) {
			return;
		}

		foreach ( DSMTP_Log::due_retries( 20 ) as $row ) {
			// Park the row first, so a fatal in the middle cannot leave it looping.
			DSMTP_Log::update(
				(int) $row['id'],
				array(
					'status'   => DSMTP_Log::STATUS_FAILED,
					'retry_at' => null,
				)
			);

			DSMTP_Mailer::resend( (int) $row['id'], true );
		}
	}

	/**
	 * Daily clean up.
	 *
	 * @return void
	 */
	public static function maintenance() {
		DSMTP_Log::purge( (int) DSMTP_Settings::get( 'log_retention_days', 30 ) );
	}
}
