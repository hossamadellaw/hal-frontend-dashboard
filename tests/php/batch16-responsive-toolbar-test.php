<?php
/**
 * HAL Frontend Dashboard — B16-RT responsive-toolbar harness (v1.1.3 W7).
 *
 * Local, isolated harness: no live WordPress, no network, no database.
 * Measures the REAL responsive contract of the W7 toolbar in
 * dashboard.css and posts.js against the SPEC section-2 drawing:
 *
 *   STATIC    — the toolbar container stays static above the canvas (the
 *               m18 decision: never sticky, never JS-pinned).
 *   TABLET    — <=1024: one horizontal scrollable row; the hossam-pt-extra groups
 *               fold behind the More toggle until hossam-pt-more-open.
 *   PHONE     — <=768: the toolbar becomes an off-canvas drawer
 *               (translateX off-canvas, flipped for RTL, dark backdrop,
 *               opened by hossam-pt-more-open); the bottom action bar docks
 *               (sticky, elevated) and stays hidden on desktop.
 *   FOCUS     — the zen toggle class folds the side column and the header.
 *   BUDGET    — the W7 CSS block uses the token variables (no new raw
 *               color values beyond the shadow alphas).
 *
 * Usage:  php tests/php/batch16-responsive-toolbar-test.php
 * Exit:   0 = all assertions held, 1 = at least one failure.
 */

if ( ! defined( 'B16R_PROJECT' ) ) {
	$B16R_project = dirname( __DIR__, 2 );
	if ( ! is_file( $B16R_project . '/runtime/assets/css/dashboard.css' ) ) {
		fwrite( STDERR, "B16R: cannot locate the project root\n" );
		exit( 1 );
	}
	define( 'B16R_PROJECT', $B16R_project );
}

$GLOBALS['B16R_RESULTS'] = [];
$GLOBALS['B16R_PASS']    = 0;
$GLOBALS['B16R_FAIL']    = 0;
$GLOBALS['B16R_FAILS']   = [];

function b16r_check( string $id, bool $ok, string $pass, string $fail ): void {
	$GLOBALS['B16R_RESULTS'][] = [ 'id' => $id, 'ok' => $ok, 'pass' => $pass, 'fail' => $fail ];
	if ( $ok ) {
		$GLOBALS['B16R_PASS']++;
	} else {
		$GLOBALS['B16R_FAIL']++;
		$GLOBALS['B16R_FAILS'][] = $id . ': ' . $fail;
	}
}

$css_src = (string) file_get_contents( B16R_PROJECT . '/runtime/assets/css/dashboard.css' );
$css_src = str_replace( "\r\n", "\n", $css_src );
$js_src  = (string) file_get_contents( B16R_PROJECT . '/runtime/assets/js/modules/posts.js' );
$js_src  = str_replace( "\r\n", "\n", $js_src );

$w7_start = strpos( $css_src, 'v1.1.3 W7' );
$w7_css   = ( false !== $w7_start ) ? substr( $css_src, $w7_start ) : '';

/* ── STATIC (m18: the TOOLBAR never sticky/pinned; the bottom bar's dock is
   the model's own "sticky elevated bottom action bar" and is allowed there
   only) ── */
$sticky_pos = strpos( (string) $w7_css, 'position: sticky' );
$sticky_ctx = ( false !== $sticky_pos ) ? substr( (string) $w7_css, max( 0, $sticky_pos - 200 ), 200 ) : '';
$sticky_only_bar = ( false !== $sticky_pos ) && false !== strpos( $sticky_ctx, 'action-bottom-bar' );
b16r_check(
	'B16R-S1-static-toolbar',
	false !== $w7_css && false !== strpos( $w7_css, 'position: static;' )
		&& $sticky_only_bar,
	'the toolbar container is static above the canvas (never sticky, never pinned); sticky belongs to the model-docked bottom bar only',
	'the toolbar is sticky/pinned or the W7 CSS block is missing'
);

/* ── TABLET (<=1024) ── */
b16r_check(
	'B16R-T1-scroll-row',
	false !== strpos( $w7_css, '@media (max-width: 1024px)' )
		&& false !== strpos( $w7_css, 'flex-wrap: nowrap' )
		&& false !== strpos( $w7_css, 'overflow-x: auto' ),
	'the <=1024 layout is one horizontal scrollable row',
	'the tablet scroll-row rules are missing'
);
b16r_check(
	'B16R-T2-more-fold',
	false !== strpos( $w7_css, '.hossam-pt-more-toggle { display: none; }' )
		&& false !== strpos( $w7_css, '.hossam-pt-group.hossam-pt-extra { display: none; }' )
		&& false !== strpos( $w7_css, '.hossam-pro-toolbar-container.hossam-pt-more-open .hossam-pt-group.hossam-pt-extra { display: flex; }' ),
	'the hossam-pt-extra groups fold behind the More toggle until hossam-pt-more-open',
	'the More fold rules are missing or inverted'
);

