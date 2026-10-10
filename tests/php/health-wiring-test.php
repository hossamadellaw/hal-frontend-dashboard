<?php
/**
 * HAL Frontend Dashboard — v1.0.3 health wiring regression test.
 *
 * Local, isolated harness: no live WordPress, no network, no database.
 * It stubs the WordPress functions/objects the actual load path touches,
 * then requires the REAL runtime/bootstrap.php of the examined runtime and
 * verifies the v1.0.3 Item-1 wiring:
 *
 *   NORMAL     — with release context: both health handlers registered
 *                exactly once with a valid callable, without this test
 *                loading the Health class or registering on bootstrap's
 *                behalf; runtime_ready fired; the real readiness filter
 *                evaluates false pre-init (live state, no migrations yet).
 *   PRELOADED  — Health class loaded beforehand (includes/ copy for the
 *                source runtime, infrastructure/ copy for a --runtime-root
 *                tree): no redeclaration, class file unchanged, both
 *                handlers still registered. Runs in its own process,
 *                independent from NORMAL.
 *   NOCONTEXT  — without release context: bootstrap exits silently
 *                (exit 0, no hooks, no handlers, no runtime_ready).
 *   FUNCTIONAL — one unauthenticated request path: verified migration pass
 *                via the real init callbacks makes the REAL readiness
 *                filter true; a valid temporary challenge plus the callback
 *                RECORDED by bootstrap yields the correct ACK and consumes
 *                the challenge. HTTP transport and wp_send_json are
 *                substituted at the boundary; success/__return_true are
 *                never fabricated and register() is never called manually.
 *
 * Usage:
 *   php tests/php/health-wiring-test.php
 *   php tests/php/health-wiring-test.php --runtime-root=<absolute-path>
 *
 * The default examines the source runtime. With --runtime-root the test
 * examines the unpacked runtime tree whose directory directly contains
 * bootstrap.php (as produced from the carrier payload). When the flag is
 * given and a required file is missing there, the test fails outright —
 * there is intentionally no fallback to the source tree.
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

if ( PHP_SAPI !== 'cli' ) {
	echo "This harness must run under CLI PHP.\n";
	exit( 1 );
}

$GLOBALS['HAL_HW_HOOKS']   = array();
$GLOBALS['HAL_HW_ACTIONS'] = array();
$GLOBALS['HAL_HW_OPTIONS'] = array();
$GLOBALS['HAL_HW_RESULTS'] = array();
$GLOBALS['HAL_HW_JSON']    = null;

/* ────────────────────────────────────────────────────────────────
 * Result bookkeeping
 * ──────────────────────────────────────────────────────────────── */

function hal_hw_check( string $id, bool $condition, string $pass, string $fail = '' ): void {
	$GLOBALS['HAL_HW_RESULTS'][] = array(
		'id'   => $id,
		'ok'   => $condition,
		'pass' => $pass,
		'fail' => $fail,
	);
}

function hal_hw_report( string $title ): bool {
	$pass_count = 0;
	$total      = count( $GLOBALS['HAL_HW_RESULTS'] );
	echo $title . "\n";
	foreach ( $GLOBALS['HAL_HW_RESULTS'] as $result ) {
		if ( $result['ok'] ) {
			$pass_count++;
			echo "PASS [{$result['id']}] {$result['pass']}\n";
		} else {
			echo "FAIL [{$result['id']}] {$result['fail']}\n";
		}
	}
	echo "RESULT: {$pass_count}/{$total} checks passed\n";
	return $pass_count === $total;
}

/* ────────────────────────────────────────────────────────────────
 * Hook registry (WordPress semantics only — never project logic)
 * ──────────────────────────────────────────────────────────────── */

function hal_hw_register_hook( string $hook_name, $callback, int $priority ): void {
	$GLOBALS['HAL_HW_HOOKS'][ $hook_name ][] = array(
		'callback' => $callback,
		'priority' => $priority,
	);
}

