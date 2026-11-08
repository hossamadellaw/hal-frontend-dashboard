<?php
/**
 * runtime/adapters/ai.php (batch 4)
 * ══════════════════════════════════════════════════════════════
 * Role: a tightly bound AI Registry and Dispatcher (the unified reference
 * §7.4 verbatim) — the two approved strategies only: wp_ai_client and
 * direct_key.
 *
 * Source: moved from legacy mu-plugins/hossam-dashboard/adapters/ai.php
 * (batch 4 of HAL Frontend Dashboard) — the declared edits alone (the
 * approved batch-3 credential/setup contract):
 *   - hossam_ai_get_preference(): reads the strategy preference from
 *     HAL_Frontend_Dashboard_Settings_Repository (the approved owner of the
 *     setting — a closed matching enum); outside the natural load order the
 *     legacy 'hossam_ai_preference' option remains the protected fallback.
 *   - hossam_ai_set_preference(): writes via the repository save() in the
 *     natural load order (one write path — §8.3); outside the batch-3 layer
 *     the legacy option remains protected with a class_exists guard — with
 *     the same historic bool signature.
 *   - hossam_ai_direct_key_ready(): the provider setup gate (the constant
 *     HOSSAM_AI_PROVIDER) + the encrypted secret ai_direct_key existing in
 *     the HAL Secret Store via has() only — no decryption (called from
 *     resolve_strategy in display/health paths). A missing secret is not a
 *     decision here: the health monitor reports it with the closed
 *     direct_key_secret_missing code.
 *   - hossam_ai_resolve_strategy(): an explicit direct_key preference is
 *     rejected with the hossam_ai_direct_key_not_fallback code when a WP AI
 *     Client capability is available (fallback only — architecture §7.6);
 *     no silent switch to another provider after actual execution starts,
 *     and the strategy is settled at submit alone.
 *   - hossam_ai_connector_source_status(): non-sensitive Connector source
 *     status (capable/source/reason) without the key; undetermined via
 *     no_source_contract when no detector contract exists.
 *   - hossam_ai_allowed_models(): a fixed allowlist (gemini-1.5-flash);
 *     anything unlisted is rejected with the hossam_ai_unsupported_model
 *     code before the call.
 *   - hossam_ai_get_provider_config(): provider/model from the constants
 *     as-is, and the key from the HAL encrypted fallback only (owner
 *     decision §2.4: "the encrypted direct_key as fallback only" + RG3: the
 *     provider allowlist belongs to this adapter). The HOSSAM_AI_API_KEY
 *     constant channel for the key was replaced by the encrypted store; a
 *     secret-decrypt failure fails closed with a refined code and no value.
 *   - The two executors, the prompt builders and verify_* as-is verbatim:
 *     fixed endpoint, the key in the x-goog-api-key header only (no query/
 *     body/log), no streaming/embeddings, no cross-provider fallback after
 *     actual execution starts, and the strategy settled at submit alone.
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ══════════════════════════════════════════════════════════════
// 1) The approved capability check + direct_key readiness
// ══════════════════════════════════════════════════════════════

/**
 * The approved capability check verbatim — local, no API call, no transient.
 * The defensive guards (method_exists/is_object) separate the state "the
 * function exists but the Connector contract differs" from a silent failure
 * without changing the check's semantics.
 */
if ( ! function_exists( 'hossam_ai_wp_client_supported' ) ) {
	function hossam_ai_wp_client_supported(): bool {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return false;
		}

		$prompt = wp_ai_client_prompt( 'test' );
		if ( ! is_object( $prompt ) || ! method_exists( $prompt, 'is_supported_for_text_generation' ) ) {
			return false;
		}

		return (bool) $prompt->is_supported_for_text_generation();
	}
}

/**
 * direct_key readiness: the provider is configured (a documented constant)
 * and the encrypted secret exists in the HAL Secret Store — has() with no
 * decryption. Reading the value itself happens at execution time only via
 * hossam_ai_get_provider_config().
 */
if ( ! function_exists( 'hossam_ai_direct_key_ready' ) ) {
	function hossam_ai_direct_key_ready(): bool {
		if ( ! defined( 'HOSSAM_AI_PROVIDER' ) || ! is_string( HOSSAM_AI_PROVIDER ) ) {
			return false;
		}
		if ( 'gemini' !== sanitize_key( (string) HOSSAM_AI_PROVIDER ) ) {
			return false;
		}
		if ( ! class_exists( 'HAL_Frontend_Dashboard_Secret_Store' ) ) {
			return false;
		}

		return HAL_Frontend_Dashboard_Secret_Store::has( 'ai_direct_key' );
	}
}

