<?php
/**
 * runtime/ajax/appointments.php (الدفعة 6)
 * ══════════════════════════════════════════════════════════════
 * الدور: نقطتا AJAX للمواعيد (Amelia): hossam_get_upcoming_appointments
 * (nonce: hossam_nonce) وhossam_lazy_appointments (nonce منفصل:
 * hossam_lazy_appointments). الاستثناء الوحيد الموثق في المصدر يبقى كما هو:
 * أول endpoint يُستدعى من <script> مضمَّن في القالب لا من JS module.
 *
 * المصدر: نقل حرفي 1:1 لكل الكود من legacy mu-plugins/hossam-dashboard/
 * ajax/appointments.php — بصمة المصدر بالبايت مثبتة في inventory الدفعة 6
 * (build/source-inventory-batch-6.json، snapshot E48A0A98063529FA19C53E7AD25DDFA2A665C7E4AA795B0E280CADD8841490CA).
 *
 * الدلتا المصرَّح بها الوحيدة (قرار المالك B3-08، الخيار أ — ربط نقاط
 * الاستهلاك بعقدها عند الترحيل): بوابة feature في كلا الـendpoints،
 * بعد nonce وحرس 401 وقبل أي capability check:
 *   HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled('amelia')
 *   === false → wp_send_json_error( feature_disabled, 403 ) فشل مغلق.
 *   الغياب/الافتراضي (enabled) يطابق سلوك المصدر حرفيًا — المصدر الأصلي
 *   بلا feature toggles إطلاقًا فلا يُزال أي سلوك قائم.
 *
 * العقد المنقول كما هو (ملخص رأس المصدر): 401/403/503 (plugin_missing/
 * not_configured/feature_unavailable — حالة التكامل، مستقلة عن بوابة
 * المالك) و500 db_error عبر adapters/amelia.php؛ صفر SQL مباشر هنا.
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ══════════════════════════════════════════════════════════════
// [H-06] T3-03 — Endpoint: hossam_get_upcoming_appointments + transient cache
// الدليل: Amelia SQL خام في page-dashboard.php بدون كاش
// يُستخدَم في: page-dashboard.php [P-07]
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
// nonce منفصل 'hossam_lazy_appointments' — مُولَّد فعلاً فى page-dashboard.php
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
