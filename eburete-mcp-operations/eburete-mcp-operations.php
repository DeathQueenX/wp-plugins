<?php
/**
 * Plugin Name: Eburete MCP Operations
 * Description: Safe operational abilities for the WordPress MCP Adapter: diagnostics and LiteSpeed Cache management.
 * Version: 0.5.0
 * Update URI: https://github.com/DeathQueenX/wp-plugins
 * Requires at least: 7.1
 * Requires PHP: 8.1
 * Author: Eburete
 * License: GPL-2.0-or-later
 * Text Domain: eburete-mcp-operations
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/diagnostics.php';
require_once __DIR__ . '/updater.php';

/**
 * MCP abilities are registered only when the WordPress Abilities API is present.
 */
add_action( 'wp_abilities_api_categories_init', 'eburete_mcp_operations_register_category' );
add_action( 'wp_abilities_api_init', 'eburete_mcp_operations_register_abilities' );

function eburete_mcp_operations_register_category() {
	wp_register_ability_category(
		'eburete-operations',
		array(
			'label'       => __( 'Eburete Operations', 'eburete-mcp-operations' ),
			'description' => __( 'Restricted diagnostics and cache-maintenance abilities for Eburete.', 'eburete-mcp-operations' ),
		)
	);
}

function eburete_mcp_operations_can_manage_site() {
	return current_user_can( 'manage_options' );
}

function eburete_mcp_operations_meta( $readonly = true, $idempotent = true ) {
	return array(
		'mcp' => array(
			'public' => true,
			'type'   => 'tool',
		),
		'annotations' => array(
			'readonly'       => $readonly,
			'destructive'    => false,
			'idempotent'     => $idempotent,
			'openWorldHint'  => false,
		),
	);
}

function eburete_mcp_operations_register_abilities() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability(
		'eburete-operations/get-diagnostics',
		array(
			'label'               => __( 'Get Site Diagnostics', 'eburete-mcp-operations' ),
			'description'         => __( 'Returns a small, non-sensitive diagnostics snapshot for performance analysis.', 'eburete-mcp-operations' ),
			'category'            => 'eburete-operations',
			'input_schema'        => array( 'type' => 'object', 'properties' => array() ),
			'execute_callback'    => 'eburete_mcp_operations_get_diagnostics',
			'permission_callback' => 'eburete_mcp_operations_can_manage_site',
			'meta'                => eburete_mcp_operations_meta(),
		)
	);

	wp_register_ability(
		'eburete-operations/get-litespeed-status',
		array(
			'label'               => __( 'Get LiteSpeed Status', 'eburete-mcp-operations' ),
			'description'         => __( 'Returns the installed LiteSpeed Cache version and a restricted set of performance-related setting values.', 'eburete-mcp-operations' ),
			'category'            => 'eburete-operations',
			'input_schema'        => array( 'type' => 'object', 'properties' => array() ),
			'execute_callback'    => 'eburete_mcp_operations_get_litespeed_status',
			'permission_callback' => 'eburete_mcp_operations_can_manage_site',
			'meta'                => eburete_mcp_operations_meta(),
		)
	);

	wp_register_ability(
		'eburete-operations/purge-litespeed-page-cache',
		array(
			'label'               => __( 'Purge LiteSpeed Page Cache', 'eburete-mcp-operations' ),
			'description'         => __( 'Purges the LiteSpeed page cache only. It does not delete posts, media, users, orders, or plugin settings.', 'eburete-mcp-operations' ),
			'category'            => 'eburete-operations',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'reason' => array(
						'type'        => 'string',
						'description' => __( 'Short audit reason for the cache purge.', 'eburete-mcp-operations' ),
						'maxLength'   => 120,
					),
				),
			),
			'execute_callback'    => 'eburete_mcp_operations_purge_litespeed_page_cache',
			'permission_callback' => 'eburete_mcp_operations_can_manage_site',
			'meta'                => eburete_mcp_operations_meta( false, false ),
		)
	);

	wp_register_ability(
		'eburete-operations/update-litespeed-performance-settings',
		array(
			'label'               => __( 'Update LiteSpeed Performance Settings', 'eburete-mcp-operations' ),
			'description'         => __( 'Updates only an allowlisted set of LiteSpeed settings that affect response time and front-end loading. Sensitive CDN credentials, object-cache credentials, exclusions and arbitrary configuration are not accepted.', 'eburete-mcp-operations' ),
			'category'            => 'eburete-operations',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'settings' => array(
						'type'                 => 'object',
						'description'          => __( 'Map of approved LiteSpeed performance option IDs to boolean values.', 'eburete-mcp-operations' ),
						'additionalProperties' => array( 'type' => 'boolean' ),
					),
				),
				'required'   => array( 'settings' ),
			),
			'execute_callback'    => 'eburete_mcp_operations_update_litespeed_performance_settings',
			'permission_callback' => 'eburete_mcp_operations_can_manage_site',
			'meta'                => eburete_mcp_operations_meta( false, false ),
		)
	);
}

function eburete_mcp_operations_litespeed_performance_keys() {
	return array(
		'cache',
		'cache-browser',
		'cache-mobile',
		'guest',
		'guest_optm',
		'optm-css_min',
		'optm-js_min',
		'optm-html_min',
		'optm-css_async',
		'optm-css_font_display',
		'media-lazy',
		'media-iframe_lazy',
		'img_optm-auto',
		'img_optm-webp',
		'img_optm-webp_replace_srcset',
	);
}

