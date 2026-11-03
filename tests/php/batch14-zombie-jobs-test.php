<?php
/**
 * HAL Frontend Dashboard — Batch 14 harness T7: zombie pending jobs sweep (R30 / H2 §5).
 *
 * Local, isolated harness: no live WordPress, no network, no database, no
 * browser. Loads the REAL runtime/core/setup.php (the runtime profile
 * resolver), the REAL runtime/core/audit-log.php (the real hossam_audit_log
 * writer) and the REAL runtime/ajax/ai.php, then fires
 * do_action('hossam_ai_sweep_jobs_event') on the REAL registered sweep
 * callback. Only the WordPress boundary (hooks, options, cron, wpdb) is
 * stubbed; no project logic is reimplemented in the harness. The wpdb stub
 * executes the SQL conditions actually captured from the production
 * prepare() against synthetic rows — it is a DB boundary, not a logic
 * reimplementation (batch6 pattern, ajax-integrations-test.php:2290-2400).
 *
 * Coverage (H2-ZOMBIE-JOBS-DESIGN.md §5 acceptance items, R30 delta = block
 * (ج) inside the real hossam_ai_sweep_jobs_event callback):
 *
 *   Z1          exhausted pending (attempt_count >= max_attempts) flips to
 *               failed with last_error='attempts_exhausted', locked_at=NULL,
 *               updated_at UTC, via the existing conditional choke
 *               hossam_ai_fail_job_if_outstanding — and zero worker retries.
 *   Z2          orphaned pending (next_run_at IS NULL, schema-1.1.1 legacy)
 *               flips with 'orphaned_pending'.
 *   MIXED       a row that is both exhausted and NULL-orphaned resolves
 *               'attempts_exhausted' (attempts precede at the reason
 *               ternary).
 *   VALID-STALE a healthy stale pending (attempts < max, next_run_at due)
 *               goes through branch (ب) only (retry scheduled + next_run_at
 *               pinned) and never through (ج).
 *   VALID-FUTURE pending with a future next_run_at is untouched by every
 *               branch (zero UPDATE, zero schedule).
 *   PROCESSING  a processing row past its deadline is touched by branch (أ)
 *               alone ('processing_deadline_exceeded'); (ج) issues no UPDATE
 *               for it; a fresh processing row is touched by nothing.
 *   TERMINAL    completed/failed rows: zero UPDATE, zero audit events.
 *   IDEMPOTENT  a second sweep after the first issues zero new UPDATEs and
 *               zero new ai_fail events (rows_affected guard); three
 *               consecutive sweeps over a batch larger than LIMIT dispose
 *               exactly 20 rows per run while stock remains (LIMIT 20 pinned
 *               in the captured SQL).
 *   AUDIT       every real flip emits exactly one ai_fail row with
 *               object_type=ai_job and the matching meta.reason (flowing
 *               through the REAL audit-log writer); a failed flip (0 rows)
 *               emits nothing.
 *   RACE        interleaving (race_complete_on_select pattern, batch6
 *               :2394): a concurrent completion commits between the (ج)
 *               SELECT and the conditional UPDATE — the UPDATE is issued but
 *               matches zero rows, the completed row is never flipped and no
 *               audit event is written.
 *   DISABLED    the disable gate stays exactly as it was (branch 1 untouched
 *               — zero regression): no profile + ai disabled fails the
 *               outstanding rows feature_disabled with no (ب)/(ج) queries;
 *               no profile + ai enabled still touches nothing. Runs as
 *               self-children of this very file (--b14z-child=<scenario>)
 *               because the profile snapshot is per-request static.
 *   SCHEMA      static delta audit — zero CREATE/ALTER/new columns; the (ج)
 *               SQL rides only on tables.php columns; error_message is never
 *               written; the retention integration contract phrases are
 *               present in both docblocks (H2 §4-5).
 *
 * Usage:  php -d extension=sodium tests/php/batch14-zombie-jobs-test.php  (exit 0 = all pass)
 *
 * Settings-dependent scenarios run as self-children of this very file
 * (--b14z-child=<scenario>) because the runtime profile snapshot is a
 * per-request static: the child process is a fresh request. No extra files
 * are created.
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

if ( PHP_SAPI !== 'cli' ) {
	echo "This harness must run under CLI PHP.\n";
	exit( 1 );
}

$GLOBALS['B14Z_PROJECT'] = dirname( __DIR__, 2 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', ( getenv( 'TMP' ) ?: sys_get_temp_dir() ) . '/hal-b14z-fake-wp-root/' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

/* ════════════════════════════════════════════════════════════════
 * WordPress boundary stubs (WordPress only — never project logic)
 * ════════════════════════════════════════════════════════════════ */

/* ── Hook registry (priority-ordered, dispatching) ── */
$GLOBALS['B14Z_HOOKS']    = array();
$GLOBALS['B14Z_HOOK_SEQ'] = 0;

function b14z_hook_register( string $type, string $hook, callable $cb, int $priority, int $args ): void {
	$GLOBALS['B14Z_HOOKS'][ $type ][ $hook ][] = array(
		'p'   => $priority,
		'seq' => $GLOBALS['B14Z_HOOK_SEQ']++,
		'cb'  => $cb,
		'a'   => $args,
	);
}
function add_action( string $hook, callable $cb, int $priority = 10, int $accepted_args = 1 ): void {
	b14z_hook_register( 'action', $hook, $cb, $priority, $accepted_args );
}
function add_filter( string $hook, callable $cb, int $priority = 10, int $accepted_args = 1 ): void {
	b14z_hook_register( 'filter', $hook, $cb, $priority, $accepted_args );
}
function b14z_hook_sorted( string $type, string $hook ): array {
	$entries = array_values( (array) ( $GLOBALS['B14Z_HOOKS'][ $type ][ $hook ] ?? array() ) );
	usort( $entries, static fn( $x, $y ) => array( $x['p'], $x['seq'] ) <=> array( $y['p'], $y['seq'] ) );
	return $entries;
}
function apply_filters( string $hook, $value, ...$rest ) {
	foreach ( b14z_hook_sorted( 'filter', $hook ) as $entry ) {
		$call_args = array_merge( array( $value ), $rest );
		$value     = call_user_func_array( $entry['cb'], array_slice( $call_args, 0, max( 1, $entry['a'] ) ) );
	}
	return $value;
}
function do_action( string $hook, ...$args ): void {
	foreach ( b14z_hook_sorted( 'action', $hook ) as $entry ) {
		call_user_func_array( $entry['cb'], array_slice( $args, 0, max( 0, $entry['a'] ) ) );
	}
}

