<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SSC_Checkout {

    /**
     * Renders the checkout page (cart + payment form).
     */
    public function checkout_page() {
        ob_start();
        include SSCHECKOUT_PLUGIN_DIR . 'templates/checkout-template.php';
        return ob_get_clean();
    }
    
    /**
     * Processes the payment using Stripe.
     * Expects an AJAX call and returns a PaymentIntent client secret.
     */
    public function process_payment() {
        if ( ! is_ssl() ) {
            wp_send_json_error( 'SSL is required for Stripe payments.' );
        }
        
        // Retrieve stored Stripe API keys (set in admin settings)
        $stripe_public_key = get_option( 'flw_stripe_public_key' );
        $stripe_secret_key = get_option( 'flw_stripe_secret_key' );
        
        if ( ! $stripe_public_key || ! $stripe_secret_key ) {
            wp_send_json_error( 'Stripe API keys are not set.' );
        }
        
        // Ensure the Stripe PHP library is loaded.
        if ( ! class_exists( 'Stripe\Stripe' ) ) {
            // Make sure you have installed Stripe via Composer and this path is correct.
            require_once SSCHECKOUT_PLUGIN_DIR . 'vendor/autoload.php';
        }
        
        \Stripe\Stripe::setApiKey( $stripe_secret_key );
        
        // Calculate cart total (assumes USD)
        $cart   = new SSC_Cart();
        $items  = $cart->get_cart_items();
        $amount = 0;
        foreach ( $items as $item ) {
            $amount += $item->price * $item->quantity;
        }
        
        try {
            $paymentIntent = \Stripe\PaymentIntent::create( [
                'amount'   => $amount * 100, // Amount in cents
                'currency' => 'usd',
            ] );
            
            wp_send_json_success( [
                'client_secret' => $paymentIntent->client_secret,
            ] );
        } catch ( Exception $e ) {
            wp_send_json_error( $e->getMessage() );
        }
    }
    
    /**
     * Stores an order in the database after successful payment.
     */
    public function store_order( $user_id, $cart_total ) {
        global $wpdb;
        $table_orders = $wpdb->prefix . 'sscheckout_orders';
        $wpdb->insert( $table_orders, array(
            'user_id'       => $user_id,
            'cart_total'    => $cart_total,
            'purchase_date' => current_time( 'mysql' )
        ) );
    }
}

// AJAX handler for processing payment
add_action( 'wp_ajax_ssc_process_payment', function() {
    $checkout = new SSC_Checkout();
    $checkout->process_payment();
});
add_action( 'wp_ajax_nopriv_ssc_process_payment', function() {
    $checkout = new SSC_Checkout();
    $checkout->process_payment();
});
