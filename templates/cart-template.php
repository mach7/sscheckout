<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! empty( $items ) ) :
    echo '<ul class="sscheckout-cart-items">';
    foreach ( $items as $item ) {
        echo '<li>';
        echo esc_html( $item->product_name ) . ' - Qty: ' . esc_html( $item->quantity ) . ' - Price: $' . esc_html( $item->price );
        echo '</li>';
    }
    echo '</ul>';
else :
    echo '<p>Your cart is empty.</p>';
endif;
