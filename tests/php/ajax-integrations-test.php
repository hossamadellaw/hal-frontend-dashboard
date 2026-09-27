<?php
/**
 * HAL Frontend Dashboard — Batch 6 closure harness: integrations & services AJAX.
 *
 * Local, isolated harness: no live WordPress, no network, no database.
 * Loads the REAL runtime/bootstrap.php (core + batch-3 backend + the 8
 * batch-4 adapters + the 8 ajax files of batches 5–6) — only WordPress
 * itself (and the external-plugin APIs) are stubbed. Every status,
 * ownership, capability, quota and DB-failure assertion below invokes
 * the REAL registered wp_ajax_* / cron callback captured by the
 * add_action stub; no project logic is reimplemented in the harness.
 *
 * Coverage (architecture §17 closure gate + §10 per-file rules + the
 * adopted owner decision B3-08 option أ):
 *
 *   INVENTORY — the action inventory gains exactly the 17 batch-6
 *              wp_ajax_* hooks (2 appointments + 4 finance + 1 members +
 *              6 inbox + 1 store + 3 ai); zero wp_ajax_nopriv_* anywhere;
 *              ai's worker (hossam_ai_process_job_event) and sweep
 *              (hossam_ai_sweep_jobs_event) are internal cron hooks, NOT
 *              wp_ajax; ajax/seo.php stays shipped-but-unloaded.
 *   ORDER     — core(5) → backend(5) → adapters(8) → ajax(8) in the
 *              mandated bootstrap order; runtime_ready still fires.
 *   B3-08     — every batch-6 endpoint reads
 *              HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled()
 *              AFTER nonce + auth and BEFORE capability checks; disabled
 *              → 403 code=feature_disabled (fail-closed); the unset
 *              default (enabled) keeps the legacy behaviour byte-for-byte;
 *              the ai worker fails claimed jobs safely
 *              (hossam_ai_fail_job 'feature_disabled') without leaving
 *              pending jobs hanging (§7.6 async-feature contract).
 *   MATRIX    — independent capability matrix per file (appointments
 *              mgr/emp/none via view_amelia_calendar*; finance
 *              manager/own via manage_options/manage_woocommerce; members
 *              manage_options; store edit_products/edit_others_products;
 *              inbox ownership + manage_options for send; ai edit_post
 *              ownership + manage_options preference), JSON contracts of
 *              the legacy sources, pagination (per/has_more/offset),
 *              DB failures NEVER becoming empty success ($wpdb->last_error
 *              → documented 4xx/5xx errors), inbox atomic quota under
 *              GET_LOCK, ai atomic claim + daily/minute/concurrent
 *              limits, finance cache epoch (hossam_fin_cache_ver),
 *              saved tokens last4-only (never a full PAN), and zero
 *              secrets/strategy details in ai payloads.
 *
 * Modes: main (inventory/order/B3-08 static + spawns the subprocess
 * modes), appointments, finance, finance-wc, members, inbox, ai,
 * ai-noprofile, ai-disabled-noprofile, ai-unavailable, ai-worker, b308.
 *
 * Usage:  php tests/php/ajax-integrations-test.php            (main)
 *         php tests/php/ajax-integrations-test.php <mode>     (subprocess)
 *         exit 0 = all checks pass.
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

if ( PHP_SAPI !== 'cli' ) {
	echo "This harness must run under CLI PHP.\n";
	exit( 1 );
}

/* ════════════════════════════════════════════════════════════════
 * AJAX boundary stubs — WordPress semantics only. wp_send_json*
 * terminates the request (die) — modelled as an exception carrying
 * the HTTP status + JSON body; check_ajax_referer dies with 403 '-1'
 * on failure (WordPress wp_die semantics).
 * ════════════════════════════════════════════════════════════════ */

$GLOBALS['B6_NONCE']          = 'b6-fixture-nonce';
$GLOBALS['B6_HOOKS']          = array();
$GLOBALS['B6_ACTIONS']        = array();
$GLOBALS['B6_OPTIONS']        = array();
$GLOBALS['B6_RESULTS']        = array();
$GLOBALS['B6_CAPS']           = array();
$GLOBALS['B6_USER_CAN']       = array();
$GLOBALS['B6_HTTP']           = array( 'calls' => array() );
$GLOBALS['B6_LOGGED_IN']      = false;
$GLOBALS['B6_USER_ID']        = 3;
$GLOBALS['B6_PLUGIN_ACTIVE']  = false;
$GLOBALS['B6_SHORTCODES']     = array();
$GLOBALS['B6_SHORTCODE_OUT']  = '';
$GLOBALS['B6_SCHEDULED']      = array();
$GLOBALS['B6_SCHEDULE_FAIL']  = false;
$GLOBALS['B6_TRANSIENT_FAIL'] = false;
$GLOBALS['B6_DB_FAIL']        = false;
$GLOBALS['B6_DB_FAIL_OPS']    = array();
$GLOBALS['B6_DB_FAIL_CONTAINS'] = array();
$GLOBALS['B6_DB']             = array();
$GLOBALS['B6_DB']['race_complete_on_select'] = false;
$GLOBALS['B6_USERS']          = array();
$GLOBALS['B6_USERS_TOTAL']    = 0;
$GLOBALS['B6_WC']             = array();
$GLOBALS['B6_AI_TEXT']        = 'B6 generated text';
$GLOBALS['B6_ERROR_LOG']      = array();

if ( ! class_exists( 'B6_AjaxExit' ) ) {
	class B6_AjaxExit extends Exception {
		public int $status;
		public string $payload;
		public function __construct( int $status, string $payload ) {
			parent::__construct( 'b6-ajax-exit' );
			$this->status  = $status;
			$this->payload = $payload;
		}
	}
}
if ( ! function_exists( 'wp_send_json_success' ) ) {
	function wp_send_json_success( $data = null, int $status = 200 ): void {
		throw new B6_AjaxExit( $status, (string) wp_json_encode( array( 'success' => true, 'data' => $data ) ) );
	}
}
if ( ! function_exists( 'wp_send_json_error' ) ) {
	function wp_send_json_error( $data = null, int $status = 200 ): void {
		throw new B6_AjaxExit( $status, (string) wp_json_encode( array( 'success' => false, 'data' => $data ) ) );
	}
}
if ( ! function_exists( 'check_ajax_referer' ) ) {
	function check_ajax_referer( $action = -1, $query_arg = false, bool $die = true ) {
		$supplied = $_POST[ (string) $query_arg ] ?? $_GET[ (string) $query_arg ] ?? null;
		$expected = (string) $GLOBALS['B6_NONCE'];
		if ( is_string( $supplied ) && '' !== $supplied && hash_equals( $expected, $supplied ) ) {
			return 1;
		}
		if ( $die ) {
			throw new B6_AjaxExit( 403, '-1' );
		}
		return false;
	}
}

/* ────────────────────────────────────────────────────────────────
 * Harness bookkeeping
 * ──────────────────────────────────────────────────────────────── */

function b6_check( string $id, bool $condition, string $pass, string $fail = '' ): void {
	$GLOBALS['B6_RESULTS'][] = array( 'id' => $id, 'ok' => $condition, 'pass' => $pass, 'fail' => $fail );
}

function b6_result_line( string $mode ): void {
	$fail = 0;
	foreach ( $GLOBALS['B6_RESULTS'] as $result ) {
		if ( ! $result['ok'] ) {
			$fail++;
			echo "FAIL [{$result['id']}] {$result['fail']}\n";
		}
	}
	echo 'B6-VERDICT ' . $mode . ( 0 === $fail ? ' ALL-ASSERTIONS-HELD' : ' ASSERTIONS-FAILED-' . $fail ) . "\n";
	exit( 0 === $fail ? 0 : 1 );
}

/* ────────────────────────────────────────────────────────────────
 * WordPress boundary stubs (WordPress only — never project logic)
 * ──────────────────────────────────────────────────────────────── */

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook_name, $callback = '', int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['B6_HOOKS'][ $hook_name ][] = array( 'type' => 'action', 'callback' => $callback, 'priority' => $priority );
		return true;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook_name, $callback = '', int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['B6_HOOKS'][ $hook_name ][] = array( 'type' => 'filter', 'callback' => $callback, 'priority' => $priority );
		return true;
	}
}
if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( string $hook_name, $callback, int $priority = 10 ): bool {
		foreach ( $GLOBALS['B6_HOOKS'][ $hook_name ] ?? array() as $index => $entry ) {
			if ( $entry['callback'] === $callback ) {
				unset( $GLOBALS['B6_HOOKS'][ $hook_name ][ $index ] );
				return true;
			}
		}
		return false;
	}
}
if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( string $hook_name, $callback = false ) {
		$entries = $GLOBALS['B6_HOOKS'][ $hook_name ] ?? array();
		if ( false === $callback ) {
			return array() !== $entries;
		}
		foreach ( $entries as $entry ) {
			if ( $entry['callback'] === $callback ) {
				return true;
			}
		}
		return false;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook_name, ...$args ): void {
		$GLOBALS['B6_ACTIONS'][ $hook_name ] = ( $GLOBALS['B6_ACTIONS'][ $hook_name ] ?? 0 ) + 1;
		foreach ( $GLOBALS['B6_HOOKS'][ $hook_name ] ?? array() as $entry ) {
			call_user_func_array( $entry['callback'], $args );
		}
	}
}
if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook_name ): int {
		return $GLOBALS['B6_ACTIONS'][ $hook_name ] ?? 0;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook_name, $value, ...$args ) {
		foreach ( $GLOBALS['B6_HOOKS'][ $hook_name ] ?? array() as $entry ) {
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
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ): string {
		$url = trim( (string) $url );
		return preg_match( '#^https?://#i', $url ) ? $url : '';
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text, $remove_breaks = false ): string {
		return trim( strip_tags( (string) $text ) );
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
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability, ...$object_args ): bool {
		$key = $capability . ( isset( $object_args[0] ) ? '_' . (int) $object_args[0] : '' );
		if ( array_key_exists( $key, $GLOBALS['B6_CAPS'] ) ) {
			return (bool) $GLOBALS['B6_CAPS'][ $key ];
		}
		if ( array_key_exists( $capability, $GLOBALS['B6_CAPS'] ) ) {
			return (bool) $GLOBALS['B6_CAPS'][ $capability ];
		}
		return false;
	}
}
if ( ! function_exists( 'user_can' ) ) {
	function user_can( $user_id, string $capability, ...$object_args ): bool {
		$uid = is_object( $user_id ) ? (int) ( $user_id->ID ?? 0 ) : (int) $user_id;
		$key = $uid . '|' . $capability . ( isset( $object_args[0] ) ? '_' . (int) $object_args[0] : '' );
		if ( array_key_exists( $key, $GLOBALS['B6_USER_CAN'] ) ) {
			return (bool) $GLOBALS['B6_USER_CAN'][ $key ];
		}
		$key_plain = $uid . '|' . $capability;
		if ( array_key_exists( $key_plain, $GLOBALS['B6_USER_CAN'] ) ) {
			return (bool) $GLOBALS['B6_USER_CAN'][ $key_plain ];
		}
		return false;
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return (int) $GLOBALS['B6_USER_ID'];
	}
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		return (bool) $GLOBALS['B6_LOGGED_IN'];
	}
}
if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id(): int {
		return 1;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default_value = false ) {
		return array_key_exists( $option, $GLOBALS['B6_OPTIONS'] ) ? $GLOBALS['B6_OPTIONS'][ $option ] : $default_value;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, $value, $autoload = null ): bool {
		$GLOBALS['B6_OPTIONS'][ $option ] = $value;
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
		if ( ! empty( $GLOBALS['B6_TRANSIENT_FAIL'] ) ) {
			return false;
		}
		return update_option( '_transient_' . $key, $value );
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $key ): bool {
		unset( $GLOBALS['B6_OPTIONS'][ '_transient_' . $key ] );
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
if ( ! class_exists( 'WP_User_Query' ) ) {
	class WP_User_Query {
		public array $args = array();
		public function __construct( array $args = array() ) {
			$this->args = $args;
		}
		public function get_results(): array {
			return $GLOBALS['B6_USERS'];
		}
		public function get_total(): int {
			return (int) $GLOBALS['B6_USERS_TOTAL'];
		}
	}
}
if ( ! function_exists( 'get_users' ) ) {
	function get_users( array $args = array() ): array {
		$include = array_map( 'intval', (array) ( $args['include'] ?? array() ) );
		$out     = array();
		foreach ( $GLOBALS['B6_USERS'] as $user ) {
			if ( array() === $include || in_array( (int) $user->ID, $include, true ) ) {
				$out[] = $user;
			}
		}
		return $out;
	}
}
if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( int $user_id ) {
		foreach ( $GLOBALS['B6_USERS'] as $user ) {
			if ( (int) $user->ID === $user_id ) {
				return $user;
			}
		}
		return false;
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
		$GLOBALS['B6_HTTP']['calls'][] = array( 'verb' => 'GET', 'url' => $url, 'args' => $args );
		return $GLOBALS['B6_HTTP']['wp_error'] ?? $GLOBALS['B6_HTTP']['response'] ?? new WP_Error( 'b6_no_response_fixture' );
	}
}
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( string $url, array $args = array() ) {
		$GLOBALS['B6_HTTP']['calls'][] = array( 'verb' => 'POST', 'url' => $url, 'args' => $args );
		return $GLOBALS['B6_HTTP']['wp_error'] ?? $GLOBALS['B6_HTTP']['response'] ?? new WP_Error( 'b6_no_response_fixture' );
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
		return in_array( $plugin_file, (array) $GLOBALS['B6_PLUGIN_ACTIVE'], true );
	}
}
if ( ! function_exists( 'get_plugin_data' ) ) {
	function get_plugin_data( string $plugin_file, bool $markup = true, bool $translate = true ): array {
		return array( 'Version' => '' );
	}
}
if ( ! function_exists( 'shortcode_exists' ) ) {
	function shortcode_exists( string $tag ): bool {
		return in_array( $tag, $GLOBALS['B6_SHORTCODES'], true );
	}
}
if ( ! function_exists( 'do_shortcode' ) ) {
	function do_shortcode( string $content ): string {
		$GLOBALS['B6_SHORTCODE_CALLS'][] = $content;
		return $GLOBALS['B6_SHORTCODE_OUT'];
	}
}
if ( ! function_exists( 'get_page_by_path' ) ) {
	function get_page_by_path( string $page_path, $output = null, string $post_type = 'page' ) {
		return null;
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
		$GLOBALS['B6_GET_POSTS_ARGS'] = $args;
		return $GLOBALS['B6_GET_POSTS'] ?? array();
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) {
		$id = is_object( $id ) ? ( $id->ID ?? 0 ) : (int) $id;
		return $GLOBALS['B6_POSTS'][ $id ] ?? null;
	}
}
if ( ! function_exists( 'is_rtl' ) ) {
	function is_rtl(): bool {
		return false;
	}
}
if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( string $format, $timestamp = null ) {
		return gmdate( $format, $timestamp ?? time() );
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
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

/* Cron boundary stubs — batch 6: ai.php worker scheduling. */
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( string $hook, array $args = array() ) {
		$key = $hook . '|' . json_encode( array_values( $args ) );
		return $GLOBALS['B6_SCHEDULED'][ $key ] ?? false;
	}
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = array() ): bool {
		$key = $hook . '|' . json_encode( array_values( $args ) );
		$GLOBALS['B6_SCHEDULED'][ $key ] = $timestamp;
		return true;
	}
}
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( int $timestamp, string $hook, array $args = array() ): bool {
		if ( ! empty( $GLOBALS['B6_SCHEDULE_FAIL'] ) ) {
			return false;
		}
		$key = $hook . '|' . json_encode( array_values( $args ) );
		$GLOBALS['B6_SCHEDULED'][ $key ] = $timestamp;
		return true;
	}
}

/* External AI Client API stub (defined on demand, never at load). */
if ( ! class_exists( 'B6_AiPrompt' ) ) {
	class B6_AiPrompt {
		public static int $supported_calls = 0;
		public static int $generate_calls  = 0;
		public bool $supported             = true;
		public function is_supported_for_text_generation(): bool {
			self::$supported_calls++;
			return $this->supported;
		}
		public function generate_text() {
			self::$generate_calls++;
			return (string) $GLOBALS['B6_AI_TEXT'];
		}
	}
}

function b6_enable_ai_client(): void {
	if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
		eval( 'function wp_ai_client_prompt( string $prompt ): B6_AiPrompt { return new B6_AiPrompt(); }' );
	}
}

