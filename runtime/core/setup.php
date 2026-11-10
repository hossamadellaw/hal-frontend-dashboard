<?php
/**
 * runtime/core/setup.php
 * ══════════════════════════════════════════════════════════════
 * Role: the release-owned shared infrastructure — Dashboard page
 * detection, asset paths from the release context (RUNTIME_ROOT/RUNTIME_URL),
 * CSS/JS loading, and the site-wide guards.
 *
 * Source: verbatim 1:1 port from legacy mu-plugins/hossam-dashboard/core/setup.php
 * (batch 2 of HAL Frontend Dashboard). The sanctioned modifications:
 *   - hossam_asset_path() / hossam_asset_uri(): the entire child theme logic was removed
 *     (get_stylesheet_directory/get_template_directory/uploads/dashboard-assets)
 *     and replaced exclusively with the release context via HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT
 *     and HAL_FRONTEND_DASHBOARD_RUNTIME_URL (both defined by loader-core.php before
 *     loading runtime/bootstrap.php).
 *   - B2-03 (batch 2 audit): wp_enqueue_style('astra-parent-style')
 *     via get_template_directory_uri() was removed from the Dashboard enqueue
 *     block — no dependency of a Dashboard asset on the theme; the assets
 *     are release-owned exclusively, and without executing the full assets batch nor
 *     a redesign (visual re-verification at the batch 9 gate).
 *   - B2-U3 (batch 2 audit): hossam_integration_registry_key() now has
 *     the transient key bound to the release id — switching a release
 *     orphans the old value automatically (TTL sweeps it) instead of relying on
 *     plugins events only (activated/deactivated/upgrader).
 *   - Batch 7 (§7.5): hossam_is_dashboard() now uses the predicate
 *     HAL_Frontend_Dashboard_Template_Controller::is_target_query()
 *     (owner page ID + WPML translations) instead of is_page('dashboard')/
 *     is_page_template('page-dashboard.php') — fail-closed; the consumers
 *     (enqueue/body classes/wp hook) consume the template predicate
 *     itself, so no assets leak to other pages.
 *
 * Historical fix notes (summarized from the source):
 *   - A LiteSpeed no-cache site-wide guard was historically appended to this file.
 *   - hossam_wpml_all_paths()/hossam_wpml_is_rtl() calls (adapters/wpml.php,
 *     batch 4) are protected by a function_exists guard or deferred to callback time
 *     (init/wp_enqueue_scripts) where the adapters are loaded before execution.
 *   - hossam_dashboard_url() is defined in adapters/wpml.php (outside this batch);
 *     its calls here are either inside callbacks executed at request time (init/admin_init/
 *     plugins_loaded/login_redirect) — i.e. after the adapters finish loading — or
 *     protected by a function_exists guard in the function-definition path.
 *   - wp_localize_script consumes hossam_get_i18n_strings() from core/i18n.php.
 *   - The long historical TODO doc comment is not carried over — this header is concise.
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
				__( 'AI assistance is unavailable in this environment.', 'hal-frontend-dashboard' )
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
					__( 'AI assistance is unavailable in this environment.', 'hal-frontend-dashboard' )
				);
				return $snapshots[ $blog ];
			}

			$value = (int) $profile[ $key ];
			if ( $value < $minimum || $value > $maximum ) {
				$snapshots[ $blog ] = new WP_Error(
					'hossam_ai_runtime_profile_invalid',
					__( 'AI assistance is unavailable in this environment.', 'hal-frontend-dashboard' )
				);
				return $snapshots[ $blog ];
			}

			$normalized[ $key ] = $value;
		}

		$maximum_timeout = max( $normalized['wp_ai_client_timeout'], $normalized['direct_key_timeout'] );
		if ( $normalized['processing_deadline'] < $maximum_timeout + 30 ) {
			$snapshots[ $blog ] = new WP_Error(
				'hossam_ai_runtime_profile_invalid',
				__( 'AI assistance is unavailable in this environment.', 'hal-frontend-dashboard' )
			);
			return $snapshots[ $blog ];
		}

		$snapshots[ $blog ] = $normalized;
		return $snapshots[ $blog ];
	}
}

/**
 * Register the integrations capabilities; it is not created before the regular plugins finish loading.
 *
 * B2-U3 (invalidation resolution on release switch): the key is bound to the
 * release id, so every release builds and registers its own cache and orphans the previous releases'
 * values automatically until the TTL elapses — without assuming a switch event nor
 * adding new events to the closed batch 1 files. The
 * activated/deactivated/upgrader events stay as they are for switches within the same
 * release.
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
			// v1.1.1 (E3): the alignment — the capability reflects the actual dependency source that
			// the resolver honors (env→constant→hal_encrypted via the Registry) instead of
			// the constant alone; the absence of the class (shims/test environments) falls back to the existing
			// constant check — fail-closed, consistent with the resolver.
			'elite_api_secret'   => class_exists( 'HAL_Frontend_Dashboard_Integration_Settings_Registry' )
				? 'none' !== HAL_Frontend_Dashboard_Integration_Settings_Registry::credential_source( 'amelia_elite_api_key' )
				: ( defined( 'HOSSAM_AMELIA_API_KEY' ) && '' !== HOSSAM_AMELIA_API_KEY ),
			'elite_api_client'   => function_exists( 'hossam_amelia_api_request' ),
		];
		$wpml_capabilities = [
			'language_urls' => defined( 'ICL_SITEPRESS_VERSION' ) && has_filter( 'wpml_permalink' ),
			'languages'     => defined( 'ICL_SITEPRESS_VERSION' ) && has_filter( 'wpml_active_languages' ),
		];
		$rank_math_capabilities = [
			'plugin_contract' => defined( 'RANK_MATH_VERSION' ),
			// v1.0.6 §8.2 (unified decision a/c): runtime detection instead of the
			// static value — the two gates of the template (seo.php) and the endpoint (ajax/seo.php)
			// thus open automatically together when Rank Math is available; the owner key rank_math_seo
			// remains the UI display gate.
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
		 * registry (introductory item 3: one capability decision per panel/endpoint from the registry itself:
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
// Scheduling the custom cron via cron_schedules — registered here in core before any
// use of `hossam_every_five_minutes` for the periodic sweep of the AI jobs table
// (registered and scheduled from ajax/ai.php). The filter is called at the scheduling time itself,
// so it does not depend on the file loading order.
// ══════════════════════════════════════════════════════════════
add_filter( 'cron_schedules', function ( array $schedules ): array {
	if ( ! isset( $schedules['hossam_every_five_minutes'] ) ) {
		$schedules['hossam_every_five_minutes'] = [
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 Minutes (Hossam Dashboard)', 'hal-frontend-dashboard' ),
		];
	}
	return $schedules;
} );

if ( ! function_exists( 'hossam_is_dashboard' ) ) {
    /**
     * Detects the Dashboard page reliably across WPML translations.
     *
     * Batch 7 (§7.5): the sole target contract is the owner page (the page ID
     * owned via Installer) and the WPML translations installed via the predicate
     * HAL_Frontend_Dashboard_Template_Controller::is_target_query() —
     * the exact same predicate as template_include exclusively, so that no assets or
     * body classes leak to other pages. No remaining dependency on
     * is_page('dashboard') nor is_page_template('page-dashboard.php')
     * nor a generic slug. Failure is closed: the absence of the Controller or an owned page
     * returns false (safe failure with no assets and no template).
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
     * Release-owned asset path (batch 2: the old child theme paths were
     * replaced with the release context — no get_stylesheet_directory nor
     * uploads/dashboard-assets here).
     *
     * @param string $file The relative path of the file.
     * @return string The full path on the server.
     */
    function hossam_asset_path( string $file ): string {
        return HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT . 'assets/' . $file;
    }
}

