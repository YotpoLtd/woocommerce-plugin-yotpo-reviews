<?php

defined( 'ABSPATH' ) || exit;

function use_v3_widgets() {
	$settings = get_option('yotpo_settings',wc_yotpo_get_default_settings());
	return $settings['widget_version'] === 'v3';
}

function wc_yotpo_admin_settings() {
	add_action( 'admin_enqueue_scripts', 'wc_yotpo_admin_styles' );
	add_action( 'admin_head', 'wc_yotpo_menu_icon_style' );
	$page = add_menu_page( 'Yotpo', 'Yotpo', 'manage_options', 'woocommerce-yotpo-settings-page', 'wc_display_yotpo_admin_page', 'none', null );
}

// Yotpo brand mark (assets/images/yotpo-menu-icon.svg: the brand file without its white square) as the admin menu icon.
// Painted with inline CSS rather than passed to add_menu_page(): WordPress recolors SVG menu icons to the
// admin gray, which drops the brand blue. Inline, so there is no stylesheet for browsers to cache.
function wc_yotpo_menu_icon_style() {
	$icon_url = plugins_url( 'assets/images/yotpo-menu-icon.svg', dirname( __DIR__, 2 ) . '/wc_yotpo.php' );
	echo '<style>#toplevel_page_woocommerce-yotpo-settings-page .wp-menu-image{background:url("' . esc_url( $icon_url ) . '") center / 20px 20px no-repeat;}</style>';
}

// Single idiom for "is WooCommerce active" used across the plugin, instead of each
// call site re-implementing its own class_exists()/defined() check.
function wc_yotpo_is_woocommerce_active() {
	return class_exists( 'WooCommerce' );
}

// After activation, an admin's next wp-admin page opens the Yotpo settings, once.
function wc_yotpo_redirect() {
	if ( ! get_option( 'wc_yotpo_just_installed', false ) || ! wc_yotpo_can_redirect_after_activation() ) {
		return;
	}
	delete_option( 'wc_yotpo_just_installed' );
	// wc_yotpo_init() never registers the settings page (admin_menu -> wc_yotpo_admin_settings)
	// when WooCommerce is inactive, so redirecting there would land on an unregistered
	// menu slug and produce an access-denied error instead of a no-op. After a bulk activation,
	// WordPress shows the plugins list, as for other plugins.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WordPress's own flag after a bulk activation, only checked for presence.
	if ( ! wc_yotpo_is_woocommerce_active() || isset( $_GET['activate-multi'] ) ) {
		return;
	}
	wp_safe_redirect( admin_url( 'admin.php?page=woocommerce-yotpo-settings-page' ) );
	exit;
}

// Registered on admin_init, which also fires for admin-ajax.php: only an admin's page load redirects.
function wc_yotpo_can_redirect_after_activation() {
	return ! wp_doing_ajax() && current_user_can( 'manage_options' );
}

/**
 * Yotpo's code for a language subtag (the part of a locale before the region: fr of fr_FR or fr-FR). It is the
 * subtag itself, except Norwegian: WordPress calls it Bokmål (nb) or Nynorsk (nn), Yotpo has one Norwegian,
 * "no", and shows English for "nb" and "nn". Used by the V3 loader and the V2 widgets alike.
 */
function wc_yotpo_language_code_for_subtag($subtag) {
	return in_array($subtag, array('nb', 'nn'), true) ? 'no' : $subtag;
}

/**
 * The language of the page being shown, as Yotpo's v3 widget loader takes it (its languageCode
 * parameter): the language part of the WordPress locale (fr_FR is fr, de_DE_formal is de), except the
 * regional variants Yotpo has, which keep their region with a hyphen (pt_BR is pt-BR, zh_CN is zh-CN;
 * zh_HK is zh-TW, the traditional script), and Norwegian, which Yotpo calls "no" (nb_NO and nn_NO). The loader shows English for a code it doesn't know, so
 * nothing breaks for a language Yotpo lacks. Multilingual plugins (WPML, Polylang and others) set the
 * locale per page, so the widgets follow the language the visitor browses in.
 *
 * Filterable as wc_yotpo_widget_language_code, for example to map a locale differently.
 *
 * @param string|null $locale A WordPress locale; the current page's when null.
 * @return string A language code such as fr or pt-BR, or '' when there is no locale.
 */
