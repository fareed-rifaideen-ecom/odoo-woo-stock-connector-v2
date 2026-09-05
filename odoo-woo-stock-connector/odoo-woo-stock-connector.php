<?php
/**
 * Plugin Name: Odoo WooCommerce Stock Connector V2
 * Plugin URI: https://github.com/fareed-rifaideen-ecom/odoo-woo-stock-connector-v2
 * Description: V2 foundation for a secure Odoo 18 and WooCommerce inventory connector.
 * Version: 2.2.0
 * Author: Fareed M. Rifaideen
 * Author URI: https://fareed-rifaideen.netlify.app/
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'OWSC_VERSION', '2.2.0' );
define( 'OWSC_FILE', __FILE__ );
define( 'OWSC_DIR', plugin_dir_path( __FILE__ ) );

// Load dependencies
require_once OWSC_DIR . 'includes/class-owsc-odoo-xmlrpc-client.php';
require_once OWSC_DIR . 'includes/class-owsc-connection-test.php';
require_once OWSC_DIR . 'includes/class-owsc-sku-audit.php';
require_once OWSC_DIR . 'includes/class-owsc-sku-audit-admin.php';
require_once OWSC_DIR . 'includes/class-owsc-stock-sync.php';
require_once OWSC_DIR . 'includes/class-owsc-order-import.php'; // NEW: Load Order Import

final class OWSCPluginV2 {
    const OPTION_NAME = 'owsc_odoo_settings';

    public static function boot(): void {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
        add_action( 'admin_post_owsc_save_settings', array( __CLASS__, 'save_settings' ) );
        add_action( 'admin_post_owsc_run_bulk_sync', array( __CLASS__, 'handle_bulk_sync' ) );
        
        // Register the background cron job hook
        add_action( 'owsc_cron_stock_sync', array( __CLASS__, 'run_scheduled_sync' ) );
        
        OWSC_Connection_Test::instance()->register();
        new OWSC_SKU_Audit_Admin();

        // NEW: Register the WooCommerce Order Event capture
        if ( class_exists( 'OWSC_Order_Import' ) ) {
            ( new OWSC_Order_Import() )->register();
        }
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook( 'owsc_cron_stock_sync' );
    }

    public static function configuration(): array {
        $settings = get_option( self::OPTION_NAME, array() );
        return array(
            'url'           => (string) ( $settings['url'] ?? '' ),
            'database'      => (string) ( $settings['database'] ?? '' ),
            'username'      => (string) ( $settings['username'] ?? '' ),
            'api_key'       => (string) ( $settings['api_key'] ?? '' ),
            'sync_enabled'  => (string) ( $settings['sync_enabled'] ?? 'no' ),
            'sync_interval' => (string) ( $settings['sync_interval'] ?? 'hourly' ),
        );
    }

    public static function register_menu(): void {
        add_submenu_page( 
            'woocommerce', 
            'Odoo Connector', 
            'Odoo Connector', 
            'manage_woocommerce', 
            'owsc-connector', 
            array( __CLASS__, 'render_page' ) 
        );
    }

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        
        $config = self::configuration();
        $is_saved = isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] === 'true';
        $sync_message = get_transient( 'owsc_sync_message_' . get_current_user_id() );
        delete_transient( 'owsc_sync_message_' . get_current_user_id() );

        ?>
        <div class="wrap">
            <h1>Odoo WooCommerce Stock Connector V2</h1>
            <?php OWSC_Connection_Test::instance()->render_notice(); ?>
            
            <?php if ( $sync_message ) : ?>
                <div class="notice notice-info is-dismissible">
                    <p><strong><?php echo esc_html( $sync_message ); ?></strong></p>
                </div>
            <?php endif; ?>

            <?php if ( $is_saved ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>Settings saved successfully.</p>
                </div>
            <?php endif; ?>

            <p><strong>Phase:</strong> Automated Synchronization Configuration.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="owsc_save_settings">
                <?php wp_nonce_field( 'owsc_save_settings' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="odoo_url">Odoo URL</label></th>
                        <td><input name="url" id="odoo_url" class="regular-text" type="url" value="<?php echo esc_attr( $config['url'] ); ?>" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="odoo_database">Odoo Database</label></th>
                        <td><input name="database" id="odoo_database" class="regular-text" type="text" value="<?php echo esc_attr( $config['database'] ); ?>" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="odoo_username">Odoo Username</label></th>
                        <td><input name="username" id="odoo_username" class="regular-text" type="text" value="<?php echo esc_attr( $config['username'] ); ?>" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="odoo_api_key">Odoo API Key</label></th>
                        <td>
                            <input name="api_key" id="odoo_api_key" type="password" class="regular-text" value="" placeholder="<?php echo $config['api_key'] ? 'Saved (hidden)' : 'Enter API key'; ?>">
                            <p class="description">Leave blank to retain the currently saved API key.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sync_enabled">Automated Sync</label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="sync_enabled" id="sync_enabled" value="yes" <?php checked( $config['sync_enabled'], 'yes' ); ?>>
                                Enable automatic background stock synchronization
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sync_interval">Sync Interval</label></th>
                        <td>
                            <select name="sync_interval" id="sync_interval">
                                <option value="hourly" <?php selected( $config['sync_interval'], 'hourly' ); ?>>Hourly</option>
                                <option value="twicedaily" <?php selected( $config['sync_interval'], 'twicedaily' ); ?>>Twice Daily (Every 12 hours)</option>
                                <option value="daily" <?php selected( $config['sync_interval'], 'daily' ); ?>>Daily</option>
                            </select>
                            <p class="description">How often the plugin should fetch stock updates from Odoo.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Save Connection Settings' ); ?>
            </form>

            <hr />
            <h2>Bulk Stock Synchronization</h2>
            <p>This will immediately query Odoo for all products with <strong>Available for WooCommerce Sync</strong> checked, aggregate their stock across WH, MC, and JM, and update matched SKUs in WooCommerce.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="owsc_run_bulk_sync">
                <?php wp_nonce_field( 'owsc_run_bulk_sync' ); ?>
                <?php submit_button( 'Run Full Manual Sync', 'primary', 'submit', false ); ?>
            </form>

            <hr />
            <h2>Connection Test</h2>
            <p>This test only calls Odoo's version and authenticate methods.</p>
            <?php OWSC_Connection_Test::instance()->render_test_form(); ?>
        </div>
        <?php
    }

    public static function save_settings(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Unauthorized.' );
        }
        check_admin_referer( 'owsc_save_settings' );

        $old_config = self::configuration();
        $submitted_key = isset( $_POST['api_key'] ) ? trim( (string) wp_unslash( $_POST['api_key'] ) ) : '';
        
        $sync_enabled = isset( $_POST['sync_enabled'] ) ? 'yes' : 'no';
        $allowed_intervals = array( 'hourly', 'twicedaily', 'daily' );
        $sync_interval = in_array( $_POST['sync_interval'] ?? '', $allowed_intervals, true ) ? sanitize_text_field( wp_unslash( $_POST['sync_interval'] ) ) : 'hourly';

        $new_config = array(
            'url'           => esc_url_raw( trim( (string) wp_unslash( $_POST['url'] ?? '' ) ) ),
            'database'      => sanitize_text_field( wp_unslash( $_POST['database'] ?? '' ) ),
            'username'      => sanitize_text_field( wp_unslash( $_POST['username'] ?? '' ) ),
            'api_key'       => $submitted_key ? $submitted_key : $old_config['api_key'],
            'sync_enabled'  => $sync_enabled,
            'sync_interval' => $sync_interval,
        );

        update_option( self::OPTION_NAME, $new_config, false );

        // Handle Cron Scheduling
        wp_clear_scheduled_hook( 'owsc_cron_stock_sync' );
        if ( $sync_enabled === 'yes' ) {
            wp_schedule_event( time(), $sync_interval, 'owsc_cron_stock_sync' );
        }

        wp_safe_redirect( add_query_arg( array( 'page' => 'owsc-connector', 'settings-updated' => 'true' ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handle_bulk_sync(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Unauthorized.' );
        }
        check_admin_referer( 'owsc_run_bulk_sync' );

        $sync_service = new OWSC_Stock_Sync();
        $result = $sync_service->run_sync();

        set_transient( 'owsc_sync_message_' . get_current_user_id(), $result['message'], MINUTE_IN_SECONDS );
        wp_safe_redirect( add_query_arg( array( 'page' => 'owsc-connector' ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function run_scheduled_sync(): void {
        $config = self::configuration();
        if ( $config['sync_enabled'] === 'yes' ) {
            $sync_service = new OWSC_Stock_Sync();
            $sync_service->run_sync();
        }
    }
}

register_deactivation_hook( OWSC_FILE, array( 'OWSCPluginV2', 'deactivate' ) );
OWSCPluginV2::boot();
