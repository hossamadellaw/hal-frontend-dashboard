/**
 * HAL Frontend Dashboard — Batch 11 forms-and-loaders test (§22).
 *
 * Plain Node runner (no dependencies, no live browser, no network):
 * loads the REAL runtime/assets/js/dashboard.js (helpers t/esc/showToast/
 * skeletonHTML) plus the REAL modules posts.js and uploads.js into a
 * sandboxed VM context with minimal DOM/network stubs, then drives the
 * documented form and loader contracts.
 *
 * Covers (§22 row):
 *   defaultPrevented on the delegated click actions, exact fetch counts
 *   (no duplicate submit / loading guard), loading state (skeleton while
 *   fetching), error toasts + technical-failure fallback, pagination
 *   append (page/has_more), and Retry re-fetch after failure.
 *
 * Usage: node tests/js/forms-and-loaders.test.js   (exit 0 = all pass)
 */

'use strict';

const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const projectRoot = path.resolve(__dirname, '..', '..');
const assetsJs = path.join(projectRoot, 'runtime', 'assets', 'js');

let passCount = 0;
let failCount = 0;

function check(id, ok, pass, fail) {
  if (ok) {
    passCount += 1;
    console.log(`PASS [${id}] ${pass}`);
  } else {
    failCount += 1;
    console.log(`FAIL [${id}] ${fail}`);
  }
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/* ── Minimal DOM stub (browser boundary only) ── */

function escapeHtml(s) {
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function makeNode(extra = {}) {
  const node = {
    tagName: 'DIV',
    className: '',
    style: {},
    dataset: {},
    children: [],
    _html: '',
    _text: '',
    classList: {
      add() {}, remove() {}, toggle() { return false; }, contains() { return false; },
    },
    setAttribute() {}, getAttribute() { return null; },
    appendChild(child) { node.children.push(child); return child; },
    insertAdjacentHTML(pos, html) { node._html += html; },
    remove() { node._removed = true; },
    querySelector(sel) {
      if (sel === '.text-center.mt-8' && node._html.includes('text-center mt-8')) {
        return { remove() { node._html = node._html.replace(/<div class="text-center mt-8">.*?<\/div>/s, ''); } };
      }
      return null;
    },
    querySelectorAll() { return []; },
    closest() { return null; },
    focus() {},
    ...extra,
  };
  Object.defineProperty(node, 'innerHTML', {
    get() {
      // Browser contract esc() relies on: a createElement'd node whose
      // textContent was set reads back escaped HTML.
      if (node._fromCreateElement && node._html === '') { return escapeHtml(node._text || ''); }
      return node._html;
    },
    set(v) { node._html = String(v); },
  });
  Object.defineProperty(node, 'textContent', {
    get() { return node._text; },
    set(v) { node._text = String(v); },
  });
  return node;
}

function makeSandbox() {
  const sandbox = {};
  sandbox.window = sandbox;
  sandbox.globalThis = sandbox;
  sandbox.console = console;
  sandbox.URL = URL;
  sandbox.FormData = FormData;
  sandbox.setTimeout = () => 0;
  sandbox.clearTimeout = () => {};
  sandbox.setInterval = () => 0;
  sandbox.clearInterval = () => {};
  sandbox.location = { href: 'https://example.test/dashboard/', reloadCalled: 0, reload() { sandbox.location.reloadCalled += 1; } };
  sandbox.localStorage = { _s: {}, getItem(k) { return this._s[k] ?? null; }, setItem(k, v) { this._s[k] = String(v); } };
  sandbox.innerWidth = 1200;
  sandbox.confirm = () => true;

  sandbox.hossamAjax = { ajaxurl: 'https://example.test/wp-admin/admin-ajax.php', nonce: 'b11-test-nonce', i18n: {} };

  const elements = {};
  const listeners = {};
  sandbox.__elements = elements;
  sandbox.__listeners = listeners;
  sandbox.__fetchCalls = [];
  sandbox.__fetchQueue = [];

  sandbox.document = {
    title: 'Dashboard',
    body: makeNode(),
    documentElement: makeNode(),
    getElementById(id) { return elements[id] || null; },
    __register(id, node) { elements[id] = node; return node; },
    querySelector() { return null; },
    querySelectorAll() { return []; },
    createElement() { return makeNode({ _fromCreateElement: true }); },
    addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
  };

  sandbox.fetch = async (url, opts) => {
    sandbox.__fetchCalls.push({ url, opts });
    const next = sandbox.__fetchQueue.shift();
    if (next instanceof Error) { throw next; }
    if (typeof next === 'function') { return next(); }
    return next;
  };

  return sandbox;
}

function jsonResponse(payload, { ok = true, status = 200 } = {}) {
  return { ok, status, text: async () => JSON.stringify(payload), json: async () => payload };
}

function loadRealSources(sandbox) {
  vm.createContext(sandbox);
  for (const file of ['dashboard.js', path.join('modules', 'posts.js'), path.join('modules', 'uploads.js')]) {
    const code = fs.readFileSync(path.join(assetsJs, file), 'utf8');
    vm.runInContext(code, sandbox, { filename: file });
  }
}

function makeForm(values) {
  return {
    querySelector(sel) {
      const m = sel.match(/\[name="([^"]+)"\]/);
      const value = m ? (values[m[1]] ?? '') : '';
      return { value: String(value) };
    },
  };
}

function lastToast(sandbox) {
  const container = sandbox.__elements['toast-container'];
  if (!container || container.children.length === 0) { return ''; }
  const toast = container.children[container.children.length - 1];
  return `${toast.className || ''} ${toast.innerHTML || ''}`;
}

function dispatchClick(sandbox, target) {
  const event = { target, preventDefaultCalled: false, preventDefault() { this.preventDefaultCalled = true; } };
  for (const fn of sandbox.__listeners.click || []) { fn(event); }
  return event;
}

async function main() {
  /* ── L1: delegated restore click prevents default + fires exactly one fetch. */
  {
    const sandbox = makeSandbox();
    loadRealSources(sandbox);
    sandbox.__elements['toast-container'] = makeNode();
    sandbox.__fetchQueue.push(jsonResponse({ success: true, data: {} }));
    const button = makeNode({ dataset: { id: '5' } });
    const target = makeNode();
    target.closest = (sel) => (sel === '.js-restore-article' ? button : null);
    const event = dispatchClick(sandbox, target);
    await sleep(20);
    const actions = sandbox.__fetchCalls.map((c) => {
      const fd = c.opts.body;
      return typeof fd.get === 'function' ? fd.get('action') : null;
    });
    check(
      'L1-DEFAULT-PREVENTED',
      event.preventDefaultCalled === true && sandbox.__fetchCalls.length === 1 && actions[0] === 'hossam_restore_article',
      'restore click: default prevented + exactly one hossam_restore_article fetch',
      `prevented=${event.preventDefaultCalled} fetches=${sandbox.__fetchCalls.length} actions=${JSON.stringify(actions)}`,
    );
    check(
      'L1-SUCCESS-TOAST',
      sandbox.location.reloadCalled === 1 && lastToast(sandbox).includes('toast-success'),
      'restore success: success toast + single reload',
      `reloads=${sandbox.location.reloadCalled} toast=${lastToast(sandbox).slice(0, 80)}`,
    );
  }

  /* ── L2: create-article success — one fetch, success toast, no fallback. */
  {
    const sandbox = makeSandbox();
    loadRealSources(sandbox);
    sandbox.__elements['toast-container'] = makeNode();
    const fallback = makeNode();
    fallback.style.display = 'none';
    sandbox.__elements['fa-fallback-write'] = fallback;
    sandbox.__fetchQueue.push(jsonResponse({ success: true, data: { edit_url: 'https://example.test/edit/1' } }));
    const form = makeForm({ title: 'Hello', content: 'World', status: 'draft', categories: '' });
    const result = await sandbox.hossamCreateArticle(form);
    await sleep(10);
    check(
      'L2-CREATE-ONCE',
      result === true && sandbox.__fetchCalls.length === 1 && sandbox.location.href === 'https://example.test/edit/1',
      'create success with edit_url: single fetch, redirect to edit_url',
      `result=${result} fetches=${sandbox.__fetchCalls.length} href=${sandbox.location.href}`,
    );
    check(
      'L2-CREATE-TOAST',
      lastToast(sandbox).includes('toast-success') && fallback.style.display === 'none',
      'create success: success toast, legacy fallback stays hidden',
      `toast=${lastToast(sandbox).slice(0, 80)} fallback=${fallback.style.display}`,
    );
  }

  /* ── L3: server 403 — error toast, fetch count stays 1, no fallback. */
  {
    const sandbox = makeSandbox();
    loadRealSources(sandbox);
    sandbox.__elements['toast-container'] = makeNode();
    const fallback = makeNode();
    fallback.style.display = 'none';
    sandbox.__elements['fa-fallback-write'] = fallback;
    sandbox.__fetchQueue.push(jsonResponse({ success: false, data: { message: 'Forbidden' } }, { ok: false, status: 403 }));
    const form = makeForm({ title: 'T', content: 'C', status: 'draft', categories: '' });
    await sandbox.hossamCreateArticle(form);
    await sleep(10);
    check(
      'L3-403-NO-FALLBACK',
      sandbox.__fetchCalls.length === 1 && lastToast(sandbox).includes('toast-error')
        && lastToast(sandbox).includes('Forbidden') && fallback.style.display === 'none',
      '403: one fetch, server-message error toast, no legacy fallback (non-technical)',
      `fetches=${sandbox.__fetchCalls.length} toast=${lastToast(sandbox).slice(0, 100)}`,
    );
  }

  /* ── L4: network error — error toast + technical-failure fallback opens. */
  {
    const sandbox = makeSandbox();
    loadRealSources(sandbox);
    sandbox.__elements['toast-container'] = makeNode();
    const fallback = makeNode();
    fallback.style.display = 'none';
    sandbox.__elements['fa-fallback-write'] = fallback;
    sandbox.__fetchQueue.push(new Error('network down'));
    const form = makeForm({ title: 'T', content: 'C', status: 'draft', categories: '' });
    await sandbox.hossamCreateArticle(form);
    await sleep(10);
    check(
      'L4-NETWORK-FALLBACK',
      fallback.style.display === 'block' && lastToast(sandbox).includes('toast-error'),
      'network failure: legacy fallback opens + error toast',
      `fallback=${fallback.style.display}`,
    );
  }

  /* ── L5: loader guard — concurrent loads collapse to a single fetch. */
  {
    const sandbox = makeSandbox();
    loadRealSources(sandbox);
    const wrap = makeNode();
    sandbox.__elements['files-my-files-wrap'] = wrap;
    let release;
    const gate = new Promise((resolve) => { release = resolve; });
    sandbox.__fetchQueue.push(() => gate.then(() => jsonResponse({ success: true, data: { files: [], page: 1, has_more: false } })));
    const first = sandbox.loadMyFiles('active', false);
    const second = sandbox.loadMyFiles('active', false);
    check(
      'L5-LOADING-STATE',
      wrap.innerHTML.includes('skel-row') && sandbox.__hossamFiles.active.loading === true,
      'loading: skeleton shown + loading flag set while fetching',
      `html=${wrap.innerHTML.slice(0, 60)} loading=${sandbox.__hossamFiles.active.loading}`,
    );
    release();
    await first;
    await second;
    await sleep(10);
    check(
      'L5-SINGLE-FETCH',
      sandbox.__fetchCalls.length === 1 && wrap.innerHTML.includes('No files yet.'),
      'concurrent loads: exactly one fetch, empty state rendered',
      `fetches=${sandbox.__fetchCalls.length}`,
    );
  }

  /* ── L6: pagination — page 1 appends page 2, Load more disappears at the end. */
  {
    const sandbox = makeSandbox();
    loadRealSources(sandbox);
    const wrap = makeNode();
    sandbox.__elements['files-my-files-wrap'] = wrap;
    sandbox.__fetchQueue.push(jsonResponse({
      success: true,
      data: { files: [{ id: 1, title: 'Alpha' }], page: 1, has_more: true },
    }));
    await sandbox.loadMyFiles('active', false);
    await sleep(10);
    const afterFirst = wrap.innerHTML;
    sandbox.__fetchQueue.push(jsonResponse({
      success: true,
      data: { files: [{ id: 2, title: 'Beta' }], page: 2, has_more: false },
    }));
    await sandbox.loadMyFiles('active', true);
    await sleep(10);
    check(
      'L6-PAGINATION-APPEND',
      afterFirst.includes('Alpha') && afterFirst.includes('Load more')
        && wrap.innerHTML.includes('Alpha') && wrap.innerHTML.includes('Beta')
        && !wrap.innerHTML.includes('Load more'),
      'page 1 renders + Load more; page 2 appends and the button disappears',
      `html=${wrap.innerHTML.slice(0, 200)}`,
    );
    check(
      'L6-FETCH-COUNT',
      sandbox.__fetchCalls.length === 2
        && sandbox.__fetchCalls[0].opts.body.get('page') === '1'
        && sandbox.__fetchCalls[1].opts.body.get('page') === '2',
      'exactly two paged fetches (page 1 then 2)',
      `fetches=${sandbox.__fetchCalls.length}`,
    );
  }

  /* ── L7: failure renders Retry; retry re-fetches and renders. */
  {
    const sandbox = makeSandbox();
    loadRealSources(sandbox);
    const wrap = makeNode();
    sandbox.__elements['files-my-files-wrap'] = wrap;
    sandbox.__fetchQueue.push(new Error('boom'));
    await sandbox.loadMyFiles('active', false);
    await sleep(10);
    const failedHtml = wrap.innerHTML;
    sandbox.__fetchQueue.push(jsonResponse({
      success: true,
      data: { files: [{ id: 7, title: 'RetryOK' }], page: 1, has_more: false },
    }));
    await sandbox.loadMyFiles('active', false);
    await sleep(10);
    check(
      'L7-RETRY',
      failedHtml.includes('Retry') && wrap.innerHTML.includes('RetryOK') && sandbox.__fetchCalls.length === 2,
      'failure shows Retry; re-invocation re-fetches and renders',
      `failed=${failedHtml.slice(0, 120)} fetches=${sandbox.__fetchCalls.length}`,
    );
  }

  /* ── L8: contract violation (success without files[]) shows Retry, no crash. */
  {
    const sandbox = makeSandbox();
    loadRealSources(sandbox);
    const wrap = makeNode();
    sandbox.__elements['files-my-files-wrap'] = wrap;
    sandbox.__fetchQueue.push(jsonResponse({ success: true, data: { nope: 1 } }));
    await sandbox.loadMyFiles('active', false);
    await sleep(10);
    check(
      'L8-CONTRACT',
      wrap.innerHTML.includes('Retry') && sandbox.__hossamFiles.active.loading === false,
      'contract violation: Retry notice + loading flag cleared',
      `html=${wrap.innerHTML.slice(0, 120)}`,
    );
  }

  console.log(`RESULT: ${passCount}/${passCount + failCount} checks passed`);
  process.exit(failCount === 0 ? 0 : 1);
}

main().catch((error) => {
  console.error(`FATAL ${error && error.stack ? error.stack : error}`);
  process.exit(1);
});
