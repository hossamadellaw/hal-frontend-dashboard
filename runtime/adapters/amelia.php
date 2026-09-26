<?php
/**
 * runtime/adapters/amelia.php (الدفعة 4)
 * ══════════════════════════════════════════════════════════════
 * الدور: كل تكامل Amelia — الـfilters الرسمية الأربعة، allowlist
 * ثابتة لـElite API (قراءة فقط)، والاستثناء SQL المباشر الوحيد
 * المعتمَد فى كل المشروع (3 مواضع داخل هذا الملف: جدول/موظف مرتبط/
 * مواعيد قادمة — SQL #2 (إبطال كاش المواعيد) مملوك لـcore/notifications.php).
 *
 * المصدر: نقل من legacy mu-plugins/hossam-dashboard/adapters/amelia.php
 * (الدفعة 4 من HAL Frontend Dashboard) — التعديل المصرَّح به وحده:
 *   - hossam_amelia_get_api_key(): تستهلك credential resolver الدفعة 3
 *     المعتمد (قرار المالك §2.4) عبر
 *     HAL_Frontend_Dashboard_Integration_Settings_Registry::credential_source():
 *     الثابت الموثق HOSSAM_AMELIA_API_KEY ثم HAL encrypted fallback
 *     (amelia_elite_api_key) — لا env name موثق للـAmelia (قرار
 *     registry الدفعة 3 المغلق)، لذا قناة getenv التاريخية استُبدلت
 *     بالمصدر المعتمد. خارج ترتيب التحميل الطبيعي (طبقة الدفعة 3
 *     غائبة) يبقى الثابت الموثق وحده fallback محميًا.
 *
 * العقد المنقول كما هو:
 *   - api_request: allowlist حرفية (GET /appointments فقط، وسائط
 *     مغلقة dates/page/skipServices/skipProviders/asArray)، HTTPS مفروض
 *     على الـURL المبني، sslverify=true، redirection=0، حد استجابة
 *     64KB، timeout=15، بلا base URL من input، ولkeleton المفتاح في ترويسة Amelia فقط.
 *   - 401/403 → credentials_rejected + إبطال كاش الصحة وقدرات
 *     التكاملات (delete_transient + hossam_invalidate_integration_capabilities
 *     بحارس function_exists).
 *   - health: transient خمس دقائق + إبطال على activated/deactivated/
 *     upgrader_process_complete.
 *   - SQL: prepared حصرًا + فحص last_error + fail-safe ([] / null؛
 *     قراءة ربط الموظف وحدها WP_Error عند فشل القراءة — B6-01).
 *     + allowlist أسماء جداول مغلقة بـstatic cache.
 *   - صلاحيات: manage_options حصرًا للـAPI؛ view_amelia_calendar_all
 *     (المسجلة في core/permissions.php) مكافئ in_array الأصلي لفرع
 *     المدير بجانب manage_options؛ ownership: user_id نفسه أو
 *     manage_options لقراءة الموظف المرتبط/المواعيد.
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'hossam_amelia_is_active' ) ) {
	function hossam_amelia_is_active(): bool {
		if ( function_exists( 'hossam_is_known_plugin_active' ) ) {
			return hossam_is_known_plugin_active( [ 'ameliabooking/ameliabooking.php' ] );
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			$plugin_api = ABSPATH . 'wp-admin/includes/plugin.php';
			if ( ! is_readable( $plugin_api ) ) {
				return false;
			}
			require_once $plugin_api;
		}

		return is_plugin_active( 'ameliabooking/ameliabooking.php' )
			|| ( is_multisite()
				&& function_exists( 'is_plugin_active_for_network' )
				&& is_plugin_active_for_network( 'ameliabooking/ameliabooking.php' ) );
	}
}

if ( ! function_exists( 'hossam_amelia_valid_date_range' ) ) {
	function hossam_amelia_valid_date_range( string $dates ): bool {
		$parts = explode( ',', $dates );
		if ( 2 !== count( $parts ) ) {
			return false;
		}

		$parsed = [];
		foreach ( $parts as $date ) {
			$value  = DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
			$errors = DateTimeImmutable::getLastErrors();
			if ( false === $value
				|| ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) )
				|| $value->format( 'Y-m-d' ) !== $date ) {
				return false;
			}
			$parsed[] = $value;
		}

		return $parsed[0] <= $parsed[1];
	}
}

if ( ! function_exists( 'hossam_amelia_has_appointments_contract' ) ) {
	/** @param array<string,mixed> $response */
	function hossam_amelia_has_appointments_contract( array $response ): bool {
		return isset( $response['data'] )
			&& is_array( $response['data'] )
			&& array_key_exists( 'appointments', $response['data'] )
			&& is_array( $response['data']['appointments'] );
	}
}

