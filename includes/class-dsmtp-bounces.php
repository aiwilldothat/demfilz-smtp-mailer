<?php
/**
 * Bounce reports read out of the connected mailbox.
 *
 * @package Demfilz_SMTP_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Microsoft and Google do not post delivery events, but every bounce comes
 * back to the sending mailbox as a delivery status notification. This class
 * reads those reports from the connected mailbox, parses the machine readable
 * part (RFC 3464) and feeds the result through the same event handling the
 * webhook providers use.
 */
class DSMTP_Bounces {

	const HOOK   = 'dsmtp_bounce_poll';
	const OPTION = 'dsmtp_bounce_state';

	/**
	 * Hook the scheduled poll.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'poll_all' ) );
	}

	/**
	 * Providers whose mailbox can be read for bounce reports.
	 *
	 * @return array
	 */
	public static function providers() {
		return array( 'microsoft', 'gmail' );
	}

	/**
	 * Whether the stored token for a provider carries the read permission.
	 *
	 * @param string $provider_id Provider identifier.
	 * @return bool
	 */
	public static function has_read_scope( $provider_id ) {
		$provider = DSMTP_Providers::get( $provider_id );

		if ( 'microsoft' === $provider_id && $provider && method_exists( $provider, 'is_app_only' ) && $provider->is_app_only() ) {
			// Application permissions are granted in the directory, not in the token scope list.
			return true;
		}

		$tokens = DSMTP_OAuth::tokens( $provider_id );
		$scope  = isset( $tokens['scope'] ) ? (string) $tokens['scope'] : '';
		$needle = 'microsoft' === $provider_id ? 'Mail.Read' : 'gmail.readonly';

		return false !== stripos( $scope, $needle );
	}

	/**
	 * Poll every connected mailbox, from cron.
	 *
	 * @return void
	 */
	public static function poll_all() {
		if ( ! DSMTP_Settings::get( 'read_bounces', 0 ) ) {
			return;
		}

		foreach ( self::providers() as $provider_id ) {
			if ( DSMTP_OAuth::is_connected( $provider_id ) ) {
				self::poll( $provider_id );
			}
		}
	}

	/**
	 * Read new bounce reports from one mailbox.
	 *
	 * @param string $provider_id Provider identifier.
	 * @return array|WP_Error With checked and bounces counts.
	 */
	public static function poll( $provider_id ) {
		if ( ! in_array( $provider_id, self::providers(), true ) ) {
			return new WP_Error( 'dsmtp_bounces', __( 'That mailer does not expose a mailbox to read.', 'demfilz-smtp-mailer' ) );
		}

		if ( ! self::has_read_scope( $provider_id ) ) {
			return new WP_Error( 'dsmtp_bounces', __( 'The connection does not include permission to read the mailbox. Reconnect the mailer after turning bounce reading on.', 'demfilz-smtp-mailer' ) );
		}

		$token = DSMTP_OAuth::access_token( $provider_id );

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$state   = self::state( $provider_id );
		$reports = 'microsoft' === $provider_id ? self::microsoft_reports( $token, $state ) : self::gmail_reports( $token, $state );

		if ( is_wp_error( $reports ) ) {
			return $reports;
		}

		$bounces = 0;

		foreach ( $reports as $id => $mime ) {
			$state['seen'][] = $id;

			foreach ( self::parse_dsn( $mime ) as $result ) {
				$event = DSMTP_Events::event( $result['email'], $result['kind'], $result['detail'], '', $provider_id );

				if ( $event && DSMTP_Events::apply( $event ) ) {
					++$bounces;
				}
			}
		}

		$state['seen']  = array_slice( array_unique( $state['seen'] ), -200 );
		$state['since'] = gmdate( 'Y-m-d\TH:i:s\Z', time() - HOUR_IN_SECONDS );
		$state['last']  = time();

		self::save_state( $provider_id, $state );

		return array(
			'checked' => count( $reports ),
			'bounces' => $bounces,
		);
	}

