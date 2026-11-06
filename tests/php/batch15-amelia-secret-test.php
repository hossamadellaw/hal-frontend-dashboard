<?php
/**
 * HAL Frontend Dashboard — B15 (v1.1.1) tagged-addition harness: Amelia
 * secret surface & integration capability alignment (E3).
 *
 * Local, isolated harness: no live WordPress, no network, no database.
 * Loads the REAL runtime/bootstrap.php and drives the REAL
 * handle_set_secret / handle_delete_secret / render_secret_block /
 * hossam_get_integration_capabilities — only WordPress itself is
 * stubbed. No project logic is reimplemented in the harness.
 *
 * Coverage (v1.1.1 E3 — tagged addition; this file ADDS coverage and
 * touches no existing suite contract):
 *
 *   TAB       — secret_redirect_tab through the real handlers: an absent
 *               secret_tab POST keeps the documented TAB_AI fallback
 *               (backward compatibility with the legacy AI-tab forms);
 *               each allowlisted tab echoes itself (general/branding/
 *               features/integrations/ai/status); hostile values
 *               ('../../ai', 'AI%00', 'weird') and an array POST land on
 *               TAB_AI (sanitize_key + the six-tab allowlist — no free
 *               context reaches redirect_back). The delete handler obeys
 *               the same contract.
 *   PROBE     — after a SUCCESSFUL store touch the mandatory immediate
 *               probe runs: the Registry capabilities transient
 *               (hossam_integration_registry_key) is DELETED (the
 *               hossam_invalidate_integration_capabilities path) and
 *               Integration_Health_Monitor::run_checks() writes the
 *               sanitized status option — both proven through the real
 *               harness option store on set AND delete. Early-reject
 *               paths (bad nonce → forbidden, unknown id →
 *               unknown_secret) never run the probe: a seeded transient
 *               survives and the status option is untouched.
 *   STALE GAP — the motivation is measured: a seeded STALE registry
 *               transient (elite_api_secret=false while a credential now
 *               exists) keeps answering false through the cached reader
 *               until the probe deletes it — after invalidation the same
 *               reader reports the fresh state.
 *   ALIGN     — setup.php :252–262 alignment: with no credential the
 *               amelia capability elite_api_secret is false; after an
 *               ENCRYPTED store set (hal_encrypted source through the
 *               Registry credential_source) it is true — and false again
 *               after the encrypted record is deleted; an external
 *               HOSSAM_AMELIA_API_KEY constant outranks and keeps it
 *               true (env→constant→hal_encrypted precedence reflected by
 *               the capability, not the constant-only legacy probe).
 *   RENDER    — the Integrations tab renders the Amelia secret block
 *               from the Registry with the hidden secret_tab field set
 *               to "integrations" in BOTH its set and delete forms; the
 *               AI tab renders its block with the field valued "ai"
 *               (the real AI call site passes self::TAB_AI explicitly —
 *               the legacy absent-field fallback contract is proven at
 *               the handler level instead); no stored secret value ever
 *               appears in either tab's HTML.
 *
 * Usage:  php tests/php/batch15-amelia-secret-test.php            (main)
 *         php tests/php/batch15-amelia-secret-test.php <mode>     (subprocess)
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

$GLOBALS['A15_HOOKS']       = array();
$GLOBALS['A15_ACTIONS']     = array();
$GLOBALS['A15_OPTIONS']     = array();
$GLOBALS['A15_RESULTS']     = array();
$GLOBALS['A15_CAN_MANAGE']  = false;
$GLOBALS['A15_NONCE_VALID'] = false;

function a15_check( string $id, bool $condition, string $pass, string $fail = '' ): void {
	$GLOBALS['A15_RESULTS'][] = array( 'id' => $id, 'ok' => $condition, 'pass' => $pass, 'fail' => $fail );
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook_name, $callback = '', int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['A15_HOOKS'][ $hook_name ][] = array( 'callback' => $callback, 'priority' => $priority );
		return true;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook_name, $callback = '', int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['A15_HOOKS'][ $hook_name ][] = array( 'callback' => $callback, 'priority' => $priority );
		return true;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook_name, ...$args ): void {
		$GLOBALS['A15_ACTIONS'][ $hook_name ] = ( $GLOBALS['A15_ACTIONS'][ $hook_name ] ?? 0 ) + 1;
		foreach ( $GLOBALS['A15_HOOKS'][ $hook_name ] ?? array() as $entry ) {
			call_user_func_array( $entry['callback'], $args );
		}
	}
}
if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook_name ): int {
		return $GLOBALS['A15_ACTIONS'][ $hook_name ] ?? 0;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook_name, $value, ...$args ) {
		foreach ( $GLOBALS['A15_HOOKS'][ $hook_name ] ?? array() as $entry ) {
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
if ( ! function_exists( 'submit_button' ) ) {
	function submit_button( $text = null, $type = 'primary', $name = 'submit', $wrap = true, $other_attributes = array() ): void {
		echo '<button type="submit">' . htmlspecialchars( (string) $text, ENT_QUOTES ) . '</button>';
	}
}
if ( ! function_exists( 'disabled' ) ) {
	function disabled( $disabled, $current = true, $display = true ): string {
		$output = $display && (bool) $disabled ? 'disabled="disabled" ' : '';
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
		return $GLOBALS['A15_NONCE_VALID'] && 'fixture-nonce' === $nonce ? 1 : false;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability ): bool {
		return $GLOBALS['A15_CAN_MANAGE'] && HAL_Frontend_Dashboard_Settings_Repository::CAPABILITY === $capability;
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
if ( ! class_exists( 'A15_Redirect' ) ) {
	/**
	 * Faithful emulation: real wp_safe_redirect() is followed by exit in
	 * the controller, so the stub unwinds with an exception that carries
	 * the target instead of ending the process.
	 */
	class A15_Redirect extends Exception {
		public string $target;
		public function __construct( string $target ) {
			parent::__construct( 'a15 redirect' );
			$this->target = $target;
		}
	}
}
if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( string $location, int $status = 302 ): bool {
		throw new A15_Redirect( $location );
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
		return array_key_exists( $option, $GLOBALS['A15_OPTIONS'] ) ? $GLOBALS['A15_OPTIONS'][ $option ] : $default_value;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, $value, $autoload = null ): bool {
		$GLOBALS['A15_OPTIONS'][ $option ] = $value;
		$GLOBALS['A15_OPTION_WRITE_LOG'][ $option ] = ( $GLOBALS['A15_OPTION_WRITE_LOG'][ $option ] ?? 0 ) + 1;
		return true;
	}
}
if ( ! function_exists( 'a15_option_writes' ) ) {
	/** Counts the real update_option writes of one option (clock-free probe proof). */
	function a15_option_writes( string $option ): int {
		return (int) ( $GLOBALS['A15_OPTION_WRITE_LOG'][ $option ] ?? 0 );
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
		unset( $GLOBALS['A15_OPTIONS'][ '_transient_' . $key ] );
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
		return 'a15-salt-' . $scheme;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0 ) {
		return json_encode( $value, $flags );
	}
}
// تفويض المالك #1 (2026-11-02): R29 — stub دالة نواة ناقصة بنمط الـharness
// (settings-and-secrets-test.php:314): settings_save ينادي hossam_audit_log
// الحقيقي الذي يكتب في جدول DB لا يحاكيه هذا الـharness.
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
		return null;
	}
}
if ( ! function_exists( 'get_post_mime_type' ) ) {
	function get_post_mime_type( $id ): string {
		return '';
	}
}
if ( ! function_exists( 'wp_attachment_is_image' ) ) {
	function wp_attachment_is_image( $id ): bool {
		return false;
	}
}
if ( ! function_exists( 'wp_enqueue_media' ) ) {
	function wp_enqueue_media( array $args = array() ): void {}
}
if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( string $handle, string $src = '', array $deps = array(), $ver = false ): void {}
}
if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( string $handle, string $src = '', array $deps = array(), $ver = false, bool $in_footer = false ): void {}
}
if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( string $handle, string $object_name, array $l10n ): bool {
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
		public string $post_type = 'attachment';
	}
}

