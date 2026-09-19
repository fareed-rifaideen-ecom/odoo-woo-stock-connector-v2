<?php
/**
 * Plugin Name: Odoo WooCommerce Stock Connector V2
 * Plugin URI: https://github.com/fareed-rifaideen-ecom/odoo-woo-stock-connector-v2
 * Description: V2 foundation for a secure Odoo 18 and WooCommerce inventory connector.
 * Version: 2.6.0
 * Author: Fareed M. Rifaideen
 * Author URI: https://fareed-rifaideen.netlify.app/
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'OWSC_VERSION', '2.6.0' );
define( 'OWSC_FILE', __FILE__ );
define( 'OWSC_DIR', plugin_dir_path( __FILE__ ) );

require_once OWSC_DIR . 'includes/class-owsc-odoo-xmlrpc-client.php';
require_once OWSC_DIR . 'includes/class-owsc-connection-test.php';
require_once OWSC_DIR . 'includes/class-owsc-sku-audit.php';
require_once OWSC_DIR . 'includes/class-owsc-sku-audit-admin.php';
require_once OWSC_DIR . 'includes/class-owsc-stock-sync.php';
require_once OWSC_DIR . 'includes/class-owsc-order-import.php';
require_once OWSC_DIR . 'includes/class-owsc-webhook.php';

// ==========================================
// LOGGING SYSTEM: Schema & Helper Functions
// ==========================================

function owsc_create_log_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'owsc_logs';
    $charset_collate = $wpdb->get_charset_collate();

    // Note: dbDelta strictly requires exactly two spaces after PRIMARY KEY
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        trigger_source varchar(50) NOT NULL,
        status varchar(20) DEFAULT 'info' NOT NULL,
        message text NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
}
register_activation_hook( OWSC_FILE, 'owsc_create_log_table' );

function owsc_log_sync_event( string $trigger_source, string $message, string $status = 'info' ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'owsc_logs';

    $wpdb->insert( $table_name, array(
        'created_at'     => current_time( 'mysql' ),
        'trigger_source' => $trigger_source,
        'status'         => $status,
        'message'        => sanitize_text_field( $message )
    ) );

    $wpdb->query( $wpdb->prepare(
        "DELETE FROM $table_name WHERE created_at < %s",
        gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) )
    ) );
}

// ==========================================
// MAIN PLUGIN CLASS
// ==========================================

final class OWSCPluginV2 {
    const OPTION_NAME = 'owsc_odoo_settings';

    public static function boot(): void {
        add_filter( 'cron_schedules', array( __CLASS__, 'add_custom_cron_schedule' ) );
        
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
        add_action( 'admin_post_owsc_save_settings', array( __CLASS__, 'save_settings' ) );
        add_action( 'admin_post_owsc_run_bulk_sync', array( __CLASS__, 'handle_bulk_sync' ) );
        
        add_action( 'owsc_cron_stock_sync', array( __CLASS__, 'run_scheduled_sync' ) );
        
        OWSC_Connection_Test::instance()->register();
        new OWSC_SKU_Audit_Admin();

        if ( class_exists( 'OWSC_Order_Import' ) ) {
            ( new OWSC_Order_Import() )->register();
        }
        
        if ( class_exists( 'OWSC_Webhook' ) ) {
            ( new OWSC_Webhook() )->register();
        }
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook( 'owsc_cron_stock_sync' );
    }

    public static function add_custom_cron_schedule( $schedules ) {
        $settings = get_option( self::OPTION_NAME, array() );
        $minutes  = max( 1, (int) ( $settings['cron_interval'] ?? 5 ) );
        
        $schedules['owsc_custom_interval'] = array(
            'interval' => $minutes * 60, 
            'display'  => sprintf( 'Every %d Minutes (Odoo Sync)', $minutes )
        );
        return $schedules;
    }

    public static function configuration(): array {
        $settings = get_option( self::OPTION_NAME, array() );
        return array(
            'url'                => (string) ( $settings['url'] ?? '' ),
            'database'           => (string) ( $settings['database'] ?? '' ),
            'username'           => (string) ( $settings['username'] ?? '' ),
            'api_key'            => (string) ( $settings['api_key'] ?? '' ),
            'sync_enabled'       => (string) ( $settings['sync_enabled'] ?? 'no' ),
            'cron_interval'      => (int) ( $settings['cron_interval'] ?? 5 ), 
            'priority_jm'        => (string) ( $settings['priority_jm'] ?? 'JM,MC,WH' ), 
            'priority_mc'        => (string) ( $settings['priority_mc'] ?? 'MC,WH,JM' ), 
            'priority_uae'       => (string) ( $settings['priority_uae'] ?? 'WH,MC,JM' ), 
            'auto_confirm'       => (string) ( $settings['auto_confirm'] ?? 'no' ),
            'sync_price'         => (string) ( $settings['sync_price'] ?? 'no' ),
            'pricelist_id'       => (int) ( $settings['pricelist_id'] ?? 0 ),
            'customer_sync_mode' => (string) ( $settings['customer_sync_mode'] ?? 'strict_reuse' ),
        );
    }

    public static function register_menu(): void {
        add_menu_page( 
            'Odoo WooCommerce Connector', 
            'Odoo Sync', 
            'manage_woocommerce', 
            'owsc-connector', 
            array( __CLASS__, 'render_page' ),
            'dashicons-update',
            56 
        );

        add_submenu_page( 
            'owsc-connector', 
            'Odoo Connector Settings', 
            'Settings', 
            'manage_woocommerce', 
            'owsc-connector', 
            array( __CLASS__, 'render_page' ) 
        );

        add_submenu_page( 
            'owsc-connector', 
            'Sync Logs', 
            'Sync Logs', 
            'manage_woocommerce', 
            'owsc-logs', 
            array( __CLASS__, 'render_log_page' ) 
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

        $webhook_token = substr( md5( $config['url'] . $config['username'] ), 0, 16 );
        $webhook_url   = site_url( '/wp-json/owsc/v1/sync?token=' . $webhook_token );

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
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="customer_sync_mode">Customer Sync Mode</label></th>
                        <td>
                            <select name="customer_sync_mode" id="customer_sync_mode">
                                <option value="strict_reuse" <?php selected( $config['customer_sync_mode'], 'strict_reuse' ); ?>>Option 2: Strict Fallback (Reuse matched contact, never overwrite)</option>
                                <option value="create_child" <?php selected( $config['customer_sync_mode'], 'create_child' ); ?>>Option 1: Create Child Contact (For alternative delivery addresses)</option>
                            </select>
                            <p class="description">Select how Odoo handles WooCommerce shipping addresses that differ from the main customer profile.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sync_enabled">Automated Stock Sync</label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="sync_enabled" id="sync_enabled" value="yes" <?php checked( $config['sync_enabled'], 'yes' ); ?>>
                                Enable automatic background stock synchronization (Cron)
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cron_interval">Cron Interval (Minutes)</label></th>
                        <td>
                            <input name="cron_interval" id="cron_interval" class="small-text" type="number" min="1" value="<?php echo esc_attr( $config['cron_interval'] ); ?>">
                            <p class="description">How often the background sync should run. (Requires a server-level cron job triggering WP-Cron).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Routing Priority: Jumeirah Pickup</label></th>
                        <td><input name="priority_jm" class="regular-text" type="text" value="<?php echo esc_attr( $config['priority_jm'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Routing Priority: Motor City Pickup</label></th>
                        <td><input name="priority_mc" class="regular-text" type="text" value="<?php echo esc_attr( $config['priority_mc'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Routing Priority: UAE Delivery</label></th>
                        <td><input name="priority_uae" class="regular-text" type="text" value="<?php echo esc_attr( $config['priority_uae'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="auto_confirm">Order Import Rules</label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="auto_confirm" id="auto_confirm" value="yes" <?php checked( $config['auto_confirm'], 'yes' ); ?>>
                                Enable Warehouse Routing & Auto-Confirmation
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sync_price"><strong>Price Synchronization</strong></label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="sync_price" id="sync_price" value="yes" <?php checked( $config['sync_price'], 'yes' ); ?>>
                                Enable Price Sync from Odoo Pricelist
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="pricelist_id">Target Pricelist ID</label></th>
                        <td>
                            <input name="pricelist_id" id="pricelist_id" class="small-text" type="number" value="<?php echo esc_attr( $config['pricelist_id'] ? $config['pricelist_id'] : '' ); ?>">
                            <p class="description">Enter the numeric ID of the Pricelist. <em>(To find it: Open the Pricelist in Odoo and look at the URL for <code>id=XX</code>)</em></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Save Connection Settings' ); ?>
            </form>

            <hr />
            <h2>Real-Time Sync (Odoo Webhook)</h2>
            <p>To update WooCommerce instantly when stock changes in Odoo, create an Automated Action in Odoo Studio targeting the <code>stock.quant</code> model. Use this secure URL:</p>
            <p><code><?php echo esc_url( $webhook_url ); ?></code></p>
            
            <hr />
            <h2>Bulk Stock & Price Synchronization</h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="owsc_run_bulk_sync">
                <?php wp_nonce_field( 'owsc_run_bulk_sync' ); ?>
                <?php submit_button( 'Run Full Manual Sync', 'primary', 'submit', false ); ?>
            </form>

        </div>
        <?php
    }

    public static function render_log_page(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'owsc_logs';
        
        if ( $wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name ) {
            echo '<div class="wrap"><h1>Odoo Sync Logs</h1><div class="notice notice-error"><p>The log database table is missing. <strong>Please deactivate and immediately reactivate the plugin to generate it.</strong></p></div></div>';
            return;
        }

        $logs = $wpdb->get_results( "SELECT * FROM $table_name ORDER BY created_at DESC LIMIT 500" );
        ?>
        <div class="wrap">
            <h1>Odoo Sync Logs (Last 7 Days)</h1>
            <p class="description">This table automatically deletes logs older than 7 days to preserve server performance.</p>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 15%">Date/Time</th>
                        <th style="width: 15%">Source</th>
                        <th style="width: 10%">Status</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $logs ) ) : ?>
                        <tr><td colspan="4">No logs found.</td></tr>
                    <?php else : ?>
                        <?php foreach ( $logs as $log ) : 
                            $color = $log->status === 'error' ? 'color: red; font-weight: bold;' : ($log->status === 'skipped' ? 'color: orange;' : 'color: green;');
                        ?>
                            <tr>
                                <td><?php echo esc_html( $log->created_at ); ?></td>
                                <td><strong><?php echo esc_html( $log->trigger_source ); ?></strong></td>
                                <td style="<?php echo $color; ?>"><?php echo strtoupper( esc_html( $log->status ) ); ?></td>
                                <td><?php echo esc_html( $log->message ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
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
        
        $new_config = array(
            'url'                => esc_url_raw( trim( (string) wp_unslash( $_POST['url'] ?? '' ) ) ),
            'database'           => sanitize_text_field( wp_unslash( $_POST['database'] ?? '' ) ),
            'username'           => sanitize_text_field( wp_unslash( $_POST['username'] ?? '' ) ),
            'api_key'            => $submitted_key ? $submitted_key : $old_config['api_key'],
            'sync_enabled'       => isset( $_POST['sync_enabled'] ) ? 'yes' : 'no',
            'cron_interval'      => max( 1, (int) ( $_POST['cron_interval'] ?? 5 ) ), 
            'priority_jm'        => sanitize_text_field( wp_unslash( $_POST['priority_jm'] ?? 'JM,MC,WH' ) ), 
            'priority_mc'        => sanitize_text_field( wp_unslash( $_POST['priority_mc'] ?? 'MC,WH,JM' ) ), 
            'priority_uae'       => sanitize_text_field( wp_unslash( $_POST['priority_uae'] ?? 'WH,MC,JM' ) ), 
            'auto_confirm'       => isset( $_POST['auto_confirm'] ) ? 'yes' : 'no',
            'sync_price'         => isset( $_POST['sync_price'] ) ? 'yes' : 'no',
            'pricelist_id'       => (int) ( $_POST['pricelist_id'] ?? 0 ),
            'customer_sync_mode' => sanitize_text_field( wp_unslash( $_POST['customer_sync_mode'] ?? 'strict_reuse' ) ),
        );

        update_option( self::OPTION_NAME, $new_config, false );

        wp_clear_scheduled_hook( 'owsc_cron_stock_sync' );
        if ( $new_config['sync_enabled'] === 'yes' ) {
            wp_schedule_event( time(), 'owsc_custom_interval', 'owsc_cron_stock_sync' );
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
        
        $status = isset( $result['status'] ) ? $result['status'] : 'info';
        owsc_log_sync_event( 'Manual Sync', $result['message'], $status );

        set_transient( 'owsc_sync_message_' . get_current_user_id(), $result['message'], MINUTE_IN_SECONDS );
        wp_safe_redirect( add_query_arg( array( 'page' => 'owsc-connector' ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function run_scheduled_sync(): void {
        $config = self::configuration();
        if ( $config['sync_enabled'] === 'yes' ) {
            $sync_service = new OWSC_Stock_Sync();
            $result = $sync_service->run_sync();
            
            $status = isset( $result['status'] ) ? $result['status'] : 'info';
            owsc_log_sync_event( 'WP-Cron', $result['message'], $status );
        }
    }
}

register_deactivation_hook( OWSC_FILE, array( 'OWSCPluginV2', 'deactivate' ) );
OWSCPluginV2::boot();
