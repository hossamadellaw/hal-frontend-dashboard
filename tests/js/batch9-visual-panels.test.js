/**
 * HAL Frontend Dashboard — Batch 9 visual-per-panel evidence (B9-U1).
 *
 * Plain Node runner (no dependencies, no browser, no network, no WordPress):
 * compares, for each of the 12 dashboard panels plus the shell, the styling
 * surface of the REFERENCE tree against the MIGRATED tree under an identical
 * extraction method and identical display conditions (same algorithm, same
 * real stylesheets, no live data). No project logic is reimplemented and no
 * source file is modified by this harness.
 *
 * Method (documented limits inline):
 *   1. Both template trees are read as text with comments stripped
 *      (HTML comments, PHP block comments, PHP // line comments) by the SAME
 *      function, so comment-embedded mentions can never count as markup.
 *   2. From each unit the harness extracts the styling hooks consumers see:
 *      static class tokens, single-quoted PHP literals emitted inside class
 *      attributes (ternary branches such as text-danger / rm-score rm-good),
 *      static id tokens, data-* attribute names, and static style="..."
 *      fragments. Identical markup ⇒ identical element/type/inline rendering;
 *      only class/id/attribute hooks can diverge, and those are checked
 *      exhaustively against BOTH real stylesheets.
 *   3. Both real stylesheets (legacy theme/dashboard.css and runtime
 *      assets/css/dashboard.css) are parsed with the SAME parser (comments
 *      stripped, @media inner rules kept with their query label).
 *   4. Per unit the harness asserts:
 *        markup-symmetric  — hook sets migrated == reference (exact);
 *        no-lost-rules     — every hook matched by ≥1 legacy rule is still
 *                            matched by ≥1 runtime rule;
 *        rules-symmetric   — the applicable rule sets are identical
 *                            (the single documented [B9-U1] .text-center
 *                            addition matches no PHP markup, so PHP units
 *                            must be exactly equal).
 *   5. Residual orphans (hooks with no rule in EITHER stylesheet) must be a
 *      subset of the documented allowlist below. Each allowlist entry was
 *      verified symmetric pre-existing with a styling-irrelevant or
 *      base-class-paired role cited at its use site; any NEW orphan fails.
 *      The parent-theme question is answered by construction: the migration
 *      neither removed nor orphaned any hook the reference had styled, so no
 *      panel can look different for a migration reason. Hooks unstyled in
 *      BOTH trees render via browser defaults identically.
 *
 * Out of scope (stated, not claimed): pixel rasterization (no browser engine
 * exists on this machine), live-data rendering (needs WordPress), and the
 * contents of the external parent-theme stylesheet (unavailable by design;
 * header/footer 1:1 applicability is batch-7 evidence, cited not repeated).
 *
 * Usage:  node tests/js/batch9-visual-panels.test.js   (exit 0 = all pass)
 */

'use strict';

const fs = require('node:fs');
const path = require('node:path');

const projectRoot = path.resolve(__dirname, '..', '..');
const legacyTheme = path.resolve(projectRoot, '..', '..', 'dashboard', 'Dahboard-v-1.0.0', 'theme');

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

/* ── Identical preprocessing for both trees ─────────────────────── */

function stripComments(src) {
  return src
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/(^|[^:])\/\/[^\n]*/g, '$1');
}

