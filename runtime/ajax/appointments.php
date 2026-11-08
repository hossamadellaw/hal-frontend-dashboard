<?php
/**
 * runtime/ajax/appointments.php (Batch 6)
 * ══════════════════════════════════════════════════════════════
 * Role: the two AJAX endpoints for appointments (Amelia): hossam_get_upcoming_appointments
 * (nonce: hossam_nonce) and hossam_lazy_appointments (a separate nonce:
 * hossam_lazy_appointments). The only documented exception in the source remains as it is:
 * the first endpoint is called from a <script> inlined in the template, not from a JS module.
 *
 * Source: verbatim 1:1 transfer of all code from legacy mu-plugins/hossam-dashboard/
 * ajax/appointments.php — the source fingerprint is pinned byte-for-byte in the Batch 6
 * inventory (build/source-inventory-batch-6.json, snapshot E48A0A98063529FA19C53E7AD25DDFA2A665C7E4AA795B0E280CADD8841490CA).
 *
 * The only authorized delta (owner decision B3-08, option a — binding the consumption
 * points to their contracts at migration): a feature gate in both endpoints,
 * after the nonce and the 401 guard and before any capability check:
 *   HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled('amelia')
 *   === false → wp_send_json_error( feature_disabled, 403 ) closed failure.
 *   The absence/the default (enabled) matches the source behavior verbatim — the original source
 *   has no feature toggles at all so no existing behavior is removed.
 *
 * The carried-over contract as-is (a summary of the source header): 401/403/503 (plugin_missing/
 * not_configured/feature_unavailable — the integration state, independent of the owner
 * gate) and 500 db_error via adapters/amelia.php; zero direct SQL here.
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ══════════════════════════════════════════════════════════════
// [H-06] T3-03 — Endpoint: hossam_get_upcoming_appointments + transient cache
// Evidence: raw Amelia SQL in page-dashboard.php without a cache
// Used in: page-dashboard.php [P-07]
// ══════════════════════════════════════════════════════════════
add_action( 'wp_ajax_hossam_get_upcoming_appointments', function(): void {
    check_ajax_referer( 'hossam_nonce', 'nonce' );
    if ( ! is_user_logged_in() ) { wp_send_json_error( ['code' => 'unauthorized'], 401 ); }
    if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'amelia' ) ) {
        wp_send_json_error( array( 'code' => 'feature_disabled', 'message' => 'This feature is disabled.' ), 403 );
    }

    $is_mgr = current_user_can( 'manage_options' ) || current_user_can( 'view_amelia_calendar_all' );
    $is_emp = ! $is_mgr && current_user_can( 'view_amelia_calendar' );
    if ( ! $is_mgr && ! $is_emp ) {
        wp_send_json_error( [ 'code' => 'forbidden' ], 403 );
    }

    $amelia_decision = hossam_get_integration_decision( 'amelia' );
    if ( 'unavailable' === $amelia_decision['status'] || ! hossam_amelia_is_active() ) { wp_send_json_error( ['code' => 'plugin_missing'], 503 ); }
    if ( ! hossam_amelia_table_exists( 'amelia_appointments' )
        || ! hossam_amelia_table_exists( 'amelia_employees' )
        || ! hossam_amelia_table_exists( 'amelia_services' ) ) {
        wp_send_json_error( ['code' => 'not_configured'], 503 );
    }

    $uid    = get_current_user_id();
    $result = hossam_amelia_get_upcoming_appointments( $uid, $is_mgr );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [ 'code' => 'db_error' ], 500 );
    }

    wp_send_json_success( $result );
} );


// ══════════════════════════════════════════════════════════════
// [H-14] PERF-02 — Lazy-load Appointments Panel (Amelia)
// a separate nonce 'hossam_lazy_appointments' — actually generated in page-dashboard.php
// ══════════════════════════════════════════════════════════════
add_action( 'wp_ajax_hossam_lazy_appointments', function(): void {
    check_ajax_referer( 'hossam_lazy_appointments', 'nonce' );
    if ( ! is_user_logged_in() ) { wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 ); }
    if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'amelia' ) ) {
        wp_send_json_error( array( 'code' => 'feature_disabled', 'message' => 'This feature is disabled.' ), 403 );
    }

    $user_id       = get_current_user_id();
    $is_admin      = current_user_can( 'manage_options' );
    $is_amelia_mgr = current_user_can( 'view_amelia_calendar_all' );
    $is_amelia_emp = ! $is_amelia_mgr && current_user_can( 'view_amelia_calendar' );
    $sees_calendar = $is_amelia_mgr || $is_amelia_emp || $is_admin;

    if ( ! $sees_calendar ) { wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 ); }

    $amelia_decision = hossam_get_integration_decision( 'amelia' );
    if ( 'unavailable' === $amelia_decision['status'] || ! hossam_amelia_is_active() ) {
        wp_send_json_error( [ 'code' => 'plugin_missing', 'message' => hossam_t( 'Amelia plugin not active.' ) ], 503 );
    }
    if ( empty( $amelia_decision['capabilities']['bookings_shortcode'] )
        || ! shortcode_exists( 'ameliaemployeepanel' ) ) {
        wp_send_json_error( [ 'code' => 'feature_unavailable', 'message' => hossam_t( 'Appointment module not configured.' ) ], 503 );
    }

    ob_start();

    $linked = $is_amelia_emp ? hossam_amelia_get_linked_employee_id( $user_id ) : null;
    if ( is_wp_error( $linked ) ) {
        wp_send_json_error( [ 'message' => $linked->get_error_message() ], 500 );
    }

    if ( $is_amelia_emp && ! $linked ) {
        echo '<div class="info-card" style="color:var(--warn)">' . esc_html( hossam_t( 'Please contact the administrator to link your account.' ) ) . '</div>';
    }

    if ( $is_admin || $is_amelia_mgr ) {
        echo do_shortcode( '[ameliaemployeepanel appointments=1 events=1 profile-hidden=1]' );
    } elseif ( $is_amelia_emp ) {
        if ( $linked ) {
            echo do_shortcode( '[ameliaemployeepanel appointments=1 profile-hidden=1]' );
        }
    }

    wp_send_json_success( [ 'html' => ob_get_clean() ] );
} );
