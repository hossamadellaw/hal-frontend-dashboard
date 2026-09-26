<?php
/**
 * runtime/templates/dashboard/store.php — بانل المتجر (الدفعة 8)
 * ══════════════════════════════════════════════════════════════
 * المصدر: legacy theme/template-parts/dashboard/store.php (بصمة
 * المصدر بالبايت مثبتة في inventory الدفعة 8 — snapshot 5CD55D5D…).
 *
 * الدلتا المصرَّح بها الوحيدة (قرار المالك B3-08 — الخيار أ): بوابة
 * المالك is_feature_enabled('store') بعد حارس ABSPATH — return
 * مبكر؛ فشل آمن بلا إخراج. فصل scopes المصدر ($store_can_products
 * بـedit_products و$store_can_orders بـmanage_options أو
 * manage_woocommerce) وregistry states وحالات التبويبين باقية كما
 * هي (عقد §19: فصل products/orders scopes).
 *
 * args صريحة: البانل بلا متغيرات من الـshell — القدرات والـregistry
 * محلية؛ يُستدعى من render_part بقائمة args الموثقة.
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'store' ) ) {
	return;
}
$store_integration = function_exists( 'hossam_get_integration_decision' )
    ? hossam_get_integration_decision( 'woocommerce' )
    : [ 'status' => 'unavailable', 'reason' => '', 'capabilities' => [] ];
$store_can_products = current_user_can( 'edit_products' )
    && 'unavailable' !== $store_integration['status']
    && ! empty( $store_integration['capabilities']['products'] );
$store_can_orders = ( current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' ) )
    && 'unavailable' !== $store_integration['status']
    && ! empty( $store_integration['capabilities']['orders'] );
if ( ! current_user_can( 'edit_products' )
    && ! current_user_can( 'manage_options' )
    && ! current_user_can( 'manage_woocommerce' ) ) {
    return;
}
?>
    <!-- ══ PANEL: STORE ══ -->
    <div class="panel" id="panel-store">
      <div class="page-hdr">
        <div class="page-breadcrumb">
          <span><?php echo esc_html( hossam_t( 'Dashboard' ) ); ?></span><span>›</span>
          <span class="crumb-active"><?php echo esc_html( hossam_t( 'Store' ) ); ?></span>
        </div>
        <h1><?php echo esc_html( hossam_t( 'Store' ) ); ?></h1>
        <p><?php echo esc_html( hossam_t( 'WooCommerce status' ) . ': ' . $store_integration['status'] ); ?></p>
      </div>

      <?php if ( ! $store_can_products && ! $store_can_orders ) : ?>
      <div class="card"><div class="ph-notice"><?php echo esc_html( $store_integration['reason'] ?: hossam_t( 'WooCommerce not active' ) ); ?></div></div>
      <?php else : ?>
      <div class="tab-group" data-tab-group="store">
        <div class="tab-bar mb-14">
          <?php if ( $store_can_products ) : ?>
          <button class="tab-btn active" data-tab="products" onclick="switchTab(this)"><?php echo esc_html( hossam_t( 'Products' ) ); ?></button>
          <?php endif; ?>
          <?php if ( $store_can_orders ) : ?>
          <button class="tab-btn <?php echo $store_can_products ? '' : 'active'; ?>" data-tab="orders" onclick="switchTab(this)"><?php echo esc_html( hossam_t( 'Orders' ) ); ?></button>
          <?php endif; ?>
        </div>

        <?php if ( $store_can_products ) : ?>
        <div class="tab-content active" data-tab-content="products">
          <div class="card">
            <div id="store-products-wrap">
              <div class="ph-notice"><?php echo esc_html( hossam_t( 'Loading…' ) ); ?></div>
            </div>
          </div>
        </div>
        <?php endif; ?>
        <?php if ( $store_can_orders ) : ?>
        <div class="tab-content <?php echo $store_can_products ? '' : 'active'; ?>" data-tab-content="orders"<?php echo $store_can_products ? ' style="display:none"' : ''; ?>>
          <div class="card">
            <div id="store-orders-wrap">
              <div class="ph-notice"><?php echo esc_html( hossam_t( 'Loading…' ) ); ?></div>
            </div>
          </div>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div><!-- /panel-store -->
