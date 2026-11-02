<?php
/**
 * runtime/core/audit-log.php — وحدة سجل التدقيق والاحتفاظ (v1.1.0 — صف R2 موسوم «دمج v1.1.0» — ملف جديد)
 * ══════════════════════════════════════════════════════════════
 * الدور: الصمام الخادمي — جدول {$wpdb->prefix}hossam_audit_log + الدالة
 * الموحدة hossam_audit_log() + القائمة المغلقة 20 حدثًا + المستمع الواحد
 * لhook الأسرار + سياسة H1 + محرك الاحتفاظ الرباعي.
 *
 * المصدر الحاكم: G4-AUDIT-LOG-POSITION.md — الوثيقة المختومة
 * (sha256 = 21ea8cefdfaeecba5d58ff817f65eb5436c9f8ff35dcccbe9961dc6e73a5fa97،
 * أُثبتت بsha256sum قبل أي نقل). النقل الحرفي (تصويب K4/K8 E4 —
 * استقلالية العقد): §2 كتلة الأعمدة العشرة وأنواعها والفهارس الخمسة
 * (بملاحظات الأعمدة الصامدة)، §3-أ جدول object_type لكل حدث،
 * receiver_id لميتا message_send، البنود الأربعة المتبقية من عقد
 * hossam_audit_log الثماني، وصياغة §6-1 الحرفية (فحص القيمة والوجود لا
 * حالة autoload). الدلتا الموسومة من القائمة: الحدث 20 secret_key_mismatch
 * والفرع الرابع في المستمع (H7 §2-ج-4) + الحدث 19 article_h1_normalized
 * (حسم Q5 — دفاع G2 العميق).
 *
 * ═══ عقد hossam_audit_log( string $event_action, array $context ): bool الثماني ═══
 *   1. فشل الكتابة يُبتلع مع error_log ولا يغيّر سير المنادي — القيمة
 *      المرجعة تُتجاهل عمدًا في كل نقاط النداء (G4 §5).
 *   2. user_id من الجلسة حصرًا (get_current_user_id) — صفر superglobals
 *      في الملف ومسار الكتابة كله: لا $_POST/$_GET/$_REQUEST/$_COOKIE.
 *      الاستثناء الموثق الوحيد عند تمكين ip_enabled: قراءة
 *      $_SERVER['REMOTE_ADDR'] حصرًا مع FILTER_VALIDATE_IP وإلا NULL —
 *      رفض X-Forwarded-For نهائيًا (راس منتِحل يجعل «الدليل» قابلاً
 *      للتأليف — Q4-1).
 *   3. لقطات الهوية: user_login VARCHAR(60) من الجلسة (يبقى مقروءًا بعد
 *      حذف المستخدم)؛ object_title لقطة مقصوصة sanitize_text_field +
 *      mb_substr(0,255) غير مفهرسة.
 *   4. meta: JSON منقح بمفاتيح مغلقة لكل حدث (جدول الأحداث أدناه) وقيم
 *      scalar منقحة (ومصفوفة أسماء للأحداث 6/15) — وسقف 64KB: تجاوزه
 *      يُسقط الحقل كله مع error_log والحدث يبقى بأساسه في الأعمدة.
 *   5. INSERT مُحضَّر واحد بNULL inline (نمط ai.php UPDATE مُجهَّز
 *      :99-103): أعمدة object_id/meta/ip تُنقل NULL حرفيًا في SQL عند
 *      غيابها — NULL يعني «لا كائن» والصفر مزيف يلوّث الفهرس.
 *   6. created_at UTC صريح (gmdate) — متسقًا مع cutoff بgmdate في
 *      محرك الاحتفاظ (Q4-3/بند القبول 9).
 *   7. مفتاح الإيقاف enabled=false ⇒ تعيد true بلا كتابة (Q4-5) — يوقف
 *      كتابة السجل وحصة audit_log في الكسح حصرًا، بلا unschedule
 *      (احتفاظ الجداول الثلاثة الأخرى مستقل عن المفتاح).
 *   8. event_action خارج القائمة المغلقة العشرين يُتجاهل دفاعيًا
 *      (false + error_log) — القائمة مغلقة لا تُفتح إلا بقرار مالك.
 *   كما أن object_type مستمد من الجدول المغلق حصرًا لا من النداء.
 *
 * ═══ عقد كاتب واحد (G4 موقع Q3-3 / §6-7) ═══
 * hossam_audit_log_db_version = '1.0.0' يكتبه hossam_dashboard_ensure_table
 * حصرًا (نمط tables.php:196 بلا وسيط autoload — autoloaded كما هي حالة
 * خيارات النسخ الثلاثة القائمة) — صفر update_option/add_option على خيار
 * النسخة من هذا الملف إطلاقًا. فحص §6-1: القيمة والوجود فقط، لا حالة
 * autoload التي يحددها مسار النواة نفسها. خيار الإعدادات
 * hossam_audit_log_settings يُنشأ add_option(…, false) فحسب عند أول
 * كتابة فعلية وكل كتابة لاحقة update_option(…, false) — الوسيط false
 * حاضر دوومًا (بصمة C7 الصحيحة؛ درس option.php:1298+940).
 *
 * ═══ أمر الإيقاف الآمن الموثق للمالك (Q4-5 — بلا UI في v1.1.0) ═══
 *   wp eval 'update_option("hossam_audit_log_settings",["enabled"=>false,"days"=>90,"ip_enabled"=>false],false);'
 * تحذير موثق: لا تستخدم `wp option update` على هذا الخيار (بلا وسيط
 * autoload) — يعيد ضبطه 'auto-on' (C7 حرفيًا). الإيقاف لا يُلغي الجدولة
 * (unschedule مرفوض — كلفة الحدث المتبقي ≈ صفر ويعاود التمكين تلقائيًا).
 *
 * ═══ حد التلاعب المقبول (Q4-7 — معلن لا مخفي) ═══
 * السجل غير مقاوم لمهاجم يملك قاعدة البيانات كاملة (كود ببيانات اعتماد
 * DB يستطيع UPDATE/DELETE). لا مسار تعديل/حذف من التطبيق إلا cron
 * الاحتفاظ، ولا تصدير خارج الموقع ولا توقيع صفوف في v1.1.0 — الجدول
 * ضمن نسخ DB الاحتياطية ذاتها. أي توسعة = قرار مالك في دورة لاحقة.
 *
 * ═══ نقطة الربط المستقبلية الوحيدة (Q4-2) ═══
 * amelia_after_booking_added (notifications.php:128) — مستمع المواعيد
 * يُضاف لاحقًا من هذا الملف حصرًا؛ appointments.php لا يُلمس فالقيد 9
 * محفوظ بنيويًا. لا endpoints جديدة (صفر wp_ajax_/register_rest_route)،
 * ولا مواعيد إضافية غير الحدث الواحد hossam_dashboard_retention_sweep_event.
 *
 * ═══ عقد التكامل مع الزومبي (H2 §4-5 — موسوم دلتا) ═══
 * الاحتفاظ terminal-only (حصة ai_jobs تحذف status IN
 * ('completed','failed') بتاريخ updated_at فقط) وإنتاج terminal
 * مسؤولية آلة الحالة (صف R30 على runtime/ajax/ai.php). صفر مواضع نداء
 * audit جديدة من الزومبي: حدث ai_fail يتدفق من دالتي الاختناق
 * القائمتين (hossam_ai_fail_job / hossam_ai_fail_job_if_outstanding).
 * قيم meta.reason الجديدتين attempts_exhausted/orphaned_pending بيانات
 * في حقل لا أعمدة جديدة — تعبر sanitize_key (ai.php:116/:146) وسقف
 * meta 64KB هنا، وتُقبل لأن reason مفتاح مغلق مسبقًا للحدث 14.
 *
 * ممنوعات الصف (R2): صفر تعديل tables.php/appointments.php/secret-store
 * (المستمع يستهلك hook الأسرار من الخارج بتوقيعه وحمولته الرباعية بلا
 * أي تغيير — G4 §6-11)؛ لا IP افتراضيًا (ip_enabled=false)؛ لا unschedule
 * عند الإيقاف؛ لا تصدير/توقيع صفوف؛ سياسة H1: لا فحص قبل kses (الفحص
 * على الحمولة المخزَّنة بعد wp_kses_post — wp_insert_post_data يُطبَّق
 * بعد sanitize_post، مرجع نواة 6.7.1 post.php:4761) ولا على post_title
 * ولا خارج نوع post.
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'hossam_audit_log_table_definition' ) ) {
	/**
	 * تعريف جدول السجل — النقل الحرفي بايت-ببايت من G4 §2 (الكتلة E4
	 * المختومة): الأعمدة العشرة بأسمائها وأنواعها وترتيبها + الفهارس
	 * الخمسة + قواعد dbDelta (KEY لا INDEX، بلا backticks،
	 * `PRIMARY KEY  (id)` بمسافتين — تقاليد schema.php النووية) +
	 * ملاحظات الأعمدة الصامدة: user_login VARCHAR(60) لقطة هوية
	 * تطابق wp_users.user_login؛ object_title VARCHAR(255) لقطة مقصوصة
	 * غير مفهرسة؛ meta LONGTEXT JSON منقح بنمط input_data؛ ip VARCHAR(45)
	 * يسع IPv6 وNULL افتراضيًا؛ object_id nullable؛ created_at DATETIME
	 * DEFAULT CURRENT_TIMESTAMP بنمط الجداول الثلاثة والكتابة الفعلية
	 * تمرر UTC صريحًا. الفرق عن A4 §1 حرفيًا: action→event_action
	 * (C3-2 — ACTION محجوزة MySQL 8؛ إغلاق فشل 1064 الصامت) — لا شيء آخر.
	 *
	 * الشكل مسطَّح (بلا غلاف 'audit_log' التوضيحي في كتلة G4) لأن نداء
	 * init الحرفي ذاته يمرر قيمة هذه الدالة مباشرة إلى
	 * hossam_dashboard_ensure_table — وكلا الشكلين من المختوم.
	 *
	 * @return array{table_suffix:string, version:string, option:string, columns:string[], indexes:string[], schema:callable(string,string):string}
	 */
	function hossam_audit_log_table_definition(): array {
		return [
			'table_suffix' => 'hossam_audit_log',
			'option'       => 'hossam_audit_log_db_version',
			'version'      => '1.0.0',
			'columns'      => [ 'id', 'user_id', 'user_login', 'event_action', 'object_type', 'object_id', 'object_title', 'meta', 'ip', 'created_at' ],
			'indexes'      => [ 'PRIMARY', 'created_at', 'user_id', 'object_id', 'event_action' ],
			'schema'       => static function ( string $table, string $charset ): string {
				return "CREATE TABLE {$table} (
		id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id       BIGINT UNSIGNED NOT NULL,
		user_login    VARCHAR(60)     NOT NULL DEFAULT '',
		event_action  VARCHAR(64)     NOT NULL,
		object_type   VARCHAR(32)     NOT NULL DEFAULT '',
		object_id     BIGINT UNSIGNED          DEFAULT NULL,
		object_title  VARCHAR(255)    NOT NULL DEFAULT '',
		meta          LONGTEXT                 DEFAULT NULL,
		ip            VARCHAR(45)              DEFAULT NULL,
		created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY created_at (created_at),
		KEY user_id (user_id),
		KEY object_id (object_id),
		KEY event_action (event_action)
	) {$charset};";
			},
		];
	}
}

