# Case Sensitivity Analysis

## Question
Is the `site_catalog` ACF field case sensitive? Will `Vimergy_catalog` or `vimergy_catalog` match `access-vimergy-user`?

## Answer
**YES, it IS case sensitive. You MUST use the format with capital first letter: `Vimergy_catalog`**

## Why?

### The Conversion Logic

**1. Role → Catalog Conversion** (line 340-341):
```php
// "access-vimergy-user" → "Vimergy_catalog"
$brand = str_replace( array( 'access-', '-user' ), '', $role );
$catalog = ucfirst( $brand ) . '_catalog';
```

**Result**: `access-vimergy-user` → `Vimergy_catalog` (capital V)

**2. Catalog → Role Conversion** (line 637-638):
```php
// "Vimergy_catalog" → "access-vimergy-user"
$brand = strtolower( str_replace( '_catalog', '', $catalog ) );
$roles[] = 'access-' . $brand . '-user';
```

**Result**: Both `Vimergy_catalog` AND `vimergy_catalog` → `access-vimergy-user`

**3. Catalog Comparison** (line 366):
```php
if ( in_array( $cat, $user_accessible_catalogs, true ) ) {
```

**Note**: The `true` parameter makes this a **strict comparison** (case-sensitive).

## Test Scenarios

### ✅ Scenario 1: CORRECT - Capital First Letter
- **ACF Field**: `Vimergy_catalog`
- **Role**: `access-vimergy-user`
- **Role converts to**: `Vimergy_catalog`
- **Match**: `in_array( 'Vimergy_catalog', ['Vimergy_catalog'], true )` → **TRUE** ✅
- **Result**: User can see products

### ❌ Scenario 2: WRONG - All Lowercase
- **ACF Field**: `vimergy_catalog`
- **Role**: `access-vimergy-user`
- **Role converts to**: `Vimergy_catalog` (capital V due to `ucfirst()`)
- **Match**: `in_array( 'vimergy_catalog', ['Vimergy_catalog'], true )` → **FALSE** ❌
- **Result**: User CANNOT see products (even though they have the role!)

### ✅ Scenario 3: Capital in Role Name (doesn't matter)
- **ACF Field**: `Vimergy_catalog`
- **Role**: `access-Vimergy-user` (capital V in role)
- **Role converts to**: `Vimergy_catalog` (capital V preserved in brand, then `ucfirst()`)
- **Match**: `in_array( 'Vimergy_catalog', ['Vimergy_catalog'], true )` → **TRUE** ✅
- **Result**: User can see products

### ❌ Scenario 4: All Caps
- **ACF Field**: `VIMERGY_CATALOG`
- **Role**: `access-vimergy-user`
- **Role converts to**: `Vimergy_catalog` (capital V, rest lowercase)
- **Match**: `in_array( 'VIMERGY_CATALOG', ['Vimergy_catalog'], true )` → **FALSE** ❌
- **Result**: User CANNOT see products

## The Problem

### Why This Happens

1. **`ucfirst()`** always capitalizes only the FIRST letter and lowercases the rest
   - `ucfirst('vimergy')` → `Vimergy` ✅
   - `ucfirst('Vimergy')` → `Vimergy` ✅
   - `ucfirst('VIMERGY')` → `VIMERGY` ❌ (doesn't lowercase the rest!)

2. **Strict comparison (`true` parameter)** means exact string match required
   - `'Vimergy_catalog' === 'vimergy_catalog'` → FALSE
   - `'Vimergy_catalog' === 'Vimergy_catalog'` → TRUE

### Where It Breaks

The plugin assumes ACF values follow this format: `{Brand}_catalog` where `{Brand}` has:
- First letter capitalized
- Rest lowercase
- Examples: `Vimergy_catalog`, `Gaia_catalog`, `Test_catalog`

## Required Format

### ACF Field Choices
```
Vimergy_catalog : Vimergy Catalog     ✅ CORRECT
HP_catalog : HP Catalog                ✅ CORRECT (exception - all caps brand)
DCG_catalog : DCG Catalog              ✅ CORRECT (exception - all caps brand)
```

**WRONG formats** (will not work):
```
vimergy_catalog : Vimergy Catalog      ❌ WRONG - lowercase brand
VIMERGY_CATALOG : Vimergy Catalog      ❌ WRONG - all caps brand
ViMeRgY_catalog : Vimergy Catalog      ❌ WRONG - mixed case
```

### WordPress Roles
```
access-vimergy-user    ✅ CORRECT (all lowercase)
access-gaia-user       ✅ CORRECT
access-test-user       ✅ CORRECT
```

**Note**: Role names are always lowercase because:
1. WordPress role slugs are conventionally lowercase
2. The plugin uses `strtolower()` when converting catalog to role

## Recommendation

### Option 1: Keep Current Behavior (Document It)
- Document that ACF choices MUST use format: `{Brand}_catalog`
- First letter capital, rest lowercase
- Update `ADDING-NEW-CATALOGS.md` to emphasize this

### Option 2: Make It Case-Insensitive (Code Change)
Modify line 366 to use case-insensitive comparison:

```php
// Current (case-sensitive):
if ( in_array( $cat, $user_accessible_catalogs, true ) ) {

// Alternative (case-insensitive):
$cat_lower = strtolower( $cat );
$accessible_lower = array_map( 'strtolower', $user_accessible_catalogs );
if ( in_array( $cat_lower, $accessible_lower, true ) ) {
```

### Option 3: Normalize ACF Values on Save
Add a hook to automatically normalize ACF values when saved:
```php
add_filter( 'acf/update_value/name=site_catalog', function( $value ) {
    if ( is_array( $value ) ) {
        return array_map( function( $cat ) {
            $brand = str_replace( '_catalog', '', $cat );
            return ucfirst( strtolower( $brand ) ) . '_catalog';
        }, $value );
    }
    $brand = str_replace( '_catalog', '', $value );
    return ucfirst( strtolower( $brand ) ) . '_catalog';
}, 10, 1 );
```

## Current Status

**Implementation**: Option 1 (Document the requirement)

**Format Required**: 
- ACF: `Vimergy_catalog` (capital V, lowercase rest)
- Role: `access-vimergy-user` (all lowercase)

**Documentation Updated**: 
- This file explains the case sensitivity
- `ADDING-NEW-CATALOGS.md` should be updated to emphasize format

## Testing Your Setup

To verify your ACF field values are correct:

1. **Check ACF field choices** in WordPress admin:
   - Settings > Custom Fields > Product Access Control
   - Verify format: `{Brand}_catalog` with capital first letter

2. **Check product ACF values**:
   ```bash
   wp post meta get PRODUCT_ID site_catalog
   ```
   Should return: `Vimergy_catalog` (NOT `vimergy_catalog`)

3. **Check role names**:
   ```bash
   wp role list --fields=role
   ```
   Should see: `access-vimergy-user` (all lowercase)

4. **Check user roles**:
   ```bash
   wp user get USERNAME --field=roles
   ```
   Should see: `access-vimergy-user` in the list

