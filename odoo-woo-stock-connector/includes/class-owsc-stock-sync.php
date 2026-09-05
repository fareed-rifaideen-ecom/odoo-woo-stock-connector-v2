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

        // 1. Fetch eligible Odoo products
        $products = $client->execute_kw(
            $config['database'], $uid, $config['api_key'],
            'product.product', 'search_read',
            array( array( array( 'x_studio_available_for_woocommerce_sync', '=', true ) ) ),
            array( 'fields' => array( 'id', 'default_code', 'product_tmpl_id' ) )
        );

        if ( is_wp_error( $products ) || ! is_array( $products ) || empty( $products ) ) {
            return array( 'status' => 'info', 'message' => 'No eligible products found for sync in Odoo.' );
        }

        $product_map      = array(); 
        $odoo_product_ids = array();
        $odoo_tmpl_ids    = array();
        $tmpl_to_sku_map  = array();
        
        foreach ( $products as $p ) {
            if ( ! empty( $p['default_code'] ) ) {
                $sku = trim( $p['default_code'] );
                $product_map[ $p['id'] ] = $sku;
                $odoo_product_ids[] = $p['id'];
                
                if ( isset( $p['product_tmpl_id'][0] ) ) {
                    $odoo_tmpl_ids[] = $p['product_tmpl_id'][0];
                    $tmpl_to_sku_map[ $p['product_tmpl_id'][0] ] = $sku;
                }
            }
        }

        // 2. Fetch specific IDs for WH, MC, and JM locations
        $approved_locations = array( 'WH/Stock', 'MC/Stock', 'JM/Stock' );
        $locations = $client->execute_kw(
            $config['database'], $uid, $config['api_key'],
            'stock.location', 'search_read',
            array( array( array( 'complete_name', 'in', $approved_locations ) ) ),
            array( 'fields' => array( 'id', 'complete_name' ) )
        );

        $location_ids = array_column( (array) $locations, 'id' );

        // 3. Fetch Stock Quants
        $quants = $client->execute_kw(
            $config['database'], $uid, $config['api_key'],
            'stock.quant', 'search_read',
            array( array(
                array( 'product_id', 'in', $odoo_product_ids ),
                array( 'location_id', 'in', $location_ids )
            ) ),
            array( 'fields' => array( 'product_id', 'quantity', 'reserved_quantity' ) )
        );

        $stock_totals = array(); 
        foreach ( $product_map as $sku ) {
            $stock_totals[ $sku ] = 0;
        }

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

        // 3.5 Dynamic Subtraction for Unconfirmed Drafts
        $tag_ids = array();
        $tags = $client->execute_kw( 
            $config['database'], $uid, $config['api_key'], 
            'crm.tag', 'search_read', 
            array( array( array( 'name', '=', 'Online Order' ) ) ), 
            array( 'fields' => array( 'id' ), 'limit' => 1 ) 
        );
        
        if ( ! is_wp_error( $tags ) && ! empty( $tags ) ) {
            $tag_ids[] = (int) $tags[0]['id'];
        }

        if ( ! empty( $tag_ids ) ) {
            $draft_orders = $client->execute_kw(
                $config['database'], $uid, $config['api_key'],
                'sale.order', 'search_read',
                array( array(
                    array( 'state', 'in', array( 'draft', 'sent' ) ),
                    array( 'tag_ids', 'in', $tag_ids ),
                    array( 'client_order_ref', 'ilike', 'WOO-' ) 
                ) ),
                array( 'fields' => array( 'id' ) )
            );

            if ( ! is_wp_error( $draft_orders ) && is_array( $draft_orders ) && ! empty( $draft_orders ) ) {
                $draft_order_ids = array_column( $draft_orders, 'id' );
                $draft_lines = $client->execute_kw(
                    $config['database'], $uid, $config['api_key'],
                    'sale.order.line', 'search_read',
                    array( array(
                        array( 'order_id', 'in', $draft_order_ids ),
                        array( 'product_id', 'in', $odoo_product_ids ) 
                    ) ),
                    array( 'fields' => array( 'product_id', 'product_uom_qty' ) )
                );

                if ( ! is_wp_error( $draft_lines ) && is_array( $draft_lines ) ) {
                    foreach ( $draft_lines as $line ) {
                        $pid = $line['product_id'][0] ?? 0;
                        if ( isset( $product_map[ $pid ] ) ) {
                            $sku = $product_map[ $pid ];
                            $draft_qty = (float) ( $line['product_uom_qty'] ?? 0 );
                            $stock_totals[ $sku ] -= $draft_qty;
                        }
                    }
                }
            }
        }

        // --- NEW: 3.8 Fetch Prices using Explicit Pricelist ID ---
        $sku_prices = array();
        $pricelist_diagnostic = '';
        
        $pricelist_id = isset( $config['pricelist_id'] ) ? (int) $config['pricelist_id'] : 0;
        
        if ( $config['sync_price'] === 'yes' && $pricelist_id > 0 ) {
            
            $pricelist_items = $client->execute_kw(
                $config['database'], $uid, $config['api_key'],
                'product.pricelist.item', 'search_read',
                array( array(
                    array( 'pricelist_id', '=', $pricelist_id ),
                    '|',
                    array( 'product_id', 'in', $odoo_product_ids ),
                    array( 'product_tmpl_id', 'in', $odoo_tmpl_ids )
                ) ),
                array( 'fields' => array( 'product_id', 'product_tmpl_id', 'fixed_price' ) )
            );

            if ( ! is_wp_error( $pricelist_items ) && is_array( $pricelist_items ) ) {
                $pricelist_diagnostic = sprintf( ' [Pricelist ID %d Connected: Found %d price rules]', $pricelist_id, count( $pricelist_items ) );
                
                foreach ( $pricelist_items as $item ) {
                    $price = isset( $item['fixed_price'] ) ? (float) $item['fixed_price'] : 0;
                    if ( $price <= 0 ) continue;

                    if ( ! empty( $item['product_id'][0] ) && isset( $product_map[ $item['product_id'][0] ] ) ) {
                        $sku = $product_map[ $item['product_id'][0] ];
                        $sku_prices[ $sku ] = $price;
                    } elseif ( ! empty( $item['product_tmpl_id'][0] ) && isset( $tmpl_to_sku_map[ $item['product_tmpl_id'][0] ] ) ) {
                        $sku = $tmpl_to_sku_map[ $item['product_tmpl_id'][0] ];
                        if ( ! isset( $sku_prices[ $sku ] ) ) {
                            $sku_prices[ $sku ] = $price;
                        }
                    }
                }
            } else {
                $pricelist_diagnostic = sprintf( ' [Error: Could not extract rules for Pricelist ID %d]', $pricelist_id );
            }
        }

        // 4. Update WooCommerce
        $updated_stock_count = 0;
        $updated_price_count = 0;

        foreach ( $stock_totals as $sku => $qty ) {
            $final_qty = max( 0, $qty ); 
            $status = $final_qty > 0 ? 'instock' : 'outofstock';
            
            $woo_product_id = wc_get_product_id_by_sku( $sku );
            if ( $woo_product_id ) {
                $product = wc_get_product( $woo_product_id );
                if ( $product ) {
                    $product_changed = false;

                    // Update Stock
                    if ( $product->get_manage_stock() && (float) $product->get_stock_quantity() !== (float) $final_qty ) {
                        $product->set_stock_quantity( $final_qty );
                        $product->set_stock_status( $status );
                        $updated_stock_count++;
                        $product_changed = true;
                    }

                    // Update Price (Force updating both Regular Price and Active Price)
                    if ( $config['sync_price'] === 'yes' && isset( $sku_prices[ $sku ] ) ) {
                        $target_price = (string) $sku_prices[ $sku ];
                        if ( $product->get_regular_price() !== $target_price ) {
                            $product->set_regular_price( $target_price );
                            $product->set_price( $target_price ); 
                            $updated_price_count++;
                            $product_changed = true;
                        }
                    }

                    // Save if modified
                    if ( $product_changed ) {
                        $product->save();
                    }
                }
            }
        }

        return array(
            'status'  => 'success',
            'message' => sprintf( 'Sync complete. Updated Stock for %d items. Updated Prices for %d items.%s', $updated_stock_count, $updated_price_count, $pricelist_diagnostic )
        );
    }
}
