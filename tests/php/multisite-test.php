<?php
/**
 * HAL Frontend Dashboard — Simple Multisite acceptance (approved scope).
 *
 * Local, isolated harness (CLI PHP 8.3 only; no network, no live WordPress,
 * no database). Simulates a two-site network (blogs 1 + 2, blog 3 joins
 * later) with a stubbed multisite WordPress boundary and drives REAL
 * product code throughout: Installer::activate(true), the shared
 * Site_Provisioner, Settings Repository, Secret Store, table ensure flow,
 * Update Bridge auto-update gate, Release Manager shared state, and the
 * REAL runtime/core/setup.php wp_initialize_site registration.
 *
 * Coverage (mandated acceptance):
 *   M1 — network activation provisions every current site (distinct owned
 *        pages + per-site capability), single shared install, no refusal.
 *   M2 — repeat network activation is idempotent (same IDs, no new pages).
 *   M3 — per-site isolation: settings, secret ciphertexts, page links,
 *        capabilities, and blog-prefixed tables (structural + real rows
 *        through the secret/settings/page write paths; adapter row paths
 *        are unchanged blog-context code covered by their own suites).
 *   M4 — capabilities: super admin holds manage_network, site admins do
 *        not; auto-update applies for super/cron contexts and passes
 *        through for site admins on multisite (single-site unchanged,
 *        proven by runtime-bootstrap-test).
 *   M5 — one shared release state for the network: same active/previous
 *        pointers from both blogs, one shared update then shared failed
 *        rollback observed identically (transition mechanics themselves
 *        proven by release-manager-test on the same single-dir design).
 *   M6 — late-joined site via the REAL setup.php wp_initialize_site hook:
 *        blog 3 provisioned, blogs 1-2 untouched.
 *   M7 — shared network import through the REAL candidate→import→promote
 *        path (MS-01): site admin refused fail-closed with state kept,
 *        system context promotes v4 identically on both blogs, failed v5
 *        rolls back identically with its digest suppressed.
 *
 * Usage: php -d extension=sodium tests/php/multisite-test.php (exit 0)
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

if ( PHP_SAPI !== 'cli' ) {
	echo "This harness must run under CLI PHP.\n";
	exit( 1 );
}
if ( ! extension_loaded( 'sodium' ) ) {
	echo "SKIP: ext-sodium is required (secret-store paths).\n";
	exit( 2 );
}

$project = dirname( __DIR__, 2 );
$ws_root = $project . '/.local-execution/multisite/run-' . getmypid();
@mkdir( $ws_root, 0777, true );

$GLOBALS['MS_RESULTS'] = array();

function ms_check( string $id, bool $condition, string $pass, string $fail = '' ): void {
	$GLOBALS['MS_RESULTS'][] = array( 'id' => $id, 'ok' => $condition, 'pass' => $pass, 'fail' => $fail );
	echo ( $condition ? 'PASS' : 'FAIL' ) . " [$id] " . ( $condition ? $pass : $fail ) . "\n";
}

function ms_rmdir( string $dir ): void {
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

/* ── Multisite WordPress boundary stubs (per-blog stores) ── */

$GLOBALS['MS_SITES'] = array( 1, 2 );
$GLOBALS['MS_CURRENT'] = 1;
$GLOBALS['MS_STACK'] = array();
$GLOBALS['MS_OPTIONS'] = array();
$GLOBALS['MS_POSTS'] = array();
$GLOBALS['MS_META'] = array();
$GLOBALS['MS_POST_SEQ'] = array();
$GLOBALS['MS_ROLES'] = array();
$GLOBALS['MS_USER_ID'] = 1;
$GLOBALS['MS_SUPER_ADMINS'] = array( 1 );
$GLOBALS['MS_USER_CAPS'] = array(
	2 => array( 1 => array( 'manage_options', 'edit_posts' ), 2 => array( 'manage_options', 'edit_posts' ) ),
	3 => array( 1 => array( 'read' ), 2 => array( 'read' ) ),
);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $ws_root . '/wp/' );
}
@mkdir( ABSPATH, 0777, true );
// Fake wp-admin upgrade file: dbDelta itself is defined by this harness.
@mkdir( ABSPATH . 'wp-admin/includes', 0777, true );
file_put_contents( ABSPATH . 'wp-admin/includes/upgrade.php', "<?php\n// dbDelta is defined by the multisite harness.\n" );

function ms_blog_prefix( int $blog_id ): string {
	return 1 === $blog_id ? 'wp_' : 'wp_' . $blog_id . '_';
}