function eburete_mcp_operations_get_litespeed_option( $option_key ) {
	if ( class_exists( '\\LiteSpeed\\Conf' ) && method_exists( '\\LiteSpeed\\Conf', 'cls' ) ) {
		$configuration = \LiteSpeed\Conf::cls();
		if ( is_object( $configuration ) && method_exists( $configuration, 'conf' ) ) {
			return $configuration->conf( $option_key );
		}
	}

	return get_option( 'litespeed.' . $option_key, null );
}

function eburete_mcp_operations_get_diagnostics() {
	$active_plugins = (array) get_option( 'active_plugins', array() );
	$plugin_names   = array();

	foreach ( $active_plugins as $plugin_file ) {
		$plugin_names[] = basename( dirname( $plugin_file ) );
	}

	return array(
		'wordpress_version' => get_bloginfo( 'version' ),
		'php_version'       => PHP_VERSION,
		'php_memory_limit'  => WP_MEMORY_LIMIT,
		'home_url'          => home_url( '/' ),
		'is_multisite'      => is_multisite(),
		'active_plugin_count' => count( $active_plugins ),
		'active_plugin_slugs' => $plugin_names,
		'litespeed_active'  => defined( 'LSCWP_V' ),
		'woocommerce_active' => class_exists( 'WooCommerce' ),
		'woodmart_active'   => defined( 'WOODMART_THEME_DIR' ) || defined( 'WOODMART_VERSION' ),
	);
}

function eburete_mcp_operations_get_litespeed_status() {
	if ( ! defined( 'LSCWP_V' ) ) {
		return array(
			'active'  => false,
			'message' => __( 'LiteSpeed Cache is not active.', 'eburete-mcp-operations' ),
		);
	}

	$settings = array();
	foreach ( eburete_mcp_operations_litespeed_performance_keys() as $option_key ) {
		$settings[ $option_key ] = eburete_mcp_operations_get_litespeed_option( $option_key );
	}

	return array(
		'active'   => true,
		'version'  => LSCWP_V,
		'settings' => $settings,
		'note'     => __( 'Only non-sensitive, performance-related option keys are returned.', 'eburete-mcp-operations' ),
	);
}

function eburete_mcp_operations_update_litespeed_performance_settings( $input = array() ) {
	if ( ! defined( 'LSCWP_V' ) || ! class_exists( '\\LiteSpeed\\Conf' ) || ! method_exists( '\\LiteSpeed\\Conf', 'cls' ) ) {
		return new WP_Error(
			'eburete_mcp_litespeed_unavailable',
			__( 'LiteSpeed Cache configuration API is unavailable.', 'eburete-mcp-operations' )
		);
	}

	$requested = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();
	if ( ! $requested ) {
		return new WP_Error(
			'eburete_mcp_empty_settings',
			__( 'Provide at least one performance setting.', 'eburete-mcp-operations' )
		);
	}

	$allowed = array_flip( eburete_mcp_operations_litespeed_performance_keys() );
	$updates = array();
	foreach ( $requested as $key => $value ) {
		if ( ! isset( $allowed[ $key ] ) ) {
			return new WP_Error(
				'eburete_mcp_setting_not_allowed',
				sprintf( __( 'The setting "%s" is not permitted by this performance-only plugin.', 'eburete-mcp-operations' ), sanitize_key( $key ) )
			);
		}

		if ( ! is_bool( $value ) && ! in_array( $value, array( 0, 1, '0', '1' ), true ) ) {
			return new WP_Error(
				'eburete_mcp_invalid_setting_value',
				sprintf( __( 'The setting "%s" must be true or false.', 'eburete-mcp-operations' ), $key )
			);
		}

		$updates[ $key ] = (bool) $value;
	}

	$configuration = \LiteSpeed\Conf::cls();
	if ( ! is_object( $configuration ) || ! method_exists( $configuration, 'update_confs' ) ) {
		return new WP_Error(
			'eburete_mcp_litespeed_unavailable',
			__( 'LiteSpeed Cache cannot update settings through its supported configuration API.', 'eburete-mcp-operations' )
		);
	}

	$configuration->update_confs( $updates );

	$applied = array();
	foreach ( array_keys( $updates ) as $key ) {
		$applied[ $key ] = eburete_mcp_operations_get_litespeed_option( $key );
	}

	return array(
		'updated' => $applied,
		'note'    => __( 'LiteSpeed applies its own validation, file updates, queue maintenance, and any required cache invalidation.', 'eburete-mcp-operations' ),
	);
}

function eburete_mcp_operations_purge_litespeed_page_cache( $input = array() ) {
	if ( ! defined( 'LSCWP_V' ) || ! is_callable( array( '\\LiteSpeed\\Purge', 'purge_all_lscache' ) ) ) {
		return new WP_Error(
			'eburete_mcp_litespeed_unavailable',
			__( 'LiteSpeed Cache is not active or its purge API is unavailable.', 'eburete-mcp-operations' )
		);
	}

	$reason = isset( $input['reason'] ) ? sanitize_text_field( $input['reason'] ) : 'MCP maintenance';
	\LiteSpeed\Purge::purge_all_lscache( 'Eburete MCP: ' . $reason );

	return array(
		'purged' => true,
		'scope'  => 'LiteSpeed page cache',
		'reason' => $reason,
	);
}
