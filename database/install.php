<?php
function sscheckout_install_db() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // Table for cart items
    $table_cart = $wpdb->prefix . 'sscheckout_cart';
    $sql_cart = "CREATE TABLE $table_cart (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_id varchar(100) NOT NULL,
        product_name varchar(255) NOT NULL,
        price decimal(10,2) NOT NULL,
        quantity int NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    // Table for orders
    $table_orders = $wpdb->prefix . 'sscheckout_orders';
    $sql_orders = "CREATE TABLE $table_orders (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_id varchar(100) NOT NULL,
        cart_total decimal(10,2) NOT NULL,
        purchase_date datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql_cart );
    dbDelta( $sql_orders );
}
