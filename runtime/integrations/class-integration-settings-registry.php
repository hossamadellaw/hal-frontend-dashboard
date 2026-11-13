<?php
/**
 * runtime/integrations/class-integration-settings-registry.php (batch 3)
 * ══════════════════════════════════════════════════════════════
 * Contract (architecture §14): a single allowlist defining the settings/
 * secrets/health consumers of every integration — no free-form key names
 * and no provider URLs from input. The approved secret ids here alone are
 * what the controller passes to the Secret Store, and health consumers are
 * abstract identifiers consumed by batch 4/the monitor — no URLs here at
 * all.
 *
 * The seven integrations matching the capability register of
 * core/setup.php: amelia, wpml, rank_math, woocommerce, ultimate_member,
 * frontend_admin and ai.
 *
 * AI: the strategy preference is a closed enum (unified reference §7.4);
 * the provider/model allowlist for direct_key is owned by adapters/ai.php
 * in batch 4 via apply_filters('hossam_ai_strategies') from trusted PHP
 * only — it is not returned here and not accepted from input.
 * ══════════════════════════════════════════════════════════════
 */

defined( 'ABSPATH' ) || exit;

final class HAL_Frontend_Dashboard_Integration_Settings_Registry {

	/**
	 * Integration definitions: label for display, secrets as the allowed
	 * ids (optional = the secret is optional and its integration stays
	 * unavailable in its absence), settings as the Settings Repository keys
	 * assigned to the integration, and health consumers as abstract
	 * identifiers examined by the health monitor.
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
						// The historical contract documented in core/setup.php:
						// a constant outside the repository
						// HOSSAM_AMELIA_API_KEY (no documented env name).
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
	 * @return string[] every approved secret id across the integrations.
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
	 * Is the secret id within the allowlist? (no free-form key names from
	 * input).
	 */
	public static function is_valid_secret_id( string $secret_id ): bool {
		return in_array( $secret_id, self::secret_ids(), true );
	}

	/**
	 * The secret key corresponding to one of the Settings Repository
	 * settings (the shared contract with the later integrations).
	 */
	public static function integration_settings( string $integration ): array {
		$definition = self::integrations()[ $integration ] ?? null;
		return is_array( $definition ) ? $definition['settings'] : array();
	}

	/**
	 * The health consumer ids of an integration — consumed by the health
	 * monitor only.
	 */
	public static function integration_health_consumers( string $integration ): array {
		$definition = self::integrations()[ $integration ] ?? null;
		return is_array( $definition ) ? $definition['health'] : array();
	}

	/**
	 * B3-credential-status: the actual credential source of a given secret,
	 * in the order of owner decision §2.4 for what this layer owns:
	 * environment, then constant, then HAL encrypted fallback, then none.
	 * Source 1 (an official credential with no copy) and source 5 (the
	 * Connector DB with its warning) are owned by the adapter/the AI Client
	 * in batches 4+ and stay outside this contract. HAL plaintext is
	 * forbidden and never appears here.
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
