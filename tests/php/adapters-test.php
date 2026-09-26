<?php
/**
 * HAL Frontend Dashboard — Batch 4 closure harness: runtime adapters.
 *
 * Local, isolated harness: no live WordPress, no network, no database.
 * Loads the REAL runtime/bootstrap.php (core + batch-3 backend + the
 * eight batch-4 adapters) — only WordPress itself is stubbed.
 *
 * Coverage (architecture §15 closure gate + §10 per-file rules):
 *
 *   LOAD     — the real bootstrap loads all 8 adapters AFTER the 5 core
 *              files and the 5 batch-3 backend files, before the two
 *              batch-5 ajax files (posts/uploads, documented batch-5
 *              evolution); every adapter signature function is
 *              defined; every adapter definition is function_exists-
 *              guarded (amelia's load-time add_action calls are the only
 *              allowed top-level statements); a second require_once
 *              registers nothing new.
 *   ABSENCE  — with the external plugin absent, every wrapper degrades
 *              safely: WooCommerce/WPML guards, Amelia inactive paths,
 *              Rank Math core-meta contract, Ultimate Member empty file,
 *              Posts CRUD security matrix, Uploads capability/mime gates.
 *   STATES   — the integration capability registry classifies
 *              unavailable / limited / available per adapter state, and
 *              the AI strategy registry resolves/fails closed per the
 *              unified reference §7.4 table (no provider fallback).
 *   CREDENTIALS — the batch-3 credential resolver is consumed: Amelia
 *              constant → hal_encrypted precedence (and the documented
 *              constant-only fallback when the batch-3 layer is absent);
 *              AI direct_key provider gate + encrypted store presence;
 *              the decrypted key never reaches URL/body/logs/error data.
 *   CLOSURE    — the agreed B4-01..B4-04 hardening: Amelia HTTPS /
 *              zero-redirect / 64KB-bound transport with safe refusal
 *              (AA8/AA9/AA10); direct_key fallback-only with a clear
 *              refusal error while a client capability exists (AS5) and
 *              the still-valid fallback path without one (AS8);
 *              non-sensitive connector source status (CS1/CS2/CS3);
 *              fixed model allowlist with pre-connection refusal
 *              (AM1/AM2/AM3).
 *
 * Usage:  php tests/php/adapters-test.php               (main)
 *         php tests/php/adapters-test.php <mode> [args…] (subprocess)
 *         exit 0 = all checks pass.
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

if ( PHP_SAPI !== 'cli' ) {
	echo "This harness must run under CLI PHP.\n";
	exit( 1 );
}

$hal_ext_dir = (string) ( getenv( 'HAL_PHP_EXT_DIR' ) ?: ini_get( 'extension_dir' ) );
if ( '' !== $hal_ext_dir && ! is_dir( $hal_ext_dir ) ) {
	$hal_ext_dir = (string) realpath( $hal_ext_dir );
}
define( 'HAL_TEST_EXT_DIR', is_dir( $hal_ext_dir ) ? $hal_ext_dir : (string) $hal_ext_dir );

$project = dirname( __DIR__, 2 );

$GLOBALS['B4_HOOKS']      = array();
$GLOBALS['B4_ACTIONS']    = array();
$GLOBALS['B4_OPTIONS']    = array();
$GLOBALS['B4_RESULTS']    = array();
$GLOBALS['B4_CAPS']       = array();
$GLOBALS['B4_HTTP']       = array( 'calls' => array() );
$GLOBALS['B4_UPLOAD']     = array( 'result' => 0, 'calls' => 0 );
$GLOBALS['B4_TERMS']      = array();
$GLOBALS['B4_POSTS']      = array();
$GLOBALS['B4_MEDIA']      = array( 'image_ids' => array(), 'meta' => array() );
$GLOBALS['B4_SHORTCODES'] = array();
$GLOBALS['B4_PLUGIN_ACTIVE'] = false;
$GLOBALS['B4_LOGGED_IN']  = false;
$GLOBALS['B4_USER_ID']    = 3;

/* ────────────────────────────────────────────────────────────────
 * Harness bookkeeping
 * ──────────────────────────────────────────────────────────────── */

function b4_check( string $id, bool $condition, string $pass, string $fail = '' ): void {
	$GLOBALS['B4_RESULTS'][] = array( 'id' => $id, 'ok' => $condition, 'pass' => $pass, 'fail' => $fail );
}

function b4_result_line( string $mode ): void {
	$fail = 0;
	foreach ( $GLOBALS['B4_RESULTS'] as $result ) {
		if ( ! $result['ok'] ) {
			$fail++;
			echo "FAIL [{$result['id']}] {$result['fail']}\n";
		}
	}
	echo 'B4-VERDICT ' . $mode . ( 0 === $fail ? ' ALL-ASSERTIONS-HELD' : ' ASSERTIONS-FAILED-' . $fail ) . "\n";
	exit( 0 === $fail ? 0 : 1 );
}

