<?php
/**
 * Verifies signed HAL release manifests and package contents.
 *
 * @package HAL_Frontend_Dashboard
 */

defined( 'ABSPATH' ) || exit;

final class HAL_Frontend_Dashboard_Package_Verifier {
	public const PUBLIC_KEY_BASE64 = 'PMUvQarh5F86Ts0I+wRMK5B5tGTn3hCDKvLtNYdt/6A=';
	public const PUBLIC_KEY_FINGERPRINT = 'b8151f3feb710b37735655e659f49b0dd2eae8dbcd2e2eaafca114827477dffd';

	private const PRODUCT = 'hal-frontend-dashboard';
	private const PLUGIN_BASENAME = 'hal-frontend-dashboard/hal-frontend-dashboard.php';
	private const MAX_ENTRIES = 4096;
	private const MAX_COMPRESSED_BYTES = 134217728;
	private const MAX_UNCOMPRESSED_BYTES = 268435456;
	private const MAX_COMPRESSION_RATIO = 100;
	private const MAX_MANIFEST_BYTES = 2097152;

	private string $public_key;

	public function __construct( ?string $public_key_base64 = null, ?string $fingerprint = null ) {
		if ( ! extension_loaded( 'sodium' ) || ! defined( 'SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES' ) ) {
			self::fail( 'HAL_SIGNATURE_EXTENSION_MISSING' );
		}

		$encoded = $public_key_base64 ?? self::PUBLIC_KEY_BASE64;
		$expected_fingerprint = $fingerprint ?? self::PUBLIC_KEY_FINGERPRINT;
		$public_key = base64_decode( $encoded, true );

		if ( false === $public_key || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $public_key ) ) {
			self::fail( 'HAL_SIGNATURE_PUBLIC_KEY_INVALID' );
		}

		if ( ! hash_equals( strtolower( $expected_fingerprint ), hash( 'sha256', $public_key ) ) ) {
			self::fail( 'HAL_SIGNATURE_PUBLIC_KEY_FINGERPRINT_MISMATCH' );
		}

