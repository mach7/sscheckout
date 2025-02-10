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

        let cartContainer = document.querySelector(".ssc-cart-container");

        if (cartContainer) {
            console.log("Updating full cart container");
            cartContainer.innerHTML = data.data.cart_html; // Insert updated cart HTML
        } else {
            console.warn("Warning: .ssc-cart-container element not found. Appending new cart.");
            
            let newCart = document.createElement("div");
            newCart.classList.add("ssc-cart-container");
            newCart.innerHTML = data.data.cart_html;
            document.body.appendChild(newCart); // Append to body or correct section
        }

        // After updating the cart, update the button to show "- X +"
        updateCartButtons();
    })
    .catch(error => console.error("Error updating cart:", error));
}

function updateCartButtons() {
    document.querySelectorAll(".ssc-cart-btn").forEach(button => {
        let quantityElement = button.parentNode.querySelector(".ssc-cart-quantity");
        let quantity = parseInt(quantityElement?.innerText || 0);

        if (quantity > 0) {
            let productName = button.dataset.name;
            let productPrice = button.dataset.price;
            
            let newButtons = `
                <button class="ssc-cart-btn" data-action="decrease" data-name="${productName}" data-price="${productPrice}">-</button>
                <span class="ssc-cart-quantity">${quantity}</span>
                <button class="ssc-cart-btn" data-action="increase" data-name="${productName}" data-price="${productPrice}">+</button>
            `;
            button.parentNode.innerHTML = newButtons;
        }
    });
}
