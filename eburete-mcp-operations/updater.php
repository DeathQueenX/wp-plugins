<?php
/** Explicit self-update only, from the owner's fixed public repository. */
defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'eburete_mcp_register_updater', 20 );
function eburete_mcp_register_updater() {
	foreach ( array( 'check-self-update', 'install-self-update' ) as $name ) {
		$install = 'install-self-update' === $name;
		$meta = eburete_mcp_operations_meta( ! $install, false );
		$meta['annotations']['destructive'] = $install;
		wp_register_ability( 'eburete-operations/' . $name, array(
			'label' => $install ? 'Update Eburete MCP Operations' : 'Check Eburete MCP Operations update',
			'description' => $install ? 'Replace only this plugin with the reviewed version and SHA-256 returned by check-self-update. Requires update_plugins and direct filesystem access. Loads executable PHP from the fixed DeathQueenX/wp-plugins repository. Verify the next MCP request after updating.' : 'Read installed version and validated release manifest from DeathQueenX/wp-plugins. Returns version and SHA-256 to pin an explicit update.',
			'category' => 'eburete-operations',
			'input_schema' => $install ? array( 'type' => 'object', 'properties' => array(
				'version' => array( 'type' => 'string', 'pattern' => '^\d+\.\d+\.\d+$' ),
				'sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
			), 'required' => array( 'version', 'sha256' ), 'additionalProperties' => false ) : array( 'type' => 'object', 'properties' => array() ),
			'execute_callback' => $install ? 'eburete_mcp_install_self_update' : 'eburete_mcp_check_self_update',
			'permission_callback' => $install ? 'eburete_mcp_can_update_self' : 'eburete_mcp_operations_can_manage_site',
			'meta' => $meta,
		) );
	}
}

function eburete_mcp_can_update_self() {
	return current_user_can( 'manage_options' ) && current_user_can( 'update_plugins' ) && ! is_multisite() && wp_is_file_mod_allowed( 'eburete_mcp_update' );
}

function eburete_mcp_installed_version() {
	$data = get_file_data( __DIR__ . '/eburete-mcp-operations.php', array( 'version' => 'Version' ) );
	return $data['version'];
}

function eburete_mcp_release_manifest() {
	$response = wp_safe_remote_get( 'https://raw.githubusercontent.com/DeathQueenX/wp-plugins/main/releases/eburete-mcp-operations.json', array( 'timeout' => 15, 'redirection' => 0, 'limit_response_size' => 16384, 'sslverify' => true ) );
	if ( is_wp_error( $response ) ) { return $response; }
	if ( 200 !== wp_remote_retrieve_response_code( $response ) ) { return new WP_Error( 'release_unavailable', 'Release manifest could not be retrieved.' ); }
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $data ) || 'eburete-mcp-operations' !== ( $data['slug'] ?? '' ) || ! preg_match( '/^\d+\.\d+\.\d+$/D', (string) ( $data['version'] ?? '' ) ) || ! preg_match( '/^[a-f0-9]{64}$/D', (string) ( $data['sha256'] ?? '' ) ) ) {
		return new WP_Error( 'invalid_manifest', 'Invalid plugin, version or SHA-256 in release manifest.' );
	}
	// URL is constructed, never accepted from MCP arguments or the manifest.
	$data['package'] = 'https://raw.githubusercontent.com/DeathQueenX/wp-plugins/main/packages/eburete-mcp-operations-' . $data['version'] . '.zip';
	return $data;
}

function eburete_mcp_check_self_update() {
	$release = eburete_mcp_release_manifest();
	if ( is_wp_error( $release ) ) { return $release; }
	return array( 'installed' => eburete_mcp_installed_version(), 'available' => version_compare( $release['version'], eburete_mcp_installed_version(), '>' ), 'release' => $release, 'source' => 'DeathQueenX/wp-plugins', 'trust' => 'SHA-256 verifies package integrity against the HTTPS manifest; this is not an independent digital signature. Repository writers are trusted code publishers.' );
}

function eburete_mcp_validate_update_zip( $file, $version ) {
	if ( ! class_exists( 'ZipArchive' ) ) { return new WP_Error( 'zip_missing', 'ZipArchive is required to validate the package before installation.' ); }
	$zip = new ZipArchive();
	if ( true !== $zip->open( $file ) ) { return new WP_Error( 'invalid_zip', 'Cannot open update ZIP.' ); }
	try {
		$total = 0;
		$seen = array();
		if ( $zip->numFiles > 100 ) { return new WP_Error( 'invalid_zip', 'Too many archive entries.' ); }
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			$name = $stat['name'];
			if ( isset( $seen[ $name ] ) || ! preg_match( '#^eburete-mcp-operations/[a-zA-Z0-9_./-]+$#D', $name ) || str_contains( $name, '..' ) || str_contains( $name, '//' ) ) { return new WP_Error( 'unsafe_zip', 'Archive path is outside the plugin or duplicated.' ); }
			$seen[ $name ] = true;
			$total += $stat['size'];
			if ( $total > 10485760 ) { return new WP_Error( 'oversized_zip', 'Uncompressed package exceeds 10 MB.' ); }
			$opsys = 0; $attr = 0;
			if ( $zip->getExternalAttributesIndex( $i, $opsys, $attr ) && 0120000 === ( ( $attr >> 16 ) & 0170000 ) ) { return new WP_Error( 'unsafe_zip', 'Symlinks are not accepted.' ); }
		}
		$main = $zip->getFromName( 'eburete-mcp-operations/eburete-mcp-operations.php' );
		if ( false === $main || ! preg_match( '/^\s*\*\s*Version:\s*' . preg_quote( $version, '/' ) . '\s*$/m', $main ) ) { return new WP_Error( 'version_mismatch', 'ZIP plugin header does not match the reviewed version.' ); }
		return true;
	} finally { $zip->close(); }
}

