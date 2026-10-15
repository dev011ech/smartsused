<?php
/**
 * Plugin Name: SmartSused Core
 * Description: Základná funkcionalita pre správu bytových domov a jednotiek.
 * Version: 1.0.24
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: SmartSused
 * Text Domain: smartsused-core
 * Domain Path: /languages
 *
 * @package SmartSused_Core
 */

defined( 'ABSPATH' ) || exit;

define( 'SMARTSUSED_CORE_VERSION', '1.0.24' );
define( 'SMARTSUSED_CORE_FILE', __FILE__ );
define( 'SMARTSUSED_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'SMARTSUSED_CORE_URL', plugin_dir_url( __FILE__ ) );

function smartsused_clear_update_cache( $upgrader, $options ) {
	unset( $upgrader );

	if (
		! is_array( $options )
		|| ! isset( $options['action'], $options['type'] )
		|| 'update' !== $options['action']
		|| 'plugin' !== $options['type']
	) {
		return;
	}

	delete_site_transient( 'update_plugins' );
	wp_clean_plugins_cache();
}
add_action( 'upgrader_process_complete', 'smartsused_clear_update_cache', 10, 2 );

require_once SMARTSUSED_CORE_PATH . 'vendor/autoload.php';
require_once SMARTSUSED_CORE_PATH . 'includes/class-smartsused-core.php';
require_once SMARTSUSED_CORE_PATH . 'includes/class-smartsused-format.php';
require_once SMARTSUSED_CORE_PATH . 'includes/class-smartsused-validation.php';
require_once SMARTSUSED_CORE_PATH . 'includes/class-smartsused-secure-files.php';
require_once SMARTSUSED_CORE_PATH . 'includes/class-smartsused-license.php';
require_once SMARTSUSED_CORE_PATH . 'includes/class-smartsused-google-config.php';
require_once SMARTSUSED_CORE_PATH . 'includes/class-smartsused-module-updater.php';
require_once SMARTSUSED_CORE_PATH . 'includes/class-smartsused-jwt.php';
require_once SMARTSUSED_CORE_PATH . 'includes/class-smartsused-welcome-email.php';
require_once SMARTSUSED_CORE_PATH . 'includes/class-smartsused-manager-scope.php';
require_once SMARTSUSED_CORE_PATH . 'api/class-smartsused-rest-api.php';
require_once SMARTSUSED_CORE_PATH . 'api/class-smartsused-owner-import.php';
require_once SMARTSUSED_CORE_PATH . 'api/class-smartsused-owner-management.php';
require_once SMARTSUSED_CORE_PATH . 'api/class-smartsused-import-file.php';
require_once SMARTSUSED_CORE_PATH . 'api/class-smartsused-property-import.php';
require_once SMARTSUSED_CORE_PATH . 'api/class-smartsused-unit-import.php';
require_once SMARTSUSED_CORE_PATH . 'api/class-smartsused-import-template.php';
require_once SMARTSUSED_CORE_PATH . 'api/class-smartsused-tenant-invites.php';
require_once SMARTSUSED_CORE_PATH . 'admin/class-smartsused-admin.php';
require_once SMARTSUSED_CORE_PATH . 'api/class-smartsused-admin-api.php';

add_action(
	'init',
	static function () {
		load_plugin_textdomain(
			'smartsused-core',
			false,
			dirname( plugin_basename( SMARTSUSED_CORE_FILE ) ) . '/languages'
		);
	},
	0
);

register_activation_hook( SMARTSUSED_CORE_FILE, array( 'SmartSused_Core', 'activate' ) );
register_deactivation_hook( SMARTSUSED_CORE_FILE, array( 'SmartSused_Core', 'deactivate' ) );

SmartSused_Core::instance();
new SmartSused_Secure_Files();
new SmartSused_JWT();
new SmartSused_Module_Updater();
new SmartSused_Manager_Scope();
new SmartSused_REST_API();
new SmartSused_Owner_Import();
new SmartSused_Owner_Management();
new SmartSused_Property_Import();
new SmartSused_Unit_Import();
new SmartSused_Import_Template();
new SmartSused_Tenant_Invites();
new SmartSused_Admin();
new SmartSused_Admin_API();
