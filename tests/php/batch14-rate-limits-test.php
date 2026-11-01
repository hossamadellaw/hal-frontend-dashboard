<?php
/**
 * HAL Frontend Dashboard — Batch 14 harness T1: central rate limits (R1).
 *
 * Local, isolated harness: no live WordPress, no network, no database, no
 * browser. Loads the REAL runtime/core/rate-limits.php (plus the REAL
 * hossam_get_allowed_upload_mimes from runtime/adapters/wordpress-uploads.php
 * — a definitions-only file, zero file-level hooks) and invokes the real
 * functions through the real registered hook callbacks. Only the WordPress
 * boundary is stubbed; no project logic is reimplemented in the harness.
 *
 * Coverage (G3-RATE-LIMITS-POSITION.md §4 items 1-13 + the fourteenth item
 * [تصويب شهادة K3/K8 R5 — ext-object-cache branch] + R1 acceptance rows):
 *
 *   1  THRESHOLD   — counter init == limit ⇒ 429 'rate_limit' with the exact
 *                    literal message; counter unchanged after rejection.
 *   2  ASCENDING   — limit consecutive successes then 429 on N+1.
 *   3  BUSY LOCK   — GET_LOCK != 1 ⇒ 503 'quota_lock_failed' exact literal,
 *                    counter untouched (zero reads, zero writes), caller-side
 *                    write never runs.
 *   4  WRITE FAIL  — set_transient failure ⇒ 503 'quota_write_failed' exact
 *                    literal, no counter row change.
 *   5  RELEASE     — RELEASE_LOCK exactly once on success / 429 / write-503 /
 *                    exception paths; zero releases on the busy path (nothing
 *                    was acquired).
 *   6  WINDOW      — captured TTL == the declared window (60 / 86400).
 *   7  DAILY       — `_day_` key is a separate sliding counter.
 *   8  NO EXEMPT   — manage_options users are rejected at the limit too.
 *   9  HAPPY PATH  — create → update×3 → save_seo → upload → send in one
 *                    minute: every call granted (no false positives).
 *   10 RACE (n7)   — simulated interleaving: a contended qualified lock blocks
 *                    BOTH the read and the write of the counter; serially at
 *                    the threshold the second increment is rejected.
 *   11 F-04 NAME   — GET_LOCK name matches the ai.php:85-97 recipe
 *                    (sha256[:40], prefix hossam_rl_, DB+prefix+blog+scope:user),
 *                    differs per scope/user; the raw inbox name
 *                    hossam_msg_rate_{uid} has ZERO occurrences in the new
 *                    unit (repo-wide retirement belongs to the inbox
 *                    unification row — inbox.php is untouched in this batch).
 *   12 REVISIONS   — wp_revisions_to_keep callback returns 10 for every
 *                    incoming value; registered once (priority 10, 2 args);
 *                    no per-type filter registered by the unit (owner's
 *                    wp_{$post_type}_revisions_to_keep stays free); a later
 *                    external filter overrides (core ordering simulated).
 *   13 UPLOAD      — unified prefilter: Arabic size message before any write,
 *                    extension reject, success consumes BOTH quotas exactly
 *                    once (no double counting), 429 in either consume sets
 *                    $file['error'] and the file never arrives, limit value
 *                    itself passes.
 *   14 EXT CACHE   — wp_using_ext_object_cache()=true branch: add(0)→incr,
 *                    reject-after threshold, single-retry then 503 on double
 *                    failure, and ZERO GET_LOCK/RELEASE_LOCK issued.
 *
 * Usage:  php -d extension=sodium tests/php/batch14-rate-limits-test.php  (exit 0 = all pass)
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

if ( PHP_SAPI !== 'cli' ) {
	echo "This harness must run under CLI PHP.\n";
	exit( 1 );
}

$GLOBALS['B14_PROJECT'] = dirname( __DIR__, 2 );

/* ════════════════════════════════════════════════════════════════
 * WordPress boundary stubs (WordPress only — never project logic)
 * ════════════════════════════════════════════════════════════════ */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $GLOBALS['B14_PROJECT'] . '/runtime/fake-wp-root/' );
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
if ( ! defined( 'DB_HOST' ) ) {
	define( 'DB_HOST', 'db.test:3306' );
}
if ( ! defined( 'DB_NAME' ) ) {
	define( 'DB_NAME', 'hal_b14_db' );
}

/* ── Hook registry (priority-ordered, dispatching) ── */
$GLOBALS['B14_HOOKS']     = array();
$GLOBALS['B14_HOOK_SEQ']  = 0;

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

/* ── Errors / sanitizers / misc ── */
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
		public function get_error_data( $code = '' ) {
			return $this->error_data;
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
if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( string $name ): string {
		return preg_replace( '/[^A-Za-z0-9.\-_]/', '', $name );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $s, ?string $domain = null ): string {
		return $s;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0 ) {
		return json_encode( $value, $flags );
	}
}

/* ── Users / caps ── */
function get_current_user_id(): int {
	return (int) ( $GLOBALS['B14_USER_ID'] ?? 0 );
}
function get_current_blog_id(): int {
	return (int) ( $GLOBALS['B14_BLOG_ID'] ?? 1 );
}
function current_user_can( string $cap, ...$rest ): bool {
	return ! empty( $GLOBALS['B14_CAPS'][ $cap ] );
}
if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( int $user_id ) {
		return $GLOBALS['B14_USERS'][ $user_id ] ?? false;
	}
}

/* ── Options / transients (store + call log) ── */
$GLOBALS['B14_OPTIONS']     = array();
$GLOBALS['B14_TRANSIENTS']  = array();
$GLOBALS['B14_TRANSIENT_LOG'] = array(); // [ ['op'=>'get'|'set'|'delete', 'key', 'ttl'] ]
$GLOBALS['B14_SET_TRANSIENT_FAIL'] = false;
$GLOBALS['B14_GET_TRANSIENT_THROW'] = false;

