<?php
/**
 * runtime/ajax/ai.php (الدفعة 6)
 * ══════════════════════════════════════════════════════════════
 * الدور: طبقة Job Queue غير متزامنة لكل استدعاءات الذكاء الاصطناعي:
 * submit_job/get_job_status/set_preference (wp_ajax_*) + العامل الداخلي
 * hossam_ai_process_job_event (cron hook — لا wp_ajax ولا cookies ولا
 * nonce للعامل) + الكسح الدوري hossam_ai_sweep_jobs_event عبر init.
 *
 * المصدر: نقل حرفي 1:1 لكل الكود من legacy mu-plugins/hossam-dashboard/
 * ajax/ai.php — بصمة المصدر الحالي بالبايت مثبتة في inventory الدفعة 6
 * (build/source-inventory-batch-6.json، snapshot E48A0A98063529FA19C53E7AD25DDFA2A665C7E4AA795B0E280CADD8841490CA؛
 * المصدر انحرف عن baseline الدفعة 0 قبل ترحيله — موثق في الـinventory).
 *
 * الدلتا المصرَّح بها الوحيدة (قرار المالك B3-08، الخيار أ):
 * 1) بوابة feature في الـendpoints الثلاثة بعد nonce وحرس 401 إن وُجد
 *    وقبل أي capability check — is_feature_enabled('ai') === false →
 *    feature_disabled 403 فشل مغلق؛ الافتراضي (enabled) يطابق المصدر.
 * 2) بوابة العامل بعد الـclaim الناجح حصرًا (حتى لا تُدنَّس مهمة غير
 *    pending): is_feature_enabled('ai') === false → فشل آمن موثق
 *    hossam_ai_fail_job( $job_id, 'feature_disabled' ) دون ترك مهمة
 *    معلقة (عقد §7.6: منع jobs جديدة + عقد موثق على pending دون تعليق) —
 *    ولا تنفيذ أي استراتيجية والمصدر الأصلي بلا feature toggles إطلاقًا.
 *
 * العقد المنقول كما هو: ملخص رأس المصدر — استبدال loopback بجدولة داخلية،
 * claim ذري WHERE status=pending مع إعادة تحقق user_can(edit_post) قبل
 * التنفيذ، حسم الاستراتيجية عند submit وحده (لا fallback بعد البدء)،
 * أزمنة UTC حصرًا، حماية تكلفة ثلاثية (transient/دaily/concurrent)،
 * والقيم التشغيلية من runtime profile موثوق يفشل آمنًا عند غيابه.
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * أنواع المهام المسموحة — أي قيمة غير مذكورة هنا تُرفَض فورًا.
 */
if ( ! function_exists( 'hossam_ai_get_allowed_job_types' ) ) {
	function hossam_ai_get_allowed_job_types(): array {
		return [ 'grammar', 'translation', 'seo', 'improvement' ];
	}
}

/**
 * @return array{per_minute:int,per_day:int,concurrent:int}|WP_Error
 */
if ( ! function_exists( 'hossam_ai_get_rate_limits' ) ) {
	function hossam_ai_get_rate_limits() {
		$profile = hossam_ai_get_runtime_profile();
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		return [
			'per_minute' => $profile['per_minute'],
			'per_day'    => $profile['per_day'],
			'concurrent' => $profile['concurrent'],
		];
	}
}

/**
 * @return array{max_attempts:int,stale_pending:int,processing_deadline:int}|WP_Error
 */
if ( ! function_exists( 'hossam_ai_get_sweep_limits' ) ) {
	function hossam_ai_get_sweep_limits() {
		$profile = hossam_ai_get_runtime_profile();
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		return [
			'max_attempts'        => $profile['max_attempts'],
			'stale_pending'       => $profile['stale_pending'],
			'processing_deadline' => $profile['processing_deadline'],
		];
	}
}

/**
 * Build a non-revealing MySQL named-lock namespace for this DB/site.
 */
