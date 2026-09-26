<?php
/**
 * runtime/ajax/posts.php (الدفعة 5)
 * ══════════════════════════════════════════════════════════════
 * الدور: كل نقاط AJAX الخاصة بالمقالات — خمسة endpoints كلها wp_ajax_*
 * بلا nopriv: restore/translate/create/update/trash. طبقة ترجمة فقط:
 * nonce موحد + حارس 401 + تحقق شكل مدخلات محافظ + ترجمة WP_Error إلى
 * حالات HTTP؛ منطق المقالات مالكه adapters/wordpress-posts.php وعقد
 * WPML مالكه adapters/wpml.php (الدفعة 4) — كما قرر رأس المصدر حرفيًا.
 *
 * المصدر: نقل حرفي 1:1 لكل الكود من legacy mu-plugins/hossam-dashboard/
 * ajax/posts.php — بصمة المصدر بالبايت مثبتة في inventory الدفعة 5
 * (build/source-inventory-batch-5.json). صفر تعديل في العقود.
 *
 * العقد المنقول كما هو (ملخَّص من رأس المصدر)، مع بوابة B3-08
 * (المعمارية §7.6 — قرار B3-08 يُستهلك في AJAX):
 *   - check_ajax_referer('hossam_nonce', 'nonce') أول سطر في كل callback
 *     ثم is_user_logged_in() → 401 'Unauthorized'، ثم بوابة الميزة
 *     (posts/wpml_translations) → 403 feature_disabled قبل أي عملية؛
 *     nonce والصلاحيات والملكية مستقلة وباقية كما هي.
 *   - restore/trash: تترجم hossam_not_found→404 وhossam_forbidden→403
 *     وأي كود آخر→400؛ وفشل غير الخطأ (true !== $result) → 500.
 *   - create/update: تحقق شكل المدخلات هنا (سلاسل/مصفوفة تصنيفات أعداد
 *     صحيحة موجبة/thumbnail سكالار) → 400، ثم wp_unslash وتمرير $data
 *     للمحوِّل الذي يملك التنظيف الفعلي — لا ازدواج تنظيف في طبقتين.
 *   - أخطاء المحوِّل عبر hossam_send_article_error():
 *     hossam_not_found→404؛ hossam_forbidden/hossam_thumbnail_forbidden→403؛
 *     hossam_invalid_categories/hossam_invalid_thumbnail→400؛ غير ذلك→500؛
 *     الحمولة message+code وحقول سياق سكالارة فقط من
 *     post_id/stage/cleanup/compensated.
 *   - translate: قرار التكامل من السجل hossam_get_integration_decision('wpml')
 *     بجانب الحارس المباشر → 503 عند unavailable أو غياب languages؛
 *     ثم 404/403/400 وفق المصدر؛ الـedit_url عبر hossam_dashboard_url().
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'hossam_send_article_error' ) ) {
    /**
     * Translates adapter failures without discarding an authorized partial-state report.
     */
    function hossam_send_article_error( WP_Error $error ): void {
        $code = $error->get_error_code();
        if ( 'hossam_not_found' === $code ) {
            $status = 404;
        } elseif ( in_array( $code, [ 'hossam_forbidden', 'hossam_thumbnail_forbidden' ], true ) ) {
            $status = 403;
        } elseif ( in_array( $code, [ 'hossam_invalid_categories', 'hossam_invalid_thumbnail' ], true ) ) {
            $status = 400;
        } else {
            $status = 500;
        }

        $payload = [
            'message' => $error->get_error_message(),
            'code'    => $code,
        ];
        $details = $error->get_error_data( $code );
        if ( is_array( $details ) ) {
            foreach ( [ 'post_id', 'stage', 'cleanup', 'compensated' ] as $field ) {
                if ( array_key_exists( $field, $details ) && is_scalar( $details[ $field ] ) ) {
                    $payload[ $field ] = $details[ $field ];
                }
            }
        }

        wp_send_json_error( $payload, $status );
    }
}

