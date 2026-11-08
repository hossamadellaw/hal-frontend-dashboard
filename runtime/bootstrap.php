<?php
/**
 * runtime/bootstrap.php — Runtime Bootstrap (HAL Frontend Dashboard)
 * ══════════════════════════════════════════════════════════════
 * A load point (Bootstrap) only for the version-owned release — no direct
 * logic here. Source: legacy mu-plugins/hossam-dashboard.php (batch 2).
 *
 * Contract (loader-core.php defines the release constants before this
 * file is loaded):
 *   - HAL_FRONTEND_DASHBOARD_RUNTIME_DIR   the release root path ending
 *     with DIRECTORY_SEPARATOR — the architectural contract (document
 *     §13, B2-04).
 *   - HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT  the same compatibility name —
 *     defined by loader-core.php:136 and consumed by setup.php:435. This
 *     file accepts either name and defines the missing one from the
 *     present one, so both remain available.
 *   - HAL_FRONTEND_DASHBOARD_RUNTIME_URL   the release URL (ending with
 *     /).
 *   - HAL_FRONTEND_DASHBOARD_RELEASE_ID / HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION.
 *
 * Mandatory rule (the mandatory load order Core→Adapters→AJAX):
 *   core/setup → core/i18n → core/tables → core/permissions →
 *   core/notifications → adapters/* → ajax/*
 * The load order below is fixed and must not be changed. core (batch 2),
 * then Backend/Settings/Secrets (batch 3), then adapters/* (batch 4 —
 * loaded after core in full and before any AJAX), and batch 5 adds
 * ajax/* (loaded after adapters in full — every ajax file assumes the
 * core and adapters functions are defined before it). This order is what
 * guarantees the functions are available at callback time, not the file
 * system order.
 *
 * Guarding: if neither name is defined, the exit is clean (a return — no
 * fatal and no runtime_ready fired), because the closed Loader builds the
 * rollback based on the action not being fired by shutdown time.
 *
 * READINESS (B2-01): the action is fired when the core load completes
 * (the closed Loader contract: its job = "boot done"), while the
 * hal_frontend_dashboard_boot_readiness filter evaluates on every call
 * the result of the actual initialization and verification (each owned
 * table's recorded version at or above the code target, persisted after
 * the init migrations). A migration failure prevents a successful Health
 * acknowledgement (class-health-check.php reads did_action +
 * apply_filters together) without changing the action timing or the
 * Loader contract. No static cache for the result: an early call before
 * init returns an honest false and is not pinned as an error.
 *
 * No state/network here. active-version.json and the owner decision
 * (observability) are deferred outside this batch — no code for them
 * here.
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_DIR' ) ) {
	$hal_runtime_root = HAL_FRONTEND_DASHBOARD_RUNTIME_DIR;
} elseif ( defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT' ) ) {
	$hal_runtime_root = HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT;
} else {
	// No release context — a clean exit with no fatal and no runtime_ready;
	// the closed Loader builds the rollback when the action is not fired.
	return;
}

// B2-04: unifying the path contract — §13 requires RUNTIME_DIR and the
// current Loader consumers consume RUNTIME_ROOT; the missing one is
// defined from the present one.
if ( ! defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_DIR' ) ) {
	define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_DIR', $hal_runtime_root );
}
if ( ! defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT' ) ) {
	define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT', $hal_runtime_root );
}

// ══════════════════════════════════════════════════════════════
// CORE — in the mandatory order, do not change
// ══════════════════════════════════════════════════════════════
require_once $hal_runtime_root . 'core/setup.php';
require_once $hal_runtime_root . 'core/i18n.php';
require_once $hal_runtime_root . 'core/tables.php';
require_once $hal_runtime_root . 'core/permissions.php';
require_once $hal_runtime_root . 'core/notifications.php';
require_once $hal_runtime_root . 'core/rate-limits.php';
require_once $hal_runtime_root . 'core/audit-log.php';

// ══════════════════════════════════════════════════════════════
// BACKEND — batch 3: the settings, the secrets, the integration registry,
// the integration health and the admin interface. Loaded after core in
// full and before adapters (batch 4 will consume the Settings
// Repository/Secret Store/Registry contracts). Every file here registers
// hooks only at load time with no other WordPress calls — execution
// happens at callback time.
// ══════════════════════════════════════════════════════════════
require_once $hal_runtime_root . 'settings/class-settings-repository.php';
require_once $hal_runtime_root . 'security/class-secret-store.php';
require_once $hal_runtime_root . 'integrations/class-integration-settings-registry.php';
require_once $hal_runtime_root . 'health/class-integration-health-monitor.php';
require_once $hal_runtime_root . 'admin/class-admin-controller.php';

// ══════════════════════════════════════════════════════════════
// ADAPTERS — batch 4: loaded here after core in full and before any
// AJAX, in the order: wordpress-posts → wordpress-uploads → woocommerce
// → wpml → amelia → rankmath → ultimatemember → ai
// (no mandatory order among them, but they must be loaded after core in
// full).
// ══════════════════════════════════════════════════════════════
require_once $hal_runtime_root . 'adapters/wordpress-posts.php';
require_once $hal_runtime_root . 'adapters/wordpress-uploads.php';
require_once $hal_runtime_root . 'adapters/woocommerce.php';
require_once $hal_runtime_root . 'adapters/wpml.php';
require_once $hal_runtime_root . 'adapters/amelia.php';
require_once $hal_runtime_root . 'adapters/rankmath.php';
require_once $hal_runtime_root . 'adapters/ultimatemember.php';
require_once $hal_runtime_root . 'adapters/ai.php';

// ══════════════════════════════════════════════════════════════
// AJAX — batches 5 and 6 + v1.0.6 §8.3: loaded after adapters in full
// (they call their functions directly). Every ajax file assumes the core
// and adapters functions are defined before it — this order is what
// guarantees that. The order of the nine files:
// posts → uploads → appointments → finance → members → inbox →
// store → ai → seo (ninth).
//
// ajax/seo.php: v1.0.6 §8.3 — a documented decision (unified decision
// a/j): loaded ninth after ai; the template and endpoint gates were
// opened together with runtime disclosure (setup.php: verified_fields)
// instead of the previous batch-5 closure gate (loading it would have
// changed the action inventory — now a documented change:
// wp_ajax_hossam_save_seo is registered in the actual runtime, and the
// affected wings were updated with a documented existing-file edit). The
// owner key rank_math_seo remains the display gate.
// ══════════════════════════════════════════════════════════════
require_once $hal_runtime_root . 'ajax/posts.php';
require_once $hal_runtime_root . 'ajax/uploads.php';
require_once $hal_runtime_root . 'ajax/appointments.php';
require_once $hal_runtime_root . 'ajax/finance.php';
require_once $hal_runtime_root . 'ajax/members.php';
require_once $hal_runtime_root . 'ajax/inbox.php';
require_once $hal_runtime_root . 'ajax/store.php';
require_once $hal_runtime_root . 'ajax/ai.php';
require_once $hal_runtime_root . 'ajax/seo.php';

// ══════════════════════════════════════════════════════════════
// TEMPLATES — batch 7: Template Controller (the shared Runtime copy
// §6.1 of includes/class-template-controller.php; the build generates it
// — batch 11). Loaded after all modules, with no load dependency on it
// and none of it on AJAX; it registers only the template_include filter
// at load time, and evaluation happens at template_include time. The
// batch-7 contract: a Runtime template for the owned page (page ID) and
// WPML translations exclusively — no slug/query parameter — and a
// dashboard parts loader with a closed allowlist.
// ══════════════════════════════════════════════════════════════
require_once $hal_runtime_root . 'infrastructure/class-template-controller.php';

// ══════════════════════════════════════════════════════════════
// UPDATE BRIDGE — batch 11: the permanent update bridge (§6.1 shared
// copy of includes/class-update-bridge.php; the build generates it).
// Loaded right after the Template Controller, with no load dependency on
// it and none of it on AJAX; initialization is restricted to update
// contexts only (admin/cron/CLI), so no network in the visitor path —
// is_update_context() returns false outside them, so no hooks are
// registered.
// ══════════════════════════════════════════════════════════════
require_once $hal_runtime_root . 'infrastructure/class-update-bridge.php';

if ( class_exists( 'HAL_Frontend_Dashboard_Update_Bridge', false ) && HAL_Frontend_Dashboard_Update_Bridge::is_update_context() ) {
	HAL_Frontend_Dashboard_Update_Bridge::init();
	if ( function_exists( 'add_action' ) ) {
		// B11-01: the deferred-import consumer. The callback loads the
		// manager lazily and only if no copy is already present, so a
		// process that loaded the includes/ copy (installer, harnesses)
		// never sees a duplicate declaration; production cron loads the
		// infrastructure copy behind the MU release context.
		add_action(
			HAL_Frontend_Dashboard_Update_Bridge::IMPORT_CRON_HOOK,
			static function () use ( $hal_runtime_root ): void {
				if ( ! class_exists( 'HAL_Frontend_Dashboard_Release_Manager', false ) ) {
					require_once $hal_runtime_root . 'infrastructure/class-release-manager.php';
				}
				HAL_Frontend_Dashboard_Release_Manager::import_candidate_cron();
			}
		);
	}
}

// ══════════════════════════════════════════════════════════════
// HEALTH CHECK — v1.0.3 wiring: load the runtime infrastructure copy
// then register both wp_ajax and wp_ajax_nopriv handlers unconditionally
// (outside admin/cron/CLI/login conditions) so the unauthenticated
// loopback is reachable. The class_exists guard avoids redeclaring the
// class when the includes/ copy was loaded first (installer, harnesses);
// registration always runs and never calls check()/handle_request() here.
// ══════════════════════════════════════════════════════════════
if ( ! class_exists( 'HAL_Frontend_Dashboard_Health_Check', false ) ) {
	require_once $hal_runtime_root . 'infrastructure/class-health-check.php';
}
HAL_Frontend_Dashboard_Health_Check::register();

unset( $hal_runtime_root );

// ══════════════════════════════════════════════════════════════
// READINESS — the closed Health Check contract after B2-01:
//   - The action is fired here when the core load completes (the closed
//     Loader contract: the action's job = "boot done"; its absence at
//     shutdown builds the rollback).
//   - The filter does not grant true for free: it evaluates on every call
//     the result of the actual initialization and verification, so a
//     migration failure prevents a successful Health acknowledgement
//     (handle_request reads did_action + apply_filters together).
// ══════════════════════════════════════════════════════════════

if ( ! function_exists( 'hossam_dashboard_core_boot_state_verified' ) ) {
	/**
	 * B2-01: the actual initialization and verification result for Core
	 * readiness.
	 *
	 * Evaluated live on every call with no static cache — any call before
	 * init (before the migrations) returns an honest false, and after the
	 * migrations succeed it returns true: every owned table carries a
	 * recorded version matching the code target or a higher persisted one
	 * (B2-02). It fails closed if the schemas function is not defined.
	 */
	function hossam_dashboard_core_boot_state_verified(): bool {
		if ( ! function_exists( 'hossam_dashboard_table_schemas' ) ) {
			return false;
		}

		foreach ( hossam_dashboard_table_schemas() as $definition ) {
			$recorded = get_option( $definition['option'] );
			if ( ! is_string( $recorded ) || '' === $recorded
				|| ! version_compare( $recorded, $definition['version'], '>=' ) ) {
				return false;
			}
		}

		return true;
	}
}

if ( ! function_exists( 'hossam_dashboard_core_boot_ready' ) ) {
	/**
	 * The hal_frontend_dashboard_boot_readiness filter — the actual
	 * readiness state, not a static assertion. The release arguments passed
	 * from class-health-check.php are accepted and ignored here (version
	 * matching is verified in consume() through the closed health
	 * challenge).
	 */
	function hossam_dashboard_core_boot_ready(): bool {
		return hossam_dashboard_core_boot_state_verified();
	}
}

add_filter( 'hal_frontend_dashboard_boot_readiness', 'hossam_dashboard_core_boot_ready' );
do_action( 'hal_frontend_dashboard_runtime_ready' );
