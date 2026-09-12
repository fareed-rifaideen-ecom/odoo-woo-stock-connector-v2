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
        // CRITICAL FIX: Prevent Odoo's timeout from killing the WordPress process
        ignore_user_abort( true );
        
        $params = $request->get_json_params() ?: array();
        
        // Odoo 18 webhooks often send payloads wrapped in a list: [ { "product_id": ... } ]
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
            // FULL SYNC FALLBACK: Retain the global lock to prevent server crashes
            if ( get_transient( 'owsc_webhook_lock_global' ) ) {
                $msg = 'Full sync in progress. Skipped.';
                if ( function_exists( 'owsc_log_sync_event' ) ) {
                    owsc_log_sync_event( 'Webhook (Full)', $msg, 'skipped' );
                }
                return new \WP_REST_Response( array( 'status' => 'skipped', 'message' => $msg ), 200 );
            }
            set_transient( 'owsc_webhook_lock_global', true, 45 ); 
        } else {
            // MICRO-SYNC: Force WordPress to take a breath for 1.5 seconds.
            usleep( 1500000 ); 
        }
        
        $sync = new OWSC_Stock_Sync();
        // Pass both variables; the sync engine will prioritize dynamically
        $result = $sync->run_sync( $target_sku, $odoo_product_id ); 
        
        if ( $odoo_product_id === 0 && empty( $target_sku ) ) {
            delete_transient( 'owsc_webhook_lock_global' );
        }
        
        // ==========================================
        // CRITICAL FIX: The missing logging hook
        // ==========================================
        $source = ( $odoo_product_id === 0 && empty( $target_sku ) ) ? 'Webhook (Full)' : 'Webhook (Micro)';
        $status = isset( $result['status'] ) ? $result['status'] : 'info';
        
        if ( function_exists( 'owsc_log_sync_event' ) ) {
            owsc_log_sync_event( $source, $result['message'], $status );
        }
        // ==========================================
        
        return new \WP_REST_Response( $result, 200 );
    }
}