	/**
	 * Stored polling state for a provider.
	 *
	 * @param string $provider_id Provider identifier.
	 * @return array
	 */
	public static function state( $provider_id ) {
		$all   = get_option( self::OPTION, array() );
		$state = isset( $all[ $provider_id ] ) && is_array( $all[ $provider_id ] ) ? $all[ $provider_id ] : array();

		return wp_parse_args(
			$state,
			array(
				'since' => gmdate( 'Y-m-d\TH:i:s\Z', time() - ( 3 * DAY_IN_SECONDS ) ),
				'seen'  => array(),
				'last'  => 0,
			)
		);
	}

	/**
	 * Persist polling state.
	 *
	 * @param string $provider_id Provider identifier.
	 * @param array  $state       State.
	 * @return void
	 */
	private static function save_state( $provider_id, $state ) {
		$all = get_option( self::OPTION, array() );

		if ( ! is_array( $all ) ) {
			$all = array();
		}

		$all[ $provider_id ] = $state;

		update_option( self::OPTION, $all, false );
	}

	/**
	 * Whether a message looks like a delivery report.
	 *
	 * @param string $from    Sender address.
	 * @param string $subject Subject line.
	 * @return bool
	 */
	public static function looks_like_report( $from, $subject ) {
		$from    = strtolower( (string) $from );
		$subject = strtolower( (string) $subject );

		if ( preg_match( '/^(postmaster|mailer-daemon|microsoftexchange)/', $from ) ) {
			return true;
		}

		return (bool) preg_match( '/^(undeliverable|delivery has failed|mail delivery failed|delivery status notification|undelivered mail)/', $subject );
	}

