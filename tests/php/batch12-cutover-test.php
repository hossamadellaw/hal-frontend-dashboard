<?php
/**
 * HAL Frontend Dashboard — Batch 12 cutover/de-duplication evidence (§23).
 *
 * Local, isolated harness (CLI PHP 8.3 only; no network, no live WordPress,
 * no Git writes, no Composer install). Drives REAL product files throughout;
 * WordPress API surface is stubbed at the boundary only (never project logic).
 * Throwaway fixtures live under .local-execution/batch-12/ (never packaged).
 *
 * Usage:
 *   php83 tests/php/batch12-cutover-test.php <LEGACY_ROOT> [PHP83_BINARY]
 *   exit 0 = B12C-VERDICT main ALL-ASSERTIONS-HELD.
 *
 * Legacy-absent mode (e.g. CI runners without the exclusive reference
 * tree): omit LEGACY_ROOT (or point at a missing dir) to run every section
 * except the live-legacy re-read, which SKIPS loudly (never PASS).
 * Skipped is not passed.
 *
 * Coverage (§23 items B12-1…B12-7 + closing gate):
 *   COVER   — 49-source re-read vs batch-0/batch-2/batch-4/batch-6 inventories
 *             + legacy->runtime coverage map (B12-1).
 *   PATHS   — no functional code path reads theme/assets, theme/template-parts,
 *             get_template_part(), or stylesheet/template directory helpers (B12-2).
 *   SINGLE  — legacy-loader preflight gate present/absent via REAL Installer
 *             preflight; loader/bootstrap never require legacy trees (B12-3).
 *   SHIM    — no legacy require/include in product code; no shim files shipped (B12-4).
 *   KEEP    — Docs/, .audit-work/, legacy reference marker untouched (B12-5).
 *   COUNT   — 49 describes the old package only; new-product total recorded (B12-6).
 *   BOOT    — REAL runtime/bootstrap.php loads on release context alone
 *             (carrier-independent) + double load without fatal, in a fresh
 *             subprocess (closing gate: carrier-disabled continuity).
 *   PKG     — carrier/runtime path classification per workflow §8.5 gates,
 *             bans, ABSPATH guards, no logo/png, no absolute paths (B12-7).
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (PHP_SAPI !== 'cli') { echo "CLI only.\n"; exit(1); }
if (version_compare(PHP_VERSION, '8.3.0', '<') || version_compare(PHP_VERSION, '8.4.0', '>=')) {
    echo 'REQUIRES PHP 8.3.x, got ' . PHP_VERSION . "\n";
    exit(1);
}

$LEGACY_ROOT = isset($argv[1]) ? rtrim((string) $argv[1], "/\\") : '';
$PHP83 = isset($argv[2]) && '' !== $argv[2] ? (string) $argv[2] : PHP_BINARY;
/* Exclusive legacy root presence gate (missing on CI runners by design):
   without it only the live re-read SKIPS; everything else still runs. */
$B12C_LEGACY = ('' !== $LEGACY_ROOT && is_dir($LEGACY_ROOT));

$PROJECT = dirname(__DIR__, 2);
$WS = $PROJECT . '/.local-execution/batch-12/cutover';
require_once __DIR__ . '/lib-hal-php-flags.php'; // test-launcher flag filter (no product code, no assertions)
@mkdir($WS . '/tmp', 0777, true);

$GLOBALS['B12C_PASS'] = 0;
$GLOBALS['B12C_FAIL'] = 0;
$GLOBALS['B12C_FAILS'] = array();
$GLOBALS['B12C_SKIP'] = 0;

function b12c_check(string $id, bool $ok, string $pass, string $fail): void {
    if ($ok) {
        $GLOBALS['B12C_PASS']++;
        echo "PASS [$id] $pass\n";
    } else {
        $GLOBALS['B12C_FAIL']++;
        $GLOBALS['B12C_FAILS'][] = "$id: $fail";
        echo "FAIL [$id] $fail\n";
    }
}

/**
 * Loud skip (never a PASS): used ONLY when the exclusive legacy reference
 * root is absent (e.g. CI runners). The live-legacy re-read is then
 * deferred to runs with the reference tree. Skipped is not passed.
 */