if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite(): bool {
		return true;
	}
}
if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id(): int {
		return (int) $GLOBALS['MS_CURRENT'];
	}
}
if ( ! function_exists( 'switch_to_blog' ) ) {
	function switch_to_blog( int $blog_id ): bool {
		$GLOBALS['MS_STACK'][] = $GLOBALS['MS_CURRENT'];
		$GLOBALS['MS_CURRENT'] = $blog_id;
		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) ) {
			$wpdb->prefix = ms_blog_prefix( $blog_id );
			$wpdb->blogid = $blog_id;
		}
		return true;
	}
}
if ( ! function_exists( 'restore_current_blog' ) ) {
	function restore_current_blog(): bool {
		$prev = array_pop( $GLOBALS['MS_STACK'] );
		$GLOBALS['MS_CURRENT'] = null === $prev ? 1 : (int) $prev;
		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) ) {
			$wpdb->prefix = ms_blog_prefix( $GLOBALS['MS_CURRENT'] );
			$wpdb->blogid = $GLOBALS['MS_CURRENT'];
		}
		return true;
	}
}
if ( ! function_exists( 'get_sites' ) ) {
	function get_sites( array $args = array() ) {
		if ( 'ids' === ( $args['fields'] ?? '' ) ) {
			return array_values( $GLOBALS['MS_SITES'] );
		}
		$out = array();
		foreach ( $GLOBALS['MS_SITES'] as $id ) {
			$out[] = (object) array( 'blog_id' => (int) $id );
		}
		return $out;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $key, $default = false ) {
		$blog = (int) $GLOBALS['MS_CURRENT'];
		return $GLOBALS['MS_OPTIONS'][ $blog ][ $key ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $key, $value, $autoload = null ): bool {
		$blog = (int) $GLOBALS['MS_CURRENT'];
		$GLOBALS['MS_OPTIONS'][ $blog ][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $key ): bool {
		$blog = (int) $GLOBALS['MS_CURRENT'];
		unset( $GLOBALS['MS_OPTIONS'][ $blog ][ $key ] );
		return true;
	}
}
if ( ! class_exists( 'WP_Role' ) ) {
	class WP_Role {
		public array $caps = array();
		public int $add_cap_calls = 0;
		public function has_cap( string $cap ): bool {
			return in_array( $cap, $this->caps, true );
		}
		public function add_cap( string $cap ): void {
			$this->add_cap_calls++;
			if ( ! in_array( $cap, $this->caps, true ) ) {
				$this->caps[] = $cap;
			}
		}
	}
}
if ( ! function_exists( 'get_role' ) ) {
	function get_role( string $role ) {
		$blog = (int) $GLOBALS['MS_CURRENT'];
		if ( 'administrator' !== $role ) {
			return null;
		}
		if ( ! isset( $GLOBALS['MS_ROLES'][ $blog ] ) ) {
			$GLOBALS['MS_ROLES'][ $blog ] = new WP_Role();
			$GLOBALS['MS_ROLES'][ $blog ]->caps = array( 'manage_options', 'edit_posts' );
		}
		return $GLOBALS['MS_ROLES'][ $blog ];
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return (int) $GLOBALS['MS_USER_ID'];
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap, ...$args ): bool {
		$uid = (int) $GLOBALS['MS_USER_ID'];
		if ( in_array( $uid, $GLOBALS['MS_SUPER_ADMINS'], true ) ) {
			return true;
		}
		$blog = (int) $GLOBALS['MS_CURRENT'];
		return in_array( $cap, $GLOBALS['MS_USER_CAPS'][ $uid ][ $blog ] ?? array(), true );
	}
}
if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID;
		public string $post_type;
		public string $post_status;
		public function __construct( int $id = 0, string $type = '', string $status = '' ) {
			$this->ID = $id;
			$this->post_type = $type;
			$this->post_status = $status;
		}
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( int $id ) {
		$blog = (int) $GLOBALS['MS_CURRENT'];
		return $GLOBALS['MS_POSTS'][ $blog ][ $id ] ?? null;
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $id, string $key, bool $single = false ) {
		$blog = (int) $GLOBALS['MS_CURRENT'];
		return $GLOBALS['MS_META'][ $blog ][ $id ][ $key ] ?? '';
	}
}
if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( array $args ): array {
		$blog = (int) $GLOBALS['MS_CURRENT'];
		$ids = array();
		foreach ( $GLOBALS['MS_POSTS'][ $blog ] ?? array() as $id => $post ) {
			if ( 'page' === $post->post_type && in_array( $post->post_status, $args['post_status'], true )
				&& ( $GLOBALS['MS_META'][ $blog ][ $id ][ $args['meta_key'] ] ?? '' ) === $args['meta_value'] ) {
				$ids[] = $id;
			}
		}
		return array_slice( $ids, 0, $args['posts_per_page'] );
	}
}
if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( array $args, bool $wp_error = false ): int {
		$blog = (int) $GLOBALS['MS_CURRENT'];
		$GLOBALS['MS_POST_SEQ'][ $blog ] = ( $GLOBALS['MS_POST_SEQ'][ $blog ] ?? 100 ) + 1;
		$id = (int) $GLOBALS['MS_POST_SEQ'][ $blog ];
		$GLOBALS['MS_POSTS'][ $blog ][ $id ] = new WP_Post( $id, $args['post_type'], $args['post_status'] );
		$GLOBALS['MS_META'][ $blog ][ $id ] = $args['meta_input'] ?? array();
		return $id;
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private $data;
		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code = (string) $code;
			$this->data = $data;
		}
		public function get_error_code() {
			return $this->code;
		}
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}
if ( ! function_exists( 'get_filesystem_method' ) ) {
	function get_filesystem_method(): string {
		return 'direct';
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
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $options = 0 ) {
		return json_encode( $data, $options );
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook_name, $callback = '', int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['MS_HOOKS'][ $hook_name ][] = $callback;
		return true;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook_name, $callback = '', int $priority = 10, int $accepted_args = 1 ): bool {
		return true;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook_name, ...$args ): void {
	}
}
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( string $url, array $args = array() ) {
		$op = $args['body']['operation'] ?? '';
		$challenge = json_decode( (string) @file_get_contents( $GLOBALS['MS_STATE'] . '/health-challenge-' . $op . '.json' ), true );
		if ( ! is_array( $challenge ) ) {
			$challenge = array();
		}
		$GLOBALS['MS_BODY'] = json_encode( array(
			'success' => true, 'ready' => true, 'operation' => $op,
			'release_id' => $challenge['release_id'] ?? '',
			'version' => $challenge['version'] ?? '',
			'loader_id' => $challenge['loader_id'] ?? '',
		) );
		return array( 'ok' => true );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ): int {
		return 200;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ): string {
		return (string) $GLOBALS['MS_BODY'];
	}
}
if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook ): int {
		return 0;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		foreach ( $GLOBALS['MS_FILTER_HOOKS'][ $hook ] ?? array() as $cb ) {
			$value = $cb( $value );
		}
		return $value;
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}
if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( string $hook, $callback = false ): bool {
		return false;
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $id ): string {
		$host = 1 === get_current_blog_id() ? 'https://one.test' : 'https://two.test';
		return $host . '/?p=' . (int) $id;
	}
}
if ( ! function_exists( 'get_page_by_path' ) ) {
	function get_page_by_path( string $slug ) {
		if ( 1 === get_current_blog_id() && 'hello' === $slug ) {
			return (object) array( 'ID' => 55 );
		}
		return null;
	}
}
$GLOBALS['MS_FILTER_HOOKS'] = array();

