<?php
// Release delegation #2 (ESC-3): the guard at the top of the file (batch12 §7 gate — guardLine>70
// measured :95) — the full doc header follows it with no content characters.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * runtime/core/audit-log.php — the audit log and retention module (v1.1.0 — R2 row tagged «merge v1.1.0» — new file)
 * ══════════════════════════════════════════════════════════════
 * Role: the server-side valve — the {$wpdb->prefix}hossam_audit_log table + the
 * unified function hossam_audit_log() + the closed 20-event list + the single
 * listener for the secrets hook + the H1 policy + the four-way retention engine.
 *
 * Governing source: G4-AUDIT-LOG-POSITION.md — the sealed document
 * (sha256 = 21ea8cefdfaeecba5d58ff817f65eb5436c9f8ff35dcccbe9961dc6e73a5fa97,
 * attested with sha256sum before any porting). The verbatim port (K4/K8 E4 correction —
 * contract autonomy): §2 the ten-column block with their types and the five indexes
 * (with the resilient column notes), §3-a the object_type table per event,
 * receiver_id for the message_send meta, the four remaining items of the eight-item
 * hossam_audit_log contract, and the literal §6-1 wording (the value-and-existence check, not
 * the autoload state). The tagged delta from the list: event 20 secret_key_mismatch
 * and the fourth branch in the listener (H7 §2-c-4) + event 19 article_h1_normalized
 * (Q5 resolution — the G2 deep defense).
 *
 * ═══ The eight-item contract of hossam_audit_log( string $event_action, array $context ): bool ═══
 *   1. Write failure is swallowed with error_log and never changes the caller's flow — the
 *      return value is deliberately ignored at every call site (G4 §5).
 *   2. user_id from the session exclusively (get_current_user_id) — zero superglobals
 *      in the file and the whole write path: no $_POST/$_GET/$_REQUEST/$_COOKIE.
 *      The sole documented exception when ip_enabled is on: reading
 *      $_SERVER['REMOTE_ADDR'] exclusively with FILTER_VALIDATE_IP, otherwise NULL —
 *      X-Forwarded-For is refused definitively (a spoofed origin makes the «evidence»
 *      fabricable — Q4-1).
 *   3. Identity snapshots: user_login VARCHAR(60) from the session (stays readable after
 *      the user is deleted); object_title a truncated snapshot with sanitize_text_field +
 *      mb_substr(0,255), unindexed.
 *   4. meta: sanitized JSON with closed keys per event (the events table below) and sanitized
 *      scalar values (and a names array for events 6/15) — with a 64KB cap: exceeding it
 *      drops the whole field with error_log and the event keeps its base in the columns.
 *   5. One prepared INSERT with inline NULL (the ai.php prepared UPDATE pattern
 *      :99-103): the object_id/meta/ip columns are passed as literal NULL in SQL when
 *      absent — NULL means «no object» and a fake zero pollutes the index.
 *   6. created_at explicit UTC (gmdate) — consistent with the gmdate-based cutoff in
 *      the retention engine (Q4-3/acceptance item 9).
 *   7. The kill switch enabled=false ⇒ returns true with no write (Q4-5) — it stops
 *      the log write and the audit_log share in the sweep exclusively, with no unschedule
 *      (the retention of the other three tables is independent of the switch).
 *   8. event_action outside the closed twenty-event list is defensively ignored
 *      (false + error_log) — the list is closed and opens only by owner decision.
 *   Also, object_type derives from the closed table exclusively, not from the call.
 *
 * ═══ Single-writer contract (G4 site Q3-3 / §6-7) ═══
 * hossam_audit_log_db_version = '1.0.0' is written by hossam_dashboard_ensure_table
 * exclusively (the tables.php:196 pattern with no autoload argument — autoloaded as is
 * the state of the three existing versions' options) — zero update_option/add_option on the
 * version option from this file ever. The §6-1 check: value and existence only, not the
 * autoload state which the core path itself determines. The settings option
 * hossam_audit_log_settings is created with add_option(…, false) only at the first
 * actual write and every later write is update_option(…, false) — the false argument
 * present always (the correct C7 fingerprint; the lesson of option.php:1298+940).
 *
 * ═══ The documented safe kill command for the owner (Q4-5 — no UI in v1.1.0) ═══
 *   wp eval 'update_option("hossam_audit_log_settings",["enabled"=>false,"days"=>90,"ip_enabled"=>false],false);'
 * Documented warning: do not use `wp option update` on this option (no autoload
 * argument) — it resets it to 'auto-on' (C7 literally). Stopping does not cancel the schedule
 * (unschedule refused — the cost of the remaining event ≈ zero and re-enabling resumes automatically).
 *
 * ═══ The acceptable tamper boundary (Q4-7 — declared, not hidden) ═══
 * The log is not resistant to an attacker owning the full database (code with DB
 * credentials can UPDATE/DELETE). There is no edit/delete path from the application except the
 * retention cron, no off-site export and no row signing in v1.1.0 — the table is
 * within the DB backups themselves. Any expansion = an owner decision in a later cycle.
 *
 * ═══ The single future integration point (Q4-2) ═══
 * amelia_after_booking_added (notifications.php:128) — the appointments listener
 * is added later from this file exclusively; appointments.php is untouched so constraint 9
 * stays structurally preserved. No new endpoints (zero wp_ajax_/register_rest_route),
 * and no additional schedules beyond the single event hossam_dashboard_retention_sweep_event.
 *
 * ═══ Zombie integration contract (H2 §4-5 — tagged delta) ═══
 * The retention is terminal-only (the ai_jobs share deletes status IN
 * ('completed','failed') by updated_at date only) and terminal production is the
 * state machine's responsibility (the R30 row on runtime/ajax/ai.php). Zero new audit
 * call sites from the zombie: the ai_fail event flows from the two existing
 * choke functions (hossam_ai_fail_job / hossam_ai_fail_job_if_outstanding).
 * The two new meta.reason values attempts_exhausted/orphaned_pending are data
 * in a field with no new columns — they pass sanitize_key (ai.php:116/:146) and the 64KB
 * meta cap here, and are accepted because reason is a key already closed for event 14.
 *
 * Row prohibitions (R2): zero modification of tables.php/appointments.php/secret-store
 * (the listener consumes the secrets hook from the outside with its signature and its
 * four-item payload without any change — G4 §6-11); no IP by default (ip_enabled=false); no unschedule
 * on stop; no export/row signing; the H1 policy: no check before kses (the check
 * runs on the stored payload after wp_kses_post — wp_insert_post_data is applied
 * after sanitize_post, core reference 6.7.1 post.php:4761) nor on post_title
 * nor outside the post type.
 * ══════════════════════════════════════════════════════════════
 */

if ( ! function_exists( 'hossam_audit_log_table_definition' ) ) {
	/**
	 * The audit log table definition — the verbatim byte-by-byte port from G4 §2 (the sealed E4
	 * block): the ten columns with their names, types and order + the five
	 * indexes + the dbDelta rules (KEY not INDEX, no backticks,
	 * `PRIMARY KEY  (id)` with two spaces — the core schema.php traditions) +
	 * the resilient column notes: user_login VARCHAR(60) an identity snapshot
	 * matching wp_users.user_login; object_title VARCHAR(255) a truncated
	 * unindexed snapshot; meta LONGTEXT sanitized JSON in the input_data pattern; ip VARCHAR(45)
	 * fits IPv6 and defaults to NULL; object_id nullable; created_at DATETIME
	 * DEFAULT CURRENT_TIMESTAMP in the three-tables pattern and the actual write
	 * passes explicit UTC. The difference from A4 §1 is literal: action→event_action
	 * (C3-2 — ACTION is reserved in MySQL 8; closing the silent 1064 failure) — nothing else.
	 *
	 * The shape is flattened (without the illustrative 'audit_log' wrapper in the G4 block) because the
	 * literal init call itself passes this function's value directly to
	 * hossam_dashboard_ensure_table — and both shapes are from the sealed document.
	 *
	 * @return array{table_suffix:string, version:string, option:string, columns:string[], indexes:string[], schema:callable(string,string):string}
	 */
	function hossam_audit_log_table_definition(): array {
		return [
			'table_suffix' => 'hossam_audit_log',
			'option'       => 'hossam_audit_log_db_version',
			'version'      => '1.0.0',
			'columns'      => [ 'id', 'user_id', 'user_login', 'event_action', 'object_type', 'object_id', 'object_title', 'meta', 'ip', 'created_at' ],
			'indexes'      => [ 'PRIMARY', 'created_at', 'user_id', 'object_id', 'event_action' ],
			'schema'       => static function ( string $table, string $charset ): string {
				return "CREATE TABLE {$table} (
		id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id       BIGINT UNSIGNED NOT NULL,
		user_login    VARCHAR(60)     NOT NULL DEFAULT '',
		event_action  VARCHAR(64)     NOT NULL,
		object_type   VARCHAR(32)     NOT NULL DEFAULT '',
		object_id     BIGINT UNSIGNED          DEFAULT NULL,
		object_title  VARCHAR(255)    NOT NULL DEFAULT '',
		meta          LONGTEXT                 DEFAULT NULL,
		ip            VARCHAR(45)              DEFAULT NULL,
		created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY created_at (created_at),
		KEY user_id (user_id),
		KEY object_id (object_id),
		KEY event_action (event_action)
	) {$charset};";
			},
		];
	}
}

