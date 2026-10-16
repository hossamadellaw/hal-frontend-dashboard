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
      <?php
      // v1.0.6 §14 — article translations list (manual create/edit only).
      // Status comes from hossam_get_translation_status() (linked articles:
      // languages, relations, states — never WPML translation jobs); no
      // independent system and no WPML job-management calls. The strict
      // runtime gate disables this UI safely when WPML is incomplete —
      // articles stay intact and the textual reason is carried for the
      // capability log. Buttons reuse the existing hossam_translate_article
      // endpoint via posts.js (no new path).
      $hossam_tr_gate = function_exists( 'hossam_wpml_translation_runtime_ready' )
          ? hossam_wpml_translation_runtime_ready()
          : [ 'ready' => false, 'reason' => 'WPML translation runtime is unavailable.' ];
      $hossam_tr_ready = ! empty( $hossam_tr_gate['ready'] );
      $hossam_tr_langs = ( $hossam_tr_ready && function_exists( 'hossam_wpml_get_active_languages' ) )
          ? hossam_wpml_get_active_languages()
          : [];
      if ( ! is_array( $hossam_tr_langs ) ) {
          $hossam_tr_langs = [];
          $hossam_tr_ready = false;
      }
      ?>
      <div class="card mt-12 hossam-translations-panel">
        <div class="card-title mb-12"><?php echo esc_html( hossam_t( 'Article translations' ) ); ?></div>
        <?php if ( ! $hossam_tr_ready ) : ?>
          <div class="ph-notice"><?php echo esc_html( hossam_t( 'Translations are unavailable.' ) ); ?></div>
          <?php if ( ! empty( $hossam_tr_gate['reason'] ) ) : ?>
          <p><span style="font-size:11px;color:var(--txt-3)"><?php echo esc_html( (string) $hossam_tr_gate['reason'] ); ?></span></p>
          <?php endif; ?>
        <?php elseif ( empty( $articles_query ) || ! method_exists( $articles_query, 'have_posts' ) || ! method_exists( $articles_query, 'the_post' ) ) : ?>
          <div class="ph-notice"><?php echo esc_html( hossam_t( 'No articles found.' ) ); ?></div>
        <?php else : ?>
        <?php
          // The articles query is shared with the articles table rendered
          // above on the same page — rewind it before this second pass.
          if ( method_exists( $articles_query, 'rewind_posts' ) ) {
              $articles_query->rewind_posts();
          }
        ?>
        <?php if ( ! $articles_query->have_posts() ) : ?>
          <div class="ph-notice"><?php echo esc_html( hossam_t( 'No articles found.' ) ); ?></div>
        <?php else : ?>
        <div class="tbl-wrap">
          <table>
            <thead>
              <tr>
                <th><?php echo esc_html( hossam_t( 'Title' ) ); ?></th>
                <th><?php echo esc_html( hossam_t( 'Lang' ) ); ?></th>
                <th><?php echo esc_html( hossam_t( 'Status' ) ); ?></th>
                <th><?php echo esc_html( hossam_t( 'Actions' ) ); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php while ( $articles_query->have_posts() ) : $articles_query->the_post(); ?>
                <?php
                  $hossam_tr_post_id = function_exists( 'get_the_ID' ) ? (int) get_the_ID() : 0;
                  $hossam_tr_title  = function_exists( 'get_the_title' ) ? (string) get_the_title() : '';
                  $hossam_tr_src   = function_exists( 'hossam_wpml_get_element_language_code' )
                      ? (string) hossam_wpml_get_element_language_code( $hossam_tr_post_id )
                      : '';
                  $hossam_tr_map = function_exists( 'hossam_get_translation_status' )
                      ? hossam_get_translation_status( $hossam_tr_post_id )
                      : [];
                  if ( ! is_array( $hossam_tr_map ) ) {
                      $hossam_tr_map = [];
                  }
                  $hossam_tr_can_source = $hossam_tr_post_id > 0 && current_user_can( 'edit_post', $hossam_tr_post_id );
                ?>
                <tr>
                  <td><span class="td-title"><?php echo esc_html( $hossam_tr_title ); ?></span></td>
                  <td><span style="font-size:11px;font-weight:600;color:var(--txt-2)"><?php echo esc_html( '' !== $hossam_tr_src ? strtoupper( $hossam_tr_src ) : '—' ); ?></span></td>
                  <td>
                    <?php foreach ( (array) $hossam_tr_langs as $hossam_tr_code => $hossam_tr_info ) : ?>
                      <?php
                        if ( '' !== $hossam_tr_src && (string) $hossam_tr_code === $hossam_tr_src ) {
                            continue;
                        }
                        $hossam_tr_entry = $hossam_tr_map[ (string) $hossam_tr_code ] ?? null;
                        if ( is_object( $hossam_tr_entry ) ) {
                            $hossam_tr_entry = get_object_vars( $hossam_tr_entry );
                        }
                        $hossam_tr_tid = 0;
                        if ( is_array( $hossam_tr_entry ) ) {
                            if ( isset( $hossam_tr_entry['element_id'] ) ) {
                                $hossam_tr_tid = (int) $hossam_tr_entry['element_id'];
                            } elseif ( isset( $hossam_tr_entry['translation_id'] ) ) {
                                $hossam_tr_tid = (int) $hossam_tr_entry['translation_id'];
                            }
                        }
                        $hossam_tr_tstatus = $hossam_tr_tid > 0 && function_exists( 'get_post_status' )
                            ? (string) get_post_status( $hossam_tr_tid )
                            : '';
                        if ( '' !== $hossam_tr_tstatus && function_exists( '_dash_status_badge' ) ) {
                            list( $hossam_tr_cls, $hossam_tr_txt ) = _dash_status_badge( $hossam_tr_tstatus );
                        } else {
                            $hossam_tr_cls = $hossam_tr_tid > 0 ? 'pill-pub' : 'pill-draft';
                            $hossam_tr_txt = $hossam_tr_tid > 0 ? ( '' !== $hossam_tr_tstatus ? ucfirst( $hossam_tr_tstatus ) : hossam_t( 'Original' ) ) : hossam_t( 'Not translated' );
                        }
                      ?>
                      <span class="pill <?php echo esc_attr( $hossam_tr_cls ); ?>"><?php echo esc_html( strtoupper( (string) $hossam_tr_code ) . ': ' . $hossam_tr_txt ); ?></span>
                    <?php endforeach; ?>
                  </td>
                  <td>
                    <div class="articles-tbl-actions">
                      <?php foreach ( (array) $hossam_tr_langs as $hossam_tr_code => $hossam_tr_info ) : ?>
                        <?php
                          if ( '' !== $hossam_tr_src && (string) $hossam_tr_code === $hossam_tr_src ) {
                              continue;
                          }
                          $hossam_tr_entry = $hossam_tr_map[ (string) $hossam_tr_code ] ?? null;
                          if ( is_object( $hossam_tr_entry ) ) {
                              $hossam_tr_entry = get_object_vars( $hossam_tr_entry );
                          }
                          $hossam_tr_tid = 0;
                          if ( is_array( $hossam_tr_entry ) ) {
                              if ( isset( $hossam_tr_entry['element_id'] ) ) {
                                  $hossam_tr_tid = (int) $hossam_tr_entry['element_id'];
                              } elseif ( isset( $hossam_tr_entry['translation_id'] ) ) {
                                  $hossam_tr_tid = (int) $hossam_tr_entry['translation_id'];
                              }
                          }
                          $hossam_tr_edit_url = ( $hossam_tr_tid > 0 && isset( $_dash_base ) )
                              ? add_query_arg( [ 'panel' => 'edit-article', 'post_id' => $hossam_tr_tid ], $_dash_base )
                              : '';
                        ?>
                        <?php if ( $hossam_tr_tid > 0 && '' !== $hossam_tr_edit_url && current_user_can( 'edit_post', $hossam_tr_tid ) ) : ?>
                        <a href="<?php echo esc_url( $hossam_tr_edit_url ); ?>" class="btn btn-sm btn-ghost"><?php echo esc_html( sprintf( hossam_t( 'Edit %s' ), strtoupper( (string) $hossam_tr_code ) ) ); ?></a>
                        <?php elseif ( 0 === $hossam_tr_tid && $hossam_tr_can_source ) : ?>
                        <a href="#" class="btn btn-sm btn-ghost js-translate-article" data-post="<?php echo esc_attr( (string) $hossam_tr_post_id ); ?>" data-lang="<?php echo esc_attr( (string) $hossam_tr_code ); ?>"><?php echo esc_html( sprintf( hossam_t( 'Translate to %s' ), strtoupper( (string) $hossam_tr_code ) ) ); ?></a>
                        <?php endif; ?>
                      <?php endforeach; ?>
                    </div>
                  </td>
                </tr>
              <?php endwhile; if ( function_exists( 'wp_reset_postdata' ) ) { wp_reset_postdata(); } ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </div><!-- /panel-translation -->
