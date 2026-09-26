<?php
/**
 * HAL Frontend Dashboard — Batch 7 closure harness: Runtime template shell & base structure.
 *
 * Local, isolated harness: no live WordPress, no network, no database.
 * Loads the REAL runtime/bootstrap.php (core + batch-3 backend + 8
 * adapters + 8 ajax + the batch-7 infrastructure Template Controller)
 * and RENDERS the REAL runtime templates (dashboard.php shell + the
 * five batch-7 parts) — only WordPress itself is stubbed. No project
 * logic is reimplemented in the harness.
 *
 * Coverage (architecture §18 closure gate + §7.5 contract + the adopted
 * owner decision B3-08 option أ — template consumption points):
 *
 *   CONTROLLER — template_include registration; owned-page predicate by
 *              page ID ONLY (no slug / query-param / is_page_template);
 *              WPML translation targets via the official filters
 *              (wpml_element_trid / wpml_get_element_translations,
 *              post_page); realpath + readability + containment inside
 *              HAL_FRONTEND_DASHBOARD_RUNTIME_DIR before any include;
 *              fail-safe (original template / no output) when runtime
 *              context, file or allowlist entry is missing; closed
 *              allowlist of the twelve documented dashboard parts.
 *   SHELL     — Auth Guard redirect+exit for guests; single request =
 *              single release for template and assets (no
 *              get_template_part(), no child-theme dependency, no
 *              get_stylesheet_directory, no fixed logo file path);
 *              logo resolves via Branding attachment (Settings
 *              Repository validation) with the recorded safe HAL text
 *              fallback; explicit named args replace get_defined_vars().
 *   B3-08     — owner-disabled features are not consumed in navigation,
 *              panel rendering, quick actions or the AI buttons of the
 *              migrated batch-7 surfaces (posts/files/amelia/finance/
 *              store/inbox/members/wpml_translations/rank_math_seo/
 *              ultimate_member_profile/ai); source capability conditions
 *              remain untouched (the owner toggle is an additional
 *              layer); unset defaults keep legacy behaviour.
 *   MIGRATION — the infrastructure copy is byte-identical to the shared
 *              source in includes/ (§6.1 pre-build copy; the batch-11
 *              workflow owns regeneration); hossam_is_dashboard()
 *              delegates to the controller predicate (fail-closed).
 *
 * Modes: main (inventory/controller/predicate/static scans + spawns the
 * subprocess modes), shell-admin, shell-writer, shell-employee,
 * shell-guest, gates, controller-nocontext, controller-wpml.
 *
 * Usage:  php tests/php/template-shell-test.php            (main)
 *         php tests/php/template-shell-test.php <mode>     (subprocess)
 *         exit 0 = all checks pass.
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

if ( PHP_SAPI !== 'cli' ) {
	echo "This harness must run under CLI PHP.\n";
	exit( 1 );
}

define( 'HAL_TEST_EXT_DIR', (string) ( getenv( 'HAL_PHP_EXT_DIR' ) ?: ini_get( 'extension_dir' ) ) );
$GLOBALS['B7_PROJECT'] = dirname( __DIR__, 2 );

/* ════════════════════════════════════════════════════════════════
 * WordPress boundary stubs (WordPress only — never project logic)
 * ════════════════════════════════════════════════════════════════ */

$GLOBALS['B7_HOOKS']        = array();
$GLOBALS['B7_ACTIONS']      = array();
$GLOBALS['B7_OPTIONS']      = array();
$GLOBALS['B7_TRANSIENTS']   = array();
$GLOBALS['B7_CAPS']         = array();
$GLOBALS['B7_USER_CAN']     = array();
$GLOBALS['B7_LOGGED_IN']    = true;
$GLOBALS['B7_USER_ID']      = 7;
$GLOBALS['B7_USER']         = null;
$GLOBALS['B7_POSTS']        = array();
$GLOBALS['B7_QUERIED']      = null;
$GLOBALS['B7_SHORTCODES']   = array();
$GLOBALS['B7_SHORTCODE_OUT'] = 'B7-SHORTCODE-OUTPUT';
$GLOBALS['B7_DO_SHORTCODE'] = array();
$GLOBALS['B7_REDIRECT']     = array();
$GLOBALS['B7_ATTACHMENT_URL'] = '';
$GLOBALS['B7_DB_VAR']       = null;
$GLOBALS['B7_DB_RESULTS']   = array();
$GLOBALS['B7_DB_FAIL']      = false;
$GLOBALS['B7_QUERY_LOG']      = array();
$GLOBALS['B7_QUERY_POSTS']    = array();
$GLOBALS['B7_POSTMETA']       = array();
$GLOBALS['B7_ENQUEUED']     = array();
$GLOBALS['B7_HEAD_MARK']    = '<!--B7-WP-HEAD-->';
$GLOBALS['B7_FOOT_MARK']    = '<!--B7-WP-FOOT-->';

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! class_exists( 'B7_Wpdb' ) ) {
	final class B7_Wpdb {
		public string $prefix      = 'wp_';
		public string $posts       = 'wp_posts';
		public string $last_error  = '';
		public function prepare( string $query, ...$args ): string {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			$index = 0;
			return (string) preg_replace_callback( '/%[sd]/', function ( array $matches ) use ( &$index, $args ): string {
				$arg = $args[ $index ] ?? '';
				$index++;
				if ( '%d' === $matches[0] ) {
					return (string) (int) $arg;
				}
				return "'" . addcslashes( (string) $arg, "'" ) . "'";
			}, $query );
		}
		public function get_results( $query, $output = null ): array {
			$GLOBALS['B7_QUERY_LOG'][] = 'db:' . (string) $query;
			if ( $GLOBALS['B7_DB_FAIL'] ) {
				$this->last_error = 'B7 injected db failure';
				return array();
			}
			return (array) $GLOBALS['B7_DB_RESULTS'];
		}
		public function get_var( $query = null ) {
			$GLOBALS['B7_QUERY_LOG'][] = 'db:' . (string) $query;
			if ( $GLOBALS['B7_DB_FAIL'] ) {
				$this->last_error = 'B7 injected db failure';
				return null;
			}
			foreach ( (array) ( $GLOBALS['B7_DB_VARS_BY_QUERY'] ?? array() ) as $needle => $value ) {
				if ( false !== strpos( (string) $query, (string) $needle ) ) {
					return $value;
				}
			}
			return $GLOBALS['B7_DB_VAR'];
		}
		public function esc_like( string $text ): string {
			return addcslashes( $text, '_%\\' );
		}
		public function query( $query ) {
			return 1;
		}
	}
}

