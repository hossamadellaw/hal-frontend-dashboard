<?php
/**
 * HAL Frontend Dashboard — Batch 14 harness T5: IO hardening (G6 §4 items 4-5, 7-9).
 *
 * Local, isolated harness: no live WordPress, no network, no database, no
 * browser. Loads the REAL runtime units (audit-log, wordpress-posts,
 * rankmath, class-secret-store) and invokes the real functions through the
 * real registered hook callbacks. Only the WordPress boundary is stubbed;
 * no project logic is reimplemented in the harness.
 *
 * Coverage (G6-IO-POSITION.md §4 — الجزء القابل للقياس محليًا الآن):
 *
 *   ITEM 4    — dead code: `hossam_ai_verify_links` deleted — ZERO
 *               occurrences (definition and calls) across the repo surface
 *               (runtime/ + includes/ + mu-loader/ + main file), proven by
 *               two independent scans (D1 lesson: substr_count + preg).
 *   ITEM 5    — persist error code: `hal_secret_persist_failed` present in
 *               the persist branch of set() (static region extraction), the
 *               old decrypt-code no longer returned from that branch, and
 *               the behavioral path: update_option failure ⇒ WP_Error
 *               hal_secret_persist_failed with zero bytes written; success
 *               path still round-trips through real Sodium AEAD.
 *   ITEM 7    — static 404 oracle: every remaining literal-403
 *               wp_send_json_error site in the three endpoint files
 *               (posts/uploads/seo) is enumerated and classified into the
 *               authorized set only (feature_disabled gates / translate
 *               internal ownership branch / B3-08 files gate) — zero
 *               unauthorized 403 remains in the seven points.
 *   ITEM 8    — extended robots values drop without breaking: sanitize_key
 *               + closed-list intersection keeps only the supported values,
 *               an exclusively-unsupported selection fails false with ZERO
 *               meta writes (silent-save guard).
 *   ITEM 9    — post-kses H1 valve (server-side measurable part): the valve
 *               reads the post-kses payload (kses proxy runs first — static
 *               order in both create/update bodies); reject ⇒ clean 400
 *               hossam_h1_rejected BEFORE any write; normalize ⇒ converts
 *               h1→h2 through the real wp_insert_post_data handler and the
 *               toolbar tags round-trip the save path unchanged (local
 *               proxy: keep-list kses); off ⇒ no check, h1 stored as-is.
 *
 *   DEFERRED (T5 stays open — دو المراحل بعقد القائمة):
 *     - Client round-trip part (R18-R20) — يُستكمل عند المرحلة 3.
 *     - The REAL wp_kses_post allowlist round-trip and the REST-media path
 *       of the size gate — live acceptance on the owner's WordPress
 *       (core 6.7.1 semantics are read-certified, not run here).
 *
 * Usage:  php -d extension=sodium tests/php/batch14-io-hardening-test.php  (exit 0 = all pass)
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

if ( PHP_SAPI !== 'cli' ) {
	echo "This harness must run under CLI PHP.\n";
	exit( 1 );
}

$GLOBALS['B14_PROJECT'] = dirname( __DIR__, 2 );
$GLOBALS['B14_ERRLOG']  = ( getenv( 'TMP' ) ?: sys_get_temp_dir() ) . '/b14-io-error.log';

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
function has_filter( string $hook ): bool {
	return ! empty( $GLOBALS['B14_HOOKS']['filter'][ $hook ] );
}
function has_action( string $hook ): bool {
	return ! empty( $GLOBALS['B14_HOOKS']['action'][ $hook ] );
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
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $str ): string {
		return trim( strip_tags( (string) $str ) );
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
if ( ! function_exists( '__' ) ) {
	function __( string $s, ?string $domain = null ): string {
		return $s;
	}
}

/**
 * Boundary proxy for wp_kses_post — keep-list semantics for the toolbar
 * round-trip: disallowed element TAGS are removed (text content kept, the
 * real kses behavior), on* handler attributes and javascript: URLs are
 * dropped, and every documented toolbar tag (G6 §3-أ-3: u/s/mark/hr/
 * table/thead/tbody/tr/th/td + strong/em + h1/h2/p) passes. h1 is KEPT —
 * it is in the core $allowedposttags (G6 §3-أ-2) so the post-kses valve
 * sees it, exactly as on production. The REAL allowlist round-trip stays
 * live-acceptance (deferred marker in the summary).
 */
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $content ): string {
		$c = (string) $content;
		$c = preg_replace( '#</?(script|iframe|style)\b[^>]*>#si', '', $c );
		$c = preg_replace( '/\son[a-z]+\s*=\s*(["\']).*?\1/si', '', $c );
		$c = preg_replace( '/\son[a-z]+\s*=\s*[^\s>]+/si', '', $c );
		$c = preg_replace( '/(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2/si', '$1=$2$2', $c );
		return $c;
	}
}
if ( ! function_exists( 'safecss_filter_attr' ) ) {
	function safecss_filter_attr( $css ): string {
		return (string) $css; // boundary proxy — style attribute kept for the round-trip proxy
	}
}

