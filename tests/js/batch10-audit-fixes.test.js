/**
 * HAL Frontend Dashboard — Batch 10 audit-fix harness (B10-01 / B10-02).
 *
 * Plain Node runner (no dependencies, no live browser, no network, no
 * WordPress): loads the REAL runtime/assets/js files into sandboxed VM
 * contexts with minimal DOM/network stubs and drives the two audit items.
 * No project logic is reimplemented; all behavior is asserted through the
 * real callbacks with staged fetch responses.
 *
 *   B10-01 (§21 inbox badges): the server list is capped at the latest 50
 *         rows with no total; the badges render the exact server-side
 *         COUNT(*) total at shell time. The fix writes badges from a list
 *         only when provably complete (<50 rows) and decrements the exact
 *         displayed total on a confirmed single read.
 *         B10F-1: 51 unread (50-row capped list) keeps the server total 51.
 *         B10F-2: 50 read rows + one old unread beyond keeps badges at 1.
 *         B10F-3: short list (<50) still sets the exact count.
 *         B10F-4: confirmed read decrements 51 -> 50 once on both badges;
 *                 a subsequent capped 50-row list cannot mask a second decrement.
 *         B10F-5: failed read (404) keeps 51 on both badges.
 *   B10-02 (§21 AI terminal stability): settled/inFlight/epoch guards.
 *         B10F-6: two un-awaited ticks issue a single status request.
 *         B10F-7: timeout then a late completed response keeps timeout.
 *         B10F-8: a newer attempt on the same element wins; the older
 *                 late response is ignored, the newer completes normally.
 *         B10F-9: ordinary polling success still renders + Apply.
 *         B10F-10: one transient poll failure is ignored, next completes.
 *         B10F-11: reverse submit completion does not restore the old attempt.
 *         B10F-12: an old submit failure does not overwrite the newer attempt.
 *         B10F-13: an old poll response during a new submit is ignored.
 *
 * Usage:  node tests/js/batch10-audit-fixes.test.js   (exit 0 = all pass)
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

function escaped(value) {
  return String(value)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function makeNode(extra = {}) {
  const n = {
    tagName: 'DIV', className: '', style: {}, dataset: {}, children: [],
    _innerHTML: '', _text: '',
    classList: { add() {}, remove() {}, toggle() { return false; }, contains() { return false; } },
    setAttribute() {}, getAttribute() { return null; },
    appendChild(c) { n.children.push(c); return c; },
    insertAdjacentHTML(p, h) { n._innerHTML += h; },
    remove() {},
    querySelector() { return null; }, querySelectorAll() { return []; },
    closest() { return null; }, focus() {}, click() {},
    ...extra,
  };
  Object.defineProperty(n, 'innerHTML', {
    get() { return n._fromCreate ? escaped(n._text || '') : n._innerHTML; },
    set(v) { n._innerHTML = String(v); },
  });
  Object.defineProperty(n, 'textContent', {
    get() { return n._text || ''; },
    set(v) { n._text = String(v); },
  });
  return n;
}

function makeSandbox() {
  const sb = {};
  sb.window = sb; sb.globalThis = sb;
  // Product code console.warns on expected failure paths (markInboxRead
  // 404); silence it so harness output shows only verdict lines.
  sb.console = { log: (...a) => console.log(...a), warn: () => {}, error: (...a) => console.error(...a) };
  sb.URL = URL; sb.URLSearchParams = URLSearchParams; sb.FormData = FormData;
  sb.hossamAjax = { ajaxurl: 'https://example.test/wp-admin/admin-ajax.php', nonce: 't', i18n: {} };
  const els = {}; sb.__elements = els;
  sb.document = {
    title: '', dataset: {},
    getElementById: (id) => els[id] || null,
    querySelector: () => null, querySelectorAll: () => [],
    addEventListener() {},
    createElement: () => makeNode({ _fromCreate: true }),
  };
  sb.localStorage = { getItem: () => null, setItem() {}, removeItem() {} };
  sb.location = { href: 'https://site.test/', hash: '', reload() {}, assign(u) { sb.__assigned = u; } };
  sb.history = { replaceState() {} };
  sb.setTimeout = () => 0; sb.clearTimeout = () => {}; sb.confirm = () => true;
  // Dashboard shell top-level needs these before ai.js overrides intervals.
  sb.setInterval = () => 0; sb.clearInterval = () => {};
  const calls = []; sb.__fetchCalls = calls;
  let queue = [];
  sb.fetch = async (url, opts) => {
    calls.push({ url, opts });
    let next = queue.length ? queue.shift() : { ok: false, status: 599, json: async () => ({ success: false }) };
    if (typeof next === 'function') { next = await next(); }
    if (typeof next.text !== 'function') {
      const s = next;
      next = { ...s, text: async () => JSON.stringify(await s.json()) };
    }
    return next;
  };
  sb.__stageResponses = (list) => { queue = list.slice(); };
  vm.createContext(sb);
  return sb;
}

function loadReal(sb, rel) {
  vm.runInContext(fs.readFileSync(path.join(runtimeAssets, rel), 'utf8'), sb, { filename: `runtime/assets/js/${rel}` });
}

function controllableIntervals(sb) {
  const reg = new Map(); let seq = 0;
  sb.setInterval = (fn) => { const id = ++seq; reg.set(id, { fn, cleared: false }); return id; };
  sb.clearInterval = (id) => { const e = reg.get(id); if (e) { e.cleared = true; } };
  return reg;
}

const tick = () => new Promise((r) => setTimeout(r, 5));
const msgRow = (id, read) => ({ id, read_at: read ? '2026-01-01' : null, sender_name: 'S', message: 'm', created_at: 'D' });
const okList = (rows) => ({ ok: true, status: 200, json: async () => ({ success: true, data: rows }) });

/* ── B10-01 ─────────────────────────────────────────────────────── */

