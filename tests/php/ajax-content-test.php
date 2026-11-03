<?php
/**
 * HAL Frontend Dashboard — Batch 5 closure harness: content & files AJAX.
 *
 * Local, isolated harness: no live WordPress, no network, no database.
 * Loads the REAL runtime/bootstrap.php (core + batch-3 backend + the 8
 * batch-4 adapters + the batch-5 ajax/posts.php + ajax/uploads.php) —
 * only WordPress itself is stubbed. Every status/ownership assertion
 * below invokes the REAL registered wp_ajax_* callback captured by the
 * add_action stub; no project logic is reimplemented in the harness.
 *
 * Coverage (architecture §16 closure gate + §10 per-file rules):
 *
 *   INVENTORY — the action inventory is exactly: 29 wp_ajax_* hooks
 *              (9 batch-5 posts/uploads + 17 batch-6
 *              appointments/finance/members/inbox/store/ai + 1 v1.0.6 §8.3
 *              seo + 2 v1.0.3 health), the single wp_ajax_nopriv_* hook
 *              being the v1.0.3 health handler, and ajax/seo.php loads
 *              ninth from the real bootstrap (its endpoint
 *              wp_ajax_hossam_save_seo IS registered) per the documented
 *              v1.0.6 §8.3 gate change (runtime detection instead of the
 *              §16 limited-read-only hold).
 *   ORDER     — core(5) → backend(5) → adapters(8) → ajax(9) in the
 *              mandated bootstrap order; runtime_ready still fires.
  *   B3-08     — the posts/uploads endpoints consume the owner feature
  *              decision (posts/files/wpml_translations) via
  *              is_feature_enabled: disabled → 403 feature_disabled with
  *              data preserved; re-enabled authorization stays independent.
  *              ajax/seo.php loads ninth (v1.0.6 §8.3 — G3/G4 hold).
 *   STATUS    — for the nine registered endpoints: 401 (logged out),
 *              403 (nonce failure / capability denied / author
 *              mismatch / feature disabled), 404 (resource missing),
 *              400 (input shape), 500 (adapter failures incl. Core
 *              insert/update/thumbnail failures, translation-draft
 *              insert failure, cleanup failure + error_log capture),
 *              503 (integration decision), and success shapes — driven
 *              through the REAL adapters
 *              (wordpress-posts / wordpress-uploads / wpml registry).
 *
 * Modes: main (inventory/order/B3-08 + spawns the subprocess modes),
 * posts, translate (WPML fixture active), uploads, feature-gates.
 *
 * Usage:  php tests/php/ajax-content-test.php            (main)
 *         php tests/php/ajax-content-test.php <mode>     (subprocess)
 *         exit 0 = all checks pass.
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

if ( PHP_SAPI !== 'cli' ) {
	echo "This harness must run under CLI PHP.\n";
	exit( 1 );
}

/* ════════════════════════════════════════════════════════════════
 * Batch-5 AJAX boundary stubs — defined BEFORE the shared stub block
 * (function_exists guards keep one definition). WordPress semantics
 * only: wp_send_json* terminates the request (die) — modelled as an
 * exception carrying the HTTP status + JSON body; check_ajax_referer
 * dies with 403 '-1' on failure (WordPress wp_die semantics).
 * ════════════════════════════════════════════════════════════════ */

$GLOBALS['B5_NONCE']                = 'b5-fixture-nonce';
$GLOBALS['B5_FAIL_UNTRASH']         = false;
$GLOBALS['B5_FAIL_DELETE_ATTACHMENT'] = false;
$GLOBALS['B5_QUERY_POSTS']          = array();
$GLOBALS['B5_LAST_QUERY_ARGS']      = null;
$GLOBALS['B5_MEDIA_URLS']           = array();
$GLOBALS['B5_WPML_LANGS']           = array();
$GLOBALS['B5_WPML_OBJECT_ID']       = array();
$GLOBALS['B5_WPML_TRID']            = 0;
$GLOBALS['B5_WPML_SOURCE']          = '';
$GLOBALS['B5_WPML_SET']             = array();
// v1.0.6 §4/§6 (W-NEW read-back): frozen-mock controls for the two official
// read-back contracts. DETAILS_FROZEN=false (default) mirrors a faithful
// WPML: details/translations derive from the recorded do_action links.
// DETAILS_FROZEN=true forces an explicit (mismatching) answer to prove the
// fail-closed contract — no success before a match.
$GLOBALS['B5_WPML_DETAILS_FROZEN']  = false;
$GLOBALS['B5_WPML_DETAILS_EXPLICIT'] = null;

if ( ! class_exists( 'B5_AjaxExit' ) ) {
	class B5_AjaxExit extends Exception {
		public int $status;
		public string $payload;
		public function __construct( int $status, string $payload ) {
			parent::__construct( 'b5-ajax-exit' );
			$this->status  = $status;
			$this->payload = $payload;
		}
	}
}
if ( ! function_exists( 'wp_send_json_success' ) ) {
	function wp_send_json_success( $data = null, int $status = 200 ): void {
		throw new B5_AjaxExit( $status, (string) wp_json_encode( array( 'success' => true, 'data' => $data ) ) );
	}
}
if ( ! function_exists( 'wp_send_json_error' ) ) {
	function wp_send_json_error( $data = null, int $status = 200 ): void {
		throw new B5_AjaxExit( $status, (string) wp_json_encode( array( 'success' => false, 'data' => $data ) ) );
	}
}
if ( ! function_exists( 'check_ajax_referer' ) ) {
	function check_ajax_referer( $action = -1, $query_arg = false, bool $die = true ) {
		$supplied = $_POST[ (string) $query_arg ] ?? $_GET[ (string) $query_arg ] ?? null;
		$expected = (string) $GLOBALS['B5_NONCE'];
		if ( is_string( $supplied ) && '' !== $supplied && hash_equals( $expected, $supplied ) ) {
			return 1;
		}
		if ( $die ) {
			throw new B5_AjaxExit( 403, '-1' );
		}
		return false;
	}
}
if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		public array $posts = array();
		// T10 (v1.1.0): loop surface for the editor-mode template cycle —
		// additive only: every pre-existing mode constructs zero-arg queries
		// and never calls the loop methods, so their behavior is untouched.
		public int $found_posts   = 0;
		public int $post_count    = 0;
		public int $max_num_pages = 0;
		private int $b5_loop_index = -1;
		public function __construct( array $args = array() ) {
			$GLOBALS['B5_LAST_QUERY_ARGS'] = $args;
			$this->posts                   = is_array( $GLOBALS['B5_QUERY_POSTS'] ) ? $GLOBALS['B5_QUERY_POSTS'] : array();
		}
		public function have_posts(): bool {
			return $this->b5_loop_index + 1 < count( $this->posts );
		}
		public function the_post(): void {
			$this->b5_loop_index += 1;
			$GLOBALS['B5_CURRENT_LOOP_POST'] = (int) ( $this->posts[ $this->b5_loop_index ] ?? 0 );
		}
	}
}
if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $post = 0 ): string {
		$id = is_object( $post ) ? ( $post->ID ?? 0 ) : (int) $post;
		$p  = $GLOBALS['B5_POSTS'][ $id ] ?? null;
		return $p ? (string) $p->post_title : '';
	}
}
if ( ! function_exists( 'get_the_date' ) ) {
	function get_the_date( $format = 'Y-m-d H:i:s', $post = null ): string {
		$id = is_object( $post ) ? ( $post->ID ?? 0 ) : (int) $post;
		$p  = $GLOBALS['B5_POSTS'][ $id ] ?? null;
		$ts = ( $p && ! empty( $p->post_date ) ) ? strtotime( $p->post_date ) : time();
		return date( (string) $format, $ts );
	}
}
if ( ! function_exists( 'wp_get_attachment_url' ) ) {
	function wp_get_attachment_url( $id ): string {
		return $GLOBALS['B5_MEDIA_URLS'][ (int) $id ] ?? 'https://example.test/wp-content/uploads/b5-' . (int) $id;
	}
}
if ( ! function_exists( 'wp_delete_attachment' ) ) {
	function wp_delete_attachment( int $post_id, bool $force = false ) {
		if ( ! empty( $GLOBALS['B5_FAIL_DELETE_ATTACHMENT'] ) ) {
			return false;
		}
		$post = $GLOBALS['B5_POSTS'][ $post_id ] ?? null;
		unset( $GLOBALS['B5_POSTS'][ $post_id ] );
		return $post ?? false;
	}
}
if ( ! function_exists( 'wp_untrash_post' ) ) {
	function wp_untrash_post( int $post_id ) {
		if ( ! empty( $GLOBALS['B5_FAIL_UNTRASH'] ) ) {
			return false;
		}
		$post = $GLOBALS['B5_POSTS'][ $post_id ] ?? null;
		if ( ! $post ) {
			return false;
		}
		$post->post_status = 'draft';
		return $post;
	}
}
$hal_ext_dir = (string) ( getenv( 'HAL_PHP_EXT_DIR' ) ?: ini_get( 'extension_dir' ) );
if ( '' !== $hal_ext_dir && ! is_dir( $hal_ext_dir ) ) {
	$hal_ext_dir = (string) realpath( $hal_ext_dir );
}
define( 'HAL_TEST_EXT_DIR', is_dir( $hal_ext_dir ) ? $hal_ext_dir : (string) $hal_ext_dir );
require_once __DIR__ . '/lib-hal-php-flags.php'; // test-launcher flag filter (no product code, no assertions)

$project = dirname( __DIR__, 2 );

$GLOBALS['B5_HOOKS']      = array();
$GLOBALS['B5_ACTIONS']    = array();
$GLOBALS['B5_OPTIONS']    = array();
$GLOBALS['B5_RESULTS']    = array();
$GLOBALS['B5_CAPS']       = array();
$GLOBALS['B5_HTTP']       = array( 'calls' => array() );
$GLOBALS['B5_UPLOAD']     = array( 'result' => 0, 'calls' => 0 );
$GLOBALS['B5_TERMS']      = array();
$GLOBALS['B5_POSTS']      = array();
$GLOBALS['B5_MEDIA']      = array( 'image_ids' => array(), 'meta' => array() );
$GLOBALS['B5_SHORTCODES'] = array();
$GLOBALS['B5_PLUGIN_ACTIVE'] = false;
$GLOBALS['B5_LOGGED_IN']  = false;
$GLOBALS['B5_USER_ID']    = 3;

/* ────────────────────────────────────────────────────────────────
 * Harness bookkeeping
 * ──────────────────────────────────────────────────────────────── */

function b5_check( string $id, bool $condition, string $pass, string $fail = '' ): void {
	$GLOBALS['B5_RESULTS'][] = array( 'id' => $id, 'ok' => $condition, 'pass' => $pass, 'fail' => $fail );
}

function b5_result_line( string $mode ): void {
	$fail = 0;
	foreach ( $GLOBALS['B5_RESULTS'] as $result ) {
		if ( ! $result['ok'] ) {
			$fail++;
			echo "FAIL [{$result['id']}] {$result['fail']}\n";
		}
	}
	echo 'B5-VERDICT ' . $mode . ( 0 === $fail ? ' ALL-ASSERTIONS-HELD' : ' ASSERTIONS-FAILED-' . $fail ) . "\n";
	exit( 0 === $fail ? 0 : 1 );
}

/* ────────────────────────────────────────────────────────────────
 * WordPress boundary stubs (WordPress only — never project logic)
 * ──────────────────────────────────────────────────────────────── */

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook_name, $callback = '', int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['B5_HOOKS'][ $hook_name ][] = array( 'type' => 'action', 'callback' => $callback, 'priority' => $priority );
		return true;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook_name, $callback = '', int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['B5_HOOKS'][ $hook_name ][] = array( 'type' => 'filter', 'callback' => $callback, 'priority' => $priority );
		return true;
	}
}
if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( string $hook_name, $callback, int $priority = 10 ): bool {
		foreach ( $GLOBALS['B5_HOOKS'][ $hook_name ] ?? array() as $index => $entry ) {
			if ( $entry['callback'] === $callback ) {
				unset( $GLOBALS['B5_HOOKS'][ $hook_name ][ $index ] );
				return true;
			}
		}
		return false;
	}
}
if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( string $hook_name, $callback = false ) {
		$entries = $GLOBALS['B5_HOOKS'][ $hook_name ] ?? array();
		if ( false === $callback ) {
			return array() !== $entries;
		}
		foreach ( $entries as $entry ) {
			if ( $entry['callback'] === $callback ) {
				return $priority ?? 10;
			}
		}
		return false;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook_name, ...$args ): void {
		$GLOBALS['B5_ACTIONS'][ $hook_name ] = ( $GLOBALS['B5_ACTIONS'][ $hook_name ] ?? 0 ) + 1;
		foreach ( $GLOBALS['B5_HOOKS'][ $hook_name ] ?? array() as $entry ) {
			call_user_func_array( $entry['callback'], $args );
		}
	}
}
if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook_name ): int {
		return $GLOBALS['B5_ACTIONS'][ $hook_name ] ?? 0;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook_name, $value, ...$args ) {
		foreach ( $GLOBALS['B5_HOOKS'][ $hook_name ] ?? array() as $entry ) {
			$value = call_user_func_array( $entry['callback'], array_merge( array( $value ), $args ) );
		}
		return $value;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return preg_replace( '/[^a-z0-9_]/', '', strtolower( $key ) );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ): string {
		return trim( (string) $value );
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $value ): string {
		return trim( (string) $value );
	}
}
if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( string $name ): string {
		return preg_replace( '/[^A-Za-z0-9._-]/', '_', basename( $name ) );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return $value;
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ): string {
		$url = trim( (string) $url );
		return preg_match( '#^https?://#i', $url ) ? $url : '';
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0 ) {
		return json_encode( $value, $flags );
	}
}
if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) {
		return array_merge( (array) $defaults, (array) $args );
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component );
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability, ...$object_args ): bool {
		$key = $capability . ( isset( $object_args[0] ) ? '_' . (int) $object_args[0] : '' );
		if ( array_key_exists( $key, $GLOBALS['B5_CAPS'] ) ) {
			return (bool) $GLOBALS['B5_CAPS'][ $key ];
		}
		if ( array_key_exists( $capability, $GLOBALS['B5_CAPS'] ) ) {
			return (bool) $GLOBALS['B5_CAPS'][ $capability ];
		}
		return false;
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return (int) $GLOBALS['B5_USER_ID'];
	}
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		return (bool) $GLOBALS['B5_LOGGED_IN'];
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default_value = false ) {
		return array_key_exists( $option, $GLOBALS['B5_OPTIONS'] ) ? $GLOBALS['B5_OPTIONS'][ $option ] : $default_value;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, $value, $autoload = null ): bool {
		$GLOBALS['B5_OPTIONS'][ $option ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ) {
		return get_option( '_transient_' . $key );
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, $value, int $ttl = 0 ): bool {
		return update_option( '_transient_' . $key, $value );
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $key ): bool {
		unset( $GLOBALS['B5_OPTIONS'][ '_transient_' . $key ] );
		return true;
	}
}
// تفويض المالك #1 (2026-11-02): دوال نواة ناقصة في الـharness — صفوف
// R9/R10 تستدعي core/rate-limits.php (wp_using_ext_object_cache — G3 §1.4)
// وcore/audit-log.php (get_userdata من الجلسة، G4 §3-أ) وget_post_mime_type
// لمفتاح mime المغلق في file_upload/file_delete. دلالة النواة: بلا object
// cache خارجي ⇒ false؛ لا مستخدم ملتقط في هذا الـharness ⇒ false؛
// نمط الـstub الثابت المعتمد في adapters-test (image/png).
if ( ! function_exists( 'wp_using_ext_object_cache' ) ) {
	function wp_using_ext_object_cache(): bool {
		return false;
	}
}
if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( int $user_id ) {
		return false;
	}
}
if ( ! function_exists( 'get_post_mime_type' ) ) {
	function get_post_mime_type( $attachment_id ): string {
		return 'image/png';
	}
}
/* T10 (v1.1.0): render-boundary stubs for the editor-mode template cycle
 * (b5_mode_editor_mode) — WordPress escaping/query-loop surfaces and the
 * minimal paginate/redirect boundary. All additive + function_exists
 * guarded: no pre-existing mode or assertion changes meaning. The real
 * badge mapping lives in the shell dashboard.php (out of scope here) —
 * the mode cycle asserts presence/absence of holders, never pill styling. */
