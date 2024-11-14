<?php

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

function yotpo_settings_allowed_html() {
  return array(
    'style' => array(),
    'form' => array(
      'method' => array(),
      'id' => array(),
      'action' => array(),
      'style' => array(),
      'target' => array()
    ),
    'fieldset' => array(),
    'table' => array(
      'class' => array(),
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
    ),
    'tbody' => array(
      'id' => array(),
      'style' => array(),
    ),
    'tr' => array(
      'id' => array(),
      'valign' => array(),
      'style' => array(),
      'class' => array(),
    ),
    'th' => array(
      'scope' => array(),
      'rowspan' => array(),
      'colspan' => array(),
    ),
    'td' => array(),
    'p' => array(
      'class' => array(),
      'style' => array(),
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
    ),
    'br' => array(),
    'code' => array(),
    'span' => array(),
    'i' => array(),
    'h2' => array(),
    'h4' => array(),
    'iframe' => array(
      'name' => array(),
      'style' => array()
    ),
    'dialog' => array(
      'id' => array()
    ),
  );
}

function yotpo_allowed_register_html() {
  return array(
    'div' => array(
        'class' => array(),
        'id' => array(),
    ),
    'h2' => array(),
    'form' => array(
        'method' => array(),
    ),
    'table' => array(
        'class' => array(),
    ),
    'fieldset' => array(),
    'h2' => array(
        'class' => array(),
    ),
    'tr' => array(
        'valign' => array(),
    ),
    'th' => array(
        'scope' => array(),
    ),
    'td' => array(),
    'input' => array(
        'type' => array(),
        'name' => array(),
        'value' => array(),
        'class' => array(),
    ),
    'a' => array(
        'href' => array(),
        'target' => array(),
    ),
    'p' => array(
        'class' => array(),
    ),
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
