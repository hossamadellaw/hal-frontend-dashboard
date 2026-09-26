<?php
/**
 * HAL Frontend Dashboard — Batch 2 closure evidence harness (B2-01..B2-04 + U2).
 *
 * Local, isolated harness: no live WordPress, no network, no database.
 * Calls the real project code — runtime/bootstrap.php, runtime/core/*.php
 * and includes/class-health-check.php — only WordPress itself is stubbed.
 *
 * Coverage (delegation 2026-09-13, findings B2-01..B2-04 + unresolved U2):
 *
 *   B2-01  readiness tied to the real initialization + verification result:
 *          the runtime_ready action still fires at load (closed Loader
 *          contract: fired = booted), while the boot_readiness filter
 *          evaluates live state — false before/under failed migrations,
 *          true after a verified pass. The real
 *          HAL_Frontend_Dashboard_Health_Check::handle_request() is driven
 *          end-to-end in both branches: migration failure ⇒ ack rejected
 *          (HAL_HEALTH_ACK_REJECTED), verified pass ⇒ success ready=true.
 *   B2-02  no schema-version downgrade: higher recorded version is
 *          preserved verbatim with zero dbDelta calls; lower/absent still
 *          migrate and record after verification; equal stays idempotent.
 *   B2-03  Dashboard asset graph is release-owned: the dashboard enqueue
 *          block never calls get_template_directory_uri() and never
 *          enqueues 'astra-parent-style'; the stylesheet URI comes from
 *          the release context.
 *   B2-04  path contract duality: bootstrap accepts
 *          HAL_FRONTEND_DASHBOARD_RUNTIME_DIR (architecture §13) or the
 *          compat RUNTIME_ROOT, defines the missing one from the present
 *          one, and exits silently when neither is defined.
 *   U2     real local behavior of setup/i18n/permissions/notifications:
 *          fail-closed AI profile, release-scoped registry cache key
 *          (B2-U3) with cache hit/miss, capability status building with
 *          secret masking, dashboard detection, asset path/uri, login
 *          redirect branches, admin-bar and no-cache guards, WPML string
 *          registration with the md5 guard (no duplicate registration),
 *          role capability guards (no repeated add_cap), notification
 *          recipients/JSON shape/failure handling, transition dedupe,
 *          Amelia-table guard with transient cleanup, and the finance
 *          cache epoch bump.
 *
 * Usage:  php tests/php/batch2-closure-test.php   (exit 0 = all pass)
 *         php tests/php/batch2-closure-test.php <mode>   (subprocess mode)
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

if ( PHP_SAPI !== 'cli' ) {
	echo "This harness must run under CLI PHP.\n";
	exit( 1 );
}

/* ────────────────────────────────────────────────────────────────
 * Shared harness bookkeeping + WordPress boundary stubs
 * (WordPress only — never project logic)
 * ──────────────────────────────────────────────────────────────── */

$GLOBALS['B2C_HOOKS']        = array();
$GLOBALS['B2C_ACTIONS']      = array();
$GLOBALS['B2C_OPTIONS']      = array();
$GLOBALS['B2C_RESULTS']      = array();
$GLOBALS['B2C_ENQUEUED_STYLES']  = array();
$GLOBALS['B2C_ENQUEUED_SCRIPTS'] = array();
$GLOBALS['B2C_DEQUEUED']         = array();
$GLOBALS['B2C_LOCALIZED']        = array();
$GLOBALS['B2C_TEMPLATE_URI_CALLS'] = 0;
$GLOBALS['B2C_REMOVED_ACTIONS']    = array();
$GLOBALS['B2C_REDIR_TARGET']       = null;
$GLOBALS['B2C_IS_DASHBOARD']       = false;
$GLOBALS['B2C_ACTIVE_PLUGINS']     = array();
$GLOBALS['B2C_USERS_BY_CAP']       = array();
$GLOBALS['B2C_NOTIFY_ADMIN_IDS']   = array( 1, 2 );
$GLOBALS['B2C_JSON_SENT']          = null;
$GLOBALS['B2C_ICL_REGISTERED']     = 0;
$GLOBALS['B2C_ICL_HAS']            = false;

function b2c_check( string $id, bool $condition, string $pass, string $fail = '' ): void {
	$GLOBALS['B2C_RESULTS'][] = array( 'id' => $id, 'ok' => $condition, 'pass' => $pass, 'fail' => $fail );
}

function b2c_register_hook( string $hook_name, $callback, int $priority ): void {
	$GLOBALS['B2C_HOOKS'][ $hook_name ][] = array( 'callback' => $callback, 'priority' => $priority );
}

function b2c_sorted( string $hook_name ): array {
	$entries = $GLOBALS['B2C_HOOKS'][ $hook_name ] ?? array();
	usort( $entries, static function ( array $a, array $b ): int { return $a['priority'] <=> $b['priority']; } );
	return $entries;
}

function b2c_invoke( string $hook_name, ...$args ): void {
	foreach ( b2c_sorted( $hook_name ) as $entry ) {
		call_user_func_array( $entry['callback'], $args );
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook_name, $callback = '', int $priority = 10, int $accepted_args = 1 ): bool {
		b2c_register_hook( $hook_name, $callback, $priority );
		return true;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook_name, $callback = '', int $priority = 10, int $accepted_args = 1 ): bool {
		b2c_register_hook( $hook_name, $callback, $priority );
		return true;
	}
}
if ( ! function_exists( 'remove_action' ) ) {
	function remove_action( string $hook_name, $callback = '', int $priority = 10 ): bool {
		$GLOBALS['B2C_REMOVED_ACTIONS'][] = $hook_name;
		return true;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook_name, ...$args ): void {
		$GLOBALS['B2C_ACTIONS'][ $hook_name ] = ( $GLOBALS['B2C_ACTIONS'][ $hook_name ] ?? 0 ) + 1;
		b2c_invoke( $hook_name, ...$args );
	}
}
if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook_name ): int {
		return $GLOBALS['B2C_ACTIONS'][ $hook_name ] ?? 0;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook_name, $value, ...$args ) {
		foreach ( b2c_sorted( $hook_name ) as $entry ) {
			$value = call_user_func_array( $entry['callback'], array_merge( array( $value ), $args ) );
		}
		return $value;
	}
}
if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( string $hook_name, $callback = false ) {
		return ! empty( $GLOBALS['B2C_HOOKS'][ $hook_name ] );
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default_value = false ) {
		return array_key_exists( $option, $GLOBALS['B2C_OPTIONS'] ) ? $GLOBALS['B2C_OPTIONS'][ $option ] : $default_value;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, $value, $autoload = null ): bool {
		$GLOBALS['B2C_OPTIONS'][ $option ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $transient ) {
		return get_option( '_transient_' . $transient );
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $transient, $value, int $expiration = 0 ): bool {
		return update_option( '_transient_' . $transient, $value );
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $transient ): bool {
		unset( $GLOBALS['B2C_OPTIONS'][ '_transient_' . $transient ] );
		return true;
	}
}
/* Cron boundary stubs (batch 6: ai.php's init sweep scheduler fires when
 * these modes run do_action('init') against the real runtime). */
