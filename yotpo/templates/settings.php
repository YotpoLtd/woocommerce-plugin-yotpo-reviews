<?php
function wc_display_yotpo_admin_page() {
	if ( function_exists('current_user_can') && !current_user_can('manage_options') ) {
		die(__(''));
	}
	if (isset($_POST['log_in_button']) ) {
		wc_display_yotpo_settings();
	}
	elseif (isset($_POST['yotpo_settings'])) {
		check_admin_referer( 'yotpo_settings_form' );
		wc_proccess_yotpo_settings();
		wc_display_yotpo_settings();
	}
	elseif (isset($_POST['yotpo_register'])) {
		check_admin_referer( 'yotpo_registration_form' );
		$success = wc_proccess_yotpo_register();
		if($success) {			
			wc_display_yotpo_settings();
		}
		else {
			wc_display_yotpo_register();	
		}
		
	}
	elseif (isset($_POST['yotpo_past_orders'])) {
		wc_yotpo_send_past_orders();	
		wc_display_yotpo_settings();
	}	
	else {
		$yotpo_settings = get_option('yotpo_settings', wc_yotpo_get_degault_settings());
		if(empty($yotpo_settings['app_key']) && empty($yotpo_settings['secret'])) {			
			wc_display_yotpo_register();
		}
		else {
			wc_display_yotpo_settings();
		}
	}
}

function wc_display_yotpo_settings() {
	$yotpo_settings = get_option('yotpo_settings', wc_yotpo_get_degault_settings());
	$app_key = $yotpo_settings['app_key'];
	$secret = $yotpo_settings['secret'];
	$widget_location = $yotpo_settings['widget_location'];
	$language_code = $yotpo_settings['language_code'];
	$widget_tab_name = $yotpo_settings['widget_tab_name'];
	$bottom_line_enabled_category = $yotpo_settings['bottom_line_enabled_category'];
	$bottom_line_enabled_product = $yotpo_settings['bottom_line_enabled_product'];
	$yotpo_language_as_site = $yotpo_settings['yotpo_language_as_site'];
	$show_submit_past_orders = $yotpo_settings['show_submit_past_orders']; 
	$yotpo_language_as_site_checkbox = $yotpo_language_as_site ? "checked='checked'" : '';
	$bottom_line_enabled_product_checkbox = $bottom_line_enabled_product ? "checked='checked'" : '';
	$bottom_line_enabled_category_checkbox = $bottom_line_enabled_category ? "checked='checked'" : '';
	
	$widget_location_footer = $widget_location == 'footer' ? 'selected' : '';
	$widget_location_tab = $widget_location == 'tab' ? 'selected' : '';
	$widget_location_other = $widget_location == 'other' ? 'selected' : '';
	
	if(empty($yotpo_settings['app_key'])) {
		wc_yotpo_display_message('Set your API key in order the Yotpo plugin to work correctly', false);	
	}
	$settings_html =  
		"<div class='wrap'>"			
		   .screen_icon( ).
		   "<h2>Yotpo Settings</h2>			
			  <form  method='post'>
			  	<table class='form-table'>".
			  		wp_nonce_field('yotpo_settings_form').
			  	  "<fieldset>
			  	     <tr valign='top'>  	
		             <th scope='row'><div class='y-label'>For multipule-language sites, mark this check box. This will choose the language according to the user's site language</div></th>
	                 <td><input type='checkbox' name='yotpo_language_as_site' value='1' $yotpo_language_as_site_checkbox /></td>	                  
	                 </tr>
	                 <tr valign='top'>
	                 	<th scope='row'><div>If you would like to choose a set language, please type the 2-letter language code here. You can find the supported langauge codes <a class='y-href' href='http://support.yotpo.com/entries/21861473-Languages-Customization-' target='_blank'>here.</a></div></th>
	                 	<td><div><input type='text' class='yotpo_language_code_text' name='yotpo_widget_language_code' maxlength='2' value='$language_code'/></div></td>
	                 </tr>
	    	         <tr valign='top'>			
				       <th scope='row'><div>Select widget location</div></th>
				       <td>
				         <select name='yotpo_widget_location' class='yotpo-widget-location'>
				  	       <option value='footer' $widget_location_footer>Page footer</option>
			 		       <option value='tab' $widget_location_tab>Tab</option>
			 	           <option value='other' $widget_location_other>Other (click update to see instructions)</option>
				         </select>
		   		       </td>
		   		     </tr>
		   		     <tr valign='top'>
		   		       <th scope='row'><div>Select tab name:</div></th>
		   		       <td><div class='y-input'><input type='text' name='yotpo_widget_tab_name' value='$widget_tab_name' /></div></td>
		   		     </tr>
					 <tr valign='top'>
		   		       <th scope='row'><div>App key:</div></th>
		   		       <td><div class='y-input'><input type='text' name='yotpo_app_key' value='$app_key'/></div></td>
		   		     </tr>
					 <tr valign='top'>
		   		       <th scope='row'><div>Secret token:</div></th>
		   		       <td><div class='y-input'><input type='text'  name='yotpo_oauth_token' value='$secret'/></div></td>
		   		     </tr>				 	 
					 <tr valign='top'>
		   		       <th scope='row'><div>Enable bottom line in product page:</div></th>
		   		       <td><input type='checkbox' name='yotpo_bottom_line_enabled_product' value='1' $bottom_line_enabled_product_checkbox /></td>
		   		     </tr>					  	 
					 <tr valign='top'>
		   		       <th scope='row'><div class='y-label'>Enable bottom line in category page:</div></th>
		   		       <td><input type='checkbox' name='yotpo_bottom_line_enabled_category' value='1' $bottom_line_enabled_category_checkbox /></td>
		   		     </tr>					 	 
		           </fieldset>
		         </table></br>			  		
		         <div class='buttons-container'>
				<input type='submit' name='yotpo_settings' value='Update' class='button-primary' id='save_yotpo_settings'/>";
				if($show_submit_past_orders) {
					$settings_html .="<input type='submit' name='yotpo_past_orders' value='Submit past orders' class='button-secondary past-orders-btn'>";
				}
			$settings_html .="
			</div>
		  </form>
		</div>";		

	echo $settings_html;		  
}

