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
			// الأربعة المؤكَّدة أصلًا من الكود الفعلي — بلا تغيير.
			'seo_score'     => (int) get_post_meta( $post_id, 'rank_math_seo_score', true ), // قراءة فقط، لا تُكتَب
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

if ( ! function_exists( 'hossam_save_rankmath_seo_data' ) ) {
	/**
	 * الكتابة — لا تكتب rank_math_seo_score عمدًا. الأربعة المثبتة فقط.
	 * robots صار يقبل القيم المسموحة فقط من hossam_get_allowed_rank_math_robots()
	 * — القيم الغريبة تُهمَل، والكتابة تُتخطى كليًا إن لم تبقَ قيمة صالحة.
	 *
	 * @param int   $post_id
	 * @param array $data ['focus_keyword','description','robots']
	 * @return bool
	 */
	function hossam_save_rankmath_seo_data( int $post_id, array $data ): bool {
		if ( ! isset( $data['robots'] ) || ! is_array( $data['robots'] ) ) {
			return false;
		}

		$fields_map = [
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

		foreach ( $clean_fields as $meta_key => $value ) {
			if ( ! hossam_update_rankmath_meta( $post_id, $meta_key, $value ) ) {
				return false;
			}
		}
		if ( ! hossam_update_rankmath_meta( $post_id, 'rank_math_robots', $clean_robots ) ) {
			return false;
		}

		return true;
	}
}

// لا حقول Rank Math موسّعة فى هذا الملف أصلًا — انظر رأس الملف لشرط التحقق الحي
// المطلوب قبل إضافة أي حقل موسّع مستقبلاً.