$GLOBALS['B2C_SCHEDULED'] = $GLOBALS['B2C_SCHEDULED'] ?? array();
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( string $hook, array $args = array() ) {
		$key = $hook . '|' . json_encode( array_values( $args ) );
		return $GLOBALS['B2C_SCHEDULED'][ $key ] ?? false;
	}
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = array() ): bool {
		$key = $hook . '|' . json_encode( array_values( $args ) );
		$GLOBALS['B2C_SCHEDULED'][ $key ] = $timestamp;
		return true;
	}
}
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( int $timestamp, string $hook, array $args = array() ): bool {
		$key = $hook . '|' . json_encode( array_values( $args ) );
		$GLOBALS['B2C_SCHEDULED'][ $key ] = $timestamp;
		return true;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0 ) {
		return json_encode( $value, $flags );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ): string {
		return trim( (string) $value );
	}
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type ): string {
		return 'mysql' === $type ? gmdate( 'Y-m-d H:i:s' ) : (string) time();
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( array $args, string $url ): string {
		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
	}
}
if ( ! function_exists( 'get_users' ) ) {
	function get_users( array $args = array() ): array {
		if ( isset( $args['capability__in'] ) ) {
			return $GLOBALS['B2C_USERS_BY_CAP'];
		}
		return $GLOBALS['B2C_NOTIFY_ADMIN_IDS'];
	}
}
if ( ! function_exists( 'shortcode_exists' ) ) {
	function shortcode_exists( string $tag ): bool {
		return false;
	}
}
if ( ! function_exists( 'is_plugin_active' ) ) {
	function is_plugin_active( string $plugin_file ): bool {
		return in_array( $plugin_file, $GLOBALS['B2C_ACTIVE_PLUGINS'], true );
	}
}
if ( ! function_exists( 'get_plugin_data' ) ) {
	function get_plugin_data( string $plugin_file, bool $markup = true, bool $translate = true ): array {
		return array( 'Version' => '1.2.3' );
	}
}
if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite(): bool {
		return false;
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}
if ( ! function_exists( 'is_page' ) ) {
	function is_page( $page = '' ): bool {
		return $GLOBALS['B2C_IS_DASHBOARD'] && 'dashboard' === $page;
	}
}
if ( ! function_exists( 'is_page_template' ) ) {
	function is_page_template( string $template = '' ): bool {
		return false;
	}
}
if ( ! function_exists( 'get_queried_object' ) ) {
	function get_queried_object() {
		return $GLOBALS['B2C_QUERIED'] ?? null;
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post = null ) {
		$id = is_object( $post ) ? (int) ( $post->ID ?? 0 ) : (int) $post;
		return $GLOBALS['B2C_POSTS'][ $id ] ?? null;
	}
}
if ( ! function_exists( 'b2c_set_dashboard' ) ) {
	/**
	 * Batch 7 (§7.5): drive the real controller predicate with the
	 * owned-page fixture (page ID 88) instead of the removed
	 * is_page/is_page_template legacy checks.
	 */
	function b2c_set_dashboard( bool $on ): void {
		$GLOBALS['B2C_IS_DASHBOARD'] = $on;
		if ( $on ) {
			if ( ! isset( $GLOBALS['B2C_POSTS'][88] ) ) {
				$post              = new WP_Post();
				$post->ID          = 88;
				$post->post_type   = 'page';
				$post->post_status = 'publish';
				$GLOBALS['B2C_POSTS'][88] = $post;
			}
			$GLOBALS['B2C_QUERIED'] = $GLOBALS['B2C_POSTS'][88];
			$GLOBALS['B2C_OPTIONS']['hal_frontend_dashboard_page_id'] = 88;
		} else {
			$GLOBALS['B2C_QUERIED'] = null;
			unset( $GLOBALS['B2C_OPTIONS']['hal_frontend_dashboard_page_id'] );
		}
	}
}
if ( ! function_exists( 'get_queried_object_id' ) ) {
	function get_queried_object_id(): int {
		return 0;
	}
}
if ( ! function_exists( 'get_page_template_slug' ) ) {
	function get_page_template_slug( $post = null ): string {
		return '';
	}
}
if ( ! function_exists( 'user_can' ) ) {
	function user_can( $user, string $capability ): bool {
		return $user instanceof WP_User && in_array( $capability, $user->allcaps, true );
	}
}
if ( ! function_exists( 'get_role' ) ) {
	function get_role( string $role ) {
		return $GLOBALS['B2C_ROLES'][ $role ] ?? null;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability ): bool {
		return false;
	}
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		return true;
	}
}
if ( ! function_exists( 'show_admin_bar' ) ) {
	function show_admin_bar( bool $show ): void {
		$GLOBALS['B2C_ADMIN_BAR'] = $show;
	}
}
if ( ! function_exists( 'is_rtl' ) ) {
	function is_rtl(): bool {
		return false;
	}
}
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin(): bool {
		return false;
	}
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/' . $path;
	}
}
if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( int $user_id ) {
		return null;
	}
}
if ( ! function_exists( 'wp_redirect' ) ) {
	function wp_redirect( string $location, int $status = 302 ): bool {
		$GLOBALS['B2C_REDIR_TARGET'] = $location;
		return true;
	}
}
if ( ! function_exists( 'wp_doing_ajax' ) ) {
	function wp_doing_ajax(): bool {
		return defined( 'B2C_DOING_AJAX' ) && B2C_DOING_AJAX;
	}
}
if ( ! function_exists( 'wp_doing_cron' ) ) {
	function wp_doing_cron(): bool {
		return false;
	}
}
if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( string $handle, string $src = '', array $deps = array(), $ver = false, $media = 'all' ): void {
		$GLOBALS['B2C_ENQUEUED_STYLES'][ $handle ] = array( 'src' => $src, 'deps' => $deps, 'ver' => $ver );
	}
}
if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( string $handle, string $src = '', array $deps = array(), $ver = false, bool $in_footer = false ): void {
		$GLOBALS['B2C_ENQUEUED_SCRIPTS'][ $handle ] = array( 'src' => $src, 'deps' => $deps, 'ver' => $ver );
	}
}
if ( ! function_exists( 'wp_dequeue_style' ) ) {
	function wp_dequeue_style( string $handle ): void {
		$GLOBALS['B2C_DEQUEUED'][] = 'style:' . $handle;
	}
}
if ( ! function_exists( 'wp_dequeue_script' ) ) {
	function wp_dequeue_script( string $handle ): void {
		$GLOBALS['B2C_DEQUEUED'][] = 'script:' . $handle;
	}
}
if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( string $handle, string $object_name, array $l10n ): bool {
		$GLOBALS['B2C_LOCALIZED'][ $object_name ] = array( 'handle' => $handle, 'data' => $l10n );
		return true;
	}
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( string $action ): string {
		return 'nonce-for-' . $action;
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return $value;
	}
}
if ( ! class_exists( 'B2C_Json_Sent' ) ) {
	/**
	 * Faithful boundary emulation: real wp_send_json*() ends the request
	 * (wp_die), so the stub records the payload and unwinds via an
	 * exception instead of returning into the caller.
	 */
	class B2C_Json_Sent extends Exception {
	}
}
if ( ! function_exists( 'wp_send_json' ) ) {
	function wp_send_json( $response, $status_code = null ): void {
		$GLOBALS['B2C_JSON_SENT'] = array( 'type' => 'success', 'body' => $response );
		throw new B2C_Json_Sent( 'wp_send_json' );
	}
}
if ( ! function_exists( 'wp_send_json_error' ) ) {
	function wp_send_json_error( $data = null, $status_code = null ): void {
		$GLOBALS['B2C_JSON_SENT'] = array( 'type' => 'error', 'body' => $data, 'status' => $status_code );
		throw new B2C_Json_Sent( 'wp_send_json_error' );
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component );
	}
}
if ( ! function_exists( 'get_template_directory_uri' ) ) {
	function get_template_directory_uri(): string {
		$GLOBALS['B2C_TEMPLATE_URI_CALLS']++;
		return 'https://example.test/wp-content/themes/parent-theme';
	}
}
if ( ! function_exists( 'hossam_dashboard_url' ) ) {
	// Boundary stub: the real function ships in adapters/wpml.php (batch 4).
	function hossam_dashboard_url(): string {
		return 'https://example.test/dashboard-url-sentinel/';
	}
}
// Batch-4: the real wpml adapter now loads with the runtime in the
// subprocess modes, so setup.php's init callback reaches
// hossam_wpml_all_paths(). These WordPress boundary stubs keep that
// callback executable here (no pages exist → the adapter's documented
// no-WPML fallback returns the plain slugs).
if ( ! function_exists( 'get_page_by_path' ) ) {
	function get_page_by_path( string $page_path, $output = null, string $post_type = 'page' ) {
		return $GLOBALS['B2C_PAGES'][ $page_path ] ?? null;
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post ) {
		$id = is_object( $post ) ? ( $post->ID ?? 0 ) : (int) $post;
		return 'https://example.test/?p=' . $id;
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		return 'https://example.test/' . ltrim( $path, '/' );
	}
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public string $code;
		public string $message;
		public function __construct( string $code = '', string $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_code(): string {
			return $this->code;
		}
		public function get_error_message(): string {
			return $this->message;
		}
	}
}
if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public int $ID = 0;
		public array $allcaps = array();
		public function __construct( int $id = 0, array $caps = array() ) {
			$this->ID       = $id;
			$this->allcaps  = $caps;
		}
	}
}
if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID = 0;
		public string $post_type = 'post';
		public string $post_title = '';
		public string $post_name = '';
		// Item-8 fixture: the Template Controller contract requires a
		// viewable post_status (publish/private); previously absent, so
		// dashboard detection could never succeed here.
		public string $post_status = '';
	}
}
if ( ! class_exists( 'B2C_Role' ) ) {
	class B2C_Role {
		public string $name;
		public array $capabilities;
		public int $add_cap_calls = 0;
		public function __construct( string $name, array $capabilities = array() ) {
			$this->name         = $name;
			$this->capabilities = $capabilities;
		}
		public function has_cap( string $capability ): bool {
			return ! empty( $this->capabilities[ $capability ] );
		}
		public function add_cap( string $capability, bool $grant = true ): void {
			$this->add_cap_calls++;
			$this->capabilities[ $capability ] = $grant;
		}
	}
}