// ══════════════════════════════════════════════════════════════
// [AR-02-endpoint] Endpoint: hossam_restore_article
// الدفعة الثانية (2026-08-24): المنطق انتقل إلى hossam_restore_article()
// فى adapters/wordpress-posts.php (فحص edit_post على مستوى المقال عبر
// map_meta_cap) — هذا الـendpoint يترجم فقط وفق بند 3 الحرفى:
// «adapters/wordpress-posts.php مالك المنطق؛ ajax/posts.php يترجم فقط».
add_action( 'wp_ajax_hossam_restore_article', function(): void {
    check_ajax_referer( 'hossam_nonce', 'nonce' );
    if ( ! is_user_logged_in() ) { wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 ); }
    if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'posts' ) ) {
        wp_send_json_error( [ 'code' => 'feature_disabled', 'message' => 'This feature is disabled.' ], 403 );
    }

    $post_id = isset( $_POST['post_id'] ) && is_scalar( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

    $result = hossam_restore_article( $post_id );
    if ( is_wp_error( $result ) ) {
        $code   = $result->get_error_code();
        $status = 'hossam_not_found' === $code ? 404 : ( 'hossam_forbidden' === $code ? 403 : 400 );
        wp_send_json_error( [ 'message' => $result->get_error_message() ], $status );
    }
    if ( true !== $result ) {
        wp_send_json_error( [ 'message' => hossam_t( 'Article could not be restored.' ) ], 500 );
    }

    wp_send_json_success();
} );


// ══════════════════════════════════════════════════════════════
// [H-15] TR-02 — Create Article Translation (WPML Official API)
// ══════════════════════════════════════════════════════════════
add_action( 'wp_ajax_hossam_translate_article', function(): void {
    check_ajax_referer( 'hossam_nonce', 'nonce' );
    if ( ! is_user_logged_in() ) { wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 ); }
    if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'wpml_translations' ) ) {
        wp_send_json_error( [ 'code' => 'feature_disabled', 'message' => 'This feature is disabled.' ], 403 );
    }
    // البند التمهيدى 3 (الدفعة الثانية): قرار التكامل من سجل القدرات بجانب الحارس المباشر.
    $wpml_decision = hossam_get_integration_decision( 'wpml' );
    if ( 'unavailable' === $wpml_decision['status']
        || empty( $wpml_decision['capabilities']['languages'] ) ) {
        wp_send_json_error( [ 'message' => hossam_t( 'WPML not active.' ) ], 503 );
    }

    $post_id = isset( $_POST['post_id'] ) && is_scalar( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
    $lang_code = isset( $_POST['lang'] ) && is_string( $_POST['lang'] )
        ? sanitize_key( wp_unslash( $_POST['lang'] ) )
        : '';
    $post      = $post_id ? get_post( $post_id ) : null;

    if ( ! $post || 'post' !== $post->post_type ) {
        wp_send_json_error( [ 'message' => hossam_t( 'Article not found.' ) ], 404 );
    }

    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        wp_send_json_error( [ 'message' => hossam_t( 'You do not have permission to edit this article.' ) ], 403 );
    }

    $active_langs = hossam_wpml_get_active_languages();
    if ( '' === $lang_code || ! isset( $active_langs[ $lang_code ] ) ) {
        wp_send_json_error( [ 'message' => hossam_t( 'Invalid language.' ) ], 400 );
    }

    $user_id = get_current_user_id();

    $existing = hossam_wpml_get_translation_id( $post_id, $lang_code );
    if ( $existing ) {
        $translated_post = get_post( $existing );
        if ( ! $translated_post || 'post' !== $translated_post->post_type || ! current_user_can( 'edit_post', $existing ) ) {
            wp_send_json_error( [ 'message' => hossam_t( 'You do not have permission to edit this translation.' ) ], 403 );
        }
        wp_send_json_success( [
            'post_id'  => $existing,
            'edit_url' => add_query_arg( [ 'panel' => 'edit-article', 'post_id' => $existing ], hossam_dashboard_url() ),
            'existing' => true,
        ] );
    }

    $trid        = hossam_wpml_get_element_trid( $post_id );
    $source_lang = hossam_wpml_get_element_language_code( $post_id );
    if ( $trid < 1 || '' === $source_lang ) {
        wp_send_json_error( [ 'message' => hossam_t( 'Translation contract is unavailable.' ) ], 503 );
    }

    $new_post_id = wp_insert_post( [
        'post_title'  => $post->post_title,
        'post_type'   => 'post',
        'post_status' => 'draft',
        'post_author' => $user_id,
    ], true );

    if ( is_wp_error( $new_post_id ) ) {
        wp_send_json_error( [ 'message' => $new_post_id->get_error_message() ], 500 );
    }

    if ( ! hossam_wpml_set_element_language( $new_post_id, $trid, $lang_code, $source_lang ) ) {
        $deleted = wp_delete_post( $new_post_id, true );
        if ( ! $deleted || is_wp_error( $deleted ) ) {
            error_log( 'Hossam Dashboard translation cleanup failed for post ' . $new_post_id );
            wp_send_json_error( [ 'message' => hossam_t( 'Translation could not be linked.' ), 'code' => 'partial_failure', 'post_id' => $new_post_id, 'cleanup' => 'failed' ], 500 );
        }
        wp_send_json_error( [ 'message' => hossam_t( 'Translation could not be linked.' ), 'code' => 'translation_link_failed', 'cleanup' => 'done' ], 500 );
    }

    wp_send_json_success( [
        'post_id'  => $new_post_id,
        'edit_url' => add_query_arg( [ 'panel' => 'edit-article', 'post_id' => $new_post_id ], hossam_dashboard_url() ),
        'existing' => false,
    ] );
} );


