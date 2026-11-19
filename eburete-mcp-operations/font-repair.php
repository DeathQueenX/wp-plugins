<?php
/** Restore only the two missing Satoshi files already registered in this site's media library. */
defined( 'ABSPATH' ) || exit;
add_filter( 'eburete_mcp_diagnostic_definitions', static function ( $definitions ) {
	$definitions['repair-missing-theme-fonts'] = array( 'Repair missing theme fonts', 'Inspect or restore only the two registered Satoshi font files, downloaded from fixed official Fontshare URLs and verified against pinned SHA-256. Existing files are never overwritten. Revert removes only unchanged files created by this repair.', 'eburete_mcp_font_repair', array( 'action' => array( 'type' => 'string', 'enum' => array( 'inspect', 'restore', 'revert' ) ) ) );
	return $definitions;
} );
function eburete_mcp_font_definitions() {
	return array(
		615 => array( 'file' => 'Satoshi-Medium.woff', 'bytes' => 33272, 'sha256' => '7aeaf037fba5e3e1b3a1862934f5cabcc863d8e1bd6f45d08c4338d2f93b3e2e', 'url' => 'https://cdn.fontshare.com/wf/P2LQKHE6KA6ZP4AAGN72KDWMHH6ZH3TA/ZC32TK2P7FPS5GFTL46EU6KQJA24ZYDB/7AHDUZ4A7LFLVFUIFSARGIWCRQJHISQP.woff' ),
		616 => array( 'file' => 'Satoshi-Bold.woff', 'bytes' => 32972, 'sha256' => '1789917c68172af8a24eeec4ceced208471f7906c6ac51fb7017636b9af6cbe3', 'url' => 'https://cdn.fontshare.com/wf/LAFFD4SDUCDVQEXFPDC7C53EQ4ZELWQI/PXCT3G6LO6ICM5I3NTYENYPWJAECAWDD/GHM6WVH6MILNYOOCXHXB5GTSGNTMGXZR.woff' ),
	);
}
function eburete_mcp_font_repair( $input = array() ) {
	$action = $input['action'] ?? 'inspect';
	if ( ! in_array( $action, array( 'inspect', 'restore', 'revert' ), true ) ) { return new WP_Error( 'invalid_action', 'Unsupported action.' ); }
	if ( 'inspect' !== $action && ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'update_plugins' ) || ! wp_is_file_mod_allowed( 'eburete_mcp_font_repair' ) ) ) { return new WP_Error( 'font_permission', 'Upload and update permissions are required.' ); }
	$uploads = wp_upload_dir(); $definitions = eburete_mcp_font_definitions(); $paths = array(); $result = array();
	$root = realpath( $uploads['basedir'] );
	if ( ! $root || ! empty( $uploads['error'] ) ) { return new WP_Error( 'font_uploads', 'Uploads directory unavailable.' ); }
	foreach ( $definitions as $id => $font ) {
		$path = get_attached_file( $id, true ); $expected = $uploads['basedir'] . '/2024/10/' . $font['file'];
		$ancestor = dirname( $expected );
		while ( ! file_exists( $ancestor ) && dirname( $ancestor ) !== $ancestor ) { $ancestor = dirname( $ancestor ); }
		$parent = realpath( $ancestor );
		$url_matches = wp_get_attachment_url( $id ) === rtrim( $uploads['baseurl'], '/' ) . '/2024/10/' . $font['file'];
		$safe = 'attachment' === get_post_type( $id ) && $url_matches && $parent && str_starts_with( wp_normalize_path( $parent ) . '/', trailingslashit( wp_normalize_path( $root ) ) ) && ! is_link( $expected );
		if ( ! $safe && 'inspect' !== $action ) { return new WP_Error( 'font_target', 'Registered public font URL or target directory did not match the fixed repair target.' ); }
		$paths[ $id ] = $expected;
		$result[ $id ] = array( 'file' => $font['file'], 'exists' => file_exists( $expected ), 'expected_hash' => file_exists( $expected ) && hash_file( 'sha256', $expected ) === $font['sha256'], 'safe_target' => (bool) $safe, 'registered_url_matches' => $url_matches, 'registered_path_matches' => wp_normalize_path( $path ) === wp_normalize_path( $expected ), 'parent_exists' => is_dir( dirname( $expected ) ) );
	}
	if ( 'inspect' === $action ) { return array( 'files' => $result, 'changed' => array() ); }
	$created = (array) get_option( 'eburete_mcp_restored_fonts', array() ); $changed = array();
	if ( 'revert' === $action ) {
		foreach ( $definitions as $id => $font ) {
			if ( isset( $created[ $id ] ) && $created[ $id ] === $font['sha256'] && is_file( $paths[ $id ] ) && hash_file( 'sha256', $paths[ $id ] ) === $font['sha256'] && unlink( $paths[ $id ] ) ) { unset( $created[ $id ] ); $changed[] = $font['file']; }
		}
	} else {
		// Download and validate all missing files before creating any file.
		$bodies = array();
		foreach ( $definitions as $id => $font ) {
			if ( file_exists( $paths[ $id ] ) ) { continue; }
			$response = wp_remote_get( $font['url'], array( 'timeout' => 15, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 65536, 'cookies' => array() ) );
			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) { return new WP_Error( 'font_download', 'Official font download failed; no files were created.' ); }
			$body = wp_remote_retrieve_body( $response );
			if ( strlen( $body ) !== $font['bytes'] || substr( $body, 0, 4 ) !== 'wOFF' || hash( 'sha256', $body ) !== $font['sha256'] ) { return new WP_Error( 'font_integrity', 'Font validation failed; no files were created.' ); }
			$bodies[ $id ] = $body;
		}
		foreach ( $bodies as $id => $body ) {
			if ( ! wp_mkdir_p( dirname( $paths[ $id ] ) ) || ! str_starts_with( wp_normalize_path( realpath( dirname( $paths[ $id ] ) ) ) . '/', trailingslashit( wp_normalize_path( $root ) ) ) ) { return new WP_Error( 'font_directory', 'Font directory could not be created safely.' ); }
			$handle = @fopen( $paths[ $id ], 'xb' );
			if ( ! $handle ) { return new WP_Error( 'font_create', 'Exclusive creation failed; existing files were not overwritten.' ); }
			$written = fwrite( $handle, $body ); fclose( $handle );
			if ( $written !== strlen( $body ) || hash_file( 'sha256', $paths[ $id ] ) !== $definitions[ $id ]['sha256'] ) { unlink( $paths[ $id ] ); return new WP_Error( 'font_write', 'Font write verification failed.' ); }
			$created[ $id ] = $definitions[ $id ]['sha256']; $changed[] = $definitions[ $id ]['file'];
			update_option( 'eburete_mcp_restored_fonts', $created, false );
		}
	}
	update_option( 'eburete_mcp_restored_fonts', $created, false );
	return array( 'action' => $action, 'changed' => $changed, 'note' => 'No theme options, media records, styles, products or orders changed.' );
}

