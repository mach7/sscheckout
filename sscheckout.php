<?php
/*
Plugin Name: SS Checkout
Description: A simple shopping cart plugin with Stripe checkout integration.
Version: 1.0.0
Author: Tyson Brooks
Author URI: https://frostlineworks.com
Tested up to: 6.2
*/

// Prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Ensure the FLW Plugin Library is loaded before running the plugin.
add_action('plugins_loaded', function () {

    // Check if the FLW Plugin Update Checker is active.
    if ( class_exists('FLW_Plugin_Update_Checker') ) {
        $pluginSlug = basename(dirname(__FILE__)); // Dynamically get the plugin slug.
        // Initialize the update checker.
        FLW_Plugin_Update_Checker::initialize(__FILE__, $pluginSlug);

        // Replace the update icon.
        add_filter('site_transient_update_plugins', function ($transient) {
            if ( isset($transient->response) ) {
                foreach ( $transient->response as $plugin_slug => $plugin_data ) {
                    if ( $plugin_slug === plugin_basename(__FILE__) ) {
                        $icon_url = plugins_url('assets/logo-128x128.png', __FILE__);
                        $transient->response[$plugin_slug]->icons = [
                            'default' => $icon_url,
                            '1x'      => $icon_url,
                            '2x'      => plugins_url('assets/logo-256x256.png', __FILE__),
                        ];
                    }
                }
            }
            return $transient;
        });
    } else {
        // Admin notice for missing FLW Plugin Library.
        add_action('admin_notices', function () {
            // Ensure get_plugins() is available.
            if ( ! function_exists( 'get_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $pluginSlug = 'flwpluginlibrary/flwpluginlibrary.php';
            $plugins = get_plugins();
            if ( ! isset( $plugins[$pluginSlug] ) ) {
                echo '<div class="notice notice-error"><p>The FLW Plugin Library is not installed. Please install and activate it to enable update functionality.</p></div>';
            } elseif ( ! is_plugin_active( $pluginSlug ) ) {
                $activateUrl = wp_nonce_url(
                    admin_url('plugins.php?action=activate&plugin=' . $pluginSlug),
                    'activate-plugin_' . $pluginSlug
                );
                echo '<div class="notice notice-error"><p>The FLW Plugin Library is installed but not active. Please <a href="' . esc_url($activateUrl) . '">activate</a> it to enable update functionality.</p></div>';
            }
        });
    }

    // Check if the FLW Plugin Library is available.
    if ( class_exists('FLW_Plugin_Library') ) {

        class SS_Checkout_Plugin {

            /**
             * Constructor to initialize the plugin.
             */
            public function __construct() {
                // Front-end hooks.
                add_action('init', [$this, 'maybe_create_tables']);
                add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
                add_shortcode('add_to_cart', [$this, 'add_to_cart_shortcode']);
                add_shortcode('checkout', [$this, 'checkout_shortcode']);
                add_action('wp_ajax_ss_process_checkout', [$this, 'process_checkout']);
                add_action('wp_ajax_nopriv_ss_process_checkout', [$this, 'process_checkout']);

                // Register admin settings submenu.
                add_action('admin_menu', [$this, 'register_submenu']);
            }

            /**
             * Create custom database tables if they do not exist.
             */
            public function maybe_create_tables() {
                global $wpdb;
                $table1 = $wpdb->prefix . 'ss_shopping_cart';
                $table2 = $wpdb->prefix . 'ss_order_history';
                require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
                $charset_collate = $wpdb->get_charset_collate();

                $sql1 = "CREATE TABLE $table1 (
                    id mediumint(9) NOT NULL AUTO_INCREMENT,
                    uid varchar(100) NOT NULL,
                    product_name varchar(255) NOT NULL,
                    product_price decimal(10,2) NOT NULL,
                    quantity int NOT NULL DEFAULT 1,
                    added_at datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id)
                ) $charset_collate;";

                $sql2 = "CREATE TABLE $table2 (
                    id mediumint(9) NOT NULL AUTO_INCREMENT,
                    uid varchar(100) NOT NULL,
                    order_id varchar(100) NOT NULL,
                    product_name varchar(255) NOT NULL,
                    product_price decimal(10,2) NOT NULL,
                    quantity int NOT NULL DEFAULT 1,
                    purchased_at datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id)
                ) $charset_collate;";

                dbDelta($sql1);
                dbDelta($sql2);
            }

            /**
             * Enqueue CSS, Stripe.js, and custom JS.
             */
            public function enqueue_scripts() {
                wp_enqueue_style('sscheckout-css', plugin_dir_url(__FILE__) . 'assets/css/sscheckout.css');
                wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', [], null, true);
                wp_enqueue_script('sscheckout-js', plugin_dir_url(__FILE__) . 'assets/js/sscheckout.js', ['jquery', 'stripe-js'], '1.0.0', true);

                // Pass data to our JS file.
                $publishable_key = get_option('ss_stripe_publishable_key', 'pk_test_TYooMQauvdEDq54NiTphI7jx');
                wp_localize_script('sscheckout-js', 'sscheckout_params', [
                    'ajax_url'       => admin_url('admin-ajax.php'),
                    'publishableKey' => $publishable_key,
                ]);
            }

            /**
             * Get a unique ID for the current user (logged in or guest).
             */
            public function get_user_uid() {
                if (is_user_logged_in()) {
                    return 'user_' . get_current_user_id();
                } else {
                    if (isset($_COOKIE['ss_uid'])) {
                        return sanitize_text_field($_COOKIE['ss_uid']);
                    } else {
                        $uid = 'guest_' . wp_generate_uuid4();
                        setcookie('ss_uid', $uid, time() + (30 * 24 * 3600), COOKIEPATH, COOKIE_DOMAIN);
                        $_COOKIE['ss_uid'] = $uid;
                        return $uid;
                    }
                }
            }

            /**
             * [add_to_cart] shortcode: display an add-to-cart button or quantity controls.
             */
            public function add_to_cart_shortcode($atts) {
                $atts = shortcode_atts([
                    'price' => '0',
                    'name'  => '',
                ], $atts, 'add_to_cart');

                $uid = $this->get_user_uid();
                global $wpdb;
                $table = $wpdb->prefix . 'ss_shopping_cart';
                $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE uid = %s AND product_name = %s", $uid, $atts['name']));

                ob_start();
                if ($existing) {
                    ?>
                    <div class="ss-product" data-product="<?php echo esc_attr($atts['name']); ?>">
                        <button class="ss-minus" data-action="minus">–</button>
                        <span class="ss-quantity"><?php echo intval($existing->quantity); ?></span>
                        <button class="ss-plus" data-action="plus">+</button>
                        <button class="ss-remove" data-action="remove">🗑️</button>
                    </div>
                    <?php
                } else {
                    ?>
                    <div class="ss-product" data-product="<?php echo esc_attr($atts['name']); ?>" data-price="<?php echo esc_attr($atts['price']); ?>">
                        <button class="ss-add-to-cart">Add to Cart</button>
                    </div>
                    <?php
                }
                return ob_get_clean();
            }

            /**
             * [checkout] shortcode: display cart items and the checkout form.
             */
            public function checkout_shortcode() {
                $uid = $this->get_user_uid();
                global $wpdb;
                $table = $wpdb->prefix . 'ss_shopping_cart';
                $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE uid = %s", $uid));

                ob_start();
                ?>
                <div class="ss-checkout">
                    <h2>Your Cart</h2>
                    <?php if ($items) : ?>
                        <table class="ss-cart-table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Price</th>
                                    <th>Quantity</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item) : ?>
                                    <tr data-product="<?php echo esc_attr($item->product_name); ?>">
                                        <td><?php echo esc_html($item->product_name); ?></td>
                                        <td><?php echo esc_html($item->product_price); ?></td>
                                        <td class="ss-item-quantity"><?php echo intval($item->quantity); ?></td>
                                        <td>
                                            <button class="ss-minus" data-action="minus">–</button>
                                            <button class="ss-plus" data-action="plus">+</button>
                                            <button class="ss-remove" data-action="remove">🗑️</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else : ?>
                        <p>Your cart is empty.</p>
                    <?php endif; ?>

                    <h2>Checkout</h2>
                    <form id="ss-checkout-form">
                        <label>Name: <input type="text" name="name" required></label><br>
                        <?php if ( ! is_user_logged_in() ) : ?>
                            <label>Email: <input type="email" name="email" required></label><br>
                            <label>Password: <input type="password" name="password" required></label><br>
                        <?php endif; ?>
                        <label>Phone: <input type="text" name="phone"></label><br>
                        <!-- Stripe Card Element container -->
                        <div id="card-element"><!-- Stripe Element will be mounted here --></div>
                        <div id="card-errors" role="alert"></div>
                        <input type="hidden" name="action" value="ss_process_checkout">
                        <button type="submit">Submit Payment</button>
                    </form>
                    <div id="ss-checkout-response"></div>
                </div>
                <?php
                return ob_get_clean();
            }

            /**
             * AJAX handler to process the checkout.
             */
            public function process_checkout() {
                $name = sanitize_text_field($_POST['name']);
                $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
                $password = isset($_POST['password']) ? $_POST['password'] : '';
                $phone = sanitize_text_field($_POST['phone']);
                $paymentMethod = sanitize_text_field($_POST['paymentMethod']);
                $uid = $this->get_user_uid();

                global $wpdb;
                $cart_table = $wpdb->prefix . 'ss_shopping_cart';
                $order_table = $wpdb->prefix . 'ss_order_history';
                $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM $cart_table WHERE uid = %s", $uid));
                if ( ! $items ) {
                    wp_send_json_error("Cart is empty");
                }

                // Calculate total in dollars.
                $total = 0;
                foreach ($items as $item) {
                    $total += floatval($item->product_price) * intval($item->quantity);
                }
                $amount = intval($total * 100); // convert to cents

                // Retrieve Stripe secret key.
                $stripe_secret = get_option('ss_stripe_secret_key');
                if ( ! $stripe_secret ) {
                    wp_send_json_error("Stripe secret key not set");
                }

                // Create the charge using the token (PaymentMethod ID).
                $ch = curl_init("https://api.stripe.com/v1/charges");
                curl_setopt($ch, CURLOPT_USERPWD, $stripe_secret . ":");
                curl_setopt($ch, CURLOPT_POST, true);
                $data = [
                    "amount"      => $amount,
                    "currency"    => "usd",
                    "source"      => $paymentMethod,
                    "description" => "Charge for " . $name,
                ];
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $response = curl_exec($ch);
                $err = curl_error($ch);
                curl_close($ch);
                if ($err) {
                    wp_send_json_error("cURL Error: " . $err);
                }
                $result = json_decode($response, true);
                if ( isset($result['error']) ) {
                    wp_send_json_error("Stripe error: " . $result['error']['message']);
                }

                // If guest and not logged in, create user.
                if ( ! is_user_logged_in() ) {
                    if ( ! email_exists($email) ) {
                        $user_id = wp_create_user($email, $password, $email);
                        if ( is_wp_error($user_id) ) {
                            wp_send_json_error("User creation failed");
                        }
                    }
                }

                // Send order email to admin.
                $admin_email = get_option('ss_order_admin_email');
                if ( ! $admin_email ) {
                    $admin_email = get_option('admin_email');
                }
                $order_id = "ORDER-" . time();
                $subject = "New Order: " . $order_id;
                $message = "Order Details:\n";
                foreach ($items as $item) {
                    $message .= $item->product_name . " x " . $item->quantity . " - $" . $item->product_price . "\n";
                }
                wp_mail($admin_email, $subject, $message);

                // Move items to order history and clear cart.
                foreach ($items as $item) {
                    $wpdb->insert($order_table, [
                        "uid"           => $uid,
                        "order_id"      => $order_id,
                        "product_name"  => $item->product_name,
                        "product_price" => $item->product_price,
                        "quantity"      => $item->quantity,
                        "purchased_at"  => current_time('mysql')
                    ]);
                }
                $wpdb->delete($cart_table, ["uid" => $uid]);

                wp_send_json_success("Payment successful, order processed.");
            }

            /**
             * Register the admin submenu under the FLW Plugins menu.
             */
            public function register_submenu() {
                FLW_Plugin_Library::add_submenu(
                    'SS Checkout Settings', // {PLUGIN_SETTINGS_TITLE}
                    'ss-checkout',           // {PLUGIN_SLUG}
                    [$this, 'render_settings_page']
                );
            }

            /**
             * Render the plugin settings page.
             */
            public function render_settings_page() {
                echo '<div class="wrap">';
                echo '<h1>SS Checkout Settings</h1>';
                echo '<form method="post" action="options.php">';
                // In a real plugin, settings fields would be registered and output here.
                echo '<p>Here you can manage settings for SS Checkout.</p>';
                settings_fields('ss_checkout_options');
                do_settings_sections('ss_checkout_options');
                submit_button('Save Settings');
                echo '</form>';
                echo '</div>';
            }
        }

        // Initialize our plugin.
        new SS_Checkout_Plugin();
    } else {
        // Show an admin notice if FLW Plugin Library is not active.
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>The FLW Plugin Library must be activated for SS Checkout to work.</p></div>';
        });
    }
});
