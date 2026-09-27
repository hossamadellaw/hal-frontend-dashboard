<?php
/**
 * HAL Frontend Dashboard — Batch 11 harness: update bridge + runtime bootstrap.
 *
 * Local, isolated harness: no live WordPress, no network, no database.
 * It stubs only the WordPress API surface (functions/constants the real code
 * calls) and then drives the REAL project code:
 *
 *   runtime/bootstrap.php                        — require order (bridge
 *                                                  AFTER the template
 *                                                  controller), context-gated
 *                                                  init, single-release
 *                                                  context, silent exit
 *                                                  without release context
 *   runtime/infrastructure/class-update-bridge.php — asset regex, PUC boot
 *                                                  behind class_exists
 *                                                  guards, strategies
 *                                                  filter (§4 shape),
 *                                                  auto-update policy,
 *                                                  upgrader scoping with
 *                                                  ambiguity passthrough,
 *                                                  record-only completion
 *
 * Fixtures live under .local-execution/batch-11/ (per-run), never inside
 * the shipped tree. Ed25519 keys are NOT needed here: the bridge paths
 * under test either pass input through or reject; acceptance of a
 * production-signed package is covered by package-verifier-test.php and
 * installer-test.php against the REAL verifier. Exit 0 = all checks pass.
 *
 * Usage:  php -d extension=sodium tests/php/runtime-bootstrap-test.php   (main)
 *         php -d extension=sodium tests/php/runtime-bootstrap-test.php <mode>
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

if ( PHP_SAPI !== 'cli' ) {
	echo "This harness must run under CLI PHP.\n";
	exit( 1 );
}

$mode = isset( $argv[1] ) ? (string) $argv[1] : 'main';
$project = dirname( __DIR__, 2 );
$ws_root = $project . '/.local-execution/batch-11';
require_once __DIR__ . '/lib-hal-php-flags.php'; // test-launcher flag filter (no product code, no assertions)
@mkdir( $ws_root, 0777, true );

if ( in_array( $mode, array( 'admin', 'puc' ), true ) && ! defined( 'WP_ADMIN' ) ) {
	define( 'WP_ADMIN', true );
}
if ( 'cli' === $mode && ! defined( 'WP_CLI' ) ) {
	define( 'WP_CLI', true );
}

$GLOBALS['HAL_B11_IS_ADMIN'] = false;
$GLOBALS['HAL_B11_DOING_CRON'] = ( 'cron' === $mode );
$GLOBALS['HAL_B11_HOOKS'] = array();
$GLOBALS['HAL_B11_ACTIONS_DONE'] = array();
$GLOBALS['HAL_B11_OPTIONS'] = array();
$GLOBALS['HAL_B11_SCHEDULED'] = array();
$GLOBALS['HAL_B11_RESULTS'] = array();
$GLOBALS['HAL_B11_ABSPATH'] = $ws_root . '/rt-abspath-' . $mode . '-' . getmypid() . '/';

/* ── WordPress boundary stubs (WordPress only — never project logic) ── */

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook_name, $callback = '', int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['HAL_B11_HOOKS'][ $hook_name ][] = array( 'type' => 'action', 'callback' => $callback, 'priority' => $priority );
		return true;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook_name, $callback = '', int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['HAL_B11_HOOKS'][ $hook_name ][] = array( 'type' => 'filter', 'callback' => $callback, 'priority' => $priority );
		return true;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook_name, ...$args ): void {
		$GLOBALS['HAL_B11_ACTIONS_DONE'][ $hook_name ] = ( $GLOBALS['HAL_B11_ACTIONS_DONE'][ $hook_name ] ?? 0 ) + 1;
		foreach ( $GLOBALS['HAL_B11_HOOKS'][ $hook_name ] ?? array() as $entry ) {
			call_user_func_array( $entry['callback'], $args );
		}
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook_name, $value, ...$args ) {
		foreach ( $GLOBALS['HAL_B11_HOOKS'][ $hook_name ] ?? array() as $entry ) {
			$value = call_user_func_array( $entry['callback'], array_merge( array( $value ), $args ) );
		}
		return $value;
	}
}
if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook_name ): int {
		return $GLOBALS['HAL_B11_ACTIONS_DONE'][ $hook_name ] ?? 0;
	}
}
if ( ! function_exists( '__return_true' ) ) {
	function __return_true() {
		return true;
	}
}
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin(): bool {
		return (bool) ( $GLOBALS['HAL_B11_IS_ADMIN'] ?? false );
	}
}
if ( ! function_exists( 'wp_doing_cron' ) ) {
	function wp_doing_cron(): bool {
		return (bool) ( $GLOBALS['HAL_B11_DOING_CRON'] ?? false );
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default_value = false ) {
		return array_key_exists( $option, $GLOBALS['HAL_B11_OPTIONS'] ) ? $GLOBALS['HAL_B11_OPTIONS'][ $option ] : $default_value;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, $value, $autoload = null ): bool {
		$GLOBALS['HAL_B11_OPTIONS'][ $option ] = $value;
		return true;
	}
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( string $hook, array $args = array() ) {
		$key = $hook . '|' . json_encode( array_values( $args ) );
		return $GLOBALS['HAL_B11_SCHEDULED'][ $key ] ?? false;
	}
}
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( int $timestamp, string $hook, array $args = array() ): bool {
		$key = $hook . '|' . json_encode( array_values( $args ) );
		$GLOBALS['HAL_B11_SCHEDULED'][ $key ] = $timestamp;
		return true;
	}
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = array() ): bool {
		return true;
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		return 'https://example.test/' . ltrim( $path, '/' );
	}
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}
}
if ( ! function_exists( 'content_url' ) ) {
	function content_url( string $path = '' ): string {
		return 'https://example.test/wp-content/' . ltrim( $path, '/' );
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component );
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
if ( ! function_exists( 'headers_sent' ) ) {
	function headers_sent( &$file = null, &$line = null ): bool {
		return false;
	}
}
if ( ! function_exists( 'download_url' ) ) {
	function download_url( string $url, int $timeout = 300 ) {
		return $GLOBALS['HAL_B11_DOWNLOAD_RESULT'] ?? new WP_Error( 'http_request_failed', 'stub' );
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
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
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post_id = null ) {
		return $GLOBALS['HAL_B11_POSTS'][ (int) $post_id ] ?? null;
	}
}
if ( ! function_exists( 'get_queried_object' ) ) {
	function get_queried_object() {
		return $GLOBALS['HAL_B11_QUERIED'] ?? null;
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public string $code = '';
		public string $message = '';
		public function __construct( $code = '', $message = '' ) {
			$this->code = (string) $code;
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
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
}

/* ── Harness helpers ── */

function hal_b11_check( string $id, bool $condition, string $pass, string $fail = '' ): void {
	$GLOBALS['HAL_B11_RESULTS'][] = array( 'id' => $id, 'ok' => $condition, 'pass' => $pass, 'fail' => $fail );
	if ( 'main' !== ( $GLOBALS['HAL_B11_MODE'] ?? 'main' ) ) {
		echo ( $condition ? 'PASS' : 'FAIL' ) . " [$id] " . ( $condition ? $pass : $fail ) . "\n";
	}
}

function hal_b11_remove_dir( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $item ) {
		if ( $item->isLink() || ! $item->isDir() ) {
			@unlink( $item->getPathname() );
		} else {
			@rmdir( $item->getPathname() );
		}
	}
	@rmdir( $dir );
}

function hal_b11_report( string $mode ): void {
	$pass = 0;
	$total = count( $GLOBALS['HAL_B11_RESULTS'] );
	foreach ( $GLOBALS['HAL_B11_RESULTS'] as $result ) {
		if ( $result['ok'] ) {
			$pass++;
		}
		if ( 'main' === $mode ) {
			echo ( $result['ok'] ? 'PASS' : 'FAIL' ) . " [{$result['id']}] " . ( $result['ok'] ? $result['pass'] : $result['fail'] ) . "\n";
		}
	}
	if ( 'main' === $mode ) {
		echo "RESULT: $pass/$total checks passed\n";
	} else {
		echo 'HAL-VERDICT ' . $mode . ( $pass === $total ? ' ALL-ASSERTIONS-HELD' : ' ASSERTIONS-FAILED' ) . "\n";
	}
	exit( $pass === $total ? 0 : 1 );
}

function hal_b11_spawn( string $mode ): bool {
	$php = PHP_BINARY;
	$command = array_merge( hal_php_argv( $php, array(), array( 'sodium' ) ), array( __FILE__, $mode ) );
	$stdout_file = (string) tempnam( sys_get_temp_dir(), 'b11out' );
	$stderr_file = (string) tempnam( sys_get_temp_dir(), 'b11err' );
	$descriptors = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'file', $stdout_file, 'w' ),
		2 => array( 'file', $stderr_file, 'w' ),
	);
	$proc = proc_open( $command, $descriptors, $pipes );
	if ( ! is_resource( $proc ) ) {
		hal_b11_check( 'SPAWN-' . $mode, false, '', 'proc_open failed' );
		return false;
	}
	fclose( $pipes[0] );
	$code = proc_close( $proc );
	$stdout = (string) file_get_contents( $stdout_file );
	$stderr = (string) file_get_contents( $stderr_file );
	@unlink( $stdout_file );
	@unlink( $stderr_file );
	$ok = 0 === $code && false !== strpos( $stdout, 'HAL-VERDICT ' . $mode . ' ALL-ASSERTIONS-HELD' );
	hal_b11_check(
		'SPAWN-' . $mode,
		$ok,
		'subprocess ' . $mode . ' held all assertions (exit 0)',
		'exit ' . $code . '; stdout: ' . trim( $stdout ) . '; stderr: ' . trim( $stderr )
	);
	return $ok;
}

