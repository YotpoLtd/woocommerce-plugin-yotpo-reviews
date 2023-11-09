<?php

// All widgets that are not supported directly should be pasted inside this function
// as following elements of the returned array.
function generate_v3_custom_widgets_code($product): array {
	$product_data = wc_yotpo_get_product_data($product);
  return [
    "<div class='yotpo-widget-instance'
      data-yotpo-instance-id='540894'
      data-yotpo-product-id='".$product_data['id']."'
    ></div>"
  ];
}
