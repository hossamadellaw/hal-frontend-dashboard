<?php
/**
 * runtime/security/class-secret-store.php — HAL Secret Store (batch 3)
 * ══════════════════════════════════════════════════════════════
 * The contract (architecture §7.6 HAL Secret Store + owner decisions §2.4):
 *   - The key source order is exclusive: the Environment secret, then the PHP
 *     constant HAL_FRONTEND_DASHBOARD_SECRET_KEY outside the repository. The
 *     environment outranks the constant and no DB value can outrank either.
 *     No default derivation from WordPress salts and no generation stored in
 *     the DB; a missing source or Sodium makes the encrypted entry explicitly
 *     blocked (no weaker fallback).
 *   - Sodium AEAD encryption (XChaCha20-Poly1305) with a unique nonce per
 *     value and associated data binding site/secret id/schema version.
 *   - In a separate autoload=no option only ciphertext+nonce+version+key
 *     fingerprint are stored — never plaintext, and it never appears in
 *     export/log/HTML/JS/AJAX.
 *   - A master key change (a different fingerprint) or an AEAD failure leaves
 *     the ciphertext as-is, records no success, and produces a diagnosable
 *     error that raises the integration blocked state in the health monitor.
 *   - Audit metadata: actor/time/action/secret id without the value or
 *     prefix, and no raw request/response logging.
 *   - The interface returns the value only for trusted internal consumption;
 *     deletion is a separate action called from the controller with
 *     POST + nonce + capability.
 * ══════════════════════════════════════════════════════════════
 */

defined( 'ABSPATH' ) || exit;

final class HAL_Frontend_Dashboard_Secret_Store {

	const OPTION_NAME    = 'hal_frontend_dashboard_secrets';
	const SCHEMA_VERSION = 1;

	/** The encryption key env-var/constant name: environment first, then PHP (outside the repository). */
	const KEY_NAME = 'HAL_FRONTEND_DASHBOARD_SECRET_KEY';

	/** Key fingerprint: the first 16 bytes of sha256 of the raw bytes (not the key). */
	const FINGERPRINT_BYTES = 16;

	/** Secret ids: lowercase letters/digits/underscore only — the Registry owns the list. */
	const SECRET_ID_PATTERN = '/\A[a-z0-9_]{1,64}\z/D';

	const ERR_UNAVAILABLE   = 'hal_secret_store_unavailable';
	const ERR_BAD_ID        = 'hal_secret_bad_id';
	const ERR_EMPTY_VALUE   = 'hal_secret_empty_value';
	const ERR_MISSING       = 'hal_secret_missing';
	const ERR_KEY_MISMATCH  = 'hal_secret_key_mismatch';
	const ERR_PERSIST_FAILED = 'hal_secret_persist_failed';
	const ERR_DECRYPT_FAILED = 'hal_secret_decrypt_failed';
	const ERR_UNSUPPORTED_VERSION = 'hal_secret_unsupported_version';

	/**
	 * Availability state: Sodium present AND an external key source present —
	 * both required (B3-04: a missing Sodium alone suffices for the explicit
	 * block). A missing either makes the encrypted entry blocked (the external
	 * sources environment/constant remain usable in the adapters).
	 */
	public static function is_available(): bool {
		return function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' )
			&& null !== self::resolve_key();
	}

	/**
	 * The actual key source: environment | constant | unavailable.
	 * The environment outranks the constant and no DB value can outrank either.
	 */
	public static function get_key_source(): string {
		if ( function_exists( 'getenv' ) && is_string( getenv( self::KEY_NAME ) ) && '' !== getenv( self::KEY_NAME ) ) {
			return 'environment';
		}
		if ( defined( self::KEY_NAME ) && is_string( constant( self::KEY_NAME ) ) && '' !== constant( self::KEY_NAME ) ) {
			return 'constant';
		}
		return 'unavailable';
	}

	/**
	 * The current key fingerprint (hex, 32 chars from the first 16 bytes of
	 * sha256) or null when the key is absent — used for diagnostics and
	 * key-change detection, and is not a secret.
	 */
	public static function get_key_fingerprint(): ?string {
		$key = self::resolve_key();
		return null === $key ? null : substr( hash( 'sha256', $key ), 0, self::FINGERPRINT_BYTES * 2 );
	}

	/**
	 * Is the secret set? An existence check only — no decryption (render-safe).
	 */
	public static function has( string $secret_id ): bool {
		if ( ! self::is_valid_secret_id( $secret_id ) ) {
			return false;
		}
		$records = self::read_records();
		return isset( $records[ $secret_id ] ) && is_array( $records[ $secret_id ] );
	}

