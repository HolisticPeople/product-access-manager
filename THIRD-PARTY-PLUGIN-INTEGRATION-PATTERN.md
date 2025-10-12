# Third-Party Plugin Integration Pattern

## Overview
This document explains the **Hook Priority Strategy** discovered while integrating with FiboSearch, and how it can be applied to other third-party plugins.

## The Problem
Third-party plugins often use their own query systems that bypass standard WordPress/WooCommerce hooks. Fighting their architecture leads to:
- Complex workarounds
- Performance issues
- Unreliable filtering
- Maintenance headaches

## The Solution: Hook Priority Strategy

### Core Principle
**Don't fight the plugin's architecture - hook AFTER it and filter its results.**

### How It Works

1. **Research the Plugin**
   - Find the main file that handles the feature (e.g., `SearchPage.php`)
   - Check what hooks they use and at what priority
   - Identify what they set (`post__in`, `post__not_in`, custom query vars)

2. **Hook After Them**
   - Use the same hook but with priority +1
   - Filter their results array instead of trying to modify the query early

3. **Use Fast Filtering Methods**
   - `array_diff()` for removing items from arrays
   - `array_intersect()` for keeping only allowed items
   - Work with IDs, not full objects

## Real-World Example: FiboSearch

### Discovery Process

**Step 1: Found the file**
```
plugins/ajax-search-for-woocommerce-premium/includes/Engines/TNTSearchMySQL/SearchPage.php
```

**Step 2: Found their hook**
```php
add_action( 'pre_get_posts', array( $this, 'searchProducts' ), 900001 );
```

**Step 3: Saw what they set**
```php
$search->searchProducts();
$results = $search->getProducts( $orderby, $order );
$postIn = array_map( 'intval', wp_list_pluck( $results, 'post_id' ) );
$query->set( 'post__in', $postIn );  // <-- THIS IS KEY
```

### Our Implementation

