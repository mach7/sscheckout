console.log("SSC Cart JS Loaded");

document.addEventListener("DOMContentLoaded", function () {
    // Load cart on page load
    loadCart();

    document.body.addEventListener("click", function (event) {
        if (event.target.classList.contains("ssc-cart-btn")) {
            let action = event.target.dataset.action;
            let productName = event.target.dataset.name;
            let productPrice = event.target.dataset.price;
            updateCart(action, productName, productPrice);
        }
    });
});

function loadCart() {
    let data = new FormData();
    data.append("action", "ssc_load_cart");

    console.log("Sending AJAX request to load cart:", data);

    fetch(ssc_ajax.ajax_url, {
        method: "POST",
        body: data,
    })
    .then(response => response.json())
    .then(responseData => {
        console.log("Cart loaded:", responseData);
        if (responseData.success) {
            let cartContainer = document.querySelector(".ssc-cart-container");
            if (cartContainer) {
                cartContainer.innerHTML = responseData.data.cart_html;
            }
        } else {
            console.error("Failed to load cart:", responseData);
        }
    })
    .catch(error => console.error("Error loading cart:", error));
}

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
    .then(responseData => {
        console.log("Full AJAX response:", responseData);
        let cartContainer = document.querySelector(".ssc-cart-container");
        if (cartContainer) {
            cartContainer.innerHTML = responseData.data.cart_html;
        } else {
            console.warn("Warning: .ssc-cart-container not found. Appending new cart.");
            let newCart = document.createElement("div");
            newCart.classList.add("ssc-cart-container");
            newCart.innerHTML = responseData.data.cart_html;
            document.body.appendChild(newCart);
        }
    })
    .catch(error => console.error("Error updating cart:", error));
}
