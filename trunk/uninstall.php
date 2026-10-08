<?php
if( !defined('WP_UNINSTALL_PLUGIN') ) exit();
delete_option('yotpo_settings');

// Remove the private debug log directory (log, .htaccess and index.php guards); the log contains customer emails.
require_once ABSPATH . 'wp-admin/includes/file.php';
if ( WP_Filesystem() ) {
	global $wp_filesystem;
	$yotpo_upload_dir = wp_upload_dir( null, false );
	$yotpo_private_dir = trailingslashit( $yotpo_upload_dir['basedir'] ) . 'yotpo-social-reviews-for-woocommerce';
	if ( $wp_filesystem->is_dir( $yotpo_private_dir ) ) {
		$wp_filesystem->delete( $yotpo_private_dir, true );
	}
}
