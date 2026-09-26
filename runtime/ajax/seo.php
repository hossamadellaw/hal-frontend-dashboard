<?php
/**
 * runtime/ajax/seo.php (الدفعة 5)
 * ══════════════════════════════════════════════════════════════
 * الدور: إجراء AJAX واحد — wp_ajax_hossam_save_seo لحفظ بيانات SEO.
 * منطق الكتابة مالكه adapters/rankmath.php (الدفعة 4)؛ هذا الـendpoint
 * يترجم فقط — كما قرر رأس المصدر حرفيًا.
 *
 * المصدر: نقل حرفي 1:1 لكل الكود من legacy mu-plugins/hossam-dashboard/
 * ajax/seo.php — بصمة المصدر بالبايت مثبتة في inventory الدفعة 5
 * (build/source-inventory-batch-5.json). صفر تعديل في العقود.
 *
 * حالة التحميل — عقد §16: «يبقى التحميل تابعًا لحالة registry المعتمدة؛
 * limited read-only» — منقولة كما في المصدر حرفيًا: الملف مشحون في
 * الـrelease لكنه غير مستدعى من runtime/bootstrap.php؛ السجل يثبت
 * verified_fields=false (runtime/core/setup.php) وبوابة المقارنة الحية
 * المؤجلة رسميًا للمقارنة postmeta/المخرجات لم تُجتَز، فلا يُسجَّل هذا
 * الـendpoint في التشغيل الفعلي. تحميله اليوم سيضيف
 * wp_ajax_hossam_save_seo إلى action inventory ويخالف بوابة إغلاق
 * الدفعة 5 («action inventory لا يتغير»). الشرط الوثائقي للتحميل لاحقًا:
 * قرار السجل rank_math = available مع plugin_contract وverified_fields.
 *
 * العقد المنقول كما هو: check_ajax_referer('hossam_nonce','nonce') ثم
 * حارس 401؛ 503 بأسباب السجل عند غير available أو غياب
 * plugin_contract/verified_fields؛ 400 لغير السكالار/الأنواع و404 لغير
 * post و403 لرفض edit_post؛ التنظيف كله داخل المحوِّل؛ الاستجابة تعرض
 * قراءة راجعة من الخادم فقط {message,fields}.
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_ajax_hossam_save_seo', function(): void {
	check_ajax_referer( 'hossam_nonce', 'nonce' );
	if ( ! is_user_logged_in() ) { wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 ); }

	$rm_decision = hossam_get_integration_decision( 'rank_math' );
	$rm_caps     = is_array( $rm_decision['capabilities'] ?? null ) ? $rm_decision['capabilities'] : [];
	if ( 'available' !== $rm_decision['status'] || empty( $rm_caps['plugin_contract'] ) || empty( $rm_caps['verified_fields'] ) ) {
		wp_send_json_error( [ 'message' => $rm_decision['reason'] ?: 'SEO editing is read-only until Rank Math fields are verified.' ], 503 );
	}

	$post_id_raw = $_POST['post_id'] ?? '';
	if ( ! is_scalar( $post_id_raw ) ) {
		wp_send_json_error( [ 'message' => hossam_t( 'Invalid article.' ) ], 400 );
	}
	$post_id = absint( $post_id_raw );
	$post    = $post_id ? get_post( $post_id ) : null;
	if ( ! $post || 'post' !== $post->post_type ) {
		wp_send_json_error( [ 'message' => hossam_t( 'Article not found.' ) ], 404 );
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( [ 'message' => hossam_t( 'You do not have permission to edit this article.' ) ], 403 );
	}

	$data = [];
	if ( isset( $_POST['focus_keyword'] ) ) {
		if ( ! is_string( $_POST['focus_keyword'] ) ) {
			wp_send_json_error( [ 'message' => hossam_t( 'Invalid focus keyword.' ) ], 400 );
		}
		$data['focus_keyword'] = $_POST['focus_keyword'];
	}
	if ( isset( $_POST['description'] ) ) {
		if ( ! is_string( $_POST['description'] ) ) {
			wp_send_json_error( [ 'message' => hossam_t( 'Invalid description.' ) ], 400 );
		}
		$data['description'] = $_POST['description'];
	}
	if ( ! isset( $_POST['robots'] ) || ! is_array( $_POST['robots'] ) ) {
		wp_send_json_error( [ 'message' => hossam_t( 'Choose at least one valid robots directive.' ) ], 400 );
	}
	foreach ( $_POST['robots'] as $robot ) {
		if ( ! is_string( $robot ) ) {
			wp_send_json_error( [ 'message' => hossam_t( 'Invalid robots directive.' ) ], 400 );
		}
	}
	$data['robots'] = $_POST['robots'];

	if ( ! hossam_save_rankmath_seo_data( $post_id, $data ) ) {
		wp_send_json_error( [ 'message' => hossam_t( 'SEO settings could not be saved.' ) ], 500 );
	}

	wp_send_json_success( [
		'message' => hossam_t( 'SEO settings saved.' ),
		'fields'  => hossam_get_rankmath_seo_data( $post_id ),
	] );
} );
