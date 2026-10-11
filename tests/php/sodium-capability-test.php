<?php
/**
 * HAL Frontend Dashboard — v1.0.4 sodium capability gates.
 *
 * Local, isolated harness: no live WordPress, no network, no database.
 * Drives the REAL v1.0.4 surfaces (installer preflight, package verifier,
 * activation wrapper) through subprocesses whose extension state is proven
 * per child, never assumed.
 *
 *   (A) WITHOUT sodium (`-n`, absence proven via the hal_php_argv child
 *       probe): the real preflight refuses (single literal code, or the
 *       HAL_PREFLIGHT_MULTIPLE: composed code naming every failed check)
 *       and the real verifier refuses with HAL_SIGNATURE_CAPABILITY_MISSING.
 *       Mandatory where absence is producible; recorded UNAVAILABLE (not a
 *       PASS) when the runner builds sodium statically.
 *   (B) WITH sodium: both gates accept; a valid signature is accepted and a
 *       tampered one is rejected. Mandatory.
 *   (W) The real activation wrapper (item 4): no Throwable escapes raw —
 *       HAL_ codes reach wp_die, installer-load failures surface as
 *       HAL_INSTALLER_UNAVAILABLE / HAL_INSTALLER_INVALID, and any other
 *       Throwable collapses to HAL_UNEXPECTED_ERROR with the class name
 *       only (message and paths suppressed).
 *   (C) OPTIONAL external sodium_compat path (--wp-sodium-compat=<dir>):
 *       loads the WordPress-bundled polyfill library ONLY (read in place,
 *       never copied) without the sodium extension, accepts through the
 *       real verifier, and measures G5: the sum of the three Ed25519
 *       detached verifications per activation. Above 2000ms locally the
 *       in-request verification memo decision would be triggered; the
 *       measurement is a lower bound for shared hosting. Missing path →
 *       SKIP (skip ≠ PASS).
 *
 * Fixture signing uses EPHEMERAL Ed25519 keypairs generated at runtime —
 * never the production release key, never any private key in the repo.
 *
 * Usage:  php -d extension=sodium tests/php/sodium-capability-test.php
 *         [--wp-sodium-compat=<dir of sodium_compat>]
 *         exit 0 = all mandatory checks pass.
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

if ( PHP_SAPI !== 'cli' ) {
	echo "This harness must run under CLI PHP.\n";
	exit( 1 );
}

if ( ! extension_loaded( 'sodium' ) || ! class_exists( 'ZipArchive' ) ) {
	echo "Run with the extensions the suite gate expects: php -d extension=sodium tests/php/sodium-capability-test.php\n";
	exit( 1 );
}

$project = dirname( __DIR__, 2 );
$run_dir = $project . '/.local-execution/v1.0.4-exec-20261011';
$ws = $run_dir . '/sodium-cap-' . getmypid();
@mkdir( $ws, 0777, true );

require_once __DIR__ . '/lib-hal-php-flags.php'; // test-launcher flag filter (no product code, no assertions)

$GLOBALS['HAL_CAP_RESULTS'] = array();

function hal_cap_check( string $id, bool $condition, string $pass, string $fail = '' ): void {
	$GLOBALS['HAL_CAP_RESULTS'][] = array( 'id' => $id, 'ok' => $condition );
	echo ( $condition ? 'PASS' : 'FAIL' ) . " [$id] " . ( $condition ? $pass : $fail ) . "\n";
}

function hal_cap_run( array $cmd, array $env = array() ): string {
	$descriptor = array(
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);
	$proc = proc_open( $cmd, $descriptor, $pipes, null, array_merge( getenv(), $env ) );
	if ( ! is_resource( $proc ) ) {
		return 'PROC_OPEN_FAILED';
	}
	$out = (string) stream_get_contents( $pipes[1] ) . "\n" . (string) stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	proc_close( $proc );
	return $out;
}

/* ── Extension-state probe: absence is proven per child, never assumed ── */

$php = PHP_BINARY;
$ext_dir = getenv( 'HAL_PHP_EXT_DIR' ) ?: ( is_dir( dirname( $php ) . '/ext' ) ? dirname( $php ) . '/ext' : (string) ini_get( 'extension_dir' ) );
$plain = array( '-n', '-d', 'extension_dir=' . $ext_dir );

