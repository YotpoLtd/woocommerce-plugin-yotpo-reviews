<?php

function v3_enabler($isChecked, string $name, string $text, string $subtext = null): string {
  $additional_info = $subtext
    ? "<p style='margin: unset;font-weight: normal;'>
      " . $subtext . "
      </p>"
    : "";
  return "
    <tr valign='top'>
      <th scope='row'>
        <div>" . $text . ":</div>
        " .
        $additional_info
        . "
      </th>
      <td><input type='checkbox' name='" . $name . "' value='1' " . $isChecked . " /></td>
    </tr>
  ";
}

function v3_multifield_enabler(array $locations, $text, $subtext = null): string {
  $additional_info = $subtext
    ? "<p style='margin: unset;font-weight: normal;'>
      " . $subtext . "
      </p>"
    : "";
    $formatted_locations = "";
    for ($i = 0; $i < sizeof($locations); $i++) {
      $formatted_locations .= get_multifield_enabler_field($locations[$i]);
    }
  return "
    <tr valign='top'>
      <th scope='row'>
        <div>" . $text . ":</div>
        " .
        $additional_info
        . "
      </th>
      <td>
        "
          .
          $formatted_locations
          .
        "
      </td>
    </tr>
  ";
}

function get_multifield_enabler_field($location): string {
  return
  "<span>" . $location['text'] . "</span>
  <input type='checkbox' name='" . $location['name'] . "' value='1' " . $location['checked'] . " />
  ";
}
