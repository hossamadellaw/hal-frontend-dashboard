<?php
/**
 * HAL Frontend Dashboard — B15 (v1.1.1) tagged-addition harness: kses
 * content roundtrip through the REAL articles adapter (B2, coupled with
 * the completed C2).
 *
 * Local, isolated harness: no live WordPress, no network, no database.
 * Loads the REAL runtime/bootstrap.php and drives the REAL
 * hossam_create_article / hossam_update_article of
 * runtime/adapters/wordpress-posts.php (the production content path that
 * applies wp_kses_post at :105 create / :204 update) — only WordPress
 * itself is stubbed. No project logic is reimplemented in the harness.
 *
 * ═══ TAGGED CONTRACT (B2 / v1.1.1) ═══
 * هذا الاختبار المحلي يقيس الجزء المقيس محليًا حصرًا: دورة
 * create→قراءة→update→قراءة على المحوِّل الحقيقي مع wp_kses_post عبر
 * وسيط حدودي موثق (نمط batch14-io-hardening-test.php:191–209). المصفوفة
 * الحية على staging (نفس الكتل عبر محرر HAL الحقيقي على ووردبريس نطاق)
 * ليست من هذا الملف — تُنفَّذ ضمن الجلسة الحية C5 وتُوَسْم
 * [شروط قبول v1.1.2]. لا يُعدل هذا القسم لاحتكار القبول الحي ولا
 * العكس: الملف المحلي = البرهان المقيس هنا حصرًا.
 * ═══════════════════════════════════════
 *
 * Coverage (B2 — the locally measurable half):
 *
 *   ROUNDTRIP — a represented block matrix (paragraph / heading h2-h3 /
 *               list ul+ol / quote / image+figcaption / table / link /
 *               mixed div) through the REAL create→read→update→read
 *               cycle: every clean allowed-tag block survives kses
 *               byte-identically on create, and re-saving the READ-BACK
 *               content via update reproduces the same bytes
 *               (idempotence — the stored representation is a fixed
 *               point of the content path).
 *   DANGEROUS — script/iframe tags are removed with their text kept,
 *               form/input element tags are removed, on* handler
 *               attributes are dropped, and javascript: href/src are
 *               emptied — in BOTH the create and the update cycles, and
 *               the cleaned result is itself a fixed point (no tag
 *               mutates across a second save).
 *   VALVE     — one inherited R14 row (not part of the B2 matrix): with
 *               the h1 policy filtered to 'reject' the REAL valve
 *               hossam_article_h1_should_reject fails the create with
 *               hossam_h1_rejected BEFORE any write (h1 is inside the
 *               core allowlist so the valve sees the post-kses payload).
 *   NO GUESSING — the harness wp_kses_post is a documented boundary
 *               proxy with keep-list semantics (WordPress-only real
 *               function); assertions are designed on the proxy's
 *               deterministic behavior, never on a live environment.
 *
 * Usage:  php tests/php/batch15-kses-roundtrip-test.php            (main)
 *         php tests/php/batch15-kses-roundtrip-test.php <mode>     (subprocess)
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
require_once __DIR__ . '/lib-hal-php-flags.php'; // test-launcher flag filter (no product code, no assertions)

$project = dirname( __DIR__, 2 );

/* ────────────────────────────────────────────────────────────────
 * WordPress boundary stubs (WordPress only — never project logic)
 * ──────────────────────────────────────────────────────────────── */

$GLOBALS['K15_HOOKS']     = array();
$GLOBALS['K15_RESULTS']   = array();
$GLOBALS['K15_POSTS']     = array();
$GLOBALS['K15_CAPS']      = array();
$GLOBALS['K15_USER_ID']   = 3;
$GLOBALS['K15_OPTIONS']   = array();

