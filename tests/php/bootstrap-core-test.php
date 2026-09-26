<?php
/**
 * HAL Frontend Dashboard — Batch 2 closure harness (bootstrap core).
 *
 * Local, isolated harness: no live WordPress, no network, no database.
 * It stubs the WordPress functions/objects the actual load path touches,
 * simulates the runtime constants (RUNTIME_ROOT / RELEASE_ID / VERSION /
 * RUNTIME_URL), then requires the real runtime/bootstrap.php and verifies:
 *
 *   POSITIVE     — runtime_ready action fired (>= 1), boot_readiness filter
 *                  registered and evaluating the live verified state (B2-01:
 *                  false before init migrations run, true after the first
 *                  verified migration pass — check B3b), and the actual load
 *                  order is exactly setup → i18n → tables → permissions →
 *                  notifications (via get_included_files() and the exact
 *                  hook/event sequence).
 *   NEGATIVE     — in a real separate process (proc_open argv mode
 *                  "negative"): without HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT
 *                  defined, bootstrap.php exits silently — no fatal (exit
 *                  code 0), no action fired, no core function defined.
 *   IDEMPOTENCY  — a second require_once registers nothing new; the actual
 *                  registered init callbacks run twice against an in-memory
 *                  option store and a $wpdb mock: dbDelta runs exactly once
 *                  per table, the three schema version options are set once
 *                  and never change on the second run.
 *   ORDER-WEIGHT — every core file defines its signature functions (each
 *                  definition guarded by function_exists), and bootstrap.php
 *                  requires exactly the mandated load order: the 5 core files,
 *                  then the 8 batch-4 adapters (documented batch-4 evolution
 *                  of the bootstrap contract — the batch-2 state "no adapters
 *                  yet" was superseded by the designated adapters section),
 *                  and ajax/seo.php stays unloaded while the 8 batch-5/6 ajax files load after adapters.
 *
 * The harness calls the real project code — only WordPress itself is
 * stubbed (add_action/add_filter/do_action/apply_filters/__return_true/
 * get_role/get_option/update_option/is_admin/headers_sent, a $wpdb mock,
 * and a dbDelta stub installed at a temporary ABSPATH scaffold outside the
 * project, cleaned up on shutdown).
 *
 * Usage:  php tests/php/bootstrap-core-test.php   (exit 0 = all checks pass)
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

if ( PHP_SAPI !== 'cli' ) {
	echo "This harness must run under CLI PHP.\n";
	exit( 1 );
}

$GLOBALS['HAL_TEST_HOOKS']        = array();
$GLOBALS['HAL_TEST_ACTIONS_DONE'] = array();
$GLOBALS['HAL_TEST_EVENT_LOG']    = array();
$GLOBALS['HAL_TEST_OPTIONS']      = array();

/* ────────────────────────────────────────────────────────────────
 * Harness bookkeeping
 * ──────────────────────────────────────────────────────────────── */

function hal_test_check( string $id, bool $condition, string $pass, string $fail = '' ): void {
	$GLOBALS['HAL_TEST_RESULTS'][] = array(
		'id'    => $id,
		'ok'    => $condition,
		'pass'  => $pass,
		'fail'  => $fail,
	);
}

function hal_test_register_hook( string $type, string $hook_name, $callback, int $priority ): bool {
	$GLOBALS['HAL_TEST_HOOKS'][ $hook_name ][] = array(
		'type'     => $type,
		'callback' => $callback,
		'priority' => $priority,
	);
	$GLOBALS['HAL_TEST_EVENT_LOG'][] = array(
		'type'     => $type,
		'hook'     => $hook_name,
		'priority' => $priority,
	);
	return true;
}