// نمط tables.php:200-219 حرفيًا (G4 §2 — الكتلة الحرفية): init بguard على
// القيمة والوجود (لا حالة autoload — صياغة §6-1) وإفصاح error_log.
add_action( 'init', function (): void {
	hossam_dashboard_ensure_table( hossam_audit_log_table_definition() );
	if ( ! get_option( 'hossam_audit_log_db_version' ) ) {
		error_log( 'HAL audit log: table ensure did not complete; init will retry.' ); // إفصاح الفشل الصامت (V4 §5-8) — يستمر فقط طالما الحالة مكسورة
	}
} );

if ( ! function_exists( 'hossam_audit_log_get_settings' ) ) {
	/**
	 * قراءة hossam_audit_log_settings — كسولية بmemo لكل طلب (Q4-1):
	 * طلب بلا أحداث تدقيق = صفر استعلام على خيارات السجل بعد أول قراءة.
	 * الافتراضيات في الكود: enabled=true، ip_enabled=false، days=90
	 * (بclamp 1..365 عند القراءة — Q4-3). الوضع الافتراضي بلا صف options
	 * مكتوب إطلاقًا — وهذا مقصود (Q4-1: الخيار يُنشأ عند أول كتابة فعلية).
	 *
	 * @return array{enabled:bool, ip_enabled:bool, days:int}
	 */
	function hossam_audit_log_get_settings(): array {
		static $memo = null;
		if ( null === $memo ) {
			$raw  = get_option( 'hossam_audit_log_settings', [] );
			$raw  = is_array( $raw ) ? $raw : [];
			$memo = [
				'enabled'    => ! array_key_exists( 'enabled', $raw ) || (bool) $raw['enabled'],
				'ip_enabled' => array_key_exists( 'ip_enabled', $raw ) && (bool) $raw['ip_enabled'],
				'days'       => max( 1, min( 365, (int) ( $raw['days'] ?? 90 ) ) ),
			];
		}

		return $memo;
	}
}

