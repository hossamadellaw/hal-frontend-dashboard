<?php
/**
 * runtime/core/rate-limits.php — the central limits unit (v1.1.0 — row R1 — new file)
 * ══════════════════════════════════════════════════════════════
 * Role: the unified write-quota consumption function + the revisions cap + the
 * two upload-hook protections (size cap + MIME intersection + counting) + the fileinfo disclosure.
 *
 * Governing source: G3-RATE-LIMITS-POSITION.md — §1.1 (the eight-point
 * function contract), §1.2 (the lock-qualification recipe), §1.4 (the object
 * cache branch), §2 (the revisions cap), §4 items 1-13 — and
 * G6-IO-POSITION.md §7-a/7-b/7-c and §4 items 1-3.
 * The carried established pattern: runtime/ajax/inbox.php:36-60 (read →
 * check → write inside GET_LOCK with RELEASE in finally).
 *
 * The eight-point contract of hossam_rate_limit_consume (G3 §1.1 verbatim):
 *   1. Consumption after every verification completes (nonce/401/feature/caps/
 *      ownership/input sanitization) and immediately before the actual write —
 *      no rollback (a later write failure wastes one unit ≤1; the ai.php rollback pattern is not generalized).
 *   2. A transient key `hossam_rl_{sanitize_key(scope)}_{user_id}` with
 *      threshold limit and TTL window_seconds — a sliding window (every
 *      write renews the TTL). The daily window: key
 *      `hossam_rl_{scope}_day_{user_id}` with a DAY_IN_SECONDS window —
 *      deliberately a sliding 24 hours, and the difference from ai.php's UTC
 *      calendar day is documented on purpose (G3 §1.1-2 / V3 §3-d).
 *   3. GET_LOCK(qualified name §1.2, 2) before the read; anything other
 *      than 1 ⇒ fail-closed 503 (no write passes); RELEASE_LOCK in finally
 *      on every path (success/429/503/exception).
 *   4. set_transient failure ⇒ fail-closed 503 (no pass-through on breakage).
 *   5. The 429 rejection with the established family verbatim — WP_Error
 *      'rate_limit' with 'Rate limit exceeded. Try later.' and
 *      error_data['status']=429, rendered at the call site (the inbox.php:225-232 pattern). No remaining-quota disclosure.
 *   6. No manage_options exemption — the cap also protects against a compromised session.
 *   7. Never called inside hossam_ai_insert_job_under_quota (GET_LOCK nesting
 *      on one connection) — ai.php is not touched at all in this batch.
 *   8. Position: this file + two require lines in runtime/bootstrap.php after
 *      the core block and before settings/adapters/ajax. The 429/503 values
 *      are contract-fixed — no options and no UI for thresholds (the setup.php philosophy).
 *
 * The two fail-closed 503 messages (T1 matches them verbatim — G3 §1.1):
 *   'quota_lock_failed'  [503] 'Service is busy. Try again.'
 *   'quota_write_failed' [503] 'Service is unavailable. Try again.'
 *   (and the rejection family: 'rate_limit' [429] 'Rate limit exceeded. Try later.')
 *
 * The wp_using_ext_object_cache() branch (G3 §1.4/m3): the lock is skipped —
 * an atomic add(0)→incr path on the 'hossam_rl' group; add failing because
 * the key exists ⇒ incr directly; incr failing ⇒ one add+incr retry; a
 * second failure ⇒ fail-closed 503; the check comes after the increment
 * (reject-after at n > limit) — a bounded overshoot under parallel requests
 * within the window, accepted and documented (V3 n5).
 *
 * ═══ Access declaration (G6 §7-b-a — relayed to the owner in the approval package) ═══
 * The wp_handle_upload_prefilter hook is site-scoped in reach: every upload
 * path passes through it exclusively via wp_handle_upload/_wp_handle_upload
 * (file.php:833 — since 2.9.0): the two AJAX add paths (hossam_upload_file →
 * media_handle_upload in adapters/wordpress-uploads.php:83) + wp-admin/async-upload
 * + REST wp/v2/media (wp_handle_upload inside upload_from_file). That is, the
 * size, MIME and counting protections also apply to the official wp-admin
 * upload itself — a site-scoped reach declared to the owner in the approval
 * package, never passed silently. The MIME intersection array_intersect_assoc
 * never widens the site policy (the wordpress-uploads.php:43 contract
 * preserved verbatim) — and a global permanent upload_mimes filter is
 * forbidden (§b-49).
 *
 * Not from this file: the conditional nonce for the upload surface (row R31
 * — the setup.php file).
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'HOSSAM_UPLOADS_MAX_FILE_SIZE' ) ) {
	// q2 (G6 §7-a): a per-file size cap — 8 MB by default, adjustable with the
	// single filter hossam_uploads_max_file_size (value 0 = disable the cap).
	define( 'HOSSAM_UPLOADS_MAX_FILE_SIZE', 8388608 );
}

if ( ! function_exists( 'hossam_rate_limit_get_lock_name' ) ) {
	/**
	 * Qualify the unified named lock — an exact copy of the ai.php:85-97
	 * recipe (F-04) with a dedicated `hossam_rl_` namespace prefix so it
	 * cannot collide with `hossam_ai_`.
	 * Identity: DB_HOST\0DB_NAME\0prefix\0blog_id\0{scope}:{user_id} —
	 * sha256[:40] (G3 §1.2 verbatim). Database + prefix + site + resource
	 * isolation.
	 *
	 * @param string $scope   The scope key (upload/create/msg/...).
	 * @param int    $user_id The quota-owning user ID.
	 * @return string A GET_LOCK lock name qualified by environment, site and resource.
	 */
	function hossam_rate_limit_get_lock_name( string $scope, int $user_id ): string {
		global $wpdb;

		$database = ( defined( 'DB_HOST' ) ? (string) DB_HOST : '' ) . "\0"
			. ( defined( 'DB_NAME' ) ? (string) DB_NAME : '' );
		$blog_id = function_exists( 'get_current_blog_id' )
			? (int) get_current_blog_id()
			: (int) ( $wpdb->blogid ?? 0 );
		$resource = $scope . ':' . $user_id;
		$identity = $database . "\0" . (string) $wpdb->prefix . "\0" . $blog_id . "\0" . $resource;

		return 'hossam_rl_' . substr( hash( 'sha256', $identity ), 0, 40 );
	}
}

