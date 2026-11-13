<?php
/**
 * runtime/ajax/ai.php (Batch 6)
 * ══════════════════════════════════════════════════════════════
 * Role: an asynchronous Job Queue layer for every AI call:
 * submit_job/get_job_status/set_preference (wp_ajax_*) + the internal worker
 * hossam_ai_process_job_event (cron hook — no wp_ajax, no cookies, no
 * nonce for the worker) + the periodic sweep hossam_ai_sweep_jobs_event via init.
 *
 * Source: verbatim 1:1 transfer of all code from legacy mu-plugins/hossam-dashboard/
 * ajax/ai.php — the current source fingerprint is pinned byte-for-byte in the Batch 6
 * inventory (build/source-inventory-batch-6.json, snapshot E48A0A98063529FA19C53E7AD25DDFA2A665C7E4AA795B0E280CADD8841490CA;
 * the source had drifted from the Batch 0 baseline before its migration — documented in the inventory).
 *
 * The only authorized delta (owner decision B3-08, option a):
 * 1) a feature gate in the three endpoints after the nonce and the 401 guard when present,
 *    and before any capability check — is_feature_enabled('ai') === false →
 *    feature_disabled 403 closed failure; the default (enabled) matches the source.
 * 2) the worker gate after the successful claim exclusively (so a non-pending
 *    job is not desecrated): is_feature_enabled('ai') === false → a documented safe failure
 *    hossam_ai_fail_job( $job_id, 'feature_disabled' ) without leaving a job
 *    hanging (contract §7.6: blocking new jobs + a documented contract on pending without hanging) —
 *    and no strategy executes, and the original source has no feature toggles at all.
 *
 * The carried-over contract as-is: a summary of the source header — replacing loopback with internal scheduling,
 * an atomic claim WHERE status=pending with a re-verified user_can(edit_post) before
 * execution, strategy resolution at submit alone (no fallback after starting),
 * UTC times exclusively, triple cost protection (transient/daily/concurrent),
 * and the operational values from a trusted runtime profile that fails safe when absent.
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Allowed job types — any value not listed here is rejected immediately.
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
 * Marks a job with a terminal status under a safe error — the message is a fixed internal text only,
 * no provider details and no secrets. An explicitly prepared UPDATE to control the NULL
 * columns with uniform behavior across wpdb versions.
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

		// R11 (G4 §3-a-14 position 1/2): ai_fail after the UPDATE actually took effect — a
		// rows_affected guard; meta reason pre-sanitized with sanitize_key; the return
		// value is deliberately ignored (G4 §5). Every failure path passes here.
		if ( (int) $wpdb->rows_affected > 0 ) {
			hossam_audit_log( 'ai_fail', [ 'object_id' => $job_id, 'meta' => [ 'reason' => sanitize_key( $safe_reason ) ] ] );
		}
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
		$outstanding = false !== $updated && (int) $updated > 0;

		// R11 (G4 §3-a-14 position 2/2): ai_fail after the conditional UPDATE — with a
		// guard on the returned value true (rows_affected>0); meta reason pre-sanitized
		// with sanitize_key; the return value is deliberately ignored (G4 §5).
		if ( $outstanding ) {
			hossam_audit_log( 'ai_fail', [ 'object_id' => $job_id, 'meta' => [ 'reason' => sanitize_key( $safe_reason ) ] ] );
		}

		return $outstanding;
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
// 1) Submit a new job — user/ownership/limits validation, strategy resolution,
//    saving job + strategy_id, then checking the scheduling result
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

	// Article ownership via a Core resource-level capability (reference line 142 verbatim):
	// no manual post_author comparison — Core decides author, editor and administrator.
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

	// ── Strategy resolved at submit alone; failure here is a safe terminal failure.
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

	// R11 (G4 §3-a-12): ai_submit after the error gate and before the scheduling block —
	// one position covering both success exits (:399/:408) even if scheduling fails;
	// no input_data and no content in meta; the return value is deliberately ignored (G4 §5).
	hossam_audit_log(
		'ai_submit',
		[
			'object_id' => (int) $job_id,
			'meta'      => [
				'job_type'    => $job_type,
				'post_id'     => (int) $post_id,
				'strategy_id' => (string) $strategy,
			],
		]
	);

	// ── Internal scheduling: one hook with its args, no duplication, with a result check.
	//    No loopback, no admin-ajax, no nonce, no cookies for the worker.
	$hook = 'hossam_ai_process_job_event';
	$args = [ $job_id ];

	if ( false !== wp_next_scheduled( $hook, $args ) ) {
		// An event with the same hook and args is already scheduled — no duplication.
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
// 2) The internal worker — a cron hook only. No wp_ajax_hossam_ai_process_job,
//    no cookies, no nonce. The claim is atomic then the capability is re-verified before execution.
//    B6-02: a profile failure with the feature disabled terminates pending/processing
//    (feature_disabled) instead of blind rescheduling.
// ══════════════════════════════════════════════════════════════
add_action( 'hossam_ai_process_job_event', function ( int $job_id ): void {
	global $wpdb;
	$table   = $wpdb->prefix . 'hossam_ai_jobs';
	$profile = hossam_ai_get_runtime_profile();
	if ( is_wp_error( $profile ) ) {
		// B6-02: a profile failure does not reschedule blindly — the disablement
		// terminates the outstanding jobs (pending/processing only; completed or
		// missing ones or a read failure are left untouched with no
		// gratuitous rescheduling), otherwise it reschedules as before.
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
		return; // Claimed by another worker or not pending — nothing executes.
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

	// Re-verifying the capability before execution (reference verbatim): loss of
	// the user's capability on the article between submit and execution fails safely.
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

	// Execution on the strategy_id saved at submit exclusively — no re-resolution
	// in the worker and no fallback to a second provider after actual execution starts.
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

	// R11 (G4 §3-a-13): ai_complete after the completion UPDATE — with a
	// rows_affected>0 guard; meta job_type exclusively (never result); the return
	// value is deliberately ignored (G4 §5).
	if ( (int) $wpdb->rows_affected > 0 ) {
		hossam_audit_log( 'ai_complete', [ 'object_id' => $job_id, 'meta' => [ 'job_type' => (string) $job['job_type'] ] ] );
	}
} );

// ══════════════════════════════════════════════════════════════
// 3) The periodic sweep — hossam_every_five_minutes from core/setup.php.
//    Rescheduling stuck pending jobs within the attempt limit (after checking
//    wp_next_scheduled), and safely failing processing jobs past the deadline
//    with no retry to an alternative provider.
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
		// B6-02: without sweep limits ages cannot be settled — but the disablement terminates
		// the outstanding ones (pending/processing) since no execution is ever possible while
		// submission is blocked (submit/ad adapters reject) and every worker event takes
		// the same fast path; completed/failed are always left alone. The processing ones
		// here are inevitably orphaned: no live executor can complete them under the disablement, and the
		// deadline path is disabled by the absence of the profile.
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

	// (a) processing past the deadline → a safe terminal failure, with no retry.
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

	// (b) stuck pending (missed their event) within the attempt limit → rescheduling
	//     the same hook and same args after a verbatim wp_next_scheduled check.
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
			continue; // No duplicate events — reference verbatim.
		}

		$scheduled = wp_schedule_single_event( time(), $hook, $args );
		if ( false === $scheduled || is_wp_error( $scheduled ) ) {
			continue; // A failed sweep schedule does not mark the job failed — the next cycle's attempt.
		}

		$wpdb->update(
			$table,
			[ 'next_run_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ],
			[ 'id' => $job_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}

	// (c) zombie pending — V110-H2: exhausted attempts (Z1, and preceding on the count)
	//     or orphaned next_run_at IS NULL (Z2 legacy schema 1.1.1) — Final failure with no retry.
	$zombies = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, attempt_count FROM {$table} WHERE status = 'pending' AND ( attempt_count >= %d OR next_run_at IS NULL ) LIMIT 20",
			$sweep['max_attempts']
		),
		ARRAY_A
	);
	foreach ( (array) $zombies as $zombie ) {
		$reason = ( (int) $zombie['attempt_count'] >= $sweep['max_attempts'] )
			? 'attempts_exhausted'
			: 'orphaned_pending';
		hossam_ai_fail_job_if_outstanding( (int) $zombie['id'], $reason );
	}
} );

// ══════════════════════════════════════════════════════════════
// 4) Poll job status — the owner alone; no strategy and no secrets in
//    the response (job_id, the status and the safe result only, as ai.js consumes them).
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

	// Ownership condition inside WHERE — only the job's owner polls its status.
	$job = $wpdb->get_row(
		$wpdb->prepare( "SELECT status, result, last_error FROM {$table} WHERE id = %d AND user_id = %d", $job_id, get_current_user_id() ),
		ARRAY_A
	);

	if ( ! $job ) {
		wp_send_json_error( [ 'message' => 'Job not found' ], 404 );
	}

	// R11-(e) (G3 §1.3-12): consume poll 120/min — an optional read granted per the map gate 15/15 (F-07)
	$hossam_rl = hossam_rate_limit_consume( 'poll', (int) get_current_user_id(), 120, MINUTE_IN_SECONDS );
	if ( is_wp_error( $hossam_rl ) ) {
		$hossam_rl_data = $hossam_rl->get_error_data();
		wp_send_json_error(
			[ 'message' => $hossam_rl->get_error_message() ],
			is_array( $hossam_rl_data ) && isset( $hossam_rl_data['status'] ) ? (int) $hossam_rl_data['status'] : 503
		);
	}

	wp_send_json_success( [
		'status' => $job['status'],
		'result' => $job['result'] ? json_decode( $job['result'], true ) : null,
		'error'  => $job['last_error'],
	] );
} );

// ══════════════════════════════════════════════════════════════
// 5) Preference editing — manage_options exclusively (the clause: a limited and sanitized option).
//    No UI for it outside posts.php in compliance with the constraint «new development inside posts.php only».
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

	// R11 (G4 §3-a-16): ai_preference_set after hossam_ai_set_preference succeeds
	// and before wp_send_json_success — a settings row with no object_id (NULL in
	// the closed list), meta preference (the sanitize_key-sanitized value
	// above); the return value is deliberately ignored (G4 §5).
	hossam_audit_log( 'ai_preference_set', [ 'meta' => [ 'preference' => $preference ] ] );

	wp_send_json_success( [ 'preference' => hossam_ai_get_preference() ] );
} );