function get_option( string $option, $default_value = false ) {
	return array_key_exists( $option, $GLOBALS['B14_OPTIONS'] ) ? $GLOBALS['B14_OPTIONS'][ $option ] : $default_value;
}
function update_option( string $option, $value, $autoload = null ): bool {
	$GLOBALS['B14_OPTIONS'][ $option ] = $value;
	return true;
}
function add_option( string $option, $value, $autoload = null ): bool {
	if ( array_key_exists( $option, $GLOBALS['B14_OPTIONS'] ) ) {
		return false;
	}
	$GLOBALS['B14_OPTIONS'][ $option ] = $value;
	return true;
}
function get_transient( string $key ) {
	$GLOBALS['B14_TRANSIENT_LOG'][] = array( 'op' => 'get', 'key' => $key, 'ttl' => null );
	if ( ! empty( $GLOBALS['B14_GET_TRANSIENT_THROW'] ) ) {
		throw new RuntimeException( 'B14 fixture: transient read explosion' );
	}
	return array_key_exists( $key, $GLOBALS['B14_TRANSIENTS'] ) ? $GLOBALS['B14_TRANSIENTS'][ $key ] : false;
}
function set_transient( string $key, $value, int $ttl = 0 ): bool {
	$GLOBALS['B14_TRANSIENT_LOG'][] = array( 'op' => 'set', 'key' => $key, 'ttl' => $ttl );
	if ( ! empty( $GLOBALS['B14_SET_TRANSIENT_FAIL'] ) ) {
		return false;
	}
	$GLOBALS['B14_TRANSIENTS'][ $key ] = $value;
	return true;
}
function delete_transient( string $key ): bool {
	$GLOBALS['B14_TRANSIENT_LOG'][] = array( 'op' => 'delete', 'key' => $key, 'ttl' => null );
	unset( $GLOBALS['B14_TRANSIENTS'][ $key ] );
	return true;
}

/* ── External object cache branch (item 14) ── */
$GLOBALS['B14_EXT_CACHE']      = false;
$GLOBALS['B14_CACHE_STORE']    = array(); // group => key => value
$GLOBALS['B14_CACHE_LOG']      = array(); // [ ['op'=>'add'|'incr', 'key', 'group', 'ttl'] ]
$GLOBALS['B14_CACHE_INCR_FAIL']       = false;  // every incr fails
$GLOBALS['B14_CACHE_INCR_FAIL_ONCE']  = false;  // exactly the first incr fails (retry path)

function wp_using_ext_object_cache(): bool {
	return (bool) $GLOBALS['B14_EXT_CACHE'];
}
function wp_cache_add( $key, $value, string $group = '', int $ttl = 0 ): bool {
	$GLOBALS['B14_CACHE_LOG'][] = array( 'op' => 'add', 'key' => (string) $key, 'group' => $group, 'ttl' => $ttl );
	if ( isset( $GLOBALS['B14_CACHE_STORE'][ $group ][ (string) $key ] ) ) {
		return false; // add() fails when the key already exists (real backend semantics)
	}
	$GLOBALS['B14_CACHE_STORE'][ $group ][ (string) $key ] = $value;
	return true;
}
function wp_cache_incr( $key, int $offset = 1, string $group = '' ): int|false {
	$GLOBALS['B14_CACHE_LOG'][] = array( 'op' => 'incr', 'key' => (string) $key, 'group' => $group, 'ttl' => null );
	if ( ! empty( $GLOBALS['B14_CACHE_INCR_FAIL'] ) ) {
		return false;
	}
	if ( ! empty( $GLOBALS['B14_CACHE_INCR_FAIL_ONCE'] ) ) {
		$GLOBALS['B14_CACHE_INCR_FAIL_ONCE'] = false; // consume the one-shot failure
		return false;
	}
	if ( ! isset( $GLOBALS['B14_CACHE_STORE'][ $group ][ (string) $key ] ) ) {
		return false; // incr on a missing key fails (memcached semantics; the add() arm covers creation)
	}
	$GLOBALS['B14_CACHE_STORE'][ $group ][ (string) $key ] += $offset;
	return $GLOBALS['B14_CACHE_STORE'][ $group ][ (string) $key ];
}

/* ── wpdb boundary (GET_LOCK / RELEASE_LOCK capture) ── */
if ( ! class_exists( 'B14_wpdb' ) ) {
	final class B14_wpdb {
		public string $prefix     = 'wp_';
		public string $last_error = '';
		public int $blogid        = 1;
		public array $queries     = array();   // [ ['op', 'sql'] ] — prepared SQL
		public array $lock_acquires = array(); // names passed to GET_LOCK
		public array $lock_releases = array(); // names passed to RELEASE_LOCK

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
		public function get_var( ?string $query = null ) {
			$this->queries[] = array( 'op' => 'get_var', 'sql' => (string) $query );
			if ( false !== strpos( (string) $query, 'GET_LOCK' ) ) {
				$name = $this->extract_lock_name( (string) $query );
				$this->lock_acquires[] = $name;
				// 'free' → always granted; 'busy'/'held' → another holder owns the named lock.
				return 'free' === $GLOBALS['B14_LOCK_MODE'] ? 1 : 0;
			}
			if ( false !== strpos( (string) $query, 'RELEASE_LOCK' ) ) {
				$this->lock_releases[] = $this->extract_lock_name( (string) $query );
				return 1;
			}
			return null;
		}
		public function query( ?string $query = null ) {
			$this->queries[] = array( 'op' => 'query', 'sql' => (string) $query );
			return 1;
		}
		public function flush(): void {
			$this->last_error = '';
		}
		public function get_charset_collate(): string {
			return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
		}
		private function extract_lock_name( string $sql ): string {
			if ( 1 === preg_match( "/'(.*)'/s", $sql, $m ) ) {
				return stripslashes( $m[1] );
			}
			return '';
		}
	}
}
$GLOBALS['wpdb'] = new B14_wpdb();
$GLOBALS['B14_LOCK_MODE'] = 'free'; // free | busy | held

/* ── Filetype boundary (faithful core semantics: '!\.(ext|ext)$!i') ── */
function get_allowed_mime_types( $user_id = 0 ): array {
	return (array) ( $GLOBALS['B14_SITE_MIMES'] ?? array() );
}
function wp_check_filetype( string $filename, $mimes = null ): array {
	$mimes = is_array( $mimes ) ? $mimes : get_allowed_mime_types();
	$type  = false;
	$ext   = false;
	foreach ( $mimes as $ext_preg => $mime_match ) {
		$ext_preg = '!\.(' . $ext_preg . ')$!i';
		if ( preg_match( $ext_preg, $filename, $ext_matches ) ) {
			$type = $mime_match;
			$ext  = $ext_matches[1];
			break;
		}
	}
	return array( 'ext' => $ext, 'type' => $type );
}

/* ════════════════════════════════════════════════════════════════
 * Load the REAL production unit + the REAL allowed-mimes helper
 * ════════════════════════════════════════════════════════════════ */

