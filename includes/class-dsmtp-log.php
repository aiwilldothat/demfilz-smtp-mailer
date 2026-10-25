<?php
/**
 * The email log table.
 *
 * @package Demfilz_SMTP_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores one row per message WordPress tried to send.
 */
class DSMTP_Log {

	const STATUS_SENT    = 'sent';
	const STATUS_FAILED  = 'failed';
	const STATUS_QUEUED  = 'queued';
	const STATUS_BOUNCED = 'bounced';
	const STATUS_BLOCKED = 'blocked';

	/**
	 * Fully qualified table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'dsmtp_log';
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
			created_at datetime NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'sent',
			mailer varchar(40) NOT NULL DEFAULT '',
			from_email varchar(200) NOT NULL DEFAULT '',
			to_email text NOT NULL,
			cc_email text NOT NULL,
			bcc_email text NOT NULL,
			subject text NOT NULL,
			headers longtext NOT NULL,
			body longtext NOT NULL,
			attachments text NOT NULL,
			payload longtext NOT NULL,
			message_id varchar(255) NOT NULL DEFAULT '',
			error text NOT NULL,
			source varchar(190) NOT NULL DEFAULT '',
			initiator bigint(20) unsigned NOT NULL DEFAULT 0,
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			retry_at datetime DEFAULT NULL,
			delivered_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY created_at (created_at),
			KEY retry_at (retry_at),
			KEY message_id (message_id(100))
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Drop the table.
	 *
	 * @return void
	 */
	public static function drop_table() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	/**
	 * Default row values.
	 *
	 * @return array
	 */
	private static function defaults() {
		return array(
			'created_at'   => current_time( 'mysql', true ),
			'status'       => self::STATUS_SENT,
			'mailer'       => '',
			'from_email'   => '',
			'to_email'     => '',
			'cc_email'     => '',
			'bcc_email'    => '',
			'subject'      => '',
			'headers'      => '',
			'body'         => '',
			'attachments'  => '',
			'payload'      => '',
			'message_id'   => '',
			'error'        => '',
			'source'       => '',
			'initiator'    => 0,
			'attempts'     => 0,
			'retry_at'     => null,
			'delivered_at' => null,
		);
	}

	/**
	 * Insert a row.
	 *
	 * @param array $data Row values.
	 * @return int Insert id, 0 on failure.
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$row = array_merge( self::defaults(), array_intersect_key( $data, self::defaults() ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Writing to the plugin's own table.
		$ok = $wpdb->insert( self::table(), $row );

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update a row.
	 *
	 * @param int   $id   Row id.
	 * @param array $data Values to change.
	 * @return void
	 */
	public static function update( $id, array $data ) {
		global $wpdb;

		$row = array_intersect_key( $data, self::defaults() );

		if ( ! $row ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Writing to the plugin's own table.
		$wpdb->update( self::table(), $row, array( 'id' => (int) $id ) );
	}

	/**
	 * One row.
	 *
	 * @param int $id Row id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );

		return $row ? $row : null;
	}

	/**
	 * Query the log.
	 *
	 * @param array $args Search, status, date range, paging.
	 * @return array Rows.
	 */
	public static function query( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status'   => '',
				'search'   => '',
				'mailer'   => '',
				'since'    => '',
				'per_page' => 20,
				'page'     => 1,
				'orderby'  => 'id',
				'order'    => 'DESC',
			)
		);

		$table   = self::table();
		$order   = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
		$orderby = in_array( $args['orderby'], array( 'id', 'created_at', 'status' ), true ) ? $args['orderby'] : 'id';
		$offset  = max( 0, ( (int) $args['page'] - 1 ) ) * max( 1, (int) $args['per_page'] );
		$like    = '' === $args['search'] ? '' : '%' . $wpdb->esc_like( $args['search'] ) . '%';