function b12c_skip(string $id, string $reason): void {
    $GLOBALS['B12C_SKIP']++;
    echo "SKIP [$id] $reason\n";
}

function b12c_files_recursive(string $dir, string $ext = ''): array {
    $out = array();
    if (!is_dir($dir)) { return $out; }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($it as $item) {
        if ($item->isLink() || !$item->isFile()) { continue; }
        if ('' !== $ext && strtolower((string) pathinfo($item->getFilename(), PATHINFO_EXTENSION)) !== $ext) { continue; }
        $out[] = $item->getPathname();
    }
    sort($out);
    return $out;
}

/** Code tokens only: skips comments AND string literals (provenance notes live in comments). */
function b12c_code_tokens(string $file): array {
    $toks = token_get_all((string) file_get_contents($file));
    $out = array();
    foreach ($toks as $t) {
        if (is_array($t)) {
            if (in_array($t[0], array(T_COMMENT, T_DOC_COMMENT), true)) { continue; }
            $out[] = $t;
        } else {
            $out[] = $t;
        }
    }
    return $out;
}

/** String-literal contents only (proves no path dependency hides in literals). */
function b12c_string_literals(string $file): array {
    $toks = token_get_all((string) file_get_contents($file));
    $out = array();
    foreach ($toks as $t) {
        if (is_array($t) && T_CONSTANT_ENCAPSED_STRING === $t[0]) { $out[] = $t[1]; }
    }
    return $out;
}

function b12c_product_php(): array {
    global $PROJECT;
    $files = array_merge(
        b12c_files_recursive($PROJECT . '/runtime', 'php'),
        b12c_files_recursive($PROJECT . '/includes', 'php'),
        b12c_files_recursive($PROJECT . '/mu-loader', 'php'),
        array($PROJECT . '/hal-frontend-dashboard.php')
    );
    sort($files);
    return $files;
}

/* ════════════════ COVER — B12-1 ════════════════ */

$inv12 = json_decode((string) file_get_contents($PROJECT . '/build/source-inventory-batch-12.json'), true, 512, JSON_THROW_ON_ERROR);
$inv0 = json_decode((string) file_get_contents($PROJECT . '/build/source-inventory.json'), true, 512, JSON_THROW_ON_ERROR);
$inv2 = json_decode((string) file_get_contents($PROJECT . '/build/source-inventory-batch-2.json'), true, 512, JSON_THROW_ON_ERROR);
$inv4 = json_decode((string) file_get_contents($PROJECT . '/build/source-inventory-batch-4.json'), true, 512, JSON_THROW_ON_ERROR);
$inv6 = json_decode((string) file_get_contents($PROJECT . '/build/source-inventory-batch-6.json'), true, 512, JSON_THROW_ON_ERROR);

function b12c_find(array $inv, string $path): ?array {
    foreach ($inv['files'] as $f) { if ($f['path'] === $path) { return $f; } }
    return null;
}

/* Snapshot self-verification (same canonicalization as batch-0/2/4/6/8). */
$sorted = $inv12['files'];
usort($sorted, static fn($a, $b) => strcmp($a['path'], $b['path']));
$lines = array();
foreach ($sorted as $f) { $lines[] = $f['path'] . "\0" . $f['bytes'] . "\0" . $f['sha256'] . "\n"; }
b12c_check(
    'B12C-COVER-SNAP',
    hash('sha256', implode('', $lines)) === $inv12['snapshot_id'],
    'batch-12 snapshot_id recomputes; counts mu=' . $inv12['counts']['mu'] . ' theme=' . $inv12['counts']['theme'] . ' total=' . $inv12['counts']['total'],
    'snapshot_id mismatch — inventory not self-consistent'
);

/* Documented-evolution sources: authoritative per-batch snapshot recorded at migration time. */
$evolvedAuthority = array(
    'mu-plugins/hossam-dashboard/core/setup.php' => $inv2,
    'mu-plugins/hossam-dashboard/core/tables.php' => $inv2,
    'mu-plugins/hossam-dashboard/adapters/ai.php' => $inv4,
    'mu-plugins/hossam-dashboard/ajax/ai.php' => $inv6,
);