function hal_test_sorted_entries( string $hook_name ): array {
	$entries = $GLOBALS['HAL_TEST_HOOKS'][ $hook_name ] ?? array();
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
		return hal_test_register_hook( 'action', $hook_name, $callback, $priority );
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook_name, $callback = '', int $priority = 10, int $accepted_args = 1 ): bool {
		return hal_test_register_hook( 'filter', $hook_name, $callback, $priority );
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook_name, ...$args ): void {
		$GLOBALS['HAL_TEST_ACTIONS_DONE'][ $hook_name ] = ( $GLOBALS['HAL_TEST_ACTIONS_DONE'][ $hook_name ] ?? 0 ) + 1;
		$GLOBALS['HAL_TEST_EVENT_LOG'][] = array(
			'type'     => 'action_fired',
			'hook'     => $hook_name,
			'priority' => null,
		);
		foreach ( hal_test_sorted_entries( $hook_name ) as $entry ) {
			call_user_func_array( $entry['callback'], $args );
		}
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook_name, $value, ...$args ) {
		foreach ( hal_test_sorted_entries( $hook_name ) as $entry ) {
			$value = call_user_func_array( $entry['callback'], array_merge( array( $value ), $args ) );
		}
		return $value;
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook_name ): int {
		return $GLOBALS['HAL_TEST_ACTIONS_DONE'][ $hook_name ] ?? 0;
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

// Batch-4: the real wpml adapter is loaded, so setup.php's init callback
// now reaches hossam_wpml_all_paths() — these WordPress boundary stubs
// keep that callback executable in this harness (no pages exist → the
// adapter's documented no-WPML fallback path returns the plain slugs).
if ( ! function_exists( 'get_page_by_path' ) ) {
	function get_page_by_path( string $page_path, $output = null, string $post_type = 'page' ) {
		return $GLOBALS['HAL_TEST_PAGES'][ $page_path ] ?? null;
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

if ( ! function_exists( 'headers_sent' ) ) {
	function headers_sent( &$file = null, &$line = null ): bool {
		return false;
	}
}

if ( ! function_exists( 'get_role' ) ) {
	function get_role( string $role ) {
		return null;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default_value = false ) {
		if ( array_key_exists( $option, $GLOBALS['HAL_TEST_OPTIONS'] ) ) {
			return $GLOBALS['HAL_TEST_OPTIONS'][ $option ];
		}
		return $default_value;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, $value, $autoload = null ): bool {
		$GLOBALS['HAL_TEST_OPTIONS'][ $option ] = $value;
		return true;
	}
}

/* ────────────────────────────────────────────────────────────────
 * Cron boundary stubs (batch 6: ai.php's init sweep scheduler).
 * WordPress semantics only — a simple scheduled-events map.
 * ──────────────────────────────────────────────────────────────── */

$GLOBALS['HAL_TEST_SCHEDULED'] = $GLOBALS['HAL_TEST_SCHEDULED'] ?? array();

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
		return $GLOBALS['HAL_TEST_SCHEDULED'][ $key ] ?? false;
	}
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = array() ): bool {
		$key = $hook . '|' . json_encode( array_values( $args ) );
		$GLOBALS['HAL_TEST_SCHEDULED'][ $key ] = $timestamp;
		return true;
	}
}
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( int $timestamp, string $hook, array $args = array() ): bool {
		$key = $hook . '|' . json_encode( array_values( $args ) );
		$GLOBALS['HAL_TEST_SCHEDULED'][ $key ] = $timestamp;
		return true;
	}
}

/* ────────────────────────────────────────────────────────────────
 * $wpdb mock (WordPress object stub)
 * ──────────────────────────────────────────────────────────────── */

final class HAL_Core_Test_WPDB {
	public string $prefix = 'wp_';
	/** @var string */
	public $last_error = '';
	/** @var array<string, array{columns:string[], indexes:string[]}> */
	private array $tables = array();

	public function hal_test_record_table( string $table, array $columns, array $indexes ): void {
		$this->tables[ $table ] = array(
			'columns' => $columns,
			'indexes' => $indexes,
		);
	}