async function testBadgesKeepServerTotal() {
  const sb = makeSandbox(); loadReal(sb, 'dashboard.js'); loadReal(sb, 'modules/inbox.js');
  sb.__elements['inbox-container'] = makeNode();
  sb.__elements['toast-container'] = makeNode();
  const b1 = makeNode(); const b2 = makeNode();
  b1.textContent = '51'; b2.textContent = '51'; // shell-rendered exact COUNT(*)
  sb.__elements['inbox-badge'] = b1; sb.__elements['hdr-inbox-badge'] = b2;
  sb.__stageResponses([okList(Array.from({ length: 50 }, (_, i) => msgRow(i + 1, false)))]);
  await sb.loadInbox(); await tick();
  check('B10F-1-capped-list-keeps-total',
    b1.textContent === '51' && b2.textContent === '51'
      && sb.__elements['inbox-container']._innerHTML.includes('activity-item'),
    'a capped 50-row list of 51 unread keeps the server total 51 on both badges',
    `badges deflated: ${b1.textContent}/${b2.textContent}`);
}

async function testBadgesKeepOldUnread() {
  const sb = makeSandbox(); loadReal(sb, 'dashboard.js'); loadReal(sb, 'modules/inbox.js');
  sb.__elements['inbox-container'] = makeNode();
  sb.__elements['toast-container'] = makeNode();
  const b1 = makeNode(); const b2 = makeNode();
  b1.textContent = '1'; b2.textContent = '1';
  sb.__elements['inbox-badge'] = b1; sb.__elements['hdr-inbox-badge'] = b2;
  sb.__stageResponses([okList(Array.from({ length: 50 }, (_, i) => msgRow(i + 1, true)))]);
  await sb.loadInbox(); await tick();
  check('B10F-2-old-unread-survives',
    b1.textContent === '1' && b2.textContent === '1',
    '50 read rows with one older unread beyond keep badges at 1 instead of wiping them',
    `badges wiped: ${JSON.stringify(b1.textContent)}/${JSON.stringify(b2.textContent)}`);
}

async function testBadgesExactOnShortList() {
  const sb = makeSandbox(); loadReal(sb, 'dashboard.js'); loadReal(sb, 'modules/inbox.js');
  sb.__elements['inbox-container'] = makeNode();
  sb.__elements['toast-container'] = makeNode();
  sb.__elements['inbox-badge'] = makeNode(); sb.__elements['hdr-inbox-badge'] = makeNode();
  sb.__stageResponses([okList([msgRow(1, false), msgRow(2, false), msgRow(3, true)])]);
  await sb.loadInbox(); await tick();
  check('B10F-3-short-list-sets-exact',
    sb.__elements['inbox-badge'].textContent === '2' && sb.__elements['hdr-inbox-badge'].textContent === '2',
    'a complete short list still sets the exact unread count on both badges',
    `badges wrong: ${sb.__elements['inbox-badge'].textContent}/${sb.__elements['hdr-inbox-badge'].textContent}`);
}

