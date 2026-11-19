<?php
define( 'ABSPATH', __DIR__ . '/' );
function add_action() {}
function add_filter() {}
function wp_hash( $value ) { return hash_hmac( 'sha256', $value, 'test-only-key' ); }
require __DIR__ . '/../eburete-mcp-operations/attribution.php';
$wpdb = (object) array( 'num_queries' => 0, 'queries' => array() );
function verify( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
verify( false === Eburete_MCP_Attribution::before( false, 'vc_row', array(), array() ), 'Changed shortcode dispatch' );
$wpdb->num_queries = 2;
Eburete_MCP_Attribution::before( false, 'products', array( 'secret' => 'do-not-report' ), array() );
$wpdb->num_queries = 7;
verify( 'child html' === Eburete_MCP_Attribution::after( 'child html', 'products', array(), array() ), 'Changed child output' );
$wpdb->num_queries = 8;
verify( 'parent html' === Eburete_MCP_Attribution::after( 'parent html', 'vc_row', array(), array() ), 'Changed parent output' );
$report = Eburete_MCP_Attribution::report();
verify( $report['by_shortcode']['vc_row']['own_queries'] === 3, 'Nested queries double counted' );
verify( $report['by_shortcode']['products']['own_queries'] === 5, 'Child queries not attributed' );
verify( $report['unclosed_spans'] === 0, 'Stack did not close' );
verify( strpos( json_encode( $report ), 'do-not-report' ) === false, 'Attribute value leaked' );
$wpdb->queries = array( array( "SELECT 'private-value'", 0.1, 'some_function' ), array( "SELECT 'private-value'", 0.2, 'some_function' ) );
$sql_report = Eburete_MCP_Attribution::query_report();
verify( count( $sql_report ) === 1 && reset( $sql_report )['count'] === 2, 'Repeated SQL not grouped' );
verify( strpos( json_encode( $sql_report ), 'private-value' ) === false, 'SQL text leaked' );
echo "Nested shortcode attribution and SQL redaction tests passed.\n";
