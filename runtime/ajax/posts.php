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

if ( ! function_exists( 'hossam_article_link_payload' ) ) {
    /**
     * v1.0.6 §4 — view_url for published posts only, preview_url for
     * draft/pending/future, both behind an explicit current_user_can(
     * 'edit_post', $post_id ) check (get_preview_post_link performs none —
     * link-template.php). No slug is ever accepted or returned here.
     *
     * @return array{view_url:string,preview_url:string}
     */
    function hossam_article_link_payload( int $post_id ): array {
        $payload = [ 'view_url' => '', 'preview_url' => '' ];
        if ( $post_id < 1 || ! current_user_can( 'edit_post', $post_id ) ) {
            return $payload;
        }
        $status = function_exists( 'get_post_status' ) ? get_post_status( $post_id ) : false;
        if ( ! is_string( $status ) || '' === $status ) {
            $post_object = get_post( $post_id );
            $status = ( $post_object && isset( $post_object->post_status ) ) ? (string) $post_object->post_status : '';
        }
        if ( 'publish' === $status ) {
            if ( function_exists( 'get_permalink' ) ) {
                $payload['view_url'] = (string) get_permalink( $post_id );
            }
        } elseif ( in_array( $status, [ 'draft', 'pending', 'future' ], true ) ) {
            if ( function_exists( 'get_preview_post_link' ) ) {
                $payload['preview_url'] = (string) get_preview_post_link( $post_id );
            }
        }
        return $payload;
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

    // v1.0.6 §14 — named permission check (pair-contract replacement: no
    // public WPML API proves translator assignment, so edit_post on the
    // source + nonce + an active target language + no existing translation
    // is the documented gate). When the official wpml_language_is_active
    // interface is registered it is authoritative and rejects here —
    // before wp_insert_post, with no draft left behind. When it is not
    // registered the active-languages membership above stays the guard.
    if ( has_filter( 'wpml_language_is_active' )
        && ! (bool) apply_filters( 'wpml_language_is_active', false, $lang_code ) ) {
        wp_send_json_error( [ 'message' => hossam_t( 'You do not have permission to create this translation.' ), 'code' => 'translation_forbidden' ], 403 );
    }

    $user_id = get_current_user_id();

    $existing = hossam_wpml_get_translation_id( $post_id, $lang_code );
    if ( $existing ) {
        $translated_post = get_post( $existing );
        if ( ! $translated_post || 'post' !== $translated_post->post_type || ! current_user_can( 'edit_post', $existing ) ) {
            wp_send_json_error( [ 'message' => hossam_t( 'You do not have permission to edit this translation.' ) ], 403 );
        }
        // v1.0.6 §14 + عقد 6.4 — membership and language are verified on
        // every translate call, not only at creation. A proven mismatch
        // (translation carries another trid/language, or the group map
        // points the target key at another element) rejects the edit;
        // states the read-back cannot prove keep the legacy edit_post-only
        // contract (1.0.5 behaviour — documented, no regression).
        $existing_trid   = hossam_wpml_get_element_trid( $post_id );
        $existing_source = hossam_wpml_get_element_language_code( $post_id );
        if ( $existing_trid > 0 && has_filter( 'wpml_element_language_details' ) && has_filter( 'wpml_get_element_translations' ) ) {
            $membership_type = 'post_post';
            if ( has_filter( 'wpml_element_type' ) ) {
                $filtered_membership_type = apply_filters( 'wpml_element_type', 'post_post' );
                if ( is_string( $filtered_membership_type ) && '' !== $filtered_membership_type ) {
                    $membership_type = $filtered_membership_type;
                }
            }
            $membership_details = apply_filters( 'wpml_element_language_details', null, [ 'element_id' => $existing, 'element_type' => $membership_type ] );
            if ( is_object( $membership_details ) ) {
                $membership_details = get_object_vars( $membership_details );
            }
            if ( is_array( $membership_details ) && isset( $membership_details['trid'], $membership_details['language_code'] ) ) {
                $membership_trid = (int) $membership_details['trid'];
                $membership_lang = is_string( $membership_details['language_code'] ) ? sanitize_key( $membership_details['language_code'] ) : '';
                if ( $membership_trid !== $existing_trid || $membership_lang !== $lang_code ) {
                    wp_send_json_error( [ 'message' => hossam_t( 'This translation does not belong to the requested article.' ), 'code' => 'translation_mismatch' ], 403 );
                }
            }
            $membership_map = apply_filters( 'wpml_get_element_translations', null, $existing_trid, $membership_type );
            if ( is_array( $membership_map ) && isset( $membership_map[ $lang_code ] ) ) {
                $membership_entry = $membership_map[ $lang_code ];
                if ( is_object( $membership_entry ) ) {
                    $membership_entry = get_object_vars( $membership_entry );
                }
                if ( is_array( $membership_entry ) ) {
                    $membership_entry_id = 0;
                    if ( isset( $membership_entry['element_id'] ) ) {
                        $membership_entry_id = (int) $membership_entry['element_id'];
                    } elseif ( isset( $membership_entry['translation_id'] ) ) {
                        $membership_entry_id = (int) $membership_entry['translation_id'];
                    }
                    if ( $membership_entry_id > 0 && $membership_entry_id !== $existing ) {
                        wp_send_json_error( [ 'message' => hossam_t( 'This translation does not belong to the requested article.' ), 'code' => 'translation_mismatch' ], 403 );
                    }
                }
            }
        }
        wp_send_json_success( [
            'post_id'  => $existing,
            'edit_url' => add_query_arg( [ 'panel' => 'edit-article', 'post_id' => $existing ], hossam_dashboard_url() ),
            'existing' => true,
            // عقد 4/6 — verified translation data travels with the result.
            'translation' => [
                'trid'                 => $existing_trid,
                'language_code'        => $lang_code,
                'source_language_code' => $existing_source,
            ],
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

    // v1.0.6 §4/§6: the adapter links then reads back (details +
    // translations); the endpoint re-verifies through the same contract —
    // no success before the target-language key carries the new article.
    // The existing-translation branch above (:146-150) is untouched apart
    // from the §14 membership proof and the verified payload, and the
    // §14 named permission check (edit_post + nonce + wpml_language_is_active
    // when registered + no existing translation) already rejected before
    // wp_insert_post with no draft left behind.
    $hossam_link_ok = hossam_wpml_set_element_language( $new_post_id, $trid, $lang_code, $source_lang );
    if ( $hossam_link_ok && function_exists( 'hossam_wpml_verify_element_link' ) ) {
        $hossam_link_ok = hossam_wpml_verify_element_link( $new_post_id, $trid, $lang_code, $source_lang );
    }
    if ( ! $hossam_link_ok ) {
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
        // عقد 4/6 — the link + two-contract read-back above verified these
        // values before any success is reported.
        'translation' => [
            'trid'                 => $trid,
            'language_code'        => $lang_code,
            'source_language_code' => $source_lang,
        ],
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
    foreach ( [ 'title', 'content', 'status', 'excerpt' ] as $field ) {
        if ( isset( $_POST[ $field ] ) ) {
            if ( ! is_string( $_POST[ $field ] ) ) {
                wp_send_json_error( [ 'message' => hossam_t( 'Invalid article data.' ) ], 400 );
            }
            $data[ $field ] = wp_unslash( $_POST[ $field ] );
        }
    }
    if ( isset( $data['excerpt'] ) ) {
        $data['excerpt'] = sanitize_textarea_field( $data['excerpt'] );
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

    $hossam_links = hossam_article_link_payload( $post_id );
    wp_send_json_success( [
        'post_id'     => $post_id,
        'edit_url'    => add_query_arg( [ 'panel' => 'edit-article', 'post_id' => $post_id ], hossam_dashboard_url() ),
        'view_url'    => $hossam_links['view_url'],
        'preview_url' => $hossam_links['preview_url'],
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
    foreach ( [ 'title', 'content', 'status', 'excerpt' ] as $field ) {
        if ( isset( $_POST[ $field ] ) ) {
            if ( ! is_string( $_POST[ $field ] ) ) {
                wp_send_json_error( [ 'message' => hossam_t( 'Invalid article data.' ) ], 400 );
            }
            $data[ $field ] = wp_unslash( $_POST[ $field ] );
        }
    }
    if ( isset( $data['excerpt'] ) ) {
        $data['excerpt'] = sanitize_textarea_field( $data['excerpt'] );
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

    $hossam_links = hossam_article_link_payload( $post_id );
    wp_send_json_success( [
        'post_id'     => $post_id,
        'view_url'    => $hossam_links['view_url'],
        'preview_url' => $hossam_links['preview_url'],
    ] );
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
