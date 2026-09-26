<?php
/**
 * runtime/security/class-secret-store.php — HAL Secret Store (الدفعة 3)
 * ══════════════════════════════════════════════════════════════
 * العقد (معمارية §7.6 HAL Secret Store + قرارات المالك §2.4):
 *   - ترتيب مصدر المفتاح حصريًا: Environment secret ثم PHP constant
 *     HAL_FRONTEND_DASHBOARD_SECRET_KEY خارج المستودع. البيئة تقدَم
 *     على الثابت ولا يمكن لقيمة DB تجاوزهما. لا اشتقاق افتراضي من
 *     WordPress salts ولا توليد يخزن في DB؛ غياب المصدر أو Sodium
 *     يجعل الإدخال المشفر blocked صراحة (بلا fallback أضعف).
 *   - تشفير Sodium AEAD (XChaCha20-Poly1305) بـnonce فريد لكل قيمة
 *     وassociated data يربط site/secret id/schema version.
 *   - في option منفصلة autoload=no تُخزن ciphertext+nonce+version+
 *     key fingerprint فقط — لا plaintext أبدًا ولا يظهر في
 *     export/log/HTML/JS/AJAX.
 *   - تغيّر master key (fingerprint مختلف) أو فشل AEAD يترك
 *     ciphertext كما هو، ولا يسجل نجاحًا، وينتج خطأ قابلًا للتشخيص
 *     يفعّل حالة integration blocked في مراقب الصحة.
 *   - audit metadata: actor/time/action/secret id دون قيمة أو prefix،
 *     ولا تسجيل request/response خام.
 *   - الواجهة لا تعيد القيمة إلا للاستهلاك الداخلي الموثوق؛ الحذف
 *     إجراء مستقل ويستدعى من المتحكم بـPOST + nonce + capability.
 * ══════════════════════════════════════════════════════════════
 */

defined( 'ABSPATH' ) || exit;

final class HAL_Frontend_Dashboard_Secret_Store {

	const OPTION_NAME    = 'hal_frontend_dashboard_secrets';
	const SCHEMA_VERSION = 1;

	/** اسم متغير/ثابت مفتاح التشفير في البيئة ثم PHP (خارج المستودع). */
	const KEY_NAME = 'HAL_FRONTEND_DASHBOARD_SECRET_KEY';

	/** بصمة المفتاح: أول 16 بايت من sha256 للبايتات الخام (ليست المفتاح). */
	const FINGERPRINT_BYTES = 16;

	/** معرفات السر: أحرف صغيرة/أرقام/شرطة سفلية فقط — والقائمة تملكها Registry. */
	const SECRET_ID_PATTERN = '/\A[a-z0-9_]{1,64}\z/D';

	const ERR_UNAVAILABLE   = 'hal_secret_store_unavailable';
	const ERR_BAD_ID        = 'hal_secret_bad_id';
	const ERR_EMPTY_VALUE   = 'hal_secret_empty_value';
	const ERR_MISSING       = 'hal_secret_missing';
	const ERR_KEY_MISMATCH  = 'hal_secret_key_mismatch';
	const ERR_DECRYPT_FAILED = 'hal_secret_decrypt_failed';
	const ERR_UNSUPPORTED_VERSION = 'hal_secret_unsupported_version';

	/**
	 * حالة التوفر: Sodium موجود ومصدر مفتاح خارجي حاضر — كلاهما مطلوب
	 * (B3-04: غياب Sodium وحده يكفي للحجب الصريح). غياب أيٍّ منهما
	 * يجعل الإدخال المشفر blocked (المصادر الخارجية environment/
	 * constant تبقى قابلة للاستخدام في الأدابتر).
	 */
	public static function is_available(): bool {
		return function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' )
			&& null !== self::resolve_key();
	}

	/**
	 * مصدر المفتاح الفعلي: environment | constant | unavailable.
	 * البيئة تتقدم على الثابت ولا يمكن لقيمة DB تجاوزهما.
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
	 * بصمة المفتاح الحالي (hex 32 حرفًا من أول 16 بايت من sha256) أو
	 * null عند غياب المفتاح — تُستخدم للتشخيص وكشف تغيّر المفتاح،
	 * وليست سرًا.
	 */
	public static function get_key_fingerprint(): ?string {
		$key = self::resolve_key();
		return null === $key ? null : substr( hash( 'sha256', $key ), 0, self::FINGERPRINT_BYTES * 2 );
	}

