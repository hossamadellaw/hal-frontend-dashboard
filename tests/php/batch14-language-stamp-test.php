<?php
/**
 * HAL Frontend Dashboard — Batch 14 harness T8: post language stamp (H4 §1 / R34+R14).
 *
 * Local, isolated harness: no live WordPress, no network, no database, no
 * browser. Pattern: batch9-assets-test.php (isolated PHP CLI with WP boundary
 * stubs) + the approved endpoint-probe pattern (a1-probe-endpoints.php).
 * Loads the REAL units — wpml.php, wordpress-posts.php, rate-limits.php,
 * audit-log.php, class-settings-repository.php, ajax/posts.php — and invokes
 * the REAL create endpoint through its registered hook callback. Only the
 * WordPress boundary and the OFFICIAL WPML filters are stubbed; no project
 * logic is reimplemented in the harness.
 *
 * Coverage (H4-LANGUAGE-WPML-DESIGN.md §1):
 *
 *   A1     WPML active + member language (wpml_current_language='fr') →
 *          the stamp fires with the MEMBER language through the official
 *          wpml_set_element_language_details action (fresh trid>0, source '')
 *          and the read-back returns 'fr'.
 *   A2     categories resolve in the ARTICLE language; a term without a
 *          target translation falls back to the explicit default-language
 *          request (fallback intact) — the resolved list (never the raw IDs)
 *          reaches wp_set_post_categories.
 *   EMPTY  member language empty → the site DEFAULT language is stamped.
 *   A3     WPML absent (self-child process: ICL_SITEPRESS_VERSION undefined)
 *          → zero stamp calls, zero log lines, zero official-filter calls,
 *          audit lang='' and the create success byte-equal to the no-WPML
 *          behavior ('language' => '').
 *   A4     stamp verify failure → the creation SUCCEEDS; exactly one
 *          error_log line carrying the post id and the expected language;
 *          NO draft deletion; audit fires with lang as ACTUALLY read
 *          (may be ''); 'language' in the success carries the actual read.
 *   GUARDS hossam_wpml_stamp_post_language: post_id<1 / '' language /
 *          missing trid — all false with the link action NEVER fired and
 *          trid=0 never passed (no trid forking).
 *
 * Usage:  php -d extension=sodium tests/php/batch14-language-stamp-test.php  (exit 0 = all pass)
 *
 * The no-WPML scenario runs as a self-child of this very file
 * (--b14t8-child=no_wpml) because ICL_SITEPRESS_VERSION is a constant: the
 * child process is a fresh request. No extra files are created.
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

if ( PHP_SAPI !== 'cli' ) {
	echo "This harness must run under CLI PHP.\n";
	exit( 1 );
}

$GLOBALS['B14_PROJECT'] = dirname( __DIR__, 2 );
$GLOBALS['B14_ERRLOG']  = ( getenv( 'TMP' ) ?: sys_get_temp_dir() ) . '/b14-lang-error.log';

$is_no_wpml_child = isset( $argv[1] ) && '--b14t8-child=no_wpml' === $argv[1];

/* ════════════════════════════════════════════════════════════════
 * WordPress boundary stubs (WordPress only — never project logic)
 * ════════════════════════════════════════════════════════════════ */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $GLOBALS['B14_PROJECT'] . '/runtime/fake-wp-root/' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DB_HOST' ) ) {
	define( 'DB_HOST', 'db.test:3306' );
}
if ( ! defined( 'DB_NAME' ) ) {
	define( 'DB_NAME', 'hal_b14_db' );
}

/* WPML official-interface presence: defined ONLY outside the no-WPML child. */
if ( ! $is_no_wpml_child && ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
	define( 'ICL_SITEPRESS_VERSION', '4.6-unit' );
}

/* ── Hook registry (priority-ordered, dispatching) ── */
$GLOBALS['B14_HOOKS']    = array();
$GLOBALS['B14_HOOK_SEQ'] = 0;

function b14_hook_register( string $type, string $hook, callable $cb, int $priority, int $args ): void {
	$GLOBALS['B14_HOOKS'][ $type ][ $hook ][] = array(
		'p'   => $priority,
		'seq' => $GLOBALS['B14_HOOK_SEQ']++,
		'cb'  => $cb,
		'a'   => $args,
	);
}
function add_action( string $hook, callable $cb, int $priority = 10, int $accepted_args = 1 ): void {
	b14_hook_register( 'action', $hook, $cb, $priority, $accepted_args );
}
function add_filter( string $hook, callable $cb, int $priority = 10, int $accepted_args = 1 ): void {
	b14_hook_register( 'filter', $hook, $cb, $priority, $accepted_args );
}
function b14_hook_sorted( string $type, string $hook ): array {
	$entries = array_values( (array) ( $GLOBALS['B14_HOOKS'][ $type ][ $hook ] ?? array() ) );
	usort( $entries, static fn( $x, $y ) => array( $x['p'], $x['seq'] ) <=> array( $y['p'], $y['seq'] ) );
	return $entries;
}
function has_filter( string $hook ): bool {
	return ! empty( $GLOBALS['B14_HOOKS']['filter'][ $hook ] );
}
function has_action( string $hook ): bool {
	return ! empty( $GLOBALS['B14_HOOKS']['action'][ $hook ] );
}
function apply_filters( string $hook, $value, ...$rest ) {
	foreach ( b14_hook_sorted( 'filter', $hook ) as $entry ) {
		$call_args = array_merge( array( $value ), $rest );
		$value     = call_user_func_array( $entry['cb'], array_slice( $call_args, 0, max( 1, $entry['a'] ) ) );
	}
	return $value;
}
function do_action( string $hook, ...$args ): void {
	foreach ( b14_hook_sorted( 'action', $hook ) as $entry ) {
		call_user_func_array( $entry['cb'], array_slice( $args, 0, max( 0, $entry['a'] ) ) );
	}
}

