<?php
/**
 * HAL Frontend Dashboard — Batch 11 harness: release manager.
 *
 * Local, isolated harness: no live WordPress, no network, no database.
 * It drives the REAL includes/class-release-manager.php against a temp
 * MU layout under .local-execution/batch-11/ (the manager is WP-free;
 * only the ABSPATH guard constant is defined). Exit 0 = all checks pass.
 *
 * Coverage (§22 release-manager row + closure-gate update simulation):
 *   lock contention/ownership, promotion, health-failure rollback,
 *   replay rejection, failed-digest suppression, idempotent repeat,
 *   retention pruning, pointer recovery, record finalization, and a full
 *   old→new Carrier update simulation with rollback-to-previous.
 *
 * Usage:  php tests/php/release-manager-test.php
 *         exit 0 = all checks pass.
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

if ( PHP_SAPI !== 'cli' ) {
	echo "This harness must run under CLI PHP.\n";
	exit( 1 );
}

$project = dirname( __DIR__, 2 );
$ws = $project . '/.local-execution/batch-11/release-manager-' . getmypid();
@mkdir( $ws, 0777, true );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $ws . '/wp/' );
}
@mkdir( ABSPATH, 0777, true );

$GLOBALS['HAL_RM_RESULTS'] = array();

function hal_rm_check( string $id, bool $condition, string $pass, string $fail = '' ): void {
	$GLOBALS['HAL_RM_RESULTS'][] = array( 'id' => $id, 'ok' => $condition, 'pass' => $pass, 'fail' => $fail );
	echo ( $condition ? 'PASS' : 'FAIL' ) . " [$id] " . ( $condition ? $pass : $fail ) . "\n";
}

function hal_rm_code( callable $fn ): string {
	try {
		$fn();
	} catch ( RuntimeException $exception ) {
		return $exception->getMessage();
	} catch ( Throwable $exception ) {
		return get_class( $exception ) . ':' . $exception->getMessage();
	}
	return '';
}

function hal_rm_remove_dir( string $dir ): void {
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

require_once $project . '/includes/class-release-manager.php';

function hal_rm_make_release( string $mu, string $id ): void {
	$dir = $mu . '/hal-frontend-dashboard/releases/' . $id;
	@mkdir( $dir, 0777, true );
	file_put_contents( $dir . '/bootstrap.php', "<?php\n// fixture $id\n" );
}

function hal_rm_make_loader( string $mu, string $id ): void {
	$dir = $mu . '/hal-frontend-dashboard/loader-releases/' . $id;
	@mkdir( $dir, 0777, true );
	file_put_contents( $dir . '/loader-core.php', "<?php\n// fixture loader $id\n" );
}

function hal_rm_manifest( string $id, string $version, int $seq, string $digest ): array {
	return array( 'release_id' => $id, 'version' => $version, 'release_sequence' => $seq, 'archive_sha256' => $digest );
}

function hal_rm_state( string $mu, string $file ): ?array {
	$path = $mu . '/hal-frontend-dashboard/state/' . $file;
	return is_file( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;
}

$mu = $ws . '/mu-plugins';
@mkdir( $mu, 0777, true );
$manager = new HAL_Frontend_Dashboard_Release_Manager( $mu );
$manager->ensure_layout();

$id1 = '1.0.0+' . str_repeat( 'a', 40 );
$id2 = '1.1.0+' . str_repeat( 'c', 40 );
hal_rm_make_release( $mu, $id1 );
hal_rm_make_release( $mu, $id2 );
hal_rm_make_loader( $mu, 'loader-1.0.0' );
$m1 = hal_rm_manifest( $id1, '1.0.0', 1, str_repeat( 'b', 64 ) );
$m2 = hal_rm_manifest( $id2, '1.1.0', 2, str_repeat( 'd', 64 ) );

/* R1 — busy lock refuses a second transition. */
$lock_handle = fopen( $mu . '/hal-frontend-dashboard/state/update.lock', 'c+b' );
flock( $lock_handle, LOCK_EX | LOCK_NB );
$code = hal_rm_code( static function () use ( $mu ): void {
	( new HAL_Frontend_Dashboard_Release_Manager( $mu ) )->with_lock( static function (): void {}, 30 );
} );
flock( $lock_handle, LOCK_UN );
fclose( $lock_handle );
hal_rm_check( 'R1-LOCK-BUSY', 'HAL_UPDATE_LOCK_BUSY' === $code, 'concurrent transition refused with HAL_UPDATE_LOCK_BUSY', 'code: ' . $code );

