<?php
/**
 * Plugin Name: Product Access Manager
 * Plugin URI: 
 * Description: ACF-based product access control. Products in restricted catalogs are hidden by default, revealed to authorized users.
 * Version: 2.0.9
 * Author: Amnon Manneberg
 * Author URI: 
 * Requires at least: 5.8
 * Requires PHP: 8.0
 * WC requires at least: 6.0
 * WC tested up to: 10.0
 * 
 * @package ProductAccessManager
 * @version 2.0.0 - Major refactor: ACF-based, security-first architecture
 * @version 2.0.7 - CRITICAL FIX: Restored v1.9.0 working selectors including data-object attribute
 * @version 2.0.8 - Use remove() instead of hide() to completely eliminate filtered items from DOM
 * @version 2.0.9 - FIX: Removed incorrect data-object attribute, restored exact v1.9.0 ID extraction and selectors
 * @author Amnon Manneberg
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants
define( 'PAM_VERSION', '2.0.9' );
define( 'PAM_PLUGIN_FILE', __FILE__ );
define( 'PAM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// Debug mode - set to false in production
define( 'PAM_DEBUG', true );

/**
 * Debug logging function
 * Only logs when PAM_DEBUG is enabled
 */
if ( ! function_exists( 'pam_log' ) ) {
    function pam_log( $message ) {
        if ( defined( 'PAM_DEBUG' ) && PAM_DEBUG ) {
            $version = defined( 'PAM_VERSION' ) ? PAM_VERSION : 'unknown';
            error_log( '[PAM v' . $version . '] ' . $message );
        }
    }
}

// ============================================================================
// CORE INITIALIZATION
// ============================================================================

/**
 * Initialize WooCommerce-dependent hooks
 * Runs on 'init' to ensure WooCommerce and ACF are loaded
 */
add_action( 'init', function () {
    pam_log( 'Init - registering hooks for user ' . ( is_user_logged_in() ? get_current_user_id() : 'guest' ) );
    
    if ( ! class_exists( 'WooCommerce' ) ) {
        pam_log( 'WooCommerce not loaded - skipping hooks' );
        return;
    }

    // Product visibility filters (reveal to authorized users)
    add_filter( 'woocommerce_product_is_visible', 'pam_reveal_product', 10, 2 );
    add_filter( 'woocommerce_variation_is_visible', 'pam_reveal_variation', 10, 4 );
    add_filter( 'woocommerce_is_purchasable', 'pam_allow_purchase', 10, 2 );
    
    // Single product page protection
    add_action( 'template_redirect', 'pam_protect_single_product' );
    
    // Query filters (reveal restricted products to authorized users)
    add_action( 'pre_get_posts', 'pam_modify_query' );
    
    pam_log( 'WooCommerce hooks registered' );
} );

/**
 * Initialize FiboSearch integration
 * Runs early on plugins_loaded to ensure filters are active during AJAX
 */
add_action( 'plugins_loaded', function () {
    // NOTE: We do NOT prevent indexing because authorized users need to search too!
    
    // Server-side filter (runs during full WordPress load - won't work in SHORTINIT)
    add_filter( 'dgwt/wcas/tnt/search_results/suggestion/product', 'pam_filter_fibo_product', 10, 2 );
    
    // Client-side filtering (PRIMARY method for FiboSearch due to SHORTINIT mode)
    add_action( 'wp_enqueue_scripts', 'pam_enqueue_fibo_filter_script' );
    add_action( 'wp_ajax_pam_get_restricted_data', 'pam_ajax_get_restricted_data' );
    add_action( 'wp_ajax_nopriv_pam_get_restricted_data', 'pam_ajax_get_restricted_data' );
    
    pam_log( 'FiboSearch hooks registered' );
}, 5 );

// ============================================================================
// CORE HELPER FUNCTIONS
// ============================================================================

/**
 * Check if product is restricted (has site_catalog ACF field set)
 * 
 * @param int|WC_Product $product Product ID or object
 * @return bool True if product is restricted
 */
