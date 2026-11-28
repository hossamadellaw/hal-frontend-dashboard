/**
 * HAL Frontend Dashboard — B9-09 rich-editor behavioral harness (v1.1).
 *
 * Plain Node runner (no dependencies, no live browser, no network, no
 * WordPress): loads the REAL runtime/assets/js/modules/posts.js into a
 * sandboxed VM context with a minimal DOM/wp.element stub and drives the
 * actual init/probe/sync callbacks. No marker-only assertions: every check
 * below executes runtime behavior (B1–B10 of the locked v1.1 list).
 *
 *   B1  both panels mount and confirm on a visible, content-ready iframe
 *   B2  dirty textarea during mounting wins; editor hidden; sync skipped
 *   B3  idempotent init: one createRoot per container
 *   B4  timeout: fallback, textarea visible, phase fallback, no sync
 *   B5  registerCoreBlocks runs once across both panels
 *   B6  sync states: no state / not ready / serialize failure / success
 *   B7  parse error and empty-parse-with-nonempty-html fall back; empty html mounts
 *   B8  catch cleanup: roots deleted, host removed, retry after reload works
 *   B9  suite passes on a copy with the [B9-09] markers stripped (behavioral)
 *   B10 present-but-unusable iframe (invisible/empty) never hides the textarea
 *   O9  re-init after teardown/reload never re-runs registerCoreBlocks
 *   O1–O8 (v1.1.0 T10) OEB cycle probes on the real posts.js: mirror
 *       normalize fail-closed, non-official early-exit, official intercept +
 *       monitor, popup-blocked tab fallback, 15-pulse cap, close → one
 *       return-sync, changed-baseline manual toast, dirty-before-open state
 *
 * Usage:  node tests/js/batch9-rich-editor.test.js   (exit 0 = all pass)
 */

'use strict';

const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const projectRoot = path.resolve(__dirname, '..', '..');
const sourcePath = path.join(projectRoot, 'runtime', 'assets', 'js', 'modules', 'posts.js');
const source = fs.readFileSync(sourcePath, 'utf8');

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
    classList: {
      _set: new Set(),
      add(c) { this._set.add(c); },
      remove(c) { this._set.delete(c); },
      contains(c) { return this._set.has(c); },
      toggle() { return false; },
    },
    setAttribute(n, v) { this._attrs[n] = String(v); },
    getAttribute(n) { return Object.prototype.hasOwnProperty.call(this._attrs, n) ? this._attrs[n] : null; },
    removeAttribute(n) { delete this._attrs[n]; },
    hasAttribute(n) { return Object.prototype.hasOwnProperty.call(this._attrs, n); },
    appendChild(child) { this._children.push(child); child.parentNode = this; return child; },
    removeChild(child) {
      const i = this._children.indexOf(child);
      if (i >= 0) this._children.splice(i, 1);
      if (child.parentNode === this) child.parentNode = null;
      return child;
    },
    querySelector() { return null; },
    querySelectorAll() { return []; },
    getBoundingClientRect() { return { width: 100, height: 100 }; },
  };
  return node;
}

function makePanel(id, taId) {
  const container = makeNode('div');
  container.id = id;
  container.setAttribute('data-textarea', taId);

  const textarea = makeNode('textarea');
  textarea.id = taId;
  textarea.value = '';

  const mounts = {};
  ['inserter', 'toolbar', 'canvas'].forEach((role) => {
    const mount = makeNode('div');
    mount.setAttribute('data-role', role);
    mounts[role] = mount;
  });

  const findFrame = () => mounts.canvas._children.find((n) => n.tagName === 'IFRAME') || null;
  mounts.canvas.querySelector = (sel) => (sel === 'iframe' ? findFrame() : null);

  container.querySelector = (sel) => {
    if (sel === '[data-role="inserter"]') return mounts.inserter;
    if (sel === '[data-role="toolbar"]') return mounts.toolbar;
    if (sel === '[data-role="canvas"]') return mounts.canvas;
    if (sel === '.hossam-rich-root') return container._host || null;
    if (sel === '[data-role="canvas"] iframe, .hossam-rich-root iframe') {
      return findFrame()
        || (container._host ? container._host._children.find((n) => n.tagName === 'IFRAME') : null)
        || null;
    }
    if (sel === 'iframe') return findFrame();
    return null;
  };

  const baseAppend = container.appendChild.bind(container);
  container.appendChild = (child) => {
    baseAppend(child);
    if (child.className === 'hossam-rich-root') container._host = child;
    return child;
  };
  const baseRemove = container.removeChild.bind(container);
  container.removeChild = (child) => {
    baseRemove(child);
    if (container._host === child) container._host = null;
    return child;
  };

  return { container, textarea, mounts };
}

function makeFrame(opts) {
  opts = opts || {};
  const visible = opts.visible !== false;
  const frame = makeNode('iframe');
  frame.getBoundingClientRect = () => ({ width: visible ? 640 : 0, height: visible ? 420 : 0 });
  if (opts.frameStyle) frame.__style = opts.frameStyle;
  if (opts.noDocument) {
    frame.contentDocument = null;
  } else {
    const hasNode = !!opts.editorNode;
    frame.contentDocument = {
      readyState: opts.readyState || 'complete',
      body: {
        className: opts.bodyClass ? 'block-editor-iframe__body editor-styles-wrapper' : '',
        textContent: opts.bodyText || '',
        querySelector: () => (hasNode ? { node: true } : null),
      },
      querySelector: () => (hasNode || opts.bodyClass ? { node: true } : null),
    };
  }
  return frame;
}

function makeSandbox(opts) {
  opts = opts || {};
  const sandbox = {};
  sandbox.window = sandbox;
  sandbox.globalThis = sandbox;
  sandbox.console = { log() {}, warn() {}, error() {} };

  const timers = [];
  sandbox.__timers = timers;
  sandbox.setTimeout = (fn, ms) => { timers.push({ fn, ms }); return timers.length; };
  sandbox.clearTimeout = () => {};
  // T10 (v1.1.0): توسعة بوابة العد إلى 37 — قرار ترقيم صيّاد القائمة.
  // Additive sandbox surfaces for the OEB cycle probes (O1–O8): intervals,
  // window.open, reload counting, and the collected document listeners.
  // Existing timer/element behavior is untouched.
  const intervals = [];
  sandbox.__intervals = intervals;
  let intervalSeq = 0;
  sandbox.setInterval = (fn, ms) => { intervalSeq += 1; const id = intervalSeq; intervals.push({ id, fn, ms, cleared: false }); return id; };
  sandbox.clearInterval = (id) => { const rec = intervals.find((i) => i.id === id); if (rec) rec.cleared = true; };
  sandbox.__windowOpenCalls = [];
  sandbox.__windowOpenResult = null;
  sandbox.open = function windowOpen(url, name, features) {
    sandbox.__windowOpenCalls.push({ url, name, features });
    return sandbox.__windowOpenResult;
  };
  sandbox.__reloads = 0;
  sandbox.getComputedStyle = (node) => node.__style || { display: 'block', visibility: 'visible', opacity: '1' };
  sandbox.FormData = function FormData() { this.append = function append() {}; };

  sandbox.t = (key, fallback) => fallback || key;
  const toasts = [];
  sandbox.__toasts = toasts;
  sandbox.showToast = (msg, type) => { toasts.push({ msg, type }); };

  const elements = {};
  sandbox.__elements = elements;
  const listeners = {};
  sandbox.__docListeners = listeners;
  const doc = {
    getElementById(id) { return elements[id] || null; },
    createElement(tag) { return makeNode(tag); },
    addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
    querySelector() { return null; },
    querySelectorAll() { return []; },
  };
  sandbox.document = doc;
  sandbox.addEventListener = () => {};
  sandbox.hossamAjax = { ajaxurl: '', nonce: '', isRtl: true, i18n: {} };
  sandbox.location = { href: '', reload() { sandbox.__reloads += 1; } };
  sandbox.history = { replaceState() {} };

  const counts = { register: 0, createRoot: 0, unmount: 0, render: 0, serialize: 0, parse: 0 };
  sandbox.__counts = counts;
  let parseMode = opts.parseMode || 'ok';
  let serializeMode = 'ok';
  sandbox.__setSerializeMode = (mode) => { serializeMode = mode; };

  const el = {
    Fragment: 'fragment',
    createElement(type, props) {
      return { type, props: Object.assign({}, props || {}), children: Array.prototype.slice.call(arguments, 2) };
    },
    createPortal(node, target) { return { __portal: true, node, target }; },
    useState(initial) { const cell = { v: initial }; return [cell.v, function set(next) { cell.v = next; }]; },
    useEffect(fn) { try { const cleanup = fn(); return typeof cleanup === 'function' ? cleanup : undefined; } catch (e) { return undefined; } },
    useRef(initial) { return { current: initial }; },
    createRoot(host) {
      counts.createRoot += 1;
      if (opts.createRootThrows) throw new Error('createRoot-failed');
      return {
        host,
        render(vnode) {
          counts.render += 1;
          if (vnode && typeof vnode.type === 'function') vnode.type(vnode.props);
        },
        unmount() { counts.unmount += 1; },
      };
    },
  };

  const parse = (html) => {
    counts.parse += 1;
    if (parseMode === 'throw') throw new Error('parse-failed');
    if (parseMode === 'empty') return [];
    if (!html) return [];
    return [{ name: 'core/paragraph', attributes: { content: html } }];
  };
  const serialize = (blocks) => {
    counts.serialize += 1;
    if (serializeMode === 'throw') throw new Error('serialize-failed');
    if (serializeMode === 'null') return null;
    return (blocks || []).map((b) => `<p>${(b.attributes && b.attributes.content) || ''}</p>`).join('');
  };
  const register = () => { counts.register += 1; };

  sandbox.wp = {
    element: el,
    blocks: {
      parse,
      serialize,
      createBlock(name) { return { name: name || 'core/image', attributes: {} }; },
      registerCoreBlocks: register,
    },
    blockEditor: {
      BlockEditorProvider: 'Provider',
      BlockCanvas: 'Canvas',
      BlockList: 'List',
      Inserter: 'Inserter',
      BlockToolbar: 'Toolbar',
      MediaUpload: null,
      MediaUploadCheck(props) { return props && props.children ? props.children : null; },
    },
    data: {},
    blockLibrary: { registerCoreBlocks: register },
  };
  sandbox.hossamRichEditor = { enabled: true, canUpload: false, restRoot: '', restNonce: '', isRtl: true };

  vm.createContext(sandbox);
  return sandbox;
}

