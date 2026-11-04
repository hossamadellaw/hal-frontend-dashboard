<?php
/**
 * HAL Frontend Dashboard — B15 (v1.1.1) closure harness: branding/site-title,
 * site-section URLs, dashboard-page block, feature descriptions (W1–W5).
 *
 * Local, isolated harness: no live WordPress, no network, no database.
 * Calls the real project code — runtime/settings/class-settings-repository.php,
 * runtime/admin/class-admin-controller.php and the real
 * runtime/templates/dashboard.php source — only WordPress itself is
 * stubbed. No project logic is reimplemented in the harness.
 *
 * Coverage (v1.1.1 W1/W2/W3/W4/W5 + the documented branding-js decision):
 *
 *   DECISION   — Branding-related JS checks (maxlength=60/80, the eight
 *                site_section_urls[…] field names, the "auto" placeholder)
 *                are covered HERE at the PHP level against the REAL
 *                server-rendered Branding tab HTML, not in
 *                tests/js/admin-settings.test.js. Rationale: these
 *                attributes are server-rendered facts owned by the PHP
 *                controller template; admin-settings.js only relays them.
 *                The decision is documented here and in the wave-1
 *                report; admin-settings.test.js keeps its existing
 *                checks untouched (double coverage, zero deletions).
 *   DEFAULTS   — with no stored values the three new keys read '' /
 *                the eight closed section keys all '' (empty = automatic
 *                resolver everywhere), in the exact closed key order.
 *   VALIDATION — repository: title >60 / tagline >80 / non-strings
 *                reject the whole save (WP_Error, all-or-nothing);
 *                site_section_urls non-array / unknown key / non-string
 *                value reject; the unknown-key guard still rejects
 *                foreign keys now that it extends to the three new keys;
 *                corrupt stored values normalize to '' on read.
 *   CONTROLLER — the real handle_save_settings bridge: over-ceiling →
 *                save_failed with a byte-identical store; array input →
 *                save_failed; the site_sections_form marker gates the
 *                section-urls key (B3-01: its absence never touches the
 *                key); missing title key preserves, empty title clears;
 *                javascript:/ftp:// URLs become '' (fail-open to auto,
 *                never stored) through BOTH the esc_url_raw boundary and
 *                the controller's own scheme gate (measured permissively
 *                and strictly); the eight keys are rebuilt completely on
 *                every markered save.
 *   W2         — the dashboard-page status block renders OUTSIDE any
 *                form in the real Branding tab HTML: no <form>, no
 *                method="post", no page-id field anywhere in the block,
 *                and the option name hal_frontend_dashboard_page_id
 *                appears nowhere in the whole render (zero writes — the
 *                Installer stays the only writer); owned/fallback status
 *                is rendered from the real read-only helper.
 *   W4/W5      — the real Features tab renders the 12 documented feature
 *                description paragraphs and the five literal editor-mode
 *                description strings under the select; description
 *                paragraphs count exactly 12+5.
 *   TEMPLATE   — dashboard.php source contract: the three fallback
 *                literals are bound to the settings variables, the four
 *                render positions (title / logo alt / sb-title /
 *                tagline span) echo exactly those variables escaped,
 *                the two HAL constant marks remain (B9-L1), the
 *                section-override loop applies the stored URL through
 *                hossam_wpml_permalink before the resolver and only
 *                falls back to it when the override is ''. The behavioral
 *                fallback/replacement proof against the REAL rendered
 *                shell lives in tests/php/template-shell-test.php (the
 *                full-shell harness — this file's template section is
 *                the structural contract of the same four positions).
 *
 * Usage:  php tests/php/batch15-branding-settings-test.php            (main)
 *         php tests/php/batch15-branding-settings-test.php <mode> [args…]  (subprocess)
 *         exit 0 = all checks pass.
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

if ( PHP_SAPI !== 'cli' ) {
	echo "This harness must run under CLI PHP.\n";
	exit( 1 );
}

$hal_ext_dir = (string) ( getenv( 'HAL_PHP_EXT_DIR' ) ?: ini_get( 'extension_dir' ) );
if ( '' !== $hal_ext_dir && ! is_dir( $hal_ext_dir ) ) {
	$hal_ext_dir = (string) realpath( $hal_ext_dir );
}
define( 'HAL_TEST_EXT_DIR', is_dir( $hal_ext_dir ) ? $hal_ext_dir : (string) $hal_ext_dir );
require_once __DIR__ . '/lib-hal-php-flags.php'; // test-launcher flag filter (no product code, no assertions)

$project = dirname( __DIR__, 2 );

/* ────────────────────────────────────────────────────────────────
 * WordPress boundary stubs (WordPress only — never project logic)
 * ──────────────────────────────────────────────────────────────── */

$GLOBALS['W15_HOOKS']       = array();
$GLOBALS['W15_ACTIONS']     = array();
$GLOBALS['W15_OPTIONS']     = array();
$GLOBALS['W15_OPTION_LOG']  = array();
$GLOBALS['W15_RESULTS']     = array();
$GLOBALS['W15_CAN_MANAGE']  = false;
$GLOBALS['W15_NONCE_VALID'] = false;
$GLOBALS['W15_POSTS']       = array();
$GLOBALS['W15_MEDIA']       = array( 'mime' => '', 'is_image' => false );
$GLOBALS['W15_ENQUEUED']    = array( 'styles' => array(), 'scripts' => array(), 'localized' => array(), 'media' => 0 );
$GLOBALS['W15_PERMISSIVE_ESC'] = false;

