<?php
define( 'ABSPATH', __DIR__ . '/' );
function add_action() {} function add_filter() {}
$GLOBALS['enabled'] = false; $GLOBALS['admin'] = false; $GLOBALS['front'] = true; $GLOBALS['cart'] = false; $GLOBALS['checkout'] = false; $GLOBALS['primed'] = array();
function get_option( $key, $default = false ) { return $GLOBALS['enabled']; }
function is_admin() { return $GLOBALS['admin']; } function is_front_page() { return $GLOBALS['front']; } function is_cart() { return $GLOBALS['cart']; } function is_checkout() { return $GLOBALS['checkout']; }
function update_meta_cache( $type, $ids ) {}
function get_post_meta( $id, $key, $single ) { return '_thumbnail_id' === $key ? '10' : '10,11,12,invalid,-1'; }
function _prime_post_caches( $ids, $terms, $meta ) { if ( $terms !== false || $meta !== true ) { throw new RuntimeException( 'Wrong cache scope' ); } $GLOBALS['primed'][] = $ids; }
require __DIR__ . '/../eburete-mcp-operations/image-prefetch.php';
function check_image( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
$posts = array( (object) array( 'ID' => 1, 'post_type' => 'product' ), (object) array( 'ID' => 2, 'post_type' => 'page' ) );
check_image( Eburete_MCP_Image_Prefetch::prime( $posts, null ) === $posts && ! $GLOBALS['primed'], 'Disabled mode changed posts' );
$GLOBALS['enabled'] = true;
foreach ( array( 'admin', 'cart', 'checkout' ) as $flag ) { $GLOBALS[ $flag ] = true; Eburete_MCP_Image_Prefetch::prime( $posts, null ); check_image( ! $GLOBALS['primed'], 'Excluded request affected' ); $GLOBALS[ $flag ] = false; }
$GLOBALS['front'] = false; Eburete_MCP_Image_Prefetch::prime( $posts, null ); check_image( ! $GLOBALS['primed'], 'Non-homepage affected' ); $GLOBALS['front'] = true;
check_image( Eburete_MCP_Image_Prefetch::prime( $posts, null ) === $posts, 'Product list changed' );
check_image( $GLOBALS['primed'] === array( array( 10, 11, 12 ) ), 'Image IDs not deduplicated or invalid ID accepted' );
Eburete_MCP_Image_Prefetch::prime( $posts, null ); check_image( count( $GLOBALS['primed'] ) === 1, 'Batch repeated unnecessarily' );
echo "Attachment batching and exclusion tests passed.\n";
