/**
 * HAL Frontend Dashboard — Batch 10 modules DOM/Fetch edge test (§21).
 *
 * Plain Node runner (no dependencies, no live browser, no network):
 * loads the REAL runtime/assets/js/dashboard.js and the REAL modules
 * bookings.js / finance.js / inbox.js / store.js / ai.js /
 * translations.js into a sandboxed VM context with minimal DOM/network
 * stubs and drives the documented edge cases.
 *
 * Covers (architecture §21 closure gate):
 *   L1 closure   — every loader referenced by dashboard.js
 *                  (nav debounce, TAB_LOADERS, DOMContentLoaded polling)
 *                  resolves once the batch-10 modules are loaded in the
 *                  real enqueue order (closes §15.24 L1 ReferenceError).
 *   bookings.js  — success render + per-panel lazy nonce, loaded/loading
 *                  concurrency guards, empty html → noAppointments,
 *                  401/403 → forbidden, 503/plugin_missing →
 *                  integrationUnavailable, 500 → loadFailed, malformed
 *                  JSON / non-string html → contractError + Retry,
 *                  network error + Retry, loading flag reset.
 *   finance.js   — orders table into the three target wraps, statuses
 *                  filter, pagination prev/next, zero state, 403/5xx
 *                  server messages, malformed JSON, KPI summary (auto
 *                  init at load + month range), payment methods
 *                  enabled/disabled, saved tokens last4-only (no PAN).
 *   inbox.js     — notifications badge count + escaped render + empty +
 *                  error, inbox badges from two badge sources, mark
 *                  read flows, already-read navigation (no refetch),
 *                  send message validation/success/failure, mark all
 *                  read.
 *   store.js     — {products,page,has_more} contract, stale page →
 *                  contract violation (duplicate prevention), pagination
 *                  prev/next, empty state, loading guard, esc'd server
 *                  error.
 *   ai.js        — submit whitelist (job_type/content + optionals, no
 *                  strategy/provider/secret field), submit failure →
 *                  aiSubmitFailed, poll pending→completed renders esc'd
 *                  result + Apply, poll failed → esc'd error, transient
 *                  poll failure ignored, poll timeout at the attempt cap,
 *                  delegated .js-ai-run click with preventDefault (no
 *                  inline submit).
 *   translations.js — loads as a no-op: defines nothing, fetches nothing
 *                  (server-rendered panel; no invented code).
 *   Static gate  — comment-stripped sources contain no onsubmit /
 *                  javascript: submission surface and no inline AI
 *                  submit (batch-9 audit C2 lesson applied to JS).
 *
 * Usage: node tests/js/batch10-modules.test.js   (exit 0 = all pass)
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

function makeSandbox({ i18n = {} } = {}) {
  const sandbox = {};
  sandbox.window = sandbox;
  sandbox.globalThis = sandbox;
  sandbox.console = console;
  sandbox.URL = URL;
  sandbox.URLSearchParams = URLSearchParams;
  sandbox.FormData = FormData;

  sandbox.hossamAjax = {
    ajaxurl: 'https://example.test/wp-admin/admin-ajax.php',
    nonce: 'b10-test-nonce',
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
  sandbox.location = {
    href: 'https://site.test/dashboard/', hash: '', reloaded: false, assigned: null,
    reload() { sandbox.location.reloaded = true; },
    assign(url) { sandbox.location.assigned = String(url); },
  };
  sandbox.history = { replaceState(state, label, url) { sandbox.__lastReplace = url; } };

  const timers = { fired: [] };
  sandbox.__timers = timers;
  sandbox.setTimeout = (fn, ms) => { timers.fired.push(ms); return 0; };
  sandbox.clearTimeout = () => {};
  sandbox.confirm = () => true;

  // Controllable interval registry: ai.js polling (and the shell/finance
  // periodic refreshes) register here; tests drive ticks manually.
  const intervals = new Map();
  let intervalSeq = 0;
  sandbox.__intervals = intervals;
  sandbox.setInterval = (fn, ms) => {
    const id = ++intervalSeq;
    intervals.set(id, { fn, ms, cleared: false });
    return id;
  };
  sandbox.clearInterval = (id) => {
    const entry = intervals.get(id);
    if (entry) entry.cleared = true;
  };
  sandbox.__tickInterval = async (id) => {
    const entry = intervals.get(id);
    if (!entry || entry.cleared) return false;
    await entry.fn();
    return true;
  };

  const fetchCalls = [];
  let queue = [];
  sandbox.__fetchCalls = fetchCalls;
  sandbox.fetch = async (url, opts) => {
    fetchCalls.push({ url, opts });
    let next = queue.length ? queue.shift() : {
      ok: false, status: 599,
      text: async () => 'no-response-staged',
      json: async () => ({ success: false, data: { message: 'no-response-staged' } }),
    };
    if (typeof next === 'function') { next = await next(); }
    if (typeof next.text !== 'function') {
      const source = next;
      next = { ...source, text: async () => JSON.stringify(await source.json()) };
    }
    return next;
  };
  sandbox.__stageResponses = (list) => { queue = list.slice(); };
  sandbox.__resetFetch = () => { fetchCalls.length = 0; queue = []; };

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

function awaitOneTick() {
  // The loaders are async; drain one microtask/macrotask turn.
  return new Promise((resolve) => setTimeout(resolve, 5));
}

function jsStripComments(source) {
  return String(source)
    .replace(/\/\*[\s\S]*?\*\//g, ' ')
    .replace(/(^|\s)\/\/[^\n]*/g, '$1 ');
}