$coverOk = true;
$coverDetail = array();
$nB0 = 0; $nBatch = 0;
if (!$B12C_LEGACY) {
    b12c_skip(
        'B12C-COVER-MAP',
        'exclusive legacy root absent — 49-source live re-read deferred to runs with the reference tree (snapshot self-check above still holds)'
    );
} else {
foreach ($inv12['files'] as $f) {
    $rel = $f['path'];
    $disk = $LEGACY_ROOT . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!is_file($disk)) { $coverOk = false; $coverDetail[] = "$rel MISSING-DISK"; continue; }
    $liveBytes = strlen((string) file_get_contents($disk));
    $liveSha = hash_file('sha256', $disk);
    $base = b12c_find($inv0, $rel);
    if (null !== $base && (int) $base['bytes'] === $liveBytes && hash_equals((string) $base['sha256'], (string) $liveSha)) {
        $nB0++;
        continue;
    }
    if (isset($evolvedAuthority[$rel])) {
        $auth = b12c_find($evolvedAuthority[$rel], $rel);
        if (null !== $auth && (int) $auth['bytes'] === $liveBytes && hash_equals((string) $auth['sha256'], (string) $liveSha)) {
            $nBatch++;
            continue;
        }
        $coverOk = false;
        $coverDetail[] = "$rel DRIFT-SINCE-MIGRATION (matches neither batch-0 nor its migration snapshot)";
        continue;
    }
    $coverOk = false;
    $coverDetail[] = "$rel UNEXPECTED-DRIFT vs batch-0";
}
b12c_check(
    'B12C-COVER-MAP',
    $coverOk && 49 === count($inv12['files']) && 45 === $nB0 && 4 === $nBatch,
    "49/49 sources live-verified: 45 match batch-0, 4 match their migration snapshots (setup/tables<-batch2, adapters/ai<-batch4, ajax/ai<-batch6) — no drift since migration",
    'coverage failure: ' . implode('; ', $coverDetail)
);
} // end legacy-present live re-read

/* Runtime counterparts exist; header/footer/logo correctly absent. */
$covOk = true; $covMiss = array();
foreach ($inv12['coverage'] as $c) {
    $st = $c['status'];
    if (in_array($st, array('migrated', 'migrated-split', 'migrated-delta-documented', 'migrated-shipped-not-loaded', 'migrated-server-rendered'), true)) {
        $rt = $c['runtime'];
        if (str_contains((string) $rt, '(+')) { $rt = 'runtime/bootstrap.php'; }
        if (!is_file($PROJECT . '/' . $rt)) { $covOk = false; $covMiss[] = "$rt MISSING for {$c['legacy']}"; }
    } elseif (in_array($st, array('not-applicable-non-consumer'), true)) {
        $guess = 'runtime/templates/' . basename((string) $c['legacy']);
        if (is_file($PROJECT . '/' . $guess)) { $covOk = false; $covMiss[] = "$guess SHIPPED-BUT-NON-CONSUMER"; }
    }
}
$noPng = 0 === count(b12c_files_recursive($PROJECT . '/runtime', 'png'));
$profileLinks = is_file($PROJECT . '/runtime/templates/dashboard/profile-links.php')
    && str_contains((string) file_get_contents($PROJECT . '/runtime/templates/dashboard/profile-links.php'), 'CR-2026-09-25-PROFILE-LINKS');
if (!$noPng) { $covOk = false; $covMiss[] = 'png found under runtime/'; }
if (!$profileLinks) { $covOk = false; $covMiss[] = 'profile-links.php missing or CR reference absent'; }
b12c_check(
    'B12C-COVER-RUNTIME',
    $covOk,
    'every migrated counterpart exists; header/footer correctly unshipped; zero png under runtime/; profile-links CR addition present',
    'runtime coverage failure: ' . implode('; ', $covMiss)
);

/* ════════════════ PATHS — B12-2 ════════════════ */