function hal_b11_update_hooks(): array {
	$found = array();
	foreach ( array( 'auto_update_plugin', 'upgrader_pre_download', 'upgrader_source_selection', 'upgrader_process_complete' ) as $hook ) {
		foreach ( $GLOBALS['HAL_B11_HOOKS'][ $hook ] ?? array() as $entry ) {
			$found[ $hook ][] = $entry['callback'];
		}
	}
	return $found;
}

function hal_b11_bridge_hook_count( string $hook ): int {
	$count = 0;
	foreach ( $GLOBALS['HAL_B11_HOOKS'][ $hook ] ?? array() as $entry ) {
		$callback = $entry['callback'];
		$label = is_array( $callback )
			? ( is_string( $callback[0] ) ? $callback[0] : get_class( $callback[0] ) ) . '::' . $callback[1]
			: (string) $callback;
		if ( 'HAL_Frontend_Dashboard_Update_Bridge' === strtok( $label, ':' ) || false !== strpos( $label, 'Update_Bridge' ) ) {
			$count++;
		}
	}
	return $count;
}

function hal_b11_fake_upgrader( $hook_extra ): stdClass {
	$skin = new stdClass();
	$skin->options = array( 'hook_extra' => $hook_extra );
	$upgrader = new stdClass();
	$upgrader->skin = $skin;
	return $upgrader;
}

