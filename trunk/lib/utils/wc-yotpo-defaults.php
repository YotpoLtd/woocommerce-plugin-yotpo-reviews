<?php

function wc_yotpo_get_default_settings() {
  return array('app_key' => '',
    'secret' => '',
    'v2_widget_location' => 'footer',
    'v3_widget_location' => 'automatic',
    'language_code' => 'en',
    'main_widget_tab_name' => 'Reviews',
    'qna_widget_tab_name' => 'Q&A',
    'widget_version' => 'v3',
    'v3_widgets_ids' => array(
      'reviews_widget' => '',
      'qna' => '',
      'star_rating' => '',
      'reviews_carousel' => '',
      'promoted_products' => '',
      'reviews_tab' => '',
    ),
    'v3_widgets_enables' => array(
      'reviews_widget_product' => true,
      'qna_product' => true,
      'star_rating_product' => true,
      'star_rating_category' => true,
      'reviews_carousel_product' => true,
      'reviews_carousel_category' => true,
      'reviews_carousel_home' => true,
      'promoted_products_product' => true,
      'promoted_products_category' => true,
      'promoted_products_home' => true,
      'reviews_tab_product' => true,
      'reviews_tab_category' => true,
    ),
    'v2_widgets_enables' => array(
      'qna_product' => false,
      'bottom_line_product' => true,
      'bottom_line_category' => false,
    ),
    'yotpo_language_as_site' => true,
    'show_submit_past_orders' => true,
    'yotpo_order_status' => 'wc-completed',
    'disable_native_review_system' => true,
    'native_star_ratings_enabled' => 'no',
    'debug_mode' => false
  );
}

function fatal_error_handler($buffer){
  $error=error_get_last();
  if(!is_null($error) && $error['type'] == 1){
      $newBuffer='<html><header><title>Fatal Error </title></header>
                  <style>                 
                  .error_content{                     
                      background: ghostwhite;
                      vertical-align: middle;
                      margin:0 auto;
                      padding:10px;
                      width:50%;                              
                   } 
                   .error_content label{color: red;font-family: "Ubuntu Mono", Consolas, monospace;font-size: 16pt;font-style: italic;}
                   .error_content ul li{ background: none repeat scroll 0 0 FloralWhite;                   
                              border: 1px solid AliceBlue;
                              display: block;
                              font-family: "Ubuntu Mono", Consolas, monospace;
                              padding: 2%;
                              text-align: left;
                    }
                  </style>
                  <body style="text-align: center;">  
                    <div class="error_content">
                        <label >Fatal Error </label>
                        <ul>
                          <li><b>Line</b> '.$error['line'].'</li>
                          <li><b>Message</b> '.$error['message'].'</li>
                          <li><b>File</b> '.$error['file'].'</li>                             
                        </ul>
                        <a href="javascript:history.back()"> Back </a>                          
                    </div>
                  </body></html>';
      return $newBuffer;
  }
  return $buffer;
}
