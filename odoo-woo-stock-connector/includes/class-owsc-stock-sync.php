<?php
/**
 * Authoritative Odoo 18 -> WooCommerce Stock & Regular Price Synchronization Engine.
 *
 * Enforces fail-closed error handling (never zeroes WooCommerce stock on Odoo RPC failures),
 * resolves warehouse sub-locations via child_of, deducts unconfirmed online draft orders,
 * handles multi-variant pricelists, and exposes a shared read-only SKU inspection helper
 * so manual SKU audits always match automated sync calculations.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OWSC_Stock_Sync {
    /**
     * Canonical Odoo field names shared across stock sync, SKU audit, order import, and connection tests.
     */
    const FIELD_SKU               = 'default_code';
    const FIELD_SYNC_ELIGIBLE     = 'x_studio_available_for_woocommerce_sync';
    const FIELD_FORCE_OOS         = 'x_studio_force_out_of_stock';
    const FIELD_IS_ONLINE_ORDER   = 'x_studio_is_online_order';
    const FIELD_WOO_ORDER_ID      = 'x_studio_woo_order_id';

    /**
     * Approved Odoo root stock locations whose stock (including nested child bins) feeds WooCommerce.
     */
    const APPROVED_LOCATIONS      = array( 'WH/Stock', 'MC/Stock', 'JM/Stock' );

    /**
     * Exclusive lock key & TTL for full-catalog syncs across Webhook, Manual Sync, and WP-Cron.
     */
    const FULL_SYNC_LOCK_KEY      = 'owsc_full_sync_lock';
    const FULL_SYNC_LOCK_TTL      = 180;

    /**
     * Batch size for Odoo `in` domain queries to avoid oversized XML-RPC payloads.
     */
    const RPC_BATCH_SIZE          = 500;

    /**
     * Runs either a full-catalog sync or a targeted single-product sync from Odoo to WooCommerce.
     *
     * @param string $target_sku             Optional exact SKU (`default_code`) to sync.
     * @param int    $target_odoo_product_id Optional Odoo `product.product` ID to sync.
     * @return array{status:string,message:string}
     */
    public function run_sync( string $target_sku = '', int $target_odoo_product_id = 0 ): array {
        if ( ! function_exists( 'wc_get_product_id_by_sku' ) || ! function_exists( 'wc_get_product' ) ) {
            return array(
                'status'  => 'error',
                'message' => 'WooCommerce is not active. Cannot run stock sync.',
            );
        }

        $target_sku   = trim( $target_sku );
        $is_full_sync = ( '' === $target_sku && $target_odoo_product_id <= 0 );

        if ( $is_full_sync ) {
            if ( get_transient( self::FULL_SYNC_LOCK_KEY ) ) {
                return array(
                    'status'  => 'skipped',
                    'message' => 'Full sync already in progress. Skipped.',
                );
            }
            set_transient( self::FULL_SYNC_LOCK_KEY, time(), self::FULL_SYNC_LOCK_TTL );
        }

        try {
            return $this->execute_sync( $target_sku, $target_odoo_product_id, $is_full_sync );
        } finally {
            if ( $is_full_sync ) {
                delete_transient( self::FULL_SYNC_LOCK_KEY );
            }
        }
    }

    /**
     * Performs a read-only Odoo stock inspection for a single SKU using the exact same
     * sub-location, forced-OOS, and draft-order math as run_sync().
     *
     * Used by OWSC_SKU_Audit so previewed quantities never drift from actual sync results.
     *
     * @param string $sku Exact SKU (`default_code`) to inspect.
     * @return array
     */
    public function inspect_sku( string $sku ): array {
        $sku = trim( $sku );

        $snapshot = array(
            'status'                => 'success',
            'sku'                   => $sku,
            'fields'                => array(),
            'odoo_products'         => array(),
            'locations'             => array(),
            'approved_stock_qty'    => 0.0,
            'draft_order_deduction' => 0.0,
            'force_out_of_stock'    => false,
            'sync_eligible'         => false,
            'proposed_qty'          => null,
            'messages'              => array(),
        );

        if ( '' === $sku ) {
            $snapshot['status']     = 'error';
            $snapshot['messages'][] = 'SKU is required.';
            return $snapshot;
        }

        $auth = $this->connect_and_authenticate();
        if ( is_wp_error( $auth ) ) {
            $snapshot['status']     = 'error';
            $snapshot['messages'][] = $auth->get_error_message();
            return $snapshot;
        }

        $client = $auth['client'];
        $config = $auth['config'];
        $uid    = $auth['uid'];

        // Targeted fields_get (only the fields needed for audit validation, preventing huge transient bloat).
        $fields = $client->execute_kw(
            $config['database'],
            $uid,
            $config['api_key'],
            'product.product',
            'fields_get',
            array( array( self::FIELD_SKU, self::FIELD_SYNC_ELIGIBLE, self::FIELD_FORCE_OOS ) ),
            array( 'attributes' => array( 'string', 'type' ) )
        );

        if ( is_wp_error( $fields ) ) {
            $snapshot['status']     = 'error';
            $snapshot['messages'][] = 'Odoo field discovery failed: ' . $fields->get_error_message();
            return $snapshot;
        }

        $products = $client->execute_kw(
            $config['database'],
            $uid,
            $config['api_key'],
            'product.product',
            'search_read',
            array( array( array( self::FIELD_SKU, '=', $sku ) ) ),
            array(
                'fields' => array(
                    'id',
                    self::FIELD_SKU,
                    'product_tmpl_id',
                    'list_price',
                    self::FIELD_SYNC_ELIGIBLE,
                    self::FIELD_FORCE_OOS,
                ),
                'limit'  => 2,
            )
        );

        if ( is_wp_error( $products ) ) {
            $snapshot['status']     = 'error';
            $snapshot['messages'][] = 'Odoo product lookup failed: ' . $products->get_error_message();
            return $snapshot;
        }

        $snapshot['fields']        = is_array( $fields ) ? $fields : array();
        $snapshot['odoo_products'] = is_array( $products ) ? $products : array();

        if ( 1 !== count( $snapshot['odoo_products'] ) ) {
            $snapshot['status']     = 'warning';
            $snapshot['messages'][] = 'Odoo SKU must resolve to exactly one product before synchronization is considered.';
            return $snapshot;
        }

        $odoo_product                  = $snapshot['odoo_products'][0];
        $odoo_product_id               = (int) $odoo_product['id'];
        $snapshot['sync_eligible']     = ! empty( $odoo_product[ self::FIELD_SYNC_ELIGIBLE ] );
        $snapshot['force_out_of_stock'] = ! empty( $odoo_product[ self::FIELD_FORCE_OOS ] );

        // Resolve approved root locations and all nested child locations for accurate parity with run_sync().
        $approved_root_ids = $this->fetch_approved_location_ids( $client, $config, $uid );
        if ( is_wp_error( $approved_root_ids ) ) {
            $snapshot['status']     = 'error';
            $snapshot['messages'][] = 'Odoo approved location lookup failed: ' . $approved_root_ids->get_error_message();
            return $snapshot;
        }

        $approved_descendant_ids = $client->execute_kw(
            $config['database'],
            $uid,
            $config['api_key'],
            'stock.location',
            'search',
            array( array( array( 'id', 'child_of', $approved_root_ids ) ) )
        );

        if ( is_wp_error( $approved_descendant_ids ) || ! is_array( $approved_descendant_ids ) ) {
            $err = is_wp_error( $approved_descendant_ids ) ? $approved_descendant_ids->get_error_message() : 'Invalid sub-location response.';
            $snapshot['status']     = 'error';
            $snapshot['messages'][] = 'Odoo sub-location lookup failed: ' . $err;
            return $snapshot;
        }

        $approved_id_lookup = array_flip( array_map( 'intval', $approved_descendant_ids ) );

        // Fetch all stock.quant rows for this product so the audit displays every location clearly.
        $quants = $client->execute_kw(
            $config['database'],
            $uid,
            $config['api_key'],
            'stock.quant',
            'search_read',
            array( array( array( 'product_id', '=', $odoo_product_id ) ) ),
            array( 'fields' => array( 'location_id', 'quantity', 'reserved_quantity' ) )
        );

        if ( is_wp_error( $quants ) || ! is_array( $quants ) ) {
            $err = is_wp_error( $quants ) ? $quants->get_error_message() : 'Invalid stock.quant response.';
            $snapshot['status']     = 'error';
            $snapshot['messages'][] = 'Odoo stock.quant lookup failed: ' . $err;
            return $snapshot;
        }

        $aggregated_quants = array();
        $quant_location_ids = array();

        foreach ( $quants as $quant ) {
            $loc_id = isset( $quant['location_id'][0] ) ? (int) $quant['location_id'][0] : 0;
            if ( $loc_id <= 0 ) {
                continue;
            }

            if ( ! isset( $aggregated_quants[ $loc_id ] ) ) {
                $aggregated_quants[ $loc_id ] = array(
                    'quantity' => 0.0,
                    'reserved' => 0.0,
                );
                $quant_location_ids[] = $loc_id;
            }

            $aggregated_quants[ $loc_id ]['quantity'] += (float) ( $quant['quantity'] ?? 0 );
            $aggregated_quants[ $loc_id ]['reserved'] += (float) ( $quant['reserved_quantity'] ?? 0 );
        }

        $locations_data     = array();
        $approved_stock_qty = 0.0;

        if ( ! empty( $quant_location_ids ) ) {
            $locations = $client->execute_kw(
                $config['database'],
                $uid,
                $config['api_key'],
                'stock.location',
                'search_read',
                array( array( array( 'id', 'in', array_values( $quant_location_ids ) ) ) ),
                array( 'fields' => array( 'id', 'complete_name', 'usage' ) )
            );

            if ( is_wp_error( $locations ) || ! is_array( $locations ) ) {
                $err = is_wp_error( $locations ) ? $locations->get_error_message() : 'Invalid stock.location response.';
                $snapshot['status']     = 'error';
                $snapshot['messages'][] = 'Odoo stock.location lookup failed: ' . $err;
                return $snapshot;
            }

            $loc_map = array();
            foreach ( $locations as $loc ) {
                if ( isset( $loc['id'] ) ) {
                    $loc_map[ (int) $loc['id'] ] = $loc;
                }
            }

            foreach ( $aggregated_quants as $loc_id => $totals ) {
                if ( ! isset( $loc_map[ $loc_id ] ) ) {
                    continue;
                }

                $complete_name = (string) ( $loc_map[ $loc_id ]['complete_name'] ?? 'Unknown' );
                $available     = $totals['quantity'] - $totals['reserved'];
                $is_approved   = isset( $approved_id_lookup[ $loc_id ] );

                $locations_data[] = array(
                    'location_id'   => $loc_id,
                    'complete_name' => $complete_name,
                    'usage'         => (string) ( $loc_map[ $loc_id ]['usage'] ?? 'Unknown' ),
                    'quantity'      => $totals['quantity'],
                    'reserved'      => $totals['reserved'],
                    'available'     => $available,
                    'is_approved'   => $is_approved,
                );

                if ( $is_approved ) {
                    $approved_stock_qty += $available;
                }
            }

            usort(
                $locations_data,
                static function ( array $a, array $b ): int {
                    return strcmp( $a['complete_name'], $b['complete_name'] );
                }
            );
        }

        // Deduct unconfirmed online draft/sent orders using the shared helper.
        $draft_deductions = $this->fetch_draft_order_quantities( $client, $config, $uid, array( $odoo_product_id ) );
        if ( is_wp_error( $draft_deductions ) ) {
            $snapshot['status']     = 'error';
            $snapshot['messages'][] = 'Odoo draft online order lookup failed: ' . $draft_deductions->get_error_message();
            return $snapshot;
        }

        $draft_qty = (float) ( $draft_deductions[ $odoo_product_id ] ?? 0.0 );

        $snapshot['locations']             = $locations_data;
        $snapshot['approved_stock_qty']    = $approved_stock_qty;
        $snapshot['draft_order_deduction'] = $draft_qty;
        $snapshot['proposed_qty']          = $this->calculate_final_qty(
            $approved_stock_qty,
            $draft_qty,
            $snapshot['force_out_of_stock']
        );

        return $snapshot;
    }

    /**
     * Core fail-closed sync implementation.
     *
     * @param string $target_sku             Optional SKU filter.
     * @param int    $target_odoo_product_id Optional Odoo product.product ID filter.
     * @param bool   $is_full_sync           Whether this is an unfiltered full sync.
     * @return array{status:string,message:string}
     */
    private function execute_sync( string $target_sku, int $target_odoo_product_id, bool $is_full_sync ): array {
        $auth = $this->connect_and_authenticate();
        if ( is_wp_error( $auth ) ) {
            return array(
                'status'  => 'error',
                'message' => $auth->get_error_message(),
            );
        }

        $client = $auth['client'];
        $config = $auth['config'];
        $uid    = $auth['uid'];

        // 1. Fetch eligible Odoo products.
        $domain = array(
            array( self::FIELD_SYNC_ELIGIBLE, '=', true ),
        );

        if ( '' !== $target_sku ) {
            $domain[] = array( self::FIELD_SKU, '=', $target_sku );
        } elseif ( $target_odoo_product_id > 0 ) {
            $domain[] = array( 'id', '=', $target_odoo_product_id );
        }

        $products = $client->execute_kw(
            $config['database'],
            $uid,
            $config['api_key'],
            'product.product',
            'search_read',
            array( $domain ),
            array(
                'fields' => array(
                    'id',
                    self::FIELD_SKU,
                    'product_tmpl_id',
                    'list_price',
                    self::FIELD_FORCE_OOS,
                ),
            )
        );

        if ( is_wp_error( $products ) ) {
            return array(
                'status'  => 'error',
                'message' => 'Sync aborted: failed to fetch Odoo products — ' . $products->get_error_message(),
            );
        }

        if ( ! is_array( $products ) || empty( $products ) ) {
            $msg = ! $is_full_sync
                ? 'Target product not found or not enabled for sync in Odoo.'
                : 'No eligible products found for sync in Odoo.';

            return array(
                'status'  => 'info',
                'message' => $msg,
            );
        }

        $product_map      = array();
        $odoo_product_ids = array();
        $odoo_tmpl_ids    = array();
        $tmpl_to_skus     = array();
        $sku_prices       = array();
        $force_oos_map    = array();

        foreach ( $products as $p ) {
            $raw_code = isset( $p[ self::FIELD_SKU ] ) && is_string( $p[ self::FIELD_SKU ] ) ? trim( $p[ self::FIELD_SKU ] ) : '';
            if ( '' === $raw_code || empty( $p['id'] ) ) {
                continue;
            }

            $pid                 = (int) $p['id'];
            $product_map[ $pid ] = $raw_code;
            $odoo_product_ids[]  = $pid;

            if ( isset( $p['product_tmpl_id'][0] ) ) {
                $tmpl_id = (int) $p['product_tmpl_id'][0];
                if ( $tmpl_id > 0 ) {
                    $odoo_tmpl_ids[] = $tmpl_id;
                    if ( ! isset( $tmpl_to_skus[ $tmpl_id ] ) ) {
                        $tmpl_to_skus[ $tmpl_id ] = array();
                    }
                    $tmpl_to_skus[ $tmpl_id ][] = $raw_code;
                }
            }

            if ( isset( $p['list_price'] ) ) {
                $sku_prices[ $raw_code ] = (float) $p['list_price'];
            }

            $force_oos_map[ $raw_code ] = ! empty( $p[ self::FIELD_FORCE_OOS ] );
        }

        $odoo_product_ids = array_values( array_unique( $odoo_product_ids ) );
        $odoo_tmpl_ids    = array_values( array_unique( $odoo_tmpl_ids ) );

        if ( empty( $odoo_product_ids ) ) {
            return array(
                'status'  => 'info',
                'message' => 'Eligible Odoo products found, but none have a SKU (default_code) assigned.',
            );
        }

        // 2. Fetch approved warehouse root location IDs (FAIL-CLOSED).
        $location_ids = $this->fetch_approved_location_ids( $client, $config, $uid );
        if ( is_wp_error( $location_ids ) ) {
            return array(
                'status'  => 'error',
                'message' => 'Sync aborted (no WooCommerce stock modified): ' . $location_ids->get_error_message(),
            );
        }

        // 3. Fetch Stock Quants across approved locations AND nested child sub-locations (FAIL-CLOSED).
        $stock_totals = array();
        foreach ( $product_map as $sku ) {
            $stock_totals[ $sku ] = 0.0;
        }

        foreach ( array_chunk( $odoo_product_ids, self::RPC_BATCH_SIZE ) as $product_batch ) {
            $quants = $client->execute_kw(
                $config['database'],
                $uid,
                $config['api_key'],
                'stock.quant',
                'search_read',
                array(
                    array(
                        array( 'product_id', 'in', $product_batch ),
                        array( 'location_id', 'child_of', $location_ids ),
                    ),
                ),
                array( 'fields' => array( 'product_id', 'quantity', 'reserved_quantity' ) )
            );

            if ( is_wp_error( $quants ) || ! is_array( $quants ) ) {
                $err = is_wp_error( $quants ) ? $quants->get_error_message() : 'Unexpected stock.quant response type.';
                return array(
                    'status'  => 'error',
                    'message' => 'Sync aborted (no WooCommerce stock modified): failed to fetch stock.quant — ' . $err,
                );
            }

            foreach ( $quants as $quant ) {
                $pid = isset( $quant['product_id'][0] ) ? (int) $quant['product_id'][0] : 0;
                if ( isset( $product_map[ $pid ] ) ) {
                    $sku                   = $product_map[ $pid ];
                    $available             = (float) ( $quant['quantity'] ?? 0 ) - (float) ( $quant['reserved_quantity'] ?? 0 );
                    $stock_totals[ $sku ] += $available;
                }
            }
        }

        // 3.5 Subtract UNCONFIRMED Online Draft/Sent Orders (FAIL-CLOSED).
        $draft_qty_by_product = $this->fetch_draft_order_quantities( $client, $config, $uid, $odoo_product_ids );
        if ( is_wp_error( $draft_qty_by_product ) ) {
            return array(
                'status'  => 'error',
                'message' => 'Sync aborted (no WooCommerce stock modified): failed to fetch unconfirmed online orders — ' . $draft_qty_by_product->get_error_message(),
            );
        }

        foreach ( $draft_qty_by_product as $pid => $draft_qty ) {
            if ( isset( $product_map[ $pid ] ) ) {
                $sku                   = $product_map[ $pid ];
                $stock_totals[ $sku ] -= (float) $draft_qty;
            }
        }

        // 3.8 Fetch Regular Prices from Explicit Odoo Pricelist (template rules first, variant overrides second).
        $sync_price_enabled   = ( isset( $config['sync_price'] ) && 'yes' === $config['sync_price'] );
        $price_sync_ok        = $sync_price_enabled;
        $pricelist_diagnostic = $sync_price_enabled ? ' [Standard Price Sync Active]' : '';
        $pricelist_id         = isset( $config['pricelist_id'] ) ? (int) $config['pricelist_id'] : 0;
        $sync_status          = 'success';

        if ( $sync_price_enabled && $pricelist_id > 0 ) {
            $pricelist_result = $this->apply_pricelist_prices(
                $client,
                $config,
                $uid,
                $pricelist_id,
                $odoo_product_ids,
                $odoo_tmpl_ids,
                $product_map,
                $tmpl_to_skus,
                $sku_prices
            );

            if ( is_wp_error( $pricelist_result ) ) {
                // Skip price mutations on pricelist error while still allowing verified stock updates.
                $price_sync_ok        = false;
                $sync_status          = 'warning';
                $pricelist_diagnostic = sprintf(
                    ' [Warning: Skipped price updates for Pricelist ID %d — %s]',
                    $pricelist_id,
                    $pricelist_result->get_error_message()
                );
            } else {
                $sku_prices           = $pricelist_result['prices'];
                $pricelist_diagnostic = sprintf(
                    ' [Pricelist ID %d Connected: Applied %d template rules, %d variant rules]',
                    $pricelist_id,
                    $pricelist_result['template_rules'],
                    $pricelist_result['variant_rules']
                );
            }
        }

        // 4. Write Verified Stock & Regular Price Updates to WooCommerce.
        $updated_stock_count = 0;
        $updated_price_count = 0;
        $price_decimals      = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;

        foreach ( $stock_totals as $sku => $net_qty ) {
            $is_forced_oos = ! empty( $force_oos_map[ $sku ] );
            $final_qty     = $this->calculate_final_qty( (float) $net_qty, 0.0, $is_forced_oos );
            $target_status = $final_qty > 0 ? 'instock' : 'outofstock';

            $woo_product_id = wc_get_product_id_by_sku( $sku );
            if ( ! $woo_product_id ) {
                continue;
            }

            $product = wc_get_product( $woo_product_id );
            if ( ! $product ) {
                continue;
            }

            $product_changed = false;

            if ( $product->get_manage_stock() ) {
                $current_qty    = $product->get_stock_quantity();
                $current_status = (string) $product->get_stock_status();

                $qty_differs    = ( null === $current_qty ) || ( abs( (float) $current_qty - $final_qty ) > 0.0001 );
                $status_differs = ( $current_status !== $target_status );

                if ( $qty_differs || $status_differs ) {
                    $product->set_stock_quantity( $final_qty );
                    $product->set_stock_status( $target_status );
                    $updated_stock_count++;
                    $product_changed = true;
                }
            }

            if ( $price_sync_ok && isset( $sku_prices[ $sku ] ) && $sku_prices[ $sku ] > 0 ) {
                $target_price_str = wc_format_decimal( (float) $sku_prices[ $sku ], $price_decimals );
                $current_raw      = (string) $product->get_regular_price();
                $current_reg_str  = '' !== $current_raw ? wc_format_decimal( (float) $current_raw, $price_decimals ) : '';

                if ( $current_reg_str !== $target_price_str ) {
                    $product->set_regular_price( $target_price_str );

                    // Preserve any active manual WooCommerce sale price until Phase 2 Loyalty Sale Price sync.
                    if ( '' === (string) $product->get_sale_price() ) {
                        $product->set_price( $target_price_str );
                    }

                    $updated_price_count++;
                    $product_changed = true;
                }
            }

            if ( $product_changed ) {
                $product->save();
            }
        }

        if ( $is_full_sync ) {
            update_option( 'owsc_last_sync_time', gmdate( 'Y-m-d H:i:s' ), false );
        }

        return array(
            'status'  => $sync_status,
            'message' => sprintf(
                'Sync complete. Updated Stock for %d items. Updated Prices for %d items.%s',
                $updated_stock_count,
                $updated_price_count,
                $pricelist_diagnostic
            ),
        );
    }

    /**
     * Loads plugin configuration, validates completeness, and authenticates against Odoo.
     *
     * @return array{client:OWSC_Odoo_XMLRPC_Client,config:array,uid:int}|WP_Error
     */
    private function connect_and_authenticate() {
        $config = OWSCPluginV2::configuration();
        if ( empty( $config['url'] ) || empty( $config['database'] ) || empty( $config['username'] ) || empty( $config['api_key'] ) ) {
            return new WP_Error( 'owsc_incomplete_config', 'Odoo configuration incomplete. Cannot run sync.' );
        }

        $client = new OWSC_Odoo_XMLRPC_Client( $config['url'] );
        $uid    = $client->authenticate( $config['database'], $config['username'], $config['api_key'] );

        if ( is_wp_error( $uid ) ) {
            return new WP_Error(
                'owsc_auth_failed',
                'Odoo authentication failed: ' . $uid->get_error_message()
            );
        }

        if ( ! is_int( $uid ) || $uid <= 0 ) {
            return new WP_Error(
                'owsc_auth_rejected',
                'Odoo authentication rejected. Check database, username, and API key.'
            );
        }

        return array(
            'client' => $client,
            'config' => $config,
            'uid'    => $uid,
        );
    }

    /**
     * Resolves Odoo location IDs for the approved root warehouse stock locations.
     *
     * @param OWSC_Odoo_XMLRPC_Client $client Authenticated client.
     * @param array                   $config Plugin configuration.
     * @param int                     $uid    Authenticated Odoo user ID.
     * @return array<int,int>|WP_Error
     */
    private function fetch_approved_location_ids( OWSC_Odoo_XMLRPC_Client $client, array $config, int $uid ) {
        $locations = $client->execute_kw(
            $config['database'],
            $uid,
            $config['api_key'],
            'stock.location',
            'search_read',
            array( array( array( 'complete_name', 'in', self::APPROVED_LOCATIONS ) ) ),
            array( 'fields' => array( 'id', 'complete_name' ) )
        );

        if ( is_wp_error( $locations ) || ! is_array( $locations ) ) {
            $err = is_wp_error( $locations ) ? $locations->get_error_message() : 'Unexpected stock.location response.';
            return new WP_Error( 'owsc_locations_failed', 'Failed to resolve approved stock locations — ' . $err );
        }

        $location_ids = array_values( array_filter( array_map( 'intval', array_column( $locations, 'id' ) ) ) );
        if ( empty( $location_ids ) ) {
            return new WP_Error(
                'owsc_locations_empty',
                sprintf(
                    'None of the approved Odoo stock locations (%s) were found.',
                    implode( ', ', self::APPROVED_LOCATIONS )
                )
            );
        }

        return $location_ids;
    }

    /**
     * Sums unconfirmed (`draft` / `sent`) online order quantities per Odoo `product.product` ID.
     *
     * @param OWSC_Odoo_XMLRPC_Client $client           Authenticated client.
     * @param array                   $config           Plugin configuration.
     * @param int                     $uid              Authenticated Odoo user ID.
     * @param array<int,int>          $odoo_product_ids Target Odoo product IDs.
     * @return array<int,float>|WP_Error Map of product_id => total unconfirmed ordered qty.
     */
    private function fetch_draft_order_quantities(
        OWSC_Odoo_XMLRPC_Client $client,
        array $config,
        int $uid,
        array $odoo_product_ids
    ) {
        $totals = array();
        if ( empty( $odoo_product_ids ) ) {
            return $totals;
        }

        $draft_orders = $client->execute_kw(
            $config['database'],
            $uid,
            $config['api_key'],
            'sale.order',
            'search_read',
            array(
                array(
                    array( 'state', 'in', array( 'draft', 'sent' ) ),
                    array( self::FIELD_IS_ONLINE_ORDER, '=', true ),
                    array( self::FIELD_WOO_ORDER_ID, 'ilike', 'WOO-' ),
                ),
            ),
            array( 'fields' => array( 'id' ) )
        );

        if ( is_wp_error( $draft_orders ) || ! is_array( $draft_orders ) ) {
            $err = is_wp_error( $draft_orders ) ? $draft_orders->get_error_message() : 'Unexpected sale.order response.';
            return new WP_Error( 'owsc_draft_orders_failed', $err );
        }

        $draft_order_ids = array_values( array_filter( array_map( 'intval', array_column( $draft_orders, 'id' ) ) ) );
        if ( empty( $draft_order_ids ) ) {
            return $totals;
        }

        foreach ( array_chunk( $odoo_product_ids, self::RPC_BATCH_SIZE ) as $product_batch ) {
            $draft_lines = $client->execute_kw(
                $config['database'],
                $uid,
                $config['api_key'],
                'sale.order.line',
                'search_read',
                array(
                    array(
                        array( 'order_id', 'in', $draft_order_ids ),
                        array( 'product_id', 'in', $product_batch ),
                    ),
                ),
                array( 'fields' => array( 'product_id', 'product_uom_qty' ) )
            );

            if ( is_wp_error( $draft_lines ) || ! is_array( $draft_lines ) ) {
                $err = is_wp_error( $draft_lines ) ? $draft_lines->get_error_message() : 'Unexpected sale.order.line response.';
                return new WP_Error( 'owsc_draft_lines_failed', $err );
            }

            foreach ( $draft_lines as $line ) {
                $pid = isset( $line['product_id'][0] ) ? (int) $line['product_id'][0] : 0;
                if ( $pid <= 0 ) {
                    continue;
                }
                if ( ! isset( $totals[ $pid ] ) ) {
                    $totals[ $pid ] = 0.0;
                }
                $totals[ $pid ] += (float) ( $line['product_uom_qty'] ?? 0 );
            }
        }

        return $totals;
    }

    /**
     * Applies active fixed-price Odoo Pricelist rules onto the SKU regular-price map.
     *
     * Template-level rules are applied to all child variants first; variant-specific rules
     * are applied second so variant overrides always take precedence.
     *
     * @param OWSC_Odoo_XMLRPC_Client  $client           Authenticated client.
     * @param array                    $config           Plugin configuration.
     * @param int                      $uid              Authenticated Odoo user ID.
     * @param int                      $pricelist_id     Odoo `product.pricelist` ID.
     * @param array<int,int>           $odoo_product_ids Synced `product.product` IDs.
     * @param array<int,int>           $odoo_tmpl_ids    Synced `product.template` IDs.
     * @param array<int,string>        $product_map      Map of product_id => SKU.
     * @param array<int,array<string>> $tmpl_to_skus     Map of template_id => list of SKUs.
     * @param array<string,float>      $sku_prices       Baseline `list_price` map per SKU.
     * @return array{prices:array<string,float>,template_rules:int,variant_rules:int}|WP_Error
     */
    private function apply_pricelist_prices(
        OWSC_Odoo_XMLRPC_Client $client,
        array $config,
        int $uid,
        int $pricelist_id,
        array $odoo_product_ids,
        array $odoo_tmpl_ids,
        array $product_map,
        array $tmpl_to_skus,
        array $sku_prices
    ) {
        $now_utc        = gmdate( 'Y-m-d H:i:s' );
        $template_rules = 0;
        $variant_rules  = 0;

        $base_domain = array(
            array( 'pricelist_id', '=', $pricelist_id ),
            array( 'compute_price', '=', 'fixed' ),
            array( 'min_quantity', '<=', 1 ),
            '|',
            array( 'date_start', '=', false ),
            array( 'date_start', '<=', $now_utc ),
            '|',
            array( 'date_end', '=', false ),
            array( 'date_end', '>=', $now_utc ),
        );

        // Pass 1: Template-level rules (product_id = false) applied to all synced variants of that template.
        if ( ! empty( $odoo_tmpl_ids ) ) {
            foreach ( array_chunk( $odoo_tmpl_ids, self::RPC_BATCH_SIZE ) as $tmpl_batch ) {
                $tmpl_domain   = $base_domain;
                $tmpl_domain[] = array( 'product_id', '=', false );
                $tmpl_domain[] = array( 'product_tmpl_id', 'in', $tmpl_batch );

                $tmpl_items = $client->execute_kw(
                    $config['database'],
                    $uid,
                    $config['api_key'],
                    'product.pricelist.item',
                    'search_read',
                    array( $tmpl_domain ),
                    array( 'fields' => array( 'product_tmpl_id', 'fixed_price' ) )
                );

                if ( is_wp_error( $tmpl_items ) || ! is_array( $tmpl_items ) ) {
                    $err = is_wp_error( $tmpl_items ) ? $tmpl_items->get_error_message() : 'Unexpected pricelist template response.';
                    return new WP_Error( 'owsc_pricelist_tmpl_failed', $err );
                }

                foreach ( $tmpl_items as $item ) {
                    $price   = isset( $item['fixed_price'] ) ? (float) $item['fixed_price'] : 0.0;
                    $tmpl_id = isset( $item['product_tmpl_id'][0] ) ? (int) $item['product_tmpl_id'][0] : 0;

                    if ( $price <= 0 || $tmpl_id <= 0 || empty( $tmpl_to_skus[ $tmpl_id ] ) ) {
                        continue;
                    }

                    $template_rules++;
                    foreach ( $tmpl_to_skus[ $tmpl_id ] as $sku ) {
                        $sku_prices[ $sku ] = $price;
                    }
                }
            }
        }

        // Pass 2: Variant-level rules (explicit product_id) override template rules.
        foreach ( array_chunk( $odoo_product_ids, self::RPC_BATCH_SIZE ) as $product_batch ) {
            $var_domain   = $base_domain;
            $var_domain[] = array( 'product_id', 'in', $product_batch );

            $var_items = $client->execute_kw(
                $config['database'],
                $uid,
                $config['api_key'],
                'product.pricelist.item',
                'search_read',
                array( $var_domain ),
                array( 'fields' => array( 'product_id', 'fixed_price' ) )
            );

            if ( is_wp_error( $var_items ) || ! is_array( $var_items ) ) {
                $err = is_wp_error( $var_items ) ? $var_items->get_error_message() : 'Unexpected pricelist variant response.';
                return new WP_Error( 'owsc_pricelist_var_failed', $err );
            }

            foreach ( $var_items as $item ) {
                $price = isset( $item['fixed_price'] ) ? (float) $item['fixed_price'] : 0.0;
                $pid   = isset( $item['product_id'][0] ) ? (int) $item['product_id'][0] : 0;

                if ( $price <= 0 || $pid <= 0 || ! isset( $product_map[ $pid ] ) ) {
                    continue;
                }

                $variant_rules++;
                $sku_prices[ $product_map[ $pid ] ] = $price;
            }
        }

        return array(
            'prices'         => $sku_prices,
            'template_rules' => $template_rules,
            'variant_rules'  => $variant_rules,
        );
    }

    /**
     * Canonical final stock quantity formula shared by run_sync() and inspect_sku().
     *
     * @param float $approved_qty    Available quantity across approved warehouses & child bins.
     * @param float $draft_order_qty Unconfirmed online draft/sent order quantity.
     * @param bool  $force_oos       Whether x_studio_force_out_of_stock is enabled in Odoo.
     * @return float
     */
    private function calculate_final_qty( float $approved_qty, float $draft_order_qty, bool $force_oos ): float {
        if ( $force_oos ) {
            return 0.0;
        }
        return max( 0.0, $approved_qty - $draft_order_qty );
    }
}
