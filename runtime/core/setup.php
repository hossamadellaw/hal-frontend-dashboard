<?php
/**
 * runtime/core/setup.php
 * ══════════════════════════════════════════════════════════════
 * الدور: البنية التحتية المشتركة المملوكة للـrelease — اكتشاف صفحة
 * Dashboard، مسارات الأصول من سياق الـrelease (RUNTIME_ROOT/RUNTIME_URL)،
 * تحميل CSS/JS، والحراسات التى تعمل site-wide.
 *
 * المصدر: نقل حرفي 1:1 من legacy mu-plugins/hossam-dashboard/core/setup.php
 * (الدفعة 2 من HAL Frontend Dashboard). التعديلات المصرَّح بها:
 *   - hossam_asset_path() / hossam_asset_uri(): حُذف كامل منطق child theme
 *     (get_stylesheet_directory/get_template_directory/uploads/dashboard-assets)
 *     واستُبدل بسياق الـrelease حصرًا عبر HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT
 *     وHAL_FRONTEND_DASHBOARD_RUNTIME_URL (يُعرِّفهما loader-core.php قبل
 *     تحميل runtime/bootstrap.php).
 *   - B2-03 (تدقيق الدفعة 2): حُذف wp_enqueue_style('astra-parent-style')
 *     عبر get_template_directory_uri() من كتلة enqueue الخاصة
 *     بـDashboard — لا اعتماد لأصل Dashboard على القالب؛ الأصول
 *     مملوكة للـrelease حصرًا، وبدون تنفيذ دفعة الأصول كاملة ولا
 *     إعادة تصميم (إعادة التحقق البصري عند بوابة الدفعة 9).
 *   - B2-U3 (تدقيق الدفعة 2): hossam_integration_registry_key() صار
 *     مفتاح الـtransient مقيَّدًا بمعرّف الـrelease — تبديل release
 *     يتيمة القيمة القديمة تلقائيًا (TTL يمسحها) بدل الاعتماد على
 *     أحداث plugins فقط (activated/deactivated/upgrader).
 *   - الدفعة 7 (§7.5): hossam_is_dashboard() صارت تستخدم predicate
 *     HAL_Frontend_Dashboard_Template_Controller::is_target_query()
 *     (صفحة المالك ID + ترجمات WPML) بدل is_page('dashboard')/
 *     is_page_template('page-dashboard.php') — fail-closed؛ المستهلكون
 *     (enqueue/body classes/wp hook) يستهلكون predicate الـtemplate
 *     نفسه فلا تسرب assets إلى صفحات أخرى.
 *
 * ملاحظات تصحيح تاريخية (ملخَّصة من المصدر):
 *   - LiteSpeed no-cache حارس site-wide أُلحق بهذا الملف تاريخيًا.
 *   - استدعاءات hossam_wpml_all_paths()/hossam_wpml_is_rtl() (adapters/wpml.php،
 *     الدفعة 4) محمية بحارس function_exists أو مؤجلة إلى callback time
 *     (init/wp_enqueue_scripts) حيث تكون adapters محمَّلة قبل التنفيذ.
 *   - hossam_dashboard_url() معرَّفة في adapters/wpml.php (خارج هذه الدفعة)؛
 *     استدعاءاتها هنا إما داخل callbacks تُنفَّذ وقت الطلب (init/admin_init/
 *     plugins_loaded/login_redirect) — أي بعد اكتمال تحميل adapters — أو
 *     محمية بحارس function_exists في مسار تعريف الدالة.
 *   - wp_localize_script يستهلك hossam_get_i18n_strings() من core/i18n.php.
 *   - التعليق التوثيقي التاريخي الطويل للـTODO لا يُنقل — هذا الرأس موجز.
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'hossam_ai_get_runtime_profile' ) ) {
	/**
	 * Resolve the environment-owned AI runtime profile without defaults.
	 *
	 * Define HOSSAM_AI_RUNTIME_PROFILE as an array in wp-config.php/the
	 * environment, or register the hossam_ai_runtime_profile filter from
	 * trusted PHP before this runtime is loaded. Options and UI are not
	 * accepted configuration sources. Missing or invalid data fails closed.
	 *
	 * @return array<string,int>|WP_Error
	 */
	function hossam_ai_get_runtime_profile() {
		// Per-blog snapshot (multisite isolation): a filter callback may
		// legitimately vary by blog; single-site behavior is identical
		// (one constant key).
		static $snapshots = array();
		$blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;

		if ( array_key_exists( $blog, $snapshots ) ) {
			return $snapshots[ $blog ];
		}

		$profile = defined( 'HOSSAM_AI_RUNTIME_PROFILE' ) ? HOSSAM_AI_RUNTIME_PROFILE : null;
		$profile = apply_filters( 'hossam_ai_runtime_profile', $profile );

		if ( ! is_array( $profile ) ) {
			$snapshots[ $blog ] = new WP_Error(
				'hossam_ai_runtime_profile_unavailable',
				__( 'AI assistance is unavailable in this environment.', 'astra-child' )
			);
			return $snapshots[ $blog ];
		}

		$bounds = [
			'per_minute'          => [ 1, 10000 ],
			'per_day'             => [ 1, 1000000 ],
			'concurrent'          => [ 1, 1000 ],
			'max_attempts'        => [ 1, 100 ],
			'stale_pending'       => [ 1, 604800 ],
			'processing_deadline' => [ 1, 86400 ],
			'wp_ai_client_timeout' => [ 5, 300 ],
			'direct_key_timeout'  => [ 5, 300 ],
		];
		$normalized = [];
		foreach ( $bounds as $key => [ $minimum, $maximum ] ) {
			if ( ! array_key_exists( $key, $profile )
				|| is_bool( $profile[ $key ] )
				|| false === filter_var( $profile[ $key ], FILTER_VALIDATE_INT ) ) {
				$snapshots[ $blog ] = new WP_Error(
					'hossam_ai_runtime_profile_invalid',
					__( 'AI assistance is unavailable in this environment.', 'astra-child' )
				);
				return $snapshots[ $blog ];
			}

			$value = (int) $profile[ $key ];
			if ( $value < $minimum || $value > $maximum ) {
				$snapshots[ $blog ] = new WP_Error(
					'hossam_ai_runtime_profile_invalid',
					__( 'AI assistance is unavailable in this environment.', 'astra-child' )
				);
				return $snapshots[ $blog ];
			}

			$normalized[ $key ] = $value;
		}

		$maximum_timeout = max( $normalized['wp_ai_client_timeout'], $normalized['direct_key_timeout'] );
		if ( $normalized['processing_deadline'] < $maximum_timeout + 30 ) {
			$snapshots[ $blog ] = new WP_Error(
				'hossam_ai_runtime_profile_invalid',
				__( 'AI assistance is unavailable in this environment.', 'astra-child' )
			);
			return $snapshots[ $blog ];
		}

		$snapshots[ $blog ] = $normalized;
		return $snapshots[ $blog ];
	}
}

