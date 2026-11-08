<?php
/**
 * runtime/templates/dashboard/files.php — Files & Documents panel (batch 7)
 * ══════════════════════════════════════════════════════════════
 * Source: legacy theme/template-parts/dashboard/files.php (byte-level
 * source fingerprint pinned in the batch 7 inventory — snapshot 67044A2F…).
 *
 * The sole authorized delta (owner decision B3-08 — option a): the
 * current_user_can('upload_files') condition had the owner gate
 * is_feature_enabled('files') added to it — the upload contract
 * (media_handle_upload via the converter and the MIME allow-list) and
 * the messages are as in the actual policy without change.
 *
 * Explicit args: the panel relies only on the current user's capabilities
 * (no variables from the shell) — it is called from render_part with the
 * documented args list.
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$hossam_has_shared_fallback = shortcode_exists( 'shared_files' );
$hossam_upload_mimes = function_exists( 'hossam_get_allowed_upload_mimes' ) ? hossam_get_allowed_upload_mimes() : [];
$hossam_upload_extensions = [];
foreach ( array_keys( $hossam_upload_mimes ) as $extension_pattern ) {
    foreach ( explode( '|', (string) $extension_pattern ) as $extension ) {
        $extension = sanitize_key( $extension );
        if ( '' !== $extension ) $hossam_upload_extensions[] = $extension;
    }
}
$hossam_upload_extensions = array_values( array_unique( $hossam_upload_extensions ) );
?>
    <!-- ══ PANEL: MEDIA & UPLOADS ══ -->
    <?php if ( current_user_can( 'upload_files' ) && HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'files' ) ) : ?>
    <div class="panel" id="panel-media">
      <div class="page-hdr">
        <div class="page-breadcrumb"><span><?php echo esc_html( hossam_t( 'Dashboard' ) ); ?></span><span>›</span><span class="crumb-active"><?php echo esc_html( hossam_t( 'Files & Documents' ) ); ?></span></div>
        <h1><?php echo esc_html( hossam_t( 'Files & Documents' ) ); ?></h1>
        <p><?php echo esc_html( hossam_t( 'Upload and manage files attached to your work.' ) ); ?></p>
      </div>
      <div class="tab-group" data-tab-group="files">
        <div class="tab-bar mb-14">
          <button class="tab-btn active" data-tab="upload" onclick="switchTab(this)"><?php echo esc_html( hossam_t( 'Upload' ) ); ?></button>
          <button class="tab-btn" data-tab="my-files" onclick="switchTab(this)"><?php echo esc_html( hossam_t( 'My Files' ) ); ?></button>
          <button class="tab-btn" data-tab="trash" onclick="switchTab(this)"><?php echo esc_html( hossam_t( 'Trash' ) ); ?></button>
        </div>
        <div class="tab-content active" data-tab-content="upload">
          <div class="card mb-14">
            <!-- Core-first (batch 2): upload via media_handle_upload with
                 upload_files and the MIME allow-list inside the converter, server-side -->
            <form id="hossam-upload-form" onsubmit="event.preventDefault(); hossamUploadFile(this); return false;">
              <div class="form-group mb-8">
                <label class="form-label" for="hossam-upload-input"><?php echo esc_html( hossam_t( 'File' ) ); ?></label>
                <input type="file" class="form-input" id="hossam-upload-input" name="file" required />
              </div>
              <div class="form-actions mt-12">
                <button type="submit" class="btn btn-gold"><?php echo esc_html( hossam_t( 'Upload File' ) ); ?></button>
                <a href="<?php echo esc_url( add_query_arg( [ 'post_type' => 'attachment' ], admin_url( 'upload.php' ) ) ); ?>" target="_blank" class="btn btn-outline"><?php echo esc_html( hossam_t( 'Open Media Library' ) ); ?></a>
              </div>
            </form>
            <p class="card-sub mt-8"><?php echo esc_html( $hossam_upload_extensions
                ? hossam_t( 'Allowed types' ) . ': ' . implode( ', ', $hossam_upload_extensions )
                : hossam_t( 'No file types are currently enabled for upload.' ) ); ?></p>
          </div>
          <!-- Narrowly-scoped fallback only: [shared_files file_upload=1] is hidden; it is opened only on
               a technical failure from uploads.js — not on a security refusal or a disallowed file type -->
          <?php if ( $hossam_has_shared_fallback ) : ?>
          <div class="card" id="hossam-shared-fallback" style="display:none">
            <?php echo do_shortcode( '[shared_files file_upload=1]' ); ?>
          </div>
          <?php endif; ?>
        </div>
        <div class="tab-content" data-tab-content="my-files" style="display:none">
          <div class="card">
            <div id="files-my-files-wrap">
              <div class="ph-notice"><?php echo esc_html( hossam_t( 'Loading…' ) ); ?></div>
            </div>
          </div>
        </div>
        <div class="tab-content" data-tab-content="trash" style="display:none">
          <div class="card">
            <div id="files-trash-wrap">
              <div class="ph-notice"><?php echo esc_html( hossam_t( 'Loading…' ) ); ?></div>
            </div>
          </div>
        </div>
      </div>
    </div><!-- /panel-media -->
    <?php endif; ?>
