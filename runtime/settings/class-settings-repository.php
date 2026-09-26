<?php
/**
 * runtime/settings/class-settings-repository.php — Settings Repository (الدفعة 3)
 * ══════════════════════════════════════════════════════════════
 * العقد (معمارية §14 و§8.3 وقرارات المالك §2.3/§2.4):
 *   - schema/version/defaults وgetters مكتوبة الأنواع مع validation
 *     صارم؛ لا مفاتيح مجهولة ولا shapes حرة.
 *   - option واحدة غير حساسة باسم ثابت وautoload=no؛ لا سر ولا
 *     ciphertext هنا (Secret Store ملف مستقل) ولا بيانات مستخدم.
 *   - الإعدادات بيانات موقع خارج releases/state وتبقى عبر
 *     update/rollback (لا تدخل release manifest — §2.5).
 *   - defaults تحافظ على السلوك الوظيفي المرحل: كل المميزات owner
 *     enabled=true (القرار الفعلي يجمع لاحقًا registry/capability/
 *     ownership في دفعاتها — هنا الإعداد وحده لا يمنح صلاحية مورد)؛
 *     الشعار attachment id=0 (fallback النصي HAL قرار المالك)؛
 *     ai_preference=auto (enum من المرجع الموحد §7.4).
 *   - cache داخل الطلب يبطل عند الحفظ وخلال invalidate_cache().
 *   - لا تقرأ $_POST ولا options مباشرة من المتحكم: المتحكم يمرر
 *     قيمًا منقاة إلى save() وحدها.
 * ══════════════════════════════════════════════════════════════
 */

defined( 'ABSPATH' ) || exit;

final class HAL_Frontend_Dashboard_Settings_Repository {

	const SCHEMA_VERSION = '1.0.0';
	const OPTION_NAME    = 'hal_frontend_dashboard_settings';

	/**
	 * Capability الإدارة المعتمد (قرار المالك §2.3): runtime checks
	 * تستخدم capability فقط، والمنح الافتراضي من Installer.
	 */
	const CAPABILITY = 'manage_hal_frontend_dashboard';

	/**
	 * المميزات الحادية عشرة المعتمدة للإصدار الأول (معمارية §7.6
	 * Feature toggles) — لا toggle خارج هذه القائمة.
	 */
	const FEATURES = array(
		'posts', 'files', 'inbox', 'members', 'amelia', 'finance', 'store',
		'wpml_translations', 'rank_math_seo', 'ultimate_member_profile', 'ai',
	);

	/**
	 * قيم تفضيل AI المسموحة (المرجع الموحد §7.4): auto أو wp_ai_client
	 * أو direct_key — يحررها manage_options فقط (يُنفَّذ في المتحكم).
	 */
	const AI_PREFERENCES = array( 'auto', 'wp_ai_client', 'direct_key' );

	/**
	 * أنواع صور الشعار المسموحة (معمارية §7.6 Branding): PNG/JPEG/WebP؛
	 * SVG مرفوض افتراضيًا ولا يقبل إلا Change Request مستقل.
	 */
	const BRANDING_ALLOWED_MIME = array( 'image/png', 'image/jpeg', 'image/webp' );

	/** @var array<string,mixed>|null */
	private static $cache = null;

	/**
	 * الحالة الكاملة بعد الدمج مع defaults والتحقق من schema.
	 *
	 * @return array{schema_version:string, features:array<string,bool>, branding_attachment_id:int, ai_preference:string}
	 */
	public static function get_all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$stored = get_option( self::OPTION_NAME, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$features = array();
		foreach ( self::FEATURES as $feature ) {
			$features[ $feature ] = isset( $stored['features'][ $feature ] )
				? (bool) $stored['features'][ $feature ]
				: true;
		}

		$attachment_id = isset( $stored['branding_attachment_id'] ) ? (int) $stored['branding_attachment_id'] : 0;

		$ai_preference = 'auto';
		if ( isset( $stored['ai_preference'] ) && in_array( $stored['ai_preference'], self::AI_PREFERENCES, true ) ) {
			$ai_preference = (string) $stored['ai_preference'];
		}

		self::$cache = array(
			'schema_version'         => self::SCHEMA_VERSION,
			'features'               => $features,
			'branding_attachment_id' => $attachment_id > 0 ? $attachment_id : 0,
			'ai_preference'          => $ai_preference,
		);
		return self::$cache;
	}