if ( ! class_exists( 'B5_Redirect' ) ) {
	class B5_Redirect extends Exception {
		public string $target;
		public function __construct( string $target ) {
			parent::__construct( 'b5-redirect' );
			$this->target = $target;
		}
	}
}
if ( ! function_exists( 'b5_capture_redirect' ) ) {
	/** Renders one admin-handler call and captures the redirect signal (settings-and-secrets b2s_capture_redirect pattern). */
	function b5_capture_redirect( callable $handler ): B5_Redirect {
		try {
			$handler();
		} catch ( B5_Redirect $redirect ) {
			return $redirect;
		}
		throw new RuntimeException( 'handler did not redirect' );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ): string {
		$url = trim( (string) $url );
		return preg_match( '#^(https?|ftp)://#i', $url ) || '' === $url || '#' === $url[0] ? $url : '';
	}
}
if ( ! function_exists( 'esc_textarea' ) ) {
	function esc_textarea( $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false );
	}
}
if ( ! function_exists( 'get_the_ID' ) ) {
	function get_the_ID(): int {
		return (int) ( $GLOBALS['B5_CURRENT_LOOP_POST'] ?? 0 );
	}
}
if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( $post = null ): string {
		$id = null === $post
			? (int) ( $GLOBALS['B5_CURRENT_LOOP_POST'] ?? 0 )
			: ( is_object( $post ) ? (int) ( $post->ID ?? 0 ) : (int) $post );
		$p = $GLOBALS['B5_POSTS'][ $id ] ?? null;
		return $p ? (string) $p->post_status : '';
	}
}
if ( ! function_exists( 'get_the_modified_date' ) ) {
	function get_the_modified_date( $format = 'Y-m-d H:i:s', $post = null ): string {
		return gmdate( (string) $format );
	}
}
if ( ! function_exists( 'the_title_attribute' ) ) {
	function the_title_attribute( array $args = array() ): void {
		echo esc_attr( get_the_title( (int) ( $GLOBALS['B5_CURRENT_LOOP_POST'] ?? 0 ) ) );
	}
}
if ( ! function_exists( 'wp_reset_postdata' ) ) {
	function wp_reset_postdata(): void {}
}
if ( ! function_exists( 'get_categories' ) ) {
	function get_categories( array $args = array() ): array {
		return array();
	}
}
if ( ! function_exists( 'paginate_links' ) ) {
	function paginate_links( array $args = array() ) {
		return '';
	}
}
if ( ! function_exists( '_dash_status_badge' ) ) {
	function _dash_status_badge( $status ): array {
		return array( 'pill-ghost', (string) $status );
	}
}
if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $nonce, $action = -1 ): int {
		if ( empty( $GLOBALS['B5_NONCE_VALID'] ) ) {
			return 0;
		}
		return ( (string) $GLOBALS['B5_NONCE'] === (string) $nonce ) ? 1 : 0;
	}
}
if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( $target, int $status = 302 ): void {
		throw new B5_Redirect( (string) $target );
	}
}
if ( ! function_exists( 'selected' ) ) {
	function selected( $helper, $current = true, bool $echo = true ): string {
		$out = (string) $helper === (string) $current ? " selected='selected'" : '';
		if ( $echo ) {
			echo $out;
		}
		return $out;
	}
}
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
if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID = 0;
		public string $post_type = 'post';
		public string $post_title = '';
		public string $post_content = '';
		public string $post_status = '';
		public int $post_author = 0;
	}
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/' . $path;
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $args, $url = '' ): string {
		if ( ! is_array( $args ) ) {
			$stack = func_get_args();
			$args  = $stack[0] ?? array();
			$url   = (string) ( $stack[ count( $stack ) - 1 ] ?? '' );
		}
		return (string) $url . ( false === strpos( (string) $url, '?' ) ? '?' : '&' ) . http_build_query( (array) $args );
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		return 'https://example.test/' . ltrim( $path, '/' );
	}
}
if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( string $url, array $args = array() ) {
		$GLOBALS['B5_HTTP']['calls'][] = array( 'verb' => 'GET', 'url' => $url, 'args' => $args );
		return $GLOBALS['B5_HTTP']['wp_error'] ?? $GLOBALS['B5_HTTP']['response'] ?? new WP_Error( 'b5_no_response_fixture' );
	}
}
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( string $url, array $args = array() ) {
		$GLOBALS['B5_HTTP']['calls'][] = array( 'verb' => 'POST', 'url' => $url, 'args' => $args );
		return $GLOBALS['B5_HTTP']['wp_error'] ?? $GLOBALS['B5_HTTP']['response'] ?? new WP_Error( 'b5_no_response_fixture' );
	}
}
if ( ! function_exists( 'wp_remote_head' ) ) {
	function wp_remote_head( string $url, array $args = array() ) {
		$GLOBALS['B5_HTTP']['calls'][] = array( 'verb' => 'HEAD', 'url' => $url, 'args' => $args );
		if ( isset( $GLOBALS['B5_HTTP']['head'][ $url ] ) ) {
			return array( 'response' => array( 'code' => $GLOBALS['B5_HTTP']['head'][ $url ] ), 'body' => '' );
		}
		return $GLOBALS['B5_HTTP']['wp_error'] ?? $GLOBALS['B5_HTTP']['response'] ?? new WP_Error( 'b5_no_response_fixture' );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ): int {
		return is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : 0;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ): string {
		return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
	}
}
if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( string $format, $timestamp = null ) {
		return gmdate( $format, $timestamp ?? time() );
	}
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type ) {
		return 'mysql' === $type ? gmdate( 'Y-m-d H:i:s' ) : time();
	}
}
if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite(): bool {
		return false;
	}
}
if ( ! function_exists( 'is_plugin_active' ) ) {
	function is_plugin_active( string $plugin_file ): bool {
		return in_array( $plugin_file, (array) $GLOBALS['B5_PLUGIN_ACTIVE'], true );
	}
}
if ( ! function_exists( 'get_plugin_data' ) ) {
	function get_plugin_data( string $plugin_file, bool $markup = true, bool $translate = true ): array {
		return array( 'Version' => '' );
	}
}
if ( ! function_exists( 'shortcode_exists' ) ) {
	function shortcode_exists( string $tag ): bool {
		return in_array( $tag, $GLOBALS['B5_SHORTCODES'], true );
	}
}
if ( ! function_exists( 'get_page_by_path' ) ) {
	function get_page_by_path( string $page_path, $output = null, string $post_type = 'page' ) {
		$id = array_search( $page_path, $GLOBALS['B5_POST_SLUGS'] ?? array(), true );
		if ( false === $id ) {
			return null;
		}
		$page       = new WP_Post();
		$page->ID   = (int) $id;
		$page->post_type = 'page';
		return $page;
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post ) {
		$id = is_object( $post ) ? ( $post->ID ?? 0 ) : (int) $post;
		return 'https://example.test/?p=' . $id;
	}
}
if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( array $args = array() ): array {
		return $GLOBALS['B5_GET_POSTS'] ?? array();
	}
}
if ( ! function_exists( 'get_post_type_archive_link' ) ) {
	function get_post_type_archive_link( string $post_type ): string {
		return 'https://example.test/archive/' . $post_type . '/';
	}
}
if ( ! function_exists( 'get_option_show_on_front' ) ) {
}
if ( ! function_exists( 'is_rtl' ) ) {
	function is_rtl(): bool {
		return (bool) ( $GLOBALS['B5_IS_RTL'] ?? false );
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) {
		$id = is_object( $id ) ? ( $id->ID ?? 0 ) : (int) $id;
		return $GLOBALS['B5_POSTS'][ $id ] ?? null;
	}
}
if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( array $postarr, bool $wp_error = false ) {
		if ( ! empty( $GLOBALS['B5_POSTS_FAIL_INSERT'] ) ) {
			return $wp_error ? new WP_Error( 'b5_insert_failed', 'insert failed fixture' ) : 0;
		}
		static $next = 100;
		$post           = new WP_Post();
		$post->ID       = ++$next;
		$post->post_type    = (string) ( $postarr['post_type'] ?? 'post' );
		$post->post_status  = (string) ( $postarr['post_status'] ?? 'draft' );
		$post->post_title   = (string) ( $postarr['post_title'] ?? '' );
		$post->post_content = (string) ( $postarr['post_content'] ?? '' );
		$post->post_author  = (int) ( $postarr['post_author'] ?? 0 );
		$GLOBALS['B5_POSTS'][ $post->ID ] = $post;
		return $post->ID;
	}
}
if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( array $postarr, bool $wp_error = false ) {
		$id = (int) ( $postarr['ID'] ?? 0 );
		if ( ! empty( $GLOBALS['B5_POSTS_FAIL_UPDATE'] ) ) {
			return $wp_error ? new WP_Error( 'b5_update_failed', 'update failed fixture' ) : 0;
		}
		$post = $GLOBALS['B5_POSTS'][ $id ] ?? null;
		if ( ! $post ) {
			return $wp_error ? new WP_Error( 'b5_update_missing', 'missing' ) : 0;
		}
		if ( isset( $postarr['post_title'] ) ) {
			$post->post_title = (string) $postarr['post_title'];
		}
		if ( isset( $postarr['post_content'] ) ) {
			$post->post_content = (string) $postarr['post_content'];
		}
		if ( isset( $postarr['post_status'] ) ) {
			$post->post_status = (string) $postarr['post_status'];
		}
		return $id;
	}
}
if ( ! function_exists( 'wp_delete_post' ) ) {
	function wp_delete_post( int $post_id, bool $force = false ) {
		$post = $GLOBALS['B5_POSTS'][ $post_id ] ?? null;
		if ( ! empty( $GLOBALS['B5_POSTS_FAIL_DELETE'] ) ) {
			return false;
		}
		unset( $GLOBALS['B5_POSTS'][ $post_id ] );
		return $post;
	}
}
if ( ! function_exists( 'wp_trash_post' ) ) {
	function wp_trash_post( int $post_id ) {
		$post = $GLOBALS['B5_POSTS'][ $post_id ] ?? null;
		if ( ! $post ) {
			return false;
		}
		$post->post_status = 'trash';
		return $post;
	}
}
if ( ! function_exists( 'wp_untrash_post' ) ) {
	function wp_untrash_post( int $post_id ) {
		$post = $GLOBALS['B5_POSTS'][ $post_id ] ?? null;
		if ( ! $post ) {
			return false;
		}
		$post->post_status = 'draft';
		return $post;
	}
}
if ( ! function_exists( 'term_exists' ) ) {
	function term_exists( $term, string $taxonomy = '' ) {
		if ( ! isset( $GLOBALS['B5_TERMS'][ $taxonomy ] ) ) {
			return null;
		}
		return in_array( (int) $term, $GLOBALS['B5_TERMS'][ $taxonomy ], true ) ? (int) $term : null;
	}
}
if ( ! function_exists( 'wp_set_post_categories' ) ) {
	function wp_set_post_categories( int $post_id, array $categories = array() ) {
		if ( ! empty( $GLOBALS['B5_POSTS_FAIL_CATEGORIES'] ) ) {
			return false;
		}
		$GLOBALS['B5_POST_CATEGORIES'][ $post_id ] = array_map( 'absint', $categories );
		return array_map( 'absint', $categories );
	}
}
if ( ! function_exists( 'wp_get_post_categories' ) ) {
	function wp_get_post_categories( int $post_id, array $args = array() ) {
		return $GLOBALS['B5_POST_CATEGORIES'][ $post_id ] ?? array();
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $meta_key, bool $single = false ) {
		return $GLOBALS['B5_MEDIA']['meta'][ $post_id ][ $meta_key ] ?? '';
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( int $post_id, string $meta_key, $value ) {
		$GLOBALS['B5_MEDIA']['meta'][ $post_id ][ $meta_key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'wp_attachment_is_image' ) ) {
	function wp_attachment_is_image( $id ): bool {
		return in_array( (int) $id, $GLOBALS['B5_MEDIA']['image_ids'], true );
	}
}
if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
	function get_post_thumbnail_id( int $post_id ) {
		return $GLOBALS['B5_POST_THUMBNAILS'][ $post_id ] ?? 0;
	}
}
if ( ! function_exists( 'set_post_thumbnail' ) ) {
	function set_post_thumbnail( int $post_id, int $thumbnail_id ) {
		if ( ! empty( $GLOBALS['B5_POSTS_FAIL_THUMBNAIL'] ) ) {
			return false;
		}
		$GLOBALS['B5_POST_THUMBNAILS'][ $post_id ] = $thumbnail_id;
		return true;
	}
}
if ( ! function_exists( 'delete_post_thumbnail' ) ) {
	function delete_post_thumbnail( int $post_id ) {
		unset( $GLOBALS['B5_POST_THUMBNAILS'][ $post_id ] );
		return true;
	}
}
if ( ! function_exists( 'get_allowed_mime_types' ) ) {
	function get_allowed_mime_types( $user = null ): array {
		return $GLOBALS['B5_SITE_MIMES'] ?? array(
			'png'  => 'image/png',
			'jpg|jpeg' => 'image/jpeg',
			'exe'  => 'application/x-msdownload',
		);
	}
}
if ( ! function_exists( 'wp_check_filetype' ) ) {
	function wp_check_filetype( string $filename, $mimes = null ): array {
		$mimes = is_array( $mimes ) ? $mimes : get_allowed_mime_types();
		$extension = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
		foreach ( $mimes as $pattern => $mime ) {
			foreach ( explode( '|', (string) $pattern ) as $candidate ) {
				if ( $candidate === $extension ) {
					return array( 'ext' => $extension, 'type' => $mime );
				}
			}
		}
		return array( 'ext' => false, 'type' => false );
	}
}
if ( ! function_exists( 'media_handle_upload' ) ) {
	function media_handle_upload( string $field, int $post_id, array $post_data = array(), array $overrides = array() ) {
		$GLOBALS['B5_UPLOAD']['calls']++;
		return $GLOBALS['B5_UPLOAD']['result'];
	}
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $content ): string {
		return (string) $content;
	}
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
function b5_define_release_context(): void {
	if ( ! defined( 'ABSPATH' ) ) {
		$abspath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'b4-' . getmypid() . DIRECTORY_SEPARATOR;
		if ( ! is_dir( $abspath ) ) {
			@mkdir( $abspath, 0777, true );
		}
		// The capability registry's version probe requires this include path;
		// an empty stub + a get_plugin_data boundary stub keep it executable
		// (no real plugin files exist here).
		$wp_admin_includes = $abspath . 'wp-admin' . DIRECTORY_SEPARATOR . 'includes';
		if ( ! is_dir( $wp_admin_includes ) ) {
			@mkdir( $wp_admin_includes, 0777, true );
		}
		$plugin_include = $wp_admin_includes . DIRECTORY_SEPARATOR . 'plugin.php';
		if ( ! file_exists( $plugin_include ) ) {
			file_put_contents( $plugin_include, "<?php\n" );
		}
		define( 'ABSPATH', $abspath );
	}
	if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
		define( 'WP_PLUGIN_DIR', ABSPATH . 'wp-content/plugins' );
	}
	if ( ! defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT' ) ) {
		define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT', $GLOBALS['B5_PROJECT'] . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR );
		// T10 (v1.1.0): the Template Controller's path validator consumes
		// RUNTIME_DIR — defined alongside the rest of the release context.
		define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_DIR', HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT );
		define( 'HAL_FRONTEND_DASHBOARD_RELEASE_ID', '4.0.0+' . str_repeat( 'b5', 16 ) );
		define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION', '5.0.0' );
		define(
			'HAL_FRONTEND_DASHBOARD_RUNTIME_URL',
			'https://example.test/releases/' . HAL_FRONTEND_DASHBOARD_RELEASE_ID . '/'
		);
	}
}

