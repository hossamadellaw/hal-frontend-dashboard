<?php
/**
 * HAL Frontend Dashboard — Batch 14 harness T4: performance & indexes closure
 * (H5-PERF-INDEXES-CLOSURE.md §4 / G5-PERFORMANCE-POSITION.md §7-1..13 portable).
 *
 * The portable T4 line (batch13-performance-test.php) is a tagged historical
 * asset (H5 §4-5); this file is the unified v1.1.0 performance harness and
 * requires nothing from it.
 *
 * Local, isolated harness: no live WordPress, no network, no live database,
 * no browser. Loads the REAL runtime/core/setup.php, runtime/core/tables.php,
 * runtime/core/audit-log.php, runtime/settings/class-settings-repository.php,
 * runtime/ajax/ai.php and the REAL runtime/infrastructure/
 * class-template-controller.php, then runs the REAL init callbacks, the REAL
 * retention sweep engine, the REAL AI sweep/quota SQL through the REAL
 * callbacks. Only the WordPress boundary (hooks, options + autoload, cron,
 * wpdb, dbDelta, headers) is stubbed; no project logic is reimplemented in
 * the harness. The dbDelta stub parses the CREATE TABLE text into a virtual
 * table model (what dbDelta applies to a live table) and the wpdb stub
 * executes the DELETE conditions captured from the production prepare() —
 * DB boundaries, not logic reimplementations.
 *
 * Coverage (G5 §7-1..13 portable + the v1.1.0 H5 §4 items):
 *
 *   MIGRATION   site recorded at 1.0.1/1.0.1/1.1.1 → ONE init run records
 *               1.0.2/1.0.2/1.1.2 and the ready-check SHOW INDEX responses
 *               carry the six names (user_created, user_is_read,
 *               receiver_created, status_locked, status_next_run,
 *               user_created_ai); an injected ready-check failure leaves the
 *               recorded version untouched and the next init retries
 *               successfully; a settled site re-runs zero dbDelta.
 *   SCHEMA-NEG  zero column changes (exact documented column lists); no
 *               fourth (status, attempt_count, next_run_at) and no fifth
 *               (status, updated_at) index; dbDelta emits no DROP; index
 *               names outside the reserved-word list (lesson C3).
 *   ZOMBIE-SQL  the real (ج) sweep SQL queries with the `status` leading
 *               column — no new index; status_locked/status_next_run match
 *               the WHERE prefixes of sweep (أ)/(ب) literally.
 *   QUOTA-PFX   the real daily-count SQL prefix (user_id, created_at)
 *               matches user_created_ai literally (column order in CREATE).
 *   R1-CACHE    the twin template controllers are byte-identical; the real
 *               get_target_page_ids() static per-blog cache: WPML
 *               translations filter fires once per blog (not per call),
 *               per-blog isolation, and the is_target_query early exit for
 *               non-pages is unchanged.
 *   R1-NEG      zero change in both site-provisioner copies — :147 keeps the
 *               `false` autoload argument; zero schema/DB surface in the
 *               template controller.
 *   R3-CAP      min() cap with the hossam_fin_summary_max_pages filter
 *               (default 20), the revenue_capped flag bound into the KPI
 *               payload only, the docblock declares the deviation, and the
 *               finance key/auth block (:123-134 locality) is untouched with
 *               zero batch14 tokens.
 *   DELETE-QUAD the retention engine is the only DELETE in the production
 *               tree: four definitions 90/30/90/180 with batch 500,
 *               terminal-only for ai_jobs on updated_at, ORDER BY id LIMIT
 *               batch, one daily guarded event never unscheduled; behavior:
 *               6000 due rows → 5000 in one capped run, terminal rows and
 *               fresh rows survive, idempotent continuation, per-table
 *               failure isolation, enabled=false stops the audit share only.
 *   R5-AUTOLOAD legacy site: init migrates the settings row autoload to on
 *               through wp_set_option_autoload_values (guarded flag, default
 *               autoload), and a later real save() keeps it 'on' (third
 *               check); a fresh install creates the row autoloaded through
 *               the real save(); the new flag is written with the default
 *               autoload.
 *   R6-PRIME    wp_doing_cron() → the real setup.php init callback exits
 *               before any hossam_wpml_all_paths call (zero calls, zero
 *               headers, zero queries); the front request keeps the three
 *               no-cache headers literally and still consults WPML paths.
 *   PKG-NEG     zero touch of appointments.php (no batch14 tokens), zero new
 *               tables (exactly the four owned), zero new AJAX/REST points
 *               in the v1.1.0 core delta files, zero composer artifacts; the
 *               legacy "deferred indexes absent" clause is absent from this
 *               file (inverted positively — the three indexes are asserted
 *               present) and nothing from the tagged legacy asset is
 *               required.
 *
 * Usage:  php -d extension=sodium tests/php/batch14-performance-test.php  (exit 0 = all pass)
 *
 * Staging-only acceptance items are emitted as loud SKIP lines (never PASS)
 * and remain owner-delegated: T4-1 live MySQL 8/MariaDB migration + SHOW
 * INDEX + EXPLAIN (H5 §4-1), the WPML 7→1 live measurement (G5 §7-2) and
 * the dashboard-render query profit vs the v1.0.8 baseline (G5 §7-12).
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );
$GLOBALS['B14P_TMP'] = ( getenv( 'TMP' ) ?: sys_get_temp_dir() );
ini_set( 'error_log', $GLOBALS['B14P_TMP'] . '/b14-perf-error.log' );

if ( PHP_SAPI !== 'cli' ) {
	echo "This harness must run under CLI PHP.\n";
	exit( 1 );
}

$GLOBALS['B14P_PROJECT'] = dirname( __DIR__, 2 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $GLOBALS['B14P_TMP'] . '/hal-b14p-fake-wp-root/' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

/* Harness WP-boundary upgrade.php (temp root — outside the repository):
 * dbDelta parses the CREATE TABLE text into the virtual table model —
 * exactly the shape dbDelta leaves on a live table. */
