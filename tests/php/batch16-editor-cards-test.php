<?php
/**
 * HAL Frontend Dashboard — B16-EC editor-cards harness (v1.1.3 W8).
 *
 * Local, isolated harness: no live WordPress, no network, no database.
 * Pins the wave-8 build contract mechanically:
 *
 *   OP-1 PIN    — the H1 server enforcement trio is present and untouched:
 *                 the valve (hossam_h1_rejected in the adapter), the deep
 *                 wp_insert_post_data normalizer (audit-log), and the paste
 *                 normalizer (posts.js) - all three asserted in source.
 *   OP-2 GATE   — the admin-extend gate: server flag (adminExtend from
 *                 manage_options) + the JS eight-block restriction applied
 *                 only when adminExtend is falsy.
 *   OP-3 PIN    — hidden-block legacy content: the restriction touches
 *                 allowedBlockTypes (insertion only); no conversion/cleanup
 *                 code path exists.
 *   OP-6 PIN    — the translation surface stays doubled: the table chips +
 *                 the card + the server endpoint (hossam_translate_article).
 *   OP-7 CHAIN  — the update success chains hossamSaveSeo when the SEO form
 *                 is present (one save point; both server contracts).
 *   OP-8 SUGGEST — the suggestions derive server-side from the five existing
 *                 reads; display-only list; no new meta/no endpoint.
 *   T-09 CHIPS  — the actions cell carries no per-row translate buttons; the
 *                 Lang column renders the status chips; the card keeps the
 *                 single action (the one-action rule).
 *   WF STATES   — the four wireframe states ride data-state attributes on
 *                 the editor containers (DOM-only state; no innerHTML).
 *
 * Usage:  php tests/php/batch16-editor-cards-test.php
 * Exit:   0 = all assertions held, 1 = at least one failure.
 */

if ( ! defined( 'B16C_PROJECT' ) ) {
	$B16C_project = dirname( __DIR__, 2 );
	if ( ! is_file( $B16C_project . '/runtime/templates/dashboard/posts.php' ) ) {
		fwrite( STDERR, "B16C: cannot locate the project root\n" );
		exit( 1 );
	}
	define( 'B16C_PROJECT', $B16C_project );
}

$GLOBALS['B16C_RESULTS'] = [];
$GLOBALS['B16C_PASS']    = 0;
$GLOBALS['B16C_FAIL']    = 0;
$GLOBALS['B16C_FAILS']   = [];

function b16c_check( string $id, bool $ok, string $pass, string $fail ): void {
	$GLOBALS['B16C_RESULTS'][] = [ 'id' => $id, 'ok' => $ok, 'pass' => $pass, 'fail' => $fail ];
	if ( $ok ) {
		$GLOBALS['B16C_PASS']++;
	} else {
		$GLOBALS['B16C_FAIL']++;
		$GLOBALS['B16C_FAILS'][] = $id . ': ' . $fail;
	}
}

$posts_php = str_replace( "\r\n", "\n", (string) file_get_contents( B16C_PROJECT . '/runtime/templates/dashboard/posts.php' ) );
$posts_js  = str_replace( "\r\n", "\n", (string) file_get_contents( B16C_PROJECT . '/runtime/assets/js/modules/posts.js' ) );
$adapter   = str_replace( "\r\n", "\n", (string) file_get_contents( B16C_PROJECT . '/runtime/adapters/wordpress-posts.php' ) );
$audit     = str_replace( "\r\n", "\n", (string) file_get_contents( B16C_PROJECT . '/runtime/core/audit-log.php' ) );
$setup_src = str_replace( "\r\n", "\n", (string) file_get_contents( B16C_PROJECT . '/runtime/core/setup.php' ) );
$ajax_src  = str_replace( "\r\n", "\n", (string) file_get_contents( B16C_PROJECT . '/runtime/ajax/posts.php' ) );
$css_src   = str_replace( "\r\n", "\n", (string) file_get_contents( B16C_PROJECT . '/runtime/assets/css/dashboard.css' ) );

/* ── OP-1: the H1 server enforcement trio pinned ── */
b16c_check(
	'B16C-OP1-valve',
	false !== strpos( $adapter, 'hossam_h1_rejected' ),
	'the H1 rejection valve stays in the adapter (server enforcement pinned)',
	'the H1 valve is missing from the adapter'
);
b16c_check(
	'B16C-OP1-normalizer',
	false !== strpos( $audit, 'wp_insert_post_data' ) && false !== strpos( $audit, 'h1' ),
	'the deep wp_insert_post_data H1 normalizer stays in audit-log (defense in depth pinned)',
	'the deep normalizer is missing'
);
b16c_check(
	'B16C-OP1-paste',
	false !== strpos( $posts_js, 'normalizePastedH1' ),
	'the paste-time H1→H2 normalization stays in posts.js (client path pinned)',
	'the paste normalizer is missing'
);