if ( ! function_exists( 'hossam_audit_log_update_settings' ) ) {
	/**
	 * مسار كتابة الإعدادات الوحيد — بصمة C7 الصحيحة (Q4-1): أول كتابة
	 * فعلية على خيار غائب = add_option(…, false) فحسب، وكل كتابة لاحقة
	 * update_option(…, false) — الوسيط false حاضر دوومًا (أي كتابة بغيره
	 * تعيد ضبط autoload 'auto-on' — option.php:1298+940). التطابق مع
	 * المخزَّن لا يكتب (نمط B3-02: update_option يعيد false في الحالتين).
	 * لا مستهلك في v1.1.0 (بلا UI) — مسار المالك/CLI/واجهة مستقبلية.
	 * مفاتيح مغلقة حصرًا: enabled/ip_enabled/days (days بclamp 1..365).
	 *
	 * @param array $settings قيم جزئية مسموحة.
	 * @return bool نجاح الكتابة أو التطابق.
	 */
	function hossam_audit_log_update_settings( array $settings ): bool {
		$payload = [
			'enabled'    => array_key_exists( 'enabled', $settings ) ? (bool) $settings['enabled'] : true,
			'ip_enabled' => array_key_exists( 'ip_enabled', $settings ) ? (bool) $settings['ip_enabled'] : false,
			'days'       => max( 1, min( 365, (int) ( $settings['days'] ?? 90 ) ) ),
		];

		$current = get_option( 'hossam_audit_log_settings' );
		if ( is_array( $current ) && $current === $payload ) {
			return true;
		}

		if ( ! is_array( $current ) ) {
			// أول كتابة فعلية — الوسيط false حاضر دوومًا (عقد كاتب الإعدادات).
			return (bool) add_option( 'hossam_audit_log_settings', $payload, false );
		}

		return (bool) update_option( 'hossam_audit_log_settings', $payload, false );
	}
}