	public function flush(): bool {
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

/* ────────────────────────────────────────────────────────────────
 * Temporary ABSPATH scaffold (outside the project, cleaned on shutdown)
 * ──────────────────────────────────────────────────────────────── */

function hal_test_remove_dir( string $dir ): void {
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

function hal_test_make_abspath_scaffold(): string {
	$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hal-core-test-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
	$includes = $base . DIRECTORY_SEPARATOR . 'wp-admin' . DIRECTORY_SEPARATOR . 'includes';
	if ( ! is_dir( $includes ) && ! @mkdir( $includes, 0777, true ) ) {
		fwrite( STDERR, "Harness cannot create the temporary ABSPATH scaffold.\n" );
		exit( 1 );
	}

	// WordPress stub for wp-admin/includes/upgrade.php::dbDelta():
	// parses the real CREATE TABLE statements the runtime passes and
	// records table columns/indexes in the $wpdb mock — add-only,
	// mirroring dbDelta's documented create/alter behavior.
	$upgrade_stub = <<<'PHP'
<?php
if ( ! function_exists( 'dbDelta' ) ) {
	function dbDelta( $queries = array(), $execute = true ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'hal_test_record_table' ) ) {
			throw new RuntimeException( 'HAL_TEST_DBDELTA_NO_WPDB_MOCK' );
		}
		$GLOBALS['HAL_TEST_DBDELTA_CALLS'] = ( $GLOBALS['HAL_TEST_DBDELTA_CALLS'] ?? 0 ) + 1;
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
		foreach ( $parsed as $table => $schema ) {
			$wpdb->hal_test_record_table( $table, $schema['columns'], $schema['indexes'] );
		}
		return array();
	}
}
PHP;

	if ( false === file_put_contents( $includes . DIRECTORY_SEPARATOR . 'upgrade.php', $upgrade_stub ) ) {
		fwrite( STDERR, "Harness cannot write the temporary dbDelta stub.\n" );
		exit( 1 );
	}
	register_shutdown_function( 'hal_test_remove_dir', $base );

	return $base . DIRECTORY_SEPARATOR;
}

function hal_test_invoke_hook( string $hook_name ): void {
	foreach ( hal_test_sorted_entries( $hook_name ) as $entry ) {
		call_user_func( $entry['callback'] );
	}
}

/* ────────────────────────────────────────────────────────────────
 * NEGATIVE mode (runs in a separate real process via proc_open)
 * ──────────────────────────────────────────────────────────────── */

function hal_test_negative_main(): void {
	$bootstrap = dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'bootstrap.php';

	define( 'ABSPATH', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hal-negative-' . getmypid() . DIRECTORY_SEPARATOR );

	// Deliberately NOT defining HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT.
	require $bootstrap;

	$ready        = did_action( 'hal_frontend_dashboard_runtime_ready' );
	$events       = count( $GLOBALS['HAL_TEST_EVENT_LOG'] );
	$core_defined = function_exists( 'hossam_is_dashboard' )
		|| function_exists( 'hossam_t' )
		|| function_exists( 'hossam_dashboard_table_is_ready' );

	echo 'HARNESS-NEGATIVE-RESULT ready=' . $ready
		. ' events=' . $events
		. ' core_defined=' . ( $core_defined ? '1' : '0' ) . "\n";

	exit( ( 0 === $ready && 0 === $events && ! $core_defined ) ? 0 : 1 );
}

/**
 * @return array{code:int, stdout:string, stderr:string, opened:bool}
 */
function hal_test_run_negative_subprocess(): array {
	$php     = PHP_BINARY;
	$script  = __FILE__;

	$descriptors = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);

	$proc = proc_open( array( $php, $script, 'negative' ), $descriptors, $pipes );
	if ( ! is_resource( $proc ) ) {
		$command = escapeshellarg( $php ) . ' ' . escapeshellarg( $script ) . ' negative';
		$proc    = proc_open( $command, $descriptors, $pipes );
		if ( ! is_resource( $proc ) ) {
			return array( 'code' => -1, 'stdout' => '', 'stderr' => 'proc_open failed to spawn the negative subprocess.', 'opened' => false );
		}
	}

	fclose( $pipes[0] );
	$stdout = stream_get_contents( $pipes[1] );
	fclose( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	fclose( $pipes[2] );
	$code   = proc_close( $proc );

	return array(
		'code'   => $code,
		'stdout' => is_string( $stdout ) ? $stdout : '',
		'stderr' => is_string( $stderr ) ? $stderr : '',
		'opened' => true,
	);
}

/* ────────────────────────────────────────────────────────────────
 * MAIN mode
 * ──────────────────────────────────────────────────────────────── */

function hal_test_main(): void {
	$plugin_root  = dirname( __DIR__, 2 );
	$runtime_root = $plugin_root . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR;
	$bootstrap    = $runtime_root . 'bootstrap.php';

	$required_files = array(
		$bootstrap,
		$runtime_root . 'core' . DIRECTORY_SEPARATOR . 'setup.php',
		$runtime_root . 'core' . DIRECTORY_SEPARATOR . 'i18n.php',
		$runtime_root . 'core' . DIRECTORY_SEPARATOR . 'tables.php',
		$runtime_root . 'core' . DIRECTORY_SEPARATOR . 'permissions.php',
		$runtime_root . 'core' . DIRECTORY_SEPARATOR . 'notifications.php',
	);
	$missing = array();
	foreach ( $required_files as $file ) {
		if ( ! is_file( $file ) ) {
			$missing[] = basename( $file );
		}
	}
	if ( array() !== $missing ) {
		fwrite( STDERR, "Missing runtime files: " . implode( ', ', $missing ) . "\n" );
		exit( 1 );
	}

	// Simulated release context (as loader-core.php defines before requiring bootstrap.php).
	define( 'ABSPATH', hal_test_make_abspath_scaffold() );
	define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT', $runtime_root );
	define( 'HAL_FRONTEND_DASHBOARD_RELEASE_ID', '1.0.0+' . str_repeat( 'ab', 20 ) );
	define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION', '1.0.0' );
	define(
		'HAL_FRONTEND_DASHBOARD_RUNTIME_URL',
		'https://example.test/wp-content/mu-plugins/hal-frontend-dashboard/releases/' . HAL_FRONTEND_DASHBOARD_RELEASE_ID . '/'
	);

	$GLOBALS['wpdb'] = new HAL_Core_Test_WPDB();

	$included_before_bootstrap = count( get_included_files() );

	// ── FIRST LOAD of the real bootstrap ──
	require_once $bootstrap;

	/* ════════ 1. POSITIVE ════════ */

	$ready_count = did_action( 'hal_frontend_dashboard_runtime_ready' );
	hal_test_check(
		'A1',
		$ready_count >= 1,
		"runtime_ready action fired (did_action={$ready_count})",
		"expected did_action('hal_frontend_dashboard_runtime_ready') >= 1, got {$ready_count}"
	);

	// B2-01: الفلتر حالة حية تُقيَّم عند كل نداء — قبل init (قبل
	// migrations) يقيَّم false صادقة مع بقاء التسجيل قائمًا؛ الإقرار
	// true بعد نجاح migrations يثبته B3b.
	$readiness = apply_filters( 'hal_frontend_dashboard_boot_readiness', false, HAL_FRONTEND_DASHBOARD_RELEASE_ID, HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION );
	$readiness_callbacks = count( $GLOBALS['HAL_TEST_HOOKS']['hal_frontend_dashboard_boot_readiness'] ?? array() );
	hal_test_check(
		'A2',
		false === $readiness && $readiness_callbacks >= 1,
		"boot_readiness filter registered and evaluates live verified state (false before init migrations; callbacks={$readiness_callbacks})",
		"expected boot_readiness filter to evaluate false pre-init with >= 1 callback; got " . var_export( $readiness, true ) . " with {$readiness_callbacks} callbacks"
	);

	$included_normalized = array_map(
		static function ( string $path ): string {
			return str_replace( '\\', '/', $path );
		},
		get_included_files()
	);
	$core_load_order = array();
	foreach ( $included_normalized as $path ) {
		if ( false !== strpos( $path, '/runtime/core/' ) ) {
			$core_load_order[] = basename( $path );
		}
	}
	$expected_order = array( 'setup.php', 'i18n.php', 'tables.php', 'permissions.php', 'notifications.php' );
	hal_test_check(
		'A3',
		$core_load_order === $expected_order,
		'actual core load order is setup → i18n → tables → permissions → notifications (' . implode( ' → ', $core_load_order ) . ')',
		'load order mismatch — expected [' . implode( ', ', $expected_order ) . '] got [' . implode( ', ', $core_load_order ) . ']'
	);

	$expected_events = array(
		array( 'action', 'activated_plugin', 10 ),
		array( 'action', 'deactivated_plugin', 10 ),
		array( 'action', 'upgrader_process_complete', 10 ),
		array( 'action', 'wp_loaded', 20 ),
		array( 'filter', 'cron_schedules', 10 ),
		array( 'filter', 'login_redirect', 10 ),
		array( 'action', 'plugins_loaded', 10 ),
		array( 'action', 'after_setup_theme', 10 ),
		array( 'action', 'init', 10 ),
		array( 'action', 'admin_init', 10 ),
		array( 'action', 'wp_enqueue_scripts', 20 ),
		array( 'action', 'wp_enqueue_scripts', 999 ),
		array( 'action', 'wp_enqueue_scripts', 999 ),
		array( 'filter', 'body_class', 10 ),
		array( 'action', 'wp', 10 ),
		array( 'action', 'init', 5 ),
		array( 'action', 'init', 10 ),
		array( 'action', 'init', 10 ),
		array( 'action', 'init', 10 ),
		array( 'action', 'init', 10 ),
		array( 'action', 'transition_post_status', 10 ),
		array( 'action', 'amelia_after_booking_added', 10 ),
		array( 'action', 'woocommerce_order_status_changed', 10 ),
		// Batch 3 (backend) load-time registrations — in bootstrap require order.
		array( 'action', 'hal_frontend_dashboard_integration_health_check', 10 ),
		array( 'filter', 'site_status_tests', 10 ),
		array( 'action', 'admin_init', 10 ),
		array( 'action', 'admin_notices', 10 ),
		array( 'action', 'admin_menu', 10 ),
		array( 'action', 'admin_enqueue_scripts', 10 ),
		array( 'action', 'admin_post_hal_frontend_dashboard_save_settings', 10 ),
		array( 'action', 'admin_post_hal_frontend_dashboard_set_secret', 10 ),
		array( 'action', 'admin_post_hal_frontend_dashboard_delete_secret', 10 ),
		array( 'action', 'admin_post_hal_frontend_dashboard_run_health', 10 ),
		// Batch 4 (adapters) load-time registrations — adapters/amelia.php
		// only; the other seven adapters register nothing at load time.
		array( 'action', 'activated_plugin', 10 ),
		array( 'action', 'deactivated_plugin', 10 ),
		array( 'action', 'upgrader_process_complete', 10 ),
		array( 'action', 'plugins_loaded', 20 ),
		// Batch 5 (content & files AJAX) load-time registrations — in
		// bootstrap require order: ajax/posts.php then ajax/uploads.php.
		array( 'action', 'wp_ajax_hossam_restore_article', 10 ),
		array( 'action', 'wp_ajax_hossam_translate_article', 10 ),
		array( 'action', 'wp_ajax_hossam_create_article', 10 ),
		array( 'action', 'wp_ajax_hossam_update_article', 10 ),
		array( 'action', 'wp_ajax_hossam_trash_article', 10 ),
		array( 'action', 'wp_ajax_hossam_get_my_files', 10 ),
		array( 'action', 'wp_ajax_hossam_restore_file', 10 ),
		array( 'action', 'wp_ajax_hossam_delete_file', 10 ),
		array( 'action', 'wp_ajax_hossam_upload_file', 10 ),
		// Batch 6 (integrations/services AJAX §17) load-time registrations —
		// in bootstrap require order (appointments → finance → members →
		// inbox → store → ai), within ai.php in its own source order
		// (3 wp_ajax_* + the internal worker/sweep/init hooks).
		array( 'action', 'wp_ajax_hossam_get_upcoming_appointments', 10 ),
		array( 'action', 'wp_ajax_hossam_lazy_appointments', 10 ),
		array( 'action', 'wp_ajax_hossam_get_orders', 10 ),
		array( 'action', 'wp_ajax_hossam_finance_summary', 10 ),
		array( 'action', 'wp_ajax_hossam_get_payment_methods', 10 ),
		array( 'action', 'wp_ajax_hossam_get_saved_tokens', 10 ),
		array( 'action', 'wp_ajax_hossam_get_members', 10 ),
		array( 'action', 'wp_ajax_hossam_get_notifications', 10 ),
		array( 'action', 'wp_ajax_hossam_mark_read', 10 ),
		array( 'action', 'wp_ajax_hossam_mark_all_read', 10 ),
		array( 'action', 'wp_ajax_hossam_send_message', 10 ),
		array( 'action', 'wp_ajax_hossam_get_inbox', 10 ),
		array( 'action', 'wp_ajax_hossam_mark_message_read', 10 ),
		array( 'action', 'wp_ajax_hossam_get_products', 10 ),
		array( 'action', 'wp_ajax_hossam_ai_submit_job', 10 ),
		array( 'action', 'hossam_ai_process_job_event', 10 ),
		array( 'action', 'init', 10 ),
		array( 'action', 'hossam_ai_sweep_jobs_event', 10 ),
		array( 'action', 'wp_ajax_hossam_ai_get_job_status', 10 ),
		array( 'action', 'wp_ajax_hossam_ai_set_preference', 10 ),
		// Batch 7 (§18): the infrastructure Template Controller registers
		// template_include when bootstrap requires it (after AJAX, before the
		// readiness filter).
		array( 'filter', 'template_include', 10 ),
		array( 'filter', 'hal_frontend_dashboard_boot_readiness', 10 ),
		array( 'action_fired', 'hal_frontend_dashboard_runtime_ready', null ),
	);
	$actual_events = array();
	foreach ( $GLOBALS['HAL_TEST_EVENT_LOG'] as $event ) {
		$actual_events[] = array( $event['type'], $event['hook'], $event['priority'] );
	}
	$event_detail = '';
	if ( $actual_events !== $expected_events ) {
		$event_detail = 'first divergence: ';
		$limit        = max( count( $expected_events ), count( $actual_events ) );
		for ( $i = 0; $i < $limit; $i++ ) {
			$expected_item = $expected_events[ $i ] ?? null;
			$actual_item   = $actual_events[ $i ] ?? null;
			if ( $expected_item !== $actual_item ) {
				$event_detail .= 'index ' . $i . ' expected ' . json_encode( $expected_item ) . ' got ' . json_encode( $actual_item );
				break;
			}
		}
	}
	hal_test_check(
		'A4',
		$actual_events === $expected_events,
		'exact load-time event sequence matches (69 events: 39 through batch 4 + the 9 batch-5 wp_ajax_* registrations + the 20 batch-6 registrations: 17 wp_ajax_* plus ai.php\'s internal worker/sweep/init hooks, one each + the batch-7 infrastructure template_include filter)',
		'event sequence mismatch — ' . $event_detail
	);

	$expected_asset_path = HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT . 'assets/css/dashboard.css';
	$expected_asset_uri  = HAL_FRONTEND_DASHBOARD_RUNTIME_URL . 'assets/css/dashboard.css';
	$actual_asset_path   = hossam_asset_path( 'css/dashboard.css' );
	$actual_asset_uri    = hossam_asset_uri( 'css/dashboard.css' );
	hal_test_check(
		'A5',
		$actual_asset_path === $expected_asset_path && $actual_asset_uri === $expected_asset_uri,
		'hossam_asset_path()/hossam_asset_uri() consume the release context only (RUNTIME_ROOT/RUNTIME_URL + assets/)',
		'asset path/uri mismatch — path expected ' . var_export( $expected_asset_path, true ) . ' got ' . var_export( $actual_asset_path, true )
			. '; uri expected ' . var_export( $expected_asset_uri, true ) . ' got ' . var_export( $actual_asset_uri, true )
	);

	/* ════════ 2. NEGATIVE (real separate process) ════════ */

	$negative = hal_test_run_negative_subprocess();
	$negative_ok = $negative['opened']
		&& 0 === $negative['code']
		&& false !== strpos( $negative['stdout'], 'ready=0' )
		&& false !== strpos( $negative['stdout'], 'events=0' )
		&& false !== strpos( $negative['stdout'], 'core_defined=0' );
	hal_test_check(
		'A6',
		$negative_ok,
		'without RUNTIME_ROOT bootstrap exits silently in a separate process (exit 0, no action, no core functions)',
		'negative subprocess failed — exit code ' . $negative['code']
			. '; stdout: ' . trim( $negative['stdout'] )
			. '; stderr: ' . trim( $negative['stderr'] )
	);

	/* ════════ 3. IDEMPOTENCY ════════ */

	$events_before_second = count( $GLOBALS['HAL_TEST_EVENT_LOG'] );
	$ready_before_second   = did_action( 'hal_frontend_dashboard_runtime_ready' );
	$includes_before_second = count( get_included_files() );

	// ── SECOND require_once in the same process ──
	require_once $bootstrap;

	$events_after_second  = count( $GLOBALS['HAL_TEST_EVENT_LOG'] );
	$ready_after_second   = did_action( 'hal_frontend_dashboard_runtime_ready' );
	$includes_after_second = count( get_included_files() );
	hal_test_check(
		'B1',
		$events_after_second === $events_before_second
			&& $ready_after_second === $ready_before_second
			&& $includes_after_second === $includes_before_second,
		"second require_once registers nothing new (events {$events_before_second}→{$events_after_second}, ready {$ready_before_second}, includes unchanged)",
		'double load changed state — events ' . $events_before_second . '→' . $events_after_second
			. ', ready ' . $ready_before_second . '→' . $ready_after_second
			. ', includes ' . $includes_before_second . '→' . $includes_after_second
	);

	$init_registrations = count( $GLOBALS['HAL_TEST_HOOKS']['init'] ?? array() );
	hal_test_check(
		'B2',
		7 === $init_registrations,
		"init hooks registered exactly once each (7 registrations: setup LiteSpeed, i18n, 3× tables, permissions, batch-6 ai sweep scheduler)",
		"expected 7 init registrations after double require_once, got {$init_registrations}"
	);

	// ── First invocation of the real registered init callbacks ──
	$invoke_error = '';
	try {
		hal_test_invoke_hook( 'init' );
	} catch ( Throwable $exception ) {
		$invoke_error = $exception->getMessage();
	}
	$first_run_deltas = (int) ( $GLOBALS['HAL_TEST_DBDELTA_CALLS'] ?? 0 );
	$options_after_first = $GLOBALS['HAL_TEST_OPTIONS'];
	$expected_versions = array(
		'hossam_notifications_db_version' => '1.0.1',
		'hossam_messages_db_version'      => '1.0.1',
		'hossam_ai_jobs_db_version'       => '1.1.1',
	);
	$versions_ok = '' === $invoke_error
		&& 3 === $first_run_deltas
		&& array() === array_diff_key( $expected_versions, $options_after_first );
	foreach ( $expected_versions as $name => $value ) {
		if ( ( $options_after_first[ $name ] ?? null ) !== $value ) {
			$versions_ok = false;
		}
	}
	hal_test_check(
		'B3',
		$versions_ok,
		"first init run migrates all 3 tables once each (dbDelta={$first_run_deltas}, schema version options set: 1.0.1/1.0.1/1.1.1)",
		'first init run failed — error: ' . ( '' !== $invoke_error ? $invoke_error : 'none' )
			. '; dbDelta calls: ' . $first_run_deltas
			. '; options: ' . json_encode( $options_after_first )
	);

	// B2-01: بعد نجاح migrations الأولى تُقيِّم حالة الجاهزية true —
	// هذا ما يقرؤه class-health-check.php::handle_request لقبول
	// health acknowledgement الناجح.
	$readiness_after_first = apply_filters( 'hal_frontend_dashboard_boot_readiness', false, HAL_FRONTEND_DASHBOARD_RELEASE_ID, HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION );
	hal_test_check(
		'B3b',
		true === $readiness_after_first,
		'boot_readiness evaluates true after the first verified migration pass (B2-01 live-state contract)',
		'expected boot_readiness true after successful first init, got ' . var_export( $readiness_after_first, true )
	);

	// ── Second invocation of the same init callbacks (idempotency) ──
	$invoke_error_2 = '';
	try {
		hal_test_invoke_hook( 'init' );
	} catch ( Throwable $exception ) {
		$invoke_error_2 = $exception->getMessage();
	}
	$second_run_deltas = (int) ( $GLOBALS['HAL_TEST_DBDELTA_CALLS'] ?? 0 );
	$options_after_second = $GLOBALS['HAL_TEST_OPTIONS'];
	hal_test_check(
		'B4',
		'' === $invoke_error_2
			&& 3 === $second_run_deltas
			&& $options_after_second === $options_after_first,
		"second init run changes nothing (dbDelta still {$second_run_deltas}, option store byte-identical) — migrate logic is idempotent",
		'second init run was not idempotent — error: ' . ( '' !== $invoke_error_2 ? $invoke_error_2 : 'none' )
			. '; dbDelta calls: ' . $second_run_deltas
			. '; options before: ' . json_encode( $options_after_first )
			. '; options after: ' . json_encode( $options_after_second )
	);

	hal_test_check(
		'B5',
		function_exists( 'hossam_dashboard_table_is_ready' ),
		'hossam_dashboard_table_is_ready() is defined',
		'hossam_dashboard_table_is_ready() is not defined'
	);

	// ── Direct verification of hossam_dashboard_table_is_ready() ──
	$mock = new HAL_Core_Test_WPDB();
	$GLOBALS['wpdb'] = $mock;
	$schemas = hossam_dashboard_table_schemas();
	$notifications = $schemas['notifications'];
	$mock->hal_test_record_table(
		'wp_hossam_notifications',
		$notifications['columns'],
		$notifications['indexes']
	);

	$f1 = hossam_dashboard_table_is_ready( 'wp_hossam_notifications', $notifications['columns'], $notifications['indexes'], 'db error' );
	$f2 = hossam_dashboard_table_is_ready( 'wp_hossam_missing', $notifications['columns'], $notifications['indexes'], '' );
	$f3 = hossam_dashboard_table_is_ready( 'wp_hossam_notifications', array_merge( $notifications['columns'], array( 'nope' ) ), $notifications['indexes'], '' );
	$f4 = hossam_dashboard_table_is_ready( 'wp_hossam_notifications', $notifications['columns'], array_merge( $notifications['indexes'], array( 'nope_idx' ) ), '' );
	$mock->last_error = 'boom';
	$f5 = hossam_dashboard_table_is_ready( 'wp_hossam_notifications', $notifications['columns'], $notifications['indexes'], '' );
	$mock->last_error = '';
	hal_test_check(
		'B6',
		false === $f1 && false === $f2 && false === $f3 && false === $f4 && false === $f5,
		'hossam_dashboard_table_is_ready() returns false for all 5 failing scenarios (db error / missing table / missing column / missing index / wpdb last_error)',
		'failing-parameter checks must all be false — got: db_error=' . var_export( $f1, true )
			. ' missing_table=' . var_export( $f2, true )
			. ' missing_column=' . var_export( $f3, true )
			. ' missing_index=' . var_export( $f4, true )
			. ' last_error=' . var_export( $f5, true )
	);

	$p1 = hossam_dashboard_table_is_ready( 'wp_hossam_notifications', $notifications['columns'], $notifications['indexes'], '' );
	hal_test_check(
		'B7',
		true === $p1,
		'hossam_dashboard_table_is_ready() returns true for a fully valid table',
		'valid-table check must be true, got ' . var_export( $p1, true )
	);

	/* ════════ 4. ORDER-WEIGHT ════════ */

	$signature_functions = array(
		'core/setup.php'         => array(
			'hossam_ai_get_runtime_profile',
			'hossam_integration_registry_key',
			'hossam_is_known_plugin_active',
			'hossam_get_known_plugin_version',
			'hossam_get_ai_integration_decision',
			'hossam_get_integration_capabilities',
			'hossam_get_integration_decision',
			'hossam_build_integration_capability',
			'hossam_invalidate_integration_capabilities',
			'hossam_is_dashboard',
			'hossam_asset_path',
			'hossam_asset_uri',
		),
		'core/i18n.php'          => array( 'hossam_t', 'hossam_get_i18n_strings' ),
		'core/tables.php'        => array(
			'hossam_dashboard_table_is_ready',
			'hossam_dashboard_table_schemas',
			'hossam_dashboard_ensure_table',
		),
		'core/notifications.php' => array( 'hossam_notify_admins', 'hossam_bump_fin_cache_epoch' ),
	);
	$missing_functions = array();
	foreach ( $signature_functions as $file => $functions ) {
		foreach ( $functions as $function ) {
			if ( ! function_exists( $function ) ) {
				$missing_functions[] = "{$file}::{$function}";
			}
		}
	}
	hal_test_check(
		'C1',
		array() === $missing_functions,
		'all core signature functions are defined after load (permissions.php is hook-only; covered by the event log)',
		'missing functions: ' . implode( ', ', $missing_functions )
	);

	// Guard contract per the legacy source: setup.php/i18n.php/notifications.php
	// wrap every definition in function_exists guards; tables.php (legacy and
	// migrated alike) defines its functions top-level without guards and
	// relies on require_once for single inclusion — proven by B1/B2 above
	// (double require_once re-registers nothing, no fatal redefinition).
	$expected_unguarded = array(
		'tables.php::hossam_dashboard_table_is_ready',
		'tables.php::hossam_dashboard_table_schemas',
		'tables.php::hossam_dashboard_ensure_table',
	);
	$unguarded = array();
	foreach ( $required_files as $file ) {
		if ( false === strpos( $file, DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR ) ) {
			continue;
		}
		$source = (string) file_get_contents( $file );
		if ( ! preg_match_all( '/(?:^|\n)[ \t]*(?:(?:if\s*\(\s*!?\s*function_exists\(\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]\s*\)\s*\)\s*\{)[^\n]*\n)?[ \t]*function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $matches, PREG_SET_ORDER ) ) {
			continue;
		}
		foreach ( $matches as $match ) {
			$function = $match[2];
			if ( isset( $match[1] ) && '' !== $match[1] ) {
				continue; // This literal definition sits inside its own function_exists guard.
			}
			if ( ! preg_match( '/function_exists\(\s*[\'"]' . preg_quote( $function, '/' ) . '[\'"]\s*\)/', $source ) ) {
				$unguarded[] = basename( $file ) . '::' . $function;
			}
		}
	}
	$unexpected_unguarded = array_diff( $unguarded, $expected_unguarded );
	hal_test_check(
		'C2',
		array() === $unexpected_unguarded,
		'guard contract matches legacy: every definition is function_exists-guarded except the tables.php trio (top-level per legacy tables.php, single inclusion proven by B1/B2)',
		'unexpected unguarded definitions (legacy contract allows only the tables.php trio): '
			. ( array() === $unexpected_unguarded ? 'none' : implode( ', ', $unexpected_unguarded ) )
	);

	$bootstrap_source = (string) file_get_contents( $bootstrap );
	preg_match_all( '/^[ \t]*(?:require_once|require|include_once|include)\b.*$/m', $bootstrap_source, $require_matches );
	$require_lines   = $require_matches[0];
	// Batch-5 evolution of the bootstrap contract (documented in the
	// bootstrap's designated AJAX section): the 5 core files, then the
	// 5 backend/settings files, then the 8 adapters, then the eight ajax
	// files in the literal legacy order (2 batch-5 content/files + the 6
	// batch-6 integrations/services files) — while ajax/seo.php stays
	// forbidden (its loading follows the adopted registry state per §16;
	// verified_fields is still false, so the save_seo endpoint remains
	// unregistered).
	$expected_requires = array(
		'core/setup.php',
		'core/i18n.php',
		'core/tables.php',
		'core/permissions.php',
		'core/notifications.php',
		'settings/class-settings-repository.php',
		'security/class-secret-store.php',
		'integrations/class-integration-settings-registry.php',
		'health/class-integration-health-monitor.php',
		'admin/class-admin-controller.php',
		'adapters/wordpress-posts.php',
		'adapters/wordpress-uploads.php',
		'adapters/woocommerce.php',
		'adapters/wpml.php',
		'adapters/amelia.php',
		'adapters/rankmath.php',
		'adapters/ultimatemember.php',
		'adapters/ai.php',
		'ajax/posts.php',
		'ajax/uploads.php',
		'ajax/appointments.php',
		'ajax/finance.php',
		'ajax/members.php',
		'ajax/inbox.php',
		'ajax/store.php',
		'ajax/ai.php',
	);
	$require_order = array();
	$forbidden_require = '';
	foreach ( $require_lines as $line ) {
		if ( preg_match( '#ajax/seo\.php#i', $line ) ) {
			$forbidden_require = $line;
			break;
		}
		foreach ( $expected_requires as $expected_file ) {
			if ( false !== strpos( $line, $expected_file ) ) {
				$require_order[] = $expected_file;
			}
		}
	}
	$forbidden_included = '';
	foreach ( $included_normalized as $path ) {
		if ( false !== strpos( $path, '/runtime/ajax/seo.php' ) ) {
			$forbidden_included = $path;
			break;
		}
	}
	hal_test_check(
		'C3',
		'' === $forbidden_require
			&& '' === $forbidden_included
			&& $require_order === $expected_requires,
		'bootstrap.php requires exactly the mandated load order (5 core + 5 backend/settings + 8 batch-4 adapters + 8 ajax files: 2 batch-5 + 6 batch-6 in the legacy order) and never loads ajax/seo.php (§16 registry-gated loading)',
		'bootstrap require scan failed — forbidden require: ' . ( '' !== $forbidden_require ? $forbidden_require : 'none' )
			. '; forbidden included file: ' . ( '' !== $forbidden_included ? $forbidden_included : 'none' )
			. '; require order: ' . json_encode( $require_order )
	);

	/* ── Report ── */
	$pass_count = 0;
	$total      = count( $GLOBALS['HAL_TEST_RESULTS'] );
	echo "HAL Frontend Dashboard — Batch 2 bootstrap core test\n";
	echo "included files before bootstrap: {$included_before_bootstrap}; simulated release: " . HAL_FRONTEND_DASHBOARD_RELEASE_ID . "\n";
	echo str_repeat( '─', 72 ) . "\n";
	foreach ( $GLOBALS['HAL_TEST_RESULTS'] as $result ) {
		if ( $result['ok'] ) {
			$pass_count++;
			echo "PASS [{$result['id']}] {$result['pass']}\n";
		} else {
			echo "FAIL [{$result['id']}] {$result['fail']}\n";
		}
	}
	echo str_repeat( '─', 72 ) . "\n";
	echo "RESULT: {$pass_count}/{$total} checks passed\n";
	exit( $pass_count === $total ? 0 : 1 );
}

/* ── Dispatch ── */
$hal_test_mode = isset( $argv[1] ) ? (string) $argv[1] : 'main';
if ( 'negative' === $hal_test_mode ) {
	hal_test_negative_main();
	exit( 1 );
}
hal_test_main();