function k15_check( string $id, bool $condition, string $pass, string $fail = '' ): void {
	$GLOBALS['K15_RESULTS'][] = array( 'id' => $id, 'ok' => $condition, 'pass' => $pass, 'fail' => $fail );
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook_name, $callback = '', int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['K15_HOOKS'][ $hook_name ][] = array( 'callback' => $callback, 'priority' => $priority );
		return true;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook_name, $callback = '', int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['K15_HOOKS'][ $hook_name ][] = array( 'callback' => $callback, 'priority' => $priority );
		return true;
	}
}
if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( string $hook_name, $callback, int $priority = 10 ): bool {
		foreach ( $GLOBALS['K15_HOOKS'][ $hook_name ] ?? array() as $index => $entry ) {
			if ( $entry['callback'] === $callback ) {
				unset( $GLOBALS['K15_HOOKS'][ $hook_name ][ $index ] );
				return true;
			}
		}
		return false;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook_name, $value, ...$args ) {
		foreach ( $GLOBALS['K15_HOOKS'][ $hook_name ] ?? array() as $entry ) {
			$value = call_user_func_array( $entry['callback'], array_merge( array( $value ), $args ) );
		}
		return $value;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook_name, ...$args ): void {
		foreach ( $GLOBALS['K15_HOOKS'][ $hook_name ] ?? array() as $entry ) {
			call_user_func_array( $entry['callback'], $args );
		}
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $s, ?string $domain = null ): string {
		return $s;
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
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0 ) {
		return json_encode( $value, $flags );
	}
}

/**
 * Boundary proxy for wp_kses_post — keep-list semantics for the roundtrip
 * (pattern: batch14-io-hardening-test.php:191–209). Disallowed element
 * TAGS are removed while text content is kept (the real kses behavior),
 * on* handler attributes and javascript: URLs are dropped, and the
 * element-tag vocabulary used by the represented blocks (p/h2/h3/strong/
 * em/u/s/mark/br/hr/a/img/figure/figcaption/ul/ol/li/blockquote/table/
 * thead/tbody/tr/th/td/div/span/code/pre) passes. The REAL allowlist
 * round-trip stays live-acceptance (the tagged C5 contract above).
 */
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $content ): string {
		$c = (string) $content;
		$c = preg_replace( '#</?(script|iframe|style|form|input|object|embed|select|textarea|button|link|meta)\b[^>]*>#si', '', $c );
		$c = preg_replace( '/\son[a-z]+\s*=\s*(["\']).*?\1/si', '', $c );
		$c = preg_replace( '/\son[a-z]+\s*=\s*[^\s>]+/si', '', $c );
		$c = preg_replace( '/(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2/si', '$1=$2$2', $c );
		return $c;
	}
}
if ( ! function_exists( 'safecss_filter_attr' ) ) {
	function safecss_filter_attr( $css ): string {
		return (string) $css; // boundary proxy — style attribute kept for the roundtrip proxy
	}
}

/* ── Users / caps ── */
function get_current_user_id(): int {
	return (int) $GLOBALS['K15_USER_ID'];
}
function current_user_can( string $capability, ...$object_args ): bool {
	$key = $capability . ( isset( $object_args[0] ) ? '_' . (int) $object_args[0] : '' );
	if ( array_key_exists( $key, $GLOBALS['K15_CAPS'] ) ) {
		return (bool) $GLOBALS['K15_CAPS'][ $key ];
	}
	if ( array_key_exists( $capability, $GLOBALS['K15_CAPS'] ) ) {
		return (bool) $GLOBALS['K15_CAPS'][ $capability ];
	}
	return false;
}

/* ── In-memory posts store (the adapter writes through Core) ── */
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
		public string $post_excerpt = '';
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) {
		$id = is_object( $id ) ? ( $id->ID ?? 0 ) : (int) $id;
		return $GLOBALS['K15_POSTS'][ $id ] ?? null;
	}
}
if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( array $postarr, bool $wp_error = false ) {
		static $next = 900;
		$post               = new WP_Post();
		$post->ID           = ++$next;
		$post->post_type    = (string) ( $postarr['post_type'] ?? 'post' );
		$post->post_status  = (string) ( $postarr['post_status'] ?? 'draft' );
		$post->post_title   = (string) ( $postarr['post_title'] ?? '' );
		$post->post_content = (string) ( $postarr['post_content'] ?? '' );
		$post->post_author  = (int) ( $postarr['post_author'] ?? 0 );
		$GLOBALS['K15_POSTS'][ $post->ID ] = $post;
		return $post->ID;
	}
}
if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( array $postarr, bool $wp_error = false ) {
		$id   = (int) ( $postarr['ID'] ?? 0 );
		$post = $GLOBALS['K15_POSTS'][ $id ] ?? null;
		if ( ! $post ) {
			return $wp_error ? new WP_Error( 'k15_update_missing', 'missing' ) : 0;
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
if ( ! function_exists( 'wp_get_post_categories' ) ) {
	function wp_get_post_categories( int $post_id ): array {
		return array();
	}
}
if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
	function get_post_thumbnail_id( int $post_id ): int {
		return 0;
	}
}
if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone(): DateTimeZone {
		return new DateTimeZone( 'UTC' );
	}
}

