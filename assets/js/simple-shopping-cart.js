jQuery(document).ready(function($){
	// Handle "Add to Cart" click.
	$('.ssc-add-to-cart').on('click', function(){
		var container = $(this).closest('.ssc-product');
		var product   = container.data('product');
		var price     = container.data('price');
		$.post(ssc_ajax.ajax_url, {
			action: 'ssc_update_cart',
			product: product,
			action_type: 'add',
			price: price
		}, function(response){
			if(response.success){
				location.reload();
			} else {
				alert(response.data);
			}
		});
	});

	// Handle plus, minus, and remove buttons.
	$('.ssc-plus, .ssc-minus, .ssc-remove').on('click', function(){
		var container   = $(this).closest('[data-product]');
		var product     = container.data('product');
		var action_type = $(this).data('action');
		$.post(ssc_ajax.ajax_url, {
			action: 'ssc_update_cart',
			product: product,
			action_type: action_type
		}, function(response){
			if(response.success){
				location.reload();
			} else {
				alert(response.data);
			}
		});
	});

	// Handle checkout form submission.
	$('#ssc-checkout-form').on('submit', function(e){
		e.preventDefault();
		var formData = $(this).serialize();
		$.post(ssc_ajax.ajax_url, formData, function(response){
			$('#ssc-checkout-response').html(response.data);
			if(response.success){
				// Optionally, reload the page after success.
				location.reload();
			}
		});
	});
});
