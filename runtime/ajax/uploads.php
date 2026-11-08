<?php
/**
 * runtime/ajax/uploads.php (Batch 5)
 * ══════════════════════════════════════════════════════════════
 * Role: every AJAX endpoint for files — four endpoints, all wp_ajax_*
 * without nopriv: get_my_files/restore_file/delete_file/upload_file.
 *
 * Source: verbatim 1:1 transfer of all code from legacy mu-plugins/hossam-dashboard/
 * ajax/uploads.php — the source fingerprint is pinned byte-for-byte in the Batch 5
 * inventory (build/source-inventory-batch-5.json). Zero changes to the contracts.
 *
 * The authorized delta (v1.1.0 merge — row R9 «v1.1.0 merge: the unified 404»):
 *   - the unified 404 (H3 §1.2/§1.5): missing, not-owned, and no-capability =
 *     404 'Not found' with the same missing-message in restore_file/delete_file —
 *     one gate swallows existence and ownership together; zero 403s remain in the two
 *     endpoints (the UI does not distinguish 403/404 — zero JS touch).
 *   - consume filedel 30/min in restore/delete exclusively (G3 §1.3-8) — no
 *     consume inside upload_file at all (the counting is in prefilter R1 — G3 §1.3-6/7).
 *   - the three audit events file_upload/file_delete/file_restore
 *     (G4 §3-a positions 7-9 — mime is captured before deletion).
 *
 * The carried-over contract as-is (summarized from the source header), with the
 * B3-08 gate
 * (architecture §7.6 — the B3-08 decision is consumed in AJAX):
 *   - check_ajax_referer('hossam_nonce', 'nonce') first line in every callback,
 *     then is_user_logged_in() → 401 'Unauthorized', then the feature gate
 *     (files) → 403 feature_disabled before any operation; capabilities and ownership
 *     are independent and remain as they are.
 *   - get_my_files: WP_Query on attachment with ownership author=get_current_user_id()
 *     and real pages (page≥1 via max(1,absint), page limit 100,
 *     posts_per_page=per+1 to compute has_more, no_found_rows); view=trash →
 *     post_status 'trash' otherwise 'inherit'; the response {files,view,page,has_more}
 *     and every file = {id,title,url,date,mime}.
 *   - restore_file/delete_file: the unified 404 'Not found' gate swallows
 *     existence, ownership and capability together (v1.1.0 delta — agreeing with the
 *     tagged comment inside the two handlers); 500 on
 *     wp_untrash_post/wp_delete_attachment failure.
 *   - upload_file: delegates to hossam_upload_file() in adapters/wordpress-uploads.php
 *     (Batch 4) — current_user_can('upload_files') is inside it and is not duplicated
 *     here; hossam_forbidden→403 and any other error→400; the response {id,title,url}.
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ══════════════════════════════════════════════════════════════
// SECTION 17: FILE MANAGEMENT (TRASH) — Feature #15
// Genuine 100% ownership isolation — without relying on an unverified parameter in an external shortcode
// ══════════════════════════════════════════════════════════════
// [FD-endpoints] hossam_get_my_files
add_action( 'wp_ajax_hossam_get_my_files', function(): void {
    check_ajax_referer( 'hossam_nonce', 'nonce' );
    if ( ! is_user_logged_in() ) { wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 ); }
    if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'files' ) ) {
        wp_send_json_error( [ 'code' => 'feature_disabled', 'message' => 'This feature is disabled.' ], 403 );
    }

    $view = isset( $_POST['view'] ) && is_string( $_POST['view'] )
        ? sanitize_key( wp_unslash( $_POST['view'] ) )
        : 'active';
    $page = isset( $_POST['page'] ) && is_scalar( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
    $per  = 100;

    $query = new WP_Query( [
        'post_type'      => 'attachment',
        'author'         => get_current_user_id(),
        'posts_per_page' => $per + 1,
        'offset'         => ( $page - 1 ) * $per,
        'post_status'    => $view === 'trash' ? 'trash' : 'inherit',
        'no_found_rows'  => true,
    ] );

    $all      = is_array( $query->posts ) ? $query->posts : [];
    $has_more = count( $all ) > $per;
    $files    = array_slice( $all, 0, $per );

    $data = array_map( function( $p ) {
        return [
            'id'    => $p->ID,
            'title' => get_the_title( $p ),
            'url'   => wp_get_attachment_url( $p->ID ),
            'date'  => get_the_date( 'Y-m-d H:i', $p ),
            'mime'  => $p->post_mime_type,
        ];
    }, $files );

    wp_send_json_success( [
        'files'    => $data,
        'view'     => $view === 'trash' ? 'trash' : 'active',
        'page'     => $page,
        'has_more' => $has_more,
    ] );
} );

// [FD-endpoints] hossam_restore_file
add_action( 'wp_ajax_hossam_restore_file', function(): void {
    check_ajax_referer( 'hossam_nonce', 'nonce' );
    if ( ! is_user_logged_in() ) { wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 ); }
    if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'files' ) ) {
        wp_send_json_error( [ 'code' => 'feature_disabled', 'message' => 'This feature is disabled.' ], 403 );
    }

    $file_id = isset( $_POST['file_id'] ) && is_scalar( $_POST['file_id'] ) ? absint( $_POST['file_id'] ) : 0;
    $file    = $file_id ? get_post( $file_id ) : null;
    // R9 (v1.1.0 merge — the unified 404, H3 §1.2/§1.5): missing, not-owned,
    // and no-capability = 404 'Not found' with the same missing-message — one gate
    // swallows existence and ownership together so no existence leaks before the reply.
    if ( ! $file || 'attachment' !== $file->post_type
        || (int) $file->post_author !== get_current_user_id() || ! current_user_can( 'edit_post', $file_id ) ) {
        wp_send_json_error( [ 'message' => 'Not found' ], 404 );
    }

    // R9 (G3 §1.3-8): consume filedel 30/min — after validations complete and before
    // the write directly (contract G3 §1.1-1); the translation in the error_data['status'] pattern.
    $quota = hossam_rate_limit_consume( 'filedel', (int) get_current_user_id(), 30, MINUTE_IN_SECONDS );
    if ( is_wp_error( $quota ) ) {
        $data = $quota->get_error_data();
        wp_send_json_error(
            [ 'message' => $quota->get_error_message() ],
            is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 503
        );
    }

    if ( false === wp_untrash_post( $file->ID ) ) {
        wp_send_json_error( [ 'message' => hossam_t( 'File could not be restored.' ) ], 500 );
    }

    // R9 (G4 §3-a-9): file_restore after wp_untrash_post succeeds and before success —
    // the return value is deliberately ignored (G4 §5).
    hossam_audit_log( 'file_restore', [ 'object_id' => (int) $file->ID ] );

    wp_send_json_success();
} );

// [FD-endpoints] hossam_delete_file
add_action( 'wp_ajax_hossam_delete_file', function(): void {
    check_ajax_referer( 'hossam_nonce', 'nonce' );
    if ( ! is_user_logged_in() ) { wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 ); }
    if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'files' ) ) {
        wp_send_json_error( [ 'code' => 'feature_disabled', 'message' => 'This feature is disabled.' ], 403 );
    }

    $file_id = isset( $_POST['file_id'] ) && is_scalar( $_POST['file_id'] ) ? absint( $_POST['file_id'] ) : 0;
    $file    = $file_id ? get_post( $file_id ) : null;
    // R9 (v1.1.0 merge — the unified 404, H3 §1.2/§1.5): missing, not-owned,
    // and no-capability = 404 'Not found' with the same missing-message — one gate
    // swallows existence and ownership together so no existence leaks before the reply.
    if ( ! $file || 'attachment' !== $file->post_type
        || (int) $file->post_author !== get_current_user_id() || ! current_user_can( 'delete_post', $file_id ) ) {
        wp_send_json_error( [ 'message' => 'Not found' ], 404 );
    }

    // R9 (G3 §1.3-8): consume filedel 30/min — after validations complete and before
    // the write directly (contract G3 §1.1-1); the translation in the error_data['status'] pattern.
    $quota = hossam_rate_limit_consume( 'filedel', (int) get_current_user_id(), 30, MINUTE_IN_SECONDS );
    if ( is_wp_error( $quota ) ) {
        $data = $quota->get_error_data();
        wp_send_json_error(
            [ 'message' => $quota->get_error_message() ],
            is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 503
        );
    }

    // R9 (G4 §3-a-8): mime is captured before the forced deletion wp_delete_attachment(…,true).
    $mime = (string) get_post_mime_type( $file->ID );

    if ( false === wp_delete_attachment( $file->ID, true ) ) {
        wp_send_json_error( [ 'message' => hossam_t( 'File could not be deleted.' ) ], 500 );
    }

    // R9 (G4 §3-a-8): file_delete after the deletion succeeds and before success — the return
    // value is deliberately ignored (G4 §5).
    hossam_audit_log( 'file_delete', [ 'object_id' => (int) $file->ID, 'meta' => [ 'mime' => $mime ] ] );

    wp_send_json_success();
} );

// ══════════════════════════════════════════════════════════════════
// T-36 — Endpoint: hossam_upload_file → hossam_upload_file() in adapters/wordpress-uploads.php
// The adapter already owns current_user_can('upload_files') inside it and returns a suitable WP_Error
// on rejection — no duplication of the check here.
// ══════════════════════════════════════════════════════════════════
add_action( 'wp_ajax_hossam_upload_file', function(): void {
    check_ajax_referer( 'hossam_nonce', 'nonce' );
    if ( ! is_user_logged_in() ) { wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 ); }
    if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'files' ) ) {
        wp_send_json_error( [ 'code' => 'feature_disabled', 'message' => 'This feature is disabled.' ], 403 );
    }

    $attachment_id = hossam_upload_file();
    if ( is_wp_error( $attachment_id ) ) {
        $status = 'hossam_forbidden' === $attachment_id->get_error_code() ? 403 : 400;
        wp_send_json_error( [ 'message' => $attachment_id->get_error_message() ], $status );
    }

    // R9 (G4 §3-a-7): file_upload before success — meta mime; no consume
    // here at all (the counting is in prefilter R1 — G3 §1.3-6/7); the return value
    // is deliberately ignored (G4 §5).
    hossam_audit_log(
        'file_upload',
        [
            'object_id' => (int) $attachment_id,
            'meta'      => [ 'mime' => (string) get_post_mime_type( $attachment_id ) ],
        ]
    );

    wp_send_json_success( [
        'id'    => $attachment_id,
        'title' => get_the_title( $attachment_id ),
        'url'   => wp_get_attachment_url( $attachment_id ),
    ] );
} );
