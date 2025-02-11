<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$orders_obj = new SSC_Orders();
$orders = $orders_obj->get_orders();
?>
<h2>Order History</h2>
<table class="wp-list-table widefat fixed striped">
    <thead>
        <tr>
            <th>Customer ID</th>
            <th>Cart Total</th>
            <th>Date of Purchase</th>
        </tr>
    </thead>
    <tbody>
        <?php if ( $orders ) : ?>
            <?php foreach ( $orders as $order ) : ?>
                <tr>
                    <td><?php echo esc_html( $order->user_id ); ?></td>
                    <td><?php echo esc_html( $order->cart_total ); ?></td>
                    <td><?php echo esc_html( $order->purchase_date ); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php else : ?>
            <tr>
                <td colspan="3">No orders found.</td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>