function w15_check( string $id, bool $condition, string $pass, string $fail = '' ): void {
	$GLOBALS['W15_RESULTS'][] = array( 'id' => $id, 'ok' => $condition, 'pass' => $pass, 'fail' => $fail );
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook_name, $callback = '', int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['W15_HOOKS'][ $hook_name ][] = array( 'callback' => $callback, 'priority' => $priority );
		return true;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook_name, $callback = '', int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['W15_HOOKS'][ $hook_name ][] = array( 'callback' => $callback, 'priority' => $priority );
		return true;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook_name, ...$args ): void {
		$GLOBALS['W15_ACTIONS'][ $hook_name ] = ( $GLOBALS['W15_ACTIONS'][ $hook_name ] ?? 0 ) + 1;
		foreach ( $GLOBALS['W15_HOOKS'][ $hook_name ] ?? array() as $entry ) {
			call_user_func_array( $entry['callback'], $args );
		}
	}
}
if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook_name ): int {
		return $GLOBALS['W15_ACTIONS'][ $hook_name ] ?? 0;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook_name, $value, ...$args ) {
		foreach ( $GLOBALS['W15_HOOKS'][ $hook_name ] ?? array() as $entry ) {
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
		return (string) $url;
	}
}
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( string $text, string $domain = 'default' ): void {
		echo htmlspecialchars( $text, ENT_QUOTES );
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		return $text;
	}
}
/**
 * Boundary stub of esc_url_raw with a documented fixture switch.
 * Strict mode (default): the WordPress contract this harness models —
 * only http(s) survives, everything else (javascript:, data:, …) is
 * dropped to ''. Permissive mode ($GLOBALS['W15_PERMISSIVE_ESC']): the
 * trimmed string passes through UNCHANGED so the CONTROLLER's own
 * scheme gate is measured in isolation of the boundary strictness.
 */
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ): string {
		$url = trim( (string) $url );
		if ( ! empty( $GLOBALS['W15_PERMISSIVE_ESC'] ) ) {
			return $url;
		}
		return preg_match( '#^https?://#i', $url ) ? $url : '';
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
if ( ! function_exists( 'checked' ) ) {
	function checked( $checked, $current = true, $display = true ): string {
		$output = $display && (string) $checked === (string) $current ? 'checked="checked" ' : '';
		if ( $display ) {
			echo $output;
		}
		return $output;
	}
}
if ( ! function_exists( 'selected' ) ) {
	function selected( $selected, $current = true, $display = true ): string {
		$output = $display && (string) $selected === (string) $current ? 'selected="selected" ' : '';
		if ( $display ) {
			echo $output;
		}
		return $output;
	}
}
if ( ! function_exists( 'submit_button' ) ) {
	function submit_button( $text = null, $type = 'primary', $name = 'submit', $wrap = true, $other_attributes = array() ): void {
		echo '<button type="submit">' . htmlspecialchars( (string) $text, ENT_QUOTES ) . '</button>';
	}
}
if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ): string {
		$field = '<input type="hidden" name="' . htmlspecialchars( (string) $name, ENT_QUOTES ) . '" value="fixture-nonce" />';
		if ( $echo ) {
			echo $field;
		}
		return $field;
	}
}
if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( string $nonce, $action = -1 ): int|false {
		return $GLOBALS['W15_NONCE_VALID'] && 'fixture-nonce' === $nonce ? 1 : false;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability ): bool {
		if ( 'upload_files' === $capability ) {
			return false;
		}
		return $GLOBALS['W15_CAN_MANAGE'] && HAL_Frontend_Dashboard_Settings_Repository::CAPABILITY === $capability;
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return 7;
	}
}
if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id(): int {
		return 1;
	}
}
if ( ! class_exists( 'W15_Redirect' ) ) {
	/**
	 * Faithful emulation: real wp_safe_redirect() is followed by exit in
	 * the controller, so the stub unwinds with an exception that carries
	 * the target instead of ending the process.
	 */
	class W15_Redirect extends Exception {
		public string $target;
		public function __construct( string $target ) {
			parent::__construct( 'w15 redirect' );
			$this->target = $target;
		}
	}
}
if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( string $location, int $status = 302 ): bool {
		throw new W15_Redirect( $location );
	}
}
if ( ! class_exists( 'W15_Wp_Die' ) ) {
	class W15_Wp_Die extends Exception {
	}
}
if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $message = '', $title = '', $args = array() ): void {
		throw new W15_Wp_Die( (string) $message );
	}
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/' . $path;
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		return 'https://example.test' . $path;
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( array $args, string $url ): string {
		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default_value = false ) {
		return array_key_exists( $option, $GLOBALS['W15_OPTIONS'] ) ? $GLOBALS['W15_OPTIONS'][ $option ] : $default_value;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, $value, $autoload = null ): bool {
		if ( ! empty( $GLOBALS['W15_FAIL_OPTION_WRITE'] ) ) {
			return false;
		}
		$GLOBALS['W15_OPTIONS'][ $option ] = $value;
		$GLOBALS['W15_OPTION_LOG'][ $option ] = array( 'autoload' => $autoload, 'written_at' => count( $GLOBALS['W15_OPTION_LOG'] ) );
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
		unset( $GLOBALS['W15_OPTIONS'][ '_transient_' . $key ] );
		return true;
	}
}
if ( ! function_exists( 'shortcode_exists' ) ) {
	function shortcode_exists( string $tag ): bool {
		return false;
	}
}
if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite(): bool {
		return false;
	}
}
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( string $scheme = 'auth' ): string {
		return 'w15-salt-' . $scheme;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0 ) {
		return json_encode( $value, $flags );
	}
}
// تفويض المالك #1 (2026-11-02): R29 — stub دالة نواة ناقصة بفئة (ب) بنمط
// الـharness (settings-and-secrets-test.php:314): نداء settings_save داخل
// مسار الحفظ ينادي hossam_audit_log من runtime/core/audit-log.php — كاتبه
// الحقيقي يكتب في جدول DB عبر $wpdb لا يحاكيه هذا الـharness إطلاقًا.
if ( ! function_exists( 'hossam_audit_log' ) ) {
	function hossam_audit_log( string $event_action, array $context = array() ): bool {
		return true;
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component );
	}
}
if ( ! function_exists( 'get_home_url' ) ) {
	function get_home_url(): string {
		return 'https://example.test/';
	}
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( string $hook ): int|false {
		return false;
	}
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( int $timestamp, string $recurrence, string $hook ): bool {
		return true;
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( int $id ) {
		return $GLOBALS['W15_POSTS'][ $id ] ?? null;
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $id ): string {
		return 'https://site.test/dashboard-page/';
	}
}
if ( ! function_exists( 'get_post_mime_type' ) ) {
	function get_post_mime_type( $id ): string {
		return $GLOBALS['W15_MEDIA']['mime'];
	}
}
if ( ! function_exists( 'wp_attachment_is_image' ) ) {
	function wp_attachment_is_image( $id ): bool {
		return (bool) $GLOBALS['W15_MEDIA']['is_image'];
	}
}
if ( ! function_exists( 'wp_enqueue_media' ) ) {
	function wp_enqueue_media( array $args = array() ): void {
		$GLOBALS['W15_ENQUEUED']['media']++;
	}
}
if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( string $handle, string $src = '', array $deps = array(), $ver = false ): void {
		$GLOBALS['W15_ENQUEUED']['styles'][ $handle ] = array( 'src' => $src, 'ver' => $ver );
	}
}
if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( string $handle, string $src = '', array $deps = array(), $ver = false, bool $in_footer = false ): void {
		$GLOBALS['W15_ENQUEUED']['scripts'][ $handle ] = array( 'src' => $src, 'ver' => $ver );
	}
}
if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( string $handle, string $object_name, array $l10n ): bool {
		$GLOBALS['W15_ENQUEUED']['localized'][ $object_name ] = $l10n;
		return true;
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		public function __construct( string $code = '', string $message = '' ) {
			$this->code = $code;
		}
		public function get_error_code(): string {
			return $this->code;
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
		public string $post_status = '';
		public function __construct( array $args = array() ) {
			foreach ( $args as $key => $value ) {
				$this->{$key} = $value;
			}
		}
	}
}

