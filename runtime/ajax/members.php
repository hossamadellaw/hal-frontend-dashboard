<?php
/**
 * runtime/ajax/members.php (الدفعة 6)
 * ══════════════════════════════════════════════════════════════
 * الدور: نقطة AJAX واحدة — قائمة الأعضاء hossam_get_members [SECTION 14]
 * مقصورة على manage_options (القسم التجريبي Pilot في المصدر — يبقى كما هو).
 *
 * المصدر: نقل حرفي 1:1 لكل الكود من legacy mu-plugins/hossam-dashboard/
 * ajax/members.php — بصمة المصدر بالبايت مثبتة في inventory الدفعة 6
 * (build/source-inventory-batch-6.json، snapshot E48A0A98063529FA19C53E7AD25DDFA2A665C7E4AA795B0E280CADD8841490CA).
 *
 * الدلتا المصرَّح بها الوحيدة (قرار المالك B3-08، الخيار أ): بوابة feature
 * بعد nonce وقبل capability check (لا حرس 401 منفصل في هذا الملف —
 * المصدر ينتقل من nonce إلى manage_options مباشرة) —
 * is_feature_enabled('members') === false → feature_disabled 403 فشل مغلق؛
 * الافتراضي (enabled) يطابق سلوك المصدر حرفيًا.
 *
 * العقد المنقول كما هو: 403 Forbidden لغير المدير، 400 Invalid page
 * لغير السكالار، خريطة role_display_map وWP_User_Query وpagination
 * (page/has_more/total) حرفية، وفشل قراءة DB (last_error) → 500
 * برسالة آمنة بدل نجاح فارغ (B6-UNVERIFIED).
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// [MB-endpoint] Endpoint: hossam_get_members — manage_options فقط
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
