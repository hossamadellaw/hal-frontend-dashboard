<?php
/**
 * runtime/adapters/rankmath.php (الدفعة 4)
 * ══════════════════════════════════════════════════════════════
 * الدور: لوحة تحكم أمامية لـRank Math (قراءة + كتابة) — عبر
 * get_post_meta()/update_post_meta() القياسيتين فى WordPress Core
 * فقط. لا Hook ولا Function من Rank Math نفسه فى أي مكان.
 *
 * المصدر: نقل حرفي 1:1 من legacy mu-plugins/hossam-dashboard/adapters/
 * rankmath.php (الدفعة 4 من HAL Frontend Dashboard) — صفر تعديل في
 * العقود أو الحقول أو الـallowlists.
 *
 * العقد المنقول كما هو (ملخَّص من رأس المصدر):
 *   - الحقول المثبَّتة أربعة فقط (rank_math_seo_score/focus_keyword/
 *     description/robots) — لا حقول موسّعة، ولا تُعاد إلا بعد التحقق
 *     الحي الموصوف فى dashboard-unified-execution-reference.md سطر 245
 *     (var_dump(get_post_meta($id)) على مقال حقيقي + مقارنة postmeta
 *     قبل/بعد على staging)، وليس بمجرد تشابه اسم المفتاح.
 *   - v1.0.6 §7: أُضيف rank_math_title (مفتاحه مثبت في المصدر الرسمي
 *     rest/class-sanitize.php:50 ومؤكد حيًّا في probe 2026-10-14)
 *     بالقراءة الراجعة والاستعادة نفسها — خامد حتى بوابة بند 8.
 *   - rank_math_seo_score للقراءة فقط — لا مسار كتابة له عمدًا
 *     (مُحسَّب داخليًا بواسطة Rank Math نفسه).
 *   - robots قائمة مغلقة من hossam_get_allowed_rank_math_robots() —
 *     القيم الغريبة تُهمَل والكتابة تُتخطى كليًا إن لم تبقَ قيمة صالحة
 *     (فشل آمن، لا إتلاف للقيمة القائمة). التوسعة عبر الفلتر
 *     hossam_rank_math_allowed_robots من PHP موثوق بعد تحقق حي فقط.
 *   - hossam_update_rankmath_meta: مقارنة قبل الكتابة (update_post_meta
 *     يعيد false ثنائي المعنى — عدم التغيير ليس فشلًا).
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'hossam_get_rankmath_seo_data' ) ) {
	/**
	 * القراءة — الأربعة المؤكَّدة أصلًا من الكود الفعلي — بلا تغيير.
	 *
	 * @return array
	 */
	function hossam_get_rankmath_seo_data( int $post_id ): array {
		return [
			// الأربعة المؤكَّدة أصلًا من الكود الفعلي + title (v1.0.6 §7) — بلا تغيير في العقود.
			'seo_score'     => (int) get_post_meta( $post_id, 'rank_math_seo_score', true ), // قراءة فقط، لا تُكتَب
			'title'         => (string) get_post_meta( $post_id, 'rank_math_title', true ), // v1.0.6 §7
			'focus_keyword' => (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true ), // قد تحتوي كلمات مفصولة بفواصل
			'description'   => (string) get_post_meta( $post_id, 'rank_math_description', true ),
			'robots'        => get_post_meta( $post_id, 'rank_math_robots', true ),
		];
	}
}

if ( ! function_exists( 'hossam_get_allowed_rank_math_robots' ) ) {
	/**
	 * Closed allow-list for rank_math_robots values (reference §Rank Math ب.4:
	 * «robots لا يقبل مصفوفة مفتوحة بل قائمة قيم مسموحة»). Extension only via
	 * this filter from trusted PHP after live verification on the site's
	 * actual Rank Math version.
	 *
	 * @return string[]
	 */
	function hossam_get_allowed_rank_math_robots(): array {
		$base       = [ 'index', 'noindex', 'follow', 'nofollow' ];
		$extensions = apply_filters(
			'hossam_rank_math_allowed_robots',
			[]
		);

		if ( ! is_array( $extensions ) ) {
			$extensions = [];
		}

		$extensions = array_map( 'sanitize_key', array_filter( $extensions, 'is_string' ) );
		return array_values( array_unique( array_merge( $base, array_filter( $extensions ) ) ) );
	}
}

if ( ! function_exists( 'hossam_update_rankmath_meta' ) ) {
	function hossam_update_rankmath_meta( int $post_id, string $meta_key, $value ): bool {
		$current = get_post_meta( $post_id, $meta_key, true );
		if ( $current === $value ) {
			return true;
		}

		return false !== update_post_meta( $post_id, $meta_key, $value );
	}
}