/* ── Probe exceptions (AJAX boundary) ── */
class HalT8Json extends Exception {
	public function __construct( public bool $success, public array $payload, public int $status ) {
		parent::__construct( 'JSON' );
	}
}
class HalT8Die extends Exception {
	public function __construct( public $code_or_message, public int $status ) {
		parent::__construct( 'DIE' );
	}
}
$GLOBALS['B14_NONCE_VALID'] = false;
function check_ajax_referer( $action = -1, $query_arg = false, bool $stop = true ) {
	if ( $stop && ! $GLOBALS['B14_NONCE_VALID'] ) {
		throw new HalT8Die( -1, 403 );
	}
	return $GLOBALS['B14_NONCE_VALID'] ? 1 : false;
}
function wp_send_json_error( $payload = null, int $status = 400 ): void {
	throw new HalT8Json( false, is_array( $payload ) ? $payload : array( 'data' => $payload ), $status );
}
function wp_send_json_success( $data = null ): void {
	throw new HalT8Json( true, is_array( $data ) ? $data : array( 'data' => $data ), 200 );
}

/* ── Errors / sanitizers / misc ── */
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public string $code = '';
		public string $message = '';
		public $error_data = null;
		public function __construct( string $code = '', string $message = '', $data = null ) {
			$this->code       = $code;
			$this->message    = $message;
			$this->error_data = $data;
		}
		public function get_error_code(): string {
			return $this->code;
		}
		public function get_error_message(): string {
			return $this->message;
		}
		public function get_error_data( $code = '' ) {
			return $this->error_data;
		}
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ): string {
		return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $str ) ) );
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $str ): string {
		return trim( strip_tags( (string) $str ) );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0 ) {
		return json_encode( $value, $flags );
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $s, ?string $domain = null ): string {
		return $s;
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $v ): int {
		return abs( (int) $v );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) {
		return $s;
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s ): string {
		return trim( strip_tags( (string) $s ) );
	}
}
/* hossam_t boundary stub (i18n fallback semantics: the literal string). */
if ( ! function_exists( 'hossam_t' ) ) {
	function hossam_t( string $s ): string {
		return $s;
	}
}
/* wp_kses_post boundary proxy (keep-list semantics; the h1 valve is not the
 * subject here — the post-kses payload feeds the adapter's stored payload). */
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $content ): string {
		$c = (string) $content;
		$c = preg_replace( '#</?(script|iframe|style)\b[^>]*>#si', '', $c );
		$c = preg_replace( '/\son[a-z]+\s*=\s*(["\']).*?\1/si', '', $c );
		return $c;
	}
}

/* ── Users / caps / session ── */
function get_current_user_id(): int {
	return (int) ( $GLOBALS['B14_USER_ID'] ?? 0 );
}
function get_current_blog_id(): int {
	return (int) ( $GLOBALS['B14_BLOG_ID'] ?? 1 );
}
function is_user_logged_in(): bool {
	return true;
}
function current_user_can( string $cap, ...$rest ): bool {
	return ! empty( $GLOBALS['B14_CAPS'][ $cap ] );
}
if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( int $user_id ) {
		return $GLOBALS['B14_USERS'][ $user_id ] ?? false;
	}
}

/* ── Options / transients ── */
$GLOBALS['B14_OPTIONS']    = array();
$GLOBALS['B14_TRANSIENTS'] = array();
function get_option( string $option, $default_value = false ) {
	return array_key_exists( $option, $GLOBALS['B14_OPTIONS'] ) ? $GLOBALS['B14_OPTIONS'][ $option ] : $default_value;
}
function update_option( string $option, $value, $autoload = null ): bool {
	$GLOBALS['B14_OPTIONS'][ $option ] = $value;
	return true;
}
function add_option( string $option, $value, $autoload = null ): bool {
	if ( array_key_exists( $option, $GLOBALS['B14_OPTIONS'] ) ) {
		return false;
	}
	$GLOBALS['B14_OPTIONS'][ $option ] = $value;
	return true;
}
function get_transient( string $key ) {
	return array_key_exists( $key, $GLOBALS['B14_TRANSIENTS'] ) ? $GLOBALS['B14_TRANSIENTS'][ $key ] : false;
}
function set_transient( string $key, $value, int $ttl = 0 ): bool {
	$GLOBALS['B14_TRANSIENTS'][ $key ] = $value;
	return true;
}
function delete_transient( string $key ): bool {
	unset( $GLOBALS['B14_TRANSIENTS'][ $key ] );
	return true;
}
function wp_using_ext_object_cache(): bool {
	return false;
}

