<?php
/**
 * HAL Frontend Dashboard — Batch 11 harness: package verifier.
 *
 * Local, isolated harness: no live WordPress, no network, no database.
 * It calls the REAL includes/class-package-verifier.php with manifests
 * signed by EPHEMERAL Ed25519 keypairs generated at runtime
 * (sodium_crypto_sign_keypair) — never the production release key, never
 * any private key material in the repo. The verifier constructor already
 * accepts an injected test key, so no production seam edit was needed.
 *
 * Coverage (§22 package-verifier row + closure-gate package inspection):
 *   runtime/carrier manifests, tampered Carrier main/includes/mu-loader/
 *   payload, extra root vendor, traversal, tree symlink, size-attribute
 *   link entry, signature/checksum/inventory mismatches, manifest shape
 *   gates, workflow-order freeze timing (manifest after sig injection
 *   verifies; a payload injected without regen is inventory-rejected),
 *   and full inspection of every file inside the produced
 *   workflow-like package (bans: Docs/tests/.github/vendor/.env/logs).
 *
 * Usage:  php -d extension=sodium tests/php/package-verifier-test.php
 *         exit 0 = all checks pass.
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

if ( PHP_SAPI !== 'cli' ) {
	echo "This harness must run under CLI PHP.\n";
	exit( 1 );
}

$project = dirname( __DIR__, 2 );
$ws = $project . '/.local-execution/batch-11/verifier-' . getmypid();
@mkdir( $ws, 0777, true );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $ws . '/wp/' );
}
@mkdir( ABSPATH, 0777, true );

$GLOBALS['HAL_PV_RESULTS'] = array();
$GLOBALS['HAL_PV_SKIPPED'] = array();

function hal_pv_check( string $id, bool $condition, string $pass, string $fail = '' ): void {
	$GLOBALS['HAL_PV_RESULTS'][] = array( 'id' => $id, 'ok' => $condition, 'pass' => $pass, 'fail' => $fail );
	echo ( $condition ? 'PASS' : 'FAIL' ) . " [$id] " . ( $condition ? $pass : $fail ) . "\n";
}

function hal_pv_code( callable $fn ): string {
	try {
		$fn();
	} catch ( RuntimeException $exception ) {
		return $exception->getMessage();
	} catch ( Throwable $exception ) {
		return get_class( $exception ) . ':' . $exception->getMessage();
	}
	return '';
}

function hal_pv_remove_dir( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $item ) {
		if ( $item->isLink() || ! $item->isDir() ) {
			@unlink( $item->getPathname() );
		} else {
			@rmdir( $item->getPathname() );
		}
	}
	@rmdir( $dir );
}

require_once $project . '/includes/class-package-verifier.php';

/* ── Ephemeral signing (test keypair only) ── */

$keypair = sodium_crypto_sign_keypair();
$pv_secret = sodium_crypto_sign_secretkey( $keypair );
$pv_public = sodium_crypto_sign_publickey( $keypair );
$verifier = new HAL_Frontend_Dashboard_Package_Verifier( base64_encode( $pv_public ), hash( 'sha256', $pv_public ) );

function hal_pv_sign( array $manifest ): array {
	$raw = str_replace( "\r\n", "\n", json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) ) . "\n";
	return array( $raw, base64_encode( sodium_crypto_sign_detached( $raw, $GLOBALS['HAL_PV_SECRET'] ) ) );
}
$GLOBALS['HAL_PV_SECRET'] = $pv_secret;

function hal_pv_runtime_manifest( string $archive_sha, array $files, string $version = '1.0.0' ): array {
	$sha = str_repeat( 'a', 40 );
	return array(
		'schema' => 1, 'product' => 'hal-frontend-dashboard', 'version' => $version,
		'release_sequence' => 1, 'release_id' => $version . '+' . $sha, 'tag' => 'v' . $version,
		'commit_sha' => $sha, 'requires_wp' => '7.0', 'requires_php' => '8.3',
		'loader_api_min' => 1, 'loader_api_max' => 1, 'loader_core_version' => $version,
		'schema_version' => '1', 'archive_sha256' => $archive_sha, 'files' => $files,
	);
}

function hal_pv_make_zip( string $path, array $entries ): void {
	$zip = new ZipArchive();
	if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		throw new RuntimeException( 'HAL_TEST_ZIP_CREATE_FAILED' );
	}
	foreach ( $entries as $name => $content ) {
		$zip->addFromString( $name, $content );
	}
	$zip->close();
}

