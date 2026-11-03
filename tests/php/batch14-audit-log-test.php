<?php
/**
 * HAL Frontend Dashboard — Batch 14 harness T2: audit log unit (R2) + R3/R4 statics.
 *
 * Local, isolated harness: no live WordPress, no network, no database, no
 * browser. Loads the REAL runtime/core/audit-log.php and invokes the real
 * functions / real registered hook callbacks. Only the WordPress boundary
 * (hooks, options, wpdb, cron, ensure_table) is stubbed; no project logic is
 * reimplemented in the harness. The wpdb DELETE emulation executes the SQL
 * conditions actually captured from the production prepare() — it is a DB
 * boundary, not a logic reimplementation.
 *
 * Coverage (G4-AUDIT-LOG-POSITION.md §6 items measurable for R2 now, sealed
 * sha256 21ea8cef… + the v1.1.0 delta rows + H1 policy + settings + retention):
 *
 *   SCHEMA     §6-1 (local proxy) — definition carries the ten columns with
 *              event_action, the five indexes, version 1.0.0; the CREATE
 *              TABLE matches the sealed G4 §2 block (dbDelta rules: KEY not
 *              INDEX, `PRIMARY KEY  (id)` with two spaces, no backticks, no
 *              explicit ENGINE).
 *   EVENTS     the closed 20-event table asserted item by item (18 from
 *              G4 §3-أ + article_h1_normalized + secret_key_mismatch).
 *   WRITER     hossam_audit_log contract: session identity, closed object_type,
 *              NULL inline for object_id/meta/ip, meta sanitising + closed
 *              keys + 64KB cap drop, UTC created_at, swallow-failure.
 *   SUPERGLOBALS §6-4 static — zero $_POST/$_GET/$_REQUEST/$_COOKIE; $_SERVER
 *              reads restricted to REMOTE_ADDR.
 *   ISOLATION  §6-5 (local proxy) — DB failure returns false + error_log,
 *              never throws into the caller.
 *   LAZY MEMO  §6-6 (local proxy) — settings read once per request; the
 *              db_version option is never read on the logging path.
 *   ONE WRITER §6-7 static — zero update_option/add_option on
 *              hossam_audit_log_db_version in the unit; the two settings
 *              writes carry the `false` autoload argument always; behavioral
 *              add→update→no-op fingerprints.
 *   RETENTION  §6-8 — four definitions 90/30/90/180, ai_jobs terminal-only
 *              (status IN completed/failed + updated_at), DELETE … ORDER BY id
 *              LIMIT 500 via prepare, gmdate cutoff, ≥5000 of 6000 due rows in
 *              one sweep, fresh rows untouched, single-table failure
 *              isolation, idempotent double run, enabled=false stops the
 *              audit_log share only, days/batch filter clamps (1..365, 1..5000),
 *              schedule guard schedules once and never unschedules.
 *   UTC        §6-9 — created_at UTC and cutoff by gmdate.
 *   SURFACE    §6-10 (static proxy) — zero wp_ajax_ / register_rest_route /
 *              ALTER TABLE / DROP TABLE in the unit; R3 require lines; R4
 *              single deactivation hook clearing exactly the one event.
 *   SECRET HOOK §6-11 — the single listener consumes set/delete/key_mismatch
 *              (four-arg payload untouched) and ignores every other value
 *              defensively; KEY_MISMATCH full path belongs to R13 (phase 2).
 *   H1 POLICY  (Q5 resolution / event 19) — normalize converts h1→h2 in
 *              post_content for the `post` type only and logs
 *              article_h1_normalized with the conversion count; reject
 *              decision function; off disables; post_title never touched;
 *              detector semantics (<h1, <h1 >, <h1/>, <H1 A>, not <h10>).
 *
 * Usage:  php -d extension=sodium tests/php/batch14-audit-log-test.php  (exit 0 = all pass)
 *
 * Settings-dependent scenarios run as self-children of this very file
 * (--b14-child=<scenario>) because the settings memo is per-request: the
 * child process is a fresh request. No extra files are created.
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

if ( PHP_SAPI !== 'cli' ) {
	echo "This harness must run under CLI PHP.\n";
	exit( 1 );
}

$GLOBALS['B14_PROJECT'] = dirname( __DIR__, 2 );
$GLOBALS['B14_ERRLOG']  = ( getenv( 'TMP' ) ?: sys_get_temp_dir() ) . '/b14-audit-error.log';

/* ════════════════════════════════════════════════════════════════
 * WordPress boundary stubs (WordPress only — never project logic)
 * ════════════════════════════════════════════════════════════════ */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $GLOBALS['B14_PROJECT'] . '/runtime/fake-wp-root/' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

/* ── Hook registry (priority-ordered, dispatching) ── */
$GLOBALS['B14_HOOKS']    = array();
$GLOBALS['B14_HOOK_SEQ'] = 0;

function b14_hook_register( string $type, string $hook, callable $cb, int $priority, int $args ): void {
	$GLOBALS['B14_HOOKS'][ $type ][ $hook ][] = array(
		'p'   => $priority,
		'seq' => $GLOBALS['B14_HOOK_SEQ']++,
		'cb'  => $cb,
		'a'   => $args,
	);
}
function add_action( string $hook, callable $cb, int $priority = 10, int $accepted_args = 1 ): void {
	b14_hook_register( 'action', $hook, $cb, $priority, $accepted_args );
}
function add_filter( string $hook, callable $cb, int $priority = 10, int $accepted_args = 1 ): void {
	b14_hook_register( 'filter', $hook, $cb, $priority, $accepted_args );
}
function remove_filter( string $hook, callable $cb, int $priority = 10 ): bool {
	$found = false;
	foreach ( (array) ( $GLOBALS['B14_HOOKS']['filter'][ $hook ] ?? array() ) as $i => $entry ) {
		if ( $entry['cb'] === $cb && $entry['p'] === $priority ) {
			unset( $GLOBALS['B14_HOOKS']['filter'][ $hook ][ $i ] );
			$found = true;
		}
	}
	return $found;
}
function b14_hook_sorted( string $type, string $hook ): array {
	$entries = array_values( (array) ( $GLOBALS['B14_HOOKS'][ $type ][ $hook ] ?? array() ) );
	usort( $entries, static fn( $x, $y ) => array( $x['p'], $x['seq'] ) <=> array( $y['p'], $y['seq'] ) );
	return $entries;
}
function apply_filters( string $hook, $value, ...$rest ) {
	foreach ( b14_hook_sorted( 'filter', $hook ) as $entry ) {
		$call_args = array_merge( array( $value ), $rest );
		$value     = call_user_func_array( $entry['cb'], array_slice( $call_args, 0, max( 1, $entry['a'] ) ) );
	}
	return $value;
}
function do_action( string $hook, ...$args ): void {
	foreach ( b14_hook_sorted( 'action', $hook ) as $entry ) {
		call_user_func_array( $entry['cb'], array_slice( $args, 0, max( 0, $entry['a'] ) ) );
	}
}

/* ── Errors / sanitizers ── */
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public string $code = '';
		public string $message = '';
		public $error_data = null;
		public function __construct( string $code = '', string $message = '', $data = null ) {
			$this->code = $code;
			$this->message = $message;
			$this->error_data = $data;
		}
		public function get_error_code(): string {
			return $this->code;
		}
		public function get_error_message(): string {
			return $this->message;
		}
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ): string {
		return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $str ) ) );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0 ) {
		return json_encode( $value, $flags );
	}
}

/* ── Users / session ── */
function get_current_user_id(): int {
	return (int) ( $GLOBALS['B14_USER_ID'] ?? 0 );
}
if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( int $user_id ) {
		return $GLOBALS['B14_USERS'][ $user_id ] ?? false;
	}
}

/* ── Options store (reads + writes recorded) ── */
$GLOBALS['B14_OPTIONS']      = array();
$GLOBALS['B14_OPTION_READS'] = array(); // option names read
$GLOBALS['B14_OPTION_WRITES'] = array(); // [ ['op', 'option', 'value', 'autoload'] ]

function get_option( string $option, $default_value = false ) {
	$GLOBALS['B14_OPTION_READS'][] = $option;
	return array_key_exists( $option, $GLOBALS['B14_OPTIONS'] ) ? $GLOBALS['B14_OPTIONS'][ $option ] : $default_value;
}
function update_option( string $option, $value, $autoload = null ): bool {
	$GLOBALS['B14_OPTION_WRITES'][] = array( 'op' => 'update', 'option' => $option, 'value' => $value, 'autoload' => $autoload );
	$GLOBALS['B14_OPTIONS'][ $option ] = $value;
	return true;
}
function add_option( string $option, $value, $autoload = null ): bool {
	$GLOBALS['B14_OPTION_WRITES'][] = array( 'op' => 'add', 'option' => $option, 'value' => $value, 'autoload' => $autoload );
	if ( array_key_exists( $option, $GLOBALS['B14_OPTIONS'] ) ) {
		return false;
	}
	$GLOBALS['B14_OPTIONS'][ $option ] = $value;
	return true;
}

/* ── ensure_table boundary (tables.php pattern) ── */
$GLOBALS['B14_ENSURE_CALLS']   = array();
$GLOBALS['B14_ENSURE_SUCCEED'] = true;
function hossam_dashboard_ensure_table( array $definition ): void {
	$GLOBALS['B14_ENSURE_CALLS'][] = $definition;
	if ( ! empty( $GLOBALS['B14_ENSURE_SUCCEED'] ) ) {
		$GLOBALS['B14_OPTIONS'][ $definition['option'] ] = $definition['version']; // tables.php:196 bare write (single writer)
	}
}

/* ── Cron boundary ── */
$GLOBALS['B14_CRON_SCHEDULED'] = array(); // [ ['timestamp', 'recurrence', 'hook'] ]
$GLOBALS['B14_CRON_CLEARED']   = array(); // hooks cleared
$GLOBALS['B14_CRON_NEXT']      = false;   // false = nothing scheduled
function wp_next_scheduled( string $hook ) {
	return ( false !== $GLOBALS['B14_CRON_NEXT'] && ( $GLOBALS['B14_CRON_NEXT']['hook'] ?? '' ) === $hook )
		? $GLOBALS['B14_CRON_NEXT']['ts'] : false;
}
function wp_schedule_event( int $timestamp, string $recurrence, string $hook ): bool {
	$GLOBALS['B14_CRON_SCHEDULED'][] = array( 'ts' => $timestamp, 'recurrence' => $recurrence, 'hook' => $hook );
	$GLOBALS['B14_CRON_NEXT'] = array( 'ts' => $timestamp, 'hook' => $hook );
	return true;
}
function wp_clear_scheduled_hook( string $hook ): void {
	$GLOBALS['B14_CRON_CLEARED'][] = $hook;
	$GLOBALS['B14_CRON_NEXT']      = false;
}

/* ── wpdb boundary (INSERT capture + faithful DELETE execution) ── */
$GLOBALS['B14_DB_ROWS']       = array(); // table => [ ['id', 'created_at', 'status', ...] ]
$GLOBALS['B14_DB_FAIL_TABLES'] = array();
$GLOBALS['B14_DB_INSERT_FAIL'] = false;

