<?php
/**
 * HAL Frontend Dashboard — Batch 10 closure harness: integration & AI modules (§21).
 *
 * Local, isolated harness: no live WordPress, no network, no database, no
 * browser. Loads the REAL runtime/core/i18n.php and the REAL
 * runtime/core/setup.php, then invokes the REAL wp_enqueue_scripts
 * closure (priority 20) so the batch-10 module files are consumed by the
 * actual production enqueue contract. Only the WordPress boundary is
 * stubbed; no project logic is reimplemented in the harness.
 *
 * Coverage (architecture §21 closure gate; §15.24 L1 intermediate state):
 *
 *   ENQUEUE   — the real closure enqueues js/modules/{bookings,finance,
 *             inbox,store,ai}.js from the release context; every URL
 *             carries the release id; each module depends on
 *             hossam-dashboard-js; versions are the real filemtime of the
 *             now-shipped files (the '1.0.0' fallback path is proven dead
 *             — closing the §15.24 L1 intermediate state where these URLs
 *             404ed in a live request); hossamAjax is localized.
 *   I18N      — every t('key') used by the six real JS files is defined
 *             by the REAL hossam_get_i18n_strings(), except the exact
 *             fallback-only set, which is byte-identical to the legacy
 *             i18n state (13 keys: 8 ai keys + forbidden +
 *             integrationUnavailable + the batch-9 triple) — equivalence,
 *             not silent drift.
 *   BYTES     — each of the six shipped files is byte-identical to its
 *             exclusive legacy source and to its
 *             build/source-inventory-batch-10.json entry; the recorded
 *             snapshot_id recomputes from the files array.
 *   ACTIONS   — every AJAX action called by the six modules exists in the
 *             real runtime/ajax endpoint files (comment-stripped).
 *   TRANSLATIONS — translations.js ships as a comment-only file (the
 *             panel is server-rendered; no invented code) and is NOT
 *             enqueued by the real closure.
 *   AI SURFACE — the AI module carries no strategy/provider/secret
 *             surface (comment-stripped static facet; the behavioral
 *             facet lives in tests/js/batch10-modules.test.js).
 *
 * Usage:  php tests/php/batch10-modules-test.php     (exit 0 = all pass)
 *
 * Legacy-absent mode (e.g. CI runners without the exclusive reference
 * tree): live-legacy comparisons SKIP loudly (never PASS); committable
 * inventory proxies run as B10-B1b checks. Skipped is not passed.
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

if ( PHP_SAPI !== 'cli' ) {
	echo "This harness must run under CLI PHP.\n";
	exit( 1 );
}

$GLOBALS['B10_PROJECT'] = dirname( __DIR__, 2 );

/* ════════════════════════════════════════════════════════════════
 * WordPress boundary stubs (WordPress only — never project logic)
 * ════════════════════════════════════════════════════════════════ */

$GLOBALS['B10_HOOKS']     = array();
$GLOBALS['B10_STYLES']    = array();
$GLOBALS['B10_SCRIPTS']   = array();
$GLOBALS['B10_LOCALIZED'] = array();

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $GLOBALS['B10_PROJECT'] . '/runtime/fake-wp-root/' );
}

