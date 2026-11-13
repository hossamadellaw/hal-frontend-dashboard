<?php
/**
 * runtime/admin/class-admin-controller.php — Backend Administration (batch 3)
 * ══════════════════════════════════════════════════════════════
 * Contract (architecture §7.6/§8.3/§14 + owner decisions §2.3/§2.4):
 *   - A single menu "HAL Frontend Dashboard" guarded by the capability
 *     manage_hal_frontend_dashboard exclusively (no role checks); the
 *     default grants come from the Installer.
 *   - The six approved tabs: General | Branding | Features |
 *     Integrations | AI | Status & Diagnostics.
 *   - Every POST write goes to admin-post.php with a nonce and capability;
 *     values are sanitized and passed to Settings Repository/Secret Store
 *     under typed/validated contracts — the controller neither reads
 *     options directly nor writes them.
 *   - No secrets in HTML ever: only configured/source/health are shown;
 *     replacement requires a full value, and deletion is a separate
 *     POST+nonce action.
 *   - Secret ids from POST are accepted only within the Registry allowlist
 *     (no free-form key names); no provider URLs from input.
 *   - The Connector DB warning is mandatory in the AI tab (and not Maximum
 *     Protection); no streaming/embeddings in V1.
 *   - Frontend assets are isolated under the project's own screen id and
 *     never touch general wp-admin.
 * ══════════════════════════════════════════════════════════════
 */

defined( 'ABSPATH' ) || exit;

final class HAL_Frontend_Dashboard_Admin_Controller {

	const MENU_SLUG      = 'hal-frontend-dashboard';
	const NONCE_ACTION   = 'hal_frontend_dashboard_settings';
	const NONCE_FIELD    = 'hal_frontend_dashboard_nonce';
	const STYLE_HANDLE   = 'hal-frontend-dashboard-admin-settings';
	const SCRIPT_HANDLE  = 'hal-frontend-dashboard-admin-settings-js';

	const TAB_GENERAL    = 'general';
	const TAB_BRANDING   = 'branding';
	const TAB_FEATURES   = 'features';
	const TAB_INTEGRATIONS = 'integrations';
	const TAB_AI         = 'ai';
	const TAB_STATUS     = 'status';

	const ACTION_SAVE_SETTINGS = 'hal_frontend_dashboard_save_settings';
	const ACTION_SET_SECRET    = 'hal_frontend_dashboard_set_secret';
	const ACTION_DELETE_SECRET = 'hal_frontend_dashboard_delete_secret';
	const ACTION_RUN_HEALTH    = 'hal_frontend_dashboard_run_health';

	/**
	 * v1.1.1 (W4): descriptions for the twelve Features — the keys are
	 * literal members of FEATURES (settings-repository:42–46) and a key
	 * with no text renders without a paragraph. Documented disclosure:
	 * the original D+E session wording is not present in the available
	 * archive; these were composed in the pattern of the approved literal
	 * W5 texts, and the rich_editor text is the verbatim contract text
	 * from W5. English strings via __() with the project's text domain —
	 * no hossam_t (frontend only).
	 */
	const FEATURE_DESCRIPTIONS = array(
		'posts'                   => 'Enables the articles panel where writers create, edit and manage posts.',
		'files'                   => 'Enables the files panel for browsing the media library.',
		'inbox'                   => 'Enables the inbox panel for reading submitted contact messages.',
		'members'                 => 'Enables the members panel for viewing registered user profiles.',
		'amelia'                  => 'Enables the bookings panel backed by the Amelia plugin.',
		'finance'                 => 'Enables the finance panel backed by WooCommerce orders.',
		'store'                   => 'Enables the store panel backed by WooCommerce products.',
		'wpml_translations'       => 'Enables the translations panel backed by WPML.',
		'rank_math_seo'           => 'Enables the SEO writing panel backed by Rank Math.',
		'ultimate_member_profile' => 'Enables the profile panel backed by Ultimate Member.',
		'ai'                      => 'Enables the AI writing features and their strategy and key settings.',
		'rich_editor'             => 'Master switch for the rich editor surface. Off overrides the mode above to off.',
	);

	/**
	 * Register the hooks when the Runtime loads (from bootstrap) — with no
	 * other WordPress calls here.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE_SETTINGS, array( self::class, 'handle_save_settings' ) );
		add_action( 'admin_post_' . self::ACTION_SET_SECRET, array( self::class, 'handle_set_secret' ) );
		add_action( 'admin_post_' . self::ACTION_DELETE_SECRET, array( self::class, 'handle_delete_secret' ) );
		add_action( 'admin_post_' . self::ACTION_RUN_HEALTH, array( self::class, 'handle_run_health' ) );
	}

	public static function register_menu(): void {
		add_menu_page(
			'HAL Frontend Dashboard',
			'HAL Frontend Dashboard',
			HAL_Frontend_Dashboard_Settings_Repository::CAPABILITY,
			self::MENU_SLUG,
			array( self::class, 'render_page' ),
			'dashicons-layout',
			58
		);
	}

	/**
	 * Assets on the project's screen exclusively — no effect on general
	 * wp-admin (§14).
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook_suffix ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style(
			self::STYLE_HANDLE,
			HAL_FRONTEND_DASHBOARD_RUNTIME_URL . 'assets/css/admin-settings.css',
			array(),
			(string) HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION
		);
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			HAL_FRONTEND_DASHBOARD_RUNTIME_URL . 'assets/js/admin-settings.js',
			array(),
			(string) HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION,
			true
		);
		wp_localize_script(
			self::SCRIPT_HANDLE,
			'halFrontendDashboardAdmin',
			array(
				'mediaTitle'        => __( 'Choose the dashboard logo', 'hal-frontend-dashboard' ),
				'mediaButton'       => __( 'Use this image', 'hal-frontend-dashboard' ),
				'submitting'        => __( 'Saving…', 'hal-frontend-dashboard' ),
				'mediaUnavailable'  => __( 'The media library is not available in this browser session.', 'hal-frontend-dashboard' ),
				'selectedLabel'     => __( 'Selected logo attachment id:', 'hal-frontend-dashboard' ),
				// §7.6: uploading new Media requires upload_files — WordPress's
				// own server-side restriction; this is a UI hint that hides the
				// uploader.
				'canUploadFiles'    => current_user_can( 'upload_files' ),
			)
		);
	}

	/**
	 * Does the current context have access? capability only — no role checks.
	 */
	public static function current_user_can_manage(): bool {
		return function_exists( 'current_user_can' ) && current_user_can( HAL_Frontend_Dashboard_Settings_Repository::CAPABILITY );
	}

