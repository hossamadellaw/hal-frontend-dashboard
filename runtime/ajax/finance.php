<?php
/**
 * runtime/ajax/finance.php (الدفعة 6)
 * ══════════════════════════════════════════════════════════════
 * الدور: نقاط AJAX الأربع لـFinance & Payments عبر WooCommerce الرسمي
 * حصرًا (صفر SQL مباشر): hossam_get_orders [SECTION 13]،
 * hossam_finance_summary [FIN-03، كاش transient 5 دقائق مع epoch
 * hossam_fin_cache_ver]، hossam_get_payment_methods [FIN-06]،
 * hossam_get_saved_tokens [FIN-07، get_last4() فقط — أبدا الرقم الكامل].
 *
 * المصدر: نقل حرفي 1:1 لكل الكود من legacy mu-plugins/hossam-dashboard/
 * ajax/finance.php — بصمة المصدر بالبايت مثبتة في inventory الدفعة 6
 * (build/source-inventory-batch-6.json، snapshot E48A0A98063529FA19C53E7AD25DDFA2A665C7E4AA795B0E280CADD8841490CA).
 *
 * الدلتا المصرَّح بها الوحيدة (قرار المالك B3-08، الخيار أ): بوابة feature
 * في الأربعة، بعد nonce وحرس 401 وقبل أي capability check —
 * is_feature_enabled('finance') === false → feature_disabled 403 فشل مغلق؛
 * الافتراضي (enabled) يطابق سلوك المصدر حرفيًا.
 *
 * العقد المنقول كما هو: scope المدير عبر manage_options/manage_woocommerce
 * (غيره customer scope)، استثناء class_exists('WC_Payment_Tokens') المتعمَّد
 * في المصدر (503 واضحة لا نجاح فارغ)، وقرار التكامل عبر
 * hossam_get_integration_decision('woocommerce') بجانب hossam_wc_is_active()،
 * وفشل قراءة DB (last_error) → 500 برسالة آمنة بدل نجاح فارغ (B6-UNVERIFIED).
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// SECTION 13: FINANCE — Feature #11
// WooCommerce orders AJAX — hossam_get_orders
// ══════════════════════════════════════════════════════════════

add_action( 'wp_ajax_hossam_get_orders', function(): void {
    check_ajax_referer( 'hossam_nonce', 'nonce' );
    if ( ! is_user_logged_in() ) { wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 ); }
    if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'finance' ) ) {
        wp_send_json_error( array( 'code' => 'feature_disabled', 'message' => 'This feature is disabled.' ), 403 );
    }
    $woo_decision = hossam_get_integration_decision( 'woocommerce' );
    if ( 'unavailable' === $woo_decision['status']
        || empty( $woo_decision['capabilities']['orders'] )
        || ! hossam_wc_is_active() ) {
        wp_send_json_error( [ 'message' => hossam_t( 'WooCommerce not active' ) ], 503 );
    }

    $page   = isset( $_POST['page'] ) && is_scalar( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
    $per    = 20;
    $status = isset( $_POST['status'] ) && is_string( $_POST['status'] )
        ? sanitize_key( wp_unslash( $_POST['status'] ) )
        : '';
    $is_mgr = current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' );

    $args = [ 'limit' => $per + 1, 'orderby' => 'date', 'order' => 'DESC', 'offset' => ( $page - 1 ) * $per ];
    if ( $status ) {
        $allowed = array_keys( hossam_wc_get_order_statuses() );
        $clean   = str_starts_with( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
        if ( in_array( 'wc-' . $clean, $allowed, true ) ) $args['status'] = $clean;
    }
    if ( ! $is_mgr ) $args['customer'] = get_current_user_id();

    $all         = hossam_wc_get_orders_data( $args );
    global $wpdb;
    if ( '' !== (string) $wpdb->last_error ) {
        wp_send_json_error( [ 'message' => hossam_t( 'Orders could not be loaded.' ) ], 500 );
    }
    $has_more    = count( $all ) > $per;
    $page_orders = array_slice( $all, 0, $per );

    $data = array_map( function( $o ) use ( $is_mgr ) {
        $row = [
            'id'             => $o->get_id(),
            'date'           => $o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d H:i' ) : '',
            'total'          => wp_strip_all_tags( $o->get_formatted_order_total() ),
            'currency'       => $o->get_currency(),
            'status'         => hossam_wc_get_order_status_name( $o->get_status() ),
            'status_slug'    => $o->get_status(),
            'payment_method' => $o->get_payment_method_title(),
            'item_count'     => $o->get_item_count(),
            'view_url'       => $o->get_view_order_url(),
            'needs_payment'  => $o->needs_payment(),
        ];
        if ( $is_mgr ) {
            $row['customer'] = trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() );
        }
        return $row;
    }, $page_orders );

    wp_send_json_success( [
        'orders'   => $data,
        'statuses' => hossam_wc_get_order_statuses(),
        'page'     => $page,
        'has_more' => $has_more,
    ] );
} );


// ══════════════════════════════════════════════════════════════
// [FIN-03] Endpoint: hossam_finance_summary — KPI مالية + Cache 5 دقائق
// مرتبط: page-dashboard.php (#fin-kpi-grid) + dashboard.js (loadFinanceSummary)
// ══════════════════════════════════════════════════════════════
add_action( 'wp_ajax_hossam_finance_summary', function(): void {
    check_ajax_referer( 'hossam_nonce', 'nonce' );
    if ( ! is_user_logged_in() ) { wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 ); }
    if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'finance' ) ) {
        wp_send_json_error( array( 'code' => 'feature_disabled', 'message' => 'This feature is disabled.' ), 403 );
    }
    $woo_decision = hossam_get_integration_decision( 'woocommerce' );
    if ( 'unavailable' === $woo_decision['status']
        || empty( $woo_decision['capabilities']['orders'] )
        || ! hossam_wc_is_active() ) {
        wp_send_json_error( [ 'message' => hossam_t( 'WooCommerce not active' ) ], 503 );
    }

    $range_map = [ '7days' => '-7 days', '30days' => '-30 days', 'month' => '-1 month', 'year' => '-1 year' ];
    $range = isset( $_POST['range'] ) && is_string( $_POST['range'] )
        ? sanitize_key( wp_unslash( $_POST['range'] ) )
        : '30days';
    if ( ! isset( $range_map[ $range ] ) ) $range = '30days';

    $user_id = get_current_user_id();
    $is_mgr  = current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' );
    $period  = strtotime( $range_map[ $range ] );
    // إبطال عند woocommerce_order_status_changed: epoch يرتفع من
    // hossam_bump_fin_cache_epoch() (core/notifications.php) فيُهمَل الكاش القديم.
    $epoch   = (int) get_option( 'hossam_fin_cache_ver', 0 );
    $key     = 'hossam_fin_summary_' . $epoch . '_' . $user_id . '_' . ( $is_mgr ? 'mgr' : 'own' ) . '_' . $range;

    $kpi = get_transient( $key );
    if ( false === $kpi ) {
        $args = [ 'limit' => 100, 'page' => 1, 'date_created' => '>' . $period ];
        if ( ! $is_mgr ) $args['customer'] = $user_id;

        $first_page    = hossam_wc_get_orders_page( $args );
        global $wpdb;
        if ( '' !== (string) $wpdb->last_error ) {
            wp_send_json_error( [ 'message' => hossam_t( 'Summary could not be loaded.' ) ], 500 );
        }
        $total_orders  = $first_page['total'];
        $total_revenue = 0.0;
        $pending_count = 0;
        $max_pages     = max( 1, $first_page['max_num_pages'] );
        for ( $query_page = 1; $query_page <= $max_pages; $query_page++ ) {
            $page_data = 1 === $query_page
                ? $first_page
                : hossam_wc_get_orders_page( array_merge( $args, [ 'page' => $query_page ] ) );
            if ( 1 !== $query_page && '' !== (string) $wpdb->last_error ) {
                wp_send_json_error( [ 'message' => hossam_t( 'Summary could not be loaded.' ) ], 500 );
            }
            foreach ( $page_data['orders'] as $o ) {
                $total_revenue += (float) $o->get_total();
                if ( $o->has_status( [ 'pending', 'on-hold' ] ) ) $pending_count++;
            }
        }

        $kpi = [
            'total_orders'  => $total_orders,
            'total_revenue' => wp_strip_all_tags( hossam_wc_format_price( $total_revenue ) ),
            'revenue_raw'   => $total_revenue,
            'average_order' => wp_strip_all_tags( hossam_wc_format_price( $total_orders > 0 ? $total_revenue / $total_orders : 0 ) ),
            'pending_count' => $pending_count,
            'currency'      => hossam_wc_get_currency(),
        ];

        set_transient( $key, $kpi, 300 );
    }

    wp_send_json_success( $kpi );
} );


// ══════════════════════════════════════════════════════════════
// [FIN-06] Endpoint: hossam_get_payment_methods
// ══════════════════════════════════════════════════════════════
add_action( 'wp_ajax_hossam_get_payment_methods', function(): void {
    check_ajax_referer( 'hossam_nonce', 'nonce' );
    if ( ! is_user_logged_in() ) { wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 ); }
    if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'finance' ) ) {
        wp_send_json_error( array( 'code' => 'feature_disabled', 'message' => 'This feature is disabled.' ), 403 );
    }
    $woo_decision = hossam_get_integration_decision( 'woocommerce' );
    if ( 'unavailable' === $woo_decision['status']
        || empty( $woo_decision['capabilities']['payment_methods'] )
        || ! hossam_wc_is_active() ) {
        wp_send_json_error( [ 'message' => hossam_t( 'WooCommerce not active' ) ], 503 );
    }

    $gws = array_filter(
        hossam_wc_get_registered_payment_gateways(),
        static fn( $gateway ): bool => is_object( $gateway )
            && isset( $gateway->enabled )
            && 'yes' === $gateway->enabled
    );
    global $wpdb;
    if ( '' !== (string) $wpdb->last_error ) {
        wp_send_json_error( [ 'message' => hossam_t( 'Payment methods could not be loaded.' ) ], 500 );
    }

    wp_send_json_success( array_values( array_map( fn( $g ) => [
        'id'      => $g->id,
        'title'   => $g->get_title(),
        'enabled' => true,
    ], $gws ) ) );
} );


// ══════════════════════════════════════════════════════════════
// [FIN-07] Endpoint: hossam_get_saved_tokens — آخر 4 أرقام فقط عبر get_last4()، أبداً الرقم الكامل
// ══════════════════════════════════════════════════════════════
add_action( 'wp_ajax_hossam_get_saved_tokens', function(): void {
    check_ajax_referer( 'hossam_nonce', 'nonce' );
    if ( ! is_user_logged_in() ) { wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 ); }
    if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'finance' ) ) {
        wp_send_json_error( array( 'code' => 'feature_disabled', 'message' => 'This feature is disabled.' ), 403 );
    }
    $woo_decision = hossam_get_integration_decision( 'woocommerce' );
    if ( 'unavailable' === $woo_decision['status']
        || empty( $woo_decision['capabilities']['tokens'] )
        || ! hossam_wc_is_active() ) {
        wp_send_json_error( [ 'message' => hossam_t( 'WooCommerce not active' ) ], 503 );
    }

    $uid    = get_current_user_id();
    $tokens = hossam_wc_get_customer_tokens( $uid );
    global $wpdb;
    if ( '' !== (string) $wpdb->last_error ) {
        wp_send_json_error( [ 'message' => hossam_t( 'Saved payment tokens could not be loaded.' ) ], 500 );
    }
    $data   = [];

    foreach ( $tokens as $token ) {
        if ( ! is_object( $token ) || ! method_exists( $token, 'get_last4' ) ) continue;
        $last4 = preg_replace( '/\D+/', '', (string) $token->get_last4() );
        if ( 4 !== strlen( $last4 ) ) continue;
        $data[] = [ 'last4' => $last4 ];
    }

    wp_send_json_success( $data );
} );
