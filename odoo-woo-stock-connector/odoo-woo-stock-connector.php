<?php
/**
 * Plugin Name: Odoo WooCommerce Stock Connector V2
 * Description: V2 foundation for a secure Odoo 18 and WooCommerce inventory connector.
 * Version: 2.0.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'OWSC_VERSION', '2.0.0' );
define( 'OWSC_FILE', __FILE__ );

final class OWSCPluginV2 {
    public static function boot(): void {
        add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
    }
    public static function render_notice(): void {
        if ( current_user_can( 'manage_woocommerce' ) ) {
            echo '<div class="notice notice-info"><p>Odoo Stock Connector V2 foundation is active. No records can be changed yet.</p></div>';
        }
    }
}
OWSCPluginV2::boot();