// The tables.php:200-219 pattern literally (G4 §2 — the literal block): init with a guard on
// the value and existence (no autoload state — the §6-1 wording) and an error_log disclosure.
add_action( 'init', function (): void {
	hossam_dashboard_ensure_table( hossam_audit_log_table_definition() );
	if ( ! get_option( 'hossam_audit_log_db_version' ) ) {
		error_log( 'HAL audit log: table ensure did not complete; init will retry.' ); // disclosure of the silent failure (V4 §5-8) — continues only as long as the state is broken
	}
} );

if ( ! function_exists( 'hossam_audit_log_get_settings' ) ) {
	/**
	 * Reading hossam_audit_log_settings — laziness with a per-request memo (Q4-1):
	 * a request with no audit events = zero queries on the log options after the first read.
	 * The defaults in code: enabled=true, ip_enabled=false, days=90
	 * (with a clamp 1..365 at read time — Q4-3). The default state writes no options row
	 * ever — and that is intentional (Q4-1: the option is created at the first actual write).
	 *
	 * @return array{enabled:bool, ip_enabled:bool, days:int}
	 */
	function hossam_audit_log_get_settings(): array {
		static $memo = null;
		if ( null === $memo ) {
			$raw  = get_option( 'hossam_audit_log_settings', [] );
			$raw  = is_array( $raw ) ? $raw : [];
			$memo = [
				'enabled'    => ! array_key_exists( 'enabled', $raw ) || (bool) $raw['enabled'],
				'ip_enabled' => array_key_exists( 'ip_enabled', $raw ) && (bool) $raw['ip_enabled'],
				'days'       => max( 1, min( 365, (int) ( $raw['days'] ?? 90 ) ) ),
			];
		}

		return $memo;
	}
}