/* ── Errors / sanitizers / misc WP helpers ── */
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public string $code = '';
		public string $message = '';
		public $error_data = null;
		public function __construct( string $code = '', string $message = '', $data = null ) {
			$this->code       = $code;
			$this->message    = $message;
			$this->error_data = $data;
		}
		public function get_error_code(): string {
			return $this->code;
		}
		public function get_error_message(): string {
			return $this->message;
		}
		public function get_error_data() {
			return $this->error_data;
		}
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
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
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}

/* ── Users / session (cron context: no user) ── */
function get_current_user_id(): int {
	return (int) ( $GLOBALS['B14Z_USER_ID'] ?? 0 );
}
if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( int $user_id ) {
		return $GLOBALS['B14Z_USERS'][ $user_id ] ?? false;
	}
}

/* ── Options store (settings repository boundary in the children) ── */
$GLOBALS['B14Z_OPTIONS'] = array();
function get_option( string $option, $default_value = false ) {
	return array_key_exists( $option, $GLOBALS['B14Z_OPTIONS'] ) ? $GLOBALS['B14Z_OPTIONS'][ $option ] : $default_value;
}
function update_option( string $option, $value, $autoload = null ): bool {
	$GLOBALS['B14Z_OPTIONS'][ $option ] = $value;
	return true;
}
function add_option( string $option, $value, $autoload = null ): bool {
	if ( array_key_exists( $option, $GLOBALS['B14Z_OPTIONS'] ) ) {
		return false;
	}
	$GLOBALS['B14Z_OPTIONS'][ $option ] = $value;
	return true;
}

/* ── Cron boundary (hook + args identity, batch6 shape) ── */
$GLOBALS['B14Z_SCHEDULED'] = array(); // "hook|args" => ts
function wp_next_scheduled( string $hook, array $args = array() ) {
	$key = $hook . '|' . implode( ',', array_map( 'strval', $args ) );
	return isset( $GLOBALS['B14Z_SCHEDULED'][ $key ] ) ? $GLOBALS['B14Z_SCHEDULED'][ $key ] : false;
}
function wp_schedule_single_event( int $timestamp, string $hook, array $args = array() ) {
	$GLOBALS['B14Z_SCHEDULED'][ $hook . '|' . implode( ',', array_map( 'strval', $args ) ) ] = $timestamp;
	return true;
}
function wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = array() ): bool {
	$GLOBALS['B14Z_SCHEDULED'][ $hook . '|' . implode( ',', array_map( 'strval', $args ) ) ] = $timestamp;
	return true;
}

/* ════════════════════════════════════════════════════════════════
 * wpdb boundary — captures every query and executes the captured
 * production SQL conditions faithfully against synthetic rows.
 * ════════════════════════════════════════════════════════════════ */

$GLOBALS['B14Z_ROWS'] = array();  // id => ['id','status','attempt_count','next_run_at','locked_at','last_error','updated_at']
$GLOBALS['B14Z_AUDIT'] = array(); // ['event','object_id','reason']
$GLOBALS['B14Z_DB_FAIL'] = false;