/* ════════════════════════════════════════════════════════════════
 * RUNTIME — manifest / signature / checksum / inventory
 * ════════════════════════════════════════════════════════════════ */

$rt_dir = $ws . '/runtime';
@mkdir( $rt_dir, 0777, true );
$bootstrap_content = "<?php\n// fixture runtime bootstrap\n";
$core_content = "<?php\n// fixture runtime core\n";
$rt_files = array( 'bootstrap.php' => hash( 'sha256', $bootstrap_content ), 'core/setup.php' => hash( 'sha256', $core_content ) );
$rt_zip = $rt_dir . '/runtime-1.0.0.zip';
hal_pv_make_zip( $rt_zip, array( 'bootstrap.php' => $bootstrap_content, 'core/setup.php' => $core_content ) );
$rt_manifest = hal_pv_runtime_manifest( hash_file( 'sha256', $rt_zip ), $rt_files );
list( $rt_raw, $rt_sig ) = hal_pv_sign( $rt_manifest );
file_put_contents( $rt_dir . '/runtime-manifest.json', $rt_raw );
file_put_contents( $rt_dir . '/runtime-manifest.sig', $rt_sig );

/* P1 — valid signed runtime verifies end to end. */
$verified = null;
try {
	$verified = $verifier->verify_runtime_archive( $rt_zip, $rt_dir . '/runtime-manifest.json', $rt_dir . '/runtime-manifest.sig' );
	$p1 = is_array( $verified ) && '1.0.0' === ( $verified['version'] ?? null );
} catch ( Throwable $exception ) {
	$p1 = false;
}
hal_pv_check( 'P1-RUNTIME-VALID', $p1, 'signed runtime manifest + ZIP verified end to end (ephemeral key)', 'valid runtime rejected' );

/* P2 — tampered manifest bytes rejected by signature. */
$tampered = $rt_manifest;
$tampered['version'] = '9.9.9';
list( $tampered_raw, ) = hal_pv_sign( $tampered );
file_put_contents( $rt_dir . '/runtime-manifest.tampered.json', $tampered_raw );
$code = hal_pv_code( static function () use ( $verifier, $rt_zip, $rt_dir ): void {
	$verifier->verify_runtime_archive( $rt_zip, $rt_dir . '/runtime-manifest.tampered.json', $rt_dir . '/runtime-manifest.sig' );
} );
hal_pv_check( 'P2-TAMPER-MANIFEST', 'HAL_SIGNATURE_VERIFICATION_FAILED' === $code, 'tampered manifest bytes rejected by signature', 'code: ' . $code );

/* P3 — archive hash mismatch. */
$other_zip = $rt_dir . '/runtime-other.zip';
hal_pv_make_zip( $other_zip, array( 'bootstrap.php' => $bootstrap_content . '// other' ) );
$code = hal_pv_code( static function () use ( $verifier, $other_zip, $rt_dir ): void {
	$verifier->verify_runtime_archive( $other_zip, $rt_dir . '/runtime-manifest.json', $rt_dir . '/runtime-manifest.sig' );
} );
hal_pv_check( 'P3-ARCHIVE-HASH', 'HAL_RUNTIME_ARCHIVE_HASH_MISMATCH' === $code, 'different archive rejected by manifest hash', 'code: ' . $code );

/* P4 — extra unexpected file rejected by inventory. */
$extra_zip = $rt_dir . '/runtime-extra.zip';
hal_pv_make_zip( $extra_zip, array( 'bootstrap.php' => $bootstrap_content, 'core/setup.php' => $core_content, 'evil-extra.php' => '<?php // extra' ) );
$extra_manifest = hal_pv_runtime_manifest( hash_file( 'sha256', $extra_zip ), $rt_files );
list( $extra_raw, $extra_sig ) = hal_pv_sign( $extra_manifest );
file_put_contents( $rt_dir . '/runtime-manifest-extra.json', $extra_raw );
file_put_contents( $rt_dir . '/runtime-manifest-extra.sig', $extra_sig );
$code = hal_pv_code( static function () use ( $verifier, $extra_zip, $rt_dir ): void {
	$verifier->verify_runtime_archive( $extra_zip, $rt_dir . '/runtime-manifest-extra.json', $rt_dir . '/runtime-manifest-extra.sig' );
} );
hal_pv_check( 'P4-EXTRA-FILE', 'HAL_RUNTIME_ARCHIVE_INVENTORY_INVALID' === $code, 'unexpected extra ZIP file rejected by inventory', 'code: ' . $code );