function hal_b11_define_release_context( string $project ): void {
	if ( ! defined( 'ABSPATH' ) ) {
		@mkdir( $GLOBALS['HAL_B11_ABSPATH'], 0777, true );
		define( 'ABSPATH', $GLOBALS['HAL_B11_ABSPATH'] );
	}
	if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
		$plugins_dir = $GLOBALS['HAL_B11_ABSPATH'] . 'wp-content' . DIRECTORY_SEPARATOR . 'plugins';
		@mkdir( $plugins_dir, 0777, true );
		define( 'WP_PLUGIN_DIR', $plugins_dir );
	}
	if ( ! defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT' ) ) {
		define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT', $project . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR );
		define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_DIR', $project . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR );
		define( 'HAL_FRONTEND_DASHBOARD_RELEASE_ID', '11.0.0+' . str_repeat( 'b', 40 ) );
		define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION', '11.0.0' );
		define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_URL', 'https://example.test/releases/11.0.0+b/' );
	}
	if ( ! defined( 'HAL_FRONTEND_DASHBOARD_VERSION' ) ) {
		define( 'HAL_FRONTEND_DASHBOARD_VERSION', '1.0.0' );
	}
}

$GLOBALS['HAL_B11_MODE'] = $mode;

/* ════════════════════════════════════════════════════════════════
 * MAIN — visitor context: bridge loads, nothing initializes
 * ════════════════════════════════════════════════════════════════ */