function hal_hw_sorted( string $hook_name ): array {
	$entries = $GLOBALS['HAL_HW_HOOKS'][ $hook_name ] ?? array();
	usort(
		$entries,
		static function ( array $a, array $b ): int {
			return $a['priority'] <=> $b['priority'];
		}
	);
	return $entries;
}

/* ────────────────────────────────────────────────────────────────
 * WordPress function stubs (WordPress only — never project logic)
 * ──────────────────────────────────────────────────────────────── */

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook_name, $callback = '', int $priority = 10, int $accepted_args = 1 ): bool {
		hal_hw_register_hook( $hook_name, $callback, $priority );
		return true;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook_name, $callback = '', int $priority = 10, int $accepted_args = 1 ): bool {
		hal_hw_register_hook( $hook_name, $callback, $priority );
		return true;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook_name, ...$args ): void {
		$GLOBALS['HAL_HW_ACTIONS'][ $hook_name ] = ( $GLOBALS['HAL_HW_ACTIONS'][ $hook_name ] ?? 0 ) + 1;
		foreach ( hal_hw_sorted( $hook_name ) as $entry ) {
			call_user_func_array( $entry['callback'], $args );
		}
	}
}
if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook_name ): int {
		return $GLOBALS['HAL_HW_ACTIONS'][ $hook_name ] ?? 0;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook_name, $value, ...$args ) {
		foreach ( hal_hw_sorted( $hook_name ) as $entry ) {
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
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default_value = false ) {
		if ( array_key_exists( $option, $GLOBALS['HAL_HW_OPTIONS'] ) ) {
			return $GLOBALS['HAL_HW_OPTIONS'][ $option ];
		}
		return $default_value;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, $value, $autoload = null ): bool {
		$GLOBALS['HAL_HW_OPTIONS'][ $option ] = $value;
		return true;
	}
}
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin(): bool {
		return false;
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
	function get_permalink( $post ) {
		$id = is_object( $post ) ? ( $post->ID ?? 0 ) : (int) $post;
		return 'https://example.test/?p=' . $id;
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		return 'https://example.test/' . ltrim( $path, '/' );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return $value;
	}
}
if ( ! class_exists( 'Hal_Hw_Json_Sent' ) ) {
	/**
	 * Faithful boundary emulation: real wp_send_json*() ends the request
	 * (wp_die), so the stub records the payload and unwinds via an
	 * exception instead of returning into the caller.
	 */
	class Hal_Hw_Json_Sent extends Exception {
	}
}
if ( ! function_exists( 'wp_send_json' ) ) {
	function wp_send_json( $response, $status_code = null ): void {
		$GLOBALS['HAL_HW_JSON'] = array( 'type' => 'success', 'body' => $response );
		throw new Hal_Hw_Json_Sent( 'wp_send_json' );
	}
}
if ( ! function_exists( 'wp_send_json_error' ) ) {
	function wp_send_json_error( $data = null, $status_code = null ): void {
		$GLOBALS['HAL_HW_JSON'] = array( 'type' => 'error', 'body' => $data, 'status' => $status_code );
		throw new Hal_Hw_Json_Sent( 'wp_send_json_error' );
	}
}

$GLOBALS['HAL_HW_SCHEDULED'] = $GLOBALS['HAL_HW_SCHEDULED'] ?? array();

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( string $hook, array $args = array() ) {
		$key = $hook . '|' . json_encode( array_values( $args ) );
		return $GLOBALS['HAL_HW_SCHEDULED'][ $key ] ?? false;
	}
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = array() ): bool {
		$key = $hook . '|' . json_encode( array_values( $args ) );
		$GLOBALS['HAL_HW_SCHEDULED'][ $key ] = $timestamp;
		return true;
	}
}
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( int $timestamp, string $hook, array $args = array() ): bool {
		$key = $hook . '|' . json_encode( array_values( $args ) );
		$GLOBALS['HAL_HW_SCHEDULED'][ $key ] = $timestamp;
		return true;
	}
}

/* ────────────────────────────────────────────────────────────────
 * $wpdb mock (WordPress object stub)
 * ──────────────────────────────────────────────────────────────── */

