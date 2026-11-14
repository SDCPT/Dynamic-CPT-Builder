<?php
/**
 * Plugin Name:       Dynamic CPT Builder
 * Description:       Create custom post types, taxonomies and fields in the backend. No code, no custom database tables.
 * Version:           0.2.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * License:           AGPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/agpl-3.0.html
 * Text Domain:       dcb
 * Domain Path:       /languages
 *
 * @package DynamicCPTBuilder
 */

defined( 'ABSPATH' ) || exit;

define( 'DCB_VERSION', '0.2.0' );
define( 'DCB_FILE', __FILE__ );
define( 'DCB_PATH', plugin_dir_path( __FILE__ ) );

require_once DCB_PATH . 'includes/class-registry.php';
require_once DCB_PATH . 'includes/class-i18n.php';
require_once DCB_PATH . 'includes/class-admin.php';
require_once DCB_PATH . 'includes/class-metabox.php';
require_once DCB_PATH . 'includes/class-frontend.php';

/**
 * Entry point. There is exactly one instance; all modules share the same registry.
 */
final class DCB_Plugin {

	/** @var DCB_Plugin|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$registry = new DCB_Registry();
		$registry->init();
		( new DCB_I18n( $registry ) )->init();

		if ( is_admin() ) {
			( new DCB_Admin( $registry ) )->init();
			( new DCB_Metabox( $registry ) )->init();
		}

		( new DCB_Frontend( $registry ) )->init();
	}
}

add_action( 'plugins_loaded', array( 'DCB_Plugin', 'instance' ) );

register_activation_hook( __FILE__, array( 'DCB_Registry', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'DCB_Registry', 'deactivate' ) );
