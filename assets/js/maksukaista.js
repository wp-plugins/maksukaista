jQuery(document).ready(function($){
	if (jQuery('form#order_review').size()>0) {

		jQuery('#maksukaista-bank-payments .bank-button').on('click',function() {
			jQuery('#maksukaista-bank-payments .bank-button').removeClass('selected');
			jQuery(this).addClass('selected');
			var id = jQuery(this).attr('id');
			var selected = id.replace('maksukaista-button-','');
			jQuery('#maksukaista_selected_bank').val(selected);
		});
	}

	jQuery('body').on( 'updated_checkout', function () {
		jQuery('#maksukaista-bank-payments .bank-button').on('click',function() {
			jQuery('#maksukaista-bank-payments .bank-button').removeClass('selected');
			jQuery(this).addClass('selected');
			var id = jQuery(this).attr('id');
			var selected = id.replace('maksukaista-button-','');
			jQuery('#maksukaista_selected_bank').val(selected);
		});

	});
}(jQuery));