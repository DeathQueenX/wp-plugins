<?php
/** Per-request attribution and read-only object-cache readiness. */
defined( 'ABSPATH' ) || exit;
add_action( 'eburete_mcp_profile_started', array( 'Eburete_MCP_Attribution', 'start' ) );
add_filter( 'eburete_mcp_diagnostic_definitions', static function ( $definitions ) {
	$definitions['object-cache-readiness'] = array(
		'Object cache readiness',
		'Read persistent cache state and LiteSpeed object cache configuration without credentials. Optional PING only to an already configured loopback Redis endpoint, with a short timeout and no writes.',
		'eburete_mcp_object_cache_readiness',
		array( 'probe_local_redis' => array( 'type' => 'boolean' ) )
	);
	return $definitions;
} );
function eburete_mcp_object_cache_readiness( $input = array() ) {
	$result = array(
		'persistent' => (bool) wp_using_ext_object_cache(),
		'dropin_exists' => file_exists( WP_CONTENT_DIR . '/object-cache.php' ),
		'redis_extension' => extension_loaded( 'redis' ),
		'probe' => 'not_requested'
	);
	if ( ! class_exists( '\\LiteSpeed\\Conf' ) || ! is_callable( array( '\\LiteSpeed\\Conf', 'cls' ) ) ) { return $result; }
	$conf = \LiteSpeed\Conf::cls();
	foreach ( array( 'object', 'object-kind', 'object-persistent', 'object-admin', 'object-life' ) as $key ) {
		$result['litespeed'][ $key ] = $conf->conf( $key );
	}
	if ( empty( $input['probe_local_redis'] ) ) { return $result; }
	$host = (string) $conf->conf( 'object-host' );
	$port = (int) $conf->conf( 'object-port' );
	if ( ! in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) || $port < 1 || $port > 65535 || ! class_exists( 'Redis' ) ) {
		$result['probe'] = 'skipped_no_configured_loopback_redis';
		return $result;
	}
	$redis = new Redis();
	try {
		$connected = @$redis->connect( $host, $port, 0.25, null, 0, 0.25 );
		$result['probe'] = $connected && $redis->ping() ? 'reachable_ping_ok' : 'connection_failed';
	} catch ( Throwable $error ) {
		$result['probe'] = stripos( $error->getMessage(), 'NOAUTH' ) !== false ? 'reachable_authentication_required' : 'connection_or_ping_failed';
	} finally {
		try { $redis->close(); } catch ( Throwable $ignored ) {}
	}
	return $result;
}

class Eburete_MCP_Attribution {
	private static $stack = array();
	private static $spans = array();
	private static $counter = 0;
	public static function start() {
		add_filter( 'pre_do_shortcode_tag', array( __CLASS__, 'before' ), PHP_INT_MAX, 4 );
		add_filter( 'do_shortcode_tag', array( __CLASS__, 'after' ), PHP_INT_MAX, 4 );
	}
	public static function before( $return, $tag, $attr, $match ) {
		global $wpdb;
		if ( false !== $return || self::$counter >= 1500 ) { return $return; }
		$id = ++self::$counter;
		$block_id = null;
		if ( is_array( $attr ) && in_array( $tag, array( 'html_block', 'woodmart_html_block' ), true ) && isset( $attr['id'] ) && ctype_digit( (string) $attr['id'] ) ) { $block_id = (int) $attr['id']; }
		self::$stack[] = array( 'id' => $id, 'tag' => $tag, 'block_id' => $block_id, 'depth' => count( self::$stack ), 'start' => microtime( true ), 'queries' => $wpdb->num_queries, 'child_ms' => 0, 'child_queries' => 0 );
		return $return;
	}
	public static function after( $output, $tag, $attr, $match ) {
		global $wpdb;
		if ( empty( self::$stack ) ) { return $output; }
		$index = count( self::$stack ) - 1;
		if ( self::$stack[ $index ]['tag'] !== $tag ) { return $output; }
		$span = array_pop( self::$stack );
		$ms = ( microtime( true ) - $span['start'] ) * 1000;
		$queries = $wpdb->num_queries - $span['queries'];
		$row = array( 'instance' => $span['id'], 'shortcode' => $tag, 'html_block_id' => $span['block_id'], 'depth' => $span['depth'], 'inclusive_ms' => round( $ms, 2 ), 'own_ms' => round( max( 0, $ms - $span['child_ms'] ), 2 ), 'inclusive_queries' => $queries, 'own_queries' => max( 0, $queries - $span['child_queries'] ) );
		self::$spans[] = $row;
		if ( self::$stack ) {
			$parent = count( self::$stack ) - 1;
			self::$stack[ $parent ]['child_ms'] += $ms;
			self::$stack[ $parent ]['child_queries'] += $queries;
		}
		return $output;
	}
	public static function report() {
		$rows = self::$spans;
		usort( $rows, static function ( $a, $b ) { return $b['own_ms'] <=> $a['own_ms']; } );
		$groups = array();
		foreach ( self::$spans as $row ) {
			$key = $row['shortcode'];
			if ( ! isset( $groups[ $key ] ) ) { $groups[ $key ] = array( 'instances' => 0, 'own_ms' => 0, 'own_queries' => 0 ); }
			$groups[ $key ]['instances']++;
			$groups[ $key ]['own_ms'] += $row['own_ms'];
			$groups[ $key ]['own_queries'] += $row['own_queries'];
		}
		uasort( $groups, static function ( $a, $b ) { return $b['own_ms'] <=> $a['own_ms']; } );
		return array( 'by_shortcode' => array_slice( $groups, 0, 30, true ), 'slowest_instances' => array_slice( $rows, 0, 30 ), 'completed_instances' => count( self::$spans ), 'unclosed_spans' => count( self::$stack ), 'note' => 'Inclusive timings overlap; own timings subtract nested shortcode execution only. No shortcode content or user attributes are returned. Instances follow invocation order.' );
	}
	public static function query_report() {
		global $wpdb;
		$groups = array();
		foreach ( (array) $wpdb->queries as $query ) {
			// Never return raw SQL. Group exact repeats using a server-keyed hash.
			$key = wp_hash( $query[0] );
			if ( ! isset( $groups[ $key ] ) ) {
				preg_match( '/^\\s*([A-Z]+)/i', $query[0], $operation );
				$groups[ $key ] = array( 'operation' => strtoupper( $operation[1] ?? 'UNKNOWN' ), 'count' => 0, 'ms' => 0, 'leaf_caller' => substr( (string) ( $query[2] ?? '' ), -600 ) );
			}
			$groups[ $key ]['count']++;
			$groups[ $key ]['ms'] += (float) $query[1] * 1000;
		}
		uasort( $groups, static function ( $a, $b ) { return $b['ms'] <=> $a['ms']; } );
		return array_slice( $groups, 0, 30, true );
	}
}
