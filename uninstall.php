<?php
// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Global database object
global $wpdb;

// Define table names
$cart_table = $wpdb->prefix . 'ssc_cart';
$orders_table = $wpdb->prefix . 'ssc_orders';

// Remove plugin tables
$wpdb->query("DROP TABLE IF EXISTS $cart_table");
$wpdb->query("DROP TABLE IF EXISTS $orders_table");

// Remove plugin settings
delete_option('ssc_plugin_settings');