/**
 * assets/js/modules/posts.js
 * ══════════════════════════════════════════════════════════════
 * المهام المرتبطة: T-22
 * الدالتان المنقولتان حرفيًا من dashboard.js الأصلي:
 *   - restoreArticle(postId) + مستمع الحدث .js-restore-article
 *   - translateArticle(postId, lang) + مستمع الحدث .js-translate-article
 * (liveSearch() قد تبقى فى الـshell — راجع TODO فى dashboard.js)
 *
 * TODO:
 * [x] نقل الدالتين + مستمعَي الحدث حرفيًا
 * [x] تستدعيان actions: hossam_restore_article، hossam_translate_article
 *     (موجودتان بالفعل فى ajax/posts.php بعد T-06)
 *
 * الدفعة الثانية (2026-08-24) — Core-first لبند 3 حرفيًا «adapters/
 * wordpress-posts.php مالك المنطق؛ ajax/posts.php يترجم فقط»:
 * hossamCreateArticle/hossamUpdateArticle/hossamTrashArticle ترسل إلى
 * endpoints CRUD الأصلية. قاعدة الـfallback الموثقة (بوابة 8.2 نصًا):
 * [frontend_admin] لا يُفتَح أبدًا عند رفض أمني أو مدخل غير صالح
 * (401/403/404/400 برسالة خادم مفهومة = toast فقط)، ولا يُفتَح إلا عند
 * «فشل تقني محدد»: استثناء شبكة / HTTP>=500 / رد غير قابل للتحليل.
 * ══════════════════════════════════════════════════════════════
 */

/* [Core-first — الدفعة الثانية] تصنيف الفشل: تقني فقط يستحق fallback */
function hossamIsTechnicalFailure(netError, resp, parsed){
  const errorCode=parsed&&parsed.data&&parsed.data.code;
  if(errorCode==='hossam_partial_failure'||errorCode==='partial_failure') return false;
  if(netError) return true;
  if(!resp) return true;
  if(resp.status >= 400 && resp.status < 500) return false;
  if(resp.status >= 500) return true;
  if(!parsed) return true;
  return false;
}

function hossamRevealFallback(containerId){
  const box=document.getElementById(containerId);
  if(!box||box.style.display!=='none') return false;
  box.style.display='block';
  showToast(t('fallbackNotice','Opening the legacy form due to a technical issue.'),'error');
  return true;
}

function hossamPostToastLabel(status){
  if(status==='pending') return t('submittedReview','Submitted for review');
  if(status==='publish') return t('published','Published');
  return t('draftSaved','Draft saved');
}

/* [Core-first] Create → hossam_create_article */
async function hossamCreateArticle(form){
  if(!window.hossamAjax || !form) return false;
  const fd=new FormData();
  fd.append("action","hossam_create_article");
  fd.append("nonce",hossamAjax.nonce);
  const title=form.querySelector('[name="title"]'),
        content=form.querySelector('[name="content"]'),
        status=form.querySelector('[name="status"]'),
        cats=form.querySelector('[name="categories"]');
  fd.append("title",title?title.value:"");
  fd.append("content",content?content.value:"");
  if(status&&status.value){fd.append("status",status.value);}
  if(cats&&cats.value.trim()){
    cats.value.split(",").map(s=>parseInt(s.trim(),10)).filter(n=>n>0)
      .forEach(n=>fd.append("categories[]",n));
  }
  let netError=false,resp=null,d=null;
  try{
    resp=await fetch(hossamAjax.ajaxurl,{method:"POST",body:fd});
    try{d=await resp.json();}catch(e){d=null;}
  }catch(e){netError=true;}
  if(d&&d.success){
    showToast(hossamPostToastLabel(status?status.value:'draft'),'success');
    if(d.data&&d.data.edit_url){window.location.href=d.data.edit_url;}
    else{location.reload();}
    return true;
  }
  showToast((d&&d.data&&d.data.message)||t('networkError','Network error'),'error');
  if(hossamIsTechnicalFailure(netError,resp,d)){hossamRevealFallback('fa-fallback-write');}
  return false;
}

