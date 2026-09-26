<?php
/**
 * runtime/templates/dashboard/bookings.php — بانل المواعيد/Amelia (الدفعة 7)
 * ══════════════════════════════════════════════════════════════
 * المصدر: legacy theme/template-parts/dashboard/bookings.php (بصمة
 * المصدر بالبايت مثبتة في inventory الدفعة 7 — snapshot 67044A2F…).
 *
 * الدلتا المصرَّح بها الوحيدة (قرار المالك B3-08 — الخيار أ): شرط
 * $bookings_allowed أضيفت عليه بوابة المالك is_feature_enabled('amelia')
 * — شروط المصدر (القدرات الثلاث) وحالة الـregistry وnonce المنفصل
 * hossam_lazy_appointments وفق scope باقية كما هي.
 *
 * args صريحة: البانل يكتفي بقدرات المستخدم والـregistry (بلا متغيرات من
 * الـshell) — يُستدعى من render_part بقائمة args الموثقة.
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$bookings_allowed = ( current_user_can( 'manage_options' )
    || current_user_can( 'view_amelia_calendar_all' )
    || current_user_can( 'view_amelia_calendar' ) )
    && HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'amelia' );
if ( ! $bookings_allowed ) {
    return;
}

$bookings_integration = function_exists( 'hossam_get_integration_decision' )
    ? hossam_get_integration_decision( 'amelia' )
    : [ 'status' => 'unavailable', 'capabilities' => [] ];
$bookings_ready = 'unavailable' !== $bookings_integration['status']
    && ! empty( $bookings_integration['capabilities']['bookings_shortcode'] )
    && shortcode_exists( 'ameliaemployeepanel' );
?>
    <!-- ══ PANEL: APPOINTMENTS ══ -->
    <div class="panel" id="panel-appointments">
      <div class="page-hdr">
        <div class="page-breadcrumb"><span><?php echo esc_html( hossam_t( 'Dashboard' ) ); ?></span><span>›</span><span class="crumb-active"><?php echo esc_html( hossam_t( 'My Schedule' ) ); ?></span></div>
        <h1><?php echo esc_html( hossam_t( 'My Schedule' ) ); ?></h1>
        <p><?php echo esc_html( hossam_t( 'Manage your bookings and employee calendar.' ) ); ?></p>
      </div>
      <?php if ( $bookings_ready ) : ?>
      <div class="card" id="appointments-panel-wrap"
           data-lazy-panel="appointments"
           data-lazy-nonce="<?php echo esc_attr( wp_create_nonce( 'hossam_lazy_appointments' ) ); ?>">
        <div class="ph-notice"><?php echo esc_html( hossam_t( 'Loading…' ) ); ?></div>
      </div>
      <?php else : ?>
      <div class="card"><div class="ph-notice"><?php echo esc_html( hossam_t( 'Appointment module not configured.' ) ); ?></div></div>
      <?php endif; ?>
    </div><!-- /panel-appointments -->
