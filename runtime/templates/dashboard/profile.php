<?php
/**
 * runtime/templates/dashboard/profile.php — بانل الملف الشخصي (الدفعة 7)
 * ══════════════════════════════════════════════════════════════
 * المصدر: legacy theme/template-parts/dashboard/profile.php (بصمة
 * المصدر بالبايت مثبتة في inventory الدفعة 7 — snapshot 67044A2F…).
 *
 * الدلتا المصرَّح بها الوحيدة (قرار المالك B3-08 — الخيار أ): بوابة
 * المالك is_feature_enabled('ultimate_member_profile') بعد حارس ABSPATH
 * — return مبكر بنمط المصدر نفسه (bookings.php)؛ فشل آمن بلا إخراج.
 * الدلتا الثانية (CR-2026-09-25-PROFILE-LINKS): قسم «روابط إضافية»
 * عبر فلتر hal_frontend_dashboard_profile_links (profile-links.php) —
 * روابط مقبولة فقط، ولا قسم فارغًا.
 * حالات الـregistry/shortcode الثلاثة (profile_ui/account/password)
 * والفشل الآمن ونصوص الحالة باقية كما هي.
 *
 * args صريحة: البانل يكتفي بالـregistry وقدرات المستخدم (بلا متغيرات من
 * الـshell) — يُستدعى من render_part بقائمة args الموثقة.
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'ultimate_member_profile' ) ) {
	return;
}
// CR-2026-09-25-PROFILE-LINKS: نقطة تمديد الروابط (profile-links.php) —
// تُحمَّل وتُستدعى وقت العرض فقط (بعد بوابة الميزة وبعد تحميل الإضافات)،
// ولا قسم بلا روابط مقبولة. غياب UM لا يمنعها؛ التعطيل يخفي اللوحة معها.
require_once __DIR__ . '/profile-links.php';
$profile_integration = function_exists( 'hossam_get_integration_decision' )
    ? hossam_get_integration_decision( 'ultimate_member' )
    : [ 'status' => 'unavailable', 'reason' => '', 'capabilities' => [] ];
?>
    <!-- ══ PANEL: MY PROFILE ══ -->
    <div class="panel" id="panel-profile">
      <div class="page-hdr">
        <div class="page-breadcrumb"><span><?php echo esc_html( hossam_t( 'Dashboard' ) ); ?></span><span>›</span><span class="crumb-active"><?php echo esc_html( hossam_t( 'My Profile' ) ); ?></span></div>
        <h1><?php echo esc_html( hossam_t( 'My Profile & Account' ) ); ?></h1>
        <p><?php echo esc_html( hossam_t( 'Manage your user profile, password, and account settings.' ) ); ?></p>
        <p><?php echo esc_html( hossam_t( 'Ultimate Member status' ) . ': ' . $profile_integration['status'] ); ?></p>
      </div>
      <div class="grid g3 mb-14">
        <div class="card">
          <div class="card-title mb-12"><?php echo esc_html( hossam_t( 'Profile' ) ); ?></div>
          <?php
          $um_profile_fid = (int) get_option( 'hossam_um_profile_form_id', 3621 );
          if ( 'unavailable' !== $profile_integration['status']
              && ! empty( $profile_integration['capabilities']['profile_ui'] )
              && shortcode_exists( 'ultimatemember' ) ) {
              echo do_shortcode( '[ultimatemember form_id="' . $um_profile_fid . '"]' );
          } else {
              echo '<div class="ph-notice">' . esc_html( $profile_integration['reason'] ?: hossam_t( 'Profile form is unavailable.' ) ) . '</div>';
          }
          ?>
        </div>
        <div class="card">
          <div class="card-title mb-12"><?php echo esc_html( hossam_t( 'Settings' ) ); ?></div>
          <?php
          if ( 'unavailable' !== $profile_integration['status'] && shortcode_exists( 'ultimatemember_account' ) ) {
              echo do_shortcode( '[ultimatemember_account]' );
          } else {
              echo '<div class="ph-notice">' . esc_html( hossam_t( 'Account settings are unavailable.' ) ) . '</div>';
          }
          ?>
        </div>
        <div class="card">
          <div class="card-title mb-12"><?php echo esc_html( hossam_t( 'Security' ) ); ?></div>
          <div class="form-actions mt-12">
            <?php
            if ( 'unavailable' !== $profile_integration['status'] && shortcode_exists( 'ultimatemember_password' ) ) {
                echo do_shortcode( '[ultimatemember_password]' );
            } else {
                echo '<div class="ph-notice">' . esc_html( hossam_t( 'Password change is unavailable.' ) ) . '</div>';
            }
            ?>
            <a href="<?php echo esc_url( wp_logout_url( hossam_login_url() ) ); ?>" class="btn btn-danger">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
              <?php echo esc_html( hossam_t( 'Sign Out' ) ); ?>
            </a>
          </div>
        </div>
      </div>
      <?php hossam_render_profile_links_section(); ?>
    </div><!-- /panel-profile -->