		/*
		 * One fixed statement with sentinel arguments, so every value is prepared
		 * and the SQL string itself never changes shape.
		 */
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table, column and direction are validated above.
		$sql = $wpdb->prepare(
			"SELECT * FROM {$table}
			WHERE ( %s = '' OR status = %s )
			AND ( %s = '' OR mailer = %s )
			AND ( %s = '' OR created_at >= %s )
			AND ( %s = '' OR subject LIKE %s OR to_email LIKE %s OR from_email LIKE %s OR error LIKE %s )
			ORDER BY {$orderby} {$order}
			LIMIT %d OFFSET %d",
			$args['status'],
			$args['status'],
			$args['mailer'],
			$args['mailer'],
			$args['since'],
			$args['since'],
			$args['search'],
			$like,
			$like,
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
				'status' => '',
				'search' => '',
				'mailer' => '',
				'since'  => '',
			)
		);

		$table = self::table();
		$like  = '' === $args['search'] ? '' : '%' . $wpdb->esc_like( $args['search'] ) . '%';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table}
			WHERE ( %s = '' OR status = %s )
			AND ( %s = '' OR mailer = %s )
			AND ( %s = '' OR created_at >= %s )
			AND ( %s = '' OR subject LIKE %s OR to_email LIKE %s OR from_email LIKE %s OR error LIKE %s )",
			$args['status'],
			$args['status'],
			$args['mailer'],
			$args['mailer'],
			$args['since'],
			$args['since'],
			$args['search'],
			$like,
			$like,
			$like,
			$like
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Sent and failed counts over a number of days.
	 *
	 * @param int $days Days to look back.
	 * @return array With sent, failed, bounced and blocked keys.
	 */
	public static function stats( $days = 7 ) {
		$since = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, (int) $days ) * DAY_IN_SECONDS ) );
		$out   = array();

		foreach ( array(
			'sent'    => self::STATUS_SENT,
			'failed'  => self::STATUS_FAILED,
			'bounced' => self::STATUS_BOUNCED,
			'blocked' => self::STATUS_BLOCKED,
		) as $key => $status ) {
			$out[ $key ] = self::count(
				array(
					'status' => $status,
					'since'  => $since,
				)
			);
		}

		return $out;
	}

	/**
	 * The row that carries a provider message id.
	 *
	 * Provider ids are matched on their leading part, because some services
	 * report a longer id in delivery events than the one they return at send
	 * time.
	 *
	 * @param string $message_id Provider message id.
	 * @return array|null
	 */
	public static function find_by_message_id( $message_id ) {
		global $wpdb;

		$message_id = trim( (string) $message_id, '<> ' );

		if ( '' === $message_id ) {
			return null;
		}

		$table = self::table();
		$base  = strtok( $message_id, '.' );
		$like  = $wpdb->esc_like( '' === $base ? $message_id : $base ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE message_id = %s OR message_id LIKE %s ORDER BY id DESC LIMIT 1", $message_id, $like ), ARRAY_A );

		return $row ? $row : null;
	}

	/**
	 * The most recent message sent to an address within a window.
	 *
	 * Used to attach a bounce report to the message it belongs to when the
	 * report carries no usable message id.
	 *
	 * @param string $email Recipient address.
	 * @param int    $days  How far back to look.
	 * @return array|null
	 */
	public static function find_recent_to( $email, $days = 7 ) {
		global $wpdb;

		$email = strtolower( trim( (string) $email ) );

		if ( '' === $email ) {
			return null;
		}

		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, (int) $days ) * DAY_IN_SECONDS ) );
		$like  = '%' . $wpdb->esc_like( $email ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE created_at >= %s AND status IN (%s, %s) AND ( to_email LIKE %s OR cc_email LIKE %s OR bcc_email LIKE %s ) ORDER BY id DESC LIMIT 1", $since, self::STATUS_SENT, self::STATUS_BOUNCED, $like, $like, $like ), ARRAY_A );

		return $row ? $row : null;
	}

	/**
	 * Rows waiting for another delivery attempt.
	 *
	 * @param int $limit Maximum rows.
	 * @return array
	 */
	public static function due_retries( $limit = 20 ) {
		global $wpdb;

		$table = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s AND retry_at IS NOT NULL AND retry_at <= %s ORDER BY retry_at ASC LIMIT %d",
				self::STATUS_QUEUED,
				current_time( 'mysql', true ),
				max( 1, (int) $limit )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Delete rows by id.
	 *
	 * @param array $ids Row ids.
	 * @return int Rows deleted.
	 */
	public static function delete( array $ids ) {
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
	 * Empty the log.
	 *
	 * @return void
	 */
	public static function delete_all() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$wpdb->query( "DELETE FROM {$table}" );
	}

	/**
	 * Delete rows older than the retention setting.
	 *
	 * @param int $days Days to keep.
	 * @return int Rows deleted.
	 */
	public static function purge( $days ) {
		global $wpdb;

		$days = (int) $days;

		if ( $days < 1 ) {
			return 0;
		}

		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s AND status <> %s", $cutoff, self::STATUS_QUEUED ) );
	}

	/**
	 * Every distinct mailer that appears in the log.
	 *
	 * @return array
	 */
	public static function used_mailers() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$values = $wpdb->get_col( "SELECT DISTINCT mailer FROM {$table} WHERE mailer <> '' ORDER BY mailer ASC" );

		return is_array( $values ) ? $values : array();
	}

	/**
	 * Delete every row that involves one address. Used by the privacy eraser.
	 *
	 * @param string $email Address.
	 * @return int Rows deleted.
	 */
	public static function delete_for_email( $email ) {
		global $wpdb;

		$table = self::table();
		$like  = '%' . $wpdb->esc_like( $email ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE to_email LIKE %s OR cc_email LIKE %s OR bcc_email LIKE %s", $like, $like, $like ) );
	}

	/**
	 * Rows that involve one address, for the privacy exporter.
	 *
	 * @param string $email  Address.
	 * @param int    $number Rows to fetch.
	 * @param int    $offset Rows to skip.
	 * @return array
	 */
	public static function find_for_email( $email, $number, $offset ) {
		global $wpdb;

		$table = self::table();
		$like  = '%' . $wpdb->esc_like( $email ) . '%';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE to_email LIKE %s OR cc_email LIKE %s OR bcc_email LIKE %s ORDER BY id ASC LIMIT %d OFFSET %d",
				$like,
				$like,
				$like,
				(int) $number,
				(int) $offset
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $rows ) ? $rows : array();
	}
}
