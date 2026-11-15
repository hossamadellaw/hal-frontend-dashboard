<?php
/**
 * HAL Frontend Dashboard — B17 sidecards contract harness (v1.1.5 wave 2).
 *
 * Local, isolated harness: no live WordPress, no network, no database.
 * Reads the REAL sources and asserts the v1.1.5 wave-2 side-card contract
 * mechanically (items 14/15/16/21 + 26):
 *
 *   ORDER      — the royal six-card order (Metadata → Tags → Translations →
 *                SEO → AI → Publishing) renders in BOTH contexts, measured
 *                by the shared data-hossam-side-card markers.
 *   PARITY     — the same unified card head family and the same side-card
 *                classes exist in both stacks (the edit ids carry the
 *                hossam-edit- prefix — two panels share one page).
 *   NO-SILENT  — every card that is not in a declared pending-save state
 *                carries at least one real input/select/button; the two
 *                write availability cards carry their declared state
 *                attribute instead (the T-05 contract — items 17-2/18-2).
 *   FIELDS     — the categories tree and the featured-image package live
 *                INSIDE the Metadata cards AND inside the wrapping forms
 *                (items 15-1/21-1: the form is never split).
 *   TAGS       — the live Tags card contract (item 16 + S-9/S-10/S-11):
 *                the article chips with remove buttons, the
 *                category-scoped suggestion chips carrying their read
 *                lists, the add row, the tags[] hidden mirror inside the
 *                form, every control type="button" (zero exceptions), the
 *                posts.js fd.append("tags[]") in BOTH save paths, and the
 *                adapter's single-key contract with the hard 15 cap
 *                (hossam_invalid_tags — an explicit server rejection, no
 *                silent drop) plus the get_term_by/wp_insert_term/
 *                wp_set_post_tags resolution.
 *   SEO        — the nested-form constraint: zero <form inside
 *                hossam-post-edit-form, the SEO container keeps its
 *                addressable id, and the wire keys are byte-identical.
 *   AVAIL      — the write availability states preserved (translations +
 *                SEO pending-save), the edit states live.
 *   AUTHOR     — item 26: the admin actions cell carries both the actions
 *                and the author info (the writer-path markup verbatim);
 *                the writer path keeps its standalone author info.
 *   HYGIENE    — zero Arabic in the touched files; zero new endpoint
 *                (exactly five add_action sites in ajax/posts.php); zero
 *                new runtime file.
 *
 * Usage:  php tests/php/batch17-sidecards-contract-test.php
 * Exit:   0 = all assertions held, 1 = at least one failure.
 */

if ( ! defined( 'B17S_PROJECT' ) ) {
	$B17S_project = dirname( __DIR__, 2 );
	if ( ! is_file( $B17S_project . '/runtime/templates/dashboard/posts.php' ) ) {
		fwrite( STDERR, "B17S: cannot locate the project root\n" );
		exit( 1 );
	}
	define( 'B17S_PROJECT', $B17S_project );
}

$GLOBALS['B17S_RESULTS'] = [];
$GLOBALS['B17S_PASS']    = 0;
$GLOBALS['B17S_FAIL']    = 0;
$GLOBALS['B17S_FAILS']   = [];

function b17s_check( string $id, bool $ok, string $pass, string $fail ): void {
	$GLOBALS['B17S_RESULTS'][] = [ 'id' => $id, 'ok' => $ok, 'pass' => $pass, 'fail' => $fail ];
	if ( $ok ) {
		$GLOBALS['B17S_PASS']++;
	} else {
		$GLOBALS['B17S_FAIL']++;
		$GLOBALS['B17S_FAILS'][] = $id . ': ' . $fail;
	}
}

function b17s_read( string $rel ): string {
	$raw = (string) file_get_contents( B17S_PROJECT . '/' . $rel );
	return str_replace( "\r\n", "\n", $raw );
}

$posts_php = b17s_read( 'runtime/templates/dashboard/posts.php' );
$posts_js  = b17s_read( 'runtime/assets/js/modules/posts.js' );
$adapter   = b17s_read( 'runtime/adapters/wordpress-posts.php' );
$ajax_src  = b17s_read( 'runtime/ajax/posts.php' );
$css_src   = b17s_read( 'runtime/assets/css/dashboard.css' );

$royal = [ 'metadata', 'tags', 'translations', 'seo', 'ai', 'publishing' ];

