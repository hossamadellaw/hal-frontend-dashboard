<?php
/**
 * HAL Frontend Dashboard — Batch 11 harness: contracts.
 *
 * Local, isolated harness: no live WordPress, no network, no database.
 * It stubs only the WordPress API surface, loads the REAL
 * runtime/bootstrap.php, and then verifies the frozen contracts against
 * the REAL registrations and the REAL source files (static scans strip
 * comments via tokens, so documentation never satisfies an assertion).
 *
 * Coverage (§22 contracts row):
 *   AJAX action inventory (exact 26), no nopriv twins for hossam_*
 *   actions, JSON response + nonce discipline per ajax file, feature/
 *   registry gates per ajax file, registry consumption in the integration
 *   adapters, TLS-only endpoints, and the registry-gated seo action
 *   staying unregistered.
 *
 * Usage:  php tests/php/contracts-test.php
 *         exit 0 = all checks pass.
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

if ( PHP_SAPI !== 'cli' ) {
	echo "This harness must run under CLI PHP.\n";
	exit( 1 );
}

$project = dirname( __DIR__, 2 );

$GLOBALS['HAL_CT_RESULTS'] = array();
$GLOBALS['HAL_CT_HOOKS'] = array();
$GLOBALS['HAL_CT_OPTIONS'] = array();
$GLOBALS['HAL_CT_SCHEDULED'] = array();

function hal_ct_check( string $id, bool $condition, string $pass, string $fail = '' ): void {
	$GLOBALS['HAL_CT_RESULTS'][] = array( 'id' => $id, 'ok' => $condition, 'pass' => $pass, 'fail' => $fail );
	echo ( $condition ? 'PASS' : 'FAIL' ) . " [$id] " . ( $condition ? $pass : $fail ) . "\n";
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/hal-ct-' . getmypid() . '/' );
}
@mkdir( ABSPATH, 0777, true );

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook_name, $callback = '', int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['HAL_CT_HOOKS'][ $hook_name ][] = $callback;
		return true;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook_name, $callback = '', int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['HAL_CT_HOOKS'][ $hook_name ][] = $callback;
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
		return array_key_exists( $option, $GLOBALS['HAL_CT_OPTIONS'] ) ? $GLOBALS['HAL_CT_OPTIONS'][ $option ] : $default_value;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, $value, $autoload = null ): bool {
		$GLOBALS['HAL_CT_OPTIONS'][ $option ] = $value;
		return true;
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
if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		return 'https://example.test/' . ltrim( $path, '/' );
	}
}
if ( ! function_exists( 'headers_sent' ) ) {
	function headers_sent( &$file = null, &$line = null ): bool {
		return false;
	}
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT' ) ) {
	define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT', $project . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR );
	define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_DIR', $project . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR );
	define( 'HAL_FRONTEND_DASHBOARD_RELEASE_ID', '11.0.0+' . str_repeat( 'c', 40 ) );
	define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION', '11.0.0' );
	define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_URL', 'https://example.test/releases/11/' );
}

require $project . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'bootstrap.php';

/* C1 — exact AJAX action inventory (26 registered hossam_* actions). */
$expected_actions = array(
	'hossam_ai_get_job_status', 'hossam_ai_set_preference', 'hossam_ai_submit_job',
	'hossam_create_article', 'hossam_delete_file', 'hossam_finance_summary',
	'hossam_get_inbox', 'hossam_get_members', 'hossam_get_my_files',
	'hossam_get_notifications', 'hossam_get_orders', 'hossam_get_payment_methods',
	'hossam_get_products', 'hossam_get_saved_tokens', 'hossam_get_upcoming_appointments',
	'hossam_lazy_appointments', 'hossam_mark_all_read', 'hossam_mark_message_read',
	'hossam_mark_read', 'hossam_restore_article', 'hossam_restore_file',
	'hossam_send_message', 'hossam_translate_article', 'hossam_trash_article',
	'hossam_update_article', 'hossam_upload_file',
);
sort( $expected_actions, SORT_STRING );
$actual_actions = array();
foreach ( $GLOBALS['HAL_CT_HOOKS'] as $hook => $callbacks ) {
	if ( str_starts_with( $hook, 'wp_ajax_hossam_' ) ) {
		$actual_actions[] = substr( $hook, strlen( 'wp_ajax_' ) );
	}
}
sort( $actual_actions, SORT_STRING );
hal_ct_check(
	'C1-INVENTORY',
	$expected_actions === $actual_actions,
	'action inventory frozen at exactly 26 wp_ajax_hossam_* registrations',
	'expected=' . json_encode( $expected_actions ) . ' actual=' . json_encode( $actual_actions )
);

