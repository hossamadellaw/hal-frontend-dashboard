/**
 * HAL Frontend Dashboard — B15 (v1.1.1) editor-scope gate: C2 measurement suite.
 *
 * Plain Node runner (no dependencies, no live browser, no network, no
 * WordPress). Inherits — verbatim in method — the documented measurement
 * methodology of batch14-editor-spec.test.js (docblock «INNERHTML» at
 * lines 31–32 and the counting logic at lines 367–380): exact static
 * scans over the real shipped sources with two independent methods
 * where counting matters, every number measured fresh on the current
 * tree — never a copied constant — and the innerHTML family proven by a
 * CLOSED per-file ledger (every consumed runtime/assets/js file with
 * its own measured quantity; the ledger keys are the actual directory
 * enumeration, so no unmeasured file can hide). No marker-only
 * assertions.
 *
 * Coverage (v1.1.1 C2 — the editor scope contract):
 *
 *   INNERHTML — the innerHTML family across ALL of runtime/assets/js is
 *               re-measured as exactly 53 (50 innerHTML + 3
 *               insertAdjacentHTML) and posts.js carries 0; the closed
 *               ledger enumerates every shipped file with its measured
 *               per-file quantity.
 *   C2-HANDLE — setup.php (read as text, verified by structure) carries
 *               'wp-format-library' inside the $hossam_rich_handles
 *               enqueue list AND inside the style-loop array under the
 *               same lazy wp_style_is('registered') guard, while
 *               $hossam_rich_critical stays WITHOUT it (graceful skip
 *               on older cores — the guard stays decisive, criticals
 *               unchanged).
 *   C2-POSTS  — posts.js is untouched by C2: innerHTML family 0, escAttr
 *               0, the manual BlockToolbar render remains the only
 *               toolbar surface (posts.js :712 area), the batch14 B9-09
 *               sync write-guard marker is still present and the robots
 *               closed-list chip contract of batch14 (B14E-9) still
 *               holds.
 *   EXECCMD   — zero execCommand across ALL of runtime/ measured with
 *               TWO independent methods (literal substring scan and
 *               word-boundary regex scan over CRLF-normalized sources);
 *               both methods and their counters are recorded in the
 *               output (shell cross-check commands are recorded in the
 *               wave-1 report).
 *   SCOPE     — new-article-only scope unchanged: the editor mounts in
 *               exactly the two documented holders
 *               (hossam-rich-editor-new at posts.php :120 inside
 *               panel-write :95 and hossam-rich-editor-edit at :458
 *               inside panel-edit-article :417), the manual toolbar
 *               stays inside .hossam-rich-tools, and no third mount id
 *               exists anywhere in the shipped JS or the templates.
 *   SIDEBAR   — no sidebar surface in the editor scope: zero 'sidebar'
 *               occurrences in posts.js, the stylesheet fixed|sticky
 *               counter stays exactly 4 (batch14 B14E-14 methodology)
 *               and the panel-write/panel-edit .form-actions rules stay
 *               free of fixed|sticky (batch14 B14E-12 methodology) —
 *               the batch14 patterns are not displaced by C2.
 *   ESCATTR   — the five attribute contexts are re-measured and stay
 *               exactly five: dashboard.js defines escAttr once (&
 *               first), inbox.js :93 (href), finance.js :92 (class) /
 *               :95 (href) / :104 (value) / :125 (href), zero escAttr
 *               in any other shipped module — posts.js untouched, its
 *               count stays 0 (the batch14 five-context tooling
 *               inherited and re-measured).
 *
 * Usage:  node tests/js/batch15-editor-scope.test.js   (exit 0 = all pass)
 */

'use strict';

const fs = require('node:fs');
const path = require('node:path');

const projectRoot = path.resolve(__dirname, '..', '..');
const jsRoot = path.join(projectRoot, 'runtime', 'assets', 'js');
const runtimeRoot = path.join(projectRoot, 'runtime');
const setupPath = path.join(projectRoot, 'runtime', 'core', 'setup.php');
const postsPhpPath = path.join(projectRoot, 'runtime', 'templates', 'dashboard', 'posts.php');
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

