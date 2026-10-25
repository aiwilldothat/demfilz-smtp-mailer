<?php
/**
 * The suppression list: addresses the site must stop emailing.
 *
 * @package Demfilz_SMTP_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hard bounces and spam complaints land here, and wp_mail() checks it before
 * every send, so a dead or unwilling address is never emailed twice.
 */
class DSMTP_Suppression {

	const REASON_BOUNCE    = 'bounce';
	const REASON_COMPLAINT = 'complaint';
	const REASON_MANUAL    = 'manual';

	/**
	 * Addresses removed from the message currently being sent.
	 *
	 * @var array
	 */
	private static $stripped = array();

	/**
	 * Hook the pre-send check.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'wp_mail', array( __CLASS__, 'filter_recipients' ), 1000 );
	}

	/**
	 * Fully qualified table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'dsmtp_suppress';
	}

	/**
	 * Create or update the table.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$table   = self::table();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(190) NOT NULL DEFAULT '',
			reason varchar(20) NOT NULL DEFAULT 'manual',
			detail text NOT NULL,
			source varchar(40) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Normalise an address for storage and comparison.
	 *
	 * @param string $email Address, possibly with a display name.
	 * @return string Lower case bare address, or empty.
	 */
	public static function normalise( $email ) {
		$email = trim( (string) $email );

		if ( preg_match( '/<([^>]+)>/', $email, $match ) ) {
			$email = $match[1];
		}

		$email = strtolower( trim( $email ) );

		return is_email( $email ) ? $email : '';
	}

	/**
	 * Whether an address is suppressed.
	 *
	 * @param string $email Address.
	 * @return bool
	 */
	public static function is_suppressed( $email ) {
		return null !== self::get( $email );
	}

	/**
	 * The suppression row for an address.
	 *
	 * @param string $email Address.
	 * @return array|null
	 */
	public static function get( $email ) {
		global $wpdb;

		$email = self::normalise( $email );

		if ( '' === $email ) {
			return null;
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s", $email ), ARRAY_A );

		return $row ? $row : null;
	}

	/**
	 * Add an address, or update the reason of one already listed.
	 *
	 * @param string $email  Address.
	 * @param string $reason One of the REASON_ constants.
	 * @param string $detail Why, in the provider's words.
	 * @param string $source Mailer or event source.
	 * @return bool Whether the address is now on the list.
	 */
	public static function add( $email, $reason = self::REASON_MANUAL, $detail = '', $source = '' ) {
		global $wpdb;

		$email = self::normalise( $email );

		if ( '' === $email ) {
			return false;
		}

		if ( ! in_array( $reason, array( self::REASON_BOUNCE, self::REASON_COMPLAINT, self::REASON_MANUAL ), true ) ) {
			$reason = self::REASON_MANUAL;
		}

		$data = array(
			'email'      => $email,
			'reason'     => $reason,
			'detail'     => wp_strip_all_tags( (string) $detail ),
			'source'     => sanitize_key( (string) $source ),
			'created_at' => current_time( 'mysql', true ),
		);

		$existing = self::get( $email );

		if ( $existing ) {
			unset( $data['created_at'] );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Writing to the plugin's own table.
			$wpdb->update( self::table(), $data, array( 'id' => (int) $existing['id'] ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Writing to the plugin's own table.
			$wpdb->insert( self::table(), $data );
		}

		/**
		 * Fires when an address is added to the suppression list.
		 *
		 * @since 1.1.0
		 *
		 * @param string $email  Address.
		 * @param string $reason Reason constant.
		 * @param string $detail Provider detail.
		 */
		do_action( 'dsmtp_suppressed', $email, $reason, $data['detail'] );

		return true;
	}

	/**
	 * Remove addresses from the list.
	 *
	 * @param array $ids Row ids.
	 * @return int Rows removed.
	 */
	public static function remove( array $ids ) {
		global $wpdb;

		$ids = array_filter( array_map( 'absint', $ids ) );

		if ( ! $ids ) {
			return 0;
		}

		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are generated from a counted integer array.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids ) );
	}

	/**
	 * Remove one address by value.
	 *
	 * @param string $email Address.
	 * @return bool
	 */
	public static function remove_email( $email ) {
		global $wpdb;

		$email = self::normalise( $email );

		if ( '' === $email ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Writing to the plugin's own table.
		return (bool) $wpdb->delete( self::table(), array( 'email' => $email ) );
	}

	/**
	 * Page through the list.
	 *
	 * @param array $args Search, reason, paging.
	 * @return array Rows.
	 */
	public static function query( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'search'   => '',
				'reason'   => '',
				'per_page' => 50,
				'page'     => 1,
			)
		);

		$table  = self::table();
		$like   = '' === $args['search'] ? '' : '%' . $wpdb->esc_like( $args['search'] ) . '%';
		$offset = max( 0, ( (int) $args['page'] - 1 ) ) * max( 1, (int) $args['per_page'] );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$sql = $wpdb->prepare(
			"SELECT * FROM {$table}
			WHERE ( %s = '' OR reason = %s )
			AND ( %s = '' OR email LIKE %s OR detail LIKE %s )
			ORDER BY id DESC
			LIMIT %d OFFSET %d",
			$args['reason'],
			$args['reason'],
			$args['search'],
			$like,
			$like,
			max( 1, (int) $args['per_page'] ),
			$offset
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count rows matching a query.
	 *
	 * @param array $args Same arguments as query().
	 * @return int
	 */
	public static function count( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'search' => '',
				'reason' => '',
			)
		);

		$table = self::table();
		$like  = '' === $args['search'] ? '' : '%' . $wpdb->esc_like( $args['search'] ) . '%';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table}
			WHERE ( %s = '' OR reason = %s )
			AND ( %s = '' OR email LIKE %s OR detail LIKE %s )",
			$args['reason'],
			$args['reason'],
			$args['search'],
			$like,
			$like
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Drop the table.
	 *
	 * @return void
	 */
	public static function drop_table() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	// The pre-send check.