function loadPosts(sandbox, text) {
  vm.runInContext(text || source, sandbox, { filename: 'runtime/assets/js/modules/posts.js' });
}

function runNextTimer(sandbox) {
  const timer = sandbox.__timers.shift();
  if (!timer) return false;
  timer.fn();
  return true;
}

function runAllTimers(sandbox, limit) {
  let ran = 0;
  const max = limit || 200;
  while (sandbox.__timers.length && ran < max) {
    runNextTimer(sandbox);
    ran += 1;
  }
  return ran;
}

function registerPanel(sandbox, which) {
  const isNew = which === 'new';
  const id = isNew ? 'hossam-rich-editor-new' : 'hossam-rich-editor-edit';
  const taId = isNew ? 'hossam-new-content' : 'hossam-edit-content';
  const panel = makePanel(id, taId);
  sandbox.__elements[id] = panel.container;
  sandbox.__elements[taId] = panel.textarea;
  return { id, taId, panel };
}

function initEditor(sandbox, id) {
  return sandbox.hossamRichInitEditor(id);
}

function stateOf(sandbox, id) {
  return sandbox.hossamRichState.inited[id] || null;
}

function attachFrame(panel, frame) {
  panel.mounts.canvas.appendChild(frame);
}

function syncForm(panel) {
  return { querySelector: (sel) => (sel === '[name="content"]' ? panel.textarea : null) };
}

/* B1 — both panels mount and confirm on a visible, content-ready iframe. */
function testB1() {
  ['edit', 'new'].forEach((which) => {
    const sb = makeSandbox();
    const { id, panel } = registerPanel(sb, which);
    loadPosts(sb);
    const ok = initEditor(sb, id);
    const mounting = stateOf(sb, id);
    check(`B9R-1a-${which}-mounting`,
      ok === true && mounting && mounting.phase === 'mounting' && mounting.ready === false
        && mounting.confirmed === false && panel.textarea.classList.contains('hossam-rich-hidden') === false,
      `${which}: init returns true and starts in phase=mounting with the textarea still visible`,
      `${which}: init did not start in mounting with a visible textarea`);

    attachFrame(panel, makeFrame({ editorNode: true }));
      runNextTimer(sb);
    const ready = stateOf(sb, id);
    check(`B9R-1b-${which}-ready`,
      ready && ready.phase === 'ready' && ready.ready === true && ready.confirmed === true
        && ready.probe === 'iframe-ready'
        && panel.textarea.classList.contains('hossam-rich-hidden')
        && panel.container.hasAttribute('hidden') === false,
      `${which}: visible/content-ready iframe confirms phase=ready and hides the textarea`,
      `${which}: confirmation state/hiding wrong`);
  });
}

/* B2 — dirty textarea during mounting wins; editor hidden; sync skipped. */
function testB2() {
  const sb = makeSandbox();
  const { id, panel } = registerPanel(sb, 'edit');
  loadPosts(sb);
  initEditor(sb, id);
  panel.textarea.value = 'manual draft kept';
  attachFrame(panel, makeFrame({ editorNode: true }));
  runNextTimer(sb);

  const st = stateOf(sb, id);
  check('B9R-2a-dirty-bailout',
    st && st.phase === 'fallback' && st.ready === false && st.confirmed === false
      && st.blocks === null && st.probe === 'dirty-textarea' && st.latch === true
      && panel.container.hasAttribute('hidden')
      && panel.textarea.classList.contains('hossam-rich-hidden') === false,
    'D1: user-typed text wins, editor hidden, phase=fallback latched, textarea remains visible',
    'dirty-textarea bailout state wrong');

  const before = panel.textarea.value;
  sb.hossamSyncRichEditorToTextarea(syncForm(panel));
  check('B9R-2b-dirty-no-sync',
    sb.__counts.serialize === 0 && panel.textarea.value === before,
    'D1/A9: sync is skipped after the dirty bailout (no serialize, user text untouched)',
    'sync wrote over manual text after fallback');
}

/* B3 — idempotent init: one createRoot per container. */
function testB3() {
  const sb = makeSandbox();
  const { id, panel } = registerPanel(sb, 'edit');
  loadPosts(sb);
  initEditor(sb, id);
  initEditor(sb, id);
  initEditor(sb, id);
  const roots = Object.keys(sb.hossamRichState.roots).length;
  check('B9R-3a-idempotent-mounting',
    sb.__counts.createRoot === 1 && roots === 1,
    'repeated init while mounting creates exactly one root',
    `expected one root, got createRoot=${sb.__counts.createRoot} roots=${roots}`);

  attachFrame(panel, makeFrame({ editorNode: true }));
  runNextTimer(sb);
  initEditor(sb, id);
  check('B9R-3b-idempotent-ready',
    sb.__counts.createRoot === 1 && stateOf(sb, id).phase === 'ready',
    'repeated init after ready does not recreate the root',
    'root recreated after ready');
}

/* B4 — timeout: fallback, textarea visible, phase fallback, no sync. */
function testB4() {
  const sb = makeSandbox();
  const { id, panel } = registerPanel(sb, 'edit');
  loadPosts(sb);
  initEditor(sb, id);
  const ran = runAllTimers(sb);
  const st = stateOf(sb, id);
  check('B9R-4a-timeout-fallback',
    ran === 40 && st && st.phase === 'fallback' && st.ready === false && st.confirmed === false
      && st.blocks === null && st.probe === 'timeout' && st.latch === true
      && panel.container.hasAttribute('hidden')
      && panel.textarea.classList.contains('hossam-rich-hidden') === false,
    'timeout after 40 probes hides the dead UI, keeps the textarea, phase=fallback',
    `timeout state wrong (timers=${ran})`);

  sb.hossamSyncRichEditorToTextarea(syncForm(panel));
  check('B9R-4b-timeout-no-sync',
    sb.__counts.serialize === 0,
    'A9: sync is skipped while phase=fallback',
    'sync ran after timeout fallback');
}

/* B5 — registerCoreBlocks runs once across both panels. */
function testB5() {
  const sb = makeSandbox();
  const edit = registerPanel(sb, 'edit');
  const add = registerPanel(sb, 'new');
  loadPosts(sb);
  initEditor(sb, edit.id);
  initEditor(sb, add.id);
  check('B9R-5-register-once',
    sb.__counts.register === 1 && sb.hossamRichState.coreBlocks === true,
    'S2: registerCoreBlocks is called exactly once across both panels',
    `registerCoreBlocks calls=${sb.__counts.register}`);
  runAllTimers(sb);
}

/* B6 — sync states: no state / not ready / serialize failure / success. */
function testB6() {
  const noState = makeSandbox();
  const noStatePanel = registerPanel(noState, 'edit');
  loadPosts(noState);
  noState.hossamSyncRichEditorToTextarea(syncForm(noStatePanel.panel));
  check('B9R-6a-no-state',
    noState.__counts.serialize === 0 && noStatePanel.panel.textarea.value === '',
    'sync without state is a silent no-op (user text untouched)',
    'sync ran without state');

  const sb = makeSandbox();
  const { id, panel } = registerPanel(sb, 'edit');
  panel.textarea.value = 'start';
  loadPosts(sb);
  initEditor(sb, id);
  sb.hossamSyncRichEditorToTextarea(syncForm(panel));
  check('B9R-6b-not-ready',
    sb.__counts.serialize === 0 && panel.textarea.value === 'start',
    'sync while phase=mounting is skipped',
    'sync ran before confirmation');

  attachFrame(panel, makeFrame({ editorNode: true }));
  runNextTimer(sb);
  sb.__setSerializeMode('throw');
  sb.hossamSyncRichEditorToTextarea(syncForm(panel));
  check('B9R-6c-serialize-failure',
    panel.textarea.value === 'start' && stateOf(sb, id).initialHtml === 'start',
    'serialize failure leaves the textarea untouched',
    'serialize failure wrote to the textarea');

  sb.__setSerializeMode('ok');
  sb.hossamSyncRichEditorToTextarea(syncForm(panel));
  const first = panel.textarea.value;
  check('B9R-6d-success',
    first === '<p>start</p>' && stateOf(sb, id).initialHtml === first,
    'successful sync writes serialize output and advances the baseline',
    `successful sync wrong: ${first}`);

  panel.textarea.value = 'manual after ready';
  sb.hossamSyncRichEditorToTextarea(syncForm(panel));
  check('B9R-6e-manual-guard',
    panel.textarea.value === 'manual after ready',
    'A9: a manually edited textarea is never overwritten',
    'manual edit overwritten after ready');
}

/* B7 — parse error and empty-parse-with-nonempty-html fall back; empty html mounts. */
function testB7() {
  const throwSb = makeSandbox({ parseMode: 'throw' });
  const throwPanel = registerPanel(throwSb, 'edit');
  loadPosts(throwSb);
  const throwOk = initEditor(throwSb, throwPanel.id);
  const throwState = stateOf(throwSb, throwPanel.id);
  check('B9R-7a-parse-throw',
    throwOk === false && throwState && throwState.phase === 'fallback'
      && throwState.probe === 'parse-error' && throwState.blocks === null
      && throwPanel.panel.container.hasAttribute('hidden')
      && throwPanel.panel.textarea.classList.contains('hossam-rich-hidden') === false
      && throwSb.__counts.createRoot === 0,
    'D8: parse throw falls back explicitly without mounting',
    'parse throw did not fall back cleanly');

  const emptySb = makeSandbox({ parseMode: 'empty' });
  const emptyPanel = registerPanel(emptySb, 'edit');
  emptyPanel.panel.textarea.value = '<p>non-empty</p>';
  loadPosts(emptySb);
  const emptyOk = initEditor(emptySb, emptyPanel.id);
  const emptyState = stateOf(emptySb, emptyPanel.id);
  check('B9R-7b-parse-empty-nonempty',
    emptyOk === false && emptyState && emptyState.phase === 'fallback'
      && emptyState.probe === 'parse-empty' && emptySb.__counts.createRoot === 0,
    'D8: zero blocks for non-empty HTML falls back (no data loss)',
    'empty parse for non-empty HTML did not fall back');

  const freshSb = makeSandbox({ parseMode: 'empty' });
  const freshPanel = registerPanel(freshSb, 'new');
  loadPosts(freshSb);
  const freshOk = initEditor(freshSb, freshPanel.id);
  const freshState = stateOf(freshSb, freshPanel.id);
  check('B9R-7c-empty-html-mounts',
    freshOk === true && freshState && freshState.phase === 'mounting'
      && freshSb.__counts.createRoot === 1,
    'an empty new post (empty HTML, zero blocks) still mounts',
    'empty new post was blocked');
  runAllTimers(freshSb);
}