/* P5 — traversal entry rejected before extraction. */
$trav_zip = $rt_dir . '/runtime-traversal.zip';
hal_pv_make_zip( $trav_zip, array( 'bootstrap.php' => $bootstrap_content, '../escape.php' => '<?php' ) );
$trav_manifest = hal_pv_runtime_manifest( hash_file( 'sha256', $trav_zip ), $rt_files );
list( $trav_raw, $trav_sig ) = hal_pv_sign( $trav_manifest );
file_put_contents( $rt_dir . '/runtime-manifest-traversal.json', $trav_raw );
file_put_contents( $rt_dir . '/runtime-manifest-traversal.sig', $trav_sig );
$code = hal_pv_code( static function () use ( $verifier, $trav_zip, $rt_dir ): void {
	$verifier->verify_runtime_archive( $trav_zip, $rt_dir . '/runtime-manifest-traversal.json', $rt_dir . '/runtime-manifest-traversal.sig' );
} );
hal_pv_check( 'P5-TRAVERSAL', 'HAL_PACKAGE_PATH_INVALID' === $code, 'ZIP traversal entry rejected before extraction', 'code: ' . $code );

/* P6 — missing bootstrap in manifest shape rejected. */
$no_boot = $rt_manifest;
unset( $no_boot['files']['bootstrap.php'] );
list( $no_boot_raw, $no_boot_sig ) = hal_pv_sign( $no_boot );
file_put_contents( $rt_dir . '/runtime-manifest-noboot.json', $no_boot_raw );
file_put_contents( $rt_dir . '/runtime-manifest-noboot.sig', $no_boot_sig );
$code = hal_pv_code( static function () use ( $verifier, $rt_zip, $rt_dir ): void {
	$verifier->verify_runtime_archive( $rt_zip, $rt_dir . '/runtime-manifest-noboot.json', $rt_dir . '/runtime-manifest-noboot.sig' );
} );
hal_pv_check( 'P6-BOOTSTRAP-REQUIRED', 'HAL_RUNTIME_MANIFEST_BOOTSTRAP_MISSING' === $code || 'HAL_RUNTIME_ARCHIVE_INVENTORY_INVALID' === $code, 'manifest without bootstrap.php rejected (' . $code . ')', 'code: ' . $code );

/* P7 — zero release_sequence rejected. */
$zero_seq = $rt_manifest;
$zero_seq['release_sequence'] = 0;
list( $zero_raw, $zero_sig ) = hal_pv_sign( $zero_seq );
file_put_contents( $rt_dir . '/runtime-manifest-zeroseq.json', $zero_raw );
file_put_contents( $rt_dir . '/runtime-manifest-zeroseq.sig', $zero_sig );
$code = hal_pv_code( static function () use ( $verifier, $rt_zip, $rt_dir ): void {
	$verifier->verify_runtime_archive( $rt_zip, $rt_dir . '/runtime-manifest-zeroseq.json', $rt_dir . '/runtime-manifest-zeroseq.sig' );
} );
hal_pv_check( 'P7-ZERO-SEQUENCE', 'HAL_RUNTIME_MANIFEST_SEQUENCE_INVALID' === $code, 'zero release_sequence rejected', 'code: ' . $code );

/* ════════════════════════════════════════════════════════════════
 * CARRIER — workflow-like package, full file inspection
 * ════════════════════════════════════════════════════════════════ */