require $GLOBALS['B14_PROJECT'] . '/runtime/adapters/wordpress-uploads.php'; // definitions only — zero file-level hooks
require $GLOBALS['B14_PROJECT'] . '/runtime/core/rate-limits.php';

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

/** Reset every mutable fixture between sections. */
function b14_reset(): void {
	$GLOBALS['B14_TRANSIENTS']     = array();
	$GLOBALS['B14_TRANSIENT_LOG']  = array();
	$GLOBALS['B14_SET_TRANSIENT_FAIL'] = false;
	$GLOBALS['B14_GET_TRANSIENT_THROW'] = false;
	$GLOBALS['B14_EXT_CACHE']      = false;
	$GLOBALS['B14_CACHE_STORE']    = array();
	$GLOBALS['B14_CACHE_LOG']      = array();
	$GLOBALS['B14_CACHE_INCR_FAIL']      = false;
	$GLOBALS['B14_CACHE_INCR_FAIL_ONCE'] = false;
	$GLOBALS['B14_LOCK_MODE']      = 'free';
	$GLOBALS['B14_USER_ID']        = 0;
	$GLOBALS['B14_CAPS']           = array();
	$GLOBALS['B14_USERS']          = array();
	$GLOBALS['B14_SITE_MIMES']     = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'pdf'          => 'application/pdf',
	);
	$GLOBALS['wpdb']               = new B14_wpdb();
}

/** Pull a single production callback registered on a hook. */
function b14_filter_cb( string $hook ): ?callable {
	$entries = b14_hook_sorted( 'filter', $hook );
	return $entries ? $entries[0]['cb'] : null;
}

/* Helper: the write step a caller performs only when consume granted. */
$GLOBALS['B14_WRITE_RAN'] = false;
function b14_simulated_write(): void {
	$GLOBALS['B14_WRITE_RAN'] = true;
}

const B14_MSG_429   = 'Rate limit exceeded. Try later.';
const B14_MSG_BUSY  = 'Service is busy. Try again.';
const B14_MSG_UNAVL = 'Service is unavailable. Try again.';

/* ════════════════════════════════════════════════════════════════
 * T1-LOAD — real unit loaded through the harness
 * ════════════════════════════════════════════════════════════════ */

b14_check( 'T1-LOAD-real-unit',
	function_exists( 'hossam_rate_limit_consume' )
		&& function_exists( 'hossam_rate_limit_get_lock_name' )
		&& function_exists( 'hossam_rate_limit_upload_prefilter' )
		&& function_exists( 'hossam_rate_limit_fileinfo_notice' )
		&& defined( 'HOSSAM_UPLOADS_MAX_FILE_SIZE' ) && 8388608 === HOSSAM_UPLOADS_MAX_FILE_SIZE,
	'real runtime/core/rate-limits.php loaded with its public surface and the 8MB constant',
	'real rate-limits.php did not load its expected surface' );

/* R3: bootstrap.php — exactly two require lines, after the core block, before settings/adapters/ajax. */
$bootstrap_src = (string) file_get_contents( $GLOBALS['B14_PROJECT'] . '/runtime/bootstrap.php' );
preg_match_all( "/require_once \\\$hal_runtime_root \. '([^']+)';/", $bootstrap_src, $b14_m, PREG_OFFSET_CAPTURE );
$b14_requires  = array_map( static fn( $pair ) => array( 'path' => $pair[0], 'offset' => $pair[1] ), $b14_m[1] );
$b14_core      = array_values( array_filter( $b14_requires, static fn( $r ) => str_starts_with( $r['path'], 'core/' ) ) );
$b14_core_paths = array_map( static fn( $r ) => $r['path'], $b14_core );
$b14_expected_core = array( 'core/setup.php', 'core/i18n.php', 'core/tables.php', 'core/permissions.php', 'core/notifications.php', 'core/rate-limits.php', 'core/audit-log.php' );
$b14_settings_offset = null;
foreach ( $b14_requires as $r ) {
	if ( 'settings/class-settings-repository.php' === $r['path'] ) {
		$b14_settings_offset = $r['offset'];
	}
}
b14_check( 'T1-R3-bootstrap-two-require-lines',
	$b14_expected_core === $b14_core_paths
		&& $b14_core[5]['offset'] > $b14_core[4]['offset']
		&& $b14_core[6]['offset'] > $b14_core[5]['offset']
		&& null !== $b14_settings_offset
		&& $b14_core[6]['offset'] < $b14_settings_offset,
	'bootstrap.php carries exactly core/rate-limits.php then core/audit-log.php after the untouched core block (7 files, mandatory order) and before settings',
	'bootstrap.php require placement/order drifted: ' . var_export( $b14_core_paths, true ) );

/* ════════════════════════════════════════════════════════════════
 * T1-S1 (G3 §4-1) — threshold: counter == limit ⇒ 429, no increment
 * ════════════════════════════════════════════════════════════════ */

b14_reset();
$GLOBALS['B14_USER_ID'] = 7;
$GLOBALS['B14_TRANSIENTS']['hossam_rl_test_7'] = 3; // counter pre-set to the limit
$r = hossam_rate_limit_consume( 'test', 7, 3, 60 );
b14_check( 'T1-S1-threshold-429-literal',
	is_wp_error( $r ) && 'rate_limit' === $r->get_error_code()
		&& B14_MSG_429 === $r->get_error_message()
		&& is_array( $r->error_data ) && 429 === $r->error_data['status'],
	'counter at the limit ⇒ WP_Error rate_limit[429] with the exact literal message',
	'threshold rejection deviated: ' . var_export( $r, true ) );
b14_check( 'T1-S1-threshold-counter-unchanged',
	3 === (int) $GLOBALS['B14_TRANSIENTS']['hossam_rl_test_7']
		&& 1 === count( array_filter( $GLOBALS['B14_TRANSIENT_LOG'], static fn( $e ) => 'get' === $e['op'] ) )
		&& 0 === count( array_filter( $GLOBALS['B14_TRANSIENT_LOG'], static fn( $e ) => 'set' === $e['op'] ) ),
	'rejection path reads the counter once and never writes it (no increment on reject)',
	'threshold rejection mutated the counter or wrote a transient' );

/* ════════════════════════════════════════════════════════════════
 * T1-S2 (G3 §4-2) — ascending: N successes then 429 on N+1
 * ════════════════════════════════════════════════════════════════ */

