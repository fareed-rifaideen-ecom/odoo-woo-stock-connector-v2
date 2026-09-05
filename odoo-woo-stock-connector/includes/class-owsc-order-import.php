<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OWSC_Order_Import {
    
    public function register(): void {
        // Trigger only when an order status changes to 'processing'
        add_action( 'woocommerce_order_status_processing', array( $this, 'capture_processing_order' ), 10, 2 );
    }

    public function capture_processing_order( int $order_id, \WC_Order $order ): void {
        // 1. Idempotency Check: Prevent processing the same order twice
        $import_status = $order->get_meta( '_owsc_odoo_import_status' );
        if ( 'processing' === $import_status || 'completed' === $import_status ) {
            return;
        }

        // Lock the order to prevent duplicate webhook/event triggers
        $order->update_meta_data( '_owsc_odoo_import_status', 'processing' );
        $order->save_meta_data();

        // 2. Extract Customer Data
        $customer_email = $order->get_billing_email();
        $customer_phone = $order->get_billing_phone();
        $first_name     = $order->get_billing_first_name();
        $last_name      = $order->get_billing_last_name();
        $customer_name  = trim( $first_name . ' ' . $last_name );

        // 3. Extract Line Items & SKUs
        $items_data = array();
        foreach ( $order->get_items() as $item_id => $item ) {
            $product = $item->get_product();
            if ( $product && $product->get_sku() ) {
                $items_data[] = array(
                    'sku'      => $product->get_sku(),
                    'quantity' => $item->get_quantity(),
                    'name'     => $item->get_name(),
                );
            }
        }

        // 4. Phase 1 Verification: Add an internal order note to prove data extraction
        if ( empty( $items_data ) ) {
            $order->add_order_note( 'Odoo Connector: Ignored. No valid SKUs found in order.' );
            return;
        }

        $note = sprintf( 
            "Odoo Connector Phase 1 Capture Successful.\nCustomer: %s\nEmail: %s\nPhone: %s\nSKUs to process: %d", 
            $customer_name, 
            $customer_email, 
            $customer_phone, 
            count( $items_data ) 
        );
        
        $order->add_order_note( $note );
    }
}