if ( ! class_exists( 'B14Z_wpdb' ) ) {
	final class B14Z_wpdb {
		public string $prefix       = 'wp_';
		public string $last_error   = '';
		public int $insert_id       = 0;
		public int $blogid          = 1;
		public int $rows_affected   = 0;
		public array $queries       = array(); // prepared SQL captured

		public function flush(): void {
			$this->last_error = '';
		}
		public function prepare( string $query, ...$args ): string {
			// Owner-delegated shape (batch6 #1): one array argument is unpacked.
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			foreach ( $args as $arg ) {
				$pos_s = strpos( $query, '%s' );
				$pos_d = strpos( $query, '%d' );
				if ( false !== $pos_s && ( false === $pos_d || $pos_s < $pos_d ) ) {
					$query = substr_replace( $query, "'" . (string) $arg . "'", (int) $pos_s, 2 );
				} elseif ( false !== $pos_d ) {
					$query = substr_replace( $query, (string) (int) $arg, (int) $pos_d, 2 );
				}
			}
			return $query;
		}
		private function ai_jobs_table(): string {
			return $this->prefix . 'hossam_ai_jobs';
		}
		/** Rows matching the captured production SELECT conditions (DB boundary). */
		private function select_zombies( string $sql ): array {
			$max = 0;
			if ( preg_match( '/attempt_count >= (\d+)/', $sql, $m ) ) {
				$max = (int) $m[1];
			}
			$limit = 0;
			if ( preg_match( '/LIMIT (\d+)\s*$/', $sql, $m ) ) {
				$limit = (int) $m[1];
			}
			$out = array();
			foreach ( $GLOBALS['B14Z_ROWS'] as $row ) {
				if ( 'pending' !== $row['status'] ) {
					continue;
				}
				if ( $row['attempt_count'] >= $max || null === $row['next_run_at'] ) {
					$out[] = $row;
				}
			}
			return $limit > 0 ? array_slice( $out, 0, $limit ) : $out;
		}
		private function select_stale( string $sql ): array {
			$max = 0;
			if ( preg_match( '/attempt_count < (\d+)/', $sql, $m ) ) {
				$max = (int) $m[1];
			}
			$cutoff = '';
			if ( preg_match( "/next_run_at <= '([^']+)'/", $sql, $m ) ) {
				$cutoff = $m[1];
			}
			$limit = 0;
			if ( preg_match( '/LIMIT (\d+)\s*$/', $sql, $m ) ) {
				$limit = (int) $m[1];
			}
			$out = array();
			foreach ( $GLOBALS['B14Z_ROWS'] as $row ) {
				if ( 'pending' !== $row['status'] || null === $row['next_run_at'] || $row['next_run_at'] > $cutoff ) {
					continue;
				}
				if ( $row['attempt_count'] < $max ) {
					$out[] = $row;
				}
			}
			return $limit > 0 ? array_slice( $out, 0, $limit ) : $out;
		}
		private function select_expired_processing( string $sql ): array {
			$cutoff = '';
			if ( preg_match( "/locked_at <= '([^']+)'/", $sql, $m ) ) {
				$cutoff = $m[1];
			}
			$limit = 0;
			if ( preg_match( '/LIMIT (\d+)\s*$/', $sql, $m ) ) {
				$limit = (int) $m[1];
			}
			$out = array();
			foreach ( $GLOBALS['B14Z_ROWS'] as $row ) {
				if ( 'processing' !== $row['status'] || null === $row['locked_at'] || $row['locked_at'] > $cutoff ) {
					continue;
				}
				$out[] = $row['id'];
			}
			return $limit > 0 ? array_slice( $out, 0, $limit ) : $out;
		}
		/** Conditional terminal transition executed against the row model. */
		private function fail_outstanding( string $sql ): int {
			if ( ! preg_match( '/WHERE id = (\d+)/', $sql, $m ) ) {
				return 0;
			}
			$rid = (int) $m[1];
			if ( ! isset( $GLOBALS['B14Z_ROWS'][ $rid ] ) ) {
				return 0;
			}
			$row = $GLOBALS['B14Z_ROWS'][ $rid ];
			if ( 'pending' !== $row['status'] && 'processing' !== $row['status'] ) {
				return 0; // finished between selection and update: zero rows match.
			}
			$GLOBALS['B14Z_ROWS'][ $rid ]['status']     = 'failed';
			$GLOBALS['B14Z_ROWS'][ $rid ]['last_error'] = preg_match( "/last_error = '([a-z0-9_]+)'/", $sql, $r ) ? $r[1] : '';
			$GLOBALS['B14Z_ROWS'][ $rid ]['locked_at']  = null;
			$GLOBALS['B14Z_ROWS'][ $rid ]['updated_at'] = preg_match( "/updated_at = '([^']+)'/", $sql, $u ) ? $u[1] : '';
			return 1;
		}
		/** Unconditional terminal transition (hossam_ai_fail_job shape). */
		private function fail_unconditional( string $sql ): int {
			if ( ! preg_match( '/WHERE id = (\d+)/', $sql, $m ) ) {
				return 0;
			}
			$rid = (int) $m[1];
			if ( ! isset( $GLOBALS['B14Z_ROWS'][ $rid ] ) ) {
				return 0;
			}
			$GLOBALS['B14Z_ROWS'][ $rid ]['status']     = 'failed';
			$GLOBALS['B14Z_ROWS'][ $rid ]['last_error'] = preg_match( "/last_error = '([a-z0-9_]+)'/", $sql, $r ) ? $r[1] : '';
			$GLOBALS['B14Z_ROWS'][ $rid ]['locked_at']  = null;
			$GLOBALS['B14Z_ROWS'][ $rid ]['updated_at'] = preg_match( "/updated_at = '([^']+)'/", $sql, $u ) ? $u[1] : '';
			return 1;
		}
		public function query( string $sql ) {
			$this->queries[] = $sql;
			$this->last_error = '';
			if ( ! empty( $GLOBALS['B14Z_DB_FAIL'] ) ) {
				$this->last_error = 'B14Z fixture db failure';
				$this->rows_affected = 0;
				return false;
			}
			if ( false !== strpos( $sql, 'INSERT INTO' ) && false !== strpos( $sql, 'hossam_audit_log' ) ) {
				// Real audit writer row: capture the event parts it produced.
				$event = preg_match( "/'ai_fail'/", $sql ) ? 'ai_fail' : '';
				$oid   = preg_match( "/'ai_fail', 'ai_job', (\d+)/", $sql, $o ) ? (int) $o[1] : null;
				$reason = preg_match( '/"reason":"([a-z0-9_]+)"/', $sql, $r ) ? $r[1] : null;
				if ( '' !== $event ) {
					$GLOBALS['B14Z_AUDIT'][] = array( 'event' => $event, 'object_id' => $oid, 'reason' => $reason );
				}
				$this->rows_affected = 1;
				return 1;
			}
			if ( false !== strpos( $sql, "SET status = 'failed'" ) ) {
				$affected = ( false !== strpos( $sql, 'AND status IN' ) )
					? $this->fail_outstanding( $sql )
					: $this->fail_unconditional( $sql );
				$this->rows_affected = $affected;
				return $affected;
			}
			$this->rows_affected = 0;
			return 0;
		}
		public function get_var( string $query = null ) {
			$this->queries[] = (string) $query;
			return null;
		}
		public function get_results( string $query = null, $output = null ) {
			$sql = (string) $query;
			$this->queries[] = $sql;
			if ( false !== strpos( $sql, 'next_run_at IS NULL' ) ) {
				// (ج) zombie selection. Interleaving hook (single purpose,
				// batch6 race_complete_on_select shape): when armed, a
				// concurrent winner commits completion right after this
				// SELECT — the stale snapshot below still carries the row.
				$rows = $this->select_zombies( $sql );
				if ( ! empty( $GLOBALS['B14Z_RACE_ROW'] ) && is_array( $GLOBALS['B14Z_ROWS'][ $GLOBALS['B14Z_RACE_ROW'] ] ?? null )
					&& 'pending' === $GLOBALS['B14Z_ROWS'][ $GLOBALS['B14Z_RACE_ROW'] ]['status'] ) {
					$GLOBALS['B14Z_ROWS'][ $GLOBALS['B14Z_RACE_ROW'] ]['status'] = 'completed';
				}
				return ARRAY_A === $output
					? array_map( static fn( $r ) => array( 'id' => $r['id'], 'attempt_count' => $r['attempt_count'] ), $rows )
					: $rows;
			}
			if ( false !== strpos( $sql, 'attempt_count <' ) ) {
				$rows = $this->select_stale( $sql );
				return ARRAY_A === $output
					? array_map( static fn( $r ) => array( 'id' => $r['id'], 'attempt_count' => $r['attempt_count'] ), $rows )
					: $rows;
			}
			return array();
		}
		public function get_row( string $query = null, $output = null ) {
			$this->queries[] = (string) $query;
			return null;
		}
		public function get_col( string $query = null ) {
			$sql = (string) $query;
			$this->queries[] = $sql;
			if ( false !== strpos( $sql, 'locked_at <=' ) ) {
				return $this->select_expired_processing( $sql );
			}
			if ( false !== strpos( $sql, "status = 'processing'" ) || false !== strpos( $sql, "status = 'pending'" ) ) {
				// Disable-gate shape: SELECT id ... WHERE status = 'x' LIMIT 20.
				$limit = preg_match( '/LIMIT (\d+)\s*$/', $sql, $m ) ? (int) $m[1] : 0;
				$status = false !== strpos( $sql, "status = 'processing'" ) ? 'processing' : 'pending';
				$out = array();
				foreach ( $GLOBALS['B14Z_ROWS'] as $row ) {
					if ( $status === $row['status'] ) {
						$out[] = $row['id'];
					}
				}
				return $limit > 0 ? array_slice( $out, 0, $limit ) : $out;
			}
			return array();
		}
		public function update( string $table, array $data, array $where, $format1 = null, $format2 = null ) {
			$this->queries[] = 'UPDATE ' . $table . ' ' . json_encode( array( $data, $where ) );
			$rid = (int) ( $where['id'] ?? 0 );
			if ( isset( $GLOBALS['B14Z_ROWS'][ $rid ] ) ) {
				foreach ( $data as $k => $v ) {
					$GLOBALS['B14Z_ROWS'][ $rid ][ $k ] = $v;
				}
			}
			return 1;
		}
		public function insert( string $table, array $data, $format = null ) {
			$this->queries[] = 'INSERT INTO ' . $table . ' ' . json_encode( $data );
			$this->insert_id = (int) ( $GLOBALS['B14Z_INSERT_ID'] ?? 42 );
			return $this->insert_id;
		}
		public function esc_like( string $text ): string {
			return addcslashes( $text, '_%\\' );
		}
		public function get_charset_collate(): string {
			return '';
		}
	}
}

