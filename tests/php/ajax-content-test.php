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
 *   INVENTORY — the action inventory is exactly the legacy one: 26
 *              wp_ajax_* hooks (9 batch-5 posts/uploads + 17 batch-6
 *              appointments/finance/members/inbox/store/ai), zero
 *              wp_ajax_nopriv_* hooks, and ajax/seo.php is shipped but
 *              NOT loaded (its endpoint wp_ajax_hossam_save_seo is not
 *              registered) per the §16 "loading follows the adopted
 *              registry state; limited read-only" contract.
 *   ORDER     — core(5) → backend(5) → adapters(8) → ajax(8) in the
 *              mandated bootstrap order; runtime_ready still fires.
 *   B3-08     — the posts/uploads endpoints consume the owner feature
 *              decision (posts/files/wpml_translations) via
 *              is_feature_enabled: disabled → 403 feature_disabled with
 *              data preserved; re-enabled authorization stays independent.
 *              ajax/seo.php ships unloaded (G3/G4 hold — no activation).
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
		public function __construct( array $args = array() ) {
			$GLOBALS['B5_LAST_QUERY_ARGS'] = $args;
			$this->posts                   = is_array( $GLOBALS['B5_QUERY_POSTS'] ) ? $GLOBALS['B5_QUERY_POSTS'] : array();
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
		public function prepare( string $query, ...$args ): string {
			return $query;
		}
		public function get_var( string $query ) {
			return null;
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
		// Batch 7 (§18) — the shared Template Controller copy loads after
		// all modules (§6.1 pre-build infrastructure copy).
		'infrastructure/class-template-controller.php',
		// Batch 11 (§22) — the Update Bridge copy loads after the
		// Template Controller (§6.1 pre-build infrastructure copy).
		'infrastructure/class-update-bridge.php',
	);
	$actual_order = array_values( array_intersect( $relative, $expected_order ) );
	b5_check( 'L1', $actual_order === $expected_order,
		'the real bootstrap loads core (5) → backend (5) → adapters (8) → ajax (8: batches 5–6) → the batch-7 infrastructure controller → the batch-11 update bridge in the mandated §13/§16/§17/§18/§22 order',
		'load order mismatch: ' . json_encode( $relative ) );

	$module_included = array_values( array_filter(
		$runtime_included,
		static function ( string $path ): bool {
			return false === strpos( $path, '/runtime/bootstrap.php' );
		}
	) );
	b5_check( 'L2', count( array_unique( $module_included ) ) === 28 && did_action( 'hal_frontend_dashboard_runtime_ready' ) >= 1,
		'exactly 28 runtime module files load (bootstrap aside: 18 from batches 2–4 + the 8 ajax files of batches 5–6 + the batch-7 infrastructure controller + the batch-11 update bridge) and runtime_ready fires after the full load',
		'unexpected runtime file set: ' . json_encode( $relative ) );

	$ajax_hooks = array_values( array_filter(
		array_keys( $GLOBALS['B5_HOOKS'] ),
		static function ( string $hook ): bool {
			return 0 === strpos( $hook, 'wp_ajax_' );
		}
	) );
	sort( $ajax_hooks );
	$expected_hooks = array(
		// Batch-5 (9) + batch-6 (17), globally sorted as the check sorts.
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
		'wp_ajax_hossam_send_message',
		'wp_ajax_hossam_translate_article',
		'wp_ajax_hossam_trash_article',
		'wp_ajax_hossam_update_article',
		'wp_ajax_hossam_upload_file',
	);
	b5_check( 'G1', $ajax_hooks === $expected_hooks,
		'the AJAX action inventory is exactly the legacy §16/§17 inventory: 26 wp_ajax_* hooks (9 batch-5 + 17 batch-6), nothing added or removed',
		'inventory mismatch: ' . json_encode( $ajax_hooks ) );

	$nopriv_hooks = array_values( array_filter(
		array_keys( $GLOBALS['B5_HOOKS'] ),
		static function ( string $hook ): bool {
			return 0 === strpos( $hook, 'wp_ajax_nopriv_' );
		}
	) );
	b5_check( 'G2', array() === $nopriv_hooks,
		'zero wp_ajax_nopriv_* hooks are registered anywhere in the runtime',
		'unexpected nopriv hooks: ' . json_encode( $nopriv_hooks ) );

	b5_check( 'G3', ! isset( $GLOBALS['B5_HOOKS']['wp_ajax_hossam_save_seo'] ),
		'wp_ajax_hossam_save_seo is NOT registered (ajax/seo.php ships but stays unloaded — registry verified_fields=false, §16 limited read-only)',
		'seo endpoint must not be registered while the registry gate holds' );

	$seo_file = $project . '/runtime/ajax/seo.php';
	$seo_loaded = array_values( array_filter(
		$runtime_included,
		static function ( string $path ): bool {
			return false !== strpos( $path, '/runtime/ajax/seo.php' );
		}
	) );
	b5_check( 'G4', file_exists( $seo_file ) && array() === $seo_loaded,
		'runtime/ajax/seo.php exists in the release but is not required by the real bootstrap',
		'seo.php missing from release or unexpectedly loaded: ' . json_encode( $seo_loaded ) );

	$consumes_posts = false !== stripos( (string) file_get_contents( $project . '/runtime/ajax/posts.php' ), 'is_feature_enabled' );
	$consumes_uploads = false !== stripos( (string) file_get_contents( $project . '/runtime/ajax/uploads.php' ), 'is_feature_enabled' );
	b5_check( 'F1', $consumes_posts && $consumes_uploads,
		'B3-08: the batch-5 posts/uploads endpoints consume the owner feature decision via is_feature_enabled (seo.php ships unloaded — G3/G4 hold, no activation)',
		'a batch-5 ajax file misses its feature gate' );

	b5_run_mode( 'posts' );
	b5_run_mode( 'translate' );
	b5_run_mode( 'uploads' );
	b5_run_mode( 'feature-gates' );

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

	b5_begin_case( array( 'post_id' => '5' ), 3, true, array() );
	b5_post( 5, 'post', 'trash', 7 );
	$r = b5_call_ajax( 'wp_ajax_hossam_restore_article' );
	b5_check( 'P-R403', b5_exit_is( $r, 403, false ) && 'You do not have permission to edit this article.' === ( b5_exit_data( $r )['message'] ?? '' ),
		'restore without edit_post on the article → adapter hossam_forbidden → 403', 'got ' . json_encode( $r ) );

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

	b5_begin_case( array( 'post_id' => '6', 'title' => 'New' ), 3, true, array() );
	b5_post( 6, 'post', 'publish', 7, 'Old' );
	$r = b5_call_ajax( 'wp_ajax_hossam_update_article' );
	b5_check( 'P-U403', b5_exit_is( $r, 403, false ), 'update without edit_post on the article → 403', 'got ' . json_encode( $r ) );

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

	b5_begin_case( array( 'post_id' => '6' ), 3, true, array() );
	b5_post( 6, 'post', 'publish', 7, 'Old' );
	$r = b5_call_ajax( 'wp_ajax_hossam_trash_article' );
	b5_check( 'P-D403', b5_exit_is( $r, 403, false ), 'trash without edit_post → 403', 'got ' . json_encode( $r ) );

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
	b5_require_runtime();

	$decision = hossam_get_integration_decision( 'wpml' );
	b5_check( 'W-A', 'available' === $decision['status'] && true === ( $decision['capabilities']['languages'] ?? null ),
		'the real registry classifies the WPML fixture as available with the languages capability',
		'got ' . json_encode( $decision ) );

	b5_begin_case( array( 'post_id' => '5', 'lang' => 'en' ), 3, true, array() );
	b5_post( 5, 'post', 'publish', 7, 'Source' );
	$GLOBALS['B5_WPML_LANGS']      = array( 'en' => array( 'code' => 'en' ), 'ar' => array( 'code' => 'ar' ) );
	$r = b5_call_ajax( 'wp_ajax_hossam_translate_article' );
	b5_check( 'W-403', b5_exit_is( $r, 403, false ) && 'You do not have permission to edit this article.' === ( b5_exit_data( $r )['message'] ?? '' ),
		'translate source article without edit_post → 403 (registry gate passed, direct guard enforces)', 'got ' . json_encode( $r ) );

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
		'translate creating a linked draft → {post_id,edit_url,existing:false} with the draft authored by the current user and the real wpml_set_element_language_details payload (trid/source/lang)',
		'got ' . json_encode( $r ) . '; set=' . json_encode( $set_entry ) );

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
	b5_begin_case( array( 'post_id' => '5' ), 3, true, array() );
	b5_post( 5, 'post', 'trash', 7, 'Other' );
	$r = b5_call_ajax( 'wp_ajax_hossam_restore_article' );
	b5_check( 'G-REENABLE-CAP', b5_exit_is( $r, 403, false ) && 'You do not have permission to edit this article.' === ( b5_exit_data( $r )['message'] ?? '' ),
		're-enabled posts with no edit_post → 403 by capability (enabling never bypasses authorization)',
		'got ' . json_encode( $r ) );

	b5_begin_case( array( 'file_id' => '9' ), 3, true, array( 'delete_post_9' => true ) );
	b5_post( 9, 'attachment', 'inherit', 3, 'Mine', 'image/png' );
	$r = b5_call_ajax( 'wp_ajax_hossam_delete_file' );
	b5_check( 'G-REENABLE-OK', b5_exit_is( $r, 200, true ) && ! isset( $GLOBALS['B5_POSTS'][9] ),
		're-enabled files with delete_post → 200 and the own attachment removed',
		'got ' . json_encode( $r ) );

	b5_result_line( 'feature-gates' );
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

	b5_begin_case( array( 'file_id' => '8' ), 3, true, array( 'edit_post_8' => true ) );
	b5_post( 8, 'attachment', 'trash', 7, 'Not mine', 'image/png' );
	$r = b5_call_ajax( 'wp_ajax_hossam_restore_file' );
	b5_check( 'U-R403AUTHOR', b5_exit_is( $r, 403, false ), 'restore_file on another user’s attachment → 403 even with edit_post capability (ownership gate)', 'got ' . json_encode( $r ) );

	b5_begin_case( array( 'file_id' => '8' ), 3, true, array() );
	b5_post( 8, 'attachment', 'trash', 3, 'Mine', 'image/png' );
	$r = b5_call_ajax( 'wp_ajax_hossam_restore_file' );
	b5_check( 'U-R403CAP', b5_exit_is( $r, 403, false ), 'restore_file own attachment without edit_post → 403', 'got ' . json_encode( $r ) );

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

	b5_begin_case( array( 'file_id' => '9' ), 3, true, array( 'delete_post_9' => true ) );
	b5_post( 9, 'attachment', 'inherit', 7, 'Not mine', 'image/png' );
	$r = b5_call_ajax( 'wp_ajax_hossam_delete_file' );
	b5_check( 'U-D403AUTHOR', b5_exit_is( $r, 403, false ), 'delete_file another user’s attachment → 403 even with delete_post capability', 'got ' . json_encode( $r ) );

	b5_begin_case( array( 'file_id' => '9' ), 3, true, array() );
	b5_post( 9, 'attachment', 'inherit', 3, 'Mine', 'image/png' );
	$r = b5_call_ajax( 'wp_ajax_hossam_delete_file' );
	b5_check( 'U-D403CAP', b5_exit_is( $r, 403, false ), 'delete_file own attachment without delete_post → 403', 'got ' . json_encode( $r ) );

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
	default:
		b5_check( 'MODE', false, '', 'unknown mode ' . $mode );
		b5_result_line( $mode );
}
