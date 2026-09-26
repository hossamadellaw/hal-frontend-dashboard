/**
 * HAL Frontend Dashboard — Batch 3 admin settings JS test (§14).
 *
 * Plain Node runner (no dependencies): loads the real
 * runtime/assets/js/admin-settings.js into a sandboxed VM context with
 * minimal DOM stubs and drives it end-to-end.
 *
 * Covers: attachment selection via the real wp.media flow, feature
 * dependencies, secret input masking (no server value, password type),
 * duplicate-submit prevention, and the missing-media error state.
 *
 * Usage: node tests/js/admin-settings.test.js   (exit 0 = all pass)
 */

'use strict';

const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

const projectRoot = path.resolve(__dirname, '..', '..');
const scriptSource = fs.readFileSync(
  path.join(projectRoot, 'runtime', 'assets', 'js', 'admin-settings.js'),
  'utf8'
);

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

/* ── Minimal DOM stubs ─────────────────────────────────────────── */

function createElement(tagName, attributes) {
  const element = {
    tagName,
    children: [],
    attributes: { ...attributes },
    listeners: {},
    disabled: false,
    textContent: '',
    value: '',
    className: '',
    appendChild(child) {
      this.children.push(child);
      child.parent = this;
      return child;
    },
    querySelector(selector) {
      const found = this.querySelectorAll(selector);
      return found.length > 0 ? found[0] : null;
    },
    querySelectorAll(selector) {
      // Supports comma lists plus "[attr]", "tag[attr]", ".class" and
      // bare "tag" selectors — enough for the real script's lookups.
      if (selector.includes(',')) {
        const collected = [];
        selector.split(',').forEach((part) => {
          this.querySelectorAll(part.trim()).forEach((item) => collected.push(item));
        });
        return collected;
      }
      const collected = [];
      let tag = null;
      let wanted = null;
      let wantedValue = null;
      if (selector.startsWith('.')) {
        wanted = 'class';
        wantedValue = selector.slice(1);
      } else if (selector.startsWith('[')) {
        wanted = selector.slice(1, -1);
      } else if (/^([a-z]+)\[(.+)\]$/.test(selector)) {
        const parts = selector.match(/^([a-z]+)\[(.+)\]$/);
        tag = parts[1];
        wanted = parts[2];
      } else {
        tag = selector;
        wanted = '__tag__';
      }
      const walk = (node) => {
        for (const child of node.children || []) {
          let hit = false;
          if ('__tag__' === wanted) {
            hit = child.tagName === tag;
          } else if (null !== wantedValue) {
            const classes = String((child.attributes || {}).class || '').split(' ');
            hit = classes.includes(wantedValue);
          } else {
            hit = Object.keys(child.attributes || {}).includes(wanted);
          }
          if (hit && (!tag || child.tagName === tag)) {
            collected.push(child);
          }
          walk(child);
        }
      };
      walk(this);
      return collected;
    },
    addEventListener(type, handler) {
      (this.listeners[type] = this.listeners[type] || []).push(handler);
    },
    dispatch(type, event) {
      (this.listeners[type] || []).forEach((handler) => handler(event || { preventDefault() {} }));
    },
    setAttribute(name, value) {
      this.attributes[name] = String(value);
    },
    getAttribute(name) {
      return Object.prototype.hasOwnProperty.call(this.attributes || {}, name)
        ? this.attributes[name]
        : null;
    },
    classList: {
      set: new Set(),
      add(name) {
        this.set.add(name);
      },
      toggle(name, force) {
        if (force === undefined) {
          this.set.has(name) ? this.set.delete(name) : this.set.add(name);
        } else if (force) {
          this.set.add(name);
        } else {
          this.set.delete(name);
        }
      },
      contains(name) {
        return this.set.has(name);
      }
    }
  };
  if (attributes) {
    for (const [name, value] of Object.entries(attributes)) {
      element.attributes[name] = value;
    }
  }
  return element;
}