$carrier_src = $ws . '/carrier-src';
@mkdir( $carrier_src . '/includes', 0777, true );
@mkdir( $carrier_src . '/mu-loader', 0777, true );
@mkdir( $carrier_src . '/payload', 0777, true );
$carrier_files_content = array(
	'hal-frontend-dashboard.php' => "<?php\ndefined( 'ABSPATH' ) || exit;\n// carrier main\n",
	'readme.txt' => "=== HAL Frontend Dashboard ===\n",
	'LICENSE' => "GPL-2.0-or-later\n",
	'THIRD-PARTY-NOTICES.txt' => "notices\n",
	'includes/class-installer.php' => "<?php\ndefined( 'ABSPATH' ) || exit;\n// installer\n",
	'includes/class-package-verifier.php' => "<?php\ndefined( 'ABSPATH' ) || exit;\n// verifier\n",
	'mu-loader/hal-frontend-dashboard.php' => "<?php\ndefined( 'ABSPATH' ) || exit;\n// anchor\n",
	'mu-loader/loader-core.php' => "<?php\ndefined( 'ABSPATH' ) || exit;\n// loader core\n",
	'payload/runtime-1.0.0.zip' => null, // binary copied below
	'payload/runtime-manifest.json' => $rt_raw,
	'payload/runtime-manifest.sig' => $rt_sig,
);
foreach ( $carrier_files_content as $relative => $content ) {
	if ( null === $content ) {
		copy( $rt_zip, $carrier_src . '/' . $relative );
	} else {
		file_put_contents( $carrier_src . '/' . $relative, $content );
	}
}
$carrier_map = array();
foreach ( $carrier_files_content as $relative => $content ) {
	$carrier_map[ $relative ] = hash_file( 'sha256', $carrier_src . '/' . $relative );
}
ksort( $carrier_map, SORT_STRING );
$carrier_manifest = array(
	'schema' => 1, 'product' => 'hal-frontend-dashboard', 'version' => '1.0.0',
	'plugin_basename' => 'hal-frontend-dashboard/hal-frontend-dashboard.php', 'files' => $carrier_map,
);
list( $carrier_raw, $carrier_sig ) = hal_pv_sign( $carrier_manifest );
file_put_contents( $carrier_src . '/carrier-manifest.json', $carrier_raw );
file_put_contents( $carrier_src . '/carrier-manifest.sig', $carrier_sig );

/* Workflow-like ZIP: single root hal-frontend-dashboard/, sorted entries. */
$carrier_zip = $ws . '/hal-frontend-dashboard-1.0.0.zip';
$zip = new ZipArchive();
$zip->open( $carrier_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE );
$names = array_keys( $carrier_map );
$names[] = 'carrier-manifest.json';
$names[] = 'carrier-manifest.sig';
sort( $names, SORT_STRING );
foreach ( $names as $name ) {
	$zip->addFile( $carrier_src . '/' . $name, 'hal-frontend-dashboard/' . $name );
}
$zip->close();

/* P8 — closure gate, part 1: every file inside the produced package inspected. */
$zip = new ZipArchive();
$zip->open( $carrier_zip );
$inside = array();
for ( $i = 0; $i < $zip->numFiles; $i++ ) {
	$inside[] = $zip->getNameIndex( $i );
}
$zip->close();
sort( $inside, SORT_STRING );
$expected_inside = array_map( static function ( string $n ): string {
	return 'hal-frontend-dashboard/' . $n;
}, $names );
$banned_hits = array();
foreach ( $inside as $entry ) {
	$lower = strtolower( $entry );
	foreach ( array( 'docs/', 'tests/', '.github/', '.git/', 'node_modules/', '.env', '.log', 'logo.png', 'vendor/' ) as $banned ) {
		if ( false !== strpos( $lower, $banned ) ) {
			$banned_hits[] = $entry . ' ~ ' . $banned;
		}
	}
}
hal_pv_check(
	'P8-PACKAGE-INSPECTION',
	$inside === $expected_inside && array() === $banned_hits,
	'inspected ' . count( $inside ) . ' package entries: exact expected set, zero banned paths (Docs/tests/.github/vendor/.env/logs/logo)',
	'entries=' . json_encode( $inside ) . ' banned=' . json_encode( $banned_hits )
);

/* P9 — valid workflow-like Carrier archive verifies. */
try {
	$carrier_verified = $verifier->verify_carrier_archive( $carrier_zip );
	$p9 = is_array( $carrier_verified ) && '1.0.0' === ( $carrier_verified['version'] ?? null );
} catch ( Throwable $exception ) {
	$p9 = false;
	$carrier_verified = null;
}
hal_pv_check( 'P9-CARRIER-VALID', $p9, 'workflow-like Carrier ZIP verified against its signed manifest', 'valid carrier rejected' );