/**
 * سجّل قدرات التكاملات؛ لا يُنشأ قبل اكتمال تحميل الإضافات العادية.
 *
 * B2-U3 (حسم invalidation عند تبديل release): المفتاح مقيَّد بمعرّف
 * الـrelease، فكل إصدار يبني ويسجّل كاشه الخاص ويتيمة قيم الإصدارات
 * السابقة تلقائيًا حتى تنقضي مدة TTL — بلا افتراض حدث switch ولا
 * إضافة أحداث جديدة على ملفات الدفعة 1 المغلقة. أحداث
 * activated/deactivated/upgrader تبقى كما هي للتبديل داخل نفس
 * الـrelease.
 */
if ( ! function_exists( 'hossam_integration_registry_key' ) ) {
	function hossam_integration_registry_key(): string {
		$release = defined( 'HAL_FRONTEND_DASHBOARD_RELEASE_ID' )
			? (string) HAL_FRONTEND_DASHBOARD_RELEASE_ID
			: ( defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION' )
				? (string) HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION
				: 'unknown' );

		return 'hossam_integration_capabilities_v1_' . $release;
	}
}

if ( ! function_exists( 'hossam_is_known_plugin_active' ) ) {
	/**
	 * @param string[] $plugin_files Relative plugin file paths.
	 */
	function hossam_is_known_plugin_active( array $plugin_files ): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( $plugin_files as $plugin_file ) {
			if ( is_plugin_active( $plugin_file )
				|| ( is_multisite()
					&& function_exists( 'is_plugin_active_for_network' )
					&& is_plugin_active_for_network( $plugin_file ) ) ) {
				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'hossam_get_known_plugin_version' ) ) {
	/**
	 * @param string[] $plugin_files Relative plugin file paths.
	 */
	function hossam_get_known_plugin_version( array $plugin_files ): string {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( $plugin_files as $plugin_file ) {
			$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
			if ( ! is_readable( $plugin_path ) ) {
				continue;
			}

			$plugin_data = get_plugin_data( $plugin_path, false, false );
			if ( ! empty( $plugin_data['Version'] ) ) {
				return sanitize_text_field( (string) $plugin_data['Version'] );
			}
		}

		return '';
	}
}

if ( ! function_exists( 'hossam_get_ai_integration_decision' ) ) {
	/**
	 * Build the AI health decision on every request without transient caching.
	 *
	 * @return array{status:string,plugin_active:bool,version:string,capabilities:array<string,bool>,reason:string,checked_at:string}
	 */
	function hossam_get_ai_integration_decision(): array {
		$capabilities = [
			'wp_ai_client' => function_exists( 'hossam_ai_wp_client_supported' ) && hossam_ai_wp_client_supported(),
			'direct_key'   => function_exists( 'hossam_ai_direct_key_ready' ) && hossam_ai_direct_key_ready(),
		];
		$strategy = function_exists( 'hossam_ai_resolve_strategy' )
			? hossam_ai_resolve_strategy()
			: null;
		$available = is_string( $strategy ) && in_array( $strategy, [ 'wp_ai_client', 'direct_key' ], true );

		return [
			'status'        => $available ? 'available' : 'unavailable',
			'plugin_active' => $available,
			'version'       => '',
			'capabilities'  => $capabilities,
			'reason'        => $available ? '' : 'AI assistance is unavailable; posts remain available.',
			'checked_at'    => gmdate( 'c' ),
		];
	}
}

if ( ! function_exists( 'hossam_get_integration_capabilities' ) ) {
	/**
	 * @return array<string,array<string,mixed>>
	 */
	function hossam_get_integration_capabilities(): array {
		$cache_key = hossam_integration_registry_key();
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			unset( $cached['ai'] );
			$cached['ai'] = hossam_get_ai_integration_decision();
			return $cached;
		}

		$plugins = [
			'amelia'          => [ 'ameliabooking/ameliabooking.php' ],
			'wpml'            => [ 'sitepress-multilingual-cms/sitepress.php' ],
			'rank_math'       => [ 'seo-by-rank-math/rank-math.php' ],
			'woocommerce'     => [ 'woocommerce/woocommerce.php' ],
			'ultimate_member' => [ 'ultimate-member/ultimate-member.php' ],
			'frontend_admin'  => [
				'acf-frontend-form-element/acf-frontend-form-element.php',
				'acf-frontend-form-element-pro/acf-frontend-form-element.php',
			],
		];

		$amelia_capabilities = [
			'bookings_shortcode' => shortcode_exists( 'ameliaemployeepanel' ),
			'elite_api_secret'   => defined( 'HOSSAM_AMELIA_API_KEY' ) && '' !== HOSSAM_AMELIA_API_KEY,
			'elite_api_client'   => function_exists( 'hossam_amelia_api_request' ),
		];
		$wpml_capabilities = [
			'language_urls' => defined( 'ICL_SITEPRESS_VERSION' ) && has_filter( 'wpml_permalink' ),
			'languages'     => defined( 'ICL_SITEPRESS_VERSION' ) && has_filter( 'wpml_active_languages' ),
		];
		$rank_math_capabilities = [
			'plugin_contract' => defined( 'RANK_MATH_VERSION' ),
			// v1.0.6 §8.2 (القرار الموحد أ/ج): كشف وقت التشغيل بدل القيمة
			// الثابتة — فيُفتح بوابتا القالب (seo.php) والنقطة (ajax/seo.php)
			// تلقائيًا معًا عند توافر Rank Math؛ مفتاح المالك rank_math_seo
			// يبقى بوابة إظهار الواجهة.
			'verified_fields' => class_exists( 'RankMath\\Post' ) && method_exists( 'RankMath\\Post', 'get_meta' ),
		];
		$woocommerce_capabilities = [
			'orders'          => function_exists( 'wc_get_orders' ),
			'products'        => function_exists( 'wc_get_products' ),
			'payment_methods' => class_exists( 'WC_Payment_Gateways' ),
			'tokens'          => class_exists( 'WC_Payment_Tokens' ),
		];
		$ultimate_member_capabilities = [
			'login_hook' => function_exists( 'um_get_option' ),
			'profile_ui' => shortcode_exists( 'ultimatemember' ),
		];
		$frontend_admin_capabilities = [
			'core_crud' => function_exists( 'hossam_create_article' )
				&& function_exists( 'hossam_update_article' )
				&& function_exists( 'hossam_trash_article' ),
			'shortcode' => shortcode_exists( 'frontend_admin' ),
		];
		$registry = [
			'amelia' => hossam_build_integration_capability(
				hossam_is_known_plugin_active( $plugins['amelia'] ),
				hossam_get_known_plugin_version( $plugins['amelia'] ),
				$amelia_capabilities,
				'Amelia bookings are unavailable until the required shortcode or Elite API client is ready.'
			),
			'wpml' => hossam_build_integration_capability(
				hossam_is_known_plugin_active( $plugins['wpml'] ),
				hossam_get_known_plugin_version( $plugins['wpml'] ),
				$wpml_capabilities,
				'Language controls are unavailable; base content remains available without guessed language data.'
			),
			'rank_math' => hossam_build_integration_capability(
				hossam_is_known_plugin_active( $plugins['rank_math'] ),
				hossam_get_known_plugin_version( $plugins['rank_math'] ),
				$rank_math_capabilities,
				'SEO writing is unavailable until the required Rank Math fields are verified in this environment.'
			),
			'woocommerce' => hossam_build_integration_capability(
				hossam_is_known_plugin_active( $plugins['woocommerce'] ),
				hossam_get_known_plugin_version( $plugins['woocommerce'] ),
				$woocommerce_capabilities,
				'Finance and store features are unavailable without the required WooCommerce APIs.'
			),
			'ultimate_member' => hossam_build_integration_capability(
				hossam_is_known_plugin_active( $plugins['ultimate_member'] ),
				hossam_get_known_plugin_version( $plugins['ultimate_member'] ),
				$ultimate_member_capabilities,
				'Ultimate Member profile features are unavailable; WordPress login and guards remain active.'
			),
			'frontend_admin' => hossam_build_integration_capability(
				hossam_is_known_plugin_active( $plugins['frontend_admin'] ),
				hossam_get_known_plugin_version( $plugins['frontend_admin'] ),
				$frontend_admin_capabilities,
				'Article editing is unavailable until Core CRUD or the documented Frontend Admin shortcode is ready.'
			),
		];

		set_transient( $cache_key, $registry, 5 * MINUTE_IN_SECONDS );
		$registry['ai'] = hossam_get_ai_integration_decision();

		return $registry;
	}
}

