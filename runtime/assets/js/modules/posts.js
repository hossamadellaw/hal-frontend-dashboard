/**
 * assets/js/modules/posts.js
 * ══════════════════════════════════════════════════════════════
 * Related task: T-22
 * The two functions carried verbatim from the original dashboard.js:
 *   - restoreArticle(postId) + the .js-restore-article event listener
 *   - translateArticle(postId, lang) + the .js-translate-article event listener
 * (liveSearch() may stay in the shell — see the TODO in dashboard.js)
 *
 * TODO:
 * [x] Moved the two functions + their event listeners verbatim
 * [x] They call the actions: hossam_restore_article, hossam_translate_article
 *     (already present in ajax/posts.php after T-06)
 *
 * Second batch (2026-08-24) — Core-first for item 3 verbatim: "adapters/
 * wordpress-posts.php owns the logic; ajax/posts.php only translates":
 * hossamCreateArticle/hossamUpdateArticle/hossamTrashArticle submit to the
 * native CRUD endpoints. The documented fallback rule (gate 8.2 verbatim):
 * [frontend_admin] never opens on a security rejection or invalid input
 * (401/403/404/400 with an understandable server message = toast only), and
 * opens only on a "specific technical failure": a network exception /
 * HTTP>=500 / an unparseable response.
 * ══════════════════════════════════════════════════════════════
 */

/* [Core-first — second batch] Failure classification: technical failures only earn the fallback */
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
  /* [B9-08] v1.0.6 §9: sync the editor blocks into the textarea before reading —
     the server contract verbatim unchanged (wp_kses_post remains the
     sanitization owner). */
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
  /* [B9-05] + [B9-06] P1: send the value when it is not empty — zero '0'
     is sent explicitly for removal (no '0' exclusion, and never -1). */
  if(thumb&&thumb.value!==''){fd.append("thumbnail_id",thumb.value);}
  let netError=false,resp=null,d=null;
  try{
    resp=await fetch(hossamAjax.ajaxurl,{method:"POST",body:fd});
    try{d=await resp.json();}catch(e){d=null;}
  }catch(e){netError=true;}
  if(d&&d.success){
    showToast(hossamPostToastLabel(status?status.value:'draft'),'success');
    /* [B9-06] P1: a success card with clickable links is shown before any
       automatic redirect; the create transition (edit_url) is delayed until
       user interaction (a clickable link) or a short timeout. */
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
  /* [B9-08] v1.0.6 §9: sync the editor blocks into the textarea before reading —
     the server contract verbatim unchanged (wp_kses_post remains the
     sanitization owner). */
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
  /* [B9-05] + [B9-06] P1: zero '0' is sent explicitly for removal (no '0'
     exclusion, and never -1) — a completely empty update keeps the contract
     (no key). */
  if(thumb&&thumb.value!==''){fd.append("thumbnail_id",thumb.value);}
  let netError=false,resp=null,d=null;
  try{
    resp=await fetch(hossamAjax.ajaxurl,{method:"POST",body:fd});
    try{d=await resp.json();}catch(e){d=null;}
  }catch(e){netError=true;}
  if(d&&d.success){
    showToast(t('updated','Updated'),'success');
    /* [B9-06] P1: the update shows the success card with its links instead of
       an instant reload — the links stay usable with no automatic redirect. */
    hossamShowPostSuccessCard(form,d,t('updated','Updated'));
    return true;
  }
  showToast((d&&d.data&&d.data.message)||t('networkError','Network error'),'error');
  if(hossamIsTechnicalFailure(netError,resp,d)){hossamRevealFallback('fa-fallback-edit');}
  return false;
}

/* [Core-first] Trash → hossam_trash_article (explicit confirmation before submit) */
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
   [B9-06] v1.0.6 P1: lazy binding — a permanent listener on the button checks
   wp.media at click time (not load time); in its absence a refined user-facing
   message in the admin-settings.js style with no details — no silent exit.
   + a remove button that sets the field to '0' and hides the preview.
   + a success card with clickable links. */
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
/* [B9-06] P1: success links — show whatever arrives in d.data when present
   (view_url for the published post, preview_url for draft/pending/scheduled,
   edit_url).
   v1.0.6 §8.6: + a deep link to the SEO panel derived from edit_url (panel=seo
   — no new endpoint); never derived from anything but the edit panel's
   edit_url. */
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
  /* v1.0.6 §8.6: deep link from the success card to the SEO panel. */
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
  /* [R38] v1.1.0 (H1 §7-1): the official editor (OEB) open link in the success
     card — present conditionally with the server-side oeb_url (esc_url) in
     the create/update responses only.
     createElement/textContent only (no literal HTML); the existing hossamOebOpen
     interceptor (.js-oeb-open) picks it up automatically: window+monitor+return.
     The text uses the i18n key oeb_open with the same t() access path R39 uses
     for oeb_manual_update. */
  const oebUrl=d&&d.data&&d.data.oeb_url;
  if(oebUrl){
    const o=document.createElement('a');
    o.href=oebUrl;
    o.target='_blank';
    o.rel='noopener';
    o.className='js-oeb-open';
    o.textContent=t('oeb_open','Manual update');
    card.appendChild(o);
  }
  form.insertBefore(card,form.firstChild);
}
hossamBindThumbnailPicker('hossam-new-thumbnail-btn','hossam-new-thumbnail','hossam-new-thumbnail-preview','hossam-new-thumbnail-remove');
hossamBindThumbnailRemove('hossam-new-thumbnail-remove','hossam-new-thumbnail','hossam-new-thumbnail-preview');
hossamBindThumbnailPicker('hossam-edit-thumbnail-btn','hossam-edit-thumbnail','hossam-edit-thumbnail-preview','hossam-edit-thumbnail-remove');
hossamBindThumbnailRemove('hossam-edit-thumbnail-remove','hossam-edit-thumbnail','hossam-edit-thumbnail-preview');

