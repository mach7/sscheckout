<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SSC_Orders {

    /**
     * Retrieves all orders, ordered by most recent.
     */
    public function get_orders() {
        global $wpdb;
        $table_orders = $wpdb->prefix . 'sscheckout_orders';
        return $wpdb->get_results( "SELECT * FROM $table_orders ORDER BY purchase_date DESC" );
    }
}