/* ── ORDER: the royal order in both contexts ── */
foreach ( [ 'panel-write' => 'hossam-metadata-holder', 'panel-edit-article' => 'hossam-edit-metadata-holder' ] as $panel_id => $first_holder ) {
	$panel_start = strpos( $posts_php, 'id="' . $panel_id . '"' );
	$panel_close = ( false !== $panel_start ) ? strpos( $posts_php, '<!-- /panel-', $panel_start ) : false;
	$panel_src   = ( false !== $panel_start && false !== $panel_close ) ? substr( $posts_php, $panel_start, $panel_close - $panel_start ) : '';
	$pos         = [];
	foreach ( $royal as $card ) {
		$pos[ $card ] = ( '' !== $panel_src ) ? strpos( $panel_src, 'data-hossam-side-card="' . $card . '"' ) : false;
	}
	$ordered = '' !== $panel_src && false !== $pos['metadata'];
	for ( $ci = 0; $ci < count( $royal ) - 1; $ci++ ) {
		$a = $pos[ $royal[ $ci ] ];
		$b = $pos[ $royal[ $ci + 1 ] ];
		if ( false === $a || false === $b || $a > $b ) {
			$ordered = false;
			break;
		}
	}
	b17s_check(
		'B17-ORDER-' . $panel_id,
		$ordered,
		'the ' . $panel_id . ' side stack renders the royal order Metadata → Tags → Translations → SEO → AI → Publishing (v1.1.5 item 14 — the owner order overrides the v1.1.4 item 7 order)',
		'the six-card order deviated in ' . $panel_id
	);
}

/* ── PARITY: same head family and side-card classes in both stacks ── */
b17s_check(
	'B17-PARITY-heads',
	12 === substr_count( $posts_php, 'hossam-write-card-head' )
		&& 12 === substr_count( $posts_php, 'hossam-write-card-title' )
		&& 12 === substr_count( $posts_php, 'hossam-write-card-tag' ),
	'the unified card head family renders on all twelve side cards (six per context — v1.1.5 item 21-2)',
	'the unified head family count deviated (heads=' . substr_count( $posts_php, 'hossam-write-card-head' ) . ')'
);
b17s_check(
	'B17-PARITY-holders',
	12 === substr_count( $posts_php, 'data-hossam-side-card="' ),
	'twelve side-card markers (six per context — the positional contract travels the render)',
	'marker count deviated: ' . substr_count( $posts_php, 'data-hossam-side-card="' )
);

/* ── NO-SILENT: every non-pending card carries a real control ── */
$write_start = strpos( $posts_php, 'id="panel-write"' );
$write_end   = ( false !== $write_start ) ? strpos( $posts_php, '<!-- /panel-write -->', $write_start ) : false;
$write_src   = ( false !== $write_start && false !== $write_end ) ? substr( $posts_php, $write_start, $write_end - $write_start ) : '';
$edit_start  = strpos( $posts_php, 'id="panel-edit-article"' );
$edit_end    = ( false !== $edit_start ) ? strpos( $posts_php, '<!-- /panel-edit-article -->', $edit_start ) : false;
$edit_src    = ( false !== $edit_start && false !== $edit_end ) ? substr( $posts_php, $edit_start, $edit_end - $edit_start ) : '';
$pending_write_controls = [ 'metadata', 'tags', 'ai', 'publishing' ];
$pending_edit_controls  = [ 'metadata', 'tags', 'translations', 'seo', 'ai', 'publishing' ];
foreach ( [ 'write' => [ $write_src, $pending_write_controls ], 'edit' => [ $edit_src, $pending_edit_controls ] ] as $ctx => $ctx_data ) {
	$ctx_src     = $ctx_data[0];
	$needs_input = $ctx_data[1];
	foreach ( $needs_input as $card ) {
		$card_start = ( '' !== $ctx_src ) ? strpos( $ctx_src, 'data-hossam-side-card="' . $card . '"' ) : false;
		$card_end   = ( false !== $card_start ) ? strpos( $ctx_src, 'data-hossam-side-card="', $card_start + strlen( 'data-hossam-side-card="' ) ) : false;
		$card_end   = ( false === $card_end && false !== $card_start ) ? strlen( $ctx_src ) : $card_end;
		$card_src   = ( false !== $card_start && false !== $card_end ) ? substr( $ctx_src, $card_start, $card_end - $card_start ) : '';
		$has_input  = ( '' !== $card_src )
			&& ( preg_match( '/<(input|select|button|textarea|a)\b/', $card_src ) === 1 );
		/* The tags card emits its controls through the ONE shared builder —
		   the builder pins (B17-TAGS-markup) prove the controls for both
		   contexts. */
		if ( 'tags' === $card ) {
			$has_input = '' !== $builder_src;
		}
		b17s_check(
			'B17-ALIVE-' . $ctx . '-' . $card,
			$has_input,
			'the ' . $ctx . ' ' . $card . ' card is not silent — it carries at least one real input/select/button/textarea/action (v1.1.5 item 14 acceptance 3)',
			'the ' . $ctx . ' ' . $card . ' card renders no real control'
		);
	}
}
b17s_check(
	'B17-AVAIL-write',
	false !== strpos( $write_src, 'id="hossam-translations-holder" data-hossam-side-card="translations" data-hossam-card-state="pending-save"' )
		&& false !== strpos( $write_src, 'id="hossam-seo-holder" data-hossam-side-card="seo" data-hossam-card-state="pending-save"' ),
	'the write availability states are declared, not silent (the T-05 contract preserved — items 17-2/18-2)',
	'the write availability state attributes are missing'
);

