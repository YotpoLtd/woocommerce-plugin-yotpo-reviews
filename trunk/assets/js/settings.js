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

	monitorDynamicElements();
});

function monitorDynamicElements() {

	const reviewsWidgetInput = jQuery('#reviews_widget');
	const qnaWidgetInput = jQuery('#qna_widget');
	const starRatingsWidgetInput = jQuery('#star_ratings_widget');
	const yotpoUseV3Widgets = jQuery('#yotpo_use_v3_widgets');
	const yotpoSyncWidgetIdsRow = jQuery('#yotpo-sync-widget-ids-row');
	const yotpoSyncWidgetIds = jQuery('#yotpo-sync-widget-ids');
	const saveYotpoSettingsButton = jQuery('#save_yotpo_settings');

	function updateWidgetSyncRowState(widgetSyncRow, visible) {
		visible ? widgetSyncRow.show() : widgetSyncRow.hide();
	}

	const widgetsIds = {
		reviewsWidget: 355852,
		qnaWidget: 355862,
		starRatings: 355851
	}

	function getWidgetsIDs() {
		return new Promise((resolve) => {
			setTimeout(() => {
				resolve(widgetsIds);
			}, 2000);
		});
	}

	function saveWidgetsIDs(widgetsIds) {
		reviewsWidgetInput.val(widgetsIds.reviewsWidget);
		qnaWidgetInput.val(widgetsIds.qnaWidget);
		starRatingsWidgetInput.val(widgetsIds.starRatings);
		saveYotpoSettingsButton.click();
	}

	function getAndSaveWidgetsIDs() {
		getWidgetsIDs().then(widgetsIds => saveWidgetsIDs(widgetsIds));
	}

	function monitorSyncIDsButton() {
		const syncIDsButton = yotpoSyncWidgetIds;
		syncIDsButton.on('click', () => getAndSaveWidgetsIDs());
	}

	function monitorV3WidgetsCheckbox() {
		const checkbox = yotpoUseV3Widgets;
		const widgetSyncRow = yotpoSyncWidgetIdsRow;
		updateWidgetSyncRowState(widgetSyncRow, checkbox.is(':checked'));
		checkbox.on('change',
			() => updateWidgetSyncRowState(widgetSyncRow, checkbox.is(':checked'))
		);
	}

	monitorSyncIDsButton();
	monitorV3WidgetsCheckbox();
}
