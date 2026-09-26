<?php
/**
 * Plugin Name: HAL Frontend Dashboard Loader
 * Description: Immutable root anchor for the versioned HAL loader.
 */

defined( 'ABSPATH' ) || exit;

// B1-09: إعادة inclusion تعيد تعريف الثوابت وتسجل callbacks مكررة؛
// الحارس الداخلي ينهي التحميل الثاني فورًا بلا أي أثر.
if ( defined( 'HAL_FRONTEND_DASHBOARD_ANCHOR_LOADED' ) ) {
	return;
}
define( 'HAL_FRONTEND_DASHBOARD_ANCHOR_LOADED', true );

$hal_mu_root = __DIR__ . DIRECTORY_SEPARATOR . 'hal-frontend-dashboard';
$hal_state = $hal_mu_root . DIRECTORY_SEPARATOR . 'state';
$hal_loader_root = $hal_mu_root . DIRECTORY_SEPARATOR . 'loader-releases';

$hal_read_pointer = static function ( string $path, string $field ): ?array {
	if ( ! is_file( $path ) || is_link( $path ) ) {
		return null;
	}
	try {
		$value = json_decode( (string) file_get_contents( $path ), true, 8, JSON_THROW_ON_ERROR );
	} catch ( Throwable $failure ) {
		return null;
	}
	if ( ! is_array( $value ) || ! isset( $value[ $field ] ) || ! is_string( $value[ $field ] ) ) {
		return null;
	}
	return $value;
};

$hal_resolve_loader = static function ( ?array $pointer ) use ( $hal_loader_root ): ?string {
	$id = $pointer['loader_id'] ?? '';
	if ( ! is_string( $id ) || 1 !== preg_match( '/\Aloader-[0-9]+\.[0-9]+\.[0-9]+\z/D', $id ) ) {
		return null;
	}
	$root = realpath( $hal_loader_root );
	$file = realpath( $hal_loader_root . DIRECTORY_SEPARATOR . $id . DIRECTORY_SEPARATOR . 'loader-core.php' );
	if ( false === $root || false === $file || is_link( $file ) || ! str_starts_with( $file, rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR ) ) {
		return null;
	}
	return $file;
};

/**
 * B1-02 recovery, embedded in the anchor itself (the shipped artifact —
 * no Carrier code and no state/ copies in normal requests). It takes the
 * update lock, re-reads the promotion record UNDER the lock, and only
 * rolls back when the record bytes still name an expired promotion:
 * a live promotion owned by a concurrent process is never touched.
 */
$hal_loader_recovery = static function ( string $promotion_path, string $active_path, string $previous_path ): bool {
	if ( ! is_file( $promotion_path ) || is_link( $promotion_path ) ) {
		return false;
	}
	$state_dir = dirname( $promotion_path );
	$lock_handle = @fopen( $state_dir . DIRECTORY_SEPARATOR . 'update.lock', 'c+b' );
	if ( false === $lock_handle || ! flock( $lock_handle, LOCK_EX | LOCK_NB ) ) {
		is_resource( $lock_handle ) && fclose( $lock_handle );
		return false;
	}
	try {
		$record = null;
		if ( is_file( $promotion_path ) && ! is_link( $promotion_path ) ) {
			try {
				$record = json_decode( (string) file_get_contents( $promotion_path ), true, 8, JSON_THROW_ON_ERROR );
			} catch ( Throwable $failure ) {
				$record = null;
			}
		}
		if ( ! is_array( $record ) || time() <= (int) ( $record['deadline'] ?? 0 ) ) {
			return false;
		}
		// Rollback under the lock with verified results; any failed step
		// keeps the record so a later request can retry the recovery.
		if ( is_file( $previous_path ) && ! is_link( $previous_path ) ) {
			$previous = null;
			try {
				$previous = json_decode( (string) file_get_contents( $previous_path ), true, 8, JSON_THROW_ON_ERROR );
			} catch ( Throwable $failure ) {
				$previous = null;
			}
			if ( null === $previous ) {
				return false;
			}
			$temp = $state_dir . DIRECTORY_SEPARATOR . '.hal-loader-rollback-' . bin2hex( random_bytes( 8 ) ) . '.tmp';
			if ( ! file_put_contents( $temp, json_encode( $previous, JSON_UNESCAPED_SLASHES ) . "\n" ) ) {
				@unlink( $temp );
				return false;
			}
			if ( ! @rename( $temp, $active_path ) ) {
				@unlink( $temp );
				return false;
			}
		} elseif ( is_file( $active_path ) ) {
			if ( ! @unlink( $active_path ) ) {
				return false;
			}
		}
		if ( ! @unlink( $promotion_path ) ) {
			return false;
		}
		return true;
	} finally {
		flock( $lock_handle, LOCK_UN );
		fclose( $lock_handle );
	}
};

$hal_active_path = $hal_state . DIRECTORY_SEPARATOR . 'loader-active.json';
$hal_previous_path = $hal_state . DIRECTORY_SEPARATOR . 'loader-previous.json';
$hal_promotion_path = $hal_state . DIRECTORY_SEPARATOR . 'loader-promotion.json';

// معالجة سجل promotion منتهٍ لloader المرشح قبل اختيار المؤشر.
$hal_promotion = $hal_read_pointer( $hal_promotion_path, 'candidate' );
if ( is_array( $hal_promotion ) && time() > (int) ( $hal_promotion['deadline'] ?? 0 ) ) {
	$hal_loader_recovery( $hal_promotion_path, $hal_active_path, $hal_previous_path );
}

$hal_pointer = $hal_read_pointer( $hal_active_path, 'loader_id' );
$hal_loader = $hal_resolve_loader( $hal_pointer );
if ( null === $hal_loader ) {
	$hal_loader = $hal_resolve_loader( $hal_read_pointer( $hal_previous_path, 'loader_id' ) );
}

if ( null !== $hal_loader ) {
	$hal_ready = false;
	add_action( 'hal_frontend_dashboard_runtime_ready', static function () use ( &$hal_ready ): void { $hal_ready = true; }, PHP_INT_MAX );
	register_shutdown_function(
		static function () use ( &$hal_ready, $hal_loader_recovery, $hal_promotion_path, $hal_active_path, $hal_previous_path ): void {
			if ( $hal_ready || ! is_file( $hal_promotion_path ) ) {
				return;
			}
			// B1-02: shutdown rollback يمر بنفس مسار recovery المقفل —
			// سجل حي غير منتهٍ يبقى لعملية التحديث، والمنتهي يُرجع previous.
			$hal_loader_recovery( $hal_promotion_path, $hal_active_path, $hal_previous_path );
		}
	);
	require $hal_loader;
}

unset(
	$hal_mu_root, $hal_state, $hal_loader_root, $hal_read_pointer, $hal_resolve_loader,
	$hal_loader_recovery, $hal_active_path, $hal_previous_path, $hal_promotion_path,
	$hal_promotion, $hal_pointer, $hal_loader, $hal_ready
);