if ( ! class_exists( 'B14_wpdb' ) ) {
	final class B14_wpdb {
		public string $prefix     = 'wp_';
		public string $last_error = '';
		public int $blogid        = 1;
		public array $queries     = array(); // prepared SQL captured

		public function prepare( string $query, ...$args ): string {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			foreach ( $args as $arg ) {
				$pos_s = strpos( $query, '%s' );
				$pos_d = strpos( $query, '%d' );
				if ( false !== $pos_s && ( false === $pos_d || $pos_s < $pos_d ) ) {
					$query = substr_replace( $query, "'" . addslashes( (string) $arg ) . "'", (int) $pos_s, 2 );
				} elseif ( false !== $pos_d ) {
					$query = substr_replace( $query, (string) (int) $arg, (int) $pos_d, 2 );
				}
			}
			return $query;
		}
		public function query( ?string $query = null ) {
			$sql = (string) $query;
			$this->queries[] = $sql;
			if ( false !== strpos( $sql, 'INSERT INTO' ) ) {
				if ( ! empty( $GLOBALS['B14_DB_INSERT_FAIL'] ) ) {
					$this->last_error = 'B14 fixture insert failure';
					return false;
				}
				$this->last_error = '';
				return 1;
			}
			if ( 1 === preg_match( '/^DELETE FROM (\w+) /', $sql, $tm ) ) {
				$table = $tm[1];
				if ( in_array( $table, (array) $GLOBALS['B14_DB_FAIL_TABLES'], true ) ) {
					$this->last_error = 'B14 fixture delete failure';
					return false;
				}
				$this->last_error = '';
				return $this->run_delete( $sql, $table );
			}
			return 1;
		}
		/** Execute the captured DELETE against the row model — DB boundary emulation. */
		private function run_delete( string $sql, string $table ): int {
			$date_col = '';
			$cutoff   = '';
			if ( 1 === preg_match( "/WHERE (\w+) < '([^']+)'/", $sql, $cm ) ) {
				$date_col = $cm[1];
				$cutoff   = $cm[2];
			}
			$terminal = false !== strpos( $sql, "status IN ('completed','failed')" );
			$limit    = 0;
			if ( 1 === preg_match( '/LIMIT (\d+)/', $sql, $lm ) ) {
				$limit = (int) $lm[1];
			}
			$rows = (array) ( $GLOBALS['B14_DB_ROWS'][ $table ] ?? array() );
			usort( $rows, static fn( $a, $b ) => (int) $a['id'] <=> (int) $b['id'] );
			$keep = array();
			$victims = array();
			foreach ( $rows as $row ) {
				$due = '' !== $cutoff && isset( $row[ $date_col ] ) && (string) $row[ $date_col ] < $cutoff;
				if ( $terminal && ! in_array( (string) ( $row['status'] ?? '' ), array( 'completed', 'failed' ), true ) ) {
					$due = false;
				}
				if ( $due && count( $victims ) < $limit ) {
					$victims[] = $row;
				} else {
					$keep[] = $row;
				}
			}
			$GLOBALS['B14_DB_ROWS'][ $table ] = $keep;
			return count( $victims );
		}
		public function flush(): void {
			$this->last_error = '';
		}
		public function get_charset_collate(): string {
			return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
		}
	}
}
$GLOBALS['wpdb'] = new B14_wpdb();

/* ── error_log capture (ini-based; appends timestamped lines) ── */
function b14_errlog_start(): void {
	@unlink( $GLOBALS['B14_ERRLOG'] );
	ini_set( 'error_log', $GLOBALS['B14_ERRLOG'] );
}
function b14_errlog_take(): string {
	ini_set( 'error_log', '' );
	$contents = (string) @file_get_contents( $GLOBALS['B14_ERRLOG'] );
	@unlink( $GLOBALS['B14_ERRLOG'] );
	return $contents;
}

/* ════════════════════════════════════════════════════════════════
 * Load the REAL production unit
 * ════════════════════════════════════════════════════════════════ */

require $GLOBALS['B14_PROJECT'] . '/runtime/core/audit-log.php';

/* ════════════════════════════════════════════════════════════════
 * Child mode — fresh-request scenarios (settings memo is per-request)
 * ════════════════════════════════════════════════════════════════ */

if ( isset( $argv[1] ) && str_starts_with( (string) $argv[1], '--b14-child=' ) ) {
	$scenario = substr( (string) $argv[1], 12 );
	$markers  = array();

	if ( 'defaults' === $scenario ) {
		$GLOBALS['B14_USER_ID'] = 4;
		$GLOBALS['B14_USERS'][4] = (object) array( 'user_login' => 'writer' );
		$reads_before = count( $GLOBALS['B14_OPTION_READS'] );
		hossam_audit_log( 'article_create', array( 'object_id' => 9 ) );
		$write_reads = array_slice( $GLOBALS['B14_OPTION_READS'], $reads_before );
		$markers['write_settings_reads']  = count( array_filter( $write_reads, static fn( $o ) => 'hossam_audit_log_settings' === $o ) );
		$markers['write_db_version_reads'] = count( array_filter( $write_reads, static fn( $o ) => 'hossam_audit_log_db_version' === $o ) );
		$markers['insert_count'] = count( array_filter( $GLOBALS['wpdb']->queries, static fn( $q ) => false !== strpos( (string) $q, 'INSERT INTO' ) ) );
		$s1 = hossam_audit_log_get_settings();
		$s2 = hossam_audit_log_get_settings();
		$s3 = hossam_audit_log_get_settings();
		$markers['settings']       = $s1;
		$markers['settings_reads'] = count( array_filter( $GLOBALS['B14_OPTION_READS'], static fn( $o ) => 'hossam_audit_log_settings' === $o ) );
		$markers['same_memo']      = ( $s1 === $s2 && $s2 === $s3 );
	} elseif ( 'disabled' === $scenario ) {
		$GLOBALS['B14_OPTIONS']['hossam_audit_log_settings'] = array( 'enabled' => false );
		$GLOBALS['B14_USER_ID'] = 4;
		$GLOBALS['B14_USERS'][4] = (object) array( 'user_login' => 'writer' );
		$GLOBALS['B14_DB_ROWS']['wp_hossam_audit_log']  = array( array( 'id' => 1, 'created_at' => '2020-01-01 00:00:00' ) );
		$GLOBALS['B14_DB_ROWS']['wp_hossam_notifications'] = array( array( 'id' => 2, 'created_at' => '2020-01-01 00:00:00' ) );
		$GLOBALS['B14_DB_ROWS']['wp_hossam_messages']   = array( array( 'id' => 3, 'created_at' => '2020-01-01 00:00:00' ) );
		$GLOBALS['B14_DB_ROWS']['wp_hossam_ai_jobs']    = array( array( 'id' => 4, 'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00', 'status' => 'completed' ) );
		$markers['writer_return'] = hossam_audit_log( 'article_create', array( 'object_id' => 9 ) );
		$markers['insert_count']  = count( array_filter( $GLOBALS['wpdb']->queries, static fn( $q ) => false !== strpos( (string) $q, 'INSERT INTO' ) ) );
		hossam_audit_log_retention_sweep();
		$markers['audit_deleted']         = 1 === count( $GLOBALS['B14_DB_ROWS']['wp_hossam_audit_log'] ) ? 0 : 1;
		$markers['notifications_deleted'] = 0 === count( $GLOBALS['B14_DB_ROWS']['wp_hossam_notifications'] ) ? 1 : 0;
		$markers['messages_deleted']      = 0 === count( $GLOBALS['B14_DB_ROWS']['wp_hossam_messages'] ) ? 1 : 0;
		$markers['ai_jobs_deleted']       = 0 === count( $GLOBALS['B14_DB_ROWS']['wp_hossam_ai_jobs'] ) ? 1 : 0;
		$markers['clears_scheduled']      = count( $GLOBALS['B14_CRON_CLEARED'] );
	} elseif ( 'ip' === $scenario ) {
		$GLOBALS['B14_OPTIONS']['hossam_audit_log_settings'] = array( 'ip_enabled' => true );
		$GLOBALS['B14_USER_ID'] = 4;
		$_SERVER['REMOTE_ADDR']           = '203.0.113.9';
		$_SERVER['HTTP_X_FORWARDED_FOR']  = '198.51.100.66';
		hossam_audit_log( 'file_upload', array( 'object_id' => 11, 'meta' => array( 'mime' => 'image/jpeg' ) ) );
		$first = (string) ( $GLOBALS['wpdb']->queries[0] ?? '' );
		$markers['first_has_remote_ip']    = false !== strpos( $first, '203.0.113.9' );
		$markers['first_has_forwarded']    = false !== strpos( $first, '198.51.100.66' );
		$markers['first_has_mime_meta']    = false !== strpos( $first, addslashes( (string) wp_json_encode( array( 'mime' => 'image/jpeg' ) ) ) );
		$_SERVER['REMOTE_ADDR']            = 'not-an-ip-garbage';
		hossam_audit_log( 'file_upload', array( 'object_id' => 12 ) );
		$second = (string) ( $GLOBALS['wpdb']->queries[1] ?? '' );
		$markers['second_null_ip_inline']  = 1 === preg_match( '/VALUES \([^\)]*NULL, \'\d{4}-/', $second );
	} elseif ( 'secret_disabled' === $scenario ) {
		$GLOBALS['B14_OPTIONS']['hossam_audit_log_settings'] = array( 'enabled' => false );
		do_action( 'hal_frontend_dashboard_secret_audit', array( 'action' => 'set', 'secret_id' => 'sec-x', 'actor' => 1, 'time' => 'now' ) );
		$markers['insert_count'] = count( array_filter( $GLOBALS['wpdb']->queries, static fn( $q ) => false !== strpos( (string) $q, 'INSERT INTO' ) ) );
	}

	echo json_encode( $markers );
	exit( 0 );
}

/* ════════════════════════════════════════════════════════════════
 * Check helper
 * ════════════════════════════════════════════════════════════════ */

$GLOBALS['B14_PASS']  = 0;
$GLOBALS['B14_FAIL']  = 0;
$GLOBALS['B14_FAILS'] = array();

function b14_check( string $id, bool $ok, string $pass, string $fail ): void {
	if ( $ok ) {
		$GLOBALS['B14_PASS']++;
		echo "PASS [{$id}] {$pass}\n";
	} else {
		$GLOBALS['B14_FAIL']++;
		$GLOBALS['B14_FAILS'][] = "{$id}: {$fail}";
		echo "FAIL [{$id}] {$fail}\n";
	}
}

function b14_reset(): void {
	$GLOBALS['B14_OPTIONS']       = array();
	$GLOBALS['B14_OPTION_READS']  = array();
	$GLOBALS['B14_OPTION_WRITES'] = array();
	$GLOBALS['B14_ENSURE_CALLS']  = array();
	$GLOBALS['B14_ENSURE_SUCCEED'] = true;
	$GLOBALS['B14_CRON_SCHEDULED'] = array();
	$GLOBALS['B14_CRON_CLEARED']   = array();
	$GLOBALS['B14_CRON_NEXT']      = false;
	$GLOBALS['B14_DB_ROWS']        = array();
	$GLOBALS['B14_DB_FAIL_TABLES'] = array();
	$GLOBALS['B14_DB_INSERT_FAIL'] = false;
	$GLOBALS['B14_USER_ID']        = 0;
	$GLOBALS['B14_USERS']          = array();
	$GLOBALS['wpdb']               = new B14_wpdb();
}

/** Run every init callback registered by the unit, in order. */
function b14_run_init_closures(): void {
	foreach ( b14_hook_sorted( 'action', 'init' ) as $entry ) {
		call_user_func( $entry['cb'] );
	}
}

function b14_spawn_child( string $scenario ): array {
	$cmd = escapeshellarg( PHP_BINARY ) . ' -d display_errors=1 -d error_reporting=' . escapeshellarg( (string) E_ALL )
		. ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( '--b14-child=' . $scenario );
	$out    = shell_exec( $cmd . ' 2>&1' );
	$decoded = json_decode( (string) $out, true );
	return is_array( $decoded ) ? $decoded : array( '_raw' => (string) $out );
}

/* ════════════════════════════════════════════════════════════════
 * T2-LOAD + SCHEMA (G4 §6-1 local proxy — sealed §2 block)
 * ════════════════════════════════════════════════════════════════ */

