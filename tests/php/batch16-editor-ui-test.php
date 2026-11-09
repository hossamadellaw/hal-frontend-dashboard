<?php
/**
 * HAL Frontend Dashboard — B16 editor-UI harness (v1.1.3 W6).
 *
 * Local, isolated harness: no live WordPress, no network, no database.
 * Reads the REAL source files and asserts the wave-6 editor build
 * contract mechanically:
 *
 *   STRUCTURE — the write workspace keeps the single form (never split):
 *               exactly one hossam-post-create-form, the side column is a
 *               single stack holding the AI card and the three display
 *               holders (Publishing / Metadata / Tags), and the workspace
 *               carries the aside grid unconditionally.
 *   ARGS      — zero $template_args usage in posts.php (the shell args
 *               list is untouched; holders compute internally).
 *   D2        — the Languages & Translation card exists with its gate
 *               chain, the single-action rule (one if/elseif chain builds
 *               the row action), and translate buttons carrying the same
 *               attributes the table's existing delegation consumes
 *               (js-translate-article + data-post + data-lang).
 *   D3        — the simplified SEO group: colored meter with the same
 *               thresholds as the articles-table score classes, field
 *               order keyword→title→description, guidance counters
 *               (" / 60", " / 160"), robots descriptions beside the raw
 *               values, and the save contract fields/ids/handler verbatim.
 *   CHANNEL   — every hossam_t() literal in posts.php is registered in the
 *               i18n.php strings array. Extraction is token-based
 *               (token_get_all — the canonical PHP token stream, immune to
 *               quoting/comment edge cases), on both sides.
 *   REMAINDER — the declared i18n.php:237 remainder is closed: the stale
 *               line-number anchor and the self-attribution header are
 *               gone, the clean replacement comment is present.
 *
 * Usage:  php tests/php/batch16-editor-ui-test.php
 * Exit:   0 = all assertions held, 1 = at least one failure.
 */

if ( ! defined( 'B16_PROJECT' ) ) {
	$B16_project = dirname( __DIR__, 2 );
	if ( ! is_file( $B16_project . '/runtime/templates/dashboard/posts.php' ) ) {
		fwrite( STDERR, "B16: cannot locate the project root\n" );
		exit( 1 );
	}
	define( 'B16_PROJECT', $B16_project );
}

$GLOBALS['B16_RESULTS'] = [];
$GLOBALS['B16_PASS']    = 0;
$GLOBALS['B16_FAIL']    = 0;
$GLOBALS['B16_FAILS']   = [];

function b16_check( string $id, bool $ok, string $pass, string $fail ): void {
	$GLOBALS['B16_RESULTS'][] = [ 'id' => $id, 'ok' => $ok, 'pass' => $pass, 'fail' => $fail ];
	if ( $ok ) {
		$GLOBALS['B16_PASS']++;
	} else {
		$GLOBALS['B16_FAIL']++;
		$GLOBALS['B16_FAILS'][] = $id . ': ' . $fail;
	}
}

function b16_read( string $rel ): string {
	$raw = (string) file_get_contents( B16_PROJECT . '/' . $rel );
	// CRLF checkout normalization, in memory only (the file itself is
	// untouched) so position and line assertions are checkout-stable.
	return str_replace( "\r\n", "\n", $raw );
}

function b16_unquote( string $token ): string {
	$quote = $token[0];
	$inner = substr( $token, 1, -1 );
	if ( "'" === $quote ) {
		return str_replace( [ "\\'", '\\\\' ], [ "'", '\\' ], $inner );
	}
	return stripcslashes( $inner );
}

$posts_src  = b16_read( 'runtime/templates/dashboard/posts.php' );
$i18n_src   = b16_read( 'runtime/core/i18n.php' );
$css_src    = b16_read( 'runtime/assets/css/dashboard.css' );

