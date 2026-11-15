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

// v1.1.5 item 16 (S-9): the site-tag suggestion read — the tags used in
// PUBLISHED articles of the given categories (the article's own sections
// govern the scope; with no categories the scope is all published posts),
// most frequent first. One markup builder renders the Tags card in BOTH
// contexts (write + edit) — no parallel build. Every read is core-only and
// function-guarded so the isolated harnesses stay green; the read scope
// travels to the render (data-hossam-tags-scope) so the documented read
// list is visible in the markup itself.
$hossam_tags_cap = 15;
$hossam_tag_suggestions = function ( array $hossam_scope_cat_ids ) use ( $hossam_tags_cap ): array {
    $hossam_scope_cat_ids = array_values( array_unique( array_filter( array_map( 'absint', $hossam_scope_cat_ids ) ) ) );
    if ( ! function_exists( 'get_posts' ) || ! function_exists( 'wp_get_object_terms' ) ) {
        return array();
    }
    $hossam_q_args = array(
        'post_type'              => 'post',
        'post_status'            => 'publish',
        'posts_per_page'         => 100,
        'fields'                 => 'ids',
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    );
    if ( ! empty( $hossam_scope_cat_ids ) ) {
        $hossam_q_args['category__in'] = $hossam_scope_cat_ids;
    }
    $hossam_scope_ids = get_posts( $hossam_q_args );
    if ( empty( $hossam_scope_ids ) || ! is_array( $hossam_scope_ids ) ) {
        return array();
    }
    // One tally pass: per scoped post, one core read returns the post's tags
    // AND categories together (the linkage the suggestion mechanism needs —
    // tag frequency + the category ids each tag was read from).
    $hossam_tally = array();
    foreach ( $hossam_scope_ids as $hossam_scope_pid ) {
        $hossam_terms = wp_get_object_terms( (int) $hossam_scope_pid, array( 'post_tag', 'category' ), array( 'update_term_meta_cache' => false ) );
        if ( is_wp_error( $hossam_terms ) || empty( $hossam_terms ) ) {
            continue;
        }
        $hossam_post_cats = array();
        $hossam_post_tags = array();
        foreach ( (array) $hossam_terms as $hossam_term ) {
            if ( ! isset( $hossam_term->taxonomy, $hossam_term->name ) ) {
                continue;
            }
            if ( 'category' === $hossam_term->taxonomy ) {
                $hossam_post_cats[ (int) $hossam_term->term_id ] = true;
            } elseif ( 'post_tag' === $hossam_term->taxonomy ) {
                $hossam_post_tags[ (string) $hossam_term->name ] = true;
            }
        }
        foreach ( array_keys( $hossam_post_tags ) as $hossam_tag_name ) {
            if ( ! isset( $hossam_tally[ $hossam_tag_name ] ) ) {
                $hossam_tally[ $hossam_tag_name ] = array( 'name' => $hossam_tag_name, 'count' => 0, 'cats' => array() );
            }
            $hossam_tally[ $hossam_tag_name ]['count']++;
            foreach ( array_keys( $hossam_post_cats ) as $hossam_cat_id ) {
                $hossam_tally[ $hossam_tag_name ]['cats'][ $hossam_cat_id ] = true;
            }
        }
    }
    uasort( $hossam_tally, function ( $a, $b ) {
        if ( $a['count'] === $b['count'] ) {
            return strcmp( $a['name'], $b['name'] );
        }
        return $b['count'] - $a['count'];
    } );
    $hossam_out = array();
    foreach ( $hossam_tally as $hossam_row ) {
        $hossam_out[] = array(
            'name'  => (string) $hossam_row['name'],
            'count' => (int) $hossam_row['count'],
            'cats'  => array_map( 'strval', array_keys( $hossam_row['cats'] ) ),
        );
        if ( count( $hossam_out ) >= $hossam_tags_cap ) {
            break;
        }
    }
    return $hossam_out;
};
// The one Tags-card builder for both contexts (the live card markup —
// article chips with remove buttons, category-scoped suggestion chips, the
// add row, and the tags[] hidden mirror inside the form). Zero inline
// styles; every control is type="button" (S-11) so nothing inside the save
// form submits implicitly; the empty marker keeps "removed the last tag"
// persisted on save.
$hossam_render_tags_card = function ( array $hossam_current_tags, array $hossam_suggestions, string $hossam_scope_read ) use ( $hossam_tags_cap ): void {
    echo '<div class="hossam-aside-chips" data-hossam-tags-chips data-hossam-tags-scope="' . esc_attr( $hossam_scope_read ) . '">';
    foreach ( $hossam_current_tags as $hossam_tag_name ) {
        $hossam_tag_name = (string) $hossam_tag_name;
        if ( '' === $hossam_tag_name ) {
            continue;
        }
        echo '<span class="hossam-tag-chip" data-tag="' . esc_attr( $hossam_tag_name ) . '">' . esc_html( $hossam_tag_name )
            . '<button type="button" class="hossam-tag-chip-remove" aria-label="' . esc_attr( sprintf( hossam_t( 'Remove tag: %s' ), $hossam_tag_name ) ) . '">×</button></span>';
    }
    echo '</div>';
    echo '<p class="form-hint hossam-aside-empty" data-hossam-tags-empty' . ( empty( $hossam_current_tags ) ? '' : ' hidden' ) . '>' . esc_html( hossam_t( 'No tags yet.' ) ) . '</p>';
    echo '<p class="form-hint">' . esc_html( hossam_t( 'Tags power related-article linking — keep them short and specific.' ) ) . '</p>';
    echo '<p class="form-hint">' . esc_html( hossam_t( 'Suggested from published articles in the selected categories.' ) ) . '</p>';
    echo '<div class="hossam-aside-chips hossam-tags-suggestions" data-hossam-tags-suggestions>';
    foreach ( $hossam_suggestions as $hossam_suggestion ) {
        echo '<button type="button" class="hossam-tag-suggest" data-tag-suggest="' . esc_attr( $hossam_suggestion['name'] ) . '" data-tag-cats="' . esc_attr( implode( ',', $hossam_suggestion['cats'] ) ) . '" title="' . esc_attr( sprintf( hossam_t( 'Used in %d published articles in this scope.' ), (int) $hossam_suggestion['count'] ) ) . '">+ ' . esc_html( $hossam_suggestion['name'] ) . '</button>';
    }
    echo '</div>';
    echo '<div class="hossam-tags-add-row">';
    echo '<input type="text" class="filter-select" maxlength="100" placeholder="' . esc_attr( hossam_t( 'Add a tag…' ) ) . '" aria-label="' . esc_attr( hossam_t( 'Add a tag…' ) ) . '" />';
    echo '<button type="button" class="btn btn-sm" data-hossam-tags-add title="' . esc_attr( hossam_t( 'Up to 15 tags per article.' ) ) . '">' . esc_html( hossam_t( 'Add' ) ) . '</button>';
    echo '</div>';
    echo '<div class="hossam-tags-values" data-hossam-tags-values aria-hidden="true">';
    $hossam_mirrored = 0;
    foreach ( $hossam_current_tags as $hossam_tag_name ) {
        $hossam_tag_name = (string) $hossam_tag_name;
        if ( '' === $hossam_tag_name ) {
            continue;
        }
        echo '<input type="hidden" name="tags[]" value="' . esc_attr( $hossam_tag_name ) . '" />';
        $hossam_mirrored++;
    }
    if ( 0 === $hossam_mirrored ) {
        // The explicit empty-set marker: the key stays present on save with
        // the cleared state (the removal acceptance) instead of falling back
        // to the keep-contract.
        echo '<input type="hidden" name="tags[]" value="" />';
    }
    echo '</div>';
};
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
      <!-- v1.1.3 W6: the side column is permanent — v1.1.5 items 15-1/21-1
           complete the reference form's column split: the single form wraps
           the two-column workspace grid (the writing column + the side
           stack), so every field — including the side-stack card fields —
           stays a DOM child of hossam-post-create-form and the posts.js
           contract (form.querySelector on hossam-post-create-form) works
           verbatim (the form is never split). -->
        <form id="hossam-post-create-form" onsubmit="event.preventDefault(); hossamCreateArticle(this); return false;" class="hossam-write-primary">
        <!-- Core-first (second batch): the primary path hossam_create_article —
             the author is assigned from the server session, and no AI here
             (item 3 constraint). -->
        <div class="hossam-write-workspace hossam-write-has-aside">
        <div class="hossam-write-writing-col">
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
          <!-- (2) v1.1.5 item 19: the Excerpt group moved directly under the
               title group (the reads are name-based and position-independent
               — hossam-new-excerpt untouched). -->
          <div class="form-group mb-8">
            <label class="form-label" for="hossam-new-excerpt"><?php echo esc_html( hossam_t( 'Excerpt' ) ); ?></label>
            <textarea class="form-input" id="hossam-new-excerpt" name="excerpt" rows="3"></textarea>
          </div>
          <!-- (3) Content panel — the model: an attached toolbar above the
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
          <!-- v1.1.5 item 15: the categories tree and the featured-image
               package moved DOM inside the Metadata card (side-stack position
               1) — same ids/names/contract, still children of the single
               form; the Status element moved into the Publishing card
               (item 14-2). The posts.js contract (form.querySelector on
               hossam-post-create-form) keeps working — the form wraps the
               whole workspace grid and is never split. -->
          <div class="form-actions mt-12">
            <button type="submit" class="btn btn-gold"><?php echo esc_html( hossam_t( 'Save Article' ) ); ?></button>
          </div>
          </div><!-- /hossam-write-card -->
        </div><!-- /hossam-write-writing-col -->
        <?php
        // v1.1.3 W6 + v1.1.5 item 14: the side column stack — the six cards
        // in the owner's royal order (Metadata → Tags → Translations → SEO →
        // AI → Publishing). The fields inside the cards are real form fields
        // (children of the single wrapping form). Role label computed
        // internally from the shell's existing flags.
        $hossam_write_role_label = $is_admin
            ? hossam_t( 'Administrator' )
            : ( $is_editor ? hossam_t( 'Editor' ) : ( $is_author ? hossam_t( 'Author' ) : hossam_t( 'Contributor' ) ) );
        ?>
        <div class="hossam-write-side-stack">
        <!-- (1) Metadata — v1.1.5 item 15: the live card carries the real
             categories tree and the real featured-image package. The two
             text mirror rows left, each resolved per the wave-0
             documentation (S-12): the Featured Image mirror row → the moved
             featured-image package inside this card (item 15-2); the
             Excerpt mirror row → the real Excerpt field in the writing
             column directly under the title (item 19-1), not inside this
             card. Zero text unchanged elsewhere. -->
        <div class="card hossam-write-aside-holder" id="hossam-metadata-holder" data-hossam-side-card="metadata">
          <div class="hossam-write-card-head">
            <div class="card-sub hossam-write-card-title"><span class="hossam-write-card-ico" aria-hidden="true">🏷️</span><?php echo esc_html( hossam_t( 'Metadata' ) ); ?></div>
            <span class="hossam-write-card-tag"><?php echo esc_html( hossam_t( 'Optional' ) ); ?></span>
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
        </div>
        <!-- (2) Tags — v1.1.5 item 16 (S-9/S-10/S-11): the live card. Write
             context: a new article starts with zero tags; the suggestions
             cover the published posts of all sections (no categories are
             checked yet) and posts.js narrows them live to the checked
             categories via the rendered data-tag-cats read lists. One
             builder renders both contexts. -->
        <div class="card hossam-write-aside-holder" id="hossam-tags-holder" data-hossam-side-card="tags">
          <div class="hossam-write-card-head">
            <div class="card-sub hossam-write-card-title"><span class="hossam-write-card-ico" aria-hidden="true">#️⃣</span><?php echo esc_html( hossam_t( 'Tags' ) ); ?></div>
            <span class="hossam-write-card-tag"><?php echo esc_html( hossam_t( 'Optional' ) ); ?></span>
          </div>
          <?php
            // S-9: the read is proven by measurement and documented in the
            // render — the scope marker travels on the chips container.
            $hossam_write_tag_scope = ( function_exists( 'get_posts' ) && function_exists( 'wp_get_object_terms' ) )
                ? 'post_tag@publish;category:all'
                : '';
            $hossam_render_tags_card( array(), $hossam_tag_suggestions( array() ), $hossam_write_tag_scope );
          ?>
        </div>
        <!-- (3) Translations — v1.1.5 item 17: one markup for both contexts.
             The write card keeps its declared availability state before the
             first save (the T-05 contract): zero rows and zero buttons while
             pending (no post id — zero calls with id 0), and the existing
             saved-state flip plus the existing post-save redirect hand over
             to the edit panel where the language rows and their actions are
             real (the same builder family and the same translate
             delegation). Detection scope per the owner's narrowing:
             read-only, zero WPML writes. -->
        <div class="card hossam-write-aside-holder" id="hossam-translations-holder" data-hossam-side-card="translations" data-hossam-card-state="pending-save">
          <div class="hossam-write-card-head">
            <div class="card-sub hossam-write-card-title"><span class="hossam-write-card-ico" aria-hidden="true">🌐</span><?php echo esc_html( hossam_t( 'Languages & Translation' ) ); ?></div>
            <span class="hossam-write-card-tag"><?php echo esc_html( hossam_t( 'After first save' ) ); ?></span>
          </div>
          <p class="form-hint"><?php echo esc_html( hossam_t( 'Language rows appear here after the first save — detection only.' ) ); ?></p>
        </div>
        <!-- (4) SEO & SERP — v1.1.4 item 5 availability state preserved
             (position 4 in the royal order). Rank Math stays the only score
             source; the fields and score activate with the saved-state wave
             after the first save. -->
        <div class="card hossam-write-aside-holder" id="hossam-seo-holder" data-hossam-side-card="seo" data-hossam-card-state="pending-save">
          <div class="hossam-write-card-head">
            <div class="card-sub hossam-write-card-title"><span class="hossam-write-card-ico" aria-hidden="true">🔍</span><?php echo esc_html( hossam_t( 'SEO & SERP' ) ); ?></div>
            <span class="hossam-write-card-tag"><?php echo esc_html( hossam_t( 'After first save' ) ); ?></span>
          </div>
          <div class="hossam-aside-row">
            <span class="form-label"><?php echo esc_html( hossam_t( 'SEO score' ) ); ?></span>
            <span class="hossam-aside-muted"><?php echo esc_html( hossam_t( 'Not calculated yet.' ) ); ?></span>
          </div>
          <p class="form-hint"><?php echo esc_html( hossam_t( 'SEO fields and the score appear after the first save.' ) ); ?></p>
        </div>
        <?php if ( $hossam_write_ai_on ) : ?>
        <!-- (5) AI Writing Assistant — third batch (7.4): AI buttons render
             only when a valid strategic capability is proven plus the user
             permission — hiding is a display condition, and authorization is
             re-enforced server-side on every endpoint.
             (Batch 7: + the owner gate B3-08 is_feature_enabled('ai')).
             v1.1.1 C1: the independent AI card in the side column per the
             model (Panel 5) — every .js-ai-run button, data attributes and
             the hidden result verbatim as-is (ai.js reads them via
             delegation).
             v1.1.5 item 18-4 (D-OWNER-4=A): the head icon is a real
             interactive button (type="button", in-flow — zero fixed/sticky,
             the m19 decision stands): activating it focuses the card's
             actions row (the first js-ai-run button) — a focus/accessibility
             link only, zero new action and zero endpoint. -->
        <div class="card hossam-write-ai-card" data-hossam-side-card="ai">
          <div class="hossam-write-card-head">
            <div class="card-sub hossam-write-card-title"><button type="button" class="hossam-write-card-ico hossam-write-card-ico-btn" aria-label="<?php echo esc_attr( hossam_t( 'Jump to the AI actions' ) ); ?>" title="<?php echo esc_attr( hossam_t( 'Jump to the AI actions' ) ); ?>">✨</button><?php echo esc_html( hossam_t( 'AI Writing Assistant' ) ); ?></div>
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
        <!-- (6) Publishing — v1.1.5 item 14-2: the real Status element moved
             DOM into the card (the id/name/options contract verbatim — the
             posts.js reads are name/id-based and position-independent; the
             bottom action bar reads getElementById('hossam-new-status')). -->
        <div class="card hossam-write-aside-holder" id="hossam-publishing-holder" data-hossam-side-card="publishing">
          <div class="hossam-write-card-head">
            <div class="card-sub hossam-write-card-title"><span class="hossam-write-card-ico" aria-hidden="true">📌</span><?php echo esc_html( hossam_t( 'Publishing' ) ); ?></div>
            <span class="hossam-write-card-tag"><?php echo esc_html( $hossam_write_role_label ); ?></span>
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
          <div class="hossam-aside-row">
            <span class="form-label"><?php echo esc_html( hossam_t( 'Permalink' ) ); ?></span>
            <span class="hossam-aside-muted">—</span>
          </div>
          <p class="form-hint"><?php echo esc_html( hossam_t( 'The permalink is set after the first save.' ) ); ?></p>
        </div>
        </div><!-- /hossam-write-side-stack -->
        </div><!-- /hossam-write-workspace -->
        </form>
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

                  // Language via WPML — [W8] v1.1.3 (T-09/AC-R5): the compact
                  // chips column (status per language; one click opens the
                  // editor where the card's single action lives). Derived inside
                  // the existing official WPML reads — no N+1 (the design's
                  // performance clause).
                  $hossam_row_langs_ready = HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'wpml_translations' )
                      && 'unavailable' !== $posts_wpml['status']
                      && ! empty( $posts_wpml['capabilities']['languages'] )
                      && function_exists( 'hossam_wpml_get_active_languages' )
                      && function_exists( 'hossam_wpml_get_post_language' )
                      && function_exists( 'hossam_wpml_get_translation_id' );
                  $hossam_row_lang_chips = [];
                  if ( $hossam_row_langs_ready ) {
                      $hossam_row_src = hossam_wpml_get_post_language( $post_id );
                      foreach ( (array) hossam_wpml_get_active_languages() as $hossam_row_lcode => $hossam_row_linfo ) {
                          $hossam_row_lcode = (string) $hossam_row_lcode;
                          $hossam_row_is_src = ( '' !== $hossam_row_src && $hossam_row_lcode === $hossam_row_src );
                          $hossam_row_tid = hossam_wpml_get_translation_id( $post_id, $hossam_row_lcode );
                          if ( $hossam_row_is_src ) {
                              $hossam_row_lcls = 'hossam-chip-src';
                              $hossam_row_ltxt = hossam_t( 'Original' );
                          } elseif ( $hossam_row_tid > 0 && 'publish' === ( function_exists( 'get_post_status' ) ? (string) get_post_status( $hossam_row_tid ) : '' ) ) {
                              $hossam_row_lcls = 'hossam-chip-pub';
                              $hossam_row_ltxt = hossam_t( 'Published' );
                          } elseif ( $hossam_row_tid > 0 ) {
                              $hossam_row_lcls = 'hossam-chip-draft';
                              $hossam_row_ltxt = hossam_t( 'Draft' );
                          } else {
                              $hossam_row_lcls = 'hossam-chip-none';
                              $hossam_row_ltxt = hossam_t( 'Not translated' );
                          }
                          $hossam_row_lang_chips[] = [ strtoupper( $hossam_row_lcode ), $hossam_row_lcls, $hossam_row_ltxt ];
                      }
                  }
                ?>
                <tr>
                  <td><span class="td-title" title="<?php the_title_attribute(); ?>"><?php echo esc_html(get_the_title()); ?></span></td>
                  <td><span class="td-muted"><?php echo esc_html($cat_str); ?></span></td>
                  <td>
                    <?php if ( ! empty( $hossam_row_lang_chips ) ) : ?>
                    <div class="hossam-lang-chips">
                      <?php foreach ( $hossam_row_lang_chips as $hossam_row_chip ) : ?>
                      <span class="hossam-lang-chip <?php echo esc_attr( $hossam_row_chip[1] ); ?>" title="<?php echo esc_attr( $hossam_row_chip[2] ); ?>" aria-label="<?php echo esc_attr( $hossam_row_chip[0] . ' — ' . $hossam_row_chip[2] ); ?>"><?php echo esc_html( $hossam_row_chip[0] ); ?></span>
                      <?php endforeach; ?>
                    </div>
                    <?php else : ?>
                    <span class="td-muted">—</span>
                    <?php endif; ?>
                  </td>
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
                    <?php
                    // v1.1.4 item 13 + v1.1.5 item 26 (D-OWNER-1(A)): the
                    // author info reads are hoisted once — both cell paths
                    // consume them. Function-guarded so the isolated
                    // rendering harnesses (no author stubs) stay green.
                    $hossam_row_author_id   = function_exists( 'get_post_field' ) ? (int) get_post_field( 'post_author', $post_id ) : 0;
                    $hossam_row_author_name = $hossam_row_author_id && function_exists( 'get_the_author_meta' ) ? (string) get_the_author_meta( 'display_name', $hossam_row_author_id ) : '';
                    $hossam_row_author_url  = $hossam_row_author_id && function_exists( 'get_the_author_meta' ) ? (string) get_the_author_meta( 'user_url', $hossam_row_author_id ) : '';
                    ?>
                    <?php if ( current_user_can( 'manage_options' ) ) : ?>
                    <!-- v1.1.4 item 13: the admin path keeps the existing
                         actions verbatim; v1.1.5 item 26 (D-OWNER-1(A))
                         appends the SAME author-info markup beside the actions
                         inside the same cell (the writer-path block verbatim —
                         zero endpoint, zero JS, zero adapter). -->
                    <div class="articles-tbl-actions">
                      <?php if ( $can_edit ) : ?>
                      <a href="<?php echo esc_url($edit_url); ?>" class="btn btn-sm"><?php echo esc_html( hossam_t( 'Edit' ) ); ?></a>
                      <?php endif; ?>
                      <?php if ( $can_del ) : ?>
                      <a href="<?php echo esc_url($del_url); ?>" class="btn btn-sm btn-danger"><?php echo esc_html( hossam_t( 'Delete' ) ); ?></a>
                      <?php endif; ?>
                      <a href="<?php echo esc_url(get_permalink($post_id)); ?>" target="_blank" class="btn btn-sm btn-ghost"><?php echo esc_html( hossam_t( 'View' ) ); ?></a>
                      <?php if ( current_user_can( 'manage_options' ) && 'official' === $hossam_editor_mode ) : ?>
                      <!-- v1.1.0 OEB (H1 §7-1 — merge R23): the official editor
                           open button — administrators only × official from
                           get_effective_editor_mode(); absent from the DOM
                           entirely for non-admins. Text: i18n oeb_open (R41). -->
                      <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $post_id . '&action=edit' ) ); ?>" target="_blank" rel="noopener" class="hossam-oeb-btn js-oeb-open" data-oeb-post="<?php echo esc_attr( $post_id ); ?>" title="<?php echo esc_attr( __( 'Admin gateway — opens the official editor in a separate tab, administrators only.', 'hal-frontend-dashboard' ) ); ?>"><?php echo esc_html( hossam_t( 'Manual update' ) ); ?></a>
                      <?php endif; ?>
                    </div>
                    <div class="td-muted hossam-author-info">
                      <?php if ( $hossam_row_author_name ) : ?>
                      <span><?php echo esc_html( $hossam_row_author_name ); ?></span>
                      <?php endif; ?>
                      <?php if ( $hossam_row_author_url ) : ?>
                      <a href="<?php echo esc_url( $hossam_row_author_url ); ?>" target="_blank" rel="noopener" class="hossam-author-site"><?php echo esc_html( hossam_t( 'Author site' ) ); ?></a>
                      <?php endif; ?>
                      <?php if ( ! $hossam_row_author_name && ! $hossam_row_author_url ) : ?>
                      <span class="hossam-aside-muted">—</span>
                      <?php endif; ?>
                    </div>
                    <?php else : ?>
                    <!-- v1.1.4 item 13 (the writer path): the author info for
                         the article and their site account — the author display
                         name and their site link; zero action buttons. -->
                    <div class="td-muted hossam-author-info">
                      <?php if ( $hossam_row_author_name ) : ?>
                      <span><?php echo esc_html( $hossam_row_author_name ); ?></span>
                      <?php endif; ?>
                      <?php if ( $hossam_row_author_url ) : ?>
                      <a href="<?php echo esc_url( $hossam_row_author_url ); ?>" target="_blank" rel="noopener" class="hossam-author-site"><?php echo esc_html( hossam_t( 'Author site' ) ); ?></a>
                      <?php endif; ?>
                      <?php if ( ! $hossam_row_author_name && ! $hossam_row_author_url ) : ?>
                      <span class="hossam-aside-muted">—</span>
                      <?php endif; ?>
                    </div>
                    <?php endif; ?>
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
        <?php
          $hossam_edit_resource_allowed = false;
          if ( $url_post_id ) {
              $the_post = get_post( $url_post_id );
              if ( ! $the_post || 'post' !== $the_post->post_type || $the_post->post_status === 'trash' ) {
                  echo '<div class="card mb-14"><div class="info-card">' . esc_html( hossam_t( 'Article not found.' ) ) . '</div></div>';
              } elseif ( ! current_user_can( 'edit_post', $url_post_id ) ) {
                  // Item 3 (second batch): per-post capability check via
                  // map_meta_cap instead of a manual post_author comparison
                  // (line-142 reference). The endpoint re-verifies
                  // server-side; hiding the form here is not authorization.
                  echo '<div class="card mb-14"><div class="info-card" style="color:var(--danger)">' . esc_html( hossam_t( 'You do not have permission to edit this article.' ) ) . '</div></div>';
              } else {
                  $hossam_edit_resource_allowed = true;
                  $hossam_edit_cats = wp_get_post_categories( $the_post->ID );
                  $hossam_can_publish = current_user_can( 'publish_posts' );
                  $hossam_cur_status  = get_post_status( $the_post );
                  $hossam_edit_role_label = $is_admin
                      ? hossam_t( 'Administrator' )
                      : ( $is_editor ? hossam_t( 'Editor' ) : ( $is_author ? hossam_t( 'Author' ) : hossam_t( 'Contributor' ) ) );
                  ?>
                  <!-- Core-first: full server-side population then submit to
                       hossam_update_article.
                       v1.1.5 items 21-1/15-1: the edit form wraps the same
                       two-column workspace grid as the write form (the form
                       is never split — every field stays its DOM child), and
                       the edit branch drops its former bare one-card wrapper
                       so the writing column renders identically to the write
                       context. -->
                   <form id="hossam-post-edit-form" onsubmit="event.preventDefault(); hossamUpdateArticle(this); return false;">
                    <input type="hidden" name="post_id" value="<?php echo esc_attr( $the_post->ID ); ?>" />
                    <div class="hossam-write-workspace hossam-write-has-aside">
                    <div class="hossam-write-writing-col">
                    <div class="card hossam-write-card">
                    <!-- v1.1.5 item 19-2: the edit title adopts the write
                         panel's title markup verbatim (the shell + the H1
                         badge + the policy line — value transfer, no parallel
                         build) with the edit field id/name/value contract
                         unchanged. -->
                    <div class="form-group mb-8 hossam-write-title-group">
                      <label class="form-label" for="hossam-edit-title"><?php echo esc_html( hossam_t( 'Title' ) ); ?></label>
                      <div class="hossam-write-title-shell">
                        <span class="hossam-write-h1-badge" title="<?php echo esc_attr( __( 'Heading policy: H1 is reserved for the main title only — the article body starts at H2 or above.', 'hal-frontend-dashboard' ) ); ?>">H1</span>
                        <input type="text" class="form-input hossam-write-title-input" id="hossam-edit-title" name="title" maxlength="255" value="<?php echo esc_attr( $the_post->post_title ); ?>" />
                      </div>
                      <div class="hossam-write-title-hint">
                        <span class="hossam-write-policy-pill">H1</span>
                        <span><?php echo esc_html( __( 'H1 is reserved for the title only — the article body starts at H2 or above.', 'hal-frontend-dashboard' ) ); ?></span>
                      </div>
                    </div>
                    <!-- v1.1.5 item 19-1: the Excerpt group directly under the
                         title group (the read is name-based and
                         position-independent — hossam-edit-excerpt
                         untouched). -->
                    <div class="form-group mb-8">
                      <label class="form-label" for="hossam-edit-excerpt"><?php echo esc_html( hossam_t( 'Excerpt' ) ); ?></label>
                      <textarea class="form-input" id="hossam-edit-excerpt" name="excerpt" rows="3"><?php echo esc_textarea( $the_post->post_excerpt ?? '' ); ?></textarea>
                    </div>
                    <div class="form-group mb-8 hossam-write-content-group">
                      <label class="form-label" for="hossam-edit-content"><?php echo esc_html( hossam_t( 'Content' ) ); ?></label>
                      <div class="hossam-write-editor-surface">
                      <textarea class="form-input hossam-write-content-input" id="hossam-edit-content" name="content" rows="12"><?php echo esc_textarea( $the_post->post_content ); ?></textarea>
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
                      </div><!-- /hossam-write-editor-surface -->
                    </div>
                    <!-- v1.1.5 item 15: the categories tree and the
                         featured-image package moved DOM inside the Metadata
                         card (side-stack position 1) — same ids/names/
                         contract, still children of the single form; the
                         Status element and the Permalink row moved into the
                         Publishing card (item 14-2). -->
                    <div class="form-actions mt-12">
                      <button type="submit" class="btn btn-gold"><?php echo esc_html( hossam_t( 'Save Article' ) ); ?></button>
                    </div>
                    </div><!-- /card hossam-write-card -->
                    </div><!-- /hossam-write-writing-col -->
                    <!-- v1.1.5 item 14/21: the side column — the six cards in
                         the royal order (Metadata → Tags → Translations → SEO
                         → AI → Publishing), same heads and classes as the
                         write context; the ids carry the hossam-edit- prefix
                         (the two panels live in one page — unique ids), and
                         the shared data-hossam-side-card marker carries the
                         positional contract the batch17 harness measures. -->
                    <div class="hossam-write-side-stack">
                    <!-- (1) Metadata — the live card (item 15). -->
                    <div class="card hossam-write-aside-holder" id="hossam-edit-metadata-holder" data-hossam-side-card="metadata">
                      <div class="hossam-write-card-head">
                        <div class="card-sub hossam-write-card-title"><span class="hossam-write-card-ico" aria-hidden="true">🏷️</span><?php echo esc_html( hossam_t( 'Metadata' ) ); ?></div>
                        <span class="hossam-write-card-tag"><?php echo esc_html( hossam_t( 'Optional' ) ); ?></span>
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
                    </div><!-- /hossam-edit-metadata-holder -->
                    <!-- (2) Tags — v1.1.5 item 16: the live card in the edit
                         context — the article's current tags render as
                         removable chips and the suggestions cover the
                         published posts of the article's own categories
                         (S-9: the read scope travels on the render). -->
                    <div class="card hossam-write-aside-holder" id="hossam-edit-tags-holder" data-hossam-side-card="tags">
                      <div class="hossam-write-card-head">
                        <div class="card-sub hossam-write-card-title"><span class="hossam-write-card-ico" aria-hidden="true">#️⃣</span><?php echo esc_html( hossam_t( 'Tags' ) ); ?></div>
                        <span class="hossam-write-card-tag"><?php echo esc_html( hossam_t( 'Optional' ) ); ?></span>
                      </div>
                      <?php
                        $hossam_edit_tag_names = array();
                        if ( function_exists( 'wp_get_post_tags' ) ) {
                            foreach ( (array) wp_get_post_tags( (int) $the_post->ID, array( 'fields' => 'names' ) ) as $hossam_edit_tag_name ) {
                                if ( is_string( $hossam_edit_tag_name ) && '' !== $hossam_edit_tag_name ) {
                                    $hossam_edit_tag_names[] = $hossam_edit_tag_name;
                                }
                            }
                        }
                        $hossam_edit_tag_scope = ( function_exists( 'get_posts' ) && function_exists( 'wp_get_object_terms' ) )
                            ? 'post_tag@publish;category:' . ( empty( $hossam_edit_cats ) ? 'all' : implode( ',', array_map( 'strval', array_map( 'intval', (array) $hossam_edit_cats ) ) ) )
                            : '';
                        $hossam_render_tags_card( $hossam_edit_tag_names, $hossam_tag_suggestions( (array) $hossam_edit_cats ), $hossam_edit_tag_scope );
                      ?>
                    </div>
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
                  <!-- (3) Translations — v1.1.5 item 17: the same card in the
                       edit context with the real rows and their real actions
                       (the js-translate-article delegation and the
                       hossam_translate_article endpoint untouched); the
                       unified head (item 21-2) and the legacy card id kept on
                       the inner block. WPML stays detection-only. -->
                  <div class="card hossam-write-aside-holder" id="hossam-edit-translations-holder" data-hossam-side-card="translations">
                    <div class="hossam-write-card-head">
                      <div class="card-sub hossam-write-card-title"><span class="hossam-write-card-ico" aria-hidden="true">🌐</span><?php echo esc_html( hossam_t( 'Languages & Translation' ) ); ?></div>
                      <span class="hossam-write-card-tag"><?php echo esc_html( $hossam_edit_role_label ); ?></span>
                    </div>
                    <div id="hossam-languages-card">
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
                    </div><!-- /hossam-languages-card -->
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
                  <!-- (4) SEO & SERP — v1.1.5 item 18: the live card in the
                       edit context (the write context keeps the availability
                       card). The fields reflect the article live while it is
                       being edited (the posts.js listeners); Rank Math stays
                       the only score source. v1.1.5 item 21-3: the inner SEO
                       form becomes a non-form container with the SAME id —
                       inside the wrapping edit form a nested form would be
                       invalid — with distinguished internal field names
                       (seo_post_id/seo_title/seo_focus_keyword/
                       seo_description/seo_robots[]) so the outer
                       hossamUpdateArticle reads never collide; hossamSaveSeo
                       appends the SAME wire keys (title/focus_keyword/
                       description/robots[]/post_id) byte-for-byte, so the
                       hossam_save_seo endpoint contract is untouched. The
                       Save SEO button is type="button" calling the existing
                       handler. -->
                  <div class="card hossam-write-aside-holder" id="hossam-edit-seo-holder" data-hossam-side-card="seo">
                    <div class="hossam-write-card-head">
                      <div class="card-sub hossam-write-card-title"><span class="hossam-write-card-ico" aria-hidden="true">🔍</span><?php echo esc_html( hossam_t( 'SEO & SERP' ) ); ?></div>
                      <span class="hossam-write-card-tag"><?php echo esc_html( $hossam_edit_role_label ); ?></span>
                    </div>
                    <div id="hossam-edit-seo-group">
                    <?php
                    // v1.1.3 W6 (D3): the simplified SEO group per the adopted
                    // design — a colored read-only meter whose thresholds are
                    // identical to the articles-table SEO column (>=80 good /
                    // >=51 average / <=50 weak / none), one explanation line
                    // per field, guidance-only character counters (the live
                    // update is the posts.js listeners — v1.1.5 item 18-1; the
                    // initial value is server-computed), and robots labels
                    // carrying a plain description with the raw value beside
                    // it (the raw values remain the save contract). Rank Math
                    // stays the only score source — no client-side score
                    // calculation anywhere.
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
                    // [W8] v1.1.3 (OP-8): the RankMath suggestions checklist -
                    // derived server-side from the EXISTING five reads only
                    // (display guidance, never a score: Rank Math stays the
                    // only score source). Each suggestion names the field it
                    // guides; an empty list renders nothing.
                    $hossam_seo_suggestions = [];
                    if ( '' === trim( $hossam_edit_seo_kw ) ) {
                        $hossam_seo_suggestions[] = hossam_t( 'Set a focus keyword so the score has a target.' );
                    }
                    if ( '' === trim( $hossam_edit_seo_title ) ) {
                        $hossam_seo_suggestions[] = hossam_t( 'Write the SEO title — it is the headline search results show.' );
                    } elseif ( $hossam_seo_title_len < 50 || $hossam_seo_title_len > 60 ) {
                        $hossam_seo_suggestions[] = hossam_t( 'Keep the SEO title between 50 and 60 characters.' );
                    }
                    if ( '' === trim( $hossam_edit_seo_desc ) ) {
                        $hossam_seo_suggestions[] = hossam_t( 'Write the meta description — it is the summary line under the title.' );
                    } elseif ( $hossam_seo_desc_len < 120 || $hossam_seo_desc_len > 160 ) {
                        $hossam_seo_suggestions[] = hossam_t( 'Keep the meta description between 120 and 160 characters.' );
                    }
                    if ( is_array( $hossam_edit_seo_robots ) && in_array( 'noindex', $hossam_edit_seo_robots, true ) ) {
                        $hossam_seo_suggestions[] = hossam_t( 'This page is set to noindex — it will not appear in search results.' );
                    }
                    ?>
                    <div class="hossam-seo-meter">
                      <span class="hossam-seo-meter-track"><span class="hossam-seo-meter-fill <?php echo esc_attr( $hossam_seo_fill_cls ); ?>" style="width:<?php echo esc_attr( (string) min( 100, max( 0, $hossam_edit_seo_score ) ) ); ?>%"></span></span>
                      <span class="hossam-seo-meter-value" id="hossam-edit-seo-score"><?php echo esc_html( $hossam_edit_seo_score > 0 ? $hossam_edit_seo_score . '%' : '—' ); ?></span>
                      <span class="hossam-seo-meter-label <?php echo esc_attr( $hossam_seo_label_cls ); ?>"><?php echo esc_html( $hossam_seo_meter_label ); ?></span>
                    </div>
                    <p class="form-hint mb-8"><?php echo esc_html( hossam_t( 'Raise the score by completing the fields below — Rank Math calculates it automatically.' ) ); ?></p>
                    <!-- v1.1.5 item 18-1: the suggestions list is always
                         addressable (the posts.js listeners rebuild it live
                         with the same server rules and hide it when empty) —
                         the initial server-computed list is unchanged. -->
                    <ul class="hossam-seo-suggestions mb-8" id="hossam-seo-suggestions"<?php echo empty( $hossam_seo_suggestions ) ? ' hidden' : ''; ?>>
                      <?php foreach ( $hossam_seo_suggestions as $hossam_seo_suggestion ) : ?>
                      <li><?php echo esc_html( $hossam_seo_suggestion ); ?></li>
                      <?php endforeach; ?>
                    </ul>
                    <!-- v1.1.5 item 21-3: the non-form container with the same
                         id (the nested-form constraint) + the distinguished
                         internal names; hossamSaveSeo reads these names and
                         appends the unchanged wire keys. -->
                    <div id="hossam-edit-seo-form">
                      <input type="hidden" name="seo_post_id" value="<?php echo esc_attr( $the_post->ID ); ?>" />
                      <div class="form-group mb-8">
                        <label class="form-label" for="hossam-edit-seo-kw"><?php echo esc_html( hossam_t( 'Focus Keyword' ) ); ?></label>
                        <input type="text" class="form-input" id="hossam-edit-seo-kw" name="seo_focus_keyword" maxlength="255" value="<?php echo esc_attr( $hossam_edit_seo_kw ); ?>" />
                        <p class="form-hint"><?php echo esc_html( hossam_t( 'The keyword your client searches for — e.g. corporate lawyer in Cairo.' ) ); ?></p>
                      </div>
                      <div class="form-group mb-8">
                        <div class="hossam-seo-field-head">
                          <label class="form-label" for="hossam-edit-seo-title"><?php echo esc_html( hossam_t( 'SEO Title' ) ); ?></label>
                          <span class="hossam-seo-counter"><?php echo esc_html( $hossam_seo_title_len . ' / 60' ); ?></span>
                        </div>
                        <input type="text" class="form-input" id="hossam-edit-seo-title" name="seo_title" maxlength="255" value="<?php echo esc_attr( $hossam_edit_seo_title ); ?>" />
                        <p class="form-hint"><?php echo esc_html( hossam_t( 'The title shown in search results — it may differ from the article title.' ) ); ?></p>
                      </div>
                      <div class="form-group mb-8">
                        <div class="hossam-seo-field-head">
                          <label class="form-label" for="hossam-edit-seo-desc"><?php echo esc_html( hossam_t( 'Meta Description' ) ); ?></label>
                          <span class="hossam-seo-counter"><?php echo esc_html( $hossam_seo_desc_len . ' / 160' ); ?></span>
                        </div>
                        <textarea class="form-input" id="hossam-edit-seo-desc" name="seo_description" rows="3"><?php echo esc_textarea( $hossam_edit_seo_desc ); ?></textarea>
                        <p class="form-hint"><?php echo esc_html( hossam_t( 'The summary line shown under the title in search results.' ) ); ?></p>
                      </div>
                      <div class="form-group mb-8">
                        <span class="form-label"><?php echo esc_html( hossam_t( 'Robots' ) ); ?></span>
                        <div class="hossam-seo-robots">
                          <?php foreach ( $hossam_edit_seo_options as $hossam_edit_seo_option ) : ?>
                          <label class="hossam-seo-robots-row">
                            <input type="checkbox" name="seo_robots[]" value="<?php echo esc_attr( $hossam_edit_seo_option ); ?>"<?php echo ( is_array( $hossam_edit_seo_robots ) ? in_array( $hossam_edit_seo_option, $hossam_edit_seo_robots, true ) : str_contains( (string) $hossam_edit_seo_robots, $hossam_edit_seo_option ) ) ? ' checked' : ''; ?> />
                            <span><?php echo esc_html( $hossam_seo_robots_labels[ $hossam_edit_seo_option ] ?? $hossam_edit_seo_option ); ?></span>
                            <span class="hossam-robots-raw"><?php echo esc_html( $hossam_edit_seo_option ); ?></span>
                          </label>
                          <?php endforeach; ?>
                        </div>
                      </div>
                      <div class="form-actions mt-12">
                        <button type="button" class="btn btn-gold" onclick="hossamSaveSeo(document.getElementById('hossam-edit-seo-form'))"><?php echo esc_html( hossam_t( 'Save SEO' ) ); ?></button>
                      </div>
                    </div><!-- /hossam-edit-seo-form (the non-form container — item 21-3) -->
                    </div><!-- /hossam-edit-seo-group -->
                  </div><!-- /hossam-edit-seo-holder -->
                  <?php endif; ?>
                  <?php if ( HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'ai' ) && function_exists( 'hossam_ai_interface_available' ) && hossam_ai_interface_available() ) : ?>
                  <!-- (5) AI Writing Assistant — third batch (7.4): inside the
                       permission branch only — edit_post at post level pinned
                       above, and the capability proven server-side now.
                       (Batch 7: + the owner gate B3-08
                       is_feature_enabled('ai')).
                       v1.1.5 item 21-2: the unified card head (the same
                       pattern as the write context) with the interactive icon
                       (item 18-4 — a focus link only). -->
                  <div class="card hossam-write-ai-card" data-hossam-side-card="ai">
                    <div class="hossam-write-card-head">
                      <div class="card-sub hossam-write-card-title"><button type="button" class="hossam-write-card-ico hossam-write-card-ico-btn" aria-label="<?php echo esc_attr( hossam_t( 'Jump to the AI actions' ) ); ?>" title="<?php echo esc_attr( hossam_t( 'Jump to the AI actions' ) ); ?>">✨</button><?php echo esc_html( hossam_t( 'AI Writing Assistant' ) ); ?></div>
                      <span class="hossam-write-card-tag"><?php echo esc_html( hossam_t( 'Optional' ) ); ?></span>
                    </div>
                    <p class="hossam-write-card-desc"><?php echo esc_html( hossam_t( 'Optional assistant for proofreading, translation and SEO — results appear inside this panel.' ) ); ?></p>
                    <div class="filter-bar hossam-write-ai-actions">
                      <button type="button" class="btn btn-sm js-ai-run" data-job-type="grammar" data-post-id="<?php echo esc_attr( $the_post->ID ); ?>" data-source="hossam-edit-content" data-result="ai-suggestion-result-edit"><?php echo esc_html( hossam_t( 'Grammar Check' ) ); ?></button>
                      <input type="text" class="filter-select" id="hossam-ai-target-edit" maxlength="8" placeholder="<?php echo esc_attr( hossam_t( 'Target language code' ) ); ?>" />
                      <button type="button" class="btn btn-sm js-ai-run" data-job-type="translation" data-post-id="<?php echo esc_attr( $the_post->ID ); ?>" data-source="hossam-edit-content" data-lang-source="hossam-ai-target-edit" data-result="ai-suggestion-result-edit"><?php echo esc_html( hossam_t( 'Suggest Translation' ) ); ?></button>
                      <button type="button" class="btn btn-sm js-ai-run" data-job-type="seo" data-post-id="<?php echo esc_attr( $the_post->ID ); ?>" data-source="hossam-edit-content" data-title-source="hossam-edit-title" data-result="ai-suggestion-result-edit"><?php echo esc_html( hossam_t( 'Suggest SEO' ) ); ?></button>
                      <button type="button" class="btn btn-sm js-ai-run" data-job-type="improvement" data-post-id="<?php echo esc_attr( $the_post->ID ); ?>" data-source="hossam-edit-content" data-result="ai-suggestion-result-edit"><?php echo esc_html( hossam_t( 'Suggest Improvements' ) ); ?></button>
                    </div>
                    <div id="ai-suggestion-result-edit" class="hidden"></div>
                  </div>
                  <?php endif; ?>
                  <!-- (6) Publishing — v1.1.5 item 14-2/21-2: the real Status
                       element and the real Permalink row moved DOM into the
                       card (the id/name/option contracts verbatim — the
                       posts.js reads and the bottom action bar are
                       name/id-based and position-independent). -->
                  <div class="card hossam-write-aside-holder" id="hossam-edit-publishing-holder" data-hossam-side-card="publishing">
                    <div class="hossam-write-card-head">
                      <div class="card-sub hossam-write-card-title"><span class="hossam-write-card-ico" aria-hidden="true">📌</span><?php echo esc_html( hossam_t( 'Publishing' ) ); ?></div>
                      <span class="hossam-write-card-tag"><?php echo esc_html( $hossam_edit_role_label ); ?></span>
                    </div>
                    <?php
                    // v1.0.6 P1: read-only Permalink display (get_permalink) —
                    // the draft link tagged "Temporary — final on publish". No
                    // slug edit field.
                    $hossam_edit_permalink = function_exists( 'get_permalink' ) ? (string) get_permalink( $the_post->ID ) : '';
                    if ( '' !== $hossam_edit_permalink ) :
                    ?>
                    <div class="hossam-aside-row">
                      <span class="form-label"><?php echo esc_html( hossam_t( 'Permalink' ) ); ?></span>
                      <span class="hossam-edit-permalink-value">
                        <a href="<?php echo esc_url( $hossam_edit_permalink ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $hossam_edit_permalink ); ?></a>
                        <?php if ( in_array( $hossam_cur_status, [ 'draft', 'pending', 'future' ], true ) ) : ?>
                        <span class="data-tag"><?php echo esc_html( hossam_t( 'Temporary — final on publish' ) ); ?></span>
                        <?php endif; ?>
                      </span>
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
                  </div>
                  </div><!-- /hossam-write-side-stack -->
                  </div><!-- /hossam-write-workspace -->
                  </form>
                  <?php
              }
          } else {
              echo '<div class="card mb-14"><div class="info-card">
                  <p>' . sprintf(
                      hossam_t( 'Select an article from %s and click Edit to load the form here.' ),
                      '<strong>' . esc_html( hossam_t( 'All My Articles' ) ) . '</strong>'
                  ) . '</p>
                  <button class="btn btn-gold mt-8" onclick="nav(document.querySelector(\'[data-panel=all-articles]\'),\'all-articles\')">' . esc_html( hossam_t( 'Go to My Articles' ) ) . '</button>
              </div></div>';
          }
        ?>
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
