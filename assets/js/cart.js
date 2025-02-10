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
        
        // If the cart container doesn't exist, create it dynamically
        let newCart = document.createElement("div");
        newCart.classList.add("ssc-cart-container");
        newCart.innerHTML = data.data.cart_html;
        document.body.appendChild(newCart); // Append to body or correct section
    }

    // After updating the cart, update the button to show "- X +"
    updateCartButtons();
})
.catch(error => console.error("Error updating cart:", error));

// Function to update the "Add to Cart" button to "- X +"
function updateCartButtons() {
    document.querySelectorAll(".ssc-cart-btn").forEach(button => {
        let quantity = parseInt(button.parentNode.querySelector(".ssc-cart-quantity")?.innerText || 0);

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