/* ════════════════════════════════════════════════════════════════
 * L1 closure — dashboard.js loader references resolve
 * ════════════════════════════════════════════════════════════════ */

function testL1Surface() {
  const sandbox = makeSandbox();
  loadRealFile(sandbox, 'dashboard.js');
  // Real enqueue order after the shell: bookings, finance, inbox, store, ai
  // (setup.php wp_enqueue_scripts(20)), then the shipped translations file.
  loadRealFile(sandbox, 'modules/bookings.js');
  loadRealFile(sandbox, 'modules/finance.js');
  loadRealFile(sandbox, 'modules/inbox.js');
  loadRealFile(sandbox, 'modules/store.js');
  loadRealFile(sandbox, 'modules/ai.js');
  loadRealFile(sandbox, 'modules/translations.js');

  const needed = ['loadInbox', 'loadFinance', 'loadPaymentMethods', 'loadSavedTokens',
    'loadProducts', 'loadAppointmentsPanel', 'loadNotifications', 'loadFinanceSummary',
    'clearNotifs', 'markNotifRead', 'markInboxRead', 'sendInboxMessage', 'hossamAiRun'];
  const missing = needed.filter((name) => typeof sandbox[name] !== 'function');
  check('B10J-1-l1-loader-surface',
    missing.length === 0,
    'every loader referenced by dashboard.js nav/TAB_LOADERS/polling resolves after the batch-10 modules load (§15.24 L1 closed)',
    'missing loaders: ' + JSON.stringify(missing));
}

/* ════════════════════════════════════════════════════════════════
 * bookings.js
 * ════════════════════════════════════════════════════════════════ */

function bookingsSandbox() {
  const sandbox = makeSandbox();
  loadRealFile(sandbox, 'dashboard.js');
  loadRealFile(sandbox, 'modules/bookings.js');
  const wrap = makeNode();
  wrap.dataset.lazyNonce = 'lazy-panel-nonce';
  sandbox.__elements['appointments-panel-wrap'] = wrap;
  sandbox.__wrap = wrap;
  return sandbox;
}

async function testBookings() {
  // Success renders server html and latches the loaded guard
  let sandbox = bookingsSandbox();
  sandbox.__stageResponses([{
    ok: true, status: 200,
    json: async () => ({ success: true, data: { html: '<div class="appt-row">OK</div>' } }),
  }]);
  await sandbox.loadAppointmentsPanel();
  await sandbox.loadAppointmentsPanel();
  check('B10J-2-bookings-success-loaded-guard',
    sandbox.__wrap._innerHTML.includes('appt-row') && sandbox.__wrap.dataset.loaded === '1'
      && sandbox.__fetchCalls.length === 1,
    'success renders the server html once; the loaded guard collapses repeat opens into one request',
    'success/loaded-guard broken: ' + sandbox.__wrap._innerHTML);

  // Per-panel lazy nonce (not the global hossamAjax.nonce)
  check('B10J-3-bookings-lazy-nonce',
    sandbox.__fetchCalls[0].opts.body.get('nonce') === 'lazy-panel-nonce'
      && sandbox.__fetchCalls[0].opts.body.get('action') === 'hossam_lazy_appointments',
    'the lazy panel posts its own data-lazy-nonce value with action hossam_lazy_appointments',
    'lazy nonce contract broken: ' + sandbox.__fetchCalls[0].opts.body.get('nonce'));

  // Empty html → noAppointments inline fallback
  sandbox = bookingsSandbox();
  sandbox.__stageResponses([{
    ok: true, status: 200,
    json: async () => ({ success: true, data: { html: '' } }),
  }]);
  await sandbox.loadAppointmentsPanel();
  check('B10J-4-bookings-empty-state',
    sandbox.__wrap._innerHTML.includes('No appointments yet.'),
    'an empty html payload renders the inline-fallback empty notice',
    'empty state broken: ' + sandbox.__wrap._innerHTML);

  // Failure classification matrix
  const matrix = [
    [401, { success: false, data: { code: 'unauthorized' } }, 'You do not have permission to view appointments.'],
    [503, { success: false, data: { code: 'plugin_missing' } }, 'Appointment integration is unavailable.'],
    [500, { success: false, data: {} }, 'Could not load appointments.'],
  ];
  let bookingsMatrixOk = true;
  for (const [status, body, expected] of matrix) {
    sandbox = bookingsSandbox();
    sandbox.__stageResponses([{ ok: false, status, json: async () => body }]);
    await sandbox.loadAppointmentsPanel();
    if (!sandbox.__wrap._innerHTML.includes(expected)) bookingsMatrixOk = false;
  }
  check('B10J-5-bookings-failure-matrix',
    bookingsMatrixOk,
    '401/403-class → forbidden message, 503/plugin_missing → integration-unavailable message, other failures → load-failed message',
    'bookings failure classification matrix broken');

  // Malformed JSON and non-string html → contractError + Retry
  sandbox = bookingsSandbox();
  sandbox.__stageResponses([{ ok: true, status: 200, json: async () => ({}), text: async () => 'not-json' }]);
  await sandbox.loadAppointmentsPanel();
  const malformedHtml = sandbox.__wrap._innerHTML;
  sandbox = bookingsSandbox();
  sandbox.__stageResponses([{
    ok: true, status: 200,
    json: async () => ({ success: true, data: {} }),
  }]);
  await sandbox.loadAppointmentsPanel();
  const contractHtml = sandbox.__wrap._innerHTML;
  check('B10J-6-bookings-contract-violations',
    malformedHtml.includes('Invalid server response') && malformedHtml.includes('Retry')
      && contractHtml.includes('Invalid server response') && contractHtml.includes('Retry'),
    'malformed JSON and a non-string html field both render the contract-error notice with a Retry control',
    'contract violation handling broken: ' + malformedHtml + ' | ' + contractHtml);

  // Network error → networkError + Retry, loading flag reset in finally
  sandbox = bookingsSandbox();
  sandbox.__stageResponses([async () => { throw new Error('ECONNREFUSED'); }]);
  await sandbox.loadAppointmentsPanel();
  check('B10J-7-bookings-network-error',
    sandbox.__wrap._innerHTML.includes('Network error') && sandbox.__wrap._innerHTML.includes('Retry')
      && sandbox.__wrap.dataset.loading === '0',
    'a transport failure renders the network-error notice with Retry and resets the loading flag',
    'network error handling broken: ' + sandbox.__wrap._innerHTML);
}