/* ────────────────────────────────────────────────────────────────
 * Fixture helpers
 * ──────────────────────────────────────────────────────────────── */

function a15_define_release_context(): void {
	if ( ! defined( 'ABSPATH' ) ) {
		$abspath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'a15-' . getmypid() . DIRECTORY_SEPARATOR;
		if ( ! is_dir( $abspath ) ) {
			@mkdir( $abspath, 0777, true );
		}
		$wp_admin_includes = $abspath . 'wp-admin' . DIRECTORY_SEPARATOR . 'includes';
		if ( ! is_dir( $wp_admin_includes ) ) {
			@mkdir( $wp_admin_includes, 0777, true );
		}
		$plugin_include = $wp_admin_includes . DIRECTORY_SEPARATOR . 'plugin.php';
		if ( ! file_exists( $plugin_include ) ) {
			file_put_contents( $plugin_include, "<?php\nfunction is_plugin_active( string \$plugin ): bool { return false; }\nfunction get_plugin_data( string \$file, bool \$markup = true, bool \$translate = true ): array { return array( 'Version' => '' ); }\n" );
		}
		define( 'ABSPATH', $abspath );
	}
	if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
		define( 'WP_PLUGIN_DIR', ABSPATH . 'wp-content/plugins' );
	}
	if ( ! defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT' ) ) {
		define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT', $GLOBALS['A15_PROJECT'] . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR );
		define( 'HAL_FRONTEND_DASHBOARD_RELEASE_ID', '3.0.0+' . str_repeat( 'a5', 20 ) );
		define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION', '3.0.0' );
		define(
			'HAL_FRONTEND_DASHBOARD_RUNTIME_URL',
			'https://example.test/releases/' . HAL_FRONTEND_DASHBOARD_RELEASE_ID . '/'
		);
	}
}