if ( ! function_exists( 'hossam_amelia_log_database_failure' ) ) {
	function hossam_amelia_log_database_failure( string $operation ): void {
		error_log( 'Hossam Dashboard Amelia database operation failed: ' . sanitize_key( $operation ) );
	}
}

if ( ! function_exists( 'hossam_amelia_table_exists' ) ) {
	function hossam_amelia_table_exists( string $table_suffix ): bool {
		static $cache = [];
		$allowed_tables = [ 'amelia_appointments', 'amelia_employees', 'amelia_services' ];
		if ( ! in_array( $table_suffix, $allowed_tables, true ) ) {
			return false;
		}
		if ( array_key_exists( $table_suffix, $cache ) ) {
			return $cache[ $table_suffix ];
		}

		global $wpdb;
		$table = $wpdb->prefix . $table_suffix;
		$found = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
		);
		if ( '' !== $wpdb->last_error ) {
			hossam_amelia_log_database_failure( 'table_check' );
		}

		$cache[ $table_suffix ] = $table === $found;

		return $cache[ $table_suffix ];
	}
}

if ( ! function_exists( 'hossam_amelia_get_api_key' ) ) {
	/**
	 * credential resolver الدفعة 3 المعتمد (قرار المالك §2.4):
	 * constant (الثابت الموثق) ثم HAL encrypted fallback ثم فراغ.
	 * القيمة تُستهلك وقت النداء فقط — لا تسجيل ولا عرض.
	 */
	function hossam_amelia_get_api_key(): string {
		if ( class_exists( 'HAL_Frontend_Dashboard_Integration_Settings_Registry' ) ) {
			$source = HAL_Frontend_Dashboard_Integration_Settings_Registry::credential_source( 'amelia_elite_api_key' );
			if ( 'constant' === $source && defined( 'HOSSAM_AMELIA_API_KEY' ) && is_string( HOSSAM_AMELIA_API_KEY ) ) {
				return trim( HOSSAM_AMELIA_API_KEY );
			}
			if ( 'hal_encrypted' === $source && class_exists( 'HAL_Frontend_Dashboard_Secret_Store' ) ) {
				$stored = HAL_Frontend_Dashboard_Secret_Store::get( 'amelia_elite_api_key' );
				return is_string( $stored ) && '' !== $stored ? trim( $stored ) : '';
			}
			return '';
		}

		// خارج ترتيب التحميل الطبيعي (طبقة الدفعة 3 غائبة): الثابت الموثق فقط.
		if ( defined( 'HOSSAM_AMELIA_API_KEY' ) && is_string( HOSSAM_AMELIA_API_KEY ) ) {
			return trim( HOSSAM_AMELIA_API_KEY );
		}
		return '';
	}
}

if ( ! function_exists( 'hossam_amelia_api_error' ) ) {
	function hossam_amelia_api_error( string $code ): WP_Error {
		$messages = [
			'plugin_missing'   => __( 'The booking integration is not available.', 'astra-child' ),
			'key_missing'      => __( 'The booking integration is not configured.', 'astra-child' ),
			'forbidden'        => __( 'You do not have permission to access booking data.', 'astra-child' ),
			'route_unavailable' => __( 'The requested booking feature is not available.', 'astra-child' ),
			'credentials_rejected' => __( 'The booking service credentials were rejected.', 'astra-child' ),
			'invalid_query'    => __( 'The booking query is invalid.', 'astra-child' ),
			'remote_failure'   => __( 'The booking service is temporarily unavailable.', 'astra-child' ),
		];

		return new WP_Error(
			'hossam_amelia_' . $code,
			$messages[ $code ] ?? $messages['remote_failure']
		);
	}
}