b14_reset();
$GLOBALS['B14_USER_ID'] = 7;
$granted = 0;
for ( $i = 0; $i < 3; $i++ ) {
	if ( true === hossam_rate_limit_consume( 'asc', 7, 3, 60 ) ) {
		$granted++;
	}
}
$r4 = hossam_rate_limit_consume( 'asc', 7, 3, 60 );
b14_check( 'T1-S2-ascending-limit-then-429',
	3 === $granted && is_wp_error( $r4 ) && 'rate_limit' === $r4->get_error_code() && B14_MSG_429 === $r4->get_error_message(),
	'3 consecutive successes then 429 on the 4th call',
	'ascending consumption deviated: granted=' . $granted . ' fourth=' . var_export( $r4, true ) );
b14_check( 'T1-S2-ascending-counter-at-limit',
	3 === (int) $GLOBALS['B14_TRANSIENTS']['hossam_rl_asc_7'],
	'counter equals the limit after the rejecting call',
	'counter after the N+1 rejection is not the limit: ' . var_export( $GLOBALS['B14_TRANSIENTS']['hossam_rl_asc_7'], true ) );

/* ════════════════════════════════════════════════════════════════
 * T1-S3 (G3 §4-3) — busy lock: 503, counter untouched, no write
 * ════════════════════════════════════════════════════════════════ */

b14_reset();
$GLOBALS['B14_USER_ID'] = 7;
$GLOBALS['B14_LOCK_MODE'] = 'busy';
$GLOBALS['B14_WRITE_RAN'] = false;
$r = hossam_rate_limit_consume( 'busy', 7, 3, 60 );
$reads_during_busy = count( array_filter( $GLOBALS['B14_TRANSIENT_LOG'], static fn( $e ) => 'get' === $e['op'] ) );
$writes_during_busy = count( array_filter( $GLOBALS['B14_TRANSIENT_LOG'], static fn( $e ) => 'set' === $e['op'] ) );
b14_check( 'T1-S3-busy-503-literal',
	is_wp_error( $r ) && 'quota_lock_failed' === $r->get_error_code()
		&& B14_MSG_BUSY === $r->get_error_message()
		&& is_array( $r->error_data ) && 503 === $r->error_data['status'],
	'contended lock ⇒ WP_Error quota_lock_failed[503] with the exact literal message',
	'busy-lock rejection deviated: ' . var_export( $r, true ) );
b14_check( 'T1-S3-busy-no-read-no-write-no-caller-write',
	0 === $reads_during_busy && 0 === $writes_during_busy
		&& ! isset( $GLOBALS['B14_TRANSIENTS']['hossam_rl_busy_7'] )
		&& false === $GLOBALS['B14_WRITE_RAN'],
	'lock failure blocks BOTH the counter read and the write, and the caller-side write never ran',
	'busy path touched the counter or let the write through: reads=' . $reads_during_busy . ' writes=' . $writes_during_busy );
b14_check( 'T1-S3-busy-zero-releases',
	0 === count( $GLOBALS['wpdb']->lock_releases ),
	'busy path releases nothing (it never held the lock)',
	'busy path issued a RELEASE_LOCK' );

/* ════════════════════════════════════════════════════════════════
 * T1-S4 (G3 §4-4) — transient write failure ⇒ 503, no row change
 * ════════════════════════════════════════════════════════════════ */

b14_reset();
$GLOBALS['B14_USER_ID'] = 7;
$GLOBALS['B14_SET_TRANSIENT_FAIL'] = true;
$GLOBALS['B14_WRITE_RAN'] = false;
$r = hossam_rate_limit_consume( 'wfail', 7, 3, 60 );
b14_check( 'T1-S4-write-fail-503-literal',
	is_wp_error( $r ) && 'quota_write_failed' === $r->get_error_code()
		&& B14_MSG_UNAVL === $r->get_error_message()
		&& is_array( $r->error_data ) && 503 === $r->error_data['status'],
	'set_transient failure ⇒ WP_Error quota_write_failed[503] with the exact literal message',
	'write-failure rejection deviated: ' . var_export( $r, true ) );
b14_check( 'T1-S4-write-fail-no-row-no-write',
	! isset( $GLOBALS['B14_TRANSIENTS']['hossam_rl_wfail_7'] ) && false === $GLOBALS['B14_WRITE_RAN'],
	'failed transient write leaves no counter row and the caller-side write never ran',
	'write-failure path stored a row or wrote through' );
b14_check( 'T1-S4-write-fail-release-once',
	1 === count( $GLOBALS['wpdb']->lock_releases ) && 1 === count( $GLOBALS['wpdb']->lock_acquires ),
	'write-failure path acquired and released the lock exactly once',
	'write-failure path lock accounting wrong: acquires=' . count( $GLOBALS['wpdb']->lock_acquires ) . ' releases=' . count( $GLOBALS['wpdb']->lock_releases ) );

/* ════════════════════════════════════════════════════════════════
 * T1-S5 (G3 §4-5) — RELEASE_LOCK exactly once on every held path
 * ════════════════════════════════════════════════════════════════ */

b14_reset();
$GLOBALS['B14_USER_ID'] = 7;
$release_counts = array();
hossam_rate_limit_consume( 'rel_ok', 7, 3, 60 );
$release_counts['success'] = count( $GLOBALS['wpdb']->lock_releases );
hossam_rate_limit_consume( 'rel_429', 7, 0, 60 ); // limit 0 ⇒ immediate 429 under the held lock
$release_counts['429'] = count( $GLOBALS['wpdb']->lock_releases ) - $release_counts['success'];
$GLOBALS['B14_SET_TRANSIENT_FAIL'] = true;
hossam_rate_limit_consume( 'rel_503', 7, 3, 60 );
$release_counts['503'] = count( $GLOBALS['wpdb']->lock_releases ) - $release_counts['success'] - $release_counts['429'];
$GLOBALS['B14_SET_TRANSIENT_FAIL'] = false;
$GLOBALS['B14_GET_TRANSIENT_THROW'] = true;
$threw = false;
try {
	hossam_rate_limit_consume( 'rel_exc', 7, 3, 60 );
} catch ( Throwable $e ) {
	$threw = true;
}
$GLOBALS['B14_GET_TRANSIENT_THROW'] = false;
$release_counts['exception'] = count( $GLOBALS['wpdb']->lock_releases )
	- $release_counts['success'] - $release_counts['429'] - $release_counts['503'];
