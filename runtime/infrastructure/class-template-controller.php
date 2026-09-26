<?php
/**
 * includes/class-template-controller.php — Template Controller (الدفعة 7)
 * ══════════════════════════════════════════════════════════════
 * العقد (معمارية §7.5 و§18 صف includes/class-template-controller.php):
 *   - يسجّل filter template_include ويعيد قالب Runtime فقط عندما يطابق
 *     queried object الـpage ID المملوك أو ترجمة WPML المثبتة له.
 *   - لا hijack بالـslug العام ولا بـquery parameter ولا بـ
 *     is_page_template — المطابقة بالـID حصرًا.
 *   - لا ينشئ الصفحة؛ إنشاء/ربط الصفحة idempotent مسؤولية Installer
 *     (§7.2 بند 9 و§7.5) عبر option 'hal_frontend_dashboard_page_id'.
 *   - قبل الإرجاع يثبت realpath/readability وبقاء الملف داخل
 *     HAL_FRONTEND_DASHBOARD_RUNTIME_DIR لهذا request (release واحد لكل
 *     request)؛ عند الفشل لا يخمّن قالبًا ولا يكوّن path من input، ويعيد
 *     القالب الأصلي مع تسجيل تشخيص آمن (code عام في log بلا مسارات أو
 *     بيانات).
 *   - fail-safe إذا Runtime غير جاهز: غياب release context أو الملف
 *     يعيد القالب الأصلي دائمًا.
 *   - محمل أجزاء Runtime (render_part): allowlist مغلقة لأجزاء
 *     dashboard الاثني عشر، تمرير args صريحة بحارس EXTR_SKIP، وفحص
 *     containment نفسه قبل كل include. لا تنفذ الأجزاء عمليات كتابة
 *     ولا تتجاوز registry/capabilities (§8.3) — فحص القدرة داخل كل
 *     جزء وبوابات B3-08 في نقاط الاستهلاك.
 *
 * ملف مصدر واحد مشترك (§6.1): هذه النسخة في includes/ هي مصدر الحقيقة
 *؛ نسخة الـRuntime في runtime/infrastructure/class-template-controller.php
 * (يولدها البناء من هذا الملف — §6.1). لا business logic هنا ولا AJAX
 * ولا secrets؛ بلا state ولا network.
 * حارس class_exists بعد تدقيق 2026-09-17 (منع fatal عند تحميل النسختين في عملية واحدة مستقبلًا — لا مسار لذلك اليوم)
 * ══════════════════════════════════════════════════════════════
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'HAL_Frontend_Dashboard_Template_Controller' ) ) {
	final class HAL_Frontend_Dashboard_Template_Controller {

		/**
		 * Option اسمها العقد المعتمد (§7.5) — يكتبه Installer بعد التحقق من
		 * WP_Post ونوعه وحالته. غيابها أو صف مشذر = لا صفحة هدف (فشل آمن).
		 */
		const PAGE_ID_OPTION = 'hal_frontend_dashboard_page_id';

		/** مسار قالب الـshell النسبي داخل جذر الـrelease. */
		const TEMPLATE_RELATIVE = 'templates/dashboard.php';

		/**
		 * أجزاء dashboard الاثنا عشر المعتمدة (§18/§19) — allowlist مغلقة؛
		 * أي اسم آخر يرفض فشل مغلقًا قبل أي وصول لنظام الملفات.
		 */
		const PANEL_PARTS = array(
			'overview', 'posts', 'files', 'bookings', 'seo', 'translations',
			'profile', 'inbox', 'finance', 'store', 'admin', 'members',
		);

		/** تسجيل فلتر القالب — يستدعى مرة واحدة عند تحميل الملف في الـRuntime. */
		public static function register(): void {
			add_filter( 'template_include', array( self::class, 'resolve_template' ), 10, 1 );
		}

		/**
		 * الـpage ID المملوك من Installer، أو 0 عند الغياب/عدم الصلاحية.
		 * تحقق قراءة مطابق لـInstaller: WP_Post من نوع page منشورة أو خاصة.
		 */
		public static function get_owned_page_id(): int {
			$page_id = absint( get_option( self::PAGE_ID_OPTION, 0 ) );
			if ( $page_id < 1 ) {
				return 0;
			}
			$post = get_post( $page_id );
			if ( ! $post instanceof WP_Post || 'page' !== $post->post_type
				|| ! in_array( $post->post_status, array( 'publish', 'private' ), true ) ) {
				return 0;
			}
			return $page_id;
		}

		/**
		 * هويات الصفحات الهدف: المملوك + ترجماته WPML عبر الفلاتر الرسمية
		 * (wpml_element_trid/wpml_get_element_translations بنوع post_page).
		 * غياب WPML يعيد [المملوك] فقط. بلا حالة مملوكة تعيد [].
		 *
		 * @return int[]
		 */
		public static function get_target_page_ids(): array {
			$owned = self::get_owned_page_id();
			if ( $owned < 1 ) {
				return array();
			}

			$ids = array( $owned );

			if ( defined( 'ICL_SITEPRESS_VERSION' ) && has_filter( 'wpml_element_trid' ) ) {
				$trid = apply_filters( 'wpml_element_trid', null, $owned, 'post_page' );
				$trid = is_numeric( $trid ) ? (int) $trid : 0;
				if ( $trid > 0 ) {
					$translations = apply_filters( 'wpml_get_element_translations', null, $trid, 'post_page' );
					if ( is_array( $translations ) ) {
						foreach ( $translations as $translation ) {
							$translation_id = is_object( $translation ) && isset( $translation->element_id )
								? absint( $translation->element_id )
								: 0;
							if ( $translation_id > 0 && ! in_array( $translation_id, $ids, true ) ) {
								$ids[] = $translation_id;
							}
						}
					}
				}
			}

			return $ids;
		}

		/** هل هذه الهوية صفحة هدف (مملوك أو ترجمة WPML مثبتة)؟ */
		public static function is_target_page_id( int $page_id ): bool {
			return $page_id > 0 && in_array( $page_id, self::get_target_page_ids(), true );
		}

		/**
		 * العقد الهدف للاستهلك المشترك (template_include + enqueues +
		 * body classes — §7.5: predicate واحد نفسه). queried object من نوع
		 * page وهويته ضمن الهدف، وإلا false. لا يقرأ query parameter ولا
		 * slug عام.
		 */
		public static function is_target_query(): bool {
			$queried = get_queried_object();
			if ( ! $queried instanceof WP_Post || 'page' !== $queried->post_type ) {
				return false;
			}
			return self::is_target_page_id( (int) $queried->ID );
		}

		/**
		 * callback للـtemplate_include: قالب Runtime للصفحة الهدف حصرًا،
		 * والقالب الأصلي في كل الحالات الأخرى (fail-safe).
		 *
		 * @param mixed $template قالب WordPress الحالي.
		 * @return mixed قالب Runtime مُتحقق منه أو القالب الأصلي كما هو.
		 */
		public static function resolve_template( $template ) {
			if ( ! is_string( $template ) || '' === $template ) {
				return $template;
			}
			if ( ! self::is_target_query() ) {
				return $template;
			}

			$path = self::validated_runtime_path( self::TEMPLATE_RELATIVE );
			if ( null === $path ) {
				self::log_safe( 'template_unavailable' );
				return $template;
			}

			return $path;
		}

		/**
		 * محمل أجزاء Runtime: include جزء dashboard معتمد داخل مسار
		 * الـrelease المتحقق منه، مع args صريحة (EXTR_SKIP يحمي متغيرات
		 * النطاق الداخلية). جزء غير معتمد أو مفقود = فشل مغلق بلا إخراج.
		 *
		 * @param string               $part اسم الجزء من الـallowlist.
		 * @param array<string,mixed>  $args متغيرات صريحة مسماة يمررها الـshell.
		 */
		public static function render_part( string $part, array $args = array() ): void {
			if ( ! preg_match( '/^[a-z0-9_-]{1,40}$/', $part ) || ! in_array( $part, self::PANEL_PARTS, true ) ) {
				self::log_safe( 'invalid_panel_part' );
				return;
			}

			$path = self::validated_runtime_path( 'templates/dashboard/' . $part . '.php' );
			if ( null === $path ) {
				self::log_safe( 'panel_part_unavailable' );
				return;
			}

			extract( $args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- عقد الأجزاء: مفاتيح مسماة من الshell حصرا، والحارس EXTR_SKIP يحمي متغيرات هذه الدالة.
			include $path;
		}

		/**
		 * تحقق المسار داخل الـrelease لهذا request: release context موجود،
		 * realpath للجذر وللمسار، القراءة ممكنة، والبقاء داخل الجذر. أي
		 * فشل يعيد null (فشل آمن) — لا path من input ولا تخمين.
		 */
		private static function validated_runtime_path( string $relative ): ?string {
			if ( ! defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_DIR' ) ) {
				return null;
			}
			$root = HAL_FRONTEND_DASHBOARD_RUNTIME_DIR;
			if ( ! is_string( $root ) || '' === $root ) {
				return null;
			}

			$real_root = realpath( $root );
			if ( false === $real_root ) {
				return null;
			}

			$candidate = $root . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
			$real_path = realpath( $candidate );
			if ( false === $real_path || ! is_readable( $real_path ) ) {
				return null;
			}

			$prefix = rtrim( $real_root, '\\/' ) . DIRECTORY_SEPARATOR;
			if ( 0 !== strpos( $real_path, $prefix ) ) {
				return null;
			}

			return $real_path;
		}

		/** تشخيص آمن في log: code عام فقط — بلا مسارات أو بيانات مستخدم. */
		private static function log_safe( string $code ): void {
			error_log( 'HAL Frontend Dashboard: template ' . $code . '.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- تشخيص منقح للمسؤول فقط (§8.1).
		}
	}

	// التسجيل عند التحميل: الـRuntime MU يُحمَّل مبكرًا، والفلتر يُقيَّم وقت
	// template_include بعد اكتمال كل الوحدات — لا اعتماد على ترتيب تحميل
	// غير هذا الملف.
	HAL_Frontend_Dashboard_Template_Controller::register();
}
