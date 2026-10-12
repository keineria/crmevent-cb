<?php
/**
 * Plugin Name: EVR CRM Bridge
 * Description: Expone una API REST de solo lectura con las inscripciones de EVR Event Registration y los pedidos de WooCommerce para que el CRM 360 las sincronice.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * Text Domain: evr-crm-bridge
 *
 * @package EVR_CRM_Bridge
 */

defined( 'ABSPATH' ) || exit;

define( 'EVR_CRM_BRIDGE_VERSION', '1.0.0' );
define( 'EVR_CRM_BRIDGE_FILE', __FILE__ );
define( 'EVR_CRM_BRIDGE_KEY_OPTION', 'evr_crm_bridge_key' );

require_once __DIR__ . '/includes/class-evr-crm-bridge-auth.php';
require_once __DIR__ . '/includes/class-evr-crm-bridge-api.php';
require_once __DIR__ . '/includes/class-evr-crm-bridge-admin.php';

register_activation_hook( __FILE__, array( 'EVR_CRM_Bridge_Auth', 'ensure_key' ) );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action( 'rest_api_init', array( 'EVR_CRM_Bridge_API', 'register_routes' ) );

if ( is_admin() ) {
	EVR_CRM_Bridge_Admin::init();
}