/* ── Per-blog $wpdb with SHOW emulation (mirrors batch2 B2C_WPDB shape) ── */

final class MS_WPDB {
	public string $prefix = 'wp_';
	public int $blogid = 1;
	/** @var string */
	public $last_error = '';
	/** @var array<string, array{columns:string[], indexes:string[]}> */
	private array $tables = array();

	public function ms_record_table( string $table, array $columns, array $indexes ): void {
		$this->tables[ $table ] = array( 'columns' => $columns, 'indexes' => $indexes );
	}

	public function ms_tables(): array {
		return array_keys( $this->tables );
	}

	public function flush(): bool {
		$this->last_error = '';
		return true;
	}

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function prepare( string $query, ...$args ): string {
		foreach ( $args as $arg ) {
			if ( false !== strpos( $query, '%s' ) ) {
				$query = preg_replace( '/%s/', "'" . $arg . "'", $query, 1 );
			} elseif ( false !== strpos( $query, '%d' ) ) {
				$query = preg_replace( '/%d/', (string) (int) $arg, $query, 1 );
			}
		}
		return $query;
	}

	/**
	 * @return mixed
	 */
	public function get_var( ?string $query = null ) {
		if ( null === $query ) {
			return null;
		}
		if ( preg_match( "/^SHOW TABLES LIKE '(.*)'$/s", $query, $match ) ) {
			$name = str_replace( array( '\\_', '\\%', '\\\\' ), array( '_', '%', '\\' ), $match[1] );
			return array_key_exists( $name, $this->tables ) ? $name : null;
		}
		return null;
	}

	/**
	 * @return string[]
	 */
	public function get_col( string $query, int $column_offset = 0 ): array {
		if ( preg_match( '/^SHOW COLUMNS FROM `(.+)`/', $query, $match ) ) {
			return isset( $this->tables[ $match[1] ] ) ? array_values( $this->tables[ $match[1] ]['columns'] ) : array();
		}
		if ( preg_match( '/^SHOW INDEX FROM `(.+)`/', $query, $match ) ) {
			return isset( $this->tables[ $match[1] ] ) ? array_values( $this->tables[ $match[1] ]['indexes'] ) : array();
		}
		return array();
	}
}

$GLOBALS['wpdb'] = new MS_WPDB();

/** dbDelta stub: parses CREATE TABLE shape into the per-blog registry. */
function dbDelta( $queries = array(), $execute = true ) {
	global $wpdb;
	$parsed = array();
	foreach ( (array) $queries as $sql ) {
		if ( ! is_string( $sql ) || ! preg_match( '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?\s*\(/i', $sql, $match ) ) {
			continue;
		}
		$open  = strpos( $sql, '(' );
		$close = strrpos( $sql, ')' );
		if ( false === $open || false === $close || $close <= $open ) {
			continue;
		}
		$columns = array();
		$indexes = array();
		foreach ( preg_split( '/\r\n|\r|\n/', substr( $sql, $open + 1, $close - $open - 1 ) ) as $line ) {
			$line = trim( $line, " \t," );
			if ( '' === $line ) {
				continue;
			}
			if ( preg_match( '/^PRIMARY\s+KEY/i', $line ) ) {
				$indexes[] = 'PRIMARY';
				continue;
			}
			if ( preg_match( '/^(?:UNIQUE\s+KEY|UNIQUE\s+INDEX|KEY|INDEX)\s+([A-Za-z0-9_]+)/i', $line, $m ) ) {
				$indexes[] = $m[1];
				continue;
			}
			if ( preg_match( '/^`?([A-Za-z0-9_]+)`?\s/i', $line, $m ) ) {
				$columns[] = $m[1];
			}
		}
		$wpdb->ms_record_table( $match[1], $columns, $indexes );
		$parsed[] = $match[1];
	}
	return $parsed;
}

/* ── Ephemeral signing (test keypair only — never the release key) ── */

function ms_sign( array $manifest, string $secret ): array {
	$raw = str_replace( "\r\n", "\n", json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) ) . "\n";
	return array( $raw, base64_encode( sodium_crypto_sign_detached( $raw, $secret ) ) );
}

/**
 * Build a minimal but complete fixture Carrier (mirrors installer-test).
 */