if ( ! function_exists( 'hossam_audit_log_update_settings' ) ) {
	/**
	 * The only settings write path — the correct C7 fingerprint (Q4-1): the first
	 * actual write on an absent option = add_option(…, false) only, and every later write
	 * is update_option(…, false) — the false argument present always (any write without it
	 * resets autoload to 'auto-on' — option.php:1298+940). Equality with
	 * the stored value writes nothing (the B3-02 pattern: update_option returns false in both cases).
	 * No consumer in v1.1.0 (no UI) — the owner/CLI/future-UI path.
	 * Closed keys only: enabled/ip_enabled/days (days with a clamp 1..365).
	 *
	 * @param array $settings Allowed partial values.
	 * @return bool Write success or equality.
	 */
	function hossam_audit_log_update_settings( array $settings ): bool {
		$payload = [
			'enabled'    => array_key_exists( 'enabled', $settings ) ? (bool) $settings['enabled'] : true,
			'ip_enabled' => array_key_exists( 'ip_enabled', $settings ) ? (bool) $settings['ip_enabled'] : false,
			'days'       => max( 1, min( 365, (int) ( $settings['days'] ?? 90 ) ) ),
		];

		$current = get_option( 'hossam_audit_log_settings' );
		if ( is_array( $current ) && $current === $payload ) {
			return true;
		}

		if ( ! is_array( $current ) ) {
			// First actual write — the false argument present always (the settings-writer contract).
			return (bool) add_option( 'hossam_audit_log_settings', $payload, false );
		}

		return (bool) update_option( 'hossam_audit_log_settings', $payload, false );
	}
}

