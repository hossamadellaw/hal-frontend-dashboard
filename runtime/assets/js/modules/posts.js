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
        excerpt=form.querySelector('[name="excerpt"]'),
        thumb=form.querySelector('[name="thumbnail_id"]');
  /* [B9-08] v1.0.6 §9: سلْسِل كتل المحرر إلى الـtextarea قبل القراءة —
     عقد الخادم نصًا بلا تغيير (wp_kses_post باقٍ مسؤول التنقية). */
  if(typeof hossamSyncRichEditorToTextarea==='function'){try{hossamSyncRichEditorToTextarea(form);}catch(e){}}
  fd.append("title",title?title.value:"");
  fd.append("content",content?content.value:"");
  if(status&&status.value){fd.append("status",status.value);}
  /* [B9-05] v1.0.5 A2: categories from checked boxes (no text parsing, no
     silent drops). Empty create sends no key — WordPress assigns its default. */
  var catBoxes = (typeof form.querySelectorAll === 'function')
    ? form.querySelectorAll('input[name="categories[]"]:checked') : [];
  catBoxes.forEach(box=>fd.append("categories[]",box.value));
  if(excerpt){fd.append("excerpt",excerpt.value);}
  /* [B9-05] + [B9-06] P1: أرسل القيمة عندما لا تكون فارغة — الصفر '0'
     يُرسل صراحة للإزالة (لا استبعاد '0'، ولا -1 إطلاقًا). */
  if(thumb&&thumb.value!==''){fd.append("thumbnail_id",thumb.value);}
  let netError=false,resp=null,d=null;
  try{
    resp=await fetch(hossamAjax.ajaxurl,{method:"POST",body:fd});
    try{d=await resp.json();}catch(e){d=null;}
  }catch(e){netError=true;}
  if(d&&d.success){
    showToast(hossamPostToastLabel(status?status.value:'draft'),'success');
    /* [B9-06] P1: بطاقة نجاح بروابط قابلة للنقر تُعرض قبل أي انتقال
       تلقائي؛ انتقال الإنشاء (edit_url) يتأخر حتى تفاعل المستخدم
       (رابط قابل للنقر) أو مهلة قصيرة. */
    hossamShowPostSuccessCard(form,d,hossamPostToastLabel(status?status.value:'draft'));
    if(d.data&&d.data.edit_url){
      const editUrl=d.data.edit_url;
      setTimeout(()=>{window.location.href=editUrl;},6000);
    }
    else if(!hossamPostSuccessLinks(d).length){location.reload();}
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
        excerpt=form.querySelector('[name="excerpt"]'),
        thumb=form.querySelector('[name="thumbnail_id"]');
  /* [B9-08] v1.0.6 §9: سلْسِل كتل المحرر إلى الـtextarea قبل القراءة —
     عقد الخادم نصًا بلا تغيير (wp_kses_post باقٍ مسؤول التنقية). */
  if(typeof hossamSyncRichEditorToTextarea==='function'){try{hossamSyncRichEditorToTextarea(form);}catch(e){}}
  fd.append("post_id",pid?pid.value:"0");
  fd.append("title",title?title.value:"");
  fd.append("content",content?content.value:"");
  if(status&&status.value){fd.append("status",status.value);}
  /* [B9-05] v1.0.5 A2: empty update sends no categories key (keep-contract);
     the server guard keeps the current categories. */
  var catBoxesU = (typeof form.querySelectorAll === 'function')
    ? form.querySelectorAll('input[name="categories[]"]:checked') : [];
  catBoxesU.forEach(box=>fd.append("categories[]",box.value));
  if(excerpt){fd.append("excerpt",excerpt.value);}
  /* [B9-05] + [B9-06] P1: الصفر '0' يُرسل صراحة للإزالة (لا استبعاد
     '0'، ولا -1 إطلاقًا) — التحديث الفارغ تمامًا يبقي العقد (بلا مفتاح). */
  if(thumb&&thumb.value!==''){fd.append("thumbnail_id",thumb.value);}
  let netError=false,resp=null,d=null;
  try{
    resp=await fetch(hossamAjax.ajaxurl,{method:"POST",body:fd});
    try{d=await resp.json();}catch(e){d=null;}
  }catch(e){netError=true;}
  if(d&&d.success){
    showToast(t('updated','Updated'),'success');
    /* [B9-06] P1: التحديث يعرض بطاقة النجاح بروابطها بدل الإعادة
       الفورية — تبقى الروابط قابلة للاستخدام بلا انتقال تلقائي. */
    hossamShowPostSuccessCard(form,d,t('updated','Updated'));
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

/* [B9-05] v1.0.5 B: featured-image picker via the standard wp.media frame.
   [B9-06] v1.0.6 P1: ربط كسول — مستمع دائم على الزر يفحص wp.media لحظة
   النقر (لا لحظة التحميل)؛ عند غيابها رسالة منقحة ظاهرة للمستخدم على
   نمط admin-settings.js بلا تفاصيل — لا خروج صامت. + زر إزالة يضبط
   الحقل على '0' ويخفي المعاينة. + بطاقة نجاح بروابط قابلة للنقر. */
function hossamMediaUnavailable(){
  showToast(t('loadFailed','Media library unavailable.'),'error');
}
function hossamEnsureThumbnailRemoveBtn(removeId, btnId, fieldId, previewId){
  let removeBtn=document.getElementById(removeId);
  if(removeBtn) return removeBtn;
  const picker=document.getElementById(btnId);
  if(!picker||!picker.parentNode) return null;
  removeBtn=document.createElement('button');
  removeBtn.type='button';
  removeBtn.className='btn btn-sm btn-ghost';
  removeBtn.id=removeId;
  removeBtn.style.display='none';
  removeBtn.textContent=t('removeFeaturedImage','Remove featured image');
  picker.parentNode.insertBefore(removeBtn,picker.nextSibling);
  hossamBindThumbnailRemove(removeId,fieldId,previewId);
  return removeBtn;
}
function hossamBindThumbnailPicker(btnId, fieldId, previewId, removeId){
  const btn=document.getElementById(btnId);
  if(!btn) return;
  btn.addEventListener('click',function(){
    if(typeof wp==='undefined'||!wp||!wp.media){hossamMediaUnavailable();return;}
    const frame=wp.media({title:t('featuredImage','Featured Image'),button:{text:t('setFeaturedImage','Set featured image')},multiple:false});
    frame.on('select',function(){
      const att=frame.state().get('selection').first().toJSON();
      const field=document.getElementById(fieldId);
      const preview=document.getElementById(previewId);
      if(field) field.value=att.id;
      if(preview){
        const url=att.sizes&&att.sizes.thumbnail?att.sizes.thumbnail.url:att.url;
        if(url){preview.src=url;preview.style.display='';}
      }
      const removeBtn=hossamEnsureThumbnailRemoveBtn(removeId,btnId,fieldId,previewId);
      if(removeBtn) removeBtn.style.display='';
    });
    frame.open();
  });
}
function hossamBindThumbnailRemove(btnId, fieldId, previewId){
  const btn=document.getElementById(btnId);
  if(!btn) return;
  btn.addEventListener('click',function(){
    const field=document.getElementById(fieldId);
    const preview=document.getElementById(previewId);
    if(field) field.value='0';
    if(preview){preview.removeAttribute('src');preview.style.display='none';}
    btn.style.display='none';
  });
}
/* [B9-06] P1: روابط النجاح — اعرض ما يصل في d.data إن وجد
   (view_url للمنشور، preview_url للمسودة/المعلق/المجدول، edit_url).
   v1.0.6 §8.6: + رابط عميق إلى لوحة SEO مشتق من edit_url (panel=seo —
   بلا endpoint جديد)؛ لا يُشتق من غير edit_url للوحة التحرير. */
function hossamPostSuccessLinks(d){
  const links=[];
  if(d&&d.data){
    if(d.data.view_url) links.push({url:d.data.view_url,label:t('viewLink','View')});
    if(d.data.preview_url&&d.data.preview_url!==d.data.view_url) links.push({url:d.data.preview_url,label:t('previewLink','Preview')});
    if(d.data.edit_url) links.push({url:d.data.edit_url,label:t('editLink','Edit')});
  }
  return links;
}
function hossamSeoPanelUrl(d){
  const editUrl=d&&d.data&&d.data.edit_url;
  if(!editUrl||typeof editUrl!=='string'||editUrl.indexOf('panel=edit-article')===-1) return '';
  try{
    const u=new URL(editUrl,'https://example.test/');
    u.searchParams.set('panel','seo');
    u.searchParams.delete('post_id');
    return u.toString();
  }catch(e){return '';}
}
function hossamShowPostSuccessCard(form,d,message){
  if(!form||!form.querySelector) return;
  const old=form.querySelector('.hossam-post-success-card');
  if(old) old.remove();
  const card=document.createElement('div');
  card.className='hossam-post-success-card info-card';
  const msg=document.createElement('p');
  msg.textContent=message||t('draftSaved','Draft saved');
  card.appendChild(msg);
  hossamPostSuccessLinks(d).forEach(function(link){
    const a=document.createElement('a');
    a.href=link.url;
    a.target='_blank';
    a.rel='noopener';
    a.className='btn btn-sm btn-ghost';
    a.style.marginInlineEnd='6px';
    a.textContent=link.label;
    card.appendChild(a);
  });
  /* v1.0.6 §8.6: رابط عميق من بطاقة النجاح إلى لوحة SEO. */
  const seoUrl=hossamSeoPanelUrl(d);
  if(seoUrl){
    const s=document.createElement('a');
    s.href=seoUrl;
    s.target='_blank';
    s.rel='noopener';
    s.className='btn btn-sm btn-ghost';
    s.style.marginInlineEnd='6px';
    s.textContent=t('seoLink','SEO');
    card.appendChild(s);
  }
  form.insertBefore(card,form.firstChild);
}
hossamBindThumbnailPicker('hossam-new-thumbnail-btn','hossam-new-thumbnail','hossam-new-thumbnail-preview','hossam-new-thumbnail-remove');
hossamBindThumbnailRemove('hossam-new-thumbnail-remove','hossam-new-thumbnail','hossam-new-thumbnail-preview');
hossamBindThumbnailPicker('hossam-edit-thumbnail-btn','hossam-edit-thumbnail','hossam-edit-thumbnail-preview','hossam-edit-thumbnail-remove');
hossamBindThumbnailRemove('hossam-edit-thumbnail-remove','hossam-edit-thumbnail','hossam-edit-thumbnail-preview');

/* v1.0.6 §8.6: حفظ SEO من مجموعة لوحة التحرير عبر wp_ajax_hossam_save_seo.
   بلا fallback قديم (لا نموذج بديل لمجموعة SEO) — رفض أمني/مدخل غير
   صالح = toast فقط. */
async function hossamSaveSeo(form){
  if(!window.hossamAjax || !form) return false;
  const fd=new FormData();
  fd.append("action","hossam_save_seo");
  fd.append("nonce",hossamAjax.nonce);
  const pid=form.querySelector('[name="post_id"]'),
        title=form.querySelector('[name="title"]'),
        kw=form.querySelector('[name="focus_keyword"]'),
        desc=form.querySelector('[name="description"]');
  fd.append("post_id",pid?pid.value:"0");
  if(title) fd.append("title",title.value);
  if(kw) fd.append("focus_keyword",kw.value);
  if(desc) fd.append("description",desc.value);
  var seoRobots=(typeof form.querySelectorAll==='function')
    ? form.querySelectorAll('input[name="robots[]"]:checked') : [];
  seoRobots.forEach(box=>fd.append("robots[]",box.value));
  let netError=false,resp=null,d=null;
  try{
    resp=await fetch(hossamAjax.ajaxurl,{method:"POST",body:fd});
    try{d=await resp.json();}catch(e){d=null;}
  }catch(e){netError=true;}
  if(d&&d.success){
    showToast(t('seoSaved','SEO settings saved.'),'success');
    if(d.data&&d.data.fields){
      const scoreEl=document.getElementById('hossam-edit-seo-score');
      if(scoreEl){
        const score=parseInt(d.data.fields.seo_score||"0",10)||0;
        scoreEl.textContent=score>0?score+"%":"—";
      }
    }
    return true;
  }
  showToast((d&&d.data&&d.data.message)||t('networkError','Network error'),'error');
  return false;
}

/* [Patch 4] Create WPML translation for an article.
   v1.0.6 §14: the translations panel reuses this same endpoint (no new
   path — the panel buttons carry .js-translate-article with
   data-post/data-lang); success AND failure both surface a visible
   result, and the edit_url hop waits so the result stays usable. */
function hossamShowTranslationResult(btn, d){
  try{
    if(!btn||!btn.closest||!d||!d.data) return;
    const host=btn.closest('tr, .card, .panel')||btn.parentNode;
    if(!host||!host.querySelector) return;
    const old=host.querySelector('.hossam-translation-result');
    if(old) old.remove();
    const box=document.createElement('div');
    box.className='hossam-translation-result info-card';
    const msg=document.createElement('p');
    msg.textContent=d.success?t('translationCreated','Translation created'):((d.data&&d.data.message)||t('translationFailed','Translation failed'));
    box.appendChild(msg);
    if(d.success&&d.data&&d.data.edit_url){
      const a=document.createElement('a');
      a.href=d.data.edit_url;
      a.className='btn btn-sm btn-ghost';
      a.textContent=t('editLink','Edit');
      box.appendChild(a);
    }
    if(host.firstChild) host.insertBefore(box,host.firstChild);
    else host.appendChild(box);
  }catch(e){}
}
async function translateArticle(postId, lang, btn){
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
      /* v1.0.6 §14: show the create/save result by the button first —
         the edit_url hop is delayed so the result stays usable. */
      hossamShowTranslationResult(btn||null,d);
      const newId=d.data&&(d.data.translated_id||d.data.post_id);
      if(d.data&&d.data.edit_url){
        const editUrl=d.data.edit_url;
        setTimeout(()=>{window.location.href=editUrl;},4000);
      }else if(newId){
        const u=new URL(location.href);
        u.searchParams.set('panel','edit-article');
        u.searchParams.set('post_id',newId);
        setTimeout(()=>{window.location.href=u.toString();},4000);
      }else{
        location.reload();
      }
    }else{
      showToast((d.data&&d.data.message)||t('translationFailed','Translation failed'),'error');
      hossamShowTranslationResult(btn||null,d);
    }
  }catch(e){showToast(t('networkError','Network error'),'error');}
}