if ( ! function_exists( 'hossam_audit_log_events' ) ) {
	/**
	 * القائمة المغلقة 20 حدثًا — الجدول المنقول من G4 §3-أ (أحداث 1-18
	 * حرفيًا) + الدلتا الموسومة (الصفان 19/20 من قائمة v1.1.0 صف R2-ج).
	 * بنية كل صف: object_type الثابت للحدث + مفاتيح meta المغلقة.
	 * نجاحات فقط — مسارات 401/403/404/500 صفر صفوف؛ استبعاد read-state
	 * للإشعارات (ضجيج بلا قيمة جنائية)؛ المواعيد مستبعدة (Q4-2).
	 *
	 * | #  | event_action           | object_type | object_id            | meta (مفاتيح مغلقة)                    | موضع النداء (G4 §3-أ — المرحلة 2)                     |
	 * |----|------------------------|-------------|----------------------|----------------------------------------|--------------------------------------------------------|
	 * | 1  | article_create         | article     | id المقال الجديد     | status                                 | posts.php:347 قبل wp_send_json_success                 |
	 * | 2  | article_update         | article     | id المقال            | status                                 | posts.php:403 قبل wp_send_json_success                 |
	 * | 3  | article_trash          | article     | id المقال            | —                                      | posts.php:433 قبل wp_send_json_success                 |
	 * | 4  | article_restore        | article     | id المقال            | —                                      | posts.php:131 قبل wp_send_json_success                 |
	 * | 5  | article_translate      | article     | id المقال المصدر     | new_post_id, lang                      | posts.php:287 فرع الإنشاء الجديد حصرًا                 |
	 * | 6  | article_save_seo       | article     | id المقال            | fields (أسماء الحقول لا قيم)           | seo.php:92 قبل wp_send_json_success                    |
	 * | 7  | file_upload            | file        | id المرفق            | mime                                   | uploads.php:154 قبل wp_send_json_success               |
	 * | 8  | file_delete            | file        | id المرفق            | mime (يُلتقط قبل الحذف)                | uploads.php:133 (حذف قسري :129)                        |
	 * | 9  | file_restore           | file        | id المرفق            | —                                      | uploads.php:108 بعد wp_untrash_post الناجح             |
	 * | 10 | message_send           | message     | id الرسالة المدرجة   | receiver_id (لا نص الرسالة إطلاقًا)    | inbox.php:253 (الإدراج :238 — الإرسال إداري حصرًا)     |
	 * | 11 | message_mark_read      | message     | id الرسالة           | —                                      | inbox.php:339 قبل wp_send_json_success                 |
	 * | 12 | ai_submit              | ai_job      | id المهمة            | job_type, post_id, strategy_id         | ai.php بعد بوابة الخطأ :384-390 وقبل الجدولة :392-397  |
	 * | 13 | ai_complete            | ai_job      | id المهمة            | job_type (لا result)                   | ai.php بعد UPDATE الاكتمال :513-526 بguard rows_affected |
	 * | 14 | ai_fail                | ai_job      | id المهمة            | reason (sanitize_key مسبقًا :116/:146)  | موضعان: داخل hossam_ai_fail_job (:121) و…_if_outstanding (:152) |
	 * | 15 | settings_save          | settings    | NULL                 | keys (أسماء المفاتيح المكتوبة لا قيم)  | class-admin-controller.php:219 بعد نجاح save() بguard  |
	 * | 16 | ai_preference_set      | settings    | NULL                 | preference                             | ai.php:674 قبل wp_send_json_success                    |
	 * | 17 | secret_set             | secret      | NULL                 | secret_id                              | المستمع المشترك أدناه (hook class-secret-store.php:313)|
	 * | 18 | secret_delete          | secret      | NULL                 | secret_id                              | المستمع المشترك أدناه                                  |
	 * | 19 | article_h1_normalized  | article     | id المقال (NULL جديد)| count                                  | معالج wp_insert_post_data أدناه (حسم Q5 — الدلتا)      |
	 * | 20 | secret_key_mismatch    | secret      | NULL                 | secret_id                              | فرع 'key_mismatch' في المستمع (H7 §2-ج-4 — الدلتا)     |
	 *
	 * الصفان 19/20: 19 بنفس بنية أحداث article؛ 20 بنفس بنية صفّي 17/18
	 * (object_type='secret'، object_id=NULL، meta secret_id) حرفيًا.
	 *
	 * @return array<string, array{object_type:string, meta_keys:string[]}>
	 */
	function hossam_audit_log_events(): array {
		return [
			// [وسم دلتا R8 — v1.1.0]: meta يضيف lang بجانب status (العقد :148).
			'article_create'       => [ 'object_type' => 'article', 'meta_keys' => [ 'status', 'lang' ] ],
			'article_update'       => [ 'object_type' => 'article', 'meta_keys' => [ 'status' ] ],
			'article_trash'        => [ 'object_type' => 'article', 'meta_keys' => [] ],
			'article_restore'      => [ 'object_type' => 'article', 'meta_keys' => [] ],
			'article_translate'    => [ 'object_type' => 'article', 'meta_keys' => [ 'new_post_id', 'lang' ] ],
			'article_save_seo'     => [ 'object_type' => 'article', 'meta_keys' => [ 'fields' ] ],
			'file_upload'          => [ 'object_type' => 'file', 'meta_keys' => [ 'mime' ] ],
			'file_delete'          => [ 'object_type' => 'file', 'meta_keys' => [ 'mime' ] ],
			'file_restore'         => [ 'object_type' => 'file', 'meta_keys' => [] ],
			'message_send'         => [ 'object_type' => 'message', 'meta_keys' => [ 'receiver_id' ] ],
			'message_mark_read'    => [ 'object_type' => 'message', 'meta_keys' => [] ],
			'ai_submit'            => [ 'object_type' => 'ai_job', 'meta_keys' => [ 'job_type', 'post_id', 'strategy_id' ] ],
			'ai_complete'          => [ 'object_type' => 'ai_job', 'meta_keys' => [ 'job_type' ] ],
			'ai_fail'              => [ 'object_type' => 'ai_job', 'meta_keys' => [ 'reason' ] ],
			'settings_save'        => [ 'object_type' => 'settings', 'meta_keys' => [ 'keys' ] ],
			'ai_preference_set'    => [ 'object_type' => 'settings', 'meta_keys' => [ 'preference' ] ],
			'secret_set'           => [ 'object_type' => 'secret', 'meta_keys' => [ 'secret_id' ] ],
			'secret_delete'        => [ 'object_type' => 'secret', 'meta_keys' => [ 'secret_id' ] ],
			'article_h1_normalized' => [ 'object_type' => 'article', 'meta_keys' => [ 'count' ] ],
			'secret_key_mismatch'  => [ 'object_type' => 'secret', 'meta_keys' => [ 'secret_id' ] ],
		];
	}
}

