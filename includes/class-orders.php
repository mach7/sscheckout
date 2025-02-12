<?php
if (!defined('ABSPATH')) {
    exit;
}

class SSC_Orders {
    public static function get_orders() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ssc_orders';
        
        return $wpdb->get_results("SELECT * FROM $table_name ORDER BY date DESC");
    }

    public static function render_orders_page() {
        echo '<div class="wrap">';
        echo '<h1>Order History</h1>';
        
        $orders = self::get_orders();
        
        if (empty($orders)) {
            echo '<p>No orders found.</p>';
        } else {
            echo '<table class="ssc-admin-table">';
            echo '<tr><th>Customer Name</th><th>Date</th><th>Cart Total</th><th>Actions</th></tr>';
            
            foreach ($orders as $order) {
                echo '<tr id="ssc-order-' . esc_attr($order->id) . '">
                        <td>' . esc_html($order->userID) . '</td>
                        <td>' . esc_html($order->date) . '</td>
                        <td>$' . number_format($order->cart_total / 100, 2) . '</td>
                        <td><button class="ssc-admin-delete-order" data-order-id="' . esc_attr($order->id) . '">Delete</button></td>
                      </tr>';
            }
            echo '</table>';
        }
        echo '</div>';
    }

    public static function delete_order() {
        if (!isset($_POST['order_id'])) {
            wp_send_json_error(['message' => 'Invalid order ID.']);
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'ssc_orders';
        $order_id = intval($_POST['order_id']);

        $deleted = $wpdb->delete($table_name, ['id' => $order_id]);
        
        if ($deleted) {
            wp_send_json_success(['message' => 'Order deleted successfully.']);
        } else {
            wp_send_json_error(['message' => 'Failed to delete order.']);
        }
    }
}

add_action('wp_ajax_ssc_delete_order', ['SSC_Orders', 'delete_order']);