if ( ! function_exists( 'hossam_ai_get_lock_name' ) ) {
	function hossam_ai_get_lock_name( string $scope, string $resource = '' ): string {
		global $wpdb;
		$database = ( defined( 'DB_HOST' ) ? (string) DB_HOST : '' ) . "\0"
			. ( defined( 'DB_NAME' ) ? (string) DB_NAME : '' );
		$blog_id = function_exists( 'get_current_blog_id' )
			? (int) get_current_blog_id()
			: (int) ( $wpdb->blogid ?? 0 );
		$identity = $database . "\0" . (string) $wpdb->prefix . "\0" . $blog_id . "\0" . $resource;

		return 'hossam_ai_' . sanitize_key( $scope ) . '_' . substr( hash( 'sha256', $identity ), 0, 40 );
	}
}

/**
 * تعليم job حالة نهائية بخطأ آمن — الرسالة نص داخلى ثابت فقط،
 * لا تفاصيل مزود ولا أسرار. UPDATE مُجهَّز صراحةً لضبط أعمدة NULL
 * بسلوك موحد عبر إصدارات wpdb.
 */
if ( ! function_exists( 'hossam_ai_fail_job' ) ) {
	function hossam_ai_fail_job( int $job_id, string $safe_reason ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'hossam_ai_jobs';
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
					SET status = 'failed',
						last_error = %s,
						locked_at = NULL,
						updated_at = %s
					WHERE id = %d",
				sanitize_key( $safe_reason ),
				gmdate( 'Y-m-d H:i:s' ),
				$job_id
			)
		);
	}
}

if ( ! function_exists( 'hossam_ai_fail_job_if_outstanding' ) ) {
	/**
	 * B6-02 race hardening: conditional terminal transition — the UPDATE
	 * itself only touches pending/processing rows, so a job that finished
	 * between selection and update is never flipped (atomic check-and-set
	 * at the DB layer; a pre-UPDATE re-read alone would not close this
	 * window). Callers keep their state pre-check to avoid pointless
	 * writes; this predicate is the actual safety guarantee.
	 *
	 * @return bool true when a row transitioned to failed.
	 */
	function hossam_ai_fail_job_if_outstanding( int $job_id, string $safe_reason ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'hossam_ai_jobs';
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
					SET status = 'failed',
						last_error = %s,
						locked_at = NULL,
						updated_at = %s
					WHERE id = %d AND status IN ('pending', 'processing')",
				sanitize_key( $safe_reason ),
				gmdate( 'Y-m-d H:i:s' ),
				$job_id
			)
		);

		return false !== $updated && (int) $updated > 0;
	}
}

/**
 * Insert one pending job while holding the per-user quota lock.
 *
 * @param array{per_minute:int,per_day:int,concurrent:int} $limits
 * @param array<string,mixed>                               $job_data
 * @param string[]                                         $job_formats
 * @return int|WP_Error
 */