/* ────────────────────────────────────────────────────────────────
 * Fixture helpers
 * ──────────────────────────────────────────────────────────────── */

function k15_define_release_context(): void {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'k15-' . getmypid() . DIRECTORY_SEPARATOR );
	}
	if ( ! defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT' ) ) {
		define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT', $GLOBALS['K15_PROJECT'] . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR );
		define( 'HAL_FRONTEND_DASHBOARD_RELEASE_ID', '3.0.0+' . str_repeat( 'k5', 20 ) );
		define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION', '3.0.0' );
		define(
			'HAL_FRONTEND_DASHBOARD_RUNTIME_URL',
			'https://example.test/releases/' . HAL_FRONTEND_DASHBOARD_RELEASE_ID . '/'
		);
	}
}

function k15_require_runtime(): void {
	require $GLOBALS['K15_PROJECT'] . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'bootstrap.php';
}

function k15_result_line( string $mode ): void {
	$failed = array();
	foreach ( $GLOBALS['K15_RESULTS'] as $result ) {
		if ( ! $result['ok'] ) {
			$failed[] = array( 'id' => $result['id'], 'fail' => $result['fail'] );
		}
	}
	$payload = array( 'mode' => $mode, 'php' => PHP_VERSION );
	if ( array() !== $failed ) {
		$payload['failed'] = $failed;
	}
	echo 'K15-RESULT ' . json_encode( $payload, JSON_UNESCAPED_SLASHES ), PHP_EOL;
	$all_ok = array() === $failed;
	echo 'K15-VERDICT ' . $mode . ' ' . ( $all_ok ? 'ALL-ASSERTIONS-HELD' : 'ASSERTIONS-FAILED' ), PHP_EOL;
	exit( $all_ok ? 0 : 1 );
}

/**
 * One full create→read→update→read cycle through the REAL adapter.
 * Returns [stored_after_create, stored_after_update, read_after_update].
 */
function k15_cycle( string $content, string $update_content = null ): array {
	$created = hossam_create_article( array( 'title' => 'K15 cycle', 'content' => $content ) );
	if ( is_wp_error( $created ) ) {
		return array( $created, null, null );
	}
	$read_create = (string) get_post( $created )->post_content;
	$updated     = hossam_update_article( (int) $created, array( 'content' => null === $update_content ? $read_create : $update_content ) );
	if ( is_wp_error( $updated ) ) {
		return array( $read_create, $updated, null );
	}
	$read_update = (string) get_post( (int) $created )->post_content;
	return array( $read_create, $updated, $read_update );
}

/* ────────────────────────────────────────────────────────────────
 * Subprocess modes — real bootstrap + real classes
 * ──────────────────────────────────────────────────────────────── */

