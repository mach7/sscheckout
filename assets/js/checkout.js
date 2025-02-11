jQuery(document).ready(function($) {
    // Initialize Stripe with the public key passed from PHP
    var stripe = Stripe(ssc_stripe.public_key);
    var elements = stripe.elements();
    var card = elements.create('card');
    card.mount('#card-element');

    // Handle payment submission
    $('#ssc-submit-payment').on('click', function(e) {
        e.preventDefault();
        $('#ssc-submit-payment').prop('disabled', true);
        
        // Request a PaymentIntent from the server
        $.post(ssc_ajax.ajax_url, { action: 'ssc_process_payment' }, function(response) {
            if (response.success) {
                var clientSecret = response.data.client_secret;
                stripe.confirmCardPayment(clientSecret, {
                    payment_method: { card: card }
                }).then(function(result) {
                    if (result.error) {
                        $('#ssc-payment-message').text(result.error.message);
                        $('#ssc-submit-payment').prop('disabled', false);
                    } else if (result.paymentIntent && result.paymentIntent.status === 'succeeded') {
                        $('#ssc-payment-message').text('Payment succeeded!');
                        // Optionally, clear the cart and store the order here.
                    }
                });
            } else {
                $('#ssc-payment-message').text(response.data);
                $('#ssc-submit-payment').prop('disabled', false);
            }
        });
    });
});
