<?php
/**
 * HAL Frontend Dashboard — Batch 11 closure evidence (B11-01…B11-05 + gaps).
 *
 * Local, isolated harness (CLI PHP only; no network, no live WordPress, no
 * Git writes). Requires ext-sodium + ext-zip like the release workflow.
 * Uses REAL product files throughout; throwaway Ed25519 test keys only
 * (HAL_FRONTEND_DASHBOARD_TEST_PUBLIC_KEY gate, same pattern as the
 * installer); the locked PUC v5.7 is staged read-only from the local
 * composer cache (ref 275a96a verified) into run-space — never installed
 * into the product tree.
 *
 * Coverage:
 *   WIRE  — bootstrap registers exactly one import-cron consumer in update
 *           contexts and none in visitor context (B11-01 wiring).
 *   AUTO  — filter_auto_update from MU reality: runtime-only true,
 *           carrier-only true, neither/ambiguous passthrough (B11-02).
 *   CHAIN — connected local update via the REAL import path: bridge records
 *           the candidate, import_candidate_cron consumes it (stage, loader,
 *           promote with stub health), tampered payload rejected with active
 *           unchanged, failed health rolls back to previous (R10/I4 gaps).
 *   PKG   — REAL product-tree Carrier package built + verified file by file
 *           (bans, single root, ABSPATH guards, both manifests with test-key
 *           signatures); vendor/ excluded with a documented deviation (P8).
 *   ZIP   — entry-count / compressed-size / ratio / traversal / drive /
 *           symlink-entry / tree-symlink cases against the REAL verifier.
 *   PUC   — REAL locked v5.7: strategy constants, enableReleaseAssets with
 *           the bridge regex, matchesAssetFilter matrix, strategy collapse.
 *   MIRROR — includes/ ↔ runtime/infrastructure/ byte identity (bridge,
 *           manager).
 *
 * Usage: php -d extension=sodium tests/php/batch11-closure-test.php (exit 0)
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (PHP_SAPI !== 'cli') { echo "CLI only.\n"; exit(1); }
if (!extension_loaded('sodium') || !class_exists('ZipArchive')) {
    echo "SKIP: ext-sodium and ext-zip are required (workflow parity).\n";
    exit(2);
}

$PROJECT = dirname(__DIR__, 2);
$GLOBALS['B11C_PROJECT'] = $PROJECT;
$WS = $PROJECT . '/.local-execution/batch-11-closure';
require_once __DIR__ . '/lib-hal-php-flags.php'; // test-launcher flag filter (no product code, no assertions)
@mkdir($WS, 0777, true);

$GLOBALS['B11C_PASS'] = 0;
$GLOBALS['B11C_FAIL'] = 0;
$GLOBALS['B11C_FAILS'] = array();
$GLOBALS['B11C_SKIP'] = 0;

function b11c_check(string $id, bool $ok, string $pass, string $fail): void {
    if ($ok) {
        $GLOBALS['B11C_PASS']++;
        echo "PASS [$id] $pass\n";
    } else {
        $GLOBALS['B11C_FAIL']++;
        $GLOBALS['B11C_FAILS'][] = "$id: $fail";
        echo "FAIL [$id] $fail\n";
    }
}

/**
 * Loud skip (never a PASS): used ONLY when neither the dev-machine composer
 * cache nor a composer-installed locked vendor copy of PUC is available
 * (e.g. offline runners). The REAL locked v5.7 bytes cannot be fabricated,
 * so the PUC section is deferred to runs with one of those sources.
 * Skipped is not passed.
 */
function b11c_skip(string $id, string $reason): void {
    $GLOBALS['B11C_SKIP']++;
    echo "SKIP [$id] $reason\n";
}

function b11c_rmdir(string $dir): void {
    if (!is_dir($dir)) { return; }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        if ($item->isLink() || $item->isFile()) { @unlink($item->getPathname()); }
        else { @rmdir($item->getPathname()); }
    }
    @rmdir($dir);
}

function b11c_code(callable $fn): string {
    try {
        $fn();
    } catch (Throwable $e) {
        return $e->getMessage();
    }
    return '';
}

/* ════════════════ WordPress boundary stubs ════════════════ */

if (!defined('ABSPATH')) { define('ABSPATH', $WS . '/fake-wp/'); }
@mkdir(ABSPATH, 0777, true);

$GLOBALS['B11C_OPTIONS'] = array();
$GLOBALS['B11C_HOOKS'] = array();
$GLOBALS['B11C_SCHEDULED'] = array();

function add_action(string $h, $cb = '', int $p = 10, int $a = 1): bool {
    $GLOBALS['B11C_HOOKS'][$h][] = array($cb, $p);
    return true;
}
function add_filter(string $h, $cb = '', int $p = 10, int $a = 1): bool {
    $GLOBALS['B11C_HOOKS'][$h][] = array($cb, $p);
    return true;
}
function get_option(string $k, $d = false) {
    return array_key_exists($k, $GLOBALS['B11C_OPTIONS']) ? $GLOBALS['B11C_OPTIONS'][$k] : $d;
}
function update_option(string $k, $v, $a = null): bool {
    $GLOBALS['B11C_OPTIONS'][$k] = $v;
    return true;
}
function delete_option(string $k): bool {
    unset($GLOBALS['B11C_OPTIONS'][$k]);
    return true;
}
function wp_next_scheduled(string $h, array $args = array()) {
    $key = $h . '|' . json_encode(array_values($args));
    return $GLOBALS['B11C_SCHEDULED'][$key] ?? false;
}
function wp_schedule_single_event(int $t, string $h, array $args = array()): bool {
    $GLOBALS['B11C_SCHEDULED'][$h . '|' . json_encode(array_values($args))] = $t;
    return true;
}
function wp_parse_url(string $url, int $c = -1) { return parse_url($url, $c); }
function is_wp_error($t): bool { return $t instanceof WP_Error; }
if (!class_exists('WP_Error')) {
    class WP_Error {
        private string $c;
        public function __construct($c = '', $m = '') { $this->c = (string) $c; }
        public function get_error_code() { return $this->c; }
    }
}
$GLOBALS['wp_version'] = '7.1';

/* ════════════════ Throwaway signing key (test only) ════════════════ */

$sign_keypair = sodium_crypto_sign_keypair();
$sign_secret = sodium_crypto_sign_secretkey($sign_keypair);
$sign_public = sodium_crypto_sign_publickey($sign_keypair);
define('HAL_FRONTEND_DASHBOARD_TEST_PUBLIC_KEY', base64_encode($sign_public));
define('HAL_FRONTEND_DASHBOARD_TEST_KEY_FINGERPRINT', strtolower(hash('sha256', $sign_public)));

