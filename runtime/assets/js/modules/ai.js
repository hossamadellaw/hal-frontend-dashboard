/**
 * assets/js/modules/ai.js
 * ══════════════════════════════════════════════════════════════
 * الدور: جانب العميل لنظام Job Queue غير المتزامن — إرسال المهمة،
 * استطلاع حالتها كل ~2 ثانية، عرض النتيجة أو الخطأ.
 *
 * القرار المعماري: dashboard-architecture-map.php، قسم 9.1 (قرار 11)
 * المهام المرتبطة: T-49
 *
 * يعتمد على dashboard.js (dependency فى wp_enqueue_script):
 *   t()، esc()، showToast() — يجب أن تكون معرَّفة مسبقًا.
 *
 * الاستخدام من template-parts/dashboard/posts.php (الدفعة الثالثة —
 * أزرار مشروطة بإثبات خادمي لقدرة استراتيجية صالحة وصلاحية المستخدم):
 *   <button class="js-ai-run" data-job-type="grammar"
 *           data-source="hossam-edit-content"
 *           data-result="ai-suggestion-result-edit">
 * لا ترى هذه الوحدة strategy_id ولا اسم مزود ولا أي سر — تُرسل job_type
 * والمحتوى وتستقبل job_id والحالة والنتيجة الآمنة فقط.
 * ══════════════════════════════════════════════════════════════
 */
'use strict';

const HOSSAM_AI_POLL_INTERVAL_MS = 2000;
const HOSSAM_AI_MAX_POLL_ATTEMPTS = 60; // ~120 ثانية سقف أعلى — يمنع استطلاعًا أبديًا لو تعطَّلت مهمة

/**
 * نقطة الدخول الوحيدة المطلوبة من واجهة posts.php — تتولى الإرسال
 * والاستطلاع والعرض بالكامل داخليًا.
 *
 * @param {string} jobType 'grammar'|'translation'|'seo'|'improvement'
 * @param {object} data    { content, title?, target_lang?, post_id? }
 * @param {string} resultElId معرِّف عنصر HTML لعرض النتيجة/الخطأ فيه
 */
function hossamAiRun(jobType, data, resultElId) {
  const resultEl = document.getElementById(resultElId);
  if (!resultEl || !window.hossamAjax) return Promise.resolve();

  // امتلاك العنصر يبدأ قبل submit: حتى لو عاد رد إرسال قديم بترتيب معكوس
  // لا يستطيع بدء polling أو تغيير عرض المحاولة الأحدث.
  const previous = resultEl._hossamAiSupersede;
  const epoch = (resultEl._hossamAiEpoch = (resultEl._hossamAiEpoch || 0) + 1);
  let finished = false;
  let stopPoll = null;
  let resolveRun;
  const runPromise = new Promise((resolve) => { resolveRun = resolve; });
  const isCurrent = () => !finished && resultEl._hossamAiEpoch === epoch;
  const finish = () => {
    if (finished) return;
    finished = true;
    if (stopPoll) stopPoll();
    if (resultEl._hossamAiSupersede === finish) delete resultEl._hossamAiSupersede;
    resolveRun();
  };
  resultEl._hossamAiSupersede = finish;
  if (typeof previous === 'function') previous();

  resultEl.innerHTML = `<div class="ph-notice">${t('aiThinking', 'AI is working on it…')}</div>`;
  resultEl.classList.remove('hidden');

  (async () => { try {
    const submitFd = new FormData();
    submitFd.append('action', 'hossam_ai_submit_job');
    submitFd.append('nonce', hossamAjax.nonce);
    submitFd.append('job_type', jobType);
    submitFd.append('content', data.content || '');
    if (data.title) submitFd.append('title', data.title);
    if (data.target_lang) submitFd.append('target_lang', data.target_lang);
    if (data.post_id) submitFd.append('post_id', data.post_id);

    const submitRes = await fetch(hossamAjax.ajaxurl, { method: 'POST', body: submitFd });
    if (!isCurrent()) return;
    const submitJson = await submitRes.json();
    if (!isCurrent()) return;

    if (!submitJson.success || !submitJson.data || !submitJson.data.job_id) {
      resultEl.innerHTML = `<div class="ph-notice" style="color:var(--danger)">${t('aiSubmitFailed', 'Could not start the AI request.')}</div>`;
      return;
    }

    await hossamAiPoll(submitJson.data.job_id, resultEl, isCurrent, (cancel) => {
      stopPoll = cancel;
      if (!isCurrent()) cancel();
    });
  } catch (e) {
    if (isCurrent()) resultEl.innerHTML = `<div class="ph-notice" style="color:var(--danger)">${t('networkError', 'Network error')}</div>`;
  } finally {
    finish();
  } })();
  return runPromise;
}

/**
 * استطلاع دوري لحالة مهمة حتى completed/failed أو بلوغ الحد الأقصى للمحاولات.
 */