function b5_define_valid_profile(): void {
	if ( ! defined( 'HOSSAM_AI_RUNTIME_PROFILE' ) ) {
		define( 'HOSSAM_AI_RUNTIME_PROFILE', array(
			'per_minute' => 30, 'per_day' => 500, 'concurrent' => 2, 'max_attempts' => 3,
			'stale_pending' => 3600, 'processing_deadline' => 240,
			'wp_ai_client_timeout' => 60, 'direct_key_timeout' => 90,
		) );
	}
}

function b5_valid_key_hex( string $seed ): string {
	return hash( 'sha256', 'b4-hal-master-key::' . $seed );
}

function b5_require_runtime(): void {
	require $GLOBALS['B5_PROJECT'] . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'bootstrap.php';
}

function b5_run_mode( string $mode, array $args = array() ): void {
	$php     = PHP_BINARY;
	$script  = __FILE__;
	$command = hal_php_argv(
		$php,
		array( '-d', 'extension_dir=' . HAL_TEST_EXT_DIR ),
		array( 'sodium', 'zip' )
	);
	$command[] = $script;
	$command[] = $mode;
	foreach ( $args as $arg ) {
		$command[] = $arg;
	}
	$descriptors = array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
	$proc = proc_open( $command, $descriptors, $pipes );
	if ( ! is_resource( $proc ) ) {
		$escaped = array_map( 'escapeshellarg', $command );
		$proc = proc_open( implode( ' ', $escaped ), $descriptors, $pipes );
		if ( ! is_resource( $proc ) ) {
			b5_check( 'MODE-' . strtoupper( str_replace( '-', '_', $mode ) ), false, 'subprocess could not start', 'proc_open failed' );
			return;
		}
	}
	fclose( $pipes[0] );
	$stdout = (string) stream_get_contents( $pipes[1] );
	fclose( $pipes[1] );
	$stderr = (string) stream_get_contents( $pipes[2] );
	fclose( $pipes[2] );
	$code = proc_close( $proc );
	$ok = 0 === $code && false !== strpos( $stdout, 'B5-VERDICT ' . $mode . ' ALL-ASSERTIONS-HELD' );
	$detail = '';
	if ( ! $ok ) {
		$detail = 'exit ' . $code . '; stdout: ' . trim( $stdout ) . '; stderr: ' . trim( $stderr );
	}
	b5_check(
		'MODE-' . strtoupper( str_replace( '-', '_', $mode ) ),
		$ok,
		'subprocess mode ' . $mode . ' held all its assertions (exit 0)',
		'mode ' . $mode . ' failed — ' . $detail
	);
}

/* ════════════════════════════════════════════════════════════════
 * Batch-5 fixture helpers (harness only — never project logic)
 * ════════════════════════════════════════════════════════════════ */

if ( ! class_exists( 'B5_Post' ) ) {
	class B5_Post extends WP_Post {
		public string $post_date       = '2026-01-15 10:30:00';
		public string $post_mime_type  = '';
	}
}

function b5_post( int $id, string $type = 'post', string $status = 'publish', int $author = 3, string $title = '', string $mime = '' ): B5_Post {
	$p                  = new B5_Post();
	$p->ID              = $id;
	$p->post_type       = $type;
	$p->post_status     = $status;
	$p->post_author     = $author;
	$p->post_title      = $title;
	$p->post_mime_type  = $mime;
	$GLOBALS['B5_POSTS'][ $id ] = $p;
	return $p;
}

function b5_define_admin_includes(): void {
	$dir = ABSPATH . 'wp-admin/includes';
	foreach ( array( 'file.php', 'image.php', 'media.php' ) as $stub ) {
		$path = $dir . DIRECTORY_SEPARATOR . $stub;
		if ( ! file_exists( $path ) ) {
			file_put_contents( $path, "<?php\n" );
		}
	}
}

function b5_new_wpdb() {
	return new class() {
		public string $prefix     = 'wp_';
		public string $last_error = '';
		// تفويض المالك #1 (2026-11-02): GET_LOCK/RELEASE_LOCK دلالة النواة
		// (القفل يُمنح) + query() الموجودة في wpdb النووية — تستلزمهما صفوف
		// R9/R10 عبر hossam_rate_limit_consume (عقد G3 §1.1-3: GET_LOCK=1 ⇒
		// متابعة) وhossam_audit_log (INSERT مُحضَّر واحد). بلا هذه الدلالة
		// كان كل consume يعيد 503 ويُسقط مسارات النجاح المشروعة.
		public function prepare( string $query, ...$args ): string {
			return $query;
		}
		public function get_var( string $query = null ) {
			$q = (string) $query;
			if ( false !== strpos( $q, 'GET_LOCK' ) || false !== strpos( $q, 'RELEASE_LOCK' ) ) {
				return 1;
			}
			return null;
		}
		public function query( string $sql ) {
			return 1;
		}
		public function get_results( string $query, $output = null ) {
			return array();
		}
		public function esc_like( string $text ): string {
			return $text;
		}
	};
}

function b5_begin_case( array $post = array(), int $user = 3, bool $logged_in = true, array $caps = array(), bool $with_nonce = true ): void {
	$_POST                                = $post;
	if ( $with_nonce ) {
		$_POST['nonce']                   = $GLOBALS['B5_NONCE'];
	}
	$GLOBALS['B5_USER_ID']                = $user;
	$GLOBALS['B5_LOGGED_IN']              = $logged_in;
	$GLOBALS['B5_CAPS']                   = $caps;
	$GLOBALS['B5_POSTS']                  = array();
	$GLOBALS['B5_POST_CATEGORIES']        = array();
	$GLOBALS['B5_POST_THUMBNAILS']        = array();
	$GLOBALS['B5_TERMS']                  = array();
	$GLOBALS['B5_MEDIA']                  = array( 'image_ids' => array(), 'meta' => array() );
	$GLOBALS['B5_QUERY_POSTS']            = array();
	$GLOBALS['B5_LAST_QUERY_ARGS']        = null;
	$GLOBALS['B5_FAIL_UNTRASH']           = false;
	$GLOBALS['B5_FAIL_DELETE_ATTACHMENT'] = false;
	$GLOBALS['B5_POSTS_FAIL_INSERT']      = false;
	$GLOBALS['B5_POSTS_FAIL_UPDATE']      = false;
	$GLOBALS['B5_POSTS_FAIL_DELETE']      = false;
	$GLOBALS['B5_POSTS_FAIL_CATEGORIES']  = false;
	$GLOBALS['B5_POSTS_FAIL_THUMBNAIL']   = false;
	$GLOBALS['B5_UPLOAD']                 = array( 'result' => 0, 'calls' => 0 );
	$GLOBALS['B5_WPML_SET']               = array();
	$GLOBALS['B5_WPML_LANGS']             = array();
	$GLOBALS['B5_WPML_OBJECT_ID']         = array();
	$GLOBALS['B5_WPML_TRID']              = 0;
	$GLOBALS['B5_WPML_SOURCE']            = '';
	// v1.0.6 §4/§6: reset the frozen read-back controls with every case.
	$GLOBALS['B5_WPML_DETAILS_FROZEN']    = false;
	$GLOBALS['B5_WPML_DETAILS_EXPLICIT']  = null;
	unset( $GLOBALS['B5_POSTS_FAIL_INSERT_TMP'] );
}

function b5_call_ajax( string $hook ): array {
	$entries = $GLOBALS['B5_HOOKS'][ $hook ] ?? array();
	if ( ! $entries ) {
		return array( 'registered' => false, 'status' => 0, 'body' => null );
	}
	$callback = end( $entries )['callback'];
	try {
		call_user_func( $callback );
		return array( 'registered' => true, 'status' => 200, 'body' => null );
	} catch ( B5_AjaxExit $exit ) {
		return array(
			'registered' => true,
			'status'     => $exit->status,
			'body'       => json_decode( $exit->payload, true ),
		);
	}
}

function b5_exit_is( array $result, int $status, bool $success ): bool {
	return true === ( $result['registered'] ?? false )
		&& $status === ( $result['status'] ?? 0 )
		&& is_array( $result['body'] ?? null )
		&& $success === ( $result['body']['success'] ?? null );
}

function b5_exit_data( array $result ): array {
	return is_array( $result['body']['data'] ?? null ) ? $result['body']['data'] : array();
}

/* ════════════════════════════════════════════════════════════════
 * MODES
 * ════════════════════════════════════════════════════════════════ */

function b5_mode_main( string $project ): void {
	$GLOBALS['B5_PROJECT'] = $project;
	b5_define_release_context();
	b5_define_valid_profile();
	b5_define_admin_includes();
	$GLOBALS['wpdb'] = b5_new_wpdb();

	b5_require_runtime();

	$included = array_map(
		static function ( string $path ): string {
			return str_replace( '\\', '/', $path );
		},
		get_included_files()
	);
	$runtime_included = array_values( array_filter(
		$included,
		static function ( string $path ): bool {
			return false !== strpos( $path, '/runtime/' );
		}
	) );
	$relative = array_map(
		static function ( string $path ): string {
			$marker = '/runtime/';
			$pos    = strrpos( $path, $marker );
			return false === $pos ? $path : substr( $path, $pos + strlen( '/runtime/' ) );
		},
		$runtime_included
	);

	$expected_order = array(
		'core/setup.php',
		'core/i18n.php',
		'core/tables.php',
		'core/permissions.php',
		'core/notifications.php',
		'settings/class-settings-repository.php',
		'security/class-secret-store.php',
		'integrations/class-integration-settings-registry.php',
		'health/class-integration-health-monitor.php',
		'admin/class-admin-controller.php',
		'adapters/wordpress-posts.php',
		'adapters/wordpress-uploads.php',
		'adapters/woocommerce.php',
		'adapters/wpml.php',
		'adapters/amelia.php',
		'adapters/rankmath.php',
		'adapters/ultimatemember.php',
		'adapters/ai.php',
		'ajax/posts.php',
		'ajax/uploads.php',
		// Batch 6 (integrations/services AJAX §17) — literal legacy order.
		'ajax/appointments.php',
		'ajax/finance.php',
		'ajax/members.php',
		'ajax/inbox.php',
		'ajax/store.php',
		'ajax/ai.php',
		// v1.0.6 §8.3 (documented gate change): ajax/seo.php loads ninth.
		'ajax/seo.php',
		// Batch 7 (§18) — the shared Template Controller copy loads after
		// all modules (§6.1 pre-build infrastructure copy).
		'infrastructure/class-template-controller.php',
		// Batch 11 (§22) — the Update Bridge copy loads after the
		// Template Controller (§6.1 pre-build infrastructure copy).
		'infrastructure/class-update-bridge.php',
	);
	$actual_order = array_values( array_intersect( $relative, $expected_order ) );
	b5_check( 'L1', $actual_order === $expected_order,
		'the real bootstrap loads core (5) → backend (5) → adapters (8) → ajax (9: batches 5–6 plus v1.0.6 §8.3 ajax/seo.php ninth) → the batch-7 infrastructure controller → the batch-11 update bridge in the mandated §13/§16/§17/§18/§22 order',
		'load order mismatch: ' . json_encode( $relative ) );

	$module_included = array_values( array_filter(
		$runtime_included,
		static function ( string $path ): bool {
			return false === strpos( $path, '/runtime/bootstrap.php' );
		}
	) );
	// تفويض المالك #1 (2026-11-02): 30→32 — الملفان المصرحان core/rate-limits.php وcore/audit-log.php (مرحلة 1) داخل الجرد الصارم نفسه.
	b5_check( 'L2', count( array_unique( $module_included ) ) === 32 && did_action( 'hal_frontend_dashboard_runtime_ready' ) >= 1,
		'exactly 32 runtime module files load (bootstrap aside: 20 from batches 2–4 incl. the batch-14 core/rate-limits.php + core/audit-log.php + the 9 ajax files of batches 5–6 plus v1.0.6 §8.3 seo + the batch-7 infrastructure controller + the batch-11 update bridge + the v1.0.3 health check) and runtime_ready fires after the full load',
		'unexpected runtime file set: ' . json_encode( $relative ) );

	$ajax_hooks = array_values( array_filter(
		array_keys( $GLOBALS['B5_HOOKS'] ),
		static function ( string $hook ): bool {
			return 0 === strpos( $hook, 'wp_ajax_' );
		}
	) );
	sort( $ajax_hooks );
	$expected_hooks = array(
		// Batch-5 (9) + batch-6 (17) + v1.0.6 §8.3 seo (1) + v1.0.3 health (2), globally sorted as the check sorts.
		'wp_ajax_hal_frontend_dashboard_boot_health',
		'wp_ajax_hossam_ai_get_job_status',
		'wp_ajax_hossam_ai_set_preference',
		'wp_ajax_hossam_ai_submit_job',
		'wp_ajax_hossam_create_article',
		'wp_ajax_hossam_delete_file',
		'wp_ajax_hossam_finance_summary',
		'wp_ajax_hossam_get_inbox',
		'wp_ajax_hossam_get_members',
		'wp_ajax_hossam_get_my_files',
		'wp_ajax_hossam_get_notifications',
		'wp_ajax_hossam_get_orders',
		'wp_ajax_hossam_get_payment_methods',
		'wp_ajax_hossam_get_products',
		'wp_ajax_hossam_get_saved_tokens',
		'wp_ajax_hossam_get_upcoming_appointments',
		'wp_ajax_hossam_lazy_appointments',
		'wp_ajax_hossam_mark_all_read',
		'wp_ajax_hossam_mark_message_read',
		'wp_ajax_hossam_mark_read',
		'wp_ajax_hossam_restore_article',
		'wp_ajax_hossam_restore_file',
		'wp_ajax_hossam_save_seo',
		'wp_ajax_hossam_send_message',
		'wp_ajax_hossam_translate_article',
		'wp_ajax_hossam_trash_article',
		'wp_ajax_hossam_update_article',
		'wp_ajax_hossam_upload_file',
		'wp_ajax_nopriv_hal_frontend_dashboard_boot_health',
	);
	b5_check( 'G1', $ajax_hooks === $expected_hooks,
		'the AJAX action inventory is exactly: 29 wp_ajax_* hooks (9 batch-5 + 17 batch-6 + 1 v1.0.6 §8.3 seo + 2 v1.0.3 health), nothing else added or removed',
		'inventory mismatch: ' . json_encode( $ajax_hooks ) );

	$nopriv_hooks = array_values( array_filter(
		array_keys( $GLOBALS['B5_HOOKS'] ),
		static function ( string $hook ): bool {
			return 0 === strpos( $hook, 'wp_ajax_nopriv_' );
		}
	) );
	b5_check( 'G2', array( 'wp_ajax_nopriv_hal_frontend_dashboard_boot_health' ) === $nopriv_hooks,
		'the only wp_ajax_nopriv_* hook is the v1.0.3 health handler (unauthenticated loopback), nothing else',
		'unexpected nopriv hooks: ' . json_encode( $nopriv_hooks ) );

	b5_check( 'G3', isset( $GLOBALS['B5_HOOKS']['wp_ajax_hossam_save_seo'] ),
		'wp_ajax_hossam_save_seo IS registered (ajax/seo.php loads ninth — v1.0.6 §8.3 documented gate change: runtime detection + owner key, no longer the §16 limited-read-only hold)',
		'seo endpoint must be registered after the v1.0.6 §8.3 gate change' );

	$seo_file = $project . '/runtime/ajax/seo.php';
	$seo_loaded = array_values( array_filter(
		$runtime_included,
		static function ( string $path ): bool {
			return false !== strpos( $path, '/runtime/ajax/seo.php' );
		}
	) );
	b5_check( 'G4', file_exists( $seo_file ) && array() !== $seo_loaded,
		'runtime/ajax/seo.php exists in the release and IS required ninth by the real bootstrap (v1.0.6 §8.3)',
		'seo.php missing from release or unexpectedly unloaded: ' . json_encode( $seo_loaded ) );

	$consumes_posts = false !== stripos( (string) file_get_contents( $project . '/runtime/ajax/posts.php' ), 'is_feature_enabled' );
	$consumes_uploads = false !== stripos( (string) file_get_contents( $project . '/runtime/ajax/uploads.php' ), 'is_feature_enabled' );
	b5_check( 'F1', $consumes_posts && $consumes_uploads,
		'B3-08: the batch-5 posts/uploads endpoints consume the owner feature decision via is_feature_enabled (seo.php loads ninth under its own gates — G3/G4 hold)',
		'a batch-5 ajax file misses its feature gate' );

	b5_run_mode( 'posts' );
	b5_run_mode( 'translate' );
	b5_run_mode( 'uploads' );
	b5_run_mode( 'feature-gates' );
	// T10 (v1.1.0): OEB editor-mode cycle × roles × template holders + the
	// rich_editor_mode POST bridge (handle_save_settings triple).
	b5_run_mode( 'editor-mode' );

	b5_result_line( 'main' );
}

