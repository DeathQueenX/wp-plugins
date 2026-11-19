<?php
/** Bounded diagnostics. No SQL text, HTTP bodies, credentials or customer data. */
defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'eburete_mcp_register_diagnostics', 20 );
function eburete_mcp_register_diagnostics() {
	$definitions = array(
		'performance-health' => array( 'Performance health', 'Read runtime, autoload size, cron backlog and Action Scheduler counts. Does not execute jobs.', 'eburete_mcp_performance_health', array() ),
		'profile-public-page' => array( 'Profile an uncached public page', 'Runs one anonymous GET on this site with cache bypass and records PHP phases, database and outbound HTTP timing. No browser required. Diagnostics add overhead; compare multiple samples. Never calls arbitrary URLs.', 'eburete_mcp_profile_page', array( 'post_id' => array( 'type' => 'integer', 'minimum' => 0, 'description' => '0 for homepage, otherwise an existing published post/page/product ID.' ) ) ),
	);
	$definitions['configure-performance-guard'] = array( 'Configure Query Monitor guard', 'Installs a fixed early diagnostic guard. enabled=true skips Query Monitor only on anonymous frontend requests; false restores it. Preserves admin, REST, cron and authenticated users.', 'eburete_mcp_configure_guard', array( 'enabled' => array( 'type' => 'boolean' ) ) );
	$definitions['profile-public-page'][3]['disable_query_monitor'] = array( 'type' => 'boolean', 'description' => 'One-request isolation without Query Monitor or query logging; requires early guard.' );
	$definitions = apply_filters( 'eburete_mcp_diagnostic_definitions', $definitions );
	foreach ( $definitions as $slug => $definition ) {
		wp_register_ability( 'eburete-operations/' . $slug, array(
			'label' => $definition[0], 'description' => $definition[1], 'category' => 'eburete-operations',
			'input_schema' => array( 'type' => 'object', 'properties' => $definition[3], 'additionalProperties' => false ),
			'execute_callback' => $definition[2], 'permission_callback' => 'eburete_mcp_operations_can_manage_site',
			'meta' => eburete_mcp_operations_meta( 'performance-health' === $slug, false ),
		) );
	}
}

function eburete_mcp_configure_guard( $input ) {
	if ( ! current_user_can( 'update_plugins' ) || ! wp_is_file_mod_allowed( 'eburete_mcp_guard' ) || ! isset( $input['enabled'] ) || ! is_bool( $input['enabled'] ) ) { return new WP_Error( 'guard_forbidden', 'Update permission and an explicit boolean enabled are required.' ); }
	require_once ABSPATH . 'wp-admin/includes/file.php';
	if ( 'direct' !== get_filesystem_method() ) { return new WP_Error( 'guard_filesystem', 'Direct filesystem access is required.' ); }
	$source = file_get_contents( __DIR__ . '/performance-guard-template.php' );
	$target = WPMU_PLUGIN_DIR . '/eburete-performance-guard.php';
	if ( file_exists( $target ) && hash_file( 'sha256', $target ) !== hash( 'sha256', $source ) ) { return new WP_Error( 'guard_conflict', 'An existing guard differs. It was not overwritten.' ); }
	if ( ! wp_mkdir_p( WPMU_PLUGIN_DIR ) || false === file_put_contents( $target, $source, LOCK_EX ) || hash_file( 'sha256', $target ) !== hash( 'sha256', $source ) ) { return new WP_Error( 'guard_write_failed', 'Guard could not be installed and verified.' ); }
	update_option( 'eburete_mcp_qm_public_disabled', $input['enabled'], false );
	return array( 'installed' => true, 'public_query_monitor_disabled' => (bool) get_option( 'eburete_mcp_qm_public_disabled', false ), 'reversal' => 'Call this ability with enabled=false. Admin and authenticated diagnostics remain available.' );
}