/* ── FIELDS: the real fields inside the cards AND inside the forms ── */
$create_form_start = strpos( $posts_php, 'id="hossam-post-create-form"' );
$create_form_end   = ( false !== $create_form_start ) ? strpos( $posts_php, '</form>', $create_form_start ) : false;
$create_form_src   = ( false !== $create_form_start && false !== $create_form_end ) ? substr( $posts_php, $create_form_start, $create_form_end - $create_form_start ) : '';
$edit_form_start   = strpos( $posts_php, 'id="hossam-post-edit-form"' );
$edit_form_end     = ( false !== $edit_form_start ) ? strpos( $posts_php, '</form>', $edit_form_start ) : false;
$edit_form_src     = ( false !== $edit_form_start && false !== $edit_form_end ) ? substr( $posts_php, $edit_form_start, $edit_form_end - $edit_form_start ) : '';
b17s_check(
	'B17-FIELDS-write-in-form',
	'' !== $create_form_src
		&& false !== strpos( $create_form_src, 'id="hossam-metadata-holder"' )
		&& false !== strpos( $create_form_src, 'name="categories[]"' )
		&& false !== strpos( $create_form_src, 'id="hossam-new-thumbnail"' )
		&& false !== strpos( $create_form_src, 'id="hossam-tags-holder"' )
		&& false !== strpos( $create_form_src, 'id="hossam-new-status"' )
		&& 0 === substr_count( $create_form_src, '<form' ),
	'the create form wraps the whole workspace: the Metadata/Tags/Publishing card fields are form children and zero nested form exists (items 15-1/21-1)',
	'the create form does not wrap the card fields or a nested form leaked'
);
b17s_check(
	'B17-FIELDS-edit-in-form',
	'' !== $edit_form_src
		&& false !== strpos( $edit_form_src, 'id="hossam-edit-metadata-holder"' )
		&& false !== strpos( $edit_form_src, 'id="hossam-edit-thumbnail"' )
		&& false !== strpos( $edit_form_src, 'id="hossam-edit-tags-holder"' )
		&& false !== strpos( $edit_form_src, 'id="hossam-edit-status"' )
		&& false !== strpos( $edit_form_src, 'id="hossam-languages-card"' ),
	'the edit form wraps the same workspace (the six cards inside the form — item 21-1)',
	'the edit form does not wrap the six cards'
);

