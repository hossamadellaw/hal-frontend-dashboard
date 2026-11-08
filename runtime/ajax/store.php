<?php
/**
 * runtime/ajax/store.php (Batch 6)
 * ══════════════════════════════════════════════════════════════
 * Role: a single AJAX endpoint — WooCommerce products hossam_get_products
 * [SECTION 15]. The Orders tab in the Store panel reuses hossam_get_orders
 * from ajax/finance.php verbatim (the documented source decision — no new endpoint).
 *
 * Source: verbatim 1:1 transfer of all code from legacy mu-plugins/hossam-dashboard/
 * ajax/store.php — the source fingerprint is pinned byte-for-byte in the Batch 6
 * inventory (build/source-inventory-batch-6.json, snapshot E48A0A98063529FA19C53E7AD25DDFA2A665C7E4AA795B0E280CADD8841490CA).
 *
 * The only authorized delta (owner decision B3-08, option a): a feature gate
 * after the nonce and the 401 guard and before any capability check —
 * is_feature_enabled('store') === false → feature_disabled 403 closed failure;
 * the default (enabled) matches the source behavior verbatim.
 *
 * The carried-over contract as-is: 403 edit_products for the unauthorized, the ownership scope
 * via edit_others_products (others: their own products only via get_posts author),
 * 503 via the woocommerce integration decision + hossam_wc_is_active(), pagination
 * (per=20 and +1 lookahead and has_more) — all verbatim, and a DB read failure
 * (last_error) → 500 with a safe message instead of an empty success (B6-UNVERIFIED).
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ══════════════════════════════════════════════════════════════
// SECTION 15: STORE — Feature #13
// ══════════════════════════════════════════════════════════════
// [WC-endpoint] Endpoint: hossam_get_products — the Orders tab reuses hossam_get_orders verbatim (no new endpoint for it)
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