function eburete_mcp_restore_active_state( $plugin, $was_active ) {
	if ( ! $was_active || is_plugin_active( $plugin ) ) { return true; }
	$activation = activate_plugin( $plugin, '', false, true );
	if ( is_wp_error( $activation ) ) { return $activation; }
	return is_plugin_active( $plugin ) ? true : new WP_Error( 'reactivation_failed', 'Updated plugin could not be reactivated.' );
}

function eburete_mcp_install_self_update( $input ) {
	if ( ! eburete_mcp_can_update_self() ) { return new WP_Error( 'forbidden', 'Self-update permission denied.' ); }
	$release = eburete_mcp_release_manifest();
	if ( is_wp_error( $release ) ) { return $release; }
	if ( ( $input['version'] ?? '' ) !== $release['version'] || ! hash_equals( $release['sha256'], (string) ( $input['sha256'] ?? '' ) ) ) { return new WP_Error( 'release_changed', 'Release differs from the reviewed version or SHA-256. Check again.' ); }
	if ( ! version_compare( $release['version'], eburete_mcp_installed_version(), '>' ) ) { return new WP_Error( 'not_newer', 'Only newer versions may be installed.' ); }
	if ( version_compare( PHP_VERSION, '8.1', '<' ) || version_compare( get_bloginfo( 'version' ), '7.1', '<' ) ) { return new WP_Error( 'incompatible', 'WordPress 7.1 and PHP 8.1 are required.' ); }
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	if ( 'direct' !== get_filesystem_method() ) { return new WP_Error( 'filesystem_access', 'Direct filesystem access is required. No FTP credentials are requested or stored.' ); }
	$lock = 'eburete_mcp_self_update_lock';
	$old = (int) get_option( $lock, 0 );
	if ( $old && $old < time() - 300 ) { delete_option( $lock ); }
	if ( ! add_option( $lock, time(), '', false ) ) { return new WP_Error( 'update_busy', 'Another self-update is in progress.' ); }
	$file = wp_tempnam( 'eburete-mcp-update.zip' );
	if ( ! $file ) { delete_option( $lock ); return new WP_Error( 'temp_failed', 'Cannot create temporary package.' ); }
	try {
		$response = wp_safe_remote_get( $release['package'], array( 'timeout' => 40, 'redirection' => 0, 'sslverify' => true, 'stream' => true, 'filename' => $file, 'limit_response_size' => 5242880 ) );
		if ( is_wp_error( $response ) ) { return $response; }
		if ( 200 !== wp_remote_retrieve_response_code( $response ) || ! hash_equals( $release['sha256'], hash_file( 'sha256', $file ) ) ) { return new WP_Error( 'package_verification_failed', 'Download failed or SHA-256 mismatch. Installed files were not touched.' ); }
		$valid = eburete_mcp_validate_update_zip( $file, $release['version'] );
		if ( is_wp_error( $valid ) ) { return $valid; }
		$plugin = plugin_basename( __DIR__ . '/eburete-mcp-operations.php' );
		$was_active = is_plugin_active( $plugin );
		$current = get_site_transient( 'update_plugins' );
		if ( ! is_object( $current ) ) { $current = new stdClass(); }
		if ( ! isset( $current->response ) || ! is_array( $current->response ) ) { $current->response = array(); }
		$current->response[ $plugin ] = (object) array( 'slug' => 'eburete-mcp-operations', 'plugin' => $plugin, 'new_version' => $release['version'], 'package' => $release['package'], 'url' => 'https://github.com/DeathQueenX/wp-plugins' );
		set_site_transient( 'update_plugins', $current );
		$download = static function ( $reply, $package, $upgrader, $extra ) use ( $file, $plugin, $release ) {
			if ( ( $extra['plugin'] ?? '' ) === $plugin ) {
				return $package === $release['package'] ? $file : new WP_Error( 'wrong_package', 'Unexpected update package.' );
			}
			return $reply;
		};
		add_filter( 'upgrader_pre_download', $download, PHP_INT_MAX, 4 );
		try {
			$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
			$result = $upgrader->upgrade( $plugin );
		} finally {
			remove_filter( 'upgrader_pre_download', $download, PHP_INT_MAX );
			wp_clean_plugins_cache();
		}
		if ( is_wp_error( $result ) ) { return $result; }
		if ( ! $result || eburete_mcp_installed_version() !== $release['version'] ) { return new WP_Error( 'upgrade_failed', 'WordPress did not confirm the installed version. Inspect the plugin before retrying.' ); }
		$restored = eburete_mcp_restore_active_state( $plugin, $was_active );
		if ( is_wp_error( $restored ) ) { return $restored; }
		return array( 'installed' => eburete_mcp_installed_version(), 'active' => is_plugin_active( $plugin ), 'next_step' => 'Reconnect and discover abilities on a new MCP request. Current request still runs the previous loaded PHP.' );
	} finally {
		if ( file_exists( $file ) ) { wp_delete_file( $file ); }
		delete_option( $lock );
	}
}