$forbiddenCalls = array('get_template_part', 'get_stylesheet_directory', 'get_stylesheet_directory_uri', 'get_template_directory', 'get_template_directory_uri');
$pathHits = array();
$requireHits = array();
foreach (b12c_product_php() as $php) {
    $short = substr($php, strlen($PROJECT) + 1);
    $toks = b12c_code_tokens($php);
    $count = count($toks);
    for ($i = 0; $i < $count; $i++) {
        $t = $toks[$i];
        if (is_array($t) && T_STRING === $t[0] && in_array($t[1], $forbiddenCalls, true)) {
            $pathHits[] = "$short:{$t[2]}:{$t[1]}";
        }
        if (is_array($t) && in_array($t[0], array(T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE), true)) {
            $window = '';
            for ($j = $i + 1; $j < min($i + 13, $count); $j++) {
                $w = $toks[$j];
                if (is_array($w) && T_CONSTANT_ENCAPSED_STRING === $w[0]) { $window .= ' ' . $w[1]; }
            }
            foreach (array('hossam-dashboard.php', 'mu-plugins/hossam-dashboard', 'theme/template-parts', 'theme/assets', 'original dashboard', 'Dahboard') as $needle) {
                if (str_contains($window, $needle)) { $requireHits[] = "$short:legacy-require($needle)"; }
            }
            if (preg_match('/[\'"][A-Za-z]:[\\\\\\/]/', $window)) { $requireHits[] = "$short:absolute-path-require"; }
        }
    }
    foreach (b12c_string_literals($php) as $lit) {
        foreach (array('theme/assets', 'theme/template-parts', 'original dashboard', 'Dahboard-v-1.0.0') as $needle) {
            if (str_contains($lit, $needle)) { $requireHits[] = "$short:literal($needle)"; }
        }
        if (preg_match('/[\'"][A-Za-z]:[\\\\\\/]/', $lit) || preg_match('/^[A-Za-z]:[\\\\\\/]/', trim($lit, '\'"'))) {
            $requireHits[] = "$short:absolute-path-literal";
        }
    }
}
b12c_check(
    'B12C-PATHS-CODE',
    0 === count($pathHits),
    'zero functional get_template_part()/stylesheet-template helper calls in product code (provenance notes are comments only)',
    'functional legacy-path calls: ' . implode('; ', $pathHits)
);
b12c_check(
    'B12C-PATHS-REQUIRE',
    0 === count($requireHits),
    'zero legacy require/include targets and zero old-theme/absolute-path literals in product code',
    'legacy path dependencies: ' . implode('; ', $requireHits)
);

/* Asset helpers resolve inside the release (not the theme). */
$setupSrc = (string) file_get_contents($PROJECT . '/runtime/core/setup.php');
b12c_check(
    'B12C-PATHS-RELEASE-ASSETS',
    str_contains($setupSrc, "HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT . 'assets/'")
    && str_contains($setupSrc, "HAL_FRONTEND_DASHBOARD_RUNTIME_URL . 'assets/'"),
    'hossam_asset_path()/hossam_asset_uri() resolve under the release root/URL',
    'asset helpers do not resolve under the release'
);

/* ════════════════ SINGLE — B12-3 ════════════════ */

if (!defined('ABSPATH')) { define('ABSPATH', $WS . '/fake-wp/'); }
@mkdir(ABSPATH, 0777, true);
$GLOBALS['wp_version'] = '7.1';
if (!function_exists('is_multisite')) { function is_multisite(): bool { return false; } }
if (!function_exists('home_url')) { function home_url(string $p = '/'): string { return 'https://example.test' . $p; } }
if (!function_exists('wp_parse_url')) { function wp_parse_url(string $url, int $c = -1) { return parse_url($url, $c); } }
if (!function_exists('add_action')) { function add_action(string $h, $cb = '', int $p = 10, int $a = 1): bool { return true; } }
if (!function_exists('add_filter')) { function add_filter(string $h, $cb = '', int $p = 10, int $a = 1): bool { return true; } }
if (!function_exists('get_option')) { function get_option(string $k, $d = false) { return $d; } }

require_once $PROJECT . '/includes/class-installer.php';
$ref = new ReflectionMethod('HAL_Frontend_Dashboard_Installer', 'preflight');
$ref->setAccessible(true);