// ══════════════════════════════════════════════════════════════
// 2) The Registry — a fixed allow-list + extension bound to the official filter
// ══════════════════════════════════════════════════════════════

if ( ! function_exists( 'hossam_ai_base_strategies' ) ) {
		/**
		 * The fixed list — no filter removes it or replaces its callbacks.
		 *
		 * @return array<string,array{label:string,callback:callable,timeout:int}>
		 */
	function hossam_ai_base_strategies(): array {
		$profile = hossam_ai_get_runtime_profile();
		if ( is_wp_error( $profile ) ) {
			return [];
		}

		return [
			'wp_ai_client' => [
				'label'    => 'WordPress AI Client',
				'callback' => 'hossam_ai_execute_wp_ai_client',
				'timeout'  => $profile['wp_ai_client_timeout'],
			],
			'direct_key' => [
				'label'    => 'Direct provider key',
				'callback' => 'hossam_ai_execute_direct_key',
				'timeout'  => $profile['direct_key_timeout'],
			],
		];
	}
}

if ( ! function_exists( 'hossam_ai_get_strategies' ) ) {
		/**
		 * The effective Registry: the fixed base + whatever trusted PHP adds via
		 * the filter, after strict sanitization (a string key, a callable
		 * callback, a bounded timeout).
		 *
		 * @return array<string,array{label:string,callback:callable,timeout:int}>
		 */
	function hossam_ai_get_strategies(): array {
		$base = hossam_ai_base_strategies();
		if ( empty( $base ) ) {
			return [];
		}

		$clean = [];

		// Extension first from the filter (trusted PHP only), then sanitization.
		foreach ( (array) apply_filters( 'hossam_ai_strategies', [] ) as $key => $entry ) {
			if ( ! is_string( $key ) || '' === $key || ! is_array( $entry ) ) {
				continue;
			}
			if ( empty( $entry['callback'] ) || ! is_callable( $entry['callback'] ) ) {
				continue;
			}
			$timeout = isset( $entry['timeout'] ) ? (int) $entry['timeout'] : 0;
			if ( $timeout < 5 || $timeout > 300 ) {
				continue;
			}
			$clean[ $key ] = [
				'label'    => sanitize_text_field( (string) ( $entry['label'] ?? $key ) ),
				'callback' => $entry['callback'],
				'timeout'  => $timeout,
			];
		}

		// The fixed base is enforced last: no deletion or breakage by a violating filter.
		foreach ( $base as $key => $entry ) {
			$clean[ $key ] = $entry;
		}

		return $clean;
	}
}

// ══════════════════════════════════════════════════════════════
// 3) The preference — via the Settings Repository (the approved owner of the setting)
// ══════════════════════════════════════════════════════════════

if ( ! function_exists( 'hossam_ai_allowed_preferences' ) ) {
	/**
	 * @return string[]
	 */
	function hossam_ai_allowed_preferences(): array {
		return [ 'auto', 'wp_ai_client', 'direct_key' ];
	}
}

if ( ! function_exists( 'hossam_ai_get_preference' ) ) {
	function hossam_ai_get_preference(): string {
		if ( class_exists( 'HAL_Frontend_Dashboard_Settings_Repository' ) ) {
			return HAL_Frontend_Dashboard_Settings_Repository::get_ai_preference();
		}

		// Outside the natural load order (the batch-3 layer absent): the legacy option.
		$pref = (string) get_option( 'hossam_ai_preference', 'auto' );
		return in_array( $pref, hossam_ai_allowed_preferences(), true ) ? $pref : 'auto';
	}
}

if ( ! function_exists( 'hossam_ai_set_preference' ) ) {
	/**
	 * The actual preference write via the repository save() only (one write
	 * path — §8.3). The caller (the admin endpoint) is what enforces nonce +
	 * capability; this function enforces sanitization and the allowlist only.
	 */
	function hossam_ai_set_preference( string $preference ): bool {
		$preference = sanitize_key( $preference );
		if ( ! in_array( $preference, hossam_ai_allowed_preferences(), true ) ) {
			return false;
		}
		if ( class_exists( 'HAL_Frontend_Dashboard_Settings_Repository' ) ) {
			return true === HAL_Frontend_Dashboard_Settings_Repository::save( [ 'ai_preference' => $preference ] );
		}

		return false !== update_option( 'hossam_ai_preference', $preference );
	}
}