if ( ! function_exists( 'hossam_audit_log_events' ) ) {
	/**
	 * The closed 20-event list — the table ported from G4 §3-a (events 1-18
	 * verbatim) + the tagged delta (rows 19/20 from the v1.1.0 list, row R2-c).
	 * Each row's structure: the event's fixed object_type + the closed meta keys.
	 * Successes only — the 401/403/404/500 paths are zero rows; read-state excluded
	 * for notifications (noise with no forensic value); appointments excluded (Q4-2).
	 *
	 * | #  | event_action           | object_type | object_id               | meta (closed keys)                        | Call site (G4 §3-a — phase 2)                                            |
	 * |----|------------------------|-------------|-------------------------|-------------------------------------------|--------------------------------------------------------------------------|
	 * | 1  | article_create         | article     | the new article's id    | status, lang                              | posts.php:347 before wp_send_json_success — delta R8: lang               |
	 * | 2  | article_update         | article     | the article's id        | status                                    | posts.php:403 before wp_send_json_success                                |
	 * | 3  | article_trash          | article     | the article's id        | —                                         | posts.php:433 before wp_send_json_success                                |
	 * | 4  | article_restore        | article     | the article's id        | —                                         | posts.php:131 before wp_send_json_success                                |
	 * | 5  | article_translate      | article     | the source article's id | new_post_id, lang                         | posts.php:287 the new-creation branch exclusively                        |
	 * | 6  | article_save_seo       | article     | the article's id        | fields (field names, not values)          | seo.php:92 before wp_send_json_success                                   |
	 * | 7  | file_upload            | file        | the attachment's id     | mime                                      | uploads.php:154 before wp_send_json_success                              |
	 * | 8  | file_delete            | file        | the attachment's id     | mime (captured before deletion)           | uploads.php:133 (forced delete :129)                                     |
	 * | 9  | file_restore           | file        | the attachment's id     | —                                         | uploads.php:108 after a successful wp_untrash_post                       |
	 * | 10 | message_send           | message     | the inserted message's id | receiver_id (no message text ever)      | inbox.php:253 (the insert :238 — sending is admin-only)                  |
	 * | 11 | message_mark_read      | message     | the message's id        | —                                         | inbox.php:339 before wp_send_json_success                                |
	 * | 12 | ai_submit              | ai_job      | the job's id            | job_type, post_id, strategy_id            | ai.php after the error gate :384-390 and before scheduling :392-397      |
	 * | 13 | ai_complete            | ai_job      | the job's id            | job_type (no result)                      | ai.php after the completion UPDATE :513-526 with a rows_affected guard   |
	 * | 14 | ai_fail                | ai_job      | the job's id            | reason (sanitize_key beforehand :116/:146) | two sites: inside hossam_ai_fail_job (:121) and …_if_outstanding (:152) |
	 * | 15 | settings_save          | settings    | NULL                    | keys (the written key names, not values)  | class-admin-controller.php:219 after save() succeeds with a guard        |
	 * | 16 | ai_preference_set      | settings    | NULL                    | preference                                | ai.php:674 before wp_send_json_success                                   |
	 * | 17 | secret_set             | secret      | NULL                    | secret_id                                 | the shared listener below (hook class-secret-store.php:313)              |
	 * | 18 | secret_delete          | secret      | NULL                    | secret_id                                 | the shared listener below                                                |
	 * | 19 | article_h1_normalized  | article     | the article's id (NULL when new) | count                            | the wp_insert_post_data handler below (Q5 resolution — the delta)        |
	 * | 20 | secret_key_mismatch    | secret      | NULL                    | secret_id                                 | the 'key_mismatch' branch in the listener (H7 §2-c-4 — the delta)        |
	 *
	 * Rows 19/20: 19 has the same structure as the article events; 20 has the same structure as rows 17/18
	 * (object_type='secret', object_id=NULL, meta secret_id) literally.
	 *
	 * @return array<string, array{object_type:string, meta_keys:string[]}>
	 */
	function hossam_audit_log_events(): array {
		return [
			// [delta R8 tag — v1.1.0]: meta adds lang alongside status (the contract :148).
			'article_create'       => [ 'object_type' => 'article', 'meta_keys' => [ 'status', 'lang' ] ],
			'article_update'       => [ 'object_type' => 'article', 'meta_keys' => [ 'status' ] ],
			'article_trash'        => [ 'object_type' => 'article', 'meta_keys' => [] ],
			'article_restore'      => [ 'object_type' => 'article', 'meta_keys' => [] ],
			'article_translate'    => [ 'object_type' => 'article', 'meta_keys' => [ 'new_post_id', 'lang' ] ],
			'article_save_seo'     => [ 'object_type' => 'article', 'meta_keys' => [ 'fields' ] ],
			'file_upload'          => [ 'object_type' => 'file', 'meta_keys' => [ 'mime' ] ],
			'file_delete'          => [ 'object_type' => 'file', 'meta_keys' => [ 'mime' ] ],
			'file_restore'         => [ 'object_type' => 'file', 'meta_keys' => [] ],
			'message_send'         => [ 'object_type' => 'message', 'meta_keys' => [ 'receiver_id' ] ],
			'message_mark_read'    => [ 'object_type' => 'message', 'meta_keys' => [] ],
			'ai_submit'            => [ 'object_type' => 'ai_job', 'meta_keys' => [ 'job_type', 'post_id', 'strategy_id' ] ],
			'ai_complete'          => [ 'object_type' => 'ai_job', 'meta_keys' => [ 'job_type' ] ],
			'ai_fail'              => [ 'object_type' => 'ai_job', 'meta_keys' => [ 'reason' ] ],
			'settings_save'        => [ 'object_type' => 'settings', 'meta_keys' => [ 'keys' ] ],
			'ai_preference_set'    => [ 'object_type' => 'settings', 'meta_keys' => [ 'preference' ] ],
			'secret_set'           => [ 'object_type' => 'secret', 'meta_keys' => [ 'secret_id' ] ],
			'secret_delete'        => [ 'object_type' => 'secret', 'meta_keys' => [ 'secret_id' ] ],
			'article_h1_normalized' => [ 'object_type' => 'article', 'meta_keys' => [ 'count' ] ],
			'secret_key_mismatch'  => [ 'object_type' => 'secret', 'meta_keys' => [ 'secret_id' ] ],
		];
	}
}

