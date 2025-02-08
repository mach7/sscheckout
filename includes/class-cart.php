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
    
        error_log("Cart action: $action, Name: $name, Price: $price");
    
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
    
        // Debug log
        error_log("Cart Total: " . print_r($cart_total, true));
        error_log("Cart HTML: " . print_r($cart_html, true));
    
        // Fix JSON encoding issue
        wp_send_json_success([
            'cart_total' => SSC_Helpers::format_price($cart_total),
            'cart_html' => trim($cart_html),
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
        ob_start();
    
        $user_id = SSC_Helpers::get_user_id();
        $cart_items = self::get_cart_items($user_id);
        $cart_total = self::get_cart_total($user_id);
        ?>
        <div class="ssc-cart-container">
            <h2>Your Cart</h2>
            <div id="ssc-cart-items">
                <?php if (!empty($cart_items)) : ?>
                    <ul>
                        <?php foreach ($cart_items as $item) : ?>
                            <li class="ssc-cart-item">
                                <span><?php echo esc_html($item->name); ?></span>
                                <div class="ssc-cart-actions">
                                    <button class="ssc-cart-btn" data-action="decrease" data-name="<?php echo esc_attr($item->name); ?>" data-price="<?php echo esc_attr($item->price); ?>">-</button>
                                    <span class="ssc-cart-quantity"><?php echo esc_html($item->quantity); ?></span>
                                    <button class="ssc-cart-btn" data-action="increase" data-name="<?php echo esc_attr($item->name); ?>" data-price="<?php echo esc_attr($item->price); ?>">+</button>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else : ?>
                    <p>Your cart is empty.</p>
                <?php endif; ?>
            </div>
            <p class="ssc-checkout-total">Total: <span id="ssc-cart-total"><?php echo SSC_Helpers::format_price($cart_total); ?></span></p>
        </div>
        <?php
        return ob_get_clean();
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
    public static function get_cart_items($user_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ssc_cart';
    
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name WHERE userID = %s", $user_id));
    }
    
}

add_action('wp_ajax_ssc_update_cart', ['SSC_Cart', 'handle_cart_update']);
add_action('wp_ajax_nopriv_ssc_update_cart', ['SSC_Cart', 'handle_cart_update']);
