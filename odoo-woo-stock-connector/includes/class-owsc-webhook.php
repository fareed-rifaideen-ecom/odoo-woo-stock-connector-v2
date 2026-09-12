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
        
        // Generate a secure, unique token based on your configuration
        $expected_token = substr( md5( $config['url'] . $config['username'] ), 0, 16 );
        
        return $token === $expected_token;
    }

    public function handle_webhook( \WP_REST_Request $request ): \WP_REST_Response {
        // Parse the incoming JSON payload from Odoo
        $params = $request->get_json_params();
        $target_sku = isset( $params['sku'] ) ? sanitize_text_field( $params['sku'] ) : '';

        // Dynamic Locking: Use a specific lock if a SKU is provided, otherwise use the global lock
        $lock_name = $target_sku ? 'owsc_webhook_lock_' . md5( $target_sku ) : 'owsc_webhook_lock';

        // Prevent concurrent overlapping runs for the exact same product/sync
        if ( get_transient( $lock_name ) ) {
            return new \WP_REST_Response( array( 
                'status'  => 'skipped', 
                'message' => sprintf( 'Sync already in progress for %s. Batching request.', $target_sku ?: 'full catalog' ) 
            ), 200 );
        }
        
        // Lock for 30 seconds
        set_transient( $lock_name, true, 30 ); 
        
        // Execute the Sync engine, passing the specific SKU (if one exists)
        $sync = new OWSC_Stock_Sync();
        $result = $sync->run_sync( $target_sku );
        
        // Release the lock
        delete_transient( $lock_name );
        
        return new \WP_REST_Response( $result, 200 );
    }
}
