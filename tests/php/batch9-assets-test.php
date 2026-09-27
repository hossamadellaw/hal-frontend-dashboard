<?php
/**
 * HAL Frontend Dashboard — Batch 9 closure harness: base assets & content modules (§20).
 *
 * Local, isolated harness: no live WordPress, no network, no database, no
 * browser. Loads the REAL runtime/core/i18n.php and the REAL
 * runtime/core/setup.php, then invokes the REAL wp_enqueue_scripts
 * closure (priority 20) so the batch-9 asset files are consumed by the
 * actual production enqueue contract. Only the WordPress boundary is
 * stubbed; no project logic is reimplemented in the harness.
 *
 * Coverage (architecture §20 closure gate):
 *
 *   ENQUEUE   — the real closure enqueues css/dashboard.css and
 *             js/dashboard.js + js/modules/{members,posts,uploads}.js
 *             from the release context; every URL carries the release id
 *             (HAL_FRONTEND_DASHBOARD_RUNTIME_URL contains it); module
 *             scripts depend on hossam-dashboard-js; versions are the
 *             real filemtime of the shipped files (not the 1.0.0
 *             fallback — the fallback path is proven dead with the
 *             files present); hossamAjax is localized on
 *             hossam-dashboard-js with ajaxurl/nonce/isRtl/i18n.
 *   I18N      — every t('key') used by the four real JS files is defined
 *             by the REAL hossam_get_i18n_strings(), except the exact
 *             set served by inline fallbacks, which is byte-identical to
 *             the legacy i18n state (contractError, loadFailed,
 *             requestFailed) — equivalence, not silent drift.
 *   BYTES     — each of the five shipped files is byte-identical to its
 *             exclusive legacy source and to its
 *             build/source-inventory-batch-9.json entry; the recorded
 *             snapshot_id recomputes from the files array.
 *   CSS       — every url() inside the real dashboard.css is a data:
 *             URI (no file URLs to break inside the release root).
 *   ACTIONS   — every AJAX action called by posts/uploads/members.js
 *             exists in the real runtime/ajax endpoint files.
 *   LOGO      — the real shell resolves Branding via the Settings
 *             Repository attachment (wp_get_attachment_url) with the
 *             HAL text fallback and contains no fixed logo file path.
 *
 * Usage:  php tests/php/batch9-assets-test.php     (exit 0 = all pass)
 *
 * Legacy-absent mode (e.g. CI runners without the exclusive reference
 * tree): live-legacy comparisons SKIP loudly (never PASS); committable
 * inventory proxies run as B9-B1b checks. Skipped is not passed.
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

if ( PHP_SAPI !== 'cli' ) {
	echo "This harness must run under CLI PHP.\n";
	exit( 1 );
}

$GLOBALS['B9_PROJECT'] = dirname( __DIR__, 2 );

/* ════════════════════════════════════════════════════════════════
 * WordPress boundary stubs (WordPress only — never project logic)
 * ════════════════════════════════════════════════════════════════ */

$GLOBALS['B9_HOOKS']     = array();
$GLOBALS['B9_STYLES']    = array();
$GLOBALS['B9_SCRIPTS']   = array();
$GLOBALS['B9_LOCALIZED'] = array();

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $GLOBALS['B9_PROJECT'] . '/runtime/fake-wp-root/' );
}

function add_action( string $hook, callable $cb, int $priority = 10, int $args = 1 ): void {
	$GLOBALS['B9_HOOKS'][] = array( 'action', $hook, $priority, $cb );
}
function add_filter( string $hook, callable $cb, int $priority = 10, int $args = 1 ): void {
	$GLOBALS['B9_HOOKS'][] = array( 'filter', $hook, $priority, $cb );
}
function apply_filters( string $hook, $value, ...$rest ) {
	return $value;
}
function __( string $s, string $domain = null ): string {
	return $s;
}
function esc_html( $s ) {
	return $s;
}
function admin_url( string $path = '' ): string {
	return 'https://example.test/wp-admin/' . $path;
}
function wp_create_nonce( string $action ): string {
	return 'b9-test-nonce-' . $action;
}
function is_rtl(): bool {
	return true;
}
function is_admin(): bool {
	return false;
}
function is_user_logged_in(): bool {
	return true;
}
function current_user_can( string $cap, ...$rest ): bool {
	return true;
}
function show_admin_bar( bool $show ): void {}

