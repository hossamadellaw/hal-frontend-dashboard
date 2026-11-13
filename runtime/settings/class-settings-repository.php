<?php
/**
 * runtime/settings/class-settings-repository.php — Settings Repository (batch 3)
 * ══════════════════════════════════════════════════════════════
 * Contract (architecture §14 and §8.3 and owner decisions §2.3/§2.4):
 *   - schema/version/defaults and getters are typed with strict
 *     validation; no unknown keys and no free-form shapes.
 *   - A single non-sensitive option with a fixed name and autoload=yes
 *     holding a small measured value of 373B — consensus-cycle decision
 *     v1.0.9 (row R6); no secret and no ciphertext here (Secret Store is
 *     a separate file) and no user data.
 *   - Settings are site data outside releases/state and persist across
 *     update/rollback (they do not enter the release manifest — §2.5).
 *   - defaults preserve the migrated functional behavior: every feature
 *     owner enabled=true (the effective decision later combines registry/
 *     capability/ownership in their batches — the setting alone here
 *     grants no resource permission); the logo attachment id=0 (the HAL
 *     textual fallback, owner decision); ai_preference=auto (enum from
 *     the unified reference §7.4).
 *   - The in-request cache is invalidated on save and during
 *     invalidate_cache().
 *   - $_POST is not read and options are not read directly from the
 *     controller: the controller passes sanitized values to save() alone.
 * ══════════════════════════════════════════════════════════════
 */

defined( 'ABSPATH' ) || exit;

final class HAL_Frontend_Dashboard_Settings_Repository {

	const SCHEMA_VERSION = '1.0.0';
	const OPTION_NAME    = 'hal_frontend_dashboard_settings';

	/**
	 * The approved management capability (owner decision §2.3): runtime
	 * checks use the capability only, and the default grants come from the
	 * Installer.
	 */
	const CAPABILITY = 'manage_hal_frontend_dashboard';

	/**
	 * The twelve approved features (architecture §7.6 Feature toggles) —
	 * no toggle outside this list. v1.0.6 §9 (doc:121): rich_editor was
	 * registered here to become togglable through the public path, with an
	 * intentionally closed default.
	 */
	const FEATURES = array(
		'posts', 'files', 'inbox', 'members', 'amelia', 'finance', 'store',
		'wpml_translations', 'rank_math_seo', 'ultimate_member_profile', 'ai',
		'rich_editor',
	);

	/**
	 * Allowed AI preference values (unified reference §7.4): auto or
	 * wp_ai_client or direct_key — only manage_options changes them
	 * (enforced in the controller).
	 */
	const AI_PREFERENCES = array( 'auto', 'wp_ai_client', 'direct_key' );

	/**
	 * OEB/1 (H1 §3): rich editor modes — an independent sibling key of
	 * ai_preference and not a member of FEATURES (the FEATURES contract is
	 * purely logical and must not be broken). The embed default = today's
	 * behavior verbatim (fail-closed) until an explicit owner decision.
	 */
	const EDITOR_MODES = array( 'official', 'embed', 'off' );

	/**
	 * Allowed logo image types (architecture §7.6 Branding): PNG/JPEG/WebP;
	 * SVG is rejected by default and accepted only via a separate Change
	 * Request.
	 */
	const BRANDING_ALLOWED_MIME = array( 'image/png', 'image/jpeg', 'image/webp' );

	/**
	 * v1.1.1 (W1): site title and tagline character caps — exceeding them
	 * rejects the whole form (WP_Error in save() and save_failed in the
	 * controller).
	 */
	const BRANDING_SITE_TITLE_MAX   = 60;
	const BRANDING_SITE_TAGLINE_MAX = 80;

	/**
	 * v1.1.1 (W3): the eight closed site section keys — a verbatim match
	 * for the anchors of the fallback loop in templates/dashboard.php (the
	 * fallback section $section_defs). No key outside this list is accepted
	 * from input or stored.
	 */
	const SITE_SECTION_KEYS = array(
		'home', 'about', 'services', 'blog', 'contact', 'privacy', 'terms', 'shop',
	);

	/** @var array<int,array<string,mixed>> per-blog cache (multisite isolation) */
	private static $cache = array();

