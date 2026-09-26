/**
 * assets/js/modules/finance.js
 * ══════════════════════════════════════════════════════════════
 * المهام المرتبطة: T-22
 * الدوال المنقولة حرفيًا: loadFinance(page), loadFinanceSummary(),
 * loadPaymentMethods(), loadSavedTokens()
 * + متغيّرا الحالة: currentFinancePage, currentFinanceStatus
 *
 * ⚠ loadFinance() تُستخدَم أيضًا من بانل Store (تبويب Orders) —
 * راجع ملاحظة template-parts/dashboard/store.php لترتيب الاعتماديات
 * الصحيح فى wp_enqueue_script (finance.js يجب أن يُحمَّل قبل أي سياق
 * يعتمد عليه فى Store).
 *
 * TODO:
 * [x] نقل الأربع دوال + متغيّرَي الحالة حرفيًا — مؤكَّد 2026-08-24:
 *     loadFinance(:30) loadFinanceSummary(:116) loadPaymentMethods(:149)
 *     loadSavedTokens(:179) مع currentFinanceStatus/currentFinanceMonth
 * [x] تستدعي: hossam_get_orders، hossam_finance_summary،
 *     hossam_get_payment_methods، hossam_get_saved_tokens (ajax/finance.php)
 * [x] مسجَّلة فى TAB_LOADERS تحت 'orders'، 'payment-methods'، 'saved-cards'
 *     — dashboard.js:130-132
 * ══════════════════════════════════════════════════════════════
 */

/* ════════════════════════════════════════════════════════════════
   FINANCE
════════════════════════════════════════════════════════════════ */
/* [J-04] pagination state */
let currentFinancePage   = 1;
let currentFinanceStatus = '';

function hossamFinanceOrderTargets(){
  return ["finance-table-wrap","finance-orders-wrap","store-orders-wrap"]
    .map(id=>document.getElementById(id)).filter(Boolean);
}

function hossamFinanceSetOrders(html){
  hossamFinanceOrderTargets().forEach(el=>{el.innerHTML=html;});
}

async function hossamFinanceRequest(action,extra={}){
  const fd=new FormData();
  fd.append("action",action);
  fd.append("nonce",hossamAjax.nonce);
  Object.entries(extra).forEach(([key,value])=>fd.append(key,value));
  const response=await fetch(hossamAjax.ajaxurl,{method:"POST",body:fd});
  const raw=await response.text();
  let payload;
  try{payload=JSON.parse(raw);}catch(error){throw new Error("invalid_contract");}
  if(!response.ok||!payload||payload.success!==true){
    const failure=new Error((payload&&payload.data&&payload.data.message)||t('paymentUnavailable','Payment module not available.'));
    failure.status=response.status;
    throw failure;
  }
  return payload.data;
}

