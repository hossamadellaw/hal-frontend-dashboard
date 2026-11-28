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

/* ══════════════════════════════════════════════════════════════
   [v1.1.14 item 1] the unified writing-rescue layer (Wave A contract):
   the write vault, the single dirty flag, the redirect governor and the
   black-box recorder. The restore banner, the official edit autosave and
   the pagehide recorder wiring live at the bottom of the file; the D1
   decision card lives in hossamShowPostSuccessCard; the second-save
   interception and the save-commit capture live in hossamCreateArticle.
   Rules honored: sessionStorage only (the localStorage upgrade stays an
   owner privacy decision), the vault is the write context (the edit panel
   rescues through /autosaves), English-only code, zero new editor-id
   references, zero counted-token additions.
   ══════════════════════════════════════════════════════════════ */
var HOSSAM_VAULT_PREFIX='hossamWriteVault';
var HOSSAM_VAULT_TTL=86400000; /* stamped; a vault older than 24h never revives */
var HOSSAM_VAULT_DEBOUNCE=1000;
/* [v1.1.15 item 1] the restore-apply deferral registry: while the editor is
   still mounting, the whole apply defers until the record resolves (the
   strict ready gate or a terminal latch) — the measured AR corruption fix.
   The ceiling guarantees a hung mount can never deadlock the restore. */
var hossamVaultApplyWaiters={};
var hossamVaultApplyTimeouts={};
var HOSSAM_VAULT_APPLY_WAIT_CEILING=20000;
/* [v1.1.15 item 4] the click-bridge text-type list hoists to module scope so
   the F-1 recovery and the deterministic-focus lifts read the same pinned
   list (the types whose last text field can receive the caret through
   selectBlock(-1) - structural, state-independent). */
var HOSSAM_CLICK_TEXT_TYPES=['core/paragraph','core/heading','core/quote','core/list','core/table','core/details'];
var HOSSAM_AUTOSAVE_DEBOUNCE=10000;
var HOSSAM_DIAG_KEY='hossamWriteDiagLog';
var hossamWriteVaultTimer=null;
var hossamAutosaveTimer=null;
var hossamWriteRedirectTimer=null;
var hossamWriteRedirectArmed=false;
var hossamWriteLastCreate=null;
var hossamDiagErrors=[];
window.hossamWriteDirty=false;
function hossamWriteLang(){
  try{return String((document.documentElement&&document.documentElement.lang)||'').toLowerCase().split('-')[0]||'x';}catch(e){return 'x';}
}
function hossamWriteVaultKey(){
  return HOSSAM_VAULT_PREFIX+':write:'+hossamWriteLang();
}
/* Every named form field is captured: title, serialized content, excerpt,
   status, featured image id, the checked categories and the tags[] mirror. */
function hossamWriteCollect(form){
  var out={};
  try{
    if(!form||!form.elements) return out;
    for(var i=0;i<form.elements.length;i++){
      var fld=form.elements[i];
      if(!fld||!fld.name) continue;
      if(fld.type==='button'||fld.type==='submit') continue;
      var n=fld.name;
      if(fld.type==='checkbox'){
        if(fld.checked){(out[n]=out[n]||[]).push(fld.value);}
        continue;
      }
      if(n==='categories[]'||n==='tags[]'){
        (out[n]=out[n]||[]).push(fld.value);
        continue;
      }
      out[n]=fld.value;
    }
  }catch(e){}
  return out;
}
/* Empty = nothing beyond the editor's seed paragraph and zero-value fields:
   an empty-seeded form never writes a vault. */
function hossamWriteVaultEmpty(payload){
  try{
    var f=payload&&payload.fields?payload.fields:{};
    for(var k in f){
      if(!Object.prototype.hasOwnProperty.call(f,k)) continue;
      var v=f[k];
      if(Object.prototype.toString.call(v)==='[object Array]'){
        for(var j=0;j<v.length;j++){if(String(v[j]).trim()!=='')return false;}
        continue;
      }
      if(k==='content'){
        var txt=String(v==null?'':v).replace(/<!--[\s\S]*?-->/g,'').replace(/<[^>]*>/g,'').trim();
        if(txt!=='') return false;
        continue;
      }
      /* [v1.1.14 - the audit note 4] the untouched default status is not
         content: a form with only the draft default writes no vault. */
      if(k==='status'&&String(v==null?'':v).trim()==='draft'){
        continue;
      }
      if(k==='thumbnail_id'){
        var tv=String(v==null?'':v).trim();
        if(tv!==''&&tv!=='0') return false;
        continue;
      }
      if(String(v==null?'':v).trim()!=='') return false;
    }
  }catch(e){return true;}
  return true;
}
function hossamWriteVaultSave(form){
  try{
    if(!form||form.id!=='hossam-post-create-form') return;
    if(!window.hossamWriteDirty) return;
    /* [v1.1.15 item 1c] the capture-suppression window: while a restore apply
       is pending (or a failed verify is latched), the debounced capture never
       re-captures a corrupted intermediate state. */
    if(form.getAttribute('data-hossam-apply-pending')==='1') return;
    /* [v1.1.14 item 1 - the advisory correction 1] the serialized block state
       is the truth in official mode (the textarea holds only the seed until a
       save syncs it) - the vault capture syncs the editor into the textarea
       before collecting, so the debounced rescue never stores a stale body. */
    if(typeof hossamSyncRichEditorToTextarea==='function'){try{hossamSyncRichEditorToTextarea(form);}catch(eVS){}}
    var payload={v:1,ctx:'write',lang:hossamWriteLang(),ts:Date.now(),bound:(form.getAttribute('data-hossam-bound-post')||''),fields:hossamWriteCollect(form)};
    if(hossamWriteVaultEmpty(payload)) return;
    sessionStorage.setItem(hossamWriteVaultKey(),JSON.stringify(payload));
  }catch(e){}
}
function hossamWriteVaultClear(form){
  try{
    if(form&&form.id!=='hossam-post-create-form') return;
    sessionStorage.removeItem(hossamWriteVaultKey());
  }catch(e){}
}
/* [v1.1.14 item 2] the redirect governor: the post-save and translation
   timers are cancelled by the first user input after the save (the intent
   the pre-existing code comment documented) and the D1 decision card
   governs what happens next. */
function hossamWriteCancelRedirect(){
  try{
    if(hossamWriteRedirectTimer){clearTimeout(hossamWriteRedirectTimer);hossamWriteRedirectTimer=null;}
    hossamWriteRedirectArmed=false;
  }catch(e){}
}
function hossamWriteClearDirty(){
  try{
    window.hossamWriteDirty=false;
    hossamWriteCancelRedirect();
  }catch(e){}
}
/* One cadence for both rescue channels: the debounced write-vault capture
   (create form) and the debounced official edit autosave. Each channel
   self-guards to its own form. */
function hossamWriteMarkDirty(){
  try{
    window.hossamWriteDirty=true;
    window.hossamDiagLastInput=Date.now();
    hossamWriteCancelRedirect();
    if(!hossamWriteVaultTimer){
      hossamWriteVaultTimer=setTimeout(function(){hossamWriteVaultTimer=null;try{hossamWriteVaultSave(document.getElementById('hossam-post-create-form'));}catch(e1){}},HOSSAM_VAULT_DEBOUNCE);
    }
    if(!hossamAutosaveTimer){
      hossamAutosaveTimer=setTimeout(function(){hossamAutosaveTimer=null;try{hossamWriteAutosave();}catch(e2){}},HOSSAM_AUTOSAVE_DEBOUNCE);
    }
  }catch(e){}
}
/* Recorder ring: the last five errors (console + window + rejection). */
function hossamDiagLogError(text){
  try{
    hossamDiagErrors.push({ts:Date.now(),text:String(text).slice(0,300)});
    if(hossamDiagErrors.length>5) hossamDiagErrors=hossamDiagErrors.slice(-5);
  }catch(e){}
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
  /* [v1.1.5 item 16] the single tags[] key opens in the save contract — the
     categories[] append pattern verbatim: the hidden mirror inside the Tags
     card is the carrier, and the empty marker (a single empty value when the
     chip set is empty) keeps "removed the last tag" an explicit save instead
     of falling back to the keep-contract. Zero new endpoint. */
  var tagBoxes = (typeof form.querySelectorAll === 'function')
    ? form.querySelectorAll('input[name="tags[]"]') : [];
  tagBoxes.forEach(box=>fd.append("tags[]",box?box.value:""));
  if(excerpt){fd.append("excerpt",excerpt.value);}
  /* [B9-05] + [B9-06] P1: send the value when it is not empty — zero '0'
     is sent explicitly for removal (no '0' exclusion, and never -1). */
  if(thumb&&thumb.value!==''){fd.append("thumbnail_id",thumb.value);}
  /* [v1.1.14 item 1+2] the save-commit capture: the vault snapshots every
     field right before the request, so a failed save still rescues. The D1
     guard: a bound form routes to the update path; a saved-but-unbound form
     is intercepted with the same decision card — a second create save is
     never silently executed (the duplicate-draft window stays closed). */
  try{hossamWriteVaultSave(form);}catch(eVault){}
  try{
    if(form.getAttribute('data-hossam-bound-post')&&typeof hossamUpdateArticle==='function'){return hossamUpdateArticle(form);}
    if(form.getAttribute('data-hossam-saved-post')&&hossamWriteLastCreate){
      hossamShowPostSuccessCard(form,hossamWriteLastCreate,hossamPostToastLabel(status?status.value:'draft'));
      return true;
    }
  }catch(eD1){}
  let netError=false,resp=null,d=null;
  try{
    resp=await fetch(hossamAjax.ajaxurl,{method:"POST",body:fd});
    try{d=await resp.json();}catch(e){d=null;}
  }catch(e){netError=true;}
  if(d&&d.success){
    showToast(hossamPostToastLabel(status?status.value:'draft'),'success');
    /* [B9-06] P1: a success card with clickable links is shown before any
       automatic redirect; the create transition (edit_url) is delayed until
       user interaction (a clickable link) or a short timeout.
       [v1.1.14 item 2 — the governor] the documented intent above is now
       enforced: the timer below is cancelled by the first keydown/mousedown/
       form input after the save and by both D1 card buttons, and the D1
       decision card governs what happens next (owner-adopted default). */
    if(form.id==='hossam-post-create-form'){hossamWriteLastCreate=d;}
    try{form.setAttribute('data-hossam-last-saved',String(Date.now()));}catch(eStamp){}
    hossamWriteClearDirty();
    hossamWriteVaultClear(form);
    hossamShowPostSuccessCard(form,d,hossamPostToastLabel(status?status.value:'draft'));
    if(d.data&&d.data.edit_url){
      const editUrl=d.data.edit_url;
      hossamWriteRedirectArmed=true;
      hossamWriteRedirectTimer=setTimeout(function(){hossamWriteRedirectTimer=null;hossamWriteRedirectArmed=false;window.location.href=editUrl;},6000);
    }
    else if(!hossamPostSuccessLinks(d).length){/* v1.1.14 item 2: unreachable on creation — edit_url is always returned by hossam_create_article; kept for the response-less edge only. */location.reload();}
    return true;
  }
  showToast((d&&d.data&&d.data.message)||t('networkError','Network error'),'error');
  if(hossamIsTechnicalFailure(netError,resp,d)){hossamSetEditorState(document.getElementById('hossam-rich-editor-new'),'error');hossamRevealFallback('fa-fallback-write');}
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
  /* [v1.1.5 item 16] the tags[] append in the update path — same contract as
     the create path (the categories[] pattern verbatim). */
  var tagBoxesU = (typeof form.querySelectorAll === 'function')
    ? form.querySelectorAll('input[name="tags[]"]') : [];
  tagBoxesU.forEach(box=>fd.append("tags[]",box?box.value:""));
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
    /* [v1.1.14 item 1 - the audit corrections] a successful update feeds the
       rescue layer on both paths (the edit form and the bound create form):
       the dirty flag clears, the saved marker stamps, and the write vault
       clears (a no-op on the edit form by its guard). */
    try{form.setAttribute('data-hossam-last-saved',String(Date.now()));}catch(eStamp2){}
    hossamWriteClearDirty();
    hossamWriteVaultClear(form);
    /* [B9-06] P1: the update shows the success card with its links instead of
       an instant reload — the links stay usable with no automatic redirect. */
    hossamShowPostSuccessCard(form,d,t('updated','Updated'));
    /* [W8] v1.1.3 (OP-7): the single save point - the article update chains
       the SEO save when the edit panel carries the SEO form (both server
       contracts verbatim: hossam_update_article already returned success,
       then hossam_save_seo runs with its own nonce and rate limit;
       hossamSaveSeo remains independently callable). */
    try{
      var seoForm=document.getElementById('hossam-edit-seo-form');
      if(seoForm&&typeof hossamSaveSeo==='function'){ await hossamSaveSeo(seoForm); }
    }catch(e7){}
    return true;
  }
  showToast((d&&d.data&&d.data.message)||t('networkError','Network error'),'error');
  if(hossamIsTechnicalFailure(netError,resp,d)){hossamSetEditorState(document.getElementById('hossam-rich-editor-edit'),'error');hossamRevealFallback('fa-fallback-edit');}
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
  try{hossamSetEditorState(document.getElementById(form.id==='hossam-post-edit-form'?'hossam-rich-editor-edit':'hossam-rich-editor-new'),'saved');}catch(eS){}
  /* [W8] v1.1.4 item 5: the two write-column availability cards flip through
     the existing saved-state wave — no new state machine, no new endpoint;
     the declared pending-save state ends at the first save. */
  try{
    if(form.id==='hossam-post-create-form'){
      ['hossam-translations-holder','hossam-seo-holder'].forEach(function(hcId){
        var hcEl=document.getElementById(hcId);
        if(hcEl&&hcEl.setAttribute) hcEl.setAttribute('data-hossam-card-state','saved');
      });
    }
  }catch(eCard){}
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
  /* [v1.1.14 item 2 — the D1 decision card (owner-adopted default)] the
     create form has no post_id: the card carries the two decision buttons.
     Continue writing binds the form to the created post (the hidden post_id
     input + the bound marker) so further saves update instead of
     duplicating; Open in editor navigates to the edit panel now. Both cancel
     the pending redirect. */
  try{
    if(form.id==='hossam-post-create-form'&&d&&d.data&&d.data.edit_url){
      form.setAttribute('data-hossam-saved-post',String(d.data.post_id||''));
      var d1Wrap=document.createElement('div');
      d1Wrap.className='hossam-d1-actions';
      var continueBtn=document.createElement('button');
      continueBtn.type='button';
      continueBtn.className='btn btn-sm btn-outline';
      continueBtn.style.marginInlineEnd='6px';
      continueBtn.textContent=t('d1Continue','Continue writing');
      continueBtn.addEventListener('click',function(){
        try{
          hossamWriteCancelRedirect();
          var pid=String((d.data&&d.data.post_id)||'');
          if(!pid){try{var u1=new URL(d.data.edit_url,location.href);pid=u1.searchParams.get('post_id')||'';}catch(eU1){pid='';}}
          if(pid){
            var hidden=form.querySelector('[name="post_id"]');
            if(!hidden){hidden=document.createElement('input');hidden.type='hidden';hidden.name='post_id';form.appendChild(hidden);}
            hidden.value=pid;
            form.setAttribute('data-hossam-bound-post',pid);
          }
          var mine=form.querySelector('[data-hossam-vault-card="d1"]');
          if(mine&&mine.parentNode) mine.parentNode.removeChild(mine);
        }catch(eD1b){}
      });
      var openBtn=document.createElement('button');
      openBtn.type='button';
      openBtn.className='btn btn-sm';
      openBtn.style.marginInlineEnd='6px';
      openBtn.textContent=t('d1OpenEditor','Open in editor');
      openBtn.addEventListener('click',function(){try{hossamWriteCancelRedirect();window.location.href=d.data.edit_url;}catch(eD1c){}});
      d1Wrap.appendChild(continueBtn);
      d1Wrap.appendChild(openBtn);
      card.setAttribute('data-hossam-vault-card','d1');
      card.appendChild(d1Wrap);
    }
  }catch(eD1a){}
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
   rejection/invalid input = toast only.
   [v1.1.5 item 21-3] the SEO group is now a non-form container with the same
   id inside the wrapping edit form (the nested-form constraint); its fields
   carry the distinguished internal names (seo_post_id/seo_title/
   seo_focus_keyword/seo_description/seo_robots[]) so the outer
   hossamUpdateArticle reads never collide — and the wire keys appended below
   are byte-identical to the shipped contract (title/focus_keyword/
   description/robots[]/post_id): the hossam_save_seo endpoint contract is
   untouched. */