if ( ! function_exists('hossam_asset_uri') ) {
    /**
     * Release-owned asset URI (batch 2: the old child theme links were
     * replaced with the release URI).
     *
     * @param string $file The relative path of the file.
     * @return string The full URI of the file.
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
    if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) { return; }
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

    // B2-03 (architecture §8.3/§13 and owner decision §2.1): the enqueue of the
    // parent theme's style.css via get_template_directory_uri() was removed — the
    // Dashboard assets block is release-owned exclusively (dashboard.css and modules
    // via hossam_asset_uri()). The visual equivalence re-verification belongs to
    // the batch 9 gate (a visual comparison of every panel) and is not a redesign here.

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

    // v1.0.6 P1a: the standard media picker for the featured image — directly before
    // registering hossam-dashboard-posts so the media assets are printed before it.
    // On Dashboard pages only (the guard above blocks any site-wide loading),
    // and conditioned on upload_files as the interface of the image itself. The settled detail: the
    // capability condition alone without a panel restriction, because navigation between panels happens
    // in JS
    // within the single page (a server-side ?panel= restriction would have broken the later navigation).
    if ( current_user_can( 'upload_files' ) ) {
        wp_enqueue_media();
    }

    // v1.0.6 §9: the rich editor — the official Block Editor packages on the HAL panel exclusively.
    // The owner decision is settled: showing and wrapping, not building — assembling the public
    // Gutenberg components (BlockEditorProvider + BlockCanvas + Inserter + BlockTools)
    // inside a HAL wrapper, with no replacement engine/blocks and no Experimental.
    // The gates (all fail-closed):
    //   - The owner key rich_editor (an is_feature_enabled pattern) is the display gate;
    //     the key is registered in FEATURES (class-settings-repository.php:41-45)
    //     and defaults to intentionally closed (:85-87), so the display stays closed until the settings
    //     owner customizes it — and the textarea remains the form field.
    //   - At least one allowed edit path: edit_posts (creating — even if the
    //     user is prevented from editing another article) or edit_post for the specific article requested
    //     to be edited (post_id from the request). No reliance on the panel alone because navigation
    //     between panels happens inside the page.
    //   - upload_files is an independent condition for media only — it does not block loading the rest of the interface.
    // The server checks (wordpress-posts.php:38-40,106-113) remain mandatory; showing
    // the interface or loading its packages grants no capability and does not replace it.
    // The packages: handles registered by WordPress for the installed version (no package installation and
    // no core line numbers) with the wp_script_is/wp_style_is registration guards; the correct
    // dependency order is settled by the official registration dependencies. The heavy package
    // (block-editor.js ≈2.7MB) is printed only for whoever owns an edit path (allowed below).
    // The earlier script-loader referral (edit-post/editor/edit-widgets) is style dependencies,
    // not JavaScript registration — not used here.
    $hossam_rich_reason  = 'feature-disabled';
    $hossam_rich_allowed = false;
    // OEB/3 (H1 §6-3): the mode is read from get_effective_editor_mode() exclusively —
    // off → no packages at all and the absence labeled mode-off; as long as the mode ≠ off
    // the packages load for everyone who passed the gate (embed remains available to those who passed
    // the gate in official — the owner guarantee H1 §4/§8/§7-1). No package-dropping branch
    // in official×manager (E2 correction settled: the drop used to flip
    // $hossam_rich_enabled=false, dropping embed for the manager and voiding the guarantee).
    // The absence of the reader (class/method) remains a closed failure with the existing
    // feature-disabled pattern — no mode read from another source.
    $hossam_editor_mode = '';
    if ( class_exists( 'HAL_Frontend_Dashboard_Settings_Repository' )
        && method_exists( 'HAL_Frontend_Dashboard_Settings_Repository', 'get_effective_editor_mode' ) ) {
        $hossam_editor_mode = HAL_Frontend_Dashboard_Settings_Repository::get_effective_editor_mode();
    }
    if ( 'off' === $hossam_editor_mode ) {
        $hossam_rich_reason = 'mode-off';
    } elseif ( class_exists( 'HAL_Frontend_Dashboard_Settings_Repository' )
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
    // The required blocks (Table and Details and the media and the lists and the text-formatting links +
    // Playlist and Tabs in 7.1.3) come from the official registration exclusively via
    // registerCoreBlocks in posts.js; other plugin blocks are not auto-included
    // because their assets are not loaded in HAL. The absence of core/playlist or core/tabs outside
    // 7.1.3 is gracefully bypassed by the rest of the blocks — UNVERIFIED locally (core 6.7.1).
    // v1.1.1 (C2): the wp-format-library package provides the text-formatting controls
    // (Bold/Italic/Link) via the BlockToolbar drawn manually in posts.js:712 —
    // its absence in an older core is gracefully bypassed via the existing guard (it is not in
    // $hossam_rich_critical).
    $hossam_rich_handles = array(
        'wp-element', 'wp-data', 'wp-components', 'wp-blocks',
        'wp-block-editor', 'wp-block-library', 'wp-compose',
        'wp-media-utils', 'wp-api-fetch', 'wp-format-library',
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
        // v1.1.1 (C2): the format-library styles load with the same graceful wp_style_is
        // guard — the absence of registration is skipped with no effect whatsoever on the rest of the packages.
        foreach ( array( 'wp-components', 'wp-block-editor', 'wp-block-library', 'wp-format-library' ) as $hossam_rich_style ) {
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

    // v1.0.6 §9: the rich editor settings on hossam-dashboard-posts — a separate
    // hossamRichEditor object (hossamAjax above stays as is with no change, B9-E3 intact).
    // enabled=false means the textarea stays visible; canUpload reflects upload_files
    // (a separate condition for media); REST/Nonce is issued conditionally on the editor being enabled and upload_files;
    // '' otherwise (uploadMedia) — no new endpoint.
    $hossam_rich_can_upload = $hossam_rich_enabled && current_user_can( 'upload_files' );
    wp_localize_script( 'hossam-dashboard-posts', 'hossamRichEditor', [
        'enabled'   => $hossam_rich_enabled,
        'canUpload' => current_user_can( 'upload_files' ),
        'restRoot'  => function_exists( 'rest_url' ) ? rest_url() : '',
        'restNonce' => $hossam_rich_can_upload ? wp_create_nonce( 'wp_rest' ) : '',
        // OEB/3 (H1 §6-3): the actual mode next to restNonce with no structural merge —
        // its source is the variable above read from get_effective_editor_mode() exclusively.
        'mode'      => $hossam_editor_mode,
        'isRtl'     => function_exists( 'hossam_wpml_is_rtl' ) ? hossam_wpml_is_rtl() : is_rtl(),
        'reason'    => $hossam_rich_reason,
        // [W8] v1.1.3 (OP-2): the admin-only extended-block gate - a display
        // capability flag computed server-side (the :107-110 pattern);
        // the endpoint re-verifies server-side on every save (the standing
        // rule: display gates never authorize).
        'adminExtend' => $hossam_rich_enabled && current_user_can( 'manage_options' ),
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

    // Hook the AI module onto the posts interface after the conditional buttons execute.
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

// v1.0.8 (2026-10-29) — REC-2 — owner-approved self-hardening; the classification a/b/c
// with post-publication acceptance (§4/2); removable in isolation.
add_action( 'wp_enqueue_scripts', function(): void {
    if ( ! hossam_is_dashboard() ) return;

    if ( function_exists( 'wp_script_is' ) && wp_script_is( 'zip-ai-sidebar', 'enqueued' ) ) {
        wp_dequeue_script( 'zip-ai-sidebar' );
    }
    if ( function_exists( 'wp_style_is' ) && wp_style_is( 'zip-ai-sidebar', 'enqueued' ) ) {
        wp_dequeue_style( 'zip-ai-sidebar' );
    }
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