// hal_php_argv appends `-d extension=<name>` exactly when its probe child
// (same base flags, no extension flags) lacks the extension — so a flag in
// the returned argv IS the proof that the base child lacks the extension.
$absent_sodium = in_array( 'extension=sodium', hal_php_argv( $php, $plain, array( 'sodium' ) ), true );
$absent_zip = in_array( 'extension=zip', hal_php_argv( $php, $plain, array( 'zip' ) ), true );
echo "PROBE: sodium absent under -n: " . ( $absent_sodium ? 'YES' : 'NO' ) . '; zip absent under -n: ' . ( $absent_zip ? 'YES' : 'NO' ) . "\n";

/* ── Ephemeral fixture signing key (never the release key) ── */

$keypair = sodium_crypto_sign_keypair();
$cap_env = array(
	'HAL_CAP_PUBLIC_KEY' => base64_encode( sodium_crypto_sign_publickey( $keypair ) ),
	'HAL_CAP_FINGERPRINT' => hash( 'sha256', sodium_crypto_sign_publickey( $keypair ) ),
);

/* ── Child scripts (standalone; no harness require) ── */

$child_stub_head = <<<'PHP'
<?php
error_reporting( E_ALL );
ini_set( 'display_errors', '1' );
$ws = $argv[1];
$project = $argv[2];
define( 'ABSPATH', $ws . '/wp/' );
@mkdir( ABSPATH, 0777, true );
$GLOBALS['wp_version'] = '7.1';
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( string $url, int $component = -1 ) { return parse_url( $url, $component ); } }
if ( ! function_exists( 'home_url' ) ) { function home_url( string $path = '' ): string { return 'https://example.test/' . ltrim( $path, '/' ); } }
if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) { define( 'WPMU_PLUGIN_DIR', $ws . '/mu/' ); }
@mkdir( WPMU_PLUGIN_DIR, 0777, true );
PHP;

$child_preflight = $child_stub_head . <<<'PHP'

require_once $project . '/includes/class-installer.php';
try {
	$ref = new ReflectionMethod( 'HAL_Frontend_Dashboard_Installer', 'preflight' );
	$ref->setAccessible( true );
	$ref->invoke( null );
	echo 'PREFLIGHT-PASS';
} catch ( RuntimeException $e ) {
	echo 'PREFLIGHT-THROW:' . $e->getMessage();
}
PHP;

$child_verifier = $child_stub_head . <<<'PHP'

require_once $project . '/includes/class-package-verifier.php';
try {
	new HAL_Frontend_Dashboard_Package_Verifier(
		getenv( 'HAL_CAP_PUBLIC_KEY' ) ?: null,
		getenv( 'HAL_CAP_FINGERPRINT' ) ?: null
	);
	echo 'VERIFIER-CONSTRUCTED';
} catch ( RuntimeException $e ) {
	echo 'VERIFIER-THROW:' . $e->getMessage();
}
PHP;

// (B) verifier accept/reject through the REAL public tree path (no zip
// needed: verify_carrier_tree hashes a real directory tree). The fixture
// satisfies the carrier completeness gate (exact required files +
// non-empty includes/ + one payload archive).
$child_accept = $child_stub_head . <<<'PHP'