/* ── Users / caps ── */
function get_current_user_id(): int {
	return (int) ( $GLOBALS['B14_USER_ID'] ?? 0 );
}
if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( int $user_id ) {
		return $GLOBALS['B14_USERS'][ $user_id ] ?? false;
	}
}
function current_user_can( string $cap, ...$rest ): bool {
	return ! empty( $GLOBALS['B14_CAPS'][ $cap ] );
}

/* ── Options store (store + write control) ── */
$GLOBALS['B14_OPTIONS']       = array();
$GLOBALS['B14_OPTION_WRITES'] = array();
$GLOBALS['B14_OPT_WRITE_FAIL'] = false;

function get_option( string $option, $default_value = false ) {
	return array_key_exists( $option, $GLOBALS['B14_OPTIONS'] ) ? $GLOBALS['B14_OPTIONS'][ $option ] : $default_value;
}
function update_option( string $option, $value, $autoload = null ): bool {
	$GLOBALS['B14_OPTION_WRITES'][] = array( 'option' => $option, 'value' => $value, 'autoload' => $autoload );
	if ( ! empty( $GLOBALS['B14_OPT_WRITE_FAIL'] ) ) {
		return false;
	}
	$GLOBALS['B14_OPTIONS'][ $option ] = $value;
	return true;
}
function add_option( string $option, $value, $autoload = null ): bool {
	$GLOBALS['B14_OPTION_WRITES'][] = array( 'option' => $option, 'value' => $value, 'autoload' => $autoload );
	if ( array_key_exists( $option, $GLOBALS['B14_OPTIONS'] ) ) {
		return false;
	}
	$GLOBALS['B14_OPTIONS'][ $option ] = $value;
	return true;
}