b14_check( 'T2-LOAD-real-unit',
	function_exists( 'hossam_audit_log' )
		&& function_exists( 'hossam_audit_log_table_definition' )
		&& function_exists( 'hossam_audit_log_events' )
		&& function_exists( 'hossam_audit_log_on_secret_audit' )
		&& function_exists( 'hossam_audit_log_retention_sweep' ),
	'real runtime/core/audit-log.php loaded with its public surface',
	'real audit-log.php did not load its expected surface' );

$def = hossam_audit_log_table_definition();
b14_check( 'T2-E1-definition-shape',
	'hossam_audit_log' === $def['table_suffix']
		&& 'hossam_audit_log_db_version' === $def['option']
		&& '1.0.0' === $def['version']
		&& array( 'id', 'user_id', 'user_login', 'event_action', 'object_type', 'object_id', 'object_title', 'meta', 'ip', 'created_at' ) === $def['columns']
		&& array( 'PRIMARY', 'created_at', 'user_id', 'object_id', 'event_action' ) === $def['indexes'],
	'definition: suffix + option + version 1.0.0 + the ten columns (event_action present) + the five indexes — G4 §2 verbatim',
	'definition deviates from the sealed G4 §2 block: ' . var_export( $def, true ) );

$ddl = call_user_func( $def['schema'], 'wp_hossam_audit_log', 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci' );
$ddl_norm = trim( (string) preg_replace( '/\s+/', ' ', $ddl ) );
$ddl_expect_norm = trim( (string) preg_replace( '/\s+/', ' ',
	'CREATE TABLE wp_hossam_audit_log (
		id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id       BIGINT UNSIGNED NOT NULL,
		user_login    VARCHAR(60)     NOT NULL DEFAULT \'\',
		event_action  VARCHAR(64)     NOT NULL,
		object_type   VARCHAR(32)     NOT NULL DEFAULT \'\',
		object_id     BIGINT UNSIGNED          DEFAULT NULL,
		object_title  VARCHAR(255)    NOT NULL DEFAULT \'\',
		meta          LONGTEXT                 DEFAULT NULL,
		ip            VARCHAR(45)              DEFAULT NULL,
		created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY created_at (created_at),
		KEY user_id (user_id),
		KEY object_id (object_id),
		KEY event_action (event_action)
	) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;' ) );
b14_check( 'T2-E1-ddl-matches-sealed-g4-2',
	$ddl_norm === $ddl_expect_norm,
	'CREATE TABLE matches the sealed G4 §2 block (whitespace-normalized byte comparison)',
	'DDL drifted from the sealed G4 §2 block: ' . $ddl_norm );
b14_check( 'T2-E1-ddl-dbdelta-rules',
	false !== strpos( $ddl, 'PRIMARY KEY  (id)' )
		&& false === strpos( $ddl, 'INDEX ' )
		&& false === strpos( $ddl, '`' )
		&& false === strpos( $ddl, 'ENGINE=' )
		&& 5 === substr_count( $ddl, 'KEY ' ),
	'dbDelta rules: `PRIMARY KEY  (id)` with two spaces, KEY not INDEX, no backticks, no explicit ENGINE, five KEY lines',
	'dbDelta rules violated: ' . $ddl );

/* init closure: ensure_table receives THIS definition; failure disclosure; retry clean. */
b14_reset();
b14_errlog_start();
$GLOBALS['B14_ENSURE_SUCCEED'] = false; // model: ensure did not complete
b14_run_init_closures();
$init_err = b14_errlog_take();
$def_a = hossam_audit_log_table_definition();
$def_b = $GLOBALS['B14_ENSURE_CALLS'][0] ?? array();
$schema_same = isset( $def_a['schema'], $def_b['schema'] )
	&& is_callable( $def_a['schema'] ) && is_callable( $def_b['schema'] )
	&& call_user_func( $def_a['schema'], 'wp_probe', 'CS' ) === call_user_func( $def_b['schema'], 'wp_probe', 'CS' );
unset( $def_a['schema'], $def_b['schema'] );
b14_check( 'T2-E1-init-calls-ensure-and-discloses',
	1 === count( $GLOBALS['B14_ENSURE_CALLS'] )
		&& $def_a === $def_b && $schema_same
		&& false !== strpos( $init_err, 'HAL audit log: table ensure did not complete' ),
	'init calls hossam_dashboard_ensure_table with the unit definition and discloses incomplete ensure via error_log',
	'init closure deviated: calls=' . count( $GLOBALS['B14_ENSURE_CALLS'] ) . ' schema_same=' . var_export( $schema_same, true ) . ' errlog=' . var_export( $init_err, true ) );
b14_errlog_start();
$GLOBALS['B14_ENSURE_SUCCEED'] = true; // retry completes: option written, no disclosure
b14_run_init_closures();
$init_err2 = b14_errlog_take();
b14_check( 'T2-E1-init-retry-clean',
	'1.0.0' === (string) ( $GLOBALS['B14_OPTIONS']['hossam_audit_log_db_version'] ?? '' )
		&& '' === trim( $init_err2 ),
	'completed ensure writes hossam_audit_log_db_version=1.0.0 (single writer inside ensure_table) and the disclosure stops',
	'ensure completion path deviated: errlog=' . var_export( $init_err2, true ) );

/* ════════════════════════════════════════════════════════════════
 * T2-EVENTS — the closed 20-event table, item by item
 * ════════════════════════════════════════════════════════════════ */

$expected_events = array(
	// R8-delta: meta يضيف lang بجانب status (تحديث بند قبول — يُحكم مع R8).
	'article_create'        => array( 'object_type' => 'article', 'meta_keys' => array( 'status', 'lang' ) ),
	'article_update'        => array( 'object_type' => 'article', 'meta_keys' => array( 'status' ) ),
	'article_trash'         => array( 'object_type' => 'article', 'meta_keys' => array() ),
	'article_restore'       => array( 'object_type' => 'article', 'meta_keys' => array() ),
	'article_translate'     => array( 'object_type' => 'article', 'meta_keys' => array( 'new_post_id', 'lang' ) ),
	'article_save_seo'      => array( 'object_type' => 'article', 'meta_keys' => array( 'fields' ) ),
	'file_upload'           => array( 'object_type' => 'file', 'meta_keys' => array( 'mime' ) ),
	'file_delete'           => array( 'object_type' => 'file', 'meta_keys' => array( 'mime' ) ),
	'file_restore'          => array( 'object_type' => 'file', 'meta_keys' => array() ),
	'message_send'          => array( 'object_type' => 'message', 'meta_keys' => array( 'receiver_id' ) ),
	'message_mark_read'     => array( 'object_type' => 'message', 'meta_keys' => array() ),
	'ai_submit'             => array( 'object_type' => 'ai_job', 'meta_keys' => array( 'job_type', 'post_id', 'strategy_id' ) ),
	'ai_complete'           => array( 'object_type' => 'ai_job', 'meta_keys' => array( 'job_type' ) ),
	'ai_fail'               => array( 'object_type' => 'ai_job', 'meta_keys' => array( 'reason' ) ),
	'settings_save'         => array( 'object_type' => 'settings', 'meta_keys' => array( 'keys' ) ),
	'ai_preference_set'     => array( 'object_type' => 'settings', 'meta_keys' => array( 'preference' ) ),
	'secret_set'            => array( 'object_type' => 'secret', 'meta_keys' => array( 'secret_id' ) ),
	'secret_delete'         => array( 'object_type' => 'secret', 'meta_keys' => array( 'secret_id' ) ),
	'article_h1_normalized' => array( 'object_type' => 'article', 'meta_keys' => array( 'count' ) ),
	'secret_key_mismatch'   => array( 'object_type' => 'secret', 'meta_keys' => array( 'secret_id' ) ),
);
$events = hossam_audit_log_events();
$events_diff = array();
foreach ( $expected_events as $name => $shape ) {
	if ( ( $events[ $name ] ?? null ) !== $shape ) {
		$events_diff[] = $name;
	}
}
b14_check( 'T2-E3-20-events-item-by-item',
	20 === count( $events ) && array() === $events_diff && array_keys( $events ) === array_keys( $expected_events ),
	'the closed event table is exactly the 20 events (18 from G4 §3-أ + article_h1_normalized + secret_key_mismatch) with their object_type and closed meta keys, in order',
	'event table deviated: count=' . count( $events ) . ' diffs=' . implode( ',', $events_diff ) . ' keys=' . implode( ',', array_keys( $events ) ) );
b14_check( 'T2-E3-secret-rows-share-shape',
	'secret' === $events['secret_set']['object_type'] && 'secret' === $events['secret_delete']['object_type'] && 'secret' === $events['secret_key_mismatch']['object_type']
		&& array( 'secret_id' ) === $events['secret_key_mismatch']['meta_keys']
		&& array( 'secret_id' ) === $events['secret_set']['meta_keys']
		&& array( 'secret_id' ) === $events['secret_delete']['meta_keys'],
	'rows 17/18/20 share the secret shape (object_type=secret, closed meta secret_id) — row 20 mirrors 17/18 verbatim',
	'secret rows deviated: ' . var_export( array_slice( $events, 16, 4, true ), true ) );

/* ════════════════════════════════════════════════════════════════
 * T2-WRITER — hossam_audit_log contract (defaults state)
 * ════════════════════════════════════════════════════════════════ */

b14_reset();
$GLOBALS['B14_USER_ID'] = 3;
$GLOBALS['B14_USERS'][3] = (object) array( 'user_login' => 'hossam_lawyer' );
$before = time();
$ok = hossam_audit_log( 'article_create', array(
	'object_id'    => 5,
	'object_title' => 'مقال الاختبار',
	'meta'         => array( 'status' => 'draft', 'injected' => 'dropped' ),
) );
$insert_sql = (string) ( $GLOBALS['wpdb']->queries[0] ?? '' );
$meta_needle = addslashes( (string) wp_json_encode( array( 'status' => 'draft' ) ) );
b14_check( 'T2-WRITER-insert-row-values',
	true === $ok
		&& 1 === count( $GLOBALS['wpdb']->queries )
		&& false !== strpos( $insert_sql, 'INSERT INTO wp_hossam_audit_log' )
		&& false !== strpos( $insert_sql, "( user_id, user_login, event_action, object_type, object_id, object_title, meta, ip, created_at )" )
		&& false !== strpos( $insert_sql, "3, 'hossam_lawyer', 'article_create', 'article', 5," ),
	'success ⇒ one prepared INSERT with session identity (user_id 3 + user_login snapshot), the event name, and object_type from the closed map (not the caller)',
	'writer INSERT deviated: ok=' . var_export( $ok, true ) . ' sql=' . var_export( $insert_sql, true ) );
if ( 1 === preg_match( "/'(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})'\s*\)\s*$/", $insert_sql, $b14_cm ) ) {
	$created_ts = (int) strtotime( str_replace( 'GMT', '', $b14_cm[1] ) . ' GMT' );
} else {
	$created_ts = 0;
}
b14_check( 'T2-WRITER-created-at-utc-gmdate',
	$created_ts > 0 && abs( $created_ts - $before ) <= 2,
	'created_at is an explicit UTC gmdate value (independent of the site timezone)',
	'created_at deviated from UTC gmdate: ' . var_export( $b14_cm[1] ?? '', true ) );
b14_check( 'T2-WRITER-meta-sanitized-closed-keys',
	false !== strpos( $insert_sql, $meta_needle )
		&& false === strpos( $insert_sql, 'injected' ),
	'meta JSON keeps only the closed keys of the event with sanitized values; foreign keys dropped',
	'meta filtering deviated: ' . var_export( $insert_sql, true ) );
b14_check( 'T2-WRITER-null-inline-ip',
	1 === preg_match( "/, NULL, '\d{4}-/", $insert_sql ),
	'ip column carried NULL inline (ip_enabled=false default — no PII by default)',
	'ip NULL inline deviated: ' . var_export( $insert_sql, true ) );

/* object_id 0 / negative / absent ⇒ NULL inline (zero is a lying index value). */
b14_reset();
hossam_audit_log( 'article_trash', array( 'object_id' => 0 ) );
hossam_audit_log( 'article_restore', array( 'object_id' => -4 ) );
hossam_audit_log( 'message_mark_read', array() );
$sqls = $GLOBALS['wpdb']->queries;
b14_check( 'T2-WRITER-object-id-null-inline',
	3 === count( $sqls )
		&& 1 === preg_match( '/VALUES \( \d+, \'\', \'article_trash\', \'article\', NULL,/', (string) $sqls[0] )
		&& 1 === preg_match( '/\'article_restore\', \'article\', NULL,/', (string) $sqls[1] )
		&& 1 === preg_match( '/\'message_mark_read\', \'message\', NULL, \'\'(, NULL){2}, \'/', (string) $sqls[2] ),
	'object_id 0/negative/absent all become literal NULL inline; empty context yields NULL meta and NULL ip',
	'NULL-inline semantics deviated: ' . var_export( $sqls, true ) );

/* object_title snapshot: sanitized + capped at 255. */
b14_reset();
$long_title = str_repeat( 'ط', 300 );
hossam_audit_log( 'article_update', array( 'object_id' => 1, 'object_title' => $long_title, 'meta' => array( 'status' => 'publish' ) ) );
$has_255 = false;
if ( 1 === preg_match( "/, 1, '(\w+)'/u", (string) ( $GLOBALS['wpdb']->queries[0] ?? '' ), $b14_tm ) ) {
	$has_255 = ( 255 === mb_strlen( $b14_tm[1] ) );
}
b14_check( 'T2-WRITER-title-snapshot-capped-255',
	$has_255,
	'object_title stored as the sanitized snapshot capped with mb_substr(0,255)',
	'title snapshot deviated from the 255 cap' );

/* meta > 64KB: dropped with error_log; the row itself survives on its columns. */
b14_reset();
b14_errlog_start();
hossam_audit_log( 'ai_submit', array( 'object_id' => 2, 'meta' => array( 'job_type' => str_repeat( 'a', 70000 ) ) ) );
$capped_err = b14_errlog_take();
$sql_meta = (string) ( $GLOBALS['wpdb']->queries[0] ?? '' );
b14_check( 'T2-WRITER-meta-64kb-cap-drops',
	false !== strpos( $capped_err, 'meta exceeded the 64KB cap' )
		&& 1 === preg_match( '/\'ai_submit\', \'ai_job\', 2, \'\', NULL,/', $sql_meta ),
	'meta beyond 64KB dropped whole with an error_log disclosure; the event row still written with NULL meta',
	'64KB cap deviated: err=' . var_export( $capped_err, true ) . ' sql=' . var_export( $sql_meta, true ) );

/* unknown event_action: defensive false + no INSERT + error_log. */
b14_reset();
b14_errlog_start();
$unknown = hossam_audit_log( 'totally_unknown_event', array( 'object_id' => 1 ) );
$unknown_err = b14_errlog_take();
b14_check( 'T2-WRITER-unknown-event-defensive',
	false === $unknown && 0 === count( $GLOBALS['wpdb']->queries )
		&& false !== strpos( $unknown_err, 'outside the closed list ignored' ),
	'event_action outside the closed list ⇒ false, zero INSERT, error_log (the closed list stays closed)',
	'unknown event deviated: ret=' . var_export( $unknown, true ) . ' err=' . var_export( $unknown_err, true ) );

/* DB failure: swallow + error_log + false (never throws into the caller). */
b14_reset();
$GLOBALS['B14_DB_INSERT_FAIL'] = true;
b14_errlog_start();
$failed_threw = false;
try {
	$failed = hossam_audit_log( 'file_upload', array( 'object_id' => 3, 'meta' => array( 'mime' => 'image/png' ) ) );
} catch ( Throwable $e ) {
	$failed_threw = true;
	$failed = null;
}
$failed_err = b14_errlog_take();
b14_check( 'T2-E5-writer-failure-isolated',
	false === $failed && ! $failed_threw && false !== strpos( $failed_err, 'insert failed for file_upload' ),
	'DB write failure swallowed: false + error_log, no exception crosses into the caller (§6-5 local proxy)',
	'writer failure isolation deviated: ret=' . var_export( $failed, true ) . ' threw=' . var_export( $failed_threw, true ) );

/* Logging path never reads the db_version option. The settings option read
 * count is measured in the FRESH child below (the memo is per-request and the
 * parent process memoized the defaults in earlier sections already). */
b14_reset();
$GLOBALS['B14_USER_ID'] = 3;
$GLOBALS['B14_USERS'][3] = (object) array( 'user_login' => 'hossam_lawyer' );
hossam_audit_log( 'article_create', array( 'object_id' => 5 ) );
$db_version_reads = count( array_filter( $GLOBALS['B14_OPTION_READS'], static fn( $o ) => 'hossam_audit_log_db_version' === $o ) );
b14_check( 'T2-E6-logging-path-no-db-version-reads',
	0 === $db_version_reads,
	'logging path never touches hossam_audit_log_db_version (the single writer stays inside ensure_table)',
	'option traffic on the logging path deviated: db_version_reads=' . $db_version_reads . ' reads=' . var_export( $GLOBALS['B14_OPTION_READS'], true ) );

/* ════════════════════════════════════════════════════════════════
 * T2-CHILD scenarios — fresh-request settings memo states
 * ════════════════════════════════════════════════════════════════ */

$c1 = b14_spawn_child( 'defaults' );
b14_check( 'T2-E6-defaults-and-memo',
	( $c1['settings']['enabled'] ?? null ) === true
		&& ( $c1['settings']['ip_enabled'] ?? null ) === false
		&& ( $c1['settings']['days'] ?? null ) === 90
		&& true === ( $c1['same_memo'] ?? false )
		&& 1 === ( $c1['write_settings_reads'] ?? -1 )
		&& 0 === ( $c1['write_db_version_reads'] ?? -1 )
		&& 1 === ( $c1['insert_count'] ?? -1 )
		&& 1 === ( $c1['settings_reads'] ?? -1 ),
	'fresh request: write path reads the settings option exactly once (lazy memo holds across the writer + 3 later reads), zero db_version reads, defaults enabled=true/ip_enabled=false/days=90',
	'defaults/memo deviated: ' . json_encode( $c1 ) );

$c2 = b14_spawn_child( 'disabled' );
b14_check( 'T2-WRITER-enabled-false-no-write',
	true === ( $c2['writer_return'] ?? null ) && 0 === ( $c2['insert_count'] ?? -1 ),
	'enabled=false ⇒ hossam_audit_log returns true with zero INSERTs (Q4-5)',
	'enabled=false deviated: ' . json_encode( $c2 ) );
b14_check( 'T2-E8-enabled-false-audit-share-only',
	0 === ( $c2['audit_deleted'] ?? -1 )
		&& 1 === ( $c2['notifications_deleted'] ?? -1 )
		&& 1 === ( $c2['messages_deleted'] ?? -1 )
		&& 1 === ( $c2['ai_jobs_deleted'] ?? -1 )
		&& 0 === ( $c2['clears_scheduled'] ?? -1 ),
	'enabled=false stops the audit_log retention share only; the other three shares run; zero unschedule calls',
	'enabled=false retention split deviated: ' . json_encode( $c2 ) );

$c3 = b14_spawn_child( 'ip' );
b14_check( 'T2-WRITER-ip-enabled-remote-addr-only',
	true === ( $c3['first_has_remote_ip'] ?? null )
		&& false === ( $c3['first_has_forwarded'] ?? null )
		&& true === ( $c3['first_has_mime_meta'] ?? null )
		&& true === ( $c3['second_null_ip_inline'] ?? null ),
	'ip_enabled=true: REMOTE_ADDR only (X-Forwarded-For never read), invalid REMOTE_ADDR ⇒ NULL inline',
	'ip policy deviated: ' . json_encode( $c3 ) );

$c4 = b14_spawn_child( 'secret_disabled' );
b14_check( 'T2-E11-secret-listener-respects-switch',
	0 === ( $c4['insert_count'] ?? -1 ),
	'the secret listener honors enabled=false: hook fired, zero rows',
	'secret listener ignored the switch: ' . json_encode( $c4 ) );

/* ════════════════════════════════════════════════════════════════
 * T2-E7 — single-writer contract (static + behavioral fingerprints)
 * ════════════════════════════════════════════════════════════════ */

$audit_src = (string) file_get_contents( $GLOBALS['B14_PROJECT'] . '/runtime/core/audit-log.php' );
preg_match_all( '/\b(?:update_option|add_option)\s*\(\s*\'([a-z0-9_]+)\'/i', $audit_src, $b14_om );
$written_options = array_unique( $b14_om[1] );
b14_check( 'T2-E7-zero-writes-on-db-version',
	! in_array( 'hossam_audit_log_db_version', $written_options, true )
		&& 0 === substr_count( $audit_src, "update_option( 'hossam_audit_log_db_version" )
		&& 0 === substr_count( $audit_src, "add_option( 'hossam_audit_log_db_version" ),
	'static: zero update_option/add_option on hossam_audit_log_db_version in the unit (the writer stays ensure_table — tables.php:196 pattern)',
	'db_version writes found in the unit: ' . var_export( $written_options, true ) );
b14_check( 'T2-E7-settings-writes-carry-false',
	false !== strpos( $audit_src, "add_option( 'hossam_audit_log_settings', \$payload, false )" )
		&& false !== strpos( $audit_src, "update_option( 'hossam_audit_log_settings', \$payload, false )" ),
	'static: both settings write sites carry the false autoload argument literally (C7-correct fingerprint)',
	'settings write sites deviated' );

/* Behavioral fingerprints of the settings writer. */
b14_reset();
$first_write  = hossam_audit_log_update_settings( array( 'days' => 120 ) );
$fingerprint1 = $GLOBALS['B14_OPTION_WRITES'];
$same_write   = hossam_audit_log_update_settings( array( 'days' => 120 ) ); // identical payload ⇒ no write
$writes_after_same = count( $GLOBALS['B14_OPTION_WRITES'] );
$second_write = hossam_audit_log_update_settings( array( 'days' => 60, 'enabled' => false ) ); // existing ⇒ update
$fingerprint2 = $GLOBALS['B14_OPTION_WRITES'];
b14_check( 'T2-E7-settings-writer-fingerprints',
	true === $first_write && 1 === count( $fingerprint1 ) && 'add' === $fingerprint1[0]['op']
		&& false === $fingerprint1[0]['autoload']
		&& true === $same_write && 1 === $writes_after_same
		&& true === $second_write && 2 === count( $fingerprint2 ) && 'update' === $fingerprint2[1]['op']
		&& false === $fingerprint2[1]['autoload'],
	'first write = add_option(...,false); identical payload = no write; second write = update_option(...,false) — the false argument is always present',
	'settings writer fingerprints deviated: ' . var_export( $GLOBALS['B14_OPTION_WRITES'], true ) );

/* days clamp inside the writer payload. */
b14_reset();
hossam_audit_log_update_settings( array( 'days' => 1000 ) );
$clamped_high = $GLOBALS['B14_OPTION_WRITES'][0]['value']['days'];
$GLOBALS['B14_OPTION_WRITES'] = array();
hossam_audit_log_update_settings( array( 'days' => 0 ) );
$clamped_low = $GLOBALS['B14_OPTION_WRITES'][0]['value']['days'];
b14_check( 'T2-E7-settings-days-clamped-1-365',
	365 === $clamped_high && 1 === $clamped_low,
	'update_settings clamps days into 1..365 (1000→365, 0→1)',
	'days clamp deviated: high=' . $clamped_high . ' low=' . $clamped_low );

/* ════════════════════════════════════════════════════════════════
 * T2-E4/E10 — statics: superglobals, no new surface, no schema drift
 * (code-only: comments/docblocks stripped first — they document the zeros)
 * ════════════════════════════════════════════════════════════════ */

$audit_code = preg_replace( '/\/\*.*?\*\//s', '', $audit_src ); // block/docblock comments
$audit_code = preg_replace( '/(?:(?:\/\/|#)[^\n]*)/', '', (string) $audit_code ); // line comments
b14_check( 'T2-E4-zero-superglobals',
	0 === substr_count( (string) $audit_code, '$_POST' ) && 0 === substr_count( (string) $audit_code, '$_GET' )
		&& 0 === substr_count( (string) $audit_code, '$_REQUEST' ) && 0 === substr_count( (string) $audit_code, '$_COOKIE' )
		&& 0 === preg_match_all( '/\$_(POST|GET|REQUEST|COOKIE)\b/', (string) $audit_code ),
	'static (two ways, code only): zero $_POST/$_GET/$_REQUEST/$_COOKIE in the unit (§6-4)',
	'superglobals found in audit-log.php code' );
$_server_keys = array();
if ( preg_match_all( '/\$_SERVER\s*\[\s*\'([A-Z_]+)\'\s*\]/', (string) $audit_code, $b14_sm ) ) {
	$_server_keys = array_unique( $b14_sm[1] );
}
b14_check( 'T2-E4-server-restricted-to-remote-addr',
	array( 'REMOTE_ADDR' ) === $_server_keys,
	'$_SERVER reads restricted to REMOTE_ADDR (the documented ip_enabled exception, behind FILTER_VALIDATE_IP)',
	'$_SERVER reads deviated: ' . var_export( $_server_keys, true ) );
b14_check( 'T2-E10-no-new-surface',
	0 === substr_count( (string) $audit_code, 'wp_ajax_' ) && 0 === substr_count( (string) $audit_code, 'register_rest_route' )
		&& 0 === preg_match_all( '/wp_ajax_|register_rest_route/', (string) $audit_code )
		&& 0 === substr_count( (string) $audit_code, 'ALTER TABLE' ) && 0 === substr_count( (string) $audit_code, 'DROP TABLE' )
		&& 0 === substr_count( (string) $audit_code, 'wp_clear_scheduled_hook' ),
	'static: zero endpoints, zero ALTER/DROP (schema drift proxy), zero unschedule from the unit (§6-10 + Q4-5)',
	'surface statics failed on audit-log.php' );

/* R4: single deactivation hook in the plugin boot file clears exactly the one event. */
$main_src = (string) file_get_contents( $GLOBALS['B14_PROJECT'] . '/hal-frontend-dashboard.php' );
preg_match_all( '/register_deactivation_hook\s*\(/', $main_src, $b14_dh );
b14_check( 'T2-R4-single-deactivation-clears-one-event',
	1 === count( $b14_dh[0] )
		&& false !== strpos( $main_src, "wp_clear_scheduled_hook( 'hossam_dashboard_retention_sweep_event' )" )
		&& 1 === substr_count( $main_src, 'wp_clear_scheduled_hook' )
		&& false !== strpos( $main_src, 'register_activation_hook' ),
	'hal-frontend-dashboard.php: exactly one register_deactivation_hook clearing exactly hossam_dashboard_retention_sweep_event (R4 — static; live boot needs the mu-loader environment)',
	'deactivation wiring deviated: hooks=' . count( $b14_dh[0] ) );

/* ════════════════════════════════════════════════════════════════
 * T2-E11 — the single secret listener (set / delete / key_mismatch / ignore)
 * ════════════════════════════════════════════════════════════════ */

$secret_entries = b14_hook_sorted( 'action', 'hal_frontend_dashboard_secret_audit' );
b14_check( 'T2-E11-listener-registered-once',
	1 === count( $secret_entries ) && 'hossam_audit_log_on_secret_audit' === ( is_string( $secret_entries[0]['cb'] ) ? $secret_entries[0]['cb'] : '' )
		&& 1 === substr_count( $audit_src, "add_action( 'hal_frontend_dashboard_secret_audit'" ),
	'exactly one listener for the secret hook, registered from one place, with the store signature untouched',
	'secret listener registration deviated: ' . var_export( $secret_entries, true ) );

b14_reset();
$GLOBALS['B14_USER_ID'] = 3;
$GLOBALS['B14_USERS'][3] = (object) array( 'user_login' => 'hossam_lawyer' );
do_action( 'hal_frontend_dashboard_secret_audit', array( 'action' => 'set', 'secret_id' => 'sec-1', 'actor' => 3, 'time' => 'now' ) );
do_action( 'hal_frontend_dashboard_secret_audit', array( 'action' => 'delete', 'secret_id' => 'sec-1', 'actor' => 3, 'time' => 'now' ) );
do_action( 'hal_frontend_dashboard_secret_audit', array( 'action' => 'key_mismatch', 'secret_id' => 'sec-9', 'actor' => 3, 'time' => 'now' ) );
$secret_sqls = $GLOBALS['wpdb']->queries;
$secret_meta_needle = addslashes( (string) wp_json_encode( array( 'secret_id' => 'sec-1' ) ) );
b14_check( 'T2-E11-three-branches-write-rows',
	3 === count( $secret_sqls )
		&& false !== strpos( (string) $secret_sqls[0], "'secret_set', 'secret', NULL, ''" )
		&& false !== strpos( (string) $secret_sqls[0], $secret_meta_needle )
		&& false !== strpos( (string) $secret_sqls[1], "'secret_delete', 'secret', NULL, ''" )
		&& false !== strpos( (string) $secret_sqls[1], $secret_meta_needle )
		&& false !== strpos( (string) $secret_sqls[2], "'secret_key_mismatch', 'secret', NULL, ''" )
		&& false !== strpos( (string) $secret_sqls[2], addslashes( (string) wp_json_encode( array( 'secret_id' => 'sec-9' ) ) ) ),
	'branches: set→secret_set, delete→secret_delete, key_mismatch→secret_key_mismatch — object_type=secret, object_id NULL inline, meta secret_id (delta row 20 live)',
	'secret branch rows deviated: ' . var_export( $secret_sqls, true ) );

/* Defensive ignore: any other action value, and non-array payloads. */
b14_reset();
$ignored_threw = false;
try {
	do_action( 'hal_frontend_dashboard_secret_audit', array( 'action' => 'get_failed', 'secret_id' => 'sec-1' ) );
	do_action( 'hal_frontend_dashboard_secret_audit', array( 'action' => 'bogus', 'secret_id' => 'sec-1' ) );
	do_action( 'hal_frontend_dashboard_secret_audit', array( 'action' => '', 'secret_id' => 'sec-1' ) );
	do_action( 'hal_frontend_dashboard_secret_audit', 'not-an-array' );
	do_action( 'hal_frontend_dashboard_secret_audit', null );
} catch ( Throwable $e ) {
	$ignored_threw = true;
}
b14_check( 'T2-E11-defensive-ignore',
	! $ignored_threw && 0 === count( $GLOBALS['wpdb']->queries ),
	'any payload outside the three mapped actions (incl. get_failed) and non-array payloads are ignored with zero rows (§6-11)',
	'defensive ignore deviated: threw=' . var_export( $ignored_threw, true ) . ' queries=' . count( $GLOBALS['wpdb']->queries ) );

/* ════════════════════════════════════════════════════════════════
 * T2-H1 — the H1 policy (Q5 / event 19)
 * ════════════════════════════════════════════════════════════════ */

b14_check( 'T2-H1-default-policy-normalize',
	'normalize' === hossam_article_h1_policy(),
	'the policy default is normalize (the valve works unless turned off)',
	'default policy deviated: ' . var_export( hossam_article_h1_policy(), true ) );

$policy_cb = static fn( $value ) => 'reject';
add_filter( 'hossam_article_h1_policy', $policy_cb, 10, 1 );
$policy_reject = hossam_article_h1_policy();
$off_cb = static fn( $value ) => 'off';
add_filter( 'hossam_article_h1_policy', $off_cb, 20, 1 );
$policy_off = hossam_article_h1_policy();
remove_filter( 'hossam_article_h1_policy', $off_cb, 20 );
$bogus_cb = static fn( $value ) => 'bogus-value';
add_filter( 'hossam_article_h1_policy', $bogus_cb, 30, 1 );
remove_filter( 'hossam_article_h1_policy', $policy_cb, 10 );
$policy_bogus = hossam_article_h1_policy();
remove_filter( 'hossam_article_h1_policy', $bogus_cb, 30 );
b14_check( 'T2-H1-filter-values-closed-set',
	'reject' === $policy_reject && 'off' === $policy_off && 'normalize' === $policy_bogus,
	'policy filter accepts normalize/reject/off; any other value falls back to normalize (only off disables)',
	'policy values deviated: ' . var_export( array( $policy_reject, $policy_off, $policy_bogus ), true ) );

$detector_cases = array(
	array( '<p>a</p><h1>b</h1>', true ),
	array( '<h1 >x', true ),
	array( '<h1/>x', true ),
	array( '<H1 CLASS="a">x</H1>', true ),
	array( '<h10>x</h10>', false ),
	array( '<h2>x</h2>', false ),
	array( '', false ),
);
$detector_ok = true;
foreach ( $detector_cases as $case ) {
	if ( hossam_audit_log_content_has_h1( $case[0] ) !== $case[1] ) {
		$detector_ok = false;
	}
}
b14_check( 'T2-H1-detector-semantics',
	$detector_ok,
	'detector matches <h1/<h1 /<h1/<H1 ATTR and never h10/h2 (post-kses stored payload scope)',
	'detector semantics deviated' );

b14_reset();
add_filter( 'hossam_article_h1_policy', $policy_cb, 10, 1 ); // reject
$reject_with_h1 = hossam_article_h1_should_reject( '<p>x</p><h1>big</h1>' );
$reject_clean   = hossam_article_h1_should_reject( '<p>clean</p>' );
remove_filter( 'hossam_article_h1_policy', $policy_cb, 10 );
$normalize_rejects = hossam_article_h1_should_reject( '<h1>big</h1>' );
$off_cb2 = static fn( $value ) => 'off';
add_filter( 'hossam_article_h1_policy', $off_cb2, 10, 1 );
$off_rejects = hossam_article_h1_should_reject( '<h1>big</h1>' );
remove_filter( 'hossam_article_h1_policy', $off_cb2, 10 );
b14_check( 'T2-H1-should-reject-matrix',
	true === $reject_with_h1 && false === $reject_clean && false === $normalize_rejects && false === $off_rejects,
	'reject decision: true only for reject+h1; clean content, normalize and off never reject (the R14 caller turns this into the clean 400)',
	'reject matrix deviated' );

/* normalize handler: post type only, converts h1→h2, logs event 19 with count. */
b14_reset();
$GLOBALS['B14_USER_ID'] = 3;
$GLOBALS['B14_USERS'][3] = (object) array( 'user_login' => 'hossam_lawyer' );
$h1_handler = b14_hook_sorted( 'filter', 'wp_insert_post_data' );
b14_check( 'T2-H1-handler-registered-once',
	1 === count( $h1_handler ) && 'hossam_audit_log_normalize_h1_post_data' === ( is_string( $h1_handler[0]['cb'] ) ? $h1_handler[0]['cb'] : '' ),
	'wp_insert_post_data handler registered exactly once by the unit',
	'H1 handler registration deviated' );

$data = array(
	'ID'           => 77,
	'post_type'    => 'post',
	'post_title'   => 'H1 Article <h1>in title</h1>',
	'post_content' => "<p>intro</p>\n<h1>First H1</h1>\n<h2>kept</h2>\n<h1 class=\"x\">Second</h1>\n<h10>not a heading</h10>\n</h1>orphan close",
);
$out = hossam_audit_log_normalize_h1_post_data( $data, $data );
b14_check( 'T2-H1-normalize-converts-post-content',
	false !== strpos( $out['post_content'], '<h2>First H1</h2>' )
		&& false !== strpos( $out['post_content'], '<h2 class="x">Second</h2>' )
		&& ! hossam_audit_log_content_has_h1( (string) $out['post_content'] )
		&& false !== strpos( $out['post_content'], '<h10>not a heading</h10>' )
		&& false !== strpos( $out['post_content'], '</h2>orphan close' ),
	'normalize converts every h1 (open/close, attrs, case) to h2 in post_content; h10 and non-h1 tags untouched (no h1 tag remains per the real detector)',
	'normalize conversion deviated: ' . var_export( $out['post_content'], true ) );
b14_check( 'T2-H1-normalize-title-untouched',
	$data['post_title'] === $out['post_title'],
	'post_title never touched by the policy (no check outside post_content)',
	'title was modified by the handler' );
$h1_rows = array_filter( $GLOBALS['wpdb']->queries, static fn( $q ) => false !== strpos( (string) $q, "'article_h1_normalized'" ) );
$h1_meta_needle = addslashes( (string) wp_json_encode( array( 'count' => 2 ) ) );
b14_check( 'T2-H1-normalize-logs-event-19',
	1 === count( $h1_rows )
		&& false !== strpos( (string) reset( $h1_rows ), "'article_h1_normalized', 'article', 77," )
		&& false !== strpos( (string) reset( $h1_rows ), $h1_meta_needle ),
	'one article_h1_normalized row per converting save with meta.count=2 (the number of converted h1 tags) — event 19 live',
	'event 19 logging deviated: ' . var_export( array_values( $h1_rows ), true ) );

/* No h1 ⇒ no conversion, no event. */
b14_reset();
$clean_data = array( 'ID' => 5, 'post_type' => 'post', 'post_title' => 'Clean', 'post_content' => '<p>no headings here</p>' );
$out2 = hossam_audit_log_normalize_h1_post_data( $clean_data, $clean_data );
b14_check( 'T2-H1-clean-content-noop',
	$out2 === $clean_data && 0 === count( $GLOBALS['wpdb']->queries ),
	'content without h1 passes byte-identical with zero audit rows',
	'clean content path deviated' );

/* Non-post types are exempt (revisions/autosaves carry their own type). */
b14_reset();
$page_data = array( 'ID' => 6, 'post_type' => 'page', 'post_title' => 'Page', 'post_content' => '<h1>page h1</h1>' );
$out3 = hossam_audit_log_normalize_h1_post_data( $page_data, $page_data );
$rev_data = array( 'ID' => 7, 'post_type' => 'revision', 'post_title' => 'Rev', 'post_content' => '<h1>rev h1</h1>' );
$out4 = hossam_audit_log_normalize_h1_post_data( $rev_data, $rev_data );
b14_check( 'T2-H1-post-type-only',
	$out3['post_content'] === $page_data['post_content'] && $out4['post_content'] === $rev_data['post_content']
		&& 0 === count( $GLOBALS['wpdb']->queries ),
	'non-post types (page/revision/autosave) pass untouched with zero rows — the policy is post-only',
	'non-post exemption deviated' );

/* off/reject: no conversion and no logging from the handler. */
b14_reset();
$off_cb3 = static fn( $value ) => 'off';
add_filter( 'hossam_article_h1_policy', $off_cb3, 10, 1 );
$post_data = array( 'ID' => 8, 'post_type' => 'post', 'post_title' => 'T', 'post_content' => '<h1>kept</h1>' );
$out5 = hossam_audit_log_normalize_h1_post_data( $post_data, $post_data );
remove_filter( 'hossam_article_h1_policy', $off_cb3, 10 );
$reject_cb = static fn( $value ) => 'reject';
add_filter( 'hossam_article_h1_policy', $reject_cb, 10, 1 );
$out6 = hossam_audit_log_normalize_h1_post_data( $post_data, $post_data );
remove_filter( 'hossam_article_h1_policy', $reject_cb, 10 );
b14_check( 'T2-H1-off-and-reject-no-conversion',
	$out5['post_content'] === $post_data['post_content'] && $out6['post_content'] === $post_data['post_content']
		&& 0 === count( $GLOBALS['wpdb']->queries ),
	'off and reject both leave the handler inert (no conversion, no event — reject is enforced at the R14 call point)',
	'off/reject handler behavior deviated' );

/* Slashed payload contract: backslashes survive, title snapshot unslashed. */
b14_reset();
$GLOBALS['B14_USER_ID'] = 3;
$GLOBALS['B14_USERS'][3] = (object) array( 'user_login' => 'hossam_lawyer' );
$slashed = array(
	'ID'           => 9,
	'post_type'    => 'post',
	'post_title'   => "It\\'s fine",
	'post_content' => '<h1>Slash \\"Keep\\"</h1>',
);
$out7 = hossam_audit_log_normalize_h1_post_data( $slashed, $slashed );
b14_check( 'T2-H1-slashed-payload-contract',
	false !== strpos( $out7['post_content'], '<h2>Slash \\"Keep\\"</h2>' )
		&& 1 === preg_match( "/'It\\\\'s fine',/", (string) ( $GLOBALS['wpdb']->queries[0] ?? '' ) ),
	'slashed filter payload: backslashes untouched in content, title snapshot stored unslashed (It\'s — the wp_insert_post_data contract)',
	'slashed payload handling deviated: ' . var_export( $out7, true ) . ' q=' . var_export( $GLOBALS['wpdb']->queries, true ) );

/* New post (no ID): event 19 carries object_id NULL. */
b14_reset();
$new_post = array( 'post_type' => 'post', 'post_title' => 'New', 'post_content' => '<h1>x</h1>' );
hossam_audit_log_normalize_h1_post_data( $new_post, $new_post );
b14_check( 'T2-H1-new-post-null-object-id',
	1 === preg_match( '/\'article_h1_normalized\', \'article\', NULL,/', (string) ( $GLOBALS['wpdb']->queries[0] ?? '' ) ),
	'new posts (no ID) log event 19 with object_id NULL inline',
	'new-post event deviated: ' . var_export( $GLOBALS['wpdb']->queries, true ) );

/* ════════════════════════════════════════════════════════════════
 * T2-E8/E9 — retention engine
 * ════════════════════════════════════════════════════════════════ */

$defs = hossam_audit_log_retention_definitions();
$expected_defs = array(
	array( 'key' => 'audit_log', 'table_suffix' => 'hossam_audit_log', 'date_column' => 'created_at', 'days' => 90, 'batch' => 500, 'terminal_only' => false ),
	array( 'key' => 'ai_jobs', 'table_suffix' => 'hossam_ai_jobs', 'date_column' => 'updated_at', 'days' => 30, 'batch' => 500, 'terminal_only' => true ),
	array( 'key' => 'notifications', 'table_suffix' => 'hossam_notifications', 'date_column' => 'created_at', 'days' => 90, 'batch' => 500, 'terminal_only' => false ),
	array( 'key' => 'messages', 'table_suffix' => 'hossam_messages', 'date_column' => 'created_at', 'days' => 180, 'batch' => 500, 'terminal_only' => false ),
);
b14_check( 'T2-E8-four-definitions-90-30-90-180',
	$defs === $expected_defs,
	'retention definitions: four tables with days 90/30/90/180, correct date columns, batch 500, ai_jobs terminal-only',
	'retention definitions deviated: ' . var_export( $defs, true ) );

/* Sweep shape: cutoff by gmdate, ORDER BY id LIMIT 500 via prepare, terminal-only literal. */
b14_reset();
$rows = array();
for ( $i = 1; $i <= 6000; $i++ ) {
	$rows[] = array( 'id' => $i, 'created_at' => gmdate( 'Y-m-d H:i:s', time() - ( 91 * DAY_IN_SECONDS ) ) );
}
for ( $i = 6001; $i <= 6010; $i++ ) {
	$rows[] = array( 'id' => $i, 'created_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ); // fresh — must survive
}
$GLOBALS['B14_DB_ROWS']['wp_hossam_audit_log'] = $rows;
$cutoff_90 = gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS );
$deleted = hossam_audit_log_retention_sweep_table( $GLOBALS['wpdb'], $defs[0], true );
$delete_sqls = array_values( array_filter( $GLOBALS['wpdb']->queries, static fn( $q ) => false !== strpos( (string) $q, 'DELETE FROM wp_hossam_audit_log' ) ) );
b14_check( 'T2-E8-6000-due-swept-in-one-run',
	6000 === $deleted && 10 === count( $GLOBALS['B14_DB_ROWS']['wp_hossam_audit_log'] )
		&& 13 === count( $delete_sqls ) && 13 <= 20,
	'6000 due rows swept in one run (12 full batches of 500 + the terminal empty probe, 13 ≤ the 20-batch cap); the 10 fresh rows survive (≥5000 acceptance)',
	'sweep count deviated: deleted=' . $deleted . ' remaining=' . count( $GLOBALS['B14_DB_ROWS']['wp_hossam_audit_log'] ) . ' batches=' . count( $delete_sqls ) );
b14_check( 'T2-E8-delete-shape-cutoff-orderby-limit',
	1 === preg_match( "/DELETE FROM wp_hossam_audit_log WHERE created_at < '([^']+)' ORDER BY id LIMIT 500/", (string) $delete_sqls[0], $b14_cut )
		&& abs( strtotime( $b14_cut[1] . ' GMT' ) - strtotime( $cutoff_90 . ' GMT' ) ) <= 2,
	'DELETE carries the gmdate cutoff (90d), ORDER BY id and LIMIT 500 — literal batch shape, zero user input',
	'DELETE shape deviated: ' . var_export( $delete_sqls[0] ?? '', true ) );

/* ai_jobs terminal-only: only completed/failed rows are due. */
b14_reset();
$GLOBALS['B14_DB_ROWS']['wp_hossam_ai_jobs'] = array(
	array( 'id' => 1, 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ), 'status' => 'completed' ),
	array( 'id' => 2, 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ), 'status' => 'failed' ),
	array( 'id' => 3, 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ), 'status' => 'processing' ),
	array( 'id' => 4, 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ), 'status' => 'pending' ),
	array( 'id' => 5, 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ), 'status' => 'completed' ), // not due by date
);
$ai_deleted = hossam_audit_log_retention_sweep_table( $GLOBALS['wpdb'], $defs[1], true );
$ai_sql = '';
foreach ( $GLOBALS['wpdb']->queries as $q ) {
	if ( false !== strpos( (string) $q, 'DELETE FROM wp_hossam_ai_jobs' ) ) {
		$ai_sql = (string) $q;
		break;
	}
}
$remaining = array_map( static fn( $r ) => $r['id'], $GLOBALS['B14_DB_ROWS']['wp_hossam_ai_jobs'] );
b14_check( 'T2-E8-ai-jobs-terminal-only',
	2 === $ai_deleted && array( 3, 4, 5 ) === $remaining
		&& false !== strpos( $ai_sql, "updated_at < '" )
		&& false !== strpos( $ai_sql, "AND status IN ('completed','failed')" ),
	'ai_jobs share: terminal-only literal (status IN completed/failed) on updated_at — processing/pending/fresh rows untouched',
	'terminal-only deviated: deleted=' . $ai_deleted . ' remaining=' . var_export( $remaining, true ) . ' sql=' . $ai_sql );