/* C2 — no nopriv twins for any hossam_* action. */
$nopriv = array();
foreach ( $GLOBALS['HAL_CT_HOOKS'] as $hook => $callbacks ) {
	if ( str_starts_with( $hook, 'wp_ajax_nopriv_hossam_' ) ) {
		$nopriv[] = $hook;
	}
}
hal_ct_check( 'C2-NOPRIV', array() === $nopriv, 'zero wp_ajax_nopriv_hossam_* registrations', 'nopriv: ' . json_encode( $nopriv ) );

/* C3 — every registered action callback is actually callable. */
$uncallable = array();
foreach ( $GLOBALS['HAL_CT_HOOKS'] as $hook => $callbacks ) {
	if ( ! str_starts_with( $hook, 'wp_ajax_hossam_' ) ) {
		continue;
	}
	foreach ( $callbacks as $callback ) {
		if ( ! is_callable( $callback ) ) {
			$uncallable[] = $hook;
		}
	}
}
hal_ct_check( 'C3-CALLABLE', array() === $uncallable, 'all 26 action callbacks are callable', 'uncallable: ' . json_encode( $uncallable ) );

/* C4 — registry-gated seo action stays unregistered in the live load. */
hal_ct_check(
	'C4-SEO-GATED',
	! isset( $GLOBALS['HAL_CT_HOOKS']['wp_ajax_hossam_save_seo'] ),
	'wp_ajax_hossam_save_seo not registered (§16 registry-gated loading)',
	'save_seo registered without registry approval'
);