// ══════════════════════════════════════════════════════════════════
// T-33 — Endpoint: hossam_create_article → hossam_create_article() فى adapters/wordpress-posts.php
// ══════════════════════════════════════════════════════════════════
add_action( 'wp_ajax_hossam_create_article', function(): void {
    check_ajax_referer( 'hossam_nonce', 'nonce' );
    if ( ! is_user_logged_in() ) { wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 ); }
    if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'posts' ) ) {
        wp_send_json_error( [ 'code' => 'feature_disabled', 'message' => 'This feature is disabled.' ], 403 );
    }

    $data = [];
    foreach ( [ 'title', 'content', 'status' ] as $field ) {
        if ( isset( $_POST[ $field ] ) ) {
            if ( ! is_string( $_POST[ $field ] ) ) {
                wp_send_json_error( [ 'message' => hossam_t( 'Invalid article data.' ) ], 400 );
            }
            $data[ $field ] = wp_unslash( $_POST[ $field ] );
        }
    }
    if ( isset( $_POST['categories'] ) ) {
        $raw_cats = wp_unslash( $_POST['categories'] );
        if ( ! is_array( $raw_cats ) ) {
            wp_send_json_error( [ 'message' => hossam_t( 'Invalid categories.' ) ], 400 );
        }
        foreach ( $raw_cats as $cat_item ) {
            if ( ! is_scalar( $cat_item ) || ! is_numeric( $cat_item ) || absint( $cat_item ) < 1 ) {
                wp_send_json_error( [ 'message' => hossam_t( 'Invalid categories.' ) ], 400 );
            }
        }
        $data['categories'] = array_values( array_map( 'absint', $raw_cats ) );
    }
    if ( isset( $_POST['thumbnail_id'] ) ) {
        if ( ! is_scalar( $_POST['thumbnail_id'] ) ) wp_send_json_error( [ 'message' => hossam_t( 'Invalid featured image.' ) ], 400 );
        $data['thumbnail_id'] = wp_unslash( $_POST['thumbnail_id'] );
    }

    $post_id = hossam_create_article( $data );
    if ( is_wp_error( $post_id ) ) {
        hossam_send_article_error( $post_id );
    }

    wp_send_json_success( [
        'post_id'  => $post_id,
        'edit_url' => add_query_arg( [ 'panel' => 'edit-article', 'post_id' => $post_id ], hossam_dashboard_url() ),
    ] );
} );


