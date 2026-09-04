<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OWSC_Connection_Test {
    private static $instance = null;
    const NONCE_ACTION = 'owsc_test_connection';

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function register() {
        add_action( 'admin_post_owsc_test_connection', array( $this, 'handle_test' ) );
    }

    public function render_test_form() {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( self::NONCE_ACTION ); ?>
            <input type="hidden" name="action" value="owsc_test_connection" />
            <?php submit_button( 'Test Odoo Connection', 'secondary' ); ?>
        </form>
        <?php
    }

    public function render_notice() {
        $result = isset( $_GET['owsc_result'] ) ? sanitize_text_field( wp_unslash( $_GET['owsc_result'] ) ) : '';
        if ( '' === $result ) {
            return;
        }
        
        $message = isset( $_GET['owsc_message'] ) ? sanitize_text_field( wp_unslash( $_GET['owsc_message'] ) ) : '';
        $class   = ( 'ok' === $result ) ? 'notice-success' : 'notice-error';
        
        printf( '<div class="notice %1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
    }

    public function handle_test() {
        if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( self::NONCE_ACTION ) ) {
            wp_die( 'Unauthorized request.' );
        }

        $config   = OWSCPluginV2::configuration();
        $url      = $config['url'];
        $db       = $config['database'];
        $username = $config['username'];
        $api_key  = $config['api_key'];

        $result  = 'error';
        $message = 'Odoo connection failed. Check the saved URL, database, username, and API key.';

        if ( $url && $db && $username && $api_key ) {
            $client  = new OWSC_Odoo_XMLRPC_Client( $url );
            $version = $client->version();
            $uid     = $client->authenticate( $db, $username, $api_key );

            if ( ! is_wp_error( $version ) && ! is_wp_error( $uid ) && is_int( $uid ) && $uid > 0 ) {
                $server_version = is_array( $version ) && isset( $version['server_version'] ) ? $version['server_version'] : 'unknown';
                $result         = 'ok';
                $message        = sprintf( 'Odoo connection successful. Server version: %1$s. Authenticated user ID: %2$d.', $server_version, $uid );
            } elseif ( is_wp_error( $version ) ) {
                $message = $version->get_error_message();
            } elseif ( is_wp_error( $uid ) ) {
                $message = $uid->get_error_message();
            }
        } else {
            $message = 'Odoo connection failed. One or more required settings are empty.';
        }

        $redirect = add_query_arg( array(
            'page'         => 'owsc-connector',
            'owsc_result'  => $result,
            'owsc_message' => rawurlencode( $message ),
        ), admin_url( 'admin.php' ) );

        wp_safe_redirect( $redirect );
        exit;
    }
}
