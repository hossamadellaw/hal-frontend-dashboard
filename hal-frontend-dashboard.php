<?php
/**
 * Plugin Name: HAL Frontend Dashboard
 * Plugin URI: https://github.com/hossamadellaw/hal-frontend-dashboard
 * Description: Carrier plugin for the HAL Frontend Dashboard release system.
 * Version: 1.0.0
 * Requires at least: 7.0
 * Requires PHP: 8.3
 * Tested up to: 7.1
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: hal-frontend-dashboard
 * Update URI: https://github.com/hossamadellaw/hal-frontend-dashboard
 */

defined( 'ABSPATH' ) || exit;

define( 'HAL_FRONTEND_DASHBOARD_VERSION', '1.0.0' );
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
 * @param bool $network_wide
 * @return void
 */
function hal_frontend_dashboard_activate( $network_wide = false ) {
	$installer_file = HAL_FRONTEND_DASHBOARD_PLUGIN_DIR . 'includes/class-installer.php';

	if ( ! is_readable( $installer_file ) ) {
		throw new RuntimeException( 'HAL Frontend Dashboard installer is unavailable.' );
	}

	require_once $installer_file;

	if ( ! class_exists( 'HAL_Frontend_Dashboard_Installer' ) || ! is_callable( array( 'HAL_Frontend_Dashboard_Installer', 'activate' ) ) ) {
		throw new RuntimeException( 'HAL Frontend Dashboard installer is invalid.' );
	}

	HAL_Frontend_Dashboard_Installer::activate( true === $network_wide );
}

register_activation_hook( __FILE__, 'hal_frontend_dashboard_activate' );