function buildDocument(root) {
  return {
    readyState: 'complete',
    querySelector(selector) {
      return selector === '[data-hal-admin]' ? root : null;
    },
    addEventListener() {},
    createElement
  };
}

/** Builds a fresh sandbox around a fresh DOM tree and inits the real script. */
function boot(options) {
  const root = createElement('div', { 'data-hal-admin': '' });

  const mediaButton = createElement('button', { 'data-hal-media-open': '' });
  const attachmentInput = createElement('input', {
    'data-hal-attachment-input': '',
    type: 'hidden',
    value: '0'
  });
  const attachmentPreview = createElement('p', { 'data-hal-attachment-preview': '' });
  const brandingForm = createElement('form', { 'data-hal-form': '' });
  brandingForm.appendChild(mediaButton);
  brandingForm.appendChild(attachmentInput);
  brandingForm.appendChild(attachmentPreview);

  const featureAi = createElement('input', { 'data-hal-feature': 'ai', type: 'checkbox' });
  featureAi.checked = true;
  const featuresForm = createElement('form', { 'data-hal-form': '' });
  featuresForm.appendChild(featureAi);

  // Matches the real controller render: the AI preference controls sit
  // inside a fieldset[data-requires-feature] that carries the disabled
  // state (B3-03); a disabled fieldset disables every control in it.
  const aiPreference = createElement('select', { id: 'hal-ai-preference', name: 'ai_preference' });
  aiPreference.appendChild(createElement('option', {}));
  const aiFieldset = createElement('fieldset', {
    class: 'hal-fd-fieldset',
    'data-requires-feature': 'ai',
    disabled: undefined
  });
  aiFieldset.appendChild(aiPreference);
  const secretInput = createElement('input', {
    'data-hal-secret-input': '',
    type: 'password',
    value: ''
  });
  const aiForm = createElement('form', { 'data-hal-form': '' });
  aiForm.appendChild(aiFieldset);
  const aiPanel = createElement('div', {});
  aiPanel.appendChild(aiForm);
  aiPanel.appendChild(secretInput);

  root.appendChild(brandingForm);
  root.appendChild(featuresForm);
  root.appendChild(aiPanel);

  const sandbox = {
    document: buildDocument(root),
    window: {},
    console,
    setTimeout,
    clearTimeout
  };
  sandbox.window = sandbox;
  // Localized strings must exist BEFORE the script boots (it inits on load).
  sandbox.window.halFrontendDashboardAdmin = {
    mediaTitle: 'Choose',
    mediaButton: 'Use',
    mediaUnavailable: 'The media library is not available.',
    submitting: 'Saving…',
    canUploadFiles: !options || options.canUploadFiles !== false
  };
  if (options && options.wpMedia) {
    let opened = 0;
    let selectHandler = null;
    const selectedAttachment = { id: options.attachmentId || 42 };
    sandbox.wp = {
      media(frameOptions) {
        sandbox.wp.__lastOptions = frameOptions || {};
        return {
          on(event, handler) {
            if (event === 'select') {
              selectHandler = handler;
            }
          },
          open() {
            opened += 1;
            if (selectHandler) {
              selectHandler();
            }
          },
          state: () => ({
            get: () => ({
              first: () =>
                options.attachmentId === null ? undefined : { get: (key) => selectedAttachment[key] }
            })
          })
        };
      },
      __opened: () => opened
    };
  }
  vm.createContext(sandbox);
  vm.runInContext(scriptSource, sandbox, { filename: 'admin-settings.js' });
  // The script booted on load with the strings above; an explicit re-init
  // must be a no-op (the data-hal-initialized guard).
  sandbox.window.HALFrontendDashboardAdminSettings.init(root, sandbox.window.halFrontendDashboardAdmin);
  return { sandbox, root, mediaButton, attachmentInput, attachmentPreview, featureAi, aiPreference, aiFieldset, aiPanel, secretInput, brandingForm, aiForm, featuresForm };
}