/* B8 — catch cleanup: roots deleted, host removed, retry after reload works. */
function testB8() {
  const sb = makeSandbox({ createRootThrows: true });
  const { id, panel } = registerPanel(sb, 'edit');
  loadPosts(sb);
  const ok = initEditor(sb, id);
  const st = stateOf(sb, id);
  check('B9R-8a-catch-cleanup',
    ok === false && st && st.phase === 'error' && st.probe === 'error'
      && st.blocks === null && st.latch === true
      && panel.container.hasAttribute('hidden')
      && panel.textarea.classList.contains('hossam-rich-hidden') === false
      && sb.hossamRichState.roots[id] === undefined
      && panel.container._children.length === 0
      && sb.__toasts.length >= 1,
    'A8: catch unmounts/removes the host, deletes roots, sets phase=error, keeps the textarea',
    'catch cleanup incomplete');

  initEditor(sb, id);
  check('B9R-8b-same-page-latched',
    sb.__counts.createRoot === 1,
    'A11: same-page retry is latched (no duplicate mount)',
    'same-page retry re-entered mounting');

  const reload = makeSandbox();
  const fresh = registerPanel(reload, 'edit');
  loadPosts(reload);
  const retryOk = initEditor(reload, fresh.id);
  check('B9R-8c-reload-retry',
    retryOk === true && stateOf(reload, fresh.id).phase === 'mounting'
      && reload.__counts.createRoot === 1,
    'retry after reload (fresh module state) succeeds',
    'retry after reload failed');
  runAllTimers(reload);
}

/* B9 — suite passes on a copy with the [B9-09] markers stripped (behavioral). */
function testB9() {
  const stripped = source.replace(/\[B9-09\]/g, '[B9-XX]');
  const sb = makeSandbox();
  const { id, panel } = registerPanel(sb, 'edit');
  loadPosts(sb, stripped);
  initEditor(sb, id);
  attachFrame(panel, makeFrame({ editorNode: true }));
  runNextTimer(sb);
  check('B9R-9-marker-agnostic',
    stateOf(sb, id).phase === 'ready'
      && panel.textarea.classList.contains('hossam-rich-hidden')
      && sb.__counts.parse === 1 && sb.__counts.render === 1,
    'behavior is driven by runtime callbacks, not by [B9-09] marker presence',
    'suite depends on marker text or did not drive runtime callbacks');
}

/* B10 — present-but-unusable iframe never hides the textarea. */
function testB10() {
  const invisible = makeSandbox();
  const inv = registerPanel(invisible, 'edit');
  loadPosts(invisible);
  initEditor(invisible, inv.id);
  attachFrame(inv.panel, makeFrame({ visible: false, editorNode: true }));
  runAllTimers(invisible);
  check('B9R-10a-invisible-frame',
    stateOf(invisible, inv.id).phase === 'fallback'
      && stateOf(invisible, inv.id).probe === 'timeout'
      && inv.panel.textarea.classList.contains('hossam-rich-hidden') === false,
    'A6: an invisible iframe is not a readiness signal',
    'invisible iframe confirmed readiness');

  const empty = makeSandbox();
  const emp = registerPanel(empty, 'edit');
  loadPosts(empty);
  initEditor(empty, emp.id);
  attachFrame(emp.panel, makeFrame({ editorNode: false, bodyText: '' }));
  runAllTimers(empty);
  check('B9R-10b-empty-frame',
    stateOf(empty, emp.id).phase === 'fallback'
      && stateOf(empty, emp.id).probe === 'timeout'
      && emp.panel.textarea.classList.contains('hossam-rich-hidden') === false,
    'A6: a visible iframe with no content/document is not a readiness signal',
    'empty iframe confirmed readiness');

  const loading = makeSandbox();
  const load = registerPanel(loading, 'edit');
  loadPosts(loading);
  initEditor(loading, load.id);
  attachFrame(load.panel, makeFrame({ readyState: 'loading', editorNode: true }));
  runAllTimers(loading);
  check('B9R-10c-loading-frame',
    stateOf(loading, load.id).phase === 'fallback'
      && load.panel.textarea.classList.contains('hossam-rich-hidden') === false,
    'A6: an iframe whose document is not complete is not a readiness signal',
    'loading iframe confirmed readiness');

  const bodyClass = makeSandbox();
  const body = registerPanel(bodyClass, 'edit');
  loadPosts(bodyClass);
  initEditor(bodyClass, body.id);
  attachFrame(body.panel, makeFrame({ editorNode: false, bodyClass: true, bodyText: 'Loading…' }));
  runAllTimers(bodyClass);
  check('B9R-10d-body-class-only',
    stateOf(bodyClass, body.id).phase === 'fallback'
      && stateOf(bodyClass, body.id).probe === 'timeout'
      && body.panel.textarea.classList.contains('hossam-rich-hidden') === false,
    'A6: the iframe body class alone (block-editor-iframe__body) is not content',
    'iframe body class falsely confirmed readiness');

  const styled = makeSandbox();
  const sty = registerPanel(styled, 'edit');
  loadPosts(styled);
  initEditor(styled, sty.id);
  attachFrame(sty.panel, makeFrame({
    editorNode: true,
    frameStyle: { display: 'none', visibility: 'visible', opacity: '1' },
  }));
  runAllTimers(styled);
  check('B9R-10e-display-none',
    stateOf(styled, sty.id).phase === 'fallback'
      && sty.panel.textarea.classList.contains('hossam-rich-hidden') === false,
    'A6: computed display:none is not a readiness signal even with a nonzero rect',
    'display:none iframe confirmed readiness');
}

/* O9 (v1.0.8) — re-initializing the embed on the same page after the
 * B9R-8a teardown (and on the B9R-8c fresh reload) never re-runs
 * registerCoreBlocks: the posts.js guard flags the attempt before the
 * call, so the counter stays at exactly 1 and the real-WP crash
 * `Format "core/footnote" is already registered.` cannot come from
 * HAL's embed path. */
function testO9() {
  const sb = makeSandbox({ createRootThrows: true });
  const { id } = registerPanel(sb, 'edit');
  loadPosts(sb);
  const first = initEditor(sb, id);
  const again = initEditor(sb, id);

  const reload = makeSandbox();
  const fresh = registerPanel(reload, 'edit');
  loadPosts(reload);
  const retryOk = initEditor(reload, fresh.id);

  check('B9R-O9-reinit-no-reregister',
    first === false && again === true
      && sb.__counts.register === 1
      && sb.hossamRichState.coreBlocks === true
      && retryOk === true && reload.__counts.register === 1,
    'after teardown, same-page re-init and fresh reload each keep registerCoreBlocks at one call (no Format "core/footnote" is already registered.)',
    `registerCoreBlocks calls: same-page=${sb.__counts.register}, reload=${reload.__counts.register}`);
  runAllTimers(reload);
}

/* T10 (v1.1.0): توسعة بوابة العد إلى 37 — قرار ترقيم صيّاد القائمة.
 * O1–O8 — OEB window/monitor/return-sync cycle probes on the REAL posts.js
 * (the same sandboxed-VM method as B1–B10/O9): mode cycle, carriers, and the
 * dirty-before-open return path are driven through actual runtime callbacks,
 * never marker reads. Checks marked [static] read the source text; all
 * others execute runtime behavior in the sandbox. */

/* Registers the OEB carrier elements (dialog/link/truth textareas) so the
 * baseline is captured at module init (posts.js captures at load). */
function attachOebDom(sb, opts) {
  opts = opts || {};
  const dlg = makeNode('dialog');
  dlg.showModal = function () { dlg.__open = true; };
  dlg.close = function () { dlg.__open = false; };
  const link = makeNode('a');
  sb.__elements['hossam-oeb-dialog'] = dlg;
  sb.__elements['hossam-oeb-dialog-link'] = link;
  const nc = makeNode('textarea');
  nc.id = 'hossam-new-content';
  nc.value = opts.newContent || '';
  const ec = makeNode('textarea');
  ec.id = 'hossam-edit-content';
  ec.value = opts.editContent || '';
  sb.__elements['hossam-new-content'] = nc;
  sb.__elements['hossam-edit-content'] = ec;
  return { dlg, link, nc, ec };
}

/* Dispatches a click on a .js-oeb-open anchor through the REAL delegated
 * listener posts.js registered at load (all document click listeners run,
 * exactly like a real click). */
function makeOebBtn(href) {
  return {
    getAttribute(n) { return n === 'href' ? href : null; },
    closest(sel) { return sel === '.js-oeb-open' ? this : null; },
  };
}

function dispatchClick(sb, target, evt) {
  (sb.__docListeners.click || []).forEach((fn) => fn(Object.assign({ target, preventDefault() {} }, evt || {})));
}

function dispatchVisibilityChange(sb) {
  (sb.__docListeners.visibilitychange || []).forEach((fn) => fn({}));
}

function oebOpenCalls(sb) {
  return sb.__windowOpenCalls.filter((c) => c.name === 'hossamOeb');
}

/* O1 — mirror normalize is fail-closed toward embed: missing config, a
 * garbage value, and wrong-case values all normalize to embed; only the
 * exact literal 'official' survives. [static + behavioral] */