function hal_ct_strip_comments( string $code ): string {
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

/* C5 — nonce + JSON discipline in every ajax file (comments stripped). */
$ajax_files = glob( $project . '/runtime/ajax/*.php' );
$nonce_missing = array();
$json_missing = array();
foreach ( $ajax_files as $file ) {
	$code = hal_ct_strip_comments( (string) file_get_contents( $file ) );
	if ( false === strpos( $code, 'check_ajax_referer' ) && false === strpos( $code, 'wp_verify_nonce' ) ) {
		$nonce_missing[] = basename( $file );
	}
	if ( false === strpos( $code, 'wp_send_json' ) ) {
		$json_missing[] = basename( $file );
	}
}
hal_ct_check( 'C5-NONCE', array() === $nonce_missing, 'nonce check in every ajax file (' . count( $ajax_files ) . ' files)', 'missing: ' . json_encode( $nonce_missing ) );
hal_ct_check( 'C5-JSON', array() === $json_missing, 'wp_send_json responses in every ajax file', 'missing: ' . json_encode( $json_missing ) );

/* C6 — authorization gate in every ajax file: BOTH a registry/decision
 * call AND a capability check (comments stripped, so documentation never
 * satisfies the gate). The conjunction is pinned by C6-NEGATIVE-CONTROL:
 * a snippet with a bare current_user_can and no registry/decision call
 * fails the predicate. Since the batch-5 closure (§15.30), uploads.php
 * also satisfies the conjunction — the former Batch-5 legacy exception
 * (capability-only) was migrated to feature gates (posts/files) with
 * ownership/capability gates kept independent; C6-UPLOADS-CONTRACT pins
 * the migrated contract. */
function hal_ct_ajax_has_registry_call( string $code ): bool {
	return false !== strpos( $code, 'is_feature_enabled' )
		|| false !== strpos( $code, 'hossam_get_integration_decision' )
		|| false !== strpos( $code, 'Integration_Settings_Registry' )
		|| false !== strpos( $code, 'hossam_integration_registry_key' );
}
function hal_ct_ajax_gate_ok( string $code ): bool {
	return hal_ct_ajax_has_registry_call( $code ) && false !== strpos( $code, 'current_user_can' );
}
$gate_missing = array();
foreach ( $ajax_files as $file ) {
	$code = hal_ct_strip_comments( (string) file_get_contents( $file ) );
	if ( ! hal_ct_ajax_gate_ok( $code ) ) {
		$gate_missing[] = basename( $file );
	}
}
hal_ct_check( 'C6-AUTH-GATE', array() === $gate_missing, 'registry/decision AND capability gate in every ajax file (uploads.php migrated since §15.30)', 'missing: ' . json_encode( $gate_missing ) );
hal_ct_check(
	'C6-NEGATIVE-CONTROL',
	! hal_ct_ajax_gate_ok( "<?php\nif ( ! current_user_can( 'manage_options' ) ) {\n\treturn;\n}\n" ),
	'bare current_user_can without a registry/decision call fails the gate predicate',
	'predicate accepted a bare capability check'
);
$uploads_code = hal_ct_strip_comments( (string) file_get_contents( $project . '/runtime/ajax/uploads.php' ) );
hal_ct_check(
	'C6-UPLOADS-CONTRACT',
	hal_ct_ajax_has_registry_call( $uploads_code )
		&& false !== strpos( $uploads_code, 'current_user_can' )
		&& false !== strpos( $uploads_code, 'post_author' )
		&& false !== strpos( $uploads_code, 'is_user_logged_in' ),
	'uploads.php pinned (migrated §15.30): feature gate AND capability + post_author ownership + logged-in, with upload_files still delegated to the adapter',
	'uploads.php authorization contract drifted'
);

/* C7 — integration adapters consume the registry/decision contracts. */
$adapter_hits = array();
foreach ( array( 'amelia.php', 'ai.php' ) as $adapter ) {
	$code = hal_ct_strip_comments( (string) file_get_contents( $project . '/runtime/adapters/' . $adapter ) );
	$adapter_hits[ $adapter ] = false !== strpos( $code, 'hossam_get_integration_decision' )
		|| false !== strpos( $code, 'hossam_integration_registry_key' )
		|| false !== strpos( $code, 'hossam_ai_resolve_strategy' )
		|| false !== strpos( $code, 'Integration_Settings_Registry' );
}
hal_ct_check(
	'C7-REGISTRY-CONSUMERS',
	true === ( $adapter_hits['amelia.php'] ?? false ) && true === ( $adapter_hits['ai.php'] ?? false ),
	'amelia + ai adapters consume the registry/decision contracts',
	'hits: ' . json_encode( $adapter_hits )
);

/* C8 — TLS-only endpoints in the integration adapters (no http:// in code). */
$plaintext = array();
foreach ( glob( $project . '/runtime/adapters/*.php' ) as $file ) {
	$code = hal_ct_strip_comments( (string) file_get_contents( $file ) );
	if ( preg_match( '/[\'"]http:\/\//', $code ) ) {
		$plaintext[] = basename( $file );
	}
}
hal_ct_check( 'C8-TLS-ONLY', array() === $plaintext, 'no plaintext http:// endpoint literals in adapter code', 'files: ' . json_encode( $plaintext ) );

/* C9 — no request-derived host/path construction in the adapters. */
$input_hosts = array();
foreach ( array( 'amelia.php', 'ai.php' ) as $adapter ) {
	$code = hal_ct_strip_comments( (string) file_get_contents( $project . '/runtime/adapters/' . $adapter ) );
	if ( false !== strpos( $code, '$_POST' ) || false !== strpos( $code, '$_GET' ) || false !== strpos( $code, '$_REQUEST' ) ) {
		$input_hosts[] = $adapter;
	}
}
hal_ct_check( 'C9-NO-INPUT-URLS', array() === $input_hosts, 'amelia/ai adapters build no URL from request input', 'files: ' . json_encode( $input_hosts ) );

$pass = 0;
foreach ( $GLOBALS['HAL_CT_RESULTS'] as $result ) {
	if ( $result['ok'] ) {
		$pass++;
	}
}
$total = count( $GLOBALS['HAL_CT_RESULTS'] );
echo "RESULT: $pass/$total checks passed\n";
exit( $pass === $total ? 0 : 1 );