$B14P_upgrade_dir = ABSPATH . 'wp-admin/includes';
if ( ! is_dir( $B14P_upgrade_dir ) ) {
	mkdir( $B14P_upgrade_dir, 0777, true );
}
if ( ! file_exists( $B14P_upgrade_dir . '/upgrade.php' ) ) {
	file_put_contents( $B14P_upgrade_dir . '/upgrade.php', '<?php
// Harness WP boundary (batch14-performance-test.php): dbDelta applies the
// CREATE TABLE text to the virtual table model.
function dbDelta( string $sql ): array {
	$GLOBALS["B14P_DBDELTA"][] = $sql;
	if ( preg_match( "/CREATE TABLE\s+(\S+)/", $sql, $t ) ) {
		$table   = trim( $t[1], "`" );
		$columns = array();
		$indexes = array( "PRIMARY" );
		foreach ( preg_split( "/\R/", $sql ) as $line ) {
			$line = trim( $line, " \t," );
			if ( preg_match( "/^KEY\s+(\w+)\s*\(/", $line, $k ) ) {
				$indexes[] = $k[1];
			} elseif ( preg_match( "/^(\w+)\s+(BIGINT|VARCHAR|TEXT|TINYINT|DATETIME|LONGTEXT)\b/", $line, $c ) ) {
				$columns[] = $c[1];
			}
		}
		$GLOBALS["B14P_TABLES"][ $table ] = array( "columns" => $columns, "indexes" => $indexes );
	}
	return array();
}
' );
}

/* ════════════════════════════════════════════════════════════════
 * WordPress boundary stubs (WordPress only — never project logic)
 * ════════════════════════════════════════════════════════════════ */

/* ── Hook registry (priority-ordered, dispatching) ── */
$GLOBALS['B14P_HOOKS']    = array();
$GLOBALS['B14P_HOOK_SEQ'] = 0;

function b14p_hook_register( string $type, string $hook, callable $cb, int $priority, int $args ): void {
	$GLOBALS['B14P_HOOKS'][ $type ][ $hook ][] = array(
		'p'   => $priority,
		'seq' => $GLOBALS['B14P_HOOK_SEQ']++,
		'cb'  => $cb,
		'a'   => $args,
	);
}
function add_action( string $hook, callable $cb, int $priority = 10, int $accepted_args = 1 ): void {
	b14p_hook_register( 'action', $hook, $cb, $priority, $accepted_args );
}
function add_filter( string $hook, callable $cb, int $priority = 10, int $accepted_args = 1 ): void {
	b14p_hook_register( 'filter', $hook, $cb, $priority, $accepted_args );
}
function remove_filter( string $hook, callable $cb, int $priority = 10 ): bool {
	$found = false;
	foreach ( (array) ( $GLOBALS['B14P_HOOKS']['filter'][ $hook ] ?? array() ) as $i => $entry ) {
		if ( $entry['cb'] === $cb && $entry['p'] === $priority ) {
			unset( $GLOBALS['B14P_HOOKS']['filter'][ $hook ][ $i ] );
			$found = true;
		}
	}
	return $found;
}
if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( string $hook, $callback = false ) {
		$entries = $GLOBALS['B14P_HOOKS']['filter'][ $hook ] ?? array();
		if ( false === $callback ) {
			return array() !== $entries;
		}
		foreach ( $entries as $entry ) {
			if ( $entry['cb'] === $callback ) {
				return true;
			}
		}
		return false;
	}
}
function b14p_hook_sorted( string $type, string $hook ): array {
	$entries = array_values( (array) ( $GLOBALS['B14P_HOOKS'][ $type ][ $hook ] ?? array() ) );
	usort( $entries, static fn( $x, $y ) => array( $x['p'], $x['seq'] ) <=> array( $y['p'], $y['seq'] ) );
	return $entries;
}
function apply_filters( string $hook, $value, ...$rest ) {
	foreach ( b14p_hook_sorted( 'filter', $hook ) as $entry ) {
		$call_args = array_merge( array( $value ), $rest );
		$value     = call_user_func_array( $entry['cb'], array_slice( $call_args, 0, max( 1, $entry['a'] ) ) );
	}
	return $value;
}
function do_action( string $hook, ...$args ): void {
	foreach ( b14p_hook_sorted( 'action', $hook ) as $entry ) {
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
if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID = 0;
		public string $post_type = '';
		public string $post_status = '';
		public function __construct( int $id, string $type, string $status ) {
			$this->ID          = $id;
			$this->post_type   = $type;
			$this->post_status = $status;
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
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text, $remove_breaks = false ): string {
		return trim( strip_tags( (string) $text ) );
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}

/* ── Users / session / blog / posts ── */
function get_current_user_id(): int {
	return (int) ( $GLOBALS['B14P_USER_ID'] ?? 0 );
}
if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( int $user_id ) {
		return $GLOBALS['B14P_USERS'][ $user_id ] ?? false;
	}
}
function get_current_blog_id(): int {
	return (int) ( $GLOBALS['B14P_BLOG'] ?? 1 );
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( int $id ) {
		return $GLOBALS['B14P_POSTS'][ $id ] ?? null;
	}
}
if ( ! function_exists( 'get_queried_object' ) ) {
	function get_queried_object() {
		return $GLOBALS['B14P_QUERIED'] ?? null;
	}
}

/* ── Options store (autoload-tracked, WP update_option adds-when-missing) ── */
$GLOBALS['B14P_OPTIONS']         = array();
$GLOBALS['B14P_AUTOLOAD']        = array(); // option => 'auto'|'on'|'off'|'yes'
$GLOBALS['B14P_OPTION_WRITES']   = array(); // ['op','option','value','autoload']
$GLOBALS['B14P_WP_SET_AUTOLOAD'] = array(); // ['options' => array]

function get_option( string $option, $default_value = false ) {
	return array_key_exists( $option, $GLOBALS['B14P_OPTIONS'] ) ? $GLOBALS['B14P_OPTIONS'][ $option ] : $default_value;
}
function update_option( string $option, $value, $autoload = null ): bool {
	if ( null !== $autoload ) {
		$GLOBALS['B14P_AUTOLOAD'][ $option ] = $autoload ? 'on' : 'off';
	}
	if ( ! array_key_exists( $option, $GLOBALS['B14P_OPTIONS'] ) ) {
		// WP boundary truth: update_option on a missing row adds it (with
		// the given autoload — here always the autoload-pinning true).
		$GLOBALS['B14P_OPTION_WRITES'][] = array( 'op' => 'add', 'option' => $option, 'value' => $value, 'autoload' => $autoload );
	} else {
		$GLOBALS['B14P_OPTION_WRITES'][] = array( 'op' => 'update', 'option' => $option, 'value' => $value, 'autoload' => $autoload );
	}
	$GLOBALS['B14P_OPTIONS'][ $option ] = $value;
	return true;
}
function add_option( string $option, $value, $autoload = null ): bool {
	if ( array_key_exists( $option, $GLOBALS['B14P_OPTIONS'] ) ) {
		return false;
	}
	$GLOBALS['B14P_OPTION_WRITES'][] = array( 'op' => 'add', 'option' => $option, 'value' => $value, 'autoload' => $autoload );
	if ( null !== $autoload ) {
		$GLOBALS['B14P_AUTOLOAD'][ $option ] = $autoload ? 'on' : 'off';
	} else {
		$GLOBALS['B14P_AUTOLOAD'][ $option ] = 'auto'; // kernel default: autoloaded
	}
	$GLOBALS['B14P_OPTIONS'][ $option ] = $value;
	return true;
}
function wp_set_option_autoload_values( array $options ): bool {
	$GLOBALS['B14P_WP_SET_AUTOLOAD'][] = array( 'options' => $options );
	foreach ( $options as $option => $autoload ) {
		$GLOBALS['B14P_AUTOLOAD'][ $option ] = $autoload ? 'on' : 'off';
	}
	return true;
}

/* ── Transients ── */
$GLOBALS['B14P_TRANSIENTS'] = array();
function get_transient( string $key ) {
	return $GLOBALS['B14P_TRANSIENTS'][ $key ] ?? false;
}
function set_transient( string $key, $value, int $ttl = 0 ): bool {
	$GLOBALS['B14P_TRANSIENTS'][ $key ] = $value;
	return true;
}
function delete_transient( string $key ): bool {
	unset( $GLOBALS['B14P_TRANSIENTS'][ $key ] );
	return true;
}

/* ── Cron boundary ── */
$GLOBALS['B14P_CRON_SCHEDULED'] = array();
$GLOBALS['B14P_CRON_NEXT']      = array(); // hook => ts
$GLOBALS['B14P_CRON_CLEARED']   = array();
function wp_next_scheduled( string $hook, array $args = array() ) {
	return isset( $GLOBALS['B14P_CRON_NEXT'][ $hook ] ) ? $GLOBALS['B14P_CRON_NEXT'][ $hook ] : false;
}
function wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = array() ): bool {
	$GLOBALS['B14P_CRON_SCHEDULED'][] = array( 'ts' => $timestamp, 'recurrence' => $recurrence, 'hook' => $hook );
	$GLOBALS['B14P_CRON_NEXT'][ $hook ] = $timestamp;
	return true;
}
function wp_schedule_single_event( int $timestamp, string $hook, array $args = array() ) {
	$GLOBALS['B14P_CRON_SCHEDULED'][] = array( 'ts' => $timestamp, 'recurrence' => '', 'hook' => $hook );
	$GLOBALS['B14P_CRON_NEXT'][ $hook ] = $timestamp;
	return true;
}
function wp_clear_scheduled_hook( string $hook ): void {
	$GLOBALS['B14P_CRON_CLEARED'][] = $hook;
	unset( $GLOBALS['B14P_CRON_NEXT'][ $hook ] );
}

/* ── Request context (R6′ boundary) ── */
$GLOBALS['B14P_DOING_CRON'] = false;
$GLOBALS['B14P_DOING_AJAX'] = false;
$GLOBALS['B14P_IS_ADMIN']   = false;
$GLOBALS['B14P_HEADERS']    = array();
$GLOBALS['B14P_WPML_CALLS'] = array();
function wp_doing_cron(): bool {
	return (bool) $GLOBALS['B14P_DOING_CRON'];
}
function wp_doing_ajax(): bool {
	return (bool) $GLOBALS['B14P_DOING_AJAX'];
}
function is_admin(): bool {
	return (bool) $GLOBALS['B14P_IS_ADMIN'];
}
function is_user_logged_in(): bool {
	return (bool) ( $GLOBALS['B14P_LOGGED_IN'] ?? false );
}
function current_user_can( string $cap, ...$rest ): bool {
	return (bool) ( $GLOBALS['B14P_CAPS'][ $cap ] ?? true );
}
/**
 * Harness WPML boundary — counts every consult. The production init
 * callback consumes it only on non-cron front requests. (header() and
 * headers_sent() are PHP internals in CLI: no-ops, not redeclarable —
 * the front-mode headers are asserted as the literal source lines and
 * the control flow through the WPML consult.)
 */
function hossam_wpml_all_paths( string $slug ): array {
	$GLOBALS['B14P_WPML_CALLS'][] = $slug;
	return array( '/ar/dashboard/', '/ar/login/' );
}

/* ════════════════════════════════════════════════════════════════
 * wpdb boundary — captures queries; executes the captured DELETE
 * conditions against synthetic rows (retention engine).
 * ════════════════════════════════════════════════════════════════ */

$GLOBALS['B14P_TABLES']          = array(); // table => ['columns','indexes']
$GLOBALS['B14P_SHOW_INDEX_SEEN'] = array(); // table => list of Key_name responses
$GLOBALS['B14P_DBDELTA']         = array(); // captured dbDelta SQL
$GLOBALS['B14P_ROWS']            = array(); // table => list of row arrays
$GLOBALS['B14P_FAIL_SHOW_INDEX'] = false;
$GLOBALS['B14P_FAIL_ONCE']       = false;

