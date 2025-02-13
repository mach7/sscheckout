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
    
    /***** Client-Side Pickup Time Validation *****/
    // Only add pickup time validation if pickup options are enabled.
    if (sscheckout_params.enable_pickup) {
        // Listen for changes on the pickup time field.
        $("#pickup_time").on("change", function() {
            var pickupTimeStr = $(this).val(); // Expected format: "YYYY-MM-DDTHH:mm" from datetime-local input
            var pickupDate = new Date(pickupTimeStr);
            if (isNaN(pickupDate)) {
                $("#pickup-time-error").text("Invalid date/time selected.");
                $("#ss-checkout-form button[type='submit']").prop("disabled", true);
                return;
            }
            
            var now = new Date();
            // Get the selected pickup type
            var pickupTypeName = $("#pickup_type").val();
            var pickupType = null;
            if (sscheckout_params.pickup_types && Array.isArray(sscheckout_params.pickup_types)) {
                for (var i = 0; i < sscheckout_params.pickup_types.length; i++) {
                    if (sscheckout_params.pickup_types[i].name === pickupTypeName) {
                        pickupType = sscheckout_params.pickup_types[i];
                        break;
                    }
                }
            }
            if (!pickupType) {
                $("#pickup-time-error").text("Invalid pickup type.");
                $("#ss-checkout-form button[type='submit']").prop("disabled", true);
                return;
            }
            // Validate minimum lead time (in hours)
            var minLeadTime = pickupType.min_lead_time; // in hours
            var minAllowedTime = new Date(now.getTime() + minLeadTime * 60 * 60 * 1000);
            if (pickupDate < minAllowedTime) {
                $("#pickup-time-error").text("Pickup time must be at least " + minLeadTime + " hours from now.");
                $("#ss-checkout-form button[type='submit']").prop("disabled", true);
                return;
            }
            // Validate global restrictions.
            var dayNum = pickupDate.getDay();
            if (dayNum === 0) { dayNum = 7; }
            var closedDays = sscheckout_params.global_restrictions.closed_days || [];
            // Ensure closedDays are numbers
            closedDays = closedDays.map(function(day) { return parseInt(day, 10); });
            if (closedDays.indexOf(dayNum) !== -1) {
                $("#pickup-time-error").text("The selected day is closed for orders.");
                $("#ss-checkout-form button[type='submit']").prop("disabled", true);
                return;
            }

            // Validate against allowed time blocks for the selected pickup type.
            // Our pickup type's time_blocks use 3-letter abbreviations: Mon, Tue, Wed, etc.
            var dayAbbr = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
            var dayStr = dayAbbr[dayNum - 1];
            var allowedBlocks = pickupType.time_blocks[dayStr];
            if (allowedBlocks && allowedBlocks.length > 0) {
                // Extract the time portion in HH:mm format.
                var hours = pickupDate.getHours();
                var minutes = pickupDate.getMinutes();
                var timeStr = ('0' + hours).slice(-2) + ':' + ('0' + minutes).slice(-2);
                var valid = false;
                for (var j = 0; j < allowedBlocks.length; j++) {
                    var range = allowedBlocks[j].split('-'); // Expected format: "HH:MM-HH:MM"
                    if (timeStr >= range[0] && timeStr <= range[1]) {
                        valid = true;
                        break;
                    }
                }
                if (!valid) {
                    $("#pickup-time-error").text(pickupType.name + " orders can only be picked up during designated hours. Please choose a different pickup time.");
                    $("#ss-checkout-form button[type='submit']").prop("disabled", true);
                    return;
                }
            }
            // If all checks pass, clear any error message and enable the submit button.
            $("#pickup-time-error").text("");
            $("#ss-checkout-form button[type='submit']").prop("disabled", false);
        });
    }
    
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