/* v1.0.6 §8.6: SEO save from the edit-panel group via wp_ajax_hossam_save_seo.
   No legacy fallback (no alternative form for the SEO group) — a security
   rejection/invalid input = toast only. */
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
  /* [R21-c] bind the robots chips to the closed list only (index/noindex/follow/
     nofollow) — NoArchive/NoSnippet blocked on the client path too (strict
     case-insensitive comparison normalization). The server-side intersection
     guard (of the allowed list) governs unchanged. */
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
   [B9-08] v1.0.6 §9 — the rich editor: render an instance of the official
   block editor inside the HAL shell (owner decision: show and wrap, not build).
   - Official components only: BlockEditorProvider + BlockCanvas (the iframe
     and BlockList inside it) + Inserter + BlockTools/BlockToolbar — no
     Experimental* surfaces, no private interfaces, no alternative blocks/tools.
   - Blocks come exclusively from the official registration via
     registerCoreBlocks (Table, Details, media, lists, text-formatting links
     + Playlist and Tabs on 7.1.3); other plugin blocks are not auto-included
     because their assets never load inside HAL. A missing core/playlist or
     core/tabs outside 7.1.3 is bypassed with the rest of the blocks —
     UNVERIFIED locally (core 6.7.1).
   - parse on edit and serialize on save into the textarea, then
     hossamCreate/UpdateArticle as they are — the server contract verbatim
     unchanged, and wp_kses_post remains the sanitization owner (no bypass).
   - Media: MediaUpload/MediaUploadCheck via the official viewer (wp.media)
     and the official uploadMedia path with WordPress's REST/nonce settings —
     no new endpoint; attachment IDs live inside the blocks after success; a
     clear HAL message on failure. No HAL ownership restriction on content
     attachments beyond WordPress permissions (canUpload mirrors upload_files).
   - Rollback: the textarea remains the visible form field and is hidden only
     after successful initialization; on any failure (missing script/
     exception/unready iframe) it stays visible with a short notice.
     Initialization on first panel open.
     Title/categories/image/SEO/status remain HAL fields.
   [B9-09] v1.0.7 REC-1/3/4 — a sync-write guard (no wiping of user text on
   missing state or serialize failure), idempotent initialization with a roots
   registry and an internal root container, and a termination probe with a
   real iframe signal — the latest; [B9-08] remains.
   ══════════════════════════════════════════════════════════════ */
var hossamRichState={inited:{},roots:{},coreBlocks:false}; /* [B9-09] v1.0.7 REC-3: container roots; S2: register blocks once */
/* [R39] OEB/5 the hossamOeb* workspace state (data, not functions) — declared
   here so the baseline is captured at hossamRichState init before anything else. */