$keypair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey( $keypair );
$public = base64_encode( sodium_crypto_sign_publickey( $keypair ) );
$fingerprint = hash( 'sha256', sodium_crypto_sign_publickey( $keypair ) );
$carrier = $ws . '/carrier-accept';
@mkdir( $carrier . '/includes', 0777, true );
@mkdir( $carrier . '/mu-loader', 0777, true );
@mkdir( $carrier . '/payload', 0777, true );
file_put_contents( $carrier . '/hal-frontend-dashboard.php', "<?php\ndefined( 'ABSPATH' ) || exit;\n// fixture main\n" );
file_put_contents( $carrier . '/readme.txt', "=== HAL Frontend Dashboard ===\nStable tag: 9.9.9\n" );
file_put_contents( $carrier . '/LICENSE', "GPL-2.0-or-later fixture\n" );
file_put_contents( $carrier . '/THIRD-PARTY-NOTICES.txt', "fixture notices\n" );
file_put_contents( $carrier . '/includes/hal-fixture.php', "<?php\ndefined( 'ABSPATH' ) || exit;\n" );
file_put_contents( $carrier . '/mu-loader/hal-frontend-dashboard.php', "<?php\n// fixture anchor\n" );
file_put_contents( $carrier . '/mu-loader/loader-core.php', "<?php\n// fixture loader\n" );
file_put_contents( $carrier . '/payload/runtime-9.9.9.zip', "PK-FACT-BYTES" );
file_put_contents( $carrier . '/payload/runtime-manifest.json', "{\n}\n" );
file_put_contents( $carrier . '/payload/runtime-manifest.sig', "fixture" );
$manifest = array(
	'schema' => 1, 'product' => 'hal-frontend-dashboard', 'version' => '9.9.9',
	'plugin_basename' => 'hal-frontend-dashboard/hal-frontend-dashboard.php',
	'files' => array(
		'hal-frontend-dashboard.php' => hash_file( 'sha256', $carrier . '/hal-frontend-dashboard.php' ),
		'readme.txt' => hash_file( 'sha256', $carrier . '/readme.txt' ),
		'LICENSE' => hash_file( 'sha256', $carrier . '/LICENSE' ),
		'THIRD-PARTY-NOTICES.txt' => hash_file( 'sha256', $carrier . '/THIRD-PARTY-NOTICES.txt' ),
		'includes/hal-fixture.php' => hash_file( 'sha256', $carrier . '/includes/hal-fixture.php' ),
		'mu-loader/hal-frontend-dashboard.php' => hash_file( 'sha256', $carrier . '/mu-loader/hal-frontend-dashboard.php' ),
		'mu-loader/loader-core.php' => hash_file( 'sha256', $carrier . '/mu-loader/loader-core.php' ),
		'payload/runtime-9.9.9.zip' => hash_file( 'sha256', $carrier . '/payload/runtime-9.9.9.zip' ),
		'payload/runtime-manifest.json' => hash_file( 'sha256', $carrier . '/payload/runtime-manifest.json' ),
		'payload/runtime-manifest.sig' => hash_file( 'sha256', $carrier . '/payload/runtime-manifest.sig' ),
	),
);
$raw = json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
$sig = base64_encode( sodium_crypto_sign_detached( $raw, $secret ) );
file_put_contents( $carrier . '/carrier-manifest.json', $raw );
file_put_contents( $carrier . '/carrier-manifest.sig', $sig );
require_once $project . '/includes/class-package-verifier.php';
try {
	$verifier = new HAL_Frontend_Dashboard_Package_Verifier( $public, $fingerprint );
	$verifier->verify_carrier_tree( $carrier, $carrier . '/carrier-manifest.json', $carrier . '/carrier-manifest.sig' );
	echo 'VERIFIER-ACCEPT';
} catch ( RuntimeException $e ) {
	echo 'VERIFIER-REJECT:' . $e->getMessage();
	exit;
}
file_put_contents( $carrier . '/readme.txt', "=== HAL Frontend Dashboard ===\nStable tag: 9.9.10\n" );
try {
	$verifier->verify_carrier_tree( $carrier, $carrier . '/carrier-manifest.json', $carrier . '/carrier-manifest.sig' );
	echo ' TAMPERED-TREE-ACCEPTED';
} catch ( RuntimeException $e ) {
	echo ' TAMPERED-TREE-REJECT:' . $e->getMessage();
}
$foreign = sodium_crypto_sign_keypair();
file_put_contents( $carrier . '/readme.txt', "=== HAL Frontend Dashboard ===\nStable tag: 9.9.9\n" );
file_put_contents( $carrier . '/carrier-manifest.sig', base64_encode( sodium_crypto_sign_detached( $raw, sodium_crypto_sign_secretkey( $foreign ) ) ) );
try {
	$verifier->verify_carrier_tree( $carrier, $carrier . '/carrier-manifest.json', $carrier . '/carrier-manifest.sig' );
	echo ' TAMPERED-SIG-ACCEPTED';
} catch ( RuntimeException $e ) {
	echo ' TAMPERED-SIG-REJECT:' . $e->getMessage();
}
PHP;

// (C) sodium_compat-only child: polyfill loaded from the external path
// (passed as argv, never written into any file), then the REAL verifier
// accepts/rejects and G5 measures three Ed25519 detached verifications.
$child_compat = $child_stub_head . <<<'PHP'

require $argv[3] . '/autoload.php';
$ready = function_exists( 'sodium_crypto_sign_verify_detached' )
	&& defined( 'SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES' ) && defined( 'SODIUM_CRYPTO_SIGN_BYTES' );
