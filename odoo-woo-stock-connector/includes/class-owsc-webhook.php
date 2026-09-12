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
        $params = $request->get_json_params() ?: array();
        
        // Extract Product ID from Odoo's native webhook payload 
        $odoo_product_id = 0;
        if ( isset( $params['product_id'] ) && is_array( $params['product_id'] ) && ! empty( $params['product_id'][0] ) ) {
            $odoo_product_id = (int) $params['product_id'][0];
        }

        // Dynamic Locking based on Odoo Product ID
        $lock_name = $odoo_product_id > 0 ? 'owsc_webhook_lock_pid_' . $odoo_product_id : 'owsc_webhook_lock';

        if ( get_transient( $lock_name ) ) {
            return new \WP_REST_Response( array( 
                'status'  => 'skipped', 
                'message' => sprintf( 'Sync already in progress for %s. Batching request.', $odoo_product_id > 0 ? 'Product ID ' . $odoo_product_id : 'full catalog' ) 
            ), 200 );
        }
        
        set_transient( $lock_name, true, 30 ); 
        
        $sync = new OWSC_Stock_Sync();
        // Pass the exact Odoo Product ID to the sync engine
        $result = $sync->run_sync( '', $odoo_product_id ); 
        
        delete_transient( $lock_name );
        
        return new \WP_REST_Response( $result, 200 );
    }
}
