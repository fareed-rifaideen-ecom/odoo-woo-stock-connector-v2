<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OWSC_SKU_Audit_Admin {
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_post_owsc_run_sku_audit', array( $this, 'handle_run' ) );
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
        $sku = isset( $_GET['sku'] ) ? sanitize_text_field( wp_unslash( $_GET['sku'] ) ) : '96522-8109'; // Default pilot SKU

        ?>
        <div class="wrap">
            <h1>Odoo SKU Audit</h1>
            <p><strong>Phase:</strong> Read-only Odoo and WooCommerce SKU preflight. No records are changed.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="owsc_run_sku_audit">
                <?php wp_nonce_field( 'owsc_run_sku_audit' ); ?>
                <label for="owsc-sku"><strong>Exact SKU</strong></label>
                <p><input id="owsc-sku" name="sku" type="text" class="regular-text" value="<?php echo esc_attr( $sku ); ?>" required></p>
                <?php submit_button( 'Run Read-only Preflight', 'primary', 'submit', false ); ?>
            </form>
            <?php $this->render_result( $result ); ?>
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

    private function render_result( $result ): void {
        if ( ! is_array( $result ) ) {
            return;
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
            echo '<p><strong>Mapping status:</strong> Valid exact-SKU match. Read-only discovery only.</p>';
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

        // --- NEW: Render Odoo Locations Table ---
        if ( isset( $result['locations'] ) && is_array( $result['locations'] ) ) {
            echo '<h2>Odoo stock by location</h2>';
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

        if ( ! empty( $result['messages'] ) && is_array( $result['messages'] ) ) {
            foreach ( $result['messages'] as $message ) {
                echo '<p>' . esc_html( (string) $message ) . '</p>';
            }
        }
    }
}