function wc_proccess_yotpo_settings() {
	$current_settings = get_option('yotpo_settings', wc_yotpo_get_degault_settings());
	$new_settings = array('app_key' => $_POST['yotpo_app_key'],
						 'secret' => $_POST['yotpo_oauth_token'],
						 'widget_location' => $_POST['yotpo_widget_location'],
						 'language_code' => $_POST['yotpo_widget_language_code'],
						 'widget_tab_name' => $_POST['yotpo_widget_tab_name'],
						 'bottom_line_enabled_product' => isset($_POST['yotpo_bottom_line_enabled_product']) ? true : false,
						 'bottom_line_enabled_category' => isset($_POST['yotpo_bottom_line_enabled_category']) ? true : false,
						 'yotpo_language_as_site' => isset($_POST['yotpo_language_as_site']) ? true : false,
						 'show_submit_past_orders' => $current_settings['show_submit_past_orders']);
	update_option( 'yotpo_settings', $new_settings );
}

function wc_display_yotpo_register() {	
	$email = isset($_POST['yotpo_user_email']) ? $_POST['yotpo_user_email'] : '';
	$user_name = isset($_POST['yotpo_user_name']) ? $_POST['yotpo_user_name'] : '';
	$register_html = 
	"<div class='wrap'>"			
		   .screen_icon().
		   "<h2>Yotpo Registration</h2>
	  <div class='y-wrapper'>
	  <div class='y-white-box'>
		<form method='post'>
		<table class='form-table'>"
		   .wp_nonce_field('yotpo_registration_form').		   
		  "<h2><i></i>Create your Yotpo account</h2>
			<fieldset>
			  <h2>Generate more reviews, more engagement, and more sales.</h2>    
			  <tr valign='top'>
			    <th scope='row'><div>Email address:</div></th>			 			  
			    <td><div><input type='text' name='yotpo_user_email' value='$email' /></div></td>
			  </tr>
			  <tr valign='top'>
			    <th scope='row'><div>Name:</div></th>			 			  
			    <td><div><input type='text' name='yotpo_user_name' value='$user_name' /></div></td>
			  </tr>
			  <tr valign='top'>
			    <th scope='row'><div>Password:</div></th>			 			  
			    <td><div><input type='password' name='yotpo_user_password' /></div></td>
			  </tr>
			  <tr valign='top'>
			    <th scope='row'><div>Confirm password:</div></th>			 			  
			    <td><div><input type='password' name='yotpo_user_confirm_password' /></div></td>
			  </tr>
			  <tr valign='top'>
			    <th scope='row'></th>
			    <td><div><input type='submit' name='yotpo_register' value='Register' class='button-primary submit-btn' /></div></td>
			  </tr>			  
			</fieldset>			
			<table/>
		</form>
		<form method='post'>
		  <div>Already using Yotpo?<input type='submit' name='log_in_button' value='click here' class='button-secondary not-user-btn' /></div>
		</form>
	 </div>
  </div>
  </div>";
  echo $register_html;		 
}

