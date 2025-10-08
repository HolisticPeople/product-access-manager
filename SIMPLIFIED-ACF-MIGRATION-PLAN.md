# Simplified ACF-Based Security Migration Plan
## Product Access Manager v2.0.0

**Date:** October 8, 2025  
**Priority:** Security & Compatibility  
**Approach:** ACF-based + Hidden by Default

---

## Executive Summary

**SIMPLIFIED APPROACH:**
- Use ACF (Advanced Custom Fields) instead of product tags
- No migration scripts needed (manual setup for ~10-20 Vimergy products)
- No shortcode needed (ACF conditions work directly in Elementor/The Plus)
- Remove ALL FiboSearch client-side filtering
- Hidden by default, reveal to authorized users

**Key Benefits:**
✅ Professional ACF-based configuration  
✅ No tag pollution  
✅ Direct ACF conditions in Elementor  
✅ Simpler, cleaner codebase  
✅ Fail-safe security  

---

## Phase 1: ACF Field Setup

### 1.1 Create ACF Field Group

**In WordPress Admin → Custom Fields → Add New**

**Field Group Settings:**
- Title: `Product Access Control`
- Location: `Post Type` is equal to `Product`

**Field Configuration:**
```
Field Label: Restrict to Brands
Field Name: pam_access_brands
Field Type: Checkbox
Choices:
  vimergy : Vimergy
  gaia : Gaia
  drcoussens : Dr. Coussens

Instructions: Select which brand roles are required to access this product. Leave empty for public products.
Default Value: (empty)
Layout: Vertical
Return Format: Value
```

### 1.2 Export ACF JSON (for version control)

ACF will auto-generate JSON in: `wp-content/uploads/acf-json/`

Copy to plugin for deployment:
```
product-access-manager/acf-json/group_pam_access.json
```

---

## Phase 2: Code Refactoring

### 2.1 Remove Tag-Based Code

**DELETE all functions that use product tags:**
- All `wp_get_post_terms()` calls for `product_tag`
- All string parsing for `access-*` tags
- Tag-based detection logic

### 2.2 Implement ACF-Based Functions

**New Core Functions:**

```php
/**
 * Check if product is restricted (has ACF brands set)
 */
function pam_is_restricted_product( $product_id ) {
    $brands = get_field( 'pam_access_brands', $product_id );
    return ! empty( $brands );
}

/**
 * Get required roles for a product
 * Returns array like: ['access-vimergy-product', 'access-gaia-product']
 */
function pam_get_required_roles( $product_id ) {
    $brands = get_field( 'pam_access_brands', $product_id );
    if ( empty( $brands ) ) {
        return [];
    }
    
    $roles = [];
    foreach ( $brands as $brand ) {
        $roles[] = 'access-' . $brand . '-product';
    }
    return $roles;
}

/**
 * Check if user can view product
 */
function pam_user_can_view( $product_id, $user_id = null ) {
    // Admin override - always allow
    if ( current_user_can( 'manage_woocommerce' ) ) {
        return true;
    }
    
    // Check if product is restricted
    $required_roles = pam_get_required_roles( $product_id );
    if ( empty( $required_roles ) ) {
        return true; // Not restricted - public product
    }
    
    // Get user
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    if ( ! $user_id ) {
        return false; // Not logged in
    }
    
    // Check if user has any required role
    $user = get_userdata( $user_id );
    foreach ( $required_roles as $role ) {
        if ( in_array( $role, $user->roles ) ) {
            return true;
        }
    }
    
    return false;
}
```

### 2.3 Reverse Visibility Filters

**BEFORE (v1.9.0 - Hide from unauthorized):**
```php
function pam_restrict_product( $visible, $product_id ) {
    if ( pam_user_can_view( $product_id ) ) {
        return $visible; // Keep visible
    }
    return false; // HIDE from unauthorized
}
```

**AFTER (v2.0.0 - Reveal to authorized):**
```php
function pam_reveal_product( $visible, $product_id ) {
    // If not restricted, use WC default visibility
    if ( ! pam_is_restricted_product( $product_id ) ) {
        return $visible;
    }
    
    // If restricted and user can view, REVEAL it
    if ( pam_user_can_view( $product_id ) ) {
        return true; // Override hidden status
    }
    
    // Otherwise keep WC default (hidden)
    return $visible;
}

// Update hook
add_filter( 'woocommerce_product_is_visible', 'pam_reveal_product', 10, 2 );
```

