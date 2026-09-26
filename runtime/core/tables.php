<?php
/**
 * runtime/core/tables.php
 * ══════════════════════════════════════════════════════════════
 * الدور: إنشاء الجداول المخصَّصة الثلاثة المملوكة للـrelease عبر dbDelta().
 *
 * المصدر: نقل من legacy mu-plugins/hossam-dashboard/core/tables.php
 * (الدفعة 2 من HAL Frontend Dashboard) مع إعادة ترتيب واحدة مصرَّح بها
 * (فصل schema versions): السكيمات الثلاث معزولة الآن في بنية واحدة
 * (hossam_dashboard_table_schemas()) ودالة موحَّدة
 * hossam_dashboard_ensure_table() تُنفِّذ لكل جدول نفس السلوك الحرفي
 * الذي كانت تنفِّذه الكتل الثلاث المتكررة: نفس SQL، نفس أسماء options،
 * نفس أرقام versions، نفس التحقق عبر hossam_dashboard_table_is_ready()
 * قبل update_option()، نفس guards، ونفس تسجيل hook init.
 *
 * الجداول (سكيمات منفصلة، كل جدول بـschema version خاص به):
 *   - {prefix}hossam_notifications  → option hossam_notifications_db_version = 1.0.1
 *   - {prefix}hossam_messages       → option hossam_messages_db_version      = 1.0.1
 *   - {prefix}hossam_ai_jobs        → option hossam_ai_jobs_db_version      = 1.1.1
 *
 * توافق rollback: dbDelta() يضيف الأعمدة/الفهارس الجديدة فقط على جدول
 * قائم — لا يحذف أعمدة أو جداول عند وجود نسخة أعلى مسجَّلة، فالتراجع
 * إلى release أقدم لا يُدمِّر بيانات أعمدة السكيمة الأحدث (تبقى موجودة
 * وتُهمَل). عمود error_message من سكيمة AI 1.0.0 يبقى legacy للسجل
 * التاريخي فقط؛ الكود يكتب ويقرأ last_error حصريًا. القيمة القديمة
 * 1.0.0 للجدولين الأوليين غير موثوقة لأنها كانت تُسجَّل بلا تحقق؛
 * 1.0.1 هي علامة اكتمال التصحيح بعد dbDelta وفحص الأعمدة والفهارس.
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verify that dbDelta left a usable table before recording its schema version.
 * A failed check leaves the version option untouched so init can retry safely.
 *
 * @param string   $table            Full table name including the WordPress prefix.
 * @param string[] $required_columns Column names required by the current schema.
 * @param string[] $required_indexes Index names required by the current schema.
 * @param string   $migration_error  Database error reported immediately after dbDelta().
 */
function hossam_dashboard_table_is_ready( string $table, array $required_columns, array $required_indexes, string $migration_error ): bool {
	global $wpdb;

	if ( '' !== $migration_error ) {
		return false;
	}

	$table_like = $wpdb->esc_like( $table );
	$found      = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_like ) );
	if ( '' !== (string) $wpdb->last_error || $table !== $found ) {
		return false;
	}

	$escaped_table = str_replace( '`', '``', $table );
	$columns       = $wpdb->get_col( "SHOW COLUMNS FROM `{$escaped_table}`", 0 );
	if ( '' !== (string) $wpdb->last_error || array_diff( $required_columns, (array) $columns ) ) {
		return false;
	}

	$indexes = $wpdb->get_col( "SHOW INDEX FROM `{$escaped_table}`", 2 );
	if ( '' !== (string) $wpdb->last_error || array_diff( $required_indexes, array_unique( (array) $indexes ) ) ) {
		return false;
	}

	return true;
}

/**
 * الفصل المعتمد للسكيمات (دفعة 2): تعريف معزول واحد لكل جدول —
 * اسم الجدول، الأعمدة المطلوبة، الفهارس المطلوبة، سكيمة CREATE
 * الكاملة، واسم option الـschema version وقيمته المستهدفة. نفس
 * القيم الحرفية من المصدر بلا أي تغيير في أسماء الجداول أو السكيمات
 * أو أسماء options أو أرقام versions.
 *
 * @return array<string, array{table_suffix:string, version:string, option:string, columns:string[], indexes:string[], schema:callable(string,string):string}>
 */
