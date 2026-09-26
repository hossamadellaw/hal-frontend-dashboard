<?php
/**
 * runtime/health/class-integration-health-monitor.php (الدفعة 3)
 * ══════════════════════════════════════════════════════════════
 * العقد (معمارية §7.6 فصل نوعي الصحة + قرار المالك §2.5):
 *   - Integration Health يراقب decryptability/key source/provider
 *     capability لكل تكامل عبر allowlist الـRegistry. فشله يعطل
 *     التكامل وحده وينبه المسؤول — لا rollback للـRuntime أبدًا
 *     (Deployment/Boot Health في class-health-check.php منفصل).
 *   - لا يقرأ/يفك الأسرار داخل page render: الفحص job محدود
 *     (cron hook أو POST إداري) والنتائج تُخزن status codes منقحة
 *     فقط في option منفصلة — بلا قيم ولا responses خام.
 *   - التنبيه one-shot محدود التكرار: notice واحد لكل حالة فشل
 *     جديدة؛ العودة إلى ok (أو تغيّر codes) تعيد تسليح التنبيه.
 *   - Site Health test يقرأ الحالة المخزنة منقحة (بلا فك تشفير).
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
	 * تسجيل الـhooks عند تحميل الـRuntime (من bootstrap). لا استدعاءات
	 * WordPress أخرى هنا — التنفيذ وقت الـcallback.
	 */
	public static function register(): void {
		add_action( self::CRON_HOOK, array( self::class, 'run_checks' ) );
		add_filter( 'site_status_tests', array( self::class, 'register_site_health_test' ) );
		add_action( 'admin_init', array( self::class, 'ensure_schedule' ) );
		add_action( 'admin_notices', array( self::class, 'render_admin_notice' ) );
	}

	/**
	 * جدولة الفحص الدوري (ساعة) بشكل idempotent — admin_init فقط.
	 */
	public static function ensure_schedule(): void {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
	}

	/**
	 * الفحص الفعلي (job محدود): لكل سر في الـallowlist — التوفّر،
	 * مصدر المفتاح، وقابلية فك التشفير (تُفك وتُهدر فورًا؛ لا قيمة
	 * تُخزن)؛ وcapability المزود للـAI بفحص محلي بلا API call.
	 * يخزن codes منقحة فقط ويعيد نفس الحالة.
	 *
	 * @return array<string, array{status:string, codes:string[]}>
	 */
	public static function run_checks(): array {
		$store_available = HAL_Frontend_Dashboard_Secret_Store::is_available();
		$key_source      = HAL_Frontend_Dashboard_Secret_Store::get_key_source();
		$status          = array();

		// B3-06-B (إعادة التسليم): الاستراتيجية المختارة للـAI تُحسم قبل
		// حلقة الأسرار — عطل الـfallback المشفر لا يُسند إلى حالة التكامل
		// إلا إذا كانت direct_key هي المختارة فعلًا.
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
					// B3-06-B: الـfallback ليس هو المختار (استراتيجية
					// wp_ai_client) أو لا استراتيجية محسومة أصلاً — عطلُه
					// لا يحجب المسار الصالح، والciphertext يبقى محفوظًا
					// دون كشفه. أما direct_key المختار فيمر للفحص الحاجب.
					continue;
				}
				// B3-06-B: الصحة تُقيَّم للمصدر المختار وفق ترتيب §2.4 حصرًا —
				// عطل HAL fallback غير المستخدم (تالف أو بلا مفتاح/Sodium)
				// لا يحجب مصدرًا خارجيًا أعلى أولوية. أما fallback المختار
				// والتالف فيظل حاجبًا.
				$source = HAL_Frontend_Dashboard_Integration_Settings_Registry::credential_source( $secret_id );
				if ( 'none' === $source ) {
					if ( ! empty( $secret['optional'] ) ) {
						continue; // سر اختياري غير مضبوط: لا حالة فشل، التكامل يبقى unavailable بسببه الخاص.
					}
					$codes[] = 'secret_not_configured';
					continue;
				}
				if ( 'hal_encrypted' !== $source ) {
					continue; // المصدر الخارجي المختار يُستهلك كما هو — لا فك تشفير هنا.
				}
				if ( ! $store_available ) {
					// B3-04: تشخيص الحاجب الفعلي منقحًا — غياب Sodium يظهر
					// blocked صراحة بغير غياب المفتاح.
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
				unset( $decrypted ); // القيمة تُهدر فورًا — لا تخزين ولا تسجيل.
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
			update_option( self::NOTICE_OPTION, 0, false ); // إعادة تسليح التنبيه لحالة الفشل الجديدة.
		}
		return $status;
	}

	/**
	 * capability المزود — فحص محلي بلا API call ولا transient (المرجع
	 * الموحد §7.4: feature detection لا version check).
	 *
	 * @return string[] codes فشل فقط.
	 */
	private static function probe_consumer( string $integration_id, string $consumer ): array {
		if ( 'ai' === $integration_id && 'provider_capability' === $consumer ) {
			// B3-06-A: تُقيَّم متطلبات الاستراتيجية المختارة فقط — فشل
			// استراتيجية غير مستخدمة لا يحجب المختارة (§7.6: direct_key
			// fallback مشروع عند غياب WP AI Client أو عدم دعمه).
			if ( ! function_exists( 'hossam_ai_resolve_strategy' ) ) {
				// الدفعة 4 تحمل adapters/ai.php — حتىها التكامل غير مكتمل
				// بنيويًا (لا يتحول إلى enabled لمجرد الإعداد).
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
	 * الحالة المخزنة منقحة — يقرؤها المتحكم/Site Health بلا فك تشفير.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_status(): array {
		$stored = get_option( self::STATUS_OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Site Health test مباشر يقرأ الحالة المخزنة فقط (بلا فك تشفير
	 * داخل الـrender — §14).
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

		// B3-06: الحالة التي لم تُفحص لا تُعرض good — تُعرض recommended
		// مع دعوة صريحة لتشغيل الفحص.
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
	 * Admin notice: واحد لكل حالة فشل جديدة (one-shot محدود التكرار) —
	 * العلامة تمنع التكرار حتى تتغير الحالة.
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
			return; // أُعلن لهذه الحالة مسبقًا — تنبيه واحد محدود التكرار.
		}
		update_option( self::NOTICE_OPTION, 1, false );
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'HAL Frontend Dashboard: one or more integrations are blocked (credential or provider state). The runtime was not rolled back — check Status & Diagnostics for sanitized status codes.', 'hal-frontend-dashboard' )
		);
	}

	/**
	 * هل أدخلت الحالة الجديدة فشلًا لم يكن موجودًا في السابقة؟
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