if ( ! class_exists( 'Hal_Hw_WPDB' ) ) {
	final class Hal_Hw_WPDB {
		public string $prefix = 'wp_';
		/** @var string */
		public $last_error = '';
		/** @var array<string, array{columns:string[], indexes:string[]}> */
		private array $tables = array();

		public function hal_hw_record_table( string $table, array $columns, array $indexes ): void {
			$this->tables[ $table ] = array(
				'columns' => $columns,
				'indexes' => $indexes,
			);
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
				$name = str_replace(
					array( '\\_', '\\%', '\\\\' ),
					array( '_', '%', '\\' ),
					$match[1]
				);
				return array_key_exists( $name, $this->tables ) ? $name : null;
			}
			return null;
		}
		/**
		 * @return string[]
		 */
		public function get_col( string $query, int $column_offset = 0 ): array {
			if ( preg_match( '/^SHOW COLUMNS FROM `(.+)`/', $query, $match ) ) {
				return isset( $this->tables[ $match[1] ] )
					? array_values( $this->tables[ $match[1] ]['columns'] )
					: array();
			}
			if ( preg_match( '/^SHOW INDEX FROM `(.+)`/', $query, $match ) ) {
				return isset( $this->tables[ $match[1] ] )
					? array_values( $this->tables[ $match[1] ]['indexes'] )
					: array();
			}
			return array();
		}
	}
}

/* ────────────────────────────────────────────────────────────────
 * dbDelta driver stub (WordPress upgrade API stub — parses the real
 * CREATE TABLE statements the runtime passes and records them).
 * ──────────────────────────────────────────────────────────────── */

$GLOBALS['HAL_HW_DBDELTA_CALLS'] = 0;
if ( ! function_exists( 'dbDelta' ) ) {
	function dbDelta( $queries = array(), $execute = true ) {
		global $wpdb;
		$GLOBALS['HAL_HW_DBDELTA_CALLS']++;
		$parsed = array();
		foreach ( (array) $queries as $sql ) {
			if ( ! is_string( $sql ) ) {
				continue;
			}
			if ( ! preg_match( '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?\s*\(/i', $sql, $match ) ) {
				continue;
			}
			$table = $match[1];
			$open  = strpos( $sql, '(' );
			$close = strrpos( $sql, ')' );
			if ( false === $open || false === $close || $close <= $open ) {
				continue;
			}
			$body    = substr( $sql, $open + 1, $close - $open - 1 );
			$columns = array();
			$indexes = array();
			foreach ( preg_split( '/\r\n|\r|\n/', $body ) as $line ) {
				$line = trim( $line, " \t," );
				if ( '' === $line ) {
					continue;
				}
				if ( preg_match( '/^PRIMARY\s+KEY/i', $line ) ) {
					$indexes[] = 'PRIMARY';
					continue;
				}
				if ( preg_match( '/^(?:UNIQUE\s+KEY|UNIQUE\s+INDEX|KEY|INDEX)\s+([A-Za-z0-9_]+)/i', $line, $key_match ) ) {
					$indexes[] = $key_match[1];
					continue;
				}
				$parts = preg_split( '/\s+/', $line );
				if ( isset( $parts[0] ) && '' !== $parts[0] ) {
					$columns[] = $parts[0];
				}
			}
			$parsed[ $table ] = array( 'columns' => $columns, 'indexes' => $indexes );
		}
		if ( is_object( $wpdb ) && method_exists( $wpdb, 'hal_hw_record_table' ) ) {
			foreach ( $parsed as $table => $schema ) {
				$wpdb->hal_hw_record_table( $table, $schema['columns'], $schema['indexes'] );
			}
		}
		return array();
	}
}

/* ────────────────────────────────────────────────────────────────
 * Runtime-root resolution (no fallback to source when flagged)
 * ──────────────────────────────────────────────────────────────── */

/**
 * @return array{root:string, from_flag:bool}|array{error:string}
 */