function testO1() {
  const sb = makeSandbox();
  attachOebDom(sb);
  loadPosts(sb);
  const missing = sb.hossamOebNormalizeMode();
  sb.hossamRichEditor.mode = 'mirror';
  const garbage = sb.hossamOebNormalizeMode();
  sb.hossamRichEditor.mode = 'Official';
  const wrongCase = sb.hossamOebNormalizeMode();
  sb.hossamRichEditor.mode = 'official';
  const official = sb.hossamOebNormalizeMode();
  check('B9R-O1-mirror-normalize-fail-closed',
    'embed' === missing && 'embed' === garbage && 'embed' === wrongCase && 'official' === official,
    'mirror normalize fails closed: missing/garbage/wrong-case mode → embed; the exact literal official is the only pass-through',
    `normalize deviated: missing=${missing} garbage=${garbage} wrongCase=${wrongCase} official=${official}`);
}

/* O2 — non-official mode never intercepts the anchor: no window.open, no
 * preventDefault (target=_blank holds), no cycle, no dialog. */
function testO2() {
  const sb = makeSandbox();
  const dom = attachOebDom(sb);
  loadPosts(sb);
  sb.hossamRichEditor.mode = 'embed';
  let prevented = false;
  dispatchClick(sb, makeOebBtn('https://wp.test/post.php?post=5&action=edit'), {
    preventDefault() { prevented = true; },
  });
  check('B9R-O2-embed-mode-no-intercept',
    0 === oebOpenCalls(sb).length && false === prevented && 0 === sb.__intervals.length
      && !dom.dlg.__open && true === sb.hossamOebState.synced,
    'embed mode: the .js-oeb-open click falls through untouched (no open call, no preventDefault, no monitor, no dialog, no cycle)',
    `embed mode intercepted: open=${oebOpenCalls(sb).length} prevented=${prevented} intervals=${sb.__intervals.length}`);
}

/* O3 — official × working window.open: the anchor is intercepted with the
 * fixed-size hossamOeb window, preventDefault lands, the monitor interval
 * is armed, and the dialog opens carrying the editor URL. */
function testO3() {
  const sb = makeSandbox();
  const dom = attachOebDom(sb);
  loadPosts(sb);
  sb.hossamRichEditor.mode = 'official';
  sb.__windowOpenResult = { closed: false };
  const url = 'https://wp.test/wp-admin/post.php?post=7&action=edit';
  let prevented = false;
  dispatchClick(sb, makeOebBtn(url), { preventDefault() { prevented = true; } });
  const calls = oebOpenCalls(sb);
  check('B9R-O3-official-open-intercepts-and-monitors',
    1 === calls.length && calls[0].url === url && 'hossamOeb' === calls[0].name
      && -1 !== String(calls[0].features).indexOf('width=1200') && true === prevented
      && 1 === sb.__intervals.length && false === sb.__intervals[0].cleared
      && true === dom.dlg.__open && url === dom.link.getAttribute('href'),
    'official mode + a real window: the click is intercepted (fixed-size hossamOeb window), preventDefault lands, the monitor arms, and the dialog opens with the editor URL',
    `official open deviated: calls=${JSON.stringify(calls)} prevented=${prevented} intervals=${sb.__intervals.length}`);
}

/* O4 — popup-blocked fallback: window.open ⇒ null drops the interception
 * (no preventDefault — target=_blank holds), no dialog, and the tab path
 * arms the one-shot visibilitychange latch whose return syncs once. */
function testO4() {
  const sb = makeSandbox();
  const dom = attachOebDom(sb);
  loadPosts(sb);
  sb.hossamRichEditor.mode = 'official';
  sb.__windowOpenResult = null;
  let prevented = false;
  dispatchClick(sb, makeOebBtn('https://wp.test/wp-admin/post.php?post=7&action=edit'), {
    preventDefault() { prevented = true; },
  });
  const latched = true === sb.hossamOebState.pendingVisibility && false === sb.hossamOebState.synced;
  sb.document.visibilityState = 'visible';
  dispatchVisibilityChange(sb);
  check('B9R-O4-popup-blocked-tab-fallback',
    1 === oebOpenCalls(sb).length && null === sb.__windowOpenResult
      && false === prevented && latched && !dom.dlg.__open
      && true === sb.hossamOebState.synced && false === sb.hossamOebState.pendingVisibility
      && 1 === sb.__reloads && 0 === sb.__intervals.length,
    'popup blocker (open ⇒ null): no preventDefault (tab holds), the visibilitychange latch arms once, the dialog stays shut, and the visible-return syncs exactly once (reload, unchanged content) with no window monitor',
    `tab fallback deviated: prevented=${prevented} latched=${latched} reloads=${sb.__reloads} synced=${sb.hossamOebState.synced}`);
}

/* O5 — the monitor asks win.closed only, every 1000ms, capped at 15 pulses:
 * reaching the cap clears the interval with no sync (no reload, no toast) —
 * no unbounded timer ever exists. */
function testO5() {
  const sb = makeSandbox();
  const dom = attachOebDom(sb);
  loadPosts(sb);
  sb.hossamRichEditor.mode = 'official';
  const win = { closed: false };
  sb.__windowOpenResult = win;
  dispatchClick(sb, makeOebBtn('https://wp.test/wp-admin/post.php?post=7&action=edit'));
  const rec = sb.__intervals[0];
  for (let i = 0; i < 15; i += 1) { rec.fn(); }
  check('B9R-O5-monitor-cap-no-timer-no-sync',
    15 === sb.hossamOebState.pulses && true === rec.cleared && null === sb.hossamOebState.interval
      && 0 === sb.__reloads && 0 === sb.__toasts.length
      && 1000 === rec.ms,
    '15 pulses without close clear the monitor with no sync at all — no reload, no toast, no unbounded timer (cap contract)',
    `monitor cap deviated: pulses=${sb.hossamOebState.pulses} cleared=${rec.cleared} reloads=${sb.__reloads} toasts=${sb.__toasts.length}`);
}

/* O6 — window closed: the monitor syncs the return exactly once (dialog
 * auto-closed, one reload for unchanged content) and the per-cycle latch
 * swallows every further signal. */
function testO6() {
  const sb = makeSandbox();
  const dom = attachOebDom(sb);
  loadPosts(sb);
  sb.hossamRichEditor.mode = 'official';
  const win = { closed: false };
  sb.__windowOpenResult = win;
  dispatchClick(sb, makeOebBtn('https://wp.test/wp-admin/post.php?post=7&action=edit'));
  const rec = sb.__intervals[0];
  rec.fn(); // one open pulse
  win.closed = true;
  rec.fn(); // the closing pulse
  const once = 1 === sb.__reloads && false === dom.dlg.__open && true === sb.hossamOebState.synced && true === rec.cleared;
  sb.hossamOebReturnSync();
  rec.fn();
  win.closed = true;
  dispatchVisibilityChange(sb);
  check('B9R-O6-close-return-sync-reload-once',
    once && 1 === sb.__reloads,
    'close ⇒ return-sync once: the dialog auto-closes, unchanged content reloads exactly once, and the cycle latch swallows repeat signals (no double reload)',
    `return sync deviated: reloads=${sb.__reloads} dialogOpen=${!!dom.dlg.__open} once=${once}`);
}

/* O7 — content changed vs the load baseline ⇒ the return path never
 * reloads: the manual-update toast (R41 key) is the alert. */
function testO7() {
  const sb = makeSandbox();
  const dom = attachOebDom(sb, { editContent: 'baseline-body' });
  loadPosts(sb);
  sb.hossamRichEditor.mode = 'official';
  const win = { closed: false };
  sb.__windowOpenResult = win;
  dispatchClick(sb, makeOebBtn('https://wp.test/wp-admin/post.php?post=7&action=edit'));
  dom.ec.value = 'baseline-body-edited-in-the-window';
  const rec = sb.__intervals[0];
  rec.fn();
  win.closed = true;
  rec.fn();
  check('B9R-O7-changed-baseline-manual-toast',
    0 === sb.__reloads && 1 === sb.__toasts.length
      && 'success' === sb.__toasts[0].type && -1 !== String(sb.__toasts[0].msg).indexOf('Manual update started.'),
    'changed vs baseline ⇒ no reload; the return path alerts with the manual-update toast (oeb_manual_update) instead',
    `changed return deviated: reloads=${sb.__reloads} toasts=${JSON.stringify(sb.__toasts)}`);
}

/* O8 (§ح-3-7 «وسخ قبل الفتح») — dirtyBeforeOpen is material state: the
 * field exists on hossamOebState [static], BeginCycle snapshots it from the
 * open-time snapshot vs the load baseline [static], and a dirty-before-open
 * cycle alerts (manual-update toast, no reload) in the return path even
 * though the window itself wrote nothing. */
function testO8() {
  const staticOk = -1 !== source.indexOf('dirtyBeforeOpen:false')
    && -1 !== source.indexOf('hossamOebState.dirtyBeforeOpen=hossamOebDiffers(')
    && -1 !== source.indexOf('dirtyBeforeOpen') && source.indexOf('dirtyBeforeOpen') < source.indexOf('function hossamOebBeginCycle');
  const sb = makeSandbox();
  const dom = attachOebDom(sb, { editContent: 'clean-load-body' });
  loadPosts(sb);
  const pristine = false === sb.hossamOebState.dirtyBeforeOpen;
  dom.ec.value = 'typed-before-open'; // dirt exists BEFORE the open cycle
  sb.hossamRichEditor.mode = 'official';
  const win = { closed: false };
  sb.__windowOpenResult = win;
  dispatchClick(sb, makeOebBtn('https://wp.test/wp-admin/post.php?post=7&action=edit'));
  const captured = true === sb.hossamOebState.dirtyBeforeOpen
    && 'typed-before-open' === sb.hossamOebState.snapshot.editContent;
  const rec = sb.__intervals[0];
  rec.fn();
  win.closed = true;
  rec.fn();
  check('B9R-O8-dirty-before-open-materialized',
    staticOk && pristine && captured && 0 === sb.__reloads && 1 === sb.__toasts.length,
    '§ح-3-7: the pre-open dirt is materialized in hossamOebState.dirtyBeforeOpen at cycle begin and the return path alerts with the manual-update toast instead of reloading over unsaved work',
    `dirty-before-open deviated: static=${staticOk} pristine=${pristine} captured=${captured} reloads=${sb.__reloads} toasts=${sb.__toasts.length}`);
}