if ( ! class_exists( 'B7_WpQuery' ) ) {
	class B7_WpQuery {
		public int $found_posts   = 0;
		public int $post_count    = 0;
		public int $max_num_pages = 0;
		public array $posts       = array();
		public array $query_args  = array();
		private ?array $fixture_rows = null;
		public function __construct( $args = array() ) {
			$this->query_args = (array) $args;
			if ( isset( $GLOBALS['B7_QUERY_ROWS_PER_INSTANCE'] ) ) {
				$this->fixture_rows = $GLOBALS['B7_QUERY_ROWS_PER_INSTANCE'];
			}
			$GLOBALS['B7_QUERY_LOG'][] = $this->query_args;
		}
		public function have_posts(): bool {
			if ( null !== $this->fixture_rows ) {
				return array() !== $this->fixture_rows;
			}
			return [] !== (array) ( $GLOBALS['B7_QUERY_POSTS'] ?? array() );
		}
		public function the_post(): void {
			if ( null !== $this->fixture_rows ) {
				$GLOBALS['post'] = array_shift( $this->fixture_rows );
				return;
			}
			$GLOBALS['post'] = array_shift( $GLOBALS['B7_QUERY_POSTS'] );
		}
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	/* WordPress boundary stub: the templates construct WP_Query directly. */
	class WP_Query extends B7_WpQuery {
	}
}
if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public int $ID;
		public function __construct( int $id ) { $this->ID = $id; }
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook_name, $callback = '', int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['B7_HOOKS'][ $hook_name ][] = array( 'type' => 'action', 'callback' => $callback, 'priority' => $priority );
		return true;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook_name, $callback = '', int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['B7_HOOKS'][ $hook_name ][] = array( 'type' => 'filter', 'callback' => $callback, 'priority' => $priority );
		return true;
	}
}
if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( string $hook_name, $callback, int $priority = 10 ): bool {
		return true;
	}
}
if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( string $hook_name, $callback = false ) {
		$entries = $GLOBALS['B7_HOOKS'][ $hook_name ] ?? array();
		if ( false === $callback ) {
			return array() !== $entries;
		}
		foreach ( $entries as $entry ) {
			if ( $entry['callback'] === $callback ) {
				return true;
			}
		}
		return false;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook_name, ...$args ): void {
		$GLOBALS['B7_ACTIONS'][ $hook_name ] = ( $GLOBALS['B7_ACTIONS'][ $hook_name ] ?? 0 ) + 1;
		foreach ( $GLOBALS['B7_HOOKS'][ $hook_name ] ?? array() as $entry ) {
			call_user_func_array( $entry['callback'], $args );
		}
	}
}
if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook_name ): int {
		return $GLOBALS['B7_ACTIONS'][ $hook_name ] ?? 0;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook_name, $value, ...$args ) {
		$GLOBALS['B7_FILTER_CALLS'][ $hook_name ] = ( $GLOBALS['B7_FILTER_CALLS'][ $hook_name ] ?? 0 ) + 1;
		foreach ( $GLOBALS['B7_HOOKS'][ $hook_name ] ?? array() as $entry ) {
			$value = call_user_func_array( $entry['callback'], array_merge( array( $value ), $args ) );
		}
		return $value;
	}
}
if ( ! function_exists( 'remove_action' ) ) {
	function remove_action( string $hook_name, $callback, int $priority = 10 ): bool {
		return true;
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
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ): string {
		$url = trim( (string) $url );
		return preg_match( '#^(https?:)?//#i', $url ) ? $url : '';
	}
}
if ( ! function_exists( 'esc_js' ) ) {
	function esc_js( $text ): string {
		return addcslashes( (string) $text, "\\'\"\n\r" );
	}
}
if ( ! function_exists( 'esc_textarea' ) ) {
	function esc_textarea( $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $content ): string {
		return (string) $content;
	}
}
if ( ! function_exists( 'selected' ) ) {
	function selected( $selected_value, $current = true, bool $display = true ): void {
		if ( (string) $selected_value === (string) $current && $display ) {
			echo ' selected="selected"';
		}
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
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public string $code = '';
		public string $message = '';
		public function __construct( $code = '', $message = '' ) {
			$this->code    = (string) $code;
			$this->message = (string) $message;
		}
		public function get_error_code() {
			return $this->code;
		}
		public function get_error_message() {
			return $this->message;
		}
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability, ...$object_args ): bool {
		$key = $capability . ( isset( $object_args[0] ) ? '_' . (int) $object_args[0] : '' );
		if ( array_key_exists( $key, $GLOBALS['B7_CAPS'] ) ) {
			return (bool) $GLOBALS['B7_CAPS'][ $key ];
		}
		if ( array_key_exists( $capability, $GLOBALS['B7_CAPS'] ) ) {
			return (bool) $GLOBALS['B7_CAPS'][ $capability ];
		}
		return false;
	}
}
if ( ! function_exists( 'user_can' ) ) {
	function user_can( $user_id, string $capability, ...$object_args ): bool {
		$uid = is_object( $user_id ) ? (int) ( $user_id->ID ?? 0 ) : (int) $user_id;
		$key = $uid . '|' . $capability . ( isset( $object_args[0] ) ? '_' . (int) $object_args[0] : '' );
		if ( array_key_exists( $key, $GLOBALS['B7_USER_CAN'] ) ) {
			return (bool) $GLOBALS['B7_USER_CAN'][ $key ];
		}
		$key_plain = $uid . '|' . $capability;
		if ( array_key_exists( $key_plain, $GLOBALS['B7_USER_CAN'] ) ) {
			return (bool) $GLOBALS['B7_USER_CAN'][ $key_plain ];
		}
		return false;
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return (int) $GLOBALS['B7_USER_ID'];
	}
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		return (bool) $GLOBALS['B7_LOGGED_IN'];
	}
}
if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user() {
		return $GLOBALS['B7_USER'];
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default_value = false ) {
		return array_key_exists( $option, $GLOBALS['B7_OPTIONS'] ) ? $GLOBALS['B7_OPTIONS'][ $option ] : $default_value;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, $value, $autoload = null ): bool {
		$GLOBALS['B7_OPTIONS'][ $option ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $option ): bool {
		unset( $GLOBALS['B7_OPTIONS'][ $option ] );
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ) {
		return $GLOBALS['B7_TRANSIENTS'][ $key ] ?? false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, $value, int $ttl = 0 ): bool {
		$GLOBALS['B7_TRANSIENTS'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $key ): bool {
		unset( $GLOBALS['B7_TRANSIENTS'][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post_id = null ) {
		$post_id = is_object( $post_id ) ? (int) ( $post_id->ID ?? 0 ) : (int) $post_id;
		return $GLOBALS['B7_POSTS'][ $post_id ] ?? null;
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $meta_key, bool $single = false ) {
		$GLOBALS['B7_META_CALLS'][ $post_id ][ $meta_key ] = ( $GLOBALS['B7_META_CALLS'][ $post_id ][ $meta_key ] ?? 0 ) + 1;
		return $GLOBALS['B7_POSTMETA'][ $post_id ][ $meta_key ] ?? '';
	}
}
if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( array $args = array() ): array {
		return array();
	}
}
if ( ! function_exists( 'get_categories' ) ) {
	function get_categories( $args = array() ): array {
		return array();
	}
}
if ( ! function_exists( 'wp_get_post_categories' ) ) {
	function wp_get_post_categories( $post_id, array $args = array() ): array {
		return array();
	}
}
if ( ! function_exists( 'get_the_ID' ) ) {
	function get_the_ID(): int {
		$post = $GLOBALS['post'] ?? null;
		return $post instanceof WP_Post ? (int) $post->ID : 0;
	}
}
if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( $post = null ): string {
		if ( $post instanceof WP_Post ) {
			return (string) $post->post_status;
		}
		$current = $GLOBALS['post'] ?? null;
		if ( null === $post && $current instanceof WP_Post && '' !== (string) $current->post_status ) {
			return (string) $current->post_status;
		}
		return 'publish';
	}
}
if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $post = null ): string {
		if ( $post instanceof WP_Post ) {
			return (string) $post->post_title;
		}
		$current = $GLOBALS['post'] ?? null;
		if ( null === $post && $current instanceof WP_Post ) {
			return (string) $current->post_title;
		}
		return '';
	}
}
if ( ! function_exists( 'get_the_modified_date' ) ) {
	function get_the_modified_date( $format = '', $post = null ): string {
		return '';
	}
}
if ( ! function_exists( 'the_title_attribute' ) ) {
	function the_title_attribute( $args = array() ): void {}
}
if ( ! function_exists( 'wp_reset_postdata' ) ) {
	function wp_reset_postdata(): void {}
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post = null ): string {
		if ( 42 === (int) $post ) {
			return 'https://site.test/owned-portal/';
		}
		if ( 99 === (int) $post ) {
			return 'https://site.test/unowned-dashboard/';
		}
		return 'https://site.test/permalink/';
	}
}
if ( ! function_exists( 'get_post_type_archive_link' ) ) {
	function get_post_type_archive_link( string $post_type ): string {
		return '';
	}
}
if ( ! function_exists( 'get_allowed_mime_types' ) ) {
	function get_allowed_mime_types(): array {
		return array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'pdf'          => 'application/pdf',
		);
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( ...$args ): string {
		$keys = array_keys( $args );
		if ( is_array( $args[0] ?? null ) ) {
			$query = http_build_query( $args[0] );
			$base = isset( $args[1] ) ? (string) $args[1] : 'https://site.test/dashboard/';
			return rtrim( $base, '/' ) . '/?' . $query;
		}
		return 'https://site.test/?' . http_build_query( array( end( $keys ) => end( $args ) ) );
	}
}
if ( ! function_exists( 'paginate_links' ) ) {
	function paginate_links( $args = array() ) {
		return '';
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		return 'https://site.test' . $path;
	}
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'https://site.test/wp-admin/' . $path;
	}
}
if ( ! function_exists( 'wp_logout_url' ) ) {
	function wp_logout_url( string $redirect_to = '' ): string {
		return 'https://site.test/wp-login.php?action=logout';
	}
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( string $action ): string {
		return 'b7-nonce-' . $action;
	}
}
if ( ! function_exists( 'wp_redirect' ) ) {
	function wp_redirect( string $location, int $status = 302 ): bool {
		$GLOBALS['B7_REDIRECT'][] = array( 'location' => $location, 'status' => $status );
		echo 'B7-REDIRECT-OK url=' . $location . "\n";
		return true;
	}
}
if ( ! function_exists( 'get_avatar_url' ) ) {
	function get_avatar_url( $id_or_email, $args = null ): string {
		return '';
	}
}
if ( ! function_exists( 'get_nav_menu_locations' ) ) {
	function get_nav_menu_locations(): array {
		return array();
	}
}
if ( ! function_exists( 'wp_get_nav_menu_items' ) ) {
	function wp_get_nav_menu_items( $menu ) {
		return array();
	}
}
if ( ! function_exists( 'language_attributes' ) ) {
	function language_attributes(): void {
		echo 'lang="en"';
	}
}
if ( ! function_exists( 'bloginfo' ) ) {
	function bloginfo( string $show = '' ): void {
		if ( 'charset' === $show ) {
			echo 'UTF-8';
		}
	}
}
if ( ! function_exists( 'wp_head' ) ) {
	function wp_head(): void {
		echo $GLOBALS['B7_HEAD_MARK'];
	}
}
if ( ! function_exists( 'wp_footer' ) ) {
	function wp_footer(): void {
		echo $GLOBALS['B7_FOOT_MARK'];
	}
}
if ( ! function_exists( 'wp_body_open' ) ) {
	function wp_body_open(): void {}
}
if ( ! function_exists( 'body_class' ) ) {
	function body_class( string $extra = '' ): void {
		echo 'class="' . htmlspecialchars( $extra, ENT_QUOTES ) . '"';
	}
}
if ( ! function_exists( 'is_rtl' ) ) {
	function is_rtl(): bool {
		return false;
	}
}
if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite(): bool {
		return false;
	}
}
if ( ! function_exists( 'is_plugin_active' ) ) {
	function is_plugin_active( string $plugin_file ): bool {
		return false;
	}
}
if ( ! function_exists( 'shortcode_exists' ) ) {
	function shortcode_exists( string $tag ): bool {
		return in_array( $tag, $GLOBALS['B7_SHORTCODES'], true );
	}
}
if ( ! function_exists( 'do_shortcode' ) ) {
	function do_shortcode( string $content ): string {
		$GLOBALS['B7_DO_SHORTCODE'][] = $content;
		return $GLOBALS['B7_SHORTCODE_OUT'];
	}
}
if ( ! function_exists( 'get_page_by_path' ) ) {
	function get_page_by_path( string $page_path, $output = null, string $post_type = 'page' ) {
		foreach ( $GLOBALS['B7_POSTS'] as $post ) {
			if ( $post instanceof WP_Post && $post->post_name === $page_path && $post->post_type === $post_type ) {
				return $post;
			}
		}
		return null;
	}
}
if ( ! function_exists( 'get_queried_object' ) ) {
	function get_queried_object() {
		return $GLOBALS['B7_QUERIED'];
	}
}
if ( ! function_exists( 'wp_attachment_is_image' ) ) {
	function wp_attachment_is_image( $attachment_id ): bool {
		return true;
	}
}
if ( ! function_exists( 'get_post_mime_type' ) ) {
	function get_post_mime_type( $attachment_id ): string {
		return 'image/png';
	}
}
if ( ! function_exists( 'wp_get_attachment_url' ) ) {
	function wp_get_attachment_url( $attachment_id ): string {
		return $GLOBALS['B7_ATTACHMENT_URL'];
	}
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( string $hook, array $args = array() ) {
		return false;
	}
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $timestamp, $recurrence, string $hook, array $args = array() ): bool {
		return true;
	}
}
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $timestamp, string $hook, array $args = array() ): bool {
		return true;
	}
}
if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( string $handle, string $name, array $data ): bool {
		$GLOBALS['B7_ENQUEUED'][ $name ] = $data;
		return true;
	}
}
if ( ! class_exists( 'WP_Post' ) ) {
	#[AllowDynamicProperties]
	class WP_Post {
		public int $ID = 0;
		public string $post_type = '';
		public string $post_name = '';
		public string $post_status = 'publish';
		public string $post_title = '';
		public string $post_content = '';
		public function __construct( array $fields = array() ) {
			foreach ( $fields as $key => $value ) {
				$this->{$key} = $value;
			}
		}
	}
}