async function testDecrementOnConfirmedRead() {
  const sb = makeSandbox(); loadReal(sb, 'dashboard.js'); loadReal(sb, 'modules/inbox.js');
  sb.__elements['inbox-container'] = makeNode();
  sb.__elements['toast-container'] = makeNode();
  const b1 = makeNode(); const b2 = makeNode();
  b1.textContent = '51'; b2.textContent = '51';
  sb.__elements['inbox-badge'] = b1; sb.__elements['hdr-inbox-badge'] = b2;
  sb.__stageResponses([
    { ok: true, status: 200, json: async () => ({ success: true, data: {} }) },
    okList(Array.from({ length: 50 }, (_, i) => msgRow(i + 1, i === 0))),
  ]);
  await sb.markInboxRead(9, null); await tick();
  const actions = sb.__fetchCalls.map((c) => c.opts.body.get('action'));
  check('B10F-4-read-decrements-then-syncs',
    b1.textContent === '50' && b2.textContent === '50'
      && actions.length === 2 && actions[0] === 'hossam_mark_message_read' && actions[1] === 'hossam_get_inbox',
    'a confirmed read changes 51 to 50 once on both badges; the capped 50-row reload leaves that total intact',
    `badges=${b1.textContent}/${b2.textContent} actions=${JSON.stringify(actions)}`);
}

async function testNoDecrementOnFailedRead() {
  const sb = makeSandbox(); loadReal(sb, 'dashboard.js'); loadReal(sb, 'modules/inbox.js');
  sb.__elements['inbox-container'] = makeNode();
  sb.__elements['toast-container'] = makeNode();
  const b1 = makeNode(); const b2 = makeNode();
  b1.textContent = '51'; b2.textContent = '51';
  sb.__elements['inbox-badge'] = b1; sb.__elements['hdr-inbox-badge'] = b2;
  sb.__stageResponses([{ ok: false, status: 404, json: async () => ({ success: false, data: { message: 'gone' } }) }]);
  await sb.markInboxRead(9, null); await tick();
  check('B10F-5-failed-read-keeps-total',
    b1.textContent === '51' && b2.textContent === '51' && sb.__fetchCalls.length === 1,
    'a failed (404) mark sends nothing further and leaves the exact total untouched',
    `badges=${b1.textContent}/${b2.textContent} calls=${sb.__fetchCalls.length}`);
}

/* ── B10-02 ─────────────────────────────────────────────────────── */

function aiSandbox() {
  const sb = makeSandbox(); loadReal(sb, 'dashboard.js'); loadReal(sb, 'modules/ai.js');
  const reg = controllableIntervals(sb);
  const result = makeNode(); sb.__elements.r1 = result;
  sb.document.getElementById = (id) => sb.__elements[id] || null;
  return { sb, reg, result };
}
const submitOk = (jobId) => ({ ok: true, status: 200, json: async () => ({ success: true, data: { job_id: jobId } }) });
const statusOf = (status, extra) => ({ ok: true, status: 200, json: async () => ({ success: true, data: { status, ...extra } }) });
function deferred() {
  let resolve; let reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}
const settledSoon = (promise) => Promise.race([promise.then(() => true), tick().then(() => false)]);

async function testNoOverlap() {
  const { sb, reg, result } = aiSandbox();
  let releaseFirst = null;
  sb.__stageResponses([
    submitOk(11),
    () => new Promise((res) => { releaseFirst = res; }),
    statusOf('completed', { result: { text: 'done' } }),
  ]);
  const p = sb.hossamAiRun('grammar', { content: 'x' }, 'r1');
  await tick(); await tick();
  const entry = reg.get(1);
  const p1 = entry.fn(); // fetch#1 stays pending
  await tick();
  const p2 = entry.fn(); // overlapping tick while #1 pending
  await tick();
  const statusCalls = sb.__fetchCalls.filter((c) => c.opts.body.get('action') === 'hossam_ai_get_job_status').length;
  releaseFirst(statusOf('completed', { result: { text: 'done' } }));
  await p1; await p2; await p; await tick();
  check('B10F-6-no-overlapping-polls',
    statusCalls === 1 && result._innerHTML.includes('done'),
    'two ticks with the first request still pending issue a single status request and still complete',
    `status fetches issued: ${statusCalls} rendered-done=${result._innerHTML.includes('done')}`);
}

async function testTimeoutThenLateIgnored() {
  const { sb, reg, result } = aiSandbox();
  let late = null; let n = 0;
  sb.fetch = async (url, opts) => {
    sb.__fetchCalls.push({ url, opts });
    if (opts.body.get('action') === 'hossam_ai_submit_job') { return submitOk(12); }
    n += 1;
    if (n === 1) { return new Promise((res) => { late = res; }); }
    return statusOf('processing');
  };
  const p = sb.hossamAiRun('grammar', { content: 'x' }, 'r1');
  await tick(); await tick();
  const entry = reg.get(1);
  const first = entry.fn();
  for (let i = 0; i < 60; i += 1) { await entry.fn(); }
  const timedOut = result._innerHTML.includes('took too long');
  late(statusOf('completed', { result: { text: 'LATE' } }));
  await first; await p; await tick(); await tick();
  check('B10F-7-late-response-after-timeout-ignored',
    timedOut === true && result._innerHTML.includes('took too long') && !result._innerHTML.includes('LATE'),
    'after the timeout a late completed response no longer replaces it',
    `timeout=${timedOut} late-shown=${result._innerHTML.includes('LATE')}`);
}

