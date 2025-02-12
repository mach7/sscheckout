jQuery(document).ready(function ($) {
    // If #card-element exists, initialize Stripe elements for the checkout form.
    var cardElementDiv = document.getElementById('card-element');
    if (cardElementDiv) {
        // Initialize Stripe with your publishable key passed via wp_localize_script.
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

        // Create the card Element and mount it.
        var card = elements.create("card", { style: style });
        card.mount(cardElementDiv);

        // Handle real-time validation errors from the card Element.
        card.on("change", function (event) {
            var displayError = document.getElementById("card-errors");
            if (event.error) {
                displayError.textContent = event.error.message;
            } else {
                displayError.textContent = "";
            }
        });

        // Handle checkout form submission.
        $("#ss-checkout-form").submit(function (e) {
            e.preventDefault();
            // Disable the submit button to prevent repeated clicks.
            $(this).find('button[type="submit"]').prop("disabled", true);

            stripe
                .createPaymentMethod({
                    type: "card",
                    card: card,
                    billing_details: {
                        name: $("input[name='name']").val(),
                        email: $("input[name='email']").val(),
                        phone: $("input[name='phone']").val()
                    }
                })
                .then(function (result) {
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
                                $("#ss-checkout-response").html("<p>" + response.data + "</p>");
                                // Reload the page after 2 seconds to clear the cart.
                                setTimeout(function() {
                                    location.reload();
                                }, 2000);
                            } else {
                                $("#ss-checkout-response").html("<p>Error: " + response.data + "</p>");
                                $("#ss-checkout-form").find('button[type="submit"]').prop("disabled", false);
                            }
                        });
                    }
                });
        });
    } // End if cardElementDiv exists

    // Bind the "Add to Cart" click event (works on all pages).
    $(".ssc-add-to-cart").on("click", function (e) {
        e.preventDefault();
        var $productElem = $(this).closest(".ssc-product");
        var productName = $productElem.data("product");
        var productPrice = $productElem.data("price");

        // Send an AJAX request to add the product to the cart.
        $.post(sscheckout_params.ajax_url, {
            action: "ssc_update_cart",
            product: productName,
            action_type: "add",
            price: productPrice
        }, function (response) {
            if (response.success) {
                // Update the UI to show the new quantity.
                $productElem.html(
                    '<button class="ssc-minus" data-action="minus">–</button> ' +
                    '<span class="ssc-quantity">' + response.data.quantity + '</span> ' +
                    '<button class="ssc-plus" data-action="plus">+</button> ' +
                    '<button class="ssc-remove" data-action="remove">🗑️</button>'
                );
            } else {
                console.log("Error adding to cart: " + response.data);
            }
        });
    });

    // Bind event for increasing the quantity.
    $(document).on("click", ".ssc-plus", function (e) {
        e.preventDefault();
        var $element = $(this).closest(".ssc-product, tr[data-product]");
        var productName = $element.data("product");
        $.post(sscheckout_params.ajax_url, {
            action: "ssc_update_cart",
            product: productName,
            action_type: "plus"
        }, function (response) {
            if (response.success) {
                if ($element.is("tr")) {
                    $element.find(".ssc-item-quantity").text(response.data.quantity);
                } else {
                    $element.find(".ssc-quantity").text(response.data.quantity);
                }
            } else {
                console.log("Error updating cart (plus): " + response.data);
            }
        });
    });

    // Bind event for decreasing the quantity.
    $(document).on("click", ".ssc-minus", function (e) {
        e.preventDefault();
        var $element = $(this).closest(".ssc-product, tr[data-product]");
        var productName = $element.data("product");
        $.post(sscheckout_params.ajax_url, {
            action: "ssc_update_cart",
            product: productName,
            action_type: "minus"
        }, function (response) {
            if (response.success) {
                if ($element.is("tr")) {
                    $element.find(".ssc-item-quantity").text(response.data.quantity);
                } else {
                    $element.find(".ssc-quantity").text(response.data.quantity);
                }
            } else {
                console.log("Error updating cart (minus): " + response.data);
            }
        });
    });

    // Bind event for removing the item from the cart.
    $(document).on("click", ".ssc-remove", function (e) {
        e.preventDefault();
        var $element = $(this).closest(".ssc-product, tr[data-product]");
        var productName = $element.data("product");
        $.post(sscheckout_params.ajax_url, {
            action: "ssc_update_cart",
            product: productName,
            action_type: "remove"
        }, function (response) {
            if (response.success) {
                // Remove the element from the UI.
                $element.remove();
            } else {
                console.log("Error updating cart (remove): " + response.data);
            }
        });
    });
});
