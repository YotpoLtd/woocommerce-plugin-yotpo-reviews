<?php

defined( 'ABSPATH' ) || exit;

// Building blocks of the settings page. Styles: assets/css/yotpo.css. Field names are what
// wc_proccess_yotpo_settings() reads, so they must not change.

/**
 * One setting with an on/off switch.
 *
 * @param string $isChecked The checked() attribute, or ''.
 */
function v3_enabler($isChecked, string $name, string $text, ?string $subtext = null): string {
  return yotpo_switch_row($name, $name, $isChecked, $text, $subtext);
}

/**
 * One setting that applies to several pages: a checkbox per page.
 *
 * @param array $locations Each: text, name, checked (the checked() attribute).
 */
function v3_multifield_enabler(array $locations, $text, $subtext = null): string {
  $formatted_locations = '';
  foreach ($locations as $location) {
    $formatted_locations .= get_multifield_enabler_field($location);
  }
  return "
    <div class='yotpo-row'>
      <div class='yotpo-row__text'>
        <span class='yotpo-label'>" . $text . "</span>
        " . ($subtext ? "<p class='yotpo-help'>" . $subtext . "</p>" : '') . "
      </div>
      <div class='yotpo-row__control'>" . $formatted_locations . "</div>
    </div>
  ";
}

function get_multifield_enabler_field($location): string {
  return "
        <label class='yotpo-check' for='" . $location['name'] . "'>
          <input type='checkbox' class='yotpo-checkbox' id='" . $location['name'] . "' name='" . $location['name'] . "' value='1' " . $location['checked'] . " />
          " . $location['text'] . "
        </label>";
}

/**
 * A setting with an on/off switch: label and help text on the left, the switch on the right.
 *
 * @param string $checked   The checked() attribute, or ''.
 * @param string $row_class Extra classes for the row, such as the ones settings.js shows and hides.
 * @param string $after     Markup under the help text, such as a status line.
 */
function yotpo_switch_row($id, $name, $checked, $label, $help = null, $row_class = '', $after = '') {
  return "
    <div class='yotpo-row $row_class'>
      <div class='yotpo-row__text'>
        <label class='yotpo-label' for='$id'>" . $label . "</label>
        " . ($help ? "<p class='yotpo-help'>" . $help . "</p>" : '') . "
        $after
      </div>
      <div class='yotpo-row__control'>
        <input type='checkbox' role='switch' class='yotpo-switch' id='$id' name='$name' value='1' $checked />
      </div>
    </div>
  ";
}

/**
 * A setting with a select box: label and help text on the left, the select on the right.
 *
 * @param string $options The <option> tags.
 */
function yotpo_select_row($id, $name, $class, $label, $options, $help = null, $row_class = '') {
  return "
    <div class='yotpo-row $row_class'>
      <div class='yotpo-row__text'>
        <label class='yotpo-label' for='$id'>" . $label . "</label>
        " . ($help ? "<p class='yotpo-help'>" . $help . "</p>" : '') . "
      </div>
      <div class='yotpo-row__control'>
        <select name='$name' id='$id' class='yotpo-select $class'>$options</select>
      </div>
    </div>
  ";
}

/**
 * A text setting, with the label above the field.
 *
 * @param string $type 'text', or 'password' to mask a saved secret.
 */
function yotpo_text_field($id, $name, $value, $label, $help = null, $input_attrs = '', $row_class = '', $input_class = '', $type = 'text') {
  return "
    <div class='yotpo-field $row_class'>
      <label class='yotpo-label' for='$id'>" . $label . "</label>
      <input type='$type' class='yotpo-input $input_class' id='$id' name='$name' value='" . esc_attr($value) . "' $input_attrs />
      " . ($help ? "<p class='yotpo-help'>" . $help . "</p>" : '') . "
    </div>
  ";
}

function yotpo_card($title, $description, $content, $attrs = '', $header_extra = '') {
  return "
    <section class='yotpo-card' $attrs>
      <div class='yotpo-card__header'>
        <div>
          <h2 class='yotpo-card__title'>" . $title . "</h2>
          " . ($description ? "<p class='yotpo-card__description'>" . $description . "</p>" : '') . "
        </div>
        $header_extra
      </div>
      <div class='yotpo-card__content'>$content</div>
    </section>
  ";
}

/**
 * Logo, page title and a line under it. Notices WordPress shows on this page land under it (wp-header-end).
 */
function yotpo_page_header($subtitle) {
  $logo = plugins_url('assets/images/yotpo-menu-icon.svg', dirname(__DIR__, 2) . '/wc_yotpo.php');
  return "
    <div class='yotpo-header'>
      <img class='yotpo-header__logo' src='" . esc_url($logo) . "' alt='' width='40' height='40' />
      <div class='yotpo-header__text'>
        <h1 class='yotpo-header__title'>Yotpo Product Reviews</h1>
        <p class='yotpo-header__subtitle'>" . $subtitle . "</p>
      </div>
      <a class='yotpo-btn yotpo-btn--outline' href='https://reviews.yotpo.com/' target='_blank' rel='noopener'>Yotpo dashboard &#8599;</a>
    </div>
    <hr class='wp-header-end' />
  ";
}
