<?php

function info_dialog($use_v3_widgets, $widgets_to_customize) {
  return !strlen($widgets_to_customize) || !$use_v3_widgets
    ? ''
    : "
      <dialog id='info-dialog'>
          <h3>You haven't customized your widgets yet</h3>
          <p>Please customize " . $widgets_to_customize . " in Yotpo Reviews, and then try updating again.</p>
          <button id='close-info-modal'>Go to Yotpo Reviews</button>
      </dialog>
    ";
}

function dialog_error_info(
  array $v3_widgets_enables,
  $reviews_widget_id,
  $qna_widget_id,
  $star_ratings_widget_id,
  $reviews_carousel_widget_id,
  $promoted_products_widget_id,
  $reviews_tab_widget_id
): string {
  $widgets_to_customize = array();
  if (!$reviews_widget_id && is_option_checked($v3_widgets_enables['reviews_widget_product'])) {
    array_push($widgets_to_customize, 'Reviews Widget');
  }
  if (!$qna_widget_id && is_option_checked($v3_widgets_enables['qna_product'])) {
    array_push($widgets_to_customize, 'Q&A');
  }
  if (!$reviews_carousel_widget_id
    && (
      is_option_checked($v3_widgets_enables['reviews_carousel_product'])
      || is_option_checked($v3_widgets_enables['reviews_carousel_category'])
      || is_option_checked($v3_widgets_enables['reviews_carousel_home'])
    )) {
    array_push($widgets_to_customize, 'Carousel Widget');
  }
  if (!$promoted_products_widget_id
    && (
      is_option_checked($v3_widgets_enables['promoted_products_product'])
      || is_option_checked($v3_widgets_enables['promoted_products_category'])
      || is_option_checked($v3_widgets_enables['promoted_products_home'])
    )
  ) {
    array_push($widgets_to_customize, 'Promoted Products');
  }
  if (!$reviews_tab_widget_id
    && (
      is_option_checked($v3_widgets_enables['reviews_tab_product'])
      || is_option_checked($v3_widgets_enables['reviews_tab_category'])
    )
  ) {
    array_push($widgets_to_customize, 'Reviews Tab');
  }
  if (
    !$star_ratings_widget_id
    && (is_option_checked($v3_widgets_enables['star_rating_product']) || is_option_checked($v3_widgets_enables['star_rating_category']))
  ) {
    array_push($widgets_to_customize, 'Star Rating');
  }

  $last  = array_slice($widgets_to_customize, -1);
  $first = implode(', ', array_slice($widgets_to_customize, 0, -1));
  $both  = array_filter(array_merge(array($first), $last), 'strlen');
  return implode(' and ', $both);
}

function is_option_checked($option) {
  return checked(1, $option, false);
}
