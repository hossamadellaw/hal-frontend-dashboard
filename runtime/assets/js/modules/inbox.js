/**
 * assets/js/modules/inbox.js
 * ══════════════════════════════════════════════════════════════
 * المهام المرتبطة: T-22
 * الدوال المنقولة حرفيًا: loadNotifications(), markNotifRead(id, el),
 * clearNotifs(), loadInbox(), markInboxRead(id, el), sendInboxMessage()
 *
 * ⚠ loadNotifications() تُستدعى بـpolling كل 60 ثانية من DOMContentLoaded
 * فى dashboard.js (الـshell) — التأكد أن هذا الاستدعاء الدوري ينتقل
 * معها بشكل صحيح أو يبقى فى الـshell مع نداء للدالة المنقولة هنا
 * (الدالة نفسها تنتقل، نقطة استدعاء الـpolling قرار تنفيذي: تبقى فى
 * dashboard.js لأنها جزء من تهيئة الصفحة العامة، لا خاصة ببانل Inbox فقط).
 *
 * TODO:
 * [x] نقل الست دوال حرفيًا — موجودة فعليًا أدناه.
 * [x] تستدعي: hossam_get_notifications، hossam_mark_read،
 *     hossam_mark_all_read، hossam_send_message، hossam_get_inbox،
 *     hossam_mark_message_read (ajax/inbox.php) — تحققتُ من
 *     ajax/inbox.php الفعلي: كل الست مطابقة حرفيًا (أسماء
 *     الحقول، شكل الاستجابة).
 *
 * ⚠ خطأ حقيقي مكتشف وأُصلح فى هذه النسخة: حرف + زائد كان موجودًا
 * قبل تعليق NOTIFICATIONS مباشرة، يحوّل async function clearNotifs(){...}
 * من إعلان (hoisted declaration) إلى named function expression — يمنع
 * ربط الاسم clearNotifs بالنطاق الخارجي نهائيًا. تحققتُ منه تجريبيًا
 * عبر Node.js: typeof clearNotifs يعطي "undefined" مع الحرف، "function"
 * بعد حذفه. زر "Mark all read" فى page-dashboard.php
 * (onclick="clearNotifs()"، سطر 557) كان مكسورًا فعلاً لكل مستخدم
 * يفتح اللوحة. حذفتُ الحرف فقط.
 * ══════════════════════════════════════════════════════════════
 */

/* ════════════════════════════════════════════════════════════════
   NOTIFICATIONS
════════════════════════════════════════════════════════════════ */
async function hossamInboxRequest(action, fields={}){
  const fd=new FormData();
  fd.append("action",action);
  fd.append("nonce",hossamAjax.nonce);
  Object.entries(fields).forEach(([key,value])=>fd.append(key,value));
  let response,text;
  try{
    response=await fetch(hossamAjax.ajaxurl,{method:"POST",body:fd});
    text=await response.text();
  }catch(networkError){ /* transport-level only: show the translated message, not the raw browser error */
    throw new Error(t('networkError','Network error'));
  }
  let payload;
  try{payload=JSON.parse(text);}catch(error){throw new Error(t('contractError','Invalid server response'));}
  if(!response.ok||!payload||payload.success!==true){
    throw new Error(payload?.data?.message||t('requestFailed','Request failed'));
  }
  return payload.data;
}

function hossamSetInboxBadges(unread){
  ["inbox-badge","hdr-inbox-badge"].forEach(id=>{
    const badge=document.getElementById(id);
    if(!badge)return;
    badge.textContent=unread||"";
    badge.classList.toggle("hidden",unread===0);
  });
}

async function clearNotifs(){
  if(!window.hossamAjax) return;
  try{
    await hossamInboxRequest("hossam_mark_all_read");
    const badge=document.getElementById("notif-badge");
    if(badge){badge.textContent="0";badge.classList.add("hidden");}
    await loadNotifications();
    showToast(t('allMarkedRead','All marked as read'),'success');
  }catch(e){showToast(e.message||t('networkError','Network error'),'error');}
}

async function loadNotifications(){
  if(!window.hossamAjax) return;
  try{
    const data=await hossamInboxRequest("hossam_get_notifications");
    if(!Array.isArray(data))throw new Error(t('contractError','Invalid server response'));
    const unread=data.filter(n=>String(n.is_read)==="0").length;
    const badge=document.getElementById("notif-badge");
    if(badge){
      badge.textContent=unread;
      badge.classList.toggle("hidden",unread===0);
    }
    const list=document.getElementById("notif-list");
    if(!list) return;
    /* [J-4] empty state + [J-06] URL + message_text */
    list.innerHTML=data.length
      ? data.map(n=>{
          const msgHtml=n.url
            ?`<a href="${esc(n.url)}" class="notif-msg">${esc(n.message_text||n.message)}</a>`
            :`<div class="notif-msg">${esc(n.message_text||n.message)}</div>`;
          return `<div class="notif-item${String(n.is_read)==="0"?" unread":""}"
            onclick="markNotifRead(${parseInt(n.id,10)||0},this,event)" data-id="${parseInt(n.id,10)||0}">
            ${msgHtml}
            <div class="notif-time">${esc(n.created_at)}</div>
          </div>`;
        }).join("")
      : '<div class="notif-empty">'+t('notifEmpty','No notifications at this time.')+'</div>';
  }catch(e){
    const list=document.getElementById("notif-list");
    if(list)list.innerHTML='<div class="notif-empty">'+esc(e.message||t('networkError','Network error'))+'</div>';
  }
}

