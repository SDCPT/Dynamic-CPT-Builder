<?php
/**
 * On uninstall only the plugin's own options are removed.
 * Posts and meta data are deliberately kept.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'dcb_definitions' );
delete_option( 'dcb_flush_needed' );