/* ── STRUCTURE: the single model is never split ── */
b16_check(
	'B16-S1-single-form',
	1 === substr_count( $posts_src, 'id="hossam-post-create-form"' ),
	'the write workspace holds exactly one create form (the single model is never split)',
	'expected exactly 1 hossam-post-create-form, found ' . substr_count( $posts_src, 'id="hossam-post-create-form"' )
);
b16_check(
	'B16-S2-side-stack',
	2 === substr_count( $posts_src, 'hossam-write-side-stack' )
		&& false !== strpos( $posts_src, '<div class="hossam-write-side-stack">' )
		&& false !== strpos( $posts_src, '</div><!-- /hossam-write-side-stack -->' ),
	'the side column is one stack wrapping the AI card and the three display holders',
	'the side stack open/close pair is missing or duplicated (count=' . substr_count( $posts_src, 'hossam-write-side-stack' ) . ')'
);
$stack_open  = strpos( $posts_src, '<div class="hossam-write-side-stack">' );
$stack_close = strpos( $posts_src, '</div><!-- /hossam-write-side-stack -->' );
$form_pos    = strpos( $posts_src, 'id="hossam-post-create-form"' );
$ai_card_pos = strpos( $posts_src, 'hossam-write-ai-card' );
b16_check(
	'B16-S3-stack-order',
	false !== $stack_open && false !== $stack_close && false !== $form_pos && false !== $ai_card_pos
		&& $form_pos < $stack_open && $ai_card_pos > $stack_open && $ai_card_pos < $stack_close,
	'source order: form column first, then the side stack containing the AI card and the holders',
	'the form/side-stack source order is wrong (form=' . ( false !== $form_pos ? 'ok' : 'missing' )
		. ' stack=' . ( false !== $stack_open ? 'ok' : 'missing' ) . ')'
);
foreach ( [ 'hossam-publishing-holder', 'hossam-metadata-holder', 'hossam-tags-holder' ] as $holder_id ) {
	b16_check(
		'B16-S4-' . $holder_id,
		false !== strpos( $posts_src, 'id="' . $holder_id . '"' )
			&& false !== strpos( $posts_src, 'hossam-write-aside-holder' ),
		'the display holder "' . $holder_id . '" is present in the side column',
		'the holder "' . $holder_id . '" is missing'
	);
}
b16_check(
	'B16-S5-aside-grid',
	false !== strpos( $posts_src, 'class="hossam-write-workspace hossam-write-has-aside"' ),
	'the workspace carries the aside grid unconditionally (the side column is permanent)',
	'the workspace still keys the aside grid on the AI gate'
);

/* ── ARGS: zero template_args expansion ── */
b16_check(
	'B16-A1-no-template-args',
	0 === substr_count( $posts_src, '$template_args' ),
	'zero $template_args usage in posts.php (holder data computed internally)',
	'$template_args appears ' . substr_count( $posts_src, '$template_args' ) . ' times in posts.php'
);

/* ── D2: the Languages & Translation card ── */
b16_check(
	'B16-D2-card',
	false !== strpos( $posts_src, 'id="hossam-languages-card"' ),
	'the Languages & Translation card is present in the edit panel',
	'the languages card is missing'
);
$gate_ok = false !== strpos( $posts_src, "is_feature_enabled( 'wpml_translations' )" )
	&& false !== strpos( $posts_src, '$posts_wpml[\'status\']' )
	&& false !== strpos( $posts_src, '$posts_wpml[' . "'capabilities'" . '][' . "'languages'" . ']' )
	&& false !== strpos( $posts_src, 'hossam_wpml_get_active_languages' )
	&& false !== strpos( $posts_src, 'hossam_wpml_get_post_language' )
	&& false !== strpos( $posts_src, 'hossam_wpml_get_translation_id' );
b16_check(
	'B16-D2-gate-chain',
	$gate_ok,
	'the card gate chain covers feature × integration status × languages capability × the three official reads',
	'the card gate chain is incomplete'
);
$action_builder_start = strpos( $posts_src, '$hossam_row_action = \'\';' );
$action_builder_end   = strpos( $posts_src, "'original' => " . '$hossam_is_original,' );
$action_builder       = ( false !== $action_builder_start && false !== $action_builder_end && $action_builder_end > $action_builder_start )
	? substr( $posts_src, $action_builder_start, $action_builder_end - $action_builder_start )
	: '';