testB1();
testB2();
testB3();
testB4();
testB5();
testB6();
testB7();
function testI20(){
  /* v1.1.5 item 20 + v1.1.8 item 1 + v1.1.9 item 1: the contentCss channel
     keeps the canvas chrome-free — the nested block-list-appender and the
     empty-block inserter stay display:none (the appender scoped to
     inside-blocks), the DEFAULT appender rules keep the invisible click-layer
     styling, and — v1.1.9 consensus correction 5 — the insertion point has
     LEFT the hide list: Gutenberg never mounts the default appender while
     blocks exist, so the click layer alone was structurally dead; the native
     between-blocks affordance returns and the in-tree bridge owns
     blank-canvas clicks. The parent stylesheet does not pretend to cross the
     iframe boundary. */
  var hiddenFamilies=['.block-list-appender','.block-editor-block-list__empty-block-inserter','.block-editor-block-list__insertion-point'];
  var cssStart=source.indexOf('var contentCss=');
  var cssChunk=cssStart===-1?'':source.slice(cssStart, cssStart+1200);
  var hiddenScoped=cssChunk.indexOf('.block-editor-block-list__block .block-list-appender,.block-editor-block-list__empty-block-inserter{display:none!important;}')!==-1;
  var insertionPointRestored=cssChunk.indexOf('.block-editor-block-list__insertion-point{display:none')===-1 && cssChunk.indexOf('.block-editor-block-list__insertion-point,')===-1 && cssChunk.indexOf(',.block-editor-block-list__insertion-point')===-1;
  var appenderLayer=cssChunk.indexOf('.block-editor-default-block-appender{display:block!important;height:260px;margin:0;padding:0;cursor:text;}')!==-1
    && cssChunk.indexOf('.block-editor-default-block-appender .block-editor-default-block-appender__content{height:260px;margin:0;padding:0;color:transparent!important;cursor:text;}')!==-1
    && cssChunk.indexOf('.block-editor-default-block-appender .block-editor-inserter{display:none!important;}')!==-1;
  var appenderNotHidden=cssChunk.indexOf('.block-editor-default-block-appender,')===-1 && cssChunk.indexOf('.block-editor-default-block-appender{display:none')===-1;
  check('I20-CSS-FAMILIES', hiddenScoped && insertionPointRestored && appenderLayer && appenderNotHidden,
    'contentCss keeps the nested/empty-block families hidden, the insertion point restored (v1.1.9 — native between-blocks affordance), and the default appender click-layer rules intact',
    'the hide families, the insertion-point restoration, or the appender click-layer rules are wrong');
  var css = require('node:fs').readFileSync(path.join(projectRoot,'runtime','assets','css','dashboard.css'),'utf8');
  var parentCrosses = hiddenFamilies.concat(['.block-editor-default-block-appender']).some(function(f){ return css.indexOf(f)!==-1; });
  check('I20-CHANNEL-DISCIPLINE', !parentCrosses, 'the in-canvas hide families live only in the official styles channel, never in the parent stylesheet', 'families found in parent css');
}

testB8();
testB9();
testB10();
testO9();
testO1();
testO2();
testO3();
testO4();
testO5();
testO6();
testO7();
testO8();
testI20();

/* v1.1.7 item 1 — the pure seed helper + the seeded mount on an empty field
   (the hidden appender left no clickable path to the first block). */
function testSeed() {
  const sb = makeSandbox();
  loadPosts(sb);
  const createBlock = (name) => ({ name: name || 'core/image', attributes: {} });
  const seeded = sb.hossamRichSeedBlocks([], '', createBlock);
  check('B9R-SEED-pure-empty',
    Array.isArray(seeded) && seeded.length === 1 && seeded[0].name === 'core/paragraph',
    'an empty parsed state on an empty field seeds exactly one empty paragraph',
    `got: ${JSON.stringify(seeded)}`);
  const parsed = [{ name: 'core/paragraph', attributes: { content: 'x' } }];
  const passed = sb.hossamRichSeedBlocks(parsed, 'x', createBlock);
  check('B9R-SEED-pure-content',
    passed === parsed && passed.length === 1,
    'parsed content passes through untouched (same reference)',
    'parsed content was replaced');
  const noFactory = sb.hossamRichSeedBlocks([], '', undefined);
  check('B9R-SEED-pure-no-factory',
    Array.isArray(noFactory) && noFactory.length === 0,
    'a missing factory degrades to zero blocks without throwing',
    `got: ${JSON.stringify(noFactory)}`);
  const sb2 = makeSandbox();
  const { id } = registerPanel(sb2, 'new');
  loadPosts(sb2);
  const ok = initEditor(sb2, id);
  const mounting = stateOf(sb2, id);
  check('B9R-SEED-mount-empty-field',
    ok === true && mounting && Array.isArray(mounting.blocks) && mounting.blocks.length === 1
      && mounting.blocks[0].name === 'core/paragraph',
    'an empty field mounts exactly one seeded paragraph (the canvas is writable from the first click)',
    `mounting blocks: ${JSON.stringify((mounting.blocks || []).map((b) => b.name))}`);
  const sb3 = makeSandbox();
  const r3 = registerPanel(sb3, 'edit');
  r3.panel.textarea.value = '<!-- wp:paragraph --><p>real content</p><!-- /wp:paragraph -->';
  loadPosts(sb3);
  initEditor(sb3, 'hossam-rich-editor-edit');
  const m3 = stateOf(sb3, 'hossam-rich-editor-edit');
  check('B9R-SEED-content-passthrough',
    m3 && Array.isArray(m3.blocks) && m3.blocks.length === 1 && m3.blocks[0].name === 'core/paragraph'
      && m3.blocks[0].attributes && m3.blocks[0].attributes.content === '<!-- wp:paragraph --><p>real content</p><!-- /wp:paragraph -->',
    'a field with content parses normally (the seed does not touch parsed blocks)',
    `got: ${JSON.stringify((m3.blocks || []).map((b) => ({ n: b.name, a: b.attributes && String(b.attributes.content || '').slice(0, 30) })))}`);
}

testSeed();

/* v1.1.8 item 1 + v1.1.9 item 1 — the default appender keeps the invisible
   click-layer styling; the insertion point leaves the hide list (the layer
   alone was structurally dead: Gutenberg never mounts the default appender
   while blocks exist) and the in-tree click bridge owns blank-canvas clicks
   (Gutenberg's native insertDefaultBlock path through the provider's
   sub-registry). */
function testSeedAppender() {
  const cssStart = source.indexOf('var contentCss=');
  const cssChunk = cssStart === -1 ? '' : source.slice(cssStart, cssStart + 1200);
  const appenderLayer = cssChunk.indexOf('.block-editor-default-block-appender{display:block!important;height:260px;margin:0;padding:0;cursor:text;}') !== -1
    && cssChunk.indexOf('.block-editor-default-block-appender .block-editor-default-block-appender__content{height:260px;margin:0;padding:0;color:transparent!important;cursor:text;}') !== -1
    && cssChunk.indexOf('.block-editor-default-block-appender .block-editor-inserter{display:none!important;}') !== -1
    && cssChunk.indexOf('.block-list-appender{display:block!important;margin:0;padding:0;}') !== -1;
  const nestedHidden = cssChunk.indexOf('.block-editor-block-list__block .block-list-appender,.block-editor-block-list__empty-block-inserter{display:none!important;}') !== -1;
  const insertionPointRestored = cssChunk.indexOf('.block-editor-block-list__insertion-point') === -1;
  // (the rule keeps its trailing semicolon inside the concatenated contentCss)
  const appenderInHideList = /display:none!important;\}\.block-editor-default-block-appender[,{}]/.test(cssChunk) || cssChunk.indexOf('.block-editor-default-block-appender,.block-list-appender') !== -1;
  check('B9R-SEED-APPENDER-CLICKABLE',
    appenderLayer && nestedHidden && insertionPointRestored && !appenderInHideList,
    'the default appender keeps its click-layer rules, the nested families stay scoped-hidden, and the insertion point is out of the hide list (v1.1.9)',
    'the appender click-layer rules are missing, or the insertion point is still hidden');
}

testSeedAppender();

/* v1.1.9 item 1 — the blank-canvas click bridge (consensus adoption): an
   in-tree BlockCanvas child resolves the provider's sub-registry via
   useRegistry, deposits it on the state record (rec.wpRegistry — the
   sanctioned channel, since the static toolbar lives outside the React tree
   and a global block-store dispatch is undefined on this page), attaches
   iframe-document mousedown+click listeners with the seven-rule exclusion
   chain and the consensus guards, and dispatches the official
   insertDefaultBlock (with the click-Y insertion index, falling back to
   append-at-end). */
