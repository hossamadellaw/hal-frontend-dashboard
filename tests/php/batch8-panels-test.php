<?php
/**
 * HAL Frontend Dashboard — Batch 8 closure harness: remaining dashboard panels.
 *
 * Local, isolated harness: no live WordPress, no network, no database.
 * Loads the REAL runtime/bootstrap.php (core + batch-3 backend + 8
 * adapters + 8 ajax + the batch-7 infrastructure Template Controller)
 * and RENDERS the REAL runtime/templates/dashboard/{seo,translations,
 * inbox,finance,store,admin,members}.php parts via the REAL
 * HAL_Frontend_Dashboard_Template_Controller::render_part() — only
 * WordPress itself is stubbed. No project logic is reimplemented here.
 *
 * Coverage (architecture §19 closure gate + §15.20 documented owner
 * decision B3-08 option أ — per-panel internal gates):
 *
 *   SHIP       — all twelve documented PANEL_PARTS files exist on disk;
 *                PANEL_PARTS allowlist unchanged (§18+§19).
 *   BYTE-DIFF  — each migrated body after its ABSPATH guard matches the
 *                batch-8 source bytes (inventory snapshot 5CD55D5D…)
 *                exactly, preceded only by the known header + guard +
 *                the single documented delta, with nothing after the body.
 *   STATIC     — no get_template_part()/child-theme helpers and no write
 *                operations inside the seven templates (§19 inbox
 *                contract, §8.3 parts perform no writes); the owner-gate
 *                keys are exactly the documented ones per panel and no
 *                other feature key is consumed (admin: none — its
 *                manage_options guard comes from the source itself).
 *   ARGS-MIRROR — the shell's literal $template_args key list in
 *                runtime/templates/dashboard.php mirrors b8_full_args()
 *                exactly (same keys, same order, token-level) — the
 *                arg-contract regression guard (batch-8 V-1).
 *   B3-08      — owner-disabled features produce zero output from their
 *                panel (internal early return) even for an admin; enabled
 *                features render each panel per its source capability
 *                conditions (the toggle is an ADDITIONAL layer: a
 *                capability-less user still sees nothing — including the
 *                members double guard current_user_can('manage_options')
 *                required by §19); shell booleans are not trusted.
 *
 * Usage:  php tests/php/batch8-panels-test.php     (single mode: main)
 *         exit 0 = all assertions pass (B8-VERDICT main ALL-ASSERTIONS-HELD).
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

if ( PHP_SAPI !== 'cli' ) {
	echo "This harness must run under CLI PHP.\n";
	exit( 1 );
}

define( 'HAL_TEST_EXT_DIR', (string) ( getenv( 'HAL_PHP_EXT_DIR' ) ?: ini_get( 'extension_dir' ) ) );
$GLOBALS['B8_PROJECT'] = dirname( __DIR__, 2 );

/* ════════════════════════════════════════════════════════════════
 * WordPress boundary stubs (WordPress only — never project logic)
 * ════════════════════════════════════════════════════════════════ */

$GLOBALS['B8_HOOKS']        = array();
$GLOBALS['B8_ACTIONS']      = array();
$GLOBALS['B8_OPTIONS']      = array();
$GLOBALS['B8_TRANSIENTS']   = array();
$GLOBALS['B8_CAPS']         = array();
$GLOBALS['B8_LOGGED_IN']    = true;
$GLOBALS['B8_POSTS']        = array();
$GLOBALS['B8_SHORTCODES']   = array();
$GLOBALS['B8_SHORTCODE_OUT'] = 'B8-SHORTCODE-OUTPUT';
$GLOBALS['B8_DB_VAR']       = null;
$GLOBALS['B8_DB_RESULTS']   = array();
$GLOBALS['B8_DB_FAIL']      = false;

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! class_exists( 'B8_Wpdb' ) ) {
	final class B8_Wpdb {
		public string $prefix      = 'wp_';
		public string $posts       = 'wp_posts';
		public string $last_error  = '';
		public function prepare( string $query, ...$args ): string {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			$index = 0;
			return (string) preg_replace_callback( '/%[sd]/', function ( array $matches ) use ( &$index, $args ): string {
				$arg = $args[ $index ] ?? '';
				$index++;
				if ( '%d' === $matches[0] ) {
					return (string) (int) $arg;
				}
				return "'" . addcslashes( (string) $arg, "'" ) . "'";
			}, $query );
		}
		public function get_results( $query, $output = null ): array {
			if ( $GLOBALS['B8_DB_FAIL'] ) {
				$this->last_error = 'B8 injected db failure';
				return array();
			}
			return (array) $GLOBALS['B8_DB_RESULTS'];
		}
		public function get_var( $query = null ) {
			if ( $GLOBALS['B8_DB_FAIL'] ) {
				$this->last_error = 'B8 injected db failure';
				return null;
			}
			return $GLOBALS['B8_DB_VAR'];
		}
		public function esc_like( string $text ): string {
			return addcslashes( $text, '_%\\' );
		}
		public function query( $query ) {
			return 1;
		}
	}
}

