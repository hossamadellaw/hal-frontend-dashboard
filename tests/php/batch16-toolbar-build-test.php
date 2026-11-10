<?php
/**
 * HAL Frontend Dashboard — B16-TB toolbar-build harness (v1.1.3 W7).
 *
 * Local, isolated harness: no live WordPress, no network, no database.
 * Measures the REAL wave-7 toolbar build in posts.js against the pinned
 * model structure and the i18n channel:
 *
 *   STRUCTURE — the ten groups + More + History built statically inside
 *               posts.js (no new module file), mounted above the canvas via
 *               the init hook, with the H1 level strictly excluded from the
 *               heading select (the heading-policy lock).
 *   WIRING    — every tool dispatches document.execCommand into the canvas
 *               iframe only; the bottom action bar serves the EXISTING
 *               create-form contract (status select + hossamCreateArticle),
 *               and the Discard action reuses the existing parse/keep path.
 *   CHANNEL   — the annex-10-a dynamic value<->fallback check: every
 *               t('key','fallback') in posts.js is extracted and matched
 *               against the hossam_get_i18n_strings map — the key must
 *               exist and its registered value must equal the fallback
 *               verbatim (token-based extraction on the PHP side).
 *
 * Usage:  php tests/php/batch16-toolbar-build-test.php
 * Exit:   0 = all assertions held, 1 = at least one failure.
 */

if ( ! defined( 'B16T_PROJECT' ) ) {
	$B16T_project = dirname( __DIR__, 2 );
	if ( ! is_file( $B16T_project . '/runtime/assets/js/modules/posts.js' ) ) {
		fwrite( STDERR, "B16T: cannot locate the project root\n" );
		exit( 1 );
	}
	define( 'B16T_PROJECT', $B16T_project );
}

$GLOBALS['B16T_RESULTS'] = [];
$GLOBALS['B16T_PASS']    = 0;
$GLOBALS['B16T_FAIL']    = 0;
$GLOBALS['B16T_FAILS']   = [];

function b16t_check( string $id, bool $ok, string $pass, string $fail ): void {
	$GLOBALS['B16T_RESULTS'][] = [ 'id' => $id, 'ok' => $ok, 'pass' => $pass, 'fail' => $fail ];
	if ( $ok ) {
		$GLOBALS['B16T_PASS']++;
	} else {
		$GLOBALS['B16T_FAIL']++;
		$GLOBALS['B16T_FAILS'][] = $id . ': ' . $fail;
	}
}

$js_src     = (string) file_get_contents( B16T_PROJECT . '/runtime/assets/js/modules/posts.js' );
$js_src     = str_replace( "\r\n", "\n", $js_src );
$i18n_src   = (string) file_get_contents( B16T_PROJECT . '/runtime/core/i18n.php' );
$i18n_src   = str_replace( "\r\n", "\n", $i18n_src );
$setup_src  = (string) file_get_contents( B16T_PROJECT . '/runtime/core/setup.php' );