if ( ! function_exists( 'hossam_get_integration_decision' ) ) {
	/**
	 * Single read accessor for panels/endpoints to consume the capability
	 * registry (بند تمهيدي 3: قرار قدرة لكل panel/endpoint من السجل نفسه:
	 * available / limited / unavailable). Unknown keys degrade safely.
	 *
	 * @return array{status:string,plugin_active:bool,version:string,capabilities:array<string,bool>,reason:string,checked_at:string}
	 */
	function hossam_get_integration_decision( string $integration ): array {
		$defaults = [
			'status'       => 'unknown',
			'plugin_active' => false,
			'version'      => '',
			'capabilities' => [],
			'reason'       => '',
			'checked_at'   => '',
		];

		$registry = hossam_get_integration_capabilities();
		if ( isset( $registry[ $integration ] ) && is_array( $registry[ $integration ] ) ) {
			return array_merge( $defaults, $registry[ $integration ] );
		}

		return $defaults;
	}
}

if ( ! function_exists( 'hossam_build_integration_capability' ) ) {
	/**
	 * @param array<string,bool> $capabilities
	 * @return array<string,mixed>
	 */
	function hossam_build_integration_capability(
		bool $plugin_active,
		string $version,
		array $capabilities,
		string $unavailable_reason
	): array {
		$available_count = count( array_filter( $capabilities ) );
		$capability_count = count( $capabilities );
		$status = ! $plugin_active || 0 === $available_count
			? 'unavailable'
			: ( $available_count === $capability_count ? 'available' : 'limited' );

		if ( 'unavailable' === $status ) {
			$reason = $unavailable_reason;
		} elseif ( 'limited' === $status ) {
			$missing_capabilities = [];
			foreach ( $capabilities as $capability_name => $capability_ready ) {
				if ( $capability_ready ) {
					continue;
				}
				$name = (string) $capability_name;
				if ( false !== strpos( $name, 'secret' ) || false !== strpos( $name, 'key' ) ) {
					continue;
				}
				$missing_capabilities[] = $name;
			}
			$reason = empty( $missing_capabilities )
				? 'Limited mode: some required capabilities are not ready yet.'
				: sprintf(
					'Limited mode: the following capabilities are not ready yet: %s.',
					implode( ', ', $missing_capabilities )
				);
		} else {
			$reason = '';
		}

		return [
			'status'       => $status,
			'plugin_active' => $plugin_active,
			'version'      => $version,
			'capabilities' => $capabilities,
			'reason'       => $reason,
			'checked_at'   => gmdate( 'c' ),
		];
	}
}