/* ════════════════════════════════════════════════════════════════
 * finance.js
 * ════════════════════════════════════════════════════════════════ */

function financeSandbox({ withKpi = false, preload = [] } = {}) {
  const sandbox = makeSandbox();
  if (withKpi) sandbox.__elements['finance-kpi'] = makeNode();
  // finance.js auto-runs loadFinanceSummary() during its own load, so any
  // responses for that auto-init must be staged BEFORE the module loads.
  sandbox.__stageResponses(preload);
  loadRealFile(sandbox, 'dashboard.js');
  loadRealFile(sandbox, 'modules/finance.js');
  for (const id of ['finance-table-wrap', 'finance-orders-wrap', 'store-orders-wrap']) {
    sandbox.__elements[id] = makeNode();
  }
  return sandbox;
}

async function testFinance() {
  // Success: table into all three target wraps, statuses filter, pagination
  let sandbox = financeSandbox();
  sandbox.__elements['finance-invoices-wrap'] = makeNode();
  sandbox.__stageResponses([{
    ok: true, status: 200,
    json: async () => ({
      success: true,
      data: {
        orders: [{ id: 9, date: '2026', total: '500', status: 'Processing', status_slug: 'processing', payment_method: 'tabby', item_count: 2, view_url: 'https://site.test/invoice/9' }],
        has_more: true,
        page: 2,
        statuses: { processing: 'Processing' },
      },
    }),
  }]);
  await sandbox.loadFinance(2);
  const ordersHtml = sandbox.__elements['finance-table-wrap']._innerHTML;
  check('B10J-8-finance-orders-success',
    ordersHtml.includes('fin-tbl') && ordersHtml.includes('Processing')
      && ordersHtml.includes('loadFinance(1)') && ordersHtml.includes('loadFinance(3)')
      && ordersHtml.includes('All statuses')
      && sandbox.__elements['store-orders-wrap']._innerHTML === ordersHtml
      && sandbox.__elements['finance-orders-wrap']._innerHTML === ordersHtml,
    'orders render into every target wrap (finance + store Orders tab) with the statuses filter and prev/next pagination',
    'orders render broken: ' + ordersHtml);

  const invoicesHtml = sandbox.__elements['finance-invoices-wrap']._innerHTML;
  check('B10J-9-finance-invoices',
    invoicesHtml.includes('invoice/9') && invoicesHtml.includes('financeColId') === false
      && invoicesHtml.includes('#'),
    'invoiced orders render in the invoices wrap from the same page data',
    'invoices render broken: ' + invoicesHtml);

  // Zero state
  sandbox = financeSandbox();
  sandbox.__elements['finance-invoices-wrap'] = makeNode();
  sandbox.__stageResponses([{
    ok: true, status: 200,
    json: async () => ({ success: true, data: { orders: [], has_more: false, page: 1 } }),
  }]);
  await sandbox.loadFinance(1);
  check('B10J-10-finance-zero-state',
    sandbox.__elements['finance-table-wrap']._innerHTML.includes('No payment records yet.')
      && sandbox.__elements['finance-invoices-wrap']._innerHTML.includes('No invoices yet.'),
    'an empty orders page renders the no-payments and no-invoices empty notices',
    'zero state broken');

  // Server error surfaces (independent from other tabs)
  sandbox = financeSandbox();
  sandbox.__stageResponses([{ ok: false, status: 403, json: async () => ({ success: false, data: { message: 'DENIED' } }) }]);
  await sandbox.loadFinance(1);
  const deniedHtml = sandbox.__elements['finance-table-wrap']._innerHTML;
  sandbox = financeSandbox();
  sandbox.__stageResponses([{ ok: true, status: 200, json: async () => ({}), text: async () => 'garbage{' }]);
  await sandbox.loadFinance(1);
  const malformedHtml = sandbox.__elements['finance-table-wrap']._innerHTML;
  check('B10J-11-finance-errors',
    deniedHtml.includes('DENIED') && deniedHtml.includes('Retry')
      && malformedHtml.includes('Network error') && malformedHtml.includes('Retry'),
    'a 403 surfaces the server message; malformed JSON falls back to the network-error message — both with Retry',
    'finance error handling broken: ' + deniedHtml + ' | ' + malformedHtml);

  // KPI summary: auto-init at load + month range. The auto-init runs during
  // module load, so its responses are staged before the module loads.
  sandbox = financeSandbox({
    withKpi: true,
    preload: [
      { ok: true, status: 200, json: async () => ({ success: true, data: { total_revenue: '1,200', pending_count: 2 } }) },
      { ok: true, status: 200, json: async () => ({ success: true, data: { total_revenue: '300' } }) },
    ],
  });
  await awaitOneTick();
  await awaitOneTick();
  const kpiHtml = sandbox.__elements['finance-kpi']._innerHTML;
  check('B10J-12-finance-kpi-summary',
    sandbox.__fetchCalls.length === 2
      && sandbox.__fetchCalls[0].opts.body.get('action') === 'hossam_finance_summary'
      && kpiHtml.includes('1,200') && kpiHtml.includes('300') && kpiHtml.includes('2'),
    'the summary auto-initialises at module load with two requests (all + month) and renders the three KPI cards',
    'KPI summary broken: calls=' + sandbox.__fetchCalls.length + ' html=' + kpiHtml);

  // Auto-init error path: with no staged response the auto-run consumes the
  // failed response and renders the loadFailed fallback, leaving no empty widget.
  sandbox = financeSandbox({ withKpi: true });
  await awaitOneTick();
  const kpiErrorHtml = sandbox.__elements['finance-kpi']._innerHTML;
  check('B10J-35-finance-kpi-autoinit-error',
    kpiErrorHtml.includes('Could not load finance summary.'),
    'the auto-init renders the loadFailed fallback when its request fails',
    'KPI auto-init error path broken: ' + kpiErrorHtml);

  // Payment methods + saved tokens (last4 only)
  sandbox = financeSandbox();
  sandbox.__elements['finance-payment-methods-wrap'] = makeNode();
  sandbox.__elements['finance-saved-cards-wrap'] = makeNode();
  sandbox.__stageResponses([
    { ok: true, status: 200, json: async () => ({ success: true, data: [{ title: 'Tabby', enabled: true }, { name: 'COD', enabled: false }] }) },
    { ok: true, status: 200, json: async () => ({ success: true, data: [{ last4: '4242', pan: '4111111111111111', brand: 'visa' }] }) },
  ]);
  await sandbox.loadPaymentMethods();
  await sandbox.loadSavedTokens();
  const pmHtml = sandbox.__elements['finance-payment-methods-wrap']._innerHTML;
  const tokensHtml = sandbox.__elements['finance-saved-cards-wrap']._innerHTML;
  check('B10J-13-finance-methods-tokens',
    pmHtml.includes('Tabby') && pmHtml.includes('Enabled') && pmHtml.includes('Disabled')
      && tokensHtml.includes('4242') && !tokensHtml.includes('4111111111111111') && !tokensHtml.includes('visa'),
    'payment methods render enabled/disabled states; saved cards expose the last4 only — no PAN or brand leaks',
    'methods/tokens broken: ' + pmHtml + ' | ' + tokensHtml);

  // Empty methods/tokens
  sandbox = financeSandbox();
  sandbox.__elements['finance-payment-methods-wrap'] = makeNode();
  sandbox.__elements['finance-saved-cards-wrap'] = makeNode();
  sandbox.__stageResponses([
    { ok: true, status: 200, json: async () => ({ success: true, data: [] }) },
    { ok: true, status: 200, json: async () => ({ success: true, data: [] }) },
  ]);
  await sandbox.loadPaymentMethods();
  await sandbox.loadSavedTokens();
  check('B10J-14-finance-methods-tokens-empty',
    sandbox.__elements['finance-payment-methods-wrap']._innerHTML.includes('No payment methods yet.')
      && sandbox.__elements['finance-saved-cards-wrap']._innerHTML.includes('No saved cards yet.'),
    'empty payment methods and saved cards render their empty notices',
    'methods/tokens empty states broken');
}