if ( ! function_exists( 'hossam_rate_limit_consume' ) ) {
	/**
	 * Consumes one unit of the {scope} quota for the user under a qualified
	 * named lock — the inbox.php:36-60 contract (read → check → write inside
	 * GET_LOCK + RELEASE in finally) with the ai.php:85-97 recipe
	 * qualification, no rollback (G3 §1.1 — the full eight-point contract in
	 * the file-header documentation).
	 *
	 * @param string $scope          The scope key (sanitized with sanitize_key in the key).
	 * @param int    $user_id        The user ID.
	 * @param int    $limit          The window threshold (the maximum granted).
	 * @param int    $window_seconds The window age in seconds (60/86400 — sliding).
	 * @return true|WP_Error true = granted
	 *   'rate_limit'         [429] 'Rate limit exceeded. Try later.'
	 *   'quota_lock_failed'  [503] 'Service is busy. Try again.'
	 *   'quota_write_failed' [503] 'Service is unavailable. Try again.'
	 */
	function hossam_rate_limit_consume( string $scope, int $user_id, int $limit, int $window_seconds ): true|WP_Error {
		global $wpdb;

		$key = 'hossam_rl_' . sanitize_key( $scope ) . '_' . $user_id;

		// The external object cache branch (G3 §1.4): the lock is skipped —
		// wp_cache_incr is atomic; the check comes after the increment
		// (reject-after). Runtime check on every call, no manual setup.
		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $key, 0, 'hossam_rl', $window_seconds );
			$count = wp_cache_incr( $key, 1, 'hossam_rl' );
			if ( false === $count ) {
				// One add+incr retry; a second failure ⇒ fail-closed 503.
				wp_cache_add( $key, 0, 'hossam_rl', $window_seconds );
				$count = wp_cache_incr( $key, 1, 'hossam_rl' );
				if ( false === $count ) {
					return new WP_Error( 'quota_write_failed', 'Service is unavailable. Try again.', [ 'status' => 503 ] );
				}
			}
			if ( $count > $limit ) {
				return new WP_Error( 'rate_limit', 'Rate limit exceeded. Try later.', [ 'status' => 429 ] );
			}

			return true;
		}

		// The default path (transient): GET_LOCK before the read — a transient
		// is not an atomic read-modify-write (S2/S3); the 2s timeout is a cost
		// bounded to the same user because the lock is qualified by it (V3 n9).
		$lock_name = hossam_rate_limit_get_lock_name( $scope, $user_id );
		$locked    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 2)', $lock_name ) );
		if ( 1 !== $locked ) {
			return new WP_Error( 'quota_lock_failed', 'Service is busy. Try again.', [ 'status' => 503 ] );
		}

		try {
			$cnt = (int) get_transient( $key );
			if ( $cnt >= $limit ) {
				// Rejection without increment — the counter is untouched after rejection (G3 §4-1).
				return new WP_Error( 'rate_limit', 'Rate limit exceeded. Try later.', [ 'status' => 429 ] );
			}

			// The write renews the TTL ⇒ a sliding window (every write renews the age).
			if ( ! set_transient( $key, $cnt + 1, $window_seconds ) ) {
				return new WP_Error( 'quota_write_failed', 'Service is unavailable. Try again.', [ 'status' => 503 ] );
			}

			return true;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}
}

