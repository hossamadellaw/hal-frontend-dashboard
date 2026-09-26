<?php
/**
 * HAL Frontend Dashboard — Batch 11 harness: installer (clean/repeat/failure/permission).
 *
 * Local, isolated harness: no live WordPress, no network, no database.
 * It stubs only the WordPress API surface and then drives the REAL project
 * code (includes/class-installer.php → real verifier, release manager,
 * health check, MU loader files). Fixture Carrier packages are built with
 * ZipArchive under .local-execution/batch-7/closure/ and signed with EPHEMERAL
 * Ed25519 keypairs generated at runtime (sodium_crypto_sign_keypair) —
 * never the production release key, never any private key in the repo.
 * The installer already exposes the test-key seam
 * (HAL_FRONTEND_DASHBOARD_TEST_PUBLIC_KEY/_FINGERPRINT); no production
 * code change was needed for it.
 *
 * Coverage (§22 installer row):
 *   clean install, repeat (idempotent) install, tampered Carrier main /
 *   includes / payload rejection, missing payload, wrong-key signature,
 *   legacy-loader preflight gate, DISALLOW_FILE_MODS permission gate.
 *
 * Usage:  php -d extension=sodium tests/php/installer-test.php          (main)
 *         php -d extension=sodium tests/php/installer-test.php disallow (subprocess)
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
$ws_root = $project . '/.local-execution/batch-7/closure/installer-' . getmypid();
@mkdir( $ws_root, 0777, true );

if ( 'disallow' === $mode && ! defined( 'DISALLOW_FILE_MODS' ) ) {
	define( 'DISALLOW_FILE_MODS', true );
}

$GLOBALS['HAL_IN_RESULTS'] = array();
$GLOBALS['HAL_IN_MODE'] = $mode;
$GLOBALS['HAL_IN_STATE'] = '';
$GLOBALS['HAL_IN_BODY'] = '';

function hal_in_check( string $id, bool $condition, string $pass, string $fail = '' ): void {
	$GLOBALS['HAL_IN_RESULTS'][] = array( 'id' => $id, 'ok' => $condition, 'pass' => $pass, 'fail' => $fail );
	echo ( $condition ? 'PASS' : 'FAIL' ) . " [$id] " . ( $condition ? $pass : $fail ) . "\n";
}

function hal_in_code( callable $fn ): string {
	try {
		$fn();
	} catch ( RuntimeException $exception ) {
		return $exception->getMessage();
	} catch ( Throwable $exception ) {
		return get_class( $exception ) . ':' . $exception->getMessage();
	}
	return '';
}

function hal_in_copy_dir( string $from, string $to ): void {
	hal_in_remove_dir( $to );
	@mkdir( $to, 0777, true );
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $from, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);
	foreach ( $iterator as $item ) {
		$target = $to . substr( $item->getPathname(), strlen( $from ) );
		if ( $item->isDir() ) {
			@mkdir( $target, 0777, true );
		} else {
			copy( $item->getPathname(), $target );
		}
	}
}
function hal_in_remove_dir( string $dir ): void {
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

/* ── WordPress boundary stubs (WordPress only — never project logic) ── */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $ws_root . '/wp/' );
}
@mkdir( ABSPATH, 0777, true );

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook_name, $callback = '', int $priority = 10, int $accepted_args = 1 ): bool {
		return true;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook_name, $callback = '', int $priority = 10, int $accepted_args = 1 ): bool {
		return true;
	}
}
if ( ! function_exists( 'get_filesystem_method' ) ) {
	function get_filesystem_method(): string {
		return 'direct';
	}
}
if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite(): bool {
		return false;
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component );
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
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( string $url, array $args = array() ) {
		$op = $args['body']['operation'] ?? '';
		$challenge = json_decode( (string) @file_get_contents( $GLOBALS['HAL_IN_STATE'] . '/health-challenge-' . $op . '.json' ), true );
		if ( ! is_array( $challenge ) ) {
			$challenge = array();
		}
		$GLOBALS['HAL_IN_BODY'] = json_encode( array(
			'success' => true, 'ready' => true, 'operation' => $op,
			'release_id' => $challenge['release_id'] ?? '',
			'version' => $challenge['version'] ?? '',
			'loader_id' => $challenge['loader_id'] ?? '',
		) );
		return array( 'ok' => true );
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return false;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ): int {
		return 200;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ): string {
		return (string) $GLOBALS['HAL_IN_BODY'];
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return $value;
	}
}
if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook ): int {
		return 0;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		return $value;
	}
}
if ( ! class_exists( 'WP_Role' ) ) {
	class WP_Role {
		public array $caps = array();
		public function has_cap( string $cap ): bool {
			return in_array( $cap, $this->caps, true );
		}
		public function add_cap( string $cap ): void {
			$this->caps[] = $cap;
		}
	}
}
if ( ! function_exists( 'get_role' ) ) {
	function get_role( string $role ) {
		$GLOBALS['HAL_IN_ROLE'] = $GLOBALS['HAL_IN_ROLE'] ?? new WP_Role();
		return $GLOBALS['HAL_IN_ROLE'];
	}
}
// WordPress page storage boundary used by Installer::activate().
if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public function __construct( public int $ID, public string $post_type, public string $post_status ) {}
	}
}
$GLOBALS['HAL_IN_OPTIONS'] = array();
$GLOBALS['HAL_IN_POSTS'] = array();
$GLOBALS['HAL_IN_META'] = array();
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $key, $default = false ) { return $GLOBALS['HAL_IN_OPTIONS'][ $key ] ?? $default; }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $key, $value, $autoload = null ): bool {
		$GLOBALS['HAL_IN_OPTIONS'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( int $id ) { return $GLOBALS['HAL_IN_POSTS'][ $id ] ?? null; }
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $id, string $key, bool $single = false ) { return $GLOBALS['HAL_IN_META'][ $id ][ $key ] ?? ''; }
}
if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( array $args ): array {
		$ids = array();
		foreach ( $GLOBALS['HAL_IN_POSTS'] as $id => $post ) {
			if ( 'page' === $post->post_type && in_array( $post->post_status, $args['post_status'], true )
				&& ( $GLOBALS['HAL_IN_META'][ $id ][ $args['meta_key'] ] ?? '' ) === $args['meta_value'] ) $ids[] = $id;
		}
		return array_slice( $ids, 0, $args['posts_per_page'] );
	}
}
if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( array $args, bool $wp_error = false ): int {
		$id = 100 + count( $GLOBALS['HAL_IN_POSTS'] );
		$GLOBALS['HAL_IN_POSTS'][ $id ] = new WP_Post( $id, $args['post_type'], $args['post_status'] );
		$GLOBALS['HAL_IN_META'][ $id ] = $args['meta_input'];
		return $id;
	}
}