if ( ! function_exists( 'hossam_invalidate_integration_capabilities' ) ) {
	function hossam_invalidate_integration_capabilities(): void {
		delete_transient( hossam_integration_registry_key() );
	}
}

add_action( 'activated_plugin', 'hossam_invalidate_integration_capabilities' );
add_action( 'deactivated_plugin', 'hossam_invalidate_integration_capabilities' );
add_action( 'upgrader_process_complete', 'hossam_invalidate_integration_capabilities', 10, 2 );
add_action( 'wp_loaded', 'hossam_get_integration_capabilities', 20 );

// ══════════════════════════════════════════════════════════════
// جدولة cron المخصصة عبر cron_schedules — تُسجَّل هنا فى core قبل أى
// استعمال لِـ`hossam_every_five_minutes` للكسح الدوري لجدول مهام AI
// (المسجَّل والمُجدوَل من ajax/ai.php). الفلتر يُستدعى وقت الجدولة نفسه
// فلا يعتمد على ترتيب تحميل الملفات.
// ══════════════════════════════════════════════════════════════
add_filter( 'cron_schedules', function ( array $schedules ): array {
	if ( ! isset( $schedules['hossam_every_five_minutes'] ) ) {
		$schedules['hossam_every_five_minutes'] = [
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 Minutes (Hossam Dashboard)', 'astra-child' ),
		];
	}
	return $schedules;
} );