/* ────────────────────────────────────────────────────────────────
 * WordPress boundary stubs (WordPress only — never project logic)
 * ──────────────────────────────────────────────────────────────── */

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook_name, $callback = '', int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['B4_HOOKS'][ $hook_name ][] = array( 'type' => 'action', 'callback' => $callback, 'priority' => $priority );
		return true;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook_name, $callback = '', int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['B4_HOOKS'][ $hook_name ][] = array( 'type' => 'filter', 'callback' => $callback, 'priority' => $priority );
		return true;
	}
}
if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( string $hook_name, $callback, int $priority = 10 ): bool {
		foreach ( $GLOBALS['B4_HOOKS'][ $hook_name ] ?? array() as $index => $entry ) {
			if ( $entry['callback'] === $callback ) {
				unset( $GLOBALS['B4_HOOKS'][ $hook_name ][ $index ] );
				return true;
			}
		}
		return false;
	}
}
if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( string $hook_name, $callback = false ) {
		$entries = $GLOBALS['B4_HOOKS'][ $hook_name ] ?? array();
		if ( false === $callback ) {
			return array() !== $entries;
		}
		foreach ( $entries as $entry ) {
			if ( $entry['callback'] === $callback ) {
				return $priority ?? 10;
			}
		}
		return false;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook_name, ...$args ): void {
		$GLOBALS['B4_ACTIONS'][ $hook_name ] = ( $GLOBALS['B4_ACTIONS'][ $hook_name ] ?? 0 ) + 1;
		foreach ( $GLOBALS['B4_HOOKS'][ $hook_name ] ?? array() as $entry ) {
			call_user_func_array( $entry['callback'], $args );
		}
	}
}
if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook_name ): int {
		return $GLOBALS['B4_ACTIONS'][ $hook_name ] ?? 0;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook_name, $value, ...$args ) {
		foreach ( $GLOBALS['B4_HOOKS'][ $hook_name ] ?? array() as $entry ) {
			$value = call_user_func_array( $entry['callback'], array_merge( array( $value ), $args ) );
		}
		return $value;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return preg_replace( '/[^a-z0-9_]/', '', strtolower( $key ) );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ): string {
		return trim( (string) $value );
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $value ): string {
		return trim( (string) $value );
	}
}
if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( string $name ): string {
		return preg_replace( '/[^A-Za-z0-9._-]/', '_', basename( $name ) );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return $value;
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ): string {
		$url = trim( (string) $url );
		return preg_match( '#^https?://#i', $url ) ? $url : '';
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0 ) {
		return json_encode( $value, $flags );
	}
}
if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) {
		return array_merge( (array) $defaults, (array) $args );
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component );
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability, ...$object_args ): bool {
		$key = $capability . ( isset( $object_args[0] ) ? '_' . (int) $object_args[0] : '' );
		if ( array_key_exists( $key, $GLOBALS['B4_CAPS'] ) ) {
			return (bool) $GLOBALS['B4_CAPS'][ $key ];
		}
		if ( array_key_exists( $capability, $GLOBALS['B4_CAPS'] ) ) {
			return (bool) $GLOBALS['B4_CAPS'][ $capability ];
		}
		return false;
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return (int) $GLOBALS['B4_USER_ID'];
	}
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		return (bool) $GLOBALS['B4_LOGGED_IN'];
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default_value = false ) {
		return array_key_exists( $option, $GLOBALS['B4_OPTIONS'] ) ? $GLOBALS['B4_OPTIONS'][ $option ] : $default_value;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, $value, $autoload = null ): bool {
		$GLOBALS['B4_OPTIONS'][ $option ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ) {
		return get_option( '_transient_' . $key );
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, $value, int $ttl = 0 ): bool {
		return update_option( '_transient_' . $key, $value );
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $key ): bool {
		unset( $GLOBALS['B4_OPTIONS'][ '_transient_' . $key ] );
		return true;
	}
}
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
if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID = 0;
		public string $post_type = 'post';
		public string $post_title = '';
		public string $post_content = '';
		public string $post_status = '';
		public int $post_author = 0;
	}
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return ( $GLOBALS['B4_ADMIN_URL'] ?? 'https://example.test/wp-admin/' ) . $path;
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $args, $url = '' ): string {
		if ( ! is_array( $args ) ) {
			$stack = func_get_args();
			$args  = $stack[0] ?? array();
			$url   = (string) ( $stack[ count( $stack ) - 1 ] ?? '' );
		}
		return (string) $url . ( false === strpos( (string) $url, '?' ) ? '?' : '&' ) . http_build_query( (array) $args );
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		return 'https://example.test/' . ltrim( $path, '/' );
	}
}
if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( string $url, array $args = array() ) {
		$GLOBALS['B4_HTTP']['calls'][] = array( 'verb' => 'GET', 'url' => $url, 'args' => $args );
		return $GLOBALS['B4_HTTP']['wp_error'] ?? $GLOBALS['B4_HTTP']['response'] ?? new WP_Error( 'b4_no_response_fixture' );
	}
}
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( string $url, array $args = array() ) {
		$GLOBALS['B4_HTTP']['calls'][] = array( 'verb' => 'POST', 'url' => $url, 'args' => $args );
		return $GLOBALS['B4_HTTP']['wp_error'] ?? $GLOBALS['B4_HTTP']['response'] ?? new WP_Error( 'b4_no_response_fixture' );
	}
}
if ( ! function_exists( 'wp_remote_head' ) ) {
	function wp_remote_head( string $url, array $args = array() ) {
		$GLOBALS['B4_HTTP']['calls'][] = array( 'verb' => 'HEAD', 'url' => $url, 'args' => $args );
		if ( isset( $GLOBALS['B4_HTTP']['head'][ $url ] ) ) {
			return array( 'response' => array( 'code' => $GLOBALS['B4_HTTP']['head'][ $url ] ), 'body' => '' );
		}
		return $GLOBALS['B4_HTTP']['wp_error'] ?? $GLOBALS['B4_HTTP']['response'] ?? new WP_Error( 'b4_no_response_fixture' );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ): int {
		return is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : 0;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ): string {
		return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
	}
}
if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( string $format, $timestamp = null ) {
		return gmdate( $format, $timestamp ?? time() );
	}
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type ) {
		return 'mysql' === $type ? gmdate( 'Y-m-d H:i:s' ) : time();
	}
}
if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite(): bool {
		return false;
	}
}
if ( ! function_exists( 'is_plugin_active' ) ) {
	function is_plugin_active( string $plugin_file ): bool {
		return in_array( $plugin_file, (array) $GLOBALS['B4_PLUGIN_ACTIVE'], true );
	}
}
if ( ! function_exists( 'get_plugin_data' ) ) {
	function get_plugin_data( string $plugin_file, bool $markup = true, bool $translate = true ): array {
		return array( 'Version' => '' );
	}
}
if ( ! function_exists( 'shortcode_exists' ) ) {
	function shortcode_exists( string $tag ): bool {
		return in_array( $tag, $GLOBALS['B4_SHORTCODES'], true );
	}
}
if ( ! function_exists( 'get_page_by_path' ) ) {
	function get_page_by_path( string $page_path, $output = null, string $post_type = 'page' ) {
		$id = array_search( $page_path, $GLOBALS['B4_POST_SLUGS'] ?? array(), true );
		if ( false === $id ) {
			return null;
		}
		$page       = new WP_Post();
		$page->ID   = (int) $id;
		$page->post_type = 'page';
		return $page;
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post ) {
		$id = is_object( $post ) ? ( $post->ID ?? 0 ) : (int) $post;
		return 'https://example.test/?p=' . $id;
	}
}
if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( array $args = array() ): array {
		return $GLOBALS['B4_GET_POSTS'] ?? array();
	}
}
if ( ! function_exists( 'get_post_type_archive_link' ) ) {
	function get_post_type_archive_link( string $post_type ): string {
		return 'https://example.test/archive/' . $post_type . '/';
	}
}
if ( ! function_exists( 'get_option_show_on_front' ) ) {
}
if ( ! function_exists( 'is_rtl' ) ) {
	function is_rtl(): bool {
		return (bool) ( $GLOBALS['B4_IS_RTL'] ?? false );
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) {
		$id = is_object( $id ) ? ( $id->ID ?? 0 ) : (int) $id;
		return $GLOBALS['B4_POSTS'][ $id ] ?? null;
	}
}
if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( array $postarr, bool $wp_error = false ) {
		if ( ! empty( $GLOBALS['B4_POSTS_FAIL_INSERT'] ) ) {
			return $wp_error ? new WP_Error( 'b4_insert_failed', 'insert failed fixture' ) : 0;
		}
		static $next = 100;
		$post           = new WP_Post();
		$post->ID       = ++$next;
		$post->post_type    = (string) ( $postarr['post_type'] ?? 'post' );
		$post->post_status  = (string) ( $postarr['post_status'] ?? 'draft' );
		$post->post_title   = (string) ( $postarr['post_title'] ?? '' );
		$post->post_content = (string) ( $postarr['post_content'] ?? '' );
		$post->post_author  = (int) ( $postarr['post_author'] ?? 0 );
		$GLOBALS['B4_POSTS'][ $post->ID ] = $post;
		return $post->ID;
	}
}
if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( array $postarr, bool $wp_error = false ) {
		$id = (int) ( $postarr['ID'] ?? 0 );
		if ( ! empty( $GLOBALS['B4_POSTS_FAIL_UPDATE'] ) ) {
			return $wp_error ? new WP_Error( 'b4_update_failed', 'update failed fixture' ) : 0;
		}
		$post = $GLOBALS['B4_POSTS'][ $id ] ?? null;
		if ( ! $post ) {
			return $wp_error ? new WP_Error( 'b4_update_missing', 'missing' ) : 0;
		}
		if ( isset( $postarr['post_title'] ) ) {
			$post->post_title = (string) $postarr['post_title'];
		}
		if ( isset( $postarr['post_content'] ) ) {
			$post->post_content = (string) $postarr['post_content'];
		}
		if ( isset( $postarr['post_status'] ) ) {
			$post->post_status = (string) $postarr['post_status'];
		}
		return $id;
	}
}
if ( ! function_exists( 'wp_delete_post' ) ) {
	function wp_delete_post( int $post_id, bool $force = false ) {
		$post = $GLOBALS['B4_POSTS'][ $post_id ] ?? null;
		if ( ! empty( $GLOBALS['B4_POSTS_FAIL_DELETE'] ) ) {
			return false;
		}
		unset( $GLOBALS['B4_POSTS'][ $post_id ] );
		return $post;
	}
}
if ( ! function_exists( 'wp_trash_post' ) ) {
	function wp_trash_post( int $post_id ) {
		$post = $GLOBALS['B4_POSTS'][ $post_id ] ?? null;
		if ( ! $post ) {
			return false;
		}
		$post->post_status = 'trash';
		return $post;
	}
}
if ( ! function_exists( 'wp_untrash_post' ) ) {
	function wp_untrash_post( int $post_id ) {
		$post = $GLOBALS['B4_POSTS'][ $post_id ] ?? null;
		if ( ! $post ) {
			return false;
		}
		$post->post_status = 'draft';
		return $post;
	}
}
if ( ! function_exists( 'term_exists' ) ) {
	function term_exists( $term, string $taxonomy = '' ) {
		if ( ! isset( $GLOBALS['B4_TERMS'][ $taxonomy ] ) ) {
			return null;
		}
		return in_array( (int) $term, $GLOBALS['B4_TERMS'][ $taxonomy ], true ) ? (int) $term : null;
	}
}
if ( ! function_exists( 'wp_set_post_categories' ) ) {
	function wp_set_post_categories( int $post_id, array $categories = array() ) {
		if ( ! empty( $GLOBALS['B4_POSTS_FAIL_CATEGORIES'] ) ) {
			return false;
		}
		$GLOBALS['B4_POST_CATEGORIES'][ $post_id ] = array_map( 'absint', $categories );
		return array_map( 'absint', $categories );
	}
}
if ( ! function_exists( 'wp_get_post_categories' ) ) {
	function wp_get_post_categories( int $post_id, array $args = array() ) {
		return $GLOBALS['B4_POST_CATEGORIES'][ $post_id ] ?? array();
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $meta_key, bool $single = false ) {
		return $GLOBALS['B4_MEDIA']['meta'][ $post_id ][ $meta_key ] ?? '';
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( int $post_id, string $meta_key, $value ) {
		$GLOBALS['B4_MEDIA']['meta'][ $post_id ][ $meta_key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'wp_attachment_is_image' ) ) {
	function wp_attachment_is_image( $id ): bool {
		return in_array( (int) $id, $GLOBALS['B4_MEDIA']['image_ids'], true );
	}
}
if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
	function get_post_thumbnail_id( int $post_id ) {
		return $GLOBALS['B4_POST_THUMBNAILS'][ $post_id ] ?? 0;
	}
}
if ( ! function_exists( 'set_post_thumbnail' ) ) {
	function set_post_thumbnail( int $post_id, int $thumbnail_id ) {
		if ( ! empty( $GLOBALS['B4_POSTS_FAIL_THUMBNAIL'] ) ) {
			return false;
		}
		$GLOBALS['B4_POST_THUMBNAILS'][ $post_id ] = $thumbnail_id;
		return true;
	}
}
if ( ! function_exists( 'delete_post_thumbnail' ) ) {
	function delete_post_thumbnail( int $post_id ) {
		unset( $GLOBALS['B4_POST_THUMBNAILS'][ $post_id ] );
		return true;
	}
}
if ( ! function_exists( 'get_allowed_mime_types' ) ) {
	function get_allowed_mime_types( $user = null ): array {
		return $GLOBALS['B4_SITE_MIMES'] ?? array(
			'png'  => 'image/png',
			'jpg|jpeg' => 'image/jpeg',
			'exe'  => 'application/x-msdownload',
		);
	}
}
if ( ! function_exists( 'wp_check_filetype' ) ) {
	function wp_check_filetype( string $filename, $mimes = null ): array {
		$mimes = is_array( $mimes ) ? $mimes : get_allowed_mime_types();
		$extension = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
		foreach ( $mimes as $pattern => $mime ) {
			foreach ( explode( '|', (string) $pattern ) as $candidate ) {
				if ( $candidate === $extension ) {
					return array( 'ext' => $extension, 'type' => $mime );
				}
			}
		}
		return array( 'ext' => false, 'type' => false );
	}
}
if ( ! function_exists( 'media_handle_upload' ) ) {
	function media_handle_upload( string $field, int $post_id, array $post_data = array(), array $overrides = array() ) {
		$GLOBALS['B4_UPLOAD']['calls']++;
		return $GLOBALS['B4_UPLOAD']['result'];
	}
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $content ): string {
		return (string) $content;
	}
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

/* ────────────────────────────────────────────────────────────────
 * Fixture helpers
 * ──────────────────────────────────────────────────────────────── */

function b4_define_release_context(): void {
	if ( ! defined( 'ABSPATH' ) ) {
		$abspath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'b4-' . getmypid() . DIRECTORY_SEPARATOR;
		if ( ! is_dir( $abspath ) ) {
			@mkdir( $abspath, 0777, true );
		}
		// The capability registry's version probe requires this include path;
		// an empty stub + a get_plugin_data boundary stub keep it executable
		// (no real plugin files exist here).
		$wp_admin_includes = $abspath . 'wp-admin' . DIRECTORY_SEPARATOR . 'includes';
		if ( ! is_dir( $wp_admin_includes ) ) {
			@mkdir( $wp_admin_includes, 0777, true );
		}
		$plugin_include = $wp_admin_includes . DIRECTORY_SEPARATOR . 'plugin.php';
		if ( ! file_exists( $plugin_include ) ) {
			file_put_contents( $plugin_include, "<?php\n" );
		}
		define( 'ABSPATH', $abspath );
	}
	if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
		define( 'WP_PLUGIN_DIR', ABSPATH . 'wp-content/plugins' );
	}
	if ( ! defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT' ) ) {
		define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT', $GLOBALS['B4_PROJECT'] . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR );
		define( 'HAL_FRONTEND_DASHBOARD_RELEASE_ID', '4.0.0+' . str_repeat( 'b4', 16 ) );
		define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION', '4.0.0' );
		define(
			'HAL_FRONTEND_DASHBOARD_RUNTIME_URL',
			'https://example.test/releases/' . HAL_FRONTEND_DASHBOARD_RELEASE_ID . '/'
		);
	}
}

function b4_define_valid_profile(): void {
	if ( ! defined( 'HOSSAM_AI_RUNTIME_PROFILE' ) ) {
		define( 'HOSSAM_AI_RUNTIME_PROFILE', array(
			'per_minute' => 30, 'per_day' => 500, 'concurrent' => 2, 'max_attempts' => 3,
			'stale_pending' => 3600, 'processing_deadline' => 240,
			'wp_ai_client_timeout' => 60, 'direct_key_timeout' => 90,
		) );
	}
}

function b4_valid_key_hex( string $seed ): string {
	return hash( 'sha256', 'b4-hal-master-key::' . $seed );
}