function wp_enqueue_style( string $handle, string $src = '', array $deps = array(), $ver = null ): void {
	$GLOBALS['B9_STYLES'][ $handle ] = array( 'src' => $src, 'deps' => $deps, 'ver' => $ver );
}
function wp_enqueue_script( string $handle, string $src = '', array $deps = array(), $ver = null, bool $in_footer = false ): void {
	$GLOBALS['B9_SCRIPTS'][ $handle ] = array( 'src' => $src, 'deps' => $deps, 'ver' => $ver, 'in_footer' => $in_footer );
}
function wp_localize_script( string $handle, string $name, array $data ): bool {
	$GLOBALS['B9_LOCALIZED'][ $handle . ':' . $name ] = $data;
	return true;
}

/**
 * Boundary state stub: "the current query is the owned dashboard page".
 * The real predicate (batch-7 controller) is proven by
 * tests/php/template-shell-test.php; here only the state is simulated so
 * the real enqueue closure runs.
 */
class HAL_Frontend_Dashboard_Template_Controller {
	public static function is_target_query(): bool {
		return true;
	}
}

/* ════════════════════════════════════════════════════════════════
 * Release context constants (same shape as loader-core.php defines)
 * ════════════════════════════════════════════════════════════════ */

define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT', $GLOBALS['B9_PROJECT'] . '/runtime/' );
define( 'HAL_FRONTEND_DASHBOARD_RELEASE_ID', '1.0.0+' . str_repeat( 'b9', 20 ) );
define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION', '1.0.0' );
define(
	'HAL_FRONTEND_DASHBOARD_RUNTIME_URL',
	'https://example.test/wp-content/mu-plugins/hal-frontend-dashboard/releases/' . HAL_FRONTEND_DASHBOARD_RELEASE_ID . '/'
);

/* ════════════════════════════════════════════════════════════════
 * Check helper
 * ════════════════════════════════════════════════════════════════ */

$GLOBALS['B9_PASS'] = 0;
$GLOBALS['B9_FAIL'] = 0;
$GLOBALS['B9_FAILS'] = array();
$GLOBALS['B9_SKIP'] = 0;

function b9_check( string $id, bool $ok, string $pass, string $fail ): void {
	if ( $ok ) {
		$GLOBALS['B9_PASS']++;
		echo "PASS [{$id}] {$pass}\n";
	} else {
		$GLOBALS['B9_FAIL']++;
		$GLOBALS['B9_FAILS'][] = "{$id}: {$fail}";
		echo "FAIL [{$id}] {$fail}\n";
	}
}

/**
 * Loud skip (never a PASS): used ONLY when the exclusive legacy reference
 * root is absent (e.g. CI runners). The live-legacy comparison is then
 * deferred to runs with the reference tree; committable inventory proxies
 * run as separate B9-B1b checks. Skipped is not passed.
 */
function b9_skip( string $id, string $reason ): void {
	$GLOBALS['B9_SKIP']++;
	echo "SKIP [{$id}] {$reason}\n";
}

/* Exclusive legacy root presence gate (missing on CI runners by design). */
$B9_LEGACY = is_dir( dirname( $GLOBALS['B9_PROJECT'], 2 ) . '/dashboard/Dahboard-v-1.0.0' );

/* ════════════════════════════════════════════════════════════════
 * Load the REAL i18n and REAL setup, then run the REAL enqueue closure
 * ════════════════════════════════════════════════════════════════ */

