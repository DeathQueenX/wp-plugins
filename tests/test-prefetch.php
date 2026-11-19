<?php
define( 'ABSPATH', __DIR__ . '/' );
function add_action() {} function add_filter() {}
$GLOBALS['enabled'] = false; $GLOBALS['admin'] = false; $GLOBALS['front'] = true; $GLOBALS['cart'] = false; $GLOBALS['checkout'] = false; $GLOBALS['cache'] = array();
function get_option( $key, $default = false ) { return $GLOBALS['enabled']; }
function is_admin() { return $GLOBALS['admin']; }
function is_front_page() { return $GLOBALS['front']; }
function is_cart() { return $GLOBALS['cart']; }
function is_checkout() { return $GLOBALS['checkout']; }
function wp_cache_get( $key, $group, $force, &$found ) { $found = array_key_exists( $key, $GLOBALS['cache'] ); return $GLOBALS['cache'][ $key ] ?? false; }
class WC_Data {
	public static function generate_meta_cache_key( $id, $group ) { return $group . ':' . $id; }
	public static function prime_raw_meta_data_cache( $data, $group ) { foreach ( $data as $id => $rows ) { $GLOBALS['cache'][ self::generate_meta_cache_key( $id, $group ) ] = $rows; } }
}
class Test_DB {
	public $postmeta = 'wp_postmeta'; public $last_error = ''; public $calls = 0;
	public function prepare( $query, $ids ) { return $ids; }
	public function get_results( $ids ) { $this->calls++; return array_map( static function ( $id ) { return (object) array( 'object_id' => $id, 'meta_id' => $id * 10, 'meta_key' => 'custom_price_option', 'meta_value' => 'original-value-' . $id ); }, $ids ); }
}
$wpdb = new Test_DB();
require __DIR__ . '/../eburete-mcp-operations/product-prefetch.php';
function verify_prefetch( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
$posts = array( (object) array( 'ID' => 1, 'post_type' => 'product' ), (object) array( 'ID' => 2, 'post_type' => 'product_variation' ), (object) array( 'ID' => 3, 'post_type' => 'page' ) );
verify_prefetch( Eburete_MCP_Product_Prefetch::prime( $posts, null ) === $posts && $wpdb->calls === 0, 'Disabled feature changed behavior' );
$GLOBALS['enabled'] = true;
foreach ( array( 'admin', 'cart', 'checkout' ) as $flag ) { $GLOBALS[ $flag ] = true; Eburete_MCP_Product_Prefetch::prime( $posts, null ); verify_prefetch( $wpdb->calls === 0, 'Excluded request was prefetched' ); $GLOBALS[ $flag ] = false; }
$GLOBALS['front'] = false; Eburete_MCP_Product_Prefetch::prime( $posts, null ); verify_prefetch( $wpdb->calls === 0, 'Non-homepage affected' ); $GLOBALS['front'] = true;
verify_prefetch( Eburete_MCP_Product_Prefetch::prime( $posts, null ) === $posts, 'Product order or objects changed' );
verify_prefetch( $wpdb->calls === 1 && count( $GLOBALS['cache'] ) === 2, 'Metadata not batched or non-product cached' );
verify_prefetch( $GLOBALS['cache']['products:1'][0]->meta_value === 'original-value-1' && $GLOBALS['cache']['products:1'][0]->meta_id === 10, 'Metadata value or ID changed' );
Eburete_MCP_Product_Prefetch::prime( $posts, null ); verify_prefetch( $wpdb->calls === 1, 'Existing cache overwritten' );
echo "Product metadata batching and exclusion tests passed.\n";
