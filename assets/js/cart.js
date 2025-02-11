jQuery(document).ready(function($) {
    // Handle Add to Cart button click
    $(document).on('click', '.ssc-add-to-cart', function(e) {
        e.preventDefault();
        var button = $(this);
        var productName = button.data('name');
        var price = button.data('price');
        updateCart('add', productName, price);
    });

    // Handle Increase quantity
    $(document).on('click', '.ssc-increase', function(e) {
        e.preventDefault();
        var button = $(this);
        var productName = button.data('name');
        var price = button.data('price');
        updateCart('add', productName, price);
    });

    // Handle Decrease quantity
    $(document).on('click', '.ssc-decrease', function(e) {
        e.preventDefault();
        var button = $(this);
        var productName = button.data('name');
        var price = button.data('price') || 0;
        updateCart('remove', productName, price);
    });

    // Function to update cart via AJAX
    function updateCart(operation, name, price) {
        var data = {
            action: 'ssc_update_cart',
            operation: operation,
            name: name,
            price: price
        };

        $.post(ssc_ajax.ajax_url, data, function(response) {
            if (response.success) {
                refreshCart();
            } else {
                console.log(response.data);
            }
        });
    }

    // Refresh the cart display
    function refreshCart() {
        $.post(ssc_ajax.ajax_url, { action: 'ssc_load_cart' }, function(response) {
            $('.sscheckout-cart').html(response);
        });
    }
});
