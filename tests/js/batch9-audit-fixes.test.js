/**
 * HAL Frontend Dashboard — Batch 9 audit-fix harness (B9-01 / B9-02 / B9-U1).
 *
 * Plain Node runner (no dependencies, no live browser, no network, no
 * WordPress): loads the REAL runtime/assets/js files into a sandboxed VM
 * context with minimal DOM/network stubs and drives the three audit items.
 * No project logic is reimplemented; failing paths are asserted through the
 * real callbacks with staged fetch responses.
 *
 *   B9-01 (§20 + unified §6.4 files pagination): after a successful permanent
 *         delete the view is re-read from page 1 (same pattern restoreFile
 *         already used). Scenario: 101 files, page 1 shows 100 with
 *         has_more=true, one file is deleted, the reload must expose the
 *         shifted last file (id 101) instead of requesting a stale offset.
 *   B9-02 (§20 + §10 navigation): initNav() survives a malformed hash
 *         ('#%') without throwing, completes DOMContentLoaded (notifications
 *         wiring runs), and falls back to 'overview'; a valid hash still
 *         routes to its panel.
 *   B9-U1 (unified §6.4 visual equivalence): under identical fixture inputs
 *         the reference (legacy) and migrated (runtime) render helpers
 *         produce identical markup; dashboard.css is byte-identical to the
 *         legacy source with data:-only url()s; every CSS class used by the
 *         compared fragments exists in the release stylesheet (removal of
 *         the astra-parent-style dependency orphans nothing batch-9 owns);
 *         the real enqueue closure serves release-owned assets only.
 *
 * Usage:  node tests/js/batch9-audit-fixes.test.js   (exit 0 = all pass)
 */

'use strict';

const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const crypto = require('node:crypto');

const projectRoot = path.resolve(__dirname, '..', '..');
const runtimeAssets = path.join(projectRoot, 'runtime', 'assets', 'js');
const legacyAssets = path.resolve(projectRoot, '..', '..', 'dashboard', 'Dahboard-v-1.0.0', 'theme', 'assets', 'js');

let passCount = 0;
let failCount = 0;
const failures = [];

function check(id, ok, pass, fail) {
  if (ok) {
    passCount += 1;
    console.log(`PASS [${id}] ${pass}`);
  } else {
    failCount += 1;
    failures.push(`${id}: ${fail}`);
    console.log(`FAIL [${id}] ${fail}`);
  }
}

function makeNode(extra = {}) {
  const node = {
    tagName: 'DIV',
    className: '',
    style: {},
    dataset: {},
    children: [],
    _innerHTML: '',
    classList: {
      add() {}, remove() {}, toggle() { return false; }, contains() { return false; },
    },
    setAttribute() {}, getAttribute() { return null; },
    appendChild(child) { node.children.push(child); return child; },
    insertAdjacentHTML(pos, html) { node._innerHTML += html; },
    remove() {},
    querySelector(sel) { return node._queryOverride && node._queryOverride(sel); },
    querySelectorAll() { return []; },
    closest() { return null; },
    focus() {},
    click() {},
    ...extra,
  };
  Object.defineProperty(node, 'innerHTML', {
    get() {
      if (node._fromCreateElement) { return escaped(node._text || ''); }
      return node._innerHTML;
    },
    set(v) { node._innerHTML = String(v); },
  });
  Object.defineProperty(node, 'textContent', {
    get() { return node._text || ''; },
    set(v) { node._text = String(v); },
  });
  return node;
}

