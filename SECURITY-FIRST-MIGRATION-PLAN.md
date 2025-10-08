# Security-First Architecture Migration Plan
## Product Access Manager v2.0.0

**Date:** October 8, 2025  
**Priority:** Security & Compatibility  
**Approach:** Hidden by Default, Show to Authorized

---

## Executive Summary

Migrating from "Visible by Default" to "Hidden by Default" architecture for maximum security and 3rd party compatibility.

**Core Change:**
- Products with `access-*` tags will be set to `exclude-from-catalog` visibility
- Plugin will ADD visibility for users with matching roles
- Fail-safe: If plugin fails → products stay hidden (secure)

---

## Phase 1: Preparation & Backup

### 1.1 Create Backup Tag
```bash
git tag v1.9.0-pre-security-migration
git push origin v1.9.0-pre-security-migration
```

### 1.2 Document Current State
- Total products with `access-*` tags: TBD
- Current visibility settings: TBD
- Active user roles: TBD

### 1.3 Create Rollback Script
Store SQL to revert product visibility if needed.

---

## Phase 2: Architecture Changes

### 2.1 Reverse Core Filters

**File:** `product-access-manager.php`

**BEFORE (Current):**
```php
function pam_restrict_product( $visible, $product_id ) {
    if ( pam_user_can_view( $product_id ) ) {
        return $visible; // Keep visible if user has access
    }
    return false; // HIDE from unauthorized users
}
```

**AFTER (New):**
```php
function pam_reveal_product( $visible, $product_id ) {
    // If product is NOT restricted, keep WC default visibility
    if ( ! pam_is_restricted_product( $product_id ) ) {
        return $visible;
    }
    
    // If restricted and user has access, SHOW it
    if ( pam_user_can_view( $product_id ) ) {
        return true; // REVEAL to authorized users
    }
    
    // Otherwise keep it hidden (WC default)
    return false;
}
```

### 2.2 Add Admin Override

Admins and shop managers should ALWAYS see restricted products:

```php
function pam_user_can_view( $product_id ) {
    // Admin override - always allow
    if ( current_user_can( 'manage_woocommerce' ) ) {
        return true;
    }
    
    // Existing role check logic...
}
```

### 2.3 Simplify Query Filters

Since products will be hidden by WC visibility, we can SIMPLIFY query filters:

```php
function pam_filter_query( $query ) {
    // Only modify for non-admins
    if ( current_user_can( 'manage_woocommerce' ) ) {
        return;
    }
    
    // For authorized users: modify tax_query to INCLUDE their restricted products
    // For unauthorized: WC will naturally exclude them (already hidden)
}
```

### 2.4 Remove FiboSearch Client-Side Filtering

**DELETE:**
- `pam-fibosearch-filter.js` file
- All FiboSearch AJAX handlers
- All client-side filtering code

**KEEP:**
- Server-side FiboSearch hooks (for proper indexing)

---

## Phase 3: Product Visibility Migration

### 3.1 Identify Restricted Products

```bash
wp post list \
  --post_type=product \
  --meta_key=_visibility \
  --fields=ID,post_title \
  --format=csv \
  > restricted-products-before.csv
```

### 3.2 Set Products to Hidden

Create migration function:

```php
function pam_migrate_to_hidden_visibility() {
    $products = wc_get_products([
        'limit' => -1,
        'return' => 'ids',
    ]);
    
    $migrated = 0;
    foreach ( $products as $product_id ) {
        // Check if product has any access-* tags
        $tags = wp_get_post_terms( $product_id, 'product_tag', ['fields' => 'slugs'] );
        $has_access_tag = false;
        
        foreach ( $tags as $tag ) {
            if ( strpos( $tag, 'access-' ) === 0 ) {
                $has_access_tag = true;
                break;
            }
        }
        
        if ( $has_access_tag ) {
            // Set to hidden (exclude from catalog and search)
            $product = wc_get_product( $product_id );
            $product->set_catalog_visibility( 'hidden' );
            $product->save();
            $migrated++;
            
            error_log( "PAM Migration: Set product $product_id to hidden" );
        }
    }
    
    return $migrated;
}
```

### 3.3 Run Migration via WP-CLI

```bash
wp eval 'echo pam_migrate_to_hidden_visibility() . " products migrated\n";'
```

### 3.4 Verify Migration

```bash
wp post list \
  --post_type=product \
  --meta_key=_visibility \
  --fields=ID,post_title \
  --format=csv \
  > restricted-products-after.csv
```

---

## Phase 4: FiboSearch Integration

### 4.1 Update Indexing

FiboSearch will now naturally exclude hidden products from indexing.

### 4.2 Reindex FiboSearch

```bash
# Via WordPress admin or WP-CLI
wp eval 'do_action("dgwt/wcas/indexer/start");'
```

### 4.3 Test Search Results

- Logged out users: Should see NO restricted products
- Authorized users: Should see their restricted products
- Admins: Should see ALL products

---

## Phase 5: Testing Plan

