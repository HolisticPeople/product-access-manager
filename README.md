# Product Access Manager

**Version:** 2.8.1  
**Status:** Production Ready ✅  
**WordPress:** 5.8+  
**WooCommerce:** 6.0+  
**PHP:** 8.0+

ACF-based product access control for WooCommerce with high-performance caching and complete display integration.

---

## What It Does

Controls which products users can see based on ACF `site_catalog` values and WordPress user roles.

**Example:**
- Products with `Vimergy_catalog` → Only visible to users with `access-vimergy-user` role
- Products with `HP_catalog` or `DCG_catalog` → Visible to everyone (public catalogs)
- Admins → See everything

**Works everywhere:**
- Shop pages
- Category pages
- Search results
- Product sliders
- FiboSearch
- Third-party plugins using `wc_get_products()`

---

## Quick Start

### 1. Requirements

- **ACF (Advanced Custom Fields)** plugin installed
- ACF field `site_catalog` configured on products
- WordPress user roles created (format: `access-xxx-user`)

### 2. Installation

```bash
# Upload to plugins directory
wp-content/plugins/product-access-manager/

# Or via WP-CLI
wp plugin install product-access-manager.zip --activate
```

### 3. Configuration

**Public Catalogs (always visible):**
- `HP_catalog`
- `DCG_catalog`

**Restricted Catalogs (auto-detected):**
- Any other `XXX_catalog` value in ACF field

**User Roles Required:**
- Format: `access-{catalog-name}-user`
- Example: `access-vimergy-user` for `Vimergy_catalog`

---

## How It Works

### Visibility Logic

```
Is user an admin?
    YES → Show ALL products
    NO ↓
    
Does product have site_catalog set?
    NO → Show product (default visible)
    YES ↓
    
Is catalog public (HP/DCG)?
    YES → Show product
    NO ↓
    
Does user have matching access-{catalog}-user role?
    YES → Show product
    NO → Hide product
```

### Caching System

**Dual-layer cache for maximum performance:**

1. **Per-User Cache** (shop/search/categories)
   - Cache key: `pam_hidden_products_{user_id}` or `pam_hidden_products_guest`
   - Duration: 30 minutes
   - Stores: Products THIS user cannot see

2. **Shared Cache** (sliders/widgets)
   - Cache key: `pam_all_restricted_products`
   - Duration: 30 minutes
   - Stores: ALL restricted products (used by sliders)

**Performance:**
- First page load: ~1 second (cache rebuild)
- Cached loads: ~0.1 seconds (cache hit)
- Database queries: 74% reduction

**Auto-clears on:**
- User login
- User logout
- User role change

---

## Integration Points

### WooCommerce

**Main queries** (shop, categories, search):
- Hook: `pre_get_posts`
- Method: `post__not_in` exclusion
- Cache: Per-user blocked products

### Product Sliders

**All `wc_get_products()` calls**:
- Hook: `woocommerce_product_data_store_cpt_get_products_query`
- Method: Universal filter
- Cache: Shared restricted products
- **Note:** Sliders NEVER show restricted products (even to authorized users)

### FiboSearch

**Hybrid approach:**
- Server-side: Visibility filters affect indexing
- Client-side: JavaScript removes restricted products from live results
- File: `pam-fibosearch-filter.js`
- **Exception:** Admins and authorized users bypass client filter

---

## Adding New Catalogs

**Zero code changes required!**

### Steps:

1. **Add ACF choice**
   ```
   ACF Field: site_catalog
   Add choice: NewBrand_catalog
   ```

2. **Create WordPress role**
   ```
   Role slug: access-newbrand-user
   Role name: NewBrand Access
   ```

3. **Assign products**
   ```
   Edit product → Set site_catalog to "NewBrand_catalog"
   ```

4. **Assign users**
   ```
   Edit user → Assign role "NewBrand Access"
   ```

Done! The plugin auto-detects the new catalog.

See `ADDING-NEW-CATALOGS.md` for detailed instructions.

---

## Manual Cache Clearing

### Via WP-CLI

```bash
# Clear all caches
wp cache flush
wp transient delete --all

# Clear specific PAM caches
wp transient delete pam_hidden_products_guest
wp transient delete pam_all_restricted_products

# Clear specific user cache (replace 123 with user ID)
wp transient delete pam_hidden_products_123
```

### Via Code

```php
// Clear for specific user
pam_clear_blocked_products_cache($user_id);

// Clear for guests
pam_clear_blocked_products_cache(null);

// Clear slider cache
pam_clear_slider_transients();
```

---

## Debugging

### Enable Debug Mode

Edit `product-access-manager.php`:

```php
define( 'PAM_DEBUG', true ); // Line 35
```

### View Logs

