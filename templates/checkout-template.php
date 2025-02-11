<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="sscheckout-checkout-container">
    <div class="sscheckout-cart">
        <?php
        // Display the current cart
        $cart = new SSC_Cart();
        echo $cart->get_cart_items_html();
        ?>
    </div>
    <div class="sscheckout-payment">
        <form id="ssc-payment-form">
            <div id="card-element"><!-- Stripe Element will be inserted here --></div>
            <button id="ssc-submit-payment" type="button">Pay Now</button>
        </form>
        <div id="ssc-payment-message"></div>
    </div>
</div>