/* ────────────────────────────────────────────────────────────────
 * Harness bookkeeping
 * ──────────────────────────────────────────────────────────────── */

function b7_check( string $id, bool $condition, string $pass, string $fail = '' ): void {
	$GLOBALS['B7_RESULTS'][] = array( 'id' => $id, 'ok' => $condition, 'pass' => $pass, 'fail' => $fail );
}

function b7_result_line( string $mode ): void {
	$fail = 0;
	foreach ( $GLOBALS['B7_RESULTS'] as $result ) {
		if ( ! $result['ok'] ) {
			$fail++;
			echo "FAIL [{$result['id']}] {$result['fail']}\n";
		}
	}
	echo 'B7-VERDICT ' . $mode . ( 0 === $fail ? ' ALL-ASSERTIONS-HELD' : ' ASSERTIONS-FAILED-' . $fail ) . "\n";
	exit( 0 === $fail ? 0 : 1 );
}

/* ════════════════════════════════════════════════════════════════
 * Release context + runtime loading + fixtures
 * ════════════════════════════════════════════════════════════════ */

function b7_define_release_context(): void {
	if ( ! defined( 'ABSPATH' ) ) {
		$abspath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'b7-' . getmypid() . DIRECTORY_SEPARATOR;
		if ( ! is_dir( $abspath ) ) {
			@mkdir( $abspath, 0777, true );
		}
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
	if ( ! defined( 'DB_HOST' ) ) {
		define( 'DB_HOST', 'b7-db-host' );
		define( 'DB_NAME', 'b7-db-name' );
	}
	if ( ! defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT' ) ) {
		define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT', $GLOBALS['B7_PROJECT'] . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR );
		define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_DIR', $GLOBALS['B7_PROJECT'] . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR );
		define( 'HAL_FRONTEND_DASHBOARD_RELEASE_ID', '7.0.0+' . str_repeat( 'b7', 16 ) );
		define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION', '7.0.0' );
		define(
			'HAL_FRONTEND_DASHBOARD_RUNTIME_URL',
			'https://example.test/releases/' . HAL_FRONTEND_DASHBOARD_RELEASE_ID . '/'
		);
	}
}

function b7_define_valid_profile(): void {
	if ( ! defined( 'HOSSAM_AI_RUNTIME_PROFILE' ) ) {
		define( 'HOSSAM_AI_RUNTIME_PROFILE', array(
			'per_minute' => 30, 'per_day' => 500, 'concurrent' => 2, 'max_attempts' => 3,
			'stale_pending' => 3600, 'processing_deadline' => 240,
			'wp_ai_client_timeout' => 60, 'direct_key_timeout' => 90,
		) );
	}
}

function b7_require_runtime(): void {
	require $GLOBALS['B7_PROJECT'] . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'bootstrap.php';
}

function b7_reset_fixtures(): void {
	$GLOBALS['B7_OPTIONS']    = array();
	$GLOBALS['B7_TRANSIENTS'] = array();
	$GLOBALS['B7_CAPS']       = array();
	$GLOBALS['B7_USER_CAN']   = array();
	$GLOBALS['B7_LOGGED_IN']  = true;
	$GLOBALS['B7_USER_ID']    = 7;
	$GLOBALS['B7_POSTS']      = array();
	$GLOBALS['B7_QUERIED']    = null;
	$GLOBALS['B7_SHORTCODES'] = array();
	$GLOBALS['B7_REDIRECT']   = array();
	$GLOBALS['B7_DO_SHORTCODE'] = array();
	$GLOBALS['B7_ATTACHMENT_URL'] = '';
	$GLOBALS['B7_DB_VAR']     = null;
	$GLOBALS['B7_DB_VARS_BY_QUERY'] = array();
	$GLOBALS['B7_DB_RESULTS'] = array();
	$GLOBALS['B7_DB_FAIL']    = false;
	$GLOBALS['B7_QUERY_LOG']    = array();
	$GLOBALS['B7_QUERY_POSTS']  = array();
	$GLOBALS['B7_POSTMETA']     = array();
	unset( $GLOBALS['post'] );
	$GLOBALS['B7_USER']       = new WP_Post( array(
		'ID'          => 7,
		'post_type'   => 'user-fixture',
		'display_name' => 'B7 Admin',
		'first_name'  => 'B7',
		'user_email'  => 'b7@example.test',
		'roles'       => array( 'administrator' ),
	) );
	$GLOBALS['B7_HOOKS']      = array();
	$GLOBALS['B7_ACTIONS']    = array();
}

function b7_user( int $id, string $display_name, array $roles = array( 'subscriber' ) ): WP_Post {
	return new WP_Post( array(
		'ID'           => $id,
		'post_type'    => 'user-fixture',
		'display_name' => $display_name,
		'first_name'   => '',
		'user_email'   => 'b7@example.test',
		'roles'        => $roles,
	) );
}

function b7_set_features( array $features ): void {
	HAL_Frontend_Dashboard_Settings_Repository::save( array( 'features' => $features ) );
	HAL_Frontend_Dashboard_Settings_Repository::invalidate_cache();
}

function b7_enable_all_features(): void {
	b7_set_features( array(
		'posts' => true, 'files' => true, 'inbox' => true, 'members' => true,
		'amelia' => true, 'finance' => true, 'store' => true,
		'wpml_translations' => true, 'rank_math_seo' => true,
		'ultimate_member_profile' => true, 'ai' => true,
	) );
}

/**
 * Seed the integration registry transient with an available Amelia so the
 * shell's calendar consumption points can render (the real registry builder
 * reports every plugin inactive in the harness; the cached shape is the
 * contract consumed by hossam_get_integration_decision).
 */
function b7_seed_amelia_registry(): void {
	set_transient( hossam_integration_registry_key(), array(
		'amelia' => array(
			'status'        => 'available',
			'plugin_active' => true,
			'version'       => '7.0-b7',
			'capabilities'  => array( 'bookings_shortcode' => true, 'elite_api_secret' => true, 'elite_api_client' => true ),
			'reason'        => '',
			'checked_at'    => '2026-09-15T00:00:00Z',
		),
	), 5 * MINUTE_IN_SECONDS );
}

function b7_render_shell(): string {
	/* The real template runs in the main script scope on WordPress; here
	 * the include happens in this function's scope, so provision $wpdb
	 * the way the global scope would (the shell also imports it via
	 * global $wpdb for the inbox section). */
	global $wpdb;
	if ( ! $wpdb instanceof B7_Wpdb ) {
		$wpdb = new B7_Wpdb();
	}
	ob_start();
	include $GLOBALS['B7_PROJECT'] . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'dashboard.php';
	return (string) ob_get_clean();
}

function b7_capture_part( string $part, array $args = array() ): string {
	ob_start();
	HAL_Frontend_Dashboard_Template_Controller::render_part( $part, $args );
	return (string) ob_get_clean();
}

function b7_spawn_mode( string $mode ): bool {
	$trace = (string) getenv( 'B7_SPAWN_TRACE' );
	if ( '' !== $trace ) {
		fwrite( STDERR, "B7-SPAWN-START {$mode}\n" );
	}
	$php      = PHP_BINARY;
	$script   = __FILE__;
	$command  = array( $php );
	$command[] = '-d';
	$command[] = 'extension_dir=' . HAL_TEST_EXT_DIR;
	$command[] = '-d';
	$command[] = 'extension=sodium';
	$command[] = '-d';
	$command[] = 'extension=zip';
	$command[] = $script;
	$command[] = $mode;
	/* stdout/stderr go to temp FILES, not pipes: the subprocess writes
	 * error_log() diagnostics to stderr while the parent drains stdout;
	 * pipe buffers would deadlock that pair on Windows. */
	$stdout_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'b7out-' . bin2hex( random_bytes( 8 ) ) . '.txt';
	$stderr_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'b7err-' . bin2hex( random_bytes( 8 ) ) . '.txt';
	$descriptors = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'file', $stdout_file, 'w' ),
		2 => array( 'file', $stderr_file, 'w' ),
	);
	$proc = proc_open( $command, $descriptors, $pipes );
	if ( ! is_resource( $proc ) ) {
		$escaped = array_map( 'escapeshellarg', $command );
		$proc = proc_open( implode( ' ', $escaped ), $descriptors, $pipes );
		if ( ! is_resource( $proc ) ) {
			b7_check( 'MODE-' . strtoupper( str_replace( '-', '_', $mode ) ), false, 'subprocess could not start', 'proc_open failed' );
			return false;
		}
	}
	fclose( $pipes[0] );
	$code = proc_close( $proc );
	$stdout = (string) file_get_contents( $stdout_file );
	$stderr = (string) file_get_contents( $stderr_file );
	@unlink( $stdout_file );
	@unlink( $stderr_file );
	if ( '' !== $trace ) {
		fwrite( STDERR, "B7-SPAWN-END {$mode} exit={$code}\n" );
	}
	$ok   = 0 === $code && false !== strpos( $stdout, 'B7-VERDICT ' . $mode . ' ALL-ASSERTIONS-HELD' );
	$detail = '';
	if ( ! $ok ) {
		$detail = 'exit ' . $code . '; stdout: ' . trim( $stdout ) . '; stderr: ' . trim( $stderr );
	}
	b7_check(
		'MODE-' . strtoupper( str_replace( '-', '_', $mode ) ),
		$ok,
		'subprocess mode ' . $mode . ' held all its assertions (exit 0)',
		$detail
	);
	return $ok;
}