async function testNewerAttemptWins() {
  const { sb, reg, result } = aiSandbox();
  const deferreds = [];
  const origFetch = sb.fetch;
  sb.fetch = async (url, opts) => {
    sb.__fetchCalls.push({ url, opts });
    if (opts.body.get('action') === 'hossam_ai_submit_job') {
      const id = 20 + sb.__fetchCalls.filter((c) => c.opts.body.get('action') === 'hossam_ai_submit_job').length;
      return submitOk(id);
    }
    return new Promise((res) => { deferreds.push(res); });
  };
  const pA = sb.hossamAiRun('grammar', { content: 'a' }, 'r1');
  await tick(); await tick();
  const tickA = reg.get(1).fn;
  const runA = tickA(); // A's status request stays pending
  await tick();
  const pB = sb.hossamAiRun('grammar', { content: 'b' }, 'r1'); // newer attempt, same element
  await tick(); await tick();
  const tickB = reg.get(2).fn;
  const thinkingB = result._innerHTML.includes('AI is working');
  const runB = tickB(); // B's status request stays pending
  await tick();
  deferreds[0](statusOf('completed', { result: { text: 'STALE-A' } })); // old attempt resolves late
  await runA; await tick();
  const staleIgnored = !result._innerHTML.includes('STALE-A') && result._innerHTML.includes('AI is working');
  deferreds[1](statusOf('completed', { result: { text: 'FRESH-B' } }));
  await runB; await pA; await pB; await tick(); await tick();
  sb.fetch = origFetch;
  check('B10F-8-newer-attempt-wins',
    thinkingB === true && staleIgnored === true && result._innerHTML.includes('FRESH-B'),
    'a stale response from an older attempt is ignored and the newer attempt completes normally',
    `thinkingB=${thinkingB} staleIgnored=${staleIgnored} fresh=${result._innerHTML.includes('FRESH-B')}`);
}

async function testNormalPollStillWorks() {
  const { sb, reg, result } = aiSandbox();
  sb.__stageResponses([submitOk(30), statusOf('processing'), statusOf('completed', { result: { text: 'hello' } })]);
  const p = sb.hossamAiRun('grammar', { content: 'x' }, 'r1');
  await tick(); await tick();
  const entry = reg.get(1);
  await entry.fn(); await entry.fn(); await p; await tick();
  check('B10F-9-normal-poll-success',
    result._innerHTML.includes('hello') && result._innerHTML.includes('js-ai-apply'),
    'ordinary polling (processing then completed) still renders the suggestion with Apply',
    `html=${result._innerHTML.slice(0, 120)}`);
}

async function testTransientIgnored() {
  const { sb, reg, result } = aiSandbox();
  sb.__stageResponses([
    submitOk(31),
    { ok: false, status: 500, json: async () => ({ success: false }) },
    statusOf('completed', { result: { text: 'recovered' } }),
  ]);
  const p = sb.hossamAiRun('grammar', { content: 'x' }, 'r1');
  await tick(); await tick();
  const entry = reg.get(1);
  await entry.fn(); await entry.fn(); await p; await tick();
  check('B10F-10-transient-then-complete',
    result._innerHTML.includes('recovered'),
    'one transient poll failure is ignored and the next tick completes the job',
    `html=${result._innerHTML.slice(0, 120)}`);
}

async function testReverseSubmitCompletion() {
  const { sb, reg, result } = aiSandbox();
  const submitA = deferred(); const submitB = deferred();
  sb.fetch = async (url, opts) => {
    sb.__fetchCalls.push({ url, opts });
    if (opts.body.get('action') === 'hossam_ai_submit_job') {
      return opts.body.get('content') === 'a' ? submitA.promise : submitB.promise;
    }
    return statusOf('completed', { result: { text: 'FRESH-B' } });
  };
  const pA = sb.hossamAiRun('grammar', { content: 'a' }, 'r1');
  const pB = sb.hossamAiRun('grammar', { content: 'b' }, 'r1');
  const oldSettled = await settledSoon(pA);
  submitB.resolve(submitOk(42));
  await tick(); await tick();
  const bPoll = [...reg.values()].find((entry) => !entry.cleared);
  if (bPoll) await bPoll.fn();
  await pB;
  submitA.resolve(submitOk(41));
  await tick(); await tick();
  const statusCalls = sb.__fetchCalls.filter((c) => c.opts.body.get('action') === 'hossam_ai_get_job_status').length;
  check('B10F-11-reverse-submit-completion',
    oldSettled && result._innerHTML.includes('FRESH-B') && statusCalls === 1,
    'the older run settles immediately; its submit completion after B cannot start polling or overwrite B',
    `oldSettled=${oldSettled} statusCalls=${statusCalls} html=${result._innerHTML.slice(0, 120)}`);
}