function escaped(value) {
  return String(value)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function makeSandbox({ i18n = {}, responses = [] } = {}) {
  const sandbox = {};
  sandbox.window = sandbox;
  sandbox.globalThis = sandbox;
  sandbox.console = console;
  sandbox.URL = URL;
  sandbox.URLSearchParams = URLSearchParams;
  sandbox.FormData = FormData;

  sandbox.hossamAjax = {
    ajaxurl: 'https://example.test/wp-admin/admin-ajax.php',
    nonce: 'b9-test-nonce',
    isRtl: true,
    i18n,
  };

  const elements = {};
  const listeners = {};
  const doc = {
    title: 'Dashboard',
    dataset: {},
    documentElement: makeNode(),
    body: makeNode(),
    getElementById(id) { return elements[id] || null; },
    querySelector(sel) { return doc._queryOverride ? doc._queryOverride(sel) : null; },
    querySelectorAll() { return []; },
    addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
    createElement() {
      const el = makeNode();
      el._fromCreateElement = true;
      return el;
    },
  };

  sandbox.__elements = elements;
  sandbox.__listeners = listeners;
  sandbox.document = doc;
  sandbox.localStorage = { getItem() { return null; }, setItem() {}, removeItem() {} };
  sandbox.location = { href: 'https://site.test/dashboard/', hash: '', search: '', reloaded: false, reload() { sandbox.location.reloaded = true; } };
  sandbox.history = { replaceState(state, label, url) { sandbox.__lastReplace = url; } };

  sandbox.setInterval = () => 0;
  sandbox.setTimeout = () => 0;
  sandbox.clearTimeout = () => {};
  sandbox.confirm = () => true;

  const fetchCalls = [];
  let queue = responses.slice();
  sandbox.__fetchCalls = fetchCalls;
  sandbox.fetch = async (url, opts) => {
    fetchCalls.push({ url, opts });
    let next = queue.length ? queue.shift() : { ok: false, status: 599, text: async () => 'no-response-staged', json: async () => ({ success: false, data: { message: 'no-response-staged' } }) };
    if (typeof next === 'function') { next = await next(); }
    if (typeof next.text !== 'function') {
      const source = next;
      next = { ...source, text: async () => JSON.stringify(await source.json()) };
    }
    return next;
  };
  sandbox.__stageResponses = (list) => { queue = list.slice(); };

  vm.createContext(sandbox);
  return sandbox;
}

function loadRuntime(sandbox, relativePath) {
  const source = fs.readFileSync(path.join(runtimeAssets, relativePath), 'utf8');
  vm.runInContext(source, sandbox, { filename: `runtime/assets/js/${relativePath}` });
}

function loadLegacy(sandbox, relativePath) {
  const source = fs.readFileSync(path.join(legacyAssets, relativePath), 'utf8');
  vm.runInContext(source, sandbox, { filename: `legacy/theme/assets/js/${relativePath}` });
}

const tick = () => new Promise((resolve) => setTimeout(resolve, 5));

/* ════════════════════════════════════════════════════════════════
 * B9-01 — pagination continuity after a successful permanent delete
 * ════════════════════════════════════════════════════════════════ */

async function testDeleteReloadsView() {
  const sandbox = makeSandbox({
    i18n: { fileDeleted: 'File deleted', loadMore: 'Load more', noFiles: 'No files yet.', networkError: 'Network error' },
  });
  loadRuntime(sandbox, 'dashboard.js');
  loadRuntime(sandbox, 'modules/uploads.js');

  const trashWrap = makeNode();
  trashWrap._queryOverride = (sel) => {
    if (sel === '.text-center.mt-8' && trashWrap._innerHTML.includes('text-center mt-8')) {
      return { remove() { trashWrap._innerHTML = trashWrap._innerHTML.replace(/<div class="text-center mt-8"><button[\s\S]*?<\/button><\/div>/, ''); } };
    }
    return null;
  };
  sandbox.__elements['files-trash-wrap'] = trashWrap;
  sandbox.__elements['toast-container'] = makeNode();

  // 101 files server-side: page 1 returns ids 1..100 with has_more=true.
  const page1 = Array.from({ length: 100 }, (_, i) => ({ id: i + 1, title: `File ${i + 1}`, date: '2026-01-01' }));
  sandbox.__stageResponses([{
    ok: true, status: 200,
    json: async () => ({ success: true, data: { files: page1, page: 1, has_more: true } }),
  }]);
  await sandbox.loadMyFiles('trash');
  const sawLoadMore = trashWrap._innerHTML.includes('Load more');

  // Delete id 50 (success). Server now holds 100 files; a fresh page 1
  // exposes the shifted tail, including id 101, with has_more=false.
  const reloaded = Array.from({ length: 100 }, (_, i) => {
    const id = i < 49 ? i + 1 : i + 2; // 1..49, 51..101
    return { id, title: `File ${id}`, date: '2026-01-01' };
  });
  sandbox.__stageResponses([
    { ok: true, status: 200, json: async () => ({ success: true, data: {} }) },
    { ok: true, status: 200, json: async () => ({ success: true, data: { files: reloaded, page: 1, has_more: false } }) },
  ]);
  const fakeButton = { closest: () => ({ id: 'files-trash-wrap' }) };
  await sandbox.deleteFilePermanently(50, fakeButton);
  await tick();

  const actions = sandbox.__fetchCalls.map((c) => `${c.opts.body.get('action')}/page=${c.opts.body.get('page') || '-'}`);
  const reloadCall = sandbox.__fetchCalls.find((c) => c.opts.body.get('action') === 'hossam_get_my_files');
  check('B9F-1-delete-reloads-page1',
    sawLoadMore === true
      && reloadCall !== undefined
      && reloadCall.opts.body.get('page') === '1'
      && trashWrap._innerHTML.includes('data-file-id="101"')
      && !trashWrap._innerHTML.includes('data-file-id="50"')
      && !trashWrap._innerHTML.includes('Load more'),
    'after deleting one of 101 files the view is re-read from page 1 and the shifted last file (101) is reachable with no stale Load more',
    `delete did not restore pagination continuity (calls=${JSON.stringify(actions)} has101=${trashWrap._innerHTML.includes('data-file-id="101"')})`);
}

/* ════════════════════════════════════════════════════════════════
 * B9-02 — malformed hash never aborts DOMContentLoaded
 * ════════════════════════════════════════════════════════════════ */

function fireDOMContentLoaded(sandbox) {
  const handlers = sandbox.__listeners.DOMContentLoaded || [];
  for (const fn of handlers) { fn(); }
}

function testMalformedHash() {
  const sandbox = makeSandbox({ i18n: {} });
  sandbox.loadNotifications = () => { sandbox.__notifCalls = (sandbox.__notifCalls || 0) + 1; };
  loadRuntime(sandbox, 'dashboard.js');
  sandbox.__elements.sidebar = makeNode();
  sandbox.__elements.overlay = makeNode();
  sandbox.location.hash = '#%';

  let threw = null;
  try {
    fireDOMContentLoaded(sandbox);
  } catch (error) {
    threw = error;
  }
  check('B9F-2-malformed-hash-survives',
    threw === null
      && sandbox.__notifCalls === 1
      && String(sandbox.__lastReplace || '').includes('panel=overview'),
    'DOMContentLoaded with hash "#%" completes (notifications wired once) and falls back to overview',
    threw ? `threw ${threw}` : `incomplete init (notif=${sandbox.__notifCalls} url=${sandbox.__lastReplace})`);
}

function testValidHashStillRoutes() {
  const sandbox = makeSandbox({ i18n: {} });
  sandbox.loadNotifications = () => {};
  loadRuntime(sandbox, 'dashboard.js');
  sandbox.__elements.sidebar = makeNode();
  sandbox.__elements.overlay = makeNode();
  const panelButton = makeNode();
  sandbox.document._queryOverride = (sel) => {
    if (typeof sel === 'string' && sel.includes('data-panel="seo"')) { return panelButton; }
    if (typeof sel === 'string' && sel.includes('[data-panel=overview]')) { return panelButton; }
    return null;
  };
  sandbox.location.hash = '#seo';

  let threw = null;
  try {
    fireDOMContentLoaded(sandbox);
  } catch (error) {
    threw = error;
  }
  check('B9F-3-valid-hash-routes',
    threw === null && String(sandbox.__lastReplace || '').includes('panel=seo'),
    'a valid hash "#seo" still routes to its panel without exception',
    threw ? `threw ${threw}` : `valid hash misrouted (url=${sandbox.__lastReplace})`);
}

/* ════════════════════════════════════════════════════════════════
 * B9-U1 — visual equivalence under identical inputs
 * ════════════════════════════════════════════════════════════════ */

function testRenderEquivalence() {
  const legacy = makeSandbox({ i18n: {} });
  loadLegacy(legacy, 'dashboard.js');
  loadLegacy(legacy, 'modules/uploads.js');
  const runtime = makeSandbox({ i18n: {} });
  loadRuntime(runtime, 'dashboard.js');
  loadRuntime(runtime, 'modules/uploads.js');

  const script = `
    JSON.stringify([
      hossamFileRowHTML({id:7,title:'A <b>&"q',date:'2026-01-01'},'trash'),
      hossamFileRowHTML({id:7,title:'A <b>&"q',date:'2026-01-01'},'active'),
      hossamLoadMoreBtnHTML('trash'),
      skeletonHTML(2),
      esc('<img src=x onerror=alert(1)>')
    ])
  `;
  const legacyOut = vm.runInContext(script, legacy);
  const runtimeOut = vm.runInContext(script, runtime);
  check('B9F-4-render-identical',
    legacyOut === runtimeOut,
    'reference and migrated render helpers produce identical markup for identical fixture inputs (file rows trash/active, load-more, skeleton, esc)',
    'render divergence between reference and migrated outputs');
  return runtimeOut;
}

function testCssCoversFragments(renderedJson) {
  const runtimeCss = fs.readFileSync(path.join(projectRoot, 'runtime', 'assets', 'css', 'dashboard.css'), 'utf8');
  const legacyCss = fs.readFileSync(
    path.resolve(projectRoot, '..', '..', 'dashboard', 'Dahboard-v-1.0.0', 'theme', 'assets', 'css', 'dashboard.css'), 'utf8');
  const runtimeHash = crypto.createHash('sha256').update(runtimeCss, 'utf8').digest('hex');
  const legacyHash = crypto.createHash('sha256').update(legacyCss, 'utf8').digest('hex');
  // The single documented [B9-U1] utility rule is the only permitted delta:
  // strip it and the remainder must be byte-identical to the legacy source.
  const markerStart = runtimeCss.indexOf('\n/* [B9-U1]');
  const markerEnd = runtimeCss.indexOf('.text-center{text-align:center}') + '.text-center{text-align:center}'.length;
  const addedBlock = markerStart >= 0 ? runtimeCss.slice(markerStart, markerEnd) : '';
  const remainder = addedBlock !== '' ? runtimeCss.replace(addedBlock, '') : runtimeCss;
  check('B9F-5-css-delta-only',
    runtimeCss.includes('.text-center{text-align:center}')
      && runtimeCss.includes('[B9-U1]')
      && !legacyCss.includes('.text-center')
      && remainder === legacyCss,
    `dashboard.css equals the legacy source except the single documented [B9-U1] utility rule (runtime sha256 ${runtimeHash.slice(0, 12)}… vs legacy ${legacyHash.slice(0, 12)}…)`,
    'dashboard.css diverges from legacy beyond the documented [B9-U1] rule');

  const urls = [...runtimeCss.matchAll(/url\(\s*([^)]+)\)/g)].map((m) => m[1].trim().replace(/^['"]|['"]$/g, ''));
  check('B9F-6-css-urls-data-only',
    urls.length > 0 && urls.every((u) => u.startsWith('data:')),
    `all ${urls.length} url() references inside dashboard.css are data: URIs (no file URLs to break in the release)`,
    `non-data url() found: ${JSON.stringify(urls.slice(0, 3))}`);

  const classes = new Set();
  for (const fragment of JSON.parse(renderedJson)) {
    for (const m of String(fragment).matchAll(/class="([^"]+)"/g)) {
      for (const token of m[1].split(/\s+/)) { if (token) { classes.add(token); } }
    }
  }
  const missing = [...classes].filter((c) => !runtimeCss.includes(`.${c}`));
  check('B9F-7-css-covers-rendered-classes',
    classes.size > 0 && missing.length === 0,
    `all ${classes.size} CSS classes used by the compared fragments are defined in the release stylesheet`,
    `classes without a stylesheet rule: ${JSON.stringify(missing)}`);
}

function testEnqueueServesReleaseOnly() {
  const setupSource = fs.readFileSync(path.join(projectRoot, 'runtime', 'core', 'setup.php'), 'utf8');
  const stripped = setupSource
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/(^|\s)\/\/.*$/gm, '$1');
  check('B9F-8-enqueue-release-only',
    setupSource.includes('hossam-dashboard-css')
      && setupSource.includes("hossam_asset_uri( 'css/dashboard.css' )")
      && !stripped.includes('astra-parent-style')
      && !stripped.includes('get_template_directory_uri'),
    'the real enqueue closure serves release-owned assets only (no active astra-parent-style or parent-theme stylesheet call)',
    'enqueue still references a parent-theme stylesheet outside comments');
}

/* ════════════════════════════════════════════════════════════════
 * Run
 * ════════════════════════════════════════════════════════════════ */

(async function main() {
  await testDeleteReloadsView();
  testMalformedHash();
  testValidHashStillRoutes();
  const rendered = testRenderEquivalence();
  testCssCoversFragments(rendered);
  testEnqueueServesReleaseOnly();

  console.log(`B9F RESULT: ${passCount} pass, ${failCount} fail (node ${process.version})`);
  if (failCount > 0) {
    console.log('Failures:\n' + failures.join('\n'));
    process.exit(1);
  }
  process.exit(0);
})().catch((error) => {
  console.error('HARNESS ERROR:', error);
  process.exit(1);
});