var hossamOebState={baseline:null,snapshot:null,synced:true,pendingVisibility:false,dirtyBeforeOpen:false,interval:null,pulses:0};
var hossamOebFeatures='width=1200,height=800,scrollbars=yes,resizable=yes'; /* fixed proposed dimensions */
/* [R39-4] the sync baseline = page load, not the open snapshot — captured at
   hossamRichState init for the hossam-new-content/hossam-edit-content values;
   the open snapshot is stored separately on every open (hossamOebBeginCycle). */
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
/* [B9-09] v1.1 A6/D6: the probe signal = an actually visible frame with ready
   content — the mere presence of an iframe element is not enough (the v4 gate
   requires visibility + content). An unavailable/incomplete document = not
   ready: polling continues to the ceiling, then fallback. */
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
    /* an actual block-editor container inside body — search descendants only so
       the body itself (class block-editor-iframe__body) does not match and
       count as phantom content.
       Its absence = polling continues to the ceiling, then fallback. */
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
/* The official upload path: uploadMedia with WordPress's REST/nonce settings. */
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
    /* apiFetch carries the official nonce/root — no new endpoint. */
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
          /* Files still uploading (progress) are passed through without insertion. */
          if(files&&files.length) onFileChange(files);
        }catch(e){}
      },
      onError:fail,
      additionalData:{},
    });
  }catch(e){fail();}
}
/* Open the official media library (wp.media) to pick an attachment WP permissions allow. */
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
  /* [B9-09] v1.1 A11: terminal states (fallback/error) are latched — no
     automatic retry; the next attempt only after a page reload. */
  if(prior&&(prior.phase==='fallback'||prior.phase==='error')) return true;
  if(prior&&prior.phase==='ready'&&prior.ready) return true;
  /* [B9-09] v1.0.7 REC-3: one idempotent initialization — an existing root for
     this container is never re-created. */
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
    /* Official only — ExperimentalBlockCanvas is rejected here. */
    if(typeof BlockEditorProvider==='undefined'||typeof BlockCanvas==='undefined'||typeof BlockList==='undefined') throw new Error('missing-components');
    /* [B9-09] v1.1 A4/S2: register the core blocks once across both panels —
       the attempt is flagged before the call so the throw/registration cannot
       repeat when the other panel re-initializes. */
    if(!hossamRichState.coreBlocks){
      hossamRichState.coreBlocks=true;
      try{
        if(wp0.blockLibrary&&typeof wp0.blockLibrary.registerCoreBlocks==='function') wp0.blockLibrary.registerCoreBlocks();
        else if(typeof blocksPkg.registerCoreBlocks==='function') blocksPkg.registerCoreBlocks();
      }catch(regErr){}
    }
    var initialHtml=textarea.value||'';
    /* [B9-09] v1.1 A7/D8: a parse failure or empty blocks for non-empty content
       = an explicit fallback — no dead interface is mounted and the user's text
       is never touched. */
    var initialBlocks=[];
    var parseFailed=false;
    try{initialBlocks=blocksPkg.parse(initialHtml)||[];}catch(parseErr){parseFailed=true;}
    if(parseFailed||((!initialBlocks||!initialBlocks.length)&&String(initialHtml).trim()!=='')){
      failFallback(parseFailed?'parse-error':'parse-empty');
      return false;
    }
    var canUpload=!!cfg.canUpload;
    /* [R21-b] restrict the heading/block lists via settings.allowedBlockTypes —
       advisory only (the server-side enforcement governs); the list = the
       registered official (core/*) blocks enumerated at initialization after
       registerCoreBlocks. If enumeration fails = unrestricted (the existing
       behavior unchanged — no additional rejection). */
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
    /* [R21-a] normalize H1→H2 on paste inside the embed editor before
       serializing to the textarea — a paste handler on the official container
       + the ready iframe document. The wp-admin editor is entirely outside
       this path (the core normalization and the server-side valve govern both
       paths). Normalizes official block state only — a shallow copy at the
       heading level — with no alternative editing engine. */
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
    /* paste handler on the official container (the container's own events). */
    try{container.addEventListener('paste',markPaste,true);}catch(pe0){}
    /* Content styles are passed to the iframe through the official BlockCanvas
       interface (styles) — with RTL from the HAL settings. */
    var contentCss='html{direction:'+(cfg.isRtl?'rtl':'ltr')+';}body{font-family:inherit;line-height:1.7;max-width:100%;}img{max-width:100%;height:auto;}table{border-collapse:collapse;width:100%;}';
    function HossamRichApp(){
      var useState=el.useState;
      var state=useState(initialBlocks);
      var blocks=state[0], setBlocks=state[1];
      /* [B9-09] v1.1 A3/D2: update the live fields only — never replace the
         record (phase/confirmed/initialHtml/root stay as they are). */
      var rec=hossamRichState.inited[containerId]||null;
      if(rec&&(rec.phase==='mounting'||rec.phase==='ready')) rec.blocks=blocks;
      function keep(next){
        /* [R21-a] normalize on paste before committing state — the later
           serialize to the textarea reads the normalized state, so the value
           arrives already normalized before any write. */
        if(pasteFresh()) next=normalizePastedH1(next);
        var live=hossamRichState.inited[containerId]||null;
        if(live&&(live.phase==='mounting'||live.phase==='ready')) live.blocks=next;
        setBlocks(next);
      }
        /* [W7] v1.1.3: the toolbar-side handle - reparse (Discard) and
           keep reuse the existing parse/keep paths only. */
        if(rec){ rec.api={keep:keep,reparse:function(){ try{ var ta=docRef.getElementById(rec.textareaId); var parsed=blocksPkg.parse(ta?(ta.value||''):'')||[]; keep(parsed); return parsed; }catch(e){ return null; } }}; }
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
      /* Template positions: inserter/toolbar/canvas — portals preserve the
         provider context. The no-portals alternative: one tree in the canvas
         mount. */
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
    /* [B9-09] v1.1 A1: the state record starts as mounting — no ready before a
       valid signal. */
    var record={ready:false,phase:'mounting',confirmed:false,blocks:initialBlocks,initialHtml:initialHtml,textareaId:textareaId,root:null,probe:'pending',latch:false,pasteAt:0};
    hossamRichState.inited[containerId]=record;
    /* [B9-09] v1.0.7 REC-3: the root is a fresh inner container inside the
       editor shell — the first commit clears only the root container's own
       content, so the template mounts (portal targets) stay connected in the
       DOM and no wiring is dropped. */
    host=container.querySelector?container.querySelector('.hossam-rich-root'):null;
    if(!host){host=docRef.createElement('div');host.className='hossam-rich-root';container.appendChild(host);}
    if(typeof el.createRoot==='function') root=el.createRoot(host);
    else if(typeof el.render==='function'){el.render(el.createElement(HossamRichApp,null),host);root={rendered:true};}
    else throw new Error('missing-root');
    hossamRichState.roots[containerId]=root; /* v1.0.7 REC-3 */
    record.root=root;
    if(root&&typeof root.render==='function') root.render(el.createElement(HossamRichApp,null));
    container.removeAttribute('hidden');
    /* [W7] v1.1.3: the static professional toolbar above the canvas +
       the bottom action bar on the write editor (the existing form
       contract). */
    hossamToolbarMount(containerId,container);
    /* [B9-09] v1.0.7 REC-4/v1.1 A6-D6: the textarea is not hidden at init —
       it is hidden only on a valid signal: an actually visible iframe with
       ready content (not the mere presence of an iframe element).
       The ceiling 40×250ms = 10s. */
    var probeTries=0, probeEvery=250, probeLimit=40, confirmed=false;
    var hossamRichProbe=function(){
      if(confirmed) return;
      var frame=null;
      try{frame=container.querySelector?container.querySelector('[data-role="canvas"] iframe, .hossam-rich-root iframe'):null;}catch(e){frame=null;}
      if(frame&&hossamRichFrameReady(frame)){
        /* [B9-09] v1.1 A2/D1: the textarea text changed during mounting = the
           user got ahead of the editor; the manual text wins: hide the
           interface + a notice + a latched fallback, and no further sync. */
        if(textarea.value!==initialHtml){
          record.ready=false;record.phase='fallback';record.confirmed=false;record.blocks=null;record.probe='dirty-textarea';record.latch=true;
          try{container.setAttribute('hidden','');}catch(e){}
          hossamRichNotice();
          return;
        }
        confirmed=true;
        record.ready=true;record.phase='ready';record.confirmed=true;record.probe='iframe-ready';
        textarea.classList.add('hossam-rich-hidden');
        /* [R21-a] the ready iframe document is the actual paste target inside
           the official canvas — bind the paste handler on it after the ready
           signal. */
        try{if(frame.contentDocument&&typeof frame.contentDocument.addEventListener==='function') frame.contentDocument.addEventListener('paste',markPaste,true);}catch(pe1){}
        return;
      }
      probeTries++;
      if(probeTries<probeLimit){setTimeout(hossamRichProbe,probeEvery);return;}
      /* [B9-09] v1.1 A5/D4: the ceiling with no valid signal = an explicit
         fallback: hide the dead interface, keep the textarea visible, no sync. */
      try{console.warn('[HAL] rich probe: no ready iframe within '+(probeEvery*probeLimit/1000)+'s - fallback (not a clean finish)');}catch(e){}
      record.ready=false;record.phase='fallback';record.confirmed=false;record.blocks=null;record.probe='timeout';record.latch=true;
      textarea.classList.remove('hossam-rich-hidden');
      try{container.setAttribute('hidden','');}catch(e){}
      hossamRichNotice();
    };
    setTimeout(hossamRichProbe,probeEvery);
    return true;
  }catch(err){
    /* [B9-09] v1.1 A8: full cleanup before the notice — unmount + remove the
       inner container + delete the root registry entry + a latched error state;
       the textarea stays visible. */
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
      /* Both panels are hidden until activated — initialize on first actual
         visibility.
         v1.0.6 §9 (doc:124): !offsetParent and style.display are unreliable
         for stylesheet-driven hiding (display:none via stylesheet, not inline),
         so both were dropped — actual visibility only, and the listeners below
         re-arm on open. */
      var panel=c.closest?c.closest('.panel'):null;
      var visible=!panel||panel.classList.contains('active')||!!panel.offsetParent;
      if(!visible&&panel&&!panel.classList.contains('active')) return;
      if(!hossamRichState.inited[id]||!hossamRichState.inited[id].ready) hossamRichInitEditor(id);
    });
  }catch(e){}
}
/* Serialize the blocks into the textarea before hossamCreate/UpdateArticle. */
/* [B9-09] v1.0.7 REC-1: never overwrite the textarea except with a successful
   serialize of live state;
   missing state = a silent skip, and a not-ready state/serialize failure = the
   text stays + a diagnostic trace. */