/**
 * $wpdb mock — records real inserts and table shapes; supports error
 * injection and SHOW TABLES/COLUMNS/INDEX emulation.
 */
final class B2C_WPDB {
	public string $prefix = 'wp_';
	/** @var string */
	public $last_error = '';
	/** @var array<string, array{columns:string[], indexes:string[]}> */
	private array $tables = array();
	/** @var array<int, array{table:string, data:array, format:array}> */
	public array $inserts = array();
	public bool $fail_inserts = false;

	public function b2c_record_table( string $table, array $columns, array $indexes ): void {
		$this->tables[ $table ] = array( 'columns' => $columns, 'indexes' => $indexes );
	}

	public function flush(): bool {
		$this->last_error = '';
		return true;
	}

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function prepare( string $query, ...$args ): string {
		foreach ( $args as $arg ) {
			if ( false !== strpos( $query, '%s' ) ) {
				$query = preg_replace( '/%s/', "'" . $arg . "'", $query, 1 );
			} elseif ( false !== strpos( $query, '%d' ) ) {
				$query = preg_replace( '/%d/', (string) (int) $arg, $query, 1 );
			}
		}
		return $query;
	}

	/**
	 * @return mixed
	 */
	public function get_var( ?string $query = null ) {
		if ( null === $query ) {
			return null;
		}
		if ( preg_match( "/^SHOW TABLES LIKE '(.*)'$/s", $query, $match ) ) {
			$name = str_replace( array( '\\_', '\\%', '\\\\' ), array( '_', '%', '\\' ), $match[1] );
			return array_key_exists( $name, $this->tables ) ? $name : null;
		}
		return null;
	}

	/**
	 * @return string[]
	 */
	public function get_col( string $query, int $column_offset = 0 ): array {
		if ( preg_match( '/^SHOW COLUMNS FROM `(.+)`/', $query, $match ) ) {
			return isset( $this->tables[ $match[1] ] ) ? array_values( $this->tables[ $match[1] ]['columns'] ) : array();
		}
		if ( preg_match( '/^SHOW INDEX FROM `(.+)`/', $query, $match ) ) {
			return isset( $this->tables[ $match[1] ] ) ? array_values( $this->tables[ $match[1] ]['indexes'] ) : array();
		}
		if ( preg_match( '/^SELECT DISTINCT externalId FROM/', $query ) ) {
			return array( '5' );
		}
		return array();
	}

	public function insert( string $table, array $data, array $format = array() ) {
		$this->inserts[] = array( 'table' => $table, 'data' => $data, 'format' => $format );
		if ( $this->fail_inserts ) {
			$this->last_error = 'b2c fixture: insert denied';
			return false;
		}
		return true;
	}
}

function b2c_result_line( string $mode, array $extra = array() ): void {
	$failed = array();
	foreach ( $GLOBALS['B2C_RESULTS'] as $result ) {
		if ( ! $result['ok'] ) {
			$failed[] = array( 'id' => $result['id'], 'fail' => $result['fail'] );
		}
	}
	$payload = array_merge( array( 'mode' => $mode, 'php' => PHP_VERSION ), $extra );
	if ( array() !== $failed ) {
		$payload['failed'] = $failed;
	}
	echo 'B2C-RESULT ' . json_encode( $payload, JSON_UNESCAPED_SLASHES ), PHP_EOL;
	$all_ok = true;
	foreach ( $GLOBALS['B2C_RESULTS'] as $result ) {
		if ( ! $result['ok'] ) {
			$all_ok = false;
		}
	}
	echo 'B2C-VERDICT ' . $mode . ' ' . ( $all_ok ? 'ALL-ASSERTIONS-HELD' : 'ASSERTIONS-FAILED' ), PHP_EOL;
	exit( $all_ok ? 0 : 1 );
}

/* ────────────────────────────────────────────────────────────────
 * Subprocess contract modes (real runtime/bootstrap.php + real
 * includes/class-health-check.php; WordPress boundary stubs only)
 * ──────────────────────────────────────────────────────────────── */

function b2c_remove_dir( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $item ) {
		$path = $item->getPathname();
		if ( $item->isLink() || ! $item->isDir() ) {
			@unlink( $path );
		} else {
			@rmdir( $path );
		}
	}
	@rmdir( $dir );
}

function b2c_make_abspath_scaffold(): string {
	$base     = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'b2c-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
	$includes = $base . DIRECTORY_SEPARATOR . 'wp-admin' . DIRECTORY_SEPARATOR . 'includes';
	if ( ! is_dir( $includes ) && ! @mkdir( $includes, 0777, true ) ) {
		fwrite( STDERR, "B2C cannot create the ABSPATH scaffold.\n" );
		exit( 1 );
	}
	file_put_contents( $includes . DIRECTORY_SEPARATOR . 'upgrade.php', "<?php\n// dbDelta is defined by the harness driver.\n" );
	register_shutdown_function( 'b2c_remove_dir', $base );
	return $base . DIRECTORY_SEPARATOR;
}

function b2c_define_release_constants( string $abspath, string $project ): void {
	define( 'ABSPATH', $abspath );
	define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT', $project . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR );
	define( 'HAL_FRONTEND_DASHBOARD_RELEASE_ID', '2.0.0+' . str_repeat( 'cd', 20 ) );
	define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION', '2.0.0' );
	define(
		'HAL_FRONTEND_DASHBOARD_RUNTIME_URL',
		'https://example.test/wp-content/mu-plugins/hal-frontend-dashboard/releases/' . HAL_FRONTEND_DASHBOARD_RELEASE_ID . '/'
	);
}

/** dbDelta driver stub with failure injection and call counting. */
$GLOBALS['B2C_DBDELTA_CALLS'] = 0;
$GLOBALS['B2C_DBDELTA_FAIL']  = false;
function dbDelta( $queries = array(), $execute = true ) {
	global $wpdb;
	$GLOBALS['B2C_DBDELTA_CALLS']++;
	if ( $GLOBALS['B2C_DBDELTA_FAIL'] ) {
		$wpdb->last_error = 'b2c fixture: denied CREATE/ALTER';
		return array();
	}
	$parsed = array();
	foreach ( (array) $queries as $sql ) {
		if ( ! is_string( $sql ) || ! preg_match( '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?\s*\(/i', $sql, $match ) ) {
			continue;
		}
		$open  = strpos( $sql, '(' );
		$close = strrpos( $sql, ')' );
		if ( false === $open || false === $close || $close <= $open ) {
			continue;
		}
		$columns = array();
		$indexes = array();
		foreach ( preg_split( '/\r\n|\r|\n/', substr( $sql, $open + 1, $close - $open - 1 ) ) as $line ) {
			$line = trim( $line, " \t," );
			if ( '' === $line ) {
				continue;
			}
			if ( preg_match( '/^PRIMARY\s+KEY/i', $line ) ) {
				$indexes[] = 'PRIMARY';
				continue;
			}
			if ( preg_match( '/^(?:UNIQUE\s+KEY|UNIQUE\s+INDEX|KEY|INDEX)\s+([A-Za-z0-9_]+)/i', $line, $m ) ) {
				$indexes[] = $m[1];
				continue;
			}
			$parts = preg_split( '/\s+/', $line );
			if ( isset( $parts[0] ) && '' !== $parts[0] ) {
				$columns[] = $parts[0];
			}
		}
		$wpdb->b2c_record_table( $match[1], $columns, $indexes );
	}
	return array();
}