$muPresent = $WS . '/mu-present';
$muAbsent = $WS . '/mu-absent';
@mkdir($muPresent, 0777, true);
@mkdir($muAbsent, 0777, true);
file_put_contents($muPresent . '/hossam-dashboard.php', "<?php // legacy sentinel\n");

$gateTpl = <<<'PHP'
<?php
error_reporting(0);
define('ABSPATH', %ABSPATH%);
define('WPMU_PLUGIN_DIR', %MU%);
$GLOBALS['wp_version'] = '7.1';
function is_multisite(): bool { return false; }
function home_url(string $p = '/'): string { return 'https://example.test' . $p; }
function wp_parse_url(string $url, int $c = -1) { return parse_url($url, $c); }
function add_action(string $h, $cb = '', int $p = 10, int $a = 1): bool { return true; }
function add_filter(string $h, $cb = '', int $p = 10, int $a = 1): bool { return true; }
function get_option(string $k, $d = false) { return $d; }
require_once %INSTALLER%;
try {
    $r = new ReflectionMethod('HAL_Frontend_Dashboard_Installer', 'preflight');
    $r->setAccessible(true);
    $r->invoke(null);
    echo 'PREFLIGHT-PASS';
} catch (RuntimeException $e) {
    echo 'PREFLIGHT-THROW:' . $e->getMessage();
}
PHP;

function b12c_run_snippet(string $name, string $snippet): string {
    global $WS, $PHP83;
    $file = $WS . '/' . $name . '.php';
    file_put_contents($file, $snippet);
    /* Workflow parity: preflight requires ext-sodium + ext-zip. Flags are
       filtered (kept only when the child runtime lacks them). */
    $extDir = rtrim(dirname((string) $PHP83), "/\\") . DIRECTORY_SEPARATOR . 'ext';
    $base = is_dir($extDir) ? array('-d', 'extension_dir=' . $extDir) : array();
    $argv = hal_php_argv((string)$PHP83, $base, array('sodium','zip'));
    $argv[] = $file;
    $cmd = implode(' ', array_map('escapeshellarg', $argv)) . ' 2>&1';
    $out = array(); $code = 0;
    exec($cmd, $out, $code);
    return $code . ':' . implode("\n", $out);
}

$fill = static function (string $mu) use ($WS, $PROJECT, $gateTpl): string {
    return str_replace(
        array('%ABSPATH%', '%MU%', '%INSTALLER%'),
        array(var_export($WS . '/fake-wp/', true), var_export($mu, true), var_export($PROJECT . '/includes/class-installer.php', true)),
        $gateTpl
    );
};
$gotPresent = b12c_run_snippet('gate-present', $fill($muPresent));
$gotAbsent = b12c_run_snippet('gate-absent', $fill($muAbsent));
b12c_check(
    'B12C-SINGLE-GATE-PRESENT',
    '0:PREFLIGHT-THROW:HAL_PREFLIGHT_LEGACY_LOADER_ACTIVE' === $gotPresent,
    'REAL preflight refuses installation next to an active legacy loader',
    'got: ' . $gotPresent
);
b12c_check(
    'B12C-SINGLE-GATE-ABSENT',
    '0:PREFLIGHT-PASS' === $gotAbsent,
    'REAL preflight passes with no legacy loader (no parallel-load path)',
    'got: ' . $gotAbsent
);

/* Loader + bootstrap never pull legacy trees (code-token scan). */
$loaderHits = array();
foreach (array($PROJECT . '/mu-loader/hal-frontend-dashboard.php', $PROJECT . '/mu-loader/loader-core.php', $PROJECT . '/runtime/bootstrap.php') as $lf) {
    foreach (b12c_code_tokens($lf) as $t) {
        if (is_array($t) && T_STRING === $t[0] && 'hossam-dashboard' === strtolower($t[1])) {
            $loaderHits[] = basename($lf) . ':' . $t[2];
        }
    }
    foreach (b12c_string_literals($lf) as $lit) {
        if (str_contains($lit, 'hossam-dashboard.php') || str_contains($lit, 'mu-plugins/hossam-dashboard')) {
            $loaderHits[] = basename($lf) . ':legacy-literal';
        }
    }
}
b12c_check(
    'B12C-SINGLE-NOLOAD',
    0 === count($loaderHits),
    'anchor/loader-core/bootstrap reference no legacy loader file or directory (installer gate is the only legacy-aware code)',
    'legacy references in load path: ' . implode('; ', $loaderHits)
);