if ( ! function_exists( 'hossam_audit_log_sanitize_meta_value' ) ) {
	/**
	 * Sanitizing a meta value — sanitized scalar values (the G4 §3-a constant): int/float/bool
	 * as is, string via sanitize_text_field, and a names array (for events
	 * 6/15: fields/keys — field/key names exclusively, not values) as sanitized
	 * string/int elements; any other type is dropped defensively (null).
	 *
	 * @param mixed $value The raw value.
	 * @return mixed A sanitized scalar or a names array or null on drop.
	 */
	function hossam_audit_log_sanitize_meta_value( $value ) {
		if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			return sanitize_text_field( $value );
		}
		if ( is_array( $value ) ) {
			$items = [];
			foreach ( $value as $item ) {
				if ( is_string( $item ) && '' !== $item ) {
					$items[] = sanitize_text_field( $item );
				} elseif ( is_int( $item ) ) {
					$items[] = $item;
				}
			}

			return [] === $items ? null : $items;
		}

		return null;
	}
}

if ( ! function_exists( 'hossam_audit_log' ) ) {
	/**
	 * The unified function for writing one audit row — the eight-item contract documented in the
	 * file header (the G4 §3-a constant + Q4-1/Q4-5). The call-site level: after the operation succeeds
	 * (or after an UPDATE that actually affected rows for events 13/14 with a rows_affected guard) and before
	 * wp_send_json_success/redirect — and the return value is deliberately ignored and
	 * no call changes the response path (G4 §5).
	 *
	 * The call context (open keys are accepted, and the foreign ones are ignored):
	 *   object_id    => int|null (0/negative/absent ⇒ NULL — a fake zero pollutes the index)
	 *   object_title => string a truncated snapshot (sanitize + mb_substr 255)
	 *   meta         => array sanitized on the event's closed keys exclusively
	 *
	 * @param string $event_action The event name from the closed twenty-event list.
	 * @param array  $context       An optional call context.
	 * @return bool Write success (or true when stopped — with no write).
	 */
	function hossam_audit_log( string $event_action, array $context = [] ): bool {
		global $wpdb;

		// (7) The kill switch: enabled=false ⇒ no write — true untouched (Q4-5).
		$settings = hossam_audit_log_get_settings();
		if ( ! $settings['enabled'] ) {
			return true;
		}

		$events = hossam_audit_log_events();
		if ( ! isset( $events[ $event_action ] ) ) {
			// (8) Outside the closed list — defensive ignore (the rest of the list stays intact).
			error_log( 'HAL audit log: event_action outside the closed list ignored: ' . $event_action );

			return false;
		}
		$event = $events[ $event_action ];

		// (2) Identity from the session exclusively — zero superglobals in the whole path.
		$user_id    = (int) get_current_user_id();
		$user       = $user_id > 0 ? get_userdata( $user_id ) : false;
		$user_login = ( $user && ! empty( $user->user_login ) )
			? mb_substr( sanitize_text_field( (string) $user->user_login ), 0, 60 )
			: '';

		$object_id = null;
		if ( isset( $context['object_id'] ) && is_numeric( (string) $context['object_id'] ) && (int) $context['object_id'] > 0 ) {
			$object_id = (int) $context['object_id'];
		}

		$object_title = isset( $context['object_title'] ) && is_string( $context['object_title'] )
			? mb_substr( sanitize_text_field( $context['object_title'] ), 0, 255 )
			: '';

		// (4) meta: closed keys + sanitized values + JSON + the 64KB cap.
		$meta_raw = isset( $context['meta'] ) && is_array( $context['meta'] ) ? $context['meta'] : [];
		$allowed  = array_fill_keys( $event['meta_keys'], true );
		$meta     = [];
		foreach ( $meta_raw as $meta_key => $meta_value ) {
			if ( ! isset( $allowed[ (string) $meta_key ] ) ) {
				continue;
			}
			$clean = hossam_audit_log_sanitize_meta_value( $meta_value );
			if ( null !== $clean ) {
				$meta[ (string) $meta_key ] = $clean;
			}
		}
		$meta_json = [] === $meta ? null : wp_json_encode( $meta );
		if ( is_string( $meta_json ) && strlen( $meta_json ) > 65536 ) {
			error_log( 'HAL audit log: meta exceeded the 64KB cap; meta dropped for ' . $event_action . '.' );
			$meta_json = null;
		}

		// (2/ip exception): NULL by default; when enabled REMOTE_ADDR exclusively with
		// FILTER_VALIDATE_IP — X-Forwarded-For refused definitively (Q4-1).
		$ip = null;
		if ( $settings['ip_enabled'] ) {
			$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
			$valid  = filter_var( wp_unslash( $remote ), FILTER_VALIDATE_IP );
			$ip     = false === $valid ? null : (string) $valid;
		}

		// (5) One prepared INSERT with inline NULL — the NULL columns pass literally in SQL
		//     (the ai.php prepared UPDATE pattern :99-103 — uniform behavior across wpdb versions).
		$placeholders = '( %d, %s, %s, %s, '
			. ( null === $object_id ? 'NULL' : '%d' ) . ', %s, '
			. ( null === $meta_json ? 'NULL' : '%s' ) . ', '
			. ( null === $ip ? 'NULL' : '%s' ) . ', %s )';
		$args = [ $user_id, $user_login, $event_action, $event['object_type'] ];
		if ( null !== $object_id ) {
			$args[] = $object_id;
		}
		$args[] = $object_title;
		if ( null !== $meta_json ) {
			$args[] = $meta_json;
		}
		if ( null !== $ip ) {
			$args[] = $ip;
		}
		// (6) Explicit UTC — independent of the site's timezone and consistent with the gmdate cutoff.
		$args[] = gmdate( 'Y-m-d H:i:s' );

		$table = $wpdb->prefix . 'hossam_audit_log';
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table}
					( user_id, user_login, event_action, object_type, object_id, object_title, meta, ip, created_at )
				VALUES {$placeholders}",
				$args
			)
		);

		// (1) Write failure is swallowed with error_log and never changes the caller's flow at all.
		if ( '' !== (string) $wpdb->last_error ) {
			error_log( 'HAL audit log: insert failed for ' . $event_action . '.' );

			return false;
		}

		return true;
	}
}

