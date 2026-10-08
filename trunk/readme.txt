=== Yotpo Product Reviews ===
Contributors: Yotpo
Tags: reviews, social reviews, woocommerce, woocommerce reviews, woocommerce product reviews
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.8.3
License: GPLv2
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Collect and display product reviews and ratings to showcase social proof and build trust.

== Description ==

Yotpo product reviews and ratings drive sales and help you better understand your customers. Collect more trustworthy reviews, photos, and videos, with in-mail review requests and AI-powered prompts. Reach more shoppers by displaying your reviews and ratings on social media and search engines, like Google. Own all of your reviews and easily moderate them. Access detailed analytics to boost conversions. Plus, connect Yotpo Reviews with Loyalty.

* Collect product & site reviews on auto-pilot with email templates
* Display eye-catching reviews and use Smart Filters to build trust
* Stand out in search with Google Seller Ratings and Google Shoppable Ads
* Add beautiful, customizable review widgets (carousels, AI summaries, & more)
* Easily sync reviews to and from other platforms (TikTok, Walmart, & more)


== Installation ==

= Minimum Requirements =

* WordPress 6.0 or greater
* PHP version 7.4 or greater
* WooCommerce 3.0 or greater

1. Install Yotpo Product Reviews either via the WordPress.org plugin directory, or by uploading the files to your server
2. After activating Yotpo Product Reviews, click on the Yotpo link in the left hand side bar of your Wordpress admin.
3. You will now need to register an account with Yotpo or log in with your existing account.
4. Customize the different settings on the Yotpo Settings page and click update when done.
5. That's it.  You're ready to go!