if ( ! class_exists( 'B8_WpQuery' ) ) {
	class B8_WpQuery {
		public int $found_posts   = 0;
		public int $post_count    = 0;
		public int $max_num_pages = 0;
		public array $posts       = array();
		public array $query_args  = array();
		public function __construct( $args = array() ) {
			$this->query_args = (array) $args;
		}
		public function have_posts(): bool {
			return false;
		}
		public function the_post(): void {}
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	/* WordPress boundary stub: templates construct WP_Query directly. */
	class WP_Query extends B8_WpQuery {
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	#[AllowDynamicProperties]
	class WP_Post {
		public int $ID = 0;
		public string $post_type = '';
		public string $post_name = '';
		public function __construct( array $fields = array() ) {
			foreach ( $fields as $key => $value ) {
				$this->{$key} = $value;
			}
		}
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook_name, $callback = '', int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['B8_HOOKS'][ $hook_name ][] = array( 'type' => 'action', 'callback' => $callback, 'priority' => $priority );
		return true;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook_name, $callback = '', int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['B8_HOOKS'][ $hook_name ][] = array( 'type' => 'filter', 'callback' => $callback, 'priority' => $priority );
		return true;
	}
}
if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( string $hook_name, $callback = false ) {
		$entries = $GLOBALS['B8_HOOKS'][ $hook_name ] ?? array();
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
		$GLOBALS['B8_ACTIONS'][ $hook_name ] = ( $GLOBALS['B8_ACTIONS'][ $hook_name ] ?? 0 ) + 1;
		foreach ( $GLOBALS['B8_HOOKS'][ $hook_name ] ?? array() as $entry ) {
			call_user_func_array( $entry['callback'], $args );
		}
	}
}
if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook_name ): int {
		return $GLOBALS['B8_ACTIONS'][ $hook_name ] ?? 0;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook_name, $value, ...$args ) {
		foreach ( $GLOBALS['B8_HOOKS'][ $hook_name ] ?? array() as $entry ) {
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
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ): string {
		$url = trim( (string) $url );
		return preg_match( '#^(https?:)?//#i', $url ) ? $url : '';
	}
}
if ( ! function_exists( 'esc_js' ) ) {
	function esc_js( $text ): string {
		return addcslashes( (string) $text, "\\'\"\n\r" );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0 ) {
		return json_encode( $value, $flags );
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public string $code = '';
		public string $message = '';
		public function __construct( $code = '', $message = '' ) {
			$this->code    = (string) $code;
			$this->message = (string) $message;
		}
		public function get_error_code() {
			return $this->code;
		}
		public function get_error_message() {
			return $this->message;
		}
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability, ...$object_args ): bool {
		$key = $capability . ( isset( $object_args[0] ) ? '_' . (int) $object_args[0] : '' );
		if ( array_key_exists( $key, $GLOBALS['B8_CAPS'] ) ) {
			return (bool) $GLOBALS['B8_CAPS'][ $key ];
		}
		if ( array_key_exists( $capability, $GLOBALS['B8_CAPS'] ) ) {
			return (bool) $GLOBALS['B8_CAPS'][ $capability ];
		}
		return false;
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return 7;
	}
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		return (bool) $GLOBALS['B8_LOGGED_IN'];
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default_value = false ) {
		return array_key_exists( $option, $GLOBALS['B8_OPTIONS'] ) ? $GLOBALS['B8_OPTIONS'][ $option ] : $default_value;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, $value, $autoload = null ): bool {
		$GLOBALS['B8_OPTIONS'][ $option ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ) {
		return $GLOBALS['B8_TRANSIENTS'][ $key ] ?? false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, $value, int $ttl = 0 ): bool {
		$GLOBALS['B8_TRANSIENTS'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_the_ID' ) ) {
	function get_the_ID(): int {
		return 0;
	}
}
if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $post = null ): string {
		return '';
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key = '', bool $single = false ) {
		return '' === $key ? array() : '';
	}
}
if ( ! function_exists( 'wp_reset_postdata' ) ) {
	function wp_reset_postdata(): void {}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( ...$args ): string {
		$keys = array_keys( $args );
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$query = http_build_query( $args[0] );
			return 'https://site.test/dashboard/?' . $query;
		}
		return 'https://site.test/?' . http_build_query( array( end( $keys ) => end( $args ) ) );
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		return 'https://site.test' . $path;
	}
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'https://site.test/wp-admin/' . $path;
	}
}
if ( ! function_exists( 'wp_logout_url' ) ) {
	function wp_logout_url( string $redirect_to = '' ): string {
		return 'https://site.test/wp-login.php?action=logout';
	}
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( string $action ): string {
		return 'b8-nonce-' . $action;
	}
}
	if ( ! function_exists( 'is_plugin_active' ) ) {
		function is_plugin_active( string $plugin_file ): bool {
			return false;
		}
	}
	if ( ! function_exists( 'is_multisite' ) ) {
		function is_multisite(): bool {
			return false;
		}
	}
	if ( ! function_exists( 'shortcode_exists' ) ) {
	function shortcode_exists( string $tag ): bool {
		return in_array( $tag, $GLOBALS['B8_SHORTCODES'], true );
	}
}
if ( ! function_exists( 'do_shortcode' ) ) {
	function do_shortcode( string $content ): string {
		return $GLOBALS['B8_SHORTCODE_OUT'];
	}
}

/* ────────────────────────────────────────────────────────────────
 * Harness bookkeeping
 * ──────────────────────────────────────────────────────────────── */

function b8_check( string $id, bool $condition, string $pass, string $fail = '' ): void {
	$GLOBALS['B8_RESULTS'][] = array( 'id' => $id, 'ok' => $condition, 'pass' => $pass, 'fail' => $fail );
}

