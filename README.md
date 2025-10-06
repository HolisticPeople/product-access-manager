# Product Access Manager

**Version:** 1.0.0  
**Author:** Amnon Manneberg

## Description

A lightweight WooCommerce plugin that limits visibility and purchasing of products tagged with "access-*" to users with matching roles. Includes comprehensive FiboSearch integration and a shortcode for conditional stock display.

## Features

### Core Functionality
- **Role-Based Product Access**: Products tagged with `access-<brand>` are only visible to users with matching `access-<brand>` role
- **Automatic Filtering**: Works across all WooCommerce listings, search results, categories, brands, and tags
- **Purchase Protection**: Blocks purchasing and direct access (404) for restricted products
- **Admin Override**: Shop managers and administrators always have full access

### FiboSearch Integration
Comprehensive filtering for FiboSearch results including:
- Product search results
- Brand suggestions
- Category suggestions
- Tag suggestions
- Individual product suggestions

### Conditional Display Shortcode
`[has_access_tag brands="vimergy,gaia"]`

Returns `"1"` if the current product has any tag matching the specified brands, `"0"` otherwise.

**Use Cases:**
- Conditional stock display in The Plus addon
- Custom template logic based on product access tags
- Any conditional content display

## Installation

1. Upload the `product-access-manager.php` file to `/wp-content/plugins/product-access-manager/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Ensure WooCommerce is installed and active

## Usage

### Setting Up Product Access

1. **Create User Roles** (if needed):
   - Create custom roles like `access-vimergy`, `access-gaia`, etc.
   - Or use existing roles with the `access-` prefix

2. **Tag Products**:
   - Tag products with `access-<brand>` (e.g., `access-vimergy`)
   - Products with these tags will only be visible to users with matching roles

3. **Assign Roles to Users**:
   - Give users the appropriate `access-<brand>` role
   - Users can have multiple access roles for different brands

### Shortcode Usage

**Basic usage** (checks if product has ANY access tag):
```
[has_access_tag]
```

**Check specific brands**:
```
[has_access_tag brands="vimergy,gaia"]
```

**Custom prefix** (if you rename your convention):
```
[has_access_tag brands="vimergy" prefix="restricted-"]
```

### The Plus Conditional Display

Use the shortcode in The Plus display conditions:
1. Edit your stock status element in The Plus
2. Add a condition: "Shortcode Returns True"
3. Enter: `[has_access_tag brands="vimergy,gaia"]`
4. The element will only display for products with matching tags

## How It Works

### Tag & Role Matching

The plugin uses a normalized key system:

- **Product tag**: `access-vimergy-product` → normalized to `vimergy`
- **User role**: `access-vimergy-role` → normalized to `vimergy`
- If normalized keys match, user has access

Common suffixes are automatically stripped:
- `-product`, `-products`
- `-role`, `-user`
- `-brand`, `-brands`
- `-tag`, `-tags`

### Performance Optimization

- **Static caching**: User keys and restricted products are cached per request
- **Early returns**: Admin users and public products skip filtering entirely
- **Efficient queries**: Uses taxonomy queries instead of post meta for better performance
- **Minimal overhead**: When access is NOT given (normal case), filtering is extremely fast

### Debug Mode

Enable WordPress debug mode to see detailed logging:

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
```

Log messages are prefixed with `[Product Access Manager]` in your debug.log file.

## Requirements

- **WordPress:** 5.8 or higher
- **WooCommerce:** 6.0 or higher (tested up to 10.0)
- **PHP:** 8.0 or higher

## Optional Integrations

- **FiboSearch** (formerly Ajax Search for WooCommerce): Automatic filtering in search results
- **The Plus Addons for Elementor**: Shortcode for conditional display

## Changelog

### 1.0.0 - Initial Release
- Consolidated WPCode snippets into proper plugin
- Full product visibility filtering
- Purchase protection
- FiboSearch integration
- `[has_access_tag]` shortcode
- Performance optimizations with caching
- Debug logging support

## Support

For issues or questions, contact: Amnon Manneberg

## License

This plugin is provided as-is for use with WooCommerce stores.

