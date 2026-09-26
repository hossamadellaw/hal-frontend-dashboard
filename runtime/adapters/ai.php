<?php
/**
 * runtime/adapters/ai.php (الدفعة 4)
 * ══════════════════════════════════════════════════════════════
 * الدور: Registry وDispatcher مقيَّدان للذكاء الاصطناعي (المرجع الموحد
 * §7.4 حرفيًا) — الاستراتيجيتان المعتمدتان فقط: wp_ai_client وdirect_key.
 *
 * المصدر: نقل من legacy mu-plugins/hossam-dashboard/adapters/ai.php
 * (الدفعة 4 من HAL Frontend Dashboard) — التعديلات المصرَّح بها وحدها
 * (عقد credential/إعداد الدفعة 3 المعتمد):
 *   - hossam_ai_get_preference(): تقرأ تفضيل الاستراتيجية من
 *     HAL_Frontend_Dashboard_Settings_Repository (المالك المعتمد
 *     للإعداد — enum مغلق مطابق)؛ خارج ترتيب التحميل الطبيعي يبقى
 *     خيار legacy 'hossam_ai_preference' fallback محميًا.
 *   - hossam_ai_set_preference(): تكتب عبر repository save() في ترتيب
 *     التحميل الطبيعي (مسار كتابة واحد — §8.3)؛ خارج طبقة الدفعة 3
 *     يبقى خيار legacy محميًا بحارس class_exists — بنفس توقيع bool
 *     التاريخي.
 *   - hossam_ai_direct_key_ready(): بوابة إعداد المزود (الثابت
 *     HOSSAM_AI_PROVIDER) + وجود السر المشفر ai_direct_key في
 *     HAL Secret Store عبر has() حصرًا — بلا فك تشفير (تُستدعى من
 *     resolve_strategy في مسارات عرض/صحة). غياب السر ليس قرارًا هنا:
 *     مراقب الصحة يبلّغه بكود direct_key_secret_missing المغلق.
 *   - hossam_ai_resolve_strategy(): التفضيل الصريح direct_key مرفوض
 *     بكود hossam_ai_direct_key_not_fallback عند توفر قدرة WP AI
 *     Client (fallback فقط — المعمارية §7.6)؛ لا انتقال صامت لمزود
 *     آخر بعد بدء تنفيذ فعلي، وحسم الاستراتيجية عند submit وحده.
 *   - hossam_ai_connector_source_status(): حالة مصدر Connector غير
 *     حساسة (capable/source/reason) بلا المفتاح؛ التعذر عبر
 *     no_source_contract عند غياب عقد كاشف.
 *   - hossam_ai_allowed_models(): allowlist ثابتة (gemini-1.5-flash)؛
 *     غير المدرج مرفوض بكود hossam_ai_unsupported_model قبل الاتصال.
 *   - hossam_ai_get_provider_config(): provider/model من الثوابت كما
 *     هو، والمفتاح من HAL encrypted fallback حصرًا (قرار المالك §2.4:
 *     «direct_key المشفر fallback فقط» + RG3: allowlist المزود ملك
 *     هذا المحول). قناة HOSSAM_AI_API_KEY الثابتة للمفتاح استُبدلت
 *     بالمخزن المشفر؛ فشل فك السر يفشل مغلقًا بكود منقح بلا قيمة.
 *   - المنفِّذان والمطالبات وverify_* كما هي حرفيًا: endpoint ثابت،
 *     المفتاح في ترويسة x-goog-api-key فقط (لا query/body/log)،
 *     بلا streaming/embeddings، بلا fallback بين المزودين بعد بدء
 *     تنفيذ فعلي، وحسم الاستراتيجية عند submit وحده.
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ══════════════════════════════════════════════════════════════
// 1) فحص القدرة المعتمد + جاهزية direct_key
// ══════════════════════════════════════════════════════════════

/**
 * فحص القدرة المعتمد حرفيًا — محلي، بلا API call، بلا transient.
 * الحراسة الدفاعية (method_exists/is_object) تفصل حالة «الدالة موجودة
 * لكن عقد الـConnector مختلف» عن الفشل الصامت دون تغيير دلالة الفحص.
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
 * جاهزية direct_key: المزود مهيأ (ثابت موثق) والسر المشفر موجود في
 * HAL Secret Store — has() بلا فك تشفير. قراءة القيمة نفسها وقت
 * التنفيذ فقط عبر hossam_ai_get_provider_config().
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
// 2) الـRegistry — allow-list ثابت + توسعة مقيدة بالفلتر الرسمي
// ══════════════════════════════════════════════════════════════

if ( ! function_exists( 'hossam_ai_base_strategies' ) ) {
	/**
	 * القائمة الثابتة — لا يزيلها الفلتر ولا يستبدل callbacks الخاصة بها.
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
	 * الـRegistry الفعلي: الأساس الثابت + ما يضيفه الفلتر من PHP موثوق،
	 * بعد تنقية صارم (مفتاح نصي، callback قابل للاستدعاء، timeout محدود).
	 *
	 * @return array<string,array{label:string,callback:callable,timeout:int}>
	 */
	function hossam_ai_get_strategies(): array {
		$base = hossam_ai_base_strategies();
		if ( empty( $base ) ) {
			return [];
		}

		$clean = [];

		// التوسعة أولًا من الفلتر (PHP موثوق فقط)، ثم التنقية.
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

		// الأساس الثابت يُفرض أخيرًا: لا حذف ولا عطل بفلتر مخالف.
		foreach ( $base as $key => $entry ) {
			$clean[ $key ] = $entry;
		}

		return $clean;
	}
}