/* ────────────────────────────────────────────────────────────────
 * Fixture helpers
 * ──────────────────────────────────────────────────────────────── */

function w15_define_release_context(): void {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'w15-' . getmypid() . DIRECTORY_SEPARATOR );
	}
	if ( ! defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT' ) ) {
		define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT', $GLOBALS['W15_PROJECT'] . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR );
		define( 'HAL_FRONTEND_DASHBOARD_RELEASE_ID', '3.0.0+' . str_repeat( 'f1', 20 ) );
		define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION', '3.0.0' );
		define(
			'HAL_FRONTEND_DASHBOARD_RUNTIME_URL',
			'https://example.test/releases/' . HAL_FRONTEND_DASHBOARD_RELEASE_ID . '/'
		);
	}
}

function w15_require_runtime(): void {
	require $GLOBALS['W15_PROJECT'] . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'bootstrap.php';
}

function w15_result_line( string $mode ): void {
	$failed = array();
	foreach ( $GLOBALS['W15_RESULTS'] as $result ) {
		if ( ! $result['ok'] ) {
			$failed[] = array( 'id' => $result['id'], 'fail' => $result['fail'] );
		}
	}
	$payload = array( 'mode' => $mode, 'php' => PHP_VERSION );
	if ( array() !== $failed ) {
		$payload['failed'] = $failed;
	}
	echo 'W15-RESULT ' . json_encode( $payload, JSON_UNESCAPED_SLASHES ), PHP_EOL;
	$all_ok = array() === $failed;
	echo 'W15-VERDICT ' . $mode . ' ' . ( $all_ok ? 'ALL-ASSERTIONS-HELD' : 'ASSERTIONS-FAILED' ), PHP_EOL;
	exit( $all_ok ? 0 : 1 );
}

/** Renders one handler call and captures the redirect signal. */
function w15_capture_redirect( callable $handler ): W15_Redirect {
	try {
		$handler();
	} catch ( W15_Redirect $redirect ) {
		return $redirect;
	}
	throw new RuntimeException( 'handler did not redirect' );
}

function w15_store(): array {
	return (array) ( $GLOBALS['W15_OPTIONS']['hal_frontend_dashboard_settings'] ?? array() );
}

/* ────────────────────────────────────────────────────────────────
 * Subprocess modes — real bootstrap + real classes
 * ──────────────────────────────────────────────────────────────── */