echo 'COMPAT-READY=' . ( $ready ? 'Y' : 'N' ) . "\n";
if ( ! $ready ) { exit; }
$keypair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey( $keypair );
$public = base64_encode( sodium_crypto_sign_publickey( $keypair ) );
$fingerprint = hash( 'sha256', sodium_crypto_sign_publickey( $keypair ) );
$carrier = $ws . '/carrier-compat';
@mkdir( $carrier . '/includes', 0777, true );
@mkdir( $carrier . '/mu-loader', 0777, true );
@mkdir( $carrier . '/payload', 0777, true );
file_put_contents( $carrier . '/hal-frontend-dashboard.php', "<?php\ndefined( 'ABSPATH' ) || exit;\n// fixture main\n" );
file_put_contents( $carrier . '/readme.txt', "=== HAL Frontend Dashboard ===\nStable tag: 9.9.9\n" );
file_put_contents( $carrier . '/LICENSE', "GPL-2.0-or-later fixture\n" );
file_put_contents( $carrier . '/THIRD-PARTY-NOTICES.txt', "fixture notices\n" );
file_put_contents( $carrier . '/includes/hal-fixture.php', "<?php\ndefined( 'ABSPATH' ) || exit;\n" );
file_put_contents( $carrier . '/mu-loader/hal-frontend-dashboard.php', "<?php\n// fixture anchor\n" );
file_put_contents( $carrier . '/mu-loader/loader-core.php', "<?php\n// fixture loader\n" );
file_put_contents( $carrier . '/payload/runtime-9.9.9.zip', "PK-FACT-BYTES" );
file_put_contents( $carrier . '/payload/runtime-manifest.json', "{\n}\n" );
file_put_contents( $carrier . '/payload/runtime-manifest.sig', "fixture" );
$manifest = array(
	'schema' => 1, 'product' => 'hal-frontend-dashboard', 'version' => '9.9.9',
	'plugin_basename' => 'hal-frontend-dashboard/hal-frontend-dashboard.php',
	'files' => array(
		'hal-frontend-dashboard.php' => hash_file( 'sha256', $carrier . '/hal-frontend-dashboard.php' ),
		'readme.txt' => hash_file( 'sha256', $carrier . '/readme.txt' ),
		'LICENSE' => hash_file( 'sha256', $carrier . '/LICENSE' ),
		'THIRD-PARTY-NOTICES.txt' => hash_file( 'sha256', $carrier . '/THIRD-PARTY-NOTICES.txt' ),
		'includes/hal-fixture.php' => hash_file( 'sha256', $carrier . '/includes/hal-fixture.php' ),
		'mu-loader/hal-frontend-dashboard.php' => hash_file( 'sha256', $carrier . '/mu-loader/hal-frontend-dashboard.php' ),
		'mu-loader/loader-core.php' => hash_file( 'sha256', $carrier . '/mu-loader/loader-core.php' ),
		'payload/runtime-9.9.9.zip' => hash_file( 'sha256', $carrier . '/payload/runtime-9.9.9.zip' ),
		'payload/runtime-manifest.json' => hash_file( 'sha256', $carrier . '/payload/runtime-manifest.json' ),
		'payload/runtime-manifest.sig' => hash_file( 'sha256', $carrier . '/payload/runtime-manifest.sig' ),
	),
);
$raw = json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
$sig = base64_encode( sodium_crypto_sign_detached( $raw, $secret ) );
file_put_contents( $carrier . '/carrier-manifest.json', $raw );
file_put_contents( $carrier . '/carrier-manifest.sig', $sig );
require_once $project . '/includes/class-package-verifier.php';
try {
	$verifier = new HAL_Frontend_Dashboard_Package_Verifier( $public, $fingerprint );
	$verifier->verify_carrier_tree( $carrier, $carrier . '/carrier-manifest.json', $carrier . '/carrier-manifest.sig' );
	echo 'COMPAT-ACCEPT' . "\n";
} catch ( RuntimeException $e ) {
	echo 'COMPAT-REJECT:' . $e->getMessage() . "\n";
	exit;
}
$sig_bin = base64_decode( $sig, true );
$t0 = hrtime( true );
for ( $i = 0; $i < 3; $i++ ) {
	sodium_crypto_sign_verify_detached( $sig_bin, $raw, base64_decode( $public, true ) );
}
echo 'G5_MS=' . number_format( ( hrtime( true ) - $t0 ) / 1e6, 2, '.', '' ) . "\n";
PHP;

