# Case Sensitivity Answer

## Question
Is the `site_catalog` ACF field case sensitive? Will `Vimergy_catalog` or `vimergy_catalog` match `access-vimergy-user`?

---

## ✅ Answer: YES, It IS Case Sensitive

**Required Format**: `Vimergy_catalog` (capital V, rest lowercase)

### Current Server Configuration ✅

Checked your staging server - the format is **CORRECT**:

```bash
wp post meta get 124037 site_catalog
# Returns:
array (
  0 => 'Vimergy_catalog',   ← Capital V, lowercase rest ✅
  1 => 'Test_catalog',       ← Capital T, lowercase rest ✅
)
```

**Your ACF field choices are already using the correct format!**

---

## How It Works

### The Matching Process

1. **User has role**: `access-vimergy-user`
2. **Plugin converts role to catalog**:
   ```php
   $brand = str_replace( array( 'access-', '-user' ), '', 'access-vimergy-user' );
   // Result: 'vimergy'
   
   $catalog = ucfirst( $brand ) . '_catalog';
   // Result: 'Vimergy_catalog' (capital V)
   ```

3. **Plugin checks product's ACF field**:
   ```php
   $product_catalog = get_field( 'site_catalog', $product_id );
   // Returns: 'Vimergy_catalog'
   ```

4. **Strict comparison**:
   ```php
   if ( in_array( 'Vimergy_catalog', ['Vimergy_catalog'], true ) ) {
       // TRUE ✅ - User can see product
   }
   ```

### Why Case Matters

The `in_array()` function uses **strict comparison** (third parameter `true`):
- `'Vimergy_catalog' === 'Vimergy_catalog'` → TRUE ✅
- `'Vimergy_catalog' === 'vimergy_catalog'` → FALSE ❌
- `'Vimergy_catalog' === 'VIMERGY_CATALOG'` → FALSE ❌

---

## Format Rules

### ✅ CORRECT Formats

**ACF Field Choices**:
```
Vimergy_catalog : Vimergy          ✅ Capital V, rest lowercase
Gaia_catalog : Gaia                ✅ Capital G, rest lowercase
Test_catalog : Test                ✅ Capital T, rest lowercase
HP_catalog : Holistic People       ✅ Exception: all caps acronym
DCG_catalog : Dr. Coussens         ✅ Exception: all caps acronym
```

**WordPress Roles**:
```
access-vimergy-user                ✅ All lowercase
access-gaia-user                   ✅ All lowercase
access-test-user                   ✅ All lowercase
```

### ❌ WRONG Formats (Will NOT Work)

**ACF Field Choices**:
```
vimergy_catalog : Vimergy          ❌ All lowercase - NO MATCH
VIMERGY_CATALOG : Vimergy          ❌ All uppercase - NO MATCH
ViMeRgY_catalog : Vimergy          ❌ Mixed case - NO MATCH
vimergy : Vimergy                  ❌ Missing _catalog - NO MATCH
```

**WordPress Roles**:
```
access-Vimergy-user                ⚠️ Works, but not recommended
Access-Vimergy-User                ❌ Role slugs should be lowercase
```

---

## Real-World Scenarios

### ✅ Scenario 1: Everything Correct (Your Current Setup)
- **ACF**: `Vimergy_catalog`
- **Role**: `access-vimergy-user`
- **Result**: User can see Vimergy products ✅

### ❌ Scenario 2: Lowercase ACF Value
- **ACF**: `vimergy_catalog` (lowercase v)
- **Role**: `access-vimergy-user`
- **Plugin converts role to**: `Vimergy_catalog` (capital V)
- **Comparison**: `'vimergy_catalog' === 'Vimergy_catalog'` → FALSE
- **Result**: User CANNOT see Vimergy products ❌

### ❌ Scenario 3: Uppercase ACF Value
- **ACF**: `VIMERGY_CATALOG` (all caps)
- **Role**: `access-vimergy-user`
- **Plugin converts role to**: `Vimergy_catalog`
- **Comparison**: `'VIMERGY_CATALOG' === 'Vimergy_catalog'` → FALSE
- **Result**: User CANNOT see Vimergy products ❌