/* R2 — clean promotion: active+committed+history consistent, record removed. */
$code = hal_rm_code( static function () use ( $mu, $m1 ): void {
	( new HAL_Frontend_Dashboard_Release_Manager( $mu ) )->promote_runtime( $m1, static fn(): bool => true, 'loader-1.0.0' );
} );
$active = hal_rm_state( $mu, 'active.json' );
$committed = hal_rm_state( $mu, 'committed.json' );
$history = hal_rm_state( $mu, 'history.json' );
hal_rm_check(
	'R2-PROMOTE',
	'' === $code && $id1 === ( $active['release_id'] ?? null ) && $id1 === ( $committed['release_id'] ?? null )
		&& in_array( $id1, $history['releases'] ?? array(), true )
		&& ! is_file( $mu . '/hal-frontend-dashboard/state/promotion.json' ),
	'v1 promoted: active+committed+history consistent, promotion record removed',
	'code: ' . $code
);

/* R3 — failed health rolls back to the previous release. */
$code = hal_rm_code( static function () use ( $mu, $m2 ): void {
	( new HAL_Frontend_Dashboard_Release_Manager( $mu ) )->promote_runtime( $m2, static fn(): bool => false, 'loader-1.0.0' );
} );
$active = hal_rm_state( $mu, 'active.json' );
$committed = hal_rm_state( $mu, 'committed.json' );
$failed = hal_rm_state( $mu, 'failed-digests.json' );
hal_rm_check(
	'R3-ROLLBACK',
	'HAL_PROMOTION_HEALTH_FAILED' === $code && $id1 === ( $active['release_id'] ?? null )
		&& $id1 === ( $committed['release_id'] ?? null )
		&& isset( $failed['digests'][ str_repeat( 'd', 64 ) ] ),
	'v2 health failure: active+committed stay on v1, failed digest suppressed',
	'code: ' . $code
);

/* R4 — replay (older version/sequence) rejected against committed. */
$id0 = '0.9.0+' . str_repeat( 'e', 40 );
hal_rm_make_release( $mu, $id0 );
$m0 = hal_rm_manifest( $id0, '0.9.0', 1, str_repeat( '0', 64 ) );
$code = hal_rm_code( static function () use ( $mu, $m0 ): void {
	( new HAL_Frontend_Dashboard_Release_Manager( $mu ) )->promote_runtime( $m0, static fn(): bool => true, 'loader-1.0.0' );
} );
hal_rm_check( 'R4-REPLAY', 'HAL_PROMOTION_REPLAY_REJECTED' === $code, 'older version/sequence rejected with HAL_PROMOTION_REPLAY_REJECTED', 'code: ' . $code );

/* R5 — failed digest never re-promotes, even healthy. */
$code = hal_rm_code( static function () use ( $mu, $m2 ): void {
	( new HAL_Frontend_Dashboard_Release_Manager( $mu ) )->promote_runtime( $m2, static fn(): bool => true, 'loader-1.0.0' );
} );
hal_rm_check( 'R5-Failed-DIGEST', 'HAL_PROMOTION_FAILED_DIGEST_REJECTED' === $code, 'failed digest re-promotion rejected', 'code: ' . $code );

