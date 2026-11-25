<?php
/**
 * Read-only SKU preflight auditor and authoritative single-SKU manual sync service.
 *
 * Delegates all Odoo stock discovery, sub-location aggregation, unconfirmed online
 * draft-order deductions, forced-OOS overrides, and WooCommerce writes directly to
 * OWSC_Stock_Sync so audit previews always match automated synchronization.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OWSC_SKU_Audit {
    /**
     * Runs a read-only preflight check comparing Odoo 18 and WooCommerce for an exact SKU.
     *
     * @param string $sku Exact product SKU (`default_code` / `_sku`).
     * @return array
     */
    public function preflight( string $sku ): array {
        $sku = trim( $sku );

        $result = array(
            'status'                => 'success',
            'sku'                   => $sku,
            'fields'                => array(),
            'odoo_products'         => array(),
            'woocommerce_products'  => array(),
            'locations'             => array(),
            'approved_stock_qty'    => 0.0,
            'draft_order_deduction' => 0.0,
            'force_out_of_stock'    => false,
            'sync_eligible'         => false,
            'proposed_qty'          => null,
            'messages'              => array(),
        );

        if ( '' === $sku ) {
            $result['status']     = 'error';
            $result['messages'][] = 'SKU is required.';
            return $result;
        }

        // Always inspect WooCommerce mapping so missing/duplicate local SKUs are visible even if Odoo warns.
        $result['woocommerce_products'] = $this->woocommerce_products_by_sku( $sku );

        if ( ! class_exists( 'OWSC_Stock_Sync' ) ) {
            $result['status']     = 'error';
            $result['messages'][] = 'OWSC_Stock_Sync engine is unavailable.';
            return $result;
        }

        $sync_engine = new OWSC_Stock_Sync();
        $snapshot    = $sync_engine->inspect_sku( $sku );

        $result['status']                = (string) ( $snapshot['status'] ?? 'error' );
        $result['fields']                = is_array( $snapshot['fields'] ?? null ) ? $snapshot['fields'] : array();
        $result['odoo_products']         = is_array( $snapshot['odoo_products'] ?? null ) ? $snapshot['odoo_products'] : array();
        $result['locations']             = is_array( $snapshot['locations'] ?? null ) ? $snapshot['locations'] : array();
        $result['approved_stock_qty']    = (float) ( $snapshot['approved_stock_qty'] ?? 0.0 );
        $result['draft_order_deduction'] = (float) ( $snapshot['draft_order_deduction'] ?? 0.0 );
        $result['force_out_of_stock']    = ! empty( $snapshot['force_out_of_stock'] );
        $result['sync_eligible']         = ! empty( $snapshot['sync_eligible'] );
        $result['proposed_qty']          = $snapshot['proposed_qty'] ?? null;

        if ( ! empty( $snapshot['messages'] ) && is_array( $snapshot['messages'] ) ) {
            foreach ( $snapshot['messages'] as $msg ) {
                $result['messages'][] = (string) $msg;
            }
        }

        // Validate exact 1-to-1 WooCommerce SKU resolution.
        $woo_count = count( $result['woocommerce_products'] );
        if ( 1 !== $woo_count ) {
            if ( 'error' !== $result['status'] ) {
                $result['status'] = 'warning';
            }
            $result['proposed_qty'] = null;
            $result['messages'][]   = 'WooCommerce SKU must resolve to exactly one product or variation before synchronization is considered.';
        }

        // Surface eligibility, forced-OOS, and draft-order deductions clearly in audit diagnostics.
        if ( 1 === count( $result['odoo_products'] ) ) {
            if ( ! $result['sync_eligible'] ) {
                if ( 'error' !== $result['status'] ) {
                    $result['status'] = 'warning';
                }
                $result['messages'][] = 'Odoo product is NOT enabled for WooCommerce sync (x_studio_available_for_woocommerce_sync is unchecked). Automated and manual sync will skip this SKU until enabled in Odoo.';
            }

            if ( $result['force_out_of_stock'] ) {
                $result['messages'][] = 'Force Out of Stock (x_studio_force_out_of_stock) is enabled in Odoo. Proposed WooCommerce stock is forced to 0.';
            }

            if ( $result['draft_order_deduction'] > 0 ) {
                $result['messages'][] = sprintf(
                    'Deducted %s unit(s) reserved by unconfirmed online Draft/Sent Sale Orders in Odoo.',
                    $this->format_qty( $result['draft_order_deduction'] )
                );
            }
        }

        if ( 1 === $woo_count && empty( $result['woocommerce_products'][0]['manage_stock'] ) ) {
            if ( 'error' !== $result['status'] ) {
                $result['status'] = 'warning';
            }
            $result['messages'][] = 'WooCommerce stock management (manage_stock) is disabled for this product. Enable "Track stock quantity for this product" in WooCommerce before syncing stock.';
        }

        if ( 'error' !== $result['status'] ) {
            $result['messages'][] = 'Read-only preflight completed. No records were changed.';
        }

        return $result;
    }

    /**
     * Executes an authoritative single-SKU sync directly through OWSC_Stock_Sync
     * and records an audit entry in wp_owsc_logs.
     *
     * Never trusts client-submitted quantities, statuses, or product IDs.
     *
     * @param string $sku Exact SKU to synchronize from Odoo to WooCommerce.
     * @return array{status:string,message:string}
     */
    public function execute_single_sku_sync( string $sku ): array {
        $sku = trim( $sku );
        if ( '' === $sku ) {
            return array(
                'status'  => 'error',
                'message' => 'Sync failed: SKU is required.',
            );
        }

        if ( ! class_exists( 'OWSC_Stock_Sync' ) ) {
            return array(
                'status'  => 'error',
                'message' => 'Sync failed: OWSC_Stock_Sync engine is unavailable.',
            );
        }

        $sync_engine = new OWSC_Stock_Sync();
        $result      = $sync_engine->run_sync( $sku );

        $status      = isset( $result['status'] ) ? (string) $result['status'] : 'info';
        $raw_message = isset( $result['message'] ) ? (string) $result['message'] : 'Sync completed.';

        $prefix = ( 'success' === $status ) ? 'SUCCESS: ' : '';
        $final_message = sprintf( '%s[SKU %s] %s', $prefix, $sku, $raw_message );

        if ( function_exists( 'owsc_log_sync_event' ) ) {
            owsc_log_sync_event( 'Manual SKU Audit', sprintf( '[SKU %s] %s', $sku, $raw_message ), $status );
        }

        return array(
            'status'  => $status,
            'message' => $final_message,
        );
    }

    /**
     * Backward-compatible wrapper retained safely during staged file rollout.
     *
     * Intentionally ignores untrusted client-supplied product ID, quantity, and status
     * parameters and delegates straight to the authoritative Odoo -> WooCommerce engine.
     *
     * @param string $sku            Exact SKU to synchronize.
     * @param int    $woo_product_id Unused (ignored for security).
     * @param float  $new_qty        Unused (ignored for security).
     * @param string $new_status     Unused (ignored for security).
     * @return array{status:string,message:string}
     */
    public function sync_stock( string $sku, int $woo_product_id = 0, float $new_qty = 0.0, string $new_status = '' ): array {
        return $this->execute_single_sku_sync( $sku );
    }

    /**
     * Looks up all non-trashed WooCommerce products and variations matching an exact SKU.
     *
     * HPOS-safe: WooCommerce products and variations remain stored in wp_posts / wp_postmeta.
     *
     * @param string $sku Exact SKU to query.
     * @return array<int,array<string,mixed>>
     */
    private function woocommerce_products_by_sku( string $sku ): array {
        if ( ! function_exists( 'wc_get_product' ) ) {
            return array();
        }

        global $wpdb;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT p.ID
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                 WHERE pm.meta_key = '_sku'
                   AND pm.meta_value = %s
                   AND p.post_type IN ('product', 'product_variation')
                   AND p.post_status NOT IN ('trash', 'auto-draft')
                 ORDER BY p.ID ASC",
                $sku
            )
        );

        if ( ! is_array( $ids ) || empty( $ids ) ) {
            return array();
        }

        $products = array();
        foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
            if ( $id <= 0 ) {
                continue;
            }
            $product = wc_get_product( $id );
            if ( ! $product ) {
                continue;
            }

            $products[] = array(
                'id'             => $product->get_id(),
                'type'           => $product->get_type(),
                'parent_id'      => $product->is_type( 'variation' ) ? $product->get_parent_id() : 0,
                'manage_stock'   => (bool) $product->get_manage_stock(),
                'stock_quantity' => $product->get_stock_quantity(),
                'stock_status'   => (string) $product->get_stock_status(),
                'backorders'     => (string) $product->get_backorders(),
                'regular_price'  => (string) $product->get_regular_price(),
                'sale_price'     => (string) $product->get_sale_price(),
            );
        }

        return $products;
    }

    /**
     * Formats numeric quantities cleanly without trailing fractional zeroes.
     *
     * @param float $qty Numeric quantity.
     * @return string
     */
    private function format_qty( float $qty ): string {
        $formatted = rtrim( rtrim( sprintf( '%.4F', $qty ), '0' ), '.' );
        return '' === $formatted ? '0' : $formatted;
    }
}
