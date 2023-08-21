const PluginVersion = {
	v2: 'v2',
	v3: 'v3',
};

let yotpoWidgetVersion,
	yotpoV3Enablers,
	yotpoV2Enablers,
	yotpoV3Locations,
	yotpoV2Locations,
	yotpoQNAWidgetTabName,
	yotpoWidgetLocationOtherExplain,
	yotpoWidgetTabName,
	yotpoWidgetLocationManualExplain,
	yotpoV2WidgetLocation,
	yotpoV3WidgetLocation;

function defineSelectors() {
	yotpoWidgetVersion = jQuery('#yotpo-widget-version');
	yotpoV3Enablers = jQuery('#yotpo-v3-enablers');
	yotpoV2Enablers = jQuery('#yotpo-v2-enablers');
	yotpoV3Locations = jQuery('#yotpo-v3-locations');
	yotpoV2Locations = jQuery('#yotpo-v2-locations');
	yotpoQNAWidgetTabName = jQuery('#yotpo_settings_form .yotpo-qna-widget-tab-name');
	yotpoWidgetLocationOtherExplain = jQuery('#yotpo_settings_form .yotpo-widget-location-other-explain');
	yotpoWidgetTabName = jQuery('#yotpo_settings_form .yotpo-widget-tab-name');
	yotpoWidgetLocationManualExplain = jQuery('#yotpo_settings_form .yotpo-widget-location-manual-explain');
	yotpoV2WidgetLocation = jQuery('#yotpo_settings_form .yotpo-v2-widget-location');
	yotpoV3WidgetLocation = jQuery('#yotpo_settings_form .yotpo-v3-widget-location');
}

function monitorDynamicElements() {
	monitorWidgetsVersionSelect();
	monitorV2WidgetLocation();
	monitorV3WidgetLocation();
}

function changeElementVisibility(widgetSyncRow, visible, duration) {
	visible ? widgetSyncRow.show(duration) : widgetSyncRow.hide(duration);
}

function isThisVersionSelected(version) {
	if (!(version in PluginVersion)) {
		return false;
	}
	return yotpoWidgetVersion.val() === version;
}

function modifyFormPositionsVisibility() {
	changeElementVisibility(yotpoV3Enablers, isThisVersionSelected(PluginVersion.v3));
	changeElementVisibility(yotpoV2Enablers, isThisVersionSelected(PluginVersion.v2));
	changeElementVisibility(yotpoV3Locations, isThisVersionSelected(PluginVersion.v3));
	changeElementVisibility(yotpoV2Locations, isThisVersionSelected(PluginVersion.v2));
	isThisVersionSelected(PluginVersion.v3)
		? yotpoQNAWidgetTabName.removeClass('yotpo-qna-widget-tab-name--hidden')
		: yotpoQNAWidgetTabName.addClass('yotpo-qna-widget-tab-name--hidden');
}

function monitorWidgetsVersionSelect() {
	modifyFormPositionsVisibility();
	yotpoWidgetVersion.change(() => modifyFormPositionsVisibility());
}

function monitorV2WidgetLocation() {
	function hideTabName(duration) {
		changeElementVisibility(yotpoWidgetTabName, yotpoV2WidgetLocation.val() == 'tab', duration);
	};

	function hideOtherExplanation(duration) {
		changeElementVisibility(yotpoWidgetLocationOtherExplain, yotpoV2WidgetLocation.val() == 'other', duration);
	};

	hideTabName(0);
	hideOtherExplanation(0);
	yotpoV2WidgetLocation.change(() => {
		hideTabName(1000);
		hideOtherExplanation(1000);
	});
}

function monitorV3WidgetLocation() {
	function hideManualExplanation(duration) {
		changeElementVisibility(yotpoWidgetLocationManualExplain, yotpoV3WidgetLocation.val() == 'manual', duration);
	};

	hideManualExplanation(0);
	yotpoV3WidgetLocation.change(() => {
		hideManualExplanation(1000);
	});
}

function openInfoDialogIfExists() {
	const infoDialog = jQuery('#info-dialog')[0];
	if (infoDialog) {
		infoDialog.showModal();

		jQuery('#close-info-modal').click(() => {
			infoDialog.close();
			window.open('https://reviews.yotpo.com/#/display-reviews/on-site-widgets', '_blank').focus();
		});
	}
}

jQuery(document).ready(function () {
	defineSelectors();

	jQuery('#yotpo-export-reviews').click(function() {
		document.getElementById('export_reviews_submit').click();
	});

	monitorDynamicElements();
	openInfoDialogIfExists();
});
