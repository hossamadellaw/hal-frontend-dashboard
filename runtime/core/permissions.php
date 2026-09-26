<?php
/**
 * runtime/core/permissions.php
 * ══════════════════════════════════════════════════════════════
 * الدور: تسجيل Capabilities مخصَّصة لأدوار Amelia.
 *
 * المصدر: نقل حرفي 1:1 من legacy mu-plugins/hossam-dashboard/core/
 * permissions.php (الدفعة 2 من HAL Frontend Dashboard) — نقل بلا
 * توسيع صلاحيات: لا capability جديدة ولا دور جديد يُضاف هنا.
 *
 * العقد المنقول كما هو (ملخَّص من المصدر):
 *   - تسجيل view_amelia_calendar / view_amelia_calendar_all عبر hook init.
 *   - amelia_manager يأخذ الصلاحيتين معًا؛ amelia_employee يأخذ
 *     view_amelia_calendar فقط.
 *   - add_cap() على الدور الموجود فعليًا عبر get_role() فقط — لا
 *     add_role() (الدوران مفترض وجودهما مسبقًا فى قاعدة بيانات
 *     WordPress الحية). get_role() يُرجع null بأمان لو الدور غير
 *     موجود، بلا fatal.
 *   - has_cap() حارس قبل كل add_cap() — add_cap() يكتب update_option()
 *     فعليًا فى كل مرة، وبدون الحارس سيكتب على قاعدة البيانات فى كل
 *     طلب init.
 *   - تُستهلَك الصلاحيتان من adapters/amelia.php وajax/appointments.php
 *     (خارج هذه الدفعة) بجانب manage_options.
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', function (): void {
	$manager = get_role( 'amelia_manager' );
	if ( $manager ) {
		if ( ! $manager->has_cap( 'view_amelia_calendar' ) ) {
			$manager->add_cap( 'view_amelia_calendar' );
		}
		if ( ! $manager->has_cap( 'view_amelia_calendar_all' ) ) {
			$manager->add_cap( 'view_amelia_calendar_all' );
		}
	}

	$employee = get_role( 'amelia_employee' );
	if ( $employee && ! $employee->has_cap( 'view_amelia_calendar' ) ) {
		$employee->add_cap( 'view_amelia_calendar' );
	}
} );
