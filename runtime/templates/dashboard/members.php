<?php
/**
 * runtime/templates/dashboard/members.php — بانل الأعضاء (الدفعة 8)
 * ══════════════════════════════════════════════════════════════
 * المصدر: legacy theme/template-parts/dashboard/members.php (بصمة
 * المصدر بالبايت مثبتة في inventory الدفعة 8 — snapshot 5CD55D5D…).
 *
 * الدلتا المصرَّح بها الوحيدة (قرار المالك B3-08 — الخيار أ؛ عقد
 * §19 صف members.php «manage_options guard مزدوج»): حارسان داخليان
 * بعد حارس ABSPATH — current_user_can('manage_options') (القدرة
 * الحقيقية لا الـboolean الممرر) ثم بوابة المالك
 * is_feature_enabled('members') — return مبكر لكل منهما؛ شرط
 * المصدر if ( $is_admin ) باقٍ بالجسم كما هو (طبقة الـshell)
 * فيكتمل الحرس المزدوج. جدول Roles من $role_display_map
 * والتبويبان باقيان كما هما.
 *
 * args صريحة: يستهلك من الـshell $is_admin (شرط المصدر) و
 * $role_display_map (جدول Roles) — يُستدعى من render_part بقائمة
 * args الموثقة.
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	return;
}
if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'members' ) ) {
	return;
}
?>
    <!-- ══ PANEL: MEMBERS (Admin Only) ══ -->
    <?php if ( $is_admin ) : ?>
    <div class="panel" id="panel-members">
      <div class="page-hdr">
        <div class="page-breadcrumb"><span><?php echo esc_html( hossam_t( 'Dashboard' ) ); ?></span><span>›</span><span class="crumb-active"><?php echo esc_html( hossam_t( 'Members' ) ); ?></span></div>
        <h1><?php echo esc_html( hossam_t( 'Members' ) ); ?></h1>
        <p><?php echo esc_html( hossam_t( 'Manage staff accounts and roles.' ) ); ?></p>
      </div>

      <div class="tab-group" data-tab-group="members">
        <div class="tab-bar mb-14">
          <button class="tab-btn active" data-tab="all" onclick="switchTab(this)"><?php echo esc_html( hossam_t( 'All Members' ) ); ?></button>
          <button class="tab-btn" data-tab="roles" onclick="switchTab(this)"><?php echo esc_html( hossam_t( 'Roles' ) ); ?></button>
        </div>

        <div class="tab-content active" data-tab-content="all">
          <div class="card">
            <div id="members-table-wrap">
              <div class="ph-notice"><?php echo esc_html( hossam_t( 'Loading…' ) ); ?></div>
            </div>
          </div>
        </div>

        <div class="tab-content" data-tab-content="roles" style="display:none">
          <div class="card">
            <table class="role-matrix">
              <tbody>
                <?php foreach ( $role_display_map as $role_slug => $role_label ) : ?>
                <tr><td><?php echo esc_html( $role_slug ); ?></td><td><?php echo esc_html( $role_label ); ?></td></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div><!-- /panel-members -->
    <?php endif; ?>
