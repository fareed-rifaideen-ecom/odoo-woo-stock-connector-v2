<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OWSC_Order_Import {
    
    public function register(): void {
        add_action( 'woocommerce_order_status_processing', array( $this, 'capture_processing_order' ), 10, 2 );
    }

    public function capture_processing_order( int $order_id, \WC_Order $order ): void {
        // 1. Idempotency Check
        $import_status = $order->get_meta( '_owsc_odoo_import_status' );
        if ( 'processing' === $import_status || 'completed' === $import_status ) {
            return;
        }

        $order->update_meta_data( '_owsc_odoo_import_status', 'processing' );
        $order->save_meta_data();

        // 2. Extract Customer Data (UPDATED: Added Shipping Address fields)
        $customer_data = array(
            'email'   => $order->get_billing_email(),
            'phone'   => $order->get_billing_phone(),
            'name'    => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
            'street'  => $order->get_shipping_address_1(),
            'city'    => $order->get_shipping_city(),
            'zip'     => $order->get_shipping_postcode(),
            'country' => $order->get_shipping_country(), // e.g., 'AE'
        );

        // 3. Extract Line Items & SKUs
        $items_data = array();
        foreach ( $order->get_items() as $item_id => $item ) {
            $product = $item->get_product();
            if ( $product && $product->get_sku() ) {
                $items_data[] = array(
                    'sku'      => $product->get_sku(),
                    'quantity' => $item->get_quantity(),
                    'price'    => $order->get_item_total( $item, false, false ), 
                );
            }
        }

        if ( empty( $items_data ) ) {
            $order->add_order_note( 'Odoo Connector: Ignored. No valid SKUs found in order.' );
            return;
        }

        // 4. Execute Phase 2 Import
        $this->import_to_odoo( $order, $customer_data, $items_data );
    }

    private function import_to_odoo( \WC_Order $order, array $customer_data, array $items_data ): void {
        $config = OWSCPluginV2::configuration();
        if ( ! $config['url'] || ! $config['database'] || ! $config['username'] || ! $config['api_key'] ) {
            $order->add_order_note( 'Odoo Connector Exception: Odoo configuration is incomplete.' );
            return;
        }

        $is_auto_confirm_enabled = ( $config['auto_confirm'] === 'yes' );

        $client = new OWSC_Odoo_XMLRPC_Client( $config['url'] );
        $uid    = $client->authenticate( $config['database'], $config['username'], $config['api_key'] );
        
        if ( is_wp_error( $uid ) || ! is_int( $uid ) || $uid <= 0 ) {
            $order->add_order_note( 'Odoo Connector Exception: Odoo authentication failed.' );
            return;
        }

        // Step A: Resolve Customer (UPDATED: Now processes full address)
        $partner_id = $this->resolve_customer( $client, $config, $uid, $customer_data );
        if ( ! $partner_id ) {
            $order->add_order_note( 'Odoo Connector Exception: Could not resolve or create customer in Odoo.' );
            return;
        }

        // Step B: Resolve SKUs to Odoo Product IDs
        $skus = array_column( $items_data, 'sku' );
        $odoo_products = $client->execute_kw( 
            $config['database'], $uid, $config['api_key'], 
            'product.product', 'search_read', 
            array( array( array( 'default_code', 'in', $skus ) ) ), 
            array( 'fields' => array( 'id', 'default_code' ) ) 
        );

        if ( is_wp_error( $odoo_products ) ) {
            $order->add_order_note( 'Odoo Connector Exception: Failed to query products in Odoo.' );
            return;
        }

        $odoo_product_map = array();
        foreach ( $odoo_products as $op ) {
            $odoo_product_map[ $op['default_code'] ] = $op['id'];
        }

        // --- UPDATED Step C: Determine Warehouse Routing (Dynamic Priority) ---
        $shipping_methods = $order->get_shipping_methods();
        $shipping_name    = reset( $shipping_methods ) ? reset( $shipping_methods )->get_name() : 'Delivery Around UAE';

        // Evaluate WooCommerce Shipping Method against Plugin Config
        $priority_string = $config['priority_uae'] ?: 'WH,MC,JM';
        if ( stripos( $shipping_name, 'Jumeirah' ) !== false ) {
            $priority_string = $config['priority_jm'] ?: 'JM,MC,WH';
        } elseif ( stripos( $shipping_name, 'Motor City' ) !== false ) {
            $priority_string = $config['priority_mc'] ?: 'MC,WH,JM';
        }
        $priority = array_map( 'trim', explode( ',', $priority_string ) );

        $warehouses = $client->execute_kw(
            $config['database'], $uid, $config['api_key'],
            'stock.warehouse', 'search_read',
            array( array( array( 'code', 'in', $priority ) ) ),
            array( 'fields' => array( 'id', 'code', 'lot_stock_id' ) )
        );

        $wh_map       = array();
        $location_ids = array();
        
        if ( ! is_wp_error( $warehouses ) && is_array( $warehouses ) ) {
            foreach ( $warehouses as $wh ) {
                $wh_map[ $wh['code'] ] = $wh;
                if ( isset( $wh['lot_stock_id'][0] ) ) {
                    $location_ids[] = $wh['lot_stock_id'][0];
                }
            }
        }

        $target_warehouse_id = null;
        $can_auto_confirm    = false;
        $target_code         = '';

        if ( $is_auto_confirm_enabled && ! empty( $location_ids ) && ! empty( $odoo_product_map ) ) {
            $quants = $client->execute_kw(
                $config['database'], $uid, $config['api_key'],
                'stock.quant', 'search_read',
                array( array(
                    array( 'product_id', 'in', array_values( $odoo_product_map ) ),
                    array( 'location_id', 'in', $location_ids )
                ) ),
                array( 'fields' => array( 'product_id', 'location_id', 'quantity', 'reserved_quantity' ) )
            );

            $stock_levels = array();
            if ( ! is_wp_error( $quants ) && is_array( $quants ) ) {
                foreach ( $quants as $q ) {
                    $loc_id  = $q['location_id'][0] ?? 0;
                    $prod_id = $q['product_id'][0] ?? 0;
                    $avail   = (float) ($q['quantity'] ?? 0) - (float) ($q['reserved_quantity'] ?? 0);

                    if ( ! isset( $stock_levels[ $loc_id ][ $prod_id ] ) ) {
                        $stock_levels[ $loc_id ][ $prod_id ] = 0;
                    }
                    $stock_levels[ $loc_id ][ $prod_id ] += $avail;
                }
            }

            // Route based on dynamic priority
            foreach ( $priority as $code ) {
                if ( isset( $wh_map[ $code ] ) ) {
                    $wh_id  = $wh_map[ $code ]['id'];
                    $loc_id = $wh_map[ $code ]['lot_stock_id'][0] ?? 0;

                    $can_fulfill_all = true;
                    foreach ( $items_data as $item ) {
                        $prod_id = $odoo_product_map[ $item['sku'] ] ?? 0;
                        $req_qty = $item['quantity'];
                        $avail   = $stock_levels[ $loc_id ][ $prod_id ] ?? 0;

                        if ( $avail < $req_qty ) {
                            $can_fulfill_all = false;
                            break;
                        }
                    }

                    if ( $can_fulfill_all ) {
                        $target_warehouse_id = $wh_id;
                        $can_auto_confirm    = true;
                        $target_code         = $code;
                        break;
                    }
                }
            }
        }

        if ( ! $target_warehouse_id ) {
            $target_warehouse_id = $wh_map[ $priority[0] ]['id'] ?? ( $warehouses[0]['id'] ?? 1 );
            $can_auto_confirm    = false;
            $target_code         = $priority[0] . ' (Default/Split)';
            if ( $is_auto_confirm_enabled ) {
                $order->add_order_note( 'Odoo Connector Notice: Stock split across multiple locations or unavailable. Routed to default warehouse for manual review.' );
            }
        }

        // Step D: Build Order Lines
        $order_lines = array();
        foreach ( $items_data as $item ) {
            if ( ! isset( $odoo_product_map[ $item['sku'] ] ) ) {
                $order->add_order_note( sprintf( 'Odoo Connector Exception: SKU %s not found in Odoo.', $item['sku'] ) );
                return;
            }
            
            $order_lines[] = array(
                0, 0,
                array(
                    'product_id'      => $odoo_product_map[ $item['sku'] ],
                    'product_uom_qty' => $item['quantity'],
                    'price_unit'      => $item['price'],
                )
            );
        }

        // --- NEW Step D.1: Add Delivery/Payment Notes ---
        $payment_title = $order->get_payment_method_title() ?: 'Unknown Payment';
        $order_lines[] = array( 0, 0, array(
            'display_type' => 'line_note',
            'name'         => sprintf( "Delivery Method: %s\nPayment Method: %s", $shipping_name, $payment_title )
        ) );

        // --- NEW Step D.2: Add Dynamic Delivery Charges ---
        $shipping_total = (float) $order->get_shipping_total();
        if ( $shipping_total > 0 ) {
            $delivery_sku = '';
            $delivery_qty = 1;
            $unit_price   = 0;

            if ( $shipping_total % 150 == 0 ) {
                $delivery_sku = 'GNDUAE1';
                $delivery_qty = $shipping_total / 150;
                $unit_price   = 150.00;
            } elseif ( $shipping_total == 45 ) {
                $delivery_sku = 'GNDUAE2';
                $delivery_qty = 1;
                $unit_price   = 45.00;
            }

            if ( $delivery_sku ) {
                $del_product = $client->execute_kw(
                    $config['database'], $uid, $config['api_key'],
                    'product.product', 'search_read',
                    array( array( array( 'default_code', '=', $delivery_sku ) ) ),
                    array( 'fields' => array( 'id' ), 'limit' => 1 )
                );
                
                if ( ! is_wp_error( $del_product ) && ! empty( $del_product ) ) {
                    $order_lines[] = array( 0, 0, array(
                        'product_id'      => (int) $del_product[0]['id'],
                        'product_uom_qty' => $delivery_qty,
                        'price_unit'      => $unit_price,
                    ) );
                }
            }
        }

        // Step E: Get the "Online Order" tags and Sales Team
        $sale_tag_ids = array();
        $sale_tags = $client->execute_kw( 
            $config['database'], $uid, $config['api_key'], 
            'crm.tag', 'search_read', 
            array( array( array( 'name', '=', 'Online Order' ) ) ), 
            array( 'fields' => array( 'id' ), 'limit' => 1 ) 
        );
        if ( ! is_wp_error( $sale_tags ) && ! empty( $sale_tags ) ) {
            $sale_tag_ids[] = (int) $sale_tags[0]['id'];
        }

        $team_id = null;
        $sales_teams = $client->execute_kw(
            $config['database'], $uid, $config['api_key'],
            'crm.team', 'search_read',
            array( array( array( 'name', '=', 'Online Order' ) ) ),
            array( 'fields' => array( 'id' ), 'limit' => 1 )
        );
        if ( ! is_wp_error( $sales_teams ) && ! empty( $sales_teams ) ) {
            $team_id = (int) $sales_teams[0]['id'];
        }

        // Step F: Create Sale Order
        $sale_order_data = array(
            'partner_id'       => $partner_id,
            'warehouse_id'     => $target_warehouse_id,
            'client_order_ref' => 'WOO-' . $order->get_id(),
            'order_line'       => $order_lines,
        );

        if ( ! empty( $sale_tag_ids ) ) {
            $sale_order_data['tag_ids'] = array( array( 6, 0, $sale_tag_ids ) );
        }
        if ( $team_id ) {
            $sale_order_data['team_id'] = $team_id;
        }

        $sale_order_id = $client->execute_kw( 
            $config['database'], $uid, $config['api_key'], 
            'sale.order', 'create', 
            array( $sale_order_data ) 
        );

        if ( is_wp_error( $sale_order_id ) || ! is_int( $sale_order_id ) ) {
            $order->add_order_note( 'Odoo Connector Exception: Failed to create Sale Order in Odoo.' );
            return;
        }

        // Step G: Execute Auto-Confirmation
        if ( $is_auto_confirm_enabled && $can_auto_confirm ) {
            $client->execute_kw( 
                $config['database'], $uid, $config['api_key'], 
                'sale.order', 'action_confirm', 
                array( array( $sale_order_id ) ) 
            );
            $order->add_order_note( sprintf( 'Odoo Connector Success: Created and Auto-Confirmed Sale Order ID %d in Odoo (Routed to %s).', $sale_order_id, $target_code ) );
        } else {
            $order->add_order_note( sprintf( 'Odoo Connector Success: Created Draft Sale Order ID %d in Odoo (Routed to %s). Pending manual confirmation.', $sale_order_id, $target_code ) );
        }

        $order->update_meta_data( '_owsc_odoo_import_status', 'completed' );
        $order->update_meta_data( '_owsc_odoo_sale_order_id', $sale_order_id );
        $order->save_meta_data();
    }

    private function resolve_customer( $client, $config, $uid, $customer_data ): int {
        // Match by Email
        if ( ! empty( $customer_data['email'] ) ) {
            $partners = $client->execute_kw( 
                $config['database'], $uid, $config['api_key'], 
                'res.partner', 'search_read', 
                array( array( array( 'email', '=', $customer_data['email'] ) ) ), 
                array( 'fields' => array( 'id' ), 'limit' => 1 ) 
            );
            if ( ! is_wp_error( $partners ) && ! empty( $partners ) ) {
                return (int) $partners[0]['id'];
            }
        }

        // Match by Phone
        if ( ! empty( $customer_data['phone'] ) ) {
            $partners = $client->execute_kw( 
                $config['database'], $uid, $config['api_key'], 
                'res.partner', 'search_read', 
                array( array( array( 'phone', '=', $customer_data['phone'] ) ) ), 
                array( 'fields' => array( 'id' ), 'limit' => 1 ) 
            );
            if ( ! is_wp_error( $partners ) && ! empty( $partners ) ) {
                return (int) $partners[0]['id'];
            }
        }

        // Create new Contact if no match found
        $tag_ids = array();
        $tags = $client->execute_kw( 
            $config['database'], $uid, $config['api_key'], 
            'res.partner.category', 'search_read', 
            array( array( array( 'name', '=', 'Online Order' ) ) ), 
            array( 'fields' => array( 'id' ), 'limit' => 1 ) 
        );
        if ( ! is_wp_error( $tags ) && ! empty( $tags ) ) {
            $tag_ids[] = (int) $tags[0]['id'];
        }

        // Map Country ID
        $country_id = null;
        if ( ! empty( $customer_data['country'] ) ) {
            $countries = $client->execute_kw(
                $config['database'], $uid, $config['api_key'],
                'res.country', 'search_read',
                array( array( array( 'code', '=', $customer_data['country'] ) ) ),
                array( 'fields' => array( 'id' ), 'limit' => 1 )
            );
            if ( ! is_wp_error( $countries ) && ! empty( $countries ) ) {
                $country_id = (int) $countries[0]['id'];
            }
        }

        $partner_payload = array(
            'name'   => ! empty( $customer_data['name'] ) ? $customer_data['name'] : 'WooCommerce Guest',
            'email'  => $customer_data['email'],
            'phone'  => $customer_data['phone'],
            'mobile' => $customer_data['phone'],
            'street' => $customer_data['street'],
            'city'   => $customer_data['city'],
            'zip'    => $customer_data['zip'],
        );

        if ( $country_id ) {
            $partner_payload['country_id'] = $country_id;
        }
        if ( ! empty( $tag_ids ) ) {
            $partner_payload['category_id'] = array( array( 6, 0, $tag_ids ) ); 
        }

        $new_partner_id = $client->execute_kw( 
            $config['database'], $uid, $config['api_key'], 
            'res.partner', 'create', 
            array( $partner_payload ) 
        );

        if ( ! is_wp_error( $new_partner_id ) && is_int( $new_partner_id ) ) {
            return $new_partner_id;
        }

        return 0; 
    }
}