async function markNotifRead(id, el, event){
  if(!window.hossamAjax) return;
  const targetUrl=event?.target?.closest?.('a')?.href||'';
  if(targetUrl)event.preventDefault();
  if(!el?.classList?.contains('unread')){
    if(targetUrl)window.location.assign(targetUrl);
    return;
  }
  try{
    await hossamInboxRequest("hossam_mark_read",{notification_id:id});
    el?.classList.remove("unread");
    await loadNotifications();
  }catch(e){console.warn("markNotifRead:",e);}
  finally{
    if(targetUrl)window.location.assign(targetUrl);
  }
}

/* ════════════════════════════════════════════════════════════════
   INBOX
════════════════════════════════════════════════════════════════ */
async function markInboxRead(id, el){
  if(!window.hossamAjax) return;
  try{
    await hossamInboxRequest("hossam_mark_message_read",{message_id:id});
    /* [B10-01] نجاح التأشير يقلب صفًا واحدًا فقط (الخادم: UPDATE بشرط
       read_at IS NULL، وغيره 404)؛ ننقص الإجمالي الدقيق المعروض واحدًا
       بدل انتظار إعادة قد تشتق من قائمة محدودة. */
    hossamDecrementInboxBadges();
    el?.querySelector('.act-dot')?.classList.replace('warn','gold');
    el?.querySelector('.pill')?.remove();
    await loadInbox();
  }catch(e){console.warn("markInboxRead:",e);}
}

/* [B10-01] إنقاص شارتَي Inbox واحدًا بعد قراءة مؤكدة. المعروض دقيق
   بحكم البناء: إجمالي COUNT(*) خادمي عند الرسم + إنقاصات مؤكدة فقط +
   كتابات من قوائم مكتملة (<50) فقط؛ فالإنقاص هنا انتقال دقيق. */
function hossamDecrementInboxBadges(){
  const badge=document.getElementById("inbox-badge")||document.getElementById("hdr-inbox-badge");
  if(!badge)return;
  const current=parseInt(badge.textContent,10);
  hossamSetInboxBadges(isNaN(current)?0:Math.max(0,current-1));
}

async function loadInbox(){
  const box=document.getElementById("inbox-container");
  if(!box||!window.hossamAjax) return;
  /* [J-03] skeleton loading */
  box.innerHTML=skeletonHTML(4);
  try{
    const data=await hossamInboxRequest("hossam_get_inbox");
    if(!Array.isArray(data))throw new Error(t('contractError','Invalid server response'));
    /* [J-6] empty state — i18n */
    if(!data.length){
      box.innerHTML='<div class="ph-notice">'+t('noMessages','No messages yet.')+'</div>';
      hossamSetInboxBadges(0);
      return;
    }
    /* [B10-01] القائمة الخادمية محدودة بأحدث 50 رسالة (LIMIT 50) بلا
       إجمالي؛ الشارتان تعرضان إجمالي COUNT(*) الدقيق الذي رسمه الـshell
       خادميًا. الكتابة من القائمة مسموحة فقط عند اكتمالها المؤكد
       (أقل من 50) — وإلا يبقى آخر إجمالي دقيق بدل انكماش 51→50 أو
       إخفاء مقروءٍ قديم خارج الأحداث. */
    if(data.length < 50){
      const unread=data.filter(m=>!m.read_at).length;
      hossamSetInboxBadges(unread);
    }
    /* [J-5] New pill + [J-05] sender_name — i18n */
    box.innerHTML=data.map(m=>
      `<div class="activity-item" onclick="markInboxRead(${parseInt(m.id,10)||0},this)">
        <div class="act-dot ${m.read_at?"gold":"warn"}"></div>
        <div class="act-txt">
          ${!m.read_at?'<span class="pill pill-pub" style="font-size:9px">'+t('inboxNew','New')+'</span> ':''}
          ${t('inboxFrom','From: User #')}${esc(m.sender_name||m.sender_id)}<br/>${esc(m.message)}
        </div>
        <div class="act-time">${esc(m.created_at)}</div>
      </div>`).join("");
  /* [J-01] error recovery with retry */
  }catch(e){
    box.innerHTML='<div class="ph-notice" style="color:var(--danger)">'+
      esc(e.message||t('networkError','Network error'))+
      ' <button onclick="loadInbox()" class="btn btn-sm" style="margin-top:8px">'+
      t('retry','Retry')+'</button></div>';
  }
}

async function sendInboxMessage(){
  if(!window.hossamAjax) return;
  const toEl  = document.getElementById("inbox-to");
  const msgEl = document.getElementById("inbox-msg");
  const to  = toEl?.value;
  const msg = msgEl?.value;
  /* [J-7] toasts — i18n */
  if(!to||!msg){showToast(t('fillAllFields','Fill all fields'),'error');return;}
  try{
    await hossamInboxRequest("hossam_send_message",{receiver_id:to,message:msg});
    showToast(t('messageSent','Message sent'),'success');
    if(toEl)  toEl.value  = "";
    if(msgEl) msgEl.value = "";
    await loadInbox();
  }catch(e){showToast(e.message||t('networkError','Network error'),'error');}
}