b16_check(
	'B16-D2-single-action',
	'' !== $action_builder
		&& 1 === substr_count( $action_builder, 'if ( $hossam_is_original ) {' )
		&& 3 === substr_count( $action_builder, '} elseif (' ),
	'the row action is built by one if/elseif chain — one action per row at most (the design rule)',
	'the action builder is not a single if/elseif chain (if=' . substr_count( $action_builder, 'if ( $hossam_is_original ) {' )
		. ' elseif=' . substr_count( $action_builder, '} elseif (' ) . ')'
);
b16_check(
	'B16-D2-delegation-attrs',
	1 === substr_count( $posts_src, 'btn-gold js-translate-article' )
		&& 1 === substr_count( $posts_src, 'btn-ghost js-translate-article' )
		&& false !== strpos( $posts_src, 'btn-gold js-translate-article" data-post="' )
		&& false !== strpos( $posts_src, 'data-lang="' ),
	'the card translate button (btn-gold) carries the same attributes the table delegation consumes; the table button (btn-ghost) stays untouched',
	'the card translate button attributes do not match the existing delegation contract'
);
$card_region_start = strpos( $posts_src, 'id="hossam-languages-card"' );
$card_region_end   = ( false !== $card_region_start ) ? strpos( $posts_src, '<?php endif; ?>', $card_region_start ) : false;
$card_region       = ( false !== $card_region_start && false !== $card_region_end )
	? substr( $posts_src, $card_region_start, $card_region_end - $card_region_start )
	: '';
b16_check(
	'B16-D2-one-action-per-row-markup',
	'' !== $card_region && 1 === substr_count( $card_region, 'hossam-lang-action' ),
	'each language row renders exactly one action slot',
	'the row markup has more than one action slot'
);

/* ── D3: the simplified SEO group ── */
b16_check(
	'B16-D3-meter',
	false !== strpos( $posts_src, 'hossam-seo-meter' )
		&& false !== strpos( $posts_src, "'hossam-seo-meter-' . " . '$hossam_seo_meter_cls' )
		&& false !== strpos( $posts_src, 'hossam_seo_meter_cls = $hossam_edit_seo_score >= 80' )
		&& false !== strpos( $posts_src, '>= 51' )
		&& false !== strpos( $posts_src, '> 0 ?' ),
	'the colored score meter exists with the table-identical thresholds (>=80/>=51/>0), level classes travelling whole from PHP (the rm_cls pattern)',
	'the meter or its threshold chain is missing'
);
b16_check(
	'B16-D3-score-id-target',
	false !== strpos( $posts_src, '<span class="hossam-seo-meter-value" id="hossam-edit-seo-score">' )
		&& false !== strpos( $posts_src, '<div class="hossam-seo-meter">' ),
	'the hossam-edit-seo-score id sits on the meter VALUE span (the posts.js save path writes textContent into it) — never on the meter wrapper (a wrapper id would let the save wipe the whole meter until reload)',
	'the score id is not on the value span, or the meter wrapper still carries it'
);
$kw_pos    = strpos( $posts_src, 'id="hossam-edit-seo-kw"' );
$title_pos = strpos( $posts_src, 'id="hossam-edit-seo-title"' );
$desc_pos  = strpos( $posts_src, 'id="hossam-edit-seo-desc"' );
b16_check(
	'B16-D3-field-order',
	false !== $kw_pos && false !== $title_pos && false !== $desc_pos && $kw_pos < $title_pos && $title_pos < $desc_pos,
	'the SEO field order follows the adopted design: keyword → title → description',
	'the SEO field order is wrong (kw=' . var_export( $kw_pos, true ) . ' title=' . var_export( $title_pos, true ) . ' desc=' . var_export( $desc_pos, true ) . ')'
);
b16_check(
	'B16-D3-counters',
	false !== strpos( $posts_src, "' / 60'" ) && false !== strpos( $posts_src, "' / 160'" ),
	'the guidance counters carry the documented ranges (60 title / 160 description)',
	'the counter ranges are missing'
);
b16_check(
	'B16-D3-seo-title-label',
	false !== strpos( $posts_src, "hossam_t( 'SEO Title' )" ),
	'the SEO title field is labelled SEO Title per the adopted design',
	'the SEO Title label is missing'
);
$seo_form_start = strpos( $posts_src, 'id="hossam-edit-seo-form"' );
$seo_form_end   = ( false !== $seo_form_start ) ? strpos( $posts_src, '</form>', $seo_form_start ) : false;
$seo_form       = ( false !== $seo_form_start && false !== $seo_form_end )
	? substr( $posts_src, $seo_form_start, $seo_form_end - $seo_form_start )
	: '';