function a15_require_runtime(): void {
	require $GLOBALS['A15_PROJECT'] . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'bootstrap.php';
}

function a15_result_line( string $mode ): void {
	$failed = array();
	foreach ( $GLOBALS['A15_RESULTS'] as $result ) {
		if ( ! $result['ok'] ) {
			$failed[] = array( 'id' => $result['id'], 'fail' => $result['fail'] );
		}
	}
	$payload = array( 'mode' => $mode, 'php' => PHP_VERSION );
	if ( array() !== $failed ) {
		$payload['failed'] = $failed;
	}
	echo 'A15-RESULT ' . json_encode( $payload, JSON_UNESCAPED_SLASHES ), PHP_EOL;
	$all_ok = array() === $failed;
	echo 'A15-VERDICT ' . $mode . ' ' . ( $all_ok ? 'ALL-ASSERTIONS-HELD' : 'ASSERTIONS-FAILED' ), PHP_EOL;
	exit( $all_ok ? 0 : 1 );
}

/** Renders one handler call and captures the redirect signal. */
function a15_capture_redirect( callable $handler ): A15_Redirect {
	try {
		$handler();
	} catch ( A15_Redirect $redirect ) {
		return $redirect;
	}
	throw new RuntimeException( 'handler did not redirect' );
}

function a15_registry_key(): string {
	return hossam_integration_registry_key();
}

function a15_seed_registry_transient( bool $elite_api_secret ): void {
	set_transient( a15_registry_key(), array(
		'amelia' => array(
			'status'        => 'available',
			'plugin_active' => true,
			'version'       => '7.0-a15',
			'capabilities'  => array( 'bookings_shortcode' => true, 'elite_api_secret' => $elite_api_secret, 'elite_api_client' => true ),
			'reason'        => '',
			'checked_at'    => '2026-11-04T00:00:00Z',
		),
	), 5 * MINUTE_IN_SECONDS );
}

function a15_transient_alive(): bool {
	return is_array( get_transient( a15_registry_key() ) );
}

/* ────────────────────────────────────────────────────────────────
 * Subprocess modes — real bootstrap + real classes
 * ──────────────────────────────────────────────────────────────── */