/* Failure isolation: one failing table does not stop the rest. */
b14_reset();
$GLOBALS['B14_DB_FAIL_TABLES'] = array( 'wp_hossam_audit_log' );
foreach ( array( 'wp_hossam_audit_log', 'wp_hossam_ai_jobs', 'wp_hossam_notifications', 'wp_hossam_messages' ) as $t ) {
	$GLOBALS['B14_DB_ROWS'][ $t ] = array( array( 'id' => 1, 'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00', 'status' => 'completed' ) );
}
$GLOBALS['B14_DB_ROWS']['wp_hossam_ai_jobs'][0]['status'] = 'completed';
b14_errlog_start();
hossam_audit_log_retention_sweep();
$sweep_err = b14_errlog_take();
b14_check( 'T2-E8-failure-isolation',
	1 === count( $GLOBALS['B14_DB_ROWS']['wp_hossam_audit_log'] )
		&& 0 === count( $GLOBALS['B14_DB_ROWS']['wp_hossam_ai_jobs'] )
		&& 0 === count( $GLOBALS['B14_DB_ROWS']['wp_hossam_notifications'] )
		&& 0 === count( $GLOBALS['B14_DB_ROWS']['wp_hossam_messages'] )
		&& false !== strpos( $sweep_err, 'DELETE failed on audit_log' ),
	'failing audit_log DELETE logged and skipped; the other three shares still swept (§6-8 isolation)',
	'failure isolation deviated: err=' . var_export( $sweep_err, true ) . ' rows=' . var_export( array_map( 'count', $GLOBALS['B14_DB_ROWS'] ), true ) );