if ( ! function_exists( 'hossam_ai_insert_job_under_quota' ) ) {
	function hossam_ai_insert_job_under_quota( int $user_id, array $limits, array $job_data, array $job_formats ) {
		global $wpdb;
		$table           = $wpdb->prefix . 'hossam_ai_jobs';
		$lock_name       = hossam_ai_get_lock_name( 'quota', (string) $user_id );
		$lock_acquired   = false;
		$minute_reserved = false;
		$job_created     = false;
		$minute_key      = 'hossam_ai_rate_' . $user_id;
		$minute_previous = false;

		$wpdb->flush();
		$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 2)', $lock_name ) );
		if ( 1 !== (int) $locked || '' !== (string) $wpdb->last_error ) {
			return new WP_Error( 'quota_lock_failed', 'AI service is busy. Try again.', [ 'status' => 503 ] );
		}
		$lock_acquired = true;

		try {
			$minute_previous = get_transient( $minute_key );
			$minute_count    = false === $minute_previous ? 0 : (int) $minute_previous;
			if ( $minute_count >= $limits['per_minute'] ) {
				return new WP_Error( 'rate_limit', 'Rate limit exceeded. Try later.', [ 'status' => 429 ] );
			}

			if ( ! set_transient( $minute_key, $minute_count + 1, MINUTE_IN_SECONDS ) ) {
				return new WP_Error( 'quota_write_failed', 'AI service is unavailable. Try again.', [ 'status' => 503 ] );
			}
			$minute_reserved = true;

			$wpdb->flush();
			$day_count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND created_at >= %s",
					$user_id,
					gmdate( 'Y-m-d 00:00:00' )
				)
			);
			if ( '' !== (string) $wpdb->last_error || null === $day_count ) {
				return new WP_Error( 'daily_count_failed', 'AI service is unavailable. Try again.', [ 'status' => 503 ] );
			}
			if ( (int) $day_count >= $limits['per_day'] ) {
				return new WP_Error( 'daily_limit', 'Daily AI limit reached.', [ 'status' => 429 ] );
			}

			$wpdb->flush();
			$inserted = $wpdb->insert( $table, $job_data, $job_formats );
			if ( ! $inserted || '' !== (string) $wpdb->last_error || (int) $wpdb->insert_id < 1 ) {
				return new WP_Error( 'job_insert_failed', 'AI service is unavailable. Try again.', [ 'status' => 503 ] );
			}

			$job_created = true;
			return (int) $wpdb->insert_id;
		} finally {
			if ( $minute_reserved && ! $job_created ) {
				if ( false === $minute_previous ) {
					delete_transient( $minute_key );
				} else {
					set_transient( $minute_key, (int) $minute_previous, MINUTE_IN_SECONDS );
				}
			}
			if ( $lock_acquired ) {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
			}
		}
	}
}

/**
 * Retry a pending worker event after a short delay; sweep remains the fallback.
 */
if ( ! function_exists( 'hossam_ai_schedule_job_retry' ) ) {
	function hossam_ai_schedule_job_retry( int $job_id ): bool {
		$hook = 'hossam_ai_process_job_event';
		$args = [ $job_id ];
		if ( false !== wp_next_scheduled( $hook, $args ) ) {
			return true;
		}

		$scheduled = wp_schedule_single_event( time() + MINUTE_IN_SECONDS, $hook, $args );
		return false !== $scheduled && ! is_wp_error( $scheduled );
	}
}

/**
 * Enforce global worker capacity and atomically claim one pending job.
 *
 * @return bool|WP_Error True when claimed, false for a duplicate/non-pending event.
 */
if ( ! function_exists( 'hossam_ai_claim_job_with_capacity' ) ) {
	function hossam_ai_claim_job_with_capacity( int $job_id, int $concurrent_limit ) {
		global $wpdb;
		$table         = $wpdb->prefix . 'hossam_ai_jobs';
		$lock_name     = hossam_ai_get_lock_name( 'capacity' );
		$lock_acquired = false;

		$wpdb->flush();
		$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 2)', $lock_name ) );
		if ( 1 !== (int) $locked || '' !== (string) $wpdb->last_error ) {
			return new WP_Error( 'claim_lock_failed', 'AI worker is busy.' );
		}
		$lock_acquired = true;

		try {
			$wpdb->flush();
			$processing = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'processing'" );
			if ( '' !== (string) $wpdb->last_error || null === $processing ) {
				return new WP_Error( 'processing_count_failed', 'AI worker is busy.' );
			}
			if ( (int) $processing >= $concurrent_limit ) {
				return new WP_Error( 'processing_limit', 'AI worker is busy.' );
			}

			$utc_now = gmdate( 'Y-m-d H:i:s' );
			$wpdb->flush();
			$claimed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table}
						SET status = 'processing',
							locked_at = %s,
							attempt_count = attempt_count + 1,
							updated_at = %s
						WHERE id = %d AND status = 'pending'",
					$utc_now,
					$utc_now,
					$job_id
				)
			);
			if ( false === $claimed || '' !== (string) $wpdb->last_error ) {
				return new WP_Error( 'job_claim_failed', 'AI worker is busy.' );
			}

			return 1 === (int) $claimed;
		} finally {
			if ( $lock_acquired ) {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
			}
		}
	}
}

