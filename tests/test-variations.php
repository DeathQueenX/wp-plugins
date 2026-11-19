<?php
define( 'ABSPATH', __DIR__ . '/' );
function add_action() {} function add_filter() {}
$GLOBALS['enabled'] = false; $GLOBALS['admin'] = false; $GLOBALS['front'] = true; $GLOBALS['cart'] = false; $GLOBALS['checkout'] = false; $GLOBALS['primed'] = array();
function get_option( $key, $default = false ) { return $GLOBALS['enabled']; }
function is_admin() { return $GLOBALS['admin']; } function is_front_page() { return $GLOBALS['front']; } function is_cart() { return $GLOBALS['cart']; } function is_checkout() { return $GLOBALS['checkout']; }
function _prime_post_caches( $ids, $terms, $meta ) { $GLOBALS['primed'][] = $ids; }
function get_post( $id ) { return (object) array( 'ID' => $id, 'post_type' => 'product_variation' ); }
class Eburete_MCP_Product_Prefetch { public static function prime( $posts, $q ) {} }
class Eburete_MCP_Image_Prefetch { public static function prime( $posts, $q ) {} }
require __DIR__ . '/../eburete-mcp-operations/variation-prefetch.php';
function check_variation( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
$ids = array( 10, 11, 12 );
check_variation( Eburete_MCP_Variation_Prefetch::prime( $ids ) === $ids && ! $GLOBALS['primed'], 'Disabled mode changed IDs' );
$GLOBALS['enabled'] = true;
foreach ( array( 'admin', 'cart', 'checkout' ) as $flag ) { $GLOBALS[ $flag ] = true; Eburete_MCP_Variation_Prefetch::prime( $ids ); check_variation( ! $GLOBALS['primed'], 'Excluded request affected' ); $GLOBALS[ $flag ] = false; }
$GLOBALS['front'] = false; Eburete_MCP_Variation_Prefetch::prime( $ids ); check_variation( ! $GLOBALS['primed'], 'Product page affected' ); $GLOBALS['front'] = true;
check_variation( Eburete_MCP_Variation_Prefetch::prime( $ids ) === $ids, 'Variation selection or order changed' );
check_variation( $GLOBALS['primed'] === array( $ids ), 'Wrong cache IDs' );
Eburete_MCP_Variation_Prefetch::prime( $ids ); check_variation( count( $GLOBALS['primed'] ) === 1, 'Repeated batch' );
echo "Variation selection and exclusion tests passed.\n";