/* Idempotent double run: second sweep deletes nothing (nothing due). */
b14_reset();
$GLOBALS['B14_DB_ROWS']['wp_hossam_audit_log'] = array( array( 'id' => 1, 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 91 * DAY_IN_SECONDS ) ) );
$run1 = hossam_audit_log_retention_sweep_table( $GLOBALS['wpdb'], $defs[0], true );
$run2 = hossam_audit_log_retention_sweep_table( $GLOBALS['wpdb'], $defs[0], true );
b14_check( 'T2-E8-idempotent-double-run',
	1 === $run1 && 0 === $run2,
	'double invocation idempotent: the historical cutoff deletes once, the second run finds nothing due',
	'idempotency deviated: run1=' . $run1 . ' run2=' . $run2 );

/* Filters clamp: days into 1..365, batch into 1..5000 — per-table filters honored. */
b14_reset();
$days_high_cb = static fn( $value ) => 1000;
add_filter( 'hossam_retention_days_messages', $days_high_cb, 10, 1 );
$GLOBALS['B14_DB_ROWS']['wp_hossam_messages'] = array( array( 'id' => 1, 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ) ) );
hossam_audit_log_retention_sweep_table( $GLOBALS['wpdb'], $defs[3], true );
$msg_sql = '';
foreach ( $GLOBALS['wpdb']->queries as $q ) {
	if ( false !== strpos( (string) $q, 'DELETE FROM wp_hossam_messages' ) ) {
		$msg_sql = (string) $q;
	}
}
remove_filter( 'hossam_retention_days_messages', $days_high_cb, 10 );
$days_low_cb = static fn( $value ) => 0;
add_filter( 'hossam_retention_days_messages', $days_low_cb, 10, 1 );
$GLOBALS['B14_DB_ROWS']['wp_hossam_messages'] = array( array( 'id' => 2, 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ) );
$GLOBALS['wpdb']->queries = array();
hossam_audit_log_retention_sweep_table( $GLOBALS['wpdb'], $defs[3], true );
$msg_sql_low = '';
foreach ( $GLOBALS['wpdb']->queries as $q ) {
	if ( false !== strpos( (string) $q, 'DELETE FROM wp_hossam_messages' ) ) {
		$msg_sql_low = (string) $q;
	}
}
remove_filter( 'hossam_retention_days_messages', $days_low_cb, 10 );
$batch_high_cb = static fn( $value ) => 99999;
add_filter( 'hossam_retention_batch_messages', $batch_high_cb, 10, 1 );
$GLOBALS['B14_DB_ROWS']['wp_hossam_messages'] = array( array( 'id' => 3, 'created_at' => '2020-01-01 00:00:00' ) );
$GLOBALS['wpdb']->queries = array();
hossam_audit_log_retention_sweep_table( $GLOBALS['wpdb'], $defs[3], true );
$msg_sql_batch = '';
foreach ( $GLOBALS['wpdb']->queries as $q ) {
	if ( false !== strpos( (string) $q, 'DELETE FROM wp_hossam_messages' ) ) {
		$msg_sql_batch = (string) $q;
	}
}
remove_filter( 'hossam_retention_batch_messages', $batch_high_cb, 10 );
b14_check( 'T2-E8-filter-clamps-days-batch',
	1 === preg_match( '/ < \'(\d{4}-\d{2}-\d{2})/', $msg_sql, $b14_ch ) && $b14_ch[1] === gmdate( 'Y-m-d', time() - 365 * DAY_IN_SECONDS )
		&& 1 === preg_match( '/ < \'(\d{4}-\d{2}-\d{2})/', $msg_sql_low, $b14_cl ) && $b14_cl[1] === gmdate( 'Y-m-d', time() - 1 * DAY_IN_SECONDS )
		&& false !== strpos( $msg_sql_batch, 'LIMIT 5000' ),
	'per-table filters honored with clamps: days 1000→365, 0→1 (gmdate cutoffs match), batch 99999→5000',
	'filter clamps deviated: high=' . var_export( $msg_sql, true ) . ' low=' . var_export( $msg_sql_low, true ) . ' batch=' . var_export( $msg_sql_batch, true ) );

