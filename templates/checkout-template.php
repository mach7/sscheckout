<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$user_id    = SSC_Helpers::get_user_id();
$cart_total = SSC_Cart::get_cart_total( $user_id );
?>

<div class="ssc-checkout-container">
    <h2>Checkout</h2>
    <div id="ssc-cart-items">
        <?php echo SSC_Cart::get_cart_items_html( $user_id ); ?>
    </div>
    <p class="ssc-checkout-total">Total: <?php echo SSC_Helpers::format_price( $cart_total ); ?></p>
    <form id="ssc-checkout-form" method="post">
        <div id="ssc-payment-element"></div>
        <button type="submit" class="ssc-checkout-btn">Pay Now</button>
    </form>
    <p id="ssc-checkout-message"></p>
</div>