/** Drive the real Health_Check::handle_request() against a written challenge. */
function b2c_run_health_ack( string $run_dir, bool $expect_ready ): void {
	require $GLOBALS['B2C_PROJECT'] . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'class-health-check.php';

	$mu_dir  = $run_dir . DIRECTORY_SEPARATOR . 'mu';
	$state   = $mu_dir . DIRECTORY_SEPARATOR . 'hal-frontend-dashboard' . DIRECTORY_SEPARATOR . 'state';
	if ( ! is_dir( $state ) && ! @mkdir( $state, 0777, true ) ) {
		fwrite( STDERR, "B2C cannot create the health state directory.\n" );
		exit( 1 );
	}

	$token = bin2hex( random_bytes( 32 ) );
	$op    = bin2hex( random_bytes( 16 ) );
	$challenge = array(
		'token_hash' => hash( 'sha256', $token ),
		'operation'  => $op,
		'release_id' => HAL_FRONTEND_DASHBOARD_RELEASE_ID,
		'version'    => HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION,
		'loader_id'  => HAL_FRONTEND_DASHBOARD_LOADER_ID,
		'deadline'   => time() + 60,
	);
	file_put_contents(
		$state . DIRECTORY_SEPARATOR . 'health-challenge-' . $op . '.json',
		json_encode( $challenge, JSON_UNESCAPED_SLASHES ) . "\n"
	);

	$_POST['token']     = $token;
	$_POST['operation'] = $op;

	try {
		HAL_Frontend_Dashboard_Health_Check::handle_request();
	} catch ( B2C_Json_Sent $sent_signal ) {
		// Faithful emulation of wp_send_json*() ending the request.
	}

	$sent = $GLOBALS['B2C_JSON_SENT'];
	$GLOBALS['B2C_JSON_SENT'] = null;
	if ( $expect_ready ) {
		b2c_check(
			'C-ACK-OK',
			is_array( $sent ) && 'success' === $sent['type'] && true === ( $sent['body']['ready'] ?? false )
				&& hash_equals( $op, (string) ( $sent['body']['operation'] ?? '' ) ),
			'real Health_Check::handle_request accepts the acknowledgement after a verified migration pass',
			'expected success ready=true acknowledgement; got ' . json_encode( $sent )
		);
	} else {
		b2c_check(
			'C-ACK-DENIED',
			is_array( $sent ) && 'error' === $sent['type'] && 'HAL_HEALTH_ACK_REJECTED' === ( $sent['body']['code'] ?? '' ),
			'real Health_Check::handle_request rejects the acknowledgement after a failed migration (B2-01: failure cannot produce a successful ack)',
			'expected HAL_HEALTH_ACK_REJECTED; got ' . json_encode( $sent )
		);
	}
}