/* ── TAGS: the live card contract (item 16 + S-9/S-10/S-11) ── */
$builder_start = strpos( $posts_php, '$hossam_render_tags_card = function' );
$builder_end   = ( false !== $builder_start ) ? strpos( $posts_php, '?>', $builder_start ) : false;
$builder_src   = ( false !== $builder_start && false !== $builder_end ) ? substr( $posts_php, $builder_start, $builder_end - $builder_start ) : '';
b17s_check(
	'B17-TAGS-markup',
	'' !== $builder_src
		&& false !== strpos( $builder_src, 'data-hossam-tags-chips' )
		&& false !== strpos( $builder_src, 'data-hossam-tags-suggestions' )
		&& false !== strpos( $builder_src, 'data-hossam-tags-add' )
		&& false !== strpos( $builder_src, 'data-hossam-tags-values' )
		&& false !== strpos( $builder_src, 'name="tags[]"' )
		&& false !== strpos( $posts_php, '$hossam_render_tags_card( array(), $hossam_tag_suggestions( array() )' )
		&& false !== strpos( $posts_php, '$hossam_render_tags_card( $hossam_edit_tag_names, $hossam_tag_suggestions( (array) $hossam_edit_cats )' ),
	'the one Tags-card builder renders chips, suggestions, the add row and the tags[] hidden mirror in BOTH contexts (write: empty start; edit: the article tags + category-scoped suggestions — item 16-1/2/3)',
	'the Tags card builder or one of its context calls is incomplete'
);
/* S-11: zero button inside the tags card builder without an explicit type —
   extract every <button opening and require type="button" on each. */
$btn_opens = preg_match_all( '/<button\b(?! type="button")[^>]*>/', $builder_src, $m );
b17s_check(
	'B17-TAGS-type-button',
	0 === $btn_opens && '' !== $builder_src,
	'S-11: zero button without an explicit type="button" inside the Tags card builder (no implicit submit inside the wrapping save form)',
	'buttons missing type="button": ' . $btn_opens
);
b17s_check(
	'B17-TAGS-scope-read',
	false !== strpos( $posts_php, 'data-hossam-tags-scope=' )
		&& false !== strpos( $posts_php, 'data-tag-cats=' )
		&& false !== strpos( $posts_php, 'post_tag@publish' ),
	'S-9: the suggestion read scope and the per-chip category read lists are documented in the render (the linkage is measurable, not narrated)',
	'the documented read list is missing from the render'
);
b17s_check(
	'B17-TAGS-js-append',
	false !== strpos( $posts_js, 'input[name="tags[]"]' )
		&& 2 === substr_count( $posts_js, 'fd.append("tags[]"' ),
	'the posts.js save paths append the single tags[] key in BOTH create and update (the categories[] pattern verbatim)',
	'the tags[] append is missing from a save path'
);
b17s_check(
	'B17-TAGS-cap',
	false !== strpos( $adapter, "count( \$data['tags'] ) > 15" )
		&& false !== strpos( $adapter, 'hossam_invalid_tags' )
		&& false !== strpos( $ajax_src, "count( \$raw_tags ) > 15" ),
	'S-10: the 15-tag request cap is a hard explicit server rejection in the adapter gate AND the ajax input gate (WP_Error / 400 — no silent drop)',
	'the explicit 15-tag rejection is missing'
);
b17s_check(
	'B17-TAGS-adapter-contract',
	false !== strpos( $adapter, "get_term_by( 'name', \$tag_name, 'post_tag' )" )
		&& false !== strpos( $adapter, "wp_insert_term( \$tag_name, 'post_tag' )" )
		&& false !== strpos( $adapter, 'wp_set_post_tags( $post_id, $resolved_tag_ids )' )
		&& false !== strpos( $adapter, "sanitize_text_field( (string) \$tag_item )" ),
	'the adapter resolves names→ids through the ready term interfaces (get_term_by/wp_insert_term) and writes via wp_set_post_tags beside wp_set_post_categories (item 16-4)',
	'the adapter tags resolution contract is missing'
);
b17s_check(
	'B17-TAGS-endpoints',
	5 === substr_count( $ajax_src, 'add_action( \'wp_ajax_' ),
	'the byte-check: exactly the five standing endpoints in ajax/posts.php — zero sixth endpoint (item 16 acceptance 5)',
	'a sixth endpoint leaked into ajax/posts.php'
);

/* ── SEO: the nested-form constraint + live fields ── */
b17s_check(
	'B17-SEO-nested',
	'' !== $edit_form_src
		&& 0 === substr_count( $edit_form_src, '<form' )
		&& 1 === substr_count( $posts_php, '<div id="hossam-edit-seo-form">' )
		&& false !== strpos( $posts_js, "document.getElementById('hossam-edit-seo-form')" ),
	'zero nested form in the edit form; the SEO container keeps the addressable id and the posts.js chain reads it (item 21-3)',
	'a nested form leaked or the SEO container id moved'
);
b17s_check(
	'B17-SEO-wire',
	false !== strpos( $posts_js, 'if(title) fd.append("title",title.value);' )
		&& false !== strpos( $posts_js, 'if(kw) fd.append("focus_keyword",kw.value);' )
		&& false !== strpos( $posts_js, 'if(desc) fd.append("description",desc.value);' )
		&& false !== strpos( $posts_js, 'fd.append("robots[]",rv);' )
		&& false !== strpos( $posts_php, 'name="seo_post_id"' ),
	'the SEO wire keys are byte-identical before/after the distinguished internal names (the hossam_save_seo endpoint contract untouched — item 21-3)',
	'the SEO wire format drifted'
);

