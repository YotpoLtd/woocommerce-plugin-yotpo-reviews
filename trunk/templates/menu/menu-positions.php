<?php

defined( 'ABSPATH' ) || exit;

function yotpo_order_status_option($yotpo_settings) {
  // WooCommerce's statuses, including those other plugins add (such as Shipped or Delivered). The
  // saved status is always listed, so saving the page never changes it.
  $statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : array();
  unset($statuses['wc-checkout-draft']);
  $saved = (string) $yotpo_settings['yotpo_order_status'];
  if ('' !== $saved && !isset($statuses[$saved])) {
    $statuses[$saved] = $saved;
  }
  $options = '';
  foreach ($statuses as $value => $label) {
    $options .= "<option value='" . esc_attr($value) . "' " . selected($value, $saved, false) . ">" . esc_html($label) . "</option>";
  }
  return yotpo_select_row(
    'yotpo_order_status',
    'yotpo_order_status',
    'yotpo-order-status',
    'Send review requests when an order reaches',
    $options,
    'The status that means the order is fulfilled. Each order is sent to Yotpo once, when it reaches this status.'
  );
}
