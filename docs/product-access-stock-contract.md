# Product Access And Stock Contract

Owner: `product-access-manager`
Contract version: `1.0`
Runtime version posture: plugin patch version bumped to `2.15.1` because this PR adds public runtime helpers and corrects access-gate precedence.

## Product Access Contract

The public PHP function is:

```php
pam_get_product_access_contract( int|WC_Product $product, ?int $user_id = null ): array
```

It returns a fail-soft access state:

- `allow`: Product Access Manager evaluated catalog and role access and did not deny the product.
- `deny`: Product Access Manager evaluated access and the current or supplied user lacks access.
- `unknown`: Product Access Manager could not evaluate access because product, ACF, or catalog lookup state was unavailable or errored.

Required payload fields:

- `contract`: `product-access-manager/product-access`
- `version`: `1.0`
- `status`: `allow`, `deny`, or `unknown`
- `reason`: machine-readable reason
- `product_id`
- `can_view`: false only for `deny`
- `purchase_effect`: `deny` only for `deny`; otherwise `passthrough`
- `is_restricted`
- `catalogs`
- `restricted_catalogs`
- `required_roles`
- `matched_roles`
- `owner_boundaries`

## Access Rules

Product Access Manager owns only catalog and role access.

- Admins and shop managers with `manage_woocommerce` receive `allow` by `admin_override`.
- Products with no `site_catalog` receive `allow` by `no_catalog`.
- Products with only public catalogs receive `allow` by `public_catalog`.
- Products with restricted catalogs require a matching role in the form `access-{catalog}-user`.
- Guests without a required role receive `deny` by `login_required`.
- Logged-in users without a required role receive `deny` by `missing_required_role`.
- Invalid user lookup after a user ID is supplied receives `deny` by `user_unavailable`.
- Invalid product, missing ACF access, or catalog lookup errors receive `unknown`.

## Stock And Purchase Composition

The public composition helper is:

```php
pam_compose_product_access_and_stock_state(
    int|WC_Product|array $product_or_access,
    ?int $user_id = null,
    ?bool $woo_purchasable = null,
    string $stock_status = 'unknown'
): array
```

Precedence for the native single-product page:

1. `deny` from Product Access Manager hides the product page and blocks purchase attempts.
2. `allow` from Product Access Manager permits the page to render, but does not assert stock or cart acceptance.
3. `unknown` from Product Access Manager renders fail-soft and preserves downstream WooCommerce, HP-Inventory, and HP-Checkout decisions.
4. WooCommerce purchasability and HP-Inventory stock decide whether the product is available after access allows or is unknown.
5. HP-Checkout decides cart admission and checkout behavior after access and stock have not blocked the product.

Composition payload fields:

- `access_status`
- `access_reason`
- `stock_status`: `available`, `unavailable`, or `unknown`
- `page_visibility`: `hide`, `show`, or `show_fail_soft`
- `purchase_state`: `available`, `blocked`, or `unknown`
- `purchase_owner`: `product-access-manager` only for access denial; otherwise downstream commerce owners
- `purchase_reason`
- `access`: the nested product-access contract

## Owner Boundaries

Product Access Manager owns:

- ACF `site_catalog` to public/restricted access classification.
- Restricted catalog to required role mapping.
- User role allow/deny/unknown product-access result.
- Existing Woo visibility and purchasability filters only as access gates.

Product Access Manager must not own:

- Stock truth.
- Inventory reservations.
- Cart state.
- Checkout state.
- Payment state.
- Order state.
- Auth/session source truth.
- HP-Zen rendering.
- FiboSearch ownership beyond the existing repo-local filter integration.

Downstream owner map:

- Stock and purchasability truth: WooCommerce and HP-Inventory.
- Cart and checkout state: HP-Checkout.
- Identity and auth/session truth: WordPress and HP-Login.
- Native single-product rendering and visual fallback: HP-Zen.

## Fail-Soft Behavior

`unknown` is intentionally not `deny`.

Consumers may render the product page in a safe fallback state when Product Access Manager is unavailable or cannot evaluate access. `unknown` must not be treated as Product Access Manager authorizing stock, add-to-cart, cart admission, payment, or order creation. Those decisions remain downstream.

## Focused Tests

The focused contract tests live at:

```text
tests/product-access-contract-test.php
```

They prove:

- Public catalogs return `allow`.
- Restricted guest access returns `deny`.
- Restricted authorized access returns `allow`.
- Catalog lookup errors return `unknown` and do not convert Woo unavailable state to purchasable.
- Product Access Manager `deny` takes precedence over stock and Woo purchasability.
- Product Access Manager `unknown` renders fail-soft and defers purchase state downstream.
