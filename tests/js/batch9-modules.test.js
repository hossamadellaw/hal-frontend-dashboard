/**
 * HAL Frontend Dashboard — Batch 9 modules DOM edge test (§20).
 *
 * Plain Node runner (no dependencies, no live browser, no network):
 * loads the REAL runtime/assets/js/dashboard.js and the REAL modules
 * posts.js / uploads.js / members.js into a sandboxed VM context with
 * minimal DOM/network stubs and drives the documented edge cases.
 *
 * Covers (architecture §20 closure gate):
 *   dashboard.js — t() i18n fallback, esc() XSS escaping, skeletonHTML,
 *                  nav() URL/title contract, switchTab() lazy loader
 *                  wiring, and the documented global surface (no hidden
 *                  globals: every shell-consumed helper is on window).
 *   posts.js     — technical-failure classification (401/403/404/400
 *                  never open the fallback; network error/5xx/unparseable
 *                  do), success/error toasts, confirm gate for trash,
 *                  restore/translate flows.
 *   uploads.js   — pagination contract ({files,page,has_more}), Load
 *                  more append, view re-init, empty state, malformed
 *                  JSON / contract violation → Retry notice, loading
 *                  guard, upload fallback only on technical failure.
 *   members.js   — table render + Load more append, contract violation,
 *                  malformed JSON, server error message, retry notice.
 *
 * Usage: node tests/js/batch9-modules.test.js   (exit 0 = all pass)
 */

'use strict';

const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const projectRoot = path.resolve(__dirname, '..', '..');
const runtimeAssets = path.join(projectRoot, 'runtime', 'assets', 'js');

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

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/* ── Sandbox construction (WordPress/DOM/network boundary only) ── */

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
      // A createElement'd node whose textContent was set reads back escaped,
      // mirroring the browser contract esc() relies on.
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
  sandbox.location = { href: 'https://site.test/dashboard/', hash: '', reloaded: false, reload() { sandbox.location.reloaded = true; } };
  sandbox.history = { replaceState(state, label, url) { sandbox.__lastReplace = url; } };

  const timers = { fired: [] };
  sandbox.__timers = timers;
  sandbox.setInterval = () => 0;
  sandbox.setTimeout = (fn, ms) => { timers.fired.push(ms); return 0; };
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

function loadRealFile(sandbox, relativePath) {
  const source = fs.readFileSync(path.join(runtimeAssets, relativePath), 'utf8');
  vm.runInContext(source, sandbox, { filename: `runtime/assets/js/${relativePath}` });
}

