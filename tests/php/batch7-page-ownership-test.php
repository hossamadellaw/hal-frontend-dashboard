<?php
/** Installer page ownership contract with only WordPress storage APIs stubbed. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'ICL_SITEPRESS_VERSION', 'fixture' );

class WP_Post {
	public function __construct( public int $ID, public string $post_type = 'page', public string $post_status = 'publish', public string $post_name = '' ) {}
}
class WP_Error {}

$GLOBALS['b7_pages'] = array();
$GLOBALS['b7_meta'] = array();
$GLOBALS['b7_options'] = array();
$GLOBALS['b7_next'] = 100;
$GLOBALS['b7_insert_count'] = 0;
$GLOBALS['b7_fail'] = '';

function get_option( $key, $default = false ) { return $GLOBALS['b7_options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) {
	if ( 'option' === $GLOBALS['b7_fail'] ) return false;
	$GLOBALS['b7_options'][ $key ] = $value;
	return true;
}
function get_post( $id ) { return $GLOBALS['b7_pages'][ $id ] ?? null; }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['b7_meta'][ $id ][ $key ] ?? ''; }
function get_posts( $args ) {
	$ids = array();
	foreach ( $GLOBALS['b7_pages'] as $id => $page ) {
		if ( 'page' === $page->post_type && in_array( $page->post_status, $args['post_status'], true )
			&& ( $GLOBALS['b7_meta'][ $id ][ $args['meta_key'] ] ?? '' ) === $args['meta_value'] ) {
			$ids[] = $id;
		}
	}
	return array_slice( $ids, 0, $args['posts_per_page'] );
}
function wp_insert_post( $args, $wp_error = false ) {
	$GLOBALS['b7_insert_count']++;
	if ( 'insert' === $GLOBALS['b7_fail'] ) return new WP_Error();
	$id = ++$GLOBALS['b7_next'];
	$slug = $args['post_name'];
	foreach ( $GLOBALS['b7_pages'] as $existing ) {
		if ( 'page' === $existing->post_type && $slug === $existing->post_name ) {
			$slug .= '-2';
			break;
		}
	}
	$GLOBALS['b7_pages'][ $id ] = new WP_Post( $id, $args['post_type'], $args['post_status'], $slug );
	if ( 'meta' !== $GLOBALS['b7_fail'] ) $GLOBALS['b7_meta'][ $id ] = $args['meta_input'];
	return $id;
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function absint( $value ) { return abs( (int) $value ); }
function get_permalink( $id ) { return 'https://site.test/' . get_post( $id )->post_name . '/'; }
function home_url( $path = '/' ) { return 'https://site.test/'; }
function has_filter( $name ) { return 'wpml_permalink' === $name; }
function apply_filters( $name, $value, ...$args ) {
	$GLOBALS['b7_wpml_input'] = $value;
	return 'https://site.test/ar/owned-portal/';
}

require dirname( __DIR__, 2 ) . '/includes/class-installer.php';
require dirname( __DIR__, 2 ) . '/runtime/adapters/wpml.php';
$method = new ReflectionMethod( HAL_Frontend_Dashboard_Installer::class, 'ensure_dashboard_page' );
$assert = static function ( string $name, bool $ok ): void {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . "\n";
	if ( ! $ok ) exit( 1 );
};
$code = static function () use ( $method ): string {
	try { $method->invoke( null ); return ''; }
	catch ( RuntimeException $error ) { return $error->getMessage(); }
};

// An unrelated page owns the dashboard slug before a clean activation.
$GLOBALS['b7_pages'][99] = new WP_Post( 99, 'page', 'publish', 'dashboard' );
$assert( 'clean install', '' === $code() );
$owned = (int) get_option( HAL_Frontend_Dashboard_Installer::PAGE_ID_OPTION );
$assert( 'different owned page and verified marker', 99 !== $owned && 'dashboard-2' === get_post( $owned )->post_name
	&& 'dashboard' === get_post( 99 )->post_name && 1 === $GLOBALS['b7_insert_count']
	&& '1' === get_post_meta( $owned, HAL_Frontend_Dashboard_Installer::PAGE_META_KEY, true ) );
$assert( 'repeat activation', '' === $code() && 1 === $GLOBALS['b7_insert_count'] );
$GLOBALS['b7_pages'][ $owned ]->post_name = 'owned-portal';
$assert( 'URL follows owned ID through WPML adapter', 'https://site.test/ar/owned-portal/' === hossam_dashboard_url()
	&& 'https://site.test/owned-portal/' === $GLOBALS['b7_wpml_input'] );

// The stored ID is already a verified legacy assignment, even without HAL meta.
$GLOBALS['b7_options'][ HAL_Frontend_Dashboard_Installer::PAGE_ID_OPTION ] = 77;
$GLOBALS['b7_pages'][77] = new WP_Post( 77, 'page', 'private', 'legacy-portal' );
$assert( 'assigned legacy ID reused', '' === $code() && 1 === $GLOBALS['b7_insert_count'] );

// An interrupted option write recovers only the marked HAL page.
unset( $GLOBALS['b7_options'][ HAL_Frontend_Dashboard_Installer::PAGE_ID_OPTION ] );
$assert( 'marked page recovery', '' === $code() && $owned === get_option( HAL_Frontend_Dashboard_Installer::PAGE_ID_OPTION )
	&& 1 === $GLOBALS['b7_insert_count'] );

// An invalid explicit assignment must fail, not create a second page.
$GLOBALS['b7_options'][ HAL_Frontend_Dashboard_Installer::PAGE_ID_OPTION ] = 88;
$GLOBALS['b7_pages'][88] = new WP_Post( 88, 'post', 'publish', 'dashboard' );
$assert( 'invalid assigned ID fails closed', 'HAL_PAGE_ASSIGNED_ID_INVALID' === $code()
	&& 88 === get_option( HAL_Frontend_Dashboard_Installer::PAGE_ID_OPTION ) && 1 === $GLOBALS['b7_insert_count'] );

// Failures cannot publish an unmarked ID as owned.
unset( $GLOBALS['b7_pages'][ $owned ], $GLOBALS['b7_meta'][ $owned ], $GLOBALS['b7_options'][ HAL_Frontend_Dashboard_Installer::PAGE_ID_OPTION ] );
$GLOBALS['b7_fail'] = 'insert';
$assert( 'insert failure', 'HAL_PAGE_CREATE_FAILED' === $code() );
$GLOBALS['b7_fail'] = 'meta';
$assert( 'marker failure', 'HAL_PAGE_VERIFY_FAILED' === $code()
	&& 0 === (int) get_option( HAL_Frontend_Dashboard_Installer::PAGE_ID_OPTION, 0 ) );
$GLOBALS['b7_fail'] = 'option';
$assert( 'option write failure', 'HAL_PAGE_LINK_FAILED' === $code()
	&& 0 === (int) get_option( HAL_Frontend_Dashboard_Installer::PAGE_ID_OPTION, 0 ) );
$created_after_option_failure = $GLOBALS['b7_insert_count'];
$GLOBALS['b7_fail'] = '';
$assert( 'option write retry recovers marker', '' === $code()
	&& $created_after_option_failure === $GLOBALS['b7_insert_count'] );
echo "RESULT: B7 page ownership assertions held\n";