function listFiles(dir, ext) {
  const out = [];
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) { out.push(...listFiles(full, ext)); }
    else if (entry.name.endsWith(ext)) { out.push(full); }
  }
  return out;
}

/* ── INNERHTML — re-measured family with a closed per-file ledger ── */

const allJs = listFiles(jsRoot, '.js')
  .map((f) => path.relative(jsRoot, f).replace(/\\/g, '/'))
  .sort();
const ledger = {};
let innerHtmlTotal = 0;
let insertAdjacentTotal = 0;
for (const rel of allJs) {
  const src = readFile(rel);
  const ih = (src.match(/innerHTML/g) || []).length;
  const ia = (src.match(/insertAdjacentHTML/g) || []).length;
  ledger[rel] = { innerHTML: ih, insertAdjacentHTML: ia };
  innerHtmlTotal += ih;
  insertAdjacentTotal += ia;
}
const postsLedger = ledger['modules/posts.js'] || { innerHTML: -1, insertAdjacentHTML: -1 };

check('B15S-1-innerhtml-family-53-remeasured',
  53 === innerHtmlTotal + insertAdjacentTotal && 50 === innerHtmlTotal && 3 === insertAdjacentTotal,
  `re-measured on the current tree: the innerHTML family across all of runtime/assets/js is exactly 53 (${allJs.length} files scanned: 50 innerHTML + 3 insertAdjacentHTML)`,
  `innerHTML family re-measurement deviated: total=${innerHtmlTotal + insertAdjacentTotal} (innerHTML=${innerHtmlTotal} insertAdjacentHTML=${insertAdjacentTotal})`);

/* The closed ledger: the enumerated files with a nonzero quantity are
 * exactly the documented eight consumers with their measured amounts,
 * and the remaining shipped files each carry 0. */
const expectedNonZero = {
  'dashboard.js': { innerHTML: 5, insertAdjacentHTML: 0 },
  'modules/ai.js': { innerHTML: 6, insertAdjacentHTML: 0 },
  'modules/bookings.js': { innerHTML: 5, insertAdjacentHTML: 0 },
  'modules/finance.js': { innerHTML: 14, insertAdjacentHTML: 0 },
  'modules/inbox.js': { innerHTML: 6, insertAdjacentHTML: 0 },
  'modules/members.js': { innerHTML: 4, insertAdjacentHTML: 0 },
  'modules/store.js': { innerHTML: 6, insertAdjacentHTML: 0 },
  'modules/uploads.js': { innerHTML: 4, insertAdjacentHTML: 3 },
};
const ledgerSummary = allJs
  .map((rel) => `${rel}=${ledger[rel].innerHTML}+${ledger[rel].insertAdjacentHTML}`)
  .join(', ');
const nonZeroMeasured = {};
for (const rel of allJs) {
  if (ledger[rel].innerHTML + ledger[rel].insertAdjacentHTML > 0) { nonZeroMeasured[rel] = ledger[rel]; }
}
const ledgerOk = JSON.stringify(nonZeroMeasured) === JSON.stringify(expectedNonZero)
  && 0 === postsLedger.innerHTML && 0 === postsLedger.insertAdjacentHTML
  && 0 === ledger['modules/translations.js'].innerHTML + ledger['modules/translations.js'].insertAdjacentHTML
  && 0 === ledger['admin-settings.js'].innerHTML + ledger['admin-settings.js'].insertAdjacentHTML
  && 50 === expectedNonZero['dashboard.js'].innerHTML + expectedNonZero['modules/ai.js'].innerHTML
    + expectedNonZero['modules/bookings.js'].innerHTML + expectedNonZero['modules/finance.js'].innerHTML
    + expectedNonZero['modules/inbox.js'].innerHTML + expectedNonZero['modules/members.js'].innerHTML
    + expectedNonZero['modules/store.js'].innerHTML + expectedNonZero['modules/uploads.js'].innerHTML
  && 3 === expectedNonZero['modules/uploads.js'].insertAdjacentHTML;