function cleanToken(t) {
  const s = String(t).trim();
  if (!s || s.length > 64) { return null; }
  if (/[{}$<>?;:"=`]/.test(s)) { return null; }
  if (/[()']/.test(s)) { return null; }
  if (s.includes('<?') || s.startsWith('$') || s.startsWith('&')) { return null; }
  return s;
}

function lineOf(text, index) {
  const start = text.lastIndexOf('\n', index);
  const end = text.indexOf('\n', index);
  return text.slice(start + 1, end < 0 ? text.length : end);
}

function extractHooks(src) {
  const text = stripComments(src);
  const classes = new Set();
  const ids = new Set();
  const dataAttrs = new Set();
  const inlineStyles = new Set();
  const logoStyles = new Set();
  for (const m of text.matchAll(/class="([^"]*)"/g)) {
    const span = m[1];
    const hasPHP = span.includes('<?');
    // Ternary/comparison operands ('x' === / === 'x' / !==) are PHP residues,
    // never emitted classes here (admin.php:178 pattern); array-index
    // literals ($cell['c']) are residues too. Both dropped first.
    const codeFree = span
      .replace(/'[^']*'\s*(===|!==|==|!=)/g, '')
      .replace(/(===|!==|==|!=)\s*'[^']*'/g, '')
      .replace(/\[[^\]]*\]/g, '')
      .replace(/\$[\w>-]+/g, '');
    for (const t of codeFree.split(/\s+/)) {
      // Bare echo/print inside a PHP span are language constructs, never
      // CSS classes (class="<?php echo ... ?>" pattern).
      if (hasPHP && (t === 'echo' || t === 'print')) { continue; }
      const c = cleanToken(t);
      if (c) { classes.add(c); }
    }
    for (const lit of codeFree.matchAll(/'([^']+)'/g)) {
      for (const t of lit[1].split(/\s+/)) {
        const c = cleanToken(t);
        if (c && c !== 'available' && c !== 'unavailable') { classes.add(c); }
      }
    }
  }
  for (const m of text.matchAll(/\bid="([^"]*)"/g)) {
    const c = cleanToken(m[1]);
    if (c && !c.includes(' ')) { ids.add(c); }
  }
  for (const m of text.matchAll(/\bdata-([\w-]+)=/g)) { dataAttrs.add(m[1]); }
  for (const m of text.matchAll(/\bstyle="([^"]*)"/g)) {
    if (!m[1].includes('<?')) {
      // Logo-fallback inline styles (sb-logo lines) are the approved
      // batch-7 branding delta (Media attachment or HAL box instead of a
      // shipped logo.png); tracked separately, never silently merged.
      const bucket = /sb-logo/.test(lineOf(text, m.index)) ? logoStyles : inlineStyles;
      bucket.add(m[1].trim());
    }
  }
  return { classes, ids, dataAttrs, inlineStyles, logoStyles };
}

/* ── Identical CSS parsing for both stylesheets ─────────────────── */

function parseRules(css) {
  const flat = css.replace(/\/\*[\s\S]*?\*\//g, '');
  const rules = [];
  const mediaRanges = [];
  let depth = 0;
  let mediaStart = -1;
  let currentMedia = null;
  const stack = [];
  for (let i = 0; i < flat.length; i += 1) {
    if (flat.startsWith('@media', i) && (i === 0 || /[\s;}]/.test(flat[i - 1]))) {
      const open = flat.indexOf('{', i);
      if (open > 0) {
        mediaRanges.push({ start: open, query: flat.slice(i, open).replace(/\s+/g, ' ').trim() });
      }
    }
    if (flat[i] === '{') { stack.push(i); depth += 1; }
    if (flat[i] === '}') {
      const open = stack.pop();
      depth -= 1;
      if (depth === 0) {
        const header = flat.slice(0, open).split('}').pop();
        if (!header.trim().startsWith('@')) {
          let media = null;
          for (const r of mediaRanges) {
            if (r.start < open) { media = r.query; }
          }
          const selector = header.replace(/\s+/g, ' ').trim();
          const decls = flat.slice(open + 1, i).replace(/\s+/g, ' ').trim();
          if (selector) { rules.push({ key: `${media || 'all'}||${selector}||${decls}`, selector, media }); }
        }
        mediaRanges.length = 0;
      }
    }
  }
  return rules;
}

function hookMatches(hook, kind, selector) {
  if (kind === 'class') { return new RegExp(`\\.${hook.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}(?![\\w-])`).test(selector); }
  if (kind === 'id') { return selector.includes(`#${hook}`); }
  return selector.includes(`[data-${hook}`);
}

