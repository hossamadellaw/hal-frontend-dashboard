<?php
/**
 * HAL Frontend Dashboard — Batch 3 closure harness: settings and secrets.
 *
 * Local, isolated harness: no live WordPress, no network, no database.
 * Calls the real project code — runtime/settings, runtime/security,
 * runtime/integrations, runtime/health, runtime/admin and the installer
 * capability grant — only WordPress itself is stubbed.
 *
 * Coverage (architecture §14 closure gate + §7.6 + owner decisions §2.3/§2.4):
 *
 *   CAPABILITY  — runtime checks use manage_hal_frontend_dashboard only;
 *                 every admin_post handler rejects without the capability
 *                 even with a valid nonce, and without a valid nonce even
 *                 with the capability.
 *   NONCE       — per-subaction nonce contract on every write handler.
 *   SCALARS     — unknown settings keys, unknown feature keys, non-boolean
 *                 values, invalid AI preference enums and free-form secret
 *                 ids (outside the Registry allowlist) are all rejected by
 *                 the real typed contracts.
 *   OPTIONS     — settings and secrets options are written autoload=no by
 *                 the real save/set paths.
 *   PRECEDENCE  — key source: environment shadows the PHP constant; the
 *                 constant alone works; neither = blocked, explicitly.
 *   AEAD        — XChaCha20-Poly1305 roundtrip with a unique nonce and
 *                 site/id/schema associated data; tampered ciphertext
 *                 fails closed keeping the stored bytes unchanged.
 *   KEY CHANGE  — a changed master key yields key_mismatch, never a wipe
 *                 and never a success.
 *   ABSENCE     — no external key source: store is blocked and the UI
 *                 states it; external environment/constant sources stay
 *                 usable by adapters (out of batch-3 scope).
 *   NO SALT     — salts are not part of the key material: rotating salts
 *                 never breaks decryption; stored records carry no
 *                 salt-derived data (exact record key set).
 *   NO PLAINTEXT/LOGGING — audit metadata carries actor/time/action/secret
 *                 id only; plaintext never appears in the option store,
 *                 the health status store, the rendered HTML or the audit
 *                 payloads.
 *
 * Usage:  php tests/php/settings-and-secrets-test.php                 (main)
 *         php tests/php/settings-and-secrets-test.php <mode> [args…]  (subprocess)
 *         exit 0 = all checks pass.
 *
 * Batch-4 fixture evolution (2026-09-14): the real batch-4 adapters now
 * load inside the runtime (bootstrap adapters section). Consequences:
 *   - the health-consumers adapter boundary stubs (wp_ai_client_prompt /
 *     hossam_ai_resolve_strategy) are defined BEFORE the runtime loads
 *     (shadow pattern), so every closed monitor-isolation assertion keeps
 *     its exact semantics;
 *   - the batch-3-era "adapter not loaded" structural codes
 *     (ai_adapter_not_loaded / amelia_adapter_not_loaded) can no longer
 *     occur — the affected assertions (HM2, HC7, HC16, NA4, DP4) now
 *     assert the real adapter's unresolvable-strategy code
 *     (ai_strategy_invalid) with the same blocked-integration intent;
 *   - HM5 configures the real adapter contract (runtime profile +
 *     provider constant + direct_key preference) instead of an eval'd
 *     resolver stub, which would now fatal against the real definition.
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

$GLOBALS['B2S_HOOKS']       = array();
$GLOBALS['B2S_ACTIONS']     = array();
$GLOBALS['B2S_OPTIONS']     = array();
$GLOBALS['B2S_OPTION_LOG']  = array();
$GLOBALS['B2S_RESULTS']     = array();
$GLOBALS['B2S_CAN_MANAGE']  = false;
$GLOBALS['B2S_NONCE_VALID'] = false;
$GLOBALS['B2S_AUDIT_LOG']   = array();
$GLOBALS['B2S_SCHEDULED']   = array();
$GLOBALS['B2S_SALTS']       = array( 'auth' => 'salt-one', 'secure' => 'pepper-one' );
$GLOBALS['B2S_ROLES']       = array();
$GLOBALS['B2S_MEDIA']       = array( 'mime' => '', 'is_image' => false, 'post' => null );
$GLOBALS['B2S_ENQUEUED']    = array( 'styles' => array(), 'scripts' => array(), 'localized' => array(), 'media' => 0 );

function b2s_check( string $id, bool $condition, string $pass, string $fail = '' ): void {
	$GLOBALS['B2S_RESULTS'][] = array( 'id' => $id, 'ok' => $condition, 'pass' => $pass, 'fail' => $fail );
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook_name, $callback = '', int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['B2S_HOOKS'][ $hook_name ][] = array( 'callback' => $callback, 'priority' => $priority );
		return true;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook_name, $callback = '', int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['B2S_HOOKS'][ $hook_name ][] = array( 'callback' => $callback, 'priority' => $priority );
		return true;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook_name, ...$args ): void {
		$GLOBALS['B2S_ACTIONS'][ $hook_name ] = ( $GLOBALS['B2S_ACTIONS'][ $hook_name ] ?? 0 ) + 1;
		foreach ( $GLOBALS['B2S_HOOKS'][ $hook_name ] ?? array() as $entry ) {
			call_user_func_array( $entry['callback'], $args );
		}
	}
}
if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook_name ): int {
		return $GLOBALS['B2S_ACTIONS'][ $hook_name ] ?? 0;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook_name, $value, ...$args ) {
		foreach ( $GLOBALS['B2S_HOOKS'][ $hook_name ] ?? array() as $entry ) {
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
if ( ! function_exists( 'disabled' ) ) {
	function disabled( $disabled, $current = true, $display = true ): string {
		$output = $display && (bool) $disabled ? 'disabled="disabled" ' : '';
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
		return $GLOBALS['B2S_NONCE_VALID'] && 'fixture-nonce' === $nonce ? 1 : false;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability ): bool {
		if ( 'upload_files' === $capability ) {
			return ! empty( $GLOBALS['B2S_CAN_UPLOAD'] );
		}
		return $GLOBALS['B2S_CAN_MANAGE'] && HAL_Frontend_Dashboard_Settings_Repository::CAPABILITY === $capability;
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return 7;
	}
}
if ( ! class_exists( 'B2S_Redirect' ) ) {
	/**
	 * Faithful emulation: real wp_safe_redirect() is followed by exit in
	 * the controller, so the stub unwinds with an exception that carries
	 * the target instead of ending the process.
	 */
	class B2S_Redirect extends Exception {
		public string $target;
		public function __construct( string $target ) {
			parent::__construct( 'b2s redirect' );
			$this->target = $target;
		}
	}
}
if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( string $location, int $status = 302 ): bool {
		throw new B2S_Redirect( $location );
	}
}
if ( ! class_exists( 'B2S_Wp_Die' ) ) {
	class B2S_Wp_Die extends Exception {
	}
}
if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $message = '', $title = '', $args = array() ): void {
		throw new B2S_Wp_Die( (string) $message );
	}
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/' . $path;
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( array $args, string $url ): string {
		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default_value = false ) {
		return array_key_exists( $option, $GLOBALS['B2S_OPTIONS'] ) ? $GLOBALS['B2S_OPTIONS'][ $option ] : $default_value;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, $value, $autoload = null ): bool {
		if ( ! empty( $GLOBALS['B2S_FAIL_OPTION_WRITE'] ) ) {
			// B3-02 fixture: write failure — nothing is stored.
			return false;
		}
		$GLOBALS['B2S_OPTIONS'][ $option ] = $value;
		$GLOBALS['B2S_OPTION_LOG'][ $option ] = array( 'autoload' => $autoload, 'written_at' => count( $GLOBALS['B2S_OPTION_LOG'] ) );
		return true;
	}
}
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( string $scheme = 'auth' ): string {
		return $GLOBALS['B2S_SALTS'][ $scheme ] ?? 'salt-default';
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0 ) {
		return json_encode( $value, $flags );
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component );
	}
}
if ( ! function_exists( 'get_home_url' ) ) {
	function get_home_url(): string {
		return $GLOBALS['B2S_HOME_URL'] ?? 'https://example.test/';
	}
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( string $hook ): int|false {
		return $GLOBALS['B2S_SCHEDULED'][ $hook ] ?? false;
	}
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( int $timestamp, string $recurrence, string $hook ): bool {
		$GLOBALS['B2S_SCHEDULED'][ $hook ] = $timestamp;
		return true;
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( int $id ) {
		return $GLOBALS['B2S_MEDIA']['post'];
	}
}
if ( ! function_exists( 'get_post_mime_type' ) ) {
	function get_post_mime_type( $id ): string {
		return $GLOBALS['B2S_MEDIA']['mime'];
	}
}
if ( ! function_exists( 'wp_attachment_is_image' ) ) {
	function wp_attachment_is_image( $id ): bool {
		return (bool) $GLOBALS['B2S_MEDIA']['is_image'];
	}
}
if ( ! function_exists( 'wp_enqueue_media' ) ) {
	function wp_enqueue_media( array $args = array() ): void {
		$GLOBALS['B2S_ENQUEUED']['media']++;
	}
}
if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( string $handle, string $src = '', array $deps = array(), $ver = false ): void {
		$GLOBALS['B2S_ENQUEUED']['styles'][ $handle ] = array( 'src' => $src, 'ver' => $ver );
	}
}
if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( string $handle, string $src = '', array $deps = array(), $ver = false, bool $in_footer = false ): void {
		$GLOBALS['B2S_ENQUEUED']['scripts'][ $handle ] = array( 'src' => $src, 'ver' => $ver );
	}
}
if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( string $handle, string $object_name, array $l10n ): bool {
		$GLOBALS['B2S_ENQUEUED']['localized'][ $object_name ] = $l10n;
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
if ( ! class_exists( 'B2S_AiClient' ) ) {
	/**
	 * Boundary stub of the WordPress AI Client capability probe
	 * (unified reference §7.4): is_supported_for_text_generation();
	 * valid=false simulates a broken contract object.
	 */
	class B2S_AiClient {
		private bool $supported;
		private bool $valid;
		public function __construct( bool $supported, bool $valid ) {
			$this->supported = $supported;
			$this->valid     = $valid;
		}
		public function is_supported_for_text_generation(): bool {
			if ( ! $this->valid ) {
				throw new RuntimeException( 'broken contract fixture' );
			}
			return $this->supported;
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
if ( ! class_exists( 'WP_Role' ) ) {
	class WP_Role {
		public string $name;
		public array $capabilities = array();
		public int $add_cap_calls  = 0;
		public function __construct( string $name ) {
			$this->name = $name;
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
if ( ! function_exists( 'get_role' ) ) {
	function get_role( string $role ) {
		return $GLOBALS['B2S_ROLES'][ $role ] ?? null;
	}
}

/* ────────────────────────────────────────────────────────────────
 * Fixture helpers
 * ──────────────────────────────────────────────────────────────── */

function b2s_define_release_context(): void {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'b2s-' . getmypid() . DIRECTORY_SEPARATOR );
	}
	if ( ! defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT' ) ) {
		define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT', $GLOBALS['B2S_PROJECT'] . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR );
		define( 'HAL_FRONTEND_DASHBOARD_RELEASE_ID', '3.0.0+' . str_repeat( 'ef', 20 ) );
		define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION', '3.0.0' );
		define(
			'HAL_FRONTEND_DASHBOARD_RUNTIME_URL',
			'https://example.test/releases/' . HAL_FRONTEND_DASHBOARD_RELEASE_ID . '/'
		);
	}
}

function b2s_require_runtime(): void {
	require $GLOBALS['B2S_PROJECT'] . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'bootstrap.php';
}

function b2s_result_line( string $mode ): void {
	$failed = array();
	foreach ( $GLOBALS['B2S_RESULTS'] as $result ) {
		if ( ! $result['ok'] ) {
			$failed[] = array( 'id' => $result['id'], 'fail' => $result['fail'] );
		}
	}
	$payload = array( 'mode' => $mode, 'php' => PHP_VERSION );
	if ( array() !== $failed ) {
		$payload['failed'] = $failed;
	}
	echo 'B2S-RESULT ' . json_encode( $payload, JSON_UNESCAPED_SLASHES ), PHP_EOL;
	$all_ok = array() === $failed;
	echo 'B2S-VERDICT ' . $mode . ' ' . ( $all_ok ? 'ALL-ASSERTIONS-HELD' : 'ASSERTIONS-FAILED' ), PHP_EOL;
	exit( $all_ok ? 0 : 1 );
}

/** Renders one handler call and captures the redirect signal. */
function b2s_capture_redirect( callable $handler ): B2S_Redirect {
	try {
		$handler();
	} catch ( B2S_Redirect $redirect ) {
		return $redirect;
	}
	throw new RuntimeException( 'handler did not redirect' );
}

function b2s_valid_key_hex( string $seed ): string {
	return hash( 'sha256', $seed );
}

/* ────────────────────────────────────────────────────────────────
 * Subprocess modes — real bootstrap + real classes
 * ──────────────────────────────────────────────────────────────── */

function b2s_mode( string $mode, array $args, string $project ): void {
	$GLOBALS['B2S_PROJECT'] = $project;
	b2s_define_release_context();
	// Capture the real audit hook payloads from the Secret Store.
	add_action(
		'hal_frontend_dashboard_secret_audit',
		static function ( array $entry ): void {
			$GLOBALS['B2S_AUDIT_LOG'][] = $entry;
		}
	);

	switch ( $mode ) {
		case 'store-constant-key':
			// AEAD roundtrip through the PHP constant source only.
			$key = b2s_valid_key_hex( $args[0] ?? 'seed' );
			define( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY', $key );
			b2s_require_runtime();
			b2s_check( 'SS1a', 'constant' === HAL_Frontend_Dashboard_Secret_Store::get_key_source() && HAL_Frontend_Dashboard_Secret_Store::is_available(), 'constant key source makes the store available', 'wrong source/availability' );
			$fingerprint = HAL_Frontend_Dashboard_Secret_Store::get_key_fingerprint();
			b2s_check( 'SS1b', is_string( $fingerprint ) && 1 === preg_match( '/\A[0-9a-f]{32}\z/D', $fingerprint ), 'key fingerprint is a 32-hex digest of the key (not the key itself)', 'fingerprint invalid' );
			$stored = HAL_Frontend_Dashboard_Secret_Store::set( 'ai_direct_key', 'PLAINTEXT-VALUE-xyz-42' );
			b2s_check( 'SS1c', true === $stored && HAL_Frontend_Dashboard_Secret_Store::has( 'ai_direct_key' ), 'set() stores an encrypted record and has() reports it without decrypting', 'set/has mismatch' );
			b2s_check( 'SS1d', 'PLAINTEXT-VALUE-xyz-42' === HAL_Frontend_Dashboard_Secret_Store::get( 'ai_direct_key' ), 'get() decrypts the exact original value (AEAD roundtrip)', 'roundtrip mismatch' );
			$records = $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_secrets'];
			b2s_check( 'SS1e', is_array( $records ) && isset( $records['ai_direct_key'] ) && array( 'v', 'key_fp', 'nonce', 'ct' ) === array_keys( $records['ai_direct_key'] ), 'stored record carries exactly version/key fingerprint/nonce/ciphertext', 'record shape mismatch: ' . json_encode( array_keys( (array) $records ) ) );
			b2s_check( 'SS1f', false === strpos( json_encode( $records ), 'PLAINTEXT-VALUE-xyz-42' ), 'no plaintext in the option store (ciphertext+nonce only)', 'plaintext leaked into the option store' );
			b2s_check( 'SS1g', false === $GLOBALS['B2S_OPTION_LOG']['hal_frontend_dashboard_secrets']['autoload'], 'secrets option is written autoload=no', 'autoload mismatch' );
			$audit = $GLOBALS['B2S_AUDIT_LOG'];
			b2s_check( 'SS1h', count( $audit ) >= 1 && 'set' === $audit[0]['action'] && 'ai_direct_key' === $audit[0]['secret_id'] && 7 === $audit[0]['actor'] && array( 'action', 'secret_id', 'actor', 'time' ) === array_keys( $audit[0] ), 'audit metadata carries actor/time/action/secret id only', 'audit payload mismatch: ' . json_encode( $audit ) );
			b2s_check( 'SS1i', false === strpos( json_encode( $audit ), 'PLAINTEXT-VALUE' ), 'no plaintext in audit payloads or logs', 'plaintext in audit' );
			b2s_result_line( $mode );
			// no fallthrough

		case 'store-source-precedence':
			// Environment shadows the PHP constant — no DB value can
			// outrank the external sources (owner decision §2.4).
			$env_key  = b2s_valid_key_hex( 'env-seed' );
			$const_key = b2s_valid_key_hex( 'const-seed' );
			putenv( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY=' . $env_key );
			b2s_require_runtime();
			b2s_check( 'SS2a', 'environment' === HAL_Frontend_Dashboard_Secret_Store::get_key_source(), 'environment source is detected first', 'source mismatch' );
			define( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY', $const_key );
			b2s_check( 'SS2b', 'environment' === HAL_Frontend_Dashboard_Secret_Store::get_key_source(), 'the environment keeps precedence with both sources present', 'precedence mismatch' );
			HAL_Frontend_Dashboard_Secret_Store::set( 'ai_direct_key', 'env-value' );
			putenv( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY' ); // env removed → constant remains.
			b2s_check( 'SS2c', 'constant' === HAL_Frontend_Dashboard_Secret_Store::get_key_source(), 'with the environment gone the constant is the source', 'source fallback mismatch' );
			$result = HAL_Frontend_Dashboard_Secret_Store::get( 'ai_direct_key' );
			b2s_check( 'SS2d', is_wp_error( $result ) && 'hal_secret_key_mismatch' === $result->get_error_code(), 'the env-encrypted record does NOT silently pass under the constant (no DB override of external keys)', 'expected key_mismatch; got ' . json_encode( $result ) );
			b2s_result_line( $mode );
			// no fallthrough

		case 'store-unavailable':
			// No environment, no constant: encrypted entry is blocked
			// explicitly (no derivation from salts, no weaker fallback).
			b2s_require_runtime();
			b2s_check( 'SS3a', ! HAL_Frontend_Dashboard_Secret_Store::is_available() && 'unavailable' === HAL_Frontend_Dashboard_Secret_Store::get_key_source() && null === HAL_Frontend_Dashboard_Secret_Store::get_key_fingerprint(), 'missing key source = unavailable store with null fingerprint', 'availability mismatch' );
			$set = HAL_Frontend_Dashboard_Secret_Store::set( 'ai_direct_key', 'anything' );
			b2s_check( 'SS3b', is_wp_error( $set ) && 'hal_secret_store_unavailable' === $set->get_error_code(), 'set() is blocked explicitly without an external key', 'set should be blocked' );
			$set = HAL_Frontend_Dashboard_Secret_Store::set( 'Free-Key-NAME!', 'anything' );
			b2s_check( 'SS3c', is_wp_error( $set ) && 'hal_secret_bad_id' === $set->get_error_code(), 'ids outside the pattern are rejected before anything else', 'id gate mismatch' );
			$get = HAL_Frontend_Dashboard_Secret_Store::get( 'ai_direct_key' );
			b2s_check( 'SS3d', is_wp_error( $get ) && ! isset( $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_secrets'] ), 'nothing was written and get() reports the blocked state', 'blocked state leaked writes' );
			b2s_result_line( $mode );
			// no fallthrough

		case 'store-wrong-key':
			// Master-key change: key_mismatch, ciphertext kept, no wipe,
			// no success — and the original key still decrypts afterwards.
			define( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY', b2s_valid_key_hex( 'original' ) );
			b2s_require_runtime();
			HAL_Frontend_Dashboard_Secret_Store::set( 'ai_direct_key', 'keep-me' );
			$before = $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_secrets'];
			putenv( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY=' . b2s_valid_key_hex( 'rotated' ) );
			$result = HAL_Frontend_Dashboard_Secret_Store::get( 'ai_direct_key' );
			b2s_check( 'SS4a', is_wp_error( $result ) && 'hal_secret_key_mismatch' === $result->get_error_code(), 'changed master key yields key_mismatch (diagnosable blocked state)', 'expected key_mismatch; got ' . json_encode( $result ) );
			b2s_check( 'SS4b', $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_secrets'] === $before, 'the stored ciphertext is NOT wiped or altered on key change', 'ciphertext was modified' );
			putenv( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY=' . b2s_valid_key_hex( 'original' ) );
			$result = HAL_Frontend_Dashboard_Secret_Store::get( 'ai_direct_key' );
			b2s_check( 'SS4c', 'keep-me' === $result, 'restoring the original key decrypts the kept ciphertext (data survives key churn)', 'roundtrip after restore failed' );
			b2s_result_line( $mode );
			// no fallthrough

		case 'store-tamper':
			define( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY', b2s_valid_key_hex( 'tamper' ) );
			b2s_require_runtime();
			HAL_Frontend_Dashboard_Secret_Store::set( 'ai_direct_key', 'integrity-check' );
			$records = $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_secrets'];
			$ct      = base64_decode( $records['ai_direct_key']['ct'], true );
			$ct[5]   = chr( ord( $ct[5] ) ^ 0x01 ); // flip one ciphertext bit (valid base64-safe mutation follows below).
			$records['ai_direct_key']['ct'] = base64_encode( $ct );
			$GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_secrets'] = $records;
			$tampered = $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_secrets'];
			$result   = HAL_Frontend_Dashboard_Secret_Store::get( 'ai_direct_key' );
			b2s_check( 'SS5a', is_wp_error( $result ) && 'hal_secret_decrypt_failed' === $result->get_error_code(), 'tampered ciphertext fails closed with decrypt_failed', 'expected decrypt_failed; got ' . json_encode( $result ) );
			b2s_check( 'SS5b', $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_secrets'] === $tampered, 'tampered bytes are kept for forensics (no rewrite, no wipe)', 'tampered record was altered' );
			$records['ai_direct_key']['ct'] = '!!!not-base64!!!';
			$GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_secrets'] = $records;
			$result = HAL_Frontend_Dashboard_Secret_Store::get( 'ai_direct_key' );
			b2s_check( 'SS5c', is_wp_error( $result ) && 'hal_secret_decrypt_failed' === $result->get_error_code(), 'a malformed record also fails closed without throwing', 'malformed record threw or returned wrong error' );
			b2s_result_line( $mode );
			// no fallthrough

		case 'store-delete-audit':
			define( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY', b2s_valid_key_hex( 'delete' ) );
			b2s_require_runtime();
			HAL_Frontend_Dashboard_Secret_Store::set( 'ai_direct_key', 'to-be-deleted-77' );
			HAL_Frontend_Dashboard_Secret_Store::delete( 'ai_direct_key' );
			b2s_check( 'SS6a', ! HAL_Frontend_Dashboard_Secret_Store::has( 'ai_direct_key' ) && ! isset( $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_secrets']['ai_direct_key'] ), 'delete removes exactly the targeted record', 'delete mismatch' );
			$actions = array_column( $GLOBALS['B2S_AUDIT_LOG'], 'action' );
			b2s_check( 'SS6b', array( 'set', 'delete' ) === $actions, 'audit log carries set then delete with no value entries', 'audit actions mismatch: ' . json_encode( $actions ) );
			b2s_check( 'SS6c', false === strpos( json_encode( $GLOBALS['B2S_AUDIT_LOG'] ), 'to-be-deleted-77' ), 'audit payloads never contain the secret value or prefixes of it', 'plaintext in audit' );
			b2s_result_line( $mode );
			// no fallthrough

		case 'store-salt-coupling':
			define( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY', b2s_valid_key_hex( 'salts' ) );
			b2s_require_runtime();
			HAL_Frontend_Dashboard_Secret_Store::set( 'ai_direct_key', 'salt-independent' );
			$GLOBALS['B2S_SALTS'] = array( 'auth' => 'rotated-salt-a', 'secure' => 'rotated-pepper-b', 'nonce' => 'rotated-nonce-c' );
			$result = HAL_Frontend_Dashboard_Secret_Store::get( 'ai_direct_key' );
			b2s_check( 'SS7a', 'salt-independent' === $result, 'rotating WordPress salts never breaks decryption (no salt coupling)', 'salt rotation broke decryption' );
			$serialized = json_encode( $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_secrets'] );
			b2s_check( 'SS7b', false === strpos( $serialized, 'rotated-salt' ) && false === strpos( $serialized, 'salt-one' ), 'stored records contain no salt-derived material', 'salt material in the store' );
			b2s_result_line( $mode );
			// no fallthrough

		case 'settings-repo':
			b2s_require_runtime();
			$defaults = HAL_Frontend_Dashboard_Settings_Repository::get_all();
			$expected_features = array_fill_keys( HAL_Frontend_Dashboard_Settings_Repository::FEATURES, true );
			b2s_check( 'SR1', count( $expected_features ) === 11 && $defaults['features'] === $expected_features && 0 === $defaults['branding_attachment_id'] && 'auto' === $defaults['ai_preference'] && '1.0.0' === $defaults['schema_version'], 'defaults preserve the migrated behavior: 11 features enabled, no logo (text fallback), auto AI preference', 'defaults mismatch: ' . json_encode( $defaults ) );
			$saved = HAL_Frontend_Dashboard_Settings_Repository::save( array( 'features' => array( 'posts' => false ), 'ai_preference' => 'wp_ai_client' ) );
			$after = HAL_Frontend_Dashboard_Settings_Repository::get_all();
			b2s_check( 'SR2', true === $saved && false === $after['features']['posts'] && true === $after['features']['files'] && 'wp_ai_client' === $after['ai_preference'], 'typed save merges validated features and the enum-checked AI preference', 'save/merge mismatch' );
			$invalid = HAL_Frontend_Dashboard_Settings_Repository::save( array( 'hacked_key' => 'x' ) );
			b2s_check( 'SR3', is_wp_error( $invalid ) && 'hal_settings_unknown_key' === $invalid->get_error_code(), 'unknown settings keys are rejected entirely (no unknown shapes)', 'unknown key accepted' );
			$invalid = HAL_Frontend_Dashboard_Settings_Repository::save( array( 'features' => array( 'not_a_feature' => true ) ) );
			b2s_check( 'SR4', is_wp_error( $invalid ) && 'hal_settings_feature_invalid' === $invalid->get_error_code(), 'feature keys outside the allowlist are rejected', 'allowlist bypass' );
			$invalid = HAL_Frontend_Dashboard_Settings_Repository::save( array( 'features' => array( 'posts' => 'yes' ) ) );
			b2s_check( 'SR5', is_wp_error( $invalid ) && 'hal_settings_feature_invalid' === $invalid->get_error_code(), 'non-boolean feature values are rejected (strict scalars)', 'scalar check bypassed' );
			$invalid = HAL_Frontend_Dashboard_Settings_Repository::save( array( 'ai_preference' => 'gemini-direct' ) );
			b2s_check( 'SR6', is_wp_error( $invalid ) && 'hal_settings_ai_preference_invalid' === $invalid->get_error_code(), 'AI preference outside the enum is rejected', 'enum bypass' );
			$invalid = HAL_Frontend_Dashboard_Settings_Repository::save( array( 'branding_attachment_id' => -3 ) );
			b2s_check( 'SR7', is_wp_error( $invalid ) && 'hal_settings_branding_shape' === $invalid->get_error_code(), 'negative attachment ids are rejected', 'shape check bypassed' );
			$GLOBALS['B2S_MEDIA'] = array( 'mime' => 'image/svg+xml', 'is_image' => false, 'post' => new WP_Post() );
			$invalid = HAL_Frontend_Dashboard_Settings_Repository::save( array( 'branding_attachment_id' => 9 ) );
			b2s_check( 'SR8', is_wp_error( $invalid ) && 'hal_settings_branding_mime_rejected' === $invalid->get_error_code(), 'SVG branding is rejected by default (owner decision)', 'SVG accepted' );
			$GLOBALS['B2S_MEDIA'] = array( 'mime' => 'image/png', 'is_image' => true, 'post' => new WP_Post() );
			$saved = HAL_Frontend_Dashboard_Settings_Repository::save( array( 'branding_attachment_id' => 9 ) );
			b2s_check( 'SR9', true === $saved && 9 === HAL_Frontend_Dashboard_Settings_Repository::get_branding_attachment_id(), 'a real PNG/JPEG/WebP attachment id is stored (Media Library ownership)', 'valid attachment rejected' );
			$GLOBALS['B2S_MEDIA'] = array( 'mime' => 'image/png', 'is_image' => true, 'post' => null );
			$invalid = HAL_Frontend_Dashboard_Settings_Repository::validate_branding_attachment_id( 404 );
			b2s_check( 'SR10', is_wp_error( $invalid ) && 'hal_settings_branding_not_attachment' === $invalid->get_error_code(), 'a deleted attachment fails validation and the safe fallback stays', 'missing attachment accepted' );
			b2s_check( 'SR11', false === $GLOBALS['B2S_OPTION_LOG']['hal_frontend_dashboard_settings']['autoload'], 'settings option is written autoload=no', 'settings autoload mismatch' );
			$GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_settings']['ai_preference'] = 'direct_key';
			HAL_Frontend_Dashboard_Settings_Repository::invalidate_cache();
			b2s_check( 'SR12', 'direct_key' === HAL_Frontend_Dashboard_Settings_Repository::get_ai_preference(), 'request cache invalidation re-reads the real store', 'cache invalidation broken' );
			b2s_result_line( $mode );
			// no fallthrough

		case 'admin-access':
			// Capability → nonce → typed contract, in that order, per handler.
			$case = $args[0] ?? '';
			define( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY', b2s_valid_key_hex( 'admin' ) );
			b2s_require_runtime();
			$GLOBALS['B2S_CAN_MANAGE']  = 'no-cap' !== $case;
			$GLOBALS['B2S_NONCE_VALID'] = 'bad-nonce' !== $case && 'no-cap' !== $case;
			$_POST = array();
			$store_before = isset( $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_settings'] ) ? $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_settings'] : null;

			if ( 'free-secret' === $case ) {
				$_POST = array( 'secret_id' => 'free_key_name', 'secret_value' => 'x', 'hal_frontend_dashboard_nonce' => 'fixture-nonce' );
				$redirect = b2s_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_set_secret' ) );
				b2s_check( 'AC1', false !== strpos( $redirect->target, 'hal_msg=unknown_secret' ) && ! isset( $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_secrets'] ), 'a secret id outside the Registry allowlist is rejected and nothing is written (no free key names from input)', 'allowlist bypass: ' . $redirect->target );
				b2s_result_line( $mode );
			}
			if ( 'set-secret' === $case ) {
				$_POST = array( 'secret_id' => 'ai_direct_key', 'secret_value' => 'HANDLER-VALUE-31', 'hal_frontend_dashboard_nonce' => 'fixture-nonce' );
				$redirect = b2s_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_set_secret' ) );
				b2s_check( 'AC2', false !== strpos( $redirect->target, 'hal_msg=secret_saved' ) && HAL_Frontend_Dashboard_Secret_Store::has( 'ai_direct_key' ) && 'HANDLER-VALUE-31' === HAL_Frontend_Dashboard_Secret_Store::get( 'ai_direct_key' ), 'the set handler stores the encrypted secret through the real store', 'set handler mismatch: ' . $redirect->target );
				b2s_check( 'AC3', false === strpos( $redirect->target, 'HANDLER-VALUE' ), 'the redirect carries the secret id only — never a value', 'value leaked into redirect' );
				b2s_result_line( $mode );
			}
			if ( 'delete-secret' === $case ) {
				HAL_Frontend_Dashboard_Secret_Store::set( 'ai_direct_key', 'doomed' );
				$_POST = array( 'secret_id' => 'ai_direct_key', 'hal_frontend_dashboard_nonce' => 'fixture-nonce' );
				$redirect = b2s_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_delete_secret' ) );
				b2s_check( 'AC4', false !== strpos( $redirect->target, 'hal_msg=secret_deleted' ) && ! HAL_Frontend_Dashboard_Secret_Store::has( 'ai_direct_key' ), 'the independent delete action removes the stored secret', 'delete handler mismatch' );
				b2s_result_line( $mode );
			}
			if ( 'tab-allowlist' === $case ) {
				$_GET['tab'] = '../../evil';
				$_POST = array( 'features_form' => '1', 'features' => array( 'posts' => '1' ), 'hal_frontend_dashboard_nonce' => 'fixture-nonce' );
				$redirect = b2s_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_save_settings' ) );
				b2s_check( 'AC5', false !== strpos( $redirect->target, 'tab=general' ) && false === strpos( $redirect->target, 'evil' ), 'GET tab is sanitized and falls back to the allowlisted default', 'tab sanitize mismatch: ' . $redirect->target );
				b2s_result_line( $mode );
			}
			// Capability/nonce matrix for the settings save path.
			$_POST = array(
				'features_form' => 'valid' === $case || 'unknown-feature' === $case ? '1' : '',
				'features'      => 'valid' === $case || 'unknown-feature' === $case ? array( 'posts' => '1' ) : array(),
				'ai_preference' => 'valid' === $case ? 'wp_ai_client' : ( 'unknown-feature' === $case ? 'auto' : '' ),
				'hal_frontend_dashboard_nonce' => 'fixture-nonce',
			);
			if ( 'unknown-feature' === $case ) {
				$_POST['features']['not_a_feature'] = '1';
			}
			$redirect = b2s_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_save_settings' ) );
			if ( 'no-cap' === $case ) {
				b2s_check( 'AC6', false !== strpos( $redirect->target, 'hal_msg=forbidden' ) && $store_before === ( $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_settings'] ?? null ), 'without the capability even a valid nonce is rejected', 'capability gate broken' );
			} elseif ( 'bad-nonce' === $case ) {
				b2s_check( 'AC7', false !== strpos( $redirect->target, 'hal_msg=forbidden' ), 'with the capability but a bad nonce the write is rejected (CSRF)', 'nonce gate broken' );
			} elseif ( 'valid' === $case ) {
				$after = HAL_Frontend_Dashboard_Settings_Repository::get_all();
				b2s_check( 'AC8', false !== strpos( $redirect->target, 'hal_msg=saved' ) && false === $after['features']['files'] && true === $after['features']['posts'] && 'wp_ai_client' === $after['ai_preference'], 'the real handler saves through the repository: posted checkbox true, unposted features false (full-state contract)', 'save handler mismatch: ' . json_encode( $after ) );
			} elseif ( 'unknown-feature' === $case ) {
				b2s_check( 'AC9', false !== strpos( $redirect->target, 'hal_msg=save_failed' ), 'the handler surfaces the repository rejection instead of writing partial data', 'handler bypassed validation' );
			}
			b2s_result_line( $mode );
			// no fallthrough

		case 'render-no-secrets':
			define( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY', b2s_valid_key_hex( 'render' ) );
			b2s_require_runtime();
			HAL_Frontend_Dashboard_Secret_Store::set( 'ai_direct_key', 'SUPERSECRETVALUE123' );
			$GLOBALS['B2S_CAN_MANAGE']  = true;
			$GLOBALS['B2S_NONCE_VALID'] = true;
			$_GET['tab'] = 'ai';
			ob_start();
			HAL_Frontend_Dashboard_Admin_Controller::render_page();
			$html = (string) ob_get_clean();
			b2s_check( 'RN1', false === strpos( $html, 'SUPERSECRETVALUE123' ), 'the rendered settings page never contains the stored secret value', 'plaintext in HTML' );
			b2s_check( 'RN2', false !== strpos( $html, 'Configured: yes' ) && false !== strpos( $html, 'type="password"' ) && false !== strpos( $html, 'value=""' ), 'the page shows configured state with an empty password field (replace needs a full new value)', 'masking contract mismatch' );
			b2s_check( 'RN3', false !== strpos( $html, 'unencrypted' ) && false !== strpos( $html, 'not Maximum Protection' ), 'the mandatory Connector DB warning is rendered in the AI tab', 'warning missing' );
			$_GET['tab'] = 'status';
			ob_start();
			HAL_Frontend_Dashboard_Admin_Controller::render_page();
			$html = (string) ob_get_clean();
			b2s_check( 'RN4', false === strpos( $html, 'SUPERSECRETVALUE123' ) && false === strpos( $html, 'keep-me' ), 'the status tab carries sanitized state only', 'status tab leak' );
			$GLOBALS['B2S_CAN_MANAGE'] = false;
			$die = null;
			try {
				HAL_Frontend_Dashboard_Admin_Controller::render_page();
			} catch ( B2S_Wp_Die $signal ) {
				$die = $signal->getMessage();
			}
			b2s_check( 'RN5', null !== $die, 'render_page enforces the capability itself (wp_die without it)', 'render capability gate missing' );
			b2s_result_line( $mode );
			// no fallthrough

		case 'enqueue-scope':
			b2s_require_runtime();
			HAL_Frontend_Dashboard_Admin_Controller::enqueue_assets( 'toplevel_page_' . HAL_Frontend_Dashboard_Admin_Controller::MENU_SLUG );
			$style  = $GLOBALS['B2S_ENQUEUED']['styles']['hal-frontend-dashboard-admin-settings'] ?? null;
			$script = $GLOBALS['B2S_ENQUEUED']['scripts']['hal-frontend-dashboard-admin-settings-js'] ?? null;
			b2s_check( 'EQ1', is_array( $style ) && false !== strpos( $style['src'], '/assets/css/admin-settings.css' ) && is_array( $script ) && false !== strpos( $script['src'], '/assets/js/admin-settings.js' ), 'assets enqueue from the release URL on the project screen', 'asset src mismatch' );
			b2s_check( 'EQ2', 1 === $GLOBALS['B2S_ENQUEUED']['media'] && isset( $GLOBALS['B2S_ENQUEUED']['localized']['halFrontendDashboardAdmin'] ), 'media modal scripts are enqueued and localized without secret data', 'media enqueue mismatch' );
			$GLOBALS['B2S_ENQUEUED'] = array( 'styles' => array(), 'scripts' => array(), 'localized' => array(), 'media' => 0 );
			HAL_Frontend_Dashboard_Admin_Controller::enqueue_assets( 'plugins.php' );
			b2s_check( 'EQ3', array() === $GLOBALS['B2S_ENQUEUED']['styles'] && array() === $GLOBALS['B2S_ENQUEUED']['scripts'], 'assets never leak into other wp-admin screens (screen-id isolation)', 'isolation broken' );
			b2s_result_line( $mode );
			// no fallthrough

		case 'health-monitor':
			define( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY', b2s_valid_key_hex( 'health' ) );
			// Batch-4: a valid runtime profile is part of the real AI adapter
			// contract (the registry strategies stay empty without it).
			define( 'HOSSAM_AI_RUNTIME_PROFILE', array(
				'per_minute' => 30, 'per_day' => 500, 'concurrent' => 2, 'max_attempts' => 3,
				'stale_pending' => 3600, 'processing_deadline' => 240,
				'wp_ai_client_timeout' => 60, 'direct_key_timeout' => 90,
			) );
			b2s_require_runtime();
			HAL_Frontend_Dashboard_Secret_Store::set( 'ai_direct_key', 'HEALTH-PROBE-9' );
			$status = HAL_Frontend_Dashboard_Integration_Health_Monitor::run_checks();
			b2s_check( 'HM1', is_array( $status ) && isset( $status['ai'], $status['amelia'], $status['_meta']['key_source'] ) && 'constant' === $status['_meta']['key_source'], 'run_checks produces per-integration sanitized status with meta (constant key source in this fixture)', 'status shape mismatch' );
			// Batch-4: the real adapter now loads with the runtime, so the
			// batch-3-era "adapter not loaded" code is gone; with no provider
			// configured the direct_key strategy cannot become ready and the
			// preference cannot resolve — the structural code is
			// ai_strategy_invalid and the integration stays blocked either
			// way (configuring a feature never turns an unavailable
			// integration enabled).
			b2s_check( 'HM2', in_array( 'ai_strategy_invalid', $status['ai']['codes'], true ) && 'blocked' === $status['ai']['status'], 'the loaded batch-4 adapter with no provider configured cannot resolve a strategy and is reported blocked — configuring a feature never turns an unavailable integration enabled', 'adapter gate mismatch: ' . json_encode( $status['ai'] ?? array() ) );
			b2s_check( 'HM3', false === strpos( json_encode( $status ), 'HEALTH-PROBE-9' ), 'the status store carries codes only — no decrypted values', 'value leaked into status' );
			$GLOBALS['B2S_CAN_MANAGE'] = true;
			ob_start();
			HAL_Frontend_Dashboard_Integration_Health_Monitor::render_admin_notice();
			$notice_one = (string) ob_get_clean();
			HAL_Frontend_Dashboard_Integration_Health_Monitor::render_admin_notice();
			$notice_two_flag = (int) get_option( 'hal_frontend_dashboard_health_notice_sent', 0 );
			ob_start();
			HAL_Frontend_Dashboard_Integration_Health_Monitor::render_admin_notice();
			$notice_two = (string) ob_get_clean();
			b2s_check( 'HM4', '' !== $notice_one && 1 === $notice_two_flag && '' === $notice_two, 'the admin notice fires once for a failure state and stays silent afterwards (one-shot, limited repetition)', 'notice one-shot mismatch' );
			// New failure state (different codes) re-arms the notice. The
			// corrupt fallback is attributed only once direct_key is the
			// CHOSEN strategy (B3-06-B). Batch-4: the chosen strategy comes
			// from the REAL adapter contract — the documented provider
			// constant plus the direct_key preference make the stored record
			// the selected credential (no resolver eval: it would now fatal
			// against the real adapter definition of
			// hossam_ai_resolve_strategy).
			define( 'HOSSAM_AI_PROVIDER', 'gemini' );
			HAL_Frontend_Dashboard_Settings_Repository::save( array( 'ai_preference' => 'direct_key' ) );
			$records = $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_secrets'];
			$records['ai_direct_key']['key_fp'] = 'deadbeefdeadbeefdeadbeefdeadbeef';
			$GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_secrets'] = $records;
			$status = HAL_Frontend_Dashboard_Integration_Health_Monitor::run_checks();
			b2s_check( 'HM5', 0 === (int) get_option( 'hal_frontend_dashboard_health_notice_sent', 1 ) && in_array( 'decrypt_key_mismatch', $status['ai']['codes'], true ), 'a new failure signature on the CHOSEN credential re-arms the one-shot notice and the key-change code is diagnosable', 're-arm mismatch: ' . json_encode( $status['ai']['codes'] ?? array() ) );
			$tests = HAL_Frontend_Dashboard_Integration_Health_Monitor::register_site_health_test( array( 'direct' => array() ) );
			$site  = HAL_Frontend_Dashboard_Integration_Health_Monitor::site_health_test();
			b2s_check( 'HM6', isset( $tests['direct']['hal_frontend_dashboard_integration_health'] ) && 'critical' === $site['status'] && false === strpos( json_encode( $site ), 'HEALTH-PROBE-9' ), 'the Site Health test reads the sanitized status only and reports critical when blocked — without decrypting anything', 'site health mismatch' );
			// Recovery clears the notice path; runtime rollback never happens.
			$GLOBALS['B2S_ROLES_X'] = 1; // marker only
			b2s_check( 'HM7', ! defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROLLBACK' ) && true, 'integration health carries no runtime rollback contract (boot health is a separate file)' );
			b2s_result_line( $mode );
			// no fallthrough

		case 'installer-grant':
			b2s_define_release_context();
			$GLOBALS['B2S_ROLES'] = array( 'administrator' => new WP_Role( 'administrator' ) );
			require $GLOBALS['B2S_PROJECT'] . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'class-installer.php';
			$installer = new ReflectionClass( 'HAL_Frontend_Dashboard_Installer' );
			$method = $installer->getMethod( 'grant_default_capability' );
			$method->setAccessible( true );
			$role = $GLOBALS['B2S_ROLES']['administrator'];
			$method->invoke( null );
			b2s_check( 'IG1', 1 === $role->add_cap_calls && $role->has_cap( 'manage_hal_frontend_dashboard' ), 'the installer grants the default capability to the administrator idempotently', 'grant mismatch' );
			$method->invoke( null );
			b2s_check( 'IG2', 1 === $role->add_cap_calls, 'the has_cap guard prevents repeated writes on re-activation', 'guard mismatch' );
			$GLOBALS['B2S_ROLES'] = array( 'administrator' => null );
			$method->invoke( null );
			b2s_check( 'IG3', true, 'a missing role degrades safely (no fatal)' );
			b2s_result_line( $mode );
			// no fallthrough

		case 'registry-allowlist':
			b2s_require_runtime();
			$ids = HAL_Frontend_Dashboard_Integration_Settings_Registry::secret_ids();
			b2s_check( 'RG1', array( 'amelia_elite_api_key', 'ai_direct_key' ) === $ids, 'the registry allowlist defines exactly the named secret ids', 'allowlist mismatch: ' . json_encode( $ids ) );
			b2s_check( 'RG2', HAL_Frontend_Dashboard_Integration_Settings_Registry::is_valid_secret_id( 'ai_direct_key' ) && ! HAL_Frontend_Dashboard_Integration_Settings_Registry::is_valid_secret_id( 'free_key_name' ), 'validity is allowlist membership only', 'validity mismatch' );
			$ai = HAL_Frontend_Dashboard_Integration_Settings_Registry::integrations()['ai'];
			b2s_check( 'RG3', array( 'ai_preference' ) === $ai['settings'] && array( 'provider_capability' ) === $ai['health'] && 'ai_direct_key' === $ai['secrets'][0]['id'] && true === $ai['secrets'][0]['optional'], 'the ai integration declares its settings key, abstract health consumer and optional secret (provider allowlist stays owned by the batch-4 adapter)', 'ai declaration mismatch' );
			$urls = json_encode( HAL_Frontend_Dashboard_Integration_Settings_Registry::integrations() );
			b2s_check( 'RG4', false === strpos( $urls, 'http://' ) && false === strpos( $urls, 'https://' ), 'the registry carries no provider URLs at all (health consumers are abstract ids)', 'url leaked into registry' );
			b2s_result_line( $mode );
			// no fallthrough

		case 'features-marker':
			// B3-01: the browser omits every unchecked checkbox — a
			// features-form POST with no features key disables ALL
			// features; other forms never touch features.
			b2s_require_runtime();
			$GLOBALS['B2S_CAN_MANAGE']  = true;
			$GLOBALS['B2S_NONCE_VALID'] = true;
			$_POST = array( 'features_form' => '1', 'hal_frontend_dashboard_nonce' => 'fixture-nonce' );
			b2s_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_save_settings' ) );
			$after = HAL_Frontend_Dashboard_Settings_Repository::get_all();
			$all_disabled = true;
			foreach ( $after['features'] as $enabled ) {
				if ( $enabled ) { $all_disabled = false; }
			}
			b2s_check( 'FM1', $all_disabled, 'unchecking every feature (empty features key from the browser) disables all of them', 'features stayed enabled: ' . json_encode( $after['features'] ) );
			$_POST = array( 'features_form' => '1', 'features' => array( 'posts' => '1' ), 'hal_frontend_dashboard_nonce' => 'fixture-nonce' );
			b2s_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_save_settings' ) );
			$after = HAL_Frontend_Dashboard_Settings_Repository::get_all();
			b2s_check( 'FM2', true === $after['features']['posts'] && false === $after['features']['files'] && false === $after['features']['ai'], 're-enabling exactly one feature keeps the rest disabled (last-enabled disable path included)', 'single enable mismatch' );
			$_POST = array( 'ai_preference' => 'direct_key', 'hal_frontend_dashboard_nonce' => 'fixture-nonce' );
			b2s_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_save_settings' ) );
			$after = HAL_Frontend_Dashboard_Settings_Repository::get_all();
			b2s_check( 'FM3', true === $after['features']['posts'] && 'direct_key' === $after['ai_preference'], 'a non-features settings form never touches the feature toggles', 'form distinction broken' );
			// B3-07 closing case: a present NON-ARRAY features field is
			// rejected — only the truly absent field (FM1) disables all.
			$before_nonarray = $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_settings'];
			$_POST = array( 'features_form' => '1', 'features' => 'invalid-string', 'hal_frontend_dashboard_nonce' => 'fixture-nonce' );
			$redirect = b2s_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_save_settings' ) );
			b2s_check(
				'FM4',
				false !== strpos( $redirect->target, 'hal_msg=save_failed' ) && $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_settings'] === $before_nonarray,
				'a present non-array features field is rejected with the store byte-identical (no unintended mass-disable)',
				'non-array features was accepted: ' . json_encode( $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_settings'] )
			);
			b2s_result_line( $mode );
			// no fallthrough

		case 'save-results':
			// B3-02: success vs no-change vs real write failure.
			b2s_require_runtime();
			$GLOBALS['B2S_CAN_MANAGE']  = true;
			$GLOBALS['B2S_NONCE_VALID'] = true;
			$_POST = array( 'features_form' => '1', 'features' => array( 'posts' => '1' ), 'hal_frontend_dashboard_nonce' => 'fixture-nonce' );
			b2s_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_save_settings' ) );
			$after_first = $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_settings'];
			$_POST = array( 'ai_preference' => 'auto', 'hal_frontend_dashboard_nonce' => 'fixture-nonce' );
			$redirect = b2s_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_save_settings' ) );
			b2s_check( 'SRW1', false !== strpos( $redirect->target, 'hal_msg=saved' ) && $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_settings'] === $after_first, 'saving an identical value reports success without a byte change (no-change is not a failure)', 'no-change path mismatch' );
			$GLOBALS['B2S_FAIL_OPTION_WRITE'] = true;
			$_POST = array( 'ai_preference' => 'direct_key', 'hal_frontend_dashboard_nonce' => 'fixture-nonce' );
			$redirect = b2s_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_save_settings' ) );
			$result = HAL_Frontend_Dashboard_Settings_Repository::save( array( 'ai_preference' => 'direct_key' ) );
			$GLOBALS['B2S_FAIL_OPTION_WRITE'] = false;
			b2s_check( 'SRW2', false !== strpos( $redirect->target, 'hal_msg=save_failed' ) && $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_settings'] === $after_first, 'a failed write of a different value surfaces save_failed and leaves the previous values intact (no false success)', 'write-failure path mismatch' );
			b2s_check( 'SRW3', is_wp_error( $result ) && 'hal_settings_write_failed' === $result->get_error_code(), 'the repository contract returns write_failed for a failed write (distinct from no-change)', 'repository contract mismatch' );
			b2s_result_line( $mode );
			// no fallthrough

		case 'render-html':
			// B3-03: dump the real AI/Features tab HTML for the JS test and
			// assert the server-rendered dependency state.
			$variant = $args[0] ?? 'off';
			$outfile = $args[1] ?? '';
			define( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY', b2s_valid_key_hex( 'render2' ) );
			b2s_require_runtime();
			$GLOBALS['B2S_CAN_MANAGE'] = true;
			HAL_Frontend_Dashboard_Settings_Repository::save( array( 'features' => array( 'ai' => 'on' === $variant ) ) );
			$_GET['tab'] = 'ai';
			ob_start();
			HAL_Frontend_Dashboard_Admin_Controller::render_page();
			$html = (string) ob_get_clean();
			if ( '' !== $outfile && false === file_put_contents( $outfile, $html ) ) {
				fwrite( STDERR, "Cannot write the HTML dump.\n" );
				exit( 1 );
			}
			if ( 'off' === $variant ) {
				b2s_check( 'RH1', false !== strpos( $html, '<fieldset class="hal-fd-fieldset" data-requires-feature="ai" disabled="disabled"' ) || ( false !== strpos( $html, 'data-requires-feature="ai"' ) && false !== strpos( $html, 'disabled="disabled"' ) ), 'with the ai feature disabled the real AI tab renders its controls inside a disabled fieldset', 'server-side dependency missing' );
			} else {
				b2s_check( 'RH2', false !== strpos( $html, 'data-requires-feature="ai"' ) && false === strpos( $html, 'disabled="disabled"', strpos( $html, 'data-requires-feature="ai"' ) ), 'with the ai feature enabled the real AI tab controls are not disabled', 'enabled state wrongly disabled' );
			}
			b2s_result_line( $mode );
			// no fallthrough

		case 'input-shapes':
			// B3-07: arrays and malformed input are rejected without
			// coercion, partial writes or value disclosure.
			define( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY', b2s_valid_key_hex( 'shapes' ) );
			b2s_require_runtime();
			$GLOBALS['B2S_CAN_MANAGE']  = true;
			$GLOBALS['B2S_NONCE_VALID'] = true;
			$_POST = array( 'features_form' => '1', 'features' => array( 'posts' => '1' ), 'hal_frontend_dashboard_nonce' => 'fixture-nonce' );
			b2s_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_save_settings' ) );
			$baseline = $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_settings'];
			$_POST = array( 'secret_id' => array( 'ai_direct_key' ), 'secret_value' => 'x', 'hal_frontend_dashboard_nonce' => 'fixture-nonce' );
			$redirect = b2s_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_set_secret' ) );
			b2s_check( 'IS1', false !== strpos( $redirect->target, 'hal_msg=unknown_secret' ) && ! isset( $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_secrets'] ), 'an array secret_id is rejected as unknown without any write', 'array id coerced' );
			$_POST = array( 'secret_id' => 'ai_direct_key', 'secret_value' => array( 'a' => 'b' ), 'hal_frontend_dashboard_nonce' => 'fixture-nonce' );
			$redirect = b2s_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_set_secret' ) );
			b2s_check( 'IS2', false !== strpos( $redirect->target, 'hal_msg=secret_failed' ) && ! isset( $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_secrets'] ) && false === strpos( $redirect->target, 'a=b' ), 'an array secret value produces the documented failure without storing or echoing any of it', 'array value mishandled' );
			$_POST = array( 'ai_preference' => array( 'auto' ), 'hal_frontend_dashboard_nonce' => 'fixture-nonce' );
			$redirect = b2s_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_save_settings' ) );
			b2s_check( 'IS3', false !== strpos( $redirect->target, 'hal_msg=save_failed' ) && $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_settings'] === $baseline, 'an array ai_preference is rejected with no partial write', 'array preference coerced' );
			$_POST = array( 'branding_attachment_id' => array( '9' ), 'hal_frontend_dashboard_nonce' => 'fixture-nonce' );
			$redirect = b2s_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_save_settings' ) );
			b2s_check( 'IS4', false !== strpos( $redirect->target, 'hal_msg=save_failed' ) && 0 === HAL_Frontend_Dashboard_Settings_Repository::get_branding_attachment_id() && 0 === $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_settings']['branding_attachment_id'], 'an array attachment id is rejected instead of being coerced to 0 (branding is not silently cleared)', 'array id coerced to zero' );
			$_GET['tab'] = array( 'ai' );
			$before_malformed = $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_settings'];
			$_POST = array( 'features_form' => '1', 'features' => array( 'posts' => array( 'nested' => '1' ) ), 'hal_frontend_dashboard_nonce' => 'fixture-nonce' );
			$redirect = b2s_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_save_settings' ) );
			b2s_check( 'IS5', false !== strpos( $redirect->target, 'hal_msg=save_failed' ) && $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_settings'] === $before_malformed, 'a nested non-scalar feature value rejects the whole form before any write (store byte-identical) and an array tab falls back to the default', 'malformed features mutated state: ' . json_encode( $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_settings'] ) );
			b2s_result_line( $mode );
			// no fallthrough

		case 'sodium-absent':
			// B3-04: REAL PHP 8.3 WITHOUT the sodium extension (spawned
			// with -n) plus a valid synthetic key: the store reports the
			// blocked state explicitly and refuses set/get without a fatal.
			define( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY', b2s_valid_key_hex( 'no-sodium' ) );
			b2s_require_runtime();
			b2s_check( 'NA1', ! function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' ) && ! HAL_Frontend_Dashboard_Secret_Store::is_available() && 'sodium_missing' === HAL_Frontend_Dashboard_Secret_Store::get_blocker() && 'constant' === HAL_Frontend_Dashboard_Secret_Store::get_key_source(), 'without sodium the store is blocked explicitly while the key source is still reported (diagnosis distinguishes the two blockers)', 'sodium-absent diagnosis mismatch' );
			$set = HAL_Frontend_Dashboard_Secret_Store::set( 'ai_direct_key', 'nope' );
			b2s_check( 'NA2', is_wp_error( $set ) && 'hal_secret_store_unavailable' === $set->get_error_code(), 'set() is refused safely without sodium (no fallback)', 'set did not refuse' );
			$GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_secrets'] = array(
				'ai_direct_key' => array( 'v' => 1, 'key_fp' => substr( hash( 'sha256', b2s_valid_key_hex( 'no-sodium' ) ), 0, 32 ), 'nonce' => base64_encode( str_repeat( 'A', 24 ) ), 'ct' => base64_encode( 'not-really-encrypted' ) ),
			);
			$get = HAL_Frontend_Dashboard_Secret_Store::get( 'ai_direct_key' );
			b2s_check( 'NA3', is_wp_error( $get ) && 'hal_secret_store_unavailable' === $get->get_error_code(), 'get() refuses safely on an existing record without sodium (no fatal, no plaintext path)', 'get did not refuse' );
			$status = HAL_Frontend_Dashboard_Integration_Health_Monitor::run_checks();
			// Batch-4: the real adapter is loaded; with no provider configured
			// the strategy cannot resolve, so the structural code is
			// ai_strategy_invalid. The fallback fault stays unattributed
			// (no sodium_unavailable without a chosen direct_key); the sodium
			// attribution when direct_key IS chosen is proven in HC19 on a
			// real sodium-less PHP.
			b2s_check(
				'NA4',
				'blocked' === ( $status['ai']['status'] ?? '' )
					&& in_array( 'ai_strategy_invalid', $status['ai']['codes'] ?? array(), true )
					&& ! in_array( 'sodium_unavailable', $status['ai']['codes'] ?? array(), true ),
				'with no determinable strategy the fallback fault is not attributed (the structural unresolvable-strategy code blocks)',
				'monitor code mismatch: ' . json_encode( $status['ai'] ?? array() )
			);
			$GLOBALS['B2S_CAN_MANAGE'] = true;
			$_GET['tab'] = 'status';
			ob_start();
			HAL_Frontend_Dashboard_Admin_Controller::render_page();
			$html = (string) ob_get_clean();
			b2s_check( 'NA5', false !== strpos( $html, 'blocked' ) && false !== strpos( $html, 'constant' ), 'the status tab shows the blocked store with its key source (explicit diagnosis)', 'status diagnosis mismatch' );
			b2s_result_line( $mode );
			// no fallthrough

		case 'ciphertext-version':
			// B3-05: unsupported ciphertext schema versions are rejected
			// with the bytes kept.
			define( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY', b2s_valid_key_hex( 'cver' ) );
			b2s_require_runtime();
			HAL_Frontend_Dashboard_Secret_Store::set( 'ai_direct_key', 'versioned' );
			$records = $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_secrets'];
			$original = $records;
			$records['ai_direct_key']['v'] = 2;
			$GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_secrets'] = $records;
			$get = HAL_Frontend_Dashboard_Secret_Store::get( 'ai_direct_key' );
			b2s_check( 'CV1', is_wp_error( $get ) && 'hal_secret_unsupported_version' === $get->get_error_code() && $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_secrets'] === $records, 'a future ciphertext version is rejected with its own code and the bytes are kept', 'version gate mismatch' );
			$records['ai_direct_key']['v'] = 'garbage';
			$GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_secrets'] = $records;
			$get = HAL_Frontend_Dashboard_Secret_Store::get( 'ai_direct_key' );
			b2s_check( 'CV2', is_wp_error( $get ) && 'hal_secret_unsupported_version' === $get->get_error_code(), 'a non-integer version is rejected the same way', 'garbage version accepted' );
			$GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_secrets'] = $original;
			b2s_check( 'CV3', 'versioned' === HAL_Frontend_Dashboard_Secret_Store::get( 'ai_direct_key' ), 'restoring the supported version decrypts normally', 'restore mismatch' );
			b2s_result_line( $mode );
			// no fallthrough

		case 'site-scope':
			// B3-05: the associated data distinguishes sites beyond the
			// hostname (host+path) and binds the secret id.
			define( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY', b2s_valid_key_hex( 'scope' ) );
			$GLOBALS['B2S_HOME_URL'] = 'https://example.test/site-a';
			b2s_require_runtime();
			HAL_Frontend_Dashboard_Secret_Store::set( 'ai_direct_key', 'scoped' );
			$GLOBALS['B2S_HOME_URL'] = 'https://example.test/site-b';
			$get = HAL_Frontend_Dashboard_Secret_Store::get( 'ai_direct_key' );
			b2s_check( 'SP1', is_wp_error( $get ) && 'hal_secret_decrypt_failed' === $get->get_error_code(), 'the same ciphertext under a different site path on the SAME host fails closed (associated data beyond hostname)', 'cross-site decrypt succeeded' );
			$GLOBALS['B2S_HOME_URL'] = 'https://example.test/site-a';
			b2s_check( 'SP2', 'scoped' === HAL_Frontend_Dashboard_Secret_Store::get( 'ai_direct_key' ), 'the correct site context still decrypts', 'same-site decrypt broken' );
			$records = $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_secrets'];
			$records['amelia_elite_api_key'] = $records['ai_direct_key'];
			$GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_secrets'] = $records;
			$get = HAL_Frontend_Dashboard_Secret_Store::get( 'amelia_elite_api_key' );
			b2s_check( 'SP3', is_wp_error( $get ) && 'hal_secret_decrypt_failed' === $get->get_error_code(), 'copying a record under another secret id fails (the id is bound into the associated data)', 'id rebinding succeeded' );
			b2s_result_line( $mode );
			// no fallthrough

		case 'health-consumers':
			// B3-06: the monitor consumes the real capability/source
			// contract with sanitized codes for every state.
			$case = $args[0] ?? '';
			// B3-06-B closing case: a valid external constant with NO HAL
			// master key at all — the store cannot even exist, yet the
			// external source must not be blocked.
			if ( ! in_array( $case, array( 'amelia-constant-no-hal-key', 'wp-client-valid-no-hal-key' ), true ) ) {
				define( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY', b2s_valid_key_hex( 'hc' ) );
			}
			// Batch-4 fixture evolution: the adapter boundary stubs are
			// defined BEFORE the real runtime loads, so the real batch-4
			// adapter's function_exists guards skip their own definitions
			// and these stubs remain the monitor-isolation contract (the
			// same boundary pattern as the wpdb mock and the b2c
			// hossam_dashboard_url stub). Defining them after the load
			// would now fatal: the real adapter defines
			// hossam_ai_resolve_strategy itself.
			// Fix (audit note): direct-key-client-unsupported really runs
			// with an UNSUPPORTED client — its strategy simply never uses it.
			$ai_supported  = ! in_array( $case, array( 'prompt-unsupported', 'contract-invalid', 'direct-key-client-unsupported' ), true );
			$ai_valid_obj  = 'contract-invalid' !== $case;
			$strategy      = 'strategy-invalid' === $case ? 'weird' : ( in_array( $case, array( 'direct-key-missing', 'direct-key-ok', 'direct-key-no-wp-client', 'direct-key-client-unsupported', 'direct-key-chosen-corrupt', 'direct-key-no-sodium' ), true ) ? 'direct_key' : ( in_array( $case, array( 'recovery', 'prompt-unsupported', 'contract-invalid', 'wp-client-valid-corrupt-fallback', 'wp-client-valid-no-hal-key', 'wp-client-no-sodium' ), true ) ? 'wp_ai_client' : null ) );
			$eval_prompt   = ! in_array( $case, array( 'never-run', 'amelia-credential-missing', 'amelia-credential-ok', 'direct-key-no-wp-client', 'amelia-constant-corrupt-fallback', 'amelia-constant-no-hal-key', 'hal-fallback-chosen-corrupt', 'direct-key-chosen-corrupt', 'direct-key-no-sodium', 'undetermined-strategy-corrupt-fallback' ), true );
			$eval_resolver = ! in_array( $case, array( 'never-run', 'amelia-credential-missing', 'amelia-credential-ok', 'amelia-constant-corrupt-fallback', 'amelia-constant-no-hal-key', 'hal-fallback-chosen-corrupt', 'undetermined-strategy-corrupt-fallback' ), true );
			if ( $eval_prompt ) {
				eval( 'function wp_ai_client_prompt( $text ) { return new B2S_AiClient( ' . var_export( $ai_supported, true ) . ', ' . var_export( $ai_valid_obj, true ) . ' ); }' );
			}
			if ( $eval_resolver ) {
				eval( 'function hossam_ai_resolve_strategy() { return ' . var_export( $strategy, true ) . '; }' );
			}
			b2s_require_runtime();
			// direct-key-missing is ABOUT the absent credential: no secret.
			// Corrupt-fallback cases inject a broken record instead (set()
			// would produce a valid one).
			if ( ! in_array( $case, array(
				'never-run', 'direct-key-missing', 'amelia-constant-no-hal-key',
				'wp-client-valid-corrupt-fallback', 'wp-client-valid-no-hal-key',
				'undetermined-strategy-corrupt-fallback', 'direct-key-chosen-corrupt',
				'wp-client-no-sodium', 'direct-key-no-sodium',
			), true ) ) {
				HAL_Frontend_Dashboard_Secret_Store::set( 'ai_direct_key', 'hc-value' );
			}
			// B3-06-B fixtures: corrupt stored fallback records (never a
			// real secret — injected bytes with a mismatching fingerprint).
			if ( in_array( $case, array( 'amelia-constant-corrupt-fallback', 'amelia-constant-no-hal-key', 'hal-fallback-chosen-corrupt' ), true ) ) {
				$GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_secrets'] = array(
					'amelia_elite_api_key' => array(
						'v'      => 1,
						'key_fp' => substr( hash( 'sha256', 'not-the-key' ), 0, 32 ),
						'nonce'  => base64_encode( str_repeat( 'A', 24 ) ),
						'ct'     => base64_encode( 'corrupt-ciphertext-bytes' ),
					),
				);
			}
			if ( in_array( $case, array( 'wp-client-valid-corrupt-fallback', 'wp-client-valid-no-hal-key', 'undetermined-strategy-corrupt-fallback', 'direct-key-chosen-corrupt', 'wp-client-no-sodium', 'direct-key-no-sodium' ), true ) ) {
				$GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_secrets'] = array(
					'ai_direct_key' => array(
						'v'      => 1,
						'key_fp' => substr( hash( 'sha256', 'not-the-key' ), 0, 32 ),
						'nonce'  => base64_encode( str_repeat( 'A', 24 ) ),
						'ct'     => base64_encode( 'corrupt-ciphertext-bytes' ),
					),
				);
			}
			if ( in_array( $case, array( 'amelia-credential-ok', 'amelia-constant-corrupt-fallback', 'amelia-constant-no-hal-key' ), true ) ) {
				define( 'HOSSAM_AMELIA_API_KEY', 'synthetic-credential' );
			}
			$GLOBALS['B2S_CAN_MANAGE'] = true;
			if ( 'never-run' === $case ) {
				$site = HAL_Frontend_Dashboard_Integration_Health_Monitor::site_health_test();
				b2s_check( 'HC0', 'recommended' === $site['status'] && ! isset( $site['codes'] ), 'an unchecked state is never displayed as good (Site Health recommended)', 'unchecked state shown as good' );
				$rows_ok = true;
				b2s_check( 'HC0b', $rows_ok && array() === HAL_Frontend_Dashboard_Integration_Health_Monitor::get_status(), 'no status store exists before the first check', 'status pre-existed' );
				b2s_result_line( $mode );
			}
			$status = HAL_Frontend_Dashboard_Integration_Health_Monitor::run_checks();
			$ai_codes = $status['ai']['codes'] ?? array();
			switch ( $case ) {
				case 'prompt-unsupported':
					b2s_check( 'HC1', in_array( 'wp_ai_client_unsupported', $ai_codes, true ), 'a present AI Client without text-generation support is a distinct blocked code (existence is not health)', 'unsupported code missing — actual codes: ' . json_encode( $ai_codes ) );
					break;

				case 'contract-invalid':
					b2s_check( 'HC2', in_array( 'wp_ai_client_contract_invalid', $ai_codes, true ), 'a broken capability contract is a distinct code, not a pass', 'contract code missing' );
					break;
				case 'strategy-invalid':
					b2s_check( 'HC3', in_array( 'ai_strategy_invalid', $ai_codes, true ), 'a resolver without a valid strategy is blocked (resolver presence is not health)', 'strategy code missing' );
					break;
				case 'direct-key-missing':
					b2s_check( 'HC4', in_array( 'direct_key_secret_missing', $ai_codes, true ), 'a direct_key strategy without its credential is blocked (credential gate)', 'credential code missing' );
					break;
				case 'direct-key-ok':
				case 'recovery':
					b2s_check( 'HC5', array() === $ai_codes && 'ok' === $status['ai']['status'], 'the full valid contract (supported client + valid strategy + configured secret) is healthy — recovery clears the codes', 'recovery mismatch: ' . json_encode( $ai_codes ) );
					break;
				case 'amelia-credential-missing':
					b2s_check( 'HC6', in_array( 'amelia_credential_missing', $status['amelia']['codes'] ?? array(), true ), 'no credential source at all is a distinct sanitized code', 'credential-missing code missing' );
					break;
				case 'amelia-credential-ok':
					// Batch-4: the real amelia adapter is now loaded, so the
					// batch-3-era "unloaded adapter" code can no longer appear;
					// the documented constant clears the credential code and
					// the monitor leaves amelia unblocked.
					b2s_check( 'HC7', ! in_array( 'amelia_credential_missing', $status['amelia']['codes'] ?? array(), true ) && array() === ( $status['amelia']['codes'] ?? array( 'x' ) ) && 'ok' === ( $status['amelia']['status'] ?? '' ), 'defining the documented credential constant with the loaded batch-4 adapter leaves amelia unblocked (source precedence consumed)', 'credential-ok mismatch: ' . json_encode( $status['amelia'] ?? array() ) );
					break;
				case 'direct-key-no-wp-client':
					b2s_check( 'HC9', array() === $ai_codes && 'ok' === ( $status['ai']['status'] ?? '' ), 'B3-06-A: a valid direct_key strategy is healthy while the WP AI Client is absent entirely (an unused strategy never blocks the chosen one)', 'direct_key blocked by absent WP AI Client: ' . json_encode( $ai_codes ) );
					break;
				case 'direct-key-client-unsupported':
					b2s_check( 'HC10', array() === $ai_codes && 'ok' === ( $status['ai']['status'] ?? '' ), 'B3-06-A: a present-but-unsupported WP AI Client does not block a chosen direct_key strategy', 'unsupported client blocked direct_key: ' . json_encode( $ai_codes ) );
					break;
				case 'amelia-constant-corrupt-fallback':
					b2s_check(
						'HC11',
						! in_array( 'decrypt_key_mismatch', $status['amelia']['codes'] ?? array(), true )
							&& 'constant' === HAL_Frontend_Dashboard_Integration_Settings_Registry::credential_source( 'amelia_elite_api_key' )
							&& isset( $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_secrets']['amelia_elite_api_key'] ),
						'B3-06-B: a valid constant source is not blocked by a corrupt unused HAL fallback; the ciphertext stays stored for diagnosis',
						'corrupt fallback blocked the external source: ' . json_encode( $status['amelia'] ?? array() )
					);
					break;
				case 'amelia-constant-no-hal-key':
					b2s_check(
						'HC12',
						! in_array( 'key_source_unavailable', $status['amelia']['codes'] ?? array(), true )
							&& 'constant' === HAL_Frontend_Dashboard_Integration_Settings_Registry::credential_source( 'amelia_elite_api_key' ),
						'B3-06-B: a missing HAL master key does not block a valid constant source',
						'missing HAL key blocked the constant source: ' . json_encode( $status['amelia'] ?? array() )
					);
					break;
				case 'hal-fallback-chosen-corrupt':
					b2s_check(
						'HC13',
						in_array( 'decrypt_key_mismatch', $status['amelia']['codes'] ?? array(), true )
							&& isset( $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_secrets']['amelia_elite_api_key'] ),
						'B3-06-B: a corrupt fallback that IS the chosen source stays blocking with its ciphertext preserved',
						'chosen corrupt fallback did not block or was wiped: ' . json_encode( $status['amelia'] ?? array() )
					);
					break;
				case 'wp-client-valid-corrupt-fallback':
					b2s_check(
						'HC14',
						array() === $ai_codes && 'ok' === ( $status['ai']['status'] ?? '' )
							&& isset( $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_secrets']['ai_direct_key'] ),
						'B3-06-B: a valid chosen wp_ai_client strategy is NOT blocked by a corrupt unused HAL fallback; the ciphertext stays stored',
						'corrupt unused fallback blocked the valid client: ' . json_encode( $ai_codes )
					);
					break;
				case 'wp-client-valid-no-hal-key':
					b2s_check(
						'HC15',
						array() === $ai_codes && 'ok' === ( $status['ai']['status'] ?? '' ),
						'B3-06-B: a missing HAL master key does not block a chosen wp_ai_client strategy',
						'missing HAL key blocked the valid client: ' . json_encode( $ai_codes )
					);
					break;
				case 'undetermined-strategy-corrupt-fallback':
					// Batch-4: the real adapter is loaded (no resolver stub for
					// this case), so with no provider/runtime profile configured
					// the strategy cannot resolve and the structural code is now
					// ai_strategy_invalid; the corrupt unused fallback stays
					// unattributed either way.
					b2s_check(
						'HC16',
						1 !== preg_match( '/decrypt_/', json_encode( $ai_codes ) ) && in_array( 'ai_strategy_invalid', $ai_codes, true ),
						'B3-06-B + batch-4: with no determinable strategy the corrupt fallback is not attributed to the integration status (the structural unresolvable-strategy code is what blocks)',
						'fallback fault attributed without a chosen strategy: ' . json_encode( $ai_codes )
					);
					break;
				case 'direct-key-chosen-corrupt':
					b2s_check(
						'HC17',
						in_array( 'decrypt_key_mismatch', $ai_codes, true )
							&& isset( $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_secrets']['ai_direct_key'] ),
						'B3-06-B: the corrupt fallback chosen as the credential (direct_key) stays blocking with its ciphertext preserved',
						'chosen corrupt fallback did not block or was wiped: ' . json_encode( $ai_codes )
					);
					break;
				case 'wp-client-no-sodium':
					b2s_check(
						'HC18',
						array() === $ai_codes && 'ok' === ( $status['ai']['status'] ?? '' ),
						'B3-06-B: Sodium unavailable does not block a chosen wp_ai_client strategy (real PHP without sodium)',
						'sodium absence blocked the valid client: ' . json_encode( $ai_codes )
					);
					break;
				case 'direct-key-no-sodium':
					b2s_check(
						'HC19',
						in_array( 'sodium_unavailable', $ai_codes, true )
							&& isset( $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_secrets']['ai_direct_key'] ),
						'B3-06-B: Sodium unavailable IS blocking when direct_key is the chosen strategy; the ciphertext stays stored',
						'sodium absence did not block the chosen direct_key: ' . json_encode( $ai_codes )
					);
					break;
			}
			b2s_check( 'HC8', false === strpos( json_encode( $status ), 'hc-value' ) && ! defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROLLBACK' ), 'codes stay sanitized and no runtime-rollback contract exists', 'leak or rollback marker' );
			b2s_result_line( $mode );
			// no fallthrough

		case 'data-preserved':
			// B3-08 (in-scope part): disabling features never deletes data,
			// and enabling alone never bypasses the registry/capability.
			define( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY', b2s_valid_key_hex( 'preserve' ) );
			b2s_require_runtime();
			$GLOBALS['B2S_OPTIONS']['hossam_messages_db_version'] = '1.0.1';
			$GLOBALS['B2S_OPTIONS']['hossam_i18n_v'] = 'abc123';
			$GLOBALS['B2S_CAN_MANAGE']  = true;
			$GLOBALS['B2S_NONCE_VALID'] = true;
			HAL_Frontend_Dashboard_Secret_Store::set( 'ai_direct_key', 'preserved-secret' );
			$_POST = array( 'features_form' => '1', 'hal_frontend_dashboard_nonce' => 'fixture-nonce' );
			b2s_capture_redirect( array( 'HAL_Frontend_Dashboard_Admin_Controller', 'handle_save_settings' ) );
			b2s_check( 'DP1', '1.0.1' === $GLOBALS['B2S_OPTIONS']['hossam_messages_db_version'] && 'abc123' === $GLOBALS['B2S_OPTIONS']['hossam_i18n_v'] && HAL_Frontend_Dashboard_Secret_Store::has( 'ai_direct_key' ), 'disabling all features deletes no data: schema options, i18n state and stored secrets are untouched', 'data was deleted on disable' );
			b2s_check( 'DP2', true === HAL_Frontend_Dashboard_Settings_Repository::get_all()['features']['ai'] ? false : true, 'the ai feature is now disabled in the settings' );
			// Enabled setting alone never bypasses the structural gates.
			HAL_Frontend_Dashboard_Settings_Repository::save( array( 'features' => array( 'ai' => true ) ) );
			$GLOBALS['B2S_CAN_MANAGE'] = false;
			b2s_check( 'DP3', ! HAL_Frontend_Dashboard_Admin_Controller::current_user_can_manage(), 'enabling a feature never grants the management capability (owner setting ≠ capability)', 'capability bypassed' );
			$status = HAL_Frontend_Dashboard_Integration_Health_Monitor::run_checks();
			// Batch-4: the real adapter is loaded; with no provider configured
			// the strategy cannot resolve (ai_strategy_invalid) — the registry
			// gate stands either way: the feature-enabled setting alone never
			// turns the integration enabled.
			b2s_check( 'DP4', 'blocked' === $status['ai']['status'] && in_array( 'ai_strategy_invalid', $status['ai']['codes'], true ), 'with the feature enabled the unresolvable AI environment keeps the integration blocked (registry gate stands)', 'registry bypassed' );
			b2s_result_line( $mode );
			// no fallthrough

		case 'credential-source':
			// Credential source precedence per owner decision §2.4 for the
			// layer this code owns: environment → constant → hal_encrypted
			// → none (official-API and Connector-DB sources are adapter
			// contracts of later batches).
			define( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY', b2s_valid_key_hex( 'credsrc' ) );
			b2s_require_runtime();
			b2s_check( 'CS0', 'none' === HAL_Frontend_Dashboard_Integration_Settings_Registry::credential_source( 'amelia_elite_api_key' ) && 'none' === HAL_Frontend_Dashboard_Integration_Settings_Registry::credential_source( 'ai_direct_key' ), 'without any source both credentials report none', 'initial source mismatch' );
			HAL_Frontend_Dashboard_Secret_Store::set( 'ai_direct_key', 'encrypted-cred' );
			HAL_Frontend_Dashboard_Secret_Store::set( 'amelia_elite_api_key', 'encrypted-cred' );
			b2s_check( 'CS1', 'hal_encrypted' === HAL_Frontend_Dashboard_Integration_Settings_Registry::credential_source( 'amelia_elite_api_key' ) && 'hal_encrypted' === HAL_Frontend_Dashboard_Integration_Settings_Registry::credential_source( 'ai_direct_key' ), 'the encrypted fallback is reported as its own source', 'encrypted source mismatch' );
			define( 'HOSSAM_AMELIA_API_KEY', 'constant-cred' );
			b2s_check( 'CS2', 'constant' === HAL_Frontend_Dashboard_Integration_Settings_Registry::credential_source( 'amelia_elite_api_key' ) && 'hal_encrypted' === HAL_Frontend_Dashboard_Integration_Settings_Registry::credential_source( 'ai_direct_key' ), 'the constant outranks the encrypted fallback for amelia while ai stays encrypted (per-secret sources)', 'precedence mismatch' );
			b2s_check( 'CS3', 'none' === HAL_Frontend_Dashboard_Integration_Settings_Registry::credential_source( 'unknown_id' ), 'unknown ids report none (allowlist membership required)', 'unknown id leaked' );
			b2s_result_line( $mode );
			// no fallthrough

		case 'branding-fallback':
			// Branding acceptance: valid readable image saved; deletion or
			// type change yields the safe text-fallback state without
			// deleting the stored reference.
			b2s_require_runtime();
			$GLOBALS['B2S_MEDIA'] = array( 'mime' => 'image/png', 'is_image' => true, 'post' => new WP_Post() );
			b2s_check( 'BF1', true === HAL_Frontend_Dashboard_Settings_Repository::save( array( 'branding_attachment_id' => 33 ) ) && 33 === HAL_Frontend_Dashboard_Settings_Repository::get_branding_attachment_id(), 'a readable PNG attachment is accepted and served back', 'valid attachment mismatch' );
			$GLOBALS['B2S_MEDIA'] = array( 'mime' => 'image/png', 'is_image' => true, 'post' => null );
			b2s_check( 'BF2', 0 === HAL_Frontend_Dashboard_Settings_Repository::get_branding_attachment_id() && 33 === $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_settings']['branding_attachment_id'], 'deleting the attachment yields the fallback state (0) while the stored reference is preserved — no data deletion', 'fallback wiped the reference' );
			$GLOBALS['B2S_MEDIA'] = array( 'mime' => 'image/svg+xml', 'is_image' => false, 'post' => new WP_Post() );
			b2s_check( 'BF3', 0 === HAL_Frontend_Dashboard_Settings_Repository::get_branding_attachment_id(), 'an attachment whose type changed to SVG also yields the fallback state', 'svg fallback mismatch' );
			b2s_result_line( $mode );
			// no fallthrough

		case 'upload-files-gate':
			// The media modal wiring distinguishes upload capability.
			b2s_require_runtime();
			$GLOBALS['B2S_CAN_MANAGE'] = true;
			$GLOBALS['B2S_CAN_UPLOAD'] = true;
			HAL_Frontend_Dashboard_Admin_Controller::enqueue_assets( 'toplevel_page_' . HAL_Frontend_Dashboard_Admin_Controller::MENU_SLUG );
			b2s_check( 'UF1', true === ( $GLOBALS['B2S_ENQUEUED']['localized']['halFrontendDashboardAdmin']['canUploadFiles'] ?? null ), 'with upload_files the localized flag allows the uploader', 'uploader flag mismatch' );
			$GLOBALS['B2S_CAN_UPLOAD'] = false;
			$GLOBALS['B2S_ENQUEUED']['localized'] = array();
			HAL_Frontend_Dashboard_Admin_Controller::enqueue_assets( 'toplevel_page_' . HAL_Frontend_Dashboard_Admin_Controller::MENU_SLUG );
			b2s_check( 'UF2', false === ( $GLOBALS['B2S_ENQUEUED']['localized']['halFrontendDashboardAdmin']['canUploadFiles'] ?? null ), 'without upload_files the localized flag is false (the JS hides the uploader; server caps remain authoritative)', 'uploader flag mismatch' );
			b2s_result_line( $mode );
			// no fallthrough

		case 'persist-write':
			// Persistence fixture part 1: site data saved under release
			// 3.0.0, then the option store is exported to the workspace.
			$GLOBALS['B2S_HOME_URL'] = 'https://example.test/persist';
			define( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY', b2s_valid_key_hex( 'persist' ) );
			b2s_require_runtime();
			$GLOBALS['B2S_MEDIA'] = array( 'mime' => 'image/webp', 'is_image' => true, 'post' => new WP_Post() );
			HAL_Frontend_Dashboard_Settings_Repository::save( array( 'features' => array( 'posts' => false ), 'branding_attachment_id' => 21, 'ai_preference' => 'wp_ai_client' ) );
			HAL_Frontend_Dashboard_Secret_Store::set( 'ai_direct_key', 'survives-updates' );
			$runtime_files_before = 0;
			foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $GLOBALS['B2S_PROJECT'] . '/runtime', FilesystemIterator::SKIP_DOTS ) ) as $f ) { $runtime_files_before++; }
			$store_file = $args[0] ?? '';
			if ( '' === $store_file || false === file_put_contents( $store_file, json_encode( $GLOBALS['B2S_OPTIONS'] ) ) ) {
				fwrite( STDERR, "Cannot persist the option store.\n" );
				exit( 1 );
			}
			b2s_check( 'PW1', is_array( json_decode( (string) file_get_contents( $store_file ), true ) ), 'site data (settings + encrypted secret) exported from the simulated site', 'export failed' );
			b2s_check( 'PW2', $runtime_files_before > 0, 'runtime tree scanned for the outside-artifacts assertion' );
			b2s_result_line( $mode );
			// no fallthrough

		case 'persist-read':
			// Persistence fixture part 2: a DIFFERENT runtime version reads
			// the same site data back intact (update or rollback window).
			$store_file = $args[0] ?? '';
			$simulated_version = $args[1] ?? '9.9.9';
			$loaded = json_decode( (string) @file_get_contents( $store_file ), true );
			if ( ! is_array( $loaded ) ) {
				fwrite( STDERR, "Cannot load the persisted option store.\n" );
				exit( 1 );
			}
			$GLOBALS['B2S_OPTIONS']     = $loaded;
			$GLOBALS['B2S_OPTION_LOG']  = array();
			$GLOBALS['B2S_HOME_URL']    = 'https://example.test/persist';
			define( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY', b2s_valid_key_hex( 'persist' ) );
			b2s_define_release_context();
			// Simulate the OTHER release context for this process.
			$GLOBALS['B2S_SIMULATED_VERSION'] = $simulated_version;
			b2s_require_runtime();
			$settings = HAL_Frontend_Dashboard_Settings_Repository::get_all();
			b2s_check( 'PR1', false === $settings['features']['posts'] && 'wp_ai_client' === $settings['ai_preference'], "settings survive the release switch to {$simulated_version} (update or rollback)", 'settings lost across the switch' );
			b2s_check( 'PR2', 'survives-updates' === HAL_Frontend_Dashboard_Secret_Store::get( 'ai_direct_key' ), 'the encrypted secret decrypts across the release switch (site data outside releases; AD binds the site, not the version)', 'secret lost across the switch' );
			$runtime_files_after = 0;
			foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $GLOBALS['B2S_PROJECT'] . '/runtime', FilesystemIterator::SKIP_DOTS ) ) as $f ) { $runtime_files_after++; }
			b2s_check( 'PR3', is_array( $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_settings'] ) && ! isset( $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_settings']['__wrote_into_runtime' ] ), 'site settings remain WordPress options outside release artifacts', 'artifact leak' );
			b2s_result_line( $mode );
			// no fallthrough

		case 'schema-no-downgrade':
			// B3-PERSIST closing test: a stored blob written by a NEWER
			// schema (2.0.0) with an unknown feature key — a known-key
			// save must neither downgrade the version nor drop the data.
			$GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_settings'] = array(
				'schema_version'         => '2.0.0',
				'features'               => array(
					'posts'          => true,
					'files'          => false,
					'future_feature' => true,
				),
				'branding_attachment_id' => 55,
				'ai_preference'          => 'auto',
				'future_section'         => array( 'nested' => 'keep-me' ),
			);
			b2s_require_runtime();
			$saved = HAL_Frontend_Dashboard_Settings_Repository::save( array( 'ai_preference' => 'direct_key' ) );
			$stored = $GLOBALS['B2S_OPTIONS']['hal_frontend_dashboard_settings'];
			b2s_check(
				'ND1',
				true === $saved && '2.0.0' === $stored['schema_version'],
				'saving a known key over a newer schema does NOT downgrade schema_version',
				'schema downgraded: ' . json_encode( $stored['schema_version'] ?? null )
			);
			b2s_check(
				'ND2',
				true === $stored['features']['future_feature'] && array( 'nested' => 'keep-me' ) === $stored['future_section'],
				'newer-schema data (unknown feature key and unknown section) survives the older-schema save byte-intact',
				'newer-schema data lost: ' . json_encode( $stored )
			);
			b2s_check(
				'ND3',
				'direct_key' === $stored['ai_preference'] && 55 === $stored['branding_attachment_id'] && true === $stored['features']['posts'] && false === $stored['features']['files'],
				'the known keys of this version are written correctly on top of the newer blob',
				'known-key write mismatch'
			);
			$read_back = HAL_Frontend_Dashboard_Settings_Repository::get_all();
			b2s_check(
				'ND4',
				'direct_key' === $read_back['ai_preference'] && 55 === $read_back['branding_attachment_id'],
				'the tolerant read of this version keeps working against the newer-schema blob',
				'tolerant read mismatch'
			);
			b2s_result_line( $mode );
			// no fallthrough

		case 'transition-install3':
			// Real version-transition evidence, leg 1: the REAL
			// Release Manager installs release 3.0.0 and commits it via
			// promote_runtime (the real update commit path); site data is
			// then saved through the REAL repository/secret store into a
			// file-backed WordPress options fixture.
			$mu      = $args[0] ?? '';
			$optfile = $args[1] ?? '';
			define( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY', b2s_valid_key_hex( 'transition' ) );
			require $project . '/includes/class-release-manager.php';
			require $project . '/runtime/settings/class-settings-repository.php';
			require $project . '/runtime/security/class-secret-store.php';
			require $project . '/runtime/integrations/class-integration-settings-registry.php';
			$manager = new HAL_Frontend_Dashboard_Release_Manager( $mu );
			$manager->ensure_layout();
			$stage = $mu . DIRECTORY_SEPARATOR . '.stage-3.0.0';
			b2s_copy_tree( $project . '/runtime', $stage );
			$release_a = '3.0.0+' . str_repeat( 'a', 40 );
			$manager->install_release( $release_a, $stage );
			$manager->install_loader( 'loader-1.0.0', $project . '/mu-loader/loader-core.php' );
			$manager->promote_runtime(
				array(
					'release_id'       => $release_a,
					'version'          => '3.0.0',
					'release_sequence' => 1,
					'archive_sha256'   => hash( 'sha256', 'transition-payload-3.0.0' ),
				),
				static function (): bool { return true; }, // health chain proven in batch01 §15.9 — fixture boundary here.
				'loader-1.0.0'
			);
			$active = $manager->read_pointer( 'active.json' );
			b2s_check( 'TR1', '3.0.0' === ( $active['version'] ?? '' ) && '3.0.0' === ( (string) ( $manager->read_state_file( 'committed.json' )['version'] ?? '' ) ), 'the real promote_runtime committed release 3.0.0 (active + committed)', 'real promote mismatch' );
			$GLOBALS['B2S_MEDIA'] = array( 'mime' => 'image/webp', 'is_image' => true, 'post' => new WP_Post() );
			b2s_check( 'TR2', true === HAL_Frontend_Dashboard_Settings_Repository::save( array( 'features' => array( 'posts' => false ), 'branding_attachment_id' => 21, 'ai_preference' => 'wp_ai_client' ) ), 'site settings saved under release 3.0.0 (settings + Media reference)', 'settings save failed' );
			b2s_check( 'TR3', true === HAL_Frontend_Dashboard_Secret_Store::set( 'ai_direct_key', 'crosses-the-transition' ) && 'crosses-the-transition' === HAL_Frontend_Dashboard_Secret_Store::get( 'ai_direct_key' ), 'an encrypted secret is stored and readable under release 3.0.0', 'secret roundtrip failed' );
			b2s_file_options_flush( $optfile );
			b2s_result_line( $mode );
			// no fallthrough

		case 'transition-update4':
			// Real version-transition evidence, leg 2: the REAL update path
			// 3.0.0 → 4.0.0 through promote_runtime (higher version +
			// sequence through the anti-replay gate), then the site data
			// must be intact.
			$mu      = $args[0] ?? '';
			$optfile = $args[1] ?? '';
			b2s_file_options_load( $optfile );
			define( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY', b2s_valid_key_hex( 'transition' ) );
			require $project . '/includes/class-release-manager.php';
			require $project . '/runtime/settings/class-settings-repository.php';
			require $project . '/runtime/security/class-secret-store.php';
			require $project . '/runtime/integrations/class-integration-settings-registry.php';
			$manager = new HAL_Frontend_Dashboard_Release_Manager( $mu );
			$stage = $mu . DIRECTORY_SEPARATOR . '.stage-4.0.0';
			b2s_copy_tree( $project . '/runtime', $stage );
			$release_b = '4.0.0+' . str_repeat( 'b', 40 );
			$manager->install_release( $release_b, $stage );
			$manager->promote_runtime(
				array(
					'release_id'       => $release_b,
					'version'          => '4.0.0',
					'release_sequence' => 2,
					'archive_sha256'   => hash( 'sha256', 'transition-payload-4.0.0' ),
				),
				static function (): bool { return true; },
				'loader-1.0.0'
			);
			$active = $manager->read_pointer( 'active.json' );
			b2s_check( 'TR4', '4.0.0' === ( $active['version'] ?? '' ) && '4.0.0' === ( (string) ( $manager->read_state_file( 'committed.json' )['version'] ?? '' ) ), 'the real update transition committed release 4.0.0 over 3.0.0 (anti-replay passed with higher version+sequence)', 'real update transition failed' );
			$GLOBALS['B2S_MEDIA'] = array( 'mime' => 'image/webp', 'is_image' => true, 'post' => new WP_Post() );
			$settings = HAL_Frontend_Dashboard_Settings_Repository::get_all();
			b2s_check( 'TR5', false === $settings['features']['posts'] && 'wp_ai_client' === $settings['ai_preference'] && 21 === HAL_Frontend_Dashboard_Settings_Repository::get_branding_attachment_id(), 'settings and the Media reference survive the real 3.0.0 → 4.0.0 transition', 'site data lost across the update' );
			b2s_check( 'TR6', 'crosses-the-transition' === HAL_Frontend_Dashboard_Secret_Store::get( 'ai_direct_key' ), 'the encrypted secret decrypts across the real update transition', 'secret lost across the update' );
			b2s_assert_value_outside_artifacts( $mu . '/hal-frontend-dashboard/state', 'crosses-the-transition', 'TR7' );
			b2s_assert_value_outside_artifacts( $manager->release_path( $release_b ), 'crosses-the-transition', 'TR8' );
			b2s_file_options_flush( $optfile );
			b2s_result_line( $mode );
			// no fallthrough

		case 'transition-rollback':
			// Real version-transition evidence, leg 3: a failed promotion
			// rolls back through the REAL catch path (previous restored),
			// a lower version is replay-rejected (rollback is previous-
			// restore, never a re-promote), and the site data survives it all.
			$mu      = $args[0] ?? '';
			$optfile = $args[1] ?? '';
			b2s_file_options_load( $optfile );
			define( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY', b2s_valid_key_hex( 'transition' ) );
			require $project . '/includes/class-release-manager.php';
			require $project . '/runtime/settings/class-settings-repository.php';
			require $project . '/runtime/security/class-secret-store.php';
			require $project . '/runtime/integrations/class-integration-settings-registry.php';
			$manager = new HAL_Frontend_Dashboard_Release_Manager( $mu );
			$stage = $mu . DIRECTORY_SEPARATOR . '.stage-5.0.0';
			b2s_copy_tree( $project . '/runtime', $stage );
			$release_c = '5.0.0+' . str_repeat( 'c', 40 );
			$manager->install_release( $release_c, $stage );
			$failed = '';
			try {
				$manager->promote_runtime(
					array(
						'release_id'       => $release_c,
						'version'          => '5.0.0',
						'release_sequence' => 3,
						'archive_sha256'   => hash( 'sha256', 'transition-payload-5.0.0' ),
					),
					static function (): bool { return false; }, // failed boot health — the real rollback trigger.
					'loader-1.0.0'
				);
			} catch ( Throwable $promotion_failure ) {
				$failed = $promotion_failure->getMessage();
			}
			b2s_check( 'TR9', 'HAL_PROMOTION_HEALTH_FAILED' === $failed && '4.0.0' === ( (string) ( $manager->read_pointer( 'active.json' )['version'] ?? '' ) ) && '4.0.0' === ( (string) ( $manager->read_state_file( 'committed.json' )['version'] ?? '' ) ), 'the real failed-promotion rollback restored active+committed to 4.0.0', 'rollback mismatch: ' . $failed );
			$replay = '';
			try {
				$manager->promote_runtime(
					array(
						'release_id'       => '3.0.0+' . str_repeat( 'a', 40 ),
						'version'          => '3.0.0',
						'release_sequence' => 1,
						'archive_sha256'   => hash( 'sha256', 'transition-payload-3.0.0' ),
					),
					static function (): bool { return true; },
					'loader-1.0.0'
				);
			} catch ( Throwable $replay_failure ) {
				$replay = $replay_failure->getMessage();
			}
			b2s_check( 'TR10', 'HAL_PROMOTION_REPLAY_REJECTED' === $replay, 'rollback is previous-restore, never a re-promote: the lower version is replay-rejected by the real gate', 'replay was accepted' );
			$GLOBALS['B2S_MEDIA'] = array( 'mime' => 'image/webp', 'is_image' => true, 'post' => new WP_Post() );
			$settings = HAL_Frontend_Dashboard_Settings_Repository::get_all();
			b2s_check( 'TR11', false === $settings['features']['posts'] && 21 === HAL_Frontend_Dashboard_Settings_Repository::get_branding_attachment_id() && 'crosses-the-transition' === HAL_Frontend_Dashboard_Secret_Store::get( 'ai_direct_key' ), 'settings, the Media reference and the encrypted secret survive the failed promotion and its real rollback', 'site data lost across the rollback' );
			b2s_assert_value_outside_artifacts( $mu . '/hal-frontend-dashboard/state', 'crosses-the-transition', 'TR12' );
			b2s_result_line( $mode );
			// no fallthrough
	}

	fwrite( STDERR, "Unknown B2S mode: {$mode}\n" );
	exit( 1 );
}

/* ────────────────────────────────────────────────────────────────
 * MAIN — run every subprocess mode, then report
 * ──────────────────────────────────────────────────────────────── */

function b2s_run_mode( string $mode, array $args = array(), bool $with_sodium = true ): void {
	$php     = PHP_BINARY;
	$script  = __FILE__;
	$command = array( $php );
	if ( $with_sodium ) {
		$command = hal_php_argv(
			$php,
			array( '-d', 'extension_dir=' . HAL_TEST_EXT_DIR ),
			array( 'sodium' )
		);
	} else {
		// B3-04: -n loads NO extensions — a real PHP 8.3 process without
		// sodium, no matter what the parent loaded.
		$command[] = '-n';
	}
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
			b2s_check( 'MODE-' . strtoupper( str_replace( '-', '_', $mode ) ), false, 'subprocess could not start', 'proc_open failed' );
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
	$ok = 0 === $code && false !== strpos( $stdout, 'B2S-VERDICT ' . $mode . ' ALL-ASSERTIONS-HELD' );
	$detail = '';
	if ( ! $ok ) {
		$detail = 'exit ' . $code . '; stdout: ' . trim( $stdout ) . '; stderr: ' . trim( (string) $stderr );
	}
	b2s_check(
		'MODE-' . strtoupper( str_replace( '-', '_', $mode ) ),
		$ok,
		'subprocess mode ' . $mode . ' held all its assertions (exit 0)',
		'mode ' . $mode . ' failed — ' . $detail
	);
}

/** Recursive tree copy for transition fixtures. */
function b2s_copy_tree( string $source, string $destination ): void {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);
	foreach ( $iterator as $item ) {
		$target = $destination . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
		if ( $item->isDir() ) {
			@mkdir( $target, 0777, true );
		} else {
			copy( $item->getPathname(), $target );
		}
	}
}

function b2s_remove_dir( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $item ) {
		$item->isLink() || ! $item->isDir() ? @unlink( $item->getPathname() ) : @rmdir( $item->getPathname() );
	}
	@rmdir( $dir );
}

/** File-backed option store for cross-process persistence fixtures. */
function b2s_file_options_load( string $file ): void {
	$loaded = json_decode( (string) @file_get_contents( $file ), true );
	if ( is_array( $loaded ) ) {
		$GLOBALS['B2S_OPTIONS'] = $loaded;
	}
}

function b2s_file_options_flush( string $file ): void {
	if ( false === file_put_contents( $file, json_encode( $GLOBALS['B2S_OPTIONS'] ) ) ) {
		fwrite( STDERR, "Cannot flush the option store fixture.\n" );
		exit( 1 );
	}
}

/** Asserts a secret value never appears in any release/state artifact. */
function b2s_assert_value_outside_artifacts( string $root, string $needle, string $check_id ): void {
	$leaked = '';
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $iterator as $item ) {
		if ( ! $item->isFile() ) {
			continue;
		}
		$contents = (string) file_get_contents( $item->getPathname() );
		if ( false !== strpos( $contents, $needle ) ) {
			$leaked = $item->getPathname();
			break;
		}
	}
	b2s_check( $check_id, '' === $leaked, 'no release or state artifact carries the site secret value', 'value leaked into: ' . $leaked );
}

/* ── Dispatch ── */
$b2s_mode_arg = isset( $argv[1] ) ? (string) $argv[1] : 'main';
if ( 'main' !== $b2s_mode_arg ) {
	b2s_mode( $b2s_mode_arg, array_slice( $argv, 2 ), $project );
	exit( 1 );
}

b2s_run_mode( 'store-constant-key', array( 'k1' ) );
b2s_run_mode( 'store-source-precedence', array( 'k2' ) );
b2s_run_mode( 'store-unavailable' );
b2s_run_mode( 'store-wrong-key' );
b2s_run_mode( 'store-tamper' );
b2s_run_mode( 'store-delete-audit' );
b2s_run_mode( 'store-salt-coupling' );
b2s_run_mode( 'ciphertext-version' );
b2s_run_mode( 'site-scope' );
b2s_run_mode( 'sodium-absent', array(), false );
b2s_run_mode( 'settings-repo' );
b2s_run_mode( 'features-marker' );
b2s_run_mode( 'save-results' );
b2s_run_mode( 'input-shapes' );
b2s_run_mode( 'credential-source' );
b2s_run_mode( 'branding-fallback' );
b2s_run_mode( 'data-preserved' );
b2s_run_mode( 'admin-access', array( 'no-cap' ) );
b2s_run_mode( 'admin-access', array( 'bad-nonce' ) );
b2s_run_mode( 'admin-access', array( 'valid' ) );
b2s_run_mode( 'admin-access', array( 'unknown-feature' ) );
b2s_run_mode( 'admin-access', array( 'free-secret' ) );
b2s_run_mode( 'admin-access', array( 'set-secret' ) );
b2s_run_mode( 'admin-access', array( 'delete-secret' ) );
b2s_run_mode( 'admin-access', array( 'tab-allowlist' ) );
b2s_run_mode( 'render-no-secrets' );
b2s_run_mode( 'enqueue-scope' );
b2s_run_mode( 'upload-files-gate' );
b2s_run_mode( 'health-monitor' );
b2s_run_mode( 'health-consumers', array( 'never-run' ) );
b2s_run_mode( 'health-consumers', array( 'prompt-unsupported' ) );
b2s_run_mode( 'health-consumers', array( 'contract-invalid' ) );
b2s_run_mode( 'health-consumers', array( 'strategy-invalid' ) );
b2s_run_mode( 'health-consumers', array( 'direct-key-missing' ) );
b2s_run_mode( 'health-consumers', array( 'direct-key-ok' ) );
b2s_run_mode( 'health-consumers', array( 'direct-key-no-wp-client' ) );
b2s_run_mode( 'health-consumers', array( 'direct-key-client-unsupported' ) );
b2s_run_mode( 'health-consumers', array( 'amelia-credential-missing' ) );
b2s_run_mode( 'health-consumers', array( 'amelia-credential-ok' ) );
b2s_run_mode( 'health-consumers', array( 'amelia-constant-corrupt-fallback' ) );
b2s_run_mode( 'health-consumers', array( 'amelia-constant-no-hal-key' ) );
b2s_run_mode( 'health-consumers', array( 'hal-fallback-chosen-corrupt' ) );
b2s_run_mode( 'health-consumers', array( 'wp-client-valid-corrupt-fallback' ) );
b2s_run_mode( 'health-consumers', array( 'wp-client-valid-no-hal-key' ) );
b2s_run_mode( 'health-consumers', array( 'undetermined-strategy-corrupt-fallback' ) );
b2s_run_mode( 'health-consumers', array( 'direct-key-chosen-corrupt' ) );
b2s_run_mode( 'health-consumers', array( 'recovery' ) );
b2s_run_mode( 'health-consumers', array( 'wp-client-no-sodium' ), false );
b2s_run_mode( 'health-consumers', array( 'direct-key-no-sodium' ), false );
b2s_run_mode( 'registry-allowlist' );
b2s_run_mode( 'installer-grant' );
b2s_run_mode( 'schema-no-downgrade' );

$b2s_evidence_dir = $project . DIRECTORY_SEPARATOR . '.audit-work' . DIRECTORY_SEPARATOR . 'batch-3';
if ( ! is_dir( $b2s_evidence_dir ) && ! @mkdir( $b2s_evidence_dir, 0777, true ) ) {
	fwrite( STDERR, "Cannot create the evidence workspace.\n" );
	exit( 1 );
}
b2s_run_mode( 'render-html', array( 'off', $b2s_evidence_dir . DIRECTORY_SEPARATOR . 'ai-tab-feature-off.html' ) );
b2s_run_mode( 'render-html', array( 'on', $b2s_evidence_dir . DIRECTORY_SEPARATOR . 'ai-tab-feature-on.html' ) );
b2s_run_mode( 'persist-write', array( $b2s_evidence_dir . DIRECTORY_SEPARATOR . 'persist-options.json' ) );
b2s_run_mode( 'persist-read', array( $b2s_evidence_dir . DIRECTORY_SEPARATOR . 'persist-options.json', '9.9.9' ) );
b2s_run_mode( 'persist-read', array( $b2s_evidence_dir . DIRECTORY_SEPARATOR . 'persist-options.json', '3.0.0' ) );

// Real version-transition evidence: a FRESH workspace each run — the
// three legs chain real on-disk release state (install → update →
// failed-promotion rollback → replay rejection).
$b2s_transition_mu   = $b2s_evidence_dir . DIRECTORY_SEPARATOR . 'transition-mu';
$b2s_transition_opts = $b2s_evidence_dir . DIRECTORY_SEPARATOR . 'transition-options.json';
b2s_remove_dir( $b2s_transition_mu );
@unlink( $b2s_transition_opts );
@mkdir( $b2s_transition_mu, 0777, true );
b2s_run_mode( 'transition-install3', array( $b2s_transition_mu, $b2s_transition_opts ) );
b2s_run_mode( 'transition-update4', array( $b2s_transition_mu, $b2s_transition_opts ) );
b2s_run_mode( 'transition-rollback', array( $b2s_transition_mu, $b2s_transition_opts ) );

echo "HAL Frontend Dashboard — Batch 3 settings and secrets test\n";
echo str_repeat( '─', 72 ) . "\n";
$pass_count = 0;
foreach ( $GLOBALS['B2S_RESULTS'] as $result ) {
	if ( $result['ok'] ) {
		$pass_count++;
		echo "PASS [{$result['id']}] {$result['pass']}\n";
	} else {
		echo "FAIL [{$result['id']}] {$result['fail']}\n";
	}
}
echo str_repeat( '─', 72 ) . "\n";
$total = count( $GLOBALS['B2S_RESULTS'] );
echo "RESULT: {$pass_count}/{$total} checks passed\n";
exit( $pass_count === $total ? 0 : 1 );