/* Schedule guard: schedules the single event once; never re-schedules; no unschedule. */
b14_reset();
b14_run_init_closures();
b14_check( 'T2-E8-schedule-guard',
	1 === count( $GLOBALS['B14_CRON_SCHEDULED'] )
		&& 'hossam_dashboard_retention_sweep_event' === $GLOBALS['B14_CRON_SCHEDULED'][0]['hook']
		&& 'daily' === $GLOBALS['B14_CRON_SCHEDULED'][0]['recurrence']
		&& abs( $GLOBALS['B14_CRON_SCHEDULED'][0]['ts'] - ( time() + HOUR_IN_SECONDS ) ) <= 2,
	'init guard schedules exactly the one retention event daily (ai.php:535-540 pattern — idempotent guard)',
	'schedule guard deviated: ' . var_export( $GLOBALS['B14_CRON_SCHEDULED'], true ) );
b14_run_init_closures(); // second init: guard sees the existing schedule → no second event
b14_check( 'T2-E8-schedule-guard-idempotent',
	1 === count( $GLOBALS['B14_CRON_SCHEDULED'] ) && 0 === count( $GLOBALS['B14_CRON_CLEARED'] ),
	'second init with the event already scheduled: zero additional schedules, zero unschedule calls ever',
	'guard idempotency deviated: scheduled=' . count( $GLOBALS['B14_CRON_SCHEDULED'] ) . ' cleared=' . count( $GLOBALS['B14_CRON_CLEARED'] ) );

