<?php
/**
 * HAL Frontend Dashboard — Batch 14 harness T3: uploads hardening (G6 §4-1..3).
 *
 * Local, isolated harness: no live WordPress, no network, no database, no
 * browser. Loads the REAL runtime/core/rate-limits.php and the REAL
 * hossam_get_allowed_upload_mimes() from runtime/adapters/wordpress-uploads.php
 * (definitions-only file, zero file-level hooks), then invokes the real
 * registered wp_handle_upload_prefilter callback with $file arrays built from a
 * simulated $_FILES. Only the WordPress boundary is stubbed; no project logic
 * is reimplemented in the harness.
 *
 * Coverage (G6-IO-POSITION.md §4 items 1-3):
 *
 *   SIZE      item 1 — the single official chokepoint (registered once);
 *             default 8388608 bytes; over-limit rejected on BOTH measured
 *             paths (AJAX hossam_upload_file and REST wp/v2/media — both reach
 *             the hook through _wp_handle_upload) with the clear Arabic
 *             message carrying the name and human sizes, BEFORE any write and
 *             with zero quota consumption; the limit value itself passes; the
 *             hossam_uploads_max_file_size filter overrides and 0 disables.
 *   MIME      item 2 — the REAL site∩project intersection
 *             (array_intersect_assoc): a widening project filter can never
 *             expand the site policy (exe attempt dropped), narrowing works,
 *             allowed extension passes both paths, forbidden extension is
 *             rejected on both paths with the literal message; no global
 *             upload_mimes filter exists in the unit.
 *   FILEINFO  item 3 — with the extension absent (this environment's real
 *             baseline) the admin_notices warning fires for manage_options
 *             ONLY; the per-request memo stores the probe result (reflection)
 *             and keeps it stable across repeated calls; enabling the
 *             extension (self-child with -d extension=fileinfo) removes the
 *             notice with no manual step; zero manual fallback detection code;
 *             the alternative behavior is documented in the unit docblock.
 *
 * Usage:  php -d extension=sodium tests/php/batch14-uploads-hardening-test.php  (exit 0 = all pass)
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

/* ── Errors / sanitizers / misc ── */
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
if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( string $name ): string {
		return preg_replace( '/[^A-Za-z0-9.\-_]/', '', $name );
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

/* ── Users / caps ── */
function get_current_user_id(): int {
	return (int) ( $GLOBALS['B14_USER_ID'] ?? 0 );
}
function get_current_blog_id(): int {
	return 1;
}
function current_user_can( string $cap, ...$rest ): bool {
	return ! empty( $GLOBALS['B14_CAPS'][ $cap ] );
}

/* ── Transients (quota counters for the consume path) ── */
$GLOBALS['B14_TRANSIENTS'] = array();
function get_transient( string $key ) {
	return array_key_exists( $key, $GLOBALS['B14_TRANSIENTS'] ) ? $GLOBALS['B14_TRANSIENTS'][ $key ] : false;
}
function set_transient( string $key, $value, int $ttl = 0 ): bool {
	$GLOBALS['B14_TRANSIENTS'][ $key ] = $value;
	return true;
}

/* ── External object cache boundary (present so the consume branch resolves) ── */
function wp_using_ext_object_cache(): bool {
	return (bool) ( $GLOBALS['B14_EXT_CACHE'] ?? false );
}
$GLOBALS['B14_EXT_CACHE']   = false;
$GLOBALS['B14_CACHE_STORE'] = array();
function wp_cache_add( $key, $value, string $group = '', int $ttl = 0 ): bool {
	if ( isset( $GLOBALS['B14_CACHE_STORE'][ $group ][ (string) $key ] ) ) {
		return false;
	}
	$GLOBALS['B14_CACHE_STORE'][ $group ][ (string) $key ] = $value;
	return true;
}
function wp_cache_incr( $key, int $offset = 1, string $group = '' ): int|false {
	if ( ! isset( $GLOBALS['B14_CACHE_STORE'][ $group ][ (string) $key ] ) ) {
		return false;
	}
	$GLOBALS['B14_CACHE_STORE'][ $group ][ (string) $key ] += $offset;
	return $GLOBALS['B14_CACHE_STORE'][ $group ][ (string) $key ];
}

/* ── wpdb boundary (consume lock path) ── */
if ( ! class_exists( 'B14_wpdb' ) ) {
	final class B14_wpdb {
		public string $prefix = 'wp_';
		public string $last_error = '';
		public int $blogid = 1;
		public array $queries = array();
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
			$this->queries[] = (string) $query;
			if ( false !== strpos( (string) $query, 'GET_LOCK' ) ) {
				return 1; // always granted — quota tests here never contend
			}
			return 1;
		}
		public function query( ?string $query = null ) {
			$this->queries[] = (string) $query;
			return 1;
		}
		public function flush(): void {
			$this->last_error = '';
		}
	}
}
$GLOBALS['wpdb'] = new B14_wpdb();

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
 * Load the REAL production units (uploads helper is definitions-only)
 * ════════════════════════════════════════════════════════════════ */