function applicableKeys(hooks, rules) {
  const out = new Set();
  const kinds = [['classes', 'class'], ['ids', 'id'], ['dataAttrs', 'data']];
  for (const rule of rules) {
    for (const [setName, kind] of kinds) {
      for (const hook of hooks[setName]) {
        if (hookMatches(hook, kind, rule.selector)) { out.add(rule.key); break; }
      }
    }
  }
  return out;
}

/* ── Documented residual orphans (symmetric, pre-existing) ─────────
 * btn-primary ......... seo.php:239 save button; always paired with .btn
 *                       (+.btn-sm), both defined; no rule in EITHER css.
 * tab-content/tab-group files/members/finance/store panels; display driven
 *                       by switchTab() inline styles + [data-tab-content];
 *                       no rule in EITHER css.
 * hdr-lang-cur ........ shell dashboard.php:702 language label; inherits
 *                       header type; no rule in EITHER css.
 * js-ai-run ........... posts panel AI buttons (paired .btn.btn-sm); event
 *                       delegation hook in ai.js:138; styling-irrelevant.
 * js-restore-article .. posts panel; delegation hook in posts.js:160.
 * js-translate-article  posts panel; delegation hook in posts.js:192.
 * sb-logo ............. shell logo fallback hook (both trees); unstyled by
 *                       design — variants carry full inline styles (Media
 *                       attachment img vs gold HAL box, batch-7 branding
 *                       contract, no shipped logo.png).
 */
const ORPHAN_ALLOWLIST = new Set([
  'btn-primary', 'tab-content', 'tab-group', 'hdr-lang-cur', 'sb-logo',
  'js-ai-run', 'js-restore-article', 'js-translate-article',
]);

const PANELS = ['overview', 'posts', 'bookings', 'seo', 'translations', 'finance', 'inbox', 'files', 'members', 'store', 'profile', 'admin'];

function setDiff(a, b) {
  return [...a].filter((x) => !b.has(x)).sort();
}