if ( ! function_exists( 'hossam_rate_limit_revisions_to_keep' ) ) {
	/**
	 * q4 (G3 §2/m6): the revisions cap — the core filter `wp_revisions_to_keep`
	 * (revision.php:829 — since 3.6.0) with the value 10, site-wide scope (all
	 * revisions-supporting types; attachments and non-supporting types are
	 * excluded by the core itself = 0).
	 *
	 * The three-tier tuning ladder (the finest above the broadest):
	 *   1. The WP_POST_REVISIONS constant (core — default true = no cap).
	 *   2. The global filter wp_revisions_to_keep (this filter — returns 10).
	 *   3. The per-type filter wp_{$post_type}_revisions_to_keep (since 5.8.0 —
	 *      outranks both — the owner's finest tuning point later).
	 *
	 * The C2 semantics (G3 §4-12): an identical save creates no revision and
	 * deletes nothing (normalize_whitespace against the last revision); the
	 * eleventh changed save deletes the oldest (the revisions count proves
	 * pinned at 10); autosaves are excluded from pruning (revision.php:250-253);
	 * and `preview` is zero writes (the preview reads the draft itself, not
	 * revisions). With a 30-saves/minute cap the cumulative growth becomes
	 * bounded by an absolute ceiling of 10 revisions per article regardless
	 * of the attack.
	 *
	 * @param mixed $num  The value arriving from the finest tier (replaced by 10).
	 * @param mixed $post The post object (unused — the cap is site-scoped).
	 * @return int 10 always.
	 */
	function hossam_rate_limit_revisions_to_keep( $num, $post ): int {
		unset( $num, $post );

		return 10;
	}
}

add_filter( 'wp_revisions_to_keep', 'hossam_rate_limit_revisions_to_keep', 10, 2 );

if ( ! function_exists( 'hossam_rate_limit_human_size' ) ) {
	/**
	 * Human-readable size for the limit-exceeded message (bytes/KB/MB/GB).
	 *
	 * @param int $bytes The size in bytes.
	 * @return string A human-readable size text.
	 */
	function hossam_rate_limit_human_size( int $bytes ): string {
		if ( $bytes >= 1073741824 ) {
			return sprintf( '%.1f GB', $bytes / 1073741824 );
		}
		if ( $bytes >= 1048576 ) {
			return sprintf( '%.1f MB', $bytes / 1048576 );
		}
		if ( $bytes >= 1024 ) {
			return sprintf( '%.1f KB', $bytes / 1024 );
		}

		return sprintf( '%d bytes', $bytes );
	}
}