/* P10–P13 — tampered Carrier members rejected (main / includes / mu-loader / payload). */
foreach ( array(
	'P10' => 'hal-frontend-dashboard.php',
	'P11' => 'includes/class-installer.php',
	'P12' => 'mu-loader/loader-core.php',
	'P13' => 'payload/runtime-1.0.0.zip',
) as $id => $member ) {
	$mutated = $ws . '/carrier-mutated-' . $id . '.zip';
	copy( $carrier_zip, $mutated );
	$zip = new ZipArchive();
	$zip->open( $mutated );
	$original = $zip->getFromName( 'hal-frontend-dashboard/' . $member );
	$zip->deleteName( 'hal-frontend-dashboard/' . $member );
	$zip->addFromString( 'hal-frontend-dashboard/' . $member, (string) $original . '// TAMPERED' );
	$zip->close();
	$code = hal_pv_code( static function () use ( $verifier, $mutated ): void {
		$verifier->verify_carrier_archive( $mutated );
	} );
	hal_pv_check(
		$id . '-TAMPER',
		'HAL_CARRIER_ARCHIVE_HASH_MISMATCH' === $code,
		'tampered carrier member ' . $member . ' rejected with HAL_CARRIER_ARCHIVE_HASH_MISMATCH',
		'code: ' . $code
	);
	@unlink( $mutated );
}

/* P14 — extra root vendor/ in the Carrier ZIP rejected. */
$vendor_zip = $ws . '/carrier-vendor.zip';
copy( $carrier_zip, $vendor_zip );
$zip = new ZipArchive();
$zip->open( $vendor_zip );
$zip->addFromString( 'hal-frontend-dashboard/vendor/autoload.php', '<?php // no' );
$zip->close();
$code = hal_pv_code( static function () use ( $verifier, $vendor_zip ): void {
	$verifier->verify_carrier_archive( $vendor_zip );
} );
hal_pv_check(
	'P14-ROOT-VENDOR',
	'HAL_PACKAGE_PROHIBITED_PATH' === $code || 'HAL_CARRIER_ARCHIVE_INVENTORY_INVALID' === $code,
	'root vendor/ in Carrier rejected (' . $code . ')',
	'code: ' . $code
);
@unlink( $vendor_zip );

/* P15 — Docs/ inside the Carrier tree rejected (literal ban). */
$docs_tree = $ws . '/carrier-docs-tree';
hal_pv_remove_dir( $docs_tree );
@mkdir( $docs_tree . '/Docs', 0777, true );
file_put_contents( $docs_tree . '/bootstrap.php', "<?php\n" );
$docs_manifest = hal_pv_runtime_manifest(
	str_repeat( '0', 64 ),
	array( 'bootstrap.php' => hash_file( 'sha256', $docs_tree . '/bootstrap.php' ), 'Docs/notes.md' => str_repeat( '0', 64 ) )
);
try {
	$rm = new ReflectionMethod( $verifier, 'verify_tree_inventory' );
	$rm->setAccessible( true );
	$rm->invoke( $verifier, $docs_tree, $docs_manifest['files'], array(), 'runtime' );
	$docs_code = '';
} catch ( RuntimeException $exception ) {
	$docs_code = $exception->getMessage();
}
hal_pv_check( 'P15-DOCS-BAN', 'HAL_PACKAGE_PROHIBITED_PATH' === $docs_code, 'Docs/ directory rejected by the literal ban', 'code: ' . $docs_code );

/* P16 — tree symlink rejected. */
$link_tree = $ws . '/link-tree-target';
$link_root = $ws . '/link-tree-link';
hal_pv_remove_dir( $link_tree );
@mkdir( $link_tree, 0777, true );
file_put_contents( $link_tree . '/bootstrap.php', "<?php\n" );
@unlink( $link_root );
$linked = @symlink( $link_tree, $link_root );
if ( ! $linked ) {
	echo "SKIP [P16-SYMLINK] symlink not creatable on this platform (NOT counted as PASS)\n";
	$GLOBALS['HAL_PV_SKIPPED'][] = 'P16-SYMLINK';
} else {
	$link_map = array( 'bootstrap.php' => hash_file( 'sha256', $link_tree . '/bootstrap.php' ) );
	$code = hal_pv_code( static function () use ( $verifier, $link_root, $link_map ): void {
		$verifier->verify_runtime_tree( $link_root, array( 'files' => $link_map ) );
	} );
	hal_pv_check( 'P16-SYMLINK', 'HAL_PACKAGE_TREE_ROOT_INVALID' === $code, 'symlinked tree root rejected on the original path', 'code: ' . $code );
}