function hal_hw_cli_request(): array {
	$flag = null;
	$mode = 'main';
	foreach ( $GLOBALS['argv'] as $index => $arg ) {
		if ( 0 === $index ) {
			continue;
		}
		if ( str_starts_with( (string) $arg, '--runtime-root=' ) ) {
			$flag = substr( (string) $arg, strlen( '--runtime-root=' ) );
			continue;
		}
		if ( 'main' === $mode && '' !== (string) $arg ) {
			$mode = (string) $arg;
		}
	}
	return array( 'mode' => $mode, 'flag' => $flag );
}

/**
 * Resolve the examined runtime directory (trailing separator included).
 * With --runtime-root every required file must exist there; otherwise the
 * run fails outright — never falls back to the source tree.
 */
function hal_hw_resolve_runtime_root( ?string $flag ): string {
	$project = dirname( __DIR__, 2 );
	if ( null === $flag || '' === $flag ) {
		return $project . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR;
	}
	if ( 1 !== preg_match( '#^(?:[A-Za-z]:[\\\\/]|\\\\\\\\|/)#', $flag ) ) {
		fwrite( STDERR, "health-wiring-test: --runtime-root must be an absolute path.\n" );
		exit( 1 );
	}
	$root = rtrim( $flag, '/\\' ) . DIRECTORY_SEPARATOR;
	foreach ( array( 'bootstrap.php', 'infrastructure' . DIRECTORY_SEPARATOR . 'class-health-check.php' ) as $required ) {
		if ( ! is_file( $root . $required ) ) {
			fwrite( STDERR, "health-wiring-test: required file missing under --runtime-root: {$required}\n" );
			exit( 1 );
		}
	}
	return $root;
}

function hal_hw_project_root(): string {
	return dirname( __DIR__, 2 );
}

function hal_hw_remove_dir( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $item ) {
		$path = $item->getPathname();
		if ( $item->isLink() || ! $item->isDir() ) {
			@unlink( $path );
		} else {
			@rmdir( $path );
		}
	}
	@rmdir( $dir );
}

/**
 * Temporary ABSPATH scaffold (outside the examined runtime and outside
 * the package, cleaned on shutdown).
 */
function hal_hw_make_abspath_scaffold(): string {
	$base     = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hal-hw-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
	$includes = $base . DIRECTORY_SEPARATOR . 'wp-admin' . DIRECTORY_SEPARATOR . 'includes';
	if ( ! is_dir( $includes ) && ! @mkdir( $includes, 0777, true ) ) {
		fwrite( STDERR, "health-wiring-test: cannot create the temporary ABSPATH scaffold.\n" );
		exit( 1 );
	}
	// dbDelta() itself is stubbed in this file; the scaffold file only
	// satisfies require paths pointing at wp-admin/includes/upgrade.php.
	file_put_contents( $includes . DIRECTORY_SEPARATOR . 'upgrade.php', "<?php\n// dbDelta is stubbed by the harness driver.\n" );
	register_shutdown_function( 'hal_hw_remove_dir', $base );
	return $base . DIRECTORY_SEPARATOR;
}

function hal_hw_define_release_context( string $runtime_root, string $mu_dir ): void {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', hal_hw_make_abspath_scaffold() );
	}
	if ( ! defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT' ) ) {
		define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT', $runtime_root );
	}
	if ( ! defined( 'HAL_FRONTEND_DASHBOARD_RELEASE_ID' ) ) {
		define( 'HAL_FRONTEND_DASHBOARD_RELEASE_ID', '1.0.3+' . str_repeat( 'ab', 20 ) );
	}
	if ( ! defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION' ) ) {
		define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION', '1.0.3' );
	}
	if ( ! defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_URL' ) ) {
		define(
			'HAL_FRONTEND_DASHBOARD_RUNTIME_URL',
			'https://example.test/wp-content/mu-plugins/hal-frontend-dashboard/releases/' . HAL_FRONTEND_DASHBOARD_RELEASE_ID . '/'
		);
	}
	if ( ! defined( 'HAL_FRONTEND_DASHBOARD_VERSION' ) ) {
		define( 'HAL_FRONTEND_DASHBOARD_VERSION', '1.0.0' );
	}
	if ( ! defined( 'HAL_FRONTEND_DASHBOARD_LOADER_ID' ) ) {
		define( 'HAL_FRONTEND_DASHBOARD_LOADER_ID', 'loader-1.0.0' );
	}
	if ( ! defined( 'HAL_FRONTEND_DASHBOARD_LOADER_API' ) ) {
		define( 'HAL_FRONTEND_DASHBOARD_LOADER_API', 1 );
	}
	if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
		define( 'WPMU_PLUGIN_DIR', $mu_dir );
	}
	$GLOBALS['wpdb'] = new Hal_Hw_WPDB();
}