// ══════════════════════════════════════════════════════════════
// 4) Strategy resolution at submit — no switching between providers at all
// ══════════════════════════════════════════════════════════════

if ( ! function_exists( 'hossam_ai_resolve_strategy' ) ) {
		/**
		 * @return string|WP_Error The non-sensitive strategy_id, or a fail-safe WP_Error.
		 */
	function hossam_ai_resolve_strategy( ?string $preference = null ) {
		$preference = $preference ?: hossam_ai_get_preference();
		$strategies = hossam_ai_get_strategies();
		if ( empty( $strategies ) ) {
			return new WP_Error(
				'hossam_ai_unavailable',
				__( 'AI assistance is unavailable in this environment.', 'hal-frontend-dashboard' )
			);
		}

		switch ( $preference ) {
			case 'wp_ai_client':
				if ( ! isset( $strategies['wp_ai_client'] ) || ! hossam_ai_wp_client_supported() ) {
					return new WP_Error(
						'hossam_ai_unavailable',
						__( 'WordPress AI Client text generation is not available.', 'hal-frontend-dashboard' )
					);
				}
				return 'wp_ai_client';

		case 'direct_key':
			if ( ! isset( $strategies['direct_key'] ) || ! hossam_ai_direct_key_ready() ) {
				return new WP_Error(
					'hossam_ai_unavailable',
					__( 'The direct AI provider key is not configured.', 'hal-frontend-dashboard' )
				);
			}
			if ( hossam_ai_wp_client_supported() ) {
				return new WP_Error(
					'hossam_ai_direct_key_not_fallback',
					__( 'The direct provider key is only a fallback when no WordPress AI Client capability is available.', 'hal-frontend-dashboard' )
				);
			}
			return 'direct_key';

			case 'auto':
			default:
				if ( isset( $strategies['wp_ai_client'] ) && hossam_ai_wp_client_supported() ) {
					return 'wp_ai_client';
				}
				if ( isset( $strategies['direct_key'] ) && hossam_ai_direct_key_ready() ) {
					return 'direct_key';
				}
				return new WP_Error(
					'hossam_ai_no_provider',
					__( 'No AI provider is currently available.', 'hal-frontend-dashboard' )
				);
		}
	}
}

if ( ! function_exists( 'hossam_ai_interface_available' ) ) {
		/**
		 * The server-side display gate for the Posts interface: is there a
		 * valid strategy now? (Showing the buttons is a display condition
		 * only — authorization is re-enforced on every endpoint.)
		 */
	function hossam_ai_interface_available(): bool {
		return ! is_wp_error( hossam_ai_resolve_strategy() );
	}
}

if ( ! function_exists( 'hossam_ai_connector_source_status' ) ) {
		/**
		 * Non-sensitive Connector credential source status (the batch-4 contract
		 * — architecture §15): the text-generation capability and the actual
		 * credential source, with no key copy or disclosure. It uses no version
		 * check and makes no API call. The single advisor-pinned surface is
		 * `wp_ai_client_prompt()` with `is_supported_for_text_generation()`
		 * (Owner §2.6) — capability only with no source field; therefore
		 * `undetermined/no_source_contract` is the only honest value without
		 * reading the secret. No Environment/constants names are invented and
		 * the generic warning is not enough.
		 *
		 * @return array{capable:bool,source:string,reason:string} source:
		 *         none|undetermined (extension is a separate Change Request).
		 */
	function hossam_ai_connector_source_status(): array {
		if ( ! hossam_ai_wp_client_supported() ) {
			return array(
				'capable' => false,
				'source'  => 'none',
				'reason'  => 'wp_ai_client_unsupported',
			);
		}

		return array(
			'capable' => true,
			'source'  => 'undetermined',
			'reason'  => 'no_source_contract',
		);
	}
}

// ══════════════════════════════════════════════════════════════
// 5) The two strategy executors — each receives a ready prompt and returns a text or a WP_Error
// ══════════════════════════════════════════════════════════════

/**
 * The fixed approved models list for the direct_key strategy — any model
 * outside it is rejected before the call. Extension is a separate Change
 * Request.
 *
 * @return string[]
 */
if ( ! function_exists( 'hossam_ai_allowed_models' ) ) {
	function hossam_ai_allowed_models(): array {
		return array( 'gemini-1.5-flash' );
	}
}

/**
 * The direct_key setup read: provider/model from the constants, and the key
 * from the HAL encrypted fallback (ai_direct_key) only. Centralized here
 * only — the value is read at execution time and never passed to the
 * browser/log.
 *
 * @return array{provider:string,api_key:string,model:string}|WP_Error
 */
