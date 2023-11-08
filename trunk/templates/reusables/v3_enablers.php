<?php

function v3_enabler($isChecked, $name, $text, $subtext = null) {
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