function wc_proccess_yotpo_register() {
	$errors = array();
	if ($_POST['yotpo_user_email'] === '') {
		array_push($errors, 'Provide valid email address');
	}		
	if (strlen($_POST['yotpo_user_password']) < 6 || strlen($_POST['yotpo_user_password']) > 128) {
		array_push($errors, 'Password must be at least 6 characters');
	}			
	if ($_POST['yotpo_user_password'] != $_POST['yotpo_user_confirm_password']) {
		array_push($errors, 'Passwords are not identical');			
	}
	if ($_POST['yotpo_user_name'] === '') {
		array_push($errors, 'Name is missing');
	}		
	if(count($errors) == 0) {		
		$yotpo_api = new \Yotpo\Yotpo();
		$shop_url = get_bloginfo('url');		    
        $user = array(
            'email' => $_POST['yotpo_user_email'],
            'display_name' => $_POST['yotpo_user_name'],
        	'first_name' => '',
            'password' => $_POST['yotpo_user_password'],
            'last_name' => '',
            'website_name' => $shop_url,
            'support_url' => $shop_url,
            'callback_url' => $shop_url,
            'url' => $shop_url);
        try {        	        	
        	$response = $yotpo_api->create_user($user);        	
        	if(isset($response->status) && isset($response->status->code)) {
        		if($response->status->code == 200) {
        			$app_key = $response->response->app_key;
        			$secret = $response->response->secret;
        			$yotpo_api->set_app_key($app_key);
        			$yotpo_api->set_secret($secret);
        			$shop_domain = parse_url($shop_url,PHP_URL_HOST);
        			$account_platform_response = $yotpo_api->create_account_platform(array( 'shop_domain' => wc_yotpo_get_shop_domain(),
        																		   			'utoken' => $response->response->token,
        																					'platform_type_id' => 8));
        			if(isset($response->status) && isset($response->status->code) && $response->status->code == 200) {
        				$current_settings = get_option('yotpo_settings', wc_yotpo_get_degault_settings());
        				$current_settings['app_key'] = $app_key;
        				$current_settings['secret'] = $secret;
						update_option('yotpo_settings', $current_settings);							
						return true;			  						
        			}
        			elseif($response->status->code >= 400){
        				if(isset($response->status->message)) {
        					wc_yotpo_display_message($response->status->message, true);
        				}
        			}
        		}
        		elseif($response->status->code >= 400){
        			if(isset($response->status->message)) { 
        				if(isset($response->status->message->email)) {
        					if(is_array($response->status->message->email)) {
        						wc_yotpo_display_message($response->status->message->email[0], false);
        					}
        					else {
        						wc_yotpo_display_message($response->status->message->email, false);
        					}        					
        				}   
        				else {
        					wc_yotpo_display_message($response->status->message, true);	
        				}    				
        					        						
        			}
        		}
        	}
        	else {
        		
        	}
        }
        catch (Exception $e) {
        	wc_yotpo_display_message($e->getMessage(), true);	
        }         		
	}
	else {
		wc_yotpo_display_message($errors, false);	
	}	
	return false;		
}

function wc_yotpo_display_message($messages = array(), $is_error = false) {
	$class = $is_error ? 'error' : 'updated fade';
	if(is_array($messages)) {
		foreach ($messages as $message) {
			echo "<div id='message' class='$class'><p><strong>$message</strong></p></div>";
		}
	}
	elseif(is_string($messages)) {
		echo "<div id='message' class='$class'><p><strong>$messages</strong></p></div>";
	}
}