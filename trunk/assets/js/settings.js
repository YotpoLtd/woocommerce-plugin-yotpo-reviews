jQuery(document).ready(function () {
	var hide_tabname = function(duration) {
		if(jQuery('#yotpo_settings_form .yotpo-widget-location').val() == 'tab') {
			jQuery('#yotpo_settings_form .yotpo-widget-tab-name').show(duration);
		}
		else {
			jQuery('#yotpo_settings_form .yotpo-widget-tab-name').hide(duration);
		}	
	};
	
	var hide_other_explanation = function(duration) {
		if(jQuery('#yotpo_settings_form .yotpo-widget-location').val() == 'other') {
			jQuery('#yotpo_settings_form .yotpo-widget-location-other-explain').show(duration);
		}
		else {
			jQuery('#yotpo_settings_form .yotpo-widget-location-other-explain').hide(duration);
		}	
	};
	
	hide_tabname(0);
	hide_other_explanation(0);
	jQuery('#yotpo_settings_form .yotpo-widget-location').change(function() {
		hide_tabname(1000);
		hide_other_explanation(1000);
	});
	
	jQuery('#yotpo-export-reviews').click(function() {
		document.getElementById('export_reviews_submit').click();    
	});
});