/* ── Ephemeral signing (test keypair only — never the release key) ── */

function hal_in_sign( array $manifest, string $secret ): array {
	$raw = str_replace( "\r\n", "\n", json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) ) . "\n";
	return array( $raw, base64_encode( sodium_crypto_sign_detached( $raw, $secret ) ) );
}

/**
 * Build a minimal but complete fixture Carrier package and sign it with
 * the given ephemeral secret key. Returns [carrier_dir, mu_dir].
 */
function hal_in_build_carrier( string $project, string $ws, string $name, string $secret, string $version = '1.0.0' ): string {
	$carrier = $ws . '/' . $name;
	hal_in_remove_dir( $carrier );
	mkdir( $carrier . '/includes', 0777, true );
	mkdir( $carrier . '/mu-loader', 0777, true );
	mkdir( $carrier . '/payload', 0777, true );

	file_put_contents( $carrier . '/hal-frontend-dashboard.php', "<?php\ndefined( 'ABSPATH' ) || exit;\n// fixture carrier main $version\n" );
	file_put_contents( $carrier . '/readme.txt', "=== HAL Frontend Dashboard ===\nStable tag: $version\n" );
	file_put_contents( $carrier . '/LICENSE', "GPL-2.0-or-later fixture\n" );
	file_put_contents( $carrier . '/THIRD-PARTY-NOTICES.txt', "fixture notices\n" );
	file_put_contents( $carrier . '/includes/hal-fixture.php', "<?php\ndefined( 'ABSPATH' ) || exit;\n// fixture includes file\n" );
	copy( $project . '/mu-loader/hal-frontend-dashboard.php', $carrier . '/mu-loader/hal-frontend-dashboard.php' );
	copy( $project . '/mu-loader/loader-core.php', $carrier . '/mu-loader/loader-core.php' );

	$bootstrap = "<?php\n// fixture runtime bootstrap $version\n";
	$zip_path = $carrier . '/payload/runtime-' . $version . '.zip';
	$zip = new ZipArchive();
	if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		throw new RuntimeException( 'HAL_TEST_ZIP_CREATE_FAILED' );
	}
	$zip->addFromString( 'bootstrap.php', $bootstrap );
	$zip->close();

	$sha = str_repeat( 'a', 40 );
	$runtime_manifest = array(
		'schema' => 1, 'product' => 'hal-frontend-dashboard', 'version' => $version,
		'release_sequence' => 1, 'release_id' => $version . '+' . $sha, 'tag' => 'v' . $version,
		'commit_sha' => $sha, 'requires_wp' => '7.0', 'requires_php' => '8.3',
		'loader_api_min' => 1, 'loader_api_max' => 1, 'loader_core_version' => $version,
		'schema_version' => '1', 'archive_sha256' => hash_file( 'sha256', $zip_path ),
		'files' => array( 'bootstrap.php' => hash( 'sha256', $bootstrap ) ),
	);
	list( $runtime_raw, $runtime_sig ) = hal_in_sign( $runtime_manifest, $secret );
	file_put_contents( $carrier . '/payload/runtime-manifest.json', $runtime_raw );
	file_put_contents( $carrier . '/payload/runtime-manifest.sig', $runtime_sig );

	$files = array();
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $carrier, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);
	foreach ( $iterator as $item ) {
		if ( $item->isDir() ) {
			continue;
		}
		$relative = str_replace( '\\', '/', substr( $item->getPathname(), strlen( $carrier ) + 1 ) );
		if ( 'carrier-manifest.json' === $relative || 'carrier-manifest.sig' === $relative ) {
			continue;
		}
		$files[ $relative ] = hash_file( 'sha256', $item->getPathname() );
	}
	ksort( $files, SORT_STRING );
	$carrier_manifest = array(
		'schema' => 1, 'product' => 'hal-frontend-dashboard', 'version' => $version,
		'plugin_basename' => 'hal-frontend-dashboard/hal-frontend-dashboard.php', 'files' => $files,
	);
	list( $carrier_raw, $carrier_sig ) = hal_in_sign( $carrier_manifest, $secret );
	file_put_contents( $carrier . '/carrier-manifest.json', $carrier_raw );
	file_put_contents( $carrier . '/carrier-manifest.sig', $carrier_sig );

	return $carrier;
}

