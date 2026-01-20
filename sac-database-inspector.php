<?php
/**
 * Plugin Name:       SAC Database Inspector
 * Plugin URI:        https://github.com/jackofall1232/sac-database-inspector
 * Description:       Read-only administrative utility for inspecting database tables and cache usage to help identify potential bloat.
 * Version:           0.1.2
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            jackofall1232
 * Author URI:        https://wordpress.org
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sac-database-inspector
 * Domain Path:       /languages
 *
 * @package WPDI
 */

defined( 'ABSPATH' ) || exit;

define( 'WPDI_VERSION', '0.1.2' );
define( 'WPDI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPDI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Main plugin class.
 */
final class WPDI_Database_Inspector {

	/**
	 * Single instance.
	 *
	 * @var WPDI_Database_Inspector|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return WPDI_Database_Inspector
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->includes();
		$this->init_hooks();
	}

	/**
	 * Include required files.
	 */
	private function includes() {
		require_once WPDI_PLUGIN_DIR . 'includes/class-wpdi-admin.php';
	}

	/**
	 * Initialize hooks.
	 */
	private function init_hooks() {
		// WP.org auto-loads text domains (WP >= 4.6).
		if ( is_admin() ) {
			WPDI_Admin::instance();
		}
	}

	/**
	 * Activation hook.
	 */
	public static function activate() {
		// Reserved for future use.
	}

	/**
	 * Deactivation hook.
	 */
	public static function deactivate() {
		// Reserved for future use.
	}
}

register_activation_hook( __FILE__, array( 'WPDI_Database_Inspector', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WPDI_Database_Inspector', 'deactivate' ) );

/**
 * Initialize plugin.
 *
 * @return WPDI_Database_Inspector
 */
function wpdi() {
	return WPDI_Database_Inspector::instance();
}

wpdi();