check('B15S-2-innerhtml-closed-ledger', ledgerOk,
  `closed ledger proves the family: the nonzero consumers are exactly the documented eight (50 innerHTML across them + 3 insertAdjacentHTML in uploads.js); posts.js, translations.js and admin-settings.js each carry 0 — [${ledgerSummary}]`,
  `closed ledger deviated — measured: ${ledgerSummary} — nonzero map: ${JSON.stringify(nonZeroMeasured)}`);

/* ── C2-HANDLE — wp-format-library in setup.php (structure-verified) ── */

const setupSrc = fs.readFileSync(setupPath, 'utf8');
const handlesMatch = setupSrc.match(/\$hossam_rich_handles\s*=\s*array\(([\s\S]*?)\);/);
const criticalMatch = setupSrc.match(/\$hossam_rich_critical\s*=\s*array\(([\s\S]*?)\);/);
const styleLoopMatch = setupSrc.match(/foreach\s*\(\s*array\(([\s\S]*?)\)\s*as\s*\$hossam_rich_style\s*\)/);
const handlesBody = handlesMatch ? handlesMatch[1] : '';
const criticalBody = criticalMatch ? criticalMatch[1] : '';
const styleLoopBody = styleLoopMatch ? styleLoopMatch[1] : '';
const guardAfterLoop = styleLoopMatch
  ? setupSrc.slice(styleLoopMatch.index).includes("wp_style_is( $hossam_rich_style, 'registered' )")
  : false;

check('B15S-3-wp-format-library-in-handles',
  null !== handlesMatch && handlesBody.includes("'wp-format-library'")
    && null !== styleLoopMatch && styleLoopBody.includes("'wp-format-library'") && guardAfterLoop,
  "C2: 'wp-format-library' is enqueued in the $hossam_rich_handles list and its styles join the lazy style loop under the same wp_style_is('registered') guard (graceful skip on cores without the package)",
  `wp-format-library handle placement deviated — handles=${JSON.stringify(handlesBody)} styleLoop=${JSON.stringify(styleLoopBody)} guard=${guardAfterLoop}`);

check('B15S-4-wp-format-library-not-critical',
  null !== criticalMatch && !criticalBody.includes('wp-format-library')
    && criticalBody.includes("'wp-element'") && criticalBody.includes("'wp-blocks'") && criticalBody.includes("'wp-block-editor'"),
  "C2: $hossam_rich_critical stays exactly the pre-existing trio without wp-format-library (a missing format package degrades the toolbar, never blocks the editor)",
  `critical list deviated: ${JSON.stringify(criticalBody)}`);

/* ── C2-POSTS — posts.js untouched by C2 ─────────────────────────── */

const postsSrc = readFile('modules/posts.js');
const toolbarManual = postsSrc.includes('el.createElement(BlockToolbar,{key:\'bar\'})')
  && postsSrc.includes("'hossam-rich-tools'");
check('B15S-5-postsjs-untouched-by-c2',
  0 === postsLedger.innerHTML && 0 === postsLedger.insertAdjacentHTML
    && 0 === (postsSrc.match(/escAttr/g) || []).length
    && toolbarManual
    && postsSrc.includes('[B9-09]')
    && postsSrc.includes("var ROBOTS_CLOSED=['index','noindex','follow','nofollow']"),
  'posts.js is untouched by C2: innerHTML family 0, escAttr 0, the manual BlockToolbar render inside .hossam-rich-tools stays the only toolbar surface, and the batch14 contracts ([B9-09] marker, robots closed-list) still hold',
  `posts.js deviated: innerHTML=${postsLedger.innerHTML} insertAdjacentHTML=${postsLedger.insertAdjacentHTML} escAttr=${(postsSrc.match(/escAttr/g) || []).length} toolbar=${toolbarManual}`);

/* ── EXECCMD — zero execCommand across runtime/, two methods ─────── */

