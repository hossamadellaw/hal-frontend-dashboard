<?php
/**
 * HAL Frontend Dashboard — Amelia multisite auth-isolation proof (MS-02).
 *
 * Local, isolated harness (CLI PHP; no network, no live WordPress, no
 * database). Drives the REAL runtime/adapters/amelia.php auth callback
 * (`amelia_is_user_authenticated`) across a blog switch WITHIN one
 * request: both blogs own an `amelia_employees` table, the SAME employee
 * row id (7) links to DIFFERENT users (blog 1 → 101, blog 2 → 202).
 * The current user (101) must authenticate on blog 1 and NOT on blog 2,
 * with exactly one SELECT per blog (per-site cache retained, never
 * shared across sites).
 *
 * Usage: php tests/php/multisite-amelia-isolation-test.php (exit 0)
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

if ( PHP_SAPI !== 'cli' ) {
	echo "This harness must run under CLI PHP.\n";
	exit( 1 );
}

$project = dirname( __DIR__, 2 );

$GLOBALS['AM2_RESULTS'] = array();
$GLOBALS['AM2_QUERIES'] = 0;

function am2_check( string $id, bool $condition, string $pass, string $fail = '' ): void {
	$GLOBALS['AM2_RESULTS'][] = array( 'id' => $id, 'ok' => $condition );
	echo ( $condition ? 'PASS' : 'FAIL' ) . " [$id] " . ( $condition ? $pass : $fail ) . "\n";
}

/* ── WordPress boundary stubs (two blogs, per-blog employee tables) ── */

$GLOBALS['AM2_CURRENT'] = 1;
$GLOBALS['AM2_STACK'] = array();

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/am2-' . getmypid() . '/' );
}
@mkdir( ABSPATH, 0777, true );

if ( ! function_exists( 'is_plugin_active' ) ) {
	function is_plugin_active( string $plugin ): bool {
		return true;
	}
}
if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id(): int {
		return (int) $GLOBALS['AM2_CURRENT'];
	}
}
if ( ! function_exists( 'switch_to_blog' ) ) {
	function switch_to_blog( int $blog_id ): bool {
		$GLOBALS['AM2_STACK'][] = $GLOBALS['AM2_CURRENT'];
		$GLOBALS['AM2_CURRENT'] = $blog_id;
		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) ) {
			$wpdb->prefix = 1 === $blog_id ? 'wp_' : 'wp_' . $blog_id . '_';
			$wpdb->blogid = $blog_id;
		}
		return true;
	}
}
if ( ! function_exists( 'restore_current_blog' ) ) {
	function restore_current_blog(): bool {
		$prev = array_pop( $GLOBALS['AM2_STACK'] );
		$GLOBALS['AM2_CURRENT'] = null === $prev ? 1 : (int) $prev;
		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) ) {
			$wpdb->prefix = 1 === $GLOBALS['AM2_CURRENT'] ? 'wp_' : 'wp_' . $GLOBALS['AM2_CURRENT'] . '_';
			$wpdb->blogid = $GLOBALS['AM2_CURRENT'];
		}
		return true;
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return 101;
	}
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		return true;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap, ...$args ): bool {
		return false;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook_name, $callback = '', int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['AM2_HOOKS'][ $hook_name ][] = $callback;
		return true;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook_name, $callback = '', int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['AM2_FILTERS'][ $hook_name ][] = $callback;
		return true;
	}
}

final class AM2_WPDB {
	public string $prefix = 'wp_';
	public int $blogid = 1;
	public string $last_error = '';
	/** @var array<string,array<int,int>> full table name => [employee id => external (user) id] */
	public array $employees = array();

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

	public function get_var( ?string $query = null ) {
		if ( null === $query ) {
			return null;
		}
		if ( preg_match( "/^SHOW TABLES LIKE '(.*)'$/s", $query, $match ) ) {
			$name = str_replace( array( '\\_', '\\%', '\\\\' ), array( '_', '%', '\\' ), $match[1] );
			return array_key_exists( $name, $this->employees ) ? $name : null;
		}
		if ( preg_match( '/^SELECT externalId FROM (\S+) WHERE id = (\d+) LIMIT 1$/', $query, $match ) ) {
			$GLOBALS['AM2_QUERIES']++;
			return $this->employees[ $match[1] ][ (int) $match[2] ] ?? null;
		}
		return null;
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}
}