function w15_mode( string $mode, array $args, string $project ): void {
	$GLOBALS['W15_PROJECT'] = $project;
	w15_define_release_context();
	w15_require_runtime();

	switch ( $mode ) {
		case 'repo-contracts':
			// W1/W3 defaults: no stored values → '' everywhere, closed order.
			$keys = HAL_Frontend_Dashboard_Settings_Repository::SITE_SECTION_KEYS;
			w15_check( 'W15-D1', array( 'home', 'about', 'services', 'blog', 'contact', 'privacy', 'terms', 'shop' ) === $keys,
				'SITE_SECTION_KEYS is the closed eight in the documented order', 'section keys mismatch: ' . json_encode( $keys ) );
			$defaults = HAL_Frontend_Dashboard_Settings_Repository::get_all();
			w15_check( 'W15-D2', '' === $defaults['branding_site_title'] && '' === $defaults['branding_site_tagline']
					&& array_fill_keys( $keys, '' ) === $defaults['site_section_urls']
					&& array_keys( $defaults['site_section_urls'] ) === $keys,
				'defaults with no stored values: site title and tagline are empty and site_section_urls carries the closed eight, all empty, in the closed key order (empty = automatic resolver)',
				'defaults mismatch: ' . json_encode( $defaults['site_section_urls'] ?? null ) );
			$saved = HAL_Frontend_Dashboard_Settings_Repository::save( array(
				'branding_site_title'   => 'Hossam & Sons Law',
				'branding_site_tagline' => 'Legal Portal',
				'site_section_urls'     => array( 'home' => 'https://example.test/home/', 'privacy' => 'https://example.test/privacy/' ),
			) );
			$after = HAL_Frontend_Dashboard_Settings_Repository::get_all();
			w15_check( 'W15-D3', true === $saved && 'Hossam & Sons Law' === $after['branding_site_title'] && 'Legal Portal' === $after['branding_site_tagline']
					&& 'https://example.test/home/' === $after['site_section_urls']['home']
					&& 'https://example.test/privacy/' === $after['site_section_urls']['privacy']
					&& '' === $after['site_section_urls']['about']
					&& array_keys( $after['site_section_urls'] ) === $keys,
				'a valid save round-trips the two texts and the section map is always rebuilt as the complete closed eight (unmentioned keys normalize to "")',
				'valid save roundtrip mismatch: ' . json_encode( $after ) );

			// W1 ceilings (repository level, all-or-nothing WP_Error).
			$before = w15_store();
			$err = HAL_Frontend_Dashboard_Settings_Repository::save( array( 'branding_site_title' => str_repeat( 'a', 61 ) ) );
			w15_check( 'W15-V1', is_wp_error( $err ) && 'hal_settings_branding_site_title_invalid' === $err->get_error_code() && w15_store() === $before,
				'a 61-character site title is rejected with its own WP_Error code and the stored settings stay byte-identical (all-or-nothing)', 'over-ceiling title accepted or store mutated' );
			$err = HAL_Frontend_Dashboard_Settings_Repository::save( array( 'branding_site_tagline' => str_repeat( 'a', 81 ) ) );
			w15_check( 'W15-V2', is_wp_error( $err ) && 'hal_settings_branding_site_tagline_invalid' === $err->get_error_code() && w15_store() === $before,
				'an 81-character tagline is rejected with its own WP_Error code and the stored settings stay byte-identical', 'over-ceiling tagline accepted or store mutated' );
			$ok = HAL_Frontend_Dashboard_Settings_Repository::save( array(
				'branding_site_title'   => str_repeat( 'a', 60 ),
				'branding_site_tagline' => str_repeat( 'b', 80 ),
			) );
			$after = HAL_Frontend_Dashboard_Settings_Repository::get_all();
			w15_check( 'W15-V3', true === $ok && str_repeat( 'a', 60 ) === $after['branding_site_title'] && str_repeat( 'b', 80 ) === $after['branding_site_tagline'],
				'the exact ceilings 60/80 are accepted (boundary inclusive)', 'boundary values rejected' );
			// Multibyte fixtures — safe under both mb_strlen and its strlen
			// fallback (61 Arabic chars exceeds 60 under either metric; 30
			// Arabic chars stay at or under 60 under either metric).
			$err = HAL_Frontend_Dashboard_Settings_Repository::save( array( 'branding_site_title' => str_repeat( 'ب', 61 ) ) );
			$ok2 = HAL_Frontend_Dashboard_Settings_Repository::save( array( 'branding_site_title' => str_repeat( 'ب', 30 ) ) );
			w15_check( 'W15-V4', is_wp_error( $err ) && true === $ok2,
				'multibyte titles measure correctly under the mb_strlen-with-strlen-fallback guard: 61 Arabic characters reject, 30 Arabic characters accept (identical outcome under either length metric)',
				'multibyte length guard mismatch' );

			// Shape rejections at the repository level.
			$err = HAL_Frontend_Dashboard_Settings_Repository::save( array( 'branding_site_title' => array( 'x' ) ) );
			w15_check( 'W15-V5', is_wp_error( $err ) && 'hal_settings_branding_site_title_invalid' === $err->get_error_code(),
				'a non-string site title is rejected at the repository (no coercion)', 'array title coerced' );
			$err = HAL_Frontend_Dashboard_Settings_Repository::save( array( 'branding_site_tagline' => 12 ) );
			w15_check( 'W15-V6', is_wp_error( $err ) && 'hal_settings_branding_site_tagline_invalid' === $err->get_error_code(),
				'a non-string tagline is rejected at the repository (no coercion)', 'int tagline coerced' );
			$err = HAL_Frontend_Dashboard_Settings_Repository::save( array( 'site_section_urls' => 'not-an-array' ) );
			w15_check( 'W15-V7', is_wp_error( $err ) && 'hal_settings_site_section_urls_shape' === $err->get_error_code(),
				'a non-array site_section_urls is rejected with the shape code', 'non-array section urls accepted' );
			$err = HAL_Frontend_Dashboard_Settings_Repository::save( array( 'site_section_urls' => array( 'home' => 'https://x.test/', 'hacked' => 'https://x.test/' ) ) );
			w15_check( 'W15-V8', is_wp_error( $err ) && 'hal_settings_site_section_urls_invalid' === $err->get_error_code(),
				'a section key outside the closed eight is rejected at the repository', 'unknown section key accepted' );
			$err = HAL_Frontend_Dashboard_Settings_Repository::save( array( 'site_section_urls' => array( 'home' => array( 'nested' ) ) ) );
			w15_check( 'W15-V9', is_wp_error( $err ) && 'hal_settings_site_section_urls_invalid' === $err->get_error_code(),
				'a non-string section value is rejected at the repository (no coercion)', 'non-string section value coerced' );

			// The unknown-key guard stays closed while covering the new keys.
			$err = HAL_Frontend_Dashboard_Settings_Repository::save( array( 'hacked_key' => 'x' ) );
			w15_check( 'W15-V10', is_wp_error( $err ) && 'hal_settings_unknown_key' === $err->get_error_code(),
				'the unknown-key guard still rejects foreign keys after its v1.1.1 extension (no new shapes)', 'guard loosened' );

			// Read normalization: corrupt/oversized stored values → ''.
			$GLOBALS['W15_OPTIONS']['hal_frontend_dashboard_settings']['branding_site_title'] = str_repeat( 'z', 61 );
			$GLOBALS['W15_OPTIONS']['hal_frontend_dashboard_settings']['branding_site_tagline'] = array( 'corrupt' );
			$GLOBALS['W15_OPTIONS']['hal_frontend_dashboard_settings']['site_section_urls'] = array( 'home' => 'https://keep.test/', 'unknown_key' => 'x', 'about' => 42 );
			HAL_Frontend_Dashboard_Settings_Repository::invalidate_cache();
			$norm = HAL_Frontend_Dashboard_Settings_Repository::get_all();
			w15_check( 'W15-V11', '' === $norm['branding_site_title'] && '' === $norm['branding_site_tagline']
					&& array_keys( $norm['site_section_urls'] ) === $keys
					&& 'https://keep.test/' === $norm['site_section_urls']['home'] && '' === $norm['site_section_urls']['about'],
				'the tolerant read normalizes corrupt state fail-closed: oversized title and non-string tagline read as "" and a stored section map with an unknown key and a non-string value reads back as the closed eight with the valid value kept',
				'normalization mismatch: ' . json_encode( $norm ) );
			$GLOBALS['W15_OPTIONS']['hal_frontend_dashboard_settings']['site_section_urls'] = 'corrupt-string';
			HAL_Frontend_Dashboard_Settings_Repository::invalidate_cache();
			$norm = HAL_Frontend_Dashboard_Settings_Repository::get_all();
			w15_check( 'W15-V12', array_fill_keys( $keys, '' ) === $norm['site_section_urls'],
				'a stored non-array site_section_urls normalizes on read to the closed eight all empty (auto)', 'non-array section store read unsafely' );
			w15_result_line( $mode );
			// no fallthrough

		case 'controller-bridge':
			$GLOBALS['W15_CAN_MANAGE']  = true;
			$GLOBALS['W15_NONCE_VALID'] = true;
			$nonce = array( 'hal_frontend_dashboard_nonce' => 'fixture-nonce' );

			// The full valid branding form saves through the real handler.
			$_POST = array(
				'site_sections_form'    => '1',
				'branding_site_title'   => 'Adel & Partners',
				'branding_site_tagline' => 'Portal v2',
				'site_section_urls'     => array(
					'home' => 'https://example.test/h/', 'about' => 'https://example.test/a/',
				),
			) + $nonce;
			$redirect = w15_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_save_settings' ) );
			$after = HAL_Frontend_Dashboard_Settings_Repository::get_all();
			w15_check( 'W15-C1', false !== strpos( $redirect->target, 'hal_msg=saved' )
					&& 'Adel & Partners' === $after['branding_site_title'] && 'Portal v2' === $after['branding_site_tagline']
					&& 'https://example.test/h/' === $after['site_section_urls']['home']
					&& 'https://example.test/a/' === $after['site_section_urls']['about']
					&& '' === $after['site_section_urls']['services'],
				'the real handler saves the valid branding form: texts stored, the two posted section URLs stored, the unposted six normalized to "" (complete closed-eight rebuild)',
				'valid bridge save mismatch: ' . $redirect->target . ' — ' . json_encode( $after ) );

			// Over-ceiling through the handler: save_failed, store untouched.
			$before = w15_store();
			$_POST = array(
				'site_sections_form'  => '1',
				'branding_site_title' => str_repeat( 'a', 61 ),
			) + $nonce;
			$redirect = w15_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_save_settings' ) );
			w15_check( 'W15-C2', false !== strpos( $redirect->target, 'hal_msg=save_failed' ) && w15_store() === $before,
				'a posted 61-character title dies at the bridge (hal_msg=save_failed) with the store byte-identical (no partial write)', 'over-ceiling bridge mismatch' );
			$_POST = array(
				'site_sections_form'    => '1',
				'branding_site_tagline' => str_repeat( 'a', 81 ),
			) + $nonce;
			$redirect = w15_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_save_settings' ) );
			w15_check( 'W15-C3', false !== strpos( $redirect->target, 'hal_msg=save_failed' ) && w15_store() === $before,
				'a posted 81-character tagline dies at the bridge with the store byte-identical', 'over-ceiling tagline bridge mismatch' );

			// Array title → post_string null → save_failed (B3-07: no coercion).
			$_POST = array( 'site_sections_form' => '1', 'branding_site_title' => array( 'x' ) ) + $nonce;
			$redirect = w15_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_save_settings' ) );
			w15_check( 'W15-C4', false !== strpos( $redirect->target, 'hal_msg=save_failed' ) && w15_store() === $before,
				'an array title produces the documented save_failed without any write (request reading stays is_string-strict)', 'array title coerced at the bridge' );

			// Marker-gated section map (B3-01 pattern).
			$_POST = array( 'site_section_urls' => array( 'home' => 'https://evil.test/' ) ) + $nonce;
			$redirect = w15_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_save_settings' ) );
			$after = HAL_Frontend_Dashboard_Settings_Repository::get_all();
			w15_check( 'W15-C5', false !== strpos( $redirect->target, 'hal_msg=saved' ) && 'https://example.test/h/' === $after['site_section_urls']['home'],
				'without the site_sections_form marker a posted site_section_urls key is never touched (B3-01: only the form that owns the fields may write them)', 'marker gate broken: ' . json_encode( $after['site_section_urls'] ) );

			// Markered but malformed → rejected whole.
			$_POST = array( 'site_sections_form' => '1', 'site_section_urls' => 'not-an-array' ) + $nonce;
			$redirect = w15_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_save_settings' ) );
			w15_check( 'W15-C6', false !== strpos( $redirect->target, 'hal_msg=save_failed' ) && w15_store() === $before,
				'a markered non-array site_section_urls is rejected whole with the store byte-identical', 'non-array bridge coercion' );
			$_POST = array( 'site_sections_form' => '1', 'site_section_urls' => array( 'home' => 'https://ok.test/', 'evil_key' => 'x' ) ) + $nonce;
			$redirect = w15_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_save_settings' ) );
			w15_check( 'W15-C7', false !== strpos( $redirect->target, 'hal_msg=save_failed' ) && w15_store() === $before,
				'a markered unknown section key rejects the whole form before any read (structural tampering)', 'unknown key accepted at the bridge' );
			$_POST = array( 'site_sections_form' => '1', 'site_section_urls' => array( 'home' => array( 'nested' ) ) ) + $nonce;
			$redirect = w15_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_save_settings' ) );
			w15_check( 'W15-C8', false !== strpos( $redirect->target, 'hal_msg=save_failed' ) && w15_store() === $before,
				'a markered non-string section value is rejected explicitly (no silent coercion — B3-07)', 'non-string value coerced at the bridge' );

			// Permissive esc_url_raw leg: the CONTROLLER's own scheme gate.
			$GLOBALS['W15_PERMISSIVE_ESC'] = true;
			$_POST = array( 'site_sections_form' => '1', 'site_section_urls' => array(
				'home'    => 'javascript:alert(1)//x',
				'about'   => 'ftp://example.test/file',
				'services' => 'https://good.test/s/',
			) ) + $nonce;
			$redirect = w15_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_save_settings' ) );
			$after = HAL_Frontend_Dashboard_Settings_Repository::get_all();
			w15_check( 'W15-C9', false !== strpos( $redirect->target, 'hal_msg=saved' )
					&& '' === $after['site_section_urls']['home'] && '' === $after['site_section_urls']['about']
					&& 'https://good.test/s/' === $after['site_section_urls']['services'],
				'with the esc_url_raw boundary permissive the controller scheme gate still blanks javascript: and ftp:// to "" (fail-open to auto — corrupt becomes empty, never stored) while a valid https URL passes',
				'scheme gate mismatch: ' . json_encode( $after['site_section_urls'] ) );
			$GLOBALS['W15_PERMISSIVE_ESC'] = false;
			$_POST = array( 'site_sections_form' => '1', 'site_section_urls' => array( 'home' => 'javascript:alert(1)' ) ) + $nonce;
			$redirect = w15_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_save_settings' ) );
			$after = HAL_Frontend_Dashboard_Settings_Repository::get_all();
			w15_check( 'W15-C10', false !== strpos( $redirect->target, 'hal_msg=saved' ) && '' === $after['site_section_urls']['home'],
				'with the strict esc_url_raw boundary a javascript: URL is dropped to "" at the boundary — both layers agree on the fail-open-to-auto outcome',
				'strict boundary mismatch: ' . json_encode( $after['site_section_urls'] ) );

			// Missing title key preserves; empty title clears.
			$_POST = array( 'site_sections_form' => '1', 'branding_site_tagline' => 'Kept Portal' ) + $nonce;
			$redirect = w15_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_save_settings' ) );
			$after = HAL_Frontend_Dashboard_Settings_Repository::get_all();
			w15_check( 'W15-C11', false !== strpos( $redirect->target, 'hal_msg=saved' ) && 'Adel & Partners' === $after['branding_site_title'] && 'Kept Portal' === $after['branding_site_tagline'],
				'an absent title input preserves the stored title while the posted tagline updates (the browser-omitted field is not a clearing)', 'preserve-on-absent mismatch' );
			$_POST = array( 'site_sections_form' => '1', 'branding_site_title' => '' ) + $nonce;
			$redirect = w15_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_save_settings' ) );
			$after = HAL_Frontend_Dashboard_Settings_Repository::get_all();
			w15_check( 'W15-C12', false !== strpos( $redirect->target, 'hal_msg=saved' ) && '' === $after['branding_site_title'],
				'an explicitly empty title input clears the stored title (the empty field is the documented fallback signal)', 'clear-on-empty mismatch' );
			w15_result_line( $mode );
			// no fallthrough

		case 'render-branding':
			$GLOBALS['W15_CAN_MANAGE'] = true;
			$GLOBALS['W15_OPTIONS']['hal_frontend_dashboard_settings'] = array(
				'branding_site_title'   => 'Rendered <b>Title</b>',
				'branding_site_tagline' => 'Rendered "Tagline"',
			);
			HAL_Frontend_Dashboard_Settings_Repository::invalidate_cache();
			$_GET['tab'] = 'branding';
			ob_start();
			HAL_Frontend_Dashboard_Admin_Controller::render_page();
			$html = (string) ob_get_clean();

			// DECISION: branding-js coverage at the PHP level (see header).
			w15_check( 'W15-R1', false !== strpos( $html, 'name="site_sections_form"' ),
				'the real Branding tab carries the hidden site_sections_form marker input (the W3 gate marker)', 'marker input missing' );
			w15_check( 'W15-R2', 1 === preg_match( '/name="branding_site_title"[^>]*maxlength="60"/', $html ) || 1 === preg_match( '/maxlength="60"[^>]*name="branding_site_title"/', $html ),
				'the site-title input carries maxlength="60" (the BRANDING_SITE_TITLE_MAX contract is server-rendered, DECISION: covered here at PHP level instead of admin-settings.test.js)', 'title maxlength missing' );
			w15_check( 'W15-R3', 1 === preg_match( '/name="branding_site_tagline"[^>]*maxlength="80"/', $html ) || 1 === preg_match( '/maxlength="80"[^>]*name="branding_site_tagline"/', $html ),
				'the tagline input carries maxlength="80" (DECISION: PHP-level coverage — see header)', 'tagline maxlength missing' );
			$section_inputs = array();
			if ( preg_match_all( '/<input[^>]*name="site_section_urls\[(\w+)\]"[^>]*>/', $html, $m ) ) {
				foreach ( $m[0] as $i => $tag ) {
					$section_inputs[ $m[1][ $i ] ] = $tag;
				}
			}
			w15_check( 'W15-R5', 8 === count( $section_inputs ) && array_keys( $section_inputs ) === HAL_Frontend_Dashboard_Settings_Repository::SITE_SECTION_KEYS,
				'the Branding tab renders exactly eight site_section_urls[key] inputs in the closed key order (DECISION: PHP-level coverage — see header)', 'section inputs mismatch: ' . json_encode( array_keys( $section_inputs ) ) );
			$placeholders_ok = 8 === count( $section_inputs ) && count( array_filter( $section_inputs, static function ( string $tag ): bool {
				return false !== strpos( $tag, 'placeholder="auto"' ) && false !== strpos( $tag, 'type="url"' );
			} ) ) === 8;
			w15_check( 'W15-R6', $placeholders_ok,
				'all eight section inputs are type="url" with the "auto" placeholder (empty = automatic resolver)', 'placeholder/type mismatch' );

			// User data renders escaped (W6: values never i18n-registered).
			w15_check( 'W15-R7', false !== strpos( $html, 'value="Rendered &lt;b&gt;Title&lt;/b&gt;"' ) && false !== strpos( $html, 'value="Rendered &quot;Tagline&quot;"' )
					&& false === strpos( $html, 'Rendered <b>Title</b>' ),
				'stored user values render escaped through esc_attr only (no raw markup, no i18n registration of settings values)', 'value escaping mismatch' );

			// W2: the dashboard-page block outside any form, zero writes.
			$marker_pos = strpos( $html, 'Dashboard page id:' );
			w15_check( 'W15-R8', false !== $marker_pos,
				'the W2 read-only dashboard-page block renders in the Branding tab (page id + status visible)', 'page block missing' );
			$last_form_end = strrpos( substr( $html, 0, (int) $marker_pos ), '</form>' );
			$tail = false !== $last_form_end ? substr( $html, $last_form_end ) : '';
			$block_end = false !== strpos( $tail, 'This block is read-only.' ) ? (int) strpos( $tail, 'This block is read-only.' ) : strlen( $tail );
			$block = substr( $tail, 0, $block_end );
			w15_check( 'W15-R9', false !== $last_form_end && false === strpos( $block, '<form' ) && false === strpos( $block, 'method="post"' ),
				'the dashboard-page block sits AFTER the last closing form of the Branding tab and carries no <form and no method="post" (read-only, zero POST)', 'block inside a form or carrying a POST' );
			w15_check( 'W15-R10', false === strpos( $block, 'type="hidden"' ) && false === strpos( $html, 'name="page_id"' ) && false === strpos( $html, 'hal_frontend_dashboard_page_id' ),
				'the block carries no hidden page-id field and the option name hal_frontend_dashboard_page_id appears nowhere in the whole render (zero writes — the Installer stays the only writer)', 'page-id write surface found' );
			w15_check( 'W15-R11', false !== strpos( $html, 'status: fallback.' ) && false !== strpos( $html, 'This block is read-only.' ),
				'with no dashboard page the real helper renders the fallback status and the read-only disclosure', 'fallback status mismatch' );
			w15_result_line( $mode );
			// no fallthrough

		case 'render-page-owned':
			// Separate leg: hossam_dashboard_url() carries a per-request static
			// cache, so the owned URL must be measured in a fresh process.
			$GLOBALS['W15_CAN_MANAGE'] = true;
			$GLOBALS['W15_POSTS'][42] = new WP_Post( array( 'ID' => 42, 'post_type' => 'page', 'post_status' => 'publish' ) );
			$GLOBALS['W15_OPTIONS']['hal_frontend_dashboard_page_id'] = 42;
			$_GET['tab'] = 'branding';
			ob_start();
			HAL_Frontend_Dashboard_Admin_Controller::render_page();
			$html_owned = (string) ob_get_clean();
			w15_check( 'W15-R12', false !== strpos( $html_owned, 'status: owned.' ) && false !== strpos( $html_owned, 'href="https://site.test/dashboard-page/"' ),
				'with a published page the helper renders the owned status and the Open link through the real hossam_dashboard_url helper (read-only view of the real state)', 'owned status/url mismatch' );
			$GLOBALS['W15_POSTS'][42] = new WP_Post( array( 'ID' => 42, 'post_type' => 'page', 'post_status' => 'draft' ) );
			ob_start();
			HAL_Frontend_Dashboard_Admin_Controller::render_page();
			$html_draft = (string) ob_get_clean();
			w15_check( 'W15-R13', false !== strpos( $html_draft, 'status: fallback.' ),
				'a non-published/non-private page renders the fallback status (the same predicate hossam_dashboard_url uses)', 'draft page status mismatch' );
			w15_result_line( $mode );
			// no fallthrough

		case 'render-features':
			$GLOBALS['W15_CAN_MANAGE'] = true;
			$_GET['tab'] = 'features';
			ob_start();
			HAL_Frontend_Dashboard_Admin_Controller::render_page();
			$html = (string) ob_get_clean();

			$features = HAL_Frontend_Dashboard_Settings_Repository::FEATURES;
			$descs    = HAL_Frontend_Dashboard_Admin_Controller::FEATURE_DESCRIPTIONS;
			w15_check( 'W15-F1', 12 === count( $features ) && array_keys( $descs ) === $features,
				'FEATURE_DESCRIPTIONS is keyed by exactly the 12 FEATURES members in the same order (the closed toggle list)', 'descriptions map mismatch' );
			$missing = array();
			foreach ( $descs as $feature_key => $text ) {
				if ( false === strpos( $html, htmlspecialchars( (string) $text, ENT_QUOTES ) ) ) {
					$missing[] = $feature_key;
				}
			}
			w15_check( 'W15-F2', array() === $missing,
				'W4: the real Features tab renders a description paragraph for every key carrying a text (all 12)', 'missing descriptions: ' . json_encode( $missing ) );
			$desc_count = substr_count( $html, '<p class="description">' );
			w15_check( 'W15-F3', 17 === $desc_count,
				'the Features tab renders exactly 17 description paragraphs (12 feature texts + the 5 editor-mode literals — keys without a text render none)', 'description count mismatch: ' . $desc_count );

			$w5 = array(
				'Adds an Open-Editor button (admins) that opens the official WordPress editor in a window and syncs edits back.',
				'Shows the official WordPress block editor embedded inside the article forms (default).',
				'Uses the plain text field only — no rich editor packages are loaded.',
				'Default: embed — chosen automatically until you change it here.',
				'You can override at any time; the Rich editor checkbox must stay on for either mode.',
			);
			$w5_missing = array();
			foreach ( $w5 as $text ) {
				if ( false === strpos( $html, htmlspecialchars( $text, ENT_QUOTES ) ) ) {
					$w5_missing[] = $text;
				}
			}
			w15_check( 'W15-F4', array() === $w5_missing,
				'W5: the five literal editor-mode description strings render under the mode select, byte-identical to the contract', 'W5 literals missing: ' . json_encode( $w5_missing ) );
			$select_pos = strpos( $html, 'id="hal-rich-editor-mode"' );
			$first_desc = false !== $select_pos ? strpos( $html, '<p class="description">', $select_pos ) : false;
			w15_check( 'W15-F5', false !== $select_pos && false !== $first_desc,
				'the editor-mode descriptions sit after the select inside its cell (render-order contract: descriptions under the control)', 'description placement mismatch' );
			w15_result_line( $mode );
			// no fallthrough

		case 'template-contract':
			// Structural contract of the four W1 render positions in the real
			// template source; the behavioral fallback/replacement proof runs
			// against the REAL rendered shell in template-shell-test.php (the
			// full-shell harness owns the render).
			$src = (string) file_get_contents( $project . '/runtime/templates/dashboard.php' );
			$settings_block = strpos( $src, '$_hal_settings = HAL_Frontend_Dashboard_Settings_Repository::get_all();' );
			w15_check( 'W15-T1', false !== $settings_block,
				'W1: the template reads the settings through the real repository get_all()', 'repository read missing' );
			$full_ok = false !== strpos( $src, "\$_hal_site_title_full = '' !== \$_hal_settings['branding_site_title'] ? \$_hal_settings['branding_site_title'] : 'Hossam Adel Law Firm';" );
			$short_ok = false !== strpos( $src, "\$_hal_site_title_short = '' !== \$_hal_settings['branding_site_title'] ? \$_hal_settings['branding_site_title'] : 'Hossam Adel';" );
			$tagline_ok = false !== strpos( $src, "\$_hal_site_tagline = '' !== \$_hal_settings['branding_site_tagline'] ? \$_hal_settings['branding_site_tagline'] : 'Law Firm Portal';" );
			w15_check( 'W15-T2', $full_ok && $short_ok && $tagline_ok,
				'the three fallback literals are bound to the settings variables exactly (title/alt fallback "Hossam Adel Law Firm", sb-title "Hossam Adel", span "Law Firm Portal" — empty setting = literal)', 'fallback bindings deviated' );
			$title_pos = strpos( $src, "esc_html( \$_hal_site_title_full )" );
			$alt_pos = strpos( $src, "esc_attr( \$_hal_site_title_full )" );
			$sb_pos = strpos( $src, "esc_html( \$_hal_site_title_short )" );
			$span_pos = strpos( $src, "esc_html( \$_hal_site_tagline )" );
			w15_check( 'W15-T3', false !== $title_pos && false !== $alt_pos && false !== $sb_pos && false !== $span_pos,
				'the four render positions echo exactly the settings variables: <title> and logo alt share the full title, sb-title the short one, the span the tagline (each escaped)', 'render positions deviated' );
			$hal_count = substr_count( $src, '>HAL</div>' );
			w15_check( 'W15-T4', 2 === $hal_count,
				'B9-L1: the two constant HAL marks remain in the logo markup (hidden img-fallback + visible text fallback) — exactly 2', 'HAL marks count: ' . $hal_count );
			$override_pos = strpos( $src, '$override = $site_section_urls[ $key ]' );
			$permalink_pos = strpos( $src, 'hossam_wpml_permalink( $override )' );
			$resolver_pos = strpos( $src, 'hossam_resolve_site_section( $key' );
			w15_check( 'W15-T5', false !== $override_pos && false !== $permalink_pos && false !== $resolver_pos
					&& $override_pos < $permalink_pos && $permalink_pos < $resolver_pos,
				'W3: the fallback-sections loop reads the stored override, routes it through hossam_wpml_permalink, and only reaches the automatic resolver when the override is empty (override wins, resolver stays the fallback)', 'override loop deviated' );
			w15_check( 'W15-T6', false === strpos( $src, 'hossam_t( $_hal_site_title' ) && false === strpos( $src, "hossam_t(\$_hal_site_title" ),
				'W6: the settings values never pass through hossam_t (no i18n registration of user data)', 'user data registered in i18n' );
			w15_result_line( $mode );
			// no fallthrough
	}

	fwrite( STDERR, "Unknown W15 mode: {$mode}\n" );
	exit( 1 );
}

