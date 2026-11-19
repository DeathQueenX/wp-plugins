<?php
/** Batch attachment post/meta reads for homepage product grids. No HTML cache. */
defined( 'ABSPATH' ) || exit;
add_action( 'eburete_mcp_profile_started', array( 'Eburete_MCP_Image_Prefetch', 'profile' ), 10, 1 );
add_filter( 'the_posts', array( 'Eburete_MCP_Image_Prefetch', 'prime' ), 30, 2 );
add_filter( 'eburete_mcp_diagnostic_definitions', static function ( $definitions ) {
	$definitions['configure-image-prefetch'] = array( 'Configure homepage image batching', 'Batch WordPress attachment post/meta reads for existing product thumbnails and galleries on the homepage only. Does not change markup, prices or products. Set enabled=false to revert.', 'eburete_mcp_configure_images', array( 'enabled' => array( 'type' => 'boolean' ) ) );
	$definitions['profile-public-page'][3]['image_prefetch'] = array( 'type' => 'boolean', 'description' => 'Override attachment batching for this diagnostic request only.' );
	return $definitions;
} );
function eburete_mcp_configure_images( $input ) {
	if ( ! isset( $input['enabled'] ) || ! is_bool( $input['enabled'] ) ) { return new WP_Error( 'invalid_flag', 'Explicit boolean required.' ); }
	update_option( 'eburete_mcp_image_prefetch', $input['enabled'], false );
	return array( 'enabled' => (bool) get_option( 'eburete_mcp_image_prefetch', false ), 'scope' => 'Homepage product images only' );
}
class Eburete_MCP_Image_Prefetch {
	private static $override = null;
	private static $seen = array();
	private static $batches = 0;
	public static function profile( $options = array() ) { if ( is_array( $options ) && array_key_exists( 'image_prefetch', $options ) ) { self::$override = (bool) $options['image_prefetch']; } }
	public static function prime( $posts, $query ) {
		$enabled = null === self::$override ? get_option( 'eburete_mcp_image_prefetch', false ) : self::$override;
		if ( ! $enabled || is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! is_front_page() || ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) !== 'GET' || ! function_exists( '_prime_post_caches' ) ) { return $posts; }
		if ( ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() ) ) { return $posts; }
		$products = array();
		foreach ( (array) $posts as $post ) {
			if ( is_object( $post ) && isset( $post->post_type, $post->ID ) && in_array( $post->post_type, array( 'product', 'product_variation' ), true ) && (int) $post->ID > 0 ) { $products[] = (int) $post->ID; }
		}
		if ( ! $products || count( $products ) > 100 ) { return $posts; }
		update_meta_cache( 'post', $products );
		$images = array();
		foreach ( $products as $id ) {
			$thumbnail = (string) get_post_meta( $id, '_thumbnail_id', true );
			$gallery = (string) get_post_meta( $id, '_product_image_gallery', true );
			foreach ( array_merge( array( $thumbnail ), explode( ',', $gallery ) ) as $image ) {
				$image = trim( $image );
				if ( ctype_digit( $image ) && (int) $image > 0 && ! isset( self::$seen[ (int) $image ] ) ) { $images[ (int) $image ] = (int) $image; }
			}
		}
		if ( count( $images ) > 300 || count( self::$seen ) + count( $images ) > 600 ) { return $posts; }
		if ( $images ) {
			_prime_post_caches( array_values( $images ), false, true );
			foreach ( $images as $id ) { self::$seen[ $id ] = true; }
			self::$batches++;
		}
		return $posts;
	}
	public static function report() { return array( 'override' => self::$override, 'batches' => self::$batches, 'images' => count( self::$seen ) ); }
}