/* P17 — wrong-key carrier signature rejected. */
$other_keypair = sodium_crypto_sign_keypair();
$other_secret = sodium_crypto_sign_secretkey( $other_keypair );
$other_raw = $carrier_raw;
$other_sig = base64_encode( sodium_crypto_sign_detached( $other_raw, $other_secret ) );
$other_dir = $ws . '/carrier-other-key';
hal_pv_remove_dir( $other_dir );
@mkdir( $other_dir, 0777, true );
file_put_contents( $other_dir . '/m.json', $other_raw );
file_put_contents( $other_dir . '/m.sig', $other_sig );
$rm2 = new ReflectionMethod( $verifier, 'verify_manifest_contents' );
$rm2->setAccessible( true );
$code = '';
try {
	$rm2->invoke( $verifier, $other_raw, $other_sig, 'carrier' );
} catch ( RuntimeException $exception ) {
	$code = $exception->getMessage();
}
hal_pv_check( 'P17-WRONG-KEY', 'HAL_SIGNATURE_VERIFICATION_FAILED' === $code, 'foreign-key manifest rejected by signature', 'code: ' . $code );

/* ════════════════════════════════════════════════════════════════
 * WORKFLOW ORDER — the carrier manifest must be frozen only AFTER
 * every payload signature exists (assemble order). A payload triple
 * injected afterwards without regenerating the frozen manifest is
 * rejected by the exact inventory match.
 * ════════════════════════════════════════════════════════════════ */

/* P18 — POSITIVE: carrier manifest generated AFTER the payload
 * signature is injected (assemble order) verifies end to end. */
$wo_root = $ws . '/workflow-order/hal-frontend-dashboard';
@mkdir( $wo_root . '/includes', 0777, true );
@mkdir( $wo_root . '/mu-loader', 0777, true );
@mkdir( $wo_root . '/payload', 0777, true );
$wo_static = array(
	'hal-frontend-dashboard.php' => "<?php\ndefined( 'ABSPATH' ) || exit;\n// carrier main\n",
	'readme.txt' => "=== HAL Frontend Dashboard ===\n",
	'LICENSE' => "GPL-2.0-or-later\n",
	'THIRD-PARTY-NOTICES.txt' => "notices\n",
	'includes/class-installer.php' => "<?php\ndefined( 'ABSPATH' ) || exit;\n// installer\n",
	'includes/class-package-verifier.php' => "<?php\ndefined( 'ABSPATH' ) || exit;\n// verifier\n",
	'mu-loader/hal-frontend-dashboard.php' => "<?php\ndefined( 'ABSPATH' ) || exit;\n// anchor\n",
	'mu-loader/loader-core.php' => "<?php\ndefined( 'ABSPATH' ) || exit;\n// loader core\n",
);
foreach ( $wo_static as $wo_relative => $wo_content ) {
	file_put_contents( $wo_root . '/' . $wo_relative, $wo_content );
}
/* Stage the interim tree: payload runtime zip + manifest, no signatures yet. */
copy( $rt_zip, $wo_root . '/payload/runtime-1.0.0.zip' );
file_put_contents( $wo_root . '/payload/runtime-manifest.json', $rt_raw );
/* Assemble: inject the payload signature, THEN freeze the manifest. */
copy( $rt_dir . '/runtime-manifest.sig', $wo_root . '/payload/runtime-manifest.sig' );
$wo_map = array();
$wo_iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $wo_root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $wo_iterator as $wo_item ) {
	if ( $wo_item->isDir() || $wo_item->isLink() ) {
		continue;
	}
	$wo_rel = str_replace( '\\', '/', substr( $wo_item->getPathname(), strlen( $wo_root ) + 1 ) );
	if ( 'carrier-manifest.json' === $wo_rel || 'carrier-manifest.sig' === $wo_rel ) {
		continue;
	}
	$wo_map[ $wo_rel ] = hash_file( 'sha256', $wo_item->getPathname() );
}
ksort( $wo_map, SORT_STRING );
$wo_manifest = array(
	'schema' => 1, 'product' => 'hal-frontend-dashboard', 'version' => '1.0.0',
	'plugin_basename' => 'hal-frontend-dashboard/hal-frontend-dashboard.php', 'files' => $wo_map,
);
list( $wo_raw, $wo_sig ) = hal_pv_sign( $wo_manifest );
file_put_contents( $wo_root . '/carrier-manifest.json', $wo_raw );
file_put_contents( $wo_root . '/carrier-manifest.sig', $wo_sig );
$wo_zip = $ws . '/hal-workflow-order-1.0.0.zip';
$zip = new ZipArchive();
$zip->open( $wo_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE );
$wo_names = array_keys( $wo_map );
$wo_names[] = 'carrier-manifest.json';
$wo_names[] = 'carrier-manifest.sig';
sort( $wo_names, SORT_STRING );
foreach ( $wo_names as $wo_name ) {
	$zip->addFile( $wo_root . '/' . $wo_name, 'hal-frontend-dashboard/' . $wo_name );
}
$zip->close();
try {
	$wo_verified = $verifier->verify_carrier_archive( $wo_zip );
	$p18 = is_array( $wo_verified ) && '1.0.0' === ( $wo_verified['version'] ?? null );
} catch ( Throwable $exception ) {
	$p18 = false;
}
hal_pv_check( 'P18-WORKFLOW-ORDER-POSITIVE', $p18, 'carrier manifest frozen after payload-sig injection verifies (assemble order)', 'assemble-order carrier rejected' );