if ( ! function_exists( 'hossam_is_dashboard' ) ) {
    /**
     * Detects the Dashboard page reliably across WPML translations.
     *
     * الدفعة 7 (§7.5): العقد الهدف الوحيد هو صفحة المالك (page ID
     * المملوك عبر Installer) وترجمات WPML المثبتة عبر predicate
     * HAL_Frontend_Dashboard_Template_Controller::is_target_query() —
     * نفس predicate الـtemplate_include حصرًا حتى لا تتسرب assets أو
     * body classes إلى صفحات أخرى. لا اعتماد متبقٍ على
     * is_page('dashboard') ولا is_page_template('page-dashboard.php')
     * ولا slug عام. الفشل مغلق: غياب الـController أو صفحة مملوكة
     * يعيد false (فشل آمن بلا assets ولا قالب).
     *
     * @return bool
     */
    function hossam_is_dashboard(): bool {
        return class_exists( 'HAL_Frontend_Dashboard_Template_Controller' )
            && HAL_Frontend_Dashboard_Template_Controller::is_target_query();
    }
}

if ( ! function_exists('hossam_asset_path') ) {
    /**
     * Release-owned asset path (دفعة 2: استُبدلت مسارات child theme
     * القديمة بسياق الـrelease — لا get_stylesheet_directory ولا
     * uploads/dashboard-assets هنا).
     *
     * @param string $file المسار النسبي للملف.
     * @return string المسار الكامل على الخادم.
     */
    function hossam_asset_path( string $file ): string {
        return HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT . 'assets/' . $file;
    }
}

if ( ! function_exists('hossam_asset_uri') ) {
    /**
     * Release-owned asset URI (دفعة 2: استُبدلت روابط child theme
     * القديمة برابط الـrelease).
     *
     * @param string $file المسار النسبي للملف.
     * @return string الرابط الكامل للملف.
     */
    function hossam_asset_uri( string $file ): string {
        return HAL_FRONTEND_DASHBOARD_RUNTIME_URL . 'assets/' . $file;
    }
}

add_filter( 'login_redirect', function( string $redirect_to, string $request, $user ): string {
    if ( is_wp_error($user) )        return $redirect_to;
    if ( ! $user instanceof WP_User ) return $redirect_to;
    if ( user_can( $user, 'manage_options' ) ) {
        return admin_url();
    }
    return hossam_dashboard_url();
}, 10, 3 );

add_action( 'plugins_loaded', function(): void {
    if ( ! function_exists( 'um_get_option' ) ) return;
    add_filter( 'um_login_redirect_url', function( string $url, int $user_id ): string {
        $user = get_userdata( $user_id );
        if ( ! $user ) return $url;
        if ( user_can( $user, 'manage_options' ) ) {
            return admin_url();
        }
        return hossam_dashboard_url();
    }, 10, 2 );
} );

add_action( 'after_setup_theme', function(): void {
    if ( ! is_admin() && is_user_logged_in() && ! current_user_can('manage_options') ) {
        show_admin_bar( false );
    }
} );

add_action( 'init', function(): void {
    if ( is_admin() ) return;

    $uri             = $_SERVER['REQUEST_URI'] ?? '';
    $protected_paths = ['/dashboard/', '/login/', '/account/', '/profile/', '/register/'];

    if ( function_exists( 'hossam_wpml_all_paths' ) ) {
        $protected_paths = array_merge(
            $protected_paths,
            hossam_wpml_all_paths( 'dashboard' ),
            hossam_wpml_all_paths( 'login' )
        );
    }

    foreach ( $protected_paths as $path ) {
        if ( false !== strpos( $uri, $path ) ) {
            if ( function_exists('litespeed_no_cache') ) {
                litespeed_no_cache();
            }
            if ( ! headers_sent() ) {
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                header('Pragma: no-cache');
                header('X-LiteSpeed-Cache-Control: no-cache');
            }
            break;
        }
    }
} );

add_action( 'admin_init', function(): void {
    if (
        is_user_logged_in()
        && ! current_user_can('manage_options')
        && ! wp_doing_ajax()
        && ! wp_doing_cron()
        && ! ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE )
    ) {
        wp_redirect( hossam_dashboard_url() );
        exit;
    }
} );