function pam_is_restricted_product( $product ) {
    $product_id = $product instanceof WC_Product ? $product->get_id() : (int) $product;
    
    // Check if ACF function exists
    if ( ! function_exists( 'get_field' ) ) {
        pam_log( 'ACF not loaded - treating product ' . $product_id . ' as unrestricted' );
        return false;
    }
    
    $catalogs = get_field( 'site_catalog', $product_id );
    
    pam_log( 'Product ' . $product_id . ' catalogs: ' . ( empty( $catalogs ) ? 'none' : implode( ', ', (array) $catalogs ) ) );
    
    return ! empty( $catalogs );
}

/**
 * Get required roles for a product based on its site_catalog ACF field
 * 
 * @param int|WC_Product $product Product ID or object
 * @return array Array of required role slugs (e.g., ['access-vimergy-user'])
 */
function pam_get_required_roles( $product ) {
    $product_id = $product instanceof WC_Product ? $product->get_id() : (int) $product;
    
    if ( ! function_exists( 'get_field' ) ) {
        return [];
    }
    
    $catalogs = get_field( 'site_catalog', $product_id );
    if ( empty( $catalogs ) ) {
        return [];
    }
    
    $roles = [];
    foreach ( (array) $catalogs as $catalog ) {
        // Map catalog to role
        // "Vimergy_catalog" → "access-vimergy-user"
        // "DCG_catalog" → "access-dcg-user"
        // "HP_catalog" → "access-hp-user"
        $brand = strtolower( str_replace( '_catalog', '', $catalog ) );
        $roles[] = 'access-' . $brand . '-user';
    }
    
    pam_log( 'Product ' . $product_id . ' required roles: ' . implode( ', ', $roles ) );
    
    return $roles;
}

/**
 * Check if user can view a product
 * 
 * @param int|WC_Product $product Product ID or object
 * @param int|null $user_id User ID (defaults to current user)
 * @return bool True if user can view the product
 */
function pam_user_can_view( $product, $user_id = null ) {
    $product_id = $product instanceof WC_Product ? $product->get_id() : (int) $product;
    
    // Admin override - admins and shop managers can always see everything
    if ( current_user_can( 'manage_woocommerce' ) ) {
        pam_log( 'Admin user - allowing access to product ' . $product_id );
        return true;
    }
    
    // Check if product is restricted
    $required_roles = pam_get_required_roles( $product_id );
    if ( empty( $required_roles ) ) {
        pam_log( 'Product ' . $product_id . ' is not restricted - allowing access' );
        return true; // Not restricted - public product
    }
    
    // Get user
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    if ( ! $user_id ) {
        pam_log( 'Product ' . $product_id . ' is restricted, user not logged in - denying access' );
        return false; // Not logged in
    }
    
    // Check if user has any required role
    $user = get_userdata( $user_id );
    if ( ! $user ) {
        pam_log( 'Invalid user ID ' . $user_id . ' - denying access' );
        return false;
    }
    
    foreach ( $required_roles as $role ) {
        if ( in_array( $role, $user->roles ) ) {
            pam_log( 'User ' . $user_id . ' has role ' . $role . ' - allowing access to product ' . $product_id );
            return true;
        }
    }
    
    pam_log( 'User ' . $user_id . ' does not have required roles for product ' . $product_id . ' - denying access' );
    return false;
}

// ============================================================================
// PRODUCT VISIBILITY FILTERS (Reveal to Authorized)
// ============================================================================

/**
 * Reveal product to authorized users
 * 
 * @param bool $visible Current visibility
 * @param int $product_id Product ID
 * @return bool Modified visibility
 */
function pam_reveal_product( $visible, $product_id ) {
    // If not restricted, use WC default visibility
    if ( ! pam_is_restricted_product( $product_id ) ) {
        return $visible;
    }
    
    // If restricted and user can view, REVEAL it (override hidden status)
    if ( pam_user_can_view( $product_id ) ) {
        pam_log( 'Revealing restricted product ' . $product_id . ' to authorized user' );
        return true;
    }
    
    // Otherwise keep WC default (hidden)
    pam_log( 'Keeping product ' . $product_id . ' hidden from unauthorized user' );
    return $visible;
}

/**
 * Reveal variation to authorized users
 * 
 * @param bool $visible Current visibility
 * @param int $variation_id Variation ID
 * @param int $product_id Parent product ID
 * @param WC_Product_Variation $variation Variation object
 * @return bool Modified visibility
 */
