<?php
/**
 * runtime/health/class-integration-health-monitor.php (batch 3)
 * ══════════════════════════════════════════════════════════════
 * Contract (architecture §7.6 health type separation + owner decision §2.5):
 *   - Integration Health monitors decryptability/key source/provider
 *     capability for every integration via the Registry allowlist. Its
 *     failure disables that integration alone and alerts the admin — no
 *     rollback of the Runtime ever (Deployment/Boot Health in
 *     class-health-check.php is separate).
 *   - It does not read/decrypt secrets inside a page render: the check is
 *     a bounded job (a cron hook or an admin POST) and the results are
 *     stored as sanitized status codes only in a separate option — no
 *     values and no raw responses.
 *   - The one-shot alert is rate-limited: one notice per new failure
 *     state; returning to ok (or a change in codes) re-arms the alert.
 *   - The Site Health test reads the stored state sanitized (with no
 *     decryption).
 * ══════════════════════════════════════════════════════════════
 */

defined( 'ABSPATH' ) || exit;

final class HAL_Frontend_Dashboard_Integration_Health_Monitor {

	const STATUS_OPTION  = 'hal_frontend_dashboard_integration_health';
	const NOTICE_OPTION  = 'hal_frontend_dashboard_health_notice_sent';
	const CRON_HOOK      = 'hal_frontend_dashboard_integration_health_check';

	const STATUS_OK      = 'ok';
	const STATUS_BLOCKED = 'blocked';

	/**
	 * Register the hooks when the Runtime loads (from bootstrap). No other
	 * WordPress calls here — execution happens at callback time.
	 */
	public static function register(): void {
		add_action( self::CRON_HOOK, array( self::class, 'run_checks' ) );
		add_filter( 'site_status_tests', array( self::class, 'register_site_health_test' ) );
		add_action( 'admin_init', array( self::class, 'ensure_schedule' ) );
		add_action( 'admin_notices', array( self::class, 'render_admin_notice' ) );
	}

	/**
	 * Schedule the periodic check (hourly) idempotently — admin_init only.
	 */
	public static function ensure_schedule(): void {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
	}

	/**
	 * The actual check (a bounded job): for every secret in the allowlist —
	 * availability, key source, and decryptability (decrypted and discarded
	 * immediately; no value is stored); and the provider capability for AI
	 * with a local check and no API call. It stores sanitized codes only
	 * and returns the same state.
	 *
	 * @return array<string, array{status:string, codes:string[]}>
	 */
	public static function run_checks(): array {
		$store_available = HAL_Frontend_Dashboard_Secret_Store::is_available();
		$key_source      = HAL_Frontend_Dashboard_Secret_Store::get_key_source();
		$status          = array();

		// B3-06-B (re-delivery): the chosen AI strategy is settled before the
		// secrets loop — a failure of the encrypted fallback is attributed to
		// the integration state only if direct_key is the one actually
		// chosen.
		$ai_strategy = null;
		if ( function_exists( 'hossam_ai_resolve_strategy' ) ) {
			$resolved = hossam_ai_resolve_strategy();
			if ( is_string( $resolved ) && in_array( $resolved, array( 'wp_ai_client', 'direct_key' ), true ) ) {
				$ai_strategy = $resolved;
			}
		}

		foreach ( HAL_Frontend_Dashboard_Integration_Settings_Registry::integrations() as $integration_id => $definition ) {
			$codes = array();
			foreach ( $definition['secrets'] as $secret ) {
				$secret_id = (string) $secret['id'];
				if ( 'ai' === $integration_id && 'ai_direct_key' === $secret_id
					&& ( 'wp_ai_client' === $ai_strategy || null === $ai_strategy ) ) {
					// B3-06-B: the fallback is not the chosen one (the
					// wp_ai_client strategy) or there is no settled strategy
					// at all — its failure does not block the valid path, and
					// the ciphertext stays stored without being exposed. The
					// chosen direct_key, however, proceeds to the blocking
					// check.
					continue;
				}
				// B3-06-B: health is evaluated for the chosen source per the
				// §2.4 order exclusively — a failure of an unused HAL fallback
				// (corrupt or without a key/Sodium) does not block an external
				// source of higher priority. A chosen but corrupt fallback
				// remains blocking.
				$source = HAL_Frontend_Dashboard_Integration_Settings_Registry::credential_source( $secret_id );
				if ( 'none' === $source ) {
					if ( ! empty( $secret['optional'] ) ) {
						continue; // An optional secret not set: no failure state, the integration stays unavailable for its own reason.
					}
					$codes[] = 'secret_not_configured';
					continue;
				}
				if ( 'hal_encrypted' !== $source ) {
					continue; // The chosen external source is consumed as is — no decryption here.
				}
				if ( ! $store_available ) {
					// B3-04: diagnosing the actual blocker sanitized — missing
					// Sodium shows blocked explicitly, unlike a missing key.
					$blocker = HAL_Frontend_Dashboard_Secret_Store::get_blocker();
					$codes[] = 'sodium_missing' === $blocker ? 'sodium_unavailable' : 'key_source_unavailable';
					continue;
				}
				$decrypted = HAL_Frontend_Dashboard_Secret_Store::get( $secret_id );
				if ( is_wp_error( $decrypted ) ) {
					/** @var WP_Error $decrypted */
					$codes[] = 'decrypt_' . str_replace( 'hal_secret_', '', $decrypted->get_error_code() );
					continue;
				}
				unset( $decrypted ); // The value is discarded immediately — no storage and no logging.
			}
			foreach ( $definition['health'] as $consumer ) {
				$codes = array_merge( $codes, self::probe_consumer( $integration_id, (string) $consumer ) );
			}
			$status[ $integration_id ] = array(
				'status' => array() === $codes ? self::STATUS_OK : self::STATUS_BLOCKED,
				'codes'  => $codes,
			);
		}

		$status['_meta'] = array(
			'checked_at' => gmdate( 'c' ),
			'key_source' => $key_source,
		);

		$previous = get_option( self::STATUS_OPTION, array() );
		update_option( self::STATUS_OPTION, $status, false );
		if ( self::has_new_failure( $previous, $status ) ) {
			update_option( self::NOTICE_OPTION, 0, false ); // Re-arm the alert for the new failure state.
		}
		return $status;
	}