/* ════════════════════════════════════════════════════════════════
 * MAIN MODE — inventory / controller contract / static scans
 * ════════════════════════════════════════════════════════════════ */

function b7_mode_main( string $project ): void {
	b7_define_release_context();
	b7_define_valid_profile();
	b7_reset_fixtures();
	b7_require_runtime();

	/* 1. Runtime loading: bootstrap reaches readiness with the batch-7
	 * infrastructure controller loaded (27 units). */
	b7_check( 'L1-runtime-ready', did_action( 'hal_frontend_dashboard_runtime_ready' ) >= 1,
		'runtime_ready fired after full load (27 units incl. infrastructure)',
		'runtime_ready did not fire' );
	b7_check( 'L2-controller-class', class_exists( 'HAL_Frontend_Dashboard_Template_Controller' ),
		'HAL_Frontend_Dashboard_Template_Controller loaded from infrastructure',
		'controller class missing after bootstrap' );
	b7_check( 'L3-settings-class', class_exists( 'HAL_Frontend_Dashboard_Settings_Repository' ),
		'Settings Repository loaded (batch-3 backend intact)',
		'Settings Repository missing' );
	b7_check( 'L4-setup-helpers', function_exists( 'hossam_is_dashboard' ) && function_exists( 'hossam_t' ) && function_exists( 'hossam_asset_uri' ),
		'core helpers present (setup/i18n)',
		'core helpers missing' );

	/* 2. template_include registration (batch-7 contract). */
	$registered = false;
	foreach ( $GLOBALS['B7_HOOKS']['template_include'] ?? array() as $entry ) {
		if ( array( 'HAL_Frontend_Dashboard_Template_Controller', 'resolve_template' ) === $entry['callback'] ) {
			$registered = true;
		}
	}
	b7_check( 'C1-template-include-registered', $registered,
		'template_include filter registered with the controller callback',
		'template_include filter missing or wrong callback' );

	/* 3. Infrastructure copy is byte-identical to the shared source (§6.1). */
	$source_sha = hash_file( 'sha256', $project . '/includes/class-template-controller.php' );
	$runtime_sha = hash_file( 'sha256', $project . '/runtime/infrastructure/class-template-controller.php' );
	b7_check( 'C2-infrastructure-byte-identity', is_string( $source_sha ) && $source_sha === $runtime_sha,
		'runtime/infrastructure copy is byte-identical to includes/ source (' . $source_sha . ')',
		'includes source=' . $source_sha . ' vs infrastructure=' . $runtime_sha );

	/* 4. Closed allowlist of the twelve documented parts (§18+§19). */
	$expected_parts = array( 'overview', 'posts', 'files', 'bookings', 'seo', 'translations', 'profile', 'inbox', 'finance', 'store', 'admin', 'members' );
	b7_check( 'C3-part-allowlist', HAL_Frontend_Dashboard_Template_Controller::PANEL_PARTS === $expected_parts,
		'PANEL_PARTS equals the twelve documented dashboard parts in order',
		'allowlist mismatch: ' . json_encode( HAL_Frontend_Dashboard_Template_Controller::PANEL_PARTS ) );

	/* 5. Predicate: no owned page → fail-closed false everywhere. */
	$GLOBALS['B7_QUERIED'] = new WP_Post( array( 'ID' => 12, 'post_type' => 'page', 'post_name' => 'dashboard' ) );
	b7_check( 'P1-no-owned-page-false', false === hossam_is_dashboard(),
		'hossam_is_dashboard() false with no owned page even for a slug-matched page (no slug reliance)',
		'predicate true without owned page' );
	b7_check( 'P2-no-owned-target-ids', array() === HAL_Frontend_Dashboard_Template_Controller::get_target_page_ids(),
		'get_target_page_ids() empty without an owned page',
		'expected [] got ' . json_encode( HAL_Frontend_Dashboard_Template_Controller::get_target_page_ids() ) );

	/* 6. Predicate: owned page → true for the ID and the queried object;
	 * slug-only match with different ID stays false. */
	$GLOBALS['B7_POSTS'][ 42 ] = new WP_Post( array( 'ID' => 42, 'post_type' => 'page', 'post_name' => 'dashboard' ) );
	$GLOBALS['B7_POSTS'][ 43 ] = new WP_Post( array( 'ID' => 43, 'post_type' => 'page', 'post_name' => 'another-page' ) );
	update_option( 'hal_frontend_dashboard_page_id', 42 );
	$GLOBALS['B7_QUERIED'] = new WP_Post( array( 'ID' => 42, 'post_type' => 'page', 'post_name' => 'dashboard' ) );
	b7_check( 'P3-owned-page-true', true === hossam_is_dashboard(),
		'hossam_is_dashboard() true for the owned page (delegates to controller predicate)',
		'predicate false for owned page' );
	b7_check( 'P4-owned-id-target', true === HAL_Frontend_Dashboard_Template_Controller::is_target_page_id( 42 ),
		'is_target_page_id(42) true for the owned page',
		'owned id not matched' );
	$GLOBALS['B7_QUERIED'] = new WP_Post( array( 'ID' => 43, 'post_type' => 'page', 'post_name' => 'dashboard' ) );
	b7_check( 'P5-slug-not-enough', false === hossam_is_dashboard(),
		'queried page with slug dashboard but a different ID is NOT the target (no slug hijack)',
		'slug matched without ownership' );
	$GLOBALS['B7_QUERIED'] = new WP_Post( array( 'ID' => 999, 'post_type' => 'post', 'post_name' => 'x' ) );
	b7_check( 'P6-non-page-false', false === hossam_is_dashboard(),
		'non-page queried object is never the target',
		'post type leaked into target' );

	/* 7. resolve_template: original template outside the target; runtime
	 * template (validated realpath inside the release) for the target. */
	$GLOBALS['B7_QUERIED'] = new WP_Post( array( 'ID' => 43, 'post_type' => 'page', 'post_name' => 'dashboard' ) );
	$original = '/theme/sample-template.php';
	b7_check( 'R1-nontarget-original', $original === HAL_Frontend_Dashboard_Template_Controller::resolve_template( $original ),
		'resolve_template returns the original template for a non-target page',
		'template changed outside target' );
	$GLOBALS['B7_QUERIED'] = new WP_Post( array( 'ID' => 42, 'post_type' => 'page', 'post_name' => 'dashboard' ) );
	$resolved = HAL_Frontend_Dashboard_Template_Controller::resolve_template( $original );
	$expected_template = realpath( $project . '/runtime/templates/dashboard.php' );
	$release_root_real = realpath( HAL_FRONTEND_DASHBOARD_RUNTIME_DIR );
	b7_check( 'R2-target-runtime-template', is_string( $resolved ) && $expected_template === $resolved,
		'resolve_template returns the validated runtime shell path for the owned page',
		'got ' . var_export( $resolved, true ) . ' expected ' . $expected_template );
	b7_check( 'R3-single-release-containment', is_string( $resolved ) && 0 === strpos( $resolved, $release_root_real . DIRECTORY_SEPARATOR ),
		'resolved template stays inside this request release root (single release contract)',
		'resolved path escapes the release root' );

	/* 8. render_part: closed allowlist + containment + fail-safe no output
	 * for invalid/missing parts. */
	b7_check( 'M1-invalid-part', '' === b7_capture_part( '../wp-config' ),
		'path traversal part name produces no output (fail-closed)',
		'invalid part produced output' );
	b7_check( 'M2-unknown-part', '' === b7_capture_part( 'notapart' ),
		'unknown part name produces no output (fail-closed)',
		'unknown part produced output' );
	/* Batch-8 update (justified, not weakened): the seven remaining parts
	 * now SHIP, so the "unshipped part" probe would assert on a shipped
	 * file. M3 now renders the real seo part through render_part with the
	 * owner feature enabled — the old M1/M2 keep covering the invalid/
	 * unknown-part fail-closed contract (which rejects before any file
	 * access), and batch-8's own harness covers the gated panels. */
	$GLOBALS['B7_CAPS'] = array( 'manage_options' => true, 'edit_posts' => true );
	b7_enable_all_features();
	$b8_seo_html = b7_capture_part( 'seo' );
	b7_check( 'M3-batch8-part-shipped', false !== strpos( $b8_seo_html, 'id="panel-seo"' ),
		'seo part (batch 8) renders the real panel markup through render_part with the owner feature enabled',
		'seo part did not render the panel markup' );
	$GLOBALS['B7_CAPS'] = array( 'manage_options' => true );
	b7_enable_all_features();
	$bookings_html = b7_capture_part( 'bookings' );
	b7_check( 'M4-bookings-renders', false !== strpos( $bookings_html, 'id="panel-appointments"' ),
		'bookings part renders the real panel-appointments markup',
		'bookings part output missing panel id' );
	b7_check( 'M5-bookings-not-configured', false !== strpos( $bookings_html, 'Appointment module not configured.' ),
		'bookings part renders the registry unavailable state safely',
		'registry fallback text missing' );

	/* 9. Explicit args reach the part scope (overview). */
	$args = array(
		'sees_content' => false, 'sees_calendar' => false, 'stat_all' => 3, 'stat_pending' => 1,
		'stat_published' => 2, 'stat_draft' => 0, 'activity_query' => new B7_WpQuery(),
		'can_upload' => false,
	);
	$overview_html = b7_capture_part( 'overview', $args );
	b7_check( 'M6-overview-args', false !== strpos( $overview_html, 'id="panel-overview"' ) && false !== strpos( $overview_html, 'Quick Actions' ),
		'overview renders from explicit named args (no get_defined_vars)',
		'overview output missing expected markers' );

	/* 10. Static scan (code only — comments stripped via tokens): no
	 * get_template_part / child-theme path helpers remain in the migrated
	 * template surfaces and the shared controller. */
	if ( ! function_exists( 'b7_strip_php_comments' ) ) {
		function b7_strip_php_comments( string $code ): string {
			$out     = '';
			$tokens  = token_get_all( $code );
			foreach ( $tokens as $token ) {
				if ( is_array( $token ) ) {
					if ( in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
						continue;
					}
					$out .= $token[1];
				} else {
					$out .= $token;
				}
			}
			return $out;
		}
	}
	$scan_files = array(
		'runtime/templates/dashboard.php',
		'runtime/templates/dashboard/overview.php',
		'runtime/templates/dashboard/posts.php',
		'runtime/templates/dashboard/files.php',
		'runtime/templates/dashboard/bookings.php',
		'runtime/templates/dashboard/profile.php',
		'runtime/infrastructure/class-template-controller.php',
	);
	$scan_clean = true;
	$scan_hits  = array();
	foreach ( $scan_files as $relative ) {
		$contents = b7_strip_php_comments( (string) file_get_contents( $project . '/' . $relative ) );
		foreach ( array( 'get_template_part(', 'get_stylesheet_directory', 'get_template_directory' ) as $needle ) {
			if ( false !== strpos( $contents, $needle ) ) {
				$scan_clean = false;
				$scan_hits[] = $relative . ' contains ' . $needle;
			}
		}
	}
	b7_check( 'S1-no-theme-part-loader', $scan_clean,
		'no get_template_part()/child-theme path helpers remain in the migrated surfaces (code, comments excluded)',
		implode( '; ', $scan_hits ) );
	$setup_source = b7_strip_php_comments( (string) file_get_contents( $project . '/runtime/core/setup.php' ) );
	b7_check( 'S2-no-template-slug-reliance', false === strpos( $setup_source, 'is_page(' ) && false === strpos( $setup_source, 'is_page_template(' ),
		'setup.php checked after token-level comment stripping (b7_strip_php_comments): neither is_page( nor is_page_template( in live code — both legacy checks absent (predicate delegated to the controller)',
		'setup.php still contains the legacy slug checks' );

	/* 11. Spawn the subprocess modes. */
	b7_spawn_mode( 'shell-admin' );
	b7_spawn_mode( 'shell-writer' );
	b7_spawn_mode( 'shell-employee' );
	b7_spawn_mode( 'shell-guest' );
	b7_spawn_mode( 'gates' );
	b7_spawn_mode( 'controller-nocontext' );
	b7_spawn_mode( 'controller-wpml' );

	b7_result_line( 'main' );
}

