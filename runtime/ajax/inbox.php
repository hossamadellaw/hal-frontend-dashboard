<?php
/**
 * runtime/ajax/inbox.php (Batch 6)
 * ══════════════════════════════════════════════════════════════
 * Role: internal notifications + internal mail — six endpoints:
 * get_notifications/mark_read/mark_all_read [SECTION 11] and
 * send_message (rate-limit 10/minute)/get_inbox/mark_message_read [SECTION 12].
 *
 * Source: verbatim 1:1 transfer of all code from legacy mu-plugins/hossam-dashboard/
 * ajax/inbox.php — the source fingerprint is pinned byte-for-byte in the Batch 6
 * inventory (build/source-inventory-batch-6.json, snapshot E48A0A98063529FA19C53E7AD25DDFA2A665C7E4AA795B0E280CADD8841490CA).
 *
 * The only authorized delta (owner decision B3-08, option a): a feature gate
 * in all six, after the nonce and the 401 guard and before any processing —
 * is_feature_enabled('inbox') === false → feature_disabled 403 closed failure;
 * the default (enabled) matches the source behavior verbatim.
 *
 * The carried-over contract as-is: a DB failure (last_error) → 500 with a safe message + error_log,
 * ownership inside WHERE (user_id/receiver_id), the quota via GET_LOCK with
 * the release in finally, its result update false→500 and 0→404, and safe formatting
 * (sanitize/vsprintf with a pattern guard/esc_url_raw) — all verbatim.
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_ajax_hossam_get_notifications', function(): void {
    check_ajax_referer( 'hossam_nonce', 'nonce' );
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );
    }
    if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'inbox' ) ) {
        wp_send_json_error( array( 'code' => 'feature_disabled', 'message' => 'This feature is disabled.' ), 403 );
    }

    global $wpdb;
    $table   = $wpdb->prefix . 'hossam_notifications';
    $user_id = get_current_user_id();

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, type, message, is_read, created_at
             FROM {$table}
             WHERE user_id = %d
             ORDER BY created_at DESC
             LIMIT 50",
            $user_id
        ),
        ARRAY_A
    );
    if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
        error_log( 'Hossam Dashboard inbox notification read failed.' );
        wp_send_json_error( [ 'message' => 'Notifications are unavailable.' ], 500 );
    }

    $templates = [
        'new_post'             => hossam_t( 'New article saved: %s' ),
        'new_booking'          => hossam_t( 'New booking from: %s' ),
        'order_status_changed' => hossam_t( 'Order %s status changed to: %s' ),
    ];
    foreach ( $rows as &$row ) {
        $decoded = json_decode( $row['message'], true );
        if ( is_array( $decoded ) && isset( $decoded['key'], $decoded['args'] ) && is_string( $decoded['key'] ) && is_array( $decoded['args'] ) ) {
            $template = $templates[ $decoded['key'] ] ?? $decoded['key'];
            $args     = array_values( array_map(
                static fn( $value ): string => sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ),
                $decoded['args']
            ) );
            preg_match_all( '/(?<!%)%(?:\d+\$)?[bcdeEfFgGosuxX]/', $template, $matches );
            $row['message_text'] = sanitize_text_field( $decoded['key'] );
            if ( count( $matches[0] ) <= count( $args ) ) {
                try {
                    $row['message_text'] = vsprintf( $template, $args );
                } catch ( Throwable $error ) {
                    // Keep the safe key fallback for a malformed translated format string.
                }
            }
            $row['url'] = esc_url_raw( is_string( $decoded['url'] ?? null ) ? $decoded['url'] : '' );
        } else {
            $row['message_text'] = sanitize_text_field( $row['message'] );
            $row['url']          = '';
        }
    }
    unset( $row );

    wp_send_json_success( $rows );
} );

add_action( 'wp_ajax_hossam_mark_read', function(): void {
    check_ajax_referer( 'hossam_nonce', 'nonce' );
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );
    }
    if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'inbox' ) ) {
        wp_send_json_error( array( 'code' => 'feature_disabled', 'message' => 'This feature is disabled.' ), 403 );
    }

    $notification_id = 0;
    if ( isset( $_POST['notification_id'] ) ) {
        if ( ! is_scalar( $_POST['notification_id'] ) ) {
            wp_send_json_error( [ 'message' => 'Invalid ID' ], 400 );
        }
        $notification_id = absint( $_POST['notification_id'] );
    }
    if ( ! $notification_id ) {
        wp_send_json_error( [ 'message' => 'Invalid ID' ], 400 );
    }

    // R7 (G3 §1.3-11): the gray-zone reads limit 120/min — after all validations and before
    // the write directly (contract §1.1-1). The translation in the existing error_data['status'] pattern.
    $quota = hossam_rate_limit_consume( 'read', (int) get_current_user_id(), 120, MINUTE_IN_SECONDS );
    if ( is_wp_error( $quota ) ) {
        $data = $quota->get_error_data();
        wp_send_json_error(
            [ 'message' => $quota->get_error_message() ],
            is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 503
        );
    }

    global $wpdb;
    $table   = $wpdb->prefix . 'hossam_notifications';
    $user_id = get_current_user_id();

    $updated = $wpdb->update(
        $table,
        [ 'is_read' => 1 ],
        [ 'id' => $notification_id, 'user_id' => $user_id ],
        [ '%d' ],
        [ '%d', '%d' ]
    );

    if ( false === $updated ) {
        wp_send_json_error( [ 'message' => 'Update failed' ], 500 );
    }
    if ( 0 === $updated ) {
        wp_send_json_error( [ 'message' => 'Notification not found or already read' ], 404 );
    }

    wp_send_json_success();
} );

add_action( 'wp_ajax_hossam_mark_all_read', function(): void {
    check_ajax_referer( 'hossam_nonce', 'nonce' );
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );
    }
    if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'inbox' ) ) {
        wp_send_json_error( array( 'code' => 'feature_disabled', 'message' => 'This feature is disabled.' ), 403 );
    }

    // R7 (G3 §1.3-11): the gray-zone reads limit 120/min — after all validations and before
    // the write directly (contract §1.1-1). The translation in the existing error_data['status'] pattern.
    $quota = hossam_rate_limit_consume( 'read', (int) get_current_user_id(), 120, MINUTE_IN_SECONDS );
    if ( is_wp_error( $quota ) ) {
        $data = $quota->get_error_data();
        wp_send_json_error(
            [ 'message' => $quota->get_error_message() ],
            is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 503
        );
    }

    global $wpdb;
    $updated = $wpdb->update(
        $wpdb->prefix . 'hossam_notifications',
        [ 'is_read' => 1                     ],
        [ 'user_id' => get_current_user_id(),
          'is_read' => 0                     ],
        [ '%d' ],
        [ '%d', '%d' ]
    );

    if ( false === $updated ) {
        wp_send_json_error( [ 'message' => 'Update failed' ], 500 );
    }

    wp_send_json_success();
} );

add_action( 'wp_ajax_hossam_send_message', function(): void {
    check_ajax_referer( 'hossam_nonce', 'nonce' );
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );
    }
    if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'inbox' ) ) {
        wp_send_json_error( array( 'code' => 'feature_disabled', 'message' => 'This feature is disabled.' ), 403 );
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
    }

    $receiver_id = 0;
    if ( isset( $_POST['receiver_id'] ) ) {
        if ( ! is_scalar( $_POST['receiver_id'] ) ) {
            wp_send_json_error( [ 'message' => 'Invalid input' ], 400 );
        }
        $receiver_id = absint( $_POST['receiver_id'] );
    }
    $message_raw = $_POST['message'] ?? '';
    if ( ! is_string( $message_raw ) ) {
        wp_send_json_error( [ 'message' => 'Invalid input' ], 400 );
    }
    $message = sanitize_textarea_field( wp_unslash( $message_raw ) );

    if ( ! $receiver_id || '' === $message ) {
        wp_send_json_error( [ 'message' => 'Invalid input' ], 400 );
    }

    if ( ! get_userdata( $receiver_id ) ) {
        wp_send_json_error( [ 'message' => 'Receiver not found' ], 404 );
    }

    $quota = hossam_rate_limit_consume( 'msg', (int) get_current_user_id(), 10, MINUTE_IN_SECONDS );
    if ( is_wp_error( $quota ) ) {
        $data = $quota->get_error_data();
        wp_send_json_error(
            [ 'message' => $quota->get_error_message() ],
            is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 503
        );
    }

    global $wpdb;
    $table     = $wpdb->prefix . 'hossam_messages';
    $sender_id = get_current_user_id();

    $inserted = $wpdb->insert(
        $table,
        [
            'sender_id'   => $sender_id,
            'receiver_id' => $receiver_id,
            'message'     => $message,
            'created_at'  => current_time( 'mysql' ),
        ],
        [ '%d', '%d', '%s', '%s' ]
    );

    if ( ! $inserted ) {
        wp_send_json_error( [ 'message' => 'Insert failed' ], 500 );
    }

    // R7 (G4 §3-a-10): message_send after success and before success — meta
    // receiver_id exclusively (never the message text); the return value is deliberately ignored.
    hossam_audit_log(
        'message_send',
        [
            'object_id' => (int) $wpdb->insert_id,
            'meta'      => [ 'receiver_id' => $receiver_id ],
        ]
    );

    wp_send_json_success( [ 'id' => (int) $wpdb->insert_id ] );
} );

add_action( 'wp_ajax_hossam_get_inbox', function(): void {
    check_ajax_referer( 'hossam_nonce', 'nonce' );
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );
    }
    if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'inbox' ) ) {
        wp_send_json_error( array( 'code' => 'feature_disabled', 'message' => 'This feature is disabled.' ), 403 );
    }

    global $wpdb;
    $table   = $wpdb->prefix . 'hossam_messages';
    $user_id = get_current_user_id();

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, sender_id, message, read_at, created_at
             FROM {$table}
             WHERE receiver_id = %d
             ORDER BY created_at DESC
             LIMIT 50",
            $user_id
        ),
        ARRAY_A
    );
    if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
        error_log( 'Hossam Dashboard inbox message read failed.' );
        wp_send_json_error( [ 'message' => 'Inbox is unavailable.' ], 500 );
    }

    // [H-07] T3-13 — sender_name: fetches the sender's name for every message
    if ( ! empty($rows) ) {
        $ids   = array_unique( array_column( (array) $rows, 'sender_id' ) );
        $users = get_users( [ 'include' => $ids, 'fields' => [ 'ID', 'display_name' ] ] );
        $names = array_column( $users, 'display_name', 'ID' );
        foreach ( $rows as &$row ) {
            $row['sender_name'] = $names[ $row['sender_id'] ] ?? '';
        }
        unset( $row );
    }

    wp_send_json_success( $rows );
} );

add_action( 'wp_ajax_hossam_mark_message_read', function(): void {
    check_ajax_referer( 'hossam_nonce', 'nonce' );
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );
    }
    if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'inbox' ) ) {
        wp_send_json_error( array( 'code' => 'feature_disabled', 'message' => 'This feature is disabled.' ), 403 );
    }

    $message_id = 0;
    if ( isset( $_POST['message_id'] ) ) {
        if ( ! is_scalar( $_POST['message_id'] ) ) {
            wp_send_json_error( [ 'message' => 'Invalid ID' ], 400 );
        }
        $message_id = absint( $_POST['message_id'] );
    }
    if ( ! $message_id ) {
        wp_send_json_error( [ 'message' => 'Invalid ID' ], 400 );
    }

    // R7 (G3 §1.3-11): the gray-zone reads limit 120/min — after all validations and before
    // the write directly (contract §1.1-1). The translation in the existing error_data['status'] pattern.
    $quota = hossam_rate_limit_consume( 'read', (int) get_current_user_id(), 120, MINUTE_IN_SECONDS );
    if ( is_wp_error( $quota ) ) {
        $data = $quota->get_error_data();
        wp_send_json_error(
            [ 'message' => $quota->get_error_message() ],
            is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 503
        );
    }

    global $wpdb;
    $table   = $wpdb->prefix . 'hossam_messages';
    $user_id = get_current_user_id();

    $result = $wpdb->query( $wpdb->prepare(
        "UPDATE {$table}
         SET read_at = %s
         WHERE id = %d AND receiver_id = %d AND read_at IS NULL",
        current_time( 'mysql' ),
        $message_id,
        $user_id
    ) );

    if ( false === $result ) {
        wp_send_json_error( [ 'message' => 'Update failed' ], 500 );
    }
    if ( 0 === $result ) {
        wp_send_json_error( [ 'message' => 'Message not found or already read' ], 404 );
    }

    // R7 (G4 §3-a-11): message_mark_read after success and before success — no
    // meta; the return value is deliberately ignored (G4 §5).
    hossam_audit_log( 'message_mark_read', [ 'object_id' => $message_id ] );

    wp_send_json_success();
} );