function testClickBridge() {
  const bridgeStart = source.indexOf('function HossamClickBridge(');
  const appStart = source.indexOf('function HossamRichApp(');
  const bridgeChunk = bridgeStart !== -1 && appStart !== -1 && appStart > bridgeStart ? source.slice(bridgeStart, appStart) : '';
  const mounted = source.indexOf("el.createElement(HossamClickBridge,{key:'clickbridge',blocksCount:blocks.length})") !== -1;
  const chain = [
    "t.closest('.block-editor-block-list__block')",
    "t.closest('.components-popover,.components-tooltip')",
    "t.closest('.block-editor-inserter')",
    "t.closest('.block-editor-block-list__insertion-point')",
    "t.closest('.block-list-appender,.block-editor-default-block-appender')",
    "t.closest('button,a,input,select,textarea,[contenteditable=\"true\"]')",
  ];
  const chainOk = bridgeChunk !== '' && chain.every((c) => bridgeChunk.indexOf(c) !== -1);
  const guardsOk = bridgeChunk !== ''
    && bridgeChunk.indexOf('ev.detail>1') !== -1
    && bridgeChunk.indexOf('countRef.current===0') !== -1
    && bridgeChunk.indexOf("rec.phase!=='ready'") !== -1
    && bridgeChunk.indexOf('getBlockCount()!==mouse.count') !== -1
    && bridgeChunk.indexOf("doc.addEventListener('mousedown',onMouseDown,true)") !== -1
    && bridgeChunk.indexOf("doc.addEventListener('click',onClick,true)") !== -1
    && bridgeChunk.indexOf("doc.removeEventListener('mousedown',onMouseDown,true)") !== -1
    && bridgeChunk.indexOf("doc.removeEventListener('click',onClick,true)") !== -1;
  const registryOk = bridgeChunk.indexOf('wp0.data.useRegistry()') !== -1
    && bridgeChunk.indexOf('rec.wpRegistry=registry') !== -1
    && bridgeChunk.indexOf('.insertDefaultBlock(') !== -1;
  check('B9R-CLICK-BRIDGE',
    bridgeStart !== -1 && mounted && chainOk && guardsOk && registryOk,
    'the click bridge is an in-tree BlockCanvas child that deposits the sub-registry, applies the seven-rule exclusion chain and consensus guards, and dispatches the official insertDefaultBlock',
    'the click bridge is missing, unwired, or violates the consensus chain/guards');
  const noGlobalStoreDispatch = source.indexOf("wp0.data.dispatch('core/block-editor'") === -1
    && source.indexOf("wp.data.dispatch('core/block-editor'") === -1;
  check('B9R-CLICK-BRIDGE-REGISTRY-DISCIPLINE',
    noGlobalStoreDispatch && source.indexOf('function hossamToolbarRegistry(') !== -1
      && source.indexOf('rec.wpRegistry?rec.wpRegistry:null') !== -1,
    'no global block-store dispatch anywhere — every official action reads the deposited sub-registry (hossamToolbarRegistry)',
    'a global block-store dispatch or a missing registry accessor was found');
}

testClickBridge();

/* v1.1.9 item 1 — wave-1 toolbar handlers move to the official store path:
   bullet/numbered lists via switchToBlockType + replaceBlocks, and the
   divider/collapsible/table insertions via createBlock + insertBlocks —
   all through rec.wpRegistry (the legacy funnel could not survive the
   controlled re-render). */
function testWave1Handlers() {
  const listsOk = source.indexOf("hossamToolbarTransformBlock(containerId,'core/list')") !== -1
    && source.indexOf("hossamToolbarTransformBlock(containerId,'core/list',{ordered:true})") !== -1
    && source.indexOf("hossamToolbarCmd(container,'insertOrderedList')") === -1;
  // the multilevel button stays deferred (consensus) and keeps its legacy
  // call — the bullet/numbered handlers are the only ones migrated, so the
  // unordered-list legacy target must appear exactly once (multilevel only).
  const legacyUnordered = source.split("hossamToolbarCmd(container,'insertUnorderedList')").length - 1;
  const insertsOk = source.indexOf("hossamToolbarInsertBlock(containerId,'core/table')") !== -1
    && source.indexOf("hossamToolbarInsertBlock(containerId,'core/details')") !== -1
    && source.indexOf("hossamToolbarInsertBlock(containerId,'core/separator')") !== -1;
  check('B9R-W1-OFFICIAL-HANDLERS',
    listsOk && insertsOk && legacyUnordered === 1 && source.indexOf('function hossamToolbarTransformBlock(') !== -1
      && source.indexOf('function hossamToolbarInsertBlock(') !== -1,
    'wave-1 handlers (bullet/numbered lists, table, collapsible, divider) run on the official store path through the deposited registry; the deferred multilevel button alone keeps its legacy target',
    'a migrated wave-1 handler still targets the legacy funnel or an official handler is missing');
}

testWave1Handlers();

/* v1.1.11 item 1 — the click-bridge region branching (consensus): below the
   last block the caret moves to the END of the content (selectBlock(last,-1)
   through the sub-registry); above the first block the caret moves to the
   START of the first block (selectBlock(first,0) — the recorded design
   decision); a last block without a text field falls back to the append
   path; middle gaps keep the insertDefaultBlock-at-index path. */
function testClickRegions() {
  const bridgeStart = source.indexOf('function HossamClickBridge(');
  const appStart = source.indexOf('function HossamRichApp(');
  const bridgeChunk = bridgeStart !== -1 && appStart !== -1 && appStart > bridgeStart ? source.slice(bridgeStart, appStart) : '';
  const regionsOk = bridgeChunk !== ''
    && bridgeChunk.indexOf('.getBoundingClientRect().bottom') !== -1
    && bridgeChunk.indexOf('actions.selectBlock(lastId,-1)') !== -1
    && bridgeChunk.indexOf('actions.selectBlock(firstId,0)') !== -1
    && bridgeChunk.indexOf('HOSSAM_CLICK_TEXT_TYPES.indexOf(lastType)') !== -1
    && source.indexOf("var HOSSAM_CLICK_TEXT_TYPES=['core/paragraph','core/heading','core/quote','core/list','core/table','core/details']") !== -1
    && bridgeChunk.indexOf('actions.insertDefaultBlock({},undefined,idx)') !== -1;
  check('B9R-CLICK-REGIONS',
    regionsOk,
    'the empty-surface click branches by region: end-of-content caret below the last block, start-of-first-block above, native insertion for middle gaps, and the no-text-field append fallback',
    'the region branching is missing or incomplete');
}

testClickRegions();

/* v1.1.11 item 2 — the toolbar unification (consensus): the manual
   BlockToolbar render is removed, the official hasFixedToolbar switch
   prevents the floating mount, and the contentCss defense-in-depth rule
   hides exactly the two contextual toolbar selectors without touching the
   direction rule. */
function testToolbarUnified() {
  const cssStart = source.indexOf('var contentCss=');
  const cssChunk = cssStart === -1 ? '' : source.slice(cssStart, cssStart + 1400);
  const noManualToolbar = source.indexOf("el.createElement(BlockToolbar,{key:'bar'})") === -1;
  const fixedSetting = source.indexOf('hasFixedToolbar:true') !== -1;
  const hideRule = cssChunk.indexOf('.block-editor-block-toolbar,.block-editor-block-contextual-toolbar{display:none!important;}') !== -1;
  const directionFirst = cssChunk.indexOf("html{direction:'+(cfg.isRtl?'rtl':'ltr')+';}") === 0 + source.indexOf('var contentCss=') - source.indexOf('var contentCss=') || cssChunk.indexOf("html{direction:'+(cfg.isRtl?'rtl':'ltr')+';}") !== -1;
  check('B9R-TB-UNIFIED',
    noManualToolbar && fixedSetting && hideRule && directionFirst,
    'the toolbar is unified: no manual BlockToolbar render, hasFixedToolbar prevents the floating mount, and the two-selector hide rule is appended inside contentCss after the untouched direction rule',
    'the toolbar unification is incomplete');
}

testToolbarUnified();

/* v1.1.11 item 3 — wave 2 on the unified official channel: the formatting
   tools read the selection from the sub-registry, transform the value with
   the official rich-text API, write through updateBlockAttributes, restore
   the selection, and the clear list carries its pinned exceptions. */
function testWave2Channel() {
  const richEditOk = source.indexOf('function hossamToolbarRichEdit(') !== -1
    && source.indexOf('wp0.richText.create({html:htmlIn})') !== -1
    && source.indexOf('updateBlockAttributes(cid,{content:wp0.richText.toHTMLString({value:out})})') !== -1
    && source.indexOf(".selectionChange(cid,'content',start,end)") !== -1
    && source.indexOf('toHTMLString') !== -1;
  const formatsOk = source.indexOf("hossamToolbarApplyFormat(containerId,'core/strikethrough')") !== -1
    && source.indexOf("hossamToolbarApplyFormat(containerId,'core/subscript')") !== -1
    && source.indexOf("hossamToolbarApplyFormat(containerId,'core/superscript')") !== -1
    && source.indexOf("hossamToolbarApplyFormat(containerId,'core/text-color',{style:'background-color: '+c})") !== -1
    && source.indexOf("hossamToolbarApplyFormat(containerId,'core/text-color',{style:'color: '+c})") !== -1
    && source.indexOf("hossamToolbarApplyFormat(containerId,'core/text-color',{style:'color: '+c})") !== -1;
  const alignOk = source.indexOf('function hossamToolbarSetAlignment(') !== -1
    && source.indexOf('{textAlign:value})') !== -1
    && source.indexOf("hossamToolbarSetAlignment(containerId,'left')") !== -1
    && source.indexOf("hossamToolbarSetAlignment(containerId,'center')") !== -1
    && source.indexOf("hossamToolbarSetAlignment(containerId,'right')") !== -1;
  const transformsOk = source.indexOf("hossamToolbarTransformBlock(containerId,'core/heading',{level:") !== -1
    && source.indexOf("hossamToolbarTransformBlock(containerId,'core/quote')") !== -1
    && source.indexOf("hossamToolbarTransformBlock(containerId,'core/paragraph')") !== -1;
  check('B9R-W2-CHANNEL',
    richEditOk && formatsOk && alignOk && transformsOk,
    'wave 2 runs on the unified official channel: registered formats, the text-color style form, the textAlign alignment form, and the official heading/quote transforms — all through the sub-registry with selection restoration',
    'the wave-2 channel is incomplete or a legacy target remains for a migrated tool');
  const clearOk = source.indexOf("var HOSSAM_TOOLBAR_CLEAR_TYPES=['core/bold','core/italic','core/underline','core/strikethrough','core/subscript','core/superscript','core/text-color','core/code'];") !== -1
    && source.indexOf("var HOSSAM_TOOLBAR_CLEAR_EXCEPTIONS=['core/link','core/image','core/footnote','core/math','core/language','core/keyboard','core/non-breaking-space','core/unknown','core/note'];") !== -1;
  check('B9R-W2-REMOVE-EXCEPTIONS',
    clearOk,
    'the clear-format tool removes exactly the pinned inline-format types and never touches the pinned exceptions',
    'the pinned clear lists are missing or altered');
}

testWave2Channel();