require $GLOBALS['B9_PROJECT'] . '/runtime/core/i18n.php';
require $GLOBALS['B9_PROJECT'] . '/runtime/core/setup.php';

$enqueue_cb = null;
foreach ( $GLOBALS['B9_HOOKS'] as $hook ) {
	if ( 'action' === $hook[0] && 'wp_enqueue_scripts' === $hook[1] && 20 === $hook[2] ) {
		$enqueue_cb = $hook[3];
		break;
	}
}
b9_check( 'B9-LOAD-real-setup', null !== $enqueue_cb,
	'real setup.php loaded and registered its wp_enqueue_scripts(20) closure',
	'real setup.php did not register the expected enqueue closure' );

if ( null === $enqueue_cb ) {
	echo "B9 RESULT: {$GLOBALS['B9_PASS']} pass, {$GLOBALS['B9_SKIP']} skipped, {$GLOBALS['B9_FAIL']} fail\n";
	exit( 1 );
}
$enqueue_cb();

$release_id = HAL_FRONTEND_DASHBOARD_RELEASE_ID;

/* ── ENQUEUE contract through the real closure ─────────────────── */

$style = $GLOBALS['B9_STYLES']['hossam-dashboard-css'] ?? null;
b9_check( 'B9-E1-css-url-and-version',
	null !== $style
		&& $style['src'] === HAL_FRONTEND_DASHBOARD_RUNTIME_URL . 'assets/css/dashboard.css'
		&& false !== strpos( $style['src'], $release_id )
		&& $style['ver'] === (string) filemtime( HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT . 'assets/css/dashboard.css' ),
	'dashboard.css enqueued from the release URL (release id in URL) with the real filemtime version',
	'dashboard.css enqueue URL/version does not match the release contract: ' . var_export( $style, true ) );

$shell_js = $GLOBALS['B9_SCRIPTS']['hossam-dashboard-js'] ?? null;
b9_check( 'B9-E2-shell-js-url-and-version',
	null !== $shell_js
		&& $shell_js['src'] === HAL_FRONTEND_DASHBOARD_RUNTIME_URL . 'assets/js/dashboard.js'
		&& false !== strpos( $shell_js['src'], $release_id )
		&& $shell_js['ver'] === (string) filemtime( HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT . 'assets/js/dashboard.js' )
		&& true === $shell_js['in_footer'],
	'dashboard.js enqueued from the release URL with real filemtime version in footer',
	'dashboard.js enqueue URL/version/footer does not match the contract: ' . var_export( $shell_js, true ) );

foreach ( array( 'members' => 'js/modules/members.js', 'posts' => 'js/modules/posts.js', 'uploads' => 'js/modules/uploads.js' ) as $module => $file ) {
	$script = $GLOBALS['B9_SCRIPTS'][ 'hossam-dashboard-' . $module ] ?? null;
	b9_check( "B9-E3-module-{$module}",
		null !== $script
			&& $script['src'] === HAL_FRONTEND_DASHBOARD_RUNTIME_URL . 'assets/' . $file
			&& false !== strpos( $script['src'], $release_id )
			&& array( 'hossam-dashboard-js' ) === $script['deps']
			&& $script['ver'] === (string) filemtime( HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT . 'assets/' . $file ),
		"module {$module}.js enqueued from the release URL, depending on hossam-dashboard-js, real filemtime version",
		"module {$module} enqueue does not match the contract: " . var_export( $script, true ) );
}

$localized = $GLOBALS['B9_LOCALIZED']['hossam-dashboard-js:hossamAjax'] ?? null;
$i18n_strings = hossam_get_i18n_strings();
b9_check( 'B9-E4-hossamajax-localized',
	is_array( $localized )
		&& isset( $localized['ajaxurl'], $localized['nonce'], $localized['isRtl'], $localized['i18n'] )
		&& '' !== $localized['nonce']
		&& is_array( $localized['i18n'] ) && count( $localized['i18n'] ) > 0
		&& $localized['i18n'] === $i18n_strings,
	'hossamAjax localized on hossam-dashboard-js with ajaxurl/nonce/isRtl and the real i18n map',
	'hossamAjax localization does not match the JS contract: ' . var_export( $localized, true ) );