add_action( 'wp_enqueue_scripts', function(): void {
    if ( ! hossam_is_dashboard() ) return;

    // B2-03 (معمارية §8.3/§13 وقرار المالك §2.1): حُذف enqueue
    // style.css للقالب الأب عبر get_template_directory_uri() — كتلة
    // أصول Dashboard مملوكة للـrelease حصرًا (dashboard.css وmodules
    // عبر hossam_asset_uri()). إعادة التحقق البصري للتكافؤ موقعها
    // بوابة الدفعة 9 (مقارنة بصرية لكل panel) وليست إعادة تصميم هنا.

    $css_path        = hossam_asset_path( 'css/dashboard.css' );
    $js_path         = hossam_asset_path( 'js/dashboard.js' );
    $members_js_path = hossam_asset_path( 'js/modules/members.js' );
    $posts_js_path   = hossam_asset_path( 'js/modules/posts.js' );
    $uploads_js_path = hossam_asset_path( 'js/modules/uploads.js' );
    $bookings_js_path = hossam_asset_path( 'js/modules/bookings.js' );
    $finance_js_path = hossam_asset_path( 'js/modules/finance.js' );
    $inbox_js_path   = hossam_asset_path( 'js/modules/inbox.js' );
    $store_js_path   = hossam_asset_path( 'js/modules/store.js' );
    $ai_js_path      = hossam_asset_path( 'js/modules/ai.js' );

    wp_enqueue_style(
        'hossam-dashboard-css',
        hossam_asset_uri( 'css/dashboard.css' ),
        [],
        file_exists( $css_path ) ? (string) filemtime( $css_path ) : '1.0.0'
    );

    wp_enqueue_script(
        'hossam-dashboard-js',
        hossam_asset_uri( 'js/dashboard.js' ),
        [],
        file_exists( $js_path ) ? (string) filemtime( $js_path ) : '1.0.0',
        true
    );

    wp_enqueue_script(
        'hossam-dashboard-members',
        hossam_asset_uri( 'js/modules/members.js' ),
        [ 'hossam-dashboard-js' ],
        file_exists( $members_js_path ) ? (string) filemtime( $members_js_path ) : '1.0.0',
        true
    );

    // v1.0.6 P1a: منتقي الوسائط القياسي للصورة المميزة — قبل تسجيل
    // hossam-dashboard-posts مباشرة حتى تُطبع أصول الوسائط قبلها.
    // على صفحات الداشبورد فقط (الحارس أعلاه يمنع أي تحميل site-wide)،
    // ومشروط بـupload_files كواجهة الصورة نفسها. التفصيل المحسوم: شرط
    // القدرة وحده دون تقييد بلوحة، لأن التنقل بين اللوحات يتم بالـJS
    // داخل الصفحة الواحدة (تقييد ?panel= خادميًا كان سيكسر التنقل اللاحق).
    if ( current_user_can( 'upload_files' ) ) {
        wp_enqueue_media();
    }

    // v1.0.6 §9: المحرر الغني — حزم Block Editor الرسمية على لوحة HAL حصرًا.
    // قرار المالك محسوم: إظهار وتغليف لا بناء — تركيب مكونات Gutenberg
    // العلنية (BlockEditorProvider + BlockCanvas + Inserter + BlockTools)
    // داخل غلاف HAL، بلا محرك/كتل بديلة وبلا Experimental.
    // البوابات (كلها fail-closed):
    //   - مفتاح المالك rich_editor (نمط is_feature_enabled) بوابة إظهار؛
    //     المفتاح خارج FEATURES حاليًا فيُغلق العرض حتى تخصيصه من مالك
    //     الإعدادات — والـtextarea يبقى حقل النموذج.
    //   - مسار تحرير مسموح واحد على الأقل: edit_posts (إنشاء — ولو مُنع
    //     المستخدم من تحرير مقال آخر) أو edit_post لمقال بعينه مطلوب
    //     تحريره (post_id من الطلب). لا اعتماد على panel وحده لأن التنقل
    //     بين اللوحات يجري داخل الصفحة.
    //   - upload_files شرط مستقل للوسائط فقط — لا يمنع تحميل بقية الواجهة.
    // فحوص الخادم (wordpress-posts.php:38-40,106-113) إلزامية باقية؛ إظهار
    // الواجهة أو تحميل حزمها لا يمنح صلاحية ولا يستبدلها.
    // الحزم: handles مسجلة من WordPress للنسخة المثبتة (لا تثبيت حزم ولا
    // أرقام أسطر نواة) مع حراسة التسجيل wp_script_is/wp_style_is؛ ترتيب
    // الاعتماد الصحيح تحسمه تبعيات التسجيل الرسمية. لا تُطبع الحزمة الثقيلة
    // (block-editor.js ≈2.7MB) إلا لمن يملك مسار تحرير (allowed أدناه).
    // إحالة script-loader السابقة (edit-post/editor/edit-widgets) تبعيات
    // أنماط لا تسجيل JavaScript — لا تُستخدم هنا.
    $hossam_rich_reason  = 'feature-disabled';
    $hossam_rich_allowed = false;
    if ( class_exists( 'HAL_Frontend_Dashboard_Settings_Repository' )
        && method_exists( 'HAL_Frontend_Dashboard_Settings_Repository', 'is_feature_enabled' )
        && HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'rich_editor' ) ) {
        $hossam_rich_post_id = isset( $_REQUEST['post_id'] ) ? (int) $_REQUEST['post_id'] : 0;
        if ( current_user_can( 'edit_posts' ) ) {
            $hossam_rich_allowed = true;
            $hossam_rich_reason  = '';
        } elseif ( $hossam_rich_post_id > 0 && current_user_can( 'edit_post', $hossam_rich_post_id ) ) {
            $hossam_rich_allowed = true;
            $hossam_rich_reason  = '';
        } else {
            $hossam_rich_reason = 'no-edit-path';
        }
    }
    // الكتل المطلوبة (Table وDetails والوسائط والقوائم والروابط تنسيق نص +
    // Playlist وTabs في 7.1.3) تأتي من التسجيل الرسمي حصرًا عبر
    // registerCoreBlocks في posts.js؛ كتل الإضافات الأخرى لا تُضمَّن تلقائيًا
    // لأن أصولها لا تُحمَّل في HAL. غياب core/playlist أو core/tabs خارج
    // 7.1.3 يُتجاوز ببقية الكتل — UNVERIFIED محليًا (النواة 6.7.1).
    $hossam_rich_handles = array(
        'wp-element', 'wp-data', 'wp-components', 'wp-blocks',
        'wp-block-editor', 'wp-block-library', 'wp-compose',
        'wp-media-utils', 'wp-api-fetch',
    );
    $hossam_rich_critical = array( 'wp-element', 'wp-blocks', 'wp-block-editor' );
    $hossam_rich_skipped  = array();
    if ( $hossam_rich_allowed ) {
        foreach ( $hossam_rich_handles as $hossam_rich_handle ) {
            if ( function_exists( 'wp_script_is' ) && ! wp_script_is( $hossam_rich_handle, 'registered' ) ) {
                $hossam_rich_skipped[] = $hossam_rich_handle;
                continue;
            }
            wp_enqueue_script( $hossam_rich_handle );
        }
        foreach ( array( 'wp-components', 'wp-block-editor', 'wp-block-library' ) as $hossam_rich_style ) {
            if ( function_exists( 'wp_style_is' ) && ! wp_style_is( $hossam_rich_style, 'registered' ) ) {
                continue;
            }
            wp_enqueue_style( $hossam_rich_style );
        }
    }
    $hossam_rich_blocked = array_intersect( $hossam_rich_critical, $hossam_rich_skipped );
    $hossam_rich_enabled = $hossam_rich_allowed && array() === $hossam_rich_blocked;
    if ( $hossam_rich_allowed && ! $hossam_rich_enabled ) {
        $hossam_rich_reason = 'unregistered:' . implode( ',', $hossam_rich_skipped );
    }

    wp_enqueue_script(
        'hossam-dashboard-posts',
        hossam_asset_uri( 'js/modules/posts.js' ),
        [ 'hossam-dashboard-js' ],
        file_exists( $posts_js_path ) ? (string) filemtime( $posts_js_path ) : '1.0.0',
        true
    );

    // v1.0.6 §9: إعدادات المحرر الغني على hossam-dashboard-posts — كائن
    // مستقل hossamRichEditor (hossamAjax أعلاه كما هو بلا تغيير، B9-E3 سليم).
    // enabled=false يعني بقاء الـtextarea ظاهرًا؛ canUpload يعكس upload_files
    // (شرط منفصل للوسائط)؛ REST/Nonce من WordPress لمسار الرفع الرسمي
    // (uploadMedia) — لا endpoint جديد.
    wp_localize_script( 'hossam-dashboard-posts', 'hossamRichEditor', [
        'enabled'   => $hossam_rich_enabled,
        'canUpload' => current_user_can( 'upload_files' ),
        'restRoot'  => function_exists( 'rest_url' ) ? rest_url() : '',
        'restNonce' => wp_create_nonce( 'wp_rest' ),
        'isRtl'     => function_exists( 'hossam_wpml_is_rtl' ) ? hossam_wpml_is_rtl() : is_rtl(),
        'reason'    => $hossam_rich_reason,
    ] );

    wp_enqueue_script(
        'hossam-dashboard-uploads',
        hossam_asset_uri( 'js/modules/uploads.js' ),
        [ 'hossam-dashboard-js' ],
        file_exists( $uploads_js_path ) ? (string) filemtime( $uploads_js_path ) : '1.0.0',
        true
    );

    wp_enqueue_script(
        'hossam-dashboard-bookings',
        hossam_asset_uri( 'js/modules/bookings.js' ),
        [ 'hossam-dashboard-js' ],
        file_exists( $bookings_js_path ) ? (string) filemtime( $bookings_js_path ) : '1.0.0',
        true
    );

    wp_enqueue_script(
        'hossam-dashboard-finance',
        hossam_asset_uri( 'js/modules/finance.js' ),
        [ 'hossam-dashboard-js' ],
        file_exists( $finance_js_path ) ? (string) filemtime( $finance_js_path ) : '1.0.0',
        true
    );

    wp_enqueue_script(
        'hossam-dashboard-inbox',
        hossam_asset_uri( 'js/modules/inbox.js' ),
        [ 'hossam-dashboard-js' ],
        file_exists( $inbox_js_path ) ? (string) filemtime( $inbox_js_path ) : '1.0.0',
        true
    );

    wp_enqueue_script(
        'hossam-dashboard-store',
        hossam_asset_uri( 'js/modules/store.js' ),
        [ 'hossam-dashboard-js' ],
        file_exists( $store_js_path ) ? (string) filemtime( $store_js_path ) : '1.0.0',
        true
    );

    // ربط وحدة AI بواجهة posts بعد تنفيذ الأزرار المشروطة.
    wp_enqueue_script(
        'hossam-dashboard-ai',
        hossam_asset_uri( 'js/modules/ai.js' ),
        [ 'hossam-dashboard-js' ],
        file_exists( $ai_js_path ) ? (string) filemtime( $ai_js_path ) : '1.0.0',
        true
    );

    wp_localize_script( 'hossam-dashboard-js', 'hossamAjax', [
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'hossam_nonce' ),
        'isRtl'   => function_exists( 'hossam_wpml_is_rtl' ) ? hossam_wpml_is_rtl() : is_rtl(),
        'i18n'    => function_exists( 'hossam_get_i18n_strings' ) ? hossam_get_i18n_strings() : [],
    ] );
}, 20 );