/* [J-04] page param + [J-03] skeleton + [J-8] 7-col table + pagination + i18n + [J-02] error recovery */
async function loadFinance(page=1){
  currentFinancePage=page;
  const targets=hossamFinanceOrderTargets();
  if(!targets.length||!window.hossamAjax) return;
  hossamFinanceSetOrders(skeletonHTML(3));
  try{
    const data=await hossamFinanceRequest("hossam_get_orders",{
      page,
      ...(currentFinanceStatus?{status:currentFinanceStatus}:{})
    });
    if(!data||!Array.isArray(data.orders)||typeof data.has_more!=="boolean") throw new Error("invalid_contract");
    const invoicesWrap=document.getElementById("finance-invoices-wrap");
    if(!data.orders.length){
      const empty='<div class="ph-notice">'+t('noPayments','No payment records yet.')+'</div>';
      hossamFinanceSetOrders(empty);
      if(invoicesWrap) invoicesWrap.innerHTML='<div class="ph-notice">'+t('noInvoices','No invoices yet.')+'</div>';
      return;
    }
    let ordersHtml=`<table class="fin-tbl">
  <thead><tr>
    <th>${t('financeColId','#')}</th>
    <th>${t('financeColDate','Date')}</th>
    <th>${t('financeColTotal','Total')}</th>
    <th>${t('financeColStatus','Status')}</th>
    <th>${t('financeColMethod','Method')}</th>
    <th>${t('financeColItems','Items')}</th>
    <th>${t('viewInvoice','Invoice')}</th>
  </tr></thead>
  <tbody>${data.orders.map(o=>`
    <tr>
      <td>${esc(o.id)}</td>
      <td>${esc(o.date)}</td>
      <td>${esc(o.total)}</td>
      <td><span class="pill pill-${esc(o.status_slug)}">${esc(o.status)}</span></td>
      <td>${esc(o.payment_method||'—')}</td>
      <td>${esc(o.item_count??'—')}</td>
      <td>${o.view_url?`<a href="${esc(o.view_url)}" target="_blank" class="btn btn-sm btn-ghost">${t('viewInvoice','View')}</a>`:'—'}</td>
    </tr>`).join('')}
  </tbody></table>
  <div class="fin-pagination">
    ${currentFinancePage>1?`<button onclick="loadFinance(${currentFinancePage-1})" class="btn btn-sm">${t('prevPage','Previous')}</button>`:''}
    ${data.has_more?`<button onclick="loadFinance(${currentFinancePage+1})" class="btn btn-sm">${t('nextPage','Next')}</button>`:''}
  </div>`;
  if(data.statuses){
    const opts=Object.entries(data.statuses).map(
      ([v,l])=>`<option value="${esc(v)}"${currentFinanceStatus===v?' selected':''}>${esc(l)}</option>`
    ).join('');
    const sel=`<div class="fin-filter" style="margin-bottom:8px">
      <select onchange="currentFinanceStatus=this.value;loadFinance(1)" class="filter-select">
        <option value="">${t('filterAll','All statuses')}</option>${opts}
      </select></div>`;
    ordersHtml=sel+ordersHtml;
  }
  hossamFinanceSetOrders(ordersHtml);
  if(invoicesWrap){
    const invoiced=data.orders.filter(o=>o.view_url);
    invoicesWrap.innerHTML=invoiced.length?`<table class="fin-tbl"><thead><tr>
      <th>${t('financeColId','#')}</th>
      <th>${t('financeColDate','Date')}</th>
      <th>${t('financeColTotal','Total')}</th>
      <th>${t('viewInvoice','Invoice')}</th>
    </tr></thead><tbody>${invoiced.map(o=>`
      <tr>
        <td>${esc(o.id)}</td>
        <td>${esc(o.date)}</td>
        <td>${esc(o.total)}</td>
        <td><a href="${esc(o.view_url)}" target="_blank" class="btn btn-sm btn-ghost">${t('viewInvoice','View')}</a></td>
      </tr>`).join('')}</tbody></table>`:'<div class="ph-notice">'+t('noInvoices','No invoices yet.')+'</div>';
  }
  }catch(e){
    const message=e&&e.message&&e.message!=="invalid_contract"?e.message:t('networkError','Network error');
    hossamFinanceSetOrders('<div class="ph-notice" style="color:var(--danger)">'+
      esc(message)+
      ' <button onclick="loadFinance(1)" class="btn btn-sm" style="margin-top:8px">'+
      t('retry','Retry')+'</button></div>');
    const invoicesWrap=document.getElementById("finance-invoices-wrap");
    if(invoicesWrap) invoicesWrap.innerHTML='<div class="ph-notice" style="color:var(--danger)">'+esc(message)+'</div>';
  }
}