function b11c_sign(array $manifest): array {
    global $sign_secret;
    $raw = str_replace("\r\n", "\n", json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . "\n";
    $sig = sodium_crypto_sign_detached($raw, $sign_secret);
    return array($raw, base64_encode($sig) . "\n");
}

function b11c_runtime_manifest(string $version, string $sha_full, int $seq, string $archive_sha, array $files, ?string $loader_version = null): array {
    $loader_version = $loader_version ?? $version;
    return array(
        'schema' => 1, 'product' => 'hal-frontend-dashboard', 'version' => $version,
        'release_sequence' => $seq, 'release_id' => $version . '+' . $sha_full, 'tag' => 'v' . $version,
        'commit_sha' => $sha_full, 'requires_wp' => '7.0', 'requires_php' => '8.3',
        'loader_api_min' => 1, 'loader_api_max' => 1, 'loader_core_version' => $loader_version,
        'schema_version' => '1', 'archive_sha256' => $archive_sha, 'files' => $files,
    );
}

/* ════════════════ WIRE: import consumer registration ════════════════ */

$bootstrap_source = (string) file_get_contents($PROJECT . '/runtime/bootstrap.php');
$wire_ok = 1 === preg_match(
    "/is_update_context\(\)[\s\S]*?add_action\(\s*HAL_Frontend_Dashboard_Update_Bridge::IMPORT_CRON_HOOK[\s\S]*?import_candidate_cron/",
    $bootstrap_source
);
b11c_check(
    'B11C-WIRE-STATIC',
    $wire_ok,
    'bootstrap registers the import consumer inside the update-context guard, after the infrastructure requires',
    'registration block not found in its guarded position'
);

require $PROJECT . '/includes/class-package-verifier.php';
require $PROJECT . '/includes/class-release-manager.php';
require $PROJECT . '/includes/class-health-check.php';
require $PROJECT . '/includes/class-update-bridge.php';

// Execute the REAL registration statement extracted from bootstrap source
// (paren-balanced scan: the closure body itself contains ');').
$extracted = '';
$anchor = strpos($bootstrap_source, 'add_action(');
if (false !== $anchor) {
    $probe = strpos($bootstrap_source, 'HAL_Frontend_Dashboard_Update_Bridge::IMPORT_CRON_HOOK', $anchor);
    if (false !== $probe) {
        $depth = 0;
        $len = strlen($bootstrap_source);
        for ($i = $anchor; $i < $len; $i++) {
            $ch = $bootstrap_source[$i];
            if ('(' === $ch) { $depth++; }
            elseif (')' === $ch) {
                $depth--;
                if (0 === $depth) {
                    $extracted = substr($bootstrap_source, $anchor, $i - $anchor + 1) . ';';
                    break;
                }
            }
        }
    }
}
b11c_check(
    'B11C-WIRE-EXTRACT',
    '' !== $extracted,
    'the exact registration statement extracts from bootstrap source',
    'extraction failed — bootstrap drifted from the reviewed shape'
);
if ('' !== $extracted) {
    if (!defined('WP_ADMIN')) { define('WP_ADMIN', true); }
    $hal_runtime_root = $PROJECT . '/runtime/';
    eval($extracted);
    $recorded = $GLOBALS['B11C_HOOKS'][HAL_Frontend_Dashboard_Update_Bridge::IMPORT_CRON_HOOK] ?? array();
    b11c_check(
        'B11C-WIRE-CONSUMER',
        1 === count($recorded) && ($recorded[0][0] instanceof Closure),
        'exactly one import-cron consumer: a lazy closure (was: import_handlers=0)',
        'consumers: ' . json_encode(array_map(static fn($r) => is_object($r[0]) ? get_class($r[0]) : $r[0], $recorded))
    );
    // Firing paths of the REAL closure, each in a FRESH subprocess (class
    // state cannot be reset in-process):
    //  (a) manager absent → closure lazy-loads the infrastructure copy,
    //      finds no candidate, exits quietly;
    //  (b) manager already present (includes/ copy) → no second copy loads
    //      (no duplicate declaration), same quiet exit.
    $fire_tpl = <<<'PHP'
<?php
error_reporting(0);
define('ABSPATH', %ABSPATH%);
define('WPMU_PLUGIN_DIR', %MU%);
define('WP_PLUGIN_DIR', %PLUGINS%);
define('WP_ADMIN', true);
function add_action(string $h, $cb = '', int $p = 10, int $a = 1): bool {
    $GLOBALS['FIRE_HOOKS'][$h][] = $cb;
    return true;
}
function add_filter(string $h, $cb = '', int $p = 10, int $a = 1): bool { return true; }
function get_option(string $k, $d = false) { return $d; }
function update_option(string $k, $v, $a = null): bool { return true; }
function delete_option(string $k): bool { return true; }
function wp_next_scheduled(string $h, array $a = array()) { return false; }
function wp_schedule_single_event(int $t, string $h, array $a = array()): bool { return true; }
%PRELOAD%
require %BRIDGE%;
$hal_runtime_root = %PROJECT% . '/runtime/';
%STMT%
$hooks = $GLOBALS['FIRE_HOOKS']['hal_frontend_dashboard_import_candidate'] ?? array();
if (1 !== count($hooks) || !($hooks[0] instanceof Closure)) { echo 'WIRE-BAD'; exit(1); }
$hooks[0]();
$after = class_exists('HAL_Frontend_Dashboard_Release_Manager', false);
echo ($after ? 'LOADED' : 'ABSENT') . '|DONE';
PHP;
    $fire_runner = static function (string $preload) use ($WS, $PROJECT, $fire_tpl): string {
        $snippet = str_replace(
            array('%ABSPATH%', '%MU%', '%PLUGINS%', '%PRELOAD%', '%BRIDGE%', '%PROJECT%', '%STMT%'),
            array(
                var_export($WS . '/fake-wp/', true),
                var_export($WS . '/mu-plugins/', true),
                var_export($WS . '/wp-plugins/', true),
                $preload,
                var_export($PROJECT . '/includes/class-update-bridge.php', true),
                var_export($PROJECT, true),
                $GLOBALS['B11C_FIRE_STMT'],
            ),
            $fire_tpl
        );
        $file = $WS . '/fire-' . md5($preload) . '.php';
        file_put_contents($file, $snippet);
    exec('php ' . escapeshellarg($file) . ' 2>&1', $out, $code);
        return $code . ':' . implode('', $out);
    };
    // Re-extract the raw statement for the subprocess template.
    $GLOBALS['B11C_FIRE_STMT'] = $extracted;
    $fire_absent = $fire_runner('');
    $fire_present = $fire_runner("require " . var_export($PROJECT . '/includes/class-release-manager.php', true) . ";");
    b11c_check(
        'B11C-WIRE-FIRE-LAZY',
        '0:LOADED|DONE' === $fire_absent,
        'manager-absent fire lazy-loads the infrastructure copy and exits quietly with no candidate',
        'got: ' . $fire_absent
    );
    b11c_check(
        'B11C-WIRE-FIRE-PRESENT',
        '0:LOADED|DONE' === $fire_present,
        'manager-present fire loads no second copy and exits quietly with no candidate',
        'got: ' . $fire_present
    );
}

/* ════════════════ AUTO: MU-reality version policy (B11-02) ════════════════ */

$offer = array(
    'plugin' => 'hal-frontend-dashboard/hal-frontend-dashboard.php',
    'slug' => 'hal-frontend-dashboard',
    'new_version' => '2.0.0',
    'package' => 'https://github.com/hossamadellaw/hal-frontend-dashboard/releases/download/v2.0.0/hal-frontend-dashboard-2.0.0.zip',
);
b11c_check(
    'B11C-AUTO-NO-VERSION',
    false === HAL_Frontend_Dashboard_Update_Bridge::filter_auto_update(false, $offer),
    'no version constant anywhere passes the offer through untouched (fail-closed)',
    'offer enforced without any installed version'
);
// Carrier-only reality in a FRESH process (constants cannot be undefined hereafter).
$carrier_case = $WS . '/carrier-only-case.php';
file_put_contents(
    $carrier_case,
    "<?php\nerror_reporting(0);\ndefine('ABSPATH', " . var_export(ABSPATH, true) . ");\n"
    . "function wp_parse_url(string \$url, int \$c = -1) { return parse_url(\$url, \$c); }\n"
    . "define('HAL_FRONTEND_DASHBOARD_VERSION', '1.0.0');\n"
    . "require " . var_export($PROJECT . '/includes/class-update-bridge.php', true) . ";\n"
    . '$offer = ' . var_export($offer, true) . ";\n"
    . "exit(true === HAL_Frontend_Dashboard_Update_Bridge::filter_auto_update(false, \$offer) ? 0 : 1);\n"
);
exec('php ' . escapeshellarg($carrier_case), $carrier_out, $carrier_code);
b11c_check(
    'B11C-AUTO-CARRIER-ONLY',
    0 === $carrier_code,
    'carrier-only install (no MU runtime constant) still enforces a valid higher offer',
    'subprocess exit: ' . $carrier_code
);
if (!defined('HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION')) {
    define('HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION', '1.0.0');
}
b11c_check(
    'B11C-AUTO-RUNTIME',
    true === HAL_Frontend_Dashboard_Update_Bridge::filter_auto_update(false, $offer),
    'MU runtime version alone (Carrier constant absent) enforces a valid higher offer',
    'got: ' . var_export(HAL_Frontend_Dashboard_Update_Bridge::filter_auto_update(false, $offer), true)
);
$low_offer = $offer;
$low_offer['new_version'] = '1.0.0';
$low_offer['package'] = 'https://github.com/hossamadellaw/hal-frontend-dashboard/releases/download/v1.0.0/hal-frontend-dashboard-1.0.0.zip';
b11c_check(
    'B11C-AUTO-NOT-HIGHER',
    false === HAL_Frontend_Dashboard_Update_Bridge::filter_auto_update(false, $low_offer),
    'a non-higher version passes through untouched',
    'non-higher offer enforced'
);
$bad_offer = $offer;
$bad_offer['package'] = 'https://github.com/other/repo/releases/download/v2.0.0/hal-frontend-dashboard-2.0.0.zip';
b11c_check(
    'B11C-AUTO-FOREIGN-PACKAGE',
    false === HAL_Frontend_Dashboard_Update_Bridge::filter_auto_update(false, $bad_offer),
    'a foreign-repo package passes through untouched',
    'foreign package enforced'
);

/* ════════════════ CHAIN: connected update via the real import path ════════════════ */

if (!defined('WP_PLUGIN_DIR')) { define('WP_PLUGIN_DIR', $WS . '/wp-plugins'); }
if (!defined('WPMU_PLUGIN_DIR')) { define('WPMU_PLUGIN_DIR', $WS . '/mu-plugins'); }
@mkdir(WP_PLUGIN_DIR, 0777, true);
@mkdir(WPMU_PLUGIN_DIR, 0777, true);

function b11c_build_runtime_tree(string $dir, string $version): array {
    @mkdir($dir, 0777, true);
    file_put_contents($dir . '/bootstrap.php', "<?php\ndefined( 'ABSPATH' ) || exit;\n// runtime $version\n");
    file_put_contents($dir . '/core-module.php', "<?php\ndefined( 'ABSPATH' ) || exit;\n// core $version\n");
    $files = array(
        'bootstrap.php' => hash_file('sha256', $dir . '/bootstrap.php'),
        'core-module.php' => hash_file('sha256', $dir . '/core-module.php'),
    );
    ksort($files, SORT_STRING);
    return $files;
}

function b11c_zip_tree(string $src_dir, array $files, string $zip_path): void {
    $zip = new ZipArchive();
    $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach (array_keys($files) as $rel) {
        $zip->addFile($src_dir . '/' . $rel, $rel);
    }
    $zip->close();
}

function b11c_build_carrier(string $carrier_root, string $version, string $rt_zip, string $rt_raw, string $rt_sig): array {
    @mkdir($carrier_root . '/includes', 0777, true);
    @mkdir($carrier_root . '/mu-loader', 0777, true);
    @mkdir($carrier_root . '/payload', 0777, true);
    $main = "<?php\n/**\n * Plugin Name: HAL Frontend Dashboard\n * Version: $version\n */\ndefined( 'ABSPATH' ) || exit;\ndefine( 'HAL_FRONTEND_DASHBOARD_VERSION', '$version' );\n";
    file_put_contents($carrier_root . '/hal-frontend-dashboard.php', $main);
    file_put_contents($carrier_root . '/readme.txt', "=== HAL ===\nStable tag: $version\n");
    file_put_contents($carrier_root . '/LICENSE', "GPL-2.0-or-later\n");
    file_put_contents($carrier_root . '/THIRD-PARTY-NOTICES.txt', "notices\n");
    file_put_contents($carrier_root . '/includes/class-installer.php', "<?php\ndefined( 'ABSPATH' ) || exit;\n");
    file_put_contents($carrier_root . '/mu-loader/hal-frontend-dashboard.php', "<?php\ndefined( 'ABSPATH' ) || exit;\n");
    // Loader bytes mirror the real shipped file so the install-or-verify
    // gate compares equal content across successive imports in one MU home.
    copy($GLOBALS['B11C_PROJECT'] . '/mu-loader/loader-core.php', $carrier_root . '/mu-loader/loader-core.php');
    copy($rt_zip, $carrier_root . '/payload/runtime-' . $version . '.zip');
    file_put_contents($carrier_root . '/payload/runtime-manifest.json', $rt_raw);
    file_put_contents($carrier_root . '/payload/runtime-manifest.sig', $rt_sig);
    $map = array();
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($carrier_root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $item) {
        if ($item->isDir()) { continue; }
        $rel = str_replace('\\', '/', substr($item->getPathname(), strlen($carrier_root) + 1));
        $map[$rel] = hash_file('sha256', $item->getPathname());
    }
    ksort($map, SORT_STRING);
    $manifest = array(
        'schema' => 1, 'product' => 'hal-frontend-dashboard', 'version' => $version,
        'plugin_basename' => 'hal-frontend-dashboard/hal-frontend-dashboard.php', 'files' => $map,
    );
    list($carrier_raw, $carrier_sig) = b11c_sign($manifest);
    file_put_contents($carrier_root . '/carrier-manifest.json', $carrier_raw);
    file_put_contents($carrier_root . '/carrier-manifest.sig', $carrier_sig);
    return $manifest;
}

$chain_mu = $WS . '/mu-chain';
$chain_plugins = WP_PLUGIN_DIR . '/hal-frontend-dashboard';
b11c_rmdir($chain_mu);
b11c_rmdir($chain_plugins);
@mkdir($chain_mu, 0777, true);

// Old release v1 via the REAL manager (stub health, R10 precedent).
$mgr = new HAL_Frontend_Dashboard_Release_Manager($chain_mu);
$mgr->ensure_layout();
$v1 = '3.0.0+' . str_repeat('a', 40);
$v1_dir = $chain_mu . '/hal-frontend-dashboard/releases/' . $v1;
@mkdir($v1_dir, 0777, true);
file_put_contents($v1_dir . '/bootstrap.php', "<?php\n// v1\n");
$mgr->install_loader('loader-3.0.0', $PROJECT . '/mu-loader/loader-core.php');
$mgr->promote_runtime(
    array('release_id' => $v1, 'version' => '3.0.0', 'release_sequence' => 10, 'archive_sha256' => str_repeat('1', 64)),
    static fn(): bool => true,
    'loader-3.0.0'
);

// Candidate v2 carrier (test-signed), recorded through the REAL bridge action.
$v2sha = str_repeat('b', 40);
$rt_src = $WS . '/rt-v2-src';
b11c_rmdir($rt_src);
$rt_files = b11c_build_runtime_tree($rt_src, '3.1.0');
$rt_zip = $WS . '/runtime-3.1.0.zip';
@unlink($rt_zip);
b11c_zip_tree($rt_src, $rt_files, $rt_zip);
$v2manifest = b11c_runtime_manifest('3.1.0', $v2sha, 11, hash_file('sha256', $rt_zip), $rt_files);
list($rt_raw, $rt_sig) = b11c_sign($v2manifest);
b11c_build_carrier($chain_plugins, '3.1.0', $rt_zip, $rt_raw, $rt_sig);

HAL_Frontend_Dashboard_Update_Bridge::action_upgrader_complete(
    new stdClass(),
    array('type' => 'plugin', 'action' => 'update', 'plugin' => 'hal-frontend-dashboard/hal-frontend-dashboard.php')
);
$recorded_option = get_option(HAL_Frontend_Dashboard_Update_Bridge::IMPORT_OPTION);
$cron_key = HAL_Frontend_Dashboard_Update_Bridge::IMPORT_CRON_HOOK . '|' . json_encode(array());
b11c_check(
    'B11C-CHAIN-RECORDED',
    is_array($recorded_option) && isset($GLOBALS['B11C_SCHEDULED'][$cron_key]),
    'the real bridge action recorded the candidate + scheduled the import',
    'record/cron missing'
);

// Consume through the REAL cron entry point with the REAL verifier (test
// key) and stub health (loopback boundary, R10 precedent).
$chain_mgr = new HAL_Frontend_Dashboard_Release_Manager($chain_mu);
$imported = $chain_mgr->import_candidate(static fn(): bool => true);
$end_active = $chain_mgr->read_pointer('active.json');
$end_previous = $chain_mgr->read_pointer('previous.json');
b11c_check(
    'B11C-CHAIN-PROMOTED',
    '3.1.0+' . $v2sha === $imported
        && '3.1.0+' . $v2sha === ($end_active['release_id'] ?? null)
        && $v1 === ($end_previous['release_id'] ?? null)
        && false === get_option(HAL_Frontend_Dashboard_Update_Bridge::IMPORT_OPTION, false),
    'connected import promoted v1→v2 through the real path and consumed the candidate',
    'imported=' . var_export($imported, true) . ' active=' . json_encode($end_active)
);

// Tampered payload via the REAL path: active unchanged, diagnosed failure.
file_put_contents($chain_plugins . '/payload/runtime-3.1.0.zip', "\0TAMPERED");
update_option(
    HAL_Frontend_Dashboard_Update_Bridge::IMPORT_OPTION,
    array('plugin' => 'hal-frontend-dashboard/hal-frontend-dashboard.php', 'time' => time()),
    false
);
$tamper_code = b11c_code(static function () use ($chain_mu): void {
    (new HAL_Frontend_Dashboard_Release_Manager($chain_mu))->import_candidate(static fn(): bool => true);
});
$tamper_active = (new HAL_Frontend_Dashboard_Release_Manager($chain_mu))->read_pointer('active.json');
b11c_check(
    'B11C-CHAIN-TAMPER-REJECTED',
    'HAL_PACKAGE_TREE_HASH_MISMATCH' === $tamper_code && '3.1.0+' . str_repeat('b', 40) === ($tamper_active['release_id'] ?? null),
    'tampered payload rejected on the real path (tree hash gate, before staging) with active unchanged (I4 equivalent)',
    'code=' . $tamper_code . ' active=' . json_encode($tamper_active)
);

// Failed health via the REAL path rolls back to previous (R10 equivalent).
$chain_mu2 = $WS . '/mu-chain-rb';
b11c_rmdir($chain_mu2);
@mkdir($chain_mu2, 0777, true);
$rb = new HAL_Frontend_Dashboard_Release_Manager($chain_mu2);
$rb->ensure_layout();
$rb_dir = $chain_mu2 . '/hal-frontend-dashboard/releases/' . $v1;
@mkdir($rb_dir, 0777, true);
file_put_contents($rb_dir . '/bootstrap.php', "<?php\n// v1\n");
$rb->install_loader('loader-3.0.0', $PROJECT . '/mu-loader/loader-core.php');
$rb->promote_runtime(
    array('release_id' => $v1, 'version' => '3.0.0', 'release_sequence' => 10, 'archive_sha256' => str_repeat('1', 64)),
    static fn(): bool => true,
    'loader-3.0.0'
);
$chain_plugins2 = $WS . '/wp-plugins2/hal-frontend-dashboard';
b11c_rmdir($WS . '/wp-plugins2');
$rt_src2 = $WS . '/rt-v2b-src';
b11c_rmdir($rt_src2);
$rt_files2 = b11c_build_runtime_tree($rt_src2, '3.1.0');
$rt_zip2 = $WS . '/runtime-3.1.0b.zip';
@unlink($rt_zip2);
b11c_zip_tree($rt_src2, $rt_files2, $rt_zip2);
$v2manifest2 = b11c_runtime_manifest('3.1.0', $v2sha, 11, hash_file('sha256', $rt_zip2), $rt_files2, '3.0.0');
list($rt_raw2, $rt_sig2) = b11c_sign($v2manifest2);
b11c_build_carrier($chain_plugins2, '3.1.0', $rt_zip2, $rt_raw2, $rt_sig2);
// Point WP_PLUGIN_DIR at the second candidate tree for this run.
// WP_PLUGIN_DIR is fixed; copy the v2 carrier over the shared candidate path.
b11c_rmdir($chain_plugins);
@mkdir($chain_plugins, 0777, true);
$copy_it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($chain_plugins2, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($copy_it as $item) {
    $dest = $chain_plugins . '/' . $copy_it->getSubPathName();
    if ($item->isDir()) { @mkdir($dest, 0777, true); }
    else { copy($item->getPathname(), $dest); }
}
$rb_code = b11c_code(static function () use ($chain_mu2): void {
    update_option(
        HAL_Frontend_Dashboard_Update_Bridge::IMPORT_OPTION,
        array('plugin' => 'hal-frontend-dashboard/hal-frontend-dashboard.php', 'time' => time()),
        false
    );
    (new HAL_Frontend_Dashboard_Release_Manager($chain_mu2))->import_candidate(static fn(): bool => false);
});
$rb_active = (new HAL_Frontend_Dashboard_Release_Manager($chain_mu2))->read_pointer('active.json');
$rb_state = json_decode((string) @file_get_contents($chain_mu2 . '/hal-frontend-dashboard/state/manager-state.json'), true);
b11c_check(
    'B11C-CHAIN-ROLLBACK',
    'HAL_PROMOTION_HEALTH_FAILED' === $rb_code && $v1 === ($rb_active['release_id'] ?? null)
        && 'rolled_back' === ($rb_state['state'] ?? null),
    'failed health on the real path rolls back to previous with a diagnosed status',
    'code=' . $rb_code . ' active=' . json_encode($rb_active)
);

/* ════════════════ GATES: preflight + lock ownership (item 1) ════════════════ */

// DISALLOW_FILE_MODS in a FRESH subprocess (constant is process-global):
// blocked status, zero writes, candidate kept.
$dis_snippet = <<<'PHP'
<?php
error_reporting(0);
define('ABSPATH', %ABSPATH%);
define('WPMU_PLUGIN_DIR', %MU%);
define('WP_PLUGIN_DIR', %PLUGINS%);
define('DISALLOW_FILE_MODS', true);
$GLOBALS['D_OPTIONS'] = array(
    'hal_frontend_dashboard_update_candidate' => array('plugin' => 'hal-frontend-dashboard/hal-frontend-dashboard.php', 'time' => 1),
);
$GLOBALS['D_STATE'] = '';
function get_option(string $k, $d = false) { return array_key_exists($k, $GLOBALS['D_OPTIONS']) ? $GLOBALS['D_OPTIONS'][$k] : $d; }
function update_option(string $k, $v, $a = null): bool { $GLOBALS['D_OPTIONS'][$k] = $v; return true; }
function delete_option(string $k): bool { unset($GLOBALS['D_OPTIONS'][$k]); return true; }
function add_action(string $h, $cb = '', int $p = 10, int $a = 1): bool { return true; }
function add_filter(string $h, $cb = '', int $p = 10, int $a = 1): bool { return true; }
require %MANAGER%;
$GLOBALS['D_MU'] = %MU%;
$mgr = new HAL_Frontend_Dashboard_Release_Manager(%MU%);
HAL_Frontend_Dashboard_Release_Manager::import_candidate_cron();
$home = %MU% . '/hal-frontend-dashboard';
$state = @file_get_contents($home . '/state/manager-state.json');
$rel_dir = $home . '/releases';
$releases = (is_dir($rel_dir) && count(scandir($rel_dir)) > 2) ? 1 : 0;
$kept = array_key_exists('hal_frontend_dashboard_update_candidate', $GLOBALS['D_OPTIONS']) ? 1 : 0;
echo 'STATE=' . (string) $state . ' RELEASES=' . ($releases ? 1 : 0) . ' KEPT=' . $kept;
PHP;
$dis_file = $WS . '/disallow-case.php';
file_put_contents(
    $dis_file,
    str_replace(
        array('%ABSPATH%', '%MU%', '%PLUGINS%', '%MANAGER%'),
        array(
            var_export($WS . '/fake-wp/', true),
            var_export($WS . '/mu-disallow/', true),
            var_export($WS . '/wp-plugins/', true),
            var_export($PROJECT . '/includes/class-release-manager.php', true),
        ),
        $dis_snippet
    )
);
exec('php ' . escapeshellarg($dis_file), $dis_out, $dis_code);
$dis_line = implode('', $dis_out);
b11c_check(
    'B11C-GATE-DISALLOW',
    0 === $dis_code && false !== strpos($dis_line, '"state":"blocked"')
        && false !== strpos($dis_line, 'HAL_IMPORT_FILE_MODS_DISABLED')
        && false !== strpos($dis_line, 'RELEASES=0') && false !== strpos($dis_line, 'KEPT=1'),
    'DISALLOW_FILE_MODS records blocked with zero writes and keeps the candidate',
    'exit=' . $dis_code . ' out=' . $dis_line
);

// Lock busy in-process: hold the OS lock, run the REAL cron entry point.
$busy_mu = $WS . '/mu-busy';
b11c_rmdir($busy_mu);
@mkdir($busy_mu, 0777, true);
$busy_carrier = $WS . '/wp-plugins-busy/hal-frontend-dashboard';
b11c_rmdir($WS . '/wp-plugins-busy');
$busy_rt = $WS . '/rt-busy-src';
b11c_rmdir($busy_rt);
$busy_files = b11c_build_runtime_tree($busy_rt, '4.0.0');
$busy_zip = $WS . '/runtime-4.0.0.zip';
@unlink($busy_zip);
b11c_zip_tree($busy_rt, $busy_files, $busy_zip);
$busy_manifest = b11c_runtime_manifest('4.0.0', str_repeat('f', 40), 20, hash_file('sha256', $busy_zip), $busy_files);
list($busy_raw, $busy_sig) = b11c_sign($busy_manifest);
b11c_build_carrier($busy_carrier, '4.0.0', $busy_zip, $busy_raw, $busy_sig);
// Temporarily point the shared candidate path at the valid busy carrier.
b11c_rmdir($WS . '/wp-plugins/hal-frontend-dashboard');
@mkdir($WS . '/wp-plugins/hal-frontend-dashboard', 0777, true);
$busy_copy = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($busy_carrier, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($busy_copy as $item) {
    $dest = $WS . '/wp-plugins/hal-frontend-dashboard/' . $busy_copy->getSubPathName();
    if ($item->isDir()) { @mkdir($dest, 0777, true); }
    else { @mkdir(dirname($dest), 0777, true); copy($item->getPathname(), $dest); }
}
// NOTE: WP_PLUGIN_DIR is fixed for this process; emulate the busy MU home
// by running the cron entry against a manager bound to $busy_mu is not
// possible (cron binds WPMU_PLUGIN_DIR). Instead the busy lock is held on
// the DEFAULT home and the candidate is valid there: use a dedicated
// default-home run via the same technique as the cron wrapper.
$busy_mgr_home = new HAL_Frontend_Dashboard_Release_Manager($WS . '/mu-plugins');
$busy_mgr_home->ensure_layout();
$busy_lock = fopen($WS . '/mu-plugins/hal-frontend-dashboard/state/update.lock', 'c+b');
$busy_held = is_resource($busy_lock) && flock($busy_lock, LOCK_EX | LOCK_NB);
update_option(
    HAL_Frontend_Dashboard_Update_Bridge::IMPORT_OPTION,
    array('plugin' => 'hal-frontend-dashboard/hal-frontend-dashboard.php', 'time' => time()),
    false
);
if ($busy_held) {
    // cargo-carrier for the default home: version must exceed committed
    // (none here — first import) so the run reaches the install lock.
    HAL_Frontend_Dashboard_Release_Manager::import_candidate_cron();
    flock($busy_lock, LOCK_UN);
    fclose($busy_lock);
}
$busy_state = json_decode((string) @file_get_contents($WS . '/mu-plugins/hal-frontend-dashboard/state/manager-state.json'), true);
$busy_releases = is_dir($WS . '/mu-plugins/hal-frontend-dashboard/releases/4.0.0+' . str_repeat('f', 40));
b11c_check(
    'B11C-GATE-LOCK-BUSY',
    $busy_held && 'blocked' === ($busy_state['state'] ?? null)
        && 'HAL_UPDATE_LOCK_BUSY' === ($busy_state['failure_code'] ?? null)
        && false === $busy_releases,
    'a busy update lock records blocked with zero installs through the real cron entry',
    'held=' . var_export($busy_held, true) . ' state=' . json_encode($busy_state)
);

/* ════════════════ MIRROR: shared sources byte-identical ════════════════ */

b11c_check(
    'B11C-MIRROR-BRIDGE',
    hash_file('sha256', $PROJECT . '/includes/class-update-bridge.php') === hash_file('sha256', $PROJECT . '/runtime/infrastructure/class-update-bridge.php'),
    'runtime/infrastructure bridge copy is byte-identical to includes/ source',
    'mirror drift detected'
);
b11c_check(
    'B11C-MIRROR-MANAGER',
    hash_file('sha256', $PROJECT . '/includes/class-release-manager.php') === hash_file('sha256', $PROJECT . '/runtime/infrastructure/class-release-manager.php'),
    'runtime/infrastructure manager copy is byte-identical to includes/ source',
    'mirror drift detected'
);

/* ════════════════ PKG: REAL product-tree package, verified file by file ════════════════ */

$pkg = $WS . '/pkg';
b11c_rmdir($pkg);
$rt_stage = $pkg . '/rt-stage';
@mkdir($rt_stage . '/infrastructure', 0777, true);
// Real runtime tree minus vendor/ (absent locally: one-time composer
// install still unauthorised — documented deviation D1 below).
$copy_src = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($PROJECT . '/runtime', FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($copy_src as $item) {
    $rel = substr($item->getPathname(), strlen($PROJECT . '/runtime') + 1);
    if ('vendor' === $rel || str_starts_with($rel, 'vendor/')) { continue; }
    $dest = $rt_stage . '/' . $rel;
    if ($item->isDir()) { @mkdir($dest, 0777, true); }
    else { copy($item->getPathname(), $dest); }
}
foreach (array('class-package-verifier.php', 'class-release-manager.php', 'class-update-bridge.php', 'class-health-check.php', 'class-template-controller.php') as $shared) {
    copy($PROJECT . '/includes/' . $shared, $rt_stage . '/infrastructure/' . $shared);
}
$pkg_files = array();
$pkg_it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rt_stage, FilesystemIterator::SKIP_DOTS));
foreach ($pkg_it as $item) {
    if ($item->isDir() || $item->isLink()) { continue; }
    $rel = str_replace('\\', '/', substr($item->getPathname(), strlen($rt_stage) + 1));
    $pkg_files[$rel] = hash_file('sha256', $item->getPathname());
}
ksort($pkg_files, SORT_STRING);
$pkg_version = '9.9.9';
$pkg_sha = str_repeat('d', 40);
$pkg_rt_zip = $pkg . '/runtime-' . $pkg_version . '.zip';
@unlink($pkg_rt_zip);
$zip = new ZipArchive();
$zip->open($pkg_rt_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
foreach (array_keys($pkg_files) as $rel) {
    $zip->addFile($rt_stage . '/' . $rel, $rel);
}
$zip->close();
$pkg_manifest = b11c_runtime_manifest($pkg_version, $pkg_sha, 99, hash_file('sha256', $pkg_rt_zip), $pkg_files);
list($pkg_rt_raw, $pkg_rt_sig) = b11c_sign($pkg_manifest);

$carrier_root = $pkg . '/carrier/hal-frontend-dashboard';
b11c_rmdir($pkg . '/carrier');
b11c_build_carrier($carrier_root, $pkg_version, $pkg_rt_zip, $pkg_rt_raw, $pkg_rt_sig);
// Replace the fixture carrier members with the REAL product files, then
// re-freeze the carrier manifest over the real bytes.
foreach (array('hal-frontend-dashboard.php', 'readme.txt', 'LICENSE', 'THIRD-PARTY-NOTICES.txt') as $top) {
    copy($PROJECT . '/' . $top, $carrier_root . '/' . $top);
}
foreach (glob($PROJECT . '/includes/*.php') as $src) {
    copy($src, $carrier_root . '/includes/' . basename($src));
}
foreach (glob($PROJECT . '/mu-loader/*.php') as $src) {
    copy($src, $carrier_root . '/mu-loader/' . basename($src));
}
$real_map = array();
$real_it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($carrier_root, FilesystemIterator::SKIP_DOTS));
foreach ($real_it as $item) {
    if ($item->isDir()) { continue; }
    $rel = str_replace('\\', '/', substr($item->getPathname(), strlen($carrier_root) + 1));
    $real_map[$rel] = hash_file('sha256', $item->getPathname());
}
ksort($real_map, SORT_STRING);
$real_carrier_manifest = array(
    'schema' => 1, 'product' => 'hal-frontend-dashboard', 'version' => $pkg_version,
    'plugin_basename' => 'hal-frontend-dashboard/hal-frontend-dashboard.php', 'files' => $real_map,
);
// Workflow contract (§8.5): the carrier manifest lists every shipped file
// EXCEPT itself and its own signature (their integrity rides the Ed25519
// signature, not the hash list — the archive verifier reads both as
// special entries outside the inventory comparison).
unset($real_carrier_manifest['files']['carrier-manifest.json'], $real_carrier_manifest['files']['carrier-manifest.sig']);
list($real_carrier_raw, $real_carrier_sig) = b11c_sign($real_carrier_manifest);
file_put_contents($carrier_root . '/carrier-manifest.json', $real_carrier_raw);
file_put_contents($carrier_root . '/carrier-manifest.sig', $real_carrier_sig);
// Recompute over the real bytes, then re-freeze + re-sign: the manifest
// lists its own two files, so the first pass can only carry fixture hashes.
$real_map = array();
$real_it2 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($carrier_root, FilesystemIterator::SKIP_DOTS));
foreach ($real_it2 as $item) {
    if ($item->isDir()) { continue; }
    $rel = str_replace('\\', '/', substr($item->getPathname(), strlen($carrier_root) + 1));
    $real_map[$rel] = hash_file('sha256', $item->getPathname());
}
ksort($real_map, SORT_STRING);
$real_carrier_manifest['files'] = $real_map;
unset($real_carrier_manifest['files']['carrier-manifest.json'], $real_carrier_manifest['files']['carrier-manifest.sig']);
list($real_carrier_raw, $real_carrier_sig) = b11c_sign($real_carrier_manifest);
file_put_contents($carrier_root . '/carrier-manifest.json', $real_carrier_raw);
file_put_contents($carrier_root . '/carrier-manifest.sig', $real_carrier_sig);
$real_carrier_zip = $pkg . '/hal-frontend-dashboard-' . $pkg_version . '.zip';
@unlink($real_carrier_zip);
$zip = new ZipArchive();
$zip->open($real_carrier_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
foreach (array_keys($real_map) as $rel) {
    $zip->addFile($carrier_root . '/' . $rel, 'hal-frontend-dashboard/' . $rel);
}
$zip->close();

$pver = new HAL_Frontend_Dashboard_Package_Verifier(
    HAL_FRONTEND_DASHBOARD_TEST_PUBLIC_KEY,
    HAL_FRONTEND_DASHBOARD_TEST_KEY_FINGERPRINT
);
$carrier_code = b11c_code(static function () use ($pver, $real_carrier_zip): void {
    $pver->verify_carrier_archive($real_carrier_zip);
});
$payload_runtimes = glob($carrier_root . '/payload/runtime-*.zip');
$runtime_code = b11c_code(static function () use ($pver, $carrier_root, $payload_runtimes): void {
    $pver->verify_runtime_archive(
        $payload_runtimes[0],
        $carrier_root . '/payload/runtime-manifest.json',
        $carrier_root . '/payload/runtime-manifest.sig'
    );
});
b11c_check(
    'B11C-PKG-REAL-VERIFY',
    '' === $carrier_code && '' === $runtime_code && count($pkg_files) > 50,
    'real product-tree Carrier verified (carrier+runtime manifests, test-key signatures, ' . count($pkg_files) . ' runtime files, ' . count($real_map) . ' carrier files)',
    'carrier-code=' . $carrier_code . ' runtime-code=' . $runtime_code . ' runtime-files=' . count($pkg_files)
);

// Every file inside the REAL package inspected: single root, bans, guards.
$zip = new ZipArchive();
$zip->open($real_carrier_zip);
$inside = array();
for ($i = 0; $i < $zip->numFiles; $i++) { $inside[] = $zip->getNameIndex($i); }
$zip->close();
sort($inside, SORT_STRING);
$roots = array();
$banned_hits = array();
foreach ($inside as $entry) {
    $seg = explode('/', $entry)[0];
    if ('' !== $seg && '.' !== $seg) { $roots[$seg] = true; }
    foreach (array('docs/', 'tests/', '.github/', '.git/', 'node_modules/', '.env', '.log', 'logo.png', 'vendor/') as $banned) {
        if (false !== strpos(strtolower($entry), $banned)) { $banned_hits[] = $entry . ' ~ ' . $banned; }
    }
}
$unguarded = array();
foreach (array_keys($real_map) as $rel) {
    if (!str_ends_with($rel, '.php') || str_starts_with($rel, 'payload/')) { continue; }
    $src = (string) file_get_contents($carrier_root . '/' . $rel);
    // Comment text (headers mentioning require/include/class) is not code.
    $code = preg_replace('~/\*[\s\S]*?\*/~', ' ', (string) $src);
    $code = preg_replace('~(^|[^:])//[^\n]*~m', '$1', (string) $code);
    $guard = strpos($code, "defined( 'ABSPATH' )");
    if (false === $guard) { $guard = strpos($code, 'defined("ABSPATH")'); }
    $code_at = array();
    foreach (array('class ', 'function ', 'add_action', 'add_filter', 'require', 'include', 'new ') as $token) {
        $pos = strpos($code, $token);
        if (false !== $pos) { $code_at[] = $pos; }
    }
    if (false === $guard || (array() !== $code_at && $guard > min($code_at))) {
        $unguarded[] = $rel;
    }
}
b11c_check(
    'B11C-PKG-INSPECTION',
    array_keys($roots) === array('hal-frontend-dashboard') && array() === $banned_hits && array() === $unguarded,
    'real package: single root, zero banned paths, every Carrier PHP carries the ABSPATH guard (' . count($inside) . ' entries inspected)',
    'roots=' . json_encode(array_keys($roots)) . ' banned=' . json_encode($banned_hits) . ' unguarded=' . json_encode($unguarded)
);
echo "INFO_PKG deviation D1: runtime/vendor/ excluded (absent locally; one-time composer install unauthorised) — every other product file is real bytes.\n";

/* ════════════════ ZIP: limits and hostile entries (REAL verifier) ════════════════ */

function b11c_zip_case(string $dir, array $files, string $zip_path): array {
    @mkdir($dir, 0777, true);
    $map = array();
    foreach ($files as $rel => $content) {
        $full = $dir . '/' . $rel;
        @mkdir(dirname($full), 0777, true);
        file_put_contents($full, $content);
        $map[$rel] = hash_file('sha256', $full);
    }
    ksort($map, SORT_STRING);
    $zip = new ZipArchive();
    $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach (array_keys($map) as $rel) {
        $zip->addFile($dir . '/' . $rel, $rel);
    }
    $zip->close();
    return $map;
}

function b11c_rt_case_manifest(string $version, string $zip_path, array $files): array {
    $m = b11c_runtime_manifest($version, str_repeat('e', 40), 50, hash_file('sha256', $zip_path), $files);
    return b11c_sign($m);
}

$zip_ws = $WS . '/zip-cases';
b11c_rmdir($zip_ws);
@mkdir($zip_ws, 0777, true);
$zver = new HAL_Frontend_Dashboard_Package_Verifier(
    HAL_FRONTEND_DASHBOARD_TEST_PUBLIC_KEY,
    HAL_FRONTEND_DASHBOARD_TEST_KEY_FINGERPRINT
);

// Count limit: 4097 entries (+bootstrap manifest gate needs bootstrap.php).
$many = array('bootstrap.php' => "<?php\n");
for ($i = 0; $i < 4097; $i++) { $many[sprintf('f-%04d.txt', $i)] = 'x'; }
$many_map = b11c_zip_case($zip_ws . '/many-src', $many, $zip_ws . '/many.zip');
list($many_raw, $many_sig) = b11c_rt_case_manifest('8.0.0', $zip_ws . '/many.zip', $many_map);
file_put_contents($zip_ws . '/many.json', $many_raw);
file_put_contents($zip_ws . '/many.sig', $many_sig);
$count_code = b11c_code(static function () use ($zver, $zip_ws): void {
    $zver->verify_runtime_archive($zip_ws . '/many.zip', $zip_ws . '/many.json', $zip_ws . '/many.sig');
});
b11c_check(
    'B11C-ZIP-COUNT-LIMIT',
    'HAL_RUNTIME_ARCHIVE_ENTRY_LIMIT' === $count_code,
    '4097 entries rejected with HAL_RUNTIME_ARCHIVE_ENTRY_LIMIT',
    'code: ' . $count_code
);

// Compressed-size limit: 130MB stored (STORE = no compression).
$big_src = $zip_ws . '/big-src';
@mkdir($big_src, 0777, true);
$chunk = str_repeat('0', 1048576);
$fh = fopen($big_src . '/big.bin', 'wb');
for ($i = 0; $i < 130; $i++) { fwrite($fh, $chunk); }
fclose($fh);
$big_map = array(
    'bootstrap.php' => hash('sha256', "<?php\n"),
    'big.bin' => hash_file('sha256', $big_src . '/big.bin'),
);
file_put_contents($big_src . '/bootstrap.php', "<?php\n");
$big_zip = $zip_ws . '/big.zip';
$zip = new ZipArchive();
$zip->open($big_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFile($big_src . '/bootstrap.php', 'bootstrap.php');
$zip->addFile($big_src . '/big.bin', 'big.bin');
$zip->setCompressionName('big.bin', ZipArchive::CM_STORE);
$zip->close();
list($big_raw, $big_sig) = b11c_rt_case_manifest('8.0.1', $big_zip, $big_map);
file_put_contents($zip_ws . '/big.json', $big_raw);
file_put_contents($zip_ws . '/big.sig', $big_sig);
$size_code = b11c_code(static function () use ($zver, $zip_ws): void {
    $zver->verify_runtime_archive($zip_ws . '/big.zip', $zip_ws . '/big.json', $zip_ws . '/big.sig');
});
b11c_check(
    'B11C-ZIP-SIZE-LIMIT',
    'HAL_RUNTIME_ARCHIVE_SIZE_LIMIT' === $size_code,
    '130MB stored payload rejected with HAL_RUNTIME_ARCHIVE_SIZE_LIMIT',
    'code: ' . $size_code
);

// Compression-ratio limit: 2MB of zeros (deflated ≈ 2KB, ratio ≈ 1000).
$ratio_map = b11c_zip_case($zip_ws . '/ratio-src', array('bootstrap.php' => "<?php\n", 'zeros.bin' => str_repeat('0', 2097152)), $zip_ws . '/ratio.zip');
list($ratio_raw, $ratio_sig) = b11c_rt_case_manifest('8.0.2', $zip_ws . '/ratio.zip', $ratio_map);
file_put_contents($zip_ws . '/ratio.json', $ratio_raw);
file_put_contents($zip_ws . '/ratio.sig', $ratio_sig);
$ratio_code = b11c_code(static function () use ($zver, $zip_ws): void {
    $zver->verify_runtime_archive($zip_ws . '/ratio.zip', $zip_ws . '/ratio.json', $zip_ws . '/ratio.sig');
});
b11c_check(
    'B11C-ZIP-RATIO-LIMIT',
    'HAL_RUNTIME_ARCHIVE_COMPRESSION_RATIO_LIMIT' === $ratio_code,
    '2MB zeros (ratio ≈1000) rejected with HAL_RUNTIME_ARCHIVE_COMPRESSION_RATIO_LIMIT',
    'code: ' . $ratio_code
);

// Traversal + drive-prefix entries rejected.
$trav_zip = $zip_ws . '/trav.zip';
$zip = new ZipArchive();
$zip->open($trav_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('ok.txt', 'ok');
$zip->addFromString('../evil.php', 'evil');
$zip->close();
$trav_map = array(
    'bootstrap.php' => hash('sha256', "<?php\n"),
    'ok.txt' => hash('sha256', 'ok'),
    '../evil.php' => hash('sha256', 'evil'),
    'C:/evil.php' => hash('sha256', 'evil'),
);
$zip = new ZipArchive();
$zip->open($trav_zip);
$zip->addFromString('bootstrap.php', "<?php\n");
$zip->close();
list($trav_raw, $trav_sig) = b11c_rt_case_manifest('8.0.3', $trav_zip, $trav_map);
file_put_contents($zip_ws . '/trav.json', $trav_raw);
file_put_contents($zip_ws . '/trav.sig', $trav_sig);
$trav_code = b11c_code(static function () use ($zver, $zip_ws): void {
    $zver->verify_runtime_archive($zip_ws . '/trav.zip', $zip_ws . '/trav.json', $zip_ws . '/trav.sig');
});
b11c_check(
    'B11C-ZIP-TRAVERSAL',
    'HAL_PACKAGE_PATH_INVALID' === $trav_code,
    'traversal (../) paths rejected with HAL_PACKAGE_PATH_INVALID',
    'code: ' . $trav_code
);
// Drive-prefix branch of the same gate, exercised independently (the case
// above fails first on ../evil, so C:/evil never reaches the gate there).
$drv_map = array(
    'bootstrap.php' => hash('sha256', "<?php\n"),
    'ok.txt' => hash('sha256', 'ok'),
    'C:/evil.php' => hash('sha256', 'evil'),
);
$drv_zip = $zip_ws . '/drv.zip';
$zip = new ZipArchive();
$zip->open($drv_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('bootstrap.php', "<?php\n");
$zip->addFromString('ok.txt', 'ok');
$zip->close();
list($drv_raw, $drv_sig) = b11c_rt_case_manifest('8.0.5', $drv_zip, $drv_map);
file_put_contents($zip_ws . '/drv.json', $drv_raw);
file_put_contents($zip_ws . '/drv.sig', $drv_sig);
$drv_code = b11c_code(static function () use ($zver, $zip_ws): void {
    $zver->verify_runtime_archive($zip_ws . '/drv.zip', $zip_ws . '/drv.json', $zip_ws . '/drv.sig');
});
b11c_check(
    'B11C-ZIP-DRIVE-PREFIX',
    'HAL_PACKAGE_PATH_INVALID' === $drv_code,
    'drive-prefix (C:/) paths rejected with HAL_PACKAGE_PATH_INVALID',
    'code: ' . $drv_code
);

// Symlink entry (central-directory external attributes → S_IFLNK).
$link_zip = $zip_ws . '/linkentry.zip';
$zip = new ZipArchive();
$zip->open($link_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('link.php', 'target');
$zip->close();
$link_patch_ok = false;
$link_code = '';
$raw_zip = (string) file_get_contents($link_zip);
$eocd = strrpos($raw_zip, "PK\x05\x06");
if (false !== $eocd) {
    $cd_offset = unpack('V', substr($raw_zip, $eocd + 16, 4))[1];
    $cd_sig = substr($raw_zip, $cd_offset, 4);
    if ("PK\x01\x02" === $cd_sig) {
        // external file attributes at central-header offset 38.
        $patched = substr_replace($raw_zip, pack('V', 0xA0000000 | 0644), $cd_offset + 38, 4);
        file_put_contents($link_zip, $patched);
        $link_patch_ok = true;
    }
}
if ($link_patch_ok) {
    $link_map = array(
        'bootstrap.php' => hash('sha256', "<?php\n"),
        'link.php' => hash('sha256', 'target'),
    );
    // bootstrap.php must exist in the archive for the manifest gate.
    $zip = new ZipArchive();
    $zip->open($link_zip);
    $zip->addFromString('bootstrap.php', "<?php\n");
    $zip->close();
    list($link_raw, $link_sig) = b11c_rt_case_manifest('8.0.4', $link_zip, $link_map);
    file_put_contents($zip_ws . '/link.json', $link_raw);
    file_put_contents($zip_ws . '/link.sig', $link_sig);
    $link_code = b11c_code(static function () use ($zver, $zip_ws): void {
        $zver->verify_runtime_archive($zip_ws . '/linkentry.zip', $zip_ws . '/link.json', $zip_ws . '/link.sig');
    });
    // Patched sizes stay consistent, so CHECKCONS open must survive; the
    // attributes check is the gate under test.
    b11c_check(
        'B11C-ZIP-SYMLINK-ENTRY',
        'HAL_RUNTIME_ARCHIVE_LINK_ENTRY' === $link_code,
        'symlink-attribute entry rejected with HAL_RUNTIME_ARCHIVE_LINK_ENTRY',
        'code: ' . $link_code
    );
} else {
    echo "SKIP [B11C-ZIP-SYMLINK-ENTRY] central-directory patch point not located\n";
}

// Real tree symlink (P16 gap): attempt, prove rejection or environment cause.
$link_tree = $WS . '/link-tree-target';
$link_path = $WS . '/link-tree-link';
b11c_rmdir($link_tree);
@unlink($link_path);
@mkdir($link_tree, 0777, true);
file_put_contents($link_tree . '/bootstrap.php', "<?php\n");
$linked = @symlink($link_tree, $link_path);
if (false === $linked) {
    echo "SKIP [B11C-TREE-SYMLINK] symlink not creatable in this environment (privilege)\n";
} else {
    $tree_code = b11c_code(static function () use ($zver, $link_path): void {
        $rm = new ReflectionMethod($zver, 'verify_tree_inventory');
        $rm->setAccessible(true);
        $rm->invoke($zver, $link_path, array('bootstrap.php' => hash_file('sha256', $link_path . '/bootstrap.php')), array(), 'runtime');
    });
    b11c_check(
        'B11C-TREE-SYMLINK',
        'HAL_PACKAGE_TREE_ROOT_INVALID' === $tree_code || 'HAL_PACKAGE_TREE_LINK_REJECTED' === $tree_code,
        'symlinked tree root rejected (' . $tree_code . ')',
        'code: ' . $tree_code
    );
    @unlink($link_path);
}

/* ════════════════ PUC: REAL locked v5.7 (cache ref 275a96a, else locked vendor) ════════════════ */

$puc_cache = 'C:/Users/kanli/AppData/Local/Composer/files/yahnis-elsts/plugin-update-checker/c2ec7b4449eab6f6e947928001f2c4143ed5450c.zip';
$puc_stage = $WS . '/puc-v5.7';
$puc_top = '';
$puc_root = '';
$puc_from = '';
if (is_file($puc_cache)) {
    $zip = new ZipArchive();
    if (true === $zip->open($puc_cache)) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ('' === $puc_top && false !== strpos($name, '/')) {
                $puc_top = substr($name, 0, strpos($name, '/') + 1);
            }
            if (false === strpos($name, '/load-v5p7.php') && 'load-v5p7.php' !== basename($name)) {
                continue;
            }
        }
        $zip->extractTo($WS . '/puc-extract');
        $zip->close();
    }
    $puc_root = $WS . '/puc-extract/' . $puc_top;
    $puc_from = 'cache';
}
if ('' === $puc_root || !is_file($puc_root . 'load-v5p7.php')) {
    // CI/normal: composer-installed locked copy (same composer.lock bytes
    // the release build job installs). The locked git ref is proven from
    // vendor/composer/installed.json instead of a zip top-dir name.
    $puc_vendor_load = $PROJECT . '/vendor/yahnis-elsts/plugin-update-checker/load-v5p7.php';
    if (is_file($puc_vendor_load)) {
        $puc_root = dirname($puc_vendor_load) . '/';
        $puc_from = 'vendor';
        $puc_top = '';
    }
}
if ('' === $puc_root || !is_file($puc_root . 'load-v5p7.php')) {
    foreach (array('B11C-PUC-LOCKED-REF', 'B11C-PUC-REAL-CONSTANTS', 'B11C-PUC-REAL-REGEX-WIRED', 'B11C-PUC-REAL-ASSET-MATRIX', 'B11C-PUC-REAL-STRATEGIES') as $skipped_id) {
        b11c_skip($skipped_id, 'no composer cache zip and no locked vendor copy — REAL locked v5.7 bytes unavailable, deferred to runs with one of those sources');
    }
} else {
if ('vendor' === $puc_from) {
    $installed = json_decode((string) @file_get_contents($PROJECT . '/vendor/composer/installed.json'), true);
    // Composer 1 (bare list) and Composer 2 (packages key) shapes; the
    // reference may live top-level or nested under source/dist.
    $installed_pkgs = is_array($installed) && isset($installed[0]) ? $installed : ($installed['packages'] ?? array());
    $puc_ref = '';
    foreach ($installed_pkgs as $pkg) {
        if (!is_array($pkg) || 'yahnis-elsts/plugin-update-checker' !== ($pkg['name'] ?? '')) { continue; }
        $puc_ref = (string) ($pkg['reference'] ?? $pkg['source']['reference'] ?? $pkg['dist']['reference'] ?? '');
        break;
    }
    b11c_check(
        'B11C-PUC-LOCKED-REF',
        0 === strpos($puc_ref, '275a96a'),
        'locked vendor copy records git ref 275a96a (v5.7) in installed.json',
        'installed.json reference: ' . $puc_ref
    );
} else {
    b11c_check(
        'B11C-PUC-LOCKED-REF',
        '' !== $puc_top && 0 === strpos($puc_top, 'YahnisElsts-plugin-update-checker-275a96a'),
        'staged PUC top dir matches the locked git ref 275a96a (v5.7)',
        'top: ' . $puc_top
    );
}
require $puc_root . 'load-v5p7.php';
$api_class = 'YahnisElsts\\PluginUpdateChecker\\v5p7\\Vcs\\GitHubApi';
b11c_check(
    'B11C-PUC-REAL-CONSTANTS',
    defined($api_class . '::STRATEGY_LATEST_RELEASE')
        && defined($api_class . '::STRATEGY_LATEST_TAG')
        && constant($api_class . '::STRATEGY_LATEST_RELEASE') !== constant($api_class . '::STRATEGY_LATEST_TAG'),
    'real locked v5.7 declares distinct latest-release/tag/branch strategies',
    'constants missing'
);
if (!function_exists('wp_parse_url')) {
    // Already stubbed above; GitHubApi constructor needs it only.
}
$real_api = new $api_class('https://github.com/hossamadellaw/hal-frontend-dashboard');
$real_api->enableReleaseAssets(HAL_Frontend_Dashboard_Update_Bridge::ASSET_REGEX);
$rp = new ReflectionProperty($real_api, 'assetFilterRegex');
$rp->setAccessible(true);
b11c_check(
    'B11C-PUC-REAL-REGEX-WIRED',
    HAL_Frontend_Dashboard_Update_Bridge::ASSET_REGEX === $rp->getValue($real_api),
    'the real locked API accepted the bridge asset regex',
    'regex not stored'
);
$exposer = new class('https://github.com/hossamadellaw/hal-frontend-dashboard') extends YahnisElsts\PluginUpdateChecker\v5p7\Vcs\GitHubApi {
    public function matches(string $asset_name): bool {
        $asset = new stdClass();
        $asset->name = $asset_name;
        return $this->matchesAssetFilter($asset);
    }
};
$exposer->enableReleaseAssets(HAL_Frontend_Dashboard_Update_Bridge::ASSET_REGEX);
$asset_matrix = array(
    array('hal-frontend-dashboard-1.2.3.zip', true, 'exact carrier asset'),
    array('hal-frontend-dashboard-10.20.30.zip', true, 'multi-digit carrier asset'),
    array('hal-frontend-dashboard-01.2.3.zip', false, 'leading zeros rejected'),
    array('other-plugin-1.2.3.zip', false, 'foreign product rejected'),
    array('hal-frontend-dashboard-1.2.3.tar.gz', false, 'source archive rejected'),
    array('HAL-FRONTEND-DASHBOARD-1.2.3.ZIP', false, 'case-sensitive rejection'),
    array('v1.2.3.zip', false, 'tag-shaped name rejected'),
    array('hal-frontend-dashboard-1.2.zip', false, 'two-part version rejected'),
);
$asset_bad = array();
foreach ($asset_matrix as $row) {
    if ($exposer->matches($row[0]) !== $row[1]) { $asset_bad[] = $row[0] . ' (' . $row[2] . ')'; }
}
b11c_check(
    'B11C-PUC-REAL-ASSET-MATRIX',
    array() === $asset_bad,
    'real locked matchesAssetFilter accepts only exact carrier assets (8/8)',
    'mismatches: ' . json_encode($asset_bad)
);
$latest = constant($api_class . '::STRATEGY_LATEST_RELEASE');
$three = array(
    constant($api_class . '::STRATEGY_LATEST_TAG') => 'tag-strategy',
    $latest => 'release-strategy',
    constant($api_class . '::STRATEGY_BRANCH') => 'branch-strategy',
);
b11c_check(
    'B11C-PUC-REAL-STRATEGIES',
    array($latest => 'release-strategy') === HAL_Frontend_Dashboard_Update_Bridge::filter_update_detection_strategies($three)
        && array() === HAL_Frontend_Dashboard_Update_Bridge::filter_update_detection_strategies(array('x' => 'y')),
    'real locked strategy map collapses to latest-release only; missing yields no update (no tag/branch fallback)',
    'strategy restriction broken against real constants'
);
} // end PUC available (else: the five PUC checks above were skipped)

/* ════════════════ SIGNER: the ACTUAL workflow command (item 2) ════════════════ */

$workflow = (string) file_get_contents($PROJECT . '/.github/workflows/release.yml');
// CRLF checkout normalization, in memory only: the workflow file itself is
// untouched, and the extraction assertions below are unchanged. Without it,
// `$`-anchored and `\n`-literal patterns miss on Windows checkouts.
$workflow = str_replace("\r\n", "\n", $workflow);
$workflow = str_replace("\r", "\n", $workflow);
// YAML literal blocks dedent by the common indent: a `PHP` terminator at
// the block indent becomes column 0 in the executed script, which is what
// bash `<<'PHP'` requires. Prove that shape on the dedented text.
$signer_blocks = array();
foreach (array('runtime-manifest.sig', 'carrier-manifest.sig') as $sig_name) {
    $pattern = '/^( +)php \/dev\/stdin "\$KEY_FILE" ' . preg_quote($sig_name, '/') . ' <<\'PHP\'\n((?:.*\n)*?)^\1PHP$/m';
    if (1 === preg_match($pattern, $workflow, $mm)) { $signer_blocks[$sig_name] = $mm; }
}
b11c_check(
    'B11C-SIGNER-SHAPE',
    2 === count($signer_blocks),
    'both signer blocks invoke php on /dev/stdin with key+sig args and a dedent-exact lone PHP terminator (a bare file arg would execute the key, not the script)',
    'signer invocation shape broken'
);
// Extract each signer body and execute it with real argv: throwaway key,
// real manifest bytes → detached sigs verifying on the raw bytes.
$signer_bodies = array();
foreach ($signer_blocks as $sig_name => $mm) {
    // Dedent the captured body by the block indent; the executed script
    // then carries the lone terminator at column 0 exactly like CI.
    $indent = $mm[1];
    $raw_lines = explode("\n", $mm[0]);
    $body_lines = array();
    foreach (array_slice($raw_lines, 1, -1) as $line) {
        $body_lines[] = str_starts_with($line, $indent) ? substr($line, strlen($indent)) : $line;
    }
    $signer_bodies[$sig_name] = implode("\n", $body_lines);
}
b11c_check(
    'B11C-SIGNER-EXTRACT',
    2 === count($signer_bodies),
    'both signer bodies extract from the workflow source',
    'extracted: ' . count($signer_bodies)
);
$signer_ok = array();
if (2 === count($signer_bodies)) {
    $sign_wd = $WS . '/signer-run';
    b11c_rmdir($sign_wd);
    @mkdir($sign_wd, 0777, true);
    $manifest_bytes = '{"schema":1,"product":"hal-frontend-dashboard","version":"9.9.9"}' . "\n";
    file_put_contents($sign_wd . '/runtime-manifest.json', $manifest_bytes);
    file_put_contents($sign_wd . '/carrier-manifest.json', $manifest_bytes);
    $keypair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($keypair);
    $pub = sodium_crypto_sign_publickey($keypair);
    file_put_contents($sign_wd . '/hal-key', base64_encode($secret));
    foreach ($signer_bodies as $sig_name => $body) {
        $body_file = $sign_wd . '/signer-' . md5($sig_name) . '.php';
        file_put_contents($body_file, $body . "\n");
        $manifest_file = ('runtime-manifest.sig' === $sig_name) ? 'runtime-manifest.json' : 'carrier-manifest.json';
        // Same argv contract as the workflow: $argv[1]=key file, $argv[2]=sig out.
        // Extension flags are filtered (kept only when the child runtime
        // lacks them), so runners with built-in sodium stay warning-free.
        $cmd = implode( ' ', array_map( 'escapeshellarg', array_merge( hal_php_argv( 'php', array(), array( 'sodium' ) ), array( $body_file, $sign_wd . '/hal-key', $sig_name ) ) ) );
        $prev_cwd = getcwd();
        chdir($sign_wd);
        exec($cmd, $sign_out, $sign_code);
        chdir($prev_cwd);
        $sig_raw = @file_get_contents($sign_wd . '/' . $sig_name);
        $sig_bin = is_string($sig_raw) ? base64_decode(trim($sig_raw), true) : false;
        $signer_ok[$sig_name] = 0 === $sign_code && is_string($sig_bin)
            && sodium_crypto_sign_verify_detached($sig_bin, $manifest_bytes, $pub);
    }
}
b11c_check(
    'B11C-SIGNER-EXECUTE',
    array('runtime-manifest.sig' => true, 'carrier-manifest.sig' => true) === $signer_ok,
    'both real signer bodies produce detached Ed25519 sigs verifying on the raw manifest bytes',
    'results: ' . json_encode($signer_ok)
);

/* ════════════════ GATE-PROOF: the ACTUAL package gate (item 4) ════════════════ */

// Extract the staged-gate php program from the workflow and run it against
// fixture trees with HAL_RUNTIME/HAL_CARRIER pointed at them.
$gate_program = '';
if (1 === preg_match('/staged package lint, licenses, path classification[\s\S]*?php -r \'\n(.*?)\n          \'/s', $workflow, $gm)) {
    $gate_program = $gm[1];
}
b11c_check(
    'B11C-GATE-EXTRACT',
    '' !== $gate_program,
    'the staged-gate program extracts from the workflow source',
    'extraction failed'
);
$gate_runner = static function (string $program, string $rt, string $carrier) use ($WS): array {
    $file = $WS . '/gate-prog-' . md5($rt . $carrier) . '.php';
    file_put_contents($file, "<?php\n" . $program);
    $prev = array(getenv('HAL_RUNTIME'), getenv('HAL_CARRIER'));
    putenv('HAL_RUNTIME=' . $rt);
    putenv('HAL_CARRIER=' . $carrier);
    // Shell redirection is unreliable for capture here; use pipes directly.
    $proc = proc_open(
        'php ' . escapeshellarg($file),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes
    );
    $out = '';
    $code = 1;
    if (is_resource($proc)) {
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $out = $stdout . "\n" . $stderr;
    }
    if (false === $prev[0]) { putenv('HAL_RUNTIME'); } else { putenv('HAL_RUNTIME=' . $prev[0]); }
    if (false === $prev[1]) { putenv('HAL_CARRIER'); } else { putenv('HAL_CARRIER=' . $prev[1]); }
    return array($code, $out);
};
// Positive fixture: valid staged runtime + carrier incl. extensionless LICENSE.
$gate_ok_rt = $WS . '/gate-ok-rt';
$gate_ok_car = $WS . '/gate-ok-carrier';
b11c_rmdir($gate_ok_rt);
b11c_rmdir($gate_ok_car);
@mkdir($gate_ok_rt . '/core', 0777, true);
file_put_contents($gate_ok_rt . '/bootstrap.php', "<?php\n");
file_put_contents($gate_ok_rt . '/core/a.php', "<?php\n");
@mkdir($gate_ok_rt . '/vendor/yahnis-elsts/plugin-update-checker', 0777, true);
$cache_zip = new ZipArchive();
if (is_file($puc_cache) && true === $cache_zip->open($puc_cache)) {
    $cache_top = '';
    for ($gi = 0; $gi < $cache_zip->numFiles; $gi++) {
        $gn = $cache_zip->getNameIndex($gi);
        if ('' === $cache_top && false !== strpos($gn, '/')) { $cache_top = substr($gn, 0, strpos($gn, '/') + 1); }
    }
    foreach (array('composer.json', 'license.txt') as $vf) {
        $bytes = $cache_zip->getFromName($cache_top . $vf);
        if (is_string($bytes)) { file_put_contents($gate_ok_rt . '/vendor/yahnis-elsts/plugin-update-checker/' . $vf, $bytes); }
    }
    $cache_zip->close();
} else {
    // CI/normal: seed the fake vendor tree from the composer-installed
    // locked copy (same lock the release build job installs).
    $seed_pkg = $PROJECT . '/vendor/yahnis-elsts/plugin-update-checker';
    if (is_file($seed_pkg . '/composer.json')) {
        copy($seed_pkg . '/composer.json', $gate_ok_rt . '/vendor/yahnis-elsts/plugin-update-checker/composer.json');
    }
    foreach ((array) @scandir($seed_pkg) as $entry) {
        if (is_string($entry) && 1 === preg_match('/^(LICENSE|LICENCE|COPYING|NOTICE)/i', $entry)
            && is_file($seed_pkg . '/' . $entry)) {
            copy($seed_pkg . '/' . $entry, $gate_ok_rt . '/vendor/yahnis-elsts/plugin-update-checker/' . $entry);
        }
    }
}
@mkdir($gate_ok_car . '/includes', 0777, true);
@mkdir($gate_ok_car . '/mu-loader', 0777, true);
@mkdir($gate_ok_car . '/payload', 0777, true);
file_put_contents($gate_ok_car . '/hal-frontend-dashboard.php', "<?php\n");
file_put_contents($gate_ok_car . '/LICENSE', "GPL\n");
file_put_contents($gate_ok_car . '/includes/x.php', "<?php\n");
file_put_contents($gate_ok_car . '/mu-loader/y.php', "<?php\n");
file_put_contents($gate_ok_car . '/payload/runtime-1.0.0.zip', 'zip');
if ('' !== $gate_program) {
    [$gate_code, $gate_out] = $gate_runner($gate_program, $gate_ok_rt, $gate_ok_car);
    b11c_check(
        'B11C-GATE-ACCEPT',
        0 === $gate_code,
        'the real gate accepts a valid staged tree (incl. extensionless LICENSE)',
        'exit=' . $gate_code . ' out=' . substr($gate_out, 0, 300)
    );
    // Negative fixtures, each run in isolation.
    $neg_base_rt = $WS . '/gate-neg-rt';
    $neg_base_car = $WS . '/gate-neg-car';
    $neg_cases = array(
        'unclassified-dir' => array('rt_extra' => array('evil/x.php' => "<?php\n"), 'car_extra' => array(), 'want' => 'runtime_path_unclassified'),
        'bad-ext' => array('rt_extra' => array('core/notes.md' => 'x'), 'car_extra' => array(), 'want' => 'runtime_extension_unclassified'),
        'carrier-unclassified-dir' => array('rt_extra' => array(), 'car_extra' => array('extra/x.php' => "<?php\n"), 'want' => 'carrier_path_unclassified'),
        'carrier-bad-ext' => array('rt_extra' => array(), 'car_extra' => array('readme.md' => 'x'), 'want' => 'carrier_extension_unclassified'),
    );
    foreach ($neg_cases as $case_id => $case) {
        $rt_dir = $neg_base_rt . '-' . $case_id;
        $car_dir = $neg_base_car . '-' . $case_id;
        b11c_rmdir($rt_dir);
        b11c_rmdir($car_dir);
        $copy_it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($gate_ok_rt, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($copy_it as $item) {
            $dest = $rt_dir . '/' . $copy_it->getSubPathName();
            if ($item->isDir()) { @mkdir($dest, 0777, true); }
            else { @mkdir(dirname($dest), 0777, true); copy($item->getPathname(), $dest); }
        }
        $copy_it2 = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($gate_ok_car, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($copy_it2 as $item) {
            $dest = $car_dir . '/' . $copy_it2->getSubPathName();
            if ($item->isDir()) { @mkdir($dest, 0777, true); }
            else { @mkdir(dirname($dest), 0777, true); copy($item->getPathname(), $dest); }
        }
        foreach ((array) $case['rt_extra'] as $rel => $content) {
            @mkdir(dirname($rt_dir . '/' . $rel), 0777, true);
            file_put_contents($rt_dir . '/' . $rel, $content);
        }
        foreach ((array) $case['car_extra'] as $rel => $content) {
            @mkdir(dirname($car_dir . '/' . $rel), 0777, true);
            file_put_contents($car_dir . '/' . $rel, $content);
        }
        [$neg_code, $neg_out] = $gate_runner($gate_program, $rt_dir, $car_dir);
        b11c_check(
            'B11C-GATE-REJECT-' . $case_id,
            0 !== $neg_code && false !== strpos($neg_out, $case['want']),
            'the real gate rejects ' . $case_id . ' with ' . $case['want'],
            'exit=' . $neg_code . ' out=' . substr($neg_out, 0, 200)
        );
    }
    // Vendor without license text rejected.
    $nolic_rt = $WS . '/gate-neg-nolic-rt';
    b11c_rmdir($nolic_rt);
    @mkdir($nolic_rt . '/vendor/yahnis-elsts/plugin-update-checker', 0777, true);
    file_put_contents($nolic_rt . '/bootstrap.php', "<?php\n");
    file_put_contents($nolic_rt . '/vendor/yahnis-elsts/plugin-update-checker/composer.json', '{}');
    [$nolic_code, $nolic_out] = $gate_runner($gate_program, $nolic_rt, $gate_ok_car);
    b11c_check(
        'B11C-GATE-REJECT-NO-LICENSE',
        0 !== $nolic_code && false !== strpos($nolic_out, 'vendor_license_text_missing'),
        'the real gate rejects a vendor package without license text',
        'exit=' . $nolic_code . ' out=' . substr($nolic_out, 0, 200)
    );
}

/* ════════════════ PUC-UPDATE: no-asset → no update (item 7) ════════════════ */

$bridge_source = (string) file_get_contents($PROJECT . '/includes/class-update-bridge.php');
b11c_check(
    'B11C-PUC-REQUIRE-WIRED',
    false !== strpos($bridge_source, 'REQUIRE_RELEASE_ASSETS')
        && false !== strpos($bridge_source, 'puc_require_unsupported'),
    'the bridge wires REQUIRE_RELEASE_ASSETS (update-system §4) and fails closed without it',
    'REQUIRE wiring missing in bridge source'
);
$puc_vcs_api = 'YahnisElsts\\PluginUpdateChecker\\v5p7\\Vcs\\Api';
$puc_release = static function (array $assets): stdClass {
    $release = new stdClass();
    $release->tag_name = 'v2.0.0';
    $release->zipball_url = 'https://api.github.com/repos/x/y/zipball/v2.0.0';
    $release->created_at = '2026-01-01T00:00:00Z';
    $release->body = '';
    $release->assets = array();
    foreach ($assets as $name) {
        $asset = new stdClass();
        $asset->name = $name;
        $asset->url = 'https://api.github.com/repos/x/y/releases/assets/1';
        $asset->browser_download_url = 'https://github.com/hossamadellaw/hal-frontend-dashboard/releases/download/v2.0.0/' . $name;
        $asset->download_count = 3;
        $release->assets[] = $asset;
    }
    return $release;
};
$puc_fixture_api = new class('https://github.com/hossamadellaw/hal-frontend-dashboard') extends YahnisElsts\PluginUpdateChecker\v5p7\Vcs\GitHubApi {
    public static $canned = null;
    public function api($url, $httpFilter = null) {
        return self::$canned;
    }
};
// Exact bridge wiring, executed for real on the real locked object.
$puc_fixture_api->enableReleaseAssets(
    HAL_Frontend_Dashboard_Update_Bridge::ASSET_REGEX,
    constant($puc_vcs_api . '::REQUIRE_RELEASE_ASSETS')
);
$no_asset_class = get_class($puc_fixture_api);
$puc_fixture_api::$canned = $puc_release(array('some-other-1.2.3.zip'));
$no_asset_ref = $puc_fixture_api->getLatestRelease();
$puc_fixture_api::$canned = $puc_release(array('hal-frontend-dashboard-2.0.0.zip'));
$asset_ref = $puc_fixture_api->getLatestRelease();
b11c_check(
    'B11C-PUC-NO-ASSET-NO-UPDATE',
    null === $no_asset_ref,
    'a latest release without a matching asset yields no Reference (no update, no source-zipball fallback) under the wired REQUIRE',
    'got: ' . json_encode($no_asset_ref)
);
b11c_check(
    'B11C-PUC-ASSET-PACKAGE-URL',
    is_object($asset_ref) && 'https://github.com/hossamadellaw/hal-frontend-dashboard/releases/download/v2.0.0/hal-frontend-dashboard-2.0.0.zip' === ($asset_ref->downloadUrl ?? null),
    'the matching asset yields the exact package URL through the real locked selection path',
    'got: ' . json_encode($asset_ref)
);
// Contrast: the pre-fix PREFER wiring would have offered the source zipball.
$puc_prefer_api = new $no_asset_class('https://github.com/hossamadellaw/hal-frontend-dashboard');
$puc_prefer_api->enableReleaseAssets(HAL_Frontend_Dashboard_Update_Bridge::ASSET_REGEX);
$no_asset_class::$canned = $puc_release(array('some-other-1.2.3.zip'));
$prefer_ref = $puc_prefer_api->getLatestRelease();
b11c_check(
    'B11C-PUC-PREFER-FALLBACK-DOCUMENTED',
    is_object($prefer_ref) && false !== strpos((string) ($prefer_ref->downloadUrl ?? ''), 'zipball'),
    'with PREFER the same release would offer the source zipball — documenting why REQUIRE is load-bearing',
    'got: ' . json_encode($prefer_ref)
);

/* ════════════════ HEALTH-CHAIN: real Boot Health + real load path (item 6) ═══
 * The candidate boots through the REAL anchor → REAL loader-core → release
 * bootstrap chain in a subprocess (only WP boundary stubbed); the REAL
 * Health_Check::check() loopback is forwarded to it and answered by the
 * REAL handle_request()/consume(). Fixture release contents fire (or skip)
 * the readiness contract surface; product-tree boot itself is proven by
 * bootstrap-core + PKG. No static true/false health callbacks here.
 */

if (!function_exists('wp_remote_post')) {
    function wp_remote_post($url, $args) {
        $handoff = (string) getenv('HAL_BOOT_HANDOFF');
        $payload = array(
            'op' => (string) ($args['body']['operation'] ?? ''),
            'token' => (string) ($args['body']['token'] ?? ''),
        );
        if ('' === $handoff || !file_put_contents($handoff, json_encode($payload))) {
            return array('code' => 500, 'body' => '');
        }
        $driver = (string) getenv('HAL_BOOT_DRIVER');
        exec('php ' . escapeshellarg($driver), $out, $code);
        $body = implode("\n", $out);
        return array('code' => 0 === $code && '' !== $body ? 200 : 500, 'body' => $body);
    }
}
if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string {
        return 'https://example.test/wp-admin/' . $path;
    }
}
if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($r) { return $r['code']; }
}
if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($r) { return $r['body']; }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($t): bool { return $t instanceof WP_Error; }
}
if (!class_exists('WP_Error')) {
    class WP_Error {
        public function __construct($c = '', $m = '') {}
    }
}

$health_driver_tpl = <<<'PHP'
<?php
error_reporting(0);
$mu = getenv('HAL_BOOT_MU');
define('ABSPATH', $mu . 'wp-includes/');
define('WPMU_PLUGIN_DIR', $mu);
$GLOBALS['wp_version'] = '7.1';
$GLOBALS['H_HOOKS'] = array();
$GLOBALS['H_DONE'] = array();
function add_action($h, $c, $p = 10, $a = 1) { $GLOBALS['H_HOOKS'][$h][] = $c; return true; }
function add_filter($h, $c, $p = 10, $a = 1) { $GLOBALS['H_HOOKS'][$h][] = $c; return true; }
function do_action($h, ...$a) { $GLOBALS['H_DONE'][$h] = ($GLOBALS['H_DONE'][$h] ?? 0) + 1; foreach (($GLOBALS['H_HOOKS'][$h] ?? array()) as $cb) { $cb(...$a); } }
function apply_filters($h, $v, ...$r) { foreach (($GLOBALS['H_HOOKS'][$h] ?? array()) as $cb) { $v = $cb($v, ...$r); } return $v; }
function did_action($h) { return $GLOBALS['H_DONE'][$h] ?? 0; }
function __return_true() { return true; }
function __return_false() { return false; }
function content_url($p = '') { return 'https://example.test/wp-content/' . $p; }
function wp_unslash($v) { return $v; }
function wp_send_json($d) { echo json_encode($d); exit(0); }
function wp_send_json_error($d = null, $s = 403) { echo json_encode(array('success' => false)); exit(1); }
$handoff = json_decode((string) file_get_contents(getenv('HAL_BOOT_HANDOFF')), true);
$_POST['token'] = (string) ($handoff['token'] ?? '');
$_POST['operation'] = (string) ($handoff['op'] ?? '');
// Real load path: anchor → loader-core → active release bootstrap.
require $mu . 'hal-frontend-dashboard.php';
// Real acknowledgement path against the booted candidate.
require %HEALTH%;
HAL_Frontend_Dashboard_Health_Check::handle_request();
PHP;

$health_run = static function (string $mu_home, string $carrier_version, bool $ready) use ($WS, $PROJECT, $health_driver_tpl): array {
    // Fresh MU home with v1 active+committed (scaffolding via stub health).
    b11c_rmdir($mu_home);
    @mkdir($mu_home, 0777, true);
    $setup = new HAL_Frontend_Dashboard_Release_Manager($mu_home);
    $setup->ensure_layout();
    $v1 = '5.0.0+' . str_repeat('a', 40);
    $v1_dir = $mu_home . '/hal-frontend-dashboard/releases/' . $v1;
    @mkdir($v1_dir, 0777, true);
    file_put_contents($v1_dir . '/bootstrap.php', "<?php\n// v1\n");
    $setup->install_loader('loader-1.0.0', $PROJECT . '/mu-loader/loader-core.php');
    $setup->promote_runtime(
        array('release_id' => $v1, 'version' => '5.0.0', 'release_sequence' => 5, 'archive_sha256' => str_repeat('5', 64)),
        static fn(): bool => true,
        'loader-1.0.0'
    );
    // Miniature MU home: REAL anchor + REAL loader-core copies.
    copy($PROJECT . '/mu-loader/hal-frontend-dashboard.php', $mu_home . '/hal-frontend-dashboard.php');
    // Candidate carrier with a fixture release whose bootstrap fires (or
    // skips) the readiness contract surface.
    $csha = str_repeat('c', 40);
    $csrc = $WS . '/rt-health-src';
    b11c_rmdir($csrc);
    @mkdir($csrc, 0777, true);
    $boot_code = $ready
        ? "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\ndo_action( 'hal_frontend_dashboard_runtime_ready' );\nadd_filter( 'hal_frontend_dashboard_boot_readiness', '__return_true' );\n"
        : "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\n// readiness never fires: boot health must fail\n";
    file_put_contents($csrc . '/bootstrap.php', $boot_code);
    $cfiles = array('bootstrap.php' => hash_file('sha256', $csrc . '/bootstrap.php'));
    $czip = $WS . '/runtime-health.zip';
    @unlink($czip);
    b11c_zip_tree($csrc, $cfiles, $czip);
    $cmanifest = b11c_runtime_manifest($carrier_version, $csha, 6, hash_file('sha256', $czip), $cfiles, '1.0.0');
    list($craw, $csig) = b11c_sign($cmanifest);
    $ccarrier = $WS . '/wp-plugins/hal-frontend-dashboard';
    b11c_rmdir($ccarrier);
    b11c_build_carrier($ccarrier, $carrier_version, $czip, $craw, $csig);
    // Driver answering loopbacks by really booting the candidate.
    $driver = $WS . '/health-driver.php';
    file_put_contents(
        $driver,
        str_replace('%HEALTH%', var_export($PROJECT . '/includes/class-health-check.php', true), $health_driver_tpl)
    );
    putenv('HAL_BOOT_MU=' . $mu_home . '/');
    putenv('HAL_BOOT_DRIVER=' . $driver);
    putenv('HAL_BOOT_HANDOFF=' . $WS . '/handoff.json');
    update_option(
        HAL_Frontend_Dashboard_Update_Bridge::IMPORT_OPTION,
        array('plugin' => 'hal-frontend-dashboard/hal-frontend-dashboard.php', 'time' => time()),
        false
    );
    HAL_Frontend_Dashboard_Release_Manager::import_candidate_cron();
    putenv('HAL_BOOT_MU');
    putenv('HAL_BOOT_DRIVER');
    putenv('HAL_BOOT_HANDOFF');
    $probe = new HAL_Frontend_Dashboard_Release_Manager($mu_home);
    return array(
        'active' => $probe->read_pointer('active.json'),
        'previous' => $probe->read_pointer('previous.json'),
        'state' => json_decode((string) @file_get_contents($mu_home . '/hal-frontend-dashboard/state/manager-state.json'), true),
    );
};

// NOTE: cron binds WPMU_PLUGIN_DIR, fixed for this process to the shared
// path — the health runs below temporarily relocate it via subprocess env
// only for the loopback child; the MU homes below ARE the shared default
// home prepared per run. First: point the shared candidate path away.
$health_mu = $WS . '/mu-plugins';
$ok = $health_run($health_mu, '5.1.0', true);
b11c_check(
    'B11C-HEALTH-CHAIN-SUCCESS',
    '5.1.0+' . str_repeat('c', 40) === ($ok['active']['release_id'] ?? null)
        && '5.0.0+' . str_repeat('a', 40) === ($ok['previous']['release_id'] ?? null)
        && 'active' === ($ok['state']['state'] ?? null),
    'candidate boots for real, real loopback ack accepted, import commits with previous kept',
    'active=' . json_encode($ok['active']) . ' state=' . json_encode($ok['state'])
);
$rb = $health_run($health_mu, '5.2.0', false);
b11c_check(
    'B11C-HEALTH-CHAIN-ROLLBACK',
    '5.0.0+' . str_repeat('a', 40) === ($rb['active']['release_id'] ?? null)
        && 'rolled_back' === ($rb['state']['state'] ?? null),
    'unready candidate gets no ack, real health fails, automatic rollback to previous',
    'active=' . json_encode($rb['active']) . ' state=' . json_encode($rb['state'])
);

/* ════════════════ VENDOR: full vendor-inclusive package (decision 2) ═════════
 * Runs only when the one-time offline composer staging exists
 * (.local-execution/composer-stage/vendor, built with
 * COMPOSER_DISABLE_NETWORK=1 from the lock, product tree untouched);
 * otherwise SKIP with the D1 reference. Proves the complete runtime
 * payload — product files + locked vendor + generated autoloader —
 * verifies end-to-end with the real verifier and test-key signatures.
 */

$vstage = $PROJECT . '/.local-execution/composer-stage/vendor';
if (!is_dir($vstage)) {
    echo "SKIP [B11C-VENDOR-FULL] offline composer staging absent (D1: one-time install unauthorised)\n";
} else {
    $vlock = json_decode((string) file_get_contents($PROJECT . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
    $vlock_names = array();
    foreach (($vlock['packages'] ?? array()) as $p) { $vlock_names[$p['name']] = $p['version']; }
    $vinstalled = json_decode((string) file_get_contents($vstage . '/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
    $vinstalled_names = array();
    foreach (($vinstalled['packages'] ?? $vinstalled) as $p) {
        if (is_array($p) && isset($p['name'])) { $vinstalled_names[$p['name']] = $p['version']; }
    }
    ksort($vlock_names);
    ksort($vinstalled_names);
    b11c_check(
        'B11C-VENDOR-LOCK-MATCH',
        $vlock_names === $vinstalled_names,
        'staged vendor matches the locked non-dev set exactly (' . implode(',', array_keys($vlock_names)) . ')',
        'lock=' . json_encode($vlock_names) . ' staged=' . json_encode($vinstalled_names)
    );
    $vrt = $WS . '/rt-vendor-stage';
    b11c_rmdir($vrt);
    @mkdir($vrt . '/infrastructure', 0777, true);
    $vcopy = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($PROJECT . '/runtime', FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($vcopy as $item) {
        $rel = substr($item->getPathname(), strlen($PROJECT . '/runtime') + 1);
        if ('vendor' === $rel || str_starts_with($rel, 'vendor/')) { continue; }
        $dest = $vrt . '/' . $rel;
        if ($item->isDir()) { @mkdir($dest, 0777, true); }
        else { copy($item->getPathname(), $dest); }
    }
    foreach (array('class-package-verifier.php', 'class-release-manager.php', 'class-update-bridge.php', 'class-health-check.php', 'class-template-controller.php') as $shared) {
        copy($PROJECT . '/includes/' . $shared, $vrt . '/infrastructure/' . $shared);
    }
    $vcopy2 = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($vstage, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($vcopy2 as $item) {
        $rel = 'vendor/' . substr($item->getPathname(), strlen($vstage) + 1);
        $dest = $vrt . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if ($item->isDir()) { @mkdir($dest, 0777, true); }
        else { @mkdir(dirname($dest), 0777, true); copy($item->getPathname(), $dest); }
    }
    $vfiles = array();
    $vit = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($vrt, FilesystemIterator::SKIP_DOTS));
    foreach ($vit as $item) {
        if ($item->isDir() || $item->isLink()) { continue; }
        $rel = str_replace('\\', '/', substr($item->getPathname(), strlen($vrt) + 1));
        $vfiles[$rel] = hash_file('sha256', $item->getPathname());
    }
    ksort($vfiles, SORT_STRING);
    $vzip = $WS . '/runtime-vendor.zip';
    @unlink($vzip);
    $zip = new ZipArchive();
    $zip->open($vzip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach (array_keys($vfiles) as $rel) {
        $zip->addFile($vrt . '/' . $rel, $rel);
    }
    $zip->close();
    $vmanifest = b11c_runtime_manifest('9.9.8', str_repeat('e', 40), 98, hash_file('sha256', $vzip), $vfiles);
    list($vraw, $vsig) = b11c_sign($vmanifest);
    file_put_contents($WS . '/runtime-vendor.json', $vraw);
    file_put_contents($WS . '/runtime-vendor.sig', $vsig);
    $vv = new HAL_Frontend_Dashboard_Package_Verifier(
        HAL_FRONTEND_DASHBOARD_TEST_PUBLIC_KEY,
        HAL_FRONTEND_DASHBOARD_TEST_KEY_FINGERPRINT
    );
    $vcode = b11c_code(static function () use ($vv, $vzip, $WS): void {
        $vv->verify_runtime_archive($vzip, $WS . '/runtime-vendor.json', $WS . '/runtime-vendor.sig');
    });
    $vhas_puc = isset($vfiles['vendor/yahnis-elsts/plugin-update-checker/Puc/v5p7/Vcs/GitHubApi.php']);
    $vhas_autoload = isset($vfiles['vendor/autoload.php']) && isset($vfiles['vendor/composer/autoload_real.php']);
    $vhas_license = isset($vfiles['vendor/yahnis-elsts/plugin-update-checker/license.txt']);
    b11c_check(
        'B11C-VENDOR-FULL-VERIFY',
        '' === $vcode && $vhas_puc && $vhas_autoload && $vhas_license,
        'vendor-inclusive payload (' . count($vfiles) . ' files: product + locked PUC + generated autoloader + license) verified end-to-end',
        'code=' . $vcode . ' puc=' . var_export($vhas_puc, true) . ' autoload=' . var_export($vhas_autoload, true) . ' license=' . var_export($vhas_license, true)
    );
}

/* ════════════════ Report ════════════════ */

echo 'B11C RESULT: ' . $GLOBALS['B11C_PASS'] . ' pass, ' . $GLOBALS['B11C_SKIP'] . ' skipped (no PUC source — REAL bytes unavailable, never passed), ' . $GLOBALS['B11C_FAIL'] . " fail (PHP " . PHP_VERSION . ")\n";
if ($GLOBALS['B11C_FAIL'] > 0) {
    echo "Failures:\n" . implode("\n", $GLOBALS['B11C_FAILS']) . "\n";
    exit(1);
}
b11c_rmdir($WS . '/mu-chain');
b11c_rmdir($WS . '/mu-chain-rb');
b11c_rmdir($WS . '/wp-plugins2');
b11c_rmdir($WS . '/rt-v2-src');
b11c_rmdir($WS . '/rt-v2b-src');
exit(0);
