<?php
/**
 * First-install activation path for the signed embedded Runtime.
 *
 * @package HAL_Frontend_Dashboard
 */

defined( 'ABSPATH' ) || exit;

	final class HAL_Frontend_Dashboard_Installer {
	/**
	 * اسم خيار الصفحة المملوكة (العقد §7.5) — القارئ الوحيد
	 * Template Controller (get_owned_page_id)؛ الكاتب الوحيد هنا.
	 */
	const PAGE_ID_OPTION = 'hal_frontend_dashboard_page_id';

	/** علامة meta داخلية تميّز الصفحة المنشأة للمشروع (لا slug). */
	const PAGE_META_KEY = '_hal_frontend_dashboard_page';
	public static function activate(): void {
		self::preflight();

		require_once __DIR__ . '/class-package-verifier.php';
		require_once __DIR__ . '/class-release-manager.php';
		require_once __DIR__ . '/class-health-check.php';

		$plugin_root = defined( 'HAL_FRONTEND_DASHBOARD_PLUGIN_DIR' ) ? HAL_FRONTEND_DASHBOARD_PLUGIN_DIR : dirname( __DIR__ ) . DIRECTORY_SEPARATOR;
		$payload = $plugin_root . 'payload' . DIRECTORY_SEPARATOR;
		$runtime_manifest = $payload . 'runtime-manifest.json';
		$runtime_signature = $payload . 'runtime-manifest.sig';
		$carrier_manifest = $plugin_root . 'carrier-manifest.json';
		$carrier_signature = $plugin_root . 'carrier-manifest.sig';

		// Test-key gate: isolated harnesses define this constant to verify a
		// throwaway signing key; undefined in production (the verifier then
		// uses the pinned production public key). Never a secret: it names a
		// PUBLIC test key.
		$verifier = new HAL_Frontend_Dashboard_Package_Verifier(
			defined( 'HAL_FRONTEND_DASHBOARD_TEST_PUBLIC_KEY' ) ? HAL_FRONTEND_DASHBOARD_TEST_PUBLIC_KEY : null,
			defined( 'HAL_FRONTEND_DASHBOARD_TEST_KEY_FINGERPRINT' ) ? HAL_FRONTEND_DASHBOARD_TEST_KEY_FINGERPRINT : null
		);
		$verifier->verify_carrier_tree( rtrim( $plugin_root, '/\\' ), $carrier_manifest, $carrier_signature );
		$manifest = self::read_signed_runtime_manifest( $verifier, $payload, $runtime_manifest, $runtime_signature );
		$archive = $payload . 'runtime-' . $manifest['version'] . '.zip';
		$manifest = $verifier->verify_runtime_archive( $archive, $runtime_manifest, $runtime_signature );
		self::assert_manifest_compatibility( $manifest );

		$manager = new HAL_Frontend_Dashboard_Release_Manager();
		$manager->ensure_layout();
		self::assert_atomic_replace( $manager->state_path( 'atomic-probe.json' ) );

		$release_id = $manifest['release_id'];
		$release_path = $manager->release_path( $release_id );
		if ( is_link( $release_path ) ) {
			throw new RuntimeException( 'HAL_EXISTING_RELEASE_LINK_REJECTED' );
		}
		if ( is_dir( $release_path ) ) {
			$verifier->verify_runtime_tree( $release_path, $manifest );
		} else {
			$staging = dirname( $release_path ) . DIRECTORY_SEPARATOR . '.hal-stage-' . bin2hex( random_bytes( 16 ) );
			$verifier->extract_runtime_archive( $archive, $staging, $manifest );
			$manager->install_release( $release_id, $staging );
			$manager->record_status( 'staged', array( 'release_id' => $release_id, 'time' => time() ) );
		}

		$loader_id = 'loader-' . $manifest['loader_core_version'];
		$loader_source = $plugin_root . 'mu-loader' . DIRECTORY_SEPARATOR . 'loader-core.php';
		$loader_target = $manager->loader_path( $loader_id ) . DIRECTORY_SEPARATOR . 'loader-core.php';
		if ( is_link( $loader_source ) || is_link( $loader_target ) ) {
			throw new RuntimeException( 'HAL_LOADER_LINK_REJECTED' );
		}
		if ( is_file( $loader_target ) ) {
			if ( ! hash_equals( hash_file( 'sha256', $loader_source ) ?: '', hash_file( 'sha256', $loader_target ) ?: '' ) ) {
				throw new RuntimeException( 'HAL_EXISTING_LOADER_MISMATCH' );
			}
		} else {
			$manager->install_loader( $loader_id, $loader_source );
		}

		self::install_root_anchor_last( $plugin_root . 'mu-loader' . DIRECTORY_SEPARATOR . 'hal-frontend-dashboard.php' );
		self::grant_default_capability();
		self::ensure_dashboard_page();

		$current = $manager->read_pointer( 'active.json' );
		$current_loader = $manager->read_pointer( 'loader-active.json' );
		$current_committed = $manager->read_state_file( 'committed.json' );
		// Early return ONLY when the promotion completed fully: active
		// pointer, loader pointer, AND committed record all name this
		// release. An interrupted promotion (record deleted before the
		// committed write — see the W1 window) leaves active pointing at
		// the candidate without committed; the real promote path must then
		// run again to close the commit, not be skipped.
		if (
			null !== $current
			&& $release_id === ( $current['release_id'] ?? null )
			&& $manifest['version'] === ( $current['version'] ?? null )
			&& $loader_id === ( $current_loader['loader_id'] ?? null )
			&& is_array( $current_committed )
			&& $release_id === ( $current_committed['release_id'] ?? null )
		) {
			return;
		}
		$health = new HAL_Frontend_Dashboard_Health_Check();
		$manager->promote_runtime(
			$manifest,
			static fn ( string $operation, string $candidate, string $version, int $deadline, string $candidate_loader ): bool => $health->check( $operation, $candidate, $version, $deadline, $candidate_loader ),
			$loader_id
		);
	}

	/**
	 * §7.2.9 / قاعدة تسليم الدفعة 3: منح capability إدارة HAL الافتراضي
	 * لدور administrator بشكل idempotent بعد اكتمال التثبيت البنيوي
	 * (root anchor). فحوص الـruntime تستخدم الـcapability فقط ولا تفحص
	 * أسماء الأدوار (قرار المالك §2.3). البيئات المعزولة بلا roles API
	 * (harnesses) تُتخطى بأمان — WordPress الحقيقي يملك get_role دائمًا.
	 */
	private static function grant_default_capability(): void {
		if ( ! function_exists( 'get_role' ) ) {
			return;
		}
		$role = get_role( 'administrator' );
		if ( $role instanceof WP_Role && ! $role->has_cap( 'manage_hal_frontend_dashboard' ) ) {
			$role->add_cap( 'manage_hal_frontend_dashboard' );
		}
	}

	/**
	 * §7.2 بند 9 و§7.5: تسجيل/ربط صفحة Dashboard idempotently.
	 * - مسؤول الموقع يثبت Page ID الـlegacy صراحةً في خيار
	 *   hal_frontend_dashboard_page_id عبر WordPress Options API قبل activation.
	 *   الخيار غير الصفري يُقبل فقط إذا أشار إلى WP_Post/page/publish|private؛
	 *   القيمة غير الصالحة تفشل دون إنشاء صفحة ثانية.
	 * - الخيار الصالح يُعاد (مسار ترقية legacy: لا صفحة ثانية أبدًا).
	 * - وإلا: إنشاء صفحة مملوكة جديدة بعلامة meta داخلية، مع التحقق من
	 *   نتيجة wp_insert_post() ثم إعادة قراءتها ثم تخزين الخيار.
	 * - لا اختطاف slug: لا بحث عن أي صفحة موجودة — التكرار في slug
	 *   يحسمه WordPress نفسه عند الإنشاء.
	 * - أي تعذر → RuntimeException قبل إعلان الجاهزية (لا redirect عام).
	 */
	private static function ensure_dashboard_page(): void {
		$stored = function_exists( 'get_option' ) ? (int) get_option( self::PAGE_ID_OPTION, 0 ) : 0;
		if ( $stored > 0 ) {
			if ( ! self::is_usable_dashboard_page( $stored ) ) {
				throw new RuntimeException( 'HAL_PAGE_ASSIGNED_ID_INVALID' );
			}
			return;
		}

		if ( ! function_exists( 'wp_insert_post' ) || ! function_exists( 'update_option' ) || ! function_exists( 'get_post_meta' ) || ! function_exists( 'get_posts' ) ) {
			throw new RuntimeException( 'HAL_PAGE_API_UNAVAILABLE' );
		}
		// Recover an owned page after an interrupted option write. A slug match
		// cannot establish ownership, and multiple marked pages are ambiguous.
		$marked = get_posts( array(
			'post_type' => 'page', 'post_status' => array( 'publish', 'private' ),
			'meta_key' => self::PAGE_META_KEY, 'meta_value' => '1',
			'fields' => 'ids', 'posts_per_page' => 2,
		) );
		if ( ! is_array( $marked ) || count( $marked ) > 1 ) {
			throw new RuntimeException( 'HAL_PAGE_OWNERSHIP_AMBIGUOUS' );
		}
		if ( 1 === count( $marked ) ) {
			$owned_id = (int) reset( $marked );
			if ( ! self::is_usable_dashboard_page( $owned_id ) ) {
				throw new RuntimeException( 'HAL_PAGE_VERIFY_FAILED' );
			}
			self::link_dashboard_page( $owned_id );
			return;
		}

		$new_id = wp_insert_post(
			array(
				'post_title'   => 'Dashboard',
				'post_name'    => 'dashboard',
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '',
				'meta_input'   => array( self::PAGE_META_KEY => '1' ),
			),
			true
		);
		if ( ( function_exists( 'is_wp_error' ) && is_wp_error( $new_id ) ) || (int) $new_id < 1 ) {
			throw new RuntimeException( 'HAL_PAGE_CREATE_FAILED' );
		}
		$new_id = (int) $new_id;

		if ( ! self::is_usable_dashboard_page( $new_id ) || '1' !== (string) get_post_meta( $new_id, self::PAGE_META_KEY, true ) ) {
			throw new RuntimeException( 'HAL_PAGE_VERIFY_FAILED' );
		}
		self::link_dashboard_page( $new_id );
	}

	private static function link_dashboard_page( int $page_id ): void {
		update_option( self::PAGE_ID_OPTION, $page_id, false );
		if ( ! function_exists( 'get_option' ) || (int) get_option( self::PAGE_ID_OPTION, 0 ) !== $page_id ) {
			throw new RuntimeException( 'HAL_PAGE_LINK_FAILED' );
		}
	}

	/**
	 * صلاحية صفحة الهدف: WP_Post من نوع page بحالة قابلة للعرض
	 * (publish/private) — أي شيء آخر يعيد التهيئة لا الاختطاف.
	 */
	private static function is_usable_dashboard_page( int $page_id ): bool {
		if ( $page_id < 1 || ! function_exists( 'get_post' ) ) {
			return false;
		}
		$post = get_post( $page_id );
		if ( ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
			return false;
		}

		return in_array( $post->post_status, array( 'publish', 'private' ), true );
	}

	private static function preflight(): void {
		global $wp_version;
		if ( version_compare( PHP_VERSION, '8.3.0', '<' ) ) {
			throw new RuntimeException( 'HAL_PREFLIGHT_PHP_UNSUPPORTED' );
		}
		if ( ! is_string( $wp_version ) || version_compare( $wp_version, '7.0', '<' ) ) {
			throw new RuntimeException( 'HAL_PREFLIGHT_WORDPRESS_UNSUPPORTED' );
		}
		if ( ! extension_loaded( 'sodium' ) || ! class_exists( 'ZipArchive' ) ) {
			throw new RuntimeException( 'HAL_PREFLIGHT_EXTENSION_MISSING' );
		}
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			throw new RuntimeException( 'HAL_PREFLIGHT_FILE_MODS_DISABLED' );
		}
		if ( function_exists( 'get_filesystem_method' ) && 'direct' !== get_filesystem_method() ) {
			throw new RuntimeException( 'HAL_PREFLIGHT_DIRECT_FILESYSTEM_REQUIRED' );
		}
		if ( is_multisite() ) {
			throw new RuntimeException( 'HAL_PREFLIGHT_MULTISITE_UNSUPPORTED' );
		}
		if ( 'https' !== strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) ) ) {
			throw new RuntimeException( 'HAL_PREFLIGHT_HTTPS_REQUIRED' );
		}
		if ( ! defined( 'WPMU_PLUGIN_DIR' ) || ( is_dir( WPMU_PLUGIN_DIR ) && ! is_writable( WPMU_PLUGIN_DIR ) ) || ( ! is_dir( WPMU_PLUGIN_DIR ) && ! is_writable( dirname( WPMU_PLUGIN_DIR ) ) ) ) {
			throw new RuntimeException( 'HAL_PREFLIGHT_MU_DIRECTORY_UNWRITABLE' );
		}
		self::assert_no_link_segments( WPMU_PLUGIN_DIR );
		self::assert_no_legacy_loader_active();
	}

	/**
	 * B1-07 / U §10: رفض التثبيت بجوار legacy loader نشط قبل أي أثر
	 * دائم، منعًا للتحميل المزدوج. Legacy المعروف: ملف hossam-dashboard.php
	 * أو مجلد hossam-dashboard داخل mu-plugins.
	 */
	private static function assert_no_legacy_loader_active(): void {
		$legacy_file = rtrim( WPMU_PLUGIN_DIR, '/\\' ) . DIRECTORY_SEPARATOR . 'hossam-dashboard.php';
		$legacy_dir = rtrim( WPMU_PLUGIN_DIR, '/\\' ) . DIRECTORY_SEPARATOR . 'hossam-dashboard';
		if ( file_exists( $legacy_file ) || is_link( $legacy_file ) || file_exists( $legacy_dir ) || is_link( $legacy_dir ) ) {
			throw new RuntimeException( 'HAL_PREFLIGHT_LEGACY_LOADER_ACTIVE' );
		}
	}

	private static function read_signed_runtime_manifest( HAL_Frontend_Dashboard_Package_Verifier $verifier, string $payload, string $manifest_file, string $signature_file ): array {
		$candidates = glob( $payload . 'runtime-*.zip' );
		if ( false === $candidates || 1 !== count( $candidates ) ) {
			throw new RuntimeException( 'HAL_RUNTIME_ARCHIVE_CARDINALITY_INVALID' );
		}
		return $verifier->verify_runtime_archive( $candidates[0], $manifest_file, $signature_file );
	}

	private static function assert_manifest_compatibility( array $manifest ): void {
		global $wp_version;
		if (
			version_compare( PHP_VERSION, $manifest['requires_php'], '<' )
			|| version_compare( (string) $wp_version, $manifest['requires_wp'], '<' )
			|| $manifest['loader_api_min'] > 1
			|| $manifest['loader_api_max'] < 1
			|| ( defined( 'HAL_FRONTEND_DASHBOARD_VERSION' ) && HAL_FRONTEND_DASHBOARD_VERSION !== $manifest['version'] )
		) {
			throw new RuntimeException( 'HAL_RUNTIME_MANIFEST_INCOMPATIBLE' );
		}
	}

	private static function assert_atomic_replace( string $probe ): void {
		$directory = dirname( $probe );
		$nonce = bin2hex( random_bytes( 16 ) );
		$first = $directory . DIRECTORY_SEPARATOR . '.hal-atomic-' . $nonce . '-active.tmp';
		$second = $directory . DIRECTORY_SEPARATOR . '.hal-atomic-' . $nonce . '-candidate.tmp';
		try {
			$first_handle = fopen( $first, 'xb' );
			$second_handle = fopen( $second, 'xb' );
			if ( false === $first_handle || false === $second_handle ) {
				is_resource( $first_handle ) && fclose( $first_handle );
				is_resource( $second_handle ) && fclose( $second_handle );
				throw new RuntimeException( 'HAL_PREFLIGHT_ATOMIC_PROBE_CREATE_FAILED' );
			}
			fwrite( $first_handle, "first\n" );
			fwrite( $second_handle, "second\n" );
			fflush( $first_handle );
			fflush( $second_handle );
			function_exists( 'fsync' ) && fsync( $first_handle );
			function_exists( 'fsync' ) && fsync( $second_handle );
			fclose( $first_handle );
			fclose( $second_handle );
			if ( ! @rename( $second, $first ) || "second\n" !== file_get_contents( $first ) ) {
				throw new RuntimeException( 'HAL_PREFLIGHT_ATOMIC_RENAME_UNSUPPORTED' );
			}
		} finally {
			@unlink( $first );
			@unlink( $second );
		}
	}

	private static function install_root_anchor_last( string $source ): void {
		$target = rtrim( WPMU_PLUGIN_DIR, '/\\' ) . DIRECTORY_SEPARATOR . 'hal-frontend-dashboard.php';
		if ( is_link( $source ) || is_link( $target ) ) {
			throw new RuntimeException( 'HAL_ROOT_ANCHOR_PATH_INVALID' );
		}
		if ( is_file( $target ) ) {
			if ( ! hash_equals( hash_file( 'sha256', $source ) ?: '', hash_file( 'sha256', $target ) ?: '' ) ) {
				throw new RuntimeException( 'HAL_ROOT_ANCHOR_MISMATCH' );
			}
			return;
		}
		if ( ! is_file( $source ) ) {
			throw new RuntimeException( 'HAL_ROOT_ANCHOR_PATH_INVALID' );
		}
		$temp = WPMU_PLUGIN_DIR . DIRECTORY_SEPARATOR . '.hal-anchor-' . bin2hex( random_bytes( 12 ) ) . '.tmp';
		if ( ! copy( $source, $temp ) || ! hash_equals( hash_file( 'sha256', $source ) ?: '', hash_file( 'sha256', $temp ) ?: '' ) || ! @rename( $temp, $target ) ) {
			@unlink( $temp );
			throw new RuntimeException( 'HAL_ROOT_ANCHOR_INSTALL_FAILED' );
		}
		@chmod( $target, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 );
	}

	private static function assert_no_link_segments( string $path ): void {
		if ( ! function_exists( 'lstat' ) || ! function_exists( 'realpath' ) ) {
			throw new RuntimeException( 'HAL_PREFLIGHT_REPARSE_DETECTION_UNAVAILABLE' );
		}
		$cursor = $path;
		while ( ! file_exists( $cursor ) && dirname( $cursor ) !== $cursor ) {
			$cursor = dirname( $cursor );
		}
		while ( dirname( $cursor ) !== $cursor ) {
			$stat = lstat( $cursor );
			$resolved = realpath( $cursor );
			if ( false === $stat || false === $resolved || is_link( $cursor ) || 0120000 === ( $stat['mode'] & 0170000 ) ) {
				throw new RuntimeException( 'HAL_PREFLIGHT_FILESYSTEM_LINK_REJECTED' );
			}
			if ( 'Windows' === PHP_OS_FAMILY ) {
				$lexical = strtolower( str_replace( '/', '\\', rtrim( $cursor, '/\\' ) ) );
				$canonical = strtolower( str_replace( '/', '\\', rtrim( $resolved, '/\\' ) ) );
				if ( $lexical !== $canonical ) {
					throw new RuntimeException( 'HAL_PREFLIGHT_REPARSE_POINT_REJECTED' );
				}
			}
			$cursor = dirname( $cursor );
		}
	}
}