/* ════════════════════════════════════════════════════════════════
 * SHELL RENDER MODES — the real shell with real parts and fixtures
 * ════════════════════════════════════════════════════════════════ */

function b7_mode_shell_admin( string $project ): void {
	b7_define_release_context();
	b7_define_valid_profile();
	b7_reset_fixtures();
	b7_require_runtime();
	b7_enable_all_features();
	$GLOBALS['B7_CAPS'] = array(
		'manage_options' => true, 'edit_others_posts' => true, 'publish_posts' => true,
		'edit_posts' => true, 'edit_post_5' => true, 'upload_files' => true, 'edit_products' => true,
		'manage_woocommerce' => true, 'view_amelia_calendar_all' => true,
	);
	b7_seed_amelia_registry();
	$GLOBALS['B7_SHORTCODES'][] = 'ameliaemployeepanel';
	/* The owned dashboard has a different slug from a separate page named dashboard.
	 * Exercise the URL through the real shell and its posts part. */
	$GLOBALS['B7_POSTS'][42] = new WP_Post( array( 'ID' => 42, 'post_type' => 'page', 'post_name' => 'owned-portal' ) );
	$GLOBALS['B7_POSTS'][99] = new WP_Post( array( 'ID' => 99, 'post_type' => 'page', 'post_name' => 'dashboard' ) );
	update_option( 'hal_frontend_dashboard_page_id', 42 );
	$GLOBALS['B7_QUERY_ROWS_PER_INSTANCE'] = array(
		new WP_Post( array( 'ID' => 5, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'B7-Owned-URL-Article', 'post_content' => 'Body' ) ),
	);
	$login_url = apply_filters( 'login_redirect', 'https://site.test/default/', '', new WP_User( 7 ) );
	b7_check( 'A0-owned-login-url', 'https://site.test/owned-portal/' === $login_url,
		'non-admin login redirect uses the owned page permalink even when another page has slug dashboard',
		'got ' . var_export( $login_url, true ) );
	if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
		eval( 'function wp_ai_client_prompt( string $prompt ): B7_AiPromptGate { return new B7_AiPromptGate(); }' );
	}
	$html = b7_render_shell();
	unset( $GLOBALS['B7_QUERY_ROWS_PER_INSTANCE'] );
	b7_check( 'A0-owned-dashboard-url', false !== strpos( $html, 'https://site.test/owned-portal/?panel=edit-article' )
		&& false === strpos( $html, 'https://site.test/unowned-dashboard/' ),
		'shell renders article links against owned page ID 42 while slug dashboard belongs to page 99',
		'owned page URL missing or unowned slug URL rendered' );

	b7_check( 'A1-overview-panel', false !== strpos( $html, 'id="panel-overview"' ),
		'admin shell renders the overview panel (real part include)', 'panel-overview missing' );
	b7_check( 'A2-admin-nav', false !== strpos( $html, 'data-panel="admin"' ) && false !== strpos( $html, 'data-panel="members"' ),
		'admin shell renders Administration and Members nav items', 'admin nav items missing' );
	b7_check( 'A3-writer-nav', false !== strpos( $html, 'data-panel="write"' ) && false !== strpos( $html, 'data-panel="all-articles"' ),
		'admin shell renders the Articles nav group', 'articles nav missing' );
	b7_check( 'A4-media-nav', false !== strpos( $html, 'data-panel="media"' ),
		'admin shell renders the Files nav item', 'media nav missing' );
	b7_check( 'A5-appointments-nav', false !== strpos( $html, 'data-panel="appointments"' ),
		'admin shell renders the Appointments nav item', 'appointments nav missing' );
	b7_check( 'A6-seo-translation-nav', false !== strpos( $html, 'data-panel="seo"' ) && false !== strpos( $html, 'data-panel="translation"' ),
		'admin shell renders the SEO and Translations nav items', 'seo/translation nav missing' );
	b7_check( 'A7-profile-inbox-nav', false !== strpos( $html, 'data-panel="profile"' ) && false !== strpos( $html, 'data-panel="inbox"' ),
		'admin shell renders Profile and Inbox nav items', 'profile/inbox nav missing' );
	b7_check( 'A8-finance-store-nav', false !== strpos( $html, 'data-panel="finance"' ) && false !== strpos( $html, 'data-panel="store"' ),
		'admin shell renders Finance and Store nav items', 'finance/store nav missing' );
	b7_check( 'A9-posts-panels', false !== strpos( $html, 'id="panel-write"' ) && false !== strpos( $html, 'id="panel-all-articles"' ) && false !== strpos( $html, 'id="panel-trash-articles"' ),
		'admin shell renders the three writer posts panels', 'posts panels missing' );
	b7_check( 'A10-ai-buttons', false !== strpos( $html, 'js-ai-run' ),
		'AI buttons render for the admin with the ai strategy available', 'ai buttons missing' );
	b7_check( 'A11-logo-fallback-hal', false !== strpos( $html, '>HAL</div>' ) && false === strpos( $html, 'logo.png' ),
		'without a branding attachment the shell renders the recorded HAL text fallback and no logo file path',
		'HAL fallback missing or a fixed logo path leaked' );
	$GLOBALS['B7_ATTACHMENT_URL'] = 'https://site.test/wp-content/uploads/2026/09/brand.png';
	$GLOBALS['B7_POSTS'][ 55 ] = new WP_Post( array( 'ID' => 55, 'post_type' => 'attachment' ) );
	$GLOBALS['B7_OPTIONS'] = array();
	b7_enable_all_features();
	HAL_Frontend_Dashboard_Settings_Repository::save( array( 'branding_attachment_id' => 55 ) );
	$GLOBALS['B7_ATTACHMENT_URL'] = 'https://site.test/wp-content/uploads/2026/09/brand.png';
	$html_brand = b7_render_shell();
	b7_check( 'A12-branding-attachment', false !== strpos( $html_brand, 'src="https://site.test/wp-content/uploads/2026/09/brand.png"' ),
		'with a validated branding attachment the shell renders its URL from the Media Library',
		'branding attachment URL missing in output' );
	$html = $html_brand;
	b7_check( 'A13-head-foot-markers', false !== strpos( $html, $GLOBALS['B7_HEAD_MARK'] ) && false !== strpos( $html, $GLOBALS['B7_FOOT_MARK'] ),
		'shell calls wp_head() and wp_footer() directly (no theme header/footer include)',
		'wp_head/wp_footer markers missing' );
	b7_check( 'A14-no-shortcode-fallback-leak', false === strpos( $html, 'frontend_admin form=' ) || false !== strpos( $html, 'fa-fallback-write' ),
		'frontend_admin fallback stays inside its hidden container only',
		'fallback markup escaped its container' );
	b7_result_line( 'shell-admin' );
}