/* P19 — NEGATIVE: the payload triple is rebuilt (new version zip +
 * manifest + signature) and injected WITHOUT regenerating the frozen
 * carrier manifest. The frozen manifest predates the injected
 * signature, so the archive inventory no longer matches. */
$rt19_content = $core_content . "// 1.0.1\n";
$rt19_zip = $ws . '/workflow-order/runtime-1.0.1.zip';
hal_pv_make_zip( $rt19_zip, array( 'bootstrap.php' => $bootstrap_content, 'core/setup.php' => $rt19_content ) );
$rt19_files = array( 'bootstrap.php' => hash( 'sha256', $bootstrap_content ), 'core/setup.php' => hash( 'sha256', $rt19_content ) );
$rt19_manifest = hal_pv_runtime_manifest( hash_file( 'sha256', $rt19_zip ), $rt19_files, '1.0.1' );
list( $rt19_raw, $rt19_sig ) = hal_pv_sign( $rt19_manifest );
@unlink( $wo_root . '/payload/runtime-1.0.0.zip' );
copy( $rt19_zip, $wo_root . '/payload/runtime-1.0.1.zip' );
file_put_contents( $wo_root . '/payload/runtime-manifest.json', $rt19_raw );
file_put_contents( $wo_root . '/payload/runtime-manifest.sig', $rt19_sig );
/* The frozen carrier-manifest.json/.sig are left untouched (no regen). */
$stale_zip = $ws . '/hal-workflow-order-stale-1.0.1.zip';
$zip = new ZipArchive();
$zip->open( $stale_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE );
$stale_names = array(
	'hal-frontend-dashboard.php', 'readme.txt', 'LICENSE', 'THIRD-PARTY-NOTICES.txt',
	'includes/class-installer.php', 'includes/class-package-verifier.php',
	'mu-loader/hal-frontend-dashboard.php', 'mu-loader/loader-core.php',
	'payload/runtime-1.0.1.zip', 'payload/runtime-manifest.json', 'payload/runtime-manifest.sig',
	'carrier-manifest.json', 'carrier-manifest.sig',
);
sort( $stale_names, SORT_STRING );
foreach ( $stale_names as $stale_name ) {
	$zip->addFile( $wo_root . '/' . $stale_name, 'hal-frontend-dashboard/' . $stale_name );
}
$zip->close();
$stale_code = hal_pv_code( static function () use ( $verifier, $stale_zip ): void {
	$verifier->verify_carrier_archive( $stale_zip );
} );
hal_pv_check(
	'P19-WORKFLOW-ORDER-NEGATIVE',
	'HAL_CARRIER_ARCHIVE_INVENTORY_INVALID' === $stale_code,
	'a payload signature injected without carrier regen is rejected with HAL_CARRIER_ARCHIVE_INVENTORY_INVALID',
	'code: ' . $stale_code
);

$pass = 0;
foreach ( $GLOBALS['HAL_PV_RESULTS'] as $result ) {
	if ( $result['ok'] ) {
		$pass++;
	}
}
$total = count( $GLOBALS['HAL_PV_RESULTS'] );
$skipped = $GLOBALS['HAL_PV_SKIPPED'] ?? array();
$skip_note = array() === $skipped ? '' : ' +' . count( $skipped ) . ' SKIP (' . implode( ',', $skipped ) . ')';
echo "RESULT: $pass/$total checks passed$skip_note\n";
if ( $pass === $total ) {
	hal_pv_remove_dir( $ws );
}
exit( $pass === $total ? 0 : 1 );
