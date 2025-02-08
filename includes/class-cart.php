<?php
if (!defined('ABSPATH')) {
    exit;
}

class SSC_Cart {
    public static function add_to_cart_button($atts) {
        $atts = shortcode_atts([
            'price' => '',
            'name' => '',
        ], $atts);

        $price = intval($atts['price']);
        $name = sanitize_text_field($atts['name']);
        
        if (empty($price) || empty($name)) {
            return '<p>Invalid product data.</p>';
        }
        
        $user_id = SSC_Cart::get_user_id();
        $current_quantity = SSC_Cart::get_cart_quantity($user_id, $name);
        
        if ($current_quantity > 0) {
            return "<div class='ssc-cart-actions'>
                        <button class='ssc-cart-btn' data-action='decrease' data-name='$name' data-price='$price'>-</button>
                        <span class='ssc-cart-quantity'>$current_quantity</span>
                        <button class='ssc-cart-btn' data-action='increase' data-name='$name' data-price='$price'>+</button>
                    </div>";
        }
        
        return "<button class='ssc-cart-btn' data-action='add' data-name='$name' data-price='$price'>Add to Cart</button>";
    }

    public static function handle_cart_update() {
        if (!isset($_POST['cart_action'], $_POST['product_name'], $_POST['product_price'])) {
            wp_send_json_error(['message' => 'Invalid data received.']);
        }
    
        global $wpdb;
        $user_id = SSC_Cart::get_user_id();
        $name = sanitize_text_field($_POST['product_name']);
        $price = intval($_POST['product_price']);
        $action = sanitize_text_field($_POST['cart_action']);
    
        error_log("Cart action: $action, Name: $name, Price: $price"); // Debugging step
    
        $table_name = $wpdb->prefix . 'ssc_cart';
        $current_quantity = SSC_Cart::get_cart_quantity($user_id, $name);
    
        if ($action === 'add' || $action === 'increase') {
            if ($current_quantity > 0) {
                $wpdb->update($table_name, ['quantity' => $current_quantity + 1], ['userID' => $user_id, 'name' => $name]);
            } else {
                $wpdb->insert($table_name, ['userID' => $user_id, 'name' => $name, 'price' => $price, 'quantity' => 1]);
            }
        } elseif ($action === 'decrease') {
            if ($current_quantity > 1) {
                $wpdb->update($table_name, ['quantity' => $current_quantity - 1], ['userID' => $user_id, 'name' => $name]);
            } else {
                $wpdb->delete($table_name, ['userID' => $user_id, 'name' => $name]);
            }
        }
    
        $cart_total = SSC_Cart::get_cart_total($user_id);
        $cart_html = SSC_Cart::render_cart();
    
        error_log("AJAX Response - Cart Total: $cart_total, Cart HTML: " . print_r($cart_html, true)); // Debugging step
    
        wp_send_json_success([
            'cart_total' => SSC_Helpers::format_price($cart_total),
            'cart_html' => $cart_html,
        ]);
    }
    

    public static function get_cart_quantity($user_id, $name) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ssc_cart';
        return (int) $wpdb->get_var($wpdb->prepare("SELECT quantity FROM $table_name WHERE userID = %s AND name = %s", $user_id, $name));
    }

    public static function get_cart_total($user_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ssc_cart';
        return (int) $wpdb->get_var($wpdb->prepare("SELECT SUM(price * quantity) FROM $table_name WHERE userID = %s", $user_id));
    }

    public static function render_cart() {
        // Placeholder function for rendering the cart UI dynamically.
        return '<p>Cart content will be updated dynamically.</p>';
    }

    private static function get_user_id() {
        if (is_user_logged_in()) {
            return get_current_user_id();
        }
        if (!isset($_COOKIE['ssc_user_id'])) {
            setcookie('ssc_user_id', uniqid(), time() + (86400 * 30), '/');
        }
        return $_COOKIE['ssc_user_id'];
    }
}

add_action('wp_ajax_ssc_update_cart', ['SSC_Cart', 'handle_cart_update']);
add_action('wp_ajax_nopriv_ssc_update_cart', ['SSC_Cart', 'handle_cart_update']);