function hossamAiPoll(jobId, resultEl, isRunCurrent, registerCancel) {
  let attempts = 0;
  /* [B10-02] settled يمنع الرد المتأخر بعد حالة نهائية؛ inFlight يمنع
     تداخل طلبات polling؛ ملكية المحاولة تُحسم عند دخول hossamAiRun. */
  let settled = false;
  let inFlight = false;
  const isCurrent = () => !settled && isRunCurrent();

  return new Promise((resolve) => {
    const timerRef = { id: null };
    const settleQuiet = () => {
      if (settled) return;
      settled = true;
      if (timerRef.id !== null) clearInterval(timerRef.id);
      resolve();
    };
    registerCancel(settleQuiet);
    if (!isCurrent()) { settleQuiet(); return; }
    const done = (html) => {
      if (!isCurrent()) { settleQuiet(); return; }
      if (html !== null) resultEl.innerHTML = html;
      settleQuiet();
    };
    timerRef.id = setInterval(async () => {
      if (!isCurrent()) { done(null); return; } // محاولة مستبدَلة تخرج بصمت دون رسم
      attempts++;

      if (attempts > HOSSAM_AI_MAX_POLL_ATTEMPTS) {
        done(`<div class="ph-notice" style="color:var(--danger)">${t('aiTimeout', 'The AI request took too long. Please try again.')}</div>`);
        return;
      }

      if (inFlight) return; // tick متداخل يُتخطى؛ attempts يُحتسب ليبقى timeout زمنيًا
      inFlight = true;
      try {
        const fd = new FormData();
        fd.append('action', 'hossam_ai_get_job_status');
        fd.append('nonce', hossamAjax.nonce);
        fd.append('job_id', jobId);

        const r = await fetch(hossamAjax.ajaxurl, { method: 'POST', body: fd });
        const d = await r.json();

        if (!isCurrent()) return; // رد متأخر بعد حالة نهائية أو استبدال: يُتجاهل
        if (!d.success || !d.data) return; // تجاهل فشل استطلاع عابر واحد، حاول مجددًا

        if (d.data.status === 'completed') {
          done(null);
          if (isRunCurrent()) hossamAiRenderResult(resultEl, d.data.result);
        } else if (d.data.status === 'failed') {
          done(`<div class="ph-notice" style="color:var(--danger)">${esc(d.data.error || t('aiFailed', 'AI request failed.'))}</div>`);
        }
        // pending/processing: استمر فى الاستطلاع بصمت
      } catch (e) {
        // خطأ شبكة عابر أثناء الاستطلاع — لا نوقف الحلقة، المحاولة التالية قد تنجح
      } finally {
        inFlight = false;
      }
    }, HOSSAM_AI_POLL_INTERVAL_MS);
  });
}

/**
 * عرض النتيجة النهائية + زرَّا تطبيق/تجاهل. "تطبيق" يُترَك لمنطق مخصَّص
 * فى posts.php (لأنه يعتمد على أي حقل فى الفورم يجب إدراج النتيجة فيه).
 */
function hossamAiRenderResult(resultEl, result) {
  if (!result || !result.text) {
    resultEl.innerHTML = `<div class="ph-notice">${t('aiNoResult', 'No suggestion returned.')}</div>`;
    return;
  }

  resultEl.innerHTML = `
    <div class="card card-sm">
      <div class="card-sub mb-8">${t('aiSuggestion', 'AI suggestion')}</div>
      <div class="ai-suggestion-text">${esc(result.text)}</div>
      <div class="form-actions mt-8">
        <button class="btn btn-gold btn-sm js-ai-apply">${t('aiApply', 'Apply')}</button>
        <button class="btn btn-ghost btn-sm" onclick="this.closest('.card').parentElement.classList.add('hidden')">${t('aiDismiss', 'Dismiss')}</button>
      </div>
    </div>`;
}

/**
 * موصِّل أزرار .js-ai-run (الدفعة الثالثة، القسم 7.4) — يقرأ سمات data
 * من الزر ويستدعي hossamAiRun. لا يقرر أي صلاحية محليًا: الأزرار نفسها
 * تُعرض خادميًا بعد إثبات القدرة والصلاحية، والـendpoint يعيد التحقق.
 */
document.addEventListener('click', function (e) {
  const btn = e.target.closest('.js-ai-run');
  if (!btn || !window.hossamAjax) return;

  e.preventDefault();

  const sourceEl = btn.dataset.source ? document.getElementById(btn.dataset.source) : null;
  const data = { content: sourceEl ? sourceEl.value : '' };

  if (btn.dataset.titleSource) {
    const titleEl = document.getElementById(btn.dataset.titleSource);
    if (titleEl) data.title = titleEl.value;
  }
  if (btn.dataset.langSource) {
    const langEl = document.getElementById(btn.dataset.langSource);
    if (langEl && langEl.value.trim()) data.target_lang = langEl.value.trim();
  }
  if (btn.dataset.postId) {
    const pid = parseInt(btn.dataset.postId, 10);
    if (pid > 0) data.post_id = pid;
  }

  hossamAiRun(btn.dataset.jobType, data, btn.dataset.result);
});
