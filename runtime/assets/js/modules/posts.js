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
  /* [R21-ج] ربط chips robots بالقائمة المغلقة حصرًا (index/noindex/follow/
     nofollow) — منع NoArchive/NoSnippet من الطريق client أيضًا (تطبيع
     صارم للمقارنة غير الحساسة لحالة الأحرف). الفرض الخادمي (تقاطع القائمة
     المسموحة) حاكم بلا تغيير. */
  var ROBOTS_CLOSED=['index','noindex','follow','nofollow'];
  seoRobots.forEach(box=>{
    var rv=(box&&box.value!=null)?String(box.value).toLowerCase():'';
    if(ROBOTS_CLOSED.indexOf(rv)!==-1){fd.append("robots[]",rv);}
  });
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
   [B9-09] v1.0.7 REC-1/3/4 — حارس كتابة المزامنة (لا محو لنص المستخدم
   عند غياب الحالة أو فشل التسلسل)، تهيئة idempotent بسجل جذور وحاوية
   جذر داخلية، ومسبار إنهاء بإشارة iframe حقيقية — الأحدث؛ [B9-08] باقٍ.
   ══════════════════════════════════════════════════════════════ */
var hossamRichState={inited:{},roots:{},coreBlocks:false}; /* [B9-09] v1.0.7 REC-3: جذور الحاويات؛ S2: تسجيل الكتل مرة واحدة */
/* [R39] OEB/5 حالة المساحة hossamOeb* (بيانات لا دوال) — معلنة هنا كي يُلتقط
   خط الأساس عند init hossamRichState قبل أي شيء آخر. */
var hossamOebState={baseline:null,snapshot:null,synced:true,pendingVisibility:false,dirtyBeforeOpen:false,interval:null,pulses:0};
var hossamOebFeatures='width=1200,height=800,scrollbars=yes,resizable=yes'; /* مقاسات ثابتة مقترحة */
/* [R39-4] خط أساس المزامنة = تحميل الصفحة لا لقطة الفتح — يُلتقط عند init
   hossamRichState لقيم hossam-new-content/hossam-edit-content؛ لقطة الفتح
   تُحفظ منفصلة عند كل فتح (hossamOebBeginCycle). */
try{hossamOebCaptureBaseline();}catch(e0){}
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
/* [B9-09] v1.1 A6/D6: إشارة المسبار = إطار مرئي فعليًا وله محتوى جاهز —
   وجود عنصر iframe وحده لا يكفي (بوابة v4 تشترط الرؤية + المحتوى). مستند
   غير متاح/غير مكتمل = غير جاهز: يستمر الاقتراع حتى السقف ثم fallback. */