/* ════════════════════════════════════════════════════════════════
 * inbox.js
 * ════════════════════════════════════════════════════════════════ */

function inboxSandbox() {
  const sandbox = makeSandbox();
  loadRealFile(sandbox, 'dashboard.js');
  loadRealFile(sandbox, 'modules/inbox.js');
  for (const id of ['notif-badge', 'notif-list', 'inbox-badge', 'hdr-inbox-badge', 'inbox-container', 'toast-container']) {
    sandbox.__elements[id] = makeNode();
  }
  return sandbox;
}

async function testInbox() {
  // Notifications: unread badge + escaped render
  let sandbox = inboxSandbox();
  sandbox.__stageResponses([{
    ok: true, status: 200,
    json: async () => ({
      success: true,
      data: [
        { id: 1, is_read: '0', message_text: '<img src=x onerror=alert(1)>', url: '', created_at: '2026' },
        { id: 2, is_read: '1', message: 'Read one', url: '', created_at: '2026' },
      ],
    }),
  }]);
  await sandbox.loadNotifications();
  check('B10J-15-inbox-notifications',
    String(sandbox.__elements['notif-badge'].textContent) === '1'
      && sandbox.__elements['notif-list']._innerHTML.includes('&lt;img')
      && !sandbox.__elements['notif-list']._innerHTML.includes('<img')
      && sandbox.__elements['notif-list']._innerHTML.includes('markNotifRead(1'),
    'notifications update the unread badge and render escaped messages through markNotifRead rows',
    'notifications render broken: badge=' + sandbox.__elements['notif-badge'].textContent);

  // Notifications empty + error
  sandbox = inboxSandbox();
  sandbox.__stageResponses([{ ok: true, status: 200, json: async () => ({ success: true, data: [] }) }]);
  await sandbox.loadNotifications();
  const emptyHtml = sandbox.__elements['notif-list']._innerHTML;
  sandbox = inboxSandbox();
  sandbox.__stageResponses([{ ok: false, status: 403, json: async () => ({ success: false, data: { message: 'DENIED' } }) }]);
  await sandbox.loadNotifications();
  const errorHtml = sandbox.__elements['notif-list']._innerHTML;
  check('B10J-16-inbox-notifications-empty-error',
    emptyHtml.includes('No notifications at this time.') && errorHtml.includes('DENIED'),
    'an empty notifications list renders the empty notice; a failure surfaces the server message',
    'notifications empty/error broken: ' + emptyHtml + ' | ' + errorHtml);

  // Malformed body and transport failure through the shared request helper
  // (review follow-up: stage the helper's own single-line deltas — malformed
  // JSON and a network throw — so inbox has direct malformed/network stages
  // like the other modules, not only the shared !ok branch).
  sandbox = inboxSandbox();
  sandbox.__stageResponses([{ ok: true, status: 200, json: async () => ({}), text: async () => 'not-json' }]);
  await sandbox.loadNotifications();
  const malformedNotifHtml = sandbox.__elements['notif-list']._innerHTML;
  sandbox = inboxSandbox();
  sandbox.__stageResponses([async () => { throw new Error('ECONNRESET'); }]);
  await sandbox.loadNotifications();
  const networkNotifHtml = sandbox.__elements['notif-list']._innerHTML;
  check('B10J-36-inbox-malformed-network',
    malformedNotifHtml.includes('Invalid server response') && networkNotifHtml.includes('Network error'),
    'a malformed notifications body renders the contract-error message and a transport failure renders the network-error message',
    'inbox malformed/network stages broken: ' + malformedNotifHtml + ' | ' + networkNotifHtml);

  // Inbox badges from two badge sources + zeroing on empty
  sandbox = inboxSandbox();
  sandbox.__stageResponses([{
    ok: true, status: 200,
    json: async () => ({
      success: true,
      data: [
        { id: 5, read_at: null, sender_name: 'Hossam', message: 'Hello', created_at: '2026' },
        { id: 6, read_at: null, sender_name: 'Hossam', message: 'Again', created_at: '2026' },
      ],
    }),
  }]);
  await sandbox.loadInbox();
  const badgeState = sandbox.__elements['inbox-badge'].textContent;
  const hdrBadgeState = sandbox.__elements['hdr-inbox-badge'].textContent;
  check('B10J-17-inbox-badges-two-sources',
    String(badgeState) === '2' && String(hdrBadgeState) === '2'
      && sandbox.__elements['inbox-container']._innerHTML.includes('Hello')
      && sandbox.__elements['inbox-container']._innerHTML.includes('From: User #'),
    'loadInbox updates both badge sources (panel + header) with the unread count and renders sender lines',
    'inbox badges broken: ' + badgeState + '/' + hdrBadgeState);

  sandbox = inboxSandbox();
  sandbox.__stageResponses([{ ok: true, status: 200, json: async () => ({ success: true, data: [] }) }]);
  await sandbox.loadInbox();
  check('B10J-18-inbox-empty-zero-badges',
    sandbox.__elements['inbox-container']._innerHTML.includes('No messages yet.')
      && String(sandbox.__elements['inbox-badge'].textContent) === '',
    'an empty inbox renders the empty notice and zeroes the badges',
    'inbox empty state broken');

  // markNotifRead: unread marks + reloads; already-read navigates without a request
  sandbox = inboxSandbox();
  sandbox.__stageResponses([
    { ok: true, status: 200, json: async () => ({ success: true, data: {} }) },
    { ok: true, status: 200, json: async () => ({ success: true, data: [] }) },
  ]);
  const unreadEl = { classList: { contains: () => true, remove() {} } };
  await sandbox.markNotifRead(7, unreadEl, { target: { closest: () => null } });
  check('B10J-19-inbox-mark-notif-read',
    sandbox.__fetchCalls.length === 2
      && sandbox.__fetchCalls[0].opts.body.get('action') === 'hossam_mark_read'
      && sandbox.__fetchCalls[0].opts.body.get('notification_id') === '7',
    'an unread notification posts hossam_mark_read with its id and reloads the list',
    'mark notif read broken: calls=' + sandbox.__fetchCalls.length);

  sandbox = inboxSandbox();
  const readEl = { classList: { contains: () => false } };
  await sandbox.markNotifRead(8, readEl, { target: { closest: () => ({ href: 'https://site.test/?p=5' }) }, preventDefault() {} });
  check('B10J-20-inbox-already-read-navigates',
    sandbox.__fetchCalls.length === 0 && sandbox.location.assigned === 'https://site.test/?p=5',
    'an already-read notification with a link navigates directly without a server round-trip (navigation race handled)',
    'already-read navigation broken: assigned=' + sandbox.location.assigned);

  // sendInboxMessage: validation, success, failure
  sandbox = inboxSandbox();
  sandbox.__elements['inbox-to'] = makeNode({ value: '' });
  sandbox.__elements['inbox-msg'] = makeNode({ value: '' });
  await sandbox.sendInboxMessage();
  const validationCalls = sandbox.__fetchCalls.length;
  const validationToast = sandbox.__elements['toast-container'].children.length;

  sandbox.__elements['inbox-to'].value = '3';
  sandbox.__elements['inbox-msg'].value = 'Salam';
  sandbox.__stageResponses([
    { ok: true, status: 200, json: async () => ({ success: true, data: {} }) },
    { ok: true, status: 200, json: async () => ({ success: true, data: [] }) },
  ]);
  await sandbox.sendInboxMessage();
  check('B10J-21-inbox-send-message',
    validationCalls === 0 && validationToast === 1
      && sandbox.__fetchCalls.length === 2
      && sandbox.__fetchCalls[0].opts.body.get('action') === 'hossam_send_message'
      && sandbox.__fetchCalls[0].opts.body.get('receiver_id') === '3'
      && sandbox.__elements['inbox-to'].value === '' && sandbox.__elements['inbox-msg'].value === '',
    'sending validates both fields (no request, error toast), then posts to the receiver and clears the fields on success',
    'send message flow broken: validationCalls=' + validationCalls + ' calls=' + sandbox.__fetchCalls.length);

  sandbox = inboxSandbox();
  sandbox.__elements['inbox-to'] = makeNode({ value: '3' });
  sandbox.__elements['inbox-msg'] = makeNode({ value: 'Salam' });
  sandbox.__stageResponses([{ ok: false, status: 429, json: async () => ({ success: false, data: { message: 'QUOTA' } }) }]);
  await sandbox.sendInboxMessage();
  const failToast = sandbox.__elements['toast-container'].children[0];
  check('B10J-22-inbox-send-failure-toast',
    failToast && failToast._innerHTML.includes('QUOTA'),
    'a failed send surfaces the server message through the error toast',
    'send failure toast broken');

  // clearNotifs: mark all + reload + toast
  sandbox = inboxSandbox();
  sandbox.__stageResponses([
    { ok: true, status: 200, json: async () => ({ success: true, data: {} }) },
    { ok: true, status: 200, json: async () => ({ success: true, data: [] }) },
  ]);
  await sandbox.clearNotifs();
  check('B10J-23-inbox-clear-notifs',
    sandbox.__fetchCalls.length === 2
      && sandbox.__fetchCalls[0].opts.body.get('action') === 'hossam_mark_all_read'
      && sandbox.__elements['toast-container'].children.length === 1,
    'mark-all-read posts once, reloads notifications, and shows the confirmation toast',
    'clear notifs broken: calls=' + sandbox.__fetchCalls.length);
}