/* ── wpdb boundary ── */
if ( ! class_exists( 'B14_wpdb' ) ) {
	final class B14_wpdb {
		public string $prefix     = 'wp_';
		public string $last_error = '';
		public array $queries     = array();

		public function prepare( string $query, ...$args ): string {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			foreach ( $args as $arg ) {
				$pos_s = strpos( $query, '%s' );
				$pos_d = strpos( $query, '%d' );
				if ( false !== $pos_s && ( false === $pos_d || $pos_s < $pos_d ) ) {
					$query = substr_replace( $query, "'" . addslashes( (string) $arg ) . "'", (int) $pos_s, 2 );
				} elseif ( false !== $pos_d ) {
					$query = substr_replace( $query, (string) (int) $arg, (int) $pos_d, 2 );
				}
			}
			return $query;
		}
		public function query( ?string $query = null ) {
			$this->queries[] = (string) $query;
			return 1;
		}
		public function get_var( ?string $query = null ) {
			$this->queries[] = 'get_var:' . (string) $query;
			return 1; // named locks always granted in the harness
		}
		public function flush(): void {
			$this->last_error = '';
		}
		public function get_charset_collate(): string {
			return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
		}
	}
}
$GLOBALS['wpdb'] = new B14_wpdb();

/* ── Posts boundary ── */
if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public function __construct( public $ID = 0, public $post_type = '', public $post_author = 0, public $post_status = '', public $post_title = '', public $post_content = '', public $post_excerpt = '', public $post_mime_type = '' ) {}
	}
}
if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {
		public function __construct( public $term_id = 0, public $term_taxonomy_id = 0, public $taxonomy = '' ) {}
	}
}
$GLOBALS['B14_POSTS']         = array();
$GLOBALS['B14_INSERTS']       = array();
$GLOBALS['B14_DELETED_POSTS'] = array();
$GLOBALS['B14_SET_CATS']      = array();
$GLOBALS['B14_NEXT_ID']       = 5001;

function get_post( $id ) {
	return $GLOBALS['B14_POSTS'][ (int) $id ] ?? null;
}
function set_probe_post( int $id, string $type, int $author, string $status = 'publish' ): void {
	$GLOBALS['B14_POSTS'][ $id ] = new WP_Post( $id, $type, $author, $status, 'T', 'C' );
}
function wp_insert_post( array $postarr, bool $wp_error = false ) {
	$data = apply_filters( 'wp_insert_post_data', $postarr, $postarr );
	$id   = $GLOBALS['B14_NEXT_ID']++;
	$data['ID'] = $id;
	$GLOBALS['B14_INSERTS'][] = $data;
	$GLOBALS['B14_POSTS'][ $id ] = new WP_Post( $id, (string) ( $data['post_type'] ?? 'post' ), (int) ( $data['post_author'] ?? 0 ), (string) ( $data['post_status'] ?? 'draft' ), (string) ( $data['post_title'] ?? '' ), (string) ( $data['post_content'] ?? '' ) );
	// WPML fixture: a freshly inserted post is registered by WPML into a fresh
	// trid group (one element) the moment it is written.
	if ( defined( 'ICL_SITEPRESS_VERSION' ) && 'post' === ( $data['post_type'] ?? '' ) && ! isset( $GLOBALS['B14_WPML']['trid_of'][ $id ] ) ) {
		$GLOBALS['B14_WPML']['trid_of'][ $id ] = 9000 + $id;
	}
	return $id;
}
function wp_update_post( array $postarr, bool $wp_error = false ) {
	$data = apply_filters( 'wp_insert_post_data', $postarr, $postarr );
	$GLOBALS['B14_INSERTS'][] = $data;
	return (int) ( $data['ID'] ?? 0 );
}
function wp_delete_post( int $post_id, bool $force = false ) {
	$GLOBALS['B14_DELETED_POSTS'][] = $post_id;
	return true;
}
function wp_trash_post( int $post_id ) {
	return true;
}
function wp_untrash_post( int $post_id ) {
	return true;
}
function get_post_status( $id ): string {
	$p = get_post( (int) $id );
	return $p ? (string) $p->post_status : '';
}
function wp_set_post_categories( int $post_id, array $categories = array() ): array {
	$GLOBALS['B14_SET_CATS'][ $post_id ] = array_values( array_map( 'absint', $categories ) );
	return $GLOBALS['B14_SET_CATS'][ $post_id ];
}
function wp_get_post_categories( int $post_id ): array {
	return $GLOBALS['B14_SET_CATS'][ $post_id ] ?? array();
}
function get_post_thumbnail_id( int $post_id ): int {
	return 0;
}
function set_post_thumbnail( int $post_id, int $thumbnail_id ): bool {
	return true;
}
function delete_post_thumbnail( int $post_id ): bool {
	return true;
}
function wp_attachment_is_image( int $attachment_id ): bool {
	return true;
}
function term_exists( $term, $taxonomy = null ) {
	return 5;
}
function get_term( $term_id, $taxonomy = '' ) {
	$tid = (int) $term_id;
	return new WP_Term( $tid, $tid * 10, 'category' );
}
function get_the_title( $post ): string {
	return 'T';
}
function get_permalink( $id ): string {
	return 'https://example.test/?p=' . (int) $id;
}
function get_preview_post_link( $id ): string {
	return 'https://example.test/preview/?p=' . (int) $id;
}
function get_page_by_path( string $slug ) {
	return null;
}
function home_url( string $path = '' ): string {
	return 'https://example.test' . $path;
}
function admin_url( string $path = '' ): string {
	return 'https://example.test/wp-admin/' . $path;
}
function add_query_arg( array $args, string $url ): string {
	return $url . '?' . http_build_query( $args );
}
function get_post_mime_type( $id ): string {
	return 'image/jpeg';
}
function wp_get_attachment_url( int $id ): string {
	return 'https://example.test/f';
}
function get_allowed_mime_types( $user_id = 0 ): array {
	return array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf' );
}
function wp_check_filetype( string $filename, $mimes = null ): array {
	return array( 'ext' => false, 'type' => false );
}
function shortcode_exists( string $tag ): bool {
	return false;
}
function wp_next_scheduled( string $hook, array $args = array() ): bool {
	return false;
}
function wp_schedule_single_event( int $ts, string $hook, array $args = array() ): bool {
	return true;
}
function wp_doing_ajax(): bool {
	return true;
}
function hossam_dashboard_ensure_table( array $definition ): void {
	$GLOBALS['B14_OPTIONS'][ $definition['option'] ] = $definition['version'];
}

