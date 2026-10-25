<?php
/**
 * Removes the plugin's data when it is deleted, if that was asked for.
 *
 * @package Demfilz_SMTP_Mailer
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove everything belonging to one site.
 *
 * @return void
 */
function dsmtp_uninstall_site() {
	global $wpdb;

	$settings = get_option( 'dsmtp_settings', array() );

	if ( ! is_array( $settings ) || empty( $settings['delete_data'] ) ) {
		return;
	}

	$table = $wpdb->prefix . 'dsmtp_log';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

	$suppress = $wpdb->prefix . 'dsmtp_suppress';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
	$wpdb->query( "DROP TABLE IF EXISTS {$suppress}" );

	delete_option( 'dsmtp_settings' );
	delete_option( 'dsmtp_tokens' );
	delete_option( 'dsmtp_version' );
	delete_option( 'dsmtp_bounce_state' );

	delete_transient( 'dsmtp_conflict' );
	delete_transient( 'dsmtp_last_failure' );
	delete_transient( 'dsmtp_alert_sent' );

	wp_clear_scheduled_hook( 'dsmtp_retry' );
	wp_clear_scheduled_hook( 'dsmtp_maintenance' );
	wp_clear_scheduled_hook( 'dsmtp_summary' );
	wp_clear_scheduled_hook( 'dsmtp_bounce_poll' );
}

if ( is_multisite() ) {
	$dsmtp_network = get_site_option( 'dsmtp_settings', array() );

	if ( is_array( $dsmtp_network ) && ! empty( $dsmtp_network['delete_data'] ) ) {
		delete_site_option( 'dsmtp_settings' );
		delete_site_option( 'dsmtp_tokens' );
	}

	$dsmtp_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $dsmtp_sites as $dsmtp_site_id ) {
		switch_to_blog( (int) $dsmtp_site_id );
		dsmtp_uninstall_site();
		restore_current_blog();
	}
} else {
	dsmtp_uninstall_site();
}