function b5_mode_posts( string $project ): void {
	$GLOBALS['B5_PROJECT'] = $project;
	b5_define_release_context();
	b5_define_valid_profile();
	b5_define_admin_includes();
	$GLOBALS['wpdb'] = b5_new_wpdb();
	b5_require_runtime();

	// P-NONCE: nonce failure dies with 403 '-1' (wp_die semantics) before
	// anything else — the payload is the raw '-1' body, not a JSON envelope.
	b5_begin_case( array( 'post_id' => '5' ), 3, true, array(), false );
	$r = b5_call_ajax( 'wp_ajax_hossam_restore_article' );
	b5_check( 'P-NONCE', true === ( $r['registered'] ?? false ) && 403 === ( $r['status'] ?? 0 ) && -1 === ( $r['body'] ?? null ),
		'missing nonce dies 403 via check_ajax_referer on the real restore callback', 'got ' . json_encode( $r ) );

	// restore_article.
	b5_begin_case( array( 'post_id' => '5' ), 3, false, array() );
	$r = b5_call_ajax( 'wp_ajax_hossam_restore_article' );
	b5_check( 'P-R401', b5_exit_is( $r, 401, false ) && 'Unauthorized' === ( b5_exit_data( $r )['message'] ?? '' ),
		'restore logged-out → 401 Unauthorized', 'got ' . json_encode( $r ) );

	b5_begin_case( array( 'post_id' => '999' ) );
	$r = b5_call_ajax( 'wp_ajax_hossam_restore_article' );
	b5_check( 'P-R404', b5_exit_is( $r, 404, false ) && 'Article not found.' === ( b5_exit_data( $r )['message'] ?? '' ),
		'restore missing post → adapter hossam_not_found translated to 404', 'got ' . json_encode( $r ) );

	// تفويض المالك #1 (2026-11-02): P-R403 — التوقع حُدّث 403 → 404
	// 'Article not found.' (404 الموحد: الغائب والغير مملوك والبلا قدرة
	// برسالة الغائب نفسها — العقد الجديد المصرح، H3 §1.3 — صف R8
	// «دمج v1.1.0: meta lang + حقل language + 404 الموحد»؛ القديم:
	// 403 Unauthorized — المرجع: العقد :148).
	b5_begin_case( array( 'post_id' => '5' ), 3, true, array() );
	b5_post( 5, 'post', 'trash', 7 );
	$r = b5_call_ajax( 'wp_ajax_hossam_restore_article' );
	b5_check( 'P-R403', b5_exit_is( $r, 404, false ) && 'Article not found.' === ( b5_exit_data( $r )['message'] ?? '' ),
		'restore without edit_post on the article → 404 unified oracle (ownership swallowed by the existence gate)', 'got ' . json_encode( $r ) );

	b5_begin_case( array( 'post_id' => '5' ), 3, true, array( 'edit_post_5' => true ) );
	$restored = b5_post( 5, 'post', 'trash', 3 );
	$r = b5_call_ajax( 'wp_ajax_hossam_restore_article' );
	b5_check( 'P-ROK', b5_exit_is( $r, 200, true ) && 'draft' === $restored->post_status,
		'restore success path (author + edit_post) flips the trashed article via wp_untrash_post', 'got ' . json_encode( $r ) );

	// translate_article without WPML (no ICL constant): registry unavailable.
	b5_begin_case( array( 'post_id' => '5', 'lang' => 'en' ) );
	$r = b5_call_ajax( 'wp_ajax_hossam_translate_article' );
	b5_check( 'P-T503', b5_exit_is( $r, 503, false ) && 'WPML not active.' === ( b5_exit_data( $r )['message'] ?? '' ),
		'translate with the WPML registry decision unavailable → 503 from the real integration registry', 'got ' . json_encode( $r ) );

	// create_article — input shape 400s before the adapter.
	b5_begin_case( array(), 3, false, array() );
	$r = b5_call_ajax( 'wp_ajax_hossam_create_article' );
	b5_check( 'P-C401', b5_exit_is( $r, 401, false ), 'create logged-out → 401', 'got ' . json_encode( $r ) );

	b5_begin_case( array( 'title' => array( 'bad' ) ) );
	$r = b5_call_ajax( 'wp_ajax_hossam_create_article' );
	b5_check( 'P-C400T', b5_exit_is( $r, 400, false ) && 'Invalid article data.' === ( b5_exit_data( $r )['message'] ?? '' ),
		'create with a non-string title → 400 Invalid article data.', 'got ' . json_encode( $r ) );

	b5_begin_case( array( 'title' => 'x', 'categories' => 'nope' ) );
	$r = b5_call_ajax( 'wp_ajax_hossam_create_article' );
	b5_check( 'P-C400C1', b5_exit_is( $r, 400, false ) && 'Invalid categories.' === ( b5_exit_data( $r )['message'] ?? '' ),
		'create with a non-array categories field → 400 Invalid categories.', 'got ' . json_encode( $r ) );

	b5_begin_case( array( 'title' => 'x', 'categories' => array( 'abc' ) ) );
	$r = b5_call_ajax( 'wp_ajax_hossam_create_article' );
	b5_check( 'P-C400C2', b5_exit_is( $r, 400, false ) && 'Invalid categories.' === ( b5_exit_data( $r )['message'] ?? '' ),
		'create with a non-numeric category item → 400 Invalid categories.', 'got ' . json_encode( $r ) );

	b5_begin_case( array( 'title' => 'x', 'thumbnail_id' => array( 'bad' ) ) );
	$r = b5_call_ajax( 'wp_ajax_hossam_create_article' );
	b5_check( 'P-C400TH', b5_exit_is( $r, 400, false ) && 'Invalid featured image.' === ( b5_exit_data( $r )['message'] ?? '' ),
		'create with a non-scalar thumbnail_id → 400 Invalid featured image.', 'got ' . json_encode( $r ) );

	// create_article — adapter-driven errors through hossam_send_article_error.
	b5_begin_case( array( 'title' => 'x' ), 3, true, array() );
	$r = b5_call_ajax( 'wp_ajax_hossam_create_article' );
	b5_check( 'P-C403', b5_exit_is( $r, 403, false ) && 'You do not have permission to create articles.' === ( b5_exit_data( $r )['message'] ?? '' ),
		'create without edit_posts → adapter hossam_forbidden → helper maps 403', 'got ' . json_encode( $r ) );

	b5_begin_case( array( 'title' => 'x', 'categories' => array( '999999' ) ), 3, true, array( 'edit_posts' => true ) );
	$r = b5_call_ajax( 'wp_ajax_hossam_create_article' );
	b5_check( 'P-C400CAT', b5_exit_is( $r, 400, false ) && 'hossam_invalid_categories' === ( b5_exit_data( $r )['code'] ?? '' ),
		'create with an unknown category id → adapter hossam_invalid_categories → 400 with the error code', 'got ' . json_encode( $r ) );

	b5_begin_case( array( 'title' => 'x', 'thumbnail_id' => '44' ), 3, true, array( 'edit_posts' => true ) );
	b5_post( 44, 'attachment', 'inherit', 7, 'Other image', 'image/png' );
	$GLOBALS['B5_MEDIA']['image_ids'] = array( 44 );
	$r = b5_call_ajax( 'wp_ajax_hossam_create_article' );
	b5_check( 'P-C403TH', b5_exit_is( $r, 403, false ) && 'hossam_thumbnail_forbidden' === ( b5_exit_data( $r )['code'] ?? '' ),
		'create with someone else’s image attachment → adapter hossam_thumbnail_forbidden → 403', 'got ' . json_encode( $r ) );

	b5_begin_case( array( 'title' => 'x', 'categories' => array( '31' ) ), 3, true, array( 'edit_posts' => true ) );
	$GLOBALS['B5_TERMS'] = array( 'category' => array( 31 ) );
	$GLOBALS['B5_POSTS_FAIL_CATEGORIES'] = true;
	$r = b5_call_ajax( 'wp_ajax_hossam_create_article' );
	b5_check( 'P-C500', b5_exit_is( $r, 500, false ) && 'hossam_related_write_failed' === ( b5_exit_data( $r )['code'] ?? '' )
		&& 'create_related_write' === ( b5_exit_data( $r )['stage'] ?? '' ) && 'done' === ( b5_exit_data( $r )['cleanup'] ?? '' )
		&& ! array_key_exists( 'post_id', b5_exit_data( $r ) ),
		'create with failed category write → adapter hossam_related_write_failed → 500 with stage/cleanup scalars only (no post_id)',
		'got ' . json_encode( $r ) );

	b5_begin_case( array( 'title' => 'x', 'categories' => array( '31' ) ), 3, true, array( 'edit_posts' => true ) );
	$GLOBALS['B5_TERMS'] = array( 'category' => array( 31 ) );
	$GLOBALS['B5_POSTS_FAIL_CATEGORIES'] = true;
	$GLOBALS['B5_POSTS_FAIL_DELETE']     = true;
	$log_file = $project . '/.local-execution/batch-5/error-log-capture.txt';
	if ( file_exists( $log_file ) ) {
		unlink( $log_file );
	} else {
		// Fresh checkouts lack the gitignored run-space: error_log() to a
		// missing directory fails silently to stderr, so create it first.
		@mkdir( dirname( $log_file ), 0777, true );
	}
	ini_set( 'error_log', $log_file );
	$r = b5_call_ajax( 'wp_ajax_hossam_create_article' );
	$log_ok = file_exists( $log_file ) && false !== strpos( (string) file_get_contents( $log_file ), 'draft cleanup failed' );
	b5_check( 'P-C500P', b5_exit_is( $r, 500, false ) && 'hossam_partial_failure' === ( b5_exit_data( $r )['code'] ?? '' )
		&& array_key_exists( 'post_id', b5_exit_data( $r ) ) && 'failed' === ( b5_exit_data( $r )['cleanup'] ?? '' ) && $log_ok,
		'create with failed category write AND failed draft cleanup → hossam_partial_failure (cleanup=failed) + the real error_log line is emitted (no secrets, post id only)',
		'got ' . json_encode( $r ) . '; log_ok=' . var_export( $log_ok, true ) );
	ini_set( 'error_log', '' );

	b5_begin_case( array( 'title' => 'x' ), 3, true, array( 'edit_posts' => true ) );
	$rows_before_insert_fail = count( $GLOBALS['B5_POSTS'] );
	$GLOBALS['B5_POSTS_FAIL_INSERT'] = true;
	$r = b5_call_ajax( 'wp_ajax_hossam_create_article' );
	b5_check( 'P-C500I', b5_exit_is( $r, 500, false ) && 'insert failed fixture' === ( b5_exit_data( $r )['message'] ?? '' )
		&& count( $GLOBALS['B5_POSTS'] ) === $rows_before_insert_fail,
		'create with a failing Core wp_insert_post → 500 through the real callback with no post rows added',
		'got ' . json_encode( $r ) );

	b5_begin_case( array( 'title' => 'x', 'thumbnail_id' => '44' ), 3, true, array( 'edit_posts' => true, 'edit_post_44' => true ) );
	b5_post( 44, 'attachment', 'inherit', 3, 'Own image', 'image/png' );
	$GLOBALS['B5_MEDIA']['image_ids'] = array( 44 );
	$GLOBALS['B5_POSTS_FAIL_THUMBNAIL'] = true;
	$r = b5_call_ajax( 'wp_ajax_hossam_create_article' );
	b5_check( 'P-C500T', b5_exit_is( $r, 500, false ) && 'The featured image could not be saved.' === ( b5_exit_data( $r )['message'] ?? '' ),
		'create with a failing set_post_thumbnail → 500 through the real callback',
		'got ' . json_encode( $r ) );

	// create_article — success contract.
	b5_begin_case( array( 'title' => 'مرحبا <b>Test</b>', 'content' => 'Body', 'categories' => array( '31' ) ), 3, true, array( 'edit_posts' => true ) );
	$GLOBALS['B5_TERMS'] = array( 'category' => array( 31 ) );
	$r = b5_call_ajax( 'wp_ajax_hossam_create_article' );
	$data = b5_exit_data( $r );
	$new_id = (int) ( $data['post_id'] ?? 0 );
	$created_ok = b5_exit_is( $r, 200, true ) && $new_id >= 1
		&& isset( $GLOBALS['B5_POSTS'][ $new_id ] )
		&& 'مرحبا <b>Test</b>' === $GLOBALS['B5_POSTS'][ $new_id ]->post_title
		&& 3 === $GLOBALS['B5_POSTS'][ $new_id ]->post_author
		&& 'draft' === $GLOBALS['B5_POSTS'][ $new_id ]->post_status
		&& array( 31 ) === ( $GLOBALS['B5_POST_CATEGORIES'][ $new_id ] ?? array() )
		&& false !== strpos( (string) ( $data['edit_url'] ?? '' ), 'panel=edit-article' );
	b5_check( 'P-COK', $created_ok,
		'create success → {post_id,edit_url} with the draft authored by the current user, sanitized title, categories applied',
		'got ' . json_encode( $r ) );

	// update_article.
	b5_begin_case( array( 'post_id' => '6', 'title' => 'New' ) );
	$r = b5_call_ajax( 'wp_ajax_hossam_update_article' );
	b5_check( 'P-U404', b5_exit_is( $r, 404, false ), 'update missing post → 404', 'got ' . json_encode( $r ) );

	// تفويض المالك #1 (2026-11-02): P-U403 — التوقع حُدّث 403 → 404
	// 'Article not found.' (404 الموحد: الغائب والغير مملوك والبلا قدرة
	// برسالة الغائب نفسها — العقد الجديد المصرح، H3 §1.3 — صف R8
	// «دمج v1.1.0: meta lang + حقل language + 404 الموحد»؛ القديم:
	// 403 Unauthorized — المرجع: العقد :148).
	b5_begin_case( array( 'post_id' => '6', 'title' => 'New' ), 3, true, array() );
	b5_post( 6, 'post', 'publish', 7, 'Old' );
	$r = b5_call_ajax( 'wp_ajax_hossam_update_article' );
	b5_check( 'P-U403', b5_exit_is( $r, 404, false ), 'update without edit_post on the article → 404 unified oracle (ownership swallowed by the existence gate)', 'got ' . json_encode( $r ) );

	b5_begin_case( array( 'post_id' => '6', 'title' => 'New', 'thumbnail_id' => array( 'bad' ) ), 3, true, array( 'edit_post_6' => true ) );
	b5_post( 6, 'post', 'publish', 3, 'Old' );
	$r = b5_call_ajax( 'wp_ajax_hossam_update_article' );
	b5_check( 'P-U400', b5_exit_is( $r, 400, false ) && 'Invalid featured image.' === ( b5_exit_data( $r )['message'] ?? '' ),
		'update with a non-scalar thumbnail_id → 400 before the adapter', 'got ' . json_encode( $r ) );

	b5_begin_case( array( 'post_id' => '6', 'title' => 'New' ), 3, true, array( 'edit_post_6' => true ) );
	$updated = b5_post( 6, 'post', 'publish', 3, 'Old' );
	$r = b5_call_ajax( 'wp_ajax_hossam_update_article' );
	b5_check( 'P-UOK', b5_exit_is( $r, 200, true ) && 'New' === $updated->post_title,
		'update success path applies the sanitized title via the real adapter', 'got ' . json_encode( $r ) );

	b5_begin_case( array( 'post_id' => '6', 'title' => 'New' ), 3, true, array( 'edit_post_6' => true ) );
	$stale = b5_post( 6, 'post', 'publish', 3, 'Old' );
	$GLOBALS['B5_POSTS_FAIL_UPDATE'] = true;
	$r = b5_call_ajax( 'wp_ajax_hossam_update_article' );
	b5_check( 'P-U500', b5_exit_is( $r, 500, false ) && 'update failed fixture' === ( b5_exit_data( $r )['message'] ?? '' )
		&& 'Old' === $stale->post_title,
		'update with a failing Core wp_update_post → 500 through the real callback with the stored title untouched',
		'got ' . json_encode( $r ) );

	// trash_article.
	b5_begin_case( array( 'post_id' => '6' ) );
	$r = b5_call_ajax( 'wp_ajax_hossam_trash_article' );
	b5_check( 'P-D404', b5_exit_is( $r, 404, false ), 'trash missing post → 404', 'got ' . json_encode( $r ) );

	// تفويض المالك #1 (2026-11-02): P-D403 — التوقع حُدّث 403 → 404
	// 'Article not found.' (404 الموحد: الغائب والغير مملوك والبلا قدرة
	// برسالة الغائب نفسها — العقد الجديد المصرح، H3 §1.3 — صف R8
	// «دمج v1.1.0: meta lang + حقل language + 404 الموحد»؛ القديم:
	// 403 Unauthorized — المرجع: العقد :148).
	b5_begin_case( array( 'post_id' => '6' ), 3, true, array() );
	b5_post( 6, 'post', 'publish', 7, 'Old' );
	$r = b5_call_ajax( 'wp_ajax_hossam_trash_article' );
	b5_check( 'P-D403', b5_exit_is( $r, 404, false ), 'trash without edit_post → 404 unified oracle (ownership swallowed by the existence gate)', 'got ' . json_encode( $r ) );

	b5_begin_case( array( 'post_id' => '6' ), 3, true, array( 'edit_post_6' => true ) );
	$trashed = b5_post( 6, 'post', 'publish', 3, 'Old' );
	$r = b5_call_ajax( 'wp_ajax_hossam_trash_article' );
	b5_check( 'P-DOK', b5_exit_is( $r, 200, true ) && 'trash' === $trashed->post_status,
		'trash success path moves the article to trash via wp_trash_post', 'got ' . json_encode( $r ) );

	b5_result_line( 'posts' );
}

