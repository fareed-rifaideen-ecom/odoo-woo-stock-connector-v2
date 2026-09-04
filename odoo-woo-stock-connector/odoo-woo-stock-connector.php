<?php
/**
 * Plugin Name: Odoo WooCommerce Stock Connector V2
 * Plugin URI: https://github.com/fareed-rifaideen-ecom/odoo-woo-stock-connector-v2
 * Description: V2 foundation for a secure Odoo 18 and WooCommerce inventory connector.
 * Version: 2.1.0
 * Author: Fareed M. Rifaideen
 * Author URI: https://fareed-rifaideen.netlify.app/
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'OWSC_VERSION', '2.1.0' );
define( 'OWSC_FILE', __FILE__ );
define( 'OWSC_DIR', plugin_dir_path( __FILE__ ) );

// Load dependencies
require_once OWSC_DIR . 'includes/class-owsc-odoo-xmlrpc-client.php';
require_once OWSC_DIR . 'includes/class-owsc-connection-test.php';
require_once OWSC_DIR . 'includes/class-owsc-sku-audit.php';
require_once OWSC_DIR . 'includes/class-owsc-sku-audit-admin.php';

final class OWSCPluginV2 {
    const OPTION_NAME = 'owsc_odoo_settings';

    public static function boot(): void {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
        add_action( 'admin_post_owsc_save_settings', array( __CLASS__, 'save_settings' ) );
        
        // Register the connection test handler
        OWSC_Connection_Test::instance()->register();

        // Register the SKU Audit UI
        new OWSC_SKU_Audit_Admin();
    }

    public static function configuration(): array {
        $settings = get_option( self::OPTION_NAME, array() );
        return array(
            'url'      => (string) ( $settings['url'] ?? '' ),
            'database' => (string) ( $settings['database'] ?? '' ),
            'username' => (string) ( $settings['username'] ?? '' ),
            'api_key'  => (string) ( $settings['api_key'] ?? '' ),
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

        ?>
        <div class="wrap">
            <h1>Odoo WooCommerce Stock Connector V2</h1>
            <?php OWSC_Connection_Test::instance()->render_notice(); ?>
            <p><strong>Phase:</strong> Dashboard-managed configuration. Enter your Odoo 18 staging credentials below.</p>
            
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
                        <td>
                            <input name="url" id="odoo_url" class="regular-text" type="url" value="<?php echo esc_attr( $config['url'] ); ?>" required placeholder="https://your-odoo-domain.example">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="odoo_database">Odoo Database</label></th>
                        <td>
                            <input name="database" id="odoo_database" class="regular-text" type="text" value="<?php echo esc_attr( $config['database'] ); ?>" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="odoo_username">Odoo Username</label></th>
                        <td>
                            <input name="username" id="odoo_username" class="regular-text" type="text" value="<?php echo esc_attr( $config['username'] ); ?>" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="odoo_api_key">Odoo API Key</label></th>
                        <td>
                            <input name="api_key" id="odoo_api_key" type="password" class="regular-text" value="" placeholder="<?php echo $config['api_key'] ? 'Saved (hidden)' : 'Enter API key'; ?>">
                            <p class="description">Leave blank to retain the currently saved API key.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Save Connection Settings' ); ?>
            </form>

            <hr />
            <h2>Connection Test</h2>
            <p>This test only calls Odoo's version and authenticate methods. It does not read or change any business data.</p>
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

        $new_config = array(
            'url'      => esc_url_raw( trim( (string) wp_unslash( $_POST['url'] ?? '' ) ) ),
            'database' => sanitize_text_field( wp_unslash( $_POST['database'] ?? '' ) ),
            'username' => sanitize_text_field( wp_unslash( $_POST['username'] ?? '' ) ),
            'api_key'  => $submitted_key ? $submitted_key : $old_config['api_key'],
        );

        update_option( self::OPTION_NAME, $new_config, false );

        wp_safe_redirect( add_query_arg( array(
            'page'             => 'owsc-connector',
            'settings-updated' => 'true'
        ), admin_url( 'admin.php' ) ) );
        exit;
    }
}

OWSCPluginV2::boot();
