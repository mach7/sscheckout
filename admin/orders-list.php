<?php
if (!defined('ABSPATH')) {
    exit;
}

$orders = SSC_Orders::get_orders();
?>

<div class="wrap">
    <h1>Order History</h1>
    
    <?php if (empty($orders)) : ?>
        <p>No orders found.</p>
    <?php else : ?>
        <table class="ssc-admin-table">
            <thead>
                <tr>
                    <th>Customer Name</th>
                    <th>Date</th>
                    <th>Cart Total</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order) : ?>
                    <tr id="ssc-order-<?php echo esc_attr($order->id); ?>">
                        <td><?php echo esc_html($order->userID); ?></td>
                        <td><?php echo esc_html($order->date); ?></td>
                        <td>$<?php echo number_format($order->cart_total / 100, 2); ?></td>
                        <td>
                            <button class="ssc-admin-delete-order" data-order-id="<?php echo esc_attr($order->id); ?>">Delete</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>