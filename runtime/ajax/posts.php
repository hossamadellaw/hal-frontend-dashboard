<?php
/**
 * runtime/ajax/posts.php (Batch 5)
 * ══════════════════════════════════════════════════════════════
 * Role: every AJAX endpoint for articles — five endpoints, all wp_ajax_*
 * without nopriv: restore/translate/create/update/trash. A translation layer
 * only: unified nonce + 401 guard + conservative input-shape validation +
 * WP_Error to HTTP-status translation; the article logic is owned by
 * adapters/wordpress-posts.php and the WPML contract is owned by
 * adapters/wpml.php (Batch 4) — exactly as the source header states verbatim.
 *
 * Source: verbatim 1:1 transfer of all code from legacy mu-plugins/hossam-dashboard/
 * ajax/posts.php — the source fingerprint is pinned byte-for-byte in the Batch 5
 * inventory (build/source-inventory-batch-5.json). Zero changes to the contracts.
 *
 * The carried-over contract as-is (summarized from the source header), with the
 * B3-08 gate
 * (architecture §7.6 — the B3-08 decision is consumed in AJAX):
 *   - check_ajax_referer('hossam_nonce', 'nonce') first line in every callback,
 *     then is_user_logged_in() → 401 'Unauthorized', then the feature gate
 *     (posts/wpml_translations) → 403 feature_disabled before any operation;
 *     nonce, capabilities and ownership are independent and remain as they are.
 *   - restore/trash: hossam_not_found→404 and hossam_forbidden→403
 *     and any other code→400; and a non-error failure (true !== $result) → 500.
 *   - create/update: input-shape validation here (strings / categories array of
 *     positive integers / scalar thumbnail) → 400, then wp_unslash and passing $data
 *     to the adapter that owns the actual sanitization — no duplicated
 *     sanitization across two layers.
 *   - Adapter errors via hossam_send_article_error():
 *     hossam_not_found→404; hossam_forbidden/hossam_thumbnail_forbidden→403;
 *     hossam_invalid_categories/hossam_invalid_thumbnail→400; otherwise→500;
 *     the payload is message+code and only scalar context fields from
 *     post_id/stage/cleanup/compensated.
 *   - translate: the integration decision from the registry hossam_get_integration_decision('wpml')
 *     beside the direct guard → 503 on unavailable or missing languages;
 *     then 404/403/400 per the source; edit_url via hossam_dashboard_url().
 *
 * ═══ v1.1.0 merge (rows R8+E6 flat with two tagged modifications — no structural merge) ═══
 *   - E6: the rejection code hossam_h1_rejected (row R14 — the valve) is added to the
 *     400 branch in hossam_send_article_error (by in_array extension) — otherwise any
 *     new code falls into else→500 and an input policy rejection is shown as a technical failure.
 *   - R8 (a): consume per the G3 §1.3 map — update 30/min · create 10/min +
 *     50/day (create_day) · translate 10/min · trash+restore 30/min (shared
 *     trash scope) — 5 endpoints with 6 calls, after validation completes and
 *     directly before the write with no rollback; WP_Error translation in the inbox.php pattern (error_data['status']).
 *   - R8 (b): the five audit calls after success and before success —
 *     article_create/article_update/article_trash/article_restore/
 *     article_translate (the new-create branch exclusively; the «existing» branch is a read with no
 *     event) at position G4 §3-a; the return value is deliberately ignored.
 *   - R8 delta: (1) meta article_create gains lang (scalar non-PII) beside
 *     status — as actually read from the article (H4 §1.5-A4); (2) an additional non-breaking
 *     language field in create success; (3) the unified 404 in the translate branch
 *     (H3 §1.3): merging the standalone 403 gate into the 404 gate with the same
 *     missing-message — the internal translation-ownership branch and the higher 403 feature_disabled
 *     unchanged. The response/nonce/capability contracts unchanged.
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
        } elseif ( in_array( $code, [ 'hossam_invalid_categories', 'hossam_invalid_thumbnail', 'hossam_h1_rejected' ], true ) ) {
            // Row E6 (v1.1.0): the valve rejection code hossam_h1_rejected (row R14)
            // is added to the 400 branch by extension — otherwise it falls into
            // else→500 and an input policy rejection is shown as a technical failure.
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
// Second batch (2026-08-24): the logic moved to hossam_restore_article()
// in adapters/wordpress-posts.php (edit_post check at the article level via
// map_meta_cap) — this endpoint only translates per clause 3 verbatim:
// «adapters/wordpress-posts.php owns the logic; ajax/posts.php only translates».
add_action( 'wp_ajax_hossam_restore_article', function(): void {
    check_ajax_referer( 'hossam_nonce', 'nonce' );
    if ( ! is_user_logged_in() ) { wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 ); }
    if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'posts' ) ) {
        wp_send_json_error( [ 'code' => 'feature_disabled', 'message' => 'This feature is disabled.' ], 403 );
    }

    $post_id = isset( $_POST['post_id'] ) && is_scalar( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

    // Row R8 (G3 §1.3-5): consume restore 30/min on the shared trash scope — after
    // validation completes (nonce/401/feature/input shape) and directly before the write,
    // with no rollback on later write failure. No manage_options exemption.
    $hossam_rl = hossam_rate_limit_consume( 'trash', (int) get_current_user_id(), 30, MINUTE_IN_SECONDS );
    if ( is_wp_error( $hossam_rl ) ) {
        $hossam_rl_data = $hossam_rl->get_error_data();
        wp_send_json_error(
            [ 'message' => $hossam_rl->get_error_message() ],
            is_array( $hossam_rl_data ) && isset( $hossam_rl_data['status'] ) ? (int) $hossam_rl_data['status'] : 503
        );
    }

    $result = hossam_restore_article( $post_id );
    if ( is_wp_error( $result ) ) {
        $code   = $result->get_error_code();
        $status = 'hossam_not_found' === $code ? 404 : ( 'hossam_forbidden' === $code ? 403 : 400 );
        wp_send_json_error( [ 'message' => $result->get_error_message() ], $status );
    }
    if ( true !== $result ) {
        wp_send_json_error( [ 'message' => hossam_t( 'Article could not be restored.' ) ], 500 );
    }

    // Row R8 (G4 §3-a-4): article_restore after success and before success — no
    // meta; the return value is deliberately ignored and the call does not change the response path.
    hossam_audit_log( 'article_restore', [ 'object_id' => $post_id ] );

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
    // Preliminary clause 3 (second batch): the integration decision from the capabilities registry beside the direct guard.
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

    // Row R8 delta 3 (H3 §1.3 — unified 404 in the translate branch): one gate
    // swallows existence into the decision — missing, odd type, and not-owned are
    // all 404 with the same missing-message hossam_t('Article not found.'); the short-circuit
    // guarantees current_user_can is called only on an existing article of the
    // correct type. The internal translation-ownership branch below and the higher
    // 403 feature_disabled unchanged.
    if ( ! $post || 'post' !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
        wp_send_json_error( [ 'message' => hossam_t( 'Article not found.' ) ], 404 );
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
        // v1.0.6 §14 + contract 6.4 — membership and language are verified on
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
            // contract 4/6 — verified translation data travels with the result.
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

    // Row R8 (G3 §1.3-4): consume translate 10/min — after all validation completes
    // (nonce/401/feature/503/input shape/source ownership/active language/trid
    // check) and directly before the write (wp_insert_post), with no rollback. The
    // «existing» branch above is a read so no consume there.
    $hossam_rl = hossam_rate_limit_consume( 'translate', (int) get_current_user_id(), 10, MINUTE_IN_SECONDS );
    if ( is_wp_error( $hossam_rl ) ) {
        $hossam_rl_data = $hossam_rl->get_error_data();
        wp_send_json_error(
            [ 'message' => $hossam_rl->get_error_message() ],
            is_array( $hossam_rl_data ) && isset( $hossam_rl_data['status'] ) ? (int) $hossam_rl_data['status'] : 503
        );
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

    // Row R8 (G4 §3-a-5): article_translate after success and before success —
    // object_id=source id, meta new_post_id + lang = the target translation language
    // $lang_code; the new-create branch exclusively (the «existing» branch above is a read with no
    // event and no consume); the return value is deliberately ignored and the call does not change the response
    // path.
    hossam_audit_log( 'article_translate', [
        'object_id' => $post_id,
        'meta'      => [ 'new_post_id' => (int) $new_post_id, 'lang' => $lang_code ],
    ] );

    wp_send_json_success( [
        'post_id'  => $new_post_id,
        'edit_url' => add_query_arg( [ 'panel' => 'edit-article', 'post_id' => $new_post_id ], hossam_dashboard_url() ),
        'existing' => false,
        // contract 4/6 — the link + two-contract read-back above verified these
        // values before any success is reported.
        'translation' => [
            'trid'                 => $trid,
            'language_code'        => $lang_code,
            'source_language_code' => $source_lang,
        ],
    ] );
} );


// ══════════════════════════════════════════════════════════════════
// T-33 — Endpoint: hossam_create_article → hossam_create_article() in adapters/wordpress-posts.php
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

    // Row R8 (G3 §1.3-2/3): consume create 10/min + create_day 50/day — after
    // validation completes (nonce/401/feature/input shape) and directly before
    // the write (hossam_create_article), with no rollback on later write failure.
    // No manage_options exemption.
    $hossam_rl = hossam_rate_limit_consume( 'create', (int) get_current_user_id(), 10, MINUTE_IN_SECONDS );
    if ( is_wp_error( $hossam_rl ) ) {
        $hossam_rl_data = $hossam_rl->get_error_data();
        wp_send_json_error(
            [ 'message' => $hossam_rl->get_error_message() ],
            is_array( $hossam_rl_data ) && isset( $hossam_rl_data['status'] ) ? (int) $hossam_rl_data['status'] : 503
        );
    }
    $hossam_rl = hossam_rate_limit_consume( 'create_day', (int) get_current_user_id(), 50, DAY_IN_SECONDS );
    if ( is_wp_error( $hossam_rl ) ) {
        $hossam_rl_data = $hossam_rl->get_error_data();
        wp_send_json_error(
            [ 'message' => $hossam_rl->get_error_message() ],
            is_array( $hossam_rl_data ) && isset( $hossam_rl_data['status'] ) ? (int) $hossam_rl_data['status'] : 503
        );
    }

    $post_id = hossam_create_article( $data );
    if ( is_wp_error( $post_id ) ) {
        hossam_send_article_error( $post_id );
    }

    // Row R8 delta 1 (A4 — H4 §1.5-A4): lang as actually read from the article after
    // creation (may be '') — the same value as the language field in the success below.
    $hossam_lang = function_exists( 'hossam_wpml_get_post_language' ) ? (string) hossam_wpml_get_post_language( $post_id ) : '';

    // Row R8 (G4 §3-a-1 + delta 1): article_create after success and before success —
    // object_id=new id, meta status = the resulting article status + lang as
    // actually read; the return value is deliberately ignored and the call does not change the response
    // path. No «existing» branch exists here (creation is always new).
    // The status read is protected by the hossam_article_link_payload pattern above.
    $hossam_status = function_exists( 'get_post_status' ) ? (string) get_post_status( $post_id ) : '';
    if ( '' === $hossam_status ) {
        $hossam_post_obj = get_post( $post_id );
        $hossam_status   = ( $hossam_post_obj && isset( $hossam_post_obj->post_status ) ) ? (string) $hossam_post_obj->post_status : '';
    }
    hossam_audit_log( 'article_create', [
        'object_id' => $post_id,
        'meta'      => [ 'status' => $hossam_status, 'lang' => $hossam_lang ],
    ] );

    $hossam_links = hossam_article_link_payload( $post_id );
    // Row R38 (OEB/4 — H1 §6-6): oeb_url is a response field only in the success reply —
    // no new endpoint. Conditioned on the effective mode official (its source
    // get_effective_editor_mode() exclusively) × the unified predicate
    // current_user_can('manage_options'); its value is the official editor link
    // (post.php?post={ID}&action=edit) for the created field. When the condition is not met
    // the field is absent (posts.js ignores the unknown) and the existing response contracts
    // unchanged.
    $hossam_success = [
        'post_id'     => $post_id,
        'edit_url'    => add_query_arg( [ 'panel' => 'edit-article', 'post_id' => $post_id ], hossam_dashboard_url() ),
        'view_url'    => $hossam_links['view_url'],
        'preview_url' => $hossam_links['preview_url'],
        // R8 delta 2: an additional non-breaking language field — posts.js ignores unknown
        // keys (zero JS touch).
        'language'    => function_exists('hossam_wpml_get_post_language') ? hossam_wpml_get_post_language( $post_id ) : '',
    ];
    if ( 'official' === HAL_Frontend_Dashboard_Settings_Repository::get_effective_editor_mode()
        && current_user_can( 'manage_options' ) ) {
        $hossam_success['oeb_url'] = esc_url( admin_url( 'post.php?post=' . $post_id . '&action=edit' ) );
    }
    wp_send_json_success( $hossam_success );
} );


// ════════════════════════════════════════════════════════════
// T-33 — Endpoint: hossam_update_article → hossam_update_article() in adapters/wordpress-posts.php
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

    // Row R8 (G3 §1.3-1): consume update 30/min — after validation completes (nonce/
    // 401/feature/input shape) and directly before the write (hossam_update_article),
    // with no rollback on later write failure. No manage_options exemption.
    $hossam_rl = hossam_rate_limit_consume( 'update', (int) get_current_user_id(), 30, MINUTE_IN_SECONDS );
    if ( is_wp_error( $hossam_rl ) ) {
        $hossam_rl_data = $hossam_rl->get_error_data();
        wp_send_json_error(
            [ 'message' => $hossam_rl->get_error_message() ],
            is_array( $hossam_rl_data ) && isset( $hossam_rl_data['status'] ) ? (int) $hossam_rl_data['status'] : 503
        );
    }

    $result = hossam_update_article( $post_id, $data );
    if ( is_wp_error( $result ) ) {
        hossam_send_article_error( $result );
    }

    // Row R8 (G4 §3-a-2): article_update after success and before success —
    // object_id=article id, meta status = the resulting article status; the return
    // value is deliberately ignored and the call does not change the response path. The status
    // read is protected by the hossam_article_link_payload pattern above.
    $hossam_status = function_exists( 'get_post_status' ) ? (string) get_post_status( $post_id ) : '';
    if ( '' === $hossam_status ) {
        $hossam_post_obj = get_post( $post_id );
        $hossam_status   = ( $hossam_post_obj && isset( $hossam_post_obj->post_status ) ) ? (string) $hossam_post_obj->post_status : '';
    }
    hossam_audit_log( 'article_update', [
        'object_id' => $post_id,
        'meta'      => [ 'status' => $hossam_status ],
    ] );

    $hossam_links = hossam_article_link_payload( $post_id );
    // Row R38 (OEB/4 — H1 §6-6): oeb_url is a response field only — same condition
    // as create: effective mode official (from get_effective_editor_mode()
    // exclusively) × manage_options; its value is the official editor link for the
    // updated article; otherwise the field is absent — existing response contracts unchanged.
    $hossam_success = [
        'post_id'     => $post_id,
        'view_url'    => $hossam_links['view_url'],
        'preview_url' => $hossam_links['preview_url'],
    ];
    if ( 'official' === HAL_Frontend_Dashboard_Settings_Repository::get_effective_editor_mode()
        && current_user_can( 'manage_options' ) ) {
        $hossam_success['oeb_url'] = esc_url( admin_url( 'post.php?post=' . $post_id . '&action=edit' ) );
    }
    wp_send_json_success( $hossam_success );
} );


// ════════════════════════════════════════════════════════════
// T-33 — Endpoint: hossam_trash_article → hossam_trash_article() in adapters/wordpress-posts.php
// ════════════════════════════════════════════════════════════
add_action( 'wp_ajax_hossam_trash_article', function(): void {
    check_ajax_referer( 'hossam_nonce', 'nonce' );
    if ( ! is_user_logged_in() ) { wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 ); }
    if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'posts' ) ) {
        wp_send_json_error( [ 'code' => 'feature_disabled', 'message' => 'This feature is disabled.' ], 403 );
    }

    $post_id = isset( $_POST['post_id'] ) && is_scalar( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

    // Row R8 (G3 §1.3-5): consume trash 30/min on the scope shared with restore —
    // after validation completes (nonce/401/feature/input shape) and directly before
    // the write, with no rollback on later write failure. No manage_options exemption.
    $hossam_rl = hossam_rate_limit_consume( 'trash', (int) get_current_user_id(), 30, MINUTE_IN_SECONDS );
    if ( is_wp_error( $hossam_rl ) ) {
        $hossam_rl_data = $hossam_rl->get_error_data();
        wp_send_json_error(
            [ 'message' => $hossam_rl->get_error_message() ],
            is_array( $hossam_rl_data ) && isset( $hossam_rl_data['status'] ) ? (int) $hossam_rl_data['status'] : 503
        );
    }

    $result = hossam_trash_article( $post_id );
    if ( is_wp_error( $result ) ) {
        $code   = $result->get_error_code();
        $status = 'hossam_not_found' === $code ? 404 : ( 'hossam_forbidden' === $code ? 403 : 400 );
        wp_send_json_error( [ 'message' => $result->get_error_message() ], $status );
    }
    if ( true !== $result ) {
        wp_send_json_error( [ 'message' => hossam_t( 'Article could not be moved to trash.' ) ], 500 );
    }

    // Row R8 (G4 §3-a-3): article_trash after success and before success — no
    // meta; the return value is deliberately ignored and the call does not change the response path.
    hossam_audit_log( 'article_trash', [ 'object_id' => $post_id ] );

    wp_send_json_success();
} );