function ms_build_carrier( string $project, string $ws, string $name, string $secret, string $version = '1.0.0', int $sequence = 1, string $loader_version = '' ): string {
	$loader_version = '' !== $loader_version ? $loader_version : $version;
	$carrier = $ws . '/' . $name;
	ms_rmdir( $carrier );
	mkdir( $carrier . '/includes', 0777, true );
	mkdir( $carrier . '/mu-loader', 0777, true );
	mkdir( $carrier . '/payload', 0777, true );

	file_put_contents( $carrier . '/hal-frontend-dashboard.php', "<?php\n/**\n * Plugin Name: HAL Fixture\n * Version: $version\n */\ndefined( 'ABSPATH' ) || exit;\n// fixture carrier main $version\n" );
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
		'release_sequence' => $sequence, 'release_id' => $version . '+' . $sha, 'tag' => 'v' . $version,
		'commit_sha' => $sha, 'requires_wp' => '7.0', 'requires_php' => '8.3',
		'loader_api_min' => 1, 'loader_api_max' => 1, 'loader_core_version' => $loader_version,
		'schema_version' => '1', 'archive_sha256' => hash_file( 'sha256', $zip_path ),
		'files' => array( 'bootstrap.php' => hash( 'sha256', $bootstrap ) ),
	);
	list( $runtime_raw, $runtime_sig ) = ms_sign( $runtime_manifest, $secret );
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
	list( $carrier_raw, $carrier_sig ) = ms_sign( $carrier_manifest, $secret );
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
if ( ! defined( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY' ) ) {
	define( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY', hash( 'sha256', 'ms-test-secret-key' ) );
}
$mu_dir = $ws_root . '/mu-plugins';
@mkdir( $mu_dir, 0777, true );
if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
	define( 'WPMU_PLUGIN_DIR', $mu_dir );
}
$carrier_dir = ms_build_carrier( $project, $ws_root, 'carrier', $secret );
if ( ! defined( 'HAL_FRONTEND_DASHBOARD_PLUGIN_DIR' ) ) {
	define( 'HAL_FRONTEND_DASHBOARD_PLUGIN_DIR', $carrier_dir . DIRECTORY_SEPARATOR );
}
$GLOBALS['MS_STATE'] = $mu_dir . '/hal-frontend-dashboard/state';
require_once $project . '/includes/class-installer.php';
require_once $project . '/includes/class-release-manager.php';
require_once $project . '/includes/class-update-bridge.php';
require_once $project . '/runtime/core/tables.php';
require_once $project . '/runtime/settings/class-settings-repository.php';
require_once $project . '/runtime/security/class-secret-store.php';

/* ── M1: network activation provisions every current site ── */

$GLOBALS['MS_USER_ID'] = 1;
try {
	HAL_Frontend_Dashboard_Installer::activate( true );
	$m1_code = '';
} catch ( Throwable $e ) {
	$m1_code = get_class( $e ) . ':' . $e->getMessage();
}
ms_check(
	'M1-network-activate',
	'' === $m1_code,
	'network activation completes with is_multisite() true (no blanket refusal)',
	'unexpected code: ' . $m1_code
);
$page1 = (int) ( $GLOBALS['MS_OPTIONS'][1]['hal_frontend_dashboard_page_id'] ?? 0 );
$page2 = (int) ( $GLOBALS['MS_OPTIONS'][2]['hal_frontend_dashboard_page_id'] ?? 0 );
ms_check(
	'M1-per-site-pages',
	$page1 > 0 && $page2 > 0
		&& 'page' === ( $GLOBALS['MS_POSTS'][1][ $page1 ]->post_type ?? '' )
		&& 'page' === ( $GLOBALS['MS_POSTS'][2][ $page2 ]->post_type ?? '' ),
	"each site links its own owned page in its own store (blog1={$page1}, blog2={$page2}; numeric equality across blogs is normal)",
	"page linkage broken: blog1={$page1} blog2={$page2}"
);
ms_check(
	'M1-per-site-caps',
	isset( $GLOBALS['MS_ROLES'][1] ) && isset( $GLOBALS['MS_ROLES'][2] )
		&& $GLOBALS['MS_ROLES'][1]->has_cap( 'manage_hal_frontend_dashboard' )
		&& $GLOBALS['MS_ROLES'][2]->has_cap( 'manage_hal_frontend_dashboard' ),
	'both sites granted the HAL capability on their own administrator roles',
	'capability grant missing on a site'
);
$posts1_m1 = count( $GLOBALS['MS_POSTS'][1] ?? array() );
$posts2_m1 = count( $GLOBALS['MS_POSTS'][2] ?? array() );

/* ── M2: repeat network activation is idempotent ── */

try {
	HAL_Frontend_Dashboard_Installer::activate( true );
	$m2_code = '';
} catch ( Throwable $e ) {
	$m2_code = get_class( $e ) . ':' . $e->getMessage();
}
ms_check(
	'M2-repeat-idempotent',
	'' === $m2_code
		&& $page1 === (int) ( $GLOBALS['MS_OPTIONS'][1]['hal_frontend_dashboard_page_id'] ?? 0 )
		&& $page2 === (int) ( $GLOBALS['MS_OPTIONS'][2]['hal_frontend_dashboard_page_id'] ?? 0 )
		&& $posts1_m1 === count( $GLOBALS['MS_POSTS'][1] ?? array() )
		&& $posts2_m1 === count( $GLOBALS['MS_POSTS'][2] ?? array() ),
	'second network activation reuses both owned pages and creates nothing new',
	"repeat diverged: {$m2_code}"
);

/* ── M3: per-site isolation (settings, secrets, pages, tables) ── */

switch_to_blog( 1 );
$save1 = HAL_Frontend_Dashboard_Settings_Repository::save( array( 'features' => array( 'posts' => false ) ) );
$set1 = HAL_Frontend_Dashboard_Secret_Store::set( 'ai_direct_key', 'site-one-secret' );
switch_to_blog( 2 );
$save2 = HAL_Frontend_Dashboard_Settings_Repository::save( array( 'features' => array( 'posts' => true ) ) );
$get2_settings = HAL_Frontend_Dashboard_Settings_Repository::get_all();
$has2 = HAL_Frontend_Dashboard_Secret_Store::has( 'ai_direct_key' );
$get2_secret = HAL_Frontend_Dashboard_Secret_Store::get( 'ai_direct_key' );
restore_current_blog();
$get1_settings = null;
$get1_secret = null;
switch_to_blog( 1 );
$get1_settings = HAL_Frontend_Dashboard_Settings_Repository::get_all();
$get1_secret = HAL_Frontend_Dashboard_Secret_Store::get( 'ai_direct_key' );
$raw1 = $GLOBALS['MS_OPTIONS'][1]['hal_frontend_dashboard_secrets'] ?? null;
restore_current_blog();
$raw2 = $GLOBALS['MS_OPTIONS'][2]['hal_frontend_dashboard_secrets'] ?? null;
ms_check(
	'M3-settings-isolated',
	true === $save1 && true === $save2
		&& false === ( $get1_settings['features']['posts'] ?? null )
		&& true === ( $get2_settings['features']['posts'] ?? null ),
	'site settings differ per blog (posts off on blog 1, on on blog 2)',
	'settings leaked across blogs'
);
ms_check(
	'M3-secrets-isolated',
	true === $set1 && 'site-one-secret' === $get1_secret
		&& false === $has2 && is_wp_error( $get2_secret )
		&& is_array( $raw1 ) && null === $raw2,
	'secret rows live per blog (set+roundtrip on blog 1, absent on blog 2, ciphertexts never cross)',
	'secret isolation broken'
);

require_once ABSPATH . 'wp-admin/includes/upgrade.php';
$schemas = hossam_dashboard_table_schemas();
switch_to_blog( 1 );
hossam_dashboard_ensure_table( $schemas['messages'] );
$tables1 = $GLOBALS['wpdb']->ms_tables();
$ver1 = get_option( 'hossam_messages_db_version', '' );
switch_to_blog( 2 );
hossam_dashboard_ensure_table( $schemas['messages'] );
$tables2 = $GLOBALS['wpdb']->ms_tables();
$ver2 = get_option( 'hossam_messages_db_version', '' );
restore_current_blog();
ms_check(
	'M3-tables-isolated',
	in_array( 'wp_hossam_messages', $tables1, true ) && in_array( 'wp_2_hossam_messages', $tables2, true )
		&& '' !== $ver1 && '' !== $ver2,
	'messages tables are blog-prefixed per site (shared DB sees both names) with independent schema versions',
	'tables: ' . json_encode( $tables1 ) . ' / ' . json_encode( $tables2 )
);

/* ── M3b: per-blog caches on URL/table/profile paths ── */

require_once $project . '/runtime/adapters/wpml.php';
require_once $project . '/runtime/adapters/amelia.php';
require_once $project . '/runtime/core/setup.php';
$GLOBALS['wpdb']->ms_record_table( 'wp_amelia_appointments', array( 'id' ), array( 'PRIMARY' ) );
$ms_url1 = $ms_url2 = null;
switch_to_blog( 1 );
$ms_url1 = hossam_dashboard_url();
$ms_url1_again = hossam_dashboard_url();
$ms_wpml1 = hossam_wpml_url( 'hello' );
$ms_byid1 = hossam_wpml_url_by_id( 101 );
$ms_amelia1 = hossam_amelia_table_exists( 'amelia_appointments' );
switch_to_blog( 2 );
$ms_url2 = hossam_dashboard_url();
$ms_wpml2 = hossam_wpml_url( 'hello' );
$ms_byid2 = hossam_wpml_url_by_id( 101 );
$ms_amelia2 = hossam_amelia_table_exists( 'amelia_appointments' );
$ms_amelia_bad = hossam_amelia_table_exists( 'nope' );
switch_to_blog( 1 );
$ms_amelia1_again = hossam_amelia_table_exists( 'amelia_appointments' );
restore_current_blog();
ms_check(
	'M3b-url-caches',
	'https://one.test/?p=101' === $ms_url1 && 'https://two.test/?p=101' === $ms_url2
		&& $ms_url1 === $ms_url1_again
		&& false !== strpos( $ms_wpml1, 'https://one.test' ) && 'https://example.test/hello/' === $ms_wpml2
		&& 'https://one.test/?p=101' === $ms_byid1 && 'https://two.test/?p=101' === $ms_byid2,
	'dashboard/adapter URLs resolve per blog and stay stable on repeat calls',
	"urls: {$ms_url1} / {$ms_url2} / {$ms_wpml1} / {$ms_wpml2} / {$ms_byid1} / {$ms_byid2}"
);
ms_check(
	'M3b-table-cache',
	true === $ms_amelia1 && false === $ms_amelia2 && true === $ms_amelia1_again && false === $ms_amelia_bad,
	'table existence is cached per blog (present on blog 1 only, stable, unknown suffix rejected)',
	"amelia: b1={$ms_amelia1} b2={$ms_amelia2} b1again={$ms_amelia1_again}"
);
$GLOBALS['MS_FILTER_HOOKS']['hossam_ai_runtime_profile'][] = static function ( $profile ) {
	$base = array( 'per_minute' => 60, 'per_day' => 1000, 'concurrent' => 5, 'max_attempts' => 3, 'stale_pending' => 60, 'processing_deadline' => 300, 'wp_ai_client_timeout' => 10, 'direct_key_timeout' => 10 );
	if ( 2 === get_current_blog_id() ) {
		$base['per_minute'] = 120;
	}
	return $base;
};
switch_to_blog( 1 );
$ms_prof1 = hossam_ai_get_runtime_profile();
$ms_prof1_again = hossam_ai_get_runtime_profile();
switch_to_blog( 2 );
$ms_prof2 = hossam_ai_get_runtime_profile();
restore_current_blog();
ms_check(
	'M3b-profile-cache',
	is_array( $ms_prof1 ) && is_array( $ms_prof2 )
		&& 60 === ( $ms_prof1['per_minute'] ?? null ) && 120 === ( $ms_prof2['per_minute'] ?? null )
		&& $ms_prof1 === $ms_prof1_again,
	'AI runtime profile snapshots per blog (60 vs 120) and stay stable on repeat calls',
	'profile: ' . json_encode( $ms_prof1 ) . ' / ' . json_encode( $ms_prof2 )
);

/* ── M4: capabilities — super admin vs site admin; auto-update gate ── */

$GLOBALS['MS_USER_ID'] = 1;
$super_net = current_user_can( 'manage_network' );
$GLOBALS['MS_USER_ID'] = 2;
$site_net = current_user_can( 'manage_network' );
$site_opts = current_user_can( 'manage_options' );
$GLOBALS['MS_USER_ID'] = 1;
ms_check(
	'M4-caps',
	true === $super_net && false === $site_net && true === $site_opts,
	'super admin holds manage_network, site admin does not (but keeps manage_options)',
	"caps: super_net={$super_net} site_net={$site_net} site_opts={$site_opts}"
);
$offer = array(
	'plugin' => 'hal-frontend-dashboard/hal-frontend-dashboard.php',
	'slug' => 'hal-frontend-dashboard',
	'new_version' => '2.0.0',
	'package' => 'https://github.com/hossamadellaw/hal-frontend-dashboard/releases/download/v2.0.0/hal-frontend-dashboard-2.0.0.zip',
);
$GLOBALS['MS_USER_ID'] = 1;
$auto_super = HAL_Frontend_Dashboard_Update_Bridge::filter_auto_update( false, $offer );
$GLOBALS['MS_USER_ID'] = 2;
$auto_site = HAL_Frontend_Dashboard_Update_Bridge::filter_auto_update( false, $offer );
$GLOBALS['MS_USER_ID'] = 0;
$auto_cron = HAL_Frontend_Dashboard_Update_Bridge::filter_auto_update( false, $offer );
$GLOBALS['MS_USER_ID'] = 1;
ms_check(
	'M4-auto-update-gate',
	true === $auto_super && false === $auto_site && true === $auto_cron,
	'auto-update applies for super admin and system (cron/CLI) contexts, passes through for site admins on multisite',
	"gate: super=" . var_export( $auto_super, true ) . ' site=' . var_export( $auto_site, true ) . ' cron=' . var_export( $auto_cron, true )
);

/* ── M5: one shared release state across the network ── */

$manager = new HAL_Frontend_Dashboard_Release_Manager( $mu_dir );
$path_blog1 = null;
$path_blog2 = null;
$active_blog1 = null;
$active_blog2 = null;
switch_to_blog( 1 );
$path_blog1 = $manager->state_path( 'active.json' );
$active_blog1 = $manager->read_pointer( 'active.json' );
switch_to_blog( 2 );
$path_blog2 = $manager->state_path( 'active.json' );
$active_blog2 = $manager->read_pointer( 'active.json' );
restore_current_blog();
ms_check(
	'M5-shared-pointers',
	$path_blog1 === $path_blog2 && is_array( $active_blog1 ) && $active_blog1 === $active_blog2,
	'single active.json serves both blogs with the identical release',
	'pointers diverged across blogs'
);
$v2_id = '2.0.0+' . str_repeat( 'd', 40 );
$v2_stage = $ws_root . '/stage-v2';
ms_rmdir( $v2_stage );
@mkdir( $v2_stage, 0777, true );
file_put_contents( $v2_stage . '/bootstrap.php', "<?php\n// multisite v2 candidate\n" );
$manager->install_release( $v2_id, $v2_stage );
$v2_manifest = array(
	'release_id' => $v2_id, 'version' => '2.0.0', 'release_sequence' => 2,
	'archive_sha256' => str_repeat( 'd', 64 ),
);
$manager->promote_runtime( $v2_manifest, static function (): bool { return true; }, 'loader-1.0.0' );
$seen_v2_blog1 = null;
$seen_v2_blog2 = null;
switch_to_blog( 1 );
$seen_v2_blog1 = $manager->read_pointer( 'active.json' );
switch_to_blog( 2 );
$seen_v2_blog2 = $manager->read_pointer( 'active.json' );
restore_current_blog();
ms_check(
	'M5-shared-update',
	is_array( $seen_v2_blog1 ) && $v2_id === ( $seen_v2_blog1['release_id'] ?? '' )
		&& $seen_v2_blog1 === $seen_v2_blog2,
	'one shared promotion to v2 is observed identically from both blogs (previous kept)',
	'v2 not shared: ' . json_encode( $seen_v2_blog1 ) . ' / ' . json_encode( $seen_v2_blog2 )
);
$v3_id = '3.0.0+' . str_repeat( 'e', 40 );
$v3_stage = $ws_root . '/stage-v3';
ms_rmdir( $v3_stage );
@mkdir( $v3_stage, 0777, true );
file_put_contents( $v3_stage . '/bootstrap.php', "<?php\n// multisite v3 candidate\n" );
$manager->install_release( $v3_id, $v3_stage );
$v3_manifest = array(
	'release_id' => $v3_id, 'version' => '3.0.0', 'release_sequence' => 3,
	'archive_sha256' => str_repeat( 'e', 64 ),
);
try {
	$manager->promote_runtime( $v3_manifest, static function (): bool { return false; }, 'loader-1.0.0' );
	$v3_code = '';
} catch ( Throwable $e ) {
	$v3_code = $e->getMessage();
}
ms_check(
	'M5-shared-rollback-setup',
	'HAL_PROMOTION_HEALTH_FAILED' === $v3_code,
	'failed v3 health aborts the promotion (rollback path engaged)',
	'unexpected code: ' . $v3_code
);
$seen_v3_blog1 = null;
$seen_v3_blog2 = null;
$digest_state = null;
switch_to_blog( 1 );
$seen_v3_blog1 = $manager->read_pointer( 'active.json' );
switch_to_blog( 2 );
$seen_v3_blog2 = $manager->read_pointer( 'active.json' );
restore_current_blog();
$digest_state = $manager->read_state_file( 'failed-digests.json' );
$digests = is_array( $digest_state ) && is_array( $digest_state['digests'] ?? null ) ? $digest_state['digests'] : array();
ms_check(
	'M5-shared-rollback',
	$v2_id === ( $seen_v3_blog1['release_id'] ?? '' ) && $seen_v3_blog1 === $seen_v3_blog2
		&& isset( $digests[ str_repeat( 'e', 64 ) ] ),
	'failed v3 never became active on either blog (both still v2) with its digest suppressed',
	'rollback not shared: ' . json_encode( $seen_v3_blog1 ) . ' / ' . json_encode( $seen_v3_blog2 )
);

/* ── M7: shared network import through the REAL import_candidate path (MS-01) ── */

if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', $ws_root . '/wp-plugins' );
}
$v4_id = '4.0.0+' . str_repeat( 'a', 40 );
$v5_id = '5.0.0+' . str_repeat( 'a', 40 );
ms_build_carrier( $project, $ws_root, 'wp-plugins/hal-frontend-dashboard', $secret, '4.0.0', 4 );

