/**
 * assets/js/modules/uploads.js
 * ══════════════════════════════════════════════════════════════
 * المهام المرتبطة: T-22
 * الدوال المنقولة حرفيًا: loadMyFiles(view), restoreFile(id, el),
 * deleteFilePermanently(id, el)
 *
 * TODO:
 * [x] نقل الثلاثة — تستدعي: hossam_get_my_files،
 *     hossam_restore_file، hossam_delete_file (ajax/uploads.php)
 * [x] مسجَّلة فى TAB_LOADERS (dashboard.js) تحت مفاتيح 'my-files' و'trash'
 *
 * الدفعة الثانية (2026-08-24، تدقيق batch-execution-checklist-v7 بند 4):
 * 1) pagination حقيقية مطابقة للشكل الجديد من ajax/uploads.php
 *    {files,page,has_more}: زر Load more يجلب الصفحة التالية ويُلحقها،
 *    وتغيير view يُعيد التهيئة إلى صفحة 1.
 * 2) «تأكيد صريح» قبل الحذف النهائي: confirm قبل إرسال hossam_delete_file
 *    — الحماية الخادمية (ملكية post_author + wp_delete_attachment force)
 *    باقية كما هي فى الخادم، وهذا هو طبقة الواجهة المطلوبة نصًا.
 * 3) Core-first للرفع: hossamUploadFile() ترسل إلى wp_ajax_hossam_upload_file
 *    (حقل 'file' مطابق لتوقيع المحوِّل). [shared_files] fallback للرفع فقط
 *    داخل #hossam-shared-fallback ولا يُفتَح إلا عند فشل تقني محدد
 *    (شبكة/5xx/رد غير قابل للتحليل) — أبدًا لا عند رفض أمني (403) أو نوع
 *    ملف غير مسموح (400) وفق مرجع §Files وبوابة 8.2.
 * ══════════════════════════════════════════════════════════════
 */

/* ════════════════════════════════════════════════════════════════
   SHARED FILES
╚════════════════════════════════════════════════════════════════ */
/* [FD-JS] */
function hossamFilesState(){ return window.__hossamFiles || (window.__hossamFiles = {active:{page:0,hasMore:false,loading:false},trash:{page:0,hasMore:false,loading:false}}); }

function hossamUploadsIsTechnicalFailure(netError,resp,parsed){
  if(netError||!resp) return true;
  if(resp.status>=400&&resp.status<500) return false;
  return resp.status>=500||!parsed;
}

function hossamUploadsRevealFallback(){
  const box=document.getElementById('hossam-shared-fallback');
  if(!box||box.style.display!=='none') return false;
  box.style.display='block';
  showToast(t('fallbackNotice','Opening the legacy form due to a technical issue.'),'error');
  return true;
}

function hossamFileRowHTML(f, view){
  return `<div class="activity-item" data-file-id="${parseInt(f.id,10)||0}">
        <div class="act-txt">${esc(f.title||f.name)}</div>
        <div class="act-time">${esc(f.date||f.created_at)}</div>
        ${view==='trash'?`<button onclick="restoreFile(${parseInt(f.id,10)||0},this)" class="btn btn-sm btn-ghost">${t('restore','Restore')}</button>
        <button onclick="deleteFilePermanently(${parseInt(f.id,10)||0},this)" class="btn btn-sm btn-ghost">${t('deletePermanently','Delete permanently')}</button>`:''}
      </div>`;
}

function hossamLoadMoreBtnHTML(view){
  return `<div class="text-center mt-8"><button onclick="loadMyFiles('${view}',true)" class="btn btn-sm btn-outline">${t('loadMore','Load more')}</button></div>`;
}

async function loadMyFiles(view, append){
  view = view === 'trash' ? 'trash' : 'active';
  const wrap=document.getElementById(view==='trash'?'files-trash-wrap':'files-my-files-wrap');
  if(!wrap||!window.hossamAjax) return;
  const state=hossamFilesState()[view];
  if(state.loading||append&&state.page>0&&!state.hasMore) return;
  const requestedPage=append?state.page+1:1;
  state.loading=true;
  if(!append){ state.page=0; state.hasMore=false; wrap.innerHTML=skeletonHTML(3); }
  try{
    const fd=new FormData();
    fd.append("action","hossam_get_my_files");
    fd.append("nonce",hossamAjax.nonce);
    fd.append("view",view);
    fd.append("page",requestedPage);
    const r=await fetch(hossamAjax.ajaxurl,{method:"POST",body:fd});
    const raw=await r.text();
    let d;
    try{d=JSON.parse(raw);}catch(error){throw new Error('invalid_contract');}
    if(!r.ok||!d||d.success!==true){
      const failure=new Error((d&&d.data&&d.data.message)||t('loadFailed','Could not load files.'));
      failure.status=r.status;
      throw failure;
    }
    if(!d.data||!Array.isArray(d.data.files)||typeof d.data.has_more!=='boolean'||Number(d.data.page)!==requestedPage){
      throw new Error('invalid_contract');
    }
    const files=d.data.files;
    state.hasMore=d.data.has_more;
    state.page=requestedPage;
    if(!append&&!files.length){
      wrap.innerHTML='<div class="ph-notice">'+t('noFiles','No files yet.')+'</div>';
      return;
    }
    let html=files.map(f=>hossamFileRowHTML(f,view)).join('');
    if(append){
      const oldBtn=wrap.querySelector('.text-center.mt-8');
      if(oldBtn){oldBtn.remove();}
      wrap.insertAdjacentHTML('beforeend',html);
    }else{
      wrap.innerHTML=html;
    }
    if(state.hasMore){wrap.insertAdjacentHTML('beforeend',hossamLoadMoreBtnHTML(view));}
  }catch(e){
    const message=e&&e.message&&e.message!=='invalid_contract'?e.message:t('networkError','Network error');
    const errorHtml='<div class="ph-notice" style="color:var(--danger)">'+
      esc(message)+
      ' <button onclick="loadMyFiles(\''+view+'\')" class="btn btn-sm" style="margin-top:8px">'+
      t('retry','Retry')+'</button></div>';
    if(append){wrap.insertAdjacentHTML('beforeend',errorHtml);}else{wrap.innerHTML=errorHtml;}
  }finally{
    state.loading=false;
  }
}

