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

        // 2. Extract Customer Data
        $customer_data = array(
            'email' => $order->get_billing_email(),
            'phone' => $order->get_billing_phone(),
            'name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
        );

        // 3. Extract Line Items & SKUs
        $items_data = array();
        foreach ( $order->get_items() as $item_id => $item ) {
            $product = $item->get_product();
            if ( $product && $product->get_sku() ) {
                $items_data[] = array(
                    'sku'      => $product->get_sku(),
                    'quantity' => $item->get_quantity(),
                    'price'    => $order->get_item_total( $item, false, false ), // Raw unit price
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

        $client = new OWSC_Odoo_XMLRPC_Client( $config['url'] );
        $uid    = $client->authenticate( $config['database'], $config['username'], $config['api_key'] );
        
        if ( is_wp_error( $uid ) || ! is_int( $uid ) || $uid <= 0 ) {
            $order->add_order_note( 'Odoo Connector Exception: Odoo authentication failed.' );
            return;
        }

        // Step A: Resolve Customer
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

        // Step C: Build Order Lines
        $order_lines = array();
        foreach ( $items_data as $item ) {
            if ( ! isset( $odoo_product_map[ $item['sku'] ] ) ) {
                $order->add_order_note( sprintf( 'Odoo Connector Exception: SKU %s not found in Odoo.', $item['sku'] ) );
                return;
            }
            
            $order_lines[] = array(
                0, // Odoo command: Create new record
                0,
                array(
                    'product_id'      => $odoo_product_map[ $item['sku'] ],
                    'product_uom_qty' => $item['quantity'],
                    'price_unit'      => $item['price'],
                )
            );
        }

        // Step D: Create Draft Sale Order
        $sale_order_data = array(
            'partner_id'       => $partner_id,
            'client_order_ref' => 'WOO-' . $order->get_id(), // Adds WooCommerce Order ID as reference
            'order_line'       => $order_lines,
        );

        $sale_order_id = $client->execute_kw( 
            $config['database'], $uid, $config['api_key'], 
            'sale.order', 'create', 
            array( $sale_order_data ) 
        );

        if ( is_wp_error( $sale_order_id ) || ! is_int( $sale_order_id ) ) {
            $order->add_order_note( 'Odoo Connector Exception: Failed to create Sale Order in Odoo.' );
            return;
        }

        // Success: Mark completed and attach Odoo ID
        $order->update_meta_data( '_owsc_odoo_import_status', 'completed' );
        $order->update_meta_data( '_owsc_odoo_sale_order_id', $sale_order_id );
        $order->save_meta_data();

        $order->add_order_note( sprintf( 'Odoo Connector Success: Created Draft Sale Order ID %d in Odoo.', $sale_order_id ) );
    }

    private function resolve_customer( $client, $config, $uid, $customer_data ): int {
        // Priority 1: Match by exact Email
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

        // Priority 2: Match by exact Phone
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

        // Priority 3: Create new Contact if no match found
        $new_partner_id = $client->execute_kw( 
            $config['database'], $uid, $config['api_key'], 
            'res.partner', 'create', 
            array( array(
                'name'  => ! empty( $customer_data['name'] ) ? $customer_data['name'] : 'WooCommerce Guest',
                'email' => $customer_data['email'],
                'phone' => $customer_data['phone'],
            ) ) 
        );

        if ( ! is_wp_error( $new_partner_id ) && is_int( $new_partner_id ) ) {
            return $new_partner_id;
        }

        return 0; // Failed to resolve or create
    }
}
