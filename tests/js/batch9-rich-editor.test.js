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
  sandbox.getComputedStyle = (node) => node.__style || { display: 'block', visibility: 'visible', opacity: '1' };
  sandbox.FormData = function FormData() { this.append = function append() {}; };

  sandbox.t = (key, fallback) => fallback || key;
  const toasts = [];
  sandbox.__toasts = toasts;
  sandbox.showToast = (msg, type) => { toasts.push({ msg, type }); };

  const elements = {};
  sandbox.__elements = elements;
  const listeners = {};
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
  sandbox.location = { href: '', reload() {} };
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
    useState(initial) { return [initial, function noop() {}]; },
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
      createBlock() { return { name: 'core/image', attributes: {} }; },
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

testB1();
testB2();
testB3();
testB4();
testB5();
testB6();
testB7();
testB8();
testB9();
testB10();

console.log('');
console.log(`batch9-rich-editor: ${passCount} passed, ${failCount} failed`);
if (failCount) {
  console.log('Failures:');
  failures.forEach((f) => console.log(`- ${f}`));
  process.exit(1);
}
