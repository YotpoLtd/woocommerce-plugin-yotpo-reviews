# Yotpo Product Reviews for WooCommerce

Plugin for WordPress sites with WooCommerce installed. Published on WordPress.org as https://wordpress.org/plugins/yotpo-social-reviews-for-woocommerce/ (the slug is permanent).

Installation guide - https://support.yotpo.com/docs/woocommerce-installing-yotpo

# Releasing a new version to WordPress.org

GitHub `master` is the source of truth. The plugin code that ships is `trunk/`; the WordPress.org listing images in `assets/` are synced separately (step 4).

1. **Release PR on GitHub.** In one PR, update:
   - `trunk/wc_yotpo.php`: `Version` in the plugin header
   - `trunk/readme.txt`: `Stable tag`, `Tested up to`, the `== Changelog ==` entry and the `== Upgrade Notice ==` entry
   - `changelog` (repo root)

   Get it reviewed and merged, then pull `master`.

2. **Check out SVN** (once; afterwards `svn update`):

        svn checkout https://plugins.svn.wordpress.org/yotpo-social-reviews-for-woocommerce/ yotpo-svn

3. **Mirror `trunk/` into SVN trunk.** `--delete` removes files that were deleted on GitHub; skipping this is how deleted files (e.g. `assets/js/headerScript.js`) stayed on WordPress.org:

        rsync -av --delete --exclude=.svn <path-to-this-repo>/trunk/ yotpo-svn/trunk/

4. **Mirror the listing images into SVN `assets/`.** WordPress.org reads the icon (`icon.svg`, `icon-256x256.png`, `icon-128x128.png`), banners (`banner-772x250`, `banner-1544x500`) and screenshots from SVN `assets/`, not from `trunk/`. Keep them in `assets/` in this repo; `--delete` removes images deleted here:

        rsync -av --delete --exclude=.svn <path-to-this-repo>/assets/ yotpo-svn/assets/

   Listing images update the plugin page immediately for every version, not only for the new one.

5. **Register new and deleted files with SVN:**

        cd yotpo-svn
        svn add --force .
        svn status | sed -n 's/^!.\{7\}//p' | while IFS= read -r f; do svn rm --force "$f@"; done

   `svn add --force .` adds every new file; the loop removes every file that step 3 deleted (`!` entries) and is safe for empty input, paths with spaces and `@` in names. Run `svn status` afterwards: there must be no `?` or `!` entries left before you continue.

6. **Create the version tag.** `Stable tag` in the readme must point to an existing `tags/<version>` folder, otherwise WordPress.org serves `trunk` (which is what happened for every version up to 1.8.2):

        svn cp trunk tags/<version>

7. **Review, then commit** (`svn status` and `svn diff` first):

        svn ci -m "Version <version>" --username Yotpo

   Credentials: [How to Deploy a new version](https://yotpoent.atlassian.net/wiki/spaces/RD/pages/1098844645/How+to+Deploy+a+new+version)

8. **Verify** the plugin page on WordPress.org shows the new version, and that https://plugins.svn.wordpress.org/yotpo-social-reviews-for-woocommerce/tags/ lists it.


# Development

Only `trunk/` (plugin code) and the listing images (`assets/`) reach WordPress.org. Everything else at the repo root (`composer.json`, `phpcs.xml.dist`, `bin/`, `test-data/`) is development tooling.

Install the tools once (requires PHP 7.4+ and Composer):

    composer install

Run these before opening a PR:

| Check | Command | What it catches |
|---|---|---|
| PHP lint | `composer lint` | Syntax errors and deprecations. Set `PHP_BINARIES` to test several PHP versions, e.g. `PHP_BINARIES="php7.4 php8.4" composer lint` |
| PHPCS | `composer phpcs` | Security (escaping, nonces, sanitization), PHP 7.4+ compatibility, unprefixed globals. Rules are in `phpcs.xml.dist` |
| Plugin Check | `wp plugin check yotpo-social-reviews-for-woocommerce` | WordPress.org directory requirements (readme headers, direct file access, etc.). Run inside a WordPress install with the [Plugin Check](https://wordpress.org/plugins/plugin-check/) plugin active |

`test-data/yotpo-test-products.csv` is a WooCommerce product import (Products → Import) with edge cases for testing the widgets: names with `&` and quotes, a product with reviews disabled, a variable product, a product without an image, and SKU/UPC/MPN/ISBN attributes.


---
https://www.yotpo.com/

Copyright © 2018 Yotpo. All rights reserved.  

![Yotpo Logo](https://yap.yotpo.com/assets/images/logo_login.png)
