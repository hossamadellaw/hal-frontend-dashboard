<?php
/**
 * HAL Frontend Dashboard — profile links extension point (CR-2026-09-25).
 *
 * Local, isolated harness (CLI PHP only): drives the REAL
 * runtime/templates/dashboard/profile-links.php and the REAL
 * runtime/templates/dashboard/profile.php render with fixture WordPress
 * boundary stubs plus an independent test provider. No project logic is
 * reimplemented; provider, filter plumbing, and rendering are all real.
 *
 * Acceptance (§6.5 of the delegation):
 *   PL1 — no providers: current render preserved, no empty section.
 *   PL2 — eligible renders Dashboard-styled; ineligible / non-bool hidden.
 *   PL3 — user context from get_current_user_id; request input cannot
 *         override it.
 *   PL4 — ordering + first-wins dedupe per contract.
 *   PL5 — malformed data, injected labels, disallowed URLs handled safely
 *         while valid entries still render.
 *   PL6 — UM absent allows eligible links; disabled feature hides the panel
 *         and never invokes providers.
 *   PL7 — advisory hardening 2026-09-27: non-array provider output, invalid
 *         order (skipped, missing still 100), malformed host (spaces),
 *         rejected-then-valid same id.
 *
 * Usage: php tests/php/profile-links-test.php (exit 0 = all pass)
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (PHP_SAPI !== 'cli') { echo "CLI only.\n"; exit(1); }

$PROJECT = dirname(__DIR__, 2);
$GLOBALS['PL_PROJECT'] = $PROJECT;

$GLOBALS['PL_PASS'] = 0;
$GLOBALS['PL_FAIL'] = 0;
$GLOBALS['PL_FAILS'] = array();

function pl_check(string $id, bool $ok, string $pass, string $fail): void {
    if ($ok) {
        $GLOBALS['PL_PASS']++;
        echo "PASS [$id] $pass\n";
    } else {
        $GLOBALS['PL_FAIL']++;
        $GLOBALS['PL_FAILS'][] = "$id: $fail";
        echo "FAIL [$id] $fail\n";
    }
}

if (!defined('ABSPATH')) { define('ABSPATH', $PROJECT . '/runtime/fake-wp-root/'); }

/* ── Fixture WordPress boundary (WP only) ── */

$GLOBALS['PL_FILTERS'] = array();
$GLOBALS['PL_FILTER_CALLS'] = array();
$GLOBALS['PL_USER_ID'] = 0;
$GLOBALS['PL_FEATURE'] = true;
$GLOBALS['PL_UM_STATUS'] = 'available';

