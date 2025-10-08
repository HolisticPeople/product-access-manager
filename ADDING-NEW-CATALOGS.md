# Adding New Restricted Catalogs
## Product Access Manager - Quick Guide

**Version:** 2.4.0+  
**Updated:** October 8, 2025

---

## Overview

The Product Access Manager uses **AUTOMATIC DETECTION** for restricted catalogs. Adding a new restricted catalog (like Gaia, Dr. Coussens, etc.) requires **ZERO CODE CHANGES** - just add it to ACF and create the role!

**How It Works:**
- ✅ **Public Catalogs** are defined in code (HP_catalog, DCG_catalog)
- ✅ **ALL OTHER CATALOGS** are automatically restricted
- ✅ Plugin auto-detects catalogs from ACF field choices
- ✅ No code changes needed to add new restricted catalogs!

**Current Public Catalogs (Hardcoded):**
- ✅ `HP_catalog` → Public (visible to all)
- ✅ `DCG_catalog` → Public (visible to all)

**Current Restricted Catalogs (Auto-Detected):**
- ✅ `Vimergy_catalog` → Requires `access-vimergy-user` role
- ✅ Any other `XXX_catalog` you add → Requires `access-xxx-user` role

---

## How to Add a New Restricted Catalog

### Example: Adding "Gaia" as a restricted catalog

#### **STEP 1: Add to ACF Field Choices**

In WordPress Admin → Custom Fields → Edit `site_catalog` field:

```
Field Type: Checkbox (or Select)
Choices:
  Vimergy_catalog : Vimergy
  HP_catalog : Holistic People
  DCG_catalog : Dr. Coussens
  Gaia_catalog : Gaia         ← Add this line!

Return Format: Value
```

Save the field group.

**That's it for setup!** The plugin will auto-detect `Gaia_catalog` and:
- ✅ Automatically restrict it (not in public list)
- ✅ Automatically map to `access-gaia-user` role
- ✅ Apply all security filters

**NO CODE CHANGES NEEDED!** 🎉

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

### Auto-Detection System
```php
// Step 1: Define what's PUBLIC (everything else is restricted)
function pam_get_public_catalogs() {
    return array( 'HP_catalog', 'DCG_catalog' );
}

// Step 2: Auto-detect ALL catalogs from ACF field
function pam_get_all_catalogs() {
    // Reads from ACF field choices or database
    // Returns: ['Vimergy_catalog', 'HP_catalog', 'DCG_catalog', 'Gaia_catalog', ...]
}

// Step 3: Calculate restricted catalogs automatically
function pam_get_restricted_catalogs() {
    $all = pam_get_all_catalogs();
    $public = pam_get_public_catalogs();
    return array_diff( $all, $public ); // Restricted = All - Public
}
```

### Automatic Role Mapping
```
Catalog          →  Required Role (Auto-Mapped)
-------------------------------------------------
Vimergy_catalog  →  access-vimergy-user
Gaia_catalog     →  access-gaia-user
NewBrand_catalog →  access-newbrand-user
HP_catalog       →  (none - public)
DCG_catalog      →  (none - public)
```

**The mapping is automatic** - the code extracts the brand name and creates the role slug:
```php
// "Gaia_catalog" → "gaia" → "access-gaia-user"
$brand = strtolower( str_replace( '_catalog', '', $catalog ) );
$role = 'access-' . $brand . '-user';
```

---

## Example: Adding Multiple Catalogs

**ACF Field Choices:**
```
Vimergy_catalog : Vimergy
HP_catalog : Holistic People
DCG_catalog : Dr. Coussens
Gaia_catalog : Gaia
DrCoussens_catalog : Dr. Coussens Products
NewBrand_catalog : New Brand
```

**Auto-Detection Results:**
- **Public:** HP_catalog, DCG_catalog (hardcoded in `pam_get_public_catalogs()`)
- **Restricted:** Vimergy_catalog, Gaia_catalog, DrCoussens_catalog, NewBrand_catalog (auto-detected)

**Required Roles (automatically mapped):**
- `access-vimergy-user`
- `access-gaia-user`
- `access-drcoussens-user`
- `access-newbrand-user`

**Zero code changes!** Just add to ACF and the system adapts automatically.

---

## What Stays PUBLIC?

Only catalogs **explicitly listed in `pam_get_public_catalogs()`** remain public:

```php
function pam_get_public_catalogs() {
    return array(
        'HP_catalog',
        'DCG_catalog',
        // Add more public catalogs here if needed
    );
}
```

**Everything else is automatically RESTRICTED!** 🔒

```
Public (Hardcoded):
- HP_catalog        → Visible to all
- DCG_catalog       → Visible to all

Restricted (Auto-Detected):
- Vimergy_catalog   → Requires access-vimergy-user
- Gaia_catalog      → Requires access-gaia-user  
- ANY other XXX_catalog → Requires access-xxx-user
```

---

## Deployment Checklist

When adding a new restricted catalog:

- [ ] Add catalog to ACF field choices (e.g., `Gaia_catalog : Gaia`)
- [ ] Create corresponding WordPress role (e.g., `access-gaia-user`)
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

**NO CODE CHANGES NEEDED!** ✅

---

## Version History

### v2.4.0 (Current) 🚀
✅ **AUTO-DETECTION** - Zero code changes to add catalogs!  
✅ Reads catalogs from ACF field choices dynamically  
✅ Inverse approach: Define public, everything else restricted  
✅ `pam_get_all_catalogs()` - Discovers catalogs automatically  
✅ `pam_get_public_catalogs()` - Only HP/DCG hardcoded  
✅ `pam_get_restricted_catalogs()` - Calculates automatically  

### v2.3.1
✅ Centralized `pam_get_restricted_catalogs()` function  
✅ Single source of truth for all restricted catalogs  
✅ Easy to add new catalogs (1 line change)  

### v2.3.0
✅ HP/DCG explicitly made public  
✅ Only Vimergy restricted  

---

## Questions?

**Q: Do I need to change any code to add a new restricted catalog?**  
A: **NO!** Just add the catalog to ACF field choices. The plugin auto-detects it!

**Q: Do I need to change the JavaScript file?**  
A: No! It automatically uses the restricted brands from the PHP function.

**Q: What if I want to make HP or DCG restricted?**  
A: Remove them from `pam_get_public_catalogs()`. Then they'll be auto-detected as restricted!

**Q: What if I want to add a new PUBLIC catalog?**  
A: Add it to `pam_get_public_catalogs()` array in the code.

**Q: Can I have a product in multiple catalogs?**  
A: Yes! The ACF field supports multiple values. If ANY catalog is restricted, the product becomes restricted.

**Q: What happens if a product is in both a restricted and public catalog?**  
A: It becomes restricted. Security first!

**Q: Do users need ALL roles or just ONE?**  
A: Just ONE. If a product requires `access-vimergy-user` OR `access-gaia-user`, having either role grants access.

**Q: How does the plugin discover new catalogs?**  
A: It reads from ACF field choices first, then fallbacks to checking existing products in the database.

---

## Ready to Add a New Catalog?

**ZERO-CODE Workflow:**
1. Add catalog to ACF field choices (e.g., `Gaia_catalog : Gaia`)
2. Create `access-gaia-user` role in WordPress
3. Set products' `site_catalog` ACF field to `Gaia_catalog`
4. Done! 🎉

**NO CODE CHANGES!** The plugin auto-detects and handles everything!