function hossamRichFrameReady(frame){
  try{
    if(!frame) return false;
    var rect=frame.getBoundingClientRect?frame.getBoundingClientRect():null;
    var visible=!!rect&&rect.width>0&&rect.height>0;
    if(visible&&typeof getComputedStyle==='function'){
      var style=getComputedStyle(frame);
      if(style) visible=style.display!=='none'&&style.visibility!=='hidden'&&Number(style.opacity)>0;
    }
    if(!visible) return false;
    var content=frame.contentDocument;
    if(!content||content.readyState!=='complete') return false;
    /* حاوية block-editor فعلية داخل body — البحث في descendants فقط كي لا
       يطابق body نفسه (class ‏block-editor-iframe__body) فيُعدّ محتوى وهميًا.
       غيابها = استمرار الاقتراع حتى السقف ثم fallback. */
    var scope=(content.body&&content.body.querySelector)?content.body:content;
    try{return !!scope.querySelector('[class*="block-editor"],[data-type],.block-editor-block-list__layout');}catch(e){return false;}
  }catch(e){return false;}
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
  var prior=hossamRichState.inited[containerId]||null;
  /* [B9-09] v1.1 A11: الحالات النهائية (fallback/error) معلّقة — لا إعادة
     محاولة تلقائية؛ المحاولة التالية بعد إعادة تحميل الصفحة فقط. */
  if(prior&&(prior.phase==='fallback'||prior.phase==='error')) return true;
  if(prior&&prior.phase==='ready'&&prior.ready) return true;
  /* [B9-09] v1.0.7 REC-3: تهيئة واحدة idempotent — جذر قائم لهذه الحاوية لا يُعاد إنشاؤه. */
  if(hossamRichState.roots[containerId]) return true;
  var cfg=hossamRichConfig();
  if(!cfg||!cfg.enabled) return false;
  var docRef=(typeof document!=='undefined')?document:null;
  if(!docRef||!docRef.getElementById) return false;
  var container=docRef.getElementById(containerId);
  if(!container) return false;
  var textareaId=container.getAttribute?container.getAttribute('data-textarea'):'';
  var textarea=textareaId?docRef.getElementById(textareaId):null;
  if(!textarea) return false;
  var host=null, root=null;
  function failFallback(reason){
    hossamRichState.inited[containerId]={ready:false,phase:'fallback',confirmed:false,blocks:null,initialHtml:textarea.value||'',textareaId:textareaId,root:null,probe:reason,latch:true};
    try{container.setAttribute('hidden','');}catch(e){}
    hossamRichNotice();
  }
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
    /* [B9-09] v1.1 A4/S2: تسجيل الكتل الأساسية مرة واحدة عبر اللوحتين —
       تُعلَّم المحاولة قبل الاستدعاء كي لا يتكرر الرمي/التسجيل عند إعادة
       تهيئة اللوحة الأخرى. */
    if(!hossamRichState.coreBlocks){
      hossamRichState.coreBlocks=true;
      try{
        if(wp0.blockLibrary&&typeof wp0.blockLibrary.registerCoreBlocks==='function') wp0.blockLibrary.registerCoreBlocks();
        else if(typeof blocksPkg.registerCoreBlocks==='function') blocksPkg.registerCoreBlocks();
      }catch(regErr){}
    }
    var initialHtml=textarea.value||'';
    /* [B9-09] v1.1 A7/D8: فشل parse أو كتل فارغة لمحتوى غير فارغ = fallback
       صريح — لا تُركَّب واجهة ميتة ولا يُمَس نص المستخدم. */
    var initialBlocks=[];
    var parseFailed=false;
    try{initialBlocks=blocksPkg.parse(initialHtml)||[];}catch(parseErr){parseFailed=true;}
    if(parseFailed||((!initialBlocks||!initialBlocks.length)&&String(initialHtml).trim()!=='')){
      failFallback(parseFailed?'parse-error':'parse-empty');
      return false;
    }
    var canUpload=!!cfg.canUpload;
    /* [R21-ب] تقييد قوائم العناوين/الكتل عبر settings.allowedBlockTypes —
       إرشادي فقط (الفرض الخادمي حاكم)؛ القائمة = الكتل الرسمية المسجلة
       (core/*) المحصورة لحظة التهيئة بعد registerCoreBlocks. تعذر الحصر =
       بلا تقييد (السلوك القائم بلا تغيير — لا رفض إضافي). */
    var allowedTypes=null;
    try{
      if(typeof blocksPkg.getBlockTypes==='function'){
        allowedTypes=blocksPkg.getBlockTypes()
          .map(function(bt){return bt&&bt.name;})
          .filter(function(n){return typeof n==='string'&&n.indexOf('core/')===0;});
      }
    }catch(btErr){allowedTypes=null;}
    if(!allowedTypes||!allowedTypes.length) allowedTypes=true;
    var settings={
      canUpload:canUpload,
      mediaUpload:function(o){hossamRichMediaUpload(wp0,cfg,o);},
      isRTL:!!cfg.isRtl,
      allowedBlockTypes:allowedTypes,
    };
    /* [R21-أ] تطبيع H1→H2 عند اللصق داخل محرر-embed قبل التسلسل إلى
       textarea — معالج paste على الحاوية الرسمية + مستند iframe الجاهز.
       محرر wp-admin خارج هذا المسار كليًا (تطبيع النواة والصمام الخادمي
       حاكمان على الطريقين). تطبيع حالة الكتل الرسمية فقط — نسخة ضحلة
       لمستوى العنوان — بلا أي محرك تحرير بديل. */
    var PASTE_NORM_WINDOW=1500;
    function normalizePastedH1(list){
      try{
        var changed=false;
        function walk(arr){
          var out=arr;
          for(var i=0;i<arr.length;i++){
            var b=arr[i];
            if(!b) continue;
            var fixed=null;
            if(b.name==='core/heading'&&b.attributes&&b.attributes.level===1){
              fixed=Object.assign({},b,{attributes:Object.assign({},b.attributes,{level:2})});
              changed=true;
            }
            var newInner=(b.innerBlocks&&b.innerBlocks.length)?walk(b.innerBlocks):null;
            if(fixed||newInner){
              if(out===arr) out=arr.slice(0);
              if(fixed) out[i]=fixed;
              if(newInner) out[i]=Object.assign({},out[i],{innerBlocks:newInner});
            }
          }
          return out;
        }
        var result=walk(list||[]);
        return changed?result:list;
      }catch(e){return list;}
    }
    function pasteFresh(){
      var live=hossamRichState.inited[containerId];
      return !!(live&&live.pasteAt&&(Date.now()-live.pasteAt)<PASTE_NORM_WINDOW);
    }
    function markPaste(){
      var live=hossamRichState.inited[containerId];
      if(live) live.pasteAt=Date.now();
    }
    /* معالج paste على الحاوية الرسمية (أحداث الحاوية نفسها). */
    try{container.addEventListener('paste',markPaste,true);}catch(pe0){}
    /* أنماط المحتوى تُمرَّر إلى iframe عبر واجهة BlockCanvas الرسمية
       (styles) — مع RTL من إعدادات HAL. */
    var contentCss='html{direction:'+(cfg.isRtl?'rtl':'ltr')+';}body{font-family:inherit;line-height:1.7;max-width:100%;}img{max-width:100%;height:auto;}table{border-collapse:collapse;width:100%;}';
    function HossamRichApp(){
      var useState=el.useState;
      var state=useState(initialBlocks);
      var blocks=state[0], setBlocks=state[1];
      /* [B9-09] v1.1 A3/D2: تحديث الحقول الحية فقط — لا استبدال للسجل
         (phase/confirmed/initialHtml/root تبقى كما هي). */
      var rec=hossamRichState.inited[containerId]||null;
      if(rec&&(rec.phase==='mounting'||rec.phase==='ready')) rec.blocks=blocks;
      function keep(next){
        /* [R21-أ] التطبيع عند اللصق قبل تثبيت الحالة — التسلسل اللاحق إلى
           textarea يقرأ الحالة المطبَّعة فتصل القيمة قبل الكتابة مطبَّعة. */
        if(pasteFresh()) next=normalizePastedH1(next);
        var live=hossamRichState.inited[containerId]||null;
        if(live&&(live.phase==='mounting'||live.phase==='ready')) live.blocks=next;
        setBlocks(next);
      }
      function handleChange(next){keep(next);}
      function handleInput(next){keep(next);}
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
    /* [B9-09] v1.1 A1: سجل الحالة يبدأ mounting — لا ready قبل إشارة صالحة. */
    var record={ready:false,phase:'mounting',confirmed:false,blocks:initialBlocks,initialHtml:initialHtml,textareaId:textareaId,root:null,probe:'pending',latch:false,pasteAt:0};
    hossamRichState.inited[containerId]=record;
    /* [B9-09] v1.0.7 REC-3: الجذر على حاوية داخلية مستحدثة داخل غلاف المحرر —
       أول commit يمسح محتوى حاوية الجذر وحدها، فتبقى حوامل القالب
       (portal targets) موصولة في DOM ولا تسقط الوصلات. */
    host=container.querySelector?container.querySelector('.hossam-rich-root'):null;
    if(!host){host=docRef.createElement('div');host.className='hossam-rich-root';container.appendChild(host);}
    if(typeof el.createRoot==='function') root=el.createRoot(host);
    else if(typeof el.render==='function'){el.render(el.createElement(HossamRichApp,null),host);root={rendered:true};}
    else throw new Error('missing-root');
    hossamRichState.roots[containerId]=root; /* v1.0.7 REC-3 */
    record.root=root;
    if(root&&typeof root.render==='function') root.render(el.createElement(HossamRichApp,null));
    container.removeAttribute('hidden');
    /* [B9-09] v1.0.7 REC-4/v1.1 A6-D6: الـtextarea لا يُخفى في التهيئة —
       يُخفى فقط عند إشارة صالحة: iframe مرئي فعليًا وله محتوى جاهز
       (وليس مجرد وجود عنصر iframe). السقف 40×250ms = 10s. */
    var probeTries=0, probeEvery=250, probeLimit=40, confirmed=false;
    var hossamRichProbe=function(){
      if(confirmed) return;
      var frame=null;
      try{frame=container.querySelector?container.querySelector('[data-role="canvas"] iframe, .hossam-rich-root iframe'):null;}catch(e){frame=null;}
      if(frame&&hossamRichFrameReady(frame)){
        /* [B9-09] v1.1 A2/D1: نص الـtextarea تغيّر أثناء التركيب = المستخدم
           سبق المحرر؛ النص اليدوي يفوز: إخفاء الواجهة + notice + fallback
           معلّق، ولا sync لاحقًا. */
        if(textarea.value!==initialHtml){
          record.ready=false;record.phase='fallback';record.confirmed=false;record.blocks=null;record.probe='dirty-textarea';record.latch=true;
          try{container.setAttribute('hidden','');}catch(e){}
          hossamRichNotice();
          return;
        }
        confirmed=true;
        record.ready=true;record.phase='ready';record.confirmed=true;record.probe='iframe-ready';
        textarea.classList.add('hossam-rich-hidden');
        /* [R21-أ] مستند iframe الجاهز هو هدف اللصق الفعلي داخل canvas
           الرسمي — ربط معالج paste عليه بعد إشارة الجاهزية. */
        try{if(frame.contentDocument&&typeof frame.contentDocument.addEventListener==='function') frame.contentDocument.addEventListener('paste',markPaste,true);}catch(pe1){}
        return;
      }
      probeTries++;
      if(probeTries<probeLimit){setTimeout(hossamRichProbe,probeEvery);return;}
      /* [B9-09] v1.1 A5/D4: السقف بلا إشارة صالحة = fallback صريح:
         إخفاء الواجهة الميتة، إبقاء textarea ظاهرًا، لا sync. */
      try{console.warn('[HAL] rich probe: no ready iframe within '+(probeEvery*probeLimit/1000)+'s - fallback (not a clean finish)');}catch(e){}
      record.ready=false;record.phase='fallback';record.confirmed=false;record.blocks=null;record.probe='timeout';record.latch=true;
      textarea.classList.remove('hossam-rich-hidden');
      try{container.setAttribute('hidden','');}catch(e){}
      hossamRichNotice();
    };
    setTimeout(hossamRichProbe,probeEvery);
    return true;
  }catch(err){
    /* [B9-09] v1.1 A8: تنظيف كامل قبل التنبيه — unmount + إزالة الحاوية
       الداخلية + حذف سجل الجذر + حالة error معلّقة؛ الـtextarea يبقى ظاهرًا. */
    try{if(root&&typeof root.unmount==='function') root.unmount();}catch(e){}
    try{if(host&&host.parentNode) host.parentNode.removeChild(host);}catch(e){}
    try{delete hossamRichState.roots[containerId];}catch(e){}
    hossamRichState.inited[containerId]={ready:false,phase:'error',confirmed:false,blocks:null,initialHtml:textarea.value||'',textareaId:textareaId,root:null,probe:'error',latch:true};
    try{container.setAttribute('hidden','');}catch(e){}
    try{textarea.classList.remove('hossam-rich-hidden');}catch(e){}
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
/* [B9-09] v1.0.7 REC-1: لا كتابة فوق الـtextarea إلا بتسلسل ناجح من الحالة الحية؛
   غياب الحالة = تخطٍّ صامت، وحالة غير جاهزة/فشل تسلسل = بقاء النص + أثر تشخيصي. */
function hossamSyncRichEditorToTextarea(form){
  try{
    if(!form||!form.querySelector) return;
    var ta=form.querySelector('[name="content"]');
    if(!ta||!ta.id) return;
    var id=ta.id==='hossam-new-content'?'hossam-rich-editor-new':(ta.id==='hossam-edit-content'?'hossam-rich-editor-edit':null);
    var st=(id&&hossamRichState.inited[id])||null;
    if(!st) return; /* لا حالة بعد — تخطٍّ صامت سليم: نص المستخدم يبقى كما هو. */
    /* [B9-09] v1.1 A9: الكتابة فقط بعد إشارة صالحة (phase/confirmed) وبكتل حية. */
    if(st.phase!=='ready'||!st.confirmed||!st.ready||!st.blocks){
      try{console.warn('[HAL] rich sync: state not ready - textarea kept as-is');}catch(e){}
      return;
    }
    /* [B9-09] v1.1 A9: لمس المستخدم للـtextarea يدويًا يمنع الكتابة فوقه. */
    if(ta.value!==st.initialHtml){
      try{console.warn('[HAL] rich sync: textarea edited manually - textarea kept as-is');}catch(e){}
      return;
    }
    var wp0=hossamRichWp();
    if(!wp0||!wp0.blocks||typeof wp0.blocks.serialize!=='function'){
      try{console.warn('[HAL] rich sync: serializer unavailable - textarea kept as-is');}catch(e){}
      return;
    }
    var out=null;
    try{out=wp0.blocks.serialize(st.blocks);}catch(serErr){out=null;}
    if(typeof out!=='string'){
      try{console.warn('[HAL] rich sync: serialize failed - textarea kept as-is');}catch(e){}
      return;
    }
    ta.value=out;
    /* خط الأساس يُحدَّث بعد كتابتنا كي يبقى الحفظ التالي صالحًا. */
    st.initialHtml=out;
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

/* ══════════════════════════════════════════════════════════════
   [R39] OEB/5 (H1 §2) — مساحة hossamOeb* حصرًا: النافذة والمراقب والعودة.
   - صفر دوال عالمية جديدة خارج hossamOeb*؛ صفر قراءة داخل محرر wp-admin
     وصرافة رسائل بين نوافذ (لا قناة cross-window إطلاقًا) — القرار من
     حالات الصفحة المحلية حصرًا (win.closed + قيم حقول الصفحة).
   - المرسي: .js-oeb-open (R23) بحمله target=_blank — الاعتراض يُسقط
     preventDefault فقط عند نجاح window.open؛ حاجب النوافذ = تراجع كامل
     عن الاعتراض والمرسي يفتح تبويبًا، والمراقب الوحيد حينها visibilitychange
     مسجل مرة واحدة بمزلاج لكل دورة.
   - خط أساس المزامنة = تحميل الصفحة لا لقطة الفتح؛ قرار العودة: تغيّر
     مقابل خط الأساس ⇒ لا إعادة تحميل + toast بتحديث يدوي؛ لم يتغير ⇒
     إعادة تحميل واحدة. لقطة الفتح منفصلة وحالة «وسخ قبل الفتح» مادية في
     hossamOebState.dirtyBeforeOpen (لا إعادة تحميل عند لقطة وسخ).
   - المراقب: كل 1000ms يسأل win.closed فقط — سقف 15 نبضة ثم clearInterval
     بلا sync؛ لا مؤقت غير محدود أبدًا.
   ══════════════════════════════════════════════════════════════ */
/* الحالة hossamOebState ومقاسات النافذة hossamOebFeatures معلنة عند init
   hossamRichState (أعلى الملف) — هنا الدوال حصرًا. */
/* مطبّع وضع mirror (نمط O1: فشل-مغلق نحو embed) — 'official' الوحيد المقبول
   حرفيًا؛ embed/mirror/الغياب/أي قيمة غريبة = embed. */
function hossamOebNormalizeMode(){
  try{
    var cfg=(typeof window!=='undefined')?window.hossamRichEditor:null;
    var mode=(cfg&&typeof cfg==='object')?cfg.mode:'';
    return mode==='official'?'official':'embed';
  }catch(e){return 'embed';}
}
/* [R39-4] خط أساس التحميل: يُلتقط عند init hossamRichState (أعلاه) ويُكمِل
   المداخل الغائبة عند DOMContentLoaded — كلاهما قبل أي تفاعل. */
function hossamOebCaptureBaseline(){
  try{
    var doc=(typeof document!=='undefined')?document:null;
    if(!doc||!doc.getElementById) return;
    var b=hossamOebState.baseline||{newContent:null,editContent:null};
    var nc=doc.getElementById('hossam-new-content');
    var ec=doc.getElementById('hossam-edit-content');
    if(b.newContent===null&&nc) b.newContent=nc.value||'';
    if(b.editContent===null&&ec) b.editContent=ec.value||'';
    hossamOebState.baseline=b;
  }catch(e){}
}
function hossamOebSnapshotContent(){
  var out={newContent:null,editContent:null};
  try{
    var doc=(typeof document!=='undefined')?document:null;
    if(!doc||!doc.getElementById) return out;
    var nc=doc.getElementById('hossam-new-content');
    var ec=doc.getElementById('hossam-edit-content');
    if(nc) out.newContent=nc.value||'';
    if(ec) out.editContent=ec.value||'';
  }catch(e){}
  return out;
}
/* مقارنة لقطة مقابل خط أساس التحميل — الحقول غير الملتقطة مستثناة. */
function hossamOebDiffers(snap){
  var b=hossamOebState.baseline;
  if(!b||!snap) return false;
  if(b.newContent!==null&&snap.newContent!==null&&snap.newContent!==b.newContent) return true;
  if(b.editContent!==null&&snap.editContent!==null&&snap.editContent!==b.editContent) return true;
  return false;
}
function hossamOebChangedVsBaseline(){
  try{return hossamOebDiffers(hossamOebSnapshotContent());}catch(e){return false;}
}
function hossamOebEditorUrl(btn){
  try{
    if(!btn||!btn.getAttribute) return '';
    var u=btn.getAttribute('href');
    return (typeof u==='string')?u:'';
  }catch(e){return '';}
}
/* دورة فتح جديدة: إعادة تهيئة مزلاج العودة + لقطة الفتح المنفصلة + حالة
   «وسخ قبل الفتح» (كتابة غير محفوظة قبل فتح النافذة) مقابل خط الأساس.
   openDialog=false في مسار حاجب النوافذ (بلا dialog هناك). */
function hossamOebBeginCycle(editorUrl,openDialog){
  hossamOebState.synced=false;
  hossamOebState.pendingVisibility=false;
  hossamOebState.snapshot=hossamOebSnapshotContent();
  hossamOebState.dirtyBeforeOpen=hossamOebDiffers(hossamOebState.snapshot);
  if(openDialog!==false) hossamOebDialogOpen(editorUrl);
}
function hossamOebOpen(btn,evt){
  /* الخروج المبكر official (دفاع في العمق): الوضع المطبَّع ≠ official =
     no-op مع ترك المرسي (target=_blank يعمل كما هو). */
  if(hossamOebNormalizeMode()!=='official') return;
  var editorUrl=hossamOebEditorUrl(btn);
  if(!editorUrl) return;
  var win=null;
  try{win=window.open(editorUrl,'hossamOeb',hossamOebFeatures);}catch(e){win=null;}
  if(!win){
    /* fallback التبويب: حاجب نوافذ أعاد null — إسقاط الاعتراض (لا
       preventDefault) والمرسي يتمسك بtarget=_blank؛ المراقب الوحيد
       visibilitychange (مسجل مرة واحدة أدناه) بمزلاج pendingVisibility.
       دورة الحاجب دورة فتح كذلك: المزلاج واللقطة وحالة الوسخ تُهيَّأ هنا
       (بلا dialog — لا نصوص جديدة ولا اعتراض للمرسي). */
    hossamOebBeginCycle('',false);
    hossamOebState.pendingVisibility=true;
    return;
  }
  if(evt&&typeof evt.preventDefault==='function') evt.preventDefault();
  hossamOebBeginCycle(editorUrl);
  hossamOebMonitor(win);
}
/* المراقب: كل 1000ms يسأل win.closed فقط — سقف 15 نبضة. */
function hossamOebMonitor(win){
  try{if(hossamOebState.interval){clearInterval(hossamOebState.interval);}}catch(e0){}
  hossamOebState.pulses=0;
  var id=setInterval(function(){
    hossamOebState.pulses++;
    var closed=false;
    try{closed=!!(win&&win.closed);}catch(e){closed=false;}
    if(closed){
      clearInterval(id);
      hossamOebState.interval=null;
      hossamOebDialogClose();
      hossamOebReturnSync(); /* مزلاج العودة يجعلها مرة واحدة لكل دورة */
      return;
    }
    if(hossamOebState.pulses>=15){
      /* بلوغ 15 بلا إغلاق: clearInterval بلا sync مؤقت — لا مؤقت غير محدود. */
      clearInterval(id);
      hossamOebState.interval=null;
    }
  },1000);
  hossamOebState.interval=id;
}
/* العودة والمزامنة: مزلاج لكل دورة فتح (مرة واحدة مهما تكررت الإشارات
   ويُعاد تهيئته في hossamOebBeginCycle). القرار مقابل خط أساس التحميل. */
function hossamOebReturnSync(){
  if(hossamOebState.synced) return;
  hossamOebState.synced=true;
  hossamOebDialogClose(); /* إغلاق آلي عند المزامنة */
  var changed=false;
  try{changed=hossamOebChangedVsBaseline();}catch(e){changed=false;}
  if(changed){
    /* تغيّر مقابل خط أساس التحميل (يشمل الوسخ قبل الفتح): لا إعادة تحميل —
       toast بتحديث يدوي (مفتاح i18n من R41). */
    try{showToast(t('oeb_manual_update','Manual update started.'),'success');}catch(e1){}
    return;
  }
  /* لم يتغير: إعادة تحميل واحدة. */
  try{window.location.reload();}catch(e2){}
}
/* hossamOebDialog: فتح/إغلاق <dialog> القالب + الرابط الاحتياطي بنفس
   editorUrl (نص الحالة يأتي من القالب/i18n بلا نصوص جديدة هنا). */
function hossamOebDialogOpen(editorUrl){
  try{
    var doc=(typeof document!=='undefined')?document:null;
    if(!doc||!doc.getElementById) return;
    var dlg=doc.getElementById('hossam-oeb-dialog');
    if(!dlg) return;
    var link=doc.getElementById('hossam-oeb-dialog-link');
    if(link&&typeof link.setAttribute==='function') link.setAttribute('href',editorUrl||'');
    if(typeof dlg.showModal==='function'){dlg.showModal();}
    else if(typeof dlg.setAttribute==='function') dlg.setAttribute('open','open');
  }catch(e){}
}
function hossamOebDialogClose(){
  try{
    var doc=(typeof document!=='undefined')?document:null;
    if(!doc||!doc.getElementById) return;
    var dlg=doc.getElementById('hossam-oeb-dialog');
    if(!dlg) return;
    if(typeof dlg.close==='function'){dlg.close();}
    else if(typeof dlg.removeAttribute==='function') dlg.removeAttribute('open');
  }catch(e){}
}
if(typeof document!=='undefined'&&document&&typeof document.addEventListener==='function'){
  /* اعتراض نقرة المرسي .js-oeb-open (R23) — القرار داخل hossamOebOpen. */
  document.addEventListener('click',function(e){
    try{
      var b=(e&&e.target&&e.target.closest)?e.target.closest('.js-oeb-open'):null;
      if(!b) return;
      hossamOebOpen(b,e);
    }catch(err){}
  });
  /* زر الإغلاق .js-oeb-close في <dialog> القالب (R23). */
  document.addEventListener('click',function(e){
    try{
      var c=(e&&e.target&&e.target.closest)?e.target.closest('.js-oeb-close'):null;
      if(!c) return;
      hossamOebDialogClose();
    }catch(err){}
  });
  /* المراقب الوحيد لمسار الحاجب: visibilitychange مسجل مرة واحدة — مزلاج
     pendingVisibility يحدد دورة الفتح النشطة ويُعاد تهيئته في الفتح التالي. */
  document.addEventListener('visibilitychange',function(){
    try{
      if(!hossamOebState.pendingVisibility) return;
      if(document.visibilityState&&document.visibilityState!=='visible') return;
      hossamOebState.pendingVisibility=false;
      hossamOebReturnSync();
    }catch(e){}
  });
  /* إكمال خط أساس التحميل لأي حقل غاب عن لحظة init (ما زال ضمن التحميل). */
  document.addEventListener('DOMContentLoaded',function(){try{hossamOebCaptureBaseline();}catch(e){}});
}
