jQuery(document).ready(function($) {
    /***** Stripe Checkout Integration (SCA-compliant) *****/
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

        // Toggle delivery method sections
        function toggleDeliveryFields() {
            var method = $('input[name="delivery_method"]:checked').val();
            if (method === 'pickup') {
                $('#pickup-fields').slideDown('fast');
                $('#shipping-fields').slideUp('fast');
            } else {
                $('#shipping-fields').slideDown('fast');
                $('#pickup-fields').slideUp('fast');
                // Clear any pickup validation error and enable submit
                $("#pickup-time-error").hide().text("");
                $("#ss-checkout-form button[type='submit']").prop("disabled", false);
            }
        }
        $(document).on('change', 'input[name="delivery_method"]', toggleDeliveryFields);
        toggleDeliveryFields();

        $("#ss-checkout-form").submit(async function(e) {
            e.preventDefault();
            var $form = $(this);
            $form.find('button[type="submit"]').prop("disabled", true);
            // Step 1: Create PaymentIntent on server
            var createPayload = {
                action: "ssc_create_intent",
                nonce: sscheckout_params.checkout_nonce,
                name: $("input[name='name']").val(),
                email: $("input[name='email']").val(),
                phone: $("input[name='phone']").val(),
                delivery_method: $('input[name="delivery_method"]:checked').val(),
                pickup_type: $("#pickup_type").val(),
                pickup_date: $("#pickup_date").val(),
                pickup_time: $("#pickup_time").val(),
                ship_name: $("input[name='ship_name']").val(),
                ship_address1: $("input[name='ship_address1']").val(),
                ship_address2: $("input[name='ship_address2']").val(),
                ship_city: $("input[name='ship_city']").val(),
                ship_state: $("input[name='ship_state']").val(),
                ship_postal: $("input[name='ship_postal']").val(),
                ship_country: $("input[name='ship_country']").val()
            };
            try {
                const intentResp = await $.post(sscheckout_params.ajax_url, createPayload);
                if (!intentResp.success) {
                    $("#ss-checkout-response").html("<p>Error: " + intentResp.data + "</p>");
                    $form.find('button[type="submit"]').prop("disabled", false);
                    return;
                }
                var clientSecret = intentResp.data.client_secret;
                // Step 2: Confirm card payment on client
                var billingDetails = {
                    name: $("input[name='name']").val(),
                    email: $("input[name='email']").val(),
                    phone: $("input[name='phone']").val()
                };
                var result = await stripe.confirmCardPayment(clientSecret, {
                    payment_method: {
                        card: card,
                        billing_details: billingDetails
                    }
                });
                if (result.error) {
                    $("#card-errors").text(result.error.message);
                    $form.find('button[type="submit"]').prop("disabled", false);
                    return;
                }
                // Step 3: Finalize order on server
                var finalizePayload = {
                    action: "ssc_finalize_order",
                    nonce: sscheckout_params.checkout_nonce,
                    payment_intent_id: result.paymentIntent.id,
                    name: billingDetails.name,
                    email: billingDetails.email,
                    password: $("input[name='password']").val(),
                    phone: billingDetails.phone,
                    delivery_method: $('input[name="delivery_method"]:checked').val(),
                    pickup_type: $("#pickup_type").val(),
                    pickup_date: $("#pickup_date").val(),
                    pickup_time: $("#pickup_time").val(),
                    ship_name: $("input[name='ship_name']").val(),
                    ship_address1: $("input[name='ship_address1']").val(),
                    ship_address2: $("input[name='ship_address2']").val(),
                    ship_city: $("input[name='ship_city']").val(),
                    ship_state: $("input[name='ship_state']").val(),
                    ship_postal: $("input[name='ship_postal']").val(),
                    ship_country: $("input[name='ship_country']").val()
                };
                const finalizeResp = await $.post(sscheckout_params.ajax_url, finalizePayload);
                if (finalizeResp.success) {
                    $("#ss-checkout-response").html("<p>" + finalizeResp.data + "</p>");
                    setTimeout(function() { location.reload(); }, 2000);
                } else {
                    $("#ss-checkout-response").html("<p>Error: " + finalizeResp.data + "</p>");
                    $form.find('button[type="submit"]').prop("disabled", false);
                }
            } catch (err) {
                $("#ss-checkout-response").html("<p>Error: " + (err && err.message ? err.message : 'Unexpected error') + "</p>");
                $form.find('button[type="submit"]').prop("disabled", false);
            }
        });
    }
    /***** Pickup Time Validation *****/
    if (sscheckout_params.enable_pickup) {
        function isTimeWithinBlocks(dayKey, timeStr) {
            if (!sscheckout_params.pickup_types || !Array.isArray(sscheckout_params.pickup_types)) return true;
            var selectedTypeName = $("#pickup_type").val();
            var selectedType = sscheckout_params.pickup_types.find(function(pt){ return pt.name === selectedTypeName; });
            if (!selectedType || !selectedType.time_blocks) return true;
            var blocksForDay = selectedType.time_blocks[dayKey];
            if (!blocksForDay || !Array.isArray(blocksForDay) || blocksForDay.length === 0) return true;
            for (var i=0; i<blocksForDay.length; i++) {
                var parts = (blocksForDay[i] || '').split('-');
                if (parts.length !== 2) continue;
                if (timeStr >= parts[0] && timeStr <= parts[1]) return true;
            }
            return false;
        }
        $("#pickup_date, #pickup_time, #pickup_type, input[name='delivery_method']").on("input change", function() {
            if ($('input[name="delivery_method"]:checked').val() !== 'pickup') {
                $("#pickup-time-error").hide().text("");
                $("#ss-checkout-form button[type='submit']").prop("disabled", false);
                return;
            }
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
            var pickupType = sscheckout_params.pickup_types.find(function(pt) {
                return pt.name === pickupTypeName;
            });
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
            // Determine day of week (1 = Monday, 7 = Sunday)
            var dayNum = pickupDateTime.getDay();
            if (dayNum === 0) { dayNum = 7; }
            var closedDays = sscheckout_params.global_restrictions.closed_days.map(function(day) {
                return parseInt(day, 10);
            });
            if (closedDays.includes(dayNum)) {
                $("#pickup-time-error").hide().text("The selected day is closed for orders.").fadeIn("slow");
                $("#ss-checkout-form button[type='submit']").prop("disabled", true);
                return;
            }
            // Enforce allowed time blocks client-side
            var numToDay = {1:'monday',2:'tuesday',3:'wednesday',4:'thursday',5:'friday',6:'saturday',7:'sunday'};
            var dayKey = numToDay[dayNum];
            if (!isTimeWithinBlocks(dayKey, timeStr)) {
                $("#pickup-time-error").hide().text("The selected time is not available for the chosen pickup type.").fadeIn("slow");
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
        if ($element && $element.length) {
            if ($element.is("tr")) {
                $element.find(".ssc-item-quantity").text(newQuantity);
            } else {
                $element.find(".ssc-quantity").text(newQuantity);
            }
        }
    }
    function showCartError(message) {
        var $container = $("#ss-checkout-response");
        if ($container.length === 0) {
            $container = $('<div id="ss-checkout-response"></div>').appendTo('body');
        }
        $container.html("<p>Error: " + message + "</p>");
    }
    function handleCartUpdate(action, productName, price, $element) {
        $.post(sscheckout_params.ajax_url, {
            action: "ssc_update_cart",
            nonce: sscheckout_params.cart_nonce,
            product: productName,
            action_type: action,
            price: price
        }, function(response) {
            if (response.success) {
                var newQuantity = response.data.quantity;
                var newTotal = response.data.cart_total;
                if ($element && $element.length) {
                    if (newQuantity === 0) {
                        if ($element.hasClass("ssc-product")) {
                            $element.html(`<button class="ssc-add-to-cart">Add to Cart</button>`);
                        } else {
                            $element.remove();
                        }
                    } else {
                        updateItemQuantity($element, newQuantity);
                    }
                }
                updateCartTotal(newTotal);
            } else {
                showCartError(response.data);
            }
        });
    }
    /*** Add to Cart ***/
    $(document).on("click", ".ssc-add-to-cart", function(e) {
        e.preventDefault();
        var $productElem = $(this).closest(".ssc-product");
        var productName = $productElem.data("product");
        var productPrice = $productElem.data("price");
        var sig = $productElem.data("sig");
        $.post(sscheckout_params.ajax_url, {
            action: "ssc_update_cart",
            nonce: sscheckout_params.cart_nonce,
            product: productName,
            action_type: "add",
            price: productPrice,
            sig: sig
        }, function(response) {
            if (response.success) {
                $productElem.html(`
                    <button class="ssc-minus" data-action="minus">–</button>
                    <span class="ssc-quantity">${response.data.quantity}</span>
                    <button class="ssc-plus" data-action="plus">+</button>
                    <button class="ssc-remove" data-action="remove">🗑️</button>
                `);
                updateCartTotal(response.data.cart_total);
            } else {
                showCartError(response.data);
            }
        });
    });
    /*** Increase Quantity ***/
    $(document).on("click", ".ssc-plus", function(e) {
        e.preventDefault();
        var $element = $(this).closest(".ssc-product, tr[data-product]");
        var productName = $element.data("product");
        if ($element && $element.length) {
            handleCartUpdate("plus", productName, null, $element);
        } else {
            showCartError("Element not found for increasing quantity.");
        }
    });
    /*** Decrease Quantity ***/
    $(document).on("click", ".ssc-minus", function(e) {
        e.preventDefault();
        var $element = $(this).closest(".ssc-product, tr[data-product]");
        var productName = $element.data("product");
        if ($element && $element.length) {
            handleCartUpdate("minus", productName, null, $element);
        } else {
            showCartError("Element not found for decreasing quantity.");
        }
    });
    /*** Remove from Cart and Restore "Add to Cart" ***/
    $(document).on("click", ".ssc-remove", function(e) {
        e.preventDefault();
        var $element = $(this).closest(".ssc-product, tr[data-product]");
        var productName = $element.data("product");
        $.post(sscheckout_params.ajax_url, {
            action: "ssc_update_cart",
            nonce: sscheckout_params.cart_nonce,
            product: productName,
            action_type: "remove"
        }, function(response) {
            if (response.success) {
                var newTotal = response.data.cart_total;
                if ($element && $element.length) {
                    if ($element.hasClass("ssc-product")) {
                        // Restore "Add to Cart" button when removed
                        $element.html(`<button class="ssc-add-to-cart">Add to Cart</button>`);
                    } else {
                        // Remove row from cart table
                        $element.remove();
                    }
                }
                updateCartTotal(newTotal);
            } else {
                showCartError(response.data);
            }
        });
    });
});
