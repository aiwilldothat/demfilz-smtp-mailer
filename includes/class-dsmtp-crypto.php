<?php
/**
 * Encryption helper for stored credentials.
 *
 * @package Demfilz_SMTP_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encrypts secrets at rest with a key derived from the site salts.
 *
 * The salts never leave wp-config.php, so a stolen database dump alone does not
 * hand over the mail credentials. Values are stored with a version prefix so the
 * format can change later without breaking existing sites.
 */
class DSMTP_Crypto {

	const PREFIX_SODIUM  = 'dsmtp1:';
	const PREFIX_OPENSSL = 'dsmtp2:';

	/**
	 * Whether a value looks like something this class produced.
	 *
	 * @param mixed $value Stored value.
	 * @return bool
	 */
	public static function is_encrypted( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}

		return 0 === strpos( $value, self::PREFIX_SODIUM ) || 0 === strpos( $value, self::PREFIX_OPENSSL );
	}

	/**
	 * Encrypt a secret for storage.
	 *
	 * Returns the plain value unchanged when no crypto extension is available,
	 * so a locked down host still works, just without encryption at rest.
	 *
	 * @param string $value Plain text secret.
	 * @return string
	 */
	public static function encrypt( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}

		if ( self::is_encrypted( $value ) ) {
			return $value;
		}

		if ( function_exists( 'sodium_crypto_secretbox' ) && defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' ) ) {
			$key   = self::key( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
			$nonce = self::random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

			if ( '' !== $nonce ) {
				$cipher = sodium_crypto_secretbox( $value, $nonce, $key );
				return self::PREFIX_SODIUM . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext, not obfuscation.
			}
		}

		if ( function_exists( 'openssl_encrypt' ) ) {
			$key = self::key( 32 );
			$iv  = self::random_bytes( 16 );

			if ( '' !== $iv ) {
				$cipher = openssl_encrypt( $value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

				if ( false !== $cipher ) {
					$mac = hash_hmac( 'sha256', $iv . $cipher, $key, true );
					return self::PREFIX_OPENSSL . base64_encode( $iv . $mac . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext, not obfuscation.
				}
			}
		}

		return $value;
	}

	/**
	 * Decrypt a stored secret.
	 *
	 * @param string $value Stored value.
	 * @return string
	 */
	public static function decrypt( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}

		if ( 0 === strpos( $value, self::PREFIX_SODIUM ) ) {
			if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
				return '';
			}

			$raw = base64_decode( substr( $value, strlen( self::PREFIX_SODIUM ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary ciphertext, not obfuscation.

			if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}

			$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, self::key( SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) );

			return false === $plain ? '' : $plain;
		}

		if ( 0 === strpos( $value, self::PREFIX_OPENSSL ) ) {
			if ( ! function_exists( 'openssl_decrypt' ) ) {
				return '';
			}

			$raw = base64_decode( substr( $value, strlen( self::PREFIX_OPENSSL ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary ciphertext, not obfuscation.

			if ( false === $raw || strlen( $raw ) <= 48 ) {
				return '';
			}

			$key    = self::key( 32 );
			$iv     = substr( $raw, 0, 16 );
			$mac    = substr( $raw, 16, 32 );
			$cipher = substr( $raw, 48 );

			if ( ! hash_equals( hash_hmac( 'sha256', $iv . $cipher, $key, true ), $mac ) ) {
				return '';
			}

			$plain = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

			return false === $plain ? '' : $plain;
		}

		return $value;
	}

	/**
	 * Derive the encryption key from the site salts.
	 *
	 * @param int $length Key length in bytes.
	 * @return string
	 */
	private static function key( $length ) {
		$material = '';

		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT' ) as $constant ) {
			if ( defined( $constant ) ) {
				$material .= (string) constant( $constant );
			}
		}

		if ( '' === $material ) {
			$material = (string) get_option( 'siteurl' ) . ABSPATH;
		}

		return substr( hash( 'sha256', 'dsmtp|' . $material, true ), 0, $length );
	}

	/**
	 * Random bytes without fataling on hosts that cannot supply them.
	 *
	 * @param int $length Number of bytes.
	 * @return string Empty string when no source is available.
	 */
	private static function random_bytes( $length ) {
		try {
			return random_bytes( $length );
		} catch ( Exception $e ) {
			return '';
		}
	}
}