function a15_mode( string $mode, array $args, string $project ): void {
	$GLOBALS['A15_PROJECT'] = $project;
	a15_define_release_context();
	if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
		define( 'MINUTE_IN_SECONDS', 60 );
		define( 'HOUR_IN_SECONDS', 3600 );
	}
	if ( 'failure-reasons' !== $mode ) {
		define( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY', hash( 'sha256', 'a15-key-' . $mode ) );
	}
	a15_require_runtime();
	$GLOBALS['A15_CAN_MANAGE']  = true;
	$GLOBALS['A15_NONCE_VALID'] = true;

	switch ( $mode ) {
		case 'failure-reasons':
			// v1.1.2: with the store blocked (no key constant is defined
			// for this mode) the handler reports the dedicated key reason
			// while the six-tab redirect allowlist behavior stays intact.
			$GLOBALS['A15_CAN_MANAGE']  = true;
			$GLOBALS['A15_NONCE_VALID'] = true;
			$_POST = array( 'secret_id' => 'ai_direct_key', 'secret_value' => 'A15-PLAINTEXT-VALUE', 'secret_tab' => 'integrations', 'hal_frontend_dashboard_nonce' => 'fixture-nonce' );
			$redirect = a15_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_set_secret' ) );
			a15_check( 'A15-N1', false !== strpos( $redirect->target, 'hal_msg=secret_failed_key' ) && false !== strpos( $redirect->target, 'tab=integrations&' ), 'a blocked store lands on the dedicated key reason and keeps the allowlisted tab redirect', 'redirect mismatch: ' . $redirect->target );
			$_POST = array( 'secret_id' => 'ai_direct_key', 'secret_value' => 'A15-PLAINTEXT-VALUE', 'hal_frontend_dashboard_nonce' => 'fixture-nonce' );
			$redirect = a15_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_set_secret' ) );
			a15_check( 'A15-N2', false !== strpos( $redirect->target, 'hal_msg=secret_failed_key' ) && false !== strpos( $redirect->target, 'tab=ai&' ), 'without a secret_tab the blocked-store failure keeps the TAB_AI fallback with the dedicated key reason', 'redirect mismatch: ' . $redirect->target );
			a15_result_line( $mode );
			// no fallthrough

		case 'redirect-tab':
			// secret_redirect_tab through the REAL handle_set_secret: each
			// case performs a real set (the store IS touched on the allow-
			// list path) and the redirect carries the expected tab.
			$cases = array(
				'absent'         => null,
				'ai'             => 'ai',
				'general'        => 'general',
				'branding'       => 'branding',
				'features'       => 'features',
				'integrations'   => 'integrations',
				'status'         => 'status',
				'traversal'      => '../../ai',
				'null-byte'      => 'AI%00',
				'unknown-value'  => 'weird_tab',
				'array-post'     => array( 'integrations' ),
			);
			foreach ( $cases as $case_id => $tab_value ) {
				$_POST = array(
					'secret_id'    => 'amelia_elite_api_key',
					'secret_value' => 'A15-VALUE-' . $case_id,
					'hal_frontend_dashboard_nonce' => 'fixture-nonce',
				);
				if ( null !== $tab_value ) {
					$_POST['secret_tab'] = $tab_value;
				}
				$redirect = a15_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_set_secret' ) );
				$expected_tab = in_array( $case_id, array( 'ai', 'general', 'branding', 'features', 'integrations', 'status' ), true ) ? $case_id : 'ai';
				a15_check(
					'A15-T-' . $case_id,
					false !== strpos( $redirect->target, 'hal_msg=secret_saved' ) && false !== strpos( $redirect->target, 'tab=' . $expected_tab . '&' ),
					"set redirect lands on tab={$expected_tab} with secret_saved for the secret_tab case '{$case_id}' (sanitize_key + the six-tab allowlist; absent/foreign/array values keep the TAB_AI fallback)",
					"case {$case_id} redirect mismatch: {$redirect->target}"
				);
			}
			// The delete handler obeys the same contract.
			HAL_Frontend_Dashboard_Secret_Store::set( 'amelia_elite_api_key', 'A15-TO-DELETE' );
			$_POST = array(
				'secret_id'    => 'amelia_elite_api_key',
				'secret_tab'   => 'integrations',
				'hal_frontend_dashboard_nonce' => 'fixture-nonce',
			);
			$redirect = a15_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_delete_secret' ) );
			a15_check( 'A15-T-delete-tab', false !== strpos( $redirect->target, 'hal_msg=secret_deleted' ) && false !== strpos( $redirect->target, 'tab=integrations&' ),
				'the delete redirect carries the source tab (secret_tab=integrations) with secret_deleted', 'delete redirect mismatch: ' . $redirect->target );
			$_POST = array(
				'secret_id'    => 'amelia_elite_api_key',
				'hal_frontend_dashboard_nonce' => 'fixture-nonce',
			);
			HAL_Frontend_Dashboard_Secret_Store::set( 'amelia_elite_api_key', 'A15-TO-DELETE-2' );
			$redirect = a15_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_delete_secret' ) );
			a15_check( 'A15-T-delete-absent', false !== strpos( $redirect->target, 'tab=ai&' ),
				'an absent secret_tab on delete keeps the documented TAB_AI fallback (legacy AI-tab forms unchanged)', 'delete fallback mismatch: ' . $redirect->target );
			a15_result_line( $mode );
			// no fallthrough

		case 'probe':
			// 1) Stale-gap motivation: a seeded stale transient keeps the
			//    cached reader answering false while a credential exists.
			a15_seed_registry_transient( false );
			HAL_Frontend_Dashboard_Secret_Store::set( 'amelia_elite_api_key', 'A15-PROBE-VALUE' );
			$cached = hossam_get_integration_capabilities();
			a15_check( 'A15-P1-stale', false === $cached['amelia']['capabilities']['elite_api_secret'],
				'the stale-gap is real without the probe: a cached registry transient answers elite_api_secret=false even though an encrypted credential now exists (TTL window the probe closes)',
				'stale cache unexpectedly fresh' );

			// 2) Successful set → transient deleted + run_checks wrote status.
			a15_seed_registry_transient( false );
			unset( $GLOBALS['A15_OPTIONS']['hal_frontend_dashboard_integration_health'] );
			$_POST = array(
				'secret_id'    => 'amelia_elite_api_key',
				'secret_value' => 'A15-PROBE-SET',
				'hal_frontend_dashboard_nonce' => 'fixture-nonce',
			);
			$redirect = a15_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_set_secret' ) );
			a15_check( 'A15-P2-invalidate', false !== strpos( $redirect->target, 'hal_msg=secret_saved' ) && ! a15_transient_alive(),
				'after a successful set the mandatory probe deleted the Registry capabilities transient (the hossam_invalidate_integration_capabilities delete_transient path ran)',
				'transient survived the set probe: ' . $redirect->target );
			$status = get_option( 'hal_frontend_dashboard_integration_health', null );
			a15_check( 'A15-P3-health-run', is_array( $status ) && isset( $status['_meta']['key_source'], $status['amelia']['status'] ),
				'after a successful set the mandatory probe ran the real Integration_Health_Monitor::run_checks() — the sanitized status option carries _meta and the amelia status',
				'status option missing or malformed: ' . json_encode( is_array( $status ) ? array_keys( $status ) : $status ) );
			a15_check( 'A15-P4-no-plaintext', false === strpos( json_encode( (array) $status ), 'A15-PROBE' ),
				'the probe status store carries codes only — no decrypted value', 'value leaked into status' );

			// 3) Successful delete → same probe contract.
			a15_seed_registry_transient( true );
			$writes_before_delete = a15_option_writes( 'hal_frontend_dashboard_integration_health' );
			$_POST = array(
				'secret_id'    => 'amelia_elite_api_key',
				'hal_frontend_dashboard_nonce' => 'fixture-nonce',
			);
			$redirect = a15_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_delete_secret' ) );
			a15_check( 'A15-P5-delete-probe', false !== strpos( $redirect->target, 'hal_msg=secret_deleted' ) && ! a15_transient_alive(),
				'after a successful delete the probe deleted the freshly re-seeded transient (the delete handler carries the same mandatory probe)',
				'transient survived the delete probe: ' . $redirect->target );
			a15_check( 'A15-P6-delete-health-rerun', a15_option_writes( 'hal_frontend_dashboard_integration_health' ) > $writes_before_delete,
				'the delete probe re-ran run_checks — the real status option was written again by the health job (write-count proof, clock-free)',
				'run_checks did not re-run on delete' );

			// 4) Early-reject paths never probe.
			a15_seed_registry_transient( true );
			unset( $GLOBALS['A15_OPTIONS']['hal_frontend_dashboard_integration_health'] );
			$_POST = array(
				'secret_id'    => 'free_key_name',
				'secret_value' => 'x',
				'hal_frontend_dashboard_nonce' => 'fixture-nonce',
			);
			$redirect = a15_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_set_secret' ) );
			a15_check( 'A15-P7-early-reject-no-probe', false !== strpos( $redirect->target, 'hal_msg=unknown_secret' ) && a15_transient_alive()
					&& null === get_option( 'hal_frontend_dashboard_integration_health', null ),
				'an unknown secret id rejects before the store is touched: the seeded transient survives and run_checks never ran (early-reject paths carry no probe)',
				'early reject ran the probe: ' . $redirect->target );
			$_POST = array(
				'secret_id'    => 'amelia_elite_api_key',
				'secret_value' => 'x',
				'hal_frontend_dashboard_nonce' => 'wrong-nonce',
			);
			$redirect = a15_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_set_secret' ) );
			a15_check( 'A15-P8-nonce-reject-no-probe', false !== strpos( $redirect->target, 'hal_msg=forbidden' ) && a15_transient_alive(),
				'a bad nonce rejects before any store touch: the transient survives (no probe on the forbidden path)', 'forbidden path probed: ' . $redirect->target );

			// 5) After invalidation the reader reports the fresh state.
			HAL_Frontend_Dashboard_Secret_Store::set( 'amelia_elite_api_key', 'A15-FRESH' );
			hossam_invalidate_integration_capabilities();
			$fresh = hossam_get_integration_capabilities();
			a15_check( 'A15-P9-fresh-after-invalidate', true === $fresh['amelia']['capabilities']['elite_api_secret'],
				'after the probe-style invalidation the registry reader rebuilds and reports elite_api_secret=true for the now-existing encrypted credential (the gap the E3 probe closes)',
				'fresh rebuild mismatch: ' . json_encode( $fresh['amelia']['capabilities'] ?? array() ) );
			a15_result_line( $mode );
			// no fallthrough

		case 'setup-alignment':
			// E3 alignment: the capability mirrors the RESOLVER's accepted
			// credential source (env→constant→hal_encrypted), not the
			// constant-only legacy probe.
			$caps = hossam_get_integration_capabilities();
			a15_check( 'A15-A1-none', false === $caps['amelia']['capabilities']['elite_api_secret'],
				'with no credential source at all the amelia capability elite_api_secret is false (fail-closed default)', 'no-credential capability mismatch' );
			a15_check( 'A15-A2-client', true === $caps['amelia']['capabilities']['elite_api_client'],
				'the loaded batch-4 adapter keeps elite_api_client true (the client capability is orthogonal to the credential)', 'client capability mismatch' );

			HAL_Frontend_Dashboard_Secret_Store::set( 'amelia_elite_api_key', 'A15-ENCRYPTED-ALIGN' );
			hossam_invalidate_integration_capabilities();
			$caps = hossam_get_integration_capabilities();
			a15_check( 'A15-A3-hal-encrypted', true === $caps['amelia']['capabilities']['elite_api_secret']
					&& 'hal_encrypted' === HAL_Frontend_Dashboard_Integration_Settings_Registry::credential_source( 'amelia_elite_api_key' ),
				'an ENCRYPTED stored credential alone flips elite_api_secret to true through the Registry credential_source (the constant-only legacy probe could never see this source)',
				'hal_encrypted alignment mismatch' );

			HAL_Frontend_Dashboard_Secret_Store::delete( 'amelia_elite_api_key' );
			hossam_invalidate_integration_capabilities();
			$caps = hossam_get_integration_capabilities();
			a15_check( 'A15-A4-deleted', false === $caps['amelia']['capabilities']['elite_api_secret']
					&& 'none' === HAL_Frontend_Dashboard_Integration_Settings_Registry::credential_source( 'amelia_elite_api_key' ),
				'deleting the encrypted record flips elite_api_secret back to false (the capability tracks the source both ways)',
				'post-delete alignment mismatch' );

			define( 'HOSSAM_AMELIA_API_KEY', 'A15-CONSTANT-CRED' );
			hossam_invalidate_integration_capabilities();
			$caps = hossam_get_integration_capabilities();
			a15_check( 'A15-A5-constant', true === $caps['amelia']['capabilities']['elite_api_secret']
					&& 'constant' === HAL_Frontend_Dashboard_Integration_Settings_Registry::credential_source( 'amelia_elite_api_key' ),
				'an external constant credential keeps elite_api_secret true with source constant (the legacy fallback contract intact for external sources)',
				'constant alignment mismatch' );
			a15_result_line( $mode );
			// no fallthrough

		case 'render-blocks':
			$GLOBALS['A15_CAN_MANAGE'] = true;
			HAL_Frontend_Dashboard_Secret_Store::set( 'amelia_elite_api_key', 'A15-RENDER-SECRET' );
			$_GET['tab'] = 'integrations';
			ob_start();
			HAL_Frontend_Dashboard_Admin_Controller::render_page();
			$html_integrations = (string) ob_get_clean();
			a15_check( 'A15-R1', false !== strpos( $html_integrations, 'data-hal-secret="amelia_elite_api_key"' ),
				'the Integrations tab renders the Amelia secret block from the Registry (the E3 UI entry exists)', 'amelia block missing' );
			$block_start = strpos( $html_integrations, 'data-hal-secret="amelia_elite_api_key"' );
			$block_end   = strpos( $html_integrations, 'hal-fd-secret', $block_start + 10 );
			$block = false !== $block_end ? substr( $html_integrations, $block_start, $block_end - $block_start ) : substr( $html_integrations, $block_start );
			$hidden_count = substr_count( $block, 'name="secret_tab"' );
			$set_form_value  = 1 === preg_match( '/name="action" value="hal_frontend_dashboard_set_secret"[^>]*>[\s\S]*?name="secret_tab" value="integrations"/', $block )
				|| 1 === preg_match( '/name="secret_tab" value="integrations"/', $block );
			a15_check( 'A15-R2', 2 === $hidden_count && $set_form_value,
				'the Amelia secret block carries the hidden secret_tab field in BOTH its set and delete forms with value "integrations" (the redirect returns to the source tab)', 'secret_tab hidden fields mismatch: count=' . $hidden_count );
			a15_check( 'A15-R3', false === strpos( $html_integrations, 'A15-RENDER-SECRET' ),
				'no stored secret value appears in the rendered Integrations tab', 'plaintext in HTML' );

			$_GET['tab'] = 'ai';
			ob_start();
			HAL_Frontend_Dashboard_Admin_Controller::render_page();
			$html_ai = (string) ob_get_clean();
			a15_check( 'A15-R4', false !== strpos( $html_ai, 'data-hal-secret="ai_direct_key"' )
					&& false !== strpos( $html_ai, 'name="secret_tab" value="ai"' ),
				'the AI tab renders its secret block with the hidden secret_tab field valued "ai" (the real AI call site passes self::TAB_AI explicitly; the absent-field legacy fallback is still proven at the handler level by A15-T-absent/A15-T-delete-absent)',
				'AI tab field mismatch' );
			a15_result_line( $mode );
			// no fallthrough
	}

	fwrite( STDERR, "Unknown A15 mode: {$mode}\n" );
	exit( 1 );
}