/* R6 — idempotent repeat of the committed release. */
$code = hal_rm_code( static function () use ( $mu, $m1 ): void {
	( new HAL_Frontend_Dashboard_Release_Manager( $mu ) )->promote_runtime( $m1, static fn(): bool => true, 'loader-1.0.0' );
} );
$history = hal_rm_state( $mu, 'history.json' );
hal_rm_check(
	'R6-REPEAT',
	'' === $code && array( $id1 ) === ( $history['releases'] ?? null ),
	'repeat promotion of the committed release is a silent no-op (no double history)',
	'code: ' . $code . ' history=' . json_encode( $history )
);

/* R7 — retention keeps active/previous + newest, deletes the rest. */
$extra_ids = array(
	'2.0.0+' . str_repeat( '1', 40 ),
	'2.1.0+' . str_repeat( '2', 40 ),
	'2.2.0+' . str_repeat( '3', 40 ),
	'2.3.0+' . str_repeat( '4', 40 ),
);
foreach ( $extra_ids as $extra ) {
	hal_rm_make_release( $mu, $extra );
}
file_put_contents(
	$mu . '/hal-frontend-dashboard/state/history.json',
	json_encode( array( 'releases' => array_merge( $extra_ids, array( $id1 ) ) ) ) . "\n"
);
$code = hal_rm_code( static function () use ( $mu ): void {
	( new HAL_Frontend_Dashboard_Release_Manager( $mu ) )->prune_retained_releases( 3 );
} );
$history = hal_rm_state( $mu, 'history.json' );
$gone = $extra_ids[3];
$kept = ! is_dir( $mu . '/hal-frontend-dashboard/releases/' . $gone );
$present = true;
foreach ( array( $extra_ids[0], $extra_ids[1], $extra_ids[2], $id1 ) as $keep ) {
	if ( ! is_dir( $mu . '/hal-frontend-dashboard/releases/' . $keep ) ) {
		$present = false;
	}
}
hal_rm_check(
	'R7-RETENTION',
	'' === $code && $kept && $present && array( $extra_ids[0], $extra_ids[1], $extra_ids[2], $id1 ) === ( $history['releases'] ?? null ),
	'prune keeps newest 3 + active/previous, deletes the retired tree, rewrites history',
	'code: ' . $code . ' history=' . json_encode( $history )
);

/* R8 — recovery pointers and record finalization under operation binding. */
$rm8 = new HAL_Frontend_Dashboard_Release_Manager( $mu );
$bad_name = hal_rm_code( static function () use ( $rm8 ): void {
	$rm8->restore_pointer_from_previous( 'evil.json', 'previous.json' );
} );
hal_rm_check( 'R8-BAD-NAME', 'HAL_STATE_POINTER_NAME_INVALID' === $bad_name, 'unknown pointer filename rejected', 'code: ' . $bad_name );
file_put_contents( $mu . '/hal-frontend-dashboard/state/active.json', json_encode( array( 'release_id' => 'candidate-x', 'version' => '9.9.9' ) ) . "\n" );
file_put_contents( $mu . '/hal-frontend-dashboard/state/previous.json', json_encode( array( 'release_id' => $id1, 'version' => '1.0.0' ) ) . "\n" );
$rm8->restore_pointer_from_previous( 'active.json', 'previous.json' );
$restored = hal_rm_state( $mu, 'active.json' );
hal_rm_check( 'R8-RESTORE', $id1 === ( $restored['release_id'] ?? null ), 'active restored from previous', 'got: ' . json_encode( $restored ) );
file_put_contents( $mu . '/hal-frontend-dashboard/state/promotion.json', json_encode( array( 'candidate' => 'x', 'operation' => 'op-live', 'deadline' => time() + 300 ) ) . "\n" );
$wrong_op = $rm8->finalize_record( 'promotion.json', 'op-stale' );
$still_there = is_file( $mu . '/hal-frontend-dashboard/state/promotion.json' );
$right_op = $rm8->finalize_record( 'promotion.json', 'op-live' );
$gone_now = ! is_file( $mu . '/hal-frontend-dashboard/state/promotion.json' );
$missing = $rm8->finalize_record( 'promotion.json', 'op-live' );
hal_rm_check(
	'R8-FINALIZE',
	false === $wrong_op && $still_there && true === $right_op && $gone_now && false === $missing,
	'record deletion binds to the owning operation; missing record returns false',
	"wrong=$wrong_op still=$still_there right=$right_op gone=$gone_now missing=$missing"
);

