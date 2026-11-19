<?php
/** Batch WooCommerce metadata within this request; never cache rendered HTML. */
defined( 'ABSPATH' ) || exit;
add_action( 'eburete_mcp_profile_started', array( 'Eburete_MCP_Product_Prefetch', 'profile' ), 10, 1 );
add_filter( 'the_posts', array( 'Eburete_MCP_Product_Prefetch', 'prime' ), 20, 2 );
add_filter( 'eburete_mcp_diagnostic_definitions', static function ( $definitions ) {
	$definitions['configure-product-meta-prefetch'] = array( 'Configure homepage product metadata batching', 'Enable or disable batched WooCommerce raw metadata reads on the homepage only. No rendered output, prices or stock values are cached. Admin, REST, cart, checkout and product pages are excluded.', 'eburete_mcp_configure_prefetch', array( 'enabled' => array( 'type' => 'boolean' ) ) );
	$definitions['profile-public-page'][3]['product_meta_prefetch'] = array( 'type' => 'boolean', 'description' => 'Override metadata batching for this diagnostic request only.' );
	return $definitions;
} );
function eburete_mcp_configure_prefetch( $input ) {
	if ( ! isset( $input['enabled'] ) || ! is_bool( $input['enabled'] ) ) { return new WP_Error( 'invalid_flag', 'Explicit boolean enabled is required.' ); }
	update_option( 'eburete_mcp_product_meta_prefetch', $input['enabled'], false );
	return array( 'enabled' => (bool) get_option( 'eburete_mcp_product_meta_prefetch', false ), 'scope' => 'Homepage product query results only', 'reversal' => 'Set enabled=false' );
}
class Eburete_MCP_Product_Prefetch {
	private static $override = null;
	public static $batches = 0;
	public static $products = 0;
	public static function profile( $options = array() ) {
		if ( is_array( $options ) && array_key_exists( 'product_meta_prefetch', $options ) ) { self::$override = (bool) $options['product_meta_prefetch']; }
	}
	public static function prime( $posts, $query ) {
		global $wpdb;
		$enabled = null === self::$override ? get_option( 'eburete_mcp_product_meta_prefetch', false ) : self::$override;
		if ( ! $enabled || is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! is_front_page() || ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) !== 'GET' || ! class_exists( 'WC_Data' ) || ! is_callable( array( 'WC_Data', 'prime_raw_meta_data_cache' ) ) ) { return $posts; }
		if ( ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() ) ) { return $posts; }
		$ids = array();
		foreach ( (array) $posts as $post ) {
			if ( ! is_object( $post ) || ! isset( $post->ID, $post->post_type ) || ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) { continue; }
			$id = (int) $post->ID;
			if ( $id <= 0 ) { continue; }
			$found = false;
			$cached = wp_cache_get( WC_Data::generate_meta_cache_key( $id, 'products' ), 'products', false, $found );
			if ( ! $found || ! is_array( $cached ) ) { $ids[ $id ] = $id; }
		}
		// Keep small single-product lookups on the normal WooCommerce path.
		if ( count( $ids ) < 2 || count( $ids ) > 100 ) { return $posts; }
		foreach ( array_chunk( array_values( $ids ), 25 ) as $batch ) {
			$placeholders = implode( ',', array_fill( 0, count( $batch ), '%d' ) );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id AS object_id, meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($placeholders) ORDER BY meta_id", $batch ) );
			if ( ! is_array( $rows ) || $wpdb->last_error ) { continue; }
			$data = array_fill_keys( $batch, array() );
			foreach ( $rows as $row ) { if ( isset( $data[ $row->object_id ] ) ) { $data[ $row->object_id ][] = $row; } }
			WC_Data::prime_raw_meta_data_cache( $data, 'products' );
			self::$batches++;
			self::$products += count( $batch );
		}
		return $posts;
	}
	public static function report() { return array( 'override' => self::$override, 'batches' => self::$batches, 'products' => self::$products ); }
}
