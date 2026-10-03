<?php
/**
 * Application-level encryption and security helper for sensitive wallet data.
 *
 * Uses OpenSSL AES-256-CBC with authentication via HMAC-SHA256. Keyed from
 * WOO_WALLET_ENCRYPTION_KEY when it is defined in wp-config.php (enc:v2:),
 * otherwise from WordPress's SECURE_AUTH_KEY / AUTH_KEY salts (enc:v1:).
 *
 * @package StandaleneTech
 * @since   2.1.0
 */

defined( 'ABSPATH' ) || exit;

class Woo_Wallet_Security {

	const PREFIX    = 'enc:v1:';
	const PREFIX_V2 = 'enc:v2:';
	const CIPHER    = 'aes-256-cbc';

	/**
	 * Option: a known value encrypted with each key in use, to tell when a
	 * key has changed (see key_problems()).
	 */
	const CANARY_OPTION = 'woo_wallet_encryption_canary';
	const CANARY_TEXT   = 'woo-wallet-encryption-canary';

	/**
	 * Option: whether bank details on withdrawals are encrypted ('yes'/'no').
	 */
	const ENABLED_OPTION = 'woo_wallet_encrypt_bank_details';

	/**
	 * The key for a ciphertext version, or null if it is not available.
	 *
	 * v1 is derived from WordPress's own salts — which hosts and security
	 * plugins routinely regenerate (after a hack, on a migration), silently
	 * making every v1 value unreadable. v2 uses WOO_WALLET_ENCRYPTION_KEY
	 * from wp-config.php, which nothing changes but the site owner.
	 *
	 * @param string $version 'v1' or 'v2'.
	 * @return string|null 32-byte raw binary key.
	 */
	private static function key_for( $version ) {
		if ( 'v2' === $version ) {
			$secret = defined( 'WOO_WALLET_ENCRYPTION_KEY' ) ? (string) WOO_WALLET_ENCRYPTION_KEY : '';
			$secret = (string) apply_filters( 'woo_wallet_encryption_key', $secret );
			return '' !== $secret ? hash( 'sha256', $secret . 'woo_wallet_vault_v2', true ) : null;
		}
		$salt = defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'woo-wallet-default-salt' );
		$salt = (string) apply_filters( 'woo_wallet_legacy_encryption_salt', $salt );
		return hash( 'sha256', $salt . 'woo_wallet_vault', true );
	}

	/**
	 * The version new values are encrypted with.
	 *
	 * @return string 'v1' or 'v2'.
	 */
	public static function current_version() {
		return null !== self::key_for( 'v2' ) ? 'v2' : 'v1';
	}

	/**
	 * Whether bank details on withdrawals should be stored encrypted.
	 *
	 * @param bool $enabled Value from earlier filters.
	 * @return bool
	 */
	public static function bank_details_encryption_enabled( $enabled = false ) {
		return $enabled || 'yes' === get_option( self::ENABLED_OPTION, 'no' );
	}

	/**
	 * Encrypt plaintext data with the current key.
	 *
	 * @param string $plaintext Value to encrypt.
	 * @return string Ciphertext prefixed with enc:v1: or enc:v2:, or the original value if empty.
	 */
	public static function encrypt( $plaintext ) {
		if ( ! is_string( $plaintext ) || '' === $plaintext ) {
			return $plaintext;
		}
		// Avoid double encryption.
		if ( 0 === strpos( $plaintext, 'enc:' ) ) {
			return $plaintext;
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return $plaintext;
		}
		return self::encrypt_with( $plaintext, self::current_version() );
	}

	/**
	 * Encrypt with a specific key version.
	 *
	 * @param string $plaintext Value.
	 * @param string $version   'v1' or 'v2'.
	 * @return string
	 */
	private static function encrypt_with( $plaintext, $version ) {
		$key = self::key_for( $version );
		if ( null === $key ) {
			return $plaintext;
		}
		$ivlen   = openssl_cipher_iv_length( self::CIPHER );
		$iv      = openssl_random_pseudo_bytes( $ivlen );
		$raw_enc = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
		$hmac    = hash_hmac( 'sha256', $iv . $raw_enc, $key, true );
		return ( 'v2' === $version ? self::PREFIX_V2 : self::PREFIX ) . base64_encode( $iv . $hmac . $raw_enc ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * The key version a ciphertext was made with.
	 *
	 * @param mixed $value Value.
	 * @return string|null 'v1', 'v2', or null for plaintext.
	 */
	public static function version_of( $value ) {
		if ( ! is_string( $value ) ) {
			return null;
		}
		if ( 0 === strpos( $value, self::PREFIX_V2 ) ) {
			return 'v2';
		}
		if ( 0 === strpos( $value, self::PREFIX ) ) {
			return 'v1';
		}
		return null;
	}

	/**
	 * Decrypt ciphertext data.
	 *
	 * Transparently returns plaintext if the string is not encrypted (e.g.
	 * legacy data), and the ciphertext unchanged if it cannot be decrypted —
	 * use reveal() for anything shown to a person.
	 *
	 * @param string $ciphertext Value to decrypt.
	 * @return string Plaintext value.
	 */
	public static function decrypt( $ciphertext ) {
		$version = self::version_of( $ciphertext );
		if ( null === $version || ! function_exists( 'openssl_decrypt' ) ) {
			return $ciphertext;
		}
		$key = self::key_for( $version );
		if ( null === $key ) {
			return $ciphertext;
		}
		$prefix  = 'v2' === $version ? self::PREFIX_V2 : self::PREFIX;
		$payload = base64_decode( substr( $ciphertext, strlen( $prefix ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $payload ) {
			return $ciphertext;
		}

		$ivlen = openssl_cipher_iv_length( self::CIPHER );
		if ( strlen( $payload ) < $ivlen + 32 ) {
			return $ciphertext;
		}

		$iv       = substr( $payload, 0, $ivlen );
		$hmac     = substr( $payload, $ivlen, 32 );
		$raw_enc  = substr( $payload, $ivlen + 32 );
		$calc_mac = hash_hmac( 'sha256', $iv . $raw_enc, $key, true );

		if ( ! hash_equals( $hmac, $calc_mac ) ) {
			return $ciphertext; // Wrong key, tampered or corrupted.
		}

		$decrypted = openssl_decrypt( $raw_enc, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
		return false !== $decrypted ? $decrypted : $ciphertext;
	}

	/**
	 * Whether a stored value is encrypted but cannot be decrypted with the
	 * keys available now.
	 *
	 * @param mixed $value Stored value.
	 * @return bool
	 */
	public static function is_unreadable( $value ) {
		return null !== self::version_of( $value ) && self::decrypt( $value ) === $value;
	}

	/**
	 * The text shown in place of a value that cannot be decrypted.
	 *
	 * @return string
	 */
	public static function unreadable_text() {
		return __( '[Unreadable — the encryption key has changed]', 'woo-wallet' );
	}

	/**
	 * Decrypt a stored value for display: the plaintext, or a clear message
	 * instead of an enc:v1:… string when it cannot be decrypted.
	 *
	 * @param mixed $value Stored value.
	 * @return mixed
	 */
	public static function reveal( $value ) {
		return self::is_unreadable( $value ) ? self::unreadable_text() : self::decrypt( $value );
	}

	/* ---------------- key-change detection ---------------- */

	/**
	 * Store a canary for each key in use that does not have one yet.
	 */
	public static function ensure_canaries() {
		$canaries = (array) get_option( self::CANARY_OPTION, array() );
		$changed  = false;
		foreach ( array( 'v1', 'v2' ) as $version ) {
			if ( empty( $canaries[ $version ] ) && null !== self::key_for( $version ) && function_exists( 'openssl_encrypt' ) ) {
				$canaries[ $version ] = self::encrypt_with( self::CANARY_TEXT, $version );
				$changed              = true;
			}
		}
		if ( $changed ) {
			update_option( self::CANARY_OPTION, $canaries, false );
		}
	}

	/**
	 * Keys that no longer match the one data was encrypted with, limited
	 * to versions that actually have encrypted data stored.
	 *
	 * @return string[] Versions with a problem: 'v1' (the WordPress salts changed), 'v2' (WOO_WALLET_ENCRYPTION_KEY changed or was removed).
	 */
	public static function key_problems() {
		$problems = array();
		$canaries = (array) get_option( self::CANARY_OPTION, array() );
		foreach ( $canaries as $version => $canary ) {
			if ( self::CANARY_TEXT !== self::decrypt( $canary ) && self::count_encrypted( $version ) > 0 ) {
				$problems[] = $version;
			}
		}
		return $problems;
	}

	/**
	 * Accept that data encrypted with a lost key cannot be recovered: stop
	 * warning about it by re-keying the canary. The data stays unreadable.
	 *
	 * @param string $version Version.
	 */
	public static function acknowledge_lost_key( $version ) {
		$canaries = (array) get_option( self::CANARY_OPTION, array() );
		unset( $canaries[ $version ] );
		update_option( self::CANARY_OPTION, $canaries, false );
		self::ensure_canaries();
	}

	/* ---------------- stored data ---------------- */

	/**
	 * How many stored bank details are encrypted with a version (or
	 * plaintext when $version is null), across withdrawals and approval
	 * requests.
	 *
	 * @param string|null $version 'v1', 'v2', or null for plaintext.
	 * @return int
	 */
	public static function count_encrypted( $version ) {
		global $wpdb;
		$withdrawals = $wpdb->base_prefix . 'woo_wallet_withdrawals';
		$approvals   = $wpdb->base_prefix . 'woo_wallet_approval_requests';
		if ( null === $version ) {
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$withdrawals}` WHERE account_number <> '' AND account_number NOT LIKE 'enc:%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$like   = 'enc:' . $version . ':%';
		$count  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$withdrawals}` WHERE account_number LIKE %s OR iban LIKE %s", $like, $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$approvals}` WHERE payload LIKE %s", '%' . $wpdb->esc_like( 'enc:' . $version . ':' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $count;
	}

	/**
	 * Bring stored bank details up to date: encrypt plaintext withdrawal
	 * details if encryption is on, and re-encrypt anything made with an
	 * older key with the current one. Never runs while a key problem is
	 * detected, and leaves any value it cannot decrypt exactly as it is.
	 *
	 * @param int $limit Most rows to change in one call.
	 * @return array|WP_Error {changed: int, remaining: int}
	 */
	public static function reencrypt_stored( $limit = 500 ) {
		global $wpdb;
		if ( self::key_problems() ) {
			return new WP_Error( 'woo_wallet_key_problem', __( 'The encryption key has changed. Restore the original key before re-encrypting anything.', 'woo-wallet' ) );
		}
		$current     = self::current_version();
		$encrypt_all = self::bank_details_encryption_enabled( (bool) apply_filters( 'woo_wallet_encrypt_bank_details', false ) );
		$update      = function ( $value ) use ( $current, $encrypt_all ) {
			if ( ! is_string( $value ) || '' === $value ) {
				return $value;
			}
			$version = self::version_of( $value );
			if ( null === $version ) {
				return $encrypt_all ? self::encrypt_with( $value, $current ) : $value;
			}
			if ( $version === $current ) {
				return $value;
			}
			$plain = self::decrypt( $value );
			return $plain === $value ? $value : self::encrypt_with( $plain, $current );
		};

		$changed     = 0;
		$withdrawals = $wpdb->base_prefix . 'woo_wallet_withdrawals';
		$rows        = $wpdb->get_results( $wpdb->prepare( "SELECT id, account_number, iban FROM `{$withdrawals}` WHERE account_number <> '' AND ( account_number NOT LIKE %s OR ( iban <> '' AND iban NOT LIKE %s ) ) LIMIT %d", 'enc:' . $current . ':%', 'enc:' . $current . ':%', $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $rows as $row ) {
			$account = $update( $row->account_number );
			$iban    = $update( (string) $row->iban );
			if ( $account !== $row->account_number || $iban !== (string) $row->iban ) {
				$wpdb->update( $withdrawals, array( 'account_number' => $account, 'iban' => $iban ), array( 'id' => $row->id ), array( '%s', '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				++$changed;
			}
		}

		$approvals = $wpdb->base_prefix . 'woo_wallet_approval_requests';
		$old       = 'v2' === $current ? 'enc:v1:' : 'enc:v2:';
		$requests  = $wpdb->get_results( $wpdb->prepare( "SELECT id, payload FROM `{$approvals}` WHERE payload LIKE %s LIMIT %d", '%' . $wpdb->esc_like( $old ) . '%', $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $requests as $request ) {
			$payload = json_decode( (string) $request->payload, true );
			if ( ! is_array( $payload ) ) {
				continue;
			}
			$new = $payload;
			foreach ( array( 'account_number', 'iban' ) as $key ) {
				if ( isset( $new[ $key ] ) && null !== self::version_of( $new[ $key ] ) ) {
					$new[ $key ] = $update( $new[ $key ] );
				}
			}
			if ( $new !== $payload ) {
				$wpdb->update( $approvals, array( 'payload' => wp_json_encode( $new ) ), array( 'id' => $request->id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				++$changed;
			}
		}

		$remaining = ( $encrypt_all ? self::count_encrypted( null ) : 0 ) + self::count_encrypted( 'v2' === $current ? 'v1' : 'v2' );
		return array(
			'changed'   => $changed,
			'remaining' => $remaining,
		);
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