function b7_mode_shell_writer( string $project ): void {
	b7_define_release_context();
	b7_define_valid_profile();
	b7_reset_fixtures();
	b7_require_runtime();
	b7_enable_all_features();
	$GLOBALS['B7_USER_ID'] = 21;
	$GLOBALS['B7_USER']    = b7_user( 21, 'B7 Writer', array( 'author' ) );
	$GLOBALS['B7_CAPS']    = array( 'edit_posts' => true, 'publish_posts' => true, 'edit_others_posts' => false );
	$html = b7_render_shell();
	b7_check( 'W1-posts-panels', false !== strpos( $html, 'id="panel-write"' ) && false !== strpos( $html, 'id="panel-all-articles"' ),
		'writer shell renders write and all-articles panels', 'writer posts panels missing' );
	b7_check( 'W2-no-media-panel', false === strpos( $html, 'id="panel-media"' ) && false === strpos( $html, 'data-panel="media"' ),
		'writer without upload_files gets no Files panel or nav item', 'media leaked to writer' );
	b7_check( 'W3-no-bookings', false === strpos( $html, 'id="panel-appointments"' ) && false === strpos( $html, 'data-panel="appointments"' ),
		'writer without Amelia roles gets no bookings panel or nav item', 'bookings leaked to writer' );
	b7_check( 'W4-no-admin-panels', false === strpos( $html, 'data-panel="admin"' ) && false === strpos( $html, 'data-panel="members"' ),
		'non-admin gets no Administration/Members nav items', 'admin surfaces leaked' );
	b7_check( 'W5-articles-badge', false !== strpos( $html, 'id="articles-badge"' ),
		'writer sees the articles count badge', 'articles badge missing' );
	b7_result_line( 'shell-writer' );
}

function b7_mode_shell_employee( string $project ): void {
	b7_define_release_context();
	b7_define_valid_profile();
	b7_reset_fixtures();
	b7_require_runtime();
	b7_enable_all_features();
	b7_seed_amelia_registry();
	$GLOBALS['B7_SHORTCODES'][] = 'ameliaemployeepanel';
	$GLOBALS['B7_USER_ID'] = 22;
	$GLOBALS['B7_USER']    = b7_user( 22, 'B7 Employee', array( 'amelia_employee' ) );
	$GLOBALS['B7_CAPS']    = array( 'view_amelia_calendar' => true );
	$html = b7_render_shell();
	b7_check( 'E1-bookings-panel', false !== strpos( $html, 'id="panel-appointments"' ),
		'Amelia employee shell renders the bookings panel', 'bookings panel missing' );
	b7_check( 'E2-appointments-nav', false !== strpos( $html, 'data-panel="appointments"' ),
		'Amelia employee shell renders the Appointments nav item', 'appointments nav missing' );
	b7_check( 'E3-no-posts', false === strpos( $html, 'id="panel-write"' ) && false === strpos( $html, 'data-panel="write"' ),
		'employee without edit_posts gets no writer surfaces', 'writer surfaces leaked' );
	b7_check( 'E4-inbox-visible', false !== strpos( $html, 'data-panel="inbox"' ),
		'employee sees the inbox nav item (source behaviour)', 'inbox nav missing for employee' );
	b7_result_line( 'shell-employee' );
}

function b7_mode_shell_guest( string $project ): void {
	b7_define_release_context();
	b7_define_valid_profile();
	b7_reset_fixtures();
	b7_require_runtime();
	b7_enable_all_features();
	/* The real Auth Guard calls wp_redirect() then exit() — the verdict is
	 * emitted from the shutdown handler after the exit, based on the
	 * captured redirect target (hossam_login_url() → /login/ fallback). */
	register_shutdown_function( function (): void {
		$redirect = $GLOBALS['B7_REDIRECT'][0] ?? null;
		$ok       = is_array( $redirect ) && false !== strpos( (string) ( $redirect['location'] ?? '' ), '/login/' );
		echo 'B7-VERDICT shell-guest ' . ( $ok ? 'ALL-ASSERTIONS-HELD' : 'ASSERTIONS-FAILED-redirect' ) . "\n";
	} );
	$GLOBALS['B7_LOGGED_IN'] = false;
	b7_render_shell();
	exit( 1 );
}

