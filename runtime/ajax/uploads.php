<?php
/**
 * runtime/ajax/uploads.php (الدفعة 5)
 * ══════════════════════════════════════════════════════════════
 * الدور: كل نقاط AJAX الخاصة بالملفات — أربعة endpoints كلها wp_ajax_*
 * بلا nopriv: get_my_files/restore_file/delete_file/upload_file.
 *
 * المصدر: نقل حرفي 1:1 لكل الكود من legacy mu-plugins/hossam-dashboard/
 * ajax/uploads.php — بصمة المصدر بالبايت مثبتة في inventory الدفعة 5
 * (build/source-inventory-batch-5.json). صفر تعديل في العقود.
 *
 * الدلتا المصرَّح بها (دمج v1.1.0 — صف R9 «دمج v1.1.0: 404 الموحد»):
 *   - 404 الموحد (H3 §1.2/§1.5): الغائب والغير مملوك والبلا قدرة =
 *     404 'Not found' برسالة الغائب نفسها في restore_file/delete_file —
 *     بوابة واحدة تبتلع الوجود والملكية معًا؛ صفر 403 متبقٍ في النقطتين
 *     (الواجهة لا تفرق 403/404 — صفر لمس JS).
 *   - consume filedel 30/د في restore/delete حصرًا (G3 §1.3-8) — بلا
 *     consume داخل upload_file إطلاقًا (العدّ في prefilter R1 — G3 §1.3-6/7).
 *   - أحداث audit الثلاثة file_upload/file_delete/file_restore
 *     (G4 §3-أ مواضع 7-9 — mime يُلتقط قبل الحذف).
 *
 * العقد المنقول كما هو (ملخَّص من رأس المصدر)، مع بوابة B3-08
 * (المعمارية §7.6 — قرار B3-08 يُستهلك في AJAX):
 *   - check_ajax_referer('hossam_nonce', 'nonce') أول سطر في كل callback
 *     ثم is_user_logged_in() → 401 'Unauthorized'، ثم بوابة الميزة
 *     (files) → 403 feature_disabled قبل أي عملية؛ الصلاحيات والملكية
 *     مستقلة وباقية كما هي.
 *   - get_my_files: WP_Query على attachment بملكية author=get_current_user_id()
 *     وصفحات حقيقية (page≥1 عبر max(1,absint)، حد الصفحة 100،
 *     posts_per_page=per+1 لحساب has_more، no_found_rows)؛ view=trash →
 *     post_status 'trash' وإلا 'inherit'؛ الاستجابة {files,view,page,has_more}
 *     وكل file = {id,title,url,date,mime}.
 *   - restore_file/delete_file: بوابة 404 'Not found' الموحدة تبتلع
 *     الوجود والملكية والقدرة معًا (دلتا v1.1.0 — موافقة للتعليق
 *     الموسوم داخل المعالجَين)؛ 500 عند إخفاق
 *     wp_untrash_post/wp_delete_attachment.
 *   - upload_file: يفوض hossam_upload_file() في adapters/wordpress-uploads.php
 *     (الدفعة 4) — current_user_can('upload_files') داخلها ولا يُكرَّر
 *     هنا؛ hossam_forbidden→403 وأي خطأ آخر→400؛ الاستجابة {id,title,url}.
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ══════════════════════════════════════════════════════════════
// SECTION 17: FILE MANAGEMENT (TRASH) — Feature #15
// عزل ملكية أصيل 100% — بلا اعتماد على معامل غير موثَّق فى shortcode خارجي
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
    // R9 (دمج v1.1.0 — 404 الموحد، H3 §1.2/§1.5): الغائب والغير مملوك
    // والبلا قدرة = 404 'Not found' برسالة الغائب نفسها — بوابة واحدة
    // تبتلع الوجود والملكية معًا فلا كشف وجود قبل الرد.
    if ( ! $file || 'attachment' !== $file->post_type
        || (int) $file->post_author !== get_current_user_id() || ! current_user_can( 'edit_post', $file_id ) ) {
        wp_send_json_error( [ 'message' => 'Not found' ], 404 );
    }

    // R9 (G3 §1.3-8): consume filedel 30/د — بعد اكتمال التحققات وقبل
    // الكتابة مباشرة (عقد G3 §1.1-1)؛ الترجمة بنمط error_data['status'].
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

    // R9 (G4 §3-أ-9): file_restore بعد نجاح wp_untrash_post وقبل success —
    // قيمة الإرجاع تُتجاهل عمدًا (G4 §5).
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
    // R9 (دمج v1.1.0 — 404 الموحد، H3 §1.2/§1.5): الغائب والغير مملوك
    // والبلا قدرة = 404 'Not found' برسالة الغائب نفسها — بوابة واحدة
    // تبتلع الوجود والملكية معًا فلا كشف وجود قبل الرد.
    if ( ! $file || 'attachment' !== $file->post_type
        || (int) $file->post_author !== get_current_user_id() || ! current_user_can( 'delete_post', $file_id ) ) {
        wp_send_json_error( [ 'message' => 'Not found' ], 404 );
    }

    // R9 (G3 §1.3-8): consume filedel 30/د — بعد اكتمال التحققات وقبل
    // الكتابة مباشرة (عقد G3 §1.1-1)؛ الترجمة بنمط error_data['status'].
    $quota = hossam_rate_limit_consume( 'filedel', (int) get_current_user_id(), 30, MINUTE_IN_SECONDS );
    if ( is_wp_error( $quota ) ) {
        $data = $quota->get_error_data();
        wp_send_json_error(
            [ 'message' => $quota->get_error_message() ],
            is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 503
        );
    }

    // R9 (G4 §3-أ-8): mime يُلتقط قبل الحذف القسري wp_delete_attachment(…,true).
    $mime = (string) get_post_mime_type( $file->ID );

    if ( false === wp_delete_attachment( $file->ID, true ) ) {
        wp_send_json_error( [ 'message' => hossam_t( 'File could not be deleted.' ) ], 500 );
    }

    // R9 (G4 §3-أ-8): file_delete بعد نجاح الحذف وقبل success — قيمة
    // الإرجاع تُتجاهل عمدًا (G4 §5).
    hossam_audit_log( 'file_delete', [ 'object_id' => (int) $file->ID, 'meta' => [ 'mime' => $mime ] ] );

    wp_send_json_success();
} );

// ══════════════════════════════════════════════════════════════════
// T-36 — Endpoint: hossam_upload_file → hossam_upload_file() فى adapters/wordpress-uploads.php
// المحوّل يملك current_user_can('upload_files') داخله بالفعل ويُرجع WP_Error
// مناسبًا عند الرفض — لا تكرار للفحص هنا.
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

    // R9 (G4 §3-أ-7): file_upload قبل success — meta mime؛ بلا consume
    // هنا إطلاقًا (العدّ في prefilter R1 — G3 §1.3-6/7)؛ قيمة الإرجاع
    // تُتجاهل عمدًا (G4 §5).
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