b14_check( 'T1-S5-release-once-every-path',
	1 === $release_counts['success'] && 1 === $release_counts['429']
		&& 1 === $release_counts['503'] && $threw && 1 === $release_counts['exception'],
	'RELEASE_LOCK fires exactly once on success / 429 / write-503 / exception (finally on every held path)',
	'lock release accounting deviated: ' . var_export( $release_counts, true ) . ' threw=' . var_export( $threw, true ) );

/* ════════════════════════════════════════════════════════════════
 * T1-S6 (G3 §4-6) — sliding window TTL == declared window
 * ════════════════════════════════════════════════════════════════ */

b14_reset();
$GLOBALS['B14_USER_ID'] = 7;
hossam_rate_limit_consume( 'win60', 7, 3, 60 );
hossam_rate_limit_consume( 'win_day', 7, 3, 86400 );
$set_ttls = array();
foreach ( $GLOBALS['B14_TRANSIENT_LOG'] as $e ) {
	if ( 'set' === $e['op'] ) {
		$set_ttls[ $e['key'] ] = $e['ttl'];
	}
}
b14_check( 'T1-S6-window-ttl-captured',
	60 === $set_ttls['hossam_rl_win60_7'] && 86400 === $set_ttls['hossam_rl_win_day_7'],
	'set_transient receives the declared window as TTL (60 minute / 86400 daily) — every write renews it (sliding)',
	'captured TTLs deviate: ' . var_export( $set_ttls, true ) );

/* ════════════════════════════════════════════════════════════════
 * T1-S7 (G3 §4-7) — daily `_day_` counter is separate
 * ════════════════════════════════════════════════════════════════ */

b14_reset();
$GLOBALS['B14_USER_ID'] = 7;
$minute_open = hossam_rate_limit_consume( 'create', 7, 10, 60 );
$first_day   = hossam_rate_limit_consume( 'create_day', 7, 1, 86400 ); // daily limit 1 → granted (counter now 1)
$daily_429   = hossam_rate_limit_consume( 'create_day', 7, 1, 86400 ); // daily exhausted
$minute_open2 = hossam_rate_limit_consume( 'create', 7, 10, 60 );      // minute window still open
b14_check( 'T1-S7-daily-key-separate',
	true === $minute_open && true === $first_day
		&& is_wp_error( $daily_429 ) && 'rate_limit' === $daily_429->get_error_code()
		&& true === $minute_open2
		&& 2 === (int) $GLOBALS['B14_TRANSIENTS']['hossam_rl_create_7']
		&& 1 === (int) $GLOBALS['B14_TRANSIENTS']['hossam_rl_create_day_7'],
	'daily `_day_` key rejects independently while the minute window stays open (distinct counters)',
	'daily counter leaked into the minute counter or the daily rejection deviated: ' . var_export( $daily_429, true ) );

/* ════════════════════════════════════════════════════════════════
 * T1-S8 (G3 §4-8) — no manage_options exemption
 * ════════════════════════════════════════════════════════════════ */

b14_reset();
$GLOBALS['B14_USER_ID'] = 9;
$GLOBALS['B14_CAPS']['manage_options'] = true;
for ( $i = 0; $i < 5; $i++ ) {
	hossam_rate_limit_consume( 'admin', 9, 5, 60 );
}
$r = hossam_rate_limit_consume( 'admin', 9, 5, 60 );
b14_check( 'T1-S8-no-manage-options-exemption',
	is_wp_error( $r ) && 'rate_limit' === $r->get_error_code() && B14_MSG_429 === $r->get_error_message(),
	'manage_options user is rejected 429 at the limit like anyone else (no role bypass)',
	'manage_options user escaped the limit: ' . var_export( $r, true ) );

/* ════════════════════════════════════════════════════════════════
 * T1-S9 (G3 §4-9) — realistic session: no false positives
 * ════════════════════════════════════════════════════════════════ */

b14_reset();
$GLOBALS['B14_USER_ID'] = 11;
$happy = array(
	'create'    => hossam_rate_limit_consume( 'create', 11, 10, 60 ),
	'update1'   => hossam_rate_limit_consume( 'update', 11, 30, 60 ),
	'update2'   => hossam_rate_limit_consume( 'update', 11, 30, 60 ),
	'update3'   => hossam_rate_limit_consume( 'update', 11, 30, 60 ),
	'save_seo'  => hossam_rate_limit_consume( 'seo', 11, 30, 60 ),
	'msg'       => hossam_rate_limit_consume( 'msg', 11, 10, 60 ),
);
b14_check( 'T1-S9-happy-path-all-granted',
	true === $happy['create'] && true === $happy['update1'] && true === $happy['update2']
		&& true === $happy['update3'] && true === $happy['save_seo'] && true === $happy['msg'],
	'create → update×3 → save_seo → send within one minute: every call granted',
	'realistic session hit a false positive: ' . var_export( $happy, true ) );

/* ════════════════════════════════════════════════════════════════
 * T1-S10 (G3 §4-10, V3 ن7) — race: lock blocks read+write; serial at threshold
 * ════════════════════════════════════════════════════════════════ */

b14_reset();
$GLOBALS['B14_USER_ID'] = 7;
$GLOBALS['B14_TRANSIENTS']['hossam_rl_race_7'] = 0;
$GLOBALS['B14_LOCK_MODE'] = 'held'; // another parallel request owns the qualified lock right now
$GLOBALS['B14_WRITE_RAN'] = false;
$r_held = hossam_rate_limit_consume( 'race', 7, 1, 60 );
$held_reads  = count( array_filter( $GLOBALS['B14_TRANSIENT_LOG'], static fn( $e ) => 'get' === $e['op'] ) );
$held_writes = count( array_filter( $GLOBALS['B14_TRANSIENT_LOG'], static fn( $e ) => 'set' === $e['op'] ) );
b14_check( 'T1-S10-race-contended-lock-blocks-read-and-write',
	is_wp_error( $r_held ) && 'quota_lock_failed' === $r_held->get_error_code()
		&& 0 === $held_reads && 0 === $held_writes && 0 === (int) $GLOBALS['B14_TRANSIENTS']['hossam_rl_race_7']
		&& false === $GLOBALS['B14_WRITE_RAN'],
	'interleaved parallel request under a contended qualified lock can neither read nor write the counter (S3 simulation)',
	'contended path leaked a read/write: ' . var_export( $r_held, true ) . ' reads=' . $held_reads . ' writes=' . $held_writes );