For full installation guide, [please click here](https://support.yotpo.com/docs/woocommerce-installing-yotpo)

== Screenshots ==

1.
2.
3.
4.
5.
6.
7.
8.
9.
10.
11.
12.
13.

== Changelog ==

= 1.0.2 - 04/07/2013 =
* Initial Release.
= 1.0.3 - 16/07/2013 =
* Changes in text.
= 1.0.4 - 21/07/2013 =
* Replace yotpo.phar file with Yotpo.php, and change minimum requirements from PHP 5.3.0 to PHP 5.2.0 and above .
* Ensure that the minimum requirements are met.
= 1.0.5 - 31/07/2013 =
* Remove widget from products which are not checked with 'Enable reviews' checkbox.
* Fix bugs.
* Disable woocommerce ratings upon activation and restore value of woocommerce ratings when deactivating.

= 1.0.6 - 14/08/2013 =
* Add support to Tab manager extension.
* Bug fix in removing native review system.

= 1.0.7 - 19/01/2014 =
* Async load of js file.
* Internal changes.

= 1.0.8 - 25/02/2014 =
* Change language code length. 
* Mail After Purchase will be sent when order status is changing from a remote source.  

= 1.1.1 - 15/05/2014 =
* Bug fixes. 

= 1.1.2 - 21/05/2014 =
* Exporting reviews bug fix. 

= 1.1.3 - 02/06/2014 =
* This version includes the latest version of Yotpo's Reviews Widget. It features an updated, cleaner UI, faster performance and more customization options.

= 1.1.4 - 01/13/2015 =
* Added support for WooCommerce v2.2.x

= 1.1.5 - 11/10/2016 =
* Update conversion pixel

= 1.1.6 - 31/12/2017 =
* Added support for woocommerce up-to version 3.2.6, Wordpress up-to version 4.9.1 and PHP up-to 7.1.x.
* Fixed a bug with Woocommerce 3.1.0 and 3.1.1 affecting Yotpo's widget
* Added the ability to add and customize additional specs and unique identifiers per product (Brand, ISBN, MPN, UPC) .
* Added the ability to pull orders and send MAPs according to additional order statuses (other than "complete").
* Added bottom-line for category page.
* Fixed "review export" method on Yotpo's integration page.

= 1.1.7 - 13/02/2018 =
* Reverted default order status to 'Completed'

= 1.1.8 - 13/10/2018 =
* Added support for:
  * Wordpress Version 4.9.8
  * Woocommerce Version 3.5.0
  * Price & currency metadata in widget for Rich Snippets.
  * Custom order status into account when retrieving past orders.
  * Q&A bottom line in storefront and Yotpo settings.
  * Bug fixes.

= 1.1.9 - 27/06/2023 =
* Bug fix in fatal_error_handler method

= 1.5.0 - 26/07/2023 =
* Added v3 widgets support
  * Added v2/v3 selector
  * left possibility to use v2
  * added togglers for all 3 main widgets (reviews, q&a and star rating)

= 1.5.1 - 02/08/2023 =
* reapplied `wc_yotpo_show_widget` function for backward compatibility
  
= 1.6.1 - 22/08/2023 =
* Support CRUD objects in submit past order feature flow to support HPOS feature
* Flagged that HPOS is supported

= 1.6.2 - 22/08/2023 =
* fix broken submit past order feature flow

= 1.6.3 - 30/08/2023 =
* fixed import path of `bottom-line.css` file

= 1.6.4 - 31/08/2023 =
* added `esc_attr` function to all `$product_data` fields

= 1.6.5 - 4/09/2023 =
* Bug fix in fatal_error_handler method

= 1.6.6 - 05/09/2023 =
* created modal with warning about trial of usage not customizated v3 widgets

= 1.7.0 - 18/12/2023 =
* added carousel widget, promoted products widget and reviews tab widget

= 1.7.1 - 10/01/2024 =
* removed edge case bug - when `v3_widgets_enables` was undefined, error occured

= 1.7.2 - 10/01/2024 =
* Reviews Tab made enablable on product page and category page

= 1.7.3 - 26/01/2024 =
* removed exception throw which caused complete block of settings page

= 1.7.4 - 29/01/2024 =
* added checking if the key in $widgets_instances array exists in wc-yotpo-settings-functions.php

= 1.7.5 - 29/01/2024 =
* changed function name get_arr_value to yotpo_get_arr_value to eliminate the same function name as in another plugin

= 1.7.6 - 29/01/2024 =
* used yotpo_get_arr_value in wc_yotpo.php to eliminate warnings

= 1.7.7 - 05/02/2024 =
* used yotpo_get_arr_value in wc-yotpo-settings.php to eliminate warnings

= 1.7.8 - 27/02/2024 =
* added separate label for category page and home page for Carousel and Promoted Products and Out of Product Page for Reviews Tab

= 1.7.9 - 05/09/2024 =
* changed default settings of widget version to v3

= 1.7.10 - 14/11/2024 =
* fixed registration form inputs vulnerabilities

= 1.8.0 - 20/11/2024 =
* Added license header to wc_yotpo.php
* Fixed various errors detected with Plugin Check
* Improved filesystem operations by replacing direct PHP calls with WP_Filesystem
* Replaced cURL with wp_remote_* for HTTP requests
* Allowed product ID and star ratings in specific features
* Updated debug_log checker in settings
* Fixed badly working export reviews functionality
* Added missing functions, including generateReviewRow in export reviews
* Fixed escapes and updated allowed HTML elements for settings
* Improved handling of past orders and error messages when no orders found
* Removed deprecated force_ssl_login and adopted suggested methods

= 1.8.1 - 20/11/2024 =
* Removed hidden files

= 1.8.2 - 12/12/2024 =
* fixed function securing script and noscript content in wc_yotpo.php

= 1.8.3 - 08/10/2026 =
* Renamed to Yotpo Product Reviews and refreshed the plugin description
* Tested up to WordPress 7.1; the plugin is listed in WordPress.org search again
* Requires WordPress 6.0 and PHP 7.4, and declares WooCommerce as a required plugin
* Fixed a fatal error on every storefront page when WooCommerce is inactive; an admin notice is shown instead
* Plugin files can no longer be loaded directly from a browser
* Removed the unneeded cURL requirement
* Fixed PHP 8.2 and 8.4 deprecation warnings
* Security: TLS certificates are now verified on all Yotpo API calls
* Security: the Yotpo Dashboard link in the settings no longer auto-logs in; it opens the Yotpo login page, so the app secret is never placed in a URL
* Security: added nonce checks to Sync widget IDs, Submit past orders and Export reviews
* Security: the debug log moved to a private folder under wp-content/uploads/yotpo-social-reviews-for-woocommerce/ and is removed on uninstall
* Security: review export is streamed to the browser instead of being written to the plugin folder, and guards against spreadsheet formula injection
* Fixed the debug log viewer and Clear button
* Syncing or saving v3 widget IDs no longer clears the saved IDs when the Yotpo API cannot be reached
* Removed the global fatal error output handler; WordPress recovery mode handles fatal errors
* Fixed plugin activation failing ("The link you followed has expired") when activated in bulk or with WP-CLI
* Fixed all product tabs disappearing on products with reviews disabled when the v2 widget is shown in a tab
* Fixed the WooCommerce Tab Manager integration hiding non-Yotpo tabs
* Fixed the no-JavaScript conversion tracking pixel URL
* Exported reviews now include product permalinks instead of ?p=ID links
* Fixed wc_yotpo_show_custom_widgets() rendering only the last widget
* Stopped re-firing the woocommerce_init action for every order during order sync

== Upgrade Notice ==

= 1.8.3 =
Security and compatibility release: verifies TLS on Yotpo API calls, keeps customer data out of public files, supports WordPress 7.1 and PHP 8.4, and fixes a crash when WooCommerce is inactive. Recommended for all stores.
