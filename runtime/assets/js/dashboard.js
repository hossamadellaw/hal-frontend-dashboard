/* ════════════════════════════════════════════════════════════════
   HOSSAM ADEL LAW FIRM — DASHBOARD JS
   Version: 1.0.0 — Production Build
   Bugs Fixed:
     - initNav() if/else branches corrected
     - Removed: DEMO_ARTICLES, renderArticles(), filterArticles()
     - Removed: renderSEOTable(), setRole(), ROLE_CONFIG
     - Added: URL parameter panel routing
     - Added: Hash update on nav()
════════════════════════════════════════════════════════════════ */

/* ════════════════════════════════════════════════════════════════
   SVG ICONS — inject into sidebar
════════════════════════════════════════════════════════════════ */
'use strict';

/* [J-A] i18n Bootstrap */
const i18n = (window.hossamAjax && window.hossamAjax.i18n) ? window.hossamAjax.i18n : {};
function t(key, fallback) {
  return (i18n && key in i18n) ? i18n[key] : fallback;
}

const ICONS = {
  overview:       `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>`,
  articles:       `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>`,
  write:          `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>`,
  'all-articles': `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>`,
  'edit-article': `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>`,
  'delete-article':`<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>`,
  media:          `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>`,
  appointments:   `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>`,
  seo:            `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>`,
  translation:    `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>`,
  profile:        `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>`,
  admin:          `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>`,
  logout:         `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>`,
  inbox:`<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>`,
  finance:`<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>`,
};

// Inject icons into sidebar
Object.keys(ICONS).forEach(key => {
  const el = document.getElementById('icon-' + key);
  if (el) el.innerHTML = ICONS[key];
});

/* ════════════════════════════════════════════════════════════════
   SIDEBAR
════════════════════════════════════════════════════════════════ */
function toggleSidebar(){
  const sb = document.getElementById('sidebar');
  const collapsed = sb.classList.toggle('collapsed');
  localStorage.setItem('sb-collapsed', collapsed);
}

function openMobile(){
  document.getElementById('sidebar').classList.add('mobile-open');
  document.getElementById('overlay').classList.add('open');
}
function closeMobile(){
  document.getElementById('sidebar').classList.remove('mobile-open');
  document.getElementById('overlay').classList.remove('open');
}

/* ════════════════════════════════════════════════════════════════
   NAVIGATION + HASH
════════════════════════════════════════════════════════════════ */
/* [J-1] PANEL_TITLES — i18n */
const PANEL_TITLES = {
  overview:          t('panelOverview',      'Dashboard Overview'),
  write:             t('panelWrite',         'Write New Article'),
  'all-articles':    t('panelAllArticles',   'All My Articles'),
  'edit-article':    t('panelEditArticle',   'Edit Article'),
  'delete-article':  t('panelDeleteArticle', 'Delete Article'),
  media:             t('panelMedia',         'Files & Documents'),
  appointments:      t('panelAppointments',  'Appointments'),
  seo:               t('panelSeo',           'SEO Settings'),
  translation:       t('panelTranslation',   'Languages & Translations'),
  profile:           t('panelProfile',       'My Profile'),
  admin:             t('panelAdmin',         'Administration'),
  inbox:             t('panelInbox',         'My Inbox'),
  finance:           t('panelFinance',       'Finance & Payments'),
  members:           t('panelMembers',       'Members'),
  store:             t('panelStore',         'Store'),
  trash:             t('panelTrash',         'Trash'),
};

/* [NAV-JS] debounce state */
let _navTimer;

function nav(el, panelId){
  if(!panelId) return;
  document.querySelectorAll('.sb-item').forEach(i=>i.classList.remove('active'));
  document.querySelectorAll('.panel').forEach(p=>p.classList.remove('active'));
  if(el) el.classList.add('active');
  const panel = document.getElementById('panel-'+panelId);
  if(panel) panel.classList.add('active');
  clearTimeout(_navTimer);
  _navTimer=setTimeout(()=>{
    if(panelId==='inbox')loadInbox();
    if(panelId==='finance')loadFinance();
    if(panelId==='members')loadMembers();
    if(panelId==='store')loadProducts();
    if(panelId==='appointments')loadAppointmentsPanel();
  },150);
  const contentArea = document.querySelector('.content');
  if(contentArea) contentArea.scrollTop = 0;
  const u=new URL(location.href);
  if(!['edit-article','delete-article'].includes(panelId))u.searchParams.delete('post_id');
  u.searchParams.delete('paged'); u.searchParams.set('panel',panelId); u.hash='';
  history.replaceState(null,'',u.toString());
  document.title = (PANEL_TITLES[panelId]||panelId) + ' — Hossam Adel Law Firm';
  closeMobile();
}

function toggleSub(el, subId){
  const sub = document.getElementById(subId);
  if(!sub) return;
  const isOpen = sub.classList.contains('open');
  document.querySelectorAll('.sb-sub').forEach(s=>s.classList.remove('open'));
  document.querySelectorAll('.sb-item').forEach(i=>i.classList.remove('open'));
  if(!isOpen){ sub.classList.add('open'); el.classList.add('open'); }
}