function hossamSyncRichEditorToTextarea(form){
  try{
    if(!form||!form.querySelector) return;
    var ta=form.querySelector('[name="content"]');
    if(!ta||!ta.id) return;
    var id=ta.id==='hossam-new-content'?'hossam-rich-editor-new':(ta.id==='hossam-edit-content'?'hossam-rich-editor-edit':null);
    var st=(id&&hossamRichState.inited[id])||null;
    if(!st) return; /* no state yet — a healthy silent skip: the user's text stays as-is. */
    /* [B9-09] v1.1 A9: write only after a valid signal (phase/confirmed) with live blocks. */
    if(st.phase!=='ready'||!st.confirmed||!st.ready||!st.blocks){
      try{console.warn('[HAL] rich sync: state not ready - textarea kept as-is');}catch(e){}
      return;
    }
    /* [B9-09] v1.1 A9: the user's manual textarea touch forbids overwriting it. */
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
    /* The baseline updates after our write so the next save stays valid. */
    st.initialHtml=out;
  }catch(e){}
}
if(typeof document!=='undefined'&&document&&typeof document.addEventListener==='function'){
  document.addEventListener('DOMContentLoaded',function(){try{hossamRichInitVisible();}catch(e){}});
  /* HAL panel navigation happens in-page — capture the first panel open. */
  document.addEventListener('click',function(){try{setTimeout(hossamRichInitVisible,60);}catch(e){}},true);
}
if(typeof window!=='undefined'&&window&&typeof window.addEventListener==='function'){
  window.addEventListener('hashchange',function(){try{setTimeout(hossamRichInitVisible,60);}catch(e){}});
}

/* ══════════════════════════════════════════════════════════════
   [R39] OEB/5 (H1 §2) — the hossamOeb* namespace only: the window, the monitor
   and the return.
   - Zero new global functions outside hossamOeb*; zero reads inside the
     wp-admin editor and zero cross-window message brokering (no cross-window
     channel at all) — decisions come exclusively from local page state
     (win.closed + the page field values).
   - The anchor: .js-oeb-open (R23) with its target=_blank — the interception
     drops preventDefault only when window.open succeeds; a window blocker =
     full retreat from interception and the anchor opens a tab, and the only
     monitor then is visibilitychange, registered once with a latch per cycle.
   - The sync baseline = page load, not the open snapshot; the return decision:
     changed against the baseline ⇒ no reload + a manual-update toast;
     unchanged ⇒ one reload. The open snapshot is separate and the
     "dirty before open" state is material in
     hossamOebState.dirtyBeforeOpen (no reload on a dirty snapshot).
   - The monitor: every 1000ms asks win.closed only — a 15-pulse ceiling then
     clearInterval with no sync; never an unbounded timer.
   ══════════════════════════════════════════════════════════════ */
/* The hossamOebState state and the hossamOebFeatures window dimensions are
   declared at hossamRichState init (top of the file) — functions only here. */
/* Normalizes the mirror mode (the O1 pattern: fail-closed toward embed) —
   'official' is the only accepted value verbatim; embed/mirror/absent/any
   foreign value = embed. */
function hossamOebNormalizeMode(){
  try{
    var cfg=(typeof window!=='undefined')?window.hossamRichEditor:null;
    var mode=(cfg&&typeof cfg==='object')?cfg.mode:'';
    return mode==='official'?'official':'embed';
  }catch(e){return 'embed';}
}
/* [R39-4] the load baseline: captured at hossamRichState init (above) and
   completed for missing entries at DOMContentLoaded — both before any interaction. */
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
/* Compare a snapshot against the load baseline — un-captured fields are excluded. */
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
/* A new open cycle: re-initialize the return latch + the separate open
   snapshot + the "dirty before open" state (unsaved writing before the window
   opened) against the baseline.
   openDialog=false on the window-blocker path (no dialog there). */