function b7_mode_gates( string $project ): void {
	b7_define_release_context();
	b7_define_valid_profile();
	b7_reset_fixtures();
	b7_require_runtime();

	/* Admin with every capability and strategy: toggling each feature off
	 * must remove its consumption points; enabling restores them. */
	$GLOBALS['B7_CAPS'] = array(
		'manage_options' => true, 'edit_others_posts' => true, 'publish_posts' => true,
		'edit_posts' => true, 'upload_files' => true, 'edit_products' => true,
		'manage_woocommerce' => true, 'view_amelia_calendar_all' => true,
	);
	b7_seed_amelia_registry();
	$GLOBALS['B7_SHORTCODES'][] = 'ameliaemployeepanel';
	if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
		eval( 'function wp_ai_client_prompt( string $prompt ): B7_AiPromptGate { return new B7_AiPromptGate(); }' );
	}

	$consumptions = array(
		'posts' => array( 'data-panel="write"', 'id="panel-write"' ),
		'files' => array( 'data-panel="media"', 'id="panel-media"' ),
		'amelia' => array( 'data-panel="appointments"', 'id="panel-appointments"' ),
		'finance' => array( 'data-panel="finance"' ),
		'store' => array( 'data-panel="store"' ),
		'inbox' => array( 'data-panel="inbox"', 'id="hdr-inbox-badge"' ),
		'ultimate_member_profile' => array( 'data-panel="profile"', 'id="panel-profile"' ),
		'rank_math_seo' => array( 'data-panel="seo"' ),
		'wpml_translations' => array( 'data-panel="translation"' ),
		'members' => array( 'data-panel="members"' ),
		'ai' => array( 'js-ai-run' ),
	);
	foreach ( $consumptions as $feature => $markers ) {
		b7_enable_all_features();
		$enabled_html = b7_render_shell();
		$all_enabled  = true;
		foreach ( $markers as $marker ) {
			if ( false === strpos( $enabled_html, $marker ) ) {
				$all_enabled = false;
			}
		}
		b7_check( 'G-ON-' . $feature, $all_enabled,
			'feature ' . $feature . ' enabled → all its consumption points render',
			'missing markers with feature enabled: ' . json_encode( $markers ) );

		b7_set_features( array( $feature => false ) );
		$disabled_html = b7_render_shell();
		$none_rendered = true;
		foreach ( $markers as $marker ) {
			if ( false !== strpos( $disabled_html, $marker ) ) {
				$none_rendered = false;
			}
		}
		b7_check( 'G-OFF-' . $feature, $none_rendered,
			'feature ' . $feature . ' disabled → none of its consumption points render (B3-08 template gate)',
			'disabled feature still consumed: ' . $feature );
		b7_check( 'G-OVERVIEW-KEPT-' . $feature, false !== strpos( $disabled_html, 'id="panel-overview"' ),
			'overview panel stays available while ' . $feature . ' is disabled',
			'overview lost while toggling ' . $feature );
	}

	/* B7-03 (closure §15.32): post/inbox query gating + activity visibility
	 * and per-row seo/translate gates, all with non-empty fixtures. */
	$GLOBALS['B7_DB_RESULTS'] = array(
		(object) array( 'post_status' => 'publish', 'cnt' => 3 ),
		(object) array( 'post_status' => 'draft', 'cnt' => 2 ),
	);
	$GLOBALS['B7_QUERY_POSTS'] = array(
		new WP_Post( array( 'ID' => 5, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'B7-Activity-Post', 'post_content' => 'Body' ) ),
	);
	$GLOBALS['B7_DB_VARS_BY_QUERY'] = array(
		'SHOW TABLES LIKE' => 'wp_hossam_messages',
		'SELECT COUNT(*) FROM wp_hossam_messages' => '4',
	);
	b7_enable_all_features();
	b7_set_features( array( 'rank_math_seo' => false ) );
	$GLOBALS['B7_QUERY_LOG'] = array();
	$posts_on_html = b7_render_shell();
	$post_queries_on = 0;
	$post_count_sql_on = 0;
	$inbox_table_queries_on = 0;
	$inbox_count_queries_on = 0;
	foreach ( $GLOBALS['B7_QUERY_LOG'] as $logged ) {
		if ( is_array( $logged ) && 'post' === ( $logged['post_type'] ?? '' ) ) {
			$post_queries_on++;
		}
		if ( is_string( $logged ) && false !== strpos( $logged, 'SHOW TABLES LIKE' ) ) {
			$inbox_table_queries_on++;
		}
		if ( is_string( $logged ) && false !== strpos( $logged, 'SELECT COUNT(*) FROM wp_hossam_messages' ) ) {
			$inbox_count_queries_on++;
		}
		if ( is_string( $logged ) && false !== strpos( $logged, 'post_type =' ) ) {
			$post_count_sql_on++;
		}
	}
	b7_check( 'G-POSTS-QUERIES-RUN', $post_queries_on > 0 && $post_count_sql_on > 0 && false !== strpos( $posts_on_html, 'B7-Activity-Post' )
		&& false !== strpos( $posts_on_html, 'class="card-title">Recent Activity</div>' )
		&& false !== strpos( $posts_on_html, '<div class="stat-val">5</div>' ),
		'posts enabled with data → post queries run and the activity card renders its row',
		'queries ' . $post_queries_on );
	b7_check( 'G-INBOX-COUNT-RUN', 1 === $inbox_table_queries_on && 1 === $inbox_count_queries_on
		&& 1 === preg_match( '/id="hdr-inbox-badge" data-state="ok"[^>]*>4<\/span>/', $posts_on_html ),
		'inbox enabled with a present table and count 4 → its COUNT query runs and the inbox badge reports 4',
		'table queries ' . $inbox_table_queries_on . '; count queries ' . $inbox_count_queries_on );

	b7_set_features( array( 'posts' => false, 'rank_math_seo' => false ) );
	$GLOBALS['B7_QUERY_POSTS'] = array(
		new WP_Post( array( 'ID' => 5, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'B7-Activity-Post', 'post_content' => 'Body' ) ),
	);
	$GLOBALS['B7_DB_VARS_BY_QUERY'] = array(
		'SHOW TABLES LIKE' => 'wp_hossam_messages',
		'SELECT COUNT(*) FROM wp_hossam_messages' => '4',
	);
	$GLOBALS['B7_QUERY_LOG'] = array();
	$posts_off_html = b7_render_shell();
	$post_queries_off = 0;
	$post_count_sql_off = 0;
	foreach ( $GLOBALS['B7_QUERY_LOG'] as $logged ) {
		if ( is_array( $logged ) && 'post' === ( $logged['post_type'] ?? '' ) ) {
			$post_queries_off++;
		}
		if ( is_string( $logged ) && false !== strpos( $logged, 'post_type =' ) ) {
			$post_count_sql_off++;
		}
	}
	b7_check( 'G-POSTS-QUERIES-SKIPPED', 0 === $post_queries_off && 0 === $post_count_sql_off && false === strpos( $posts_off_html, 'B7-Activity-Post' )
		&& false === strpos( $posts_off_html, 'class="card-title">Recent Activity</div>' )
		&& false !== strpos( $posts_off_html, 'id="panel-overview"' ),
		'posts disabled with data present → no post queries run and no activity surfaces, overview itself stays',
		'queries ' . $post_queries_off . '; post count SQL ' . $post_count_sql_off
		. '; activity row ' . (int) ( false !== strpos( $posts_off_html, 'B7-Activity-Post' ) )
		. '; activity card ' . (int) ( false !== strpos( $posts_off_html, 'class="card-title">Recent Activity</div>' ) )
		. '; overview ' . (int) ( false !== strpos( $posts_off_html, 'id="panel-overview"' ) ) );

	b7_enable_all_features();
	$GLOBALS['B7_QUERY_LOG'] = array();
	b7_set_features( array( 'inbox' => false ) );
	$inbox_off_html = b7_render_shell();
	$inbox_queries_off = 0;
	foreach ( $GLOBALS['B7_QUERY_LOG'] as $logged ) {
		if ( is_string( $logged ) && false !== strpos( $logged, 'hossam_messages' ) ) {
			$inbox_queries_off++;
		}
	}
	b7_check( 'G-INBOX-COUNT-SKIPPED', 0 === $inbox_queries_off && false === strpos( $inbox_off_html, 'id="hdr-inbox-badge"' ),
		'inbox disabled with data present → no inbox query runs and its badge is absent',
		'inbox queries ' . $inbox_queries_off );

	/* Per-row seo/translate gates inside the posts part (direct render
	 * with a non-empty article row). */
	if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
		define( 'ICL_SITEPRESS_VERSION', 'B7-FIXTURE' );
	}
	add_filter( 'wpml_active_languages', static function ( $value ) {
		return array( 'en' => array( 'language_code' => 'en' ), 'ar' => array( 'language_code' => 'ar' ) );
	} );
	add_filter( 'wpml_current_language', static function ( $value ) {
		return 'en';
	} );
	add_filter( 'wpml_object_id', static function ( $value ) {
		return 0;
	} );
	$GLOBALS['B7_CAPS'] = array( 'edit_posts' => true, 'edit_post_5' => true );
	$GLOBALS['B7_POSTMETA'][5] = array(
		'rank_math_seo_score'    => '85',
		'rank_math_focus_keyword' => 'B7KW',
		'rank_math_description'  => 'B7 description',
		'rank_math_robots'       => array( 'index' ),
	);
	$wpml_available = array( 'status' => 'available', 'capabilities' => array( 'languages' => true ) );
	$rm_available   = array( 'status' => 'available', 'capabilities' => array() );
	/* Seed the WordPress transient boundary used by the real registry;
	 * posts.php resolves these decisions itself instead of consuming args. */
	$registry_key = hossam_integration_registry_key();
	$registry = (array) get_transient( $registry_key );
	$registry['wpml'] = $wpml_available;
	$registry['rank_math'] = $rm_available;
	set_transient( $registry_key, $registry, 5 * MINUTE_IN_SECONDS );
	$posts_part_args = array(
		'sees_content' => true, 'is_writer' => true, 'is_author' => false, 'is_editor' => false, 'is_admin' => false,
		'author_filter' => false, 'articles_query' => new B7_WpQuery(),
		'posts_frontend_admin' => array( 'status' => 'unavailable', 'capabilities' => array() ),
		'posts_frontend_fallback' => false, '_dash_base' => 'https://site.test/dashboard/',
		'url_panel' => '', 'url_post_id' => 0,
		'hossam_edit_panel_allowed' => false, 'hossam_delete_panel_allowed' => false,
	);
	$GLOBALS['B7_QUERY_POSTS'] = array(
		new WP_Post( array( 'ID' => 5, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'B7-Seo-Article', 'post_content' => 'Body' ) ),
	);
	b7_enable_all_features();
	$GLOBALS['B7_META_CALLS'] = array();
	$GLOBALS['B7_FILTER_CALLS'] = array();
	$seo_on_html = b7_capture_part( 'posts', $posts_part_args );
	b7_check( 'G-POSTS-SEO-READ', false !== strpos( $seo_on_html, '85%' ) && false !== strpos( $seo_on_html, 'B7-Seo-Article' )
		&& ( $GLOBALS['B7_META_CALLS'][5]['rank_math_seo_score'] ?? 0 ) > 0,
		'rank_math_seo enabled with data → the row reads and shows the real seo score',
		'seo value missing' );
	b7_check( 'G-TRANSLATE-BUTTONS', false !== strpos( $seo_on_html, 'js-translate-article' )
		&& ( $GLOBALS['B7_FILTER_CALLS']['wpml_post_language_details'] ?? 0 ) > 0,
		'wpml_translations enabled with languages → per-row translate buttons render',
		'translate buttons missing' );
	b7_set_features( array( 'rank_math_seo' => false ) );
	$GLOBALS['B7_META_CALLS'] = array();
	$GLOBALS['B7_QUERY_POSTS'] = array(
		new WP_Post( array( 'ID' => 5, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'B7-Seo-Article', 'post_content' => 'Body' ) ),
	);
	$seo_off_html = b7_capture_part( 'posts', $posts_part_args );
	b7_check( 'G-POSTS-SEO-OFF', false === strpos( $seo_off_html, '85%' ) && false !== strpos( $seo_off_html, 'B7-Seo-Article' )
		&& 0 === ( $GLOBALS['B7_META_CALLS'][5]['rank_math_seo_score'] ?? 0 ),
		'rank_math_seo disabled with data present → no seo read (row still lists the article)',
		'seo value leaked' );
	b7_enable_all_features();
	b7_set_features( array( 'wpml_translations' => false ) );
	$GLOBALS['B7_FILTER_CALLS'] = array();
	$GLOBALS['B7_QUERY_POSTS'] = array(
		new WP_Post( array( 'ID' => 5, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'B7-Seo-Article', 'post_content' => 'Body' ) ),
	);
	$translate_off_html = b7_capture_part( 'posts', $posts_part_args );
	b7_check( 'G-TRANSLATE-OFF', false === strpos( $translate_off_html, 'js-translate-article' )
		&& 0 === ( $GLOBALS['B7_FILTER_CALLS']['wpml_post_language_details'] ?? 0 )
		&& 0 === ( $GLOBALS['B7_FILTER_CALLS']['wpml_active_languages'] ?? 0 )
		&& false !== strpos( $translate_off_html, 'B7-Seo-Article' ),
		'wpml_translations disabled with data present → no translate buttons (row still lists the article)',
		'translate buttons leaked' );
	b7_enable_all_features();
	b7_set_features( array( 'posts' => false ) );
	$GLOBALS['B7_QUERY_POSTS'] = array(
		new WP_Post( array( 'ID' => 5, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'B7-Seo-Article' ) ),
	);
	$posts_disabled_html = b7_capture_part( 'posts', $posts_part_args );
	b7_check( 'G-POSTS-PART-DIRECT-OFF', false === strpos( $posts_disabled_html, 'id="panel-' )
		&& false === strpos( $posts_disabled_html, 'B7-Seo-Article' ),
		'posts part refuses stale positive shell args when posts is disabled and rows exist',
		'disabled posts part still rendered a panel or row' );

	/* Quick actions consume the same gates (write/upload/appointments/profile).
	 * Batch-8 update (justified, not weakened): admin.php now ships and its
	 * Role Access Matrix repeats the plain-text button labels, so the probes
	 * target the unique quick-action onclick wiring instead of shared labels. */
	b7_enable_all_features();
	b7_set_features( array( 'posts' => false, 'files' => false, 'amelia' => false, 'ultimate_member_profile' => false ) );
	$qa_html = b7_render_shell();
	$qa_clean = false === strpos( $qa_html, "nav(document.querySelector('[data-panel=write]'),'write')" )
		&& false === strpos( $qa_html, "nav(document.querySelector('[data-panel=media]'),'media')" )
		&& false === strpos( $qa_html, "nav(document.querySelector('[data-panel=appointments]'),'appointments')" );
	b7_check( 'G-QUICK-ACTIONS', $qa_clean,
		'quick actions honour the owner gates for posts/files/amelia',
		'quick actions leaked disabled features' );

	/* The owner toggle is an ADDITIONAL layer: capability conditions of the
	 * source stay intact (a subscriber sees none of the writer surfaces
	 * even with every feature enabled). */
	b7_enable_all_features();
	$GLOBALS['B7_CAPS'] = array();
	$subscriber_html = b7_render_shell();
	b7_check( 'G-CAPABILITY-LAYER', false === strpos( $subscriber_html, 'id="panel-write"' ) && false === strpos( $subscriber_html, 'id="panel-media"' ),
		'capabilities remain the security layer (subscriber gets no gated surfaces even with features on)',
		'feature gate replaced the capability layer' );

	b7_result_line( 'gates' );
}