function b8_result_line( string $mode ): void {
	$fail = 0;
	foreach ( $GLOBALS['B8_RESULTS'] as $result ) {
		if ( ! $result['ok'] ) {
			$fail++;
			echo "FAIL [{$result['id']}] {$result['fail']}\n";
		}
	}
	echo 'B8-VERDICT ' . $mode . ( 0 === $fail ? ' ALL-ASSERTIONS-HELD' : ' ASSERTIONS-FAILED-' . $fail ) . "\n";
	exit( 0 === $fail ? 0 : 1 );
}

/* ════════════════════════════════════════════════════════════════
 * Release context + runtime loading + fixtures
 * ════════════════════════════════════════════════════════════════ */

function b8_define_release_context(): void {
	if ( ! defined( 'ABSPATH' ) ) {
		$abspath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'b8-' . getmypid() . DIRECTORY_SEPARATOR;
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
		define( 'DB_HOST', 'b8-db-host' );
		define( 'DB_NAME', 'b8-db-name' );
	}
	if ( ! defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT' ) ) {
		define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT', $GLOBALS['B8_PROJECT'] . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR );
		define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_DIR', $GLOBALS['B8_PROJECT'] . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR );
		define( 'HAL_FRONTEND_DASHBOARD_RELEASE_ID', '7.0.0+' . str_repeat( 'b8', 16 ) );
		define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION', '7.0.0' );
		define(
			'HAL_FRONTEND_DASHBOARD_RUNTIME_URL',
			'https://example.test/releases/' . HAL_FRONTEND_DASHBOARD_RELEASE_ID . '/'
		);
	}
}

function b8_define_valid_profile(): void {
	if ( ! defined( 'HOSSAM_AI_RUNTIME_PROFILE' ) ) {
		define( 'HOSSAM_AI_RUNTIME_PROFILE', array(
			'per_minute' => 30, 'per_day' => 500, 'concurrent' => 2, 'max_attempts' => 3,
			'stale_pending' => 3600, 'processing_deadline' => 240,
			'wp_ai_client_timeout' => 60, 'direct_key_timeout' => 90,
		) );
	}
}

function b8_require_runtime(): void {
	require $GLOBALS['B8_PROJECT'] . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'bootstrap.php';
}

function b8_reset_fixtures(): void {
	$GLOBALS['B8_OPTIONS']      = array();
	$GLOBALS['B8_TRANSIENTS']   = array();
	$GLOBALS['B8_CAPS']         = array();
	$GLOBALS['B8_LOGGED_IN']    = true;
	$GLOBALS['B8_SHORTCODES']   = array();
	$GLOBALS['B8_DB_VAR']       = null;
	$GLOBALS['B8_DB_RESULTS']   = array();
	$GLOBALS['B8_DB_FAIL']      = false;
	$GLOBALS['B8_HOOKS']        = array();
	$GLOBALS['B8_ACTIONS']      = array();
}

function b8_set_features( array $features ): void {
	HAL_Frontend_Dashboard_Settings_Repository::save( array( 'features' => $features ) );
	HAL_Frontend_Dashboard_Settings_Repository::invalidate_cache();
}

function b8_enable_all_features(): void {
	b8_set_features( array(
		'posts' => true, 'files' => true, 'inbox' => true, 'members' => true,
		'amelia' => true, 'finance' => true, 'store' => true,
		'wpml_translations' => true, 'rank_math_seo' => true,
		'ultimate_member_profile' => true, 'ai' => true,
	) );
}

/**
 * Seed the real integration registry transient with available shapes for
 * woocommerce/rank_math/wpml/amelia so panels can render their richer
 * states (the real registry builder reports every plugin inactive in the
 * harness; the cached shape is the contract consumed by
 * hossam_get_integration_decision).
 */
function b8_seed_registry(): void {
	set_transient( hossam_integration_registry_key(), array(
		'woocommerce' => array(
			'status'        => 'available',
			'plugin_active' => true,
			'version'       => '8.0-b8',
			'capabilities'  => array( 'orders' => true, 'products' => true ),
			'reason'        => '',
			'checked_at'    => '2026-09-16T00:00:00Z',
		),
		'rank_math' => array(
			'status'        => 'available',
			'plugin_active' => true,
			'version'       => '1.0-b8',
			'capabilities'  => array( 'plugin_contract' => true, 'verified_fields' => false ),
			'reason'        => '',
			'checked_at'    => '2026-09-16T00:00:00Z',
		),
		'wpml' => array(
			'status'        => 'available',
			'plugin_active' => true,
			'version'       => '4.6-b8',
			'capabilities'  => array( 'languages' => true ),
			'reason'        => '',
			'checked_at'    => '2026-09-16T00:00:00Z',
		),
		'amelia' => array(
			'status'        => 'available',
			'plugin_active' => true,
			'version'       => '7.0-b8',
			'capabilities'  => array( 'bookings_shortcode' => true ),
			'reason'        => '',
			'checked_at'    => '2026-09-16T00:00:00Z',
		),
	), 5 * MINUTE_IN_SECONDS );
}

function b8_capture_part( string $part, array $args = array() ): string {
	ob_start();
	HAL_Frontend_Dashboard_Template_Controller::render_part( $part, $args );
	return (string) ob_get_clean();
}