// ══════════════════════════════════════════════════════════════
// 1) تقديم مهمة جديدة — تحقق مستخدم/ملكية/حدود، حسم الاستراتيجية،
//    حفظ job + strategy_id، ثم فحص نتيجة الجدولة
// ══════════════════════════════════════════════════════════════
add_action( 'wp_ajax_hossam_ai_submit_job', function (): void {
	check_ajax_referer( 'hossam_nonce', 'nonce' );

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );
	}
	if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'ai' ) ) {
		wp_send_json_error( array( 'code' => 'feature_disabled', 'message' => 'This feature is disabled.' ), 403 );
	}

	$post_id  = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
	$job_type = isset( $_POST['job_type'] ) ? sanitize_key( $_POST['job_type'] ) : '';
	$content  = isset( $_POST['content'] ) ? wp_kses_post( wp_unslash( $_POST['content'] ) ) : '';

	if ( ! in_array( $job_type, hossam_ai_get_allowed_job_types(), true ) ) {
		wp_send_json_error( [ 'message' => 'Invalid job_type' ], 400 );
	}

	if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
		wp_send_json_error( [ 'message' => 'Empty content' ], 400 );
	}

	if ( ! $post_id && ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
	}

	// ملكية المقال عبر قدرة Core على مستوى المورد (المرجع سطر 142 حرفيًا):
	// لا مقارنة post_author يدوية — Core يحسم الكاتب والمحرر والمدير.
	if ( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post
			|| 'post' !== $post->post_type
			|| 'trash' === $post->post_status
			|| ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized for this article' ], 403 );
		}
	}

	$limits = hossam_ai_get_rate_limits();
	if ( is_wp_error( $limits ) ) {
		wp_send_json_error( [ 'message' => 'AI assistance is unavailable in this environment.' ], 503 );
	}

	// ── حسم الاستراتيجية عند submit وحده؛ الفشل هنا فشل نهائي آمن.
	$strategy = hossam_ai_resolve_strategy();
	if ( is_wp_error( $strategy ) ) {
		wp_send_json_error( [ 'message' => 'No AI provider is currently available.' ], 503 );
	}

	$input_data = [
		'content'     => $content,
		'target_lang' => isset( $_POST['target_lang'] ) ? sanitize_text_field( wp_unslash( $_POST['target_lang'] ) ) : '',
		'title'       => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
	];

	$uid     = get_current_user_id();
	$utc_now = gmdate( 'Y-m-d H:i:s' );
	$job_id  = hossam_ai_insert_job_under_quota(
		$uid,
		$limits,
		[
			'user_id'       => $uid,
			'post_id'       => $post_id ?: null,
			'job_type'      => $job_type,
			'input_data'    => wp_json_encode( $input_data ),
			'status'        => 'pending',
			'strategy_id'   => $strategy,
			'attempt_count' => 0,
			'next_run_at'   => $utc_now,
			'created_at'    => $utc_now,
			'updated_at'    => $utc_now,
		],
		[ '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ]
	);

	if ( is_wp_error( $job_id ) ) {
		$error_data = $job_id->get_error_data();
		wp_send_json_error(
			[ 'message' => $job_id->get_error_message() ],
			is_array( $error_data ) && isset( $error_data['status'] ) ? (int) $error_data['status'] : 503
		);
	}

	// ── الجدولة الداخلية: hook واحد بمعاملاته، بلا تكرار، وبفحص نتيجة.
	//    لا loopback ولا admin-ajax ولا nonce ولا cookies للعامل.
	$hook = 'hossam_ai_process_job_event';
	$args = [ $job_id ];

	if ( false !== wp_next_scheduled( $hook, $args ) ) {
		// حدث بنفس الـhook والمعاملات مجدول بالفعل — لا ازدواج.
		wp_send_json_success( [ 'job_id' => $job_id ] );
	}

	$scheduled = wp_schedule_single_event( time(), $hook, $args );
	if ( false === $scheduled || is_wp_error( $scheduled ) ) {
		hossam_ai_fail_job( $job_id, 'schedule_failed' );
		wp_send_json_error( [ 'message' => 'Could not schedule the AI task.' ], 500 );
	}

	wp_send_json_success( [ 'job_id' => $job_id ] );
} );