$GLOBALS['B14_LOCK_MODE'] = 'free';
$first = hossam_rate_limit_consume( 'race', 7, 1, 60 ); // counter 0 → granted (now 1)
$second = hossam_rate_limit_consume( 'race', 7, 1, 60 ); // at threshold ⇒ 429 under the lock
b14_check( 'T1-S10-race-serial-second-increment-rejected',
	true === $first && is_wp_error( $second ) && 'rate_limit' === $second->get_error_code()
		&& 1 === (int) $GLOBALS['B14_TRANSIENTS']['hossam_rl_race_7'],
	'serialized increments at the threshold: second request rejected, counter stays at the limit',
	'second increment at the threshold was not rejected: ' . var_export( $second, true ) );

/* ════════════════════════════════════════════════════════════════
 * T1-S11 (G3 §4-11, F-04) — qualified lock name recipe
 * ════════════════════════════════════════════════════════════════ */

b14_reset();
$GLOBALS['B14_USER_ID'] = 7;
hossam_rate_limit_consume( 'upload', 7, 10, 60 );
$identity_7_upload = DB_HOST . "\0" . DB_NAME . "\0" . 'wp_' . "\0" . '1' . "\0" . 'upload:7';
$expected_7_upload = 'hossam_rl_' . substr( hash( 'sha256', $identity_7_upload ), 0, 40 );
$acquired = $GLOBALS['wpdb']->lock_acquires;
b14_check( 'T1-S11-lock-name-recipe-sha256-40',
	1 === count( $acquired ) && $expected_7_upload === $acquired[0]
		&& 1 === preg_match( '/^hossam_rl_[0-9a-f]{40}$/', $acquired[0] )
		&& 50 === strlen( $acquired[0] ),
	'GET_LOCK name matches the ai.php:85-97 recipe byte-for-byte (sha256[:40], prefix hossam_rl_, DB+prefix+blog+scope:user)',
	'lock name deviates from the recipe: ' . var_export( $acquired, true ) . ' expected ' . $expected_7_upload );
$name_a = hossam_rate_limit_get_lock_name( 'upload', 7 );
$name_b = hossam_rate_limit_get_lock_name( 'create', 7 );
$name_c = hossam_rate_limit_get_lock_name( 'upload', 8 );
b14_check( 'T1-S11-lock-name-differs-by-scope-and-user',
	$name_a !== $name_b && $name_a !== $name_c && $name_b !== $name_c,
	'qualified name differs per scope and per user (no cross-user lock contention)',
	'lock names collide across scope/user: ' . var_export( array( $name_a, $name_b, $name_c ), true ) );

/* Raw inbox lock name: zero occurrences in the NEW unit (repo-wide retirement
 * belongs to the inbox unification row — inbox.php is untouched in this batch). */
$rl_src = (string) file_get_contents( $GLOBALS['B14_PROJECT'] . '/runtime/core/rate-limits.php' );
b14_check( 'T1-S11-raw-name-zero-in-new-unit',
	0 === substr_count( $rl_src, 'hossam_msg_rate_' )
		&& 0 === preg_match_all( '/hossam_msg_rate_/', $rl_src ),
	'the raw inbox lock name hossam_msg_rate_{uid} has zero occurrences in the new unit (qualified recipe only)',
	'raw lock name found in rate-limits.php' );

/* ai.php untouched: zero hossam_rate_limit_consume calls inside it (G3 م8). */
$ai_src = (string) file_get_contents( $GLOBALS['B14_PROJECT'] . '/runtime/ajax/ai.php' );
b14_check( 'T1-S11-ai-php-untouched-by-consume',
	0 === substr_count( $ai_src, 'hossam_rate_limit_consume' )
		&& 0 === preg_match_all( '/hossam_rate_limit_consume/', $ai_src ),
	'ai.php keeps its profile-driven trio: zero hossam_rate_limit_consume occurrences (G3 §1.1-7)',
	'ai.php references the unified consume function' );

/* ════════════════════════════════════════════════════════════════
 * T1-S12 (G3 §4-12) — revisions cap by the three-tier ladder
 * ════════════════════════════════════════════════════════════════ */

$rev_entries = b14_hook_sorted( 'filter', 'wp_revisions_to_keep' );
$rev_cb      = null;
foreach ( $rev_entries as $entry ) {
	if ( 'hossam_rate_limit_revisions_to_keep' === ( is_string( $entry['cb'] ) ? $entry['cb'] : '' ) ) {
		$rev_cb = $entry;
	}
}
b14_check( 'T1-S12-revisions-registered-once',
	1 === count( $rev_entries ) && null !== $rev_cb && 10 === $rev_cb['p'] && 2 === $rev_cb['a'],
	'wp_revisions_to_keep registered exactly once (priority 10, 2 args) by the unit',
	'revisions filter registration deviated: ' . var_export( $rev_entries, true ) );
$rev_vals = array();
foreach ( array( true, -1, 0, 99, 'whatever' ) as $incoming ) {
	$rev_vals[] = call_user_func( 'hossam_rate_limit_revisions_to_keep', $incoming, null );
}
b14_check( 'T1-S12-revisions-always-10',
	array( 10, 10, 10, 10, 10 ) === $rev_vals,
	'the unit filter returns 10 for every incoming value (true/-1/0/99/other — replaces the whole ladder below it)',
	'revisions values deviate: ' . var_export( $rev_vals, true ) );
$per_type = array();
foreach ( array_keys( $GLOBALS['B14_HOOKS']['filter'] ) as $hook_name ) {
	if ( 1 === preg_match( '/^wp_.+_revisions_to_keep$/', $hook_name ) && 'wp_revisions_to_keep' !== $hook_name ) {
		$per_type[] = $hook_name;
	}
}
b14_check( 'T1-S12-no-per-type-registration',
	array() === $per_type,
	'no per-type wp_{$post_type}_revisions_to_keep filter registered — the owner-ladder stays free to override',
	'unit registered a per-type revisions filter: ' . var_export( $per_type, true ) );
/* Core ordering simulation: a LATER-registered filter on the same hook wins. */
$external_called = false;
$ext_cb = static function ( $num, $post ) use ( &$external_called ) {
	$external_called = true;
	return 5;
};
add_filter( 'wp_revisions_to_keep', $ext_cb, 10, 2 );
$chained = null;
foreach ( b14_hook_sorted( 'filter', 'wp_revisions_to_keep' ) as $entry ) {
	$chained = call_user_func( $entry['cb'], $chained, null );
}
remove_filter( 'wp_revisions_to_keep', $ext_cb, 10 );
b14_check( 'T1-S12-external-filter-override',
	$external_called && 5 === $chained,
	'an external filter registered later overrides the unit value (10 → 5) — core filter-chain ordering preserved',
	'external override simulation failed: chained=' . var_export( $chained, true ) );

