jQuery(document).ready(function($) {
    /***** Stripe Checkout Integration *****/
    if ($('#card-element').length) {
        // Initialize Stripe
        var stripe = Stripe(sscheckout_params.publishableKey);
        var elements = stripe.elements();
        
        // Custom styling for the Stripe card element.
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
        
        // Create and mount the card element.
        var card = elements.create("card", { style: style });
        card.mount("#card-element");
        
        // Handle real-time validation errors.
        card.on("change", function(event) {
            var displayError = $("#card-errors");
            if (event.error) {
                displayError.text(event.error.message);
            } else {
                displayError.text("");
            }
        });
        
        // Checkout form submission.
        $("#ss-checkout-form").submit(function(e) {
            e.preventDefault();
            // Disable the submit button to prevent multiple clicks.
            $(this).find('button[type="submit"]').prop("disabled", true);
            
            stripe.createPaymentMethod({
                type: "card",
                card: card,
                billing_details: {
                    name: $("input[name='name']").val(),
                    email: $("input[name='email']").val(),
                    phone: $("input[name='phone']").val()
                }
            }).then(function(result) {
                if (result.error) {
                    $("#card-errors").text(result.error.message);
                    $("#ss-checkout-form").find('button[type="submit"]').prop("disabled", false);
                } else {
                    var paymentMethod = result.paymentMethod.id;
                    var formData = {
                        action: "ssc_checkout",
                        name: $("input[name='name']").val(),
                        email: $("input[name='email']").val(),
                        password: $("input[name='password']").val(),
                        phone: $("input[name='phone']").val(),
                        paymentMethod: paymentMethod,
                        pickup_type: $("#pickup_type").val(),  // Ensure your checkout form includes a select with id="pickup_type"
                        pickup_time: $("#pickup_time").val()   // And an input with id="pickup_time"
                    };
                    $.post(sscheckout_params.ajax_url, formData, function(response) {
                        if (response.success) {
                            $("#ss-checkout-response").html("<p>" + response.data + "</p>");
                            // Reload the page after a brief pause.
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
    }
    
    /***** Pickup Time Field *****/
    // If you're using a native HTML5 time input, no additional JS initialization is needed.
    // Example HTML for pickup time in your checkout form:
    // <input type="time" name="pickup_time" id="pickup_time" required>
    //
    // If you require a combined date/time picker with a 12-hour format,
    // consider enqueuing a dedicated JS library (such as Tempus Dominus or similar).
    
    /***** Cart Update Functionality *****/
    // Add to Cart.
    $(".ssc-add-to-cart").on("click", function(e) {
        e.preventDefault();
        var $productElem = $(this).closest(".ssc-product");
        var productName = $productElem.data("product");
        var productPrice = $productElem.data("price");
        $.post(sscheckout_params.ajax_url, {
            action: "ssc_update_cart",
            product: productName,
            action_type: "add",
            price: productPrice
        }, function(response) {
            if (response.success) {
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
    
    // Increase quantity.
    $(document).on("click", ".ssc-plus", function(e) {
        e.preventDefault();
        var $element = $(this).closest(".ssc-product, tr[data-product]");
        var productName = $element.data("product");
        $.post(sscheckout_params.ajax_url, {
            action: "ssc_update_cart",
            product: productName,
            action_type: "plus"
        }, function(response) {
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
    
    // Decrease quantity.
    $(document).on("click", ".ssc-minus", function(e) {
        e.preventDefault();
        var $element = $(this).closest(".ssc-product, tr[data-product]");
        var productName = $element.data("product");
        $.post(sscheckout_params.ajax_url, {
            action: "ssc_update_cart",
            product: productName,
            action_type: "minus"
        }, function(response) {
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
    
    // Remove item from cart.
    $(document).on("click", ".ssc-remove", function(e) {
        e.preventDefault();
        var $element = $(this).closest(".ssc-product, tr[data-product]");
        var productName = $element.data("product");
        $.post(sscheckout_params.ajax_url, {
            action: "ssc_update_cart",
            product: productName,
            action_type: "remove"
        }, function(response) {
            if (response.success) {
                $element.remove();
            } else {
                console.log("Error updating cart (remove): " + response.data);
            }
        });
    });
});
