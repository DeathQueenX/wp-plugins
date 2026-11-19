<?php
/** Selected action callback timing for administrator-issued profile requests only. */
defined( 'ABSPATH' ) || exit;
add_action( 'eburete_mcp_profile_started', array( 'Eburete_MCP_Hook_Timings', 'start' ) );
class Eburete_MCP_Hook_Timings {
	private static $wrapped = array();
	private static $rows = array();
	private static $stack = array();
	private const HOOKS = array( 'after_setup_theme', 'init', 'wp_loaded', 'wp', 'template_redirect', 'wp_head', 'wp_footer' );
	public static function start() { add_action( 'all', array( __CLASS__, 'wrap' ), PHP_INT_MIN, 1 ); }
	public static function wrap( $hook ) {
		global $wp_filter;
		if ( ! in_array( $hook, self::HOOKS, true ) || isset( self::$wrapped[ $hook ] ) || empty( $wp_filter[ $hook ]->callbacks ) ) { return; }
		self::$wrapped[ $hook ] = true;
		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $id => $entry ) {
				$callback = $entry['function'];
				if ( ! is_callable( $callback ) ) { continue; }
				$name = self::name( $callback );
				// Preserve original unique ID, priority and accepted_args for has/remove_action.
				$wp_filter[ $hook ]->callbacks[ $priority ][ $id ]['function'] = static function ( ...$args ) use ( $callback, $name, $hook ) {
					global $wpdb;
					$begin = microtime( true ); $queries = $wpdb->num_queries; self::$stack[] = 0.0;
					try { return call_user_func_array( $callback, $args ); }
					finally {
						$ms = ( microtime( true ) - $begin ) * 1000; $children = array_pop( self::$stack );
						if ( self::$stack ) { self::$stack[ count( self::$stack ) - 1 ] += $ms; }
						$key = $hook . '|' . $name;
						if ( ! isset( self::$rows[ $key ] ) ) { self::$rows[ $key ] = array( 'hook' => $hook, 'callback' => $name, 'calls' => 0, 'inclusive_ms' => 0.0, 'own_ms' => 0.0, 'queries' => 0 ); }
						self::$rows[ $key ]['calls']++; self::$rows[ $key ]['inclusive_ms'] += $ms; self::$rows[ $key ]['own_ms'] += max( 0, $ms - $children ); self::$rows[ $key ]['queries'] += $wpdb->num_queries - $queries;
					}
				};
			}
		}
	}
	private static function name( $callback ) {
		if ( is_string( $callback ) ) { return $callback; }
		if ( is_array( $callback ) ) { return ( is_object( $callback[0] ) ? get_class( $callback[0] ) : $callback[0] ) . '::' . $callback[1]; }
		if ( $callback instanceof Closure ) {
			$r = new ReflectionFunction( $callback ); $file = str_replace( '\\', '/', (string) $r->getFileName() ); $root = str_replace( '\\', '/', WP_CONTENT_DIR ) . '/';
			return 'closure@' . ( str_starts_with( $file, $root ) ? substr( $file, strlen( $root ) ) : basename( $file ) ) . ':' . $r->getStartLine();
		}
		return get_class( $callback ) . '::__invoke';
	}
	public static function report() {
		$rows = array_values( self::$rows ); usort( $rows, static function ( $a, $b ) { return $b['own_ms'] <=> $a['own_ms']; } );
		foreach ( $rows as &$row ) { $row['inclusive_ms'] = round( $row['inclusive_ms'], 2 ); $row['own_ms'] = round( $row['own_ms'], 2 ); } unset( $row );
		return array( 'slowest' => array_slice( $rows, 0, 40 ), 'measured_callbacks' => count( $rows ), 'note' => 'Selected action callbacks present at hook entry only. Diagnostic overhead included. Nested own times subtract measured child callbacks. No arguments or return values recorded.' );
	}
}

