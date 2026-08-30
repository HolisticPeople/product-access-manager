<?php
/**
 * Focused contract tests for Product Access Manager.
 *
 * Run with:
 * php tests/product-access-contract-test.php
 */

define( 'ABSPATH', __DIR__ . '/' );

class WC_Product {
    private $id;

    public function __construct( $id ) {
        $this->id = $id;
    }

    public function get_id() {
        return $this->id;
    }
}

class PAM_Test_Features_Util {
    public static $declarations = array();

    public static function declare_compatibility( $feature_id, $plugin_file, $positive_compatibility = true ) {
        self::$declarations[] = array( $feature_id, $plugin_file, $positive_compatibility );
    }
}
class_alias( 'PAM_Test_Features_Util', 'Automattic\\WooCommerce\\Utilities\\FeaturesUtil' );

function add_action( $hook_name, $callback = null, $priority = 10, $accepted_args = 1 ) {
    $GLOBALS['pam_test_actions'][ $hook_name ][] = array( $callback, $priority, $accepted_args );
}
function add_filter() {}
function plugin_dir_path( $file ) {
    return dirname( $file ) . '/';
}
function plugins_url( $path, $file ) {
    return 'https://example.test/wp-content/plugins/product-access-manager/' . ltrim( $path, '/' );
}
function absint( $value ) {
    return abs( (int) $value );
}
function current_user_can( $capability ) {
    return ! empty( $GLOBALS['pam_test_manage_woocommerce'] ) && 'manage_woocommerce' === $capability;
}
function get_current_user_id() {
    return isset( $GLOBALS['pam_test_current_user_id'] ) ? (int) $GLOBALS['pam_test_current_user_id'] : 0;
}
function get_userdata( $user_id ) {
    return isset( $GLOBALS['pam_test_users'][ $user_id ] )
        ? (object) array( 'roles' => $GLOBALS['pam_test_users'][ $user_id ] )
        : false;
}
function acf_get_field( $field_name ) {
    if ( 'site_catalog' !== $field_name ) {
        return null;
    }

    return array(
        'choices' => array(
            'HP_catalog' => 'HP',
            'DCG_catalog' => 'DCG',
            'Vimergy_catalog' => 'Vimergy',
            'Gaia_catalog' => 'Gaia',
        ),
    );
}
function get_field( $field_name, $product_id ) {
    if ( 'site_catalog' !== $field_name ) {
        return null;
    }

    if ( 999 === (int) $product_id ) {
        throw new RuntimeException( 'catalog lookup failed' );
    }

    return isset( $GLOBALS['pam_test_product_catalogs'][ $product_id ] )
        ? $GLOBALS['pam_test_product_catalogs'][ $product_id ]
        : array();
}

require dirname( __DIR__ ) . '/product-access-manager.php';

function pam_test_reset() {
    $GLOBALS['pam_test_manage_woocommerce'] = false;
    $GLOBALS['pam_test_current_user_id'] = 0;
    $GLOBALS['pam_test_users'] = array();
    $GLOBALS['pam_test_product_catalogs'] = array();
}

function pam_assert_same( $expected, $actual, $message ) {
    if ( $expected !== $actual ) {
        throw new RuntimeException(
            $message . ' Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true )
        );
    }
}

function pam_assert_true( $actual, $message ) {
    pam_assert_same( true, $actual, $message );
}

function pam_assert_false( $actual, $message ) {
    pam_assert_same( false, $actual, $message );
}

function pam_test( $name, $callback ) {
    pam_test_reset();
    $callback();
    echo '[PASS] ' . $name . PHP_EOL;
}

pam_test( 'public catalog returns allow', function () {
    $GLOBALS['pam_test_product_catalogs'][101] = array( 'HP_catalog' );

    $contract = pam_get_product_access_contract( 101 );

    pam_assert_same( PAM_ACCESS_ALLOW, $contract['status'], 'Public catalog should allow.' );
    pam_assert_same( 'public_catalog', $contract['reason'], 'Public catalog should explain allow.' );
    pam_assert_false( $contract['is_restricted'], 'Public catalog should not be restricted.' );
    pam_assert_same( 'passthrough', $contract['purchase_effect'], 'Allow should preserve downstream purchase truth.' );
} );

pam_test( 'plugin header and constant versions match release version', function () {
    $plugin_source = file_get_contents( dirname( __DIR__ ) . '/product-access-manager.php' );

    if ( ! preg_match( '/^[ \t]*\*[ \t]+Version:[ \t]+([0-9.]+)/m', $plugin_source, $matches ) ) {
        throw new RuntimeException( 'Plugin header version was not found.' );
    }

    pam_assert_same( '2.15.2', $matches[1], 'Plugin header should use release version.' );
    pam_assert_same( '2.15.2', PAM_VERSION, 'PAM_VERSION should use release version.' );
    pam_assert_same( $matches[1], PAM_VERSION, 'Plugin header Version and PAM_VERSION should match.' );
} );