	/**
	 * Read a single string value from POST — is_string exclusively: arrays
	 * and non-string values return null with no conversion or notice
	 * (B3-07, separating request reading from the rest of the controller
	 * logic per §8.3).
	 */
	private static function post_string( string $key ): ?string {
		if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) {
			return null;
		}
		return (string) wp_unslash( $_POST[ $key ] );
	}

	/**
	 * Read a single string value from GET — same contract as post_string.
	 */
	private static function get_string( string $key ): ?string {
		if ( ! isset( $_GET[ $key ] ) || ! is_string( $_GET[ $key ] ) ) {
			return null;
		}
		return (string) wp_unslash( $_GET[ $key ] );
	}

	/**
	 * A nonce valid for this action?
	 */
	private static function nonce_ok( string $subaction ): bool {
		$nonce = self::post_string( self::NONCE_FIELD );
		return null !== $nonce && '' !== $nonce
			&& function_exists( 'wp_verify_nonce' )
			&& 1 === wp_verify_nonce( $nonce, self::NONCE_ACTION . ':' . $subaction );
	}

	/**
	 * The tab from GET within the allowlist — no free-form context.
	 */
	private static function requested_tab(): string {
		$tab = self::get_string( 'tab' );
		$tab = null === $tab ? self::TAB_GENERAL : sanitize_key( $tab );
		return in_array( $tab, array( self::TAB_GENERAL, self::TAB_BRANDING, self::TAB_FEATURES, self::TAB_INTEGRATIONS, self::TAB_AI, self::TAB_STATUS ), true )
			? $tab
			: self::TAB_GENERAL;
	}

	/**
	 * v1.1.1 (E3): secret handlers' redirect tab from POST — an absent
	 * value stays TAB_AI (backward compatibility with the existing AI tab
	 * forms), and a present value is sanitized with sanitize_key then
	 * tested against the allowlist of the same six tabs (verbatim
	 * requested_tab pattern) — anything outside it returns TAB_AI: no
	 * free-form context reaches redirect_back.
	 */
	private static function secret_redirect_tab(): string {
		$tab = self::post_string( 'secret_tab' );
		if ( null === $tab ) {
			return self::TAB_AI;
		}
		$tab = sanitize_key( $tab );
		return in_array( $tab, array( self::TAB_GENERAL, self::TAB_BRANDING, self::TAB_FEATURES, self::TAB_INTEGRATIONS, self::TAB_AI, self::TAB_STATUS ), true )
			? $tab
			: self::TAB_AI;
	}

	/* ────────────────────────────────────────────────────────────
	 * Handlers — each one: capability, then nonce, then sanitization,
	 * then the typed contract, then a safe redirect. The authorization
	 * order never changes (§5.10).
	 * ──────────────────────────────────────────────────────────── */

	public static function handle_save_settings(): void {
		if ( ! self::current_user_can_manage() || ! self::nonce_ok( 'save_settings' ) ) {
			self::redirect_back( self::TAB_GENERAL, 'forbidden' );
		}

		$settings = array();

		// B3-01: the Features form is distinguished by a marker field — the
		// browser does not send unchecked checkboxes, so features being
		// entirely absent while the marker is present means "disable all",
		// not "no change". The other forms have no marker and never touch
		// features at all.
		if ( null !== self::post_string( 'features_form' ) ) {
			$features = array_fill_keys( HAL_Frontend_Dashboard_Settings_Repository::FEATURES, false );
			// B3-07 (closure): a present, non-array field is rejected
			// explicitly — truly absent (the browser unchecked every box) is
			// the only legitimate state for disabling all.
			if ( isset( $_POST['features'] ) && ! is_array( $_POST['features'] ) ) {
				self::redirect_back( self::requested_tab(), 'save_failed' );
			}
			if ( isset( $_POST['features'] ) && is_array( $_POST['features'] ) ) {
				foreach ( wp_unslash( (array) $_POST['features'] ) as $feature => $value ) {
					// B3-07 (re-delivery): any tampered input — a non-string key,
					// a nested/non-scalar value, or an unknown key — rejects the
					// whole form before any save; no silent coercion and no
					// partial write.
					if ( ! is_string( $feature ) || ! is_scalar( $value ) ) {
						self::redirect_back( self::requested_tab(), 'save_failed' );
					}
					$feature_key = sanitize_key( $feature );
					if ( ! in_array( $feature_key, HAL_Frontend_Dashboard_Settings_Repository::FEATURES, true ) ) {
						self::redirect_back( self::requested_tab(), 'save_failed' );
					}
					$features[ $feature_key ] = true;
				}
			}
			$settings['features'] = $features;
		}

		// B3-07: a present but non-string field (an array, for example) is
		// rejected explicitly — no silent ignoring and no conversion; an
		// absent field means "not part of this form".
		if ( isset( $_POST['branding_attachment_id'] ) ) {
			$branding = self::post_string( 'branding_attachment_id' );
			if ( null === $branding || 1 !== preg_match( '/\A\d{1,20}\z/D', $branding ) ) {
				self::redirect_back( self::requested_tab(), 'save_failed' );
			}
			$settings['branding_attachment_id'] = (int) $branding;
		}

		if ( isset( $_POST['ai_preference'] ) ) {
			$preference = self::post_string( 'ai_preference' );
			if ( null === $preference ) {
				self::redirect_back( self::requested_tab(), 'save_failed' );
			}
			$settings['ai_preference'] = sanitize_key( $preference );
		}

		// OEB/2 (H1 §3/§6-2): rich editor mode save bridge — the ai_preference
		// pattern (isset → post_string → sanitize_key) with an EDITOR_MODES
		// check here: outside the list → save_failed, then assignment to
		// settings so it is passed to the unified save() gate. No new
		// endpoint and no touching of the save/capabilities/redirect logic
		// or of the R29 call below.
		if ( isset( $_POST['rich_editor_mode'] ) ) {
			$editor_mode = self::post_string( 'rich_editor_mode' );
			if ( null === $editor_mode ) {
				self::redirect_back( self::requested_tab(), 'save_failed' );
			}
			$editor_mode = sanitize_key( $editor_mode );
			if ( ! in_array( $editor_mode, HAL_Frontend_Dashboard_Settings_Repository::EDITOR_MODES, true ) ) {
				self::redirect_back( self::requested_tab(), 'save_failed' );
			}
			$settings['rich_editor_mode'] = $editor_mode;
		}

		// v1.1.1 (W1): site title and tagline — free text (user data escaped
		// only at render time) in the verbatim branding_attachment_id pattern:
		// isset → post_string → null rejects, then an mb_strlen cap otherwise
		// the whole form is rejected (no sanitize_key — no rewriting of the
		// user's text).
		if ( isset( $_POST['branding_site_title'] ) ) {
			$site_title = self::post_string( 'branding_site_title' );
			if ( null === $site_title ) {
				self::redirect_back( self::requested_tab(), 'save_failed' );
			}
			// Character length via mb_strlen when available, otherwise strlen —
			// the existing rankmath.php/ajax-seo.php pattern (environments
			// without mbstring).
			$site_title_length = function_exists( 'mb_strlen' ) ? mb_strlen( $site_title ) : strlen( $site_title );
			if ( $site_title_length > HAL_Frontend_Dashboard_Settings_Repository::BRANDING_SITE_TITLE_MAX ) {
				self::redirect_back( self::requested_tab(), 'save_failed' );
			}
			$settings['branding_site_title'] = $site_title;
		}

		if ( isset( $_POST['branding_site_tagline'] ) ) {
			$site_tagline = self::post_string( 'branding_site_tagline' );
			if ( null === $site_tagline ) {
				self::redirect_back( self::requested_tab(), 'save_failed' );
			}
			$site_tagline_length = function_exists( 'mb_strlen' ) ? mb_strlen( $site_tagline ) : strlen( $site_tagline );
			if ( $site_tagline_length > HAL_Frontend_Dashboard_Settings_Repository::BRANDING_SITE_TAGLINE_MAX ) {
				self::redirect_back( self::requested_tab(), 'save_failed' );
			}
			$settings['branding_site_tagline'] = $site_tagline;
		}

		// v1.1.1 (W3): site section URLs — verbatim B3-01/features_form
		// pattern: the form containing the fields is represented by a marker
		// (site_sections_form) and its absence = the key is never touched.
		// If present: it must be an array or the form is rejected; for each
		// of the eight keys: absent = '' (auto), a present non-string value
		// is rejected explicitly (B3-07: silent conversion forbidden), and
		// the text passes esc_url_raw then a scheme check via wp_parse_url —
		// http/https only, otherwise '' (malformed = empty = auto). The
		// eight are always built in full.
		if ( null !== self::post_string( 'site_sections_form' ) ) {
			if ( isset( $_POST['site_section_urls'] ) && ! is_array( $_POST['site_section_urls'] ) ) {
				self::redirect_back( self::requested_tab(), 'save_failed' );
			}
			$section_input = isset( $_POST['site_section_urls'] ) && is_array( $_POST['site_section_urls'] )
				? (array) $_POST['site_section_urls']
				: array();
			// An unknown key = structural tampering — reject the whole form
			// before any read (B3-07 pattern: a key outside the list rejects).
			foreach ( array_keys( $section_input ) as $submitted_key ) {
				if ( ! is_string( $submitted_key ) || ! in_array( $submitted_key, HAL_Frontend_Dashboard_Settings_Repository::SITE_SECTION_KEYS, true ) ) {
					self::redirect_back( self::requested_tab(), 'save_failed' );
				}
			}
			$section_urls = array_fill_keys( HAL_Frontend_Dashboard_Settings_Repository::SITE_SECTION_KEYS, '' );
			foreach ( HAL_Frontend_Dashboard_Settings_Repository::SITE_SECTION_KEYS as $section_key ) {
				// Absent = '' (auto) — the only legitimate state for absence
				// (features pattern: truly absent is the sole sanctioned case);
				// a present non-string value is rejected explicitly with no
				// silent conversion (verbatim B3-07).
				if ( ! isset( $section_input[ $section_key ] ) ) {
					continue;
				}
				if ( ! is_string( $section_input[ $section_key ] ) ) {
					self::redirect_back( self::requested_tab(), 'save_failed' );
				}
				$section_value = esc_url_raw( $section_input[ $section_key ] );
				if ( '' !== $section_value ) {
					$section_scheme = wp_parse_url( $section_value, PHP_URL_SCHEME );
					$section_scheme = is_string( $section_scheme ) ? strtolower( $section_scheme ) : '';
					if ( ! in_array( $section_scheme, array( 'http', 'https' ), true ) ) {
						$section_value = '';
					}
				}
				$section_urls[ $section_key ] = $section_value;
			}
			$settings['site_section_urls'] = $section_urls;
		}

		$saved = HAL_Frontend_Dashboard_Settings_Repository::save( $settings );

		// R29 (G4 §3-a-15): settings_save only after save() succeeds and
		// before redirect_back — with the is_wp_error guard, a failed save
		// logs zero rows. meta keys carry only the written section key names,
		// never values; object_id NULL in the siblings' pattern, and the
		// return value is deliberately ignored (G4 §5) — no touching of the
		// save logic, the capabilities, or the redirect.
		if ( ! is_wp_error( $saved ) ) {
			hossam_audit_log(
				'settings_save',
				array(
					'object_id' => null,
					'meta'      => array( 'keys' => array_keys( $settings ) ),
				)
			);
		}

		// B3-02: an actual write failure (after passing validation) shows
		// save_failed — no change shows saved without a false write.
		self::redirect_back( self::requested_tab(), is_wp_error( $saved ) ? 'save_failed' : 'saved' );
	}

	public static function handle_set_secret(): void {
		if ( ! self::current_user_can_manage() || ! self::nonce_ok( 'set_secret' ) ) {
			self::redirect_back( self::TAB_AI, 'forbidden' );
		}
		$secret_id = self::post_string( 'secret_id' );
		// The secret id comes from the Registry allowlist exclusively (no
		// free-form key names, and arrays return null from the reader with
		// no conversion — B3-07).
		if ( null === $secret_id || ! HAL_Frontend_Dashboard_Integration_Settings_Registry::is_valid_secret_id( $secret_id ) ) {
			self::redirect_back( self::TAB_AI, 'unknown_secret' );
		}
		// B3-07: the secret value must be a string — an array/absence returns
		// null and is passed as an empty string, producing the documented
		// contract error (EMPTY_VALUE) instead of a silent conversion of any
		// content.
		$value = self::post_string( 'secret_value' ) ?? '';
		// A full value is required for replacement — no reading of the old
		// value and no partial update (owner decision §2.4).
		$result = HAL_Frontend_Dashboard_Secret_Store::set( $secret_id, $value );
		// v1.1.1 (E3): an immediate probe is mandatory after the store is
		// actually touched — invalidating the Registry transient (TTL 5
		// minutes) then an immediate health check closes a time gap that
		// could serve a stale credential source for up to 5 minutes after
		// saving. The early rejection paths above run no probe (the store
		// was untouched). The second guard (delete_transient) preserves shim/
		// test environments without the transient API — a closed no-op there
		// and fully active in WordPress.
		if ( function_exists( 'hossam_invalidate_integration_capabilities' ) && function_exists( 'delete_transient' ) ) {
			hossam_invalidate_integration_capabilities();
		}
		HAL_Frontend_Dashboard_Integration_Health_Monitor::run_checks();
		// v1.1.1 (E3): redirect to the source tab (secret_tab) instead of the
		// hardcoded TAB_AI.
		// v1.1.2: the store error code maps to a dedicated message key so the
		// notice names the actual failure reason; unknown codes keep the
		// generic message below as the fallback (text and behavior intact).
		if ( is_wp_error( $result ) ) {
			$code    = $result->get_error_code();
			$map     = array(
				'hal_secret_bad_id'            => 'secret_failed_value',
				'hal_secret_empty_value'       => 'secret_failed_value',
				'hal_secret_persist_failed'    => 'secret_failed_persist',
				'hal_secret_store_unavailable' => ( 'sodium_missing' === HAL_Frontend_Dashboard_Secret_Store::get_blocker() )
					? 'secret_failed_sodium'
					: 'secret_failed_key',
			);
			$message = isset( $map[ $code ] ) ? $map[ $code ] : 'secret_failed';
		} else {
			$message = 'secret_saved';
		}
		self::redirect_back( self::secret_redirect_tab(), $message, $secret_id );
	}

	public static function handle_delete_secret(): void {
		if ( ! self::current_user_can_manage() || ! self::nonce_ok( 'delete_secret' ) ) {
			self::redirect_back( self::TAB_AI, 'forbidden' );
		}
		$secret_id = self::post_string( 'secret_id' );
		if ( null === $secret_id || ! HAL_Frontend_Dashboard_Integration_Settings_Registry::is_valid_secret_id( $secret_id ) ) {
			self::redirect_back( self::TAB_AI, 'unknown_secret' );
		}
		$deleted = HAL_Frontend_Dashboard_Secret_Store::delete( $secret_id );
		// v1.1.1 (E3): an immediate probe is mandatory after the store is
		// actually touched — verbatim handle_set_secret pattern (the early
		// rejection paths above run no probe).
		if ( function_exists( 'hossam_invalidate_integration_capabilities' ) && function_exists( 'delete_transient' ) ) {
			hossam_invalidate_integration_capabilities();
		}
		HAL_Frontend_Dashboard_Integration_Health_Monitor::run_checks();
		// v1.1.1 (E3): redirect to the source tab (secret_tab) instead of the
		// hardcoded TAB_AI.
		self::redirect_back( self::secret_redirect_tab(), $deleted ? 'secret_deleted' : 'secret_missing', $secret_id );
	}

	public static function handle_run_health(): void {
		if ( ! self::current_user_can_manage() || ! self::nonce_ok( 'run_health' ) ) {
			self::redirect_back( self::TAB_STATUS, 'forbidden' );
		}
		HAL_Frontend_Dashboard_Integration_Health_Monitor::run_checks();
		self::redirect_back( self::TAB_STATUS, 'health_run' );
	}

	private static function redirect_back( string $tab, string $message, string $secret_id = '' ): void {
		$args = array(
			'page'     => self::MENU_SLUG,
			'tab'      => $tab,
			'hal_msg'  => $message,
		);
		if ( '' !== $secret_id ) {
			$args['secret'] = $secret_id; // id only — no value.
		}
		$target = function_exists( 'add_query_arg' ) && function_exists( 'admin_url' )
			? add_query_arg( $args, admin_url( 'admin.php' ) )
			: '';
		if ( function_exists( 'wp_safe_redirect' ) ) {
			wp_safe_redirect( $target );
		}
		exit;
	}

	/**
	 * v1.1.1 (W2): dashboard page status — read-only with no writing at all
	 * (the option hal_frontend_dashboard_page_id is read-only here; the
	 * sole writer is the Installer): owned = id>0 maps to a page in
	 * publish/private status with the same check as hossam_dashboard_url
	 * (adapters/wpml.php — called, not modified, for the link); otherwise
	 * fallback.
	 *
	 * @return array{status:string, page_id:int, url:string}
	 */
	private static function dashboard_page_status(): array {
		$page_id = function_exists( 'get_option' ) ? absint( get_option( 'hal_frontend_dashboard_page_id', 0 ) ) : 0;
		$owned   = false;
		if ( $page_id > 0 && function_exists( 'get_post' ) ) {
			$post  = get_post( $page_id );
			$owned = $post instanceof WP_Post
				&& 'page' === $post->post_type
				&& in_array( $post->post_status, array( 'publish', 'private' ), true );
		}
		$url = function_exists( 'hossam_dashboard_url' ) ? hossam_dashboard_url() : '';
		return array(
			'status'  => $owned ? 'owned' : 'fallback',
			'page_id' => $page_id,
			'url'     => $url,
		);
	}

	/* ────────────────────────────────────────────────────────────
	 * Render — no secrets: configured/source/health only.
	 * ──────────────────────────────────────────────────────────── */

	public static function render_page(): void {
		if ( ! self::current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage HAL Frontend Dashboard.', 'hal-frontend-dashboard' ), 403 );
		}

		$tab     = self::requested_tab();
		$settings = HAL_Frontend_Dashboard_Settings_Repository::get_all();
		?>
		<div class="wrap hal-fd-admin" data-hal-admin>
			<h1><?php echo esc_html( 'HAL Frontend Dashboard' ); ?></h1>
			<?php self::render_notice_from_query(); ?>
			<h2 class="nav-tab-wrapper hal-fd-tabs">
				<?php
				$tabs = array(
					self::TAB_GENERAL      => __( 'General', 'hal-frontend-dashboard' ),
					self::TAB_BRANDING     => __( 'Branding', 'hal-frontend-dashboard' ),
					self::TAB_FEATURES     => __( 'Features', 'hal-frontend-dashboard' ),
					self::TAB_INTEGRATIONS => __( 'Integrations', 'hal-frontend-dashboard' ),
					self::TAB_AI           => __( 'AI', 'hal-frontend-dashboard' ),
					self::TAB_STATUS       => __( 'Status & Diagnostics', 'hal-frontend-dashboard' ),
				);
				foreach ( $tabs as $tab_id => $label ) {
					printf(
						'<a class="nav-tab%1$s" href="%2$s" data-tab="%3$s">%4$s</a>',
						$tab_id === $tab ? ' nav-tab-active' : '',
						esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=' . $tab_id ) ),
						esc_attr( $tab_id ),
						esc_html( $label )
					);
				}
				?>
			</h2>

			<?php if ( self::TAB_GENERAL === $tab ) : ?>
				<div class="hal-fd-card">
					<p>
						<?php
						printf(
							/* translators: 1: runtime version, 2: release id. */
							esc_html__( 'Runtime version: %1$s — release: %2$s.', 'hal-frontend-dashboard' ),
							esc_html( HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION ),
							esc_html( HAL_FRONTEND_DASHBOARD_RELEASE_ID )
						);
						?>
					</p>
					<p><?php esc_html_e( 'Runtime checks use the manage_hal_frontend_dashboard capability only. Site settings, media attachments and encrypted secrets live outside release directories and persist across updates and rollbacks.', 'hal-frontend-dashboard' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( self::TAB_BRANDING === $tab ) : ?>
				<div class="hal-fd-card">
					<p><?php esc_html_e( 'The dashboard logo is a Media Library attachment (PNG, JPEG or WebP). No logo file ships with the plugin; missing attachments fall back to a safe text mark (HAL).', 'hal-frontend-dashboard' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-hal-form>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE_SETTINGS ); ?>" />
						<input type="hidden" name="tab" value="<?php echo esc_attr( self::TAB_BRANDING ); ?>" />
						<?php wp_nonce_field( self::NONCE_ACTION . ':save_settings', self::NONCE_FIELD ); ?>
						<input type="hidden" name="branding_attachment_id" data-hal-attachment-input value="<?php echo esc_attr( (string) $settings['branding_attachment_id'] ); ?>" />
						<p data-hal-attachment-preview data-hal-attachment-id="<?php echo esc_attr( (string) $settings['branding_attachment_id'] ); ?>">
							<?php
							// Live read: a deleted/invalid attachment shows the
							// textual fallback state (owner decision §2.3).
							$effective_attachment_id = HAL_Frontend_Dashboard_Settings_Repository::get_branding_attachment_id();
							echo esc_html(
								$effective_attachment_id > 0
									? sprintf( /* translators: %d: attachment id */ __( 'Current logo attachment id: %d', 'hal-frontend-dashboard' ), $effective_attachment_id )
									: __( 'No usable logo attachment — the safe text fallback (HAL) is used.', 'hal-frontend-dashboard' )
							);
							?>
						</p>
						<?php // v1.1.1 (W1/W3): site title/tagline fields and the eight section URLs — user values are output escaped with esc_attr exclusively (no i18n and no hossam_t). ?>
						<input type="hidden" name="site_sections_form" value="1" />
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="hal-branding-site-title"><?php esc_html_e( 'Site title', 'hal-frontend-dashboard' ); ?></label></th>
								<td>
									<input type="text" id="hal-branding-site-title" name="branding_site_title" value="<?php echo esc_attr( $settings['branding_site_title'] ); ?>" maxlength="60" class="regular-text" />
									<p class="description"><?php esc_html_e( 'Leave empty to use the default text at each position.', 'hal-frontend-dashboard' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="hal-branding-site-tagline"><?php esc_html_e( 'Tagline', 'hal-frontend-dashboard' ); ?></label></th>
								<td>
									<input type="text" id="hal-branding-site-tagline" name="branding_site_tagline" value="<?php echo esc_attr( $settings['branding_site_tagline'] ); ?>" maxlength="80" class="regular-text" />
									<p class="description"><?php esc_html_e( 'Leave empty to use the default text at each position.', 'hal-frontend-dashboard' ); ?></p>
								</td>
							</tr>
							<?php foreach ( HAL_Frontend_Dashboard_Settings_Repository::SITE_SECTION_KEYS as $section_key ) : ?>
							<tr>
								<th scope="row"><label for="hal-section-url-<?php echo esc_attr( $section_key ); ?>"><?php echo esc_html( $section_key ); ?></label></th>
								<td>
									<input type="url" id="hal-section-url-<?php echo esc_attr( $section_key ); ?>" name="site_section_urls[<?php echo esc_attr( $section_key ); ?>]" value="<?php echo esc_attr( $settings['site_section_urls'][ $section_key ] ); ?>" placeholder="auto" class="regular-text code" />
								</td>
							</tr>
							<?php endforeach; ?>
						</table>
						<p class="description"><?php esc_html_e( 'URL overrides apply to the fallback site sections list only — shown when no primary navigation menu exists; the primary menu always takes precedence. Leave a field empty for the automatic resolver.', 'hal-frontend-dashboard' ); ?></p>
						<p>
							<button type="button" class="button" data-hal-media-open><?php esc_html_e( 'Choose from Media Library', 'hal-frontend-dashboard' ); ?></button>
							<?php submit_button( __( 'Save branding', 'hal-frontend-dashboard' ), 'primary', 'submit', false ); ?>
						</p>
					</form>
				</div>
				<?php // v1.1.1 (W2): read-only block for the dashboard page — after the form and outside it: zero POST and zero hidden fields and no writing to the option at all (the sole writer is the Installer). ?>
				<?php $dashboard_page = self::dashboard_page_status(); ?>
				<div class="hal-fd-card">
					<p>
						<?php
						printf(
							/* translators: 1: page id, 2: ownership status (owned or fallback). */
							esc_html__( 'Dashboard page id: %1$d — status: %2$s.', 'hal-frontend-dashboard' ),
							$dashboard_page['page_id'],
							esc_html( $dashboard_page['status'] )
						);
						?>
						<?php if ( '' !== $dashboard_page['url'] ) : ?>
							<a href="<?php echo esc_url( $dashboard_page['url'] ); ?>" target="_blank"><?php esc_html_e( 'Open', 'hal-frontend-dashboard' ); ?></a>
						<?php endif; ?>
					</p>
					<p class="description"><?php esc_html_e( 'Reactivating the plugin secures or generates the dashboard page. Manual binding is done via the page id option before activation. This block is read-only.', 'hal-frontend-dashboard' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( self::TAB_FEATURES === $tab ) : ?>
				<div class="hal-fd-card">
					<p><?php esc_html_e( 'Feature toggles never grant capability or resource ownership; the effective decision combines this setting with the integration registry, user capability and resource ownership at each consumer.', 'hal-frontend-dashboard' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-hal-form>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE_SETTINGS ); ?>" />
						<input type="hidden" name="tab" value="<?php echo esc_attr( self::TAB_FEATURES ); ?>" />
						<input type="hidden" name="features_form" value="1" />
						<?php wp_nonce_field( self::NONCE_ACTION . ':save_settings', self::NONCE_FIELD ); ?>
							<table class="form-table" role="presentation">
								<?php foreach ( $settings['features'] as $feature => $enabled ) : ?>
									<tr>
										<th scope="row"><label for="hal-feature-<?php echo esc_attr( $feature ); ?>"><?php echo esc_html( $feature ); ?></label></th>
										<td>
											<input type="checkbox" id="hal-feature-<?php echo esc_attr( $feature ); ?>" name="features[<?php echo esc_attr( $feature ); ?>]" value="1" <?php checked( $enabled ); ?> data-hal-feature="<?php echo esc_attr( $feature ); ?>" />
											<?php // v1.1.1 (W4): the feature description from the FEATURE_DESCRIPTIONS map — a key with no text = no paragraph. ?>
											<?php if ( isset( self::FEATURE_DESCRIPTIONS[ $feature ] ) ) : ?>
												<p class="description"><?php echo esc_html( __( self::FEATURE_DESCRIPTIONS[ $feature ], 'hal-frontend-dashboard' ) ); ?></p>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php // OEB/2 (H1 §3/§6-2): rich editor mode select in the existing ai_preference select pattern — the three modes with the current one from get_rich_editor_mode(). ?>
							<tr>
								<th scope="row"><label for="hal-rich-editor-mode"><?php esc_html_e( 'Rich editor mode', 'hal-frontend-dashboard' ); ?></label></th>
								<td>
									<select id="hal-rich-editor-mode" name="rich_editor_mode">
										<?php foreach ( HAL_Frontend_Dashboard_Settings_Repository::EDITOR_MODES as $editor_mode ) : ?>
											<option value="<?php echo esc_attr( $editor_mode ); ?>" <?php selected( HAL_Frontend_Dashboard_Settings_Repository::get_rich_editor_mode(), $editor_mode ); ?>><?php echo esc_html( $editor_mode ); ?></option>
										<?php endforeach; ?>
									</select>
									<?php // v1.1.1 (W5): the three-mode description with the verbatim contract texts — display only with no new logic: the smart default already exists (repository: fail-closed embed on absence/corruption, and off is forced when rich_editor is disabled). ?>
									<p class="description"><?php echo esc_html( __( 'Adds an Open-Editor button (admins) that opens the official WordPress editor in a window and syncs edits back.', 'hal-frontend-dashboard' ) ); ?></p>
									<p class="description"><?php echo esc_html( __( 'Shows the official WordPress block editor embedded inside the article forms (default).', 'hal-frontend-dashboard' ) ); ?></p>
									<p class="description"><?php echo esc_html( __( 'Uses the plain text field only — no rich editor packages are loaded.', 'hal-frontend-dashboard' ) ); ?></p>
									<p class="description"><?php echo esc_html( __( 'Default: embed — chosen automatically until you change it here.', 'hal-frontend-dashboard' ) ); ?></p>
									<p class="description"><?php echo esc_html( __( 'You can override at any time; the Rich editor checkbox must stay on for either mode.', 'hal-frontend-dashboard' ) ); ?></p>
								</td>
							</tr>
						</table>
						<?php submit_button( __( 'Save features', 'hal-frontend-dashboard' ), 'primary', 'submit', false ); ?>
					</form>
				</div>
			<?php endif; ?>

			<?php if ( self::TAB_INTEGRATIONS === $tab ) : ?>
				<div class="hal-fd-card">
					<p><?php esc_html_e( 'Sanitized status codes from the last bounded health job. Values and raw health responses are never displayed here.', 'hal-frontend-dashboard' ); ?></p>
					<table class="widefat striped hal-fd-status" role="presentation">
						<?php foreach ( self::render_integration_rows() as $row ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html( $row['label'] ); ?></th>
								<td><span class="hal-fd-badge hal-fd-badge--<?php echo esc_attr( $row['status'] ); ?>"><?php echo esc_html( $row['status'] ); ?></span> <code><?php echo esc_html( implode( ', ', $row['codes'] ) ); ?></code><?php '' !== $row['credential'] ? printf( ' <span class="description">%s <code>%s</code></span>', esc_html( 'credential source:' ), esc_html( $row['credential'] ) ) : null; ?></td>
							</tr>
						<?php endforeach; ?>
					</table>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-hal-form>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_RUN_HEALTH ); ?>" />
						<?php wp_nonce_field( self::NONCE_ACTION . ':run_health', self::NONCE_FIELD ); ?>
						<?php submit_button( __( 'Run integration health check now', 'hal-frontend-dashboard' ), 'secondary', 'submit', false ); ?>
					</form>
					<?php // v1.1.1 (E3): Amelia key entry through the encrypted UI — from the Registry exclusively in the verbatim AI tab pattern; the rows table above remains the credential-source display as is. ?>
					<?php foreach ( HAL_Frontend_Dashboard_Integration_Settings_Registry::integrations()['amelia']['secrets'] as $secret ) : ?>
						<?php self::render_secret_block( (string) $secret['id'], (string) $secret['label'], self::TAB_INTEGRATIONS ); ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( self::TAB_AI === $tab ) : ?>
				<div class="hal-fd-card">
					<p class="hal-fd-warning">
						<?php esc_html_e( 'Warning: WordPress AI Client Connector DB keys are stored unencrypted in the database and readable by other plugins. This source is supported with a warning and is not Maximum Protection.', 'hal-frontend-dashboard' ); ?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-hal-form>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE_SETTINGS ); ?>" />
						<input type="hidden" name="tab" value="<?php echo esc_attr( self::TAB_AI ); ?>" />
						<?php wp_nonce_field( self::NONCE_ACTION . ':save_settings', self::NONCE_FIELD ); ?>
						<?php $ai_feature_enabled = (bool) $settings['features']['ai']; ?>
						<fieldset class="hal-fd-fieldset" data-requires-feature="ai" <?php disabled( ! $ai_feature_enabled ); ?>>
							<table class="form-table" role="presentation">
								<tr>
									<th scope="row"><label for="hal-ai-preference"><?php esc_html_e( 'Strategy preference', 'hal-frontend-dashboard' ); ?></label></th>
									<td>
										<select id="hal-ai-preference" name="ai_preference">
											<?php foreach ( HAL_Frontend_Dashboard_Settings_Repository::AI_PREFERENCES as $preference ) : ?>
												<option value="<?php echo esc_attr( $preference ); ?>" <?php selected( $settings['ai_preference'], $preference ); ?>><?php echo esc_html( $preference ); ?></option>
											<?php endforeach; ?>
										</select>
										<p class="description"><?php esc_html_e( 'auto prefers the WordPress AI Client when text generation is supported; the direct encrypted key is a fallback. The provider/model allowlist is owned by the AI adapter.', 'hal-frontend-dashboard' ); ?></p>
									</td>
								</tr>
							</table>
						</fieldset>
						<?php if ( ! $ai_feature_enabled ) : ?>
							<p class="description"><?php esc_html_e( 'The AI feature is disabled in the Features tab — its controls are disabled until it is enabled. Disabling a feature never deletes its data.', 'hal-frontend-dashboard' ); ?></p>
						<?php endif; ?>
						<?php submit_button( __( 'Save AI preference', 'hal-frontend-dashboard' ), 'primary', 'submit', false ); ?>
					</form>
					<?php foreach ( HAL_Frontend_Dashboard_Integration_Settings_Registry::integrations()['ai']['secrets'] as $secret ) : ?>
						<?php self::render_secret_block( (string) $secret['id'], (string) $secret['label'], self::TAB_AI ); ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( self::TAB_STATUS === $tab ) : ?>
				<div class="hal-fd-card">
					<p>
						<?php
						$store_available = HAL_Frontend_Dashboard_Secret_Store::is_available();
						echo esc_html(
							sprintf(
								/* translators: 1: availability, 2: key source, 3: fingerprint. */
								__( 'Secret store: %1$s — key source: %2$s — key fingerprint: %3$s.', 'hal-frontend-dashboard' ),
								$store_available ? 'available' : 'blocked',
								esc_html( HAL_Frontend_Dashboard_Secret_Store::get_key_source() ),
								esc_html( HAL_Frontend_Dashboard_Secret_Store::get_key_fingerprint() ?? 'n/a' )
							)
						);
						?>
						</p>
						<?php if ( ! $store_available ) : ?>
							<p class="description">
								<?php
								$blocker = HAL_Frontend_Dashboard_Secret_Store::get_blocker();
								echo esc_html( 'sodium_missing' === $blocker
									? __( 'Reason: the PHP sodium extension is missing on this server.', 'hal-frontend-dashboard' )
									: __( 'Reason: no valid external encryption key was found (environment variable or wp-config.php constant).', 'hal-frontend-dashboard' ) );
								?>
							</p>
							<p class="description"><?php esc_html_e( 'To enable encrypted storage, generate a key locally on your own machine and add this line to wp-config.php above the stop-editing comment:', 'hal-frontend-dashboard' ); ?></p>
							<p><code>define( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY', '&lt;paste-64-hex-characters&gt;' );</code></p>
							<p class="description"><?php esc_html_e( 'Local key generation command: php -r \'echo bin2hex(random_bytes(32));\'', 'hal-frontend-dashboard' ); ?></p>
							<p class="description"><?php esc_html_e( 'Expected read-back after saving wp-config.php and reloading this page: "Secret store: available — key source: constant — key fingerprint: <32 hex characters>".', 'hal-frontend-dashboard' ); ?></p>
						<?php endif; ?>
						<table class="widefat striped hal-fd-status" role="presentation">
						<?php foreach ( self::render_integration_rows() as $row ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html( $row['label'] ); ?></th>
								<td><span class="hal-fd-badge hal-fd-badge--<?php echo esc_attr( $row['status'] ); ?>"><?php echo esc_html( $row['status'] ); ?></span> <code><?php echo esc_html( implode( ', ', $row['codes'] ) ); ?></code></td>
							</tr>
						<?php endforeach; ?>
					</table>
					<p class="description"><?php esc_html_e( 'Integration Health never rolls the runtime back; Deployment/Boot Health is a separate contract.', 'hal-frontend-dashboard' ); ?></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Sanitized status rows from the monitor's cache (no values and no raw
	 * responses) with the actual credential source for each integration
	 * that owns a secret (B3-credential), and the Connector source status
	 * for AI when the Client is capable (B4-03 — display only, no secret
	 * reading inside the render).
	 *
	 * @return array<int, array{label:string, status:string, codes:string[], credential:string}>
	 */
	private static function render_integration_rows(): array {
		$status  = HAL_Frontend_Dashboard_Integration_Health_Monitor::get_status();
		$rows    = array();
		foreach ( HAL_Frontend_Dashboard_Integration_Settings_Registry::integrations() as $integration_id => $definition ) {
			$entry  = is_array( $status[ $integration_id ] ?? null ) ? $status[ $integration_id ] : array();
			$credential = '';
			foreach ( $definition['secrets'] as $secret ) {
				$credential = HAL_Frontend_Dashboard_Integration_Settings_Registry::credential_source( (string) $secret['id'] );
				break;
			}
			if ( 'ai' === $integration_id && function_exists( 'hossam_ai_connector_source_status' ) ) {
				$connector = hossam_ai_connector_source_status();
				if ( ! empty( $connector['capable'] ) ) {
					$suffix = 'connector:' . (string) $connector['source'] . ' (' . (string) $connector['reason'] . ')';
					$credential = '' !== $credential ? $credential . ' + ' . $suffix : $suffix;
				}
			}
			$rows[] = array(
				'label'      => (string) $definition['label'],
				'status'     => (string) ( $entry['status'] ?? 'unknown' ),
				'codes'      => array_map( 'strval', (array) ( $entry['codes'] ?? array() ) ),
				'credential' => $credential,
			);
		}
		return $rows;
	}

	/**
	 * One secret block: its status only (configured/source) + replacement
	 * with a full value + a separate delete. No stored value is displayed
	 * or sent to the browser.
	 * v1.1.1 (E3): the $tab argument is optional — when passed, a hidden
	 * secret_tab is printed in the set and delete forms so the redirect
	 * returns to the source tab (when absent, the existing TAB_AI behavior
	 * remains without the field).
	 */
	private static function render_secret_block( string $secret_id, string $label, string $tab = '' ): void {
		$configured = HAL_Frontend_Dashboard_Secret_Store::has( $secret_id );
		?>
		<div class="hal-fd-secret" data-hal-secret="<?php echo esc_attr( $secret_id ); ?>">
			<h3><?php echo esc_html( $label ); ?></h3>
			<p>
				<?php
				echo esc_html(
					$configured
						? __( 'Configured: yes — the stored value is never displayed or sent back.', 'hal-frontend-dashboard' )
						: __( 'Configured: no.', 'hal-frontend-dashboard' )
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-hal-form>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SET_SECRET ); ?>" />
				<input type="hidden" name="secret_id" value="<?php echo esc_attr( $secret_id ); ?>" />
				<?php if ( '' !== $tab ) : ?>
					<input type="hidden" name="secret_tab" value="<?php echo esc_attr( $tab ); ?>" />
				<?php endif; ?>
				<?php wp_nonce_field( self::NONCE_ACTION . ':set_secret', self::NONCE_FIELD ); ?>
				<label class="screen-reader-text" for="hal-secret-value-<?php echo esc_attr( $secret_id ); ?>"><?php echo esc_html( $label ); ?></label>
				<input type="password" id="hal-secret-value-<?php echo esc_attr( $secret_id ); ?>" name="secret_value" value="" autocomplete="new-password" data-hal-secret-input />
				<?php submit_button( __( 'Replace with a full new value', 'hal-frontend-dashboard' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php if ( $configured ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-hal-form>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_DELETE_SECRET ); ?>" />
					<input type="hidden" name="secret_id" value="<?php echo esc_attr( $secret_id ); ?>" />
					<?php if ( '' !== $tab ) : ?>
						<input type="hidden" name="secret_tab" value="<?php echo esc_attr( $tab ); ?>" />
					<?php endif; ?>
					<?php wp_nonce_field( self::NONCE_ACTION . ':delete_secret', self::NONCE_FIELD ); ?>
					<?php submit_button( __( 'Delete stored value', 'hal-frontend-dashboard' ), 'link-delete', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Redirect messages from the handlers — generic sanitized texts with no
	 * sensitive details.
	 */
	private static function render_notice_from_query(): void {
		$message = isset( $_GET['hal_msg'] ) ? sanitize_key( wp_unslash( (string) $_GET['hal_msg'] ) ) : '';
		if ( '' === $message ) {
			return;
		}
		$messages = array(
			'saved'          => array( __( 'Settings saved.', 'hal-frontend-dashboard' ), 'success' ),
			'save_failed'    => array( __( 'Settings were not saved — the submitted values were rejected.', 'hal-frontend-dashboard' ), 'error' ),
			'secret_saved'   => array( __( 'Secret stored (encrypted).', 'hal-frontend-dashboard' ), 'success' ),
			'secret_failed'  => array( __( 'The secret was not stored — encrypted storage is blocked or the value was rejected.', 'hal-frontend-dashboard' ), 'error' ),
			'secret_failed_sodium'  => array( __( 'The secret was not stored — the PHP sodium extension is missing on this server, so encrypted storage is unavailable.', 'hal-frontend-dashboard' ), 'error' ),
			'secret_failed_key'     => array( __( 'The secret was not stored — no valid external encryption key was found. Set HAL_FRONTEND_DASHBOARD_SECRET_KEY in the environment or wp-config.php (32 raw bytes, 64 hex characters, or base64 of 32 bytes) — see the Status tab.', 'hal-frontend-dashboard' ), 'error' ),
			'secret_failed_persist' => array( __( 'The secret was not stored — writing the encrypted record to the database failed.', 'hal-frontend-dashboard' ), 'error' ),
			'secret_failed_value'   => array( __( 'The secret was not stored — the submitted value was rejected (empty or malformed input).', 'hal-frontend-dashboard' ), 'error' ),
			'secret_deleted' => array( __( 'Secret deleted.', 'hal-frontend-dashboard' ), 'success' ),
			'secret_missing' => array( __( 'No stored secret matched that id.', 'hal-frontend-dashboard' ), 'error' ),
			'unknown_secret' => array( __( 'Unknown secret id.', 'hal-frontend-dashboard' ), 'error' ),
			'health_run'     => array( __( 'Integration health check completed.', 'hal-frontend-dashboard' ), 'success' ),
			'forbidden'      => array( __( 'You are not allowed to perform that action.', 'hal-frontend-dashboard' ), 'error' ),
		);
		if ( ! isset( $messages[ $message ] ) ) {
			return;
		}
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			'success' === $messages[ $message ][1] ? 'success' : 'error',
			esc_html( $messages[ $message ][0] )
		);
	}
}

HAL_Frontend_Dashboard_Admin_Controller::register();