/* ════════════════════════════════════════════════════════════════
 * Release context + runtime loading
 * ════════════════════════════════════════════════════════════════ */

function b6_define_release_context(): void {
	if ( ! defined( 'ABSPATH' ) ) {
		$abspath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'b6-' . getmypid() . DIRECTORY_SEPARATOR;
		if ( ! is_dir( $abspath ) ) {
			@mkdir( $abspath, 0777, true );
		}
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
	if ( ! defined( 'DB_HOST' ) ) {
		define( 'DB_HOST', 'b6-db-host' );
	}
	if ( ! defined( 'DB_NAME' ) ) {
		define( 'DB_NAME', 'b6-db-name' );
	}
	if ( ! defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT' ) ) {
		define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT', $GLOBALS['B6_PROJECT'] . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR );
		define( 'HAL_FRONTEND_DASHBOARD_RELEASE_ID', '4.0.0+' . str_repeat( 'b6', 16 ) );
		define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION', '5.0.0' );
		define(
			'HAL_FRONTEND_DASHBOARD_RUNTIME_URL',
			'https://example.test/releases/' . HAL_FRONTEND_DASHBOARD_RELEASE_ID . '/'
		);
	}
}

function b6_define_valid_profile(): void {
	if ( ! defined( 'HOSSAM_AI_RUNTIME_PROFILE' ) ) {
		define( 'HOSSAM_AI_RUNTIME_PROFILE', array(
			'per_minute' => 30, 'per_day' => 500, 'concurrent' => 2, 'max_attempts' => 3,
			'stale_pending' => 3600, 'processing_deadline' => 240,
			'wp_ai_client_timeout' => 60, 'direct_key_timeout' => 90,
		) );
	}
}

function b6_define_admin_includes(): void {
	$dir = ABSPATH . 'wp-admin/includes';
	foreach ( array( 'file.php', 'image.php', 'media.php' ) as $stub ) {
		$path = $dir . DIRECTORY_SEPARATOR . $stub;
		if ( ! file_exists( $path ) ) {
			file_put_contents( $path, "<?php\n" );
		}
	}
}

function b6_require_runtime(): void {
	require $GLOBALS['B6_PROJECT'] . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'bootstrap.php';
}

function b6_run_mode( string $mode, array $args = array() ): void {
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
			b6_check( 'MODE-' . strtoupper( str_replace( '-', '_', $mode ) ), false, 'subprocess could not start', 'proc_open failed' );
			return;
		}
	}
	fclose( $pipes[0] );
	$stdout = (string) stream_get_contents( $pipes[1] );
	fclose( $pipes[1] );
	$stderr = (string) stream_get_contents( $pipes[2] );
	fclose( $pipes[2] );
	$code = proc_close( $proc );
	$ok = 0 === $code && false !== strpos( $stdout, 'B6-VERDICT ' . $mode . ' ALL-ASSERTIONS-HELD' );
	$detail = '';
	if ( ! $ok ) {
		$detail = 'exit ' . $code . '; stdout: ' . trim( $stdout ) . '; stderr: ' . trim( $stderr );
	}
	b6_check(
		'MODE-' . strtoupper( str_replace( '-', '_', $mode ) ),
		$ok,
		'subprocess mode ' . $mode . ' held all its assertions (exit 0)',
		'mode ' . $mode . ' failed — ' . $detail
	);
}

/* ════════════════════════════════════════════════════════════════
 * Batch-6 fixture helpers (harness only — never project logic)
 * ════════════════════════════════════════════════════════════════ */