if ( ! function_exists( 'hossam_amelia_api_request' ) ) {
	/**
	 * Executes only allow-listed, read-only Amelia Elite API requests.
	 *
	 * @param array<string,mixed> $query_args
	 * @return array<string,mixed>|WP_Error
	 */
	function hossam_amelia_api_request( string $route, string $method = 'GET', array $query_args = [] ) {
		if ( ! hossam_amelia_is_active() ) {
			return hossam_amelia_api_error( 'plugin_missing' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return hossam_amelia_api_error( 'forbidden' );
		}

		$api_key = hossam_amelia_get_api_key();
		if ( '' === $api_key ) {
			return hossam_amelia_api_error( 'key_missing' );
		}

		$route  = '/' . ltrim( $route, '/' );
		$method = strtoupper( $method );
		if ( 'GET' !== $method || '/appointments' !== $route ) {
			return hossam_amelia_api_error( 'route_unavailable' );
		}

		$allowed_query_args = [];
		if ( array_key_exists( 'dates', $query_args ) ) {
			$dates_value = $query_args['dates'];
			if ( ! is_string( $dates_value ) || ! hossam_amelia_valid_date_range( $dates_value ) ) {
				return hossam_amelia_api_error( 'invalid_query' );
			}
			$allowed_query_args['dates'] = $dates_value;
		}
		if ( isset( $query_args['page'] ) ) {
			$allowed_query_args['page'] = max( 1, absint( $query_args['page'] ) );
		}
		foreach ( [ 'skipServices', 'skipProviders', 'asArray' ] as $key ) {
			if ( array_key_exists( $key, $query_args ) ) {
				$flag_value = $query_args[ $key ];
				if ( is_bool( $flag_value ) ) {
					$allowed_query_args[ $key ] = $flag_value;
				} elseif ( is_int( $flag_value ) && ( 0 === $flag_value || 1 === $flag_value ) ) {
					$allowed_query_args[ $key ] = (bool) $flag_value;
				} else {
					return hossam_amelia_api_error( 'invalid_query' );
				}
			}
		}

		$url = add_query_arg(
			array_merge(
				[
					'action' => 'wpamelia_api',
					'call'   => '/api/v1' . $route,
				],
				$allowed_query_args
			),
			admin_url( 'admin-ajax.php' )
		);
		$url_scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
		if ( 'https' !== $url_scheme ) {
			return hossam_amelia_api_error( 'remote_failure' );
		}
		$response = wp_remote_get(
			$url,
			[
				'timeout'             => 15,
				'redirection'         => 0,
				'limit_response_size' => 65536,
				'sslverify'           => true,
				'headers'             => [
					'Accept' => 'application/json',
					'Amelia' => $api_key,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return hossam_amelia_api_error( 'remote_failure' );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 401 === $status_code || 403 === $status_code ) {
			delete_transient( 'hossam_amelia_api_health_v1' );
			if ( function_exists( 'hossam_invalidate_integration_capabilities' ) ) {
				hossam_invalidate_integration_capabilities();
			}

			return hossam_amelia_api_error( 'credentials_rejected' );
		}
		if ( $status_code < 200 || $status_code >= 300 ) {
			return hossam_amelia_api_error( 'remote_failure' );
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) || ! hossam_amelia_has_appointments_contract( $decoded ) ) {
			return hossam_amelia_api_error( 'remote_failure' );
		}

		return $decoded;
	}
}

if ( ! function_exists( 'hossam_amelia_get_health' ) ) {
	/**
	 * @return array<string,mixed>
	 */
	function hossam_amelia_get_health(): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return [];
		}

		$cache_key = 'hossam_amelia_api_health_v1';
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$health = [
			'plugin_active'     => hossam_amelia_is_active(),
			'api_key_configured' => '' !== hossam_amelia_get_api_key(),
			'api_available'     => false,
			'last_check'        => gmdate( 'c' ),
			'error_code'        => '',
			'checked_routes'    => [],
		];

		if ( ! $health['plugin_active'] ) {
			$health['error_code'] = 'plugin_missing';
		} elseif ( ! $health['api_key_configured'] ) {
			$health['error_code'] = 'key_missing';
		} else {
			$today    = wp_date( 'Y-m-d' );
			$tomorrow = wp_date( 'Y-m-d', time() + DAY_IN_SECONDS );
			$result   = hossam_amelia_api_request(
				'/appointments',
				'GET',
				[
					'dates'         => $today . ',' . $tomorrow,
					'page'          => 1,
					'skipServices'  => true,
					'skipProviders' => true,
				]
			);
			if ( is_wp_error( $result ) ) {
				$health['error_code'] = sanitize_key( $result->get_error_code() );
			} else {
				$health['api_available']  = true;
				$health['checked_routes'] = [ 'appointments_read' ];
			}
		}

		set_transient( $cache_key, $health, 5 * MINUTE_IN_SECONDS );

		return $health;
	}
}

if ( ! function_exists( 'hossam_amelia_invalidate_health' ) ) {
	function hossam_amelia_invalidate_health(): void {
		delete_transient( 'hossam_amelia_api_health_v1' );
	}
}

add_action( 'activated_plugin', 'hossam_amelia_invalidate_health' );
add_action( 'deactivated_plugin', 'hossam_amelia_invalidate_health' );
add_action( 'upgrader_process_complete', 'hossam_amelia_invalidate_health', 10, 2 );