/* ── error_log capture ── */
function b14_errlog_start(): void {
	@unlink( $GLOBALS['B14_ERRLOG'] );
	ini_set( 'error_log', $GLOBALS['B14_ERRLOG'] );
}
function b14_errlog_take(): string {
	ini_set( 'error_log', '' );
	$contents = (string) @file_get_contents( $GLOBALS['B14_ERRLOG'] );
	@unlink( $GLOBALS['B14_ERRLOG'] );
	return $contents;
}

/* ════════════════════════════════════════════════════════════════
 * OFFICIAL WPML interface stubs (state machine on the real filter names)
 * ════════════════════════════════════════════════════════════════ */

/*
 * $state mirrors what a real WPML runtime keeps:
 *   current          — wpml_current_language answer (member's chosen language)
 *   default          — wpml_default_language answer
 *   accept_stamp     — when false, the link action is recorded but WPML
 *                      "fails to persist" (verify must then fail)
 *   trid_of          — element_id ⇒ trid (fresh groups: one element)
 *   details          — element_id ⇒ {trid, language_code, source_language_code}
 *   translations     — trid ⇒ language_code ⇒ {element_id, source_language_code}
 *   set_action_calls — every wpml_set_element_language_details invocation
 *   filter_calls     — every official-filter invocation (zero-call proof)
 *   term_map         — term_id ⇒ lang ⇒ mapped term id (0 = no translation)
 */
$GLOBALS['B14_WPML'] = array(
	'current'         => '',
	'default'         => 'en',
	'accept_stamp'    => true,
	'trid_of'         => array(),
	'details'         => array(),
	'translations'    => array(),
	'set_action_calls' => array(),
	'filter_calls'    => array(),
	'term_map'        => array(),
);