/* ════════════════════════════════════════════════════════════════
 * CONTROLLER-ONLY MODES — fail-safe without release context; WPML
 * ════════════════════════════════════════════════════════════════ */

function b7_mode_controller_nocontext( string $project ): void {
	/* No release context constants: the controller must still register and
	 * fail safe to the original template. */
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'b7nc-' . getmypid() . DIRECTORY_SEPARATOR );
	}
	b7_reset_fixtures();
	require $project . '/includes/class-template-controller.php';

	$registered = false;
	foreach ( $GLOBALS['B7_HOOKS']['template_include'] ?? array() as $entry ) {
		if ( array( 'HAL_Frontend_Dashboard_Template_Controller', 'resolve_template' ) === $entry['callback'] ) {
			$registered = true;
		}
	}
	b7_check( 'N1-registered-without-context', $registered,
		'controller registers template_include even without release context',
		'registration missing without context' );

	$GLOBALS['B7_POSTS'][ 42 ] = new WP_Post( array( 'ID' => 42, 'post_type' => 'page', 'post_name' => 'dashboard' ) );
	update_option( 'hal_frontend_dashboard_page_id', 42 );
	$GLOBALS['B7_QUERIED'] = new WP_Post( array( 'ID' => 42, 'post_type' => 'page', 'post_name' => 'dashboard' ) );
	$original = '/theme/original-template.php';
	b7_check( 'N2-failsafe-original', $original === HAL_Frontend_Dashboard_Template_Controller::resolve_template( $original ),
		'without release context the original template is returned (fail-safe §7.5)',
		'template resolved without release context' );
	b7_check( 'N3-failsafe-part-no-context', '' === b7_capture_part( 'overview', array() ),
		'render_part without release context produces no output (fail-safe)',
		'part rendered without release context' );
	b7_result_line( 'controller-nocontext' );
}

function b7_mode_controller_wpml( string $project ): void {
	b7_define_release_context();
	b7_reset_fixtures();
	require_once $project . '/runtime/infrastructure/class-template-controller.php';
	if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
		define( 'ICL_SITEPRESS_VERSION', 'B7-FIXTURE' );
	}
	/* Official WPML filters (stubs at the boundary only). */
	add_filter( 'wpml_element_trid', function ( $value, $element_id, $element_type ) {
		return 777;
	}, 10, 3 );
	add_filter( 'wpml_get_element_translations', function ( $value, $trid, $element_type ) {
		return array(
			'en' => (object) array( 'element_id' => 42, 'language_code' => 'en' ),
			'de' => (object) array( 'element_id' => 202, 'language_code' => 'de' ),
			'ar' => (object) array( 'element_id' => 303, 'language_code' => 'ar' ),
		);
	}, 10, 3 );

	$GLOBALS['B7_POSTS'][ 42 ] = new WP_Post( array( 'ID' => 42, 'post_type' => 'page', 'post_name' => 'dashboard' ) );
	update_option( 'hal_frontend_dashboard_page_id', 42 );

	$ids = HAL_Frontend_Dashboard_Template_Controller::get_target_page_ids();
	b7_check( 'W1-translation-targets', array( 42, 202, 303 ) === $ids,
		'target ids cover the owned page and its WPML translations via official filters',
		'got ' . json_encode( $ids ) );
	b7_check( 'W2-translation-match', true === HAL_Frontend_Dashboard_Template_Controller::is_target_page_id( 303 ),
		'a WPML translation id matches the target predicate',
		'translation id not matched' );
	$GLOBALS['B7_QUERIED'] = new WP_Post( array( 'ID' => 202, 'post_type' => 'page', 'post_name' => 'dashboard-de' ) );
	$original = '/theme/original-template.php';
	$resolved = HAL_Frontend_Dashboard_Template_Controller::resolve_template( $original );
	b7_check( 'W3-translation-template', is_string( $resolved ) && realpath( $project . '/runtime/templates/dashboard.php' ) === $resolved,
		'the WPML translation of the owned page receives the runtime template',
		'translation page did not resolve to the runtime template' );
	b7_result_line( 'controller-wpml' );
}

/* ════════════════════════════════════════════════════════════════
 * Runner
 * ════════════════════════════════════════════════════════════════ */

$GLOBALS['B7_RESULTS'] = array();

if ( ! class_exists( 'B7_AiPromptGate' ) ) {
	class B7_AiPromptGate {
		public function is_supported_for_text_generation(): bool {
			return true;
		}
		public function generate_text(): string {
			return 'B7 generated text';
		}
	}
}

$mode = $argv[1] ?? 'main';
$project = $GLOBALS['B7_PROJECT'];

switch ( $mode ) {
	case 'main':
		b7_mode_main( $project );
		break;
	case 'shell-admin':
		b7_mode_shell_admin( $project );
		break;
	case 'shell-writer':
		b7_mode_shell_writer( $project );
		break;
	case 'shell-employee':
		b7_mode_shell_employee( $project );
		break;
	case 'shell-guest':
		b7_mode_shell_guest( $project );
		break;
	case 'gates':
		b7_mode_gates( $project );
		break;
	case 'controller-nocontext':
		b7_mode_controller_nocontext( $project );
		break;
	case 'controller-wpml':
		b7_mode_controller_wpml( $project );
		break;
	default:
		fwrite( STDERR, "Unknown mode: {$mode}\n" );
		exit( 1 );
}