// A site admin (uid 2: manage_options, no manage_network) fails closed
// BEFORE any write: the shared network release never moves for them.
$GLOBALS['MS_USER_ID'] = 2;
switch_to_blog( 1 );
update_option( 'hal_frontend_dashboard_update_candidate', array( 'plugin' => 'hal-frontend-dashboard/hal-frontend-dashboard.php', 'time' => time() ), false );
try {
	( new HAL_Frontend_Dashboard_Release_Manager( $mu_dir ) )->import_candidate( static function (): bool { return true; } );
	$m7_unauth_code = '';
} catch ( Throwable $e ) {
	$m7_unauth_code = $e->getMessage();
}
restore_current_blog();
ms_check(
	'M7-site-admin-refused',
	'HAL_IMPORT_MULTISITE_UNAUTHORIZED' === $m7_unauth_code,
	'site admin import refused fail-closed (network capability preserved)',
	'unexpected code: ' . $m7_unauth_code
);
$still1 = null;
$still2 = null;
$cand_kept = null;
switch_to_blog( 1 );
$still1 = $manager->read_pointer( 'active.json' );
$cand_kept = get_option( 'hal_frontend_dashboard_update_candidate', false );
switch_to_blog( 2 );
$still2 = $manager->read_pointer( 'active.json' );
restore_current_blog();
ms_check(
	'M7-refusal-keeps-state',
	$v2_id === ( $still1['release_id'] ?? '' ) && $still1 === $still2 && is_array( $cand_kept ),
	'refused import moved nothing (both blogs still v2) and kept the candidate for forensics',
	'state moved: ' . json_encode( $still1 ) . ' / ' . json_encode( $still2 )
);