function k15_mode( string $mode, array $args, string $project ): void {
	$GLOBALS['K15_PROJECT'] = $project;
	k15_define_release_context();
	k15_require_runtime();
	$GLOBALS['K15_CAPS'] = array(
		'edit_posts'      => true,
		'publish_posts'   => true,
		'edit_post'       => true,
	);

	switch ( $mode ) {
		case 'roundtrip':
			// The represented block matrix (B2). Each row: create→read keeps
			// the clean allowed-tag markup byte-identically, then update with
			// the READ-BACK content reproduces the same bytes (fixed point).
			$matrix = array(
				'paragraph'  => '<p>مرحبا بالعالم — نص عربي للتحقق.</p>',
				'heading'    => '<h2>عنوان رئيسي</h2><h3>عنوان فرعي</h3>',
				'list'       => '<ul><li>بند أول</li><li>بند ثانٍ</li></ul><ol><li>مرقّم</li></ol>',
				'quote'      => '<blockquote><p>اقتباس مقتبس.</p></blockquote>',
				'image-caption' => '<figure class="wp-block-image"><img src="https://example.test/img.png" alt="صورة الشرح" width="640" height="480" /><figcaption>تعليق الصورة</figcaption></figure>',
				'table'      => '<table><thead><tr><th>الرأس</th></tr></thead><tbody><tr><td>خلية</td></tr></tbody></table>',
				'link'       => '<p><a href="https://example.test/page/" rel="nofollow">رابط خارجي</a></p>',
				'mixed-div'  => '<div class="wrap"><p>سلام داخل div.</p><pre>code block</pre></div>',
			);
			foreach ( $matrix as $block_id => $block_content ) {
				list( $stored, $updated, $read_update ) = k15_cycle( $block_content );
				k15_check(
					'K15-RT-create-' . $block_id,
					! is_wp_error( $stored ) && $stored === $block_content,
					"{$block_id}: create→read keeps the clean allowed-tag block byte-identical through the REAL adapter's wp_kses_post path",
					"{$block_id} create roundtrip deviated: stored=" . var_export( is_wp_error( $stored ) ? $stored->get_error_code() : $stored, true )
				);
				k15_check(
					'K15-RT-update-fixedpoint-' . $block_id,
					! is_wp_error( $updated ) && $read_update === $stored,
					"{$block_id}: updating with the READ-BACK content reproduces the same bytes (the stored representation is a fixed point of the create→read→update→read cycle)",
					"{$block_id} update fixed-point deviated: updated=" . var_export( is_wp_error( $updated ) ? $updated->get_error_code() : $updated, true ) . ' read=' . var_export( $read_update, true )
				);
			}

			// Dangerous input: stripped in BOTH cycles, then stable.
			$dirty  = '<p onclick="evil()" onmouseover=\'x()\'>نص نظيف</p><script>alert(1)</script>'
				. '<iframe src="https://evil.test/frame"></iframe><a href="javascript:alert(2)">حلقة</a>'
				. '<form action="https://evil.test"><input type="text" /></form>';
			$clean_expected = '<p>نص نظيف</p>alert(1)<a href="">حلقة</a>';
			list( $stored_dirty, $updated_dirty, $read_dirty ) = k15_cycle( $dirty );
			k15_check(
				'K15-RT-dangerous-create',
				! is_wp_error( $stored_dirty ) && $stored_dirty === $clean_expected,
				'the dangerous block is stripped on create: script/iframe tags removed (text kept), form/input elements removed, on* handlers dropped, javascript: href emptied',
				'dangerous create deviated: stored=' . var_export( is_wp_error( $stored_dirty ) ? $stored_dirty->get_error_code() : $stored_dirty, true )
			);
			k15_check(
				'K15-RT-dangerous-update-fixedpoint',
				! is_wp_error( $updated_dirty ) && $read_dirty === $clean_expected,
				'updating with the read-back cleaned content is idempotent — no tag mutates across a second save (update→read)',
				'dangerous update deviated: read=' . var_export( is_wp_error( $updated_dirty ) ? $updated_dirty->get_error_code() : $read_dirty, true )
			);

			// Update-overwrite with dirty content over a clean stored row.
			$created  = hossam_create_article( array( 'title' => 'K15 overwrite', 'content' => '<p>نظيف قبل التحديث</p>' ) );
			$overwritten = hossam_update_article( (int) $created, array( 'content' => '<p>بعد التحديث</p><script>bad()</script>' ) );
			$read_over = (string) get_post( (int) $created )->post_content;
			k15_check(
				'K15-RT-overwrite-clean',
				! is_wp_error( $overwritten ) && '<p>بعد التحديث</p>bad()' === $read_over,
				'a dirty update payload over a stored clean row stores only the kses-cleaned content — the script tag is removed while its inner text stays as plain text (the proxy keep-list semantics; the update path applies wp_kses_post at :204 the same way create does at :105)',
				'overwrite deviated: ' . var_export( is_wp_error( $overwritten ) ? $overwritten->get_error_code() : $read_over, true )
			);

			// Inherited R14 valve row (outside the B2 matrix): policy 'reject'
			// + h1 content → clean 400-code rejection BEFORE any write.
			add_filter( 'hossam_article_h1_policy', static function ( $policy ) {
				return 'reject';
			} );
			$rows_before = count( $GLOBALS['K15_POSTS'] );
			$h1_result   = hossam_create_article( array( 'title' => 'K15 valve', 'content' => '<h1>عنوان مرفوض</h1>' ) );
			k15_check(
				'K15-VALVE-h1-reject-before-write',
				is_wp_error( $h1_result ) && 'hossam_h1_rejected' === $h1_result->get_error_code() && count( $GLOBALS['K15_POSTS'] ) === $rows_before,
				'inherited R14 interplay: with the h1 policy at reject the valve fails the create with hossam_h1_rejected before any write (h1 is in the core allowlist, so the valve reads the post-kses payload)',
				'valve mismatch: ' . ( is_wp_error( $h1_result ) ? $h1_result->get_error_code() : 'created id ' . var_export( $h1_result, true ) )
			);
			k15_result_line( $mode );
			// no fallthrough
	}

	fwrite( STDERR, "Unknown K15 mode: {$mode}\n" );
	exit( 1 );
}

