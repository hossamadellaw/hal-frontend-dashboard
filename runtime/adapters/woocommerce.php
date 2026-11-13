<?php
/**
 * runtime/adapters/woocommerce.php (Batch 4)
 * ══════════════════════════════════════════════════════════════
 * Role: complete isolation of every official WooCommerce call — no direct SQL
 * anywhere in this file.
 *
 * Source: verbatim 1:1 port from legacy mu-plugins/hossam-dashboard/adapters/
 * woocommerce.php (Batch 4 of HAL Frontend Dashboard) — zero changes
 * to contracts.
 *
 * The contract as carried over (summarized from the source header):
 *   - Every official WC call in the project goes through here only (the finance/store AJAX
 *     handlers call this file's functions — Batches 5/6).
 *   - The woocommerce_order_status_changed hook is deliberately not registered here:
 *     it is registered and owned by core/notifications.php (which calls
 *     hossam_wc_get_order_status_name directly, guarded
 *     by function_exists) — registering it here would produce a duplicate notification.
 *   - hossam_wc_get_available_payment_gateways: a dashboard-safe list —
 *     the official get_available_payment_gateways() requires a
 *     checkout/session context and must not be called from a generic admin-ajax request.
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'hossam_wc_is_active' ) ) {
	function hossam_wc_is_active(): bool {
		return function_exists( 'wc_get_orders' );
	}
}

if ( ! function_exists( 'hossam_wc_get_orders_data' ) ) {
	/**
	 * @param array<string,mixed> $args wc_get_orders() args, already built/scoped by the caller.
	 * @return array<int,WC_Order>
	 */
	function hossam_wc_get_orders_data( array $args ): array {
		if ( ! hossam_wc_is_active() ) {
			return [];
		}

		$orders = wc_get_orders( $args );

		return is_array( $orders ) ? $orders : [];
	}
}

if ( ! function_exists( 'hossam_wc_get_orders_page' ) ) {
	/**
	 * Executes a bounded, paginated WooCommerce order query.
	 *
	 * @param array<string,mixed> $args Scoped query arguments.
	 * @return array{orders:array<int,WC_Order>,total:int,max_num_pages:int}
	 */
	function hossam_wc_get_orders_page( array $args ): array {
		$empty = [ 'orders' => [], 'total' => 0, 'max_num_pages' => 0 ];
		if ( ! hossam_wc_is_active() ) {
			return $empty;
		}

		$args['limit']    = min( 100, max( 1, absint( $args['limit'] ?? 100 ) ) );
		$args['page']     = max( 1, absint( $args['page'] ?? 1 ) );
		$args['paginate'] = true;
		unset( $args['offset'] );
		$result = wc_get_orders( $args );
		if ( ! is_object( $result ) || ! isset( $result->orders, $result->total, $result->max_num_pages ) ) {
			return $empty;
		}

		return [
			'orders'        => is_array( $result->orders ) ? $result->orders : [],
			'total'         => max( 0, (int) $result->total ),
			'max_num_pages' => max( 0, (int) $result->max_num_pages ),
		];
	}
}

if ( ! function_exists( 'hossam_wc_get_order_statuses' ) ) {
	/**
	 * @return array<string,string>
	 */
	function hossam_wc_get_order_statuses(): array {
		return function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : [];
	}
}

if ( ! function_exists( 'hossam_wc_get_order_status_name' ) ) {
	function hossam_wc_get_order_status_name( string $status ): string {
		return function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $status ) : $status;
	}
}

if ( ! function_exists( 'hossam_wc_get_currency' ) ) {
	function hossam_wc_get_currency(): string {
		return function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
	}
}

if ( ! function_exists( 'hossam_wc_format_price' ) ) {
	function hossam_wc_format_price( float $amount ): string {
		return function_exists( 'wc_price' ) ? (string) wc_price( $amount ) : (string) $amount;
	}
}

if ( ! function_exists( 'hossam_wc_get_products_data' ) ) {
	/**
	 * @param array<string,mixed> $args wc_get_products() args, already built/scoped by the caller.
	 * @return array<int,WC_Product>
	 */
	function hossam_wc_get_products_data( array $args ): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return [];
		}

		$products = wc_get_products( $args );

		return is_array( $products ) ? $products : [];
	}
}

if ( ! function_exists( 'hossam_wc_get_available_payment_gateways' ) ) {
	/**
	 * Dashboard-safe gateway list. WooCommerce's
	 * get_available_payment_gateways() requires a checkout/session context and
	 * must not be called from a generic admin-ajax request.
	 *
	 * @return array<string,WC_Payment_Gateway>
	 */
	function hossam_wc_get_available_payment_gateways(): array {
		return array_filter(
			hossam_wc_get_registered_payment_gateways(),
			static function ( $gateway ): bool {
				return is_object( $gateway )
					&& isset( $gateway->enabled )
					&& 'yes' === $gateway->enabled;
			}
		);
	}
}

if ( ! function_exists( 'hossam_wc_get_registered_payment_gateways' ) ) {
	/**
	 * @return array<string,WC_Payment_Gateway>
	 */
	function hossam_wc_get_registered_payment_gateways(): array {
		if ( ! class_exists( 'WC_Payment_Gateways' ) ) {
			return [];
		}

		return WC_Payment_Gateways::instance()->payment_gateways();
	}
}

if ( ! function_exists( 'hossam_wc_get_customer_tokens' ) ) {
	/**
	 * @return array<int,WC_Payment_Token>
	 */
	function hossam_wc_get_customer_tokens( int $user_id ): array {
		if ( $user_id < 1 || ! class_exists( 'WC_Payment_Tokens' ) ) {
			return [];
		}

		$tokens = WC_Payment_Tokens::get_customer_tokens( $user_id );

		return is_array( $tokens ) ? $tokens : [];
	}
}
