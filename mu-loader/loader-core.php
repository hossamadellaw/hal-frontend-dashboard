<?php
/**
 * Versioned HAL loader core.
 *
 * @package HAL_Frontend_Dashboard
 */

defined( 'ABSPATH' ) || exit;

// B1-09: حارس تحميل مكرر — إعادة inclusion تنهي فورًا قبل إعادة تعريف
// الثوابت أو تسجيل shutdown callbacks مكررة.
if ( defined( 'HAL_FRONTEND_DASHBOARD_LOADER_LOADED' ) ) {
	return;
}
define( 'HAL_FRONTEND_DASHBOARD_LOADER_LOADED', true );

define( 'HAL_FRONTEND_DASHBOARD_LOADER_API', 1 );
define( 'HAL_FRONTEND_DASHBOARD_LOADER_VERSION', '1.0.0' );
$hal_loader_id = basename( __DIR__ );
if ( 1 !== preg_match( '/\Aloader-[0-9]+\.[0-9]+\.[0-9]+\z/D', $hal_loader_id ) ) {
	return;
}
define( 'HAL_FRONTEND_DASHBOARD_LOADER_ID', $hal_loader_id );

$hal_root = dirname( __DIR__, 2 );
$hal_state = $hal_root . DIRECTORY_SEPARATOR . 'state';
$hal_releases = $hal_root . DIRECTORY_SEPARATOR . 'releases';

$hal_read_state = static function ( string $name ) use ( $hal_state ): ?array {
	$path = $hal_state . DIRECTORY_SEPARATOR . $name;
	if ( ! is_file( $path ) || is_link( $path ) ) {
		return null;
	}
	try {
		$value = json_decode( (string) file_get_contents( $path ), true, 8, JSON_THROW_ON_ERROR );
		return is_array( $value ) ? $value : null;
	} catch ( Throwable $failure ) {
		return null;
	}
};

$hal_resolve_release = static function ( ?array $pointer ) use ( $hal_releases ): ?array {
	$id = $pointer['release_id'] ?? '';
	$version = $pointer['version'] ?? '';
	if ( ! is_string( $id ) || ! is_string( $version ) || 1 !== preg_match( '/\A[0-9]+\.[0-9]+\.[0-9]+\+[a-f0-9]{40}\z/D', $id ) || 1 !== preg_match( '/\A[0-9]+\.[0-9]+\.[0-9]+\z/D', $version ) ) {
		return null;
	}
	$root = realpath( $hal_releases );
	$release = realpath( $hal_releases . DIRECTORY_SEPARATOR . $id );
	$bootstrap = false !== $release ? realpath( $release . DIRECTORY_SEPARATOR . 'bootstrap.php' ) : false;
	if ( false === $root || false === $release || false === $bootstrap || is_link( $bootstrap ) || ! str_starts_with( $release, rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR ) || ! str_starts_with( $bootstrap, rtrim( $release, '/\\' ) . DIRECTORY_SEPARATOR ) ) {
		return null;
	}
	return array( 'id' => $id, 'version' => $version, 'root' => $release, 'bootstrap' => $bootstrap );
};

/**
 * B1-02 recovery, embedded in the shipped loader-core itself: takes the
 * update lock, re-reads the promotion record UNDER the lock, rolls back
 * only an expired record with verified file-operation results, and keeps
 * the record for a later retry when any step fails.
 */
$hal_runtime_recovery = static function ( string $promotion_path, string $active_path, string $previous_path ): bool {
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
			$temp = $state_dir . DIRECTORY_SEPARATOR . '.hal-runtime-rollback-' . bin2hex( random_bytes( 8 ) ) . '.tmp';
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

$hal_active_path = $hal_state . DIRECTORY_SEPARATOR . 'active.json';
$hal_previous_path = $hal_state . DIRECTORY_SEPARATOR . 'previous.json';
$hal_promotion_path = $hal_state . DIRECTORY_SEPARATOR . 'promotion.json';

$hal_promotion = $hal_read_state( 'promotion.json' );
if ( is_array( $hal_promotion ) && time() > (int) ( $hal_promotion['deadline'] ?? 0 ) ) {
	$hal_runtime_recovery( $hal_promotion_path, $hal_active_path, $hal_previous_path );
}

$hal_release = $hal_resolve_release( $hal_read_state( 'active.json' ) );
if ( null === $hal_release ) {
	$hal_release = $hal_resolve_release( $hal_read_state( 'previous.json' ) );
}

if ( null !== $hal_release ) {
	define( 'HAL_FRONTEND_DASHBOARD_RELEASE_ID', $hal_release['id'] );
	define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION', $hal_release['version'] );
	define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_ROOT', $hal_release['root'] . DIRECTORY_SEPARATOR );
	define( 'HAL_FRONTEND_DASHBOARD_RUNTIME_URL', content_url( 'mu-plugins/hal-frontend-dashboard/releases/' . rawurlencode( $hal_release['id'] ) . '/' ) );

	$hal_ready = false;
	add_action( 'hal_frontend_dashboard_runtime_ready', static function () use ( &$hal_ready ): void { $hal_ready = true; }, PHP_INT_MAX );
	register_shutdown_function(
		static function () use ( &$hal_ready, $hal_runtime_recovery, $hal_promotion_path, $hal_active_path, $hal_previous_path ): void {
			if ( $hal_ready || ! is_file( $hal_promotion_path ) ) {
				return;
			}
			// B1-02: shutdown rollback بنفس مسار recovery المقفل.
			$hal_runtime_recovery( $hal_promotion_path, $hal_active_path, $hal_previous_path );
		}
	);
	require $hal_release['bootstrap'];
}

unset(
	$hal_loader_id, $hal_root, $hal_state, $hal_releases, $hal_read_state, $hal_resolve_release,
	$hal_runtime_recovery, $hal_active_path, $hal_previous_path, $hal_promotion_path, $hal_promotion,
	$hal_temp, $hal_release, $hal_ready
);