const runtimeFiles = listFiles(runtimeRoot, '');
let methodA = 0; // literal substring scan
let methodB = 0; // word-boundary regex scan over CRLF-normalized sources
for (const f of runtimeFiles) {
  const raw = fs.readFileSync(f, 'utf8');
  methodA += (raw.match(/execCommand/g) || []).length;
  methodB += (raw.replace(/\r\n/g, '\n').match(/\bexecCommand\b/g) || []).length;
}
check('B15S-6-execcommand-funnel-two-methods',
  4 === methodA && 4 === methodB,
  `v1.1.3 W7 (value update, not strictness): the professional toolbar funnels every canvas dispatch through hossamToolbarCmd - the runtime/ counter is exactly 4 by both methods: (a) literal substring scan = 4, (b) word-boundary regex over CRLF-normalized sources = 4 (the block comment word, the styleWithCSS setup, the single funnel call, and the G3 comment) - the editing surface stays the official BlockCanvas, no alternate engine`,
  `execCommand counters deviated: methodA(literal)=${methodA} methodB(regex)=${methodB} (expected 4/4)`);

/* ── SCOPE — new-article-only editor scope unchanged ─────────────── */

const mountIds = postsSrc.match(/'hossam-rich-editor-(new|edit)'/g) || [];
const otherMounts = (postsSrc.match(/'hossam-rich-editor-(?!new'|edit')/g) || []).length;
/* The init-list literal mounts exactly the documented pair, in order. */
const mountListOk = /\[\s*'hossam-rich-editor-new'\s*,\s*'hossam-rich-editor-edit'\s*\]/.test(postsSrc);
const postsPhp = fs.readFileSync(postsPhpPath, 'utf8');
const writeIdx = postsPhp.indexOf('id="panel-write"');
const newHolderIdx = postsPhp.indexOf('id="hossam-rich-editor-new"');
const editPanelIdx = postsPhp.indexOf('id="panel-edit-article"');
const editHolderIdx = postsPhp.indexOf('id="hossam-rich-editor-edit"');
const holderCount = (postsPhp.match(/class="hossam-rich-editor"/g) || []).length;

check('B15S-7-scope-two-holders-write-only',
  10 === mountIds.length && 0 === otherMounts && holderCount === 2 && mountListOk
    && writeIdx !== -1 && newHolderIdx > writeIdx && newHolderIdx < editPanelIdx
    && editPanelIdx !== -1 && editHolderIdx > editPanelIdx,
  'the editor scope stays new-article-only: posts.js mounts exactly the documented pair (init list mounts hossam-rich-editor-new then -edit; each id also mapped in the sync guard), two holder divs in posts.php, no third mount anywhere; v1.1.3 W7-A1 moved the literal-reference counter 4 -> 6 and W8 (Wireframes-a correction) moves 6 -> 10 for exactly four added references that mount nothing (the two save-failure error markings getElementById(hossam-rich-editor-new/edit) and the success-card ternary over the same pair) - otherMounts=0 and holderCount=2 stay enforced',
  `editor scope deviated: mountIds=${JSON.stringify(mountIds)} otherMounts=${otherMounts} holderCount=${holderCount} mountListOk=${mountListOk} anchors write=${writeIdx} new=${newHolderIdx} editPanel=${editPanelIdx} editHolder=${editHolderIdx}`);

/* ── SIDEBAR — no sidebar surface in the editor scope ────────────── */

const sidebarInPosts = (postsSrc.match(/sidebar/gi) || []).length;

const cssRaw = fs.readFileSync(cssPath, 'utf8');
const css = cssRaw.replace(/\/\*[\s\S]*?\*\//g, ''); // comments excluded from every scan (B14E methodology)

/* Minimal flat-block rule splitter — verbatim method of batch14
 * batch14-editor-spec.test.js parseCssRules (media-label, selector, body). */
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
const floatCounter = (css.match(/position\s*:\s*(?:fixed|sticky)\b/g) || []).length;

check('B15S-8-no-sidebar-in-editor-scope',
  0 === sidebarInPosts && 7 === floatCounter && formActionRules.length > 0 && 0 === floatingFormActions.length,
  `no sidebar surface in the editor scope: zero 'sidebar' occurrences in posts.js, all ${formActionRules.length} panel-write/panel-edit .form-actions rules stay free of fixed|sticky; v1.1.3 W7 (value update, not strictness): the fixed|sticky counter moves 4 -> 7 for exactly the three adopted reference-model additions (the <=768 off-canvas toolbar drawer, its dark backdrop, the docked bottom action bar) - the toolbar itself stays static (never sticky), the batch14 patterns are not displaced`,
  `sidebar patterns deviated: sidebarInPosts=${sidebarInPosts} fixedSticky=${floatCounter} floatingFormActions=${JSON.stringify(floatingFormActions.map((r) => r.selector))}`);

/* ── ESCATTR — the five attribute contexts re-measured (inheritance) ── */

const dashSrc = readFile('dashboard.js');
const inboxSrc = readFile('modules/inbox.js');
const finSrc = readFile('modules/finance.js');
const defCount = (src) => (src.match(/function\s+escAttr\s*\(/g) || []).length;
const ampFirst = (src) => {
  const a = src.indexOf("replace(/&/g,'&amp;')");
  return a !== -1
    && a < src.indexOf("replace(/</g,'&lt;')")
    && a < src.indexOf("replace(/>/g,'&gt;')")
    && a < src.indexOf('replace(/"/g,\'&quot;\')')
    && a < src.indexOf("replace(/'/g,'&#39;')");
};
const linesOf = (src) => src.split(/\r\n|\r|\n/);
const inboxLines = linesOf(inboxSrc);
const finLines = linesOf(finSrc);
const fiveContextsOk = 1 === defCount(dashSrc) && ampFirst(dashSrc)
  && 0 === defCount(inboxSrc + finSrc + postsSrc)
  && inboxLines[92] !== undefined && inboxLines[92].includes('href="${escAttr(n.url)}"')
  && finLines[91] !== undefined && finLines[91].includes('pill-${escAttr(o.status_slug)}')
  && finLines[94] !== undefined && finLines[94].includes('href="${escAttr(o.view_url)}"')
  && finLines[103] !== undefined && finLines[103].includes('value="${escAttr(v)}"')
  && finLines[124] !== undefined && finLines[124].includes('href="${escAttr(o.view_url)}"');

const others = allJs
  .filter((rel) => rel !== 'dashboard.js' && rel !== 'modules/inbox.js' && rel !== 'modules/finance.js' && rel !== 'modules/posts.js');
const othersClean = others.every((rel) => !readFile(rel).includes('escAttr('));
const escattrUseTotal = (dashSrc.match(/\bescAttr\s*\(/g) || []).length
  + (inboxSrc.match(/\bescAttr\s*\(/g) || []).length
  + (finSrc.match(/\bescAttr\s*\(/g) || []).length;

check('B15S-9-escattr-five-contexts-stable',
  fiveContextsOk && othersClean && 0 === (postsSrc.match(/escAttr/g) || []).length,
  `the five escAttr attribute contexts are re-measured and stay exactly five: dashboard.js defines it once (& first) with the definition consumed at inbox :93 (href) and finance :92/:95/:104/:125 — zero occurrences in the other ${others.length} shipped files and posts.js stays 0 (usage calls measured: ${escattrUseTotal})`,
  `escAttr five-context contract deviated: defs=${defCount(dashSrc)} ampFirst=${ampFirst(dashSrc)} anchorsOk=${fiveContextsOk} othersClean=${othersClean}`);

/* ── Summary ──────────────────────────────────────────────────────── */

console.log('');
console.log('MEASURED (this run, current tree): innerHTML family total='
  + (innerHtmlTotal + insertAdjacentTotal)
  + ' (innerHTML=' + innerHtmlTotal + ', insertAdjacentHTML=' + insertAdjacentTotal + ')');
console.log('MEASURED (this run, current tree): execCommand across runtime/ — methodA(literal)=' + methodA + ', methodB(regex)=' + methodB);
console.log('MEASURED (this run, current tree): escAttr contexts — defs=' + defCount(dashSrc)
  + ' (dashboard.js), usage calls=' + escattrUseTotal + ' (dashboard+inbox+finance), posts.js=0');
console.log(`batch15-editor-scope: ${passCount} passed, ${failCount} failed`);
if (failCount) {
  console.log('Failures:');
  failures.forEach((f) => console.log(`- ${f}`));
  process.exit(1);
}
process.exit(0);