/* ── Shared environment ── */

$GLOBALS['wp_version'] = '7.1';
$keypair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey( $keypair );
$public = sodium_crypto_sign_publickey( $keypair );
if ( ! defined( 'HAL_FRONTEND_DASHBOARD_TEST_PUBLIC_KEY' ) ) {
	define( 'HAL_FRONTEND_DASHBOARD_TEST_PUBLIC_KEY', base64_encode( $public ) );
	define( 'HAL_FRONTEND_DASHBOARD_TEST_KEY_FINGERPRINT', hash( 'sha256', $public ) );
}
if ( ! defined( 'HAL_FRONTEND_DASHBOARD_VERSION' ) ) {
	define( 'HAL_FRONTEND_DASHBOARD_VERSION', '1.0.0' );
}
require_once $project . '/includes/class-installer.php';

function hal_in_report( string $mode ): void {
	$pass = 0;
	$total = count( $GLOBALS['HAL_IN_RESULTS'] );
	foreach ( $GLOBALS['HAL_IN_RESULTS'] as $result ) {
		if ( $result['ok'] ) {
			$pass++;
		}
	}
	if ( 'main' === $mode ) {
		echo "RESULT: $pass/$total checks passed\n";
	} else {
		echo 'HAL-VERDICT ' . $mode . ( $pass === $total ? ' ALL-ASSERTIONS-HELD' : ' ASSERTIONS-FAILED' ) . "\n";
	}
	exit( $pass === $total ? 0 : 1 );
}

/* ════════════════════════════════════════════════════════════════
 * DISALLOW MODE — permission gate before any lasting effect
 * ════════════════════════════════════════════════════════════════ */

