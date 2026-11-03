/**
 * HAL Frontend Dashboard — B14 T6 editor-spec gate (v1.1.0).
 *
 * Plain Node runner (no dependencies, no live browser, no network, no
 * WordPress): loads the REAL dashboard.js (and posts.js for the meter
 * probe) into a sandboxed VM context and drives the actual escAttr/esc/
 * hossamSaveSeo callbacks; the remaining contract items are measured as
 * exact static scans over the real shipped sources (two independent
 * methods where counting matters). No marker-only assertions.
 *
 * Coverage (T6 — القابل للقياس محليًا):
 *
 *   ESCATTR   — behavioral props over the real dashboard.js escAttr:
 *               the five characters, combination order (& first — no
 *               double-escape), empty/Arabic/plain strings; esc() stays
 *               the element-text escaper (sandbox DOM round-trip).
 *   CONTEXTS  — the five attribute contexts and ONLY those: dashboard.js
 *               defines escAttr (single definition, & first), inbox.js :93
 *               href, finance.js :92 class / :95 href / :104 value /
 *               :125 href — exact line anchors — and zero escAttr in any
 *               other shipped module.
 *   PASTE     — posts.js carries the paste handlers (container + iframe
 *               document) and normalizes H1→H2 in keep() BEFORE the state
 *               the textarea serialize reads (source-text order).
 *   CHIPS     — robots chips are locked to the closed four-list client
 *               side (lowercase normalize + indexOf filter).
 *   METER     — the gauge/SERP surface is client-computed with zero new
 *               fetch: hossamSaveSeo updates the score element from an
 *               existing action's response (behavioral), no 'serp'
 *               anywhere in the shipped JS.
 *   INNERHTML — the innerHTML family across ALL of runtime/assets/js is
 *               exactly 53 (50 innerHTML + 3 insertAdjacentHTML) and
 *               posts.js is 0.
 *   CSS       — zero fixed|sticky in the panel-write/panel-edit
 *               .form-actions rules (comments excluded) [H6]; the ≤768
 *               v2.8 compression rules are present with position:static;
 *               the whole-file fixed|sticky counter is exactly 4 (no new
 *               floating surface); no AI-selector floating rule; RTL-first
 *               ordering of the OEB block; the rich-editor/OEB holder
 *               classes exist in the stylesheet.
 *   NAMESPACE — every posts.js top-level function is hossam-prefixed
 *               except the two documented legacy globals; hossamOeb* =
 *               exactly 12; zero postMessage / contenteditable /
 *               execCommand across the shipped JS.
 *
 * Deferred to the live-acceptance session (recorded in the suite report,
 * not asserted here): the reference write scene on both accounts across
 * the approved path (H1 §7-1 matrix), corrected gate 9, and manual
 * window-blocker acceptance — «بانتظار جلسة القبول — H1 §7».
 *
 * Usage:  node tests/js/batch14-editor-spec.test.js   (exit 0 = all pass)
 */

'use strict';

const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const projectRoot = path.resolve(__dirname, '..', '..');
const jsRoot = path.join(projectRoot, 'runtime', 'assets', 'js');
const cssPath = path.join(projectRoot, 'runtime', 'assets', 'css', 'dashboard.css');

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

function readFile(rel) {
  return fs.readFileSync(path.join(jsRoot, rel), 'utf8');
}

function listJsFiles(dir) {
  const out = [];
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) { out.push(...listJsFiles(full)); }
    else if (entry.name.endsWith('.js')) { out.push(full); }
  }
  return out;
}

function makeNode(tag) {
  const node = {
    tagName: String(tag || 'div').toUpperCase(),
    id: '',
    className: '',
    style: {},
    dataset: {},
    _attrs: {},
    _children: [],
    parentNode: null,
    textContent: '',
    classList: {
      _set: new Set(),
      add(c) { this._set.add(c); },
      remove(c) { this._set.delete(c); },
      contains(c) { return this._set.has(c); },
    },
    setAttribute(n, v) { this._attrs[n] = String(v); },
    getAttribute(n) { return Object.prototype.hasOwnProperty.call(this._attrs, n) ? this._attrs[n] : null; },
    appendChild(child) { this._children.push(child); child.parentNode = this; return child; },
    querySelector() { return null; },
    querySelectorAll() { return []; },
  };
  return node;
}

/* Escaping-node: assigning textContent renders escaped markup on the
 * innerHTML getter — the browser semantics esc() relies on. */
