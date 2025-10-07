<?php
/**
 * Plugin Name: Product Access Manager
 * Plugin URI: 
 * Description: Limits visibility and purchasing of products tagged with "access-*" to users with matching roles. Includes shortcode for conditional stock display.
 * Version: 1.0.8
 * Author: Amnon Manneberg
 * Author URI: 
 * Requires at least: 5.8
 * Requires PHP: 8.0
 * WC requires at least: 6.0
 * WC tested up to: 10.0
 * 
 * @package ProductAccessManager
 * @version 1.0.0 - Initial release: Consolidated WPCode snippets into plugin
 * @author Amnon Manneberg
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants
define( 'PAM_VERSION', '1.0.8' );
define( 'PAM_PLUGIN_FILE', __FILE__ );
define( 'PAM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Debug logging function
 * Only logs when WP_DEBUG is enabled
 */
if ( ! function_exists( 'pam_log' ) ) {
    function pam_log( $message ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $version = defined( 'PAM_VERSION' ) ? PAM_VERSION : 'unknown';
            error_log( '[PAM v' . $version . '] ' . $message );
        }
    }
}

// ============================================================================
// CORE INITIALIZATION
// ============================================================================

/**
 * Initialize the plugin hooks
 * Runs on 'plugins_loaded' (EARLY) to ensure filters are registered before AJAX
 */
add_action( 'plugins_loaded', function () {
    pam_log( 'Plugins loaded - registering filters for user ' . ( is_user_logged_in() ? get_current_user_id() : 'guest' ) );
    
    // FiboSearch integration (TNT Search Engine - v1.31+)
    // Register these FIRST, before WooCommerce checks, so AJAX works
    // Filter product IDs early (most efficient - before full products loaded)
    add_filter( 'dgwt/wcas/tnt/search_results/ids', 'pam_filter_fibo_tnt_product_ids', 10, 2 );
    pam_log( 'Registered filter: dgwt/wcas/tnt/search_results/ids' );
    // Filter full product objects (backup if IDs filter doesn't catch everything)
    add_filter( 'dgwt/wcas/tnt/search_results/products', 'pam_filter_fibo_tnt_products', 10, 3 );
    // Filter individual suggestions (dropdown results)
    add_filter( 'dgwt/wcas/tnt/search_results/suggestion/product', 'pam_filter_fibo_tnt_product_suggestion', 10, 2 );
    add_filter( 'dgwt/wcas/tnt/search_results/suggestion/taxonomy', 'pam_filter_fibo_tnt_taxonomy_suggestion', 10, 2 );
    // Filter final output (catch-all) - run early so other filters can still modify
    add_filter( 'dgwt/wcas/tnt/search_results/output', 'pam_filter_fibo_tnt_output', 5, 1 );
    pam_log( 'Registered filter: dgwt/wcas/tnt/search_results/output' );
    
    // TEST: Add a simple inline filter to see if THIS gets called
    add_filter( 'dgwt/wcas/tnt/search_results/output', function( $output ) {
        error_log( '[PAM v' . ( defined('PAM_VERSION') ? PAM_VERSION : 'unknown' ) . '] === INLINE TNT OUTPUT FILTER CALLED ===' );
        return $output;
    }, 1, 1 );
    pam_log( 'Registered INLINE test filter for dgwt/wcas/tnt/search_results/output' );
    
    // Legacy FiboSearch hooks (for older versions)
    add_filter( 'dgwt/wcas/products', 'pam_filter_fibo_products', 10, 2 );
    add_filter( 'dgwt/wcas/suggestions', 'pam_filter_fibo_products', 10, 2 );
    add_filter( 'dgwt/wcas/suggestion', 'pam_filter_fibo_single', 10, 2 );
}, 5 ); // Priority 5 - run early

/**
 * Initialize WooCommerce-dependent hooks
 * Runs on 'init' to ensure WooCommerce is loaded
 */
add_action( 'init', function () {
    pam_log( 'Init hooks registered for user ' . ( is_user_logged_in() ? get_current_user_id() : 'guest' ) );
    
    if ( ! class_exists( 'WooCommerce' ) ) {
        return;
    }

    // Product visibility filters
    add_filter( 'woocommerce_product_is_visible', 'pam_restrict_product', 10, 2 );
    add_filter( 'woocommerce_variation_is_visible', 'pam_restrict_variation', 10, 4 );
    add_filter( 'woocommerce_is_purchasable', 'pam_block_purchase', 10, 2 );
    
    // Single product page protection
    add_action( 'template_redirect', 'pam_block_single_product' );
    
    // Query filters
    add_action( 'pre_get_posts', 'pam_filter_query' );
    add_filter( 'woocommerce_product_data_store_cpt_get_products_query', 'pam_filter_product_query', 10, 2 );
} );

