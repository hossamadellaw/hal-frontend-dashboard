/**
 * assets/js/modules/bookings.js
 * ══════════════════════════════════════════════════════════════
 * المهام المرتبطة: T-22
 * الدالة المنقولة حرفيًا: loadAppointmentsPanel()
 *
 * ⚠ لا علاقة لهذا الملف بـhossam_get_upcoming_appointments — ذلك
 * الاستدعاء يبقى فى <script> مضمَّن مباشرة داخل
 * template-parts/dashboard/overview.php (استثناء موثَّق، راجع ملاحظة
 * ajax/appointments.php). هذا الملف يخص فقط loadAppointmentsPanel()
 * التى تستدعي hossam_lazy_appointments (nonce منفصل: hossam_lazy_appointments،
 * يُقرَأ من data-lazy-nonce فى العنصر، لا من hossamAjax.nonce العام).
 *
 * TODO:
 * [x] نقل loadAppointmentsPanel() حرفيًا — مؤكَّد 2026-08-24: الدالة
 *     معرَّفة أدناه (:24) بـnonce منفصل من data-lazy-nonce
 * [x] مسجَّلة فى nav() لتُستدعى عند فتح panelId === 'appointments'
 *     — مؤكَّد 2026-08-24: dashboard.js:104 يستدعيها حرفيًا بهذا الشرط
 * ══════════════════════════════════════════════════════════════
 */

/* ════════════════════════════════════════════════════════════════
   APPOINTMENTS
════════════════════════════════════════════════════════════════ */
/* [Patch 4] Lazy-loaded appointments panel — uses its own per-render nonce */
async function loadAppointmentsPanel(){
  const wrap=document.getElementById("appointments-panel-wrap");
  if(!wrap||!window.hossamAjax) return;
  if(wrap.dataset.loading==='1'||wrap.dataset.loaded==='1') return;
  const nonce=wrap.dataset.lazyNonce;
  if(!nonce) return;
  wrap.dataset.loading='1';
  try{
    const fd=new FormData();
    fd.append("action","hossam_lazy_appointments");
    fd.append("nonce",nonce);
    const r=await fetch(hossamAjax.ajaxurl,{method:"POST",body:fd});
    const raw=await r.text();
    let d;
    try{ d=JSON.parse(raw); }catch(parseError){
      wrap.innerHTML='<div class="ph-notice" style="color:var(--danger)">'+
        t('contractError','Invalid server response')+
        ' <button onclick="loadAppointmentsPanel()" class="btn btn-sm" style="margin-top:8px">'+
        t('retry','Retry')+'</button></div>';
      return;
    }
    if(!r.ok||!d||d.success!==true){
      const code=d&&d.data&&d.data.code?d.data.code:'';
      const denied=r.status===401||r.status===403||code==='unauthorized'||code==='forbidden';
      const unavailable=r.status===503||code==='plugin_missing'||code==='feature_unavailable';
      wrap.innerHTML='<div class="ph-notice" style="color:var(--danger)">'+
        (denied?t('forbidden','You do not have permission to view appointments.'):
          unavailable?t('integrationUnavailable','Appointment integration is unavailable.'):
          t('loadFailed','Could not load appointments.'))+'</div>';
      return;
    }
    if(!d.data||typeof d.data.html!=='string'){
      wrap.innerHTML='<div class="ph-notice" style="color:var(--danger)">'+
        t('contractError','Invalid server response')+
        ' <button onclick="loadAppointmentsPanel()" class="btn btn-sm" style="margin-top:8px">'+
        t('retry','Retry')+'</button></div>';
      return;
    }
    wrap.innerHTML=d.data.html||'<div class="ph-notice">'+t('noAppointments','No appointments yet.')+'</div>';
    wrap.dataset.loaded='1';
  }catch(e){
    wrap.innerHTML='<div class="ph-notice" style="color:var(--danger)">'+
      t('networkError','Network error')+
      ' <button onclick="loadAppointmentsPanel()" class="btn btn-sm" style="margin-top:8px">'+
      t('retry','Retry')+'</button></div>';
  }finally{
    wrap.dataset.loading='0';
  }
}