async function restoreFile(fileId, el){
  if(!window.hossamAjax || !fileId) return;
  try{
    const fd=new FormData();
    fd.append("action","hossam_restore_file");
    fd.append("nonce",hossamAjax.nonce);
    fd.append("file_id",fileId);
    const r=await fetch(hossamAjax.ajaxurl,{method:"POST",body:fd});
    const d=await r.json();
    if(d.success){
      showToast(t('fileRestored','File restored'),'success');
      loadMyFiles('trash');
    }else{
      showToast((d.data&&d.data.message)||t('restoreFailed','Restore failed'),'error');
    }
  }catch(e){showToast(t('networkError','Network error'),'error');}
}

async function deleteFilePermanently(fileId, el){
  if(!window.hossamAjax || !fileId) return;
  /* تأكيد صريح إلزامى قبل الحذف النهائي (بند 4 — الدفعة الثانية) */
  if(!window.confirm(t('confirmDeleteFile','Delete this file permanently?'))){return;}
  try{
    const fd=new FormData();
    fd.append("action","hossam_delete_file");
    fd.append("nonce",hossamAjax.nonce);
    fd.append("file_id",fileId);
    const r=await fetch(hossamAjax.ajaxurl,{method:"POST",body:fd});
    const d=await r.json();
    if(d.success){
      showToast(t('fileDeleted','File deleted'),'success');
      /* [B9-01] بعد الحذف الناجح تُعاد قراءة العرض المتأثر من الصفحة 1 بدل
         إزالة الصف من DOM فقط؛ الإزالة وحدها تُبقي state.page/hasMore على
         إزاحة قديمة فيفوّت Load more ملفًا انتقل إلى إزاحة سابقة
         (سيناريو 101 ملف). نمط restoreFile — loadMyFiles(view) — هو المرجع
         داخل هذا الملف نفسه. */
      let _b901View='trash';
      try{
        const _b901Wrap=el&&el.closest?el.closest('#files-trash-wrap,#files-my-files-wrap'):null;
        if(_b901Wrap&&_b901Wrap.id==='files-my-files-wrap'){_b901View='active';}
      }catch(_b901Err){_b901View='trash';}
      loadMyFiles(_b901View);
    }else{
      showToast((d.data&&d.data.message)||t('deleteFailed','Delete failed'),'error');
    }
  }catch(e){showToast(t('networkError','Network error'),'error');}
}

/* [Core-first — الدفعة الثانية] Upload → wp_ajax_hossam_upload_file.
   قاعدة fallback: فشل تقني فقط (شبكة/5xx/رد غير قابل للتحليل) يفتح
   #hossam-shared-fallback؛ رفض أمني/نوع غير مسموح = toast بلا fallback. */
async function hossamUploadFile(form){
  if(!window.hossamAjax || !form) return false;
  const input=form.querySelector('input[type="file"][name="file"]');
  if(!input || !input.files || !input.files.length){return false;}
  const fd=new FormData();
  fd.append("action","hossam_upload_file");
  fd.append("nonce",hossamAjax.nonce);
  fd.append("file",input.files[0]);
  let netError=false,resp=null,d=null;
  try{
    resp=await fetch(hossamAjax.ajaxurl,{method:"POST",body:fd});
    try{d=await resp.json();}catch(e){d=null;}
  }catch(e){netError=true;}
  if(d&&d.success){
    showToast(t('fileUploaded','File uploaded.'),'success');
    form.reset();
    loadMyFiles('active');
    const tb=document.querySelector('[data-tab-group="files"] .tab-btn[data-tab="my-files"]');
    if(tb){tb.click();}
    return true;
  }
  showToast((d&&d.data&&d.data.message)||t('networkError','Network error'),'error');
  if(hossamUploadsIsTechnicalFailure(netError,resp,d)){
    hossamUploadsRevealFallback();
  }
  return false;
}