if ( ! function_exists( 'hossam_audit_log_on_secret_audit' ) ) {
	/**
	 * The single listener for the secrets hook hal_frontend_dashboard_secret_audit —
	 * with its signature and its four-item payload (action/secret_id/actor/time) without any change
	 * (G4 §6-11 — class-secret-store.php:313 consumed from the outside exclusively).
	 * The branching: 'set'→secret_set, 'delete'→secret_delete,
	 * 'key_mismatch'→secret_key_mismatch (the fourth branch — the delta tag H7
	 * §2-c-4), and any other value is defensively ignored (the rest — also the
	 * 'get_failed' value documented in the store's docblock and never dispatched). actor/time
	 * in the payload are consumed by the log from the session and gmdate themselves — they are not carried over.
	 * No second listener and no public hook (the R2 prohibitions).
	 *
	 * @param mixed $entry The four-item hook payload from the store.
	 * @return void
	 */
	function hossam_audit_log_on_secret_audit( $entry ): void {
		if ( ! is_array( $entry ) ) {
			return;
		}

		$action    = isset( $entry['action'] ) && is_string( $entry['action'] ) ? $entry['action'] : '';
		$secret_id = isset( $entry['secret_id'] ) && is_string( $entry['secret_id'] ) ? $entry['secret_id'] : '';

		$map = [
			'set'          => 'secret_set',
			'delete'       => 'secret_delete',
			'key_mismatch' => 'secret_key_mismatch',
		];
		if ( ! isset( $map[ $action ] ) ) {
			return;
		}

		hossam_audit_log(
			$map[ $action ],
			[
				'object_id' => null,
				'meta'      => [ 'secret_id' => $secret_id ],
			]
		);
	}
}

add_action( 'hal_frontend_dashboard_secret_audit', 'hossam_audit_log_on_secret_audit' );

if ( ! function_exists( 'hossam_article_h1_policy' ) ) {
	/**
	 * The H1 policy (q1 — the default is normalize: the valve works always except off).
	 * The closed values: normalize | reject | off — any other filter value returns
	 * to the default normalize (not-off means active). The owner's single tuning point:
	 * add_filter( 'hossam_article_h1_policy', fn() => 'reject' | 'off' ).
	 *
	 * @return string normalize|reject|off.
	 */
	function hossam_article_h1_policy(): string {
		$policy = sanitize_key( (string) apply_filters( 'hossam_article_h1_policy', 'normalize' ) );

		return in_array( $policy, [ 'normalize', 'reject', 'off' ], true ) ? $policy : 'normalize';
	}
}

if ( ! function_exists( 'hossam_audit_log_content_has_h1' ) ) {
	/**
	 * An h1 tag detector in content — the check on the stored payload after wp_kses_post
	 * exclusively (h1 is within the core allowlist so it passes kses — G6 §3-a-2). It matches
	 * <h1> and <h1 > and <h1/> and <h1 attr> in any letter case, and does not match h10/h12.
	 *
	 * @param string $html Stored HTML content.
	 * @return bool Whether it contains an opening h1 tag.
	 */
	function hossam_audit_log_content_has_h1( string $html ): bool {
		return (bool) preg_match( '/<h1[\s\/>]/i', $html );
	}
}