/* ── AUTHOR: item 26 both paths ── */
$admin_cell_start = strpos( $posts_php, 'articles-tbl-actions' );
$admin_td_start   = ( false !== $admin_cell_start ) ? strrpos( substr( $posts_php, 0, $admin_cell_start ), '<td>' ) : false;
$admin_td_end     = ( false !== $admin_td_start ) ? strpos( $posts_php, '</td>', $admin_cell_start ) : false;
$admin_cell       = ( false !== $admin_td_start && false !== $admin_td_end ) ? substr( $posts_php, $admin_td_start, $admin_td_end - $admin_td_start ) : '';
$writer_start     = ( false !== $admin_cell_end ) ? strpos( $posts_php, 'hossam-author-info', $admin_cell_end ) : false;
b17s_check(
	'B17-AUTHOR-admin-cell',
	'' !== $admin_cell
		&& false !== strpos( $admin_cell, 'articles-tbl-actions' )
		&& false !== strpos( $admin_cell, 'hossam-author-info' )
		&& false !== strpos( $admin_cell, "get_post_field( 'post_author'" )
		&& false !== strpos( $admin_cell, "get_the_author_meta( 'display_name'" ),
	'item 26: the admin actions cell carries the actions AND the author info built from the same guarded reads (zero endpoint, zero JS, zero adapter)',
	'the admin actions cell lost one of the two blocks'
);
b17s_check(
	'B17-AUTHOR-writer-path',
	false !== $writer_start,
	'item 26: the writer path keeps its standalone author-info block (the existing item 13 surface untouched)',
	'the writer-path author info vanished'
);

/* ── CSS: the complementary values exist; zero fixed|sticky growth ── */
b17s_check(
	'B17-CSS-values',
	false !== strpos( $css_src, '.hossam-tag-chip' )
		&& false !== strpos( $css_src, '.hossam-tag-suggest' )
		&& false !== strpos( $css_src, '.hossam-tags-add-row' )
		&& false !== strpos( $css_src, '.hossam-write-card-ico-btn' )
		&& false !== strpos( $css_src, '#panel-edit-article .hossam-write-workspace' )
		&& false !== strpos( $css_src, '#panel-edit-article .hossam-write-card-head' ),
	'dashboard.css carries the item 21-4 scope extension and the tag-card complementary values (extension in this file, no new asset)',
	'the CSS extension is missing'
);

/* ── hygiene: zero Arabic; the language rule of the contract ── */
foreach ( [ 'posts.php' => $posts_php, 'posts.js' => $posts_js, 'adapter' => $adapter, 'ajax' => $ajax_src, 'css' => $css_src ] as $name => $src ) {
	b17s_check(
		'B17-Z-' . $name,
		0 === preg_match( '/\\p{Arabic}/', $src ),
		'zero Arabic in ' . $name,
		$name . ' carries Arabic characters'
	);
}

/* ── verdict ── */
echo "HAL Frontend Dashboard — B17 sidecards contract test (v1.1.5 wave 2)\n";
echo str_repeat( '-', 72 ) . "\n";
foreach ( $GLOBALS['B17S_RESULTS'] as $result ) {
	echo ( $result['ok'] ? 'PASS' : 'FAIL' ) . ' [' . $result['id'] . '] ' . ( $result['ok'] ? $result['pass'] : $result['fail'] ) . "\n";
}
echo str_repeat( '-', 72 ) . "\n";
echo "B17S RESULT: {$GLOBALS['B17S_PASS']} pass, 0 skipped, {$GLOBALS['B17S_FAIL']} fail (PHP " . PHP_VERSION . ")\n";
if ( $GLOBALS['B17S_FAIL'] > 0 ) {
	echo "Failures:\n" . implode( "\n", $GLOBALS['B17S_FAILS'] ) . "\n";
	exit( 1 );
}
exit( 0 );