if ( ! function_exists( 'hossam_ai_get_provider_config' ) ) {
	function hossam_ai_get_provider_config() {
		if ( ! defined( 'HOSSAM_AI_PROVIDER' ) || ! is_string( HOSSAM_AI_PROVIDER ) || '' === sanitize_key( (string) HOSSAM_AI_PROVIDER ) ) {
			return new WP_Error(
				'hossam_ai_not_configured',
				__( 'AI provider is not configured.', 'hal-frontend-dashboard' )
			);
		}

		$provider = sanitize_key( (string) HOSSAM_AI_PROVIDER );

		if ( ! class_exists( 'HAL_Frontend_Dashboard_Secret_Store' ) || ! HAL_Frontend_Dashboard_Secret_Store::has( 'ai_direct_key' ) ) {
			return new WP_Error(
				'hossam_ai_not_configured',
				__( 'AI provider is not configured.', 'hal-frontend-dashboard' )
			);
		}
		$api_key = HAL_Frontend_Dashboard_Secret_Store::get( 'ai_direct_key' );
		if ( is_wp_error( $api_key ) || ! is_string( $api_key ) || '' === trim( $api_key ) ) {
			return new WP_Error(
				'hossam_ai_credential_unreadable',
				__( 'The AI credential could not be read.', 'hal-frontend-dashboard' )
			);
		}

		$model = defined( 'HOSSAM_AI_MODEL' ) && is_string( HOSSAM_AI_MODEL ) && '' !== HOSSAM_AI_MODEL
			? HOSSAM_AI_MODEL
			: 'gemini-1.5-flash';
		if ( ! in_array( $model, hossam_ai_allowed_models(), true ) ) {
			return new WP_Error(
				'hossam_ai_unsupported_model',
				__( 'The configured AI model is not supported.', 'hal-frontend-dashboard' )
			);
		}

		return [
			'provider' => $provider,
			'api_key'  => (string) $api_key,
			'model'    => $model,
		];
	}
}

if ( ! function_exists( 'hossam_ai_execute_wp_ai_client' ) ) {
	/**
	 * Execute the WordPress AI Client strategy — fail-safe when the contract
	 * is absent. The check via is_supported_for_text_generation() only (no
	 * generate_text for probing).
	 *
	 * @return string|WP_Error
	 */
	function hossam_ai_execute_wp_ai_client( string $prompt, array $options = [] ) {
		unset( $options );
		$profile = hossam_ai_get_runtime_profile();
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		if ( ! hossam_ai_wp_client_supported() ) {
			return new WP_Error(
				'hossam_ai_wp_client_unavailable',
				__( 'WordPress AI Client text generation is not available.', 'hal-frontend-dashboard' )
			);
		}

		$timeout = (float) $profile['wp_ai_client_timeout'];
		$timeout_filter = static function ( $default_timeout ) use ( $timeout ): float {
			unset( $default_timeout );
			return $timeout;
		};

		add_filter( 'wp_ai_client_default_request_timeout', $timeout_filter );

		try {
			$prompt_object = wp_ai_client_prompt( $prompt );
			if ( ! is_object( $prompt_object ) || ! method_exists( $prompt_object, 'generate_text' ) ) {
				return new WP_Error(
					'hossam_ai_wp_client_contract_missing',
					__( 'AI generation is unavailable in this environment.', 'hal-frontend-dashboard' )
				);
			}

			$text = $prompt_object->generate_text();
		} catch ( Throwable $e ) {
			// Fail-safe: no leak of internal exception messages to the browser or the table.
			return new WP_Error(
				'hossam_ai_generation_failed',
				__( 'AI generation failed.', 'hal-frontend-dashboard' )
			);
		} finally {
			remove_filter( 'wp_ai_client_default_request_timeout', $timeout_filter );
		}

		if ( is_wp_error( $text ) ) {
			return new WP_Error(
				'hossam_ai_generation_failed',
				__( 'AI generation failed.', 'hal-frontend-dashboard' )
			);
		}

		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return new WP_Error(
				'hossam_ai_empty_result',
				__( 'AI returned an empty result.', 'hal-frontend-dashboard' )
			);
		}

		return trim( $text );
	}
}

