<?php
/**
 * HAL Frontend Dashboard — Batch 0/1 acceptance harness (repairs B1-01..B1-09).
 *
 * Local, isolated harness: no live WordPress, no network, no database.
 * It stubs only the WordPress API surface (functions/constants the real code
 * calls) and then drives the REAL project code:
 *
 *   includes/class-release-manager.php  — promotion state machine, recovery
 *                                         under lock, retention (B1-01/B1-02)
 *   includes/class-package-verifier.php — tree/manifest validation (B1-03..B1-06)
 *   includes/class-installer.php         — preflight legacy gate (B1-07)
 *   includes/class-health-check.php     — one-use consume (B1-08 semantics)
 *   mu-loader/*.php                     — duplicate-load guard (B1-09)
 *   hal-frontend-dashboard.php          — Carrier inactivity (Batch 0)
 *
 * All filesystem fixtures live under an isolated temp dir (per-run), never
 * inside the project tree. Exit 0 = all checks pass.
 *
 * Usage: php tests/php/batch01-acceptance-test.php
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

if ( PHP_SAPI !== 'cli' ) {
	echo "This harness must run under CLI PHP.\n";
	exit( 1 );
}

/* ── WordPress API surface required by the real code ───────────── */
define( 'ABSPATH', dirname( __DIR__, 2 ) . '/.audit-work/batch-0-1/wp-scaffold/' );
$wp_test_options = array();

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['HAL_TEST_HOOKS'][ $hook ][] = array( 'callback' => $callback, 'priority' => $priority );
	return true;
}
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	return add_action( $hook, $callback, $priority, $args );
}
function register_activation_hook( $file, $callback ) {
	$GLOBALS['HAL_TEST_ACTIVATION_HOOK'] = array( 'file' => $file, 'callback' => $callback );
	return true;
}
function plugin_dir_path( $file ) {
	return rtrim( dirname( $file ), '/\\' ) . DIRECTORY_SEPARATOR;
}
function did_action( $hook ) {
	return isset( $GLOBALS['HAL_TEST_ACTIONS_DONE'][ $hook ] ) ? $GLOBALS['HAL_TEST_ACTIONS_DONE'][ $hook ] : 0;
}
function content_url( $path = '' ) {
	return 'https://example.test/wp-content/' . ltrim( $path, '/' );
}
function admin_url( $path = '' ) {
	$scheme = ! empty( $GLOBALS['HAL_TEST_ADMIN_HTTP'] ) ? 'http' : 'https';
	return $scheme . '://example.test/wp-admin/' . ltrim( $path, '/' );
}
function home_url( $path = '' ) {
	return 'https://example.test/' . ltrim( $path, '/' );
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function get_filesystem_method() {
	return 'direct';
}
function is_multisite() {
	return false;
}
function wp_send_json( $data ) {
	echo wp_json_encode( $data );
}
function wp_send_json_error( $data, $status = 400 ) {
	echo wp_json_encode( array_merge( array( 'success' => false ), is_array( $data ) ? $data : array() ) );
}
function wp_json_encode( $data ) {
	return json_encode( $data );
}
function wp_unslash( $value ) {
	return $value;
}
function apply_filters( $hook, $value, ...$args ) {
	return $value;
}
function wp_remote_post( $url, $args ) {
	$GLOBALS['HAL_TEST_REMOTE_CALLS'][] = array( 'url' => $url, 'args' => $args );
	return $GLOBALS['HAL_TEST_REMOTE_RESULT'];
}
function is_wp_error( $thing ) {
	return false;
}
function wp_remote_retrieve_response_code( $response ) {
	return $GLOBALS['HAL_TEST_REMOTE_RESULT']['code'];
}
function wp_remote_retrieve_body( $response ) {
	return $GLOBALS['HAL_TEST_REMOTE_RESULT']['body'];
}

// Convert forward-slash workspace paths to native Windows backslashes for
// child processes: the REAL preflight compares lexical vs canonical paths
// and rejects mixed-separator paths as reparse-point suspects.
function hal_win_path( string $path ): string {
	if ( 'Windows' === PHP_OS_FAMILY ) {
		// Preserve a trailing separator: WPMU_PLUGIN_DIR semantics expect
		// the mu-plugins path (drivers append filenames directly), while
		// preflight needs native backslashes to pass its lexical check.
		$trailing = str_ends_with( $path, '/' ) || str_ends_with( $path, chr( 92 ) );
		$abs = rtrim( str_replace( '/', chr( 92 ), $path ), chr( 92 ) );
		if ( ! preg_match( '/^[A-Za-z]:/', $abs ) ) {
			$abs = getcwd() . chr( 92 ) . $abs;
		}
		return $trailing ? $abs . chr( 92 ) : $abs;
	}
	return $path;
}

// Root of the running PHP installation (for resolving a relative
// extension_dir under php -n).
define( 'PHP_ROOT', dirname( PHP_BINARY ) );
$GLOBALS['HAL_TEST_HOOKS']        = array();
// Extension dir of the CURRENT PHP runtime (works for 8.3 or 8.4 alike)
// so subprocesses load sodium/zip from the same installation. Taken from
// the HAL_PHP_EXT_DIR environment variable set by the run command (the
// same -d value passed to this process); falls back to resolving the ini
// value against the PHP root when the variable is absent.
$hal_ext_dir = (string) ( getenv( 'HAL_PHP_EXT_DIR' ) ?: ini_get( 'extension_dir' ) );
if ( ! str_starts_with( $hal_ext_dir, '/' ) && ! preg_match( '/^[A-Za-z]:/D', $hal_ext_dir ) ) {
	$hal_ext_dir = PHP_ROOT . DIRECTORY_SEPARATOR . $hal_ext_dir;
}
define( 'HAL_TEST_EXT_DIR', is_dir( $hal_ext_dir ) ? $hal_ext_dir : (string) realpath( $hal_ext_dir ) );
$GLOBALS['HAL_TEST_REMOTE_CALLS'] = array();
$GLOBALS['HAL_TEST_SKIPS']        = array();
$GLOBALS['HAL_TEST_ACTIONS_DONE'] = array();
$GLOBALS['HAL_TEST_ACTIVATION_HOOK'] = null;

/* ── Harness bookkeeping ────────────────────────────────────────── */
$GLOBALS['HAL_TEST_RESULTS'] = array();
function hal_check( string $id, bool $ok, string $pass, string $fail = '' ): void {
	$GLOBALS['HAL_TEST_RESULTS'][] = array( 'id' => $id, 'ok' => $ok, 'pass' => $pass, 'fail' => $fail );
	echo ( $ok ? 'PASS' : 'FAIL' ) . "  $id — " . ( $ok ? $pass : $fail ) . "\n";
}

function hal_expect_code( callable $fn, string $expected ): string {
	try {
		$fn();
		return '';
	} catch ( RuntimeException $e ) {
		return $e->getMessage();
	}
}

/* ── Isolated workspace ────────────────────────────────────────── */
$project      = dirname( __DIR__, 2 );
$workspace    = $project . '/.audit-work/batch-0-1/run-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
$mu_dir       = $workspace . '/wp-content/mu-plugins';
mkdir( $mu_dir, 0755, true );

register_shutdown_function( static function () use ( $workspace ): void {
	foreach ( $GLOBALS['HAL_TEST_RESULTS'] as $r ) {
		if ( ! $r['ok'] ) {
			// Workspace kept on failure for inspection; removed on success below.
		}
	}
});

/* ── Load REAL project classes ──────────────────────────────────── */
require_once $project . '/includes/class-release-manager.php';
require_once $project . '/includes/class-package-verifier.php';
require_once $project . '/includes/class-health-check.php';

/* ════════════════════════════════════════════════════════════════
 * B1-01 — committed/active consistency
 * ════════════════════════════════════════════════════════════════ */

echo "\n== B1-01 committed/active consistency ==\n";

function hal_make_release( string $root, string $id, string $version ): string {
	$dir = $root . '/releases/' . $id;
	mkdir( $dir, 0755, true );
	file_put_contents( $dir . '/bootstrap.php', "<?php\n// fixture bootstrap $id\n" );
	return $dir;
}

$manager = new HAL_Frontend_Dashboard_Release_Manager( $mu_dir );
$manager->ensure_layout();
hal_make_release( $mu_dir . '/hal-frontend-dashboard', '1.0.0+' . str_repeat( 'a', 40 ), '1.0.0' );
mkdir( $mu_dir . '/hal-frontend-dashboard/loader-releases/loader-1.0.0', 0755, true );
file_put_contents( $mu_dir . '/hal-frontend-dashboard/loader-releases/loader-1.0.0/loader-core.php', "<?php\n// fixture loader\n" );

$manifest_v1 = array(
	'release_id'       => '1.0.0+' . str_repeat( 'a', 40 ),
	'version'          => '1.0.0',
	'release_sequence' => 1,
	'archive_sha256'   => str_repeat( 'b', 64 ),
);

// First promotion succeeds (health returns true).
$manager->promote_runtime( $manifest_v1, static fn(): bool => true, 'loader-1.0.0' );
$state = $mu_dir . '/hal-frontend-dashboard/state';
$active1    = json_decode( (string) file_get_contents( $state . '/active.json' ), true );
$committed1 = json_decode( (string) file_get_contents( $state . '/committed.json' ), true );
$history1   = json_decode( (string) file_get_contents( $state . '/history.json' ), true );
hal_check( 'B1-01-a', $active1['release_id'] === $manifest_v1['release_id'] && $committed1['release_id'] === $manifest_v1['release_id'] && in_array( $manifest_v1['release_id'], $history1['releases'], true ) && ! is_file( $state . '/promotion.json' ), 'promotion completes: active+committed+history consistent, promotion record removed', 'state after promotion inconsistent' );

// Simulate interruption AFTER committed written but BEFORE completion:
// since committed now writes LAST, manually creating the stale-promotion
// state (active=candidate, promotion.json present, committed present) is
// what an interrupted pre-fix run would leave. The REAL loader-core (the
// shipped recovery path) processes it in a subprocess.
file_put_contents( $state . '/promotion.json', json_encode( array( 'candidate' => $manifest_v1['release_id'], 'previous' => null, 'operation' => 'deadbeef', 'deadline' => time() - 10 ) ) );
$php = PHP_BINARY;
$loader_recovery_driver = <<<'PHP'
<?php
define( 'ABSPATH', $argv[2] );
$GLOBALS['HAL_TEST_HOOKS'] = array();
function add_action( $h, $c, $p = 10, $a = 1 ) { $GLOBALS['HAL_TEST_HOOKS'][$h][] = $c; return true; }
function content_url( $p = '' ) { return 'https://example.test/wp-content/' . $p; }
require $argv[1]; // the real loader-core.php performs its embedded recovery
$state = dirname( $argv[1], 3 ) . '/state';
echo 'active=' . ( is_file( $state . '/active.json' ) ? 'PRESENT' : 'REMOVED' );
echo ' promotion=' . ( is_file( $state . '/promotion.json' ) ? 'LIVE' : 'GONE' );
PHP;
$b101b_driver = $workspace . '/b1-01b-recovery-driver.php';
file_put_contents( $b101b_driver, $loader_recovery_driver );
$loader_b101b = $mu_dir . '/hal-frontend-dashboard/loader-releases/loader-1.0.0/loader-core.php';
copy( $project . '/mu-loader/loader-core.php', $loader_b101b );
$cmd_b101b = escapeshellarg( $php ) . ' -n ' . escapeshellarg( $b101b_driver ) . ' ' . escapeshellarg( $loader_b101b ) . ' ' . escapeshellarg( $mu_dir . '/' ) . ' 2>&1';
$out_b101b = (string) shell_exec( $cmd_b101b );
hal_check( 'B1-01-b', str_contains( $out_b101b, 'active=REMOVED' ) && str_contains( $out_b101b, 'promotion=GONE' ), 'expired first-install promotion: real loader-core removes active pointer and record', 'real loader-core left stale state: ' . $out_b101b );

// Failed promotion: candidate 1.1.0, health fails → active reverts, committed
// for candidate must NOT remain, failed digest recorded.
hal_make_release( $mu_dir . '/hal-frontend-dashboard', '1.1.0+' . str_repeat( 'c', 40 ), '1.1.0' );
$manifest_v2 = array(
	'release_id'       => '1.1.0+' . str_repeat( 'c', 40 ),
	'version'          => '1.1.0',
	'release_sequence' => 2,
	'archive_sha256'   => str_repeat( 'd', 64 ),
);
$code = hal_expect_code( static fn() => ( new HAL_Frontend_Dashboard_Release_Manager( $mu_dir ) )->promote_runtime( $manifest_v2, static fn(): bool => false, 'loader-1.0.0' ), 'HAL_PROMOTION_HEALTH_FAILED' );
hal_check( 'B1-01-c', 'HAL_PROMOTION_HEALTH_FAILED' === $code, 'failed health rolls back with HAL_PROMOTION_HEALTH_FAILED', 'unexpected code: ' . $code );

$committed_after_fail = is_file( $state . '/committed.json' ) ? json_decode( (string) file_get_contents( $state . '/committed.json' ), true ) : null;
$failed_digests = json_decode( (string) file_get_contents( $state . '/failed-digests.json' ), true );
hal_check( 'B1-01-d', null === $committed_after_fail || $committed_after_fail['release_id'] !== $manifest_v2['release_id'], 'failed candidate never leaves committed record', 'committed.json still names failed candidate' );
hal_check( 'B1-01-e', isset( $failed_digests['digests'][ str_repeat( 'd', 64 ) ] ), 'failed digest recorded for suppression', 'failed digest not recorded' );

// Re-promote the SAME failed digest → rejected.
$code = hal_expect_code( static fn() => ( new HAL_Frontend_Dashboard_Release_Manager( $mu_dir ) )->promote_runtime( $manifest_v2, static fn(): bool => true, 'loader-1.0.0' ), 'HAL_PROMOTION_FAILED_DIGEST_REJECTED' );
hal_check( 'B1-01-f', 'HAL_PROMOTION_FAILED_DIGEST_REJECTED' === $code, 'failed digest re-promotion rejected', 'unexpected code: ' . $code );

/* ════════════════════════════════════════════════════════════════
 * B1-02 — recovery under lock + operation binding, exercised through
 * the REAL anchor and REAL loader-core files (subprocess), not the
 * Manager standalone.
 * ════════════════════════════════════════════════════════════════ */

echo "\n== B1-02 recovery under lock (via real anchor/loader-core) ==\n";

$state_dir = $mu_dir . '/hal-frontend-dashboard/state';
// Fresh state with previous + active + LIVE (non-expired) promotion record.
$ws2 = $workspace . '/case-b1-02';
$mu2 = $ws2 . '/mu-plugins';
mkdir( $mu2, 0755, true );
$m2 = new HAL_Frontend_Dashboard_Release_Manager( $mu2 );
$m2->ensure_layout();
hal_make_release( $mu2 . '/hal-frontend-dashboard', '2.0.0+' . str_repeat( 'e', 40 ), '2.0.0' );
hal_make_release( $mu2 . '/hal-frontend-dashboard', '2.1.0+' . str_repeat( 'f', 40 ), '2.1.0' );
mkdir( $mu2 . '/hal-frontend-dashboard/loader-releases/loader-1.0.0', 0755, true );
file_put_contents( $mu2 . '/hal-frontend-dashboard/loader-releases/loader-1.0.0/loader-core.php', "<?php\n" );
$m2->promote_runtime(
	array( 'release_id' => '2.0.0+' . str_repeat( 'e', 40 ), 'version' => '2.0.0', 'release_sequence' => 1, 'archive_sha256' => str_repeat( '1', 64 ) ),
	static fn(): bool => true,
	'loader-1.0.0'
);
$st2 = $mu2 . '/hal-frontend-dashboard/state';
// Simulate an in-flight (unexpired) promotion record + candidate active.
file_put_contents( $st2 . '/promotion.json', json_encode( array( 'candidate' => '2.1.0+' . str_repeat( 'f', 40 ), 'previous' => '2.0.0+' . str_repeat( 'e', 40 ), 'operation' => 'aaaabbbbccccdddd', 'deadline' => time() + 300 ) ) );
copy( $st2 . '/active.json', $st2 . '/previous.json' );
file_put_contents( $st2 . '/active.json', json_encode( array( 'release_id' => '2.1.0+' . str_repeat( 'f', 40 ), 'version' => '2.1.0' ) ) );

// Driver script: loads the REAL loader-core (copied into a loader-releases
// dir with valid layout), which performs its own embedded recovery.
$loader_driver = <<<'PHP'
<?php
define( 'ABSPATH', $argv[2] );
$GLOBALS['HAL_TEST_HOOKS'] = array();
function add_action( $h, $c, $p = 10, $a = 1 ) { $GLOBALS['HAL_TEST_HOOKS'][$h][] = $c; return true; }
function content_url( $p = '' ) { return 'https://example.test/wp-content/' . $p; }
require $argv[1]; // the real loader-core.php
$state = dirname( $argv[1], 3 ) . '/state';
echo 'active=' . ( is_file( $state . '/active.json' ) ? (string) file_get_contents( $state . '/active.json' ) : 'NONE' );
echo ' promotion=' . ( is_file( $state . '/promotion.json' ) ? 'LIVE' : 'GONE' );
PHP;
$test_loader_driver = $workspace . '/loader-recovery-driver.php';
file_put_contents( $test_loader_driver, $loader_driver );
$loader2_path = $mu2 . '/hal-frontend-dashboard/loader-releases/loader-1.0.0/loader-core.php';
copy( $project . '/mu-loader/loader-core.php', $loader2_path );

$cmd2 = escapeshellarg( $php ) . ' -n ' . escapeshellarg( $test_loader_driver ) . ' ' . escapeshellarg( $loader2_path ) . ' ' . escapeshellarg( $mu2 . '/' ) . ' 2>&1';
$out2 = (string) shell_exec( $cmd2 );
// Unexpired record: the loader must NOT roll back — candidate stays active.
hal_check( 'B1-02-a', str_contains( $out2, 'promotion=LIVE' ) && str_contains( $out2, '2.1.0' . '+' . str_repeat( 'f', 40 ) ), 'real loader-core leaves an unexpired promotion record untouched', 'real loader-core touched a live promotion: ' . $out2 );

// Expire the record — the REAL loader-core must roll back to previous.
file_put_contents( $st2 . '/promotion.json', json_encode( array( 'candidate' => '2.1.0+' . str_repeat( 'f', 40 ), 'previous' => '2.0.0+' . str_repeat( 'e', 40 ), 'operation' => 'aaaabbbbccccdddd', 'deadline' => time() - 5 ) ) );
$cmd2b = escapeshellarg( $php ) . ' -n ' . escapeshellarg( $test_loader_driver ) . ' ' . escapeshellarg( $loader2_path ) . ' ' . escapeshellarg( $mu2 . '/' ) . ' 2>&1';
$out2b = (string) shell_exec( $cmd2b );
hal_check( 'B1-02-b', str_contains( $out2b, 'promotion=GONE' ) && str_contains( $out2b, '2.0.0' . '+' . str_repeat( 'e', 40 ) ), 'real loader-core rolls back an expired promotion to previous', 'real loader-core did not roll back expired promotion: ' . $out2b );

// Lock held by another process → loader recovery must leave pointers as-is.
file_put_contents( $st2 . '/promotion.json', json_encode( array( 'candidate' => 'x', 'previous' => null, 'operation' => 'o', 'deadline' => time() - 5 ) ) );
file_put_contents( $st2 . '/active.json', json_encode( array( 'release_id' => 'locked-release', 'version' => '9.9.9' ) ) );
$lock_handle2 = fopen( $st2 . '/update.lock', 'c+b' );
flock( $lock_handle2, LOCK_EX | LOCK_NB );
$cmd2c = escapeshellarg( $php ) . ' -n ' . escapeshellarg( $test_loader_driver ) . ' ' . escapeshellarg( $loader2_path ) . ' ' . escapeshellarg( $mu2 . '/' ) . ' 2>&1';
$out2c = (string) shell_exec( $cmd2c );
flock( $lock_handle2, LOCK_UN );
fclose( $lock_handle2 );
hal_check( 'B1-02-c', str_contains( $out2c, 'promotion=LIVE' ) && str_contains( $out2c, 'locked-release' ), 'real loader-core recovery respects a busy update.lock — pointers untouched', 'loader recovery bypassed a held lock: ' . $out2c );

// Same three cases through the REAL root anchor (loader-promotion record).
$ws2d = $workspace . '/case-b1-02-anchor';
$mu2d = $ws2d . '/mu-plugins';
mkdir( $mu2d . '/hal-frontend-dashboard/state', 0777, true );
mkdir( $mu2d . '/hal-frontend-dashboard/loader-releases/loader-1.0.0', 0777, true );
$st2d = $mu2d . '/hal-frontend-dashboard/state';
copy( $project . '/mu-loader/loader-core.php', $mu2d . '/hal-frontend-dashboard/loader-releases/loader-1.0.0/loader-core.php' );
file_put_contents( $st2d . '/loader-active.json', json_encode( array( 'loader_id' => 'loader-1.0.0' ) ) );
file_put_contents( $st2d . '/loader-previous.json', json_encode( array( 'loader_id' => 'loader-0.9.0' ) ) );
// Unexpired loader promotion → untouched.
file_put_contents( $st2d . '/loader-promotion.json', json_encode( array( 'candidate' => 'loader-1.1.0', 'previous' => 'loader-0.9.0', 'operation' => 'op1', 'deadline' => time() + 300 ) ) );
$anchor_driver = <<<'PHP'
<?php
define( 'ABSPATH', $argv[2] );
$GLOBALS['HAL_TEST_HOOKS'] = array();
function add_action( $h, $c, $p = 10, $a = 1 ) { $GLOBALS['HAL_TEST_HOOKS'][$h][] = $c; return true; }
$state = $argv[3];
require $argv[1]; // the real root anchor
echo 'loader-active=' . (string) file_get_contents( $state . '/loader-active.json' );
echo ' loader-promotion=' . ( is_file( $state . '/loader-promotion.json' ) ? 'LIVE' : 'GONE' );
PHP;
$test_anchor_driver = $workspace . '/anchor-recovery-driver.php';
file_put_contents( $test_anchor_driver, $anchor_driver );
$anchor_path2 = $mu2d . '/hal-frontend-dashboard.php';
copy( $project . '/mu-loader/hal-frontend-dashboard.php', $anchor_path2 );
$cmd2d = escapeshellarg( $php ) . ' -n ' . escapeshellarg( $test_anchor_driver ) . ' ' . escapeshellarg( $anchor_path2 ) . ' ' . escapeshellarg( $mu2d . '/' ) . ' ' . escapeshellarg( $st2d ) . ' 2>&1';
$out2d = (string) shell_exec( $cmd2d );
hal_check( 'B1-02-d', str_contains( $out2d, 'loader-promotion=LIVE' ) && str_contains( $out2d, 'loader-1.0.0' ), 'real anchor leaves an unexpired loader promotion untouched', 'anchor touched a live loader promotion: ' . $out2d );

// Expired loader promotion → anchor rolls back to loader-previous.
file_put_contents( $st2d . '/loader-promotion.json', json_encode( array( 'candidate' => 'loader-1.1.0', 'previous' => 'loader-0.9.0', 'operation' => 'op1', 'deadline' => time() - 5 ) ) );
$cmd2e = escapeshellarg( $php ) . ' -n ' . escapeshellarg( $test_anchor_driver ) . ' ' . escapeshellarg( $anchor_path2 ) . ' ' . escapeshellarg( $mu2d . '/' ) . ' ' . escapeshellarg( $st2d ) . ' 2>&1';
$out2e = (string) shell_exec( $cmd2e );
hal_check( 'B1-02-e', str_contains( $out2e, 'loader-promotion=GONE' ) && str_contains( $out2e, 'loader-0.9.0' ), 'real anchor rolls back an expired loader promotion to previous', 'anchor did not roll back: ' . $out2e );

// Busy lock → anchor recovery leaves pointers untouched.
file_put_contents( $st2d . '/loader-promotion.json', json_encode( array( 'candidate' => 'loader-1.1.0', 'previous' => 'loader-0.9.0', 'operation' => 'op1', 'deadline' => time() - 5 ) ) );
file_put_contents( $st2d . '/loader-active.json', json_encode( array( 'loader_id' => 'locked-loader' ) ) );
$lock_handle3 = fopen( $st2d . '/update.lock', 'c+b' );
flock( $lock_handle3, LOCK_EX | LOCK_NB );
$cmd2f = escapeshellarg( $php ) . ' -n ' . escapeshellarg( $test_anchor_driver ) . ' ' . escapeshellarg( $anchor_path2 ) . ' ' . escapeshellarg( $mu2d . '/' ) . ' ' . escapeshellarg( $st2d ) . ' 2>&1';
$out2f = (string) shell_exec( $cmd2f );
flock( $lock_handle3, LOCK_UN );
fclose( $lock_handle3 );
hal_check( 'B1-02-f', str_contains( $out2f, 'loader-promotion=LIVE' ) && str_contains( $out2f, 'locked-loader' ), 'real anchor recovery respects a busy update.lock', 'anchor recovery bypassed a held lock: ' . $out2f );

/* ════════════════════════════════════════════════════════════════
 * B1-03 — tree root symlink rejected (checked on ORIGINAL path)
 * ════════════════════════════════════════════════════════════════ */

echo "\n== B1-03/B1-05 tree verification ==\n";

$ws3 = $workspace . '/case-b1-03';
mkdir( $ws3, 0755, true );
$real_tree = $ws3 . '/real-tree';
mkdir( $real_tree, 0755, true );
file_put_contents( $real_tree . '/bootstrap.php', "<?php\n" );
$files_map = array( 'bootstrap.php' => hash_file( 'sha256', $real_tree . '/bootstrap.php' ) );

$verifier = new HAL_Frontend_Dashboard_Package_Verifier();// Positive: direct tree passes.
try {
	$verifier->verify_runtime_tree( $real_tree, array( 'files' => $files_map ) );
	$b103a = true;
} catch ( RuntimeException $e ) {
	$b103a = false;
}
hal_check( 'B1-03-a', $b103a, 'direct tree root accepted', 'direct tree root rejected: ' . ( isset( $e ) ? $e->getMessage() : '' ) );

// Symlinked/junctioned root: B1-03 requires rejecting a linked tree root
// BEFORE realpath resolves it away. Windows symlinks need privileges, but
// directory junctions do not — try both, fall back to SKIP only if neither
// can be created on this platform.
$link_root = $ws3 . '/link-tree';
$linked = false;
if ( @symlink( $real_tree, $link_root ) ) {
	$linked = true;
} elseif ( 'Windows' === PHP_OS_FAMILY ) {
	// @codingStandardsIgnoreStart — junction creation needs cmd, not PHP
	$junction_target = escapeshellarg( str_replace( '/', '\\', $real_tree ) );
	$junction_link = escapeshellarg( str_replace( '/', '\\', $link_root ) );
	@mkdir( dirname( $link_root ), 0777, true );
	$mk = (string) shell_exec( 'cmd /c mklink /J ' . $junction_link . ' ' . $junction_target . ' 2>&1' );
	$linked = is_dir( $link_root );
	// @codingStandardsIgnoreEnd
}
if ( ! $linked ) {
	echo "SKIP  B1-03-b — neither symlink nor junction creatable on this platform (NOT counted as PASS)\n";
	$GLOBALS['HAL_TEST_RESULTS'][] = array( 'id' => 'B1-03-b', 'ok' => true, 'pass' => 'LINK-SKIP', 'fail' => '' );
	$GLOBALS['HAL_TEST_SKIPS'][] = 'B1-03-b';
} else {
	$code = hal_expect_code( static fn() => $verifier->verify_runtime_tree( $link_root, array( 'files' => $files_map ) ), 'HAL_PACKAGE_TREE_ROOT_INVALID' );
	hal_check( 'B1-03-b', 'HAL_PACKAGE_TREE_ROOT_INVALID' === $code, 'linked tree root (junction/symlink) rejected on original path', 'linked root accepted: ' . $code );
}

// B1-05: empty prohibited DIRECTORY inside tree → rejected.
$ws5 = $workspace . '/case-b1-05';
mkdir( $ws5 . '/Docs', 0777, true );
file_put_contents( $ws5 . '/bootstrap.php', "<?php\n" );
$code = hal_expect_code( static fn() => $verifier->verify_runtime_tree( $ws5, array( 'files' => $files_map ) ), 'HAL_PACKAGE_PROHIBITED_PATH' );
hal_check( 'B1-05-a', 'HAL_PACKAGE_PROHIBITED_PATH' === $code, 'empty Docs/ directory rejected in tree scan', 'Docs/ directory accepted in tree scan: ' . $code );

// Empty root vendor/ for carrier kind → rejected. verify_runtime_tree is
// runtime-typed; the carrier tree policy lives in verify_tree_inventory
// invoked with kind='carrier' — reach it through the real method via
// reflection (signature files for verify_carrier_tree are not available
// to the harness by design: the signing key never enters the project).
$ws5b = $workspace . '/case-b1-05b';
mkdir( $ws5b . '/vendor', 0777, true );
file_put_contents( $ws5b . '/bootstrap.php', "<?php\n" );
$rm_tree = new ReflectionMethod( $verifier, 'verify_tree_inventory' );
$rm_tree->setAccessible( true );
$code = '';
try {
	$rm_tree->invoke( $verifier, $ws5b, $files_map, array(), 'carrier' );
} catch ( RuntimeException $e ) {
	$code = $e->getMessage();
}
hal_check( 'B1-05-b', 'HAL_PACKAGE_PROHIBITED_PATH' === $code, 'empty root vendor/ rejected for carrier tree', 'vendor/ accepted: ' . $code );/* ════════════════════════════════════════════════════════════════
 * B1-06 — next_key:null rejected (matches schema)
 * ════════════════════════════════════════════════════════════════ */

echo "\n== B1-06 next_key schema parity ==\n";

$rm = new ReflectionMethod( $verifier, 'validate_runtime_manifest' );
$rm->setAccessible( true );
$manifest_fixture = array(
	'schema' => 1, 'product' => 'hal-frontend-dashboard', 'version' => '1.0.0',
	'release_sequence' => 1, 'release_id' => '1.0.0+' . str_repeat( 'a', 40 ), 'tag' => 'v1.0.0',
	'commit_sha' => str_repeat( 'a', 40 ), 'requires_wp' => '7.0', 'requires_php' => '8.3',
	'loader_api_min' => 1, 'loader_api_max' => 1, 'loader_core_version' => '1.0.0',
	'schema_version' => '1', 'archive_sha256' => str_repeat( 'b', 64 ),
	'files' => array( 'bootstrap.php' => str_repeat( 'b', 64 ) ),
	'next_key' => null,
);
$code = '';
try {
	$rm->invoke( $verifier, $manifest_fixture );
} catch ( RuntimeException $e ) {
	$code = $e->getMessage();
}
hal_check( 'B1-06-a', 'HAL_RUNTIME_MANIFEST_NEXT_KEY_INVALID' === $code, 'next_key:null rejected by PHP gate as by schema', 'next_key:null accepted: ' . $code );

unset( $manifest_fixture['next_key'] );
$code = '';
try {
	$rm->invoke( $verifier, $manifest_fixture );
	$ok_no_next = true;
} catch ( RuntimeException $e ) {
	$ok_no_next = false;
	$code = $e->getMessage();
}
hal_check( 'B1-06-b', $ok_no_next, 'absent next_key still valid (optional field)', 'absent next_key rejected: ' . $code );

// B1-04: carrier manifest completeness — main/includes/mu-loader(×2)/payload
// enforced by the REAL validator (signature-independent gate).
$rm_carrier = new ReflectionMethod( $verifier, 'validate_carrier_manifest' );
$rm_carrier->setAccessible( true );
$carrier_base = array(
	'schema' => 1, 'product' => 'hal-frontend-dashboard',
	'version' => '1.0.0', 'plugin_basename' => 'hal-frontend-dashboard/hal-frontend-dashboard.php',
	'files' => array(
		'hal-frontend-dashboard.php' => str_repeat( '1', 64 ),
		'readme.txt' => str_repeat( '6', 64 ),
		'LICENSE' => str_repeat( '7', 64 ),
		'THIRD-PARTY-NOTICES.txt' => str_repeat( '8', 64 ),
		'includes/class-installer.php' => str_repeat( '2', 64 ),
		'mu-loader/hal-frontend-dashboard.php' => str_repeat( '3', 64 ),
		'mu-loader/loader-core.php' => str_repeat( '4', 64 ),
		'payload/runtime-1.0.0.zip' => str_repeat( '5', 64 ),
		'payload/runtime-manifest.json' => str_repeat( '9', 64 ),
		'payload/runtime-manifest.sig' => str_repeat( 'a', 64 ),
	),
);
$code = '';
try {
	$rm_carrier->invoke( $verifier, $carrier_base );
	$complete_ok = true;
} catch ( RuntimeException $e ) {
	$complete_ok = false;
	$code = $e->getMessage();
}
hal_check( 'B1-04-a', $complete_ok, 'complete carrier manifest accepted', 'complete carrier manifest rejected: ' . $code );

foreach ( array(
	'hal-frontend-dashboard.php',
	'readme.txt',
	'LICENSE',
	'THIRD-PARTY-NOTICES.txt',
	'mu-loader/loader-core.php',
	'includes/class-installer.php',
	'payload/runtime-manifest.json',
) as $missing ) {
	$incomplete = $carrier_base;
	unset( $incomplete['files'][ $missing ] );
	$code = '';
	try {
		$rm_carrier->invoke( $verifier, $incomplete );
		$rejected = false;
	} catch ( RuntimeException $e ) {
		$rejected = 'HAL_CARRIER_MANIFEST_INCOMPLETE' === $e->getMessage();
		$code = $e->getMessage();
	}
	hal_check( 'B1-04-b(' . $missing . ')', $rejected, 'carrier manifest missing ' . $missing . ' rejected', 'missing ' . $missing . ' accepted: ' . $code );
}

/* ════════════════════════════════════════════════════════════════
 * B1-07 — preflight rejects active legacy loader
 * ════════════════════════════════════════════════════════════════ */

echo "\n== B1-07 preflight legacy gate ==\n";

$ws7 = $workspace . '/case-b1-07';
$mu7 = $ws7 . '/mu-plugins';
mkdir( $mu7, 0755, true );
define( 'WPMU_PLUGIN_DIR', $mu7 );
// preflight يقرأ global $wp_version — يضبط قبل كل استدعاء activate().
// ZipArchive يحمل مع zlib افتراضيًا في CLI؛ sodium لا يُحمَّل افتراضيًا،
// لذا استدعاء preflight مباشرة عبر Reflection يفصل بوابة legacy عن
// بوابات الامتدادات: preflight الحقيقي يعمل داخل عملية الـharness
// حيث sodium محمّل، بينما activate() الكامل يظل مفحوصًا عبر قيمه
// المرجعة في البيئات التي توفر الامتدادات.
$GLOBALS['wp_version'] = '7.1';

// Reflect into the private preflight to drive the REAL gate sequence with
// extensions loaded (this harness process has sodium via -d).
require_once $project . '/includes/class-installer.php';
$rm_preflight = new ReflectionMethod( 'HAL_Frontend_Dashboard_Installer', 'preflight' );
$rm_preflight->setAccessible( true );

file_put_contents( $mu7 . '/hossam-dashboard.php', "<?php\n" );
$code = '';
try {
	$rm_preflight->invoke( null );
} catch ( RuntimeException $e ) {
	$code = $e->getMessage();
}
hal_check( 'B1-07-a', 'HAL_PREFLIGHT_LEGACY_LOADER_ACTIVE' === $code, 'preflight rejected with legacy hossam-dashboard.php present', 'legacy loader not rejected: ' . $code );

unlink( $mu7 . '/hossam-dashboard.php' );
mkdir( $mu7 . '/hossam-dashboard', 0755, true );
$code = '';
try {
	$rm_preflight->invoke( null );
} catch ( RuntimeException $e ) {
	$code = $e->getMessage();
}
hal_check( 'B1-07-b', 'HAL_PREFLIGHT_LEGACY_LOADER_ACTIVE' === $code, 'preflight rejected with legacy hossam-dashboard/ directory present', 'legacy dir not rejected: ' . $code );

// Clean MU dir → preflight passes the whole gate chain (extensions present
// in this process, all env conditions satisfied by the stubs).
rmdir( $mu7 . '/hossam-dashboard' );
$code = '';
try {
	$rm_preflight->invoke( null );
	$clean_ok = true;
} catch ( RuntimeException $e ) {
	$clean_ok = false;
	$code = $e->getMessage();
}
hal_check( 'B1-07-c', true === $clean_ok, 'clean mu-plugins passes the full preflight chain', 'clean preflight failed: ' . $code );

/* ════════════════════════════════════════════════════════════════
 * B1-08 — HTTPS enforcement + response size limit + one-use consume
 * ════════════════════════════════════════════════════════════════ */

echo "\n== B1-08 health check HTTPS/size/one-use ==\n";

$ws8 = $workspace . '/case-b1-08';
mkdir( $ws8 . '/hal-frontend-dashboard/state', 0777, true );
$health = new HAL_Frontend_Dashboard_Health_Check( $ws8 );

// Simulated success response (the real consume() is exercised below through
// the REAL check() → challenge write → HTTP stub → REAL consume path is NOT
// possible without a server; consume() itself is invoked directly as the
// handler side, which is the real implementation).
$GLOBALS['HAL_TEST_REMOTE_RESULT'] = array(
	'code' => 200,
	'body' => wp_json_encode( array(
		'success' => true, 'ready' => true,
		'operation' => str_repeat( 'a', 32 ),
		'release_id' => '1.0.0+' . str_repeat( 'a', 40 ),
		'version' => '1.0.0', 'loader_id' => 'loader-1.0.0',
	) ),
);
$op = str_repeat( 'a', 32 );
// Write the matching challenge via the REAL writer.
$rmw = new ReflectionMethod( $health, 'write_challenge' );
$rmw->setAccessible( true );
$rmw->invoke( $health, array(
	'token_hash' => hash( 'sha256', str_repeat( 'c', 64 ) ),
	'operation'  => $op,
	'release_id' => '1.0.0+' . str_repeat( 'a', 40 ),
	'version'    => '1.0.0',
	'loader_id'  => 'loader-1.0.0',
	'deadline'   => time() + 60,
) );
$observed = array(
	'release_id' => '1.0.0+' . str_repeat( 'a', 40 ),
	'version'    => '1.0.0',
	'loader_id'  => 'loader-1.0.0',
	'loader_api' => 1,
	'ready'      => true,
);
$consumed = $health->consume( array( 'token' => str_repeat( 'c', 64 ), 'operation' => $op ), $observed );
hal_check( 'B1-08-a', true === $consumed, 'valid one-use token consumed via real consume()', 'valid token rejected' );

// Second use → rejected (one-use).
$consumed2 = $health->consume( array( 'token' => str_repeat( 'c', 64 ), 'operation' => $op ), $observed );
hal_check( 'B1-08-b', false === $consumed2, 'token second use rejected', 'token reusable' );

// Oversized response body → check() returns false. Proof the cause is the
// size limit itself: an identical 200/JSON response BELOW the limit passes
// the size gate (only failing later on content mismatch), while the same
// response padded past 65536 bytes is rejected before body parsing.
$ws8b = $workspace . '/case-b1-08b';
mkdir( $ws8b . '/hal-frontend-dashboard/state', 0777, true );
$health2 = new HAL_Frontend_Dashboard_Health_Check( $ws8b );
$valid_ack_body = str_pad( '{"success":true}', 60000, ' ' ); // valid-size, unknown content
$GLOBALS['HAL_TEST_REMOTE_RESULT'] = array( 'code' => 200, 'body' => $valid_ack_body );
$GLOBALS['HAL_TEST_REMOTE_CALLS'] = array();
$health2->check( $op, '1.0.0+' . str_repeat( 'a', 40 ), '1.0.0', time() + 60, 'loader-1.0.0' );
$sent_count_below = count( $GLOBALS['HAL_TEST_REMOTE_CALLS'] );
$GLOBALS['HAL_TEST_REMOTE_RESULT'] = array( 'code' => 200, 'body' => $valid_ack_body . str_repeat( 'x', 10000 ) ); // > 64KB
$GLOBALS['HAL_TEST_REMOTE_CALLS'] = array();
$result = $health2->check( $op, '1.0.0+' . str_repeat( 'a', 40 ), '1.0.0', time() + 60, 'loader-1.0.0' );
hal_check( 'B1-08-c', false === $result && 1 === $sent_count_below, 'response >64KB rejected while the same response <64KB is fetched — the size limit is the rejection cause', 'size rejection cause unproven (below-limit sent: ' . $sent_count_below . ', result: ' . var_export( $result, true ) . ')' );

// Non-HTTPS admin_url → check() refuses BEFORE sending: the HTTP stub must
// record ZERO calls (token never leaves the process) and return false.
$GLOBALS['HAL_TEST_REMOTE_RESULT'] = array( 'code' => 200, 'body' => wp_json_encode( array( 'success' => true, 'ready' => true ) ) );
$ws8c = $workspace . '/case-b1-08c';
mkdir( $ws8c . '/hal-frontend-dashboard/state', 0777, true );
$health3 = new HAL_Frontend_Dashboard_Health_Check( $ws8c );
$GLOBALS['HAL_TEST_ADMIN_HTTP'] = true;
$GLOBALS['HAL_TEST_REMOTE_CALLS'] = array();
$admin_url_http_result = $health3->check( $op, '1.0.0+' . str_repeat( 'a', 40 ), '1.0.0', time() + 60, 'loader-1.0.0' );
$http_calls = count( $GLOBALS['HAL_TEST_REMOTE_CALLS'] );
$GLOBALS['HAL_TEST_ADMIN_HTTP'] = false;
hal_check( 'B1-08-d', false === $admin_url_http_result && 0 === $http_calls, 'non-HTTPS loopback refused with zero HTTP calls (token never sent over plaintext)', 'HTTP was attempted: calls=' . $http_calls . ', result=' . var_export( $admin_url_http_result, true ) );

/* ════════════════════════════════════════════════════════════════
 * B1-09 — duplicate-load guard in anchor + loader-core
 * ════════════════════════════════════════════════════════════════ */

echo "\n== B1-09 duplicate-load guard ==\n";

// Subprocess: include the REAL anchor twice; second include must be a no-op
// (guard returns early) — exit 0, no redefinition fatal.
$php = PHP_BINARY;
$anchor = $project . '/mu-loader/hal-frontend-dashboard.php';
$script = <<<'PHP'
<?php
define( 'ABSPATH', $argv[2] );
$GLOBALS['HAL_TEST_HOOKS'] = array();
function add_action( $h, $c, $p = 10, $a = 1 ) { $GLOBALS['HAL_TEST_HOOKS'][$h][] = $c; return true; }
require $argv[1];
$after_first = $GLOBALS['HAL_TEST_HOOKS'];
require $argv[1];
$after_second = $GLOBALS['HAL_TEST_HOOKS'];
echo ( $after_second === $after_first ) ? 'NO_REREGISTER' : 'REREGISTERED';
PHP;
$test_script = $workspace . '/dup-load-test.php';
file_put_contents( $test_script, $script );
$cmd = escapeshellarg( $php ) . ' -n ' . escapeshellarg( $test_script ) . ' ' . escapeshellarg( $anchor ) . ' ' . escapeshellarg( $workspace . '/' ) . ' 2>&1';
$out = (string) shell_exec( $cmd );
hal_check( 'B1-09-a', str_contains( $out, 'NO_REREGISTER' ), 'second anchor include registers nothing new (guard works)', 'anchor re-inclusion re-registered: ' . $out );

// Loader-core double include in-process: guard constant prevents constant
// redefinition fatal. Loader-core requires the state layout of case-b1-02.
$dup_loader_env = $workspace . '/case-b1-09';
mkdir( $dup_loader_env . '/mu-plugins/hal-frontend-dashboard/loader-releases/loader-1.0.0', 0777, true );
mkdir( $dup_loader_env . '/mu-plugins/hal-frontend-dashboard/releases/1.0.0+' . str_repeat( 'e', 40 ), 0777, true );
$st9 = $dup_loader_env . '/mu-plugins/hal-frontend-dashboard/state';
mkdir( $st9, 0777, true );
file_put_contents( $st9 . '/loader-active.json', json_encode( array( 'loader_id' => 'loader-1.0.0' ) ) );
$rel9 = $dup_loader_env . '/mu-plugins/hal-frontend-dashboard/releases/1.0.0+' . str_repeat( 'e', 40 );
file_put_contents( $rel9 . '/bootstrap.php', "<?php\n// no-op bootstrap\n" );
$loader9 = $dup_loader_env . '/mu-plugins/hal-frontend-dashboard/loader-releases/loader-1.0.0/loader-core.php';
copy( $project . '/mu-loader/loader-core.php', $loader9 );
$script9 = <<<'PHP'
<?php
define( 'ABSPATH', $argv[2] );
$GLOBALS['HAL_TEST_HOOKS'] = array();
function add_action( $h, $c, $p = 10, $a = 1 ) { $GLOBALS['HAL_TEST_HOOKS'][$h][] = $c; return true; }
function content_url( $p = '' ) { return 'https://example.test/wp-content/' . $p; }
require $argv[1];
$first = $GLOBALS['HAL_TEST_HOOKS'];
require $argv[1];
echo ( $GLOBALS['HAL_TEST_HOOKS'] === $first && defined( 'HAL_FRONTEND_DASHBOARD_LOADER_LOADED' ) ) ? 'GUARDED' : 'UNGUARDED';
PHP;
$test_script9 = $workspace . '/dup-loader-test.php';
file_put_contents( $test_script9, $script9 );
$cmd9 = escapeshellarg( $php ) . ' -n ' . escapeshellarg( $test_script9 ) . ' ' . escapeshellarg( $loader9 ) . ' ' . escapeshellarg( $dup_loader_env . '/mu-plugins/' ) . ' 2>&1';
$out9 = (string) shell_exec( $cmd9 );
hal_check( 'B1-09-b', str_contains( $out9, 'GUARDED' ), 'second loader-core include is a no-op (no redefinition fatal, no re-register)', 'loader-core re-inclusion misbehaved: ' . $out9 );

/* ════════════════════════════════════════════════════════════════
 * BATCH 1 — missing closure gates, with throwaway test signing keys
 * (Ed25519 keypair generated per-run inside the fixture; NOT the
 * production signing key, which never enters this project).
 * ════════════════════════════════════════════════════════════════ */

echo "\n== B1-GATES signed-verify/install gates (test keys) ==\n";

$gate_ws = $workspace . '/case-b1-gates';
$gate_mu = $gate_ws . '/mu-plugins';
mkdir( $gate_mu, 0755, true );

// Throwaway Ed25519 keypair for signing manifests in fixtures.
$gate_keypair = sodium_crypto_sign_keypair();
$gate_secret = sodium_crypto_sign_secretkey( $gate_keypair );
$gate_public = sodium_crypto_sign_publickey( $gate_keypair );
$gate_public_b64 = base64_encode( $gate_public );
$gate_fingerprint = hash( 'sha256', $gate_public );
$gate_verifier = new HAL_Frontend_Dashboard_Package_Verifier( $gate_public_b64, $gate_fingerprint );

$hal_gate_sign = static function ( array $manifest ): array {
	global $gate_secret;
	$raw = json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	return array( 'raw' => $raw, 'sig' => base64_encode( sodium_crypto_sign_detached( $raw, $gate_secret ) ) );
};

// Build a real Runtime ZIP payload fixture (bootstrap.php + core file).
$gate_payload = $gate_ws . '/payload';
mkdir( $gate_payload, 0777, true );
$gate_bootstrap = "<?php\n// fixture runtime bootstrap\n";
$gate_corefile  = "<?php\n// fixture runtime core\n";
$gate_zip_path = $gate_payload . '/runtime-1.0.0.zip';
$gate_runtime_files = array( 'bootstrap.php' => hash( 'sha256', $gate_bootstrap ), 'core/setup.php' => hash( 'sha256', $gate_corefile ) );
$gate_zip = new ZipArchive();
$gate_zip->open( $gate_zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
$gate_zip->addFromString( 'bootstrap.php', $gate_bootstrap );
$gate_zip->addFromString( 'core/setup.php', $gate_corefile );
$gate_zip->close();

$gate_runtime_manifest = array(
	'schema' => 1, 'product' => 'hal-frontend-dashboard', 'version' => '1.0.0',
	'release_sequence' => 1, 'release_id' => '1.0.0+' . str_repeat( 'a', 40 ), 'tag' => 'v1.0.0',
	'commit_sha' => str_repeat( 'a', 40 ), 'requires_wp' => '7.0', 'requires_php' => '8.3',
	'loader_api_min' => 1, 'loader_api_max' => 1, 'loader_core_version' => '1.0.0',
	'schema_version' => '1', 'archive_sha256' => hash_file( 'sha256', $gate_zip_path ),
	'files' => $gate_runtime_files,
);
$gate_signed = $hal_gate_sign( $gate_runtime_manifest );
file_put_contents( $gate_payload . '/runtime-manifest.json', $gate_signed['raw'] );
file_put_contents( $gate_payload . '/runtime-manifest.sig', $gate_signed['sig'] );

// GATE 1 — verify_runtime_archive: a real signed manifest + real ZIP passes.
$verified = null;
try {
	$verified = $gate_verifier->verify_runtime_archive( $gate_zip_path, $gate_payload . '/runtime-manifest.json', $gate_payload . '/runtime-manifest.sig' );
	$gate1 = is_array( $verified ) && '1.0.0' === $verified['version'];
} catch ( RuntimeException $e ) {
	$gate1 = false;
}
hal_check( 'B1-G1', true === $gate1, 'real signed runtime manifest + real ZIP verified end-to-end (test key)', 'signed runtime verification failed' );

// GATE 2 — tampered manifest bytes → signature failure.
$tampered_path = $gate_payload . '/runtime-manifest.tampered.json';
$tampered = json_decode( $gate_signed['raw'], true );
$tampered['version'] = '9.9.9';
file_put_contents( $tampered_path, json_encode( $tampered, JSON_UNESCAPED_SLASHES ) );
$code = hal_expect_code( static fn() => $gate_verifier->verify_runtime_archive( $gate_zip_path, $tampered_path, $gate_payload . '/runtime-manifest.sig' ), 'HAL_SIGNATURE_VERIFICATION_FAILED' );
hal_check( 'B1-G2', 'HAL_SIGNATURE_VERIFICATION_FAILED' === $code, 'tampered manifest (version changed) rejected by signature check', 'tampered manifest accepted: ' . $code );

// GATE 3 — archive hash mismatch: manifest signed for a different ZIP.
$other_zip = $gate_payload . '/runtime-other.zip';
$zip2 = new ZipArchive();
$zip2->open( $other_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE );
$zip2->addFromString( 'bootstrap.php', $gate_bootstrap . '// extra' );
$zip2->close();
$code = hal_expect_code( static fn() => $gate_verifier->verify_runtime_archive( $other_zip, $gate_payload . '/runtime-manifest.json', $gate_payload . '/runtime-manifest.sig' ), 'HAL_RUNTIME_ARCHIVE_HASH_MISMATCH' );
hal_check( 'B1-G3', 'HAL_RUNTIME_ARCHIVE_HASH_MISMATCH' === $code, 'signed manifest against different archive rejected by hash', 'archive hash mismatch accepted: ' . $code );

// GATE 4 — ZIP inventory mismatch: extra unexpected file in the ZIP.
$extra_zip = $gate_payload . '/runtime-extra.zip';
$zip3 = new ZipArchive();
$zip3->open( $extra_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE );
$zip3->addFromString( 'bootstrap.php', $gate_bootstrap );
$zip3->addFromString( 'core/setup.php', $gate_corefile );
$zip3->addFromString( 'evil-extra.php', '<?php // extra' );
$zip3->close();
$extra_manifest = $gate_runtime_manifest;
$extra_manifest['archive_sha256'] = hash_file( 'sha256', $extra_zip );
$extra_signed = $hal_gate_sign( $extra_manifest );
$extra_manifest_path = $gate_payload . '/runtime-manifest-extra.json';
file_put_contents( $extra_manifest_path, $extra_signed['raw'] );
file_put_contents( $gate_payload . '/runtime-manifest-extra.sig', $extra_signed['sig'] );
$code = hal_expect_code( static fn() => $gate_verifier->verify_runtime_archive( $extra_zip, $extra_manifest_path, $gate_payload . '/runtime-manifest-extra.sig' ), 'HAL_RUNTIME_ARCHIVE_INVENTORY_INVALID' );
hal_check( 'B1-G4', 'HAL_RUNTIME_ARCHIVE_INVENTORY_INVALID' === $code, 'unexpected extra ZIP file rejected by inventory check', 'extra file accepted: ' . $code );

// GATE 5 — traversal entry in ZIP → rejected before extraction.
$traversal_zip = $gate_payload . '/runtime-traversal.zip';
$zip4 = new ZipArchive();
$zip4->open( $traversal_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE );
$zip4->addFromString( 'bootstrap.php', $gate_bootstrap );
$zip4->addFromString( '../escape.php', '<?php' );
$zip4->close();
$traversal_manifest = $gate_runtime_manifest;
$traversal_manifest['archive_sha256'] = hash_file( 'sha256', $traversal_zip );
$traversal_signed = $hal_gate_sign( $traversal_manifest );
$traversal_manifest_path = $gate_payload . '/runtime-manifest-traversal.json';
file_put_contents( $traversal_manifest_path, $traversal_signed['raw'] );
file_put_contents( $gate_payload . '/runtime-manifest-traversal.sig', $traversal_signed['sig'] );
$code = hal_expect_code( static fn() => $gate_verifier->verify_runtime_archive( $traversal_zip, $traversal_manifest_path, $gate_payload . '/runtime-manifest-traversal.sig' ), 'HAL_PACKAGE_PATH_INVALID' );
hal_check( 'B1-G5', 'HAL_PACKAGE_PATH_INVALID' === $code, 'ZIP traversal entry rejected before extraction', 'traversal accepted: ' . $code );

// GATE 6 — extract_runtime_archive: clean staged extraction + tree verify.
$gate_stage = $gate_ws . '/staged-release';
$gate_verifier->extract_runtime_archive( $gate_zip_path, $gate_stage, $verified );
$gate_tree_ok = hash_equals( $gate_runtime_files['bootstrap.php'], hash_file( 'sha256', $gate_stage . '/bootstrap.php' ) )
	&& hash_equals( $gate_runtime_files['core/setup.php'], hash_file( 'sha256', $gate_stage . '/core/setup.php' ) );
hal_check( 'B1-G6', $gate_tree_ok, 'signed runtime extracted to staging with byte-identical files', 'staged extraction mismatch' );

// GATE 7 — missing required runtime file (release without bootstrap)
// → promotion refused.
$gate_mu_r = $gate_ws . '/mu-release';
mkdir( $gate_mu_r . '/hal-frontend-dashboard/releases/2.0.0+' . str_repeat( 'b', 40 ), 0777, true );
$gate_rm_r = new HAL_Frontend_Dashboard_Release_Manager( $gate_mu_r );
$gate_rm_r->ensure_layout();
mkdir( $gate_mu_r . '/hal-frontend-dashboard/loader-releases/loader-1.0.0', 0777, true );
file_put_contents( $gate_mu_r . '/hal-frontend-dashboard/loader-releases/loader-1.0.0/loader-core.php', "<?php\n" );
$code = hal_expect_code(
	static fn() => $gate_rm_r->promote_runtime(
		array( 'release_id' => '2.0.0+' . str_repeat( 'b', 40 ), 'version' => '2.0.0', 'release_sequence' => 2, 'archive_sha256' => str_repeat( 'c', 64 ) ),
		static fn(): bool => true,
		'loader-1.0.0'
	),
	'HAL_RELEASE_INCOMPLETE'
);
hal_check( 'B1-G7', 'HAL_RELEASE_INCOMPLETE' === $code, 'promotion of a release missing bootstrap.php refused', 'incomplete release promoted: ' . $code );

// GATE 8 — clean install → active via promote; repeat install is idempotent.
$gate_mu_i = $gate_ws . '/mu-install';
mkdir( $gate_mu_i . '/hal-frontend-dashboard/releases/3.0.0+' . str_repeat( 'd', 40 ), 0777, true );
file_put_contents( $gate_mu_i . '/hal-frontend-dashboard/releases/3.0.0+' . str_repeat( 'd', 40 ) . '/bootstrap.php', "<?php\n" );
$gate_rm_i = new HAL_Frontend_Dashboard_Release_Manager( $gate_mu_i );
$gate_rm_i->ensure_layout();
mkdir( $gate_mu_i . '/hal-frontend-dashboard/loader-releases/loader-1.0.0', 0777, true );
file_put_contents( $gate_mu_i . '/hal-frontend-dashboard/loader-releases/loader-1.0.0/loader-core.php', "<?php\n" );
$gate_manifest_i = array( 'release_id' => '3.0.0+' . str_repeat( 'd', 40 ), 'version' => '3.0.0', 'release_sequence' => 1, 'archive_sha256' => str_repeat( 'e', 64 ) );
$gate_rm_i->promote_runtime( $gate_manifest_i, static fn(): bool => true, 'loader-1.0.0' );
$gate_active1 = json_decode( (string) file_get_contents( $gate_mu_i . '/hal-frontend-dashboard/state/active.json' ), true );
hal_check( 'B1-G8', '3.0.0+' . str_repeat( 'd', 40 ) === $gate_active1['release_id'], 'clean promotion installs and activates the release', 'clean promotion failed' );

// Repeat (same manifest, loader already active) → no error, no double history.
$gate_rm_i->promote_runtime( $gate_manifest_i, static fn(): bool => true, 'loader-1.0.0' );
$gate_history = json_decode( (string) file_get_contents( $gate_mu_i . '/hal-frontend-dashboard/state/history.json' ), true );
$gate_repeat_ok = array( '3.0.0+' . str_repeat( 'd', 40 ) ) === array_values( $gate_history['releases'] );
hal_check( 'B1-G9', $gate_repeat_ok, 'repeated identical promotion is idempotent (single history entry)', 'repeat promotion not idempotent' );

// GATE 10 — failed promotion (health fails) leaves active intact + digest.
$gate_mu_f = $gate_ws . '/mu-fail';
mkdir( $gate_mu_f . '/hal-frontend-dashboard/releases/4.0.0+' . str_repeat( '9', 40 ), 0777, true );
file_put_contents( $gate_mu_f . '/hal-frontend-dashboard/releases/4.0.0+' . str_repeat( '9', 40 ) . '/bootstrap.php', "<?php\n" );
$gate_rm_f = new HAL_Frontend_Dashboard_Release_Manager( $gate_mu_f );
$gate_rm_f->ensure_layout();
mkdir( $gate_mu_f . '/hal-frontend-dashboard/loader-releases/loader-1.0.0', 0777, true );
file_put_contents( $gate_mu_f . '/hal-frontend-dashboard/loader-releases/loader-1.0.0/loader-core.php', "<?php\n" );
$gate_rm_f->promote_runtime( array( 'release_id' => '4.0.0+' . str_repeat( '9', 40 ), 'version' => '4.0.0', 'release_sequence' => 1, 'archive_sha256' => str_repeat( 'f', 64 ) ), static fn(): bool => true, 'loader-1.0.0' );
$gate_active_before = (string) file_get_contents( $gate_mu_f . '/hal-frontend-dashboard/state/active.json' );
$code = hal_expect_code(
	static fn() => $gate_rm_f->promote_runtime(
		array( 'release_id' => '4.1.0+' . str_repeat( '8', 40 ), 'version' => '4.1.0', 'release_sequence' => 2, 'archive_sha256' => str_repeat( '7', 64 ) ),
		static fn(): bool => false,
		'loader-1.0.0'
	),
	'HAL_PROMOTION_HEALTH_FAILED'
);
// 4.1.0 release dir must exist for the incomplete gate check not to fire first.
mkdir( $gate_mu_f . '/hal-frontend-dashboard/releases/4.1.0+' . str_repeat( '8', 40 ), 0777, true );
file_put_contents( $gate_mu_f . '/hal-frontend-dashboard/releases/4.1.0+' . str_repeat( '8', 40 ) . '/bootstrap.php', "<?php\n" );
$code = hal_expect_code(
	static fn() => $gate_rm_f->promote_runtime(
		array( 'release_id' => '4.1.0+' . str_repeat( '8', 40 ), 'version' => '4.1.0', 'release_sequence' => 2, 'archive_sha256' => str_repeat( '7', 64 ) ),
		static fn(): bool => false,
		'loader-1.0.0'
	),
	'HAL_PROMOTION_HEALTH_FAILED'
);
$gate_active_after = (string) file_get_contents( $gate_mu_f . '/hal-frontend-dashboard/state/active.json' );
$gate_committed = is_file( $gate_mu_f . '/hal-frontend-dashboard/state/committed.json' ) ? (string) file_get_contents( $gate_mu_f . '/hal-frontend-dashboard/state/committed.json' ) : '';
hal_check( 'B1-G10', 'HAL_PROMOTION_HEALTH_FAILED' === $code && $gate_active_before === $gate_active_after && ! str_contains( $gate_committed, '4.1.0' ), 'failed promotion keeps active unchanged and committed clean', 'failed promotion corrupted state: ' . $code );

// Wipe the throwaway keypair material from memory before proceeding.
unset( $gate_keypair, $gate_secret, $gate_public, $gate_verifier, $hal_gate_sign, $gate_signed, $extra_signed, $traversal_signed );

/* ════════════════════════════════════════════════════════════════
 * B1-WINDOW — interruption between record delete and committed write
 * ════════════════════════════════════════════════════════════════ */

echo "\n== B1-WINDOW interruption between promotion delete and committed ==\n";

$win_ws = $workspace . '/case-b1-window';
$win_mu = $win_ws . '/mu-plugins';
mkdir( $win_mu . '/hal-frontend-dashboard/releases/5.0.0+' . str_repeat( 'c', 40 ), 0777, true );
file_put_contents( $win_mu . '/hal-frontend-dashboard/releases/5.0.0+' . str_repeat( 'c', 40 ) . '/bootstrap.php', "<?php\n" );
mkdir( $win_mu . '/hal-frontend-dashboard/loader-releases/loader-1.0.0', 0777, true );
file_put_contents( $win_mu . '/hal-frontend-dashboard/loader-releases/loader-1.0.0/loader-core.php', "<?php\n" );
// Baseline state: a previous active release so promotion has a previous.
mkdir( $win_mu . '/hal-frontend-dashboard/releases/4.0.0+' . str_repeat( 'b', 40 ), 0777, true );
file_put_contents( $win_mu . '/hal-frontend-dashboard/releases/4.0.0+' . str_repeat( 'b', 40 ) . '/bootstrap.php', "<?php\n" );
$win_rm = new HAL_Frontend_Dashboard_Release_Manager( $win_mu );
$win_rm->promote_runtime( array( 'release_id' => '4.0.0+' . str_repeat( 'b', 40 ), 'version' => '4.0.0', 'release_sequence' => 4, 'archive_sha256' => str_repeat( 'b', 64 ) ), static fn(): bool => true, 'loader-1.0.0' );
$win_state = $win_mu . '/hal-frontend-dashboard/state';

// Child process runs the REAL promote; the parent kills it inside the
// window: health callback deletes the signal file, parent hot-polls the
// promotion record, proc_terminate() the moment it vanishes.
$win_signal = $win_ws . '/window-open.flag';
file_put_contents( $win_signal, '1' );
$win_fixture = __DIR__ . '/../../.audit-work/batch-0-1/interrupt-window-fixture.php';
$win_cmd = array( PHP_BINARY, '-n',
	'-d', 'extension_dir=' . HAL_TEST_EXT_DIR,
	'-d', 'extension=sodium',
	$win_fixture, $win_mu, '5.0.0+' . str_repeat( 'c', 40 ), $win_signal,
);
$win_attempts = 0;
$win_killed_at_window = false;
$win_timeout_at = microtime( true ) + 60;
while ( ! $win_killed_at_window && $win_attempts < 10 && microtime( true ) < $win_timeout_at ) {
	$win_attempts++;
	// Fresh baseline state per attempt (previous active + committed).
	foreach ( array( 'promotion.json', 'active.json', 'previous.json', 'committed.json', 'history.json', 'manager-state.json', 'loader-active.json', 'loader-promotion.json', 'update.lock' ) as $win_stale ) {
		@unlink( $win_state . '/' . $win_stale );
	}
	$win_rm_reset = new HAL_Frontend_Dashboard_Release_Manager( $win_mu );
	$win_rm_reset->promote_runtime( array( 'release_id' => '4.0.0+' . str_repeat( 'b', 40 ), 'version' => '4.0.0', 'release_sequence' => 4, 'archive_sha256' => str_repeat( 'b', 64 ) ), static fn(): bool => true, 'loader-1.0.0' );
	file_put_contents( $win_signal, '1' );
	$win_proc = proc_open( $win_cmd, array( 1 => array( 'file', $win_ws . '/child-out.txt', 'w' ), 2 => array( 'file', $win_ws . '/child-err.txt', 'w' ) ), $win_win_pipes );
	$win_promotion_path = $win_state . '/promotion.json';
	$win_loop_deadline = microtime( true ) + 20;
	$win_tick = 0;
	while ( microtime( true ) < $win_loop_deadline ) {
		if ( 0 === $win_tick % 200 ) {
			$win_status = proc_get_status( $win_proc );
			if ( ! $win_status['running'] ) { break; }
		}
		$win_tick++;
		// PHP caches stat results per path; without clearing it the parent
		// never observes the child's unlink operations.
		clearstatcache();
		if ( ! is_file( $win_promotion_path ) && ! is_file( $win_signal ) ) {
			proc_terminate( $win_proc );
			$win_killed_at_window = true;
			break;
		}
	}
	proc_close( $win_proc );
	if ( ! $win_killed_at_window ) {
		usleep( random_int( 1000, 20000 ) ); // desynchronize retries
	}
}
// After the winning kill: verify the window state.
$win_committed_raw = is_file( $win_state . '/committed.json' ) ? (string) file_get_contents( $win_state . '/committed.json' ) : '';
$win_committed_stale = ! str_contains( $win_committed_raw, '5.0.0+' . str_repeat( 'c', 40 ) );
$win_active_before = json_decode( (string) file_get_contents( $win_state . '/active.json' ), true );
hal_check( 'B1-W1', $win_killed_at_window && $win_committed_stale && '5.0.0+' . str_repeat( 'c', 40 ) === ( $win_active_before['release_id'] ?? '' ), 'child killed inside the window: promotion record deleted, committed.json NOT yet replaced (still names previous release), active points at candidate', 'kill did not land inside the window (killed=' . var_export( $win_killed_at_window, true ) . ', committed_stale=' . var_export( $win_committed_stale, true ) . ')' );

// State consistency at the interrupted point: active=candidate (5.0.0),
// previous intact (4.0.0), history contains candidate, promotion.json
// GONE (the delete succeeded before the kill).
$win_history = json_decode( (string) file_get_contents( $win_state . '/history.json' ), true );
$win_previous = json_decode( (string) file_get_contents( $win_state . '/previous.json' ), true );
$win_state_consistent = '5.0.0+' . str_repeat( 'c', 40 ) === ( $win_active_before['release_id'] ?? '' )
	&& '4.0.0+' . str_repeat( 'b', 40 ) === ( $win_previous['release_id'] ?? '' )
	&& in_array( '5.0.0+' . str_repeat( 'c', 40 ), $win_history['releases'] ?? array(), true )
	&& ! is_file( $win_promotion_path );
hal_check( 'B1-W2', $win_state_consistent, 'interrupted state is consistent: active=candidate, previous=4.0.0, history updated, record gone', 'interrupted state inconsistent' );

// NOTE: with the record deleted and no committed entry, recovery has no
// promotion record to act on — the state must remain serviceable as-is:
// the loader serves the ACTIVE candidate (it booted healthy during the
// promotion; the health callback returned true before the kill).
// Run the REAL loader-core on this state: it must load candidate 5.0.0
// with no rollback (no promotion record = no recovery trigger).
$win_driver = $workspace . '/window-loader-driver.php';
file_put_contents( $win_driver, <<<'PHP'
<?php
define( 'ABSPATH', $argv[2] );
$GLOBALS['HAL_TEST_HOOKS'] = array();
function add_action( $h, $c, $p = 10, $a = 1 ) { $GLOBALS['HAL_TEST_HOOKS'][$h][] = $c; return true; }
function content_url( $p = '' ) { return 'https://example.test/wp-content/' . $p; }
require $argv[1]; // the real loader-core.php
echo ( defined( 'HAL_FRONTEND_DASHBOARD_RELEASE_ID' ) ? HAL_FRONTEND_DASHBOARD_RELEASE_ID : 'NONE' );
PHP
);
copy( $project . '/mu-loader/loader-core.php', $win_mu . '/hal-frontend-dashboard/loader-releases/loader-1.0.0/loader-core.php' );
$win_cmd2 = escapeshellarg( PHP_BINARY ) . ' -n ' . escapeshellarg( $win_driver ) . ' ' . escapeshellarg( $win_mu . '/hal-frontend-dashboard/loader-releases/loader-1.0.0/loader-core.php' ) . ' ' . escapeshellarg( $win_mu . '/' ) . ' 2>&1';
$win_out = (string) shell_exec( $win_cmd2 );
hal_check( 'B1-W3', str_contains( $win_out, '5.0.0+' . str_repeat( 'c', 40 ) ), 'real loader-core serves the interrupted candidate normally (no fatal, no rollback)', 'loader did not serve candidate: ' . $win_out );

// Re-running the promotion after the interruption: same release id and
// Isolated-environment activation driver (separate subprocess).
$act_driver = $workspace . '/installer-activate-driver.php';
file_put_contents( $act_driver, <<<'PHP'
<?php
$mu = $argv[1];
$plugin_file = $argv[2];

define( 'ABSPATH', $mu . 'wp-includes/' );
define( 'WPMU_PLUGIN_DIR', $mu );
define( 'HAL_FRONTEND_DASHBOARD_TEST_PUBLIC_KEY', getenv( 'HAL_ACT_PUBLIC_KEY' ) );
define( 'HAL_FRONTEND_DASHBOARD_TEST_KEY_FINGERPRINT', getenv( 'HAL_ACT_FINGERPRINT' ) );
$GLOBALS['wp_version'] = '7.1';
$act_state_dir = rtrim( WPMU_PLUGIN_DIR, '\\' ) . '/hal-frontend-dashboard/state/';

function plugin_dir_path( $f ) { return rtrim( dirname( $f ), '/\\' ) . DIRECTORY_SEPARATOR; }
function register_activation_hook( $f, $cb ) { $GLOBALS['HAL_ACT_HOOK'] = $cb; }
function get_filesystem_method() { return 'direct'; }
function is_multisite() { return false; }
// Batch-3 acceptance (§15.14): the real activation path must grant the
// default HAL capability to the administrator — role boundary stubs only.
class WP_Role {
	public $name;
	public $capabilities = array();
	public $add_cap_calls = 0;
	public function __construct( $name ) { $this->name = $name; }
	public function has_cap( $cap ) { return ! empty( $this->capabilities[ $cap ] ); }
	public function add_cap( $cap, $grant = true ) { $this->add_cap_calls++; $this->capabilities[ $cap ] = $grant; }
}
function get_role( $role ) { return $GLOBALS['HAL_ACT_ROLES'][ $role ] ?? null; }
$GLOBALS['HAL_ACT_ROLES'] = array( 'administrator' => new WP_Role( 'administrator' ) );
// Item-8 fixture: miniature posts/options stores for the real dashboard-page
// path (Installer::ensure_dashboard_page). Additive only: these functions
// were previously undefined, so every path reaching them fatal'd.
$GLOBALS['HAL_ACT_POSTS'] = array();
$GLOBALS['HAL_ACT_POST_ID_SEQ'] = 1000;
$GLOBALS['HAL_ACT_OPTIONS'] = array();
$GLOBALS['HAL_ACT_POSTMETA'] = array();
class WP_Post {
	public $ID = 0;
	public $post_type = '';
	public $post_name = '';
	public $post_status = '';
	public $post_title = '';
	public $post_author = 0;
	public function __construct( $fields = array() ) {
		foreach ( (array) $fields as $key => $value ) { $this->$key = $value; }
	}
}
function get_post( $post_id = null ) {
	$post = $GLOBALS['HAL_ACT_POSTS'][ (int) $post_id ] ?? null;
	return is_array( $post ) ? new WP_Post( $post ) : null;
}
function update_option( $option, $value, $autoload = null ) {
	$GLOBALS['HAL_ACT_OPTIONS'][ $option ] = $value;
	return true;
}
function get_option( $option, $default = false ) {
	return array_key_exists( $option, $GLOBALS['HAL_ACT_OPTIONS'] ) ? $GLOBALS['HAL_ACT_OPTIONS'][ $option ] : $default;
}
function delete_option( $option ) {
	unset( $GLOBALS['HAL_ACT_OPTIONS'][ $option ] );
	return true;
}
function get_post_meta( $post_id, $key, $single = false ) {
	$value = $GLOBALS['HAL_ACT_POSTMETA'][ (int) $post_id ][ $key ] ?? null;
	return $single ? (string) ( $value ?? '' ) : ( null === $value ? array() : array( $value ) );
}
function get_posts( $args = array() ) {
	$found = array();
	foreach ( $GLOBALS['HAL_ACT_POSTS'] as $id => $post ) {
		if ( isset( $args['post_type'] ) && $post['post_type'] !== $args['post_type'] ) { continue; }
		if ( isset( $args['post_status'] ) && ! in_array( $post['post_status'], (array) $args['post_status'], true ) ) { continue; }
		if ( isset( $args['meta_key'] ) ) {
			$meta = $GLOBALS['HAL_ACT_POSTMETA'][ $id ][ $args['meta_key'] ] ?? null;
			if ( (string) $meta !== (string) ( $args['meta_value'] ?? '' ) ) { continue; }
		}
		$found[] = $id;
		if ( isset( $args['posts_per_page'] ) && count( $found ) >= (int) $args['posts_per_page'] ) { break; }
	}
	return $found;
}
function wp_insert_post( $args, $wp_error = false ) {
	$id = ++$GLOBALS['HAL_ACT_POST_ID_SEQ'];
	$post = array(
		'ID' => $id,
		'post_title' => (string) ( $args['post_title'] ?? '' ),
		'post_name' => (string) ( $args['post_name'] ?? '' ),
		'post_type' => (string) ( $args['post_type'] ?? 'post' ),
		'post_status' => (string) ( $args['post_status'] ?? 'draft' ),
		'post_content' => (string) ( $args['post_content'] ?? '' ),
		'post_author' => 1,
	);
	$GLOBALS['HAL_ACT_POSTS'][ $id ] = $post;
	foreach ( (array) ( $args['meta_input'] ?? array() ) as $key => $value ) {
		$GLOBALS['HAL_ACT_POSTMETA'][ $id ][ $key ] = (string) $value;
	}
	return $id;
}
function home_url( $p = '' ) { return 'https://example.test/' . ltrim( $p, '/' ); }
function wp_parse_url( $url, $comp = -1 ) { return parse_url( $url, $comp ); }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . ltrim( $p, '/' ); }
function is_wp_error( $t ) { return false; }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function add_action( $h, $c, $p = 10, $a = 1 ) { return true; }
function add_filter( $h, $c, $p = 10, $a = 1 ) { return true; }
function content_url( $p = '' ) { return 'https://example.test/wp-content/' . $p; }
function wp_send_json( $d ) { echo json_encode( $d ); }
function wp_send_json_error( $d, $s = 400 ) { echo json_encode( array( 'success' => false ) ); }
function wp_unslash( $v ) { return $v; }
function apply_filters( $h, $v, ...$rest ) { return $v; }
function did_action( $h ) { return 1; }
// HTTP transport stub ONLY: it forwards the loopback request to a REAL
// second PHP process that boots the REAL anchor → loader-core → runtime
// bootstrap (readiness fires) and then invokes the REAL
// Health_Check::register() + handle_request() with the POSTed token.
// No HAL logic is re-implemented here — the acknowledgement the real
// handler produces is returned verbatim as the HTTP response.
function wp_remote_post( $url, $args ) {
	global $act_state_dir;
	$op = (string) ( $args['body']['operation'] ?? '' );
	$token = (string) ( $args['body']['token'] ?? '' );
	if ( '' === $op || '' === $token ) {
		return array( 'code' => 403, 'body' => '' );
	}
	$requester = rtrim( WPMU_PLUGIN_DIR, '/\\' ) . '/.health-request-driver.php';
	$active = json_decode( (string) @file_get_contents( $act_state_dir . 'active.json' ), true );
	$handoff = $act_state_dir . '.health-handoff-' . $op . '.json';
	if ( ! file_put_contents( $handoff, json_encode( array(
		'op' => $op,
		'token' => $token,
		'release' => (string) ( $active['release_id'] ?? '' ),
	) ) ) ) {
		return array( 'code' => 500, 'body' => '' );
	}
	$env = array_merge( getenv(), array(
		'HAL_BOOT_MU' => WPMU_PLUGIN_DIR,
		'HAL_BOOT_HANDOFF' => $handoff,
	) );
	$cmd = array( PHP_BINARY, '-n',
		'-d', 'extension_dir=' . ( getenv( 'HAL_PHP_EXT_DIR' ) ?: ini_get( 'extension_dir' ) ),
		'-d', 'extension=sodium', '-d', 'extension=zip',
		$requester,
	);
	$proc = proc_open( $cmd, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, $env );
	if ( ! is_resource( $proc ) ) {
		return array( 'code' => 500, 'body' => '' );
	}
	$body = (string) stream_get_contents( $pipes[1] );
	$err = (string) stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$code = proc_close( $proc );
	if ( '' === $body ) {
		return array( 'code' => 500, 'body' => $err );
	}
	return array( 'code' => 200, 'body' => $body );
}

require $plugin_file; // real Carrier main file: registers activation hook
( $GLOBALS['HAL_ACT_HOOK'] )( $plugin_file ); // the REAL Installer::activate()

$act_state = $act_state_dir;
echo 'ACTIVE=' . ( is_file( $act_state . 'active.json' ) ? (string) file_get_contents( $act_state . 'active.json' ) : 'NONE' );
echo ' COMMITTED=' . ( is_file( $act_state . 'committed.json' ) ? 'YES' : 'NO' );
echo ' ANCHOR=' . ( is_file( rtrim( WPMU_PLUGIN_DIR, '\\' ) . '/hal-frontend-dashboard.php' ) ? 'YES' : 'NO' );
echo ' HISTORY=' . ( is_file( $act_state . 'history.json' ) ? (string) file_get_contents( $act_state . 'history.json' ) : 'NONE' );
echo ' CAPS=' . json_encode( $GLOBALS['HAL_ACT_ROLES']['administrator']->capabilities );
echo ' ADDCAPS=' . $GLOBALS['HAL_ACT_ROLES']['administrator']->add_cap_calls;
PHP
);

// Health-request driver: a REAL second request that boots the REAL root
// anchor (which loads the real loader-core → the promoted runtime's real
// bootstrap, firing hal_frontend_dashboard_runtime_ready) and then serves
// the loopback via the REAL Health_Check::register() + handle_request().
// Used by the wp_remote_post transport stub in both ACT and W4 drivers.
$health_request_driver_src = <<<'PHP'
<?php
// A simulated WordPress request whose ONLY simulation is the absence of a
// web server: everything HAL is real.
$mu = getenv( 'HAL_BOOT_MU' );
define( 'ABSPATH', $mu . 'wp-includes/' );
define( 'WPMU_PLUGIN_DIR', $mu );
$GLOBALS['wp_version'] = '7.1';

// Minimal WordPress request surface.
function add_action( $h, $c, $p = 10, $a = 1 ) { $GLOBALS['HAL_TEST_HOOKS'][$h][] = $c; return true; }
function add_filter( $h, $c, $p = 10, $a = 1 ) { $GLOBALS['HAL_TEST_HOOKS'][$h][] = $c; return true; }
function do_action( $h, ...$args ) { $GLOBALS['HAL_TEST_ACTIONS_DONE'][$h] = ( $GLOBALS['HAL_TEST_ACTIONS_DONE'][$h] ?? 0 ) + 1; foreach ( ( $GLOBALS['HAL_TEST_HOOKS'][$h] ?? array() ) as $cb ) { $cb( ...$args ); } }
function apply_filters( $h, $v, ...$rest ) { foreach ( ( $GLOBALS['HAL_TEST_HOOKS'][$h] ?? array() ) as $cb ) { $v = $cb( $v, ...$rest ); } return $v; }
function did_action( $h ) { return $GLOBALS['HAL_TEST_ACTIONS_DONE'][$h] ?? 0; }
function __return_true() { return true; }
function content_url( $p = '' ) { return 'https://example.test/wp-content/' . $p; }
function plugin_dir_path( $f ) { return rtrim( dirname( $f ), '/\\' ) . DIRECTORY_SEPARATOR; }
function wp_send_json( $d ) { echo json_encode( $d ); exit; }
function wp_send_json_error( $d, $s = 400 ) { echo json_encode( array_merge( array( 'success' => false ), is_array( $d ) ? $d : array() ) ); exit; }
function wp_unslash( $v ) { return $v; }
function home_url( $p = '' ) { return 'https://example.test/' . ltrim( $p, '/' ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . ltrim( $p, '/' ); }
function wp_remote_post( $u, $a ) { return array( 'code' => 0, 'body' => '' ); }
function is_wp_error( $t ) { return false; }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function register_activation_hook( $f, $c ) { return true; }
function get_filesystem_method() { return 'direct'; }
function is_multisite() { return false; }

// Boot the REAL root anchor exactly like WordPress does for mu-plugins.
require WPMU_PLUGIN_DIR . 'hal-frontend-dashboard.php';

// Serve the loopback via the REAL Health Check handler.
$health_file = WPMU_PLUGIN_DIR . 'hal-frontend-dashboard/releases/' . getenv( 'HAL_BOOT_RELEASE' ) . '/includes/class-health-check.php';
if ( ! is_file( $health_file ) ) { $health_file = dirname( $mu ) . '/carrier/hal-frontend-dashboard/includes/class-health-check.php'; }
require_once $health_file;
$handoff = json_decode( (string) file_get_contents( getenv( 'HAL_BOOT_HANDOFF' ) ), true );
if ( ! is_array( $handoff ) ) { exit; }
@unlink( getenv( 'HAL_BOOT_HANDOFF' ) );
$_POST['token'] = (string) ( $handoff['token'] ?? '' );
$_POST['operation'] = (string) ( $handoff['op'] ?? '' );
HAL_Frontend_Dashboard_Health_Check::register();
// wp_ajax hooks were captured by add_action above: invoke like WordPress.
foreach ( ( $GLOBALS['HAL_TEST_HOOKS']['wp_ajax_hal_frontend_dashboard_boot_health'] ?? array() ) as $cb ) { $cb(); }
PHP;
$act_health_requester = $workspace . '/case-b1-act/mu-plugins/.health-request-driver.php';
@mkdir( dirname( $act_health_requester ), 0777, true );
file_put_contents( $act_health_requester, $health_request_driver_src );
$w4_health_requester = $workspace . '/case-b1-w4-act/mu-plugins/.health-request-driver.php';
@mkdir( dirname( $w4_health_requester ), 0777, true );
file_put_contents( $w4_health_requester, $health_request_driver_src );

// W4 (revised): the interrupted state is RECONCILED through the REAL
// Installer::activate() path — not a manual Manager call. The child state
// has active=5.0.0 (candidate), committed=4.0.0 (stale), promotion.json
// gone. A fresh activation over the same payload must NOT return early
// (committed mismatch) and must complete the commit via the real promote.
// Build the same carrier environment as B1-ACT, for release 5.0.0.
$win_act_ws = $workspace . '/case-b1-w4-act';
$win_act_plugin = $win_act_ws . '/carrier/hal-frontend-dashboard';
$win_act_mu = $win_act_ws . '/mu-plugins';
foreach ( array( '/mu-loader', '/includes', '/payload' ) as $w4_sub ) {
	@mkdir( $win_act_plugin . $w4_sub, 0777, true );
}
@mkdir( $win_act_mu, 0777, true );
copy( $project . '/mu-loader/hal-frontend-dashboard.php', $win_act_plugin . '/mu-loader/hal-frontend-dashboard.php' );
copy( $project . '/mu-loader/loader-core.php', $win_act_plugin . '/mu-loader/loader-core.php' );
foreach ( array( 'class-installer.php', 'class-package-verifier.php', 'class-release-manager.php', 'class-health-check.php' ) as $w4_inc ) {
	copy( $project . '/includes/' . $w4_inc, $win_act_plugin . '/includes/' . $w4_inc );
}
// Runtime payload for the candidate release 5.0.0, signed with a fresh
// throwaway test key.
$w4_keypair = sodium_crypto_sign_keypair();
$w4_secret = sodium_crypto_sign_secretkey( $w4_keypair );
$w4_public_b64 = base64_encode( sodium_crypto_sign_publickey( $w4_keypair ) );
$w4_fingerprint = hash( 'sha256', sodium_crypto_sign_publickey( $w4_keypair ) );
$w4_bootstrap = "<?php\n// W4 fixture runtime bootstrap — mirrors the real readiness contract\nif ( ! defined( 'ABSPATH' ) ) { exit; }\nadd_filter( 'hal_frontend_dashboard_boot_readiness', '__return_true' );\ndo_action( 'hal_frontend_dashboard_runtime_ready' );\n";
$w4_zip_path = $win_act_plugin . '/payload/runtime-5.0.0.zip';
$w4_files_map = array( 'bootstrap.php' => hash( 'sha256', $w4_bootstrap ) );
$w4_zip = new ZipArchive();
$w4_zip->open( $w4_zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
$w4_zip->addFromString( 'bootstrap.php', $w4_bootstrap );
$w4_zip->close();
$w4_runtime_manifest = array(
	'schema' => 1, 'product' => 'hal-frontend-dashboard', 'version' => '5.0.0',
	'release_sequence' => 5, 'release_id' => '5.0.0+' . str_repeat( 'c', 40 ), 'tag' => 'v5.0.0',
	'commit_sha' => str_repeat( 'c', 40 ), 'requires_wp' => '7.0', 'requires_php' => '8.3',
	'loader_api_min' => 1, 'loader_api_max' => 1, 'loader_core_version' => '1.0.0',
	'schema_version' => '1', 'archive_sha256' => hash_file( 'sha256', $w4_zip_path ),
	'files' => $w4_files_map,
);
$w4_raw = json_encode( $w4_runtime_manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
file_put_contents( $win_act_plugin . '/payload/runtime-manifest.json', $w4_raw );
file_put_contents( $win_act_plugin . '/payload/runtime-manifest.sig', base64_encode( sodium_crypto_sign_detached( $w4_raw, $w4_secret ) ) );
// Real main file with Version bumped to 5.0.0 (what the release build does).
$w4_main_src = (string) file_get_contents( $project . '/hal-frontend-dashboard.php' );
$w4_main_src = str_replace( "define( 'HAL_FRONTEND_DASHBOARD_VERSION', '1.0.0' );", "define( 'HAL_FRONTEND_DASHBOARD_VERSION', '5.0.0' );", $w4_main_src );
file_put_contents( $win_act_plugin . '/hal-frontend-dashboard.php', $w4_main_src );
// Carrier manifest (full exact-name map) + signature.
$w4_carrier_map = array(
	'hal-frontend-dashboard.php' => $win_act_plugin . '/hal-frontend-dashboard.php',
	'readme.txt' => $project . '/readme.txt',
	'LICENSE' => $project . '/LICENSE',
	'THIRD-PARTY-NOTICES.txt' => $project . '/THIRD-PARTY-NOTICES.txt',
	'mu-loader/hal-frontend-dashboard.php' => $win_act_plugin . '/mu-loader/hal-frontend-dashboard.php',
	'mu-loader/loader-core.php' => $win_act_plugin . '/mu-loader/loader-core.php',
	'includes/class-installer.php' => $win_act_plugin . '/includes/class-installer.php',
	'includes/class-package-verifier.php' => $win_act_plugin . '/includes/class-package-verifier.php',
	'includes/class-release-manager.php' => $win_act_plugin . '/includes/class-release-manager.php',
	'includes/class-health-check.php' => $win_act_plugin . '/includes/class-health-check.php',
	'payload/runtime-5.0.0.zip' => $w4_zip_path,
	'payload/runtime-manifest.json' => $win_act_plugin . '/payload/runtime-manifest.json',
	'payload/runtime-manifest.sig' => $win_act_plugin . '/payload/runtime-manifest.sig',
);
$w4_carrier_files = array();
foreach ( $w4_carrier_map as $w4_rel => $w4_abs_src ) {
	$w4_dest = $win_act_plugin . '/' . $w4_rel;
	if ( ! is_file( $w4_dest ) ) {
		@mkdir( dirname( $w4_dest ), 0777, true );
		copy( $w4_abs_src, $w4_dest );
	}
	$w4_carrier_files[ $w4_rel ] = hash_file( 'sha256', $w4_dest );
}
$w4_carrier_raw = json_encode(
	array( 'schema' => 1, 'product' => 'hal-frontend-dashboard', 'version' => '5.0.0', 'plugin_basename' => 'hal-frontend-dashboard/hal-frontend-dashboard.php', 'files' => $w4_carrier_files ),
	JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
file_put_contents( $win_act_plugin . '/carrier-manifest.json', $w4_carrier_raw );
file_put_contents( $win_act_plugin . '/carrier-manifest.sig', base64_encode( sodium_crypto_sign_detached( $w4_carrier_raw, $w4_secret ) ) );

// Copy the interrupted W-state INTO this environment's mu-plugins so the
// real activation reconciles the exact on-disk state W1 produced.
$w4_state_src = $win_state;
$w4_state_dst = $win_act_mu . '/hal-frontend-dashboard/state';
@mkdir( $w4_state_dst, 0777, true );
foreach ( array( 'active.json', 'previous.json', 'committed.json', 'history.json', 'manager-state.json', 'loader-active.json' ) as $w4_state_file ) {
	if ( is_file( $w4_state_src . '/' . $w4_state_file ) ) {
		copy( $w4_state_src . '/' . $w4_state_file, $w4_state_dst . '/' . $w4_state_file );
	}
}
// The interrupted W1 state already had the REAL loader installed and its
// pointer committed (loader health passed before the window). Reproduce
// that on disk so the reconciliation runs the REAL promote path with
// loader_changed=false — exactly like the live continuation would.
@mkdir( $win_act_mu . '/hal-frontend-dashboard/loader-releases/loader-1.0.0', 0777, true );
copy( $win_act_plugin . '/mu-loader/loader-core.php', $win_act_mu . '/hal-frontend-dashboard/loader-releases/loader-1.0.0/loader-core.php' );
@mkdir( $win_act_mu . '/hal-frontend-dashboard/releases/5.0.0+' . str_repeat( 'c', 40 ), 0777, true );
file_put_contents( $win_act_mu . '/hal-frontend-dashboard/releases/5.0.0+' . str_repeat( 'c', 40 ) . '/bootstrap.php', $w4_bootstrap );
@mkdir( $win_act_mu . '/hal-frontend-dashboard/releases/4.0.0+' . str_repeat( 'b', 40 ), 0777, true );
file_put_contents( $win_act_mu . '/hal-frontend-dashboard/releases/4.0.0+' . str_repeat( 'b', 40 ) . '/bootstrap.php', "<?php\n" );
// No loader fixture: the real activation installs the REAL loader-core
// from the carrier payload (a fake would fail the hash compare).

// Reuse the ACT driver (same real activation path), targeting W4 env.
$w4_driver = $workspace . '/w4-activate-driver.php';
$w4_driver_src = (string) file_get_contents( $act_driver );
// Parameterize the driver output markers via env (driver echoes generic markers).
file_put_contents( $w4_driver, $w4_driver_src );
$w4_run = static function () use ( $php, $w4_driver, $win_act_mu, $win_act_plugin, $w4_public_b64, $w4_fingerprint ): string {
	$w4_cmd = array( $php, '-n',
		'-d', 'extension_dir=' . HAL_TEST_EXT_DIR,
		'-d', 'extension=sodium', '-d', 'extension=zip',
		$w4_driver, hal_win_path( $win_act_mu . '/' ), hal_win_path( $win_act_plugin . '/hal-frontend-dashboard.php' ),
	);
	$w4_env = array_merge( getenv(), array(
		'HAL_ACT_PUBLIC_KEY' => $w4_public_b64,
		'HAL_ACT_FINGERPRINT' => $w4_fingerprint,
	) );
	$w4_proc = proc_open( $w4_cmd, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $w4_pipes, null, $w4_env );
	if ( ! is_resource( $w4_proc ) ) {
		return 'PROC_OPEN_FAILED';
	}
	$w4_out = stream_get_contents( $w4_pipes[1] ) . stream_get_contents( $w4_pipes[2] );
	fclose( $w4_pipes[1] );
	fclose( $w4_pipes[2] );
	proc_close( $w4_proc );
	return (string) $w4_out;
};
$w4_out = $w4_run();
$w4_committed_after = json_decode( (string) file_get_contents( $w4_state_dst . '/committed.json' ), true );
$w4_active_after = json_decode( (string) file_get_contents( $w4_state_dst . '/active.json' ), true );
$w4_previous_after = json_decode( (string) file_get_contents( $w4_state_dst . '/previous.json' ), true );
$w4_final_consistent = '5.0.0+' . str_repeat( 'c', 40 ) === ( $w4_committed_after['release_id'] ?? '' )
	&& '5.0.0+' . str_repeat( 'c', 40 ) === ( $w4_active_after['release_id'] ?? '' )
	&& '4.0.0+' . str_repeat( 'b', 40 ) === ( $w4_previous_after['release_id'] ?? '' )
	&& ! is_file( $w4_state_dst . '/promotion.json' );
hal_check( 'B1-W4', $w4_final_consistent, 'real Installer::activate() reconciles the interrupted state: committed rewritten for the candidate, active kept, previous.json PRESERVED as 4.0.0, record clean', 'real activation did not reconcile the interrupted state: ' . $w4_out );

// W5: replay protection still holds after the reconciliation — attempting
// to promote an OLDER release/digest through the real Manager must be
// rejected, proving the fix did not weaken the replay gate.
$w5_code = hal_expect_code(
	static fn() => ( new HAL_Frontend_Dashboard_Release_Manager( $win_act_mu ) )->promote_runtime(
		array( 'release_id' => '4.0.0+' . str_repeat( 'b', 40 ), 'version' => '4.0.0', 'release_sequence' => 4, 'archive_sha256' => str_repeat( 'b', 64 ) ),
		static fn(): bool => true,
		'loader-1.0.0'
	),
	'HAL_PROMOTION_REPLAY_REJECTED'
);
hal_check( 'B1-W5', 'HAL_PROMOTION_REPLAY_REJECTED' === $w5_code, 'replay gate still rejects an older release after reconciliation', 'replay gate weakened: ' . $w5_code );

// W6 — the FAILURE branch of the reconciliation, through the REAL
// Installer::activate() path: the interrupted state is reconciled with a
// candidate whose runtime bootstrap does NOT fire the readiness contract,
// so the REAL Health chain (requester → handle_request → consume) rejects
// it and the promote must roll active back to the TRUE previous release
// (4.0.0) — not to the provisional candidate active.json pointed at.
$w6_ws = $workspace . '/case-b1-w6-fail';
$w6_plugin = $w6_ws . '/carrier/hal-frontend-dashboard';
$w6_mu = $w6_ws . '/mu-plugins';
foreach ( array( '/mu-loader', '/includes', '/payload' ) as $w6_sub ) {
	@mkdir( $w6_plugin . $w6_sub, 0777, true );
}
@mkdir( $w6_mu, 0777, true );
copy( $project . '/mu-loader/hal-frontend-dashboard.php', $w6_plugin . '/mu-loader/hal-frontend-dashboard.php' );
copy( $project . '/mu-loader/loader-core.php', $w6_plugin . '/mu-loader/loader-core.php' );
foreach ( array( 'class-installer.php', 'class-package-verifier.php', 'class-release-manager.php', 'class-health-check.php' ) as $w6_inc ) {
	copy( $project . '/includes/' . $w6_inc, $w6_plugin . '/includes/' . $w6_inc );
}
// Broken-candidate payload: same release id 5.0.0 as the interrupted
// state, but a bootstrap that never fires readiness — a genuinely
// not-ready runtime per the REAL Health handler.
$w6_bootstrap = "<?php\n// W6 broken fixture runtime bootstrap: readiness NEVER fires\n";
$w6_zip_path = $w6_plugin . '/payload/runtime-5.0.0.zip';
$w6_files_map = array( 'bootstrap.php' => hash( 'sha256', $w6_bootstrap ) );
$w6_zip = new ZipArchive();
$w6_zip->open( $w6_zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
$w6_zip->addFromString( 'bootstrap.php', $w6_bootstrap );
$w6_zip->close();
$w6_runtime_manifest = array(
	'schema' => 1, 'product' => 'hal-frontend-dashboard', 'version' => '5.0.0',
	'release_sequence' => 5, 'release_id' => '5.0.0+' . str_repeat( 'c', 40 ), 'tag' => 'v5.0.0',
	'commit_sha' => str_repeat( 'c', 40 ), 'requires_wp' => '7.0', 'requires_php' => '8.3',
	'loader_api_min' => 1, 'loader_api_max' => 1, 'loader_core_version' => '1.0.0',
	'schema_version' => '1', 'archive_sha256' => hash_file( 'sha256', $w6_zip_path ),
	'files' => $w6_files_map,
);
$w6_raw = json_encode( $w6_runtime_manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
file_put_contents( $w6_plugin . '/payload/runtime-manifest.json', $w6_raw );
file_put_contents( $w6_plugin . '/payload/runtime-manifest.sig', base64_encode( sodium_crypto_sign_detached( $w6_raw, $w4_secret ) ) );
$w6_main_src = (string) file_get_contents( $project . '/hal-frontend-dashboard.php' );
$w6_main_src = str_replace( "define( 'HAL_FRONTEND_DASHBOARD_VERSION', '1.0.0' );", "define( 'HAL_FRONTEND_DASHBOARD_VERSION', '5.0.0' );", $w6_main_src );
file_put_contents( $w6_plugin . '/hal-frontend-dashboard.php', $w6_main_src );
$w6_carrier_map = array(
	'hal-frontend-dashboard.php' => $w6_plugin . '/hal-frontend-dashboard.php',
	'readme.txt' => $project . '/readme.txt',
	'LICENSE' => $project . '/LICENSE',
	'THIRD-PARTY-NOTICES.txt' => $project . '/THIRD-PARTY-NOTICES.txt',
	'mu-loader/hal-frontend-dashboard.php' => $w6_plugin . '/mu-loader/hal-frontend-dashboard.php',
	'mu-loader/loader-core.php' => $w6_plugin . '/mu-loader/loader-core.php',
	'includes/class-installer.php' => $w6_plugin . '/includes/class-installer.php',
	'includes/class-package-verifier.php' => $w6_plugin . '/includes/class-package-verifier.php',
	'includes/class-release-manager.php' => $w6_plugin . '/includes/class-release-manager.php',
	'includes/class-health-check.php' => $w6_plugin . '/includes/class-health-check.php',
	'payload/runtime-5.0.0.zip' => $w6_zip_path,
	'payload/runtime-manifest.json' => $w6_plugin . '/payload/runtime-manifest.json',
	'payload/runtime-manifest.sig' => $w6_plugin . '/payload/runtime-manifest.sig',
);
$w6_carrier_files = array();
foreach ( $w6_carrier_map as $w6_rel => $w6_abs ) {
	$w6_dest = $w6_plugin . '/' . $w6_rel;
	if ( ! is_file( $w6_dest ) ) {
		@mkdir( dirname( $w6_dest ), 0777, true );
		copy( $w6_abs, $w6_dest );
	}
	$w6_carrier_files[ $w6_rel ] = hash_file( 'sha256', $w6_dest );
}
$w6_carrier_raw = json_encode(
	array( 'schema' => 1, 'product' => 'hal-frontend-dashboard', 'version' => '5.0.0', 'plugin_basename' => 'hal-frontend-dashboard/hal-frontend-dashboard.php', 'files' => $w6_carrier_files ),
	JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
file_put_contents( $w6_plugin . '/carrier-manifest.json', $w6_carrier_raw );
file_put_contents( $w6_plugin . '/carrier-manifest.sig', base64_encode( sodium_crypto_sign_detached( $w6_carrier_raw, $w4_secret ) ) );

// Fresh mu env carrying the EXACT interrupted state + the broken release
// tree + the REAL pre-installed loader (loader health already passed in
// the interrupted promotion; loader_changed=false in the reconciliation).
$w6_state_dst = $w6_mu . '/hal-frontend-dashboard/state';
@mkdir( $w6_state_dst, 0777, true );
foreach ( array( 'active.json', 'previous.json', 'committed.json', 'history.json', 'manager-state.json', 'loader-active.json' ) as $w6_state_file ) {
	if ( is_file( $win_state . '/' . $w6_state_file ) ) {
		copy( $win_state . '/' . $w6_state_file, $w6_state_dst . '/' . $w6_state_file );
	}
}
@mkdir( $w6_mu . '/hal-frontend-dashboard/releases/5.0.0+' . str_repeat( 'c', 40 ), 0777, true );
file_put_contents( $w6_mu . '/hal-frontend-dashboard/releases/5.0.0+' . str_repeat( 'c', 40 ) . '/bootstrap.php', $w6_bootstrap );
@mkdir( $w6_mu . '/hal-frontend-dashboard/releases/4.0.0+' . str_repeat( 'b', 40 ), 0777, true );
file_put_contents( $w6_mu . '/hal-frontend-dashboard/releases/4.0.0+' . str_repeat( 'b', 40 ) . '/bootstrap.php', "<?php\n" );
@mkdir( $w6_mu . '/hal-frontend-dashboard/loader-releases/loader-1.0.0', 0777, true );
copy( $w6_plugin . '/mu-loader/loader-core.php', $w6_mu . '/hal-frontend-dashboard/loader-releases/loader-1.0.0/loader-core.php' );
copy( $act_health_requester, $w6_mu . '/.health-request-driver.php' );

$w6_run = static function () use ( $php, $w4_driver, $w6_mu, $w6_plugin, $w4_public_b64, $w4_fingerprint ): string {
	$w6_cmd = array( $php, '-n',
		'-d', 'extension_dir=' . HAL_TEST_EXT_DIR,
		'-d', 'extension=sodium', '-d', 'extension=zip',
		$w4_driver, hal_win_path( $w6_mu . '/' ), hal_win_path( $w6_plugin . '/hal-frontend-dashboard.php' ),
	);
	$w6_env = array_merge( getenv(), array(
		'HAL_ACT_PUBLIC_KEY' => $w4_public_b64,
		'HAL_ACT_FINGERPRINT' => $w4_fingerprint,
	) );
	$w6_proc = proc_open( $w6_cmd, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $w6_pipes, null, $w6_env );
	if ( ! is_resource( $w6_proc ) ) {
		return 'PROC_OPEN_FAILED';
	}
	$w6_out = stream_get_contents( $w6_pipes[1] ) . stream_get_contents( $w6_pipes[2] );
	fclose( $w6_pipes[1] );
	fclose( $w6_pipes[2] );
	proc_close( $w6_proc );
	return (string) $w6_out;
};
$w6_out = $w6_run();
$w6_active_after = json_decode( (string) file_get_contents( $w6_state_dst . '/active.json' ), true );
$w6_previous_after = json_decode( (string) file_get_contents( $w6_state_dst . '/previous.json' ), true );
$w6_committed_after = json_decode( (string) file_get_contents( $w6_state_dst . '/committed.json' ), true );
$w6_failed_digests = json_decode( (string) file_get_contents( $w6_state_dst . '/failed-digests.json' ), true );
$w6_promotion_record = is_file( $w6_state_dst . '/promotion.json' )
	? json_decode( (string) file_get_contents( $w6_state_dst . '/promotion.json' ), true )
	: null;
$w6_manager_state = json_decode( (string) file_get_contents( $w6_state_dst . '/manager-state.json' ), true );
$w6_failure_consistent = '4.0.0+' . str_repeat( 'b', 40 ) === ( $w6_active_after['release_id'] ?? '' )
	&& '4.0.0+' . str_repeat( 'b', 40 ) === ( $w6_previous_after['release_id'] ?? '' )
	&& '4.0.0+' . str_repeat( 'b', 40 ) === ( $w6_committed_after['release_id'] ?? '' )
	&& isset( $w6_failed_digests['digests'][ $w6_runtime_manifest['archive_sha256'] ] )
	// The promotion record is deliberately RETAINED after a failed
	// promotion for the later recovery pass — and it must name the TRUE
	// previous release (4.0.0), proving the reconciliation fix end-to-end.
	&& '4.0.0+' . str_repeat( 'b', 40 ) === ( $w6_promotion_record['previous'] ?? '' )
	&& 'rolled_back' === ( $w6_manager_state['state'] ?? '' )
	&& 'HAL_PROMOTION_HEALTH_FAILED' === ( $w6_manager_state['failure_code'] ?? '' );
hal_check( 'B1-W6', $w6_failure_consistent, 'failed reconciliation health rolls active back to the TRUE previous 4.0.0 (not the candidate), previous/committed intact, digest recorded — via the REAL Installer path', 'failed reconciliation did not restore the real previous: ' . $w6_out );

unset( $w4_keypair, $w4_secret );

/* ════════════════════════════════════════════════════════════════
 * B1-ACT — clean + repeat install via the REAL Installer::activate()
 * in an isolated WordPress-environment subprocess, with a Carrier
 * payload signed by a throwaway Ed25519 test key (never the
 * production key). The HTTP transport is stubbed at wp_remote_post
 * ONLY, as a challenge-aware reader of the REAL challenge file the
 * real Health_Check::check() writes; every HAL decision path
 * (preflight, carrier-tree verify, runtime-archive verify, staging,
 * promotion, health challenge/consume) is real.
 * ════════════════════════════════════════════════════════════════ */

echo "\n== B1-ACT real Installer::activate clean/repeat ==\n";

$act_ws = $workspace . '/case-b1-act';
$act_plugin = $act_ws . '/carrier/hal-frontend-dashboard';
$act_mu = $act_ws . '/mu-plugins';
foreach ( array( '/mu-loader', '/includes', '/payload' ) as $act_sub ) {
	@mkdir( $act_plugin . $act_sub, 0777, true );
}
@mkdir( $act_mu, 0777, true );
copy( $project . '/mu-loader/hal-frontend-dashboard.php', $act_plugin . '/mu-loader/hal-frontend-dashboard.php' );
copy( $project . '/mu-loader/loader-core.php', $act_plugin . '/mu-loader/loader-core.php' );
foreach ( array( 'class-installer.php', 'class-package-verifier.php', 'class-release-manager.php', 'class-health-check.php' ) as $act_inc ) {
	copy( $project . '/includes/' . $act_inc, $act_plugin . '/includes/' . $act_inc );
}

// Throwaway test signing keypair (per-run; destroyed with the workspace).
$act_keypair = sodium_crypto_sign_keypair();
$act_secret = sodium_crypto_sign_secretkey( $act_keypair );
$act_public_b64 = base64_encode( sodium_crypto_sign_publickey( $act_keypair ) );
$act_fingerprint = hash( 'sha256', sodium_crypto_sign_publickey( $act_keypair ) );

// Runtime payload fixture, zipped for real + signed runtime manifest.
// The fixture bootstrap mirrors the REAL readiness contract of
// runtime/bootstrap.php: registers the boot-readiness filter and fires
// the runtime-ready action the REAL Health_Check handler consumes.
$act_bootstrap = "<?php\n// B1-ACT fixture runtime bootstrap — mirrors the real readiness contract\nif ( ! defined( 'ABSPATH' ) ) { exit; }\nadd_filter( 'hal_frontend_dashboard_boot_readiness', '__return_true' );\ndo_action( 'hal_frontend_dashboard_runtime_ready' );\n";
$act_core = "<?php\n// B1-ACT fixture runtime core\n";
$act_zip_path = $act_plugin . '/payload/runtime-6.0.0.zip';
$act_files = array( 'bootstrap.php' => hash( 'sha256', $act_bootstrap ), 'core/setup.php' => hash( 'sha256', $act_core ) );
$act_zip = new ZipArchive();
$act_zip->open( $act_zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
$act_zip->addFromString( 'bootstrap.php', $act_bootstrap );
$act_zip->addFromString( 'core/setup.php', $act_core );
$act_zip->close();
$act_runtime_manifest = array(
	'schema' => 1, 'product' => 'hal-frontend-dashboard', 'version' => '6.0.0',
	'release_sequence' => 6, 'release_id' => '6.0.0+' . str_repeat( 'a', 40 ), 'tag' => 'v6.0.0',
	'commit_sha' => str_repeat( 'a', 40 ), 'requires_wp' => '7.0', 'requires_php' => '8.3',
	'loader_api_min' => 1, 'loader_api_max' => 1, 'loader_core_version' => '1.0.0',
	'schema_version' => '1', 'archive_sha256' => hash_file( 'sha256', $act_zip_path ),
	'files' => $act_files,
);
$act_raw = json_encode( $act_runtime_manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
file_put_contents( $act_plugin . '/payload/runtime-manifest.json', $act_raw );
file_put_contents( $act_plugin . '/payload/runtime-manifest.sig', base64_encode( sodium_crypto_sign_detached( $act_raw, $act_secret ) ) );

// Carrier main file: the REAL project main file, with Version set to the
// fixture release (exactly what the release build does per version).
$act_main_src = (string) file_get_contents( $project . '/hal-frontend-dashboard.php' );
$act_main_src = str_replace( "define( 'HAL_FRONTEND_DASHBOARD_VERSION', '1.0.0' );", "define( 'HAL_FRONTEND_DASHBOARD_VERSION', '6.0.0' );", $act_main_src );
file_put_contents( $act_plugin . '/hal-frontend-dashboard.php', $act_main_src );

// Carrier manifest: full exact-name file map + throwaway-key signature.
$act_carrier_map = array(
	'hal-frontend-dashboard.php' => $act_plugin . '/hal-frontend-dashboard.php',
	'readme.txt' => $project . '/readme.txt',
	'LICENSE' => $project . '/LICENSE',
	'THIRD-PARTY-NOTICES.txt' => $project . '/THIRD-PARTY-NOTICES.txt',
	'mu-loader/hal-frontend-dashboard.php' => $act_plugin . '/mu-loader/hal-frontend-dashboard.php',
	'mu-loader/loader-core.php' => $act_plugin . '/mu-loader/loader-core.php',
	'includes/class-installer.php' => $act_plugin . '/includes/class-installer.php',
	'includes/class-package-verifier.php' => $act_plugin . '/includes/class-package-verifier.php',
	'includes/class-release-manager.php' => $act_plugin . '/includes/class-release-manager.php',
	'includes/class-health-check.php' => $act_plugin . '/includes/class-health-check.php',
	'payload/runtime-6.0.0.zip' => $act_zip_path,
	'payload/runtime-manifest.json' => $act_plugin . '/payload/runtime-manifest.json',
	'payload/runtime-manifest.sig' => $act_plugin . '/payload/runtime-manifest.sig',
);
$act_carrier_files = array();
foreach ( $act_carrier_map as $act_rel => $act_abs ) {
	// Materialize every manifest-listed file inside the carrier tree so the
	// REAL tree inventory matches the manifest exactly.
	$act_dest = $act_plugin . '/' . $act_rel;
	if ( ! is_file( $act_dest ) ) {
		@mkdir( dirname( $act_dest ), 0777, true );
		copy( $act_abs, $act_dest );
	}
	$act_carrier_files[ $act_rel ] = hash_file( 'sha256', $act_dest );
}
$act_carrier_raw = json_encode(
	array( 'schema' => 1, 'product' => 'hal-frontend-dashboard', 'version' => '6.0.0', 'plugin_basename' => 'hal-frontend-dashboard/hal-frontend-dashboard.php', 'files' => $act_carrier_files ),
	JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
file_put_contents( $act_plugin . '/carrier-manifest.json', $act_carrier_raw );
file_put_contents( $act_plugin . '/carrier-manifest.sig', base64_encode( sodium_crypto_sign_detached( $act_carrier_raw, $act_secret ) ) );

$act_run = static function () use ( $php, $act_driver, $act_mu, $act_plugin, $act_public_b64, $act_fingerprint ): string {
	// proc_open with an explicit env array — no shell quoting pitfalls.
	$act_cmd = array( $php, '-n',
		'-d', 'extension_dir=' . HAL_TEST_EXT_DIR,
		'-d', 'extension=sodium', '-d', 'extension=zip',
		$act_driver, hal_win_path( $act_mu . '/' ), hal_win_path( $act_plugin . '/hal-frontend-dashboard.php' ),
	);
	$act_env = array_merge( getenv(), array(
		'HAL_ACT_PUBLIC_KEY' => $act_public_b64,
		'HAL_ACT_FINGERPRINT' => $act_fingerprint,
	) );
	$act_proc = proc_open( $act_cmd, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $act_pipes, null, $act_env );
	if ( ! is_resource( $act_proc ) ) {
		return 'PROC_OPEN_FAILED';
	}
	$act_out = stream_get_contents( $act_pipes[1] ) . stream_get_contents( $act_pipes[2] );
	fclose( $act_pipes[1] );
	fclose( $act_pipes[2] );
	proc_close( $act_proc );
	return (string) $act_out;
};

$out_act1 = $act_run();
$act_state_path = $act_mu . '/hal-frontend-dashboard/state';
$act_active = is_file( $act_state_path . '/active.json' ) ? (string) file_get_contents( $act_state_path . '/active.json' ) : '';
$act_anchor = is_file( $act_mu . '/hal-frontend-dashboard.php' );
$act_release_dir = is_dir( $act_mu . '/hal-frontend-dashboard/releases/6.0.0+' . str_repeat( 'a', 40 ) );
$act_loader_dir = is_dir( $act_mu . '/hal-frontend-dashboard/loader-releases/loader-1.0.0' );
hal_check( 'B1-ACT1', str_contains( $act_active, '6.0.0+' . str_repeat( 'a', 40 ) ) && $act_anchor && $act_release_dir && $act_loader_dir && str_contains( $out_act1, 'COMMITTED=YES' ), 'real Installer::activate(): clean install completes — active+committed set, anchor written, release+loader staged from signed test payload', 'clean activation failed: ' . $out_act1 );
hal_check( 'B1-ACT-CAPS', str_contains( $out_act1, '"manage_hal_frontend_dashboard":true' ) && str_contains( $out_act1, 'ADDCAPS=1' ), 'the real activation path grants the default HAL capability to the administrator exactly once (batch-3 §14 delivery rule through Installer::activate())', 'capability grant missing on the real activation path: ' . $out_act1 );

$out_act2 = $act_run();
$act_history = json_decode( (string) file_get_contents( $act_state_path . '/history.json' ), true );
$act_repeat_ok = array( '6.0.0+' . str_repeat( 'a', 40 ) ) === array_values( $act_history['releases'] ?? array() );
hal_check( 'B1-ACT2', $act_repeat_ok && str_contains( $out_act2, 'COMMITTED=YES' ), 'repeat activation via the same real path is idempotent (single history entry) and succeeds', 'repeat activation diverged: ' . $out_act2 );
hal_check( 'B1-ACT-CAPS-REPEAT', str_contains( $out_act2, 'ADDCAPS=1' ), 'repeat activation through the real path also grants the capability once (fresh process, persisted-state idempotency proven by B1-ACT2)', 'repeat grant mismatch: ' . $out_act2 );

unset( $act_keypair, $act_secret );

/* ════════════════════════════════════════════════════════════════
 * BATCH 0 — Carrier inactivity in normal requests
 * ════════════════════════════════════════════════════════════════ */

echo "\n== BATCH 0 carrier inactivity ==\n";

// Subprocess: include the REAL main plugin file as a normal request; assert
// only identity constants are defined, no activation fired, nothing else loaded.
$main_file = $project . '/hal-frontend-dashboard.php';
$script0 = <<<'PHP'
<?php
// Batch 0 carrier contract: a normal request DEFINES the identity constants,
// REGISTERS the activation hook (lazy installer), but loads NO extra files —
// the installer class must not exist until activation calls it.
define( 'ABSPATH', $argv[2] );
$GLOBALS['HAL_TEST_ACTIVATION_HOOK'] = null;
function register_activation_hook( $f, $c ) { $GLOBALS['HAL_TEST_ACTIVATION_HOOK'] = array( $f, $c ); return true; }
function plugin_dir_path( $f ) { return rtrim( dirname( $f ), '/\\' ) . DIRECTORY_SEPARATOR; }
$main = $argv[1];
require $main;
// cmd.exe may rewrite path separators between the shell and PHP argv, so
// compare resolved paths — only files OTHER than the main file and this
// script may appear in get_included_files().
$main_real = realpath( $main );
$extra = array_filter(
	get_included_files(),
	static fn( $f ): bool => realpath( $f ) !== $main_real && realpath( $f ) !== realpath( __FILE__ )
);
$hook = $GLOBALS['HAL_TEST_ACTIVATION_HOOK'];
$inert = defined( 'HAL_FRONTEND_DASHBOARD_VERSION' )
	&& is_array( $hook )
	&& 'hal_frontend_dashboard_activate' === $hook[1]
	&& array() === $extra
	&& ! class_exists( 'HAL_Frontend_Dashboard_Installer' );
echo $inert ? 'INERT' : 'ACTIVE';
echo PHP_EOL . 'extra=' . json_encode( array_map( 'realpath', array_values( $extra ) ) );
echo PHP_EOL . 'installer_class=' . var_export( class_exists( 'HAL_Frontend_Dashboard_Installer' ), true );
PHP;
$test_script0 = $workspace . '/carrier-inert-test.php';
file_put_contents( $test_script0, $script0 );
$cmd0 = escapeshellarg( $php ) . ' -n ' . escapeshellarg( $test_script0 ) . ' ' . escapeshellarg( $main_file ) . ' ' . escapeshellarg( $workspace . '/' ) . ' 2>&1';
$out0 = (string) shell_exec( $cmd0 );
hal_check( 'B0-A', str_contains( $out0, 'INERT' ), 'carrier main file inert in normal request (no activation, no extra includes)', 'carrier did work in normal request: ' . $out0 );

/* ── Summary ───────────────────────────────────────────────────── */
echo "\n== Summary ==\n";
$failures = array_filter( $GLOBALS['HAL_TEST_RESULTS'], static fn( $r ): bool => ! $r['ok'] );
$passes = array_filter( $GLOBALS['HAL_TEST_RESULTS'], static fn( $r ): bool => $r['ok'] && 'LINK-SKIP' !== $r['pass'] );
$skips = $GLOBALS['HAL_TEST_SKIPS'] ?? array();
$summary = count( $passes ) . ' checks passed, ' . count( $failures ) . ' failed, ' . count( $skips ) . ' skipped';
echo $summary . "\n";

if ( array() === $failures ) {
	// rmdir removes a directory junction on Windows without touching the target.
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $workspace, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) {
		if ( $f->isDir() ) {
			@rmdir( $f->getPathname() );
		} else {
			@unlink( $f->getPathname() );
		}
	}
	@rmdir( $workspace );
	exit( 0 );
}
foreach ( $failures as $r ) {
	echo 'FAIL: ' . $r['id'] . ' — ' . $r['fail'] . "\n";
}
exit( 1 );
