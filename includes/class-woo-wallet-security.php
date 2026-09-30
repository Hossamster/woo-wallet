<?php
/**
 * Application-level encryption and security helper for sensitive wallet data.
 *
 * Uses OpenSSL AES-256-CBC with authentication via HMAC-SHA256, keyed from
 * WordPress SECURE_AUTH_KEY / AUTH_KEY constants.
 *
 * @package StandaleneTech
 * @since   2.1.0
 */

defined( 'ABSPATH' ) || exit;

class Woo_Wallet_Security {

	const PREFIX = 'enc:v1:';
	const CIPHER = 'aes-256-cbc';

	/**
	 * Derive a 256-bit encryption key from WordPress salts.
	 *
	 * @return string 32-byte raw binary key.
	 */
	private static function get_key() {
		$salt = defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'woo-wallet-default-salt' );
		return hash( 'sha256', $salt . 'woo_wallet_vault', true );
	}

	/**
	 * Encrypt plaintext data.
	 *
	 * @param string $plaintext Value to encrypt.
	 * @return string Ciphertext prefixed with enc:v1: or original value if empty.
	 */
	public static function encrypt( $plaintext ) {
		if ( ! is_string( $plaintext ) || '' === $plaintext ) {
			return $plaintext;
		}
		// Avoid double encryption
		if ( 0 === strpos( $plaintext, self::PREFIX ) ) {
			return $plaintext;
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return $plaintext;
		}

		$key     = self::get_key();
		$ivlen   = openssl_cipher_iv_length( self::CIPHER );
		$iv      = openssl_random_pseudo_bytes( $ivlen );
		$raw_enc = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
		$hmac    = hash_hmac( 'sha256', $iv . $raw_enc, $key, true );
		$payload = base64_encode( $iv . $hmac . $raw_enc );

		return self::PREFIX . $payload;
	}

	/**
	 * Decrypt ciphertext data.
	 *
	 * Transparently returns plaintext if the string is not encrypted (e.g. legacy data).
	 *
	 * @param string $ciphertext Value to decrypt.
	 * @return string Plaintext value.
	 */
	public static function decrypt( $ciphertext ) {
		if ( ! is_string( $ciphertext ) || '' === $ciphertext ) {
			return $ciphertext;
		}
		if ( 0 !== strpos( $ciphertext, self::PREFIX ) ) {
			return $ciphertext; // Plaintext legacy row
		}
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return $ciphertext;
		}

		$payload = base64_decode( substr( $ciphertext, strlen( self::PREFIX ) ), true );
		if ( false === $payload ) {
			return $ciphertext;
		}

		$key   = self::get_key();
		$ivlen = openssl_cipher_iv_length( self::CIPHER );
		if ( strlen( $payload ) < $ivlen + 32 ) {
			return $ciphertext;
		}

		$iv       = substr( $payload, 0, $ivlen );
		$hmac     = substr( $payload, $ivlen, 32 );
		$raw_enc  = substr( $payload, $ivlen + 32 );
		$calc_mac = hash_hmac( 'sha256', $iv . $raw_enc, $key, true );

		if ( ! hash_equals( $hmac, $calc_mac ) ) {
			return $ciphertext; // Tampered or corrupted
		}

		$decrypted = openssl_decrypt( $raw_enc, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
		return false !== $decrypted ? $decrypted : $ciphertext;
	}

	/**
	 * Mask a sensitive string (account number, IBAN, phone) keeping only the tail.
	 *
	 * @param string $value       String to mask.
	 * @param int    $keep_suffix Number of trailing characters to keep visible.
	 * @return string|null
	 */
	public static function mask( $value, $keep_suffix = 4 ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		$len = strlen( $value );
		if ( $len <= $keep_suffix ) {
			return $value;
		}
		return str_repeat( '•', $len - $keep_suffix ) . substr( $value, -$keep_suffix );
	}
}