document.addEventListener('click',e=>{const b=e.target.closest('.js-translate-article');if(b){e.preventDefault();translateArticle(parseInt(b.dataset.post,10),b.dataset.lang,b);}});

/* ══════════════════════════════════════════════════════════════
   [B9-08] v1.0.6 §9 — المحرر الغني: إظهار مثيل محرر الكتل الرسمي
   داخل غلاف HAL (قرار المالك: إظهار وتغليف لا بناء).
   - المكونات العلنية حصرًا: BlockEditorProvider + BlockCanvas (iframe
     وBlockList بداخله) + Inserter + BlockTools/BlockToolbar — لا
     Experimental* ولا واجهات خاصة ولا كتل/أدوات بديلة.
   - الكتل من التسجيل الرسمي حصرًا عبر registerCoreBlocks (Table
     وDetails والوسائط والقوائم والروابط تنسيق نص + Playlist وTabs
     في 7.1.3)؛ كتل الإضافات الأخرى لا تُضمَّن تلقائيًا لأن أصولها
     لا تُحمَّل في HAL. غياب core/playlist أو core/tabs خارج 7.1.3
     يُتجاوز ببقية الكتل — UNVERIFIED محليًا (النواة 6.7.1).
   - parse عند التحرير وserialize عند الحفظ إلى الـtextarea ثم
     hossamCreate/UpdateArticle كما هما — عقد الخادم نصًا بلا تغيير،
     وwp_kses_post يبقى مسؤول التنقية (لا تجاوز).
   - الوسائط: MediaUpload/MediaUploadCheck عبر العارض الرسمي
     (wp.media) ومسار الرفع الرسمي uploadMedia بإعدادات REST/الـnonce
     من WordPress — لا endpoint جديد؛ IDs المرفقات ضمن الكتل بعد
     النجاح؛ رسالة HAL واضحة عند الفشل. لا قيد ملكية HAL على مرفقات
     المحتوى beyond صلاحيات WordPress (canUpload يعكس upload_files).
   - التراجع: الـtextarea يبقى حقل النموذج ظاهرًا ولا يُخفى إلا بعد
     نجاح التهيئة؛ عند أي فشل (سكربت مفقود/استثناء/تعذر iframe)
     يبقى ظاهرًا مع تنبيه قصير. التهيئة عند أول فتح اللوحة.
     العنوان/التصنيفات/الصورة/SEO/الحالة تبقى حقول HAL.
   ══════════════════════════════════════════════════════════════ */