async function testOldSubmitFailureIgnored() {
  const { sb, reg, result } = aiSandbox();
  const submitA = deferred(); const submitB = deferred();
  sb.fetch = async (url, opts) => {
    sb.__fetchCalls.push({ url, opts });
    if (opts.body.get('action') === 'hossam_ai_submit_job') {
      return opts.body.get('content') === 'a' ? submitA.promise : submitB.promise;
    }
    return statusOf('completed', { result: { text: 'FRESH-B' } });
  };
  const pA = sb.hossamAiRun('grammar', { content: 'a' }, 'r1');
  const pB = sb.hossamAiRun('grammar', { content: 'b' }, 'r1');
  const oldSettled = await settledSoon(pA);
  submitB.resolve(submitOk(52));
  await tick(); await tick();
  const bPoll = [...reg.values()].find((entry) => !entry.cleared);
  if (bPoll) await bPoll.fn();
  await pB;
  submitA.reject(new Error('old submit failed'));
  await tick(); await tick();
  check('B10F-12-old-submit-failure-ignored',
    oldSettled && result._innerHTML.includes('FRESH-B') && !result._innerHTML.includes('Network error'),
    'a late failure from the superseded submit cannot replace the newer result',
    `oldSettled=${oldSettled} html=${result._innerHTML.slice(0, 120)}`);
}

async function testOldPollDuringNewSubmit() {
  const { sb, reg, result } = aiSandbox();
  const oldPoll = deferred(); const submitB = deferred();
  sb.fetch = async (url, opts) => {
    sb.__fetchCalls.push({ url, opts });
    if (opts.body.get('action') === 'hossam_ai_submit_job') {
      return opts.body.get('content') === 'a' ? submitOk(61) : submitB.promise;
    }
    return opts.body.get('job_id') === '61'
      ? oldPoll.promise : statusOf('completed', { result: { text: 'FRESH-B' } });
  };
  const pA = sb.hossamAiRun('grammar', { content: 'a' }, 'r1');
  await tick(); await tick();
  const pollA = reg.get(1).fn();
  await tick();
  const pB = sb.hossamAiRun('grammar', { content: 'b' }, 'r1');
  const oldSettled = await settledSoon(pA);
  oldPoll.resolve(statusOf('completed', { result: { text: 'STALE-A' } }));
  await pollA; await tick();
  const held = result._innerHTML.includes('AI is working') && !result._innerHTML.includes('STALE-A');
  submitB.resolve(submitOk(62));
  await tick(); await tick();
  const bPoll = [...reg.values()].find((entry) => !entry.cleared);
  if (bPoll) await bPoll.fn();
  await pB;
  check('B10F-13-old-poll-during-new-submit',
    oldSettled && held && result._innerHTML.includes('FRESH-B'),
    'an old poll response while the new submit is pending leaves the new thinking state intact',
    `oldSettled=${oldSettled} held=${held} html=${result._innerHTML.slice(0, 120)}`);
}

/* ── Run ────────────────────────────────────────────────────────── */

(async function main() {
  await testBadgesKeepServerTotal();
  await testBadgesKeepOldUnread();
  await testBadgesExactOnShortList();
  await testDecrementOnConfirmedRead();
  await testNoDecrementOnFailedRead();
  await testNoOverlap();
  await testTimeoutThenLateIgnored();
  await testNewerAttemptWins();
  await testNormalPollStillWorks();
  await testTransientIgnored();
  await testReverseSubmitCompletion();
  await testOldSubmitFailureIgnored();
  await testOldPollDuringNewSubmit();
  console.log(`B10F RESULT: ${passCount} pass, ${failCount} fail (node ${process.version})`);
  if (failCount > 0) {
    console.log('Failures:\n' + failures.join('\n'));
    process.exit(1);
  }
  process.exit(0);
})().catch((error) => {
  console.error('HARNESS ERROR:', error);
  process.exit(1);
});
