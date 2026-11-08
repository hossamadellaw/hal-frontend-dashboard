<?php
/**
 * Per-site provisioning for single sites and Simple Multisite networks.
 *
 * Shared source per architecture §6.1: this file is the single home of the
 * per-site setup logic (default capability grant + owned Dashboard page).
 * The build copies it byte-identical to
 * runtime/infrastructure/class-site-provisioner.php, which the MU Runtime
 * uses for late-joined sites; the Carrier Installer uses this copy at
 * activation time. Installer keeps delegating wrappers (reflective harness
 * compatibility); the logic lives here only.
 *
 * Multisite model (Simple, approved scope): one shared MU Runtime release
 * and one shared update state for the network, managed under the network
 * capability; each site is provisioned separately and repeatably
 * (idempotent), with its own settings/page/data/secrets/capabilities
 * (native per-blog storage). No per-site releases, no bulk complexity:
 * network activation provisions current sites, wp_initialize_site covers
 * later ones (registered from the Runtime side).
 *
 * @package HAL_Frontend_Dashboard
 */

defined( 'ABSPATH' ) || exit;

	final class HAL_Frontend_Dashboard_Site_Provisioner {
	/**
	 * Name of the owned-page option (contract §7.5).
	 */
	const PAGE_ID_OPTION = 'hal_frontend_dashboard_page_id';

	/** Internal meta flag marking the page provisioned for the project (not a slug). */
	const PAGE_META_KEY = '_hal_frontend_dashboard_page';

	/**
	 * Provision the CURRENT site idempotently: default capability, then
	 * the owned Dashboard page. Safe to repeat; never duplicates.
	 */
	public static function ensure_site(): void {
		self::grant_default_capability();
		self::ensure_dashboard_page();
	}

	/**
	 * Provision one blog of a network idempotently, always restoring the
	 * previous blog context — even when ensure_site() throws.
	 */
	public static function ensure_site_for_blog( int $blog_id ): void {
		if ( $blog_id > 0 && function_exists( 'switch_to_blog' ) && function_exists( 'restore_current_blog' ) ) {
			switch_to_blog( $blog_id );
			try {
				self::ensure_site();
			} finally {
				restore_current_blog();
			}
			return;
		}
		self::ensure_site();
	}

	/**
	 * §7.2.9 / batch-3 delivery rule: grant the default HAL management
	 * capability to the administrator role idempotently after the structural
	 * installation completes (root anchor). Runtime checks use only the
	 * capability and never inspect role names (owner decision §2.3).
	 * Isolated environments without the roles API (harnesses) are skipped safely — real WordPress always has get_role.
	 * Roles are per-site on multisite, so the grant is isolated by design.
	 */
	public static function grant_default_capability(): void {
		if ( ! function_exists( 'get_role' ) ) {
			return;
		}
		$role = get_role( 'administrator' );
		if ( $role instanceof WP_Role && ! $role->has_cap( 'manage_hal_frontend_dashboard' ) ) {
			$role->add_cap( 'manage_hal_frontend_dashboard' );
		}
	}

	/**
	 * §7.2 item 9 and §7.5: register/link the Dashboard page idempotently.
	 * - The site owner pins the legacy Page ID explicitly into the option
	 *   hal_frontend_dashboard_page_id via the WordPress Options API before
	 *   activation. A non-zero option is accepted only if it points to a
	 *   WP_Post/page/publish|private; an invalid value fails without creating a second page.
	 * - A valid option is returned (the legacy upgrade path: never a second page).
	 * - Otherwise: create a new owned page with an internal meta flag, verifying
	 *   the wp_insert_post() result, then re-reading it, then storing the option.
	 * - No slug hijacking: no search for any existing page — slug duplication
	 *   is resolved by WordPress itself at creation.
	 * - Any impossibility → RuntimeException before readiness is declared (no public redirect).
	 * Options are per-blog on multisite, so each site links its own page.
	 */
	public static function ensure_dashboard_page(): void {
		$stored = function_exists( 'get_option' ) ? (int) get_option( self::PAGE_ID_OPTION, 0 ) : 0;
		if ( $stored > 0 ) {
			if ( ! self::is_usable_dashboard_page( $stored ) ) {
				throw new RuntimeException( 'HAL_PAGE_ASSIGNED_ID_INVALID' );
			}
			return;
		}

		if ( ! function_exists( 'wp_insert_post' ) || ! function_exists( 'update_option' ) || ! function_exists( 'get_post_meta' ) || ! function_exists( 'get_posts' ) ) {
			throw new RuntimeException( 'HAL_PAGE_API_UNAVAILABLE' );
		}
		// Recover an owned page after an interrupted option write. A slug match
		// cannot establish ownership, and multiple marked pages are ambiguous.
		$marked = get_posts( array(
			'post_type' => 'page', 'post_status' => array( 'publish', 'private' ),
			'meta_key' => self::PAGE_META_KEY, 'meta_value' => '1',
			'fields' => 'ids', 'posts_per_page' => 2,
		) );
		if ( ! is_array( $marked ) || count( $marked ) > 1 ) {
			throw new RuntimeException( 'HAL_PAGE_OWNERSHIP_AMBIGUOUS' );
		}
		if ( 1 === count( $marked ) ) {
			$owned_id = (int) reset( $marked );
			if ( ! self::is_usable_dashboard_page( $owned_id ) ) {
				throw new RuntimeException( 'HAL_PAGE_VERIFY_FAILED' );
			}
			self::link_dashboard_page( $owned_id );
			return;
		}

		$new_id = wp_insert_post(
			array(
				'post_title'   => 'Dashboard',
				'post_name'    => 'dashboard',
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '',
				'meta_input'   => array( self::PAGE_META_KEY => '1' ),
			),
			true
		);
		if ( ( function_exists( 'is_wp_error' ) && is_wp_error( $new_id ) ) || (int) $new_id < 1 ) {
			throw new RuntimeException( 'HAL_PAGE_CREATE_FAILED' );
		}
		$new_id = (int) $new_id;

		if ( ! self::is_usable_dashboard_page( $new_id ) || '1' !== (string) get_post_meta( $new_id, self::PAGE_META_KEY, true ) ) {
			throw new RuntimeException( 'HAL_PAGE_VERIFY_FAILED' );
		}
		self::link_dashboard_page( $new_id );
	}

	private static function link_dashboard_page( int $page_id ): void {
		update_option( self::PAGE_ID_OPTION, $page_id, false );
		if ( ! function_exists( 'get_option' ) || (int) get_option( self::PAGE_ID_OPTION, 0 ) !== $page_id ) {
			throw new RuntimeException( 'HAL_PAGE_LINK_FAILED' );
		}
	}

	/**
	 * Target page validity: a WP_Post of type page with a displayable status
	 * (publish/private) — anything else triggers re-provisioning, not
	 * hijacking.
	 */
	private static function is_usable_dashboard_page( int $page_id ): bool {
		if ( $page_id < 1 || ! function_exists( 'get_post' ) ) {
			return false;
		}
		$post = get_post( $page_id );
		if ( ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
			return false;
		}

		return in_array( $post->post_status, array( 'publish', 'private' ), true );
	}
}