	/**
	 * v1.1.1 (W1): character length — mb_strlen when available, otherwise
	 * strlen (the existing rankmath.php:161 and ajax/seo.php:69 pattern:
	 * shim and test environments without mbstring stay valid; mbstring is
	 * available in production, so the measure is characters, not bytes).
	 */
	private static function text_length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}

	/**
	 * The current cache key: the blog id when available, otherwise one
	 * context. On Single Site the behavior stays exactly the same (one
	 * fixed key).
	 */
	private static function cache_blog_id(): int {
		return function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
	}

	/**
	 * The full state after merging with defaults and validating against
	 * the schema.
	 *
	 * @return array{schema_version:string, features:array<string,bool>, branding_attachment_id:int, ai_preference:string, rich_editor_mode:string, branding_site_title:string, branding_site_tagline:string, site_section_urls:array<string,string>}
	 */
	public static function get_all(): array {
		$blog = self::cache_blog_id();
		if ( isset( self::$cache[ $blog ] ) ) {
			return self::$cache[ $blog ];
		}
		$stored = get_option( self::OPTION_NAME, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$features = array();
		foreach ( self::FEATURES as $feature ) {
			// v1.0.6 §9 (doc:121): rich_editor with an intentionally closed
			// default — the general true is not extended to it (fail-closed
			// until an owner assignment).
			$default = 'rich_editor' === $feature ? false : true;
			$features[ $feature ] = isset( $stored['features'][ $feature ] )
				? (bool) $stored['features'][ $feature ]
				: $default;
		}

		$attachment_id = isset( $stored['branding_attachment_id'] ) ? (int) $stored['branding_attachment_id'] : 0;

		$ai_preference = 'auto';
		if ( isset( $stored['ai_preference'] ) && in_array( $stored['ai_preference'], self::AI_PREFERENCES, true ) ) {
			$ai_preference = (string) $stored['ai_preference'];
		}

		// OEB/1 (H1 §3): fail-closed rich editor mode normalization — an
		// absent key or any value outside EDITOR_MODES returns embed (the
		// ai_preference normalization pattern above): upgrading changes
		// nothing for any site before an explicit owner decision.
		$editor_mode = 'embed';
		if ( isset( $stored['rich_editor_mode'] ) && in_array( $stored['rich_editor_mode'], self::EDITOR_MODES, true ) ) {
			$editor_mode = (string) $stored['rich_editor_mode'];
		}

		// v1.1.1 (W1): site title and tagline normalization in the verbatim
		// ai_preference pattern — non-string or over-cap values are
		// normalized to '' (fail-closed); '' means the existing textual
		// fallback at every position in the template. The values are user
		// data escaped only at render time (no i18n and no hossam_t).
		$branding_site_title = '';
		if ( isset( $stored['branding_site_title'] ) && is_string( $stored['branding_site_title'] )
			&& self::text_length( $stored['branding_site_title'] ) <= self::BRANDING_SITE_TITLE_MAX ) {
			$branding_site_title = $stored['branding_site_title'];
		}

		$branding_site_tagline = '';
		if ( isset( $stored['branding_site_tagline'] ) && is_string( $stored['branding_site_tagline'] )
			&& self::text_length( $stored['branding_site_tagline'] ) <= self::BRANDING_SITE_TAGLINE_MAX ) {
			$branding_site_tagline = $stored['branding_site_tagline'];
		}

		// v1.1.1 (W3): site section URL normalization — a non-array returns
		// the eight with '' (auto via the existing resolver); an unknown
		// stored key is ignored; a non-string value is normalized to ''
		// (malformed = empty = auto).
		$site_section_urls = array_fill_keys( self::SITE_SECTION_KEYS, '' );
		if ( isset( $stored['site_section_urls'] ) && is_array( $stored['site_section_urls'] ) ) {
			foreach ( self::SITE_SECTION_KEYS as $section_key ) {
				$section_value = isset( $stored['site_section_urls'][ $section_key ] ) ? $stored['site_section_urls'][ $section_key ] : null;
				if ( is_string( $section_value ) ) {
					$site_section_urls[ $section_key ] = $section_value;
				}
			}
		}

		self::$cache[ $blog ] = array(
			'schema_version'         => self::SCHEMA_VERSION,
			'features'               => $features,
			'branding_attachment_id' => $attachment_id > 0 ? $attachment_id : 0,
			'ai_preference'          => $ai_preference,
			'rich_editor_mode'       => $editor_mode,
			'branding_site_title'    => $branding_site_title,
			'branding_site_tagline'  => $branding_site_tagline,
			'site_section_urls'      => $site_section_urls,
		);
		return self::$cache[ $blog ];
	}

	/**
	 * A single feature state — keys outside the list fail closed (false).
	 */
	public static function is_feature_enabled( string $feature ): bool {
		$all = self::get_all();
		return isset( $all['features'][ $feature ] ) && true === $all['features'][ $feature ];
	}

	public static function get_branding_attachment_id(): int {
		$attachment_id = self::get_all()['branding_attachment_id'];
		if ( $attachment_id > 0 && is_wp_error( self::validate_branding_attachment_id( $attachment_id ) ) ) {
			// The attachment was deleted or is no longer a readable image of
			// the approved types: a safe textual fallback (owner decision
			// §2.3) — 0 is returned and the consumer displays the HAL text
			// mark.
			return 0;
		}
		return $attachment_id;
	}

	/**
	 * AI strategy preference — a closed enum (auto/wp_ai_client/direct_key).
	 */
	public static function get_ai_preference(): string {
		return self::get_all()['ai_preference'];
	}

	/**
	 * OEB/1 (H1 §3): the raw normalized mode of the sibling
	 * rich_editor_mode key — absent/corrupt is normalized to embed in
	 * get_all() (fail-closed); the get_ai_preference pattern.
	 */
	public static function get_rich_editor_mode(): string {
		return self::get_all()['rich_editor_mode'];
	}

	/**
	 * OEB/1 (H1 §3): the effective rich editor mode — the existing
	 * rich_editor key stays the fail-closed master of the whole rich
	 * surface: disabling it makes the effective mode off regardless of
	 * rich_editor_mode.
	 */
	public static function get_effective_editor_mode(): string {
		if ( ! self::is_feature_enabled( 'rich_editor' ) ) {
			return 'off';
		}
		return self::get_rich_editor_mode();
	}

	/**
	 * Validate that the attachment id is a readable image within the
	 * allowed types (PNG/JPEG/WebP; SVG is rejected by default — §7.6). A
	 * missing/deleted attachment is rejected here and the 0 remains (safe
	 * textual fallback).
	 *
	 * @return true|WP_Error
	 */
	public static function validate_branding_attachment_id( int $attachment_id ) {
		if ( $attachment_id <= 0 ) {
			return new WP_Error( 'hal_settings_branding_invalid_id', 'Invalid attachment id.' );
		}
		$post = get_post( $attachment_id );
		if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'hal_settings_branding_not_attachment', 'The id is not a Media Library attachment.' );
		}
		$mime = (string) get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, self::BRANDING_ALLOWED_MIME, true ) ) {
			return new WP_Error( 'hal_settings_branding_mime_rejected', 'Only PNG, JPEG and WebP images are accepted.' );
		}
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return new WP_Error( 'hal_settings_branding_not_image', 'The attachment is not a readable image.' );
		}
		return true;
	}

	/**
	 * The only write path for settings: strict validation of every known
	 * key, and wholesale rejection of unknown keys (no unknown shapes —
	 * §14). Secrets are never accepted here.
	 *
	 * @param array{features?:array<string,bool>, branding_attachment_id?:int, ai_preference?:string, rich_editor_mode?:string, branding_site_title?:string, branding_site_tagline?:string, site_section_urls?:array<string,string>} $settings
	 * @return true|WP_Error
	 */
	public static function save( array $settings ) {
		if ( isset( $settings['schema_version'] ) ) {
			return new WP_Error( 'hal_settings_reserved_key', 'schema_version is managed internally.' );
		}

		$current = self::get_all();

		if ( array_key_exists( 'features', $settings ) ) {
			$features = $settings['features'];
			if ( ! is_array( $features ) ) {
				return new WP_Error( 'hal_settings_features_shape', 'features must be an array of booleans.' );
			}
			foreach ( $features as $feature => $enabled ) {
				if ( ! in_array( (string) $feature, self::FEATURES, true ) || ! is_bool( $enabled ) ) {
					return new WP_Error( 'hal_settings_feature_invalid', 'Unknown feature key or non-boolean value.' );
				}
			}
			foreach ( $features as $feature => $enabled ) {
				$current['features'][ (string) $feature ] = $enabled;
			}
		}

		if ( array_key_exists( 'branding_attachment_id', $settings ) ) {
			$attachment_id = $settings['branding_attachment_id'];
			if ( ! is_int( $attachment_id ) || $attachment_id < 0 ) {
				return new WP_Error( 'hal_settings_branding_shape', 'branding_attachment_id must be a non-negative integer.' );
			}
			if ( $attachment_id > 0 ) {
				$validated = self::validate_branding_attachment_id( $attachment_id );
				if ( is_wp_error( $validated ) ) {
					return $validated;
				}
			}
			$current['branding_attachment_id'] = $attachment_id;
		}

		if ( array_key_exists( 'ai_preference', $settings ) ) {
			$preference = $settings['ai_preference'];
			if ( ! is_string( $preference ) || ! in_array( $preference, self::AI_PREFERENCES, true ) ) {
				return new WP_Error( 'hal_settings_ai_preference_invalid', 'ai_preference must be one of the allowlisted values.' );
			}
			$current['ai_preference'] = $preference;
		}

		// OEB/1 (H1 §3): the rich_editor_mode block in the verbatim
		// ai_preference pattern — no values outside EDITOR_MODES ever.
		if ( array_key_exists( 'rich_editor_mode', $settings ) ) {
			$editor_mode = $settings['rich_editor_mode'];
			if ( ! is_string( $editor_mode ) || ! in_array( $editor_mode, self::EDITOR_MODES, true ) ) {
				return new WP_Error( 'hal_settings_rich_editor_mode_invalid', 'rich_editor_mode must be one of the allowlisted values.' );
			}
			$current['rich_editor_mode'] = $editor_mode;
		}

		// v1.1.1 (W1): the site title and tagline blocks — free text (user
		// data escaped only at render time) with shape validation and an
		// mb_strlen cap; exceeding the cap rejects the whole form
		// (fail-closed).
		if ( array_key_exists( 'branding_site_title', $settings ) ) {
			$site_title = $settings['branding_site_title'];
			if ( ! is_string( $site_title ) || self::text_length( $site_title ) > self::BRANDING_SITE_TITLE_MAX ) {
				return new WP_Error( 'hal_settings_branding_site_title_invalid', 'branding_site_title must be a string of at most 60 characters.' );
			}
			$current['branding_site_title'] = $site_title;
		}

		if ( array_key_exists( 'branding_site_tagline', $settings ) ) {
			$site_tagline = $settings['branding_site_tagline'];
			if ( ! is_string( $site_tagline ) || self::text_length( $site_tagline ) > self::BRANDING_SITE_TAGLINE_MAX ) {
				return new WP_Error( 'hal_settings_branding_site_tagline_invalid', 'branding_site_tagline must be a string of at most 80 characters.' );
			}
			$current['branding_site_tagline'] = $site_tagline;
		}

		// v1.1.1 (W3): the site section URLs block — an array of the eight
		// closed keys exclusively: a key outside them or a non-string value
		// rejects the whole form; storage is always for all eight in full
		// (an absent one is normalized to '' = auto).
		if ( array_key_exists( 'site_section_urls', $settings ) ) {
			$section_urls = $settings['site_section_urls'];
			if ( ! is_array( $section_urls ) ) {
				return new WP_Error( 'hal_settings_site_section_urls_shape', 'site_section_urls must be an array keyed by the eight closed section keys.' );
			}
			foreach ( $section_urls as $section_key => $section_url ) {
				if ( ! in_array( (string) $section_key, self::SITE_SECTION_KEYS, true ) || ! is_string( $section_url ) ) {
					return new WP_Error( 'hal_settings_site_section_urls_invalid', 'Unknown site section key or non-string URL value.' );
				}
			}
			$normalized_urls = array_fill_keys( self::SITE_SECTION_KEYS, '' );
			foreach ( self::SITE_SECTION_KEYS as $section_key ) {
				if ( isset( $section_urls[ $section_key ] ) && is_string( $section_urls[ $section_key ] ) ) {
					$normalized_urls[ $section_key ] = $section_urls[ $section_key ];
				}
			}
			$current['site_section_urls'] = $normalized_urls;
		}

		// v1.1.1 (W1/W3): the unknown-key guard expands with the three new
		// keys — no unknown shapes.
		foreach ( $settings as $key => $value ) {
			if ( ! in_array( $key, array( 'features', 'branding_attachment_id', 'ai_preference', 'rich_editor_mode', 'branding_site_title', 'branding_site_tagline', 'site_section_urls' ), true ) ) {
				return new WP_Error( 'hal_settings_unknown_key', 'Unknown settings key.' );
			}
		}

		$stored_raw = get_option( self::OPTION_NAME, array() );
		$stored_raw = is_array( $stored_raw ) ? $stored_raw : array();

		// B3-PERSIST (§8.4: no automatic downgrade, and compatibility during
		// the rollback window): the write starts from the store as it is —
		// any key or section or feature key written by a newer version (a
		// higher schema or keys this version does not know) stays intact,
		// and this version places its known keys on top of it only. A newer
		// schema version is not downgraded; this version invents no
		// migration — it reads what it knows and tolerates the rest.
		$stored_schema = isset( $stored_raw['schema_version'] ) && is_string( $stored_raw['schema_version'] )
			? $stored_raw['schema_version']
			: null;
		$newer_schema  = null !== $stored_schema && version_compare( $stored_schema, self::SCHEMA_VERSION, '>' );
		$payload       = $stored_raw;

		$stored_features = isset( $stored_raw['features'] ) && is_array( $stored_raw['features'] )
			? $stored_raw['features']
			: array();
		$merged_features = $stored_features;
		foreach ( $current['features'] as $feature_key => $feature_value ) {
			$merged_features[ $feature_key ] = $feature_value;
		}
		$payload['features'] = $merged_features;
		$payload['branding_attachment_id'] = $current['branding_attachment_id'];
		$payload['ai_preference'] = $current['ai_preference'];
		$payload['rich_editor_mode'] = $current['rich_editor_mode'];
		// v1.1.1 (W1/W3): the three new keys are always written from the
		// normalized $current — all eight in full in site_section_urls.
		$payload['branding_site_title'] = $current['branding_site_title'];
		$payload['branding_site_tagline'] = $current['branding_site_tagline'];
		$payload['site_section_urls'] = $current['site_section_urls'];
		if ( ! $newer_schema ) {
			$payload['schema_version'] = self::SCHEMA_VERSION;
		}

		// B3-02: distinguishing no-change from a write failure —
		// update_option returns false in both cases, so the explicit
		// comparison prevents declaring a false success when writing an
		// actually different value fails.
		if ( $stored_raw === $payload ) {
			self::invalidate_cache();
			return true;
		}

		// R6 (G5 §5-1): the third argument true (bool → 'on') — every actual
		// save re-establishes autoload and persistence by construction; no
		// null and no string values.
		$written = update_option( self::OPTION_NAME, $payload, true );
		self::invalidate_cache();
		if ( false === $written ) {
			return new WP_Error( 'hal_settings_write_failed', 'The settings write failed; the previously stored values remain intact.' );
		}
		return true;
	}

	/**
	 * Invalidate the request cache — called after saving and made available
	 * to consumers when a forced re-read is needed (a one-capability
	 * decision, §8.3). It clears every blog: a write to one blog must not
	 * leave a stale read for any blog.
	 */
	public static function invalidate_cache(): void {
		self::$cache = array();
	}

	/**
	 * R6 (G5 §5-2): the one-time migration of the settings option's
	 * autoload — registered on init in the triple ensure_table pattern
	 * (tables.php:200-219). A verbatim flag guard
	 * `hossam_settings_autoload_migrated`: when the flag is absent, the
	 * existing option row is migrated via `wp_set_option_autoload_values`
	 * (boolean values are the stable path after 6.7.0 deprecated the
	 * textual 'yes'/'no') and the flag is then created with a default
	 * autoload — the opposite of the intended C7 lesson for no-autoload
	 * options: a small flag read from alloptions on every request with no
	 * SQL in steady state. The two function_exists guards preserve shim/
	 * older-version environments, and the writer above re-establishes the
	 * autoload with every actual save anyway.
	 */
	public static function init(): void {
		if ( get_option( 'hossam_settings_autoload_migrated' ) ) {
			return;
		}
		if ( function_exists( 'wp_set_option_autoload_values' ) ) {
			wp_set_option_autoload_values( array( self::OPTION_NAME => true ) );
		}
		if ( function_exists( 'add_option' ) ) {
			add_option( 'hossam_settings_autoload_migrated', true );
		}
	}
}

// R6 (G5 §5-2): the tables.php/audit-log.php pattern — registering the one-time migration on init.
add_action( 'init', function (): void {
	HAL_Frontend_Dashboard_Settings_Repository::init();
} );
