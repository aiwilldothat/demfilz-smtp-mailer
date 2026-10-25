<?php
/**
 * The admin screens.
 *
 * @package Demfilz_SMTP_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menus, forms and notices.
 */
class DSMTP_Admin {

	/**
	 * Hook the admin up.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );

		if ( DSMTP_Settings::is_network_mode() ) {
			add_action( 'network_admin_menu', array( __CLASS__, 'menu' ) );
		}

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'network_admin_notices', array( __CLASS__, 'notices' ) );

		add_action( 'admin_post_dsmtp_save_settings', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_dsmtp_connect', array( __CLASS__, 'handle_connect' ) );
		add_action( 'admin_post_dsmtp_disconnect', array( __CLASS__, 'handle_disconnect' ) );
		add_action( 'admin_post_dsmtp_test', array( __CLASS__, 'handle_test' ) );
		add_action( 'admin_post_dsmtp_import', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_dsmtp_log_action', array( __CLASS__, 'handle_log_action' ) );
		add_action( 'admin_post_dsmtp_export_log', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_dsmtp_suppress_action', array( __CLASS__, 'handle_suppress_action' ) );
		add_action( 'admin_post_dsmtp_export_suppress', array( __CLASS__, 'handle_export_suppress' ) );
		add_action( 'admin_post_dsmtp_feedback_token', array( __CLASS__, 'handle_feedback_token' ) );
		add_action( 'admin_post_dsmtp_poll_bounces', array( __CLASS__, 'handle_poll_bounces' ) );
	}

	/**
	 * The capability needed to manage the plugin.
	 *
	 * @return string
	 */
	public static function capability() {
		return DSMTP_Settings::is_network_mode() ? 'manage_network_options' : 'manage_options';
	}

	/**
	 * Register the menu.
	 *
	 * @return void
	 */
	public static function menu() {
		add_menu_page(
			__( 'Mail', 'demfilz-smtp-mailer' ),
			__( 'Mail', 'demfilz-smtp-mailer' ),
			self::capability(),
			'dsmtp-settings',
			array( __CLASS__, 'render_settings' ),
			'dashicons-email-alt',
			81
		);

		add_submenu_page(
			'dsmtp-settings',
			__( 'Mail settings', 'demfilz-smtp-mailer' ),
			__( 'Settings', 'demfilz-smtp-mailer' ),
			self::capability(),
			'dsmtp-settings',
			array( __CLASS__, 'render_settings' )
		);

		add_submenu_page(
			'dsmtp-settings',
			__( 'Email log', 'demfilz-smtp-mailer' ),
			__( 'Email log', 'demfilz-smtp-mailer' ),
			self::capability(),
			'dsmtp-log',
			array( __CLASS__, 'render_log' )
		);

		add_submenu_page(
			'dsmtp-settings',
			__( 'Suppression list', 'demfilz-smtp-mailer' ),
			__( 'Suppression list', 'demfilz-smtp-mailer' ),
			self::capability(),
			'dsmtp-suppress',
			array( __CLASS__, 'render_suppress' )
		);

		add_submenu_page(
			'dsmtp-settings',
			__( 'Mail tools', 'demfilz-smtp-mailer' ),
			__( 'Tools', 'demfilz-smtp-mailer' ),
			self::capability(),
			'dsmtp-tools',
			array( __CLASS__, 'render_tools' )
		);
	}