if ( ! function_exists( 'hossam_audit_log_sanitize_meta_value' ) ) {
	/**
	 * تنقيح قيمة meta — قيم scalar منقحة (G4 §3-أ الثابت): int/float/bool
	 * كما هي، string عبر sanitize_text_field، ومصفوفة أسماء (للحدثين
	 * 6/15: fields/keys — أسماء حقول/مفاتيح حصرًا لا قيم) كعناصر
	 * strings/ints منقحة؛ أي نوع آخر يُسقط دفاعيًا (null).
	 *
	 * @param mixed $value القيمة الخام.
	 * @return mixed scalar منقح أو مصفوفة أسماء أو null عند الإسقاط.
	 */
	function hossam_audit_log_sanitize_meta_value( $value ) {
		if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			return sanitize_text_field( $value );
		}
		if ( is_array( $value ) ) {
			$items = [];
			foreach ( $value as $item ) {
				if ( is_string( $item ) && '' !== $item ) {
					$items[] = sanitize_text_field( $item );
				} elseif ( is_int( $item ) ) {
					$items[] = $item;
				}
			}

			return [] === $items ? null : $items;
		}

		return null;
	}
}

if ( ! function_exists( 'hossam_audit_log' ) ) {
	/**
	 * الدالة الموحدة لكتابة صف تدقيق واحد — العقد الثماني موثق في رأس
	 * الملف (G4 §3-أ الثابت + Q4-1/Q4-5). مستوى النداء: بعد نجاح العملية
	 * (أو بعد UPDATE أثّر فعلاً للحدثين 13/14 بguard rows_affected) وقبل
	 * wp_send_json_success/redirect — وقيمة الإرجاع تُتجاهل عمدًا ولا
	 * يغيّر أي نداء مسار الاستجابة (G4 §5).
	 *
	 * سياق النداء (مفاتيح مفتوحة تُقبل، والمغاير يُتجاهل):
	 *   object_id    => int|null (0/سالب/غائب ⇒ NULL — الصفر مزيف يلوّث الفهرس)
	 *   object_title => string لقطة مقصوصة (sanitize + mb_substr 255)
	 *   meta         => array يُنقّى على مفاتيح الحدث المغلقة حصرًا
	 *
	 * @param string $event_action اسم الحدث من القائمة المغلقة العشرين.
	 * @param array  $context       سياق نداء اختياري.
	 * @return bool نجاح الكتابة (أو true عند الإيقاف — بلا كتابة).
	 */
	function hossam_audit_log( string $event_action, array $context = [] ): bool {
		global $wpdb;

		// (7) مفتاح الإيقاف: enabled=false ⇒ بلا كتابة — true بلا مساس (Q4-5).
		$settings = hossam_audit_log_get_settings();
		if ( ! $settings['enabled'] ) {
			return true;
		}

		$events = hossam_audit_log_events();
		if ( ! isset( $events[ $event_action ] ) ) {
			// (8) خارج القائمة المغلقة — تجاهل دفاعي (بقية القائمة صامدة).
			error_log( 'HAL audit log: event_action outside the closed list ignored: ' . $event_action );

			return false;
		}
		$event = $events[ $event_action ];

		// (2) الهوية من الجلسة حصرًا — صفر superglobals في المسار كله.
		$user_id    = (int) get_current_user_id();
		$user       = $user_id > 0 ? get_userdata( $user_id ) : false;
		$user_login = ( $user && ! empty( $user->user_login ) )
			? mb_substr( sanitize_text_field( (string) $user->user_login ), 0, 60 )
			: '';

		$object_id = null;
		if ( isset( $context['object_id'] ) && is_numeric( (string) $context['object_id'] ) && (int) $context['object_id'] > 0 ) {
			$object_id = (int) $context['object_id'];
		}

		$object_title = isset( $context['object_title'] ) && is_string( $context['object_title'] )
			? mb_substr( sanitize_text_field( $context['object_title'] ), 0, 255 )
			: '';

		// (4) meta: مفاتيح مغلقة + قيم منقحة + JSON + سقف 64KB.
		$meta_raw = isset( $context['meta'] ) && is_array( $context['meta'] ) ? $context['meta'] : [];
		$allowed  = array_fill_keys( $event['meta_keys'], true );
		$meta     = [];
		foreach ( $meta_raw as $meta_key => $meta_value ) {
			if ( ! isset( $allowed[ (string) $meta_key ] ) ) {
				continue;
			}
			$clean = hossam_audit_log_sanitize_meta_value( $meta_value );
			if ( null !== $clean ) {
				$meta[ (string) $meta_key ] = $clean;
			}
		}
		$meta_json = [] === $meta ? null : wp_json_encode( $meta );
		if ( is_string( $meta_json ) && strlen( $meta_json ) > 65536 ) {
			error_log( 'HAL audit log: meta exceeded the 64KB cap; meta dropped for ' . $event_action . '.' );
			$meta_json = null;
		}

		// (2/استثناء ip): NULL افتراضيًا؛ عند التمكين REMOTE_ADDR حصرًا مع
		// FILTER_VALIDATE_IP — رفض X-Forwarded-For نهائيًا (Q4-1).
		$ip = null;
		if ( $settings['ip_enabled'] ) {
			$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
			$valid  = filter_var( wp_unslash( $remote ), FILTER_VALIDATE_IP );
			$ip     = false === $valid ? null : (string) $valid;
		}

		// (5) INSERT مُحضَّر واحد بNULL inline — أعمدة NULL تنقل حرفيًا في SQL
		//     (نمط ai.php UPDATE مُجهَّز :99-103 — سلوك موحد عبر إصدارات wpdb).
		$placeholders = '( %d, %s, %s, %s, '
			. ( null === $object_id ? 'NULL' : '%d' ) . ', %s, '
			. ( null === $meta_json ? 'NULL' : '%s' ) . ', '
			. ( null === $ip ? 'NULL' : '%s' ) . ', %s )';
		$args = [ $user_id, $user_login, $event_action, $event['object_type'] ];
		if ( null !== $object_id ) {
			$args[] = $object_id;
		}
		$args[] = $object_title;
		if ( null !== $meta_json ) {
			$args[] = $meta_json;
		}
		if ( null !== $ip ) {
			$args[] = $ip;
		}
		// (6) UTC صريح — مستقل عن timezone الموقع ومتسقًا مع cutoff بgmdate.
		$args[] = gmdate( 'Y-m-d H:i:s' );

		$table = $wpdb->prefix . 'hossam_audit_log';
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table}
					( user_id, user_login, event_action, object_type, object_id, object_title, meta, ip, created_at )
				VALUES {$placeholders}",
				$args
			)
		);

		// (1) فشل الكتابة يُبتلع مع error_log ولا يغيّر سير المنادي إطلاقًا.
		if ( '' !== (string) $wpdb->last_error ) {
			error_log( 'HAL audit log: insert failed for ' . $event_action . '.' );

			return false;
		}

		return true;
	}
}

