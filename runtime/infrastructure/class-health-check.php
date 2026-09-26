<?php
/**
 * One-use loopback health acknowledgement.
 *
 * @package HAL_Frontend_Dashboard
 */

defined( 'ABSPATH' ) || exit;

final class HAL_Frontend_Dashboard_Health_Check {
	private const ACTION = 'hal_frontend_dashboard_boot_health';
	private const MAX_RESPONSE_BYTES = 65536;
	private string $state_directory;

	public function __construct( ?string $mu_plugins_dir = null ) {
		$base = $mu_plugins_dir ?? ( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : '' );
		if ( '' === $base ) {
			throw new RuntimeException( 'HAL_HEALTH_STATE_UNAVAILABLE' );
		}
		$this->state_directory = rtrim( $base, '/\\' ) . DIRECTORY_SEPARATOR . 'hal-frontend-dashboard' . DIRECTORY_SEPARATOR . 'state';
	}

	public static function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( self::class, 'handle_request' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( self::class, 'handle_request' ) );
	}

	public function check( string $operation, string $release_id, string $version, int $deadline, string $loader_id ): bool {
		if ( time() >= $deadline ) {
			return false;
		}
		$token = bin2hex( random_bytes( 32 ) );
		$this->write_challenge(
			array(
				'token_hash' => hash( 'sha256', $token ),
				'operation'  => $operation,
				'release_id' => $release_id,
				'version'    => $version,
				'loader_id'  => $loader_id,
				'deadline'   => $deadline,
			)
		);

		// B1-08 / U §13 + O §2.4: loopback يبنى من home_url()/المسار الثابت
		// بلا redirects أو cookies، مع إلزام HTTPS وsslverify=true وحد
		// صريح لحجم الاستجابة — token لا يسافر عبر نص صافٍ أبدًا.
		$url = admin_url( 'admin-ajax.php' );
		if ( 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
			return false;
		}
		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => max( 1, min( 20, $deadline - time() ) ),
				'redirection' => 0,
				'sslverify'   => true,
				'cookies'     => array(),
				'body'        => array(
					'action'    => self::ACTION,
					'token'     => $token,
					'operation' => $operation,
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}
		$body_raw = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body_raw ) > self::MAX_RESPONSE_BYTES ) {
			return false;
		}
		try {
			$body = json_decode( $body_raw, true, 8, JSON_THROW_ON_ERROR );
		} catch ( JsonException $exception ) {
			return false;
		}
		return is_array( $body )
			&& true === ( $body['success'] ?? false )
			&& true === ( $body['ready'] ?? false )
			&& hash_equals( $operation, (string) ( $body['operation'] ?? '' ) )
			&& hash_equals( $release_id, (string) ( $body['release_id'] ?? '' ) )
			&& hash_equals( $version, (string) ( $body['version'] ?? '' ) )
			&& hash_equals( $loader_id, (string) ( $body['loader_id'] ?? '' ) );
	}

	public static function handle_request(): void {
		$health = new self();
		$input = array(
			'token'      => isset( $_POST['token'] ) ? (string) wp_unslash( $_POST['token'] ) : '',
			'operation'  => isset( $_POST['operation'] ) ? (string) wp_unslash( $_POST['operation'] ) : '',
		);
		$observed_release = defined( 'HAL_FRONTEND_DASHBOARD_RELEASE_ID' ) ? HAL_FRONTEND_DASHBOARD_RELEASE_ID : '';
		$observed_version = defined( 'HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION' ) ? HAL_FRONTEND_DASHBOARD_RUNTIME_VERSION : '';
		$observed = array(
			'release_id' => $observed_release,
			'version'    => $observed_version,
			'loader_id'  => defined( 'HAL_FRONTEND_DASHBOARD_LOADER_ID' ) ? HAL_FRONTEND_DASHBOARD_LOADER_ID : '',
			'loader_api' => defined( 'HAL_FRONTEND_DASHBOARD_LOADER_API' ) ? HAL_FRONTEND_DASHBOARD_LOADER_API : 0,
			'ready'      => did_action( 'hal_frontend_dashboard_runtime_ready' ) > 0
				&& true === apply_filters( 'hal_frontend_dashboard_boot_readiness', false, $observed_release, $observed_version ),
		);
		$accepted = $health->consume( $input, $observed );
		if ( ! $accepted ) {
			wp_send_json_error( array( 'code' => 'HAL_HEALTH_ACK_REJECTED' ), 403 );
		}
		wp_send_json(
			array(
				'success'    => true,
				'ready'      => true,
				'operation'  => $input['operation'],
				'release_id' => $observed['release_id'],
				'version'    => $observed['version'],
				'loader_id'  => $observed['loader_id'],
			)
		);
	}

	public function consume( array $input, array $observed ): bool {
		if ( ! is_dir( $this->state_directory ) || is_link( $this->state_directory ) ) {
			return false;
		}
		if (
			1 !== preg_match( '/\A[a-f0-9]{64}\z/D', (string) ( $input['token'] ?? '' ) )
			|| 1 !== preg_match( '/\A[a-f0-9]{32}\z/D', (string) ( $input['operation'] ?? '' ) )
		) {
			return false;
		}
		$lock = @fopen( $this->state_directory . DIRECTORY_SEPARATOR . 'health.lock', 'c+b' );
		if ( false === $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			is_resource( $lock ) && fclose( $lock );
			return false;
		}

		$challenge_path = $this->state_directory . DIRECTORY_SEPARATOR . 'health-challenge-' . $input['operation'] . '.json';
		try {
			$raw = is_file( $challenge_path ) ? file_get_contents( $challenge_path ) : false;
			if ( false === $raw ) {
				return false;
			}
			try {
				$challenge = json_decode( $raw, true, 8, JSON_THROW_ON_ERROR );
			} catch ( JsonException $exception ) {
				return false;
			}
			$valid = is_array( $challenge )
				&& true === ( $observed['ready'] ?? false )
				&& 1 === ( $observed['loader_api'] ?? 0 )
				&& time() <= (int) ( $challenge['deadline'] ?? 0 )
				&& hash_equals( (string) ( $challenge['token_hash'] ?? '' ), hash( 'sha256', (string) ( $input['token'] ?? '' ) ) )
				&& hash_equals( (string) ( $challenge['operation'] ?? '' ), (string) ( $input['operation'] ?? '' ) )
				&& hash_equals( (string) ( $challenge['release_id'] ?? '' ), (string) ( $observed['release_id'] ?? '' ) )
				&& hash_equals( (string) ( $challenge['version'] ?? '' ), (string) ( $observed['version'] ?? '' ) )
				&& hash_equals( (string) ( $challenge['loader_id'] ?? '' ), (string) ( $observed['loader_id'] ?? '' ) );
			if ( $valid && ! unlink( $challenge_path ) ) {
				return false;
			}
			return $valid;
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	private function write_challenge( array $challenge ): void {
		if ( ! is_dir( $this->state_directory ) || is_link( $this->state_directory ) ) {
			throw new RuntimeException( 'HAL_HEALTH_STATE_UNAVAILABLE' );
		}
		$path = $this->state_directory . DIRECTORY_SEPARATOR . 'health-challenge-' . $challenge['operation'] . '.json';
		$temp = $this->state_directory . DIRECTORY_SEPARATOR . '.hal-health-' . bin2hex( random_bytes( 12 ) ) . '.tmp';
		$json = json_encode( $challenge, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
		$handle = @fopen( $temp, 'xb' );
		if ( false === $handle ) {
			throw new RuntimeException( 'HAL_HEALTH_CHALLENGE_CREATE_FAILED' );
		}
		try {
			if ( strlen( $json ) !== fwrite( $handle, $json ) || ! fflush( $handle ) ) {
				throw new RuntimeException( 'HAL_HEALTH_CHALLENGE_WRITE_FAILED' );
			}
			function_exists( 'fsync' ) && fsync( $handle );
		} finally {
			fclose( $handle );
		}
		if ( ! @rename( $temp, $path ) ) {
			@unlink( $temp );
			throw new RuntimeException( 'HAL_HEALTH_CHALLENGE_ATOMIC_RENAME_FAILED' );
		}
	}
}