function hal_hw_health_action(): string {
	return 'hal_frontend_dashboard_boot_health';
}

/* ────────────────────────────────────────────────────────────────
 * NORMAL mode — wiring as bootstrap registers it
 * ──────────────────────────────────────────────────────────────── */

function hal_hw_mode_normal( string $runtime_root ): void {
	// The test itself must not load the Health class beforehand: the
	// class below may only appear via the real bootstrap require.
	hal_hw_check(
		'W1',
		! class_exists( 'HAL_Frontend_Dashboard_Health_Check', false ),
		'health class absent before bootstrap (test loads nothing on its behalf)',
		'health class already present before bootstrap — test setup loads it'
	);
	$mu = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hal-hw-mu-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
	@mkdir( $mu, 0777, true );
	register_shutdown_function( 'hal_hw_remove_dir', $mu );
	hal_hw_define_release_context( $runtime_root, $mu );

	require $runtime_root . 'bootstrap.php';

	$action = hal_hw_health_action();
	$auth   = $GLOBALS['HAL_HW_HOOKS'][ 'wp_ajax_' . $action ] ?? array();
	$unauth = $GLOBALS['HAL_HW_HOOKS'][ 'wp_ajax_nopriv_' . $action ] ?? array();

	hal_hw_check(
		'W2',
		1 === count( $auth ),
		'wp_ajax health handler registered exactly once by bootstrap',
		'wp_ajax handler count=' . count( $auth )
	);
	hal_hw_check(
		'W3',
		1 === count( $unauth ),
		'wp_ajax_nopriv health handler registered exactly once by bootstrap',
		'wp_ajax_nopriv handler count=' . count( $unauth )
	);
	$callbacks_valid = false;
	if ( 1 === count( $auth ) && 1 === count( $unauth ) ) {
		$first  = $auth[0]['callback'];
		$second = $unauth[0]['callback'];
		$callbacks_valid = is_array( $first ) && is_array( $second )
			&& ( $first[0] ?? '' ) === 'HAL_Frontend_Dashboard_Health_Check'
			&& ( $first[1] ?? '' ) === 'handle_request'
			&& ( $second[0] ?? '' ) === 'HAL_Frontend_Dashboard_Health_Check'
			&& ( $second[1] ?? '' ) === 'handle_request'
			&& is_callable( $first )
			&& is_callable( $second );
	}
	hal_hw_check(
		'W4',
		$callbacks_valid,
		'both handlers carry a valid callable recorded by bootstrap',
		'handler callback invalid or not callable'
	);
	$ready_count = did_action( 'hal_frontend_dashboard_runtime_ready' );
	hal_hw_check(
		'W5',
		$ready_count >= 1,
		"runtime_ready action fired (did_action={$ready_count})",
		'runtime_ready missing after bootstrap'
	);
	$readiness_hooks = $GLOBALS['HAL_HW_HOOKS']['hal_frontend_dashboard_boot_readiness'] ?? array();
	$uses_stub       = false;
	foreach ( $readiness_hooks as $entry ) {
		if ( '__return_true' === $entry['callback'] ) {
			$uses_stub = true;
		}
	}
	$pre_init = apply_filters(
		'hal_frontend_dashboard_boot_readiness',
		false,
		HAL_FRONTEND_DASHBOARD_RELEASE_ID,
		HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION
	);
	hal_hw_check(
		'W6',
		count( $readiness_hooks ) >= 1 && ! $uses_stub && false === $pre_init,
		'real readiness filter evaluates the live pre-init state (false, no stub)',
		'readiness filter missing, stubbed, or not false pre-init'
	);

	$ok = hal_hw_report( 'health-wiring NORMAL' );
	exit( $ok ? 0 : 1 );
}