pam_test( 'declares WooCommerce HPOS compatibility before initialization', function () {
    pam_assert_true(
        isset( $GLOBALS['pam_test_actions']['before_woocommerce_init'] ),
        'HPOS declaration should be registered on before_woocommerce_init.'
    );

    $registration = $GLOBALS['pam_test_actions']['before_woocommerce_init'][0];
    pam_assert_same( 'pam_declare_hpos_compatibility', $registration[0], 'HPOS callback should be registered.' );

    PAM_Test_Features_Util::$declarations = array();
    call_user_func( $registration[0] );

    pam_assert_same(
        array( array( 'custom_order_tables', PAM_PLUGIN_FILE, true ) ),
        PAM_Test_Features_Util::$declarations,
        'Plugin should declare positive custom_order_tables compatibility for its main file.'
    );
} );

pam_test( 'restricted guest returns deny', function () {
    $GLOBALS['pam_test_product_catalogs'][201] = array( 'Vimergy_catalog' );

    $contract = pam_get_product_access_contract( 201 );

    pam_assert_same( PAM_ACCESS_DENY, $contract['status'], 'Restricted guest should be denied.' );
    pam_assert_same( 'login_required', $contract['reason'], 'Guest denial should require login.' );
    pam_assert_true( $contract['is_restricted'], 'Restricted catalog should be marked restricted.' );
    pam_assert_same( array( 'access-vimergy-user' ), $contract['required_roles'], 'Required role should be derived from catalog.' );
    pam_assert_same( 'deny', $contract['purchase_effect'], 'Deny should block purchase attempts.' );
    pam_assert_false( pam_reveal_product( true, 201 ), 'Deny should force Woo visibility false.' );
    pam_assert_false( pam_allow_purchase( true, 201 ), 'Deny should override Woo purchasable true.' );
} );

pam_test( 'restricted authorized user returns allow', function () {
    $GLOBALS['pam_test_product_catalogs'][202] = array( 'Vimergy_catalog' );
    $GLOBALS['pam_test_current_user_id'] = 12;
    $GLOBALS['pam_test_users'][12] = array( 'customer', 'access-vimergy-user' );

    $contract = pam_get_product_access_contract( new WC_Product( 202 ) );

    pam_assert_same( PAM_ACCESS_ALLOW, $contract['status'], 'Authorized user should be allowed.' );
    pam_assert_same( 'required_role_matched', $contract['reason'], 'Allow should come from role match.' );
    pam_assert_same( array( 'access-vimergy-user' ), $contract['matched_roles'], 'Matched role should be reported.' );
    pam_assert_true( pam_allow_purchase( true, 202 ), 'Allow should preserve Woo purchasable true.' );
    pam_assert_false( pam_allow_purchase( false, 202 ), 'Allow should not convert Woo unavailable to purchasable.' );
} );

pam_test( 'catalog lookup error returns unknown and preserves Woo unavailable', function () {
    $contract = pam_get_product_access_contract( 999 );

    pam_assert_same( PAM_ACCESS_UNKNOWN, $contract['status'], 'Lookup error should be unknown.' );
    pam_assert_same( 'catalog_lookup_error', $contract['reason'], 'Unknown should explain lookup failure.' );
    pam_assert_true( pam_user_can_view( 999 ), 'Unknown should render fail-soft for viewing.' );
    pam_assert_false( pam_allow_purchase( false, 999 ), 'Unknown should not convert Woo unavailable to purchasable.' );
} );

pam_test( 'access deny wins over stock and Woo purchasable', function () {
    $GLOBALS['pam_test_product_catalogs'][301] = array( 'Gaia_catalog' );

    $composition = pam_compose_product_access_and_stock_state( 301, null, true, 'available' );

    pam_assert_same( PAM_ACCESS_DENY, $composition['access_status'], 'Guest restricted access should deny.' );
    pam_assert_same( 'hide', $composition['page_visibility'], 'Access denial should hide page.' );
    pam_assert_same( 'blocked', $composition['purchase_state'], 'Access denial should block purchase.' );
    pam_assert_same( 'product-access-manager', $composition['purchase_owner'], 'PAM should own access-denied purchase block.' );
} );

pam_test( 'access unknown renders fail-soft and defers purchase state', function () {
    $composition = pam_compose_product_access_and_stock_state( 999, null, null, 'unknown' );

    pam_assert_same( PAM_ACCESS_UNKNOWN, $composition['access_status'], 'Lookup error should compose as unknown.' );
    pam_assert_same( 'show_fail_soft', $composition['page_visibility'], 'Unknown access should render fail-soft.' );
    pam_assert_same( 'unknown', $composition['purchase_state'], 'Unknown downstream truth should remain unknown.' );
    pam_assert_same( 'WooCommerce/HP-Inventory/HP-Checkout', $composition['purchase_owner'], 'Unknown purchase state should stay downstream-owned.' );
} );

echo 'Product Access Manager contract tests passed.' . PHP_EOL;