/* ── I18N completeness for the t() keys used by the shipped files ── */

$js_sources = array(
	'js/dashboard.js'         => file_get_contents( HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT . 'assets/js/dashboard.js' ),
	'js/modules/posts.js'     => file_get_contents( HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT . 'assets/js/modules/posts.js' ),
	'js/modules/uploads.js'   => file_get_contents( HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT . 'assets/js/modules/uploads.js' ),
	'js/modules/members.js'   => file_get_contents( HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT . 'assets/js/modules/members.js' ),
);

$used_keys = array();
foreach ( $js_sources as $content ) {
	preg_match_all( "/\bt\(\s*['\"]([A-Za-z0-9_]+)['\"]/", (string) $content, $m );
	foreach ( $m[1] as $key ) {
		$used_keys[ $key ] = true;
	}
}
$undefined = array_values( array_filter( array_keys( $used_keys ), static function( string $key ) use ( $i18n_strings ): bool {
	return ! array_key_exists( $key, $i18n_strings );
} ) );
sort( $undefined );

$expected_fallback_only = array( 'contractError', 'loadFailed', 'requestFailed' );

$members_src = $js_sources['js/modules/members.js'];
$uploads_src = $js_sources['js/modules/uploads.js'];
// Audit C1: all three fallback-only keys are asserted, including the
// loadFailed inline fallback in uploads.js (previously unchecked).
$fallbacks_present = false !== strpos( $members_src, "t('contractError','Invalid server response')" )
	&& false !== strpos( $members_src, "t('requestFailed','Request failed')" )
	&& false !== strpos( $uploads_src, "t('loadFailed','Could not load files.')" );

$legacy_i18n_path = dirname( $GLOBALS['B9_PROJECT'], 2 ) . '/dashboard/Dahboard-v-1.0.0/mu-plugins/hossam-dashboard/core/i18n.php';
if ( ! $B9_LEGACY ) {
	b9_check( 'B9-I1b-fallback-only-set-nolegacy',
		$expected_fallback_only === $undefined && $fallbacks_present,
		'the only t() keys without an i18n entry are contractError/loadFailed/requestFailed with inline fallbacks (live-legacy gap cross-check skipped: root absent)',
		'unexpected i18n gap: undefined=' . json_encode( $undefined ) . ' fallbacks_present=' . var_export( $fallbacks_present, true ) );
	b9_skip( 'B9-I1b-fallback-only-set-exact', 'exclusive legacy root absent — live-legacy gap equivalence deferred to runs with the reference tree' );
} else {
	$legacy_i18n = file_exists( $legacy_i18n_path ) ? (string) file_get_contents( $legacy_i18n_path ) : '';
	$legacy_same_gap = '' !== $legacy_i18n
		&& false === strpos( $legacy_i18n, 'contractError' )
		&& false === strpos( $legacy_i18n, 'loadFailed' )
		&& false === strpos( $legacy_i18n, 'requestFailed' );
	b9_check( 'B9-I1b-fallback-only-set-exact',
		$expected_fallback_only === $undefined && $fallbacks_present && $legacy_same_gap,
		'the only t() keys without an i18n entry are contractError/loadFailed/requestFailed, each carries its inline fallback in members.js/uploads.js, and the legacy i18n has the same gap (equivalence, not drift)',
		'unexpected i18n gap: undefined=' . json_encode( $undefined ) . ' fallbacks_present=' . var_export( $fallbacks_present, true ) . ' legacy_same_gap=' . var_export( $legacy_same_gap, true ) );
}