async function hossamSaveSeo(form){
  if(!window.hossamAjax || !form) return false;
  const fd=new FormData();
  fd.append("action","hossam_save_seo");
  fd.append("nonce",hossamAjax.nonce);
  const pid=form.querySelector('[name="seo_post_id"]'),
        title=form.querySelector('[name="seo_title"]'),
        kw=form.querySelector('[name="seo_focus_keyword"]'),
        desc=form.querySelector('[name="seo_description"]');
  fd.append("post_id",pid?pid.value:"0");
  if(title) fd.append("title",title.value);
  if(kw) fd.append("focus_keyword",kw.value);
  if(desc) fd.append("description",desc.value);
  var seoRobots=(typeof form.querySelectorAll==='function')
    ? form.querySelectorAll('input[name="seo_robots[]"]:checked') : [];
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

/* ══════════════════════════════════════════════════════════════
   [v1.1.5 item 16] the live Tags card — one behavior set for both contexts
   (the same builder renders the write and the edit cards). State lives in
   the card DOM only: the chips + the hidden tags[] mirror inside the form;
   the save path reads the mirror exactly like the categories checkboxes.
   DOM APIs only (createElement/textContent — zero raw-HTML writes). Every
   control is type="button" (S-11): nothing inside the save form submits
   implicitly.
   S-10: the client mirrors the server's 15-tag cap with an explicit toast —
   the server gate stays the only authority.
   ══════════════════════════════════════════════════════════════ */
var hossamTagsCap=15; /* the server cap (S-10) mirrored for the explicit client message */
function hossamTagsCardOf(node){
  try{ return (node&&node.closest)?node.closest('[data-hossam-side-card="tags"]'):null; }catch(e){ return null; }
}
function hossamTagsChipsBox(card){
  try{ return card?card.querySelector('[data-hossam-tags-chips]'):null; }catch(e){ return null; }
}
function hossamTagsMirrorBox(card){
  try{ return card?card.querySelector('[data-hossam-tags-values]'):null; }catch(e){ return null; }
}
function hossamTagChipName(chip){
  try{ return chip&&chip.getAttribute?(chip.getAttribute('data-tag')||''):''; }catch(e){ return ''; }
}
/* Rebuild the hidden mirror from the live chips + keep the empty hint and
   the suggestion selected-state in sync. The empty set keeps ONE empty
   marker input so "removed the last tag" reaches the server as an explicit
   empty set (the removal acceptance) instead of the keep-contract. */
function hossamTagsSync(card){
  try{
    if(!card) return;
    var chipsBox=hossamTagsChipsBox(card), mirror=hossamTagsMirrorBox(card);
    if(!chipsBox||!mirror) return;
    var chips=chipsBox.querySelectorAll('.hossam-tag-chip');
    while(mirror.firstChild) mirror.removeChild(mirror.firstChild);
    for(var i=0;i<chips.length;i++){
      var input=document.createElement('input');
      input.type='hidden';
      input.name='tags[]';
      input.value=hossamTagChipName(chips[i]);
      mirror.appendChild(input);
    }
    if(!chips.length){
      var marker=document.createElement('input');
      marker.type='hidden'; marker.name='tags[]'; marker.value='';
      mirror.appendChild(marker);
    }
    var empty=card.querySelector('[data-hossam-tags-empty]');
    if(empty){ if(chips.length){empty.setAttribute('hidden','');}else{empty.removeAttribute('hidden');} }
    var suggestBox=card.querySelector('[data-hossam-tags-suggestions]');
    if(suggestBox){
      var sugg=suggestBox.querySelectorAll('.hossam-tag-suggest');
      for(var s=0;s<sugg.length;s++){
        var name=hossamTagChipName(sugg[s]);
        var selected=false;
        for(var c=0;c<chips.length;c++){ if(hossamTagChipName(chips[c])===name){selected=true;break;} }
        if(selected){ sugg[s].classList.add('is-selected'); } else { sugg[s].classList.remove('is-selected'); }
      }
    }
  }catch(e){}
}
function hossamTagsAdd(name, card){
  try{
    name=String(name||'').replace(/^\s+|\s+$/g,'');
    if(!card||!name) return false;
    var chipsBox=hossamTagsChipsBox(card);
    if(!chipsBox) return false;
    var chips=chipsBox.querySelectorAll('.hossam-tag-chip');
    for(var i=0;i<chips.length;i++){ if(hossamTagChipName(chips[i])===name){ return false; } } /* dedupe — the chip is already on the article */
    if(chips.length>=hossamTagsCap){
      showToast(t('tagsCap','Up to 15 tags per article.'),'error');
      return false;
    }
    var chip=document.createElement('span');
    chip.className='hossam-tag-chip';
    chip.setAttribute('data-tag',name);
    chip.textContent=name;
    var x=document.createElement('button');
    x.type='button';
    x.className='hossam-tag-chip-remove';
    x.setAttribute('aria-label',t('tagRemoveNamed','Remove tag')+' '+name);
    x.textContent='×';
    chip.appendChild(x);
    chipsBox.appendChild(chip);
    hossamTagsSync(card);
    return true;
  }catch(e){ return false; }
}
/* One delegation pair for both contexts: suggestion chips add their tag to
   the article set; chip remove buttons remove it. Both walk up to the card
   through the shared data-hossam-side-card="tags" marker. */
document.addEventListener('click',function(e){
  try{
    var sug=(e.target&&e.target.closest)?e.target.closest('[data-tag-suggest]'):null;
    if(sug){ e.preventDefault(); hossamTagsAdd(sug.getAttribute('data-tag-suggest'),hossamTagsCardOf(sug)); return; }
    var rm=(e.target&&e.target.closest)?e.target.closest('.hossam-tag-chip-remove'):null;
    if(rm){
      e.preventDefault();
      var chip=rm.closest('.hossam-tag-chip'), card=hossamTagsCardOf(rm);
      if(chip&&chip.parentNode) chip.parentNode.removeChild(chip);
      hossamTagsSync(card);
      return;
    }
    var add=(e.target&&e.target.closest)?e.target.closest('[data-hossam-tags-add]'):null;
    if(add){
      e.preventDefault();
      var card2=hossamTagsCardOf(add);
      var row=add.closest?add.closest('.hossam-tags-add-row'):null;
      var input=row?row.querySelector('input[type="text"]'):null;
      if(input){ hossamTagsAdd(input.value,card2); if(input.value){input.value='';} }
    }
  }catch(e){}
});
/* S-9: the suggestion scope narrows live to the checked categories — the
   read lists travel on each suggestion chip (data-tag-cats) rendered
   server-side; with no categories checked the full rendered scope shows. */
document.addEventListener('change',function(e){
  try{
    var box=e.target;
    if(!box||!box.name||box.name!=='categories[]') return;
    var form=box.closest?box.closest('form'):null;
    if(!form) return;
    var checked={};
    var boxes=form.querySelectorAll?form.querySelectorAll('input[name="categories[]"]:checked'):[];
    for(var i=0;i<boxes.length;i++){ checked[boxes[i].value]=true; }
    var anyChecked=Object.keys(checked).length>0;
    var suggestBoxes=form.querySelectorAll?form.querySelectorAll('[data-hossam-tags-suggestions]'):[];
    for(var s=0;s<suggestBoxes.length;s++){
      var chips=suggestBoxes[s].querySelectorAll('.hossam-tag-suggest');
      for(var c=0;c<chips.length;c++){
        var cats=(chips[c].getAttribute('data-tag-cats')||'').split(',');
        var hit=false;
        for(var k=0;k<cats.length;k++){ if(cats[k]&&checked[cats[k]]){hit=true;break;} }
        chips[c].hidden=anyChecked&&!hit;
      }
    }
  }catch(e){}
});

/* ══════════════════════════════════════════════════════════════
   [v1.1.5 item 18-1] the live SEO fields — the counters and the suggestion
   list reflect the article while it is being edited (the guidance-only
   rules are the server's own: empty → write-it, 50-60 title, 120-160
   description, noindex note). Display guidance only: Rank Math stays the
   only score source — the score element updates from the hossam_save_seo
   response exclusively (the chain above), zero client-side score
   calculation anywhere.
   ══════════════════════════════════════════════════════════════ */
function hossamSeoLiveCount(v){
  try{ return (typeof Array.from==='function')?Array.from(String(v)).length:String(v).length; }catch(e){ return String(v).length; }
}
function hossamSeoLiveUpdate(){
  try{
    var box=document.getElementById('hossam-edit-seo-form');
    if(!box||!box.querySelector) return;
    var kw=box.querySelector('[name="seo_focus_keyword"]'),
        st=box.querySelector('[name="seo_title"]'),
        de=box.querySelector('[name="seo_description"]');
    var kwV=kw?String(kw.value||''):'', stV=st?String(st.value||''):'', deV=de?String(de.value||''):'';
    var stLen=hossamSeoLiveCount(stV), deLen=hossamSeoLiveCount(deV);
    if(st){
      var stGroup=st.closest?st.closest('.form-group'):null;
      var stCounter=stGroup?stGroup.querySelector('.hossam-seo-counter'):null;
      if(stCounter) stCounter.textContent=stLen+' / 60';
    }
    if(de){
      var deGroup=de.closest?de.closest('.form-group'):null;
      var deCounter=deGroup?deGroup.querySelector('.hossam-seo-counter'):null;
      if(deCounter) deCounter.textContent=deLen+' / 160';
    }
    var noindex=false;
    var robots=box.querySelectorAll?box.querySelectorAll('input[name="seo_robots[]"]:checked'):[];
    for(var r=0;r<robots.length;r++){ if(String(robots[r].value||'').toLowerCase()==='noindex'){noindex=true;break;} }
    var items=[];
    if(''===kwV.replace(/^\s+|\s+$/g,'')){ items.push(t('seoSugKw','Set a focus keyword so the score has a target.')); }
    if(''===stV.replace(/^\s+|\s+$/g,'')){ items.push(t('seoSugTitle','Write the SEO title — it is the headline search results show.')); }
    else if(stLen<50||stLen>60){ items.push(t('seoSugTitleLen','Keep the SEO title between 50 and 60 characters.')); }
    if(''===deV.replace(/^\s+|\s+$/g,'')){ items.push(t('seoSugDesc','Write the meta description — it is the summary line under the title.')); }
    else if(deLen<120||deLen>160){ items.push(t('seoSugDescLen','Keep the meta description between 120 and 160 characters.')); }
    if(noindex){ items.push(t('seoSugNoindex','This page is set to noindex — it will not appear in search results.')); }
    var list=box.querySelector('.hossam-seo-suggestions');
    if(list){
      while(list.firstChild) list.removeChild(list.firstChild);
      for(var i=0;i<items.length;i++){
        var li=document.createElement('li');
        li.textContent=items[i];
        list.appendChild(li);
      }
      if(items.length){ list.removeAttribute('hidden'); } else { list.setAttribute('hidden',''); }
    }
  }catch(e){}
}
try{
  var hossamSeoLiveBind=function(){
    var ids=['hossam-edit-seo-kw','hossam-edit-seo-title','hossam-edit-seo-desc'];
    for(var i=0;i<ids.length;i++){
      var f=document.getElementById(ids[i]);
      if(f&&!f.getAttribute('data-hossam-seo-live')){
        f.setAttribute('data-hossam-seo-live','1');
        f.addEventListener('input',hossamSeoLiveUpdate);
        f.addEventListener('change',hossamSeoLiveUpdate);
      }
    }
    var box=document.getElementById('hossam-edit-seo-form');
    if(box&&box.addEventListener&&!box.getAttribute('data-hossam-seo-live-box')){
      box.setAttribute('data-hossam-seo-live-box','1');
      box.addEventListener('change',function(ev){
        try{ if(ev.target&&ev.target.name==='seo_robots[]') hossamSeoLiveUpdate(); }catch(e){}
      });
    }
  };
  if(typeof document!=='undefined'&&document&&typeof document.addEventListener==='function'){
    if(document.readyState==='loading'){ document.addEventListener('DOMContentLoaded',function(){try{hossamSeoLiveBind();hossamSeoLiveUpdate();}catch(e){}}); }
    else{ hossamSeoLiveBind(); hossamSeoLiveUpdate(); }
  }
}catch(eSeoLive){}

/* [v1.1.5 item 18-4 — D-OWNER-4=A] the AI card head icon is a real button
   (type="button", in-flow): activating it focuses the card's actions row —
   the first js-ai-run button — a focus/accessibility link only (zero new
   action, zero endpoint, zero fixed/sticky). */
document.addEventListener('click',function(e){
  try{
    var ico=(e.target&&e.target.closest)?e.target.closest('.hossam-write-card-ico-btn'):null;
    if(!ico) return;
    var card=ico.closest?ico.closest('.card'):null;
    var first=card?card.querySelector('.js-ai-run'):null;
    if(first&&typeof first.focus==='function') first.focus();
  }catch(e){}
});

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
      /* [v1.1.14 item 2] the same governor treatment: both 4-second hops are
         cancelled by the first user input after the result shows. */
      if(d.data&&d.data.edit_url){
        const editUrl=d.data.edit_url;
        hossamWriteRedirectArmed=true;
        hossamWriteRedirectTimer=setTimeout(function(){hossamWriteRedirectTimer=null;hossamWriteRedirectArmed=false;window.location.href=editUrl;},4000);
      }else if(newId){
        const u=new URL(location.href);
        u.searchParams.set('panel','edit-article');
        u.searchParams.set('post_id',newId);
        hossamWriteRedirectArmed=true;
        hossamWriteRedirectTimer=setTimeout(function(){hossamWriteRedirectTimer=null;hossamWriteRedirectArmed=false;window.location.href=u.toString();},4000);
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
/* [v1.1.7 item 1] Pure seed helper: an empty parsed state on an empty
   field yields exactly one empty paragraph; any parsed state on non-empty
   content passes through untouched; a missing factory degrades to today's
   behavior (zero blocks — never throws). */
function hossamRichSeedBlocks(parsed, html, createBlock){
  var blocks=(parsed&&parsed.length)?parsed:[];
  if(blocks.length||String(html==null?'':html).trim()!=='') return blocks;
  var nb=(typeof createBlock==='function')?createBlock('core/paragraph',{}):null;
  return nb?[nb]:[];
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
/* [W8] v1.1.3 (Wireframes-a, the correction): the editor surface state
   rides a data attribute in DOM only - the one helper, four call sites
   (ready=empty, input=writing, the two save-failure branches=error,
   the success card=saved); DOM APIs only - setAttribute, never raw-HTML
   injection. */
function hossamSetEditorState(container,state){
  try{if(container&&container.setAttribute)container.setAttribute('data-hossam-editor-state',state);}catch(e){}
}
/* [v1.1.15 item 4 - focus lift 1] the programmatic caret at readiness:
   gated three-way (write context x the single empty seeded paragraph x no
   existing selection and no canvas interaction yet) plus the document guard
   (a focused form field never gets its caret stolen - it never steals the
   caret from the title mid-typing). Runs through the deposited sub-registry
   only. */
function hossamRichFocusAtReady(containerId){
  try{
    var rec=hossamRichState.inited[containerId];
    if(!rec||rec.phase!=='ready'||!rec.wpRegistry||!rec.blocks) return;
    /* write context only: the record's textarea id decides (no new editor-id
       references - the B15S-7 count stays pinned). */
    if(rec.textareaId!=='hossam-new-content') return;
    if(rec.canvasTouched) return;
    if(!rec.blocks.length||rec.blocks.length>1) return;
    var b0=rec.blocks[0];
    if(!b0||b0.name!=='core/paragraph') return;
    var c0=b0.attributes&&b0.attributes.content;
    var txt0=c0&&(typeof c0.toHTMLString==='function')?c0.toHTMLString():String(c0==null?'':c0);
    if(String(txt0).replace(/<!--[\s\S]*?-->/g,'').replace(/<[^>]*>/g,'').trim()!=='') return;
    var store=rec.wpRegistry.select('core/block-editor');
    if(store.getSelectedBlockClientId()) return;
    var doc0=(typeof document!=='undefined')?document:null;
    if(doc0&&doc0.activeElement&&doc0.activeElement!==doc0.body){
      var tn=doc0.activeElement.tagName;
      if(tn==='INPUT'||tn==='TEXTAREA'||tn==='SELECT') return;
    }
    if(b0.clientId) rec.wpRegistry.dispatch('core/block-editor').selectionChange(b0.clientId,'content',0,0);
  }catch(e){}
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
    /* [v1.1.7 item 1] seed one empty paragraph on an empty field — the hidden
       appender (item 20 styles) leaves no clickable path to the first block,
       so a zero-block mount is a dead canvas; the seed restores click-and-type. */
    initialBlocks=hossamRichSeedBlocks(initialBlocks, initialHtml, blocksPkg.createBlock);
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
    /* [W8] v1.1.3 (OP-2): the extended-block gate - administrators (the
       server-side adminExtend flag) keep the full core set; every other
       role writes within the eight-block set (TS-01/TS-04: paragraph,
       heading, image, list, quote, table, details, separator - display
       restriction only; existing content with hidden blocks renders and
       edits in place untouched (TS-06/OP-3), and the server-side kses
       valve governs every save regardless). */
    if(true===allowedTypes&&!(cfg&&cfg.adminExtend)){
      allowedTypes=['core/paragraph','core/heading','core/image','core/list','core/quote','core/table','core/details','core/separator'];
    }
    var settings={
      canUpload:canUpload,
      mediaUpload:function(o){hossamRichMediaUpload(wp0,cfg,o);},
      isRTL:!!cfg.isRtl,
      allowedBlockTypes:allowedTypes,
      /* [v1.1.11 item 2 — consensus] the floating contextual toolbar never
         mounts: the fixed HAL toolbar is the only formatting surface (the
         owner's unification decision). The official switch prevents the
         mount instead of hiding it after the fact; the contentCss hide rule
         appended below stays as defense-in-depth. */
      hasFixedToolbar:true,
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
    try{container.addEventListener('input',function(){hossamSetEditorState(container,'writing');},true);}catch(pe1){}
    /* Content styles are passed to the iframe through the official BlockCanvas
       interface (styles) — with RTL from the HAL settings.
       v1.1.5 item 20: this styles string is the only legitimate channel into
       the BlockCanvas iframe — the default appender and the empty-block
       inserter families are hidden inside the canvas so the writing body
       stays clean and the toolbar button remains the only add path (the
       pre-save serialize reads block state, never this DOM). */
    /* v1.1.8 item 1: the default appender leaves the hide list and is restyled
       as an invisible full-height click layer (260px, transparent text,
       cursor:text) — clicking anywhere in the blank canvas creates a paragraph
       through Gutenberg's native insertDefaultBlock path. Nested block-list
       appenders stay hidden, the UA paragraph margins on __content are zeroed
       (the isolated iframe has no content.scss), and the appender's internal
       quick-inserter "+" is hidden (item 20 stays chrome-free).
       [v1.1.9 item 1 — consensus correction 5] the appender layer alone can
       never mount while blocks exist (Gutenberg renders it only on a zero
       list, and the seed/reseed plus the store's own default-block refill
       keep the list non-empty), so the blank-canvas click path is the in-tree
       bridge component below. The insertion point therefore leaves the hide
       list — the native between-blocks affordance returns; every other
       hide-list family and the appender rules stay byte-identical. */
    var contentCss='html{direction:'+(cfg.isRtl?'rtl':'ltr')+';}body{font-family:inherit;line-height:1.7;max-width:100%;}img{max-width:100%;height:auto;}table{border-collapse:collapse;width:100%;}.block-editor-block-list__block .block-list-appender,.block-editor-block-list__empty-block-inserter{display:none!important;}.block-list-appender{display:block!important;margin:0;padding:0;}.block-editor-default-block-appender{display:block!important;height:260px;margin:0;padding:0;cursor:text;}.block-editor-default-block-appender .block-editor-default-block-appender__content{height:260px;margin:0;padding:0;color:transparent!important;cursor:text;}.block-editor-default-block-appender .block-editor-inserter{display:none!important;}.block-editor-block-toolbar,.block-editor-block-contextual-toolbar{display:none!important;}';
    /* [v1.1.11 item 2 — consensus] the trailing rule is the defense-in-depth
       hide for the two contextual toolbar selectors only (never the wider
       components-toolbar family — the inserter popover and the link UI live
       there). It is appended AFTER the direction rule, which stays
       byte-identical; settings.hasFixedToolbar already prevents the mount. */
    /* [v1.1.9 item 1 — consensus] the blank-canvas click bridge: a component
       INSIDE the provider tree (a BlockCanvas child, so it renders inside the
       iframe document) — wp.data.useRegistry() here resolves the provider's
       sub-registry, the only registry carrying this editor's block store.
       A click on the empty canvas surface dispatches the official
       insertDefaultBlock action on that sub-registry; the store change flows
       back through the provider's onChange/onInput into keep() (the existing
       sync path) and the store auto-selects the new paragraph. The same
       effect deposits the sub-registry on the state record (rec.wpRegistry) —
       the sanctioned channel the static toolbar (outside the React tree)
       reads for the official wave-1 actions; a global-registry block-store
       dispatch is undefined by design on this page. Guards (consensus):
       attach once per editor, iframe-document listeners only, skip the
       zero-block state (seed/reseed and the store's default-block refill own
       it), skip multi-clicks, skip drags (mousedown-to-click distance),
       apply the seven-rule exclusion chain, re-check the block count stamped
       at mousedown, detach on unmount, and only act on a ready record. */
        /* [v1.1.12] the block types whose last text field can receive the caret
       through selectBlock(-1) — structural, state-independent. */
    function HossamClickBridge(props){
      if(!wp0.data||typeof wp0.data.useRegistry!=='function') return null;
      var useRef=el.useRef, useEffect=el.useEffect;
      var registry=wp0.data.useRegistry();
      var host=useRef(null);
      var countRef=useRef(props&&props.blocksCount?props.blocksCount:0);
      countRef.current=props&&props.blocksCount?props.blocksCount:0;
      useEffect(function(){
        var rec=hossamRichState.inited[containerId]||null;
        if(rec) rec.wpRegistry=registry;
        return function(){
          var r2=hossamRichState.inited[containerId];
          if(r2&&r2.wpRegistry===registry) r2.wpRegistry=null;
        };
      },[registry]);
      useEffect(function(){
        var node=host.current;
        if(!node||!node.ownerDocument) return;
        var doc=node.ownerDocument;
        var mouse={x:0,y:0,count:-1};
        function excluded(t){
          if(!t||t.nodeType!==1) return true;
          if(t.closest('.block-editor-block-list__block')) return true;
          if(t.closest('.components-popover,.components-tooltip')) return true;
          if(t.closest('.block-editor-inserter')) return true;
          if(t.closest('.block-editor-block-list__insertion-point')) return true;
          if(t.closest('.block-list-appender,.block-editor-default-block-appender')) return true;
          if(t.closest('button,a,input,select,textarea,[contenteditable="true"]')) return true;
          return false;
        }
        function isSurface(t){
          /* the empty area below the content hit-tests to the iframe's <html>
             (the body box ends with the content), so the surface walk accepts
             both body and the document element. */
          var n=t;
          while(n){
            if(n===doc.body||n===doc.documentElement) return true;
            var cls=n.classList;
            if(cls&&(cls.contains('is-root-container')||cls.contains('block-editor-block-list__layout'))) return true;
            if(n===doc.documentElement) break;
            n=n.parentNode;
          }
          return false;
        }
        function indexForY(y){
          var list=doc.querySelectorAll('.block-editor-block-list__layout.is-root-container > .block-editor-block-list__block');
          var store=registry.select('core/block-editor');
          for(var i=0;i<list.length;i++){
            if(y<list[i].getBoundingClientRect().top){
              var cid=list[i].getAttribute('data-block');
              if(cid){ var idx=store.getBlockIndex(cid); if(idx>=0) return idx; }
              return i;
            }
          }
          return -1;
        }
        function onMouseDown(ev){
          mouse.x=ev.clientX||0;
          mouse.y=ev.clientY||0;
          try{ mouse.count=registry.select('core/block-editor').getBlockCount(); }catch(e0){ mouse.count=-1; }
          /* [v1.1.15 item 4 - focus lift 1] any canvas interaction cancels the at-readiness caret so it never competes with a real click. */
          var recM=hossamRichState.inited[containerId];
          if(recM) recM.canvasTouched=true;
        }
        function onClick(ev){
          try{
            if(ev.detail>1) return;
            if(Math.abs((ev.clientX||0)-mouse.x)>4||Math.abs((ev.clientY||0)-mouse.y)>4) return;
            var rec=hossamRichState.inited[containerId];
            if(!rec||rec.phase!=='ready'||!rec.wpRegistry) return;
            var store=registry.select('core/block-editor');
            /* [v1.1.15 item 4 - focus lift 2] a real click on an unfocused
               text-type block selects the block (the caret lands at the
               block start - documented as better than dead) instead of the
               silent exclusion. The focused rich text stays native: its
               contenteditable surface is excluded below and the browser
               places the caret exactly where the user clicked. */
            var bw=ev.target&&ev.target.closest?ev.target.closest('.block-editor-block-list__block'):null;
            if(bw){
              var btype=bw.getAttribute('data-type')||'';
              var bcid=bw.getAttribute('data-block')||'';
              if(bcid&&HOSSAM_CLICK_TEXT_TYPES.indexOf(btype)!==-1
                &&!(ev.target.closest&&ev.target.closest('[contenteditable="true"]'))
                &&store.getSelectedBlockClientId()!==bcid){
                registry.dispatch('core/block-editor').selectBlock(bcid,0);
                return;
              }
            }
            if(excluded(ev.target)||!isSurface(ev.target)) return;
            if(countRef.current===0) return;
            if(mouse.count>=0&&store.getBlockCount()!==mouse.count) return;
            /* [v1.1.11 item 1 — consensus] region branching: the empty surface
               no longer mounts a floating paragraph below the content. Below
               the last block the caret moves to the END of the content
               (selectBlock(last,-1) — the core places the caret in the last
               text field of any block type); above the first block the caret
               moves to the START of the first block (selectBlock(first,0) —
               the recorded design decision, symmetric with the empty-state
               start-from-the-top the owner accepts); a middle gap keeps the
               native insertion path (insertDefaultBlock at the click index).
               A last block without a text field (separator, image without a
               caption) falls back to the append path — no caret can land
               there. */
            var list=doc.querySelectorAll('.block-editor-block-list__layout.is-root-container > .block-editor-block-list__block');
            if(!list.length) return;
            var y=ev.clientY||0;
            var actions=registry.dispatch('core/block-editor');
            var lastNode=list[list.length-1];
            var lastId=lastNode.getAttribute('data-block');
            /* [v1.1.12 hotfix — live-measured] the contenteditable attribute is
               state-dependent (Gutenberg renders it only on the focused rich
               text), so the probe must be structural: the block TYPE decides.
               Types without a text field keep the append fallback. */
            var lastType=lastNode.getAttribute('data-type')||'';
            if(y>=lastNode.getBoundingClientRect().bottom){
              if(lastId&&HOSSAM_CLICK_TEXT_TYPES.indexOf(lastType)!==-1){
                actions.selectBlock(lastId,-1);
                return;
              }
              actions.insertDefaultBlock();
              return;
            }
            if(y<list[0].getBoundingClientRect().top){
              var firstId=list[0].getAttribute('data-block');
              if(firstId){
                actions.selectBlock(firstId,0);
                return;
              }
            }
            var idx=indexForY(y);
            if(idx>=0) actions.insertDefaultBlock({},undefined,idx);
            else actions.insertDefaultBlock();
          }catch(e){}
        }
        doc.addEventListener('mousedown',onMouseDown,true);
        doc.addEventListener('click',onClick,true);
        return function(){
          doc.removeEventListener('mousedown',onMouseDown,true);
          doc.removeEventListener('click',onClick,true);
        };
      },[registry]);
      return el.createElement('div',{ref:host,'data-hossam-click-bridge':'1',hidden:true});
    }
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
        /* [v1.1.14 item 1] every editor commit feeds the rescue layer: the
           dirty flag (beforeunload + the nav() guard read it), the debounced
           write-vault capture and the debounced edit autosave. */
        try{hossamWriteMarkDirty();}catch(eRes){}
      }
        /* [W7] v1.1.3: the toolbar-side handle - reparse (Discard) and
           keep reuse the existing parse/keep paths only. */
        if(rec){ rec.api={keep:keep,reparse:function(){ try{ var ta=docRef.getElementById(rec.textareaId); var parsed=blocksPkg.parse(ta?(ta.value||''):'')||[]; keep(parsed); return parsed; }catch(e){ return null; } }}; }
      function handleChange(next){keep(next);}
      function handleInput(next){keep(next);}
      /* [v1.1.7 item 1-b] reseed-on-empty: zero live blocks = the dead canvas
         (the appender is hidden) — one empty paragraph is re-mounted in
         place. The ref latch fires once per zero-state and re-arms when the
         count returns above zero, so a later delete-all reseeds by design.
         Component-scoped: it ends with the component — no wp.data
         subscription is left behind, and the Discard/reparse path ([] on an
         empty field) reseeds through the same effect. */
      var reseedEffect=el.useEffect, reseedRef=el.useRef;
      var reseedLatch=reseedRef(false);
      reseedEffect(function(){
        try{
          if(blocks&&blocks.length){ reseedLatch.current=false; return; }
          if(reseedLatch.current) return;
          reseedLatch.current=true;
          var nb=blocksPkg.createBlock?blocksPkg.createBlock('core/paragraph',{}):null;
          if(nb) keep([nb]);
        }catch(e){}
      },[blocks]);
      function insertImage(img){
        try{
          var nb=blocksPkg.createBlock?blocksPkg.createBlock('core/image',{id:img.id,url:img.url,caption:img.caption||''}):null;
          if(nb) handleChange(blocks.concat([nb]));
        }catch(e){}
      }
      var barChildren=[];
      /* [v1.1.11 item 2 — consensus] the Gutenberg BlockToolbar render is
         removed: the fixed HAL toolbar is the only formatting surface and the
         canvas body stays writing-only. The Media button below stays; the
         floating contextual toolbar is prevented by settings.hasFixedToolbar
         and hidden inside the canvas by the contentCss defense-in-depth rule. */
      if(canUpload&&MediaUpload){
        barChildren.push(el.createElement(MediaUploadCheck,{key:'muc'},
          el.createElement(MediaUpload,{
            onSelect:function(img){insertImage(img);},
            allowedTypes:['image'],
            render:function(renderProps){
              var open=renderProps&&renderProps.open;
              return el.createElement('button',{type:'button',className:'btn btn-sm',onClick:function(){if(typeof open==='function'){open();}else{hossamRichOpenLibrary(insertImage);}}},t('mediaLabel','Media'));
            },
          })
        ));
      }
      var inserterNode=Inserter?el.createElement(Inserter,{key:'ins',position:'bottom right',showInserterHelpPanel:false}):null;
      var canvasNode=el.createElement(BlockCanvas,{key:'canvas',height:'420px',styles:[{css:contentCss}]},
        el.createElement(BlockList,null),
        el.createElement(HossamClickBridge,{key:'clickbridge',blocksCount:blocks.length})
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
    hossamSetEditorState(container,'empty');
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
          hossamVaultApplyNotify(containerId);
          try{container.setAttribute('hidden','');}catch(e){}
          hossamRichNotice();
          return;
        }
        confirmed=true;
        record.ready=true;record.phase='ready';record.confirmed=true;record.probe='iframe-ready';
        hossamVaultApplyNotify(containerId);
        /* [v1.1.15 item 4 - focus lift 1] the programmatic caret at readiness (the three-way gate lives inside). */
        hossamRichFocusAtReady(containerId);
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
      hossamVaultApplyNotify(containerId);
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
    hossamVaultApplyNotify(containerId);
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
/* [v1.1.15 item 3] the server-rendered refusal leg: the bound edit form
   carries the meta stamp at load (the refusal now survives a reload). The
   leg applies ONLY to a bound vault whose bound id matches the rendered
   edit form's post - an unbound vault keeps the exact previous behavior
   (the create form's contract is unchanged). Within a live page the JS
   stamp on the form governs: the effective comparison reads the newer of
   the two legs. */
function hossamWriteServerSavedAt(boundId){
  try{
    if(!boundId||boundId==='') return 0;
    var ef=document.getElementById('hossam-post-edit-form');
    if(!ef) return 0;
    var pid=ef.querySelector('[name="post_id"]');
    if(!pid||String(pid.value)!==String(boundId)) return 0;
    var n=parseInt(ef.getAttribute('data-hossam-last-saved')||'0',10);
    return (n&&n>0)?n:0;
  }catch(e){ return 0; }
}
/* [v1.1.14 item 1] restore banner: never silent. Shown when a fresh vault
   exists on load; refused over newer saved content (timestamp comparison);
   Restore re-applies the captured fields, Discard drops the vault. */
function hossamWriteRestoreBanner(form){
  try{
    if(!form||form.id!=='hossam-post-create-form') return;
    var raw=sessionStorage.getItem(hossamWriteVaultKey());
    if(!raw) return;
    var payload=null;
    try{payload=JSON.parse(raw);}catch(eP){payload=null;}
    if(!payload||payload.v!==1||!payload.fields||!payload.ts||(Date.now()-payload.ts)>HOSSAM_VAULT_TTL||hossamWriteVaultEmpty(payload)){
      sessionStorage.removeItem(hossamWriteVaultKey());
      return;
    }
    var savedAt=parseInt(form.getAttribute('data-hossam-last-saved')||'0',10);
    /* [v1.1.15 item 3] the server-rendered leg joins the comparison (the newer leg wins - the refusal survives a reload, the live-page JS stamp stays authoritative). */
    var serverAt=hossamWriteServerSavedAt(payload.bound?String(payload.bound):'');
    if(serverAt>savedAt) savedAt=serverAt;
    if(savedAt&&payload.ts<=savedAt){sessionStorage.removeItem(hossamWriteVaultKey());return;}
    var old=form.querySelector('[data-hossam-vault-banner]');
    if(old&&old.parentNode) old.parentNode.removeChild(old);
    var banner=document.createElement('div');
    banner.className='hossam-vault-restore-banner info-card';
    banner.setAttribute('data-hossam-vault-banner','1');
    var msg=document.createElement('p');
    if(payload.bound){
      msg.textContent=t('vaultRestoreBoundPrompt','Restore your unsaved edits to the saved article from {time}?').replace('{time}',new Date(payload.ts).toLocaleString());
    }else{
      msg.textContent=t('vaultRestorePrompt','Restore unsaved draft from {time}?').replace('{time}',new Date(payload.ts).toLocaleString());
    }
    banner.appendChild(msg);
    var restoreBtn=document.createElement('button');
    restoreBtn.type='button';
    restoreBtn.className='btn btn-sm btn-outline';
    restoreBtn.style.marginInlineEnd='6px';
    restoreBtn.textContent=t('vaultRestore','Restore');
    restoreBtn.addEventListener('click',function(){try{hossamWriteVaultApply(form,payload);banner.parentNode.removeChild(banner);}catch(eR){}});
    var discardBtn=document.createElement('button');
    discardBtn.type='button';
    discardBtn.className='btn btn-sm btn-ghost';
    discardBtn.textContent=t('vaultDiscard','Discard');
    discardBtn.addEventListener('click',function(){try{sessionStorage.removeItem(hossamWriteVaultKey());banner.parentNode.removeChild(banner);}catch(eD){}});
    banner.appendChild(restoreBtn);
    banner.appendChild(discardBtn);
    form.insertBefore(banner,form.firstChild);
  }catch(e){}
}
/* Restore applies the captured fields. The banner's payload must still BE
   the live vault (timestamp equality) and must not be older than the last
   saved content — both re-checked at click time. The tags[] mirror values
   are restored as data (the chips UI rebuilds on the next tag interaction —
   the honest limitation: saved tags beat lost tags).
   [v1.1.15 item 1] the measured AR corruption fix (defect 1): the apply
   gates on the strict editor readiness the rest of the editor uses — a
   still-mounting editor DEFERS the whole apply until the record reaches
   phase==='ready' (the canvas keep() path) or a terminal latch (the
   textarea fallback stays allowed there so a fallback environment can never
   deadlock the restore). Every field writes through its own explicit
   target, a post-apply verification pass reads each landing back, and the
   debounced capture is suppressed for the whole window so a corrupted
   intermediate state is never re-captured. The identical chain runs on
   every locale — no language branch anywhere in the apply path. */
/* [v1.1.15 item 1a] the strict classifier: ready is the exact gate
   hossamSyncRichEditorToTextarea uses; latched terminal records and
   record-less surfaces take the textarea fallback; anything else alive is
   still mounting and defers. */
function hossamVaultEditorState(rec){
  if(!rec) return 'none';
  if(rec.phase==='ready'&&rec.confirmed&&rec.ready&&rec.blocks) return 'ready';
  if(rec.phase==='fallback'||rec.phase==='error'||rec.latch) return 'terminal';
  return 'mounting';
}
/* [v1.1.15 item 1a] the transition notifier: fired at every editor record
   transition (ready / the fallback latches / error) so a deferred restore
   lands the moment the gate resolves instead of racing the mount. */
function hossamVaultApplyNotify(containerId){
  try{
    var w=hossamVaultApplyWaiters[containerId];
    if(typeof w==='function'){hossamVaultApplyWaiters[containerId]=null;w();}
  }catch(e){}
}
function hossamWriteVaultApply(form,payload){
  try{
    var raw=sessionStorage.getItem(hossamWriteVaultKey());
    if(!raw) return;
    var live=null;
    try{live=JSON.parse(raw);}catch(eP2){return;}
    if(!live||!live.ts||live.ts!==payload.ts) return;
    var savedAt=parseInt(form.getAttribute('data-hossam-last-saved')||'0',10);
    /* [v1.1.15 item 3] the server-rendered leg joins the comparison (the newer leg wins). */
    var serverAt=hossamWriteServerSavedAt(live.bound?String(live.bound):'');
    if(serverAt>savedAt) savedAt=serverAt;
    if(savedAt&&live.ts<=savedAt) return;
    var holder=form.querySelector('.hossam-rich-editor');
    var containerId=(holder&&holder.id)?holder.id:null;
    var rec=containerId?hossamRichState.inited[containerId]:null;
    var state=hossamVaultEditorState(rec);
    if(state==='mounting'&&containerId){
      /* [v1.1.15 item 1a] defer: the suppression window opens now and the
         apply reruns when the record resolves — ready takes the canvas
         path, a terminal latch takes the textarea fallback. The payload
         identity is the attempt token: a newer attempt replaces the waiter
         and an old ceiling can never fire a stale apply. The capture-
         suppression window keeps the vault intact through the whole wait (a
         real user input marks the form dirty but its debounced capture is
         refused while the window is open), and the timestamp re-check at
         completion still guards the residual edges - any vault rewrite from
         outside the suppressed capture drops the stale apply honestly. */
      form.setAttribute('data-hossam-apply-pending','1');
      if(hossamVaultApplyTimeouts[containerId]){clearTimeout(hossamVaultApplyTimeouts[containerId]);}
      hossamVaultApplyTimeouts[containerId]=setTimeout(function(){
        try{
          delete hossamVaultApplyTimeouts[containerId];
          if(hossamVaultApplyWaiters[containerId]===owner){
            hossamVaultApplyWaiters[containerId]=null;
            try{hossamDiagLogError('vault-apply-waiter-ceiling: the deferred restore took the textarea fallback');}catch(eD1){}
            hossamWriteVaultApplyRun(form,live,'textarea');
          }
        }catch(eD2){}
      },HOSSAM_VAULT_APPLY_WAIT_CEILING);
      var owner=function(){
        try{
          if(hossamVaultApplyTimeouts[containerId]){clearTimeout(hossamVaultApplyTimeouts[containerId]);delete hossamVaultApplyTimeouts[containerId];}
          delete hossamVaultApplyWaiters[containerId];
          var rawNow=sessionStorage.getItem(hossamWriteVaultKey());
          if(!rawNow){form.removeAttribute('data-hossam-apply-pending');return;}
          var liveNow=null;
          try{liveNow=JSON.parse(rawNow);}catch(eP4){form.removeAttribute('data-hossam-apply-pending');return;}
          if(!liveNow||!liveNow.ts||liveNow.ts!==live.ts){form.removeAttribute('data-hossam-apply-pending');return;}
          var savedNow=parseInt(form.getAttribute('data-hossam-last-saved')||'0',10);
          /* [v1.1.15 item 3] the server-rendered leg joins the deferred re-check too. */
          var serverNow=hossamWriteServerSavedAt(liveNow.bound?String(liveNow.bound):'');
          if(serverNow>savedNow) savedNow=serverNow;
          if(savedNow&&liveNow.ts<=savedNow){form.removeAttribute('data-hossam-apply-pending');return;}
          var recNow=hossamRichState.inited[containerId]||null;
          var stateNow=hossamVaultEditorState(recNow);
          hossamWriteVaultApplyRun(form,liveNow,stateNow==='ready'?'canvas':'textarea');
        }catch(eW){
          try{form.removeAttribute('data-hossam-apply-pending');}catch(eW2){}
        }
      };
      hossamVaultApplyWaiters[containerId]=owner;
      return;
    }
    hossamWriteVaultApplyRun(form,live,state==='ready'?'canvas':'textarea');
  }catch(e){}
}
/* [v1.1.15 item 1b] the apply body: every field through its own explicit
   target (title → the title input, excerpt/status/thumbnail_id → their
   fields, content → the canvas keep() path behind the strict gate or the
   textarea on a terminal/record-less surface, categories → the checked
   boxes, tags → the hidden mirrors), then the post-apply verification
   pass. A canvas-gated apply whose keep() failed while the editor is
   verified-ready never re-routes the content into the hidden textarea —
   that silent re-route is the desync family the measured defect lives in. */
function hossamWriteVaultApplyRun(form,live,surface){
  try{
    form.setAttribute('data-hossam-apply-pending','1');
    var f=live.fields||{};
    function setVal(name,val){var fld=form.querySelector('[name="'+name+'"]');if(fld) fld.value=(val==null?'':val);}
    setVal('title',f.title);
    setVal('excerpt',f.excerpt);
    setVal('status',f.status);
    setVal('thumbnail_id',f.thumbnail_id);
    var boxes=form.querySelectorAll('input[name="categories[]"]');
    for(var i=0;i<boxes.length;i++){boxes[i].checked=false;}
    var cats=Object.prototype.toString.call(f['categories[]'])==='[object Array]'?f['categories[]']:[];
    for(var c=0;c<cats.length;c++){
      for(var k2=0;k2<boxes.length;k2++){if(String(boxes[k2].value)===String(cats[c])){boxes[k2].checked=true;break;}}
    }
    var mirrors=form.querySelectorAll('input[name="tags[]"]');
    var tags=Object.prototype.toString.call(f['tags[]'])==='[object Array]'?f['tags[]']:[];
    for(var m=0;m<mirrors.length;m++){if(mirrors[m]&&mirrors[m].parentNode) mirrors[m].parentNode.removeChild(mirrors[m]);}
    for(var t2=0;t2<tags.length;t2++){
      var mi=document.createElement('input');
      mi.type='hidden';mi.name='tags[]';mi.value=String(tags[t2]);
      form.appendChild(mi);
    }
    var content=String(f.content==null?'':f.content);
    var recApplied=null,parsedApplied=null;
    if(surface==='canvas'){
      var holder=form.querySelector('.hossam-rich-editor');
      var rec=(holder&&holder.id)?hossamRichState.inited[holder.id]:null;
      var wp0=hossamRichWp();
      var ok=rec&&rec.phase==='ready'&&rec.confirmed&&rec.ready&&rec.blocks&&rec.api&&typeof rec.api.keep==='function'&&wp0&&wp0.blocks&&typeof wp0.blocks.parse==='function';
      if(ok){
        try{var parsed=wp0.blocks.parse(content)||[];rec.api.keep(parsed);recApplied=rec;parsedApplied=parsed;}catch(eK){recApplied=null;parsedApplied=null;}
      }
    }
    if(!recApplied){
      if(surface==='canvas'){
        try{hossamDiagLogError('vault-apply-canvas-keep-failed');}catch(eK2){}
      }else{
        var ta=form.querySelector('[name="content"]');
        if(ta) ta.value=content;
      }
    }
    /* [v1.1.14 - the independent audit correction 2] the binding survives the
       reload through the vault: a bound restore re-binds the form so the next
       save updates the same article (the duplicate window stays closed across
       page loads), and the banner names it explicitly. */
    if(String(live.bound||'')!==''){
      var hidden2=form.querySelector('[name="post_id"]');
      if(!hidden2){hidden2=document.createElement('input');hidden2.type='hidden';hidden2.name='post_id';form.appendChild(hidden2);}
      hidden2.value=String(live.bound);
      form.setAttribute('data-hossam-bound-post',String(live.bound));
    }
    hossamWriteMarkDirty();
    /* [v1.1.15 item 1b] the post-apply verification pass: every restored
       field is read back from its own target — the title equality is the
       measured glue detector (defect 1: canvas content landing in the
       title). */
    var verified=hossamWriteVaultVerify(form,f,recApplied?'canvas':'textarea',recApplied,parsedApplied?parsedApplied.length:null);
    if(verified){
      form.removeAttribute('data-hossam-apply-pending');
    }else{
      /* [v1.1.15 item 1c] a failed verify keeps the suppression window open
         and the original vault intact — the corrupted intermediate state is
         never re-captured; the latch clears on the next real user input. */
      form.setAttribute('data-hossam-apply-failed','1');
      try{hossamDiagLogError('vault-apply-verify-failed: surface='+(recApplied?'canvas':'textarea')+' the restored fields did not all land');}catch(eV2){}
    }
  }catch(e){
    try{form.setAttribute('data-hossam-apply-failed','1');}catch(e2){}
  }
}
/* [v1.1.15 item 1b] the verification pass: title/excerpt/status/thumbnail_id
   read back exactly from their targets, categories and tags re-read from
   their targets, and the content surface checked structurally — the strict
   ready record holding exactly the applied block count on the canvas, the
   exact payload string in the textarea. */
function hossamWriteVaultVerify(form,fields,surface,rec,wantLen){
  try{
    function appliedVal(name){var fld=form.querySelector('[name="'+name+'"]');return fld?String(fld.value==null?'':fld.value):null;}
    function fieldMatches(name){
      var want=fields?fields[name]:undefined;
      want=(want==null?'':String(want));
      var got=appliedVal(name);
      return got!==null&&got===want;
    }
    if(!fieldMatches('title')) return false;
    if(!fieldMatches('excerpt')) return false;
    if(!fieldMatches('status')) return false;
    if(!fieldMatches('thumbnail_id')) return false;
    function listOf(v){return Object.prototype.toString.call(v)==='[object Array]'?v.map(String):[];}
    function setKey(arr){return arr.slice().sort().join('|');}
    var gotCats=[];
    var catBoxes=form.querySelectorAll('input[name="categories[]"]:checked');
    for(var ci=0;ci<catBoxes.length;ci++){gotCats.push(String(catBoxes[ci].value));}
    if(setKey(gotCats)!==setKey(listOf(fields?fields['categories[]']:[]))) return false;
    var gotTags=[];
    var tagMirrors=form.querySelectorAll('input[name="tags[]"]');
    for(var ti=0;ti<tagMirrors.length;ti++){gotTags.push(String(tagMirrors[ti].value));}
    if(setKey(gotTags)!==setKey(listOf(fields?fields['tags[]']:[]))) return false;
    var wantContent=String((fields&&fields.content)==null?'':(fields&&fields.content));
    if(surface==='canvas'){
      if(!rec||rec.phase!=='ready'||!rec.confirmed||!rec.ready||!rec.blocks) return false;
      if(wantLen!==null&&(!rec.blocks||rec.blocks.length!==wantLen)) return false;
    }else{
      var ta=form.querySelector('[name="content"]');
      if(!ta||String(ta.value==null?'':ta.value)!==wantContent) return false;
    }
    return true;
  }catch(eV){return false;}
}
/* [v1.1.14 item 1] the edit-context rescue: the official autosaves endpoint
   through wp.apiFetch with the official wp_rest nonce (issued server-side
   decoupled from upload_files in setup.php). Debounced; failures land in
   the recorder, never as toasts. */
function hossamWriteAutosave(){
  try{
    var form=document.getElementById('hossam-post-edit-form');
    if(!form||!window.hossamWriteDirty) return;
    var pid=form.querySelector('[name="post_id"]');
    var id=pid?parseInt(pid.value,10):0;
    if(!id||id<1) return;
    var cfg=hossamRichConfig();
    if(!cfg||!cfg.restNonce||!cfg.restRoot) return;
    var wp0=hossamRichWp();
    if(!wp0||!wp0.apiFetch) return;
    try{
      if(!window.hossamApiFetchWired){
        if(cfg.restNonce&&wp0.apiFetch.createNonceMiddleware) wp0.apiFetch.use(wp0.apiFetch.createNonceMiddleware(cfg.restNonce));
        if(cfg.restRoot&&wp0.apiFetch.createRootURLMiddleware) wp0.apiFetch.use(wp0.apiFetch.createRootURLMiddleware(cfg.restRoot));
        window.hossamApiFetchWired=true;
      }
    }catch(eMw){}
    if(typeof hossamSyncRichEditorToTextarea==='function'){try{hossamSyncRichEditorToTextarea(form);}catch(eSync){}}
    var title=form.querySelector('[name="title"]');
    var content=form.querySelector('[name="content"]');
    var excerpt=form.querySelector('[name="excerpt"]');
    wp0.apiFetch({path:'/wp/v2/posts/'+id+'/autosaves',method:'POST',data:{content:content?content.value:undefined,title:title?title.value:undefined,excerpt:excerpt?excerpt.value:undefined}})
      .then(function(){try{form.setAttribute('data-hossam-autosaved',String(Date.now()));}catch(eA){}})
      .catch(function(err){try{hossamDiagLogError('autosave-failed: '+String((err&&err.message)||err||'error').slice(0,200));}catch(eE){}});
  }catch(e){}
}
/* [v1.1.14 item 1 — defect A] the black-box recorder: installed once; the
   pagehide snapshot is the evidence surface the diagnosis session reads. */
function hossamDiagInstall(){
  try{
    if(window.hossamDiagWired) return;
    window.hossamDiagWired=true;
    window.hossamDiagStart=Date.now();
    try{
      var origError=console.error;
      if(typeof origError==='function'){
        console.error=function(){
          try{var parts=[];for(var i=0;i<arguments.length;i++){parts.push(String(arguments[i]));}hossamDiagLogError(parts.join(' '));}catch(eW){}
          return origError.apply(console,arguments);
        };
      }
    }catch(eC){}
    window.addEventListener('error',function(ev){try{hossamDiagLogError('window.onerror: '+String((ev&&ev.message)||'error').slice(0,200));}catch(e1){}});
    window.addEventListener('unhandledrejection',function(ev){try{hossamDiagLogError('unhandledrejection: '+String((ev&&ev.reason)||(ev&&ev.detail&&ev.detail.reason)||'rejection').slice(0,200));}catch(e2){}});
    window.addEventListener('pagehide',function(){
      try{
        var navType='';
        try{
          var navEntries=(typeof performance==='object'&&performance.getEntriesByType)?performance.getEntriesByType('navigation'):[];
          if(navEntries&&navEntries.length&&navEntries[0]&&navEntries[0].type) navType=String(navEntries[0].type);
        }catch(eN){}
        sessionStorage.setItem(HOSSAM_DIAG_KEY,JSON.stringify({
          v:1,ts:Date.now(),navType:navType,visibility:document.visibilityState,
          pageAgeMs:Date.now()-(window.hossamDiagStart||Date.now()),
          lastInputAgoMs:window.hossamDiagLastInput?(Date.now()-window.hossamDiagLastInput):-1,
          dirty:!!window.hossamWriteDirty,
          errors:hossamDiagErrors.slice(-5)
        }));
      }catch(e3){}
    });
  }catch(e){}
}
/* [v1.1.14 item 1] rescue wiring (once): the per-form input listeners feed
   the dirty flag and the debounced captures; beforeunload is conditioned on
   the dirty flag; the governor cancel rides the first keydown/mousedown;
   the restore banner mounts on the create form. */
(function hossamRescueWire(){
  try{
    if(window.hossamRescueWired) return;
    window.hossamRescueWired=true;
    var createForm=document.getElementById('hossam-post-create-form');
    var editForm=document.getElementById('hossam-post-edit-form');
    if(createForm){
      createForm.addEventListener('input',function(){try{
        /* [v1.1.15 item 1c] a real user input clears a latched verify failure:
           the user's own next words start an honest capture again. */
        if(createForm.getAttribute('data-hossam-apply-failed')==='1'){createForm.removeAttribute('data-hossam-apply-failed');createForm.removeAttribute('data-hossam-apply-pending');}
        hossamWriteMarkDirty();
      }catch(eW1){}});
      hossamWriteRestoreBanner(createForm);
      var titleIn=createForm.querySelector('[name="title"]');
      if(titleIn){
        titleIn.addEventListener('keydown',function(ev){
          try{
            if((ev.key||'')!=='Enter'||ev.shiftKey) return;
            ev.preventDefault();
            /* [v1.1.15 item 4 - focus lift 3] Enter in the title moves the writing focus into the canvas (write context only) through the deposited sub-registry. */
            var holder3=createForm.querySelector('.hossam-rich-editor');
            var rec3=(holder3&&holder3.id)?hossamRichState.inited[holder3.id]:null;
            if(!rec3||rec3.phase!=='ready'||!rec3.wpRegistry) return;
            var blocks3=rec3.wpRegistry.select('core/block-editor').getBlocks();
            if(blocks3&&blocks3.length){
              rec3.wpRegistry.dispatch('core/block-editor').selectBlock(blocks3[0].clientId,0);
            }
          }catch(eTL){}
        });
      }
    }
    if(editForm){
      editForm.addEventListener('input',function(){try{hossamWriteMarkDirty();}catch(eW2){}});
    }
    document.addEventListener('keydown',function(){try{if(hossamWriteRedirectArmed)hossamWriteCancelRedirect();}catch(eW3){}},true);
    document.addEventListener('mousedown',function(){try{if(hossamWriteRedirectArmed)hossamWriteCancelRedirect();}catch(eW4){}},true);
    window.addEventListener('beforeunload',function(ev){
      try{
        if(window.hossamWriteDirty){ev.preventDefault();ev.returnValue='';}
      }catch(eW5){}
    });
    hossamDiagInstall();
  }catch(e){}
})();
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
/* [v1.1.9 item 1 — consensus] the official-action bridge for the static
   toolbar: the toolbar DOM lives outside the React tree, so the block store
   on the GLOBAL registry is absent on this page and a global dispatch is
   undefined by design. The sanctioned channel is the sub-registry the
   in-tree click bridge deposits on the editor state record (rec.wpRegistry);
   every official action reads/writes that registry, and the store changes
   flow back through the provider's onChange/onInput into keep() (the
   existing sync path). Store-level insertions are not undoable by the
   legacy text-level undo — documented consensus limitation. */
function hossamToolbarRegistry(containerId){
  try{
    var rec=containerId?hossamRichState.inited[containerId]:null;
    return rec&&rec.wpRegistry?rec.wpRegistry:null;
  }catch(e){ return null; }
}
/* [v1.1.15 item 4 - the F-1 core] the unified failure path: a silent false
   is never the whole answer - the self-dismissing toast names the missing
   precondition, one automatic selection recovery (selectBlock on the last
   text-type block, -1) re-arms the operation, and a single retry runs
   before giving up. The three official families (transform / richEdit /
   alignment) ride the same wrapper. */
function hossamToolbarToast(key,fallback){
  try{ showToast(t(key,fallback),'error'); }catch(e){}
}
function hossamToolbarRecoverSelection(containerId){
  try{
    var reg=hossamToolbarRegistry(containerId);
    if(!reg) return false;
    var blocksR=reg.select('core/block-editor').getBlocks();
    if(!blocksR||!blocksR.length) return false;
    for(var i=blocksR.length-1;i>=0;i--){
      if(blocksR[i]&&HOSSAM_CLICK_TEXT_TYPES.indexOf(blocksR[i].name)!==-1){
        reg.dispatch('core/block-editor').selectBlock(blocksR[i].clientId,-1);
        return true;
      }
    }
    return false;
  }catch(e){ return false; }
}
function hossamToolbarWithRecovery(containerId,run,toastKey,toastText){
  var ok=run();
  if(ok) return true;
  hossamToolbarToast(toastKey,toastText);
  if(hossamToolbarRecoverSelection(containerId)) ok=run();
  return ok;
}
function hossamToolbarTransformBlock(containerId,blockName,attributes){
  return hossamToolbarWithRecovery(containerId,function(){return hossamToolbarTransformBlockRun(containerId,blockName,attributes);},'toolbarNoBlock','No editable block selected');
}
/* [v1.1.15 item 4 - D5 documentation, owner decision 2026-11-22 (option A): the shipped list semantics are SINGLE-BLOCK - switchToBlockType on one block yields a one-item list. Documented as the official behavior; the multi-paragraph replaceBlocks extension stays deferred per the v1.1.14 deferral chain. */
function hossamToolbarTransformBlockRun(containerId,blockName,attributes){
  try{
    var reg=hossamToolbarRegistry(containerId);
    if(!reg) return false;
    var wp0=hossamRichWp();
    if(!wp0||!wp0.blocks||typeof wp0.blocks.switchToBlockType!=='function') return false;
    var sel=reg.select('core/block-editor');
    var cid=sel.getSelectedBlockClientId();
    if(!cid) return false;
    var block=sel.getBlock(cid);
    if(!block) return false;
    var transformed=wp0.blocks.switchToBlockType(block,blockName);
    if(!transformed||!transformed.length) return false;
    if(attributes&&transformed[0]) transformed[0].attributes=Object.assign({},transformed[0].attributes||{},attributes);
    reg.dispatch('core/block-editor').replaceBlocks([cid],transformed);
    return true;
  }catch(e){ return false; }
}
function hossamToolbarInsertBlock(containerId,blockName,attributes){
  try{
    var reg=hossamToolbarRegistry(containerId);
    if(!reg) return false;
    var wp0=hossamRichWp();
    if(!wp0||!wp0.blocks||typeof wp0.blocks.createBlock!=='function') return false;
    var nb=wp0.blocks.createBlock(blockName,attributes||{});
    reg.dispatch('core/block-editor').insertBlocks(nb);
    return true;
  }catch(e){ return false; }
}
/* [v1.1.16 item 2 - consensus] the list-context attribute toggle: when the
   selection already sits on a list block (or its inner list item), the
   official ordered attribute writes directly through the sub-registry - the
   same-type block switch has no transform on this core, so the funnel was a
   measured no-op. The paragraph transform path stays byte-unchanged. */
function hossamToolbarListOrdered(containerId,ordered){
  try{
    var reg=hossamToolbarRegistry(containerId);
    if(!reg) return false;
    var selL=reg.select('core/block-editor');
    var cidL=selL.getSelectedBlockClientId();
    if(!cidL) return false;
    var blockL=selL.getBlock(cidL);
    if(!blockL) return false;
    var targetL=null;
    if(blockL.name==='core/list') targetL=cidL;
    else if(blockL.name==='core/list-item'&&selL.getBlockParents){
      var parentsL=selL.getBlockParents(cidL);
      for(var iL=parentsL.length-1;iL>=0;iL--){
        var pL=selL.getBlock(parentsL[iL]);
        if(pL&&pL.name==='core/list'){ targetL=parentsL[iL]; break; }
      }
    }
    if(!targetL) return false;
    reg.dispatch('core/block-editor').updateBlockAttributes(targetL,{ordered:ordered});
    return true;
  }catch(eL){ return false; }
}
/* [v1.1.11 item 3 — consensus wave 2] the unified official formatting
   channel: the selection is read from the sub-registry store (the only
   registry carrying this editor's block store), the value is transformed
   with the official rich-text API, the result is written through
   updateBlockAttributes, and the selection is restored afterwards with the
   four-argument selectionChange. Store-level writes are outside the reach
   of the legacy text-level undo — the documented limitation extends here. */
function hossamToolbarRichEdit(containerId,fn){
  return hossamToolbarWithRecovery(containerId,function(){return hossamToolbarRichEditRun(containerId,fn);},'toolbarSelectFirst','Select text first');
}
function hossamToolbarRichEditRun(containerId,fn){
  try{
    var reg=hossamToolbarRegistry(containerId);
    if(!reg) return false;
    var wp0=hossamRichWp();
    if(!wp0||!wp0.richText) return false;
    var sel=reg.select('core/block-editor');
    var cid=sel.getSelectedBlockClientId();
    if(!cid) return false;
    var block=sel.getBlock(cid);
    if(!block||!block.attributes||block.attributes.content===undefined) return false;
    var s=sel.getSelectionStart(), e=sel.getSelectionEnd();
    if(!s||!e) return false;
    var start=(typeof s.offset==='number')?s.offset:0;
    var end=(typeof e.offset==='number')?e.offset:start;
    /* the content attribute may carry a RichTextData instance on this core
       version — serialize it to its HTML string first (Arabic text must not
       distort through a raw-string round-trip). */
    var htmlIn=block.attributes.content;
    if(htmlIn&&typeof htmlIn.toHTMLString==='function') htmlIn=htmlIn.toHTMLString();
    var value=wp0.richText.create({html:htmlIn});
    var out=fn(wp0.richText,value,start,end);
    if(out===null||out===undefined) return false;
    reg.dispatch('core/block-editor').updateBlockAttributes(cid,{content:wp0.richText.toHTMLString({value:out})});
    try{ reg.dispatch('core/block-editor').selectionChange(cid,'content',start,end); }catch(e1){}
    return true;
  }catch(e){ return false; }
}
function hossamToolbarApplyFormat(containerId,formatType,attrs){
  return hossamToolbarRichEdit(containerId,function(rt,value,start,end){
    return rt.applyFormat(value,{type:formatType,attributes:attrs||{}},start,end);
  });
}
/* The pinned clear list (consensus): the inline formats the toolbar applies
   are removed; the exceptions never are. Pinned by B9R-W2-REMOVE-EXCEPTIONS. */
var HOSSAM_TOOLBAR_CLEAR_TYPES=['core/bold','core/italic','core/underline','core/strikethrough','core/subscript','core/superscript','core/text-color','core/code'];
var HOSSAM_TOOLBAR_CLEAR_EXCEPTIONS=['core/link','core/image','core/footnote','core/math','core/language','core/keyboard','core/non-breaking-space','core/unknown','core/note'];
function hossamToolbarRemoveFormat(containerId){
  return hossamToolbarRichEdit(containerId,function(rt,value,start,end){
    var removed=value;
    for(var i=0;i<HOSSAM_TOOLBAR_CLEAR_TYPES.length;i++){
      /* [v1.1.15 item 2] the official signature is removeFormat(value, formatType, start, end) - the previous argument order passed the numeric start into the formatType slot, so every pinned removal was a silent no-op (the measured clear-formatting defect). */
      removed=rt.removeFormat(removed,HOSSAM_TOOLBAR_CLEAR_TYPES[i],start,end);
    }
    return removed;
  });
}
function hossamToolbarSetCase(containerId,mode){
  return hossamToolbarRichEdit(containerId,function(rt,value,start,end){
    var text=rt.getTextContent(value);
    var slice=text.slice(start,end);
    var out=slice;
    if(mode==='upper') out=slice.toUpperCase();
    else if(mode==='lower') out=slice.toLowerCase();
    else if(mode==='sentence') out=slice.charAt(0).toUpperCase()+slice.slice(1).toLowerCase();
    /* title mode is Latin-only by regex; Arabic text passes through unchanged. */
    else if(mode==='title') out=slice.replace(/\w\S*/g,function(w){return w.charAt(0).toUpperCase()+w.slice(1).toLowerCase();});
    else return null;
    return rt.insert(value,out,start,end);
  });
}
/* [v1.1.11 item 3 — consensus] text alignment lives in
   style.typography.textAlign on this core version (the align attribute is
   layout-width-only — writing to it would produce an invalid block). */
function hossamToolbarSetAlignment(containerId,value){
  return hossamToolbarWithRecovery(containerId,function(){return hossamToolbarSetAlignmentRun(containerId,value);},'toolbarNoBlock','No editable block selected');
}
function hossamToolbarSetAlignmentRun(containerId,value){
  try{
    var reg=hossamToolbarRegistry(containerId);
    if(!reg) return false;
    var sel=reg.select('core/block-editor');
    var cid=sel.getSelectedBlockClientId();
    if(!cid) return false;
    var block=sel.getBlock(cid);
    if(!block||!block.attributes) return false;
    var style=block.attributes.style?block.attributes.style:{};
    var typography=style.typography?style.typography:{};
    var merged=Object.assign({},style,{typography:Object.assign({},typography,{textAlign:value})});
    reg.dispatch('core/block-editor').updateBlockAttributes(cid,{style:merged});
    return true;
  }catch(e){ return false; }
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
    /* [v1.1.15 item 2] the popover discipline: the trigger carries the open state (aria-expanded), an opening menu closes its open siblings, and the state moves as one synchronized pair. */
    var trig=btn(triggerSym,triggerTitle,function(){toggle();});
    trig.setAttribute('aria-expanded','false');
    trig.appendChild(el('span','hossam-pt-caret','▾'));
    var m=el('div','hossam-pt-menu');
    m.appendChild(el('div','hossam-pt-menu-title',t(menuTitleKey,menuTitleText)));
    buildBody(m);
    m.style.display='none';
    function closeSiblings(){
      try{
        var rootEl=wrap.closest?wrap.closest('.hossam-pro-toolbar-container'):null;
        if(!rootEl||!rootEl.querySelectorAll) return;
        var dds=rootEl.querySelectorAll('.hossam-pt-dropdown');
        for(var i=0;i<dds.length;i++){
          if(dds[i]===wrap) continue;
          var mm=dds[i].querySelector('.hossam-pt-menu');
          var tt=dds[i].querySelector('.hossam-pt-btn');
          if(mm&&mm.style.display!=='none'){mm.style.display='none';}
          if(tt&&tt.setAttribute) tt.setAttribute('aria-expanded','false');
        }
      }catch(e){}
    }
    function toggle(){
      try{
        var open=(m.style.display!=='none');
        if(!open) closeSiblings();
        m.style.display=open?'none':'block';
        trig.setAttribute('aria-expanded',open?'false':'true');
      }catch(e){}
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
  function freeRow(m,pickId,apply,sel){
    m.appendChild(el('div','hossam-pt-menu-title',t('w7FreePicker','Free color picker:')));
    var row=el('div','hossam-pt-free-row');
    var pick=el('input'); pick.type='color'; pick.id=pickId;
    /* [v1.1.15 item 2] the measured Apply-lands-black defect: the Apply button sent the untouched picker default over the selected swatch. The selection state rides the menu: a picker touch marks it, a swatch click clears the mark and syncs the visible picker - the free-input Apply stays intact (a picked color always wins; no selection at all keeps the exact previous behavior). */
    if(sel){
      sel.syncPick=function(v){try{pick.value=v;}catch(e){}};
      pick.addEventListener('input',function(){try{sel.pickTouched=true;}catch(e){}});
      pick.addEventListener('change',function(){try{sel.pickTouched=true;}catch(e){}});
    }
    var ap=el('button','btn btn-sm',t('aiApply','Apply'));
    ap.type='button';
    ap.addEventListener('click',function(){
      try{
        var v=pick.value;
        if(sel&&sel.pickTouched){v=pick.value;}
        else if(sel&&sel.swatch){v=sel.swatch;}
        apply(v);
      }catch(e){}
    });
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
  ],function(v){
    /* [v1.1.11 item 2 — consensus] official block transforms replace the
       formatBlock funnel (whose in-paragraph structure was never a real
       heading/quote block) before the Gutenberg block switcher disappears
       with hasFixedToolbar. */
    if(v==='p') hossamToolbarTransformBlock(containerId,'core/paragraph');
    else hossamToolbarTransformBlock(containerId,'core/heading',{level:parseInt(String(v).slice(1),10)||2});
  }));
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
  /* G4 — line spacing (the official lineHeight attribute on the selected block) */
  var g4=group(true);
  g4.appendChild(select(['w7LineHeightLabel','Line spacing'],[
    ['1.4','w7LhCompact','↕ 1.4 (Compact)'],
    ['1.6','w7LhNormal','↕ 1.6 (Normal)'],
    ['1.8','w7LhRelaxed','↕ 1.8 (Relaxed)'],
    ['2.0','w7LhDouble','↕ 2.0 (Double)']
  ],function(v){
    /* [v1.1.16 item 4 - consensus] the official lineHeight attribute on the
       SELECTED block through the sub-registry (the same merge pattern the
       alignment family uses). The legacy DOM walk landed on the iframe
       body's direct child - the shared root container measured live -
       shifting every block and persisting nothing. */
    try{
      var reg=hossamToolbarRegistry(containerId);
      if(!reg) return;
      var selH=reg.select('core/block-editor');
      var cidH=selH.getSelectedBlockClientId();
      var blkH=cidH?selH.getBlock(cidH):null;
      if(!blkH||!blkH.attributes) return;
      var stH=blkH.attributes.style?blkH.attributes.style:{};
      var typH=stH.typography?stH.typography:{};
      var mergedH=Object.assign({},stH,{typography:Object.assign({},typH,{lineHeight:v})});
      reg.dispatch('core/block-editor').updateBlockAttributes(cidH,{style:mergedH});
    }catch(eH){}
  }));
  root.appendChild(g4); root.appendChild(divider());
  /* G5 — inline formatting. [v1.1.11 item 3 — consensus wave 2] the
     strikethrough/subscript/superscript/clear tools move to the official
     rich-text channel (registered formats + the pinned clear list); bold/
     italic/underline stay on the legacy funnel (measured working). */
  var g5=group();
  g5.appendChild(btn('B',['w7Bold','Bold (Ctrl+B)'],function(){hossamToolbarCmd(container,'bold');}));
  g5.appendChild(btn('I',['w7Italic','Italic (Ctrl+I)'],function(){hossamToolbarCmd(container,'italic');}));
  g5.appendChild(btn('U',['w7Underline','Underline (Ctrl+U)'],function(){hossamToolbarCmd(container,'underline');}));
  g5.appendChild(btn('S',['w7Strike','Strikethrough'],function(){hossamToolbarApplyFormat(containerId,'core/strikethrough');}));
  g5.appendChild(btn('X₂',['w7Subscript','Subscript (X₂)'],function(){hossamToolbarApplyFormat(containerId,'core/subscript');}));
  g5.appendChild(btn('X²',['w7Superscript','Superscript (X²)'],function(){hossamToolbarApplyFormat(containerId,'core/superscript');}));
  g5.appendChild(btn('🧹',['w7ClearFormat','Clear formatting'],function(){hossamToolbarRemoveFormat(containerId);}));
  root.appendChild(g5); root.appendChild(divider());
  /* G6 — color, highlight, case, grow/shrink */
  var g6=group();
  /* [v1.1.11 item 3 — consensus wave 2] color and highlight migrate to the
     official core/text-color format (style color / background-color) through
     the unified channel. */
  g6.appendChild(menu('A',['w7TextColor','Text color'],'w7TextColor','Text color',function(m){
    m.appendChild(el('div','hossam-pt-menu-title',t('w7Suggested','Suggested colors:')));
    var sel={swatch:null,pickTouched:false};
    swatchRow(m,[['#171825','w7ColorNavy','Navy'],['#C5A028','w7ColorGold','Gold'],['#C0392B','w7ColorRed','Red'],['#1A5FAB','w7ColorBlue','Blue'],['#2E7D4F','w7ColorGreen','Green'],['#5C5B6B','w7ColorGray','Gray'],['#000000','w7ColorBlack','Black']],function(c){try{sel.swatch=c;sel.pickTouched=false;if(typeof sel.syncPick==='function'){sel.syncPick(c);}}catch(e){}hossamToolbarApplyFormat(containerId,'core/text-color',{style:'color: '+c});});
    freeRow(m,'hossamFreeTextColor',function(c){hossamToolbarApplyFormat(containerId,'core/text-color',{style:'color: '+c});},sel);
  }));
  g6.appendChild(menu('🖍️',['w7Highlight','Highlight'],'w7Highlight','Highlight',function(m){
    m.appendChild(el('div','hossam-pt-menu-title',t('w7Suggested','Suggested colors:')));
    var sel={swatch:null,pickTouched:false};
    swatchRow(m,[['#FDF6B2','w7ColorYellow','Yellow'],['#E1EFFE','w7ColorBlue','Blue'],['#EAF5EE','w7ColorGreen','Green'],['#FDECEB','w7ColorRed','Red'],['#F2E7C0','w7ColorGoldPale','Gold Pale']],function(c){try{sel.swatch=c;sel.pickTouched=false;if(typeof sel.syncPick==='function'){sel.syncPick(c);}}catch(e){}hossamToolbarApplyFormat(containerId,'core/text-color',{style:'background-color: '+c});});
    freeRow(m,'hossamFreeHighlight',function(c){hossamToolbarApplyFormat(containerId,'core/text-color',{style:'background-color: '+c});},sel);
  }));
  g6.appendChild(menu('Aa',['w7Case','Change case'],'w7Case','Change case',function(m){
    /* [v1.1.16 item 1 - consensus] the case items are [key, default, mode]: the
       label reads the registered key with its default and the click passes the
       mode (the shifted indexing passed an undefined mode and rendered the raw
       mode strings as labels - the measured case defect). */
    var items=[['w7CaseSentence','Sentence case.','sentence'],['w7CaseLower','lowercase','lower'],['w7CaseUpper','UPPERCASE','upper'],['w7CaseTitle','Title Case','title']];
    for(var i=0;i<items.length;i++){
      (function(item){
        var mi=el('button','hossam-pt-menu-item',t(item[0],item[1]));
        mi.type='button';
        mi.addEventListener('click',function(){
          try{
            /* [v1.1.11 item 3 — consensus wave 2] the official rich-text
               insert replaces the legacy funnel (whose DOM change was
               reverted by the controlled re-render). Title mode is Latin-only
               by regex; Arabic passes through unchanged. */
            hossamToolbarSetCase(containerId,item[2]);
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
  /* [v1.1.11 item 3 — consensus wave 2] text alignment lives in
     style.typography.textAlign through the sub-registry.
     [v1.1.16 item 3 — consensus] justify full migrates off the dead legacy funnel
     into this same official alignment family (the funnel was a
     measured dead-silent no-op live). */
  g7.appendChild(btn('⫷',['w7AlignLeft','Align left'],function(){hossamToolbarSetAlignment(containerId,'left');}));
  g7.appendChild(btn('≡',['w7AlignCenter','Center align'],function(){hossamToolbarSetAlignment(containerId,'center');}));
  g7.appendChild(btn('⫸',['w7AlignRight','Align right'],function(){hossamToolbarSetAlignment(containerId,'right');}));
  g7.appendChild(btn('≣',['w7Justify','Justify full'],function(){hossamToolbarSetAlignment(containerId,'justify');}));
  g7.appendChild(btn('⇄',['w7Direction','Switch paragraph direction'],function(){
    try{
      var d=hossamToolbarDoc(container);
      var s=d&&d.getSelection&&d.getSelection();
      /* [v1.1.15 item 4 - D2 per the owner's resolved kickoff decision (v1114 ledger S-D)] the official paragraph-direction path behind the getBlockSupport probe: when the live core supports the direction attribute, the switcher migrates off the legacy funnel and writes the official attribute through the sub-registry; when the probe fails, the tool stays deferred exactly like justify-full and this documented canvas-level fallback remains the shipped behavior (a separate owner decision if it ever migrates). The direction-chain bytes stay byte-preserved either way. */
      var wpD=hossamRichWp();
      if(wpD&&wpD.blocks&&typeof wpD.blocks.getBlockSupport==='function'&&wpD.blocks.getBlockSupport('core/paragraph','direction')){
        var regD=hossamToolbarRegistry(containerId);
        if(regD){
          var selD=regD.select('core/block-editor');
          var cidD=selD.getSelectedBlockClientId();
          var blkD=cidD?selD.getBlock(cidD):null;
          if(blkD&&blkD.attributes&&blkD.attributes.direction!==undefined){
            regD.dispatch('core/block-editor').updateBlockAttributes(cidD,{direction:(blkD.attributes.direction==='rtl'?'ltr':'rtl')});
            return;
          }
        }
        return;
      }
      /* [v1.1.15 corrections C-1] the two legacy lines restored: the declaration and the body walk feed the final write below (the advisory corrections commit). */
      var blk=s&&s.anchorNode?(s.anchorNode.nodeType===1?s.anchorNode:s.anchorNode.parentNode):null;
      while(blk&&blk.parentNode&&blk.parentNode!==d.body) blk=blk.parentNode;
      if(blk) blk.style.direction=(blk.style.direction==='rtl'?'ltr':'rtl');
    }catch(e){}
  }));
  root.appendChild(g7); root.appendChild(divider());
  /* G8 — lists & indent. [v1.1.9 item 1 — consensus wave 1] the bullet and
     numbered lists move to the official store path (switchToBlockType →
     core/list + replaceBlocks through rec.wpRegistry) — the legacy funnel
     could not survive Gutenberg's controlled re-render. Multilevel and the
     indents stay deferred unchanged (consensus). */
  var g8=group(true);
  g8.appendChild(btn('•≡',['w7BulletList','Bullet list'],function(){
    /* [v1.1.16 item 2 - consensus] an existing list toggles its official ordered attribute through the sub-registry first; the paragraph transform path stays unchanged. */
    if(hossamToolbarListOrdered(containerId,false)) return;
    hossamToolbarTransformBlock(containerId,'core/list');
  }));
  g8.appendChild(btn('1≡',['w7NumberedList','Numbered list'],function(){
    if(hossamToolbarListOrdered(containerId,true)) return;
    hossamToolbarTransformBlock(containerId,'core/list',{ordered:true});
  }));
  g8.appendChild(btn('⫶≡',['w7Multilevel','Multilevel list'],function(){hossamToolbarCmd(container,'insertUnorderedList');}));
  g8.appendChild(btn('☑≡',['w7Checklist','Checklist'],function(){hossamToolbarCmd(container,'insertHTML','☑ ');}));
  g8.appendChild(btn('⇤',['w7Outdent','Decrease indent'],function(){hossamToolbarCmd(container,'outdent');}));
  g8.appendChild(btn('⇥',['w7Indent','Increase indent'],function(){hossamToolbarCmd(container,'indent');}));
  root.appendChild(g8); root.appendChild(divider());
  /* G9 — legal specialized elements (insert HTML into the editable body) */
  var g9=group(true);
  g9.appendChild(btn('❝',['w7LegalQuote','Legal statutory clause'],function(){
    /* [v1.1.11 item 2 — consensus] the official core/quote transform — a
       real quote block like the heading path above. */
    hossamToolbarTransformBlock(containerId,'core/quote');
  }));
  g9.appendChild(btn('⚠️',['w7Callout','Legal callout / notice box'],function(){hossamToolbarCmd(container,'insertHTML','<div style="border-inline-start:3px solid #C5A028;background:#FDF6B2;padding:8px 10px;">⚠️ </div>');}));
  g9.appendChild(btn('🔖',['w7Footnote','Insert footnote and citation'],function(){
    var txt=hossamToolbarPrompt(t('w7PromptFootnote','Footnote text:'));
    if(txt) hossamToolbarCmd(container,'insertHTML','<sup>['+String(txt).replace(/</g,'&lt;')+']</sup>');
  }));
  g9.appendChild(btn('⊞',['w7ComparisonTable','Insert legal comparison table'],function(){
    /* [v1.1.9 item 1 — consensus wave 1] the official core/table block
       through the store (insertBlocks) instead of raw HTML the engine
       reverted — the legal comparison table becomes a real editable block. */
    hossamToolbarInsertBlock(containerId,'core/table');
  }));
  g9.appendChild(btn('📂',['w7Collapsible','Insert collapsible section'],function(){
    /* [v1.1.9 item 1 — consensus wave 1] the official core/details block
       through the store (insertBlocks) — a real collapsible section. */
    hossamToolbarInsertBlock(containerId,'core/details');
  }));
  /* [W8] v1.1.4 item 8: the Add-block bridge — a toolbar button opening the
     official Inserter's own popover (zero engine touch); the internal inserter
     row is hidden by CSS (item 8's CSS-only hide, documented [B9-U6]). The
     same available blocks per role govern the popover (OP-2). */
  g9.appendChild(btn('＋',['w7AddBlock','Add block'],function(){
    try{
      var trigger=container.querySelector('[data-role="inserter"] button');
      if(trigger) trigger.click();
    }catch(e){}
  }));
  g9.appendChild(btn('🔗',['w7RefLink','Insert reference link'],function(){
    var url=hossamToolbarPrompt(t('w7PromptLink','Link URL:'));
    /* [v1.1.16 item 5 - consensus] the official core/link format through the
       wave-2 channel (the legacy funnel was measured dead-silent live with a
       valid selection and an answered prompt). The clear-exceptions list
       keeps core/link byte-exact - clear formatting never strips links. */
    if(url) hossamToolbarApplyFormat(containerId,'core/link',{url:url});
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
  g9.appendChild(btn('—',['w7Divider','Divider line'],function(){
    /* [v1.1.9 item 1 — consensus wave 1] the official core/separator block
       through the store (insertBlocks) instead of the reverted in-paragraph
       rule. */
    hossamToolbarInsertBlock(containerId,'core/separator');
  }));
  root.appendChild(g9);
  /* [W8] v1.1.4 item 3 (OP-2): the More fold is admin-only — the server-side
     adminExtend flag (computed in setup.php, consumed here from cfg) gates the
     toggle and adds the fold-capable display class; every other role keeps its
     allowed groups visible in the scrollable row with no missing fold. Display
     gate only — the endpoint re-verifies server-side on every save.
     [v1.1.5 item 25] the More click behavior is wired in hossamToolbarMount —
     the only scope where the unified close path (drawerClose) exists — so the
     drawer-context branch can call the identical close function the backdrop
     and the Escape guard call (no bare abstract class toggle anymore). */
  if(cfg&&cfg.adminExtend){
    var moreBtn=el('button','hossam-pt-btn hossam-pt-more-toggle');
    moreBtn.type='button';
    /* [v1.1.15 item 2] the fold state machine carries its aria state (the measured More defect: aria-expanded never mounted). */
    moreBtn.setAttribute('aria-expanded','false');
    moreBtn.title=t('w7MoreTitle','More tools');
    moreBtn.appendChild(el('span',undefined,t('w7More','More')));
    moreBtn.appendChild(el('span','hossam-pt-caret','▾'));
    root.appendChild(moreBtn);
    root.classList.add('hossam-pt-more-capable');
  }
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
/* [W7] the static bottom action bar (the model :2337-2349) — one render path
   for both editor contexts: context=write serves the EXISTING create-form
   contract, context=edit serves the EXISTING update-form contract (the edit
   save model posts.php:586 with its actions) — no third path, no duplicated
   logic. [W8] v1.1.4 item 6: the bar is mounted for both contexts. */
function hossamToolbarBuildActionBar(container,context){
  var doc=document;
  context=(context==='edit')?'edit':'write';
  var editorId=context==='edit'?'hossam-rich-editor-edit':'hossam-rich-editor-new';
  var textareaId=context==='edit'?'hossam-edit-content':'hossam-new-content';
  function mk(labelKey,labelText,cls,fn){
    var b=doc.createElement('button');
    b.type='button'; b.className=cls; b.textContent=t(labelKey,labelText);
    b.addEventListener('click',function(){try{fn();}catch(e){}});
    return b;
  }
  var bar=doc.createElement('div');
  bar.className='action-bottom-bar';
  bar.setAttribute('data-hossam-bottom-bar','');
  bar.setAttribute('data-hossam-bar-context',context);
  var left=doc.createElement('div'); left.className='hossam-bar-left';
  var canPublish=false;
  try{
    var st=doc.getElementById('hossam-'+(context==='edit'?'edit':'new')+'-status');
    canPublish=!!(st&&st.querySelector('option[value="publish"]'));
  }catch(e){}
  function submitWith(status){
    try{
      if(context==='edit'){
        var stE=doc.getElementById('hossam-edit-status');
        if(stE&&status) stE.value=status;
        var fe=doc.getElementById('hossam-post-edit-form');
        if(fe&&typeof hossamUpdateArticle==='function') hossamUpdateArticle(fe);
        return;
      }
      var st=doc.getElementById('hossam-new-status');
      if(st&&status) st.value=status;
      var f=doc.getElementById('hossam-post-create-form');
      if(f&&typeof hossamCreateArticle==='function') hossamCreateArticle(f);
    }catch(e){}
  }
  if(canPublish){
    left.appendChild(mk('w7PublishNow','Publish Article Now','btn btn-gold',function(){submitWith('publish');}));
  }
  left.appendChild(mk('w7SaveDraft','Save as Draft','btn btn-outline',function(){submitWith('draft');}));
  bar.appendChild(left);
  bar.appendChild(mk('w7Discard','Discard Changes','btn btn-outline hossam-bar-discard',function(){
    /* [W8] v1.1.4 item 9: the confirm guard with the model's verbatim text —
       confirm executes the existing discard path; cancel leaves everything
       untouched. Present in both contexts through the unified bar. */
    try{
      if(!window.confirm('Discard changes and restore previous snapshot?')) return;
    }catch(e){}
    try{
      var ta=doc.getElementById(textareaId);
      var rec=hossamRichState&&hossamRichState.inited?hossamRichState.inited[editorId]:null;
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
   container (the write editor only). Called once per successful init.
   [W8] v1.1.4 item 4: the external drawer trigger — the drawer's only key
   used to live inside the sliding container itself, so it vanished with the
   drawer closed. A trigger OUTSIDE the sliding container toggles
   hossam-pt-more-open for every role; Escape and the clickable backdrop close
   it and restore focus to the trigger. Display gate only — the endpoint
   re-verifies server-side on every save (OP-2/OP-3). */
function hossamToolbarMount(containerId,container){
  try{
    if(!container||container.querySelector('.hossam-pro-toolbar-container')) return;
    var cfg=(typeof window!=='undefined'&&window.hossamRichEditor)||{};
    var bar=hossamToolbarBuild(containerId,container,cfg);
    var mount=container.querySelector('[data-role="toolbar"]');
    if(mount&&mount.insertBefore) mount.insertBefore(bar,mount.firstChild);
    else container.insertBefore(bar,container.firstChild);
    var host=mount||container;
    var backdrop=document.createElement('div');
    backdrop.className='hossam-pt-drawer-backdrop';
    var trigger=document.createElement('button');
    trigger.type='button';
    trigger.className='hossam-pt-drawer-trigger';
    trigger.setAttribute('aria-expanded','false');
    var drawerId='hossam-pt-drawer-'+(containerId||'editor');
    bar.id=drawerId;
    trigger.setAttribute('aria-controls',drawerId);
    var trigLabel=document.createElement('span');
    trigLabel.setAttribute('aria-hidden','true');
    trigLabel.textContent='☰';
    trigger.appendChild(trigLabel);
    var trigText=document.createElement('span');
    trigText.textContent=t('w7DrawerTools','Tools');
    trigger.appendChild(trigText);
    var drawerClose=function(refocus){
      try{
        bar.classList.remove('hossam-pt-more-open');
        backdrop.classList.remove('hossam-pt-open');
        trigger.setAttribute('aria-expanded','false');
        /* [v1.1.15 item 2] the More fold's aria state rides the same close path. */
        var mt=bar.querySelector('.hossam-pt-more-toggle');
        if(mt&&mt.setAttribute) mt.setAttribute('aria-expanded','false');
        if(refocus&&trigger.focus) trigger.focus();
      }catch(e){}
    };
    trigger.addEventListener('click',function(ev){
      try{
        ev.preventDefault();
        var open=!bar.classList.contains('hossam-pt-more-open');
        bar.classList.toggle('hossam-pt-more-open',open);
        backdrop.classList.toggle('hossam-pt-open',open);
        trigger.setAttribute('aria-expanded',open?'true':'false');
      }catch(e){}
    });
    backdrop.addEventListener('click',function(){try{drawerClose(true);}catch(e){}});
    document.addEventListener('keydown',function(ev){
      try{
        if((ev.key||'')!=='Escape') return;
        if(!bar.classList.contains('hossam-pt-more-open')) return;
        drawerClose(true);
      }catch(e){}
    });
    /* [v1.1.5 item 25 — the named complementary fix (§86/§94 text)] the More
       handler, in the drawer context — the band where the container is the
       off-canvas drawer and the drawer is open — calls the unified close
       path itself (drawerClose above, the identical function the backdrop
       click and the Escape guard call) instead of the bare abstract class
       toggle: class, backdrop, aria-expanded and the Escape guard's state
       test move as one synchronized state machine, and focus returns to the
       external trigger. The JS gate reads the SAME breakpoint the CSS uses
       (≤768 — the exact media query, one shared value, no drift); the fold
       band (769-1024) keeps the shipped bare toggle semantics unchanged
       (v1.1.4 item 3's fold acceptance untouched). */
    var moreSync=bar.querySelector('.hossam-pt-more-toggle');
    if(moreSync){
      moreSync.addEventListener('click',function(ev){
        try{
          ev.preventDefault();
          var drawerBand=false;
          try{ drawerBand=!!(window.matchMedia&&window.matchMedia('(max-width: 768px)').matches); }catch(eM){ drawerBand=false; }
          if(drawerBand&&bar.classList.contains('hossam-pt-more-open')){ drawerClose(true); return; }
          bar.classList.toggle('hossam-pt-more-open');
          /* [v1.1.15 item 2] the aria state is part of the fold state machine. */
          moreSync.setAttribute('aria-expanded',bar.classList.contains('hossam-pt-more-open')?'true':'false');
        }catch(e){}
      });
    }
    host.insertBefore(backdrop,host.firstChild);
    host.insertBefore(trigger,backdrop.nextSibling);
    /* [W8] v1.1.4 item 6: the bottom bar mounts for both contexts — one render
       path (context=write|edit) targeting each context's existing save
       contract. */
    if(containerId==='hossam-rich-editor-new'||containerId==='hossam-rich-editor-edit'){
      container.appendChild(hossamToolbarBuildActionBar(container,containerId==='hossam-rich-editor-edit'?'edit':'write'));
    }
  }catch(e){}
}