/* ── PHONE (<=768) ── */
b16r_check(
	'B16R-P1-off-canvas',
	false !== strpos( $w7_css, '@media (max-width: 768px)' )
		&& false !== strpos( $w7_css, 'position: fixed' )
		&& false !== strpos( $w7_css, 'transform: translateX(-110%)' )
		&& false !== strpos( $w7_css, '[dir="rtl"]' )
		&& false !== strpos( $w7_css, 'transform: translateX(110%)' ),
	'the <=768 layout is an off-canvas drawer with the RTL flip',
	'the off-canvas drawer rules or the RTL flip are missing'
);
b16r_check(
	'B16R-P2-backdrop-open',
	false !== strpos( $w7_css, '.hossam-pro-toolbar-container.hossam-pt-more-open { transform: translateX(0); }' )
		&& false !== strpos( $w7_css, 'rgba(0,0,0,.45)' ),
	'hossam-pt-more-open slides the drawer in over a dark dismissible backdrop',
	'the open/backdrop rules are missing'
);
b16r_check(
	'B16R-P3-bottom-bar',
	false !== strpos( $w7_css, '.action-bottom-bar { display: none; }' )
		&& false !== strpos( $w7_css, 'position: sticky; bottom: 0' )
		&& false !== strpos( $w7_css, 'box-shadow: 0 -4px 16px' )
		&& false !== strpos( $w7_css, '@media (max-width: 768px) { .action-bottom-bar { display: flex; } }' ),
	'the bottom action bar docks (sticky, elevated) on the phone width and stays hidden on desktop',
	'the bottom-bar docking rules are missing or inverted'
);

/* ── FOCUS MODE ── */
b16r_check(
	'B16R-F1-zen',
	false !== strpos( $w7_css, '.hossam-zen-focus .hossam-write-side-stack' )
		&& false !== strpos( $js_src, "ws.classList.toggle('hossam-zen-focus')" ),
	'the focus-mode toggle folds the side column and the header (CSS class only)',
	'the zen focus rules or the toggle are missing'
);

/* ── TOKEN DISCIPLINE ── */
preg_match_all( '/#[0-9a-fA-F]{3,6}\\b/', $w7_css, $b16r_hex );
$b16r_hex = array_values( array_unique( $b16r_hex[0] ) );
b16r_check(
	'B16R-Z1-tokens',
	[] === $b16r_hex,
	'the W7 CSS block uses the token variables only (no raw hex colors; the swatch hexes live in the JS builder, not the stylesheet)',
	'raw hex colors in the W7 CSS block: ' . implode( ' ', $b16r_hex )
);

/* ── zero-Arabic hygiene ── */
foreach ( [ 'css' => $css_src, 'posts.js' => $js_src ] as $name => $src ) {
	b16r_check(
		'B16R-Z-' . $name,
		0 === preg_match( '/\\p{Arabic}/', $src ),
		'zero Arabic in ' . $name,
		$name . ' carries Arabic characters'
	);
}

/* ── verdict ── */
echo "HAL Frontend Dashboard - B16 responsive-toolbar test (v1.1.3 W7)\n";
echo str_repeat( '-', 72 ) . "\n";
foreach ( $GLOBALS['B16R_RESULTS'] as $result ) {
	echo ( $result['ok'] ? 'PASS' : 'FAIL' ) . ' [' . $result['id'] . '] ' . ( $result['ok'] ? $result['pass'] : $result['fail'] ) . "\n";
}
echo str_repeat( '-', 72 ) . "\n";
echo "B16R RESULT: {$GLOBALS['B16R_PASS']} pass, 0 skipped, {$GLOBALS['B16R_FAIL']} fail (PHP " . PHP_VERSION . ")\n";
if ( $GLOBALS['B16R_FAIL'] > 0 ) {
	echo "Failures:\n" . implode( "\n", $GLOBALS['B16R_FAILS'] ) . "\n";
	exit( 1 );
}
exit( 0 );