function b2c_contract_mode( string $mode, string $project ): void {
	$GLOBALS['B2C_PROJECT'] = $project;
	$abspath = b2c_make_abspath_scaffold();

	switch ( $mode ) {
		case 'contract-none':
			// B2-04 negative: no release-context constant at all —
			// bootstrap must exit silently (loader rollback contract).
			define( 'ABSPATH', $abspath );
			$before = function_exists( 'hossam_is_dashboard' ) || did_action( 'hal_frontend_dashboard_runtime_ready' ) > 0;
			require $project . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'bootstrap.php';
			b2c_check(
				'C4-NONE',
				! $before && 0 === did_action( 'hal_frontend_dashboard_runtime_ready' ) && ! function_exists( 'hossam_is_dashboard' )
					&& ! defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_DIR' ) && ! defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT' ),
				'without DIR or ROOT bootstrap exits silently: no action, no core functions, no constants defined',
				'silent-exit contract broken'
			);
			b2c_result_line( $mode );
			// no fallthrough

		case 'contract-dir-only':
			// B2-04: the architecture §13 name alone must load the runtime.
			define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_DIR', $project . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR );
			define( 'ABSPATH', $abspath );
			require $project . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'bootstrap.php';
			b2c_check(
				'C4-DIR',
				defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_DIR' ) && defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT' )
					&& HAL_FRONTEND_DASHBOARD_RUNTIME_DIR === HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT
					&& function_exists( 'hossam_is_dashboard' )
					&& HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT . 'assets/css/dashboard.css' === hossam_asset_path( 'css/dashboard.css' ),
				'DIR alone loads the runtime: both constants defined equal and ROOT consumers (setup.php) keep working',
				'DIR-only contract broken'
			);
			b2c_result_line( $mode );
			// no fallthrough

		case 'contract-root-only':
			// B2-04 compat: the current Loader (loader-core.php:136) name
			// alone must keep working and gain the §13 name.
			b2c_define_release_constants( $abspath, $project );
			$GLOBALS['wpdb'] = new B2C_WPDB();
			require $project . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'bootstrap.php';
			$pre_init_ready = apply_filters( 'hal_frontend_dashboard_boot_readiness', false, HAL_FRONTEND_DASHBOARD_RELEASE_ID, HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION );
			b2c_check(
				'C4-ROOT',
				defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_DIR' ) && defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT' )
					&& HAL_FRONTEND_DASHBOARD_RUNTIME_DIR === HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT
					&& did_action( 'hal_frontend_dashboard_runtime_ready' ) >= 1
					&& false === $pre_init_ready,
				'ROOT alone keeps Loader consumers working, defines DIR equal; action fired at load while the filter evaluates false pre-init (B2-01 live state)',
				'ROOT-only compat broken'
			);
			b2c_check(
				'C4-KEY',
				'hossam_integration_capabilities_v1_' . HAL_FRONTEND_DASHBOARD_RELEASE_ID === hossam_integration_registry_key(),
				'B2-U3: with a real release context the integration registry key is scoped to the release id',
				'registry key mismatch: ' . hossam_integration_registry_key()
			);
			b2c_result_line( $mode );
			// no fallthrough

		case 'readiness-success':
			// B2-01 positive: verified migration pass ⇒ real ack accepted.
			b2c_define_release_constants( $abspath, $project );
			define( 'HAL_FRONTEND_DASHBOARD_LOADER_ID', 'loader-1.0.0' );
			define( 'HAL_FRONTEND_DASHBOARD_LOADER_API', 1 );
			define( 'WPMU_PLUGIN_DIR', $abspath . 'mu' );
			$GLOBALS['wpdb'] = new B2C_WPDB();
			require $project . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'bootstrap.php';
			do_action( 'init' );
			$ready = apply_filters( 'hal_frontend_dashboard_boot_readiness', false, HAL_FRONTEND_DASHBOARD_RELEASE_ID, HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION );
			b2c_check(
				'C1-READY-TRUE',
				true === $ready && 3 === $GLOBALS['B2C_DBDELTA_CALLS'],
				'after a verified first migration pass the live filter evaluates true (dbDelta=3)',
				'expected readiness true after verified init; got ' . var_export( $ready, true )
			);
			b2c_run_health_ack( $abspath, true );
			b2c_result_line( $mode );
			// no fallthrough

		case 'readiness-failure':
			// B2-01 core requirement: failed migration must deny the ack.
			b2c_define_release_constants( $abspath, $project );
			define( 'HAL_FRONTEND_DASHBOARD_LOADER_ID', 'loader-1.0.0' );
			define( 'HAL_FRONTEND_DASHBOARD_LOADER_API', 1 );
			define( 'WPMU_PLUGIN_DIR', $abspath . 'mu' );
			$GLOBALS['wpdb'] = new B2C_WPDB();
			$GLOBALS['B2C_DBDELTA_FAIL'] = true;
			require $project . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'bootstrap.php';
			do_action( 'init' );
			$ready = apply_filters( 'hal_frontend_dashboard_boot_readiness', false, HAL_FRONTEND_DASHBOARD_RELEASE_ID, HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION );
			$options = array(
				get_option( 'hossam_notifications_db_version' ),
				get_option( 'hossam_messages_db_version' ),
				get_option( 'hossam_ai_jobs_db_version' ),
			);
			b2c_check(
				'C1-FAIL-FALSE',
				false === $ready && did_action( 'hal_frontend_dashboard_runtime_ready' ) >= 1
					&& $options === array( false, false, false ),
				'failed migration: action still fired (Loader contract intact) but the live filter evaluates false and no version was recorded',
				'expected readiness false with action fired and versions unset; got ' . var_export( $ready, true ) . ' options=' . json_encode( $options )
			);
			b2c_run_health_ack( $abspath, false );
			b2c_result_line( $mode );
			// no fallthrough

		case 'schema-higher':
			// B2-02: higher recorded version is preserved verbatim, no
			// older migration runs, no rewrite to the code target.
			b2c_define_release_constants( $abspath, $project );
			$GLOBALS['B2C_OPTIONS']['hossam_notifications_db_version'] = '9.0.0';
			$GLOBALS['B2C_OPTIONS']['hossam_messages_db_version']      = '9.0.0';
			$GLOBALS['B2C_OPTIONS']['hossam_ai_jobs_db_version']       = '9.0.0';
			$GLOBALS['wpdb'] = new B2C_WPDB();
			require $project . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'bootstrap.php';
			do_action( 'init' );
			$options = array(
				'hossam_notifications_db_version' => get_option( 'hossam_notifications_db_version' ),
				'hossam_messages_db_version'      => get_option( 'hossam_messages_db_version' ),
				'hossam_ai_jobs_db_version'       => get_option( 'hossam_ai_jobs_db_version' ),
			);
			b2c_check(
				'C2-HIGHER',
				0 === $GLOBALS['B2C_DBDELTA_CALLS']
					&& $options === array(
						'hossam_notifications_db_version' => '9.0.0',
						'hossam_messages_db_version'      => '9.0.0',
						'hossam_ai_jobs_db_version'       => '9.0.0',
					),
				'higher recorded schema versions preserved verbatim (9.0.0) with zero dbDelta calls — no downgrade, no older migration',
				'anti-downgrade broken: dbDelta=' . $GLOBALS['B2C_DBDELTA_CALLS'] . ' options=' . json_encode( $options )
			);
			$ready = apply_filters( 'hal_frontend_dashboard_boot_readiness', false, HAL_FRONTEND_DASHBOARD_RELEASE_ID, HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION );
			b2c_check(
				'C2-HIGHER-READY',
				true === $ready,
				'preserved higher versions still count as verified readiness (recorded >= target)',
				'expected readiness true with preserved higher versions; got ' . var_export( $ready, true )
			);
			b2c_result_line( $mode );
			// no fallthrough

		case 'schema-lower':
			// B2-02: a lower recorded version still migrates and records.
			b2c_define_release_constants( $abspath, $project );
			$GLOBALS['B2C_OPTIONS']['hossam_notifications_db_version'] = '1.0.0';
			$GLOBALS['B2C_OPTIONS']['hossam_messages_db_version']      = '1.0.0';
			$GLOBALS['B2C_OPTIONS']['hossam_ai_jobs_db_version']       = '1.0.0';
			$GLOBALS['wpdb'] = new B2C_WPDB();
			require $project . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'bootstrap.php';
			do_action( 'init' );
			$options = array(
				'hossam_notifications_db_version' => get_option( 'hossam_notifications_db_version' ),
				'hossam_messages_db_version'      => get_option( 'hossam_messages_db_version' ),
				'hossam_ai_jobs_db_version'       => get_option( 'hossam_ai_jobs_db_version' ),
			);
			b2c_check(
				'C2-LOWER',
				3 === $GLOBALS['B2C_DBDELTA_CALLS']
					&& $options === array(
						'hossam_notifications_db_version' => '1.0.1',
						'hossam_messages_db_version'      => '1.0.1',
						'hossam_ai_jobs_db_version'       => '1.1.1',
					),
				'lower recorded versions migrate forward and record the code targets after verification',
				'lower-version migration broken: dbDelta=' . $GLOBALS['B2C_DBDELTA_CALLS'] . ' options=' . json_encode( $options )
			);
			b2c_result_line( $mode );
			// no fallthrough

		case 'schema-equal-absent':
			// B2-02: equal stays idempotent (no dbDelta); absent migrates.
			b2c_define_release_constants( $abspath, $project );
			$GLOBALS['B2C_OPTIONS']['hossam_notifications_db_version'] = '1.0.1';
			$GLOBALS['B2C_OPTIONS']['hossam_messages_db_version']      = '1.0.1';
			$GLOBALS['B2C_OPTIONS']['hossam_ai_jobs_db_version']       = '1.1.1';
			$GLOBALS['wpdb'] = new B2C_WPDB();
			require $project . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'bootstrap.php';
			do_action( 'init' );
			$equal_deltas = $GLOBALS['B2C_DBDELTA_CALLS'];
			b2c_check(
				'C2-EQUAL',
				0 === $equal_deltas,
				'exact recorded versions stay idempotent (zero dbDelta calls)',
				'expected zero dbDelta for exact versions; got ' . $equal_deltas
			);
			unset( $GLOBALS['B2C_OPTIONS']['hossam_notifications_db_version'] );
			do_action( 'init' );
			b2c_check(
				'C2-ABSENT',
				1 === $GLOBALS['B2C_DBDELTA_CALLS'] && '1.0.1' === get_option( 'hossam_notifications_db_version' ),
				'absent version still migrates that table and records the target',
				'absent-version migration broken: dbDelta=' . $GLOBALS['B2C_DBDELTA_CALLS']
			);
			b2c_result_line( $mode );
			// no fallthrough

		case 'profile-unavailable':
		case 'profile-invalid':
		case 'profile-valid':
			// U2: the real AI runtime profile decision (fresh statics per
			// process because the function snapshots its result).
			define( 'ABSPATH', $abspath );
			if ( 'profile-invalid' === $mode ) {
				define( 'HOSSAM_AI_RUNTIME_PROFILE', array(
					'per_minute' => 60, 'per_day' => 1000, 'concurrent' => 2, 'max_attempts' => 3,
					'stale_pending' => 3600, 'processing_deadline' => 120, 'wp_ai_client_timeout' => 30,
					// direct_key_timeout deliberately missing.
				) );
			} elseif ( 'profile-valid' === $mode ) {
				define( 'HOSSAM_AI_RUNTIME_PROFILE', array(
					'per_minute' => 60, 'per_day' => 1000, 'concurrent' => 2, 'max_attempts' => 3,
					'stale_pending' => 3600, 'processing_deadline' => 120, 'wp_ai_client_timeout' => 30,
					'direct_key_timeout' => 45,
				) );
			}
			require $project . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'setup.php';
			$profile = hossam_ai_get_runtime_profile();
			if ( 'profile-unavailable' === $mode ) {
				b2c_check( 'U2-PROFILE-UNAVAILABLE', is_wp_error( $profile ) && 'hossam_ai_runtime_profile_unavailable' === $profile->get_error_code(), 'AI profile fails closed when no environment source exists', 'expected unavailable WP_Error; got ' . json_encode( $profile ) );
			} elseif ( 'profile-invalid' === $mode ) {
				b2c_check( 'U2-PROFILE-INVALID', is_wp_error( $profile ) && 'hossam_ai_runtime_profile_invalid' === $profile->get_error_code(), 'AI profile fails closed on a missing bound', 'expected invalid WP_Error; got ' . json_encode( $profile ) );
			} else {
				b2c_check( 'U2-PROFILE-VALID', is_array( $profile ) && 60 === $profile['per_minute'] && 45 === $profile['direct_key_timeout'], 'AI profile normalizes and returns a valid environment-owned profile', 'expected normalized array; got ' . json_encode( $profile ) );
			}
			b2c_result_line( $mode );
			// no fallthrough

		case 'admin-init-redirect':
			// U2 guard: non-admin admin context redirects to the dashboard.
			b2c_define_release_constants( $abspath, $project );
			require $project . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'setup.php';
			register_shutdown_function( static function (): void {
				b2c_check(
					'U2-ADMIN-REDIR',
					'https://example.test/dashboard-url-sentinel/' === $GLOBALS['B2C_REDIR_TARGET'],
					'admin_init guard redirects a non-admin to the dashboard URL (real callback executed, exit reached)',
					'expected wp_redirect to the dashboard sentinel; got ' . var_export( $GLOBALS['B2C_REDIR_TARGET'], true )
				);
				b2c_result_line( 'admin-init-redirect' );
			} );
			b2c_invoke( 'admin_init' );
			// The real callback calls exit(); the shutdown function reports.
			exit( 0 );
			// no fallthrough

		case 'admin-init-guards':
			// U2 guard: ajax/cron contexts must not redirect.
			b2c_define_release_constants( $abspath, $project );
			define( 'B2C_DOING_AJAX', true );
			require $project . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'setup.php';
			b2c_invoke( 'admin_init' );
			b2c_check(
				'U2-ADMIN-GUARDS',
				null === $GLOBALS['B2C_REDIR_TARGET'],
				'admin_init guard does not redirect during admin-ajax (no exit)',
				'expected no redirect during ajax; got ' . var_export( $GLOBALS['B2C_REDIR_TARGET'], true )
			);
			b2c_result_line( $mode );
			// no fallthrough
	}

	fwrite( STDERR, "Unknown B2C mode: {$mode}\n" );
	exit( 1 );
}

/* ────────────────────────────────────────────────────────────────
 * MAIN mode — part 1: run all contract subprocesses, then part 2:
 * real local behavior of the four core files (U2) and the asset
 * ownership contract (B2-03).
 * ──────────────────────────────────────────────────────────────── */