/* ════════════════════════════════════════════════════════════════
 * T1-S13 (G3 §4-13) — unified upload: one chokepoint, no double counting
 * ════════════════════════════════════════════════════════════════ */

b14_reset();
$GLOBALS['B14_USER_ID'] = 7;
$prefilter = b14_filter_cb( 'wp_handle_upload_prefilter' );
b14_check( 'T1-S13-prefilter-registered-once',
	null !== $prefilter && 'hossam_rate_limit_upload_prefilter' === ( is_string( $prefilter ) ? $prefilter : '' )
		&& 1 === count( b14_hook_sorted( 'filter', 'wp_handle_upload_prefilter' ) ),
	'wp_handle_upload_prefilter registered exactly once — the single official chokepoint for AJAX + async-upload + REST media',
	'upload prefilter registration deviated' );

/* Success path: one file → exactly +1 upload and +1 upload_day (no double counting). */
$GLOBALS['B14_WRITE_RAN'] = false;
$f = call_user_func( $prefilter, array( 'name' => 'photo.jpg', 'size' => 1000 ) );
if ( ! isset( $f['error'] ) ) {
	b14_simulated_write(); // core (file.php:867-869) proceeds to write only when no error was set
}
$up_min  = (int) ( $GLOBALS['B14_TRANSIENTS']['hossam_rl_upload_7'] ?? 0 );
$up_day  = (int) ( $GLOBALS['B14_TRANSIENTS']['hossam_rl_upload_day_7'] ?? 0 );
b14_check( 'T1-S13-success-consumes-both-once',
	! isset( $f['error'] ) && 1 === $up_min && 1 === $up_day && true === $GLOBALS['B14_WRITE_RAN'],
	'allowed file passes and consumes upload 10/min + upload_day 100/day exactly once each (no double counting)',
	'success path counting deviated: file=' . var_export( $f, true ) . " min={$up_min} day={$up_day}" );

/* 11 files in the minute: the 11th is rejected with the exact literal before any write. */
b14_reset();
$GLOBALS['B14_USER_ID'] = 7;
$eleventh_error = '';
for ( $i = 1; $i <= 10; $i++ ) {
	$ok = call_user_func( $prefilter, array( 'name' => 'photo-' . $i . '.jpg', 'size' => 100 ) );
	if ( isset( $ok['error'] ) ) {
		$eleventh_error = 'unexpected rejection on file ' . $i . ': ' . $ok['error'];
		break;
	}
}
$GLOBALS['B14_WRITE_RAN'] = false;
$f11 = call_user_func( $prefilter, array( 'name' => 'photo-11.jpg', 'size' => 100 ) );
b14_check( 'T1-S13-11th-file-rejected-literal',
	'' === $eleventh_error && isset( $f11['error'] ) && B14_MSG_429 === $f11['error']
		&& 10 === (int) $GLOBALS['B14_TRANSIENTS']['hossam_rl_upload_7']
		&& false === $GLOBALS['B14_WRITE_RAN'],
	'the 11th upload in the minute sets $file[error] to the exact 429 literal and the file never arrives (write aborted)',
	'11th-file rejection deviated: ' . $eleventh_error . ' file=' . var_export( $f11, true ) );

/* Size limit: over → Arabic message before any write; counters untouched. */
b14_reset();
$GLOBALS['B14_USER_ID'] = 7;
$f_big = call_user_func( $prefilter, array( 'name' => 'scan.pdf', 'size' => 9437184 ) );
b14_check( 'T1-S13-size-reject-arabic-before-write',
	isset( $f_big['error'] ) && false !== strpos( (string) $f_big['error'], 'يتجاوز الحد المسموح' )
		&& false !== strpos( (string) $f_big['error'], 'scan.pdf' )
		&& false !== strpos( (string) $f_big['error'], 'ميغابايت' )
		&& ! isset( $GLOBALS['B14_TRANSIENTS']['hossam_rl_upload_7'] ),
	'9MB file rejected with the Arabic size message (name + sizes included) before any counter consumption or file write',
	'size rejection deviated: ' . var_export( $f_big['error'] ?? null, true ) );

/* Size limit value itself passes (8388608 is not > 8388608). */
$f_at = call_user_func( $prefilter, array( 'name' => 'exact.pdf', 'size' => 8388608 ) );
b14_check( 'T1-S13-size-at-limit-passes',
	! isset( $f_at['error'] ) && 1 === (int) $GLOBALS['B14_TRANSIENTS']['hossam_rl_upload_7'],
	'a file of exactly 8388608 bytes passes the size gate and consumes its quota',
	'at-limit file was rejected: ' . var_export( $f_at, true ) );

/* Extension reject: outside the site∩project intersection. */
b14_reset();
$GLOBALS['B14_USER_ID'] = 7;
$f_exe = call_user_func( $prefilter, array( 'name' => 'evil.exe', 'size' => 100 ) );
b14_check( 'T1-S13-ext-reject',
	isset( $f_exe['error'] ) && 'نوع الملف غير مسموح.' === $f_exe['error']
		&& ! isset( $GLOBALS['B14_TRANSIENTS']['hossam_rl_upload_7'] ),
	'extension outside the unified intersection rejected with the literal message, zero quota consumed',
	'extension rejection deviated: ' . var_export( $f_exe['error'] ?? null, true ) );

/* 429 on the DAILY arm while the minute arm is open: file invalidated, minute unit spent (documented ≤1, no rollback). */
b14_reset();
$GLOBALS['B14_USER_ID'] = 7;
$GLOBALS['B14_TRANSIENTS']['hossam_rl_upload_day_7'] = 100;
$GLOBALS['B14_WRITE_RAN'] = false;
$f_day = call_user_func( $prefilter, array( 'name' => 'photo.jpg', 'size' => 100 ) );
b14_check( 'T1-S13-daily-429-invalidates-upload',
	isset( $f_day['error'] ) && B14_MSG_429 === $f_day['error']
		&& 1 === (int) $GLOBALS['B14_TRANSIENTS']['hossam_rl_upload_7']
		&& 100 === (int) $GLOBALS['B14_TRANSIENTS']['hossam_rl_upload_day_7']
		&& false === $GLOBALS['B14_WRITE_RAN'],
	'daily quota full ⇒ $file[error] set, upload_day untouched, the minute unit spent (≤1, no rollback) and the file never arrives',
	'daily-rejection handling deviated: ' . var_export( $f_day['error'] ?? null, true ) );