// (W) the REAL activation wrapper through a fixture plugin whose main file
// is the real project main file; the includes/ directory content decides
// which wrapper branch runs. No wp_die in this child: the stub records it.
$child_wrapper = <<<'PHP'
<?php
error_reporting( E_ALL );
ini_set( 'display_errors', '1' );
$ws = $argv[1];
$project = $argv[2];
$mode = $argv[3];
$plugin_dir = $argv[4];
define( 'ABSPATH', $ws . '/wp/' );
@mkdir( ABSPATH, 0777, true );
$GLOBALS['wp_version'] = '7.1';
function wp_die( $message = '', $title = '', $args = array() ) { echo 'WPDIE:' . $message; exit; }
function register_activation_hook( $file, $callback ) { return true; }
function plugin_dir_path( $file ) { return rtrim( dirname( $file ), '/\\' ) . DIRECTORY_SEPARATOR; }
function wp_parse_url( string $url, int $component = -1 ) { return parse_url( $url, $component ); }
function home_url( string $path = '' ): string { return 'https://example.test/' . ltrim( $path, '/' ); }
if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) { define( 'WPMU_PLUGIN_DIR', $ws . '/mu/' ); }
@mkdir( WPMU_PLUGIN_DIR, 0777, true );
require $plugin_dir . 'hal-frontend-dashboard.php';
hal_frontend_dashboard_activate();
PHP;

function hal_cap_write( string $path, string $content ): void {
	if ( false === file_put_contents( $path, $content ) ) {
		echo "FAIL [harness] cannot write {$path}\n";
		exit( 1 );
	}
}

$child_preflight_file = $ws . '/child-preflight.php';
$child_verifier_file = $ws . '/child-verifier.php';
$child_accept_file = $ws . '/child-accept.php';
$child_compat_file = $ws . '/child-compat.php';
$child_wrapper_file = $ws . '/child-wrapper.php';
hal_cap_write( $child_preflight_file, $child_preflight );
hal_cap_write( $child_verifier_file, $child_verifier );
hal_cap_write( $child_accept_file, $child_accept );
hal_cap_write( $child_compat_file, $child_compat );
hal_cap_write( $child_wrapper_file, $child_wrapper );

$failures = static function (): array {
	return array_filter( $GLOBALS['HAL_CAP_RESULTS'], static fn( $r ): bool => ! $r['ok'] );
};

/* ════════════════════════════════════════════════════════════════
 * (B) WITH sodium — mandatory: both gates accept; valid accepted,
 * tampered rejected.
 * ════════════════════════════════════════════════════════════════ */

echo "\n== (B) sodium loaded ==\n";

$base_zip = array_merge( $plain, array( '-d', 'extension=zip' ) );
$present_flags = hal_php_argv( $php, $base_zip, array( 'sodium', 'zip' ) );

$out = hal_cap_run( array_merge( $present_flags, array( $child_preflight_file, $ws, $project ) ) );
hal_cap_check(
	'CAP-B1-PREFLIGHT-ACCEPTS',
	str_contains( $out, 'PREFLIGHT-PASS' ),
	'real preflight passes the unified capacity checks with sodium+zip+zlib available',
	'unexpected: ' . $out
);

$out = hal_cap_run( array_merge( $present_flags, array( $child_accept_file, $ws, $project ) ), $cap_env );
hal_cap_check(
	'CAP-B2-VERIFIER-ACCEPTS',
	str_contains( $out, 'VERIFIER-ACCEPT' )
		&& str_contains( $out, 'TAMPERED-TREE-REJECT:HAL_PACKAGE_TREE_HASH_MISMATCH' )
		&& str_contains( $out, 'TAMPERED-SIG-REJECT:HAL_SIGNATURE_VERIFICATION_FAILED' ),
	'real verifier accepts a valid signed carrier tree, rejects tampered bytes and a foreign-key signature',
	'unexpected: ' . $out
);

/* ════════════════════════════════════════════════════════════════
 * (A) WITHOUT sodium — mandatory where absence is producible.
 * ════════════════════════════════════════════════════════════════ */

echo "\n== (A) sodium absent (-n) ==\n";

