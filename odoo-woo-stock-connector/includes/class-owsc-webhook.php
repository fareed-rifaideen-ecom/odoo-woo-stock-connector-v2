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
        // Prevent concurrent overlapping runs if Odoo sends multiple rapid webhooks
        if ( get_transient( 'owsc_webhook_lock' ) ) {
            return new \WP_REST_Response( array( 
                'status'  => 'skipped', 
                'message' => 'Sync already in progress. Batching request.' 
            ), 200 );
        }
        
        // Lock for 30 seconds
        set_transient( 'owsc_webhook_lock', true, 30 ); 
        
        // Execute the identical Bulk Sync engine you just tested
        $sync = new OWSC_Stock_Sync();
        $result = $sync->run_sync();
        
        // Release the lock
        delete_transient( 'owsc_webhook_lock' );
        
        return new \WP_REST_Response( $result, 200 );
    }
}