/* ════════════════════════════════════════════════════════════════
 * T2-R13 — KEY_MISMATCH full path (H3 §4 / G4 §7): WP_Error + hook once
 * + audit row + ciphertext unchanged + the EXCLUSIVE scope (the hook fires
 * on key_mismatch ONLY — never on match/missing/unavailable/decrypt-fail)
 * ════════════════════════════════════════════════════════════════ */

/* Boundary stubs needed by the store's associated_data (host+path of home URL). */
if ( ! function_exists( 'get_home_url' ) ) {
	function get_home_url( $blog_id = null, string $path = '', $scheme = null ): string {
		return 'https://example.test';
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return -1 === $component ? parse_url( (string) $url ) : parse_url( (string) $url, $component );
	}
}

$ss_path = $GLOBALS['B14_PROJECT'] . '/runtime/security/class-secret-store.php';
require $ss_path; // the REAL Secret Store — the R13 subject (sodium loaded via -d extension=sodium)
$ss_src  = (string) file_get_contents( $ss_path );
putenv( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY=' . str_repeat( 'ab', 32 ) ); // 64 hex chars → valid 32-byte key (harness fixture only)

/* The hook recorder rides the real dispatch (registered AFTER the audit-log
 * listener — both consume every firing; the listener count is asserted). */
$GLOBALS['B14_R13_HOOK_CALLS'] = array();
$km_recorder = static function ( $entry ) {
	$GLOBALS['B14_R13_HOOK_CALLS'][] = $entry;
};
add_action( 'hal_frontend_dashboard_secret_audit', $km_recorder, 20, 1 );

b14_reset();
$GLOBALS['B14_USER_ID'] = 3;
$GLOBALS['B14_USERS'][3] = (object) array( 'user_login' => 'hossam_lawyer' );
$forged_fp = str_repeat( 'cd', 16 ); // 32 hex chars — a WRONG key fingerprint
$GLOBALS['B14_OPTIONS'][ HAL_Frontend_Dashboard_Secret_Store::OPTION_NAME ] = array(
	'ai_key' => array(
		'v'      => 1,
		'key_fp' => $forged_fp,
		'nonce'  => base64_encode( str_repeat( '0', SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES ) ),
		'ct'     => base64_encode( 'forged-context-ciphertext' ),
	),
);
b14_errlog_start();
$before_hash = hash( 'sha256', (string) wp_json_encode( $GLOBALS['B14_OPTIONS'][ HAL_Frontend_Dashboard_Secret_Store::OPTION_NAME ] ) );
$km_result = HAL_Frontend_Dashboard_Secret_Store::get( 'ai_key' );
$km_err    = b14_errlog_take();
$after_hash = hash( 'sha256', (string) wp_json_encode( $GLOBALS['B14_OPTIONS'][ HAL_Frontend_Dashboard_Secret_Store::OPTION_NAME ] ) );
$km_entry = $GLOBALS['B14_R13_HOOK_CALLS'][0] ?? array();
$km_entry_keys = array_keys( $km_entry );
sort( $km_entry_keys );
$km_rows = array_values( array_filter( $GLOBALS['wpdb']->queries, static fn( $q ) => false !== strpos( (string) $q, 'INSERT INTO wp_hossam_audit_log' ) ) );

b14_check( 'T2-R13-wp-error-code-and-message',
	is_wp_error( $km_result ) && 'hal_secret_key_mismatch' === $km_result->get_error_code()
		&& 'The master key changed; the stored ciphertext is kept for diagnosis.' === $km_result->get_error_message(),
	'get() over a forged fingerprint ⇒ WP_Error hal_secret_key_mismatch with the exact literal message (R13)',
	'key_mismatch WP_Error deviated: ' . var_export( $km_result, true ) );
b14_check( 'T2-R13-hook-once-four-field-payload-zero-secret-bytes',
	1 === count( $GLOBALS['B14_R13_HOOK_CALLS'] )
		&& array( 'action', 'actor', 'secret_id', 'time' ) === $km_entry_keys
		&& 'key_mismatch' === $km_entry['action'] && 'ai_key' === $km_entry['secret_id'] && 3 === $km_entry['actor']
		&& false === strpos( (string) wp_json_encode( $km_entry ), 'forged-context-ciphertext' )
		&& false === strpos( (string) wp_json_encode( $km_entry ), $forged_fp ),
	'the hook fires exactly once with the four-field payload {action,secret_id,actor,time} and ZERO secret bytes (no ct/nonce/key_fp/value anywhere)',
	'hook payload deviated: calls=' . count( $GLOBALS['B14_R13_HOOK_CALLS'] ) . ' entry=' . var_export( $km_entry, true ) );
b14_check( 'T2-R13-audit-row-secret-key-mismatch',
	1 === count( $km_rows ) && false !== strpos( (string) $km_rows[0], "'secret_key_mismatch', 'secret', NULL, ''" )
		&& false !== strpos( (string) $km_rows[0], addslashes( (string) wp_json_encode( array( 'secret_id' => 'ai_key' ) ) ) ),
	'one secret_key_mismatch row was written (enabled=true default) with object_type=secret and meta secret_id only',
	'audit row deviated: rows=' . count( $km_rows ) . ' sql=' . var_export( $km_rows[0] ?? '', true ) );
b14_check( 'T2-R13-ciphertext-bytes-unchanged',
	$before_hash === $after_hash
		&& 0 === count( array_filter( $GLOBALS['B14_OPTION_WRITES'], static fn( $w ) => HAL_Frontend_Dashboard_Secret_Store::OPTION_NAME === $w['option'] ) ),
	'the stored ciphertext record is byte-identical before/after get() (hash equal) and the mismatch path writes ZERO options',
	'ciphertext mutation deviated: before=' . $before_hash . ' after=' . $after_hash );
b14_check( 'T2-R13-single-error-log-line',
	1 === substr_count( $km_err, 'HAL secret audit: action=key_mismatch secret_id=ai_key actor=3' ),
	'exactly one scrubbed error_log line for the key_mismatch audit (the audit() disclosure pattern)',
	'error_log deviated: ' . var_export( $km_err, true ) );

/* Exclusive scope: the hook fires on key_mismatch ONLY. */
$listener_before = count( $GLOBALS['B14_R13_HOOK_CALLS'] );

/* match: a REAL record encrypted with the CURRENT key — decrypts clean, no hook. */
$km_key = hex2bin( str_repeat( 'ab', 32 ) );
$km_ad  = (string) wp_json_encode( array( 'site' => 'example.test', 'id' => 'ai_key', 'schema' => 1 ) );
$km_nonce = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
$km_ct = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( 'unit-secret-value', $km_ad, $km_nonce, $km_key );
$GLOBALS['B14_OPTIONS'][ HAL_Frontend_Dashboard_Secret_Store::OPTION_NAME ]['ai_key'] = array(
	'v'      => 1,
	'key_fp' => HAL_Frontend_Dashboard_Secret_Store::get_key_fingerprint(),
	'nonce'  => base64_encode( $km_nonce ),
	'ct'     => base64_encode( $km_ct ),
);
$km_match = HAL_Frontend_Dashboard_Secret_Store::get( 'ai_key' );

/* ERR_MISSING. */
$km_missing = HAL_Frontend_Dashboard_Secret_Store::get( 'not_configured' );

/* ERR_DECRYPT_FAILED: correct fingerprint, corrupted ciphertext. */
$GLOBALS['B14_OPTIONS'][ HAL_Frontend_Dashboard_Secret_Store::OPTION_NAME ]['bad_ct'] = array(
	'v'      => 1,
	'key_fp' => HAL_Frontend_Dashboard_Secret_Store::get_key_fingerprint(),
	'nonce'  => base64_encode( str_repeat( '1', SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES ) ),
	'ct'     => base64_encode( 'corrupted-bytes' ),
);
$km_decrypt = HAL_Frontend_Dashboard_Secret_Store::get( 'bad_ct' );

/* ERR_UNAVAILABLE: remove the external key source (env unset, constant absent). */
putenv( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY' );
$km_unavailable = HAL_Frontend_Dashboard_Secret_Store::get( 'ai_key' );
putenv( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY=' . str_repeat( 'ab', 32 ) );

$hook_after_scope = count( $GLOBALS['B14_R13_HOOK_CALLS'] ) - $listener_before;
b14_check( 'T2-R13-scope-exclusive-key-mismatch-only',
	'unit-secret-value' === $km_match
		&& is_wp_error( $km_missing ) && 'hal_secret_missing' === $km_missing->get_error_code()
		&& is_wp_error( $km_decrypt ) && 'hal_secret_decrypt_failed' === $km_decrypt->get_error_code()
		&& is_wp_error( $km_unavailable ) && 'hal_secret_store_unavailable' === $km_unavailable->get_error_code()
		&& 0 === $hook_after_scope,
	'the hook NEVER fires on match / ERR_MISSING / ERR_DECRYPT_FAILED / ERR_UNAVAILABLE — its scope is exclusively key_mismatch (H3 §4.4-2/§4.5)',
	'exclusive scope deviated: match=' . var_export( $km_match, true )
		. ' missing=' . var_export( $km_missing->get_error_code() ?? $km_missing, true )
		. ' decrypt=' . var_export( $km_decrypt->get_error_code() ?? $km_decrypt, true )
		. ' unavailable=' . var_export( $km_unavailable->get_error_code() ?? $km_unavailable, true )
		. ' extra_hook_calls=' . $hook_after_scope );

/* Static: the audit() docblock documents set|delete|key_mismatch; get_failed is gone (C3-1). */
b14_check( 'T2-R13-docblock-set-delete-key-mismatch',
	false !== strpos( $ss_src, '@param string $action set|delete|key_mismatch' )
		&& 0 === substr_count( $ss_src, 'get_failed' )
		&& 1 === substr_count( $ss_src, "self::audit( 'key_mismatch'" ),
	'static: audit() docblock = set|delete|key_mismatch, zero get_failed mentions in the store (C3-1 removal final), exactly one key_mismatch audit call site',
	'docblock/call-site deviated: doc=' . var_export( false !== strpos( $ss_src, '@param string $action set|delete|key_mismatch' ), true )
		. ' get_failed=' . substr_count( $ss_src, 'get_failed' ) );
putenv( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY' ); // clean the harness fixture key

/* ════════════════════════════════════════════════════════════════
 * T2-INVENTORY — the physical audit inventory is EXACTLY 20 (two ways):
 * 19 hossam_audit_log( call sites across runtime/ (excluding the definition
 * and the two docblock mentions in audit-log.php) + the single
 * self::audit('key_mismatch') line in class-secret-store.php (G4 §5 / H3 §4.4-6)
 * ════════════════════════════════════════════════════════════════ */

$b14_inv_dir    = $GLOBALS['B14_PROJECT'] . '/runtime';
$b14_inv_raw    = 0;
$b14_inv_token  = 0;
$b14_inv_files  = 0;
$b14_inv_detail = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $b14_inv_dir, FilesystemIterator::SKIP_DOTS ) ) as $b14_inv_file ) {
	if ( ! $b14_inv_file->isFile() || 'php' !== $b14_inv_file->getExtension() ) {
		continue;
	}
	$b14_inv_files++;
	$b14_inv_src = (string) file_get_contents( $b14_inv_file->getPathname() );

	/* Way 1 — raw line scan: skip docblock/comment lines and the definition. */
	foreach ( preg_split( '/\r\n|\r|\n/', $b14_inv_src ) as $line ) {
		if ( false === strpos( $line, 'hossam_audit_log(' ) ) {
			continue;
		}
		$trim = ltrim( $line );
		if ( '' === $trim || '*' === $trim[0] || '//' === substr( $trim, 0, 2 ) ) {
			continue; // docblock/comment mention
		}
		if ( 1 === preg_match( '/function\s+hossam_audit_log\s*\(/', $line ) ) {
			continue; // the definition
		}
		$b14_inv_raw++;
		$b14_inv_detail[] = $b14_inv_file->getBasename();
	}

	/* Way 2 — token scan: T_STRING hossam_audit_log + '(' minus the T_FUNCTION definition. */
	$tokens = token_get_all( $b14_inv_src );
	$n = count( $tokens );
	for ( $i = 0; $i < $n; $i++ ) {
		$tk = $tokens[ $i ];
		if ( ! is_array( $tk ) || T_STRING !== $tk[0] || 'hossam_audit_log' !== $tk[1] ) {
			continue;
		}
		$j = $i + 1;
		while ( $j < $n && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			$j++;
		}
		if ( $j >= $n || '(' !== $tokens[ $j ] ) {
			continue;
		}
		$k = $i - 1;
		while ( $k >= 0 && is_array( $tokens[ $k ] ) && T_WHITESPACE === $tokens[ $k ][0] ) {
			$k--;
		}
		if ( $k >= 0 && is_array( $tokens[ $k ] ) && T_FUNCTION === $tokens[ $k ][0] ) {
			continue; // the definition
		}
		$b14_inv_token++;
	}
}

/* The store's key_mismatch audit line — counted on comment-stripped source. */
$ss_code = '';
foreach ( token_get_all( $ss_src ) as $token ) {
	if ( is_array( $token ) ) {
		if ( in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_OPEN_TAG ), true ) ) {
			continue;
		}
		$ss_code .= $token[1];
	} else {
		$ss_code .= $token;
	}
}
$km_call_sites = (int) preg_match_all( "/self::audit\(\s*'key_mismatch'/", $ss_code );
b14_check( 'T2-INVENTORY-20-physical-calls-two-ways',
	19 === $b14_inv_raw && 19 === $b14_inv_token && 1 === $km_call_sites
		&& 20 === $b14_inv_raw + $km_call_sites && 20 === $b14_inv_token + $km_call_sites,
	"the audit inventory is EXACTLY 20 both ways: 19 hossam_audit_log( call sites across runtime/ ({$b14_inv_files} php files; definition + 2 docblock mentions excluded) + 1 self::audit('key_mismatch') in class-secret-store.php (G4 §5)",
	'inventory deviated: raw=' . $b14_inv_raw . ' token=' . $b14_inv_token . ' km=' . $km_call_sites
		. ' files=' . json_encode( array_count_values( $b14_inv_detail ) ) );

