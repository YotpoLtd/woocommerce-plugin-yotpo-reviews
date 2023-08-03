# Yotpo: Product & Photo Reviews for WooCommerce

Plugin for Wordpress sites which have WooCommerce shop plugin installed in them.

Installation guide - https://support.yotpo.com/en/installing-yotpo/woocommerce

# Uploading a new version or changes to https://wordpress.org/plugins/yotpo-social-reviews-for-woocommerce/

1. Download the SVN directory from Wordpress:
svn checkout https://plugins.svn.wordpress.org/yotpo-social-reviews-for-woocommerce/

 Now this is your working copy of the plugin.

2. Pull the latest changes from github repository: woocommerce-plugin

3. Create a new branch, change the relevant files (changelog, wc_yotpo.php,readme.txt) 

4. Copy the code from  'woocommerce-yotpo' folder to to the working copy under 'trunk' folder. 

5. Verify that the following files are updated: 

        Changelog
        wc_yotpo.php: update the version in the header
        readme.txt:  the relevant fields: Stable tag, Tested up to, Requires
        readme.txt: the changelog section.

6. Add all changes and new files
svn add * --force


7. run 'svn diff' to verify the code changes before you upload the code
6. Push the changes:
svn ci -m 'Version `<<new version number>>`' --username Yotpo --password yotpo2010

7. Thats it! check changes on our plugin page in Wordprass.

8. Push your branch to master in github.


(info) More details on how to use svn you can find here


---
https://www.yotpo.com/

Copyright © 2018 Yotpo. All rights reserved.  

![Yotpo Logo](https://yap.yotpo.com/assets/images/logo_login.png)