add_action( 'wp_enqueue_scripts', function(): void {
    if ( ! hossam_is_dashboard() ) return;
    wp_dequeue_style( 'elementor-frontend' );
    wp_dequeue_script( 'elementor-frontend' );
    wp_dequeue_style( 'elementor-icons' );
    wp_dequeue_style( 'elementor-pro-frontend' );
    wp_dequeue_script( 'elementor-pro-frontend' );
    wp_dequeue_style( 'e-swiper' );
    wp_dequeue_script( 'elementor-common' );
}, 999 );

add_action( 'wp_enqueue_scripts', function(): void {
    if ( ! hossam_is_dashboard() ) return;
    foreach ( ['woocommerce-layout', 'woocommerce-smallscreen', 'woocommerce-general'] as $handle ) {
        wp_dequeue_style( $handle );
    }
}, 999 );

add_filter( 'body_class', function( array $classes ): array {
    if ( hossam_is_dashboard() ) {
        $classes[] = 'dashboard-template';
        $classes[] = 'no-elementor';
    }
    return $classes;
} );

add_action( 'wp', function(): void {
    if ( ! hossam_is_dashboard() ) return;
    remove_action( 'astra_header',       'astra_header_markup' );
    remove_action( 'astra_footer',       'astra_footer_markup' );
    remove_action( 'astra_content_top',  'astra_breadcrumb_markup' );
} );

// Simple Multisite: provision late-joined sites idempotently. The hook
// fires only on multisite; the provisioner loads lazily here (never from
// the Carrier) so single-site load order is untouched. Callback resolves
// at do_action time, after bootstrap has defined the release context.
add_action( 'wp_initialize_site', function( $site ): void {
    if ( ! defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT' ) ) {
        return;
    }
    $id = is_object( $site ) && isset( $site->blog_id ) ? (int) $site->blog_id : (int) $site;
    if ( $id < 1 ) {
        return;
    }
    // The includes/ copy (activation time) and this infrastructure copy
    // (late-joined sites) must never collide in one process: load only
    // when no copy is already present (established shared-source pattern).
    if ( ! class_exists( 'HAL_Frontend_Dashboard_Site_Provisioner', false ) ) {
        require_once HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT . 'infrastructure/class-site-provisioner.php';
    }
    HAL_Frontend_Dashboard_Site_Provisioner::ensure_site_for_blog( $id );
} );