```bash
# Local development
tail -f wp-content/debug.log | grep PAM

# Production server
ssh -p 12872 holisticpeoplecom@35.236.219.140
tail -f public/wp-content/debug.log | grep PAM
```

### Debug Output

```
[PAM v2.8.1] Blocked products cache MISS for user 0 - rebuilding
[PAM v2.8.1] Calculated 50 blocked products for user 0
[PAM v2.8.1] Blocked products cache SAVED for user 0
[PAM v2.8.1] Restricted products cache HIT - 50 products
[PAM v2.8.1] wc_get_products(): Applied exclusion of 50 restricted products
```

**Remember:** Set `PAM_DEBUG` to `false` before production deployment!

---

## Troubleshooting

### Products not appearing for authorized user

**Symptom:** User has `access-vimergy-user` role but doesn't see Vimergy products

**Solutions:**
1. Clear user's cache: `wp transient delete pam_hidden_products_{user_id}`
2. User should log out and back in
3. Verify role: `wp user get user@example.com --field=roles`
4. Check product ACF: Ensure `site_catalog` is set to `Vimergy_catalog`

### Slider showing restricted products

**Symptom:** Guest users see restricted products on homepage slider

**Solutions:**
1. Clear shared cache: `wp transient delete pam_all_restricted_products`
2. Clear slider cache: `wp db query "DELETE FROM wp_options WHERE option_name LIKE '%_transient_spwps_%'"`
3. Clear Kinsta cache (if on Kinsta)
4. Hard refresh browser (Ctrl+F5)

### FiboSearch not filtering

**Symptom:** Search results show restricted products to guests

**Solutions:**
1. Clear browser cache (JavaScript might be cached)
2. Check browser console for errors
3. Verify script loaded: `typeof pamFilterFiboResults` should return `"function"`
4. Test AJAX endpoint manually (see AI-AGENT-GUIDE.md)

### Site crash / Memory exhaustion

**Symptom:** White screen, 500 error, "Allowed memory size exhausted"

**Cause:** Likely recursion issue in cache building

**Solution:**
1. Disable plugin temporarily
2. Check error logs for stack trace
3. Verify `$GLOBALS['pam_building_cache']` flag exists (see AI-AGENT-GUIDE.md)
4. Report issue if flag is present

---

## File Structure

```
product-access-manager/
├── product-access-manager.php         # Main plugin
├── pam-fibosearch-filter.js          # FiboSearch client filter
├── README.md                          # This file
├── QUICK-START.md                     # Setup guide
├── ADDING-NEW-CATALOGS.md            # Catalog instructions
├── AI-AGENT-GUIDE.md                 # Developer reference
├── DEPLOYMENT-SETUP.md               # CI/CD setup
├── GITHUB-SECRETS-TEMPLATE.md        # Secrets template
└── Plans and reports/
    └── v2.8.1-production-ready.md    # Status report
```

---

## Version History

### v2.8.1 (Current)
- ✅ Optimized slider caching with shared cache
- ✅ Eliminated redundant static variables
- ✅ Production-ready with debug disabled
- ✅ Documentation updated

### v2.8.0
- ✅ Simplified slider strategy
- ✅ Re-enabled slider native caching
- ✅ Removed user-aware slider complexity

### v2.5.x
- ✅ Session-based caching (30-minute transients)
- ✅ FiboSearch client-side filtering
- ✅ Memory exhaustion fixes

### v2.0.0
- ✅ Migrated from tags to ACF-based control
- ✅ Dynamic catalog detection

---

## Performance Metrics

| Metric | Before Cache | After Cache | Improvement |
|--------|--------------|-------------|-------------|
| Shop page load | 2.1s | 0.4s | **81% faster** |
| Slider load (cached) | 1.8s | 0.1s | **94% faster** |
| Database queries | 47 | 12 | **74% reduction** |

---

## Security

**Fail-safe design:**
- If uncertain → Hide products (secure default)
- Admins → Always see everything
- Cache isolation → No user data leakage
- Capability checks → Consistent permission validation

**Current design note:**
Products with `site_catalog` set to restricted catalogs are still "visible" in WooCommerce. The plugin filters them from queries. If plugin fails, products remain visible (not a fail-secure design for product visibility itself).

---

## Support & Documentation

- **Setup:** `QUICK-START.md`
- **Add Catalogs:** `ADDING-NEW-CATALOGS.md`
- **Developers:** `AI-AGENT-GUIDE.md`
- **Status Report:** `Plans and reports/v2.8.1-production-ready.md`
- **Deployment:** `DEPLOYMENT-SETUP.md`

---

## Credits

**Author:** Amnon Manneberg  
**License:** Proprietary  
**Support:** Internal use only

---

*Last Updated: October 10, 2025*