/* ────────────────────────────────────────────────────────────────
 * MAIN — run every subprocess mode, then report
 * ──────────────────────────────────────────────────────────────── */

function a15_run_mode( string $mode, array $args = array() ): void {
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
			a15_check( 'MODE-' . strtoupper( str_replace( '-', '_', $mode ) ), false, 'subprocess could not start', 'proc_open failed' );
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
	$ok = 0 === $code && false !== strpos( $stdout, 'A15-VERDICT ' . $mode . ' ALL-ASSERTIONS-HELD' );
	$detail = '';
	if ( ! $ok ) {
		$detail = 'exit ' . $code . '; stdout: ' . trim( $stdout ) . '; stderr: ' . trim( (string) $stderr );
	}
	a15_check(
		'MODE-' . strtoupper( str_replace( '-', '_', $mode ) ),
		$ok,
		'subprocess mode ' . $mode . ' held all its assertions (exit 0)',
		'mode ' . $mode . ' failed — ' . $detail
	);
}

/* ── Dispatch ── */
$a15_mode_arg = isset( $argv[1] ) ? (string) $argv[1] : 'main';
if ( 'main' !== $a15_mode_arg ) {
	a15_mode( $a15_mode_arg, array_slice( $argv, 2 ), $project );
	exit( 1 );
}

a15_run_mode( 'redirect-tab' );
a15_run_mode( 'probe' );
a15_run_mode( 'setup-alignment' );
a15_run_mode( 'render-blocks' );
a15_run_mode( 'failure-reasons' );

echo "HAL Frontend Dashboard — B15 Amelia secret test (v1.1.1 E3)\n";
echo str_repeat( '─', 72 ) . "\n";
$pass_count = 0;
foreach ( $GLOBALS['A15_RESULTS'] as $result ) {
	if ( $result['ok'] ) {
		$pass_count++;
		echo "PASS [{$result['id']}] {$result['pass']}\n";
	} else {
		echo "FAIL [{$result['id']}] {$result['fail']}\n";
	}
}
echo str_repeat( '─', 72 ) . "\n";
$total = count( $GLOBALS['A15_RESULTS'] );
echo "RESULT: {$pass_count}/{$total} checks passed\n";
exit( $pass_count === $total ? 0 : 1 );
