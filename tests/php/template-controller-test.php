<?php
/**
 * HAL Frontend Dashboard — Batch 11 harness: template controller.
 *
 * Local, isolated harness: no live WordPress, no network, no database.
 * It stubs only the WordPress API surface and then drives the REAL
 * includes/class-template-controller.php (the §6.1 shared source; byte-
 * identity with the runtime/infrastructure copy is asserted here).
 *
 * Coverage (§22 template-controller row):
 *   target page only (ID predicate, no slug/query-param/template-name),
 *   unavailable runtime fail-safe, WPML translation targets, closed part
 *   allowlist, and theme independence (no child-theme loaders).
 *
 * Usage:  php tests/php/template-controller-test.php            (main)
 *         php tests/php/template-controller-test.php nocontext (subprocess)
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

$GLOBALS['HAL_TC_RESULTS'] = array();
$GLOBALS['HAL_TC_HOOKS'] = array();
$GLOBALS['HAL_TC_OPTIONS'] = array();
$GLOBALS['HAL_TC_POSTS'] = array();
$GLOBALS['HAL_TC_QUERIED'] = null;
$GLOBALS['HAL_TC_WPML'] = null;

function hal_tc_check( string $id, bool $condition, string $pass, string $fail = '' ): void {
	$GLOBALS['HAL_TC_RESULTS'][] = array( 'id' => $id, 'ok' => $condition, 'pass' => $pass, 'fail' => $fail );
	echo ( $condition ? 'PASS' : 'FAIL' ) . " [$id] " . ( $condition ? $pass : $fail ) . "\n";
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/hal-tc-' . getmypid() . '/' );
}
@mkdir( ABSPATH, 0777, true );

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook_name, $callback = '', int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['HAL_TC_HOOKS'][ $hook_name ][] = $callback;
		return true;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook_name, $callback = '', int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['HAL_TC_HOOKS'][ $hook_name ][] = $callback;
		return true;
	}
}
if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( string $hook_name, $callback = false ) {
		if ( 'wpml_element_trid' === $hook_name && null !== $GLOBALS['HAL_TC_WPML'] ) {
			return true;
		}
		$entries = $GLOBALS['HAL_TC_HOOKS'][ $hook_name ] ?? array();
		if ( false === $callback ) {
			return array() !== $entries;
		}
		return in_array( $callback, $entries, true );
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook_name, $value, ...$args ) {
		if ( 'wpml_element_trid' === $hook_name && null !== $GLOBALS['HAL_TC_WPML'] ) {
			return $GLOBALS['HAL_TC_WPML']['trid'];
		}
		if ( 'wpml_get_element_translations' === $hook_name && null !== $GLOBALS['HAL_TC_WPML'] ) {
			return $GLOBALS['HAL_TC_WPML']['translations'];
		}
		foreach ( $GLOBALS['HAL_TC_HOOKS'][ $hook_name ] ?? array() as $callback ) {
			$value = call_user_func_array( $callback, array_merge( array( $value ), $args ) );
		}
		return $value;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default_value = false ) {
		return array_key_exists( $option, $GLOBALS['HAL_TC_OPTIONS'] ) ? $GLOBALS['HAL_TC_OPTIONS'][ $option ] : $default_value;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, $value, $autoload = null ): bool {
		$GLOBALS['HAL_TC_OPTIONS'][ $option ] = $value;
		return true;
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post_id = null ) {
		return $GLOBALS['HAL_TC_POSTS'][ (int) $post_id ] ?? null;
	}
}
if ( ! function_exists( 'get_queried_object' ) ) {
	function get_queried_object() {
		return $GLOBALS['HAL_TC_QUERIED'];
	}
}
if ( ! class_exists( 'WP_Post' ) ) {
	#[AllowDynamicProperties]
	class WP_Post {
		public int $ID = 0;
		public string $post_type = '';
		public string $post_name = '';
		public string $post_status = 'publish';
		public function __construct( array $fields = array() ) {
			foreach ( $fields as $key => $value ) {
				$this->{$key} = $value;
			}
		}
	}
}

if ( 'main' === $mode && ! defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_DIR' ) ) {
	define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_DIR', $project . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR );
}
/* WPML-present simulation: the real plugin defines this constant. */
if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
	define( 'ICL_SITEPRESS_VERSION', '4.0-tc-harness' );
}

