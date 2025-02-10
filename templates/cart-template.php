<?php
if (!defined('ABSPATH')) {
    exit;
}

$user_id = SSC_Helpers::get_user_id();
$cart_items = SSC_Cart::get_cart_items($user_id);
$cart_total = SSC_Cart::get_cart_total($user_id);

error_log("Cart template loaded.");
?>
<p>Debug: Cart template is loading.</p>


<div class="ssc-cart-container">
    <h2>Your Cart</h2>
    <div id="ssc-cart-items">
        <?php if (!empty($cart_items)) : ?>
            <ul>
                <?php foreach ($cart_items as $item) : ?>
                    <li class="ssc-cart-item">
                        <span><?php echo esc_html($item->name); ?></span>
                        <div class="ssc-cart-actions">
                            <button class="ssc-cart-btn" data-action="decrease" data-name="<?php echo esc_attr($item->name); ?>" data-price="<?php echo esc_attr($item->price); ?>">-</button>
                            <span class="ssc-cart-quantity"> <?php echo esc_html($item->quantity); ?> </span>
                            <button class="ssc-cart-btn" data-action="increase" data-name="<?php echo esc_attr($item->name); ?>" data-price="<?php echo esc_attr($item->price); ?>">+</button>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else : ?>
            <ul id="ssc-cart-placeholder">
                <li class="ssc-cart-item">Your cart is empty. (Debug: Cart template loaded)</li>
            </ul>
        <?php endif; ?>
    </div>
    <p class="ssc-checkout-total">Total: <span id="ssc-cart-total"><?php echo SSC_Helpers::format_price($cart_total); ?></span></p>
</div>

