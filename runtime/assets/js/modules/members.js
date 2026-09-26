/**
 * assets/js/modules/members.js
 * ══════════════════════════════════════════════════════════════
 * المهام المرتبطة: T-22
 * الدالة المنقولة حرفيًا: loadMembers()
 *
 * TODO:
 * [x] نقل الدالة حرفيًا — تستدعي hossam_get_members (ajax/members.php)
 *     — مؤكَّد 2026-08-24: loadMembers معرَّفة أدناه (:17) والـendpoint
 *     مُكتمَل بالنقل الحرفى (batch-tracking-log.md صف SECTION 14)
 * [x] مسجَّلة فى TAB_LOADERS تحت 'all' — dashboard.js:134
 * ══════════════════════════════════════════════════════════════
 */

/* ════════════════════════════════════════════════════════════════
   MEMBERS
════════════════════════════════════════════════════════════════ */
/* [MB-JS] */
const hossamMembersState={page:0,items:[],loading:false,hasMore:true};

function hossamRenderMembers(wrap){
  if(!hossamMembersState.items.length){
    wrap.innerHTML='<div class="ph-notice">'+t('noMembers','No members yet.')+'</div>';
    return;
  }
  wrap.innerHTML=`<table class="fin-tbl"><thead><tr>
    <th>${t('memberName','Name')}</th>
    <th>${t('memberEmail','Email')}</th>
    <th>${t('memberRole','Role')}</th>
  </tr></thead><tbody>${hossamMembersState.items.map(m=>`
    <tr>
      <td>${esc(m.name)}</td>
      <td>${esc(m.email)}</td>
      <td>${esc((m.roles||[]).join(', '))}</td>
    </tr>`).join('')}</tbody></table>`+
    (hossamMembersState.hasMore?`<button type="button" class="btn btn-outline mt-12" onclick="loadMembers(true)">${t('loadMore','Load more')}</button>`:'');
}

async function loadMembers(append=false){
  const wrap=document.getElementById("members-table-wrap");
  if(!wrap||!window.hossamAjax||hossamMembersState.loading) return;
  if(!append){hossamMembersState.page=0;hossamMembersState.items=[];hossamMembersState.hasMore=true;wrap.innerHTML=skeletonHTML(4);}
  if(!hossamMembersState.hasMore)return;
  hossamMembersState.loading=true;
  try{
    const fd=new FormData();
    fd.append("action","hossam_get_members");
    fd.append("nonce",hossamAjax.nonce);
    fd.append("page",String(hossamMembersState.page+1));
    const r=await fetch(hossamAjax.ajaxurl,{method:"POST",body:fd});
    const text=await r.text();
    let d;try{d=JSON.parse(text);}catch(error){throw new Error(t('contractError','Invalid server response'));}
    if(!r.ok||!d||d.success!==true)throw new Error(d?.data?.message||t('requestFailed','Request failed'));
    if(!d.data||!Array.isArray(d.data.members)||!Number.isInteger(Number(d.data.page))||typeof d.data.has_more!=="boolean")throw new Error(t('contractError','Invalid server response'));
    hossamMembersState.page=Number(d.data.page);
    hossamMembersState.items.push(...d.data.members);
    hossamMembersState.hasMore=d.data.has_more;
    hossamRenderMembers(wrap);
  }catch(e){
    wrap.innerHTML='<div class="ph-notice" style="color:var(--danger)">'+
      esc(e.message||t('networkError','Network error'))+
      ' <button onclick="loadMembers(false)" class="btn btn-sm" style="margin-top:8px">'+
      t('retry','Retry')+'</button></div>';
  }finally{hossamMembersState.loading=false;}
}
