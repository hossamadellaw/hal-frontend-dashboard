<?php
/**
 * runtime/templates/dashboard/admin.php — بانل Administration (الدفعة 8)
 * ══════════════════════════════════════════════════════════════
 * المصدر: legacy theme/template-parts/dashboard/admin.php (بصمة
 * المصدر بالبايت مثبتة في inventory الدفعة 8 — snapshot 5CD55D5D…).
 *
 * الدلتا: لا دلتا وظيفية — حارس المصدر الداخلي current_user_can(
 * 'manage_options') (الدفاع المزدوج مع شرط $is_admin في الـshell)
 * موجود بالحرف في المصدر وهو الـgate الداخلي لهذا البانل (عقد
 * §19)؛ لا مفتاح له في قائمة FEATURES فلا بوابة مالك. بطاقات
 * الصحة booleans ونصوص حالة فقط بلا secrets/user data، والروابط
 * إلى wp-admin — لا يحل محل Backend Settings.
 *
 * args صريحة: البانل بلا متغيرات من الـshell؛ يُستدعى من
 * render_part بقائمة args الموثقة.
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


if ( ! current_user_can( 'manage_options' ) ) {
	return;
}
?>
<div class="panel" id="panel-admin">
  <div class="page-hdr">
    <div class="page-breadcrumb"><span><?php echo esc_html( hossam_t( 'Dashboard' ) ); ?></span><span>›</span><span class="crumb-active"><?php echo esc_html( hossam_t( 'Administration' ) ); ?></span></div>
    <h1><?php echo esc_html( hossam_t( 'Site Administration' ) ); ?></h1>
    <p><?php echo esc_html( hossam_t( 'Full administrator access — all links open the site administration panel.' ) ); ?></p>
    <span class="data-tag" style="background:var(--gold-pale);color:var(--gold-dim)">
      <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      <?php echo esc_html( hossam_t( 'Administrator access only' ) ); ?>
    </span>
  </div>

  <div class="grid g3 mb-14">
    <a href="<?php echo esc_url( admin_url() ); ?>" target="_blank" class="card admin-link-card">
      <div class="alc-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg></div>
      <div class="alc-title"><?php echo esc_html( hossam_t( 'Site Settings' ) ); ?></div>
      <div class="alc-sub"><?php echo esc_html( hossam_t( 'Full administration panel' ) ); ?></div>
      <svg class="alc-arrow" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
    </a>
    <a href="<?php echo esc_url( admin_url( 'users.php' ) ); ?>" target="_blank" class="card admin-link-card">
      <div class="alc-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
      <div class="alc-title"><?php echo esc_html( hossam_t( 'User Management' ) ); ?></div>
      <div class="alc-sub"><?php echo esc_html( hossam_t( 'All users & roles' ) ); ?></div>
      <svg class="alc-arrow" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
    </a>
    <a href="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>" target="_blank" class="card admin-link-card">
      <div class="alc-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
      <div class="alc-title"><?php echo esc_html( hossam_t( 'All Posts' ) ); ?></div>
      <div class="alc-sub"><?php echo esc_html( hossam_t( 'Full content management' ) ); ?></div>
      <svg class="alc-arrow" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
    </a>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=amelia-appointments' ) ); ?>" target="_blank" class="card admin-link-card">
      <div class="alc-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
      <div class="alc-title"><?php echo esc_html( hossam_t( 'Appointments Backend' ) ); ?></div>
      <div class="alc-sub"><?php echo esc_html( hossam_t( 'Full booking management' ) ); ?></div>
      <svg class="alc-arrow" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
    </a>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=rank-math' ) ); ?>" target="_blank" class="card admin-link-card">
      <div class="alc-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div>
      <div class="alc-title"><?php echo esc_html( hossam_t( 'SEO Dashboard' ) ); ?></div>
      <div class="alc-sub"><?php echo esc_html( hossam_t( 'Full SEO analytics' ) ); ?></div>
      <svg class="alc-arrow" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
    </a>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=litespeed' ) ); ?>" target="_blank" class="card admin-link-card">
      <div class="alc-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg></div>
      <div class="alc-title"><?php echo esc_html( hossam_t( 'Cache Settings' ) ); ?></div>
      <div class="alc-sub"><?php echo esc_html( hossam_t( 'Performance & cache exclusions' ) ); ?></div>
      <svg class="alc-arrow" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
    </a>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=frontend-admin' ) ); ?>" target="_blank" class="card admin-link-card">
      <div class="alc-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></div>
      <div class="alc-title"><?php echo esc_html( hossam_t( 'Form Builder' ) ); ?></div>
      <div class="alc-sub"><?php echo esc_html( hossam_t( 'Article forms management' ) ); ?></div>
      <svg class="alc-arrow" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
    </a>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=sitepress-multilingual-cms' ) ); ?>" target="_blank" class="card admin-link-card">
      <div class="alc-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg></div>
      <div class="alc-title"><?php echo esc_html( hossam_t( 'Language Manager' ) ); ?></div>
      <div class="alc-sub"><?php echo esc_html( hossam_t( 'Translation management' ) ); ?></div>
      <svg class="alc-arrow" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
    </a>
    <a href="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" target="_blank" class="card admin-link-card">
      <div class="alc-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg></div>
      <div class="alc-title"><?php echo esc_html( hossam_t( 'MU Plugin Dashboard' ) ); ?></div>
      <div class="alc-sub"><?php echo esc_html( hossam_t( 'PHP hooks and shortcodes managed via hossam-dashboard.php' ) ); ?></div>
      <svg class="alc-arrow" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
    </a>
  </div>

  <!-- Amelia Integration Health — مطلوب حرفيًا من القائمة التنفيذية بند 1 — manage_options فقط -->
  <?php $amelia_health = function_exists( 'hossam_amelia_get_health' ) ? hossam_amelia_get_health() : []; ?>
  <?php if ( ! empty( $amelia_health ) ) : ?>
  <div class="card mb-14">
    <div class="card-title mb-12"><?php echo esc_html( hossam_t( 'Amelia Integration Health' ) ); ?></div>
    <div class="flex gap-8" style="flex-wrap:wrap">
      <span class="data-tag" style="background:<?php echo esc_attr( $amelia_health['plugin_active'] ? 'var(--success-bg)' : 'var(--danger-bg)' ); ?>;color:<?php echo esc_attr( $amelia_health['plugin_active'] ? 'var(--success)' : 'var(--danger)' ); ?>">
        <?php echo esc_html( hossam_t( 'Plugin' ) . ': ' . ( $amelia_health['plugin_active'] ? hossam_t( 'Active' ) : hossam_t( 'Inactive' ) ) ); ?>
      </span>
      <span class="data-tag" style="background:<?php echo esc_attr( $amelia_health['api_key_configured'] ? 'var(--success-bg)' : 'var(--danger-bg)' ); ?>;color:<?php echo esc_attr( $amelia_health['api_key_configured'] ? 'var(--success)' : 'var(--danger)' ); ?>">
        <?php echo esc_html( hossam_t( 'API Key' ) . ': ' . ( $amelia_health['api_key_configured'] ? hossam_t( 'Configured' ) : hossam_t( 'Missing' ) ) ); ?>
      </span>
      <span class="data-tag" style="background:<?php echo esc_attr( $amelia_health['api_available'] ? 'var(--success-bg)' : 'var(--danger-bg)' ); ?>;color:<?php echo esc_attr( $amelia_health['api_available'] ? 'var(--success)' : 'var(--danger)' ); ?>">
        <?php echo esc_html( hossam_t( 'API' ) . ': ' . ( $amelia_health['api_available'] ? hossam_t( 'Reachable' ) : hossam_t( 'Unreachable' ) ) ); ?>
      </span>
    </div>
    <?php if ( '' !== $amelia_health['error_code'] ) : ?>
    <p class="text-danger text-xs mt-8"><?php echo esc_html( hossam_t( 'Last error' ) . ': ' . $amelia_health['error_code'] ); ?></p>
    <p class="card-sub mt-8"><a href="<?php echo esc_url( admin_url( 'admin.php?page=wpamelia-settings' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( hossam_t( 'Open Amelia settings' ) ); ?></a></p>
    <?php endif; ?>
    <?php
    $amelia_checked_routes = isset( $amelia_health['checked_routes'] ) && is_array( $amelia_health['checked_routes'] )
        ? array_values( array_filter( array_map( 'sanitize_key', $amelia_health['checked_routes'] ) ) )
        : [];
    ?>
    <p class="card-sub mt-8"><?php echo esc_html( hossam_t( 'Checked routes' ) . ': ' . ( $amelia_checked_routes ? implode( ', ', $amelia_checked_routes ) : '—' ) ); ?></p>
    <p class="card-sub mt-8"><?php echo esc_html( hossam_t( 'Last checked' ) . ': ' . $amelia_health['last_check'] ); ?></p>
  </div>
  <?php endif; ?>

  <!-- Integrations Health Matrix — البند التمهيدى 6 (الدفعة الثانية): صفحة صحة داخلية للمدير فقط:
       الإضافة/النسخة المرصودة/القدرات المتاحة/سبب التعطيل وآخر فحص. لا تعرض مفاتيح API
       ولا بيانات مستخدمين ولا محتوى وظائف AI — السجل يحمل booleans ونصوص حالة فقط. -->
  <?php
  $hossam_registry = function_exists( 'hossam_get_integration_capabilities' ) ? hossam_get_integration_capabilities() : [];
  if ( ! empty( $hossam_registry ) ) :
      $hossam_labels = [
          'amelia'          => 'Amelia',
          'wpml'            => 'WPML',
          'rank_math'       => 'Rank Math',
          'woocommerce'     => 'WooCommerce',
          'ultimate_member' => 'Ultimate Member',
          'frontend_admin'  => 'Frontend Admin',
          'ai'              => 'AI Provider',
      ];
  ?>
  <div class="card mb-14">
    <div class="card-title mb-12"><?php echo esc_html( hossam_t( 'Integrations Health' ) ); ?></div>
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr>
            <th><?php echo esc_html( hossam_t( 'Section' ) ); ?></th>
            <th><?php echo esc_html( hossam_t( 'Status' ) ); ?></th>
            <th><?php echo esc_html( hossam_t( 'Version' ) ); ?></th>
            <th><?php echo esc_html( hossam_t( 'Capabilities' ) ); ?></th>
            <th><?php echo esc_html( hossam_t( 'Reason' ) ); ?></th>
            <th><?php echo esc_html( hossam_t( 'Last checked' ) ); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ( $hossam_registry as $hossam_key => $hossam_entry ) : ?>
          <?php
            $hossam_status   = isset( $hossam_entry['status'] ) ? (string) $hossam_entry['status'] : 'unknown';
            $hossam_version  = isset( $hossam_entry['version'] ) ? (string) $hossam_entry['version'] : '';
            $hossam_reason   = isset( $hossam_entry['reason'] ) ? (string) $hossam_entry['reason'] : '';
            $hossam_checked  = isset( $hossam_entry['checked_at'] ) ? (string) $hossam_entry['checked_at'] : '';
            $hossam_caps     = isset( $hossam_entry['capabilities'] ) && is_array( $hossam_entry['capabilities'] ) ? $hossam_entry['capabilities'] : [];
            $hossam_caps_txt = implode(
                ', ',
                array_map(
                    static function ( string $cap_key, bool $cap_ok ): string {
                        return sanitize_key( $cap_key ) . ( $cap_ok ? ': yes' : ': no' );
                    },
                    array_keys( $hossam_caps ),
                    array_map( 'boolval', array_values( $hossam_caps ) )
                )
            );
          ?>
          <tr>
            <td><span class="td-title"><?php echo esc_html( $hossam_labels[ $hossam_key ] ?? sanitize_key( $hossam_key ) ); ?></span></td>
            <td><span class="<?php echo esc_attr( 'unavailable' === $hossam_status ? 'text-danger' : ( 'available' === $hossam_status ? 'rm-score rm-good' : 'rm-score rm-avg' ) ); ?>"><?php echo esc_html( $hossam_status ); ?></span></td>
            <td><span class="td-muted"><?php echo esc_html( '' !== $hossam_version ? $hossam_version : '—' ); ?></span></td>
            <td><span class="td-muted"><?php echo esc_html( '' !== $hossam_caps_txt ? $hossam_caps_txt : '—' ); ?></span></td>
            <td><span class="td-muted"><?php echo esc_html( '' !== $hossam_reason ? $hossam_reason : '—' ); ?></span></td>
            <td><span class="td-muted"><?php echo esc_html( '' !== $hossam_checked ? $hossam_checked : '—' ); ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- Role Matrix -->
  <?php
  if ( ! function_exists( '_dash_role_access_matrix' ) ) {
      function _dash_role_access_matrix(): array {
          $yes = [ 't' => '✦', 'c' => 'rm-yes' ];
          $no  = [ 't' => '—', 'c' => 'rm-no' ];
          $cell = static function ( string $t ): array {
              return [ 't' => $t, 'c' => 'rm-yes' ];
          };
          return [
              [ 'label' => 'Overview',                 'cells' => [ $yes, $yes, $yes, $yes, $yes, $yes ] ],
              [ 'label' => 'Write Article',            'cells' => [ $yes, $yes, $yes, $cell( '✦ Pending' ), $no, $no ] ],
              [ 'label' => 'All Articles',             'cells' => [ $cell( '✦ All' ), $cell( '✦ All' ), $cell( '✦ Own' ), $cell( '✦ Own' ), $no, $no ] ],
              [ 'label' => 'Edit Article',             'cells' => [ $cell( '✦ All' ), $cell( '✦ All' ), $cell( '✦ Own' ), $cell( '✦ Own' ), $no, $no ] ],
              [ 'label' => 'Delete Article',           'cells' => [ $cell( '✦ All' ), $cell( '✦ All' ), $cell( '✦ Own' ), $cell( '✦ Own' ), $no, $no ] ],
              [ 'label' => 'Files & Documents',        'cells' => [ $yes, $yes, $yes, $no, $no, $no ] ],
              [ 'label' => 'My Schedule',              'cells' => [ $yes, $no, $no, $no, $cell( '✦ Full' ), $cell( '✦ Own' ) ] ],
              [ 'label' => 'SEO Settings',             'cells' => [ $yes, $yes, $yes, $yes, $no, $no ] ],
              [ 'label' => 'Languages & Translations', 'cells' => [ $yes, $yes, $yes, $yes, $yes, $yes ] ],
              [ 'label' => 'Profile / Account',        'cells' => [ $yes, $yes, $yes, $yes, $yes, $yes ] ],
              [ 'label' => 'Inbox',                    'cells' => [ $yes, $yes, $yes, $yes, $yes, $yes ] ],
              [ 'label' => 'Finance',                  'cells' => [ $cell( '✦ Full' ), $yes, $yes, $cell( '✦ Own' ), $cell( '✦ Own' ), $cell( '✦ Own' ) ] ],
              [ 'label' => 'Administration',           'cells' => [ $cell( '✦ Full' ), $no, $no, $no, $no, $no ] ],
              [ 'label' => 'Members',                  'cells' => [ $cell( '✦ Full' ), $no, $no, $no, $no, $no ] ],
              [ 'label' => 'Store Products',           'cells' => [ $cell( '✦ Yes' ), $no, $no, $no, $no, $no ] ],
              [ 'label' => 'Store Orders',             'cells' => [ $cell( '✦ Yes' ), $no, $no, $no, $no, $no ] ],
          ];
      }
  }
  ?>
  <div class="card">
    <div class="card-title mb-12"><?php echo esc_html( hossam_t( 'Role Access Matrix' ) ); ?></div>
    <div class="role-matrix-wrap">
      <table class="role-matrix">
        <thead>
          <tr>
            <th><?php echo esc_html( hossam_t( 'Section' ) ); ?></th>
            <th><?php echo esc_html( hossam_t( 'Administrator' ) ); ?></th>
            <th><?php echo esc_html( hossam_t( 'Editor' ) ); ?></th>
            <th><?php echo esc_html( hossam_t( 'Author' ) ); ?></th>
            <th><?php echo esc_html( hossam_t( 'Contributor' ) ); ?></th>
            <th><?php echo esc_html( hossam_t( 'Amelia Mgr' ) ); ?></th>
            <th><?php echo esc_html( hossam_t( 'Amelia Emp' ) ); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ( _dash_role_access_matrix() as $hossam_matrix_row ) : ?>
          <tr>
            <td><?php echo esc_html( hossam_t( $hossam_matrix_row['label'] ) ); ?></td>
            <?php foreach ( $hossam_matrix_row['cells'] as $hossam_matrix_cell ) : ?>
            <td class="<?php echo esc_attr( $hossam_matrix_cell['c'] ); ?>"><?php echo esc_html( hossam_t( $hossam_matrix_cell['t'] ) ); ?></td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p class="card-sub mt-8"><?php echo esc_html( hossam_t( 'Cells reflect default WordPress capabilities per role. Specific-article Edit/Delete/Trash always re-check edit_post or delete_post for that resource, so direct capability grants follow the capability itself rather than the role name. Store Products requires edit_products, Store Orders require manage_woocommerce or manage_options, and calendar sections also require their integration to be available.' ) ); ?></p>
    </div>
  </div>
</div>
