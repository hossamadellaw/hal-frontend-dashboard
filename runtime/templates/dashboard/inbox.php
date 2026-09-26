<?php
/**
 * runtime/templates/dashboard/inbox.php — بانل البريد الداخلي (الدفعة 8)
 * ══════════════════════════════════════════════════════════════
 * المصدر: legacy theme/template-parts/dashboard/inbox.php (بصمة
 * المصدر بالبايت مثبتة في inventory الدفعة 8 — snapshot 5CD55D5D…).
 *
 * الدلتا المصرَّح بها الوحيدة (قرار المالك B3-08 — الخيار أ): بوابة
 * المالك is_feature_enabled('inbox') بعد حارس ABSPATH — return
 * مبكر؛ فشل آمن بلا إخراج. الحاويات (#inbox-container) وفورم
 * الإرسال المديري (شرط $is_admin من الـshell) باقية كما هي —
 * بلا عمليات كتابة داخل القالب (عقد §19)؛ التحميل والإرسال عبر
 * endpoints الـAJAX حصرًا وتُملأ عبر inbox.js.
 *
 * args صريحة: يستهلك من الـshell $is_admin (فورم الإرسال) — بقية
 * المحتوى حاويات فارغة؛ يُستدعى من render_part بقائمة args
 * الموثقة.
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'inbox' ) ) {
	return;
}
?>
    <!-- ══ PANEL: MY INBOX ══ -->
    <div class="panel" id="panel-inbox">
      <div class="page-hdr">
        <div class="page-breadcrumb">
          <span><?php echo esc_html( hossam_t( 'Dashboard' ) ); ?></span><span>›</span>
          <span class="crumb-active"><?php echo esc_html( hossam_t( 'My Inbox' ) ); ?></span>
        </div>
        <h1><?php echo esc_html( hossam_t( 'My Inbox' ) ); ?></h1>
        <p><?php echo esc_html( hossam_t( 'Messages from visitors and the administration team.' ) ); ?></p>
      </div>
      <div class="grid <?php echo $is_admin ? 'g2' : 'g1'; ?>">
        <div class="card" id="inbox-container" style="max-height:520px;overflow-y:auto">
          <div class="ph-notice"><?php echo esc_html( hossam_t( 'Loading messages…' ) ); ?></div>
        </div>
        <?php if ( $is_admin ) : ?>
        <div class="card">
          <div class="card-title mb-12"><?php echo esc_html( hossam_t( 'Send Message' ) ); ?></div>
          <div class="form-group mb-8">
            <label class="form-label"><?php echo esc_html( hossam_t( 'To — User ID' ) ); ?></label>
            <input type="number" class="form-input" id="inbox-to" placeholder="<?php echo esc_attr( hossam_t( 'User ID' ) ); ?>" min="1"/>
          </div>
          <div class="form-group mb-8">
            <textarea class="form-input" id="inbox-msg" rows="5"></textarea>
          </div>
          <button class="btn btn-gold" onclick="sendInboxMessage()"><?php echo esc_html( hossam_t( 'Send' ) ); ?></button>
        </div>
        <?php endif; ?>
      </div>
    </div><!-- /panel-inbox -->