		$this->public_key = $public_key;
	}

	public function verify_runtime_archive( string $archive, string $manifest_file, string $signature_file ): array {
		$manifest = $this->verify_manifest_file( $manifest_file, $signature_file, 'runtime' );

		if ( ! is_file( $archive ) || ! hash_equals( $manifest['archive_sha256'], hash_file( 'sha256', $archive ) ?: '' ) ) {
			self::fail( 'HAL_RUNTIME_ARCHIVE_HASH_MISMATCH' );
		}

		$this->verify_zip_inventory( $archive, $manifest['files'] );

		return $manifest;
	}

	public function verify_runtime_tree( string $root, array $manifest ): void {
		$this->verify_tree_inventory( $root, $manifest['files'] ?? array(), array(), 'runtime' );
	}

	public function verify_carrier_archive( string $archive ): array {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $archive, ZipArchive::CHECKCONS | ZipArchive::RDONLY ) ) {
			self::fail( 'HAL_CARRIER_ARCHIVE_OPEN_FAILED' );
		}

		$root = self::PRODUCT . '/';
		$actual = array();
		$manifest_raw = null;
		$signature_raw = null;
		try {
			$this->assert_zip_safety( $zip );
			for ( $index = 0; $index < $zip->numFiles; $index++ ) {
				$stat = $zip->statIndex( $index, ZipArchive::FL_UNCHANGED );
				if ( false === $stat ) {
					self::fail( 'HAL_CARRIER_ARCHIVE_ENTRY_INVALID' );
				}
				$name = $this->normalize_relative_path( $stat['name'] );
				if ( $root === $name && str_ends_with( $name, '/' ) ) {
					continue;
				}
				if ( ! str_starts_with( $name, $root ) || $root === $name ) {
					self::fail( 'HAL_CARRIER_ARCHIVE_ROOT_INVALID' );
				}
				$relative = substr( $name, strlen( $root ) );
				if ( str_ends_with( $name, '/' ) ) {
					$this->assert_distributable_path( rtrim( $relative, '/' ), 'carrier' );
					continue;
				}
				$stream = $zip->getStream( $stat['name'] );
				if ( false === $stream ) {
					self::fail( 'HAL_CARRIER_ARCHIVE_ENTRY_UNREADABLE' );
				}
				if ( 'carrier-manifest.json' === $relative ) {
					$manifest_raw = (int) $stat['size'] <= self::MAX_MANIFEST_BYTES ? stream_get_contents( $stream, self::MAX_MANIFEST_BYTES + 1 ) : false;
					fclose( $stream );
					if ( false === $manifest_raw || strlen( $manifest_raw ) > self::MAX_MANIFEST_BYTES ) {
						self::fail( 'HAL_CARRIER_ARCHIVE_MANIFEST_SIZE_INVALID' );
					}
					continue;
				}
				if ( 'carrier-manifest.sig' === $relative ) {
					$signature_content = (int) $stat['size'] <= 256 ? stream_get_contents( $stream, 257 ) : false;
					fclose( $stream );
					if ( false === $signature_content || strlen( $signature_content ) > 256 ) {
						self::fail( 'HAL_CARRIER_ARCHIVE_SIGNATURE_SIZE_INVALID' );
					}
					$signature_raw = trim( $signature_content );
					continue;
				}
				$this->assert_distributable_path( $relative, 'carrier' );
				if ( isset( $actual[ $relative ] ) ) {
					self::fail( 'HAL_CARRIER_ARCHIVE_INVENTORY_INVALID' );
				}
				$hash = hash_init( 'sha256' );
				hash_update_stream( $hash, $stream );
				fclose( $stream );
				$actual[ $relative ] = hash_final( $hash );
			}
		} finally {
			$zip->close();
		}

		if ( ! is_string( $manifest_raw ) || ! is_string( $signature_raw ) ) {
			self::fail( 'HAL_CARRIER_ARCHIVE_MANIFEST_MISSING' );
		}
		$manifest = $this->verify_manifest_contents( $manifest_raw, $signature_raw, 'carrier' );
		$expected = $manifest['files'];
		ksort( $actual, SORT_STRING );
		ksort( $expected, SORT_STRING );
		if ( array_keys( $actual ) !== array_keys( $expected ) ) {
			self::fail( 'HAL_CARRIER_ARCHIVE_INVENTORY_INVALID' );
		}
		foreach ( $expected as $path => $hash ) {
			if ( ! hash_equals( $hash, $actual[ $path ] ) ) {
				self::fail( 'HAL_CARRIER_ARCHIVE_HASH_MISMATCH' );
			}
		}
		return $manifest;
	}

	public function verify_carrier_tree( string $root, string $manifest_file, string $signature_file ): array {
		$manifest = $this->verify_manifest_file( $manifest_file, $signature_file, 'carrier' );
		$this->verify_tree_inventory(
			$root,
			$manifest['files'],
			array( 'carrier-manifest.json', 'carrier-manifest.sig' ),
			'carrier'
		);

		return $manifest;
	}

	public function extract_runtime_archive( string $archive, string $destination, array $manifest ): void {
		if ( file_exists( $destination ) || is_link( $destination ) ) {
			self::fail( 'HAL_STAGING_DESTINATION_EXISTS' );
		}

		if ( ! mkdir( $destination, $this->directory_mode(), true ) && ! is_dir( $destination ) ) {
			self::fail( 'HAL_STAGING_CREATE_FAILED' );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $archive, ZipArchive::RDONLY ) ) {
			self::fail( 'HAL_RUNTIME_ARCHIVE_OPEN_FAILED' );
		}

		try {
			$this->assert_zip_safety( $zip );

			for ( $index = 0; $index < $zip->numFiles; $index++ ) {
				$stat = $zip->statIndex( $index, ZipArchive::FL_UNCHANGED );
				if ( false === $stat ) {
					self::fail( 'HAL_RUNTIME_ARCHIVE_ENTRY_INVALID' );
				}

				$name = $this->normalize_relative_path( $stat['name'] );
				if ( str_ends_with( $stat['name'], '/' ) ) {
					$this->assert_distributable_path( rtrim( $name, '/' ), 'runtime' );
					$this->create_contained_directory( $destination, rtrim( $name, '/' ) );
					continue;
				}

				if ( ! array_key_exists( $name, $manifest['files'] ) ) {
					self::fail( 'HAL_RUNTIME_ARCHIVE_UNEXPECTED_FILE' );
				}

				$target = $this->contained_target( $destination, $name );
				$this->create_contained_directory( $destination, dirname( str_replace( '\\', '/', $name ) ) );
				$input = $zip->getStream( $stat['name'] );
				$output = @fopen( $target, 'xb' );
				if ( false === $input || false === $output ) {
					is_resource( $input ) && fclose( $input );
					is_resource( $output ) && fclose( $output );
					self::fail( 'HAL_RUNTIME_ARCHIVE_EXTRACT_FAILED' );
				}

				$hash = hash_init( 'sha256' );
				while ( ! feof( $input ) ) {
					$chunk = fread( $input, 1048576 );
					if ( false === $chunk || false === fwrite( $output, $chunk ) ) {
						fclose( $input );
						fclose( $output );
						self::fail( 'HAL_RUNTIME_ARCHIVE_EXTRACT_FAILED' );
					}
					hash_update( $hash, $chunk );
				}
				fclose( $input );
				fflush( $output );
				function_exists( 'fsync' ) && fsync( $output );
				fclose( $output );
				@chmod( $target, $this->file_mode() );

				if ( ! hash_equals( $manifest['files'][ $name ], hash_final( $hash ) ) ) {
					self::fail( 'HAL_RUNTIME_ARCHIVE_FILE_HASH_MISMATCH' );
				}
			}
		} finally {
			$zip->close();
		}

		$this->verify_runtime_tree( $destination, $manifest );
	}

	private function verify_manifest_file( string $manifest_file, string $signature_file, string $kind ): array {
		$raw = ! is_link( $manifest_file ) && is_file( $manifest_file ) ? file_get_contents( $manifest_file ) : false;
		$signature_encoded = ! is_link( $signature_file ) && is_file( $signature_file ) ? trim( (string) file_get_contents( $signature_file ) ) : '';
		if ( false === $raw ) {
			self::fail( 'HAL_SIGNATURE_INPUT_INVALID' );
		}
		return $this->verify_manifest_contents( $raw, $signature_encoded, $kind );
	}

	private function verify_manifest_contents( string $raw, string $signature_encoded, string $kind ): array {
		$signature = base64_decode( $signature_encoded, true );

		if ( false === $signature || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $signature ) ) {
			self::fail( 'HAL_SIGNATURE_INPUT_INVALID' );
		}

		if ( ! sodium_crypto_sign_verify_detached( $signature, $raw, $this->public_key ) ) {
			self::fail( 'HAL_SIGNATURE_VERIFICATION_FAILED' );
		}

		try {
			$manifest = json_decode( $raw, true, 64, JSON_THROW_ON_ERROR );
		} catch ( JsonException $exception ) {
			self::fail( 'HAL_MANIFEST_JSON_INVALID' );
		}

		if ( ! is_array( $manifest ) ) {
			self::fail( 'HAL_MANIFEST_SHAPE_INVALID' );
		}

		if ( 'runtime' === $kind ) {
			$this->validate_runtime_manifest( $manifest );
		} else {
			$this->validate_carrier_manifest( $manifest );
		}

		return $manifest;
	}

	private function validate_runtime_manifest( array $manifest ): void {
		$required = array( 'schema', 'product', 'version', 'release_sequence', 'release_id', 'tag', 'commit_sha', 'requires_wp', 'requires_php', 'loader_api_min', 'loader_api_max', 'loader_core_version', 'schema_version', 'archive_sha256', 'files' );
		$allowed = array_merge( $required, array( 'next_key' ) );
		$this->assert_keys( $manifest, $required, $allowed );

		if ( 1 !== $manifest['schema'] || self::PRODUCT !== $manifest['product'] || ! $this->valid_version( $manifest['version'] ) ) {
			self::fail( 'HAL_RUNTIME_MANIFEST_IDENTITY_INVALID' );
		}
		if ( ! is_int( $manifest['release_sequence'] ) || $manifest['release_sequence'] < 1 ) {
			self::fail( 'HAL_RUNTIME_MANIFEST_SEQUENCE_INVALID' );
		}
		if ( ! is_string( $manifest['release_id'] ) || ! preg_match( '/\\A[0-9]+\\.[0-9]+\\.[0-9]+\\+[a-f0-9]{40}\\z/D', $manifest['release_id'] ) ) {
			self::fail( 'HAL_RUNTIME_MANIFEST_RELEASE_ID_INVALID' );
		}
		if ( 'v' . $manifest['version'] !== $manifest['tag'] || ! is_string( $manifest['commit_sha'] ) || ! preg_match( '/\\A[a-f0-9]{40}\\z/D', $manifest['commit_sha'] ) ) {
			self::fail( 'HAL_RUNTIME_MANIFEST_RELEASE_IDENTITY_MISMATCH' );
		}
		if ( ! hash_equals( $manifest['version'] . '+' . $manifest['commit_sha'], $manifest['release_id'] ) ) {
			self::fail( 'HAL_RUNTIME_MANIFEST_RELEASE_IDENTITY_MISMATCH' );
		}
		foreach ( array( 'requires_wp', 'requires_php' ) as $field ) {
			if ( ! is_string( $manifest[ $field ] ) || 1 !== preg_match( '/\A[0-9]+\.[0-9]+(?:\.[0-9]+)?\z/D', $manifest[ $field ] ) ) {
				self::fail( 'HAL_RUNTIME_MANIFEST_REQUIREMENT_INVALID' );
			}
		}
		if ( ! $this->valid_version( $manifest['loader_core_version'] ) || ! is_string( $manifest['schema_version'] ) || 1 !== preg_match( '/\A[A-Za-z0-9._-]+\z/D', $manifest['schema_version'] ) ) {
			self::fail( 'HAL_RUNTIME_MANIFEST_REQUIREMENT_INVALID' );
		}
		if ( ! is_int( $manifest['loader_api_min'] ) || ! is_int( $manifest['loader_api_max'] ) || $manifest['loader_api_min'] < 1 || $manifest['loader_api_max'] < $manifest['loader_api_min'] ) {
			self::fail( 'HAL_RUNTIME_MANIFEST_LOADER_API_INVALID' );
		}
		$this->assert_sha256( $manifest['archive_sha256'], 'HAL_RUNTIME_MANIFEST_ARCHIVE_HASH_INVALID' );
		$this->validate_file_map( $manifest['files'], 'runtime' );

		if ( ! isset( $manifest['files']['bootstrap.php'] ) ) {
			self::fail( 'HAL_RUNTIME_MANIFEST_BOOTSTRAP_MISSING' );
		}
		if ( array_key_exists( 'next_key', $manifest ) ) {
			$next = $manifest['next_key'];
			// B1-06: schema يشترط object — null أو أي قيمة غير مصفوفة
			// ترفض هنا كما ترفضها بوابة schema تمامًا.
			if ( ! is_array( $next ) ) {
				self::fail( 'HAL_RUNTIME_MANIFEST_NEXT_KEY_INVALID' );
			}
			$this->assert_keys( $next, array( 'public_key', 'fingerprint', 'not_before' ), array( 'public_key', 'fingerprint', 'not_before' ) );
			$key = base64_decode( (string) $next['public_key'], true );
			if (
				false === $key
				|| SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $key )
				|| ! hash_equals( 'sha256:' . hash( 'sha256', $key ), (string) $next['fingerprint'] )
				|| false === strtotime( (string) $next['not_before'] )
			) {
				self::fail( 'HAL_RUNTIME_MANIFEST_NEXT_KEY_INVALID' );
			}
		}
	}

	private function validate_carrier_manifest( array $manifest ): void {
		$required = array( 'schema', 'product', 'version', 'plugin_basename', 'files' );
		$allowed = array_merge( $required, array( 'archive_sha256' ) );
		$this->assert_keys( $manifest, $required, $allowed );

		if ( 1 !== $manifest['schema'] || self::PRODUCT !== $manifest['product'] || self::PLUGIN_BASENAME !== $manifest['plugin_basename'] || ! $this->valid_version( $manifest['version'] ) ) {
			self::fail( 'HAL_CARRIER_MANIFEST_IDENTITY_INVALID' );
		}
		if ( isset( $manifest['archive_sha256'] ) ) {
			$this->assert_sha256( $manifest['archive_sha256'], 'HAL_CARRIER_MANIFEST_ARCHIVE_HASH_INVALID' );
		}
		$this->validate_file_map( $manifest['files'], 'carrier' );

		// B1-04 / A §6.2: Carrier الكامل يفرض ملفات بعينها بأسمائها
		// الدقيقة، لا اكتفاءً بتطابق prefixes — أي ملف إلزامي غائب يرفض.
		$required_exact = array(
			'hal-frontend-dashboard.php',
			'readme.txt',
			'LICENSE',
			'THIRD-PARTY-NOTICES.txt',
			'mu-loader/hal-frontend-dashboard.php',
			'mu-loader/loader-core.php',
			'payload/runtime-manifest.json',
			'payload/runtime-manifest.sig',
		);
		foreach ( $required_exact as $required_path ) {
			if ( ! isset( $manifest['files'][ $required_path ] ) ) {
				self::fail( 'HAL_CARRIER_MANIFEST_INCOMPLETE' );
			}
		}
		// includes/ وpayload/ مجلدان إلزاميان بمحتوى فعلي، ويجب وجود
		// أرشيف runtime واحد على الأقل بأسماء الحمولة المعتمدة.
		$required_prefixed = array( 'includes/' );
		foreach ( $required_prefixed as $prefix ) {
			$present = (bool) array_filter(
				array_keys( $manifest['files'] ),
				static fn ( string $path ): bool => str_starts_with( $path, $prefix )
			);
			if ( ! $present ) {
				self::fail( 'HAL_CARRIER_MANIFEST_INCOMPLETE' );
			}
		}
		$runtime_archive = (bool) array_filter(
			array_keys( $manifest['files'] ),
			static fn ( string $p ): bool => str_starts_with( $p, 'payload/runtime-' ) && str_ends_with( $p, '.zip' )
		);
		if ( ! $runtime_archive ) {
			self::fail( 'HAL_CARRIER_MANIFEST_INCOMPLETE' );
		}
	}

	private function assert_keys( array $value, array $required, array $allowed ): void {
		if ( array_diff( $required, array_keys( $value ) ) || array_diff( array_keys( $value ), $allowed ) ) {
			self::fail( 'HAL_MANIFEST_FIELDS_INVALID' );
		}
	}

	private function validate_file_map( mixed $files, string $kind ): void {
		if ( ! is_array( $files ) || array_is_list( $files ) || array() === $files ) {
			self::fail( 'HAL_MANIFEST_FILE_MAP_INVALID' );
		}

		foreach ( $files as $path => $hash ) {
			$normalized = $this->normalize_relative_path( (string) $path );
			if ( $normalized !== $path || str_ends_with( $normalized, '/' ) ) {
				self::fail( 'HAL_MANIFEST_FILE_PATH_INVALID' );
			}
			$this->assert_distributable_path( $normalized, $kind );
			$this->assert_sha256( $hash, 'HAL_MANIFEST_FILE_HASH_INVALID' );
		}
	}

	private function verify_zip_inventory( string $archive, array $expected_files ): void {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $archive, ZipArchive::CHECKCONS | ZipArchive::RDONLY ) ) {
			self::fail( 'HAL_RUNTIME_ARCHIVE_OPEN_FAILED' );
		}

		$actual = array();
		try {
			$this->assert_zip_safety( $zip );
			for ( $index = 0; $index < $zip->numFiles; $index++ ) {
				$stat = $zip->statIndex( $index, ZipArchive::FL_UNCHANGED );
				if ( false === $stat ) {
					self::fail( 'HAL_RUNTIME_ARCHIVE_ENTRY_INVALID' );
				}
				$name = $this->normalize_relative_path( $stat['name'] );
				if ( str_ends_with( $stat['name'], '/' ) ) {
					$this->assert_distributable_path( rtrim( $name, '/' ), 'runtime' );
					continue;
				}
				if ( isset( $actual[ $name ] ) || ! isset( $expected_files[ $name ] ) ) {
					self::fail( 'HAL_RUNTIME_ARCHIVE_INVENTORY_INVALID' );
				}

				$stream = $zip->getStream( $stat['name'] );
				if ( false === $stream ) {
					self::fail( 'HAL_RUNTIME_ARCHIVE_ENTRY_UNREADABLE' );
				}
				$hash = hash_init( 'sha256' );
				hash_update_stream( $hash, $stream );
				fclose( $stream );
				$actual[ $name ] = hash_final( $hash );
			}
		} finally {
			$zip->close();
		}

		ksort( $actual, SORT_STRING );
		ksort( $expected_files, SORT_STRING );
		if ( array_keys( $actual ) !== array_keys( $expected_files ) ) {
			self::fail( 'HAL_RUNTIME_ARCHIVE_INVENTORY_INVALID' );
		}
		foreach ( $expected_files as $path => $hash ) {
			if ( ! hash_equals( $hash, $actual[ $path ] ) ) {
				self::fail( 'HAL_RUNTIME_ARCHIVE_FILE_HASH_MISMATCH' );
			}
		}
	}

	private function assert_zip_safety( ZipArchive $zip ): void {
		if ( $zip->numFiles < 1 || $zip->numFiles > self::MAX_ENTRIES ) {
			self::fail( 'HAL_RUNTIME_ARCHIVE_ENTRY_LIMIT' );
		}

		$compressed = 0;
		$uncompressed = 0;
		$seen = array();
		for ( $index = 0; $index < $zip->numFiles; $index++ ) {
			$stat = $zip->statIndex( $index, ZipArchive::FL_UNCHANGED );
			if ( false === $stat ) {
				self::fail( 'HAL_RUNTIME_ARCHIVE_ENTRY_INVALID' );
			}
			$name = $this->normalize_relative_path( $stat['name'] );
			if ( isset( $seen[ $name ] ) ) {
				self::fail( 'HAL_RUNTIME_ARCHIVE_DUPLICATE_ENTRY' );
			}
			$seen[ $name ] = true;
			$compressed += (int) $stat['comp_size'];
			$uncompressed += (int) $stat['size'];

			$operations = 0;
			$attributes = 0;
			if ( $zip->getExternalAttributesIndex( $index, $operations, $attributes ) ) {
				$type = ( $attributes >> 16 ) & 0xF000;
				if ( 0 !== $type && 0x8000 !== $type && 0x4000 !== $type ) {
					self::fail( 'HAL_RUNTIME_ARCHIVE_LINK_ENTRY' );
				}
			}
		}

		if ( $compressed > self::MAX_COMPRESSED_BYTES || $uncompressed > self::MAX_UNCOMPRESSED_BYTES ) {
			self::fail( 'HAL_RUNTIME_ARCHIVE_SIZE_LIMIT' );
		}
		if ( $compressed > 0 && $uncompressed > $compressed * self::MAX_COMPRESSION_RATIO ) {
			self::fail( 'HAL_RUNTIME_ARCHIVE_COMPRESSION_RATIO_LIMIT' );
		}
	}

	private function verify_tree_inventory( string $root, array $expected_files, array $ignored_files = array(), string $kind = 'runtime' ): void {
		// B1-03: فحص الروابط على المسار الأصلي قبل realpath — realpath
		// يحل الوجهة فلا يكشف أبدًا جذرًا مرتبطًا؛ الأصل هو المرجع.
		if ( is_link( $root ) ) {
			self::fail( 'HAL_PACKAGE_TREE_ROOT_INVALID' );
		}
		$root_real = realpath( $root );
		if ( false === $root_real || ! is_dir( $root_real ) ) {
			self::fail( 'HAL_PACKAGE_TREE_ROOT_INVALID' );
		}
		// Windows junction/reparse: is_link لا يكشفه؛ نفس مقارنة
		// lexical/canonical المستخدمة في cleanup ترفض الجذر المتحوّل.
		if ( 'Windows' === PHP_OS_FAMILY ) {
			$lexical = strtolower( str_replace( '/', '\\', rtrim( $root, '/\\' ) ) );
			$canonical = strtolower( str_replace( '/', '\\', rtrim( $root_real, '/\\' ) ) );
			if ( $lexical !== $canonical ) {
				self::fail( 'HAL_PACKAGE_TREE_ROOT_INVALID' );
			}
		}

		$actual = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root_real, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $iterator as $item ) {
			if ( $item->isLink() ) {
				self::fail( 'HAL_PACKAGE_TREE_LINK_REJECTED' );
			}
			$relative = str_replace( '\\', '/', substr( $item->getPathname(), strlen( $root_real ) + 1 ) );
			$relative = $this->normalize_relative_path( $relative );
			if ( $item->isDir() ) {
				// B1-05: سياسة المسارات الممنوعة تُطبق على المجلدات أيضًا —
				// مجلد Docs/ أو root vendor/ فارغ لا يُقبل في فحص الشجرة
				// أكثر مما يُقبل في فحص ZIP.
				$this->assert_distributable_path( rtrim( $relative, '/' ), $kind );
				continue;
			}
			if ( in_array( $relative, $ignored_files, true ) ) {
				continue;
			}
			if ( isset( $actual[ $relative ] ) || ! isset( $expected_files[ $relative ] ) ) {
				self::fail( 'HAL_PACKAGE_TREE_INVENTORY_INVALID' );
			}
			$actual[ $relative ] = hash_file( 'sha256', $item->getPathname() );
		}

		ksort( $actual, SORT_STRING );
		ksort( $expected_files, SORT_STRING );
		if ( array_keys( $actual ) !== array_keys( $expected_files ) ) {
			self::fail( 'HAL_PACKAGE_TREE_INVENTORY_INVALID' );
		}
		foreach ( $expected_files as $path => $hash ) {
			if ( ! is_string( $actual[ $path ] ) || ! hash_equals( $hash, $actual[ $path ] ) ) {
				self::fail( 'HAL_PACKAGE_TREE_HASH_MISMATCH' );
			}
		}
	}

	private function assert_distributable_path( string $path, string $kind ): void {
		$segments = explode( '/', strtolower( $path ) );
		$blocked_directories = array( '.git', '.github', 'docs', 'tests', 'node_modules' );
		if ( array_intersect( $segments, $blocked_directories ) ) {
			self::fail( 'HAL_PACKAGE_PROHIBITED_PATH' );
		}
		if ( 'carrier' === $kind && 'vendor' === $segments[0] ) {
			self::fail( 'HAL_PACKAGE_PROHIBITED_PATH' );
		}

		$basename = end( $segments );
		if (
			false === $basename
			|| '.env' === $basename
			|| str_starts_with( $basename, '.env.' )
			|| str_ends_with( $basename, '.log' )
			|| preg_match( '/\A(?:logo|screenshot)(?:[-_.]|\z)/', $basename )
		) {
			self::fail( 'HAL_PACKAGE_PROHIBITED_PATH' );
		}
	}

	private function normalize_relative_path( string $path ): string {
		if ( '' === $path || str_contains( $path, "\0" ) || preg_match( '/\\A(?:[A-Za-z]:|[\\\\\/])/', $path ) ) {
			self::fail( 'HAL_PACKAGE_PATH_INVALID' );
		}
		$path = str_replace( '\\', '/', $path );
		$segments = explode( '/', rtrim( $path, '/' ) );
		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				self::fail( 'HAL_PACKAGE_PATH_INVALID' );
			}
		}
		return implode( '/', $segments ) . ( str_ends_with( $path, '/' ) ? '/' : '' );
	}

	private function contained_target( string $root, string $relative ): string {
		$relative = rtrim( $this->normalize_relative_path( $relative ), '/' );
		return rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
	}

	private function create_contained_directory( string $root, string $relative ): void {
		if ( '.' === $relative || '' === $relative ) {
			return;
		}
		$relative = rtrim( $this->normalize_relative_path( $relative ), '/' );
		$current = rtrim( $root, '/\\' );
		foreach ( explode( '/', $relative ) as $segment ) {
			$current .= DIRECTORY_SEPARATOR . $segment;
			if ( is_link( $current ) ) {
				self::fail( 'HAL_STAGING_LINK_REJECTED' );
			}
			if ( file_exists( $current ) ) {
				if ( ! is_dir( $current ) ) {
					self::fail( 'HAL_STAGING_PATH_INVALID' );
				}
				continue;
			}
			if ( ! mkdir( $current, $this->directory_mode() ) ) {
				self::fail( 'HAL_STAGING_CREATE_FAILED' );
			}
		}
	}

	private function valid_version( mixed $version ): bool {
		return is_string( $version ) && 1 === preg_match( '/\\A(?:0|[1-9][0-9]*)\\.(?:0|[1-9][0-9]*)\\.(?:0|[1-9][0-9]*)\\z/D', $version );
	}

	private function assert_sha256( mixed $hash, string $code ): void {
		if ( ! is_string( $hash ) || 1 !== preg_match( '/\\A[a-f0-9]{64}\\z/D', $hash ) ) {
			self::fail( $code );
		}
	}

	private function directory_mode(): int {
		return defined( 'FS_CHMOD_DIR' ) ? FS_CHMOD_DIR : 0755;
	}

	private function file_mode(): int {
		return defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
	}

	private static function fail( string $code ): never {
		throw new RuntimeException( $code );
	}
}
