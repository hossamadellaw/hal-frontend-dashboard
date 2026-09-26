<?php
/**
 * runtime/templates/dashboard/posts.php — بانلات المقالات الخمسة (الدفعة 7)
 * ══════════════════════════════════════════════════════════════
 * المصدر: legacy theme/template-parts/dashboard/posts.php (بصمة المصدر
 * بالبايت مثبتة في inventory الدفعة 7 — snapshot 67044A2F…).
 *
 * الدلتا المصرَّح بها الوحيدة (قرار المالك B3-08 — الخيار أ + سد فجوة
 * ترحيل مثبتة):
 *   1. استعادة تعريف ثوابت HOSSAM_FA_WRITE_FORM/EDIT/DELETE بحارس
 *      defined() — المصدر القديم عرّفها في
 *      «original dashboard/hossam-dashboard.php» [H-04] T2-01
 *      (get_option بنفس الأسماء والقيم الافتراضية 3727/3805/3813) ولم
 *      يكن لها وجه في خريطة الترحيل؛ بدونها فشل fatal عند فرع
 *      frontend_admin fallback (PHP 8: Undefined constant). التعريف هنا
 *      عند نقطة الاستهلاك الوحيدة وبنفس عقد المصدر.
 *   2. إعادة حساب $sees_content المحلي أضيفت عليها بوابة المالك
 *      is_feature_enabled('posts') — شرط المصدر (edit_posts) باقٍ.
 *   3. بطاقتا أزرار AI (panel-write/panel-edit-article) أضيفت عليهما
 *      بوابة المالك is_feature_enabled('ai') — شرطا المصدر
 *      (القدرة عبر hossam_ai_interface_available + صلاحية المقال)
 *      باقيان.
 *   4. قراءة SEO لكل صف أضيفت عليها بوابة المالك
 *      is_feature_enabled('rank_math_seo') — شروط المصدر (صلاحية
 *      المقال وحالة rank_math) باقية.
 *   5. أزرار الترجمة لكل صف أضيفت عليها بوابة المالك
 *      is_feature_enabled('wpml_translations') — شروط المصدر (صلاحية
 *      المقال وحالة WPML واللغات) باقية.
 *
 * args صريحة: تستقبل متغيراتها من الـshell عبر محمل أجزاء Runtime
 * (render_part) — قائمة args الموثقة في runtime/templates/dashboard.php.
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// [H-04] T2-01 — Form ID Constants (استعادة من original dashboard/
// hossam-dashboard.php:298-303 — نفس الأسماء والخيارات والافتراضيات)
if ( ! defined('HOSSAM_FA_WRITE_FORM') )
    define( 'HOSSAM_FA_WRITE_FORM',  (int) get_option('hossam_fa_write_form',  3727) );
if ( ! defined('HOSSAM_FA_EDIT_FORM') )
    define( 'HOSSAM_FA_EDIT_FORM',   (int) get_option('hossam_fa_edit_form',   3805) );
if ( ! defined('HOSSAM_FA_DELETE_FORM') )
    define( 'HOSSAM_FA_DELETE_FORM', (int) get_option('hossam_fa_delete_form', 3813) );

$posts_enabled = HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'posts' );
$sees_content = $posts_enabled && current_user_can( 'edit_posts' );
$hossam_edit_panel_allowed = $sees_content
    || ( $posts_enabled && 'edit-article' === $url_panel
        && ! empty( $url_post_id )
        && current_user_can( 'edit_post', (int) $url_post_id ) );
$hossam_delete_panel_allowed = $sees_content
    || ( $posts_enabled && 'delete-article' === $url_panel
        && ! empty( $url_post_id )
        && current_user_can( 'edit_post', (int) $url_post_id ) );
$posts_wpml = function_exists( 'hossam_get_integration_decision' )
    ? hossam_get_integration_decision( 'wpml' )
    : [ 'status' => 'unavailable', 'capabilities' => [] ];
$posts_rank_math = function_exists( 'hossam_get_integration_decision' )
    ? hossam_get_integration_decision( 'rank_math' )
    : [ 'status' => 'unavailable', 'capabilities' => [] ];
$posts_frontend_admin = function_exists( 'hossam_get_integration_decision' )
    ? hossam_get_integration_decision( 'frontend_admin' )
    : [ 'status' => 'unavailable', 'capabilities' => [] ];
$posts_frontend_fallback = 'unavailable' !== $posts_frontend_admin['status']
    && ! empty( $posts_frontend_admin['capabilities']['shortcode'] )
    && shortcode_exists( 'frontend_admin' );
?>
    <!-- ══ PANEL: WRITE NEW ARTICLE ══ -->
    <?php if ( $sees_content ) : ?>
    <div class="panel" id="panel-write">
      <div class="page-hdr">
        <div class="page-breadcrumb"><span><?php echo esc_html( hossam_t( 'Dashboard' ) ); ?></span><span>›</span><span><?php echo esc_html( hossam_t( 'Articles' ) ); ?></span><span>›</span><span class="crumb-active"><?php echo esc_html( hossam_t( 'Write New Article' ) ); ?></span></div>
        <h1><?php echo esc_html( hossam_t( 'Write New Article' ) ); ?></h1>
        <p><?php echo esc_html( hossam_t( 'Create a new article and submit it for publication.' ) ); ?></p>
      </div>
      <?php if ( $is_writer && ! $is_author && ! $is_editor && ! $is_admin ) : ?>
      <div class="ph-notice"><?php echo esc_html( hossam_t( 'Your articles go to review before publishing.' ) ); ?></div>
      <?php endif; ?>
      <div class="card mb-14">
        <!-- Core-first (الدفعة الثانية): المسار الأساسي hossam_create_article —
             الكاتب يُعيَّن من جلسة الخادم، ولا AI هنا (قيد البند 3). -->
        <form id="hossam-post-create-form" onsubmit="event.preventDefault(); hossamCreateArticle(this); return false;">
          <div class="form-group mb-8">
            <label class="form-label" for="hossam-new-title"><?php echo esc_html( hossam_t( 'Title' ) ); ?></label>
            <input type="text" class="form-input" id="hossam-new-title" name="title" maxlength="255" />
          </div>
          <div class="form-group mb-8">
            <label class="form-label" for="hossam-new-content"><?php echo esc_html( hossam_t( 'Content' ) ); ?></label>
            <textarea class="form-input" id="hossam-new-content" name="content" rows="10"></textarea>
          </div>
          <div class="form-group mb-8">
            <label class="form-label" for="hossam-new-cats"><?php echo esc_html( hossam_t( 'Categories' ) ); ?></label>
            <input type="text" class="form-input" id="hossam-new-cats" name="categories" inputmode="numeric" placeholder="3,7,12" />
          </div>
          <div class="form-group mb-8">
            <label class="form-label" for="hossam-new-status"><?php echo esc_html( hossam_t( 'Status' ) ); ?></label>
            <select class="filter-select" id="hossam-new-status" name="status">
              <option value="draft"><?php echo esc_html( hossam_t( 'Draft' ) ); ?></option>
              <option value="pending"><?php echo esc_html( hossam_t( 'Pending Review' ) ); ?></option>
              <?php if ( current_user_can( 'publish_posts' ) ) : ?>
              <option value="publish"><?php echo esc_html( hossam_t( 'Published' ) ); ?></option>
              <?php endif; ?>
            </select>
          </div>
          <div class="form-actions mt-12">
            <button type="submit" class="btn btn-gold"><?php echo esc_html( hossam_t( 'Save Article' ) ); ?></button>
          </div>
        </form>
      </div>
      <?php if ( current_user_can( 'edit_posts' ) && HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'ai' ) && function_exists( 'hossam_ai_interface_available' ) && hossam_ai_interface_available() ) : ?>
      <!-- الدفعة الثالثة (7.4): أزرار AI تُعرض فقط عند إثبات قدرة استراتيجية صالحة
           وصلاحية المستخدم — الإخفاء شرط عرضي، والتفويض يعاد خادميًا فى كل endpoint.
           (الدفعة 7: + بوابة المالك B3-08 is_feature_enabled('ai')). -->
      <div class="card mb-14">
        <div class="card-sub mb-8"><?php echo esc_html( hossam_t( 'AI Writing Assistant' ) ); ?></div>
        <div class="filter-bar">
          <button type="button" class="btn btn-sm js-ai-run" data-job-type="grammar" data-source="hossam-new-content" data-result="ai-suggestion-result-write"><?php echo esc_html( hossam_t( 'Grammar Check' ) ); ?></button>
          <input type="text" class="filter-select" id="hossam-ai-target-write" maxlength="8" placeholder="<?php echo esc_attr( hossam_t( 'Target language code' ) ); ?>" />
          <button type="button" class="btn btn-sm js-ai-run" data-job-type="translation" data-source="hossam-new-content" data-lang-source="hossam-ai-target-write" data-result="ai-suggestion-result-write"><?php echo esc_html( hossam_t( 'Suggest Translation' ) ); ?></button>
          <button type="button" class="btn btn-sm js-ai-run" data-job-type="seo" data-source="hossam-new-content" data-title-source="hossam-new-title" data-result="ai-suggestion-result-write"><?php echo esc_html( hossam_t( 'Suggest SEO' ) ); ?></button>
          <button type="button" class="btn btn-sm js-ai-run" data-job-type="improvement" data-source="hossam-new-content" data-result="ai-suggestion-result-write"><?php echo esc_html( hossam_t( 'Suggest Improvements' ) ); ?></button>
        </div>
        <div id="ai-suggestion-result-write" class="hidden"></div>
      </div>
      <?php endif; ?>
      <!-- Fallback محدد: [frontend_admin] مخفي افتراضيًا؛ لا يُفتَح إلا عند فشل تقني
           (شبكة/5xx/رد غير قابل للتحليل) من posts.js — أبدًا لا عند رفض أمني أو
           مدخل غير صالح (بوابة 8.2 نصًا). -->
      <?php if ( $posts_frontend_fallback ) : ?>
      <div class="card" id="fa-fallback-write" style="display:none">
        <?php echo do_shortcode( '[frontend_admin form=' . HOSSAM_FA_WRITE_FORM . ']' ); ?>
      </div>
      <?php endif; ?>
    </div><!-- /panel-write -->
    <?php endif; ?>
    <!-- ══ PANEL: ALL ARTICLES ══ -->
    <?php if ( $sees_content ) : ?>
    <div class="panel" id="panel-all-articles">
      <div class="page-hdr">
        <div class="page-breadcrumb"><span><?php echo esc_html( hossam_t( 'Dashboard' ) ); ?></span><span>›</span><span><?php echo esc_html( hossam_t( 'Articles' ) ); ?></span><span>›</span><span class="crumb-active"><?php echo esc_html( hossam_t( 'All My Articles' ) ); ?></span></div>
        <h1><?php echo esc_html( ! $author_filter ? hossam_t( 'All Articles' ) : hossam_t( 'My Articles' ) ); ?></h1>
        <p><?php echo esc_html( ! $author_filter ? hossam_t( 'All articles — all authors.' ) : hossam_t( 'All posts authored by you.' ) ); ?></p>
      </div>

      <!-- Filter Bar -->
      <div class="card card-sm mb-14">
        <div class="filter-bar">
          <form method="GET" action="" style="display:contents">
            <input type="hidden" name="panel" value="all-articles"/>
            <input type="hidden" name="paged" value="1"/>
            <select class="filter-select" name="status" onchange="this.form.submit()">
              <option value="all" <?php selected(($_GET['status']??'all'),'all'); ?>><?php echo esc_html( hossam_t( 'All Statuses' ) ); ?></option>
              <option value="publish" <?php selected(($_GET['status']??''),'publish'); ?>><?php echo esc_html( hossam_t( 'Published' ) ); ?></option>
              <option value="draft"   <?php selected(($_GET['status']??''),'draft'); ?>><?php echo esc_html( hossam_t( 'Draft' ) ); ?></option>
              <option value="pending" <?php selected(($_GET['status']??''),'pending'); ?>><?php echo esc_html( hossam_t( 'Pending Review' ) ); ?></option>
              <option value="future"  <?php selected(($_GET['status']??''),'future'); ?>><?php echo esc_html( hossam_t( 'Scheduled' ) ); ?></option>
            </select>
            <select class="filter-select" name="cat" onchange="this.form.submit()">
              <option value=""><?php echo esc_html( hossam_t( 'All Categories' ) ); ?></option>
              <?php
                $dash_cats = get_categories( ['hide_empty' => false] );
                foreach ( $dash_cats as $dash_cat ) {
                    echo '<option value="' . esc_attr( $dash_cat->slug ) . '" ' . ( ( $_GET['cat'] ?? '' ) === $dash_cat->slug ? 'selected' : '' ) . '>' . esc_html( $dash_cat->name ) . '</option>';
                }
              ?>
            </select>
          </form>
          <span class="filter-count" id="filter-count"
                data-found="<?php echo (int)$articles_query->found_posts; ?>">
            <?php echo esc_html( str_replace( ['{x}', '{y}'], [ $articles_query->post_count, $articles_query->found_posts ], hossam_t( 'Showing {x} of {y} articles' ) ) ); ?>
          </span>
          <div style="margin-left:auto">
            <?php if ( $sees_content ) : ?>
            <button class="btn btn-gold btn-sm" onclick="nav(document.querySelector('[data-panel=write]'),'write')"><?php echo esc_html( hossam_t( '+ Write New' ) ); ?></button>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Articles Table — PHP rendered, no JS fake data -->
      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th><?php echo esc_html( hossam_t( 'Title' ) ); ?></th>
              <th><?php echo esc_html( hossam_t( 'Category' ) ); ?></th>
              <th><?php echo esc_html( hossam_t( 'Lang' ) ); ?></th>
              <th><?php echo esc_html( hossam_t( 'Status' ) ); ?></th>
              <th><?php echo esc_html( hossam_t( 'Modified' ) ); ?></th>
              <th><?php echo esc_html( hossam_t( 'SEO' ) ); ?></th>
              <th><?php echo esc_html( hossam_t( 'Actions' ) ); ?></th>
            </tr>
          </thead>
          <tbody id="articles-body">
            <?php if ( $articles_query->have_posts() ) : ?>
              <?php while ( $articles_query->have_posts() ) : $articles_query->the_post(); ?>
                <?php
                  $post_id    = get_the_ID();
                  $pstatus    = get_post_status();
                  list($pill_cls, $pill_txt) = _dash_status_badge($pstatus);
                  $cats       = wp_get_post_categories($post_id, ['fields'=>'names']);
                  $cat_str    = !empty($cats) ? implode(', ', $cats) : '—';
                  $modified   = get_the_modified_date('M j, Y');
                  $can_edit   = current_user_can( 'edit_post', $post_id );
                  $can_del    = $can_edit;
                   $seo_data   = $can_edit
                      && HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'rank_math_seo' )
                      && 'unavailable' !== $posts_rank_math['status']
                      && function_exists( 'hossam_get_rankmath_seo_data' )
                      ? hossam_get_rankmath_seo_data( $post_id )
                      : [ 'seo_score' => 0, 'focus_keyword' => '', 'robots' => [] ];
                  $seo_score  = (int) $seo_data['seo_score'];
                  $focus_kw   = (string) $seo_data['focus_keyword'];
                  $seo_robots = $seo_data['robots'];
                  $is_noindex = is_array( $seo_robots )
                      ? in_array( 'noindex', $seo_robots, true )
                      : str_contains( (string) $seo_robots, 'noindex' );
                  $rm_cls     = $seo_score >= 80 ? 'rm-good' : ( $seo_score >= 51 ? 'rm-avg' : ( $seo_score > 0 ? 'rm-bad' : 'rm-none' ) );
                  $edit_url   = add_query_arg( [ 'panel' => 'edit-article', 'post_id' => $post_id ], $_dash_base );
                  $del_url    = add_query_arg( [ 'panel' => 'delete-article', 'post_id' => $post_id ], $_dash_base );

                  // Language via WPML
                  if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'wpml_translations' )
                      || 'unavailable' === $posts_wpml['status']
                      || empty( $posts_wpml['capabilities']['languages'] )
                      || ! function_exists( 'hossam_wpml_get_post_language' ) ) {
                      $lang_name = '—';
                  } else {
                      $post_language = hossam_wpml_get_post_language( $post_id );
                      $lang_name = '' !== $post_language ? strtoupper( $post_language ) : '—';
                  }
                ?>
                <tr>
                  <td><span class="td-title" title="<?php the_title_attribute(); ?>"><?php echo esc_html(get_the_title()); ?></span></td>
                  <td><span class="td-muted"><?php echo esc_html($cat_str); ?></span></td>
                  <td><span style="font-size:11px;font-weight:600;color:var(--txt-2)"><?php echo esc_html($lang_name); ?></span></td>
                  <td><span class="pill <?php echo esc_attr($pill_cls); ?>"><?php echo esc_html($pill_txt); ?></span></td>
                  <td><span class="td-muted"><?php echo esc_html($modified); ?></span></td>
                  <td title="<?php echo $focus_kw ? esc_attr( 'KW: ' . $focus_kw ) : esc_attr( hossam_t( 'No keyword set' ) ); ?>">
                    <?php if ( $can_edit ) : ?>
                    <a href="<?php echo esc_url($edit_url); ?>" class="rm-score <?php echo esc_attr( $rm_cls ); ?>">
                      <?php echo $seo_score > 0 ? esc_html( $seo_score . '%' ) : '—'; ?>
                    </a>
                    <?php else : ?>
                    <span class="rm-score <?php echo esc_attr( $rm_cls ); ?>">
                      <?php echo $seo_score > 0 ? esc_html( $seo_score . '%' ) : '—'; ?>
                    </span>
                    <?php endif; ?>
                    <?php if ( $is_noindex ) : ?><span title="Noindex" style="font-size:10px">🚫</span><?php endif; ?>
                  </td>
                  <td>
                    <div class="articles-tbl-actions">
                      <?php if ( $can_edit ) : ?>
                      <a href="<?php echo esc_url($edit_url); ?>" class="btn btn-sm"><?php echo esc_html( hossam_t( 'Edit' ) ); ?></a>
                      <?php endif; ?>
                      <?php if ( $can_del ) : ?>
                      <a href="<?php echo esc_url($del_url); ?>" class="btn btn-sm btn-danger"><?php echo esc_html( hossam_t( 'Delete' ) ); ?></a>
                      <?php endif; ?>
                      <a href="<?php echo esc_url(get_permalink($post_id)); ?>" target="_blank" class="btn btn-sm btn-ghost"><?php echo esc_html( hossam_t( 'View' ) ); ?></a>
                      <?php if ( $can_edit
                          && HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'wpml_translations' )
                          && 'unavailable' !== $posts_wpml['status']
                          && ! empty( $posts_wpml['capabilities']['languages'] )
                          && function_exists( 'hossam_wpml_get_active_languages' ) ) :
                          $_row_langs    = hossam_wpml_get_active_languages();
                          $_row_cur_lang = hossam_wpml_get_current_language();
                          foreach ( (array) $_row_langs as $_row_lang_code => $_row_lang_info ) :
                              if ( $_row_lang_code === $_row_cur_lang ) {
                                  continue;
                              }
                              $_row_translated_id = hossam_wpml_get_translation_id( $post_id, (string) $_row_lang_code );
                              ?>
                              <?php if ( $_row_translated_id && current_user_can( 'edit_post', $_row_translated_id ) ) : ?>
                              <a href="<?php echo esc_url( add_query_arg( [ 'panel' => 'edit-article', 'post_id' => $_row_translated_id ], $_dash_base ) ); ?>" class="btn btn-sm btn-ghost"><?php echo esc_html( sprintf( hossam_t( 'Edit %s' ), strtoupper( $_row_lang_code ) ) ); ?></a>
                              <?php elseif ( ! $_row_translated_id ) : ?>
                              <a href="#" class="btn btn-sm btn-ghost js-translate-article" data-post="<?php echo esc_attr( $post_id ); ?>" data-lang="<?php echo esc_attr( $_row_lang_code ); ?>"><?php echo esc_html( sprintf( hossam_t( 'Translate to %s' ), strtoupper( $_row_lang_code ) ) ); ?></a>
                              <?php endif; ?>
                          <?php endforeach; ?>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endwhile; wp_reset_postdata(); ?>
            <?php else : ?>
              <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--txt-3);"><?php echo esc_html( hossam_t( 'No articles found.' ) ); ?></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php
        $dash_pagination = paginate_links( [
            'base'      => add_query_arg( ['paged' => '%#%', 'panel' => 'all-articles'] ),
            'format'    => '',
            'current'   => max( 1, intval( $_GET['paged'] ?? 1 ) ),
            'total'     => $articles_query->max_num_pages,
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
            'type'      => 'plain',
        ] );
        if ( $dash_pagination ) :
      ?>
      <div class="dash-pagination" style="display:flex;gap:6px;justify-content:center;align-items:center;margin-top:16px;flex-wrap:wrap;font-size:13px;font-family:'DM Sans',sans-serif">
        <?php echo wp_kses_post( $dash_pagination ); ?>
      </div>
      <?php endif; ?>
      <div id="articles-empty" class="hidden" style="text-align:center;padding:40px;color:var(--txt-3);font-size:13px">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" style="margin:0 auto 8px"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
        <div><?php echo esc_html( hossam_t( 'No articles match the selected filters.' ) ); ?></div>
      </div>
    </div><!-- /panel-all-articles -->
    <?php endif; ?>
    <!-- ══ PANEL: EDIT ARTICLE ══ -->
    <?php if ( $hossam_edit_panel_allowed ) : ?>
    <div class="panel" id="panel-edit-article">
      <div class="page-hdr">
        <div class="page-breadcrumb"><span><?php echo esc_html( hossam_t( 'Dashboard' ) ); ?></span><span>›</span><span><?php echo esc_html( hossam_t( 'Articles' ) ); ?></span><span>›</span><span class="crumb-active"><?php echo esc_html( hossam_t( 'Edit Article' ) ); ?></span></div>
        <h1><?php echo esc_html( hossam_t( 'Edit Article' ) ); ?></h1>
        <p><?php echo esc_html( hossam_t( 'Edit an existing post. Changes are saved immediately upon submission.' ) ); ?></p>
      </div>
      <div class="card mb-14">
        <?php
          $hossam_edit_resource_allowed = false;
          if ( $url_post_id ) {
              $the_post = get_post( $url_post_id );
              if ( ! $the_post || 'post' !== $the_post->post_type || $the_post->post_status === 'trash' ) {
                  echo '<div class="info-card">' . esc_html( hossam_t( 'Article not found.' ) ) . '</div>';
              } elseif ( ! current_user_can( 'edit_post', $url_post_id ) ) {
                  // بند 3 (الدفعة الثانية): فحص على مستوى المقال عبر map_meta_cap
                  // بدل مقارنة post_author اليدوية (مرجع سطر 142). الـendpoint يعيد
                  // التحقق خادميًا؛ إخفاء النموذج هنا ليس تفويضًا.
                  echo '<div class="info-card" style="color:var(--danger)">' . esc_html( hossam_t( 'You do not have permission to edit this article.' ) ) . '</div>';
              } else {
                  $hossam_edit_resource_allowed = true;
                  $hossam_edit_cats = wp_get_post_categories( $the_post->ID );
                  $hossam_can_publish = current_user_can( 'publish_posts' );
                  $hossam_cur_status  = get_post_status( $the_post );
                  ?>
                  <!-- Core-first: تعبئة خادمية كاملة ثم إرسال إلى hossam_update_article -->
                   <form id="hossam-post-edit-form" onsubmit="event.preventDefault(); hossamUpdateArticle(this); return false;">
                    <input type="hidden" name="post_id" value="<?php echo esc_attr( $the_post->ID ); ?>" />
                    <div class="form-group mb-8">
                      <label class="form-label" for="hossam-edit-title"><?php echo esc_html( hossam_t( 'Title' ) ); ?></label>
                      <input type="text" class="form-input" id="hossam-edit-title" name="title" maxlength="255" value="<?php echo esc_attr( $the_post->post_title ); ?>" />
                    </div>
                    <div class="form-group mb-8">
                      <label class="form-label" for="hossam-edit-content"><?php echo esc_html( hossam_t( 'Content' ) ); ?></label>
                      <textarea class="form-input" id="hossam-edit-content" name="content" rows="12"><?php echo esc_textarea( $the_post->post_content ); ?></textarea>
                    </div>
                    <div class="form-group mb-8">
                      <label class="form-label" for="hossam-edit-cats"><?php echo esc_html( hossam_t( 'Categories' ) ); ?></label>
                      <input type="text" class="form-input" id="hossam-edit-cats" name="categories" inputmode="numeric" value="<?php echo esc_attr( implode( ',', $hossam_edit_cats ) ); ?>" />
                    </div>
                    <?php if ( 'publish' === $hossam_cur_status && ! $hossam_can_publish ) : ?>
                    <?php /* مقال منشور ومستخدم بلا publish_posts: لا حقل حالة أصلًا — يبقى المنشور منشورًا */ ?>
                    <?php else : ?>
                    <div class="form-group mb-8">
                      <label class="form-label" for="hossam-edit-status"><?php echo esc_html( hossam_t( 'Status' ) ); ?></label>
                      <select class="filter-select" id="hossam-edit-status" name="status">
                        <?php if ( $hossam_can_publish || in_array( $hossam_cur_status, [ 'draft', 'pending' ], true ) ) : ?>
                        <option value="draft" <?php selected( $hossam_cur_status, 'draft' ); ?>><?php echo esc_html( hossam_t( 'Draft' ) ); ?></option>
                        <option value="pending" <?php selected( $hossam_cur_status, 'pending' ); ?>><?php echo esc_html( hossam_t( 'Pending Review' ) ); ?></option>
                        <?php endif; ?>
                        <?php if ( $hossam_can_publish || 'publish' === $hossam_cur_status ) : ?>
                        <option value="publish" <?php selected( $hossam_cur_status, 'publish' ); ?>><?php echo esc_html( hossam_t( 'Published' ) ); ?></option>
                        <?php endif; ?>
                      </select>
                    </div>
                    <?php endif; ?>
                    <div class="form-actions mt-12">
                      <button type="submit" class="btn btn-gold"><?php echo esc_html( hossam_t( 'Save Article' ) ); ?></button>
                    </div>
                  </form>
                  <?php if ( HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'ai' ) && function_exists( 'hossam_ai_interface_available' ) && hossam_ai_interface_available() ) : ?>
                  <!-- الدفعة الثالثة (7.4): داخل فرع الصلاحية حصرًا — edit_post على
                       مستوى المقال مثبت أعلاه، والقدرة إثبتت خادميًا الآن.
                       (الدفعة 7: + بوابة المالك B3-08 is_feature_enabled('ai')). -->
                  <div class="card mt-12">
                    <div class="card-sub mb-8"><?php echo esc_html( hossam_t( 'AI Writing Assistant' ) ); ?></div>
                    <div class="filter-bar">
                      <button type="button" class="btn btn-sm js-ai-run" data-job-type="grammar" data-post-id="<?php echo esc_attr( $the_post->ID ); ?>" data-source="hossam-edit-content" data-result="ai-suggestion-result-edit"><?php echo esc_html( hossam_t( 'Grammar Check' ) ); ?></button>
                      <input type="text" class="filter-select" id="hossam-ai-target-edit" maxlength="8" placeholder="<?php echo esc_attr( hossam_t( 'Target language code' ) ); ?>" />
                      <button type="button" class="btn btn-sm js-ai-run" data-job-type="translation" data-post-id="<?php echo esc_attr( $the_post->ID ); ?>" data-source="hossam-edit-content" data-lang-source="hossam-ai-target-edit" data-result="ai-suggestion-result-edit"><?php echo esc_html( hossam_t( 'Suggest Translation' ) ); ?></button>
                      <button type="button" class="btn btn-sm js-ai-run" data-job-type="seo" data-post-id="<?php echo esc_attr( $the_post->ID ); ?>" data-source="hossam-edit-content" data-title-source="hossam-edit-title" data-result="ai-suggestion-result-edit"><?php echo esc_html( hossam_t( 'Suggest SEO' ) ); ?></button>
                      <button type="button" class="btn btn-sm js-ai-run" data-job-type="improvement" data-post-id="<?php echo esc_attr( $the_post->ID ); ?>" data-source="hossam-edit-content" data-result="ai-suggestion-result-edit"><?php echo esc_html( hossam_t( 'Suggest Improvements' ) ); ?></button>
                    </div>
                    <div id="ai-suggestion-result-edit" class="hidden"></div>
                  </div>
                  <?php endif; ?>
                  <?php
              }
          } else {
              echo '<div class="info-card">
                  <p>' . sprintf(
                      hossam_t( 'Select an article from %s and click Edit to load the form here.' ),
                      '<strong>' . esc_html( hossam_t( 'All My Articles' ) ) . '</strong>'
                  ) . '</p>
                  <button class="btn btn-gold mt-8" onclick="nav(document.querySelector(\'[data-panel=all-articles]\'),\'all-articles\')">' . esc_html( hossam_t( 'Go to My Articles' ) ) . '</button>
              </div>';
          }
        ?>
      </div>
      <?php if ( $hossam_edit_resource_allowed && $posts_frontend_fallback ) : ?>
      <!-- Fallback محدد فقط (فشل تقني من posts.js) — لا يفتح عند رفض أمني -->
      <div class="card" id="fa-fallback-edit" style="display:none">
        <?php echo do_shortcode( '[frontend_admin form=' . HOSSAM_FA_EDIT_FORM . ' post_id="' . $url_post_id . '"]' ); ?>
      </div>
      <?php endif; ?>
    </div><!-- /panel-edit-article -->
    <?php endif; ?>


    <!-- ══ PANEL: DELETE ARTICLE ══ -->
    <?php if ( $hossam_delete_panel_allowed ) : ?>
    <div class="panel" id="panel-delete-article">
      <div class="page-hdr">
        <div class="page-breadcrumb"><span><?php echo esc_html( hossam_t( 'Dashboard' ) ); ?></span><span>›</span><span><?php echo esc_html( hossam_t( 'Articles' ) ); ?></span><span>›</span><span class="crumb-active"><?php echo esc_html( hossam_t( 'Delete Article' ) ); ?></span></div>
        <h1><?php echo esc_html( hossam_t( 'Delete Article' ) ); ?></h1>
        <p><?php echo esc_html( hossam_t( 'Move a post to trash — requires confirmation. Action cannot be undone from this interface.' ) ); ?></p>
        <span class="data-tag" style="background:var(--danger-bg);color:var(--danger);">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
          <?php echo esc_html( hossam_t( 'Moves to trash only — not permanent' ) ); ?>
        </span>
      </div>
      <div class="card mb-14">
        <?php
          $hossam_delete_resource_allowed = false;
          if ( $url_post_id ) {
              $the_post = get_post( $url_post_id );
              if ( ! $the_post || 'post' !== $the_post->post_type || $the_post->post_status === 'trash' ) {
                  echo '<div class="info-card">' . esc_html( hossam_t( 'Article not found.' ) ) . '</div>';
              } elseif ( ! current_user_can( 'edit_post', $url_post_id ) ) {
                  // بند 3 (الدفعة الثانية): edit_post على مستوى المقال؛
                  // الـendpoint (hossam_trash_article) يعيد التحقق خادميًا.
                  echo '<div class="info-card" style="color:var(--danger)">' . esc_html( hossam_t( 'You do not have permission to delete this article.' ) ) . '</div>';
              } else {
                  $hossam_delete_resource_allowed = true;
                  ?>
                  <!-- Core-first: نقل إلى السلة عبر hossam_trash_article بعد تأكيد صريح -->
                  <div class="info-card">
                    <p><?php
                      printf(
                        /* translators: %s: article title */
                        esc_html( hossam_t( 'Move “%s” to trash?' ) ),
                        esc_html( get_the_title( $the_post ) )
                      );
                    ?></p>
                    <button type="button" class="btn btn-danger mt-8" onclick="hossamTrashArticle(<?php echo esc_attr( $the_post->ID ); ?>, this)"><?php echo esc_html( hossam_t( 'Move to Trash' ) ); ?></button>
                  </div>
                  <?php
              }
          } else {
              echo '<div class="info-card">
                  <p>' . sprintf(
                      hossam_t( 'Select an article from %s and click Delete to load the confirmation form here.' ),
                      '<strong>' . esc_html( hossam_t( 'All My Articles' ) ) . '</strong>'
                  ) . '</p>
                  <button class="btn mt-8" onclick="nav(document.querySelector(\'[data-panel=all-articles]\'),\'all-articles\')">' . esc_html( hossam_t( 'Go to My Articles' ) ) . '</button>
              </div>';
          }
        ?>
      </div>
      <?php if ( $hossam_delete_resource_allowed && $posts_frontend_fallback ) : ?>
      <!-- Fallback محدد فقط (فشل تقني من posts.js) — لا يفتح عند رفض أمني -->
      <div class="card" id="fa-fallback-delete" style="display:none">
        <?php echo do_shortcode( '[frontend_admin form=' . HOSSAM_FA_DELETE_FORM . ' post_id="' . $url_post_id . '"]' ); ?>
      </div>
      <?php endif; ?>
    </div><!-- /panel-delete-article -->
    <?php endif; ?>


    <!-- ══ PANEL: TRASH (Articles) ══ -->
    <?php if ( $sees_content ) : ?>
    <div class="panel" id="panel-trash-articles">
      <div class="page-hdr">
        <div class="page-breadcrumb"><span><?php echo esc_html( hossam_t( 'Dashboard' ) ); ?></span><span>›</span><span class="crumb-active"><?php echo esc_html( hossam_t( 'Trash' ) ); ?></span></div>
        <h1><?php echo esc_html( hossam_t( 'Trash' ) ); ?></h1>
        <p><?php echo esc_html( hossam_t( 'Restore recently deleted articles.' ) ); ?></p>
      </div>
      <div class="card">
        <?php
        $_trashed_articles = get_posts( [
            'post_type'      => 'post',
            'post_status'    => 'trash',
            'author'         => $author_filter ? $user_id : null,
            'posts_per_page' => 50,
        ] );
        ?>
        <?php if ( $_trashed_articles ) : ?>
        <div class="tbl-wrap">
          <table>
            <thead>
              <tr>
                <th><?php echo esc_html( hossam_t( 'Title' ) ); ?></th>
                <th><?php echo esc_html( hossam_t( 'Deleted' ) ); ?></th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ( $_trashed_articles as $_trashed_post ) : ?>
              <tr>
                <td><span class="td-title"><?php echo esc_html( $_trashed_post->post_title ); ?></span></td>
                <td><span class="td-muted"><?php echo esc_html( get_the_modified_date( 'M j, Y', $_trashed_post ) ); ?></span></td>
                <td>
                  <?php if ( current_user_can( 'edit_post', $_trashed_post->ID ) ) : ?>
                  <a href="#" class="btn btn-sm js-restore-article" data-id="<?php echo esc_attr( $_trashed_post->ID ); ?>"><?php echo esc_html( hossam_t( 'Restore' ) ); ?></a>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else : ?>
        <div class="ph-notice"><?php echo esc_html( hossam_t( 'Trash is empty.' ) ); ?></div>
        <?php endif; ?>
      </div>
    </div><!-- /panel-trash-articles -->
    <?php endif; ?>