if ( ! function_exists( 'hossam_audit_log_on_secret_audit' ) ) {
	/**
	 * المستمع الواحد لhook الأسرار hal_frontend_dashboard_secret_audit —
	 * بتوقيعه وحمولته الرباعية (action/secret_id/actor/time) بلا أي تغيير
	 * (G4 §6-11 — class-secret-store.php:313 يُستهلك من الخارج حصرًا).
	 * التفريع: 'set'→secret_set، 'delete'→secret_delete،
	 * 'key_mismatch'→secret_key_mismatch (الفرع الرابع — وسم دلتا H7
	 * §2-ج-4)، وأي قيمة أخرى تُتجاهل دفاعيًا (بقية — كذلك قيمة
	 * 'get_failed' الموثقة في docblock المخزن وغير المطلقة). actor/time
	 * في الحمولة يستهلكهما السجل من الجلسة وgmdate ذاتهما — لا يُنقلان.
	 * لا مستمع ثانٍ ولا hook عام (ممنوعات R2).
	 *
	 * @param mixed $entry حمولة الhook الرباعية من المخزن.
	 * @return void
	 */
	function hossam_audit_log_on_secret_audit( $entry ): void {
		if ( ! is_array( $entry ) ) {
			return;
		}

		$action    = isset( $entry['action'] ) && is_string( $entry['action'] ) ? $entry['action'] : '';
		$secret_id = isset( $entry['secret_id'] ) && is_string( $entry['secret_id'] ) ? $entry['secret_id'] : '';

		$map = [
			'set'          => 'secret_set',
			'delete'       => 'secret_delete',
			'key_mismatch' => 'secret_key_mismatch',
		];
		if ( ! isset( $map[ $action ] ) ) {
			return;
		}

		hossam_audit_log(
			$map[ $action ],
			[
				'object_id' => null,
				'meta'      => [ 'secret_id' => $secret_id ],
			]
		);
	}
}

add_action( 'hal_frontend_dashboard_secret_audit', 'hossam_audit_log_on_secret_audit' );

if ( ! function_exists( 'hossam_article_h1_policy' ) ) {
	/**
	 * سياسة H1 (ق1 — الافتراضي normalize: الصمام يعمل دومًا ما عدا off).
	 * القيم المغلقة: normalize | reject | off — أي قيمة فلتر أخرى تعود
	 * للافتراضي normalize (غير off يعمل). نقطة الضبط الوحيدة للمالك:
	 * add_filter( 'hossam_article_h1_policy', fn() => 'reject' | 'off' ).
	 *
	 * @return string normalize|reject|off.
	 */
	function hossam_article_h1_policy(): string {
		$policy = sanitize_key( (string) apply_filters( 'hossam_article_h1_policy', 'normalize' ) );

		return in_array( $policy, [ 'normalize', 'reject', 'off' ], true ) ? $policy : 'normalize';
	}
}

if ( ! function_exists( 'hossam_audit_log_content_has_h1' ) ) {
	/**
	 * كاشف وسم h1 في محتوى — الفحص على الحمولة المخزَّنة بعد wp_kses_post
	 * حصرًا (h1 ضمن allowlist النواة فيجاوز kses — G6 §3-أ-2). يطابق
	 * <h1> و<h1 > و<h1/> و<h1 attr> بأي حالة أحرف، ولا يطابق h10/h12.
	 *
	 * @param string $html محتوى HTML مخزَّن.
	 * @return bool هل يحتوي وسم h1 افتتاحيًا.
	 */
	function hossam_audit_log_content_has_h1( string $html ): bool {
		return (bool) preg_match( '/<h1[\s\/>]/i', $html );
	}
}