require $GLOBALS['B14_PROJECT'] . '/runtime/adapters/wordpress-uploads.php';
require $GLOBALS['B14_PROJECT'] . '/runtime/core/rate-limits.php';

/* ════════════════════════════════════════════════════════════════
 * Child mode — the fileinfo-enabled request (fresh process)
 * ════════════════════════════════════════════════════════════════ */

if ( isset( $argv[1] ) && str_starts_with( (string) $argv[1], '--b14-child=' ) ) {
	$markers = array(
		'loaded'            => hossam_rate_limit_fileinfo_loaded(),
		'notice_for_admin'  => null,
	);
	$GLOBALS['B14_CAPS']['manage_options'] = true;
	ob_start();
	hossam_rate_limit_fileinfo_notice();
	$markers['notice_for_admin'] = ob_get_clean();
	echo json_encode( $markers );
	exit( 0 );
}

/* ════════════════════════════════════════════════════════════════
 * Check helper + fixture reset
 * ════════════════════════════════════════════════════════════════ */

$GLOBALS['B14_PASS']  = 0;
$GLOBALS['B14_FAIL']  = 0;
$GLOBALS['B14_FAILS'] = array();
$GLOBALS['B14_SKIPPED'] = 0;
$GLOBALS['B14_SKIPS']   = array();

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

// ESC-5 — تكييف بيئي لفرعي fileinfo: skip صريح (ليس PASS) لفحوص الفرع الذي
// تقيسه البيئة التوأم — العدّاد الحقيقي يُطبع في سطر الختام (شرط ESC-5-2أ).
function b14_skip( string $id, string $reason ): void {
	$GLOBALS['B14_SKIPPED']++;
	$GLOBALS['B14_SKIPS'][] = "{$id}: {$reason}";
	echo "SKIP [{$id}] {$reason}\n";
}

function b14_reset(): void {
	$GLOBALS['B14_TRANSIENTS'] = array();
	$GLOBALS['B14_USER_ID']    = 0;
	$GLOBALS['B14_CAPS']       = array();
	$GLOBALS['B14_SITE_MIMES'] = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'pdf'          => 'application/pdf',
	);
	$GLOBALS['wpdb']           = new B14_wpdb();
	$GLOBALS['B14_WRITE_RAN']  = false;
	$GLOBALS['_FILES']         = array();
}

/** Simulate the core building the $file array from $_FILES (wp-admin/includes/file.php shape). */
function b14_file_from_FILES( string $field ): array {
	$f = $GLOBALS['_FILES'][ $field ] ?? array();
	return array(
		'name'     => $f['name'] ?? '',
		'type'     => $f['type'] ?? '',
		'size'     => $f['size'] ?? 0,
		'tmp_name' => $f['tmp_name'] ?? '',
		'error'    => $f['error'] ?? 0,
		'action'   => $f['action'] ?? 'hossam_upload_file',
	);
}

/** The caller-side write step (core proceeds only when no error was set). */
function b14_simulated_write(): void {
	$GLOBALS['B14_WRITE_RAN'] = true;
}

function b14_filter_cb( string $hook ): ?callable {
	$entries = b14_hook_sorted( 'filter', $hook );
	return $entries ? $entries[0]['cb'] : null;
}