/* ════════════════════════════════════════════════════════════════
 * T2-ZOMBIE — meta.reason values of the zombie sweep survive sanitize_key
 * intact (static + behavioral through the REAL fail_job_if_outstanding)
 * ════════════════════════════════════════════════════════════════ */

require $GLOBALS['B14_PROJECT'] . '/runtime/ajax/ai.php';

b14_reset();
$GLOBALS['B14_USER_ID'] = 3;
$GLOBALS['B14_USERS'][3] = (object) array( 'user_login' => 'hossam_lawyer' );
$zombie_static = 'attempts_exhausted' === sanitize_key( 'attempts_exhausted' )
	&& 'orphaned_pending' === sanitize_key( 'orphaned_pending' );
$z1 = hossam_ai_fail_job_if_outstanding( 88, 'attempts_exhausted' );
$z2 = hossam_ai_fail_job_if_outstanding( 89, 'orphaned_pending' );
$zombie_rows = array_values( array_filter( $GLOBALS['wpdb']->queries, static fn( $q ) => false !== strpos( (string) $q, 'INSERT INTO wp_hossam_audit_log' ) ) );
$z1_needle = addslashes( (string) wp_json_encode( array( 'reason' => 'attempts_exhausted' ) ) );
$z2_needle = addslashes( (string) wp_json_encode( array( 'reason' => 'orphaned_pending' ) ) );
b14_check( 'T2-ZOMBIE-meta-reason-survives-sanitize-key',
	$zombie_static && true === $z1 && true === $z2
		&& 2 === count( $zombie_rows )
		&& false !== strpos( (string) $zombie_rows[0], $z1_needle )
		&& false !== strpos( (string) $zombie_rows[1], $z2_needle )
		&& false !== strpos( (string) $zombie_rows[0], "'ai_fail'" ) && false !== strpos( (string) $zombie_rows[1], "'ai_fail'" ),
	'both zombie reasons (attempts_exhausted / orphaned_pending) pass sanitize_key byte-intact — statically and behaviorally through the REAL fail_job_if_outstanding writing ai_fail rows (V110-H2)',
	'zombie reasons deviated: static=' . var_export( $zombie_static, true )
		. ' z1=' . var_export( $z1, true ) . ' z2=' . var_export( $z2, true )
		. ' rows=' . count( $zombie_rows ) );

/* ════════════════════════════════════════════════════════════════
 * Summary
 * ════════════════════════════════════════════════════════════════ */

echo "B14-T2 RESULT: {$GLOBALS['B14_PASS']} pass, 0 skipped, {$GLOBALS['B14_FAIL']} fail (PHP " . PHP_VERSION . ")\n";
if ( $GLOBALS['B14_FAIL'] > 0 ) {
	echo "Failures:\n" . implode( "\n", $GLOBALS['B14_FAILS'] ) . "\n";
	exit( 1 );
}
exit( 0 );