var hossamRichState={inited:{}};
function hossamRichConfig(){
  try{
    if(typeof window!=='undefined'&&window.hossamRichEditor&&typeof window.hossamRichEditor==='object') return window.hossamRichEditor;
  }catch(e){}
  return {enabled:false,canUpload:false,restRoot:'',restNonce:'',isRtl:true,reason:'missing-config'};
}
function hossamRichWp(){
  try{
    if(typeof window!=='undefined'&&window.wp) return window.wp;
    if(typeof wp!=='undefined') return wp;
  }catch(e){}
  return null;
}
function hossamRichNotice(){
  try{showToast(t('richEditorUnavailable','Rich editor unavailable — using the plain text field.'),'error');}catch(e){}
}
function hossamRichUploadFailed(){
  try{showToast(t('richUploadFailed','Media upload failed.'),'error');}catch(e){}
}
/* مسار الرفع الرسمي: uploadMedia بإعدادات REST/الـnonce من WordPress. */
function hossamRichMediaUpload(wp0,cfg,opts){
  opts=opts||{};
  var onFileChange=opts.onFileChange||opts.onSelect||function(){};
  var onError=opts.onError||function(){};
  function fail(){
    hossamRichUploadFailed();
    try{onError(new Error('hossam-rich-upload-failed'));}catch(e){}
  }
  try{
    var filesList=opts.filesList||[];
    if(!filesList.length) return;
    var uploader=(wp0.mediaUtils&&wp0.mediaUtils.uploadMedia)
      ||(wp0.mediaUtils&&wp0.mediaUtils.utils&&wp0.mediaUtils.utils.uploadMedia)
      ||null;
    /* apiFetch يحمل الـnonce/الجذر الرسميين — لا endpoint جديد. */
    try{
      if(wp0.apiFetch){
        if(cfg.restNonce&&wp0.apiFetch.createNonceMiddleware) wp0.apiFetch.use(wp0.apiFetch.createNonceMiddleware(cfg.restNonce));
        if(cfg.restRoot&&wp0.apiFetch.createRootURLMiddleware) wp0.apiFetch.use(wp0.apiFetch.createRootURLMiddleware(cfg.restRoot));
      }
    }catch(mwErr){}
    if(typeof uploader!=='function') throw new Error('missing-uploadMedia');
    uploader({
      filesList:filesList,
      onFileChange:function(files){
        try{
          var done=(files||[]).filter(function(f){return f&&f.id&&f.url;});
          if(done.length){onFileChange(done);return;}
          /* الملفات قيد الرفع (progress) تُمرَّر كما هي دون إدراج. */
          if(files&&files.length) onFileChange(files);
        }catch(e){}
      },
      onError:fail,
      additionalData:{},
    });
  }catch(e){fail();}
}
/* فتح مكتبة الوسائط الرسمية (wp.media) لاختيار مرفق تسمح به صلاحيات WP. */
function hossamRichOpenLibrary(onSelect){
  try{
    if(typeof wp==='undefined'&&!(window&&window.wp)) throw new Error('missing-wp');
    var wph=hossamRichWp();
    if(!wph||!wph.media) throw new Error('missing-wp-media');
    var frame=wph.media({title:'Media',button:{text:'Select'},multiple:false});
    frame.on('select',function(){
      try{
        var att=frame.state().get('selection').first().toJSON();
        if(att&&att.id&&onSelect) onSelect({id:att.id,url:att.url,caption:att.caption&&att.caption.rendered?att.caption.rendered:''});
      }catch(e){}
    });
    frame.open();
  }catch(e){hossamRichUploadFailed();}
}
function hossamRichInitEditor(containerId){
  if(hossamRichState.inited[containerId]&&hossamRichState.inited[containerId].ready) return true;
  var cfg=hossamRichConfig();
  if(!cfg||!cfg.enabled) return false;
  var docRef=(typeof document!=='undefined')?document:null;
  if(!docRef||!docRef.getElementById) return false;
  var container=docRef.getElementById(containerId);
  if(!container) return false;
  var textareaId=container.getAttribute?container.getAttribute('data-textarea'):'';
  var textarea=textareaId?docRef.getElementById(textareaId):null;
  if(!textarea) return false;
  try{
    var wp0=hossamRichWp();
    if(!wp0||!wp0.element||!wp0.blocks||!wp0.data||!wp0.blockEditor) throw new Error('missing-packages');
    var el=wp0.element;
    if(typeof el.createElement!=='function') throw new Error('missing-element');
    var blocksPkg=wp0.blocks;
    if(typeof blocksPkg.parse!=='function'||typeof blocksPkg.serialize!=='function') throw new Error('missing-parse');
    var be=wp0.blockEditor;
    var BlockEditorProvider=be.BlockEditorProvider;
    var BlockCanvas=be.BlockCanvas;
    var BlockList=be.BlockList;
    var Inserter=be.Inserter||be.BlockInserter||null;
    var BlockToolbar=be.BlockToolbar||be.BlockTools||null;
    var MediaUpload=be.MediaUpload||(wp0.editor&&wp0.editor.MediaUpload)||null;
    var MediaUploadCheck=be.MediaUploadCheck||function(props){return props&&props.children?props.children:null;};
    /* علني حصرًا — ExperimentalBlockCanvas مرفوض هنا. */
    if(typeof BlockEditorProvider==='undefined'||typeof BlockCanvas==='undefined'||typeof BlockList==='undefined') throw new Error('missing-components');
    /* تسجيل الكتل الأساسية الرسمية للنسخة المثبتة — بلا تثبيت حزم. */
    try{
      if(wp0.blockLibrary&&typeof wp0.blockLibrary.registerCoreBlocks==='function') wp0.blockLibrary.registerCoreBlocks();
      else if(typeof blocksPkg.registerCoreBlocks==='function') blocksPkg.registerCoreBlocks();
    }catch(regErr){}
    var initialHtml=textarea.value||'';
    var initialBlocks=[];
    try{initialBlocks=blocksPkg.parse(initialHtml)||[];}catch(parseErr){initialBlocks=[];}
    var canUpload=!!cfg.canUpload;
    var settings={
      canUpload:canUpload,
      mediaUpload:function(o){hossamRichMediaUpload(wp0,cfg,o);},
      isRTL:!!cfg.isRtl,
    };
    /* أنماط المحتوى تُمرَّر إلى iframe عبر واجهة BlockCanvas الرسمية
       (styles) — مع RTL من إعدادات HAL. */
    var contentCss='html{direction:'+(cfg.isRtl?'rtl':'ltr')+';}body{font-family:inherit;line-height:1.7;max-width:100%;}img{max-width:100%;height:auto;}table{border-collapse:collapse;width:100%;}';
    function HossamRichApp(){
      var useState=el.useState;
      var state=useState(initialBlocks);
      var blocks=state[0], setBlocks=state[1];
      hossamRichState.inited[containerId]={ready:true,blocks:blocks,textareaId:textareaId};
      function handleChange(next){hossamRichState.inited[containerId].blocks=next;setBlocks(next);}
      function handleInput(next){hossamRichState.inited[containerId].blocks=next;setBlocks(next);}
      function insertImage(img){
        try{
          var nb=blocksPkg.createBlock?blocksPkg.createBlock('core/image',{id:img.id,url:img.url,caption:img.caption||''}):null;
          if(nb) handleChange(blocks.concat([nb]));
        }catch(e){}
      }
      var barChildren=[];
      if(BlockToolbar) barChildren.push(el.createElement(BlockToolbar,{key:'bar'}));
      if(canUpload&&MediaUpload){
        barChildren.push(el.createElement(MediaUploadCheck,{key:'muc'},
          el.createElement(MediaUpload,{
            onSelect:function(img){insertImage(img);},
            allowedTypes:['image'],
            render:function(renderProps){
              var open=renderProps&&renderProps.open;
              return el.createElement('button',{type:'button',className:'btn btn-sm',onClick:function(){if(typeof open==='function'){open();}else{hossamRichOpenLibrary(insertImage);}}},'Media');
            },
          })
        ));
      }
      var inserterNode=Inserter?el.createElement(Inserter,{key:'ins',position:'bottom right',showInserterHelpPanel:false}):null;
      var canvasNode=el.createElement(BlockCanvas,{key:'canvas',height:'420px',styles:[{css:contentCss}]},
        el.createElement(BlockList,null)
      );
      var toolbarNode=barChildren.length?el.createElement('div',{key:'tools',className:'hossam-rich-tools'},barChildren):null;
      /* مواضع القالب: inserter/toolbar/canvas — portals تحفظ سياق الـprovider.
         بديل بلا portals: شجرة واحدة في حامل canvas. */
      var q=function(sel){try{return container.querySelector?container.querySelector(sel):null;}catch(e){return null;};};
      var inserterMount=q('[data-role="inserter"]'), toolbarMount=q('[data-role="toolbar"]'), canvasMount=q('[data-role="canvas"]');
      var usePortals=(typeof el.createPortal==='function')&&inserterMount&&toolbarMount&&canvasMount;
      var providerKids;
      if(usePortals){
        var Frag=el.Fragment||'div';
        providerKids=el.createElement(Frag,null,
          inserterNode?el.createPortal(inserterNode,inserterMount):null,
          toolbarNode?el.createPortal(toolbarNode,toolbarMount):null,
          el.createPortal(canvasNode,canvasMount)
        );
      }else{
        providerKids=el.createElement('div',{className:'hossam-rich-shell'},
          inserterNode,
          toolbarNode,
          canvasNode
        );
      }
      return el.createElement(BlockEditorProvider,{value:blocks,onInput:handleInput,onChange:handleChange,settings:settings},providerKids);
    }
    var host=container;
    var root=null;
    if(typeof el.createRoot==='function') root=el.createRoot(host);
    else if(typeof el.render==='function'){el.render(el.createElement(HossamRichApp,null),host);root={rendered:true};}
    else throw new Error('missing-root');
    if(root&&typeof root.render==='function') root.render(el.createElement(HossamRichApp,null));
    hossamRichState.inited[containerId]={ready:true,blocks:initialBlocks,textareaId:textareaId,root:root};
    container.removeAttribute('hidden');
    /* الـtextarea يُخفى فقط بعد نجاح التهيئة — ثم يُتحقق من الـiframe. */
    textarea.classList.add('hossam-rich-hidden');
    setTimeout(function(){
      try{
        var frame=container.querySelector?container.querySelector('iframe'):null;
        if(!frame){
          /* تعذر الـiframe: عودة آمنة — الـtextarea يبقى حقل النموذج. */
          textarea.classList.remove('hossam-rich-hidden');
          hossamRichState.inited[containerId]={ready:false,blocks:null,textareaId:textareaId};
          hossamRichNotice();
        }
      }catch(e){}
    },2500);
    return true;
  }catch(err){
    /* أي فشل: الـtextarea ظاهر + تنبيه قصير — لا يعطل بقية اللوحة. */
    try{container.setAttribute('hidden','');}catch(e){}
    hossamRichNotice();
    return false;
  }
}
function hossamRichInitVisible(){
  try{
    var cfg=hossamRichConfig();
    if(!cfg||!cfg.enabled) return;
    var docRef=(typeof document!=='undefined')?document:null;
    if(!docRef||!docRef.getElementById) return;
    ['hossam-rich-editor-new','hossam-rich-editor-edit'].forEach(function(id){
      var c=docRef.getElementById(id);
      if(!c) return;
      /* اللوحتان مخفيتان حتى التفعيل — هيّئ عند أول ظهور فعلي.
         v1.0.6 §9 (doc:124): !offsetParent وstyle.display دائما-الصحة
         للمخفية (display:none عبر stylesheet لا inline) فأُسقطا — المرئية
         فعلًا فقط، والمستمعان أدناه يعيدان التسليح عند الفتح. */
      var panel=c.closest?c.closest('.panel'):null;
      var visible=!panel||panel.classList.contains('active')||!!panel.offsetParent;
      if(!visible&&panel&&!panel.classList.contains('active')) return;
      if(!hossamRichState.inited[id]||!hossamRichState.inited[id].ready) hossamRichInitEditor(id);
    });
  }catch(e){}
}
/* سلْسِل الكتل إلى الـtextarea قبل hossamCreate/UpdateArticle. */
function hossamSyncRichEditorToTextarea(form){
  try{
    if(!form||!form.querySelector) return;
    var ta=form.querySelector('[name="content"]');
    if(!ta||!ta.id) return;
    var id=ta.id==='hossam-new-content'?'hossam-rich-editor-new':(ta.id==='hossam-edit-content'?'hossam-rich-editor-edit':null);
    if(!id||!hossamRichState.inited[id]||!hossamRichState.inited[id].ready||!hossamRichState.inited[id].blocks) return;
    var wp0=hossamRichWp();
    if(!wp0||!wp0.blocks||typeof wp0.blocks.serialize!=='function') return;
    ta.value=wp0.blocks.serialize(hossamRichState.inited[id].blocks);
  }catch(e){}
}
if(typeof document!=='undefined'&&document&&typeof document.addEventListener==='function'){
  document.addEventListener('DOMContentLoaded',function(){try{hossamRichInitVisible();}catch(e){}});
  /* التنقل بين لوحات HAL يجري داخل الصفحة — التقط أول فتح للوحة. */
  document.addEventListener('click',function(){try{setTimeout(hossamRichInitVisible,60);}catch(e){}},true);
}
if(typeof window!=='undefined'&&window&&typeof window.addEventListener==='function'){
  window.addEventListener('hashchange',function(){try{setTimeout(hossamRichInitVisible,60);}catch(e){}});
}