// System context (cron/CLI, uid 0) promotes v4 through the real path.
$GLOBALS['MS_USER_ID'] = 0;
switch_to_blog( 1 );
try {
	$m7_imported = ( new HAL_Frontend_Dashboard_Release_Manager( $mu_dir ) )->import_candidate( static function (): bool { return true; } );
	$m7_import_code = '';
} catch ( Throwable $e ) {
	$m7_imported = '';
	$m7_import_code = $e->getMessage();
}
restore_current_blog();
ms_check(
	'M7-shared-import',
	$v4_id === $m7_imported,
	'system import promoted v4 through candidate→import→promote (no direct promote_runtime)',
	'unexpected: ' . $m7_import_code
);
$seen_v4_blog1 = null;
$seen_v4_blog2 = null;
$cand_gone = null;
switch_to_blog( 1 );
$seen_v4_blog1 = $manager->read_pointer( 'active.json' );
$cand_gone = get_option( 'hal_frontend_dashboard_update_candidate', false );
switch_to_blog( 2 );
$seen_v4_blog2 = $manager->read_pointer( 'active.json' );
restore_current_blog();
ms_check(
	'M7-shared-import-pointers',
	$v4_id === ( $seen_v4_blog1['release_id'] ?? '' ) && $seen_v4_blog1 === $seen_v4_blog2 && false === $cand_gone,
	'v4 observed identically from both blogs and the candidate consumed',
	'import not shared: ' . json_encode( $seen_v4_blog1 ) . ' / ' . json_encode( $seen_v4_blog2 )
);