if ( ! function_exists( 'hossam_article_h1_should_reject' ) ) {
	/**
	 * دالة فحص الرفض النظيف (تُنادى لاحقًا من صف R14 —
	 * runtime/adapters/wordpress-posts.php): true فقط عندما تكون السياسة
	 * 'reject' والمحتوى يحوي h1 — النقطة التي يحوّلها المنادي إلى رفض 400
	 * نظيف في مسار الحفظ. السياسة normalize/off ترفض لا شيء هنا.
	 * الفحص على post_content المخزَّن بعد kses — لا قبل kses، ولا على
	 * post_title، ولا خارج نوع post (المستدعي يضمن النوع).
	 *
	 * @param string $post_content محتوى المقال المخزَّن (بعد kses).
	 * @return bool هل يُرفض المحتوى بسياسة reject.
	 */
	function hossam_article_h1_should_reject( string $post_content ): bool {
		if ( 'reject' !== hossam_article_h1_policy() ) {
			return false;
		}

		return hossam_audit_log_content_has_h1( $post_content );
	}
}

if ( ! function_exists( 'hossam_audit_log_normalize_h1_post_data' ) ) {
	/**
	 * معالج سياسة H1 على wp_insert_post_data (دفاع G2 العميق — حسم Q5 /
	 * الحدث 19): يحوّل <h1>→<h2> في post_content لنوع post حصرًا عند
	 * السياسة normalize ويسجل article_h1_normalized عن كل تحويل (صف واحد
	 * لكل حفظ تحوّل، meta.count = عدد وسوم h1 المحوَّلة). الفحص بعد kses:
	 * الفلتر يُطبَّق بعد sanitize_post('db') الذي يمر wp_kses_post
	 * (مرجع نواة 6.7.1 post.php:4761) — فلا فحص قبل kses ولا على
	 * post_title ولا خارج نوع post (النسخ/الautosaves نوعها revision —
	 * مستثناة بالنوع ذاته). off/reject: لا تحويل ولا تسجيل من هنا
	 * (reject تُنفَّذ من نقطة R14 عبر hossam_article_h1_should_reject).
	 *
	 * بيانات الفلتر مشطوفة (slashed) بعقد النواة — الاستبدال لا يمس
	 * backslashes فيبقى الشطف متسقًا؛ لقطة العنوان تُفكّ قبل التنقيح.
	 *
	 * @param array $data    بيانات المقال المشطوفة قبيل الكتابة.
	 * @param array $postarr المصفوفة الخام الواردة.
	 * @return array البيانات بعد التطبيع أو كما هي.
	 */
	function hossam_audit_log_normalize_h1_post_data( array $data, array $postarr ) {
		unset( $postarr );

		if ( ! isset( $data['post_type'] ) || 'post' !== $data['post_type'] ) {
			return $data;
		}
		if ( ! isset( $data['post_content'] ) || ! is_string( $data['post_content'] ) || '' === $data['post_content'] ) {
			return $data;
		}

		$policy = hossam_article_h1_policy();
		if ( 'normalize' !== $policy ) {
			return $data;
		}

		$count = (int) preg_match_all( '/<h1[\s\/>]/i', $data['post_content'] );
		if ( $count < 1 ) {
			return $data;
		}

		$content = preg_replace( '/<h1([\s\/>])/i', '<h2$1', $data['post_content'] );
		$content = is_string( $content ) ? preg_replace( '/<\/h1\s*>/i', '</h2>', $content ) : null;
		if ( ! is_string( $content ) ) {
			// فشل preg — لا مساس بالمحتوى (fail-safe صامت).
			return $data;
		}

		$data['post_content'] = $content;

		hossam_audit_log(
			'article_h1_normalized',
			[
				'object_id'    => isset( $data['ID'] ) && (int) $data['ID'] > 0 ? (int) $data['ID'] : null,
				'object_title' => isset( $data['post_title'] ) && is_string( $data['post_title'] )
					? sanitize_text_field( wp_unslash( $data['post_title'] ) )
					: '',
				'meta'         => [ 'count' => $count ],
			]
		);

		return $data;
	}
}

add_filter( 'wp_insert_post_data', 'hossam_audit_log_normalize_h1_post_data', 10, 2 );

if ( ! function_exists( 'hossam_audit_log_retention_definitions' ) ) {
	/**
	 * سجل تعريفات محرك الاحتفاظ — أربعة جداول (Q4-8 الدمج المعتمد بقيم
	 * ق3: 90/30/90/180): [key, table_suffix, date_column, days, batch].
	 * أيام ai_jobs محسوبة على updated_at وterminal-only
	 * (status IN ('completed','failed')) — عقد التكامل مع الزومبي في رأس
	 * الملف. الفلاتر لكل جدول (معيار 1 — قابلة للتبديل سطر فلتر):
	 *   hossam_retention_days_audit_log | _ai_jobs | _notifications | _messages
	 *   hossam_retention_batch_audit_log | _ai_jobs | _notifications | _messages
	 * أضافة/إزالة جدول مستقبليًا = سطر في هذا السجل (G4 Q4-8).
	 *
	 * @return array<int, array{key:string, table_suffix:string, date_column:string, days:int, batch:int, terminal_only:bool}>
	 */
	function hossam_audit_log_retention_definitions(): array {
		return [
			[
				'key'           => 'audit_log',
				'table_suffix'  => 'hossam_audit_log',
				'date_column'   => 'created_at',
				'days'          => 90,
				'batch'         => 500,
				'terminal_only' => false,
			],
			[
				'key'           => 'ai_jobs',
				'table_suffix'  => 'hossam_ai_jobs',
				'date_column'   => 'updated_at',
				'days'          => 30,
				'batch'         => 500,
				'terminal_only' => true,
			],
			[
				'key'           => 'notifications',
				'table_suffix'  => 'hossam_notifications',
				'date_column'   => 'created_at',
				'days'          => 90,
				'batch'         => 500,
				'terminal_only' => false,
			],
			[
				'key'           => 'messages',
				'table_suffix'  => 'hossam_messages',
				'date_column'   => 'created_at',
				'days'          => 180,
				'batch'         => 500,
				'terminal_only' => false,
			],
		];
	}
}