/* ────────────────────────────────────────────────────────────────
 * MAIN — run every subprocess mode, then report
 * ──────────────────────────────────────────────────────────────── */

function w15_run_mode( string $mode, array $args = array() ): void {
	$php     = PHP_BINARY;
	$script  = __FILE__;
	$command = hal_php_argv(
		$php,
		array( '-d', 'extension_dir=' . HAL_TEST_EXT_DIR ),
		array( 'sodium' )
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
			w15_check( 'MODE-' . strtoupper( str_replace( '-', '_', $mode ) ), false, 'subprocess could not start', 'proc_open failed' );
			return;
		}
	}
	fclose( $pipes[0] );
	$stdout = stream_get_contents( $pipes[1] );
	fclose( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	fclose( $pipes[2] );
	$code = proc_close( $proc );
	$stdout = (string) $stdout;
	$ok = 0 === $code && false !== strpos( $stdout, 'W15-VERDICT ' . $mode . ' ALL-ASSERTIONS-HELD' );
	$detail = '';
	if ( ! $ok ) {
		$detail = 'exit ' . $code . '; stdout: ' . trim( $stdout ) . '; stderr: ' . trim( (string) $stderr );
	}
	w15_check(
		'MODE-' . strtoupper( str_replace( '-', '_', $mode ) ),
		$ok,
		'subprocess mode ' . $mode . ' held all its assertions (exit 0)',
		'mode ' . $mode . ' failed — ' . $detail
	);
}