### 2.4 Simplify Query Filters

```php
function pam_filter_query( $query ) {
    // Skip for admins
    if ( current_user_can( 'manage_woocommerce' ) ) {
        return;
    }
    
    // Skip if not main query or not shop/archive
    if ( ! $query->is_main_query() || ! ( $query->is_shop() || $query->is_product_taxonomy() ) ) {
        return;
    }
    
    // WC will naturally exclude hidden products
    // We just need to potentially INCLUDE restricted products for authorized users
    
    if ( ! is_user_logged_in() ) {
        return; // Let WC hide everything
    }
    
    // Get user's accessible brands
    $user = wp_get_current_user();
    $accessible_brands = [];
    
    foreach ( $user->roles as $role ) {
        if ( strpos( $role, 'access-' ) === 0 ) {
            $brand = str_replace( ['access-', '-product'], '', $role );
            $accessible_brands[] = $brand;
        }
    }
    
    if ( empty( $accessible_brands ) ) {
        return; // No special access
    }
    
    // Add meta query to include products user can access
    $meta_query = $query->get( 'meta_query' ) ?: [];
    $meta_query[] = [
        'relation' => 'OR',
        [
            'key' => 'pam_access_brands',
            'compare' => 'NOT EXISTS', // Public products
        ],
        [
            'key' => 'pam_access_brands',
            'value' => $accessible_brands,
            'compare' => 'IN',
        ],
    ];
    
    $query->set( 'meta_query', $meta_query );
}
```

### 2.5 Remove FiboSearch Client-Side Filtering

**DELETE FILES:**
- `pam-fibosearch-filter.js`

**REMOVE from PHP:**
- All AJAX handlers for `pam_get_restricted_products`
- All `wp_enqueue_script` for FiboSearch filtering
- All `wp_localize_script` calls

**KEEP (Simplified):**
- Server-side FiboSearch hooks (to ensure proper indexing respects WC visibility)

```php
// Simplified FiboSearch integration
add_action( 'plugins_loaded', function () {
    // FiboSearch will naturally respect WC product visibility
    // Just ensure our reveal filter works during FiboSearch queries
    add_filter( 'dgwt/wcas/tnt/search_results/suggestion/product', 'pam_filter_fibo_product', 10, 2 );
}, 5 );

function pam_filter_fibo_product( $suggestion, $post_id ) {
    // If user can't view this product, hide it from FiboSearch
    if ( ! pam_user_can_view( $post_id ) ) {
        return false; // Remove from suggestions
    }
    return $suggestion;
}
```

---

## Phase 3: Manual Product Setup

### 3.1 Set ACF Field on Products

**For each Vimergy product:**

1. Edit product in WordPress admin
2. Scroll to "Product Access Control" field group
3. Check "Vimergy"
4. Update product

### 3.2 Set Products to Hidden

**For each restricted product:**

1. In product editor, scroll to "Product Data" → "General" tab
2. Find "Catalog visibility" (click "Edit" next to it in sidebar)
3. Select: **"Hidden"**
4. Update product

**OR use WP-CLI for bulk update:**

```bash
# Get list of all products with pam_access_brands set
wp post list \
  --post_type=product \
  --meta_key=pam_access_brands \
  --fields=ID,post_title

# For each ID, set to hidden:
wp post meta update <ID> _visibility hidden
wp post meta update <ID> _featured no
```

---

## Phase 4: Elementor Conditions

### 4.1 Remove Shortcode Approach

Since ACF can be used directly in The Plus conditions, we can:

**OPTION 1: Keep shortcode as backup (minimal)**
```php
// Simplified shortcode - just for backward compatibility
function pam_has_access_tag_shortcode( $atts ) {
    $atts = shortcode_atts(['brands' => ''], $atts, 'has_access_tag');
    
    global $product;
    if ( ! $product ) {
        return '0';
    }
    
    $product_brands = get_field( 'pam_access_brands', $product->get_id() );
    if ( empty( $product_brands ) ) {
        return '0';
    }
    
    if ( empty( $atts['brands'] ) ) {
        return '1'; // Has any restriction
    }
    
    $requested = array_map( 'trim', explode( ',', $atts['brands'] ) );
    return array_intersect( $requested, $product_brands ) ? '1' : '0';
}
```

**OPTION 2: Remove shortcode entirely**

