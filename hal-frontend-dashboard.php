<?php
/**
 * Plugin Name: HAL Frontend Dashboard
 * Plugin URI: https://github.com/hossamadellaw/hal-frontend-dashboard
 * Description: Carrier plugin for the HAL Frontend Dashboard release system.
 * Version: 1.0.5
 * Requires at least: 7.0
 * Requires PHP: 8.3
 * Tested up to: 7.1
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: hal-frontend-dashboard
 * Update URI: https://github.com/hossamadellaw/hal-frontend-dashboard
 */

defined( 'ABSPATH' ) || exit;

define( 'HAL_FRONTEND_DASHBOARD_VERSION', '1.0.5' );
define( 'HAL_FRONTEND_DASHBOARD_SLUG', 'hal-frontend-dashboard' );
define( 'HAL_FRONTEND_DASHBOARD_PLUGIN_FILE', __FILE__ );
define( 'HAL_FRONTEND_DASHBOARD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'HAL_FRONTEND_DASHBOARD_REPOSITORY_URL', 'https://github.com/hossamadellaw/hal-frontend-dashboard' );

/**
 * Run the installer only when WordPress activates the Carrier.
 *
 * WordPress passes $network_wide on multisite; it is forwarded so a
 * network activation provisions every current site (single shared
 * Runtime release, per-site setup).
 *
 * v1.0.4 wrapper contract: no Throwable escapes raw. Installer-source
 * HAL_ codes (single, or preflight codes composed behind
 * HAL_PREFLIGHT_MULTIPLE:) are displayed through the installer's static
 * remedy map; the two installer-load failures below surface as
 * HAL_INSTALLER_UNAVAILABLE / HAL_INSTALLER_INVALID; anything else
 * collapses to HAL_UNEXPECTED_ERROR with the exception class name only —
 * no message, no paths. Display runs through wp_die: never a silent
 * return that could leave the plugin "active without install". (How
 * wp_die renders inside the activation hook is field-verified under G4.)
 *
 * @param bool $network_wide
 * @return void
 */
function hal_frontend_dashboard_activate( $network_wide = false ) {
	try {
		$installer_file = HAL_FRONTEND_DASHBOARD_PLUGIN_DIR . 'includes/class-installer.php';

		if ( ! is_readable( $installer_file ) ) {
			throw new RuntimeException( 'HAL_INSTALLER_UNAVAILABLE' );
		}

		require_once $installer_file;

		if ( ! class_exists( 'HAL_Frontend_Dashboard_Installer' ) || ! is_callable( array( 'HAL_Frontend_Dashboard_Installer', 'activate' ) ) ) {
			throw new RuntimeException( 'HAL_INSTALLER_INVALID' );
		}

		HAL_Frontend_Dashboard_Installer::activate( true === $network_wide );
	} catch ( Throwable $exception ) {
		$message = $exception->getMessage();

		if ( $exception instanceof RuntimeException && str_starts_with( $message, 'HAL_' ) ) {
			$display = $message;

			if ( str_starts_with( $message, 'HAL_PREFLIGHT_' ) && class_exists( 'HAL_Frontend_Dashboard_Installer', false ) ) {
				$codes = str_starts_with( $message, 'HAL_PREFLIGHT_MULTIPLE:' )
					? explode( ',', substr( $message, strlen( 'HAL_PREFLIGHT_MULTIPLE:' ) ) )
					: array( $message );
				$lines = array();
				foreach ( HAL_Frontend_Dashboard_Installer::preflight_remedy( $codes ) as $code => $remedy ) {
					$line = $code;
					if ( '' !== $remedy['text'] ) {
						$line .= ' — ' . $remedy['text'];
						if ( '' !== $remedy['remedy'] ) {
							$line .= ' ' . $remedy['remedy'];
						}
					}
					$lines[] = $line;
				}
				$display = implode( "\n", $lines );
			}

			wp_die( $display );
		}

		wp_die( 'HAL_UNEXPECTED_ERROR (' . get_class( $exception ) . ')' );
	}
}

register_activation_hook( __FILE__, 'hal_frontend_dashboard_activate' );