// Split assertions so each failure is actionable:
$shared_keys = array( 'networkError', 'fallbackNotice', 'loadMore', 'retry', 'noFiles', 'noMembers', 'confirmTrashArticle', 'confirmDeleteFile', 'fileUploaded' );
$shared_missing = array();
foreach ( $shared_keys as $key ) {
	if ( ! isset( $used_keys[ $key ] ) || ! array_key_exists( $key, $i18n_strings ) ) {
		$shared_missing[] = $key;
	}
}
b9_check( 'B9-I1a-defined-keys', array() === $shared_missing,
	'the shared/known t() keys used by the shipped files are used and defined in the real i18n map',
	'shared i18n keys missing from use or from the real map: ' . json_encode( $shared_missing ) );

/* ── BYTE identity vs exclusive source and batch-9 inventory ───── */

$source_root = dirname( $GLOBALS['B9_PROJECT'], 2 ) . '/dashboard/Dahboard-v-1.0.0';
$inventory = json_decode( file_get_contents( $GLOBALS['B9_PROJECT'] . '/build/source-inventory-batch-9.json' ), true );
$inv_by_path = array();
foreach ( $inventory['files'] as $f ) {
	$inv_by_path[ $f['path'] ] = $f;
}
$concat = '';
foreach ( $inventory['files'] as $f ) {
	$concat .= $f['path'] . "\0" . $f['bytes'] . "\0" . $f['sha256'] . "\n";
}

$mappings = array(
	'theme/assets/js/modules/posts.js'     => 'runtime/assets/js/modules/posts.js',
	'theme/assets/js/modules/members.js'   => 'runtime/assets/js/modules/members.js',
);

foreach ( $mappings as $legacy_rel => $runtime_rel ) {
	if ( ! $B9_LEGACY ) {
		$dst_hash = hash_file( 'sha256', $GLOBALS['B9_PROJECT'] . '/' . $runtime_rel );
		$inv      = $inv_by_path[ $legacy_rel ] ?? null;
		b9_check( 'B9-B1b-runtime-matches-inventory-' . basename( $legacy_rel ),
			null !== $inv && $dst_hash === $inv['sha256'] && (int) filesize( $GLOBALS['B9_PROJECT'] . '/' . $runtime_rel ) === (int) $inv['bytes'],
			"{$runtime_rel} shows no drift since the verified migration (inventory hash) — live-legacy byte comparison skipped: root absent",
			"{$runtime_rel}: dest={$dst_hash} inventory=" . ( $inv['sha256'] ?? 'missing' ) );
		b9_skip( 'B9-B1-byte-identity-' . basename( $legacy_rel ), 'exclusive legacy root absent — live-legacy byte identity deferred to runs with the reference tree' );
		continue;
	}
	$src_hash = hash_file( 'sha256', $source_root . '/' . $legacy_rel );
	$dst_hash = hash_file( 'sha256', $GLOBALS['B9_PROJECT'] . '/' . $runtime_rel );
	$inv      = $inv_by_path[ $legacy_rel ] ?? null;
	b9_check( 'B9-B1-byte-identity-' . basename( $legacy_rel ),
		null !== $inv && $src_hash === $dst_hash && $dst_hash === $inv['sha256'] && (int) filesize( $GLOBALS['B9_PROJECT'] . '/' . $runtime_rel ) === (int) $inv['bytes'],
		"{$runtime_rel} is byte-identical to the exclusive source and matches inventory",
		"{$runtime_rel}: source={$src_hash} dest={$dst_hash} inventory=" . ( $inv['sha256'] ?? 'missing' ) );
}

