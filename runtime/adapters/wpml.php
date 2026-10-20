<?php
/**
 * runtime/adapters/wpml.php (الدفعة 4)
 * ══════════════════════════════════════════════════════════════
 * الدور: كل تكامل WPML الرسمي — URL helpers + حالة الترجمة عبر
 * الفلاتر الرسمية الموثقة فقط.
 *
 * المصدر: نقل حرفي 1:1 من legacy mu-plugins/hossam-dashboard/adapters/
 * wpml.php (الدفعة 4 من HAL Frontend Dashboard) — صفر تعديل في العقود.
 *
 * العقد المنقول كما هو (ملخَّص من رأس المصدر):
 *   - كل النداءات عبر فلاتر WPML الرسمية (wpml_permalink/
 *     wpml_active_languages/wpml_object_id/wpml_element_trid/…)
 *     مع حراسة ICL_SITEPRESS_VERSION — غياب WPML يعيد fallbacks آمنة
 *     (لا SQL ولا تخمين مفاتيح داخلية).
 *   - مستهلكو core محميون: setup.php/notifications.php يستدعون
 *     hossam_wpml_all_paths()/hossam_dashboard_url()/hossam_wpml_is_rtl()
 *     داخل callbacks وقت الطلب أو بحارس function_exists — الترتيب
 *     Core→Adapters في bootstrap يضمن التعريف وقت النداء.
 *   - T-29/T-30 (سويتشر لغة مخصص واستبدال [wpml_language_switcher])
 *     مؤجلة بقرار صاحب المشروع إلى بوابة إغلاق المشروع (اختبار حي
 *     ومقارنة shortcode) — لا تنفيذ هنا.
 *   - hossam_get_translation_status(): مستهلكها الفعلي translations.php
 *     (v1.0.6 §14 — جدول حالات الترجمة، استدعاء :152-154)؛ أي مسار إضافي
 *     يحتاج تفويضًا صريحًا + بند تحقق حي معلق (غير ملغى، مؤجل فقط).
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists('hossam_wpml_url') ) {
    function hossam_wpml_url( string $slug, string $fallback = '' ): string {
        static $cache = [];
        $blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
        $key = $blog . "\0" . $slug;
        if ( isset($cache[$key]) ) return $cache[$key];
        $page = get_page_by_path( $slug );
        $url  = $page
            ? apply_filters( 'wpml_permalink', get_permalink( $page->ID ), null, true, null )
            : home_url( $fallback ?: '/' . $slug . '/' );
        return $cache[$key] = (string) $url;
    }
}
if ( ! function_exists('hossam_wpml_all_paths') ) {
    function hossam_wpml_all_paths( string $slug ): array {
        $paths = [];
        $page  = get_page_by_path( $slug );
        if ( ! $page ) return [ '/' . $slug . '/' ];
        if ( ! defined('ICL_SITEPRESS_VERSION') ) {
            $p = parse_url( get_permalink( $page->ID ), PHP_URL_PATH );
            return $p ? [ $p ] : [ '/' . $slug . '/' ];
        }
        $langs = (array) apply_filters( 'wpml_active_languages', null, [] );
        foreach ( $langs as $lang ) {
            $code = $lang['language_code'] ?? '';
            if ( ! $code ) continue;
            $tr_id = apply_filters( 'wpml_object_id', $page->ID, 'page', false, $code );
            if ( $tr_id ) {
                $p = parse_url( get_permalink( $tr_id ), PHP_URL_PATH );
                if ( $p ) $paths[] = $p;
            }
        }
        return array_unique( $paths ) ?: [ '/' . $slug . '/' ];
    }
}
if ( ! function_exists('hossam_login_url') ) {
    function hossam_login_url(): string { return hossam_wpml_url('login'); }
}
if ( ! function_exists('hossam_dashboard_url') ) {
    /**
     * B7-02 (معمارية §7.5): الرابط من permalink الصفحة المملوكة
     * (option hal_frontend_dashboard_page_id — الكاتب الوحيد Installer)
     * ثم عبر adapter الترجمة (wpml_permalink). غياب هوية صالحة
     * يعيد جذر الموقع الآمن، دون البحث عن صفحة بالـslug.
     */
    function hossam_dashboard_url(): string {
        static $cache = [];
        $blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
        if ( array_key_exists( $blog, $cache ) ) {
            return $cache[ $blog ];
        }
        $page_id = absint( get_option( 'hal_frontend_dashboard_page_id', 0 ) );
        if ( $page_id > 0 ) {
            $post = get_post( $page_id );
            if ( $post instanceof WP_Post && 'page' === $post->post_type && in_array( $post->post_status, array( 'publish', 'private' ), true ) ) {
                $permalink = get_permalink( $page_id );
                if ( is_string( $permalink ) && '' !== $permalink ) {
                    return $cache[ $blog ] = hossam_wpml_permalink( $permalink );
                }
            }
        }
        return $cache[ $blog ] = home_url( '/' );
    }
}
if ( ! function_exists('hossam_wpml_url_by_id') ) {
    function hossam_wpml_url_by_id( int $post_id ): string {
        static $cache = [];
        $blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
        $key = $blog . "\0" . $post_id;
        if ( isset( $cache[$key] ) ) return $cache[$key];
        return $cache[$key] = (string) apply_filters( 'wpml_permalink', get_permalink( $post_id ), null, true, null );
    }
}