if ( ! function_exists( 'hossam_rate_limit_upload_prefilter' ) ) {
	/**
	 * The single upload hook — two protections + counting (G6 §7-a/7-b + G3
	 * §1.3 rows 6-7):
	 *   (1) Size cap: the HOSSAM_UPLOADS_MAX_FILE_SIZE constant (8MB) with the
	 *       `hossam_uploads_max_file_size` filter (0 = disable) — an overshoot
	 *       sets $file['error'] with a message carrying the size and the cap,
	 *       and the core aborts before any file write (file.php:867-869).
	 *   (2) MIME intersection: the $file['name'] extension checked via
	 *       wp_check_filetype($file['name'], hossam_get_allowed_upload_mimes())
	 *       with the same semantics as wordpress-uploads.php:69 — rejection
	 *       when ext/type is empty; the array_intersect_assoc intersection
	 *       never widens the site policy, and the deep nuclear verification
	 *       (wp_check_filetype_and_ext with the real content) remains the
	 *       second net.
	 *   (3) Counting: upload 10/min + upload_day 100/day — two
	 *       hossam_rate_limit_consume calls after both checks pass (a file
	 *       rejected by size or type consumes no quota) with no double
	 *       counting: the single official chokepoint enforces the cap no
	 *       matter which upload interface is used.
	 *       A quota rejection sets $file['error'] with the established message
	 *       family verbatim ('Rate limit exceeded. Try later.') with no quota
	 *       disclosure and no manual HTTP status (the core contract is not
	 *       tampered with).
	 *
	 * The site-scoped access declaration (G6 §7-b-a) is in the file-header
	 * documentation — AJAX + wp-admin/async-upload + REST wp/v2/media all
	 * pass through it.
	 *
	 * @param array $file The file array arriving from the core (carries name/size).
	 * @return array The same array with $file['error'] on rejection.
	 */
	function hossam_rate_limit_upload_prefilter( $file ) {
		if ( ! is_array( $file ) ) {
			return $file;
		}

		// (1) Size cap — before any write.
		$max_bytes = (int) apply_filters( 'hossam_uploads_max_file_size', (int) HOSSAM_UPLOADS_MAX_FILE_SIZE );
		if ( $max_bytes > 0 && isset( $file['size'] ) && is_numeric( (string) $file['size'] ) && (int) $file['size'] > $max_bytes ) {
			$file['error'] = sprintf(
				'File "%1$s" is %2$s and exceeds the allowed limit of %3$s — please compress the file or upload a smaller version.',
				(string) ( $file['name'] ?? '' ),
				hossam_rate_limit_human_size( (int) $file['size'] ),
				hossam_rate_limit_human_size( $max_bytes )
			);

			return $file;
		}

		// (2) MIME intersection — the same semantics as wordpress-uploads.php:69 (the intersection never widens).
		if ( isset( $file['name'] ) && is_string( $file['name'] ) && '' !== $file['name'] ) {
			$filetype = wp_check_filetype( $file['name'], hossam_get_allowed_upload_mimes() );
			if ( empty( $filetype['ext'] ) || empty( $filetype['type'] ) ) {
				$file['error'] = 'File type not allowed.';

				return $file;
			}
		}

		// (3) Counting — 10/minute then 100/day (a 24-hour sliding daily key);
		//     no double counting: one call per unit at the official point.
		$user_id = (int) get_current_user_id();

		$minute = hossam_rate_limit_consume( 'upload', $user_id, 10, MINUTE_IN_SECONDS );
		if ( is_wp_error( $minute ) ) {
			$file['error'] = 'Rate limit exceeded. Try later.';

			return $file;
		}

		$daily = hossam_rate_limit_consume( 'upload_day', $user_id, 100, DAY_IN_SECONDS );
		if ( is_wp_error( $daily ) ) {
			$file['error'] = 'Rate limit exceeded. Try later.';

			return $file;
		}

		return $file;
	}
}

add_filter( 'wp_handle_upload_prefilter', 'hossam_rate_limit_upload_prefilter' );

if ( ! function_exists( 'hossam_rate_limit_fileinfo_loaded' ) ) {
	/**
	 * The fileinfo extension check result — per-request memory (the approved
	 * C1 pattern): the first check with no query, and the result is fixed for
	 * the rest of the request (G6 §7-c-1).
	 *
	 * @return bool Whether the extension is loaded.
	 */
	function hossam_rate_limit_fileinfo_loaded(): bool {
		static $loaded = null;
		if ( null === $loaded ) {
			$loaded = extension_loaded( 'fileinfo' );
		}

		return $loaded;
	}
}

if ( ! function_exists( 'hossam_rate_limit_fileinfo_notice' ) ) {
	/**
	 * The fileinfo disclosure (G6 §7-c-2 / V2-12): when the extension is
	 * absent — a permanent admin notice for manage_options only, recommending
	 * enabling the PHP `fileinfo` extension.
	 * No per-user dismiss/hide mechanism and no new option (the notice
	 * disappears by itself once the extension is enabled).
	 * The documented fallback behavior: without fileinfo the deep content check
	 * in wp_check_filetype_and_ext leans on the extension — the stored extension
	 * stays under the allow-list and the deny-list rejection remains (the
	 * uploads documentation R26 details it). No replacement manual inspection
	 * code ever.
	 *
	 * @return void
	 */
	function hossam_rate_limit_fileinfo_notice(): void {
		if ( hossam_rate_limit_fileinfo_loaded() ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>'
			. esc_html( 'The PHP "fileinfo" extension is not enabled on this server — please enable it to support deep content inspection on uploads.' )
			. '</p></div>';
	}
}

add_action( 'admin_notices', 'hossam_rate_limit_fileinfo_notice' );
