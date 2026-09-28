<?php
/**
 * Owns immutable releases, state pointers, and the update lock.
 *
 * @package HAL_Frontend_Dashboard
 */

defined( 'ABSPATH' ) || exit;

final class HAL_Frontend_Dashboard_Release_Manager {
	private string $root;
	private string $state;
	private string $releases;
	private string $loader_releases;

	public function __construct( ?string $mu_plugins_dir = null ) {
		$base = $mu_plugins_dir ?? ( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : '' );
		if ( '' === $base ) {
			self::fail( 'HAL_MU_PLUGIN_DIR_UNAVAILABLE' );
		}
		$this->root = rtrim( $base, '/\\' ) . DIRECTORY_SEPARATOR . 'hal-frontend-dashboard';
		$this->state = $this->root . DIRECTORY_SEPARATOR . 'state';
		$this->releases = $this->root . DIRECTORY_SEPARATOR . 'releases';
		$this->loader_releases = $this->root . DIRECTORY_SEPARATOR . 'loader-releases';
	}

	public function ensure_layout(): void {
		foreach ( array( $this->root, $this->state, $this->releases, $this->loader_releases ) as $directory ) {
			$this->ensure_directory( $directory );
		}
	}

	public function with_lock( callable $transition, int $ttl = 120 ): mixed {
		$this->ensure_layout();
		$lock_path = $this->state . DIRECTORY_SEPARATOR . 'update.lock';
		$handle = @fopen( $lock_path, 'c+b' );
		if ( false === $handle || ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			is_resource( $handle ) && fclose( $handle );
			self::fail( 'HAL_UPDATE_LOCK_BUSY' );
		}

		$operation = bin2hex( random_bytes( 16 ) );
		$metadata = array(
			'operation' => $operation,
			'owner'      => getmypid() ?: 0,
			'created_at' => time(),
			'deadline'   => time() + max( 30, $ttl ),
		);

		try {
			$this->write_locked_metadata( $handle, $metadata );
			return $transition( $this, $operation, $metadata['deadline'] );
		} finally {
			$this->write_locked_metadata( $handle, array() );
			flock( $handle, LOCK_UN );
			fclose( $handle );
		}
	}

	/**
	 * Shared state-pointer writer used by recovery paths: replaces the
	 * active pointer from previous, or removes it on first install. Runs
	 * only under the update lock held by the caller.
	 */
	public function restore_pointer_from_previous( string $active_filename, string $previous_filename ): void {
		$pointer_names = array( 'active.json', 'loader-active.json' );
		if ( ! in_array( $active_filename, $pointer_names, true ) || ! in_array( $previous_filename, array( 'previous.json', 'loader-previous.json' ), true ) ) {
			self::fail( 'HAL_STATE_POINTER_NAME_INVALID' );
		}
		$active_path = $this->state_path( $active_filename );
		$previous = $this->read_pointer( $previous_filename );
		if ( null !== $previous ) {
			$this->write_json_atomic( $active_path, $previous );
		} elseif ( is_file( $active_path ) ) {
			if ( ! unlink( $active_path ) ) {
				self::fail( 'HAL_RECOVERY_POINTER_WRITE_FAILED' );
			}
		}
	}

	/**
	 * Deletes a promotion record if it still belongs to the given
	 * operation, under the update lock. Returns false when the record is
	 * missing or owned by a different (newer) operation, so a stale
	 * request never cancels a live promotion.
	 */
	public function finalize_record( string $record_filename, ?string $operation ): bool {
		if ( basename( $record_filename ) !== $record_filename || '' === $record_filename ) {
			self::fail( 'HAL_STATE_FILENAME_INVALID' );
		}
		$deleted = false;
		$this->with_lock( function ( self $manager ) use ( $record_filename, $operation, &$deleted ): void {
			$record_path = $manager->state_path( $record_filename );
			if ( ! is_file( $record_path ) ) {
				return;
			}
			$record = $manager->read_state_json( $record_filename );
			if ( is_array( $record ) && null !== $operation && ( $record['operation'] ?? null ) !== $operation ) {
				return;
			}
			if ( ! unlink( $record_path ) ) {
				self::fail( 'HAL_RECOVERY_RECORD_DELETE_FAILED' );
			}
			$deleted = true;
		}, 30 );
		return $deleted;
	}

	public function install_release( string $release_id, string $staged_directory ): string {
		$this->assert_release_id( $release_id );
		$destination = $this->release_path( $release_id );
		if ( file_exists( $destination ) || is_link( $destination ) ) {
			self::fail( 'HAL_RELEASE_ALREADY_EXISTS' );
		}
		$this->assert_clean_directory( $staged_directory );
		if ( ! @rename( $staged_directory, $destination ) ) {
			self::fail( 'HAL_RELEASE_INSTALL_RENAME_FAILED' );
		}
		return $destination;
	}

