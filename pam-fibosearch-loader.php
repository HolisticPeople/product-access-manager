<?php
/**
 * Plugin Name: PAM FiboSearch Loader (MU-Plugin)
 * Description: Loads Product Access Manager during FiboSearch SHORTINIT mode
 * Version: 1.0.1
 * 
 * MU-Plugin to ensure Product Access Manager loads even during FiboSearch SHORTINIT mode.
 * MU-plugins ALWAYS load, even when WordPress is in SHORTINIT mode.
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Detect FiboSearch AJAX requests by checking the request URI and parameters
$is_fibosearch_request = (
    defined( 'SHORTINIT' ) && SHORTINIT &&
    (
        // Check for FiboSearch endpoint in URI
        ( isset( $_SERVER['REQUEST_URI'] ) && strpos( $_SERVER['REQUEST_URI'], 'dgwt-wcas-ajax-search' ) !== false ) ||
        // Or check for FiboSearch search parameter
        ( isset( $_GET['s'] ) && isset( $_SERVER['REQUEST_URI'] ) && strpos( $_SERVER['REQUEST_URI'], 'ajax-search-for-woocommerce' ) !== false )
    )
);

// Load Product Access Manager during FiboSearch requests
if ( $is_fibosearch_request ) {
    
    // Path to Product Access Manager plugin
    $pam_plugin_file = WP_PLUGIN_DIR . '/product-access-manager/product-access-manager.php';
    
    // Load the plugin if it exists and isn't already loaded
    if ( file_exists( $pam_plugin_file ) && ! function_exists( 'pam_get_blocked_products_cached' ) ) {
        require_once $pam_plugin_file;
        
        // Debug: Confirm loading (will appear in debug.log if PAM_DEBUG is true)
        if ( function_exists( 'pam_log' ) ) {
            pam_log( '[MU-Plugin] Loaded PAM for FiboSearch SHORTINIT request' );
        }
    }
}