function eburete_mcp_performance_health() {
	global $wpdb;
	$autoload = $wpdb->get_row( "SELECT COUNT(*) AS count, COALESCE(SUM(LENGTH(option_value)),0) AS bytes FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on','auto')", ARRAY_A );
	$largest = $wpdb->get_results( "SELECT option_name, LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on','auto') ORDER BY LENGTH(option_value) DESC LIMIT 15", ARRAY_A );
	// Values never leave the server. Names identify which plugin owns large entries.
	$cron = array( 'events' => 0, 'overdue' => 0, 'hooks' => array() );
	foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
		foreach ( $hooks as $hook => $events ) {
			$count = count( $events );
			$cron['events'] += $count;
			if ( $timestamp < time() - 300 ) {
				$cron['overdue'] += $count;
			}
			$cron['hooks'][ $hook ] = ( $cron['hooks'][ $hook ] ?? 0 ) + $count;
		}
	}
	arsort( $cron['hooks'] );
	$cron['hooks'] = array_slice( $cron['hooks'], 0, 20, true );
	$actions = array( 'available' => false );
	if ( class_exists( 'ActionScheduler' ) && did_action( 'action_scheduler_init' ) ) {
		try {
			$store = ActionScheduler::store();
			$actions = array( 'available' => true );
			foreach ( array( 'pending', 'failed', 'in-progress' ) as $status ) {
				$actions[ $status ] = $store->query_actions( array( 'status' => $status ), 'count' );
			}
		} catch ( Throwable $error ) {
			$actions = array( 'available' => false, 'error' => 'Scheduler counts unavailable' );
		}
	}
	$theme = wp_get_theme();
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$plugins = array();
	foreach ( get_plugins() as $file => $data ) {
		if ( is_plugin_active( $file ) ) {
			$plugins[] = array( 'file' => $file, 'name' => $data['Name'], 'version' => $data['Version'] );
		}
	}
	return array(
		'active_plugins' => $plugins,
		'database_version' => $wpdb->db_version(),
		'debug' => array( 'wp_debug' => defined( 'WP_DEBUG' ) && WP_DEBUG, 'savequeries' => defined( 'SAVEQUERIES' ) && SAVEQUERIES ),
		'php' => array( 'version' => PHP_VERSION, 'effective_memory_limit' => ini_get( 'memory_limit' ), 'wp_memory_limit' => WP_MEMORY_LIMIT, 'wp_max_memory_limit' => WP_MAX_MEMORY_LIMIT, 'max_execution_time' => ini_get( 'max_execution_time' ), 'peak_memory_bytes' => memory_get_peak_usage( true ), 'opcache_enabled' => function_exists( 'opcache_get_status' ) ? (bool) opcache_get_status( false ) : null ),
		'object_cache' => array( 'persistent' => wp_using_ext_object_cache(), 'dropin_exists' => file_exists( WP_CONTENT_DIR . '/object-cache.php' ), 'redis_extension' => extension_loaded( 'redis' ), 'memcached_extension' => extension_loaded( 'memcached' ) ),
		'autoload' => array( 'totals' => $autoload, 'largest' => $largest ),
		'cron' => $cron, 'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
		'action_scheduler' => $actions, 'theme' => array( 'name' => $theme->get( 'Name' ), 'version' => $theme->get( 'Version' ) ),
		'limits' => array( 'No option values, job arguments, logs, SQL text or credentials returned.', 'No queue cleanup, SQL mutation, plugin disabling or cache configuration changes performed.' ),
	);
}

function eburete_mcp_profile_page( $input = array() ) {
	if ( get_transient( 'eburete_mcp_profile_lock' ) ) {
		return new WP_Error( 'profile_busy', 'A profile is running or was recently requested. Retry after 60 seconds.' );
	}
	$id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$url = home_url( '/' );
	if ( $id ) {
		$post = get_post( $id );
		if ( ! $post || 'publish' !== $post->post_status || ! in_array( $post->post_type, array( 'post', 'page', 'product' ), true ) || $post->post_password ) {
			return new WP_Error( 'invalid_target', 'Target must be an unprotected published post, page or product.' );
		}
		$url = get_permalink( $id );
	}
	if ( wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
		return new WP_Error( 'invalid_origin', 'Profile target must be on this site over HTTPS.' );
	}
	set_transient( 'eburete_mcp_profile_lock', true, 60 );
	$token = bin2hex( random_bytes( 32 ) );
	$key = hash( 'sha256', $token );
	$disable_qm = ! empty( $input['disable_query_monitor'] );
	if ( $disable_qm && ! file_exists( WPMU_PLUGIN_DIR . '/eburete-performance-guard.php' ) ) { return new WP_Error( 'guard_missing', 'Install the performance guard first.' ); }
	set_transient( 'eburete_mcp_ticket_' . $key, array_merge( array( 'disable_qm' => $disable_qm ), array_key_exists( 'product_meta_prefetch', $input ) ? array( 'product_meta_prefetch' => (bool) $input['product_meta_prefetch'] ) : array(), array_key_exists( 'image_prefetch', $input ) ? array( 'image_prefetch' => (bool) $input['image_prefetch'] ) : array(), array_key_exists( 'variation_prefetch', $input ) ? array( 'variation_prefetch' => (bool) $input['variation_prefetch'] ) : array() ), 90 );
	$started = microtime( true );
	$response = wp_remote_get( add_query_arg( 'eburete_profile', $key, $url ), array(
		'timeout' => 40, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 2097152,
		'headers' => array( 'X-Eburete-Profile' => $token, 'Cache-Control' => 'no-cache' ),
		'cookies' => array(),
	) );
	delete_transient( 'eburete_mcp_ticket_' . $key );
	$report = get_transient( 'eburete_mcp_report_' . $key );
	delete_transient( 'eburete_mcp_report_' . $key );
	return array( 'elapsed_ms' => round( ( microtime( true ) - $started ) * 1000, 2 ), 'http_status' => is_wp_error( $response ) ? null : wp_remote_retrieve_response_code( $response ), 'transport_error' => is_wp_error( $response ) ? $response->get_error_code() : null, 'profile' => $report ?: null, 'note' => 'A missing profile may indicate a blocked loopback or shutdown not yet completed. Timings start when this plugin loads; early bootstrap is not fully instrumented.' );
}