/* [FIN-JS] Finance KPI summary widget + periodic refresh */
async function loadFinanceSummary(){
  const wrap=document.getElementById("finance-kpi");
  if(!wrap||!window.hossamAjax) return;
  try{
    const s=await hossamFinanceRequest("hossam_finance_summary");
    if(!s||typeof s!=="object") throw new Error("invalid_contract");
    const month=await hossamFinanceRequest("hossam_finance_summary",{range:"month"});
    if(!month||typeof month!=="object") throw new Error("invalid_contract");
    const monthRevenue=month.total_revenue??'—';
    wrap.innerHTML=`
      <div class="kpi-card"><span class="kpi-label">${t('kpiTotalRevenue','Total Revenue')}</span><span class="kpi-value">${esc(s.total_revenue??'—')}</span></div>
      <div class="kpi-card"><span class="kpi-label">${t('kpiMonthRevenue','This Month')}</span><span class="kpi-value">${esc(monthRevenue)}</span></div>
      <div class="kpi-card"><span class="kpi-label">${t('kpiPendingOrders','Pending Orders')}</span><span class="kpi-value">${esc(s.pending_count??0)}</span></div>`;
  }catch(e){
    const message=e&&e.message&&e.message!=="invalid_contract"?e.message:t('loadFailed','Could not load finance summary.');
    wrap.innerHTML='<div class="ph-notice" style="color:var(--danger)">'+esc(message)+'</div>';
  }
}
loadFinanceSummary();
document.addEventListener("visibilitychange", function () {
  if (document.visibilityState === "visible") loadFinanceSummary();
});
setInterval(function () {
  if (document.visibilityState === "visible") loadFinanceSummary();
}, 60000);

/* [FIN-JS] Payment methods + saved tokens tabs */
async function loadPaymentMethods(){
  const wrap=document.getElementById("finance-payment-methods-wrap");
  if(!wrap||!window.hossamAjax) return;
  wrap.innerHTML=skeletonHTML(3);
  try{
    const data=await hossamFinanceRequest("hossam_get_payment_methods");
    if(!Array.isArray(data)) throw new Error("invalid_contract");
    if(!data.length){
      wrap.innerHTML='<div class="ph-notice">'+t('noPaymentMethods','No payment methods yet.')+'</div>';
      return;
    }
    wrap.innerHTML=`<table class="fin-tbl"><thead><tr>
      <th>${t('pmTitle','Method')}</th>
      <th>${t('pmStatus','Status')}</th>
    </tr></thead><tbody>${data.map(p=>`
      <tr>
        <td>${esc(p.title||p.name)}</td>
        <td>${esc(p.enabled?t('enabled','Enabled'):t('disabled','Disabled'))}</td>
      </tr>`).join('')}</tbody></table>`;
  }catch(e){
    const message=e&&e.message&&e.message!=="invalid_contract"?e.message:t('networkError','Network error');
    wrap.innerHTML='<div class="ph-notice" style="color:var(--danger)">'+
      esc(message)+
      ' <button onclick="loadPaymentMethods()" class="btn btn-sm" style="margin-top:8px">'+
      t('retry','Retry')+'</button></div>';
  }
}

async function loadSavedTokens(){
  const wrap=document.getElementById("finance-saved-cards-wrap");
  if(!wrap||!window.hossamAjax) return;
  wrap.innerHTML=skeletonHTML(3);
  try{
    const data=await hossamFinanceRequest("hossam_get_saved_tokens");
    if(!Array.isArray(data)) throw new Error("invalid_contract");
    if(!data.length){
      wrap.innerHTML='<div class="ph-notice">'+t('noSavedTokens','No saved cards yet.')+'</div>';
      return;
    }
    wrap.innerHTML=`<table class="fin-tbl"><thead><tr>
      <th>${t('tokenLast4','Ending in')}</th>
    </tr></thead><tbody>${data.map(tk=>`
      <tr>
        <td>${esc(tk.last4)}</td>
      </tr>`).join('')}</tbody></table>`;
  }catch(e){
    const message=e&&e.message&&e.message!=="invalid_contract"?e.message:t('networkError','Network error');
    wrap.innerHTML='<div class="ph-notice" style="color:var(--danger)">'+
      esc(message)+
      ' <button onclick="loadSavedTokens()" class="btn btn-sm" style="margin-top:8px">'+
      t('retry','Retry')+'</button></div>';
  }
}