/* ────────────────────────────────────────────────────────────────
 * PRELOADED mode — class already loaded before bootstrap
 * ──────────────────────────────────────────────────────────────── */

function hal_hw_mode_preloaded( string $runtime_root, ?string $flag ): void {
	$project = hal_hw_project_root();
	if ( null === $flag || '' === $flag ) {
		$preload = $project . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'class-health-check.php';
		$expect  = 'includes/class-health-check.php';
	} else {
		$preload = $runtime_root . 'infrastructure' . DIRECTORY_SEPARATOR . 'class-health-check.php';
		$expect  = 'infrastructure/class-health-check.php';
	}
	// The includes/ copy is ABSPATH-guarded, so the scaffold must exist
	// before preloading it — exactly like a WordPress request would.
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', hal_hw_make_abspath_scaffold() );
	}
	require_once $preload;
	$before = (string) (new ReflectionClass( 'HAL_Frontend_Dashboard_Health_Check' ))->getFileName();

	$mu = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hal-hw-mu-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
	@mkdir( $mu, 0777, true );
	register_shutdown_function( 'hal_hw_remove_dir', $mu );
	hal_hw_define_release_context( $runtime_root, $mu );

	require $runtime_root . 'bootstrap.php';

	$after  = (string) (new ReflectionClass( 'HAL_Frontend_Dashboard_Health_Check' ))->getFileName();
	$action = hal_hw_health_action();
	$auth   = $GLOBALS['HAL_HW_HOOKS'][ 'wp_ajax_' . $action ] ?? array();
	$unauth = $GLOBALS['HAL_HW_HOOKS'][ 'wp_ajax_nopriv_' . $action ] ?? array();

	hal_hw_check(
		'P1',
		false !== strpos( str_replace( '\\', '/', $before ), $expect ),
		"preloaded from the expected copy ({$expect})",
		'preload path unexpected: ' . $before
	);
	hal_hw_check(
		'P2',
		$before === $after,
		'no redeclaration: class file unchanged by bootstrap',
		'class file changed after bootstrap: ' . $after
	);
	hal_hw_check(
		'P3',
		1 === count( $auth ) && 1 === count( $unauth ),
		'both handlers registered despite the preloaded class',
		'handler counts auth=' . count( $auth ) . ' unauth=' . count( $unauth )
	);

	$ok = hal_hw_report( 'health-wiring PRELOADED' );
	exit( $ok ? 0 : 1 );
}

/* ────────────────────────────────────────────────────────────────
 * NOCONTEXT mode — no release context at all
 * ──────────────────────────────────────────────────────────────── */

function hal_hw_mode_nocontext(): void {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', hal_hw_make_abspath_scaffold() );
	}
	// Deliberately no HAL_FRONTEND_DASHBOARD_RUNTIME_* constants.
	$project = hal_hw_project_root();
	$flag    = hal_hw_cli_request();
	$root    = hal_hw_resolve_runtime_root( $flag['flag'] ?? null );
	require $root . 'bootstrap.php';

	$action = hal_hw_health_action();
	$total  = count( $GLOBALS['HAL_HW_HOOKS'][ 'wp_ajax_' . $action ] ?? array() )
		+ count( $GLOBALS['HAL_HW_HOOKS'][ 'wp_ajax_nopriv_' . $action ] ?? array() );

	hal_hw_check(
		'G1',
		0 === did_action( 'hal_frontend_dashboard_runtime_ready' ),
		'no runtime_ready without release context',
		'runtime_ready fired without release context'
	);
	hal_hw_check(
		'G2',
		0 === $total,
		'no health handlers without release context',
		'handlers registered without release context'
	);
	hal_hw_check(
		'G3',
		0 === count( $GLOBALS['HAL_HW_HOOKS'] ),
		'no hooks at all without release context',
		'hooks registered without release context: ' . count( $GLOBALS['HAL_HW_HOOKS'] )
	);

	$ok = hal_hw_report( 'health-wiring NOCONTEXT' );
	exit( $ok ? 0 : 1 );
}