function pam_reveal_variation( $visible, $variation_id, $product_id, $variation ) {
    // Check parent product access
    return $visible && pam_user_can_view( $product_id );
}

/**
 * Allow purchase for authorized users
 * 
 * @param bool $purchasable Current purchasable status
 * @param WC_Product $product Product object
 * @return bool Modified purchasable status
 */
function pam_allow_purchase( $purchasable, $product ) {
    return $purchasable && pam_user_can_view( $product );
}

/**
 * Protect single product page access
 * Returns 404 for restricted products that user can't view
 */
function pam_protect_single_product() {
    if ( ! is_product() ) {
        return;
    }

    $product_id = get_queried_object_id();
    if ( ! pam_user_can_view( $product_id ) ) {
        pam_log( 'Blocking single product page access for product ' . $product_id );
        
        global $wp_query;
        $wp_query->set_404();
        status_header( 404 );
        nocache_headers();

        $template = locate_template( array( 'woocommerce/404.php', '404.php' ) );
        if ( $template ) {
            include $template;
        } else {
            wp_die( esc_html__( 'This product is not available.', 'woocommerce' ), '', array( 'response' => 404 ) );
        }
        exit;
    }
}

// ============================================================================
// QUERY FILTERING (Reveal Restricted Products to Authorized Users)
// ============================================================================

/**
 * Modify WP_Query to reveal restricted products to authorized users
 * 
 * @param WP_Query $query WordPress query object
 */
function pam_modify_query( $query ) {
    // Skip for admins
    if ( current_user_can( 'manage_woocommerce' ) ) {
        return;
    }

    // Skip if not main query or not shop/archive
    if ( ! $query->is_main_query() || ! ( $query->is_shop() || $query->is_product_taxonomy() || $query->is_search() ) ) {
        return;
    }

    // Skip if not product query
    $post_types = $query->get( 'post_type' );
    if ( ! empty( $post_types ) && ! in_array( 'product', (array) $post_types, true ) ) {
        return;
    }
    
    pam_log( 'Modifying query for user ' . ( is_user_logged_in() ? get_current_user_id() : 'guest' ) );
    
    // WC will naturally exclude hidden products
    // We need to potentially INCLUDE restricted products for authorized users
    
    if ( ! is_user_logged_in() ) {
        pam_log( 'User not logged in - letting WC hide restricted products' );
        return; // Let WC hide everything restricted
    }
    
    // Get user's accessible catalogs
    $user = wp_get_current_user();
    $accessible_catalogs = [];
    
    foreach ( $user->roles as $role ) {
        if ( strpos( $role, 'access-' ) === 0 ) {
            // Convert role back to catalog
            // "access-vimergy-user" → "Vimergy_catalog"
            // "access-dcg-user" → "DCG_catalog"
            $brand = str_replace( ['access-', '-user'], '', $role );
            $accessible_catalogs[] = ucfirst( $brand ) . '_catalog';
        }
    }
    
    if ( empty( $accessible_catalogs ) ) {
        pam_log( 'User has no special access roles - letting WC hide restricted products' );
        return; // No special access
    }
    
    pam_log( 'User has access to catalogs: ' . implode( ', ', $accessible_catalogs ) );
    
    // Modify meta query to include products user can access
    // This overrides WC's hidden visibility for authorized products
    $meta_query = $query->get( 'meta_query' ) ?: [];
    
    // Add our reveal logic
    $meta_query[] = [
        'relation' => 'OR',
        [
            'key' => 'site_catalog',
            'compare' => 'NOT EXISTS', // Public products (no catalog set)
        ],
        [
            'key' => 'site_catalog',
            'value' => $accessible_catalogs,
            'compare' => 'IN', // User's accessible catalogs
        ],
    ];
    
    $query->set( 'meta_query', $meta_query );
    
    pam_log( 'Query modified to reveal restricted products' );
}

// ============================================================================
// FIBOSEARCH INTEGRATION
// ============================================================================

/**
 * NOTE: We do NOT exclude products from FiboSearch index
 * Because then authorized users couldn't search for them either!
 * 
 * Instead, we let FiboSearch index everything and filter via:
 * 1. Server-side filters for normal page loads
 * 2. Client-side JS for FiboSearch AJAX (SHORTINIT mode)
 */

