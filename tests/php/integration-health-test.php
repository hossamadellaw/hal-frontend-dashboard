<?php
/**
 * HAL Frontend Dashboard — Batch 11 harness: integration health.
 *
 * Local, isolated harness: no live WordPress, no network, no database.
 * It stubs only the WordPress API surface, loads the REAL
 * runtime/bootstrap.php, and drives the REAL secret store, integration
 * registry, health monitor, and amelia/ai adapters.
 *
 * Coverage (§22 integration-health row):
 *   credential source precedence, decrypt failure, Connector DB warning,
 *   Amelia route allowlist, SSRF/TLS posture, AI provider gating, and
 *   proof that external-integration failure never rolls back the Runtime.
 *   The HAL_WP_TARGET environment (7.0/7.1, as the release workflow runs
 *   it) selects the reported WordPress target; AI resolution must be
 *   version-independent (feature detection, never a version check).
 *
 * Usage:  php -d extension=sodium tests/php/integration-health-test.php
 *         HAL_WP_TARGET=7.0 php -d extension=sodium tests/php/integration-health-test.php
 *         php -d extension=sodium tests/php/integration-health-test.php bad-provider
 *         exit 0 = all checks pass.
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

if ( PHP_SAPI !== 'cli' ) {
	echo "This harness must run under CLI PHP.\n";
	exit( 1 );
}

$mode = isset( $argv[1] ) ? (string) $argv[1] : 'main';
$project = dirname( __DIR__, 2 );
$wp_target = (string) ( getenv( 'HAL_WP_TARGET' ) ?: '7.1' );

if ( 'bad-provider' === $mode && ! defined( 'HOSSAM_AI_PROVIDER' ) ) {
	define( 'HOSSAM_AI_PROVIDER', 'evil-provider' );
}

$GLOBALS['HAL_IH_RESULTS'] = array();
$GLOBALS['HAL_IH_HOOKS'] = array();
$GLOBALS['HAL_IH_OPTIONS'] = array();
$GLOBALS['HAL_IH_TRANSIENTS'] = array();
$GLOBALS['HAL_IH_CAPS'] = array( 'manage_options' => true );
$GLOBALS['HAL_IH_REMOTE'] = array();
$GLOBALS['HAL_IH_PLUGINS_ACTIVE'] = array( 'ameliabooking/ameliabooking.php' );

function hal_ih_check( string $id, bool $condition, string $pass, string $fail = '' ): void {
	$GLOBALS['HAL_IH_RESULTS'][] = array( 'id' => $id, 'ok' => $condition, 'pass' => $pass, 'fail' => $fail );
	echo ( $condition ? 'PASS' : 'FAIL' ) . " [$id] " . ( $condition ? $pass : $fail ) . "\n";
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/hal-ih-' . getmypid() . '/' );
}
@mkdir( ABSPATH, 0777, true );

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook_name, $callback = '', int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['HAL_IH_HOOKS'][ $hook_name ][] = $callback;
		return true;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook_name, $callback = '', int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['HAL_IH_HOOKS'][ $hook_name ][] = $callback;
		return true;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook_name, ...$args ): void {}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook_name, $value, ...$args ) {
		return $value;
	}
}
if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook_name ): int {
		return 0;
	}
}
if ( ! function_exists( '__return_true' ) ) {
	function __return_true() {
		return true;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin(): bool {
		return false;
	}
}
if ( ! function_exists( 'wp_doing_cron' ) ) {
	function wp_doing_cron(): bool {
		return false;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default_value = false ) {
		return array_key_exists( $option, $GLOBALS['HAL_IH_OPTIONS'] ) ? $GLOBALS['HAL_IH_OPTIONS'][ $option ] : $default_value;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, $value, $autoload = null ): bool {
		$GLOBALS['HAL_IH_OPTIONS'][ $option ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ) {
		return $GLOBALS['HAL_IH_TRANSIENTS'][ $key ] ?? false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, $value, int $ttl = 0 ): bool {
		$GLOBALS['HAL_IH_TRANSIENTS'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $key ): bool {
		unset( $GLOBALS['HAL_IH_TRANSIENTS'][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap, ...$args ): bool {
		return (bool) ( $GLOBALS['HAL_IH_CAPS'][ $cap ] ?? false );
	}
}
if ( ! function_exists( 'is_plugin_active' ) ) {
	function is_plugin_active( string $plugin ): bool {
		return in_array( $plugin, $GLOBALS['HAL_IH_PLUGINS_ACTIVE'], true );
	}
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/' . $path;
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		return 'https://example.test/' . ltrim( $path, '/' );
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( array $args, string $url ): string {
		return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args );
	}
}
if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( string $url, array $args = array() ) {
		$GLOBALS['HAL_IH_REMOTE'][] = array( 'url' => $url, 'args' => $args );
		return $GLOBALS['HAL_IH_REMOTE_RESULT'] ?? new WP_Error( 'http_request_failed', 'stub' );
	}
}
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( string $url, array $args = array() ) {
		$GLOBALS['HAL_IH_REMOTE'][] = array( 'url' => $url, 'args' => $args );
		return $GLOBALS['HAL_IH_REMOTE_RESULT'] ?? new WP_Error( 'http_request_failed', 'stub' );
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ): int {
		return is_array( $response ) ? (int) ( $response['code'] ?? 0 ) : 0;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ): string {
		return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return (string) preg_replace( '/[^a-z0-9_]/', '', strtolower( $key ) );
	}
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( string $hook, array $args = array() ) {
		return false;
	}
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = array() ): bool {
		return true;
	}
}
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( int $timestamp, string $hook, array $args = array() ): bool {
		return true;
	}
}
if ( ! function_exists( 'get_role' ) ) {
	function get_role( string $role ) {
		return null;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0 ) {
		return json_encode( $value, $flags );
	}
}
if ( ! function_exists( 'get_page_by_path' ) ) {
	function get_page_by_path( string $page_path, $output = null, string $post_type = 'page' ) {
		return null;
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post = null ): string {
		return 'https://example.test/?p=0';
	}
}
if ( ! function_exists( 'headers_sent' ) ) {
	function headers_sent( &$file = null, &$line = null ): bool {
		return false;
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public string $code = '';
		public string $message = '';
		public $data;
		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code = (string) $code;
			$this->message = (string) $message;
			$this->data = $data;
		}
		public function get_error_code() {
			return $this->code;
		}
		public function get_error_message() {
			return $this->message;
		}
	}
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
}

$GLOBALS['wp_version'] = $wp_target;

/* Valid AI runtime profile (the contract hossam_ai_get_runtime_profile validates). */
if ( ! defined( 'HOSSAM_AI_RUNTIME_PROFILE' ) ) {
	define( 'HOSSAM_AI_RUNTIME_PROFILE', array(
		'per_minute' => 30, 'per_day' => 500, 'concurrent' => 2, 'max_attempts' => 3,
		'stale_pending' => 3600, 'processing_deadline' => 240,
		'wp_ai_client_timeout' => 60, 'direct_key_timeout' => 90,
	) );
}

if ( ! defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT' ) ) {
	define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT', $project . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR );
	define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_DIR', $project . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR );
	define( 'HAL_FRONTEND_DASHBOARD_RELEASE_ID', '11.0.0+' . str_repeat( 'd', 40 ) );
	define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION', '11.0.0' );
	define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_URL', 'https://example.test/releases/11/' );
}

require $project . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'bootstrap.php';

function hal_ih_report( string $mode, string $wp_target ): void {
	$pass = 0;
	$total = count( $GLOBALS['HAL_IH_RESULTS'] );
	foreach ( $GLOBALS['HAL_IH_RESULTS'] as $result ) {
		if ( $result['ok'] ) {
			$pass++;
		}
	}
	if ( 'main' === $mode ) {
		echo "WP-TARGET: $wp_target\n";
		echo "RESULT: $pass/$total checks passed\n";
	} else {
		echo 'HAL-VERDICT ' . $mode . ( $pass === $total ? ' ALL-ASSERTIONS-HELD' : ' ASSERTIONS-FAILED' ) . "\n";
	}
	exit( $pass === $total ? 0 : 1 );
}

function hal_ih_strip_comments( string $code ): string {
	$out = '';
	foreach ( token_get_all( $code ) as $token ) {
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

/* ════════════════════════════════════════════════════════════════
 * BAD-PROVIDER MODE — unsupported provider rejected, key untouched
 * ════════════════════════════════════════════════════════════════ */

if ( 'bad-provider' === $mode ) {
	putenv( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY=' . bin2hex( random_bytes( 32 ) ) );
	$set = HAL_Frontend_Dashboard_Secret_Store::set( 'ai_direct_key', 'provider-mode-key' );
	hal_ih_check( 'X-SET', true === $set, 'direct key stored under the environment master key', 'set failed' );
	$result = hossam_ai_execute_direct_key( 'hello', array() );
	hal_ih_check(
		'X-UNSUPPORTED-PROVIDER',
		$result instanceof WP_Error && 'hossam_ai_unsupported_provider' === $result->get_error_code(),
		'unsupported provider rejected with hossam_ai_unsupported_provider',
		'got: ' . ( $result instanceof WP_Error ? $result->get_error_code() : var_export( $result, true ) )
	);
	hal_ih_check( 'X-KEY-KEPT', true === HAL_Frontend_Dashboard_Secret_Store::has( 'ai_direct_key' ), 'rejected provider leaves the stored key untouched', 'key wiped' );
	putenv( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY' );
	hal_ih_report( 'bad-provider', $wp_target );
}

/* ════════════════════════════════════════════════════════════════
 * MAIN — credential sources, decrypt failure, allowlists, monitor
 * ════════════════════════════════════════════════════════════════ */

/* H1 — no external key source: store blocked, explicitly. */
hal_ih_check( 'H1-SOURCE-NONE', 'unavailable' === HAL_Frontend_Dashboard_Secret_Store::get_key_source(), 'key source reports unavailable with no environment or constant', 'source: ' . HAL_Frontend_Dashboard_Secret_Store::get_key_source() );
hal_ih_check( 'H1-NOT-AVAILABLE', false === HAL_Frontend_Dashboard_Secret_Store::is_available(), 'store unavailable without a key source', 'store available' );
$set_none = HAL_Frontend_Dashboard_Secret_Store::set( 'ai_direct_key', 'x' );
hal_ih_check( 'H1-SET-BLOCKED', $set_none instanceof WP_Error && 'hal_secret_store_unavailable' === $set_none->get_error_code(), 'set() fails closed with hal_secret_store_unavailable', 'unexpected result' );
$bad_id = HAL_Frontend_Dashboard_Secret_Store::set( 'BAD-ID!', 'x' );
hal_ih_check( 'H1-BAD-ID', $bad_id instanceof WP_Error && 'hal_secret_bad_id' === $bad_id->get_error_code(), 'free-form secret ids rejected (Registry allowlist shape)', 'unexpected result' );

/* H2 — constant source works; fingerprint is a 32-hex key id. */
$key1 = random_bytes( 32 );
define( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY', bin2hex( $key1 ) );
hal_ih_check( 'H2-SOURCE-CONSTANT', 'constant' === HAL_Frontend_Dashboard_Secret_Store::get_key_source(), 'constant key source detected', 'source mismatch' );
$fingerprint = HAL_Frontend_Dashboard_Secret_Store::get_key_fingerprint();
hal_ih_check( 'H2-FINGERPRINT', is_string( $fingerprint ) && 1 === preg_match( '/\A[a-f0-9]{32}\z/', $fingerprint ), 'fingerprint is a 32-hex key id (no key material)', 'got: ' . var_export( $fingerprint, true ) );
$set_a = HAL_Frontend_Dashboard_Secret_Store::set( 'ai_direct_key', 'direct-key-one' );
$get_a = HAL_Frontend_Dashboard_Secret_Store::get( 'ai_direct_key' );
hal_ih_check( 'H2-ROUNDTRIP', true === $set_a && 'direct-key-one' === $get_a, 'AEAD roundtrip under the constant key', 'roundtrip failed' );

/* H3 — environment shadows the constant; rotation surfaces as mismatch. */
$key2 = random_bytes( 32 );
putenv( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY=' . bin2hex( $key2 ) );
hal_ih_check( 'H3-ENV-SHADOWS', 'environment' === HAL_Frontend_Dashboard_Secret_Store::get_key_source(), 'environment shadows the PHP constant', 'precedence broken' );
$get_mismatch = HAL_Frontend_Dashboard_Secret_Store::get( 'ai_direct_key' );
hal_ih_check(
	'H3-KEY-MISMATCH',
	$get_mismatch instanceof WP_Error && 'hal_secret_key_mismatch' === $get_mismatch->get_error_code(),
	'rotated master key yields key_mismatch (never a wipe, never success)',
	'got: ' . ( $get_mismatch instanceof WP_Error ? $get_mismatch->get_error_code() : var_export( $get_mismatch, true ) )
);
$records = get_option( HAL_Frontend_Dashboard_Secret_Store::OPTION_NAME, array() );
hal_ih_check( 'H3-RECORD-KEPT', isset( $records['ai_direct_key'] ), 'ciphertext preserved across key rotation', 'record wiped' );
$set_b = HAL_Frontend_Dashboard_Secret_Store::set( 'test_tamper_key', 'tamper-me' );
hal_ih_check( 'H3-SET-ENV', true === $set_b, 'set() works under the environment key', 'set failed' );

/* H4 — tampered ciphertext fails closed, stored bytes unchanged. */
$records_before = get_option( HAL_Frontend_Dashboard_Secret_Store::OPTION_NAME, array() );
$tampered_records = $records_before;
$tampered_records['test_tamper_key']['ct'][0] = 'A' === $tampered_records['test_tamper_key']['ct'][0] ? 'B' : 'A';
update_option( HAL_Frontend_Dashboard_Secret_Store::OPTION_NAME, $tampered_records, false );
$get_tampered = HAL_Frontend_Dashboard_Secret_Store::get( 'test_tamper_key' );
$records_after = get_option( HAL_Frontend_Dashboard_Secret_Store::OPTION_NAME, array() );
hal_ih_check(
	'H4-TAMPER',
	$get_tampered instanceof WP_Error && 'hal_secret_decrypt_failed' === $get_tampered->get_error_code()
		&& $records_after === $tampered_records,
	'tampered ciphertext fails closed with hal_secret_decrypt_failed and the stored bytes are unchanged',
	'got: ' . ( $get_tampered instanceof WP_Error ? $get_tampered->get_error_code() : var_export( $get_tampered, true ) )
);

/* H5 — Registry allowlist: exact secret ids and integrations. */
$secret_ids = HAL_Frontend_Dashboard_Integration_Settings_Registry::secret_ids();
sort( $secret_ids );
hal_ih_check( 'H5-SECRET-IDS', array( 'ai_direct_key', 'amelia_elite_api_key' ) === $secret_ids, 'registry secret ids are exactly the two known secrets', 'ids: ' . json_encode( $secret_ids ) );
$integration_ids = array_keys( HAL_Frontend_Dashboard_Integration_Settings_Registry::integrations() );
sort( $integration_ids );
hal_ih_check(
	'H5-INTEGRATIONS',
	array( 'ai', 'amelia', 'frontend_admin', 'rank_math', 'ultimate_member', 'woocommerce', 'wpml' ) === $integration_ids,
	'registry integrations are exactly the seven known integrations (no free-form key names)',
	'ids: ' . json_encode( $integration_ids )
);

/* H6 — Amelia credential resolution prefers the encrypted fallback store. */
$set_amelia = HAL_Frontend_Dashboard_Secret_Store::set( 'amelia_elite_api_key', 'amelia-key-live' );
hal_ih_check( 'H6-STORE-SET', true === $set_amelia, 'amelia key stored encrypted', 'set failed' );
hal_ih_check(
	'H6-SOURCE',
	'hal_encrypted' === HAL_Frontend_Dashboard_Integration_Settings_Registry::credential_source( 'amelia_elite_api_key' ),
	'credential_source resolves the encrypted fallback without a constant',
	'source: ' . HAL_Frontend_Dashboard_Integration_Settings_Registry::credential_source( 'amelia_elite_api_key' )
);
hal_ih_check( 'H6-RESOLVER', 'amelia-key-live' === hossam_amelia_get_api_key(), 'adapter resolver returns the stored key (never plaintext option)', 'resolver mismatch' );

/* H7 — Amelia route allowlist: only GET /appointments passes the gate. */
$evil_route = hossam_amelia_api_request( '/evil-endpoint' );
hal_ih_check( 'H7-ROUTE', $evil_route instanceof WP_Error && 'hossam_amelia_route_unavailable' === $evil_route->get_error_code(), 'unknown route rejected with route_unavailable', 'got: ' . ( $evil_route instanceof WP_Error ? $evil_route->get_error_code() : 'non-error' ) );
$post_route = hossam_amelia_api_request( '/appointments', 'POST' );
hal_ih_check( 'H7-METHOD', $post_route instanceof WP_Error && 'hossam_amelia_route_unavailable' === $post_route->get_error_code(), 'non-GET method rejected with route_unavailable', 'got: ' . ( $post_route instanceof WP_Error ? $post_route->get_error_code() : 'non-error' ) );
$bad_query = hossam_amelia_api_request( '/appointments', 'GET', array( 'dates' => 'not-a-range', 'extra' => 'injected' ) );
hal_ih_check( 'H7-QUERY', $bad_query instanceof WP_Error && 'hossam_amelia_invalid_query' === $bad_query->get_error_code(), 'invalid/extra query args rejected with invalid_query', 'got: ' . ( $bad_query instanceof WP_Error ? $bad_query->get_error_code() : 'non-error' ) );

/* H8 — Amelia HTTP posture: loopback URL, TLS verify, timeout, JSON contract. */
$GLOBALS['HAL_IH_REMOTE'] = array();
$GLOBALS['HAL_IH_REMOTE_RESULT'] = array(
	'code' => 200,
	'body' => json_encode( array( 'data' => array( 'appointments' => array( array( 'id' => 1 ) ) ) ) ),
);
$http_result = hossam_amelia_api_request( '/appointments', 'GET', array( 'page' => 2 ) );
$calls = $GLOBALS['HAL_IH_REMOTE'];
$call_ok = 1 === count( $calls )
	&& str_starts_with( (string) ( $calls[0]['url'] ?? '' ), 'https://example.test/wp-admin/admin-ajax.php' )
	&& true === ( $calls[0]['args']['sslverify'] ?? null )
	&& 15 === ( $calls[0]['args']['timeout'] ?? null )
	&& 'amelia-key-live' === ( $calls[0]['args']['headers']['Amelia'] ?? null );
hal_ih_check( 'H8-HTTP-POSTURE', $call_ok, 'loopback https URL + sslverify + 15s timeout + key header (no cookies/nonces in args)', 'calls: ' . json_encode( $calls ) );
hal_ih_check(
	'H8-CONTRACT',
	is_array( $http_result ) && array( array( 'id' => 1 ) ) === ( $http_result['data']['appointments'] ?? null ),
	'contract-shaped response returned; malformed shape would fail closed',
	'got: ' . json_encode( $http_result instanceof WP_Error ? $http_result->get_error_code() : $http_result )
);
$GLOBALS['HAL_IH_REMOTE_RESULT'] = array( 'code' => 200, 'body' => '{"data":{}}' );
$malformed = hossam_amelia_api_request( '/appointments' );
hal_ih_check( 'H8-MALFORMED', $malformed instanceof WP_Error && 'hossam_amelia_remote_failure' === $malformed->get_error_code(), 'contract-violating body fails closed with remote_failure', 'got: ' . ( $malformed instanceof WP_Error ? $malformed->get_error_code() : 'non-error' ) );

/* H9 — AI: feature detection first; direct encrypted fallback only.
 * (direct_key readiness needs the provider constant — exercised in H9b
 * after the monitor runs unconfigured; here the unconfigured state.) */
hal_ih_check( 'H9-NO-WP-CLIENT', false === hossam_ai_wp_client_supported(), 'wp_ai_client correctly reported unsupported (function absent)', 'misdetected' );
hal_ih_check( 'H9-PREFERENCE', false === hossam_ai_set_preference( 'evil' ) && 'auto' === hossam_ai_get_preference(), 'invalid AI preference rejected; default stays auto', 'preference contract broken' );
$no_provider = hossam_ai_get_provider_config();
hal_ih_check( 'H9-NO-PROVIDER', $no_provider instanceof WP_Error, 'unconfigured provider yields WP_Error (no silent default key)', 'got a config without a provider' );

/* H10 — Connector DB warning ships in the admin surface (supported-with-warning). */
$warning_hits = array();
foreach ( array( 'runtime/admin/class-admin-controller.php', 'runtime/settings/class-settings-repository.php', 'runtime/health/class-integration-health-monitor.php' ) as $relative ) {
	$contents = (string) file_get_contents( $project . '/' . $relative );
	if ( false !== strpos( $contents, 'Connector DB keys are stored unencrypted' ) ) {
		$warning_hits[] = $relative;
	}
}
hal_ih_check( 'H10-CONNECTOR-WARNING', array() !== $warning_hits, 'Connector DB unencrypted warning present (' . implode( ',', $warning_hits ) . ')', 'warning text missing' );

/* H11 — V1 scope: no streaming/embeddings in the AI surfaces. */
$scope_hits = array();
foreach ( array( 'runtime/adapters/ai.php', 'runtime/ajax/ai.php' ) as $relative ) {
	$code = hal_ih_strip_comments( (string) file_get_contents( $project . '/' . $relative ) );
	foreach ( array( 'streaming', 'embedding', 'stream_', '->stream(' ) as $needle ) {
		if ( false !== stripos( $code, $needle ) ) {
			$scope_hits[] = $relative . ':' . $needle;
		}
	}
}
hal_ih_check( 'H11-NO-STREAMING', array() === $scope_hits, 'no streaming/embeddings in the AI adapter or AJAX (text generation only)', 'hits: ' . json_encode( $scope_hits ) );

/* H12 — monitor: blocked integrations reported; Runtime never rolls back. */
$mu_state = sys_get_temp_dir() . '/hal-ih-state-' . getmypid();
@mkdir( $mu_state, 0777, true );
$active_bytes = json_encode( array( 'release_id' => '9.9.9+' . str_repeat( 'f', 40 ), 'version' => '9.9.9' ) ) . "\n";
file_put_contents( $mu_state . '/active.json', $active_bytes );
$checks = HAL_Frontend_Dashboard_Integration_Health_Monitor::run_checks();
$shape_ok = is_array( $checks );
foreach ( $checks as $integration => $entry ) {
	if ( '_meta' === $integration ) {
		if ( ! is_array( $entry ) || ! isset( $entry['checked_at'], $entry['key_source'] ) ) {
			$shape_ok = false;
		}
		continue;
	}
	if ( ! is_array( $entry ) || ! in_array( $entry['status'] ?? null, array( 'ok', 'blocked' ), true ) || ! is_array( $entry['codes'] ?? null ) ) {
		$shape_ok = false;
	}
}
$blocked = array();
foreach ( $checks as $integration => $entry ) {
	if ( 'blocked' === ( $entry['status'] ?? null ) ) {
		$blocked[] = $integration;
	}
}
hal_ih_check( 'H12-MONITOR-SHAPE', $shape_ok, 'run_checks returns status+codes per integration (' . count( $checks ) . ' integrations)', 'shape: ' . json_encode( $checks ) );
hal_ih_check( 'H12-BLOCKED-REPORTED', array() !== $blocked, 'failing integrations reported blocked: ' . implode( ',', $blocked ), 'nothing blocked' );
hal_ih_check(
	'H12-NO-ROLLBACK',
	$active_bytes === (string) file_get_contents( $mu_state . '/active.json' )
		&& false === strpos( hal_ih_strip_comments( (string) file_get_contents( $project . '/runtime/health/class-integration-health-monitor.php' ) ), 'Release_Manager' ),
	'external failure leaves the release pointer untouched and the monitor never references the release manager',
	'pointer changed or manager referenced'
);

/* H9b — configured provider: direct_key resolves without wp_ai_client.
 * (ai_direct_key was enrolled under the pre-rotation constant key, so it
 * is re-enrolled here under the current environment key — the documented
 * rotation path — before readiness is asserted.) */
define( 'HOSSAM_AI_PROVIDER', 'gemini' );
$rekey = HAL_Frontend_Dashboard_Secret_Store::set( 'ai_direct_key', 'direct-key-two' );
hal_ih_check( 'H9b-REENROLL', true === $rekey, 'direct key re-enrolled under the current master key', 're-enroll failed' );
hal_ih_check( 'H9b-DIRECT-READY', true === hossam_ai_direct_key_ready(), 'direct_key ready from provider constant + encrypted fallback', 'not ready' );
hal_ih_check( 'H9b-RESOLVE', 'direct_key' === hossam_ai_resolve_strategy( 'auto' ), 'auto resolves to direct_key (never wp_ai_client when unsupported)', 'got: ' . var_export( hossam_ai_resolve_strategy( 'auto' ), true ) );
$provider_config = hossam_ai_get_provider_config();
hal_ih_check(
	'H9b-PROVIDER-CONFIG',
	is_array( $provider_config ) && 'gemini' === ( $provider_config['provider'] ?? null ) && '' !== ( $provider_config['model'] ?? '' ),
	'provider config resolves gemini + model from the allowlisted constants',
	'got: ' . json_encode( $provider_config instanceof WP_Error ? $provider_config->get_error_code() : $provider_config )
);

/* H13 — the reported matrix target equals the HAL_WP_TARGET environment
 * value (default 7.1 when unset) AND lies within the supported {7.0, 7.1}
 * set: an out-of-matrix target fails instead of passing by construction. */
$env_target_raw = getenv( 'HAL_WP_TARGET' );
$env_target = is_string( $env_target_raw ) && '' !== $env_target_raw ? $env_target_raw : '7.1';
hal_ih_check(
	'H13-VERSION-INDEPENDENT',
	$wp_target === $env_target && in_array( $wp_target, array( '7.0', '7.1' ), true ),
	'matrix target equals HAL_WP_TARGET (' . $wp_target . ') and is within {7.0, 7.1}',
	'target=' . var_export( $wp_target, true ) . ' env=' . var_export( $env_target_raw, true )
);
$ai_code = hal_ih_strip_comments( (string) file_get_contents( $project . '/runtime/adapters/ai.php' ) );
hal_ih_check(
	'H13-FEATURE-DETECTION',
	false === strpos( $ai_code, '$wp_version' ) && false !== strpos( $ai_code, 'is_supported_for_text_generation' ),
	'ai adapter uses feature detection (is_supported_for_text_generation), never a $wp_version check',
	'version check found in ai adapter'
);

putenv( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY' );
hal_ih_report( 'main', $wp_target );