### ✅ Scenario 4: Capital in Role (Still Works)
- **ACF**: `Vimergy_catalog`
- **Role**: `access-Vimergy-user` (capital V in role)
- **Plugin converts role to**: `Vimergy_catalog` (ucfirst preserves capital)
- **Comparison**: `'Vimergy_catalog' === 'Vimergy_catalog'` → TRUE
- **Result**: User can see Vimergy products ✅

---

## Testing Your Configuration

### 1. Check ACF Field Choices
WordPress Admin → Custom Fields → Edit "Site Catalog" field

**Should see**:
```
Vimergy_catalog : Vimergy
HP_catalog : Holistic People
DCG_catalog : Dr. Coussens
Test_catalog : Test
```

### 2. Check Product Values
```bash
wp post meta get PRODUCT_ID site_catalog
```

**Should return**: `Vimergy_catalog` (NOT `vimergy_catalog`)

### 3. Check WordPress Roles
```bash
wp role list --fields=role
```

**Should see**: `access-vimergy-user` (all lowercase)

### 4. Check User Roles
```bash
wp user get USERNAME --field=roles
```

**Should see**: `access-vimergy-user` in the array

### 5. Test Access
```bash
# Enable debug temporarily
# Edit product-access-manager.php: PAM_DEBUG = true

# Clear cache
wp cache flush

# Check logs while user browses
tail -f ~/logs/php-errors.log | grep PAM
```

**Look for**:
```
[PAM v2.15.0] User X has access to catalogs: Vimergy_catalog
[PAM v2.15.0] Product Y is RESTRICTED by catalog: Vimergy_catalog
[PAM v2.15.0] User X has role access-vimergy-user - allowing access
```

---

## Common Issues & Solutions

### Issue 1: "User has role but can't see products"

**Diagnosis**:
```bash
# Check product catalog
wp post meta get PRODUCT_ID site_catalog
# Should return: Vimergy_catalog

# Check user role
wp user get USERNAME --field=roles
# Should include: access-vimergy-user
```

**Possible Causes**:
1. ACF field value is wrong case (`vimergy_catalog` instead of `Vimergy_catalog`)
2. Role slug is wrong (`access-Vimergy-User` instead of `access-vimergy-user`)
3. Cache not cleared after role change

**Solution**:
```bash
# Fix ACF value (if wrong)
wp post meta update PRODUCT_ID site_catalog "Vimergy_catalog"

# Clear cache
wp cache flush

# Test again
```

### Issue 2: "ACF field shows lowercase value"

**Problem**: Someone manually entered `vimergy_catalog` in ACF

**Solution**: Edit the product in WordPress admin and re-save with correct format

**Prevention**: Document the format requirement for content managers

### Issue 3: "New catalog not working"

**Checklist**:
1. ✅ ACF choice added: `Brandname_catalog` (capital B, rest lowercase)
2. ✅ Role created: `access-brandname-user` (all lowercase)
3. ✅ Role assigned to user
4. ✅ Product has correct ACF value
5. ✅ Cache cleared: `wp cache flush`

---

## Documentation Updates

### Files Updated

1. **`ADDING-NEW-CATALOGS.md`**
   - Added case sensitivity warning section
   - Examples of correct and wrong formats
   - Explanation of why it matters

2. **`CASE-SENSITIVITY-TEST.md`** (NEW)
   - Detailed technical analysis
   - Code explanations
   - Test scenarios with outcomes

3. **`CASE-SENSITIVITY-ANSWER.md`** (This file)
   - Quick reference guide
   - Current server verification
   - Troubleshooting guide

---

## Summary

**Question**: Is it case sensitive?  
**Answer**: **YES**

**Your Current Setup**: ✅ **CORRECT** (`Vimergy_catalog`)

**Required Format**:
- ACF: `{Brand}_catalog` (Capital first letter, rest lowercase)
- Role: `access-{brand}-user` (All lowercase)

**Examples**:
- ✅ `Vimergy_catalog` + `access-vimergy-user` = Works
- ❌ `vimergy_catalog` + `access-vimergy-user` = Doesn't work
- ❌ `VIMERGY_CATALOG` + `access-vimergy-user` = Doesn't work

**Your site is already configured correctly!** Just maintain this format when adding new catalogs.

