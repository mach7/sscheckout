<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SSC_Cart {

    /**
     * Returns the current user's unique ID.
     */
    public function get_user_id() {
        return sscheckout_get_user_id();
    }
    
    /**
     * Generates the add-to-cart button markup.
     */
    public function add_to_cart_button( $price, $name ) {
        $user_id = $this->get_user_id();
        global $wpdb;
        $table_cart = $wpdb->prefix . 'sscheckout_cart';
        $item = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table_cart WHERE user_id = %s AND product_name = %s",
            $user_id, $name
        ) );
        
        if ( $item ) {
            // Display the quantity with - and + buttons
            $quantity = intval( $item->quantity );
            $button  = '<button class="ssc-decrease" data-name="' . esc_attr( $name ) . '">-</button>';
            $button .= '<span class="ssc-quantity">' . esc_html( $quantity ) . '</span>';
            $button .= '<button class="ssc-increase" data-name="' . esc_attr( $name ) . '" data-price="' . esc_attr( $price ) . '">+</button>';
        } else {
            // Display a simple Add to Cart button
            $button = '<button class="ssc-add-to-cart" data-name="' . esc_attr( $name ) . '" data-price="' . esc_attr( $price ) . '">Add to Cart</button>';
        }
        
        return $button;
    }
    
    /**
     * Handles AJAX updates to the cart (adding or removing items).
     */
    public function handle_cart_update() {
        if ( ! isset( $_POST['action'] ) || $_POST['action'] !== 'ssc_update_cart' ) {
            wp_send_json_error( 'Invalid action' );
        }
        
        global $wpdb;
        $user_id      = $this->get_user_id();
        $product_name = sanitize_text_field( $_POST['name'] );
        $price        = floatval( $_POST['price'] );
        $operation    = sanitize_text_field( $_POST['operation'] ); // 'add' or 'remove'
        
        $table_cart = $wpdb->prefix . 'sscheckout_cart';
        $item = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table_cart WHERE user_id = %s AND product_name = %s",
            $user_id, $product_name
        ) );
        
        if ( $operation === 'add' ) {
            if ( $item ) {
                $new_quantity = $item->quantity + 1;
                $wpdb->update( $table_cart, array( 'quantity' => $new_quantity ), array( 'id' => $item->id ) );
            } else {
                $wpdb->insert( $table_cart, array(
                    'user_id'      => $user_id,
                    'product_name' => $product_name,
                    'price'        => $price,
                    'quantity'     => 1
                ) );
            }
        } elseif ( $operation === 'remove' ) {
            if ( $item ) {
                $new_quantity = $item->quantity - 1;
                if ( $new_quantity > 0 ) {
                    $wpdb->update( $table_cart, array( 'quantity' => $new_quantity ), array( 'id' => $item->id ) );
                } else {
                    $wpdb->delete( $table_cart, array( 'id' => $item->id ) );
                }
            }
        }
        
        wp_send_json_success( 'Cart updated' );
    }
    
    /**
     * Retrieves all cart items for the current user.
     */
    public function get_cart_items( $user_id = null ) {
        global $wpdb;
        if ( ! $user_id ) {
            $user_id = $this->get_user_id();
        }
        $table_cart = $wpdb->prefix . 'sscheckout_cart';
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table_cart WHERE user_id = %s",
            $user_id
        ) );
    }
    
    /**
     * Renders the cart using a template.
     */
    public function render_cart() {
        $user_id = $this->get_user_id();
        $items   = $this->get_cart_items( $user_id );
        ob_start();
        include SSCHECKOUT_PLUGIN_DIR . 'templates/cart-template.php';
        return ob_get_clean();
    }
    
    /**
     * Wrapper to return the cart’s HTML.
     */
    public function get_cart_items_html() {
        return $this->render_cart();
    }
}

// AJAX handler: Load cart contents
add_action( 'wp_ajax_ssc_load_cart', 'ssc_load_cart_callback' );
add_action( 'wp_ajax_nopriv_ssc_load_cart', 'ssc_load_cart_callback' );
function ssc_load_cart_callback() {
    $cart = new SSC_Cart();
    echo $cart->render_cart();
    wp_die();
}

// AJAX handler: Update cart items (add/remove)
add_action( 'wp_ajax_ssc_update_cart', function() {
    $cart = new SSC_Cart();
    $cart->handle_cart_update();
});
add_action( 'wp_ajax_nopriv_ssc_update_cart', function() {
    $cart = new SSC_Cart();
    $cart->handle_cart_update();
});