function b4_require_runtime(): void {
	require $GLOBALS['B4_PROJECT'] . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'bootstrap.php';
}

function b4_run_mode( string $mode, array $args = array() ): void {
	$php     = PHP_BINARY;
	$script  = __FILE__;
	$command = array( $php );
	$command[] = '-d';
	$command[] = 'extension_dir=' . HAL_TEST_EXT_DIR;
	$command[] = '-d';
	$command[] = 'extension=sodium';
	$command[] = '-d';
	$command[] = 'extension=zip';
	$command[] = $script;
	$command[] = $mode;
	foreach ( $args as $arg ) {
		$command[] = $arg;
	}
	$descriptors = array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
	$proc = proc_open( $command, $descriptors, $pipes );
	if ( ! is_resource( $proc ) ) {
		$escaped = array_map( 'escapeshellarg', $command );
		$proc = proc_open( implode( ' ', $escaped ), $descriptors, $pipes );
		if ( ! is_resource( $proc ) ) {
			b4_check( 'MODE-' . strtoupper( str_replace( '-', '_', $mode ) ), false, 'subprocess could not start', 'proc_open failed' );
			return;
		}
	}
	fclose( $pipes[0] );
	$stdout = (string) stream_get_contents( $pipes[1] );
	fclose( $pipes[1] );
	$stderr = (string) stream_get_contents( $pipes[2] );
	fclose( $pipes[2] );
	$code = proc_close( $proc );
	$ok = 0 === $code && false !== strpos( $stdout, 'B4-VERDICT ' . $mode . ' ALL-ASSERTIONS-HELD' );
	$detail = '';
	if ( ! $ok ) {
		$detail = 'exit ' . $code . '; stdout: ' . trim( $stdout ) . '; stderr: ' . trim( $stderr );
	}
	b4_check(
		'MODE-' . strtoupper( str_replace( '-', '_', $mode ) ),
		$ok,
		'subprocess mode ' . $mode . ' held all its assertions (exit 0)',
		'mode ' . $mode . ' failed — ' . $detail
	);
}

/* ────────────────────────────────────────────────────────────────
 * MODES
 * ──────────────────────────────────────────────────────────────── */