/* ── Harness bookkeeping ── */
$GLOBALS['B14Z_PASS']  = 0;
$GLOBALS['B14Z_FAIL']  = 0;
$GLOBALS['B14Z_FAILS'] = array();
$GLOBALS['B14Z_SKIP']  = 0;

function b14z_check( string $id, bool $ok, string $pass, string $fail ): void {
	if ( $ok ) {
		$GLOBALS['B14Z_PASS']++;
		echo "PASS [{$id}] {$pass}\n";
	} else {
		$GLOBALS['B14Z_FAIL']++;
		$GLOBALS['B14Z_FAILS'][] = "{$id}: {$fail}";
		echo "FAIL [{$id}] {$fail}\n";
	}
}

function b14z_db(): B14Z_wpdb {
	return $GLOBALS['wpdb'];
}

/** Fresh request state: new wpdb, fresh row model, fresh captures. */
function b14z_reset(): void {
	$GLOBALS['wpdb']          = new B14Z_wpdb();
	$GLOBALS['B14Z_ROWS']     = array();
	$GLOBALS['B14Z_AUDIT']    = array();
	$GLOBALS['B14Z_SCHEDULED'] = array();
	$GLOBALS['B14Z_RACE_ROW'] = 0;
	$GLOBALS['B14Z_DB_FAIL']  = false;
	$GLOBALS['B14Z_USER_ID']  = 0;
	$GLOBALS['B14Z_USERS']    = array();
	$GLOBALS['B14Z_INSERT_ID'] = 42;
}

/** Seed one synthetic ai_jobs row. */
function b14z_seed( int $id, string $status, int $attempt_count, ?string $next_run_at, ?string $locked_at ): void {
	$GLOBALS['B14Z_ROWS'][ $id ] = array(
		'id'            => $id,
		'status'        => $status,
		'attempt_count' => $attempt_count,
		'next_run_at'   => $next_run_at,
		'locked_at'     => $locked_at,
		'last_error'    => null,
		'updated_at'    => '2026-01-01 00:00:00',
	);
}

/** Fire the REAL sweep callback once. */
function b14z_sweep(): void {
	do_action( 'hossam_ai_sweep_jobs_event' );
}

function b14z_zombie_sqls(): array {
	$out = array();
	foreach ( $GLOBALS['wpdb']->queries as $q ) {
		if ( false !== strpos( (string) $q, 'next_run_at IS NULL' ) ) {
			$out[] = (string) $q;
		}
	}
	return $out;
}

