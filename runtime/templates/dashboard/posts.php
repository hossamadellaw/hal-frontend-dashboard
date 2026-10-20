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
// v1.0.6 §9: بوابة إظهار المحرر الغني — مفتاح المالك rich_editor (نمط
// is_feature_enabled)؛ fail-closed (المفتاح مسجل في FEATURES بافتراضي مغلق
// مقصود حتى تخصيصه من مالك الإعدادات — والـtextarea يبقى حقل النموذج).
// فحص القدرة لكل لوحة عند موضعها.
$hossam_rich_editor_feature = class_exists( 'HAL_Frontend_Dashboard_Settings_Repository' )
    && method_exists( 'HAL_Frontend_Dashboard_Settings_Repository', 'is_feature_enabled' )
    && HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'rich_editor' );
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
            <?php if ( $hossam_rich_editor_feature ) : ?>
            <!-- v1.0.6 §9: موضع المحرر الغني (inserter/أدوات/canvas) داخل حقل
                 المحتوى بقالب HAL — الـtextarea أعلاه يبقى حقل النموذج ظاهرًا
                 ولا يُخفى إلا بعد نجاح التهيئة (posts.js)؛ التهيئة عند أول
                 فتح اللوحة. اللوحة نفسها مشروطة بـ$sees_content (edit_posts). -->
            <div class="hossam-rich-editor" id="hossam-rich-editor-new" data-textarea="hossam-new-content" hidden>
              <div class="hossam-rich-editor-inserter" data-role="inserter"></div>
              <div class="hossam-rich-editor-toolbar" data-role="toolbar"></div>
              <div class="hossam-rich-editor-canvas" data-role="canvas"></div>
            </div>
            <?php endif; ?>
          </div>
          <div class="form-group mb-8">
            <label class="form-label" for="hossam-new-excerpt"><?php echo esc_html( hossam_t( 'Excerpt' ) ); ?></label>
            <textarea class="form-input" id="hossam-new-excerpt" name="excerpt" rows="3"></textarea>
          </div>
          <div class="form-group mb-8">
            <span class="form-label"><?php echo esc_html( hossam_t( 'Categories' ) ); ?></span>
            <?php
              // v1.0.6 §3-P4: parent-children tree (hierarchical order + RTL
              // indent), collapsible group open by default in a scroll box,
              // one language badge per term via the official WPML term
              // interface. Display only — the categories[] contract (ID value)
              // is unchanged.
              $hossam_write_cats = get_categories( [ 'hide_empty' => false ] );
              $hossam_write_kids = array();
              foreach ( $hossam_write_cats as $hossam_write_cat ) {
                  $hossam_write_parent = isset( $hossam_write_cat->parent ) ? (int) $hossam_write_cat->parent : 0;
                  $hossam_write_kids[ $hossam_write_parent ][] = $hossam_write_cat;
              }
              $hossam_write_walk = function ( $hossam_walk_parent, $hossam_walk_depth ) use ( &$hossam_write_walk, $hossam_write_kids ) {
                  foreach ( (array) ( $hossam_write_kids[ $hossam_walk_parent ] ?? array() ) as $hossam_write_cat ) {
                      $hossam_write_ttid = isset( $hossam_write_cat->term_taxonomy_id ) ? (int) $hossam_write_cat->term_taxonomy_id : (int) $hossam_write_cat->term_id;
                      $hossam_write_lang = '';
                      if ( defined( 'ICL_SITEPRESS_VERSION' ) && has_filter( 'wpml_element_language_code' ) ) {
                          $hossam_write_etype = apply_filters( 'wpml_element_type', 'category' );
                          $hossam_write_lraw = apply_filters( 'wpml_element_language_code', null, array( 'element_id' => $hossam_write_ttid, 'element_type' => $hossam_write_etype ) );
                          if ( is_string( $hossam_write_lraw ) && '' !== $hossam_write_lraw ) {
                              $hossam_write_lang = strtoupper( $hossam_write_lraw );
                          }
                      }
                      echo '<label style="display:block;margin-right:' . (int) ( $hossam_walk_depth * 16 ) . 'px;"><input type="checkbox" name="categories[]" value="' . esc_attr( $hossam_write_cat->term_id ) . '" /> ' . esc_html( $hossam_write_cat->name );
                      if ( '' !== $hossam_write_lang ) {
                          echo ' <span class="data-tag">' . esc_html( $hossam_write_lang ) . '</span>';
                      }
                      echo '</label>';
                      $hossam_write_walk( (int) $hossam_write_cat->term_id, $hossam_walk_depth + 1 );
                  }
              };
              echo '<div style="max-height:180px;overflow:auto;"><details open><summary>' . esc_html( hossam_t( 'Categories' ) ) . '</summary>';
              $hossam_write_walk( 0, 0 );
              echo '</details></div>';
            ?>
          </div>
          <?php if ( current_user_can( 'upload_files' ) ) : ?>
          <div class="form-group mb-8">
            <span class="form-label"><?php echo esc_html( hossam_t( 'Featured Image' ) ); ?></span>
            <input type="hidden" id="hossam-new-thumbnail" name="thumbnail_id" value="" />
            <div><img id="hossam-new-thumbnail-preview" alt="" style="display:none;max-width:120px;height:auto;" /></div>
            <button type="button" class="btn btn-sm" id="hossam-new-thumbnail-btn"><?php echo esc_html( hossam_t( 'Set featured image' ) ); ?></button>
            <button type="button" class="btn btn-sm btn-ghost" id="hossam-new-thumbnail-remove" style="display:none"><?php echo esc_html( hossam_t( 'Remove featured image' ) ); ?></button>
          </div>
          <?php endif; ?>
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
                      <?php if ( $hossam_rich_editor_feature ) : ?>
                      <!-- v1.0.6 §9: موضع المحرر الغني في لوحة التحرير داخل حقل
                           المحتوى بقالب HAL — نفس عقد لوحة الكتابة: الـtextarea
                           أعلاه يبقى حقل النموذج ظاهرًا ولا يُخفى إلا بعد نجاح
                           التهيئة (posts.js)؛ التهيئة عند أول فتح اللوحة. هذا
                           الفرع داخل $hossam_edit_resource_allowed (edit_post
                           لهذا المقال مثبت أعلاه) — فحص الخادم باقٍ. -->
                      <div class="hossam-rich-editor" id="hossam-rich-editor-edit" data-textarea="hossam-edit-content" hidden>
                        <div class="hossam-rich-editor-inserter" data-role="inserter"></div>
                        <div class="hossam-rich-editor-toolbar" data-role="toolbar"></div>
                        <div class="hossam-rich-editor-canvas" data-role="canvas"></div>
                      </div>
                      <?php endif; ?>
                    </div>
                    <div class="form-group mb-8">
                      <label class="form-label" for="hossam-edit-excerpt"><?php echo esc_html( hossam_t( 'Excerpt' ) ); ?></label>
                      <textarea class="form-input" id="hossam-edit-excerpt" name="excerpt" rows="3"><?php echo esc_textarea( $the_post->post_excerpt ?? '' ); ?></textarea>
                    </div>
                    <div class="form-group mb-8">
                      <span class="form-label"><?php echo esc_html( hossam_t( 'Categories' ) ); ?></span>
                      <?php
                        // v1.0.6 §3-P4: parent-children tree (hierarchical order
                        // + RTL indent), collapsible group open by default in a
                        // scroll box, one language badge per term via the
                        // official WPML term interface. Display only — the
                        // categories[] contract (ID value) and the disclosure
                        // text below are unchanged.
                        $hossam_edit_cat_list = get_categories( [ 'hide_empty' => false ] );
                        $hossam_edit_kids = array();
                        foreach ( $hossam_edit_cat_list as $hossam_edit_cat ) {
                            $hossam_edit_parent = isset( $hossam_edit_cat->parent ) ? (int) $hossam_edit_cat->parent : 0;
                            $hossam_edit_kids[ $hossam_edit_parent ][] = $hossam_edit_cat;
                        }
                        $hossam_edit_walk = function ( $hossam_walk_parent, $hossam_walk_depth ) use ( &$hossam_edit_walk, $hossam_edit_kids, $hossam_edit_cats ) {
                            foreach ( (array) ( $hossam_edit_kids[ $hossam_walk_parent ] ?? array() ) as $hossam_edit_cat ) {
                                $hossam_cat_checked = in_array( (int) $hossam_edit_cat->term_id, array_map( 'intval', (array) $hossam_edit_cats ), true ) ? ' checked' : '';
                                $hossam_edit_ttid = isset( $hossam_edit_cat->term_taxonomy_id ) ? (int) $hossam_edit_cat->term_taxonomy_id : (int) $hossam_edit_cat->term_id;
                                $hossam_edit_lang = '';
                                if ( defined( 'ICL_SITEPRESS_VERSION' ) && has_filter( 'wpml_element_language_code' ) ) {
                                    $hossam_edit_etype = apply_filters( 'wpml_element_type', 'category' );
                                    $hossam_edit_lraw = apply_filters( 'wpml_element_language_code', null, array( 'element_id' => $hossam_edit_ttid, 'element_type' => $hossam_edit_etype ) );
                                    if ( is_string( $hossam_edit_lraw ) && '' !== $hossam_edit_lraw ) {
                                        $hossam_edit_lang = strtoupper( $hossam_edit_lraw );
                                    }
                                }
                                echo '<label style="display:block;margin-right:' . (int) ( $hossam_walk_depth * 16 ) . 'px;"><input type="checkbox" name="categories[]" value="' . esc_attr( $hossam_edit_cat->term_id ) . '"' . $hossam_cat_checked . ' /> ' . esc_html( $hossam_edit_cat->name );
                                if ( '' !== $hossam_edit_lang ) {
                                    echo ' <span class="data-tag">' . esc_html( $hossam_edit_lang ) . '</span>';
                                }
                                echo '</label>';
                                $hossam_edit_walk( (int) $hossam_edit_cat->term_id, $hossam_walk_depth + 1 );
                            }
                        };
                        echo '<div style="max-height:180px;overflow:auto;"><details open><summary>' . esc_html( hossam_t( 'Categories' ) ) . '</summary>';
                        $hossam_edit_walk( 0, 0 );
                        echo '</details></div>';
                      ?>
                      <p class="form-hint"><?php echo esc_html( hossam_t( 'Leave all unchecked to keep the current categories.' ) ); ?></p>
                    </div>
                    <?php if ( current_user_can( 'upload_files' ) ) :
                      $hossam_edit_thumb_id = function_exists( 'get_post_thumbnail_id' ) ? (int) get_post_thumbnail_id( $the_post->ID ) : 0;
                      $hossam_edit_thumb_url = ( $hossam_edit_thumb_id > 0 && function_exists( 'wp_get_attachment_url' ) ) ? (string) wp_get_attachment_url( $hossam_edit_thumb_id ) : '';
                    ?>
                    <div class="form-group mb-8">
                      <span class="form-label"><?php echo esc_html( hossam_t( 'Featured Image' ) ); ?></span>
                      <input type="hidden" id="hossam-edit-thumbnail" name="thumbnail_id" value="<?php echo esc_attr( $hossam_edit_thumb_id > 0 ? (string) $hossam_edit_thumb_id : '' ); ?>" />
                      <div><img id="hossam-edit-thumbnail-preview" src="<?php echo esc_url( $hossam_edit_thumb_url ); ?>" alt="" style="<?php echo '' !== $hossam_edit_thumb_url ? 'max-width:120px;height:auto;' : 'display:none;max-width:120px;height:auto;'; ?>" /></div>
                      <button type="button" class="btn btn-sm" id="hossam-edit-thumbnail-btn"><?php echo esc_html( hossam_t( 'Set featured image' ) ); ?></button>
                      <?php if ( $hossam_edit_thumb_id > 0 ) : ?>
                      <button type="button" class="btn btn-sm btn-ghost" id="hossam-edit-thumbnail-remove"><?php echo esc_html( hossam_t( 'Remove featured image' ) ); ?></button>
                      <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php
                    // v1.0.6 P1: Permalink عرض قراءة فقط (get_permalink) —
                    // رابط المسودة موسوم «مؤقت — النهائي عند النشر». لا حقل تحرير slug.
                    $hossam_edit_permalink = function_exists( 'get_permalink' ) ? (string) get_permalink( $the_post->ID ) : '';
                    if ( '' !== $hossam_edit_permalink ) :
                    ?>
                    <div class="form-group mb-8">
                      <span class="form-label"><?php echo esc_html( hossam_t( 'Permalink' ) ); ?></span>
                      <div>
                        <a href="<?php echo esc_url( $hossam_edit_permalink ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $hossam_edit_permalink ); ?></a>
                        <?php if ( in_array( $hossam_cur_status, [ 'draft', 'pending', 'future' ], true ) ) : ?>
                        <span class="data-tag"><?php echo esc_html( hossam_t( 'مؤقت — النهائي عند النشر' ) ); ?></span>
                        <?php endif; ?>
                      </div>
                    </div>
                    <?php endif; ?>
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
                  <?php
                  // v1.0.6 §8.5: مجموعة SEO في لوحة التحرير فقط — خلف البوابتين:
                  // مفتاح المالك rank_math_seo + can_write من السجل (نفس عقد
                  // seo.php: بوابة available + plugin_contract + verified_fields).
                  // لوحة الكتابة بلا مجموعة SEO (المقال غير موجود = حقول وهمية).
                  $hossam_edit_seo_can_write = HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'rank_math_seo' )
                      && 'available' === ( $posts_rank_math['status'] ?? '' )
                      && ! empty( $posts_rank_math['capabilities']['plugin_contract'] )
                      && ! empty( $posts_rank_math['capabilities']['verified_fields'] );
                  ?>
                  <?php if ( $hossam_edit_seo_can_write && function_exists( 'hossam_get_rankmath_seo_data' ) ) :
                      $hossam_edit_seo = hossam_get_rankmath_seo_data( $the_post->ID );
                      $hossam_edit_seo_title = isset( $hossam_edit_seo['title'] ) ? (string) $hossam_edit_seo['title'] : '';
                      $hossam_edit_seo_kw = isset( $hossam_edit_seo['focus_keyword'] ) ? (string) $hossam_edit_seo['focus_keyword'] : '';
                      $hossam_edit_seo_desc = isset( $hossam_edit_seo['description'] ) ? (string) $hossam_edit_seo['description'] : '';
                      $hossam_edit_seo_robots = $hossam_edit_seo['robots'] ?? [];
                      $hossam_edit_seo_score = isset( $hossam_edit_seo['seo_score'] ) ? (int) $hossam_edit_seo['seo_score'] : 0;
                      $hossam_edit_seo_options = function_exists( 'hossam_get_allowed_rank_math_robots' ) ? hossam_get_allowed_rank_math_robots() : [];
                  ?>
                  <div class="card mt-12" id="hossam-edit-seo-group">
                    <div class="card-sub mb-8"><?php echo esc_html( hossam_t( 'SEO' ) ); ?></div>
                    <div class="form-group mb-8">
                      <span class="form-label"><?php echo esc_html( hossam_t( 'SEO Score' ) ); ?></span>
                      <span id="hossam-edit-seo-score" class="td-muted"><?php echo esc_html( $hossam_edit_seo_score > 0 ? $hossam_edit_seo_score . '%' : '—' ); ?></span>
                    </div>
                    <form id="hossam-edit-seo-form" onsubmit="event.preventDefault(); hossamSaveSeo(this); return false;">
                      <input type="hidden" name="post_id" value="<?php echo esc_attr( $the_post->ID ); ?>" />
                      <div class="form-group mb-8">
                        <label class="form-label" for="hossam-edit-seo-title"><?php echo esc_html( hossam_t( 'Title' ) ); ?></label>
                        <input type="text" class="form-input" id="hossam-edit-seo-title" name="title" maxlength="255" value="<?php echo esc_attr( $hossam_edit_seo_title ); ?>" />
                      </div>
                      <div class="form-group mb-8">
                        <label class="form-label" for="hossam-edit-seo-kw"><?php echo esc_html( hossam_t( 'Focus Keyword' ) ); ?></label>
                        <input type="text" class="form-input" id="hossam-edit-seo-kw" name="focus_keyword" maxlength="255" value="<?php echo esc_attr( $hossam_edit_seo_kw ); ?>" />
                      </div>
                      <div class="form-group mb-8">
                        <label class="form-label" for="hossam-edit-seo-desc"><?php echo esc_html( hossam_t( 'Meta Description' ) ); ?></label>
                        <textarea class="form-input" id="hossam-edit-seo-desc" name="description" rows="3"><?php echo esc_textarea( $hossam_edit_seo_desc ); ?></textarea>
                      </div>
                      <div class="form-group mb-8">
                        <span class="form-label"><?php echo esc_html( hossam_t( 'Robots' ) ); ?></span>
                        <div class="flex gap-8" style="flex-wrap:wrap">
                          <?php foreach ( $hossam_edit_seo_options as $hossam_edit_seo_option ) : ?>
                          <label style="display:inline-flex;align-items:center;gap:4px">
                            <input type="checkbox" name="robots[]" value="<?php echo esc_attr( $hossam_edit_seo_option ); ?>"<?php echo ( is_array( $hossam_edit_seo_robots ) ? in_array( $hossam_edit_seo_option, $hossam_edit_seo_robots, true ) : str_contains( (string) $hossam_edit_seo_robots, $hossam_edit_seo_option ) ) ? ' checked' : ''; ?> />
                            <?php echo esc_html( $hossam_edit_seo_option ); ?>
                          </label>
                          <?php endforeach; ?>
                        </div>
                      </div>
                      <div class="form-actions mt-12">
                        <button type="submit" class="btn btn-gold"><?php echo esc_html( hossam_t( 'Save SEO' ) ); ?></button>
                      </div>
                    </form>
                  </div>
                  <?php endif; ?>
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
