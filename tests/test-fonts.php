<?php
define( 'ABSPATH', __DIR__ );
class WP_Error { public function __construct( public $code, public $message ) {} }
function add_filter( ...$args ) {}
function current_user_can( $cap ) { return $GLOBALS['allow']; }
function wp_is_file_mod_allowed( $context ) { return true; }
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['root'], 'error' => false ); }
function get_attached_file( $id, $raw ) { return $GLOBALS['root'] . '/2024/10/' . ( $id === 615 ? 'Satoshi-Medium.woff' : 'Satoshi-Bold.woff' ) . ( $GLOBALS['mismatch'] ? '.unexpected' : '' ); }
function get_post_type( $id ) { return 'attachment'; }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
function trailingslashit( $path ) { return rtrim( $path, '/' ) . '/'; }
function get_option( $key, $default = null ) { return $GLOBALS['options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload ) { $GLOBALS['options'][ $key ] = $value; }
function wp_remote_get( $url, $args ) { $GLOBALS['http']++; return array( 'code' => 200, 'body' => 'wOFF-not-a-valid-font' ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_remote_retrieve_response_code( $value ) { return $value['code']; }
function wp_remote_retrieve_body( $value ) { return $value['body']; }
function check( $condition, $message ) { if ( ! $condition ) { throw new Exception( $message ); } }
$root = sys_get_temp_dir() . '/eburete-font-test-' . bin2hex( random_bytes( 8 ) ); mkdir( $root . '/2024/10', 0777, true );
$allow = true; $mismatch = false; $options = array(); $http = 0;
require __DIR__ . '/../eburete-mcp-operations/font-repair.php';
try {
	check( eburete_mcp_font_repair( array( 'action' => 'execute' ) ) instanceof WP_Error, 'no arbitrary action' );
	$allow = false; check( eburete_mcp_font_repair( array( 'action' => 'restore' ) ) instanceof WP_Error, 'permission required' ); $allow = true;
	$mismatch = true; check( eburete_mcp_font_repair() instanceof WP_Error, 'exact media path required' ); $mismatch = false;
	$r = eburete_mcp_font_repair(); check( count( $r['files'] ) === 2 && $http === 0, 'inspect makes no downloads' );
	$r = eburete_mcp_font_repair( array( 'action' => 'restore' ) ); check( $r instanceof WP_Error && $r->code === 'font_integrity', 'pinned hash enforced' );
	check( ! file_exists( $root . '/2024/10/Satoshi-Medium.woff' ) && ! file_exists( $root . '/2024/10/Satoshi-Bold.woff' ), 'invalid download creates no files' );
	file_put_contents( $root . '/2024/10/Satoshi-Medium.woff', 'existing-medium' ); file_put_contents( $root . '/2024/10/Satoshi-Bold.woff', 'existing-bold' ); $http = 0;
	$r = eburete_mcp_font_repair( array( 'action' => 'restore' ) ); check( $r['changed'] === array() && $http === 0, 'existing files not overwritten or downloaded' );
	foreach ( eburete_mcp_font_definitions() as $id => $font ) { $options['eburete_mcp_restored_fonts'][ $id ] = $font['sha256']; }
	$r = eburete_mcp_font_repair( array( 'action' => 'revert' ) ); check( $r['changed'] === array() && file_get_contents( $root . '/2024/10/Satoshi-Medium.woff' ) === 'existing-medium', 'revert cannot remove unrelated files' );
	echo "Font repair boundaries passed\n";
} finally {
	foreach ( array( 'Satoshi-Medium.woff', 'Satoshi-Bold.woff' ) as $file ) { if ( file_exists( $root . '/2024/10/' . $file ) ) { unlink( $root . '/2024/10/' . $file ); } }
	rmdir( $root . '/2024/10' ); rmdir( $root . '/2024' ); rmdir( $root );
}