	/**
	 * حالة مميزة واحدة — مفاتيح خارج القائمة تفشل مغلقًا (false).
	 */
	public static function is_feature_enabled( string $feature ): bool {
		$all = self::get_all();
		return isset( $all['features'][ $feature ] ) && true === $all['features'][ $feature ];
	}

	public static function get_branding_attachment_id(): int {
		$attachment_id = self::get_all()['branding_attachment_id'];
		if ( $attachment_id > 0 && is_wp_error( self::validate_branding_attachment_id( $attachment_id ) ) ) {
			// المرفق حُذف أو لم يعد صورة مقروءة من الأنواع المعتمدة:
			// fallback نصي آمن (قرار المالك §2.3) — يُعاد 0 والمستهلك
			// يعرض العلامة النصية HAL.
			return 0;
		}
		return $attachment_id;
	}

	/**
	 * تفضيل استراتيجية AI — enum مغلق (auto/wp_ai_client/direct_key).
	 */
	public static function get_ai_preference(): string {
		return self::get_all()['ai_preference'];
	}

	/**
	 * التحقق من أن attachment id صورة قابلة للقراءة ضمن الأنواع
	 * المسموحة (PNG/JPEG/WebP؛ SVG مرفوض افتراضيًا — §7.6). غياب/حذف
	 * المرفق يرفض هنا ويبقى الـ0 (fallback نصي آمن).
	 *
	 * @return true|WP_Error
	 */
	public static function validate_branding_attachment_id( int $attachment_id ) {
		if ( $attachment_id <= 0 ) {
			return new WP_Error( 'hal_settings_branding_invalid_id', 'Invalid attachment id.' );
		}
		$post = get_post( $attachment_id );
		if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'hal_settings_branding_not_attachment', 'The id is not a Media Library attachment.' );
		}
		$mime = (string) get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, self::BRANDING_ALLOWED_MIME, true ) ) {
			return new WP_Error( 'hal_settings_branding_mime_rejected', 'Only PNG, JPEG and WebP images are accepted.' );
		}
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return new WP_Error( 'hal_settings_branding_not_image', 'The attachment is not a readable image.' );
		}
		return true;
	}

	/**
	 * الكتابة الوحيدة للإعدادات: تحقق صارم لكل مفتاح معروف، ورفض
	 * المفاتيح المجهولة كليًا (لا shapes مجهولة — §14). لا تُقبل
	 * أسرار هنا إطلاقًا.
	 *
	 * @param array{features?:array<string,bool>, branding_attachment_id?:int, ai_preference?:string} $settings
	 * @return true|WP_Error
	 */
	public static function save( array $settings ) {
		if ( isset( $settings['schema_version'] ) ) {
			return new WP_Error( 'hal_settings_reserved_key', 'schema_version is managed internally.' );
		}

		$current = self::get_all();

		if ( array_key_exists( 'features', $settings ) ) {
			$features = $settings['features'];
			if ( ! is_array( $features ) ) {
				return new WP_Error( 'hal_settings_features_shape', 'features must be an array of booleans.' );
			}
			foreach ( $features as $feature => $enabled ) {
				if ( ! in_array( (string) $feature, self::FEATURES, true ) || ! is_bool( $enabled ) ) {
					return new WP_Error( 'hal_settings_feature_invalid', 'Unknown feature key or non-boolean value.' );
				}
			}
			foreach ( $features as $feature => $enabled ) {
				$current['features'][ (string) $feature ] = $enabled;
			}
		}

		if ( array_key_exists( 'branding_attachment_id', $settings ) ) {
			$attachment_id = $settings['branding_attachment_id'];
			if ( ! is_int( $attachment_id ) || $attachment_id < 0 ) {
				return new WP_Error( 'hal_settings_branding_shape', 'branding_attachment_id must be a non-negative integer.' );
			}
			if ( $attachment_id > 0 ) {
				$validated = self::validate_branding_attachment_id( $attachment_id );
				if ( is_wp_error( $validated ) ) {
					return $validated;
				}
			}
			$current['branding_attachment_id'] = $attachment_id;
		}

		if ( array_key_exists( 'ai_preference', $settings ) ) {
			$preference = $settings['ai_preference'];
			if ( ! is_string( $preference ) || ! in_array( $preference, self::AI_PREFERENCES, true ) ) {
				return new WP_Error( 'hal_settings_ai_preference_invalid', 'ai_preference must be one of the allowlisted values.' );
			}
			$current['ai_preference'] = $preference;
		}

		foreach ( $settings as $key => $value ) {
			if ( ! in_array( $key, array( 'features', 'branding_attachment_id', 'ai_preference' ), true ) ) {
				return new WP_Error( 'hal_settings_unknown_key', 'Unknown settings key.' );
			}
		}

		$stored_raw = get_option( self::OPTION_NAME, array() );
		$stored_raw = is_array( $stored_raw ) ? $stored_raw : array();

		// B3-PERSIST (§8.4: لا downgrade تلقائي، والتوافق خلال نافذة
		// rollback): الكتابة تبدأ من المخزن كما هو — أي مفتاح أو قسم أو
		// مفتاح مميزات كتبته نسخة أحدث (schema أعلى أو مفاتيح لا تعرفها
		// هذه النسخة) يبقى سليمًا، وتضع هذه النسخة مفاتيحها المعروفة
		// فوقه فقط. نسخة schema الأحدث لا تُخفض؛ هذه النسخة لا تخترع
		// migration — تقرأ ما تعرفه وتتسامح مع البقية.
		$stored_schema = isset( $stored_raw['schema_version'] ) && is_string( $stored_raw['schema_version'] )
			? $stored_raw['schema_version']
			: null;
		$newer_schema  = null !== $stored_schema && version_compare( $stored_schema, self::SCHEMA_VERSION, '>' );
		$payload       = $stored_raw;

		$stored_features = isset( $stored_raw['features'] ) && is_array( $stored_raw['features'] )
			? $stored_raw['features']
			: array();
		$merged_features = $stored_features;
		foreach ( $current['features'] as $feature_key => $feature_value ) {
			$merged_features[ $feature_key ] = $feature_value;
		}
		$payload['features'] = $merged_features;
		$payload['branding_attachment_id'] = $current['branding_attachment_id'];
		$payload['ai_preference'] = $current['ai_preference'];
		if ( ! $newer_schema ) {
			$payload['schema_version'] = self::SCHEMA_VERSION;
		}

		// B3-02: التمييز بين عدم التغيير وفشل الكتابة — update_option
		// يعيد false في الحالتين، فالمقارنة الصريحة تمنع إعلان نجاح
		// كاذب عند فشل كتابة قيمة مختلفة فعلاً.
		if ( $stored_raw === $payload ) {
			self::invalidate_cache();
			return true;
		}

		$written = update_option( self::OPTION_NAME, $payload, false );
		self::invalidate_cache();
		if ( false === $written ) {
			return new WP_Error( 'hal_settings_write_failed', 'The settings write failed; the previously stored values remain intact.' );
		}
		return true;
	}

	/**
	 * إبطال كاش الطلب — يستدعى بعد الحفظ ويتاح للمستهلكين عند حاجة
	 * إعادة قراءة قسرية (قرار قدرة واحد، §8.3).
	 */
	public static function invalidate_cache(): void {
		self::$cache = null;
	}
}