/* R9 — lock metadata cleared after the transition (no stale owner). */
$rm9 = new HAL_Frontend_Dashboard_Release_Manager( $mu );
$rm9->with_lock( static function (): void {}, 30 );
$lock_bytes = file_get_contents( $mu . '/hal-frontend-dashboard/state/update.lock' );
hal_rm_check( 'R9-LOCK-CLEAN', '' === $lock_bytes, 'update.lock metadata cleared after transition', 'bytes: ' . var_export( $lock_bytes, true ) );

/* R10 — closure gate: old→new update simulation with rollback-to-previous. */
$mu2 = $ws . '/mu-update-sim';
@mkdir( $mu2, 0777, true );
$sim = new HAL_Frontend_Dashboard_Release_Manager( $mu2 );
$sim->ensure_layout();
hal_rm_make_loader( $mu2, 'loader-1.0.0' );
$v1 = '3.0.0+' . str_repeat( 'a', 40 );
$v2 = '3.1.0+' . str_repeat( 'b', 40 );
$v3 = '3.2.0+' . str_repeat( 'c', 40 );
hal_rm_make_release( $mu2, $v1 );
hal_rm_make_release( $mu2, $v2 );
hal_rm_make_release( $mu2, $v3 );
$sim->promote_runtime( hal_rm_manifest( $v1, '3.0.0', 10, str_repeat( '1', 64 ) ), static fn(): bool => true, 'loader-1.0.0' );
$sim->promote_runtime( hal_rm_manifest( $v2, '3.1.0', 11, str_repeat( '2', 64 ) ), static fn(): bool => true, 'loader-1.0.0' );
$mid_active = hal_rm_state( $mu2, 'active.json' );
$mid_previous = hal_rm_state( $mu2, 'previous.json' );
$code = hal_rm_code( static function () use ( $mu2, $v3 ): void {
	( new HAL_Frontend_Dashboard_Release_Manager( $mu2 ) )->promote_runtime(
		hal_rm_manifest( $v3, '3.2.0', 12, str_repeat( '3', 64 ) ),
		static fn(): bool => false,
		'loader-1.0.0'
	);
} );
$end_active = hal_rm_state( $mu2, 'active.json' );
$end_previous = hal_rm_state( $mu2, 'previous.json' );
$end_committed = hal_rm_state( $mu2, 'committed.json' );
hal_rm_check(
	'R10-UPDATE-SIM',
	$v2 === ( $mid_active['release_id'] ?? null ) && $v1 === ( $mid_previous['release_id'] ?? null )
		&& 'HAL_PROMOTION_HEALTH_FAILED' === $code
		&& $v2 === ( $end_active['release_id'] ?? null ) && $v2 === ( $end_previous['release_id'] ?? null )
		&& $v2 === ( $end_committed['release_id'] ?? null )
		&& is_dir( $mu2 . '/hal-frontend-dashboard/releases/' . $v1 )
		&& is_dir( $mu2 . '/hal-frontend-dashboard/releases/' . $v2 )
		&& is_dir( $mu2 . '/hal-frontend-dashboard/releases/' . $v3 ),
	'v1→v2 promoted, failed v3 rolled back to previous (v2); all immutable trees retained for forensics',
	'code: ' . $code . ' active=' . json_encode( $end_active )
);

$pass = 0;
foreach ( $GLOBALS['HAL_RM_RESULTS'] as $result ) {
	if ( $result['ok'] ) {
		$pass++;
	}
}
$total = count( $GLOBALS['HAL_RM_RESULTS'] );
echo "RESULT: $pass/$total checks passed\n";
if ( $pass === $total ) {
	hal_rm_remove_dir( $ws );
}
exit( $pass === $total ? 0 : 1 );