if ( ! function_exists( 'hossam_restore_rankmath_meta_snapshot' ) ) {
	/**
	 * v1.0.6 §7 — استعادة لقطة سابقة: meta غائبة تُحذف، وموجودة فارغة تُكتب ''.
	 *
	 * @param array<string,array{exists:bool,value:mixed}> $snapshot
	 */
	function hossam_restore_rankmath_meta_snapshot( int $post_id, array $snapshot ): void {
		foreach ( $snapshot as $meta_key => $previous ) {
			if ( empty( $previous['exists'] ) ) {
				delete_post_meta( $post_id, $meta_key );
			} else {
				update_post_meta( $post_id, $meta_key, $previous['value'] );
			}
		}
	}
}

if ( ! function_exists( 'hossam_save_rankmath_seo_data' ) ) {
	/**
	 * الكتابة — لا تكتب rank_math_seo_score عمدًا. الحقول المثبتة:
	 * title/focus_keyword/description/robots (v1.0.6 §7 أضاف title).
	 * robots صار يقبل القيم المسموحة فقط من hossam_get_allowed_rank_math_robots()
	 * — القيم الغريبة تُهمَل، والكتابة تُتخطى كليًا إن لم تبقَ قيمة صالحة.
	 *
	 * v1.0.6 §7 (نمط الفشل الجزئي — خامد حتى بوابة بند 8): قبل أي كتابة
	 * تُقرأ القيم السابقة للحقول المستهدفة (وجودًا وقيمة) → كتابة →
	 * إعادة قراءة ومطابقة لكل حقل → عند أي فشل تُستعاد السابقة كاملة
	 * قبل return false.
	 *
	 * @param int   $post_id
	 * @param array $data ['title','focus_keyword','description','robots']
	 * @return bool
	 */
	function hossam_save_rankmath_seo_data( int $post_id, array $data ): bool {
		if ( ! isset( $data['robots'] ) || ! is_array( $data['robots'] ) ) {
			return false;
		}

		$fields_map = [
			'title'         => 'rank_math_title',
			'focus_keyword' => 'rank_math_focus_keyword',
			'description'   => 'rank_math_description',
		];

		$clean_fields = [];
		foreach ( $fields_map as $data_key => $meta_key ) {
			if ( ! isset( $data[ $data_key ] ) ) {
				continue;
			}
			if ( ! is_string( $data[ $data_key ] ) ) {
				return false;
			}

			$clean_fields[ $meta_key ] = 'description' === $data_key
				? sanitize_textarea_field( wp_unslash( $data[ $data_key ] ) )
				: sanitize_text_field( wp_unslash( $data[ $data_key ] ) );
		}

		if ( isset( $clean_fields['rank_math_title'] ) ) {
			$title_length = function_exists( 'mb_strlen' )
				? mb_strlen( $clean_fields['rank_math_title'] )
				: strlen( $clean_fields['rank_math_title'] );
			if ( $title_length > 255 ) {
				return false;
			}
		}

		$robots = [];
		foreach ( $data['robots'] as $robot ) {
			if ( ! is_string( $robot ) ) {
				return false;
			}
			$robots[] = sanitize_key( wp_unslash( $robot ) );
		}
		$clean_robots = array_values( array_unique( array_intersect( $robots, hossam_get_allowed_rank_math_robots() ) ) );
		if ( empty( $clean_robots ) ) {
			return false;
		}

		// اللقطة قبل أي كتابة — للحقول المستهدفة فقط.
		$targets = $clean_fields;
		$targets['rank_math_robots'] = $clean_robots;
		$snapshot = [];
		foreach ( $targets as $meta_key => $new_value ) {
			$snapshot[ $meta_key ] = [
				'exists' => metadata_exists( 'post', $post_id, $meta_key ),
				'value'  => get_post_meta( $post_id, $meta_key, true ),
			];
		}

		foreach ( $targets as $meta_key => $new_value ) {
			if ( ! hossam_update_rankmath_meta( $post_id, $meta_key, $new_value ) ) {
				hossam_restore_rankmath_meta_snapshot( $post_id, $snapshot );
				return false;
			}
			if ( get_post_meta( $post_id, $meta_key, true ) !== $new_value ) {
				hossam_restore_rankmath_meta_snapshot( $post_id, $snapshot );
				return false;
			}
		}

		return true;
	}
}

// v1.0.6 §7: أُضيف rank_math_title بالقراءة الراجعة والاستعادة نفسها
// (خامد حتى بوابة بند 8) — لا حقول موسّعة أخرى في هذا الملف.
