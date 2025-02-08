console.log("SSC Cart JS Loaded");

document.addEventListener("DOMContentLoaded", function () {
    document.body.addEventListener("click", function (event) {
        if (event.target.classList.contains("ssc-cart-btn")) {
            let action = event.target.dataset.action;
            let productName = event.target.dataset.name;
            let productPrice = event.target.dataset.price;

            updateCart(action, productName, productPrice);
        }
    });

    function updateCart(action, name, price) {
        let data = new FormData();
        data.append("action", "ssc_update_cart");
        data.append("cart_action", action);
        data.append("product_name", name);
        data.append("product_price", price);

        fetch(ssc_ajax.ajax_url, {
            method: "POST",
            body: data,
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById("ssc-cart-total").innerText = data.cart_total;
                document.getElementById("ssc-cart-items").innerHTML = data.cart_html;
            } else {
                console.error("Error: ", data.message);
            }
        })
        .catch(error => console.error("Error updating cart:", error));
    }
});