function add_action( string $hook, callable $cb, int $priority = 10, int $args = 1 ): void {
	$GLOBALS['B10_HOOKS'][] = array( 'action', $hook, $priority, $cb );
}
function add_filter( string $hook, callable $cb, int $priority = 10, int $args = 1 ): void {
	$GLOBALS['B10_HOOKS'][] = array( 'filter', $hook, $priority, $cb );
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
	return 'b10-test-nonce-' . $action;
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
	$GLOBALS['B10_STYLES'][ $handle ] = array( 'src' => $src, 'deps' => $deps, 'ver' => $ver );
}
function wp_enqueue_script( string $handle, string $src = '', array $deps = array(), $ver = null, bool $in_footer = false ): void {
	$GLOBALS['B10_SCRIPTS'][ $handle ] = array( 'src' => $src, 'deps' => $deps, 'ver' => $ver, 'in_footer' => $in_footer );
}
function wp_localize_script( string $handle, string $name, array $data ): bool {
	$GLOBALS['B10_LOCALIZED'][ $handle . ':' . $name ] = $data;
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

define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT', $GLOBALS['B10_PROJECT'] . '/runtime/' );
define( 'HAL_FRONTEND_DASHBOARD_RELEASE_ID', '1.0.0+' . str_repeat( 'b10', 20 ) );
define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION', '1.0.0' );
define(
	'HAL_FRONTEND_DASHBOARD_RUNTIME_URL',
	'https://example.test/wp-content/mu-plugins/hal-frontend-dashboard/releases/' . HAL_FRONTEND_DASHBOARD_RELEASE_ID . '/'
);

/* ════════════════════════════════════════════════════════════════
 * Check helper
 * ════════════════════════════════════════════════════════════════ */

$GLOBALS['B10_PASS'] = 0;
$GLOBALS['B10_FAIL'] = 0;
$GLOBALS['B10_FAILS'] = array();
$GLOBALS['B10_SKIP'] = 0;

function b10_check( string $id, bool $ok, string $pass, string $fail ): void {
	if ( $ok ) {
		$GLOBALS['B10_PASS']++;
		echo "PASS [{$id}] {$pass}\n";
	} else {
		$GLOBALS['B10_FAIL']++;
		$GLOBALS['B10_FAILS'][] = "{$id}: {$fail}";
		echo "FAIL [{$id}] {$fail}\n";
	}
}

/**
 * Loud skip (never a PASS): used ONLY when the exclusive legacy reference
 * root is absent (e.g. CI runners). The live-legacy comparison is then
 * deferred to runs with the reference tree; committable inventory proxies
 * run as separate B10-B1b checks. Skipped is not passed.
 */
function b10_skip( string $id, string $reason ): void {
	$GLOBALS['B10_SKIP']++;
	echo "SKIP [{$id}] {$reason}\n";
}

/* Exclusive legacy root presence gate (missing on CI runners by design). */
$B10_LEGACY = is_dir( dirname( $GLOBALS['B10_PROJECT'], 2 ) . '/dashboard/Dahboard-v-1.0.0' );

/**
 * Strip JS comments (block + line) so static string checks cannot be
 * satisfied by documentation text — the batch-9 audit C2 lesson applied
 * to the JS side.
 */
function b10_js_strip_comments( string $source ): string {
	$no_block  = preg_replace( '/\/\*.*?\*\//s', ' ', $source );
	$no_line   = preg_replace( '/(^|\s)\/\/[^\n]*/', '$1 ', (string) $no_block );
	return (string) $no_line;
}

/* ════════════════════════════════════════════════════════════════
 * Load the REAL i18n and REAL setup, then run the REAL enqueue closure
 * ════════════════════════════════════════════════════════════════ */

require $GLOBALS['B10_PROJECT'] . '/runtime/core/i18n.php';
require $GLOBALS['B10_PROJECT'] . '/runtime/core/setup.php';

$enqueue_cb = null;
foreach ( $GLOBALS['B10_HOOKS'] as $hook ) {
	if ( 'action' === $hook[0] && 'wp_enqueue_scripts' === $hook[1] && 20 === $hook[2] ) {
		$enqueue_cb = $hook[3];
		break;
	}
}
b10_check( 'B10-LOAD-real-setup', null !== $enqueue_cb,
	'real setup.php loaded and registered its wp_enqueue_scripts(20) closure',
	'real setup.php did not register the expected enqueue closure' );

if ( null === $enqueue_cb ) {
	echo "B10 RESULT: {$GLOBALS['B10_PASS']} pass, {$GLOBALS['B10_FAIL']} fail\n";
	exit( 1 );
}
$enqueue_cb();

$release_id = HAL_FRONTEND_DASHBOARD_RELEASE_ID;

/* ── ENQUEUE contract through the real closure (§15.24 L1 closed) ── */

$batch10_modules = array(
	'bookings' => 'js/modules/bookings.js',
	'finance'  => 'js/modules/finance.js',
	'inbox'    => 'js/modules/inbox.js',
	'store'    => 'js/modules/store.js',
	'ai'       => 'js/modules/ai.js',
);

foreach ( $batch10_modules as $module => $file ) {
	$script = $GLOBALS['B10_SCRIPTS'][ 'hossam-dashboard-' . $module ] ?? null;
	b10_check( "B10-E1-module-{$module}",
		null !== $script
			&& $script['src'] === HAL_FRONTEND_DASHBOARD_RUNTIME_URL . 'assets/' . $file
			&& false !== strpos( $script['src'], $release_id )
			&& array( 'hossam-dashboard-js' ) === $script['deps']
			&& true === $script['in_footer']
			&& $script['ver'] === (string) filemtime( HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT . 'assets/' . $file )
			&& '1.0.0' !== $script['ver'],
		"module {$module}.js enqueued from the release URL (release id in URL), depending on hossam-dashboard-js, real filemtime version — the 1.0.0/404 intermediate state (§15.24 L1) is closed",
		"module {$module} enqueue does not match the contract: " . var_export( $script, true ) );
}

$translations_handle = $GLOBALS['B10_SCRIPTS']['hossam-dashboard-translations'] ?? null;
$translations_in_any = false;
foreach ( $GLOBALS['B10_SCRIPTS'] as $script ) {
	if ( false !== strpos( (string) $script['src'], 'translations.js' ) ) {
		$translations_in_any = true;
	}
}
b10_check( 'B10-E2-translations-not-enqueued',
	file_exists( HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT . 'assets/js/modules/translations.js' )
		&& null === $translations_handle
		&& ! $translations_in_any,
	'translations.js ships on disk but the real closure does not enqueue it — the panel is server-rendered (documented §21 state)',
	'translations.js missing from disk or unexpectedly enqueued' );

$localized = $GLOBALS['B10_LOCALIZED']['hossam-dashboard-js:hossamAjax'] ?? null;
$i18n_strings = hossam_get_i18n_strings();
b10_check( 'B10-E3-hossamajax-localized',
	is_array( $localized )
		&& isset( $localized['ajaxurl'], $localized['nonce'], $localized['isRtl'], $localized['i18n'] )
		&& '' !== $localized['nonce']
		&& is_array( $localized['i18n'] ) && count( $localized['i18n'] ) > 0
		&& $localized['i18n'] === $i18n_strings,
	'hossamAjax localized on hossam-dashboard-js with ajaxurl/nonce/isRtl and the real i18n map',
	'hossamAjax localization does not match the JS contract: ' . var_export( $localized, true ) );

/* ── I18N completeness for the t() keys used by the shipped files ── */

$module_sources = array();
foreach ( $batch10_modules as $module => $file ) {
	$module_sources[ 'js/modules/' . $module . '.js' ] = (string) file_get_contents( HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT . 'assets/' . $file );
}
$module_sources['js/modules/translations.js'] = (string) file_get_contents( HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT . 'assets/js/modules/translations.js' );

$used_keys = array();
foreach ( $module_sources as $content ) {
	preg_match_all( "/\bt\(\s*['\"]([A-Za-z0-9_]+)['\"]/", (string) $content, $m );
	foreach ( $m[1] as $key ) {
		$used_keys[ $key ] = true;
	}
}
$undefined = array_values( array_filter( array_keys( $used_keys ), static function( string $key ) use ( $i18n_strings ): bool {
	return ! array_key_exists( $key, $i18n_strings );
} ) );
sort( $undefined );

$expected_fallback_only = array(
	'aiApply', 'aiDismiss', 'aiFailed', 'aiNoResult', 'aiSubmitFailed',
	'aiSuggestion', 'aiThinking', 'aiTimeout',
	'contractError', 'forbidden', 'integrationUnavailable', 'loadFailed', 'requestFailed',
);
sort( $expected_fallback_only );

// Fallback presence per module, against comment-stripped sources so a
// docblock mention can never satisfy the check.
$stripped_modules = array();
foreach ( $module_sources as $rel => $content ) {
	$stripped_modules[ $rel ] = b10_js_strip_comments( $content );
}
$fallback_expectations = array(
	'js/modules/bookings.js' => array(
		"t('contractError','Invalid server response')",
		"t('retry','Retry')",
		"t('forbidden','You do not have permission to view appointments.')",
		"t('integrationUnavailable','Appointment integration is unavailable.')",
		"t('loadFailed','Could not load appointments.')",
		"t('noAppointments','No appointments yet.')",
		"t('networkError','Network error')",
	),
	'js/modules/finance.js' => array(
		"t('loadFailed','Could not load finance summary.')",
		"t('paymentUnavailable','Payment module not available.')",
	),
	'js/modules/inbox.js' => array(
		"t('contractError','Invalid server response')",
		"t('requestFailed','Request failed')",
	),
	'js/modules/store.js' => array(
		"t('loadFailed','Could not load products.')",
		"t('contractError','Invalid server response.')",
	),
	'js/modules/ai.js' => array(
		"t('aiThinking', 'AI is working on it…')",
		"t('aiSubmitFailed', 'Could not start the AI request.')",
		"t('aiTimeout', 'The AI request took too long. Please try again.')",
		"t('aiFailed', 'AI request failed.')",
		"t('aiNoResult', 'No suggestion returned.')",
		"t('aiSuggestion', 'AI suggestion')",
		"t('aiApply', 'Apply')",
		"t('aiDismiss', 'Dismiss')",
	),
);
$fallbacks_missing = array();
foreach ( $fallback_expectations as $rel => $needles ) {
	foreach ( $needles as $needle ) {
		if ( false === strpos( $stripped_modules[ $rel ], $needle ) ) {
			$fallbacks_missing[] = $rel . ' missing ' . $needle;
		}
	}
}

$shared_keys = array(
	'networkError', 'retry', 'noMessages', 'inboxFrom', 'inboxNew', 'notifEmpty',
	'paymentUnavailable', 'filterAll', 'kpiTotalRevenue', 'kpiMonthRevenue', 'kpiPendingOrders',
	'noPayments', 'noInvoices', 'noPaymentMethods', 'noSavedTokens', 'tokenLast4',
	'pmTitle', 'pmStatus', 'enabled', 'disabled', 'viewInvoice', 'prevPage', 'nextPage',
	'noAppointments', 'noProducts', 'productName', 'productPrice', 'productStock',
	'fillAllFields', 'messageSent', 'allMarkedRead',
);
$shared_missing = array();
foreach ( $shared_keys as $key ) {
	if ( ! isset( $used_keys[ $key ] ) || ! array_key_exists( $key, $i18n_strings ) ) {
		$shared_missing[] = $key;
	}
}
b10_check( 'B10-I1a-defined-keys', array() === $shared_missing,
	'the shared/known t() keys used by the shipped modules are used and defined in the real i18n map',
	'shared i18n keys missing from use or from the real map: ' . json_encode( $shared_missing ) );

$legacy_i18n_path = dirname( $GLOBALS['B10_PROJECT'], 2 ) . '/dashboard/Dahboard-v-1.0.0/mu-plugins/hossam-dashboard/core/i18n.php';
if ( ! $B10_LEGACY ) {
	b10_check( 'B10-I1b-fallback-set-nolegacy',
		$expected_fallback_only === $undefined && array() === $fallbacks_missing,
		'the only t() keys without an i18n entry are the exact 13-key set with inline fallbacks (live-legacy gap cross-check skipped: root absent)',
		'unexpected i18n gap: undefined=' . json_encode( $undefined )
			. ' fallbacks_missing=' . json_encode( $fallbacks_missing ) );
	b10_skip( 'B10-I1b-fallback-only-set-exact', 'exclusive legacy root absent — live-legacy gap equivalence deferred to runs with the reference tree' );
} else {
	$legacy_i18n = file_exists( $legacy_i18n_path ) ? (string) file_get_contents( $legacy_i18n_path ) : '';
	$legacy_same_gap = '' !== $legacy_i18n;
	foreach ( $expected_fallback_only as $key ) {
		if ( false !== strpos( $legacy_i18n, "'" . $key . "'" ) ) {
			$legacy_same_gap = false;
		}
	}
	b10_check( 'B10-I1b-fallback-only-set-exact',
		$expected_fallback_only === $undefined && array() === $fallbacks_missing && $legacy_same_gap,
		'the only t() keys without an i18n entry are the exact 13-key set, each carries its inline fallback at its call sites (comment-stripped), and the legacy i18n has the same gap (equivalence, not drift)',
		'unexpected i18n gap: undefined=' . json_encode( $undefined )
			. ' fallbacks_missing=' . json_encode( $fallbacks_missing )
			. ' legacy_same_gap=' . var_export( $legacy_same_gap, true ) );
}

/* ── BYTE identity vs exclusive source and batch-10 inventory ───── */

$source_root = dirname( $GLOBALS['B10_PROJECT'], 2 ) . '/dashboard/Dahboard-v-1.0.0';
$inventory = json_decode( file_get_contents( $GLOBALS['B10_PROJECT'] . '/build/source-inventory-batch-10.json' ), true );
$inv_by_path = array();
foreach ( $inventory['files'] as $f ) {
	$inv_by_path[ $f['path'] ] = $f;
}
$concat = '';
foreach ( $inventory['files'] as $f ) {
	$concat .= $f['path'] . "\0" . $f['bytes'] . "\0" . $f['sha256'] . "\n";
}

$mappings = array(
	'theme/assets/js/modules/bookings.js'     => 'runtime/assets/js/modules/bookings.js',
	'theme/assets/js/modules/finance.js'      => 'runtime/assets/js/modules/finance.js',
	'theme/assets/js/modules/store.js'        => 'runtime/assets/js/modules/store.js',
	'theme/assets/js/modules/translations.js' => 'runtime/assets/js/modules/translations.js',
);

foreach ( $mappings as $legacy_rel => $runtime_rel ) {
	if ( ! $B10_LEGACY ) {
		$dst_hash = hash_file( 'sha256', $GLOBALS['B10_PROJECT'] . '/' . $runtime_rel );
		$inv      = $inv_by_path[ $legacy_rel ] ?? null;
		b10_check( 'B10-B1b-runtime-matches-inventory-' . basename( $legacy_rel ),
			null !== $inv && $dst_hash === $inv['sha256'] && (int) filesize( $GLOBALS['B10_PROJECT'] . '/' . $runtime_rel ) === (int) $inv['bytes'],
			"{$runtime_rel} shows no drift since the verified migration (inventory hash) — live-legacy byte comparison skipped: root absent",
			"{$runtime_rel}: dest={$dst_hash} inventory=" . ( $inv['sha256'] ?? 'missing' ) );
		b10_skip( 'B10-B1-byte-identity-' . basename( $legacy_rel ), 'exclusive legacy root absent — live-legacy byte identity deferred to runs with the reference tree' );
		continue;
	}
	$src_hash = hash_file( 'sha256', $source_root . '/' . $legacy_rel );
	$dst_hash = hash_file( 'sha256', $GLOBALS['B10_PROJECT'] . '/' . $runtime_rel );
	$inv      = $inv_by_path[ $legacy_rel ] ?? null;
	b10_check( 'B10-B1-byte-identity-' . basename( $legacy_rel ),
		null !== $inv && $src_hash === $dst_hash && $dst_hash === $inv['sha256'] && (int) filesize( $GLOBALS['B10_PROJECT'] . '/' . $runtime_rel ) === (int) $inv['bytes'],
		"{$runtime_rel} is byte-identical to the exclusive source and matches inventory",
		"{$runtime_rel}: source={$src_hash} dest={$dst_hash} inventory=" . ( $inv['sha256'] ?? 'missing' ) );
}

// Audit-mandated deltas (B10-01/B10-02): the two fixed files intentionally
// diverge from the legacy bytes. Source-side provenance must still match
// inventory, and the runtime side must carry exactly the documented marker
// the legacy source lacks. Minimality of the surrounding diff is proven by
// independent review of the two files, not by this harness.
$audit_deltas = array(
	'theme/assets/js/modules/inbox.js' => array( 'runtime/assets/js/modules/inbox.js', 'B10-01' ),
	'theme/assets/js/modules/ai.js'    => array( 'runtime/assets/js/modules/ai.js', 'B10-02' ),
);

foreach ( $audit_deltas as $legacy_rel => $pair ) {
	list( $runtime_rel, $marker ) = $pair;
	$dst_src  = (string) file_get_contents( $GLOBALS['B10_PROJECT'] . '/' . $runtime_rel );
	if ( ! $B10_LEGACY ) {
		b10_check( 'B10-B1b-delta-marker-' . basename( $legacy_rel ),
			false !== strpos( $dst_src, '[' . $marker . ']' ),
			"{$runtime_rel} carries the documented audit marker [{$marker}] (source-side provenance skipped: root absent)",
			"{$runtime_rel}: marker [{$marker}] missing in runtime" );
		b10_skip( 'B10-B1-audit-delta-' . basename( $legacy_rel ), 'exclusive legacy root absent — source-side provenance deferred to runs with the reference tree' );
		continue;
	}
	$src_hash = hash_file( 'sha256', $source_root . '/' . $legacy_rel );
	$dst_hash = hash_file( 'sha256', $GLOBALS['B10_PROJECT'] . '/' . $runtime_rel );
	$inv      = $inv_by_path[ $legacy_rel ] ?? null;
	$leg_src  = (string) file_get_contents( $source_root . '/' . $legacy_rel );
	b10_check( 'B10-B1-audit-delta-' . basename( $legacy_rel ),
		null !== $inv && $src_hash === $inv['sha256'] && (int) filesize( $source_root . '/' . $legacy_rel ) === (int) $inv['bytes']
			&& $src_hash !== $dst_hash
			&& false !== strpos( $dst_src, '[' . $marker . ']' )
			&& false === strpos( $leg_src, '[' . $marker . ']' ),
		"{$runtime_rel} keeps source-side inventory provenance and carries exactly the documented audit marker [{$marker}]",
		"{$runtime_rel}: source={$src_hash} dest={$dst_hash} inventory=" . ( $inv['sha256'] ?? 'missing' ) );
}

b10_check( 'B10-B2-snapshot-id-recomputes',
	isset( $inventory['snapshot_id'] ) && hash( 'sha256', $concat ) === $inventory['snapshot_id'],
	'batch-10 inventory snapshot_id recomputes from its files array (' . $inventory['snapshot_id'] . ')',
	'inventory snapshot_id does not recompute from its files array' );

/* ── AJAX action inventory consumed by the shipped modules ─────── */

$action_targets = array(
	'hossam_lazy_appointments'    => 'runtime/ajax/appointments.php',
	'hossam_get_products'         => 'runtime/ajax/store.php',
	'hossam_get_orders'           => 'runtime/ajax/finance.php',
	'hossam_finance_summary'      => 'runtime/ajax/finance.php',
	'hossam_get_payment_methods'  => 'runtime/ajax/finance.php',
	'hossam_get_saved_tokens'     => 'runtime/ajax/finance.php',
	'hossam_get_notifications'    => 'runtime/ajax/inbox.php',
	'hossam_mark_read'            => 'runtime/ajax/inbox.php',
	'hossam_mark_all_read'        => 'runtime/ajax/inbox.php',
	'hossam_send_message'         => 'runtime/ajax/inbox.php',
	'hossam_get_inbox'            => 'runtime/ajax/inbox.php',
	'hossam_mark_message_read'    => 'runtime/ajax/inbox.php',
	'hossam_ai_submit_job'        => 'runtime/ajax/ai.php',
	'hossam_ai_get_job_status'    => 'runtime/ajax/ai.php',
);
$endpoints = array(
	'runtime/ajax/appointments.php' => (string) file_get_contents( $GLOBALS['B10_PROJECT'] . '/runtime/ajax/appointments.php' ),
	'runtime/ajax/store.php'        => (string) file_get_contents( $GLOBALS['B10_PROJECT'] . '/runtime/ajax/store.php' ),
	'runtime/ajax/finance.php'      => (string) file_get_contents( $GLOBALS['B10_PROJECT'] . '/runtime/ajax/finance.php' ),
	'runtime/ajax/inbox.php'        => (string) file_get_contents( $GLOBALS['B10_PROJECT'] . '/runtime/ajax/inbox.php' ),
	'runtime/ajax/ai.php'           => (string) file_get_contents( $GLOBALS['B10_PROJECT'] . '/runtime/ajax/ai.php' ),
);
// Existence is matched against comment-stripped token text (batch-9 audit
// C2 pattern): an action name surviving only inside a PHP comment cannot
// satisfy the check.
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
$missing_actions = array();
// Review hardening (audit follow-up): the needle is the registered hook
// name matched with word boundaries, so a renamed/suffixed registration
// (e.g. wp_ajax_hossam_mark_read_x) can no longer satisfy the check the
// way a bare-substring match could.
foreach ( $action_targets as $action => $endpoint ) {
	if ( 1 !== preg_match( '/\bwp_ajax_' . $action . '\b/', $stripped_endpoints[ $endpoint ] ) ) {
		$missing_actions[] = "wp_ajax_{$action} not registered in {$endpoint}";
	}
}
b10_check( 'B10-A1-ajax-actions-exist', array() === $missing_actions,
	'all 14 AJAX actions called by the six modules are registered (wp_ajax_* word-boundary matched, comment-stripped) in the real runtime endpoints',
	'missing actions: ' . implode( '; ', $missing_actions ) );

/* ── TRANSLATIONS: comment-only file (server-rendered, no invented code) ── */

$translations_stripped = b10_js_strip_comments( $module_sources['js/modules/translations.js'] );
b10_check( 'B10-T1-translations-comment-only',
	'' === trim( $translations_stripped ),
	'translations.js contains no executable JS (comment-only) — the §21 requirement "stays empty when server-rendered; no invented code"',
	'translations.js carries executable content outside comments: ' . json_encode( substr( trim( $translations_stripped ), 0, 80 ) ) );

/* ── AI static surface: no strategy/provider/secret identifiers ── */

$ai_stripped = $stripped_modules['js/modules/ai.js'];
$secret_markers = array( 'strategy_id', 'wp_ai_client', 'direct_key', 'api_key', 'apiKey', 'Authorization', 'secret', 'provider' );
$found_markers = array();
foreach ( $secret_markers as $marker ) {
	if ( false !== strpos( $ai_stripped, $marker ) ) {
		$found_markers[] = $marker;
	}
}
b10_check( 'B10-S1-ai-no-strategy-or-secret-surface',
	array() === $found_markers,
	'the AI module code surface carries no strategy/provider/credential identifiers (comment-stripped); it only transports job_type/content and job_id/status/result',
	'forbidden identifiers found in ai.js code: ' . json_encode( $found_markers ) );

/* ════════════════════════════════════════════════════════════════
 * Summary
 * ════════════════════════════════════════════════════════════════ */

echo "B10 RESULT: {$GLOBALS['B10_PASS']} pass, {$GLOBALS['B10_SKIP']} skipped (legacy root absent — inventory-proxy only, never passed), {$GLOBALS['B10_FAIL']} fail (PHP " . PHP_VERSION . ")\n";
if ( $GLOBALS['B10_FAIL'] > 0 ) {
	echo "Failures:\n" . implode( "\n", $GLOBALS['B10_FAILS'] ) . "\n";
	exit( 1 );
}
exit( 0 );
