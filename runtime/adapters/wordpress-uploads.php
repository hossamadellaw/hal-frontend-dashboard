<?php
/**
 * runtime/adapters/wordpress-uploads.php (الدفعة 4)
 * ══════════════════════════════════════════════════════════════
 * الدور: رفع ملفات أصلي عبر WordPress Core (media_handle_upload).
 *
 * المصدر: نقل حرفي 1:1 من legacy mu-plugins/hossam-dashboard/adapters/
 * wordpress-uploads.php (الدفعة 4 من HAL Frontend Dashboard) — صفر
 * تعديل في العقود.
 *
 * العقد المنقول كما هو (ملخَّص من رأس المصدر):
 *   - require الثلاثة wp-admin/includes (file/image/media) قبل
 *     media_handle_upload() — ملف تعريف الدالة نفسه غير محمل خارج
 *     wp-admin إلا بهذا الطلب الصريح.
 *   - media_handle_upload( 'file', 0 ) — 0 = غير مرتبط بمقال؛ اسم حقل
 *     $_FILES ثابت 'file' (وحدة uploads.js تسمّي الحقل بنفس الاسم —
 *     الدفعة 9).
 *   - allow-list امتدادات/MIME: سياسة WordPress الفعلية للمستخدم هي
 *     الحد الأعلى؛ الفلتر hossam_uploads_allowed_mimes يضيّقها فقط —
 *     التقاطع يمنع إعادة تمكين نوع عطله الموقع أو فلتر سابق.
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'hossam_get_allowed_upload_mimes' ) ) {
	/**
	 * @return array<string,string> extension pattern => mime type (WordPress upload_mimes format).
	 */
	function hossam_get_allowed_upload_mimes(): array {
		$site_policy = get_allowed_mime_types( get_current_user_id() );
		if ( ! is_array( $site_policy ) ) {
			return [];
		}
		$requested = apply_filters( 'hossam_uploads_allowed_mimes', $site_policy );
		if ( ! is_array( $requested ) ) {
			return $site_policy;
		}

		// The project filter may narrow the site's effective policy, never expand it.
		return array_intersect_assoc( $site_policy, $requested );
	}
}

if ( ! function_exists( 'hossam_upload_file' ) ) {
	/**
	 * Uploads the file submitted in the 'file' $_FILES field, unattached to any post.
	 *
	 * @return int|WP_Error
	 */
	function hossam_upload_file() {
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error( 'hossam_forbidden', __( 'You do not have permission to upload files.', 'astra-child' ) );
		}

		$field = 'file';
		if ( empty( $_FILES[ $field ] )
			|| ! is_array( $_FILES[ $field ] )
			|| ! isset( $_FILES[ $field ]['name'] )
			|| ! is_string( $_FILES[ $field ]['name'] )
			|| '' === $_FILES[ $field ]['name'] ) {
			return new WP_Error( 'hossam_no_file', __( 'No file was received.', 'astra-child' ) );
		}

		$allowed_mimes = hossam_get_allowed_upload_mimes();
		$file_name     = sanitize_file_name( wp_unslash( $_FILES[ $field ]['name'] ) );
		$filetype      = wp_check_filetype( $file_name, $allowed_mimes );
		if ( ! $filetype['ext'] || ! $filetype['type'] ) {
			return new WP_Error( 'hossam_invalid_file_type', __( 'This file type is not allowed.', 'astra-child' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$restrict_mimes = function ( array $current_mimes ) use ( $allowed_mimes ): array {
			return array_intersect_assoc( $current_mimes, $allowed_mimes );
		};

		add_filter( 'upload_mimes', $restrict_mimes );
		$attachment_id = media_handle_upload( $field, 0 );
		remove_filter( 'upload_mimes', $restrict_mimes );

		return $attachment_id;
	}
}