/**
 * Filter FiboSearch product suggestions - hide restricted products from unauthorized users
 * 
 * @param array|bool $suggestion Product suggestion data
 * @param int $post_id Product ID
 * @return array|bool Modified suggestion or false to hide
 */
function pam_filter_fibo_product( $suggestion, $post_id ) {
    // If user can't view this product, hide it from FiboSearch
    if ( ! pam_user_can_view( $post_id ) ) {
        pam_log( 'FiboSearch: Hiding product ' . $post_id . ' from unauthorized user' );
        return false; // Remove from suggestions
    }
    
    pam_log( 'FiboSearch: Showing product ' . $post_id . ' to authorized user' );
    return $suggestion;
}

// ============================================================================
// UTILITY FUNCTIONS (Kept for Backward Compatibility)
// ============================================================================

/**
 * Check if current user has full access (admin/shop manager)
 * 
 * @return bool True if user can manage WooCommerce
 */
function pam_user_has_full_access() {
    return current_user_can( 'manage_woocommerce' );
}

/**
 * Get restricted product IDs (for current user)
 * 
 * @return array Array of product IDs that current user cannot view
 */
function pam_get_restricted_product_ids() {
    if ( pam_user_has_full_access() ) {
        return [];
    }
    
    // This is now less efficient but kept for backward compatibility
    // In v2.0, we rely on ACF meta queries instead
    $all_products = wc_get_products([
        'limit' => -1,
        'return' => 'ids',
        'status' => 'publish',
    ]);
    
    $restricted = [];
    foreach ( $all_products as $product_id ) {
        if ( ! pam_user_can_view( $product_id ) ) {
            $restricted[] = $product_id;
        }
    }
    
    return $restricted;
}

// ============================================================================
// FIBOSEARCH CLIENT-SIDE FILTERING (For SHORTINIT Mode)
// ============================================================================

/**
 * Enqueue FiboSearch filtering JavaScript
 */
function pam_enqueue_fibo_filter_script() {
    wp_enqueue_script(
        'pam-fibosearch-filter',
        plugins_url( 'pam-fibosearch-filter.js', __FILE__ ),
        array( 'jquery' ),
        PAM_VERSION,
        true
    );
    
    wp_localize_script( 'pam-fibosearch-filter', 'pamFiboFilter', array(
        'ajaxUrl' => admin_url( 'admin-ajax.php' )
    ) );
    
    pam_log( 'FiboSearch filter script enqueued' );
}

/**
 * AJAX handler: Get restricted products and brands for current user
 */
function pam_ajax_get_restricted_data() {
    // Clean output buffer to prevent PHP notices from breaking JSON
    while ( ob_get_level() > 0 ) {
        ob_end_clean();
    }
    ob_start();
    
    $restricted_products = pam_get_restricted_product_ids();
    $restricted_brands = pam_get_restricted_brand_names();
    
    wp_send_json_success( array(
        'products' => $restricted_products,
        'brands' => $restricted_brands,
    ) );
}

/**
 * Get restricted brand names for current user
 * 
 * @return array Array of brand names that current user cannot see
 */
function pam_get_restricted_brand_names() {
    if ( pam_user_has_full_access() ) {
        return [];
    }
    
    // Get all possible catalog values
    $all_catalogs = array( 'Vimergy_catalog', 'DCG_catalog', 'HP_catalog' );
    
    // Get user's accessible catalogs
    $accessible_catalogs = [];
    if ( is_user_logged_in() ) {
        $user = wp_get_current_user();
        foreach ( $user->roles as $role ) {
            if ( strpos( $role, 'access-' ) === 0 ) {
                // Convert role to catalog
                // "access-vimergy-user" → "Vimergy_catalog"
                $brand = str_replace( array( 'access-', '-user' ), '', $role );
                $accessible_catalogs[] = ucfirst( $brand ) . '_catalog';
            }
        }
    }
    
    // Get restricted catalogs (ones user can't access)
    $restricted_catalogs = array_diff( $all_catalogs, $accessible_catalogs );
    
    // Convert to brand names for display filtering
    $restricted_brands = [];
    foreach ( $restricted_catalogs as $catalog ) {
        // "Vimergy_catalog" → "Vimergy"
        $brand = str_replace( '_catalog', '', $catalog );
        $restricted_brands[] = $brand;
    }
    
    return $restricted_brands;
}