// ══════════════════════════════════════════════════════════════
// 2) العامل الداخلي — hook cron فقط. لا wp_ajax_hossam_ai_process_job،
//    لا cookies، لا nonce. الـclaim ذري ثم إعادة تحقق الصلاحية قبل التنفيذ.
//    B6-02: فشل الـprofile مع التعطيل ينهي pending/processing
//    (feature_disabled) بدل إعادة الجدولة العمياء.
// ══════════════════════════════════════════════════════════════
add_action( 'hossam_ai_process_job_event', function ( int $job_id ): void {
	global $wpdb;
	$table   = $wpdb->prefix . 'hossam_ai_jobs';
	$profile = hossam_ai_get_runtime_profile();
	if ( is_wp_error( $profile ) ) {
		// B6-02: فشل الـprofile لا يعيد الجدولة عميانيًا — التعطيل
		// ينهي المهام القائمة (pending/processing فقط؛ المنتهية أو
		// الغائبة أو عطل القراءة تُترك دون مساس ولا إعادة جدولة
		// عبثية)، وإلا تُعاد الجدولة كالسابق.
		if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'ai' ) ) {
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", $job_id ), ARRAY_A );
			$state    = is_array( $existing ) ? ( $existing['status'] ?? null ) : null;
			if ( is_string( $state ) && in_array( $state, array( 'pending', 'processing' ), true ) ) {
				hossam_ai_fail_job_if_outstanding( $job_id, 'feature_disabled' );
			}
			return;
		}
		hossam_ai_schedule_job_retry( $job_id );
		return;
	}
	$claimed = hossam_ai_claim_job_with_capacity( $job_id, $profile['concurrent'] );
	if ( is_wp_error( $claimed ) ) {
		hossam_ai_schedule_job_retry( $job_id );
		return;
	}
	if ( ! $claimed ) {
		return; // قبضها عامل آخر أو ليست pending — لا شيء يُنفَّذ.
	}
	if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'ai' ) ) {
		hossam_ai_fail_job( $job_id, 'feature_disabled' );
		return;
	}

	$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $job_id ), ARRAY_A );
	if ( ! $job ) {
		return;
	}

	if ( (int) $job['attempt_count'] > $profile['max_attempts'] ) {
		hossam_ai_fail_job( $job_id, 'max_attempts_exceeded' );
		return;
	}

	// إعادة التحقق من الصلاحية قبل التنفيذ (المرجع حرفيًا): فقدان
	// صلاحية المستخدم على المقال بين submit والتنفيذ يفشل بأمان.
	$post_id = (int) $job['post_id'];
	$allowed = $post_id
		? user_can( (int) $job['user_id'], 'edit_post', $post_id )
		: user_can( (int) $job['user_id'], 'edit_posts' );
	if ( ! $allowed ) {
		hossam_ai_fail_job( $job_id, 'permission_revoked' );
		return;
	}

	$input = json_decode( (string) $job['input_data'], true );
	$input = is_array( $input ) ? $input : [];

	switch ( $job['job_type'] ) {
		case 'grammar':
			$prompt = hossam_ai_build_grammar_prompt( $input['content'] ?? '' );
			break;
		case 'translation':
			$prompt = hossam_ai_build_translation_prompt( $input['content'] ?? '', $input['target_lang'] ?? 'en' );
			break;
		case 'seo':
			$prompt = hossam_ai_build_seo_prompt( $input['title'] ?? '', $input['content'] ?? '' );
			break;
		case 'improvement':
			$prompt = hossam_ai_build_improvement_prompt( $input['content'] ?? '' );
			break;
		default:
			hossam_ai_fail_job( $job_id, 'unknown_job_type' );
			return;
	}

	// التنفيذ على strategy_id المحفوظ عند submit حصرًا — لا إعادة حسم
	// فى العامل ولا fallback لمزود ثانٍ بعد بدء التنفيذ الفعلي.
	$strategies = hossam_ai_get_strategies();
	$strategy_id = (string) $job['strategy_id'];

	if ( ! isset( $strategies[ $strategy_id ] ) || ! is_callable( $strategies[ $strategy_id ]['callback'] ) ) {
		hossam_ai_fail_job( $job_id, 'unknown_strategy' );
		return;
	}

	$result = call_user_func(
		$strategies[ $strategy_id ]['callback'],
		$prompt,
		[ 'timeout' => (int) $strategies[ $strategy_id ]['timeout'] ]
	);

	if ( is_wp_error( $result ) ) {
		hossam_ai_fail_job( $job_id, 'generation_failed' );
		return;
	}

	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$table}
				SET status = 'completed',
					result = %s,
					locked_at = NULL,
					last_error = NULL,
					updated_at = %s
				WHERE id = %d",
			wp_json_encode( [ 'text' => (string) $result ] ),
			gmdate( 'Y-m-d H:i:s' ),
			$job_id
		)
	);
} );