/* ════════════════════════════════════════════════════════════════
 * T1-S14 (K3/K8 R5 — fourteenth item) — ext-object-cache branch
 * ════════════════════════════════════════════════════════════════ */

b14_reset();
$GLOBALS['B14_EXT_CACHE'] = true;
$GLOBALS['B14_USER_ID'] = 7;
$ext_granted = 0;
for ( $i = 0; $i < 3; $i++ ) {
	if ( true === hossam_rate_limit_consume( 'ext', 7, 3, 60 ) ) {
		$ext_granted++;
	}
}
$ext_r4 = hossam_rate_limit_consume( 'ext', 7, 3, 60 );
$store_after_4 = (int) $GLOBALS['B14_CACHE_STORE']['hossam_rl']['hossam_rl_ext_7'];
$ext_r5 = hossam_rate_limit_consume( 'ext', 7, 3, 60 );
$store_after_5 = (int) $GLOBALS['B14_CACHE_STORE']['hossam_rl']['hossam_rl_ext_7'];
b14_check( 'T1-S14-ext-reject-after-threshold',
	3 === $ext_granted && is_wp_error( $ext_r4 ) && 'rate_limit' === $ext_r4->get_error_code()
		&& B14_MSG_429 === $ext_r4->get_error_message() && is_wp_error( $ext_r5 )
		&& 4 === $store_after_4 && 5 === $store_after_5,
	'ext-cache branch: 3 grants then reject-after on the 4th/5th (counter 4 then 5 — bounded overshoot, documented semantics)',
	'ext-cache threshold deviated: granted=' . $ext_granted . ' r4=' . var_export( $ext_r4, true ) . ' store4=' . $store_after_4 . ' store5=' . $store_after_5 );
$ext_ttls = array();
foreach ( $GLOBALS['B14_CACHE_LOG'] as $e ) {
	if ( 'add' === $e['op'] ) {
		$ext_ttls[] = $e['ttl'];
	}
}
b14_check( 'T1-S14-ext-add-ttl-and-group',
	5 === count( $ext_ttls ) && array( 60, 60, 60, 60, 60 ) === $ext_ttls
		&& isset( $GLOBALS['B14_CACHE_STORE']['hossam_rl'] ),
	'ext-cache add() carries the declared window as TTL on the hossam_rl group (one add per consume call — 5 calls)',
	'ext-cache add TTL/group deviated: ' . var_export( $ext_ttls, true ) );

/* Single-retry then success (add→incr fail→add+incr again). */
b14_reset();
$GLOBALS['B14_EXT_CACHE'] = true;
$GLOBALS['B14_CACHE_INCR_FAIL_ONCE'] = true;
$GLOBALS['B14_USER_ID'] = 8;
$r_retry = hossam_rate_limit_consume( 'retry', 8, 3, 60 );
b14_check( 'T1-S14-ext-single-retry-grants',
	true === $r_retry && 1 === (int) $GLOBALS['B14_CACHE_STORE']['hossam_rl']['hossam_rl_retry_8'],
	'first incr failure triggers the documented single add+incr retry which grants the unit',
	'single-retry path deviated: ' . var_export( $r_retry, true ) );

/* Double failure ⇒ 503 with the exact literal. */
b14_reset();
$GLOBALS['B14_EXT_CACHE'] = true;
$GLOBALS['B14_CACHE_INCR_FAIL'] = true;
$GLOBALS['B14_USER_ID'] = 9;
$r_df = hossam_rate_limit_consume( 'dfail', 9, 3, 60 );
b14_check( 'T1-S14-ext-double-failure-503-literal',
	is_wp_error( $r_df ) && 'quota_write_failed' === $r_df->get_error_code()
		&& B14_MSG_UNAVL === $r_df->get_error_message()
		&& is_array( $r_df->error_data ) && 503 === $r_df->error_data['status'],
	'double incr failure ⇒ WP_Error quota_write_failed[503] with the exact literal message (closed on failure)',
	'double-failure path deviated: ' . var_export( $r_df, true ) );

/* ZERO GET_LOCK/RELEASE_LOCK issued on the ext-cache branch. */
$get_locks = 0;
foreach ( $GLOBALS['wpdb']->queries as $q ) {
	if ( false !== strpos( (string) $q['sql'], 'GET_LOCK' ) || false !== strpos( (string) $q['sql'], 'RELEASE_LOCK' ) ) {
		$get_locks++;
	}
}
b14_check( 'T1-S14-ext-zero-GET_LOCK-issued',
	0 === $get_locks && 0 === count( $GLOBALS['wpdb']->lock_acquires ) && 0 === count( $GLOBALS['wpdb']->lock_releases ),
	'ext-cache branch issues zero GET_LOCK/RELEASE_LOCK statements (lock skipped entirely)',
	'ext-cache branch touched the named lock: statements=' . $get_locks );

/* ════════════════════════════════════════════════════════════════
 * T1-S15 — static hygiene of the new unit
 * ════════════════════════════════════════════════════════════════ */

b14_check( 'T1-S15-static-hygiene',
	0 === substr_count( $rl_src, "add_filter( 'upload_mimes'" )
		&& 0 === preg_match_all( '/add_filter\s*\(\s*[\'"]upload_mimes/', $rl_src )
		&& 0 === substr_count( $rl_src, 'wp_ajax_' )
		&& 0 === substr_count( $rl_src, 'register_rest_route' ),
	'new unit: no upload_mimes filter, zero AJAX/REST surface (G6 §4-2 / R1 prohibitions)',
	'static hygiene failed on rate-limits.php' );

/* ════════════════════════════════════════════════════════════════
 * Summary
 * ════════════════════════════════════════════════════════════════ */

echo "B14-T1 RESULT: {$GLOBALS['B14_PASS']} pass, 0 skipped, {$GLOBALS['B14_FAIL']} fail (PHP " . PHP_VERSION . ")\n";
if ( $GLOBALS['B14_FAIL'] > 0 ) {
	echo "Failures:\n" . implode( "\n", $GLOBALS['B14_FAILS'] ) . "\n";
	exit( 1 );
}
exit( 0 );