function b14_spawn_child(): array {
	$ext_dir = (string) ( getenv( 'HAL_PHP_EXT_DIR' ) ?: ini_get( 'extension_dir' ) );
	if ( '' !== $ext_dir && ! is_dir( $ext_dir ) ) {
		$candidate = dirname( PHP_BINARY ) . DIRECTORY_SEPARATOR . $ext_dir;
		if ( is_dir( $candidate ) ) {
			$ext_dir = $candidate;
		}
	}
	$cmd = escapeshellarg( PHP_BINARY )
		. ' -d extension_dir=' . escapeshellarg( $ext_dir )
		. ' -d extension=fileinfo'
		. ' -d display_errors=1 -d log_errors=0 -d error_reporting=' . escapeshellarg( (string) E_ALL )
		. ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( '--b14-child=fileinfo_on' );
	$out     = shell_exec( $cmd . ' 2>&1' );
	// ESC-5: consume PHP startup warnings (e.g. "Module 'fileinfo' is already
	// loaded" on fileinfo-loaded runners) — the child's JSON payload follows them.
	$out = preg_replace( '/^PHP (Warning|Deprecated|Notice):[^\n]*\n/m', '', (string) $out );
	$decoded = json_decode( (string) $out, true );
	return is_array( $decoded ) ? $decoded : array( '_raw' => (string) $out );
}

/* ════════════════════════════════════════════════════════════════
 * T3-LOAD
 * ════════════════════════════════════════════════════════════════ */

b14_check( 'T3-LOAD-real-units',
	function_exists( 'hossam_rate_limit_upload_prefilter' )
		&& function_exists( 'hossam_get_allowed_upload_mimes' )
		&& function_exists( 'hossam_rate_limit_fileinfo_notice' )
		&& defined( 'HOSSAM_UPLOADS_MAX_FILE_SIZE' ) && 8388608 === HOSSAM_UPLOADS_MAX_FILE_SIZE,
	'real rate-limits.php + real allowed-mimes helper loaded; the default cap constant is 8388608 (8MB)',
	'real units did not load their expected surface' );

$prefilter = b14_filter_cb( 'wp_handle_upload_prefilter' );
b14_check( 'T3-1-chokepoint-registered-once',
	null !== $prefilter && 'hossam_rate_limit_upload_prefilter' === ( is_string( $prefilter ) ? $prefilter : '' )
		&& 1 === count( b14_hook_sorted( 'filter', 'wp_handle_upload_prefilter' ) ),
	'wp_handle_upload_prefilter registered exactly once — the single official chokepoint covering AJAX hossam_upload_file + wp-admin/async-upload + REST wp/v2/media',
	'chokepoint registration deviated: ' . var_export( b14_hook_sorted( 'filter', 'wp_handle_upload_prefilter' ), true ) );

/* ════════════════════════════════════════════════════════════════
 * T3-1 — size limit on BOTH measured paths (AJAX + REST media)
 * ════════════════════════════════════════════════════════════════ */

b14_reset();
$GLOBALS['B14_USER_ID'] = 7;
/* Simulated $_FILES entries for the two paths (same hook, same $file shape). */
$GLOBALS['_FILES']['file'] = array(
	'name'     => 'contract-scan.pdf',
	'type'     => 'application/pdf',
	'size'     => 9437184, // 9MB > 8MB
	'tmp_name' => 'D:/wp-tmp/phpA1.tmp',
	'error'    => 0,
	'action'   => 'hossam_upload_file', // AJAX path context
);
$file_ajax = b14_file_from_FILES( 'file' );
$result_ajax = call_user_func( $prefilter, $file_ajax );
b14_check( 'T3-1-ajax-path-over-limit-rejected',
	isset( $result_ajax['error'] )
		&& false !== strpos( (string) $result_ajax['error'], 'يتجاوز الحد المسموح' )
		&& false !== strpos( (string) $result_ajax['error'], 'contract-scan.pdf' )
		&& false !== strpos( (string) $result_ajax['error'], 'ميغابايت' ),
	'AJAX path (hossam_upload_file): 9MB rejected with the clear Arabic message carrying the file name and both human sizes',
	'AJAX size rejection deviated: ' . var_export( $result_ajax['error'] ?? null, true ) );