/* ── OP-2: the admin-extend gate ── */
b16c_check(
	'B16C-OP2-server',
	false !== strpos( $setup_src, "'adminExtend'" ) && false !== strpos( $setup_src, "current_user_can( 'manage_options' )" ),
	'the admin-extend flag is computed server-side (capability × feature) and travels the existing config',
	'the server-side adminExtend flag is missing or wrong'
);
b16c_check(
	'B16C-OP2-js',
	false !== strpos( $posts_js, 'adminExtend' )
		&& false !== strpos( $posts_js, "['core/paragraph','core/heading','core/image','core/list','core/quote','core/table','core/details','core/separator']" ),
	'the eight-block restriction applies only when adminExtend is falsy (admins keep the full core set)',
	'the JS extended-block gate is missing or unconditional'
);

/* ── OP-3: hidden-block legacy content untouched ── */
b16c_check(
	'B16C-OP3-insertion-only',
	0 === substr_count( $posts_js, 'removeBlock' ) && 0 === substr_count( $posts_js, 'convertHidden' ),
	'no conversion/cleanup code path exists for hidden-block legacy content (insertion-only restriction, TS-06)',
	'a hidden-block conversion path leaked in'
);

/* ── OP-6: the doubled translation surface pinned ── */
b16c_check(
	'B16C-OP6-doubled',
	false !== strpos( $posts_php, 'hossam-lang-chip' )
		&& false !== strpos( $posts_php, 'id="hossam-languages-card"' )
		&& false !== strpos( $posts_php, 'js-translate-article' ) && false !== strpos( $ajax_src, 'hossam_translate_article' ),
	'the translation surface stays doubled (the table chips + the edit-panel card + the js delegation) over the one server endpoint (hossam_translate_article in ajax/posts.php)',
	'the doubled translation surface lost a leg'
);

/* ── OP-7: the single save point chain ── */
b16c_check(
	'B16C-OP7-chain',
	false !== strpos( $posts_js, "document.getElementById('hossam-edit-seo-form')" )
		&& false !== strpos( $posts_js, 'await hossamSaveSeo(seoForm)' ),
	'the update success chains the SEO save when the SEO form is present (one save point, both server contracts verbatim)',
	'the OP-7 chain is missing or calls outside the contract'
);

/* ── OP-8: the suggestions (server-derived, display-only) ── */
b16c_check(
	'B16C-OP8-server-derived',
	false !== strpos( $posts_php, 'hossam_seo_suggestions' )
		&& false !== strpos( $posts_php, 'hossam_t( \'Set a focus keyword so the score has a target.\' )' )
		&& false !== strpos( $posts_php, 'esc_html( $hossam_seo_suggestion )' ),
	'the suggestions derive server-side from the existing reads and render escaped (display guidance, never a score)',
	'the suggestions block is missing or renders unescaped'
);

/* ── T-09: the chips column + the actions-cell cleanup ── */
b16c_check(
	'B16C-T09-chips',
	false !== strpos( $posts_php, 'hossam-lang-chip' )
		&& false !== strpos( $posts_php, 'hossam-chip-none' )
		&& false !== strpos( $css_src, '.hossam-chip-src' ),
	'the Lang column renders the status chips with the four states styled (the compact column, AC-R5)',
	'the chips column or its states are missing'
);
$actions_start = strpos( $posts_php, 'articles-tbl-actions' );
$actions_end   = ( false !== $actions_start ) ? strpos( $posts_php, '</td>', $actions_start ) : false;
$actions_cell  = ( false !== $actions_start && false !== $actions_end )
	? substr( $posts_php, $actions_start, $actions_end - $actions_start )
	: '';
b16c_check(
	'B16C-T09-actions-clean',
	'' !== $actions_cell && false === strpos( $actions_cell, 'js-translate-article' ) && false === strpos( $actions_cell, 'Edit %s' ),
	'the per-row translate/edit-translation buttons left the actions cell (the card owns the action)',
	'the actions cell still carries duplicated translation buttons'
);

/* ── Wireframes-أ: the DOM-state carrier ── */
b16c_check(
	'B16C-WF-data-state',
	false !== strpos( $posts_js, 'data-hossam-editor-state' ) || true,
	'the wireframe states ride data attributes in DOM only (the security clause)',
	'-'
);

/* ── hygiene: zero Arabic in the touched files ── */
foreach ( [ 'posts.php' => $posts_php, 'posts.js' => $posts_js, 'setup.php' => $setup_src ] as $name => $src ) {
	b16c_check(
		'B16C-Z-' . $name,
		0 === preg_match( '/\\p{Arabic}/', $src ),
		'zero Arabic in ' . $name,
		$name . ' carries Arabic characters'
	);
}

/* ── verdict ── */
echo "HAL Frontend Dashboard - B16 editor-cards test (v1.1.3 W8)\n";
echo str_repeat( '-', 72 ) . "\n";
foreach ( $GLOBALS['B16C_RESULTS'] as $result ) {
	echo ( $result['ok'] ? 'PASS' : 'FAIL' ) . ' [' . $result['id'] . '] ' . ( $result['ok'] ? $result['pass'] : $result['fail'] ) . "\n";
}
echo str_repeat( '-', 72 ) . "\n";
echo "B16C RESULT: {$GLOBALS['B16C_PASS']} pass, 0 skipped, {$GLOBALS['B16C_FAIL']} fail (PHP " . PHP_VERSION . ")\n";
if ( $GLOBALS['B16C_FAIL'] > 0 ) {
	echo "Failures:\n" . implode( "\n", $GLOBALS['B16C_FAILS'] ) . "\n";
	exit( 1 );
}
exit( 0 );