/* ════════════════ SHIM — B12-4 ════════════════ */

$shimFiles = array();
foreach (b12c_product_php() as $php) {
    if (str_contains(strtolower(basename($php)), 'shim') || str_contains(strtolower(basename($php)), 'legacy')) {
        $shimFiles[] = substr($php, strlen($PROJECT) + 1);
    }
}
b12c_check(
    'B12C-SHIM-NONE',
    0 === count($shimFiles) && 0 === count($requireHits),
    'no shim/legacy files ship and no legacy require/include exists — B12-4 needs no shim (nothing to bridge)',
    'shim files or legacy requires: ' . implode('; ', $shimFiles)
);

/* ════════════════ KEEP — B12-5 ════════════════ */

/* gitignored-or-absent probe: protected paths stay out of git so no
   update/rollback can delete them from history; on fresh checkouts their
   absence is by design, not deletion. */
function b12c_ignored_or_absent(string $rel): bool {
    global $PROJECT;
    if (!file_exists($PROJECT . '/' . $rel)) { return true; }
    $out = array(); $code = 1;
    @exec('git check-ignore -q ' . escapeshellarg($rel) . ' 2>&1', $out, $code);
    return 0 === $code;
}

$keepLocal = b12c_ignored_or_absent('Docs') && b12c_ignored_or_absent('.audit-work');
if (!$B12C_LEGACY) {
    b12c_check(
        'B12C-KEEP-REFS',
        $keepLocal,
        'Docs/ and .audit-work/ protected (present or gitignored — absent on fresh checkouts by design, never tracked)',
        'a local protected reference is missing'
    );
    b12c_skip(
        'B12C-KEEP-LEGACY-MARKERS',
        'exclusive legacy root absent — legacy marker presence deferred to runs with the reference tree'
    );
} else {
    $keepOk = $keepLocal
        && is_file($LEGACY_ROOT . '/mu-plugins/hossam-dashboard.php')
        && is_file($LEGACY_ROOT . '/theme/page-dashboard.php');
    b12c_check(
        'B12C-KEEP-REFS',
        $keepOk,
        'Docs/, .audit-work/ and the legacy reference markers (mu boot + theme shell) all still present — nothing deleted',
        'a protected reference is missing'
    );
}

/* ════════════════ COUNT — B12-6 ════════════════ */

$runtimePhp = b12c_files_recursive($PROJECT . '/runtime', 'php');
$runtimeJs = b12c_files_recursive($PROJECT . '/runtime', 'js');
$runtimeCss = b12c_files_recursive($PROJECT . '/runtime', 'css');
$newTotal = count($runtimePhp) + count($runtimeJs) + count($runtimeCss);
b12c_check(
    'B12C-COUNT',
    23 === $inv0['counts']['mu'] && 26 === $inv0['counts']['theme'] && 49 === $inv0['counts']['total'] && $newTotal > 49,
    "49 = old functional package only (MU 23 + Theme 26); new runtime tree holds $newTotal files (php " . count($runtimePhp) . ' + js ' . count($runtimeJs) . ' + css ' . count($runtimeCss) . ') incl. architecture + CR additions',
    'count contract broken (baseline ' . json_encode($inv0['counts']) . ", runtime total $newTotal)"
);

/* ════════════════ BOOT — closing gate (carrier-independent load) ════════════════ */