function b14_wpml_note( string $hook ): void {
	$GLOBALS['B14_WPML']['filter_calls'][ $hook ] = ( $GLOBALS['B14_WPML']['filter_calls'][ $hook ] ?? 0 ) + 1;
}
function b14_wpml_register_filters(): void {
	add_filter( 'wpml_current_language', static function ( $v ) {
		b14_wpml_note( 'wpml_current_language' );
		return $GLOBALS['B14_WPML']['current'];
	}, 10, 1 );
	add_filter( 'wpml_default_language', static function ( $v ) {
		b14_wpml_note( 'wpml_default_language' );
		return $GLOBALS['B14_WPML']['default'];
	}, 10, 1 );
	add_filter( 'wpml_element_trid', static function ( $v, $element_id, $type = '' ) {
		b14_wpml_note( 'wpml_element_trid' );
		return $GLOBALS['B14_WPML']['trid_of'][ (int) $element_id ] ?? 0;
	}, 10, 3 );
	add_filter( 'wpml_element_language_details', static function ( $v, $args ) {
		b14_wpml_note( 'wpml_element_language_details' );
		return $GLOBALS['B14_WPML']['details'][ (int) ( $args['element_id'] ?? 0 ) ] ?? null;
	}, 10, 2 );
	add_filter( 'wpml_get_element_translations', static function ( $v, $trid, $type = '' ) {
		b14_wpml_note( 'wpml_get_element_translations' );
		return $GLOBALS['B14_WPML']['translations'][ (int) $trid ] ?? null;
	}, 10, 3 );
	add_filter( 'wpml_element_type', static function ( $v ) {
		b14_wpml_note( 'wpml_element_type' );
		return $v; // WPML maps 'post'→'post_post'; the incoming literal passes
	}, 10, 1 );
	add_filter( 'wpml_post_language_details', static function ( $v, $post_id ) {
		b14_wpml_note( 'wpml_post_language_details' );
		$d = $GLOBALS['B14_WPML']['details'][ (int) $post_id ] ?? null;
		return array( 'language_code' => is_array( $d ) ? (string) $d['language_code'] : '' );
	}, 10, 2 );
	add_action( 'wpml_set_element_language_details', static function ( $args ) {
		$GLOBALS['B14_WPML']['set_action_calls'][] = $args;
		if ( ! $GLOBALS['B14_WPML']['accept_stamp'] ) {
			return; // WPML failed to persist the link — details stay absent
		}
		$element_id = (int) ( $args['element_id'] ?? 0 );
		$trid       = (int) ( $args['trid'] ?? 0 );
		$GLOBALS['B14_WPML']['details'][ $element_id ] = array(
			'trid'                 => $trid,
			'language_code'        => (string) ( $args['language_code'] ?? '' ),
			'source_language_code' => (string) ( $args['source_language_code'] ?? '' ),
		);
		$GLOBALS['B14_WPML']['translations'][ $trid ][ (string) $args['language_code'] ] = array(
			'element_id'           => $element_id,
			'source_language_code' => (string) ( $args['source_language_code'] ?? '' ),
		);
	}, 10, 1 );
	add_filter( 'wpml_object_id', static function ( $v, $type = '', $missing = false, $lang = '' ) {
		// Official signature: ( element_id, element_type, return_original_if_missing, language_code ).
		b14_wpml_note( 'wpml_object_id' );
		return $GLOBALS['B14_WPML']['term_map'][ (int) $v ][ (string) $lang ] ?? 0;
	}, 10, 4 );
	add_filter( 'wpml_is_translated_taxonomy', static function ( $v, $tax = '' ) {
		b14_wpml_note( 'wpml_is_translated_taxonomy' );
		return true;
	}, 10, 2 );
	add_filter( 'wpml_language_is_active', static function ( $v, $lang = '' ) {
		b14_wpml_note( 'wpml_language_is_active' );
		return in_array( (string) $lang, array( 'en', 'fr', 'ar', 'de' ), true );
	}, 10, 2 );
	add_filter( 'wpml_element_language_code', static function ( $v, $args ) {
		b14_wpml_note( 'wpml_element_language_code' );
		return $GLOBALS['B14_WPML']['ttid_lang'][ (int) ( $args['element_id'] ?? 0 ) ] ?? '';
	}, 10, 2 );
}

if ( ! $is_no_wpml_child ) {
	b14_wpml_register_filters();
}

/* ════════════════════════════════════════════════════════════════
 * Load the REAL production units (bootstrap order: core → settings →
 * adapters → ajax)
 * ════════════════════════════════════════════════════════════════ */

require $GLOBALS['B14_PROJECT'] . '/runtime/core/rate-limits.php';
require $GLOBALS['B14_PROJECT'] . '/runtime/core/audit-log.php';
require $GLOBALS['B14_PROJECT'] . '/runtime/settings/class-settings-repository.php';
require $GLOBALS['B14_PROJECT'] . '/runtime/adapters/wpml.php';
require $GLOBALS['B14_PROJECT'] . '/runtime/adapters/wordpress-posts.php';
require $GLOBALS['B14_PROJECT'] . '/runtime/adapters/wordpress-uploads.php';
require $GLOBALS['B14_PROJECT'] . '/runtime/ajax/posts.php';

/* ════════════════════════════════════════════════════════════════
 * Child mode — the no-WPML scenario (A3)
 * ════════════════════════════════════════════════════════════════ */

if ( $is_no_wpml_child ) {
	$GLOBALS['B14_USER_ID'] = 7;
	$GLOBALS['B14_CAPS']    = array( 'edit_posts' => true );
	$GLOBALS['B14_USERS']   = array( 7 => (object) array( 'user_login' => 'hossam_member' ) );
	$GLOBALS['B14_NONCE_VALID'] = true;
	b14_errlog_start();
	$response = null;
	try {
		( function_exists( 'b14_hook_sorted' ) ? b14_hook_sorted( 'action', 'wp_ajax_hossam_create_article' )[0]['cb'] : null )();
	} catch ( HalT8Json $j ) {
		$response = $j;
	}
	$errlog   = b14_errlog_take();
	$inserts  = array_values( array_filter( $GLOBALS['wpdb']->queries, static fn( $q ) => false !== strpos( (string) $q, 'INSERT INTO wp_hossam_audit_log' ) ) );
	$lang_read = function_exists( 'hossam_wpml_get_post_language' ) && isset( $GLOBALS['B14_INSERTS'][0]['ID'] )
		? hossam_wpml_get_post_language( (int) $GLOBALS['B14_INSERTS'][0]['ID'] )
		: 'no-read';
	echo json_encode( array(
		'success'            => $response instanceof HalT8Json ? $response->success : false,
		'language'           => is_array( $response->payload ?? null ) ? (string) ( $response->payload['language'] ?? 'MISSING' ) : 'MISSING',
		'stamp_calls'        => $GLOBALS['B14_WPML']['set_action_calls'] ?? array(),
		'filter_calls'       => count( $GLOBALS['B14_WPML']['filter_calls'] ?? array() ),
		'stamp_log_lines'    => substr_count( $errlog, 'language stamp failed' ),
		'deleted_posts'      => count( $GLOBALS['B14_DELETED_POSTS'] ),
		'audit_lang_empty'   => 1 === count( $inserts ) && false !== strpos( (string) $inserts[0], addslashes( (string) wp_json_encode( array( 'status' => 'draft', 'lang' => '' ) ) ) ),
		'wpml_read'          => $lang_read,
		'post_id'            => (int) ( $GLOBALS['B14_INSERTS'][0]['ID'] ?? 0 ),
	) );
	exit( 0 );
}