/** The shell's arg set (batch-7's 44 named keys + the batch-8 delta V-1
 * 'role_display_map'). */
function b8_full_args(): array {
	return array(
		'current_user'         => new WP_Post( array( 'ID' => 7, 'post_type' => 'user-fixture' ) ),
		'user_id'              => 7,
		'_dash_base'           => 'https://site.test/dashboard/',
		'user_name'            => 'B8 Admin',
		'user_fname'           => 'B8',
		'user_email'           => 'b8@example.test',
		'user_roles'           => array( 'administrator' ),
		'user_avatar_url'      => '',
		'is_admin'             => current_user_can( 'manage_options' ),
		'is_editor'            => current_user_can( 'edit_others_posts' ),
		'is_author'            => current_user_can( 'publish_posts' ),
		'is_writer'            => current_user_can( 'edit_posts' ),
		'can_upload'           => current_user_can( 'upload_files' ),
		'can_delete'           => current_user_can( 'edit_posts' ),
		'is_amelia_mgr'        => current_user_can( 'view_amelia_calendar_all' ),
		'is_amelia_emp'        => false,
		'url_panel'            => '',
		'url_post_id'          => 0,
		'direct_post_panel'    => false,
		'can_edit_url_post'    => false,
		'show_edit_post_link'  => false,
		'show_delete_post_link' => false,
		'amelia_integration'   => array( 'status' => 'unavailable', 'capabilities' => array() ),
		'woo_integration'      => array( 'status' => 'unavailable', 'capabilities' => array() ),
		'wpml_integration'     => array( 'status' => 'unavailable', 'capabilities' => array() ),
		'sees_calendar'        => false,
		'sees_content'         => false,
		'_show_finance'        => true,
		'sees_store'           => false,
		'display_role'         => 'Member',
		'author_filter'        => false,
		'stat_all'             => 0,
		'stat_pending'         => 0,
		'stat_published'       => 0,
		'stat_draft'           => 0,
		'activity_query'       => new WP_Query(),
		'articles_query'       => new WP_Query(),
		'unread_inbox'         => null,
		'logo_url'             => '',
		'show_seo'             => true,
		'show_translations'    => true,
		'show_profile'         => true,
		'show_inbox'           => true,
		'show_members'         => true,
		/* Batch-8 delta (V-1): the shell now passes role_display_map
		 * explicitly (45th key) for the members Roles table. */
		'role_display_map'     => array(
			'administrator'   => hossam_t( 'Administrator' ),
			'editor'          => hossam_t( 'Editor' ),
			'author'          => hossam_t( 'Author' ),
			'contributor'     => hossam_t( 'Contributor' ),
			'amelia_manager'  => hossam_t( 'Manager' ),
			'amelia_employee' => hossam_t( 'Employee' ),
		),
	);
}

/* ════════════════════════════════════════════════════════════════
 * MAIN MODE
 * ════════════════════════════════════════════════════════════════ */