function makeEscapingNode(tag) {
  const node = makeNode(tag);
  let raw = '';
  Object.defineProperty(node, 'textContent', {
    get() { return raw; },
    set(v) {
      raw = String(v);
      node._children.length = 0;
    },
  });
  Object.defineProperty(node, 'innerHTML', {
    get() {
      return raw
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
    },
    set(v) { raw = String(v); },
  });
  return node;
}

/* Sandbox for the REAL dashboard.js: only the WordPress/DOM boundary is
 * stubbed; escAttr/esc are driven as loaded. */
function makeDashboardSandbox() {
  const sandbox = {};
  sandbox.window = sandbox;
  sandbox.globalThis = sandbox;
  sandbox.console = { log() {}, warn() {}, error() {} };
  sandbox.setTimeout = () => 1;
  sandbox.clearTimeout = () => {};
  sandbox.setInterval = () => 1;
  sandbox.clearInterval = () => {};
  sandbox.localStorage = { getItem: () => null, setItem() {}, removeItem() {} };
  sandbox.location = { href: '', search: '', hash: '', reload() {} };
  sandbox.history = { replaceState() {} };
  sandbox.hossamAjax = { ajaxurl: '', nonce: '', isRtl: true, i18n: {} };
  const listeners = {};
  sandbox.__docListeners = listeners;
  sandbox.document = {
    getElementById() { return null; },
    createElement: (tag) => makeEscapingNode(tag),
    addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
    querySelector() { return null; },
    querySelectorAll() { return []; },
    documentElement: makeNode('html'),
    visibilityState: 'visible',
  };
  sandbox.addEventListener = () => {};
  vm.createContext(sandbox);
  return sandbox;
}

/* Sandbox for the REAL posts.js — minimal surface for the meter probe
 * (hossamSaveSeo through the real fetch call). */
function makePostsSandbox(opts) {
  opts = opts || {};
  const sandbox = makeDashboardSandbox();
  const elements = {};
  sandbox.__elements = elements;
  sandbox.document.getElementById = (id) => elements[id] || null;
  const toasts = [];
  sandbox.__toasts = toasts;
  sandbox.showToast = (msg, type) => { toasts.push({ msg, type }); };
  sandbox.t = (key, fallback) => fallback || key;
  sandbox.FormData = function FormData() {
    this.fields = {};
    this.append = function append(k, v) { this.fields[k] = v; };
  };
  const fetches = [];
  sandbox.__fetches = fetches;
  sandbox.fetch = (url, init) => {
    fetches.push({ url, init });
    const fd = init && init.body;
    const action = fd && fd.fields ? fd.fields.action : '';
    return Promise.resolve({
      status: 200,
      json: () => Promise.resolve({
        success: true,
        data: { fields: { seo_score: opts.seoScore || '0' } },
        __action: action,
      }),
    });
  };
  return sandbox;
}

/* ── ESCATTR behavioral props over the REAL dashboard.js ─────────── */

const dashSb = makeDashboardSandbox();
vm.runInContext(readFile('dashboard.js'), dashSb, { filename: 'runtime/assets/js/dashboard.js' });
const escAttrFn = dashSb.escAttr;
const escFn = dashSb.esc;

/* T6-1 — the five characters. */
const five = {
  '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
};
let fiveOk = typeof escAttrFn === 'function';
for (const [ch, want] of Object.entries(five)) {
  if (!fiveOk || escAttrFn(ch) !== want) { fiveOk = false; }
}
check('B14E-1-escattr-five-chars', fiveOk,
  'the real escAttr escapes the five attribute-significant characters exactly (& < > " \')',
  'escAttr five-character mapping deviated');

/* T6-2 — combinations: & escapes FIRST, so an already-escaped-looking
 * input is escaped exactly once (no double-escape). */
const combos = [
  ['&lt;script&gt;', '&amp;lt;script&amp;gt;'],
  ['&&&', '&amp;&amp;&amp;'],
  [`<a href="x"&'y'>`, '&lt;a href=&quot;x&quot;&amp;&#39;y&#39;&gt;'],
  ['"&<>', '&quot;&amp;&lt;&gt;'],
];
const comboOk = typeof escAttrFn === 'function'
  && combos.every(([input, want]) => escAttrFn(input) === want);
check('B14E-2-escattr-combos-amp-first', comboOk,
  'combination inputs are escaped once with & first (already-escaped-looking text never double-escapes)',
  'escAttr combination ordering deviated');

