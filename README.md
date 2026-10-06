# Yotpo: Product & Photo Reviews for WooCommerce

Plugin for Wordpress sites which have WooCommerce shop plugin installed in them.

Installation guide - https://support.yotpo.com/docs/woocommerce-installing-yotpo

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
`svn ci -m "Version '<<new version number>>'" --username Yotpo --password ` [use password from this page](https://yotpoent.atlassian.net/wiki/spaces/RD/pages/1098844645/How+to+Deploy+a+new+version)

7. Thats it! check changes on our plugin page in Wordprass.

8. Push your branch to master in github.


(info) More details on how to use svn you can find here


# Development

Only `trunk/` is shipped to WordPress.org. Everything at the repo root (`composer.json`, `phpcs.xml.dist`, `bin/`, `test-data/`) is development tooling.

Install the tools once (requires PHP 7.4+ and Composer):

    composer install

Run these before opening a PR:

| Check | Command | What it catches |
|---|---|---|
| PHP lint | `composer lint` | Syntax errors and deprecations. Set `PHP_BINARIES` to test several PHP versions, e.g. `PHP_BINARIES="php7.4 php8.4" composer lint` |
| PHPCS | `composer phpcs` | Security (escaping, nonces, sanitization), PHP 7.4+ compatibility, unprefixed globals. Rules are in `phpcs.xml.dist` |
| Plugin Check | `wp plugin check yotpo-social-reviews-for-woocommerce` | WordPress.org directory requirements (readme headers, direct file access, etc.). Run inside a WordPress install with the [Plugin Check](https://wordpress.org/plugins/plugin-check/) plugin active |

Note: `phpcs.xml.dist`'s PHP/WordPress version floor is the 1.8.3 tooling target, not the plugin's published minimum (see the comment above those `<config>` entries) — it's expected to diverge from `trunk/readme.txt` until 1.8.3 ships.

`test-data/yotpo-test-products.csv` is a WooCommerce product import (Products → Import) with edge cases for testing the widgets: names with `&` and quotes, a product with reviews disabled, a variable product, a product without an image, and SKU/UPC/MPN/ISBN attributes.


---
https://www.yotpo.com/

Copyright © 2018 Yotpo. All rights reserved.  

![Yotpo Logo](https://yap.yotpo.com/assets/images/logo_login.png)