if ( ! class_exists( 'B6_wpdb' ) ) {
	final class B6_wpdb {
		public string $prefix     = 'wp_';
		public string $last_error = '';
		public int $insert_id     = 0;
		public int $blogid        = 1;
		public array $queries     = array();

		public function flush(): void {
			$this->last_error = '';
		}
		public function prepare( string $query, ...$args ): string {
			foreach ( $args as $arg ) {
				if ( false !== strpos( $query, '%s' ) ) {
					$query = substr_replace( $query, (string) $arg, (int) strpos( $query, '%s' ), 2 );
				} elseif ( false !== strpos( $query, '%d' ) ) {
					$query = substr_replace( $query, (string) (int) $arg, (int) strpos( $query, '%d' ), 2 );
				}
			}
			return $query;
		}
	private function fail( string $op ): bool {
		if ( ! empty( $GLOBALS['B6_DB_FAIL'] ) || in_array( $op, (array) ( $GLOBALS['B6_DB_FAIL_OPS'] ?? array() ), true ) ) {
			$this->last_error = 'B6 fixture db failure';
			return true;
		}
		return false;
	}
	// Query-scoped failure (faithful per-query simulation): any read whose
	// SQL contains one of these substrings fails with last_error set,
	// while unrelated probes (e.g. SHOW TABLES) keep succeeding.
	private function fail_query( string $sql ): bool {
		foreach ( (array) ( $GLOBALS['B6_DB_FAIL_CONTAINS'] ?? array() ) as $needle ) {
			if ( '' !== (string) $needle && false !== strpos( $sql, (string) $needle ) ) {
				$this->last_error = 'B6 fixture db failure';
				return true;
			}
		}
		return false;
	}
	public function query( string $sql ) {
		$this->queries[] = $sql;
		if ( $this->fail('query') || $this->fail_query( $sql ) ) {
			return false;
		}
		// Faithful conditional-update emulation (DB boundary): a
		// SET-failed UPDATE guarded by AND status IN only affects a
		// modeled row that is still pending/processing — a concurrent
		// completion between SELECT and UPDATE matches zero rows.
		if ( false !== strpos( $sql, "SET status = 'failed'" ) && false !== strpos( $sql, 'AND status IN' )
			&& 1 === preg_match( '/WHERE id = (\d+)/', $sql, $m ) ) {
			$rid = (int) $m[1];
			$known = isset( $GLOBALS['B6_DB']['row']['id'] ) ? (int) $GLOBALS['B6_DB']['row']['id'] : null;
			if ( $rid === $known ) {
				$st = (string) ( $GLOBALS['B6_DB']['row']['status'] ?? '' );
				if ( 'pending' === $st || 'processing' === $st ) {
					$GLOBALS['B6_DB']['row']['status'] = 'failed';
					$GLOBALS['B6_DB']['row']['last_error'] = 'feature_disabled';
					return 1;
				}
				return 0;
			}
		}
			if ( false !== strpos( $sql, 'RELEASE_LOCK' ) ) {
				$GLOBALS['B6_DB']['lock_releases'][] = $sql;
			}
			return (int) ( $GLOBALS['B6_DB']['query_result'] ?? 1 );
		}
	public function get_var( string $query = null ) {
		$this->queries[] = (string) $query;
		if ( $this->fail('get_var') ) {
			return null;
		}
		$q = (string) $query;
		if ( $this->fail_query( $q ) ) {
			return null;
		}
			if ( false !== strpos( $q, 'GET_LOCK' ) ) {
				return (int) ( $GLOBALS['B6_DB']['lock_acquire'] ?? 1 );
			}
			if ( false !== strpos( $q, 'RELEASE_LOCK' ) ) {
				$GLOBALS['B6_DB']['lock_releases'][] = $q;
				return 1;
			}
			if ( false !== strpos( $q, 'SHOW TABLES' ) ) {
				if ( ! empty( $GLOBALS['B6_DB']['show_tables_missing'] ) ) {
					return '';
				}
				// Real SHOW TABLES LIKE returns the matched table name; the
				// LIKE target after prepare is the esc_like'd probed table,
				// so the fixture un-escapes it back.
				$pos = strpos( $q, 'LIKE ' );
				return false === $pos ? '' : str_replace( '\\', '', trim( substr( $q, $pos + 5 ) ) );
			}
			if ( false !== strpos( $q, 'COUNT(*)' ) && false !== strpos( $q, 'hossam_ai_jobs' ) ) {
				if ( false !== strpos( $q, 'processing' ) ) {
					return (int) ( $GLOBALS['B6_DB']['ai_processing'] ?? 0 );
				}
				return (int) ( $GLOBALS['B6_DB']['ai_day_count'] ?? 0 );
			}
			return $GLOBALS['B6_DB']['get_var_default'] ?? null;
		}
	public function get_results( string $query = null, $output = null ) {
		$this->queries[] = (string) $query;
		if ( $this->fail('get_results') || $this->fail_query( (string) $query ) ) {
			return array();
		}
			$rows = $GLOBALS['B6_DB']['results'] ?? array();
			return is_array( $rows ) ? $rows : array();
		}
	public function get_row( string $query = null, $output = null ) {
		$this->queries[] = (string) $query;
		if ( $this->fail('get_row') || $this->fail_query( (string) $query ) ) {
			return null;
		}
		// Interleaving hook (single purpose): when armed, a concurrent
		// winner commits completion right after our status SELECT — the
		// stale 'pending' read below exercises the TOCTOU window while
		// the stored row truthfully reads 'completed'.
		if ( ! empty( $GLOBALS['B6_DB']['race_complete_on_select'] )
			&& false !== strpos( (string) $query, 'SELECT status FROM' )
			&& is_array( $GLOBALS['B6_DB']['row'] ?? null ) ) {
			$GLOBALS['B6_DB']['row']['status'] = 'completed';
			return array( 'status' => 'pending' );
		}
		return $GLOBALS['B6_DB']['row'] ?? null;
	}
	public function get_col( string $query = null ) {
		$this->queries[] = (string) $query;
		if ( $this->fail('get_col') || $this->fail_query( (string) $query ) ) {
			return array();
		}
		if ( false !== strpos( (string) $query, "'processing'" ) ) {
			return $GLOBALS['B6_DB']['col_processing'] ?? $GLOBALS['B6_DB']['col'] ?? array();
		}
		if ( false !== strpos( (string) $query, "'pending'" ) ) {
			return $GLOBALS['B6_DB']['col_pending'] ?? $GLOBALS['B6_DB']['col'] ?? array();
		}
		return $GLOBALS['B6_DB']['col'] ?? array();
	}
		public function update( string $table, array $data, array $where, $format1 = null, $format2 = null ) {
			$this->queries[] = 'UPDATE ' . $table . ' ' . json_encode( array( $data, $where ) );
			if ( $this->fail('update') ) {
				return false;
			}
			return (int) ( $GLOBALS['B6_DB']['update_result'] ?? 1 );
		}
		public function insert( string $table, array $data, $format = null ) {
			$this->queries[] = 'INSERT INTO ' . $table . ' ' . json_encode( $data );
			if ( $this->fail('insert') ) {
				return false;
			}
			$this->insert_id = (int) ( $GLOBALS['B6_DB']['insert_id'] ?? 42 );
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

function b6_begin_case( array $post = array(), int $user = 3, bool $logged_in = true, array $caps = array(), bool $with_nonce = true ): void {
	$_POST                   = $post;
	if ( $with_nonce ) {
		$_POST['nonce']      = $GLOBALS['B6_NONCE'];
	}
	$GLOBALS['B6_USER_ID']   = $user;
	$GLOBALS['B6_LOGGED_IN'] = $logged_in;
	$GLOBALS['B6_CAPS']      = $caps;
	$GLOBALS['B6_USER_CAN']  = array();
	$GLOBALS['B6_OPTIONS']   = array();
	$GLOBALS['B6_TRANSIENT_FAIL'] = false;
	$GLOBALS['B6_SCHEDULED'] = array();
	$GLOBALS['B6_SCHEDULE_FAIL']  = false;
	$GLOBALS['B6_DB_FAIL']   = false;
	$GLOBALS['B6_DB_FAIL_OPS'] = array();
	$GLOBALS['B6_DB_FAIL_CONTAINS'] = array();
	$GLOBALS['B6_DB']        = array();
	$GLOBALS['B6_DB']['race_complete_on_select'] = false;
	$GLOBALS['B6_DB']        = array();
	$GLOBALS['B6_SHORTCODE_CALLS'] = array();
	$GLOBALS['B6_USERS']     = array();
	$GLOBALS['B6_USERS_TOTAL'] = 0;
	$GLOBALS['B6_PLUGIN_ACTIVE']  = false;
	$GLOBALS['B6_SHORTCODES'] = array();
	$GLOBALS['B6_SHORTCODE_OUT']  = '';
	$GLOBALS['B6_GET_POSTS'] = array();
	$GLOBALS['B6_POSTS']     = array();
	B6_AiPrompt::$supported_calls = 0;
	B6_AiPrompt::$generate_calls  = 0;
	$GLOBALS['wpdb']          = new B6_wpdb();
	if ( class_exists( 'HAL_Frontend_Dashboard_Settings_Repository' ) ) {
		HAL_Frontend_Dashboard_Settings_Repository::invalidate_cache();
	}
}

function b6_call_ajax( string $hook ): array {
	$entries = $GLOBALS['B6_HOOKS'][ $hook ] ?? array();
	if ( ! $entries ) {
		return array( 'registered' => false, 'status' => 0, 'body' => null );
	}
	$callback = end( $entries )['callback'];
	try {
		call_user_func( $callback );
		return array( 'registered' => true, 'status' => 200, 'body' => null );
	} catch ( B6_AjaxExit $exit ) {
		return array(
			'registered' => true,
			'status'     => $exit->status,
			'body'       => json_decode( $exit->payload, true ),
		);
	}
}

function b6_exit_is( array $result, int $status, bool $success ): bool {
	return true === ( $result['registered'] ?? false )
		&& $status === ( $result['status'] ?? 0 )
		&& is_array( $result['body'] ?? null )
		&& $success === ( $result['body']['success'] ?? null );
}

function b6_exit_data( array $result ): array {
	return is_array( $result['body']['data'] ?? null ) ? $result['body']['data'] : array();
}

function b6_set_features( array $features ): void {
	update_option( 'hal_frontend_dashboard_settings', array( 'schema_version' => '1.0.0' ) + array( 'features' => $features ) );
	if ( class_exists( 'HAL_Frontend_Dashboard_Settings_Repository' ) ) {
		HAL_Frontend_Dashboard_Settings_Repository::invalidate_cache();
	}
}

function b6_set_registry( array $integrations ): void {
	update_option( '_transient_' . hossam_integration_registry_key(), $integrations );
}

function b6_user( int $id, string $display_name, string $email = '', array $roles = array( 'subscriber' ) ): object {
	return (object) array(
		'ID'           => $id,
		'display_name' => $display_name,
		'user_email'   => $email,
		'roles'        => $roles,
	);
}

/* WooCommerce external-API fixtures (defined on demand per subprocess). */
function b6_enable_wc_fixtures(): void {
	if ( ! class_exists( 'B6_WCOrder' ) ) {
		class B6_WCOrder {
			public int $id = 0;
			public string $status = 'pending';
			public float $total = 100.0;
			public string $currency = 'USD';
			public string $customer = '';
			public bool $pending = true;
			public function get_id(): int {
				return $this->id;
			}
			public function get_date_created() {
				return new class() {
					public function date( string $format ): string {
						return '2026-09-15 10:30';
					}
				};
			}
			public function get_formatted_order_total(): string {
				return '<span class="amount">' . number_format( $this->total, 2 ) . '</span>';
			}
			public function get_total(): float {
				return $this->total;
			}
			public function get_currency(): string {
				return $this->currency;
			}
			public function get_status(): string {
				return $this->status;
			}
			public function has_status( array $statuses ): bool {
				return in_array( $this->status, $statuses, true );
			}
			public function get_payment_method_title(): string {
				return 'Direct bank transfer';
			}
			public function get_item_count(): int {
				return 1;
			}
			public function get_view_order_url(): string {
				return 'https://example.test/view-order/' . $this->id . '/';
			}
			public function needs_payment(): bool {
				return $this->pending;
			}
			public function get_billing_first_name(): string {
				return 'Hossam';
			}
			public function get_billing_last_name(): string {
				return 'Adel';
			}
		}
	}
	if ( ! class_exists( 'B6_WCGateway' ) ) {
		class B6_WCGateway {
			public string $id = '';
			public string $enabled = 'yes';
			private string $title = '';
			public function __construct( string $id, string $enabled, string $title ) {
				$this->id      = $id;
				$this->enabled = $enabled;
				$this->title   = $title;
			}
			public function get_title(): string {
				return $this->title;
			}
		}
	}
	if ( ! class_exists( 'B6_WCToken' ) ) {
		class B6_WCToken {
			private string $last4 = '';
			public function __construct( string $last4 ) {
				$this->last4 = $last4;
			}
			public function get_last4(): string {
				return $this->last4;
			}
		}
	}
	if ( ! class_exists( 'B6_WCProduct' ) ) {
		class B6_WCProduct {
			public int $id = 0;
			public function get_id(): int {
				return $this->id;
			}
			public function get_name(): string {
				return 'Product ' . $this->id;
			}
			public function get_price_html(): string {
				return '<span>100.00&nbsp;USD</span>';
			}
			public function get_stock_status(): string {
				return 'instock';
			}
			public function get_sku(): string {
				return 'SKU-' . $this->id;
			}
		}
	}
	if ( ! class_exists( 'WC_Payment_Gateways' ) ) {
		class WC_Payment_Gateways {
			public static function instance(): self {
				return new self();
			}
			public function payment_gateways(): array {
				return $GLOBALS['B6_WC']['gateways'] ?? array();
			}
		}
	}
	if ( ! class_exists( 'WC_Payment_Tokens' ) ) {
		class WC_Payment_Tokens {
			public static function get_customer_tokens( int $user_id ): array {
				return $GLOBALS['B6_WC']['tokens'] ?? array();
			}
		}
	}
	if ( ! function_exists( 'wc_get_orders' ) ) {
		eval( 'function wc_get_orders( $args ) {
			$GLOBALS["B6_WC"]["orders_calls"] = ( $GLOBALS["B6_WC"]["orders_calls"] ?? 0 ) + 1;
			$GLOBALS["B6_WC"]["last_orders_args"] = $args;
			return $GLOBALS["B6_WC"]["paginate"] ?? $GLOBALS["B6_WC"]["orders"] ?? array();
		}' );
	}
	if ( ! function_exists( 'wc_get_products' ) ) {
		eval( 'function wc_get_products( $args ) {
			$GLOBALS["B6_WC"]["products_calls"] = ( $GLOBALS["B6_WC"]["products_calls"] ?? 0 ) + 1;
			$GLOBALS["B6_WC"]["last_products_args"] = $args;
			return $GLOBALS["B6_WC"]["products"] ?? array();
		}' );
	}
	if ( ! function_exists( 'wc_get_order_statuses' ) ) {
		eval( 'function wc_get_order_statuses(): array {
			return array( "wc-pending" => "Pending payment", "wc-on-hold" => "On hold", "wc-completed" => "Completed" );
		}' );
	}
	if ( ! function_exists( 'wc_get_order_status_name' ) ) {
		eval( 'function wc_get_order_status_name( string $status ): string {
			$map = array( "pending" => "Pending payment", "on-hold" => "On hold", "completed" => "Completed" );
			return $map[ $status ] ?? $status;
		}' );
	}
	if ( ! function_exists( 'get_woocommerce_currency' ) ) {
		eval( 'function get_woocommerce_currency(): string { return "USD"; }' );
	}
	if ( ! function_exists( 'wc_price' ) ) {
		eval( 'function wc_price( $amount ): string {
			return "<span class=\"amount\">" . number_format( (float) $amount, 2 ) . "</span>";
		}' );
	}
}

/* ════════════════════════════════════════════════════════════════
 * MODES
 * ════════════════════════════════════════════════════════════════ */

function b6_mode_main( string $project ): void {
	$GLOBALS['B6_PROJECT'] = $project;
	b6_define_release_context();
	b6_define_valid_profile();
	b6_define_admin_includes();
	$GLOBALS['wpdb'] = new B6_wpdb();

	b6_require_runtime();

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
	b6_check( 'L1', $actual_order === $expected_order,
		'the real bootstrap loads core (5) → backend (5) → adapters (8) → ajax (8: batches 5–6) → the batch-7 infrastructure controller → the batch-11 update bridge in the mandated §13/§16/§17/§18/§22 order',
		'load order mismatch: ' . json_encode( $relative ) );

	$module_included = array_values( array_filter(
		$runtime_included,
		static function ( string $path ): bool {
			return false === strpos( $path, '/runtime/bootstrap.php' );
		}
	) );
	b6_check( 'L2', count( array_unique( $module_included ) ) === 28 && did_action( 'hal_frontend_dashboard_runtime_ready' ) >= 1,
		'exactly 28 runtime module files load (through batch 6 plus the batch-7 infrastructure controller plus the batch-11 update bridge) and runtime_ready fires after the full load',
		'unexpected runtime file set: ' . json_encode( $relative ) );

	$ajax_hooks = array_values( array_filter(
		array_keys( $GLOBALS['B6_HOOKS'] ),
		static function ( string $hook ): bool {
			return 0 === strpos( $hook, 'wp_ajax_' );
		}
	) );
	b6_check( 'G1', 26 === count( $ajax_hooks ),
		'the AJAX action inventory carries exactly 26 wp_ajax_* hooks (9 batch-5 + 17 batch-6)',
		'inventory count mismatch: ' . json_encode( $ajax_hooks ) );

	$batch6_hooks = array(
		'wp_ajax_hossam_get_upcoming_appointments',
		'wp_ajax_hossam_lazy_appointments',
		'wp_ajax_hossam_get_orders',
		'wp_ajax_hossam_finance_summary',
		'wp_ajax_hossam_get_payment_methods',
		'wp_ajax_hossam_get_saved_tokens',
		'wp_ajax_hossam_get_members',
		'wp_ajax_hossam_get_notifications',
		'wp_ajax_hossam_mark_read',
		'wp_ajax_hossam_mark_all_read',
		'wp_ajax_hossam_send_message',
		'wp_ajax_hossam_get_inbox',
		'wp_ajax_hossam_mark_message_read',
		'wp_ajax_hossam_get_products',
		'wp_ajax_hossam_ai_submit_job',
		'wp_ajax_hossam_ai_get_job_status',
		'wp_ajax_hossam_ai_set_preference',
	);
	$missing = array_diff( $batch6_hooks, $ajax_hooks );
	b6_check( 'G2', array() === $missing,
		'all 17 batch-6 endpoints are registered (2 appointments + 4 finance + 1 members + 6 inbox + 1 store + 3 ai)',
		'missing batch-6 hooks: ' . json_encode( array_values( $missing ) ) );

	$nopriv_hooks = array_values( array_filter(
		array_keys( $GLOBALS['B6_HOOKS'] ),
		static function ( string $hook ): bool {
			return 0 === strpos( $hook, 'wp_ajax_nopriv_' );
		}
	) );
	b6_check( 'G3', array() === $nopriv_hooks,
		'zero wp_ajax_nopriv_* hooks are registered anywhere in the runtime',
		'unexpected nopriv hooks: ' . json_encode( $nopriv_hooks ) );

	b6_check( 'G4', ! isset( $GLOBALS['B6_HOOKS']['wp_ajax_hossam_save_seo'] ) && ! isset( $GLOBALS['B6_HOOKS']['wp_ajax_hossam_ai_process_job'] ),
		'ajax/seo.php endpoint wp_ajax_hossam_save_seo stays unregistered AND the ai worker is NOT exposed as wp_ajax_hossam_ai_process_job (source contract: worker is cron-only)',
		'forbidden wp_ajax registration appeared' );

	$internal = array( 'hossam_ai_process_job_event', 'hossam_ai_sweep_jobs_event', 'init' );
	$internal_missing = array();
	foreach ( $internal as $hook ) {
		if ( ! isset( $GLOBALS['B6_HOOKS'][ $hook ] ) ) {
			$internal_missing[] = $hook;
		}
	}
	b6_check( 'G5', array() === $internal_missing,
		'ai.php registers its internal worker/sweep/init hooks (cron-internal, not wp_ajax)',
		'missing internal hooks: ' . json_encode( $internal_missing ) );

	/* B3-08 static: every batch-6 file carries the feature gate at its
	 * declared consumption points. Counted via the if-statement pattern so
	 * header-comment mentions of the accessor are not counted. */
	$gate_counts = array(
		'appointments' => 2,
		'finance'      => 4,
		'members'      => 1,
		'inbox'        => 6,
		'store'        => 1,
		'ai'           => 6, // 3 endpoints + post-claim worker gate + B6-02 worker fast path + B6-02 sweeper policy (pending + orphaned processing share one gate, §15.31).
	);
	foreach ( $gate_counts as $name => $expected ) {
		$source   = (string) file_get_contents( $project . '/runtime/ajax/' . $name . '.php' );
		$found    = substr_count( $source, 'if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled(' );
		$settings = false !== strpos( $source, 'Settings_Repository' );
		b6_check( 'F-' . $name, $found === $expected && $settings,
			"B3-08: {$name}.php binds its {$expected} consumption point(s) to the Settings Repository feature contract",
			"{$name}.php gate count {$found} ≠ {$expected} or Settings_Repository missing" );
	}

	b6_run_mode( 'appointments' );
	b6_run_mode( 'appointments-notables' );
	b6_run_mode( 'finance' );
	b6_run_mode( 'finance-wc' );
	b6_run_mode( 'members' );
	b6_run_mode( 'inbox' );
	b6_run_mode( 'ai' );
	b6_run_mode( 'ai-noprofile' );
	b6_run_mode( 'ai-disabled-noprofile' );
	b6_run_mode( 'ai-unavailable' );
	b6_run_mode( 'ai-worker' );
	b6_run_mode( 'store-wc' );
	b6_run_mode( 'b308' );

	b6_result_line( 'main' );
}

function b6_mode_appointments( string $project ): void {
	$GLOBALS['B6_PROJECT'] = $project;
	b6_define_release_context();
	b6_define_valid_profile();
	b6_define_admin_includes();
	b6_begin_case();
	b6_require_runtime();

	$UPCOMING = 'wp_ajax_hossam_get_upcoming_appointments';
	$LAZY     = 'wp_ajax_hossam_lazy_appointments';

	// Nonce failure dies 403 '-1' on the real callback.
	b6_begin_case( array(), 3, true, array(), false );
	$r = b6_call_ajax( $UPCOMING );
	b6_check( 'A-NONCE', 403 === $r['status'] && -1 === ( $r['body'] ?? null ),
		'missing nonce dies 403 via check_ajax_referer on the real upcoming callback', 'got ' . json_encode( $r ) );

	// Feature gate (B3-08) precedes the capability matrix: a manager with
	// the feature disabled sees feature_disabled, not forbidden.
	b6_begin_case( array(), 3, true, array( 'manage_options' => true, 'view_amelia_calendar_all' => true ) );
	b6_set_features( array( 'amelia' => false ) );
	$r = b6_call_ajax( $UPCOMING );
	b6_check( 'A-GATE', b6_exit_is( $r, 403, false ) && 'feature_disabled' === ( b6_exit_data( $r )['code'] ?? '' ),
		'amelia feature disabled → 403 feature_disabled before any capability check', 'got ' . json_encode( $r ) );
	b6_set_features( array() );

	// Logged-out → 401 (exact source payloads).
	b6_begin_case( array(), 3, false );
	$r = b6_call_ajax( $UPCOMING );
	$r2 = b6_call_ajax( $LAZY );
	b6_check( 'A-401', b6_exit_is( $r, 401, false ) && 'unauthorized' === ( b6_exit_data( $r )['code'] ?? '' )
		&& b6_exit_is( $r2, 401, false ) && 'Unauthorized' === ( b6_exit_data( $r2 )['message'] ?? '' ),
		'logged-out → 401 with the exact legacy payloads on both endpoints', 'got ' . json_encode( $r ) . ' / ' . json_encode( $r2 ) );

	// Capability matrix: none → 403 forbidden; employee passes the guard.
	b6_begin_case( array(), 3, true, array() );
	$r = b6_call_ajax( $UPCOMING );
	b6_check( 'A-403', b6_exit_is( $r, 403, false ) && 'forbidden' === ( b6_exit_data( $r )['code'] ?? '' ),
		'no amelia capability → 403 forbidden (legacy guard)', 'got ' . json_encode( $r ) );

	// Amelia inactive → 503 plugin_missing (integration state, not the owner gate).
	b6_begin_case( array(), 3, true, array( 'manage_options' => true ) );
	$r = b6_call_ajax( $UPCOMING );
	b6_check( 'A-503A', b6_exit_is( $r, 503, false ) && 'plugin_missing' === ( b6_exit_data( $r )['code'] ?? '' ),
		'amelia inactive → 503 plugin_missing', 'got ' . json_encode( $r ) );

	// DB failure on the upcoming read → 500 db_error (never empty success).
	// Fail scope: get_results only — the SHOW TABLES probes must succeed so
	// table_exists (function-static cache) records the tables as existing.
	b6_begin_case( array(), 3, true, array( 'manage_options' => true ) );
	$GLOBALS['B6_PLUGIN_ACTIVE'][] = 'ameliabooking/ameliabooking.php';
	$GLOBALS['B6_DB_FAIL_OPS'] = array( 'get_results' );
	$r = b6_call_ajax( $UPCOMING );
	b6_check( 'A-500', b6_exit_is( $r, 500, false ) && 'db_error' === ( b6_exit_data( $r )['code'] ?? '' ),
		'wpdb failure on the upcoming read → 500 db_error', 'got ' . json_encode( $r ) );

	// Success: real adapter shapes the fixture rows.
	b6_begin_case( array(), 3, true, array( 'manage_options' => true ) );
	$GLOBALS['B6_PLUGIN_ACTIVE'][] = 'ameliabooking/ameliabooking.php';
	$GLOBALS['B6_DB']['results'] = array(
		array( 'bookingStart' => '2026-09-20 10:00:00', 'status' => 'approved', 'service_name' => 'Consultation' ),
	);
	$r = b6_call_ajax( $UPCOMING );
	$data = b6_exit_data( $r );
	b6_check( 'A-OK', b6_exit_is( $r, 200, true )
		&& '2026-09-20 10:00:00' === ( $data[0]['bookingStart'] ?? null )
		&& 'approved' === ( $data[0]['status'] ?? null )
		&& 'Consultation' === ( $data[0]['service_name'] ?? null ),
		'upcoming success returns the real adapter rows with the bookingStart/status/service_name fields the overview panel consumes', 'got ' . json_encode( $r ) );

	// Independent capability matrix: employee with view_amelia_calendar
	// alone passes the guard with employee scope; manager with
	// view_amelia_calendar_all alone (no manage_options) passes as manager.
	b6_begin_case( array(), 3, true, array( 'view_amelia_calendar' => true ) );
	$GLOBALS['B6_PLUGIN_ACTIVE'][] = 'ameliabooking/ameliabooking.php';
	$GLOBALS['B6_DB']['results'] = array(
		array( 'bookingStart' => '2026-09-21 11:00:00', 'status' => 'approved', 'service_name' => 'Hearing' ),
	);
	$r = b6_call_ajax( $UPCOMING );
	$scoped = false;
	foreach ( $GLOBALS['wpdb']->queries as $q ) {
		if ( false !== strpos( (string) $q, 'e.externalId = 3' ) ) {
			$scoped = true;
		}
	}
	b6_check( 'A-EMP', b6_exit_is( $r, 200, true ) && $scoped,
		'employee with view_amelia_calendar alone reaches the read with the employee scope (externalId = current user) in the real query',
		'got ' . json_encode( $r ) );

	b6_begin_case( array(), 3, true, array( 'view_amelia_calendar_all' => true ) );
	$GLOBALS['B6_PLUGIN_ACTIVE'][] = 'ameliabooking/ameliabooking.php';
	$GLOBALS['B6_DB']['results'] = array();
	$r = b6_call_ajax( $UPCOMING );
	b6_check( 'A-MGR-ALL', b6_exit_is( $r, 200, true ) && array() === b6_exit_data( $r ),
		'manager with view_amelia_calendar_all alone (no manage_options) reads as manager — empty read stays an empty success',
		'got ' . json_encode( $r ) );

	// Lazy employee states: linked renders the employee panel; unlinked
	// shows the link-account card; read failure fails safe (B6-01).
	$lazy_employee = static function (): void {
		b6_set_registry( array( 'amelia' => array( 'status' => 'available', 'plugin_active' => true, 'version' => '1.0', 'capabilities' => array( 'bookings_shortcode' => true ), 'reason' => '', 'checked_at' => '2026-09-15' ) ) );
		$GLOBALS['B6_PLUGIN_ACTIVE'][] = 'ameliabooking/ameliabooking.php';
		$GLOBALS['B6_SHORTCODES'][] = 'ameliaemployeepanel';
		$GLOBALS['B6_SHORTCODE_OUT'] = '<div class="amelia-panel"></div>';
	};
	b6_begin_case( array(), 3, true, array( 'view_amelia_calendar' => true ) );
	$lazy_employee();
	$GLOBALS['B6_DB']['get_var_default'] = 11;
	$r = b6_call_ajax( $LAZY );
	b6_check( 'A-LINKED', b6_exit_is( $r, 200, true ) && '<div class="amelia-panel"></div>' === ( b6_exit_data( $r )['html'] ?? '' )
		&& ! empty( $GLOBALS['B6_SHORTCODE_CALLS'] ),
		'linked employee renders the employee panel via do_shortcode (200)', 'got ' . json_encode( $r ) );

	b6_begin_case( array(), 3, true, array( 'view_amelia_calendar' => true ) );
	$lazy_employee();
	$r = b6_call_ajax( $LAZY );
	b6_check( 'A-UNLINKED', b6_exit_is( $r, 200, true )
		&& false !== strpos( (string) ( b6_exit_data( $r )['html'] ?? '' ), 'link your account' )
		&& empty( $GLOBALS['B6_SHORTCODE_CALLS'] ),
		'unlinked employee gets the link-account card with no panel render (200)', 'got ' . json_encode( $r ) );

	b6_begin_case( array(), 3, true, array( 'view_amelia_calendar' => true ) );
	$lazy_employee();
	$GLOBALS['B6_DB_FAIL_CONTAINS'] = array( 'externalId' );
	$r = b6_call_ajax( $LAZY );
	b6_check( 'A-LINKDB500', b6_exit_is( $r, 500, false ) && 'Database query failed.' === ( b6_exit_data( $r )['message'] ?? '' )
		&& empty( $GLOBALS['B6_SHORTCODE_CALLS'] ),
		'linked-lookup DB failure fails safe with 500 (never the link-account success)',
		'got ' . json_encode( $r ) );

	// (The tables-missing 503 lives in its own subprocess mode
	// appointments-notables — hossam_amelia_table_exists caches per
	// process, so a process can only ever witness ONE table state.)

	// Lazy: no calendar capability → 403.
	b6_begin_case( array(), 3, true, array() );
	$r = b6_call_ajax( $LAZY );
	b6_check( 'A-L403', b6_exit_is( $r, 403, false ) && 'Unauthorized' === ( b6_exit_data( $r )['message'] ?? '' ),
		'lazy without calendar capability → 403', 'got ' . json_encode( $r ) );

	// Lazy: plugin inactive → 503 plugin_missing; active but shortcode
	// capability missing → 503 feature_unavailable (legacy integration
	// state — distinct from the owner feature gate).
	b6_begin_case( array(), 3, true, array( 'manage_options' => true ) );
	$r = b6_call_ajax( $LAZY );
	$first = b6_exit_is( $r, 503, false ) && 'plugin_missing' === ( b6_exit_data( $r )['code'] ?? '' );
	b6_begin_case( array(), 3, true, array( 'manage_options' => true ) );
	$GLOBALS['B6_PLUGIN_ACTIVE'][] = 'ameliabooking/ameliabooking.php';
	b6_set_registry( array( 'amelia' => array( 'status' => 'available', 'plugin_active' => true, 'version' => '1.0', 'capabilities' => array( 'bookings_shortcode' => true ), 'reason' => '', 'checked_at' => '2026-09-15' ) ) );
	$r = b6_call_ajax( $LAZY );
	b6_check( 'A-L503', $first && b6_exit_is( $r, 503, false ) && 'feature_unavailable' === ( b6_exit_data( $r )['code'] ?? '' ),
		'lazy → 503 plugin_missing when inactive and 503 feature_unavailable when the shortcode contract is missing', 'got ' . json_encode( $r ) );

	// Lazy success: admin renders the Amelia panel via do_shortcode.
	b6_begin_case( array(), 3, true, array( 'manage_options' => true ) );
	$GLOBALS['B6_PLUGIN_ACTIVE'][] = 'ameliabooking/ameliabooking.php';
	b6_set_registry( array( 'amelia' => array( 'status' => 'available', 'plugin_active' => true, 'version' => '1.0', 'capabilities' => array( 'bookings_shortcode' => true ), 'reason' => '', 'checked_at' => '2026-09-15' ) ) );
	$GLOBALS['B6_SHORTCODES'][] = 'ameliaemployeepanel';
	$GLOBALS['B6_SHORTCODE_OUT'] = '<div class="amelia-panel"></div>';
	$r = b6_call_ajax( $LAZY );
	b6_check( 'A-LOK', b6_exit_is( $r, 200, true ) && '<div class="amelia-panel"></div>' === ( b6_exit_data( $r )['html'] ?? '' )
		&& ! empty( $GLOBALS['B6_SHORTCODE_CALLS'] ),
		'lazy success renders the panel via do_shortcode and returns the html key (200)', 'got ' . json_encode( $r ) );

	// Separate nonce: lazy fails with hossam_nonce when only that is supplied.
	b6_begin_case( array(), 3, true, array( 'manage_options' => true ) );
	$_POST['nonce'] = 'wrong-nonce-value';
	$r = b6_call_ajax( $LAZY );
	b6_check( 'A-LNONCE', 403 === $r['status'] && -1 === ( $r['body'] ?? null ),
		'lazy endpoint keeps its separate hossam_lazy_appointments nonce (403 -1 on mismatch)', 'got ' . json_encode( $r ) );

	b6_result_line( 'appointments' );
}

function b6_mode_appointments_notables( string $project ): void {
	// Own subprocess: the table_exists static cache pins ONE table state
	// per process, so the missing-tables contract needs its own run.
	$GLOBALS['B6_PROJECT'] = $project;
	b6_define_release_context();
	b6_define_valid_profile();
	b6_define_admin_includes();
	b6_begin_case();
	b6_require_runtime();

	b6_begin_case( array(), 3, true, array( 'manage_options' => true ) );
	$GLOBALS['B6_PLUGIN_ACTIVE'][] = 'ameliabooking/ameliabooking.php';
	$GLOBALS['B6_DB']['show_tables_missing'] = true;
	$r = b6_call_ajax( 'wp_ajax_hossam_get_upcoming_appointments' );
	b6_check( 'A-503B', b6_exit_is( $r, 503, false ) && 'not_configured' === ( b6_exit_data( $r )['code'] ?? '' ),
		'amelia tables missing → 503 not_configured', 'got ' . json_encode( $r ) );

	b6_result_line( 'appointments-notables' );
}

function b6_mode_finance( string $project ): void {
	$GLOBALS['B6_PROJECT'] = $project;
	b6_define_release_context();
	b6_define_valid_profile();
	b6_define_admin_includes();
	b6_begin_case();
	b6_require_runtime();
	// WooCommerce deliberately absent in this subprocess.

	$hooks = array( 'wp_ajax_hossam_get_orders', 'wp_ajax_hossam_finance_summary', 'wp_ajax_hossam_get_payment_methods', 'wp_ajax_hossam_get_saved_tokens' );

	// The registry is populated with the full woocommerce capability set:
	// the legacy endpoints check capabilities.orders/payment_methods/tokens
	// in addition to the activity guard, so success paths need both layers.
	b6_set_registry( array( 'woocommerce' => array( 'status' => 'available', 'plugin_active' => true, 'version' => '9.0', 'capabilities' => array( 'orders' => true, 'payment_methods' => true, 'tokens' => true ), 'reason' => '', 'checked_at' => '2026-09-15' ) ) );

	// Nonce + 401 on all four.
	b6_begin_case( array(), 3, true, array(), false );
	$nonce_ok = true;
	foreach ( $hooks as $hook ) {
		$r = b6_call_ajax( $hook );
		$nonce_ok = $nonce_ok && 403 === $r['status'] && -1 === ( $r['body'] ?? null );
	}
	b6_check( 'FIN-NONCE', $nonce_ok, 'all four finance endpoints die 403 -1 without a nonce', 'nonce matrix failed' );

	b6_begin_case( array(), 3, false );
	$a401 = true;
	foreach ( $hooks as $hook ) {
		$r = b6_call_ajax( $hook );
		$a401 = $a401 && b6_exit_is( $r, 401, false ) && 'Unauthorized' === ( b6_exit_data( $r )['message'] ?? '' );
	}
	b6_check( 'FIN-401', $a401, 'logged-out → 401 Unauthorized on all four finance endpoints', '401 matrix failed' );

	// Feature gate precedes the integration guard: manager + finance off →
	// feature_disabled (not 503).
	b6_begin_case( array(), 3, true, array( 'manage_options' => true, 'manage_woocommerce' => true ) );
	b6_set_features( array( 'finance' => false ) );
	$r = b6_call_ajax( 'wp_ajax_hossam_get_orders' );
	b6_check( 'FIN-GATE', b6_exit_is( $r, 403, false ) && 'feature_disabled' === ( b6_exit_data( $r )['code'] ?? '' ),
		'finance feature disabled → 403 feature_disabled before capability/integration guards', 'got ' . json_encode( $r ) );

	// WooCommerce absent → 503 on all four (manager caps, feature on).
	b6_begin_case( array(), 3, true, array( 'manage_options' => true, 'manage_woocommerce' => true ) );
	$a503 = true;
	foreach ( $hooks as $hook ) {
		$r = b6_call_ajax( $hook );
		$a503 = $a503 && b6_exit_is( $r, 503, false ) && 'WooCommerce not active' === ( b6_exit_data( $r )['message'] ?? '' );
	}
	b6_check( 'FIN-503', $a503, 'WooCommerce absent → 503 WooCommerce not active on all four endpoints (legacy double guard)', '503 matrix failed' );

	b6_result_line( 'finance' );
}

function b6_mode_finance_wc( string $project ): void {
	$GLOBALS['B6_PROJECT'] = $project;
	b6_define_release_context();
	b6_define_valid_profile();
	b6_define_admin_includes();
	b6_enable_wc_fixtures();
	b6_begin_case();
	b6_require_runtime();

	$MGR = array( 'manage_options' => true, 'manage_woocommerce' => true );

	// begin_case wipes options; the woocommerce registry entry must be
	// re-set inside every case (the legacy endpoints check
	// capabilities.orders/payment_methods/tokens beyond the activity gate).
	$wc_registry = static function (): void {
		b6_set_registry( array( 'woocommerce' => array( 'status' => 'available', 'plugin_active' => true, 'version' => '9.0', 'capabilities' => array( 'orders' => true, 'payment_methods' => true, 'tokens' => true ), 'reason' => '', 'checked_at' => '2026-09-15' ) ) );
	};

	// Orders: manager sees the customer field; own scope does not; invalid
	// status ignored; pagination via +1 lookahead.
	b6_begin_case( array( 'page' => '1' ), 3, true, $MGR );
	$wc_registry();
	$GLOBALS['B6_WC']['orders'] = array( new B6_WCOrder(), new B6_WCOrder() );
	$r = b6_call_ajax( 'wp_ajax_hossam_get_orders' );
	$data = b6_exit_data( $r );
	b6_check( 'FINC-ORD-MGR', b6_exit_is( $r, 200, true ) && 2 === count( $data['orders'] ?? array() )
		&& 'Hossam Adel' === ( $data['orders'][0]['customer'] ?? '' ) && false === ( $data['has_more'] ?? true )
		&& array_key_exists( 'statuses', $data ) && 1 === ( $data['page'] ?? 0 ),
		'orders success (manager): legacy JSON contract with statuses/page/has_more and the customer field', 'got ' . json_encode( $r ) );

	b6_begin_case( array( 'page' => '1', 'status' => 'wc-bogus' ), 5, true, array( 'edit_posts' => true ) );
	$wc_registry();
	$GLOBALS['B6_WC']['orders'] = array( new B6_WCOrder() );
	$GLOBALS['B6_WC']['orders'][0]->pending = false;
	$GLOBALS['B6_WC']['orders'][0]->status = 'completed';
	$GLOBALS['B6_WC']['orders'][0]->total = 50.0;
	$r = b6_call_ajax( 'wp_ajax_hossam_get_orders' );
	$data = b6_exit_data( $r );
	$args = $GLOBALS['B6_WC']['last_orders_args'] ?? null;
	b6_check( 'FINC-ORD-OWN', b6_exit_is( $r, 200, true ) && ! array_key_exists( 'customer', $data['orders'][0] ?? array() )
		&& ! isset( $args['status'] ) && ( $args['customer'] ?? 0 ) === 5
		&& 'Completed' === ( $data['orders'][0]['status'] ?? '' ) && false === ( $data['orders'][0]['needs_payment'] ?? true ),
		'orders success (own scope): customer field absent, invalid status dropped, customer scoping preserved', 'got ' . json_encode( $r ) . ' args ' . json_encode( $args ) );

	b6_begin_case( array( 'page' => '1' ), 3, true, $MGR );
	$wc_registry();
	$GLOBALS['B6_WC']['orders'] = array();
	for ( $i = 0; $i < 21; $i++ ) {
		$GLOBALS['B6_WC']['orders'][] = new B6_WCOrder();
	}
	$r = b6_call_ajax( 'wp_ajax_hossam_get_orders' );
	$data = b6_exit_data( $r );
	b6_check( 'FINC-ORD-PAGE', b6_exit_is( $r, 200, true ) && 20 === count( $data['orders'] ) && true === ( $data['has_more'] ?? false ),
		'orders pagination: per=20 slice with +1 lookahead flips has_more', 'got ' . json_encode( $r ) );

	// Summary: computes once, caches under the epoch key, second call is a
	// cache hit, epoch bump invalidates.
	b6_begin_case( array( 'range' => '30days' ), 3, true, $MGR );
	$wc_registry();
	$page_obj = new class() {
		public array $orders = array();
		public int $total = 2;
		public int $max_num_pages = 1;
	};
	$o1 = new B6_WCOrder();
	$o2 = new B6_WCOrder();
	$o2->pending = false;
	$o2->status = 'completed';
	$o2->total = 300.0;
	$page_obj->orders = array( $o1, $o2 );
	$GLOBALS['B6_WC']['paginate'] = $page_obj;
	$r = b6_call_ajax( 'wp_ajax_hossam_finance_summary' );
	$data = b6_exit_data( $r );
	$calls_first = $GLOBALS['B6_WC']['orders_calls'] ?? 0;
	$cached_shape = 2 === ( $data['total_orders'] ?? 0 ) && '400.00' === ( $data['total_revenue'] ?? '' )
		&& 1 === ( $data['pending_count'] ?? 0 ) && 'USD' === ( $data['currency'] ?? '' )
		&& array_key_exists( 'revenue_raw', $data ) && array_key_exists( 'average_order', $data );
	$r2 = b6_call_ajax( 'wp_ajax_hossam_finance_summary' );
	$calls_second = $GLOBALS['B6_WC']['orders_calls'] ?? 0;
	update_option( 'hossam_fin_cache_ver', 7 );
	$r3 = b6_call_ajax( 'wp_ajax_hossam_finance_summary' );
	$calls_third = $GLOBALS['B6_WC']['orders_calls'] ?? 0;
	b6_check( 'FINC-SUM-CACHE', b6_exit_is( $r, 200, true ) && $cached_shape
		&& $calls_second === $calls_first && $calls_third > $calls_second,
		'summary: legacy KPI shape, transient cache hit without new wc_get_orders calls, epoch bump forces recompute', 'got ' . json_encode( $r ) . ' / calls ' . $calls_first . ',' . $calls_second . ',' . $calls_third );

	// Payment methods: enabled-only via the real gateway filter.
	b6_begin_case( array(), 3, true, $MGR );
	$wc_registry();
	$GLOBALS['B6_WC']['gateways'] = array(
		'stripe' => new B6_WCGateway( 'stripe', 'yes', 'Stripe' ),
		'cod'    => new B6_WCGateway( 'cod', 'no', 'Cash on delivery' ),
	);
	$r = b6_call_ajax( 'wp_ajax_hossam_get_payment_methods' );
	$data = b6_exit_data( $r );
	b6_check( 'FINC-GW', b6_exit_is( $r, 200, true ) && 1 === count( $data ) && 'stripe' === ( $data[0]['id'] ?? '' )
		&& 'Stripe' === ( $data[0]['title'] ?? '' ) && true === ( $data[0]['enabled'] ?? false ),
		'payment methods: registered+enabled gateways only, legacy {id,title,enabled} contract', 'got ' . json_encode( $r ) );

	// Saved tokens: last4 only — malformed last4 and non-token objects skipped.
	b6_begin_case( array(), 3, true, $MGR );
	$wc_registry();
	$GLOBALS['B6_WC']['tokens'] = array( new B6_WCToken( '4242' ), new B6_WCToken( '12x4' ), 'not-an-object' );
	$r = b6_call_ajax( 'wp_ajax_hossam_get_saved_tokens' );
	$data = b6_exit_data( $r );
	$only_last4 = true;
	foreach ( (array) $data as $item ) {
		$only_last4 = $only_last4 && is_array( $item ) && array_keys( $item ) === array( 'last4' );
	}
	b6_check( 'FINC-TOK', b6_exit_is( $r, 200, true ) && array( array( 'last4' => '4242' ) ) === $data && $only_last4
		&& false === strpos( (string) wp_json_encode( $r['body'] ), '424211111111' ),
		'saved tokens: last4-only contract, malformed entries skipped, no full PAN anywhere in the payload', 'got ' . json_encode( $r ) );

	// Read failure vs genuinely empty on every finance read path: a DB
	// failure ($wpdb->last_error) is a 500, an empty read stays 200 empty.
	b6_begin_case( array( 'page' => '1' ), 3, true, $MGR );
	$wc_registry();
	$GLOBALS['B6_WC']['orders'] = array();
	$GLOBALS['wpdb']->last_error = 'B6 fixture read failure';
	$r = b6_call_ajax( 'wp_ajax_hossam_get_orders' );
	b6_check( 'FINC-ORD-FAIL', b6_exit_is( $r, 500, false ) && 'Orders could not be loaded.' === ( b6_exit_data( $r )['message'] ?? '' ),
		'orders read failure → 500 (never an empty success)', 'got ' . json_encode( $r ) );

	b6_begin_case( array( 'page' => '1' ), 3, true, $MGR );
	$wc_registry();
	$GLOBALS['B6_WC']['orders'] = array();
	$r = b6_call_ajax( 'wp_ajax_hossam_get_orders' );
	$data = b6_exit_data( $r );
	b6_check( 'FINC-ORD-EMPTY', b6_exit_is( $r, 200, true ) && array() === ( $data['orders'] ?? null )
		&& false === ( $data['has_more'] ?? true ),
		'genuinely empty orders → 200 empty success', 'got ' . json_encode( $r ) );

	b6_begin_case( array( 'range' => '30days' ), 3, true, $MGR );
	$wc_registry();
	$fail_page = new class() {
		public array $orders = array();
		public int $total = 0;
		public int $max_num_pages = 0;
	};
	$GLOBALS['B6_WC']['paginate'] = $fail_page;
	$GLOBALS['wpdb']->last_error = 'B6 fixture read failure';
	$r = b6_call_ajax( 'wp_ajax_hossam_finance_summary' );
	b6_check( 'FINC-SUM-FAIL', b6_exit_is( $r, 500, false ) && 'Summary could not be loaded.' === ( b6_exit_data( $r )['message'] ?? '' ),
		'summary read failure → 500 (never zeroed KPIs as success)', 'got ' . json_encode( $r ) );

	b6_begin_case( array(), 3, true, $MGR );
	$wc_registry();
	$GLOBALS['B6_WC']['gateways'] = array( new B6_WCGateway( 'stripe', 'yes', 'Stripe' ) );
	$GLOBALS['wpdb']->last_error = 'B6 fixture read failure';
	$r = b6_call_ajax( 'wp_ajax_hossam_get_payment_methods' );
	b6_check( 'FINC-GW-FAIL', b6_exit_is( $r, 500, false ) && 'Payment methods could not be loaded.' === ( b6_exit_data( $r )['message'] ?? '' ),
		'payment-methods read failure → 500 (never an empty success)', 'got ' . json_encode( $r ) );

	b6_begin_case( array(), 3, true, $MGR );
	$wc_registry();
	$GLOBALS['B6_WC']['tokens'] = array( new B6_WCToken( '4242' ) );
	$GLOBALS['wpdb']->last_error = 'B6 fixture read failure';
	$r = b6_call_ajax( 'wp_ajax_hossam_get_saved_tokens' );
	b6_check( 'FINC-TOK-FAIL', b6_exit_is( $r, 500, false ) && 'Saved payment tokens could not be loaded.' === ( b6_exit_data( $r )['message'] ?? '' ),
		'saved-tokens read failure → 500 (never an empty success)', 'got ' . json_encode( $r ) );

	b6_result_line( 'finance-wc' );
}

function b6_mode_members( string $project ): void {
	$GLOBALS['B6_PROJECT'] = $project;
	b6_define_release_context();
	b6_define_valid_profile();
	b6_define_admin_includes();
	b6_begin_case();
	b6_require_runtime();

	$MEMBERS = 'wp_ajax_hossam_get_members';

	// Nonce → 403 -1.
	b6_begin_case( array(), 3, true, array( 'manage_options' => true ), false );
	$r = b6_call_ajax( $MEMBERS );
	b6_check( 'M-NONCE', 403 === $r['status'] && -1 === ( $r['body'] ?? null ),
		'missing nonce dies 403 -1 on the real members callback', 'got ' . json_encode( $r ) );

	// Non-admin → 403 Forbidden (legacy payload — no 401 guard in source).
	b6_begin_case( array(), 5, true, array() );
	$r = b6_call_ajax( $MEMBERS );
	b6_check( 'M-403', b6_exit_is( $r, 403, false ) && 'Forbidden' === ( b6_exit_data( $r )['message'] ?? '' ),
		'non-admin → 403 Forbidden (source has no separate 401 guard)', 'got ' . json_encode( $r ) );

	// B3-08 order proof: admin caps + feature disabled → feature_disabled
	// (the gate runs BEFORE the capability check).
	b6_begin_case( array(), 3, true, array( 'manage_options' => true ) );
	b6_set_features( array( 'members' => false ) );
	$r = b6_call_ajax( $MEMBERS );
	b6_check( 'M-GATE-ORDER', b6_exit_is( $r, 403, false ) && 'feature_disabled' === ( b6_exit_data( $r )['code'] ?? '' ),
		'members gate precedes the manage_options check (admin + disabled → feature_disabled)', 'got ' . json_encode( $r ) );

	// Invalid page shape → 400.
	b6_begin_case( array( 'page' => array( 'bogus' ) ), 3, true, array( 'manage_options' => true ) );
	$r = b6_call_ajax( $MEMBERS );
	b6_check( 'M-400', b6_exit_is( $r, 400, false ) && 'Invalid page' === ( b6_exit_data( $r )['message'] ?? '' ),
		'non-scalar page → 400 Invalid page', 'got ' . json_encode( $r ) );

	// Success: role map, pagination contract.
	b6_begin_case( array( 'page' => '1' ), 3, true, array( 'manage_options' => true ) );
	$GLOBALS['B6_USERS'] = array(
		b6_user( 1, 'Admin One', 'a@example.test', array( 'administrator' ) ),
		b6_user( 2, 'Emp Two', 'e@example.test', array( 'amelia_employee' ) ),
	);
	$GLOBALS['B6_USERS_TOTAL'] = 120;
	$r = b6_call_ajax( $MEMBERS );
	$data = b6_exit_data( $r );
	b6_check( 'M-OK', b6_exit_is( $r, 200, true ) && 2 === count( $data['members'] ?? array() )
		&& array( 'Administrator' ) === ( $data['members'][0]['roles'] ?? array() )
		&& array( 'Employee' ) === ( $data['members'][1]['roles'] ?? array() )
		&& true === ( $data['has_more'] ?? false ) && 120 === ( $data['total'] ?? 0 ) && 1 === ( $data['page'] ?? 0 ),
		'members success: role_display_map honoured, per=50 pagination with has_more/total (page 1)', 'got ' . json_encode( $r ) );

	// Read failure vs genuinely empty: $wpdb->last_error distinguishes a
	// failed WP_User_Query read (500) from an empty result (200 empty).
	b6_begin_case( array( 'page' => '1' ), 3, true, array( 'manage_options' => true ) );
	$GLOBALS['B6_USERS'] = array();
	$GLOBALS['B6_USERS_TOTAL'] = 0;
	$GLOBALS['wpdb']->last_error = 'B6 fixture read failure';
	$r = b6_call_ajax( $MEMBERS );
	b6_check( 'M-FAIL', b6_exit_is( $r, 500, false ) && 'Members could not be loaded.' === ( b6_exit_data( $r )['message'] ?? '' ),
		'members read failure → 500 (never an empty success)', 'got ' . json_encode( $r ) );

	b6_begin_case( array( 'page' => '1' ), 3, true, array( 'manage_options' => true ) );
	$GLOBALS['B6_USERS'] = array();
	$GLOBALS['B6_USERS_TOTAL'] = 0;
	$r = b6_call_ajax( $MEMBERS );
	$data = b6_exit_data( $r );
	b6_check( 'M-EMPTY', b6_exit_is( $r, 200, true ) && array() === ( $data['members'] ?? null )
		&& 0 === ( $data['total'] ?? -1 ) && false === ( $data['has_more'] ?? true ),
		'genuinely empty members → 200 empty success with total 0', 'got ' . json_encode( $r ) );

	b6_result_line( 'members' );
}

function b6_mode_inbox( string $project ): void {
	$GLOBALS['B6_PROJECT'] = $project;
	b6_define_release_context();
	b6_define_valid_profile();
	b6_define_admin_includes();
	b6_begin_case();
	b6_require_runtime();

	$hooks = array( 'wp_ajax_hossam_get_notifications', 'wp_ajax_hossam_mark_read', 'wp_ajax_hossam_mark_all_read', 'wp_ajax_hossam_send_message', 'wp_ajax_hossam_get_inbox', 'wp_ajax_hossam_mark_message_read' );

	// Real error_log capture: the native error_log() target is redirected
	// to a temp file for the whole mode (message_type 0 semantics).
	$GLOBALS['B6_ERROR_LOG_FILE'] = tempnam( sys_get_temp_dir(), 'b6-err-' );
	ini_set( 'error_log', $GLOBALS['B6_ERROR_LOG_FILE'] );

	// Nonce + 401 on all six.
	b6_begin_case( array(), 3, true, array(), false );
	$nonce_ok = true;
	foreach ( $hooks as $hook ) {
		$r = b6_call_ajax( $hook );
		$nonce_ok = $nonce_ok && 403 === $r['status'] && -1 === ( $r['body'] ?? null );
	}
	b6_check( 'IN-NONCE', $nonce_ok, 'all six inbox endpoints die 403 -1 without a nonce', 'nonce matrix failed' );

	b6_begin_case( array(), 3, false );
	$a401 = true;
	foreach ( $hooks as $hook ) {
		$r = b6_call_ajax( $hook );
		$a401 = $a401 && b6_exit_is( $r, 401, false ) && 'Unauthorized' === ( b6_exit_data( $r )['message'] ?? '' );
	}
	b6_check( 'IN-401', $a401, 'logged-out → 401 Unauthorized on all six inbox endpoints', '401 matrix failed' );

	// Feature gate before everything else (admin caps + inbox off).
	b6_begin_case( array(), 3, true, array( 'manage_options' => true ) );
	b6_set_features( array( 'inbox' => false ) );
	$r = b6_call_ajax( 'wp_ajax_hossam_get_notifications' );
	b6_check( 'IN-GATE', b6_exit_is( $r, 403, false ) && 'feature_disabled' === ( b6_exit_data( $r )['code'] ?? '' ),
		'inbox feature disabled → 403 feature_disabled (gate precedes capability and DB work)', 'got ' . json_encode( $r ) );

	// get_notifications: DB failure → 500 + real error_log capture (never empty success).
	b6_begin_case( array(), 3, true );
	$GLOBALS['B6_DB_FAIL'] = true;
	$r = b6_call_ajax( 'wp_ajax_hossam_get_notifications' );
	$log_content = (string) file_get_contents( $GLOBALS['B6_ERROR_LOG_FILE'] );
	$log_hit = false !== strpos( $log_content, 'Hossam Dashboard inbox notification read failed.' );
	b6_check( 'IN-NOTIF-500', b6_exit_is( $r, 500, false ) && 'Notifications are unavailable.' === ( b6_exit_data( $r )['message'] ?? '' ) && $log_hit,
		'notifications read failure → 500 with the safe message and a real error_log entry', 'got ' . json_encode( $r ) . ' log=' . var_export( $log_hit, true ) );

	// get_notifications success with the legacy template decoding.
	b6_begin_case( array(), 3, true );
	$GLOBALS['B6_DB']['results'] = array(
		array( 'id' => '1', 'type' => 'new_post', 'message' => wp_json_encode( array( 'key' => 'new_post', 'args' => array( 'Hello' ), 'url' => 'https://example.test/?p=9' ) ), 'is_read' => '0', 'created_at' => '2026-09-15 10:00:00' ),
		array( 'id' => '2', 'type' => 'order_status_changed', 'message' => 'plain text message', 'is_read' => '1', 'created_at' => '2026-09-15 09:00:00' ),
	);
	$r = b6_call_ajax( 'wp_ajax_hossam_get_notifications' );
	$data = b6_exit_data( $r );
	b6_check( 'IN-NOTIF-OK', b6_exit_is( $r, 200, true ) && 'New article saved: Hello' === ( $data[0]['message_text'] ?? '' )
		&& 'https://example.test/?p=9' === ( $data[0]['url'] ?? '' ) && 'plain text message' === ( $data[1]['message_text'] ?? '' ) && '' === ( $data[1]['url'] ?? '' ),
		'notifications success: legacy template decode (vsprintf with pattern guard) and safe plain-text fallback', 'got ' . json_encode( $r ) );

	// mark_read: 400 invalid → 500 failure → 404 nothing matched → success.
	b6_begin_case( array( 'notification_id' => array( 'x' ) ), 3, true );
	$r = b6_call_ajax( 'wp_ajax_hossam_mark_read' );
	$step400 = b6_exit_is( $r, 400, false ) && 'Invalid ID' === ( b6_exit_data( $r )['message'] ?? '' );
	b6_begin_case( array( 'notification_id' => '9' ), 3, true );
	$GLOBALS['B6_DB_FAIL'] = true;
	$r = b6_call_ajax( 'wp_ajax_hossam_mark_read' );
	$step500 = b6_exit_is( $r, 500, false ) && 'Update failed' === ( b6_exit_data( $r )['message'] ?? '' );
	b6_begin_case( array( 'notification_id' => '9' ), 3, true );
	$GLOBALS['B6_DB']['update_result'] = 0;
	$r = b6_call_ajax( 'wp_ajax_hossam_mark_read' );
	$step404 = b6_exit_is( $r, 404, false ) && 'Notification not found or already read' === ( b6_exit_data( $r )['message'] ?? '' );
	b6_begin_case( array( 'notification_id' => '9' ), 3, true );
	$r = b6_call_ajax( 'wp_ajax_hossam_mark_read' );
	$stepOK = b6_exit_is( $r, 200, true );
	$ownership = false;
	foreach ( $GLOBALS['wpdb']->queries as $q ) {
		if ( false !== strpos( (string) $q, '"user_id":3' ) && false !== strpos( (string) $q, 'hossam_notifications' ) ) {
			$ownership = true;
		}
	}
	b6_check( 'IN-MARK', $step400 && $step500 && $step404 && $stepOK && $ownership,
		'mark_read: 400/500/404/success contract with ownership (user_id) inside the WHERE', 'got ' . json_encode( array( $step400, $step500, $step404, $stepOK, $ownership ) ) );

	// mark_all_read: 500 on failure, success otherwise.
	b6_begin_case( array(), 3, true );
	$GLOBALS['B6_DB_FAIL'] = true;
	$r = b6_call_ajax( 'wp_ajax_hossam_mark_all_read' );
	$step500 = b6_exit_is( $r, 500, false ) && 'Update failed' === ( b6_exit_data( $r )['message'] ?? '' );
	b6_begin_case( array(), 3, true );
	$r = b6_call_ajax( 'wp_ajax_hossam_mark_all_read' );
	$stepOK = b6_exit_is( $r, 200, true );
	b6_check( 'IN-MARKALL', $step500 && $stepOK, 'mark_all_read: 500 on failure and plain success otherwise', 'got ' . json_encode( array( $step500, $stepOK ) ) );

	// send_message capability + input contract.
	b6_begin_case( array( 'receiver_id' => '2', 'message' => 'Hi' ), 5, true, array() );
	$r = b6_call_ajax( 'wp_ajax_hossam_send_message' );
	$step403 = b6_exit_is( $r, 403, false ) && 'Forbidden' === ( b6_exit_data( $r )['message'] ?? '' );
	b6_begin_case( array( 'receiver_id' => '0', 'message' => '' ), 3, true, array( 'manage_options' => true ) );
	$r = b6_call_ajax( 'wp_ajax_hossam_send_message' );
	$step400 = b6_exit_is( $r, 400, false ) && 'Invalid input' === ( b6_exit_data( $r )['message'] ?? '' );
	b6_begin_case( array( 'receiver_id' => '77', 'message' => 'Hi' ), 3, true, array( 'manage_options' => true ) );
	$GLOBALS['B6_USERS'] = array( b6_user( 2, 'Receiver' ) );
	$r = b6_call_ajax( 'wp_ajax_hossam_send_message' );
	$step404 = b6_exit_is( $r, 404, false ) && 'Receiver not found' === ( b6_exit_data( $r )['message'] ?? '' );
	b6_check( 'IN-SEND-GUARDS', $step403 && $step400 && $step404,
		'send_message: 403 non-admin / 400 invalid input / 404 unknown receiver', 'got ' . json_encode( array( $step403, $step400, $step404 ) ) );

	// send_message quota paths under the real GET_LOCK contract.
	b6_begin_case( array( 'receiver_id' => '2', 'message' => 'Hi' ), 3, true, array( 'manage_options' => true ) );
	$GLOBALS['B6_USERS'] = array( b6_user( 2, 'Receiver' ) );
	$GLOBALS['B6_DB']['lock_acquire'] = 0;
	$r = b6_call_ajax( 'wp_ajax_hossam_send_message' );
	$step503a = b6_exit_is( $r, 503, false ) && 'Message service is busy. Try again.' === ( b6_exit_data( $r )['message'] ?? '' );
	b6_begin_case( array( 'receiver_id' => '2', 'message' => 'Hi' ), 3, true, array( 'manage_options' => true ) );
	$GLOBALS['B6_USERS'] = array( b6_user( 2, 'Receiver' ) );
	$GLOBALS['B6_TRANSIENT_FAIL'] = true;
	$r = b6_call_ajax( 'wp_ajax_hossam_send_message' );
	$step503b = b6_exit_is( $r, 503, false ) && 'Message service is unavailable. Try again.' === ( b6_exit_data( $r )['message'] ?? '' );
	b6_begin_case( array( 'receiver_id' => '2', 'message' => 'Hi' ), 3, true, array( 'manage_options' => true ) );
	$GLOBALS['B6_USERS'] = array( b6_user( 2, 'Receiver' ) );
	$GLOBALS['B6_OPTIONS']['_transient_hossam_msg_rate_3'] = 10;
	$r = b6_call_ajax( 'wp_ajax_hossam_send_message' );
	$step429 = b6_exit_is( $r, 429, false ) && 'Rate limit exceeded. Try later.' === ( b6_exit_data( $r )['message'] ?? '' );
	$released = ! empty( $GLOBALS['B6_DB']['lock_releases'] );
	b6_check( 'IN-QUOTA', $step503a && $step503b && $step429 && $released,
		'quota: lock-busy 503, transient-write 503, rate-limit 429 — and the named lock is released in finally on every path', 'got ' . json_encode( array( $step503a, $step503b, $step429, $released ) ) );

	// send_message insert failure → 500; success → {id}.
	// Fail scope: insert only — the quota lock and transient must succeed
	// so the request reaches the insert.
	b6_begin_case( array( 'receiver_id' => '2', 'message' => 'Hi' ), 3, true, array( 'manage_options' => true ) );
	$GLOBALS['B6_USERS'] = array( b6_user( 2, 'Receiver' ) );
	$GLOBALS['B6_DB_FAIL_OPS'] = array( 'insert' );
	$r = b6_call_ajax( 'wp_ajax_hossam_send_message' );
	$step500 = b6_exit_is( $r, 500, false ) && 'Insert failed' === ( b6_exit_data( $r )['message'] ?? '' );
	b6_begin_case( array( 'receiver_id' => '2', 'message' => 'Hi' ), 3, true, array( 'manage_options' => true ) );
	$GLOBALS['B6_USERS'] = array( b6_user( 2, 'Receiver' ) );
	$GLOBALS['B6_DB']['insert_id'] = 77;
	$r = b6_call_ajax( 'wp_ajax_hossam_send_message' );
	$stepOK = b6_exit_is( $r, 200, true ) && array( 'id' => 77 ) === b6_exit_data( $r );
	b6_check( 'IN-SEND-OK', $step500 && $stepOK, 'send_message: insert failure → 500; success → legacy {id} payload', 'got ' . json_encode( array( $step500, $stepOK ) ) );

	// get_inbox: DB failure → 500 + log; success with sender_name enrichment.
	b6_begin_case( array(), 3, true );
	$GLOBALS['B6_DB_FAIL'] = true;
	$r = b6_call_ajax( 'wp_ajax_hossam_get_inbox' );
	$step500 = b6_exit_is( $r, 500, false ) && 'Inbox is unavailable.' === ( b6_exit_data( $r )['message'] ?? '' );
	b6_begin_case( array(), 3, true );
	$GLOBALS['B6_USERS'] = array( b6_user( 7, 'Sender Seven' ) );
	$GLOBALS['B6_DB']['results'] = array( array( 'id' => '5', 'sender_id' => '7', 'message' => 'hello', 'read_at' => null, 'created_at' => '2026-09-15 11:00:00' ) );
	$r = b6_call_ajax( 'wp_ajax_hossam_get_inbox' );
	$data = b6_exit_data( $r );
	$stepOK = b6_exit_is( $r, 200, true ) && 'Sender Seven' === ( $data[0]['sender_name'] ?? '' );
	b6_check( 'IN-INBOX', $step500 && $stepOK, 'get_inbox: 500 on failure; success enriches sender_name via the real get_users path', 'got ' . json_encode( array( $step500, $stepOK ) ) );

	// mark_message_read: 500 → 404 → success, receiver ownership in WHERE.
	b6_begin_case( array( 'message_id' => '5' ), 3, true );
	$GLOBALS['B6_DB_FAIL'] = true;
	$r = b6_call_ajax( 'wp_ajax_hossam_mark_message_read' );
	$step500 = b6_exit_is( $r, 500, false ) && 'Update failed' === ( b6_exit_data( $r )['message'] ?? '' );
	b6_begin_case( array( 'message_id' => '5' ), 3, true );
	$GLOBALS['B6_DB']['query_result'] = 0;
	$r = b6_call_ajax( 'wp_ajax_hossam_mark_message_read' );
	$step404 = b6_exit_is( $r, 404, false ) && 'Message not found or already read' === ( b6_exit_data( $r )['message'] ?? '' );
	b6_begin_case( array( 'message_id' => '5' ), 3, true );
	$r = b6_call_ajax( 'wp_ajax_hossam_mark_message_read' );
	$stepOK = b6_exit_is( $r, 200, true );
	$ownership = false;
	foreach ( $GLOBALS['wpdb']->queries as $q ) {
		if ( false !== strpos( (string) $q, 'receiver_id = 3' ) && false !== strpos( (string) $q, 'read_at IS NULL' ) ) {
			$ownership = true;
		}
	}
	b6_check( 'IN-MSGREAD', $step500 && $step404 && $stepOK && $ownership,
		'mark_message_read: 500/404/success with receiver ownership + read_at IS NULL inside the WHERE', 'got ' . json_encode( array( $step500, $step404, $stepOK, $ownership ) ) );

	b6_result_line( 'inbox' );
}

function b6_mode_ai( string $project ): void {
	$GLOBALS['B6_PROJECT'] = $project;
	b6_define_release_context();
	b6_define_valid_profile();
	b6_define_admin_includes();
	b6_enable_ai_client();
	b6_begin_case();
	b6_require_runtime();

	$SUBMIT = 'wp_ajax_hossam_ai_submit_job';
	$STATUS = 'wp_ajax_hossam_ai_get_job_status';
	$PREF   = 'wp_ajax_hossam_ai_set_preference';

	// Nonce + 401 on the two guarded endpoints.
	b6_begin_case( array(), 3, true, array(), false );
	$r = b6_call_ajax( $SUBMIT );
	$r2 = b6_call_ajax( $STATUS );
	b6_check( 'AI-NONCE', 403 === $r['status'] && -1 === ( $r['body'] ?? null ) && 403 === $r2['status'] && -1 === ( $r2['body'] ?? null ),
		'missing nonce dies 403 -1 on the real submit and status callbacks', 'got ' . json_encode( array( $r, $r2 ) ) );

	b6_begin_case( array(), 3, false );
	$r = b6_call_ajax( $SUBMIT );
	$r2 = b6_call_ajax( $STATUS );
	b6_check( 'AI-401', b6_exit_is( $r, 401, false ) && b6_exit_is( $r2, 401, false ),
		'logged-out → 401 on submit and job status', 'got ' . json_encode( array( $r, $r2 ) ) );

	// Feature gates: ai off → feature_disabled on all three endpoints
	// (set_preference: gate before manage_options — admin caps + off).
	b6_begin_case( array(), 3, true, array( 'manage_options' => true, 'edit_posts' => true ) );
	b6_set_features( array( 'ai' => false ) );
	$r = b6_call_ajax( $SUBMIT );
	$r2 = b6_call_ajax( $STATUS );
	$r3 = b6_call_ajax( $PREF );
	$gates = b6_exit_is( $r, 403, false ) && 'feature_disabled' === ( b6_exit_data( $r )['code'] ?? '' )
		&& b6_exit_is( $r2, 403, false ) && 'feature_disabled' === ( b6_exit_data( $r2 )['code'] ?? '' )
		&& b6_exit_is( $r3, 403, false ) && 'feature_disabled' === ( b6_exit_data( $r3 )['code'] ?? '' );
	b6_check( 'AI-GATE', $gates,
		'ai feature disabled → 403 feature_disabled on all three endpoints (set_preference gate precedes manage_options)', 'got ' . json_encode( array( $r, $r2, $r3 ) ) );

	// set_preference: 403 non-admin; invalid → 400; valid roundtrip.
	b6_begin_case( array( 'preference' => 'direct_key' ), 5, true, array() );
	$r = b6_call_ajax( $PREF );
	$step403 = b6_exit_is( $r, 403, false ) && 'Forbidden' === ( b6_exit_data( $r )['message'] ?? '' );
	b6_begin_case( array( 'preference' => 'bogus' ), 3, true, array( 'manage_options' => true ) );
	$r = b6_call_ajax( $PREF );
	$step400 = b6_exit_is( $r, 400, false ) && 'Invalid preference' === ( b6_exit_data( $r )['message'] ?? '' );
	b6_begin_case( array( 'preference' => 'direct_key' ), 3, true, array( 'manage_options' => true ) );
	$r = b6_call_ajax( $PREF );
	$stepOK = b6_exit_is( $r, 200, true ) && array( 'preference' => 'direct_key' ) === b6_exit_data( $r );
	b6_check( 'AI-PREF', $step403 && $step400 && $stepOK,
		'set_preference: 403 non-admin / 400 outside the closed enum / success roundtrip through the real adapter+repository', 'got ' . json_encode( array( $step403, $step400, $stepOK ) ) );

	// submit input contract: 400 job_type / 400 empty / 403 capability / 403 ownership.
	b6_begin_case( array( 'post_id' => '0', 'job_type' => 'bogus', 'content' => 'text' ), 3, true, array() );
	$r = b6_call_ajax( $SUBMIT );
	$step400a = b6_exit_is( $r, 400, false ) && 'Invalid job_type' === ( b6_exit_data( $r )['message'] ?? '' );
	b6_begin_case( array( 'post_id' => '0', 'job_type' => 'grammar', 'content' => '   ' ), 3, true, array() );
	$r = b6_call_ajax( $SUBMIT );
	$step400b = b6_exit_is( $r, 400, false ) && 'Empty content' === ( b6_exit_data( $r )['message'] ?? '' );
	b6_begin_case( array( 'post_id' => '0', 'job_type' => 'grammar', 'content' => 'text' ), 3, true, array() );
	$r = b6_call_ajax( $SUBMIT );
	$step403a = b6_exit_is( $r, 403, false ) && 'Unauthorized' === ( b6_exit_data( $r )['message'] ?? '' );
	b6_begin_case( array( 'post_id' => '5', 'job_type' => 'grammar', 'content' => 'text' ), 3, true, array( 'edit_post_5' => false ) );
	$GLOBALS['B6_POSTS'][5] = (object) array( 'ID' => 5, 'post_type' => 'post', 'post_status' => 'publish' );
	$r = b6_call_ajax( $SUBMIT );
	$step403b = b6_exit_is( $r, 403, false ) && 'Unauthorized for this article' === ( b6_exit_data( $r )['message'] ?? '' );
	b6_check( 'AI-SUBMIT-GUARDS', $step400a && $step400b && $step403a && $step403b,
		'submit: 400 invalid job_type / 400 empty content / 403 edit_posts / 403 edit_post ownership (server-side)', 'got ' . json_encode( array( $step400a, $step400b, $step403a, $step403b ) ) );

	// Quota paths through the real insert helper.
	$base = array( 'post_id' => '5', 'job_type' => 'grammar', 'content' => 'text' );
	b6_begin_case( $base, 3, true, array( 'edit_post_5' => true ) );
	$GLOBALS['B6_POSTS'][5] = (object) array( 'ID' => 5, 'post_type' => 'post', 'post_status' => 'publish' );
	$GLOBALS['B6_DB']['lock_acquire'] = 0;
	$r = b6_call_ajax( $SUBMIT );
	$step503a = b6_exit_is( $r, 503, false ) && 'AI service is busy. Try again.' === ( b6_exit_data( $r )['message'] ?? '' );
	b6_begin_case( $base, 3, true, array( 'edit_post_5' => true ) );
	$GLOBALS['B6_POSTS'][5] = (object) array( 'ID' => 5, 'post_type' => 'post', 'post_status' => 'publish' );
	$GLOBALS['B6_OPTIONS']['_transient_hossam_ai_rate_3'] = 30;
	$r = b6_call_ajax( $SUBMIT );
	$step429a = b6_exit_is( $r, 429, false ) && 'Rate limit exceeded. Try later.' === ( b6_exit_data( $r )['message'] ?? '' );
	b6_begin_case( $base, 3, true, array( 'edit_post_5' => true ) );
	$GLOBALS['B6_POSTS'][5] = (object) array( 'ID' => 5, 'post_type' => 'post', 'post_status' => 'publish' );
	$GLOBALS['B6_DB']['ai_day_count'] = 500;
	$r = b6_call_ajax( $SUBMIT );
	$step429b = b6_exit_is( $r, 429, false ) && 'Daily AI limit reached.' === ( b6_exit_data( $r )['message'] ?? '' );
	b6_begin_case( $base, 3, true, array( 'edit_post_5' => true ) );
	$GLOBALS['B6_POSTS'][5] = (object) array( 'ID' => 5, 'post_type' => 'post', 'post_status' => 'publish' );
	$GLOBALS['B6_TRANSIENT_FAIL'] = true;
	$r = b6_call_ajax( $SUBMIT );
	$step503b = b6_exit_is( $r, 503, false ) && 'AI service is unavailable. Try again.' === ( b6_exit_data( $r )['message'] ?? '' );
	b6_begin_case( $base, 3, true, array( 'edit_post_5' => true ) );
	$GLOBALS['B6_POSTS'][5] = (object) array( 'ID' => 5, 'post_type' => 'post', 'post_status' => 'publish' );
	$GLOBALS['B6_DB_FAIL'] = true;
	$r = b6_call_ajax( $SUBMIT );
	$step503c = b6_exit_is( $r, 503, false );
	b6_check( 'AI-QUOTA', $step503a && $step429a && $step429b && $step503b && $step503c,
		'quota matrix: lock-busy 503, minute 429, daily 429, transient-write 503, DB-failure 503 — DB failures never become empty success', 'got ' . json_encode( array( $step503a, $step429a, $step429b, $step503b, $step503c ) ) );

	// Submit success: schedule recorded, {job_id} payload, no secrets in row.
	b6_begin_case( $base, 3, true, array( 'edit_post_5' => true ) );
	$GLOBALS['B6_POSTS'][5] = (object) array( 'ID' => 5, 'post_type' => 'post', 'post_status' => 'publish' );
	$GLOBALS['B6_DB']['insert_id'] = 7;
	$r = b6_call_ajax( $SUBMIT );
	$inserted_json = '';
	$scheduled_key = 'hossam_ai_process_job_event|[7]';
	$strategy_leak = false;
	foreach ( $GLOBALS['wpdb']->queries as $q ) {
		if ( false !== strpos( (string) $q, 'INSERT INTO' ) ) {
			$inserted_json = (string) $q;
			if ( false !== stripos( $q, 'direct_key' ) && false !== stripos( $q, 'key' ) ) {
				$strategy_leak = true;
			}
		}
	}
	$ok_shape = b6_exit_is( $r, 200, true ) && array( 'job_id' => 7 ) === b6_exit_data( $r );
	b6_check( 'AI-SUBMIT-OK', $ok_shape && isset( $GLOBALS['B6_SCHEDULED'][ $scheduled_key ] ) && '' !== $inserted_json && ! $strategy_leak,
		'submit success: {job_id} payload, internal single event scheduled (wp_schedule_single_event, no loopback), strategy_id stored non-revealingly', 'got ' . json_encode( $r ) . ' sched ' . var_export( isset( $GLOBALS['B6_SCHEDULED'][ $scheduled_key ] ), true ) );

	// Duplicate event → success without a second schedule.
	b6_begin_case( $base, 3, true, array( 'edit_post_5' => true ) );
	$GLOBALS['B6_POSTS'][5] = (object) array( 'ID' => 5, 'post_type' => 'post', 'post_status' => 'publish' );
	$GLOBALS['B6_DB']['insert_id'] = 8;
	$GLOBALS['B6_SCHEDULED'][ 'hossam_ai_process_job_event|[8]' ] = time();
	$r = b6_call_ajax( $SUBMIT );
	$still_one = array_key_exists( 'hossam_ai_process_job_event|[8]', $GLOBALS['B6_SCHEDULED'] );
	b6_check( 'AI-SUBMIT-DUP', b6_exit_is( $r, 200, true ) && $still_one,
		'duplicate schedule check: wp_next_scheduled short-circuits (no second event, no error)', 'got ' . json_encode( $r ) );

	// Schedule failure → 500 + job failed with schedule_failed.
	b6_begin_case( $base, 3, true, array( 'edit_post_5' => true ) );
	$GLOBALS['B6_POSTS'][5] = (object) array( 'ID' => 5, 'post_type' => 'post', 'post_status' => 'publish' );
	$GLOBALS['B6_DB']['insert_id'] = 9;
	$GLOBALS['B6_SCHEDULE_FAIL'] = true;
	$r = b6_call_ajax( $SUBMIT );
	$failed_sql = false;
	foreach ( $GLOBALS['wpdb']->queries as $q ) {
		if ( false !== strpos( (string) $q, "SET status = 'failed'" ) && false !== strpos( (string) $q, 'schedule_failed' ) ) {
			$failed_sql = true;
		}
	}
	b6_check( 'AI-SCHED-FAIL', b6_exit_is( $r, 500, false ) && 'Could not schedule the AI task.' === ( b6_exit_data( $r )['message'] ?? '' ) && $failed_sql,
		'schedule failure → 500 and the job is failed safely with the schedule_failed reason', 'got ' . json_encode( $r ) );

	// get_status: 400 / 404 / success without strategy or secrets.
	b6_begin_case( array(), 3, true );
	$r = b6_call_ajax( $STATUS );
	$step400 = b6_exit_is( $r, 400, false ) && 'Invalid job_id' === ( b6_exit_data( $r )['message'] ?? '' );
	b6_begin_case( array( 'job_id' => '5' ), 3, true );
	$GLOBALS['B6_DB']['row'] = null;
	$r = b6_call_ajax( $STATUS );
	$step404 = b6_exit_is( $r, 404, false ) && 'Job not found' === ( b6_exit_data( $r )['message'] ?? '' );
	b6_begin_case( array( 'job_id' => '5' ), 3, true );
	$GLOBALS['B6_DB']['row'] = array( 'status' => 'completed', 'result' => wp_json_encode( array( 'text' => 'done' ) ), 'last_error' => null );
	$r = b6_call_ajax( $STATUS );
	$data = b6_exit_data( $r );
	$ownership_sql = false;
	foreach ( $GLOBALS['wpdb']->queries as $q ) {
		if ( false !== strpos( (string) $q, 'user_id = 3' ) && false !== strpos( (string) $q, 'SELECT status, result, last_error' ) ) {
			$ownership_sql = true;
		}
	}
	$shape_ok = b6_exit_is( $r, 200, true ) && 'completed' === ( $data['status'] ?? '' ) && array( 'text' => 'done' ) === ( $data['result'] ?? array() )
		&& array_key_exists( 'error', $data ) && ! array_key_exists( 'strategy', $data ) && ! array_key_exists( 'input_data', $data );
	b6_check( 'AI-STATUS', $step400 && $step404 && $shape_ok && $ownership_sql,
		'job status: 400/404 contract; success exposes status/result/error only — no strategy_id/input/secrets — and ownership lives in the WHERE', 'got ' . json_encode( $r ) );

	b6_result_line( 'ai' );
}

function b6_mode_ai_noprofile( string $project ): void {
	$GLOBALS['B6_PROJECT'] = $project;
	b6_define_release_context();
	b6_define_admin_includes();
	b6_enable_ai_client();
	// Deliberately NO runtime profile: the cost-guard contract must fail
	// closed before any quota or provider work.
	b6_begin_case();
	b6_require_runtime();

	b6_begin_case( array( 'post_id' => '0', 'job_type' => 'grammar', 'content' => 'text' ), 3, true, array( 'edit_posts' => true ) );
	$r = b6_call_ajax( 'wp_ajax_hossam_ai_submit_job' );
	b6_check( 'NP-503', b6_exit_is( $r, 503, false ) && 'AI assistance is unavailable in this environment.' === ( b6_exit_data( $r )['message'] ?? '' ),
		'missing runtime profile → 503 fail-closed before quota/claim/provider work', 'got ' . json_encode( $r ) );

	b6_result_line( 'ai-noprofile' );
}

function b6_mode_ai_disabled_noprofile( string $project ): void {
	$GLOBALS['B6_PROJECT'] = $project;
	b6_define_release_context();
	b6_define_admin_includes();
	// Deliberately NO runtime profile AND ai disabled: outstanding jobs
	// must settle as feature_disabled (worker + sweep) instead of hanging
	// on blind retries; finished/missing rows stay untouched (B6-02).
	b6_begin_case();
	b6_require_runtime();

	$WORKER = 'hossam_ai_process_job_event';
	$SWEEP  = 'hossam_ai_sweep_jobs_event';

	// Worker, pending row: failed feature_disabled, zero generation, no retry.
	b6_begin_case();
	b6_set_features( array( 'ai' => false ) );
	$GLOBALS['B6_DB']['row'] = array( 'id' => 7, 'status' => 'pending' );
	do_action( $WORKER, 7 );
	$failed_pending = false;
	foreach ( $GLOBALS['wpdb']->queries as $q ) {
		if ( false !== strpos( (string) $q, "SET status = 'failed'" ) && false !== strpos( (string) $q, 'feature_disabled' ) ) {
			$failed_pending = true;
		}
	}
	b6_check( 'W-PROFILE-DISABLED', $failed_pending && ! array_key_exists( 'hossam_ai_process_job_event|[7]', $GLOBALS['B6_SCHEDULED'] ),
		'worker with invalid profile + ai disabled: pending job fails feature_disabled with no retry scheduling (no hang)',
		'failed ' . var_export( $failed_pending, true ) );

	// Worker, finished row: untouched, no retry.
	b6_begin_case();
	b6_set_features( array( 'ai' => false ) );
	$GLOBALS['B6_DB']['row'] = array( 'id' => 7, 'status' => 'completed' );
	do_action( $WORKER, 7 );
	$touched_finished = false;
	foreach ( $GLOBALS['wpdb']->queries as $q ) {
		if ( false !== strpos( (string) $q, 'SET status' ) ) {
			$touched_finished = true;
		}
	}
	b6_check( 'W-PROFILE-FINISHED', ! $touched_finished && ! array_key_exists( 'hossam_ai_process_job_event|[7]', $GLOBALS['B6_SCHEDULED'] ),
		'worker with invalid profile + ai disabled: finished job untouched with no retry',
		'touched ' . var_export( $touched_finished, true ) );

	// Worker, missing row: untouched, no retry.
	b6_begin_case();
	b6_set_features( array( 'ai' => false ) );
	$GLOBALS['B6_DB']['row'] = null;
	do_action( $WORKER, 7 );
	$touched_missing = false;
	foreach ( $GLOBALS['wpdb']->queries as $q ) {
		if ( false !== strpos( (string) $q, 'SET status' ) ) {
			$touched_missing = true;
		}
	}
	b6_check( 'W-PROFILE-MISSING', ! $touched_missing && ! array_key_exists( 'hossam_ai_process_job_event|[7]', $GLOBALS['B6_SCHEDULED'] ),
		'worker with invalid profile + ai disabled: missing job untouched with no retry',
		'touched ' . var_export( $touched_missing, true ) );

	// Interleaving (TOCTOU): the job completes between our status SELECT
	// and the conditional UPDATE — the row must stay completed (the
	// UPDATE is issued but matches zero rows; never flipped to failed).
	b6_begin_case();
	b6_set_features( array( 'ai' => false ) );
	$GLOBALS['B6_DB']['row'] = array( 'id' => 7, 'status' => 'pending' );
	$GLOBALS['B6_DB']['race_complete_on_select'] = true;
	do_action( $WORKER, 7 );
	$race_update_issued = false;
	foreach ( $GLOBALS['wpdb']->queries as $q ) {
		if ( false !== strpos( (string) $q, "SET status = 'failed'" ) && false !== strpos( (string) $q, 'WHERE id = 7' ) ) {
			$race_update_issued = true;
		}
	}
	b6_check( 'W-PROFILE-RACE', $race_update_issued && 'completed' === ( $GLOBALS['B6_DB']['row']['status'] ?? '' )
		&& ! array_key_exists( 'hossam_ai_process_job_event|[7]', $GLOBALS['B6_SCHEDULED'] ),
		'interleaving: concurrent completion wins — conditional UPDATE issued but the completed row is never flipped to failed, with no retry',
		'issued ' . var_export( $race_update_issued, true ) . ' state ' . var_export( $GLOBALS['B6_DB']['row']['status'] ?? null, true ) );

	// Worker, ai ENABLED + invalid profile: the legacy blind retry is
	// preserved (nothing executes, the job stays pending for recovery).
	b6_begin_case();
	$GLOBALS['B6_DB']['row'] = array( 'id' => 7, 'status' => 'pending' );
	do_action( $WORKER, 7 );
	$failed_enabled = false;
	foreach ( $GLOBALS['wpdb']->queries as $q ) {
		if ( false !== strpos( (string) $q, "SET status = 'failed'" ) ) {
			$failed_enabled = true;
		}
	}
	b6_check( 'W-PROFILE-ENABLED-RETRY', ! $failed_enabled && array_key_exists( 'hossam_ai_process_job_event|[7]', $GLOBALS['B6_SCHEDULED'] ),
		'worker with invalid profile + ai enabled: pending job kept (no fail) with the legacy retry scheduled',
		'failed ' . var_export( $failed_enabled, true ) );

	// Sweeper, invalid limits + ai disabled: stale pending fails, nothing scheduled.
	b6_begin_case();
	b6_set_features( array( 'ai' => false ) );
	$GLOBALS['B6_DB']['col'] = array( 7 );
	do_action( $SWEEP );
	$sweep_failed = false;
	foreach ( $GLOBALS['wpdb']->queries as $q ) {
		if ( false !== strpos( (string) $q, "SET status = 'failed'" ) && false !== strpos( (string) $q, 'feature_disabled' ) ) {
			$sweep_failed = true;
		}
	}
	b6_check( 'S-PROFILE-DISABLED', $sweep_failed,
		'sweeper with invalid limits + ai disabled: stale pending fails feature_disabled (no hang)',
		'swept ' . var_export( $sweep_failed, true ) );
	$sweep_touched_other = false;
	$sweep_select_pending_only = false;
	foreach ( $GLOBALS['wpdb']->queries as $q ) {
		if ( false !== strpos( (string) $q, 'WHERE id = 9' ) ) {
			$sweep_touched_other = true;
		}
		if ( false !== strpos( (string) $q, 'SELECT id FROM' ) && false !== strpos( (string) $q, "status = 'pending'" ) ) {
			$sweep_select_pending_only = true;
		}
	}
	b6_check( 'S-PROFILE-OTHER-UNTOUCHED', ! $sweep_touched_other && $sweep_select_pending_only,
		'sweeper disabled-policy selects pending ids only and never addresses a finished id 9',
		'touched-other ' . var_export( $sweep_touched_other, true ) );

	// Orphaned processing with no worker event: the real sweeper alone
	// settles both outstanding states (B6-02 remainder).
	b6_begin_case();
	b6_set_features( array( 'ai' => false ) );
	$GLOBALS['B6_DB']['col_pending'] = array( 7 );
	$GLOBALS['B6_DB']['col_processing'] = array( 9 );
	do_action( $SWEEP );
	$failed_ids = array();
	$selected_both = 0;
	foreach ( $GLOBALS['wpdb']->queries as $q ) {
		if ( false !== strpos( (string) $q, "SET status = 'failed'" ) && false !== strpos( (string) $q, 'feature_disabled' ) ) {
			if ( false !== strpos( (string) $q, 'WHERE id = 7' ) ) { $failed_ids[] = 7; }
			if ( false !== strpos( (string) $q, 'WHERE id = 9' ) ) { $failed_ids[] = 9; }
		}
		if ( false !== strpos( (string) $q, 'SELECT id FROM' ) && false !== strpos( (string) $q, "status = 'pending'" ) ) { $selected_both++; }
		if ( false !== strpos( (string) $q, 'SELECT id FROM' ) && false !== strpos( (string) $q, "status = 'processing'" ) ) { $selected_both++; }
	}
	sort( $failed_ids );
	b6_check( 'S-PROFILE-ORPHAN', array( 7, 9 ) === $failed_ids && 2 === $selected_both,
		'real sweeper alone with invalid limits + ai disabled: pending 7 and orphaned processing 9 both fail feature_disabled (finished rows never selected)',
		'failed ' . json_encode( $failed_ids ) . ' selects ' . $selected_both );

	b6_result_line( 'ai-disabled-noprofile' );
}

function b6_mode_ai_unavailable( string $project ): void {
	$GLOBALS['B6_PROJECT'] = $project;
	b6_define_release_context();
	b6_define_valid_profile();
	b6_define_admin_includes();
	// Profile present but NO wp_ai_client_prompt and no direct key: the
	// strategy resolution must fail closed at submit.
	b6_begin_case();
	b6_require_runtime();

	b6_begin_case( array( 'post_id' => '0', 'job_type' => 'grammar', 'content' => 'text' ), 3, true, array( 'edit_posts' => true ) );
	$r = b6_call_ajax( 'wp_ajax_hossam_ai_submit_job' );
	b6_check( 'UN-503', b6_exit_is( $r, 503, false ) && 'No AI provider is currently available.' === ( b6_exit_data( $r )['message'] ?? '' ),
		'no available strategy → 503 No AI provider is currently available (fail-closed strategy resolution)', 'got ' . json_encode( $r ) );

	b6_result_line( 'ai-unavailable' );
}

function b6_mode_ai_worker( string $project ): void {
	$GLOBALS['B6_PROJECT'] = $project;
	b6_define_release_context();
	b6_define_valid_profile();
	b6_define_admin_includes();
	b6_enable_ai_client();
	b6_begin_case();
	b6_require_runtime();

	$WORKER = 'hossam_ai_process_job_event';
	$base_row = array(
		'id' => '7', 'user_id' => '3', 'post_id' => '5', 'job_type' => 'grammar',
		'input_data' => wp_json_encode( array( 'content' => 'draft text', 'target_lang' => '', 'title' => '' ) ),
		'status' => 'processing', 'strategy_id' => 'wp_ai_client', 'attempt_count' => '1',
		'next_run_at' => gmdate( 'Y-m-d H:i:s' ), 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ),
	);

	// Normal path: claim → prompts → real wp_ai_client strategy → completed.
	b6_begin_case();
	$GLOBALS['B6_POSTS'][5] = (object) array( 'ID' => 5, 'post_type' => 'post', 'post_status' => 'publish' );
	$GLOBALS['B6_USER_CAN']['3|edit_post_5'] = true;
	$GLOBALS['B6_DB']['row'] = $base_row;
	do_action( $WORKER, 7 );
	$completed = false;
	$result_in_row = false;
	foreach ( $GLOBALS['wpdb']->queries as $q ) {
		if ( false !== strpos( (string) $q, "SET status = 'completed'" ) && false !== strpos( (string) $q, 'B6 generated text' ) ) {
			$completed = true;
		}
		if ( false !== strpos( (string) $q, 'INSERT INTO' ) ) {
			$result_in_row = true;
		}
	}
	b6_check( 'W-OK', 1 === B6_AiPrompt::$generate_calls && $completed && ! $result_in_row,
		'worker normal path: atomic claim → real strategy executes once → completed UPDATE carries the generated text only', 'generate calls ' . B6_AiPrompt::$generate_calls . ' completed ' . var_export( $completed, true ) );

	// B3-08: feature disabled AFTER a successful claim → safe fail with
	// feature_disabled, zero generation, no retry scheduling.
	b6_begin_case();
	b6_set_features( array( 'ai' => false ) );
	$GLOBALS['B6_POSTS'][5] = (object) array( 'ID' => 5, 'post_type' => 'post', 'post_status' => 'publish' );
	$GLOBALS['B6_USER_CAN']['3|edit_post_5'] = true;
	$GLOBALS['B6_DB']['row'] = $base_row;
	do_action( $WORKER, 7 );
	$failed_disabled = false;
	foreach ( $GLOBALS['wpdb']->queries as $q ) {
		if ( false !== strpos( (string) $q, "SET status = 'failed'" ) && false !== strpos( (string) $q, 'feature_disabled' ) ) {
			$failed_disabled = true;
		}
	}
	b6_check( 'W-GATE', 0 === B6_AiPrompt::$generate_calls && $failed_disabled && empty( $GLOBALS['B6_SCHEDULED'] ),
		'worker B3-08 gate: disabled feature → claimed job failed feature_disabled, zero generation, no pending hang', 'generate calls ' . B6_AiPrompt::$generate_calls . ' failed ' . var_export( $failed_disabled, true ) );

	// Claim lock busy → retry scheduled, no claim, no failure.
	b6_begin_case();
	$GLOBALS['B6_DB']['lock_acquire'] = 0;
	do_action( $WORKER, 7 );
	$retry = array_key_exists( 'hossam_ai_process_job_event|[7]', $GLOBALS['B6_SCHEDULED'] );
	$no_claim = true;
	foreach ( $GLOBALS['wpdb']->queries as $q ) {
		if ( false !== strpos( (string) $q, "SET status = 'processing'" ) ) {
			$no_claim = false;
		}
	}
	b6_check( 'W-LOCK', $retry && $no_claim, 'claim lock busy → the real retry helper reschedules the same hook/args and nothing is claimed', 'retry ' . var_export( $retry, true ) );

	// Concurrent limit reached → retry, no claim.
	b6_begin_case();
	$GLOBALS['B6_DB']['ai_processing'] = 2;
	do_action( $WORKER, 7 );
	$retry2 = array_key_exists( 'hossam_ai_process_job_event|[7]', $GLOBALS['B6_SCHEDULED'] );
	b6_check( 'W-CONCURRENT', $retry2, 'concurrent capacity exhausted → retry scheduled without claiming (processing limit honoured)', 'retry ' . var_export( $retry2, true ) );

	// Max attempts exceeded → fail with max_attempts_exceeded.
	b6_begin_case();
	$GLOBALS['B6_DB']['row'] = array_merge( $base_row, array( 'attempt_count' => '4' ) );
	$GLOBALS['B6_USER_CAN']['3|edit_post_5'] = true;
	do_action( $WORKER, 7 );
	$max_sql = false;
	foreach ( $GLOBALS['wpdb']->queries as $q ) {
		if ( false !== strpos( (string) $q, 'max_attempts_exceeded' ) ) {
			$max_sql = true;
		}
	}
	b6_check( 'W-MAX', $max_sql, 'attempt_count beyond the profile limit → the job fails with max_attempts_exceeded', 'found ' . var_export( $max_sql, true ) );

	// Permission revoked between submit and run → permission_revoked.
	b6_begin_case();
	$GLOBALS['B6_DB']['row'] = $base_row;
	$GLOBALS['B6_USER_CAN']['3|edit_post_5'] = false;
	do_action( $WORKER, 7 );
	$revoked = false;
	foreach ( $GLOBALS['wpdb']->queries as $q ) {
		if ( false !== strpos( (string) $q, 'permission_revoked' ) ) {
			$revoked = true;
		}
	}
	b6_check( 'W-REVOKE', $revoked, 'lost edit_post between submit and run → the real re-check fails the job with permission_revoked', 'found ' . var_export( $revoked, true ) );

	b6_result_line( 'ai-worker' );
}

function b6_mode_store_wc( string $project ): void {
	$GLOBALS['B6_PROJECT'] = $project;
	b6_define_release_context();
	b6_define_valid_profile();
	b6_define_admin_includes();
	b6_enable_wc_fixtures();
	b6_begin_case();
	b6_require_runtime();

	$STORE = 'wp_ajax_hossam_get_products';
	$ALL = array( 'edit_products' => true, 'edit_others_products' => true );

	// Nonce + 401 + 403 matrix.
	b6_begin_case( array(), 3, true, array(), false );
	$r = b6_call_ajax( $STORE );
	$stepNonce = 403 === $r['status'] && -1 === ( $r['body'] ?? null );
	b6_begin_case( array(), 3, false, array( 'edit_products' => true ) );
	$r = b6_call_ajax( $STORE );
	$step401 = b6_exit_is( $r, 401, false );
	b6_begin_case( array(), 5, true, array() );
	$r = b6_call_ajax( $STORE );
	$step403 = b6_exit_is( $r, 403, false ) && 'You do not have permission to view products.' === ( b6_exit_data( $r )['message'] ?? '' );
	b6_check( 'S-GUARDS', $stepNonce && $step401 && $step403,
		'store: 403 -1 nonce / 401 logged-out / 403 edit_products legacy payload', 'got ' . json_encode( array( $stepNonce, $step401, $step403 ) ) );

	// Feature gate before edit_products (owner caps + store off).
	b6_begin_case( array(), 3, true, $ALL );
	b6_set_features( array( 'store' => false ) );
	$r = b6_call_ajax( $STORE );
	b6_check( 'S-GATE', b6_exit_is( $r, 403, false ) && 'feature_disabled' === ( b6_exit_data( $r )['code'] ?? '' ),
		'store feature disabled → 403 feature_disabled before edit_products', 'got ' . json_encode( $r ) );

	// Registry products capability off → 503 (integration decision guard).
	b6_begin_case( array(), 3, true, $ALL );
	b6_set_registry( array( 'woocommerce' => array( 'status' => 'limited', 'plugin_active' => true, 'version' => '9.0', 'capabilities' => array( 'products' => false, 'orders' => true ), 'reason' => '', 'checked_at' => '2026-09-15' ) ) );
	$r = b6_call_ajax( $STORE );
	$step503 = b6_exit_is( $r, 503, false ) && 'WooCommerce not active' === ( b6_exit_data( $r )['message'] ?? '' );
	// Success with full products capability: edit_others scope lists all.
	b6_begin_case( array( 'page' => '1' ), 3, true, $ALL );
	b6_set_registry( array( 'woocommerce' => array( 'status' => 'available', 'plugin_active' => true, 'version' => '9.0', 'capabilities' => array( 'products' => true, 'orders' => true ), 'reason' => '', 'checked_at' => '2026-09-15' ) ) );
	$GLOBALS['B6_WC']['products'] = array();
	for ( $i = 0; $i < 21; $i++ ) {
		$p            = new B6_WCProduct();
		$p->id        = 11 + $i;
		$GLOBALS['B6_WC']['products'][] = $p;
	}
	$r = b6_call_ajax( $STORE );
	$data = b6_exit_data( $r );
	$args_all = $GLOBALS['B6_WC']['last_products_args'];
	$stepOK = b6_exit_is( $r, 200, true ) && 20 === count( $data['products'] ?? array() )
		&& true === ( $data['has_more'] ?? false ) && 1 === ( $data['page'] ?? 0 )
		&& isset( $args_all['orderby'], $args_all['offset'] ) && ! isset( $args_all['include'] );
	// Owned scope: no edit_others_products → get_posts author path with include.
	b6_begin_case( array( 'page' => '2' ), 5, true, array( 'edit_products' => true ) );
	b6_set_registry( array( 'woocommerce' => array( 'status' => 'available', 'plugin_active' => true, 'version' => '9.0', 'capabilities' => array( 'products' => true, 'orders' => true ), 'reason' => '', 'checked_at' => '2026-09-15' ) ) );
	$GLOBALS['B6_WC']['products'] = array( new B6_WCProduct() );
	$GLOBALS['B6_GET_POSTS'] = array( 11, 12 );
	$r = b6_call_ajax( $STORE );
	$args_own = $GLOBALS['B6_WC']['last_products_args'];
	$own_posts_args = $GLOBALS['B6_GET_POSTS_ARGS'] ?? array();
	$data = b6_exit_data( $r );
	$stepOwn = b6_exit_is( $r, 200, true ) && 1 === count( $data['products'] ?? array() ) && false === ( $data['has_more'] ?? true )
		&& ( $own_posts_args['author'] ?? 0 ) === 5 && isset( $args_own['include'] ) && ! isset( $args_own['author'] );
	b6_check( 'S-SCOPE', $step503 && $stepOK && $stepOwn,
		'store: 503 on missing products capability; edit_others lists all with offset pagination; owned scope queries by author then include',
		'got ' . json_encode( array( $step503, $stepOK, $stepOwn ) ) . ' OK-resp: ' . json_encode( $r ) . ' args: ' . json_encode( $args_all ) );

	// Read failure vs genuinely empty on both product read paths.
	$store_registry = static function (): void {
		b6_set_registry( array( 'woocommerce' => array( 'status' => 'available', 'plugin_active' => true, 'version' => '9.0', 'capabilities' => array( 'products' => true, 'orders' => true ), 'reason' => '', 'checked_at' => '2026-09-15' ) ) );
	};
	b6_begin_case( array( 'page' => '1' ), 3, true, $ALL );
	$store_registry();
	$GLOBALS['B6_WC']['products'] = array();
	$GLOBALS['wpdb']->last_error = 'B6 fixture read failure';
	$r = b6_call_ajax( $STORE );
	b6_check( 'S-PROD-FAIL', b6_exit_is( $r, 500, false ) && 'Products could not be loaded.' === ( b6_exit_data( $r )['message'] ?? '' ),
		'products read failure (manager path) → 500 (never an empty success)', 'got ' . json_encode( $r ) );

	b6_begin_case( array( 'page' => '1' ), 3, true, $ALL );
	$store_registry();
	$GLOBALS['B6_WC']['products'] = array();
	$r = b6_call_ajax( $STORE );
	$data = b6_exit_data( $r );
	b6_check( 'S-PROD-EMPTY', b6_exit_is( $r, 200, true ) && array() === ( $data['products'] ?? null )
		&& false === ( $data['has_more'] ?? true ),
		'genuinely empty products → 200 empty success', 'got ' . json_encode( $r ) );

	b6_begin_case( array( 'page' => '1' ), 5, true, array( 'edit_products' => true ) );
	$store_registry();
	$GLOBALS['B6_WC']['products'] = array( new B6_WCProduct() );
	$GLOBALS['B6_GET_POSTS'] = array( 11, 12 );
	$GLOBALS['wpdb']->last_error = 'B6 fixture read failure';
	$r = b6_call_ajax( $STORE );
	b6_check( 'S-OWN-FAIL', b6_exit_is( $r, 500, false ) && 'Products could not be loaded.' === ( b6_exit_data( $r )['message'] ?? '' ),
		'owned-scope get_posts read failure → 500 before the include query', 'got ' . json_encode( $r ) );

	b6_result_line( 'store-wc' );
}

function b6_mode_b308( string $project ): void {
	$GLOBALS['B6_PROJECT'] = $project;
	b6_define_release_context();
	b6_define_valid_profile();
	b6_define_admin_includes();
	b6_begin_case();
	b6_require_runtime();

	// Every batch-6 endpoint: feature disabled → 403 feature_disabled;
	// feature on (default) → the gate passes and the request proceeds to
	// the legacy guards (not feature_disabled).
	$matrix = array(
		'wp_ajax_hossam_get_upcoming_appointments' => 'amelia',
		'wp_ajax_hossam_lazy_appointments'         => 'amelia',
		'wp_ajax_hossam_get_orders'                => 'finance',
		'wp_ajax_hossam_finance_summary'           => 'finance',
		'wp_ajax_hossam_get_payment_methods'       => 'finance',
		'wp_ajax_hossam_get_saved_tokens'          => 'finance',
		'wp_ajax_hossam_get_members'               => 'members',
		'wp_ajax_hossam_get_notifications'         => 'inbox',
		'wp_ajax_hossam_mark_read'                 => 'inbox',
		'wp_ajax_hossam_mark_all_read'             => 'inbox',
		'wp_ajax_hossam_send_message'              => 'inbox',
		'wp_ajax_hossam_get_inbox'                 => 'inbox',
		'wp_ajax_hossam_mark_message_read'         => 'inbox',
		'wp_ajax_hossam_get_products'              => 'store',
		'wp_ajax_hossam_ai_submit_job'             => 'ai',
		'wp_ajax_hossam_ai_get_job_status'         => 'ai',
		'wp_ajax_hossam_ai_set_preference'         => 'ai',
	);
	$gate_off_ok = true;
	$gate_on_ok = true;
	foreach ( $matrix as $hook => $feature ) {
		b6_begin_case( array(), 3, true, array( 'manage_options' => true, 'edit_products' => true, 'edit_posts' => true ) );
		b6_set_features( array( $feature => false ) );
		$r = b6_call_ajax( $hook );
		$gate_off_ok = $gate_off_ok && b6_exit_is( $r, 403, false ) && 'feature_disabled' === ( b6_exit_data( $r )['code'] ?? '' );

		b6_begin_case( array(), 3, true, array( 'manage_options' => true, 'edit_products' => true, 'edit_posts' => true ) );
		$r = b6_call_ajax( $hook );
		$body = (string) wp_json_encode( $r['body'] ?? null );
		$gate_on_ok = $gate_on_ok && 'feature_disabled' !== ( b6_exit_data( $r )['code'] ?? '' ) && false === strpos( $body, 'feature_disabled' );
	}
	b6_check( 'B308-OFF', $gate_off_ok,
		'B3-08: all 17 batch-6 endpoints fail closed with 403 feature_disabled when their feature is off (owner decision, option أ)',
		'gate-off matrix failed' );
	b6_check( 'B308-ON', $gate_on_ok,
		'B3-08: with features enabled (the unset default) no batch-6 endpoint returns feature_disabled — the legacy behaviour is untouched',
		'gate-on matrix failed' );

	b6_result_line( 'b308' );
}

/* ── Dispatch ── */
$project = dirname( __DIR__, 2 );
if ( ! defined( 'HAL_TEST_EXT_DIR' ) ) {
	$hal_ext_dir = (string) ( getenv( 'HAL_PHP_EXT_DIR' ) ?: ini_get( 'extension_dir' ) );
	if ( '' !== $hal_ext_dir && ! is_dir( $hal_ext_dir ) ) {
		$hal_ext_dir = (string) realpath( $hal_ext_dir );
	}
	define( 'HAL_TEST_EXT_DIR', is_dir( $hal_ext_dir ) ? $hal_ext_dir : (string) $hal_ext_dir );
}
require_once __DIR__ . '/lib-hal-php-flags.php'; // test-launcher flag filter (no product code, no assertions)
$mode = isset( $argv[1] ) ? (string) $argv[1] : 'main';
switch ( $mode ) {
	case 'main':
		b6_mode_main( $project );
		break;
	case 'appointments':
		b6_mode_appointments( $project );
		break;
	case 'appointments-notables':
		b6_mode_appointments_notables( $project );
		break;
	case 'finance':
		b6_mode_finance( $project );
		break;
	case 'finance-wc':
		b6_mode_finance_wc( $project );
		break;
	case 'members':
		b6_mode_members( $project );
		break;
	case 'inbox':
		b6_mode_inbox( $project );
		break;
	case 'ai':
		b6_mode_ai( $project );
		break;
	case 'ai-noprofile':
		b6_mode_ai_noprofile( $project );
		break;
	case 'ai-disabled-noprofile':
		b6_mode_ai_disabled_noprofile( $project );
		break;
	case 'ai-unavailable':
		b6_mode_ai_unavailable( $project );
		break;
	case 'ai-worker':
		b6_mode_ai_worker( $project );
		break;
	case 'store-wc':
		b6_mode_store_wc( $project );
		break;
	case 'b308':
		b6_mode_b308( $project );
		break;
	default:
		echo "unknown mode: {$mode}\n";
		exit( 1 );
}