function wc_yotpo_widget_language_code($locale = null) {
	if (null === $locale) {
		$locale = function_exists('determine_locale') ? determine_locale() : get_locale();
	}
	$locale = (string) $locale;
	$regional = array(
		'pt_BR' => 'pt-BR',
		'pt_PT' => 'pt-PT',
		'zh_CN' => 'zh-CN',
		'zh_SG' => 'zh-CN',
		'zh_TW' => 'zh-TW',
		'zh_HK' => 'zh-TW',
	);
	if (isset($regional[$locale])) {
		$code = $regional[$locale];
	} elseif (0 === strpos($locale, 'pt_')) {
		$code = 'pt-PT'; // Other Portuguese locales (pt_AO, pt_PT_ao90): Yotpo has no plain "pt".
	} else {
		$parts = explode('_', $locale);
		$code = wc_yotpo_language_code_for_subtag(strtolower($parts[0]));
	}
	return (string) apply_filters('wc_yotpo_widget_language_code', $code, $locale);
}

/**
 * The first release of each PHP branch with the fix for CVE-2026-109618 and CVE-2026-109619, critical flaws
 * in PHP itself (CERT-EU advisory 2026-018). PHP no longer supports the branches before 8.2: they get no fix.
 */
function wc_yotpo_php_security_releases() {
	return array(
		'8.2' => '8.2.35',
		'8.3' => '8.3.36',
		'8.4' => '8.4.27',
		'8.5' => '8.5.12',
	);
}

/**
 * The PHP release this server should update to for that fix, or '' when its version has it. A branch with
 * no fix gets the first fixed release of the plugin's minimum, 8.2. Branches after 8.5 are released with the fix.
 */
function wc_yotpo_php_security_update($version = PHP_VERSION) {
	$releases = wc_yotpo_php_security_releases();
	$parts = explode('.', (string) $version);
	$branch = $parts[0] . '.' . (isset($parts[1]) ? $parts[1] : '0');
	if (isset($releases[$branch])) {
		return version_compare($version, $releases[$branch], '<') ? $releases[$branch] : '';
	}
	return version_compare($branch, WC_YOTPO_MIN_PHP_VERSION, '<') ? $releases[WC_YOTPO_MIN_PHP_VERSION] : '';
}

/**
 * admin_notices: on the Plugins screen and the Yotpo settings, for admins, asks for a PHP update when the
 * server's version lacks the fix.
 */
function wc_yotpo_php_security_notice() {
	if (wc_yotpo_php_security_notice_screen()) {
		echo wc_yotpo_php_security_message(PHP_VERSION); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by wp_kses() there.
	}
}

function wc_yotpo_php_security_notice_screen() {
	if (!current_user_can('manage_options') || !function_exists('get_current_screen')) {
		return false;
	}
	$screen = get_current_screen();
	return $screen && in_array($screen->id, array('plugins', 'toplevel_page_woocommerce-yotpo-settings-page'), true);
}

/**
 * The notice for a server on this PHP version, or '' when it has the fix (or the filter turned it off).
 *
 * Some Linux distributions (Debian, Ubuntu) backport fixes and keep the version number, so a version
 * reported by wc_yotpo_php_security_update() may be patched after all: the notice says so, and the
 * wc_yotpo_show_php_security_notice filter turns it off.
 */
function wc_yotpo_php_security_message($version) {
	$update = wc_yotpo_php_security_update($version);
	if ('' === $update || !apply_filters('wc_yotpo_show_php_security_notice', true, $version)) {
		return '';
	}
	$advisory = '<a href="https://cert.europa.eu/publications/security-advisories/2026-018" target="_blank" rel="noopener">CVE-2026-109618 and CVE-2026-109619</a>';
	if (version_compare($version, WC_YOTPO_MIN_PHP_VERSION, '<')) {
		$message = sprintf(
			'<strong>Yotpo Product Reviews:</strong> your server runs PHP %1$s, which no longer gets security fixes, including for critical flaws found in October 2026 (%2$s). This version of the plugin needs PHP %3$s or later. Ask your host to update PHP to %4$s or later.',
			esc_html($version), $advisory, esc_html(WC_YOTPO_MIN_PHP_VERSION), esc_html($update)
		);
	} else {
		$message = sprintf(
			'<strong>Yotpo Product Reviews:</strong> your server runs PHP %1$s. Releases before %2$s have critical security flaws (%3$s), unless your host applied the fix separately. Ask your host to update PHP to %2$s or later, or to confirm the fix is in place.',
			esc_html($version), esc_html($update), $advisory
		);
	}
	return '<div class="notice notice-warning"><p>' . wp_kses($message, array('strong' => array(), 'a' => array('href' => array(), 'target' => array(), 'rel' => array()))) . '</p></div>';
}