if ( ! $absent_sodium ) {
	echo "UNAVAILABLE: this runtime builds sodium statically; absence is not producible (skip ≠ PASS; mandatory locally on Windows/G1).\n";
} else {
	$out = hal_cap_run( array_merge( array( $php ), $plain, array( $child_preflight_file, $ws, $project ) ) );
	$composed = str_starts_with( $out, 'PREFLIGHT-THROW:HAL_PREFLIGHT_MULTIPLE:' )
		&& str_contains( $out, 'HAL_PREFLIGHT_SODIUM_UNAVAILABLE' )
		&& ( ! $absent_zip || str_contains( $out, 'HAL_PREFLIGHT_ZIP_MISSING' ) );
	hal_cap_check(
		'CAP-A1-PREFLIGHT-COMPOSED',
		$composed,
		'absent-sodium preflight refuses with the composed code naming every failed capacity check',
		'unexpected: ' . $out
	);

	if ( $absent_zip ) {
		$single_cmd = array_merge( array( $php ), $plain, array( '-d', 'extension=zip' ), array( $child_preflight_file, $ws, $project ) );
		$out = hal_cap_run( $single_cmd );
		hal_cap_check(
			'CAP-A2-PREFLIGHT-SINGLE',
			'PREFLIGHT-THROW:HAL_PREFLIGHT_SODIUM_UNAVAILABLE' === trim( $out ),
			'with zip forced, the single sodium failure keeps its literal code (exact-match contract)',
			'unexpected: ' . $out
		);
	} else {
		echo "UNAVAILABLE: zip is built into this runtime, the single-failure case is covered by CAP-A1's composed report.\n";
	}

	$out = hal_cap_run( array_merge( array( $php ), $plain, array( $child_verifier_file, $ws, $project ) ), $cap_env );
	hal_cap_check(
		'CAP-A3-VERIFIER-REFUSES',
		str_contains( $out, 'VERIFIER-THROW:HAL_SIGNATURE_CAPABILITY_MISSING' ),
		'real verifier refuses without the signature capability, before any key handling',
		'unexpected: ' . $out
	);
}

/* ════════════════════════════════════════════════════════════════
 * (W) the real activation wrapper — no raw Throwable, wp_die display.
 * ════════════════════════════════════════════════════════════════ */

echo "\n== (W) activation wrapper ==\n";

$wrapper_flags = $present_flags;

$fixture_main = (string) file_get_contents( $project . '/hal-frontend-dashboard.php' );
foreach ( array( 'unavailable', 'invalid', 'unexpected' ) as $mode ) {
	$fixture = $ws . '/wrapper-' . $mode . '/';
	@mkdir( $fixture, 0777, true );
	file_put_contents( $fixture . 'hal-frontend-dashboard.php', $fixture_main );
	if ( 'invalid' === $mode ) {
		@mkdir( $fixture . 'includes', 0777, true );
		file_put_contents( $fixture . 'includes/class-installer.php', "<?php\n// fixture: file loads but declares no installer class\n" );
	}
	if ( 'unexpected' === $mode ) {
		@mkdir( $fixture . 'includes', 0777, true );
		file_put_contents(
			$fixture . 'includes/class-installer.php',
			"<?php\nclass HAL_Frontend_Dashboard_Installer {\n\tpublic static function activate( bool \$network_wide = false ): void {\n\t\tthrow new LogicException( 'HAL-CAP-LEAK-MARKER' );\n\t}\n}\n"
		);
	}
	$out = hal_cap_run( array_merge( $wrapper_flags, array( $child_wrapper_file, $ws, $project, $mode, $fixture ) ) );
	$expected = array(
		'unavailable' => 'WPDIE:HAL_INSTALLER_UNAVAILABLE',
		'invalid' => 'WPDIE:HAL_INSTALLER_INVALID',
		'unexpected' => 'WPDIE:HAL_UNEXPECTED_ERROR (LogicException)',
	);
	hal_cap_check(
		'CAP-W-' . strtoupper( $mode ),
		str_contains( $out, $expected[ $mode ] ) && ( 'unexpected' !== $mode || ! str_contains( $out, 'HAL-CAP-LEAK-MARKER' ) ),
		'wrapper shows ' . $expected[ $mode ] . ' via wp_die (no raw Throwable)' . ( 'unexpected' === $mode ? ' and suppresses the original message' : '' ),
		'unexpected: ' . $out
	);
}

