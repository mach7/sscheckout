<?php
if (!defined('ABSPATH')) {
    exit;
}

class SSC_Admin {
    public function __construct() {
        add_action('admin_menu', [$this, 'register_admin_page']);
    }

    public function register_admin_page() {
        FLW_Plugin_Library::add_submenu(
            'Simple Stripe Checkout', // Title
            'sscheckout', // Slug
            [$this, 'render_admin_page'] // Callback function
        );
    }

    public function render_admin_page() {
        echo '<div class="wrap">';
        echo '<h1>Simple Stripe Checkout - Orders</h1>';
        SSC_Orders::render_orders_page();
        echo '</div>';
    }
}

new SSC_Admin();