/* ════════════════════════════════════════════════════════════════
 * store.js
 * ════════════════════════════════════════════════════════════════ */

function storeSandbox() {
  const sandbox = makeSandbox();
  loadRealFile(sandbox, 'dashboard.js');
  loadRealFile(sandbox, 'modules/store.js');
  sandbox.__elements['store-products-wrap'] = makeNode();
  sandbox.__wrap = sandbox.__elements['store-products-wrap'];
  return sandbox;
}

async function testStore() {
  // Page 1 with has_more → next only; page 2 → prev only
  let sandbox = storeSandbox();
  sandbox.__stageResponses([{
    ok: true, status: 200,
    json: async () => ({ success: true, data: { products: [{ name: 'Contract', price: '100', stock: '5' }], page: 1, has_more: true } }),
  }]);
  await sandbox.loadProducts(1);
  const page1Html = sandbox.__wrap._innerHTML;
  sandbox.__stageResponses([{
    ok: true, status: 200,
    json: async () => ({ success: true, data: { products: [{ name: 'Deed', price: '200', stock: '9' }], page: 2, has_more: false } }),
  }]);
  await sandbox.loadProducts(2);
  const page2Html = sandbox.__wrap._innerHTML;
  check('B10J-24-store-pagination',
    page1Html.includes('Contract') && page1Html.includes('loadProducts(2)') && !page1Html.includes('loadProducts(0)')
      && page2Html.includes('Deed') && page2Html.includes('loadProducts(1)') && !page2Html.includes('loadProducts(3)')
      && sandbox.__fetchCalls[1].opts.body.get('page') === '2',
    'products paginate by page with next-only on page 1 (has_more) and prev-only on the last page',
    'store pagination broken: ' + page1Html + ' | ' + page2Html);

  // Stale page → contract violation (duplicate prevention)
  sandbox = storeSandbox();
  sandbox.__stageResponses([{
    ok: true, status: 200,
    json: async () => ({ success: true, data: { products: [{ name: 'X', price: '1', stock: '1' }], page: 2, has_more: true } }),
  }]);
  await sandbox.loadProducts(1);
  check('B10J-25-store-stale-page-contract',
    sandbox.__wrap._innerHTML.includes('Invalid server response.') && sandbox.__wrap._innerHTML.includes('Retry')
      && !sandbox.__wrap._innerHTML.includes('>X<'),
    'a response whose page does not match the request violates the contract and renders the retry notice (no stale rows)',
    'stale page handling broken: ' + sandbox.__wrap._innerHTML);

  // Empty state + loading guard + esc'd server error
  sandbox = storeSandbox();
  sandbox.__stageResponses([{ ok: true, status: 200, json: async () => ({ success: true, data: { products: [], page: 1, has_more: false } }) }]);
  await sandbox.loadProducts(1);
  const emptyHtml = sandbox.__wrap._innerHTML;
  sandbox = storeSandbox();
  sandbox.loadProducts(1);
  await sandbox.loadProducts(1);
  await awaitOneTick();
  const guardCalls = sandbox.__fetchCalls.length;
  sandbox = storeSandbox();
  sandbox.__stageResponses([{ ok: false, status: 403, json: async () => ({ success: false, data: { message: '<b>DENIED</b>' } }) }]);
  await sandbox.loadProducts(1);
  const errorHtml = sandbox.__wrap._innerHTML;
  check('B10J-26-store-empty-guard-error',
    emptyHtml.includes('No products yet.') && guardCalls === 1
      && errorHtml.includes('&lt;b&gt;DENIED&lt;/b&gt;') && !errorHtml.includes('<b>DENIED'),
    'the empty state renders, the loading guard collapses concurrent loads, and server errors render escaped',
    'store empty/guard/error broken: ' + emptyHtml + ' guard=' + guardCalls + ' ' + errorHtml);
}

