<?php

function yotpo_order_status_option($yotpo_settings) {
  return "<tr valign='top'>
      <th scope='row'><div>Order Status:</div></th>
      <td>
          <select name='yotpo_order_status' class='yotpo-order-status' >
              <option value='wc-completed' " . selected('wc-completed', $yotpo_settings['yotpo_order_status'], false) . ">Completed</option>
              <option value='wc-pending' " . selected('wc-pending', $yotpo_settings['yotpo_order_status'], false) . ">Pending Payment</option>
              <option value='wc-processing' " . selected('wc-processing', $yotpo_settings['yotpo_order_status'], false) . ">Processing</option>
              <option value='wc-on-hold' " . selected('wc-on-hold', $yotpo_settings['yotpo_order_status'], false) . ">On Hold</option>
              <option value='wc-cancelled' " . selected('wc-cancelled', $yotpo_settings['yotpo_order_status'], false) . ">Cancelled</option>
              <option value='wc-refunded' " . selected('wc-refunded', $yotpo_settings['yotpo_order_status'], false) . ">Refunded</option>
              <option value='wc-failed' " . selected('wc-failed', $yotpo_settings['yotpo_order_status'], false) . ">Failed</option>
          </select>
      </td>
  </tr>";
}