	/**
	 * Stores an encrypted secret value. A failure (availability/verification)
	 * returns a WP_Error and writes nothing — no plaintext fallback and no
	 * weaker algorithm.
	 *
	 * @return true|WP_Error
	 */
	public static function set( string $secret_id, string $plaintext ) {
		if ( ! self::is_valid_secret_id( $secret_id ) ) {
			return new WP_Error( self::ERR_BAD_ID, 'Invalid secret id.' );
		}
		if ( '' === $plaintext ) {
			return new WP_Error( self::ERR_EMPTY_VALUE, 'The secret value must not be empty.' );
		}
		$key = self::resolve_key();
		if ( null === $key || ! function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' ) ) {
			return new WP_Error( self::ERR_UNAVAILABLE, 'HAL encrypted storage is blocked: no external key source or sodium extension.' );
		}

		$nonce = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
		$ad    = self::associated_data( $secret_id );
		$ct    = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $plaintext, $ad, $nonce, $key );
		if ( false === $ct ) {
			return new WP_Error( self::ERR_DECRYPT_FAILED, 'Encryption failed.' );
		}

		$records       = self::read_records();
		$records[ $secret_id ] = array(
			'v'      => self::SCHEMA_VERSION,
			'key_fp' => self::get_key_fingerprint(),
			'nonce'  => base64_encode( $nonce ),
			'ct'     => base64_encode( $ct ),
		);
		if ( ! update_option( self::OPTION_NAME, $records, false ) && get_option( self::OPTION_NAME ) !== $records ) {
			return new WP_Error( self::ERR_PERSIST_FAILED, 'Persisting the encrypted secret failed.' );
		}