function b4_mode( string $mode, array $args, string $project ): void {
	$GLOBALS['B4_PROJECT'] = $project;

	switch ( $mode ) {
		case 'main':
			b4_define_release_context();
			$GLOBALS['wpdb'] = new class() {
				public string $prefix = 'wp_';
				public string $last_error = '';
				public function prepare( string $query, ...$args ): string {
					return $query;
				}
				public function get_var( string $query ) {
					return null;
				}
				public function get_results( string $query, $output = null ) {
					return array();
				}
				public function esc_like( string $text ): string {
					return $text;
				}
			};
			$included_before = count( get_included_files() );
			b4_require_runtime();

			$included = array_map(
				static function ( string $path ): string {
					return str_replace( '\\', '/', $path );
				},
				get_included_files()
			);
			$runtime_included = array_values( array_filter(
				$included,
				static function ( string $path ): bool {
					return false !== strpos( $path, '/runtime/' );
				}
			) );
			$relative = array_map(
				static function ( string $path ): string {
					$marker = '/runtime/';
					$pos    = strrpos( $path, $marker );
					return false === $pos ? $path : substr( $path, $pos + strlen( '/runtime/' ) );
				},
				$runtime_included
			);
			$expected_order = array(
				'core/setup.php',
				'core/i18n.php',
				'core/tables.php',
				'core/permissions.php',
				'core/notifications.php',
				'settings/class-settings-repository.php',
				'security/class-secret-store.php',
				'integrations/class-integration-settings-registry.php',
				'health/class-integration-health-monitor.php',
				'admin/class-admin-controller.php',
				'adapters/wordpress-posts.php',
				'adapters/wordpress-uploads.php',
				'adapters/woocommerce.php',
				'adapters/wpml.php',
				'adapters/amelia.php',
				'adapters/rankmath.php',
				'adapters/ultimatemember.php',
				'adapters/ai.php',
				'ajax/posts.php',
				'ajax/uploads.php',
				// Batch 6 (integrations/services AJAX §17) — literal legacy order.
				'ajax/appointments.php',
				'ajax/finance.php',
				'ajax/members.php',
				'ajax/inbox.php',
				'ajax/store.php',
				'ajax/ai.php',
				// Batch 7 (§18) — the shared Template Controller copy loads
				// after all modules (§6.1 pre-build infrastructure copy).
				'infrastructure/class-template-controller.php',
				// Batch 11 (§22) — the Update Bridge copy loads after the
				// Template Controller (§6.1 pre-build infrastructure copy).
				'infrastructure/class-update-bridge.php',
			);
			$actual_order = array_values( array_intersect( $relative, $expected_order ) );
			b4_check( 'L1', $actual_order === $expected_order,
				'the real bootstrap loads core (5) → backend (5) → adapters (8) → ajax (8: batch-5 content/files + batch-6 integrations/services) → the batch-7 infrastructure controller → the batch-11 update bridge in the mandated order and nothing else from runtime',
				'load order mismatch: ' . json_encode( $relative ) );
			$module_included = array_values( array_filter(
				$runtime_included,
				static function ( string $path ): bool {
					return false === strpos( $path, '/runtime/bootstrap.php' );
				}
			) );
			b4_check( 'L2', count( array_unique( $module_included ) ) === 28 && did_action( 'hal_frontend_dashboard_runtime_ready' ) >= 1,
				'exactly 28 runtime module files load (bootstrap aside: 18 through batch 4 + the 8 ajax files of batches 5–6 + the batch-7 infrastructure controller + the batch-11 update bridge) and runtime_ready fires after the full load',
				'unexpected runtime file set: ' . json_encode( $relative ) );

			$signatures = array(
				'wordpress-posts.php'    => array( 'hossam_create_article', 'hossam_update_article', 'hossam_trash_article', 'hossam_restore_article', 'hossam_validate_article_taxonomy_and_thumbnail', 'hossam_restore_article_snapshot', 'hossam_apply_article_taxonomy_and_thumbnail' ),
				'wordpress-uploads.php'  => array( 'hossam_get_allowed_upload_mimes', 'hossam_upload_file' ),
				'woocommerce.php'        => array( 'hossam_wc_is_active', 'hossam_wc_get_orders_data', 'hossam_wc_get_orders_page', 'hossam_wc_get_order_statuses', 'hossam_wc_get_order_status_name', 'hossam_wc_get_currency', 'hossam_wc_format_price', 'hossam_wc_get_products_data', 'hossam_wc_get_available_payment_gateways', 'hossam_wc_get_registered_payment_gateways', 'hossam_wc_get_customer_tokens' ),
				'wpml.php'               => array( 'hossam_wpml_url', 'hossam_wpml_all_paths', 'hossam_login_url', 'hossam_dashboard_url', 'hossam_wpml_url_by_id', 'hossam_wpml_get_active_languages', 'hossam_wpml_get_current_language', 'hossam_wpml_permalink', 'hossam_wpml_is_rtl', 'hossam_wpml_get_post_language', 'hossam_wpml_get_translation_id', 'hossam_wpml_get_element_trid', 'hossam_wpml_get_element_language_code', 'hossam_wpml_set_element_language', 'hossam_resolve_site_section', 'hossam_get_translation_status' ),
				'amelia.php'             => array( 'hossam_amelia_is_active', 'hossam_amelia_valid_date_range', 'hossam_amelia_has_appointments_contract', 'hossam_amelia_log_database_failure', 'hossam_amelia_table_exists', 'hossam_amelia_get_api_key', 'hossam_amelia_api_error', 'hossam_amelia_api_request', 'hossam_amelia_get_health', 'hossam_amelia_invalidate_health', 'hossam_amelia_get_linked_employee_id', 'hossam_amelia_get_upcoming_appointments' ),
				'rankmath.php'           => array( 'hossam_get_rankmath_seo_data', 'hossam_get_allowed_rank_math_robots', 'hossam_update_rankmath_meta', 'hossam_save_rankmath_seo_data' ),
				'ultimatemember.php'     => array(),
				'ai.php'                 => array( 'hossam_ai_wp_client_supported', 'hossam_ai_direct_key_ready', 'hossam_ai_base_strategies', 'hossam_ai_get_strategies', 'hossam_ai_allowed_preferences', 'hossam_ai_get_preference', 'hossam_ai_set_preference', 'hossam_ai_resolve_strategy', 'hossam_ai_interface_available', 'hossam_ai_connector_source_status', 'hossam_ai_allowed_models', 'hossam_ai_get_provider_config', 'hossam_ai_execute_wp_ai_client', 'hossam_ai_execute_direct_key', 'hossam_ai_build_grammar_prompt', 'hossam_ai_build_translation_prompt', 'hossam_ai_build_seo_prompt', 'hossam_ai_build_improvement_prompt', 'hossam_ai_verify_links', 'hossam_ai_verify_facts' ),
			);
			$missing = array();
			foreach ( $signatures as $file => $functions ) {
				foreach ( $functions as $function ) {
					if ( ! function_exists( $function ) ) {
						$missing[] = $file . '::' . $function;
					}
				}
			}
			b4_check( 'L3', array() === $missing,
				'all 8 adapter signature functions are defined after the real load (ultimatemember.php stays empty by documented decision)',
				'missing functions: ' . implode( ', ', $missing ) );

			// Guard contract: every adapter function definition is wrapped in
			// its own function_exists guard; amelia's add_action registrations
			// are the only allowed top-level statements.
			$unguarded = array();
			$top_level_hooks = 0;
			foreach ( glob( $project . '/runtime/adapters/*.php' ) as $adapter_file ) {
				$source = (string) file_get_contents( $adapter_file );
				if ( ! preg_match_all( '/(?:^|\n)[ \t]*(?:(?:if\s*\(\s*!?\s*function_exists\(\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]\s*\)\s*\)\s*\{\n)[^\n]*\n)?[ \t]*function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $matches, PREG_SET_ORDER ) ) {
					continue;
				}
				foreach ( $matches as $match ) {
					$function = $match[2];
					if ( isset( $match[1] ) && '' !== $match[1] ) {
						continue;
					}
					if ( ! preg_match( '/function_exists\(\s*[\'"]' . preg_quote( $function, '/' ) . '[\'"]\s*\)/', $source ) ) {
						$unguarded[] = basename( $adapter_file ) . '::' . $function;
					}
				}
				$top_level_hooks += preg_match_all( '/^[ \t]*add_action\(/m', $source );
			}
			b4_check( 'L4', array() === $unguarded && 4 === $top_level_hooks,
				'every adapter definition is function_exists-guarded; the only top-level statements are amelia\'s 4 health/cache invalidation + auth hook registrations',
				'unguarded: ' . implode( ', ', $unguarded ) . '; top-level add_action count: ' . $top_level_hooks );

			$events_before = count( $GLOBALS['B4_HOOKS'] );
			require_once $project . '/runtime/bootstrap.php';
			b4_check( 'L5', count( $GLOBALS['B4_HOOKS'] ) === $events_before && did_action( 'hal_frontend_dashboard_runtime_ready' ) >= 1,
				'a second require_once of the runtime registers nothing new (require_once idempotency with adapters)',
				'second require changed hook registrations' );

			$ajax_loaded = array_filter( $runtime_included, static function ( string $path ): bool {
				return false !== strpos( $path, '/runtime/ajax/' );
			} );
			$ajax_names = array_map( static function ( string $path ): string {
				return basename( $path );
			}, array_values( $ajax_loaded ) );
			sort( $ajax_names );
			b4_check( 'L6', array( 'ai.php', 'appointments.php', 'finance.php', 'inbox.php', 'members.php', 'posts.php', 'store.php', 'uploads.php' ) === $ajax_names,
				'the runtime loads exactly the eight ajax files of batches 5–6 (posts/uploads + appointments/finance/members/inbox/store/ai) after the adapters and never ajax/seo.php (§16 registry-gated loading)',
				'ajax load set mismatch: ' . json_encode( $ajax_names ) );
			b4_result_line( $mode );
			// no fallthrough

		case 'woocommerce-absent':
			b4_define_release_context();
			b4_require_runtime();
			// No WooCommerce function/class exists in this process.
			b4_check( 'WC1', false === hossam_wc_is_active(), 'with WooCommerce absent the activity gate is false', 'is_active true' );
			b4_check( 'WC2', array() === hossam_wc_get_orders_data( array( 'limit' => 5 ) ) && array( 'orders' => array(), 'total' => 0, 'max_num_pages' => 0 ) === hossam_wc_get_orders_page( array() ), 'order wrappers return empty safe shapes', 'non-empty result' );
			b4_check( 'WC3', array() === hossam_wc_get_order_statuses() && 'processing' === hossam_wc_get_order_status_name( 'processing' ) && '' === hossam_wc_get_currency() && '12.5' === hossam_wc_format_price( 12.5 ), 'status/currency/price wrappers degrade to literal safe values', 'unsafe degradation' );
			b4_check( 'WC4', array() === hossam_wc_get_products_data( array() ) && array() === hossam_wc_get_available_payment_gateways() && array() === hossam_wc_get_registered_payment_gateways() && array() === hossam_wc_get_customer_tokens( 3 ) && array() === hossam_wc_get_customer_tokens( 0 ), 'product/gateway/token wrappers return empty arrays (tokens also for user 0)', 'non-empty result' );
			b4_result_line( $mode );
			// no fallthrough

		case 'wpml-absent':
			b4_define_release_context();
			b4_require_runtime();
			// ICL_SITEPRESS_VERSION is not defined in this process.
			b4_check( 'WP1', array() === hossam_wpml_get_active_languages() && '' === hossam_wpml_get_current_language() && '' === hossam_wpml_get_post_language( 5 ) && 0 === hossam_wpml_get_translation_id( 5, 'ar' ) && 0 === hossam_wpml_get_element_trid( 5 ) && '' === hossam_wpml_get_element_language_code( 5 ) && false === hossam_wpml_set_element_language( 5, 1, 'ar', 'en' ) && array() === hossam_get_translation_status( 5 ), 'every guarded WPML reader returns its safe empty fallback without WPML', 'unsafe fallback' );
			b4_check( 'WP2', array( '/missing-page/' ) === hossam_wpml_all_paths( 'missing-page' ) && 'https://example.test/missing-page/' === hossam_wpml_url( 'missing-page' ), 'URL helpers fall back to the plain slug path without WPML', 'fallback mismatch' );
			b4_check( 'WP3', 'https://example.test/?p=7' === hossam_wpml_permalink( 'https://example.test/?p=7' ) && is_bool( hossam_wpml_is_rtl() ) && hossam_wpml_is_rtl() === is_rtl(), 'permalink helper returns the URL unchanged without the WPML filter; RTL falls back to Core', 'permalink/rtl mismatch' );
			b4_check( 'WP4', 'https://example.test/?p=7' === hossam_wpml_url_by_id( 7 ) && is_string( hossam_login_url() ) && is_string( hossam_dashboard_url() ), 'by-id/url helpers stay string-typed without WPML', 'type mismatch' );
			b4_check( 'WP5', null === hossam_resolve_site_section( 'privacy' ) && is_array( hossam_resolve_site_section( 'home' ) ) && isset( hossam_resolve_site_section( 'home' )['url'] ), 'site-section resolution degrades safely (privacy absent → null; home keeps a URL)', 'site-section mismatch' );
			b4_result_line( $mode );
			// no fallthrough

		case 'amelia-inactive':
			b4_define_release_context();
			// Table-existence probes run inside the inactive paths too.
			$GLOBALS['wpdb'] = new class() {
				public string $prefix = 'wp_';
				public string $last_error = '';
				public function prepare( string $query, ...$args ): string {
					return $query;
				}
				public function get_var( string $query ) {
					return null;
				}
				public function get_results( string $query, $output = null ) {
					return array();
				}
				public function esc_like( string $text ): string {
					return $text;
				}
			};
			b4_require_runtime();
			// Amelia is not active in this process (B4_PLUGIN_ACTIVE=false).
			$GLOBALS['B4_CAPS']['manage_options'] = true;
			define( 'HOSSAM_AMELIA_API_KEY', 'b4-fixture-key' );
			b4_check( 'AM1', false === hossam_amelia_is_active(), 'with Amelia absent the activity gate is false', 'is_active true' );
			$error = hossam_amelia_api_request( '/appointments' );
			b4_check( 'AM2', is_wp_error( $error ) && 'hossam_amelia_plugin_missing' === $error->get_error_code(), 'the API client refuses closed with plugin_missing before any capability/credential work', 'wrong failure' );
			b4_check( 'AM3', null === hossam_amelia_get_linked_employee_id( 3 ) && array() === hossam_amelia_get_upcoming_appointments( 3, false ) && array() === hossam_amelia_get_upcoming_appointments( 3, true ), 'employee lookup and upcoming appointments degrade to null/[] without Amelia', 'unsafe degradation' );
			$health = hossam_amelia_get_health();
			b4_check( 'AM4', is_array( $health ) && false === $health['plugin_active'] && true === $health['api_key_configured'] && 'plugin_missing' === $health['error_code'] && false === $health['api_available'], 'the health shape reports the inactive plugin explicitly (credential state still surfaced)', 'health mismatch: ' . json_encode( $health ) );
			b4_check( 'AM5', true === hossam_amelia_valid_date_range( '2026-09-14,2026-09-15' ) && false === hossam_amelia_valid_date_range( '2026-09-15,2026-09-14' ) && false === hossam_amelia_valid_date_range( '2026-13-01,2026-13-02' ) && false === hossam_amelia_valid_date_range( '2026-09-14' ) && false === hossam_amelia_valid_date_range( 'not-a-date,2026-09-14' ), 'the date-range validator accepts only ordered, real Y-m-d pairs', 'date-range gate mismatch' );
			b4_result_line( $mode );
			// no fallthrough

		case 'amelia-api-contract':
			b4_define_release_context();
			define( 'HOSSAM_AMELIA_API_KEY', 'b4-fixture-key-77' );
			b4_require_runtime();
			$GLOBALS['B4_PLUGIN_ACTIVE'] = array( 'ameliabooking/ameliabooking.php' );
			$GLOBALS['B4_CAPS']['manage_options'] = true;
			// Route/method allowlist.
			b4_check( 'AA1', is_wp_error( hossam_amelia_api_request( '/customers' ) ) && 'hossam_amelia_route_unavailable' === hossam_amelia_api_request( '/customers' )->get_error_code() && is_wp_error( hossam_amelia_api_request( '/appointments', 'POST' ) ) && 'hossam_amelia_route_unavailable' === hossam_amelia_api_request( '/appointments', 'POST' )->get_error_code(), 'the Elite API client accepts GET /appointments only (fixed route allowlist)', 'route allowlist broken' );
			b4_check( 'AA2', is_wp_error( hossam_amelia_api_request( '/appointments', 'GET', array( 'dates' => 'garbage' ) ) ) && 'hossam_amelia_invalid_query' === hossam_amelia_api_request( '/appointments', 'GET', array( 'dates' => 'garbage' ) )->get_error_code() && is_wp_error( hossam_amelia_api_request( '/appointments', 'GET', array( 'skipServices' => 'yes' ) ) ) && 'hossam_amelia_invalid_query' === hossam_amelia_api_request( '/appointments', 'GET', array( 'skipServices' => 'yes' ) )->get_error_code(), 'query validation rejects non-conforming dates and flags closed', 'query gate broken' );
			$GLOBALS['B4_HTTP'] = array(
				'calls' => array(),
				'response' => array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'data' => array( 'appointments' => array() ) ) ) ),
			);
			$result = hossam_amelia_api_request( '/appointments', 'GET', array( 'dates' => '2026-09-14,2026-09-15', 'page' => 2, 'skipServices' => true, 'unknown_key' => 'dropped' ) );
			$call   = $GLOBALS['B4_HTTP']['calls'][0] ?? array();
			b4_check( 'AA3', is_array( $result ) && isset( $result['data']['appointments'] ) && 1 === count( $GLOBALS['B4_HTTP']['calls'] ) && false === strpos( $call['url'] ?? '', 'unknown_key' ) && false !== strpos( $call['url'] ?? '', 'dates=2026-09-14' ) && 'b4-fixture-key-77' === ( $call['args']['headers']['Amelia'] ?? '' ), 'a conforming request reaches exactly one allow-listed read with the sanitized query args and the credential in the header', 'request shape mismatch: ' . json_encode( $call['url'] ?? '' ) );
			b4_check( 'AA4', true === ( $call['args']['sslverify'] ?? false ) && false === strpos( ( $call['url'] ?? '' ), 'b4-fixture-key-77' ) && false === strpos( json_encode( $call['args']['body'] ?? '' ), 'b4-fixture-key-77' ), 'the request is TLS-verified and the credential never rides the URL or body', 'credential placement mismatch' );
		b4_check( 'AA8', 'https' === strtolower( (string) parse_url( (string) ( $call['url'] ?? '' ), PHP_URL_SCHEME ) ) && 0 === ( $call['args']['redirection'] ?? null ) && 65536 === ( $call['args']['limit_response_size'] ?? null ) && 15 === ( $call['args']['timeout'] ?? null ), 'the health transport is HTTPS with zero redirects, a 64KB response bound and the documented timeout', 'transport guards mismatch: ' . json_encode( $call['args'] ?? array() ) );
			$GLOBALS['B4_HTTP']['response'] = array( 'response' => array( 'code' => 401 ), 'body' => '' );
			update_option( '_transient_hossam_integration_capabilities_v1_' . HAL_FRONTEND_DASHBOARD_RELEASE_ID, array( 'stale' => true ), false );
			$rejected = hossam_amelia_api_request( '/appointments' );
			b4_check( 'AA5', is_wp_error( $rejected ) && 'hossam_amelia_credentials_rejected' === $rejected->get_error_code() && ! isset( $GLOBALS['B4_OPTIONS'][ '_transient_hossam_amelia_api_health_v1' ] ) && ! isset( $GLOBALS['B4_OPTIONS'][ '_transient_hossam_integration_capabilities_v1_' . HAL_FRONTEND_DASHBOARD_RELEASE_ID ] ), '401/403 invalidate the health cache and the integration capability cache and report credentials_rejected', 'rejection handling mismatch' );
			$GLOBALS['B4_HTTP']['response'] = array( 'response' => array( 'code' => 500 ), 'body' => 'boom' );
			b4_check( 'AA6', is_wp_error( hossam_amelia_api_request( '/appointments' ) ) && 'hossam_amelia_remote_failure' === hossam_amelia_api_request( '/appointments' )->get_error_code(), 'a 5xx response is a sanitized remote failure (no raw body disclosure)', '5xx handling mismatch' );
		$GLOBALS['B4_HTTP']['response'] = array( 'response' => array( 'code' => 200 ), 'body' => 'not-json' );
		b4_check( 'AA7', is_wp_error( hossam_amelia_api_request( '/appointments' ) ) && 'hossam_amelia_remote_failure' === hossam_amelia_api_request( '/appointments' )->get_error_code(), 'a malformed body fails closed through the response contract check', 'contract check missing' );
		b4_result_line( $mode );
		// no fallthrough

		case 'amelia-transport-guards':
			// B4-01 closure: non-HTTPS transport is refused before any HTTP
			// call; the health path never emits a request without its guards.
			b4_define_release_context();
			define( 'HOSSAM_AMELIA_API_KEY', 'b4-fixture-key-78' );
			b4_require_runtime();
			$GLOBALS['B4_PLUGIN_ACTIVE'] = array( 'ameliabooking/ameliabooking.php' );
			$GLOBALS['B4_CAPS']['manage_options'] = true;
			$GLOBALS['B4_HTTP'] = array( 'calls' => array() );
			$GLOBALS['B4_ADMIN_URL'] = 'http://example.test/wp-admin/';
			$refused = hossam_amelia_api_request( '/appointments' );
			b4_check( 'AA9', is_wp_error( $refused ) && 'hossam_amelia_remote_failure' === $refused->get_error_code() && 0 === count( $GLOBALS['B4_HTTP']['calls'] ), 'a non-HTTPS transport URL is refused safely with zero HTTP calls', 'http refusal mismatch' );
			$GLOBALS['B4_ADMIN_URL'] = 'https://example.test/wp-admin/';
			$GLOBALS['B4_HTTP'] = array(
				'calls' => array(),
				'response' => array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'data' => array( 'appointments' => array() ) ) ) ),
			);
			$allowed = hossam_amelia_api_request( '/appointments' );
			$guarded_call = $GLOBALS['B4_HTTP']['calls'][0] ?? array();
			b4_check( 'AA10', is_array( $allowed ) && 1 === count( $GLOBALS['B4_HTTP']['calls'] ) && 0 === ( $guarded_call['args']['redirection'] ?? null ) && 65536 === ( $guarded_call['args']['limit_response_size'] ?? null ), 'the HTTPS transport carries zero redirects and the 64KB bound on the real call', 'guarded call mismatch' );
			b4_result_line( $mode );
			// no fallthrough

		case 'amelia-credentials':
			// The batch-3 resolver precedence through the REAL registry:
			// none → hal_encrypted → constant (constant outranks encrypted).
			b4_define_release_context();
			define( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY', b4_valid_key_hex( 'cred' ) );
			b4_require_runtime();
			b4_check( 'AC1', '' === hossam_amelia_get_api_key(), 'with no source at all the resolver returns an empty key', 'unexpected key' );
			HAL_Frontend_Dashboard_Secret_Store::set( 'amelia_elite_api_key', 'b4-encrypted-key-55' );
			b4_check( 'AC2', 'b4-encrypted-key-55' === hossam_amelia_get_api_key(), 'the encrypted fallback is consumed through the real store', 'encrypted path mismatch' );
			define( 'HOSSAM_AMELIA_API_KEY', 'b4-constant-key-11' );
			b4_check( 'AC3', 'b4-constant-key-11' === hossam_amelia_get_api_key() && 'constant' === HAL_Frontend_Dashboard_Integration_Settings_Registry::credential_source( 'amelia_elite_api_key' ), 'the documented constant outranks the encrypted fallback (owner decision §2.4 order)', 'precedence mismatch' );
			b4_result_line( $mode );
			// no fallthrough

		case 'amelia-credentials-legacy-fallback':
			// Standalone adapter load (batch-3 layer absent): the documented
			// constant fallback path keeps working.
			if ( ! defined( 'ABSPATH' ) ) {
				define( 'ABSPATH', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'b4-solo-' . getmypid() . DIRECTORY_SEPARATOR );
			}
			require $project . '/runtime/adapters/amelia.php';
			b4_check( 'AL1', '' === hossam_amelia_get_api_key(), 'without any layer the adapter-only load returns an empty key', 'unexpected key' );
			define( 'HOSSAM_AMELIA_API_KEY', 'b4-legacy-constant-3' );
			b4_check( 'AL2', 'b4-legacy-constant-3' === hossam_amelia_get_api_key(), 'the documented constant fallback works when the batch-3 layer is absent (guarded branch)', 'fallback mismatch' );
			b4_result_line( $mode );
			// no fallthrough

		case 'rankmath-contract':
			b4_define_release_context();
			b4_require_runtime();
			$GLOBALS['B4_MEDIA']['meta'][9] = array(
				'rank_math_seo_score'     => '77',
				'rank_math_focus_keyword' => 'lawyer, attorney',
				'rank_math_description'   => 'Legal description',
				'rank_math_robots'        => array( 'index', 'follow' ),
			);
			$data = hossam_get_rankmath_seo_data( 9 );
			b4_check( 'RM1', 77 === $data['seo_score'] && 'lawyer, attorney' === $data['focus_keyword'] && 'Legal description' === $data['description'] && array( 'index', 'follow' ) === $data['robots'], 'the read contract returns exactly the four verified fields', 'read mismatch: ' . json_encode( $data ) );
			b4_check( 'RM2', array( 'index', 'noindex', 'follow', 'nofollow' ) === hossam_get_allowed_rank_math_robots(), 'the robots allow-list is the closed four-value base', 'allowlist mismatch' );
			b4_check( 'RM3', true === hossam_save_rankmath_seo_data( 9, array( 'focus_keyword' => 'court', 'description' => 'Desc', 'robots' => array( 'noindex', 'garbage' ) ) ) && array( 'noindex' ) === $GLOBALS['B4_MEDIA']['meta'][9]['rank_math_robots'] && 'court' === $GLOBALS['B4_MEDIA']['meta'][9]['rank_math_focus_keyword'], 'unknown robots values are dropped while the valid ones save', 'robots filtering mismatch' );
			b4_check( 'RM4', false === hossam_save_rankmath_seo_data( 9, array( 'robots' => array( 'garbage' ) ) ) && false === hossam_save_rankmath_seo_data( 9, array( 'robots' => 'index' ) ) && false === hossam_save_rankmath_seo_data( 9, array( 'robots' => array( 'index' ), 'description' => 42 ) ) && false === hossam_save_rankmath_seo_data( 9, array( 'robots' => array( 5 ) ) ), 'a non-allowlisted set, non-array robots, non-string fields or non-string members all fail closed without writing', 'write rejection mismatch' );
			$GLOBALS['B4_MEDIA']['meta'][9]['rank_math_description'] = 'same';
			b4_check( 'RM5', true === hossam_update_rankmath_meta( 9, 'rank_math_description', 'same' ) && true === hossam_save_rankmath_seo_data( 9, array( 'robots' => array( 'index' ) ) ), 'an unchanged value short-circuits to success (update_option false is ambiguous)', 'same-value mismatch' );
			b4_result_line( $mode );
			// no fallthrough

		case 'uploads-contract':
			b4_define_release_context();
			// Empty wp-admin include stubs so the adapter's explicit requires resolve.
			foreach ( array( 'file.php', 'image.php', 'media.php' ) as $include ) {
				$dir = ABSPATH . 'wp-admin/includes';
				if ( ! is_dir( $dir ) ) {
					@mkdir( $dir, 0777, true );
				}
				$path = $dir . '/' . $include;
				if ( ! file_exists( $path ) ) {
					file_put_contents( $path, "<?php\n" );
				}
			}
			b4_require_runtime();
			$GLOBALS['B4_CAPS']['upload_files'] = false;
			$error = hossam_upload_file();
			b4_check( 'UP1', is_wp_error( $error ) && 'hossam_forbidden' === $error->get_error_code(), 'without upload_files the upload refuses closed', 'capability gate missing' );
			$GLOBALS['B4_CAPS']['upload_files'] = true;
			$error = hossam_upload_file();
			b4_check( 'UP2', is_wp_error( $error ) && 'hossam_no_file' === $error->get_error_code(), 'without a file field the upload reports no_file', 'no-file gate missing' );
			$GLOBALS['B4_SITE_MIMES'] = array( 'png' => 'image/png', 'jpg|jpeg' => 'image/jpeg' );
			$_FILES = array( 'file' => array( 'name' => 'document.exe', 'tmp_name' => '/tmp/x', 'size' => 10 ) );
			$error  = hossam_upload_file();
			b4_check( 'UP3', is_wp_error( $error ) && 'hossam_invalid_file_type' === $error->get_error_code(), 'a file type outside the effective policy is rejected before any handler runs', 'type gate missing' );
			$_FILES = array( 'file' => array( 'name' => 'photo.png', 'tmp_name' => '/tmp/x', 'size' => 10 ) );
			$GLOBALS['B4_UPLOAD'] = array( 'result' => 4242, 'calls' => 0 );
			$result = hossam_upload_file();
			$hook_entries = $GLOBALS['B4_HOOKS']['upload_mimes'] ?? array();
			b4_check( 'UP4', 4242 === $result && 1 === $GLOBALS['B4_UPLOAD']['calls'] && array() === $hook_entries, 'a conforming upload reaches media_handle_upload once and the MIME-restricting filter is removed afterwards', 'upload path mismatch' );
			$GLOBALS['B4_SITE_MIMES'] = array( 'png' => 'image/png', 'jpg|jpeg' => 'image/jpeg', 'exe' => 'application/x-msdownload' );
			$GLOBALS['B4_HOOKS']['hossam_uploads_allowed_mimes'] = array( array( 'type' => 'filter', 'callback' => static function ( array $policy ): array { return array( 'png' => 'image/png' ); }, 'priority' => 10 ) );
			$effective = hossam_get_allowed_upload_mimes();
			b4_check( 'UP5', array( 'png' => 'image/png' ) === $effective, 'the project filter narrows the site policy and can never expand it (intersection)', 'intersection mismatch' );
			$_FILES = array();
			b4_result_line( $mode );
			// no fallthrough

		case 'posts-contract':
			b4_define_release_context();
			b4_require_runtime();
			$GLOBALS['B4_TERMS']['category'] = array( 11, 12 );
			$GLOBALS['B4_MEDIA']['image_ids'] = array( 33, 34 );

			$GLOBALS['B4_CAPS'] = array();
			$error = hossam_create_article( array( 'title' => 'T' ) );
			b4_check( 'PS1', is_wp_error( $error ) && 'hossam_forbidden' === $error->get_error_code(), 'creation without edit_posts refuses closed', 'capability gate missing' );

			$GLOBALS['B4_CAPS'] = array( 'edit_posts' => true );
			$error = hossam_create_article( array( 'title' => 'T', 'status' => 'publish' ) );
			b4_check( 'PS2', is_wp_error( $error ) && 'hossam_forbidden' === $error->get_error_code(), 'publishing without publish_posts refuses closed even for an editor of posts', 'publish gate missing' );

			$error = hossam_create_article( array( 'title' => 'T', 'categories' => array( 11, 99 ) ) );
			b4_check( 'PS3', is_wp_error( $error ) && 'hossam_invalid_categories' === $error->get_error_code(), 'a nonexistent term id rejects the article before creation', 'category validation missing' );

			$GLOBALS['B4_CAPS'] = array( 'edit_posts' => true, 'publish_posts' => true, 'edit_post_33' => true );
			$post_id = hossam_create_article( array( 'title' => 'New', 'status' => 'publish', 'content' => 'C', 'categories' => array( 12 ), 'thumbnail_id' => 33 ) );
			b4_check( 'PS4', is_int( $post_id ) && 'publish' === $GLOBALS['B4_POSTS'][ $post_id ]->post_status && array( 12 ) === ( $GLOBALS['B4_POST_CATEGORIES'][ $post_id ] ?? array() ) && 33 === ( $GLOBALS['B4_POST_THUMBNAILS'][ $post_id ] ?? 0 ), 'a fully valid creation persists status, categories and the verified image thumbnail', 'creation mismatch' );

			$error = hossam_create_article( array( 'title' => 'T', 'thumbnail_id' => 99 ) );
			b4_check( 'PS5', is_wp_error( $error ) && 'hossam_invalid_thumbnail' === $error->get_error_code(), 'a non-image thumbnail id rejects the article', 'thumbnail validation missing' );

			$GLOBALS['B4_CAPS'] = array( 'edit_posts' => true );
			$error = hossam_update_article( 999999, array( 'title' => 'X' ) );
			b4_check( 'PS6', is_wp_error( $error ) && 'hossam_not_found' === $error->get_error_code(), 'updating a missing article reports not_found', 'not-found mismatch' );

			$GLOBALS['B4_CAPS'] = array( 'edit_posts' => true, 'publish_posts' => true, 'edit_post_33' => false, 'edit_post_' . $post_id => true );
			$error = hossam_update_article( $post_id, array( 'title' => 'X2', 'thumbnail_id' => 33 ) );
			b4_check( 'PS7', is_wp_error( $error ) && 'hossam_thumbnail_forbidden' === $error->get_error_code(), 'an attachment the user cannot edit is rejected as thumbnail_forbidden (the article itself stays editable)', 'attachment ownership gate missing: ' . json_encode( is_wp_error( $error ) ? $error->get_error_code() : $error ) );

			$GLOBALS['B4_CAPS'] = array( 'edit_posts' => true, 'publish_posts' => true, 'edit_post_33' => true, 'edit_post_34' => true, 'edit_post_' . $post_id => true );
			$before_title = $GLOBALS['B4_POSTS'][ $post_id ]->post_title;
			// A DIFFERENT thumbnail id: re-setting the current one cannot
			// produce a genuine write failure (the resulting state matches).
			$GLOBALS['B4_POSTS_FAIL_THUMBNAIL'] = true;
			$error = hossam_update_article( $post_id, array( 'title' => 'X3', 'thumbnail_id' => 34 ) );
			$GLOBALS['B4_POSTS_FAIL_THUMBNAIL'] = false;
			b4_check( 'PS8', is_wp_error( $error ) && 'hossam_related_write_failed' === $error->get_error_code() && $before_title === $GLOBALS['B4_POSTS'][ $post_id ]->post_title && true === ( $error->get_error_data()['compensated'] ?? false ), 'a failing related write is compensated from the snapshot (title restored) and reported as related_write_failed — never a silent success', 'compensation mismatch: ' . json_encode( is_wp_error( $error ) ? $error->get_error_data() : $error ) );

			$GLOBALS['B4_POSTS_FAIL_UPDATE'] = true;
			$error = hossam_update_article( $post_id, array( 'title' => 'X4' ) );
			$GLOBALS['B4_POSTS_FAIL_UPDATE'] = false;
			b4_check( 'PS9', is_wp_error( $error ) && 'b4_update_failed' === $error->get_error_code(), 'a failing main write propagates the Core error as-is (no false success)', 'update failure mismatch' );

			$GLOBALS['B4_CAPS'] = array( 'edit_posts' => true, 'edit_post_' . $post_id => true );
			$error = hossam_trash_article( 888888 );
			b4_check( 'PS10', is_wp_error( $error ) && 'hossam_not_found' === $error->get_error_code() && true === hossam_trash_article( $post_id ) && 'trash' === $GLOBALS['B4_POSTS'][ $post_id ]->post_status && true === hossam_restore_article( $post_id ) && 'draft' === $GLOBALS['B4_POSTS'][ $post_id ]->post_status, 'trash/restore respect edit_post on the real post and move its status only', 'trash/restore mismatch' );
			b4_result_line( $mode );
			// no fallthrough

		case 'ai-strategies-unprofiled':
			// No runtime profile at all: the registry cannot execute.
			b4_define_release_context();
			define( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY', b4_valid_key_hex( 'ai' ) );
			b4_require_runtime();
			$error = hossam_ai_resolve_strategy();
			b4_check( 'AS1', is_wp_error( $error ) && 'hossam_ai_unavailable' === $error->get_error_code() && array() === hossam_ai_get_strategies() && false === hossam_ai_interface_available(), 'without the environment-owned runtime profile the registry is empty and every resolution fails closed', 'profile gate missing' );
			b4_result_line( $mode );
			// no fallthrough

		case 'ai-strategies':
			// Registry/resolve fail-closed matrix (unified reference §7.4).
			// The profile constant is defined BEFORE the runtime loads:
			// hossam_ai_get_runtime_profile() caches its snapshot statically,
			// so a later define would be dead.
			b4_define_release_context();
			define( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY', b4_valid_key_hex( 'ai' ) );
			b4_define_valid_profile();
			b4_require_runtime();
			// Profile present, no provider configured: auto fails with no_provider.
			$error = hossam_ai_resolve_strategy();
			b4_check( 'AS2', is_wp_error( $error ) && 'hossam_ai_no_provider' === $error->get_error_code() && 'auto' === hossam_ai_get_preference(), 'with a profile but no provider the auto preference fails closed with no_provider (repository default is auto)', 'auto resolution mismatch' );

			// Explicit wp_ai_client preference without a supported client.
			HAL_Frontend_Dashboard_Settings_Repository::save( array( 'ai_preference' => 'wp_ai_client' ) );
			$error = hossam_ai_resolve_strategy();
			b4_check( 'AS3', is_wp_error( $error ) && 'hossam_ai_unavailable' === $error->get_error_code(), 'the explicit wp_ai_client preference fails safe without support and never falls back to direct_key', 'fallback violation' );

			// Supported client + configured provider + stored credential.
			eval( 'function wp_ai_client_prompt( $text ) { return new B4_AiClient_Fixture( true ); }' );
			define( 'HOSSAM_AI_PROVIDER', 'gemini' );
			HAL_Frontend_Dashboard_Secret_Store::set( 'ai_direct_key', 'b4-direct-key-42' );
			b4_check( 'AS4', true === hossam_ai_wp_client_supported() && true === hossam_ai_direct_key_ready() && 'wp_ai_client' === hossam_ai_resolve_strategy() && true === hossam_ai_interface_available(), 'auto prefers a supported WP AI Client and resolves it (interface available)', 'auto preference mismatch' );

		HAL_Frontend_Dashboard_Settings_Repository::save( array( 'ai_preference' => 'direct_key' ) );
		$explicit = hossam_ai_resolve_strategy();
		b4_check( 'AS5', is_wp_error( $explicit ) && 'hossam_ai_direct_key_not_fallback' === $explicit->get_error_code() && 'direct_key' === HAL_Frontend_Dashboard_Settings_Repository::get_ai_preference(), 'the explicit direct_key preference is refused with a clear fallback-only error while a WP AI Client capability is available (no silent switch to another provider)', 'fallback-only gate missing: ' . var_export( is_wp_error( $explicit ) ? $explicit->get_error_code() : $explicit, true ) );
		$connector_status = hossam_ai_connector_source_status();
		b4_check( 'CS1', true === ( $connector_status['capable'] ?? null ) && 'undetermined' === ( $connector_status['source'] ?? null ) && 'no_source_contract' === ( $connector_status['reason'] ?? null ), 'with a supported client the connector source status reports capability with a precise undetermined-source reason (no invented environment/constant names)', 'connector status mismatch: ' . json_encode( $connector_status ) );
		b4_check( 'CS2', false === strpos( json_encode( $connector_status ), 'b4-direct-key-42' ), 'the connector source status carries no key material', 'secret leaked into status' );

			HAL_Frontend_Dashboard_Secret_Store::delete( 'ai_direct_key' );
			b4_check( 'AS6', false === hossam_ai_direct_key_ready() && is_wp_error( hossam_ai_resolve_strategy( 'direct_key' ) ), 'direct_key without its stored credential is not ready and fails closed (the monitor reports the missing credential separately)', 'credential gate missing' );
			b4_check( 'AS7', array( 'auto', 'wp_ai_client', 'direct_key' ) === hossam_ai_allowed_preferences() && false === hossam_ai_set_preference( 'weird' ) && true === hossam_ai_set_preference( 'direct_key' ) && 'direct_key' === HAL_Frontend_Dashboard_Settings_Repository::get_ai_preference(), 'the preference enum is closed and writes go through the Settings Repository (single write path)', 'preference contract mismatch' );
			b4_result_line( $mode );
			// no fallthrough

		case 'ai-execution':
			// Real executor paths with the credential-placement and
			// sanitized-failure contracts.
			if ( ! defined( 'ABSPATH' ) ) {
				define( 'ABSPATH', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'b4-exec-' . getmypid() . DIRECTORY_SEPARATOR );
			}
			require $project . '/runtime/core/setup.php'; // hossam_ai_get_runtime_profile only.
			require $project . '/runtime/adapters/ai.php'; // the isolated adapter under test.
			b4_define_valid_profile();
			define( 'HOSSAM_AI_PROVIDER', 'gemini' );
			define( 'HOSSAM_AI_MODEL', 'gemini-1.5-flash' );
			// This isolated process intentionally has NO batch-3 classes: the
			// adapter's credential read must fail closed without them — the
			// registry-backed read is proven in the runtime modes.
			$error = hossam_ai_get_provider_config();
			b4_check( 'AE1', is_wp_error( $error ) && 'hossam_ai_not_configured' === $error->get_error_code(), 'without the store class the credential read fails closed (no plaintext path anywhere)', 'isolated read mismatch' );
			$ready = hossam_ai_direct_key_ready();
			b4_check( 'AE2', false === $ready, 'direct_key readiness requires the store class (absent here) — false', 'readiness mismatch' );

			$wp_client_error = hossam_ai_execute_wp_ai_client( 'p' );
			b4_check( 'AE3', is_wp_error( $wp_client_error ) && 'hossam_ai_wp_client_unavailable' === $wp_client_error->get_error_code(), 'the WP AI Client executor fails safe when the capability probe is absent', 'executor mismatch' );
			b4_check( 'AE4', is_wp_error( hossam_ai_verify_facts( 'claim' ) ) && 'hossam_ai_not_implemented' === hossam_ai_verify_facts( 'claim' )->get_error_code(), 'live fact verification stays the documented not_implemented expansion', 'facts contract mismatch' );
			$b4_fixture_body = json_encode( array(
				'candidates' => array(
					array(
						'content' => array(
							'parts' => array(
								array( 'text' => '  result text  ' ),
							),
						),
					),
				),
			) );
			$GLOBALS['B4_HTTP'] = array(
				'calls'    => array(),
				'response' => array( 'response' => array( 'code' => 200 ), 'body' => $b4_fixture_body ),
			);
			$GLOBALS['B4_HOOKS']['hal_provider_config_double'] = array();
			// Registry-backed config through a tiny local double of the store
			// read: define the batch-3 class-equivalent constants is not
			// possible — instead assert the pure executor against a fixture
			// config via the real runtime modes. Here: verify_links + prompts.
			$GLOBALS['B4_HTTP'] = array(
				'calls' => array(),
				'head'  => array( 'https://example.test/ok' => 200, 'https://example.test/fail' => 500 ),
			);
			$links = hossam_ai_verify_links( array( 'https://example.test/ok', 'notaurl', 'https://example.test/fail' ) );
			b4_check( 'AE5', true === ( $links['https://example.test/ok']['valid'] ?? null ) && false === ( $links['https://example.test/fail']['valid'] ?? null ) && ! array_key_exists( 'notaurl', $links ) && 2 === count( $GLOBALS['B4_HTTP']['calls'] ), 'link verification drops URLs that fail esc_url_raw and reports valid/status per remaining link (HEAD calls only for real URLs)', 'link verification mismatch: ' . json_encode( $links ) );
			$prompt = hossam_ai_build_seo_prompt( 'T', 'C' );
			b4_check( 'AE6', false !== strpos( $prompt, 'T' ) && false !== strpos( $prompt, 'focus_keyword' ) && is_string( hossam_ai_build_grammar_prompt( 'x' ) ) && is_string( hossam_ai_build_translation_prompt( 'x', 'ar' ) ) && is_string( hossam_ai_build_improvement_prompt( 'x' ) ), 'the four prompt builders return deterministic local strings (no network)', 'prompt builder mismatch' );
			b4_result_line( $mode );
			// no fallthrough

		case 'ai-execution-runtime':
			// The registry-backed direct_key execution inside the full runtime
			// (store + provider + profile): key placement, sanitized errors,
			// and the no-leak log contract.
			$log_file = $args[0] ?? '';
			b4_define_release_context();
			define( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY', b4_valid_key_hex( 'exec' ) );
			b4_define_valid_profile();
			define( 'HOSSAM_AI_PROVIDER', 'gemini' );
			define( 'HOSSAM_AI_MODEL', 'gemini-1.5-flash' );
			b4_require_runtime();
			HAL_Frontend_Dashboard_Secret_Store::set( 'ai_direct_key', 'B4-FIXTURE-SECRET-9f3' );
			b4_check( 'AE7', is_array( hossam_ai_get_provider_config() ) && 'gemini' === hossam_ai_get_provider_config()['provider'] && 'gemini-1.5-flash' === hossam_ai_get_provider_config()['model'], 'the registry-backed config reads provider/model from the documented constants and the key from the store (model on the fixed allowlist)', 'config mismatch' );

			$b4_fixture_body = json_encode( array(
				'candidates' => array(
					array(
						'content' => array(
							'parts' => array(
								array( 'text' => '  generated  ' ),
							),
						),
					),
				),
			) );
			$GLOBALS['B4_HTTP'] = array(
				'calls'    => array(),
				'response' => array( 'response' => array( 'code' => 200 ), 'body' => $b4_fixture_body ),
			);
			$result = hossam_ai_execute_direct_key( 'prompt text' );
			$call   = $GLOBALS['B4_HTTP']['calls'][0] ?? array();
			b4_check( 'AE8', 'generated' === $result && 'POST' === $call['verb'] && false !== strpos( $call['url'], 'gemini-1.5-flash' ) && 'B4-FIXTURE-SECRET-9f3' === ( $call['args']['headers']['x-goog-api-key'] ?? '' ) && false === strpos( $call['url'], 'B4-FIXTURE-SECRET-9f3' ) && false === strpos( (string) ( $call['args']['body'] ?? '' ), 'B4-FIXTURE-SECRET-9f3' ), 'a successful direct_key call sends the credential only in the header (never URL/body) and trims the result', 'credential placement mismatch: ' . json_encode( array( 'url' => $call['url'] ?? '', 'body' => $call['args']['body'] ?? '' ) ) );
			b4_check( 'AE9', 90.0 === (float) ( $call['args']['timeout'] ?? 0 ), 'the executor timeout comes from the runtime profile (direct_key_timeout)', 'timeout mismatch' );

			if ( '' !== $log_file ) {
				ini_set( 'error_log', $log_file );
			}
			$GLOBALS['B4_HTTP']['response'] = array( 'response' => array( 'code' => 500 ), 'body' => 'raw-provider-body' );
			$error = hossam_ai_execute_direct_key( 'prompt text' );
			$log_contents = '' !== $log_file && file_exists( $log_file ) ? (string) file_get_contents( $log_file ) : '';
			b4_check( 'AE10', is_wp_error( $error ) && 'hossam_ai_http_error' === $error->get_error_code() && false !== strpos( $error->get_error_message(), '500' ) && false === strpos( $error->get_error_message(), 'raw-provider-body' ) && false === strpos( json_encode( $error->get_error_data() ), 'B4-FIXTURE-SECRET-9f3' ) && false === strpos( $log_contents, 'B4-FIXTURE-SECRET-9f3' ) && false === strpos( $log_contents, 'raw-provider-body' ), 'a 5xx failure reports the sanitized status code only — no raw provider body, no credential in error data or logs', 'error sanitization mismatch' );

			$GLOBALS['B4_HTTP']['wp_error'] = new WP_Error( 'http_failure', 'transport' );
			$error = hossam_ai_execute_direct_key( 'prompt text' );
			b4_check( 'AE11', is_wp_error( $error ) && 'hossam_ai_request_failed' === $error->get_error_code(), 'a transport failure is a sanitized request_failed error', 'transport mismatch' );

			$GLOBALS['B4_HTTP'] = array(
				'calls' => array(),
				'response' => array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'promptFeedback' => array( 'blockReason' => 'SAFETY Block!' ) ) ) ),
			);
			$GLOBALS['B4_HTTP']['wp_error'] = null;
			$error = hossam_ai_execute_direct_key( 'prompt text' );
			b4_check( 'AE12', is_wp_error( $error ) && 'hossam_ai_unexpected_response' === $error->get_error_code() && false !== strpos( $error->get_error_message(), 'safetyblock' ) && false === strpos( $error->get_error_message(), 'SAFETY Block' ), 'a blocked response reports the sanitized block reason key (WordPress sanitize_key strips spaces/case — no raw reason text)', 'block reason mismatch: ' . json_encode( is_wp_error( $error ) ? $error->get_error_message() : $error ) );

			$GLOBALS['B4_HTTP']['response'] = array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'candidates' => array() ) ) );
			$error = hossam_ai_execute_direct_key( 'prompt text' );
			b4_check( 'AE13', is_wp_error( $error ) && 'hossam_ai_unexpected_response' === $error->get_error_code(), 'a response without the content field fails closed as unexpected_response', 'missing-content mismatch' );
			b4_result_line( $mode );
			// no fallthrough

		case 'ai-provider-allowlist':
			// Legacy allowlist parity: only the documented gemini provider is
			// direct_key-ready; anything else fails closed at resolution.
			b4_define_release_context();
			define( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY', b4_valid_key_hex( 'prov' ) );
			b4_define_valid_profile();
			define( 'HOSSAM_AI_PROVIDER', 'openai' );
			b4_require_runtime();
			HAL_Frontend_Dashboard_Secret_Store::set( 'ai_direct_key', 'b4-prov-secret' );
		b4_check( 'AV1', false === hossam_ai_direct_key_ready() && is_wp_error( hossam_ai_resolve_strategy( 'direct_key' ) ), 'a non-allowlisted provider is not direct_key-ready and the strategy fails closed at resolution (no fallback to any other provider)', 'provider allowlist mismatch: ' . json_encode( hossam_ai_resolve_strategy( 'direct_key' ) ) );
		b4_result_line( $mode );
		// no fallthrough

		case 'ai-direct-fallback':
			// B4-02 allowed path: with NO WP AI Client capability, the
			// explicit direct_key preference and auto both resolve to the
			// encrypted fallback; the connector status reports no capability.
			b4_define_release_context();
			define( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY', b4_valid_key_hex( 'fb' ) );
			b4_define_valid_profile();
			define( 'HOSSAM_AI_PROVIDER', 'gemini' );
			b4_require_runtime();
			HAL_Frontend_Dashboard_Secret_Store::set( 'ai_direct_key', 'b4-fallback-key-43' );
			HAL_Frontend_Dashboard_Settings_Repository::save( array( 'ai_preference' => 'direct_key' ) );
			b4_check( 'AS8', false === hossam_ai_wp_client_supported() && 'direct_key' === hossam_ai_resolve_strategy() && 'direct_key' === hossam_ai_resolve_strategy( 'auto' ), 'without a client capability the explicit direct_key preference and auto both resolve to the encrypted fallback', 'fallback path mismatch' );
		$fallback_status = hossam_ai_connector_source_status();
		b4_check( 'CS3', false === ( $fallback_status['capable'] ?? null ) && 'none' === ( $fallback_status['source'] ?? null ) && 'wp_ai_client_unsupported' === ( $fallback_status['reason'] ?? null ), 'without a client capability the connector source status reports none with its precise reason', 'connector status mismatch: ' . json_encode( $fallback_status ) );
		$fallback_render = new ReflectionMethod( 'HAL_Frontend_Dashboard_Admin_Controller', 'render_integration_rows' );
		$fallback_render->setAccessible( true );
		$fallback_credential = '';
		foreach ( (array) $fallback_render->invoke( null ) as $fallback_row ) {
			if ( 'AI' === ( $fallback_row['label'] ?? '' ) ) {
				$fallback_credential = (string) ( $fallback_row['credential'] ?? '' );
			}
		}
		b4_check( 'CS6', false !== strpos( $fallback_credential, 'hal_encrypted' ) && false === strpos( $fallback_credential, 'connector:' ), 'without a client capability the AI diagnosis row shows only the fallback credential source with no connector suffix', 'diagnosis suffix mismatch: ' . $fallback_credential );
			b4_result_line( $mode );
			// no fallthrough

		case 'ai-model-allowlist':
			// B4-04 closure: the fixed model allowlist accepts the listed
			// model and rejects anything else before any connection.
			b4_define_release_context();
			define( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY', b4_valid_key_hex( 'mod' ) );
			b4_define_valid_profile();
			define( 'HOSSAM_AI_PROVIDER', 'gemini' );
			define( 'HOSSAM_AI_MODEL', 'unlisted-model-x' );
			b4_require_runtime();
			HAL_Frontend_Dashboard_Secret_Store::set( 'ai_direct_key', 'b4-model-key-44' );
			b4_check( 'AM1', array( 'gemini-1.5-flash' ) === hossam_ai_allowed_models(), 'the approved model list is fixed in code', 'allowlist mismatch: ' . json_encode( hossam_ai_allowed_models() ) );
			$model_error = hossam_ai_get_provider_config();
			b4_check( 'AM2', is_wp_error( $model_error ) && 'hossam_ai_unsupported_model' === $model_error->get_error_code(), 'an unlisted model is refused before any connection', 'model gate missing' );
			$GLOBALS['B4_HTTP'] = array( 'calls' => array() );
			$model_exec = hossam_ai_execute_direct_key( 'prompt text' );
		b4_check( 'AM3', is_wp_error( $model_exec ) && 'hossam_ai_unsupported_model' === $model_exec->get_error_code() && 0 === count( $GLOBALS['B4_HTTP']['calls'] ), 'the executor rejects the unlisted model with zero HTTP calls', 'executor model gate missing' );
		b4_result_line( $mode );
		// no fallthrough

		case 'ai-connector-reach':
			// B4-03 reach: the real admin diagnosis rows consume the real
			// connector source status (reflection into the real private
			// renderer — no logic reimplemented in the test).
			b4_define_release_context();
			define( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY', b4_valid_key_hex( 'reach' ) );
			b4_define_valid_profile();
			define( 'HOSSAM_AI_PROVIDER', 'gemini' );
			b4_require_runtime();
			HAL_Frontend_Dashboard_Secret_Store::set( 'ai_direct_key', 'b4-reach-key-45' );
			eval( 'function wp_ai_client_prompt( $text ) { return new B4_AiClient_Fixture( true ); }' );
			$status_before = hossam_ai_connector_source_status();
			HAL_Frontend_Dashboard_Secret_Store::set( 'ai_direct_key', 'b4-reach-key-46-changed' );
			$status_after = hossam_ai_connector_source_status();
			b4_check( 'CS4', $status_before === $status_after && 'undetermined' === ( $status_before['source'] ?? null ) && 'no_source_contract' === ( $status_before['reason'] ?? null ) && false === strpos( json_encode( $status_before ), 'b4-reach-key' ), 'the connector source status is independent of stored secrets and carries no key material', 'status sensitivity mismatch: ' . json_encode( $status_before ) );
			$render_rows = new ReflectionMethod( 'HAL_Frontend_Dashboard_Admin_Controller', 'render_integration_rows' );
			$render_rows->setAccessible( true );
			$diagnosis_rows = $render_rows->invoke( null );
			$ai_credential = '';
			foreach ( (array) $diagnosis_rows as $diagnosis_row ) {
				if ( 'AI' === ( $diagnosis_row['label'] ?? '' ) ) {
					$ai_credential = (string) ( $diagnosis_row['credential'] ?? '' );
				}
			}
			b4_check( 'CS5', false !== strpos( $ai_credential, 'connector:undetermined (no_source_contract)' ), 'the real admin diagnosis row for AI displays the connector source status with its precise reason', 'diagnosis reach mismatch: ' . $ai_credential );
			b4_result_line( $mode );
			// no fallthrough

		case 'ai-preference-legacy-fallback':
			// Standalone adapter load (repository absent): the legacy option
			// path stays a guarded fallback.
			if ( ! defined( 'ABSPATH' ) ) {
				define( 'ABSPATH', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'b4-solo2-' . getmypid() . DIRECTORY_SEPARATOR );
			}
			require $project . '/runtime/adapters/ai.php';
			b4_check( 'AP1', 'auto' === hossam_ai_get_preference() && true === hossam_ai_set_preference( 'direct_key' ) && 'direct_key' === get_option( 'hossam_ai_preference' ) && false === hossam_ai_set_preference( 'bogus' ) && 'direct_key' === get_option( 'hossam_ai_preference' ), 'the legacy preference fallback validates the enum and writes the legacy option when the repository layer is absent', 'legacy preference mismatch' );
			b4_result_line( $mode );
			// no fallthrough

		case 'registry-states':
			// The setup registry classifies available/limited/unavailable from
			// the real adapter states.
			b4_define_release_context();
			define( 'HAL_FRONTEND_DASHBOARD_SECRET_KEY', b4_valid_key_hex( 'states' ) );
			b4_define_valid_profile();
			define( 'HOSSAM_AMELIA_API_KEY', 'b4-states-key' );
			b4_require_runtime();
			$GLOBALS['B4_PLUGIN_ACTIVE'] = array( 'ameliabooking/ameliabooking.php' );
			HAL_Frontend_Dashboard_Secret_Store::set( 'ai_direct_key', 'b4-states-secret' );
			eval( 'function wp_ai_client_prompt( $text ) { return new B4_AiClient_Fixture( true ); }' );
			delete_transient( hossam_integration_registry_key() );
			$registry = hossam_get_integration_capabilities();
			b4_check( 'RS1', 'unavailable' === ( $registry['woocommerce']['status'] ?? '' ) && 'unavailable' === ( $registry['wpml']['status'] ?? '' ) && 'unavailable' === ( $registry['ultimate_member']['status'] ?? '' ), 'integrations whose external plugin is absent are classified unavailable', 'unavailable classification mismatch: ' . json_encode( array( 'woocommerce' => $registry['woocommerce']['status'] ?? '', 'wpml' => $registry['wpml']['status'] ?? '' ) ) );
			b4_check( 'RS2', 'limited' === ( $registry['amelia']['status'] ?? '' ) && true === ( $registry['amelia']['capabilities']['elite_api_client'] ?? false ) && true === ( $registry['amelia']['capabilities']['elite_api_secret'] ?? false ) && false === ( $registry['amelia']['capabilities']['bookings_shortcode'] ?? true ), 'with the loaded adapter, the credential and no shortcode Amelia is limited (secret capability no longer masks the reason)', 'amelia classification mismatch: ' . json_encode( $registry['amelia'] ?? array() ) );
			b4_check( 'RS3', 'unavailable' === ( $registry['frontend_admin']['status'] ?? '' ) && true === ( $registry['frontend_admin']['capabilities']['core_crud'] ?? false ), 'the posts adapter makes core_crud ready, and with the Frontend Admin plugin absent the integration stays unavailable (an adapter capability alone never enables it — §7.6)', 'frontend_admin classification mismatch: ' . json_encode( $registry['frontend_admin'] ?? array() ) );
			b4_check( 'RS4', 'available' === ( $registry['ai']['status'] ?? '' ) && true === ( $registry['ai']['capabilities']['wp_ai_client'] ?? false ), 'the AI integration is available through the real adapter (supported client + profile + credential)', 'ai classification mismatch: ' . json_encode( $registry['ai'] ?? array() ) );
			b4_result_line( $mode );
			// no fallthrough
	}

	fwrite( STDERR, "Unknown B4 mode: {$mode}\n" );
	exit( 1 );
}

if ( ! class_exists( 'B4_AiClient_Fixture' ) ) {
	/**
	 * Boundary stub of the WordPress AI Client capability probe
	 * (unified reference §7.4): is_supported_for_text_generation().
	 */
	class B4_AiClient_Fixture {
		private bool $supported;
		public function __construct( bool $supported ) {
			$this->supported = $supported;
		}
		public function is_supported_for_text_generation(): bool {
			return $this->supported;
		}
	}
}

/* ── Dispatch ──
 * Any invocation WITH a mode argument (including 'main') runs exactly that
 * one mode and exits with its verdict. The full runner (which spawns every
 * mode as a subprocess) executes only with no mode argument — otherwise
 * the 'main' subprocess would re-enter the runner recursively.
 */
$b4_mode_arg = isset( $argv[1] ) ? (string) $argv[1] : '';
if ( '' !== $b4_mode_arg ) {
	b4_mode( $b4_mode_arg, array_slice( $argv, 2 ), $project );
	exit( 1 ); // safety net — b4_result_line already exited with the verdict.
}

b4_run_mode( 'main' );
b4_run_mode( 'woocommerce-absent' );
b4_run_mode( 'wpml-absent' );
b4_run_mode( 'amelia-inactive' );
b4_run_mode( 'amelia-api-contract' );
b4_run_mode( 'amelia-transport-guards' );
b4_run_mode( 'amelia-credentials' );
b4_run_mode( 'amelia-credentials-legacy-fallback' );
b4_run_mode( 'rankmath-contract' );
b4_run_mode( 'uploads-contract' );
b4_run_mode( 'posts-contract' );
b4_run_mode( 'ai-strategies-unprofiled' );
b4_run_mode( 'ai-strategies' );
b4_run_mode( 'ai-execution' );
b4_run_mode( 'ai-provider-allowlist' );
b4_run_mode( 'ai-direct-fallback' );
b4_run_mode( 'ai-model-allowlist' );
b4_run_mode( 'ai-connector-reach' );

$b4_log_file = $project . DIRECTORY_SEPARATOR . '.local-execution' . DIRECTORY_SEPARATOR . 'batch-4' . DIRECTORY_SEPARATOR . 'direct-key-error.log';
if ( ! is_dir( dirname( $b4_log_file ) ) ) {
	@mkdir( dirname( $b4_log_file ), 0777, true );
}
b4_run_mode( 'ai-execution-runtime', array( $b4_log_file ) );
b4_run_mode( 'ai-preference-legacy-fallback' );
b4_run_mode( 'registry-states' );

echo "HAL Frontend Dashboard — Batch 4 adapters test\n";
echo str_repeat( '─', 72 ) . "\n";
$pass_count = 0;
foreach ( $GLOBALS['B4_RESULTS'] as $result ) {
	if ( $result['ok'] ) {
		$pass_count++;
		echo "PASS [{$result['id']}] {$result['pass']}\n";
	} else {
		echo "FAIL [{$result['id']}] {$result['fail']}\n";
	}
}
echo str_repeat( '─', 72 ) . "\n";
$total = count( $GLOBALS['B4_RESULTS'] );
echo "RESULT: {$pass_count}/{$total} checks passed\n";
exit( $pass_count === $total ? 0 : 1 );