	/**
	 * Provider capability — a local check with no API call and no transient
	 * (unified reference §7.4: feature detection, not a version check).
	 *
	 * @return string[] failure codes only.
	 */
	private static function probe_consumer( string $integration_id, string $consumer ): array {
		if ( 'ai' === $integration_id && 'provider_capability' === $consumer ) {
			// B3-06-A: only the chosen strategy's requirements are evaluated —
			// a failure of an unused strategy does not block the chosen one
			// (§7.6: direct_key fallback is legitimate when the WP AI Client
			// is absent or unsupported).
			if ( ! function_exists( 'hossam_ai_resolve_strategy' ) ) {
				// Batch 4 carries adapters/ai.php — until then the integration
				// is structurally incomplete (it does not become enabled
				// merely by being configured).
				return array( 'ai_adapter_not_loaded' );
			}
			$strategy = hossam_ai_resolve_strategy();
			if ( ! is_string( $strategy ) || ! in_array( $strategy, array( 'wp_ai_client', 'direct_key' ), true ) ) {
				return array( 'ai_strategy_invalid' );
			}
			if ( 'direct_key' === $strategy ) {
				return HAL_Frontend_Dashboard_Secret_Store::has( 'ai_direct_key' )
					? array()
					: array( 'direct_key_secret_missing' );
			}
			if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
				return array( 'wp_ai_client_unavailable' );
			}
			$supported = null;
			try {
				$client    = wp_ai_client_prompt( 'test' );
				$supported = is_object( $client ) && method_exists( $client, 'is_supported_for_text_generation' )
					? (bool) $client->is_supported_for_text_generation()
					: null;
			} catch ( Throwable $probe_failure ) {
				$supported = null;
			}
			if ( null === $supported ) {
				return array( 'wp_ai_client_contract_invalid' );
			}
			if ( true !== $supported ) {
				return array( 'wp_ai_client_unsupported' );
			}
			return array();
		}
		if ( 'amelia' === $integration_id && 'elite_api_client_ready' === $consumer ) {
			$codes = array();
			if ( ! function_exists( 'hossam_amelia_api_request' ) ) {
				$codes[] = 'amelia_adapter_not_loaded';
			}
			if ( 'none' === HAL_Frontend_Dashboard_Integration_Settings_Registry::credential_source( 'amelia_elite_api_key' ) ) {
				$codes[] = 'amelia_credential_missing';
			}
			return $codes;
		}
		return array();
	}

	/**
	 * The stored state sanitized — read by the controller/Site Health with
	 * no decryption.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_status(): array {
		$stored = get_option( self::STATUS_OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * A direct Site Health test reading only the stored state (no decryption
	 * inside the render — §14).
	 *
	 * @param array<string, array{direct:array<string, array{label:string, test:callable}>, async:array<string, array{label:string, test:callable}>>} $tests
	 * @return array<string, mixed>
	 */
	public static function register_site_health_test( array $tests ) {
		$tests['direct']['hal_frontend_dashboard_integration_health'] = array(
			'label' => __( 'HAL Frontend Dashboard integration health', 'hal-frontend-dashboard' ),
			'test'  => array( self::class, 'site_health_test' ),
		);
		return $tests;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function site_health_test(): array {
		$status = self::get_status();

		// B3-06: a state never checked is not shown good — it is shown
		// recommended with an explicit invitation to run the check.
		if ( array() === $status ) {
			return array(
				'label'       => __( 'HAL Frontend Dashboard integration health has not been checked yet', 'hal-frontend-dashboard' ),
				'status'      => 'recommended',
				'badge'       => array(
					'label' => __( 'Security', 'hal-frontend-dashboard' ),
					'color' => 'blue',
				),
				'test'        => 'hal_frontend_dashboard_integration_health',
				'description' => __( 'Run the integration health check from the HAL settings page to produce sanitized status codes.', 'hal-frontend-dashboard' ),
			);
		}

		$failed  = array();
		foreach ( $status as $integration_id => $entry ) {
			if ( '_meta' === $integration_id ) {
				continue;
			}
			if ( is_array( $entry ) && ( $entry['status'] ?? '' ) === self::STATUS_BLOCKED ) {
				$failed[] = (string) $integration_id;
			}
		}

		$result = array(
			'label'       => __( 'HAL Frontend Dashboard integrations are healthy', 'hal-frontend-dashboard' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Security', 'hal-frontend-dashboard' ),
				'color' => 'blue',
			),
			'test'        => 'hal_frontend_dashboard_integration_health',
		);

		if ( array() !== $failed ) {
			$result['status']      = 'critical';
			$result['label']       = __( 'HAL Frontend Dashboard has blocked integrations', 'hal-frontend-dashboard' );
			$result['description'] = sprintf(
				/* translators: %s: integration ids. */
				__( 'Blocked integrations (status codes only): %s. Check the HAL settings page — no Runtime rollback occurred.', 'hal-frontend-dashboard' ),
				implode( ', ', $failed )
			);
		}
		return $result;
	}

	/**
	 * Admin notice: one per new failure state (a rate-limited one-shot) —
	 * the flag prevents repetition until the state changes.
	 */
	public static function render_admin_notice(): void {
		if ( ! current_user_can( HAL_Frontend_Dashboard_Settings_Repository::CAPABILITY ) ) {
			return;
		}
		$status   = self::get_status();
		$has_fail = false;
		foreach ( $status as $integration_id => $entry ) {
			if ( '_meta' === $integration_id ) {
				continue;
			}
			if ( is_array( $entry ) && ( $entry['status'] ?? '' ) === self::STATUS_BLOCKED ) {
				$has_fail = true;
			}
		}
		if ( ! $has_fail ) {
			return;
		}
		if ( (int) get_option( self::NOTICE_OPTION, 0 ) >= 1 ) {
			return; // This state was already announced — a single rate-limited alert.
		}
		update_option( self::NOTICE_OPTION, 1, false );
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'HAL Frontend Dashboard: one or more integrations are blocked (credential or provider state). The runtime was not rolled back — check Status & Diagnostics for sanitized status codes.', 'hal-frontend-dashboard' )
		);
	}

	/**
	 * Did the new state introduce a failure that was not present in the
	 * previous one?
	 *
	 * @param array<string, mixed> $previous
	 * @param array<string, mixed> $current
	 */
	private static function has_new_failure( array $previous, array $current ): bool {
		foreach ( $current as $integration_id => $entry ) {
			if ( '_meta' === $integration_id || ! is_array( $entry ) ) {
				continue;
			}
			if ( self::STATUS_BLOCKED !== ( $entry['status'] ?? '' ) ) {
				continue;
			}
			$previous_entry = $previous[ $integration_id ] ?? null;
			if ( ! is_array( $previous_entry ) || ( $previous_entry['status'] ?? '' ) !== self::STATUS_BLOCKED
				|| ( $previous_entry['codes'] ?? array() ) !== ( $entry['codes'] ?? array() ) ) {
				return true;
			}
		}
		return false;
	}
}

HAL_Frontend_Dashboard_Integration_Health_Monitor::register();