if ( ! function_exists( 'hossam_wpml_get_active_languages' ) ) {
	/** @return array<string,array<string,mixed>> */
	function hossam_wpml_get_active_languages(): array {
		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return [];
		}
		$languages = apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] );

		return is_array( $languages ) ? $languages : [];
	}
}

if ( ! function_exists( 'hossam_wpml_get_current_language' ) ) {
	function hossam_wpml_get_current_language(): string {
		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return '';
		}
		$language = apply_filters( 'wpml_current_language', null );

		return is_string( $language ) ? sanitize_key( $language ) : '';
	}
}

if ( ! function_exists( 'hossam_wpml_permalink' ) ) {
	function hossam_wpml_permalink( string $url ): string {
		if ( '' === $url || ! defined( 'ICL_SITEPRESS_VERSION' ) || ! has_filter( 'wpml_permalink' ) ) {
			return $url;
		}

		$translated = apply_filters( 'wpml_permalink', $url, null, true, null );
		return is_string( $translated ) && '' !== $translated ? $translated : $url;
	}
}

if ( ! function_exists( 'hossam_wpml_is_rtl' ) ) {
	function hossam_wpml_is_rtl(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' )
			? (bool) apply_filters( 'wpml_is_rtl', false )
			: is_rtl();
	}
}

if ( ! function_exists( 'hossam_wpml_get_post_language' ) ) {
	function hossam_wpml_get_post_language( int $post_id ): string {
		if ( $post_id < 1 || ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return '';
		}
		$details = apply_filters( 'wpml_post_language_details', null, $post_id );
		$language = is_array( $details ) && isset( $details['language_code'] ) && is_string( $details['language_code'] )
			? $details['language_code']
			: '';

		return sanitize_key( $language );
	}
}

if ( ! function_exists( 'hossam_wpml_get_translation_id' ) ) {
	function hossam_wpml_get_translation_id( int $post_id, string $language_code ): int {
		if ( $post_id < 1 || '' === $language_code || ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return 0;
		}

		return absint( apply_filters( 'wpml_object_id', $post_id, 'post', false, $language_code ) );
	}
}

if ( ! function_exists( 'hossam_wpml_get_element_trid' ) ) {
	function hossam_wpml_get_element_trid( int $post_id ): int {
		return $post_id > 0 && defined( 'ICL_SITEPRESS_VERSION' )
			? absint( apply_filters( 'wpml_element_trid', null, $post_id, 'post_post' ) )
			: 0;
	}
}

if ( ! function_exists( 'hossam_wpml_get_element_language_code' ) ) {
	function hossam_wpml_get_element_language_code( int $post_id ): string {
		if ( $post_id < 1 || ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return '';
		}
		$language = apply_filters( 'wpml_element_language_code', null, [
			'element_id'   => $post_id,
			'element_type' => 'post_post',
		] );

		return is_string( $language ) ? sanitize_key( $language ) : '';
	}
}