b16_check(
	'B16-D3-no-generic-title-label',
	'' !== $seo_form && false === strpos( $seo_form, "hossam_t( 'Title' )" ),
	'the SEO form carries no bare Title label (the article Title field is untouched elsewhere)',
	'the SEO form still uses the generic Title label'
);
$robots_map_ok = false !== strpos( $posts_src, "'noindex'      => hossam_t( 'Do not show this page in search results' )" )
	&& false !== strpos( $posts_src, "'nofollow'     => hossam_t( 'Do not follow links on this page' )" )
	&& false !== strpos( $posts_src, "'noarchive'    => hossam_t( 'Do not store a cached copy of this page' )" )
	&& false !== strpos( $posts_src, "'noimageindex' => hossam_t( 'Do not show images from this page in image search' )" )
	&& false !== strpos( $posts_src, 'hossam-robots-raw' );
b16_check(
	'B16-D3-robots-labels',
	$robots_map_ok,
	'every robots checkbox shows a plain description with the raw value beside it',
	'the robots description map or raw-value marker is missing'
);
b16_check(
	'B16-D3-save-contract',
	false !== strpos( $seo_form, 'name="post_id"' )
		&& false !== strpos( $seo_form, 'name="title"' )
		&& false !== strpos( $seo_form, 'name="focus_keyword"' )
		&& false !== strpos( $seo_form, 'name="description"' )
		&& false !== strpos( $seo_form, 'name="robots[]"' )
		&& false !== strpos( $seo_form, 'hossamSaveSeo(this)' ),
	'the SEO save contract fields/ids/handler are verbatim (hossamSaveSeo untouched)',
	'the SEO save contract fields changed'
);

/* ── CHANNEL: token-based extraction of both sides ── */
$tokens          = token_get_all( '<?php ' . $posts_src );
$b16_t_literals  = [];
$b16_token_count = count( $tokens );
for ( $i = 0; $i < $b16_token_count; $i++ ) {
	$tok = $tokens[ $i ];
	if ( is_array( $tok ) && T_STRING === $tok[0] && 'hossam_t' === $tok[1] ) {
		// scan forward to the next '(' then take the first string literal
		$j    = $i + 1;
		$open = false;
		while ( $j < $b16_token_count ) {
			$nxt = $tokens[ $j ];
			if ( is_array( $nxt ) && T_WHITESPACE === $nxt[0] ) { $j++; continue; }
			if ( '(' === $nxt ) { $open = true; $j++; break; }
			break;
		}
		if ( ! $open ) { continue; }
		while ( $j < $b16_token_count ) {
			$nxt = $tokens[ $j ];
			if ( is_array( $nxt ) && T_WHITESPACE === $nxt[0] ) { $j++; continue; }
			if ( is_array( $nxt ) && T_CONSTANT_ENCAPSED_STRING === $nxt[0] ) {
				$b16_t_literals[] = b16_unquote( $nxt[1] );
			}
			break;
		}
	}
}
$b16_t_literals = array_values( array_unique( $b16_t_literals ) );

