<?php
/**
 * SPF, DKIM and DMARC lookups for the sending domain.
 *
 * @package Demfilz_SMTP_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks the records that decide whether inboxes trust your mail.
 */
class DSMTP_DNS {

	/**
	 * The SPF include and DKIM selectors each mailer normally needs.
	 *
	 * @return array
	 */
	public static function expectations() {
		return array(
			'microsoft'      => array(
				'spf'       => 'spf.protection.outlook.com',
				'selectors' => array( 'selector1', 'selector2' ),
			),
			'microsoft-smtp' => array(
				'spf'       => 'spf.protection.outlook.com',
				'selectors' => array( 'selector1', 'selector2' ),
			),
			'gmail'          => array(
				'spf'       => '_spf.google.com',
				'selectors' => array( 'google' ),
			),
			'sendgrid'       => array(
				'spf'       => 'sendgrid.net',
				'selectors' => array( 's1', 's2' ),
			),
			'mailgun'        => array(
				'spf'       => 'mailgun.org',
				'selectors' => array( 'mailo', 'smtp', 'krs' ),
			),
			'brevo'          => array(
				'spf'       => 'spf.brevo.com',
				'selectors' => array( 'mail', 'brevo1', 'brevo2' ),
			),
			'postmark'       => array(
				'spf'       => 'spf.mtasv.net',
				'selectors' => array( 'pm', 'pm-bounces' ),
			),
			'resend'         => array(
				'spf'       => 'amazonses.com',
				'selectors' => array( 'resend' ),
			),
		);
	}

	/**
	 * Run every check for one domain.
	 *
	 * @param string $domain      Domain to inspect.
	 * @param string $provider_id Active mailer, used to guess selectors.
	 * @return array
	 */
	public static function inspect( $domain, $provider_id = '' ) {
		$domain = self::clean_domain( $domain );

		if ( '' === $domain ) {
			return array(
				'domain' => '',
				'error'  => __( 'Set a from address first, then run the check.', 'demfilz-smtp-mailer' ),
			);
		}

		$expect    = self::expectations();
		$expected  = isset( $expect[ $provider_id ] ) ? $expect[ $provider_id ] : array(
			'spf'       => '',
			'selectors' => array(),
		);
		$txt       = self::txt( $domain );
		$spf       = '';
		$dmarc_txt = self::txt( '_dmarc.' . $domain );
		$dmarc     = '';

		foreach ( $txt as $record ) {
			if ( 0 === stripos( $record, 'v=spf1' ) ) {
				$spf = $record;
				break;
			}
		}

		foreach ( $dmarc_txt as $record ) {
			if ( 0 === stripos( $record, 'v=DMARC1' ) ) {
				$dmarc = $record;
				break;
			}
		}

		$dkim = array();

		foreach ( (array) $expected['selectors'] as $selector ) {
			$name    = $selector . '._domainkey.' . $domain;
			$records = array_merge( self::txt( $name ), self::cname( $name ) );

			if ( $records ) {
				$dkim[ $selector ] = $records[0];
			}
		}

		return array(
			'domain'       => $domain,
			'error'        => '',
			'method'       => self::method(),
			'spf'          => $spf,
			'spf_expected' => $expected['spf'],
			'spf_ok'       => '' !== $spf && ( '' === $expected['spf'] || false !== stripos( $spf, $expected['spf'] ) ),
			'dmarc'        => $dmarc,
			'dmarc_policy' => self::dmarc_policy( $dmarc ),
			'dkim'         => $dkim,
			'dkim_checked' => (array) $expected['selectors'],
			'mx'           => self::mx( $domain ),
		);
	}

	/**
	 * The policy value from a DMARC record.
	 *
	 * @param string $record DMARC record.
	 * @return string
	 */
	private static function dmarc_policy( $record ) {
		if ( '' === $record ) {
			return '';
		}

		if ( preg_match( '/\bp\s*=\s*(none|quarantine|reject)\b/i', $record, $match ) ) {
			return strtolower( $match[1] );
		}

		return '';
	}

	/**
	 * The domain part of an address, or a bare domain.
	 *
	 * @param string $value Address or domain.
	 * @return string
	 */
	public static function clean_domain( $value ) {
		$value = trim( (string) $value );

		if ( false !== strpos( $value, '@' ) ) {
			$value = substr( strrchr( $value, '@' ), 1 );
		}

		$value = preg_replace( '/[^a-z0-9.\-]/i', '', $value );

		return strtolower( (string) $value );
	}

	/**
	 * How lookups are being performed on this host.
	 *
	 * @return string
	 */
	public static function method() {
		return function_exists( 'dns_get_record' ) ? 'dns' : 'https';
	}

	/**
	 * TXT records for a name.
	 *
	 * @param string $name Host name.
	 * @return array
	 */
	public static function txt( $name ) {
		if ( function_exists( 'dns_get_record' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed lookup must not raise a warning on the settings screen.
			$records = @dns_get_record( $name, DNS_TXT );

			if ( is_array( $records ) ) {
				$out = array();

				foreach ( $records as $record ) {
					if ( ! empty( $record['txt'] ) ) {
						$out[] = (string) $record['txt'];
					} elseif ( ! empty( $record['entries'] ) ) {
						$out[] = implode( '', (array) $record['entries'] );
					}
				}

				if ( $out ) {
					return $out;
				}
			}
		}

		return self::over_https( $name, 'TXT' );
	}

	/**
	 * CNAME records for a name.
	 *
	 * @param string $name Host name.
	 * @return array
	 */
	public static function cname( $name ) {
		if ( function_exists( 'dns_get_record' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed lookup must not raise a warning on the settings screen.
			$records = @dns_get_record( $name, DNS_CNAME );

			if ( is_array( $records ) && $records ) {
				$out = array();

				foreach ( $records as $record ) {
					if ( ! empty( $record['target'] ) ) {
						$out[] = (string) $record['target'];
					}
				}

				return $out;
			}
		}

		return self::over_https( $name, 'CNAME' );
	}

	/**
	 * MX hosts for a domain.
	 *
	 * @param string $domain Domain.
	 * @return array
	 */
	public static function mx( $domain ) {
		if ( function_exists( 'dns_get_record' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed lookup must not raise a warning on the settings screen.
			$records = @dns_get_record( $domain, DNS_MX );

			if ( is_array( $records ) && $records ) {
				$out = array();

				foreach ( $records as $record ) {
					if ( ! empty( $record['target'] ) ) {
						$out[] = (string) $record['target'];
					}
				}

				return $out;
			}
		}

		return self::over_https( $domain, 'MX' );
	}

	/**
	 * Fall back to DNS over HTTPS when the PHP resolver is unavailable.
	 *
	 * @param string $name Host name.
	 * @param string $type Record type.
	 * @return array
	 */
	private static function over_https( $name, $type ) {
		$response = wp_remote_get(
			add_query_arg(
				array(
					'name' => rawurlencode( $name ),
					'type' => rawurlencode( $type ),
				),
				'https://dns.google/resolve'
			),
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/dns-json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || empty( $data['Answer'] ) ) {
			return array();
		}

		$out = array();

		foreach ( $data['Answer'] as $answer ) {
			if ( empty( $answer['data'] ) ) {
				continue;
			}

			$value = trim( (string) $answer['data'], '"' );
			$value = str_replace( '" "', '', $value );

			$out[] = $value;
		}

		return $out;
	}
}