function b14z_failed_updates_for( int $id ): array {
	$out = array();
	foreach ( $GLOBALS['wpdb']->queries as $q ) {
		if ( false !== strpos( (string) $q, "SET status = 'failed'" ) && preg_match( '/WHERE id = ' . $id . '\b/', (string) $q ) ) {
			$out[] = (string) $q;
		}
	}
	return $out;
}

function b14z_audit_count( ?int $object_id = null, ?string $reason = null ): int {
	$n = 0;
	foreach ( $GLOBALS['B14Z_AUDIT'] as $e ) {
		if ( 'ai_fail' !== $e['event'] ) {
			continue;
		}
		if ( null !== $object_id && $e['object_id'] !== $object_id ) {
			continue;
		}
		if ( null !== $reason && $e['reason'] !== $reason ) {
			continue;
		}
		$n++;
	}
	return $n;
}

function b14z_result_line(): void {
	echo "B14-T7 RESULT: {$GLOBALS['B14Z_PASS']} pass, {$GLOBALS['B14Z_SKIP']} skipped, {$GLOBALS['B14Z_FAIL']} fail (PHP " . PHP_VERSION . ")\n";
	if ( $GLOBALS['B14Z_FAIL'] > 0 ) {
		echo "Failures:\n" . implode( "\n", $GLOBALS['B14Z_FAILS'] ) . "\n";
		exit( 1 );
	}
	exit( 0 );
}

/* ════════════════════════════════════════════════════════════════
 * Self-children: the disable-gate regression scenarios. The runtime
 * profile snapshot is a per-request static, so the "no profile"
 * environment must be a fresh process — this same file, no extra files.
 * ════════════════════════════════════════════════════════════════ */

if ( isset( $argv[1] ) && str_starts_with( (string) $argv[1], '--b14z-child=' ) ) {
	$scenario = substr( (string) $argv[1], strlen( '--b14z-child=' ) );

	// Load the REAL production files WITHOUT any profile constant: the
	// profile resolver must fail closed (WP_Error) exactly as in production.
	require $GLOBALS['B14Z_PROJECT'] . '/runtime/core/setup.php';
	require $GLOBALS['B14Z_PROJECT'] . '/runtime/core/audit-log.php';
	require $GLOBALS['B14Z_PROJECT'] . '/runtime/settings/class-settings-repository.php';
	require $GLOBALS['B14Z_PROJECT'] . '/runtime/ajax/ai.php';

	// Feature state through the REAL settings repository (default: enabled).
	$GLOBALS['wpdb'] = new B14Z_wpdb();
	if ( 'disabled' === $scenario ) {
		update_option( 'hal_frontend_dashboard_settings', array( 'features' => array( 'ai' => false ) ) );
	}
	HAL_Frontend_Dashboard_Settings_Repository::invalidate_cache();

	b14z_reset();
	b14z_seed( 41, 'pending', 0, gmdate( 'Y-m-d H:i:s', time() - 7200 ), null ); // stale pending
	b14z_seed( 42, 'processing', 1, null, gmdate( 'Y-m-d H:i:s', time() - 7200 ) ); // orphaned processing
	b14z_sweep();

	if ( 'disabled' === $scenario ) {
		$ok = 'failed' === $GLOBALS['B14Z_ROWS'][41]['status'] && 'feature_disabled' === $GLOBALS['B14Z_ROWS'][41]['last_error']
			&& 'failed' === $GLOBALS['B14Z_ROWS'][42]['status'] && 'feature_disabled' === $GLOBALS['B14Z_ROWS'][42]['last_error']
			&& 0 === count( b14z_zombie_sqls() )
			&& 0 === count( array_filter( $GLOBALS['wpdb']->queries, static fn( $q ) => false !== strpos( (string) $q, 'attempt_count <' ) ) )
			&& 0 === count( array_filter( $GLOBALS['wpdb']->queries, static fn( $q ) => false !== strpos( (string) $q, 'processing_deadline_exceeded' ) ) )
			&& 0 === count( $GLOBALS['B14Z_SCHEDULED'] );
		echo $ok ? "B14Z-CHILD disabled OK\n" : "B14Z-CHILD disabled BROKEN\n";
		exit( $ok ? 0 : 1 );
	}

	if ( 'enabled-noprofile' === $scenario ) {
		$ok = 'pending' === $GLOBALS['B14Z_ROWS'][41]['status'] && 'processing' === $GLOBALS['B14Z_ROWS'][42]['status']
			&& 0 === count( $GLOBALS['B14Z_AUDIT'] )
			&& 0 === count( $GLOBALS['B14Z_SCHEDULED'] )
			&& 0 === count( array_filter( $GLOBALS['wpdb']->queries, static fn( $q ) => false !== strpos( (string) $q, "SET status" ) ) );
		echo $ok ? "B14Z-CHILD enabled-noprofile OK\n" : "B14Z-CHILD enabled-noprofile BROKEN\n";
		exit( $ok ? 0 : 1 );
	}

	echo "B14Z-CHILD unknown scenario\n";
	exit( 1 );
}

/* ════════════════════════════════════════════════════════════════
 * Load the REAL production files (valid fixed profile, audit enabled)
 * ════════════════════════════════════════════════════════════════ */

if ( ! defined( 'HOSSAM_AI_RUNTIME_PROFILE' ) ) {
	define( 'HOSSAM_AI_RUNTIME_PROFILE', array(
		'per_minute' => 30, 'per_day' => 500, 'concurrent' => 2, 'max_attempts' => 3,
		'stale_pending' => 3600, 'processing_deadline' => 240,
		'wp_ai_client_timeout' => 60, 'direct_key_timeout' => 90,
	) );
}

require $GLOBALS['B14Z_PROJECT'] . '/runtime/core/setup.php';
require $GLOBALS['B14Z_PROJECT'] . '/runtime/core/audit-log.php';
require $GLOBALS['B14Z_PROJECT'] . '/runtime/ajax/ai.php';

