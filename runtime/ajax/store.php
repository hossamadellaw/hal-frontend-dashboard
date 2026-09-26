<?php
/**
 * runtime/ajax/store.php (الدفعة 6)
 * ══════════════════════════════════════════════════════════════
 * الدور: نقطة AJAX واحدة — منتجات WooCommerce hossam_get_products
 * [SECTION 15]. تبويب Orders في بانل Store يعيد استخدام hossam_get_orders
 * من ajax/finance.php حرفيًا (قرار المصدر الموثق — لا endpoint جديد).
 *
 * المصدر: نقل حرفي 1:1 لكل الكود من legacy mu-plugins/hossam-dashboard/
 * ajax/store.php — بصمة المصدر بالبايت مثبتة في inventory الدفعة 6
 * (build/source-inventory-batch-6.json، snapshot E48A0A98063529FA19C53E7AD25DDFA2A665C7E4AA795B0E280CADD8841490CA).
 *
 * الدلتا المصرَّح بها الوحيدة (قرار المالك B3-08، الخيار أ): بوابة feature
 * بعد nonce وحرس 401 وقبل أي capability check —
 * is_feature_enabled('store') === false → feature_disabled 403 فشل مغلق؛
 * الافتراضي (enabled) يطابق سلوك المصدر حرفيًا.
 *
 * العقد المنقول كما هو: 403 edit_products لغير المصرح، scope الملكية
 * عبر edit_others_products (غيره: منتجاته فقط عبر get_posts author)،
 * 503 عبر قرار التكامل woocommerce + hossam_wc_is_active()، pagination
 * (per=20 و+1 lookahead وhas_more) — كلها حرفية، وفشل قراءة DB
 * (last_error) → 500 برسالة آمنة بدل نجاح فارغ (B6-UNVERIFIED).
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ══════════════════════════════════════════════════════════════
// SECTION 15: STORE — Feature #13
// ══════════════════════════════════════════════════════════════
// [WC-endpoint] Endpoint: hossam_get_products — تبويب Orders يُعيد استخدام hossam_get_orders حرفياً (لا endpoint جديد له)
add_action( 'wp_ajax_hossam_get_products', function(): void {
    check_ajax_referer( 'hossam_nonce', 'nonce' );
    if ( ! is_user_logged_in() ) { wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 ); }
    if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'store' ) ) {
        wp_send_json_error( array( 'code' => 'feature_disabled', 'message' => 'This feature is disabled.' ), 403 );
    }
    if ( ! current_user_can( 'edit_products' ) ) {
        wp_send_json_error( [ 'message' => hossam_t( 'You do not have permission to view products.' ) ], 403 );
    }
    $woo_decision = hossam_get_integration_decision( 'woocommerce' );
    if ( 'unavailable' === $woo_decision['status']
        || empty( $woo_decision['capabilities']['products'] )
        || ! hossam_wc_is_active() ) {
        wp_send_json_error( [ 'message' => hossam_t( 'WooCommerce not active' ) ], 503 );
    }

    $page    = isset( $_POST['page'] ) && is_scalar( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
    $per     = 20;
    $user_id = get_current_user_id();
    $can_edit_all = current_user_can( 'edit_others_products' );

    if ( $can_edit_all ) {
        $all = hossam_wc_get_products_data( [
            'limit'   => $per + 1,
            'offset'  => ( $page - 1 ) * $per,
            'orderby' => 'date',
            'order'   => 'DESC',
        ] );
        global $wpdb;
        if ( '' !== (string) $wpdb->last_error ) {
            wp_send_json_error( [ 'message' => hossam_t( 'Products could not be loaded.' ) ], 500 );
        }
        $has_more = count( $all ) > $per;
        $products = array_slice( $all, 0, $per );
    } else {
        $owned_ids = get_posts( [
            'post_type'      => 'product',
            'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
            'author'         => $user_id,
            'fields'         => 'ids',
            'posts_per_page' => $per + 1,
            'offset'         => ( $page - 1 ) * $per,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ] );
        global $wpdb;
        if ( '' !== (string) $wpdb->last_error ) {
            wp_send_json_error( [ 'message' => hossam_t( 'Products could not be loaded.' ) ], 500 );
        }
        $has_more = count( $owned_ids ) > $per;
        $owned_ids = array_slice( array_map( 'absint', $owned_ids ), 0, $per );
        $products = $owned_ids
            ? hossam_wc_get_products_data( [ 'include' => $owned_ids, 'limit' => $per, 'orderby' => 'include' ] )
            : [];
        if ( [] !== $owned_ids && '' !== (string) $wpdb->last_error ) {
            wp_send_json_error( [ 'message' => hossam_t( 'Products could not be loaded.' ) ], 500 );
        }
    }

    $data = array_map( function( $p ) {
        return [
            'id'    => $p->get_id(),
            'name'  => $p->get_name(),
            'price' => wp_strip_all_tags( $p->get_price_html() ),
            'stock' => $p->get_stock_status(),
            'sku'   => $p->get_sku(),
        ];
    }, $products );

    wp_send_json_success( [
        'products' => $data,
        'page'     => $page,
        'has_more' => $has_more,
    ] );
} );