	/**
	 * Whether the current screen belongs to this plugin.
	 *
	 * @return bool
	 */
	private static function is_our_screen() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the current screen only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return in_array( $page, array( 'dsmtp-settings', 'dsmtp-log', 'dsmtp-tools', 'dsmtp-suppress' ), true );
	}

	/**
	 * Load the admin stylesheet and script.
	 *
	 * @return void
	 */
	public static function assets() {
		if ( ! self::is_our_screen() ) {
			return;
		}

		wp_enqueue_style( 'dsmtp-admin', DSMTP_URL . 'assets/dsmtp-admin.css', array(), DSMTP_VERSION );
		wp_enqueue_script( 'dsmtp-admin', DSMTP_URL . 'assets/dsmtp-admin.js', array(), DSMTP_VERSION, true );

		wp_localize_script(
			'dsmtp-admin',
			'dsmtpAdmin',
			array(
				'confirmClear'  => __( 'Delete every entry in the email log?', 'demfilz-smtp-mailer' ),
				'confirmDelete' => __( 'Delete the selected entries?', 'demfilz-smtp-mailer' ),
				'confirmToken'  => __( 'Create a new webhook secret? The current webhook addresses stop working until you update them at each service.', 'demfilz-smtp-mailer' ),
				'copied'        => __( 'Copied', 'demfilz-smtp-mailer' ),
				'copy'          => __( 'Copy', 'demfilz-smtp-mailer' ),
			)
		);
	}

	/**
	 * Admin notices for results, failures and conflicts.
	 *
	 * @return void
	 */
	public static function notices() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}

		if ( self::is_our_screen() ) {
			self::result_notice();
		}

		$failure = DSMTP_Alerts::last_failure();

		if ( $failure && ! empty( $failure['message'] ) ) {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s</p><p><a href="%s">%s</a></p></div>',
				esc_html__( 'Email is failing on this site.', 'demfilz-smtp-mailer' ),
				esc_html( $failure['message'] ),
				esc_url( admin_url( 'admin.php?page=dsmtp-log' ) ),
				esc_html__( 'Open the email log', 'demfilz-smtp-mailer' )
			);
		}

		$conflict = DSMTP_Mailer::conflict();

		if ( '' !== $conflict && self::is_our_screen() ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: PHP class name of the other mail plugin. */
						__( 'Another mail plugin is also taking over sending on this site (%s). Deactivate one of them, otherwise the settings here may be ignored.', 'demfilz-smtp-mailer' ),
						$conflict
					)
				)
			);
		}
	}

	/**
	 * Show the outcome of the last action.
	 *
	 * @return void
	 */
	private static function result_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading a redirect result code, no action is taken.
		$notice = isset( $_GET['dsmtp_notice'] ) ? sanitize_key( wp_unslash( $_GET['dsmtp_notice'] ) ) : '';
		$oauth  = isset( $_GET['dsmtp_oauth'] ) ? sanitize_key( wp_unslash( $_GET['dsmtp_oauth'] ) ) : '';
		$mailer = isset( $_GET['dsmtp_mailer'] ) ? sanitize_key( wp_unslash( $_GET['dsmtp_mailer'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$messages = array(
			'saved'        => array( 'success', __( 'Settings saved.', 'demfilz-smtp-mailer' ) ),
			'imported'     => array( 'success', __( 'Settings imported. Check them over, then send a test email.', 'demfilz-smtp-mailer' ) ),
			'import_pass'  => array( 'warning', __( 'Settings imported, but the password could not be read. Enter it again below.', 'demfilz-smtp-mailer' ) ),
			'disconnected' => array( 'success', __( 'Disconnected.', 'demfilz-smtp-mailer' ) ),
			'deleted'      => array( 'success', __( 'Log entries deleted.', 'demfilz-smtp-mailer' ) ),
			'cleared'      => array( 'success', __( 'The email log is empty.', 'demfilz-smtp-mailer' ) ),
			'resent'       => array( 'success', __( 'The message was sent again.', 'demfilz-smtp-mailer' ) ),
			'suppressed'   => array( 'success', __( 'Address added to the suppression list.', 'demfilz-smtp-mailer' ) ),
			'unsuppressed' => array( 'success', __( 'Addresses removed from the suppression list. They can be emailed again.', 'demfilz-smtp-mailer' ) ),
			'token'        => array( 'success', __( 'New webhook secret created. Update the webhook address at every service that posts events here.', 'demfilz-smtp-mailer' ) ),
			'reconnect'    => array( 'warning', __( 'Settings saved. Reading bounce reports needs extra permission from the mailbox, so press Disconnect and then Connect again on the mailer.', 'demfilz-smtp-mailer' ) ),
			'error'        => array( 'error', __( 'That did not work. Check the details and try again.', 'demfilz-smtp-mailer' ) ),
		);

		$polled = get_transient( 'dsmtp_poll_result_' . get_current_user_id() );

		if ( is_array( $polled ) ) {
			delete_transient( 'dsmtp_poll_result_' . get_current_user_id() );

			if ( ! empty( $polled['error'] ) ) {
				$messages['polled'] = array( 'error', $polled['error'] );
			} else {
				$messages['polled'] = array(
					'success',
					sprintf(
						/* translators: 1: number of reports read, 2: number of bounces recorded. */
						__( 'Checked the mailbox: %1$d new delivery reports, %2$d bounces recorded.', 'demfilz-smtp-mailer' ),
						(int) $polled['checked'],
						(int) $polled['bounces']
					),
				);
			}
		}

		if ( isset( $messages[ $notice ] ) ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $messages[ $notice ][0] ),
				esc_html( $messages[ $notice ][1] )
			);
		}

		if ( '' === $oauth ) {
			return;
		}

		if ( 'connected' === $oauth ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Connected. Send a test email to confirm delivery.', 'demfilz-smtp-mailer' )
			);

			return;
		}

		$reason = '' !== $mailer ? DSMTP_OAuth::last_error( $mailer ) : '';

		$oauth_messages = array(
			'state'      => __( 'The sign in could not be verified. Start again from this page.', 'demfilz-smtp-mailer' ),
			'permission' => __( 'That account is not allowed to change these settings.', 'demfilz-smtp-mailer' ),
			'denied'     => __( 'The sign in was cancelled or refused.', 'demfilz-smtp-mailer' ),
			'nocode'     => __( 'The mail provider did not return an authorisation code.', 'demfilz-smtp-mailer' ),
			'failed'     => __( 'The tokens could not be collected.', 'demfilz-smtp-mailer' ),
		);

		if ( isset( $oauth_messages[ $oauth ] ) ) {
			printf(
				'<div class="notice notice-error"><p>%s %s</p></div>',
				esc_html( $oauth_messages[ $oauth ] ),
				esc_html( $reason )
			);
		}
	}

	/**
	 * Send the user back with a result code.
	 *
	 * @param string $page   Page slug.
	 * @param string $notice Result code.
	 * @param array  $extra  Extra query arguments.
	 * @return void
	 */
	private static function redirect( $page, $notice, $extra = array() ) {
		$url = add_query_arg(
			array_merge(
				array(
					'page'         => $page,
					'dsmtp_notice' => $notice,
				),
				$extra
			),
			DSMTP_Settings::is_network_mode() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Check the nonce and capability of a posted form.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'demfilz-smtp-mailer' ), 403 );
		}

		check_admin_referer( $action );
	}

	// Form handlers.

	/**
	 * Save the settings form.
	 *
	 * @return void
	 */
	public static function handle_save() {
		self::guard( 'dsmtp_save_settings' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified in guard(); every value is sanitized by type below and in DSMTP_Settings.
		$input    = isset( $_POST['dsmtp'] ) ? map_deep( wp_unslash( $_POST['dsmtp'] ), 'trim' ) : array();
		$input    = is_array( $input ) ? $input : array();
		$settings = DSMTP_Settings::all();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
		$tab = isset( $_POST['dsmtp_tab'] ) ? sanitize_key( wp_unslash( $_POST['dsmtp_tab'] ) ) : 'general';

		if ( 'general' === $tab ) {
			if ( isset( $input['mailer'] ) && DSMTP_Providers::exists( $input['mailer'] ) ) {
				$settings['mailer'] = sanitize_key( $input['mailer'] );
			}

			$settings['from_email']       = sanitize_email( isset( $input['from_email'] ) ? $input['from_email'] : '' );
			$settings['from_name']        = sanitize_text_field( isset( $input['from_name'] ) ? $input['from_name'] : '' );
			$settings['reply_to']         = sanitize_email( isset( $input['reply_to'] ) ? $input['reply_to'] : '' );
			$settings['force_from_email'] = empty( $input['force_from_email'] ) ? 0 : 1;
			$settings['force_from_name']  = empty( $input['force_from_name'] ) ? 0 : 1;
			$settings['set_return_path']  = empty( $input['set_return_path'] ) ? 0 : 1;

			$backup = isset( $input['backup_mailer'] ) ? sanitize_key( $input['backup_mailer'] ) : '';

			$settings['backup_mailer'] = DSMTP_Providers::exists( $backup ) && 'php' !== $backup ? $backup : '';

			DSMTP_Settings::save( $settings );

			if ( isset( $input['providers'] ) && is_array( $input['providers'] ) ) {
				foreach ( $input['providers'] as $provider_id => $values ) {
					$provider_id = sanitize_key( $provider_id );

					if ( DSMTP_Providers::exists( $provider_id ) && is_array( $values ) ) {
						DSMTP_Settings::save_provider( $provider_id, $values );
					}
				}
			}

			DSMTP_Providers::flush();
			DSMTP_Mailer::clear_conflict();
		}

		if ( 'log' === $tab ) {
			$settings['log_enabled']        = empty( $input['log_enabled'] ) ? 0 : 1;
			$settings['log_body']           = empty( $input['log_body'] ) ? 0 : 1;
			$settings['log_retention_days'] = max( 0, (int) ( isset( $input['log_retention_days'] ) ? $input['log_retention_days'] : 30 ) );
			$settings['retry_enabled']      = empty( $input['retry_enabled'] ) ? 0 : 1;
			$settings['retry_max']          = max( 0, min( 10, (int) ( isset( $input['retry_max'] ) ? $input['retry_max'] : 3 ) ) );
			$settings['alerts_enabled']     = empty( $input['alerts_enabled'] ) ? 0 : 1;
			$settings['alert_email']        = sanitize_email( isset( $input['alert_email'] ) ? $input['alert_email'] : '' );
			$settings['alert_webhook']      = esc_url_raw( isset( $input['alert_webhook'] ) ? $input['alert_webhook'] : '' );
			$settings['summary_enabled']    = empty( $input['summary_enabled'] ) ? 0 : 1;
			$settings['summary_email']      = sanitize_email( isset( $input['summary_email'] ) ? $input['summary_email'] : '' );

			$was_reading                       = ! empty( $settings['read_bounces'] );
			$settings['read_bounces']          = empty( $input['read_bounces'] ) ? 0 : 1;
			$settings['suppress_on_bounce']    = empty( $input['suppress_on_bounce'] ) ? 0 : 1;
			$settings['suppress_on_complaint'] = empty( $input['suppress_on_complaint'] ) ? 0 : 1;

			DSMTP_Settings::save( $settings );
			DSMTP_Providers::flush();

			// Turning reading on widens the OAuth scope, which only a fresh sign in can grant.
			if ( ! $was_reading && $settings['read_bounces'] ) {
				foreach ( DSMTP_Bounces::providers() as $provider_id ) {
					if ( DSMTP_OAuth::is_connected( $provider_id ) && ! DSMTP_Bounces::has_read_scope( $provider_id ) ) {
						self::redirect( 'dsmtp-settings', 'reconnect', array( 'tab' => $tab ) );
					}
				}
			}
		}

		if ( 'controls' === $tab ) {
			$controls = array();

			foreach ( array_keys( DSMTP_Controls::available() ) as $key ) {
				$controls[ $key ] = empty( $input['controls'][ $key ] ) ? 0 : 1;
			}

			$settings['controls'] = $controls;

			DSMTP_Settings::save( $settings );
		}

		if ( 'advanced' === $tab ) {
			$settings['debug_smtp']  = empty( $input['debug_smtp'] ) ? 0 : 1;
			$settings['delete_data'] = empty( $input['delete_data'] ) ? 0 : 1;

			DSMTP_Settings::save( $settings );
		}

		self::redirect( 'dsmtp-settings', 'saved', array( 'tab' => $tab ) );
	}

	/**
	 * Start an OAuth connection.
	 *
	 * @return void
	 */
	public static function handle_connect() {
		self::guard( 'dsmtp_connect' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified in guard().
		$provider_id = isset( $_REQUEST['provider'] ) ? sanitize_key( wp_unslash( $_REQUEST['provider'] ) ) : '';
		$url         = DSMTP_OAuth::authorize_url( $provider_id );

		if ( is_wp_error( $url ) ) {
			self::redirect( 'dsmtp-settings', 'error' );
		}

		// Leaving the site for the mail provider's own sign in page.
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- The destination is the mail provider chosen in the settings.
		exit;
	}

	/**
	 * Forget the tokens for a provider.
	 *
	 * @return void
	 */
	public static function handle_disconnect() {
		self::guard( 'dsmtp_disconnect' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified in guard().
		$provider_id = isset( $_REQUEST['provider'] ) ? sanitize_key( wp_unslash( $_REQUEST['provider'] ) ) : '';

		if ( DSMTP_Providers::exists( $provider_id ) ) {
			DSMTP_OAuth::disconnect( $provider_id );
		}

		self::redirect( 'dsmtp-settings', 'disconnected' );
	}

	/**
	 * Send a test email.
	 *
	 * @return void
	 */
	public static function handle_test() {
		self::guard( 'dsmtp_test' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in guard().
		$to   = isset( $_POST['dsmtp_to'] ) ? sanitize_email( wp_unslash( $_POST['dsmtp_to'] ) ) : '';
		$html = ! empty( $_POST['dsmtp_html'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! is_email( $to ) ) {
			$to = (string) get_option( 'admin_email' );
		}

		$result = DSMTP_Mailer::send_test( $to, $html );

		set_transient( 'dsmtp_test_result_' . get_current_user_id(), $result, 10 * MINUTE_IN_SECONDS );

		self::redirect( 'dsmtp-tools', $result['sent'] ? 'saved' : 'error' );
	}

	/**
	 * Import from another plugin.
	 *
	 * @return void
	 */
	public static function handle_import() {
		self::guard( 'dsmtp_import' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
		$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		$result = DSMTP_Import::run( $source );

		if ( is_wp_error( $result ) ) {
			self::redirect( 'dsmtp-tools', 'error' );
		}

		self::redirect( 'dsmtp-settings', empty( $result['has_password'] ) ? 'import_pass' : 'imported' );
	}

	/**
	 * Delete, clear or resend log entries.
	 *
	 * @return void
	 */
	public static function handle_log_action() {
		self::guard( 'dsmtp_log_action' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in guard().
		$action = isset( $_POST['dsmtp_do'] ) ? sanitize_key( wp_unslash( $_POST['dsmtp_do'] ) ) : '';
		$ids    = isset( $_POST['dsmtp_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['dsmtp_ids'] ) ) : array();
		$id     = isset( $_POST['dsmtp_id'] ) ? absint( wp_unslash( $_POST['dsmtp_id'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		switch ( $action ) {
			case 'delete':
				DSMTP_Log::delete( $ids );
				self::redirect( 'dsmtp-log', 'deleted' );
				break;

			case 'clear':
				DSMTP_Log::delete_all();
				self::redirect( 'dsmtp-log', 'cleared' );
				break;

			case 'resend':
				$result = DSMTP_Mailer::resend( $id );

				self::redirect( 'dsmtp-log', is_wp_error( $result ) ? 'error' : 'resent' );
				break;
		}

		self::redirect( 'dsmtp-log', 'error' );
	}

	/**
	 * Download the log as CSV.
	 *
	 * @return void
	 */
	public static function handle_export() {
		self::guard( 'dsmtp_export_log' );

		$rows = DSMTP_Log::query(
			array(
				'per_page' => 5000,
				'page'     => 1,
			)
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=email-log-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Writing the response body, not the file system.

		fputcsv( $out, array( 'id', 'date', 'status', 'mailer', 'from', 'to', 'subject', 'error', 'source' ) );

		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				array(
					$row['id'],
					$row['created_at'],
					$row['status'],
					$row['mailer'],
					self::csv_safe( $row['from_email'] ),
					self::csv_safe( $row['to_email'] ),
					self::csv_safe( $row['subject'] ),
					self::csv_safe( $row['error'] ),
					$row['source'],
				)
			);
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the response body.
		exit;
	}

	/**
	 * Stop a spreadsheet treating a logged value as a formula.
	 *
	 * @param string $value Cell value.
	 * @return string
	 */
	private static function csv_safe( $value ) {
		$value = (string) $value;

		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}

		return $value;
	}

	// Screens.

	/**
	 * The settings screen.
	 *
	 * @return void
	 */
	public static function render_settings() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}

		require DSMTP_DIR . 'admin/views/settings.php';
	}

	/**
	 * The email log screen.
	 *
	 * @return void
	 */
	public static function render_log() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read only navigation.
		$view = isset( $_GET['view'] ) ? absint( wp_unslash( $_GET['view'] ) ) : 0;

		if ( $view ) {
			$entry = DSMTP_Log::get( $view );

			require DSMTP_DIR . 'admin/views/log-entry.php';

			return;
		}

		require DSMTP_DIR . 'admin/views/log.php';
	}

	/**
	 * Add to or remove from the suppression list.
	 *
	 * @return void
	 */
	public static function handle_suppress_action() {
		self::guard( 'dsmtp_suppress_action' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in guard().
		$action = isset( $_POST['dsmtp_do'] ) ? sanitize_key( wp_unslash( $_POST['dsmtp_do'] ) ) : '';
		$ids    = isset( $_POST['dsmtp_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['dsmtp_ids'] ) ) : array();
		$email  = isset( $_POST['dsmtp_email'] ) ? sanitize_email( wp_unslash( $_POST['dsmtp_email'] ) ) : '';
		$note   = isset( $_POST['dsmtp_note'] ) ? sanitize_text_field( wp_unslash( $_POST['dsmtp_note'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( 'add' === $action ) {
			$added = DSMTP_Suppression::add( $email, DSMTP_Suppression::REASON_MANUAL, $note, 'manual' );

			self::redirect( 'dsmtp-suppress', $added ? 'suppressed' : 'error' );
		}

		if ( 'remove' === $action ) {
			DSMTP_Suppression::remove( $ids );

			self::redirect( 'dsmtp-suppress', 'unsuppressed' );
		}

		self::redirect( 'dsmtp-suppress', 'error' );
	}

	/**
	 * Download the suppression list as CSV.
	 *
	 * @return void
	 */
	public static function handle_export_suppress() {
		self::guard( 'dsmtp_export_suppress' );

		$rows = DSMTP_Suppression::query(
			array(
				'per_page' => 10000,
				'page'     => 1,
			)
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=suppression-list-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Writing the response body, not the file system.

		fputcsv( $out, array( 'email', 'reason', 'detail', 'source', 'added' ) );

		foreach ( $rows as $row ) {
			fputcsv( $out, array( self::csv_safe( $row['email'] ), $row['reason'], self::csv_safe( $row['detail'] ), $row['source'], $row['created_at'] ) );
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the response body.
		exit;
	}

	/**
	 * Replace the webhook secret.
	 *
	 * @return void
	 */
	public static function handle_feedback_token() {
		self::guard( 'dsmtp_feedback_token' );

		DSMTP_Events::regenerate_token();

		self::redirect( 'dsmtp-settings', 'token', array( 'tab' => 'log' ) );
	}

	/**
	 * Read bounce reports from the connected mailboxes now.
	 *
	 * @return void
	 */
	public static function handle_poll_bounces() {
		self::guard( 'dsmtp_poll_bounces' );

		$checked = 0;
		$bounces = 0;
		$error   = '';

		foreach ( DSMTP_Bounces::providers() as $provider_id ) {
			if ( ! DSMTP_OAuth::is_connected( $provider_id ) ) {
				continue;
			}

			$result = DSMTP_Bounces::poll( $provider_id );

			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
				continue;
			}

			$checked += (int) $result['checked'];
			$bounces += (int) $result['bounces'];
		}

		set_transient(
			'dsmtp_poll_result_' . get_current_user_id(),
			array(
				'checked' => $checked,
				'bounces' => $bounces,
				'error'   => $error,
			),
			5 * MINUTE_IN_SECONDS
		);

		self::redirect( 'dsmtp-tools', 'polled' );
	}

	/**
	 * The suppression list screen.
	 *
	 * @return void
	 */
	public static function render_suppress() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}

		require DSMTP_DIR . 'admin/views/suppression.php';
	}

	/**
	 * The tools screen.
	 *
	 * @return void
	 */
	public static function render_tools() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}

		require DSMTP_DIR . 'admin/views/tools.php';
	}

	/**
	 * The URL of a plugin admin page.
	 *
	 * @param string $page Page slug.
	 * @param array  $args Extra query arguments.
	 * @return string
	 */
	public static function page_url( $page, $args = array() ) {
		$base = DSMTP_Settings::is_network_mode() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' );

		return add_query_arg( array_merge( array( 'page' => $page ), $args ), $base );
	}

	/**
	 * The admin-post endpoint URL.
	 *
	 * @return string
	 */
	public static function post_url() {
		return admin_url( 'admin-post.php' );
	}

	// Rendering helpers.

	/**
	 * The connection panel for one mailer.
	 *
	 * @param string $provider_id Provider identifier.
	 * @param string $active_id   Currently selected mailer.
	 * @return void
	 */
	public static function provider_panel( $provider_id, $active_id ) {
		$provider = DSMTP_Providers::get( $provider_id );

		if ( ! $provider ) {
			return;
		}

		$fields = DSMTP_Providers::fields( $provider_id );
		$config = DSMTP_Settings::provider_config( $provider_id );
		$notes  = $provider::setup_notes();
		$oauth  = method_exists( $provider, 'oauth_config' );

		printf(
			'<div class="dsmtp-panel" data-dsmtp-panel="%s"%s>',
			esc_attr( $provider_id ),
			$provider_id === $active_id ? '' : ' hidden'
		);

		printf( '<h3>%s</h3>', esc_html( $provider::label() ) );

		if ( '' !== $notes ) {
			printf( '<p class="dsmtp-notes">%s</p>', esc_html( $notes ) );
		}

		if ( $oauth ) {
			self::oauth_box( $provider );
		}

		if ( $fields ) {
			echo '<table class="form-table" role="presentation"><tbody>';

			foreach ( $fields as $key => $field ) {
				self::field_row( $provider_id, $key, $field, isset( $config[ $key ] ) ? $config[ $key ] : $field['default'] );
			}

			echo '</tbody></table>';
		}

		echo '</div>';
	}

	/**
	 * The connect and disconnect controls.
	 *
	 * @param DSMTP_Provider $provider Provider instance.
	 * @return void
	 */
	private static function oauth_box( $provider ) {
		$provider_id = $provider::id();
		$redirect    = DSMTP_OAuth::redirect_uri( $provider_id );
		$connected   = DSMTP_OAuth::is_connected( $provider_id );
		$tokens      = DSMTP_OAuth::tokens( $provider_id );
		$app_only    = method_exists( $provider, 'is_app_only' ) && $provider->is_app_only();

		echo '<div class="dsmtp-oauth">';

		printf(
			'<p><strong>%s</strong><br><code class="dsmtp-copy" data-dsmtp-copy="%s">%s</code> <button type="button" class="button button-small dsmtp-copy-button" data-dsmtp-target="%s">%s</button></p>',
			esc_html__( 'Redirect URI to register with the mail provider', 'demfilz-smtp-mailer' ),
			esc_attr( $redirect ),
			esc_html( $redirect ),
			esc_attr( $redirect ),
			esc_html__( 'Copy', 'demfilz-smtp-mailer' )
		);

		if ( $app_only ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Application permissions do not need a sign in. Grant admin consent to the application in your directory, then send a test email.', 'demfilz-smtp-mailer' )
			);

			if ( method_exists( $provider, 'admin_consent_url' ) && '' !== (string) $provider->option( 'client_id', '' ) ) {
				printf(
					'<p><a class="button" href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
					esc_url( $provider->admin_consent_url() ),
					esc_html__( 'Grant admin consent', 'demfilz-smtp-mailer' )
				);
			}

			echo '</div>';

			return;
		}

		if ( $connected ) {
			$account = ! empty( $tokens['account'] ) ? $tokens['account'] : '';

			printf(
				'<p class="dsmtp-connected"><span class="dashicons dashicons-yes-alt"></span> %s</p>',
				esc_html(
					'' !== $account
						? sprintf(
							/* translators: %s: mailbox address. */
							__( 'Connected as %s', 'demfilz-smtp-mailer' ),
							$account
						)
						: __( 'Connected', 'demfilz-smtp-mailer' )
				)
			);

			printf(
				'<p><a class="button" href="%s">%s</a></p>',
				esc_url(
					wp_nonce_url(
						add_query_arg(
							array(
								'action'   => 'dsmtp_disconnect',
								'provider' => $provider_id,
							),
							self::post_url()
						),
						'dsmtp_disconnect'
					)
				),
				esc_html__( 'Disconnect', 'demfilz-smtp-mailer' )
			);
		} else {
			printf(
				'<p><a class="button button-secondary" href="%s">%s</a> <span class="description">%s</span></p>',
				esc_url(
					wp_nonce_url(
						add_query_arg(
							array(
								'action'   => 'dsmtp_connect',
								'provider' => $provider_id,
							),
							self::post_url()
						),
						'dsmtp_connect'
					)
				),
				esc_html__( 'Connect', 'demfilz-smtp-mailer' ),
				esc_html__( 'Save the application details first.', 'demfilz-smtp-mailer' )
			);
		}

		echo '</div>';
	}

	/**
	 * One connection field.
	 *
	 * @param string $provider_id Provider identifier.
	 * @param string $key         Field key.
	 * @param array  $field       Field definition.
	 * @param mixed  $value       Current value.
	 * @return void
	 */
	private static function field_row( $provider_id, $key, $field, $value ) {
		$name     = 'dsmtp[providers][' . $provider_id . '][' . $key . ']';
		$id       = 'dsmtp-' . $provider_id . '-' . $key;
		$constant = DSMTP_Settings::provider_constant( $provider_id, $key );
		$pinned   = defined( $constant );

		if ( ! empty( $field['depends'] ) ) {
			printf( '<tr data-dsmtp-depends="%s">', esc_attr( (string) wp_json_encode( $field['depends'] ) ) );
		} else {
			echo '<tr>';
		}

		printf( '<th scope="row"><label for="%s">%s</label></th><td>', esc_attr( $id ), esc_html( $field['label'] ) );

		if ( $pinned ) {
			printf(
				'<p><code>%s</code><br><span class="description">%s</span></p>',
				esc_html__( 'Set in wp-config.php', 'demfilz-smtp-mailer' ),
				esc_html(
					sprintf(
						/* translators: %s: PHP constant name. */
						__( 'This value comes from the constant %s and cannot be changed here.', 'demfilz-smtp-mailer' ),
						$constant
					)
				)
			);

			echo '</td></tr>';

			return;
		}

		switch ( $field['type'] ) {
			case 'checkbox':
				printf(
					'<label><input type="checkbox" id="%s" name="%s" value="1" %s> %s</label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( ! empty( $value ), true, false ),
					esc_html( $field['label'] )
				);
				break;

			case 'select':
				printf( '<select id="%s" name="%s">', esc_attr( $id ), esc_attr( $name ) );

				foreach ( (array) $field['options'] as $option_value => $option_label ) {
					printf(
						'<option value="%s" %s>%s</option>',
						esc_attr( $option_value ),
						selected( (string) $value, (string) $option_value, false ),
						esc_html( $option_label )
					);
				}

				echo '</select>';
				break;

			case 'password':
				$stored = DSMTP_Settings::has_secret( $provider_id, $key );

				printf(
					'<input type="password" class="regular-text" id="%s" name="%s" value="" autocomplete="new-password" placeholder="%s">',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $stored ? DSMTP_Settings::MASK : '' )
				);

				if ( $stored ) {
					printf(
						'<p><label><input type="checkbox" name="%s" value="1"> %s</label></p>',
						esc_attr( 'dsmtp[providers][' . $provider_id . '][' . $key . '_clear]' ),
						esc_html__( 'Remove the stored value', 'demfilz-smtp-mailer' )
					);
				}
				break;

			case 'number':
				printf(
					'<input type="number" class="small-text" id="%s" name="%s" value="%s">',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
				break;

			default:
				printf(
					'<input type="%s" class="regular-text" id="%s" name="%s" value="%s" placeholder="%s">',
					'email' === $field['type'] ? 'email' : 'text',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					esc_attr( $field['placeholder'] )
				);
		}

		if ( '' !== $field['description'] ) {
			printf( '<p class="description">%s</p>', esc_html( $field['description'] ) );
		}

		echo '</td></tr>';
	}
}
