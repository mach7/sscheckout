<?php
/*
Plugin Name: Simple Stripe Checkout
Plugin URI: http://example.com/
Description: A lightweight Stripe checkout plugin for adding simple cart functionality with embedded payments.
Version: 1.0.1
Author: Tyson Brooks
Tested up to: WordPress 6.4
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define constants for paths and URLs
define( 'SSCHECKOUT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SSCHECKOUT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Include required files
require_once SSCHECKOUT_PLUGIN_DIR . 'includes/helpers.php';
require_once SSCHECKOUT_PLUGIN_DIR . 'includes/class-cart.php';
require_once SSCHECKOUT_PLUGIN_DIR . 'includes/class-checkout.php';
require_once SSCHECKOUT_PLUGIN_DIR . 'includes/class-orders.php';

// Admin files
if ( is_admin() ) {
    require_once SSCHECKOUT_PLUGIN_DIR . 'admin/class-admin.php';
    require_once SSCHECKOUT_PLUGIN_DIR . 'admin/orders-list.php';
}

// Activation: Create database tables
register_activation_hook( __FILE__, 'sscheckout_install' );
function sscheckout_install() {
    require_once SSCHECKOUT_PLUGIN_DIR . 'database/install.php';
    sscheckout_install_db();
}

// Uninstall: Cleanup database
register_uninstall_hook( __FILE__, 'sscheckout_uninstall' );
function sscheckout_uninstall() {
    require_once SSCHECKOUT_PLUGIN_DIR . 'uninstall.php';
    sscheckout_cleanup();
}

// Enqueue frontend scripts and styles
add_action( 'wp_enqueue_scripts', 'sscheckout_enqueue_scripts' );
function sscheckout_enqueue_scripts() {
    wp_enqueue_style( 'sscheckout-style', SSCHECKOUT_PLUGIN_URL . 'assets/css/style.css' );
    wp_enqueue_script( 'sscheckout-cart', SSCHECKOUT_PLUGIN_URL . 'assets/js/cart.js', array( 'jquery' ), '1.0.0', true );
    wp_localize_script( 'sscheckout-cart', 'ssc_ajax', array(
        'ajax_url' => admin_url( 'admin-ajax.php' )
    ) );

    wp_enqueue_script( 'sscheckout-checkout', SSCHECKOUT_PLUGIN_URL . 'assets/js/checkout.js', array( 'jquery' ), '1.0.0', true );
    // Pass the Stripe public key (set via admin settings) to JS
    wp_localize_script( 'sscheckout-checkout', 'ssc_stripe', array(
        'public_key' => get_option( 'flw_stripe_public_key' )
    ) );
}

// Enqueue admin scripts and styles
add_action( 'admin_enqueue_scripts', 'sscheckout_enqueue_admin_scripts' );
function sscheckout_enqueue_admin_scripts() {
    wp_enqueue_style( 'sscheckout-admin-style', SSCHECKOUT_PLUGIN_URL . 'assets/css/admin.css' );
    wp_enqueue_script( 'sscheckout-admin', SSCHECKOUT_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), '1.0.0', true );
}

// Register shortcodes
function sscheckout_add_to_cart_shortcode( $atts ) {
    $atts = shortcode_atts( array(
        'price' => '0',
        'name'  => 'Product'
    ), $atts, 'add_to_cart' );
    
    $cart = new SSC_Cart();
    return $cart->add_to_cart_button( $atts['price'], $atts['name'] );
}
add_shortcode( 'add_to_cart', 'sscheckout_add_to_cart_shortcode' );

function sscheckout_checkout_shortcode( $atts ) {
    $checkout = new SSC_Checkout();
    return $checkout->checkout_page();
}
add_shortcode( 'Checkout', 'sscheckout_checkout_shortcode' );