function b5_mode_translate( string $project ): void {
	$GLOBALS['B5_PROJECT'] = $project;
	// WPML fixture: the official plugin constant + plugin-active entry +
	// the two capability filters the real registry probes.
	if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
		define( 'ICL_SITEPRESS_VERSION', '4.6.9-fixture' );
	}
	b5_define_release_context();
	b5_define_valid_profile();
	b5_define_admin_includes();
	$GLOBALS['wpdb']  = b5_new_wpdb();
	$GLOBALS['B5_PLUGIN_ACTIVE'] = array( 'sitepress-multilingual-cms/sitepress.php' );
	add_filter( 'wpml_permalink', static function ( $url, ...$rest ) {
		return $url;
	}, 10, 2 );
	add_filter( 'wpml_active_languages', static function ( $languages, ...$rest ) {
		return $GLOBALS['B5_WPML_LANGS'];
	}, 10, 2 );
	add_filter( 'wpml_object_id', static function ( $id, $type = null, $return_original = null, $lang = null ) {
		return $GLOBALS['B5_WPML_OBJECT_ID'][ (int) $id ][ (string) $lang ] ?? 0;
	}, 10, 4 );
	add_filter( 'wpml_element_trid', static function ( $value, $element_id = null, $element_type = null ) {
		return $GLOBALS['B5_WPML_TRID'];
	}, 10, 3 );
	add_filter( 'wpml_element_language_code', static function ( $value, $details = null ) {
		return $GLOBALS['B5_WPML_SOURCE'];
	}, 10, 2 );
	add_action( 'wpml_set_element_language_details', static function ( array $details ): void {
		$GLOBALS['B5_WPML_SET'][] = $details;
	}, 10, 1 );
	// v1.0.6 §4/§6: register the two official read-back contracts in the
	// frozen mock (fail-closed is the contract — see W-NEW-FAILCLOSED).
	// Unfrozen, details are re-read from the recorded links and the
	// translations map is rebuilt per trid, so W-NEW proves the verified
	// success path instead of the historic assume-success path. Stubs prove
	// branches only — the live WPML read-back stays a field gate (G-WPML).
	add_filter( 'wpml_element_language_details', static function ( $value, $args = null ) {
		if ( ! empty( $GLOBALS['B5_WPML_DETAILS_FROZEN'] ) ) {
			return $GLOBALS['B5_WPML_DETAILS_EXPLICIT'];
		}
		$eid = is_array( $args ) ? (int) ( $args['element_id'] ?? 0 ) : 0;
		foreach ( array_reverse( $GLOBALS['B5_WPML_SET'] ?? array() ) as $recorded ) {
			if ( $eid > 0 && (int) ( $recorded['element_id'] ?? 0 ) === $eid ) {
				return array(
					'trid'                 => (int) ( $recorded['trid'] ?? 0 ),
					'language_code'        => (string) ( $recorded['language_code'] ?? '' ),
					'source_language_code' => (string) ( $recorded['source_language_code'] ?? '' ),
				);
			}
		}
		return null;
	}, 10, 2 );
	add_filter( 'wpml_get_element_translations', static function ( $value, $trid = null, $type = null ) {
		$map = array();
		foreach ( $GLOBALS['B5_WPML_SET'] ?? array() as $recorded ) {
			if ( (int) $trid > 0 && (int) ( $recorded['trid'] ?? 0 ) === (int) $trid ) {
				$lc = (string) ( $recorded['language_code'] ?? '' );
				if ( '' !== $lc ) {
					$map[ $lc ] = array(
						'element_id'           => (int) ( $recorded['element_id'] ?? 0 ),
						'language_code'        => $lc,
						'source_language_code' => (string) ( $recorded['source_language_code'] ?? '' ),
					);
				}
			}
		}
		return array() === $map ? null : $map;
	}, 10, 3 );
	b5_require_runtime();

	$decision = hossam_get_integration_decision( 'wpml' );
	b5_check( 'W-A', 'available' === $decision['status'] && true === ( $decision['capabilities']['languages'] ?? null ),
		'the real registry classifies the WPML fixture as available with the languages capability',
		'got ' . json_encode( $decision ) );

	// تفويض المالك #1 (2026-11-02): W-403 — التوقع حُدّث 403 → 404
	// 'Article not found.' (404 الموحد في فرع الترجمة: الغائب والغير مملوك
	// برسالة الغائب نفسها — العقد الجديد المصرح، H3 §1.3 — صف R8
	// «دمج v1.1.0: meta lang + حقل language + 404 الموحد»؛ القديم:
	// 403 Unauthorized — المرجع: العقد :148).
	b5_begin_case( array( 'post_id' => '5', 'lang' => 'en' ), 3, true, array() );
	b5_post( 5, 'post', 'publish', 7, 'Source' );
	$GLOBALS['B5_WPML_LANGS']      = array( 'en' => array( 'code' => 'en' ), 'ar' => array( 'code' => 'ar' ) );
	$r = b5_call_ajax( 'wp_ajax_hossam_translate_article' );
	b5_check( 'W-403', b5_exit_is( $r, 404, false ) && 'Article not found.' === ( b5_exit_data( $r )['message'] ?? '' ),
		'translate source article without edit_post → 404 unified oracle (registry gate passed, existence+ownership swallowed by one gate)', 'got ' . json_encode( $r ) );

	b5_begin_case( array( 'post_id' => '5', 'lang' => 'fr' ), 3, true, array( 'edit_post_5' => true ) );
	b5_post( 5, 'post', 'publish', 3, 'Source' );
	$GLOBALS['B5_WPML_LANGS']      = array( 'en' => array( 'code' => 'en' ), 'ar' => array( 'code' => 'ar' ) );
	$r = b5_call_ajax( 'wp_ajax_hossam_translate_article' );
	b5_check( 'W-400', b5_exit_is( $r, 400, false ) && 'Invalid language.' === ( b5_exit_data( $r )['message'] ?? '' ),
		'translate with a language outside the active languages → 400 Invalid language.', 'got ' . json_encode( $r ) );

	b5_begin_case( array( 'post_id' => '5', 'lang' => 'en' ), 3, true, array( 'edit_post_5' => true, 'edit_post_12' => true ) );
	b5_post( 5, 'post', 'publish', 3, 'Source' );
	$GLOBALS['B5_WPML_LANGS']        = array( 'en' => array( 'code' => 'en' ) );
	$GLOBALS['B5_WPML_OBJECT_ID']    = array( 5 => array( 'en' => 12 ) );
	b5_post( 12, 'post', 'draft', 3, 'Existing translation' );
	$GLOBALS['B5_WPML_TRID']         = 5;
	$GLOBALS['B5_WPML_SOURCE']       = 'en';
	$posts_before = count( $GLOBALS['B5_POSTS'] );
	$r = b5_call_ajax( 'wp_ajax_hossam_translate_article' );
	$data = b5_exit_data( $r );
	b5_check( 'W-EXIST', b5_exit_is( $r, 200, true ) && 12 === (int) ( $data['post_id'] ?? 0 )
		&& true === ( $data['existing'] ?? null ) && false !== strpos( (string) ( $data['edit_url'] ?? '' ), 'panel=edit-article' )
		&& count( $GLOBALS['B5_POSTS'] ) === $posts_before && array() === $GLOBALS['B5_WPML_SET'],
		'translate with an existing translation → {post_id,edit_url,existing:true} and no new post or language mutation',
		'got ' . json_encode( $r ) );

	b5_begin_case( array( 'post_id' => '5', 'lang' => 'en' ), 3, true, array( 'edit_post_5' => true ) );
	b5_post( 5, 'post', 'publish', 3, 'Source' );
	$GLOBALS['B5_WPML_LANGS']        = array( 'en' => array( 'code' => 'en' ) );
	$GLOBALS['B5_WPML_OBJECT_ID']    = array( 5 => array( 'en' => 12 ) );
	b5_post( 12, 'post', 'draft', 7, 'Existing translation' );
	$GLOBALS['B5_WPML_TRID']         = 5;
	$GLOBALS['B5_WPML_SOURCE']       = 'en';
	$r = b5_call_ajax( 'wp_ajax_hossam_translate_article' );
	b5_check( 'W-EXIST403', b5_exit_is( $r, 403, false ) && 'You do not have permission to edit this translation.' === ( b5_exit_data( $r )['message'] ?? '' ),
		'translate onto an existing translation the user cannot edit → 403', 'got ' . json_encode( $r ) );

	b5_begin_case( array( 'post_id' => '5', 'lang' => 'en' ), 3, true, array( 'edit_post_5' => true ) );
	b5_post( 5, 'post', 'publish', 3, 'Source' );
	$GLOBALS['B5_WPML_LANGS']        = array( 'en' => array( 'code' => 'en' ) );
	$GLOBALS['B5_WPML_TRID']         = 0;
	$GLOBALS['B5_WPML_SOURCE']       = 'en';
	$r = b5_call_ajax( 'wp_ajax_hossam_translate_article' );
	b5_check( 'W-TRID503', b5_exit_is( $r, 503, false ) && 'Translation contract is unavailable.' === ( b5_exit_data( $r )['message'] ?? '' ),
		'translate with trid=0 → 503 Translation contract is unavailable.', 'got ' . json_encode( $r ) );

	b5_begin_case( array( 'post_id' => '5', 'lang' => 'ar' ), 3, true, array( 'edit_post_5' => true ) );
	$source_post = b5_post( 5, 'post', 'publish', 3, 'Source article' );
	$GLOBALS['B5_WPML_LANGS']        = array( 'en' => array( 'code' => 'en' ), 'ar' => array( 'code' => 'ar' ) );
	$GLOBALS['B5_WPML_OBJECT_ID']    = array();
	$GLOBALS['B5_WPML_TRID']         = 5;
	$GLOBALS['B5_WPML_SOURCE']       = 'en';
	$r = b5_call_ajax( 'wp_ajax_hossam_translate_article' );
	$data     = b5_exit_data( $r );
	$new_id   = (int) ( $data['post_id'] ?? 0 );
	$set_entry = $GLOBALS['B5_WPML_SET'][0] ?? array();
	$new_ok = b5_exit_is( $r, 200, true ) && $new_id >= 1 && false === ( $data['existing'] ?? true )
		&& isset( $GLOBALS['B5_POSTS'][ $new_id ] )
		&& 'draft' === $GLOBALS['B5_POSTS'][ $new_id ]->post_status
		&& 3 === $GLOBALS['B5_POSTS'][ $new_id ]->post_author
		&& 'Source article' === $GLOBALS['B5_POSTS'][ $new_id ]->post_title
		&& ( $set_entry['element_id'] ?? 0 ) === $new_id && 5 === ( $set_entry['trid'] ?? 0 )
		&& 'ar' === ( $set_entry['language_code'] ?? '' ) && 'en' === ( $set_entry['source_language_code'] ?? '' )
		&& false !== strpos( (string) ( $data['edit_url'] ?? '' ), 'panel=edit-article' );
	b5_check( 'W-NEW', $new_ok,
		'translate creating a linked draft → {post_id,edit_url,existing:false} with the draft authored by the current user, the real wpml_set_element_language_details payload (trid/source/lang), AND the two-contract read-back match (details + translations) — v1.0.6 §4/§6: no success before the target-language key carries the new article',
		'got ' . json_encode( $r ) . '; set=' . json_encode( $set_entry ) );

	// v1.0.6 §4/§6 (fail-closed is the contract): freeze the read-back to a
	// mismatching answer — the link is attempted (do_action recorded) but the
	// endpoint must refuse success, delete the created draft (cleanup=done),
	// and report translation_link_failed. Stubs prove the branch; the live
	// WPML read-back stays a field gate (G-WPML).
	b5_begin_case( array( 'post_id' => '5', 'lang' => 'ar' ), 3, true, array( 'edit_post_5' => true ) );
	b5_post( 5, 'post', 'publish', 3, 'Source article' );
	$GLOBALS['B5_WPML_LANGS']           = array( 'en' => array( 'code' => 'en' ), 'ar' => array( 'code' => 'ar' ) );
	$GLOBALS['B5_WPML_OBJECT_ID']       = array();
	$GLOBALS['B5_WPML_TRID']            = 5;
	$GLOBALS['B5_WPML_SOURCE']          = 'en';
	$GLOBALS['B5_WPML_DETAILS_FROZEN']  = true;
	$GLOBALS['B5_WPML_DETAILS_EXPLICIT'] = array( 'trid' => 6, 'language_code' => 'fr', 'source_language_code' => 'en' );
	$failclosed_before = count( $GLOBALS['B5_POSTS'] );
	$r = b5_call_ajax( 'wp_ajax_hossam_translate_article' );
	$failclosed_data = b5_exit_data( $r );
	$failclosed_new_id = 0;
	foreach ( $GLOBALS['B5_WPML_SET'] as $attempt ) {
		if ( 5 === (int) ( $attempt['trid'] ?? 0 ) && 'ar' === (string) ( $attempt['language_code'] ?? '' ) ) {
			$failclosed_new_id = (int) ( $attempt['element_id'] ?? 0 );
		}
	}
	b5_check( 'W-NEW-FAILCLOSED', b5_exit_is( $r, 500, false ) && 'translation_link_failed' === ( $failclosed_data['code'] ?? '' )
		&& 'done' === ( $failclosed_data['cleanup'] ?? '' )
		&& $failclosed_new_id >= 1 && ! isset( $GLOBALS['B5_POSTS'][ $failclosed_new_id ] )
		&& count( $GLOBALS['B5_POSTS'] ) === $failclosed_before,
		'translate with an unverifiable link → 500 translation_link_failed (cleanup=done): the created draft is deleted and no success is reported before the read-back match',
		'got ' . json_encode( $r ) . '; set=' . json_encode( $GLOBALS['B5_WPML_SET'] ) );

	b5_begin_case( array( 'post_id' => '5', 'lang' => 'ar' ), 3, true, array( 'edit_post_5' => true ) );
	b5_post( 5, 'post', 'publish', 3, 'Source article' );
	$GLOBALS['B5_WPML_LANGS']        = array( 'en' => array( 'code' => 'en' ), 'ar' => array( 'code' => 'ar' ) );
	$GLOBALS['B5_WPML_OBJECT_ID']    = array();
	$GLOBALS['B5_WPML_TRID']         = 5;
	$GLOBALS['B5_WPML_SOURCE']       = 'en';
	$translate_rows_before = count( $GLOBALS['B5_POSTS'] );
	$GLOBALS['B5_POSTS_FAIL_INSERT'] = true;
	$r = b5_call_ajax( 'wp_ajax_hossam_translate_article' );
	b5_check( 'W-NEW500I', b5_exit_is( $r, 500, false ) && 'insert failed fixture' === ( b5_exit_data( $r )['message'] ?? '' )
		&& count( $GLOBALS['B5_POSTS'] ) === $translate_rows_before && array() === $GLOBALS['B5_WPML_SET'],
		'translate with a failing Core wp_insert_post → 500 through the real callback with no draft rows and no language mutation',
		'got ' . json_encode( $r ) );

	b5_result_line( 'translate' );
}