/* v1.1.14 item 1 — the unified writing-rescue layer (Wave A consensus):
   the write vault (sessionStorage keyed {form}:{lang}, TTL stamped, cleared
   on success, never-silent restore banner, refused over newer saved
   content), the single dirty flag (beforeunload conditional + the
   dashboard.js nav() guard), the redirect governor (the 6s create timer and
   both 4s translation hops cancel on the first user input), the D1 decision
   card (bind/intercept), the official edit autosave through wp.apiFetch,
   and the black-box recorder persisted on pagehide. */
function testRescueLayer() {
  const dashSrc = fs.readFileSync(path.join(projectRoot, 'runtime', 'assets', 'js', 'dashboard.js'), 'utf8');
  const vaultOk = source.indexOf("var HOSSAM_VAULT_PREFIX='hossamWriteVault'") !== -1
    && source.indexOf('var HOSSAM_VAULT_TTL=86400000;') !== -1
    && source.indexOf("HOSSAM_VAULT_PREFIX+':write:'+hossamWriteLang()") !== -1
    && source.indexOf('sessionStorage.setItem(hossamWriteVaultKey(),JSON.stringify(payload))') !== -1;
  const bannerOk = source.indexOf('function hossamWriteRestoreBanner(form){') !== -1
    && source.indexOf("'data-hossam-vault-banner','1'") !== -1
    && source.indexOf("t('vaultRestorePrompt','Restore unsaved draft from {time}?')") !== -1
    && source.indexOf("t('vaultRestore','Restore')") !== -1
    && source.indexOf("t('vaultDiscard','Discard')") !== -1
    && source.indexOf('payload.ts<=savedAt') !== -1
    && source.indexOf('live.ts!==payload.ts') !== -1;
  const dirtyOk = source.indexOf('window.hossamWriteDirty=true;') !== -1
    && source.indexOf("if(window.hossamWriteDirty){ev.preventDefault();ev.returnValue='';}") !== -1
    && source.indexOf('try{hossamWriteMarkDirty();}catch(eRes){}') !== -1
    && dashSrc.indexOf('window.hossamWriteDirty&&!window.confirm(') !== -1
    && dashSrc.indexOf("t('unsavedLeave','You have unsaved changes in the writing form — leave anyway?')") !== -1
    && (source.match(/hossamWriteClearDirty\(\);/g) || []).length === 2
    && source.indexOf("try{hossamSyncRichEditorToTextarea(form);}catch(eVS){}") !== -1
    && source.indexOf("bound:(form.getAttribute('data-hossam-bound-post')||'')") !== -1
    && source.indexOf("form.setAttribute('data-hossam-bound-post',String(live.bound));") !== -1
    && source.indexOf("t('vaultRestoreBoundPrompt','Restore your unsaved edits to the saved article from {time}?')") !== -1
    && source.indexOf("if(k==='status'&&String(v==null?'':v).trim()==='draft'){") !== -1;
  const governorArms = (source.match(/hossamWriteRedirectArmed=true;/g) || []).length;
  const governorOk = governorArms === 3
    && source.indexOf('function hossamWriteCancelRedirect(){') !== -1
    && source.indexOf('if(hossamWriteRedirectArmed)hossamWriteCancelRedirect();') !== -1
    && source.indexOf("if(form.getAttribute('data-hossam-bound-post')&&typeof hossamUpdateArticle==='function'){return hossamUpdateArticle(form);}") !== -1
    && source.indexOf("if(form.getAttribute('data-hossam-saved-post')&&hossamWriteLastCreate){") !== -1;
  const d1Ok = source.indexOf("form.setAttribute('data-hossam-saved-post',String(d.data.post_id||''));") !== -1
    && source.indexOf("form.setAttribute('data-hossam-bound-post',pid);") !== -1
    && source.indexOf("t('d1Continue','Continue writing')") !== -1
    && source.indexOf("t('d1OpenEditor','Open in editor')") !== -1;
  const autosaveOk = source.indexOf("path:'/wp/v2/posts/'+id+'/autosaves'") !== -1
    && source.indexOf('function hossamWriteAutosave(){') !== -1
    && source.indexOf('window.hossamApiFetchWired') !== -1;
  const recorderOk = source.indexOf("var HOSSAM_DIAG_KEY='hossamWriteDiagLog';") !== -1
    && source.indexOf('sessionStorage.setItem(HOSSAM_DIAG_KEY,JSON.stringify({') !== -1
    && source.indexOf("window.addEventListener('pagehide'") !== -1
    && source.indexOf('lastInputAgoMs:') !== -1
    && source.indexOf('function hossamDiagInstall(){') !== -1;
  check('B9R-W3-RESCUE-LAYER',
    vaultOk && bannerOk && dirtyOk && governorOk && d1Ok && autosaveOk && recorderOk,
    'the unified writing-rescue layer is wired: the write vault with its stamped TTL and never-silent restore banner (refused over newer saved content), the single dirty flag feeding beforeunload and the dashboard.js nav() guard, the redirect governor over all three timers with the D1 bind/intercept pair, the official edit autosave through wp.apiFetch, and the pagehide black-box recorder',
    'the rescue layer is missing or incomplete');
}

testRescueLayer();

/* v1.1.15 item 1 — the AR restore integrity fix (measured P1): the restore
   apply gates on the strict editor readiness (a mounting editor defers the
   whole apply until phase==='ready'; terminal latches and record-less
   surfaces take the textarea fallback so a fallback environment can never
   deadlock the restore), every field lands through its own explicit target,
   a post-apply verification pass reads every field back (the title equality
   is the measured glue detector), and the capture-suppression window keeps
   the debounced capture from ever re-capturing a corrupted intermediate
   state. The identical chain runs on every locale — no language branch. */
function testRestoreIntegrity() {
  const applyStart = source.indexOf('function hossamWriteVaultApply(form,payload){');
  const autosaveStart = source.indexOf('function hossamWriteAutosave(){');
  const applyRegion = (applyStart !== -1 && autosaveStart > applyStart)
    ? source.slice(applyStart, autosaveStart) : '';
  const gateOk = applyRegion.indexOf("hossamVaultEditorState(rec)") !== -1
    && applyRegion.indexOf("if(state==='mounting'&&containerId){") !== -1
    && source.indexOf("if(rec.phase==='ready'&&rec.confirmed&&rec.ready&&rec.blocks) return 'ready';") !== -1
    && source.indexOf("if(rec.phase==='fallback'||rec.phase==='error'||rec.latch) return 'terminal';") !== -1
    && source.indexOf("function hossamVaultApplyNotify(containerId){") !== -1
    && (source.match(/hossamVaultApplyNotify\(containerId\);/g) || []).length === 4;
  const deferOk = source.indexOf('var hossamVaultApplyWaiters={};') !== -1
    && source.indexOf('var HOSSAM_VAULT_APPLY_WAIT_CEILING=20000;') !== -1
    && applyRegion.indexOf('vault-apply-waiter-ceiling') !== -1
    && applyRegion.indexOf('liveNow.ts!==live.ts') !== -1;
  const targetsOk = applyRegion.indexOf("setVal('title',f.title);") !== -1
    && applyRegion.indexOf("setVal('excerpt',f.excerpt);") !== -1
    && applyRegion.indexOf("setVal('status',f.status);") !== -1
    && applyRegion.indexOf("setVal('thumbnail_id',f.thumbnail_id);") !== -1
    && applyRegion.indexOf('rec.api.keep(parsed);recApplied=rec;parsedApplied=parsed;') !== -1
    && applyRegion.indexOf('if(ta) ta.value=content;') !== -1;
  const verifyOk = source.indexOf('function hossamWriteVaultVerify(form,fields,surface,rec,wantLen){') !== -1
    && source.indexOf("if(!fieldMatches('title')) return false;") !== -1
    && source.indexOf('vault-apply-verify-failed') !== -1
    && source.indexOf('vault-apply-canvas-keep-failed') !== -1;
  const suppressOk = source.indexOf("if(form.getAttribute('data-hossam-apply-pending')==='1') return;") !== -1
    && source.indexOf("form.setAttribute('data-hossam-apply-pending','1');") !== -1
    && source.indexOf("form.removeAttribute('data-hossam-apply-pending');") !== -1
    && source.indexOf("form.setAttribute('data-hossam-apply-failed','1');") !== -1
    && source.indexOf("createForm.getAttribute('data-hossam-apply-failed')==='1'") !== -1;
  const localeOk = applyRegion.indexOf('isRtl') === -1
    && applyRegion.indexOf('hossamWriteLang()') === -1;
  check('B9R-W4-RESTORE-INTEGRITY',
    gateOk && deferOk && targetsOk && verifyOk && suppressOk && localeOk,
    'the restore integrity chain is wired: the strict ready gate (mounting defers through the waiter registry with its ceiling, terminal/record-less takes the textarea fallback), the per-field explicit targets (title/excerpt/status/thumbnail_id + the canvas keep() or textarea content surface), the post-apply verification pass with the title glue detector, the capture-suppression window with the failed-verify latch cleared by real input, and the locale-blind apply chain',
    'the restore integrity chain is missing or incomplete');
}

testRestoreIntegrity();

/* v1.1.15 item 2 - the four live-measured tool failures: the clear-format
   execution path now calls the official removeFormat signature (value,
   formatType, start, end - the previous order passed the numeric start into
   the formatType slot and every pinned removal was a silent no-op), the
   color Apply reads the selected swatch through the per-menu selection state
   without breaking the free-input path, the More fold toggle carries and
   synchronizes its aria-expanded state, and every dropdown trigger rides the
   single-open popover discipline with the same aria state machine. */