if ( 'disallow' === $mode ) {
	$mu = $ws_root . '/mu-disallow';
	@mkdir( $mu, 0777, true );
	if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
		define( 'WPMU_PLUGIN_DIR', $mu );
	}
	if ( ! defined( 'HAL_FRONTEND_DASHBOARD_PLUGIN_DIR' ) ) {
		define( 'HAL_FRONTEND_DASHBOARD_PLUGIN_DIR', $ws_root . '/carrier-unused/' );
	}
	$code = hal_in_code( static function (): void {
		HAL_Frontend_Dashboard_Installer::activate();
	} );
	hal_in_check(
		'I-PERMISSION',
		'HAL_PREFLIGHT_FILE_MODS_DISABLED' === $code,
		'DISALLOW_FILE_MODS refuses activation before any lasting effect',
		'unexpected code: ' . $code
	);
	hal_in_report( 'disallow' );
}

/* ════════════════════════════════════════════════════════════════
 * MAIN — clean / repeat / failure cases
 * ════════════════════════════════════════════════════════════════ */

$mu = $ws_root . '/mu-plugins';
@mkdir( $mu, 0777, true );
if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
	define( 'WPMU_PLUGIN_DIR', $mu );
}
$GLOBALS['HAL_IN_STATE'] = $mu . '/hal-frontend-dashboard/state';

/* I1 — clean install from a signed fixture Carrier. */
$carrier1 = hal_in_build_carrier( $project, $ws_root, 'carrier-clean', $secret );
if ( ! defined( 'HAL_FRONTEND_DASHBOARD_PLUGIN_DIR' ) ) {
	define( 'HAL_FRONTEND_DASHBOARD_PLUGIN_DIR', $carrier1 . '/' );
}
$code = hal_in_code( static function (): void {
	HAL_Frontend_Dashboard_Installer::activate();
} );
hal_in_check( 'I1-CLEAN', '' === $code, 'clean install from the signed fixture Carrier succeeds', 'code: ' . $code );
$page_id = (int) get_option( HAL_Frontend_Dashboard_Installer::PAGE_ID_OPTION, 0 );
hal_in_check( 'I1-PAGE', $page_id > 0 && get_post( $page_id ) instanceof WP_Post
	&& 'page' === get_post( $page_id )->post_type
	&& '1' === get_post_meta( $page_id, HAL_Frontend_Dashboard_Installer::PAGE_META_KEY, true ),
	'clean activation stores a verified owned Dashboard page', 'page was not owned and linked' );

$state = $mu . '/hal-frontend-dashboard/state';
$release_id = '1.0.0+' . str_repeat( 'a', 40 );
$active = is_file( $state . '/active.json' ) ? json_decode( (string) file_get_contents( $state . '/active.json' ), true ) : null;
$committed = is_file( $state . '/committed.json' ) ? json_decode( (string) file_get_contents( $state . '/committed.json' ), true ) : null;
$history = is_file( $state . '/history.json' ) ? json_decode( (string) file_get_contents( $state . '/history.json' ), true ) : null;
$loader_active = is_file( $state . '/loader-active.json' ) ? json_decode( (string) file_get_contents( $state . '/loader-active.json' ), true ) : null;
hal_in_check(
	'I1-STATE',
	is_array( $active ) && $release_id === ( $active['release_id'] ?? null )
		&& is_array( $committed ) && $release_id === ( $committed['release_id'] ?? null )
		&& is_array( $history ) && in_array( $release_id, $history['releases'] ?? array(), true )
		&& is_array( $loader_active ) && 'loader-1.0.0' === ( $loader_active['loader_id'] ?? null )
		&& is_file( $mu . '/hal-frontend-dashboard/releases/' . $release_id . '/bootstrap.php' )
		&& is_file( $mu . '/hal-frontend-dashboard.php' ),
	'active+committed+history+loader pointers, immutable release, and root anchor all installed',
	'state after clean install inconsistent'
);