function b5_mode_feature_gates( string $project ): void {
	$GLOBALS['B5_PROJECT'] = $project;
	b5_define_release_context();
	b5_define_valid_profile();
	b5_define_admin_includes();
	$GLOBALS['wpdb'] = b5_new_wpdb();
	b5_require_runtime();

	$saved = HAL_Frontend_Dashboard_Settings_Repository::save(
		array( 'features' => array( 'posts' => false, 'files' => false, 'wpml_translations' => false ) )
	);
	b5_check( 'G-SAVE', true === $saved
		&& false === HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'posts' )
		&& false === HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'files' )
		&& false === HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'wpml_translations' ),
		'the owner posts/files/translations decisions switch off through the real repository',
		'got ' . json_encode( HAL_Frontend_Dashboard_Settings_Repository::get_all()['features'] ) );

	// posts disabled → the five article endpoints refuse before any work.
	b5_begin_case( array( 'post_id' => '5' ), 3, true, array( 'edit_post_5' => true ) );
	$kept = b5_post( 5, 'post', 'trash', 3, 'Keep me' );
	$r = b5_call_ajax( 'wp_ajax_hossam_restore_article' );
	b5_check( 'G-POSTS-RESTORE', b5_exit_is( $r, 403, false ) && 'feature_disabled' === ( b5_exit_data( $r )['code'] ?? '' )
		&& 'trash' === $kept->post_status,
		'restore with posts disabled → 403 feature_disabled with the trashed article preserved',
		'got ' . json_encode( $r ) );

	b5_begin_case( array( 'title' => 'x' ), 3, true, array( 'edit_posts' => true ) );
	$rows_before = count( $GLOBALS['B5_POSTS'] );
	$r = b5_call_ajax( 'wp_ajax_hossam_create_article' );
	b5_check( 'G-POSTS-CREATE', b5_exit_is( $r, 403, false ) && 'feature_disabled' === ( b5_exit_data( $r )['code'] ?? '' )
		&& count( $GLOBALS['B5_POSTS'] ) === $rows_before,
		'create with posts disabled → 403 feature_disabled with no post rows added',
		'got ' . json_encode( $r ) );

	b5_begin_case( array( 'post_id' => '6', 'title' => 'New' ), 3, true, array( 'edit_post_6' => true ) );
	$held = b5_post( 6, 'post', 'publish', 3, 'Old' );
	$r = b5_call_ajax( 'wp_ajax_hossam_update_article' );
	b5_check( 'G-POSTS-UPDATE', b5_exit_is( $r, 403, false ) && 'feature_disabled' === ( b5_exit_data( $r )['code'] ?? '' )
		&& 'Old' === $held->post_title,
		'update with posts disabled → 403 feature_disabled with the stored title untouched',
		'got ' . json_encode( $r ) );

	b5_begin_case( array( 'post_id' => '6' ), 3, true, array( 'edit_post_6' => true ) );
	$held_trash = b5_post( 6, 'post', 'publish', 3, 'Old' );
	$r = b5_call_ajax( 'wp_ajax_hossam_trash_article' );
	b5_check( 'G-POSTS-TRASH', b5_exit_is( $r, 403, false ) && 'feature_disabled' === ( b5_exit_data( $r )['code'] ?? '' )
		&& 'publish' === $held_trash->post_status,
		'trash with posts disabled → 403 feature_disabled with the article status preserved',
		'got ' . json_encode( $r ) );

	b5_begin_case( array( 'post_id' => '5', 'lang' => 'ar' ), 3, true, array( 'edit_post_5' => true ) );
	b5_post( 5, 'post', 'publish', 3, 'Source article' );
	$translate_rows_before = count( $GLOBALS['B5_POSTS'] );
	$r = b5_call_ajax( 'wp_ajax_hossam_translate_article' );
	b5_check( 'G-TRANSLATE', b5_exit_is( $r, 403, false ) && 'feature_disabled' === ( b5_exit_data( $r )['code'] ?? '' )
		&& count( $GLOBALS['B5_POSTS'] ) === $translate_rows_before,
		'translate with translations disabled → 403 feature_disabled before any registry/translation work',
		'got ' . json_encode( $r ) );

	// files disabled → the four file endpoints refuse before any work.
	b5_begin_case( array(), 3, true, array() );
	$r = b5_call_ajax( 'wp_ajax_hossam_get_my_files' );
	b5_check( 'G-FILES-GET', b5_exit_is( $r, 403, false ) && 'feature_disabled' === ( b5_exit_data( $r )['code'] ?? '' ),
		'get_my_files with files disabled → 403 feature_disabled', 'got ' . json_encode( $r ) );

	b5_begin_case( array( 'file_id' => '8' ), 3, true, array( 'edit_post_8' => true ) );
	$kept_file = b5_post( 8, 'attachment', 'trash', 3, 'Mine', 'image/png' );
	$r = b5_call_ajax( 'wp_ajax_hossam_restore_file' );
	b5_check( 'G-FILES-RESTORE', b5_exit_is( $r, 403, false ) && 'feature_disabled' === ( b5_exit_data( $r )['code'] ?? '' )
		&& 'trash' === $kept_file->post_status,
		'restore_file with files disabled → 403 feature_disabled with the attachment preserved',
		'got ' . json_encode( $r ) );

	b5_begin_case( array( 'file_id' => '9' ), 3, true, array( 'delete_post_9' => true ) );
	b5_post( 9, 'attachment', 'inherit', 3, 'Mine', 'image/png' );
	$r = b5_call_ajax( 'wp_ajax_hossam_delete_file' );
	b5_check( 'G-FILES-DELETE', b5_exit_is( $r, 403, false ) && 'feature_disabled' === ( b5_exit_data( $r )['code'] ?? '' )
		&& isset( $GLOBALS['B5_POSTS'][9] ),
		'delete_file with files disabled → 403 feature_disabled with the attachment preserved (no delete call)',
		'got ' . json_encode( $r ) );

	b5_begin_case( array(), 3, true, array( 'upload_files' => true ) );
	$GLOBALS['B5_UPLOAD'] = array( 'result' => 0, 'calls' => 0 );
	$r = b5_call_ajax( 'wp_ajax_hossam_upload_file' );
	b5_check( 'G-FILES-UPLOAD', b5_exit_is( $r, 403, false ) && 'feature_disabled' === ( b5_exit_data( $r )['code'] ?? '' )
		&& 0 === $GLOBALS['B5_UPLOAD']['calls'],
		'upload_file with files disabled → 403 feature_disabled before the upload adapter runs',
		'got ' . json_encode( $r ) );

	// Re-enable → authorization stays independent: no caps still 403 by
	// capability (not by feature), authorized work succeeds again.
	HAL_Frontend_Dashboard_Settings_Repository::save(
		array( 'features' => array( 'posts' => true, 'files' => true, 'wpml_translations' => true ) )
	);
	// تفويض المالك #1 (2026-11-02): G-REENABLE-CAP — التوقع حُدّث 403 → 404
	// 'Article not found.' (404 الموحد: الغائب والغير مملوك والبلا قدرة
	// برسالة الغائب نفسها — العقد الجديد المصرح، H3 §1.3 — صف R8
	// «دمج v1.1.0: meta lang + حقل language + 404 الموحد»؛ القديم:
	// 403 Unauthorized — المرجع: العقد :148).
	b5_begin_case( array( 'post_id' => '5' ), 3, true, array() );
	b5_post( 5, 'post', 'trash', 7, 'Other' );
	$r = b5_call_ajax( 'wp_ajax_hossam_restore_article' );
	b5_check( 'G-REENABLE-CAP', b5_exit_is( $r, 404, false ) && 'Article not found.' === ( b5_exit_data( $r )['message'] ?? '' ),
		're-enabled posts with no edit_post → 404 unified oracle by capability (enabling never bypasses authorization)',
		'got ' . json_encode( $r ) );

	b5_begin_case( array( 'file_id' => '9' ), 3, true, array( 'delete_post_9' => true ) );
	b5_post( 9, 'attachment', 'inherit', 3, 'Mine', 'image/png' );
	$r = b5_call_ajax( 'wp_ajax_hossam_delete_file' );
	b5_check( 'G-REENABLE-OK', b5_exit_is( $r, 200, true ) && ! isset( $GLOBALS['B5_POSTS'][9] ),
		're-enabled files with delete_post → 200 and the own attachment removed',
		'got ' . json_encode( $r ) );

	b5_result_line( 'feature-gates' );
}

/* ════════════════════════════════════════════════════════════════
 * T10 (v1.1.0) — OEB mode cycle × roles × template holders + the
 * rich_editor_mode POST bridge (R36) as an independent triple carried
 * over as a PATTERN from settings-and-secrets-test.php (admin-access,
 * b2s_capture_redirect:469 + the saved/forbidden/save_failed checks
 * :681-718): forbidden without the capability / invalid → save_failed /
 * saved then re-display. The posts part renders through the REAL
 * Template Controller (render_part) with the real repository readers —
 * only WordPress itself is stubbed.
 * ════════════════════════════════════════════════════════════════ */
