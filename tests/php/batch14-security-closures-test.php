<?php
/**
 * HAL Frontend Dashboard — Batch 14 harness T9: security closures (H3 §1/§2/§3).
 *
 * Local, isolated harness: no live WordPress, no network, no database, no
 * browser. Pattern: batch9-assets-test.php (isolated PHP CLI with WP boundary
 * stubs) + the approved endpoint-probe pattern (a1-probe-endpoints.php).
 * Loads the REAL units — i18n, setup, rate-limits, audit-log, settings
 * repository, wordpress-uploads, woocommerce, wordpress-posts, wpml, and the
 * REAL ajax endpoints (posts/uploads/seo/finance) — and invokes the real
 * registered hook callbacks. Only the WordPress boundary (and the WooCommerce
 * gateway registry class) is stubbed; no project logic is reimplemented.
 *
 * Coverage (H3-SECURITY-CLOSURES.md):
 *
 *   §1 ORACLE  — byte-for-byte response equality (status+message+code)
 *               across the THREE cases (missing id / existing id of a weird
 *               non-post/attachment type [H9 C1 correction] / the victim
 *               object id) on the SEVEN points × TWO roles (Subscriber and
 *               foreign Author). All three cases answer the unified 404 with
 *               the exact not-found message — existence is never disclosed.
 *               + static: zero unauthorized literal-403 wp_send_json_error
 *               sites remain in the seven-point paths (every survivor is the
 *               feature_disabled gate or the translate internal branch).
 *   §2 NONCE   — the conditional wp_rest nonce through the REAL setup.php
 *               enqueue closure: emitted only when the rich editor is enabled
 *               AND upload_files; '' otherwise (key kept, never removed);
 *               wp_create_nonce('wp_rest') is called exactly once per emitting
 *               run and zero times otherwise; canUpload/restRoot contract
 *               unchanged. Static: exactly one wp_create_nonce('wp_rest') site
 *               in setup.php, conditional; posts.js fingerprint intact vs
 *               HEAD (line-ending-normalized blob equality) and vs the H3 §6
 *               recorded byte hash.
 *   §3 PAYMENT — Subscriber + valid nonce ⇒ 200 with the exact contract
 *               {title, enabled:true} and NO 'id' key; the member panel
 *               render stays safe from the remaining fields (finance.js
 *               consumes p.title/p.enabled only — p.id unconsumed; finance.js
 *               fingerprint intact vs the H3 §6 recorded byte hash).
 *               (The live probe against production remains a separate
 *               acceptance proof — H3 §3.5.)
 *
 * Usage:  php -d extension=sodium tests/php/batch14-security-closures-test.php  (exit 0 = all pass)
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

if ( PHP_SAPI !== 'cli' ) {
	echo "This harness must run under CLI PHP.\n";
	exit( 1 );
}

$GLOBALS['B14_PROJECT'] = dirname( __DIR__, 2 );

/* ════════════════════════════════════════════════════════════════
 * WordPress boundary stubs (WordPress only — never project logic)
 * ════════════════════════════════════════════════════════════════ */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $GLOBALS['B14_PROJECT'] . '/runtime/fake-wp-root/' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'DB_HOST' ) ) {
	define( 'DB_HOST', 'db.test:3306' );
}
if ( ! defined( 'DB_NAME' ) ) {
	define( 'DB_NAME', 'hal_b14_db' );
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
class HalS9Json extends Exception {
	public function __construct( public bool $success, public array $payload, public int $status ) {
		parent::__construct( 'JSON' );
	}
	public function tuple(): string {
		// The byte-for-byte oracle tuple: status + message + code.
		return (string) json_encode( array(
			'status'  => $this->status,
			'message' => (string) ( $this->payload['message'] ?? '' ),
			'code'    => array_key_exists( 'code', $this->payload ) ? (string) $this->payload['code'] : null,
		) );
	}
}
class HalS9Die extends Exception {
	public function __construct( public $code_or_message, public int $status ) {
		parent::__construct( 'DIE' );
	}
}
$GLOBALS['B14_NONCE_VALID'] = false;
function check_ajax_referer( $action = -1, $query_arg = false, bool $stop = true ) {
	if ( $stop && ! $GLOBALS['B14_NONCE_VALID'] ) {
		throw new HalS9Die( -1, 403 );
	}
	return $GLOBALS['B14_NONCE_VALID'] ? 1 : false;
}
function wp_send_json_error( $payload = null, int $status = 400 ): void {
	throw new HalS9Json( false, is_array( $payload ) ? $payload : array( 'data' => $payload ), $status );
}
function wp_send_json_success( $data = null ): void {
	throw new HalS9Json( true, is_array( $data ) ? $data : array( 'data' => $data ), 200 );
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
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) {
		return $s;
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
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s ): string {
		return trim( strip_tags( (string) $s ) );
	}
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $content ): string {
		$c = (string) $content;
		$c = preg_replace( '#</?(script|iframe|style)\b[^>]*>#si', '', $c );
		return $c;
	}
}