if ( ! class_exists( 'B14P_wpdb' ) ) {
	final class B14P_wpdb {
		public string $prefix       = 'wp_';
		public string $last_error   = '';
		public int $insert_id       = 0;
		public int $blogid          = 1;
		public int $rows_affected   = 0;
		public array $queries       = array();

		public function flush(): void {
			$this->last_error = '';
		}
		public function prepare( string $query, ...$args ): string {
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
		/** Execute the captured production DELETE conditions (DB boundary). */
		private function run_delete( string $sql ): int {
			if ( ! preg_match( '/DELETE FROM (\S+) WHERE/', $sql, $t ) ) {
				return 0;
			}
			$table = trim( $t[1], '`' );
			if ( ! preg_match( "/(\w+) < '([^']+)'/", $sql, $c ) ) {
				return 0;
			}
			$col      = $c[1];
			$cutoff   = $c[2];
			$terminal = false !== strpos( $sql, "AND status IN ('completed','failed')" );
			$batch    = preg_match( '/LIMIT (\d+)\s*$/', $sql, $b ) ? (int) $b[1] : 0;
			$due      = array();
			foreach ( $GLOBALS['B14P_ROWS'][ $table ] ?? array() as $idx => $row ) {
				if ( ! isset( $row[ $col ] ) || (string) $row[ $col ] >= $cutoff ) {
					continue;
				}
				if ( $terminal && ! in_array( $row['status'] ?? '', array( 'completed', 'failed' ), true ) ) {
					continue;
				}
				$due[ $idx ] = (int) $row['id'];
			}
			asort( $due ); // ORDER BY id
			$due = array_slice( $due, 0, $batch > 0 ? $batch : count( $due ), true );
			foreach ( array_keys( $due ) as $idx ) {
				unset( $GLOBALS['B14P_ROWS'][ $table ][ $idx ] );
			}
			return count( $due );
		}
		public function query( string $sql ) {
			$this->queries[] = $sql;
			$this->last_error = '';
			if ( $GLOBALS['B14P_FAIL_ONCE'] ) {
				$GLOBALS['B14P_FAIL_ONCE'] = false;
				$this->last_error = 'B14P fixture db failure';
				$this->rows_affected = 0;
				return false;
			}
			if ( 0 === strpos( $sql, 'DELETE' ) ) {
				$deleted = $this->run_delete( $sql );
				$this->rows_affected = $deleted;
				return $deleted;
			}
			$this->rows_affected = 0;
			return 0;
		}
		public function get_var( string $query = null ) {
			$q = (string) $query;
			$this->queries[] = $q;
			if ( $GLOBALS['B14P_FAIL_SHOW_INDEX'] && false !== strpos( $q, 'SHOW INDEX' ) ) {
				$this->last_error = 'B14P fixture db failure';
				return null;
			}
			if ( false !== strpos( $q, 'SHOW TABLES' ) ) {
				$pos = strpos( $q, 'LIKE ' );
				if ( false === $pos ) {
					return '';
				}
				$table = str_replace( '\\', '', trim( substr( $q, $pos + 5 ), "' " ) );
				return isset( $GLOBALS['B14P_TABLES'][ $table ] ) ? $table : '';
			}
			if ( false !== strpos( $q, 'GET_LOCK' ) ) {
				return 1;
			}
			if ( false !== strpos( $q, 'RELEASE_LOCK' ) ) {
				return 1;
			}
			if ( false !== strpos( $q, 'COUNT(*)' ) ) {
				return (int) ( $GLOBALS['B14P_COUNT'] ?? 0 );
			}
			return null;
		}
		public function get_col( string $query = null, $offset = 0 ) {
			$q = (string) $query;
			$this->queries[] = $q;
			if ( $GLOBALS['B14P_FAIL_SHOW_INDEX'] && false !== strpos( $q, 'SHOW INDEX' ) ) {
				$this->last_error = 'B14P fixture db failure';
				return array();
			}
			if ( false !== strpos( $q, 'SHOW COLUMNS FROM' ) ) {
				if ( preg_match( '/SHOW COLUMNS FROM `([^`]+)`/', $q, $m ) && isset( $GLOBALS['B14P_TABLES'][ $m[1] ] ) ) {
					return $GLOBALS['B14P_TABLES'][ $m[1] ]['columns'];
				}
				return array();
			}
			if ( false !== strpos( $q, 'SHOW INDEX FROM' ) ) {
				if ( preg_match( '/SHOW INDEX FROM `([^`]+)`/', $q, $m ) ) {
					$table = $m[1];
					$names = $GLOBALS['B14P_TABLES'][ $table ]['indexes'] ?? array();
					$GLOBALS['B14P_SHOW_INDEX_SEEN'][ $table ] = array_merge(
						(array) ( $GLOBALS['B14P_SHOW_INDEX_SEEN'][ $table ] ?? array() ),
						array( $names )
					);
					return $names; // offset 2 = Key_name; the model stores names only
				}
				return array();
			}
			return array();
		}
		public function get_results( string $query = null, $output = null ) {
			$this->queries[] = (string) $query;
			return array();
		}
		public function get_row( string $query = null, $output = null ) {
			$this->queries[] = (string) $query;
			return null;
		}
		public function update( string $table, array $data, array $where, $format1 = null, $format2 = null ) {
			$this->queries[] = 'UPDATE ' . $table . ' ' . json_encode( array( $data, $where ) );
			return 1;
		}
		public function insert( string $table, array $data, $format = null ) {
			$this->queries[] = 'INSERT INTO ' . $table . ' ' . json_encode( $data );
			$this->insert_id = (int) ( $GLOBALS['B14P_INSERT_ID'] ?? 77 );
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
$GLOBALS['B14P_PASS']  = 0;
$GLOBALS['B14P_FAIL']  = 0;
$GLOBALS['B14P_FAILS'] = array();
$GLOBALS['B14P_SKIP']  = 0;

function b14p_check( string $id, bool $ok, string $pass, string $fail ): void {
	if ( $ok ) {
		$GLOBALS['B14P_PASS']++;
		echo "PASS [{$id}] {$pass}\n";
	} else {
		$GLOBALS['B14P_FAIL']++;
		$GLOBALS['B14P_FAILS'][] = "{$id}: {$fail}";
		echo "FAIL [{$id}] {$fail}\n";
	}
}

/** Loud skip for staging-only acceptance items — never a PASS. */
function b14p_skip( string $id, string $reason ): void {
	$GLOBALS['B14P_SKIP']++;
	echo "SKIP [{$id}] {$reason}\n";
}

function b14p_result_line(): void {
	echo "B14-T4 RESULT: {$GLOBALS['B14P_PASS']} pass, {$GLOBALS['B14P_SKIP']} skipped (staging-only — owner-delegated, never passed), {$GLOBALS['B14P_FAIL']} fail (PHP " . PHP_VERSION . ")\n";
	if ( $GLOBALS['B14P_FAIL'] > 0 ) {
		echo "Failures:\n" . implode( "\n", $GLOBALS['B14P_FAILS'] ) . "\n";
		exit( 1 );
	}
	exit( 0 );
}

function b14p_reset_db(): void {
	$GLOBALS['wpdb'] = new B14P_wpdb();
	$GLOBALS['B14P_FAIL_ONCE'] = false;
}

function b14p_reset_cron(): void {
	$GLOBALS['B14P_CRON_SCHEDULED'] = array();
	$GLOBALS['B14P_CRON_NEXT']      = array();
	$GLOBALS['B14P_CRON_CLEARED']   = array();
}

/* ════════════════════════════════════════════════════════════════
 * Load the REAL production files (release context + valid profile)
 * ════════════════════════════════════════════════════════════════ */

if ( ! defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT' ) ) {
	define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT', $GLOBALS['B14P_PROJECT'] . '/runtime/' );
	define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_DIR', $GLOBALS['B14P_PROJECT'] . '/runtime/' );
	define( 'HAL_FRONTEND_DASHBOARD_RELEASE_ID', '1.1.0+' . str_repeat( 't4', 16 ) );
	define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION', '1.1.0' );
	define(
		'HAL_FRONTEND_DASHBOARD_RUNTIME_URL',
		'https://example.test/wp-content/mu-plugins/hal-frontend-dashboard/releases/' . HAL_FRONTEND_DASHBOARD_RELEASE_ID . '/'
	);
}
if ( ! defined( 'HOSSAM_AI_RUNTIME_PROFILE' ) ) {
	define( 'HOSSAM_AI_RUNTIME_PROFILE', array(
		'per_minute' => 30, 'per_day' => 500, 'concurrent' => 2, 'max_attempts' => 3,
		'stale_pending' => 3600, 'processing_deadline' => 240,
		'wp_ai_client_timeout' => 60, 'direct_key_timeout' => 90,
	) );
}

b14p_reset_db();
require $GLOBALS['B14P_PROJECT'] . '/runtime/core/setup.php';
require $GLOBALS['B14P_PROJECT'] . '/runtime/core/audit-log.php';
require $GLOBALS['B14P_PROJECT'] . '/runtime/core/tables.php';
require $GLOBALS['B14P_PROJECT'] . '/runtime/settings/class-settings-repository.php';
require $GLOBALS['B14P_PROJECT'] . '/runtime/ajax/ai.php';
require $GLOBALS['B14P_PROJECT'] . '/runtime/infrastructure/class-template-controller.php';

b14p_check( 'T4-LOAD-real-units',
	function_exists( 'hossam_dashboard_table_schemas' ) && function_exists( 'hossam_dashboard_ensure_table' )
		&& function_exists( 'hossam_audit_log_retention_definitions' ) && function_exists( 'hossam_ai_get_sweep_limits' )
		&& class_exists( 'HAL_Frontend_Dashboard_Settings_Repository' ) && class_exists( 'HAL_Frontend_Dashboard_Template_Controller' )
		&& count( $GLOBALS['B14P_HOOKS']['action']['init'] ?? array() ) >= 6,
	'the real production units are loaded and their init callbacks registered (tables ×3, audit, settings, setup paths, ai schedule)',
	'the real production units did not load' );

$init_closures = array_map( static fn( $e ) => $e['cb'], $GLOBALS['B14P_HOOKS']['action']['init'] ?? array() );
function b14p_run_init(): void {
	do_action( 'init' );
}

/* ════════════════════════════════════════════════════════════════
 * MIGRATION (H5 §4-1 local proxy / G5 §7-9): 1.0.1/1.0.1/1.1.1 → one init
 * ════════════════════════════════════════════════════════════════ */

function b14p_seed_legacy_tables(): void {
	$GLOBALS['B14P_TABLES'] = array(
		'wp_hossam_notifications' => array(
			'columns' => array( 'id', 'user_id', 'type', 'message', 'is_read', 'created_at' ),
			'indexes' => array( 'PRIMARY', 'user_id', 'is_read' ),
		),
		'wp_hossam_messages'      => array(
			'columns' => array( 'id', 'sender_id', 'receiver_id', 'message', 'read_at', 'created_at' ),
			'indexes' => array( 'PRIMARY', 'sender_id', 'receiver_id', 'read_at' ),
		),
		'wp_hossam_ai_jobs'       => array(
			'columns' => array( 'id', 'user_id', 'post_id', 'job_type', 'input_data', 'status', 'result', 'error_message', 'strategy_id', 'attempt_count', 'locked_at', 'next_run_at', 'last_error', 'created_at', 'updated_at' ),
			'indexes' => array( 'PRIMARY', 'user_id', 'post_id', 'status' ),
		),
		'wp_hossam_audit_log'     => array(
			'columns' => array( 'id', 'user_id', 'user_login', 'event_action', 'object_type', 'object_id', 'object_title', 'meta', 'ip', 'created_at' ),
			'indexes' => array( 'PRIMARY', 'created_at', 'user_id', 'object_id', 'event_action' ),
		),
	);
	$GLOBALS['B14P_SHOW_INDEX_SEEN'] = array();
	$GLOBALS['B14P_DBDELTA']         = array();
	$GLOBALS['B14P_OPTIONS']['hossam_notifications_db_version'] = '1.0.1';
	$GLOBALS['B14P_OPTIONS']['hossam_messages_db_version']      = '1.0.1';
	$GLOBALS['B14P_OPTIONS']['hossam_ai_jobs_db_version']       = '1.1.1';
	unset( $GLOBALS['B14P_OPTIONS']['hossam_audit_log_db_version'] );
}

b14p_reset_db();
b14p_reset_cron();
b14p_seed_legacy_tables();
b14p_run_init(); // ONE init run over the legacy site

b14p_check( 'T4-MIG-versions-recorded',
	'1.0.2' === get_option( 'hossam_notifications_db_version' ) && '1.0.2' === get_option( 'hossam_messages_db_version' )
		&& '1.1.2' === get_option( 'hossam_ai_jobs_db_version' ) && '1.0.0' === get_option( 'hossam_audit_log_db_version' ),
	'one init run migrates the legacy site and records 1.0.2/1.0.2/1.1.2 (audit 1.0.0)',
	'recorded: notifications=' . get_option( 'hossam_notifications_db_version' ) . ' messages=' . get_option( 'hossam_messages_db_version' )
		. ' ai_jobs=' . get_option( 'hossam_ai_jobs_db_version' ) );

$six = array(
	'wp_hossam_notifications' => array( 'user_created', 'user_is_read' ),
	'wp_hossam_messages'      => array( 'receiver_created' ),
	'wp_hossam_ai_jobs'       => array( 'status_locked', 'status_next_run', 'user_created_ai' ),
);
$six_missing = array();
foreach ( $six as $table => $names ) {
	$seen = array();
	foreach ( (array) ( $GLOBALS['B14P_SHOW_INDEX_SEEN'][ $table ] ?? array() ) as $response ) {
		$seen = array_merge( $seen, (array) $response );
	}
	foreach ( $names as $name ) {
		if ( ! in_array( $name, $seen, true ) ) {
			$six_missing[] = $table . '.' . $name;
		}
	}
}
b14p_check( 'T4-MIG-show-index-six-names',
	array() === $six_missing,
	'the ready-check SHOW INDEX responses during migration carry all six index names',
	'missing from SHOW INDEX responses: ' . json_encode( $six_missing ) );

$dbdelta_no_drop = 0 === count( array_filter( $GLOBALS['B14P_DBDELTA'], static fn( $sql ) => false !== stripos( (string) $sql, 'DROP' ) ) );
b14p_check( 'T4-MIG-dbdelta-three-no-drop',
	count( $GLOBALS['B14P_DBDELTA'] ) >= 3 && $dbdelta_no_drop,
	'dbDelta ran the schema upgrades and issued no DROP (query log)',
	'dbdelta=' . count( $GLOBALS['B14P_DBDELTA'] ) . ' no_drop=' . var_export( $dbdelta_no_drop, true ) );

$dbdelta_before = count( $GLOBALS['B14P_DBDELTA'] );
b14p_run_init(); // settled site: the guard short-circuits
b14p_check( 'T4-MIG-idempotent',
	count( $GLOBALS['B14P_DBDELTA'] ) === $dbdelta_before
		&& '1.1.2' === get_option( 'hossam_ai_jobs_db_version' ),
	'a settled site re-runs zero dbDelta (recorded >= target short-circuit)',
	'dbdelta grew from ' . $dbdelta_before . ' to ' . count( $GLOBALS['B14P_DBDELTA'] ) );

/* ── Ready-check failure injection: version kept, init retries ── */
b14p_seed_legacy_tables();
$GLOBALS['B14P_FAIL_SHOW_INDEX'] = true;
b14p_run_init();
$kept = '1.0.1' === get_option( 'hossam_notifications_db_version' )
	&& '1.0.1' === get_option( 'hossam_messages_db_version' )
	&& '1.1.1' === get_option( 'hossam_ai_jobs_db_version' );
$GLOBALS['B14P_FAIL_SHOW_INDEX'] = false;
b14p_run_init(); // retry
b14p_check( 'T4-MIG-ready-failure-retries',
	$kept && '1.0.2' === get_option( 'hossam_notifications_db_version' )
		&& '1.0.2' === get_option( 'hossam_messages_db_version' )
		&& '1.1.2' === get_option( 'hossam_ai_jobs_db_version' ),
	'an injected ready-check failure leaves the recorded version untouched and the next init retries to completion',
	'kept=' . var_export( $kept, true ) . ' after-retry: notifications=' . get_option( 'hossam_notifications_db_version' )
		. ' messages=' . get_option( 'hossam_messages_db_version' ) . ' ai_jobs=' . get_option( 'hossam_ai_jobs_db_version' ) );

/* ════════════════════════════════════════════════════════════════
 * SCHEMA NEGATIVES (H5 §4-2 / G5 §7-10)
 * ════════════════════════════════════════════════════════════════ */

$expected_columns = array(
	'notifications' => array( 'id', 'user_id', 'type', 'message', 'is_read', 'created_at' ),
	'messages'      => array( 'id', 'sender_id', 'receiver_id', 'message', 'read_at', 'created_at' ),
	'ai_jobs'       => array( 'id', 'user_id', 'post_id', 'job_type', 'input_data', 'status', 'result', 'error_message', 'strategy_id', 'attempt_count', 'locked_at', 'next_run_at', 'last_error', 'created_at', 'updated_at' ),
);
$expected_indexes = array(
	'notifications' => array( 'PRIMARY', 'user_id', 'is_read', 'user_created', 'user_is_read' ),
	'messages'      => array( 'PRIMARY', 'sender_id', 'receiver_id', 'read_at', 'receiver_created' ),
	'ai_jobs'       => array( 'PRIMARY', 'user_id', 'post_id', 'status', 'status_locked', 'status_next_run', 'user_created_ai' ),
);
$schemas = hossam_dashboard_table_schemas();
$schema_shape_ok = true;
foreach ( $schemas as $key => $definition ) {
	$sql = $definition['schema']( 'wp_' . $definition['table_suffix'], '' );
	$keys = array( 'PRIMARY' );
	$cols = array();
	foreach ( preg_split( '/\R/', $sql ) as $line ) {
		$line = trim( $line, " \t," );
		if ( preg_match( '/^KEY\s+(\w+)\s*\(/', $line, $k ) ) {
			$keys[] = $k[1];
		} elseif ( preg_match( '/^(\w+)\s+(BIGINT|VARCHAR|TEXT|TINYINT|DATETIME|LONGTEXT)\b/', $line, $c ) ) {
			$cols[] = $c[1];
		}
	}
	if ( $cols !== $expected_columns[ $key ] || $keys !== $expected_indexes[ $key ]
		|| $definition['columns'] !== $expected_columns[ $key ] || $definition['indexes'] !== $expected_indexes[ $key ] ) {
		$schema_shape_ok = false;
	}
	if ( false !== stripos( $sql, 'DROP' ) ) {
		$schema_shape_ok = false;
	}
}
b14p_check( 'T4-SCHEMA-exact-shape',
	$schema_shape_ok && 3 === count( $schemas ),
	'zero column changes: the three schemas carry exactly the documented column lists and exactly the documented index sets (no fourth/fifth index), and no DROP anywhere',
	'schema shape deviated' );

$ai_sql = $schemas['ai_jobs']['schema']( 'wp_hossam_ai_jobs', '' );
$ai_key_lines = array();
foreach ( preg_split( '/\R/', $ai_sql ) as $line ) {
	if ( preg_match( '/KEY\s+(\w+)\s*\(([^)]*)\)/', trim( $line, " \t," ), $k ) ) {
		$ai_key_lines[ $k[1] ] = array_map( 'trim', explode( ',', $k[2] ) );
	}
}
$no_new_fourth_fifth = ! isset( $ai_key_lines['status_next_run_at'] ) && ! isset( $ai_key_lines['status_updated_at'] )
	&& 0 === count( array_filter( $ai_key_lines, static fn( $cols ) => in_array( 'attempt_count', $cols, true ) || in_array( 'updated_at', $cols, true ) ) );
b14p_check( 'T4-SCHEMA-no-fourth-fifth-index',
	$no_new_fourth_fifth,
	'no (status, attempt_count, next_run_at) and no (status, updated_at) index exists — the two re-open triggers stay closed',
	'key lines: ' . json_encode( $ai_key_lines ) );

$reserved = array( 'ACTION', 'ORDER', 'GROUP', 'STATUS', 'CONDITION', 'INTERVAL', 'LOCK', 'READ', 'WRITE', 'USE',
	'INDEX', 'KEY', 'PRIMARY', 'CASE', 'CHECK', 'COLUMN', 'CONSTRAINT', 'DELETE', 'DROP', 'ESCAPE', 'EXCEPT',
	'EXISTS', 'INSERT', 'LEAVE', 'REPLACE', 'SCHEMA', 'TABLE', 'UPDATE', 'VALUES', 'WHEN', 'WHERE', 'WHILE' );
$reserved_hits = array();
foreach ( array( 'user_created', 'user_is_read', 'receiver_created', 'status_locked', 'status_next_run', 'user_created_ai' ) as $name ) {
	if ( in_array( strtoupper( $name ), $reserved, true ) ) {
		$reserved_hits[] = $name;
	}
}
b14p_check( 'T4-SCHEMA-names-not-reserved',
	array() === $reserved_hits,
	'the six new index names are outside the reserved-word list (lesson C3)',
	'reserved collisions: ' . json_encode( $reserved_hits ) );

/* ════════════════════════════════════════════════════════════════
 * ZOMBIE SERVICE (H5 §4-3): the real (ج) SQL and the literal prefixes
 * ════════════════════════════════════════════════════════════════ */

b14p_reset_db();
do_action( 'hossam_ai_sweep_jobs_event' ); // real ai.php callback, valid profile

$sweep_queries = $GLOBALS['wpdb']->queries;
$zombie_sql = '';
$sql_a = '';
$sql_b = '';
foreach ( $sweep_queries as $q ) {
	$q = (string) $q;
	if ( false !== strpos( $q, 'next_run_at IS NULL' ) ) {
		$zombie_sql = $q;
	}
	if ( false !== strpos( $q, "status = 'processing'" ) && false !== strpos( $q, 'locked_at <=' ) ) {
		$sql_a = $q;
	}
	if ( false !== strpos( $q, "status = 'pending'" ) && false !== strpos( $q, 'attempt_count <' ) ) {
		$sql_b = $q;
	}
}
b14p_check( 'T4-ZOMBIE-real-sql-captured',
	'' !== $zombie_sql && '' !== $sql_a && '' !== $sql_b,
	'the real sweep callback produced the (أ)/(ب)/(ج) SQL triple',
	'captured: zombie=' . ( '' !== $zombie_sql ? 'yes' : 'NO' ) . ' a=' . ( '' !== $sql_a ? 'yes' : 'NO' ) . ' b=' . ( '' !== $sql_b ? 'yes' : 'NO' ) );
b14p_check( 'T4-ZOMBIE-status-prefix-no-new-index',
	'' !== $zombie_sql
		&& false !== strpos( $zombie_sql, "SELECT id, attempt_count FROM wp_hossam_ai_jobs WHERE status = 'pending' AND ( attempt_count >= 3 OR next_run_at IS NULL ) LIMIT 20" )
		&& false === strpos( $zombie_sql, 'locked_at' )
		&& array_values( $expected_indexes['ai_jobs'] ) === $expected_indexes['ai_jobs'],
	'the zombie SQL queries with the `status` leading column only (attempt_count/next_run_at as post-prefix filters) — no new index exists to serve it',
	'zombie SQL: ' . $zombie_sql );

$prefix_a_ok = '' !== $sql_a
	&& false !== strpos( $sql_a, "WHERE status = 'processing' AND locked_at IS NOT NULL AND locked_at <=" )
	&& array( 'status', 'locked_at' ) === $ai_key_lines['status_locked'];
$prefix_b_ok = '' !== $sql_b
	&& false !== strpos( $sql_b, "WHERE status = 'pending' AND next_run_at IS NOT NULL AND next_run_at <=" )
	&& false !== strpos( $sql_b, 'AND attempt_count < 3 LIMIT 20' )
	&& array( 'status', 'next_run_at' ) === $ai_key_lines['status_next_run'];
b14p_check( 'T4-ZOMBIE-prefixes-literal',
	$prefix_a_ok && $prefix_b_ok,
	'status_locked (status, locked_at) and status_next_run (status, next_run_at) match the (أ)/(ب) WHERE prefixes literally',
	'prefix_a=' . var_export( $prefix_a_ok, true ) . ' prefix_b=' . var_export( $prefix_b_ok, true ) );

/* ════════════════════════════════════════════════════════════════
 * QUOTA PREFIX (H5 §4-4): the real daily-count SQL vs user_created_ai
 * ════════════════════════════════════════════════════════════════ */

b14p_reset_db();
$GLOBALS['B14P_COUNT']  = 0;
$GLOBALS['B14P_INSERT_ID'] = 77;
$limits = array( 'per_minute' => 30, 'per_day' => 500, 'concurrent' => 2 );
$quota_id = hossam_ai_insert_job_under_quota(
	5,
	$limits,
	array(
		'user_id' => 5, 'post_id' => null, 'job_type' => 'grammar', 'input_data' => '{"content":"x"}',
		'status' => 'pending', 'strategy_id' => 'wp_ai_client', 'attempt_count' => 0,
		'next_run_at' => gmdate( 'Y-m-d H:i:s' ), 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ),
	),
	array( '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
);
$count_sql = '';
foreach ( $GLOBALS['wpdb']->queries as $q ) {
	if ( false !== strpos( (string) $q, 'COUNT(*)' ) ) {
		$count_sql = (string) $q;
	}
}
b14p_check( 'T4-QUOTA-prefix-matches-user_created_ai',
	77 === (int) $quota_id && false !== strpos( $count_sql, 'SELECT COUNT(*) FROM wp_hossam_ai_jobs WHERE user_id = 5 AND created_at >=' )
		&& array( 'user_id', 'created_at' ) === $ai_key_lines['user_created_ai'],
	'the real daily-count SQL prefix (user_id, created_at) matches the user_created_ai definition literally (column order in CREATE)',
	'quota_id=' . var_export( $quota_id, true ) . ' count_sql=' . $count_sql );

/* ════════════════════════════════════════════════════════════════
 * R1 CACHE (G5 §7-1): static per-blog in the twin controllers
 * ════════════════════════════════════════════════════════════════ */

$twin_hashes = array(
	hash_file( 'sha256', $GLOBALS['B14P_PROJECT'] . '/includes/class-template-controller.php' ),
	hash_file( 'sha256', $GLOBALS['B14P_PROJECT'] . '/runtime/infrastructure/class-template-controller.php' ),
);
b14p_check( 'T4-R1-twins-byte-identical',
	$twin_hashes[0] === $twin_hashes[1],
	'the includes/ source and runtime/ generated template controllers are byte-identical — the behavioral proof below covers both copies',
	'hashes: ' . implode( ' vs ', $twin_hashes ) );

$GLOBALS['B14P_POSTS'] = array( 7 => new WP_Post( 7, 'page', 'publish' ) );
$GLOBALS['B14P_OPTIONS']['hal_frontend_dashboard_page_id'] = 7;
$GLOBALS['B14P_QUERIED'] = new WP_Post( 7, 'page', 'publish' );
$GLOBALS['B14P_WPML_TRID_CALLS'] = 0;
$GLOBALS['B14P_WPML_TRANS_CALLS'] = 0;
add_filter( 'wpml_element_trid', function ( $value, $element_id, $type ) {
	$GLOBALS['B14P_WPML_TRID_CALLS']++;
	return 555;
}, 10, 3 );
add_filter( 'wpml_get_element_translations', function ( $value, $trid, $type ) {
	$GLOBALS['B14P_WPML_TRANS_CALLS']++;
	return array(
		(object) array( 'element_id' => 8 ),
		(object) array( 'element_id' => 9 ),
	);
}, 10, 3 );
if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
	define( 'ICL_SITEPRESS_VERSION', '4.6.0' );
}

$ids1 = HAL_Frontend_Dashboard_Template_Controller::get_target_page_ids();
$trans_after_first = $GLOBALS['B14P_WPML_TRANS_CALLS'];
$ids2 = HAL_Frontend_Dashboard_Template_Controller::get_target_page_ids();
$trans_after_second = $GLOBALS['B14P_WPML_TRANS_CALLS'];
b14p_check( 'T4-R1-static-per-request-cache',
	array( 7, 8, 9 ) === $ids1 && array( 7, 8, 9 ) === $ids2 && 1 === $trans_after_first && 1 === $trans_after_second,
	'two calls to the real get_target_page_ids() invoke the WPML translations filter exactly once (the static per-request cache serves the second call)',
	'ids1=' . json_encode( $ids1 ) . ' ids2=' . json_encode( $ids2 ) . ' trans=' . $trans_after_first . '/' . $trans_after_second );

$GLOBALS['B14P_BLOG'] = 2; // per-blog isolation: blog 2 recomputes
$ids3 = HAL_Frontend_Dashboard_Template_Controller::get_target_page_ids();
$trans_after_blog2 = $GLOBALS['B14P_WPML_TRANS_CALLS'];
$GLOBALS['B14P_BLOG'] = 1; // blog 1 cache intact
$ids4 = HAL_Frontend_Dashboard_Template_Controller::get_target_page_ids();
$trans_after_back = $GLOBALS['B14P_WPML_TRANS_CALLS'];
b14p_check( 'T4-R1-per-blog-isolation',
	array( 7, 8, 9 ) === $ids3 && 2 === $trans_after_blog2 && array( 7, 8, 9 ) === $ids4 && 2 === $trans_after_back,
	'the cache is keyed per blog: blog 2 recomputes once, returning to blog 1 stays cached',
	'ids3=' . json_encode( $ids3 ) . ' trans_blog2=' . $trans_after_blog2 . ' trans_back=' . $trans_after_back );

$GLOBALS['B14P_QUERIED'] = new WP_Post( 5, 'post', 'publish' ); // non-page → early exit unchanged
$not_page = HAL_Frontend_Dashboard_Template_Controller::is_target_query();
$GLOBALS['B14P_QUERIED'] = new WP_Post( 99, 'page', 'publish' ); // page outside the target set
$foreign_page = HAL_Frontend_Dashboard_Template_Controller::is_target_query();
$GLOBALS['B14P_QUERIED'] = new WP_Post( 8, 'page', 'publish' );
$target_page = HAL_Frontend_Dashboard_Template_Controller::is_target_query();
$controller_src = (string) file_get_contents( $GLOBALS['B14P_PROJECT'] . '/runtime/infrastructure/class-template-controller.php' );
b14p_check( 'T4-R1-predicate-early-exit-unchanged',
	false === $not_page && false === $foreign_page && true === $target_page
		&& 0 === substr_count( $controller_src, 'dbDelta' ) && 0 === substr_count( $controller_src, 'CREATE TABLE' ) && 0 === substr_count( $controller_src, 'ALTER TABLE' ),
	'is_target_query keeps the non-page early exit and the closed target set; zero schema/DB surface in the controller',
	'not_page=' . var_export( $not_page, true ) . ' foreign=' . var_export( $foreign_page, true ) . ' target=' . var_export( $target_page, true ) );

/* ════════════════════════════════════════════════════════════════
 * R1 NEGATIVE (G5 §7-3): provisioner :147 false, both copies
 * ════════════════════════════════════════════════════════════════ */

$prov_lines_runtime = file( $GLOBALS['B14P_PROJECT'] . '/runtime/infrastructure/class-site-provisioner.php', FILE_IGNORE_NEW_LINES );
$prov_lines_includes = file( $GLOBALS['B14P_PROJECT'] . '/includes/class-site-provisioner.php', FILE_IGNORE_NEW_LINES );
b14p_check( 'T4-R1NEG-provisioner-147-false',
	isset( $prov_lines_runtime[146], $prov_lines_includes[146] )
		&& false !== strpos( $prov_lines_runtime[146], 'update_option( self::PAGE_ID_OPTION, $page_id, false );' )
		&& $prov_lines_runtime[146] === $prov_lines_includes[146],
	'line 147 in both site-provisioner copies keeps the bare `false` autoload argument — zero R1 drift',
	'runtime147=' . ( $prov_lines_runtime[146] ?? 'missing' ) . ' includes147=' . ( $prov_lines_includes[146] ?? 'missing' ) );

/* ════════════════════════════════════════════════════════════════
 * R3 CAP (G5 §7-4/5): the min() cap, the flag, the untouched key block
 * ════════════════════════════════════════════════════════════════ */

$finance_src = (string) file_get_contents( $GLOBALS['B14P_PROJECT'] . '/runtime/ajax/finance.php' );
$finance_lines = file( $GLOBALS['B14P_PROJECT'] . '/runtime/ajax/finance.php', FILE_IGNORE_NEW_LINES );
$r3_cap_line = "min( max( 1, \$first_page['max_num_pages'] ), (int) apply_filters( 'hossam_fin_summary_max_pages', 20 ) )";
$r3_flag_line = "\$revenue_capped = \$first_page['max_num_pages'] > \$max_pages;";
$r3_bind_line = "'revenue_capped' => \$revenue_capped,";
$r3_key_line = "'hossam_fin_summary_' . \$epoch . '_' . \$user_id . '_' . ( \$is_mgr ? 'mgr' : 'own' ) . '_' . \$range;";
$r3_role_line = "\$is_mgr  = current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' );";
$r3_scope_line = "if ( ! \$is_mgr ) \$args['customer'] = \$user_id;";
$key_line_no = 0;
foreach ( $finance_lines as $i => $line ) {
	if ( false !== strpos( $line, "'hossam_fin_summary_' . \$epoch" ) ) {
		$key_line_no = $i + 1;
		break;
	}
}
$block_start = max( 0, $key_line_no - 12 );
$block = implode( "\n", array_slice( $finance_lines, $block_start, 25 ) );
$block_clean = 0 === count( array_filter(
	array( 'attempts_exhausted', 'orphaned_pending', 'zombie', 'retention', 'batch14', 'user_created_ai', 'autoload_migrated' ),
	static fn( $token ) => false !== strpos( $block, $token )
) );
b14p_check( 'T4-R3-cap-and-flag',
	false !== strpos( $finance_src, $r3_cap_line ) && false !== strpos( $finance_src, $r3_flag_line ) && false !== strpos( $finance_src, $r3_bind_line ),
	'the finance summary cap is min() over the hossam_fin_summary_max_pages filter (default 20) and the revenue_capped flag is bound into the KPI payload',
	'cap/flag/binding fingerprint missing' );
b14p_check( 'T4-R3-key-block-untouched',
	false !== strpos( $finance_src, $r3_key_line ) && false !== strpos( $finance_src, $r3_role_line )
		&& false !== strpos( $finance_src, $r3_scope_line ) && $key_line_no > 0 && $block_clean,
	'the cache-key epoch+uid+role+scope construction, the server-side role derivation and the customer=uid scope stay intact with zero batch14 tokens in their block',
	'key_line_no=' . $key_line_no . ' block_clean=' . var_export( $block_clean, true ) );
b14p_check( 'T4-R3-docblock-declares-deviation',
	false !== strpos( $finance_src, 'revenue_capped' ) && false !== strpos( $finance_src, 'R15 (G5' ) && false !== strpos( $finance_src, 'hossam_fin_summary_max_pages' ),
	'the finance docblock declares the capped-revenue deviation explicitly',
	'docblock fingerprint missing' );

/* ════════════════════════════════════════════════════════════════
 * DELETE QUAD (G5 §7-6/7/8): one engine, four shares, terminal-only
 * ════════════════════════════════════════════════════════════════ */

$defs = hossam_audit_log_retention_definitions();
$defs_shape = 4 === count( $defs )
	&& array( 'audit_log', 'ai_jobs', 'notifications', 'messages' ) === array_column( $defs, 'key' )
	&& array( 90, 30, 90, 180 ) === array_column( $defs, 'days' )
	&& array( 500, 500, 500, 500 ) === array_column( $defs, 'batch' );
$ai_def = null;
foreach ( $defs as $def ) {
	if ( 'ai_jobs' === $def['key'] ) {
		$ai_def = $def;
	}
}
$audit_src = (string) file_get_contents( $GLOBALS['B14P_PROJECT'] . '/runtime/core/audit-log.php' );
b14p_check( 'T4-DELETE-quad-definitions',
	$defs_shape && null !== $ai_def && true === $ai_def['terminal_only'] && 'updated_at' === $ai_def['date_column']
		&& 1 === substr_count( $audit_src, 'DELETE FROM' ) && false !== strpos( $audit_src, 'ORDER BY id LIMIT %d' ),
	'four retention definitions 90/30/90/180 with batch 500; ai_jobs terminal-only on updated_at; exactly one DELETE template with ORDER BY id LIMIT in the unit',
	'defs=' . json_encode( array_column( $defs, 'days' ) ) );

/* Tree scan: no DELETE anywhere else in the production tree. */
$tree_delete_hits = array();
$tree_iter = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $GLOBALS['B14P_PROJECT'], FilesystemIterator::SKIP_DOTS ) );
foreach ( $tree_iter as $file ) {
	if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
		continue;
	}
	$path = str_replace( '\\', '/', $file->getPathname() );
	if ( false !== strpos( $path, '/runtime/' ) || false !== strpos( $path, '/includes/' ) || preg_match( '#/hal-frontend-dashboard/[^/]+\.php$#', $path ) ) {
		if ( false !== strpos( (string) file_get_contents( $path ), 'DELETE FROM' ) ) {
			$tree_delete_hits[] = $path;
		}
	}
}
$only_engine = 1 === count( $tree_delete_hits ) && false !== strpos( (string) $tree_delete_hits[0], '/runtime/core/audit-log.php' );
b14p_check( 'T4-DELETE-tree-single-site',
	$only_engine,
	'the retention engine is the only DELETE FROM in the production tree (runtime/ + includes/ + root php)',
	'hits: ' . json_encode( $tree_delete_hits ) );

