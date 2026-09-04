<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OWSC_SKU_Audit {
    public function preflight( string $sku ): array {
        $sku = trim( $sku );
        if ( '' === $sku ) {
            return array( 'status' => 'error', 'sku' => '', 'messages' => array( 'SKU is required.' ) );
        }

        $result = array(
            'status'               => 'success',
            'sku'                  => $sku,
            'fields'               => array(),
            'odoo_products'        => array(),
            'woocommerce_products' => array(),
            'messages'             => array(),
        );

        $config = OWSCPluginV2::configuration();
        if ( ! $config['url'] || ! $config['database'] || ! $config['username'] || ! $config['api_key'] ) {
            return array_merge( $result, array( 'status' => 'error', 'messages' => array( 'Odoo configuration is incomplete.' ) ) );
        }

        $client = new OWSC_Odoo_XMLRPC_Client( $config['url'] );
        $uid    = $client->authenticate( $config['database'], $config['username'], $config['api_key'] );
        
        if ( is_wp_error( $uid ) || ! is_int( $uid ) || $uid <= 0 ) {
            return array_merge( $result, array( 'status' => 'error', 'messages' => array( 'Odoo authentication failed.' ) ) );
        }

        // Check if the eligibility field exists in Odoo
        $fields = $client->execute_kw( $config['database'], $uid, $config['api_key'], 'product.product', 'fields_get', array(), array( 'attributes' => array( 'string', 'type' ) ) );
        
        // Search Odoo for the exact SKU
        $products = $client->execute_kw( $config['database'], $uid, $config['api_key'], 'product.product', 'search_read', array( array( array( 'default_code', '=', $sku ) ) ), array( 'fields' => array( 'id', 'default_code', 'product_tmpl_id', 'x_studio_available_for_woocommerce_sync' ), 'limit' => 2 ) );
        
        if ( is_wp_error( $fields ) || is_wp_error( $products ) ) {
            return array_merge( $result, array( 'status' => 'error', 'messages' => array( 'Odoo read-only product discovery failed.' ) ) );
        }

        $result['fields']               = is_array( $fields ) ? $fields : array();
        $result['odoo_products']        = is_array( $products ) ? $products : array();
        $result['woocommerce_products'] = $this->woocommerce_products_by_sku( $sku );

        if ( 1 !== count( $result['odoo_products'] ) ) {
            $result['status'] = 'warning';
            $result['messages'][] = 'Odoo SKU must resolve to exactly one product before synchronization is considered.';
        }
        if ( 1 !== count( $result['woocommerce_products'] ) ) {
            $result['status'] = 'warning';
            $result['messages'][] = 'WooCommerce SKU must resolve to exactly one product or variation before synchronization is considered.';
        }
        
        $result['messages'][] = 'Read-only preflight completed. No records were changed.';
        return $result;
    }

    private function woocommerce_products_by_sku( string $sku ): array {
        if ( ! function_exists( 'wc_get_product' ) ) {
            return array();
        }
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID WHERE pm.meta_key = '_sku' AND pm.meta_value = %s AND p.post_type IN ('product', 'product_variation') AND p.post_status NOT IN ('trash', 'auto-draft') ORDER BY p.ID ASC", $sku ) );
        
        $products = array();
        foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
            $product = wc_get_product( $id );
            if ( ! $product ) { continue; }
            $products[] = array(
                'id'             => $product->get_id(),
                'type'           => $product->get_type(),
                'parent_id'      => $product->is_type( 'variation' ) ? $product->get_parent_id() : 0,
                'manage_stock'   => $product->get_manage_stock(),
                'stock_quantity' => $product->get_stock_quantity(),
                'stock_status'   => $product->get_stock_status(),
                'backorders'     => $product->get_backorders(),
            );
        }
        return $products;
    }
}
