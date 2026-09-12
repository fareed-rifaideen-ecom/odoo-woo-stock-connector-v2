<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OWSC_Webhook {
    
    public function register(): void {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes(): void {
        register_rest_route( 'owsc/v1', '/sync', array(
            'methods'             => \WP_REST_Server::ALLMETHODS,
            'callback'            => array( $this, 'handle_webhook' ),
            'permission_callback' => array( $this, 'verify_token' ),
        ) );
    }

    public function verify_token( \WP_REST_Request $request ): bool {
        $config = OWSCPluginV2::configuration();
        $token  = $request->get_param( 'token' );
        
        $expected_token = substr( md5( $config['url'] . $config['username'] ), 0, 16 );
        return $token === $expected_token;
    }

    public function handle_webhook( \WP_REST_Request $request ): \WP_REST_Response {
        // CRITICAL FIX: Prevent Odoo's 3-second timeout from killing the WordPress process
        ignore_user_abort( true );
        
        $params = $request->get_json_params() ?: array();
        
        // Odoo 18 webhooks often send payloads wrapped in a list: [ { "product_id": ... } ]
        $record = isset( $params[0] ) && is_array( $params[0] ) ? $params[0] : $params;
        
        $odoo_product_id = 0;
        if ( isset( $record['product_id'] ) ) {
            if ( is_array( $record['product_id'] ) && ! empty( $record['product_id'][0] ) ) {
                $odoo_product_id = (int) $record['product_id'][0];
            } elseif ( is_numeric( $record['product_id'] ) ) {
                $odoo_product_id = (int) $record['product_id'];
            }
        }

        if ( $odoo_product_id === 0 ) {
            // FULL SYNC FALLBACK: Retain the global lock to prevent server crashes
            if ( get_transient( 'owsc_webhook_lock_global' ) ) {
                return new \WP_REST_Response( array( 'status' => 'skipped', 'message' => 'Full sync in progress.' ), 200 );
            }
            set_transient( 'owsc_webhook_lock_global', true, 45 ); 
        } else {
            // MICRO-SYNC: Force WordPress to take a breath for 1.5 seconds.
            usleep( 1500000 ); 
        }
        
        $sync = new OWSC_Stock_Sync();
        $result = $sync->run_sync( '', $odoo_product_id ); 
        
        if ( $odoo_product_id === 0 ) {
            delete_transient( 'owsc_webhook_lock_global' );
        }
        
        return new \WP_REST_Response( $result, 200 );
    }
}