/* T6-3 — empty and Arabic strings pass through untouched. */
const emptyArabicOk = typeof escAttrFn === 'function'
  && escAttrFn('') === ''
  && escAttrFn('حسام عادل — محامٍ') === 'حسام عادل — محامٍ'
  && escAttrFn('plain text 123') === 'plain text 123';
check('B14E-3-escattr-empty-and-arabic', emptyArabicOk,
  'empty strings and Arabic/plain text pass through escAttr unchanged (no transliteration, no mangling)',
  'escAttr empty/Arabic passthrough deviated');

/* T6-4 — esc() stays the element-text escaper (sandbox DOM round-trip). */
const escOk = typeof escFn === 'function'
  && escFn('<b>bold</b>') === '&lt;b&gt;bold&lt;/b&gt;'
  && escFn('مرحبا') === 'مرحبا';
check('B14E-4-esc-element-context', escOk,
  'esc() escapes element text through the DOM round-trip and leaves plain/Arabic text intact (element contexts keep esc, not escAttr)',
  'esc() element-text contract deviated');

/* ── CONTEXTS — the five attribute contexts and ONLY those ───────── */

const dashSrc = readFile('dashboard.js');
const inboxSrc = readFile('modules/inbox.js');
const finSrc = readFile('modules/finance.js');
const postsSrc = readFile('modules/posts.js');