/* Behavior: 6000 due completed rows → 5000 in one capped run; terminals and fresh survive. */
b14p_reset_db();
$GLOBALS['B14P_ROWS']['wp_hossam_ai_jobs'] = array();
for ( $i = 1; $i <= 11000; $i++ ) {
	$GLOBALS['B14P_ROWS']['wp_hossam_ai_jobs'][ $i ] = array(
		'id'         => $i,
		'status'     => 'completed',
		'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS ),
		'created_at' => gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS ),
	);
}
$GLOBALS['B14P_ROWS']['wp_hossam_ai_jobs'][9001] = array( 'id' => 9001, 'status' => 'pending', 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ), 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) );
$GLOBALS['B14P_ROWS']['wp_hossam_ai_jobs'][9002] = array( 'id' => 9002, 'status' => 'processing', 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ), 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) );
$GLOBALS['B14P_ROWS']['wp_hossam_ai_jobs'][9003] = array( 'id' => 9003, 'status' => 'completed', 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 29 * DAY_IN_SECONDS ), 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 29 * DAY_IN_SECONDS ) );
$deleted1 = hossam_audit_log_retention_sweep_table( $GLOBALS['wpdb'], $ai_def, true );
$deleted2 = hossam_audit_log_retention_sweep_table( $GLOBALS['wpdb'], $ai_def, true );
$rows_left = count( $GLOBALS['B14P_ROWS']['wp_hossam_ai_jobs'] );
$terminals_alive = 'pending' === $GLOBALS['B14P_ROWS']['wp_hossam_ai_jobs'][9001]['status']
	&& 'processing' === $GLOBALS['B14P_ROWS']['wp_hossam_ai_jobs'][9002]['status']
	&& 'completed' === $GLOBALS['B14P_ROWS']['wp_hossam_ai_jobs'][9003]['status'];
