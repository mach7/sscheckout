document.addEventListener("DOMContentLoaded", function () {
    let checkoutForm = document.getElementById("ssc-checkout-form");
    if (checkoutForm) {
        checkoutForm.addEventListener("submit", function (event) {
            event.preventDefault();
            processPayment();
        });
    }

    function processPayment() {
        let data = new FormData();
        data.append("action", "ssc_process_payment");

        fetch(ssc_ajax.ajax_url, {
            method: "POST",
            body: data,
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById("ssc-checkout-message").innerText = "Payment successful!";
                document.getElementById("ssc-cart-items").innerHTML = "";
                document.getElementById("ssc-cart-total").innerText = "0.00";
            } else {
                document.getElementById("ssc-checkout-message").innerText = "Payment failed. Please try again.";
            }
        })
        .catch(error => console.error("Error processing payment:", error));
    }
});