if ( ! function_exists( 'hossam_audit_log_retention_sweep_table' ) ) {
	/**
	 * حصة جدول واحد داخل الكسح اليومي — DELETE مفهرس بالتاريخ بدفعات
	 * ORDER BY id LIMIT {batch} حتى 20 دفعة (سقف 20×batch صفًا/تشغيل —
	 * Q4-4)، والفائض يتعوضه تلقائيًا في الدورة التالية (cutoff تاريخي
	 * فالعملية idempotent وتتسامح مع فوات wp-cron). صفر مدخل مستخدم في
	 * أي DELETE: cutoff بgmdate من قيمة days المضبوطة، وbatch/days من
	 * سجل التعريفات والفلاتر المعلنة (بclamp days 1..365)، وشرط
	 * terminal-only ثابت مكتوب حرفيًا — لا شيء من الطلب أصلًا (cron داخلي).
	 *
	 * مفتاح الإيقاف: enabled=false يوقف حصة audit_log حصرًا (Q4-5) —
	 * بلا unschedule؛ حصص الجداول الثلاثة الأخرى مستقلة عن المفتاح.
	 * عزل الفشل: فشل DELETE ⇒ error_log + عودة — الدورة التالية تعيد
	 * ولا تلف بيانات ممكنة؛ فشل جدول لا يوقف بقية الحصص (العزل عند
	 * المستدعي).
	 *
	 * @param wpdb  $wpdb            كائن قاعدة البيانات (بلا type-hint —
	 *                               سلوك الكود القائم كله global $wpdb).
	 * @param array $def             تعريف الجدول من سجل التعريفات.
	 * @param bool  $audit_enabled   قيمة مفتاح enabled الحالي.
	 * @return int عدد الصفوف المحذوفة من هذا الجدول.
	 */
	function hossam_audit_log_retention_sweep_table( $wpdb, array $def, bool $audit_enabled ): int {
		if ( 'audit_log' === $def['key'] && ! $audit_enabled ) {
			return 0;
		}

		$days  = max( 1, min( 365, (int) apply_filters( 'hossam_retention_days_' . $def['key'], (int) $def['days'] ) ) );
		$batch = max( 1, min( 5000, (int) apply_filters( 'hossam_retention_batch_' . $def['key'], (int) $def['batch'] ) ) );
		$table = $wpdb->prefix . $def['table_suffix'];

		$where = $def['date_column'] . ' < %s';
		if ( ! empty( $def['terminal_only'] ) ) {
			// terminal-only — ثابت مكتوب حرفيًا (عقد التكامل مع الزومبي:
			// الاحتفاظ terminal-only وإنتاج terminal مسؤولية آلة الحالة).
			$where .= " AND status IN ('completed','failed')";
		}

		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$deleted = 0;

		for ( $i = 0; $i < 20; $i++ ) {
			$result = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE {$where} ORDER BY id LIMIT %d",
					$cutoff,
					$batch
				)
			);
			if ( false === $result ) {
				error_log( 'HAL retention sweep: DELETE failed on ' . $def['key'] . '.' );

				return $deleted;
			}
			$deleted += (int) $result;
			if ( (int) $result < $batch ) {
				break;
			}
		}

		return $deleted;
	}
}

if ( ! function_exists( 'hossam_audit_log_retention_sweep' ) ) {
	/**
	 * الكسح اليومي — الحدث الواحد hossam_dashboard_retention_sweep_event
	 * يدور على سجل التعريفات بجدولة واحدة وحارس واحد (Q4-8). لكل جدول:
	 * تنفيذ معزول — فشل جدول (بما فيه استثناء غير متوقع) يُسجل ولا يوقف
	 * بقية الحصص (عزل فشل لكل جدول). المواعيد محسومة UTC بgmdate داخليًا.
	 *
	 * @return void
	 */
	function hossam_audit_log_retention_sweep(): void {
		global $wpdb;

		$settings = hossam_audit_log_get_settings();
		$enabled  = (bool) $settings['enabled'];

		foreach ( hossam_audit_log_retention_definitions() as $def ) {
			try {
				hossam_audit_log_retention_sweep_table( $wpdb, $def, $enabled );
			} catch ( Throwable $sweep_exception ) {
				error_log( 'HAL retention sweep: table ' . $def['key'] . ' failed: ' . $sweep_exception->getMessage() );
			}
		}
	}
}

add_action( 'hossam_dashboard_retention_sweep_event', 'hossam_audit_log_retention_sweep' );

// الجدولة الذاتية بحارس idempotency — النمط الحرفي المجرَّب ai.php:535-540
// (Q4-4): init بguard wp_next_scheduled، فاصل daily نووي بلا cron_schedules
// جديد. إزالة الحدث عند إلغاء التفعيل = register_deactivation_hook واحد
// في الحامل hal-frontend-dashboard.php (صف R4) — لا unschedule من هنا.
add_action( 'init', function (): void {
	if ( wp_next_scheduled( 'hossam_dashboard_retention_sweep_event' ) ) {
		return;
	}
	wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'hossam_dashboard_retention_sweep_event' );
} );