// ══════════════════════════════════════════════════════════════
// 3) الكسح الدوري — hossam_every_five_minutes من core/setup.php.
//    إعادة جدولة pending العالقة ضمن حد المحاولات (بعد فحص
//    wp_next_scheduled)، وفشل processing المتجاوزة deadline بأمان
//    بلا retry لمزود بديل.
// ══════════════════════════════════════════════════════════════
add_action( 'init', function (): void {
	if ( wp_next_scheduled( 'hossam_ai_sweep_jobs_event' ) ) {
		return;
	}
	wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hossam_every_five_minutes', 'hossam_ai_sweep_jobs_event' );
} );

add_action( 'hossam_ai_sweep_jobs_event', function (): void {
	global $wpdb;
	$table  = $wpdb->prefix . 'hossam_ai_jobs';
	$sweep  = hossam_ai_get_sweep_limits();
	if ( is_wp_error( $sweep ) ) {
		// B6-02: بلا حدود كسح لا تُحسم الأعمار — لكن التعطيل ينهي
		// القائمة (pending/processing) إذ لا تنفيذ ممكن أبدًا ما دام
		// التقديم محظورًا (submit/ad adapters ترفض) وكل حدث عامل يأخذ
		// المسار السريع نفسه؛ completed/failed تُترك دائمًا. الـprocessing
		// هنا يتيم حتمًا: لا منفذ حي يمكنه إكماله تحت التعطيل، ومسار
		// الـdeadline معطل بغياب الـprofile.
		if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'ai' ) ) {
			$pending = $wpdb->get_col( "SELECT id FROM {$table} WHERE status = 'pending' LIMIT 20" );
			foreach ( (array) $pending as $pending_id ) {
				hossam_ai_fail_job_if_outstanding( (int) $pending_id, 'feature_disabled' );
			}
			$orphaned = $wpdb->get_col( "SELECT id FROM {$table} WHERE status = 'processing' LIMIT 20" );
			foreach ( (array) $orphaned as $orphaned_id ) {
				hossam_ai_fail_job_if_outstanding( (int) $orphaned_id, 'feature_disabled' );
			}
		}
		return;
	}
	$utc_now = time();

	// (أ) processing تجاوزت deadline → فشل نهائي بأمان، بلا أي retry.
	$deadline_utc = gmdate( 'Y-m-d H:i:s', $utc_now - $sweep['processing_deadline'] );
	$expired = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT id FROM {$table} WHERE status = 'processing' AND locked_at IS NOT NULL AND locked_at <= %s LIMIT 20",
			$deadline_utc
		)
	);
	foreach ( (array) $expired as $expired_id ) {
		hossam_ai_fail_job( (int) $expired_id, 'processing_deadline_exceeded' );
	}

	// (ب) pending عالقة (فاتها حدثها) ضمن حد المحاولات → إعادة جدولة
	//     نفس الـhook ونفس المعاملات بعد فحص wp_next_scheduled حرفيًا.
	$stale_utc = gmdate( 'Y-m-d H:i:s', $utc_now - $sweep['stale_pending'] );
	$stale = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id FROM {$table} WHERE status = 'pending' AND next_run_at IS NOT NULL AND next_run_at <= %s AND attempt_count < %d LIMIT 20",
			$stale_utc,
			$sweep['max_attempts']
		),
		ARRAY_A
	);

	foreach ( (array) $stale as $row ) {
		$job_id = (int) $row['id'];
		$hook   = 'hossam_ai_process_job_event';
		$args   = [ $job_id ];

		if ( false !== wp_next_scheduled( $hook, $args ) ) {
			continue; // لا ازدواج أحداث — المرجع حرفيًا.
		}

		$scheduled = wp_schedule_single_event( time(), $hook, $args );
		if ( false === $scheduled || is_wp_error( $scheduled ) ) {
			continue; // فشل جدولة الكسح لا يعلم المهمة فشلاً — محاولة الدورة التالية.
		}

		$wpdb->update(
			$table,
			[ 'next_run_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ],
			[ 'id' => $job_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}
} );

// ══════════════════════════════════════════════════════════════
// 4) استطلاع حالة المهمة — المالك وحده؛ لا strategy ولا secrets فى
//    الاستجابة (job_id والحالة والنتيجة الآمنة فقط، كما تستهلكها ai.js).
// ══════════════════════════════════════════════════════════════
add_action( 'wp_ajax_hossam_ai_get_job_status', function (): void {
	check_ajax_referer( 'hossam_nonce', 'nonce' );

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );
	}
	if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'ai' ) ) {
		wp_send_json_error( array( 'code' => 'feature_disabled', 'message' => 'This feature is disabled.' ), 403 );
	}

	$job_id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
	if ( ! $job_id ) {
		wp_send_json_error( [ 'message' => 'Invalid job_id' ], 400 );
	}

	global $wpdb;
	$table = $wpdb->prefix . 'hossam_ai_jobs';

	// شرط الملكية داخل WHERE — مالك المهمة فقط يستطلع حالتها.
	$job = $wpdb->get_row(
		$wpdb->prepare( "SELECT status, result, last_error FROM {$table} WHERE id = %d AND user_id = %d", $job_id, get_current_user_id() ),
		ARRAY_A
	);

	if ( ! $job ) {
		wp_send_json_error( [ 'message' => 'Job not found' ], 404 );
	}

	wp_send_json_success( [
		'status' => $job['status'],
		'result' => $job['result'] ? json_decode( $job['result'], true ) : null,
		'error'  => $job['last_error'],
	] );
} );

// ══════════════════════════════════════════════════════════════
// 5) تحرير التفضيل — manage_options حصريًا (البند: option محدود ومُنظَّف).
//    لا واجهة له خارج posts.php التزامًا بقيد «تطوير جديد داخل posts.php فقط».
// ══════════════════════════════════════════════════════════════
add_action( 'wp_ajax_hossam_ai_set_preference', function (): void {
	check_ajax_referer( 'hossam_nonce', 'nonce' );
	if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'ai' ) ) {
		wp_send_json_error( array( 'code' => 'feature_disabled', 'message' => 'This feature is disabled.' ), 403 );
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
	}

	$preference = isset( $_POST['preference'] ) ? sanitize_key( wp_unslash( $_POST['preference'] ) ) : '';

	if ( ! hossam_ai_set_preference( $preference ) ) {
		wp_send_json_error( [ 'message' => 'Invalid preference' ], 400 );
	}

	wp_send_json_success( [ 'preference' => hossam_ai_get_preference() ] );
} );