// ══════════════════════════════════════════════════════════════
// 3) التفضيل — عبر Settings Repository (المالك المعتمد للإعداد)
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

		// خارج ترتيب التحميل الطبيعي (طبقة الدفعة 3 غائبة): خيار legacy.
		$pref = (string) get_option( 'hossam_ai_preference', 'auto' );
		return in_array( $pref, hossam_ai_allowed_preferences(), true ) ? $pref : 'auto';
	}
}

if ( ! function_exists( 'hossam_ai_set_preference' ) ) {
	/**
	 * الكتابة الفعلية للتفضيل عبر repository save() حصرًا (مسار كتابة
	 * واحد — §8.3). المتصل (endpoint الإدارة) هو من يفرض nonce +
	 * capability؛ هذه الدالة تفرض النظافة والسماح فقط.
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
// 4) حسم الاستراتيجية عند submit — بلا أي انتقال بين المزودين
// ══════════════════════════════════════════════════════════════

if ( ! function_exists( 'hossam_ai_resolve_strategy' ) ) {
	/**
	 * @return string|WP_Error strategy_id غير الحساس، أو WP_Error فشل آمن.
	 */
	function hossam_ai_resolve_strategy( ?string $preference = null ) {
		$preference = $preference ?: hossam_ai_get_preference();
		$strategies = hossam_ai_get_strategies();
		if ( empty( $strategies ) ) {
			return new WP_Error(
				'hossam_ai_unavailable',
				__( 'AI assistance is unavailable in this environment.', 'astra-child' )
			);
		}

		switch ( $preference ) {
			case 'wp_ai_client':
				if ( ! isset( $strategies['wp_ai_client'] ) || ! hossam_ai_wp_client_supported() ) {
					return new WP_Error(
						'hossam_ai_unavailable',
						__( 'WordPress AI Client text generation is not available.', 'astra-child' )
					);
				}
				return 'wp_ai_client';

		case 'direct_key':
			if ( ! isset( $strategies['direct_key'] ) || ! hossam_ai_direct_key_ready() ) {
				return new WP_Error(
					'hossam_ai_unavailable',
					__( 'The direct AI provider key is not configured.', 'astra-child' )
				);
			}
			if ( hossam_ai_wp_client_supported() ) {
				return new WP_Error(
					'hossam_ai_direct_key_not_fallback',
					__( 'The direct provider key is only a fallback when no WordPress AI Client capability is available.', 'astra-child' )
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
					__( 'No AI provider is currently available.', 'astra-child' )
				);
		}
	}
}

if ( ! function_exists( 'hossam_ai_interface_available' ) ) {
	/**
	 * بوابة العرض الخادمية لواجهة Posts: هل توجد استراتيجية صالحة الآن؟
	 * (إظهار الأزرار شرطٌ عرضي فقط — التفويض يعاد في كل endpoint.)
	 */
	function hossam_ai_interface_available(): bool {
		return ! is_wp_error( hossam_ai_resolve_strategy() );
	}
}

if ( ! function_exists( 'hossam_ai_connector_source_status' ) ) {
	/**
	 * حالة مصدر اعتماد Connector غير حساسة (عقد الدفعة 4 — المعمارية §15):
	 * قدرة text generation ومصدر الاعتماد الفعلي، بلا نسخ المفتاح أو
	 * كشفه. لا يستخدم version check ولا يجري API call. السطح المثبت
	 * المستشار وحده هو `wp_ai_client_prompt()` مع
	 * `is_supported_for_text_generation()` (Owner §2.6) — قدرة فقط
	 * بلا حقل مصدر؛ لذا `undetermined/no_source_contract` هي القيمة
	 * الصادقة الوحيدة دون قراءة السر. لا تُخترع أسماء
	 * Environment/constants ولا يُكتفى بالتحذير العام.
	 *
	 * @return array{capable:bool,source:string,reason:string} source:
	 *         none|undetermined (التوسعة Change Request مستقل).
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
// 5) منفِّذا الاستراتيجيتين — كلٌّ يستقبل prompt جاهزًا ويعيد نصًا أو WP_Error
// ══════════════════════════════════════════════════════════════

/**
 * قائمة models الثابتة المعتمدة لاستراتيجية direct_key — أي model
 * خارجها يُرفض قبل الاتصال. التوسعة Change Request مستقل.
 *
 * @return string[]
 */
if ( ! function_exists( 'hossam_ai_allowed_models' ) ) {
	function hossam_ai_allowed_models(): array {
		return array( 'gemini-1.5-flash' );
	}
}

/**
 * قراءة إعداد direct_key: provider/model من الثوابت، والمفتاح من
 * HAL encrypted fallback (ai_direct_key) حصرًا. مركزية هنا فقط —
 * القيمة تُقرأ وقت التنفيذ ولا تُمرر للمتصفح/السجل أبدًا.
 *
 * @return array{provider:string,api_key:string,model:string}|WP_Error
 */
if ( ! function_exists( 'hossam_ai_get_provider_config' ) ) {
	function hossam_ai_get_provider_config() {
		if ( ! defined( 'HOSSAM_AI_PROVIDER' ) || ! is_string( HOSSAM_AI_PROVIDER ) || '' === sanitize_key( (string) HOSSAM_AI_PROVIDER ) ) {
			return new WP_Error(
				'hossam_ai_not_configured',
				__( 'AI provider is not configured.', 'astra-child' )
			);
		}

		$provider = sanitize_key( (string) HOSSAM_AI_PROVIDER );

		if ( ! class_exists( 'HAL_Frontend_Dashboard_Secret_Store' ) || ! HAL_Frontend_Dashboard_Secret_Store::has( 'ai_direct_key' ) ) {
			return new WP_Error(
				'hossam_ai_not_configured',
				__( 'AI provider is not configured.', 'astra-child' )
			);
		}
		$api_key = HAL_Frontend_Dashboard_Secret_Store::get( 'ai_direct_key' );
		if ( is_wp_error( $api_key ) || ! is_string( $api_key ) || '' === trim( $api_key ) ) {
			return new WP_Error(
				'hossam_ai_credential_unreadable',
				__( 'The AI credential could not be read.', 'astra-child' )
			);
		}

		$model = defined( 'HOSSAM_AI_MODEL' ) && is_string( HOSSAM_AI_MODEL ) && '' !== HOSSAM_AI_MODEL
			? HOSSAM_AI_MODEL
			: 'gemini-1.5-flash';
		if ( ! in_array( $model, hossam_ai_allowed_models(), true ) ) {
			return new WP_Error(
				'hossam_ai_unsupported_model',
				__( 'The configured AI model is not supported.', 'astra-child' )
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
	 * تنفيذ استراتيجية WordPress AI Client — فشل آمن عند غياب العقد.
	 * الفحص عبر is_supported_for_text_generation() حصرًا (لا generate_text للفحص).
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
				__( 'WordPress AI Client text generation is not available.', 'astra-child' )
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
					__( 'AI generation is unavailable in this environment.', 'astra-child' )
				);
			}

			$text = $prompt_object->generate_text();
		} catch ( Throwable $e ) {
			// فشل آمن: لا تسريب لرسائل الاستثناء الداخلية للمتصفح أو الجدول.
			return new WP_Error(
				'hossam_ai_generation_failed',
				__( 'AI generation failed.', 'astra-child' )
			);
		} finally {
			remove_filter( 'wp_ai_client_default_request_timeout', $timeout_filter );
		}

		if ( is_wp_error( $text ) ) {
			return new WP_Error(
				'hossam_ai_generation_failed',
				__( 'AI generation failed.', 'astra-child' )
			);
		}

		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return new WP_Error(
				'hossam_ai_empty_result',
				__( 'AI returned an empty result.', 'astra-child' )
			);
		}

		return trim( $text );
	}
}