if ( ! function_exists( 'hossam_wpml_verify_element_link' ) ) {
	/**
	 * v1.0.6 §6 — mandatory two-contract read-back for a freshly linked element.
	 *
	 * Reads wpml_element_language_details (trid + language + source language)
	 * then wpml_get_element_translations and requires the target-language key
	 * to carry this element with the expected source. Any mismatch is false.
	 *
	 * WPML absent means the link cannot exist (false). WPML present but the
	 * official read-back contracts unregistered in this runtime is also
	 * false — no success before a match (§4/§6); the caller deletes the
	 * created draft with verification. Stubs alone never prove a live
	 * integration.
	 */
	function hossam_wpml_verify_element_link( int $post_id, int $trid, string $language_code, string $source_language ): bool {
		$language_code   = sanitize_key( $language_code );
		$source_language = sanitize_key( $source_language );
		if ( $post_id < 1 || $trid < 1 || '' === $language_code || ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return false;
		}
		if ( ! has_filter( 'wpml_element_language_details' ) || ! has_filter( 'wpml_get_element_translations' ) ) {
			return false;
		}
		$element_type  = 'post_post';
		$filtered_type = apply_filters( 'wpml_element_type', 'post_post' );
		if ( is_string( $filtered_type ) && '' !== $filtered_type ) {
			$element_type = $filtered_type;
		}
		$details = apply_filters( 'wpml_element_language_details', null, [ 'element_id' => $post_id, 'element_type' => $element_type ] );
		if ( is_object( $details ) ) {
			$details = get_object_vars( $details );
		}
		if ( ! is_array( $details ) ) {
			return false;
		}
		$details_trid   = isset( $details['trid'] ) ? (int) $details['trid'] : 0;
		$details_lang   = isset( $details['language_code'] ) && is_string( $details['language_code'] ) ? sanitize_key( $details['language_code'] ) : '';
		$details_source = isset( $details['source_language_code'] ) && is_string( $details['source_language_code'] ) ? sanitize_key( $details['source_language_code'] ) : '';
		if ( $details_trid !== $trid || $details_lang !== $language_code ) {
			return false;
		}
		if ( '' !== $source_language && $details_source !== $source_language ) {
			return false;
		}
		$translations = apply_filters( 'wpml_get_element_translations', null, $trid, $element_type );
		if ( ! is_array( $translations ) || ! isset( $translations[ $language_code ] ) ) {
			return false;
		}
		$entry = $translations[ $language_code ];
		if ( is_object( $entry ) ) {
			$entry = get_object_vars( $entry );
		}
		if ( ! is_array( $entry ) ) {
			return false;
		}
		$entry_id = 0;
		if ( isset( $entry['element_id'] ) ) {
			$entry_id = (int) $entry['element_id'];
		} elseif ( isset( $entry['translation_id'] ) ) {
			$entry_id = (int) $entry['translation_id'];
		}
		$entry_source = isset( $entry['source_language_code'] ) && is_string( $entry['source_language_code'] ) ? sanitize_key( $entry['source_language_code'] ) : '';
		if ( $entry_id !== $post_id ) {
			return false;
		}
		if ( '' !== $source_language && '' !== $entry_source && $entry_source !== $source_language ) {
			return false;
		}
		return true;
	}
}

if ( ! function_exists( 'hossam_wpml_set_element_language' ) ) {
	function hossam_wpml_set_element_language( int $post_id, int $trid, string $language_code, string $source_language ): bool {
		if ( $post_id < 1 || $trid < 1 || '' === $language_code || ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return false;
		}
		$language_code   = sanitize_key( $language_code );
		$source_language = sanitize_key( $source_language );
		// v1.0.6 §6: element_type is normalised through the official filter
		// (WPML maps 'post' to 'post_post'); the current 'post_post' literal
		// passes through unchanged. Never pass trid=false (it would fork trids
		// and drop the existing relations) — $trid stays the guarded int.
		$element_type  = 'post_post';
		$filtered_type = apply_filters( 'wpml_element_type', 'post_post' );
		if ( is_string( $filtered_type ) && '' !== $filtered_type ) {
			$element_type = $filtered_type;
		}
		// Edit path: when the stored details already match, do not re-fire
		// the link action — verify by re-reading and comparing instead.
		if ( has_filter( 'wpml_element_language_details' ) ) {
			$current = apply_filters( 'wpml_element_language_details', null, [ 'element_id' => $post_id, 'element_type' => $element_type ] );
			if ( is_object( $current ) ) {
				$current = get_object_vars( $current );
			}
			if ( is_array( $current ) ) {
				$current_trid   = isset( $current['trid'] ) ? (int) $current['trid'] : 0;
				$current_lang   = isset( $current['language_code'] ) && is_string( $current['language_code'] ) ? sanitize_key( $current['language_code'] ) : '';
				$current_source = isset( $current['source_language_code'] ) && is_string( $current['source_language_code'] ) ? sanitize_key( $current['source_language_code'] ) : '';
				if ( $current_trid === $trid && $current_lang === $language_code && ( '' === $source_language || $current_source === $source_language ) ) {
					return hossam_wpml_verify_element_link( $post_id, $trid, $language_code, $source_language );
				}
			}
		}
		do_action( 'wpml_set_element_language_details', [
			'element_id'           => $post_id,
			'element_type'         => $element_type,
			'trid'                 => $trid,
			'language_code'        => $language_code,
			'source_language_code' => $source_language,
		] );

		return hossam_wpml_verify_element_link( $post_id, $trid, $language_code, $source_language );
	}
}