$GLOBALS['_FILES']['file'] = array(
	'name'     => 'rest-media.pdf',
	'type'     => 'application/pdf',
	'size'     => 10485760, // 10MB via the editor REST media path
	'tmp_name' => 'D:/wp-tmp/phpB2.tmp',
	'error'    => 0,
	'action'   => 'rest_wp_v2_media', // REST wp/v2/media context (same hook via _wp_handle_upload)
);
$file_rest = b14_file_from_FILES( 'file' );
$result_rest = call_user_func( $prefilter, $file_rest );
b14_check( 'T3-1-rest-path-over-limit-rejected',
	isset( $result_rest['error'] )
		&& false !== strpos( (string) $result_rest['error'], 'يتجاوز الحد المسموح' )
		&& false !== strpos( (string) $result_rest['error'], 'rest-media.pdf' ),
	'REST wp/v2/media path: 10MB rejected by the same single hook with the same Arabic message (one chokepoint, both paths)',
	'REST size rejection deviated: ' . var_export( $result_rest['error'] ?? null, true ) );

b14_check( 'T3-1-rejection-before-write-and-quota',
	false === $GLOBALS['B14_WRITE_RAN']
		&& ! isset( $GLOBALS['B14_TRANSIENTS']['hossam_rl_upload_7'] )
		&& ! isset( $GLOBALS['B14_TRANSIENTS']['hossam_rl_upload_day_7'] ),
	'size rejection happens before any file write and consumes zero quota (no orphan attachment possible from the prefilter itself)',
	'size rejection leaked a write or quota consumption' );

/* The limit value itself passes (strictly greater is rejected). */
b14_reset();
$GLOBALS['B14_USER_ID'] = 7;
$GLOBALS['_FILES']['file'] = array( 'name' => 'exact.pdf', 'size' => 8388608, 'action' => 'hossam_upload_file' );
$result_at = call_user_func( $prefilter, b14_file_from_FILES( 'file' ) );
if ( empty( $result_at['error'] ) ) {
	b14_simulated_write();
}
b14_check( 'T3-1-at-limit-passes',
	empty( $result_at['error'] ) && true === $GLOBALS['B14_WRITE_RAN']
		&& 1 === (int) $GLOBALS['B14_TRANSIENTS']['hossam_rl_upload_7'],
	'a file of exactly 8388608 bytes passes the size gate, proceeds to write, and consumes its quota',
	'at-limit file deviated: ' . var_export( $result_at, true ) );

/* The hossam_uploads_max_file_size filter: overrides down; 0 disables. */
$small_cb = static fn( $value ) => 1000;
add_filter( 'hossam_uploads_max_file_size', $small_cb, 10, 1 );
$GLOBALS['_FILES']['file'] = array( 'name' => 'two-k.pdf', 'size' => 2000, 'action' => 'hossam_upload_file' );
$result_small = call_user_func( $prefilter, b14_file_from_FILES( 'file' ) );
remove_filter( 'hossam_uploads_max_file_size', $small_cb, 10 );
b14_check( 'T3-1-filter-overrides-down',
	isset( $result_small['error'] ) && false !== strpos( (string) $result_small['error'], 'يتجاوز الحد المسموح' )
		&& false !== strpos( (string) $result_small['error'], 'كيلوبايت' ),
	'the hossam_uploads_max_file_size filter overrides the cap down (2000B vs 1000 ⇒ rejected, kilobyte wording)',
	'filter override deviated: ' . var_export( $result_small['error'] ?? null, true ) );

$zero_cb = static fn( $value ) => 0;
add_filter( 'hossam_uploads_max_file_size', $zero_cb, 10, 1 );
$GLOBALS['_FILES']['file'] = array( 'name' => 'big-but-allowed.pdf', 'size' => 10485760, 'action' => 'hossam_upload_file' );
$result_zero = call_user_func( $prefilter, b14_file_from_FILES( 'file' ) );
remove_filter( 'hossam_uploads_max_file_size', $zero_cb, 10 );
b14_check( 'T3-1-filter-zero-disables',
	empty( $result_zero['error'] ),
	'filter value 0 disables the size limit entirely (10MB passes the size gate)',
	'zero-disable deviated: ' . var_export( $result_zero['error'] ?? null, true ) );

