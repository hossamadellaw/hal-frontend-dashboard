/**
 * assets/js/modules/store.js
 * ══════════════════════════════════════════════════════════════
 * المهام المرتبطة: T-22
 * الدالة المنقولة حرفيًا: loadProducts()
 * (تبويب Orders فى نفس البانل يُعاد استخدام loadFinance() من
 * modules/finance.js — لا دالة منفصلة له هنا، راجع ملاحظة
 * template-parts/dashboard/store.php)
 *
 * TODO:
 * [x] نقل loadProducts() حرفيًا — تستدعي hossam_get_products (ajax/store.php)
 *     — مؤكَّد 2026-08-24: الدالة معرَّفة أدناه (:20)
 * [x] مسجَّلة فى TAB_LOADERS تحت 'products' — dashboard.js:133
 * ══════════════════════════════════════════════════════════════
 */

/* ════════════════════════════════════════════════════════════════
   STORE
════════════════════════════════════════════════════════════════ */
/* [WC-JS] Orders tab reuses existing loadFinance() — no duplication */
let currentProductPage=1;
let productsLoading=false;
async function loadProducts(page=1){
  const wrap=document.getElementById("store-products-wrap");
  if(!wrap||!window.hossamAjax||productsLoading) return;
  productsLoading=true;
  currentProductPage=Math.max(1,Number(page)||1);
  wrap.innerHTML=skeletonHTML(4);
  try{
    const fd=new FormData();
    fd.append("action","hossam_get_products");
    fd.append("nonce",hossamAjax.nonce);
    fd.append("page",String(currentProductPage));
    const r=await fetch(hossamAjax.ajaxurl,{method:"POST",body:fd});
    const raw=await r.text();
    let d;
    try{d=JSON.parse(raw);}catch(error){throw new Error("invalid_contract");}
    if(!r.ok||!d||d.success!==true){
      const message=d&&d.data&&d.data.message?d.data.message:t('loadFailed','Could not load products.');
      wrap.innerHTML='<div class="ph-notice" style="color:var(--danger)">'+esc(message)+'</div>';
      return;
    }
    if(!d.data||!Array.isArray(d.data.products)||typeof d.data.has_more!=="boolean"||Number(d.data.page)!==currentProductPage){
      throw new Error("invalid_contract");
    }
    if(!d.data.products.length){
      wrap.innerHTML='<div class="ph-notice">'+t('noProducts','No products yet.')+'</div>';
      return;
    }
    wrap.innerHTML=`<table class="fin-tbl"><thead><tr>
      <th>${t('productName','Product')}</th>
      <th>${t('productPrice','Price')}</th>
      <th>${t('productStock','Stock')}</th>
    </tr></thead><tbody>${d.data.products.map(p=>`
      <tr>
        <td>${esc(p.name)}</td>
        <td>${esc(p.price)}</td>
        <td>${esc(p.stock)}</td>
      </tr>`).join('')}</tbody></table>`;
    wrap.innerHTML+=`<div class="fin-pagination">
      ${currentProductPage>1?`<button type="button" onclick="loadProducts(${currentProductPage-1})" class="btn btn-sm">${t('prevPage','Previous')}</button>`:''}
      ${d.data.has_more?`<button type="button" onclick="loadProducts(${currentProductPage+1})" class="btn btn-sm">${t('nextPage','Next')}</button>`:''}
    </div>`;
  }catch(e){
    wrap.innerHTML='<div class="ph-notice" style="color:var(--danger)">'+
      (e&&e.message==="invalid_contract"?t('contractError','Invalid server response.'):t('networkError','Network error'))+
      ' <button onclick="loadProducts('+currentProductPage+')" class="btn btn-sm" style="margin-top:8px">'+
      t('retry','Retry')+'</button></div>';
  }finally{
    productsLoading=false;
  }
}
