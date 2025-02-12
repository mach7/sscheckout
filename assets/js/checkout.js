document.addEventListener("DOMContentLoaded", function () {
    let checkoutForm = document.getElementById("ssc-checkout-form");
    if (checkoutForm) {
        // Initialize Stripe Elements using the localized stripe_key.
        var stripe = Stripe(ssc_ajax.stripe_key);
        var elements = stripe.elements();
        var cardElement = elements.create('card');
        cardElement.mount('#ssc-payment-element');

        checkoutForm.addEventListener("submit", function (event) {
            event.preventDefault();
            processPayment(cardElement, stripe);
        });
    }
});

function processPayment(cardElement, stripe) {
    // Create a payment method using Stripe Elements.
    stripe.createPaymentMethod({
        type: 'card',
        card: cardElement,
    }).then(function(result) {
        if (result.error) {
            document.getElementById("ssc-checkout-message").innerText = result.error.message;
        } else {
            let data = new FormData();
            data.append("action", "ssc_process_payment");
            data.append("payment_method", result.paymentMethod.id);

            fetch(ssc_ajax.ajax_url, {
                method: "POST",
                body: data,
            })
            .then(response => response.json())
            .then(responseData => {
                if (responseData.success) {
                    document.getElementById("ssc-checkout-message").innerText = "Payment successful!";
                    document.getElementById("ssc-cart-items").innerHTML = "";
                    document.getElementById("ssc-cart-total").innerText = "0.00";
                } else {
                    document.getElementById("ssc-checkout-message").innerText = "Payment failed: " + responseData.data.message;
                }
            })
            .catch(error => console.error("Error processing payment:", error));
        }
    });
}