function hossamOebBeginCycle(editorUrl,openDialog){
  hossamOebState.synced=false;
  hossamOebState.pendingVisibility=false;
  hossamOebState.snapshot=hossamOebSnapshotContent();
  hossamOebState.dirtyBeforeOpen=hossamOebDiffers(hossamOebState.snapshot);
  if(openDialog!==false) hossamOebDialogOpen(editorUrl);
}
function hossamOebOpen(btn,evt){
  /* The official early exit (defense in depth): the normalized mode ≠ official
     = a no-op with the anchor left alone (target=_blank works as-is). */
  if(hossamOebNormalizeMode()!=='official') return;
  var editorUrl=hossamOebEditorUrl(btn);
  if(!editorUrl) return;
  var win=null;
  try{win=window.open(editorUrl,'hossamOeb',hossamOebFeatures);}catch(e){win=null;}
  if(!win){
    /* The tab fallback: a window blocker returned null — drop the interception
       (no preventDefault) and the anchor keeps its target=_blank; the only
       monitor is visibilitychange (registered once below) with the
       pendingVisibility latch.
       The blocker cycle is an open cycle too: the latch, the snapshot and the
       dirty state initialize here (no dialog — no new texts and no anchor
       interception). */
    hossamOebBeginCycle('',false);
    hossamOebState.pendingVisibility=true;
    return;
  }
  if(evt&&typeof evt.preventDefault==='function') evt.preventDefault();
  hossamOebBeginCycle(editorUrl);
  hossamOebMonitor(win);
}
/* The monitor: every 1000ms asks win.closed only — a 15-pulse ceiling. */
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
      hossamOebReturnSync(); /* the return latch makes it once per cycle */
      return;
    }
    if(hossamOebState.pulses>=15){
      /* Reaching 15 without a close: clearInterval with no interim sync — never
         an unbounded timer. */
      clearInterval(id);
      hossamOebState.interval=null;
    }
  },1000);
  hossamOebState.interval=id;
}
/* Return and sync: a latch per open cycle (once no matter how often the signal
   repeats, re-initialized in hossamOebBeginCycle). The decision is against the
   load baseline. */
function hossamOebReturnSync(){
  if(hossamOebState.synced) return;
  hossamOebState.synced=true;
  hossamOebDialogClose(); /* automatic close at sync */
  var changed=false;
  try{changed=hossamOebChangedVsBaseline();}catch(e){changed=false;}
  if(changed){
    /* Changed against the load baseline (including dirty-before-open): no
       reload — a manual-update toast (the i18n key from R41). */
    try{showToast(t('oeb_manual_update','Manual update started.'),'success');}catch(e1){}
    return;
  }
  /* Unchanged: one reload. */
  try{window.location.reload();}catch(e2){}
}
/* hossamOebDialog: open/close the template's <dialog> + the fallback link with
   the same editorUrl (the status text comes from the template/i18n — no new
   texts here). */
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
  /* Intercept the .js-oeb-open anchor click (R23) — the decision lives in
     hossamOebOpen. */
  document.addEventListener('click',function(e){
    try{
      var b=(e&&e.target&&e.target.closest)?e.target.closest('.js-oeb-open'):null;
      if(!b) return;
      hossamOebOpen(b,e);
    }catch(err){}
  });
  /* The .js-oeb-close button in the template's <dialog> (R23). */
  document.addEventListener('click',function(e){
    try{
      var c=(e&&e.target&&e.target.closest)?e.target.closest('.js-oeb-close'):null;
      if(!c) return;
      hossamOebDialogClose();
    }catch(err){}
  });
  /* The only monitor of the blocker path: visibilitychange registered once —
     the pendingVisibility latch identifies the active open cycle and is
     re-initialized on the next open. */
  document.addEventListener('visibilitychange',function(){
    try{
      if(!hossamOebState.pendingVisibility) return;
      if(document.visibilityState&&document.visibilityState!=='visible') return;
      hossamOebState.pendingVisibility=false;
      hossamOebReturnSync();
    }catch(e){}
  });
  /* Complete the load baseline for any field missing at init time (still
     loading). */
  document.addEventListener('DOMContentLoaded',function(){try{hossamOebCaptureBaseline();}catch(e){}});
}

/* ══════════════════════════════════════════════════════════════
   [W7] v1.1.3: the professional toolbar — the measured model structure
   (ten groups + More + History), STATIC above the canvas, built here in
   posts.js (no new module file — the single-file scope). The canvas is the
   official BlockCanvas iframe (an editable body), so the tools dispatch
   document.execCommand into it — the model's own mechanism — with a
   defensive guard per action (a refused command never dead-ends the
   interface). All labels flow through t() with the i18n channel (every
   fallback matches its registered literal verbatim). The bottom action bar
   (Publish Now / Save as Draft / Discard Changes) serves the EXISTING form
   contract only: it sets the status select of hossam-post-create-form and
   submits through hossamCreateArticle — no new endpoint, no new field.
   ══════════════════════════════════════════════════════════════ */