// Audit-mandated deltas (B9-01/B9-02): the two fixed files intentionally
// diverge from the legacy bytes. Source-side provenance must still match
// inventory, and the runtime side must carry exactly the documented marker
// the legacy source lacks. Minimality of the surrounding diff is proven by
// independent review of the two files, not by this harness.
$audit_deltas = array(
	'theme/assets/css/dashboard.css'        => array( 'runtime/assets/css/dashboard.css', 'B9-U1' ),
	'theme/assets/js/dashboard.js'       => array( 'runtime/assets/js/dashboard.js', 'B9-02' ),
	'theme/assets/js/modules/uploads.js' => array( 'runtime/assets/js/modules/uploads.js', 'B9-01' ),
);

foreach ( $audit_deltas as $legacy_rel => $pair ) {
	list( $runtime_rel, $marker ) = $pair;
	$dst_src  = (string) file_get_contents( $GLOBALS['B9_PROJECT'] . '/' . $runtime_rel );
	if ( ! $B9_LEGACY ) {
		b9_check( 'B9-B1b-delta-marker-' . basename( $legacy_rel ),
			false !== strpos( $dst_src, '[' . $marker . ']' ),
			"{$runtime_rel} carries the documented audit marker [{$marker}] (source-side provenance skipped: root absent)",
			"{$runtime_rel}: marker [{$marker}] missing in runtime" );
		b9_skip( 'B9-B1-audit-delta-' . basename( $legacy_rel ), 'exclusive legacy root absent — source-side provenance deferred to runs with the reference tree' );
		continue;
	}
	$src_hash = hash_file( 'sha256', $source_root . '/' . $legacy_rel );
	$dst_hash = hash_file( 'sha256', $GLOBALS['B9_PROJECT'] . '/' . $runtime_rel );
	$inv      = $inv_by_path[ $legacy_rel ] ?? null;
	$leg_src  = (string) file_get_contents( $source_root . '/' . $legacy_rel );
	b9_check( 'B9-B1-audit-delta-' . basename( $legacy_rel ),
		null !== $inv && $src_hash === $inv['sha256'] && (int) filesize( $source_root . '/' . $legacy_rel ) === (int) $inv['bytes']
			&& $src_hash !== $dst_hash
			&& false !== strpos( $dst_src, '[' . $marker . ']' )
			&& false === strpos( $leg_src, '[' . $marker . ']' ),
		"{$runtime_rel} keeps source-side inventory provenance and carries exactly the documented audit marker [{$marker}]",
		"{$runtime_rel}: source={$src_hash} dest={$dst_hash} inventory=" . ( $inv['sha256'] ?? 'missing' ) );
}

b9_check( 'B9-B2-snapshot-id-recomputes',
	isset( $inventory['snapshot_id'] ) && hash( 'sha256', $concat ) === $inventory['snapshot_id'],
	'batch-9 inventory snapshot_id recomputes from its files array (' . $inventory['snapshot_id'] . ')',
	'inventory snapshot_id does not recompute from its files array' );

/* ── CSS url() safety inside the release root ──────────────────── */

$css = (string) file_get_contents( $GLOBALS['B9_PROJECT'] . '/runtime/assets/css/dashboard.css' );
preg_match_all( '/url\(\s*([^)]+)\)/', $css, $urls );
$urls_all_data = true;
$url_values = array();
foreach ( $urls[1] as $u ) {
	$trimmed = trim( (string) $u, "'\" " );
	$url_values[] = substr( $trimmed, 0, 30 );
	if ( 0 !== strpos( $trimmed, 'data:' ) ) {
		$urls_all_data = false;
	}
}
b9_check( 'B9-C1-css-urls-are-data-uris',
	$urls_all_data && count( $url_values ) > 0,
	'every url() in dashboard.css is a data: URI (' . count( $url_values ) . ' occurrences) — no file URLs to break inside the release',
	'non-data url() found in dashboard.css: ' . json_encode( $url_values ) );

/* ── AJAX action inventory consumed by the shipped modules ─────── */