/* T6-5 — single definition, & first (static mirror of the behavioral probe). */
const defCount = (src) => (src.match(/function\s+escAttr\s*\(/g) || []).length;
const ampFirst = (src) => {
  const a = src.indexOf("replace(/&/g,'&amp;')");
  return a !== -1
    && a < src.indexOf("replace(/</g,'&lt;')")
    && a < src.indexOf("replace(/>/g,'&gt;')")
    && a < src.indexOf('replace(/"/g,\'&quot;\')')
    && a < src.indexOf("replace(/'/g,'&#39;')");
};
check('B14E-5-escattr-definition-single-amp-first',
  1 === defCount(dashSrc) && ampFirst(dashSrc) && 0 === defCount(inboxSrc + finSrc + postsSrc),
  'dashboard.js defines escAttr exactly once with & first; no module redefines it',
  'escAttr definition deviated');

/* T6-6 — exact line anchors of the five contexts (T6 contract cites
 * inbox :93; finance :92/:95/:104/:125). */
const linesOf = (src) => src.split(/\r\n|\r|\n/);
const inboxLines = linesOf(inboxSrc);
const finLines = linesOf(finSrc);
const ctxOk = inboxLines[92] !== undefined && inboxLines[92].includes('href="${escAttr(n.url)}"')
  && finLines[91] !== undefined && finLines[91].includes('pill-${escAttr(o.status_slug)}')
  && finLines[94] !== undefined && finLines[94].includes('href="${escAttr(o.view_url)}"')
  && finLines[103] !== undefined && finLines[103].includes('value="${escAttr(v)}"')
  && finLines[124] !== undefined && finLines[124].includes('href="${escAttr(o.view_url)}"');
check('B14E-6-five-contexts-exact-lines', ctxOk,
  'the five escAttr contexts sit at the contract lines: inbox :93 (href); finance :92 (class) / :95 (href) / :104 (value) / :125 (href)',
  'five-context line anchors deviated');

/* T6-7 — escAttr appears nowhere else; esc() remains for element texts. */
const others = listJsFiles(jsRoot)
  .map((f) => path.relative(jsRoot, f).replace(/\\/g, '/'))
  .filter((rel) => rel !== 'dashboard.js' && rel !== 'modules/inbox.js' && rel !== 'modules/finance.js' && rel !== 'modules/posts.js');
const othersClean = others.every((rel) => !readFile(rel).includes('escAttr('));
const escCounts = {
  inbox: (inboxSrc.match(/\besc\(/g) || []).length,
  fin: (finSrc.match(/\besc\(/g) || []).length,
};
check('B14E-7-escattr-no-other-usage', othersClean && escCounts.inbox > 0 && escCounts.fin > 0,
  `escAttr is used by the five contexts only (zero occurrences in the other ${others.length} shipped files) and esc() keeps the element-text contexts in inbox/finance (${escCounts.inbox}/${escCounts.fin} calls)`,
  'escAttr spread deviated');

/* T6-8 — paste handlers exist and H1→H2 runs BEFORE the serialize state. */
const pasteHandlers = (postsSrc.match(/addEventListener\('paste',markPaste,true\)/g) || []).length;
const keepBody = (postsSrc.match(/function keep\(next\)\{[\s\S]*?\n      \}/) || [''])[0];
const keepOrder = keepBody.includes('normalizePastedH1')
  && keepBody.indexOf('normalizePastedH1') < keepBody.indexOf('setBlocks(');
const h1toH2 = postsSrc.includes("b.attributes.level===1") && postsSrc.includes('level:2');
check('B14E-8-paste-normalize-before-serialize',
  2 === pasteHandlers && keepOrder && h1toH2,
  'posts.js carries both paste handlers (container + ready iframe document) and keep() normalizes H1→H2 before setBlocks — the state the textarea serialize reads is the normalized one',
  'paste normalize order deviated');

/* T6-9 — robots chips locked to the closed four-list client side. */
const chipsOk = postsSrc.includes("var ROBOTS_CLOSED=['index','noindex','follow','nofollow']")
  && postsSrc.includes('ROBOTS_CLOSED.indexOf(rv)!==-1')
  && postsSrc.includes('String(box.value).toLowerCase()');
check('B14E-9-chips-closed-list', chipsOk,
  'robots chips are filtered through the closed [index,noindex,follow,nofollow] list with a case-insensitive normalize before append (client lock; the server intersection stays governing)',
  'chips lock deviated');

/* T6-10 — meter/SERP is client-computed with zero new fetch (behavioral:
 * hossamSaveSeo computes the % text from an existing action's response). */
const serpScan = listJsFiles(jsRoot).filter((f) => /serp/i.test(fs.readFileSync(f, 'utf8')));
const meterSb = makePostsSandbox({ seoScore: '75' });
const scoreEl = makeNode('span');
scoreEl.textContent = '—';
meterSb.__elements['hossam-edit-seo-score'] = scoreEl;
const formStub = {
  querySelector(sel) {
    if (sel === '[name="post_id"]') { return { value: '9' }; }
    if (sel === '[name="title"]') { return { value: 'SEO Title' }; }
    if (sel === '[name="focus_keyword"]') { return { value: 'kw' }; }
    if (sel === '[name="description"]') { return { value: 'desc' }; }
    return null;
  },
  querySelectorAll() { return []; },
};
vm.runInContext(postsSrc, meterSb, { filename: 'runtime/assets/js/modules/posts.js' });

function runMeterProbe(done) {
  const probe = meterSb.hossamSaveSeo(formStub);
  probe.then((ok) => {
    const call = meterSb.__fetches[0] || {};
    const action = call.init && call.init.body ? call.init.body.fields.action : '';
    const meterOk = true === ok
      && '75%' === scoreEl.textContent
      && 1 === meterSb.__fetches.length
      && 'hossam_save_seo' === action
      && 0 === serpScan.length;
    check('B14E-10-meter-client-calc-no-fetch', meterOk,
      'the meter is computed client-side from the existing hossam_save_seo response (75 → "75%") with that one known fetch and zero serp code in the shipped JS',
      `meter deviated: ok=${ok} score=${scoreEl.textContent} fetches=${meterSb.__fetches.length} action=${action} serpFiles=${serpScan.length}`);
    done();
  }).catch((err) => {
    check('B14E-10-meter-client-calc-no-fetch', false,
      'the meter is computed client-side from the existing hossam_save_seo response',
      `meter probe threw: ${err && err.message}`);
    done();
  });
}

/* T6-11 — innerHTML family across ALL runtime/assets/js = exactly 53. */
const allJs = listJsFiles(jsRoot);
let innerHtmlCount = 0;
let insertAdjacentCount = 0;
for (const f of allJs) {
  const src = fs.readFileSync(f, 'utf8');
  innerHtmlCount += (src.match(/innerHTML/g) || []).length;
  insertAdjacentCount += (src.match(/insertAdjacentHTML/g) || []).length;
}
const postsInnerHtml = (postsSrc.match(/innerHTML/g) || []).length;
check('B14E-11-innerhtml-family-53-exact',
  53 === innerHtmlCount + insertAdjacentCount && 50 === innerHtmlCount && 3 === insertAdjacentCount && 0 === postsInnerHtml,
  `the innerHTML family across all of runtime/assets/js is exactly 53 (${allJs.length} files: 50 innerHTML + 3 insertAdjacentHTML) and posts.js carries 0`,
  `innerHTML family deviated: total=${innerHtmlCount + insertAdjacentCount} (innerHTML=${innerHtmlCount} insertAdjacentHTML=${insertAdjacentCount}) posts=${postsInnerHtml}`);

/* ── CSS structure ────────────────────────────────────────────────── */

const cssRaw = fs.readFileSync(cssPath, 'utf8');
const css = cssRaw.replace(/\/\*[\s\S]*?\*\//g, ''); // comments excluded from every scan

/* Minimal flat-block rule splitter: (media-label, selector, body). Handles
 * rules nested inside @media blocks (the stylesheet keeps its media queries
 * flat — no rule nests deeper). */
function parseCssRules(src) {
  const rules = [];
  const mediaStack = [];
  let pendingSelector = null;
  let headerStart = 0;
  for (let i = 0; i < src.length; i += 1) {
    if (src[i] === '{') {
      const header = src.slice(headerStart, i).trim();
      if (header.startsWith('@media')) {
        mediaStack.push(header.replace(/\s+/g, ' '));
      } else {
        pendingSelector = header.replace(/\s+/g, ' ');
      }
      headerStart = i + 1;
    } else if (src[i] === '}') {
      if (null !== pendingSelector) {
        rules.push({ selector: pendingSelector, body: src.slice(headerStart, i), media: mediaStack[mediaStack.length - 1] || null });
        pendingSelector = null;
      } else if (mediaStack.length) {
        mediaStack.pop();
      }
      headerStart = i + 1;
    }
  }
  return rules;
}

const cssRules = parseCssRules(css);
const isFormActionRule = (r) => r.selector.includes('form-actions')
  && (r.selector.includes('panel-write') || r.selector.includes('panel-edit-article'));
const formActionRules = cssRules.filter(isFormActionRule);
const floatingFormActions = formActionRules.filter((r) => /position\s*:\s*(fixed|sticky)\b/.test(r.body));
const staticFormActions = formActionRules.filter((r) => /position\s*:\s*static\b/.test(r.body));

/* T6-12 — [H6]: zero floating rule for the write/edit action bars. */
check('B14E-12-css-form-actions-no-floating',
  formActionRules.length > 0 && 0 === floatingFormActions.length,
  `all ${formActionRules.length} panel-write/panel-edit .form-actions rules are free of fixed|sticky (H6 — the action bars never float; comments excluded)`,
  `floating action-bar rules found: ${JSON.stringify(floatingFormActions.map((r) => r.selector))}`);

/* T6-13 — the v2.8 ≤768 compression rules are present (static bars + smaller buttons). */
const compressionRule = formActionRules.find((r) => r.media && r.media.includes('768') && r.body.includes('position:static'));
const buttonRule = cssRules.find((r) => r.media && r.media.includes('768')
  && r.selector.includes('.form-actions') && r.selector.includes('.btn')
  && r.body.includes('padding:5px 10px'));
check('B14E-13-css-compression-768-present',
  undefined !== compressionRule && undefined !== buttonRule,
  'the ≤768 v2.8 compression exists: in-flow bars (position:static) with the RTL twin plus the smaller .btn scale on both panels',
  '≤768 compression rules deviated (static/RTL/btn missing)');

/* T6-14 — the whole-file fixed|sticky counter is exactly 4 (no new floating surface). */
const floatCounter = (css.match(/position\s*:\s*(?:fixed|sticky)\b/g) || []).length;
check('B14E-14-css-fixed-sticky-counter-4', 4 === floatCounter,
  'the stylesheet carries exactly 4 fixed|sticky declarations (sidebar header, toast container, overlay, mobile sidebar) — no new floating surface',
  `fixed|sticky counter deviated: ${floatCounter} (expected 4)`);

/* T6-15 — no floating AI element (m19 stays rejected by measurement). */
const floatingAi = cssRules.filter((r) => /(^|[\s,])\.?hossam-ai/i.test(r.selector)
  && /position\s*:\s*(fixed|sticky)\b/.test(r.body));
check('B14E-15-css-no-floating-ai', 0 === floatingAi.length,
  'no hossam-ai selector carries a fixed|sticky rule — the AI orb stays rejected, no floating AI element exists',
  `floating AI rules: ${JSON.stringify(floatingAi.map((r) => r.selector))}`);

/* T6-16 — RTL first: the OEB [dir="rtl"] rules precede the base rules. */
const rtlIdx = css.indexOf('[dir="rtl"] .hossam-oeb-btn');
const baseIdx = css.search(/\.hossam-oeb-btn\s*\{/);
check('B14E-16-css-rtl-first-oeb', rtlIdx !== -1 && baseIdx !== -1 && rtlIdx < baseIdx,
  'the OEB block is RTL-first: the [dir="rtl"] direction rules precede the base .hossam-oeb-btn rule',
  `RTL ordering deviated: rtl=${rtlIdx} base=${baseIdx}`);

/* T6-17 — the holder classes exist in the stylesheet (CSS structure of
 * the two rich holders + the OEB button/dialog). */
const holderClasses = [
  '.hossam-rich-editor{', '.hossam-rich-editor-inserter', '.hossam-rich-editor-toolbar',
  '.hossam-rich-editor-canvas', '.hossam-oeb-btn{', 'dialog.hossam-oeb-dialog{', '::backdrop',
];
const missingHolders = holderClasses.filter((c) => !css.includes(c));
check('B14E-17-css-holders-structure', 0 === missingHolders.length,
  'the stylesheet styles every holder: both rich-editor carriers (inserter/toolbar/canvas) and the OEB button/dialog with its backdrop',
  `holder classes missing from dashboard.css: ${JSON.stringify(missingHolders)}`);

/* T6-18 — hossamOeb namespace: 12 functions, no global outside the
 * documented set (posts.js top-level scan). */
const topLevelFns = [];
const fnRe = /^(?:async\s+)?function\s+([A-Za-z0-9_$]+)\s*\(/gm;
let m = fnRe.exec(postsSrc);
while (m !== null) { topLevelFns.push(m[1]); m = fnRe.exec(postsSrc); }
const oebFns = topLevelFns.filter((n) => n.startsWith('hossamOeb'));
const nonHossam = topLevelFns.filter((n) => !n.startsWith('hossam'));
const legacyGlobals = ['restoreArticle', 'translateArticle'];
const unexpectedGlobals = nonHossam.filter((n) => !legacyGlobals.includes(n));
const oebExpected = [
  'hossamOebNormalizeMode', 'hossamOebCaptureBaseline', 'hossamOebSnapshotContent',
  'hossamOebDiffers', 'hossamOebChangedVsBaseline', 'hossamOebEditorUrl',
  'hossamOebBeginCycle', 'hossamOebOpen', 'hossamOebMonitor', 'hossamOebReturnSync',
  'hossamOebDialogOpen', 'hossamOebDialogClose',
];
const oebMissing = oebExpected.filter((n) => !oebFns.includes(n));
check('B14E-18-hossamoeb-namespace-12',
  12 === oebFns.length && 0 === oebMissing.length && 0 === unexpectedGlobals.length,
  'posts.js declares exactly the 12 hossamOeb* functions and no new global outside them (the only non-hossam globals remain the documented legacy pair restoreArticle/translateArticle)',
  `hossamOeb namespace deviated: count=${oebFns.length} missing=${JSON.stringify(oebMissing)} unexpected=${JSON.stringify(unexpectedGlobals)}`);

/* T6-19 — zero postMessage / contenteditable / execCommand in the shipped JS. */
let pmCount = 0;
let ceCount = 0;
let ecCount = 0;
for (const f of allJs) {
  const src = fs.readFileSync(f, 'utf8');
  pmCount += (src.match(/postMessage\s*\(/g) || []).length;
  ceCount += (src.match(/contentEditable/i) || []).length;
  ecCount += (src.match(/execCommand\s*\(/g) || []).length;
}
check('B14E-19-zero-postmessage-contenteditable-execcommand',
  0 === pmCount && 0 === ceCount && 0 === ecCount,
  'zero postMessage, zero contenteditable, zero execCommand across the shipped JS (no cross-window channel, no alternate editing engine)',
  `forbidden surfaces deviated: postMessage=${pmCount} contentEditable=${ceCount} execCommand=${ecCount}`);

/* ── Summary ──────────────────────────────────────────────────────── */

function finalize() {
  console.log('');
  console.log('DEFERRED (بانتظار جلسة القبول — H1 §7): مشهد الكتابة المرجعي بالحسابين على مسار كلٍّ المعتمد (مصفوفة H1 §7-1)، بوابة 9 المصححة، قبول يدوي لحاجب النوافذ — قبول حي لا يقاس محليًا.');
  console.log(`batch14-editor-spec: ${passCount} passed, ${failCount} failed`);
  if (failCount) {
    console.log('Failures:');
    failures.forEach((f) => console.log(`- ${f}`));
    process.exit(1);
  }
  process.exit(0);
}

runMeterProbe(finalize);