**Hook at priority 900002** (right after FiboSearch's 900001):
```php
add_action( 'pre_get_posts', 'pam_filter_fibosearch_post_in', 900002 );

function pam_filter_fibosearch_post_in( $query ) {
    // Only run for main query
    if ( ! $query->is_main_query() ) {
        return;
    }
    
    // Only process if FiboSearch has set post__in
    $post_in = $query->get( 'post__in' );
    if ( empty( $post_in ) || ! is_array( $post_in ) ) {
        return;
    }
    
    // Check if this is a FiboSearch query
    if ( ! $query->get( 'dgwt_wcas' ) ) {
        return;
    }
    
    // Get blocked products from cache
    $blocked_products = pam_get_blocked_products_cached();
    
    // Filter out blocked products - FAST!
    $filtered_post_in = array_diff( $post_in, $blocked_products );
    
    // Update the query
    $query->set( 'post__in', array_values( $filtered_post_in ) );
}
```

### Why This Works

✅ **Runs after plugin** - FiboSearch does all the heavy lifting (search, score, sort)  
✅ **Simple filtering** - Just remove IDs from an array with `array_diff()`  
✅ **Uses cached data** - No duplicate queries  
✅ **Can't cause conflicts** - Very specific conditions  
✅ **Respects their architecture** - Works WITH the plugin, not against it  

## Applying This Pattern to Other Plugins

### Step-by-Step Guide

**1. Identify the Feature**
Example: Product slider, related products, search results

**2. Find the Code**
```bash
ssh user@server "find wp-content/plugins/PLUGIN_NAME -name '*.php' | xargs grep -l 'wc_get_products\|WP_Query\|pre_get_posts'"
```

**3. Check Hook Usage**
```bash
ssh user@server "grep -n 'add_action\|add_filter' PLUGIN_FILE | grep -E 'pre_get_posts|woocommerce_'"
```

**4. Find What They Set**
Look for:
- `$query->set( 'post__in', ... )`
- `$query->set( 'post__not_in', ... )`
- Custom query vars

**5. Hook After Them**
```php
add_action( 'THE_HOOK', 'your_filter_function', THEIR_PRIORITY + 1 );
```

**6. Filter Their Results**
```php
function your_filter_function( $query ) {
    // 1. Verify it's the right query
    if ( ! is_the_right_query( $query ) ) {
        return;
    }
    
    // 2. Get what they set
    $their_array = $query->get( 'post__in' );
    
    // 3. Get your blocked items (from cache!)
    $blocked = get_your_blocked_items();
    
    // 4. Filter their array
    $filtered = array_diff( $their_array, $blocked );
    
    // 5. Update query
    $query->set( 'post__in', array_values( $filtered ) );
}
```

## Investigation Results

### FiboSearch (SOLVED ✅)
- **Hook**: `pre_get_posts` at priority 900001
- **Sets**: `post__in` array with search results
- **Our Hook**: Priority 900002
- **Method**: Filter `post__in` with `array_diff()`
- **Result**: Perfect - no delays, server-side filtering

### Product Slider Pro (Already Optimal ✅)
- **Method**: Uses `wc_get_products()`
- **Our Hook**: `woocommerce_product_data_store_cpt_get_products_query`
- **Status**: Already caught at WooCommerce level
- **Result**: No changes needed

## Key Learnings

### 1. Hook Priorities Matter
Standard priorities:
- Early: 5-10 (general filtering)
- Normal: 10 (default)
- Late: 100-999 (overrides)
- Very Late: 900000+ (third-party plugins often use these)

**Strategy**: Research specific plugins and hook +1 higher

### 2. Filtering Arrays is Faster Than Query Modification
```php
// FAST ✅
$filtered = array_diff( $post_in, $blocked );

// SLOW ❌
// Complex query modification or multiple database calls
```

### 3. Always Use Cached Data
```php
// GOOD ✅
$blocked = pam_get_blocked_products_cached(); // 30-minute cache

// BAD ❌
$blocked = expensive_database_query(); // Every time
```

### 4. Be Specific in Conditionals
Don't run your filter for every query:
```php
// Check main query, post type, custom flags, etc.
if ( ! $query->is_main_query() ) return;
if ( ! $query->get( 'plugin_specific_flag' ) ) return;
```

## Common Patterns to Look For

### Pattern 1: `post__in` Array (like FiboSearch)
```php
$query->set( 'post__in', array( 1, 2, 3, 4 ) );
```
**Solution**: Hook after, filter with `array_diff()`

### Pattern 2: Direct `wc_get_products()` (like sliders)
```php
$products = wc_get_products( $args );
```
**Solution**: Hook `woocommerce_product_data_store_cpt_get_products_query`

### Pattern 3: Custom Loops (rare)
```php
foreach ( $products as $product ) {
    // Display
}
```
**Solution**: Plugin-specific filter on `$products` array

## Future Plugin Integration Checklist

When integrating with a new plugin:

- [ ] Find the feature's main PHP file
- [ ] Check what hooks they use (and priorities)
- [ ] Identify what they set (`post__in`, etc.)
- [ ] Hook at priority +1
- [ ] Filter their array with `array_diff()`
- [ ] Use cached blocked products list
- [ ] Add specific conditionals
- [ ] Test with guests, authorized users, and admins
- [ ] Document the integration in this file

## Tools for Investigation

### Find Hook Priorities
```bash
grep -n "add_action\|add_filter" PLUGIN_FILE.php | head -50
```

### Find What's Set in Queries
```bash
grep -n "query->set" PLUGIN_FILE.php
```

### Find Product Queries
```bash
grep -rn "wc_get_products\|WP_Query.*product" PLUGIN_DIR/
```

## Conclusion

**The key insight**: Third-party plugins do the heavy lifting. We just filter their final results using fast array operations and cached data. This approach is:
- More reliable
- Higher performance
- Easier to maintain
- Less likely to break on plugin updates

**Pattern**: Research → Find hook priority → Hook +1 → Filter array → Done

