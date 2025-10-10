<?php
/**
 * Plugin Name: Product Access Manager
 * Plugin URI: 
 * Description: ACF-based product access control with session-based caching. Auto-detects restricted catalogs, uses fast post__not_in exclusion. HP and DCG catalogs public.
 * Version: 2.6.0
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
 * @version 2.1.0 - Added filtering for FiboSearch right panel (details view on hover/selection)
 * @version 2.1.1 - FIX: Run filter multiple times with delays to catch FiboSearch re-renders
 * @author Amnon Manneberg
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants
define( 'PAM_VERSION', '2.6.0' );
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
    
    // Filter wc_get_products() calls (catches sliders, widgets, related products, etc.)
    add_filter( 'woocommerce_product_data_store_cpt_get_products_query', 'pam_filter_wc_get_products', 10, 2 );
    
    // Cache invalidation hooks
    add_action( 'wp_login', 'pam_clear_user_cache_on_login', 10, 2 );
    add_action( 'wp_logout', 'pam_clear_user_cache_on_logout' );
    add_action( 'set_user_role', 'pam_clear_user_cache_on_role_change', 10, 3 );
    
    pam_log( 'WooCommerce hooks registered' );
} );

/**
 * Initialize FiboSearch integration
 * Runs early on plugins_loaded to ensure filters are active during AJAX
 */
