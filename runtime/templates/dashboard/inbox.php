<?php
/**
 * runtime/templates/dashboard/inbox.php — Inbox panel (batch 8)
 * ══════════════════════════════════════════════════════════════
 * Source: legacy theme/template-parts/dashboard/inbox.php (byte-level
 * source fingerprint pinned in the batch 8 inventory — snapshot 5CD55D5D…).
 *
 * The sole authorized delta (owner decision B3-08 — option a): the owner
 * gate is_feature_enabled('inbox') after the ABSPATH guard — early
 * return; fail-safe with no output. The containers (#inbox-container)
 * and the admin send form (the $is_admin condition from the shell)
 * remain as-is — no write operations inside the template (contract
 * §19); loading and sending happen exclusively through the AJAX
 * endpoints and filling is done via inbox.js.
 *
 * Explicit args: it consumes $is_admin from the shell (the send form) —
 * the rest of the content is empty containers; it is called from
 * render_part with the documented args list.
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