/* ════════════════════════════════════════════════════════════════
 * Check helper + fixtures
 * ════════════════════════════════════════════════════════════════ */

$GLOBALS['B14_PASS']  = 0;
$GLOBALS['B14_FAIL']  = 0;
$GLOBALS['B14_FAILS'] = array();

function b14_check( string $id, bool $ok, string $pass, string $fail ): void {
	if ( $ok ) {
		$GLOBALS['B14_PASS']++;
		echo "PASS [{$id}] {$pass}\n";
	} else {
		$GLOBALS['B14_FAIL']++;
		$GLOBALS['B14_FAILS'][] = "{$id}: {$fail}";
		echo "FAIL [{$id}] {$fail}\n";
	}
}

/** Reset every mutable fixture between scenarios. */
function b14_reset( string $current = 'fr' ): void {
	$GLOBALS['B14_OPTIONS']        = array();
	$GLOBALS['B14_TRANSIENTS']     = array();
	$GLOBALS['B14_POSTS']          = array();
	$GLOBALS['B14_INSERTS']        = array();
	$GLOBALS['B14_DELETED_POSTS']  = array();
	$GLOBALS['B14_SET_CATS']       = array();
	$GLOBALS['B14_NONCE_VALID']    = true;
	$GLOBALS['B14_USER_ID']        = 7;
	$GLOBALS['B14_CAPS']           = array( 'edit_posts' => true );
	$GLOBALS['B14_USERS']          = array( 7 => (object) array( 'user_login' => 'hossam_member' ) );
	$GLOBALS['B14_WPML']['current']          = $current;
	$GLOBALS['B14_WPML']['default']          = 'en';
	$GLOBALS['B14_WPML']['accept_stamp']     = true;
	$GLOBALS['B14_WPML']['trid_of']          = array();
	$GLOBALS['B14_WPML']['details']          = array();
	$GLOBALS['B14_WPML']['translations']     = array();
	$GLOBALS['B14_WPML']['set_action_calls'] = array();
	$GLOBALS['B14_WPML']['filter_calls']     = array();
	$GLOBALS['B14_WPML']['term_map']         = array();
	$GLOBALS['wpdb']               = new B14_wpdb();
}

/** Invoke the REAL create endpoint callback and capture the response. */
function b14_create( array $post_fields ): HalT8Json {
	$_POST = $post_fields;
	$_REQUEST = $post_fields;
	try {
		$entries = b14_hook_sorted( 'action', 'wp_ajax_hossam_create_article' );
		call_user_func( $entries[0]['cb'] );
		throw new RuntimeException( 'create endpoint returned without a JSON response' );
	} catch ( HalT8Json $j ) {
		return $j;
	} finally {
		$_POST = array();
		$_REQUEST = array();
	}
}

function b14_audit_inserts(): array {
	return array_values( array_filter( $GLOBALS['wpdb']->queries, static fn( $q ) => false !== strpos( (string) $q, 'INSERT INTO wp_hossam_audit_log' ) ) );
}

/* ════════════════════════════════════════════════════════════════
 * T8-LOAD
 * ════════════════════════════════════════════════════════════════ */

b14_check( 'T8-LOAD-real-units',
	function_exists( 'hossam_wpml_stamp_post_language' )
		&& function_exists( 'hossam_create_article' )
		&& function_exists( 'hossam_wpml_get_current_language' )
		&& function_exists( 'hossam_wpml_get_post_language' )
		&& ! empty( b14_hook_sorted( 'action', 'wp_ajax_hossam_create_article' ) ),
	'real wpml.php/wordpress-posts.php/rate-limits.php/audit-log.php/ajax-posts.php loaded; the stamp wrapper and the real create endpoint are present',
	'real units did not load their expected surfaces' );

/* ════════════════════════════════════════════════════════════════
 * T8-GUARDS — the wrapper never fires the link action and never passes trid=0
 * ════════════════════════════════════════════════════════════════ */

