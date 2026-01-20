<?php
/**
 * Plugin Name:       SAC Database Inspector
 * Plugin URI:        https://github.com/jackofall1232/database-inspector
 * Description:       Visualizes database and cache usage, identifies bloated entries, and allows safe manual cleanup.
 * Version:           0.1.2
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            jackofall1232
 * Author URI:        https://wordpress.org
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       database-inspector
 * Domain Path:       /languages
 *
 * @package Database_Inspector
 */

defined( 'ABSPATH' ) || exit;

define( 'WPDI_VERSION', '0.1.2' );
define( 'WPDI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPDI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Main plugin class.
 */
final class Database_Inspector {

	/**
	 * Single instance.
	 *
	 * @var Database_Inspector|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Database_Inspector
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
		// No manual load_plugin_textdomain() needed on WP.org (WP ≥ 4.6).
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

register_activation_hook( __FILE__, array( 'Database_Inspector', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Database_Inspector', 'deactivate' ) );

/**
 * Initialize plugin.
 *
 * @return Database_Inspector
 */
function wpdi() {
	return Database_Inspector::instance();
}

wpdi();