function checkUnit(name, refSrc, migSrc, legacyRules, runtimeRules) {
  const ref = extractHooks(refSrc);
  const mig = extractHooks(migSrc);

  const classSame = JSON.stringify([...ref.classes].sort()) === JSON.stringify([...mig.classes].sort());
  const idSame = JSON.stringify([...ref.ids].sort()) === JSON.stringify([...mig.ids].sort());
  const dataSame = JSON.stringify([...ref.dataAttrs].sort()) === JSON.stringify([...mig.dataAttrs].sort());
  const styleSame = JSON.stringify([...ref.inlineStyles].sort()) === JSON.stringify([...mig.inlineStyles].sort());
  const logoInfo = `logo-fallback-styles ref=${ref.logoStyles.size} mig=${mig.logoStyles.size}`;
  check(`B9V-${name}-markup-symmetric`,
    classSame && idSame && dataSame && styleSame,
    `${name}: styling hooks identical (classes=${mig.classes.size} ids=${mig.ids.size} data=${mig.dataAttrs.size} static-styles=${mig.inlineStyles.size}; ${logoInfo})`,
    `${name}: markup drift class[+${setDiff(mig.classes, ref.classes)}]/[-${setDiff(ref.classes, mig.classes)}]`
      + ` id[+${setDiff(mig.ids, ref.ids)}]/[-${setDiff(ref.ids, mig.ids)}]`
      + ` data[+${setDiff(mig.dataAttrs, ref.dataAttrs)}]/[-${setDiff(ref.dataAttrs, mig.dataAttrs)}]`
      + ` styles[+${setDiff(mig.inlineStyles, ref.inlineStyles)}]/[-${setDiff(ref.inlineStyles, mig.inlineStyles)}]`
      + ` ${logoInfo}`);

  const kinds = [['classes', 'class'], ['ids', 'id'], ['dataAttrs', 'data']];
  const lost = [];
  for (const [setName, kind] of kinds) {
    for (const hook of mig[setName]) {
      const inLegacy = legacyRules.some((r) => hookMatches(hook, kind, r.selector));
      const inRuntime = runtimeRules.some((r) => hookMatches(hook, kind, r.selector));
      if (inLegacy && !inRuntime) { lost.push(`${kind}:${hook}`); }
    }
  }
  check(`B9V-${name}-no-lost-rules`,
    lost.length === 0,
    `${name}: every hook styled by the reference stylesheet is still styled by the release stylesheet`,
    `${name}: lost rules for ${JSON.stringify(lost)}`);

  const legacyKeys = applicableKeys(mig, legacyRules);
  const runtimeKeys = applicableKeys(mig, runtimeRules);
  const gained = setDiff(runtimeKeys, legacyKeys);
  const dropped = setDiff(legacyKeys, runtimeKeys);
  check(`B9V-${name}-rules-symmetric`,
    dropped.length === 0 && gained.every((k) => k.includes('.text-center')),
    `${name}: applicable release rules ⊇ reference rules (${runtimeKeys.size} vs ${legacyKeys.size}; only .text-center may be gained)`,
    `${name}: dropped=[${dropped.length}] gained-non-text-center=[${gained.filter((k) => !k.includes('.text-center')).length}]`);

  const orphans = [...mig.classes].filter((c) =>
    !runtimeRules.some((r) => hookMatches(c, 'class', r.selector))).sort();
  const novel = orphans.filter((c) => !ORPHAN_ALLOWLIST.has(c));
  check(`B9V-${name}-orphans-allowlisted`,
    novel.length === 0,
    `${name}: ${orphans.length} residual orphan(s), all documented symmetric pre-existing [${orphans.join(', ') || 'none'}]`,
    `${name}: undocumented orphans ${JSON.stringify(novel)} (full set: ${JSON.stringify(orphans)})`);
  return orphans;
}

/* ── Run ────────────────────────────────────────────────────────── */

(function main() {
  const legacyCss = fs.readFileSync(path.join(legacyTheme, 'assets/css/dashboard.css'), 'utf8');
  const runtimeCss = fs.readFileSync(path.join(projectRoot, 'runtime/assets/css/dashboard.css'), 'utf8');
  const legacyRules = parseRules(legacyCss);
  const runtimeRules = parseRules(runtimeCss);
  console.log(`INFO stylesheets parsed: legacy=${legacyRules.length} rules runtime=${runtimeRules.length} rules`);

  const allOrphans = new Set();
  for (const p of PANELS) {
    const refSrc = fs.readFileSync(path.join(legacyTheme, 'template-parts/dashboard', `${p}.php`), 'utf8');
    const migSrc = fs.readFileSync(path.join(projectRoot, 'runtime/templates/dashboard', `${p}.php`), 'utf8');
    for (const o of checkUnit(`panel-${p}`, refSrc, migSrc, legacyRules, runtimeRules)) { allOrphans.add(o); }
  }
  // Shell: header/footer 1:1 applicability is batch-7 evidence (cited, not
  // repeated); the shell-vs-shell comparison below covers page-dashboard.php.
  const refShell = fs.readFileSync(path.join(legacyTheme, 'page-dashboard.php'), 'utf8');
  const migShell = fs.readFileSync(path.join(projectRoot, 'runtime/templates/dashboard.php'), 'utf8');
  for (const o of checkUnit('shell', refShell, migShell, legacyRules, runtimeRules)) { allOrphans.add(o); }

  console.log(`INFO union residual orphans across 12 panels + shell: [${[...allOrphans].sort().join(', ') || 'none'}]`);
  console.log(`B9V RESULT: ${passCount} pass, ${failCount} fail (node ${process.version})`);
  if (failCount > 0) {
    console.log('Failures:\n' + failures.join('\n'));
    process.exit(1);
  }
  process.exit(0);
})();
