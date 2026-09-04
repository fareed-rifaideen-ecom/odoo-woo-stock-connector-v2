<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OWSC_Stock_Sync {
    public function run_sync(): array {
        $config = OWSCPluginV2::configuration();
        if ( ! $config['url'] || ! $config['database'] || ! $config['username'] || ! $config['api_key'] ) {
            return array( 'status' => 'error', 'message' => 'Odoo configuration incomplete. Cannot run sync.' );
        }

        $client = new OWSC_Odoo_XMLRPC_Client( $config['url'] );
        $uid    = $client->authenticate( $config['database'], $config['username'], $config['api_key'] );
        
        if ( is_wp_error( $uid ) || ! is_int( $uid ) || $uid <= 0 ) {
            return array( 'status' => 'error', 'message' => 'Odoo authentication failed. Cannot run sync.' );
        }

        // 1. Fetch all Odoo products marked as eligible for WooCommerce sync
        $products = $client->execute_kw(
            $config['database'], $uid, $config['api_key'],
            'product.product', 'search_read',
            array( array( array( 'x_studio_available_for_woocommerce_sync', '=', true ) ) ),
            array( 'fields' => array( 'id', 'default_code' ) )
        );

        if ( is_wp_error( $products ) || ! is_array( $products ) || empty( $products ) ) {
            return array( 'status' => 'info', 'message' => 'No eligible products found for sync in Odoo.' );
        }

        $product_map = array(); // Map Odoo ID to SKU
        $odoo_product_ids = array();
        
        foreach ( $products as $p ) {
            if ( ! empty( $p['default_code'] ) ) {
                $product_map[ $p['id'] ] = trim( $p['default_code'] );
                $odoo_product_ids[] = $p['id'];
            }
        }

        // 2. Fetch the specific IDs for WH, MC, and JM locations
        $approved_locations = array( 'WH/Stock', 'MC/Stock', 'JM/Stock' );
        $locations = $client->execute_kw(
            $config['database'], $uid, $config['api_key'],
            'stock.location', 'search_read',
            array( array( array( 'complete_name', 'in', $approved_locations ) ) ),
            array( 'fields' => array( 'id', 'complete_name' ) )
        );

        if ( is_wp_error( $locations ) || empty( $locations ) ) {
            return array( 'status' => 'error', 'message' => 'Could not find approved warehouse locations (WH, MC, JM) in Odoo.' );
        }

        $location_ids = array_column( $locations, 'id' );

        // 3. Fetch Stock Quants for eligible products in approved locations
        $quants = $client->execute_kw(
            $config['database'], $uid, $config['api_key'],
            'stock.quant', 'search_read',
            array( array(
                array( 'product_id', 'in', $odoo_product_ids ),
                array( 'location_id', 'in', $location_ids )
            ) ),
            array( 'fields' => array( 'product_id', 'quantity', 'reserved_quantity' ) )
        );

        // Initialize all eligible SKUs to 0 stock
        $stock_totals = array(); 
        foreach ( $product_map as $sku ) {
            $stock_totals[ $sku ] = 0;
        }

        // Aggregate available stock
        if ( ! is_wp_error( $quants ) && is_array( $quants ) ) {
            foreach ( $quants as $quant ) {
                $pid = $quant['product_id'][0] ?? 0;
                if ( isset( $product_map[ $pid ] ) ) {
                    $sku = $product_map[ $pid ];
                    $available = (float) ( $quant['quantity'] ?? 0 ) - (float) ( $quant['reserved_quantity'] ?? 0 );
                    $stock_totals[ $sku ] += $available;
                }
            }
        }

        // 4. Update WooCommerce
        $updated_count = 0;
        $not_found_count = 0;

        foreach ( $stock_totals as $sku => $qty ) {
            $final_qty = max( 0, $qty ); // Prevent negative stock
            $status = $final_qty > 0 ? 'instock' : 'outofstock';
            
            $woo_product_id = wc_get_product_id_by_sku( $sku );
            if ( $woo_product_id ) {
                $product = wc_get_product( $woo_product_id );
                // Only update if the product manages stock
                if ( $product && $product->get_manage_stock() ) {
                    wc_update_product_stock( $product, $final_qty );
                    wc_update_product_stock_status( $woo_product_id, $status );
                    $updated_count++;
                }
            } else {
                $not_found_count++;
            }
        }

        return array(
            'status'  => 'success',
            'message' => sprintf( 'Bulk sync complete. %d WooCommerce products updated successfully. %d Odoo products not found in WooCommerce.', $updated_count, $not_found_count )
        );
    }
}