function hossam_dashboard_table_schemas(): array {
	return [
		'notifications' => [
			'table_suffix' => 'hossam_notifications',
			'option'       => 'hossam_notifications_db_version',
			'version'      => '1.0.1',
			'columns'      => [ 'id', 'user_id', 'type', 'message', 'is_read', 'created_at' ],
			'indexes'      => [ 'PRIMARY', 'user_id', 'is_read' ],
			'schema'       => static function ( string $table, string $charset ): string {
				return "CREATE TABLE {$table} (
		id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id     BIGINT UNSIGNED NOT NULL,
		type        VARCHAR(100)    NOT NULL DEFAULT '',
		message     TEXT            NOT NULL,
		is_read     TINYINT(1)      NOT NULL DEFAULT 0,
		created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY user_id (user_id),
		KEY is_read (is_read)
	) {$charset};";
			},
		],
		'messages' => [
			'table_suffix' => 'hossam_messages',
			'option'       => 'hossam_messages_db_version',
			'version'      => '1.0.1',
			'columns'      => [ 'id', 'sender_id', 'receiver_id', 'message', 'read_at', 'created_at' ],
			'indexes'      => [ 'PRIMARY', 'sender_id', 'receiver_id', 'read_at' ],
			'schema'       => static function ( string $table, string $charset ): string {
				return "CREATE TABLE {$table} (
		id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		sender_id   BIGINT UNSIGNED NOT NULL,
		receiver_id BIGINT UNSIGNED NOT NULL,
		message     TEXT            NOT NULL,
		read_at     DATETIME                 DEFAULT NULL,
		created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY sender_id   (sender_id),
		KEY receiver_id (receiver_id),
		KEY read_at     (read_at)
	) {$charset};";
			},
		],
		'ai_jobs' => [
			'table_suffix' => 'hossam_ai_jobs',
			'option'       => 'hossam_ai_jobs_db_version',
			'version'      => '1.1.1',
			'columns'      => [ 'id', 'user_id', 'post_id', 'job_type', 'input_data', 'status', 'result', 'error_message', 'strategy_id', 'attempt_count', 'locked_at', 'next_run_at', 'last_error', 'created_at', 'updated_at' ],
			'indexes'      => [ 'PRIMARY', 'user_id', 'post_id', 'status' ],
			'schema'       => static function ( string $table, string $charset ): string {
				return "CREATE TABLE {$table} (
		id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id       BIGINT UNSIGNED NOT NULL,
		post_id       BIGINT UNSIGNED         DEFAULT NULL,
		job_type      VARCHAR(50)     NOT NULL,
		input_data    LONGTEXT        NOT NULL,
		status        VARCHAR(20)     NOT NULL DEFAULT 'pending',
		result        LONGTEXT                 DEFAULT NULL,
		error_message TEXT                     DEFAULT NULL,
		strategy_id   VARCHAR(32)     NOT NULL DEFAULT '',
		attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
		locked_at     DATETIME                 DEFAULT NULL,
		next_run_at   DATETIME                 DEFAULT NULL,
		last_error    TEXT                     DEFAULT NULL,
		created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY user_id (user_id),
		KEY post_id (post_id),
		KEY status  (status)
	) {$charset};";
			},
		],
	];
}

/**
 * دالة موحَّدة (دفعة 2) تُستدعى لكل جدول بدل الكتل الثلاث المتكررة في
 * المصدر — بنفس السلوك الحرفي: guard على قيمة option المسجَّلة، ثم
 * dbDelta()، ثم التحقق عبر hossam_dashboard_table_is_ready() قبل
 * update_option() لنجاح الـmigration. فشل التحقق يترك الـoption دون
 * تغيير ليُعاد المحاولة في init التالي بأمان.
 *
 * B2-02 (معمارية §8.4: لا downgrade تلقائيًا لمخطط البيانات): قيمة
 * مسجَّلة تطابق الهدف تعني idempotency كما كانت، وقيمة أعلى تُحفَظ
 * كما هي — لا ترحيل أقدم ولا إعادة كتابة رقم أقل فوق سكيمة أحدث
 * (نافذة rollback تبقى ببياناتها وشكلها الأحدث). أما غياب القيمة أو
 * قيمة أدنى فيُرحَّل ويُسجَّل الهدف بعد التحقق كما في المصدر.
 *
 * @param array{table_suffix:string, version:string, option:string, columns:string[], indexes:string[], schema:callable(string,string):string} $definition
 */
function hossam_dashboard_ensure_table( array $definition ): void {
	$recorded = get_option( $definition['option'] );
	if ( is_string( $recorded ) && '' !== $recorded
		&& version_compare( $recorded, $definition['version'], '>=' ) ) {
		return;
	}

	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table   = $wpdb->prefix . $definition['table_suffix'];
	$charset = $wpdb->get_charset_collate();

	$sql = $definition['schema']( $table, $charset );

	$wpdb->flush();
	dbDelta( $sql );
	$migration_error = (string) $wpdb->last_error;

	if ( hossam_dashboard_table_is_ready(
		$table,
		$definition['columns'],
		$definition['indexes'],
		$migration_error
	) ) {
		update_option( $definition['option'], $definition['version'] );
	}
}

add_action( 'init', function (): void {
	hossam_dashboard_ensure_table( hossam_dashboard_table_schemas()['notifications'] );
} );

add_action( 'init', function (): void {
	hossam_dashboard_ensure_table( hossam_dashboard_table_schemas()['messages'] );
} );

/**
 * جدول مهام الذكاء الاصطناعي (Job Queue غير متزامن) — نفس نمط
 * الجدولين أعلاه حرفيًا عبر الدالة الموحَّدة: dbDelta() + تتبُّع نسخة
 * سكيمة عبر Option. السكيمة 1.1.1 تشمل أعمدة 1.1.0 غير الحساسة
 * (strategy_id وattempt_count وlocked_at وnext_run_at وlast_error)
 * مع تصحيح تتبع النسخة؛ dbDelta() يضيف الأعمدة الجديدة فقط على جدول
 * قائم بلا حذف ولا تعديل للأعمدة القائمة — ترقية قابلة للتكرار
 * وبقاء البيانات القديمة كاملًا.
 */
add_action( 'init', function (): void {
	hossam_dashboard_ensure_table( hossam_dashboard_table_schemas()['ai_jobs'] );
} );