function hal_b11_mode_main( string $project ): void {
	hal_b11_define_release_context( $project );
	require $project . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'bootstrap.php';

	/* 1. Require order: bridge exactly once, after the template controller. */
	$source = (string) file_get_contents( $project . '/runtime/bootstrap.php' );
	preg_match_all( '/^[ \t]*(?:require_once|require|include_once|include)\b.*$/m', $source, $matches );
	$bridge_lines = array();
	$controller_line = -1;
	foreach ( $matches[0] as $index => $line ) {
		if ( false !== strpos( $line, 'infrastructure/class-update-bridge.php' ) ) {
			$bridge_lines[] = $index;
		}
		if ( false !== strpos( $line, 'infrastructure/class-template-controller.php' ) ) {
			$controller_line = $index;
		}
	}
	hal_b11_check(
		'B-ORDER',
		1 === count( $bridge_lines ) && $controller_line >= 0 && $bridge_lines[0] > $controller_line,
		'bootstrap requires infrastructure/class-update-bridge.php exactly once, after the template-controller require',
		'bridge require lines: ' . json_encode( $bridge_lines ) . '; controller line: ' . $controller_line
	);

	/* 2. Bridge class loaded from the runtime infrastructure copy. */
	hal_b11_check(
		'B-LOAD',
		class_exists( 'HAL_Frontend_Dashboard_Update_Bridge', false ),
		'update bridge class loaded via bootstrap (runtime/infrastructure copy)',
		'bridge class missing after bootstrap'
	);
	$ref = new ReflectionClass( 'HAL_Frontend_Dashboard_Update_Bridge' );
	hal_b11_check(
		'B-LOAD-PATH',
		false !== strpos( str_replace( '\\', '/', (string) $ref->getFileName() ), 'runtime/infrastructure/class-update-bridge.php' ),
		'bridge class came from runtime/infrastructure (' . $ref->getFileName() . ')',
		'bridge loaded from unexpected path: ' . $ref->getFileName()
	);

	/* 3. Visitor context: bridge init never runs (runtime keeps its own hooks). */
	hal_b11_check(
		'B-VISITOR-CONTEXT',
		false === HAL_Frontend_Dashboard_Update_Bridge::is_update_context(),
		'is_update_context() false in the visitor path',
		'visitor path misclassified as update context'
	);
	$bridge_hook_total = hal_b11_bridge_hook_count( 'auto_update_plugin' )
		+ hal_b11_bridge_hook_count( 'upgrader_pre_download' )
		+ hal_b11_bridge_hook_count( 'upgrader_source_selection' )
		+ hal_b11_bridge_hook_count( 'upgrader_process_complete' );
	hal_b11_check(
		'B-VISITOR-HOOKS',
		0 === $bridge_hook_total,
		'zero bridge update hooks registered in the visitor path (runtime keeps its own registrations)',
		'bridge hooks leaked in visitor path: ' . $bridge_hook_total
	);

	/* 4. PUC absent: boot no-ops, strategies pass through. */
	hal_b11_check(
		'B-PUC-ABSENT',
		null === HAL_Frontend_Dashboard_Update_Bridge::boot_puc(),
		'boot_puc() returns null with no PUC classes and no runtime vendor (no fatal)',
		'boot_puc() did not no-op without PUC'
	);
	$strategies_in = array( 'a' => 1, 'b' => 2 );
	hal_b11_check(
		'B-STRATEGIES-PASSTHROUGH',
		$strategies_in === HAL_Frontend_Dashboard_Update_Bridge::filter_update_detection_strategies( $strategies_in ),
		'strategies filter passes input through when PUC is absent',
		'strategies altered without PUC'
	);

	/* 5. Asset regex: exact literal + matching semantics. */
	$expected_regex = '/\Ahal-frontend-dashboard-(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.zip\z/';
	hal_b11_check(
		'B-REGEX-LITERAL',
		$expected_regex === HAL_Frontend_Dashboard_Update_Bridge::ASSET_REGEX,
		'ASSET_REGEX equals the exclusive literal from update-system §4',
		'got: ' . HAL_Frontend_Dashboard_Update_Bridge::ASSET_REGEX
	);
	$accept = array( 'hal-frontend-dashboard-1.0.0.zip', 'hal-frontend-dashboard-0.0.0.zip', 'hal-frontend-dashboard-10.20.30.zip' );
	$reject = array(
		'hal-frontend-dashboard-01.2.3.zip', 'hal-frontend-dashboard-1.2.zip', 'hal-frontend-dashboard-1.2.3.4.zip',
		'HAL-FRONTEND-DASHBOARD-1.2.3.zip', 'hal-frontend-dashboard-1.2.3.tar.gz', 'hal-frontend-dashboard-1.2.3.ZIP',
		'xhal-frontend-dashboard-1.2.3.zip', 'hal-frontend-dashboard-1.2.3.zip.bak', '../hal-frontend-dashboard-1.2.3.zip',
	);
	$regex_ok = true;
	foreach ( $accept as $name ) {
		if ( 1 !== preg_match( HAL_Frontend_Dashboard_Update_Bridge::ASSET_REGEX, $name ) ) {
			$regex_ok = false;
		}
	}
	foreach ( $reject as $name ) {
		if ( 1 === preg_match( HAL_Frontend_Dashboard_Update_Bridge::ASSET_REGEX, $name ) ) {
			$regex_ok = false;
		}
	}
	hal_b11_check(
		'B-REGEX-SEMANTICS',
		$regex_ok,
		'regex accepts plain versions, rejects leading zeros/case variants/suffixes/paths',
		'regex semantics mismatch'
	);

	/* 6. auto_update_plugin matrix (B11-02: installed = MU runtime version). */
	$bridge = 'HAL_Frontend_Dashboard_Update_Bridge';
	$offer = static function ( string $version, string $package, string $plugin = 'hal-frontend-dashboard/hal-frontend-dashboard.php' ): stdClass {
		$item = new stdClass();
		$item->plugin = $plugin;
		$item->slug = 'hal-frontend-dashboard';
		$item->new_version = $version;
		$item->package = $package;
		return $item;
	};
	hal_b11_check(
		'B-AUTO-TRUE',
		true === $bridge::filter_auto_update( false, $offer( '11.0.1', 'https://github.com/hossamadellaw/hal-frontend-dashboard/releases/download/v11.0.1/hal-frontend-dashboard-11.0.1.zip' ) ),
		'exact basename + version above the MU runtime + version-bound https asset → true',
		'valid offer not auto-enabled'
	);
	hal_b11_check(
		'B-AUTO-OTHER-PLUGIN',
		'sentinel' === $bridge::filter_auto_update( 'sentinel', $offer( '9.9.9', 'https://github.com/hossamadellaw/hal-frontend-dashboard/releases/download/v9.9.9/hal-frontend-dashboard-9.9.9.zip', 'other/other.php' ) ),
		'other plugin basename passes through untouched',
		'other plugin affected'
	);
	hal_b11_check(
		'B-AUTO-NOT-HIGHER',
		false === $bridge::filter_auto_update( false, $offer( '1.0.0', 'https://github.com/hossamadellaw/hal-frontend-dashboard/releases/download/v1.0.0/hal-frontend-dashboard-1.0.0.zip' ) ),
		'version below the MU runtime passes through (not higher)',
		'lower version auto-enabled'
	);
	hal_b11_check(
		'B-AUTO-PACKAGE-MISMATCH',
		false === $bridge::filter_auto_update( false, $offer( '11.0.1', 'https://github.com/hossamadellaw/hal-frontend-dashboard/releases/download/v11.0.1/hal-frontend-dashboard-11.0.2.zip' ) ),
		'package embedding a different version passes through',
		'version-unbound package auto-enabled'
	);
	hal_b11_check(
		'B-AUTO-NON-HTTPS',
		false === $bridge::filter_auto_update( false, $offer( '11.0.1', 'http://github.com/hossamadellaw/hal-frontend-dashboard/releases/download/v11.0.1/hal-frontend-dashboard-11.0.1.zip' ) ),
		'non-https package passes through',
		'plaintext package auto-enabled'
	);
	hal_b11_check(
		'B-AUTO-SOURCE-ZIP',
		false === $bridge::filter_auto_update( false, $offer( '11.0.1', 'https://github.com/hossamadellaw/hal-frontend-dashboard/archive/refs/tags/v11.0.1.zip' ) ),
		'source/branch archive passes through (no fallback)',
		'branch archive auto-enabled'
	);
	$empty_slug_item = $offer( '11.0.1', 'https://github.com/hossamadellaw/hal-frontend-dashboard/releases/download/v11.0.1/hal-frontend-dashboard-11.0.1.zip' );
	$empty_slug_item->slug = '';
	hal_b11_check(
		'B-AUTO-EMPTY-SLUG',
		false === $bridge::filter_auto_update( false, $empty_slug_item ),
		'empty slug passes through untouched (exact slug required)',
		'empty slug auto-enabled'
	);
	hal_b11_check(
		'B-AUTO-EVIL-HOST',
		false === $bridge::filter_auto_update( false, $offer( '11.0.1', 'https://evil.example.com/hossamadellaw/hal-frontend-dashboard/releases/download/v11.0.1/hal-frontend-dashboard-11.0.1.zip' ) ),
		'off-repository https host passes through untouched (github.com binding)',
		'evil-host package auto-enabled'
	);
	hal_b11_check(
		'B-AUTO-GITHUB-URL-TRUE',
		true === $bridge::filter_auto_update( false, $offer( '12.0.0', 'https://github.com/hossamadellaw/hal-frontend-dashboard/releases/download/v12.0.0/hal-frontend-dashboard-12.0.0.zip' ) ),
		'repo-bound public-release asset URL (releases/download/<tag>/<asset>) with a higher version → true',
		'repo-bound offer not auto-enabled'
	);

	/* 7. upgrader_pre_download scoping: ambiguity + foreign basename pass through. */
	$pre = 'HAL_Frontend_Dashboard_Update_Bridge::filter_pre_download';
	hal_b11_check(
		'B-PRE-HANDLED',
		'sentinel' === $bridge::filter_pre_download( 'sentinel', 'https://example.test/x.zip', hal_b11_fake_upgrader( array( 'plugin' => 'hal-frontend-dashboard/hal-frontend-dashboard.php' ) ) ),
		'already-handled reply passes through',
		'handled reply overridden'
	);
	hal_b11_check(
		'B-PRE-AMBIGUOUS',
		false === $bridge::filter_pre_download( false, 'https://example.test/x.zip', new stdClass() ),
		'upgrader without skin options passes through untouched',
		'ambiguous upgrader intercepted'
	);
	hal_b11_check(
		'B-PRE-FOREIGN',
		false === $bridge::filter_pre_download( false, 'https://example.test/x.zip', hal_b11_fake_upgrader( array( 'plugin' => 'other/other.php' ) ) ),
		'foreign basename passes through untouched',
		'foreign plugin intercepted'
	);

	/* 7b. Bulk hook_extra['plugins'] arrays: member verified, foreign-only passthrough. */
	$bulk_asset = 'https://github.com/hossamadellaw/hal-frontend-dashboard/releases/download/v1.0.1/hal-frontend-dashboard-1.0.1.zip';
	$bulk_bad = $GLOBALS['HAL_B11_ABSPATH'] . 'bad-carrier-bulk.zip';
	file_put_contents( $bulk_bad, 'not-a-zip' );
	$GLOBALS['HAL_B11_DOWNLOAD_RESULT'] = $bulk_bad;
	$bulk_match = $bridge::filter_pre_download( false, $bulk_asset, hal_b11_fake_upgrader( array( 'plugins' => array( 'hal-frontend-dashboard/hal-frontend-dashboard.php' ) ) ) );
	hal_b11_check(
		'B-PRE-BULK-MATCH',
		$bulk_match instanceof WP_Error && 'hal_carrier_download_rejected' === $bulk_match->get_error_code() && ! is_file( $bulk_bad ),
		'bulk array containing our basename enters verification (unverifiable package returns WP_Error and removes temp)',
		'got: ' . ( is_object( $bulk_match ) ? get_class( $bulk_match ) : var_export( $bulk_match, true ) )
	);
	hal_b11_check(
		'B-PRE-BULK-FOREIGN',
		false === $bridge::filter_pre_download( false, $bulk_asset, hal_b11_fake_upgrader( array( 'plugins' => array( 'other/other.php', 'third/third.php' ) ) ) ),
		'foreign-only bulk array passes through untouched',
		'foreign bulk intercepted'
	);
	$bulk_bad_mixed = $GLOBALS['HAL_B11_ABSPATH'] . 'bad-carrier-bulk-mixed.zip';
	file_put_contents( $bulk_bad_mixed, 'not-a-zip' );
	$GLOBALS['HAL_B11_DOWNLOAD_RESULT'] = $bulk_bad_mixed;
	$bulk_mixed = $bridge::filter_pre_download( false, $bulk_asset, hal_b11_fake_upgrader( array( 'plugins' => array( 'other/other.php', 'hal-frontend-dashboard/hal-frontend-dashboard.php' ) ) ) );
	hal_b11_check(
		'B-PRE-BULK-MIXED',
		$bulk_mixed instanceof WP_Error && 'hal_carrier_download_rejected' === $bulk_mixed->get_error_code() && ! is_file( $bulk_bad_mixed ),
		'mixed bulk array with our basename present verifies ours (WP_Error, temp removed)',
		'got: ' . ( is_object( $bulk_mixed ) ? get_class( $bulk_mixed ) : var_export( $bulk_mixed, true ) )
	);
	hal_b11_check(
		'B-PRE-BULK-NONSTRINGS',
		false === $bridge::filter_pre_download( false, $bulk_asset, hal_b11_fake_upgrader( array( 'plugins' => array( 123, '', null, false ) ) ) ),
		'bulk array of non-strings/empties passes through untouched',
		'non-string bulk intercepted'
	);

	/* 8. Matching basename + garbage package → WP_Error, temp removed. */
	$bad_zip = $GLOBALS['HAL_B11_ABSPATH'] . 'bad-carrier.zip';
	@mkdir( $GLOBALS['HAL_B11_ABSPATH'], 0777, true );
	file_put_contents( $bad_zip, 'not-a-zip' );
	$GLOBALS['HAL_B11_DOWNLOAD_RESULT'] = $bad_zip;
	$result = $bridge::filter_pre_download( false, 'https://github.com/hossamadellaw/hal-frontend-dashboard/releases/download/v1.0.1/hal-frontend-dashboard-1.0.1.zip', hal_b11_fake_upgrader( array( 'plugin' => 'hal-frontend-dashboard/hal-frontend-dashboard.php' ) ) );
	hal_b11_check(
		'B-PRE-REJECT',
		$result instanceof WP_Error && 'hal_carrier_download_rejected' === $result->get_error_code() && ! is_file( $bad_zip ),
		'matching basename with an unverifiable package returns WP_Error and removes temp',
		'got: ' . ( is_object( $result ) ? get_class( $result ) : var_export( $result, true ) )
	);

	/* 9. upgrader_source_selection scoping. */
	hal_b11_check(
		'B-SRC-AMBIGUOUS',
		'/src' === $bridge::filter_source_selection( '/src', '/remote', new stdClass(), null ),
		'missing hook_extra passes through untouched',
		'ambiguous source_selection intercepted'
	);
	hal_b11_check(
		'B-SRC-FOREIGN',
		'/src' === $bridge::filter_source_selection( '/src', '/remote', new stdClass(), array( 'plugin' => 'other/other.php' ) ),
		'foreign basename passes through untouched',
		'foreign source_selection intercepted'
	);
	hal_b11_check(
		'B-SRC-BULK-FOREIGN',
		'/src-bulk' === $bridge::filter_source_selection( '/src-bulk', '/remote', new stdClass(), array( 'plugins' => array( 'other/other.php' ) ) ),
		'foreign-only bulk array passes through untouched',
		'foreign bulk source_selection intercepted'
	);
	$bulk_tree = $GLOBALS['HAL_B11_ABSPATH'] . 'junk-tree-bulk';
	@mkdir( $bulk_tree, 0777, true );
	$bulk_src_result = $bridge::filter_source_selection( $bulk_tree, '/remote', new stdClass(), array( 'plugins' => array( 'hal-frontend-dashboard/hal-frontend-dashboard.php' ) ) );
	hal_b11_check(
		'B-SRC-BULK-MATCH',
		$bulk_src_result instanceof WP_Error && 'hal_carrier_source_rejected' === $bulk_src_result->get_error_code(),
		'bulk array containing our basename verifies the tree (unverifiable tree returns WP_Error)',
		'got: ' . ( is_object( $bulk_src_result ) ? get_class( $bulk_src_result ) : var_export( $bulk_src_result, true ) )
	);
	$mixed_src_result = $bridge::filter_source_selection( $bulk_tree, '/remote', new stdClass(), array( 'plugins' => array( 'other/other.php', 'hal-frontend-dashboard/hal-frontend-dashboard.php' ) ) );
	hal_b11_check(
		'B-SRC-BULK-MIXED',
		$mixed_src_result instanceof WP_Error && 'hal_carrier_source_rejected' === $mixed_src_result->get_error_code(),
		'mixed bulk array with our basename present verifies ours (WP_Error)',
		'got: ' . ( is_object( $mixed_src_result ) ? get_class( $mixed_src_result ) : var_export( $mixed_src_result, true ) )
	);
	$junk_tree = $GLOBALS['HAL_B11_ABSPATH'] . 'junk-tree';
	@mkdir( $junk_tree, 0777, true );
	$src_result = $bridge::filter_source_selection( $junk_tree, '/remote', new stdClass(), array( 'plugin' => 'hal-frontend-dashboard/hal-frontend-dashboard.php' ) );
	hal_b11_check(
		'B-SRC-REJECT',
		$src_result instanceof WP_Error && 'hal_carrier_source_rejected' === $src_result->get_error_code(),
		'matching basename with an unverifiable tree returns WP_Error (old Carrier kept)',
		'got: ' . ( is_object( $src_result ) ? get_class( $src_result ) : var_export( $src_result, true ) )
	);

	/* 10. upgrader_process_complete: record-only, never promotes. */
	$bridge::action_upgrader_complete( new stdClass(), array( 'type' => 'plugin', 'action' => 'update', 'plugin' => 'other/other.php' ) );
	hal_b11_check(
		'B-COMPLETE-FOREIGN',
		! array_key_exists( HAL_Frontend_Dashboard_Update_Bridge::IMPORT_OPTION, $GLOBALS['HAL_B11_OPTIONS'] )
			&& array() === $GLOBALS['HAL_B11_SCHEDULED'],
		'foreign completion records nothing and schedules nothing',
		'foreign completion had side effects'
	);
	$bridge::action_upgrader_complete( new stdClass(), array( 'type' => 'plugin', 'action' => 'update', 'plugin' => 'hal-frontend-dashboard/hal-frontend-dashboard.php' ) );
	$recorded = $GLOBALS['HAL_B11_OPTIONS'][ HAL_Frontend_Dashboard_Update_Bridge::IMPORT_OPTION ] ?? null;
	$cron_key = HAL_Frontend_Dashboard_Update_Bridge::IMPORT_CRON_HOOK . '|' . json_encode( array() );
	hal_b11_check(
		'B-COMPLETE-RECORD',
		is_array( $recorded ) && 'hal-frontend-dashboard/hal-frontend-dashboard.php' === ( $recorded['plugin'] ?? null )
			&& isset( $recorded['time'] ) && isset( $GLOBALS['HAL_B11_SCHEDULED'][ $cron_key ] ),
		'matching completion records the candidate + schedules the deferred import',
		'candidate record/cron missing: ' . json_encode( $recorded )
	);
	$bridge_source = (string) file_get_contents( $project . '/includes/class-update-bridge.php' );
	hal_b11_check(
		'B-COMPLETE-NEVER-PROMOTES',
		false === strpos( $bridge_source, 'Release_Manager' ) && false === strpos( $bridge_source, 'active.json' )
			&& false === strpos( $bridge_source, 'promote_runtime' ),
		'bridge source never references the release manager, active.json, or promotion',
		'promotion-adjacent reference found in the bridge'
	);

	/* 11. Single release context: template + assets share RUNTIME_DIR. */
	$GLOBALS['HAL_B11_POSTS'][42] = new WP_Post( array( 'ID' => 42, 'post_type' => 'page', 'post_name' => 'dashboard', 'post_status' => 'publish' ) );
	update_option( 'hal_frontend_dashboard_page_id', 42 );
	$GLOBALS['HAL_B11_QUERIED'] = new WP_Post( array( 'ID' => 42, 'post_type' => 'page', 'post_name' => 'dashboard', 'post_status' => 'publish' ) );
	$resolved = HAL_Frontend_Dashboard_Template_Controller::resolve_template( '/theme/orig.php' );
	$asset_uri = hossam_asset_uri( 'css/dashboard.css' );
	$release_root = realpath( HAL_FRONTEND_DASHBOARD_RUNTIME_DIR );
	hal_b11_check(
		'B-SINGLE-RELEASE',
		is_string( $resolved ) && 0 === strpos( $resolved, $release_root . DIRECTORY_SEPARATOR )
			&& 0 === strpos( $asset_uri, 'https://example.test/releases/' ),
		'template path and asset URI belong to this request release only',
		'template=' . var_export( $resolved, true ) . ' uri=' . var_export( $asset_uri, true )
	);

	/* 12. Static least-change guards on the bridge source. */
	$code_lines = explode( "\n", $bridge_source );
	$top_requires = 0;
	$vendor_requires = 0;
	foreach ( $code_lines as $line ) {
		if ( preg_match( '/^(require|include)(_once)?\b/', $line ) ) {
			$top_requires++;
		}
		if ( preg_match( '/^\s*(require|include)(_once)?\b.*vendor/i', $line ) ) {
			$vendor_requires++;
		}
	}
	hal_b11_check(
		'B-NO-VENDOR-LOAD',
		0 === $top_requires && 0 === $vendor_requires && false !== strpos( $bridge_source, "defined( 'ABSPATH' ) || exit;" ),
		'no file-load require/include, no vendor require anywhere, ABSPATH guard present',
		'file-load requires: ' . $top_requires . '; vendor requires: ' . $vendor_requires
	);
	hal_b11_check(
		'B-NO-PUC-USE',
		false === strpos( $bridge_source, 'use YahnisElsts' ),
		'no PUC use-statement at file load (string references behind guards only)',
		'PUC use-statement found'
	);

	/* 13. Mirror identity (§6.1): runtime copy byte-identical to includes/. */
	hal_b11_check(
		'B-MIRROR',
		hash_file( 'sha256', $project . '/includes/class-update-bridge.php' ) === hash_file( 'sha256', $project . '/runtime/infrastructure/class-update-bridge.php' ),
		'runtime/infrastructure copy is byte-identical to includes/ source',
		'mirror drift detected'
	);

	hal_b11_spawn( 'admin' );
	hal_b11_spawn( 'cron' );
	hal_b11_spawn( 'cli' );
	hal_b11_spawn( 'puc' );
	hal_b11_spawn( 'nocontext' );
	hal_b11_spawn( 'autoload' );

	hal_b11_report( 'main' );
}