$sweep_registered = false;
foreach ( $GLOBALS['B14Z_HOOKS']['action']['hossam_ai_sweep_jobs_event'] ?? array() as $entry ) {
	if ( $entry['cb'] instanceof Closure ) {
		$sweep_registered = true;
	}
}
b14z_check( 'T7-LOAD-real-callback', $sweep_registered,
	'real ai.php registered the real hossam_ai_sweep_jobs_event callback',
	'the real sweep callback was not registered by ai.php' );

$stale_utc  = gmdate( 'Y-m-d H:i:s', time() - 7200 );
$future_utc = gmdate( 'Y-m-d H:i:s', time() + 3600 );
$fresh_utc  = gmdate( 'Y-m-d H:i:s', time() - 10 );

/* ── Z1: exhausted pending (H2 §5-1) ───────────────────────────── */
b14z_reset();
b14z_seed( 11, 'pending', 3, $stale_utc, null ); // attempt_count == max_attempts
b14z_sweep();
$z11 = $GLOBALS['B14Z_ROWS'][11];
$z11_utc_ok = 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $z11['updated_at'] )
	&& abs( time() - (int) strtotime( (string) $z11['updated_at'] ) ) <= 5;
b14z_check( 'T7-Z1-exhausted-failed',
	'failed' === $z11['status'] && 'attempts_exhausted' === $z11['last_error'] && null === $z11['locked_at'] && $z11_utc_ok,
	'exhausted pending flips to failed with attempts_exhausted, locked_at=NULL and a UTC updated_at',
	'row state: ' . json_encode( $z11 ) );
b14z_check( 'T7-Z1-via-conditional-choke',
	1 === count( b14z_failed_updates_for( 11 ) ) && false !== strpos( (string) b14z_failed_updates_for( 11 )[0], "AND status IN ('pending', 'processing')" ),
	'the flip flows through the existing conditional choke hossam_ai_fail_job_if_outstanding (one UPDATE, atomic status guard)',
	'update log: ' . json_encode( b14z_failed_updates_for( 11 ) ) );
b14z_check( 'T7-Z1-no-retry',
	0 === count( $GLOBALS['B14Z_SCHEDULED'] ),
	'exhausted pending is never rescheduled (no hossam_ai_process_job_event retry)',
	'scheduled: ' . json_encode( array_keys( $GLOBALS['B14Z_SCHEDULED'] ) ) );
b14z_check( 'T7-Z1-audit-one',
	1 === b14z_audit_count( 11, 'attempts_exhausted' ),
	'exactly one real ai_fail audit row with object_type=ai_job and meta.reason=attempts_exhausted',
	'audit: ' . json_encode( $GLOBALS['B14Z_AUDIT'] ) );

/* ── Z2: orphaned NULL pending (H2 §5-2) ───────────────────────── */
b14z_reset();
b14z_seed( 12, 'pending', 0, null, null );
b14z_sweep();
$z12 = $GLOBALS['B14Z_ROWS'][12];
b14z_check( 'T7-Z2-orphaned-failed',
	'failed' === $z12['status'] && 'orphaned_pending' === $z12['last_error'] && null === $z12['locked_at'] && 1 === b14z_audit_count( 12, 'orphaned_pending' ),
	'NULL next_run_at pending (schema-1.1.1 legacy) flips with orphaned_pending through the real choke and audit',
	'row state: ' . json_encode( $z12 ) . ' audit: ' . json_encode( $GLOBALS['B14Z_AUDIT'] ) );

/* ── MIXED: exhausted + NULL — attempts precede (H2 §5-2) ──────── */
b14z_reset();
b14z_seed( 13, 'pending', 3, null, null );
b14z_sweep();
b14z_check( 'T7-MIXED-attempts-precede',
	'failed' === $GLOBALS['B14Z_ROWS'][13]['status'] && 'attempts_exhausted' === $GLOBALS['B14Z_ROWS'][13]['last_error'],
	'a row that is both exhausted and NULL-orphaned resolves attempts_exhausted (attempts precede at the ternary)',
	'row state: ' . json_encode( $GLOBALS['B14Z_ROWS'][13] ) );

/* ── VALID-STALE: healthy pending goes through (ب) only (H2 §5-4) ─ */
b14z_reset();
b14z_seed( 14, 'pending', 1, $stale_utc, null );
b14z_sweep();
$z14 = $GLOBALS['B14Z_ROWS'][14];
b14z_check( 'T7-VALID-STALE-branch-b-only',
	'pending' === $z14['status'] && 0 === count( b14z_failed_updates_for( 14 ) )
		&& isset( $GLOBALS['B14Z_SCHEDULED']['hossam_ai_process_job_event|14'] )
		&& null !== $z14['next_run_at'] && $z14['next_run_at'] > $stale_utc,
	'a healthy stale pending is handled by branch (ب) alone: retry scheduled, next_run_at pinned, no (ج) flip',
	'row state: ' . json_encode( $z14 ) . ' scheduled: ' . json_encode( array_keys( $GLOBALS['B14Z_SCHEDULED'] ) ) );

/* ── VALID-FUTURE: future pending untouched (H2 §5-4) ──────────── */
b14z_reset();
b14z_seed( 15, 'pending', 0, $future_utc, null );
b14z_sweep();
$z15 = $GLOBALS['B14Z_ROWS'][15];
$writes_15 = count( array_filter( $GLOBALS['wpdb']->queries, static fn( $q ) => false !== strpos( (string) $q, 'WHERE id = 15' ) ) );
b14z_check( 'T7-VALID-FUTURE-untouched',
	'pending' === $z15['status'] && null === $z15['last_error'] && 0 === $writes_15 && 0 === count( $GLOBALS['B14Z_SCHEDULED'] ),
	'a pending with a future next_run_at is touched by no branch (zero UPDATE, zero schedule)',
	'row state: ' . json_encode( $z15 ) . ' writes=' . $writes_15 );

