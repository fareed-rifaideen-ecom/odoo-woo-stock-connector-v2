<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OWSC_Webhook {
    
    public function register(): void {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
        // Register the background worker hook
        add_action( 'owsc_execute_background_sync', array( $this, 'background_sync_worker' ), 10, 2 );
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
        $record = isset( $params[0] ) && is_array( $params[0] ) ? $params[0] : $params;
        
        // 1. Check for standard Inventory Webhook (Product Variant ID)
        $odoo_product_id = 0;
        if ( isset( $record['product_id'] ) ) {
            if ( is_array( $record['product_id'] ) && ! empty( $record['product_id'][0] ) ) {
                $odoo_product_id = (int) $record['product_id'][0];
            } elseif ( is_numeric( $record['product_id'] ) ) {
                $odoo_product_id = (int) $record['product_id'];
            }
        }

        // 2. Check for Force OOS Webhook (Product Template SKU)
        $target_sku = '';
        if ( isset( $record['default_code'] ) && is_string( $record['default_code'] ) ) {
            $target_sku = sanitize_text_field( $record['default_code'] );
        }

        // 3. Routing Logic (Full vs Micro)
        if ( $odoo_product_id === 0 && empty( $target_sku ) ) {
            // FULL SYNC FALLBACK
            if ( get_transient( 'owsc_webhook_lock_global' ) ) {
                return new \WP_REST_Response( array( 'status' => 'skipped', 'message' => 'Full sync in progress.' ), 200 );
            }
            set_transient( 'owsc_webhook_lock_global', true, 45 ); 
            
            // CRITICAL FIX: Offload to background cron to prevent Odoo's timeout from killing the process
            wp_schedule_single_event( time(), 'owsc_execute_background_sync', array( '', 0 ) );
            
            // Return instantly so Odoo is happy
            return new \WP_REST_Response( array( 'status' => 'success', 'message' => 'Full sync triggered in background.' ), 200 );
        } else {
            // MICRO-SYNC: Fast enough to run synchronously
            $sync = new OWSC_Stock_Sync();
            $result = $sync->run_sync( $target_sku, $odoo_product_id ); 
            
            $status = isset( $result['status'] ) ? $result['status'] : 'info';
            if ( function_exists( 'owsc_log_sync_event' ) ) {
                owsc_log_sync_event( 'Webhook (Micro)', $result['message'], $status );
            }
            return new \WP_REST_Response( $result, 200 );
        }
    }

    // The background worker that runs completely detached from Odoo's 3-second limit
    public function background_sync_worker( $target_sku, $odoo_product_id ) {
        $sync = new OWSC_Stock_Sync();
        $result = $sync->run_sync( $target_sku, $odoo_product_id ); 
        
        delete_transient( 'owsc_webhook_lock_global' );
        
        $status = isset( $result['status'] ) ? $result['status'] : 'info';
        if ( function_exists( 'owsc_log_sync_event' ) ) {
            owsc_log_sync_event( 'Webhook (Full)', $result['message'], $status );
        }
    }
}