/* [TAB-JS] Generic tab delegation — toggles active/display + lazy-loads on first open */
const _loadedTabs = new Set();
const TAB_LOADERS = {
  'my-files': ()=>loadMyFiles('active'),
  'trash': ()=>loadMyFiles('trash'),
  'orders': ()=>loadFinance(),
  'payment-methods': ()=>loadPaymentMethods(),
  'saved-cards': ()=>loadSavedTokens(),
  'products': ()=>loadProducts(),
  'all': ()=>loadMembers(),
};
function switchTab(el){
  const tabId = el && el.dataset.tab;
  if(!tabId) return;
  const scope = el.closest('.tab-group') || document;
  scope.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
  el.classList.add('active');
  scope.querySelectorAll('[data-tab-content]').forEach(c=>{
    const active = c.dataset.tabContent === tabId;
    c.classList.toggle('active', active);
    c.style.display = active ? '' : 'none';
  });
  const key = (scope.dataset.tabGroup||'') + ':' + tabId;
  if(!_loadedTabs.has(key)){
    _loadedTabs.add(key);
    if(TAB_LOADERS[tabId]) TAB_LOADERS[tabId]();
  }
}

/* ── BUG FIX: initNav() — both branches were identical in prototype ── */
function initNav(){
  /* [B9-02] hash مشوّه (مثل '#%') يجعل decodeURIComponent يرمي URIError
     فيقطع callback التهيئة قبل loadNotifications. السقوط الآمن إلى
     'overview' يحافظ على اكتمال DOMContentLoaded. */
  let hash = 'overview';
  try {
    hash = decodeURIComponent(location.hash.replace('#','')) || 'overview';
  } catch (navHashError) {
    hash = 'overview';
  }
  const safeHash = /^[\w-]+$/.test(hash) ? hash : 'overview';
  const articlePanels = ['write','all-articles','edit-article','delete-article'];

  if(articlePanels.includes(safeHash)){
    const sub = document.getElementById('sub-articles');
    if(sub) sub.classList.add('open');
    const trigger = document.querySelector('.sb-item[onclick*="sub-articles"]');
    if(trigger) trigger.classList.add('open');
  }

  if(safeHash){
    const el = document.querySelector(`.sb-item[data-panel="${safeHash}"]`);
    if(el) nav(el, safeHash);
    else nav(document.querySelector('[data-panel=overview]'),'overview');
  } else {
    nav(document.querySelector('[data-panel=overview]'),'overview');
  }
}

/* ════════════════════════════════════════════════════════════════
   THEME
════════════════════════════════════════════════════════════════ */
const MOON_SVG=`<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>`;
const SUN_SVG=`<circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>`;

function toggleTheme(){
  const isDark = document.documentElement.getAttribute('data-theme')==='dark';
  const next = isDark ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', next);
  const svg = document.getElementById('theme-icon-svg');
  if(svg) svg.innerHTML = isDark ? MOON_SVG : SUN_SVG;
  localStorage.setItem('theme', next);
}

/* ════════════════════════════════════════════════════════════════
   LIVE CLOCK + GREETING
════════════════════════════════════════════════════════════════ */
function updateClock(){
  const now = new Date();
  const h = now.getHours(), m = now.getMinutes();
  const ampm = h>=12?'PM':'AM';
  const hh = String(h%12||12).padStart(2,'0');
  const mm = String(m).padStart(2,'0');
  const el = document.getElementById('hdr-clock');
  if(el) el.textContent = `${hh}:${mm} ${ampm}`;
  const sal = document.getElementById('hdr-salut');
  if(sal){
    /* [J-2] greetings — i18n */
    const greet = h<12
      ? t('goodMorning',   'Good morning')
      : h<17
        ? t('goodAfternoon','Good afternoon')
        : t('goodEvening',  'Good evening');
    sal.textContent = greet;
  }
}
setInterval(updateClock,1000);
updateClock();

/* ════════════════════════════════════════════════════════════════
   XSS ESCAPE HELPER
════════════════════════════════════════════════════════════════ */
function esc(s){ const d=document.createElement('div'); d.textContent=String(s); return d.innerHTML; }

/* ════════════════════════════════════════════════════════════════
   SKELETON LOADING  [J-03]
════════════════════════════════════════════════════════════════ */
function skeletonHTML(n=4) {
  return Array.from({length:n}, (_,i) =>
    `<div class="skel-row">
      <div class="skel-line" style="width:${60+i*8}%"></div>
      <div class="skel-line skel-sm" style="width:${35+i*5}%"></div>
    </div>`
  ).join('');
}