/* ── PROCESSING: stuck vs fresh (H2 §5-3) ──────────────────────── */
b14z_reset();
b14z_seed( 16, 'processing', 1, null, gmdate( 'Y-m-d H:i:s', time() - 7200 ) ); // past deadline
b14z_seed( 17, 'processing', 1, null, $fresh_utc ); // fresh
b14z_sweep();
$stuck_updates  = b14z_failed_updates_for( 16 );
$fresh_updates  = b14z_failed_updates_for( 17 );
b14z_check( 'T7-PROCESSING-STUCK-branch-a-only',
	1 === count( $stuck_updates ) && false === strpos( (string) $stuck_updates[0], 'AND status IN' )
		&& 'failed' === $GLOBALS['B14Z_ROWS'][16]['status'] && 'processing_deadline_exceeded' === $GLOBALS['B14Z_ROWS'][16]['last_error'],
	'the stuck processing row is touched by branch (أ) alone with processing_deadline_exceeded (unconditional choke)',
	'updates: ' . json_encode( $stuck_updates ) . ' row: ' . json_encode( $GLOBALS['B14Z_ROWS'][16] ) );
b14z_check( 'T7-PROCESSING-FRESH-untouched',
	0 === count( $fresh_updates ) && 'processing' === $GLOBALS['B14Z_ROWS'][17]['status'],
	'a fresh processing row is touched by no branch (zero UPDATE)',
	'fresh updates: ' . json_encode( $fresh_updates ) );

/* ── TERMINAL: completed/failed untouched (H2 §5-5) ────────────── */
b14z_reset();
b14z_seed( 18, 'completed', 2, null, null );
b14z_seed( 19, 'failed', 2, null, null );
b14z_sweep();
$terminal_writes = count( array_filter( $GLOBALS['wpdb']->queries, static fn( $q ) => false !== strpos( (string) $q, 'SET status' ) ) );
b14z_check( 'T7-TERMINAL-untouched',
	'completed' === $GLOBALS['B14Z_ROWS'][18]['status'] && 'failed' === $GLOBALS['B14Z_ROWS'][19]['status']
		&& 0 === $terminal_writes && 0 === count( $GLOBALS['B14Z_AUDIT'] ),
	'terminal rows are never rewritten and never audited (retention terminal-only integration: (ج) produces failed only)',
	'writes=' . $terminal_writes . ' audit=' . json_encode( $GLOBALS['B14Z_AUDIT'] ) );

/* ── IDEMPOTENT: second sweep disposes nothing (H2 §5-6) ───────── */
b14z_reset();
b14z_seed( 20, 'pending', 3, $stale_utc, null );
b14z_sweep();
$first_run_updates = count( $GLOBALS['wpdb']->queries );
$first_run_audits  = count( $GLOBALS['B14Z_AUDIT'] );
$queries_after_first = $GLOBALS['wpdb']->queries;
b14z_sweep(); // second run: the row is terminal now
$new_updates_second = count( array_filter(
	array_diff( $GLOBALS['wpdb']->queries, $queries_after_first ),
	static fn( $q ) => false !== strpos( (string) $q, 'SET status' )
) );
b14z_check( 'T7-IDEMPOTENT-second-run',
	1 === count( b14z_failed_updates_for( 20 ) ) && 0 === $new_updates_second
		&& count( $GLOBALS['B14Z_AUDIT'] ) === $first_run_audits,
	'a second sweep after the first issues zero new UPDATEs and zero new ai_fail events (rows_affected guard holds)',
	"second-run new status writes={$new_updates_second} audits=" . count( $GLOBALS['B14Z_AUDIT'] ) );

/* ── THREE CONSECUTIVE RUNS over a batch larger than LIMIT (H2 §5-6) ── */
b14z_reset();
for ( $i = 100; $i < 125; $i++ ) { // 25 exhausted zombies > LIMIT 20
	b14z_seed( $i, 'pending', 3, $stale_utc, null );
}
$per_run = array();
for ( $run = 1; $run <= 3; $run++ ) {
	$before = 0;
	foreach ( $GLOBALS['B14Z_ROWS'] as $r ) {
		if ( 'failed' === $r['status'] ) {
			$before++;
		}
	}
	b14z_sweep();
	$after = 0;
	foreach ( $GLOBALS['B14Z_ROWS'] as $r ) {
		if ( 'failed' === $r['status'] ) {
			$after++;
		}
	}
	$per_run[] = $after - $before;
}
$zombie_sql = b14z_zombie_sqls();
$limit_seen = '';
if ( count( $zombie_sql ) > 0 && 1 === preg_match( '/LIMIT (\d+)\s*$/', (string) $zombie_sql[0], $lm ) ) {
	$limit_seen = (string) $lm[1];
}
b14z_check( 'T7-BATCH-LIMIT-20-per-run',
	array( 20, 5, 0 ) === $per_run && count( $zombie_sql ) > 0 && '20' === $limit_seen,
	'three consecutive sweeps over 25 zombies dispose exactly 20/5/0 — the LIMIT 20 is pinned in the captured SQL',
	'per-run=' . json_encode( $per_run ) . ' limit=' . ( $limit_seen ?: 'none' ) );

