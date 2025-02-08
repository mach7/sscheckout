document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll(".ssc-cart-btn").forEach(function (button) {
        button.addEventListener("click", function () {
            let action = this.dataset.action;
            let productName = this.dataset.name;
            let productPrice = this.dataset.price;

            updateCart(action, productName, productPrice);
        });
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
            }
        })
        .catch(error => console.error("Error updating cart:", error));
    }
});