/* ════════════════════════════════════════════════════════════════
 * T3-2 — unified MIME intersection (REAL hossam_get_allowed_upload_mimes)
 * ════════════════════════════════════════════════════════════════ */

b14_reset();
$GLOBALS['B14_USER_ID'] = 7;
/* A widening project filter can NEVER expand the site policy (intersect_assoc). */
$widen_cb = static fn( $value ) => array(
	'jpg|jpeg|jpe' => 'image/jpeg',
	'png'          => 'image/png',
	'pdf'          => 'application/pdf',
	'exe'          => 'application/x-msdownload',
);
add_filter( 'hossam_uploads_allowed_mimes', $widen_cb, 10, 1 );
$intersection = hossam_get_allowed_upload_mimes();
remove_filter( 'hossam_uploads_allowed_mimes', $widen_cb, 10 );
b14_check( 'T3-2-intersection-never-expands',
	array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf' ) === $intersection
		&& ! isset( $intersection['exe'] ),
	'the REAL intersection drops the widening attempt: exe never enters the effective policy (array_intersect_assoc, wordpress-uploads.php:43 contract)',
	'intersection expanded: ' . var_export( $intersection, true ) );

/* Narrowing works. */
$narrow_cb = static fn( $value ) => array( 'pdf' => 'application/pdf' );
add_filter( 'hossam_uploads_allowed_mimes', $narrow_cb, 10, 1 );
$narrowed = hossam_get_allowed_upload_mimes();
remove_filter( 'hossam_uploads_allowed_mimes', $narrow_cb, 10 );
b14_check( 'T3-2-intersection-narrows',
	array( 'pdf' => 'application/pdf' ) === $narrowed,
	'the project filter narrows the effective policy to pdf only',
	'narrowing deviated: ' . var_export( $narrowed, true ) );

/* Allowed extension passes BOTH paths; forbidden rejected on BOTH. */
$GLOBALS['_FILES']['file'] = array( 'name' => 'photo.jpg', 'size' => 500, 'action' => 'hossam_upload_file' );
$ok_ajax = call_user_func( $prefilter, b14_file_from_FILES( 'file' ) );
$GLOBALS['_FILES']['file'] = array( 'name' => 'photo-2.jpg', 'size' => 500, 'action' => 'rest_wp_v2_media' );
$ok_rest = call_user_func( $prefilter, b14_file_from_FILES( 'file' ) );
b14_check( 'T3-2-allowed-passes-both-paths',
	empty( $ok_ajax['error'] ) && empty( $ok_rest['error'] )
		&& 2 === (int) $GLOBALS['B14_TRANSIENTS']['hossam_rl_upload_7'],
	'allowed extension (jpg inside the intersection) passes both paths and each consumes its quota unit (no double counting per file)',
	'allowed-path handling deviated: ' . var_export( array( $ok_ajax, $ok_rest ), true ) );

$GLOBALS['_FILES']['file'] = array( 'name' => 'evil.exe', 'size' => 500, 'action' => 'hossam_upload_file' );
$bad_ajax = call_user_func( $prefilter, b14_file_from_FILES( 'file' ) );
$GLOBALS['_FILES']['file'] = array( 'name' => 'evil-2.exe', 'size' => 500, 'action' => 'rest_wp_v2_media' );
$bad_rest = call_user_func( $prefilter, b14_file_from_FILES( 'file' ) );
b14_check( 'T3-2-forbidden-rejected-both-paths',
	isset( $bad_ajax['error'] ) && 'نوع الملف غير مسموح.' === $bad_ajax['error']
		&& isset( $bad_rest['error'] ) && 'نوع الملف غير مسموح.' === $bad_rest['error'],
	'forbidden extension (outside the intersection) rejected on both paths with the identical literal message',
	'forbidden-path handling deviated: ' . var_export( array( $bad_ajax['error'] ?? null, $bad_rest['error'] ?? null ), true ) );

$rl_src = (string) file_get_contents( $GLOBALS['B14_PROJECT'] . '/runtime/core/rate-limits.php' );
b14_check( 'T3-2-no-global-upload_mimes-filter',
	0 === substr_count( $rl_src, "add_filter( 'upload_mimes'" )
		&& 0 === preg_match_all( '/add_filter\s*\(\s*[\'"]upload_mimes/', $rl_src ),
	'static (two ways): the unit registers no global upload_mimes filter — the unified intersection lives inside the prefilter only (§ب prohibition)',
	'global upload_mimes filter found in rate-limits.php' );

