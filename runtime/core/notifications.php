<?php
/**
 * runtime/core/notifications.php
 * ══════════════════════════════════════════════════════════════
 * Role: a single unified function for admin notifications that replaces the logic
 * duplication literally installed 3 times in the original code, along with invalidating
 * the financial KPI cache.
 *
 * Source: verbatim 1:1 port from legacy mu-plugins/hossam-dashboard/core/
 * notifications.php (batch 2 of HAL Frontend Dashboard).
 *
 * Contract carried over as-is:
 *   - hossam_notify_admins(): a get_users(['role'=>'administrator',
 *     'fields'=>'ID']) loop + $wpdb->insert() with the column format
 *     ['%d','%s','%s','%d','%s'], and a JSON message {key,args,url}.
 *   - The three hooks: transition_post_status / amelia_after_booking_added /
 *     woocommerce_order_status_changed.
 *   - hossam_bump_fin_cache_epoch(): rotating the epoch inside the transient key
 *     used by ajax/finance.php (outside this batch) — KPI cache invalidation
 *     when the order status changes.
 *   - A $wpdb->last_error guard on the employees query in
 *     amelia_after_booking_added.
 *   - hossam_dashboard_url() calls (adapters/wpml.php, batch 4)
 *     inside callbacks executed at request time — i.e. after the adapters finish
 *     loading, hence the mandatory boot ordering.
 *
 * Invalidation note on release switch (B2-U3 resolved with the evidence of the keys
 * and consumers — the absence of a switch event is not enough to deny the need):
 *   - hossam_integration_capabilities_v1_* (setup.php): resolved
 *     by constraining the key with the release id in this batch — switching
 *     the release orphans the old value automatically until TTL expiry.
 *   - hossam_i18n_v (i18n.php): no need — the md5 hash over the content of
 *     the strings is self-verifying; any later change re-registers without binding
 *     to the version.
 *   - hossam_fin_cache_ver (epoch) and hossam_fin_summary_* and
 *     hossam_appts_{id}_emp/_mgr: the appts key is written and deleted from
 *     two sides — the creator in ajax/appointments.php (batch 6, not yet ported)
 *     and the deleter here (154-155). Changing only one side now breaks the
 *     invalidation pair, so the binding decision: constrain these keys with the release id in
 *     batch 6 along with the creator, and delete them here in the same batch (an item recorded in
 *     the migration map), and their values remain TTL-bounded in the meantime.
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'hossam_notify_admins' ) ) {
	/**
	 * @param array<int,string> $args
	 */
	function hossam_notify_admins( string $type, array $args, string $url = '' ): bool {
		global $wpdb;

		$table  = $wpdb->prefix . 'hossam_notifications';
		$admins = get_users( [ 'role' => 'administrator', 'fields' => 'ID' ] );
		if ( ! is_array( $admins ) || [] === $admins ) {
			return true;
		}
		$message = wp_json_encode( [
			'key'  => $type,
			'args' => $args,
			'url'  => $url,
		] );

		$all_ok = true;
		foreach ( $admins as $admin_id ) {
			$result = $wpdb->insert(
				$table,
				[
					'user_id'    => (int) $admin_id,
					'type'       => $type,
					'message'    => $message,
					'is_read'    => 0,
					'created_at' => current_time( 'mysql' ),
				],
				[ '%d', '%s', '%s', '%d', '%s' ]
			);
			if ( false === $result || '' !== $wpdb->last_error ) {
				error_log( 'Hossam Dashboard notification insert failed.' );
				$all_ok = false;
			}
		}

		return $all_ok;
	}
}

add_action( 'transition_post_status', function( string $new_status, string $old_status, WP_Post $post ): void {
	if ( ! in_array( $new_status, [ 'pending', 'publish' ], true )
		|| $new_status === $old_status
		|| 'post' !== $post->post_type ) {
		return;
	}

	$post_id = (int) $post->ID;
	hossam_notify_admins(
		'new_post',
		[ sanitize_text_field( $post->post_title ) ],
		add_query_arg(
			[ 'panel' => 'edit-article', 'post_id' => $post_id ],
			hossam_dashboard_url()
		)
	);
}, 10, 3 );

if ( ! function_exists( 'hossam_bump_fin_cache_epoch' ) ) {
	/**
	 * Orphans every hossam_fin_summary_* transient at once by rotating the
	 * epoch segment inside the cache key used by ajax/finance.php. Old
	 * transients expire on their own 300s TTL; no unbounded option growth.
	 */
	function hossam_bump_fin_cache_epoch(): bool {
		$target  = (int) get_option( 'hossam_fin_cache_ver', 0 ) + 1;
		$updated = update_option( 'hossam_fin_cache_ver', $target, false );
		if ( false === $updated ) {
			if ( (int) get_option( 'hossam_fin_cache_ver', 0 ) === $target ) {
				return true;
			}
			error_log( 'Hossam Dashboard finance cache epoch bump failed.' );
			return false;
		}

		return true;
	}
}

add_action( 'amelia_after_booking_added', function( array $booking ): void {
	$first_name = isset( $booking['firstName'] ) && is_scalar( $booking['firstName'] )
		? sanitize_text_field( (string) $booking['firstName'] )
		: '';
	$last_name = isset( $booking['lastName'] ) && is_scalar( $booking['lastName'] )
		? sanitize_text_field( (string) $booking['lastName'] )
		: '';
	$name = $first_name . ' ' . $last_name;

	hossam_notify_admins( 'new_booking', [ trim( $name ) ], hossam_dashboard_url() );

	global $wpdb;
	$employees_table = $wpdb->prefix . 'amelia_employees';
	$found_table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $employees_table ) ) );
	if ( '' !== $wpdb->last_error ) {
		error_log( 'Hossam Dashboard Amelia table check failed while invalidating appointments cache.' );

		return;
	}
	if ( $found_table !== $employees_table ) {
		return;
	}

	$employees = $wpdb->get_col( "SELECT DISTINCT externalId FROM {$employees_table} WHERE externalId > 0" );
	if ( '' !== $wpdb->last_error ) {
		error_log( 'Hossam Dashboard Amelia employee lookup failed while invalidating appointments cache.' );

		return;
	}

	$calendar_users = get_users( [
		'fields'         => 'ID',
		'capability__in' => [ 'manage_options', 'view_amelia_calendar_all', 'view_amelia_calendar' ],
	] );
	$cache_user_ids = array_unique( array_map( 'intval', array_merge( (array) $employees, (array) $calendar_users ) ) );
	foreach ( $cache_user_ids as $employee_id ) {
		delete_transient( 'hossam_appts_' . (int) $employee_id . '_emp' );
		delete_transient( 'hossam_appts_' . (int) $employee_id . '_mgr' );
	}
} );

add_action( 'woocommerce_order_status_changed', function( int $order_id, string $from, string $to ): void {
	$status_name = function_exists( 'hossam_wc_get_order_status_name' )
		? hossam_wc_get_order_status_name( $to )
		: $to;

	hossam_notify_admins(
		'order_status_changed',
		[ (string) $order_id, $status_name ],
		hossam_dashboard_url()
	);

	hossam_bump_fin_cache_epoch();
}, 10, 3 );