	/**
	 * Fetch new delivery reports from a Microsoft mailbox as raw MIME.
	 *
	 * @param string $token Access token.
	 * @param array  $state Polling state.
	 * @return array|WP_Error Message id to MIME.
	 */
	private static function microsoft_reports( $token, $state ) {
		$provider = DSMTP_Providers::get( 'microsoft' );
		$base     = DSMTP_Provider_Microsoft::GRAPH;

		if ( $provider && $provider->is_app_only() ) {
			$base .= '/users/' . rawurlencode( (string) $provider->option( 'mailbox', '' ) );
		} else {
			$base .= '/me';
		}

		$headers = array( 'Authorization' => 'Bearer ' . $token );
		$list    = wp_remote_get(
			add_query_arg(
				array(
					'$select'  => 'id,subject,from,receivedDateTime',
					'$orderby' => 'receivedDateTime desc',
					'$top'     => 25,
					'$filter'  => rawurlencode( 'receivedDateTime ge ' . $state['since'] ),
				),
				$base . '/messages'
			),
			array(
				'timeout' => 30,
				'headers' => $headers,
			)
		);

		if ( is_wp_error( $list ) ) {
			return $list;
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $list ) ) {
			return new WP_Error(
				'dsmtp_bounces',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Microsoft refused the mailbox listing (HTTP %d). Check that the connection has permission to read mail.', 'demfilz-smtp-mailer' ),
					(int) wp_remote_retrieve_response_code( $list )
				)
			);
		}

		$data    = json_decode( wp_remote_retrieve_body( $list ), true );
		$reports = array();

		foreach ( (array) ( isset( $data['value'] ) ? $data['value'] : array() ) as $item ) {
			if ( empty( $item['id'] ) || in_array( $item['id'], $state['seen'], true ) ) {
				continue;
			}

			$from = isset( $item['from']['emailAddress']['address'] ) ? $item['from']['emailAddress']['address'] : '';

			if ( ! self::looks_like_report( $from, isset( $item['subject'] ) ? $item['subject'] : '' ) ) {
				continue;
			}

			$raw = wp_remote_get(
				$base . '/messages/' . rawurlencode( $item['id'] ) . '/$value',
				array(
					'timeout' => 30,
					'headers' => $headers,
				)
			);

			if ( ! is_wp_error( $raw ) && 200 === (int) wp_remote_retrieve_response_code( $raw ) ) {
				$reports[ $item['id'] ] = wp_remote_retrieve_body( $raw );
			}
		}

		return $reports;
	}

	/**
	 * Fetch new delivery reports from a Gmail mailbox as raw MIME.
	 *
	 * @param string $token Access token.
	 * @param array  $state Polling state.
	 * @return array|WP_Error Message id to MIME.
	 */
	private static function gmail_reports( $token, $state ) {
		$headers = array( 'Authorization' => 'Bearer ' . $token );
		$list    = wp_remote_get(
			add_query_arg(
				array(
					'q'          => rawurlencode( 'from:mailer-daemon newer_than:3d' ),
					'maxResults' => 25,
				),
				'https://gmail.googleapis.com/gmail/v1/users/me/messages'
			),
			array(
				'timeout' => 30,
				'headers' => $headers,
			)
		);

		if ( is_wp_error( $list ) ) {
			return $list;
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $list ) ) {
			return new WP_Error(
				'dsmtp_bounces',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Google refused the mailbox listing (HTTP %d). Check that the connection has permission to read mail.', 'demfilz-smtp-mailer' ),
					(int) wp_remote_retrieve_response_code( $list )
				)
			);
		}

		$data    = json_decode( wp_remote_retrieve_body( $list ), true );
		$reports = array();

		foreach ( (array) ( isset( $data['messages'] ) ? $data['messages'] : array() ) as $item ) {
			if ( empty( $item['id'] ) || in_array( $item['id'], $state['seen'], true ) ) {
				continue;
			}

			$raw = wp_remote_get(
				'https://gmail.googleapis.com/gmail/v1/users/me/messages/' . rawurlencode( $item['id'] ) . '?format=raw',
				array(
					'timeout' => 30,
					'headers' => $headers,
				)
			);

			if ( is_wp_error( $raw ) || 200 !== (int) wp_remote_retrieve_response_code( $raw ) ) {
				continue;
			}

			$message = json_decode( wp_remote_retrieve_body( $raw ), true );

			if ( empty( $message['raw'] ) ) {
				continue;
			}

			$mime = base64_decode( strtr( $message['raw'], '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- The API returns the message base64url encoded.

			if ( is_string( $mime ) && '' !== $mime ) {
				$reports[ $item['id'] ] = $mime;
			}
		}

		return $reports;
	}

	// Parsing a delivery status notification.

	/**
	 * Pull the failed recipients out of a bounce message.
	 *
	 * Reads the RFC 3464 message/delivery-status part when there is one, and
	 * falls back to the wording Exchange and Gmail use in the human readable
	 * part when there is not.
	 *
	 * @param string $mime Raw message.
	 * @return array Each with email, kind and detail keys.
	 */
	public static function parse_dsn( $mime ) {
		$mime    = str_replace( "\r\n", "\n", (string) $mime );
		$results = array();

		$position = stripos( $mime, 'content-type: message/delivery-status' );

		if ( false !== $position ) {
			$section = substr( $mime, $position );
			$end     = preg_match( '/\n--[^\n]+\n/', $section, $match, PREG_OFFSET_CAPTURE, 1 ) ? $match[0][1] : strlen( $section );
			$section = substr( $section, 0, $end );

			// Drop the part headers, keep the status fields.
			$split  = strpos( $section, "\n\n" );
			$fields = false === $split ? $section : substr( $section, $split + 2 );
			$groups = preg_split( '/\n\s*\n/', trim( $fields ) );

			foreach ( $groups as $group ) {
				$result = self::parse_group( $group );

				if ( $result ) {
					$results[] = $result;
				}
			}
		}

		if ( ! $results ) {
			$results = self::parse_human_readable( $mime );
		}

		return $results;
	}

	/**
	 * Parse one per-recipient block of a delivery status part.
	 *
	 * @param string $group Field block.
	 * @return array|null
	 */
	private static function parse_group( $group ) {
		// Unfold continuation lines.
		$group  = preg_replace( '/\n[ \t]+/', ' ', $group );
		$fields = array();

		foreach ( explode( "\n", $group ) as $line ) {
			if ( preg_match( '/^([A-Za-z\-]+):\s*(.*)$/', trim( $line ), $match ) ) {
				$fields[ strtolower( $match[1] ) ] = trim( $match[2] );
			}
		}

		$recipient = '';

		foreach ( array( 'final-recipient', 'original-recipient' ) as $key ) {
			if ( ! empty( $fields[ $key ] ) ) {
				$recipient = preg_replace( '/^[a-z0-9\-]+;\s*/i', '', $fields[ $key ] );
				break;
			}
		}

		$recipient = DSMTP_Suppression::normalise( $recipient );

		if ( '' === $recipient ) {
			return null;
		}

		$action = isset( $fields['action'] ) ? strtolower( $fields['action'] ) : '';
		$status = isset( $fields['status'] ) ? $fields['status'] : '';
		$detail = isset( $fields['diagnostic-code'] ) ? preg_replace( '/^[a-z0-9\-]+;\s*/i', '', $fields['diagnostic-code'] ) : '';

		if ( 'delivered' === $action || 'relayed' === $action || 'expanded' === $action ) {
			return null;
		}

		$kind = self::kind_from_status( $status, $action );

		if ( '' === $kind ) {
			return null;
		}

		return array(
			'email'  => $recipient,
			'kind'   => $kind,
			'detail' => trim( ( '' !== $status ? $status . ' ' : '' ) . $detail ),
		);
	}

	/**
	 * Decide between a hard and a soft bounce from the DSN status.
	 *
	 * @param string $status Status such as 5.1.1.
	 * @param string $action Action such as failed or delayed.
	 * @return string A KIND_ constant, or empty to ignore.
	 */
	private static function kind_from_status( $status, $action ) {
		if ( 0 === strpos( $status, '5' ) ) {
			return DSMTP_Events::KIND_BOUNCE;
		}

		if ( 0 === strpos( $status, '4' ) || 'delayed' === $action ) {
			return DSMTP_Events::KIND_SOFT;
		}

		if ( 'failed' === $action ) {
			return DSMTP_Events::KIND_BOUNCE;
		}

		return '';
	}

	/**
	 * Last resort: read the failed address out of the text Exchange and Gmail write.
	 *
	 * @param string $mime Raw message.
	 * @return array
	 */
	private static function parse_human_readable( $mime ) {
		$text = quoted_printable_decode( $mime );
		$out  = array();

		$patterns = array(
			// Exchange Online: "Your message to x@y couldn't be delivered." plus the remote server line.
			'/message to\s+([^\s<>]+@[^\s<>]+)\s+couldn.t be delivered/i',
			// Exchange: "Delivery has failed to these recipients or groups:" followed by the address.
			'/Delivery has failed to these recipients or groups:\s*(?:[^\n]*\n)*?[^\n]*?([^\s<>]+@[^\s<>]+)/i',
			// Gmail: "Your message wasn't delivered to x@y because".
			'/wasn.t delivered to\s+([^\s<>]+@[^\s<>]+)/i',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $text, $match ) ) {
				$email = DSMTP_Suppression::normalise( $match[1] );

				if ( '' !== $email ) {
					$detail = '';

					if ( preg_match( "/Remote Server returned '([^']+)'/i", $text, $reason ) ) {
						$detail = $reason[1];
					} elseif ( preg_match( '/(5\.\d+\.\d+[^\n]*)/', $text, $reason ) ) {
						$detail = $reason[1];
					}

					$out[] = array(
						'email'  => $email,
						'kind'   => DSMTP_Events::KIND_BOUNCE,
						'detail' => trim( $detail ),
					);

					break;
				}
			}
		}

		return $out;
	}
}