/* ════════════════════════════════════════════════════════════════
 * T3-3 — fileinfo disclosure (ESC-5: environment-adaptive two-branch design)
 * The behavior is two-state by environment: fileinfo ABSENT ⇒ the admin
 * notice fires (G6 §4-3); PRESENT ⇒ silent. CI (ubuntu, php8.3-common)
 * loads fileinfo; Windows-local lacks it. Each environment measures its
 * reachable branch; the other branch is SKIP-tagged (measured on the twin
 * environment) — the union covers the full contract. Detection: read in the
 * measured environment itself (extension_loaded) cross-asserted against a
 * lib-mirror probe child (same binary, same base, no fileinfo flag) —
 * no parallel environment read (ESC-5-2ب).
 * ════════════════════════════════════════════════════════════════ */

$b14_probe_out     = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( 'echo extension_loaded("fileinfo") ? "F1" : "F0";' ) . ' 2>&1' );
$fileinfo_present  = false !== strpos( $b14_probe_out, 'F1' ) && false === strpos( $b14_probe_out, 'Fatal error' );
b14_check( 'T3-3-detection-agreement',
	$fileinfo_present === extension_loaded( 'fileinfo' ),
	'ESC-5 detection: the lib-mirror probe and the measured environment agree (no parallel environment read — ESC-5-2ب)',
	'detection deviated: probe=' . var_export( $fileinfo_present, true ) . ' parent=' . var_export( extension_loaded( 'fileinfo' ), true ) );

$notice_entries = b14_hook_sorted( 'action', 'admin_notices' );
b14_check( 'T3-3-notice-registered-once',
	1 === count( $notice_entries ) && 'hossam_rate_limit_fileinfo_notice' === ( is_string( $notice_entries[0]['cb'] ) ? $notice_entries[0]['cb'] : '' ),
	'admin_notices carries exactly one listener from the unit',
	'notice registration deviated: ' . var_export( $notice_entries, true ) );

if ( $fileinfo_present ) {
	/* Present-branch, measured natively here: probe reports loaded; notice stays silent. */
	b14_check( 'T3-3-extension-present-baseline',
		extension_loaded( 'fileinfo' ) && true === hossam_rate_limit_fileinfo_loaded(),
		'environment baseline: fileinfo is genuinely present — the disclosure path stays silent for real, no stubbed state',
		'fileinfo unexpectedly absent — the presence test is not meaningful here' );

	$GLOBALS['B14_CAPS']['manage_options'] = true;
	ob_start();
	hossam_rate_limit_fileinfo_notice();
	$admin_notice = (string) ob_get_clean();
	$GLOBALS['B14_CAPS'] = array(); // editor: no manage_options
	ob_start();
	hossam_rate_limit_fileinfo_notice();
	$editor_notice = (string) ob_get_clean();
	b14_check( 'T3-3-notice-silent-when-present',
		'' === $admin_notice && '' === $editor_notice,
		'with the extension present: zero notice output for admins and non-admins alike — the disclosure exists only for the absent state (G6 §4-3)',
		'notice audience deviated: admin=' . var_export( $admin_notice, true ) . ' editor=' . var_export( $editor_notice, true ) );

	b14_skip( 'T3-3-extension-absent-baseline', 'fileinfo present — absent-branch measured on fileinfo-less runners (ESC-5)' );
	b14_skip( 'T3-3-notice-manager-only', 'fileinfo present — absent-branch measured on fileinfo-less runners (ESC-5)' );
} else {
	b14_check( 'T3-3-extension-absent-baseline',
		! extension_loaded( 'fileinfo' ) && false === hossam_rate_limit_fileinfo_loaded(),
		'environment baseline: the fileinfo extension is genuinely absent in this PHP — the disclosure path is exercised for real, no stubbed state',
		'fileinfo unexpectedly present — the absence test is not meaningful here' );

	$GLOBALS['B14_CAPS']['manage_options'] = true;
	ob_start();
	hossam_rate_limit_fileinfo_notice();
	$admin_notice = (string) ob_get_clean();
	$GLOBALS['B14_CAPS'] = array(); // editor: no manage_options
	ob_start();
	hossam_rate_limit_fileinfo_notice();
	$editor_notice = (string) ob_get_clean();
	b14_check( 'T3-3-notice-manager-only',
		false !== strpos( $admin_notice, 'notice notice-warning' )
			&& false !== strpos( $admin_notice, 'fileinfo' )
			&& '' === $editor_notice,
		'with the extension absent: the warning notice renders for manage_options ONLY; non-admins get zero output',
		'notice audience deviated: admin=' . var_export( $admin_notice, true ) . ' editor=' . var_export( $editor_notice, true ) );

	b14_skip( 'T3-3-extension-present-baseline', 'fileinfo absent — present-branch measured natively on fileinfo-loaded runners (ESC-5)' );
	b14_skip( 'T3-3-notice-silent-when-present', 'fileinfo absent — present-branch measured natively on fileinfo-loaded runners (ESC-5)' );
}

