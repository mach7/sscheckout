<?php
if (!defined('ABSPATH')) {
    exit;
}

class SSC_Upgrade {
    public static function run() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $orders_table = $wpdb->prefix . 'ssc_orders';

        $columns = $wpdb->get_col("DESC $orders_table", 0);

        if (!in_array('customer_name', $columns)) {
            $wpdb->query("ALTER TABLE $orders_table ADD COLUMN customer_name VARCHAR(255) AFTER userID;");
        }
    }
}