<?php
/**
 * runtime/integrations/class-integration-settings-registry.php (الدفعة 3)
 * ══════════════════════════════════════════════════════════════
 * العقد (معمارية §14): allowlist واحدة لتعريف settings/secrets/health
 * consumers لكل تكامل — لا key names حرة ولا provider URLs من input.
 * معرفات الأسرار المسموحة هنا حصرًا يمررها المتحكم إلى Secret Store،
 * وhealth consumers معرفات مجردة تستهلكها الدفعة 4/المراقب — لا URLs
 * هنا إطلاقًا.
 *
 * التكاملات السبعة المطابقة لسجل قدرات core/setup.php: amelia وwpml و
 * rank_math وwoocommerce وultimate_member وfrontend_admin وai.
 *
 * AI: تفضيل الاستراتيجية enum مغلق (المرجع الموحد §7.4)؛ أما allowlist
 * المزود/النموذج لـdirect_key فملك adapters/ai.php في الدفعة 4 عبر
 * apply_filters('hossam_ai_strategies') من PHP موثوق فقط — لا تُعاد
 * هنا ولا تُقبل من input.
 * ══════════════════════════════════════════════════════════════
 */

defined( 'ABSPATH' ) || exit;

final class HAL_Frontend_Dashboard_Integration_Settings_Registry {

	/**
	 * تعريف التكاملات: label للعرض، secrets المعرفات المسموحة
	 * (optional = السر اختياري وتكامله يبقى unavailable بغيابه)،
	 * settings مفاتيح Settings Repository المخصصة للتكامل، وhealth
	 * consumers معرفات مجردة يفحصها مراقب الصحة.
	 *
	 * @return array<string, array{label:string, secrets:array<int, array{id:string, label:string, optional:bool}>, settings:string[], health:string[]}>
	 */
	public static function integrations(): array {
		return array(
			'amelia'         => array(
				'label'    => 'Amelia',
				'secrets'  => array(
					array(
						'id'            => 'amelia_elite_api_key',
						'label'         => 'Amelia Elite API key',
						'optional'      => true,
						// العقد التاريخي الموثق في core/setup.php: ثابت خارج
						// المستودع HOSSAM_AMELIA_API_KEY (لا env name موثق).
						'constant_name' => 'HOSSAM_AMELIA_API_KEY',
						'env_name'      => '',
					),
				),
				'settings' => array(),
				'health'   => array( 'elite_api_client_ready' ),
			),
			'wpml'           => array(
				'label'    => 'WPML Translations',
				'secrets'  => array(),
				'settings' => array(),
				'health'   => array(),
			),
			'rank_math'      => array(
				'label'    => 'Rank Math SEO',
				'secrets'  => array(),
				'settings' => array(),
				'health'   => array(),
			),
			'woocommerce'    => array(
				'label'    => 'WooCommerce',
				'secrets'  => array(),
				'settings' => array(),
				'health'   => array(),
			),
			'ultimate_member' => array(
				'label'    => 'Ultimate Member',
				'secrets'  => array(),
				'settings' => array(),
				'health'   => array(),
			),
			'frontend_admin' => array(
				'label'    => 'Frontend Admin',
				'secrets'  => array(),
				'settings' => array(),
				'health'   => array(),
			),
			'ai'             => array(
				'label'    => 'AI',
				'secrets'  => array(
					array(
						'id'       => 'ai_direct_key',
						'label'    => 'AI direct key (encrypted fallback)',
						'optional' => true,
					),
				),
				'settings' => array( 'ai_preference' ),
				'health'   => array( 'provider_capability' ),
			),
		);
	}

	/**
	 * @return string[] كل معرفات الأسرار المسموحة عبر التكاملات.
	 */
	public static function secret_ids(): array {
		$ids = array();
		foreach ( self::integrations() as $integration ) {
			foreach ( $integration['secrets'] as $secret ) {
				$ids[] = $secret['id'];
			}
		}
		return $ids;
	}

	/**
	 * هل معرف السر ضمن الallowlist؟ (لا key names حرة من input).
	 */
	public static function is_valid_secret_id( string $secret_id ): bool {
		return in_array( $secret_id, self::secret_ids(), true );
	}

	/**
	 * مفتاح السر المناظر لأحد إعدادات Settings Repository (العقد
	 * المشترك مع التكاملات اللاحقة).
	 */
	public static function integration_settings( string $integration ): array {
		$definition = self::integrations()[ $integration ] ?? null;
		return is_array( $definition ) ? $definition['settings'] : array();
	}

	/**
	 * معرفات health consumers لتكامل — يستهلكها مراقب الصحة فقط.
	 */
	public static function integration_health_consumers( string $integration ): array {
		$definition = self::integrations()[ $integration ] ?? null;
		return is_array( $definition ) ? $definition['health'] : array();
	}

	/**
	 * B3-credential-status: مصدر credential الفعلي لسر معرف، وفق ترتيب
	 * قرار المالك §2.4 فيما تملكه هذه الطبقة: environment ثم constant
	 * ثم HAL encrypted fallback ثم none. المصدر 1 (credential رسمي بلا
	 * نسخ) والمصدر 5 (Connector DB بتحذيره) يملكهما adapter/الـAI
	 * Client في الدفعتين 4+ ويبقيان خارج هذا العقد. HAL plaintext
	 * ممنوع ولا يظهر هنا إطلاقًا.
	 *
	 * @return string environment|constant|hal_encrypted|none
	 */
	public static function credential_source( string $secret_id ): string {
		foreach ( self::integrations() as $definition ) {
			foreach ( $definition['secrets'] as $secret ) {
				if ( (string) $secret['id'] !== $secret_id ) {
					continue;
				}
				$env_name = (string) ( $secret['env_name'] ?? '' );
				if ( '' !== $env_name && function_exists( 'getenv' ) && is_string( getenv( $env_name ) ) && '' !== getenv( $env_name ) ) {
					return 'environment';
				}
				$constant_name = (string) ( $secret['constant_name'] ?? '' );
				if ( '' !== $constant_name && defined( $constant_name ) && is_string( constant( $constant_name ) ) && '' !== constant( $constant_name ) ) {
					return 'constant';
				}
				if ( HAL_Frontend_Dashboard_Secret_Store::has( $secret_id ) ) {
					return 'hal_encrypted';
				}
				return 'none';
			}
		}
		return 'none';
	}
}