/* Per-request memo (adaptive — one check, both branches): the stored value
 * equals this environment's probe and is stable across repeated calls. */
$reflection  = new ReflectionFunction( 'hossam_rate_limit_fileinfo_loaded' );
$static_vars = $reflection->getStaticVariables();
$memo_stable = hossam_rate_limit_fileinfo_loaded() === hossam_rate_limit_fileinfo_loaded()
	&& hossam_rate_limit_fileinfo_loaded() === hossam_rate_limit_fileinfo_loaded();
b14_check( 'T3-3-memo-stored-and-stable',
	array_key_exists( 'loaded', $static_vars ) && $static_vars['loaded'] === hossam_rate_limit_fileinfo_loaded() && $memo_stable,
	'the per-request memo stored this environment\'s probe result (static $loaded === the live probe via reflection) and repeated calls return it unchanged — the probe runs once per request',
	'memo deviated: static=' . var_export( $static_vars, true ) . ' stable=' . var_export( $memo_stable, true ) );

/* No manual fallback detection code. */
b14_check( 'T3-3-no-manual-fallback-detection',
	0 === substr_count( $rl_src, 'finfo' )
		&& 0 === preg_match_all( '/finfo_|class_exists\s*\(\s*[\'"]finfo/', $rl_src )
		&& false === strpos( $rl_src, 'mime_content_type' ),
	'static: zero manual detection fallbacks (finfo/mime_content_type) — the disclosure is the only mechanism (G6 §4-3)',
	'manual fallback detection found in rate-limits.php' );

/* The alternative behavior is documented in the unit docblock. */
b14_check( 'T3-3-alternative-behavior-documented',
	false !== strpos( $rl_src, 'wp_check_filetype_and_ext' ) && false !== strpos( $rl_src, 'السلوك البديل الموثق' ),
	'the unit docblock documents the fallback behavior when fileinfo is absent (deep check leans on the stored extension; the deny-list remains)',
	'docblock documentation missing' );

/* Enabling the extension removes the notice with no manual step (fresh request). */
$child = b14_spawn_child();
b14_check( 'T3-3-enabled-extension-removes-notice',
	true === ( $child['loaded'] ?? null ) && '' === ( $child['notice_for_admin'] ?? 'x' ),
	'child request with -d extension=fileinfo: probe=true and the admin notice is empty — enabling the extension removes the disclosure with zero manual action',
	'extension-enabled child deviated: ' . json_encode( $child ) );

/* ════════════════════════════════════════════════════════════════
 * Summary
 * ════════════════════════════════════════════════════════════════ */

echo "B14-T3 RESULT: {$GLOBALS['B14_PASS']} pass, {$GLOBALS['B14_SKIPPED']} skipped, {$GLOBALS['B14_FAIL']} fail (PHP " . PHP_VERSION . ")\n";
if ( $GLOBALS['B14_SKIPPED'] > 0 ) {
	echo "Skipped:\n" . implode( "\n", $GLOBALS['B14_SKIPS'] ) . "\n";
}
if ( $GLOBALS['B14_FAIL'] > 0 ) {
	echo "Failures:\n" . implode( "\n", $GLOBALS['B14_FAILS'] ) . "\n";
	exit( 1 );
}
exit( 0 );
