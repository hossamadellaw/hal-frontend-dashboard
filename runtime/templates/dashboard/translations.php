<?php
/**
 * runtime/templates/dashboard/translations.php — بانل اللغات والترجمة (الدفعة 8)
 * ══════════════════════════════════════════════════════════════
 * المصدر: legacy theme/template-parts/dashboard/translations.php (بصمة
 * المصدر بالبايت مثبتة في inventory الدفعة 8 — snapshot 5CD55D5D…).
 *
 * الدلتا المصرَّح بها الوحيدة (قرار المالك B3-08 — الخيار أ): بوابة
 * المالك is_feature_enabled('wpml_translations') بعد حارس ABSPATH —
 * return مبكر بنمط المصدر نفسه (bookings.php)؛ فشل آمن بلا إخراج.
 * شورت كود اللغة المثبت من المصدر (wpml_language_switcher) وسرد
 * اللغات النشطة وregistry states والرابط الإداري المحروس
 * manage_options باقية كما هي — بلا استبدال shortcode (مؤجل بقرار
 * صاحب المشروع في نص المصدر ذاته).
 *
 * args صريحة: البانل بلا متغيرات من الـshell — كل قراره من
 * الـregistry والقدرات المحلية؛ يُستدعى من render_part بقائمة
 * args الموثقة.
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'wpml_translations' ) ) {
	return;
}

$hossam_wpml_decision = function_exists( 'hossam_get_integration_decision' )
    ? hossam_get_integration_decision( 'wpml' )
    : [ 'status' => 'unavailable', 'capabilities' => [], 'reason' => 'WPML is unavailable.' ];
$hossam_wpml_caps = is_array( $hossam_wpml_decision['capabilities'] ?? null ) ? $hossam_wpml_decision['capabilities'] : [];
?>
    <!-- ══ PANEL: LANGUAGES & TRANSLATIONS ══ -->
    <div class="panel" id="panel-translation">
      <div class="page-hdr">
        <div class="page-breadcrumb"><span><?php echo esc_html( hossam_t( 'Dashboard' ) ); ?></span><span>›</span><span class="crumb-active"><?php echo esc_html( hossam_t( 'Languages & Translations' ) ); ?></span></div>
        <h1><?php echo esc_html( hossam_t( 'Languages & Translations' ) ); ?></h1>
        <p><?php echo esc_html( hossam_t( 'Manage content languages and translation settings.' ) ); ?></p>
      </div>
      <div class="grid g2">
        <div class="card">
          <div class="card-title mb-12"><?php echo esc_html( hossam_t( 'Switch Interface Language' ) ); ?></div>
          <div class="lang-section">
            <?php if ( 'unavailable' !== $hossam_wpml_decision['status'] && shortcode_exists( 'wpml_language_switcher' ) ) : ?>
              <?php echo do_shortcode('[wpml_language_switcher type="footer" flags=1 native=1 translated=1][/wpml_language_switcher]'); ?>
            <?php else : ?>
              <div class="ph-notice"><?php echo esc_html( $hossam_wpml_decision['reason'] ?: hossam_t( 'Language switcher is unavailable.' ) ); ?></div>
            <?php endif; ?>
          </div>
          <div class="divider"></div>
          <div class="info-card">
            <p><?php echo esc_html( hossam_t( 'Active languages on this site:' ) ); ?></p>
            <?php if ( 'unavailable' !== $hossam_wpml_decision['status'] && ! empty( $hossam_wpml_caps['languages'] ) ) :
              $active_langs = hossam_wpml_get_active_languages();
              foreach ( (array) $active_langs as $lang ) : ?>
            <div class="lang-row">
              <span class="lang-flag">
                <img src="<?php echo esc_url( $lang['country_flag_url'] ); ?>"
                     width="20" height="15"
                     alt="<?php echo esc_attr( $lang['language_code'] ); ?>">
              </span>
              <div class="lang-info">
                <div class="lang-name"><?php echo esc_html( $lang['translated_name'] ); ?></div>
                <div class="lang-status"><?php echo esc_html( strtoupper( $lang['language_code'] ) ); ?></div>
              </div>
            </div>
            <?php endforeach;
            else : ?>
            <div class="ph-notice"><?php echo esc_html( hossam_t( 'WPML not active.' ) ); ?></div>
            <?php endif; ?>
          </div>
        </div>
        <div class="card">
          <div class="card-title mb-12"><?php echo esc_html( hossam_t( 'Translation Guidelines' ) ); ?></div>
          <div class="info-card">
            <ul>
              <li><?php echo esc_html( hossam_t( 'To translate an article, find it in All My Articles and use the translation option next to each article.' ) ); ?></li>
              <li><?php echo esc_html( hossam_t( 'Arabic content is displayed right-to-left automatically when Arabic is the active language.' ) ); ?></li>
              <li><?php echo esc_html( hossam_t( 'Translation editing is managed through the site administration area.' ) ); ?></li>
              <li><?php echo esc_html( hossam_t( 'Contact your editor to request new translations or to update existing ones.' ) ); ?></li>
            </ul>
          </div>
          <?php if ( current_user_can( 'manage_options' ) && 'unavailable' !== $hossam_wpml_decision['status'] ) : ?>
          <a href="<?php echo esc_url(admin_url('admin.php?page=sitepress-multilingual-cms')); ?>" target="_blank" class="btn btn-outline mt-12">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
            Language Manager
          </a>
          <?php endif; ?>
        </div>
      </div>
    </div><!-- /panel-translation -->