function escaped(value) {
  return String(value)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/* ════════════════════════════════════════════════════════════════
 * dashboard.js — helpers, nav, switchTab, global surface
 * ════════════════════════════════════════════════════════════════ */

function testDashboardHelpers() {
  const sandbox = makeSandbox({ i18n: { panelOverview: 'نظرة عامة' } });
  loadRealFile(sandbox, 'dashboard.js');
  loadRealFile(sandbox, 'modules/posts.js');
  loadRealFile(sandbox, 'modules/uploads.js');
  loadRealFile(sandbox, 'modules/members.js');

  check('B9J-1-t-i18n-and-fallback',
    vm.runInContext(`t('panelOverview','F')`, sandbox) === 'نظرة عامة'
      && vm.runInContext(`t('missingKey','FALL')`, sandbox) === 'FALL',
    't() returns the localized string and the inline fallback for undefined keys',
    't() i18n/fallback contract broken');

  const escapedValue = vm.runInContext(`esc('<img src=x onerror=alert(1)>')`, sandbox);
  check('B9J-2-esc-xss',
    escapedValue === escaped('<img src=x onerror=alert(1)>') && !escapedValue.includes('<img'),
    'esc() escapes HTML-significant characters (no raw markup survives)',
    'esc() did not escape: ' + escapedValue);

  const skeleton = vm.runInContext(`skeletonHTML(2)`, sandbox);
  check('B9J-3-skeleton',
    (skeleton.match(/skel-row/g) || []).length === 2 && skeleton.includes('skel-line'),
    'skeletonHTML(2) renders two skeleton rows using the CSS classes',
    'skeletonHTML output unexpected: ' + skeleton);

  const surface = ['t', 'esc', 'showToast', 'skeletonHTML', 'nav', 'switchTab', 'initNav',
    'loadMyFiles', 'restoreFile', 'deleteFilePermanently', 'hossamUploadFile',
    'loadMembers', 'hossamCreateArticle', 'hossamUpdateArticle', 'hossamTrashArticle',
    'restoreArticle', 'translateArticle'];
  const missing = surface.filter((name) => typeof sandbox[name] !== 'function');
  check('B9J-4-global-surface',
    missing.length === 0,
    'every shell-consumed helper is a visible window global (no hidden globals)',
    'missing global helpers: ' + JSON.stringify(missing));
}

function testNav() {
  const sandbox = makeSandbox({ i18n: { panelOverview: 'Overview' } });
  loadRealFile(sandbox, 'dashboard.js');
  sandbox.__elements['sidebar'] = makeNode();
  sandbox.__elements['overlay'] = makeNode();

  vm.runInContext(`nav(null, 'overview')`, sandbox);
  const lastUrl = String(sandbox.__lastReplace || '');
  check('B9J-5-nav-url-and-title',
    lastUrl.includes('panel=overview') && !lastUrl.includes('post_id=') && sandbox.document.title.includes('Overview'),
    'nav() writes panel=overview into the URL, drops post_id, and sets the localized title',
    `nav() url=${lastUrl} title=${sandbox.document.title}`);
}

async function testSwitchTabLoader() {
  const sandbox = makeSandbox({
    i18n: { loadMore: 'Load more', retry: 'Retry', noFiles: 'No files yet.', networkError: 'Network error' },
  });
  loadRealFile(sandbox, 'dashboard.js');
  loadRealFile(sandbox, 'modules/uploads.js');
  const wrap = makeNode();
  sandbox.__elements['files-my-files-wrap'] = wrap;
  sandbox.__stageResponses([{
    ok: true, status: 200,
    json: async () => ({ success: true, data: { files: [{ id: 1, title: 'A', date: 'D' }], page: 1, has_more: false } }),
  }]);

  const tabButton = {
    dataset: { tab: 'my-files' },
    classList: { add() {}, remove() {} },
    closest() { return null; },
  };
  sandbox.switchTab(tabButton);
  await awaitOneTick();
  check('B9J-6-switchtab-lazy-loader',
    sandbox.__fetchCalls.length === 1 && wrap._innerHTML.includes('data-file-id="1"'),
    'switchTab(my-files) lazily invokes the real loadMyFiles loader once',
    'switchTab did not reach loadMyFiles: fetch calls=' + sandbox.__fetchCalls.length);
}

function awaitOneTick() {
  // The loaders are async; drain one microtask/macrotask turn.
  return new Promise((resolve) => setTimeout(resolve, 5));
}

/* ════════════════════════════════════════════════════════════════
 * posts.js — fallback rule and flows
 * ════════════════════════════════════════════════════════════════ */

function testPostsClassification() {
  const sandbox = makeSandbox();
  loadRealFile(sandbox, 'dashboard.js');
  loadRealFile(sandbox, 'modules/posts.js');
  const matrix = vm.runInContext(`[
    hossamIsTechnicalFailure(true, null, null),
    hossamIsTechnicalFailure(false, null, null),
    hossamIsTechnicalFailure(false, { status: 401 }, null),
    hossamIsTechnicalFailure(false, { status: 403 }, null),
    hossamIsTechnicalFailure(false, { status: 404 }, null),
    hossamIsTechnicalFailure(false, { status: 400 }, null),
    hossamIsTechnicalFailure(false, { status: 500 }, null),
    hossamIsTechnicalFailure(false, { status: 200 }, null),
    hossamIsTechnicalFailure(false, { status: 200 }, { data: { code: 'hossam_partial_failure' } }),
    hossamIsTechnicalFailure(false, { status: 200 }, { success: true, data: {} })
  ]`, sandbox);
  const expected = [true, true, false, false, false, false, true, true, false, false];
  check('B9J-7-posts-technical-failure-matrix',
    JSON.stringify(matrix) === JSON.stringify(expected),
    'fallback opens only for network failure, missing response, 5xx, or unparseable body — never for 4xx/security rejection or partial_failure',
    'classification matrix mismatch: ' + JSON.stringify(matrix));
}

async function testPostsFlows() {
  // Every module sandbox loads the real dependency chain first:
  // dashboard.js (showToast/esc/t/skeletonHTML) then the module itself.
  // Success with edit_url
  let sandbox = makeSandbox({ i18n: { published: 'Published', draftSaved: 'Draft saved' } });
  loadRealFile(sandbox, 'dashboard.js');
  loadRealFile(sandbox, 'modules/posts.js');
  const toastBox = makeNode();
  sandbox.__elements['toast-container'] = toastBox;
  const form = makeForm(sandbox, { title: 'T', content: 'C', status: 'publish' });
  sandbox.__stageResponses([{
    ok: true, status: 200,
    json: async () => ({ success: true, data: { edit_url: 'https://site.test/?p=9' } }),
  }]);
  await sandbox.hossamCreateArticle(form);
  check('B9J-8-posts-create-success',
    sandbox.location.href === 'https://site.test/?p=9' && toastBox.children.length === 1,
    'create success shows a toast and follows the server edit_url',
    `create success flow broken: href=${sandbox.location.href}`);

  // Security rejection (403) — toast only, fallback stays closed
  sandbox = makeSandbox({ i18n: { networkError: 'Network error' } });
  loadRealFile(sandbox, 'dashboard.js');
  loadRealFile(sandbox, 'modules/posts.js');
  const toast403 = makeNode();
  sandbox.__elements['toast-container'] = toast403;
  const fallbackWrite = makeNode();
  fallbackWrite.style.display = 'none';
  sandbox.__elements['fa-fallback-write'] = fallbackWrite;
  sandbox.__stageResponses([{
    ok: false, status: 403,
    json: async () => ({ success: false, data: { message: 'DENIED' } }),
  }]);
  const created = await sandbox.hossamCreateArticle(makeForm(sandbox, { title: 'T' }));
  check('B9J-9-posts-403-no-fallback',
    created === false && toast403.children.length === 1
      && toast403.children[0]._innerHTML.includes('DENIED') && fallbackWrite.style.display === 'none',
    '403 rejection shows the server message and never opens [frontend_admin] fallback',
    '403 flow opened fallback or hid the message');

  // 500 — technical failure opens the fallback
  sandbox = makeSandbox({ i18n: { fallbackNotice: 'Fallback notice', networkError: 'Network error' } });
  loadRealFile(sandbox, 'dashboard.js');
  loadRealFile(sandbox, 'modules/posts.js');
  sandbox.__elements['toast-container'] = makeNode();
  const fallbackWrite2 = makeNode();
  fallbackWrite2.style.display = 'none';
  sandbox.__elements['fa-fallback-write'] = fallbackWrite2;
  sandbox.__stageResponses([{
    ok: false, status: 500,
    json: async () => ({ success: false, data: { message: 'BOOM' } }),
  }]);
  await sandbox.hossamCreateArticle(makeForm(sandbox, { title: 'T' }));
  check('B9J-10-posts-500-fallback',
    fallbackWrite2.style.display === 'block',
    '500 technical failure opens the legacy fallback exactly once',
    '500 flow did not open the fallback');

  // Trash: explicit confirm gate
  sandbox = makeSandbox({ i18n: { confirmTrashArticle: 'Sure?' } });
  loadRealFile(sandbox, 'dashboard.js');
  loadRealFile(sandbox, 'modules/posts.js');
  sandbox.confirm = () => false;
  await sandbox.hossamTrashArticle(3, null);
  check('B9J-11-posts-trash-confirm-gate',
    sandbox.__fetchCalls.length === 0,
    'trash sends nothing when the explicit confirm is declined',
    'trash proceeded without confirmation');

  // Restore success reloads
  sandbox = makeSandbox({ i18n: { articleRestored: 'Restored' } });
  loadRealFile(sandbox, 'dashboard.js');
  loadRealFile(sandbox, 'modules/posts.js');
  sandbox.__elements['toast-container'] = makeNode();
  sandbox.__stageResponses([{
    ok: true, status: 200,
    json: async () => ({ success: true, data: {} }),
  }]);
  await sandbox.restoreArticle(11);
  check('B9J-12-posts-restore-success',
    sandbox.location.reloaded === true,
    'restore success reloads the list',
    'restore success did not reload');

  // Translate success with translated id
  sandbox = makeSandbox({ i18n: { translationCreated: 'Created' } });
  loadRealFile(sandbox, 'dashboard.js');
  loadRealFile(sandbox, 'modules/posts.js');
  sandbox.__elements['toast-container'] = makeNode();
  sandbox.__stageResponses([{
    ok: true, status: 200,
    json: async () => ({ success: true, data: { translated_id: 9 } }),
  }]);
  await sandbox.translateArticle(4, 'en');
  const translateUrl = String(sandbox.location.href);
  check('B9J-13-posts-translate-success',
    translateUrl.includes('post_id=9') && translateUrl.includes('panel=edit-article'),
    'translate success navigates to the new translation editor',
    'translate success navigation broken: ' + translateUrl);
}

function makeForm(sandbox, fields) {
  return {
    querySelector(sel) {
      const name = (sel.match(/name="([a-z_]+)"/) || [])[1];
      if (name && Object.prototype.hasOwnProperty.call(fields, name)) {
        return { value: fields[name] };
      }
      return null;
    },
    reset() {},
  };
}

/* ════════════════════════════════════════════════════════════════
 * uploads.js — pagination, contract, fallback rule
 * ════════════════════════════════════════════════════════════════ */

function uploadsSandbox(i18n) {
  const sandbox = makeSandbox({ i18n });
  loadRealFile(sandbox, 'dashboard.js');
  loadRealFile(sandbox, 'modules/uploads.js');
  const wrap = makeNode();
  wrap._queryOverride = (sel) => {
    if (sel === '.text-center.mt-8' && wrap._innerHTML.includes('text-center mt-8')) {
      return { remove() {
        wrap._loadMoreRemoved = true;
        wrap._innerHTML = wrap._innerHTML.replace(
          /<div class="text-center mt-8"><button[\s\S]*?<\/button><\/div>/, '');
      } };
    }
    return null;
  };
  sandbox.__elements['files-my-files-wrap'] = wrap;
  sandbox.__wrap = wrap;
  return sandbox;
}

const UP_I18N = {
  loadMore: 'Load more', retry: 'Retry', noFiles: 'No files yet.',
  networkError: 'Network error', fileRestored: 'File restored',
  fileDeleted: 'File deleted', fileUploaded: 'File uploaded.',
  confirmDeleteFile: 'Sure?', fallbackNotice: 'Fallback notice',
};

async function testUploadsFlows() {
  // Page 1 with has_more → rows + Load more
  let sandbox = uploadsSandbox(UP_I18N);
  sandbox.__stageResponses([{
    ok: true, status: 200,
    json: async () => ({ success: true, data: { files: [{ id: 5, title: 'Contract', date: '2026' }], page: 1, has_more: true } }),
  }]);
  await sandbox.loadMyFiles('active');
  check('B9J-14-uploads-page1-loadmore',
    sandbox.__wrap._innerHTML.includes('data-file-id="5"') && sandbox.__wrap._innerHTML.includes('Load more'),
    'page 1 renders file rows and a Load more control when has_more=true',
    'page 1 render broken: ' + sandbox.__wrap._innerHTML);

  // Append page 2 → old Load more removed, rows appended
  sandbox.__stageResponses([{
    ok: true, status: 200,
    json: async () => ({ success: true, data: { files: [{ id: 6, title: 'Deed', date: '2026' }], page: 2, has_more: false } }),
  }]);
  await sandbox.loadMyFiles('active', true);
  check('B9J-15-uploads-append-page2',
    sandbox.__wrap._loadMoreRemoved === true
      && sandbox.__wrap._innerHTML.includes('data-file-id="5"')
      && sandbox.__wrap._innerHTML.includes('data-file-id="6"')
      && !sandbox.__wrap._innerHTML.includes('Load more'),
    'append adds page 2 rows, removes the stale Load more, and adds none when has_more=false',
    'append flow broken: ' + sandbox.__wrap._innerHTML);

  // View switch re-initialises to page 1
  sandbox.__stageResponses([{
    ok: true, status: 200,
    json: async () => ({ success: true, data: { files: [{ id: 7, title: 'Only', date: '2026' }], page: 1, has_more: false } }),
  }]);
  await sandbox.loadMyFiles('active');
  check('B9J-16-uploads-view-reinit',
    sandbox.__fetchCalls[sandbox.__fetchCalls.length - 1].opts.body.get('page') === '1'
      && sandbox.__wrap._innerHTML.includes('data-file-id="7"')
      && !sandbox.__wrap._innerHTML.includes('data-file-id="5"'),
    'a non-append load re-initialises to page 1 and replaces the list',
    're-init flow broken');

  // Empty state
  sandbox = uploadsSandbox(UP_I18N);
  sandbox.__stageResponses([{
    ok: true, status: 200,
    json: async () => ({ success: true, data: { files: [], page: 1, has_more: false } }),
  }]);
  await sandbox.loadMyFiles('active');
  check('B9J-17-uploads-empty-state',
    sandbox.__wrap._innerHTML.includes('ph-notice') && sandbox.__wrap._innerHTML.includes('No files yet.'),
    'an empty first page renders the empty notice',
    'empty state broken: ' + sandbox.__wrap._innerHTML);

  // Malformed JSON → Retry notice
  sandbox = uploadsSandbox(UP_I18N);
  sandbox.__stageResponses([{ ok: true, status: 200, json: async () => ({}), text: async () => 'not-json' }]);
  await sandbox.loadMyFiles('active');
  check('B9J-18-uploads-malformed-json',
    sandbox.__wrap._innerHTML.includes('Retry') && sandbox.__wrap._innerHTML.includes('ph-notice'),
    'a malformed JSON body renders the retry notice instead of failing silently',
    'malformed JSON handling broken: ' + sandbox.__wrap._innerHTML);

  // Contract violation (missing has_more) → Retry notice
  sandbox = uploadsSandbox(UP_I18N);
  sandbox.__stageResponses([{
    ok: true, status: 200,
    json: async () => ({ success: true, data: { files: [{ id: 1, title: 'X', date: 'D' }], page: 1 } }),
  }]);
  await sandbox.loadMyFiles('active');
  check('B9J-19-uploads-contract-violation',
    sandbox.__wrap._innerHTML.includes('Retry') && !sandbox.__wrap._innerHTML.includes('data-file-id="1"'),
    'a response violating the {files,page,has_more} contract renders the retry notice and no rows',
    'contract violation handling broken: ' + sandbox.__wrap._innerHTML);

  // Loading guard
  sandbox = uploadsSandbox(UP_I18N);
  sandbox.loadMyFiles('active');
  await sandbox.loadMyFiles('active');
  awaitOneTick();
  check('B9J-20-uploads-loading-guard',
    sandbox.__fetchCalls.length === 1,
    'the loading guard collapses concurrent loads into one request',
    'loading guard broken: fetch calls=' + sandbox.__fetchCalls.length);

  // Upload success → refresh list + form reset
  sandbox = uploadsSandbox(UP_I18N);
  sandbox.__elements['toast-container'] = makeNode();
  const fileForm = {
    querySelector(sel) {
      return sel === 'input[type="file"][name="file"]' ? { files: [{ name: 'a.txt' }] } : null;
    },
    reset() { fileForm.resetCalled = true; },
  };
  sandbox.__stageResponses([
    { ok: true, status: 200, json: async () => ({ success: true, data: {} }) },
    { ok: true, status: 200, json: async () => ({ success: true, data: { files: [{ id: 2, title: 'A', date: 'D' }], page: 1, has_more: false } }) },
  ]);
  const uploaded = await sandbox.hossamUploadFile(fileForm);
  check('B9J-21-uploads-upload-success',
    uploaded === true && fileForm.resetCalled === true && sandbox.__fetchCalls.length === 2
      && sandbox.__fetchCalls[0].opts.body.get('action') === 'hossam_upload_file',
    'upload success posts hossam_upload_file, resets the form, and refreshes the list',
    'upload success flow broken');

  // Upload 403 → no fallback
  sandbox = uploadsSandbox(UP_I18N);
  sandbox.__elements['toast-container'] = makeNode();
  const sharedFallback = makeNode();
  sharedFallback.style.display = 'none';
  sandbox.__elements['hossam-shared-fallback'] = sharedFallback;
  sandbox.__stageResponses([{ ok: false, status: 403, json: async () => ({ success: false, data: { message: 'DENIED' } }) }]);
  await sandbox.hossamUploadFile(fileForm);
  check('B9J-22-uploads-403-no-fallback',
    sharedFallback.style.display === 'none',
    'a security rejection (403) never opens the [shared_files] fallback',
    '403 opened the fallback');

  // Upload 500 → fallback opens
  sandbox = uploadsSandbox(UP_I18N);
  sandbox.__elements['toast-container'] = makeNode();
  const sharedFallback2 = makeNode();
  sharedFallback2.style.display = 'none';
  sandbox.__elements['hossam-shared-fallback'] = sharedFallback2;
  sandbox.__stageResponses([{ ok: false, status: 500, json: async () => ({ success: false, data: { message: 'BOOM' } }) }]);
  await sandbox.hossamUploadFile(fileForm);
  check('B9J-23-uploads-500-fallback',
    sharedFallback2.style.display === 'block',
    'a 500 technical failure opens the [shared_files] upload fallback',
    '500 did not open the fallback');
}

/* ════════════════════════════════════════════════════════════════
 * members.js — render, pagination, contract
 * ════════════════════════════════════════════════════════════════ */

function membersSandbox() {
  const sandbox = makeSandbox({
    i18n: { loadMore: 'Load more', retry: 'Retry', noMembers: 'No members yet.', networkError: 'Network error' },
  });
  loadRealFile(sandbox, 'dashboard.js');
  loadRealFile(sandbox, 'modules/members.js');
  const wrap = makeNode();
  sandbox.__elements['members-table-wrap'] = wrap;
  sandbox.__wrap = wrap;
  return sandbox;
}

async function testMembersFlows() {
  // Page 1 → table + Load more; page 2 append
  let sandbox = membersSandbox();
  sandbox.__stageResponses([{
    ok: true, status: 200,
    json: async () => ({ success: true, data: { members: [{ name: 'A', email: 'a@x', roles: ['lawyer'] }], page: 1, has_more: true } }),
  }]);
  await sandbox.loadMembers();
  check('B9J-24-members-page1',
    sandbox.__wrap._innerHTML.includes('<table') && sandbox.__wrap._innerHTML.includes('a@x') && sandbox.__wrap._innerHTML.includes('Load more'),
    'members page 1 renders the table with escaped fields and a Load more control',
    'members page 1 broken: ' + sandbox.__wrap._innerHTML);

  sandbox.__stageResponses([{
    ok: true, status: 200,
    json: async () => ({ success: true, data: { members: [{ name: 'B', email: 'b@x', roles: ['clerk'] }], page: 2, has_more: false } }),
  }]);
  await sandbox.loadMembers(true);
  const membersPage = vm.runInContext('hossamMembersState.page', sandbox);
  check('B9J-25-members-append',
    sandbox.__wrap._innerHTML.includes('b@x') && membersPage === 2
      && !sandbox.__wrap._innerHTML.includes('Load more'),
    'append pushes page 2 members and stops offering Load more when has_more=false',
    'members append broken: page=' + membersPage);

  // Contract violation (page not an integer)
  sandbox = membersSandbox();
  sandbox.__stageResponses([{
    ok: true, status: 200,
    json: async () => ({ success: true, data: { members: [{ name: 'A', email: 'a@x', roles: [] }], page: 'x', has_more: true } }),
  }]);
  await sandbox.loadMembers();
  check('B9J-26-members-contract-violation',
    sandbox.__wrap._innerHTML.includes('Invalid server response') && sandbox.__wrap._innerHTML.includes('Retry'),
    'a non-integer page violates the contract and renders the inline-fallback message with Retry',
    'members contract handling broken: ' + sandbox.__wrap._innerHTML);

  // Malformed JSON
  sandbox = membersSandbox();
  sandbox.__stageResponses([{ ok: true, status: 200, json: async () => ({}), text: async () => 'garbage{' }]);
  await sandbox.loadMembers();
  check('B9J-27-members-malformed-json',
    sandbox.__wrap._innerHTML.includes('Invalid server response'),
    'malformed JSON renders the contract-error fallback message',
    'members malformed JSON handling broken: ' + sandbox.__wrap._innerHTML);

  // Server error message surfaces
  sandbox = membersSandbox();
  sandbox.__stageResponses([{ ok: false, status: 403, json: async () => ({ success: false, data: { message: 'FORBIDDEN' } }) }]);
  await sandbox.loadMembers();
  check('B9J-28-members-server-error',
    sandbox.__wrap._innerHTML.includes('FORBIDDEN'),
    'a failed request surfaces the server message with the retry control',
    'members server-error handling broken: ' + sandbox.__wrap._innerHTML);
}

/* ════════════════════════════════════════════════════════════════
 * Run
 * ════════════════════════════════════════════════════════════════ */

(async function main() {
  testDashboardHelpers();
  testNav();
  await testSwitchTabLoader();
  testPostsClassification();
  await testPostsFlows();
  await testUploadsFlows();
  await testMembersFlows();

  console.log(`B9J RESULT: ${passCount} pass, ${failCount} fail (node ${process.version})`);
  if (failCount > 0) {
    console.log('Failures:\n' + failures.join('\n'));
    process.exit(1);
  }
  process.exit(0);
})().catch((error) => {
  console.error('HARNESS ERROR:', error);
  process.exit(1);
});
