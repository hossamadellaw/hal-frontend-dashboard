<?php
/**
 * runtime/admin/class-admin-controller.php — Backend Administration (الدفعة 3)
 * ══════════════════════════════════════════════════════════════
 * العقد (معمارية §7.6/§8.3/§14 + قرارات المالك §2.3/§2.4):
 *   - قائمة واحدة «HAL Frontend Dashboard» محروسة بـcapability
 *     manage_hal_frontend_dashboard حصرًا (لا role checks)؛ المنح
 *     الافتراضي من Installer.
 *   - التبويبات الست المعتمدة: General | Branding | Features |
 *     Integrations | AI | Status & Diagnostics.
 *   - كل كتابة POST إلى admin-post.php مع nonce وcapability؛
 *     القيم تُنقى وتُمرر إلى Settings Repository/Secret Store بعقود
 *     typed/validated — المتحكم لا يقرأ options مباشرة ولا يكتبها.
 *   - لا secrets في HTML إطلاقًا: تُعرض configured/source/health فقط؛
 *     الاستبدال يتطلب قيمة كاملة، والحذف إجراء مستقل POST+nonce.
 *   - معرفات الأسرار من الـPOST تُقبل فقط ضمن allowlist الـRegistry
 *     (لا key names حرة)؛ لا provider URLs من input.
 *   - تحذير Connector DB إلزامي في تبويب AI (وليس Maximum Protection)؛
 *     لا streaming/embeddings في V1.
 *   - أصول الواجهة معزولة تحت screen id الخاص بالمشروع ولا تلمس
 *     wp-admin العام.
 * ══════════════════════════════════════════════════════════════
 */

defined( 'ABSPATH' ) || exit;

final class HAL_Frontend_Dashboard_Admin_Controller {

	const MENU_SLUG      = 'hal-frontend-dashboard';
	const NONCE_ACTION   = 'hal_frontend_dashboard_settings';
	const NONCE_FIELD    = 'hal_frontend_dashboard_nonce';
	const STYLE_HANDLE   = 'hal-frontend-dashboard-admin-settings';
	const SCRIPT_HANDLE  = 'hal-frontend-dashboard-admin-settings-js';

	const TAB_GENERAL    = 'general';
	const TAB_BRANDING   = 'branding';
	const TAB_FEATURES   = 'features';
	const TAB_INTEGRATIONS = 'integrations';
	const TAB_AI         = 'ai';
	const TAB_STATUS     = 'status';

	const ACTION_SAVE_SETTINGS = 'hal_frontend_dashboard_save_settings';
	const ACTION_SET_SECRET    = 'hal_frontend_dashboard_set_secret';
	const ACTION_DELETE_SECRET = 'hal_frontend_dashboard_delete_secret';
	const ACTION_RUN_HEALTH    = 'hal_frontend_dashboard_run_health';

	/**
	 * v1.1.1 (W4): توصيف Features الاثنا عشر — المفاتيح أعضاء
	 * FEATURES حرفيًا (settings-repository:42–46) والمفتاح بلا نص
	 * يُرندر بلا فقرة. إفصاح موثق: صياغة جلسة D+E الأصلية غير موجودة
	 * في الأرشيف المتاح؛ صيغت هذه بنمط نصوص W5 المعتمدة حرفيًا، ونص
	 * rich_editor هو نص العقد الحرفي من W5. نصوص إنجليزية عبر __()
	 * بنطاق المشروع — لا hossam_t (للواجهة الأمامية حصرًا).
	 */
	const FEATURE_DESCRIPTIONS = array(
		'posts'                   => 'Enables the articles panel where writers create, edit and manage posts.',
		'files'                   => 'Enables the files panel for browsing the media library.',
		'inbox'                   => 'Enables the inbox panel for reading submitted contact messages.',
		'members'                 => 'Enables the members panel for viewing registered user profiles.',
		'amelia'                  => 'Enables the bookings panel backed by the Amelia plugin.',
		'finance'                 => 'Enables the finance panel backed by WooCommerce orders.',
		'store'                   => 'Enables the store panel backed by WooCommerce products.',
		'wpml_translations'       => 'Enables the translations panel backed by WPML.',
		'rank_math_seo'           => 'Enables the SEO writing panel backed by Rank Math.',
		'ultimate_member_profile' => 'Enables the profile panel backed by Ultimate Member.',
		'ai'                      => 'Enables the AI writing features and their strategy and key settings.',
		'rich_editor'             => 'Master switch for the rich editor surface. Off overrides the mode above to off.',
	);

	/**
	 * تسجيل الـhooks عند تحميل الـRuntime (من bootstrap) — بلا أي
	 * استدعاءات WordPress أخرى هنا.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE_SETTINGS, array( self::class, 'handle_save_settings' ) );
		add_action( 'admin_post_' . self::ACTION_SET_SECRET, array( self::class, 'handle_set_secret' ) );
		add_action( 'admin_post_' . self::ACTION_DELETE_SECRET, array( self::class, 'handle_delete_secret' ) );
		add_action( 'admin_post_' . self::ACTION_RUN_HEALTH, array( self::class, 'handle_run_health' ) );
	}

	public static function register_menu(): void {
		add_menu_page(
			'HAL Frontend Dashboard',
			'HAL Frontend Dashboard',
			HAL_Frontend_Dashboard_Settings_Repository::CAPABILITY,
			self::MENU_SLUG,
			array( self::class, 'render_page' ),
			'dashicons-layout',
			58
		);
	}

	/**
	 * الأصول على شاشة المشروع حصرًا — لا تأثير في wp-admin العام (§14).
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook_suffix ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style(
			self::STYLE_HANDLE,
			HAL_FRONTEND_DASHBOARD_RUNTIME_URL . 'assets/css/admin-settings.css',
			array(),
			(string) HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION
		);
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			HAL_FRONTEND_DASHBOARD_RUNTIME_URL . 'assets/js/admin-settings.js',
			array(),
			(string) HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION,
			true
		);
		wp_localize_script(
			self::SCRIPT_HANDLE,
			'halFrontendDashboardAdmin',
			array(
				'mediaTitle'        => __( 'Choose the dashboard logo', 'hal-frontend-dashboard' ),
				'mediaButton'       => __( 'Use this image', 'hal-frontend-dashboard' ),
				'submitting'        => __( 'Saving…', 'hal-frontend-dashboard' ),
				'mediaUnavailable'  => __( 'The media library is not available in this browser session.', 'hal-frontend-dashboard' ),
				'selectedLabel'     => __( 'Selected logo attachment id:', 'hal-frontend-dashboard' ),
				// §7.6: رفع Media جديد يحتاج upload_files — القيود الخادمية
				// لووردبريس نفسه، وهذا تلميح واجهة يخفي رافع الملفات.
				'canUploadFiles'    => current_user_can( 'upload_files' ),
			)
		);
	}

	/**
	 * هل السياق الحالي يملك حق الوصول؟ capability فقط — لا role checks.
	 */
	public static function current_user_can_manage(): bool {
		return function_exists( 'current_user_can' ) && current_user_can( HAL_Frontend_Dashboard_Settings_Repository::CAPABILITY );
	}