$block_start = strpos( $js_src, '[W7] v1.1.3: the professional toolbar' );
$block       = ( false !== $block_start ) ? substr( $js_src, $block_start ) : '';
fwrite(STDERR, "DBG start=".var_export($block_start,true)." len=".strlen($block)." preg=".preg_match("/\['h1'/", $block)." h2=".var_export(strpos($block,'\x27w7OptH2\x27'),true)." h4=".var_export(strpos($block,'\x27w7OptH4\x27'),true)."
");

/* ── STRUCTURE ── */
b16t_check(
	'B16T-S1-single-file',
	false !== $block_start
		&& false !== strpos( $block, 'function hossamToolbarBuild(' )
		&& false !== strpos( $block, 'function hossamToolbarMount(' )
		&& false !== strpos( $block, 'function hossamToolbarBuildActionBar(' ),
	'the professional toolbar builds inside posts.js (no new module file)',
	'the W7 toolbar block is missing or incomplete'
);
b16t_check(
	'B16T-S2-ten-groups',
	10 === preg_match_all( '/var g\d+=group\(/', $block ),
	'ten tool groups are built (the model: ten groups + More + History)',
	'expected 10 group() builders, found ' . preg_match_all( '/var g\d+=group\(/', $block )
);
b16t_check(
	'B16T-S3-more-history',
	false !== strpos( $block, 'hossam-pt-more-toggle' )
		&& false !== strpos( $block, "'w7Undo'" )
		&& false !== strpos( $block, "'w7Redo'" ),
	'the More toggle and the History group are present',
	'the More toggle or the History group is missing'
);
$S4_preg = preg_match( "/\['h1'/", $block );
b16t_check(
	'B16T-S4-h1-excluded',
	0 === $S4_preg
		&& false !== strpos( $block, 'w7OptH2' )
		&& false !== strpos( $block, 'w7OptH4' ),
	'the heading select offers p/H2/H3/H4 only — H1 strictly excluded (the heading-policy lock; preg_match returns int 0, never false)',
	'an H1 option leaked into the heading select'
);
b16t_check(
	'B16T-S5-mount-hook',
	1 === substr_count( $js_src, 'hossamToolbarMount(containerId,container);' )
		&& strpos( $js_src, 'hossamToolbarMount(containerId,container);' ) > strpos( $js_src, "container.removeAttribute('hidden');" ),
	'the toolbar mounts once per successful init, above the canvas (after the ready gate)',
	'the mount hook is missing, duplicated, or outside the init success path'
);
b16t_check(
	'B16T-S6-dedupe-guard',
	false !== strpos( $block, "container.querySelector('.hossam-pro-toolbar-container')" ) && false !== strpos( $block, 'return;' ),
	'the mount is idempotent (a second init never duplicates the toolbar)',
	'the mount dedupe guard is missing'
);

/* ── WIRING ── */
b16t_check(
	'B16T-W1-iframe-only',
	false !== strpos( $block, "querySelector('[data-role=\"canvas\"] iframe, .hossam-rich-root iframe')" )
		&& false !== strpos( $block, "doc.execCommand(" ),
	'the tools dispatch execCommand into the canvas iframe document only',
	'the execCommand target is not the canvas iframe'
);
b16t_check(
	'B16T-W2-bar-form-contract',
	false !== strpos( $block, "getElementById('hossam-new-status')" )
		&& false !== strpos( $block, "getElementById('hossam-post-create-form')" )
		&& false !== strpos( $block, 'hossamCreateArticle(f)' )
		&& false !== strpos( $block, "submitWith('publish')" )
		&& false !== strpos( $block, "submitWith('draft')" ),
	'the bottom action bar serves the existing create-form contract (status select + hossamCreateArticle)',
	'the bottom bar leaves the existing form contract'
);
b16t_check(
	'B16T-W3-discard-reparse',
	false !== strpos( $js_src, 'rec.api={keep:keep,reparse:' )
		&& false !== strpos( $block, 'rec.api.reparse' ),
	'Discard re-parses the textarea through the existing parse path and commits via the existing keep()',
	'the Discard action does not reuse the existing parse/keep path'
);
b16t_check(
	'B16T-W4-write-editor-only',
	false !== strpos( $block, "containerId==='hossam-rich-editor-new'" ),
	'the bottom action bar attaches to the write editor only (the model scope)',
	'the bottom bar attaches outside the write editor'
);

/* ── CHANNEL: the dynamic value<->fallback match (annex 10-a) ── */
preg_match_all( "/(?<![A-Za-z0-9_$])t\\('((?:[^'\\\\]|\\\\.)*)'\\s*,\\s*'((?:[^'\\\\]|\\\\.)*)'\\)/", $js_src, $b16t_calls, PREG_SET_ORDER );
$map_start = strpos( $i18n_src, 'function hossam_get_i18n_strings' );
$map_src   = ( false !== $map_start ) ? substr( $i18n_src, $map_start ) : '';
$map_tokens = ( '' !== $map_src ) ? token_get_all( '<?php ' . $map_src ) : [];
$b16t_map = [];
$pending_key = null;
foreach ( $map_tokens as $tok ) {
	if ( is_array( $tok ) && T_CONSTANT_ENCAPSED_STRING === $tok[0] ) {
		$inner = substr( $tok[1], 1, -1 );
		if ( "'" === $tok[1][0] ) {
			$inner = str_replace( [ "\\'", '\\\\' ], [ "'", '\\' ], $inner );
		} else {
			$inner = stripcslashes( $inner );
		}
		if ( null === $pending_key ) {
			$pending_key = $inner;
		} else {
			$b16t_map[ $pending_key ] = $inner;
			$pending_key = null;
		}
	} elseif ( is_array( $tok ) && T_DOUBLE_ARROW === $tok[0] ) {
		continue;
	} elseif ( ',' === $tok || ')' === $tok ) {
		$pending_key = null;
	}
}
$b16t_bad = [];
$b16t_seen = 0;
// The owner-approved fallback-only exclusion (i18n.php documents it:
// loadFailed stays unregistered by decision; it has no map key by design).
$b16t_excluded = [ 'loadFailed' ];
foreach ( $b16t_calls as $call ) {
	$key = stripcslashes( $call[1] );
	$fallback = stripcslashes( $call[2] );
	if ( in_array( $key, $b16t_excluded, true ) ) {
		continue;
	}
	$b16t_seen++;
	if ( ! isset( $b16t_map[ $key ] ) ) {
		$b16t_bad[] = $key . ' (unregistered)';
	} elseif ( $b16t_map[ $key ] !== $fallback ) {
		$b16t_bad[] = $key . ' (map="' . $b16t_map[ $key ] . '" != fallback="' . $fallback . '")';
	}
}
b16t_check(
	'B16T-C1-value-fallback',
	[] === $b16t_bad && $b16t_seen > 0 && [] !== $b16t_map,
	'every t(key, fallback) in posts.js matches its registered map value verbatim (' . $b16t_seen . ' calls, ' . count( $b16t_map ) . ' map keys)',
	'mismatches: ' . implode( ' | ', array_slice( $b16t_bad, 0, 8 ) )
);

/* ─ INTERSECTION: every W7 class token used anywhere in the posts.js
   block (word-boundary extraction, composed strings included) carries a
   dashboard.css selector - the gate-verdict guard: the doubled-prefix
   corruption was invisible to the separate CSS/JS needles. */
preg_match_all( "/hossam-(?:pt-[a-z-]+|pro-toolbar-container)/", $block, $b16t_cls );
$b16t_tokens = array_values( array_unique( $b16t_cls[0] ) );
$b16t_css = (string) file_get_contents( B16T_PROJECT . '/runtime/assets/css/dashboard.css' );
$b16t_css = str_replace( "
", "
", $b16t_css );
$b16t_missing_sel = [];
foreach ( $b16t_tokens as $tok ) {
    if ( false === strpos( $b16t_css, '.' . $tok ) ) { $b16t_missing_sel[] = $tok; }
}
b16t_check(
	'B16T-I1-css-js-intersection',
	[] !== $b16t_tokens && 16 === count( $b16t_tokens ) && [] === $b16t_missing_sel,
	'every W7 class token used in posts.js carries a dashboard.css selector (16 tokens: the toolbar family plus the container, composed strings included)',
	'tokens=' . count( $b16t_tokens ) . ' without a selector: ' . implode( ' | ', $b16t_missing_sel )
);

/* ─ EXTENDED PAIRS: every w7-key fallback pair in the block (select
   titles, option triples, case items, swatch names) cross-checked against
   the i18n ledger - the textual t() regex cannot see array-shaped pairs. */
preg_match_all( "/,'(w7[A-Za-z0-9]*)','((?:[^'\\\\]|\\\\.)*)'[,\\]]/", $block, $b16t_arr, PREG_SET_ORDER );
$b16t_arr_bad = [];
$b16t_arr_seen = 0;
foreach ( $b16t_arr as $pair ) {
	$key = $pair[1];
	$fallback = stripcslashes( $pair[2] );
	$b16t_arr_seen++;
	if ( ! isset( $b16t_map[ $key ] ) ) {
		$b16t_arr_bad[] = $key . ' (unregistered)';
	} elseif ( $b16t_map[ $key ] !== $fallback ) {
		$b16t_arr_bad[] = $key . ' (map mismatch)';
	}
}
b16t_check(
	'B16T-C2-extended-array-pairs',
	[] === $b16t_arr_bad && $b16t_arr_seen >= 30,
	'every w7-key fallback pair in the W7 block matches its registered map value verbatim (' . $b16t_arr_seen . ' pairs: select titles plus option triples plus case items plus swatch names)',
	'seen=' . $b16t_arr_seen . ' mismatches: ' . implode( ' | ', array_slice( $b16t_arr_bad, 0, 8 ) )
);


/* ── zero-Arabic hygiene ── */
foreach ( [ 'posts.js' => $js_src, 'i18n.php' => $i18n_src ] as $name => $src ) {
	b16t_check(
		'B16T-Z-' . $name,
		0 === preg_match( '/\\p{Arabic}/', $src ),
		'zero Arabic in ' . $name,
		$name . ' carries Arabic characters'
	);
}

/* ── verdict ── */
echo "HAL Frontend Dashboard - B16 toolbar-build test (v1.1.3 W7)\n";
echo str_repeat( '-', 72 ) . "\n";
foreach ( $GLOBALS['B16T_RESULTS'] as $result ) {
	echo ( $result['ok'] ? 'PASS' : 'FAIL' ) . ' [' . $result['id'] . '] ' . ( $result['ok'] ? $result['pass'] : $result['fail'] ) . "\n";
}
echo str_repeat( '-', 72 ) . "\n";
echo "B16T RESULT: {$GLOBALS['B16T_PASS']} pass, 0 skipped, {$GLOBALS['B16T_FAIL']} fail (PHP " . PHP_VERSION . ")\n";
if ( $GLOBALS['B16T_FAIL'] > 0 ) {
	echo "Failures:\n" . implode( "\n", $GLOBALS['B16T_FAILS'] ) . "\n";
	exit( 1 );
}
exit( 0 );