function add_filter(string $h, $cb, int $p = 10, int $a = 1): bool {
    $GLOBALS['PL_FILTERS'][$h][] = array($cb, $a);
    return true;
}
function apply_filters(string $h, $value, ...$rest) {
    $GLOBALS['PL_FILTER_CALLS'][$h] = ($GLOBALS['PL_FILTER_CALLS'][$h] ?? 0) + 1;
    foreach ($GLOBALS['PL_FILTERS'][$h] ?? array() as $entry) {
        [$cb, $accepted] = $entry;
        $value = $cb($value, ...array_slice($rest, 0, max(0, $accepted - 1)));
    }
    return $value;
}
function get_current_user_id(): int { return (int) $GLOBALS['PL_USER_ID']; }
function esc_html($s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function esc_url($u): string {
    $u = (string) $u;
    if ('' === $u) { return ''; }
    if (1 !== preg_match('/\Ahttps?:\/\//i', $u)) { return ''; }
    return str_replace(array('"', '<', '>'), array('%22', '%3C', '%3E'), $u);
}
function hossam_t(string $s): string { return $s; }
function get_option(string $k, $d = false) { return $d; }
function shortcode_exists(string $tag): bool { return false; }
function do_shortcode(string $s): string { return ''; }
function wp_logout_url(string $r = ''): string { return 'https://example.test/logout/'; }
function hossam_login_url(): string { return 'https://example.test/login/'; }
function wp_parse_url(string $u, int $c = -1) { return parse_url($u, $c); }

class HAL_Frontend_Dashboard_Settings_Repository {
    public static function is_feature_enabled(string $feature): bool {
        return (bool) $GLOBALS['PL_FEATURE'];
    }
}
function hossam_get_integration_decision(string $integration): array {
    return array('status' => $GLOBALS['PL_UM_STATUS'], 'reason' => '', 'capabilities' => array());
}

require $GLOBALS['PL_PROJECT'] . '/runtime/templates/dashboard/profile-links.php';

function pl_render_panel(): string {
    ob_start();
    include $GLOBALS['PL_PROJECT'] . '/runtime/templates/dashboard/profile.php';
    return (string) ob_get_clean();
}

function pl_reset(): void {
    $GLOBALS['PL_FILTERS'] = array();
    $GLOBALS['PL_FILTER_CALLS'] = array();
}

/* ── PL1: no providers → current render, no empty section ── */

pl_reset();
$GLOBALS['PL_USER_ID'] = 7;
$html = pl_render_panel();
pl_check(
    'PL1-no-providers',
    false !== strpos($html, 'panel-profile')
        && false === strpos($html, 'Additional links')
        && false !== strpos($html, 'My Profile &amp; Account'),
    'without providers the panel renders as before with no empty links section',
    'panel altered with no providers: ' . substr($html, 0, 200)
);

/* ── PL2: eligible renders, ineligible / non-bool hidden ── */

pl_reset();
add_filter(
    'hal_frontend_dashboard_profile_links',
    static function (array $links, int $user_id): array {
        $links[] = array('id' => 'demo.overview', 'label' => 'Overview', 'url' => 'https://example.test/m/1', 'eligible' => true, 'order' => 10);
        $links[] = array('id' => 'demo.hidden', 'label' => 'Hidden', 'url' => 'https://example.test/m/2', 'eligible' => false, 'order' => 5);
        $links[] = array('id' => 'demo.loose', 'label' => 'Loose', 'url' => 'https://example.test/m/3', 'eligible' => 1, 'order' => 1);
        return $links;
    },
    10,
    2
);
$html = pl_render_panel();
pl_check(
    'PL2-eligible-only',
    false !== strpos($html, '>Overview</a>')
        && false !== strpos($html, 'btn btn-ghost btn-sm')
        && false === strpos($html, '>Hidden</a>')
        && false === strpos($html, '>Loose</a>'),
    'the eligible link renders Dashboard-styled; ineligible and non-bool eligible stay hidden',
    'eligibility filtering broken'
);

/* ── PL3: user context from WordPress, not request input ── */

pl_reset();
$_GET['user_id'] = 999;
$_POST['user_id'] = 999;
$GLOBALS['PL_USER_ID'] = 42;
$seen_user = null;
add_filter(
    'hal_frontend_dashboard_profile_links',
    static function (array $links, int $user_id) use (&$seen_user): array {
        $seen_user = $user_id;
        $links[] = array('id' => 'demo.mine', 'label' => 'Mine', 'url' => 'https://example.test/u/' . $user_id, 'eligible' => true);
        return $links;
    },
    10,
    2
);
$html = pl_render_panel();
unset($_GET['user_id'], $_POST['user_id']);
pl_check(
    'PL3-user-context',
    42 === $seen_user && false !== strpos($html, 'https://example.test/u/42'),
    'the provider receives user 42 from get_current_user_id despite request input claiming 999',
    'seen user: ' . var_export($seen_user, true)
);

/* ── PL4: ordering + first-wins dedupe ── */

pl_reset();
add_filter(
    'hal_frontend_dashboard_profile_links',
    static function (array $links): array {
        $links[] = array('id' => 'demo.second', 'label' => 'Second', 'url' => 'https://example.test/s', 'eligible' => true, 'order' => 20);
        $links[] = array('id' => 'demo.first', 'label' => 'First', 'url' => 'https://example.test/f', 'eligible' => true, 'order' => 5);
        $links[] = array('id' => 'demo.tie-a', 'label' => 'TieA', 'url' => 'https://example.test/a', 'eligible' => true);
        $links[] = array('id' => 'demo.tie-b', 'label' => 'TieB', 'url' => 'https://example.test/b', 'eligible' => true);
        $links[] = array('id' => 'demo.first', 'label' => 'FIRST-DUP', 'url' => 'https://example.test/dup', 'eligible' => true, 'order' => 1);
        return $links;
    },
    10,
    1
);
$html = pl_render_panel();
$pos = array();
foreach (array('First', 'Second', 'TieA', 'TieB') as $label) {
    $pos[$label] = strpos($html, '>' . $label . '</a>');
}
pl_check(
    'PL4-order-dedupe',
    false !== $pos['First'] && $pos['First'] < $pos['Second']
        && $pos['Second'] < $pos['TieA'] && $pos['TieA'] < $pos['TieB']
        && false === strpos($html, 'FIRST-DUP'),
    'order ascending with input-order ties, and the first demo.first wins over its duplicate',
    'positions: ' . json_encode($pos)
);

/* ── PL5: malformed / injected / disallowed handled, valid survive ── */

pl_reset();
add_filter(
    'hal_frontend_dashboard_profile_links',
    static function (array $links): array {
        $links[] = array('id' => 'demo.ok', 'label' => 'OK', 'url' => 'https://example.test/ok', 'eligible' => true);
        $links[] = 'not-an-array';
        $links[] = array('id' => '', 'label' => 'Empty id', 'url' => 'https://example.test/x', 'eligible' => true);
        $links[] = array('id' => 'demo.xss', 'label' => '<img src=x onerror=alert(1)>', 'url' => 'https://example.test/x', 'eligible' => true);
        $links[] = array('id' => 'demo.js', 'label' => 'JS', 'url' => 'javascript:alert(1)', 'eligible' => true);
        $links[] = array('id' => 'demo.ftp', 'label' => 'FTP', 'url' => 'ftp://example.test/f', 'eligible' => true);
        $links[] = array('id' => 'demo.rel', 'label' => 'REL', 'url' => '/relative/path', 'eligible' => true);
        $links[] = array('id' => 'demo.nolabel', 'url' => 'https://example.test/x', 'eligible' => true);
        return $links;
    },
    10,
    1
);
$html = pl_render_panel();
pl_check(
    'PL5-hostile-input',
    false !== strpos($html, '>OK</a>')
        && false === strpos($html, '<img src=x')
        && false === strpos($html, 'onerror')
        && false === strpos($html, 'javascript:')
        && false === strpos($html, 'ftp://')
        && false === strpos($html, '/relative/path')
        && false === strpos($html, '>REL</a>'),
    'valid entry renders while malformed, injected, and disallowed-URL entries are skipped safely',
    'hostile input leaked: ' . substr($html, (int) strpos($html, 'Additional links'), 400)
);

/* ── PL6a: UM absent still allows eligible links ── */

pl_reset();
$GLOBALS['PL_UM_STATUS'] = 'unavailable';
add_filter(
    'hal_frontend_dashboard_profile_links',
    static function (array $links): array {
        $links[] = array('id' => 'demo.umless', 'label' => 'Umless', 'url' => 'https://example.test/u', 'eligible' => true);
        return $links;
    },
    10,
    1
);
$html = pl_render_panel();
pl_check(
    'PL6a-um-absent',
    false !== strpos($html, '>Umless</a>'),
    'with Ultimate Member unavailable the eligible link still renders',
    'link hidden without UM'
);

/* ── PL6b: disabled feature hides panel and never calls providers ── */

pl_reset();
$GLOBALS['PL_UM_STATUS'] = 'available';
$GLOBALS['PL_FEATURE'] = false;
add_filter(
    'hal_frontend_dashboard_profile_links',
    static function (array $links): array {
        $links[] = array('id' => 'demo.never', 'label' => 'Never', 'url' => 'https://example.test/n', 'eligible' => true);
        return $links;
    },
    10,
    1
);
$html = pl_render_panel();
$calls = $GLOBALS['PL_FILTER_CALLS']['hal_frontend_dashboard_profile_links'] ?? 0;
$GLOBALS['PL_FEATURE'] = true;
pl_check(
    'PL6b-feature-disabled',
    '' === trim($html) && 0 === $calls,
    'disabled ultimate_member_profile hides the panel and never invokes providers',
    'output bytes: ' . strlen($html) . '; provider calls: ' . $calls
);

/* ── PL7a: non-array provider output → no section, no fatal ── */

pl_reset();
add_filter(
    'hal_frontend_dashboard_profile_links',
    static function (array $links) { return 'not-an-array-output'; },
    10,
    1
);
$html = pl_render_panel();
pl_check(
    'PL7a-non-array-output',
    false === strpos($html, 'Additional links'),
    'a non-array provider result yields no links section and no fatal',
    'non-array output leaked: ' . substr($html, 0, 200)
);

/* ── PL7b: invalid order skipped, missing still defaults to 100 ── */

pl_reset();
add_filter(
    'hal_frontend_dashboard_profile_links',
    static function (array $links): array {
        $links[] = array('id' => 'demo.noorder', 'label' => 'NoOrder', 'url' => 'https://example.test/n', 'eligible' => true);
        $links[] = array('id' => 'demo.nullorder', 'label' => 'NullOrd', 'url' => 'https://example.test/x', 'eligible' => true, 'order' => null);
        $links[] = array('id' => 'demo.strorder', 'label' => 'StrOrd', 'url' => 'https://example.test/x', 'eligible' => true, 'order' => '5');
        $links[] = array('id' => 'demo.floatorder', 'label' => 'FloatOrd', 'url' => 'https://example.test/x', 'eligible' => true, 'order' => 1.5);
        $links[] = array('id' => 'demo.negorder', 'label' => 'NegOrd', 'url' => 'https://example.test/g', 'eligible' => true, 'order' => -3);
        return $links;
    },
    10,
    1
);
$html = pl_render_panel();
$pos_no = strpos($html, '>NoOrder</a>');
$pos_neg = strpos($html, '>NegOrd</a>');
pl_check(
    'PL7b-order-shape',
    false !== $pos_no && false !== $pos_neg && $pos_neg < $pos_no
        && false === strpos($html, 'NullOrd')
        && false === strpos($html, 'StrOrd')
        && false === strpos($html, 'FloatOrd'),
    'null/string/float orders are skipped while missing order defaults to 100 after the valid negative order',
    'order shaping broken: ' . substr($html, (int) strpos($html, 'Additional links'), 300)
);

/* ── PL7c: malformed host (spaces) rejected, valid survives ── */

pl_reset();
pl_check(
    'PL7c-url-allowed-unit',
    false === hossam_profile_link_url_allowed('https://exa mple.test/x')
        && true === hossam_profile_link_url_allowed('https://example.test/ok'),
    'a host containing spaces is rejected while a clean URL is allowed, with no network involved',
    'url gate misbehaves'
);
add_filter(
    'hal_frontend_dashboard_profile_links',
    static function (array $links): array {
        $links[] = array('id' => 'demo.good', 'label' => 'Good', 'url' => 'https://example.test/good', 'eligible' => true);
        $links[] = array('id' => 'demo.spacehost', 'label' => 'SpaceHost', 'url' => 'https://exa mple.test/x', 'eligible' => true);
        return $links;
    },
    10,
    1
);
$html = pl_render_panel();
pl_check(
    'PL7c-space-host-render',
    false !== strpos($html, '>Good</a>') && false === strpos($html, 'SpaceHost'),
    'the spaced-host entry never renders while the valid entry does',
    'spaced host leaked into output'
);

/* ── PL7d: rejected-then-valid same id → valid wins, id not burned ── */

pl_reset();
add_filter(
    'hal_frontend_dashboard_profile_links',
    static function (array $links): array {
        $links[] = array('id' => 'demo.same', 'label' => 'Bad', 'url' => 'https://exa mple.test/x', 'eligible' => true);
        $links[] = array('id' => 'demo.same', 'label' => 'Good', 'url' => 'https://example.test/good', 'eligible' => true);
        return $links;
    },
    10,
    1
);
$html = pl_render_panel();
pl_check(
    'PL7d-rejected-id-reusable',
    false !== strpos($html, '>Good</a>')
        && false === strpos($html, '>Bad</a>')
        && 1 === substr_count($html, '>Good</a>'),
    'a rejected entry does not burn its id: the later valid same-id entry renders exactly once',
    'same-id handling broken: ' . substr($html, (int) strpos($html, 'Additional links'), 300)
);

/* ── Report ── */

echo 'PL RESULT: ' . $GLOBALS['PL_PASS'] . ' pass, ' . $GLOBALS['PL_FAIL'] . ' fail (PHP ' . PHP_VERSION . ")\n";
if ($GLOBALS['PL_FAIL'] > 0) {
    echo "Failures:\n" . implode("\n", $GLOBALS['PL_FAILS']) . "\n";
    exit(1);
}
exit(0);
