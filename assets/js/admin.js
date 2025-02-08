document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll(".ssc-admin-delete-order").forEach(function (button) {
        button.addEventListener("click", function () {
            let orderId = this.dataset.orderId;
            deleteOrder(orderId);
        });
    });

    function deleteOrder(orderId) {
        if (!confirm("Are you sure you want to delete this order?")) {
            return;
        }

        let data = new FormData();
        data.append("action", "ssc_delete_order");
        data.append("order_id", orderId);

        fetch(ssc_ajax.ajax_url, {
            method: "POST",
            body: data,
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById("ssc-order-" + orderId).remove();
            } else {
                alert("Failed to delete order. Please try again.");
            }
        })
        .catch(error => console.error("Error deleting order:", error));
    }
});