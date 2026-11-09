<?php
/**
 * runtime/templates/dashboard/posts.php — the five articles panels (batch 7)
 * ══════════════════════════════════════════════════════════════
 * Source: legacy theme/template-parts/dashboard/posts.php (the source
 * fingerprint byte-pinned in the batch-7 inventory — snapshot 67044A2F…).
 *
 * The only declared deltas (owner decision B3-08 — option A + closing a
 * proven migration gap):
 *   1. Restore the HOSSAM_FA_WRITE_FORM/EDIT/DELETE constant definitions
 *      with a defined() guard — the legacy source defined them in
 *      "original dashboard/hossam-dashboard.php" [H-04] T2-01
 *      (get_option with the same names and default values 3727/3805/3813)
 *      and they had no place in the migration map; without them a fatal
 *      fails at the frontend_admin fallback branch (PHP 8: Undefined
 *      constant). Defined here at the single consumption point with the
 *      same source contract.
 *   2. Recompute the local $sees_content with the owner gate
 *      is_feature_enabled('posts') added — the source condition (edit_posts)
 *      remains.
 *   3. The two AI button cards (panel-write/panel-edit-article) get the
 *      owner gate is_feature_enabled('ai') added — the source conditions
 *      (capability via hossam_ai_interface_available + post permission)
 *      remain.
 *   4. Per-row SEO reading gets the owner gate
 *      is_feature_enabled('rank_math_seo') added — the source conditions
 *      (post permission and rank_math status) remain.
 *   5. Per-row translation buttons get the owner gate
 *      is_feature_enabled('wpml_translations') added — the source conditions
 *      (post permission and WPML status and languages) remain.
 *   6. v1.1.0 merge (row R23): an OEB button inside the articles-table
 *      actions cell — administrators (manage_options) × the official mode
 *      from get_effective_editor_mode() only; absent from the DOM entirely
 *      for non-admins. + one native <dialog> with no library inside the
 *      articles panel (status text + manual fallback link with the same
 *      editorUrl + a close button — the three OEB key texts R41 via
 *      hossam_t). + the two rich carriers become conditional on
 *      feature && 'off' !== mode — official keeps embed for non-admins.
 *      No new table column; the textarea remains the truth field.
 *
 * Explicit args: receives its variables from the shell via the Runtime
 * parts loader (render_part) — the args list documented in
 * runtime/templates/dashboard.php.
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// [H-04] T2-01 — Form ID Constants (restored from original dashboard/
// hossam-dashboard.php:298-303 — same names, options and defaults)
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
// v1.0.6 §9: rich editor display gate — the owner key rich_editor (the
// is_feature_enabled pattern); fail-closed (the key is registered in FEATURES
// with an intentionally closed default until the settings owner customizes
// it — and the textarea remains the form field).
// Capability check per panel at its own position.
$hossam_rich_editor_feature = class_exists( 'HAL_Frontend_Dashboard_Settings_Repository' )
    && method_exists( 'HAL_Frontend_Dashboard_Settings_Repository', 'is_feature_enabled' )
    && HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'rich_editor' );
// v1.1.0 OEB (merge R23): the effective mode from get_effective_editor_mode()
// only (R35) — off switches the two rich carriers off; official enables the
// OEB button for administrators only and keeps the carriers for non-admins
// (the owner guarantee for both accounts).
$hossam_editor_mode = ( $hossam_rich_editor_feature
    && method_exists( 'HAL_Frontend_Dashboard_Settings_Repository', 'get_effective_editor_mode' ) )
    ? HAL_Frontend_Dashboard_Settings_Repository::get_effective_editor_mode()
    : 'off';
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
      <?php
      // v1.1.1 C1: the AI gate itself verbatim (evaluated once for the space
      // column class and for rendering the AI card — a pure read, no new
      // decision).
      $hossam_write_ai_on = current_user_can( 'edit_posts' )
          && HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'ai' )
          && function_exists( 'hossam_ai_interface_available' )
          && hossam_ai_interface_available();
      ?>
      <!-- v1.1.1 C1: re-capsuling per the approved model
           (v1.0.9-article-editor-v2) — a two-column workspace: the writing
           column (title / content panel with its attached tools / actions) +
           the independent AI card in the side column. Every
           id/name/data-attribute consumed by posts.js/setup.php is preserved
           verbatim (zero JS touch). The model is structural reference for the
           shell only: no editing engine and no direct edit field — the
           existing BlockCanvas is the only surface, and the toolbar is manual
           from posts.js. -->
      <!-- v1.1.3 W6: the side column is permanent (the three display holders
           below keep it present regardless of the AI card) — the writing
           column stays the single form (never split). -->
      <div class="hossam-write-workspace hossam-write-has-aside">
        <!-- Core-first (second batch): the primary path hossam_create_article —
             the author is assigned from the server session, and no AI here
             (item 3 constraint). -->
        <form id="hossam-post-create-form" onsubmit="event.preventDefault(); hossamCreateArticle(this); return false;" class="hossam-write-primary">
          <div class="card hossam-write-card">
          <!-- (1) Title row — the model: title-input-container (shell + H1
               badge + heading-policy line). The H1 badge is a reminder of the
               existing governance policy ([R21-A] H1→H2 normalization on
               paste in the editor). -->
          <div class="form-group mb-8 hossam-write-title-group">
            <label class="form-label" for="hossam-new-title"><?php echo esc_html( hossam_t( 'Title' ) ); ?></label>
            <div class="hossam-write-title-shell">
              <span class="hossam-write-h1-badge" title="<?php echo esc_attr( __( 'Heading policy: H1 is reserved for the main title only — the article body starts at H2 or above.', 'hal-frontend-dashboard' ) ); ?>">H1</span>
              <input type="text" class="form-input hossam-write-title-input" id="hossam-new-title" name="title" maxlength="255" placeholder="<?php echo esc_attr( __( 'Enter the article title here…', 'hal-frontend-dashboard' ) ); ?>" />
            </div>
            <div class="hossam-write-title-hint">
              <span class="hossam-write-policy-pill">H1</span>
              <span><?php echo esc_html( __( 'H1 is reserved for the title only — the article body starts at H2 or above.', 'hal-frontend-dashboard' ) ); ?></span>
            </div>
          </div>
          <!-- (2) Content panel — the model: an attached toolbar above the
               canvas in one static box (pro-toolbar-container +
               editor-canvas-surface without separation or pinning — the v2.8
               decision). The shell holds the textarea (the permanent form
               field and truth-source carrier) and the rich carrier; posts.js
               hides the textarea only after successful initialization (the
               textarea-fallback contract, untouched). -->
          <div class="form-group mb-8 hossam-write-content-group">
            <label class="form-label" for="hossam-new-content"><?php echo esc_html( hossam_t( 'Content' ) ); ?></label>
            <div class="hossam-write-editor-surface">
              <textarea class="form-input hossam-write-content-input" id="hossam-new-content" name="content" rows="10"></textarea>
              <?php if ( $hossam_rich_editor_feature && 'off' !== $hossam_editor_mode ) : ?>
              <!-- v1.0.6 §9: the rich editor position (inserter/tools/canvas)
                   inside the content field of the HAL template — the textarea
                   above remains the visible form field and is not hidden
                   until initialization succeeds (posts.js); initialization on
                   first panel open. The panel itself is conditional on
                   $sees_content (edit_posts). -->
              <div class="hossam-rich-editor" id="hossam-rich-editor-new" data-textarea="hossam-new-content" hidden>
                <div class="hossam-rich-editor-inserter" data-role="inserter"></div>
                <div class="hossam-rich-editor-toolbar" data-role="toolbar"></div>
                <div class="hossam-rich-editor-canvas" data-role="canvas"></div>
              </div>
              <?php endif; ?>
            </div>
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
          <!-- The metadata field rows (excerpt/categories/featured image/
               status) stay verbatim inside the single form — the posts.js
               contract (form.querySelector on hossam-post-create-form)
               forbids splitting the form, so the reference form's column
               split is not duplicated here (a marked delta). -->
          <div class="form-actions mt-12">
            <button type="submit" class="btn btn-gold"><?php echo esc_html( hossam_t( 'Save Article' ) ); ?></button>
          </div>
          </div><!-- /hossam-write-card -->
        </form>
        <?php
        // v1.1.3 W6: the side column stack — the AI card plus the three
        // display holders (Publishing / Metadata / Tags) in one column so the
        // workspace grid keeps exactly two children (the single form column
        // and the side stack); the holders are display-only — the form fields
        // above remain the only inputs, and the holders mirror their initial
        // state (the empty-state wireframes land with the editor-cards wave).
        // Role label computed internally from the shell's existing flags.
        $hossam_write_role_label = $is_admin
            ? hossam_t( 'Administrator' )
            : ( $is_editor ? hossam_t( 'Editor' ) : ( $is_author ? hossam_t( 'Author' ) : hossam_t( 'Contributor' ) ) );
        ?>
        <div class="hossam-write-side-stack">
        <?php if ( $hossam_write_ai_on ) : ?>
        <!-- Third batch (7.4): AI buttons render only when a valid strategic
             capability is proven plus the user permission — hiding is a
             display condition, and authorization is re-enforced server-side
             on every endpoint.
             (Batch 7: + the owner gate B3-08 is_feature_enabled('ai')).
             v1.1.1 C1: the independent AI card in the side column per the
             model (Panel 5) — every .js-ai-run button, data attributes and
             the hidden result verbatim as-is (ai.js reads them via
             delegation). -->
        <div class="card hossam-write-ai-card">
          <div class="hossam-write-card-head">
            <div class="card-sub hossam-write-card-title"><span class="hossam-write-card-ico" aria-hidden="true">✨</span><?php echo esc_html( hossam_t( 'AI Writing Assistant' ) ); ?></div>
            <span class="hossam-write-card-tag"><?php echo esc_html( hossam_t( 'Optional' ) ); ?></span>
          </div>
          <p class="hossam-write-card-desc"><?php echo esc_html( hossam_t( 'Optional assistant for proofreading, translation and SEO — results appear inside this panel.' ) ); ?></p>
          <div class="filter-bar hossam-write-ai-actions">
            <button type="button" class="btn btn-sm js-ai-run" data-job-type="grammar" data-source="hossam-new-content" data-result="ai-suggestion-result-write"><?php echo esc_html( hossam_t( 'Grammar Check' ) ); ?></button>
            <input type="text" class="filter-select" id="hossam-ai-target-write" maxlength="8" placeholder="<?php echo esc_attr( hossam_t( 'Target language code' ) ); ?>" />
            <button type="button" class="btn btn-sm js-ai-run" data-job-type="translation" data-source="hossam-new-content" data-lang-source="hossam-ai-target-write" data-result="ai-suggestion-result-write"><?php echo esc_html( hossam_t( 'Suggest Translation' ) ); ?></button>
            <button type="button" class="btn btn-sm js-ai-run" data-job-type="seo" data-source="hossam-new-content" data-title-source="hossam-new-title" data-result="ai-suggestion-result-write"><?php echo esc_html( hossam_t( 'Suggest SEO' ) ); ?></button>
            <button type="button" class="btn btn-sm js-ai-run" data-job-type="improvement" data-source="hossam-new-content" data-result="ai-suggestion-result-write"><?php echo esc_html( hossam_t( 'Suggest Improvements' ) ); ?></button>
          </div>
          <div id="ai-suggestion-result-write" class="hidden"></div>
        </div>
        <?php endif; ?>
        <div class="card hossam-write-aside-holder" id="hossam-publishing-holder">
          <div class="hossam-write-card-head">
            <div class="card-sub hossam-write-card-title"><span class="hossam-write-card-ico" aria-hidden="true">📌</span><?php echo esc_html( hossam_t( 'Publishing' ) ); ?></div>
            <span class="hossam-write-card-tag"><?php echo esc_html( $hossam_write_role_label ); ?></span>
          </div>
          <div class="hossam-aside-row">
            <span class="form-label"><?php echo esc_html( hossam_t( 'Status' ) ); ?></span>
            <span class="pill pill-draft"><?php echo esc_html( hossam_t( 'Draft' ) ); ?></span>
          </div>
          <div class="hossam-aside-row">
            <span class="form-label"><?php echo esc_html( hossam_t( 'Permalink' ) ); ?></span>
            <span class="hossam-aside-muted">—</span>
          </div>
          <p class="form-hint"><?php echo esc_html( hossam_t( 'The permalink is set after the first save.' ) ); ?></p>
        </div>
        <div class="card hossam-write-aside-holder" id="hossam-metadata-holder">
          <div class="hossam-write-card-head">
            <div class="card-sub hossam-write-card-title"><span class="hossam-write-card-ico" aria-hidden="true">🏷️</span><?php echo esc_html( hossam_t( 'Metadata' ) ); ?></div>
          </div>
          <div class="hossam-aside-row">
            <span class="form-label"><?php echo esc_html( hossam_t( 'Featured Image' ) ); ?></span>
            <span class="hossam-aside-muted"><?php echo esc_html( hossam_t( 'No cover selected yet.' ) ); ?></span>
          </div>
          <div class="hossam-aside-row">
            <span class="form-label"><?php echo esc_html( hossam_t( 'Excerpt' ) ); ?></span>
            <span class="hossam-aside-muted"><?php echo esc_html( hossam_t( 'Write a short excerpt in the form — it feeds archives and search results.' ) ); ?></span>
          </div>
        </div>
        <div class="card hossam-write-aside-holder" id="hossam-tags-holder">
          <div class="hossam-write-card-head">
            <div class="card-sub hossam-write-card-title"><span class="hossam-write-card-ico" aria-hidden="true">#️⃣</span><?php echo esc_html( hossam_t( 'Tags' ) ); ?></div>
          </div>
          <div class="hossam-aside-chips" aria-hidden="true"></div>
          <p class="form-hint hossam-aside-empty"><?php echo esc_html( hossam_t( 'No tags yet.' ) ); ?></p>
          <p class="form-hint"><?php echo esc_html( hossam_t( 'Tags power related-article linking — keep them short and specific.' ) ); ?></p>
        </div>
        </div><!-- /hossam-write-side-stack -->
      </div><!-- /hossam-write-workspace -->
      <!-- Scoped fallback: [frontend_admin] hidden by default; opened only on
           a technical failure (network/5xx/unparseable response) from
           posts.js — never on a security rejection or invalid input (gate
           8.2 verbatim). -->
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
                      <?php if ( current_user_can( 'manage_options' ) && 'official' === $hossam_editor_mode ) : ?>
                      <!-- v1.1.0 OEB (H1 §7-1 — merge R23): the official editor
                           open button — administrators only × official from
                           get_effective_editor_mode(); absent from the DOM
                           entirely for non-admins. Text: i18n oeb_open (R41). -->
                      <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $post_id . '&action=edit' ) ); ?>" target="_blank" rel="noopener" class="hossam-oeb-btn js-oeb-open" data-oeb-post="<?php echo esc_attr( $post_id ); ?>" title="<?php echo esc_attr( __( 'Admin gateway — opens the official editor in a separate tab, administrators only.', 'hal-frontend-dashboard' ) ); ?>"><?php echo esc_html( hossam_t( 'Manual update' ) ); ?></a>
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
      <?php if ( current_user_can( 'manage_options' ) && 'official' === $hossam_editor_mode ) : ?>
      <!-- v1.1.0 OEB (H1 §6-8/constraint 7 — merge R23): one native <dialog>
           with no library, inside the existing articles panel. Status text:
           i18n oeb_dialog_status; the manual fallback link with the same
           editorUrl (JS fills it from data-oeb-post of the clicked button on
           open); a close button. Its condition is the button's condition:
           official × administrators only. -->
      <dialog id="hossam-oeb-dialog" class="hossam-oeb-dialog">
        <!-- v1.1.1 E1: the "Admin gateway" distinction line — new text in the
             __() pattern with the hal-frontend-dashboard domain (display
             only; no logic, no new path). -->
        <div class="hossam-oeb-gate-note">
          <span class="hossam-oeb-gate-chip"><?php echo esc_html( __( 'Admin gateway', 'hal-frontend-dashboard' ) ); ?></span>
          <span><?php echo esc_html( __( 'Opens the official editor in a separate tab — administrators only.', 'hal-frontend-dashboard' ) ); ?></span>
        </div>
        <div class="hossam-oeb-dialog-head"><?php echo esc_html( hossam_t( 'Manual update' ) ); ?></div>
        <p class="hossam-oeb-status" id="hossam-oeb-dialog-status"><?php echo esc_html( hossam_t( 'Checking for updates…' ) ); ?></p>
        <div class="hossam-oeb-actions">
          <a href="#" id="hossam-oeb-dialog-link" class="btn btn-sm" target="_blank" rel="noopener"><?php echo esc_html( hossam_t( 'Manual update' ) ); ?></a>
          <button type="button" class="btn btn-sm js-oeb-close"><?php echo esc_html( hossam_t( 'Cancel' ) ); ?></button>
        </div>
      </dialog>
      <?php endif; ?>
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
                  // Item 3 (second batch): per-post capability check via
                  // map_meta_cap instead of a manual post_author comparison
                  // (line-142 reference). The endpoint re-verifies
                  // server-side; hiding the form here is not authorization.
                  echo '<div class="info-card" style="color:var(--danger)">' . esc_html( hossam_t( 'You do not have permission to edit this article.' ) ) . '</div>';
              } else {
                  $hossam_edit_resource_allowed = true;
                  $hossam_edit_cats = wp_get_post_categories( $the_post->ID );
                  $hossam_can_publish = current_user_can( 'publish_posts' );
                  $hossam_cur_status  = get_post_status( $the_post );
                  ?>
                  <!-- Core-first: full server-side population then submit to
                       hossam_update_article -->
                   <form id="hossam-post-edit-form" onsubmit="event.preventDefault(); hossamUpdateArticle(this); return false;">
                    <input type="hidden" name="post_id" value="<?php echo esc_attr( $the_post->ID ); ?>" />
                    <div class="form-group mb-8">
                      <label class="form-label" for="hossam-edit-title"><?php echo esc_html( hossam_t( 'Title' ) ); ?></label>
                      <input type="text" class="form-input" id="hossam-edit-title" name="title" maxlength="255" value="<?php echo esc_attr( $the_post->post_title ); ?>" />
                    </div>
                    <div class="form-group mb-8">
                      <label class="form-label" for="hossam-edit-content"><?php echo esc_html( hossam_t( 'Content' ) ); ?></label>
                      <textarea class="form-input" id="hossam-edit-content" name="content" rows="12"><?php echo esc_textarea( $the_post->post_content ); ?></textarea>
                      <?php if ( $hossam_rich_editor_feature && 'off' !== $hossam_editor_mode ) : ?>
                      <!-- v1.0.6 §9: the rich editor position in the edit panel
                           inside the content field of the HAL template — the
                           same contract as the write panel: the textarea above
                           remains the visible form field and is not hidden
                           until initialization succeeds (posts.js);
                           initialization on first panel open. This branch is
                           inside $hossam_edit_resource_allowed (edit_post for
                           this post pinned above) — the server check remains. -->
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
                    // v1.0.6 P1: read-only Permalink display (get_permalink) —
                    // the draft link tagged "Temporary — final on publish". No
                    // slug edit field.
                    $hossam_edit_permalink = function_exists( 'get_permalink' ) ? (string) get_permalink( $the_post->ID ) : '';
                    if ( '' !== $hossam_edit_permalink ) :
                    ?>
                    <div class="form-group mb-8">
                      <span class="form-label"><?php echo esc_html( hossam_t( 'Permalink' ) ); ?></span>
                      <div>
                        <a href="<?php echo esc_url( $hossam_edit_permalink ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $hossam_edit_permalink ); ?></a>
                        <?php if ( in_array( $hossam_cur_status, [ 'draft', 'pending', 'future' ], true ) ) : ?>
                        <span class="data-tag"><?php echo esc_html( hossam_t( 'Temporary — final on publish' ) ); ?></span>
                        <?php endif; ?>
                      </div>
                    </div>
                    <?php endif; ?>
                    <?php if ( 'publish' === $hossam_cur_status && ! $hossam_can_publish ) : ?>
                    <?php /* A published post whose user lacks publish_posts: no status field at all — the published post stays published */ ?>
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
                  // v1.1.3 W6 (D2): the Languages & Translation card per the
                  // adopted translation design — always visible in the edit
                  // panel (the write panel reveals it after the first save
                  // with the saved-state wave). One row per active language
                  // from the official WPML reads; one action per row at most
                  // (the design's single-action rule): View for a published
                  // translation, open-in-editor for a linked draft (edit_post
                  // on the target governs), and the existing translate action
                  // whose button attributes match the table's existing
                  // delegation (js-translate-article) — no new endpoint, no
                  // JS touch. Direction tags are not derivable from the
                  // active-languages contract, so each row carries its
                  // language code chip instead (a marked delta from the
                  // design sketch, verified against the adapter's reads).
                  $hossam_lang_card_ready = HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'wpml_translations' )
                      && 'unavailable' !== $posts_wpml['status']
                      && ! empty( $posts_wpml['capabilities']['languages'] )
                      && function_exists( 'hossam_wpml_get_active_languages' )
                      && function_exists( 'hossam_wpml_get_post_language' )
                      && function_exists( 'hossam_wpml_get_translation_id' );
                  $hossam_lang_rows = [];
                  if ( $hossam_lang_card_ready ) {
                      $hossam_post_lang = hossam_wpml_get_post_language( (int) $the_post->ID );
                      foreach ( (array) hossam_wpml_get_active_languages() as $hossam_lang_code => $hossam_lang_info ) {
                          $hossam_lang_code = (string) $hossam_lang_code;
                          $hossam_is_original = ( '' !== $hossam_post_lang && $hossam_lang_code === $hossam_post_lang );
                          $hossam_tid = hossam_wpml_get_translation_id( (int) $the_post->ID, $hossam_lang_code );
                          $hossam_row_published = false;
                          if ( $hossam_is_original ) {
                              $hossam_row_status = hossam_t( 'Original' );
                              $hossam_row_cls = 'hossam-lang-original';
                          } elseif ( $hossam_tid > 0 ) {
                              $hossam_tstatus = function_exists( 'get_post_status' ) ? (string) get_post_status( $hossam_tid ) : '';
                              $hossam_row_published = ( 'publish' === $hossam_tstatus );
                              $hossam_row_status = $hossam_row_published ? hossam_t( 'Published' ) : hossam_t( 'Draft' );
                              $hossam_row_cls = $hossam_row_published ? 'pill-pub' : 'pill-draft';
                          } else {
                              $hossam_row_status = hossam_t( 'Not translated' );
                              $hossam_row_cls = 'hossam-lang-none';
                          }
                          $hossam_row_action = '';
                          if ( $hossam_is_original ) {
                              $hossam_row_action = ''; // the open article IS this language row
                          } elseif ( $hossam_row_published && function_exists( 'get_permalink' ) ) {
                              $hossam_row_action = '<a class="btn btn-sm btn-ghost" target="_blank" rel="noopener" href="' . esc_url( get_permalink( $hossam_tid ) ) . '">' . esc_html( hossam_t( 'View' ) ) . '</a>';
                          } elseif ( $hossam_tid > 0 && current_user_can( 'edit_post', $hossam_tid ) ) {
                              $hossam_row_action = '<a class="btn btn-sm" href="' . esc_url( add_query_arg( [ 'panel' => 'edit-article', 'post_id' => $hossam_tid ], $_dash_base ) ) . '">' . esc_html( hossam_t( 'Open translation' ) ) . '</a>';
                          } elseif ( $hossam_tid < 1 ) {
                              $hossam_row_action = '<a href="#" class="btn btn-sm btn-gold js-translate-article" data-post="' . esc_attr( $the_post->ID ) . '" data-lang="' . esc_attr( $hossam_lang_code ) . '">' . esc_html( sprintf( hossam_t( 'Translate to %s' ), strtoupper( $hossam_lang_code ) ) ) . '</a>';
                          }
                          $hossam_lang_rows[] = [
                              'code'     => $hossam_lang_code,
                              'name'     => ( isset( $hossam_lang_info['translated_name'] ) && is_string( $hossam_lang_info['translated_name'] ) && '' !== $hossam_lang_info['translated_name'] )
                                  ? $hossam_lang_info['translated_name']
                                  : strtoupper( $hossam_lang_code ),
                              'status'   => $hossam_row_status,
                              'cls'      => $hossam_row_cls,
                              'action'   => $hossam_row_action,
                              'original' => $hossam_is_original,
                          ];
                      }
                  }
                  ?>
                  <?php if ( $hossam_lang_card_ready && ! empty( $hossam_lang_rows ) ) : ?>
                  <div class="card mt-12" id="hossam-languages-card">
                    <div class="card-sub mb-8"><?php echo esc_html( hossam_t( 'Languages & Translation' ) ); ?></div>
                    <p class="form-hint mb-8"><?php echo esc_html( hossam_t( 'Translation creates a linked draft in the target language — edit it and publish it like any article.' ) ); ?></p>
                    <div class="hossam-lang-rows">
                      <?php foreach ( $hossam_lang_rows as $hossam_lang_row ) : ?>
                      <div class="hossam-lang-row<?php echo $hossam_lang_row['original'] ? ' is-original' : ''; ?>">
                        <span class="hossam-lang-code"><?php echo esc_html( strtoupper( $hossam_lang_row['code'] ) ); ?></span>
                        <span class="hossam-lang-name"><?php echo esc_html( $hossam_lang_row['name'] ); ?></span>
                        <span class="pill <?php echo esc_attr( $hossam_lang_row['cls'] ); ?>"><?php echo esc_html( $hossam_lang_row['status'] ); ?></span>
                        <span class="hossam-lang-action"><?php echo $hossam_lang_row['action']; // built above from escaped parts only ?></span>
                      </div>
                      <?php endforeach; ?>
                    </div>
                    <?php if ( current_user_can( 'manage_options' ) ) : ?>
                    <p class="form-hint mt-8"><a href="<?php echo esc_url( admin_url( 'admin.php?page=sitepress-multilingual-cms' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( hossam_t( 'Language Manager' ) ); ?></a></p>
                    <?php endif; ?>
                  </div>
                  <?php endif; ?>
                  <?php
                  // v1.0.6 §8.5: the SEO group in the edit panel only — behind
                  // both gates: the owner key rank_math_seo + can_write from
                  // the registry (the same seo.php contract: available gate +
                  // plugin_contract + verified_fields).
                  // The write panel has no SEO group (no article = phantom
                  // fields).
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
                    <?php
                    // v1.1.3 W6 (D3): the simplified SEO group per the adopted
                    // design — a colored read-only meter whose thresholds are
                    // identical to the articles-table SEO column (>=80 good /
                    // >=51 average / <=50 weak / none), one explanation line
                    // per field, guidance-only character counters (the live
                    // update is later JS; the initial value is server-computed),
                    // and robots labels carrying a plain description with the
                    // raw value beside it (the raw values remain the save
                    // contract). Rank Math stays the only score source — no
                    // client-side calculation anywhere.
                    $hossam_seo_meter_cls = $hossam_edit_seo_score >= 80 ? 'good' : ( $hossam_edit_seo_score >= 51 ? 'avg' : ( $hossam_edit_seo_score > 0 ? 'weak' : 'none' ) );
                    $hossam_seo_meter_label = $hossam_edit_seo_score >= 80 ? hossam_t( 'Good' ) : ( $hossam_edit_seo_score >= 51 ? hossam_t( 'Average' ) : ( $hossam_edit_seo_score > 0 ? hossam_t( 'Weak' ) : hossam_t( 'Not calculated yet.' ) ) );
                    // The level classes travel whole from PHP (the rm_cls
                    // pattern) — no static class prefix inside the attribute.
                    $hossam_seo_fill_cls = 'hossam-seo-meter-' . $hossam_seo_meter_cls;
                    $hossam_seo_label_cls = 'hossam-seo-meter-label-' . $hossam_seo_meter_cls;
                    $hossam_seo_robots_labels = [
                        'noindex'      => hossam_t( 'Do not show this page in search results' ),
                        'nofollow'     => hossam_t( 'Do not follow links on this page' ),
                        'noarchive'    => hossam_t( 'Do not store a cached copy of this page' ),
                        'noimageindex' => hossam_t( 'Do not show images from this page in image search' ),
                    ];
                    $hossam_seo_title_len = function_exists( 'mb_strlen' ) ? mb_strlen( $hossam_edit_seo_title ) : strlen( $hossam_edit_seo_title );
                    $hossam_seo_desc_len = function_exists( 'mb_strlen' ) ? mb_strlen( $hossam_edit_seo_desc ) : strlen( $hossam_edit_seo_desc );
                    ?>
                    <div class="hossam-seo-meter" id="hossam-edit-seo-score">
                      <span class="hossam-seo-meter-track"><span class="hossam-seo-meter-fill <?php echo esc_attr( $hossam_seo_fill_cls ); ?>" style="width:<?php echo esc_attr( (string) min( 100, max( 0, $hossam_edit_seo_score ) ) ); ?>%"></span></span>
                      <span class="hossam-seo-meter-value"><?php echo esc_html( $hossam_edit_seo_score > 0 ? $hossam_edit_seo_score . '%' : '—' ); ?></span>
                      <span class="hossam-seo-meter-label <?php echo esc_attr( $hossam_seo_label_cls ); ?>"><?php echo esc_html( $hossam_seo_meter_label ); ?></span>
                    </div>
                    <p class="form-hint mb-8"><?php echo esc_html( hossam_t( 'Raise the score by completing the fields below — Rank Math calculates it automatically.' ) ); ?></p>
                    <form id="hossam-edit-seo-form" onsubmit="event.preventDefault(); hossamSaveSeo(this); return false;">
                      <input type="hidden" name="post_id" value="<?php echo esc_attr( $the_post->ID ); ?>" />
                      <div class="form-group mb-8">
                        <label class="form-label" for="hossam-edit-seo-kw"><?php echo esc_html( hossam_t( 'Focus Keyword' ) ); ?></label>
                        <input type="text" class="form-input" id="hossam-edit-seo-kw" name="focus_keyword" maxlength="255" value="<?php echo esc_attr( $hossam_edit_seo_kw ); ?>" />
                        <p class="form-hint"><?php echo esc_html( hossam_t( 'The keyword your client searches for — e.g. corporate lawyer in Cairo.' ) ); ?></p>
                      </div>
                      <div class="form-group mb-8">
                        <div class="hossam-seo-field-head">
                          <label class="form-label" for="hossam-edit-seo-title"><?php echo esc_html( hossam_t( 'SEO Title' ) ); ?></label>
                          <span class="hossam-seo-counter"><?php echo esc_html( $hossam_seo_title_len . ' / 60' ); ?></span>
                        </div>
                        <input type="text" class="form-input" id="hossam-edit-seo-title" name="title" maxlength="255" value="<?php echo esc_attr( $hossam_edit_seo_title ); ?>" />
                        <p class="form-hint"><?php echo esc_html( hossam_t( 'The title shown in search results — it may differ from the article title.' ) ); ?></p>
                      </div>
                      <div class="form-group mb-8">
                        <div class="hossam-seo-field-head">
                          <label class="form-label" for="hossam-edit-seo-desc"><?php echo esc_html( hossam_t( 'Meta Description' ) ); ?></label>
                          <span class="hossam-seo-counter"><?php echo esc_html( $hossam_seo_desc_len . ' / 160' ); ?></span>
                        </div>
                        <textarea class="form-input" id="hossam-edit-seo-desc" name="description" rows="3"><?php echo esc_textarea( $hossam_edit_seo_desc ); ?></textarea>
                        <p class="form-hint"><?php echo esc_html( hossam_t( 'The summary line shown under the title in search results.' ) ); ?></p>
                      </div>
                      <div class="form-group mb-8">
                        <span class="form-label"><?php echo esc_html( hossam_t( 'Robots' ) ); ?></span>
                        <div class="hossam-seo-robots">
                          <?php foreach ( $hossam_edit_seo_options as $hossam_edit_seo_option ) : ?>
                          <label class="hossam-seo-robots-row">
                            <input type="checkbox" name="robots[]" value="<?php echo esc_attr( $hossam_edit_seo_option ); ?>"<?php echo ( is_array( $hossam_edit_seo_robots ) ? in_array( $hossam_edit_seo_option, $hossam_edit_seo_robots, true ) : str_contains( (string) $hossam_edit_seo_robots, $hossam_edit_seo_option ) ) ? ' checked' : ''; ?> />
                            <span><?php echo esc_html( $hossam_seo_robots_labels[ $hossam_edit_seo_option ] ?? $hossam_edit_seo_option ); ?></span>
                            <span class="hossam-robots-raw"><?php echo esc_html( $hossam_edit_seo_option ); ?></span>
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
                  <!-- Third batch (7.4): inside the permission branch only —
                       edit_post at post level pinned above, and the capability
                       proven server-side now.
                       (Batch 7: + the owner gate B3-08
                       is_feature_enabled('ai')). -->
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
      <!-- Scoped fallback only (a technical failure from posts.js) — never
           opens on a security rejection -->
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
                  // Item 3 (second batch): edit_post at post level;
                  // the endpoint (hossam_trash_article) re-verifies server-side.
                  echo '<div class="info-card" style="color:var(--danger)">' . esc_html( hossam_t( 'You do not have permission to delete this article.' ) ) . '</div>';
              } else {
                  $hossam_delete_resource_allowed = true;
                  ?>
                  <!-- Core-first: move to trash via hossam_trash_article after
                       explicit confirmation -->
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
      <!-- Scoped fallback only (a technical failure from posts.js) — never
           opens on a security rejection -->
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