$reg_start = strpos( $i18n_src, '$strings = [' );
$reg_end   = ( false !== $reg_start ) ? strpos( $i18n_src, '$hash = substr(', $reg_start ) : false;
$b16_registered_set = [];
if ( false !== $reg_start && false !== $reg_end ) {
	$reg_tokens = token_get_all( '<?php ' . substr( $i18n_src, $reg_start, $reg_end - $reg_start ) );
	foreach ( $reg_tokens as $rtok ) {
		if ( is_array( $rtok ) && T_CONSTANT_ENCAPSED_STRING === $rtok[0] ) {
			$b16_registered_set[ b16_unquote( $rtok[1] ) ] = true;
		}
	}
}
$b16_missing = [];
foreach ( $b16_t_literals as $b16_literal ) {
	if ( ! isset( $b16_registered_set[ $b16_literal ] ) ) {
		$b16_missing[] = $b16_literal;
	}
}
b16_check(
	'B16-C1-channel-gate',
	[] === $b16_missing && [] !== $b16_t_literals && [] !== $b16_registered_set,
	'every hossam_t() literal in posts.php is registered in the i18n strings array (' . count( $b16_t_literals ) . ' distinct literals vs ' . count( $b16_registered_set ) . ' registered — token-based extraction)',
	'unregistered hossam_t literals: ' . implode( ' | ', array_slice( $b16_missing, 0, 8 ) )
);

/* ── REMAINDER: the declared i18n.php:237 item is closed ── */
b16_check(
	'B16-R1-stale-anchor-gone',
	false === strpos( $i18n_src, 'wordpress-posts.php :120/:213' )
		&& false === strpos( $i18n_src, 'v1.1.3 T1 (' ),
	'the stale line-number anchor and the self-attribution header are gone from i18n.php',
	'the i18n.php comment still carries the stale anchor or self-attribution header'
);
b16_check(
	'B16-R2-clean-comment',
	false !== strpos( $i18n_src, 'the H1 rejection source moves to the unified domain and' ),
	'the clean replacement comment (no line indicator) is present',
	'the clean replacement comment is missing'
);

/* ── CSS extension + zero-Arabic hygiene of the touched files ── */
b16_check(
	'B16-C2-css-extension',
	false !== strpos( $css_src, 'v1.1.3 W6' )
		&& false !== strpos( $css_src, '.hossam-seo-meter-fill' )
		&& false !== strpos( $css_src, '.hossam-lang-row' )
		&& false !== strpos( $css_src, '.hossam-write-side-stack' ),
	'dashboard.css carries the W6 extension (stack, language rows, meter) — no new asset file',
	'the W6 CSS extension is missing'
);
foreach ( [ 'posts' => $posts_src, 'i18n' => $i18n_src, 'css' => $css_src ] as $b16_name => $b16_src ) {
	b16_check(
		'B16-Z-' . $b16_name,
		0 === preg_match( '/\\p{Arabic}/', $b16_src ),
		'zero Arabic in ' . $b16_name . ' (both-method parity: \\p{Arabic})',
		$b16_name . ' carries Arabic characters'
	);
}

/* ── verdict ── */
echo "HAL Frontend Dashboard — B16 editor-UI test (v1.1.3 W6)\n";
echo str_repeat( '─', 72 ) . "\n";
foreach ( $GLOBALS['B16_RESULTS'] as $result ) {
	echo ( $result['ok'] ? 'PASS' : 'FAIL' ) . ' [' . $result['id'] . '] ' . ( $result['ok'] ? $result['pass'] : $result['fail'] ) . "\n";
}
echo str_repeat( '─', 72 ) . "\n";
echo "B16 RESULT: {$GLOBALS['B16_PASS']} pass, 0 skipped, {$GLOBALS['B16_FAIL']} fail (PHP " . PHP_VERSION . ")\n";
if ( $GLOBALS['B16_FAIL'] > 0 ) {
	echo "Failures:\n" . implode( "\n", $GLOBALS['B16_FAILS'] ) . "\n";
	exit( 1 );
}
exit( 0 );