/* ════════════════════════════════════════════════════════════════
 * UPDATE-CONTEXT MODES — init must fire exactly once with 4 hooks
 * ════════════════════════════════════════════════════════════════ */

function hal_b11_mode_update_context( string $project, string $mode ): void {
	if ( 'puc' === $mode ) {
		require $GLOBALS['HAL_B11_PUC_STUB'];
	}
	hal_b11_define_release_context( $project );
	require $project . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'bootstrap.php';

	hal_b11_check( $mode . '-CONTEXT', HAL_Frontend_Dashboard_Update_Bridge::is_update_context(), 'update context detected in ' . $mode . ' mode', 'context missed' );
	$expected_hooks = array( 'auto_update_plugin', 'upgrader_pre_download', 'upgrader_source_selection', 'upgrader_process_complete' );
	$bridge_counts = array();
	foreach ( $expected_hooks as $hook ) {
		$bridge_counts[ $hook ] = hal_b11_bridge_hook_count( $hook );
	}
	$hooks_ok = array( 1, 1, 1, 1 ) === array_values( $bridge_counts );
	hal_b11_check( $mode . '-HOOKS', $hooks_ok, 'init registered exactly one bridge callback per update hook', 'bridge hook counts: ' . json_encode( $bridge_counts ) );

	/* init() is idempotent: second call registers nothing new. */
	$before = array_map( 'count', hal_b11_update_hooks() );
	HAL_Frontend_Dashboard_Update_Bridge::init();
	$after = array_map( 'count', hal_b11_update_hooks() );
	hal_b11_check( $mode . '-IDEMPOTENT', $before === $after, 'second init() registers nothing new', 'double init changed hooks' );

	if ( 'puc' === $mode ) {
		hal_b11_puc_assertions();
	} else {
		hal_b11_check( $mode . '-PUC-ABSENT', null === HAL_Frontend_Dashboard_Update_Bridge::boot_puc(), 'boot_puc() no-ops without PUC (no fatal)', 'boot did not no-op' );
	}

	hal_b11_report( $mode );
}

