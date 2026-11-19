<?php
/** Prime only variation IDs WooCommerce already selected. */
defined( 'ABSPATH' ) || exit;
add_action( 'eburete_mcp_profile_started', array( 'Eburete_MCP_Variation_Prefetch', 'profile' ), 10, 1 );
add_filter( 'woocommerce_get_children', array( 'Eburete_MCP_Variation_Prefetch', 'prime' ), 999, 3 );
add_filter( 'eburete_mcp_diagnostic_definitions', static function ( $definitions ) {
	$definitions['configure-variation-prefetch'] = array( 'Configure homepage variation batching', 'Prime WordPress posts, metadata and images for variation IDs already returned by WooCommerce on the homepage. Returns the original IDs unchanged. Excludes admin, REST, cart, checkout and individual product pages.', 'eburete_mcp_configure_variations', array( 'enabled' => array( 'type' => 'boolean' ) ) );
	$definitions['profile-public-page'][3]['variation_prefetch'] = array( 'type' => 'boolean', 'description' => 'One-request override for variation batching.' );
	return $definitions;
} );
function eburete_mcp_configure_variations( $input ) {
	if ( ! isset( $input['enabled'] ) || ! is_bool( $input['enabled'] ) ) { return new WP_Error( 'invalid_flag', 'Explicit boolean required.' ); }
	update_option( 'eburete_mcp_variation_prefetch', $input['enabled'], false );
	return array( 'enabled' => (bool) get_option( 'eburete_mcp_variation_prefetch', false ) );
}
class Eburete_MCP_Variation_Prefetch {
	private static $override = null;
	private static $seen = array();
	private static $calls = 0;
	public static function profile( $options = array() ) { if ( is_array( $options ) && array_key_exists( 'variation_prefetch', $options ) ) { self::$override = (bool) $options['variation_prefetch']; } }
	public static function prime( $ids, $product = null, $visible = false ) {
		$enabled = null === self::$override ? get_option( 'eburete_mcp_variation_prefetch', false ) : self::$override;
		if ( ! $enabled || is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! is_front_page() || ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) !== 'GET' || ! is_array( $ids ) || count( $ids ) > 100 ) { return $ids; }
		if ( ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() ) ) { return $ids; }
		$batch = array();
		foreach ( $ids as $id ) { if ( is_numeric( $id ) && (int) $id > 0 && ! isset( self::$seen[ (int) $id ] ) ) { $batch[ (int) $id ] = (int) $id; } }
		if ( ! $batch || count( self::$seen ) + count( $batch ) > 300 ) { return $ids; }
		_prime_post_caches( array_values( $batch ), false, true );
		$posts = array();
		foreach ( $batch as $id ) {
			$post = get_post( $id );
			if ( $post && 'product_variation' === $post->post_type ) { $posts[] = $post; self::$seen[ $id ] = true; }
		}
		Eburete_MCP_Product_Prefetch::prime( $posts, null );
		Eburete_MCP_Image_Prefetch::prime( $posts, null );
		self::$calls++;
		return $ids;
	}
	public static function report() { return array( 'override' => self::$override, 'batches' => self::$calls, 'variations' => count( self::$seen ) ); }
}
