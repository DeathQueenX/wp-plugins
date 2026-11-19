<?php
define( 'ABSPATH', __DIR__ ); define( 'WP_CONTENT_DIR', '/site/wp-content' );
function add_action( ...$args ) {}
$wpdb = (object) array( 'num_queries' => 0 ); $wp_filter = array();
require __DIR__ . '/../eburete-mcp-operations/hook-timings.php';
function check( $condition, $message ) { if ( ! $condition ) { throw new Exception( $message ); } }
$calls = 0;
$callback = static function ( $value ) use ( &$calls ) { global $wpdb; $calls++; $wpdb->num_queries++; return $value + 1; };
$wp_filter['init'] = (object) array( 'callbacks' => array( 10 => array( 'original-id' => array( 'function' => $callback, 'accepted_args' => 1 ) ) ) );
Eburete_MCP_Hook_Timings::wrap( 'init' );
$entry = $wp_filter['init']->callbacks[10]['original-id'];
check( $entry['accepted_args'] === 1, 'accepted args preserved' );
check( $entry['function']( 8 ) === 9 && $calls === 1, 'return value and calls preserved' );
Eburete_MCP_Hook_Timings::wrap( 'init' );
check( $entry['function'] === $wp_filter['init']->callbacks[10]['original-id']['function'], 'no double wrapping' );
$report = Eburete_MCP_Hook_Timings::report();
check( $report['slowest'][0]['queries'] === 1 && $report['slowest'][0]['calls'] === 1, 'query/call accounting' );
$wp_filter['the_content'] = (object) array( 'callbacks' => array( 10 => array( 'untouched' => array( 'function' => $callback, 'accepted_args' => 1 ) ) ) );
Eburete_MCP_Hook_Timings::wrap( 'the_content' );
check( $wp_filter['the_content']->callbacks[10]['untouched']['function'] === $callback, 'unselected filters untouched' );
$throw = static function () { throw new RuntimeException( 'original' ); };
$wp_filter['wp'] = (object) array( 'callbacks' => array( 10 => array( 'throw-id' => array( 'function' => $throw, 'accepted_args' => 0 ) ) ) );
Eburete_MCP_Hook_Timings::wrap( 'wp' );
try { $wp_filter['wp']->callbacks[10]['throw-id']['function'](); throw new Exception( 'exception lost' ); } catch ( RuntimeException $e ) { check( $e->getMessage() === 'original', 'exception preserved' ); }
echo "Hook timing boundaries passed\n";