	public function install_loader( string $loader_id, string $source_file ): string {
		$this->assert_release_id( $loader_id );
		if ( ! is_file( $source_file ) || is_link( $source_file ) ) {
			self::fail( 'HAL_LOADER_SOURCE_INVALID' );
		}
		$directory = $this->loader_path( $loader_id );
		if ( file_exists( $directory ) || is_link( $directory ) ) {
			self::fail( 'HAL_LOADER_ALREADY_EXISTS' );
		}
		$staging = $this->loader_releases . DIRECTORY_SEPARATOR . '.hal-loader-' . bin2hex( random_bytes( 12 ) );
		$this->ensure_directory( $staging );
		$target = $staging . DIRECTORY_SEPARATOR . 'loader-core.php';
		if ( ! copy( $source_file, $target ) || ! hash_equals( hash_file( 'sha256', $source_file ) ?: '', hash_file( 'sha256', $target ) ?: '' ) ) {
			self::fail( 'HAL_LOADER_INSTALL_FAILED' );
		}
		@chmod( $target, $this->file_mode() );
		if ( ! @rename( $staging, $directory ) ) {
			self::fail( 'HAL_LOADER_INSTALL_RENAME_FAILED' );
		}
		return $directory;
	}

	public function promote_runtime( array $manifest, callable $health_check, string $loader_id ): void {
		$release_id = $manifest['release_id'] ?? '';
		$version = $manifest['version'] ?? '';
		$sequence = $manifest['release_sequence'] ?? 0;
		$archive_hash = $manifest['archive_sha256'] ?? '';
		$this->assert_release_id( $release_id );
		$this->assert_release_id( $loader_id );
		if ( ! is_string( $version ) || ! is_int( $sequence ) || $sequence < 1 || ! is_string( $archive_hash ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $archive_hash ) ) {
			self::fail( 'HAL_PROMOTION_MANIFEST_INVALID' );
		}
		if ( ! is_file( $this->release_path( $release_id ) . DIRECTORY_SEPARATOR . 'bootstrap.php' ) ) {
			self::fail( 'HAL_RELEASE_INCOMPLETE' );
		}
		if ( ! is_file( $this->loader_path( $loader_id ) . DIRECTORY_SEPARATOR . 'loader-core.php' ) ) {
			self::fail( 'HAL_LOADER_INCOMPLETE' );
		}

		$this->with_lock( function ( self $manager, string $operation, int $deadline ) use ( $manifest, $release_id, $version, $sequence, $archive_hash, $health_check, $loader_id ): void {
			$failed_state = $manager->read_state_json( 'failed-digests.json' );
			$failed_digests = is_array( $failed_state['digests'] ?? null ) ? $failed_state['digests'] : array();
			if ( isset( $failed_digests[ $archive_hash ] ) ) {
				self::fail( 'HAL_PROMOTION_FAILED_DIGEST_REJECTED' );
			}
				$loader_active = $manager->read_pointer( 'loader-active.json' );
				$committed = $manager->read_state_json( 'committed.json' );
				if ( null !== $committed ) {
					$same = $release_id === ( $committed['release_id'] ?? null ) && $archive_hash === ( $committed['archive_sha256'] ?? null );
					if ( $same && $loader_id === ( $loader_active['loader_id'] ?? null ) ) {
						$active_now = $manager->read_pointer( 'active.json' );
						if ( is_array( $active_now ) && $release_id === ( $active_now['release_id'] ?? null ) ) {
							return;
						}
						// committed موجود لكن active لا يطابقه (مثل انقطاع قبل
						// إتمام التحويل): لا يعد idempotent — تكمل العملية.
					}
					if ( ! $same && ( ! isset( $committed['version'], $committed['release_sequence'] ) || ! is_int( $committed['release_sequence'] ) || ! version_compare( $version, (string) $committed['version'], '>' ) || $sequence <= $committed['release_sequence'] ) ) {
						self::fail( 'HAL_PROMOTION_REPLAY_REJECTED' );
					}
				}
			$active = $manager->read_pointer( 'active.json' );
			$previous = $active;
			// مصالحة ترويج مقطوع (نافذة W1): active يشير أصلًا إلى المرشح
			// بينما committed ما زال يسمي آخر إصدار معتمد كاملًا. هدف
			// الرجوع هو ذلك الإصدار المعتمد — لا المرشح المؤقت الذي يشير
			// إليه active — وإلا أدّى فشل health في المصالحة إلى "رجوع"
			// زائف إلى المرشح نفسه ودنس previous.json به.
			if (
				is_array( $active )
				&& $release_id === ( $active['release_id'] ?? null )
				&& null !== $committed
				&& $release_id !== ( $committed['release_id'] ?? null )
			) {
				$previous = array(
					'release_id' => (string) $committed['release_id'],
					'version'    => (string) ( $committed['version'] ?? '' ),
				);
			}
			$loader_previous = $loader_active;
			$loader_changed = $loader_id !== ( $loader_active['loader_id'] ?? null );
			$loader_committed = false;
			$record = array(
				'candidate' => $release_id,
				'previous'  => $previous['release_id'] ?? null,
				'loader'    => $loader_id,
				'operation' => $operation,
				'deadline'  => $deadline,
			);
			try {
			if ( $loader_changed ) {
				$manager->write_json_atomic(
					$manager->state_path( 'loader-promotion.json' ),
					array( 'candidate' => $loader_id, 'previous' => $loader_previous['loader_id'] ?? null, 'operation' => $operation, 'deadline' => $deadline )
				);
				if ( null !== $loader_previous ) {
					$manager->write_json_atomic( $manager->state_path( 'loader-previous.json' ), $loader_previous );
				}
				$manager->write_json_atomic( $manager->state_path( 'loader-active.json' ), array( 'loader_id' => $loader_id ) );
				if ( null !== $previous ) {
					$manager->record_status( 'loader_pending_health', array( 'loader_id' => $loader_id, 'runtime_release_id' => $previous['release_id'], 'time' => time() ) );
					if ( true !== $health_check( $operation, $previous['release_id'], $previous['version'], $deadline, $loader_id ) ) {
						self::fail( 'HAL_LOADER_PROMOTION_HEALTH_FAILED' );
					}
					$loader_promotion_path = $manager->state_path( 'loader-promotion.json' );
					if ( is_file( $loader_promotion_path ) && ! unlink( $loader_promotion_path ) ) {
						self::fail( 'HAL_LOADER_PROMOTION_COMMIT_FAILED' );
					}
					$loader_committed = true;
					$manager->record_status( 'loader_staged', array( 'loader_id' => $loader_id, 'runtime_release_id' => $previous['release_id'], 'time' => time() ) );
				}
			}
			$manager->record_status( 'pending_health', array( 'release_id' => $release_id, 'archive_sha256' => $archive_hash, 'operation' => $operation, 'time' => time() ) );
			$manager->write_json_atomic( $manager->state_path( 'promotion.json' ), $record );
			if ( null !== $previous ) {
				$manager->write_json_atomic( $manager->state_path( 'previous.json' ), $previous );
			}
			$manager->write_json_atomic( $manager->state_path( 'active.json' ), array( 'release_id' => $release_id, 'version' => $version ) );

				if ( true !== $health_check( $operation, $release_id, $version, $deadline, $loader_id ) ) {
					self::fail( 'HAL_PROMOTION_HEALTH_FAILED' );
				}
				// committed يكتب أخيرًا فقط بعد اكتمال history وstatus وحذف
				// promotion — أي انقطاع قبله يترك active/previous صحيحين
				// وسجل promotion قائمًا ليعالجه recovery في request لاحق.
				$history = $manager->read_state_json( 'history.json' );
				$release_history = is_array( $history['releases'] ?? null ) ? $history['releases'] : array();
				$release_history = array_values( array_filter( $release_history, 'is_string' ) );
				$release_history = array_values( array_unique( array_merge( array( $release_id ), $release_history ) ) );
				$manager->write_json_atomic( $manager->state_path( 'history.json' ), array( 'releases' => $release_history ) );
				$manager->record_status( 'active', array( 'release_id' => $release_id, 'time' => time() ) );
				$promotion_record_path = $manager->state_path( 'promotion.json' );
				if ( is_file( $promotion_record_path ) ) {
					if ( ! unlink( $promotion_record_path ) ) {
						self::fail( 'HAL_RUNTIME_PROMOTION_RECORD_DELETE_FAILED' );
					}
				}
				if ( $loader_changed ) {
					$loader_record_path = $manager->state_path( 'loader-promotion.json' );
					if ( is_file( $loader_record_path ) ) {
						if ( ! unlink( $loader_record_path ) ) {
							self::fail( 'HAL_LOADER_PROMOTION_RECORD_DELETE_FAILED' );
						}
					}
				}
				$manager->write_json_atomic(
					$manager->state_path( 'committed.json' ),
					array(
						'release_id'       => $release_id,
						'version'          => $version,
						'release_sequence' => $sequence,
						'archive_sha256'   => $archive_hash,
					)
				);
			} catch ( Throwable $failure ) {
				if ( null !== $previous ) {
					$manager->write_json_atomic( $manager->state_path( 'active.json' ), $previous );
				} else {
					$active_path = $manager->state_path( 'active.json' );
					if ( is_file( $active_path ) && ! unlink( $active_path ) ) {
						self::fail( 'HAL_RUNTIME_FIRST_INSTALL_ROLLBACK_FAILED' );
					}
				}
				if ( $loader_changed && ! $loader_committed ) {
					if ( null !== $loader_previous ) {
						$manager->write_json_atomic( $manager->state_path( 'loader-active.json' ), $loader_previous );
					} else {
						$loader_active_path = $manager->state_path( 'loader-active.json' );
						if ( is_file( $loader_active_path ) && ! unlink( $loader_active_path ) ) {
							self::fail( 'HAL_LOADER_FIRST_INSTALL_ROLLBACK_FAILED' );
						}
					}
				}
				$manager->write_json_atomic(
					$manager->state_path( 'failed-digests.json' ),
					array(
						'digests' => array_merge(
							$failed_digests,
							array( $archive_hash => array( 'release_id' => $release_id, 'failure_code' => $failure->getMessage(), 'time' => time() ) )
						),
					)
				);
				// إزالة committed الخاص بالمرشح الفاشل حتى لا تسجَّل اعتمادية
				// زائفة لنسخة لم تكتمل (مثل فشل كتابة history بعد committed).
				$failed_committed = $manager->read_state_json( 'committed.json' );
				if ( is_array( $failed_committed ) && $release_id === ( $failed_committed['release_id'] ?? null ) ) {
					$committed_path = $manager->state_path( 'committed.json' );
					if ( is_file( $committed_path ) && ! unlink( $committed_path ) ) {
						self::fail( 'HAL_RUNTIME_PROMOTION_COMMIT_ROLLBACK_FAILED' );
					}
				}
				$manager->record_status( 'rolled_back', array( 'release_id' => $release_id, 'archive_sha256' => $archive_hash, 'failure_code' => $failure->getMessage(), 'time' => time() ) );
				throw $failure;
			}
		} );
	}

