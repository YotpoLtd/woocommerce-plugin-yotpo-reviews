# Yotpo Product Reviews for WooCommerce

Plugin for WordPress sites with WooCommerce installed. Published on WordPress.org as https://wordpress.org/plugins/yotpo-social-reviews-for-woocommerce/ (the slug is permanent).

Installation guide - https://support.yotpo.com/docs/woocommerce-installing-yotpo

# Releasing a new version to WordPress.org

GitHub `master` is the source of truth. Only `trunk/` is shipped.

1. **Release PR on GitHub.** In one PR, update:
   - `trunk/wc_yotpo.php`: `Version` in the plugin header
   - `trunk/readme.txt`: `Stable tag`, `Tested up to`, the `== Changelog ==` entry and the `== Upgrade Notice ==` entry
   - `changelog` (repo root)

   Get it reviewed and merged, then pull `master`.

2. **Check out SVN** (once; afterwards `svn update`):

        svn checkout https://plugins.svn.wordpress.org/yotpo-social-reviews-for-woocommerce/ yotpo-svn

3. **Mirror `trunk/` into SVN trunk.** `--delete` removes files that were deleted on GitHub; skipping this is how deleted files (e.g. `assets/js/headerScript.js`) stayed on WordPress.org:

        rsync -av --delete --exclude=.svn <path-to-this-repo>/trunk/ yotpo-svn/trunk/

4. **Register new and deleted files with SVN:**

        cd yotpo-svn
        svn status | awk '/^\?/ {print $2}' | xargs svn add
        svn status | awk '/^!/ {print $2}' | xargs svn rm

5. **Create the version tag.** `Stable tag` in the readme must point to an existing `tags/<version>` folder, otherwise WordPress.org serves `trunk` (which is what happened for every version up to 1.8.2):

        svn cp trunk tags/<version>

6. **Review, then commit** (`svn status` and `svn diff` first):

        svn ci -m "Version <version>" --username Yotpo

   Credentials: [How to Deploy a new version](https://yotpoent.atlassian.net/wiki/spaces/RD/pages/1098844645/How+to+Deploy+a+new+version)

7. **Verify** the plugin page on WordPress.org shows the new version, and that https://plugins.svn.wordpress.org/yotpo-social-reviews-for-woocommerce/tags/ lists it.

Listing images (icon, banner, screenshots) live in `assets/` at the repo root and go to the SVN `assets/` folder, not `trunk/`. They update the listing immediately for every version.


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
