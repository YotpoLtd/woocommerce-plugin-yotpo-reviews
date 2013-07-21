<?php
function wc_display_yotpo_admin_page() {

	if ( function_exists('current_user_can') && !current_user_can('manage_options') ) {
		die(__(''));
	}
	if(wc_yotpo_compatible()) {			
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
		elseif (isset($_POST['yotpo_export_reviews'])) {
			$yotpo_settings = get_option('yotpo_settings', wc_yotpo_get_degault_settings());
			if(!empty($yotpo_settings['app_key'])) {					
				include(dirname(plugin_dir_path( __FILE__ )) . '/classes/class-wc-yotpo-export-reviews.php');
				$export = new Yotpo_Review_Export();
				list($file, $errors) = $export->exportReviews();
				if(is_null($errors)) {
					$errors = $export->downloadReviewToBrowser($file);
					if(!is_null($errors)) {
						wc_yotpo_display_message($errors);
					}	
				}
				else {
					wc_yotpo_display_message($errors);
				}	
			}
			else {
				wc_yotpo_display_message('Please set up your API key before exporting reviews.');	
			}	
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
	else {
		if(version_compare(phpversion(), '5.2.0') < 0) {
			echo '<h1>Yotpo plugin requires PHP 5.2.0 above.</h1><br>';	
		}	
		if(!function_exists('curl_init')) {
			echo '<h1>Yotpo plugin requires cURL library.</h1><br>';
		}			
	}
}

function wc_display_yotpo_settings() {
	$yotpo_settings = get_option('yotpo_settings', wc_yotpo_get_degault_settings());
	$app_key = $yotpo_settings['app_key'];
	$secret = $yotpo_settings['secret'];
	$language_code = $yotpo_settings['language_code'];
	$widget_tab_name = $yotpo_settings['widget_tab_name'];

	if(empty($yotpo_settings['app_key'])) {
		wc_yotpo_display_message('Set your API key in order the Yotpo plugin to work correctly', false);			
	}
	$google_tracking_params = '&utm_source=yotpo_plugin_woocommerce&utm_medium=header_link&utm_campaign=woocommerce_customize_link';
	if (!empty($yotpo_settings['app_key']) && !empty($yotpo_settings['secret'])) {
		$dashboard_link = '<a href="https://api.yotpo.com/users/b2blogin?app_key='.$yotpo_settings['app_key'].'&secret='.$yotpo_settings['secret'].$google_tracking_params.'" target="_blank">Yotpo Dashboard.</a></div>';
	}
	else {
		$dashboard_link = "<a href='https://www.yotpo.com/?login=true$google_tracking_params' target='_blank'>Yotpo Dashboard.</a></div>";
	}	
	$read_only = isset($_POST['log_in_button']) ? '' : 'readonly';
	$cradentials_location_explanation = isset($_POST['log_in_button']) 	? "<tr valign='top'>  	
		             														<th scope='row'><p class='description'>To get your api key and secret token <a href='https://www.yotpo.com/?login=true' target='_blank'>log in here</a> and go to your account settings.</p></th>
	                 		                  							   </tr>" : '';		
	$submit_past_orders_button = $yotpo_settings['show_submit_past_orders'] ? "<input type='submit' name='yotpo_past_orders' value='Submit past orders' class='button-secondary past-orders-btn' ".disabled(true,empty($app_key) || empty($secret), false).">" : '';
	
	$settings_html =  
		"<div class='wrap'>"			
		   .screen_icon( ).
		   "<h2>Yotpo Settings</h2>						  
			  <h4>To customize the look and feel of the widget, and to edit your Mail After Purchase settings, just head to the ".$dashboard_link."</h4>
			  <form  method='post' id='yotpo_settings_form'>
			  	<table class='form-table'>".
			  		wp_nonce_field('yotpo_settings_form').
			  	  "<fieldset>
	                 <tr valign='top'>
	                 	<th scope='row'><div>If you would like to choose a set language, please type the 2-letter language code here. You can find the supported langauge codes <a class='y-href' href='http://support.yotpo.com/entries/21861473-Languages-Customization-' target='_blank'>here.</a></div></th>
	                 	<td><div><input type='text' class='yotpo_language_code_text' name='yotpo_widget_language_code' maxlength='2' value='$language_code'/></div></td>
	                 </tr>
			  	     <tr valign='top'>  	
		             	<th scope='row'><div>For multipule-language sites, mark this check box. This will choose the language according to the user's site language.</div></th>
	                 	<td><input type='checkbox' name='yotpo_language_as_site' value='1' ".checked(1, $yotpo_settings['yotpo_language_as_site'], false)."/></td>	                  
	                 </tr>
					 <tr valign='top'>
		   		       <th scope='row'><div>Disable native reviews system:</div></th>
		   		       <td><input type='checkbox' name='disable_native_review_system' value='1' ".checked(1, $yotpo_settings['disable_native_review_system'], false)." /></td>
		   		     </tr>	                 	                 
	    	         <tr valign='top'>			
				       <th scope='row'><div>Select widget location</div></th>
				       <td>
				         <select name='yotpo_widget_location' class='yotpo-widget-location'>
				  	       <option value='footer' ".selected('footer',$yotpo_settings['widget_location'], false).">Page footer</option>
			 		       <option value='tab' ".selected('tab',$yotpo_settings['widget_location'], false).">Tab</option>
			 	           <option value='other' ".selected('other',$yotpo_settings['widget_location'], false).">Other (click update to see instructions)</option>
				         </select>
		   		       </td>
		   		     </tr>
		   		     <tr valign='top' class='yotpo-widget-location-other-explain'>
                 		<th scope='row'><p class='description'>In order to locate the widget in a custome location open 'wp-content/plugins/woocommerce/templates/content-single-product.php' and add the following line <code>wc_yotpo_show_widget();</code> in the requested location.</p></th>	                 																	
	                 </tr>
		   		     <tr valign='top' class='yotpo-widget-tab-name'>
		   		       <th scope='row'><div>Select tab name:</div></th>
		   		       <td><div><input type='text' name='yotpo_widget_tab_name' value='$widget_tab_name' /></div></td>
		   		     </tr>
		   		     $cradentials_location_explanation
					 <tr valign='top'>
		   		       <th scope='row'><div>App key:</div></th>
		   		       <td><div class='y-input'><input id='app_key' type='text' name='yotpo_app_key' value='$app_key' $read_only '/></div></td>
		   		     </tr>
					 <tr valign='top'>
		   		       <th scope='row'><div>Secret token:</div></th>
		   		       <td><div class='y-input'><input id='secret' type='text'  name='yotpo_oauth_token' value='$secret' $read_only '/></div></td>
		   		     </tr>	
		   		     <tr valign='top'>
		   		       <th scope='row'><p class='description'>Yotpo's Bottom Line shows the star rating of the product and the number of reviews for the product. <a href='http://support.yotpo.com/entries/24467793-What-is-the-Yotpo-Bottomline-' target='_blank'>learn more.</a></p></th>		   		       
		   		     </tr>				 	 
					 <tr valign='top'>
		   		       <th scope='row'><div>Enable bottom line in product page:</div></th>
		   		       <td><input type='checkbox' name='yotpo_bottom_line_enabled_product' value='1' ".checked(1, $yotpo_settings['bottom_line_enabled_product'], false)." /></td>
		   		     </tr>					  	 
					 <tr valign='top'>
		   		       <th scope='row'><div>Enable bottom line in category page:</div></th>
		   		       <td><input type='checkbox' name='yotpo_bottom_line_enabled_category' value='1' ".checked(1, $yotpo_settings['bottom_line_enabled_category'], false)." />		   		       
		   		       </td>
		   		     </tr>					 	 
		           </fieldset>
		         </table></br>			  		
		         <div class='buttons-container'>
		        <input type='submit' name='yotpo_export_reviews' value='Export Reviews' class='button-secondary' ".disabled(true,empty($app_key) || empty($secret), false)."/>
				<input type='submit' name='yotpo_settings' value='Update' class='button-primary' id='save_yotpo_settings'/>$submit_past_orders_button
			  </br></br><p class='description'>*Learn <a href='http://support.yotpo.com/entries/24454261-Exporting-reviews-for-Woocommerce' target='_blank'>how to export your existing reviews</a> into Yotpo.</p>
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
						 'disable_native_review_system' => isset($_POST['disable_native_review_system']) ? true : false,
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
		<form method='post'>
		<table class='form-table'>"
		   .wp_nonce_field('yotpo_registration_form').		   
		  "<fieldset>
			  <h2 class='y-register-title'>Fill out the form below and click register to get started with Yotpo.</h2></br></br>    
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
		  <div>Already registered to Yotpo?<input type='submit' name='log_in_button' value='click here' class='button-secondary not-user-btn' /></div>
		</form></br><p class='description'>*Learn <a href='http://support.yotpo.com/entries/24454261-Exporting-reviews-for-Woocommerce'>how to export your existing reviews</a> into Yotpo.</p></br></br>
		<div class='yotpo-terms'>By registering I accept the <a href='https://www.yotpo.com/terms-of-service' target='_blank'>Terms of Use</a> and recognize that a 'Powered by Yotpo' link will appear on the bottom of my Yotpo widget.</div>
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
		$yotpo_api = new Yotpo();
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
        	$response = $yotpo_api->create_user($user, true);        	
        	if(!empty($response['status']) && !empty($response['status']['code'])) {
        		if($response['status']['code'] == 200) {
        			$app_key = $response['response']['app_key'];
        			$secret = $response['response']['secret'];
        			$yotpo_api->set_app_key($app_key);
        			$yotpo_api->set_secret($secret);
        			$shop_domain = parse_url($shop_url,PHP_URL_HOST);
        			$account_platform_response = $yotpo_api->create_account_platform(array( 'shop_domain' => wc_yotpo_get_shop_domain(),
        																		   			'utoken' => $response['response']['token'],
        																					'platform_type_id' => 12));
        			if(!empty($response['status']) && !empty($response['status']['code']) && $response['status']['code'] == 200) {
        				$current_settings = get_option('yotpo_settings', wc_yotpo_get_degault_settings());
        				$current_settings['app_key'] = $app_key;
        				$current_settings['secret'] = $secret;
						update_option('yotpo_settings', $current_settings);							
						return true;			  						
        			}
        			elseif($response['status']['code'] >= 400){
        				if(!empty($response['status']['message'])) {
        					wc_yotpo_display_message($response['status']['message'], true);
        				}
        			}
        		}
        		elseif($response['status']['code'] >= 400){
        			if(!empty($response['status']['message'])) { 
        				if(!empty($response['status']['message']['email'])) {
        					if(is_array($response['status']['message']['email'])) {
        						wc_yotpo_display_message($response['status']['message']['email'][0], false);
        					}
        					else {
        						wc_yotpo_display_message($response['status']['message']['email'], false);
        					}        					
        				}   
        				else {
        					wc_yotpo_display_message($response['status']['message'], true);	
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