b14p_check( 'T4-DELETE-behavior-terminal-only-capped',
	10000 === $deleted1 && 997 === $deleted2 && 3 === $rows_left && $terminals_alive,
	'10997 due terminal rows (11000 minus the 3 overwritten survivor seeds) dispose exactly 10000 in one run (the 20×500 per-run cap) then the remaining 997 idempotently; live pending/processing and the fresh completed row survive (terminal-only holds)',
	"deleted={$deleted1}/{$deleted2} left={$rows_left} terminals_alive=" . var_export( $terminals_alive, true ) );

/* enabled=false stops the audit share only; per-table failure isolation. */
$GLOBALS['B14P_ROWS']['wp_hossam_audit_log'] = array( 1 => array( 'id' => 1, 'status' => '', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 200 * DAY_IN_SECONDS ), 'updated_at' => '' ) );
$audit_share_disabled = hossam_audit_log_retention_sweep_table( $GLOBALS['wpdb'], $defs[0], false );
$GLOBALS['B14P_FAIL_ONCE'] = true;
$failed_share = hossam_audit_log_retention_sweep_table( $GLOBALS['wpdb'], $defs[2], true );
b14p_check( 'T4-DELETE-gate-and-isolation',
	0 === $audit_share_disabled && 0 === $failed_share && 1 === count( $GLOBALS['B14P_ROWS']['wp_hossam_audit_log'] )
		&& 0 === count( $GLOBALS['B14P_CRON_CLEARED'] ),
	'enabled=false stops the audit_log share only (rows intact) and a DELETE failure is swallowed by the engine (no throw) with zero unschedule calls ever',
	'disabled=' . $audit_share_disabled . ' failed=' . $failed_share );