function hossamToolbarDoc(container){
  try{
    var frame=container.querySelector('[data-role="canvas"] iframe, .hossam-rich-root iframe');
    if(frame&&frame.contentDocument) return frame.contentDocument;
  }catch(e){}
  return null;
}
function hossamToolbarCmd(container,cmd,arg){
  try{
    var doc=hossamToolbarDoc(container);
    if(!doc||!doc.body) return false;
    try{doc.execCommand('styleWithCSS',false,true);}catch(e0){}
    return doc.execCommand(cmd,false,arg||null);
  }catch(e){return false;}
}
function hossamToolbarSelectedText(doc){
  try{ return doc.getSelection?String(doc.getSelection()):''; }catch(e){ return ''; }
}
function hossamToolbarPrompt(label){
  try{ return window.prompt(label)||''; }catch(e){ return ''; }
}
function hossamToolbarBuild(containerId,container,cfg){
  var doc=document;
  function el(tag,cls,text,title){
    var n=doc.createElement(tag);
    if(cls) n.className=cls;
    if(text!==undefined&&text!==null) n.textContent=text;
    if(title) n.title=t(title[0],title[1]);
    return n;
  }
  function btn(sym,title,onClick,cls){
    var b=el('button','hossam-pt-btn'+(cls?' '+cls:''),sym,title);
    b.type='button';
    b.addEventListener('click',function(ev){try{ev.preventDefault();onClick(b);}catch(e){}});
    return b;
  }
  function group(extra){
    return el('div','hossam-pt-group'+(extra?' hossam-pt-extra':''));
  }
  function divider(){ return el('div','hossam-pt-divider'); }
  function select(title,opts,onPick){
    var s=el('select','hossam-pt-select',undefined,title);
    for(var i=0;i<opts.length;i++){
      var o=doc.createElement('option');
      o.value=opts[i][0]; o.textContent=t(opts[i][1],opts[i][2]);
      s.appendChild(o);
    }
    s.addEventListener('change',function(){try{onPick(s.value);}catch(e){}});
    return s;
  }
  function menu(triggerSym,triggerTitle,menuTitleKey,menuTitleText,buildBody){
    var wrap=el('div','hossam-pt-dropdown');
    var trig=btn(triggerSym,triggerTitle,function(){toggle();});
    trig.appendChild(el('span','hossam-pt-caret','▾'));
    var m=el('div','hossam-pt-menu');
    m.appendChild(el('div','hossam-pt-menu-title',t(menuTitleKey,menuTitleText)));
    buildBody(m);
    m.style.display='none';
    function toggle(){
      try{ m.style.display=(m.style.display!=='none')?'none':'block'; }catch(e){}
    }
    wrap.appendChild(trig); wrap.appendChild(m);
    return wrap;
  }
  function swatchRow(m,colors,apply){
    var row=el('div','hossam-pt-swatch-row');
    for(var i=0;i<colors.length;i++){
      var sw=el('button','hossam-pt-swatch',undefined,[colors[i][1],colors[i][2]]);
      sw.type='button'; sw.style.background=colors[i][0];
      sw.addEventListener('click',function(c){return function(){try{apply(c);}catch(e){}};}(colors[i][0]));
      row.appendChild(sw);
    }
    m.appendChild(row);
  }
  function freeRow(m,pickId,apply){
    m.appendChild(el('div','hossam-pt-menu-title',t('w7FreePicker','Free color picker:')));
    var row=el('div','hossam-pt-free-row');
    var pick=el('input'); pick.type='color'; pick.id=pickId;
    var ap=el('button','btn btn-sm',t('aiApply','Apply'));
    ap.type='button';
    ap.addEventListener('click',function(){try{apply(pick.value);}catch(e){}});
    row.appendChild(pick); row.appendChild(ap);
    m.appendChild(row);
  }
  var root=el('div','hossam-pro-toolbar-container');
  root.id='hossam-pro-toolbar';
  /* G1 — heading level (H1 strictly excluded) */
  var g1=group();
  g1.appendChild(select(['w7HeadingLabel','Heading level'],[
    ['p','w7OptParagraph','Paragraph (Normal Text)'],
    ['h2','w7OptH2','Section Heading (H2)'],
    ['h3','w7OptH3','Subsection Heading (H3)'],
    ['h4','w7OptH4','Minor Detail (H4)']
  ],function(v){hossamToolbarCmd(container,'formatBlock',v==='p'?'p':v);}));
  root.appendChild(g1); root.appendChild(divider());
  /* G2 — font family (the font names travel the channel like every label) */
  var g2=group(true);
  g2.appendChild(select(['w7FontLabel','Font family'],[
    ['Inter, sans-serif','w7FontInter','Modern Sans (Inter)'],
    ["'Merriweather', serif",'w7FontSerif','Legal Serif (Merriweather)'],
    ["'Cairo', sans-serif",'w7FontCairo','Arabic Corporate (Cairo)'],
    ["'Cormorant Garamond', serif",'w7FontCormorant','Classical Serif (Cormorant)']
  ],function(v){hossamToolbarCmd(container,'fontName',v);}));
  root.appendChild(g2);
  /* G3 — font size (execCommand sizes 3-6) */
  var g3=group(true);
  g3.appendChild(select(['w7SizeLabel','Font size'],[
    ['3','w7SizeSmall','13px (Small)'],
    ['4','w7SizeBody','15px (Body)'],
    ['5','w7SizeMedium','18px (Medium)'],
    ['6','w7SizeLarge','22px (Large)']
  ],function(v){hossamToolbarCmd(container,'fontSize',v);}));
  root.appendChild(g3);
  /* G4 — line spacing (inline style on the enclosing block) */
  var g4=group(true);
  g4.appendChild(select(['w7LineHeightLabel','Line spacing'],[
    ['1.4','w7LhCompact','↕ 1.4 (Compact)'],
    ['1.6','w7LhNormal','↕ 1.6 (Normal)'],
    ['1.8','w7LhRelaxed','↕ 1.8 (Relaxed)'],
    ['2.0','w7LhDouble','↕ 2.0 (Double)']
  ],function(v){
    try{
      var d=hossamToolbarDoc(container);
      var s=d&&d.getSelection&&d.getSelection();
      var blk=s&&s.anchorNode?(s.anchorNode.nodeType===1?s.anchorNode:s.anchorNode.parentNode):null;
      while(blk&&blk.parentNode&&blk.parentNode!==d.body) blk=blk.parentNode;
      if(blk) blk.style.lineHeight=v;
    }catch(e){}
  }));
  root.appendChild(g4); root.appendChild(divider());
  /* G5 — inline formatting */
  var g5=group();
  g5.appendChild(btn('B',['w7Bold','Bold (Ctrl+B)'],function(){hossamToolbarCmd(container,'bold');}));
  g5.appendChild(btn('I',['w7Italic','Italic (Ctrl+I)'],function(){hossamToolbarCmd(container,'italic');}));
  g5.appendChild(btn('U',['w7Underline','Underline (Ctrl+U)'],function(){hossamToolbarCmd(container,'underline');}));
  g5.appendChild(btn('S',['w7Strike','Strikethrough'],function(){hossamToolbarCmd(container,'strikeThrough');}));
  g5.appendChild(btn('X₂',['w7Subscript','Subscript (X₂)'],function(){hossamToolbarCmd(container,'subscript');}));
  g5.appendChild(btn('X²',['w7Superscript','Superscript (X²)'],function(){hossamToolbarCmd(container,'superscript');}));
  g5.appendChild(btn('🧹',['w7ClearFormat','Clear formatting'],function(){hossamToolbarCmd(container,'removeFormat');}));
  root.appendChild(g5); root.appendChild(divider());
  /* G6 — color, highlight, case, grow/shrink */
  var g6=group();
  g6.appendChild(menu('A',['w7TextColor','Text color'],'w7TextColor','Text color',function(m){
    m.appendChild(el('div','hossam-pt-menu-title',t('w7Suggested','Suggested colors:')));
    swatchRow(m,[['#171825','w7ColorNavy','Navy'],['#C5A028','w7ColorGold','Gold'],['#C0392B','w7ColorRed','Red'],['#1A5FAB','w7ColorBlue','Blue'],['#2E7D4F','w7ColorGreen','Green'],['#5C5B6B','w7ColorGray','Gray'],['#000000','w7ColorBlack','Black']],function(c){hossamToolbarCmd(container,'foreColor',c);});
    freeRow(m,'hossamFreeTextColor',function(c){hossamToolbarCmd(container,'foreColor',c);});
  }));
  g6.appendChild(menu('🖍️',['w7Highlight','Highlight'],'w7Highlight','Highlight',function(m){
    m.appendChild(el('div','hossam-pt-menu-title',t('w7Suggested','Suggested colors:')));
    swatchRow(m,[['#FDF6B2','w7ColorYellow','Yellow'],['#E1EFFE','w7ColorBlue','Blue'],['#EAF5EE','w7ColorGreen','Green'],['#FDECEB','w7ColorRed','Red'],['#F2E7C0','w7ColorGoldPale','Gold Pale']],function(c){hossamToolbarCmd(container,'hiliteColor',c);});
    freeRow(m,'hossamFreeHighlight',function(c){hossamToolbarCmd(container,'hiliteColor',c);});
  }));
  g6.appendChild(menu('Aa',['w7Case','Change case'],'w7Case','Change case',function(m){
    var items=[['w7CaseSentence','Sentence case.','sentence'],['w7CaseLower','lowercase','lower'],['w7CaseUpper','UPPERCASE','upper'],['w7CaseTitle','Title Case','title']];
    for(var i=0;i<items.length;i++){
      (function(item){
        var mi=el('button','hossam-pt-menu-item',t(item[1],item[2]));
        mi.type='button';
        mi.addEventListener('click',function(){
          try{
            var d=hossamToolbarDoc(container); if(!d) return;
            var s=d.getSelection&&d.getSelection(); if(!s||s.isCollapsed) return;
            var txt=hossamToolbarSelectedText(d);
            var out=txt;
            if(item[3]==='sentence') out=txt.charAt(0).toUpperCase()+txt.slice(1).toLowerCase();
            else if(item[3]==='lower') out=txt.toLowerCase();
            else if(item[3]==='upper') out=txt.toUpperCase();
            else if(item[3]==='title') out=txt.replace(/\w\S*/g,function(w){return w.charAt(0).toUpperCase()+w.slice(1).toLowerCase();});
            hossamToolbarCmd(container,'insertText',out);
          }catch(e){}
        });
        m.appendChild(mi);
      })(items[i]);
    }
  }));
  g6.appendChild(btn('A^',['w7Grow','Grow font'],function(){hossamToolbarCmd(container,'fontSize',5);}));
  g6.appendChild(btn('A⌄',['w7Shrink','Shrink font'],function(){hossamToolbarCmd(container,'fontSize',3);}));
  root.appendChild(g6); root.appendChild(divider());
  /* G7 — alignment & directionality */
  var g7=group(true);
  g7.appendChild(btn('⫷',['w7AlignLeft','Align left'],function(){hossamToolbarCmd(container,'justifyLeft');}));
  g7.appendChild(btn('≡',['w7AlignCenter','Center align'],function(){hossamToolbarCmd(container,'justifyCenter');}));
  g7.appendChild(btn('⫸',['w7AlignRight','Align right'],function(){hossamToolbarCmd(container,'justifyRight');}));
  g7.appendChild(btn('≣',['w7Justify','Justify full'],function(){hossamToolbarCmd(container,'justifyFull');}));
  g7.appendChild(btn('⇄',['w7Direction','Switch paragraph direction'],function(){
    try{
      var d=hossamToolbarDoc(container);
      var s=d&&d.getSelection&&d.getSelection();
      var blk=s&&s.anchorNode?(s.anchorNode.nodeType===1?s.anchorNode:s.anchorNode.parentNode):null;
      while(blk&&blk.parentNode&&blk.parentNode!==d.body) blk=blk.parentNode;
      if(blk) blk.style.direction=(blk.style.direction==='rtl'?'ltr':'rtl');
    }catch(e){}
  }));
  root.appendChild(g7); root.appendChild(divider());
  /* G8 — lists & indent */
  var g8=group(true);
  g8.appendChild(btn('•≡',['w7BulletList','Bullet list'],function(){hossamToolbarCmd(container,'insertUnorderedList');}));
  g8.appendChild(btn('1≡',['w7NumberedList','Numbered list'],function(){hossamToolbarCmd(container,'insertOrderedList');}));
  g8.appendChild(btn('⫶≡',['w7Multilevel','Multilevel list'],function(){hossamToolbarCmd(container,'insertUnorderedList');}));
  g8.appendChild(btn('☑≡',['w7Checklist','Checklist'],function(){hossamToolbarCmd(container,'insertHTML','☑ ');}));
  g8.appendChild(btn('⇤',['w7Outdent','Decrease indent'],function(){hossamToolbarCmd(container,'outdent');}));
  g8.appendChild(btn('⇥',['w7Indent','Increase indent'],function(){hossamToolbarCmd(container,'indent');}));
  root.appendChild(g8); root.appendChild(divider());
  /* G9 — legal specialized elements (insert HTML into the editable body) */
  var g9=group(true);
  g9.appendChild(btn('❝',['w7LegalQuote','Legal statutory clause'],function(){hossamToolbarCmd(container,'formatBlock','blockquote');}));
  g9.appendChild(btn('⚠️',['w7Callout','Legal callout / notice box'],function(){hossamToolbarCmd(container,'insertHTML','<div style="border-inline-start:3px solid #C5A028;background:#FDF6B2;padding:8px 10px;">⚠️ </div>');}));
  g9.appendChild(btn('🔖',['w7Footnote','Insert footnote and citation'],function(){
    var txt=hossamToolbarPrompt(t('w7PromptFootnote','Footnote text:'));
    if(txt) hossamToolbarCmd(container,'insertHTML','<sup>['+String(txt).replace(/</g,'&lt;')+']</sup>');
  }));
  g9.appendChild(btn('⊞',['w7ComparisonTable','Insert legal comparison table'],function(){
    hossamToolbarCmd(container,'insertHTML','<table border="1" style="width:100%;border-collapse:collapse;"><tr><th style="text-align:start;"></th><th style="text-align:start;"></th></tr><tr><td></td><td></td></tr><tr><td></td><td></td></tr></table><p></p>');
  }));
  g9.appendChild(btn('📂',['w7Collapsible','Insert collapsible section'],function(){hossamToolbarCmd(container,'insertHTML','<details><summary></summary><p></p></details><p></p>');}));
  g9.appendChild(btn('🔗',['w7RefLink','Insert reference link'],function(){
    var url=hossamToolbarPrompt(t('w7PromptLink','Link URL:'));
    if(url) hossamToolbarCmd(container,'createLink',url);
  }));
  g9.appendChild(btn('📷',['w7Media','Insert image or document'],function(){
    try{
      var trigger=container.querySelector('[data-role="inserter"] button');
      if(trigger) trigger.click();
      else hossamToolbarCmd(container,'insertHTML','<img alt="" />');
    }catch(e){}
  }));
  g9.appendChild(btn('§',['w7LegalSymbol','Insert legal symbol'],function(){
    var sym=hossamToolbarPrompt(t('w7PromptSymbol','Legal symbol:'));
    if(sym) hossamToolbarCmd(container,'insertText',String(sym).slice(0,3));
  }));
  g9.appendChild(btn('—',['w7Divider','Divider line'],function(){hossamToolbarCmd(container,'insertHorizontalRule');}));
  root.appendChild(g9);
  /* More toggle — folds the hossam-pt-extra groups (tablet/phone) */
  var moreBtn=el('button','hossam-pt-btn hossam-pt-more-toggle');
  moreBtn.type='button';
  moreBtn.title=t('w7MoreTitle','More tools');
  moreBtn.appendChild(el('span',undefined,t('w7More','More')));
  moreBtn.appendChild(el('span','hossam-pt-caret','▾'));
  moreBtn.addEventListener('click',function(ev){
    try{ ev.preventDefault(); root.classList.toggle('hossam-pt-more-open'); }catch(e){}
  });
  root.appendChild(moreBtn);
  root.appendChild(divider());
  /* G10 — history & focus */
  var g10=group();
  g10.appendChild(btn('↶',['w7Undo','Undo (Ctrl+Z)'],function(){hossamToolbarCmd(container,'undo');}));
  g10.appendChild(btn('↷',['w7Redo','Redo (Ctrl+Y)'],function(){hossamToolbarCmd(container,'redo');}));
  g10.appendChild(btn('⛶',['w7Zen','Focus mode'],function(){
    try{
      var ws=container.closest?container.closest('#panel-write, #panel-edit-article'):null;
      if(ws) ws.classList.toggle('hossam-zen-focus');
    }catch(e){}
  }));
  root.appendChild(g10);
  return root;
}
/* [W7] the static bottom action bar (the model :2337-2349) — the write
   editor only; every button serves the EXISTING create-form contract. */