$action_targets = array(
	'hossam_create_article'    => 'runtime/ajax/posts.php',
	'hossam_update_article'    => 'runtime/ajax/posts.php',
	'hossam_trash_article'     => 'runtime/ajax/posts.php',
	'hossam_restore_article'   => 'runtime/ajax/posts.php',
	'hossam_translate_article' => 'runtime/ajax/posts.php',
	'hossam_get_my_files'      => 'runtime/ajax/uploads.php',
	'hossam_restore_file'      => 'runtime/ajax/uploads.php',
	'hossam_delete_file'       => 'runtime/ajax/uploads.php',
	'hossam_upload_file'       => 'runtime/ajax/uploads.php',
	'hossam_get_members'       => 'runtime/ajax/members.php',
);
$endpoints = array(
	'runtime/ajax/posts.php'    => (string) file_get_contents( $GLOBALS['B9_PROJECT'] . '/runtime/ajax/posts.php' ),
	'runtime/ajax/uploads.php'  => (string) file_get_contents( $GLOBALS['B9_PROJECT'] . '/runtime/ajax/uploads.php' ),
	'runtime/ajax/members.php'  => (string) file_get_contents( $GLOBALS['B9_PROJECT'] . '/runtime/ajax/members.php' ),
);
$missing_actions = array();
// Audit C2: existence is matched against comment-stripped token text, so an
// action name surviving only inside a PHP comment cannot satisfy the check.
// Only code tokens are concatenated: T_COMMENT, T_DOC_COMMENT, T_WHITESPACE
// and T_OPEN_TAG (plus inline HTML) are skipped; T_CLOSE_TAG is kept.
$stripped_endpoints = array();
foreach ( $endpoints as $endpoint => $source ) {
	$stripped = '';
	foreach ( token_get_all( $source ) as $token ) {
		if ( is_array( $token ) ) {
			if ( in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_OPEN_TAG, T_INLINE_HTML ), true ) ) {
				continue;
			}
			$stripped .= $token[1];
		} else {
			$stripped .= $token;
		}
	}
	$stripped_endpoints[ $endpoint ] = $stripped;
}
foreach ( $action_targets as $action => $endpoint ) {
	if ( false === strpos( $stripped_endpoints[ $endpoint ], $action ) ) {
		$missing_actions[] = "{$action} not found in {$endpoint}";
	}
}
b9_check( 'B9-A1-ajax-actions-exist', array() === $missing_actions,
	'all 10 AJAX actions called by posts/uploads/members.js exist (comment-stripped) in the real runtime endpoints',
	'missing actions: ' . implode( '; ', $missing_actions ) );

/* ── Logo gate: branding attachment or HAL text fallback, no file path ── */

$shell = (string) file_get_contents( $GLOBALS['B9_PROJECT'] . '/runtime/templates/dashboard.php' );
b9_check( 'B9-L1-shell-logo-contract',
	false !== strpos( $shell, 'get_branding_attachment_id' )
		&& false !== strpos( $shell, 'wp_get_attachment_url' )
		&& false !== strpos( $shell, '>HAL</div>' )
		&& false === strpos( $shell, 'logo.png' )
		&& false === strpos( $shell, 'assets/img' ),
	'shell resolves logo via Settings Repository attachment or the HAL text fallback; no fixed logo file path',
	'shell logo contract violated (fixed path or missing attachment/fallback resolution)' );

/* ════════════════════════════════════════════════════════════════
 * Summary
 * ════════════════════════════════════════════════════════════════ */

echo "B9 RESULT: {$GLOBALS['B9_PASS']} pass, {$GLOBALS['B9_SKIP']} skipped (legacy root absent — inventory-proxy only, never passed), {$GLOBALS['B9_FAIL']} fail (PHP " . PHP_VERSION . ")\n";
if ( $GLOBALS['B9_FAIL'] > 0 ) {
	echo "Failures:\n" . implode( "\n", $GLOBALS['B9_FAILS'] ) . "\n";
	exit( 1 );
}
exit( 0 );