$GLOBALS['wpdb'] = new AM2_WPDB();
$GLOBALS['wpdb']->employees = array(
	'wp_amelia_employees'   => array( 7 => 101 ),
	'wp_2_amelia_employees' => array( 7 => 202 ),
);

/* ── Load the REAL adapter and fire plugins_loaded to register filters ── */

require_once $project . '/runtime/adapters/amelia.php';

foreach ( $GLOBALS['AM2_HOOKS']['plugins_loaded'] ?? array() as $cb ) {
	$cb();
}
$auth_cbs = $GLOBALS['AM2_FILTERS']['amelia_is_user_authenticated'] ?? array();
am2_check(
	'AM2-callback-registered',
	1 <= count( $auth_cbs ) && is_callable( $auth_cbs[0] ),
	'the real adapter registered its auth callback',
	'auth callback missing'
);
$auth = $auth_cbs[0];

/* ── Isolation proof: same employee id, different linked users ── */

am2_check(
	'AM2-tables-present',
	hossam_amelia_table_exists( 'amelia_employees' ),
	'blog-1 employee table visible',
	'blog-1 table missing'
);

$r1 = $auth( false, 7 );
am2_check(
	'AM2-blog1-linked',
	true === $r1,
	'user 101 authenticates for employee 7 on blog 1',
	'blog-1 result: ' . var_export( $r1, true )
);
$q_after_b1 = $GLOBALS['AM2_QUERIES'];

switch_to_blog( 2 );
am2_check(
	'AM2-blog2-table-present',
	hossam_amelia_table_exists( 'amelia_employees' ),
	'blog-2 employee table visible after the switch',
	'blog-2 table missing'
);
$r2 = $auth( false, 7 );
am2_check(
	'AM2-blog2-isolated',
	false === $r2,
	'user 101 does NOT authenticate for employee 7 on blog 2 (linked to 202 there)',
	'CROSS-SITE LEAK, blog-2 result: ' . var_export( $r2, true )
);
am2_check(
	'AM2-blog2-reread',
	$q_after_b1 + 1 === $GLOBALS['AM2_QUERIES'],
	'blog-2 reread its own table after the switch (no site-1 cache reuse)',
	'blog-2 served site-1 cache: queries=' . $GLOBALS['AM2_QUERIES'] . ' after b1=' . $q_after_b1
);
$q_after_b2 = $GLOBALS['AM2_QUERIES'];

$r2_again = $auth( false, 7 );
am2_check(
	'AM2-blog2-cache-stable',
	false === $r2_again && $q_after_b2 === $GLOBALS['AM2_QUERIES'],
	'blog-2 repeat call stays denied with no extra query (per-site cache)',
	'cache unstable: ' . var_export( $r2_again, true ) . ' queries=' . $GLOBALS['AM2_QUERIES']
);

switch_to_blog( 1 );
$r1_again = $auth( false, 7 );
am2_check(
	'AM2-blog1-cache-stable',
	true === $r1_again && $q_after_b2 === $GLOBALS['AM2_QUERIES'],
	'back on blog 1 the grant holds with no extra query (per-site cache)',
	'cache unstable: ' . var_export( $r1_again, true ) . ' queries=' . $GLOBALS['AM2_QUERIES']
);
restore_current_blog();

am2_check(
	'AM2-query-count',
	2 === $GLOBALS['AM2_QUERIES'],
	'exactly one employee SELECT per blog (caching retained, isolation kept)',
	'queries=' . $GLOBALS['AM2_QUERIES']
);

/* ── Report ── */

$pass = 0;
foreach ( $GLOBALS['AM2_RESULTS'] as $result ) {
	if ( $result['ok'] ) {
		$pass++;
	}
}
$total = count( $GLOBALS['AM2_RESULTS'] );
echo "AMELIA-MS RESULT: $pass/$total checks passed\n";
if ( $pass === $total ) {
	echo "AMELIA-MS-VERDICT main ALL-ASSERTIONS-HELD\n";
	exit( 0 );
}
echo "AMELIA-MS-VERDICT main FAILED\n";
exit( 1 );
