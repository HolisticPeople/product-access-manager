# Adding New Restricted Catalogs
## Product Access Manager - Quick Guide

**Version:** 2.3.1+  
**Updated:** October 8, 2025

---

## Overview

The Product Access Manager uses a **centralized configuration** for restricted catalogs. Adding a new restricted catalog (like Gaia, Dr. Coussens, etc.) is a simple 4-step process.

**Current Restricted Catalogs:**
- ✅ `Vimergy_catalog` → Requires `access-vimergy-user` role

**Public Catalogs (No Restrictions):**
- ✅ `HP_catalog` → Public (visible to all)
- ✅ `DCG_catalog` → Public (visible to all)

---

## How to Add a New Restricted Catalog

### Example: Adding "Gaia" as a restricted catalog

#### **STEP 1: Update Code (1 line change!)**

Edit `product-access-manager.php` and find the `pam_get_restricted_catalogs()` function (around line 116):

```php
function pam_get_restricted_catalogs() {
    return array(
        'Vimergy_catalog',
        // Add future restricted catalogs here:
        'Gaia_catalog',        // ← Add this line
        // 'NewBrand_catalog',
    );
}
```

**That's it for code changes!** The centralized function is used by all 3 core functions:
- `pam_is_restricted_product()` ✅
- `pam_get_required_roles()` ✅
- `pam_get_restricted_brand_names()` ✅

---

#### **STEP 2: Create User Role**

Create a new WordPress user role with the naming pattern: `access-{brand}-user`

**For Gaia:**
```php
// In WordPress, create role:
Role Name: Access Gaia User
Role Slug: access-gaia-user
Capabilities: Same as "Customer" role
```

**Or use WP-CLI:**
```bash
wp role create access-gaia-user "Access Gaia User" --clone=customer
```

**Naming Convention:**
- Catalog: `Gaia_catalog` (first letter uppercase)
- Role: `access-gaia-user` (all lowercase)
- The plugin automatically maps between them

---

#### **STEP 3: Set Product ACF Fields**

For each Gaia product:

1. Edit product in WordPress admin
2. Find the ACF field: `site_catalog`
3. Add value: `Gaia_catalog`
4. Update product

**Or use WP-CLI for bulk update:**
```bash
# Example: Set products with tag "gaia" to Gaia_catalog
wp post list --post_type=product --tag=gaia --fields=ID | xargs -I % wp post meta add % site_catalog Gaia_catalog
```

---

#### **STEP 4: Set Product Visibility**

Set Gaia products to "Search" visibility:

**Option A - WordPress Admin:**
1. Edit each Gaia product
2. Product Data → Catalog visibility
3. Select: **"Search results only"**
4. Update

**Option B - WP-CLI Bulk:**
```bash
# Find all Gaia products
wp post list \
  --post_type=product \
  --meta_key=site_catalog \
  --meta_value=Gaia_catalog \
  --fields=ID,post_title

# Set visibility to "search"
wp post meta update <ID> _visibility search
wp term-relationships create <ID> exclude-from-catalog product_visibility
```

---

#### **STEP 5: Reindex FiboSearch & Clear Cache**

```bash
# Reindex FiboSearch
wp eval 'do_action("dgwt/wcas/indexer/start");'

# Clear all caches
wp cache flush
```

---

## Testing New Catalog

### Test as Logged-Out User
```bash
# Expected: No Gaia products in search results
Search "gaia" → No results
Visit Gaia product URL → 404 error
```

### Test as User WITH access-gaia-user Role
```bash
# Expected: Gaia products visible
Search "gaia" → Results shown
Visit Gaia product → Loads successfully
Can add to cart → Works
```

### Test as User WITHOUT access-gaia-user Role
```bash
# Expected: No Gaia products visible
Search "gaia" → No results
Visit Gaia product → 404 error
```

### Test as Admin
```bash
# Expected: All products always visible
Search "gaia" → Results shown
All catalogs visible → Works
```

---

## Code Architecture (How It Works)

### Single Source of Truth
```php
function pam_get_restricted_catalogs() {
    return array(
        'Vimergy_catalog',
        'Gaia_catalog',        // Easy to add!
        // 'NewBrand_catalog',  // Easy to add!
    );
}
```

### Automatic Role Mapping
```
Catalog          →  Required Role
-----------------------------------------
Vimergy_catalog  →  access-vimergy-user
Gaia_catalog     →  access-gaia-user
NewBrand_catalog →  access-newbrand-user
```

**The mapping is automatic** - the code extracts the brand name and creates the role slug:
```php
// "Gaia_catalog" → "gaia" → "access-gaia-user"
$brand = strtolower( str_replace( '_catalog', '', $catalog ) );
$role = 'access-' . $brand . '-user';
```

---

## Example: Adding Multiple Catalogs

```php
function pam_get_restricted_catalogs() {
    return array(
        'Vimergy_catalog',
        'Gaia_catalog',
        'DrCoussens_catalog',
        'NewBrand_catalog',
    );
}
```

**Required Roles (automatically):**
- `access-vimergy-user`
- `access-gaia-user`
- `access-drcoussens-user`
- `access-newbrand-user`

**No other code changes needed!** The entire system adapts automatically.

---

## What Stays PUBLIC?

Any catalog **NOT in the `pam_get_restricted_catalogs()` array** remains public:

```php
// These are PUBLIC (not in restricted list):
- HP_catalog        → Visible to all
- DCG_catalog       → Visible to all
- Supplements_catalog → Visible to all
- Any other catalog → Visible to all
```

---

## Deployment Checklist

When adding a new restricted catalog:

- [ ] Update `pam_get_restricted_catalogs()` function
- [ ] Create corresponding WordPress role
- [ ] Set products' ACF `site_catalog` field
- [ ] Set products' WC visibility to "search"
- [ ] Reindex FiboSearch
- [ ] Clear all caches
- [ ] Test as logged-out user
- [ ] Test as authorized user
- [ ] Test as unauthorized user
- [ ] Deploy to staging first
- [ ] Test thoroughly on staging
- [ ] Deploy to production

---

## Version History

### v2.3.1 (Current)
✅ Centralized `pam_get_restricted_catalogs()` function  
✅ Single source of truth for all restricted catalogs  
✅ Easy to add new catalogs (1 line change)  

### v2.3.0
✅ HP/DCG explicitly made public  
✅ Only Vimergy restricted  

---

## Questions?

**Q: Do I need to change the JavaScript file?**  
A: No! It automatically uses the restricted brands from the PHP function.

**Q: What if I want to make HP or DCG restricted?**  
A: Add `'HP_catalog'` to the `pam_get_restricted_catalogs()` array. That's it!

**Q: Can I have a product in multiple catalogs?**  
A: Yes! The ACF field supports multiple values. If ANY catalog is restricted, the product becomes restricted.

**Q: What happens if a product is in both a restricted and public catalog?**  
A: It becomes restricted. Security first!

**Q: Do users need ALL roles or just ONE?**  
A: Just ONE. If a product requires `access-vimergy-user` OR `access-gaia-user`, having either role grants access.

---

## Ready to Add a New Catalog?

**Simplest Workflow:**
1. Edit `product-access-manager.php` line ~118
2. Add `'YourBrand_catalog',` to the array
3. Create `access-yourbrand-user` role in WordPress
4. Done! 🎉

Everything else adapts automatically thanks to the centralized architecture.