// Failed v5 through the same real path rolls back identically. It reuses
// the active loader (4.0.0) so the failure lands on the runtime leg —
// the exact leg M5's direct promote_runtime() call exercised.
ms_build_carrier( $project, $ws_root, 'wp-plugins/hal-frontend-dashboard', $secret, '5.0.0', 5, '4.0.0' );
switch_to_blog( 1 );
update_option( 'hal_frontend_dashboard_update_candidate', array( 'plugin' => 'hal-frontend-dashboard/hal-frontend-dashboard.php', 'time' => time() ), false );
try {
	( new HAL_Frontend_Dashboard_Release_Manager( $mu_dir ) )->import_candidate( static function (): bool { return false; } );
	$m7_v5_code = '';
} catch ( Throwable $e ) {
	$m7_v5_code = $e->getMessage();
}
restore_current_blog();
ms_check(
	'M7-shared-import-rollback-setup',
	'HAL_PROMOTION_HEALTH_FAILED' === $m7_v5_code,
	'failed v5 health aborts the real-path promotion (rollback path engaged)',
	'unexpected code: ' . $m7_v5_code
);
$seen_v5_blog1 = null;
$seen_v5_blog2 = null;
switch_to_blog( 1 );
$seen_v5_blog1 = $manager->read_pointer( 'active.json' );
switch_to_blog( 2 );
$seen_v5_blog2 = $manager->read_pointer( 'active.json' );
restore_current_blog();
$digest_state5 = $manager->read_state_file( 'failed-digests.json' );
$found5 = false;
foreach ( ( is_array( $digest_state5 ) && is_array( $digest_state5['digests'] ?? null ) ? $digest_state5['digests'] : array() ) as $entry ) {
	if ( $v5_id === ( $entry['release_id'] ?? '' ) ) {
		$found5 = true;
		break;
	}
}
ms_check(
	'M7-shared-import-rollback',
	$v4_id === ( $seen_v5_blog1['release_id'] ?? '' ) && $seen_v5_blog1 === $seen_v5_blog2 && $found5,
	'failed v5 never became active on either blog (both still v4) with its digest suppressed',
	'rollback not shared: ' . json_encode( $seen_v5_blog1 ) . ' / ' . json_encode( $seen_v5_blog2 )
);
$GLOBALS['MS_USER_ID'] = 1;