/**
 * Register the [has_access_tag] shortcode
 */
add_shortcode( 'has_access_tag', 'pam_has_access_tag_shortcode' );

// ============================================================================
// PRODUCT VISIBILITY FILTERS
// ============================================================================

/**
 * Filter product visibility
 */
function pam_restrict_product( $visible, $product_id ) {
    return $visible && pam_user_can_view( $product_id );
}

/**
 * Filter variation visibility
 */
function pam_restrict_variation( $visible, $variation_id ) {
    return $visible && pam_user_can_view( $variation_id );
}

/**
 * Block purchasing of restricted products
 */
function pam_block_purchase( $purchasable, $product ) {
    return $purchasable && pam_user_can_view( $product );
}

/**
 * Block single product page access
 * Returns 404 for restricted products
 */
function pam_block_single_product() {
    if ( ! is_product() ) {
        return;
    }

    $product = wc_get_product( get_queried_object_id() );
    if ( $product && ! pam_user_can_view( $product ) ) {
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
// QUERY FILTERING
// ============================================================================

/**
 * Filter WP_Query to exclude restricted products
 */
function pam_filter_query( $query ) {
    if ( ! pam_should_filter_query( $query ) ) {
        return;
    }

    if ( pam_is_internal_lookup() ) {
        return;
    }

    $restricted_slugs = pam_get_restricted_tag_slugs();
    $restricted_ids   = pam_get_restricted_product_ids();

    // Filter by taxonomy query
    if ( ! empty( $restricted_slugs ) ) {
        $tax_query   = (array) $query->get( 'tax_query', array() );
        $tax_query[] = array(
            'taxonomy'         => 'product_tag',
            'field'            => 'slug',
            'terms'            => $restricted_slugs,
            'operator'         => 'NOT IN',
            'include_children' => false,
        );
        $query->set( 'tax_query', $tax_query );
    }

    // Filter by post exclusion
    if ( ! empty( $restricted_ids ) ) {
        $post__not_in = array_map( 'intval', (array) $query->get( 'post__not_in', array() ) );
        $post__not_in = array_unique( array_merge( $post__not_in, $restricted_ids ) );
        $query->set( 'post__not_in', $post__not_in );
    }
}

/**
 * Filter WooCommerce product query
 */
function pam_filter_product_query( $query_args, $query_vars ) {
    // Skip in admin (non-AJAX)
    if ( is_admin() && ! wp_doing_ajax() ) {
        return $query_args;
    }

    if ( pam_user_has_full_access() ) {
        return $query_args;
    }

    if ( pam_is_internal_lookup() ) {
        return $query_args;
    }

    $restricted_slugs = pam_get_restricted_tag_slugs();
    $restricted_ids   = pam_get_restricted_product_ids();

    // Add taxonomy query
    if ( ! empty( $restricted_slugs ) ) {
        if ( empty( $query_args['tax_query'] ) ) {
            $query_args['tax_query'] = array();
        }

        $query_args['tax_query'][] = array(
            'taxonomy'         => 'product_tag',
            'field'            => 'slug',
            'terms'            => $restricted_slugs,
            'operator'         => 'NOT IN',
            'include_children' => false,
        );
    }

    // Add product exclusions
    if ( ! empty( $restricted_ids ) ) {
        $query_args['exclude'] = array_unique( array_merge( (array) ( $query_args['exclude'] ?? array() ), $restricted_ids ) );
    }

    return $query_args;
}

/**
 * Determine if a query should be filtered
 */
function pam_should_filter_query( $query ) {
    if ( ! $query instanceof WP_Query ) {
        return false;
    }

    if ( is_admin() && ! wp_doing_ajax() ) {
        return false;
    }

    if ( pam_user_has_full_access() ) {
        return false;
    }

    $post_types = $query->get( 'post_type' );

    if ( empty( $post_types ) ) {
        if ( $query->is_post_type_archive( 'product' ) ) {
            return true;
        }

        if ( $query->is_tax( get_object_taxonomies( 'product' ) ) ) {
            return true;
        }

        return false;
    }

    $post_types = (array) $post_types;

    if ( $query->is_search() ) {
        $post_types = array_filter( $post_types );
        if ( empty( $post_types ) ) {
            return false;
        }
    }

    return in_array( 'product', $post_types, true );
}

// ============================================================================
// FIBOSEARCH INTEGRATION
// ============================================================================

/**
 * Filter FiboSearch products and suggestions
 */
function pam_filter_fibo_products( $items, $args ) {
    pam_log( '=== FIBOSEARCH FILTER CALLED ===' );
    pam_log( 'FiboSearch products filter start: items=' . count( $items ) . ' user=' . ( is_user_logged_in() ? get_current_user_id() : 'guest' ) );
    pam_log( 'Filter args: ' . print_r( $args, true ) );
    pam_log( 'First item structure: ' . print_r( isset( $items[0] ) ? $items[0] : 'no items', true ) );
    
    if ( pam_user_has_full_access() ) {
        pam_log( 'FiboSearch products filter: user has full access.' );
        return $items;
    }

    $user_keys      = pam_get_current_user_keys();
    $restricted_ids = pam_get_restricted_product_ids();
    $filtered       = array();
    
    pam_log( 'FiboSearch products filter: restricted IDs=' . ( empty( $restricted_ids ) ? 'none' : implode( ',', $restricted_ids ) ) . ' user keys=' . ( empty( $user_keys ) ? 'none' : implode( ',', $user_keys ) ) );

    foreach ( $items as $item ) {
        $product_id = pam_extract_product_id( $item );

        if ( $product_id ) {
            if ( ! empty( $restricted_ids ) && in_array( $product_id, $restricted_ids, true ) ) {
                pam_log( 'FiboSearch products filter: skip product ' . $product_id . ' (restricted).' );
                continue;
            }

            $product = wc_get_product( $product_id );
            if ( ! $product || ! pam_user_can_view( $product ) ) {
                pam_log( 'FiboSearch products filter: skip product ' . $product_id . ' (not viewable).' );
                continue;
            }

            $filtered[] = $item;
            continue;
        }

        $term_info = pam_extract_term_info( $item );

        if ( $term_info && ! pam_term_is_allowed( $term_info['taxonomy'], $term_info['term_id'], $user_keys ) ) {
            pam_log( 'FiboSearch products filter: skip term ' . $term_info['taxonomy'] . ':' . $term_info['term_id'] );
            continue;
        }

        $filtered[] = $item;
    }

    pam_log( 'FiboSearch products filter end: kept ' . count( $filtered ) . ' items.' );

    return array_values( $filtered );
}

/**
 * Filter single FiboSearch suggestion
 */
function pam_filter_fibo_single( $suggestion, $context ) {
    pam_log( 'FiboSearch single filter start: user=' . ( is_user_logged_in() ? get_current_user_id() : 'guest' ) );

    if ( empty( $suggestion ) || pam_user_has_full_access() ) {
        pam_log( 'FiboSearch single filter: returning suggestion as-is (empty or full access).' );
        return $suggestion;
    }

    $user_keys = pam_get_current_user_keys();

    $product_id = pam_extract_product_id( $suggestion );

    if ( $product_id ) {
        $restricted_ids = pam_get_restricted_product_ids();

        if ( ! empty( $restricted_ids ) && in_array( $product_id, $restricted_ids, true ) ) {
            pam_log( 'FiboSearch single filter: drop product ' . $product_id . ' (restricted).' );
            return false;
        }

        $product = wc_get_product( $product_id );
        if ( ! $product || ! pam_user_can_view( $product ) ) {
            pam_log( 'FiboSearch single filter: drop product ' . $product_id . ' (not viewable).' );
            return false;
        }

        pam_log( 'FiboSearch single filter: keep product ' . $product_id );
        return $suggestion;
    }

    $term_info = pam_extract_term_info( $suggestion );

    if ( $term_info && ! pam_term_is_allowed( $term_info['taxonomy'], $term_info['term_id'], $user_keys ) ) {
        pam_log( 'FiboSearch single filter: drop term ' . $term_info['taxonomy'] . ':' . $term_info['term_id'] );
        return false;
    }

    pam_log( 'FiboSearch single filter: keep non-product suggestion.' );

    return $suggestion;
}

// ============================================================================
// FIBOSEARCH TNT SEARCH ENGINE INTEGRATION (v1.31+)
// ============================================================================

/**
 * Filter FiboSearch TNT product IDs (EARLY - Most Efficient)
 * Hook: dgwt/wcas/tnt/search_results/ids
 * This runs BEFORE full product objects are loaded
 */
function pam_filter_fibo_tnt_product_ids( $ids, $phrase ) {
    pam_log( '=== TNT PRODUCT IDS FILTER CALLED ===' );
    pam_log( 'TNT IDs count: ' . count( $ids ) . ' | phrase: ' . $phrase );
    
    if ( pam_user_has_full_access() ) {
        pam_log( 'TNT IDs filter: user has full access' );
        return $ids;
    }

    $restricted_ids = pam_get_restricted_product_ids();
    if ( empty( $restricted_ids ) ) {
        pam_log( 'TNT IDs filter: no restricted products' );
        return $ids;
    }

    // Remove restricted IDs from the results
    $filtered = array_diff( $ids, $restricted_ids );
    
    pam_log( 'TNT IDs filter: removed ' . ( count( $ids ) - count( $filtered ) ) . ' restricted IDs' );
    pam_log( 'TNT IDs filter: kept ' . count( $filtered ) . ' of ' . count( $ids ) . ' IDs' );
    
    return array_values( $filtered ); // Re-index array
}

/**
 * Filter FiboSearch TNT products array
 * Hook: dgwt/wcas/tnt/search_results/products
 */
function pam_filter_fibo_tnt_products( $products, $phrase, $lang ) {
    pam_log( '=== TNT PRODUCTS FILTER CALLED ===' );
    pam_log( 'TNT products count: ' . count( $products ) . ' | phrase: ' . $phrase );
    
    if ( pam_user_has_full_access() ) {
        pam_log( 'TNT products filter: user has full access' );
        return $products;
    }

    $restricted_ids = pam_get_restricted_product_ids();
    if ( empty( $restricted_ids ) ) {
        pam_log( 'TNT products filter: no restricted products' );
        return $products;
    }

    $filtered = array();
    foreach ( $products as $product ) {
        $product_id = isset( $product->id ) ? (int) $product->id : 0;
        
        if ( $product_id && in_array( $product_id, $restricted_ids, true ) ) {
            pam_log( 'TNT products filter: skipping restricted product ID ' . $product_id );
            continue;
        }
        
        $filtered[] = $product;
    }

    pam_log( 'TNT products filter: kept ' . count( $filtered ) . ' of ' . count( $products ) . ' products' );
    return $filtered;
}

/**
 * Filter FiboSearch TNT product suggestion
 * Hook: dgwt/wcas/tnt/search_results/suggestion/product
 */
function pam_filter_fibo_tnt_product_suggestion( $suggestion, $product ) {
    pam_log( '=== TNT PRODUCT SUGGESTION FILTER CALLED ===' );
    
    if ( pam_user_has_full_access() ) {
        return $suggestion;
    }

    $product_id = isset( $product->id ) ? (int) $product->id : 0;
    if ( ! $product_id ) {
        return $suggestion;
    }

    $restricted_ids = pam_get_restricted_product_ids();
    if ( ! empty( $restricted_ids ) && in_array( $product_id, $restricted_ids, true ) ) {
        pam_log( 'TNT product suggestion filter: blocking product ID ' . $product_id );
        return false; // Return false to remove this suggestion
    }

    return $suggestion;
}

/**
 * Filter FiboSearch TNT taxonomy suggestion (brands, tags)
 * Hook: dgwt/wcas/tnt/search_results/suggestion/taxonomy
 */
function pam_filter_fibo_tnt_taxonomy_suggestion( $suggestion, $taxonomy ) {
    pam_log( '=== TNT TAXONOMY SUGGESTION FILTER CALLED ===' );
    pam_log( 'Taxonomy: ' . $taxonomy . ' | Suggestion: ' . print_r( $suggestion, true ) );
    
    if ( pam_user_has_full_access() ) {
        return $suggestion;
    }

    // Extract term information from suggestion
    $term_id = 0;
    if ( isset( $suggestion['term_id'] ) ) {
        $term_id = (int) $suggestion['term_id'];
    } elseif ( isset( $suggestion['value'] ) ) {
        // Sometimes value contains the term ID
        $term_id = is_numeric( $suggestion['value'] ) ? (int) $suggestion['value'] : 0;
    }

    if ( ! $term_id ) {
        pam_log( 'TNT taxonomy filter: could not extract term ID from suggestion' );
        return $suggestion;
    }

    $user_keys = pam_get_current_user_keys();
    if ( ! pam_term_is_allowed( $taxonomy, $term_id, $user_keys ) ) {
        pam_log( 'TNT taxonomy filter: blocking term ' . $taxonomy . ':' . $term_id );
        return false; // Return false to remove this suggestion
    }

    return $suggestion;
}

/**
 * Filter FiboSearch TNT final output (catch-all)
 * Hook: dgwt/wcas/tnt/search_results/output
 * This is the final output before sending to browser
 */
function pam_filter_fibo_tnt_output( $output ) {
    pam_log( '=== TNT OUTPUT FILTER CALLED ===' );
    pam_log( 'Output structure: ' . print_r( array_keys( $output ), true ) );
    
    if ( pam_user_has_full_access() ) {
        return $output;
    }

    $restricted_ids = pam_get_restricted_product_ids();
    $user_keys = pam_get_current_user_keys();
    
    // Filter suggestions array if it exists
    if ( isset( $output['suggestions'] ) && is_array( $output['suggestions'] ) ) {
        $original_count = count( $output['suggestions'] );
        $filtered = array();
        
        foreach ( $output['suggestions'] as $suggestion ) {
            // Skip restricted products
            if ( isset( $suggestion['type'] ) && $suggestion['type'] === 'product' ) {
                $id = isset( $suggestion['post_id'] ) ? (int) $suggestion['post_id'] : 0;
                if ( $id && ! empty( $restricted_ids ) && in_array( $id, $restricted_ids, true ) ) {
                    pam_log( 'TNT output filter: removing restricted product ID ' . $id );
                    continue;
                }
            }
            
            // Skip restricted taxonomies (brands, tags)
            if ( isset( $suggestion['type'] ) && in_array( $suggestion['type'], array( 'taxonomy', 'product_tag', 'brand' ), true ) ) {
                $term_id = isset( $suggestion['term_id'] ) ? (int) $suggestion['term_id'] : 0;
                $taxonomy = isset( $suggestion['taxonomy'] ) ? $suggestion['taxonomy'] : '';
                
                if ( $term_id && $taxonomy && ! pam_term_is_allowed( $taxonomy, $term_id, $user_keys ) ) {
                    pam_log( 'TNT output filter: removing restricted term ' . $taxonomy . ':' . $term_id );
                    continue;
                }
            }
            
            $filtered[] = $suggestion;
        }
        
        $output['suggestions'] = $filtered;
        pam_log( 'TNT output filter: kept ' . count( $filtered ) . ' of ' . $original_count . ' suggestions' );
    }
    
    return $output;
}

// ============================================================================
// ACCESS CONTROL LOGIC
// ============================================================================

/**
 * Check if current user can view a product
 * 
 * @param WC_Product|int $product Product object or ID
 * @return bool True if user can view, false otherwise
 */
function pam_user_can_view( $product ) {
    // Always allow in admin (non-AJAX)
    if ( is_admin() && ! wp_doing_ajax() ) {
        return true;
    }

    // Admins and shop managers always have access
    if ( pam_user_has_full_access() ) {
        return true;
    }

    $product_id   = $product instanceof WC_Product ? $product->get_id() : (int) $product;
    $product_keys = pam_collect_keys( $product_id, 'product_tag', array( 'product', 'products' ) );

    // If no access tags, product is public
    if ( empty( $product_keys ) ) {
        return true;
    }

    // Check if user has any matching key
    $user_keys = pam_get_current_user_keys();
    return (bool) array_intersect( $product_keys, $user_keys );
}

/**
 * Check if current user has full access (admin/shop manager)
 * 
 * @return bool True if user can manage WooCommerce or options
 */
function pam_user_has_full_access() {
    $user = wp_get_current_user();
    return $user && ( user_can( $user, 'manage_woocommerce' ) || user_can( $user, 'manage_options' ) );
}

/**
 * Get current user's access keys
 * 
 * @return array Array of access keys for the current user
 */
function pam_get_current_user_keys() {
    static $keys = null;

    if ( null !== $keys ) {
        return $keys;
    }

    $user = wp_get_current_user();
    if ( ! $user || 0 === $user->ID ) {
        $keys = array();
        return $keys;
    }

    if ( pam_user_has_full_access() ) {
        $keys = array( '*' );
        return $keys;
    }

    $keys = array();
    foreach ( (array) $user->roles as $role ) {
        $keys[] = pam_normalize_key( $role, array( 'role', 'user' ) );
    }

    $keys = array_filter( array_unique( $keys ) );
    return $keys;
}

/**
 * Check if a term is allowed for the user
 * 
 * @param string $taxonomy Taxonomy name
 * @param int $term_id Term ID
 * @param array $user_keys User's access keys
 * @return bool True if allowed
 */
function pam_term_is_allowed( $taxonomy, $term_id, $user_keys ) {
    static $cache = array();

    $cache_key = $taxonomy . ':' . (int) $term_id . ':' . md5( implode( '|', $user_keys ) );

    if ( isset( $cache[ $cache_key ] ) ) {
        return $cache[ $cache_key ];
    }

    $term = get_term( (int) $term_id, $taxonomy );

    if ( ! $term || is_wp_error( $term ) ) {
        $cache[ $cache_key ] = true;
        return true;
    }

    // Only filter access-* terms
    if ( 0 !== strpos( $term->slug, 'access-' ) ) {
        $cache[ $cache_key ] = true;
        return true;
    }

    $term_key = pam_normalize_key( $term->slug, array( 'brand', 'brands', 'product', 'products', 'tag', 'tags' ) );

    if ( '' === $term_key ) {
        $cache[ $cache_key ] = false;
        return false;
    }

    $cache[ $cache_key ] = in_array( $term_key, $user_keys, true );

    return $cache[ $cache_key ];
}

// ============================================================================
// INTERNAL LOOKUP FLAG
// ============================================================================

/**
 * Get/set internal lookup flag
 * Used to prevent infinite recursion during restricted product queries
 * 
 * @param bool|null $set Optional. Set the flag value
 * @return bool Current flag value
 */
function pam_is_internal_lookup( $set = null ) {
    static $flag = false;

    if ( null !== $set ) {
        $flag = (bool) $set;
    }

    return $flag;
}

// ============================================================================
// RESTRICTED PRODUCTS & TAGS CACHING
// ============================================================================

/**
 * Get restricted product IDs for current user
 * Results are cached per user
 * 
 * @return array Array of restricted product IDs
 */
function pam_get_restricted_product_ids() {
    static $cache = array();

    $user_id = get_current_user_id();
    $key     = $user_id ? $user_id : 'guest';

    if ( isset( $cache[ $key ] ) ) {
        return $cache[ $key ];
    }

    if ( pam_user_has_full_access() ) {
        $cache[ $key ] = array();
        return $cache[ $key ];
    }

    $restricted_slugs = pam_get_restricted_tag_slugs();

    if ( empty( $restricted_slugs ) ) {
        $cache[ $key ] = array();
        return $cache[ $key ];
    }

    // Set internal lookup flag to prevent recursion
    pam_is_internal_lookup( true );

    $query = new WP_Query(
        array(
            'post_type'        => 'product',
            'post_status'      => 'publish',
            'fields'           => 'ids',
            'nopaging'         => true,
            'suppress_filters' => true,
            'tax_query'        => array(
                array(
                    'taxonomy'         => 'product_tag',
                    'field'            => 'slug',
                    'terms'            => $restricted_slugs,
                    'operator'         => 'IN',
                    'include_children' => false,
                ),
            ),
        )
    );

    pam_is_internal_lookup( false );

    $cache[ $key ] = array_map( 'intval', (array) $query->posts );

    return $cache[ $key ];
}

/**
 * Get restricted tag slugs for current user
 * Returns tags starting with "access-" that user doesn't have access to
 * 
 * @return array Array of restricted tag slugs
 */
function pam_get_restricted_tag_slugs() {
    static $cache = array();

    $user_id  = get_current_user_id();
    $cache_id = $user_id ? $user_id : 'guest';

    if ( isset( $cache[ $cache_id ] ) ) {
        return $cache[ $cache_id ];
    }

    $user_keys = pam_get_current_user_keys();

    if ( in_array( '*', $user_keys, true ) ) {
        $cache[ $cache_id ] = array();
        return $cache[ $cache_id ];
    }

    $terms = get_terms(
        array(
            'taxonomy'   => 'product_tag',
            'hide_empty' => false,
            'fields'     => 'id=>slug',
        )
    );

    if ( is_wp_error( $terms ) ) {
        $cache[ $cache_id ] = array();
        return $cache[ $cache_id ];
    }

    $restricted = array();

    foreach ( $terms as $slug ) {
        // Only process access-* tags
        if ( 0 !== strpos( $slug, 'access-' ) ) {
            continue;
        }

        $key = pam_normalize_key( $slug, array( 'product', 'products' ) );

        if ( '' === $key ) {
            continue;
        }

        // If user doesn't have this key, it's restricted
        if ( ! in_array( $key, $user_keys, true ) ) {
            $restricted[] = $slug;
        }
    }

    $cache[ $cache_id ] = array_values( array_unique( $restricted ) );

    return $cache[ $cache_id ];
}

// ============================================================================
// UTILITY FUNCTIONS
// ============================================================================

/**
 * Extract product ID from various object types
 * 
 * @param mixed $item Product object, post, or array
 * @return int Product ID or 0
 */
function pam_extract_product_id( $item ) {
    if ( $item instanceof WC_Product ) {
        return $item->get_id();
    }

    if ( $item instanceof WP_Post ) {
        return $item->ID;
    }

    $type = null;

    if ( is_array( $item ) && isset( $item['type'] ) ) {
        $type = strtolower( (string) $item['type'] );
    } elseif ( is_object( $item ) && isset( $item->type ) ) {
        $type = strtolower( (string) $item->type );
    }

    if ( is_object( $item ) && isset( $item->post_id ) ) {
        return (int) $item->post_id;
    }

    if ( is_object( $item ) && isset( $item->product_id ) ) {
        return (int) $item->product_id;
    }

    if ( is_object( $item ) && isset( $item->id ) ) {
        if ( null === $type || in_array( $type, array( 'product', 'variation', 'simple', 'variable' ), true ) ) {
            return (int) $item->id;
        }
    }

    if ( is_array( $item ) ) {
        foreach ( array( 'product_id', 'post_id', 'ID' ) as $key ) {
            if ( isset( $item[ $key ] ) ) {
                return (int) $item[ $key ];
            }
        }

        if ( isset( $item['id'] ) ) {
            if ( null === $type || in_array( $type, array( 'product', 'variation', 'simple', 'variable' ), true ) ) {
                return (int) $item['id'];
            }
        }
    }

    return 0;
}

/**
 * Extract term information from various object types
 * 
 * @param mixed $item Term object or array
 * @return array|null Array with 'taxonomy' and 'term_id' or null
 */
function pam_extract_term_info( $item ) {
    $taxonomy = null;
    $term_id  = null;

    if ( is_array( $item ) ) {
        $taxonomy = $item['taxonomy'] ?? $item['tax'] ?? ( isset( $item['data']['taxonomy'] ) ? $item['data']['taxonomy'] : null );
        $term_id  = $item['term_id'] ?? $item['termId'] ?? ( isset( $item['data']['term_id'] ) ? $item['data']['term_id'] : ( isset( $item['data']['termId'] ) ? $item['data']['termId'] : null ) );

        if ( ! $term_id && isset( $item['id'] ) ) {
            $type = isset( $item['type'] ) ? strtolower( (string) $item['type'] ) : '';
            if ( in_array( $type, array( 'tag', 'tax', 'taxonomy', 'term', 'category', 'brand' ), true ) ) {
                $term_id = $item['id'];
            }
        }
    } elseif ( is_object( $item ) ) {
        $taxonomy = $item->taxonomy ?? $item->tax ?? null;
        if ( ! $taxonomy && isset( $item->data ) && is_array( $item->data ) ) {
            $taxonomy = $item->data['taxonomy'] ?? ( $item->data['tax'] ?? null );
        }

        $term_id = $item->term_id ?? $item->termId ?? null;
        if ( ! $term_id && isset( $item->data ) && is_array( $item->data ) ) {
            $term_id = $item->data['term_id'] ?? ( $item->data['termId'] ?? null );
        }

        if ( ! $term_id && isset( $item->id ) ) {
            $type = isset( $item->type ) ? strtolower( (string) $item->type ) : '';
            if ( in_array( $type, array( 'tag', 'tax', 'taxonomy', 'term', 'category', 'brand' ), true ) ) {
                $term_id = $item->id;
            }
        }
    }

    if ( $taxonomy && $term_id ) {
        return array(
            'taxonomy' => $taxonomy,
            'term_id'  => (int) $term_id,
        );
    }

    return null;
}

/**
 * Collect access keys from object's taxonomy terms
 * 
 * @param int $object_id Object ID (post/product)
 * @param string $taxonomy Taxonomy name
 * @param array $suffixes Suffixes to strip from keys
 * @return array Array of access keys
 */
function pam_collect_keys( $object_id, $taxonomy, $suffixes ) {
    static $cache = array();

    if ( isset( $cache[ $object_id ] ) ) {
        return $cache[ $object_id ];
    }

    $slugs = wp_get_post_terms( $object_id, $taxonomy, array( 'fields' => 'slugs' ) );
    $keys  = array();

    foreach ( $slugs as $slug ) {
        if ( 0 === strpos( $slug, 'access-' ) ) {
            $keys[] = pam_normalize_key( $slug, $suffixes );
        }
    }

    $keys = array_filter( array_unique( $keys ) );
    $cache[ $object_id ] = $keys;

    return $keys;
}

/**
 * Normalize access key by removing prefix and suffixes
 * 
 * @param string $slug Tag or role slug
 * @param array $suffixes Suffixes to strip
 * @return string Normalized key
 */
function pam_normalize_key( $slug, $suffixes ) {
    if ( 0 !== strpos( $slug, 'access-' ) ) {
        return '';
    }

    // Remove "access-" prefix
    $key = substr( $slug, 7 );

    // Remove known suffixes
    foreach ( (array) $suffixes as $suffix ) {
        $suffix  = ltrim( (string) $suffix, '-' );
        $needle  = '-' . $suffix;
        $length  = strlen( $needle );
        $key_len = strlen( $key );

        if ( $length && $key_len > $length && substr( $key, -$length ) === $needle ) {
            $key = substr( $key, 0, $key_len - $length );
            break;
        }
    }

    return $key;
}

// ============================================================================
// SHORTCODE: [has_access_tag]
// ============================================================================

/**
 * Shortcode: [has_access_tag brands="vimergy,gaia"]
 * 
 * Returns "1" if the product has any tag whose slug is "access-<brand>"
 * or starts with "access-<brand>-". Otherwise returns "0".
 * 
 * Used for conditional display in The Plus addon.
 * 
 * @param array $atts Shortcode attributes
 * @return string "1" if has access tag, "0" otherwise
 */
function pam_has_access_tag_shortcode( $atts ) {
    $atts = shortcode_atts(
        array(
            'brands' => '',          // e.g. "vimergy,gaia"
            'prefix' => 'access-',   // tag prefix
        ),
        $atts,
        'has_access_tag'
    );

    // Resolve product ID (handles variations too)
    $pid = 0;
    if ( function_exists( 'wc_get_product' ) ) {
        global $product;
        if ( $product instanceof WC_Product ) {
            $pid = $product->get_id();
            if ( $product->is_type( 'variation' ) ) {
                $pid = $product->get_parent_id();
            }
        }
    }
    if ( ! $pid ) {
        $pid = get_queried_object_id() ?: get_the_ID();
    }
    if ( ! $pid ) {
        return '0';
    }

    // Get product tag slugs
    $slugs = wp_get_post_terms( $pid, 'product_tag', array( 'fields' => 'slugs' ) );
    if ( is_wp_error( $slugs ) || empty( $slugs ) ) {
        return '0';
    }

    // If no brands provided: match ANY tag starting with "access-"
    if ( trim( $atts['brands'] ) === '' ) {
        foreach ( $slugs as $slug ) {
            if ( strpos( $slug, $atts['prefix'] ) === 0 ) {
                return '1';
            }
        }
        return '0';
    }

    // Check provided brands (match any)
    $brands = array_filter( array_map( 'sanitize_title', array_map( 'trim', explode( ',', $atts['brands'] ) ) ) );
    foreach ( $brands as $brand ) {
        $needle = $atts['prefix'] . $brand; // e.g., "access-vimergy"
        foreach ( $slugs as $slug ) {
            if ( $slug === $needle || strpos( $slug, $needle . '-' ) === 0 ) {
                return '1';
            }
        }
    }

    return '0';
}

