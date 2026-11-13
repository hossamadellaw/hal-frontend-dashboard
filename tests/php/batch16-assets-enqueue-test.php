<?php
/**
 * HAL Frontend Dashboard — B16 assets-enqueue harness (v1.1.3 W6).
 *
 * Local, isolated harness: no live WordPress, no network, no database.
 * Measures the REAL asset-versioning mechanism in runtime/core/setup.php:
 * every dashboard asset version argument must be the filemtime ternary
 * (file_exists ? (string) filemtime : '1.0.0') — never a static release
 * string — and the emitted version for the core assets must equal the
 * real file's current mtime string (functional read of the same files the
 * enqueue resolves).
 *
 *   SHAPE    — the css/js/posts handles carry the filemtime ternary verbatim.
 *   FUNCTION — for dashboard.css, dashboard.js and posts.js the real files
 *              exist and their (string) filemtime equals what the ternary
 *              emits; none equals the '1.0.0' fallback.
 *   BAN      — no static 1.x.y version literal inside any enqueue call of
 *              setup.php, and every "'1.0.0'" occurrence is a filemtime
 *              fallback (the counts match one-to-one).
 *
 * Usage:  php tests/php/batch16-assets-enqueue-test.php
 * Exit:   0 = all assertions held, 1 = at least one failure.
 */

if ( ! defined( 'B16E_PROJECT' ) ) {
	$B16E_project = dirname( __DIR__, 2 );
	if ( ! is_file( $B16E_project . '/runtime/core/setup.php' ) ) {
		fwrite( STDERR, 'B16E: cannot locate the project root' . "\n" );
		exit( 1 );
	}
	define( 'B16E_PROJECT', $B16E_project );
}

$GLOBALS['B16E_RESULTS'] = [];
$GLOBALS['B16E_PASS']    = 0;
$GLOBALS['B16E_FAIL']    = 0;
$GLOBALS['B16E_FAILS']   = [];

function b16e_check( string $id, bool $ok, string $pass, string $fail ): void {
	$GLOBALS['B16E_RESULTS'][] = [ 'id' => $id, 'ok' => $ok, 'pass' => $pass, 'fail' => $fail ];
	if ( $ok ) {
		$GLOBALS['B16E_PASS']++;
	} else {
		$GLOBALS['B16E_FAIL']++;
		$GLOBALS['B16E_FAILS'][] = $id . ': ' . $fail;
	}
}

$setup_src = (string) file_get_contents( B16E_PROJECT . '/runtime/core/setup.php' );
$setup_src = str_replace( "\r\n", "\n", $setup_src );

/* ── SHAPE: the three core handles carry the filemtime ternary verbatim ── */
$handles = [
	'hossam-dashboard-css'   => [
		'path_expr' => '$css_path',
		'file'      => 'runtime/assets/css/dashboard.css',
	],
	'hossam-dashboard-js'    => [
		'path_expr' => '$js_path',
		'file'      => 'runtime/assets/js/dashboard.js',
	],
	'hossam-dashboard-posts' => [
		'path_expr' => '$posts_js_path',
		'file'      => 'runtime/assets/js/modules/posts.js',
	],
];
foreach ( $handles as $handle => $info ) {
	$needle = "'" . $handle . "'";
	$pos    = strpos( $setup_src, $needle );
	$region = ( false !== $pos ) ? substr( $setup_src, $pos, 700 ) : '';
	$shape  = 'file_exists( ' . $info['path_expr'] . ' ) ? (string) filemtime( ' . $info['path_expr'] . " ) : '1.0.0'";
	b16e_check(
		'B16E-S-' . $handle,
		false !== $pos && false !== strpos( $region, $shape ),
		$handle . ' versions its asset with the filemtime ternary (never a static release string)',
		$handle . ' is missing or its version argument is not the filemtime ternary'
	);
}

/* ── FUNCTION: the emitted version equals the real file's mtime string ── */
foreach ( $handles as $handle => $info ) {
	$real = B16E_PROJECT . '/' . $info['file'];
	$ok   = is_file( $real );
	$ver  = $ok ? (string) filemtime( $real ) : '';
	b16e_check(
		'B16E-F-' . $handle,
		$ok && '' !== $ver && '1.0.0' !== $ver && $ver === (string) (int) $ver,
		$handle . ' emits (string) filemtime of the real file (' . ( $ok ? substr( $ver, 0, 10 ) . '…' : 'missing' ) . ') — not the fallback',
		$handle . ': the real file is missing or the mtime is not a clean integer string'
	);
}

/* ── BAN: no static version literal, and every fallback is a filemtime ternary ── */
preg_match_all( '/wp_enqueue_(style|script)\\([^;]*?\\)\\s*;/s', $setup_src, $b16e_calls );
$b16e_static = [];
foreach ( $b16e_calls[0] as $b16e_call ) {
	if ( preg_match( "/,\\s*'(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)'\\s*\\)/", $b16e_call ) ) {
		$b16e_static[] = strtok( $b16e_call, "\n" );
	}
}
b16e_check(
	'B16E-B1-no-static-versions',
	[] === $b16e_static,
	'no enqueue call in setup.php carries a static x.y.z version argument',
	count( $b16e_static ) . ' static version argument(s) found'
);
$fallback_count = substr_count( $setup_src, "'1.0.0'" );
$filemtime_count = substr_count( $setup_src, 'filemtime(' );
b16e_check(
	'B16E-B2-fallback-parity',
	$fallback_count === $filemtime_count && $fallback_count > 0,
	"every '1.0.0' occurrence is the missing-file fallback of a filemtime ternary ({$filemtime_count} ternaries / {$fallback_count} fallbacks)",
	"fallback/ternary counts differ (filemtime={$filemtime_count} fallback='1.0.0'={$fallback_count})"
);

/* ── verdict ── */
echo "HAL Frontend Dashboard — B16 assets-enqueue test (v1.1.3 W6)\n";
echo str_repeat( '─', 72 ) . "\n";
foreach ( $GLOBALS['B16E_RESULTS'] as $result ) {
	echo ( $result['ok'] ? 'PASS' : 'FAIL' ) . ' [' . $result['id'] . '] ' . ( $result['ok'] ? $result['pass'] : $result['fail'] ) . "\n";
}
echo str_repeat( '─', 72 ) . "\n";
echo "B16E RESULT: {$GLOBALS['B16E_PASS']} pass, 0 skipped, {$GLOBALS['B16E_FAIL']} fail (PHP " . PHP_VERSION . ")\n";
if ( $GLOBALS['B16E_FAIL'] > 0 ) {
	echo "Failures:\n" . implode( "\n", $GLOBALS['B16E_FAILS'] ) . "\n";
	exit( 1 );
}
exit( 0 );
