<?php
/**
 * runtime/core/tables.php
 * ══════════════════════════════════════════════════════════════
 * Role: creating the three release-owned custom tables via dbDelta().
 *
 * Source: ported from legacy mu-plugins/hossam-dashboard/core/tables.php
 * (batch 2 of HAL Frontend Dashboard) with one sanctioned reorder
 * (separating the schema versions): the three schemas are now isolated in one structure
 * (hossam_dashboard_table_schemas()) and one unified function
 * hossam_dashboard_ensure_table() that executes, for each table, the exact same behavior
 * the three repeated blocks used to execute: same SQL, same option names,
 * same version numbers, same verification via hossam_dashboard_table_is_ready()
 * before update_option(), same guards, and the same init hook registration.
 *
 * Tables (separate schemas, each table with its own schema version):
 *   - {prefix}hossam_notifications  → option hossam_notifications_db_version = 1.0.2
 *   - {prefix}hossam_messages       → option hossam_messages_db_version      = 1.0.2
 *   - {prefix}hossam_ai_jobs        → option hossam_ai_jobs_db_version      = 1.1.2
 *
 * Rollback compatibility: dbDelta() only adds new columns/indexes to an existing
 * table — it does not delete columns or tables when a higher version is registered, so rolling back
 * to an older release does not destroy the columns' data of the newer schema (they remain present
 * and are ignored). The error_message column from AI schema 1.0.0 remains legacy for the
 * historical record only; the code writes and reads last_error exclusively. The old
 * 1.0.0 value for the first two tables is unreliable because it was recorded without verification;
 * 1.0.1 is the marker of the fix's completion after dbDelta and the columns/indexes checks.
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
 * The sanctioned separation of the schemas (batch 2): one isolated definition per table —
 * the table name, the required columns, the required indexes, the full CREATE
 * schema, and the name of the schema-version option and its target value. The same
 * literal values from the source with no change whatsoever in the table names or the schemas
 * or the option names or the version numbers.
 *
 * @return array<string, array{table_suffix:string, version:string, option:string, columns:string[], indexes:string[], schema:callable(string,string):string}>
 */
function hossam_dashboard_table_schemas(): array {
	return [
		'notifications' => [
			'table_suffix' => 'hossam_notifications',
			'option'       => 'hossam_notifications_db_version',
			'version'      => '1.0.2',
			'columns'      => [ 'id', 'user_id', 'type', 'message', 'is_read', 'created_at' ],
			'indexes'      => [ 'PRIMARY', 'user_id', 'is_read', 'user_created', 'user_is_read' ],
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
		KEY is_read (is_read),
		KEY user_created (user_id, created_at),
		KEY user_is_read (user_id, is_read)
	) {$charset};";
			},
		],
		'messages' => [
			'table_suffix' => 'hossam_messages',
			'option'       => 'hossam_messages_db_version',
			'version'      => '1.0.2',
			'columns'      => [ 'id', 'sender_id', 'receiver_id', 'message', 'read_at', 'created_at' ],
			'indexes'      => [ 'PRIMARY', 'sender_id', 'receiver_id', 'read_at', 'receiver_created' ],
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
		KEY read_at     (read_at),
		KEY receiver_created (receiver_id, created_at)
	) {$charset};";
			},
		],
		'ai_jobs' => [
			'table_suffix' => 'hossam_ai_jobs',
			'option'       => 'hossam_ai_jobs_db_version',
			'version'      => '1.1.2',
			'columns'      => [ 'id', 'user_id', 'post_id', 'job_type', 'input_data', 'status', 'result', 'error_message', 'strategy_id', 'attempt_count', 'locked_at', 'next_run_at', 'last_error', 'created_at', 'updated_at' ],
			'indexes'      => [ 'PRIMARY', 'user_id', 'post_id', 'status', 'status_locked', 'status_next_run', 'user_created_ai' ],
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
		KEY status  (status),
		KEY status_locked (status, locked_at),
		KEY status_next_run (status, next_run_at),
		KEY user_created_ai (user_id, created_at)
	) {$charset};";
			},
		],
	];
}

/**
 * A unified function (batch 2) called for each table instead of the three repeated blocks in
 * the source — with the exact same behavior: a guard on the recorded option value, then
 * dbDelta(), then verification via hossam_dashboard_table_is_ready() before
 * update_option() for the migration's success. A verification failure leaves the option unchanged
 * so the next init retries safely.
 *
 * B2-02 (architecture §8.4: no automatic downgrade of the data schema): a recorded
 * value matching the target means idempotency as before, and a higher value is kept
 * as is — no older migration and no rewriting a lower number over a newer schema
 * (the rollback window keeps its data and its newer shape). As for a missing value or
 * a lower one, it is migrated and the target is recorded after verification, as in the source.
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
 * The artificial-intelligence jobs table (an asynchronous Job Queue) — the exact same
 * pattern as the two tables above via the unified function: dbDelta() + schema version
 * tracking via an Option. Schema 1.1.1 includes the non-sensitive 1.1.0 columns
 * (strategy_id and attempt_count and locked_at and next_run_at and last_error)
 * with a version-tracking fix; dbDelta() only adds the new columns to an existing
 * table with no deletion and no modification of the existing columns — a repeatable upgrade
 * with the old data kept fully intact.
 */
add_action( 'init', function (): void {
	hossam_dashboard_ensure_table( hossam_dashboard_table_schemas()['ai_jobs'] );
} );