function hal_b11_puc_assertions(): void {
	$calls = YahnisElsts\PluginUpdateChecker\v5p7\PucFactory::$calls;
	hal_b11_check(
		'puc-FACTORY-ARGS',
		1 === count( $calls )
			&& 'https://github.com/hossamadellaw/hal-frontend-dashboard' === ( $calls[0][0] ?? null )
			&& 'hal-frontend-dashboard' === ( $calls[0][2] ?? null )
			&& is_string( $calls[0][1] ?? null ) && 'hal-frontend-dashboard.php' === basename( (string) $calls[0][1] ),
		'PucFactory received (repository URL, carrier file, slug)',
		'factory calls: ' . json_encode( $calls )
	);
	$regex = YahnisElsts\PluginUpdateChecker\v5p7\PucCheckerStub::$enable_release_assets;
	hal_b11_check(
		'puc-ASSET-REGEX',
		array( HAL_Frontend_Dashboard_Update_Bridge::ASSET_REGEX, YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api::REQUIRE_RELEASE_ASSETS ) === $regex,
		'enableReleaseAssets received the exclusive asset regex with REQUIRE (no source fallback, update-system §4)',
		'got: ' . json_encode( $regex )
	);
	$filter = YahnisElsts\PluginUpdateChecker\v5p7\PucCheckerStub::$strategies_filter;
	hal_b11_check( 'puc-FILTER-REGISTERED', 'vcs_update_detection_strategies' === ( $filter[0] ?? null ) && is_callable( $filter[1] ?? null ), 'strategies filter registered on the checker', 'filter: ' . json_encode( $filter[0] ?? null ) );
	$latest = YahnisElsts\PluginUpdateChecker\v5p7\Vcs\GitHubApi::STRATEGY_LATEST_RELEASE;
	$three = array(
		'latest-tag' => 'tag-strategy',
		$latest => 'release-strategy',
		'branch' => 'branch-strategy',
	);
	hal_b11_check(
		'puc-STRATEGIES-RESTRICTED',
		array( $latest => 'release-strategy' ) === call_user_func( $filter[1], $three ),
		'three strategies collapse to STRATEGY_LATEST_RELEASE only (§4 shape)',
		'got: ' . json_encode( call_user_func( $filter[1], $three ) )
	);
	hal_b11_check(
		'puc-STRATEGIES-ABSENT',
		array() === call_user_func( $filter[1], array( 'latest-tag' => 'x', 'branch' => 'y' ) ),
		'missing latest-release strategy yields no update (empty array)',
		'non-empty result without the expected strategy'
	);
}

