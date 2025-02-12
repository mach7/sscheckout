<?php
/*
Plugin Name: Simple Stripe Checkout
Description: A lightweight Stripe checkout plugin for adding simple cart functionality with embedded payments.
Version: 1.0.1
Author: Tyson Brooks
Author URI: https://frostlineworks.com
Tested up to: 6.4
*/

// Prevent direct access to this file
if (!defined('ABSPATH')) {
    exit;
}

// Ensure the FLW Plugin Library is loaded before running the plugin
add_action('plugins_loaded', function () {

    // Check if the FLW Plugin Library is active
    if (class_exists('FLW_Plugin_Update_Checker')) {
        $pluginSlug = basename(dirname(__FILE__)); // Dynamically get the plugin slug

        // Initialize the update checker
        FLW_Plugin_Update_Checker::initialize(__FILE__, $pluginSlug);

        // Replace the update icon
        add_filter('site_transient_update_plugins', function ($transient) {
            if (isset($transient->response)) {
                foreach ($transient->response as $plugin_slug => $plugin_data) {
                    if ($plugin_slug === plugin_basename(__FILE__)) {
                        $icon_url = plugins_url('assets/logo-128x128.png', __FILE__);
                        $transient->response[$plugin_slug]->icons = [
                            'default' => $icon_url,
                            '1x' => $icon_url,
                            '2x' => plugins_url('assets/logo-256x256.png', __FILE__),
                        ];
                    }
                }
            }
            return $transient;
        });
    } else {
        add_action('admin_notices', function () {
            $pluginSlug = 'flwpluginlibrary/flwpluginlibrary.php';
            $plugins = get_plugins();

            if (!isset($plugins[$pluginSlug])) {
                echo '<div class="notice notice-error"><p>The FLW Plugin Library is not installed. Please install and activate it to enable update functionality.</p></div>';
            } elseif (!is_plugin_active($pluginSlug)) {
                $activateUrl = wp_nonce_url(
                    admin_url('plugins.php?action=activate&plugin=' . $pluginSlug),
                    'activate-plugin_' . $pluginSlug
                );
                echo '<div class="notice notice-error"><p>The FLW Plugin Library is installed but not active. Please <a href="' . esc_url($activateUrl) . '">activate</a> it to enable update functionality.</p></div>';
            }
        });
    }

    // Check if the FLW Plugin Library is available
    if (class_exists('FLW_Plugin_Library')) {
        class SSC_Plugin {
            public function __construct() {
                add_action('admin_menu', [$this, 'register_submenu']);
                $this->include_files();
            }

            public function register_submenu() {
                FLW_Plugin_Library::add_submenu(
                    'Simple Stripe Checkout', // Title
                    'sscheckout', // Slug
                    [$this, 'render_settings_page'] // Callback function
                );
            }

            public function render_settings_page() {
                echo '<div class="wrap">';
                echo '<h1>Simple Stripe Checkout</h1>';
                echo '<form method="post" action="options.php">';
                echo '<p>Here you can manage settings for Simple Stripe Checkout.</p>';
                echo '</form>';
                echo '</div>';
            }

            private function include_files() {
                require_once plugin_dir_path(__FILE__) . 'includes/class-cart.php';
                require_once plugin_dir_path(__FILE__) . 'includes/class-checkout.php';
                require_once plugin_dir_path(__FILE__) . 'includes/class-orders.php';
                require_once plugin_dir_path(__FILE__) . 'includes/helpers.php';
                require_once plugin_dir_path(__FILE__) . 'admin/class-admin.php';
            }
        }

        new SSC_Plugin();
    } else {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>The FLW Plugin Library must be activated for Simple Stripe Checkout to work.</p></div>';
        });
    }
});

// Plugin activation hook
function ssc_activate() {
    require_once plugin_dir_path(__FILE__) . 'database/install.php';
    SSC_Install::run();
}
register_activation_hook(__FILE__, 'ssc_activate');

// Plugin deactivation hook
function ssc_deactivate() {
    // Cleanup tasks if needed
}
register_deactivation_hook(__FILE__, 'ssc_deactivate');


// Register shortcodes
function ssc_register_shortcodes() {
    add_shortcode('add_to_cart', ['SSC_Cart', 'add_to_cart_button']);
    add_shortcode('Checkout', ['SSC_Checkout', 'checkout_page']);
}
add_action('init', 'ssc_register_shortcodes');
function ssc_enqueue_scripts() {
    // Enqueue Stripe JS from the official CDN.
    wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v3/', array(), null, true );

    // Enqueue the cart and checkout scripts.
    wp_enqueue_script( 'ssc-cart-js', plugin_dir_url( __FILE__ ) . 'assets/js/cart.js', array( 'jquery' ), null, true );
    wp_enqueue_script( 'ssc-checkout-js', plugin_dir_url( __FILE__ ) . 'assets/js/checkout.js', array( 'jquery', 'stripe-js' ), null, true );
    
    // Localize the scripts with common data.
    wp_localize_script( 'ssc-cart-js', 'ssc_ajax', [
        'ajax_url'   => admin_url( 'admin-ajax.php' ),
        'debug'      => is_debug_mode_enabled(),
        'stripe_key' => get_option( 'flw_stripe_public_key' ),
    ] );
}
add_action( 'wp_enqueue_scripts', 'ssc_enqueue_scripts' );