/* ════════════════════════════════════════════════════════════════
   NOTIFICATIONS
════════════════════════════════════════════════════════════════ */
function showToast(msg, type='info'){
  const icons={
    info:`<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>`,
    success:`<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>`,
    error:`<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>`
  };
  const colors={info:'var(--info)',success:'var(--success)',error:'var(--danger)'};
  const container = document.getElementById('toast-container');
  if(!container) return;
  const toast = document.createElement('div');
  toast.className = `toast toast-${type}`;
  toast.innerHTML = `<span class="toast-icon" style="color:${colors[type]}">${icons[type]||icons.info}</span><span style="flex:1">${esc(msg)}</span><span class="toast-close" onclick="this.parentElement.remove()">✕</span>`;
  container.appendChild(toast);
  setTimeout(()=>{
    toast.classList.add('toast-out');
    setTimeout(()=>toast.remove(), 250);
  }, 4000);
}

/* ════════════════════════════════════════════════════════════════
   ARTICLE TABLE: Live search on PHP-rendered rows
════════════════════════════════════════════════════════════════ */
function liveSearch(val){
  const rows = document.querySelectorAll('#articles-body tr');
  let count = 0;
  const q = val.toLowerCase();
  rows.forEach(row=>{
    if(row.dataset.phpEmpty) return;
    const txt = row.textContent.toLowerCase();
    const show = !q || txt.includes(q);
    row.style.display = show ? '' : 'none';
    if(show) count++;
  });
  const fc = document.getElementById('filter-count');
  /* [J-9] counter — i18n */
  if(fc) fc.textContent = t('showingX','Showing {x} articles').replace('{x}', count);
  const empty = document.getElementById('articles-empty');
  if(empty) empty.classList.toggle('hidden', count > 0);
}

/* [AR-02-JS] Restore article from Trash */
/* ════════════════════════════════════════════════════════════════
   KEYBOARD SHORTCUTS
════════════════════════════════════════════════════════════════ */
document.addEventListener('keydown', e => {
  if(e.key==='Escape') closeMobile();
  if(e.key==='s' && e.altKey && !e.ctrlKey && !e.metaKey){
    e.preventDefault();
    document.getElementById('search-input')?.focus();
  }
});

/* ════════════════════════════════════════════════════════════════
   INIT
════════════════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', () => {
  if(localStorage.getItem('sb-collapsed')==='true' && window.innerWidth>900){
    document.getElementById('sidebar').classList.add('collapsed');
  }
  const savedTheme = localStorage.getItem('theme');
  if(savedTheme){
    document.documentElement.setAttribute('data-theme', savedTheme);
    const svg = document.getElementById('theme-icon-svg');
    if(svg) svg.innerHTML = savedTheme==='dark' ? MOON_SVG : SUN_SVG;
  }
  document.querySelector('#articles-body td[colspan]')?.closest('tr')?.setAttribute('data-php-empty','1');
  const fc = document.getElementById('filter-count');
  if(fc){
    const realRows = Array.from(document.querySelectorAll('#articles-body tr')).filter(r=>!r.dataset.phpEmpty);
    /* [J-10] counter — i18n */
    fc.textContent = t('showingXofY','Showing {x} of {y} articles')
        .replace('{x}', realRows.length)
        .replace('{y}', parseInt(fc.dataset.found,10)||realRows.length);
  }
  const params = new URLSearchParams(location.search);
  const panelParam = params.get('panel');
  const safePanelParam = panelParam && /^[\w-]+$/.test(panelParam) ? panelParam : null;
  if(safePanelParam){
    const el = document.querySelector(`.sb-item[data-panel="${safePanelParam}"]`);
    if(el){
      const articlePanels = ['write','all-articles','edit-article','delete-article'];
      if(articlePanels.includes(safePanelParam)){
        const sub = document.getElementById('sub-articles');
        if(sub) sub.classList.add('open');
        const trigger = document.querySelector('.sb-item[onclick*="sub-articles"]');
        if(trigger) trigger.classList.add('open');
      }
      nav(el, safePanelParam);
    } else {
      initNav();
    }
  } else {
    initNav();
  }

  loadNotifications();
  document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "visible") loadNotifications();
  });
  setInterval(function () {
    if (document.visibilityState === "visible") loadNotifications();
  }, 60000);
  // loadFinance تُستدعى عند فتح البانل فقط
});

/* ══ Language Dropdown — Toggle + Close ═══════════════════ */
(function () {

  document.addEventListener("click", function (e) {
    var trigger = e.target.closest(".hdr-lang-trigger");
    var inLang  = e.target.closest(".hdr-lang");

    if (trigger) {
      var wrap = trigger.closest(".hdr-lang");
      var open = wrap.classList.toggle("hdr-lang--open");
      trigger.setAttribute("aria-expanded", String(open));
      return;
    }
    if (!inLang) {
      document.querySelectorAll(".hdr-lang--open").forEach(function (el) {
        el.classList.remove("hdr-lang--open");
        var btn = el.querySelector(".hdr-lang-trigger");
        if (btn) btn.setAttribute("aria-expanded", "false");
      });
    }
  });

  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") {
      document.querySelectorAll(".hdr-lang--open").forEach(function (el) {
        el.classList.remove("hdr-lang--open");
        var btn = el.querySelector(".hdr-lang-trigger");
        if (btn) {
          btn.setAttribute("aria-expanded", "false");
          btn.focus();
        }
      });
    }
  });

}());
