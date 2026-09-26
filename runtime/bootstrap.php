<?php
/**
 * runtime/bootstrap.php — Runtime Bootstrap (HAL Frontend Dashboard)
 * ══════════════════════════════════════════════════════════════
 * نقطة تحميل (Bootstrap) فقط للـrelease المملوك للإصدار — بلا أي منطق
 * مباشر هنا. المصدر: legacy mu-plugins/hossam-dashboard.php (الدفعة 2).
 *
 * العقد (loader-core.php يعرِّف ثوابت الـrelease قبل تحميل هذا الملف):
 *   - HAL_FRONTEND_DASHBOARD_RUNTIME_DIR   مسار جذر الـrelease المنتهي
 *     بـDIRECTORY_SEPARATOR — العقد المعماري (الوثيقة §13، B2-04).
 *   - HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT  الاسم التوافقي نفسه — يعرِّفه
 *     loader-core.php:136 ويستهلكه setup.php:435. يقبل هذا الملف أيًّا
 *     من الاسمين ويعرِّف الناقص من الموجود، فيبقى كلاهما متاحًا.
 *   - HAL_FRONTEND_DASHBOARD_RUNTIME_URL   رابط الـrelease (ينتهي بـ/).
 *   - HAL_FRONTEND_DASHBOARD_RELEASE_ID / HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION.
 *
 * قاعدة إلزامية (ترتيب التحميل الإلزامي Core→Adapters→AJAX):
 *   core/setup → core/i18n → core/tables → core/permissions →
 *   core/notifications → adapters/* → ajax/*
 * ترتيب التحميل أدناه ثابت ولا يجوز تغييره. core (الدفعة 2) ثم
 * Backend/Settings/Secrets (الدفعة 3) ثم adapters/* (الدفعة 4 — تُحمَّل
 * بعد core بالكامل وقبل أي AJAX)، والدفعة 5 تضيف ajax/* (تُحمَّل بعد
 * adapters بالكامل — كل ملف ajax يفترض أن دوال core وadapters معرَّفة
 * قبله). هذا الترتيب هو ما يضمن توفر الدوال عند وقت callback، لا ترتيب
 * نظام الملفات.
 *
 * الحراسة: إذا لم يُعرَّف أيٌّ من الاسمين فالخروج سليمًا (return — لا
 * fatal ولا إطلاق runtime_ready)، لأن الـLoader المغلق يبني rollback
 * اعتمادًا على عدم إطلاق الـaction في موعد shutdown.
 *
 * READINESS (B2-01): الـaction يُطلَق عند اكتمال تحميل core (عقد
 * Loader المغلق: عمله = «الإقلاع تم»)، بينما فلتر
 * hal_frontend_dashboard_boot_readiness يُقيِّم عند كل نداء نتيجة
 * التهيئة والتحقق الفعليين (نسخة كل جدول مملوك مسجلة أو أعلى محفوظة
 * بعد migrations الـinit). فشل migration يمنع Health acknowledgement
 * الناجح (class-health-check.php يقرأ did_action + apply_filters
 * معًا) دون تغيير توقيت الـaction أو عقد الـLoader. لا static cache
 * للنتيجة: نداء مبكر قبل init يعيد false صادقة ولا يُثبَّت خطأً.
 *
 * لا state/network هنا. active-version.json وقرار المالك
 * (observability) مؤجَّل التنفيذ خارج هذه الدفعة — لا كود له هنا.
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_DIR' ) ) {
	$hal_runtime_root = HAL_FRONTEND_DASHBOARD_RUNTIME_DIR;
} elseif ( defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT' ) ) {
	$hal_runtime_root = HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT;
} else {
	// لا release context — خروج سليم بلا fatal ولا runtime_ready؛
	// الـLoader المغلق يبني rollback عند عدم إطلاق الـaction.
	return;
}

// B2-04: توحيد عقد المسار — §13 يطلب RUNTIME_DIR ومستهلكو الـLoader
// الحاليون يستهلكون RUNTIME_ROOT؛ يُعرَّف الناقص من الموجود.
if ( ! defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_DIR' ) ) {
	define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_DIR', $hal_runtime_root );
}
if ( ! defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT' ) ) {
	define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT', $hal_runtime_root );
}

// ══════════════════════════════════════════════════════════════
// CORE — بالترتيب الإلزامي، لا تُغيَّر
// ══════════════════════════════════════════════════════════════
require_once $hal_runtime_root . 'core/setup.php';
require_once $hal_runtime_root . 'core/i18n.php';
require_once $hal_runtime_root . 'core/tables.php';
require_once $hal_runtime_root . 'core/permissions.php';
require_once $hal_runtime_root . 'core/notifications.php';

// ══════════════════════════════════════════════════════════════
// BACKEND — الدفعة 3: الإعدادات والأسرار وسجل التكاملات والصحة
// التكاملية والواجهة الإدارية. تُحمَّل بعد core بالكامل وقبل
// adapters (الدفعة 4 ستستهلك عقود Settings Repository/Secret
// Store/Registry). كل ملف هنا يسجّل hooks فقط عند التحميل بلا
// استدعاءات WordPress أخرى — التنفيذ وقت الـcallback.
// ══════════════════════════════════════════════════════════════
require_once $hal_runtime_root . 'settings/class-settings-repository.php';
require_once $hal_runtime_root . 'security/class-secret-store.php';
require_once $hal_runtime_root . 'integrations/class-integration-settings-registry.php';
require_once $hal_runtime_root . 'health/class-integration-health-monitor.php';
require_once $hal_runtime_root . 'admin/class-admin-controller.php';

// ══════════════════════════════════════════════════════════════
// ADAPTERS — الدفعة 4: تُحمَّل هنا بعد core بالكامل وقبل أي AJAX،
// بالترتيب: wordpress-posts → wordpress-uploads → woocommerce →
// wpml → amelia → rankmath → ultimatemember → ai
// (لا ترتيب إلزامي بينها، لكن يجب أن تُحمَّل بعد core بالكامل).
// ══════════════════════════════════════════════════════════════
require_once $hal_runtime_root . 'adapters/wordpress-posts.php';
require_once $hal_runtime_root . 'adapters/wordpress-uploads.php';
require_once $hal_runtime_root . 'adapters/woocommerce.php';
require_once $hal_runtime_root . 'adapters/wpml.php';
require_once $hal_runtime_root . 'adapters/amelia.php';
require_once $hal_runtime_root . 'adapters/rankmath.php';
require_once $hal_runtime_root . 'adapters/ultimatemember.php';
require_once $hal_runtime_root . 'adapters/ai.php';

// ══════════════════════════════════════════════════════════════
// AJAX — الدفعتان 5 و6: تُحمَّل بعد adapters بالكامل (تستدعي دوالها
// مباشرة). كل ملف ajax يفترض أن دوال core وadapters معرَّفة قبله —
// هذا الترتيب هو ما يضمن ذلك. ترتيب الملفات الثمانية حرفي من المصدر
// (mu-plugins/hossam-dashboard.php): posts → uploads → appointments →
// finance → members → inbox → store → ai.
//
// ajax/seo.php مشحون في الـrelease ولا يُحمَّل هنا — عقد §16: «يبقى
// التحميل تابعًا لحالة registry المعتمدة؛ limited read-only»، منقولة
// كما في المصدر: السجل يثبت verified_fields=false (core/setup.php)
// وبوابة المقارنة الحية المؤجلة رسميًا لم تُجتَز، فلا يُسجَّل
// wp_ajax_hossam_save_seo في التشغيل الفعلي؛ تحميله سيغيّر action
// inventory ويخالف بوابة إغلاق الدفعة 5.
// ══════════════════════════════════════════════════════════════
require_once $hal_runtime_root . 'ajax/posts.php';
require_once $hal_runtime_root . 'ajax/uploads.php';
require_once $hal_runtime_root . 'ajax/appointments.php';
require_once $hal_runtime_root . 'ajax/finance.php';
require_once $hal_runtime_root . 'ajax/members.php';
require_once $hal_runtime_root . 'ajax/inbox.php';
require_once $hal_runtime_root . 'ajax/store.php';
require_once $hal_runtime_root . 'ajax/ai.php';

// ══════════════════════════════════════════════════════════════
// TEMPLATES — الدفعة 7: Template Controller (نسخة الـRuntime المشتركة
// §6.1 من includes/class-template-controller.php؛ البناء يولدها —
// الدفعة 11). تُحمَّل بعد كل الوحدات ولا اعتماد تحميلي عليها ولا لها
// على AJAX؛ تسجّل filter template_include فقط عند التحميل، والتقييم
// وقت template_include. عقد الدفعة 7: قالب Runtime للصفحة المملوكة
// (page ID) وترجمات WPML حصرًا — بلا slug/query parameter — ومحمل
// أجزاء dashboard بـallowlist مغلقة.
// ══════════════════════════════════════════════════════════════
require_once $hal_runtime_root . 'infrastructure/class-template-controller.php';

// ══════════════════════════════════════════════════════════════
// UPDATE BRIDGE — الدفعة 11: جسر التحديث الدائم (§6.1 نسخة مشتركة
// من includes/class-update-bridge.php؛ البناء يولدها). يُحمَّل بعد
// Template Controller مباشرة ولا اعتماد تحميلي عليه ولا له على AJAX؛
// التهيئة مقيدة بسياقات التحديث فقط (admin/cron/CLI) فلا شبكة في
// مسار الزائر — is_update_context() يعيد false خارجها فلا تُسجَّل hooks.
// ══════════════════════════════════════════════════════════════
require_once $hal_runtime_root . 'infrastructure/class-update-bridge.php';

if ( class_exists( 'HAL_Frontend_Dashboard_Update_Bridge', false ) && HAL_Frontend_Dashboard_Update_Bridge::is_update_context() ) {
	HAL_Frontend_Dashboard_Update_Bridge::init();
	if ( function_exists( 'add_action' ) ) {
		// B11-01: the deferred-import consumer. The callback loads the
		// manager lazily and only if no copy is already present, so a
		// process that loaded the includes/ copy (installer, harnesses)
		// never sees a duplicate declaration; production cron loads the
		// infrastructure copy behind the MU release context.
		add_action(
			HAL_Frontend_Dashboard_Update_Bridge::IMPORT_CRON_HOOK,
			static function () use ( $hal_runtime_root ): void {
				if ( ! class_exists( 'HAL_Frontend_Dashboard_Release_Manager', false ) ) {
					require_once $hal_runtime_root . 'infrastructure/class-release-manager.php';
				}
				HAL_Frontend_Dashboard_Release_Manager::import_candidate_cron();
			}
		);
	}
}

unset( $hal_runtime_root );

// ══════════════════════════════════════════════════════════════
// READINESS — عقد Health Check المغلق بعد B2-01:
//   - الـaction يُطلَق هنا عند اكتمال تحميل core (عقد Loader المغلق:
//     عمل الـaction = «الإقلاع تم»؛ غيابه في shutdown يبني rollback).
//   - الفلتر لا يمنح true مجانًا: يُقيِّم عند كل نداء نتيجة التهيئة
//     والتحقق الفعليين، ففشل migration يمنع Health acknowledgement
//     الناجح (handle_request يقرأ did_action + apply_filters معًا).
// ══════════════════════════════════════════════════════════════

if ( ! function_exists( 'hossam_dashboard_core_boot_state_verified' ) ) {
	/**
	 * B2-01: نتيجة التهيئة والتحقق الفعليين لجاهزية Core.
	 *
	 * تُقيَّم حيًا عند كل نداء بلا static cache — أي نداء قبل init
	 * (قبل migrations) يعيد false صادقة، وبعد نجاح migrations يعيد
	 * true: كل جدول مملوك يحمل نسخة مسجلة تطابق هدف الكود أو أعلى
	 * محفوظة (B2-02). يُفشل مغلقًا إن لم تكن دالة السكيمات معرَّفة.
	 */
	function hossam_dashboard_core_boot_state_verified(): bool {
		if ( ! function_exists( 'hossam_dashboard_table_schemas' ) ) {
			return false;
		}

		foreach ( hossam_dashboard_table_schemas() as $definition ) {
			$recorded = get_option( $definition['option'] );
			if ( ! is_string( $recorded ) || '' === $recorded
				|| ! version_compare( $recorded, $definition['version'], '>=' ) ) {
				return false;
			}
		}

		return true;
	}
}

if ( ! function_exists( 'hossam_dashboard_core_boot_ready' ) ) {
	/**
	 * فلتر hal_frontend_dashboard_boot_readiness — حالة الجاهزية
	 * الفعلية، لا إقرار ثابت. وسائط الـrelease الممرَّرة من
	 * class-health-check.php تُقبل وتُتجاهل هنا (تطابق الإصدار تتحقق
	 * في consume() عبر تحدي الـhealth المغلق).
	 */
	function hossam_dashboard_core_boot_ready(): bool {
		return hossam_dashboard_core_boot_state_verified();
	}
}

add_filter( 'hal_frontend_dashboard_boot_readiness', 'hossam_dashboard_core_boot_ready' );
do_action( 'hal_frontend_dashboard_runtime_ready' );
