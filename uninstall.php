<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Drop the orders table
$table_orders = $wpdb->prefix . 'sscheckout_orders';
$wpdb->query( "DROP TABLE IF EXISTS $table_orders" );

// Drop the cart table
$table_cart = $wpdb->prefix . 'sscheckout_cart';
$wpdb->query( "DROP TABLE IF EXISTS $table_cart" );
