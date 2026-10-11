<?php
/**
 * Uninstall handler: remove the stored API key.
 *
 * @package EVR_CRM_Bridge
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'evr_crm_bridge_key' );