	/**
	 * قراءة قيمة نصية واحدة من POST — is_string حصرًا: المصفوفات
	 * والقيم غير النصية تعود null بلا أي تحويل أو تنبيه (B3-07، فصل
	 * قراءة الطلب عن بقية منطق المتحكم وفق §8.3).
	 */
	private static function post_string( string $key ): ?string {
		if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) {
			return null;
		}
		return (string) wp_unslash( $_POST[ $key ] );
	}

	/**
	 * قراءة قيمة نصية واحدة من GET — نفس عقد post_string.
	 */
	private static function get_string( string $key ): ?string {
		if ( ! isset( $_GET[ $key ] ) || ! is_string( $_GET[ $key ] ) ) {
			return null;
		}
		return (string) wp_unslash( $_GET[ $key ] );
	}

	/**
	 * nonce صالح لهذا الإجراء؟
	 */
	private static function nonce_ok( string $subaction ): bool {
		$nonce = self::post_string( self::NONCE_FIELD );
		return null !== $nonce && '' !== $nonce
			&& function_exists( 'wp_verify_nonce' )
			&& 1 === wp_verify_nonce( $nonce, self::NONCE_ACTION . ':' . $subaction );
	}

	/**
	 * التبويب من GET ضمن الallowlist — لا سياق حر.
	 */
	private static function requested_tab(): string {
		$tab = self::get_string( 'tab' );
		$tab = null === $tab ? self::TAB_GENERAL : sanitize_key( $tab );
		return in_array( $tab, array( self::TAB_GENERAL, self::TAB_BRANDING, self::TAB_FEATURES, self::TAB_INTEGRATIONS, self::TAB_AI, self::TAB_STATUS ), true )
			? $tab
			: self::TAB_GENERAL;
	}

	/**
	 * v1.1.1 (E3): تبويب إحالة معالجات السر من POST — الغائب يبقى
	 * TAB_AI (توافق خلفي مع نماذج تبويب AI القائمة)، والحاضر يُنقى
	 * بsanitize_key ثم يُختبر على allowlist التبويبات الست نفسها
	 * (نمط requested_tab حرفيًا) — الخارج عنها يرجع TAB_AI: لا سياق
	 * حر يصل redirect_back.
	 */
	private static function secret_redirect_tab(): string {
		$tab = self::post_string( 'secret_tab' );
		if ( null === $tab ) {
			return self::TAB_AI;
		}
		$tab = sanitize_key( $tab );
		return in_array( $tab, array( self::TAB_GENERAL, self::TAB_BRANDING, self::TAB_FEATURES, self::TAB_INTEGRATIONS, self::TAB_AI, self::TAB_STATUS ), true )
			? $tab
			: self::TAB_AI;
	}

	/* ────────────────────────────────────────────────────────────
	 * Handlers — كل واحد: capability ثم nonce ثم تنقية ثم عقد
	 * typed ثم redirect آمن. ترتيب التفويض لا يتغير (§5.10).
	 * ──────────────────────────────────────────────────────────── */

	public static function handle_save_settings(): void {
		if ( ! self::current_user_can_manage() || ! self::nonce_ok( 'save_settings' ) ) {
			self::redirect_back( self::TAB_GENERAL, 'forbidden' );
		}

		$settings = array();

		// B3-01: نموذج Features مميز بحقل marker — المتصفح لا يرسل
		// checkboxes غير المفعلة، فغياب features كليًا مع وجود الmarker
		// يعني «تعطيل الجميع» لا «لا تغيير». النماذج الأخرى بلا marker
		// لا تلمس المميزات إطلاقًا.
		if ( null !== self::post_string( 'features_form' ) ) {
			$features = array_fill_keys( HAL_Frontend_Dashboard_Settings_Repository::FEATURES, false );
			// B3-07 (إغلاق): الحقل الحاضر وغير المصفوفي يُرفض صراحة —
			// الغائب فعلًا (المتصفح ألغى كل الاختيارات) هو وحده الحالة
			// المشروعة لتعطيل الجميع.
			if ( isset( $_POST['features'] ) && ! is_array( $_POST['features'] ) ) {
				self::redirect_back( self::requested_tab(), 'save_failed' );
			}
			if ( isset( $_POST['features'] ) && is_array( $_POST['features'] ) ) {
				foreach ( wp_unslash( (array) $_POST['features'] ) as $feature => $value ) {
					// B3-07 (إعادة تسليم): أي مدخل متلاعب به — مفتاح غير نصي،
					// قيمة متداخلة/غير سلمية، أو مفتاح مجهول — يرفض النموذج
					// كله قبل أي حفظ؛ لا تخصيص صامت ولا كتابة جزئية.
					if ( ! is_string( $feature ) || ! is_scalar( $value ) ) {
						self::redirect_back( self::requested_tab(), 'save_failed' );
					}
					$feature_key = sanitize_key( $feature );
					if ( ! in_array( $feature_key, HAL_Frontend_Dashboard_Settings_Repository::FEATURES, true ) ) {
						self::redirect_back( self::requested_tab(), 'save_failed' );
					}
					$features[ $feature_key ] = true;
				}
			}
			$settings['features'] = $features;
		}

		// B3-07: حقل حاضر لكنه غير نصي (مصفوفة مثلًا) يُرفض صراحة — لا
		// تجاهل صامت ولا تحويل؛ الحقل الغائب يعني «ليس في هذا النموذج».
		if ( isset( $_POST['branding_attachment_id'] ) ) {
			$branding = self::post_string( 'branding_attachment_id' );
			if ( null === $branding || 1 !== preg_match( '/\A\d{1,20}\z/D', $branding ) ) {
				self::redirect_back( self::requested_tab(), 'save_failed' );
			}
			$settings['branding_attachment_id'] = (int) $branding;
		}

		if ( isset( $_POST['ai_preference'] ) ) {
			$preference = self::post_string( 'ai_preference' );
			if ( null === $preference ) {
				self::redirect_back( self::requested_tab(), 'save_failed' );
			}
			$settings['ai_preference'] = sanitize_key( $preference );
		}

		// OEB/2 (H1 §3/§6-2): جسر حفظ وضع المحرر الغني — نمط ai_preference
		// (isset → post_string → sanitize_key) مع تحقق EDITOR_MODES هنا:
		// الخارج عن القائمة → save_failed، ثم الإسناد إلى settings ليُمرَّر
		// إلى save() البوابة الموحَّدة. لا endpoint جديد ولا لمس لمنطق
		// الحفظ/القدرات/الredirect ولا لنداء R29 أدناه.
		if ( isset( $_POST['rich_editor_mode'] ) ) {
			$editor_mode = self::post_string( 'rich_editor_mode' );
			if ( null === $editor_mode ) {
				self::redirect_back( self::requested_tab(), 'save_failed' );
			}
			$editor_mode = sanitize_key( $editor_mode );
			if ( ! in_array( $editor_mode, HAL_Frontend_Dashboard_Settings_Repository::EDITOR_MODES, true ) ) {
				self::redirect_back( self::requested_tab(), 'save_failed' );
			}
			$settings['rich_editor_mode'] = $editor_mode;
		}

		// v1.1.1 (W1): اسم الموقع والوصف — نص حر (بيانات مستخدم تُخرج
		// مهرَّبة عند الرندر حصرًا) بنمط branding_attachment_id حرفيًا:
		// isset → post_string → null يرفض، ثم سقف mb_strlen وإلا رفض
		// النموذج كله (لا sanitize_key — لا إعادة كتابة لنص المستخدم).
		if ( isset( $_POST['branding_site_title'] ) ) {
			$site_title = self::post_string( 'branding_site_title' );
			if ( null === $site_title ) {
				self::redirect_back( self::requested_tab(), 'save_failed' );
			}
			// طول المحارف بmb_strlen عند توافرها وإلا strlen — نمط
			// rankmath.php/ajax-seo.php القائم (بيئات بلا mbstring).
			$site_title_length = function_exists( 'mb_strlen' ) ? mb_strlen( $site_title ) : strlen( $site_title );
			if ( $site_title_length > HAL_Frontend_Dashboard_Settings_Repository::BRANDING_SITE_TITLE_MAX ) {
				self::redirect_back( self::requested_tab(), 'save_failed' );
			}
			$settings['branding_site_title'] = $site_title;
		}

		if ( isset( $_POST['branding_site_tagline'] ) ) {
			$site_tagline = self::post_string( 'branding_site_tagline' );
			if ( null === $site_tagline ) {
				self::redirect_back( self::requested_tab(), 'save_failed' );
			}
			$site_tagline_length = function_exists( 'mb_strlen' ) ? mb_strlen( $site_tagline ) : strlen( $site_tagline );
			if ( $site_tagline_length > HAL_Frontend_Dashboard_Settings_Repository::BRANDING_SITE_TAGLINE_MAX ) {
				self::redirect_back( self::requested_tab(), 'save_failed' );
			}
			$settings['branding_site_tagline'] = $site_tagline;
		}

		// v1.1.1 (W3): روابط أقسام الموقع — بنمط B3-01/features_form حرفيًا:
		// النموذج الحاوي للحقول يمثله marker (site_sections_form) وغيابه =
		// لا مساس بالمفتاح إطلاقًا. الحاضر: يجب مصفوفة وإلا رفض؛ لكل مفتاح
		// من الثمانية: الغائب = '' (auto)، والحاضر غير النصي يُرفض صراحة
		// (B3-07: التحويل الصامت ممنوع)، والنص يمر esc_url_raw ثم فحص
		// scheme عبر wp_parse_url — http/https حصرًا، غير ذلك '' (الفاسد
		// = فراغ = auto). الثمانية تُبنى كاملة دومًا.
		if ( null !== self::post_string( 'site_sections_form' ) ) {
			if ( isset( $_POST['site_section_urls'] ) && ! is_array( $_POST['site_section_urls'] ) ) {
				self::redirect_back( self::requested_tab(), 'save_failed' );
			}
			$section_input = isset( $_POST['site_section_urls'] ) && is_array( $_POST['site_section_urls'] )
				? (array) $_POST['site_section_urls']
				: array();
			// مفتاح مجهول = تلاعب بنيوي — رفض النموذج كله قبل أي قراءة
			// (نمط B3-07: مفتاح خارج القائمة يرفض).
			foreach ( array_keys( $section_input ) as $submitted_key ) {
				if ( ! is_string( $submitted_key ) || ! in_array( $submitted_key, HAL_Frontend_Dashboard_Settings_Repository::SITE_SECTION_KEYS, true ) ) {
					self::redirect_back( self::requested_tab(), 'save_failed' );
				}
			}
			$section_urls = array_fill_keys( HAL_Frontend_Dashboard_Settings_Repository::SITE_SECTION_KEYS, '' );
			foreach ( HAL_Frontend_Dashboard_Settings_Repository::SITE_SECTION_KEYS as $section_key ) {
				// الغائب = '' (auto) — الحالة المشروعة الوحيدة للغياب
				// (بنمط features: الغائب فعلًا هو وحده المشروع)؛ الحاضر
				// غير النصي يُرفض صراحة بلا تحويل صامت (B3-07 حرفيًا).
				if ( ! isset( $section_input[ $section_key ] ) ) {
					continue;
				}
				if ( ! is_string( $section_input[ $section_key ] ) ) {
					self::redirect_back( self::requested_tab(), 'save_failed' );
				}
				$section_value = esc_url_raw( $section_input[ $section_key ] );
				if ( '' !== $section_value ) {
					$section_scheme = wp_parse_url( $section_value, PHP_URL_SCHEME );
					$section_scheme = is_string( $section_scheme ) ? strtolower( $section_scheme ) : '';
					if ( ! in_array( $section_scheme, array( 'http', 'https' ), true ) ) {
						$section_value = '';
					}
				}
				$section_urls[ $section_key ] = $section_value;
			}
			$settings['site_section_urls'] = $section_urls;
		}

		$saved = HAL_Frontend_Dashboard_Settings_Repository::save( $settings );

		// R29 (G4 §3-أ-15): settings_save بعد نجاح save() حصرًا وقبل
		// redirect_back — بguard is_wp_error ففشل الحفظ صفر صفوف. meta
		// keys بأسماء مفاتيح الأقسام المكتوبة حصرًا لا قيم إطلاقًا،
		// object_id NULL بنمط الأشقاء، وقيمة الإرجاع تُتجاهل عمدًا
		// (G4 §5) — لا مساس بمنطق الحفظ ولا بالقدرات ولا بالredirect.
		if ( ! is_wp_error( $saved ) ) {
			hossam_audit_log(
				'settings_save',
				array(
					'object_id' => null,
					'meta'      => array( 'keys' => array_keys( $settings ) ),
				)
			);
		}

		// B3-02: فشل كتابة فعلي (بعد اجتياز التحقق) يظهر save_failed —
		// عدم التغيير يظهر saved دون كتابة زائفة.
		self::redirect_back( self::requested_tab(), is_wp_error( $saved ) ? 'save_failed' : 'saved' );
	}

	public static function handle_set_secret(): void {
		if ( ! self::current_user_can_manage() || ! self::nonce_ok( 'set_secret' ) ) {
			self::redirect_back( self::TAB_AI, 'forbidden' );
		}
		$secret_id = self::post_string( 'secret_id' );
		// معرف السر من allowlist الـRegistry حصرًا (لا key names حرة،
		// والمصفوفات تعود null من القارئ بلا تحويل — B3-07).
		if ( null === $secret_id || ! HAL_Frontend_Dashboard_Integration_Settings_Registry::is_valid_secret_id( $secret_id ) ) {
			self::redirect_back( self::TAB_AI, 'unknown_secret' );
		}
		// B3-07: قيمة السر يجب أن تكون نصًا — المصفوفة/الغياب يعودان
		// null فيُمرران كنص فارغ ينتج خطأ العقد الموثق (EMPTY_VALUE)
		// بدل تحويل صامت لأي محتوى.
		$value = self::post_string( 'secret_value' ) ?? '';
		// قيمة كاملة مطلوبة للاستبدال — لا قراءة للقيمة القديمة ولا
		// partial update (قرار المالك §2.4).
		$result = HAL_Frontend_Dashboard_Secret_Store::set( $secret_id, $value );
		// v1.1.1 (E3): probe فوري إلزامي بعد مساس المخزن فعليًا — إبطال
		// transient الـRegistry (TTL 5 دقائق) ثم فحص صحة فوري يغلق فجوة
		// زمنية كانت تعرض مصدر اعتماد قديمًا حتى 5 دقائق بعد الحفظ.
		// مسارات الرفض المبكر أعلاه بلا probe (لم تُمس المخزن). الحارس
		// الثاني (delete_transient) يحفظ بيئات الشيم/الاختبار بلا واجهة
		// transient — no-op مغلق هناك، ونشط كاملًا في ووردبريس.
		if ( function_exists( 'hossam_invalidate_integration_capabilities' ) && function_exists( 'delete_transient' ) ) {
			hossam_invalidate_integration_capabilities();
		}
		HAL_Frontend_Dashboard_Integration_Health_Monitor::run_checks();
		// v1.1.1 (E3): الإحالة لتبويب المصدر (secret_tab) بدل TAB_AI الصلب.
		// v1.1.2: the store error code maps to a dedicated message key so the
		// notice names the actual failure reason; unknown codes keep the
		// generic message below as the fallback (text and behavior intact).
		if ( is_wp_error( $result ) ) {
			$code    = $result->get_error_code();
			$map     = array(
				'hal_secret_bad_id'            => 'secret_failed_value',
				'hal_secret_empty_value'       => 'secret_failed_value',
				'hal_secret_persist_failed'    => 'secret_failed_persist',
				'hal_secret_store_unavailable' => ( 'sodium_missing' === HAL_Frontend_Dashboard_Secret_Store::get_blocker() )
					? 'secret_failed_sodium'
					: 'secret_failed_key',
			);
			$message = isset( $map[ $code ] ) ? $map[ $code ] : 'secret_failed';
		} else {
			$message = 'secret_saved';
		}
		self::redirect_back( self::secret_redirect_tab(), $message, $secret_id );
	}

	public static function handle_delete_secret(): void {
		if ( ! self::current_user_can_manage() || ! self::nonce_ok( 'delete_secret' ) ) {
			self::redirect_back( self::TAB_AI, 'forbidden' );
		}
		$secret_id = self::post_string( 'secret_id' );
		if ( null === $secret_id || ! HAL_Frontend_Dashboard_Integration_Settings_Registry::is_valid_secret_id( $secret_id ) ) {
			self::redirect_back( self::TAB_AI, 'unknown_secret' );
		}
		$deleted = HAL_Frontend_Dashboard_Secret_Store::delete( $secret_id );
		// v1.1.1 (E3): probe فوري إلزامي بعد مساس المخزن فعليًا — نمط
		// handle_set_secret حرفيًا (مسارات الرفض المبكر أعلاه بلا probe).
		if ( function_exists( 'hossam_invalidate_integration_capabilities' ) && function_exists( 'delete_transient' ) ) {
			hossam_invalidate_integration_capabilities();
		}
		HAL_Frontend_Dashboard_Integration_Health_Monitor::run_checks();
		// v1.1.1 (E3): الإحالة لتبويب المصدر (secret_tab) بدل TAB_AI الصلب.
		self::redirect_back( self::secret_redirect_tab(), $deleted ? 'secret_deleted' : 'secret_missing', $secret_id );
	}

	public static function handle_run_health(): void {
		if ( ! self::current_user_can_manage() || ! self::nonce_ok( 'run_health' ) ) {
			self::redirect_back( self::TAB_STATUS, 'forbidden' );
		}
		HAL_Frontend_Dashboard_Integration_Health_Monitor::run_checks();
		self::redirect_back( self::TAB_STATUS, 'health_run' );
	}

	private static function redirect_back( string $tab, string $message, string $secret_id = '' ): void {
		$args = array(
			'page'     => self::MENU_SLUG,
			'tab'      => $tab,
			'hal_msg'  => $message,
		);
		if ( '' !== $secret_id ) {
			$args['secret'] = $secret_id; // معرف فقط — لا قيمة.
		}
		$target = function_exists( 'add_query_arg' ) && function_exists( 'admin_url' )
			? add_query_arg( $args, admin_url( 'admin.php' ) )
			: '';
		if ( function_exists( 'wp_safe_redirect' ) ) {
			wp_safe_redirect( $target );
		}
		exit;
	}

	/**
	 * v1.1.1 (W2): حالة صفحة الداشبورد — قراءة فقط بلا أي كتابة (option
	 * hal_frontend_dashboard_page_id قراءة-فقط هنا؛ الكاتب الوحيد
	 * Installer): owned = id>0 يقابل page بحالة publish/private بنفس
	 * تحقق hossam_dashboard_url (adapters/wpml.php — تُستدعى لا تُعدل
	 * للرابط)؛ وإلا fallback.
	 *
	 * @return array{status:string, page_id:int, url:string}
	 */
	private static function dashboard_page_status(): array {
		$page_id = function_exists( 'get_option' ) ? absint( get_option( 'hal_frontend_dashboard_page_id', 0 ) ) : 0;
		$owned   = false;
		if ( $page_id > 0 && function_exists( 'get_post' ) ) {
			$post  = get_post( $page_id );
			$owned = $post instanceof WP_Post
				&& 'page' === $post->post_type
				&& in_array( $post->post_status, array( 'publish', 'private' ), true );
		}
		$url = function_exists( 'hossam_dashboard_url' ) ? hossam_dashboard_url() : '';
		return array(
			'status'  => $owned ? 'owned' : 'fallback',
			'page_id' => $page_id,
			'url'     => $url,
		);
	}

	/* ────────────────────────────────────────────────────────────
	 * Render — بلا secrets: configured/source/health فقط.
	 * ──────────────────────────────────────────────────────────── */

	public static function render_page(): void {
		if ( ! self::current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage HAL Frontend Dashboard.', 'hal-frontend-dashboard' ), 403 );
		}

		$tab     = self::requested_tab();
		$settings = HAL_Frontend_Dashboard_Settings_Repository::get_all();
		?>
		<div class="wrap hal-fd-admin" data-hal-admin>
			<h1><?php echo esc_html( 'HAL Frontend Dashboard' ); ?></h1>
			<?php self::render_notice_from_query(); ?>
			<h2 class="nav-tab-wrapper hal-fd-tabs">
				<?php
				$tabs = array(
					self::TAB_GENERAL      => __( 'General', 'hal-frontend-dashboard' ),
					self::TAB_BRANDING     => __( 'Branding', 'hal-frontend-dashboard' ),
					self::TAB_FEATURES     => __( 'Features', 'hal-frontend-dashboard' ),
					self::TAB_INTEGRATIONS => __( 'Integrations', 'hal-frontend-dashboard' ),
					self::TAB_AI           => __( 'AI', 'hal-frontend-dashboard' ),
					self::TAB_STATUS       => __( 'Status & Diagnostics', 'hal-frontend-dashboard' ),
				);
				foreach ( $tabs as $tab_id => $label ) {
					printf(
						'<a class="nav-tab%1$s" href="%2$s" data-tab="%3$s">%4$s</a>',
						$tab_id === $tab ? ' nav-tab-active' : '',
						esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=' . $tab_id ) ),
						esc_attr( $tab_id ),
						esc_html( $label )
					);
				}
				?>
			</h2>

			<?php if ( self::TAB_GENERAL === $tab ) : ?>
				<div class="hal-fd-card">
					<p>
						<?php
						printf(
							/* translators: 1: runtime version, 2: release id. */
							esc_html__( 'Runtime version: %1$s — release: %2$s.', 'hal-frontend-dashboard' ),
							esc_html( HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION ),
							esc_html( HAL_FRONTEND_DASHBOARD_RELEASE_ID )
						);
						?>
					</p>
					<p><?php esc_html_e( 'Runtime checks use the manage_hal_frontend_dashboard capability only. Site settings, media attachments and encrypted secrets live outside release directories and persist across updates and rollbacks.', 'hal-frontend-dashboard' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( self::TAB_BRANDING === $tab ) : ?>
				<div class="hal-fd-card">
					<p><?php esc_html_e( 'The dashboard logo is a Media Library attachment (PNG, JPEG or WebP). No logo file ships with the plugin; missing attachments fall back to a safe text mark (HAL).', 'hal-frontend-dashboard' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-hal-form>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE_SETTINGS ); ?>" />
						<input type="hidden" name="tab" value="<?php echo esc_attr( self::TAB_BRANDING ); ?>" />
						<?php wp_nonce_field( self::NONCE_ACTION . ':save_settings', self::NONCE_FIELD ); ?>
						<input type="hidden" name="branding_attachment_id" data-hal-attachment-input value="<?php echo esc_attr( (string) $settings['branding_attachment_id'] ); ?>" />
						<p data-hal-attachment-preview data-hal-attachment-id="<?php echo esc_attr( (string) $settings['branding_attachment_id'] ); ?>">
							<?php
							// القراءة الحية: مرفق محذوف/غير صالح يُظهر حالة
							// الـfallback النصي (قرار المالك §2.3).
							$effective_attachment_id = HAL_Frontend_Dashboard_Settings_Repository::get_branding_attachment_id();
							echo esc_html(
								$effective_attachment_id > 0
									? sprintf( /* translators: %d: attachment id */ __( 'Current logo attachment id: %d', 'hal-frontend-dashboard' ), $effective_attachment_id )
									: __( 'No usable logo attachment — the safe text fallback (HAL) is used.', 'hal-frontend-dashboard' )
							);
							?>
						</p>
						<?php // v1.1.1 (W1/W3): حقول اسم الموقع/الوصف وروابط الأقسام الثمانية — قيم المستخدم تُخرج مهرَّبة esc_attr حصرًا (لا i18n ولا hossam_t). ?>
						<input type="hidden" name="site_sections_form" value="1" />
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="hal-branding-site-title"><?php esc_html_e( 'Site title', 'hal-frontend-dashboard' ); ?></label></th>
								<td>
									<input type="text" id="hal-branding-site-title" name="branding_site_title" value="<?php echo esc_attr( $settings['branding_site_title'] ); ?>" maxlength="60" class="regular-text" />
									<p class="description"><?php esc_html_e( 'Leave empty to use the default text at each position.', 'hal-frontend-dashboard' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="hal-branding-site-tagline"><?php esc_html_e( 'Tagline', 'hal-frontend-dashboard' ); ?></label></th>
								<td>
									<input type="text" id="hal-branding-site-tagline" name="branding_site_tagline" value="<?php echo esc_attr( $settings['branding_site_tagline'] ); ?>" maxlength="80" class="regular-text" />
									<p class="description"><?php esc_html_e( 'Leave empty to use the default text at each position.', 'hal-frontend-dashboard' ); ?></p>
								</td>
							</tr>
							<?php foreach ( HAL_Frontend_Dashboard_Settings_Repository::SITE_SECTION_KEYS as $section_key ) : ?>
							<tr>
								<th scope="row"><label for="hal-section-url-<?php echo esc_attr( $section_key ); ?>"><?php echo esc_html( $section_key ); ?></label></th>
								<td>
									<input type="url" id="hal-section-url-<?php echo esc_attr( $section_key ); ?>" name="site_section_urls[<?php echo esc_attr( $section_key ); ?>]" value="<?php echo esc_attr( $settings['site_section_urls'][ $section_key ] ); ?>" placeholder="auto" class="regular-text code" />
								</td>
							</tr>
							<?php endforeach; ?>
						</table>
						<p class="description"><?php esc_html_e( 'URL overrides apply to the fallback site sections list only — shown when no primary navigation menu exists; the primary menu always takes precedence. Leave a field empty for the automatic resolver.', 'hal-frontend-dashboard' ); ?></p>
						<p>
							<button type="button" class="button" data-hal-media-open><?php esc_html_e( 'Choose from Media Library', 'hal-frontend-dashboard' ); ?></button>
							<?php submit_button( __( 'Save branding', 'hal-frontend-dashboard' ), 'primary', 'submit', false ); ?>
						</p>
					</form>
				</div>
				<?php // v1.1.1 (W2): كتلة قراءة-فقط لصفحة الداشبورد — بعد النموذج وخارجه: صفر POST وصفر حقل مخفي ولا أي كتابة للoption (الكاتب الوحيد Installer). ?>
				<?php $dashboard_page = self::dashboard_page_status(); ?>
				<div class="hal-fd-card">
					<p>
						<?php
						printf(
							/* translators: 1: page id, 2: ownership status (owned or fallback). */
							esc_html__( 'Dashboard page id: %1$d — status: %2$s.', 'hal-frontend-dashboard' ),
							$dashboard_page['page_id'],
							esc_html( $dashboard_page['status'] )
						);
						?>
						<?php if ( '' !== $dashboard_page['url'] ) : ?>
							<a href="<?php echo esc_url( $dashboard_page['url'] ); ?>" target="_blank"><?php esc_html_e( 'Open', 'hal-frontend-dashboard' ); ?></a>
						<?php endif; ?>
					</p>
					<p class="description"><?php esc_html_e( 'Reactivating the plugin secures or generates the dashboard page. Manual binding is done via the page id option before activation. This block is read-only.', 'hal-frontend-dashboard' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( self::TAB_FEATURES === $tab ) : ?>
				<div class="hal-fd-card">
					<p><?php esc_html_e( 'Feature toggles never grant capability or resource ownership; the effective decision combines this setting with the integration registry, user capability and resource ownership at each consumer.', 'hal-frontend-dashboard' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-hal-form>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE_SETTINGS ); ?>" />
						<input type="hidden" name="tab" value="<?php echo esc_attr( self::TAB_FEATURES ); ?>" />
						<input type="hidden" name="features_form" value="1" />
						<?php wp_nonce_field( self::NONCE_ACTION . ':save_settings', self::NONCE_FIELD ); ?>
							<table class="form-table" role="presentation">
								<?php foreach ( $settings['features'] as $feature => $enabled ) : ?>
									<tr>
										<th scope="row"><label for="hal-feature-<?php echo esc_attr( $feature ); ?>"><?php echo esc_html( $feature ); ?></label></th>
										<td>
											<input type="checkbox" id="hal-feature-<?php echo esc_attr( $feature ); ?>" name="features[<?php echo esc_attr( $feature ); ?>]" value="1" <?php checked( $enabled ); ?> data-hal-feature="<?php echo esc_attr( $feature ); ?>" />
											<?php // v1.1.1 (W4): توصيف الميزة من خريطة FEATURE_DESCRIPTIONS — المفتاح بلا نص = بلا فقرة. ?>
											<?php if ( isset( self::FEATURE_DESCRIPTIONS[ $feature ] ) ) : ?>
												<p class="description"><?php echo esc_html( __( self::FEATURE_DESCRIPTIONS[ $feature ], 'hal-frontend-dashboard' ) ); ?></p>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php // OEB/2 (H1 §3/§6-2): select وضع المحرر الغني بنمط select ai_preference القائم — الأوضاع الثلاثة مع الحالي من get_rich_editor_mode(). ?>
							<tr>
								<th scope="row"><label for="hal-rich-editor-mode"><?php esc_html_e( 'Rich editor mode', 'hal-frontend-dashboard' ); ?></label></th>
								<td>
									<select id="hal-rich-editor-mode" name="rich_editor_mode">
										<?php foreach ( HAL_Frontend_Dashboard_Settings_Repository::EDITOR_MODES as $editor_mode ) : ?>
											<option value="<?php echo esc_attr( $editor_mode ); ?>" <?php selected( HAL_Frontend_Dashboard_Settings_Repository::get_rich_editor_mode(), $editor_mode ); ?>><?php echo esc_html( $editor_mode ); ?></option>
										<?php endforeach; ?>
									</select>
									<?php // v1.1.1 (W5): التوصيف الثلاثي بالنصوص الحرفية من العقد — عرض فقط بلا أي منطق جديد: الافتراضي الذكي قائم أصلًا (repository: fail-closed embed عند الغياب/الفساد، وoff يفرض عند تعطيل rich_editor). ?>
									<p class="description"><?php echo esc_html( __( 'Adds an Open-Editor button (admins) that opens the official WordPress editor in a window and syncs edits back.', 'hal-frontend-dashboard' ) ); ?></p>
									<p class="description"><?php echo esc_html( __( 'Shows the official WordPress block editor embedded inside the article forms (default).', 'hal-frontend-dashboard' ) ); ?></p>
									<p class="description"><?php echo esc_html( __( 'Uses the plain text field only — no rich editor packages are loaded.', 'hal-frontend-dashboard' ) ); ?></p>
									<p class="description"><?php echo esc_html( __( 'Default: embed — chosen automatically until you change it here.', 'hal-frontend-dashboard' ) ); ?></p>
									<p class="description"><?php echo esc_html( __( 'You can override at any time; the Rich editor checkbox must stay on for either mode.', 'hal-frontend-dashboard' ) ); ?></p>
								</td>
							</tr>
						</table>
						<?php submit_button( __( 'Save features', 'hal-frontend-dashboard' ), 'primary', 'submit', false ); ?>
					</form>
				</div>
			<?php endif; ?>

			<?php if ( self::TAB_INTEGRATIONS === $tab ) : ?>
				<div class="hal-fd-card">
					<p><?php esc_html_e( 'Sanitized status codes from the last bounded health job. Values and raw health responses are never displayed here.', 'hal-frontend-dashboard' ); ?></p>
					<table class="widefat striped hal-fd-status" role="presentation">
						<?php foreach ( self::render_integration_rows() as $row ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html( $row['label'] ); ?></th>
								<td><span class="hal-fd-badge hal-fd-badge--<?php echo esc_attr( $row['status'] ); ?>"><?php echo esc_html( $row['status'] ); ?></span> <code><?php echo esc_html( implode( ', ', $row['codes'] ) ); ?></code><?php '' !== $row['credential'] ? printf( ' <span class="description">%s <code>%s</code></span>', esc_html( 'credential source:' ), esc_html( $row['credential'] ) ) : null; ?></td>
							</tr>
						<?php endforeach; ?>
					</table>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-hal-form>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_RUN_HEALTH ); ?>" />
						<?php wp_nonce_field( self::NONCE_ACTION . ':run_health', self::NONCE_FIELD ); ?>
						<?php submit_button( __( 'Run integration health check now', 'hal-frontend-dashboard' ), 'secondary', 'submit', false ); ?>
					</form>
					<?php // v1.1.1 (E3): إدخال مفتاح Amelia عبر UI مشفر — من الـRegistry حصرًا بنمط تبويب AI حرفيًا؛ جدول الصفوف أعلاه يبقى عرض مصدر الاعتماد كما هو. ?>
					<?php foreach ( HAL_Frontend_Dashboard_Integration_Settings_Registry::integrations()['amelia']['secrets'] as $secret ) : ?>
						<?php self::render_secret_block( (string) $secret['id'], (string) $secret['label'], self::TAB_INTEGRATIONS ); ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( self::TAB_AI === $tab ) : ?>
				<div class="hal-fd-card">
					<p class="hal-fd-warning">
						<?php esc_html_e( 'Warning: WordPress AI Client Connector DB keys are stored unencrypted in the database and readable by other plugins. This source is supported with a warning and is not Maximum Protection.', 'hal-frontend-dashboard' ); ?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-hal-form>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE_SETTINGS ); ?>" />
						<input type="hidden" name="tab" value="<?php echo esc_attr( self::TAB_AI ); ?>" />
						<?php wp_nonce_field( self::NONCE_ACTION . ':save_settings', self::NONCE_FIELD ); ?>
						<?php $ai_feature_enabled = (bool) $settings['features']['ai']; ?>
						<fieldset class="hal-fd-fieldset" data-requires-feature="ai" <?php disabled( ! $ai_feature_enabled ); ?>>
							<table class="form-table" role="presentation">
								<tr>
									<th scope="row"><label for="hal-ai-preference"><?php esc_html_e( 'Strategy preference', 'hal-frontend-dashboard' ); ?></label></th>
									<td>
										<select id="hal-ai-preference" name="ai_preference">
											<?php foreach ( HAL_Frontend_Dashboard_Settings_Repository::AI_PREFERENCES as $preference ) : ?>
												<option value="<?php echo esc_attr( $preference ); ?>" <?php selected( $settings['ai_preference'], $preference ); ?>><?php echo esc_html( $preference ); ?></option>
											<?php endforeach; ?>
										</select>
										<p class="description"><?php esc_html_e( 'auto prefers the WordPress AI Client when text generation is supported; the direct encrypted key is a fallback. The provider/model allowlist is owned by the AI adapter.', 'hal-frontend-dashboard' ); ?></p>
									</td>
								</tr>
							</table>
						</fieldset>
						<?php if ( ! $ai_feature_enabled ) : ?>
							<p class="description"><?php esc_html_e( 'The AI feature is disabled in the Features tab — its controls are disabled until it is enabled. Disabling a feature never deletes its data.', 'hal-frontend-dashboard' ); ?></p>
						<?php endif; ?>
						<?php submit_button( __( 'Save AI preference', 'hal-frontend-dashboard' ), 'primary', 'submit', false ); ?>
					</form>
					<?php foreach ( HAL_Frontend_Dashboard_Integration_Settings_Registry::integrations()['ai']['secrets'] as $secret ) : ?>
						<?php self::render_secret_block( (string) $secret['id'], (string) $secret['label'], self::TAB_AI ); ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( self::TAB_STATUS === $tab ) : ?>
				<div class="hal-fd-card">
					<p>
						<?php
						$store_available = HAL_Frontend_Dashboard_Secret_Store::is_available();
						echo esc_html(
							sprintf(
								/* translators: 1: availability, 2: key source, 3: fingerprint. */
								__( 'Secret store: %1$s — key source: %2$s — key fingerprint: %3$s.', 'hal-frontend-dashboard' ),
								$store_available ? 'available' : 'blocked',
								esc_html( HAL_Frontend_Dashboard_Secret_Store::get_key_source() ),
								esc_html( HAL_Frontend_Dashboard_Secret_Store::get_key_fingerprint() ?? 'n/a' )
							)
						);
						?>
						</p>
						<?php if ( ! $store_available ) : ?>
							<p class="description">
								<?php
								$blocker = HAL_Frontend_Dashboard_Secret_Store::get_blocker();
								echo esc_html( 'sodium_missing' === $blocker
									? __( 'Reason: the PHP sodium extension is missing on this server.', 'hal-frontend-dashboard' )
									: __( 'Reason: no valid external encryption key was found (environment variable or wp-config.php constant).', 'hal-frontend-dashboard' ) );
								?>
							</p>
							<p class="description"><?php esc_html_e( 'To enable encrypted storage, generate a key locally on your own machine and add this line to wp-config.php above the stop-editing comment:', 'hal-frontend-dashboard' ); ?></p>
							<p><code>define( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY', '&lt;paste-64-hex-characters&gt;' );</code></p>
							<p class="description"><?php esc_html_e( 'Local key generation command: php -r \'echo bin2hex(random_bytes(32));\'', 'hal-frontend-dashboard' ); ?></p>
							<p class="description"><?php esc_html_e( 'Expected read-back after saving wp-config.php and reloading this page: "Secret store: available — key source: constant — key fingerprint: <32 hex characters>".', 'hal-frontend-dashboard' ); ?></p>
						<?php endif; ?>
						<table class="widefat striped hal-fd-status" role="presentation">
						<?php foreach ( self::render_integration_rows() as $row ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html( $row['label'] ); ?></th>
								<td><span class="hal-fd-badge hal-fd-badge--<?php echo esc_attr( $row['status'] ); ?>"><?php echo esc_html( $row['status'] ); ?></span> <code><?php echo esc_html( implode( ', ', $row['codes'] ) ); ?></code></td>
							</tr>
						<?php endforeach; ?>
					</table>
					<p class="description"><?php esc_html_e( 'Integration Health never rolls the runtime back; Deployment/Boot Health is a separate contract.', 'hal-frontend-dashboard' ); ?></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * صفوف الحالة المنقحة من كاش المراقب (بلا قيم ولا responses خام)
	 * مع مصدر credential الفعلي لكل تكامل يملك سرًا (B3-credential)،
	 * وحالة مصدر Connector للـAI عند قدرة Client (B4-03 — عرض فقط،
	 * بلا قراءة أسرار داخل الـrender).
	 *
	 * @return array<int, array{label:string, status:string, codes:string[], credential:string}>
	 */
	private static function render_integration_rows(): array {
		$status  = HAL_Frontend_Dashboard_Integration_Health_Monitor::get_status();
		$rows    = array();
		foreach ( HAL_Frontend_Dashboard_Integration_Settings_Registry::integrations() as $integration_id => $definition ) {
			$entry  = is_array( $status[ $integration_id ] ?? null ) ? $status[ $integration_id ] : array();
			$credential = '';
			foreach ( $definition['secrets'] as $secret ) {
				$credential = HAL_Frontend_Dashboard_Integration_Settings_Registry::credential_source( (string) $secret['id'] );
				break;
			}
			if ( 'ai' === $integration_id && function_exists( 'hossam_ai_connector_source_status' ) ) {
				$connector = hossam_ai_connector_source_status();
				if ( ! empty( $connector['capable'] ) ) {
					$suffix = 'connector:' . (string) $connector['source'] . ' (' . (string) $connector['reason'] . ')';
					$credential = '' !== $credential ? $credential . ' + ' . $suffix : $suffix;
				}
			}
			$rows[] = array(
				'label'      => (string) $definition['label'],
				'status'     => (string) ( $entry['status'] ?? 'unknown' ),
				'codes'      => array_map( 'strval', (array) ( $entry['codes'] ?? array() ) ),
				'credential' => $credential,
			);
		}
		return $rows;
	}

	/**
	 * كتلة سر واحدة: حالتها فقط (configured/source) + استبدال بقيمة
	 * كاملة + حذف مستقل. لا قيمة مخزنة تُعرض أو تُمرر للمتصفح.
	 * v1.1.1 (E3): وسيط $tab اختياري — عند تمريره يُطبع hidden
	 * secret_tab في نموذجي set وdelete ليعود redirect لتبويب المصدر
	 * (الغائب يبقى سلوك TAB_AI القائم بلا حقل).
	 */
	private static function render_secret_block( string $secret_id, string $label, string $tab = '' ): void {
		$configured = HAL_Frontend_Dashboard_Secret_Store::has( $secret_id );
		?>
		<div class="hal-fd-secret" data-hal-secret="<?php echo esc_attr( $secret_id ); ?>">
			<h3><?php echo esc_html( $label ); ?></h3>
			<p>
				<?php
				echo esc_html(
					$configured
						? __( 'Configured: yes — the stored value is never displayed or sent back.', 'hal-frontend-dashboard' )
						: __( 'Configured: no.', 'hal-frontend-dashboard' )
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-hal-form>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SET_SECRET ); ?>" />
				<input type="hidden" name="secret_id" value="<?php echo esc_attr( $secret_id ); ?>" />
				<?php if ( '' !== $tab ) : ?>
					<input type="hidden" name="secret_tab" value="<?php echo esc_attr( $tab ); ?>" />
				<?php endif; ?>
				<?php wp_nonce_field( self::NONCE_ACTION . ':set_secret', self::NONCE_FIELD ); ?>
				<label class="screen-reader-text" for="hal-secret-value-<?php echo esc_attr( $secret_id ); ?>"><?php echo esc_html( $label ); ?></label>
				<input type="password" id="hal-secret-value-<?php echo esc_attr( $secret_id ); ?>" name="secret_value" value="" autocomplete="new-password" data-hal-secret-input />
				<?php submit_button( __( 'Replace with a full new value', 'hal-frontend-dashboard' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php if ( $configured ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-hal-form>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_DELETE_SECRET ); ?>" />
					<input type="hidden" name="secret_id" value="<?php echo esc_attr( $secret_id ); ?>" />
					<?php if ( '' !== $tab ) : ?>
						<input type="hidden" name="secret_tab" value="<?php echo esc_attr( $tab ); ?>" />
					<?php endif; ?>
					<?php wp_nonce_field( self::NONCE_ACTION . ':delete_secret', self::NONCE_FIELD ); ?>
					<?php submit_button( __( 'Delete stored value', 'hal-frontend-dashboard' ), 'link-delete', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * رسائل الإحالة من الـhandlers — نصوص عامة منقحة بلا تفاصيل حساسة.
	 */
	private static function render_notice_from_query(): void {
		$message = isset( $_GET['hal_msg'] ) ? sanitize_key( wp_unslash( (string) $_GET['hal_msg'] ) ) : '';
		if ( '' === $message ) {
			return;
		}
		$messages = array(
			'saved'          => array( __( 'Settings saved.', 'hal-frontend-dashboard' ), 'success' ),
			'save_failed'    => array( __( 'Settings were not saved — the submitted values were rejected.', 'hal-frontend-dashboard' ), 'error' ),
			'secret_saved'   => array( __( 'Secret stored (encrypted).', 'hal-frontend-dashboard' ), 'success' ),
			'secret_failed'  => array( __( 'The secret was not stored — encrypted storage is blocked or the value was rejected.', 'hal-frontend-dashboard' ), 'error' ),
			'secret_failed_sodium'  => array( __( 'The secret was not stored — the PHP sodium extension is missing on this server, so encrypted storage is unavailable.', 'hal-frontend-dashboard' ), 'error' ),
			'secret_failed_key'     => array( __( 'The secret was not stored — no valid external encryption key was found. Set HAL_FRONTEND_DASHBOARD_SECRET_KEY in the environment or wp-config.php (32 raw bytes, 64 hex characters, or base64 of 32 bytes) — see the Status tab.', 'hal-frontend-dashboard' ), 'error' ),
			'secret_failed_persist' => array( __( 'The secret was not stored — writing the encrypted record to the database failed.', 'hal-frontend-dashboard' ), 'error' ),
			'secret_failed_value'   => array( __( 'The secret was not stored — the submitted value was rejected (empty or malformed input).', 'hal-frontend-dashboard' ), 'error' ),
			'secret_deleted' => array( __( 'Secret deleted.', 'hal-frontend-dashboard' ), 'success' ),
			'secret_missing' => array( __( 'No stored secret matched that id.', 'hal-frontend-dashboard' ), 'error' ),
			'unknown_secret' => array( __( 'Unknown secret id.', 'hal-frontend-dashboard' ), 'error' ),
			'health_run'     => array( __( 'Integration health check completed.', 'hal-frontend-dashboard' ), 'success' ),
			'forbidden'      => array( __( 'You are not allowed to perform that action.', 'hal-frontend-dashboard' ), 'error' ),
		);
		if ( ! isset( $messages[ $message ] ) ) {
			return;
		}
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			'success' === $messages[ $message ][1] ? 'success' : 'error',
			esc_html( $messages[ $message ][0] )
		);
	}
}

HAL_Frontend_Dashboard_Admin_Controller::register();