if ( ! function_exists( 'hossam_amelia_get_linked_employee_id' ) ) {
	/**
	 * @return int|WP_Error|null employee id, read-failure error, or null
	 *         when simply unlinked (B6-01: the DB read error is never
	 *         folded into "unlinked").
	 */
	function hossam_amelia_get_linked_employee_id( int $user_id ) {
		if ( $user_id < 1 || ( $user_id !== get_current_user_id() && ! current_user_can( 'manage_options' ) )
			|| ! hossam_amelia_table_exists( 'amelia_employees' ) ) {
			return null;
		}

		global $wpdb;
		$table       = $wpdb->prefix . 'amelia_employees';
		$employee_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE externalId = %d LIMIT 1",
				$user_id
			)
		);
		if ( '' !== $wpdb->last_error ) {
			hossam_amelia_log_database_failure( 'linked_employee' );

			return new WP_Error( 'hossam_amelia_db_error', __( 'Database query failed.', 'astra-child' ) );
		}

		return $employee_id ? (int) $employee_id : null;
	}
}

if ( ! function_exists( 'hossam_amelia_get_upcoming_appointments' ) ) {
	/**
	 * The documented Elite API has no verified employee-owner query contract here;
	 * this read-only, prepared query preserves the existing scoped behaviour pending
	 * an equivalent staged API test.
	 *
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	function hossam_amelia_get_upcoming_appointments( int $user_id, bool $is_manager ): array|WP_Error {
		if ( $user_id < 1 || ( $user_id !== get_current_user_id() && ! current_user_can( 'manage_options' ) )
			|| ( $is_manager && ! current_user_can( 'manage_options' ) && ! current_user_can( 'view_amelia_calendar_all' ) )
			|| ! hossam_amelia_is_active()
			|| ! hossam_amelia_table_exists( 'amelia_appointments' )
			|| ! hossam_amelia_table_exists( 'amelia_employees' )
			|| ! hossam_amelia_table_exists( 'amelia_services' ) ) {
			return [];
		}

		$cache_key = 'hossam_appts_' . $user_id . '_' . ( $is_manager ? 'mgr' : 'emp' );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$appointments_table = $wpdb->prefix . 'amelia_appointments';
		$services_table     = $wpdb->prefix . 'amelia_services';
		$employees_table    = $wpdb->prefix . 'amelia_employees';
		$appointments       = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.bookingStart, a.status, s.name AS service_name
				 FROM {$appointments_table} a
				 LEFT JOIN {$services_table} s ON s.id = a.serviceId
				 LEFT JOIN {$employees_table} e ON e.id = a.providerId
				 WHERE a.bookingStart >= %s AND a.status = 'approved'
				 AND (%d = 1 OR e.externalId = %d)
				 ORDER BY a.bookingStart ASC LIMIT 5",
				current_time( 'mysql' ),
				(int) $is_manager,
				$user_id
			),
			ARRAY_A
		);
		if ( '' !== $wpdb->last_error ) {
			hossam_amelia_log_database_failure( 'upcoming_appointments' );

			return new WP_Error( 'hossam_amelia_db_error', __( 'Database query failed.', 'astra-child' ) );
		}

		$appointments = is_array( $appointments ) ? $appointments : [];
		set_transient( $cache_key, $appointments, 5 * MINUTE_IN_SECONDS );

		return $appointments;
	}
}

add_action( 'plugins_loaded', function(): void {
	if ( ! hossam_amelia_is_active() ) {
		return;
	}

	$auth_callback = function( bool $authenticated, int $employee_id ): bool {
		if ( current_user_can( 'manage_options' ) || ! is_user_logged_in() ) {
			return $authenticated;
		}

		$current_user_id = get_current_user_id();
		if ( $current_user_id === $employee_id || ! hossam_amelia_table_exists( 'amelia_employees' ) ) {
			return $current_user_id === $employee_id ? true : $authenticated;
		}

		static $employee_users = [];
		if ( ! array_key_exists( $employee_id, $employee_users ) ) {
			global $wpdb;
			$table = $wpdb->prefix . 'amelia_employees';
			$employee_users[ $employee_id ] = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT externalId FROM {$table} WHERE id = %d LIMIT 1",
					$employee_id
				)
			);
			if ( '' !== $wpdb->last_error ) {
				hossam_amelia_log_database_failure( 'authentication' );
			}
		}

		return $employee_users[ $employee_id ] > 0 && $employee_users[ $employee_id ] === $current_user_id
			? true
			: $authenticated;
	};

	add_filter( 'amelia_pro_is_user_authenticated', $auth_callback, 10, 2 );
	add_filter( 'amelia_is_user_authenticated', $auth_callback, 10, 2 );
	add_filter(
		'amelia_is_user_logged_in',
		function( bool $logged_in ): bool {
			return is_user_logged_in() ? true : $logged_in;
		}
	);
	add_filter(
		'amelia_current_user_id',
		function( int $user_id ): int {
			return is_user_logged_in() ? get_current_user_id() : $user_id;
		}
	);
}, 20 );