require $project . '/includes/class-template-controller.php';

function hal_tc_report( string $mode ): void {
	$pass = 0;
	$total = count( $GLOBALS['HAL_TC_RESULTS'] );
	foreach ( $GLOBALS['HAL_TC_RESULTS'] as $result ) {
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

function hal_tc_strip_comments( string $code ): string {
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

if ( 'nocontext' === $mode ) {
	/* No HAL_FRONTEND_DASHBOARD_RUNTIME_DIR here: every resolution fails safe. */
	$GLOBALS['HAL_TC_POSTS'][42] = new WP_Post( array( 'ID' => 42, 'post_type' => 'page', 'post_name' => 'dashboard' ) );
	$GLOBALS['HAL_TC_OPTIONS']['hal_frontend_dashboard_page_id'] = 42;
	$GLOBALS['HAL_TC_QUERIED'] = new WP_Post( array( 'ID' => 42, 'post_type' => 'page', 'post_name' => 'dashboard' ) );
	$original = '/theme/orig.php';
	hal_tc_check(
		'T-NOCONTEXT',
		$original === HAL_Frontend_Dashboard_Template_Controller::resolve_template( $original ),
		'without release context the original template is returned even for the owned page',
		'template changed without runtime context'
	);
	ob_start();
	HAL_Frontend_Dashboard_Template_Controller::render_part( 'overview' );
	$part_out = (string) ob_get_clean();
	hal_tc_check( 'T-NOCONTEXT-PART', '' === $part_out, 'part render without context produces no output', 'output leaked' );
	hal_tc_report( 'nocontext' );
}

/* ── MAIN ── */

/* T1 — registration at file load. */
$registered = false;
foreach ( $GLOBALS['HAL_TC_HOOKS']['template_include'] ?? array() as $callback ) {
	if ( array( 'HAL_Frontend_Dashboard_Template_Controller', 'resolve_template' ) === $callback ) {
		$registered = true;
	}
}
hal_tc_check( 'T1-REGISTERED', $registered, 'template_include registered with the controller callback at file load', 'registration missing' );

/* T2 — §6.1 byte identity. */
$src_sha = hash_file( 'sha256', $project . '/includes/class-template-controller.php' );
$rt_sha = hash_file( 'sha256', $project . '/runtime/infrastructure/class-template-controller.php' );
hal_tc_check( 'T2-MIRROR', is_string( $src_sha ) && $src_sha === $rt_sha, 'infrastructure copy byte-identical to includes/ source', 'drift detected' );

/* T3 — closed allowlist of the twelve documented parts. */
$expected_parts = array( 'overview', 'posts', 'files', 'bookings', 'seo', 'translations', 'profile', 'inbox', 'finance', 'store', 'admin', 'members' );
hal_tc_check( 'T3-ALLOWLIST', $expected_parts === HAL_Frontend_Dashboard_Template_Controller::PANEL_PARTS, 'PANEL_PARTS is exactly the twelve documented parts', 'mismatch: ' . json_encode( HAL_Frontend_Dashboard_Template_Controller::PANEL_PARTS ) );

/* T4 — no owned page: fail-closed everywhere. */
$GLOBALS['HAL_TC_QUERIED'] = new WP_Post( array( 'ID' => 12, 'post_type' => 'page', 'post_name' => 'dashboard' ) );
hal_tc_check( 'T4-NO-OWNED', '/theme/orig.php' === HAL_Frontend_Dashboard_Template_Controller::resolve_template( '/theme/orig.php' ), 'slug-matched page without owned page keeps the original template', 'template hijacked' );
hal_tc_check( 'T4-EMPTY-TARGETS', array() === HAL_Frontend_Dashboard_Template_Controller::get_target_page_ids(), 'no targets without an owned page', 'targets leaked' );

/* T5 — owned page: target resolves into this request release. */
$GLOBALS['HAL_TC_POSTS'][42] = new WP_Post( array( 'ID' => 42, 'post_type' => 'page', 'post_name' => 'dashboard' ) );
$GLOBALS['HAL_TC_POSTS'][43] = new WP_Post( array( 'ID' => 43, 'post_type' => 'page', 'post_name' => 'other' ) );
$GLOBALS['HAL_TC_OPTIONS']['hal_frontend_dashboard_page_id'] = 42;
$GLOBALS['HAL_TC_QUERIED'] = new WP_Post( array( 'ID' => 42, 'post_type' => 'page', 'post_name' => 'dashboard' ) );
$resolved = HAL_Frontend_Dashboard_Template_Controller::resolve_template( '/theme/orig.php' );
$expected_path = realpath( $project . '/runtime/templates/dashboard.php' );
$release_real = realpath( HAL_FRONTEND_DASHBOARD_RUNTIME_DIR );
hal_tc_check( 'T5-TARGET', is_string( $resolved ) && $expected_path === $resolved, 'owned page resolves to the validated runtime shell', 'got: ' . var_export( $resolved, true ) );
hal_tc_check( 'T5-CONTAINMENT', is_string( $resolved ) && 0 === strpos( $resolved, $release_real . DIRECTORY_SEPARATOR ), 'resolved path stays inside this request release root', 'escape detected' );

/* T6 — different ID, dashboard slug: not a target (no slug hijack). */
$GLOBALS['HAL_TC_QUERIED'] = new WP_Post( array( 'ID' => 43, 'post_type' => 'page', 'post_name' => 'dashboard' ) );
hal_tc_check( 'T6-SLUG', '/theme/orig.php' === HAL_Frontend_Dashboard_Template_Controller::resolve_template( '/theme/orig.php' ), 'same slug with a different ID keeps the original template', 'slug hijack' );

/* T7 — non-page object never a target. */
$GLOBALS['HAL_TC_QUERIED'] = new WP_Post( array( 'ID' => 42, 'post_type' => 'post', 'post_name' => 'dashboard' ) );
hal_tc_check( 'T7-NON-PAGE', '/theme/orig.php' === HAL_Frontend_Dashboard_Template_Controller::resolve_template( '/theme/orig.php' ), 'non-page object keeps the original template', 'post type leaked' );
$GLOBALS['HAL_TC_POSTS'][42]->post_status = 'trash';
$GLOBALS['HAL_TC_QUERIED'] = new WP_Post( array( 'ID' => 42, 'post_type' => 'page', 'post_status' => 'trash' ) );
hal_tc_check( 'T7-TRASHED', 0 === HAL_Frontend_Dashboard_Template_Controller::get_owned_page_id()
	&& '/theme/orig.php' === HAL_Frontend_Dashboard_Template_Controller::resolve_template( '/theme/orig.php' ),
	'trashed stored page cannot select the runtime template', 'trashed page accepted' );
$GLOBALS['HAL_TC_POSTS'][42]->post_status = 'publish';

/* T8 — request input never selects the template. */
$_GET['page_id'] = 42;
$_GET['pagename'] = 'dashboard';
$_REQUEST['page_id'] = 42;
$GLOBALS['HAL_TC_QUERIED'] = new WP_Post( array( 'ID' => 43, 'post_type' => 'page', 'post_name' => 'other' ) );
hal_tc_check( 'T8-NO-INPUT', '/theme/orig.php' === HAL_Frontend_Dashboard_Template_Controller::resolve_template( '/theme/orig.php' ), 'query parameters cannot select the runtime template', 'input-driven selection' );
unset( $_GET['page_id'], $_GET['pagename'], $_REQUEST['page_id'] );

/* T9 — WPML's sitepress.class.php get_element_translations() returns
 * array<string, stdClass>, keyed by language code. The filter calls that
 * method, so translation IDs are object properties. Invalid elements
 * (including array fixtures) must not select a target page. */
$GLOBALS['HAL_TC_WPML'] = array(
	'trid'         => 5,
	'translations' => array(
		'en'      => (object) array( 'translation_id' => 42, 'language_code' => 'en', 'element_id' => 42, 'source_language_code' => null, 'original' => true, 'post_title' => 'Dashboard', 'post_status' => 'publish' ),
		'fr'      => (object) array( 'translation_id' => 77, 'language_code' => 'fr', 'element_id' => 77, 'source_language_code' => 'en', 'original' => false, 'post_title' => 'Tableau', 'post_status' => 'publish' ),
		'broken'  => 'not-an-element',
		'missing' => (object) array( 'language_code' => 'de' ),
		'array'   => array( 'element_id' => 88 ),
	),
);
$GLOBALS['HAL_TC_POSTS'][77] = new WP_Post( array( 'ID' => 77, 'post_type' => 'page', 'post_name' => 'dashboard-fr' ) );
$targets = HAL_Frontend_Dashboard_Template_Controller::get_target_page_ids();
sort( $targets );
hal_tc_check( 'T9-WPML', array( 42, 77 ) === $targets, 'WPML translation objects join the target set; malformed elements are ignored', 'targets: ' . json_encode( $targets ) );
$GLOBALS['HAL_TC_WPML'] = array( 'trid' => 0, 'translations' => null );
hal_tc_check( 'T9-WPML-EMPTY', array( 42 ) === HAL_Frontend_Dashboard_Template_Controller::get_target_page_ids(), 'invalid trid falls back to the owned page only', 'got: ' . json_encode( HAL_Frontend_Dashboard_Template_Controller::get_target_page_ids() ) );
$GLOBALS['HAL_TC_WPML'] = null;

/* T10 — invalid parts fail closed with no output. */
ob_start();
HAL_Frontend_Dashboard_Template_Controller::render_part( '../wp-config' );
$bad1 = (string) ob_get_clean();
ob_start();
HAL_Frontend_Dashboard_Template_Controller::render_part( 'notapart' );
$bad2 = (string) ob_get_clean();
hal_tc_check( 'T10-INVALID-PARTS', '' === $bad1 && '' === $bad2, 'traversal and unknown part names produce no output', 'output leaked' );

/* T11 — theme independence: no child-theme loaders, no input superglobals. */
$code = hal_tc_strip_comments( (string) file_get_contents( $project . '/includes/class-template-controller.php' ) );
$needles = array( 'get_template_part(', 'get_stylesheet_directory', 'get_template_directory', 'is_page_template(', '$_GET', '$_POST', '$_REQUEST', '$_COOKIE' );
$hits = array();
foreach ( $needles as $needle ) {
	if ( false !== strpos( $code, $needle ) ) {
		$hits[] = $needle;
	}
}
hal_tc_check( 'T11-THEME-INDEPENDENCE', array() === $hits, 'no theme loaders, template-name checks, or input superglobals in live code', 'hits: ' . json_encode( $hits ) );

/* T12 — unavailable-runtime subprocess (no release context). */
$php = PHP_BINARY;
$cmd = array( $php, __FILE__, 'nocontext' );
$stdout_file = sys_get_temp_dir() . '/tcout-' . bin2hex( random_bytes( 8 ) ) . '.txt';
$stderr_file = sys_get_temp_dir() . '/tcerr-' . bin2hex( random_bytes( 8 ) ) . '.txt';
$proc = proc_open( $cmd, array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $stdout_file, 'w' ), 2 => array( 'file', $stderr_file, 'w' ) ), $pipes );
if ( ! is_resource( $proc ) ) {
	hal_tc_check( 'T12-NOCONTEXT', false, '', 'proc_open failed' );
} else {
	fclose( $pipes[0] );
	$exit = proc_close( $proc );
	$out = (string) file_get_contents( $stdout_file );
	$err = (string) file_get_contents( $stderr_file );
	@unlink( $stdout_file );
	@unlink( $stderr_file );
	hal_tc_check(
		'T12-NOCONTEXT',
		0 === $exit && false !== strpos( $out, 'HAL-VERDICT nocontext ALL-ASSERTIONS-HELD' ),
		'no-context subprocess fails safe with the original template (exit 0)',
		'exit ' . $exit . '; stdout: ' . trim( $out ) . '; stderr: ' . trim( $err )
	);
}

$pass = 0;
foreach ( $GLOBALS['HAL_TC_RESULTS'] as $result ) {
	if ( $result['ok'] ) {
		$pass++;
	}
}
$total = count( $GLOBALS['HAL_TC_RESULTS'] );
echo "RESULT: $pass/$total checks passed\n";
exit( $pass === $total ? 0 : 1 );