add_action( 'plugins_loaded', function () {
    // NOTE: We do NOT prevent indexing because authorized users need to search too!
    
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
 * Get list of PUBLIC catalogs (catalogs that don't require special access)
 * 
 * SINGLE SOURCE OF TRUTH for which catalogs should remain public.
 * Any catalog NOT in this list will automatically require role-based access.
 * 
 * This is the INVERSE approach - we define what's public, everything else is restricted.
 * 
 * To add a new PUBLIC catalog:
 * 1. Add catalog name to this array (e.g., 'NewPublic_catalog')
 * 
 * To add a new RESTRICTED catalog:
 * 1. Add catalog choice to ACF field (e.g., 'Gaia_catalog')
 * 2. Create corresponding user role (e.g., 'access-gaia-user')
 * 3. That's it! No code changes needed - it auto-detects!
 * 
 * @return array List of public catalog names (e.g., ['HP_catalog', 'DCG_catalog'])
 */
function pam_get_public_catalogs() {
    return array(
        'HP_catalog',
        'DCG_catalog',
        // Add future PUBLIC catalogs here if needed:
        // 'FreeProducts_catalog',
    );
}

/**
 * Get all possible catalog values from ACF field
 * 
 * This dynamically discovers all catalogs by checking:
 * 1. ACF field choices (if field object is available)
 * 2. Existing products with site_catalog set (fallback)
 * 
 * @return array All catalog values (e.g., ['Vimergy_catalog', 'HP_catalog', 'DCG_catalog', 'Gaia_catalog'])
 */
function pam_get_all_catalogs() {
    $all_catalogs = array();
    
    // Try to get from ACF field choices first
    if ( function_exists( 'acf_get_field' ) ) {
        $field = acf_get_field( 'site_catalog' );
        if ( $field && ! empty( $field['choices'] ) ) {
            $all_catalogs = array_keys( $field['choices'] );
        }
    }
    
    // Fallback: Get from existing products
    if ( empty( $all_catalogs ) ) {
        global $wpdb;
        $results = $wpdb->get_col( 
            "SELECT DISTINCT meta_value 
             FROM {$wpdb->postmeta} 
             WHERE meta_key = 'site_catalog' 
             AND meta_value LIKE '%_catalog'"
        );
        
        if ( ! empty( $results ) ) {
            foreach ( $results as $serialized ) {
                // ACF stores as serialized array
                $unserialized = maybe_unserialize( $serialized );
                if ( is_array( $unserialized ) ) {
                    $all_catalogs = array_merge( $all_catalogs, $unserialized );
        } else {
                    $all_catalogs[] = $unserialized;
                }
            }
            $all_catalogs = array_unique( $all_catalogs );
        }
    }
    
    // Filter out empty values
    $all_catalogs = array_filter( $all_catalogs );
    
    return $all_catalogs;
}

/**
 * Get list of RESTRICTED catalogs (catalogs that require role-based access)
 * 
 * AUTO-DETECTS restricted catalogs by:
 * 1. Getting all catalogs from ACF field or database
 * 2. Excluding public catalogs from pam_get_public_catalogs()
 * 3. Everything else is automatically restricted!
 * 
 * NO CODE CHANGES NEEDED to add new restricted catalogs:
 * - Just add catalog to ACF field choices (e.g., 'Gaia_catalog')
 * - Create corresponding role (e.g., 'access-gaia-user')
 * - Plugin auto-detects and restricts it!
 * 
 * @return array List of restricted catalog names (auto-detected)
 */
function pam_get_restricted_catalogs() {
    $all_catalogs = pam_get_all_catalogs();
    $public_catalogs = pam_get_public_catalogs();
    
    // Restricted = All catalogs MINUS public catalogs
    $restricted_catalogs = array_diff( $all_catalogs, $public_catalogs );
    
    pam_log( 'Auto-detected catalogs - All: ' . implode( ', ', $all_catalogs ) . 
             ' | Public: ' . implode( ', ', $public_catalogs ) . 
             ' | Restricted: ' . implode( ', ', $restricted_catalogs ) );
    
    return array_values( $restricted_catalogs );
}

// ============================================================================
// SESSION-BASED CACHING (Performance Optimization)
// ============================================================================

/**
 * Get blocked product IDs for current user (cached)
 * 
 * Returns product IDs that should be hidden from current user.
 * Cached per user for 30 minutes for performance.
 * FAIL-SECURE: On error, hides all restricted products.
 * 
 * @param int|null $user_id User ID (null = current user)
 * @return array Product IDs to block
 */
function pam_get_blocked_products_cached( $user_id = null ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    
    // Cache key
    $cache_key = $user_id ? 'pam_hidden_products_' . $user_id : 'pam_hidden_products_guest';
    $lock_key = $cache_key . '_building';
    
    // Try cache first (performance layer)
    $blocked = get_transient( $cache_key );
    if ( $blocked !== false ) {
        pam_log( 'Cache HIT for ' . $cache_key . ' - ' . count( $blocked ) . ' blocked products' );
        return $blocked;
    }
    
    // Check if another process is already building the cache
    $is_building = get_transient( $lock_key );
    if ( $is_building ) {
        pam_log( 'Cache is being rebuilt by another process - waiting...' );
        
        // Wait briefly and retry (max 3 attempts)
        for ( $i = 0; $i < 3; $i++ ) {
            usleep( 100000 ); // 100ms
            $blocked = get_transient( $cache_key );
            if ( $blocked !== false ) {
                pam_log( 'Cache became available after waiting' );
                return $blocked;
            }
        }
        
        // Still not ready - fail secure by blocking all restricted products
        pam_log( 'Cache still building after wait - using fail-secure mode' );
        return pam_get_restricted_product_ids();
    }
    
    // Set lock to prevent concurrent rebuilds (5 second lock)
    set_transient( $lock_key, true, 5 );
    pam_log( 'Cache MISS for ' . $cache_key . ' - rebuilding (lock acquired)' );
    
    // Calculate (security layer)
    try {
        $blocked = pam_calculate_blocked_products( $user_id );
        
        // Sanity check (fail-secure)
        if ( empty( $blocked ) ) {
            $all_restricted = pam_get_restricted_product_ids();
            if ( ! empty( $all_restricted ) && ! pam_user_has_any_access_role( $user_id ) ) {
                pam_log( 'FAIL-SECURE: Empty blocked list but user has no access roles - blocking all restricted' );
                $blocked = $all_restricted;
            }
        }
        
    } catch ( Exception $e ) {
        pam_log( 'ERROR calculating blocked products: ' . $e->getMessage() );
        $blocked = pam_get_restricted_product_ids(); // Fail secure
    }
    
    // Cache for 30 minutes
    set_transient( $cache_key, $blocked, 30 * MINUTE_IN_SECONDS );
    
    // Release lock
    delete_transient( $lock_key );
    
    pam_log( 'Cache SAVED for ' . $cache_key . ' - ' . count( $blocked ) . ' blocked products (lock released)' );
    
    return $blocked;
}

/**
 * Calculate which products should be blocked for user
 * 
 * @param int|null $user_id User ID (0 = guest)
 * @return array Product IDs to block
 */
function pam_calculate_blocked_products( $user_id ) {
    // Admin/shop managers see everything
    if ( current_user_can( 'manage_woocommerce' ) ) {
        pam_log( 'Admin user - no products blocked' );
        return array();
    }
    
    // Get all restricted product IDs
    $restricted_products = pam_get_restricted_product_ids();
    if ( empty( $restricted_products ) ) {
        pam_log( 'No restricted products exist - nothing to block' );
        return array();
    }
    
    pam_log( 'Found ' . count( $restricted_products ) . ' restricted products total' );
    
    // Guest users: block all restricted products
    if ( ! $user_id ) {
        pam_log( 'Guest user - blocking all ' . count( $restricted_products ) . ' restricted products' );
        return $restricted_products;
    }
    
    // Logged-in users: check which catalogs they can access
    $user = get_userdata( $user_id );
    if ( ! $user ) {
        pam_log( 'Invalid user ID ' . $user_id . ' - blocking all restricted products' );
        return $restricted_products;
    }
    
    $user_accessible_catalogs = array();
    foreach ( $user->roles as $role ) {
        if ( strpos( $role, 'access-' ) === 0 ) {
            // Convert role to catalog
            // "access-vimergy-user" → "Vimergy_catalog"
            $brand = str_replace( array( 'access-', '-user' ), '', $role );
            $catalog = ucfirst( $brand ) . '_catalog';
            $user_accessible_catalogs[] = $catalog;
        }
    }
    
    // No access roles: block everything
    if ( empty( $user_accessible_catalogs ) ) {
        pam_log( 'User ' . $user_id . ' has no access roles - blocking all restricted products' );
        return $restricted_products;
    }
    
    pam_log( 'User ' . $user_id . ' has access to catalogs: ' . implode( ', ', $user_accessible_catalogs ) );
    
    // Filter: only block products user can't access
    $blocked = array();
    foreach ( $restricted_products as $product_id ) {
        $product_catalog = get_field( 'site_catalog', $product_id );
        if ( ! $product_catalog ) {
            continue; // Not restricted
        }
        
        // Check if user has access to this product's catalog
        $product_catalogs = is_array( $product_catalog ) ? $product_catalog : array( $product_catalog );
        $has_access = false;
        foreach ( $product_catalogs as $cat ) {
            if ( in_array( $cat, $user_accessible_catalogs, true ) ) {
                $has_access = true;
                break;
            }
        }
        
        if ( ! $has_access ) {
            $blocked[] = $product_id;
        }
    }
    
    pam_log( 'User ' . $user_id . ' - blocking ' . count( $blocked ) . ' of ' . count( $restricted_products ) . ' restricted products' );
    
    return $blocked;
}

/**
 * Check if user has any access-* role
 * 
 * @param int|null $user_id User ID
 * @return bool True if user has at least one access role
 */
function pam_user_has_any_access_role( $user_id ) {
    if ( ! $user_id ) {
        return false;
    }
    $user = get_userdata( $user_id );
    if ( ! $user ) {
            return false;
        }
    foreach ( $user->roles as $role ) {
        if ( strpos( $role, 'access-' ) === 0 ) {
            return true;
        }
    }
            return false;
        }

/**
 * Clear cached blocked products for user
 * 
 * @param int|null $user_id User ID (null = guest)
 */
function pam_clear_blocked_products_cache( $user_id = null ) {
    if ( $user_id ) {
        delete_transient( 'pam_hidden_products_' . $user_id );
        pam_log( 'Cleared cache for user ' . $user_id );
    } else {
        delete_transient( 'pam_hidden_products_guest' );
        pam_log( 'Cleared cache for guests' );
    }
}

/**
 * Clear cache on user login
 * 
 * @param string $user_login User login name
 * @param WP_User $user User object
 */
function pam_clear_user_cache_on_login( $user_login, $user ) {
    pam_clear_blocked_products_cache( $user->ID );
}

/**
 * Clear cache on user logout
 */
function pam_clear_user_cache_on_logout() {
    pam_clear_blocked_products_cache( get_current_user_id() );
}

/**
 * Clear cache when user roles change
 * 
 * @param int $user_id User ID
 * @param string $role New role
 * @param array $old_roles Previous roles
 */
function pam_clear_user_cache_on_role_change( $user_id, $role, $old_roles ) {
    pam_clear_blocked_products_cache( $user_id );
}

// ============================================================================
// PRODUCT RESTRICTION CHECKS
// ============================================================================

/**
 * Check if product is restricted (has restricted site_catalog ACF field set)
 * 
 * NOTE: Only catalogs in pam_get_restricted_catalogs() are restricted.
 * HP_catalog and DCG_catalog are PUBLIC.
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
    if ( empty( $catalogs ) ) {
        pam_log( 'Product ' . $product_id . ' has no catalogs - unrestricted' );
        return false;
    }
    
    // Get restricted catalogs from centralized function
    $restricted_catalogs = pam_get_restricted_catalogs();
    
    // Check if product has any restricted catalog
    foreach ( (array) $catalogs as $catalog ) {
        if ( in_array( $catalog, $restricted_catalogs, true ) ) {
            pam_log( 'Product ' . $product_id . ' is RESTRICTED by catalog: ' . $catalog );
        return true;
    }
    }
    
    pam_log( 'Product ' . $product_id . ' has PUBLIC catalogs only: ' . implode( ', ', (array) $catalogs ) );
    return false;
}

/**
 * Get required roles for a product based on its site_catalog ACF field
 * 
 * NOTE: Only returns roles for RESTRICTED catalogs (Vimergy).
 * HP_catalog and DCG_catalog are public, so they don't require any roles.
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
    
    // Get restricted catalogs from centralized function
    $restricted_catalogs = pam_get_restricted_catalogs();
    
    $roles = [];
    foreach ( (array) $catalogs as $catalog ) {
        // Only add roles for restricted catalogs
        if ( ! in_array( $catalog, $restricted_catalogs, true ) ) {
            continue; // Skip public catalogs
        }
        
        // Map catalog to role: "Vimergy_catalog" → "access-vimergy-user"
        $brand = strtolower( str_replace( '_catalog', '', $catalog ) );
        $roles[] = 'access-' . $brand . '-user';
    }
    
    pam_log( 'Product ' . $product_id . ' required roles: ' . ( empty( $roles ) ? 'none (public)' : implode( ', ', $roles ) ) );
    
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

    // Skip if not main query or not shop/archive/search
    if ( ! $query->is_main_query() || ! ( $query->is_shop() || $query->is_product_taxonomy() || $query->is_search() ) ) {
        return;
    }

    // Skip if not product query
    $post_types = $query->get( 'post_type' );
    if ( ! empty( $post_types ) && ! in_array( 'product', (array) $post_types, true ) ) {
        return;
    }
    
    // Get blocked products from cache (fast!)
    $blocked_products = pam_get_blocked_products_cached();
    
    if ( empty( $blocked_products ) ) {
        pam_log( 'No products to block for current user' );
        return;
    }
    
    // Exclude blocked products using post__not_in (fastest method)
    $query->set( 'post__not_in', $blocked_products );
    
    pam_log( 'Excluded ' . count( $blocked_products ) . ' products from query' );
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
    // Use cached blocked products (same as WooCommerce queries)
    $blocked_products = pam_get_blocked_products_cached();
    
    // If product is blocked for this user, hide it from FiboSearch
    if ( in_array( $post_id, $blocked_products, true ) ) {
        pam_log( 'FiboSearch: Hiding blocked product ' . $post_id );
        return false; // Hide from results
    }
    
    pam_log( 'FiboSearch: Showing product ' . $post_id );
    return $suggestion; // Show in results
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
        pam_log( 'User has full access - returning empty restricted list' );
        return [];
    }
    
    // Get all products with site_catalog ACF field set
    $all_products = wc_get_products([
        'limit' => -1,
        'return' => 'ids',
        'status' => 'publish',
    ]);
    
    pam_log( 'Checking ' . count( $all_products ) . ' total products for restrictions' );
    
    $restricted = [];
    $restricted_count_by_catalog = [];
    
    foreach ( $all_products as $product_id ) {
        if ( ! pam_user_can_view( $product_id ) ) {
            $restricted[] = $product_id;
            
            // Debug: Count by catalog
            if ( function_exists( 'get_field' ) ) {
                $catalogs = get_field( 'site_catalog', $product_id );
                if ( $catalogs ) {
                    foreach ( (array) $catalogs as $catalog ) {
                        if ( ! isset( $restricted_count_by_catalog[ $catalog ] ) ) {
                            $restricted_count_by_catalog[ $catalog ] = 0;
                        }
                        $restricted_count_by_catalog[ $catalog ]++;
                    }
                }
            }
        }
    }
    
    pam_log( 'Found ' . count( $restricted ) . ' restricted products. By catalog: ' . print_r( $restricted_count_by_catalog, true ) );
    
    return $restricted;
}

// ============================================================================
// FIBOSEARCH CLIENT-SIDE FILTERING (For SHORTINIT Mode)
// ============================================================================

/**
 * Enqueue FiboSearch filtering script
 * 
 * Lightweight client-side filter that uses cached blocked products endpoint.
 * Required because FiboSearch runs in SHORTINIT mode (plugins don't load).
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
    
    pam_log( 'FiboSearch filter script enqueued v' . PAM_VERSION );
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
    
    $restricted_product_ids = pam_get_restricted_product_ids();
    $restricted_brands = pam_get_restricted_brand_names();
    
    // Get product URLs for client-side matching
    $restricted_product_urls = [];
    foreach ( $restricted_product_ids as $product_id ) {
        $restricted_product_urls[] = get_permalink( $product_id );
    }
    
    pam_log( 'AJAX: Returning ' . count( $restricted_product_ids ) . ' restricted products, ' . count( $restricted_brands ) . ' brands' );
    
    // Clean any output before sending JSON
    ob_end_clean();
    
    wp_send_json_success( array(
        'products' => $restricted_product_ids,
        'product_urls' => $restricted_product_urls,
        'brands' => $restricted_brands,
    ) );
    exit;
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
    
    // Get restricted catalogs from centralized function
    $restricted_catalogs_list = pam_get_restricted_catalogs();
    
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
    // Only consider catalogs that are actually restricted (not HP/DCG)
    $restricted_catalogs = array_diff( $restricted_catalogs_list, $accessible_catalogs );
    
    // Convert to brand names for display filtering
    $restricted_brands = [];
    foreach ( $restricted_catalogs as $catalog ) {
        // "Vimergy_catalog" → "Vimergy"
        $brand = str_replace( '_catalog', '', $catalog );
        $restricted_brands[] = $brand;
    }
    
    return $restricted_brands;
}

/**
 * Filter wc_get_products() queries to exclude restricted products
 * 
 * Universal filter that catches ALL uses of wc_get_products() across plugins/themes.
 * This includes: Product sliders, related products, upsells, widgets, custom queries.
 * 
 * @param array $wp_query_args Query arguments for WP_Query
 * @param array $query_vars Query variables passed to wc_get_products()
 * @return array Modified query arguments with post__not_in exclusion
 */
function pam_filter_wc_get_products( $wp_query_args, $query_vars ) {
    // Prevent infinite recursion: Skip if we're building the cache
    static $is_building_cache = false;
    if ( $is_building_cache ) {
        return $wp_query_args;
    }
    
    // Skip for admins
    if ( current_user_can( 'manage_woocommerce' ) ) {
        return $wp_query_args;
    }
    
    // Set flag to prevent recursion during cache build
    $is_building_cache = true;
    
    // Get blocked products from cache (same as shop/search queries)
    $blocked_products = pam_get_blocked_products_cached();
    
    // Clear flag
    $is_building_cache = false;
    
    if ( empty( $blocked_products ) ) {
        return $wp_query_args;
    }
    
    // Add exclusion to query arguments
    if ( isset( $wp_query_args['post__not_in'] ) ) {
        // Merge with existing exclusions
        $wp_query_args['post__not_in'] = array_unique( 
            array_merge( 
                (array) $wp_query_args['post__not_in'], 
                $blocked_products 
            ) 
        );
    } else {
        $wp_query_args['post__not_in'] = $blocked_products;
    }
    
    pam_log( 'wc_get_products(): Excluded ' . count( $blocked_products ) . ' products' );
    
    return $wp_query_args;
}