/* I2 — repeat install is idempotent: state bytes unchanged. */
$snapshot = array();
foreach ( array( 'active.json', 'committed.json', 'history.json', 'loader-active.json' ) as $file ) {
	$snapshot[ $file ] = file_get_contents( $state . '/' . $file );
}
$code = hal_in_code( static function (): void {
	HAL_Frontend_Dashboard_Installer::activate();
} );
$repeat_ok = '' === $code;
foreach ( $snapshot as $file => $bytes ) {
	if ( file_get_contents( $state . '/' . $file ) !== $bytes ) {
		$repeat_ok = false;
	}
}
hal_in_check( 'I2-REPEAT', $repeat_ok && $page_id === (int) get_option( HAL_Frontend_Dashboard_Installer::PAGE_ID_OPTION, 0 )
	&& 1 === count( $GLOBALS['HAL_IN_POSTS'] ), 'repeat activation succeeds with byte-identical state and one owned page', 'code: ' . $code );

/* Pristine snapshot: every mutation case restores carrier1 byte-identical. */
$snapshot_dir = $ws_root . '/carrier-pristine';
hal_in_copy_dir( $carrier1, $snapshot_dir );
$hal_in_restore = static function () use ( $carrier1, $snapshot_dir ): void {
	hal_in_copy_dir( $snapshot_dir, $carrier1 );
};

/* I3 — tampered Carrier main file rejected before any MU write. */
file_put_contents( $carrier1 . '/hal-frontend-dashboard.php', "// TAMPERED\n", FILE_APPEND );
$code = hal_in_code( static function (): void {
	HAL_Frontend_Dashboard_Installer::activate();
} );
hal_in_check(
	'I3-TAMPER-MAIN',
	'HAL_PACKAGE_TREE_HASH_MISMATCH' === $code,
	'tampered Carrier main file rejected with HAL_PACKAGE_TREE_HASH_MISMATCH',
	'unexpected code: ' . $code
);
$hal_in_restore();

/* I4 — payload tampered without the runtime signing key: the Carrier tree
 * is re-signed over the tampered bytes ( Carrier-level consistency), so
 * execution reaches the runtime archive gate and rejects there. */
file_put_contents( $carrier1 . '/payload/runtime-1.0.0.zip', '// TAMPERED', FILE_APPEND );
$carrier_data = json_decode( (string) file_get_contents( $carrier1 . '/carrier-manifest.json' ), true );
$carrier_data['files']['payload/runtime-1.0.0.zip'] = hash_file( 'sha256', $carrier1 . '/payload/runtime-1.0.0.zip' );
list( $raw4, $sig4 ) = hal_in_sign( $carrier_data, $secret );
file_put_contents( $carrier1 . '/carrier-manifest.json', $raw4 );
file_put_contents( $carrier1 . '/carrier-manifest.sig', $sig4 );
$code = hal_in_code( static function (): void {
	HAL_Frontend_Dashboard_Installer::activate();
} );
$active_after = is_file( $state . '/active.json' ) ? json_decode( (string) file_get_contents( $state . '/active.json' ), true ) : null;
hal_in_check(
	'I4-TAMPER-PAYLOAD',
	'HAL_RUNTIME_ARCHIVE_HASH_MISMATCH' === $code && is_array( $active_after ) && $release_id === ( $active_after['release_id'] ?? null ),
	'tampered payload rejected with HAL_RUNTIME_ARCHIVE_HASH_MISMATCH and the installed release stays active',
	'unexpected code: ' . $code
);
$hal_in_restore();

/* I5 — tampered includes file rejected. */
file_put_contents( $carrier1 . '/includes/hal-fixture.php', "// TAMPERED\n", FILE_APPEND );
$code = hal_in_code( static function (): void {
	HAL_Frontend_Dashboard_Installer::activate();
} );
hal_in_check(
	'I5-TAMPER-INCLUDES',
	'HAL_PACKAGE_TREE_HASH_MISMATCH' === $code,
	'tampered includes file rejected with HAL_PACKAGE_TREE_HASH_MISMATCH',
	'unexpected code: ' . $code
);
$hal_in_restore();

/* I6 — two payload archives: the tree still verifies (both files listed and
 * signed), so execution reaches the cardinality gate and rejects. */
