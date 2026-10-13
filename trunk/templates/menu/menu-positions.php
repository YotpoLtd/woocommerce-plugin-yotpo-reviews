<?php

defined( 'ABSPATH' ) || exit;

function yotpo_order_status_option($yotpo_settings) {
  $statuses = array(
    'wc-completed' => 'Completed',
    'wc-pending' => 'Pending Payment',
    'wc-processing' => 'Processing',
    'wc-on-hold' => 'On Hold',
    'wc-cancelled' => 'Cancelled',
    'wc-refunded' => 'Refunded',
    'wc-failed' => 'Failed',
  );
  $options = '';
  foreach ($statuses as $value => $label) {
    $options .= "<option value='$value' " . selected($value, $yotpo_settings['yotpo_order_status'], false) . ">$label</option>";
  }
  return yotpo_select_row(
    'yotpo_order_status',
    'yotpo_order_status',
    'yotpo-order-status',
    'Send orders to Yotpo at status',
    $options,
    'Orders are sent to Yotpo when they reach this status, so Yotpo can send the review request.'
  );
}