/* ────────────────────────────────────────────────────────────────
 * MAIN — run every subprocess mode, then report
 * ──────────────────────────────────────────────────────────────── */

function k15_run_mode( string $mode, array $args = array() ): void {
	$php     = PHP_BINARY;
	$script  = __FILE__;
	$command = hal_php_argv(
		$php,
		array( '-d', 'extension_dir=' . HAL_TEST_EXT_DIR ),
		array( 'sodium' )
	);
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
			k15_check( 'MODE-' . strtoupper( str_replace( '-', '_', $mode ) ), false, 'subprocess could not start', 'proc_open failed' );
			return;
		}
	}
	fclose( $pipes[0] );
	$stdout = stream_get_contents( $pipes[1] );
	fclose( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	fclose( $pipes[2] );
	$code = proc_close( $proc );
	$stdout = (string) $stdout;
	$ok = 0 === $code && false !== strpos( $stdout, 'K15-VERDICT ' . $mode . ' ALL-ASSERTIONS-HELD' );
	$detail = '';
	if ( ! $ok ) {
		$detail = 'exit ' . $code . '; stdout: ' . trim( $stdout ) . '; stderr: ' . trim( (string) $stderr );
	}
	k15_check(
		'MODE-' . strtoupper( str_replace( '-', '_', $mode ) ),
		$ok,
		'subprocess mode ' . $mode . ' held all its assertions (exit 0)',
		'mode ' . $mode . ' failed — ' . $detail
	);
}

/* ── Dispatch ── */
$k15_mode_arg = isset( $argv[1] ) ? (string) $argv[1] : 'main';
if ( 'main' !== $k15_mode_arg ) {
	k15_mode( $k15_mode_arg, array_slice( $argv, 2 ), $project );
	exit( 1 );
}

k15_run_mode( 'roundtrip' );

echo "HAL Frontend Dashboard — B15 kses roundtrip test (v1.1.1 B2, local half)\n";
echo str_repeat( '─', 72 ) . "\n";
$pass_count = 0;
foreach ( $GLOBALS['K15_RESULTS'] as $result ) {
	if ( $result['ok'] ) {
		$pass_count++;
		echo "PASS [{$result['id']}] {$result['pass']}\n";
	} else {
		echo "FAIL [{$result['id']}] {$result['fail']}\n";
	}
}
echo str_repeat( '─', 72 ) . "\n";
$total = count( $GLOBALS['K15_RESULTS'] );
echo "RESULT: {$pass_count}/{$total} checks passed\n";
exit( $pass_count === $total ? 0 : 1 );