	/**
	 * هل السر مضبوط؟ فحص وجود فقط — بلا فك تشفير (render آمن).
	 */
	public static function has( string $secret_id ): bool {
		if ( ! self::is_valid_secret_id( $secret_id ) ) {
			return false;
		}
		$records = self::read_records();
		return isset( $records[ $secret_id ] ) && is_array( $records[ $secret_id ] );
	}

	/**
	 * تخزين قيمة سر مشفرة. الفشل (توفّر/تحقق) يعيد WP_Error ولا يكتب
	 * شيئًا — لا fallback إلى plaintext ولا خوارزمية أضعف.
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
			return new WP_Error( self::ERR_DECRYPT_FAILED, 'Persisting the encrypted secret failed.' );
		}

		self::audit( 'set', $secret_id );
		return true;
	}

	/**
	 * فك التشفير للاستهلاك الداخلي الموثوق فقط (أدابتر وقت النداء —
	 * ليس داخل page render للواجهة). المفاتيح المغلوطة تعيد WP_Error:
	 * MISSING / KEY_MISMATCH / DECRYPT_FAILED — ciphertext يبقى كما هو
	 * ولا يُسجل نجاح في أي حالة فشل.
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
		// B3-05: رفض نسخ ciphertext غير المدعومة صراحة — البايتات تبقى
		// كما هي بلا مسح صامت، والحالة قابلة للتشخيص.
		if ( ! is_int( $record['v'] ) || self::SCHEMA_VERSION !== $record['v'] ) {
			return new WP_Error( self::ERR_UNSUPPORTED_VERSION, 'Unsupported ciphertext schema version; the stored bytes are unchanged.' );
		}
		$key = self::resolve_key();
		if ( null === $key || ! function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt' ) ) {
			return new WP_Error( self::ERR_UNAVAILABLE, 'HAL encrypted storage is unavailable: no external key source or sodium extension.' );
		}
		if ( ! is_string( $record['key_fp'] ) || ! hash_equals( $record['key_fp'], (string) self::get_key_fingerprint() ) ) {
			// تغيّر master key: ciphertext يبقى كما هو (لا يمسح) وينتج
			// حالة blocked قابلة للتشخيص — §8.4.
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
			// سجل تالف أو nonce بطول خاطئ: فشل مغلق — لا استثناء يهرب
			// للمستهلك، والبايتات المخزنة تبقى كما هي للتشخيص.
			return new WP_Error( self::ERR_DECRYPT_FAILED, 'AEAD verification failed; the stored ciphertext is unchanged.' );
		}
		if ( false === $plaintext ) {
			return new WP_Error( self::ERR_DECRYPT_FAILED, 'AEAD verification failed; the stored ciphertext is unchanged.' );
		}
		return $plaintext;
	}

	/**
	 * حذف إجراء مستقل (POST + nonce + capability عند المنادي من
	 * المتحكم). لا يمس غير السر المطلوب.
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
	 * صلاحية معرف السر: نمط مغلق — والأسماء المسموحة قائمة Registry
	 * (لا key names حرة من input).
	 */
	public static function is_valid_secret_id( string $secret_id ): bool {
		return 1 === preg_match( self::SECRET_ID_PATTERN, $secret_id );
	}

	/**
	 * B3-05: associated data يربط الموقع بما يتجاوز hostname وحده —
	 * host + path من home URL، فموقعان بمسارين مختلفين على المضيف
	 * نفسه غير قابلين لتبادل ciphertext. إضافةً إلى secret id
	 * وschema version.
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
	 * B3-04: سبب عدم التوفر منقحًا — sodium_missing أو key_missing أو
	 * none. يستهلكه مراقب الصحة وشاشة التشخيص بدل تشخيص موحد غامض.
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
	 * المفتاح: raw 32 بايت، أو hex 64 محرفًا، أو base64 يقابل 32 بايت.
	 * أي شيء آخر = unavailable (بلا اشتقاق ولا أضعف بديل).
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
	 * طول مفتاح secretbox: 32 بايت ثابتة بمواصفة الخوارزمية (يطابق
	 * SODIUM_CRYPTO_SECRETBOX_KEYBYTES). قيمة حرفية عمدًا — ثوابت
	 * sodium لا تُلمس عند تحميل الملف كي لا يفتال مع غياب الامتداد.
	 */
	const KEY_BYTES = 32;

	/**
	 * audit metadata بلا قيمة ولا prefix ولا request/response خام:
	 * hook داخلي + سطر log منقح.
	 *
	 * @param string $action set|delete|get_failed
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
