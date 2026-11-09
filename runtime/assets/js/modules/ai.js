/**
 * assets/js/modules/ai.js
 * ══════════════════════════════════════════════════════════════
 * Role: the client side of the asynchronous Job Queue system — sending
 * the job, polling its status every ~2 seconds, showing the result or
 * the error.
 *
 * Architectural decision: dashboard-architecture-map.php, section 9.1
 * (decision 11)
 * Linked tasks: T-49
 *
 * Depends on dashboard.js (dependency in wp_enqueue_script):
 *   t(), esc(), showToast() — these must be defined beforehand.
 *
 * Usage from template-parts/dashboard/posts.php (third batch — buttons
 * conditional on server-side proof of a valid strategy capability and
 * the user's permission):
 *   <button class="js-ai-run" data-job-type="grammar"
 *           data-source="hossam-edit-content"
 *           data-result="ai-suggestion-result-edit">
 * This module never sees strategy_id, nor a provider name, nor any
 * secret — it sends job_type and the content, and receives job_id, the
 * status and the safe result only.
 * ══════════════════════════════════════════════════════════════
 */
'use strict';

const HOSSAM_AI_POLL_INTERVAL_MS = 2000;
const HOSSAM_AI_MAX_POLL_ATTEMPTS = 60; // ~120 seconds upper cap — prevents endless polling if a job stalls

/**
 * The single entry point required from the posts.php UI — it handles
 * submission, polling and display entirely internally.
 *
 * @param {string} jobType 'grammar'|'translation'|'seo'|'improvement'
 * @param {object} data    { content, title?, target_lang?, post_id? }
 * @param {string} resultElId id of the HTML element in which the
 *                            result/error is displayed
 */
function hossamAiRun(jobType, data, resultElId) {
  const resultEl = document.getElementById(resultElId);
  if (!resultEl || !window.hossamAjax) return Promise.resolve();

  // Ownership of the element begins before submit: even if an old submit reply returns
  // out of order, it cannot start polling or alter the display of the newer attempt.
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
 * Periodic polling of a job's status until completed/failed or the
 * maximum attempt count is reached.
 */
function hossamAiPoll(jobId, resultEl, isRunCurrent, registerCancel) {
  let attempts = 0;
  /* [B10-02] settled blocks a late reply after a final state; inFlight
     prevents polling requests from interleaving; attempt ownership is
     settled at entry into hossamAiRun. */
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
      if (!isCurrent()) { done(null); return; } // superseded attempt exits silently without drawing
      attempts++;

      if (attempts > HOSSAM_AI_MAX_POLL_ATTEMPTS) {
        done(`<div class="ph-notice" style="color:var(--danger)">${t('aiTimeout', 'The AI request took too long. Please try again.')}</div>`);
        return;
      }

      if (inFlight) return; // overlapping tick is skipped; attempts is still counted so the timeout stays time-based
      inFlight = true;
      try {
        const fd = new FormData();
        fd.append('action', 'hossam_ai_get_job_status');
        fd.append('nonce', hossamAjax.nonce);
        fd.append('job_id', jobId);

        const r = await fetch(hossamAjax.ajaxurl, { method: 'POST', body: fd });
        const d = await r.json();

        if (!isCurrent()) return; // late reply after a final state or supersession: ignored
        if (!d.success || !d.data) return; // ignore a single transient poll failure, retry

        if (d.data.status === 'completed') {
          done(null);
          if (isRunCurrent()) hossamAiRenderResult(resultEl, d.data.result);
        } else if (d.data.status === 'failed') {
          done(`<div class="ph-notice" style="color:var(--danger)">${esc(d.data.error || t('aiFailed', 'AI request failed.'))}</div>`);
        }
        // pending/processing: keep polling silently
      } catch (e) {
        // transient network error while polling — do not stop the loop, the next attempt may succeed
      } finally {
        inFlight = false;
      }
    }, HOSSAM_AI_POLL_INTERVAL_MS);
  });
}

/**
 * Renders the final result + the Apply/Dismiss buttons. "Apply" is left
 * to dedicated logic in posts.php (because it depends on which form field
 * the result must be inserted into).
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
 * Delegated wiring for the .js-ai-run buttons (third batch, section 7.4)
 * — reads the data attributes from the button and calls hossamAiRun. It
 * decides no permission locally: the buttons themselves are rendered
 * server-side after the capability and permission are proven, and the
 * endpoint re-verifies.
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