/* ── wpdb boundary (INSERT capture for the audit rows) ── */
if ( ! class_exists( 'B14_wpdb' ) ) {
	final class B14_wpdb {
		public string $prefix     = 'wp_';
		public string $last_error = '';
		public array $queries     = array();

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
			$this->queries[] = (string) $query;
			return 1;
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

/* ── ensure_table / cron boundaries (audit-log init path) ── */
$GLOBALS['B14_ENSURE_CALLS'] = array();
function hossam_dashboard_ensure_table( array $definition ): void {
	$GLOBALS['B14_ENSURE_CALLS'][] = $definition;
	$GLOBALS['B14_OPTIONS'][ $definition['option'] ] = $definition['version'];
}
function wp_next_scheduled( string $hook, array $args = array() ): bool {
	return false;
}
function wp_schedule_event( int $timestamp, string $recurrence, string $hook ): bool {
	return true;
}

/* ── Core post boundary (create/update path) ── */
$GLOBALS['B14_INSERTS']       = array();
$GLOBALS['B14_DELETED_POSTS'] = array();
$GLOBALS['B14_NEXT_ID']       = 5001;

function wp_insert_post( array $postarr, bool $wp_error = false ) {
	// Core contract: wp_insert_post_data is applied to the payload before the write.
	$data = apply_filters( 'wp_insert_post_data', $postarr, $postarr );
	$id   = $GLOBALS['B14_NEXT_ID']++;
	$data['ID'] = $id;
	$GLOBALS['B14_INSERTS'][] = $data;
	return $id;
}
function wp_update_post( array $postarr, bool $wp_error = false ) {
	$data = apply_filters( 'wp_insert_post_data', $postarr, $postarr );
	$GLOBALS['B14_INSERTS'][] = $data;
	return (int) ( $data['ID'] ?? 0 );
}
function wp_delete_post( int $post_id, bool $force = false ) {
	$GLOBALS['B14_DELETED_POSTS'][] = $post_id;
	return true;
}
function wp_trash_post( int $post_id ) {
	return true;
}
function wp_untrash_post( int $post_id ) {
	return true;
}
function wp_set_post_categories( int $post_id, array $categories = array() ): array {
	return $categories;
}
function wp_get_post_categories( int $post_id ): array {
	return array();
}
function get_post_thumbnail_id( int $post_id ): int {
	return 0;
}
function set_post_thumbnail( int $post_id, int $thumbnail_id ): bool {
	return true;
}
function delete_post_thumbnail( int $post_id ): bool {
	return true;
}
function wp_attachment_is_image( int $attachment_id ): bool {
	return true;
}
function term_exists( $term, $taxonomy = null ) {
	return 5;
}

/* ── error_log capture (ini-based) ── */
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
 * Load the REAL production units
 * ════════════════════════════════════════════════════════════════ */

require $GLOBALS['B14_PROJECT'] . '/runtime/core/audit-log.php';
require $GLOBALS['B14_PROJECT'] . '/runtime/adapters/wordpress-posts.php';
require $GLOBALS['B14_PROJECT'] . '/runtime/adapters/rankmath.php';
require $GLOBALS['B14_PROJECT'] . '/runtime/security/class-secret-store.php';

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

/** Two-way zero-scan over a directory tree (D1 lesson: two independent methods). */
function b14_scan_zero( string $root, string $needle, array &$files_with_hits ): array {
	$raw_hits  = 0;
	$preg_hits = 0;
	$scanned   = 0;
	$files_with_hits = array();
	$rit = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $rit as $file ) {
		if ( ! $file->isFile() ) {
			continue;
		}
		$scanned++;
		$src = (string) file_get_contents( $file->getPathname() );
		$n_raw  = substr_count( $src, $needle );
		$n_preg = preg_match_all( '/' . preg_quote( $needle, '/' ) . '/', $src );
		if ( $n_raw > 0 ) {
			$raw_hits += $n_raw;
			$files_with_hits[] = 'raw:' . $file->getPathname();
		}
		if ( $n_preg > 0 ) {
			$preg_hits += $n_preg;
			$files_with_hits[] = 'preg:' . $file->getPathname();
		}
	}
	return array( 'scanned' => $scanned, 'raw' => $raw_hits, 'preg' => $preg_hits );
}

/* ════════════════════════════════════════════════════════════════
 * T5-LOAD
 * ════════════════════════════════════════════════════════════════ */

b14_check( 'T5-LOAD-real-units',
	function_exists( 'hossam_create_article' )
		&& function_exists( 'hossam_article_h1_should_reject' )
		&& function_exists( 'hossam_save_rankmath_seo_data' )
		&& function_exists( 'hossam_get_allowed_rank_math_robots' )
		&& class_exists( 'HAL_Frontend_Dashboard_Secret_Store' ),
	'real audit-log/wordpress-posts/rankmath/class-secret-store loaded with their public surfaces',
	'real units did not load their expected surfaces' );

/* ════════════════════════════════════════════════════════════════
 * T5-G6-4 — dead code: hossam_ai_verify_links deleted (two ways)
 * ════════════════════════════════════════════════════════════════ */

$dead_roots = array(
	$GLOBALS['B14_PROJECT'] . '/runtime',
	$GLOBALS['B14_PROJECT'] . '/includes',
	$GLOBALS['B14_PROJECT'] . '/mu-loader',
);
$dead_total = array( 'scanned' => 0, 'raw' => 0, 'preg' => 0 );
$dead_files = array();
foreach ( $dead_roots as $root ) {
	if ( ! is_dir( $root ) ) {
		continue;
	}
	$hits = array();
	$res  = b14_scan_zero( $root, 'hossam_ai_verify_links', $hits );
	$dead_total['scanned'] += $res['scanned'];
	$dead_total['raw']     += $res['raw'];
	$dead_total['preg']    += $res['preg'];
	$dead_files = array_merge( $dead_files, $hits );
}
$main_src = (string) file_get_contents( $GLOBALS['B14_PROJECT'] . '/hal-frontend-dashboard.php' );
$dead_total['scanned']++;
$dead_total['raw']  += substr_count( $main_src, 'hossam_ai_verify_links' );
$dead_total['preg'] += (int) preg_match_all( '/hossam_ai_verify_links/', $main_src );

b14_check( 'T5-G6-4-dead-code-verify-links-two-ways',
	function_exists( 'hossam_create_article' ) && ! function_exists( 'hossam_ai_verify_links' )
		&& 0 === $dead_total['raw'] && 0 === $dead_total['preg'] && $dead_total['scanned'] > 0
		&& array() === $dead_files,
	"hossam_ai_verify_links has ZERO occurrences (definition and calls) across runtime/+includes/+mu-loader/+main ({$dead_total['scanned']} files) by two independent scans (substr_count + preg_match_all) — G6 §4-4",
	'dead code still present: ' . var_export( $dead_total, true ) . ' files=' . json_encode( $dead_files ) );

/* ════════════════════════════════════════════════════════════════
 * T5-G6-5 — persist error code (hal_secret_persist_failed)
 * ════════════════════════════════════════════════════════════════ */

$ss_path = $GLOBALS['B14_PROJECT'] . '/runtime/security/class-secret-store.php';
$ss_src  = (string) file_get_contents( $ss_path );

/* Static: the constant exists and the persist branch returns ONLY the new code. */
$persist_pos = strpos( $ss_src, 'if ( ! update_option( self::OPTION_NAME' );
$set_audit_pos = false !== $persist_pos ? strpos( $ss_src, "self::audit( 'set'", $persist_pos ) : false;
$persist_region = ( false !== $persist_pos && false !== $set_audit_pos )
	? substr( $ss_src, $persist_pos, $set_audit_pos - $persist_pos )
	: '';
b14_check( 'T5-G6-5-persist-branch-static',
	false !== strpos( $ss_src, "const ERR_PERSIST_FAILED = 'hal_secret_persist_failed';" )
		&& '' !== $persist_region
		&& false !== strpos( $persist_region, 'self::ERR_PERSIST_FAILED' )
		&& false === strpos( $persist_region, 'ERR_DECRYPT_FAILED' ),
	'the set() persist branch returns the dedicated code hal_secret_persist_failed; the old decrypt code is no longer returned from that branch (G6 §4-5)',
	'persist branch deviated: region_extracted=' . var_export( '' !== $persist_region, true )
		. ' has_new=' . var_export( false !== strpos( $persist_region, 'self::ERR_PERSIST_FAILED' ), true )
		. ' has_old=' . var_export( false !== strpos( $persist_region, 'ERR_DECRYPT_FAILED' ), true ) );

/* Behavioral: update_option failure ⇒ WP_Error hal_secret_persist_failed, zero bytes stored. */
putenv( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY=' . str_repeat( 'a1b2', 16 ) ); // 64 hex chars → valid 32-byte key (harness fixture only)
$ss_hook_calls = array();
$ss_hook_cb    = static function( $entry ) use ( &$ss_hook_calls ) {
	$ss_hook_calls[] = $entry;
};
add_action( 'hal_frontend_dashboard_secret_audit', $ss_hook_cb, 10, 1 );

b14_errlog_start();
$GLOBALS['B14_OPT_WRITE_FAIL'] = true;
$persist_fail = HAL_Frontend_Dashboard_Secret_Store::set( 'ai_key', 'unit-secret-value' );
$GLOBALS['B14_OPT_WRITE_FAIL'] = false;
$persist_err = b14_errlog_take();
b14_check( 'T5-G6-5-persist-failure-behavioral',
	is_wp_error( $persist_fail ) && 'hal_secret_persist_failed' === $persist_fail->get_error_code()
		&& 'Persisting the encrypted secret failed.' === $persist_fail->get_error_message()
		&& ! isset( $GLOBALS['B14_OPTIONS'][ HAL_Frontend_Dashboard_Secret_Store::OPTION_NAME ] )
		&& 0 === count( $ss_hook_calls )
		&& false === strpos( $persist_err, 'secret audit' ),
	'failed persist ⇒ WP_Error hal_secret_persist_failed, no option row, no audit set event, no success log',
	'persist failure path deviated: ' . var_export( $persist_fail, true )
		. ' stored=' . var_export( isset( $GLOBALS['B14_OPTIONS'][ HAL_Frontend_Dashboard_Secret_Store::OPTION_NAME ] ), true )
		. ' hooks=' . count( $ss_hook_calls ) );

/* Success path still round-trips through real Sodium AEAD and fires the set audit. */
$persist_ok = HAL_Frontend_Dashboard_Secret_Store::set( 'ai_key', 'unit-secret-value' );
$read_back  = HAL_Frontend_Dashboard_Secret_Store::get( 'ai_key' );
b14_check( 'T5-G6-5-persist-success-roundtrip',
	true === $persist_ok && 'unit-secret-value' === $read_back
		&& 1 === count( $ss_hook_calls ) && 'set' === $ss_hook_calls[0]['action'] && 'ai_key' === $ss_hook_calls[0]['secret_id'],
	'successful persist stores the AEAD record and get() decrypts the same value; audit(set) fired once',
	'persist success path deviated: set=' . var_export( $persist_ok, true ) . ' get=' . var_export( $read_back, true )
		. ' hooks=' . count( $ss_hook_calls ) );

/* ════════════════════════════════════════════════════════════════
 * T5-G6-7 — static 404 oracle: enumerate + classify remaining 403s
 * ════════════════════════════════════════════════════════════════ */

/**
 * Classify every literal-403 wp_send_json_error site in one endpoint file.
 * Authorized categories only (H3 §1.5-2/§1.5-3 + task manifest):
 *   feature_disabled   — the upper 403 feature gate (posts.php / uploads.php B3-08)
 *   translate_internal — the translate internal ownership/membership branch (posts.php)
 * Every other 403 direct call is unauthorized and fails the check.
 */
function b14_t5_classify_403( string $path, array $authorized_categories ): array {
	$src        = (string) file_get_contents( $path );
	$lines      = preg_split( '/\r\n|\r|\n/', $src ); // byte-safe split (never \R: it would split inside UTF-8 Arabic bytes)
	$direct     = array();
	$unauthorized = array();
	foreach ( $lines as $idx => $line ) {
		if ( false === strpos( $line, '403' ) ) {
			continue;
		}
		$trim = ltrim( $line );
		if ( '' === $trim || '*' === $trim[0] || '//' === substr( $trim, 0, 2 ) ) {
			continue; // docblock/comment mention
		}
		if ( 1 !== preg_match( '/wp_send_json_error\(([^\n]*?),\s*403\s*\)/', $line ) ) {
			continue; // not a direct literal-403 error call (ternary arms handled below)
		}
		$category = 'unknown';
		if ( false !== strpos( $line, 'feature_disabled' ) ) {
			$category = 'feature_disabled';
		} elseif ( false !== strpos( $line, 'translation_forbidden' ) || false !== strpos( $line, 'translation_mismatch' )
			|| false !== strpos( $line, 'permission to edit this translation' ) ) {
			$category = 'translate_internal';
		}
		$direct[] = array( 'line' => $idx + 1, 'category' => $category );
		if ( ! in_array( $category, $authorized_categories, true ) ) {
			$unauthorized[] = $idx + 1;
		}
	}
	/* The $status-ternary/assignment 403 arms (translation-layer mappings, dead
	 * for the unified points — behaviorally proven 404 in
	 * batch14-security-closures-test.php). */
	$ternary_403 = array();
	foreach ( $lines as $idx => $line ) {
		if ( 1 === preg_match( '/\?\s*403\s*:/', $line ) || 1 === preg_match( '/=\s*403\s*;/', $line ) ) {
			$ternary_403[] = array( 'line' => $idx + 1, 'maps' => false !== strpos( $line, 'hossam_forbidden' ) ? 'hossam_forbidden-map' : 'other' );
		}
	}
	return array( 'direct' => $direct, 'unauthorized' => $unauthorized, 'ternary' => $ternary_403 );
}

$ajax_dir      = $GLOBALS['B14_PROJECT'] . '/runtime/ajax/';
$posts_class   = b14_t5_classify_403( $ajax_dir . 'posts.php', array( 'feature_disabled', 'translate_internal' ) );
$uploads_class = b14_t5_classify_403( $ajax_dir . 'uploads.php', array( 'feature_disabled' ) );
$seo_class     = b14_t5_classify_403( $ajax_dir . 'seo.php', array() );

$posts_feature  = count( array_filter( $posts_class['direct'], static fn( $d ) => 'feature_disabled' === $d['category'] ) );
$posts_internal = count( array_filter( $posts_class['direct'], static fn( $d ) => 'translate_internal' === $d['category'] ) );
$uploads_feature = count( array_filter( $uploads_class['direct'], static fn( $d ) => 'feature_disabled' === $d['category'] ) );

b14_check( 'T5-G6-7-static-403-classified',
	5 === $posts_feature && 4 === $posts_internal && array() === $posts_class['unauthorized']
		&& 4 === $uploads_feature && array() === $uploads_class['unauthorized']
		&& 0 === count( $seo_class['direct'] ) && 0 === count( $seo_class['ternary'] )
		&& 3 === count( $posts_class['ternary'] ) && 1 === count( $uploads_class['ternary'] ),
	"seven-point 403 inventory closed: posts.php = 5 feature_disabled + 4 translate-internal (authorized) + 3 dead/authorized ternary arms; uploads.php = 4 B3-08 feature gates + 1 upload_file map; seo.php = ZERO 403 — no unauthorized 403 remains in the seven points (G6 §4-7 / H3 §1.5-2)",
	'403 classification deviated: posts_direct=' . json_encode( $posts_class['direct'] )
		. ' posts_unauth=' . json_encode( $posts_class['unauthorized'] )
		. ' posts_ternary=' . json_encode( $posts_class['ternary'] )
		. ' uploads_direct=' . json_encode( $uploads_class['direct'] )
		. ' uploads_unauth=' . json_encode( $uploads_class['unauthorized'] )
		. ' uploads_ternary=' . json_encode( $uploads_class['ternary'] )
		. ' seo_direct=' . json_encode( $seo_class['direct'] ) );

/* ════════════════════════════════════════════════════════════════
 * T5-G6-8 — extended robots values drop without breaking (rankmath)
 * ════════════════════════════════════════════════════════════════ */

$GLOBALS['B14_META'] = array();
function get_post_meta( int $post_id, string $meta_key, bool $single = false ) {
	return $GLOBALS['B14_META'][ $post_id ][ $meta_key ] ?? '';
}
function update_post_meta( int $post_id, string $meta_key, $meta_value ) {
	$GLOBALS['B14_META'][ $post_id ][ $meta_key ] = $meta_value;
	return true;
}
function delete_post_meta( int $post_id, string $meta_key ): bool {
	unset( $GLOBALS['B14_META'][ $post_id ][ $meta_key ] );
	return true;
}
function metadata_exists( string $meta_type, int $post_id, string $meta_key ): bool {
	return isset( $GLOBALS['B14_META'][ $post_id ][ $meta_key ] );
}

/* Baseline list is the closed four. */
b14_check( 'T5-G6-8-robots-base-list-closed',
	array( 'index', 'noindex', 'follow', 'nofollow' ) === hossam_get_allowed_rank_math_robots(),
	'the robots allow-list is the closed four [index,noindex,follow,nofollow] with no extension filter registered (H6 §5-أ lock)',
	'base robots list deviated: ' . var_export( hossam_get_allowed_rank_math_robots(), true ) );

/* Extended values (chips NoArchive/NoSnippet) drop; supported ones survive; save succeeds. */
$GLOBALS['B14_META'] = array();
$GLOBALS['B14_USER_ID'] = 7;
$GLOBALS['B14_CAPS']    = array( 'edit_posts' => true );
$saved_mixed = hossam_save_rankmath_seo_data( 31, array(
	'title'  => 'SEO Title',
	'robots' => array( 'index', 'NoArchive', 'nosnippet', 'nofollow' ),
) );
b14_check( 'T5-G6-8-robots-extended-dropped-save-ok',
	true === $saved_mixed
		&& array( 'index', 'nofollow' ) === get_post_meta( 31, 'rank_math_robots', true )
		&& 'SEO Title' === get_post_meta( 31, 'rank_math_title', true )
		&& ! isset( $GLOBALS['B14_META'][31]['rank_math_focus_keyword'] ),
	'extended chips (NoArchive/NoSnippet) are dropped by the closed intersection while the supported pair saves — no break, no extended write',
	'mixed robots save deviated: saved=' . var_export( $saved_mixed, true )
		. ' robots=' . var_export( get_post_meta( 31, 'rank_math_robots', true ), true ) );

/* Exclusively-unsupported selection: no valid value remains ⇒ false, ZERO writes. */
$GLOBALS['B14_META'] = array();
$saved_bad = hossam_save_rankmath_seo_data( 32, array(
	'robots' => array( 'noarchive' ),
) );
b14_check( 'T5-G6-8-robots-exclusive-unsupported-false',
	false === $saved_bad
		&& ! isset( $GLOBALS['B14_META'][32] ),
	'exclusively-unsupported robots selection fails false before any meta write (no silent value destruction)',
	'exclusive-unsupported save deviated: saved=' . var_export( $saved_bad, true )
		. ' meta=' . var_export( $GLOBALS['B14_META'][32] ?? null, true ) );

/* ════════════════════════════════════════════════════════════════
 * T5-G6-9 — post-kses H1 valve: static order + reject/normalize/off
 * ════════════════════════════════════════════════════════════════ */

/* Static order: wp_kses_post runs BEFORE the valve in both create and update. */
$wp_src = (string) file_get_contents( $GLOBALS['B14_PROJECT'] . '/runtime/adapters/wordpress-posts.php' );
function b14_t5_function_body( string $src, string $fn ): string {
	$pos = strpos( $src, 'function ' . $fn . '(' );
	if ( false === $pos ) {
		return '';
	}
	$open  = strpos( $src, '{', $pos );
	$depth = 0;
	$len   = strlen( $src );
	for ( $i = (int) $open; $i < $len; $i++ ) {
		if ( '{' === $src[ $i ] ) {
			$depth++;
		} elseif ( '}' === $src[ $i ] ) {
			$depth--;
			if ( 0 === $depth ) {
				return substr( $src, (int) $open, $i - (int) $open + 1 );
			}
		}
	}
	return '';
}
$create_body = b14_t5_function_body( $wp_src, 'hossam_create_article' );
$update_body = b14_t5_function_body( $wp_src, 'hossam_update_article' );
$order_ok = '' !== $create_body && '' !== $update_body
	&& false !== strpos( $create_body, "wp_kses_post( \$data['content'] )" )
	&& false !== strpos( $create_body, 'hossam_article_h1_should_reject' )
	&& strpos( $create_body, "wp_kses_post( \$data['content'] )" ) < strpos( $create_body, 'hossam_article_h1_should_reject' )
	&& false !== strpos( $update_body, "wp_kses_post( \$data['content'] )" )
	&& false !== strpos( $update_body, 'hossam_article_h1_should_reject' )
	&& strpos( $update_body, "wp_kses_post( \$data['content'] )" ) < strpos( $update_body, 'hossam_article_h1_should_reject' );
b14_check( 'T5-G6-9-valve-after-kses-static-order',
	$order_ok,
	'in both create and update bodies the valve reads the payload AFTER wp_kses_post (stored-payload scope, never the raw input)',
	'valve ordering deviated: create_extracted=' . var_export( '' !== $create_body, true )
		. ' update_extracted=' . var_export( '' !== $update_body, true ) );

function b14_t5_create( string $content ): array {
	$GLOBALS['B14_INSERTS']       = array();
	$GLOBALS['B14_DELETED_POSTS'] = array();
	$GLOBALS['B14_ERRLOG_TAKEN']  = '';
	b14_errlog_start();
	$result = hossam_create_article( array( 'title' => 'T', 'content' => $content, 'status' => 'draft' ) );
	$GLOBALS['B14_ERRLOG_TAKEN'] = b14_errlog_take();
	return array(
		'result'  => $result,
		'inserts' => $GLOBALS['B14_INSERTS'],
		'deleted' => $GLOBALS['B14_DELETED_POSTS'],
		'errlog'  => $GLOBALS['B14_ERRLOG_TAKEN'],
	);
}
function b14_t5_set_policy( string $policy ): void {
	static $cb = null;
	if ( null !== $cb ) {
		remove_filter( 'hossam_article_h1_policy', $cb, 10 );
	}
	$cb = static fn( $value ) => $policy;
	add_filter( 'hossam_article_h1_policy', $cb, 10, 1 );
}

$GLOBALS['B14_USER_ID'] = 7;
$GLOBALS['B14_CAPS']    = array( 'edit_posts' => true );
$GLOBALS['B14_USERS']   = array( 7 => (object) array( 'user_login' => 'hossam_member' ) );

/* reject + h1 ⇒ clean 400 BEFORE any write (no insert, no delete). */
b14_t5_set_policy( 'reject' );
$r_reject = b14_t5_create( '<p>a</p><h1>b</h1>' );
b14_check( 'T5-G6-9-valve-reject-before-write',
	is_wp_error( $r_reject['result'] ) && 'hossam_h1_rejected' === $r_reject['result']->get_error_code()
		&& false !== strpos( $r_reject['result']->get_error_message(), 'H1' )
		&& 0 === count( $r_reject['inserts'] ) && 0 === count( $r_reject['deleted'] ),
	'reject policy + h1 ⇒ WP_Error hossam_h1_rejected (400 via E6 map) with zero inserts and zero deletions — the clean pre-write rejection',
	'reject path deviated: ' . var_export( $r_reject['result'], true )
		. ' inserts=' . count( $r_reject['inserts'] ) );

/* normalize + h1 + toolbar tags ⇒ insert proceeds; handler converts h1→h2; toolbar tags round-trip unchanged. */
b14_t5_set_policy( 'normalize' );
$toolbar = '<p>intro</p><h1>Head</h1><u>u</u><s>s</s><mark>m</mark><hr>'
	. '<table><thead><tr><th>h</th></tr></thead><tbody><tr><td>d</td></tr></tbody></table>'
	. '<strong>b</strong><em>i</em>';
$r_norm = b14_t5_create( $toolbar );
$norm_content = (string) ( $r_norm['inserts'][0]['post_content'] ?? '' );
b14_check( 'T5-G6-9-valve-normalize-h1-to-h2',
	! is_wp_error( $r_norm['result'] ) && 1 === count( $r_norm['inserts'] )
		&& false !== strpos( $norm_content, '<h2>Head</h2>' )
		&& ! hossam_audit_log_content_has_h1( $norm_content ),
	'normalize policy: the create path inserts once and the real wp_insert_post_data handler converts h1→h2 in the stored payload (Q5 — no h1 remains)',
	'normalize path deviated: result=' . var_export( $r_norm['result'], true )
		. ' content=' . var_export( $norm_content, true ) );

foreach ( array( '<u>u</u>', '<s>s</s>', '<mark>m</mark>', '<hr>', '<table>', '<thead>', '<tbody>', '<tr>', '<th>h</th>', '<td>d</td>', '<strong>b</strong>', '<em>i</em>' ) as $tag ) {
	if ( false === strpos( $norm_content, $tag ) ) {
		b14_check( 'T5-G6-9-toolbar-roundtrip-unchanged', false,
			'toolbar tags survive the save path unchanged (local keep-list proxy — no silent loss)',
			"toolbar tag lost in the stored payload: {$tag}" );
		goto tags_done;
	}
}
b14_check( 'T5-G6-9-toolbar-roundtrip-unchanged',
	true,
	'all toolbar tags (u/s/mark/hr/table/thead/tbody/tr/th/td/strong/em) survive the save path unchanged (local keep-list proxy — no silent loss)',
	'toolbar round-trip deviated' );
tags_done:

/* off ⇒ no check at all: h1 stored as-is (handler inert), no rejection. */
b14_t5_set_policy( 'off' );
$r_off = b14_t5_create( '<p>a</p><h1>b</h1>' );
$off_content = (string) ( $r_off['inserts'][0]['post_content'] ?? '' );
b14_check( 'T5-G6-9-valve-off-no-check',
	! is_wp_error( $r_off['result'] ) && 1 === count( $r_off['inserts'] )
		&& false !== strpos( $off_content, '<h1>b</h1>' ),
	'off policy: no valve check and no conversion — h1 stored as-is (only off disables; reject stays at the call point)',
	'off path deviated: result=' . var_export( $r_off['result'], true )
		. ' content=' . var_export( $off_content, true ) );

/* The post-kses scope proof: a disallowed <script> tag is stripped by the
 * boundary proxy BEFORE the valve runs — an h1 inside it is still seen
 * post-kses (the payload the valve reads is the transformed one). */
b14_t5_set_policy( 'reject' );
$r_script = b14_t5_create( '<script>trash()</script><p>keep</p>' );
b14_check( 'T5-G6-9-valve-sees-post-kses-payload',
	! is_wp_error( $r_script['result'] ) && 1 === count( $r_script['inserts'] )
		&& 'trash()<p>keep</p>' === (string) ( $r_script['inserts'][0]['post_content'] ?? '' ),
	'the valve evaluates the post-sanitize payload: the disallowed element tags were stripped before the valve ran (text content kept — kses semantics) and the stored payload is the transformed one',
	'post-kses scope deviated: result=' . var_export( $r_script['result'], true )
		. ' content=' . var_export( $r_script['inserts'][0]['post_content'] ?? null, true ) );

b14_t5_set_policy( 'normalize' ); // restore the production default for later sections

/* ════════════════════════════════════════════════════════════════
 * Summary — T5 stays OPEN by contract (client part R18-R20 → phase 3)
 * ════════════════════════════════════════════════════════════════ */

echo "T5 DEFERRED (لا يُغلق T5): جزء client من round-trip الوسوم (R18-R20) يُستكمل عند المرحلة 3؛ وكذلك round-trip wp_kses_post الحقيقي ومسار REST لبوابة الحجم — قبول حي.\n";
echo "B14-T5 RESULT: {$GLOBALS['B14_PASS']} pass, 0 skipped, {$GLOBALS['B14_FAIL']} fail (PHP " . PHP_VERSION . ")\n";
if ( $GLOBALS['B14_FAIL'] > 0 ) {
	echo "Failures:\n" . implode( "\n", $GLOBALS['B14_FAILS'] ) . "\n";
	exit( 1 );
}
exit( 0 );
