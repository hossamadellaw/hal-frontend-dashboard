<?php
/**
 * runtime/templates/dashboard/profile-links.php — نقطة تمديد روابط «ملفي»
 * ══════════════════════════════════════════════════════════════
 * Change Request: CR-2026-09-25-PROFILE-LINKS (طلب المالك الحالي؛ §15.35).
 * فلتر معلن: `hal_frontend_dashboard_profile_links`.
 *
 * العقد:
 *   - يُستدعى وقت العرض (من بانل profile.php بعد بوابة الميزة)، بعد
 *     تحميل الإضافات — لا أثناء Bootstrap ولا في مسار مبكر.
 *   - يستقبل: array فارغًا افتراضيًا + معرّف المستخدم الحالي من
 *     get_current_user_id() (لا من مدخلات الطلب).
 *   - كل عنصر صالح: id نصي ثابت فريد (الأول يفوز) + label نصي غير فارغ
 *     بلا HTML + url مطلق http/https بمضيف بلا مسافات + eligible === true حرفيًا +
 *     order صحيح اختياري (الغائب وحده = 100؛ الموجود غير الصحيح، بما فيه
 *     null، يُتخطى ولا يُعوَّض؛ الأصغر أولًا، وثبات الإدخال عند التساوي).
 *   - ناتج غير مصفوفة = قائمة فارغة. المخالف يُتخطى وحده.
 *   - ظهور الرابط لا يمنح صلاحية: الصفحة الهدف تتحقق بنفسها.
 *   - الحدود: روابط فقط في هذا الموضع — لا panels ولا نماذج ولا حفظ
 *     ولا أسرار ولا بيانات مستخدمين آخرين. لا HTML/JS من المزود، ولا
 *     جلب أو تخزين لمحتوى الروابط.
 *
 * مثال مزوّد مستقل (في إضافة المقدِّمة، لا هنا):
 *
 *   add_filter( 'hal_frontend_dashboard_profile_links', function ( $links, $user_id ) {
 *       $links[] = array(
 *           'id'       => 'member-profiles.overview',
 *           'label'    => __( 'Member overview', 'member-profiles' ),
 *           'url'      => home_url( '/member/' . $user_id . '/' ),
 *           'eligible' => current_user_can( 'read' ),
 *           'order'    => 10,
 *       );
 *       return $links;
 *   }, 10, 2 );
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'hossam_profile_link_url_allowed' ) ) {
	/**
	 * يقبل URL مطلقًا ببروتوكول HTTP/HTTPS ومضيف غير فارغ فقط.
	 */
	function hossam_profile_link_url_allowed( $url ): bool {
		if ( ! is_string( $url ) || '' === $url ) {
			return false;
		}
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return false;
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return false;
		}
		$host = (string) ( $parts['host'] ?? '' );
		if ( '' === $host || 1 === preg_match( '/\s/', $host ) ) {
			return false;
		}
		return true;
	}
}

if ( ! function_exists( 'hossam_profile_links' ) ) {
	/**
	 * يجمع روابط «ملفي» من الفلتر المعلن ويعيد المقبولة مرتبة فقط.
	 *
	 * @return array[] كل عنصر: id/label/url/order (مرتب، بلا مفاتيح مؤقتة).
	 */
	function hossam_profile_links(): array {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$links = function_exists( 'apply_filters' )
			? apply_filters( 'hal_frontend_dashboard_profile_links', array(), $user_id )
			: array();
		if ( ! is_array( $links ) ) {
			return array();
		}
		$accepted = array();
		$seen = array();
		$index = 0;
		foreach ( $links as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$id = $item['id'] ?? null;
			if ( ! is_string( $id ) || '' === trim( $id ) || isset( $seen[ $id ] ) ) {
				continue;
			}
			$label = $item['label'] ?? null;
			if ( ! is_string( $label ) || '' === trim( $label ) || $label !== strip_tags( $label ) ) {
				continue;
			}
			$url = $item['url'] ?? null;
			if ( ! hossam_profile_link_url_allowed( $url ) ) {
				continue;
			}
			if ( ! array_key_exists( 'eligible', $item ) || true !== $item['eligible'] ) {
				continue;
			}
		$order = null;
		if ( ! array_key_exists( 'order', $item ) ) {
			$order = 100;
		} elseif ( ! is_int( $item['order'] ) ) {
			continue; // present-but-invalid order (incl. null): skip, never default
		} else {
			$order = $item['order'];
		}
			$seen[ $id ] = true;
			$accepted[] = array(
				'id'    => $id,
				'label' => $label,
				'url'   => $url,
				'order' => $order,
				'index' => $index++,
			);
		}
		usort(
			$accepted,
			static function ( array $a, array $b ): int {
				if ( $a['order'] !== $b['order'] ) {
					return $a['order'] < $b['order'] ? -1 : 1;
				}
				return $a['index'] < $b['index'] ? -1 : ( $a['index'] > $b['index'] ? 1 : 0 );
			}
		);
		$sorted = array();
		foreach ( $accepted as $entry ) {
			unset( $entry['index'] );
			$sorted[] = $entry;
		}
		return $sorted;
	}
}

if ( ! function_exists( 'hossam_render_profile_links_section' ) ) {
	/**
	 * يعرض قسم «روابط إضافية» بتنسيق Dashboard بعد بطاقات الملف
	 * والإعدادات والأمان. لا شيء عند غياب روابط مقبولة. روابط تنقل
	 * عادية بمخرجات مهرّبة (esc_url/esc_html) فقط.
	 */
	function hossam_render_profile_links_section(): void {
		$links = hossam_profile_links();
		if ( array() === $links ) {
			return;
		}
		echo '<div class="card mt-12"><div class="card-title mb-12">'
			. esc_html( function_exists( 'hossam_t' ) ? hossam_t( 'Additional links' ) : 'Additional links' )
			. '</div><div class="form-actions">';
		foreach ( $links as $link ) {
			echo '<a href="' . esc_url( $link['url'] ) . '" class="btn btn-ghost btn-sm">'
				. esc_html( $link['label'] ) . '</a>';
		}
		echo '</div></div>';
	}
}