/* ── Users / caps / session ── */
function get_current_user_id(): int {
	return (int) ( $GLOBALS['B14_USER_ID'] ?? 0 );
}
function get_current_blog_id(): int {
	return 1;
}
function is_user_logged_in(): bool {
	return true;
}
function is_admin(): bool {
	return false;
}
function is_rtl(): bool {
	return true;
}
function show_admin_bar( bool $show ): void {}
function current_user_can( string $cap, ...$rest ): bool {
	// map_meta_cap proxy: edit_post/delete_post resolve per-id against the owned set.
	if ( ( 'edit_post' === $cap || 'delete_post' === $cap ) && isset( $rest[0] ) ) {
		if ( empty( $GLOBALS['B14_CAPS']['edit_posts'] ) ) {
			return false;
		}
		return in_array( (int) $rest[0], (array) ( $GLOBALS['B14_OWNED'] ?? array() ), true );
	}
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
			return 1;
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
		public function __construct( public $ID = 0, public $post_type = '', public $post_author = 0, public $post_status = '', public $post_title = '', public $post_content = '', public $post_excerpt = '', public $post_mime_type = 'image/jpeg' ) {}
	}
}
if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {
		public function __construct( public $term_id = 0, public $term_taxonomy_id = 0, public $taxonomy = '' ) {}
	}
}
$GLOBALS['B14_POSTS']   = array();
$GLOBALS['B14_NEXT_ID'] = 5001;