		self::audit( 'set', $secret_id );
		return true;
	}

	/**
	 * Decryption for trusted internal consumption only (an adapter at call
	 * time — not inside the front-end page render). Wrong keys return a
	 * WP_Error: MISSING / KEY_MISMATCH / DECRYPT_FAILED — the ciphertext
	 * stays as-is and no success is recorded on any failure.
	 *
	 * @return string|WP_Error
	 */
	public static function get( string $secret_id ) {
		if ( ! self::is_valid_secret_id( $secret_id ) ) {
			return new WP_Error( self::ERR_BAD_ID, 'Invalid secret id.' );
		}
		$records = self::read_records();
		if ( ! isset( $records[ $secret_id ] ) || ! is_array( $records[ $secret_id ] ) ) {
			return new WP_Error( self::ERR_MISSING, 'The secret is not configured.' );
		}
		$record = $records[ $secret_id ];
		if ( ! isset( $record['v'], $record['key_fp'], $record['nonce'], $record['ct'] ) ) {
			return new WP_Error( self::ERR_DECRYPT_FAILED, 'Malformed ciphertext record.' );
		}
		// B3-05: reject unsupported ciphertext copies explicitly — the bytes stay
		// as-is with no silent wipe, and the state stays diagnosable.
		if ( ! is_int( $record['v'] ) || self::SCHEMA_VERSION !== $record['v'] ) {
			return new WP_Error( self::ERR_UNSUPPORTED_VERSION, 'Unsupported ciphertext schema version; the stored bytes are unchanged.' );
		}
		$key = self::resolve_key();
		if ( null === $key || ! function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt' ) ) {
			return new WP_Error( self::ERR_UNAVAILABLE, 'HAL encrypted storage is unavailable: no external key source or sodium extension.' );
		}
		if ( ! is_string( $record['key_fp'] ) || ! hash_equals( $record['key_fp'], (string) self::get_key_fingerprint() ) ) {
			self::audit( 'key_mismatch', $secret_id );
			// Master key change: the ciphertext stays as-is (not wiped) and yields
			// a diagnosable blocked state — §8.4.
			return new WP_Error( self::ERR_KEY_MISMATCH, 'The master key changed; the stored ciphertext is kept for diagnosis.' );
		}
		$ciphertext = base64_decode( (string) $record['ct'], true );
		$nonce      = base64_decode( (string) $record['nonce'], true );
		if ( ! is_string( $ciphertext ) || ! is_string( $nonce ) ) {
			return new WP_Error( self::ERR_DECRYPT_FAILED, 'Malformed ciphertext record; the stored bytes are unchanged.' );
		}
		try {
			$plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
				$ciphertext,
				self::associated_data( $secret_id ),
				$nonce,
				$key
			);
		} catch ( Throwable $failure ) {
			// A corrupted record or a wrong-length nonce: fail-closed — no exception
			// escapes to the consumer, and the stored bytes stay as-is for diagnosis.
			return new WP_Error( self::ERR_DECRYPT_FAILED, 'AEAD verification failed; the stored ciphertext is unchanged.' );
		}
		if ( false === $plaintext ) {
			return new WP_Error( self::ERR_DECRYPT_FAILED, 'AEAD verification failed; the stored ciphertext is unchanged.' );
		}
		return $plaintext;
	}

	/**
	 * Deletion is a separate action (POST + nonce + capability at the
	 * controller callers). It touches nothing but the requested secret.
	 */
	public static function delete( string $secret_id ): bool {
		if ( ! self::is_valid_secret_id( $secret_id ) ) {
			return false;
		}
		$records = self::read_records();
		if ( ! isset( $records[ $secret_id ] ) ) {
			return false;
		}
		unset( $records[ $secret_id ] );
		$written = update_option( self::OPTION_NAME, $records, false );
		self::audit( 'delete', $secret_id );
		return (bool) $written;
	}

	/**
	 * Secret id validity: a closed pattern — and the allowed names are the
	 * Registry list (no free-form key names from input).
	 */
	public static function is_valid_secret_id( string $secret_id ): bool {
		return 1 === preg_match( self::SECRET_ID_PATTERN, $secret_id );
	}

	/**
	 * B3-05: the associated data binds the site beyond the hostname alone —
	 * host + path from the home URL, so two sites with different paths on the
	 * same host cannot swap ciphertext. In addition to the secret id and the
	 * schema version.
	 */
	private static function associated_data( string $secret_id ): string {
		$site = '';
		if ( function_exists( 'get_home_url' ) ) {
			$url_parts = wp_parse_url( get_home_url() );
			$site = (string) ( $url_parts['host'] ?? '' ) . (string) ( $url_parts['path'] ?? '' );
		}
		return wp_json_encode(
			array(
				'site'   => $site,
				'id'     => $secret_id,
				'schema' => self::SCHEMA_VERSION,
			)
		);
	}

	/**
	 * B3-04: the unavailability reason is refined — sodium_missing, key_missing
	 * or none. Consumed by the health monitor and the diagnostics screen
	 * instead of one vague unified diagnosis.
	 */
	public static function get_blocker(): string {
		if ( ! function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' ) ) {
			return 'sodium_missing';
		}
		return null === self::resolve_key() ? 'key_missing' : 'none';
	}

	/**
	 * @return array<string, array{v:int, key_fp:?string, nonce:string, ct:string}>
	 */
	private static function read_records(): array {
		$stored = get_option( self::OPTION_NAME, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * The key: raw 32 bytes, or 64 hex chars, or base64 decoding to 32 bytes.
	 * Anything else = unavailable (no derivation, no weaker alternative).
	 *
	 * @return string|null
	 */
	private static function resolve_key(): ?string {
		$raw = null;
		if ( function_exists( 'getenv' ) ) {
			$env = getenv( self::KEY_NAME );
			if ( is_string( $env ) && '' !== $env ) {
				$raw = $env;
			}
		}
		if ( null === $raw && defined( self::KEY_NAME ) ) {
			$constant = constant( self::KEY_NAME );
			if ( is_string( $constant ) && '' !== $constant ) {
				$raw = $constant;
			}
		}
		if ( null === $raw ) {
			return null;
		}
		if ( self::KEY_BYTES === strlen( $raw ) ) {
			return $raw;
		}
		if ( 1 === preg_match( '/\A[0-9a-fA-F]{64}\z/D', $raw ) ) {
			$decoded = hex2bin( $raw );
			return is_string( $decoded ) ? $decoded : null;
		}
		$decoded = base64_decode( $raw, true );
		return is_string( $decoded ) && self::KEY_BYTES === strlen( $decoded ) ? $decoded : null;
	}

	/**
	 * The secretbox key length: a fixed 32 bytes per the algorithm spec
	 * (matches SODIUM_CRYPTO_SECRETBOX_KEYBYTES). Deliberately a literal
	 * value — sodium constants are not touched at file load so it cannot
	 * fatal with the extension absent.
	 */
	const KEY_BYTES = 32;

	/**
	 * Audit metadata with no value, no prefix and no raw request/response:
	 * an internal hook + a refined log line.
	 *
	 * @param string $action set|delete|key_mismatch
	 */
	private static function audit( string $action, string $secret_id ): void {
		$entry = array(
			'action'    => $action,
			'secret_id' => $secret_id,
			'actor'     => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
			'time'      => gmdate( 'c' ),
		);
		do_action( 'hal_frontend_dashboard_secret_audit', $entry );
		error_log( 'HAL secret audit: action=' . $action . ' secret_id=' . $secret_id . ' actor=' . (string) $entry['actor'] );
	}
}
