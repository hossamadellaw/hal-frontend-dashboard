<?php
/**
 * runtime/ajax/inbox.php (الدفعة 6)
 * ══════════════════════════════════════════════════════════════
 * الدور: الإشعارات الداخلية + البريد الداخلي — ستة endpoints:
 * get_notifications/mark_read/mark_all_read [SECTION 11] و
 * send_message (rate-limit 10/دقيقة)/get_inbox/mark_message_read [SECTION 12].
 *
 * المصدر: نقل حرفي 1:1 لكل الكود من legacy mu-plugins/hossam-dashboard/
 * ajax/inbox.php — بصمة المصدر بالبايت مثبتة في inventory الدفعة 6
 * (build/source-inventory-batch-6.json، snapshot E48A0A98063529FA19C53E7AD25DDFA2A665C7E4AA795B0E280CADD8841490CA).
 *
 * الدلتا المصرَّح بها الوحيدة (قرار المالك B3-08، الخيار أ): بوابة feature
 * في الستة، بعد nonce وحرس 401 وقبل أي معالجة —
 * is_feature_enabled('inbox') === false → feature_disabled 403 فشل مغلق؛
 * الافتراضي (enabled) يطابق سلوك المصدر حرفيًا.
 *
 * العقد المنقول كما هو: فشل DB (last_error) → 500 برسالة آمنة + error_log،
 * الملكية داخل WHERE (user_id/receiver_id)، quota عبر GET_LOCK مع
 * release في finally، تحديث نتيجته false→500 و0→404، وتنسيق آمن
 * (sanitize/vsprintf بحارس pattern/esc_url_raw) — كلها حرفية.
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Consume one message allowance while holding a database-level lock.
 *
 * WordPress transients are not an atomic read/modify/write primitive. The
 * named lock serializes concurrent requests for the same user so the
 * documented ten-messages-per-minute ceiling cannot be bypassed by racing.
 */
function hossam_inbox_consume_message_quota( int $user_id ) {
    global $wpdb;

    $lock_name = 'hossam_msg_rate_' . $user_id;
    $locked    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 2)', $lock_name ) );
    if ( 1 !== $locked ) {
        return new WP_Error( 'quota_lock_failed', 'Message service is busy. Try again.', [ 'status' => 503 ] );
    }

    try {
        $key = 'hossam_msg_rate_' . $user_id;
        $cnt = (int) get_transient( $key );
        if ( $cnt >= 10 ) {
            return new WP_Error( 'rate_limit', 'Rate limit exceeded. Try later.', [ 'status' => 429 ] );
        }

        if ( ! set_transient( $key, $cnt + 1, MINUTE_IN_SECONDS ) ) {
            return new WP_Error( 'quota_write_failed', 'Message service is unavailable. Try again.', [ 'status' => 503 ] );
        }

        return true;
    } finally {
        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
    }
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

    $quota = hossam_inbox_consume_message_quota( get_current_user_id() );
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

    // [H-07] T3-13 — sender_name: تجلب اسم المُرسِل لكل رسالة
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

    wp_send_json_success();
} );