Use The Plus ACF condition directly:
- Condition Type: `ACF Field`
- Field: `pam_access_brands`
- Operator: `contains`
- Value: `vimergy`

### 4.2 Remove Body Class (No Longer Needed)

Delete `pam_add_access_tag_body_class` function.

---

## Phase 5: Testing

### 5.1 Test Scenarios

| User Type | Shop Page | Search | Direct URL | Expected |
|-----------|-----------|--------|------------|----------|
| Logged out | No Vimergy products | No Vimergy | 404/Not found | ✅ Hidden |
| User with access-vimergy-product | Vimergy visible | Vimergy in results | Product loads | ✅ Visible |
| User without role | No Vimergy | No Vimergy | 404/Not found | ✅ Hidden |
| Admin | ALL products | ALL products | ALL load | ✅ Override |

### 5.2 FiboSearch Test

1. Logged out → Search "vimergy" → No results
2. Authorized user → Search "vimergy" → Results appear
3. Admin → Search "vimergy" → Results appear

### 5.3 Performance Test

- Check query times (should be faster with ACF meta queries)
- No N+1 issues
- FiboSearch response time

---

## Phase 6: Deployment

### 6.1 Backup

```bash
git tag v1.9.0-pre-acf-migration
git push origin v1.9.0-pre-acf-migration
```

### 6.2 Deploy Code

```bash
git add .
git commit -m "v2.0.0: ACF-based security architecture"
git push origin dev
# Wait for GitHub Actions
```

### 6.3 Post-Deployment Steps

1. **Create ACF field** in WordPress admin (staging)
2. **Manually set ACF fields** on Vimergy products
3. **Set products to hidden** visibility
4. **Reindex FiboSearch:**
   ```bash
   wp eval 'do_action("dgwt/wcas/indexer/start");'
   ```
5. **Clear caches:**
   ```bash
   wp cache flush
   wp elementor flush_css
   ```
6. **Test thoroughly**

---

## Phase 7: Documentation

### 7.1 Update AI-AGENT-GUIDE.md

Document:
- ACF field structure
- How to add new brands
- New architecture (hidden by default)
- Admin override behavior

### 7.2 Update README.md

Explain:
- ACF-based configuration
- How to use in Elementor conditions
- Security-first approach

---

## Simplified Architecture Diagram

```
Product Setup:
├─ ACF Field: pam_access_brands = ['vimergy']
├─ WC Visibility: Hidden
└─ Filters: reveal to authorized users

User Visits:
├─ Not logged in → WC hides product → 404
├─ Logged in without role → WC hides → 404
├─ Logged in with access-vimergy-product → Plugin reveals → Product visible
└─ Admin → Override → Always visible

FiboSearch:
├─ Respects WC visibility (hidden products excluded)
├─ Plugin reveals to authorized during search
└─ No client-side filtering needed
```

---

## Version 2.0.0 Changes Summary

### Added
✅ ACF field: `pam_access_brands`  
✅ Admin override (always visible)  
✅ Security-first (hidden by default)  

### Changed
🔄 Tag-based → ACF-based detection  
🔄 Hide from unauthorized → Reveal to authorized  
🔄 Products set to WC "hidden" visibility  

### Removed
❌ All tag parsing code  
❌ FiboSearch client-side JS filtering  
❌ Complex FiboSearch AJAX handlers  
❌ Body class generation  
❌ (Optional) Shortcode functionality  

### Performance
⚡ Faster (direct ACF meta queries)  
⚡ Fewer filters  
⚡ No client-side overhead  

---

## Timeline

- **Phase 1 (ACF Setup):** 30 minutes
- **Phase 2 (Code Refactor):** 2 hours
- **Phase 3 (Manual Setup):** 30 minutes (10-20 products)
- **Phase 4 (Elementor):** 15 minutes
- **Phase 5 (Testing):** 1 hour
- **Phase 6 (Deploy):** 30 minutes
- **Phase 7 (Docs):** 30 minutes

**Total:** ~5.5 hours

---

## Success Criteria

✅ ACF field created and working  
✅ Vimergy products restricted via ACF  
✅ Products hidden from unauthorized users  
✅ Authorized users see their products  
✅ Admins see all products  
✅ FiboSearch respects restrictions  
✅ No client-side filtering code  
✅ Clean, maintainable codebase  
✅ No performance regression  

---

## Ready to Begin?

**First Step:** Create ACF field in WordPress admin (staging).

Shall I proceed with Phase 1? 🚀