b14_reset( 'fr' );
$g1 = hossam_wpml_stamp_post_language( 0, 'fr' );           // post_id < 1
$g2 = hossam_wpml_stamp_post_language( 5555, '' );          // '' language
$g3 = hossam_wpml_stamp_post_language( 5556, 'fr' );        // WPML never registered the element → trid < 1
$no_trid_zero = true;
foreach ( $GLOBALS['B14_WPML']['set_action_calls'] as $call ) {
	if ( (int) ( $call['trid'] ?? 0 ) < 1 ) {
		$no_trid_zero = false;
	}
}
b14_check( 'T8-guards-false-no-action-no-trid-zero',
	false === $g1 && false === $g2 && false === $g3
		&& 0 === count( $GLOBALS['B14_WPML']['set_action_calls'] )
		&& $no_trid_zero,
	'guards post_id<1 / empty language / missing trid all return false with ZERO link-action invocations — trid=0 is never passed (no trid forking)',
	'wrapper guards deviated: g1=' . var_export( $g1, true ) . ' g2=' . var_export( $g2, true )
		. ' g3=' . var_export( $g3, true ) . ' calls=' . count( $GLOBALS['B14_WPML']['set_action_calls'] ) );

/* ════════════════════════════════════════════════════════════════
 * T8-A1 — member language fr: stamped by the member, read back, audited
 * ════════════════════════════════════════════════════════════════ */

b14_reset( 'fr' );
b14_errlog_start();
$resp = b14_create( array( 'title' => 'Bonjour', 'content' => '<p>Contenu</p>', 'status' => 'draft' ) );
$stamp_err = b14_errlog_take();
$new_id    = (int) ( $GLOBALS['B14_INSERTS'][0]['ID'] ?? 0 );
$stamp     = $GLOBALS['B14_WPML']['set_action_calls'][0] ?? array();
$lang_fr   = hossam_wpml_get_post_language( $new_id );
$fr_needle = addslashes( (string) wp_json_encode( array( 'status' => 'draft', 'lang' => 'fr' ) ) );
b14_check( 'T8-A1-stamped-by-member-language',
	$resp->success && $new_id > 0
		&& 'fr' === (string) ( $resp->payload['language'] ?? '' )
		&& 1 === count( $GLOBALS['B14_WPML']['set_action_calls'] )
		&& 'fr' === (string) ( $stamp['language_code'] ?? '' )
		&& '' === (string) ( $stamp['source_language_code'] ?? 'x' )
		&& (int) ( $stamp['trid'] ?? 0 ) > 0
		&& 'fr' === $lang_fr
		&& '' === trim( $stamp_err ),
	"member browsing in FR creates an article stamped 'fr' through the official link action (fresh trid>0, source '') — read-back 'fr', success carries language=fr, zero log lines",
	'A1 deviated: success=' . var_export( $resp->success, true ) . ' resp_lang=' . var_export( $resp->payload['language'] ?? null, true )
		. ' stamp=' . var_export( $stamp, true ) . ' read=' . var_export( $lang_fr, true ) . ' err=' . var_export( $stamp_err, true ) );
b14_check( 'T8-A1-audit-lang-fr',
	1 === count( b14_audit_inserts() ) && false !== strpos( (string) b14_audit_inserts()[0], $fr_needle ),
	'audit article_create carries meta lang=fr beside status (the actually-read value)',
	'audit meta deviated: ' . var_export( b14_audit_inserts(), true ) );

/* ════════════════════════════════════════════════════════════════
 * T8-EMPTY — member language empty → site default language
 * ════════════════════════════════════════════════════════════════ */

b14_reset( '' ); // the member has no language chosen (cookie/URL absent → WPML falls back)
$resp = b14_create( array( 'title' => 'Default', 'content' => '<p>Body</p>', 'status' => 'draft' ) );
$stamp = $GLOBALS['B14_WPML']['set_action_calls'][0] ?? array();
$new_id = (int) ( $GLOBALS['B14_INSERTS'][0]['ID'] ?? 0 );
b14_check( 'T8-empty-member-language-defaults-to-site',
	$resp->success && 'en' === (string) ( $resp->payload['language'] ?? '' )
		&& 'en' === (string) ( $stamp['language_code'] ?? '' )
		&& 'en' === hossam_wpml_get_post_language( $new_id ),
	'member language empty ⇒ the article is stamped with the site default language (en) and the read-back agrees',
	'default-language stamp deviated: resp_lang=' . var_export( $resp->payload['language'] ?? null, true )
		. ' stamp=' . var_export( $stamp, true ) );

/* ════════════════════════════════════════════════════════════════
 * T8-A2 — categories resolve in the article language; default fallback intact
 * ════════════════════════════════════════════════════════════════ */