/* ── Dispatch ── */
$w15_mode_arg = isset( $argv[1] ) ? (string) $argv[1] : 'main';
if ( 'main' !== $w15_mode_arg ) {
	w15_mode( $w15_mode_arg, array_slice( $argv, 2 ), $project );
	exit( 1 );
}

w15_run_mode( 'repo-contracts' );
w15_run_mode( 'controller-bridge' );
w15_run_mode( 'render-branding' );
w15_run_mode( 'render-page-owned' );
w15_run_mode( 'render-features' );
w15_run_mode( 'template-contract' );

echo "HAL Frontend Dashboard — B15 branding settings test (v1.1.1 W1–W5)\n";
echo str_repeat( '─', 72 ) . "\n";
$pass_count = 0;
foreach ( $GLOBALS['W15_RESULTS'] as $result ) {
	if ( $result['ok'] ) {
		$pass_count++;
		echo "PASS [{$result['id']}] {$result['pass']}\n";
	} else {
		echo "FAIL [{$result['id']}] {$result['fail']}\n";
	}
}
echo str_repeat( '─', 72 ) . "\n";
$total = count( $GLOBALS['W15_RESULTS'] );
echo "RESULT: {$pass_count}/{$total} checks passed\n";
exit( $pass_count === $total ? 0 : 1 );