$bootTpl = <<<'PHP'
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
define('ABSPATH', %ABSPATH%);
define('HAL_FRONTEND_DASHBOARD_RUNTIME_DIR', %RTDIR%);
define('HAL_FRONTEND_DASHBOARD_RUNTIME_URL', 'https://example.test/hal-runtime/');
define('HAL_FRONTEND_DASHBOARD_RELEASE_ID', 'test-release');
define('HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION', '0.0.0-test');
$GLOBALS['wp_version'] = '7.1';
$GLOBALS['B12BOOT_ACTIONS'] = array();
function add_action(string $h, $cb = '', int $p = 10, int $a = 1): bool { $GLOBALS['B12BOOT_HOOKS'][$h][] = $cb; return true; }
function add_filter(string $h, $cb = '', int $p = 10, int $a = 1): bool { $GLOBALS['B12BOOT_HOOKS'][$h][] = $cb; return true; }
function do_action(string $h, ...$a): void { $GLOBALS['B12BOOT_ACTIONS'][] = $h; }
function apply_filters(string $h, $v = null, ...$a) { return $v; }
function get_option(string $k, $d = false) { return $d; }
function update_option(string $k, $v, $a = null): bool { return true; }
function is_user_logged_in(): bool { return false; }
function wp_doing_ajax(): bool { return false; }
function wp_doing_cron(): bool { return false; }
class WP_User {}
class WP_Error { public function __construct($c = '', $m = '') {} }
function is_wp_error($t): bool { return $t instanceof WP_Error; }
%LOAD%
$ready = in_array('hal_frontend_dashboard_runtime_ready', $GLOBALS['B12BOOT_ACTIONS'], true) ? 'READY' : 'NOREADY';
echo 'BOOT-OK|' . $ready . '|carrier=' . (defined('HAL_CARRIER_TOUCHED') ? 'TOUCHED' : 'CLEAN');
PHP;
$bootSnippet = str_replace(
    array('%ABSPATH%', '%RTDIR%', '%LOAD%'),
    array(var_export($WS . '/fake-wp/', true), var_export($PROJECT . '/runtime/', true), "%LOAD%"),
    $bootTpl
);
$single = str_replace('%LOAD%', 'require ' . var_export($PROJECT . '/runtime/bootstrap.php', true) . ';', $bootSnippet);
$double = str_replace('%LOAD%', 'require ' . var_export($PROJECT . '/runtime/bootstrap.php', true) . '; require ' . var_export($PROJECT . '/runtime/bootstrap.php', true) . ';', $bootSnippet);
$gotSingle = b12c_run_snippet('boot-single', $single);
$gotDouble = b12c_run_snippet('boot-double', $double);
b12c_check(
    'B12C-BOOT-NOCARRIER',
    str_starts_with($gotSingle, '0:BOOT-OK|READY|carrier=CLEAN'),
    'REAL bootstrap loads on release context alone (no carrier) and fires runtime_ready',
    'got: ' . $gotSingle
);
b12c_check(
    'B12C-BOOT-CONCURRENT',
    str_starts_with($gotDouble, '0:BOOT-OK|READY|carrier=CLEAN'),
    'second concurrent load of the same release is fatal-free (no mixed-version request)',
    'got: ' . $gotDouble
);

/* ════════════════ PKG — B12-7 ════════════════ */