/* ════════════════════════════════════════════════════════════════
 * ai.js
 * ════════════════════════════════════════════════════════════════ */

function aiSandbox() {
  const sandbox = makeSandbox();
  loadRealFile(sandbox, 'dashboard.js');
  loadRealFile(sandbox, 'modules/ai.js');
  sandbox.__elements['ai-result'] = makeNode();
  sandbox.__result = sandbox.__elements['ai-result'];
  return sandbox;
}

async function testAi() {
  // The poll promise only settles when the test drives interval ticks (the
  // sandboxed setInterval registers no real timer). Awaiting hossamAiRun
  // before ticking would deadlock: the event loop drains and Node exits
  // silently. So each run is kicked off WITHOUT await, drained with real
  // short timers until the poll interval registers, driven by manual
  // ticks, and only then awaited. The kicker is deliberately NOT async —
  // an async helper returning the run promise would re-introduce the
  // deadlock through promise assimilation.
  const kickAi = (sandbox, jobType, data, resultElId) =>
    sandbox.hossamAiRun(jobType, data, resultElId);
  const drainUntilPollRegisters = async () => {
    await awaitOneTick();
    await awaitOneTick();
  };

  // Submit whitelist + pending → completed render
  let sandbox = aiSandbox();
  let pollTimerId = null;
  sandbox.setInterval = (fn, ms) => { pollTimerId = sandbox.__intervals.size + 1; sandbox.__intervals.set(pollTimerId, { fn, ms, cleared: false }); return pollTimerId; };
  sandbox.__stageResponses([
    { ok: true, status: 200, json: async () => ({ success: true, data: { job_id: 77 } }) },
    { ok: true, status: 200, json: async () => ({ success: true, data: { status: 'pending' } }) },
    { ok: true, status: 200, json: async () => ({ success: true, data: { status: 'completed', result: { text: '<b>Suggestion</b>' } } }) },
  ]);
  const run27 = kickAi(sandbox, 'grammar', { content: 'Draft text' }, 'ai-result');
  await drainUntilPollRegisters();
  await sandbox.__tickInterval(pollTimerId);
  await sandbox.__tickInterval(pollTimerId);
  await run27;
  const submitBody = sandbox.__fetchCalls[0].opts.body;
  const noForbiddenFields = !submitBody.has('strategy_id') && !submitBody.has('provider')
    && !submitBody.has('api_key') && !submitBody.has('model') && !submitBody.has('base_url');
  const resultHtml = sandbox.__result._innerHTML;
  check('B10J-27-ai-submit-poll-complete',
    submitBody.get('action') === 'hossam_ai_submit_job' && submitBody.get('job_type') === 'grammar'
      && submitBody.get('content') === 'Draft text' && noForbiddenFields
      && sandbox.__fetchCalls[1].opts.body.get('job_id') === '77'
      && resultHtml.includes('&lt;b&gt;Suggestion&lt;/b&gt;') && !resultHtml.includes('<b>Suggestion')
      && resultHtml.includes('js-ai-apply'),
    'submit posts only the whitelisted fields (no strategy/provider/secret), polls job_id, and renders the escaped result with Apply',
    'AI submit/poll/complete broken: calls=' + sandbox.__fetchCalls.length + ' html=' + resultHtml);

  // Submit failure → aiSubmitFailed, no polling
  sandbox = aiSandbox();
  sandbox.__stageResponses([{ ok: false, status: 403, json: async () => ({ success: false, data: { message: 'DENIED' } }) }]);
  await sandbox.hossamAiRun('grammar', { content: 'X' }, 'ai-result');
  check('B10J-28-ai-submit-failure',
    sandbox.__result._innerHTML.includes('Could not start the AI request.') && sandbox.__fetchCalls.length === 1,
    'a failed submit renders the submit-failed fallback and never starts polling',
    'submit failure broken: ' + sandbox.__result._innerHTML);

  // Poll failed → escaped error
  sandbox = aiSandbox();
  sandbox.setInterval = (fn, ms) => { pollTimerId = sandbox.__intervals.size + 1; sandbox.__intervals.set(pollTimerId, { fn, ms, cleared: false }); return pollTimerId; };
  sandbox.__stageResponses([
    { ok: true, status: 200, json: async () => ({ success: true, data: { job_id: 78 } }) },
    { ok: true, status: 200, json: async () => ({ success: true, data: { status: 'failed', error: 'PROVIDER_DOWN<script>' } }) },
  ]);
  const run29 = kickAi(sandbox, 'seo', { content: 'X' }, 'ai-result');
  await drainUntilPollRegisters();
  await sandbox.__tickInterval(pollTimerId);
  await run29;
  check('B10J-29-ai-job-failed',
    sandbox.__result._innerHTML.includes('PROVIDER_DOWN&lt;script&gt;') && !sandbox.__result._innerHTML.includes('<script>'),
    'a failed job renders the escaped server error',
    'job failed rendering broken: ' + sandbox.__result._innerHTML);

  // Transient poll failure ignored, next tick completes
  sandbox = aiSandbox();
  sandbox.setInterval = (fn, ms) => { pollTimerId = sandbox.__intervals.size + 1; sandbox.__intervals.set(pollTimerId, { fn, ms, cleared: false }); return pollTimerId; };
  sandbox.__stageResponses([
    { ok: true, status: 200, json: async () => ({ success: true, data: { job_id: 79 } }) },
    async () => { throw new Error('TRANSIENT'); },
    { ok: true, status: 200, json: async () => ({ success: true, data: { status: 'completed', result: { text: 'Recovered' } } }) },
  ]);
  const run30 = kickAi(sandbox, 'grammar', { content: 'X' }, 'ai-result');
  await drainUntilPollRegisters();
  await sandbox.__tickInterval(pollTimerId);
  await sandbox.__tickInterval(pollTimerId);
  await run30;
  check('B10J-30-ai-transient-poll-failure',
    sandbox.__result._innerHTML.includes('Recovered'),
    'one transient poll failure is ignored and the next tick completes the job',
    'transient poll handling broken: ' + sandbox.__result._innerHTML);

  // Timeout at the attempt cap (60 ticks → aiTimeout)
  sandbox = aiSandbox();
  sandbox.setInterval = (fn, ms) => { pollTimerId = sandbox.__intervals.size + 1; sandbox.__intervals.set(pollTimerId, { fn, ms, cleared: false }); return pollTimerId; };
  sandbox.__stageResponses([{ ok: true, status: 200, json: async () => ({ success: true, data: { job_id: 80 } }) }]);
  const run31 = kickAi(sandbox, 'improvement', { content: 'X' }, 'ai-result');
  await drainUntilPollRegisters();
  for (let i = 0; i < 61; i++) {
    await sandbox.__tickInterval(pollTimerId);
  }
  await run31;
  check('B10J-31-ai-poll-timeout',
    sandbox.__result._innerHTML.includes('took too long'),
    'a job that never leaves pending times out at the 60-attempt cap with the timeout message',
    'poll timeout broken: ' + sandbox.__result._innerHTML);

  // Delegated click (no inline submit): preventDefault + content from source element
  sandbox = aiSandbox();
  const sourceEl = makeNode({ value: 'Body text' });
  sandbox.__elements['hossam-edit-content'] = sourceEl;
  sandbox.__stageResponses([
    { ok: true, status: 200, json: async () => ({ success: true, data: { job_id: 81 } }) },
    { ok: true, status: 200, json: async () => ({ success: true, data: { status: 'completed', result: { text: 'Clicked' } } }) },
  ]);
  let prevented = false;
  const button = {
    dataset: { jobType: 'grammar', source: 'hossam-edit-content', result: 'ai-result' },
  };
  // ai.js registers the LAST click listener (dashboard.js's language
  // dropdown IIFE registers one earlier during shell load).
  const aiClickListener = sandbox.__listeners.click[sandbox.__listeners.click.length - 1];
  const idsBeforeClick = new Set(sandbox.__intervals.keys());
  aiClickListener({
    target: { closest: (sel) => (sel === '.js-ai-run' ? button : null) },
    preventDefault() { prevented = true; },
  });
  await awaitOneTick();
  await awaitOneTick();
  const newIds = [...sandbox.__intervals.keys()].filter((id) => !idsBeforeClick.has(id));
  for (const id of newIds) {
    await sandbox.__tickInterval(id);
  }
  check('B10J-32-ai-delegated-click',
    prevented && sandbox.__fetchCalls.length >= 1
      && sandbox.__fetchCalls[0].opts.body.get('content') === 'Body text'
      && sandbox.__result._innerHTML.includes('Clicked'),
    'the .js-ai-run delegation preventDefaults and submits the source element content (AI runs only through the listener, never inline)',
    'delegated click broken: prevented=' + prevented + ' calls=' + sandbox.__fetchCalls.length);
}