if ( ! function_exists( 'hossam_article_h1_should_reject' ) ) {
	/**
	 * The clean rejection check function (called later from the R14 row —
	 * runtime/adapters/wordpress-posts.php): true only when the policy
	 * is 'reject' and the content contains an h1 — the point the caller turns into a clean 400
	 * rejection in the save path. The normalize/off policies reject nothing here.
	 * The check on the post_content stored after kses — not before kses, not on
	 * post_title, and not outside the post type (the caller guarantees the type).
	 *
	 * @param string $post_content The article's stored content (after kses).
	 * @return bool Whether the content is rejected by the reject policy.
	 */
	function hossam_article_h1_should_reject( string $post_content ): bool {
		if ( 'reject' !== hossam_article_h1_policy() ) {
			return false;
		}

		return hossam_audit_log_content_has_h1( $post_content );
	}
}

if ( ! function_exists( 'hossam_audit_log_normalize_h1_post_data' ) ) {
	/**
	 * The H1 policy handler on wp_insert_post_data (the G2 deep defense — the Q5 resolution /
	 * event 19): converts <h1>→<h2> in post_content for the post type exclusively when
	 * the policy is normalize and logs article_h1_normalized for every conversion (one row
	 * per save that converted, meta.count = the number of converted h1 tags). The check after kses:
	 * the filter is applied after sanitize_post('db') which passes wp_kses_post
	 * (core reference 6.7.1 post.php:4761) — so no check before kses nor on
	 * post_title nor outside the post type (the copies/autosaves are of type revision —
	 * excluded by the type itself). off/reject: no conversion and no logging from here
	 * (reject is executed from the R14 point via hossam_article_h1_should_reject).
	 *
	 * The filter data are slashed by the core contract — the replacement does not touch
	 * backslashes so the slashing stays consistent; the title snapshot is unslashed before sanitizing.
	 *
	 * @param array $data    The article's slashed data just before writing.
	 * @param array $postarr The raw incoming array.
	 * @return array The data after normalization or as is.
	 */
	function hossam_audit_log_normalize_h1_post_data( array $data, array $postarr ) {
		unset( $postarr );

		if ( ! isset( $data['post_type'] ) || 'post' !== $data['post_type'] ) {
			return $data;
		}
		if ( ! isset( $data['post_content'] ) || ! is_string( $data['post_content'] ) || '' === $data['post_content'] ) {
			return $data;
		}

		$policy = hossam_article_h1_policy();
		if ( 'normalize' !== $policy ) {
			return $data;
		}

		$count = (int) preg_match_all( '/<h1[\s\/>]/i', $data['post_content'] );
		if ( $count < 1 ) {
			return $data;
		}

		$content = preg_replace( '/<h1([\s\/>])/i', '<h2$1', $data['post_content'] );
		$content = is_string( $content ) ? preg_replace( '/<\/h1\s*>/i', '</h2>', $content ) : null;
		if ( ! is_string( $content ) ) {
			// preg failure — no touching of the content (a silent fail-safe).
			return $data;
		}

		$data['post_content'] = $content;

		hossam_audit_log(
			'article_h1_normalized',
			[
				'object_id'    => isset( $data['ID'] ) && (int) $data['ID'] > 0 ? (int) $data['ID'] : null,
				'object_title' => isset( $data['post_title'] ) && is_string( $data['post_title'] )
					? sanitize_text_field( wp_unslash( $data['post_title'] ) )
					: '',
				'meta'         => [ 'count' => $count ],
			]
		);

		return $data;
	}
}

add_filter( 'wp_insert_post_data', 'hossam_audit_log_normalize_h1_post_data', 10, 2 );

if ( ! function_exists( 'hossam_audit_log_retention_definitions' ) ) {
	/**
	 * The retention engine's definitions registry — four tables (Q4-8, the sanctioned merge with the
	 * q3 values: 90/30/90/180): [key, table_suffix, date_column, days, batch].
	 * The ai_jobs days are computed on updated_at and terminal-only
	 * (status IN ('completed','failed')) — the Zombie integration contract in the file
	 * header. The filters per table (criterion 1 — swappable with a filter line):
	 *   hossam_retention_days_audit_log | _ai_jobs | _notifications | _messages
	 *   hossam_retention_batch_audit_log | _ai_jobs | _notifications | _messages
	 * Adding/removing a table in the future = a line in this registry (G4 Q4-8).
	 *
	 * @return array<int, array{key:string, table_suffix:string, date_column:string, days:int, batch:int, terminal_only:bool}>
	 */
	function hossam_audit_log_retention_definitions(): array {
		return [
			[
				'key'           => 'audit_log',
				'table_suffix'  => 'hossam_audit_log',
				'date_column'   => 'created_at',
				'days'          => 90,
				'batch'         => 500,
				'terminal_only' => false,
			],
			[
				'key'           => 'ai_jobs',
				'table_suffix'  => 'hossam_ai_jobs',
				'date_column'   => 'updated_at',
				'days'          => 30,
				'batch'         => 500,
				'terminal_only' => true,
			],
			[
				'key'           => 'notifications',
				'table_suffix'  => 'hossam_notifications',
				'date_column'   => 'created_at',
				'days'          => 90,
				'batch'         => 500,
				'terminal_only' => false,
			],
			[
				'key'           => 'messages',
				'table_suffix'  => 'hossam_messages',
				'date_column'   => 'created_at',
				'days'          => 180,
				'batch'         => 500,
				'terminal_only' => false,
			],
		];
	}
}

