jQuery(document).ready(function ($) {
    // Initialize Stripe with your publishable key from sscheckout_params.
    var stripe = Stripe(sscheckout_params.publishableKey);
    var elements = stripe.elements();

    // Custom styling for the Stripe Element.
    var style = {
        base: {
            color: "#32325d",
            fontFamily: '"Helvetica Neue", Helvetica, sans-serif',
            fontSmoothing: "antialiased",
            fontSize: "16px",
            "::placeholder": {
                color: "#aab7c4"
            }
        },
        invalid: {
            color: "#fa755a",
            iconColor: "#fa755a"
        }
    };

    // Create and mount the card Element.
    var card = elements.create("card", { style: style });
    card.mount("#card-element");

    // Handle real-time validation errors from the card Element.
    card.on("change", function (event) {
        var displayError = document.getElementById("card-errors");
        if (event.error) {
            displayError.textContent = event.error.message;
        } else {
            displayError.textContent = "";
        }
    });

    // Handle form submission.
    $("#ss-checkout-form").submit(function (e) {
        e.preventDefault();
        // Disable the submit button to prevent repeated clicks.
        $(this).find('button[type="submit"]').prop("disabled", true);

        stripe.createPaymentMethod({
            type: "card",
            card: card,
            billing_details: {
                name: $("input[name='name']").val(),
                email: $("input[name='email']").val(),
                phone: $("input[name='phone']").val()
            }
        }).then(function (result) {
            if (result.error) {
                $("#card-errors").text(result.error.message);
                $("#ss-checkout-form").find('button[type="submit"]').prop("disabled", false);
            } else {
                // Send the PaymentMethod ID to your server.
                var paymentMethod = result.paymentMethod.id;
                var formData = {
                    action: "ssc_checkout",
                    name: $("input[name='name']").val(),
                    email: $("input[name='email']").val(),
                    password: $("input[name='password']").val(),
                    phone: $("input[name='phone']").val(),
                    paymentMethod: paymentMethod
                };
                $.post(sscheckout_params.ajax_url, formData, function (response) {
                    if (response.success) {
                        $("#ssc-checkout-response").html("<p>" + response.data + "</p>");
                    } else {
                        $("#ssc-checkout-response").html("<p>Error: " + response.data + "</p>");
                        $("#ss-checkout-form").find('button[type="submit"]').prop("disabled", false);
                    }
                });
            }
        });
    });
});
