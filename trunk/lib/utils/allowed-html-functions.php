<?php

defined( 'ABSPATH' ) || exit;

function yotpo_nonce_field_allowed_html() {
  return array('input' => array(
    'id' => array(),
    'type' => array(),
    'name' => array(),
    'value' => array()
  ));
}

function yotpo_message_allowed_html() {
  return array(
    'div' => array('id' => array(), 'class' => array()),
    'p' => array(),
    'strong' => array()
  );
}

// The markup of the settings page (templates/wc-yotpo-settings.php, templates/reusables/).
function yotpo_settings_allowed_html() {
  return array(
    'form' => array(
      'method' => array(),
      'id' => array(),
      'action' => array(),
      'style' => array(),
      'target' => array()
    ),
    'section' => array(
      'class' => array(),
    ),
    'h1' => array(
      'class' => array(),
    ),
    'h2' => array(
      'class' => array(),
    ),
    'h3' => array(),
    'hr' => array(
      'class' => array(),
    ),
    'img' => array(
      'class' => array(),
      'src' => array(),
      'alt' => array(),
      'width' => array(),
      'height' => array(),
    ),
    'label' => array(
      'class' => array(),
      'for' => array(),
    ),
    'input' => array(
      'type' => array(),
      'class' => array(),
      'id' => array(),
      'name' => array(),
      'value' => array(),
      'maxlength' => array(),
      'checked' => array(),
      'readonly' => array(),
      'disabled' => array(),
      'role' => array(),
      'autocomplete' => array(),
    ),
    'p' => array(
      'class' => array(),
    ),
    'div' => array(
      'id' => array(),
      'class' => array(),
      'style' => array(),
    ),
    'a' => array(
      'href' => array(),
      'class' => array(),
      'target' => array(),
      'rel' => array(),
    ),
    'select' => array(
      'name' => array(),
      'id' => array(),
      'class' => array(),
    ),
    'option' => array(
      'value' => array(),
      'selected' => array(),
    ),
    'button' => array(
      'type' => array(),
      'id' => array(),
      'class' => array(),
      'disabled' => array(),
    ),
    'code' => array(),
    'span' => array(
      'class' => array(),
    ),
    'i' => array(),
    'iframe' => array(
      'name' => array(),
      'style' => array()
    ),
    'dialog' => array(
      'id' => array(),
      'class' => array(),
    ),
  );
}

// The registration screen (wc_display_yotpo_register).
function yotpo_allowed_register_html() {
  $allowed = yotpo_settings_allowed_html();
  return array(
    'div' => $allowed['div'],
    'section' => $allowed['section'],
    'h1' => $allowed['h1'],
    'h2' => $allowed['h2'],
    'hr' => $allowed['hr'],
    'img' => $allowed['img'],
    'form' => array(
      'method' => array(),
    ),
    'label' => $allowed['label'],
    'input' => array(
      'type' => array(),
      'id' => array(),
      'name' => array(),
      'value' => array(),
      'class' => array(),
    ),
    'a' => $allowed['a'],
    'p' => $allowed['p'],
  );
}

function yotpo_reviews_widget_allowed_html() {
  return array(
    'div' => array(
      'class' => array(),
      'data-product-id' => array(),
      'data-name' => array(),
      'data-url' => array(),
      'data-image-url' => array(),
      'data-description' => array(),
      'data-lang' => array(),
      'data-price' => array(),
      'data-currency' => array(),
      'data-yotpo-instance-id' => array(),
      'data-yotpo-product-id' => array(),
      'data-yotpo-name' => array(),
      'data-yotpo-url' => array(),
      'data-yotpo-image-url' => array(),
      'data-yotpo-price' => array(),
      'data-yotpo-currency' => array(),
      'data-yotpo-description' => array(),
    ),
  );
}

function yotpo_qna_widget_allowed_html() {
  return array(
    'div' => array(
      'class' => array(),
      'data-yotpo-instance-id' => array(),
      'data-yotpo-product-id' => array(),
      'data-yotpo-name' => array(),
      'data-yotpo-url' => array(),
      'data-yotpo-image-url' => array(),
      'data-yotpo-description' => array(),
    ),
  );
}

function yotpo_common_widgets_allowed_html() {
  return array(
    'div' => array(
      'class' => array(),
      'data-yotpo-instance-id' => array(),
      'data-yotpo-product-id' => array(),
    ),
  );
}

function yotpo_star_ratings_widgets_allowed_html() {
  return array(
    'script' => array(),
    'div' => array(
      'class' => array(),
      'data-product-id' => array(),
      'data-url' => array(),
      'data-lang' => array(),
      'data-yotpo-instance-id' => array(),
      'data-yotpo-product-id' => array(),
    ),
  );
}

function yotpo_noscript_pixel_allowed_html() {
  return array(
    'noscript' => array(),
    'img' => array(
      'src' => array(),
      'width' => array(),
      'height' => array(),
      )
    );
  }