	/**
	 * Take suppressed addresses out of a message before it is built.
	 *
	 * @param array $atts Arguments passed to wp_mail().
	 * @return array
	 */
	public static function filter_recipients( $atts ) {
		self::$stripped = array();

		if ( ! is_array( $atts ) ) {
			return $atts;
		}

		if ( isset( $atts['to'] ) ) {
			$atts['to'] = self::strip_list( $atts['to'] );
		}

		if ( ! empty( $atts['headers'] ) ) {
			$atts['headers'] = self::strip_headers( $atts['headers'] );
		}

		return $atts;
	}

	/**
	 * Addresses removed from the message currently being sent.
	 *
	 * @return array
	 */
	public static function stripped() {
		return self::$stripped;
	}

	/**
	 * Forget the stripped list, once the message has been logged.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$stripped = array();
	}

	/**
	 * Remove suppressed entries from a to value.
	 *
	 * @param string|array $to Address list as wp_mail() accepts it.
	 * @return string|array Same shape, minus suppressed entries.
	 */
	private static function strip_list( $to ) {
		$was_array = is_array( $to );
		$entries   = $was_array ? $to : explode( ',', (string) $to );
		$kept      = array();

		foreach ( $entries as $entry ) {
			$entry = trim( (string) $entry );

			if ( '' === $entry ) {
				continue;
			}

			$address = self::normalise( $entry );

			if ( '' !== $address && self::is_suppressed( $address ) ) {
				self::$stripped[] = $address;
				continue;
			}

			$kept[] = $entry;
		}

		return $was_array ? $kept : implode( ', ', $kept );
	}

	/**
	 * Remove suppressed entries from Cc and Bcc header lines.
	 *
	 * @param string|array $headers Headers as wp_mail() accepts them.
	 * @return string|array
	 */
	private static function strip_headers( $headers ) {
		$was_array = is_array( $headers );
		$lines     = $was_array ? $headers : preg_split( '/\r\n|\r|\n/', (string) $headers );
		$out       = array();

		foreach ( (array) $lines as $line ) {
			$line = (string) $line;

			if ( ! preg_match( '/^\s*(cc|bcc)\s*:\s*(.*)$/i', $line, $match ) ) {
				$out[] = $line;
				continue;
			}

			$kept = self::strip_list( $match[2] );

			if ( '' !== trim( (string) $kept ) ) {
				$out[] = ucfirst( strtolower( $match[1] ) ) . ': ' . $kept;
			}
		}

		return $was_array ? $out : implode( "\r\n", $out );
	}
}