/* ════════════════════════════════════════════════════════════════
 * NOCONTEXT — bootstrap without release context exits silently
 * ════════════════════════════════════════════════════════════════ */

function hal_b11_mode_nocontext( string $project ): void {
	if ( ! defined( 'ABSPATH' ) ) {
		@mkdir( $GLOBALS['HAL_B11_ABSPATH'], 0777, true );
		define( 'ABSPATH', $GLOBALS['HAL_B11_ABSPATH'] );
	}
	require $project . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'bootstrap.php';
	hal_b11_check( 'nocontext-SILENT', 0 === did_action( 'hal_frontend_dashboard_runtime_ready' ), 'no runtime_ready without release context', 'action fired' );
	hal_b11_check( 'nocontext-NO-BRIDGE', ! class_exists( 'HAL_Frontend_Dashboard_Update_Bridge', false ), 'bridge not loaded without release context', 'bridge loaded' );
	hal_b11_check( 'nocontext-NO-HOOKS', array() === hal_b11_update_hooks(), 'no update hooks without release context', 'hooks leaked' );
	hal_b11_report( 'nocontext' );
}

/* ════════════════════════════════════════════════════════════════
 * AUTOLOAD — vendor autoload consulted lazily, then PUC boots
 * ════════════════════════════════════════════════════════════════ */

