<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OWSC_SKU_Audit_Admin {
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_post_owsc_run_sku_audit', array( $this, 'handle_run' ) );
        add_action( 'admin_post_owsc_run_sku_sync', array( $this, 'handle_sync' ) ); // New Sync Handler
    }

    public function register_menu(): void {
        add_submenu_page( 
            'woocommerce', 
            'Odoo SKU Audit', 
            'Odoo SKU Audit', 
            'manage_woocommerce', 
            'owsc-sku-audit', 
            array( $this, 'render_page' ) 
        );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $result = get_transient( 'owsc_sku_audit_' . get_current_user_id() );
        delete_transient( 'owsc_sku_audit_' . get_current_user_id() );
        $sku = isset( $_GET['sku'] ) ? sanitize_text_field( wp_unslash( $_GET['sku'] ) ) : '96522-8109'; 

        ?>
        <div class="wrap">
            <h1>Odoo SKU Audit</h1>
            <p><strong>Phase:</strong> Manual One-SKU Write Test.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="owsc_run_sku_audit">
                <?php wp_nonce_field( 'owsc_run_sku_audit' ); ?>
                <label for="owsc-sku"><strong>Exact SKU</strong></label>
                <p><input id="owsc-sku" name="sku" type="text" class="regular-text" value="<?php echo esc_attr( $sku ); ?>" required></p>
                <?php submit_button( 'Run Read-only Preflight', 'primary', 'submit', false ); ?>
            </form>
            <?php $this->render_result( $result, $sku ); ?>
        </div>
        <?php
    }

    public function handle_run(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Unauthorized.' );
        }
        check_admin_referer( 'owsc_run_sku_audit' );
        
        $sku = isset( $_POST['sku'] ) ? sanitize_text_field( wp_unslash( $_POST['sku'] ) ) : '';
        $audit_service = new OWSC_SKU_Audit();
        $result = $audit_service->preflight( $sku );
        
        set_transient( 'owsc_sku_audit_' . get_current_user_id(), $result, MINUTE_IN_SECONDS );
        
        wp_safe_redirect( add_query_arg( array(
            'page' => 'owsc-sku-audit',
            'sku'  => rawurlencode( $sku )
        ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public function handle_sync(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Unauthorized.' );
        }
        check_admin_referer( 'owsc_run_sku_sync' );
        
        $sku             = isset( $_POST['sku'] ) ? sanitize_text_field( wp_unslash( $_POST['sku'] ) ) : '';
        $woo_id          = isset( $_POST['woo_id'] ) ? absint( $_POST['woo_id'] ) : 0;
        $proposed_qty    = isset( $_POST['proposed_qty'] ) ? (float) $_POST['proposed_qty'] : 0;
        $proposed_status = isset( $_POST['proposed_status'] ) ? sanitize_text_field( wp_unslash( $_POST['proposed_status'] ) ) : 'outofstock';

        $audit_service = new OWSC_SKU_Audit();
        
        // 1. Execute the sync
        $sync_result = $audit_service->sync_stock( $sku, $woo_id, $proposed_qty, $proposed_status );
        
        // 2. Re-run the preflight to show the updated, live data
        $result = $audit_service->preflight( $sku );
        
        // 3. Attach the success/error message to the top of the results
        array_unshift( $result['messages'], $sync_result['message'] );

        set_transient( 'owsc_sku_audit_' . get_current_user_id(), $result, MINUTE_IN_SECONDS );
        
        wp_safe_redirect( add_query_arg( array(
            'page' => 'owsc-sku-audit',
            'sku'  => rawurlencode( $sku )
        ), admin_url( 'admin.php' ) ) );
        exit;
    }

    private function render_result( $result, $sku ): void {
        if ( ! is_array( $result ) ) {
            return;
        }

        // Output Messages first so success notes are highly visible
        if ( ! empty( $result['messages'] ) && is_array( $result['messages'] ) ) {
            foreach ( $result['messages'] as $message ) {
                $class = strpos( $message, 'SUCCESS:' ) !== false ? 'notice-success' : 'notice-info';
                echo '<div class="notice ' . esc_attr( $class ) . '"><p><strong>' . esc_html( (string) $message ) . '</strong></p></div>';
            }
        }

        $status       = isset( $result['status'] ) ? $result['status'] : 'error';
        $products     = isset( $result['odoo_products'] ) && is_array( $result['odoo_products'] ) ? $result['odoo_products'] : array();
        $woo_products = isset( $result['woocommerce_products'] ) && is_array( $result['woocommerce_products'] ) ? $result['woocommerce_products'] : array();
        $fields       = isset( $result['fields'] ) && is_array( $result['fields'] ) ? $result['fields'] : array();

        echo '<hr><h2>Preflight result</h2><p><strong>Status:</strong> ' . esc_html( strtoupper( $status ) ) . '</p>';
        echo '<p><strong>default_code field:</strong> ' . ( isset( $fields['default_code'] ) ? 'Present' : 'Missing' ) . '<br>';
        echo '<strong>x_studio_available_for_woocommerce_sync field:</strong> ' . ( isset( $fields['x_studio_available_for_woocommerce_sync'] ) ? 'Present' : 'Missing' ) . '</p>';
        echo '<p><strong>Exact Odoo SKU matches:</strong> ' . count( $products ) . '</p>';

        if ( 1 === count( $products ) ) {
            $product = $products[0];
            echo '<table class="widefat striped"><tbody>';
            echo '<tr><td>Odoo product ID</td><td>' . esc_html( (string) ( $product['id'] ?? '' ) ) . '</td></tr>';
            echo '<tr><td>SKU</td><td>' . esc_html( (string) ( $product['default_code'] ?? '' ) ) . '</td></tr>';
            echo '<tr><td>Product template</td><td>' . esc_html( wp_json_encode( $product['product_tmpl_id'] ?? '' ) ) . '</td></tr>';
            echo '<tr><td>WooCommerce eligible</td><td>' . esc_html( ! empty( $product['x_studio_available_for_woocommerce_sync'] ) ? 'Yes' : 'No' ) . '</td></tr>';
            echo '</tbody></table>';
        }

        echo '<h2>WooCommerce exact-SKU discovery</h2><p><strong>Exact WooCommerce SKU matches:</strong> ' . count( $woo_products ) . '</p>';

        if ( 1 === count( $woo_products ) ) {
            echo '<p><strong>Mapping status:</strong> Valid exact-SKU match.</p>';
        } elseif ( 0 === count( $woo_products ) ) {
            echo '<p><strong>Mapping status:</strong> Missing. No WooCommerce product or variation has this exact SKU.</p>';
        } else {
            echo '<p><strong>Mapping status:</strong> Duplicate. Resolve duplicate WooCommerce SKUs before synchronization.</p>';
        }

        if ( ! empty( $woo_products ) ) {
            echo '<table class="widefat striped"><thead><tr><th>WooCommerce ID</th><th>Type</th><th>Parent ID</th><th>Manage stock</th><th>Stock quantity</th><th>Stock status</th><th>Backorders</th></tr></thead><tbody>';
            foreach ( $woo_products as $woo_product ) {
                echo '<tr>';
                echo '<td>' . esc_html( (string) ( $woo_product['id'] ?? '' ) ) . '</td>';
                echo '<td>' . esc_html( (string) ( $woo_product['type'] ?? '' ) ) . '</td>';
                echo '<td>' . esc_html( (string) ( $woo_product['parent_id'] ?? 0 ) ) . '</td>';
                echo '<td>' . esc_html( ! empty( $woo_product['manage_stock'] ) ? 'Yes' : 'No' ) . '</td>';
                echo '<td>' . esc_html( null === ( $woo_product['stock_quantity'] ?? null ) ? 'Not managed' : (string) $woo_product['stock_quantity'] ) . '</td>';
                echo '<td>' . esc_html( (string) ( $woo_product['stock_status'] ?? '' ) ) . '</td>';
                echo '<td>' . esc_html( (string) ( $woo_product['backorders'] ?? '' ) ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        // --- Proposed Synchronization Preview with Action Button ---
        if ( isset( $result['proposed_qty'] ) && 1 === count( $woo_products ) ) {
            $proposed = $result['proposed_qty'];
            $current  = $woo_products[0]['stock_quantity'];
            $proposed_status = $proposed > 0 ? 'instock' : 'outofstock';
            
            echo '<h2>Proposed Synchronization Preview</h2>';
            echo '<table class="widefat striped"><thead><tr><th>Approved Odoo Locations</th><th>Calculated Odoo Stock</th><th>Current WooCommerce Stock</th><th>Proposed Action</th></tr></thead><tbody>';
            echo '<tr>';
            echo '<td>WH/Stock, MC/Stock, JM/Stock</td>';
            echo '<td>' . esc_html( (string) $proposed ) . '</td>';
            echo '<td>' . esc_html( null === $current ? 'Not managed' : (string) $current ) . '</td>';
            echo '<td><strong>Update quantity to ' . esc_html( (string) $proposed ) . ' and status to ' . esc_html( $proposed_status ) . '</strong></td>';
            echo '</tr>';
            echo '</tbody></table>';

            // Only show the sync button if quantities are out of sync
            if ( (float) $proposed !== (float) $current ) {
                echo '<br><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block; margin-top: 10px; padding: 15px; border: 1px solid #ccd0d4; background: #fff;">';
                echo '<input type="hidden" name="action" value="owsc_run_sku_sync">';
                wp_nonce_field( 'owsc_run_sku_sync' );
                echo '<input type="hidden" name="sku" value="' . esc_attr( $sku ) . '">';
                echo '<input type="hidden" name="woo_id" value="' . esc_attr( $woo_products[0]['id'] ) . '">';
                echo '<input type="hidden" name="proposed_qty" value="' . esc_attr( $proposed ) . '">';
                echo '<input type="hidden" name="proposed_status" value="' . esc_attr( $proposed_status ) . '">';
                echo '<p>This will write data to WooCommerce immediately.</p>';
                submit_button( 'Execute Sync to WooCommerce', 'primary', 'submit', false );
                echo '</form>';
            } else {
                echo '<br><div class="notice notice-success inline" style="margin-top: 15px;"><p><strong>WooCommerce is already in sync with Odoo for this SKU.</strong></p></div>';
            }
        }

        if ( isset( $result['locations'] ) && is_array( $result['locations'] ) ) {
            echo '<h2>Raw Odoo stock by location</h2>';
            if ( empty( $result['locations'] ) ) {
                echo '<p>No stock.quant records found for this product.</p>';
            } else {
                echo '<table class="widefat striped"><thead><tr><th>Location ID</th><th>Complete location name</th><th>Usage type</th><th>On hand</th><th>Reserved</th><th>Available</th></tr></thead><tbody>';
                foreach ( $result['locations'] as $loc ) {
                    echo '<tr>';
                    echo '<td>' . esc_html( (string) $loc['location_id'] ) . '</td>';
                    echo '<td>' . esc_html( (string) $loc['complete_name'] ) . '</td>';
                    echo '<td>' . esc_html( (string) $loc['usage'] ) . '</td>';
                    echo '<td>' . esc_html( (string) $loc['quantity'] ) . '</td>';
                    echo '<td>' . esc_html( (string) $loc['reserved'] ) . '</td>';
                    echo '<td><strong>' . esc_html( (string) $loc['available'] ) . '</strong></td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            }
        }
    }
}