function hossamToolbarBuildActionBar(container){
  var doc=document;
  function mk(labelKey,labelText,cls,fn){
    var b=doc.createElement('button');
    b.type='button'; b.className=cls; b.textContent=t(labelKey,labelText);
    b.addEventListener('click',function(){try{fn();}catch(e){}});
    return b;
  }
  var bar=doc.createElement('div');
  bar.className='action-bottom-bar';
  bar.setAttribute('data-hossam-bottom-bar','');
  var left=doc.createElement('div'); left.className='hossam-bar-left';
  var canPublish=false;
  try{
    var st=doc.getElementById('hossam-new-status');
    canPublish=!!(st&&st.querySelector('option[value="publish"]'));
  }catch(e){}
  function submitWith(status){
    try{
      var st=doc.getElementById('hossam-new-status');
      if(st&&status) st.value=status;
      var f=doc.getElementById('hossam-post-create-form');
      if(f&&typeof hossamCreateArticle==='function') hossamCreateArticle(f);
    }catch(e){}
  }
  if(canPublish){
    left.appendChild(mk('w7PublishNow','Publish Now','btn btn-gold',function(){submitWith('publish');}));
  }
  left.appendChild(mk('w7SaveDraft','Save as Draft','btn btn-outline',function(){submitWith('draft');}));
  bar.appendChild(left);
  bar.appendChild(mk('w7Discard','Discard Changes','btn btn-outline hossam-bar-discard',function(){
    try{
      var ta=doc.getElementById('hossam-new-content');
      var rec=hossamRichState&&hossamRichState.inited?hossamRichState.inited['hossam-rich-editor-new']:null;
      var wp0=(typeof window!=='undefined')?window.wp:null;
      if(rec&&rec.api&&typeof rec.api.reparse==='function'){
        rec.api.reparse();
      }else if(ta&&wp0&&wp0.blocks&&typeof wp0.blocks.parse==='function'&&rec&&rec.api&&typeof rec.api.keep==='function'){
        rec.api.keep(wp0.blocks.parse(ta.value||''));
      }
    }catch(e){}
  }));
  return bar;
}
/* [W7] mount point: the pro toolbar prepends into the toolbar role (above
   the official context toolbar) and the bottom bar appends to the editor
   container (the write editor only). Called once per successful init. */
function hossamToolbarMount(containerId,container){
  try{
    if(!container||container.querySelector('.hossam-pro-toolbar-container')) return;
    var cfg=(typeof window!=='undefined'&&window.hossamRichEditor)||{};
    var bar=hossamToolbarBuild(containerId,container,cfg);
    var mount=container.querySelector('[data-role="toolbar"]');
    if(mount&&mount.insertBefore) mount.insertBefore(bar,mount.firstChild);
    else container.insertBefore(bar,container.firstChild);
    if(containerId==='hossam-rich-editor-new'){
      container.appendChild(hossamToolbarBuildActionBar(container));
    }
  }catch(e){}
}