if ( ! function_exists( 'hossam_ai_execute_direct_key' ) ) {
	/**
	 * تنفيذ استراتيجية direct_key — منطق Gemini REST القائم كما هو مع
	 * فرقين موثقَين (منقولان من المصدر):
	 *   - timeout يأتي من الـRegistry لكل استراتيجية.
	 *   - جسم استجابة المزود الخام لا يُرفَق فى data الخطأ حتى لا
	 *     يتسرب إلى أي تخزين/سجل؛ رسالة الخطأ آمنة (رمز HTTP فقط).
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
				__( 'The configured AI provider is not supported.', 'astra-child' )
			);
		}

		$defaults = [
			'temperature' => 0.4,
			'max_tokens'  => 1024,
		];
		$options = wp_parse_args( $options, $defaults );

		// المصادقة عبر ترويسة x-goog-api-key — المفتاح لا يدخل query string
		// ولا body ولا logs ولا options أبدًا.
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
				__( 'AI request failed.', 'astra-child' )
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'hossam_ai_http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'AI provider returned HTTP %d.', 'astra-child' ),
					(int) $status
				)
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		// بنية رد Gemini الرسمية: candidates[0].content.parts[0].text.
		$text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

		if ( null === $text ) {
			$block_reason = $data['promptFeedback']['blockReason'] ?? null;
			return new WP_Error(
				'hossam_ai_unexpected_response',
				$block_reason
					? sprintf(
						/* translators: %s: Gemini block reason */
						__( 'AI provider blocked this request (reason: %s).', 'astra-child' ),
						sanitize_key( (string) $block_reason )
					)
					: __( 'AI provider response did not contain the expected content field.', 'astra-child' )
			);
		}

		return trim( (string) $text );
	}
}

