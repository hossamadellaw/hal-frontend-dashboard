<?php
/**
 * includes/class-update-bridge.php — Update Bridge (Batch 11)
 * ══════════════════════════════════════════════════════════════
 * Shared source (§6.1): this copy in includes/ is the source of truth;
 * the build copies it byte-identical to runtime/infrastructure/
 * (the batch-11 workflow owns regeneration and enforces no-drift).
 *
 * Contract (update-system §§4–5 + architecture §7.3):
 *   - PUC (yahnis-elsts/plugin-update-checker ~5.7, namespace v5p7) is
 *     referenced ONLY behind class_exists guards. This file loads and
 *     no-ops safely when PUC is absent (no vendor require at file load;
 *     the runtime vendor/autoload.php is consulted lazily inside
 *     boot_puc() only).
 *   - Exclusive asset regex (case-sensitive, no leading zeros):
 *       /\Ahal-frontend-dashboard-(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.zip\z/
 *   - vcs_update_detection_strategies is restricted to STRATEGY_LATEST_RELEASE
 *     only, in the exact shape of update-system §4 (adapted to a static
 *     method so the file stays load-safe without PUC: the GitHubApi class
 *     is resolved by name at call time, never at file load).
  *   - auto_update_plugin returns true ONLY for the exact basename
  *     hal-frontend-dashboard/hal-frontend-dashboard.php + the exact slug
  *     + a higher valid version + a repo-bound PUC-shaped package
  *     (https github.com URL under this repository's path whose asset
  *     basename matches the regex with the offered version embedded).
  *   - upgrader_pre_download + upgrader_source_selection apply ONLY to the
  *     exact basename — singular hook_extra['plugin'] and bulk
  *     hook_extra['plugins'] alike; ambiguous hook_extra and foreign-only
  *     bulk arrays pass through untouched and never affect other plugins.
 *   - upgrader_process_complete only records the candidate and schedules
 *     a deferred import (cron). It never promotes and never loads the new
 *     Carrier code.
 *   - Works from both includes/ (Carrier) and runtime/infrastructure/ (MU)
 *     via constants with fallbacks (carrier_plugin_file(),
 *     vendor_autoload_path()).
 *   - init() registers hooks ONLY in admin/cron/CLI contexts (no network
 *     in the visitor path); every entry point re-checks the context.
 *
 * No state, no network at file load. All failures are fail-closed with a
 * redacted code; no paths, versions beyond the offer, or secrets leak.
 * ══════════════════════════════════════════════════════════════
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'HAL_Frontend_Dashboard_Update_Bridge', false ) ) {
	final class HAL_Frontend_Dashboard_Update_Bridge {
		public const PLUGIN_BASENAME = 'hal-frontend-dashboard/hal-frontend-dashboard.php';
		public const SLUG = 'hal-frontend-dashboard';
		public const REPOSITORY_URL = 'https://github.com/hossamadellaw/hal-frontend-dashboard';
		public const ASSET_REGEX = '/\Ahal-frontend-dashboard-(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.zip\z/';
		public const VERSION_PATTERN = '/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\z/';
		public const IMPORT_CRON_HOOK = 'hal_frontend_dashboard_import_candidate';
		public const IMPORT_OPTION = 'hal_frontend_dashboard_update_candidate';
		public const PUC_FACTORY = 'YahnisElsts\\PluginUpdateChecker\\v5p7\\PucFactory';
		public const PUC_GITHUB_API = 'YahnisElsts\\PluginUpdateChecker\\v5p7\\Vcs\\GitHubApi';

		/** Mirrors the verifier compressed-size ceiling for Carrier downloads. */
		public const CARRIER_MAX_BYTES = 134217728;

		private static bool $initialized = false;

		/**
		 * Update contexts only: WP admin (WP_ADMIN covers wp-admin/* and
		 * admin-ajax at MU-load time, when is_admin() is still false),
		 * cron, or CLI. Visitor page builds never initialize.
		 */
		public static function is_update_context(): bool {
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				return true;
			}
			if ( defined( 'WP_ADMIN' ) && WP_ADMIN ) {
				return true;
			}
			if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
				return true;
			}
			if ( function_exists( 'is_admin' ) && is_admin() ) {
				return true;
			}
			return false;
		}

		/**
		 * Register update hooks + boot PUC. Safe to call in any context
		 * (no-op outside update contexts) and idempotent.
		 */
		public static function init(): void {
			if ( self::$initialized ) {
				return;
			}
			if ( ! self::is_update_context() ) {
				return;
			}
			self::$initialized = true;
			add_filter( 'auto_update_plugin', array( self::class, 'filter_auto_update' ), 10, 2 );
			add_filter( 'upgrader_pre_download', array( self::class, 'filter_pre_download' ), 10, 3 );
			add_filter( 'upgrader_source_selection', array( self::class, 'filter_source_selection' ), 10, 4 );
			add_action( 'upgrader_process_complete', array( self::class, 'action_upgrader_complete' ), 10, 2 );
			self::boot_puc();
		}

		/**
		 * Boot PUC against the public repository. Returns the checker on
		 * success, null when PUC is unavailable (never fatal).
		 *
		 * @return mixed
		 */
		public static function boot_puc() {
			$autoload = self::vendor_autoload_path();
			if ( is_string( $autoload ) && '' !== $autoload && ! class_exists( self::PUC_FACTORY, false ) ) {
				require_once $autoload;
			}
			if ( ! class_exists( self::PUC_FACTORY ) || ! class_exists( self::PUC_GITHUB_API ) ) {
				return null;
			}
			if ( ! is_callable( array( self::PUC_FACTORY, 'buildUpdateChecker' ) ) ) {
				return null;
			}
			$plugin_file = self::carrier_plugin_file();
			if ( '' === $plugin_file ) {
				return null;
			}
			try {
				$checker = call_user_func(
					array( self::PUC_FACTORY, 'buildUpdateChecker' ),
					self::REPOSITORY_URL,
					$plugin_file,
					self::SLUG
				);
			} catch ( Throwable $exception ) {
				self::log_safe( 'puc_boot_failed' );
				return null;
			}
			if ( ! is_object( $checker ) || ! method_exists( $checker, 'getVcsApi' ) || ! method_exists( $checker, 'addFilter' ) ) {
				return null;
			}
		try {
			$api = $checker->getVcsApi();
			if ( is_object( $api ) && method_exists( $api, 'enableReleaseAssets' ) ) {
				// Update-system §4 mandates REQUIRE_RELEASE_ASSETS (no
				// source-archive fallback when the release carries no
				// matching asset). Fail closed if the locked library
				// cannot express it — never silently downgrade to PREFER.
				$vcs_api = 'YahnisElsts\\PluginUpdateChecker\\v5p7\\Vcs\\Api';
				if ( ! class_exists( $vcs_api ) || ! defined( $vcs_api . '::REQUIRE_RELEASE_ASSETS' ) ) {
					self::log_safe( 'puc_require_unsupported' );
					return null;
				}
				$api->enableReleaseAssets( self::ASSET_REGEX, constant( $vcs_api . '::REQUIRE_RELEASE_ASSETS' ) );
			}
			$checker->addFilter( 'vcs_update_detection_strategies', array( self::class, 'filter_update_detection_strategies' ) );
		} catch ( Throwable $exception ) {
			self::log_safe( 'puc_configure_failed' );
			return null;
		}
			return $checker;
		}

		/**
		 * Update-system §4 shape: keep STRATEGY_LATEST_RELEASE only; an
		 * offer without that strategy (or without a matching asset)
		 * yields no update. Passes input through when PUC is absent.
		 *
		 * @param mixed $strategies
		 * @return array
		 */
		public static function filter_update_detection_strategies( $strategies ): array {
			if ( ! class_exists( self::PUC_GITHUB_API ) ) {
				return is_array( $strategies ) ? $strategies : array();
			}
			$latest = constant( self::PUC_GITHUB_API . '::STRATEGY_LATEST_RELEASE' );
			if ( ! is_array( $strategies ) || ! isset( $strategies[ $latest ] ) ) {
				return array();
			}
			return array( $latest => $strategies[ $latest ] );
		}

		/**
	 * Update-system §5: true only for the exact basename + exact slug
	 * (empty rejected) + higher valid version + repo-bound PUC-shaped
	 * package. Everything else (including ambiguity) passes $update
	 * through untouched.
	 *
	 * @param mixed $update
	 * @param mixed $item
	 * @return mixed
	 */
	public static function filter_auto_update( $update, $item ) {
		if ( self::PLUGIN_BASENAME !== self::item_field( $item, 'plugin' ) ) {
			return $update;
		}
		$slug = self::item_field( $item, 'slug' );
		if ( self::SLUG !== $slug ) {
			return $update;
		}
		$new_version = self::item_field( $item, 'new_version' );
		if ( 1 !== preg_match( self::VERSION_PATTERN, $new_version ) ) {
			return $update;
		}
		$installed_version = self::installed_version();
		if ( '' === $installed_version ) {
			return $update;
		}
		if ( version_compare( $new_version, $installed_version, '<=' ) ) {
			return $update;
		}
		$package = self::item_field( $item, 'package' );
		if ( ! self::is_puc_shaped_package( $package, $new_version ) ) {
			return $update;
		}
		// Simple Multisite: the shared Carrier update is a network-level
		// decision. A logged-in user without the network capability never
		// gets it auto-applied; system contexts (cron/CLI, no user) keep
		// the network-owned automation. Single-site behavior unchanged.
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$uid = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
			if ( 0 !== $uid && !( function_exists( 'current_user_can' ) && current_user_can( 'manage_network' ) ) ) {
				return $update;
			}
		}
		return true;
	}

	/**
	 * upgrader_pre_download: verify the Carrier ZIP (streams only, no
	 * extraction) before WordPress touches it. Scoped to the exact
	 * basename — singular hook_extra['plugin'] and bulk
	 * hook_extra['plugins'] alike; ambiguity and foreign-only bulk
	 * arrays pass through untouched.
	 *
	 * @param mixed $reply
	 * @param mixed $package
	 * @param mixed $upgrader
	 * @return mixed
	 */
	public static function filter_pre_download( $reply, $package, $upgrader = null ) {
		if ( false !== $reply ) {
			return $reply;
		}
		if ( ! in_array( self::PLUGIN_BASENAME, self::hook_extra_plugins_from_upgrader( $upgrader ), true ) ) {
			return $reply;
		}
		return self::verified_carrier_download( $package );
	}

	/**
	 * upgrader_source_selection: re-verify the unpacked tree (root +
	 * inventory + identity) before WordPress overwrites the Carrier.
	 * Scoped to the exact basename — singular hook_extra['plugin'] and
	 * bulk hook_extra['plugins'] alike; ambiguity and foreign-only bulk
	 * arrays pass through untouched.
	 *
	 * @param mixed $source
	 * @param mixed $remote_source
	 * @param mixed $upgrader
	 * @param mixed $hook_extra
	 * @return mixed
	 */
	public static function filter_source_selection( $source, $remote_source = null, $upgrader = null, $hook_extra = null ) {
		if ( ! in_array( self::PLUGIN_BASENAME, self::hook_extra_plugins( $hook_extra ), true ) ) {
			return $source;
		}
			if ( ! is_string( $source ) || '' === $source ) {
				return new WP_Error( 'hal_carrier_source_rejected', 'The HAL Frontend Dashboard update package failed verification.' );
			}
			try {
				$root = rtrim( $source, '/\\' );
				self::package_verifier()->verify_carrier_tree(
					$root,
					$root . DIRECTORY_SEPARATOR . 'carrier-manifest.json',
					$root . DIRECTORY_SEPARATOR . 'carrier-manifest.sig'
				);
			} catch ( Throwable $exception ) {
				return new WP_Error( 'hal_carrier_source_rejected', 'The HAL Frontend Dashboard update package failed verification.' );
			}
			return $source;
		}

		/**
		 * upgrader_process_complete: record the candidate + schedule the
		 * deferred import. Runs as the OLD code; it never promotes and
		 * never loads the new Carrier.
		 *
		 * @param mixed $upgrader
		 * @param mixed $hook_extra
		 */
		public static function action_upgrader_complete( $upgrader = null, $hook_extra = null ): void {
			if ( ! is_array( $hook_extra ) ) {
				return;
			}
			if ( 'plugin' !== ( $hook_extra['type'] ?? '' ) || 'update' !== ( $hook_extra['action'] ?? '' ) ) {
				return;
			}
			$plugins = array();
			if ( isset( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) && '' !== $hook_extra['plugin'] ) {
				$plugins[] = $hook_extra['plugin'];
			}
			if ( isset( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
				foreach ( $hook_extra['plugins'] as $candidate ) {
					if ( is_string( $candidate ) && '' !== $candidate ) {
						$plugins[] = $candidate;
					}
				}
			}
			if ( ! in_array( self::PLUGIN_BASENAME, $plugins, true ) ) {
				return;
			}
			if ( function_exists( 'update_option' ) ) {
				update_option( self::IMPORT_OPTION, array( 'plugin' => self::PLUGIN_BASENAME, 'time' => time() ), false );
			}
			if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_single_event' ) ) {
				if ( false === wp_next_scheduled( self::IMPORT_CRON_HOOK ) ) {
					wp_schedule_single_event( time() + 60, self::IMPORT_CRON_HOOK );
				}
			}
		}

		/**
		 * Carrier main file PUC tracks. Constant first (both Carrier and
		 * MU contexts define it), then WP_PLUGIN_DIR, then the
		 * includes/-relative fallback (Carrier checkout without WP).
		 */
		public static function carrier_plugin_file(): string {
			if ( defined( 'HAL_FRONTEND_DASHBOARD_PLUGIN_FILE' ) ) {
				$candidate = (string) constant( 'HAL_FRONTEND_DASHBOARD_PLUGIN_FILE' );
				if ( '' !== $candidate ) {
					return $candidate;
				}
			}
			if ( defined( 'WP_PLUGIN_DIR' ) ) {
				return rtrim( (string) constant( 'WP_PLUGIN_DIR' ), '/\\' ) . '/hal-frontend-dashboard/hal-frontend-dashboard.php';
			}
			$carrier_dir = dirname( __DIR__ );
			if ( 'hal-frontend-dashboard' === basename( str_replace( '\\', '/', $carrier_dir ) ) ) {
				return $carrier_dir . DIRECTORY_SEPARATOR . 'hal-frontend-dashboard.php';
			}
			return '';
		}

		/**
		 * PUC lives in runtime/vendor/ only (root vendor is forbidden).
		 * Null when unavailable — boot_puc() then no-ops.
		 */
		public static function vendor_autoload_path(): ?string {
			foreach ( array( 'HAL_FRONTEND_DASHBOARD_RUNTIME_DIR', 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT' ) as $constant ) {
				if ( ! defined( $constant ) ) {
					continue;
				}
				$candidate = rtrim( (string) constant( $constant ), '/\\' ) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
				if ( is_file( $candidate ) && ! is_link( $candidate ) ) {
					return $candidate;
				}
			}
			return null;
		}

		/**
		 * Download + stream-verify a Carrier asset. Returns the verified
		 * temp path or a WP_Error. The temp file is removed on failure.
		 *
		 * @param mixed $package
		 * @return mixed
		 */
		public static function verified_carrier_download( $package ) {
			if ( ! is_string( $package ) || '' === $package ) {
				return new WP_Error( 'hal_carrier_download_rejected', 'The HAL Frontend Dashboard update package failed verification.' );
			}
			$scheme = strtolower( (string) wp_parse_url( $package, PHP_URL_SCHEME ) );
			$path = (string) wp_parse_url( $package, PHP_URL_PATH );
			if ( 'https' !== $scheme || 1 !== preg_match( self::ASSET_REGEX, basename( $path ) ) ) {
				return new WP_Error( 'hal_carrier_download_rejected', 'The HAL Frontend Dashboard update package failed verification.' );
			}
			if ( ! function_exists( 'download_url' ) ) {
				return new WP_Error( 'hal_carrier_download_rejected', 'The HAL Frontend Dashboard update package failed verification.' );
			}
			$temp = download_url( $package, 60 );
			if ( is_wp_error( $temp ) ) {
				return $temp;
			}
			if ( ! is_string( $temp ) || '' === $temp ) {
				return new WP_Error( 'hal_carrier_download_rejected', 'The HAL Frontend Dashboard update package failed verification.' );
			}
			$size = is_file( $temp ) && ! is_link( $temp ) ? filesize( $temp ) : false;
			if ( false === $size || $size < 1 || $size > self::CARRIER_MAX_BYTES ) {
				@unlink( $temp );
				return new WP_Error( 'hal_carrier_download_rejected', 'The HAL Frontend Dashboard update package failed verification.' );
			}
			try {
				self::package_verifier()->verify_carrier_archive( $temp );
			} catch ( Throwable $exception ) {
				@unlink( $temp );
				return new WP_Error( 'hal_carrier_download_rejected', 'The HAL Frontend Dashboard update package failed verification.' );
			}
			return $temp;
		}

	/**
	 * PUC-shaped package: https URL on the release repository host
	 * (github.com, case-insensitive) under this repository's path
	 * (case-sensitive) whose asset basename matches the exclusive
	 * regex with the offered version embedded — the public-release
	 * asset shape (…/releases/download/<tag>/<asset>).
	 */
	public static function is_puc_shaped_package( $package, string $version ): bool {
		if ( ! is_string( $package ) || '' === $package ) {
			return false;
		}
		$scheme = strtolower( (string) wp_parse_url( $package, PHP_URL_SCHEME ) );
		if ( 'https' !== $scheme ) {
			return false;
		}
		$host = strtolower( (string) wp_parse_url( $package, PHP_URL_HOST ) );
		if ( 'github.com' !== $host ) {
			return false;
		}
		$path = (string) wp_parse_url( $package, PHP_URL_PATH );
		if ( ! str_starts_with( $path, '/hossamadellaw/hal-frontend-dashboard/' ) ) {
			return false;
		}
		$basename = basename( $path );
		if ( 1 !== preg_match( self::ASSET_REGEX, $basename, $matches ) ) {
			return false;
		}
		return $version === $matches[1] . '.' . $matches[2] . '.' . $matches[3];
	}

		/**
		 * The shared verifier beside this file (both legal locations keep
		 * class-package-verifier.php next to the bridge).
		 */
		public static function package_verifier(): HAL_Frontend_Dashboard_Package_Verifier {
			if ( ! class_exists( 'HAL_Frontend_Dashboard_Package_Verifier', false ) ) {
				require_once __DIR__ . '/class-package-verifier.php';
			}
			return new HAL_Frontend_Dashboard_Package_Verifier();
		}

	/**
	 * B11-02: the installed version is the RUNNING release, not the
	 * Carrier file. MU loader defines HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION
	 * on every request (present even with the Carrier disabled); the Carrier
	 * HAL_FRONTEND_DASHBOARD_VERSION is only a fallback. Anything else
	 * (absent or malformed) is '' and callers stay fail-closed.
	 */
	private static function installed_version(): string {
		if ( defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION' ) ) {
			$runtime_version = (string) constant( 'HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION' );
			if ( 1 === preg_match( self::VERSION_PATTERN, $runtime_version ) ) {
				return $runtime_version;
			}
		}
		if ( defined( 'HAL_FRONTEND_DASHBOARD_VERSION' ) ) {
			$carrier_version = (string) constant( 'HAL_FRONTEND_DASHBOARD_VERSION' );
			if ( 1 === preg_match( self::VERSION_PATTERN, $carrier_version ) ) {
				return $carrier_version;
			}
		}
		return '';
	}

		private static function item_field( $item, string $field ): string {
			if ( is_object( $item ) && isset( $item->{$field} ) && is_scalar( $item->{$field} ) ) {
				return (string) $item->{$field};
			}
			if ( is_array( $item ) && isset( $item[ $field ] ) && is_scalar( $item[ $field ] ) ) {
				return (string) $item[ $field ];
			}
			return '';
		}

	/**
	 * Collect every candidate plugin basename from hook_extra: the
	 * singular hook_extra['plugin'] plus the bulk hook_extra['plugins']
	 * array WordPress uses for bulk upgrades. Strict strings only —
	 * non-strings and empties are ignored. An empty result means
	 * ambiguity — callers pass such input through untouched.
	 *
	 * @param mixed $hook_extra
	 * @return string[]
	 */
	private static function hook_extra_plugins( $hook_extra ): array {
		if ( ! is_array( $hook_extra ) ) {
			return array();
		}
		$plugins = array();
		if ( isset( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) && '' !== $hook_extra['plugin'] ) {
			$plugins[] = $hook_extra['plugin'];
		}
		if ( isset( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			foreach ( $hook_extra['plugins'] as $candidate ) {
				if ( is_string( $candidate ) && '' !== $candidate ) {
					$plugins[] = $candidate;
				}
			}
		}
		return $plugins;
	}

	/**
	 * Extract the candidate plugin basenames from the upgrader skin
	 * options (singular + bulk). Empty on any ambiguity (missing
	 * skin/options/hook_extra) — callers pass such input through.
	 *
	 * @param mixed $upgrader
	 * @return string[]
	 */
	private static function hook_extra_plugins_from_upgrader( $upgrader ): array {
		$options = null;
		if ( is_object( $upgrader ) && isset( $upgrader->skin ) ) {
			$skin = $upgrader->skin;
			if ( is_object( $skin ) && isset( $skin->options ) && is_array( $skin->options ) ) {
				$options = $skin->options;
			} elseif ( is_array( $skin ) && isset( $skin['options'] ) && is_array( $skin['options'] ) ) {
				$options = $skin['options'];
			}
		}
		if ( ! is_array( $options ) || ! isset( $options['hook_extra'] ) || ! is_array( $options['hook_extra'] ) ) {
			return array();
		}
		return self::hook_extra_plugins( $options['hook_extra'] );
	}

	/**
	 * Extract the target plugin basename from the upgrader skin
	 * options. Null on any ambiguity (missing skin/options/hook_extra
	 * or non-string plugin) — callers pass such input through.
	 *
	 * @param mixed $upgrader
	 */
		private static function hook_extra_plugin_from_upgrader( $upgrader ): ?string {
			$options = null;
			if ( is_object( $upgrader ) && isset( $upgrader->skin ) ) {
				$skin = $upgrader->skin;
				if ( is_object( $skin ) && isset( $skin->options ) && is_array( $skin->options ) ) {
					$options = $skin->options;
				} elseif ( is_array( $skin ) && isset( $skin['options'] ) && is_array( $skin['options'] ) ) {
					$options = $skin['options'];
				}
			}
			if ( ! is_array( $options ) || ! isset( $options['hook_extra'] ) || ! is_array( $options['hook_extra'] ) ) {
				return null;
			}
			$plugin = $options['hook_extra']['plugin'] ?? null;
			return is_string( $plugin ) && '' !== $plugin ? $plugin : null;
		}

		/** Redacted diagnostic: public code only, never paths or data. */
		private static function log_safe( string $code ): void {
			error_log( 'HAL Frontend Dashboard: update bridge ' . $code . '.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- redacted operator diagnostic (§8.1).
		}
	}
}