function b5_mode_editor_mode( string $project ): void {
	$GLOBALS['B5_PROJECT'] = $project;
	b5_define_release_context();
	b5_define_valid_profile();
	b5_define_admin_includes();
	$GLOBALS['wpdb'] = b5_new_wpdb();
	b5_require_runtime();

	$admin_caps  = array(
		'manage_options'                => true,
		'manage_hal_frontend_dashboard' => true, // Settings_Repository::CAPABILITY — the admin-handler gate.
		'edit_posts'                    => true,
		'edit_post_9'                   => true,
		'publish_posts'                 => true,
	);
	$editor_caps = array( 'edit_posts' => true, 'edit_post_9' => true, 'publish_posts' => true );

	$set_mode = static function ( string $mode ): void {
		$saved = HAL_Frontend_Dashboard_Settings_Repository::save( array(
			'features'         => array( 'posts' => true, 'rich_editor' => true ),
			'rich_editor_mode' => $mode,
		) );
		if ( is_wp_error( $saved ) ) {
			throw new RuntimeException( 'fixture mode save failed: ' . $saved->get_error_code() );
		}
		HAL_Frontend_Dashboard_Settings_Repository::invalidate_cache();
	};

	/* Renders the REAL posts part through the real Template Controller for
	 * one (role × mode) cell. A fresh query per render keeps the loop at
	 * its start; url_post_id=9 mounts the edit panel too. */
	$render_posts = static function ( array $caps ): string {
		$query               = new WP_Query();
		$query->posts        = array( 9 );
		$query->found_posts  = 1;
		$query->post_count   = 1;
		$query->max_num_pages = 1;
		b5_begin_case( array(), 3, true, $caps );
		b5_post( 9, 'post', 'publish', 3, 'Mode fixture article' );
		ob_start();
		HAL_Frontend_Dashboard_Template_Controller::render_part( 'posts', array(
			'_dash_base'     => 'https://site.test/dashboard/',
			'url_panel'      => 'edit-article',
			'url_post_id'    => 9,
			'articles_query' => $query,
			/* shell-provided role args the part consumes (b8_full_args subset). */
			'is_writer'      => true,
			'is_author'      => false,
			'is_editor'      => false,
			'is_admin'       => false,
			'author_filter'  => false,
		) );
		return (string) ob_get_clean();
	};

	/* E-NORM — تطبيع ×3 على القارئين الحقيقيين: missing → embed (fail-closed)،
	 * الثلاثي يمر، الخارج عن القائمة → WP_Error من save() والقيمة تبقى،
	 * وتعطيل rich_editor يطوي effective إلى off. */
	unset( $GLOBALS['B5_OPTIONS'][ HAL_Frontend_Dashboard_Settings_Repository::OPTION_NAME ] );
	HAL_Frontend_Dashboard_Settings_Repository::invalidate_cache();
	$norm_missing = HAL_Frontend_Dashboard_Settings_Repository::get_rich_editor_mode();
	$set_mode( 'official' );
	$norm_official_raw  = HAL_Frontend_Dashboard_Settings_Repository::get_rich_editor_mode();
	$norm_official_eff  = HAL_Frontend_Dashboard_Settings_Repository::get_effective_editor_mode();
	$set_mode( 'embed' );
	$norm_embed_raw = HAL_Frontend_Dashboard_Settings_Repository::get_rich_editor_mode();
	$set_mode( 'off' );
	$norm_off_raw = HAL_Frontend_Dashboard_Settings_Repository::get_rich_editor_mode();
	$norm_off_eff = HAL_Frontend_Dashboard_Settings_Repository::get_effective_editor_mode();
	$invalid_save = HAL_Frontend_Dashboard_Settings_Repository::save( array( 'rich_editor_mode' => 'mirror' ) );
	$norm_after_invalid = HAL_Frontend_Dashboard_Settings_Repository::get_rich_editor_mode();
	b5_check( 'E-NORM-mode-normalize-x3-fail-closed',
		'embed' === $norm_missing
			&& 'official' === $norm_official_raw && 'official' === $norm_official_eff
			&& 'embed' === $norm_embed_raw
			&& 'off' === $norm_off_raw && 'off' === $norm_off_eff
			&& is_wp_error( $invalid_save ) && 'hal_settings_rich_editor_mode_invalid' === $invalid_save->get_error_code()
			&& 'off' === $norm_after_invalid,
		'the real repository normalize ×3: missing key → embed, the EDITOR_MODES trio passes raw+effective, out-of-list is a save()-level WP_Error with the stored value unchanged, and a disabled rich_editor folds effective to off',
		"normalize deviated: missing={$norm_missing} official={$norm_official_raw}/{$norm_official_eff} embed={$norm_embed_raw} off={$norm_off_raw}/{$norm_off_eff} invalid=" . ( is_wp_error( $invalid_save ) ? $invalid_save->get_error_code() : 'NOT-ERROR' ) . " after={$norm_after_invalid}" );

	/* E-MATRIX — دورة الوضع × الدوران × حوامل القالب (real render_part). */
	$set_mode( 'official' );
	$official_admin = $render_posts( $admin_caps );
	b5_check( 'E-OFFICIAL-ADMIN-button-payload-dialog-holders',
		false !== strpos( $official_admin, 'class="hossam-oeb-btn js-oeb-open"' )
			&& false !== strpos( $official_admin, 'data-oeb-post="9"' )
			&& false !== strpos( $official_admin, 'target="_blank"' )
			&& false !== strpos( $official_admin, '<dialog id="hossam-oeb-dialog"' )
			&& false !== strpos( $official_admin, 'id="hossam-oeb-dialog-link"' )
			&& false !== strpos( $official_admin, 'js-oeb-close' )
			&& false !== strpos( $official_admin, 'id="hossam-rich-editor-new"' )
			&& false !== strpos( $official_admin, 'id="hossam-rich-editor-edit"' )
			&& 2 === substr_count( $official_admin, 'data-role="canvas"' ),
		'official × manage_options renders the OEB button with its payload (data-oeb-post/target=_blank), the native dialog with link+close, and BOTH rich holders stay mounted',
		'official admin render deviated (button/dialog/holders missing)' );

	$official_editor = $render_posts( $editor_caps );
	b5_check( 'E-OFFICIAL-EDITOR-holders-stay-open-no-button',
		false === strpos( $official_editor, 'js-oeb-open' )
			&& false === strpos( $official_editor, 'hossam-oeb-dialog' )
			&& false !== strpos( $official_editor, 'id="hossam-rich-editor-new"' )
			&& false !== strpos( $official_editor, 'id="hossam-rich-editor-edit"' ),
		'official × non-admin: the button and dialog are absent from the DOM entirely while BOTH rich holders stay mounted (E2 — official never closes the holders on anyone)',
		'official editor render deviated (button leaked or holders closed)' );

	$set_mode( 'embed' );
	$embed_admin = $render_posts( $admin_caps );
	$embed_editor = $render_posts( $editor_caps );
	b5_check( 'E-EMBED-ADMIN-today-behavior',
		false !== strpos( $embed_admin, 'id="hossam-rich-editor-new"' )
			&& false !== strpos( $embed_admin, 'id="hossam-rich-editor-edit"' )
			&& false === strpos( $embed_admin, 'js-oeb-open' )
			&& false === strpos( $embed_admin, 'hossam-oeb-dialog' ),
		'embed × admin = today: both holders mount with no OEB button and no dialog',
		'embed admin render deviated' );
	b5_check( 'E-EMBED-EDITOR-today-behavior',
		false !== strpos( $embed_editor, 'id="hossam-rich-editor-new"' )
			&& false !== strpos( $embed_editor, 'id="hossam-rich-editor-edit"' )
			&& false === strpos( $embed_editor, 'js-oeb-open' )
			&& false === strpos( $embed_editor, 'hossam-oeb-dialog' ),
		'embed × editor = today: both holders mount with no OEB button and no dialog',
		'embed editor render deviated' );

	$set_mode( 'off' );
	$off_admin = $render_posts( $admin_caps );
	$off_editor = $render_posts( $editor_caps );
	b5_check( 'E-OFF-ADMIN-no-containers',
		false === strpos( $off_admin, 'hossam-rich-editor' )
			&& false === strpos( $off_admin, 'js-oeb-open' )
			&& false === strpos( $off_admin, 'hossam-oeb-dialog' )
			&& false !== strpos( $off_admin, 'id="hossam-new-content"' )
			&& false !== strpos( $off_admin, 'id="hossam-edit-content"' ),
		'off × admin: zero rich containers and no OEB surface while both truth textareas remain',
		'off admin render deviated (containers leaked)' );
	b5_check( 'E-OFF-EDITOR-no-containers',
		false === strpos( $off_editor, 'hossam-rich-editor' )
			&& false === strpos( $off_editor, 'js-oeb-open' )
			&& false !== strpos( $off_editor, 'id="hossam-new-content"' )
			&& false !== strpos( $off_editor, 'id="hossam-edit-content"' ),
		'off × editor: zero rich containers while both truth textareas remain',
		'off editor render deviated (containers leaked)' );

	// T10 (v1.1.0): الحقل الشرطي oeb_url — بند قبول R38.
	// create/update × official × مدير ⇒ oeb_url حاضرًا بقيمة رابط محرر
	// official (esc_url لـ post.php?post={ID}&action=edit)؛ × embed أو off
	// أو غير مدير ⇒ الحقل غائب عن ردّ النجاح كليًا — بنفس محاكاة الرد
	// القائمة (b5_begin_case + b5_call_ajax على المسجلات الحقيقية).
	$set_mode( 'official' );
	b5_begin_case( array( 'title' => 'x' ), 3, true, $admin_caps );
	$r           = b5_call_ajax( 'wp_ajax_hossam_create_article' );
	$oeb_data    = b5_exit_data( $r );
	$oeb_new_id  = (int) ( $oeb_data['post_id'] ?? 0 );
	b5_check( 'E-OEBURL-OFFICIAL-ADMIN-create-has-esc-url',
		b5_exit_is( $r, 200, true ) && $oeb_new_id >= 1
			&& isset( $oeb_data['oeb_url'] )
			&& false !== strpos( (string) $oeb_data['oeb_url'], 'post.php?post=' . $oeb_new_id . '&action=edit' ),
		'official × manage_options: create success carries the conditional oeb_url with the official editor link (esc_url of post.php?post={ID}&action=edit)',
		'got ' . json_encode( $r ) );

	b5_begin_case( array( 'post_id' => '9', 'title' => 'New' ), 3, true, $admin_caps );
	b5_post( 9, 'post', 'publish', 3, 'Old' );
	$r = b5_call_ajax( 'wp_ajax_hossam_update_article' );
	$oeb_data = b5_exit_data( $r );
	b5_check( 'E-OEBURL-OFFICIAL-ADMIN-update-has-esc-url',
		b5_exit_is( $r, 200, true )
			&& isset( $oeb_data['oeb_url'] )
			&& false !== strpos( (string) $oeb_data['oeb_url'], 'post.php?post=9&action=edit' ),
		'official × manage_options: update success carries the conditional oeb_url with the official editor link of the updated article',
		'got ' . json_encode( $r ) );

	b5_begin_case( array( 'title' => 'x' ), 3, true, $editor_caps );
	$r          = b5_call_ajax( 'wp_ajax_hossam_create_article' );
	$oeb_data   = b5_exit_data( $r );
	$oeb_new_id = (int) ( $oeb_data['post_id'] ?? 0 );
	b5_begin_case( array( 'post_id' => '9', 'title' => 'Newer' ), 3, true, $editor_caps );
	b5_post( 9, 'post', 'publish', 3, 'Old' );
	$r2        = b5_call_ajax( 'wp_ajax_hossam_update_article' );
	$oeb_data2 = b5_exit_data( $r2 );
	b5_check( 'E-OEBURL-OFFICIAL-NON-ADMIN-field-absent',
		b5_exit_is( $r, 200, true ) && $oeb_new_id >= 1 && ! array_key_exists( 'oeb_url', $oeb_data )
			&& b5_exit_is( $r2, 200, true ) && ! array_key_exists( 'oeb_url', $oeb_data2 ),
		'official × non-manager: create/update success contracts stay intact with the oeb_url field absent entirely',
		'got ' . json_encode( $r ) . ' | ' . json_encode( $r2 ) );

	$set_mode( 'embed' );
	b5_begin_case( array( 'title' => 'x' ), 3, true, $admin_caps );
	$r = b5_call_ajax( 'wp_ajax_hossam_create_article' );
	$embed_create = b5_exit_is( $r, 200, true ) && ! array_key_exists( 'oeb_url', b5_exit_data( $r ) );
	b5_begin_case( array( 'post_id' => '9', 'title' => 'Newer' ), 3, true, $admin_caps );
	b5_post( 9, 'post', 'publish', 3, 'Old' );
	$r = b5_call_ajax( 'wp_ajax_hossam_update_article' );
	$embed_update = b5_exit_is( $r, 200, true ) && ! array_key_exists( 'oeb_url', b5_exit_data( $r ) );

	$set_mode( 'off' );
	b5_begin_case( array( 'title' => 'x' ), 3, true, $admin_caps );
	$r = b5_call_ajax( 'wp_ajax_hossam_create_article' );
	$off_create = b5_exit_is( $r, 200, true ) && ! array_key_exists( 'oeb_url', b5_exit_data( $r ) );
	b5_begin_case( array( 'post_id' => '9', 'title' => 'Newer' ), 3, true, $admin_caps );
	b5_post( 9, 'post', 'publish', 3, 'Old' );
	$r = b5_call_ajax( 'wp_ajax_hossam_update_article' );
	$off_update = b5_exit_is( $r, 200, true ) && ! array_key_exists( 'oeb_url', b5_exit_data( $r ) );
	b5_check( 'E-OEBURL-EMBED-AND-OFF-field-absent',
		$embed_create && $embed_update && $off_create && $off_update,
		'embed × manager and off × manager: the conditional oeb_url field is absent from all four success responses (create/update × embed/off)',
		'embed_create=' . var_export( $embed_create, true ) . ' embed_update=' . var_export( $embed_update, true )
			. ' off_create=' . var_export( $off_create, true ) . ' off_update=' . var_export( $off_update, true ) );

	/* E-BRIDGE — جسر POST عبر handle_save_settings (ثلاثية مستقلة بنمط
	 * settings-and-secrets admin-access): forbidden بلا capability؛
	 * فاسد → save_failed؛ saved ثم إعادة العرض. */
	// 1) forbidden — بدون manage_options حتى مع nonce صالح (القدرة أولًا).
	$GLOBALS['B5_NONCE_VALID'] = true;
	b5_begin_case( array(), 3, true, $editor_caps );
	$_POST = array(
		'features_form'                => '1',
		'rich_editor_mode'             => 'official',
		'hal_frontend_dashboard_nonce' => $GLOBALS['B5_NONCE'],
	);
	$bridge_forbidden = b5_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_save_settings' ) );
	b5_check( 'E-BRIDGE-FORBIDDEN-without-capability',
		false !== strpos( $bridge_forbidden->target, 'hal_msg=forbidden' )
			&& 'off' === HAL_Frontend_Dashboard_Settings_Repository::get_rich_editor_mode(),
		'the POST bridge refuses without manage_options even with a valid nonce (hal_msg=forbidden) and the stored mode is untouched',
		'bridge capability gate deviated: ' . $bridge_forbidden->target );

	// 2) فاسد → save_failed مع بقاء القيمة المخزنة.
	b5_begin_case( array(), 3, true, $admin_caps );
	$_POST = array(
		'rich_editor_mode'             => 'mirror',
		'hal_frontend_dashboard_nonce' => $GLOBALS['B5_NONCE'],
	);
	$bridge_invalid = b5_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_save_settings' ) );
	b5_check( 'E-BRIDGE-INVALID-save-failed',
		false !== strpos( $bridge_invalid->target, 'hal_msg=save_failed' )
			&& false === strpos( $bridge_invalid->target, 'hal_msg=saved' )
			&& 'off' === HAL_Frontend_Dashboard_Settings_Repository::get_rich_editor_mode(),
		'an out-of-list mode dies at the bridge (hal_msg=save_failed) with the stored mode unchanged',
		'bridge validation deviated: ' . $bridge_invalid->target );

	// 3) saved ثم إعادة العرض: official يُحفظ عبر البوابة ثم إعادة عرض
	//    القالب الحقيقي تُظهر الزر وحمولته للمدير.
	b5_begin_case( array(), 3, true, $admin_caps );
	$_POST = array(
		'rich_editor_mode'             => 'official',
		'hal_frontend_dashboard_nonce' => $GLOBALS['B5_NONCE'],
	);
	$bridge_saved = b5_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_save_settings' ) );
	$reread_mode  = HAL_Frontend_Dashboard_Settings_Repository::get_rich_editor_mode();
	$redisplay    = $render_posts( $admin_caps );
	b5_check( 'E-BRIDGE-SAVED-then-redisplay',
		false !== strpos( $bridge_saved->target, 'hal_msg=saved' )
			&& 'official' === $reread_mode
			&& false !== strpos( $redisplay, 'class="hossam-oeb-btn js-oeb-open"' )
			&& false !== strpos( $redisplay, 'data-oeb-post="9"' )
			&& false !== strpos( $redisplay, '<dialog id="hossam-oeb-dialog"' ),
		'a valid official mode saves through the bridge (hal_msg=saved), re-reads from the real store, and the re-rendered template shows the button with its payload and the dialog',
		'bridge save/redisplay deviated: target=' . $bridge_saved->target . ' mode=' . $reread_mode );

	b5_result_line( 'editor-mode' );
}