// ════════════════════════════════════════════════════════════
// T-33 — Endpoint: hossam_update_article → hossam_update_article() فى adapters/wordpress-posts.php
// ════════════════════════════════════════════════════════════
add_action( 'wp_ajax_hossam_update_article', function(): void {
    check_ajax_referer( 'hossam_nonce', 'nonce' );
    if ( ! is_user_logged_in() ) { wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 ); }
    if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'posts' ) ) {
        wp_send_json_error( [ 'code' => 'feature_disabled', 'message' => 'This feature is disabled.' ], 403 );
    }

    $post_id = isset( $_POST['post_id'] ) && is_scalar( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

    $data = [];
    foreach ( [ 'title', 'content', 'status' ] as $field ) {
        if ( isset( $_POST[ $field ] ) ) {
            if ( ! is_string( $_POST[ $field ] ) ) {
                wp_send_json_error( [ 'message' => hossam_t( 'Invalid article data.' ) ], 400 );
            }
            $data[ $field ] = wp_unslash( $_POST[ $field ] );
        }
    }
    if ( isset( $_POST['categories'] ) ) {
        $raw_cats = wp_unslash( $_POST['categories'] );
        if ( ! is_array( $raw_cats ) ) {
            wp_send_json_error( [ 'message' => hossam_t( 'Invalid categories.' ) ], 400 );
        }
        foreach ( $raw_cats as $cat_item ) {
            if ( ! is_scalar( $cat_item ) || ! is_numeric( $cat_item ) || absint( $cat_item ) < 1 ) {
                wp_send_json_error( [ 'message' => hossam_t( 'Invalid categories.' ) ], 400 );
            }
        }
        $data['categories'] = array_values( array_map( 'absint', $raw_cats ) );
    }
    if ( isset( $_POST['thumbnail_id'] ) ) {
        if ( ! is_scalar( $_POST['thumbnail_id'] ) ) wp_send_json_error( [ 'message' => hossam_t( 'Invalid featured image.' ) ], 400 );
        $data['thumbnail_id'] = wp_unslash( $_POST['thumbnail_id'] );
    }

    $result = hossam_update_article( $post_id, $data );
    if ( is_wp_error( $result ) ) {
        hossam_send_article_error( $result );
    }

    wp_send_json_success();
} );


// ════════════════════════════════════════════════════════════
// T-33 — Endpoint: hossam_trash_article → hossam_trash_article() فى adapters/wordpress-posts.php
// ════════════════════════════════════════════════════════════
add_action( 'wp_ajax_hossam_trash_article', function(): void {
    check_ajax_referer( 'hossam_nonce', 'nonce' );
    if ( ! is_user_logged_in() ) { wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 ); }
    if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'posts' ) ) {
        wp_send_json_error( [ 'code' => 'feature_disabled', 'message' => 'This feature is disabled.' ], 403 );
    }

    $post_id = isset( $_POST['post_id'] ) && is_scalar( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

    $result = hossam_trash_article( $post_id );
    if ( is_wp_error( $result ) ) {
        $code   = $result->get_error_code();
        $status = 'hossam_not_found' === $code ? 404 : ( 'hossam_forbidden' === $code ? 403 : 400 );
        wp_send_json_error( [ 'message' => $result->get_error_message() ], $status );
    }
    if ( true !== $result ) {
        wp_send_json_error( [ 'message' => hossam_t( 'Article could not be moved to trash.' ) ], 500 );
    }

    wp_send_json_success();
} );