if ( $absent_sodium ) {
	// Real main file + real installer, no sodium: the wrapper maps the
	// preflight code through the remedy map before wp_die.
	$fixture = $ws . '/wrapper-preflight/';
	@mkdir( $fixture . 'includes', 0777, true );
	file_put_contents( $fixture . 'hal-frontend-dashboard.php', $fixture_main );
	copy( $project . '/includes/class-installer.php', $fixture . 'includes/class-installer.php' );
	$absent_flags = array_merge( $plain, array( '-d', 'extension=zip' ) );
	$out = hal_cap_run( array_merge( array( $php ), $absent_flags, array( $child_wrapper_file, $ws, $project, 'preflight', $fixture ) ) );
	hal_cap_check(
		'CAP-W-PREFLIGHT-REMEDY',
		str_contains( $out, 'WPDIE:HAL_PREFLIGHT_SODIUM_UNAVAILABLE' ) && str_contains( $out, 'sodium_compat' ),
		'wrapper displays the preflight code with its remedy text through wp_die (no raw exception)',
		'unexpected: ' . $out
	);
} else {
	echo "UNAVAILABLE: wrapper remedy case needs a sodium-absent child; absence is not producible here (skip ≠ PASS).\n";
}

/* ════════════════════════════════════════════════════════════════
 * (C) OPTIONAL external sodium_compat — polyfill-only acceptance + G5.
 * ════════════════════════════════════════════════════════════════ */

echo "\n== (C) external sodium_compat (optional) ==\n";

$compat_dir = null;
foreach ( array_slice( $_SERVER['argv'], 1 ) as $arg ) {
	if ( str_starts_with( (string) $arg, '--wp-sodium-compat=' ) ) {
		$compat_dir = rtrim( substr( (string) $arg, strlen( '--wp-sodium-compat=' ) ), '/\\' );
	}
}

if ( null === $compat_dir || ! is_file( $compat_dir . '/autoload.php' ) ) {
	echo "SKIP: no --wp-sodium-compat=<dir> given (skip ≠ PASS); G5 stays unmeasured without the external reference.\n";
} elseif ( ! $absent_sodium ) {
	echo "UNAVAILABLE: the polyfill case needs a sodium-absent child; absence is not producible here (skip ≠ PASS).\n";
} else {
	$out = hal_cap_run( array_merge( array( $php ), $plain, array( $child_compat_file, $ws, $project, $compat_dir ) ), $cap_env );
	$g5_ms = null;
	if ( preg_match( '/G5_MS=([0-9.]+)/', $out, $m ) ) {
		$g5_ms = (float) $m[1];
	}
	hal_cap_check(
		'CAP-C1-COMPAT-ACCEPTS',
		str_contains( $out, 'COMPAT-READY=Y' ) && str_contains( $out, 'COMPAT-ACCEPT' ),
		'real verifier accepts through the external sodium_compat polyfill alone (no sodium extension)',
		'unexpected: ' . $out
	);
	hal_cap_check(
		'CAP-C2-G5-BUDGET',
		null !== $g5_ms && $g5_ms <= 2000.0,
		'G5 measured: sum of the three Ed25519 detached verifications = ' . ( null === $g5_ms ? 'n/a' : $g5_ms . 'ms' ) . ' (budget 2000ms; above it the in-request verification memo would be triggered)',
		'G5 ' . ( null === $g5_ms ? 'not measured' : '= ' . $g5_ms . 'ms exceeds the 2000ms local budget — the memo decision is required' )
	);
	if ( null !== $g5_ms ) {
		@mkdir( $run_dir, 0777, true );
		file_put_contents( $run_dir . '/g5-measurement.txt', 'G5_MS=' . $g5_ms . "\n" . 'measured: ' . date( 'c' ) . "\n" . 'method: 3x sodium_crypto_sign_verify_detached via sodium_compat polyfill (carrier manifest payload), budget 2000ms' . "\n" );
	}
}

/* ── Summary ───────────────────────────────────────────────────── */

$failed = $failures();
$total = count( $GLOBALS['HAL_CAP_RESULTS'] );
$passed = $total - count( $failed );
echo "\nRESULT: {$passed}/{$total} checks passed\n";
if ( array() === $failed ) {
	hal_cap_remove_dir( $ws );
	exit( 0 );
}
foreach ( $failed as $r ) {
	echo 'FAIL: ' . $r['id'] . "\n";
}
exit( 1 );

function hal_cap_remove_dir( string $dir ): void {
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