/* ════════════════════════════════════════════════════════════════
 * translations.js — server-rendered no-op
 * ════════════════════════════════════════════════════════════════ */

function testTranslations() {
  const sandbox = makeSandbox();
  loadRealFile(sandbox, 'dashboard.js');
  const before = new Set(Object.getOwnPropertyNames(sandbox));
  loadRealFile(sandbox, 'modules/translations.js');
  const added = Object.getOwnPropertyNames(sandbox).filter((name) => !before.has(name));
  check('B10J-33-translations-noop',
    sandbox.__fetchCalls.length === 0 && added.length === 0,
    'translations.js loads without defining any global and without any request — the panel is server-rendered and no code was invented',
    'translations.js is not a no-op (added globals: ' + JSON.stringify(added) + ')');
}

/* ════════════════════════════════════════════════════════════════
 * Static gate — no inline async submit surface
 * ════════════════════════════════════════════════════════════════ */

function testNoInlineAsyncSubmit() {
  const files = ['bookings.js', 'finance.js', 'inbox.js', 'store.js', 'translations.js', 'ai.js'];
  let violations = [];
  for (const file of files) {
    const stripped = jsStripComments(fs.readFileSync(path.join(runtimeAssets, 'modules', file), 'utf8'));
    if (/onsubmit/i.test(stripped)) violations.push(file + ': onsubmit');
    if (/javascript:/i.test(stripped)) violations.push(file + ': javascript: URL');
    if (/onclick\s*=\s*["']hossamAiRun/.test(stripped)) violations.push(file + ': inline AI submit');
  }
  check('B10J-34-no-inline-async-submit',
    violations.length === 0,
    'no shipped module registers an inline submit surface (onsubmit / javascript: / inline hossamAiRun) — submission flows run through delegated listeners with preventDefault',
    'inline submit violations: ' + JSON.stringify(violations));
}

/* ════════════════════════════════════════════════════════════════
 * Run
 * ════════════════════════════════════════════════════════════════ */

(async function main() {
  testL1Surface();
  await testBookings();
  await testFinance();
  await testInbox();
  await testStore();
  await testAi();
  testTranslations();
  testNoInlineAsyncSubmit();

  console.log(`B10J RESULT: ${passCount} pass, ${failCount} fail (node ${process.version})`);
  if (failCount > 0) {
    console.log('Failures:\n' + failures.join('\n'));
    process.exit(1);
  }
  process.exit(0);
})().catch((error) => {
  console.error('HARNESS ERROR:', error);
  process.exit(1);
});