function b5_mode_uploads( string $project ): void {
	$GLOBALS['B5_PROJECT'] = $project;
	b5_define_release_context();
	b5_define_valid_profile();
	b5_define_admin_includes();
	$GLOBALS['wpdb'] = b5_new_wpdb();
	b5_require_runtime();

	// get_my_files.
	b5_begin_case( array(), 3, false, array() );
	$r = b5_call_ajax( 'wp_ajax_hossam_get_my_files' );
	b5_check( 'U-401', b5_exit_is( $r, 401, false ), 'get_my_files logged-out → 401', 'got ' . json_encode( $r ) );

	b5_begin_case( array(), 3, true, array(), false );
	$r = b5_call_ajax( 'wp_ajax_hossam_get_my_files' );
	b5_check( 'U-NONCE', true === ( $r['registered'] ?? false ) && 403 === ( $r['status'] ?? 0 ) && -1 === ( $r['body'] ?? null ),
		'get_my_files nonce failure → 403 "-1" (wp_die semantics)', 'got ' . json_encode( $r ) );

	b5_begin_case( array() );
	$mine = array();
	for ( $i = 1; $i <= 5; $i++ ) {
		$mine[] = b5_post( 200 + $i, 'attachment', 'inherit', 3, 'File ' . $i, 'image/png' );
	}
	$GLOBALS['B5_QUERY_POSTS'] = $mine;
	$r = b5_call_ajax( 'wp_ajax_hossam_get_my_files' );
	$data  = b5_exit_data( $r );
	$args  = $GLOBALS['B5_LAST_QUERY_ARGS'] ?? array();
	$qargs_ok = 'attachment' === ( $args['post_type'] ?? '' ) && 3 === ( $args['author'] ?? 0 )
		&& 101 === ( $args['posts_per_page'] ?? 0 ) && 0 === ( $args['offset'] ?? -1 )
		&& 'inherit' === ( $args['post_status'] ?? '' ) && true === ( $args['no_found_rows'] ?? false );
	$shape_ok = b5_exit_is( $r, 200, true ) && 'active' === ( $data['view'] ?? '' ) && 1 === ( $data['page'] ?? 0 )
		&& false === ( $data['has_more'] ?? true ) && 5 === count( $data['files'] ?? array() )
		&& isset( $data['files'][0]['id'], $data['files'][0]['title'], $data['files'][0]['url'], $data['files'][0]['date'], $data['files'][0]['mime'] );
	b5_check( 'U-QARGS', $qargs_ok && $shape_ok,
		'get_my_files queries attachments with author=current user and page-1 defaults (per=100, +1 lookahead, no_found_rows) and answers {files,view,page,has_more} with the per-file contract',
		'args=' . json_encode( $args ) . ' got ' . json_encode( $r ) );

	b5_begin_case( array() );
	$many = array();
	for ( $i = 1; $i <= 101; $i++ ) {
		$many[] = b5_post( 300 + $i, 'attachment', 'inherit', 3, 'Many ' . $i, 'image/png' );
	}
	$GLOBALS['B5_QUERY_POSTS'] = $many;
	$r = b5_call_ajax( 'wp_ajax_hossam_get_my_files' );
	$data = b5_exit_data( $r );
	b5_check( 'U-HASMORE', b5_exit_is( $r, 200, true ) && true === ( $data['has_more'] ?? false ) && 100 === count( $data['files'] ?? array() ),
		'get_my_files with 101 matches → exactly 100 files and has_more=true (pagination contract)', 'got ' . json_encode( $r ) );

	b5_begin_case( array( 'view' => 'trash' ) );
	$GLOBALS['B5_QUERY_POSTS'] = array( b5_post( 201, 'attachment', 'trash', 3, 'Trashed file', 'image/png' ) );
	$r = b5_call_ajax( 'wp_ajax_hossam_get_my_files' );
	$data = b5_exit_data( $r );
	b5_check( 'U-TRASH', b5_exit_is( $r, 200, true ) && 'trash' === ( $GLOBALS['B5_LAST_QUERY_ARGS']['post_status'] ?? '' ) && 'trash' === ( $data['view'] ?? '' ),
		'view=trash queries post_status trash and normalizes the response view to trash', 'got ' . json_encode( $r ) );

	b5_begin_case( array( 'page' => '0' ) );
	$GLOBALS['B5_QUERY_POSTS'] = array( b5_post( 202, 'attachment', 'inherit', 3, 'F', 'image/png' ) );
	$r = b5_call_ajax( 'wp_ajax_hossam_get_my_files' );
	$data = b5_exit_data( $r );
	b5_check( 'U-PAGE0', b5_exit_is( $r, 200, true ) && 1 === ( $data['page'] ?? 0 ) && 0 === ( $GLOBALS['B5_LAST_QUERY_ARGS']['offset'] ?? -1 ),
		'page=0 clamps to page 1 with offset 0', 'got ' . json_encode( $r ) );

	b5_begin_case( array( 'page' => '3' ) );
	$GLOBALS['B5_QUERY_POSTS'] = array( b5_post( 203, 'attachment', 'inherit', 3, 'F', 'image/png' ) );
	$r = b5_call_ajax( 'wp_ajax_hossam_get_my_files' );
	$data = b5_exit_data( $r );
	b5_check( 'U-PAGE3', b5_exit_is( $r, 200, true ) && 3 === ( $data['page'] ?? 0 ) && 200 === ( $GLOBALS['B5_LAST_QUERY_ARGS']['offset'] ?? -1 ),
		'page=3 queries offset 200 (per 100 × page 3 − 100)', 'got ' . json_encode( $r ) );

	// restore_file.
	b5_begin_case( array( 'file_id' => '999' ) );
	$r = b5_call_ajax( 'wp_ajax_hossam_restore_file' );
	b5_check( 'U-R404', b5_exit_is( $r, 404, false ) && 'Not found' === ( b5_exit_data( $r )['message'] ?? '' ),
		'restore_file missing attachment → 404 Not found', 'got ' . json_encode( $r ) );

	// تفويض المالك #1 (2026-11-02): U-R403AUTHOR/U-R403CAP — التوقع حُدّث
	// 403 → 404 'Not found' (404 الموحد: الغائب والغير مملوك والبلا قدرة
	// برسالة الغائب نفسها — العقد الجديد المصرح، H3 §1.2/§1.5 — صف R9
	// «دمج v1.1.0: 404 الموحد»؛ القديم: 403 Unauthorized — المرجع: CHECKLIST R9).
	b5_begin_case( array( 'file_id' => '8' ), 3, true, array( 'edit_post_8' => true ) );
	b5_post( 8, 'attachment', 'trash', 7, 'Not mine', 'image/png' );
	$r = b5_call_ajax( 'wp_ajax_hossam_restore_file' );
	b5_check( 'U-R403AUTHOR', b5_exit_is( $r, 404, false ) && 'Not found' === ( b5_exit_data( $r )['message'] ?? '' ), 'restore_file on another user’s attachment → 404 unified oracle even with edit_post capability (existence+ownership swallowed by one gate)', 'got ' . json_encode( $r ) );

	b5_begin_case( array( 'file_id' => '8' ), 3, true, array() );
	b5_post( 8, 'attachment', 'trash', 3, 'Mine', 'image/png' );
	$r = b5_call_ajax( 'wp_ajax_hossam_restore_file' );
	b5_check( 'U-R403CAP', b5_exit_is( $r, 404, false ) && 'Not found' === ( b5_exit_data( $r )['message'] ?? '' ), 'restore_file own attachment without edit_post → 404 unified oracle', 'got ' . json_encode( $r ) );

	b5_begin_case( array( 'file_id' => '8' ), 3, true, array( 'edit_post_8' => true ) );
	b5_post( 8, 'attachment', 'trash', 3, 'Mine', 'image/png' );
	$GLOBALS['B5_FAIL_UNTRASH'] = true;
	$r = b5_call_ajax( 'wp_ajax_hossam_restore_file' );
	b5_check( 'U-R500', b5_exit_is( $r, 500, false ) && 'File could not be restored.' === ( b5_exit_data( $r )['message'] ?? '' ),
		'restore_file with a failing wp_untrash_post → 500 (false result, not an empty success)', 'got ' . json_encode( $r ) );

	b5_begin_case( array( 'file_id' => '8' ), 3, true, array( 'edit_post_8' => true ) );
	$restored_file = b5_post( 8, 'attachment', 'trash', 3, 'Mine', 'image/png' );
	$r = b5_call_ajax( 'wp_ajax_hossam_restore_file' );
	b5_check( 'U-ROK', b5_exit_is( $r, 200, true ) && 'draft' === $restored_file->post_status,
		'restore_file success path untrashes the own attachment', 'got ' . json_encode( $r ) );

	// delete_file.
	b5_begin_case( array( 'file_id' => '9' ) );
	$r = b5_call_ajax( 'wp_ajax_hossam_delete_file' );
	b5_check( 'U-D404', b5_exit_is( $r, 404, false ), 'delete_file missing attachment → 404', 'got ' . json_encode( $r ) );

	// تفويض المالك #1 (2026-11-02): U-D403AUTHOR/U-D403CAP — التوقع حُدّث
	// 403 → 404 'Not found' (404 الموحد — H3 §1.2/§1.5 — صف R9؛ القديم:
	// 403 Unauthorized — المرجع: CHECKLIST R9).
	b5_begin_case( array( 'file_id' => '9' ), 3, true, array( 'delete_post_9' => true ) );
	b5_post( 9, 'attachment', 'inherit', 7, 'Not mine', 'image/png' );
	$r = b5_call_ajax( 'wp_ajax_hossam_delete_file' );
	b5_check( 'U-D403AUTHOR', b5_exit_is( $r, 404, false ) && 'Not found' === ( b5_exit_data( $r )['message'] ?? '' ), 'delete_file another user’s attachment → 404 unified oracle even with delete_post capability', 'got ' . json_encode( $r ) );

	b5_begin_case( array( 'file_id' => '9' ), 3, true, array() );
	b5_post( 9, 'attachment', 'inherit', 3, 'Mine', 'image/png' );
	$r = b5_call_ajax( 'wp_ajax_hossam_delete_file' );
	b5_check( 'U-D403CAP', b5_exit_is( $r, 404, false ) && 'Not found' === ( b5_exit_data( $r )['message'] ?? '' ), 'delete_file own attachment without delete_post → 404 unified oracle', 'got ' . json_encode( $r ) );

	b5_begin_case( array( 'file_id' => '9' ), 3, true, array( 'delete_post_9' => true ) );
	b5_post( 9, 'attachment', 'inherit', 3, 'Mine', 'image/png' );
	$GLOBALS['B5_FAIL_DELETE_ATTACHMENT'] = true;
	$r = b5_call_ajax( 'wp_ajax_hossam_delete_file' );
	b5_check( 'U-D500', b5_exit_is( $r, 500, false ) && 'File could not be deleted.' === ( b5_exit_data( $r )['message'] ?? '' ),
		'delete_file with a failing wp_delete_attachment → 500', 'got ' . json_encode( $r ) );

	b5_begin_case( array( 'file_id' => '9' ), 3, true, array( 'delete_post_9' => true ) );
	b5_post( 9, 'attachment', 'inherit', 3, 'Mine', 'image/png' );
	$r = b5_call_ajax( 'wp_ajax_hossam_delete_file' );
	b5_check( 'U-DOK', b5_exit_is( $r, 200, true ) && ! isset( $GLOBALS['B5_POSTS'][9] ),
		'delete_file success path removes the own attachment', 'got ' . json_encode( $r ) );

	// upload_file.
	b5_begin_case( array(), 3, true, array() );
	$r = b5_call_ajax( 'wp_ajax_hossam_upload_file' );
	b5_check( 'U-P403', b5_exit_is( $r, 403, false ) && 'You do not have permission to upload files.' === ( b5_exit_data( $r )['message'] ?? '' ),
		'upload_file without upload_files → adapter hossam_forbidden → 403 (capability lives in the adapter, not duplicated in ajax)', 'got ' . json_encode( $r ) );

	b5_begin_case( array(), 3, true, array( 'upload_files' => true ) );
	$r = b5_call_ajax( 'wp_ajax_hossam_upload_file' );
	b5_check( 'U-P400NOFILE', b5_exit_is( $r, 400, false ) && 'No file was received.' === ( b5_exit_data( $r )['message'] ?? '' ),
		'upload_file without a file payload → adapter hossam_no_file → 400', 'got ' . json_encode( $r ) );

	b5_begin_case( array(), 3, true, array( 'upload_files' => true ) );
	$_FILES = array( 'file' => array( 'name' => 'archive.xyz', 'tmp_name' => '/tmp/b5-upload', 'size' => 4 ) );
	$GLOBALS['B5_SITE_MIMES'] = array( 'png' => 'image/png' );
	$r = b5_call_ajax( 'wp_ajax_hossam_upload_file' );
	b5_check( 'U-P400TYPE', b5_exit_is( $r, 400, false ) && 'This file type is not allowed.' === ( b5_exit_data( $r )['message'] ?? '' ),
		'upload_file with a disallowed extension → adapter hossam_invalid_file_type → 400 (site MIME policy bounds the allow-list)', 'got ' . json_encode( $r ) );

	b5_begin_case( array(), 3, true, array( 'upload_files' => true ) );
	$_FILES = array( 'file' => array( 'name' => 'broken.png', 'tmp_name' => '/tmp/b5-upload', 'size' => 4 ) );
	$GLOBALS['B5_SITE_MIMES'] = array( 'png' => 'image/png' );
	$GLOBALS['B5_UPLOAD']['result'] = new WP_Error( 'b5_media_failed', 'media fixture failure' );
	$r = b5_call_ajax( 'wp_ajax_hossam_upload_file' );
	b5_check( 'U-P400MEDIA', b5_exit_is( $r, 400, false ) && 'media fixture failure' === ( b5_exit_data( $r )['message'] ?? '' ),
		'upload_file whose media_handle_upload fails → 400 (only hossam_forbidden maps to 403)', 'got ' . json_encode( $r ) );

	b5_begin_case( array(), 3, true, array( 'upload_files' => true ) );
	$_FILES = array( 'file' => array( 'name' => 'photo.png', 'tmp_name' => '/tmp/b5-upload', 'size' => 10 ) );
	$GLOBALS['B5_SITE_MIMES'] = array( 'png' => 'image/png' );
	$GLOBALS['B5_UPLOAD']['result'] = 55;
	b5_post( 55, 'attachment', 'inherit', 3, 'photo.png', 'image/png' );
	$GLOBALS['B5_MEDIA_URLS'] = array( 55 => 'https://example.test/wp-content/uploads/photo.png' );
	$r = b5_call_ajax( 'wp_ajax_hossam_upload_file' );
	$data = b5_exit_data( $r );
	$mimes_clean = empty( $GLOBALS['B5_HOOKS']['upload_mimes'] );
	b5_check( 'U-POK', b5_exit_is( $r, 200, true ) && 55 === (int) ( $data['id'] ?? 0 ) && 'photo.png' === ( $data['title'] ?? '' )
		&& 'https://example.test/wp-content/uploads/photo.png' === ( $data['url'] ?? '' )
		&& 1 === ( $GLOBALS['B5_UPLOAD']['calls'] ?? 0 ) && $mimes_clean,
		'upload_file success → {id,title,url} with one media_handle_upload(\'file\',0) call and the temporary MIME filter removed afterwards',
		'got ' . json_encode( $r ) . '; mimes_clean=' . var_export( $mimes_clean, true ) );

	b5_result_line( 'uploads' );
}

/* ════════════════════════════════════════════════════════════════
 * DISPATCH
 * ════════════════════════════════════════════════════════════════ */

$mode    = (string) ( $argv[1] ?? 'main' );
$project = dirname( __DIR__, 2 );

switch ( $mode ) {
	case 'main':
		b5_mode_main( $project );
		return;
	case 'posts':
		b5_mode_posts( $project );
		return;
	case 'translate':
		b5_mode_translate( $project );
		return;
	case 'uploads':
		b5_mode_uploads( $project );
		return;
	case 'feature-gates':
		b5_mode_feature_gates( $project );
		return;
	case 'editor-mode':
		b5_mode_editor_mode( $project );
		return;
	default:
		b5_check( 'MODE', false, '', 'unknown mode ' . $mode );
		b5_result_line( $mode );
}
