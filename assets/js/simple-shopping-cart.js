jQuery(document).ready(function ($) {
    // Initialize Stripe using the publishable key passed from PHP.
    var stripe = Stripe(ssc_ajax.publishableKey);
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

    // Create the card Element and mount it.
    var card = elements.create("card", { style: style });
    card.mount("#card-element");

    // Handle real-time validation errors.
    card.on("change", function (event) {
        var displayError = document.getElementById("card-errors");
        displayError.textContent = event.error ? event.error.message : "";
    });

    // Handle checkout form submission.
    $("#ssc-checkout-form").submit(function (e) {
        e.preventDefault();
        var $form = $(this);
        $form.find('button[type="submit"]').prop("disabled", true);

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
                $form.find('button[type="submit"]').prop("disabled", false);
            } else {
                var formData = {
                    action: "ssc_checkout",
                    name: $("input[name='name']").val(),
                    email: $("input[name='email']").val(),
                    password: $("input[name='password']").val(),
                    phone: $("input[name='phone']").val(),
                    paymentMethod: result.paymentMethod.id
                };
                $.post(ssc_ajax.ajax_url, formData, function (response) {
                    if (response.success) {
                        $("#ssc-checkout-response").html("<p>" + response.data + "</p>");
                    } else {
                        $("#ssc-checkout-response").html("<p>Error: " + response.data + "</p>");
                        $form.find('button[type="submit"]').prop("disabled", false);
                    }
                });
            }
        });
    });
});
