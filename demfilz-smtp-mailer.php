<?php
/**
 * Plugin Name: Demfilz SMTP Mailer
 * Plugin URI: https://demfilz.com/smtp-mailer
 * Description: Send every WordPress email through a real mail service instead of the PHP mail function. Microsoft 365 and Outlook (modern OAuth or SMTP), Google Workspace and Gmail, any SMTP server, plus SendGrid, Mailgun, Brevo, Postmark and Resend, with an email log, bounce capture, a suppression list, retries, failure alerts and a domain records check.
 * Version: 1.1.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Demfilz
 * Author URI: https://demfilz.com/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: demfilz-smtp-mailer
 * Domain Path: /languages
 *
 * @package Demfilz_SMTP_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DSMTP_VERSION', '1.1.0' );
define( 'DSMTP_FILE', __FILE__ );
define( 'DSMTP_DIR', plugin_dir_path( __FILE__ ) );
define( 'DSMTP_URL', plugin_dir_url( __FILE__ ) );

require_once DSMTP_DIR . 'includes/class-dsmtp-crypto.php';
require_once DSMTP_DIR . 'includes/class-dsmtp-settings.php';
require_once DSMTP_DIR . 'includes/class-dsmtp-install.php';
require_once DSMTP_DIR . 'includes/class-dsmtp-log.php';
require_once DSMTP_DIR . 'includes/class-dsmtp-oauth.php';
require_once DSMTP_DIR . 'includes/class-dsmtp-providers.php';
require_once DSMTP_DIR . 'includes/providers/class-dsmtp-provider.php';
require_once DSMTP_DIR . 'includes/providers/class-dsmtp-provider-php.php';
require_once DSMTP_DIR . 'includes/providers/class-dsmtp-provider-smtp.php';
require_once DSMTP_DIR . 'includes/providers/class-dsmtp-provider-microsoft.php';
require_once DSMTP_DIR . 'includes/providers/class-dsmtp-provider-microsoft-smtp.php';
require_once DSMTP_DIR . 'includes/providers/class-dsmtp-provider-gmail.php';
require_once DSMTP_DIR . 'includes/providers/class-dsmtp-provider-sendgrid.php';
require_once DSMTP_DIR . 'includes/providers/class-dsmtp-provider-mailgun.php';
require_once DSMTP_DIR . 'includes/providers/class-dsmtp-provider-brevo.php';
require_once DSMTP_DIR . 'includes/providers/class-dsmtp-provider-postmark.php';
require_once DSMTP_DIR . 'includes/providers/class-dsmtp-provider-resend.php';
require_once DSMTP_DIR . 'includes/class-dsmtp-mailer.php';
require_once DSMTP_DIR . 'includes/class-dsmtp-suppression.php';
require_once DSMTP_DIR . 'includes/class-dsmtp-events.php';
require_once DSMTP_DIR . 'includes/class-dsmtp-bounces.php';
require_once DSMTP_DIR . 'includes/class-dsmtp-controls.php';
require_once DSMTP_DIR . 'includes/class-dsmtp-alerts.php';
require_once DSMTP_DIR . 'includes/class-dsmtp-dns.php';
require_once DSMTP_DIR . 'includes/class-dsmtp-cron.php';
require_once DSMTP_DIR . 'includes/class-dsmtp-privacy.php';

if ( is_admin() ) {
	require_once DSMTP_DIR . 'includes/class-dsmtp-import.php';
	require_once DSMTP_DIR . 'admin/class-dsmtp-admin.php';
}

register_activation_hook( __FILE__, array( 'DSMTP_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'DSMTP_Install', 'deactivate' ) );

/**
 * Boot the plugin once WordPress has loaded.
 *
 * @return void
 */
function dsmtp_bootstrap() {
	DSMTP_Install::maybe_upgrade();
	DSMTP_Mailer::init();
	DSMTP_Suppression::init();
	DSMTP_Events::init();
	DSMTP_Bounces::init();
	DSMTP_Controls::init();
	DSMTP_Alerts::init();
	DSMTP_OAuth::init();
	DSMTP_Cron::init();
	DSMTP_Privacy::init();

	if ( is_admin() ) {
		DSMTP_Admin::init();
	}
}
add_action( 'plugins_loaded', 'dsmtp_bootstrap' );

/**
 * Settings shortcut on the plugins screen.
 *
 * @param array $links Existing action links.
 * @return array
 */
function dsmtp_action_links( $links ) {
	$url = admin_url( 'admin.php?page=dsmtp-settings' );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'demfilz-smtp-mailer' ) . '</a>' );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'dsmtp_action_links' );