/* ── AUDIT one-per-flip invariant (H2 §5-7) ────────────────────── */
b14z_reset();
b14z_seed( 21, 'pending', 3, $stale_utc, null );
b14z_seed( 22, 'pending', 0, null, null );
b14z_sweep();
$flips = 0;
foreach ( $GLOBALS['B14Z_ROWS'] as $r ) {
	if ( in_array( $r['id'], array( 21, 22 ), true ) && 'failed' === $r['status'] ) {
		$flips++;
	}
}
b14z_check( 'T7-AUDIT-one-per-flip',
	2 === $flips && 2 === count( $GLOBALS['B14Z_AUDIT'] )
		&& 1 === b14z_audit_count( 21, 'attempts_exhausted' ) && 1 === b14z_audit_count( 22, 'orphaned_pending' ),
	'every real flip emitted exactly one ai_fail audit row (object_type=ai_job, matching meta.reason) — flows from the choke, not the branch',
	'flips=' . $flips . ' audit=' . json_encode( $GLOBALS['B14Z_AUDIT'] ) );

/* ── RACE: concurrent completion wins (H2 §3-ب / §5 race pattern) ─ */
b14z_reset();
b14z_seed( 30, 'pending', 3, $stale_utc, null );
$GLOBALS['B14Z_RACE_ROW'] = 30; // completion commits between the (ج) SELECT and the UPDATE
b14z_sweep();
$GLOBALS['B14Z_RACE_ROW'] = 0;
$race_updates = b14z_failed_updates_for( 30 );
b14z_check( 'T7-RACE-completion-wins',
	1 === count( $race_updates ) && false !== strpos( (string) $race_updates[0], 'AND status IN' )
		&& 'completed' === $GLOBALS['B14Z_ROWS'][30]['status']
		&& 0 === count( $GLOBALS['B14Z_AUDIT'] ),
	'interleaving: the conditional UPDATE is issued but matches zero rows — the completed row is never flipped and nothing is audited',
	'updates: ' . json_encode( $race_updates ) . ' row: ' . json_encode( $GLOBALS['B14Z_ROWS'][30] ) . ' audit: ' . json_encode( $GLOBALS['B14Z_AUDIT'] ) );

/* ── DISABLED MODE: self-children (zero regression, branch 1 untouched) ── */
// ESC-5 — تحصين وقائي: أعلام الأبناء عبر lib-hal-php-flags (hal_php_argv) —
// يسقط -d extension=sodium حين يكون static في البيئة (لا تحذير already-loaded)
// ويحتفظ به حين يلزم — fail-safe لنمط الlib الأصلي.
require __DIR__ . '/lib-hal-php-flags.php';
foreach ( array( 'disabled', 'enabled-noprofile' ) as $scenario ) {
	$php     = PHP_BINARY;
	$command = hal_php_argv( $php, array( __FILE__, '--b14z-child=' . $scenario ), array( 'sodium' ) );
	$descriptors = array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
	$proc = proc_open( $command, $descriptors, $pipes );
	$stdout = '';
	$code   = 1;
	if ( is_resource( $proc ) ) {
		fclose( $pipes[0] );
		$stdout = (string) stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		stream_get_contents( $pipes[2] );
		fclose( $pipes[2] );
		$code = proc_close( $proc );
	}
	b14z_check( 'T7-DISABLED-' . $scenario,
		0 === $code && false !== strpos( $stdout, 'B14Z-CHILD ' . $scenario . ' OK' ),
		"disable-gate regression zero: child scenario {$scenario} holds the pre-existing branch-1 behavior exactly",
		"child {$scenario} exit={$code} stdout=" . trim( $stdout ) );
}

/* ── SCHEMA static audit: the delta is the one block, nothing else ── */
$ai_src = (string) file_get_contents( $GLOBALS['B14Z_PROJECT'] . '/runtime/ajax/ai.php' );
$tables_src = (string) file_get_contents( $GLOBALS['B14Z_PROJECT'] . '/runtime/core/tables.php' );
$audit_src = (string) file_get_contents( $GLOBALS['B14Z_PROJECT'] . '/runtime/core/audit-log.php' );
b14z_check( 'T7-STATIC-single-block',
	1 === substr_count( $ai_src, 'attempt_count >= %d OR next_run_at IS NULL' )
		&& 1 === substr_count( $ai_src, "'attempts_exhausted'" )
		&& 1 === substr_count( $ai_src, "'orphaned_pending'" )
		&& false !== strpos( $ai_src, 'V110-H2' ) && false !== strpos( $ai_src, 'فشل نهائي بلا أي retry' ),
	'the (ج) delta is exactly one block in the sweep callback (one SQL, one reason ternary) with its V110-H2 docblock',
	'block counts deviate: zombie_sql=' . substr_count( $ai_src, 'attempt_count >= %d OR next_run_at IS NULL' ) );
b14z_check( 'T7-STATIC-schema-clean',
	0 === substr_count( $ai_src, 'CREATE TABLE' ) && 0 === substr_count( $ai_src, 'ALTER TABLE' )
		&& 0 === substr_count( $ai_src, "'error_message'" ) && 0 === substr_count( $ai_src, 'error_message =' )
		&& 0 === substr_count( $tables_src, 'attempts_exhausted' ) && 0 === substr_count( $tables_src, 'orphaned_pending' ),
	'zero CREATE/ALTER and zero error_message column writes in ai.php (the only matches are the WP_Error get_error_message() method); tables.php carries zero zombie tokens',
	'schema surface polluted' );
b14z_check( 'T7-STATIC-retention-contract',
	false !== strpos( $audit_src, 'عقد التكامل مع الزومبي' ) && false !== strpos( $audit_src, 'terminal-only' )
		&& false !== strpos( $audit_src, "AND status IN ('completed','failed')" )
		&& false !== strpos( $ai_src, "WHERE id = %d AND status IN ('pending', 'processing')" ),
	'the zombie-retention integration contract is documented on both sides and terminal production stays with the state machine',
	'integration contract docblocks drifted' );

/* ════════════════════════════════════════════════════════════════
 * Summary
 * ════════════════════════════════════════════════════════════════ */

b14z_result_line();