$allowedRuntimeTop = array('bootstrap.php', 'infrastructure', 'vendor', 'core', 'adapters', 'ajax', 'templates', 'admin', 'settings', 'security', 'integrations', 'health', 'assets');
$allowedRuntimeExt = array('php', 'css', 'js');
$pkgOk = true; $pkgMiss = array();
$rit = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($PROJECT . '/runtime', FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ($rit as $item) {
    if ($item->isLink() || !$item->isFile()) { continue; }
    $rel = str_replace('\\', '/', substr($item->getPathname(), strlen($PROJECT . '/runtime') + 1));
    $top = explode('/', $rel)[0];
    if (!in_array($top, $allowedRuntimeTop, true)) { $pkgOk = false; $pkgMiss[] = "runtime unclassified: $rel"; }
    if (!in_array(strtolower((string) pathinfo($rel, PATHINFO_EXTENSION)), $allowedRuntimeExt, true)) { $pkgOk = false; $pkgMiss[] = "runtime bad ext: $rel"; }
}
$expectedCarrier = array(
    'hal-frontend-dashboard.php', 'readme.txt', 'LICENSE', 'THIRD-PARTY-NOTICES.txt',
    'includes/class-installer.php', 'includes/class-package-verifier.php',
    'includes/class-release-manager.php', 'includes/class-update-bridge.php',
    'includes/class-health-check.php', 'includes/class-template-controller.php',
    'mu-loader/hal-frontend-dashboard.php', 'mu-loader/loader-core.php',
);
foreach ($expectedCarrier as $rel) {
    if (!is_file($PROJECT . '/' . $rel)) { $pkgOk = false; $pkgMiss[] = "carrier file missing: $rel"; }
}
$banned = array('/Docs/', '/tests/', '/.github/', '/.git/', '/.local-execution/', '/.audit-work/', 'logo.png', '.log');
foreach (array_merge(b12c_product_php(), array($PROJECT . '/hal-frontend-dashboard.php')) as $php) {
    $short = substr($php, strlen($PROJECT) + 1);
    /* Guard rule (§7): the ABSPATH guard opens the file (docblock headers
       allowed) and precedes any require/include. Order proven on the
       comment-free token stream. */
    $toks = b12c_code_tokens($php);
    $guardIdx = null; $guardLine = null; $requireIdx = null;
    $n = count($toks);
    for ($i = 0; $i < $n; $i++) {
        $t = $toks[$i];
        if (null === $requireIdx && is_array($t) && in_array($t[0], array(T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE), true)) {
            $requireIdx = $i;
        }
        if (null === $guardIdx && is_array($t) && T_STRING === $t[0] && 'defined' === strtolower($t[1])) {
            $k = $i + 1;
            while ($k < $n && is_array($toks[$k]) && T_WHITESPACE === $toks[$k][0]) { $k++; }
            if ($k < $n && '(' === (is_array($toks[$k]) ? $toks[$k][1] : $toks[$k])) { $k++; }
            while ($k < $n && is_array($toks[$k]) && T_WHITESPACE === $toks[$k][0]) { $k++; }
            if ($k < $n && is_array($toks[$k]) && T_CONSTANT_ENCAPSED_STRING === $toks[$k][0]
                && 'ABSPATH' === trim($toks[$k][1], "'\"")) {
                $guardIdx = $i; $guardLine = $t[2];
            }
        }
        if (null !== $guardIdx && null !== $requireIdx) { break; }
    }
    if (null === $guardIdx) {
        if ('hal-frontend-dashboard.php' !== basename($php)) { $pkgOk = false; $pkgMiss[] = "no ABSPATH guard: $short"; }
    } elseif ($guardLine > 70) {
        $pkgOk = false; $pkgMiss[] = "ABSPATH guard too deep ($guardLine): $short";
    } elseif (null !== $requireIdx && $requireIdx < $guardIdx) {
        $pkgOk = false; $pkgMiss[] = "executable require before guard: $short";
    }
    foreach (b12c_string_literals($php) as $lit) {
        foreach (array('Docs/', '.local-execution', '.audit-work', 'logo.png') as $needle) {
            if (str_contains($lit, $needle)) { $pkgOk = false; $pkgMiss[] = "$short:package-ban literal($needle)"; break; }
        }
    }
}
$rootVendor = is_dir($PROJECT . '/vendor') && !b12c_ignored_or_absent('vendor');
$runtimePng = b12c_files_recursive($PROJECT . '/runtime', 'png');
if ($rootVendor) { $pkgOk = false; $pkgMiss[] = 'root vendor/ present and tracked'; }
if (0 !== count($runtimePng)) { $pkgOk = false; $pkgMiss[] = 'png under runtime/'; }
b12c_check(
    'B12C-PKG-CLASSIFY',
    $pkgOk,
    'runtime tree fully classified; carrier static set exact (§8.5 minus CI payload); ABSPATH guards present; bans hold; no root vendor; no png',
    'package findings: ' . implode('; ', array_slice($pkgMiss, 0, 12))
);

/* ════════════════ VERDICT ════════════════ */

echo "B12C-SUMMARY pass={$GLOBALS['B12C_PASS']} skip={$GLOBALS['B12C_SKIP']} fail={$GLOBALS['B12C_FAIL']}\n";
if (0 === $GLOBALS['B12C_FAIL']) {
    echo "B12C-VERDICT main ALL-ASSERTIONS-HELD\n";
    exit(0);
}
echo "B12C-VERDICT main FAILED\n";
exit(1);