/* ────────────────────────────────────────────────────────────────
 * FUNCTIONAL mode — one unauthenticated request through the
 * callback recorded by bootstrap
 * ──────────────────────────────────────────────────────────────── */

function hal_hw_mode_functional( string $runtime_root ): void {
	$mu_base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hal-hw-func-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
	$mu      = $mu_base . DIRECTORY_SEPARATOR . 'mu';
	$state   = $mu . DIRECTORY_SEPARATOR . 'hal-frontend-dashboard' . DIRECTORY_SEPARATOR . 'state';
	if ( ! is_dir( $state ) && ! @mkdir( $state, 0777, true ) ) {
		fwrite( STDERR, "health-wiring-test: cannot create the temporary state directory.\n" );
		exit( 1 );
	}
	register_shutdown_function( 'hal_hw_remove_dir', $mu_base );
	hal_hw_define_release_context( $runtime_root, $mu );

	require $runtime_root . 'bootstrap.php';

	// Verified migration pass through the REAL init callbacks, so the
	// REAL readiness filter below evaluates true for genuine local data.
	do_action( 'init' );
	$ready = apply_filters(
		'hal_frontend_dashboard_boot_readiness',
		false,
		HAL_FRONTEND_DASHBOARD_RELEASE_ID,
		HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION
	);
	hal_hw_check(
		'F1',
		true === $ready,
		'real readiness filter true after the verified migration pass',
		'readiness not true after init: ' . var_export( $ready, true )
	);

	// Valid temporary challenge in the isolated state directory only.
	$token = bin2hex( random_bytes( 32 ) );
	$op    = bin2hex( random_bytes( 16 ) );
	$challenge_path = $state . DIRECTORY_SEPARATOR . 'health-challenge-' . $op . '.json';
	file_put_contents(
		$challenge_path,
		json_encode(
			array(
				'token_hash' => hash( 'sha256', $token ),
				'operation'  => $op,
				'release_id' => HAL_FRONTEND_DASHBOARD_RELEASE_ID,
				'version'    => HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION,
				'loader_id'  => HAL_FRONTEND_DASHBOARD_LOADER_ID,
				'deadline'   => time() + 60,
			),
			JSON_UNESCAPED_SLASHES
		) . "\n"
	);

	// The callback bootstrap recorded — never a manual register() call.
	$action  = hal_hw_health_action();
	$entries = $GLOBALS['HAL_HW_HOOKS'][ 'wp_ajax_nopriv_' . $action ] ?? array();
	hal_hw_check(
		'F2',
		1 === count( $entries ) && is_callable( $entries[0]['callback'] ),
		'exactly one recorded unauthenticated callback to drive',
		'recorded unauthenticated callbacks=' . count( $entries )
	);
	if ( 1 !== count( $entries ) || ! is_callable( $entries[0]['callback'] ) ) {
		$ok = hal_hw_report( 'health-wiring FUNCTIONAL' );
		exit( $ok ? 0 : 1 );
	}

	$_POST['token']     = $token;
	$_POST['operation'] = $op;
	try {
		call_user_func( $entries[0]['callback'] );
	} catch ( Hal_Hw_Json_Sent $sent_signal ) {
		// Faithful emulation of wp_send_json*() ending the request.
	}
	$sent = $GLOBALS['HAL_HW_JSON'];
	$GLOBALS['HAL_HW_JSON'] = null;
	$ack_ok = is_array( $sent )
		&& 'success' === ( $sent['type'] ?? '' )
		&& true === ( $sent['body']['success'] ?? false )
		&& true === ( $sent['body']['ready'] ?? false )
		&& hash_equals( $op, (string) ( $sent['body']['operation'] ?? '' ) )
		&& hash_equals( HAL_FRONTEND_DASHBOARD_RELEASE_ID, (string) ( $sent['body']['release_id'] ?? '' ) )
		&& hash_equals( HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION, (string) ( $sent['body']['version'] ?? '' ) )
		&& hash_equals( HAL_FRONTEND_DASHBOARD_LOADER_ID, (string) ( $sent['body']['loader_id'] ?? '' ) );
	hal_hw_check(
		'F3',
		$ack_ok,
		'recorded callback returns the correct ACK for the challenge',
		'ACK mismatch: ' . json_encode( $sent )
	);
	hal_hw_check(
		'F4',
		! is_file( $challenge_path ),
		'challenge consumed (one-use file removed)',
		'challenge file still present after the ACK'
	);

	$ok = hal_hw_report( 'health-wiring FUNCTIONAL' );
	exit( $ok ? 0 : 1 );
}