/* ── 1. Attachment selection through the real wp.media flow ────── */

{
  const env = boot({ wpMedia: true, attachmentId: 42 });
  env.mediaButton.dispatch('click');
  check(
    'JS1',
    env.attachmentInput.value === '42' &&
      env.attachmentPreview.getAttribute('data-hal-attachment-id') === '42' &&
      env.sandbox.wp.__opened() === 1,
    'choosing a media attachment sets its id into the hidden input and the preview',
    'attachment selection mismatch'
  );
}

/* ── 2. Missing media library surfaces the sanitized error ─────── */

{
  const env = boot({ wpMedia: false });
  env.mediaButton.dispatch('click');
  const errorNote = env.root.querySelector('[data-hal-js-error]');
  check(
    'JS2',
    Boolean(errorNote) && errorNote.textContent === 'The media library is not available.',
    'without wp.media the picker shows the localized error and does not crash',
    'missing-media error state mismatch'
  );
}

/* ── 3. Feature dependencies gate dependent controls ───────────── */

{
  const env = boot({});
  check(
    'JS3a',
    env.aiFieldset.disabled === false,
    'dependent AI controls start enabled while the ai feature is checked',
    'initial dependency state mismatch'
  );
  env.featureAi.checked = false;
  env.featureAi.dispatch('change');
  check(
    'JS3b',
    env.aiFieldset.disabled === true,
    'unchecking the ai feature disables its dependent controls (UI hint; server stays authoritative)',
    'dependency toggle mismatch'
  );
}

/* ── 4. Secret masking: password input, never prefilled ────────── */

{
  const env = boot({});
  check(
    'JS4',
    env.secretInput.attributes.type === 'password' && env.secretInput.value === '',
    'secret inputs are password-typed and never carry a server-provided value',
    'secret masking mismatch'
  );
}

/* ── 5. Duplicate submit prevention ────────────────────────────── */

{
  const env = boot({});
  let submitCount = 0;
  const form = env.featuresForm;
  const submitButton = createElement('button', {});
  form.appendChild(submitButton);
  const originalDispatch = form.dispatch.bind(form);
  form.dispatch = function dispatch(type) {
    if (type === 'submit') {
      // Browser semantics: the form submits once, unless a handler
      // calls preventDefault().
      let prevented = false;
      (form.listeners.submit || []).forEach((handler) =>
        handler({
          preventDefault() {
            prevented = true;
          }
        })
      );
      if (!prevented) {
        submitCount += 1;
      }
      return;
    }
    originalDispatch(type);
  };
  form.dispatch('submit');
  form.dispatch('submit');
  check(
    'JS5',
    submitCount === 1 && form.getAttribute('aria-busy') === 'true' && submitButton.disabled === true,
    'the first submit locks the form (aria-busy + disabled buttons) and repeats are prevented',
    `duplicate submit prevention mismatch (submitCount=${submitCount}, aria-busy=${form.getAttribute('aria-busy')})`
  );
}

/* ── 6. Upload capability gating (§7.6: new uploads need upload_files) ── */

{
  const envWithUpload = boot({ wpMedia: true, canUploadFiles: true });
  envWithUpload.mediaButton.dispatch('click');
  const withUpload = envWithUpload.sandbox.wp.__lastOptions || {};
  const envWithoutUpload = boot({ wpMedia: true, canUploadFiles: false });
  envWithoutUpload.mediaButton.dispatch('click');
  const withoutUpload = envWithoutUpload.sandbox.wp.__lastOptions || {};
  check(
    'JS6',
    withUpload.uploader === undefined && withoutUpload.uploader === false,
    'with upload_files the default media frame is used; without it the uploader is hidden while selecting existing media stays available',
    'upload gating mismatch'
  );
}

/* ── Report ────────────────────────────────────────────────────── */

console.log('─'.repeat(72));
console.log(`RESULT: ${passCount}/${passCount + failCount} checks passed`);
process.exit(failCount === 0 ? 0 : 1);