b14_reset( 'fr' );
$GLOBALS['B14_WPML']['term_map'][5]['fr'] = 55;      // term 5 has a FR counterpart
$GLOBALS['B14_WPML']['term_map'][6]['fr'] = 0;       // term 6 has NO FR counterpart
$GLOBALS['B14_WPML']['term_map'][6]['en'] = 66;      // …and falls back to the default language
$GLOBALS['B14_WPML']['ttid_lang'][550] = 'fr';       // get_term(55)->term_taxonomy_id = 550
$GLOBALS['B14_WPML']['ttid_lang'][660] = 'en';       // get_term(66)->term_taxonomy_id = 660
$resp = b14_create( array( 'title' => 'Cats', 'content' => '<p>Body</p>', 'status' => 'draft', 'categories' => array( 5, 6 ) ) );
b14_check( 'T8-A2-categories-in-article-language-with-fallback',
	$resp->success
		&& isset( $GLOBALS['B14_SET_CATS'][ $GLOBALS['B14_INSERTS'][0]['ID'] ] )
		&& array( 55, 66 ) === $GLOBALS['B14_SET_CATS'][ $GLOBALS['B14_INSERTS'][0]['ID'] ],
	'categories resolve in the member/article language (5→55 fr); the untranslated term falls back to the explicit default-language request (6→66 en) and the resolved list reaches wp_set_post_categories',
	'category resolution deviated: set=' . var_export( $GLOBALS['B14_SET_CATS'], true )
		. ' resp=' . var_export( $resp->payload, true ) );

/* ════════════════════════════════════════════════════════════════
 * T8-A4 — verify failure: creation succeeds, one log line, no draft deletion
 * ════════════════════════════════════════════════════════════════ */

b14_reset( 'fr' );
$GLOBALS['B14_WPML']['accept_stamp'] = false; // WPML never persists the link → verify mismatch
b14_errlog_start();
$resp = b14_create( array( 'title' => 'Fail', 'content' => '<p>Body</p>', 'status' => 'draft' ) );
$fail_err = b14_errlog_take();
$new_id   = (int) ( $GLOBALS['B14_INSERTS'][0]['ID'] ?? 0 );
$fail_lines = array();
foreach ( preg_split( '/\r\n|\r|\n/', $fail_err ) as $line ) {
	if ( false !== strpos( $line, 'language stamp failed' ) ) {
		$fail_lines[] = $line;
	}
}
$expected_line = 'Hossam Dashboard language stamp failed for post ' . $new_id . ' (expected fr)';
$lang_read     = hossam_wpml_get_post_language( $new_id );
$empty_needle  = addslashes( (string) wp_json_encode( array( 'status' => 'draft', 'lang' => '' ) ) );
b14_check( 'T8-A4-verify-failure-creation-succeeds',
	$resp->success && $new_id > 0
		&& '' === (string) ( $resp->payload['language'] ?? 'x' )
		&& 1 === count( $fail_lines ) && false !== strpos( $fail_lines[0], $expected_line )
		&& 0 === count( $GLOBALS['B14_DELETED_POSTS'] )
		&& '' === $lang_read
		&& 1 === count( b14_audit_inserts() ) && false !== strpos( (string) b14_audit_inserts()[0], $empty_needle ),
	'stamp verify failure: the creation SUCCEEDS, exactly one error_log line carries the post id and the expected language, the draft is NOT deleted, and audit+success carry the actually-read language (empty)',
	'A4 deviated: success=' . var_export( $resp->success, true ) . ' resp_lang=' . var_export( $resp->payload['language'] ?? null, true )
		. ' log_lines=' . count( $fail_lines ) . ' expected=' . $expected_line
		. ' deleted=' . count( $GLOBALS['B14_DELETED_POSTS'] ) . ' read=' . var_export( $lang_read, true ) );

/* ════════════════════════════════════════════════════════════════
 * T8-A3 — no WPML (fresh self-child process): zero stamp, zero log, A3 bytes
 * ════════════════════════════════════════════════════════════════ */

$cmd = escapeshellarg( PHP_BINARY ) . ' -d display_errors=1 -d error_reporting=' . escapeshellarg( (string) E_ALL )
	. ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( '--b14t8-child=no_wpml' );
$out     = shell_exec( $cmd . ' 2>&1' );
$decoded = json_decode( (string) $out, true );
b14_check( 'T8-A3-no-wpml-byte-equal-today',
	is_array( $decoded )
		&& true === ( $decoded['success'] ?? null )
		&& '' === ( $decoded['language'] ?? 'x' )
		&& array() === ( $decoded['stamp_calls'] ?? array( 'x' ) )
		&& 0 === ( $decoded['filter_calls'] ?? -1 )
		&& 0 === ( $decoded['stamp_log_lines'] ?? -1 )
		&& 0 === ( $decoded['deleted_posts'] ?? -1 )
		&& true === ( $decoded['audit_lang_empty'] ?? null )
		&& '' === ( $decoded['wpml_read'] ?? 'x' ),
	'no WPML: zero stamp calls, zero official-filter calls, zero log lines, no deletion, audit lang="" and success language="" — the no-WPML behavior is preserved byte-equal (A3)',
	'no-WPML child deviated: ' . (string) $out );

/* ════════════════════════════════════════════════════════════════
 * Summary
 * ════════════════════════════════════════════════════════════════ */

echo "B14-T8 RESULT: {$GLOBALS['B14_PASS']} pass, 0 skipped, {$GLOBALS['B14_FAIL']} fail (PHP " . PHP_VERSION . ")\n";
if ( $GLOBALS['B14_FAIL'] > 0 ) {
	echo "Failures:\n" . implode( "\n", $GLOBALS['B14_FAILS'] ) . "\n";
	exit( 1 );
}
exit( 0 );