/* One daily event, guarded. */
b14p_reset_cron();
$retention_schedules_seen = 0;
b14p_run_init();
foreach ( $GLOBALS['B14P_CRON_SCHEDULED'] as $sched ) {
	if ( 'hossam_dashboard_retention_sweep_event' === $sched['hook'] ) {
		$retention_schedules_seen++;
	}
}
$second_cycle = count( $GLOBALS['B14P_CRON_SCHEDULED'] );
b14p_run_init();
$second_cycle = count( $GLOBALS['B14P_CRON_SCHEDULED'] ) - $second_cycle;
b14p_check( 'T4-DELETE-schedule-one-daily-guarded',
	1 === $retention_schedules_seen && 'daily' === $GLOBALS['B14P_CRON_SCHEDULED'][0]['recurrence'] && 0 === $second_cycle && 0 === count( $GLOBALS['B14P_CRON_CLEARED'] ),
	'exactly one daily retention event with the wp_next_scheduled guard; the second init adds nothing and nothing is ever unscheduled',
	'seen=' . $retention_schedules_seen . ' second=' . $second_cycle );

/* ════════════════════════════════════════════════════════════════
 * R6′ (H5 §4-6): cron/CLI guard — zero WPML consults under cron
 * ════════════════════════════════════════════════════════════════ */