function hal_b11_mode_autoload( string $project ): void {
	if ( ! defined( 'ABSPATH' ) ) {
		@mkdir( $GLOBALS['HAL_B11_ABSPATH'], 0777, true );
		define( 'ABSPATH', $GLOBALS['HAL_B11_ABSPATH'] );
	}
	$fake_runtime = $GLOBALS['HAL_B11_ABSPATH'] . 'fake-runtime';
	@mkdir( $fake_runtime . '/vendor', 0777, true );
	copy( $GLOBALS['HAL_B11_PUC_STUB'], $fake_runtime . '/vendor/stub.php' );
	file_put_contents(
		$fake_runtime . '/vendor/autoload.php',
		"<?php\n\$GLOBALS['HAL_B11_AUTOLOAD_INCLUDED'] = true;\nrequire_once __DIR__ . '/stub.php';\n"
	);
	if ( ! defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_DIR' ) ) {
		define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_DIR', $fake_runtime . '/' );
	}
	require $project . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'class-update-bridge.php';

	hal_b11_check(
		'autoload-PATH',
		$fake_runtime . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php' === HAL_Frontend_Dashboard_Update_Bridge::vendor_autoload_path(),
		'vendor autoload resolved under the runtime dir',
		'got: ' . var_export( HAL_Frontend_Dashboard_Update_Bridge::vendor_autoload_path(), true )
	);
	$checker = HAL_Frontend_Dashboard_Update_Bridge::boot_puc();
	hal_b11_check( 'autoload-INCLUDED', true === ( $GLOBALS['HAL_B11_AUTOLOAD_INCLUDED'] ?? false ), 'autoload file included lazily by boot_puc()', 'autoload not included' );
	hal_b11_check( 'autoload-CHECKER', is_object( $checker ), 'checker boots from autoload-provided PUC', 'no checker' );
	hal_b11_report( 'autoload' );
}

/* ── PUC stub file (written once by main, required by puc/autoload modes) ── */

function hal_b11_write_puc_stub( string $path ): void {
	$code = <<<'PHP'
<?php
namespace YahnisElsts\PluginUpdateChecker\v5p7 {
	class PucFactory {
		public static $calls = array();
		public static function buildUpdateChecker( $url, $file, $slug ) {
			self::$calls[] = array( $url, $file, $slug );
			return new PucCheckerStub();
		}
	}
	class PucCheckerStub {
		public static $enable_release_assets = null;
		public static $strategies_filter = null;
		public function getVcsApi() {
			return new Vcs\PucApiStub();
		}
		public function addFilter( $hook, $callback ) {
			self::$strategies_filter = array( $hook, $callback );
			return true;
		}
	}
}
namespace YahnisElsts\PluginUpdateChecker\v5p7\Vcs {
	class Api {
		const PREFER_RELEASE_ASSETS = 1;
		const REQUIRE_RELEASE_ASSETS = 2;
	}
	class GitHubApi {
		const STRATEGY_LATEST_RELEASE = 'latest-release';
		const STRATEGY_LATEST_TAG = 'latest-tag';
		const STRATEGY_BRANCH = 'branch';
	}
	class PucApiStub {
		public function enableReleaseAssets( ...$args ) {
			\YahnisElsts\PluginUpdateChecker\v5p7\PucCheckerStub::$enable_release_assets = $args;
			return true;
		}
	}
}
PHP;
	file_put_contents( $path, $code );
}

/* ── Dispatch ── */

$GLOBALS['HAL_B11_PUC_STUB'] = $ws_root . '/b11-puc-stub.php';
if ( 'main' === $mode ) {
	hal_b11_write_puc_stub( $GLOBALS['HAL_B11_PUC_STUB'] );
	hal_b11_mode_main( $project );
} elseif ( in_array( $mode, array( 'admin', 'cron', 'cli', 'puc' ), true ) ) {
	hal_b11_mode_update_context( $project, $mode );
} elseif ( 'nocontext' === $mode ) {
	hal_b11_mode_nocontext( $project );
} elseif ( 'autoload' === $mode ) {
	hal_b11_mode_autoload( $project );
} else {
	echo "Unknown mode: $mode\n";
	exit( 1 );
}