### 5.1 Staging Tests

| Test Case | Expected Result |
|-----------|----------------|
| Logged out user browses shop | NO restricted products visible |
| Logged out user searches FiboSearch | NO restricted products in results |
| Logged out user visits direct product URL | 404 or "Product not found" |
| User WITH `access-vimergy-product` role | Vimergy products visible in shop/search |
| User WITHOUT role | Vimergy products hidden |
| Admin user | ALL products visible everywhere |
| Shop manager | ALL products visible everywhere |

### 5.2 Performance Tests

- Measure query time for shop page
- Measure FiboSearch response time
- Check for N+1 query issues

### 5.3 Compatibility Tests

- WooCommerce Analytics
- Product feeds (if any)
- Backup/sync plugins
- SEO plugins (ensure they respect visibility)

---

## Phase 6: Deployment

### 6.1 Pre-Deployment Checklist

- [ ] All tests pass on staging
- [ ] Migration script tested
- [ ] Rollback script ready
- [ ] Admin notified
- [ ] Backup confirmed

### 6.2 Deployment Steps (Production)

```bash
# 1. Push code to production
git checkout main
git merge dev
git push origin main

# Trigger manual GitHub Actions deployment

# 2. Run migration script
ssh -p 62071 holisticpeoplecom@35.236.219.140 \
  "cd /www/holisticpeoplecom_349/public && \
   wp eval 'require_once(\"wp-content/plugins/product-access-manager/product-access-manager.php\"); \
   echo pam_migrate_to_hidden_visibility() . \" products migrated\n\";'"

# 3. Reindex FiboSearch
ssh -p 62071 holisticpeoplecom@35.236.219.140 \
  "cd /www/holisticpeoplecom_349/public && \
   wp eval 'do_action(\"dgwt/wcas/indexer/start\");'"

# 4. Clear all caches
ssh -p 62071 holisticpeoplecom@35.236.219.140 \
  "cd /www/holisticpeoplecom_349/public && \
   wp cache flush && \
   wp elementor flush_css"
```

### 6.3 Post-Deployment Verification

- [ ] Test as logged-out user
- [ ] Test as authorized user
- [ ] Test as admin
- [ ] Check FiboSearch results
- [ ] Monitor error logs

---

## Phase 7: Rollback Plan (If Needed)

### 7.1 Code Rollback

```bash
git checkout v1.9.0-pre-security-migration
git push origin dev --force
# Trigger deployment
```

### 7.2 Product Visibility Rollback

```bash
wp eval 'pam_rollback_visibility();'
```

Rollback function:
```php
function pam_rollback_visibility() {
    // Revert all hidden products back to visible
    $products = wc_get_products([
        'limit' => -1,
        'catalog_visibility' => 'hidden',
        'return' => 'ids',
    ]);
    
    foreach ( $products as $product_id ) {
        $product = wc_get_product( $product_id );
        $product->set_catalog_visibility( 'visible' );
        $product->save();
    }
}
```

---

## Phase 8: Documentation Updates

### 8.1 Update AI-AGENT-GUIDE.md

Document the new architecture and how visibility works.

### 8.2 Update README.md

Explain the security-first approach.

### 8.3 Update Plugin Description

Reflect the new "hidden by default" model.

---

## Expected Outcomes

### Benefits Achieved
✅ Fail-safe security (products hidden if plugin fails)  
✅ Better 3rd party compatibility  
✅ No more FiboSearch client-side filtering needed  
✅ Cleaner, simpler code  
✅ Better performance (less filtering)  

### Trade-offs Accepted
⚠️ No SEO visibility for restricted products  
⚠️ Admin overrides required for reports  
⚠️ Testing requires proper role setup  

---

## Version Number

**New Version:** `2.0.0`

This is a MAJOR version bump due to architectural change.

---

## Timeline Estimate

- **Phase 1 (Prep):** 15 minutes
- **Phase 2 (Code):** 2 hours
- **Phase 3 (Migration):** 30 minutes
- **Phase 4 (FiboSearch):** 30 minutes
- **Phase 5 (Testing):** 1 hour
- **Phase 6 (Deploy):** 30 minutes
- **Phase 7 (Monitor):** 1 hour

**Total:** ~6 hours

---

## Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Products disappear for admins | Low | High | Admin override built-in |
| Migration script fails | Low | High | Rollback script ready |
| Performance degradation | Very Low | Medium | Query optimization |
| User confusion | Low | Low | No user-facing changes |
| FiboSearch index corruption | Low | Medium | Reindex available |

---

## Success Criteria

✅ All restricted products hidden from unauthorized users  
✅ Authorized users see their products  
✅ Admins see all products  
✅ FiboSearch respects visibility  
✅ No performance regression  
✅ No errors in logs  

---

## Next Steps

1. **Review this plan** - Confirm approach
2. **Start Phase 1** - Create backups
3. **Implement Phase 2** - Code changes
4. **Test on staging** - Thorough testing
5. **Deploy to production** - With migration

**Ready to proceed?**