if ( ! function_exists( 'hossam_audit_log_retention_sweep_table' ) ) {
	/**
	 * One table's share inside the daily sweep — a date-indexed DELETE in batches
	 * ORDER BY id LIMIT {batch} up to 20 batches (a cap of 20×batch rows/run —
	 * Q4-4), and the surplus is compensated automatically in the next cycle (a historical
	 * cutoff so the operation is idempotent and tolerates missed wp-cron runs). Zero user input in
	 * any DELETE: the cutoff via gmdate from the tuned days value, and batch/days from
	 * the definitions registry and the declared filters (with a days clamp 1..365), and the
	 * terminal-only condition is a literal constant written out — nothing from the request at all (an internal cron).
	 *
	 * The kill switch: enabled=false stops the audit_log share exclusively (Q4-5) —
	 * no unschedule; the other three tables' shares are independent of the switch.
	 * Failure isolation: a DELETE failure ⇒ error_log + return — the next cycle retries
	 * and damages no possible data; one table's failure does not stop the rest of the shares (isolation at
	 * the caller).
	 *
	 * @param wpdb  $wpdb            The database object (no type-hint —
	 *                               the existing code's behavior is all global $wpdb).
	 * @param array $def             The table definition from the definitions registry.
	 * @param bool  $audit_enabled   The current value of the enabled switch.
	 * @return int The number of rows deleted from this table.
	 */
	function hossam_audit_log_retention_sweep_table( $wpdb, array $def, bool $audit_enabled ): int {
		if ( 'audit_log' === $def['key'] && ! $audit_enabled ) {
			return 0;
		}

		$days  = max( 1, min( 365, (int) apply_filters( 'hossam_retention_days_' . $def['key'], (int) $def['days'] ) ) );
		$batch = max( 1, min( 5000, (int) apply_filters( 'hossam_retention_batch_' . $def['key'], (int) $def['batch'] ) ) );
		$table = $wpdb->prefix . $def['table_suffix'];

		$where = $def['date_column'] . ' < %s';
		if ( ! empty( $def['terminal_only'] ) ) {
			// terminal-only — written verbatim (the Zombie integration contract:
			// retention is terminal-only and terminal production is the state
			// machine's responsibility).
			$where .= " AND status IN ('completed','failed')";
		}

		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$deleted = 0;

		for ( $i = 0; $i < 20; $i++ ) {
			$result = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE {$where} ORDER BY id LIMIT %d",
					$cutoff,
					$batch
				)
			);
			if ( false === $result ) {
				error_log( 'HAL retention sweep: DELETE failed on ' . $def['key'] . '.' );

				return $deleted;
			}
			$deleted += (int) $result;
			if ( (int) $result < $batch ) {
				break;
			}
		}

		return $deleted;
	}
}

if ( ! function_exists( 'hossam_audit_log_retention_sweep' ) ) {
	/**
	 * The daily sweep — the single event hossam_dashboard_retention_sweep_event
	 * runs over the definitions registry with one schedule and one guard (Q4-8).
	 * Per table: isolated execution — a table failure (including an unexpected
	 * exception) is logged and never stops the remaining quotas (per-table
	 * failure isolation). Deadlines are settled in UTC with gmdate internally.
	 *
	 * @return void
	 */
	function hossam_audit_log_retention_sweep(): void {
		global $wpdb;

		$settings = hossam_audit_log_get_settings();
		$enabled  = (bool) $settings['enabled'];

		foreach ( hossam_audit_log_retention_definitions() as $def ) {
			try {
				hossam_audit_log_retention_sweep_table( $wpdb, $def, $enabled );
			} catch ( Throwable $sweep_exception ) {
				error_log( 'HAL retention sweep: table ' . $def['key'] . ' failed: ' . $sweep_exception->getMessage() );
			}
		}
	}
}

add_action( 'hossam_dashboard_retention_sweep_event', 'hossam_audit_log_retention_sweep' );

// Self-scheduling with an idempotency guard — the proven verbatim pattern
// ai.php:535-540 (Q4-4): init with a wp_next_scheduled guard, a daily
// interval with no new cron_schedules. Removing the event on deactivation =
// one register_deactivation_hook in the carrier hal-frontend-dashboard.php
// (row R4) — no unschedule from here.
add_action( 'init', function (): void {
	if ( wp_next_scheduled( 'hossam_dashboard_retention_sweep_event' ) ) {
		return;
	}
	wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'hossam_dashboard_retention_sweep_event' );
} );