// Instrument only a short-lived, administrator-issued anonymous profile request.
add_action( 'plugins_loaded', 'eburete_mcp_begin_profile', -9999 );
function eburete_mcp_begin_profile() {
	$token = isset( $_SERVER['HTTP_X_EBURETE_PROFILE'] ) ? (string) $_SERVER['HTTP_X_EBURETE_PROFILE'] : '';
	if ( ! preg_match( '/^[a-f0-9]{64}$/D', $token ) || 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		return;
	}
	$key = hash( 'sha256', $token );
	$profile_options = get_transient( 'eburete_mcp_ticket_' . $key );
	if ( ! $profile_options ) {
		return;
	}
	delete_transient( 'eburete_mcp_ticket_' . $key );
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	do_action( 'litespeed_control_set_nocache', 'Eburete authenticated diagnostic sample' );
	if ( ! defined( 'SAVEQUERIES' ) ) {
		define( 'SAVEQUERIES', true );
	}
	do_action( 'eburete_mcp_profile_started', $profile_options );
	$start = (float) ( $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime( true ) );
	$phases = array( 'plugins_loaded_start' => round( ( microtime( true ) - $start ) * 1000, 2 ) );
	$phases['_query_monitor_disabled'] = defined( 'QM_DISABLED' ) && QM_DISABLED;
	$phases['_savequeries'] = defined( 'SAVEQUERIES' ) && SAVEQUERIES;
	foreach ( array( 'plugins_loaded', 'after_setup_theme', 'init', 'wp_loaded', 'wp', 'template_redirect', 'wp_head', 'wp_footer' ) as $hook ) {
		add_action( $hook, static function () use ( &$phases, $hook, $start ) { $phases[ $hook ] = round( ( microtime( true ) - $start ) * 1000, 2 ); }, PHP_INT_MAX );
	}
	$http = array();
	$pending = array();
	add_filter( 'http_request_args', static function ( $args, $url ) use ( &$pending ) {
		$pending[ hash( 'sha256', $url ) ][] = microtime( true );
		return $args;
	}, PHP_INT_MAX, 2 );
	add_action( 'http_api_debug', static function ( $response, $context, $class, $args, $url ) use ( &$http, &$pending ) {
		if ( 'response' !== $context ) { return; }
		$key = hash( 'sha256', $url );
		$begin = ! empty( $pending[ $key ] ) ? array_shift( $pending[ $key ] ) : null;
		if ( count( $http ) < 100 ) {
			$http[] = array( 'host' => wp_parse_url( $url, PHP_URL_HOST ), 'ms' => null === $begin ? null : round( ( microtime( true ) - $begin ) * 1000, 2 ), 'status' => is_wp_error( $response ) ? $response->get_error_code() : wp_remote_retrieve_response_code( $response ) );
		}
	}, 10, 5 );
	add_action( 'shutdown', static function () use ( $key, $start, &$phases, &$http ) {
		global $wpdb;
		$db_ms = 0;
		$groups = array();
		foreach ( (array) $wpdb->queries as $query ) {
			$ms = (float) $query[1] * 1000;
			$db_ms += $ms;
			// Function-only caller summary; SQL literals and query text are never returned.
			$caller = preg_replace( '/[^a-zA-Z0-9_\\\\:>, .()-]/', '', (string) ( $query[2] ?? 'unknown' ) );
			$caller = substr( $caller, 0, 500 );
			if ( ! isset( $groups[ $caller ] ) ) { $groups[ $caller ] = array( 'count' => 0, 'ms' => 0 ); }
			$groups[ $caller ]['count']++;
			$groups[ $caller ]['ms'] += $ms;
		}
		uasort( $groups, static function ( $a, $b ) { return $b['ms'] <=> $a['ms']; } );
		$phases['_callback_timings'] = Eburete_MCP_Hook_Timings::report();
		$elapsed = round( ( microtime( true ) - $start ) * 1000, 2 );
		set_transient( 'eburete_mcp_report_' . $key, array( 'variation_prefetch' => Eburete_MCP_Variation_Prefetch::report(), 'image_prefetch' => Eburete_MCP_Image_Prefetch::report(), 'metadata_prefetch' => Eburete_MCP_Product_Prefetch::report(), 'shortcodes' => Eburete_MCP_Attribution::report(), 'repeated_queries' => Eburete_MCP_Attribution::query_report(), 'php_ms' => $elapsed, 'phases_ms_from_request_start' => $phases, 'db_queries_total' => $wpdb->num_queries, 'db_logged_queries' => count( (array) $wpdb->queries ), 'db_logged_ms' => round( $db_ms, 2 ), 'db_slowest_callers' => array_slice( $groups, 0, 15, true ), 'http_requests_after_plugins_loaded' => $http, 'peak_memory_bytes' => memory_get_peak_usage( true ), 'warning' => 'SAVEQUERIES and any active Query Monitor add overhead. Queries before plugins_loaded may be absent. HTTP covers WordPress HTTP API only; phase deltas are not exclusive CPU time.' ), 180 );
	}, PHP_INT_MAX );
}
