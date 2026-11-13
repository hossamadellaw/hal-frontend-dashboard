<?php
/**
 * runtime/templates/dashboard/finance.php — Finance & Payments panel (batch 8)
 * ══════════════════════════════════════════════════════════════
 * Source: legacy theme/template-parts/dashboard/finance.php (byte-level
 * source fingerprint pinned in the batch 8 inventory — snapshot 5CD55D5D…).
 *
 * The sole authorized delta (owner decision B3-08 — option a): the owner
 * gate is_feature_enabled('finance') after the source guard
 * is_user_logged_in() — early return; fail-safe with no output. The
 * Overview/Orders/Invoices/Payment Methods/Saved Cards tabs and their
 * empty containers (filled via finance.js) and the registry states remain
 * as-is — manager/customer visibility is decided by the server in AJAX
 * (contract §17) and the template neither renders on its own authority
 * nor writes anything (contract §19).
 *
 * Explicit args: the panel has no variables from the shell — all of its
 * decisions come from is_user_logged_in and the registry; it is called
 * from render_part with the documented args list.
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! is_user_logged_in() ) {
    return;
}
if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'finance' ) ) {
    return;
}
$finance_integration = function_exists( 'hossam_get_integration_decision' )
    ? hossam_get_integration_decision( 'woocommerce' )
    : [ 'status' => 'unavailable', 'reason' => '', 'capabilities' => [] ];
$finance_orders_ready = 'unavailable' !== $finance_integration['status']
    && ! empty( $finance_integration['capabilities']['orders'] );
?>
    <!-- ══ PANEL: FINANCE ══ -->
    <div class="panel" id="panel-finance">
      <div class="page-hdr">
        <div class="page-breadcrumb">
          <span><?php echo esc_html( hossam_t( 'Dashboard' ) ); ?></span><span>›</span>
          <span class="crumb-active"><?php echo esc_html( hossam_t( 'Finance' ) ); ?></span>
        </div>
        <h1><?php echo esc_html( hossam_t( 'Finance & Payments' ) ); ?></h1>
        <p><?php echo esc_html( hossam_t( 'WooCommerce status' ) . ': ' . $finance_integration['status'] ); ?></p>
      </div>
      <?php if ( ! $finance_orders_ready ) : ?>
      <div class="card"><div class="ph-notice"><?php echo esc_html( $finance_integration['reason'] ?: hossam_t( 'WooCommerce not active' ) ); ?></div></div>
      <?php else : ?>
      <div class="tab-group" data-tab-group="finance">
        <div class="tab-bar mb-14">
          <button class="tab-btn active" data-tab="overview" onclick="switchTab(this)"><?php echo esc_html( hossam_t( 'Overview' ) ); ?></button>
          <button class="tab-btn" data-tab="orders" onclick="switchTab(this)"><?php echo esc_html( hossam_t( 'Orders' ) ); ?></button>
          <button class="tab-btn" data-tab="invoices" onclick="switchTab(this)"><?php echo esc_html( hossam_t( 'Invoices' ) ); ?></button>
          <button class="tab-btn" data-tab="payment-methods" onclick="switchTab(this)"><?php echo esc_html( hossam_t( 'Payment Methods' ) ); ?></button>
          <button class="tab-btn" data-tab="saved-cards" onclick="switchTab(this)"><?php echo esc_html( hossam_t( 'Saved Cards' ) ); ?></button>
        </div>

        <div class="tab-content active" data-tab-content="overview">
          <div class="card">
            <div class="card-title mb-12"><?php echo esc_html( hossam_t( 'Payment History' ) ); ?></div>
            <div id="finance-kpi" class="fin-kpi-grid"></div>
            <div id="finance-table-wrap">
              <div class="ph-notice"><?php echo esc_html( hossam_t( 'Loading…' ) ); ?></div>
            </div>
          </div>
        </div>
        <div class="tab-content" data-tab-content="orders" style="display:none">
          <div class="card">
            <div id="finance-orders-wrap">
              <div class="ph-notice"><?php echo esc_html( hossam_t( 'Loading…' ) ); ?></div>
            </div>
          </div>
        </div>
        <div class="tab-content" data-tab-content="invoices" style="display:none">
          <div class="card">
            <div id="finance-invoices-wrap">
              <div class="ph-notice"><?php echo esc_html( hossam_t( 'Loading…' ) ); ?></div>
            </div>
          </div>
        </div>
        <div class="tab-content" data-tab-content="payment-methods" style="display:none">
          <div class="card">
            <div id="finance-payment-methods-wrap">
              <div class="ph-notice"><?php echo esc_html( hossam_t( 'Loading…' ) ); ?></div>
            </div>
          </div>
        </div>
        <div class="tab-content" data-tab-content="saved-cards" style="display:none">
          <div class="card">
            <div id="finance-saved-cards-wrap">
              <div class="ph-notice"><?php echo esc_html( hossam_t( 'Loading…' ) ); ?></div>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div><!-- /panel-finance -->
