<?php
/**
 * runtime/ajax/members.php (batch 6)
 * ══════════════════════════════════════════════════════════════
 * Role: a single AJAX endpoint — the members list hossam_get_members [SECTION 14]
 * restricted to manage_options (the Pilot section in the source — stays as-is).
 *
 * Source: a verbatim 1:1 move of all the code from legacy mu-plugins/hossam-dashboard/
 * ajax/members.php — the source fingerprint byte-pinned in the batch-6 inventory
 * (build/source-inventory-batch-6.json, snapshot E48A0A98063529FA19C53E7AD25DDFA2A665C7E4AA795B0E280CADD8841490CA).
 *
 * The only declared delta (owner decision B3-08, option A): a feature gate
 * after the nonce and before the capability check (no separate 401 guard in
 * this file — the source goes from the nonce straight to manage_options) —
 * is_feature_enabled('members') === false → feature_disabled 403 fail-closed;
 * the default (enabled) matches the source behavior verbatim.
 *
 * The carried contract as-is: 403 Forbidden for non-admins, 400 Invalid page
 * for non-scalars, the role_display_map map and WP_User_Query and pagination
 * (page/has_more/total) verbatim, and a DB read failure (last_error) → 500
 * with a safe message instead of an empty success (B6-UNVERIFIED).
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// [MB-endpoint] Endpoint: hossam_get_members — manage_options only
add_action( 'wp_ajax_hossam_get_members', function(): void {
	check_ajax_referer( 'hossam_nonce', 'nonce' );
	if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'members' ) ) {
		wp_send_json_error( array( 'code' => 'feature_disabled', 'message' => 'This feature is disabled.' ), 403 );
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
	}

	$page_raw = $_POST['page'] ?? 1;
	if ( ! is_scalar( $page_raw ) ) {
		wp_send_json_error( [ 'message' => 'Invalid page' ], 400 );
	}
	$page     = max( 1, absint( $page_raw ) );
	$per_page = 50;

	$role_display_map = [
		'administrator'   => hossam_t( 'Administrator' ),
		'editor'          => hossam_t( 'Editor' ),
		'author'          => hossam_t( 'Author' ),
		'contributor'     => hossam_t( 'Contributor' ),
		'amelia_manager'  => hossam_t( 'Manager' ),
		'amelia_employee' => hossam_t( 'Employee' ),
	];

	$query = new WP_User_Query( [
		'role__in'   => array_keys( $role_display_map ),
		'number'     => $per_page,
		'offset'     => ( $page - 1 ) * $per_page,
		'orderby'    => 'ID',
		'order'      => 'ASC',
		'count_total' => true,
	] );
	$users = $query->get_results();
	$total = (int) $query->get_total();

	global $wpdb;
	if ( '' !== (string) $wpdb->last_error ) {
		wp_send_json_error( [ 'message' => hossam_t( 'Members could not be loaded.' ) ], 500 );
	}

	$data = array_map( function( $u ) use ( $role_display_map ) {
		$roles = array_intersect_key( $role_display_map, array_flip( (array) $u->roles ) );
		return [
			'id'    => $u->ID,
			'name'  => $u->display_name,
			'email' => $u->user_email,
			'roles' => array_values( $roles ),
		];
	}, $users );

	wp_send_json_success( [
		'members'  => $data,
		'page'     => $page,
		'has_more' => $page * $per_page < $total,
		'total'    => $total,
	] );
} );