$paths_cb = null;
$setup_src = (string) file_get_contents( $GLOBALS['B14P_PROJECT'] . '/runtime/core/setup.php' );
foreach ( $GLOBALS['B14P_HOOKS']['action']['init'] as $entry ) {
	$GLOBALS['B14P_WPML_CALLS'] = array();
	$GLOBALS['B14P_DOING_CRON'] = false;
	$GLOBALS['B14P_IS_ADMIN']   = false;
	$_SERVER['REQUEST_URI']     = '/ar/dashboard/';
	call_user_func( $entry['cb'] );
	if ( array( 'dashboard', 'login' ) === $GLOBALS['B14P_WPML_CALLS'] ) {
		$paths_cb = $entry['cb'];
	}
}
b14p_check( 'T4-R6P-front-paths-consulted',
	null !== $paths_cb
		&& false !== strpos( $setup_src, "header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');" )
		&& false !== strpos( $setup_src, "header('Pragma: no-cache');" )
		&& false !== strpos( $setup_src, "header('X-LiteSpeed-Cache-Control: no-cache');" ),
	'the front request reaches the WPML consult (dashboard+login) and the three no-cache header literals stay in place verbatim — the front behavior is unchanged',
	'paths callback not identified or header literals drifted' );

b14p_reset_db();
$GLOBALS['B14P_WPML_CALLS'] = array();
$GLOBALS['B14P_DOING_CRON'] = true;
$GLOBALS['B14P_IS_ADMIN']   = false;
$_SERVER['REQUEST_URI']     = '/ar/dashboard/';
$queries_before_cron = count( $GLOBALS['wpdb']->queries );
call_user_func( $paths_cb );
$queries_after_cron = count( $GLOBALS['wpdb']->queries );
$GLOBALS['B14P_DOING_CRON'] = false;
b14p_check( 'T4-R6P-cron-zero-exit',
	0 === count( $GLOBALS['B14P_WPML_CALLS'] ) && $queries_after_cron === $queries_before_cron
		&& false !== strpos( $setup_src, "wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI )" ),
	'under wp_doing_cron the real init callback exits before any hossam_wpml_all_paths call (zero calls, zero queries) — the R32 one-line guard',
	'wpml=' . count( $GLOBALS['B14P_WPML_CALLS'] ) . ' queries_delta=' . ( $queries_after_cron - $queries_before_cron ) );

/* ════════════════════════════════════════════════════════════════
 * R5 AUTOLOAD (G5 §7-11): migrate-once flag, durable on later saves
 * ════════════════════════════════════════════════════════════════ */

/* Legacy site: settings row exists autoloaded, no migration flag yet. */
$GLOBALS['B14P_OPTIONS'] = array();
$GLOBALS['B14P_AUTOLOAD'] = array();
$GLOBALS['B14P_OPTION_WRITES'] = array();
$GLOBALS['B14P_WP_SET_AUTOLOAD'] = array();
$GLOBALS['B14P_OPTIONS']['hal_frontend_dashboard_settings'] = array( 'features' => array( 'ai' => true ), 'schema_version' => '1.0.0' );
$GLOBALS['B14P_AUTOLOAD']['hal_frontend_dashboard_settings'] = 'yes'; // pre-migration row state
HAL_Frontend_Dashboard_Settings_Repository::invalidate_cache();

b14p_run_init(); // settings init migrates autoload; other guards short-circuit on their state
$wpset_calls = count( $GLOBALS['B14P_WP_SET_AUTOLOAD'] );
$wpset_shape = 1 === $wpset_calls
	&& array( 'hal_frontend_dashboard_settings' => true ) === ( $GLOBALS['B14P_WP_SET_AUTOLOAD'][0]['options'] ?? null );
$flag_writes = array_values( array_filter( $GLOBALS['B14P_OPTION_WRITES'], static fn( $w ) => 'hossam_settings_autoload_migrated' === $w['option'] ) );

b14p_check( 'T4-R5-autoload-migrated-once',
	$wpset_shape && 'on' === $GLOBALS['B14P_AUTOLOAD']['hal_frontend_dashboard_settings']
		&& 1 === count( $flag_writes ) && 'add' === $flag_writes[0]['op'] && null === $flag_writes[0]['autoload'],
	'the settings init migrates the existing row autoload to on via wp_set_option_autoload_values and writes the guard flag once with the default (autoloaded) autoload',
	'wpset=' . json_encode( $GLOBALS['B14P_WP_SET_AUTOLOAD'] ) . ' flag=' . json_encode( $flag_writes ) );

$wpset_before = count( $GLOBALS['B14P_WP_SET_AUTOLOAD'] );
b14p_run_init(); // guard flag present → no second migration
$no_second = count( $GLOBALS['B14P_WP_SET_AUTOLOAD'] ) === $wpset_before;