/* ────────────────────────────────────────────────────────────────
 * MAIN mode — spawn the isolated cases and aggregate
 * ──────────────────────────────────────────────────────────────── */

function hal_hw_spawn( string $mode, ?string $flag ): array {
	$command = array( PHP_BINARY, __FILE__, $mode );
	if ( null !== $flag && '' !== $flag ) {
		$command[] = '--runtime-root=' . $flag;
	}
	$spec = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);
	$proc = proc_open( $command, $spec, $pipes );
	if ( ! is_resource( $proc ) ) {
		return array( 'code' => -1, 'stdout' => '', 'stderr' => 'proc_open failed.', 'opened' => false );
	}
	fclose( $pipes[0] );
	$stdout = stream_get_contents( $pipes[1] );
	fclose( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	fclose( $pipes[2] );
	return array(
		'code'   => proc_close( $proc ),
		'stdout' => is_string( $stdout ) ? $stdout : '',
		'stderr' => is_string( $stderr ) ? $stderr : '',
		'opened' => true,
	);
}

function hal_hw_mode_main( ?string $flag ): void {
	$runtime_root = hal_hw_resolve_runtime_root( $flag );
	$modes        = array( 'normal', 'preloaded', 'nocontext', 'functional' );
	$modes_ok     = 0;
	echo "HAL Frontend Dashboard — Health wiring regression test\n";
	echo 'examined runtime: ' . $runtime_root . "\n";
	echo str_repeat( '─', 72 ) . "\n";
	foreach ( $modes as $mode ) {
		$result = hal_hw_spawn( $mode, $flag );
		echo $result['stdout'];
		if ( '' !== trim( $result['stderr'] ) ) {
			echo '[' . $mode . '] STDERR: ' . trim( $result['stderr'] ) . "\n";
		}
		$mode_ok = $result['opened'] && 0 === $result['code'];
		if ( $mode_ok ) {
			$modes_ok++;
		}
		echo '[' . $mode . '] exit=' . $result['code'] . "\n";
		echo str_repeat( '─', 72 ) . "\n";
	}
	echo 'RESULT: ' . $modes_ok . '/' . count( $modes ) . " modes passed\n";
	exit( $modes_ok === count( $modes ) ? 0 : 1 );
}

/* ── Dispatch ── */

$hal_hw_request = hal_hw_cli_request();
switch ( $hal_hw_request['mode'] ) {
	case 'normal':
		hal_hw_mode_normal( hal_hw_resolve_runtime_root( $hal_hw_request['flag'] ) );
		break;
	case 'preloaded':
		hal_hw_mode_preloaded( hal_hw_resolve_runtime_root( $hal_hw_request['flag'] ), $hal_hw_request['flag'] );
		break;
	case 'nocontext':
		hal_hw_mode_nocontext();
		break;
	case 'functional':
		hal_hw_mode_functional( hal_hw_resolve_runtime_root( $hal_hw_request['flag'] ) );
		break;
	case 'main':
	default:
		hal_hw_mode_main( $hal_hw_request['flag'] );
		break;
}