/* [Core-first] Update → hossam_update_article */
async function hossamUpdateArticle(form){
  if(!window.hossamAjax || !form) return false;
  const fd=new FormData();
  fd.append("action","hossam_update_article");
  fd.append("nonce",hossamAjax.nonce);
  const pid=form.querySelector('[name="post_id"]'),
        title=form.querySelector('[name="title"]'),
        content=form.querySelector('[name="content"]'),
        status=form.querySelector('[name="status"]'),
        cats=form.querySelector('[name="categories"]');
  fd.append("post_id",pid?pid.value:"0");
  fd.append("title",title?title.value:"");
  fd.append("content",content?content.value:"");
  if(status&&status.value){fd.append("status",status.value);}
  if(cats&&cats.value.trim()){
    cats.value.split(",").map(s=>parseInt(s.trim(),10)).filter(n=>n>0)
      .forEach(n=>fd.append("categories[]",n));
  }
  let netError=false,resp=null,d=null;
  try{
    resp=await fetch(hossamAjax.ajaxurl,{method:"POST",body:fd});
    try{d=await resp.json();}catch(e){d=null;}
  }catch(e){netError=true;}
  if(d&&d.success){
    showToast(t('updated','Updated'),'success');
    location.reload();
    return true;
  }
  showToast((d&&d.data&&d.data.message)||t('networkError','Network error'),'error');
  if(hossamIsTechnicalFailure(netError,resp,d)){hossamRevealFallback('fa-fallback-edit');}
  return false;
}

/* [Core-first] Trash → hossam_trash_article (تأكيد صريح قبل الإرسال) */
async function hossamTrashArticle(postId, el){
  if(!window.hossamAjax || !postId) return false;
  if(!window.confirm(t('confirmTrashArticle','Move this article to trash?'))){return false;}
  const fd=new FormData();
  fd.append("action","hossam_trash_article");
  fd.append("nonce",hossamAjax.nonce);
  fd.append("post_id",postId);
  let netError=false,resp=null,d=null;
  try{
    resp=await fetch(hossamAjax.ajaxurl,{method:"POST",body:fd});
    try{d=await resp.json();}catch(e){d=null;}
  }catch(e){netError=true;}
  if(d&&d.success){
    showToast(t('movedToTrash','Moved to trash.'),'success');
    location.reload();
    return true;
  }
  showToast((d&&d.data&&d.data.message)||t('networkError','Network error'),'error');
  if(hossamIsTechnicalFailure(netError,resp,d)){hossamRevealFallback('fa-fallback-delete');}
  return false;
}

/* [AR-02-JS] Restore article from Trash */
async function restoreArticle(postId){
  if(!window.hossamAjax || !postId) return;
  try{
    const fd=new FormData();
    fd.append("action","hossam_restore_article");
    fd.append("nonce",hossamAjax.nonce);
    fd.append("post_id",postId);
    const r=await fetch(hossamAjax.ajaxurl,{method:"POST",body:fd});
    const d=await r.json();
    if(d.success){
      showToast(t('articleRestored','Article restored'),'success');
      location.reload();
    }else{
      showToast((d.data&&d.data.message)||t('restoreFailed','Restore failed'),'error');
    }
  }catch(e){showToast(t('networkError','Network error'),'error');}
}

document.addEventListener('click',e=>{const b=e.target.closest('.js-restore-article');if(b){e.preventDefault();restoreArticle(parseInt(b.dataset.id,10));}});

/* [Patch 4] Create WPML translation for an article */
async function translateArticle(postId, lang){
  if(!window.hossamAjax || !postId || !lang) return;
  try{
    const fd=new FormData();
    fd.append("action","hossam_translate_article");
    fd.append("nonce",hossamAjax.nonce);
    fd.append("post_id",postId);
    fd.append("lang",lang);
    const r=await fetch(hossamAjax.ajaxurl,{method:"POST",body:fd});
    const d=await r.json();
    if(d.success){
      showToast(t('translationCreated','Translation created'),'success');
      const newId=d.data&&(d.data.translated_id||d.data.post_id);
      if(d.data&&d.data.edit_url){
        window.location.href=d.data.edit_url;
      }else if(newId){
        const u=new URL(location.href);
        u.searchParams.set('panel','edit-article');
        u.searchParams.set('post_id',newId);
        window.location.href=u.toString();
      }else{
        location.reload();
      }
    }else{
      showToast((d.data&&d.data.message)||t('translationFailed','Translation failed'),'error');
    }
  }catch(e){showToast(t('networkError','Network error'),'error');}
}

document.addEventListener('click',e=>{const b=e.target.closest('.js-translate-article');if(b){e.preventDefault();translateArticle(parseInt(b.dataset.post,10),b.dataset.lang);}});
