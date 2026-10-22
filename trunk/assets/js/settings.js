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
	applyWidgetVersion();
	monitorV2WidgetLocation();
	monitorV3WidgetLocation();
}

function changeElementVisibility(widgetSyncRow, visible, duration) {
	visible ? widgetSyncRow.show(duration) : widgetSyncRow.hide(duration);
}

function isStoreOnVersion(version) {
	if (!(version in PluginVersion)) {
		return false;
	}
	return yotpoWidgetVersion.val() === version;
}

function modifyFormPositionsVisibility() {
	changeElementVisibility(yotpoV3Enablers, isStoreOnVersion(PluginVersion.v3));
	changeElementVisibility(yotpoV2Enablers, isStoreOnVersion(PluginVersion.v2));
	changeElementVisibility(yotpoV3Locations, isStoreOnVersion(PluginVersion.v3));
	changeElementVisibility(yotpoV2Locations, isStoreOnVersion(PluginVersion.v2));
	isStoreOnVersion(PluginVersion.v3)
		? yotpoQNAWidgetTabName.removeClass('yotpo-qna-widget-tab-name--hidden')
		: yotpoQNAWidgetTabName.addClass('yotpo-qna-widget-tab-name--hidden');
}

// The widget version is fixed for the store (a hidden field, not a select): apply it once on load.
function applyWidgetVersion() {
	modifyFormPositionsVisibility();
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

/**
 * Send past orders: keeps the order count in step with the days typed, and asks before a run starts.
 * The days field and the button belong to their own form, not the settings form, so the confirmation also
 * says that other changes on the page are not saved by it.
 */
function monitorPastOrders() {
	const days = jQuery('#yotpo_past_orders_days');
	const count = jQuery('#yotpo-past-orders-count');
	const button = jQuery('#yotpo-past-orders');
	if (!days.length || typeof yotpoPastOrders === 'undefined') {
		return;
	}
	let latest = { days: parseInt(days.val(), 10), count: null };
	let timer;

	function validDays() {
		const value = parseInt(days.val(), 10);
		return value >= 1 && value <= parseInt(days.attr('max'), 10) ? value : null;
	}

	function refresh() {
		const value = validDays();
		if (value === null) {
			count.text('Enter between 1 and ' + days.attr('max') + ' days.');
			return;
		}
		jQuery.post(yotpoPastOrders.ajaxUrl, { action: 'wc_yotpo_past_orders_count', _ajax_nonce: yotpoPastOrders.nonce, days: value })
			.done((response) => {
				if (response && response.success && response.data.days === validDays()) {
					latest = response.data;
					count.text(latest.count + (latest.count === 1 ? ' order' : ' orders') + ' in the last ' + latest.days + ' days.');
				}
			});
	}

	// Whole days only, never above the maximum: a keystroke or paste that would go past it is undone,
	// and the field keeps its last accepted value. Empty and 0 are allowed while typing; the button
	// checks the minimum.
	let accepted = days.val();
	days.on('input', () => {
		const typed = String(days.val()).replace(/\D/g, '');
		if (typed !== '' && parseInt(typed, 10) > parseInt(days.attr('max'), 10)) {
			days.val(accepted);
			return;
		}
		if (typed !== String(days.val())) {
			days.val(typed);
		}
		accepted = typed;
		latest.count = null;
		clearTimeout(timer);
		timer = setTimeout(refresh, 400);
	});

	button.on('click', (event) => {
		const value = validDays();
		if (value === null) {
			event.preventDefault();
			refresh();
			return;
		}
		const orders = latest.days === value && latest.count !== null ? latest.count + ' orders' : 'the orders';
		const question = 'Send ' + orders + ' from the last ' + value + ' days to Yotpo?\n\n'
			+ 'Your customers may get a review request, including for older orders. Orders Yotpo already has are skipped.\n\n'
			+ 'Other changes on this page are not saved by this button.';
		if (!window.confirm(question)) {
			event.preventDefault();
		}
	});
}

/**
 * Switch to V3 widgets (stores still on v2): one way, so it asks first. The button belongs to its own form,
 * so other changes on the page are not saved by it.
 */
function confirmSwitchToV3() {
	jQuery('#yotpo-switch-to-v3').on('click', (event) => {
		const question = 'Switch this store to V3 widgets?\n\n'
			+ 'Your product pages will show the V3 widgets set up in your Yotpo dashboard. You can\'t switch back.\n\n'
			+ 'Other changes on this page are not saved by this button.';
		if (!window.confirm(question)) {
			event.preventDefault();
		}
	});
}

// Saved keys are read-only until the merchant chooses to change them; the saved secret is never in the page.
function unlockKeys() {
	jQuery('#yotpo-change-keys').on('click', function () {
		jQuery('#app_key, #secret').prop('readonly', false);
		jQuery('#secret').val('').attr('placeholder', 'Leave empty to keep the saved secret');
		jQuery('#yotpo-change-keys-note').prop('hidden', false);
		jQuery(this).hide();
		jQuery('#app_key').trigger('focus');
	});
}

jQuery(document).ready(function () {
	defineSelectors();

	jQuery('#yotpo-export-reviews').click(function() {
		document.getElementById('export_reviews_submit').click();
	});

	monitorDynamicElements();
	monitorPastOrders();
	confirmSwitchToV3();
	unlockKeys();
	openInfoDialogIfExists();
});