// ══════════════════════════════════════════════════════════════
// دوال بناء الـPrompts — كل واحدة تخص قدرة واحدة فقط من قدرات
// النطاق الأولي المعتمَد (مساعد كتابة). لا شىء منها يتصل بالشبكة
// مباشرة — تُستدعى من ajax/ai.php ثم تمرر نتيجتها للمنفِّذ المطابق.
// ══════════════════════════════════════════════════════════════

if ( ! function_exists( 'hossam_ai_build_grammar_prompt' ) ) {
	function hossam_ai_build_grammar_prompt( string $content ): string {
		return "راجع النص القانوني/العربي التالي لغويًا ونحويًا فقط، بلا تغيير المعنى أو الأسلوب. أرجع النص مصححًا فقط، بلا شرح إضافي:\n\n{$content}";
	}
}

if ( ! function_exists( 'hossam_ai_build_translation_prompt' ) ) {
	function hossam_ai_build_translation_prompt( string $content, string $target_lang ): string {
		return "ترجم النص التالي إلى اللغة ذات الرمز \"{$target_lang}\" بدقة، محافظًا على المصطلحات القانونية. أرجع الترجمة فقط:\n\n{$content}";
	}
}

if ( ! function_exists( 'hossam_ai_build_seo_prompt' ) ) {
	function hossam_ai_build_seo_prompt( string $title, string $content ): string {
		return "اقترح لهذا المقال: كلمة مفتاحية رئيسية واحدة، ووصف Meta لا يتجاوز 160 حرفًا. رجِّع النتيجة بصيغة JSON فقط بالمفتاحين focus_keyword وmeta_description:\n\nالعنوان: {$title}\n\nالمحتوى: {$content}";
	}
}

if ( ! function_exists( 'hossam_ai_build_improvement_prompt' ) ) {
	function hossam_ai_build_improvement_prompt( string $content ): string {
		return "اقترح 3 تحسينات محدَّدة وموجزة لوضوح وقوة صياغة هذا المقال القانوني (لا تكتب المقال من جديد، فقط اقتراحات نقطية):\n\n{$content}";
	}
}

/**
 * (أ) صلاحية الرابط — WordPress Core مباشرة، لا استدعاء AI إطلاقًا.
 *
 * @param string[] $urls
 * @return array<string,array{valid:bool,status:int|null}>
 */
if ( ! function_exists( 'hossam_ai_verify_links' ) ) {
	function hossam_ai_verify_links( array $urls ): array {
		$results = [];

		foreach ( $urls as $url ) {
			$url = esc_url_raw( $url );
			if ( '' === $url ) {
				continue;
			}

			$response = wp_remote_head( $url, [ 'timeout' => 8 ] );
			$status   = is_wp_error( $response ) ? null : wp_remote_retrieve_response_code( $response );

			$results[ $url ] = [
				'valid'  => null !== $status && $status >= 200 && $status < 400,
				'status' => $status,
			];
		}

		return $results;
	}
}

/**
 * (ب) التحقق الحي من صحة المعلومة — توسّع مستقبلي، غير مُنفَّذ عمدًا
 * فى هذا النطاق (خارج نطاق الدفعات المعتمدة بلا طلب وظيفي مستقل).
 */
if ( ! function_exists( 'hossam_ai_verify_facts' ) ) {
	function hossam_ai_verify_facts( string $claim ) {
		return new WP_Error(
			'hossam_ai_not_implemented',
			__( 'Live fact verification is a future expansion — not part of the initial AI scope.', 'astra-child' )
		);
	}
}