if ( ! function_exists( 'hossam_ai_execute_direct_key' ) ) {
	/**
	 * Execute the direct_key strategy — the existing Gemini REST logic as-is
	 * with two documented differences (carried from the source):
	 *   - the timeout comes from the Registry per strategy.
	 *   - the raw provider response body is not attached to the error data so
	 *     it cannot leak into any storage/log; the error message is safe (the
	 *     HTTP code only).
	 *
	 * @return string|WP_Error
	 */
	function hossam_ai_execute_direct_key( string $prompt, array $options = [] ) {
		$profile = hossam_ai_get_runtime_profile();
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		$config = hossam_ai_get_provider_config();
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		if ( 'gemini' !== $config['provider'] ) {
			return new WP_Error(
				'hossam_ai_unsupported_provider',
				__( 'The configured AI provider is not supported.', 'hal-frontend-dashboard' )
			);
		}

		$defaults = [
			'temperature' => 0.4,
			'max_tokens'  => 1024,
		];
		$options = wp_parse_args( $options, $defaults );

		// Authentication via the x-goog-api-key header — the key never enters the
		// query string, the body, logs or options.
		$endpoint = sprintf(
			'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
			rawurlencode( $config['model'] )
		);

		$body = [
			'contents'         => [
				[
					'parts' => [
						[ 'text' => $prompt ],
					],
				],
			],
			'generationConfig' => [
				'temperature'     => (float) $options['temperature'],
				'maxOutputTokens' => (int) $options['max_tokens'],
			],
		];

		$response = wp_remote_post(
			$endpoint,
			[
				'timeout' => $profile['direct_key_timeout'],
				'headers' => [
					'Content-Type'   => 'application/json',
					'x-goog-api-key' => $config['api_key'],
				],
				'body'    => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'hossam_ai_request_failed',
				__( 'AI request failed.', 'hal-frontend-dashboard' )
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'hossam_ai_http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'AI provider returned HTTP %d.', 'hal-frontend-dashboard' ),
					(int) $status
				)
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		// The official Gemini response shape: candidates[0].content.parts[0].text.
		$text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

		if ( null === $text ) {
			$block_reason = $data['promptFeedback']['blockReason'] ?? null;
			return new WP_Error(
				'hossam_ai_unexpected_response',
				$block_reason
					? sprintf(
						/* translators: %s: Gemini block reason */
						__( 'AI provider blocked this request (reason: %s).', 'hal-frontend-dashboard' ),
						sanitize_key( (string) $block_reason )
					)
					: __( 'AI provider response did not contain the expected content field.', 'hal-frontend-dashboard' )
			);
		}

		return trim( (string) $text );
	}
}

// ══════════════════════════════════════════════════════════════
// Prompt builder functions — each serves exactly one capability of the
// approved initial scope (the writing assistant). None of them touches the
// network directly — ajax/ai.php calls them and passes their result to the
// matching executor.
// ══════════════════════════════════════════════════════════════

if ( ! function_exists( 'hossam_ai_build_grammar_prompt' ) ) {
	function hossam_ai_build_grammar_prompt( string $content ): string {
		return "Proofread the following Arabic legal text for grammar and syntax errors only, without changing meaning or style. Return only the corrected text, with no additional commentary:\n\n{$content}";
	}
}

if ( ! function_exists( 'hossam_ai_build_translation_prompt' ) ) {
	function hossam_ai_build_translation_prompt( string $content, string $target_lang ): string {
		return "Translate the following text into the language with code \"{$target_lang}\" accurately, preserving legal terminology. Return only the translation:\n\n{$content}";
	}
}

if ( ! function_exists( 'hossam_ai_build_seo_prompt' ) ) {
	function hossam_ai_build_seo_prompt( string $title, string $content ): string {
		return "Suggest for this article: one primary focus keyword and a Meta description of no more than 160 characters. Return the result as JSON only with the keys focus_keyword and meta_description:\n\nTitle: {$title}\n\nContent: {$content}";
	}
}

if ( ! function_exists( 'hossam_ai_build_improvement_prompt' ) ) {
	function hossam_ai_build_improvement_prompt( string $content ): string {
		return "Suggest 3 specific, concise improvements for the clarity and strength of this legal article's wording (do not rewrite the article; bullet-point suggestions only):\n\n{$content}";
	}
}

/**
 * (b) Live fact verification — a future expansion, deliberately not
 * implemented in this scope (outside the approved batches without a separate
 * functional request).
 */
if ( ! function_exists( 'hossam_ai_verify_facts' ) ) {
	function hossam_ai_verify_facts( string $claim ) {
		return new WP_Error(
			'hossam_ai_not_implemented',
			__( 'Live fact verification is a future expansion — not part of the initial AI scope.', 'hal-frontend-dashboard' )
		);
	}
}
