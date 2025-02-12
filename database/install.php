<?php
if (!defined('ABSPATH')) {
    exit;
}

class SSC_Install {
    public static function run() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $cart_table = $wpdb->prefix . 'ssc_cart';
        $orders_table = $wpdb->prefix . 'ssc_orders';

        $sql = "CREATE TABLE $cart_table (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            userID VARCHAR(255) NOT NULL,
            name VARCHAR(255) NOT NULL,
            price INT NOT NULL,
            quantity INT NOT NULL DEFAULT 1
        ) $charset_collate;";

        $sql .= "CREATE TABLE $orders_table (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            userID VARCHAR(255) NOT NULL,
            cart_total INT NOT NULL,
            date DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}