$extra = $carrier1 . '/payload/runtime-9.9.9.zip';
copy( $carrier1 . '/payload/runtime-1.0.0.zip', $extra );
$manifest_data = json_decode( (string) file_get_contents( $carrier1 . '/carrier-manifest.json' ), true );
$manifest_data['files']['payload/runtime-9.9.9.zip'] = hash_file( 'sha256', $extra );
list( $raw6, $sig6 ) = hal_in_sign( $manifest_data, $secret );
file_put_contents( $carrier1 . '/carrier-manifest.json', $raw6 );
file_put_contents( $carrier1 . '/carrier-manifest.sig', $sig6 );
$code = hal_in_code( static function (): void {
	HAL_Frontend_Dashboard_Installer::activate();
} );
hal_in_check(
	'I6-PAYLOAD-CARDINALITY',
	'HAL_RUNTIME_ARCHIVE_CARDINALITY_INVALID' === $code,
	'duplicate payload archive rejected with HAL_RUNTIME_ARCHIVE_CARDINALITY_INVALID',
	'unexpected code: ' . $code
);
@unlink( $extra );
$hal_in_restore();

/* I7 — manifest signed by a different (wrong) key rejected. */
$other_keypair = sodium_crypto_sign_keypair();
$other_secret = sodium_crypto_sign_secretkey( $other_keypair );
$carrier7 = hal_in_build_carrier( $project, $ws_root, 'carrier-wrong-key', $other_secret );
copy( $carrier7 . '/carrier-manifest.json', $carrier1 . '/carrier-manifest.json' );
copy( $carrier7 . '/carrier-manifest.sig', $carrier1 . '/carrier-manifest.sig' );
$code = hal_in_code( static function (): void {
	HAL_Frontend_Dashboard_Installer::activate();
} );
hal_in_check(
	'I7-WRONG-KEY',
	'HAL_SIGNATURE_VERIFICATION_FAILED' === $code,
	'wrong-key carrier manifest rejected with HAL_SIGNATURE_VERIFICATION_FAILED',
	'unexpected code: ' . $code
);
$hal_in_restore();

/* I8 — legacy loader preflight gate. */
file_put_contents( $mu . '/hossam-dashboard.php', "<?php\n// legacy\n" );
$code = hal_in_code( static function (): void {
	HAL_Frontend_Dashboard_Installer::activate();
} );
@unlink( $mu . '/hossam-dashboard.php' );
hal_in_check(
	'I8-LEGACY',
	'HAL_PREFLIGHT_LEGACY_LOADER_ACTIVE' === $code,
	'active legacy loader refuses activation before any lasting effect',
	'unexpected code: ' . $code
);

/* I9 — permission subprocess (DISALLOW_FILE_MODS). */
$php = PHP_BINARY;
$cmd = array( $php, '-d', 'extension_dir=' . ini_get( 'extension_dir' ), '-d', 'extension=sodium', '-d', 'extension=zip', __FILE__, 'disallow' );
$stdout_file = $ws_root . '/inout-' . bin2hex( random_bytes( 8 ) ) . '.txt';
$stderr_file = $ws_root . '/inerr-' . bin2hex( random_bytes( 8 ) ) . '.txt';
$proc = proc_open( $cmd, array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $stdout_file, 'w' ), 2 => array( 'file', $stderr_file, 'w' ) ), $pipes );
if ( ! is_resource( $proc ) ) {
	hal_in_check( 'I9-PERMISSION', false, '', 'proc_open failed' );
} else {
	fclose( $pipes[0] );
	$exit = proc_close( $proc );
	$out = (string) file_get_contents( $stdout_file );
	$err = (string) file_get_contents( $stderr_file );
	@unlink( $stdout_file );
	@unlink( $stderr_file );
	hal_in_check(
		'I9-PERMISSION',
		0 === $exit && false !== strpos( $out, 'HAL-VERDICT disallow ALL-ASSERTIONS-HELD' ),
		'DISALLOW_FILE_MODS subprocess held its permission assertion (exit 0)',
		'exit ' . $exit . '; stdout: ' . trim( $out ) . '; stderr: ' . trim( $err )
	);
}

$pass = 0;
foreach ( $GLOBALS['HAL_IN_RESULTS'] as $result ) {
	if ( $result['ok'] ) {
		$pass++;
	}
}
$total = count( $GLOBALS['HAL_IN_RESULTS'] );
echo "RESULT: $pass/$total checks passed\n";
if ( $pass === $total ) {
	hal_in_remove_dir( $ws_root );
}
exit( $pass === $total ? 0 : 1 );