$save_ok = HAL_Frontend_Dashboard_Settings_Repository::save( array( 'features' => array( 'posts' => false ) ) );
$save_writes = array_values( array_filter( $GLOBALS['B14P_OPTION_WRITES'], static fn( $w ) => 'hal_frontend_dashboard_settings' === $w['option'] ) );
$last_write = end( $save_writes );
$autoload_after_save = $GLOBALS['B14P_AUTOLOAD']['hal_frontend_dashboard_settings'] ?? '';
b14p_check( 'T4-R5-autoload-durable-after-save',
	$no_second && true === $save_ok && is_array( $last_write ) && 'update' === $last_write['op'] && true === $last_write['autoload']
		&& 'on' === $autoload_after_save && false === HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'posts' ),
	'the later real save() writes with the autoload-pinning true and the settings row autoload stays on (third check); no second migration ever',
	'no_second=' . var_export( $no_second, true ) . ' last_write=' . json_encode( $last_write ) . ' autoload=' . $autoload_after_save );

/* Fresh install: no row, no flag → init + save create everything autoloaded. */
$GLOBALS['B14P_OPTIONS'] = array();
$GLOBALS['B14P_AUTOLOAD'] = array();
$GLOBALS['B14P_OPTION_WRITES'] = array();
$GLOBALS['B14P_WP_SET_AUTOLOAD'] = array();
HAL_Frontend_Dashboard_Settings_Repository::invalidate_cache();
b14p_run_init();
$fresh_save = HAL_Frontend_Dashboard_Settings_Repository::save( array( 'features' => array( 'ai' => false ) ) );
$fresh_writes = array_values( array_filter( $GLOBALS['B14P_OPTION_WRITES'], static fn( $w ) => 'hal_frontend_dashboard_settings' === $w['option'] ) );
$fresh_last = end( $fresh_writes );
b14p_check( 'T4-R5-fresh-install-autoloaded',
	1 === count( $GLOBALS['B14P_WP_SET_AUTOLOAD'] ) && true === $fresh_save
		&& is_array( $fresh_last ) && true === $fresh_last['autoload'] && 'on' === ( $GLOBALS['B14P_AUTOLOAD']['hal_frontend_dashboard_settings'] ?? '' ),
	'a fresh install migrates the flag autoload once and the first real save creates the settings row autoloaded (on)',
	'wpset=' . count( $GLOBALS['B14P_WP_SET_AUTOLOAD'] ) . ' last=' . json_encode( $fresh_last ) );
b14p_check( 'T4-R5-docblock-fingerprint',
	false !== strpos( (string) file_get_contents( $GLOBALS['B14P_PROJECT'] . '/runtime/settings/class-settings-repository.php' ), 'autoload=yes' ),
	'the settings repository header documents the autoload=yes contract (the :8-9 contract text matches the behavior)',
	'docblock fingerprint missing' );

/* ════════════════════════════════════════════════════════════════
 * PACKAGE NEGATIVE (H5 §4-7 / G5 §7-13)
 * ════════════════════════════════════════════════════════════════ */

$appointments_src = (string) file_get_contents( $GLOBALS['B14P_PROJECT'] . '/runtime/ajax/appointments.php' );
$appointment_hits = array();
foreach ( array( 'audit', 'retention', 'zombie', 'attempts_exhausted', 'orphaned_pending', 'user_created_ai', 'status_locked', 'status_next_run', 'autoload_migrated', 'hossam_audit_log', 'batch14', '1.1.2' ) as $token ) {
	if ( false !== strpos( $appointments_src, $token ) ) {
		$appointment_hits[] = $token;
	}
}
b14p_check( 'T4-PKG-appointments-untouched',
	array() === $appointment_hits,
	'appointments.php carries zero batch14 tokens — untouched by the whole package',
	'tokens found: ' . json_encode( $appointment_hits ) );

$owned_tables = array();
foreach ( hossam_dashboard_table_schemas() as $definition ) {
	$owned_tables[] = $definition['table_suffix'];
}
$owned_tables[] = hossam_audit_log_table_definition()['table_suffix'];
b14p_check( 'T4-PKG-no-new-tables',
	array( 'hossam_notifications', 'hossam_messages', 'hossam_ai_jobs', 'hossam_audit_log' ) === $owned_tables,
	'exactly the four owned tables exist — zero new tables in the package',
	'tables: ' . json_encode( $owned_tables ) );

$delta_files = array(
	'runtime/core/tables.php',
	'runtime/core/audit-log.php',
	'runtime/core/setup.php',
	'runtime/settings/class-settings-repository.php',
	'runtime/infrastructure/class-template-controller.php',
);
$endpoint_hits = array();
foreach ( $delta_files as $rel ) {
	$src = (string) file_get_contents( $GLOBALS['B14P_PROJECT'] . '/' . $rel );
	// Comment-stripped token text (batch9 audit-C2 pattern): a token surviving
	// only inside a comment (e.g. the audit-log docblock naming the zero)
	// cannot satisfy the check and must not fail it either.
	$stripped = '';
	foreach ( token_get_all( $src ) as $token ) {
		if ( is_array( $token ) ) {
			if ( in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_OPEN_TAG, T_INLINE_HTML ), true ) ) {
				continue;
			}
			$stripped .= $token[1];
		} else {
			$stripped .= $token;
		}
	}
	if ( false !== strpos( $stripped, 'wp_ajax_' ) || false !== strpos( $stripped, 'register_rest_route' ) ) {
		$endpoint_hits[] = $rel;
	}
}
b14p_check( 'T4-PKG-no-new-endpoints',
	array() === $endpoint_hits,
	'zero wp_ajax_ and zero register_rest_route in every v1.1.0 core delta file (comment-stripped tokens) — no new AJAX/REST points',
	'hits: ' . json_encode( $endpoint_hits ) );

$composer = json_decode( (string) file_get_contents( $GLOBALS['B14P_PROJECT'] . '/composer.json' ), true );
b14p_check( 'T4-PKG-composer-unchanged',
	is_array( $composer ) && array( 'php', 'yahnis-elsts/plugin-update-checker' ) === array_keys( (array) ( $composer['require'] ?? array() ) ),
	'composer.json keeps its pre-cycle carrier shape (php + plugin-update-checker only) — zero composer packages entered the decision cycle',
	'require keys: ' . json_encode( array_keys( (array) ( $composer['require'] ?? array() ) ) ) );

/* Legacy tagging (H5 §4-5): the deferred-indexes clause stays absent from
 * this file; the positive inverse (three indexes present) is T4-SCHEMA. */
$self_src = (string) file_get_contents( __FILE__ );
$first_require_pos = (int) strpos( $self_src, 'require ' );
$head_mentions = substr_count( substr( $self_src, 0, $first_require_pos ), 'batch13-performance-test.php' );
// Token-precise: a real require/include STATEMENT whose path string names the
// tagged asset — the harness's own check code cannot false-positive here.
$batch13_required = 0;
$b14p_tokens = token_get_all( $self_src );
$b14p_token_count = count( $b14p_tokens );
for ( $ti = 0; $ti < $b14p_token_count; $ti++ ) {
	$tok = $b14p_tokens[ $ti ];
	if ( ! is_array( $tok ) || ! in_array( $tok[0], array( T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE ), true ) ) {
		continue;
	}
	for ( $tj = $ti + 1; $tj < $b14p_token_count; $tj++ ) {
		$inner = $b14p_tokens[ $tj ];
		if ( is_array( $inner ) ) {
			if ( T_CONSTANT_ENCAPSED_STRING === $inner[0] && false !== strpos( $inner[1], 'batch13' ) ) {
				$batch13_required++;
			}
			continue;
		}
		if ( ';' === $inner ) {
			break;
		}
	}
}
$legacy_clause = 'الفهارس المؤجلة' . ' غير موجودة'; // split literal: the assertion must not carry the phrase itself
b14p_check( 'T4-PKG-legacy-tagging-inverted',
	false === strpos( $self_src, $legacy_clause )
		&& 1 === $head_mentions && 0 === $batch13_required,
	'the legacy "deferred indexes absent" clause is absent (inverted positively: the three indexes are asserted present) and the tagged historical asset is mentioned once in the docblock and required nowhere',
	'head_mentions=' . $head_mentions . ' required=' . $batch13_required );

/* ════════════════════════════════════════════════════════════════
 * Staging-only items — owner-delegated, loud skips (never PASS)
 * ════════════════════════════════════════════════════════════════ */

b14p_skip( 'T4-STAGING-live-migration-T4-1',
	'live MySQL 8 and MariaDB migration + SHOW INDEX + EXPLAIN on staging — owner-delegated (H5 §4-1 / G5 §7-9); the local proxy above proves the harness-side contract' );
b14p_skip( 'T4-STAGING-R1-measurement',
	'WPML per-request wpml_get_element_translations reduction 7→1 on live staging — owner-delegated (G5 §7-2); the static per-request/per-blog cache is proven above' );
b14p_skip( 'T4-STAGING-R5-profit',
	'dashboard-render query count one below the v1.0.8 baseline (SAVEQUERIES) — owner-delegated (G5 §7-12); the autoload durability is proven above' );

/* ════════════════════════════════════════════════════════════════
 * Summary
 * ════════════════════════════════════════════════════════════════ */

b14p_result_line();