if ( ! function_exists( 'hossam_wpml_term_id_for_language' ) ) {
	/**
	 * v1.0.6 §6 — resolve a category term id into a target language through
	 * the official wpml_object_id filter with return_original_if_missing=false.
	 * Returns the matching term id, or 0 when it cannot be resolved. The caller
	 * requests the default language explicitly with a second call and verifies
	 * the result language before accepting it. The default language is read
	 * via apply_filters('wpml_default_language', null) only.
	 */
	function hossam_wpml_term_id_for_language( int $term_id, string $lang ): int {
		$lang = sanitize_key( $lang );
		if ( $term_id < 1 || '' === $lang || ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return 0;
		}
		if ( ! has_filter( 'wpml_object_id' ) ) {
			return 0;
		}
		return absint( apply_filters( 'wpml_object_id', $term_id, 'category', false, $lang ) );
	}
}
if ( ! function_exists('hossam_resolve_site_section') ) {
    function hossam_resolve_site_section( string $key, array $slug_candidates = [] ): ?array {

        switch ( $key ) {
            case 'home':
                $id = ( 'page' === get_option('show_on_front') ) ? (int) get_option('page_on_front') : 0;
                return [ 'url' => $id ? hossam_wpml_url_by_id($id) : home_url('/') ];

            case 'blog':
                $id = ( 'page' === get_option('show_on_front') ) ? (int) get_option('page_for_posts') : 0;
                return [ 'url' => $id ? hossam_wpml_url_by_id($id) : (string) get_post_type_archive_link('post') ];

            case 'privacy':
                $id = (int) get_option('wp_page_for_privacy_policy');
                return $id ? [ 'url' => hossam_wpml_url_by_id($id) ] : null;

            case 'shop':
                $id = function_exists('wc_get_page_id') ? wc_get_page_id('shop') : 0;
                return ( $id > 0 ) ? [ 'url' => hossam_wpml_url_by_id($id) ] : null;
        }

        $tagged = get_posts([
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'meta_key'       => '_hossam_site_section',
            'meta_value'     => $key,
            'fields'         => 'ids',
        ]);
        if ( ! empty( $tagged ) ) return [ 'url' => hossam_wpml_url_by_id( (int) $tagged[0] ) ];

        $candidates = apply_filters( 'hossam_site_section_slugs', $slug_candidates, $key );
        foreach ( $candidates as $slug ) {
            $page = get_page_by_path( $slug );
            if ( $page ) return [ 'url' => hossam_wpml_url_by_id( $page->ID ) ];
        }

        return null;
    }
}

if ( ! function_exists( 'hossam_get_translation_status' ) ) {
	/**
	 * حالة الترجمة الكاملة لمقال عبر كل اللغات النشطة — رسمي 100%، عبر
	 * apply_filters('wpml_get_element_translations', ...) الموثَّقة رسميًا.
	 * لا استعلام SQL، لا API خارجي إضافي.
	 *
	 * @param int $post_id
	 * @return array<string,array> مفتاحه كود اللغة؛ كل عنصر يحتوي
	 *   translation_id, language_code, element_id, source_language_code,
	 *   original (bool), post_title, post_status — كما توثِّقه wpml.org رسميًا.
	 */
	function hossam_get_translation_status( int $post_id ): array {
		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return [];
		}

		$trid = apply_filters( 'wpml_element_trid', null, $post_id, 'post_post' );
		if ( ! $trid ) {
			return [];
		}

		$translations = apply_filters( 'wpml_get_element_translations', null, $trid, 'post_post' );

		return is_array( $translations ) ? $translations : [];
	}
}