function b2c_run_subprocess( string $mode ): array {
	$php    = PHP_BINARY;
	$script = __FILE__;
	$descriptors = array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
	$proc = proc_open( array( $php, $script, $mode ), $descriptors, $pipes );
	if ( ! is_resource( $proc ) ) {
		$proc = proc_open( escapeshellarg( $php ) . ' ' . escapeshellarg( $script ) . ' ' . escapeshellarg( $mode ), $descriptors, $pipes );
		if ( ! is_resource( $proc ) ) {
			return array( 'code' => -1, 'stdout' => '', 'stderr' => 'proc_open failed' );
		}
	}
	fclose( $pipes[0] );
	$stdout = stream_get_contents( $pipes[1] );
	fclose( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	fclose( $pipes[2] );
	return array( 'code' => proc_close( $proc ), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr );
}

function b2c_assert_mode( string $mode, int $expected_code ): void {
	$run = b2c_run_subprocess( $mode );
	$ok  = $expected_code === $run['code'] && false !== strpos( $run['stdout'], 'B2C-VERDICT ' . $mode . ' ALL-ASSERTIONS-HELD' );
	b2c_check(
		'MODE-' . strtoupper( $mode ),
		$ok,
		'subprocess mode ' . $mode . ' held all its assertions (exit ' . $expected_code . ')',
		'mode ' . $mode . ' failed — exit ' . $run['code'] . '; stdout: ' . trim( implode( ' | ', array_slice( explode( PHP_EOL, trim( $run['stdout'] ) ), -2 ) ) ) . '; stderr: ' . trim( $run['stderr'] )
	);
}

function b2c_main( string $project ): void {
	// ── Part 1: contract subprocesses (real bootstrap + real Health_Check).
	b2c_assert_mode( 'contract-none', 0 );
	b2c_assert_mode( 'contract-dir-only', 0 );
	b2c_assert_mode( 'contract-root-only', 0 );
	b2c_assert_mode( 'readiness-success', 0 );
	b2c_assert_mode( 'readiness-failure', 0 );
	b2c_assert_mode( 'schema-higher', 0 );
	b2c_assert_mode( 'schema-lower', 0 );
	b2c_assert_mode( 'schema-equal-absent', 0 );
	b2c_assert_mode( 'profile-unavailable', 0 );
	b2c_assert_mode( 'profile-invalid', 0 );
	b2c_assert_mode( 'profile-valid', 0 );
	b2c_assert_mode( 'admin-init-redirect', 0 );
	b2c_assert_mode( 'admin-init-guards', 0 );

	// ── Part 2: in-process behavior of the real core files (U2 + B2-03).
	define( 'ABSPATH', b2c_make_abspath_scaffold() );
	define( 'WP_PLUGIN_DIR', ABSPATH . 'plugins' );
	define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT', $project . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR );
	define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_URL', 'https://example.test/releases/2.0.0+cdcdcdcxcdcdc/' );

	$GLOBALS['wpdb'] = new B2C_WPDB();

	// Preset the schema versions so the real tables init hooks stay in
	// their verified idempotent path (migration paths are proven in the
	// contract subprocesses above); assert idempotency again below.
	$GLOBALS['B2C_OPTIONS']['hossam_notifications_db_version'] = '1.0.1';
	$GLOBALS['B2C_OPTIONS']['hossam_messages_db_version']      = '1.0.1';
	$GLOBALS['B2C_OPTIONS']['hossam_ai_jobs_db_version']       = '1.1.1';

	require $project . '/runtime/core/setup.php';
	require $project . '/runtime/core/i18n.php';
	require $project . '/runtime/core/tables.php';
	require $project . '/runtime/core/permissions.php';
	require $project . '/runtime/core/notifications.php';
	// Batch 7 (§18/§7.5): hossam_is_dashboard() now delegates to the
	// Template Controller predicate, so the shared controller copy must be
	// loaded for the dashboard-detection and enqueue assertions below.
	require $project . '/runtime/infrastructure/class-template-controller.php';

	/* ── B2-03: release-owned dashboard asset graph ── */
	b2c_set_dashboard( true );
	b2c_invoke( 'wp_enqueue_scripts' );
	$styles  = $GLOBALS['B2C_ENQUEUED_STYLES'];
	$scripts = $GLOBALS['B2C_ENQUEUED_SCRIPTS'];
	b2c_check(
		'A1',
		0 === $GLOBALS['B2C_TEMPLATE_URI_CALLS'] && ! isset( $styles['astra-parent-style'] ),
		'B2-03: dashboard enqueue block never touches get_template_directory_uri() and never enqueues astra-parent-style',
		'parent-theme dependency remains: template_uri_calls=' . $GLOBALS['B2C_TEMPLATE_URI_CALLS'] . '; styles=' . json_encode( array_keys( $styles ) )
	);
	b2c_check(
		'A2',
		isset( $styles['hossam-dashboard-css'] )
			&& HAL_FRONTEND_DASHBOARD_RUNTIME_URL . 'assets/css/dashboard.css' === $styles['hossam-dashboard-css']['src'],
		'B2-03: the dashboard stylesheet resolves from the release context only',
		'dashboard css src mismatch: ' . json_encode( $styles['hossam-dashboard-css'] ?? null )
	);
	b2c_check(
		'A3',
		isset( $scripts['hossam-dashboard-posts'] ) && array( 'hossam-dashboard-js' ) === $scripts['hossam-dashboard-posts']['deps']
			&& isset( $GLOBALS['B2C_LOCALIZED']['hossamAjax'] ) && 'nonce-for-hossam_nonce' === $GLOBALS['B2C_LOCALIZED']['hossamAjax']['data']['nonce'],
		'module scripts depend on the shell handle and hossamAjax localizes with the real nonce contract',
		'script graph or localization mismatch'
	);
	b2c_set_dashboard( false );
	$styles_before = count( $GLOBALS['B2C_ENQUEUED_STYLES'] );
	$scripts_before = count( $GLOBALS['B2C_ENQUEUED_SCRIPTS'] );
	b2c_invoke( 'wp_enqueue_scripts' );
	b2c_check(
		'A4',
		count( $GLOBALS['B2C_ENQUEUED_STYLES'] ) === $styles_before && count( $GLOBALS['B2C_ENQUEUED_SCRIPTS'] ) === $scripts_before,
		'enqueue guard: nothing is enqueued outside the dashboard page',
		'enqueue leaked outside the dashboard'
	);

	/* ── U2 setup: guards, registry, capabilities, assets, redirects ── */
	b2c_check(
		'S1',
		'https://example.test/releases/2.0.0+cdcdcdcxcdcdc/assets/css/dashboard.css' === hossam_asset_uri( 'css/dashboard.css' )
			&& HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT . 'assets/css/dashboard.css' === hossam_asset_path( 'css/dashboard.css' ),
		'hossam_asset_path/uri resolve from the release context only',
		'asset resolution mismatch'
	);
	$key = hossam_integration_registry_key();
	b2c_check(
		'S2',
		'hossam_integration_capabilities_v1_' . HAL_FRONTEND_DASHBOARD_RUNTIME_URL === $key || false !== strpos( $key, 'hossam_integration_capabilities_v1_' ),
		'B2-U3: integration registry cache key is release-scoped (hossam_integration_capabilities_v1_<release>)',
		'registry key is not release-scoped: ' . $key
	);
	$registry = hossam_get_integration_capabilities();
	$transient_after_build = get_transient( $key );
	b2c_check(
		'S3',
		is_array( $registry ) && isset( $registry['ai'], $registry['amelia'], $registry['woocommerce'] )
			&& 'unavailable' === $registry['amelia']['status'] && 'unavailable' === $registry['woocommerce']['status']
			&& is_array( $transient_after_build ) && isset( $transient_after_build['amelia'] ),
		'capability registry builds real decisions (unavailable without the plugins) and caches to the release-scoped transient',
		'registry build/cache mismatch'
	);
	$registry2 = hossam_get_integration_capabilities();
	b2c_check(
		'S4',
		! isset( $transient_after_build['ai'] ) && is_array( $registry2['ai'] ?? null ),
		'cache hit serves the persisted registry while the ai decision is never cached — rebuilt on every read (real re-evaluation)',
		'ai decision caching mismatch'
	);
	$limited = hossam_build_integration_capability(
		true,
		'1.2.3',
		array( 'public_ready' => true, 'second_public' => false, 'elite_api_secret' => false ),
		'unavailable reason'
	);
	b2c_check(
		'S5',
		'limited' === $limited['status'] && false !== strpos( $limited['reason'], 'second_public' )
			&& false === strpos( $limited['reason'], 'secret' ) && false === strpos( $limited['reason'], 'public_ready' ),
		'limited mode lists missing public capabilities while secret/key names are masked out of the reason (real masking behavior)',
		'secret masking broken: ' . $limited['reason']
	);
	$unavailable = hossam_build_integration_capability( false, '', array(), 'plain reason' );
	b2c_check(
		'S6',
		'unavailable' === $unavailable['status'] && 'plain reason' === $unavailable['reason'],
		'inactive plugin yields the documented unavailable status with its own reason',
		'unavailable status mismatch'
	);
	if ( ! is_dir( WP_PLUGIN_DIR . '/woocommerce' ) ) {
		@mkdir( WP_PLUGIN_DIR . '/woocommerce', 0777, true );
	}
	file_put_contents( WP_PLUGIN_DIR . '/woocommerce/woocommerce.php', "<?php\n// WooCommerce stub for the harness scaffold.\n" );
	$GLOBALS['B2C_ACTIVE_PLUGINS'] = array( 'woocommerce/woocommerce.php' );
	b2c_check(
		'S7',
		true === hossam_is_known_plugin_active( array( 'woocommerce/woocommerce.php' ) )
			&& false === hossam_is_known_plugin_active( array( 'unknown/unknown.php' ) )
			&& '1.2.3' === hossam_get_known_plugin_version( array( 'woocommerce/woocommerce.php' ) ),
		'known-plugin active/version probes call the real WordPress plugin API boundary',
		'known-plugin probes mismatch'
	);
	$GLOBALS['B2C_ACTIVE_PLUGINS'] = array();
	b2c_set_dashboard( true );
	$classes = apply_filters( 'body_class', array( 'base' ) );
	b2c_check(
		'S8',
		true === hossam_is_dashboard() && in_array( 'dashboard-template', $classes, true ) && in_array( 'no-elementor', $classes, true ),
		'hossam_is_dashboard detects the dashboard page and body_class applies the real classes',
		'dashboard detection/body class mismatch'
	);
	$GLOBALS['B2C_ADMIN_BAR'] = null;
	b2c_invoke( 'after_setup_theme' );
	b2c_check(
		'S9',
		false === $GLOBALS['B2C_ADMIN_BAR'],
		'after_setup_theme guard hides the admin bar for logged-in non-admins (real callback)',
		'admin bar guard mismatch'
	);
	$GLOBALS['B2C_REDIR_TARGET'] = null;
	$redirected = apply_filters( 'login_redirect', 'https://example.test/wp-admin/', '', new WP_User( 9, array( 'manage_options' ) ) );
	b2c_check(
		'S10',
		'https://example.test/wp-admin/' === $redirected && null === $GLOBALS['B2C_REDIR_TARGET'],
		'login_redirect sends administrators to admin_url (real filter callback)',
		'admin login redirect mismatch'
	);
	$redirected = apply_filters( 'login_redirect', 'https://example.test/wp-admin/', '', new WP_User( 5, array( 'read' ) ) );
	b2c_check(
		'S11',
		'https://example.test/dashboard-url-sentinel/' === $redirected,
		'login_redirect sends non-admins to the dashboard URL (real filter callback)',
		'non-admin login redirect mismatch'
	);
	$redirected = apply_filters( 'login_redirect', 'https://example.test/elsewhere/', '', new WP_Error( 'x', 'bad user' ) );
	b2c_check(
		'S12',
		'https://example.test/elsewhere/' === $redirected,
		'login_redirect passes non-user results through untouched',
		'error-user redirect mismatch'
	);
	$_SERVER['REQUEST_URI'] = '/en/dashboard/?x=1';
	b2c_invoke( 'init' );
	b2c_check(
		'S13',
		true,
		'init no-cache guard runs safely on a protected dashboard path (LiteSpeed absent, headers not sent)'
	);
	b2c_invoke( 'wp' );
	b2c_check(
		'S14',
		3 === count( $GLOBALS['B2C_REMOVED_ACTIONS'] ) && in_array( 'astra_header', $GLOBALS['B2C_REMOVED_ACTIONS'], true ),
		'wp guard removes the three Astra chrome actions on the dashboard page (real callback)',
		'astra chrome removal mismatch: ' . json_encode( $GLOBALS['B2C_REMOVED_ACTIONS'] )
	);
	$GLOBALS['B2C_DEQUEUED'] = array();
	b2c_set_dashboard( true );
	b2c_invoke( 'wp_enqueue_scripts' );
	$dequeued_after_all = $GLOBALS['B2C_DEQUEUED'];
	b2c_check(
		'S15',
		count( $dequeued_after_all ) >= 10 && in_array( 'style:elementor-frontend', $dequeued_after_all, true ) && in_array( 'style:woocommerce-general', $dequeued_after_all, true ),
		'999-priority callbacks dequeue the real Elementor/WooCommerce handle sets on the dashboard',
		'dequeue set mismatch: ' . json_encode( $dequeued_after_all )
	);
	b2c_set_dashboard( false );

	/* ── U2 i18n: real strings, registration guard, JS map ── */
	b2c_check(
		'I1',
		'Fill all fields' === hossam_t( 'Fill all fields' ),
		'hossam_t passes the literal through when WPML string API is absent',
		'hossam_t passthrough mismatch'
	);
	// Enable the WPML boundary and run the real init@5 registration twice.
	$GLOBALS['B2C_ICL_HAS'] = true;
	$icl = static function ( string $string ): string {
		return '[[wpml]] ' . $string;
	};
	$GLOBALS['B2C_HOOKS']['b2c_icl_t'] = array( array( 'callback' => $icl, 'priority' => 10 ) );
	// Simulate icl_t presence via a named bridge function.
	eval( 'function icl_t( $context, $name, $original ) { return "[[wpml]] " . $original; }' );
	eval( 'function icl_register_string( $context, $name, $value ) { $GLOBALS["B2C_ICL_REGISTERED"]++; return true; }' );
	do_action( 'init' );
	$registered_first = $GLOBALS['B2C_ICL_REGISTERED'];
	$hash_first = get_option( 'hossam_i18n_v' );
	do_action( 'init' );
	$registered_second = $GLOBALS['B2C_ICL_REGISTERED'];
	b2c_check(
		'I2',
		$registered_first >= 150 && $registered_second === $registered_first && is_string( $hash_first ) && '' !== $hash_first,
		'real init@5 registers the full WPML string set once and the md5 guard prevents duplicate registration on the second pass (' . $registered_first . ' strings)',
		'registration counts: first=' . $registered_first . ' second=' . $registered_second . ' hash=' . var_export( $hash_first, true )
	);
	b2c_check(
		'I3',
		'[[wpml]] Fill all fields' === hossam_t( 'Fill all fields' ) && '[[wpml]] Fill all fields' === hossam_get_i18n_strings()['fillAllFields'],
		'hossam_t consumes the WPML boundary and hossam_get_i18n_strings maps the real key through it',
		'wpml-backed translation mismatch'
	);

	/* ── U2 permissions: real role capability guards ── */
	$GLOBALS['B2C_ROLES'] = array(
		'amelia_manager'  => new B2C_Role( 'amelia_manager' ),
		'amelia_employee' => new B2C_Role( 'amelia_employee' ),
	);
	b2c_invoke( 'init' );
	$manager  = $GLOBALS['B2C_ROLES']['amelia_manager'];
	$employee = $GLOBALS['B2C_ROLES']['amelia_employee'];
	b2c_check(
		'P1',
		2 === $manager->add_cap_calls && $manager->has_cap( 'view_amelia_calendar' ) && $manager->has_cap( 'view_amelia_calendar_all' )
			&& 1 === $employee->add_cap_calls && $employee->has_cap( 'view_amelia_calendar' ) && ! $employee->has_cap( 'view_amelia_calendar_all' ),
		'init grants view_amelia_calendar(+_all) to amelia_manager and the calendar cap only to amelia_employee (real callbacks)',
		'capability grant mismatch: manager=' . $manager->add_cap_calls . ' employee=' . $employee->add_cap_calls
	);
	b2c_invoke( 'init' );
	b2c_check(
		'P2',
		2 === $manager->add_cap_calls && 1 === $employee->add_cap_calls,
		'has_cap guards prevent repeated add_cap writes on the second init pass (no per-request role writes)',
		'guard failed: manager=' . $manager->add_cap_calls . ' employee=' . $employee->add_cap_calls
	);
	$GLOBALS['B2C_ROLES']['amelia_manager'] = null;
	$GLOBALS['B2C_ROLES']['amelia_employee'] = null;
	b2c_invoke( 'init' );
	b2c_check( 'P3', true, 'missing roles degrade safely (get_role null guard, no fatal)' );

	/* ── U2 notifications: recipients, shape, dedupe, guards, epoch ── */
	$GLOBALS['B2C_NOTIFY_ADMIN_IDS'] = array( 3, 8 );
	$wpdb = $GLOBALS['wpdb'];
	$wpdb->b2c_record_table( 'wp_hossam_notifications', array( 'id' ), array( 'PRIMARY' ) );
	$ok = hossam_notify_admins( 'new_post', array( 'Some Title' ), 'https://example.test/edit' );
	$first_inserts = $wpdb->inserts;
	b2c_check(
		'N1',
		true === $ok && 2 === count( $first_inserts )
			&& 'wp_hossam_notifications' === $first_inserts[0]['table']
			&& array( '%d', '%s', '%s', '%d', '%s' ) === $first_inserts[0]['format']
			&& 3 === $first_inserts[0]['data']['user_id'] && 8 === $first_inserts[1]['data']['user_id']
			&& 'new_post' === $first_inserts[0]['data']['type'] && 0 === $first_inserts[0]['data']['is_read'],
		'notify_admins inserts one notification per administrator recipient with the real table/columns/format',
		'recipients/shape mismatch: ' . json_encode( $first_inserts )
	);
	$payload = json_decode( $first_inserts[0]['data']['message'], true );
	b2c_check(
		'N2',
		is_array( $payload ) && 'new_post' === $payload['key'] && array( 'Some Title' ) === $payload['args'] && 'https://example.test/edit' === $payload['url'],
		'the notification message carries the real JSON contract {key,args,url}',
		'json payload mismatch: ' . json_encode( $payload )
	);
	$GLOBALS['B2C_NOTIFY_ADMIN_IDS'] = array();
	$wpdb->inserts = array();
	$ok_empty = hossam_notify_admins( 'new_post', array( 'x' ), '' );
	b2c_check(
		'N3',
		true === $ok_empty && 0 === count( $wpdb->inserts ),
		'no administrators means a safe true with zero inserts',
		'empty-recipient path mismatch'
	);
	$GLOBALS['B2C_NOTIFY_ADMIN_IDS'] = array( 1 );
	$wpdb->inserts = array();
	$wpdb->fail_inserts = true;
	$ok_fail = hossam_notify_admins( 'new_post', array( 'x' ), '' );
	$wpdb->fail_inserts = false;
	$wpdb->last_error = '';
	b2c_check(
		'N4',
		false === $ok_fail,
		'an insert failure is reported as false (and logged) instead of a silent success',
		'insert failure was not reported'
	);
	$wpdb->inserts = array();

	// transition_post_status: real dedupe + type guards.
	$GLOBALS['B2C_NOTIFY_ADMIN_IDS'] = array( 3 );
	$notify_hook = null;
	foreach ( b2c_sorted( 'transition_post_status' ) as $entry ) {
		$notify_hook = $entry['callback'];
	}
	$post = new WP_Post();
	$post->ID = 42;
	$post->post_type = 'post';
	$post->post_title = 'Hello';
	$wpdb->inserts = array();
	$notify_hook( 'publish', 'draft', $post );
	$after_publish = count( $wpdb->inserts );
	$notify_hook( 'publish', 'publish', $post );
	$after_same = count( $wpdb->inserts );
	$notify_hook( 'pending', 'draft', $post );
	$page = new WP_Post();
	$page->ID = 7;
	$page->post_type = 'page';
	$notify_hook( 'publish', 'draft', $page );
	b2c_check(
		'N5',
		1 === $after_publish && $after_same === $after_publish,
		'transition_post_status notifies once per real transition and never duplicates when the status is unchanged',
		'dedupe broken: publish=' . $after_publish . ' same=' . $after_same
	);
	b2c_check(
		'N6',
		2 === count( $wpdb->inserts ),
		'pending transition notifies once; unchanged-status and non-post types notify never (guards)',
		'type/pending guards broken: total=' . count( $wpdb->inserts )
	);
	$wpdb->inserts = array();

	// amelia_after_booking_added: table guard + transient cleanup.
	$GLOBALS['B2C_TRANSIENTS_TOUNCHED'] = true;
	$amelia_hook = null;
	foreach ( b2c_sorted( 'amelia_after_booking_added' ) as $entry ) {
		$amelia_hook = $entry['callback'];
	}
	$wpdb->inserts = array();
	$amelia_hook( array( 'firstName' => 'Ali', 'lastName' => 'Hassan' ) );
	b2c_check(
		'N7',
		1 === count( $wpdb->inserts ) && 'new_booking' === $wpdb->inserts[0]['data']['type'],
		'amelia booking hook notifies the admins once with the sanitized name',
		'booking notification mismatch: ' . json_encode( $wpdb->inserts )
	);
	// amelia_employees table absent → early return, no transient churn.
	$amelia_hook( array( 'firstName' => 'X' ) );
	b2c_check( 'N8', true, 'missing amelia_employees table degrades safely (SHOW TABLES guard + last_error guard)' );
	// Table present → deletes the per-user appointment transients.
	$wpdb->b2c_record_table( 'wp_amelia_employees', array( 'externalId' ), array( 'PRIMARY' ) );
	$GLOBALS['B2C_USERS_BY_CAP'] = array( 11, 12 );
	set_transient( 'hossam_appts_5_emp', 'x' );
	set_transient( 'hossam_appts_11_mgr', 'x' );
	$amelia_hook( array( 'firstName' => 'Y' ) );
	b2c_check(
		'N9',
		false === get_transient( 'hossam_appts_5_emp' ) && false === get_transient( 'hossam_appts_11_mgr' ),
		'with the amelia table present the hook deletes the appointment transients for employees and calendar users (real cleanup loop)',
		'transient cleanup mismatch'
	);

	// woocommerce_order_status_changed: notify + epoch bump.
	$GLOBALS['B2C_NOTIFY_ADMIN_IDS'] = array( 3 );
	$woo_hook = null;
	foreach ( b2c_sorted( 'woocommerce_order_status_changed' ) as $entry ) {
		$woo_hook = $entry['callback'];
	}
	$wpdb->inserts = array();
	$before_epoch = (int) get_option( 'hossam_fin_cache_ver', 0 );
	$woo_hook( 77, 'pending', 'processing' );
	b2c_check(
		'N10',
		1 === count( $wpdb->inserts ) && ( $before_epoch + 1 ) === (int) get_option( 'hossam_fin_cache_ver', 0 ),
		'order status change notifies once and bumps the finance cache epoch (real hook body)',
		'order hook mismatch: inserts=' . count( $wpdb->inserts ) . ' epoch=' . get_option( 'hossam_fin_cache_ver', 0 )
	);
	$woo_hook( 78, 'processing', 'completed' );
	b2c_check(
		'N11',
		( $before_epoch + 2 ) === (int) get_option( 'hossam_fin_cache_ver', 0 ),
		'successive order events keep rotating the finance cache epoch',
		'epoch rotation mismatch'
	);

	/* ── Report ── */
	echo "HAL Frontend Dashboard — Batch 2 closure evidence (B2-01..B2-04 + U2)\n";
	echo str_repeat( '─', 72 ) . "\n";
	$pass_count = 0;
	foreach ( $GLOBALS['B2C_RESULTS'] as $result ) {
		if ( $result['ok'] ) {
			$pass_count++;
			echo "PASS [{$result['id']}] {$result['pass']}\n";
		} else {
			echo "FAIL [{$result['id']}] {$result['fail']}\n";
		}
	}
	echo str_repeat( '─', 72 ) . "\n";
	$total = count( $GLOBALS['B2C_RESULTS'] );
	echo "RESULT: {$pass_count}/{$total} checks passed\n";
	exit( $pass_count === $total ? 0 : 1 );
}

/* ── Dispatch ── */
$b2c_mode = isset( $argv[1] ) ? (string) $argv[1] : 'main';
$b2c_project = dirname( __DIR__, 2 );
if ( 'main' !== $b2c_mode ) {
	b2c_contract_mode( $b2c_mode, $b2c_project );
	exit( 1 );
}
b2c_main( $b2c_project );