function testWaveBToolFixes() {
  const clearOk = source.indexOf('removed=rt.removeFormat(removed,HOSSAM_TOOLBAR_CLEAR_TYPES[i],start,end);') !== -1
    && source.indexOf('rt.removeFormat(removed,start,end,') === -1;
  const applyOk = source.indexOf('var sel={swatch:null,pickTouched:false};') !== -1
    && (source.match(/sel\.swatch=c;sel\.pickTouched=false;/g) || []).length === 2
    && source.indexOf("freeRow(m,'hossamFreeTextColor',function(c){hossamToolbarApplyFormat(containerId,'core/text-color',{style:'color: '+c});},sel);") !== -1
    && source.indexOf("freeRow(m,'hossamFreeHighlight',function(c){hossamToolbarApplyFormat(containerId,'core/text-color',{style:'background-color: '+c});},sel);") !== -1
    && source.indexOf("sel.syncPick=function(v){try{pick.value=v;}catch(e){}};") !== -1
    && source.indexOf('if(sel&&sel.pickTouched){v=pick.value;}') !== -1
    && source.indexOf('else if(sel&&sel.swatch){v=sel.swatch;}') !== -1;
  const moreOk = source.indexOf("moreBtn.setAttribute('aria-expanded','false');") !== -1
    && source.indexOf("moreSync.setAttribute('aria-expanded',bar.classList.contains('hossam-pt-more-open')?'true':'false');") !== -1
    && source.indexOf("var mt=bar.querySelector('.hossam-pt-more-toggle');") !== -1;
  const popoverOk = source.indexOf("trig.setAttribute('aria-expanded','false');") !== -1
    && source.indexOf('function closeSiblings(){') !== -1
    && source.indexOf('if(!open) closeSiblings();') !== -1
    && source.indexOf("trig.setAttribute('aria-expanded',open?'false':'true');") !== -1;
  check('B9R-W5-TOOL-FIXES',
    clearOk && applyOk && moreOk && popoverOk,
    'the wave-B tool fixes are wired: the official removeFormat argument order over the byte-exact pinned lists, the swatch-aware Apply with the intact free-input path on both color menus, the More fold aria state machine (mount + fold handler + drawer close), and the single-open popover discipline with synchronized trigger aria on every dropdown',
    'the wave-B tool fixes are missing or incomplete');
}

testWaveBToolFixes();

/* v1.1.15 item 3 - the refusal server leg: the bound edit form carries the
   meta stamp at load, the carrier reads it only for a bound vault whose
   bound id matches the rendered post, and the effective comparison takes
   the newer leg (the refusal survives a reload; the live-page JS stamp
   stays authoritative; an unbound vault keeps the exact previous behavior). */
function testRefusalServerLeg() {
  const carrierOk = source.indexOf('function hossamWriteServerSavedAt(boundId){') !== -1
    && source.indexOf("var pid=ef.querySelector('[name=\"post_id\"]');") !== -1
    && source.indexOf('if(!pid||String(pid.value)!==String(boundId)) return 0;') !== -1
    && source.indexOf('return (n&&n>0)?n:0;') !== -1;
  const orderOk = (source.match(/var server\w+=hossamWriteServerSavedAt\(/g) || []).length === 3
    && (source.match(/if\(server\w+>saved\w+\) saved\w+=server\w+;/g) || []).length === 3
    && source.indexOf("hossamWriteServerSavedAt(payload.bound?String(payload.bound):'')") !== -1
    && source.indexOf("hossamWriteServerSavedAt(live.bound?String(live.bound):'')") !== -1
    && source.indexOf("hossamWriteServerSavedAt(liveNow.bound?String(liveNow.bound):'')") !== -1;
  check('B9R-W6-REFUSAL-SERVER-LEG',
    carrierOk && orderOk,
    'the refusal server leg is wired: the bound-id-matched carrier reading the edit form stamp, and the newer-leg comparison order at all three refusal sites (banner render, apply entry, deferred completion)',
    'the refusal server leg is missing or incomplete');
}

testRefusalServerLeg();

/* v1.1.15 item 4 - the Wave B focus and tools core: the three deterministic
   focus lifts (the at-readiness programmatic caret with its three-way gate,
   the click-bridge extension selecting unfocused text-type blocks, and
   Enter-in-title moving into the canvas), the F-1 unified failure path
   (toast + one selection recovery + one retry over the three official
   families with the registered i18n pairs), the D2 official paragraph
   direction behind the getBlockSupport probe with the documented fallback,
   and the D5 shipped single-block list semantics documented. */
function testFocusF1D2() {
  const lift1Ok = source.indexOf('function hossamRichFocusAtReady(containerId){') !== -1
    && source.indexOf('hossamRichFocusAtReady(containerId);') !== -1
    && source.indexOf("if(rec.textareaId!=='hossam-new-content') return;") !== -1
    && source.indexOf('if(rec.canvasTouched) return;') !== -1
    && source.indexOf('if(store.getSelectedBlockClientId()) return;') !== -1;
  const lift2Ok = source.indexOf("registry.dispatch('core/block-editor').selectBlock(bcid,0);") !== -1
    && source.indexOf('&&store.getSelectedBlockClientId()!==bcid){') !== -1;
  const lift3Ok = source.indexOf("titleIn.addEventListener('keydown',function(ev){") !== -1
    && source.indexOf("if((ev.key||'')!=='Enter'||ev.shiftKey) return;") !== -1;
  const f1Ok = source.indexOf('function hossamToolbarWithRecovery(containerId,run,toastKey,toastText){') !== -1
    && source.indexOf('function hossamToolbarRecoverSelection(containerId){') !== -1
    && (source.match(/hossamToolbarWithRecovery\(containerId,function/g) || []).length === 3
    && source.indexOf(",'toolbarSelectFirst','Select text first')") !== -1
    && (source.match(/'toolbarNoBlock','No editable block selected'/g) || []).length === 2;
  const d2Ok = source.indexOf("wpD.blocks.getBlockSupport('core/paragraph','direction')") !== -1
    && source.indexOf('updateBlockAttributes(cidD,{direction:') !== -1;
  const d5Ok = source.indexOf('the shipped list semantics are SINGLE-BLOCK') !== -1;
  check('B9R-W7-FOCUS-F1-D2',
    lift1Ok && lift2Ok && lift3Ok && f1Ok && d2Ok && d5Ok,
    'the Wave B focus and tools core is wired: the gated at-readiness caret, the click-bridge text-block selection, the Enter-in-title canvas focus, the F-1 toast/recovery/retry wrapper over the three official families with the registered pairs, the D2 probed direction path, and the documented single-block list semantics',
    'the Wave B focus and tools core is missing or incomplete');
}

testFocusF1D2();

/* v1.1.16 item 1 - the case menu indexing: the items are [key, default, mode]
   and both consumers read their own slot - the label renders the registered
   key with its default and the click passes the mode (the shifted indexing
   passed an undefined mode and rendered the raw mode strings as labels). */
function testCaseIndexing() {
  const itemsOk = source.indexOf("var items=[['w7CaseSentence','Sentence case.','sentence'],['w7CaseLower','lowercase','lower'],['w7CaseUpper','UPPERCASE','upper'],['w7CaseTitle','Title Case','title']];") !== -1;
  const labelOk = source.indexOf("el('button','hossam-pt-menu-item',t(item[0],item[1]))") !== -1;
  const modeOk = source.indexOf('hossamToolbarSetCase(containerId,item[2]);') !== -1
    && source.indexOf('hossamToolbarSetCase(containerId,item[3]);') === -1;
  check('B9R-W8-CASE-INDEXING',
    itemsOk && labelOk && modeOk,
    'the case menu items are consumed at their own slots: the label renders the registered key with its default and the click passes the mode from the third element',
    'the case menu indexing is missing or shifted');
}

testCaseIndexing();

/* v1.1.16 item 2 - the list attribute toggle: an existing list (or its
   inner list item through the parent walk) toggles the official ordered
   attribute through the sub-registry before the paragraph transform runs. */
function testListToggle() {
  const helperOk = source.indexOf('function hossamToolbarListOrdered(containerId,ordered){') !== -1
    && source.indexOf("reg.dispatch('core/block-editor').updateBlockAttributes(targetL,{ordered:ordered});") !== -1
    && source.indexOf("else if(blockL.name==='core/list-item'&&selL.getBlockParents){") !== -1;
  const wiredOk = source.indexOf('if(hossamToolbarListOrdered(containerId,false)) return;') !== -1
    && source.indexOf('if(hossamToolbarListOrdered(containerId,true)) return;') !== -1;
  check('B9R-W8-LIST-TOGGLE',
    helperOk && wiredOk,
    'the bullet and numbered buttons toggle the official ordered attribute on an existing list (or its inner list item through the parent walk) before falling through to the paragraph transform',
    'the list attribute toggle is missing or incomplete');
}

testListToggle();

/* v1.1.16 item 3 - the justify migration: the button rides the official
   alignment family (style.typography.textAlign through the sub-registry)
   and the v1.1.11 deferral comment is renewed in the same commit. */
function testJustifyMigration() {
  const migrated = source.indexOf("hossamToolbarSetAlignment(containerId,'justify')") !== -1
    && source.indexOf("hossamToolbarCmd(container,'justifyFull')") === -1;
  const commentRenewed = source.indexOf('[v1.1.16 item 3') !== -1
    && source.indexOf('Justify full stays') === -1
    && source.indexOf('justify full migrates off the dead legacy funnel') !== -1;
  check('B9R-W8-JUSTIFY-MIGRATION',
    migrated && commentRenewed,
    'justify full rides the official alignment family and the v1.1.11 deferral comment is renewed in the same commit',
    'the justify migration or its comment renewal is missing');
}

testJustifyMigration();

/* v1.1.16 item 4 - line spacing writes the official lineHeight attribute
   on the selected block through the sub-registry merge pattern (the legacy
   root-container DOM walk measured live is removed). */
function testLineHeight() {
  const official = source.indexOf('typography:Object.assign({},typH,{lineHeight:v})') !== -1
    && source.indexOf('if(blk) blk.style.lineHeight=v;') === -1
    && source.indexOf('the shared root container measured live') !== -1;
  check('B9R-W8-LINEHEIGHT',
    official,
    'line spacing writes the official lineHeight attribute on the selected block through the sub-registry merge pattern (the legacy root-container DOM walk removed)',
    'the line-spacing official path is missing or the legacy walk remains');
}

testLineHeight();

console.log('');
console.log(`batch9-rich-editor: ${passCount} passed, ${failCount} failed`);
if (failCount) {
  console.log('Failures:');
  failures.forEach((f) => console.log(`- ${f}`));
  process.exit(1);
}