/* ── M6: late-joined site via the REAL setup.php hook ── */

if ( ! defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT' ) ) {
	define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT', $project . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR );
}
if ( ! defined( 'HAL_FRONTEND_DASHBOARD_RELEASE_ID' ) ) {
	define( 'HAL_FRONTEND_DASHBOARD_RELEASE_ID', '1.0.0+' . str_repeat( 'a', 40 ) );
}
if ( ! defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION' ) ) {
	define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION', '1.0.0' );
}
require_once $project . '/runtime/core/setup.php';
$init_cbs = array();
foreach ( $GLOBALS['MS_HOOKS']['wp_initialize_site'] ?? array() as $cb ) {
	$init_cbs[] = $cb;
}
ms_check(
	'M6-hook-registered',
	1 === count( $init_cbs ) && $init_cbs[0] instanceof Closure,
	'the real setup.php registers exactly one wp_initialize_site consumer (lazy provisioner)',
	'hook consumers: ' . count( $init_cbs )
);
$GLOBALS['MS_SITES'][] = 3;
foreach ( $init_cbs as $cb ) {
	$cb( (object) array( 'blog_id' => 3 ) );
}
$page3 = (int) ( $GLOBALS['MS_OPTIONS'][3]['hal_frontend_dashboard_page_id'] ?? 0 );
ms_check(
	'M6-new-site-provisioned',
	$page3 > 0 && 'page' === ( $GLOBALS['MS_POSTS'][3][ $page3 ]->post_type ?? '' )
		&& isset( $GLOBALS['MS_ROLES'][3] ) && $GLOBALS['MS_ROLES'][3]->has_cap( 'manage_hal_frontend_dashboard' ),
	"late-joined blog 3 provisioned its own page ({$page3}) and capability",
	"blog3 page={$page3}"
);
ms_check(
	'M6-others-untouched',
	$page1 === (int) ( $GLOBALS['MS_OPTIONS'][1]['hal_frontend_dashboard_page_id'] ?? 0 )
		&& $page2 === (int) ( $GLOBALS['MS_OPTIONS'][2]['hal_frontend_dashboard_page_id'] ?? 0 )
		&& $posts1_m1 === count( $GLOBALS['MS_POSTS'][1] ?? array() )
		&& $posts2_m1 === count( $GLOBALS['MS_POSTS'][2] ?? array() ),
	'blogs 1-2 keep their pages with no extra posts from blog-3 provisioning',
	'older sites changed'
);

/* ── Report ── */

$ms_pass = 0;
foreach ( $GLOBALS['MS_RESULTS'] as $result ) {
	if ( $result['ok'] ) {
		$ms_pass++;
	}
}
$ms_total = count( $GLOBALS['MS_RESULTS'] );
echo "MS RESULT: $ms_pass/$ms_total checks passed\n";
if ( $ms_pass === $ms_total ) {
	echo "MS-VERDICT main ALL-ASSERTIONS-HELD\n";
	exit( 0 );
}
echo "MS-VERDICT main FAILED\n";
exit( 1 );
