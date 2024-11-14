<?php

function generate_v2_reviews_widget_code($product, $currency) {
  $product_data = wc_yotpo_get_product_data($product);
  return "<div class='yotpo yotpo-main-widget'
      data-product-id='" . esc_attr($product_data['id']) . "'
      data-name='" . esc_attr($product_data['title']) . "'
      data-url='" . esc_attr($product_data['url']) . "'
      data-image-url='" . esc_attr($product_data['image-url']) . "'
      data-description='" . esc_attr($product_data['description']) . "'
      data-lang='" . esc_attr($product_data['lang']) . "'
      data-price='" . esc_attr($product->get_price()) . "'
      data-currency='" . esc_attr($currency) . "'
  ></div>";
}

function generate_v3_reviews_widget_code($product, $reviews_widget_id, $currency) {
  $product_data = wc_yotpo_get_product_data($product);
  return "<div class='yotpo-widget-instance'
      data-yotpo-instance-id='" . esc_attr($reviews_widget_id) . "'
      data-yotpo-product-id='" . esc_attr($product_data['id']) . "'
      data-yotpo-name='" . esc_attr($product_data['title']) . "'
      data-yotpo-url='" . esc_attr($product_data['url']) . "'
      data-yotpo-image-url='" . esc_attr($product_data['image-url']) . "'
      data-yotpo-price='" . esc_attr($product->get_price()) . "'
      data-yotpo-currency='" . esc_attr($currency) . "'
      data-yotpo-description='" . esc_attr($product_data['description']) . "'
  ></div>";
}