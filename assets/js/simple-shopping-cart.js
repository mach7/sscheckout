jQuery(document).ready(function($) {

    /***** Stripe Checkout Integration *****/
    if ($('#card-element').length) {
        var stripe = Stripe(sscheckout_params.publishableKey);
        var elements = stripe.elements();

        var style = {
            base: {
                color: "#32325d",
                fontFamily: '"Helvetica Neue", Helvetica, sans-serif',
                fontSmoothing: "antialiased",
                fontSize: "16px",
                "::placeholder": { color: "#aab7c4" }
            },
            invalid: { color: "#fa755a", iconColor: "#fa755a" }
        };

        var card = elements.create("card", { style: style });
        card.mount("#card-element");

        card.on("change", function(event) {
            var displayError = $("#card-errors");
            displayError.text(event.error ? event.error.message : "");
        });

        $("#ss-checkout-form").submit(function(e) {
            e.preventDefault();
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
                    var formData = {
                        action: "ssc_checkout",
                        name: $("input[name='name']").val(),
                        email: $("input[name='email']").val(),
                        password: $("input[name='password']").val(),
                        phone: $("input[name='phone']").val(),
                        paymentMethod: result.paymentMethod.id,
                        pickup_type: $("#pickup_type").val(),
                        pickup_date: $("#pickup_date").val(),
                        pickup_time: $("#pickup_time").val()
                    };
                    $.post(sscheckout_params.ajax_url, formData, function(response) {
                        if (response.success) {
                            $("#ss-checkout-response").html("<p>" + response.data + "</p>");
                            setTimeout(function() { location.reload(); }, 2000);
                        } else {
                            $("#ss-checkout-response").html("<p>Error: " + response.data + "</p>");
                            $("#ss-checkout-form").find('button[type="submit"]').prop("disabled", false);
                        }
                    });
                }
            });
        });
    }

    /***** Pickup Time Validation *****/
    if (sscheckout_params.enable_pickup) {
        $("#pickup_date, #pickup_time").on("input change", function() {
            $("#pickup-time-error").hide().text(""); // Clear error

            var dateStr = $("#pickup_date").val();
            var timeStr = $("#pickup_time").val();

            if (!dateStr || !timeStr) {
                $("#pickup-time-error").hide().text("Please select both a date and a time.").fadeIn("slow");
                $("#ss-checkout-form button[type='submit']").prop("disabled", true);
                return;
            }

            var pickupDateTime = new Date(dateStr + "T" + timeStr);
            if (isNaN(pickupDateTime)) {
                $("#pickup-time-error").hide().text("Invalid date/time selected.").fadeIn("slow");
                $("#ss-checkout-form button[type='submit']").prop("disabled", true);
                return;
            }

            var now = new Date();
            var pickupTypeName = $("#pickup_type").val();
            var pickupType = sscheckout_params.pickup_types.find(pt => pt.name === pickupTypeName);

            if (!pickupType) {
                $("#pickup-time-error").hide().text("Invalid pickup type.").fadeIn("slow");
                $("#ss-checkout-form button[type='submit']").prop("disabled", true);
                return;
            }

            // Validate minimum lead time
            var minLeadTime = pickupType.min_lead_time;
            var minAllowedTime = new Date(now.getTime() + minLeadTime * 60 * 60 * 1000);
            if (pickupDateTime < minAllowedTime) {
                $("#pickup-time-error").hide().text("Pickup time must be at least " + minLeadTime + " hours from now.").fadeIn("slow");
                $("#ss-checkout-form button[type='submit']").prop("disabled", true);
                return;
            }

            // Determine day of week
            var dayNum = pickupDateTime.getDay();
            if (dayNum === 0) { dayNum = 7; } // Convert Sunday (0) to 7

            var closedDays = sscheckout_params.global_restrictions.closed_days.map(day => parseInt(day, 10));

            if (closedDays.includes(dayNum)) {
                $("#pickup-time-error").hide().text("The selected day is closed for orders.").fadeIn("slow");
                $("#ss-checkout-form button[type='submit']").prop("disabled", true);
                return;
            }

            // Validate against allowed time blocks
            var dayAbbr = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
            var dayStr = dayAbbr[dayNum - 1];
            var allowedBlocks = pickupType.time_blocks[dayStr];

            if (!allowedBlocks || allowedBlocks.length === 0) {
                $("#pickup-time-error").hide().text("The selected day is closed for orders.").fadeIn("slow");
                $("#ss-checkout-form button[type='submit']").prop("disabled", true);
                return;
            }

            var timeFormatted = pickupDateTime.toTimeString().slice(0, 5); // HH:MM
            var valid = allowedBlocks.some(range => {
                var [start, end] = range.split('-');
                return timeFormatted >= start && timeFormatted <= end;
            });

            if (!valid) {
                $("#pickup-time-error").hide().text("The selected pickup time is outside the allowed time blocks for " + pickupType.name + ".").fadeIn("slow");
                $("#ss-checkout-form button[type='submit']").prop("disabled", true);
                return;
            }

            $("#pickup-time-error").fadeOut("slow");
            $("#ss-checkout-form button[type='submit']").prop("disabled", false);
        });
    }

    /***** Cart Update Functionality *****/
    function updateCartTotal(newTotal) {
        $(".ssc-cart-total h3").text("Total: $" + newTotal);
    }

    function updateItemQuantity($element, newQuantity) {
        if ($element.is("tr")) {
            $element.find(".ssc-item-quantity").text(newQuantity);
        } else {
            $element.find(".ssc-quantity").text(newQuantity);
        }
    }

    function handleCartUpdate(action, productName, price) {
        $.post(sscheckout_params.ajax_url, {
            action: "ssc_update_cart",
            product: productName,
            action_type: action,
            price: price
        }, function(response) {
            if (response.success) {
                var newQuantity = response.data.quantity;
                var newTotal = response.data.cart_total;

                if (newQuantity === 0) {
                    $("tr[data-product='" + productName + "'], .ssc-product[data-product='" + productName + "']").remove();
                } else {
                    updateItemQuantity($("tr[data-product='" + productName + "'], .ssc-product[data-product='" + productName + "']"), newQuantity);
                }

                updateCartTotal(newTotal);
            } else {
                console.log("Error updating cart: " + response.data);
            }
        });
    }

    $(".ssc-add-to-cart").on("click", function(e) {
        e.preventDefault();
        var $productElem = $(this).closest(".ssc-product");
        var productName = $productElem.data("product");
        var productPrice = $productElem.data("price");
        handleCartUpdate("add", productName, productPrice);
    });

    $(document).on("click", ".ssc-plus", function(e) {
        e.preventDefault();
        handleCartUpdate("plus", $(this).closest(".ssc-product, tr[data-product]").data("product"));
    });

    $(document).on("click", ".ssc-minus", function(e) {
        e.preventDefault();
        handleCartUpdate("minus", $(this).closest(".ssc-product, tr[data-product]").data("product"));
    });

    $(document).on("click", ".ssc-remove", function(e) {
        e.preventDefault();
        handleCartUpdate("remove", $(this).closest(".ssc-product, tr[data-product]").data("product"));
    });

});