function get_post( $id ) {
	return $GLOBALS['B14_POSTS'][ (int) $id ] ?? null;
}
function set_probe_post( int $id, string $type, int $author, string $status = 'publish' ): void {
	$GLOBALS['B14_POSTS'][ $id ] = new WP_Post( $id, $type, $author, $status, 'T', 'C' );
}
function get_post_status( $id ): string {
	$p = get_post( (int) $id );
	return $p ? (string) $p->post_status : '';
}
function wp_insert_post( array $postarr, bool $wp_error = false ) {
	$id = $GLOBALS['B14_NEXT_ID']++;
	return $id;
}
function wp_update_post( array $postarr, bool $wp_error = false ) {
	return (int) ( $postarr['ID'] ?? 0 );
}
function wp_delete_post( int $post_id, bool $force = false ) {
	return true;
}
function wp_trash_post( int $post_id ) {
	return true;
}
function wp_untrash_post( int $post_id ) {
	return true;
}
function wp_delete_attachment( int $id, bool $force = false ) {
	return true;
}
function wp_set_post_categories( int $post_id, array $categories = array() ): array {
	return $categories;
}
function wp_get_post_categories( int $post_id ): array {
	return array();
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
	return new WP_Term( (int) $term_id, (int) $term_id * 10, 'category' );
}
function get_the_title( $post ): string {
	return 'T';
}
function get_the_date( $format = '', $post = null ): string {
	return '2026-01-01 00:00';
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
function rest_url( string $path = '' ): string {
	return 'https://example.test/wp-json/' . ltrim( $path, '/' );
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
function wp_doing_cron(): bool {
	return false;
}
function hossam_dashboard_ensure_table( array $definition ): void {
	$GLOBALS['B14_OPTIONS'][ $definition['option'] ] = $definition['version'];
}

/* ── Enqueue boundary (setup.php / nonce-matrix section) ── */
$GLOBALS['B14_SCRIPTS']   = array();
$GLOBALS['B14_STYLES']    = array();
$GLOBALS['B14_LOCALIZED'] = array();
$GLOBALS['B14_MEDIA']     = 0;
function wp_enqueue_style( string $handle, string $src = '', array $deps = array(), $ver = null ): void {
	$GLOBALS['B14_STYLES'][ $handle ] = array( 'src' => $src, 'deps' => $deps, 'ver' => $ver );
}
function wp_enqueue_script( string $handle, string $src = '', array $deps = array(), $ver = null, bool $in_footer = false ): void {
	$GLOBALS['B14_SCRIPTS'][ $handle ] = array( 'src' => $src, 'deps' => $deps, 'ver' => $ver, 'in_footer' => $in_footer );
}
function wp_localize_script( string $handle, string $name, array $data ): bool {
	$GLOBALS['B14_LOCALIZED'][ $handle . ':' . $name ] = $data;
	return true;
}
function wp_enqueue_media( array $args = array() ): void {
	$GLOBALS['B14_MEDIA']++;
}
$GLOBALS['B14_NONCE_CALLS'] = array();
function wp_create_nonce( string $action ): string {
	$GLOBALS['B14_NONCE_CALLS'][ $action ] = ( $GLOBALS['B14_NONCE_CALLS'][ $action ] ?? 0 ) + 1;
	return 'b14s-nonce-' . $action;
}
if ( ! function_exists( 'wp_script_is' ) ) {
	function wp_script_is( string $handle, string $list = 'enqueued' ): bool {
		return true;
	}
}
if ( ! function_exists( 'wp_style_is' ) ) {
	function wp_style_is( string $handle, string $list = 'enqueued' ): bool {
		return true;
	}
}
if ( ! class_exists( 'HAL_Frontend_Dashboard_Template_Controller' ) ) {
	/** Boundary state stub: "the current query is the owned dashboard page". */
	class HAL_Frontend_Dashboard_Template_Controller {
		public static function is_target_query(): bool {
			return true;
		}
	}
}

/* ── WooCommerce boundary (payment section) ── */
if ( ! class_exists( 'B14S_Gateway' ) ) {
	class B14S_Gateway {
		public function __construct( public string $title = '', public string $enabled = 'yes' ) {}
		public function get_title(): string {
			return $this->title;
		}
	}
}
if ( ! class_exists( 'WC_Payment_Gateways' ) ) {
	class WC_Payment_Gateways {
		private static ?WC_Payment_Gateways $instance = null;
		public static function instance(): WC_Payment_Gateways {
			return self::$instance ??= new self();
		}
		public function payment_gateways(): array {
			return array( new B14S_Gateway( 'Stripe' ), new B14S_Gateway( 'Bank transfer' ) );
		}
	}
}
if ( ! function_exists( 'wc_get_orders' ) ) {
	function wc_get_orders( $args = array() ): array {
		return array(); // WooCommerce boundary: the real hossam_wc_is_active() resolves true
	}
}

/* ── Release context constants (same shape as loader-core.php defines) ── */
define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT', $GLOBALS['B14_PROJECT'] . '/runtime/' );
define( 'HAL_FRONTEND_DASHBOARD_RELEASE_ID', '1.0.0+' . str_repeat( 's9', 20 ) );
define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION', '1.0.0' );
define(
	'HAL_FRONTEND_DASHBOARD_RUNTIME_URL',
	'https://example.test/wp-content/mu-plugins/hal-frontend-dashboard/releases/' . HAL_FRONTEND_DASHBOARD_RELEASE_ID . '/'
);

/* ════════════════════════════════════════════════════════════════
 * Load the REAL production units (bootstrap order: core → settings →
 * adapters → ajax)
 * ════════════════════════════════════════════════════════════════ */

require $GLOBALS['B14_PROJECT'] . '/runtime/core/i18n.php';
require $GLOBALS['B14_PROJECT'] . '/runtime/core/rate-limits.php';
require $GLOBALS['B14_PROJECT'] . '/runtime/core/audit-log.php';
require $GLOBALS['B14_PROJECT'] . '/runtime/core/setup.php';
require $GLOBALS['B14_PROJECT'] . '/runtime/settings/class-settings-repository.php';
require $GLOBALS['B14_PROJECT'] . '/runtime/adapters/wordpress-uploads.php';
require $GLOBALS['B14_PROJECT'] . '/runtime/adapters/woocommerce.php';
require $GLOBALS['B14_PROJECT'] . '/runtime/adapters/wordpress-posts.php';
require $GLOBALS['B14_PROJECT'] . '/runtime/adapters/wpml.php';
foreach ( array( 'posts.php', 'uploads.php', 'seo.php', 'finance.php' ) as $b14s_ajax ) {
	require $GLOBALS['B14_PROJECT'] . '/runtime/ajax/' . $b14s_ajax;
}

/* Seed the integration registry cache (the transient read path of the REAL
 * registry — the build path needs live plugins, unavailable locally). The
 * seed also survives every b14_reset() through B14_REGISTRY_SEED. */
$GLOBALS['B14_REGISTRY_SEED'] = array(
	'wpml' => array(
		'status' => 'available', 'plugin_active' => true, 'version' => 'probe',
		'capabilities' => array( 'language_urls' => true, 'languages' => true ), 'reason' => '', 'checked_at' => '',
	),
	'rank_math' => array(
		'status' => 'available', 'plugin_active' => true, 'version' => 'probe',
		'capabilities' => array( 'plugin_contract' => true, 'verified_fields' => true ), 'reason' => '', 'checked_at' => '',
	),
	'woocommerce' => array(
		'status' => 'available', 'plugin_active' => true, 'version' => 'probe',
		'capabilities' => array( 'orders' => true, 'products' => true, 'payment_methods' => true, 'tokens' => true ), 'reason' => '', 'checked_at' => '',
	),
);
$GLOBALS['B14_TRANSIENTS'][ hossam_integration_registry_key() ] = $GLOBALS['B14_REGISTRY_SEED'];

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

/** Reset every mutable fixture for one probe run under a role. */
function b14_reset( array $caps, array $owned ): void {
	$GLOBALS['B14_POSTS']       = array();
	$GLOBALS['B14_TRANSIENTS']  = array();
	$GLOBALS['B14_NONCE_VALID'] = true;
	$GLOBALS['B14_USER_ID']     = 7;
	$GLOBALS['B14_CAPS']        = $caps;
	$GLOBALS['B14_OWNED']       = $owned;
	$GLOBALS['B14_USERS']       = array( 7 => (object) array( 'user_login' => 'hossam_member' ) );
	$GLOBALS['wpdb']            = new B14_wpdb();
	/* The fixture objects: 88 = weird type (page / post for uploads), 66/666 = victim objects owned by user 99. */
	set_probe_post( 88, 'page', 7 );
	set_probe_post( 886, 'post', 7 );
	set_probe_post( 66, 'post', 99 );
	set_probe_post( 666, 'attachment', 99 );
	/* Keep the integration registry seeded (delete_transient is never called,
	 * but stay explicit about the cache path under test). */
	$GLOBALS['B14_TRANSIENTS'][ hossam_integration_registry_key() ] = $GLOBALS['B14_REGISTRY_SEED'];
}

/** Invoke a real endpoint callback and capture its JSON response. */
function b14_invoke( string $hook_name ): ?HalS9Json {
	$entries = b14_hook_sorted( 'action', $hook_name );
	try {
		call_user_func( $entries[0]['cb'] );
	} catch ( HalS9Json $j ) {
		return $j;
	} catch ( HalS9Die $d ) {
		return null;
	}
	return null;
}

/* ════════════════════════════════════════════════════════════════
 * T9-LOAD
 * ════════════════════════════════════════════════════════════════ */

b14_check( 'T9-LOAD-real-units',
	function_exists( 'hossam_send_article_error' )
		&& function_exists( 'hossam_update_article' )
		&& function_exists( 'hossam_t' )
		&& function_exists( 'hossam_get_integration_decision' )
		&& ! empty( b14_hook_sorted( 'action', 'wp_ajax_hossam_save_seo' ) )
		&& ! empty( b14_hook_sorted( 'action', 'wp_ajax_hossam_get_payment_methods' ) ),
	'real i18n/setup/rate-limits/audit-log/settings/adapters/ajax endpoints loaded; the seven oracle points and the payment endpoint are registered',
	'real units did not load their expected surfaces' );

/* ════════════════════════════════════════════════════════════════
 * T9-§1 ORACLE — byte-for-byte 404 equality on the seven points × two roles
 * ════════════════════════════════════════════════════════════════ */

$oracle_points = array(
	'posts' => array(
		array( 'wp_ajax_hossam_restore_article', 'post_id', 4401, 88, 66 ),
		array( 'wp_ajax_hossam_update_article', 'post_id', 4401, 88, 66 ),
		array( 'wp_ajax_hossam_trash_article', 'post_id', 4401, 88, 66 ),
		array( 'wp_ajax_hossam_translate_article', 'post_id', 4401, 88, 66 ),
	),
	'uploads' => array(
		array( 'wp_ajax_hossam_restore_file', 'file_id', 4402, 886, 666 ),
		array( 'wp_ajax_hossam_delete_file', 'file_id', 4402, 886, 666 ),
	),
	'seo' => array(
		array( 'wp_ajax_hossam_save_seo', 'post_id', 4401, 88, 66 ),
	),
);
$roles = array(
	'Subscriber'   => array( 'caps' => array(), 'owned' => array() ),
	'Author-foreign' => array( 'caps' => array( 'edit_posts' => true ), 'owned' => array() ),
);

$oracle_rows = array();
foreach ( $roles as $role_name => $role ) {
	foreach ( $oracle_points as $group => $points ) {
		foreach ( $points as $point ) {
			list( $hook, $key, $missing, $weird, $victim ) = $point;
			$tuples = array();
			foreach ( array( 'missing' => $missing, 'weird' => $weird, 'victim' => $victim ) as $case => $id ) {
				b14_reset( $role['caps'], $role['owned'] );
				$_POST[ $key ] = (string) $id;
				$_REQUEST = $_POST;
				$resp = b14_invoke( $hook );
				$_POST = array();
				$_REQUEST = array();
				$tuples[ $case ] = $resp instanceof HalS9Json ? $resp->tuple() : 'NO-JSON';
				$tuples[ $case . ':status' ] = $resp instanceof HalS9Json ? $resp->status : 0;
			}
			$equal = $tuples['missing'] === $tuples['weird'] && $tuples['weird'] === $tuples['victim'];
			$all404 = 404 === $tuples['missing:status'] && 404 === $tuples['weird:status'] && 404 === $tuples['victim:status'];
			$oracle_rows[] = compact( 'role_name', 'group', 'hook', 'equal', 'all404' ) + array( 'tuple' => $tuples['missing'] );
			b14_check( "T9-ORACLE-{$role_name}-{$hook}",
				$equal && $all404,
				"the three cases (missing / weird non-post-attachment type / victim id) answer byte-for-byte identical ({$tuples['missing']}) — existence never disclosed (H3 §1.5-1)",
				"oracle mismatch on {$hook} [{$role_name}]: missing={$tuples['missing']} weird={$tuples['weird']} victim={$tuples['victim']}" );
		}
	}
}
b14_check( 'T9-ORACLE-matrix-complete',
	14 === count( $oracle_rows ) && array() === array_filter( $oracle_rows, static fn( $r ) => ! $r['equal'] || ! $r['all404'] ),
	'the full oracle matrix ran: 7 points × 2 roles, every group byte-equal and 404 (H3 §1.6 probe contract)',
	'oracle matrix incomplete: rows=' . count( $oracle_rows ) );

/* ════════════════════════════════════════════════════════════════
 * T9-§1 STATIC — zero unauthorized 403 in the seven-point regions
 * ════════════════════════════════════════════════════════════════ */

/**
 * Slice one endpoint's source region (its add_action marker up to the next
 * top-level add_action) and classify every literal-403 wp_send_json_error
 * call site. Authorized categories only (H3 §1.5-2/§1.5-3):
 *   feature_disabled   — the upper feature gate (posts / uploads B3-08)
 *   translate_internal — the translate internal ownership/membership branch
 */
function b14_s9_classify_region_403( string $src, string $marker ): array {
	$start = strpos( $src, $marker );
	if ( false === $start ) {
		return array( 'error' => 'marker-not-found' );
	}
	$next   = strpos( $src, "\nadd_action( 'wp_ajax_", $start + 10 );
	$region = false === $next ? substr( $src, $start ) : substr( $src, $start, $next - $start );
	$direct = array();
	foreach ( preg_split( '/\r\n|\r|\n/', $region ) as $idx => $line ) {
		if ( false === strpos( $line, '403' ) ) {
			continue;
		}
		$trim = ltrim( $line );
		if ( '' === $trim || '*' === $trim[0] || '//' === substr( $trim, 0, 2 ) ) {
			continue;
		}
		if ( 1 !== preg_match( '/wp_send_json_error\(([^\n]*?),\s*403\s*\)/', $line ) ) {
			continue;
		}
		$category = 'unknown';
		if ( false !== strpos( $line, 'feature_disabled' ) ) {
			$category = 'feature_disabled';
		} elseif ( false !== strpos( $line, 'translation_forbidden' ) || false !== strpos( $line, 'translation_mismatch' )
			|| false !== strpos( $line, 'permission to edit this translation' ) ) {
			$category = 'translate_internal';
		}
		$direct[] = $category;
	}
	return array( 'direct' => $direct );
}

$ajax_src = array(
	'posts'   => (string) file_get_contents( $GLOBALS['B14_PROJECT'] . '/runtime/ajax/posts.php' ),
	'uploads' => (string) file_get_contents( $GLOBALS['B14_PROJECT'] . '/runtime/ajax/uploads.php' ),
	'seo'     => (string) file_get_contents( $GLOBALS['B14_PROJECT'] . '/runtime/ajax/seo.php' ),
);
$seven_regions = array(
	'wp_ajax_hossam_restore_article'   => array( 'posts', array( 'feature_disabled' ), 1 ),
	'wp_ajax_hossam_translate_article' => array( 'posts', array( 'feature_disabled', 'translate_internal' ), 5 ),
	'wp_ajax_hossam_update_article'    => array( 'posts', array( 'feature_disabled' ), 1 ),
	'wp_ajax_hossam_trash_article'     => array( 'posts', array( 'feature_disabled' ), 1 ),
	'wp_ajax_hossam_restore_file'      => array( 'uploads', array( 'feature_disabled' ), 1 ),
	'wp_ajax_hossam_delete_file'       => array( 'uploads', array( 'feature_disabled' ), 1 ),
	'wp_ajax_hossam_save_seo'          => array( 'seo', array(), 0 ),
);
$static403_ok   = true;
$static403_info = array();
foreach ( $seven_regions as $hook => $spec ) {
	list( $file, $authorized, $expected_count ) = $spec;
	$res = b14_s9_classify_region_403( $ajax_src[ $file ], "add_action( '{$hook}'" );
	$unauthorized = array_diff( $res['direct'] ?? array(), $authorized );
	if ( isset( $res['error'] ) || count( $res['direct'] ) !== $expected_count || array() !== $unauthorized ) {
		$static403_ok = false;
		$static403_info[ $hook ] = $res;
	}
}
b14_check( 'T9-STATIC-403-seven-regions',
	$static403_ok,
	'static: the seven-point regions carry ZERO unauthorized 403 — restore/update/trash/restore_file/delete_file keep only their feature_disabled gate (1 each), translate keeps gate+4 internal-ownership sites, save_seo has ZERO 403 (H3 §1.5-2)',
	'static 403 classification deviated: ' . json_encode( $static403_info ) );

/* ════════════════════════════════════════════════════════════════
 * T9-§2 NONCE — conditional wp_rest through the REAL enqueue closure
 * ════════════════════════════════════════════════════════════════ */

function b14s_rich_run( bool $rich_feature, bool $edit_posts, bool $upload_files ): array {
	$GLOBALS['B14_OPTIONS'][ HAL_Frontend_Dashboard_Settings_Repository::OPTION_NAME ] = array(
		'features' => array( 'rich_editor' => $rich_feature ),
	);
	HAL_Frontend_Dashboard_Settings_Repository::invalidate_cache();
	$GLOBALS['B14_CAPS']       = array( 'edit_posts' => $edit_posts, 'upload_files' => $upload_files );
	$GLOBALS['B14_OWNED']      = array();
	$GLOBALS['B14_USER_ID']    = 7;
	$_REQUEST['post_id']       = 0;
	$GLOBALS['B14_SCRIPTS']    = array();
	$GLOBALS['B14_STYLES']     = array();
	$GLOBALS['B14_LOCALIZED']  = array();
	$GLOBALS['B14_MEDIA']      = 0;
	$GLOBALS['B14_NONCE_CALLS'] = array();
	$cb = null;
	foreach ( b14_hook_sorted( 'action', 'wp_enqueue_scripts' ) as $entry ) {
		if ( 20 === $entry['p'] ) {
			$cb = $entry['cb'];
			break;
		}
	}
	if ( null !== $cb ) {
		call_user_func( $cb );
	}
	return array(
		'rich'    => $GLOBALS['B14_LOCALIZED']['hossam-dashboard-posts:hossamRichEditor'] ?? null,
		'wp_rest' => (int) ( $GLOBALS['B14_NONCE_CALLS']['wp_rest'] ?? 0 ),
		'media'   => $GLOBALS['B14_MEDIA'],
	);
}

/* (true, true) — the only emitting combination. */
$m_tt = b14s_rich_run( true, true, true );
b14_check( 'T9-NONCE-emits-only-when-enabled-and-upload_files',
	is_array( $m_tt['rich'] ) && true === $m_tt['rich']['enabled'] && true === $m_tt['rich']['canUpload']
		&& '' !== $m_tt['rich']['restNonce'] && 1 === $m_tt['wp_rest'],
	'rich enabled + upload_files ⇒ the REAL closure emits the wp_rest nonce exactly once (restNonce non-empty, canUpload true)',
	'emitting run deviated: rich=' . var_export( $m_tt['rich'], true ) . ' wp_rest_calls=' . $m_tt['wp_rest'] );

/* (true, false) — editor enabled without upload rights: '' emitted, zero calls. */
$m_tf = b14s_rich_run( true, true, false );
b14_check( 'T9-NONCE-empty-when-no-upload_files',
	is_array( $m_tf['rich'] ) && true === $m_tf['rich']['enabled'] && false === $m_tf['rich']['canUpload']
		&& '' === $m_tf['rich']['restNonce'] && 0 === $m_tf['wp_rest'],
	'upload_files absent ⇒ restNonce="" (key kept, never removed) and wp_create_nonce("wp_rest") is never called',
	'no-upload run deviated: rich=' . var_export( $m_tf['rich'], true ) . ' wp_rest_calls=' . $m_tf['wp_rest'] );

/* (feature on, no edit path) — editor disabled: no consumption surface at all, '' emitted. */
$m_ft = b14s_rich_run( true, false, true );
b14_check( 'T9-NONCE-empty-when-editor-disabled',
	is_array( $m_ft['rich'] ) && false === $m_ft['rich']['enabled'] && true === $m_ft['rich']['canUpload']
		&& '' === $m_ft['rich']['restNonce'] && 0 === $m_ft['wp_rest']
		&& 'no-edit-path' === $m_ft['rich']['reason'],
	'rich editor disabled (no edit path) ⇒ restNonce="" even with upload_files — no nonce without a consumption surface',
	'disabled run deviated: rich=' . var_export( $m_ft['rich'], true ) . ' wp_rest_calls=' . $m_ft['wp_rest'] );

/* (false, false) — the deepest-off state. */
$m_ff = b14s_rich_run( false, false, false );
b14_check( 'T9-NONCE-empty-when-both-off',
	is_array( $m_ff['rich'] ) && false === $m_ff['rich']['enabled'] && false === $m_ff['rich']['canUpload']
		&& '' === $m_ff['rich']['restNonce'] && 0 === $m_ff['wp_rest'],
	'feature off + no upload_files ⇒ restNonce="" and zero wp_rest calls (the {enabled×upload_files} matrix is fully closed)',
	'off-off run deviated: rich=' . var_export( $m_ff['rich'], true ) . ' wp_rest_calls=' . $m_ff['wp_rest'] );

/* Static: exactly ONE wp_create_nonce('wp_rest') site in setup.php — conditional. */
$setup_src = (string) file_get_contents( $GLOBALS['B14_PROJECT'] . '/runtime/core/setup.php' );
$nonce_sites = substr_count( $setup_src, "wp_create_nonce( 'wp_rest' )" );
$conditional = false !== strpos( $setup_src, "'restNonce' => \$hossam_rich_can_upload ? wp_create_nonce( 'wp_rest' ) : ''" );
$guard_line  = false !== strpos( $setup_src, '$hossam_rich_can_upload = $hossam_rich_enabled && current_user_can( \'upload_files\' );' );
b14_check( 'T9-NONCE-static-single-conditional-site',
	1 === $nonce_sites && $conditional && $guard_line,
	'static: setup.php carries exactly one wp_create_nonce("wp_rest") site, behind the composite guard (rich_enabled && upload_files) with "" as the else branch (H3 §2.5)',
	'nonce static deviated: sites=' . $nonce_sites . ' conditional=' . var_export( $conditional, true )
		. ' guard=' . var_export( $guard_line, true ) );

/* posts.js fingerprint: byte-identical to HEAD (normalized) and to the H3 §6 recorded hash. */
$posts_js_path = $GLOBALS['B14_PROJECT'] . '/runtime/assets/js/modules/posts.js';
$posts_js_raw  = (string) file_get_contents( $posts_js_path );
$posts_js_raw_hash = hash( 'sha256', $posts_js_raw );
$posts_js_lf_hash  = hash( 'sha256', str_replace( "\r\n", "\n", $posts_js_raw ) );
$posts_js_head     = (string) shell_exec( 'git -C ' . escapeshellarg( $GLOBALS['B14_PROJECT'] ) . ' show HEAD:runtime/assets/js/modules/posts.js 2>&1' );
$posts_js_head_hash = hash( 'sha256', $posts_js_head );
b14_check( 'T9-NONCE-posts-js-fingerprint-intact',
	'98e5af2b008ee7586ddf848ad83401545ffb2f68baabba3f6270cec7c3deb558' === $posts_js_raw_hash
		&& $posts_js_lf_hash === $posts_js_head_hash,
	'posts.js is byte-identical to the H3 §6 recorded fingerprint and to its HEAD blob (line-ending-normalized) — zero JS drift from the nonce row (H3 §1.5-4)',
	'posts.js fingerprint deviated: raw=' . $posts_js_raw_hash . ' lf=' . $posts_js_lf_hash . ' head=' . $posts_js_head_hash );

/* ════════════════════════════════════════════════════════════════
 * T9-§3 PAYMENT — Subscriber + nonce ⇒ 200 {title, enabled:true} without id
 * ════════════════════════════════════════════════════════════════ */

b14_reset( array(), array() ); // Subscriber: no capabilities at all
$pay = b14_invoke( 'wp_ajax_hossam_get_payment_methods' );
$pay_payload = ( $pay instanceof HalS9Json && $pay->success ) ? $pay->payload : array();
$pay_rows_ok = false;
$pay_keys = array();
if ( 200 === ( $pay->status ?? 0 ) && count( $pay_payload ) === 2 ) {
	$pay_rows_ok = true;
	foreach ( $pay_payload as $row ) {
		$keys = array_keys( $row );
		sort( $keys );
		$pay_keys[] = $keys;
		if ( array( 'enabled', 'title' ) !== $keys || true !== $row['enabled'] || ! is_string( $row['title'] ) || '' === $row['title'] ) {
			$pay_rows_ok = false;
		}
		if ( array_key_exists( 'id', $row ) ) {
			$pay_rows_ok = false;
		}
	}
}
b14_check( 'T9-PAYMENT-subscriber-200-contract',
	$pay instanceof HalS9Json && $pay->success && 200 === $pay->status && $pay_rows_ok,
	'Subscriber + nonce ⇒ 200 with the exact {title, enabled:true} contract per gateway (Stripe/Bank transfer) and NO id key (H3 §3.4-2)',
	'payment contract deviated: status=' . var_export( $pay->status ?? null, true )
		. ' payload=' . var_export( $pay_payload, true ) . ' keys=' . json_encode( $pay_keys ) );

/* The member panel stays render-safe from the remaining fields: finance.js
 * consumes p.title/p.enabled only and never p.id (H3 §3.1/§3.4-1). */
$finance_js_path = $GLOBALS['B14_PROJECT'] . '/runtime/assets/js/modules/finance.js';
$finance_js_raw  = (string) file_get_contents( $finance_js_path );
b14_check( 'T9-PAYMENT-finance-js-render-safety',
	0 === preg_match_all( '/p\.id\b/', $finance_js_raw )
		&& 0 === substr_count( $finance_js_raw, 'p.id' )
		&& false !== strpos( $finance_js_raw, 'p.title' )
		&& false !== strpos( $finance_js_raw, 'p.enabled' )
		&& '3392e72c3b5ccda3c1ea333a652aad2767c9ab766c94d6f35f9f9e33d10341bc' === hash( 'sha256', $finance_js_raw ),
	'finance.js consumes p.title/p.enabled only (zero p.id reads) and is byte-identical to the H3 §6 recorded fingerprint — the member panel renders from the remaining fields',
	'finance.js render-safety deviated: p_id_regex=' . preg_match_all( '/p\.id\b/', $finance_js_raw )
		. ' hash=' . hash( 'sha256', $finance_js_raw ) );

/* uploads.js also untouched by the security closures row (H3 §1.5-4). */
$uploads_js_hash = hash( 'sha256', (string) file_get_contents( $GLOBALS['B14_PROJECT'] . '/runtime/assets/js/modules/uploads.js' ) );
b14_check( 'T9-PAYMENT-uploads-js-fingerprint-intact',
	'dbf20853956ab42196ef12a6b7b59a7a526ef61f6a42af0be29558b5cfa900db' === $uploads_js_hash,
	'uploads.js is byte-identical to the H3 §6 recorded fingerprint (zero JS drift from the oracle row)',
	'uploads.js fingerprint deviated: ' . $uploads_js_hash );

/* ════════════════════════════════════════════════════════════════
 * Summary
 * ════════════════════════════════════════════════════════════════ */

echo "T9 LIVE-ACCEPTANCE (منفصلة): البروب الحي على الإنتاج (أوراكل على النشر الفعلي) يبقى برهان قبول منفصل — H3 §1.6/§3.5.\n";
echo "B14-T9 RESULT: {$GLOBALS['B14_PASS']} pass, 0 skipped, {$GLOBALS['B14_FAIL']} fail (PHP " . PHP_VERSION . ")\n";
if ( $GLOBALS['B14_FAIL'] > 0 ) {
	echo "Failures:\n" . implode( "\n", $GLOBALS['B14_FAILS'] ) . "\n";
	exit( 1 );
}
exit( 0 );