	/**
	 * B11-01: deferred Carrier→Runtime import consumer for the cron the
	 * update bridge schedules after a verified Carrier update. Consumes the
	 * candidate option, verifies the UPDATED Carrier tree plus its embedded
	 * payload with the real package verifier, stages/installs the Runtime
	 * and loader, then promotes through promote_runtime() (automatic
	 * rollback on health failure). Returns the promoted release_id, or ''
	 * when no candidate is pending. Throws RuntimeException (HAL_* code) on
	 * any verification/install failure; import_candidate_cron() converts
	 * those to a diagnosed status so the cron run itself never fatals.
	 *
	 * @param callable|null $health_check Real loopback check by default.
	 */
	public function import_candidate( $health_check = null ): string {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) || ! function_exists( 'delete_option' ) ) {
			self::fail( 'HAL_IMPORT_WP_UNAVAILABLE' );
		}
		$candidate = get_option( self::import_option_name() );
		if ( ! is_array( $candidate ) || self::import_plugin_basename() !== ( $candidate['plugin'] ?? null ) ) {
			return '';
		}
		// Item-1 gates: environment preflight BEFORE any write (layout,
		// staging, state), then lock ownership BEFORE any install or state
		// mutation. Verification between them stays lock-free (read-only).
		$this->import_preflight();
		$this->ensure_layout();
		$carrier_root = $this->updated_carrier_root();
		$verifier = $this->import_verifier();
		$carrier_manifest = $verifier->verify_carrier_tree(
			$carrier_root,
			$carrier_root . DIRECTORY_SEPARATOR . 'carrier-manifest.json',
			$carrier_root . DIRECTORY_SEPARATOR . 'carrier-manifest.sig'
		);
		$payload = $carrier_root . DIRECTORY_SEPARATOR . 'payload';
		$manifest = $this->read_signed_payload_manifest( $verifier, $payload );
		$this->assert_import_compatibility( $carrier_root, $manifest );
		if ( null === $health_check ) {
			if ( ! class_exists( 'HAL_Frontend_Dashboard_Health_Check', false ) ) {
				require_once __DIR__ . '/class-health-check.php';
			}
			$health = new HAL_Frontend_Dashboard_Health_Check();
			$health_check = static fn ( string $operation, string $candidate_release, string $candidate_version, int $deadline, string $candidate_loader ): bool => $health->check( $operation, $candidate_release, $candidate_version, $deadline, $candidate_loader );
		}
		$release_id = $manifest['release_id'];
		$this->with_lock(
			function ( self $manager ) use ( $verifier, $carrier_root, $payload, $manifest, $release_id ): void {
				$manager->record_status( 'carrier_updated', array( 'release_id' => $release_id, 'time' => time() ) );
				$release_path = $manager->release_path( $release_id );
				if ( is_link( $release_path ) ) {
					self::fail( 'HAL_EXISTING_RELEASE_LINK_REJECTED' );
				}
				if ( is_dir( $release_path ) ) {
					$verifier->verify_runtime_tree( $release_path, $manifest );
				} else {
					$archive = $payload . DIRECTORY_SEPARATOR . 'runtime-' . $manifest['version'] . '.zip';
					$staging = $manager->import_staging_path();
					try {
						$verifier->extract_runtime_archive( $archive, $staging, $manifest );
						$manager->install_release( $release_id, $staging );
					} catch ( Throwable $failure ) {
						$manager->remove_import_staging( $staging );
						throw $failure;
					}
					$manager->record_status( 'staged', array( 'release_id' => $release_id, 'time' => time() ) );
				}
				$loader_id = 'loader-' . $manifest['loader_core_version'];
				$loader_source = $carrier_root . DIRECTORY_SEPARATOR . 'mu-loader' . DIRECTORY_SEPARATOR . 'loader-core.php';
				$loader_target = $manager->loader_path( $loader_id ) . DIRECTORY_SEPARATOR . 'loader-core.php';
				if ( is_link( $loader_source ) || is_link( $loader_target ) ) {
					self::fail( 'HAL_LOADER_LINK_REJECTED' );
				}
				if ( is_file( $loader_target ) ) {
					if ( ! hash_equals( hash_file( 'sha256', $loader_source ) ?: '', hash_file( 'sha256', $loader_target ) ?: '' ) ) {
						self::fail( 'HAL_EXISTING_LOADER_MISMATCH' );
					}
				} else {
					$manager->install_loader( $loader_id, $loader_source );
				}
			},
			180
		);
		$this->promote_runtime( $manifest, $health_check, 'loader-' . $manifest['loader_core_version'] );
		delete_option( self::import_option_name() );
		return $release_id;
	}

	/**
	 * B11-01: cron entry point for the deferred import. Never fatals the
	 * cron run: environment/lock blocks are recorded as `blocked`,
	 * verification/install failures as `failed` (with promote recording
	 * `rolled_back` itself on health failure), while the candidate option
	 * is kept for forensics (a newer Carrier update overwrites it).
	 */
	public static function import_candidate_cron(): void {
		$manager = null;
		try {
			$manager = new self();
			$manager->import_candidate();
		} catch ( Throwable $failure ) {
			try {
				$manager = $manager ?? new self();
				// promote_runtime() already diagnoses its own health-leg
				// failures precisely as `rolled_back`; keying on the live
				// exception (not the state file) keeps a stale record from
				// a previous run from masking the current outcome.
				$code = $failure->getMessage();
				if ( 'HAL_PROMOTION_HEALTH_FAILED' === $code || 'HAL_LOADER_PROMOTION_HEALTH_FAILED' === $code ) {
					return;
				}
				$blocked = array(
					'HAL_UPDATE_LOCK_BUSY',
					'HAL_IMPORT_FILE_MODS_DISABLED',
					'HAL_IMPORT_MU_DIRECTORY_UNWRITABLE',
					'HAL_IMPORT_DIRECT_FILESYSTEM_REQUIRED',
					'HAL_IMPORT_MULTISITE_UNSUPPORTED',
					'HAL_IMPORT_MULTISITE_UNAUTHORIZED',
				);
				$manager->record_status(
					in_array( $code, $blocked, true ) ? 'blocked' : 'failed',
					array( 'failure_code' => $code, 'time' => time() )
				);
			} catch ( Throwable $ignored ) {
			}
		}
	}

	/**
	 * Item-1 environment preflight (installer preflight rules adapted to
	 * the deferred cron context): read-only, runs before ANY write.
	 */
	private function import_preflight(): void {
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			self::fail( 'HAL_IMPORT_FILE_MODS_DISABLED' );
		}
		if ( ! defined( 'WPMU_PLUGIN_DIR' ) || ( is_dir( WPMU_PLUGIN_DIR ) && ! is_writable( WPMU_PLUGIN_DIR ) ) || ( ! is_dir( WPMU_PLUGIN_DIR ) && ! is_writable( dirname( WPMU_PLUGIN_DIR ) ) ) ) {
			self::fail( 'HAL_IMPORT_MU_DIRECTORY_UNWRITABLE' );
		}
		if ( function_exists( 'get_filesystem_method' ) && 'direct' !== get_filesystem_method() ) {
			self::fail( 'HAL_IMPORT_DIRECT_FILESYSTEM_REQUIRED' );
		}
		// Simple Multisite (ODR §2.7): one shared release state for the
		// network. The import runs under the network capability: system
		// contexts (cron/CLI, no user) and manage_network holders may
		// promote the shared release; any other logged-in user fails
		// closed so a site admin can never switch the network release.
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$uid = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
			$network_allowed = 0 === $uid || ( function_exists( 'current_user_can' ) && current_user_can( 'manage_network' ) );
			if ( ! $network_allowed ) {
				self::fail( 'HAL_IMPORT_MULTISITE_UNAUTHORIZED' );
			}
		}
	}

	/**
	 * Staging directory shape owned by this import (same filesystem as
	 * releases/, random suffix). Used for creation and contained cleanup.
	 */
	private function import_staging_path(): string {
		return $this->releases . DIRECTORY_SEPARATOR . '.hal-import-' . bin2hex( random_bytes( 16 ) );
	}

	/**
	 * The updated Carrier directory WordPress just wrote. Rejects links
	 * and anything outside the plugins directory (no request value or
	 * unresolved variable ever becomes a filesystem target).
	 */
	private function updated_carrier_root(): string {
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			self::fail( 'HAL_IMPORT_PLUGIN_DIR_UNAVAILABLE' );
		}
		$carrier_root = rtrim( (string) constant( 'WP_PLUGIN_DIR' ), '/\\' ) . DIRECTORY_SEPARATOR . 'hal-frontend-dashboard';
		if ( is_link( $carrier_root ) || ! is_dir( $carrier_root ) ) {
			self::fail( 'HAL_IMPORT_CARRIER_UNAVAILABLE' );
		}
		return $carrier_root;
	}

	/**
	 * Real package verifier beside this file (both legal locations keep
	 * class-package-verifier.php next to the manager). Throwaway test keys
	 * only via the explicit test constants (production uses the pinned key).
	 */
	private function import_verifier(): HAL_Frontend_Dashboard_Package_Verifier {
		if ( ! class_exists( 'HAL_Frontend_Dashboard_Package_Verifier', false ) ) {
			require_once __DIR__ . '/class-package-verifier.php';
		}
		return new HAL_Frontend_Dashboard_Package_Verifier(
			defined( 'HAL_FRONTEND_DASHBOARD_TEST_PUBLIC_KEY' ) ? HAL_FRONTEND_DASHBOARD_TEST_PUBLIC_KEY : null,
			defined( 'HAL_FRONTEND_DASHBOARD_TEST_KEY_FINGERPRINT' ) ? HAL_FRONTEND_DASHBOARD_TEST_KEY_FINGERPRINT : null
		);
	}

	/**
	 * Exactly one runtime archive beside the signed payload manifest —
	 * the same cardinality gate the installer enforces at activation.
	 */
	private function read_signed_payload_manifest( HAL_Frontend_Dashboard_Package_Verifier $verifier, string $payload ): array {
		$manifest_file = $payload . DIRECTORY_SEPARATOR . 'runtime-manifest.json';
		$signature_file = $payload . DIRECTORY_SEPARATOR . 'runtime-manifest.sig';
		$candidates = glob( $payload . DIRECTORY_SEPARATOR . 'runtime-*.zip' );
		if ( false === $candidates || 1 !== count( $candidates ) ) {
			self::fail( 'HAL_RUNTIME_ARCHIVE_CARDINALITY_INVALID' );
		}
		return $verifier->verify_runtime_archive( $candidates[0], $manifest_file, $signature_file );
	}

	/**
	 * Import compatibility mirrors the installer gates for the new code:
	 * runtime PHP/WordPress floors, loader API window, and the updated
	 * Carrier main file naming the same version as its own payload.
	 */
	private function assert_import_compatibility( string $carrier_root, array $manifest ): void {
		global $wp_version;
		if (
			version_compare( PHP_VERSION, (string) ( $manifest['requires_php'] ?? '9999' ), '<' )
			|| version_compare( (string) ( $wp_version ?? '' ), (string) ( $manifest['requires_wp'] ?? '9999' ), '<' )
			|| (int) ( $manifest['loader_api_min'] ?? 2 ) > 1
			|| (int) ( $manifest['loader_api_max'] ?? 0 ) < 1
		) {
			self::fail( 'HAL_RUNTIME_MANIFEST_INCOMPATIBLE' );
		}
		$main = (string) @file_get_contents( $carrier_root . DIRECTORY_SEPARATOR . 'hal-frontend-dashboard.php' );
		if ( 1 !== preg_match( '/^[ \t\*#@]*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)\s*$/mi', $main, $matches ) || $matches[1] !== ( $manifest['version'] ?? null ) ) {
			self::fail( 'HAL_IMPORT_CARRIER_MANIFEST_MISMATCH' );
		}
	}

	/**
	 * Best-effort removal of OUR staging directory only (exact
	 * releases/.hal-import-<hex> shape, link-free); never masks the
	 * original failure.
	 */
	private function remove_import_staging( string $staging ): void {
		if ( 1 !== preg_match( '/\A\.hal-import-[0-9a-f]{32}\z/D', basename( $staging ) ) || dirname( $staging ) !== $this->releases ) {
			return;
		}
		if ( is_link( $staging ) || ! file_exists( $staging ) ) {
			return;
		}
		try {
			$this->delete_contained_directory( $staging, $this->releases );
		} catch ( Throwable $ignored ) {
		}
	}

	/**
	 * Bridge-owned import names with literal fallbacks (the bridge file is
	 * always loaded in MU context, but the manager never assumes it).
	 */
	private static function import_option_name(): string {
		if ( class_exists( 'HAL_Frontend_Dashboard_Update_Bridge', false ) ) {
			return HAL_Frontend_Dashboard_Update_Bridge::IMPORT_OPTION;
		}
		return 'hal_frontend_dashboard_update_candidate';
	}

	private static function import_plugin_basename(): string {
		if ( class_exists( 'HAL_Frontend_Dashboard_Update_Bridge', false ) ) {
			return HAL_Frontend_Dashboard_Update_Bridge::PLUGIN_BASENAME;
		}
		return 'hal-frontend-dashboard/hal-frontend-dashboard.php';
	}

	public function prune_retained_releases( int $retain = 3 ): void {
		if ( $retain < 3 ) {
			self::fail( 'HAL_RELEASE_RETENTION_INVALID' );
		}
		$this->with_lock( function ( self $manager ) use ( $retain ): void {
			$history = $manager->read_state_json( 'history.json' );
			$release_history = is_array( $history['releases'] ?? null ) ? $history['releases'] : array();
			$validated = array();
			foreach ( $release_history as $release_id ) {
				if ( ! is_string( $release_id ) ) {
					self::fail( 'HAL_RELEASE_HISTORY_INVALID' );
				}
				$manager->assert_release_id( $release_id );
				$validated[] = $release_id;
			}
			$keep = array_slice( array_values( array_unique( $validated ) ), 0, $retain );
			foreach ( array( $manager->read_pointer( 'active.json' ), $manager->read_pointer( 'previous.json' ) ) as $pointer ) {
				if ( is_array( $pointer ) && is_string( $pointer['release_id'] ?? null ) ) {
					$manager->assert_release_id( $pointer['release_id'] );
					$keep[] = $pointer['release_id'];
				}
			}
			$keep = array_values( array_unique( $keep ) );
			foreach ( array_diff( $validated, $keep ) as $release_id ) {
				$manager->delete_release_tree( $release_id );
			}
			$manager->write_json_atomic( $manager->state_path( 'history.json' ), array( 'releases' => $keep ) );
		} );
	}

	public function set_loader_active( string $loader_id ): void {
		$this->assert_release_id( $loader_id );
		if ( ! is_file( $this->loader_path( $loader_id ) . DIRECTORY_SEPARATOR . 'loader-core.php' ) ) {
			self::fail( 'HAL_LOADER_INCOMPLETE' );
		}
		$current = $this->read_pointer( 'loader-active.json' );
		if ( null !== $current ) {
			$this->write_json_atomic( $this->state_path( 'loader-previous.json' ), $current );
		}
		$this->write_json_atomic( $this->state_path( 'loader-active.json' ), array( 'loader_id' => $loader_id ) );
	}

	public function read_pointer( string $filename ): ?array {
		$allowed = array( 'active.json', 'previous.json', 'loader-active.json', 'loader-previous.json' );
		if ( ! in_array( $filename, $allowed, true ) ) {
			self::fail( 'HAL_STATE_POINTER_NAME_INVALID' );
		}
		$path = $this->state_path( $filename );
		if ( ! is_file( $path ) ) {
			return null;
		}
		try {
			$value = json_decode( (string) file_get_contents( $path ), true, 16, JSON_THROW_ON_ERROR );
		} catch ( JsonException $exception ) {
			self::fail( 'HAL_STATE_POINTER_INVALID' );
		}
		if ( ! is_array( $value ) ) {
			self::fail( 'HAL_STATE_POINTER_INVALID' );
		}
		return $value;
	}

	public function write_pointer_for_install( string $filename, array $value ): void {
		if ( ! in_array( $filename, array( 'active.json', 'loader-active.json' ), true ) ) {
			self::fail( 'HAL_STATE_POINTER_NAME_INVALID' );
		}
		if ( file_exists( $this->state_path( $filename ) ) ) {
			return;
		}
		$this->write_json_atomic( $this->state_path( $filename ), $value, false );
	}

	public function record_status( string $status, array $context = array() ): void {
		$allowed = array( 'idle', 'carrier_updated', 'loader_staged', 'loader_pending_health', 'verifying', 'staged', 'pending_health', 'active', 'failed', 'rolled_back', 'blocked' );
		if ( ! in_array( $status, $allowed, true ) ) {
			self::fail( 'HAL_RELEASE_STATE_INVALID' );
		}
		$this->write_json_atomic( $this->state_path( 'manager-state.json' ), array_merge( array( 'state' => $status ), $context ) );
	}

	public function state_path( string $filename ): string {
		if ( basename( $filename ) !== $filename || '' === $filename ) {
			self::fail( 'HAL_STATE_FILENAME_INVALID' );
		}
		return $this->state . DIRECTORY_SEPARATOR . $filename;
	}

	public function release_path( string $release_id ): string {
		$this->assert_release_id( $release_id );
		return $this->releases . DIRECTORY_SEPARATOR . $release_id;
	}

	public function loader_path( string $loader_id ): string {
		$this->assert_release_id( $loader_id );
		return $this->loader_releases . DIRECTORY_SEPARATOR . $loader_id;
	}

	/**
	 * Reads a promotion record without holding the update lock; used by
	 * the loader to decide whether a recovery attempt is warranted before
	 * taking the lock.
	 */
	public function read_state_file( string $filename ): ?array {
		return $this->read_state_json( $filename );
	}

	private function write_json_atomic( string $destination, array $value, bool $replace = true ): void {
		$directory = dirname( $destination );
		$this->ensure_directory( $directory );
		if ( is_link( $destination ) || ( ! $replace && file_exists( $destination ) ) ) {
			self::fail( 'HAL_ATOMIC_DESTINATION_INVALID' );
		}
		$temp = $directory . DIRECTORY_SEPARATOR . '.hal-' . bin2hex( random_bytes( 12 ) ) . '.tmp';
		$handle = @fopen( $temp, 'xb' );
		if ( false === $handle ) {
			self::fail( 'HAL_ATOMIC_TEMP_CREATE_FAILED' );
		}
		$json = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
		try {
			if ( strlen( $json ) !== fwrite( $handle, $json ) || ! fflush( $handle ) ) {
				self::fail( 'HAL_ATOMIC_TEMP_WRITE_FAILED' );
			}
			function_exists( 'fsync' ) && fsync( $handle );
		} finally {
			fclose( $handle );
		}
		@chmod( $temp, $this->file_mode() );
		if ( ! @rename( $temp, $destination ) ) {
			@unlink( $temp );
			self::fail( 'HAL_ATOMIC_RENAME_UNSUPPORTED' );
		}
	}

	private function read_state_json( string $filename ): ?array {
		$path = $this->state_path( $filename );
		if ( ! is_file( $path ) || is_link( $path ) ) {
			return null;
		}
		try {
			$value = json_decode( (string) file_get_contents( $path ), true, 16, JSON_THROW_ON_ERROR );
		} catch ( JsonException $exception ) {
			self::fail( 'HAL_RELEASE_STATE_CORRUPT' );
		}
		return is_array( $value ) ? $value : null;
	}

	private function delete_release_tree( string $release_id ): void {
		$this->assert_release_id( $release_id );
		$candidate = $this->release_path( $release_id );
		if ( is_link( $candidate ) ) {
			self::fail( 'HAL_RELEASE_CLEANUP_LINK_REJECTED' );
		}
		if ( ! file_exists( $candidate ) ) {
			return;
		}
		$candidate_stat = lstat( $candidate );
		if ( false === $candidate_stat ) {
			self::fail( 'HAL_RELEASE_CLEANUP_PATH_INVALID' );
		}
		$this->assert_cleanup_path_not_reparse( $candidate, $candidate_stat );
		$root = realpath( $this->releases );
		$target = realpath( $candidate );
		if ( false === $root || false === $target || ! str_starts_with( $target, rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR ) ) {
			self::fail( 'HAL_RELEASE_CLEANUP_PATH_INVALID' );
		}
		$this->delete_contained_directory( $target, $root );
	}

	private function delete_contained_directory( string $directory, string $root ): void {
		$iterator = new FilesystemIterator( $directory, FilesystemIterator::SKIP_DOTS );
		foreach ( $iterator as $item ) {
			$path = $item->getPathname();
			if ( is_link( $path ) ) {
				self::fail( 'HAL_RELEASE_CLEANUP_LINK_REJECTED' );
			}
			$stat = lstat( $path );
			if ( false === $stat ) {
				self::fail( 'HAL_RELEASE_CLEANUP_PATH_INVALID' );
			}
			$this->assert_cleanup_path_not_reparse( $path, $stat );
			$resolved = realpath( $path );
			if ( false === $resolved || ! str_starts_with( $resolved, rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR ) ) {
				self::fail( 'HAL_RELEASE_CLEANUP_PATH_INVALID' );
			}
			if ( is_dir( $path ) ) {
				$this->delete_contained_directory( $path, $root );
			} elseif ( ! unlink( $path ) ) {
				self::fail( 'HAL_RELEASE_CLEANUP_FAILED' );
			}
		}
		if ( ! rmdir( $directory ) ) {
			self::fail( 'HAL_RELEASE_CLEANUP_FAILED' );
		}
	}

	private function assert_cleanup_path_not_reparse( string $path, array $stat ): void {
		if ( 0120000 === ( $stat['mode'] & 0170000 ) ) {
			self::fail( 'HAL_RELEASE_CLEANUP_LINK_REJECTED' );
		}
		if ( 'Windows' !== PHP_OS_FAMILY ) {
			return;
		}
		$resolved = realpath( $path );
		if ( false === $resolved ) {
			self::fail( 'HAL_RELEASE_CLEANUP_PATH_INVALID' );
		}
		$lexical = strtolower( str_replace( '/', '\\', rtrim( $path, '/\\' ) ) );
		$canonical = strtolower( str_replace( '/', '\\', rtrim( $resolved, '/\\' ) ) );
		if ( $lexical !== $canonical ) {
			self::fail( 'HAL_RELEASE_CLEANUP_REPARSE_REJECTED' );
		}
	}

	private function write_locked_metadata( $handle, array $metadata ): void {
		$json = array() === $metadata ? '' : json_encode( $metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
		if ( ! rewind( $handle ) || ! ftruncate( $handle, 0) || ( '' !== $json && strlen( $json ) !== fwrite( $handle, $json ) ) || ! fflush( $handle ) ) {
			self::fail( 'HAL_UPDATE_LOCK_METADATA_FAILED' );
		}
		function_exists( 'fsync' ) && fsync( $handle );
	}

	private function assert_release_id( string $release_id ): void {
		if ( 1 !== preg_match( '/\A(?:[0-9]+\.[0-9]+\.[0-9]+\+[a-f0-9]{40}|loader-[0-9]+\.[0-9]+\.[0-9]+)\z/D', $release_id ) ) {
			self::fail( 'HAL_RELEASE_ID_INVALID' );
		}
	}

	private function assert_clean_directory( string $directory ): void {
		$real = realpath( $directory );
		if ( false === $real || ! is_dir( $real ) || is_link( $directory ) ) {
			self::fail( 'HAL_STAGED_RELEASE_INVALID' );
		}
	}

	private function ensure_directory( string $directory ): void {
		if ( is_link( $directory ) ) {
			self::fail( 'HAL_FILESYSTEM_LINK_REJECTED' );
		}
		if ( is_dir( $directory ) ) {
			return;
		}
		if ( file_exists( $directory ) || ! @mkdir( $directory, $this->directory_mode(), true ) ) {
			self::fail( 'HAL_DIRECTORY_CREATE_FAILED' );
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
