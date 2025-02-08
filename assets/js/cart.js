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
            console.log("Full AJAX response:", data); // Debugging step
        
            let cartTotalElement = document.getElementById("ssc-cart-total");
            let cartItemsElement = document.getElementById("ssc-cart-items");
        
            if (cartItemsElement) {
                console.log("Updating cart items:", data.cart_html);
                cartItemsElement.innerHTML = data.cart_html;
            } else {
                console.warn("Warning: #ssc-cart-items element not found.");
            }
        
            // After updating the cart, get the new cart total element from updated HTML
            setTimeout(() => {
                let updatedCartTotalElement = document.getElementById("ssc-cart-total");
        
                if (updatedCartTotalElement) {
                    console.log("Updating cart total:", data.cart_total);
                    updatedCartTotalElement.innerText = data.cart_total;
                } else {
                    console.warn("Warning: #ssc-cart-total element not found after update.");
                }
            }, 100); // Wait a moment to allow DOM update
        })
        .catch(error => console.error("Error updating cart:", error));        
    }
});
