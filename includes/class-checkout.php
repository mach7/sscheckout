<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SSC_Checkout {
    public static function checkout_page() {
        ob_start();
        error_log("SSC Checkout page loaded.");
        
        $user_id    = SSC_Helpers::get_user_id();
        $cart_total = SSC_Cart::get_cart_total( $user_id );
    
        echo '<div class="ssc-checkout-container">';
        echo '<h2>Checkout</h2>';
        echo '<div id="ssc-cart-items">' . SSC_Cart::get_cart_items_html( $user_id ) . '</div>';
        echo '<p class="ssc-checkout-total">Total: $<span id="ssc-cart-total">' . number_format( $cart_total / 100, 2 ) . '</span></p>';
    
        echo '<form id="ssc-checkout-form" method="post">';
        echo '<div id="ssc-payment-element"></div>';
        echo '<button type="submit" class="ssc-checkout-btn">Pay Now</button>';
        echo '</form>';
        echo '<p id="ssc-checkout-message"></p>';
        echo '</div>';
    
        return ob_get_clean();
    }

    public static function process_payment() {
        if ( ! isset( $_POST['action'] ) || $_POST['action'] !== 'ssc_process_payment' ) {
            wp_send_json_error( [ 'message' => 'Invalid request.' ] );
        }
    
        $user_id    = SSC_Helpers::get_user_id();
        $cart_total = SSC_Cart::get_cart_total( $user_id );
    
        if ( $cart_total <= 0 ) {
            wp_send_json_error( [ 'message' => 'Cart is empty.' ] );
        }
    
        \Stripe\Stripe::setApiKey( get_option( 'flw_stripe_secret_key' ) );
    
        try {
            // If a payment method ID was sent, use it to confirm the PaymentIntent
            $payment_method = isset( $_POST['payment_method'] ) ? sanitize_text_field( $_POST['payment_method'] ) : null;
            
            $intentParams = [
                'amount'                => $cart_total,
                'currency'              => 'usd',
                'payment_method_types'  => ['card'],
            ];
    
            if ( $payment_method ) {
                $intentParams['payment_method'] = $payment_method;
                $intentParams['confirm']        = true;
            }
    
            $payment_intent = \Stripe\PaymentIntent::create( $intentParams );
    
            // On successful payment, store the order and clear the cart.
            SSC_Checkout::store_order( $user_id, $cart_total );
            SSC_Cart::clear_cart( $user_id );
    
            wp_send_json_success( [ 'message' => 'Payment successful!' ] );
        } catch ( Exception $e ) {
            wp_send_json_error( [ 'message' => 'Payment failed: ' . $e->getMessage() ] );
        }
    }
    
    private static function store_order( $user_id, $cart_total ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ssc_orders';
    
        $wpdb->insert( $table_name, [
            'userID'     => $user_id,
            'cart_total' => $cart_total,
            'date'       => current_time( 'mysql' )
        ] );
    }
}

add_action( 'wp_ajax_ssc_process_payment', ['SSC_Checkout', 'process_payment'] );
add_action( 'wp_ajax_nopriv_ssc_process_payment', ['SSC_Checkout', 'process_payment'] );
