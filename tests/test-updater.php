<?php
// Standalone tests for manifest trust boundaries and archive validation.
define( 'ABSPATH', __DIR__ . '/' );
function add_action() {}
class WP_Error { public $code; public function __construct( $code, $message ) { $this->code = $code; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_safe_remote_get( $url, $args ) { $GLOBALS['last_url'] = $url; return array( 'code' => 200, 'body' => json_encode( $GLOBALS['manifest'] ) ); }
function wp_remote_retrieve_response_code( $response ) { return $response['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
require __DIR__ . '/../eburete-mcp-operations/updater.php';
function check( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
$valid = array( 'slug' => 'eburete-mcp-operations', 'version' => '0.4.0', 'sha256' => str_repeat( 'a', 64 ), 'package' => 'https://attacker.invalid/malicious.zip' );
$GLOBALS['manifest'] = $valid;
$result = eburete_mcp_release_manifest();
check( ! is_wp_error( $result ), 'Valid manifest rejected' );
check( $result['package'] === 'https://raw.githubusercontent.com/DeathQueenX/wp-plugins/main/packages/eburete-mcp-operations-0.4.0.zip', 'Manifest controlled package URL' );
foreach ( array( 'slug' => 'another-plugin', 'version' => '../bad', 'sha256' => 'bad' ) as $key => $bad ) {
	$GLOBALS['manifest'] = array_merge( $valid, array( $key => $bad ) );
	check( is_wp_error( eburete_mcp_release_manifest() ), 'Invalid manifest accepted: ' . $key );
}
if ( ! class_exists( 'ZipArchive' ) ) { throw new RuntimeException( 'ZipArchive required for tests' ); }
function archive_test( $entries, $valid ) {
	$file = tempnam( sys_get_temp_dir(), 'eburete-test' );
	$zip = new ZipArchive();
	$zip->open( $file, ZipArchive::OVERWRITE );
	foreach ( $entries as $name => $contents ) { $zip->addFromString( $name, $contents ); }
	$zip->close();
	try { check( $valid === ! is_wp_error( eburete_mcp_validate_update_zip( $file, '0.4.0' ) ), 'Archive validation failed' ); }
	finally { unlink( $file ); }
}
$main = array( 'eburete-mcp-operations/eburete-mcp-operations.php' => "<?php\n/**\n * Version: 0.4.0\n */\n" );
archive_test( $main, true );
archive_test( array_merge( $main, array( '../another-plugin/file.php' => '<?php' ) ), false );
archive_test( array_merge( $main, array( 'eburete-mcp-operations/../file.php' => '<?php' ) ), false );
archive_test( array_merge( $main, array( 'another-plugin/file.php' => '<?php' ) ), false );
archive_test( array( 'eburete-mcp-operations/eburete-mcp-operations.php' => "<?php\n * Version: 0.3.0\n" ), false );
echo "Manifest and ZIP boundary tests passed.\n";
function is_plugin_active( $plugin ) { return ! empty( $GLOBALS['plugin_active'] ); }
function activate_plugin( $plugin, $redirect, $network, $silent ) {
	if ( ! empty( $GLOBALS['activation_error'] ) ) { return new WP_Error( 'activation_failed', 'Test failure' ); }
	$GLOBALS['plugin_active'] = true;
	return null;
}
$GLOBALS['plugin_active'] = false;
check( true === eburete_mcp_restore_active_state( 'eburete-mcp-operations/eburete-mcp-operations.php', true ), 'Active plugin was not restored' );
check( $GLOBALS['plugin_active'], 'Activation did not occur' );
$GLOBALS['plugin_active'] = false;
check( true === eburete_mcp_restore_active_state( 'eburete-mcp-operations/eburete-mcp-operations.php', false ) && ! $GLOBALS['plugin_active'], 'Previously inactive plugin unexpectedly activated' );
$GLOBALS['activation_error'] = true;
check( is_wp_error( eburete_mcp_restore_active_state( 'eburete-mcp-operations/eburete-mcp-operations.php', true ) ), 'Activation error was hidden' );
echo "Self-update activation regression tests passed.\n";