function b8_mode_main( string $project ): void {
	b8_define_release_context();
	b8_define_valid_profile();
	b8_reset_fixtures();
	b8_require_runtime();

	$panel_files = array( 'seo', 'translations', 'inbox', 'finance', 'store', 'admin', 'members' );
	$sourceRoot  = 'D:/حسام عادل المحامي Hossam Adel Lawyer/website/dashboard/Dahboard-v-1.0.0';

	/* 1. Runtime loads with the batch-7 controller; all twelve documented
	 * parts now exist on disk (batch-8 completes the set). */
	b8_check( 'L1-runtime-ready', did_action( 'hal_frontend_dashboard_runtime_ready' ) >= 1,
		'runtime_ready fired after full load (27 units)',
		'runtime_ready did not fire' );
	$expected_parts = array( 'overview', 'posts', 'files', 'bookings', 'seo', 'translations', 'profile', 'inbox', 'finance', 'store', 'admin', 'members' );
	b8_check( 'L2-part-allowlist', HAL_Frontend_Dashboard_Template_Controller::PANEL_PARTS === $expected_parts,
		'PANEL_PARTS equals the twelve documented dashboard parts in order',
		'allowlist mismatch: ' . json_encode( HAL_Frontend_Dashboard_Template_Controller::PANEL_PARTS ) );
	$missing_files = array();
	foreach ( $expected_parts as $part ) {
		if ( ! is_file( $project . '/runtime/templates/dashboard/' . $part . '.php' ) ) {
			$missing_files[] = $part;
		}
	}
	b8_check( 'L3-all-twelve-parts-shipped', array() === $missing_files,
		'all twelve panel part files exist inside runtime/templates/dashboard (batch-8 completes the set)',
		'missing files: ' . json_encode( $missing_files ) );

	/* 2. Byte-diff with the documented delta map: each migrated body after
	 * its guard equals the source bytes (inventory snapshot 5CD55D5D…)
	 * with ONLY the documented splice applied, preceded by the known
	 * header+guard and followed by nothing. */
	$guard_anchor = "if ( ! defined( 'ABSPATH' ) ) {";
	$guard_close  = "\n}\n";
	$doc_close    = " */\n\n";
	$gate = function ( string $expr ): string {
		return "if ( ! " . $expr . " ) {\n\treturn;\n}\n";
	};
	$gate4 = function ( string $expr ): string {
		return "if ( ! " . $expr . " ) {\n    return;\n}\n";
	};
	$delta_map = array(
		/* B3-08 early return before the first output (the panel markup
		 * comment sits BEFORE the capability condition in the source). */
		'seo'          => array( 'replace' => array(
			"    && ! empty( \$hossam_rm_caps['verified_fields'] );\n?>",
			"    && ! empty( \$hossam_rm_caps['verified_fields'] );\n\n"
				. $gate4( "HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'rank_math_seo' )" )
				. "?>",
		) ),
		'translations' => array( 'prepend' => $gate( "HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'wpml_translations' )" ) ),
		'inbox'        => array( 'prepend' => $gate( "HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'inbox' )" ) ),
		'finance'      => array( 'replace' => array(
			"if ( ! is_user_logged_in() ) {\n    return;\n}\n",
			"if ( ! is_user_logged_in() ) {\n    return;\n}\n"
				. $gate4( "HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'finance' )" ),
		) ),
		'store'        => array( 'prepend' => $gate( "HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'store' )" ) ),
		'admin'        => array(),
		/* §19 double guard: real capability first, then the owner toggle. */
		'members'      => array( 'prepend' =>
			$gate( "current_user_can( 'manage_options' )" )
			. $gate( "HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'members' )" ) ),
	);
	foreach ( $panel_files as $file ) {
		$src  = (string) file_get_contents( $sourceRoot . '/theme/template-parts/dashboard/' . $file . '.php' );
		$dst  = (string) file_get_contents( $project . '/runtime/templates/dashboard/' . $file . '.php' );
		$spos = strpos( $src, $guard_anchor ) + strlen( $guard_anchor );
		$sclose = strpos( $src, $guard_close, $spos );
		$src_body = substr( $src, $sclose + strlen( $guard_close ) );
		$delta = $delta_map[ $file ];
		if ( isset( $delta['prepend'] ) ) {
			$expected_body = $delta['prepend'] . $src_body;
		} elseif ( isset( $delta['replace'] ) ) {
			list( $from, $to ) = $delta['replace'];
			if ( substr_count( $src_body, $from ) !== 1 ) {
				b8_check( 'D-' . $file . '-delta-anchor', false, 'delta anchor not found exactly once in source', 'anchor drifted' );
				continue;
			}
			$expected_body = str_replace( $from, $to, $src_body );
			b8_check( 'D-' . $file . '-delta-anchor', true,
				$file . '.php documented delta anchor found exactly once in the source',
				'anchor drifted' );
		} else {
			$expected_body = $src_body;
		}
		$dpos = strpos( $dst, $guard_anchor );
		b8_check( 'D-' . $file . '-guard-present', false !== $dpos && 1 === substr_count( $dst, $guard_anchor ),
			$file . '.php carries exactly one ABSPATH guard after its documented header',
			$file . '.php guard missing or duplicated' );
		if ( false === $dpos ) {
			continue;
		}
		$dclose = strpos( $dst, $guard_close, $dpos );
		$tail_start = $dclose + strlen( $guard_close );
		$after_guard = substr( $dst, $tail_start );
		b8_check( 'D-' . $file . '-body-byte-exact', $after_guard === "\n" . $expected_body,
			$file . '.php migrated body is byte-exact: source (snapshot 5CD55D5D…) + the single documented delta only',
			$file . '.php body deviates from source+documented-delta' );
	}

	/* 3. Static scans (comments stripped via tokens) on the seven new
	 * templates: no child-theme helpers, no write operations, and the
	 * exact documented owner-gate keys per panel. */
	if ( ! function_exists( 'b8_strip_php_comments' ) ) {
		function b8_strip_php_comments( string $code ): string {
			$out    = '';
			$tokens = token_get_all( $code );
			foreach ( $tokens as $token ) {
				if ( is_array( $token ) ) {
					if ( in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
						continue;
					}
					$out .= $token[1];
				} else {
					$out .= $token;
				}
			}
			return $out;
		}
	}
	$child_helpers = array( 'get_template_part(', 'get_stylesheet_directory', 'get_template_directory' );
	$write_ops     = array(
		'wp_insert_post', 'wp_update_post', 'wp_trash_post', 'wp_untrash_post',
		'wp_delete_post', 'wp_delete_attachment', 'media_handle_upload',
		'update_post_meta', 'delete_post_meta', 'add_post_meta',
		'wp_set_post_categories', 'set_post_thumbnail', 'update_option',
		'add_option', 'delete_option',
	);
	$feature_keys  = array( 'posts', 'files', 'inbox', 'members', 'amelia', 'finance', 'store', 'wpml_translations', 'rank_math_seo', 'ultimate_member_profile', 'ai' );
	$expected_gate = array(
		'seo'          => array( 'rank_math_seo' ),
		'translations' => array( 'wpml_translations' ),
		'inbox'        => array( 'inbox' ),
		'finance'      => array( 'finance' ),
		'store'        => array( 'store' ),
		'admin'        => array(),
		'members'      => array( 'members' ),
	);
	foreach ( $panel_files as $file ) {
		$code = b8_strip_php_comments( (string) file_get_contents( $project . '/runtime/templates/dashboard/' . $file . '.php' ) );
		$hits = array();
		foreach ( $child_helpers as $needle ) {
			if ( false !== strpos( $code, $needle ) ) {
				$hits[] = $needle;
			}
		}
		b8_check( 'S-' . $file . '-no-child-theme-helpers', array() === $hits,
			$file . '.php uses no get_template_part()/child-theme path helpers',
			$file . '.php contains: ' . json_encode( $hits ) );

		/* Write ops are checked against T_STRING tokens only — inline JS
		 * (inside <script> after ?>) is T_INLINE_HTML and never matches. */
		$tokens  = token_get_all( (string) file_get_contents( $project . '/runtime/templates/dashboard/' . $file . '.php' ) );
		$strings = array();
		foreach ( $tokens as $token ) {
			if ( is_array( $token ) && T_STRING === $token[0] ) {
				$strings[] = $token[1];
			}
		}
		$write_hits = array_values( array_intersect( $write_ops, $strings ) );
		b8_check( 'S-' . $file . '-no-write-operations', array() === $write_hits,
			$file . '.php performs no write operations (parts render only; §19/§8.3)',
			$file . '.php calls write ops: ' . json_encode( $write_hits ) );

		/* Owner-gate keys: the documented key(s) appear as a real
		 * is_feature_enabled( 'key' ) call; no other feature key is
		 * consumed anywhere in the template. */
		$expected_keys = $expected_gate[ $file ];
		$consumed_keys = array();
		foreach ( $feature_keys as $key ) {
			if ( false !== strpos( $code, "is_feature_enabled( '" . $key . "' )" ) ) {
				$consumed_keys[] = $key;
			}
		}
		b8_check( 'S-' . $file . '-owner-gate-keys', $consumed_keys === $expected_keys,
			$file . '.php consumes exactly the documented owner-gate keys',
			$file . '.php gate keys ' . json_encode( $consumed_keys ) . ' != documented ' . json_encode( $expected_keys ) );
	}
	b8_check( 'S-admin-source-guard-present', false !== strpos( b8_strip_php_comments( (string) file_get_contents( $project . '/runtime/templates/dashboard/admin.php' ) ), "current_user_can( 'manage_options' )" ),
		'admin.php keeps the source-internal manage_options guard (its documented internal gate)',
		'admin.php lost the source manage_options guard' );

	/* Arg-contract mirror (token-level, no regex): the literal
	 * $template_args = [ ... ] key list in runtime/templates/dashboard.php
	 * must mirror the b8_full_args() key list exactly (same keys, same
	 * order, ===) — static regression guard for arg-contract gaps. */
	$shell_keys   = array();
	$shell_tokens = token_get_all( (string) file_get_contents( $project . '/runtime/templates/dashboard.php' ) );
	for ( $i = 0, $n = count( $shell_tokens ); $i < $n; $i++ ) {
		$token = $shell_tokens[ $i ];
		if ( ! is_array( $token ) || T_VARIABLE !== $token[0] || '$template_args' !== $token[1] ) {
			continue;
		}
		/* Expect: '=' then '[' (whitespace/comments skipped). */
		$j = $i + 1;
		while ( $j < $n && is_array( $shell_tokens[ $j ] ) && in_array( $shell_tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			$j++;
		}
		if ( $j >= $n || '=' !== $shell_tokens[ $j ] ) {
			continue;
		}
		$j++;
		while ( $j < $n && is_array( $shell_tokens[ $j ] ) && in_array( $shell_tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			$j++;
		}
		if ( $j >= $n || '[' !== $shell_tokens[ $j ] ) {
			continue;
		}
		/* Collect literal keys (T_CONSTANT_ENCAPSED_STRING followed by
		 * T_DOUBLE_ARROW) until the first ']' at depth zero — dashboard.php
		 * uses the short [ ... ] array syntax. */
		for ( $k = $j + 1, $depth = 0; $k < $n; $k++ ) {
			$t = $shell_tokens[ $k ];
			if ( ! is_array( $t ) ) {
				if ( '[' === $t || '(' === $t || '{' === $t ) {
					$depth++;
				} elseif ( ']' === $t || ')' === $t || '}' === $t ) {
					if ( 0 === $depth ) {
						break;
					}
					$depth--;
				}
				continue;
			}
			if ( T_CONSTANT_ENCAPSED_STRING === $t[0] ) {
				$m = $k + 1;
				while ( $m < $n && is_array( $shell_tokens[ $m ] ) && T_WHITESPACE === $shell_tokens[ $m ][0] ) {
					$m++;
				}
				if ( $m < $n && is_array( $shell_tokens[ $m ] ) && T_DOUBLE_ARROW === $shell_tokens[ $m ][0] ) {
					$shell_keys[] = (string) substr( $t[1], 1, -1 );
				}
			}
		}
		break;
	}
	$expected_shell_keys = array_keys( b8_full_args() );
	$missing_shell_keys  = array_values( array_diff( $expected_shell_keys, $shell_keys ) );
	$extra_shell_keys    = array_values( array_diff( $shell_keys, $expected_shell_keys ) );
	$shell_args_diff     = array();
	if ( array() !== $missing_shell_keys ) {
		$shell_args_diff[] = 'missing from shell: ' . json_encode( $missing_shell_keys );
	}
	if ( array() !== $extra_shell_keys ) {
		$shell_args_diff[] = 'extra in shell: ' . json_encode( $extra_shell_keys );
	}
	if ( array() === $missing_shell_keys && array() === $extra_shell_keys && $shell_keys !== $expected_shell_keys ) {
		$shell_args_diff[] = 'same keys, different order';
	}
	b8_check( 'S-shell-args-mirror', $shell_keys === $expected_shell_keys,
		'dashboard.php shell literal $template_args keys mirror b8_full_args() exactly (' . count( $expected_shell_keys ) . ' keys, same order) — token-level arg-contract guard',
		'shell $template_args keys != b8_full_args(): ' . ( array() === $shell_args_diff ? 'no $template_args assignment found in dashboard.php' : implode( '; ', $shell_args_diff ) ) );

	/* 4. B3-08 matrix — internal gates via the real render_part. */
	$admin_caps = array(
		'manage_options' => true, 'edit_others_posts' => true, 'publish_posts' => true,
		'edit_posts' => true, 'upload_files' => true, 'edit_products' => true,
		'manage_woocommerce' => true,
	);
	$render = function ( string $part ): string {
		return b8_capture_part( $part, b8_full_args() );
	};

	/* 4a. Owner gates OFF → zero output from each gated panel, even for
	 * an admin (the internal early return is the second layer). */
	b8_enable_all_features();
	b8_set_features( array(
		'rank_math_seo' => false, 'wpml_translations' => false, 'inbox' => false,
		'finance' => false, 'store' => false, 'members' => false,
	) );
	$GLOBALS['B8_CAPS'] = $admin_caps;
	$off = array(
		'seo'          => 'rank_math_seo',
		'translations' => 'wpml_translations',
		'inbox'        => 'inbox',
		'finance'      => 'finance',
		'store'        => 'store',
		'members'      => 'members',
	);
	foreach ( $off as $part => $feature ) {
		b8_check( 'G-OFF-' . $part, '' === $render( $part ),
			$part . ' panel renders zero output for an admin while ' . $feature . ' is owner-disabled (internal gate)',
			$part . ' panel produced output while owner-disabled' );
	}
	/* admin has no owner feature key: it stays available for an admin. */
	b8_check( 'G-OFF-admin-still-gated', false !== strpos( $render( 'admin' ), 'id="panel-admin"' ),
		'admin panel (no owner key) keeps rendering for a manage_options admin',
		'admin panel did not render for an admin' );

	/* 4b. Capability layer: zero capabilities → capability-gated panels
	 * stay empty even with every feature on (the toggle never replaces
	 * the capability layer). */
	b8_enable_all_features();
	$GLOBALS['B8_CAPS'] = array();
	$_seo_cap_html = $render( 'seo' );
	b8_check( 'G-CAP-LAYER-seo', false === strpos( $_seo_cap_html, 'id="panel-seo"' ) && false === strpos( $_seo_cap_html, 'SEO Health Overview' ),
		'seo panel needs current_user_can(edit_posts) — a capability-less user gets no panel surface even with the feature on (the source markup comment precedes its capability condition and is preserved byte-exact)',
		'seo panel rendered without edit_posts' );
	b8_check( 'G-CAP-LAYER-store', '' === $render( 'store' ),
		'store panel needs products/orders capabilities — a capability-less user gets nothing even with the feature on',
		'store panel rendered without capabilities' );
	b8_check( 'G-CAP-LAYER-members', '' === $render( 'members' ),
		'members panel double guard: no manage_options → nothing even with the feature on (§19)',
		'members panel rendered without manage_options' );
	b8_check( 'G-CAP-LAYER-inbox-authenticated', false !== strpos( $render( 'inbox' ), 'id="panel-inbox"' ),
		'inbox panel renders for any authenticated user (source behaviour) with the feature on',
		'inbox panel missing for authenticated user' );
	b8_check( 'G-CAP-LAYER-finance-authenticated', false !== strpos( $render( 'finance' ), 'id="panel-finance"' ),
		'finance panel renders for any authenticated user (source behaviour) with the feature on',
		'finance panel missing for authenticated user' );
	b8_check( 'G-CAP-LAYER-translations-authenticated', false !== strpos( $render( 'translations' ), 'id="panel-translation"' ),
		'translations panel renders for any authenticated user (source behaviour) with the feature on',
		'translations panel missing for authenticated user' );

	/* 4c. Logged-out finance: the source guard is_user_logged_in() wins
	 * even with the feature on. */
	$GLOBALS['B8_LOGGED_IN'] = false;
	b8_check( 'G-CAP-LAYER-finance-logged-out', '' === $render( 'finance' ),
		'finance panel keeps its source is_user_logged_in() guard',
		'finance panel rendered for a logged-out user' );
	$GLOBALS['B8_LOGGED_IN'] = true;

	/* 4d. Enabled features + admin capabilities → full renders with the
	 * documented panel ids. */
	b8_enable_all_features();
	$GLOBALS['B8_CAPS'] = $admin_caps;
	b8_seed_registry();
	$GLOBALS['B8_SHORTCODES'][] = 'wpml_language_switcher';

	$seo_html = $render( 'seo' );
	b8_check( 'R-seo-renders', false !== strpos( $seo_html, 'id="panel-seo"' ) && false !== strpos( $seo_html, 'SEO Health Overview' ) && false !== strpos( $seo_html, 'No articles found.' ),
		'seo panel renders the real SEO table shell (empty query state) for a writer/admin with the feature on',
		'seo panel markup missing' );
	$translations_html = $render( 'translations' );
	b8_check( 'R-translations-renders', false !== strpos( $translations_html, 'id="panel-translation"' ) && false !== strpos( $translations_html, $GLOBALS['B8_SHORTCODE_OUT'] ),
		'translations panel renders the switcher shortcode call and its panels',
		'translations panel markup missing' );
	$inbox_admin = $render( 'inbox' );
	b8_check( 'R-inbox-admin-form', false !== strpos( $inbox_admin, 'id="panel-inbox"' ) && false !== strpos( $inbox_admin, 'id="inbox-container"' ) && false !== strpos( $inbox_admin, 'sendInboxMessage()' ),
		'inbox panel renders the container and the admin-only send form (is_admin from args)',
		'inbox admin form missing' );
	$inbox_member = b8_capture_part( 'inbox', array_merge( b8_full_args(), array( 'is_admin' => false ) ) );
	b8_check( 'R-inbox-member-no-form', false !== strpos( $inbox_member, 'id="inbox-container"' ) && false === strpos( $inbox_member, 'sendInboxMessage()' ),
		'inbox panel for a non-admin renders the container without the send form',
		'inbox non-admin form leak' );
	$finance_html = $render( 'finance' );
	b8_check( 'R-finance-renders', false !== strpos( $finance_html, 'id="panel-finance"' ) && false !== strpos( $finance_html, 'id="finance-kpi"' ) && false !== strpos( $finance_html, 'id="finance-saved-cards-wrap"' ),
		'finance panel renders the five-tab structure with its empty containers (filled by finance.js)',
		'finance panel markup missing' );
	$store_html = $render( 'store' );
	b8_check( 'R-store-scopes', false !== strpos( $store_html, 'id="panel-store"' ) && false !== strpos( $store_html, 'data-tab="products"' ) && false !== strpos( $store_html, 'data-tab="orders"' ),
		'store panel separates products/orders scopes for a woocommerce admin with the feature on',
		'store panel scopes missing' );
	$GLOBALS['B8_CAPS'] = array( 'edit_products' => true );
	$store_po = b8_capture_part( 'store', b8_full_args() );
	$GLOBALS['B8_CAPS'] = $admin_caps;
	b8_check( 'R-store-products-only', false !== strpos( $store_po, 'data-tab="products"' ) && false === strpos( $store_po, 'data-tab="orders"' ),
		'store panel with only edit_products renders the products tab without the orders tab',
		'store products-only scope leak' );
	$admin_html = $render( 'admin' );
	b8_check( 'R-admin-renders', false !== strpos( $admin_html, 'id="panel-admin"' ) && false !== strpos( $admin_html, 'Role Access Matrix' ),
		'admin panel renders for a manage_options admin with its role matrix (no owner key)',
		'admin panel markup missing' );
	b8_check( 'R-admin-no-secrets', false === stripos( $admin_html, 'api_key_value' ) && false === strpos( $admin_html, 'Amelia-Key' ) && false === strpos( $admin_html, 'sk-' ),
		'admin panel renders no secrets or key values (§19)',
		'admin panel leaked a secret-looking value' );
	$members_html = $render( 'members' );
	b8_check( 'R-members-renders', false !== strpos( $members_html, 'id="panel-members"' ) && false !== strpos( $members_html, 'role-matrix' ),
		'members panel renders for a manage_options admin with the roles table (double guard held)',
		'members panel markup missing' );
	b8_check( 'R-members-roles-map-reaches-template', false !== strpos( $members_html, 'administrator' ) && false === strpos( $members_html, 'Warning:' ),
		'members Roles table renders actual role rows (role_display_map passed explicitly by the shell — batch-8 delta V-1) with no PHP warnings',
		'Roles table empty or PHP warnings leaked' );

	/* 4e. No PHP warnings in any of the seven rendered panels (regression
	 * guard for arg-contract gaps like V-1). */
	$warn_surfaces = array(
		'seo' => $seo_html, 'translations' => $translations_html, 'inbox' => $inbox_admin,
		'finance' => $finance_html, 'store' => $store_html, 'admin' => $admin_html,
		'members' => $members_html,
	);
	$warn_hits = array();
	foreach ( $warn_surfaces as $w_part => $w_html ) {
		if ( false !== strpos( $w_html, 'Warning:' ) || false !== stripos( $w_html, 'Deprecated:' ) ) {
			$warn_hits[] = $w_part;
		}
	}
	b8_check( 'R-no-php-warnings-in-panels', array() === $warn_hits,
		'none of the seven panels emit PHP warnings/notices into their output',
		'PHP diagnostics leaked into: ' . json_encode( $warn_hits ) );

	/* 5. Controller fail-safe contract unchanged (closed allowlist). */
	b8_check( 'C-parts-render-failsafe', '' === b8_capture_part( 'notapart' ) && '' === b8_capture_part( '../wp-config' ) && '' === b8_capture_part( 'zzz' ),
		'render_part keeps its closed allowlist fail-safe for invalid/unknown parts',
		'render_part produced output for an invalid part' );

	b8_result_line( 'main' );
}

/* ════════════════════════════════════════════════════════════════
 * Runner
 * ════════════════════════════════════════════════════════════════ */

$GLOBALS['B8_RESULTS'] = array();

$mode = $argv[1] ?? 'main';
if ( 'main' !== $mode ) {
	echo "Unknown mode: " . $mode . "\n";
	exit( 1 );
}
b8_mode_main( $GLOBALS['B8_PROJECT'] );
