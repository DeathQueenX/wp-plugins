<?php
/** Eburete managed performance guard v1. No business logic is changed. */
defined( 'ABSPATH' ) || exit;
$eburete_isolate = false;
$eburete_token = (string) ( $_SERVER['HTTP_X_EBURETE_PROFILE'] ?? '' );
if ( preg_match( '/^[a-f0-9]{64}$/D', $eburete_token ) ) {
	$eburete_ticket = get_transient( 'eburete_mcp_ticket_' . hash( 'sha256', $eburete_token ) );
	$eburete_isolate = is_array( $eburete_ticket ) && ! empty( $eburete_ticket['disable_qm'] );
}
$eburete_public = ! is_admin() && ! ( defined( 'DOING_CRON' ) && DOING_CRON ) && ! ( defined( 'WP_CLI' ) && WP_CLI );
$eburete_uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
if ( str_contains( $eburete_uri, '/wp-json/' ) || str_contains( $eburete_uri, 'rest_route=' ) || str_contains( $eburete_uri, 'wp-login.php' ) || str_contains( $eburete_uri, 'wc-api=' ) ) { $eburete_public = false; }
foreach ( array_keys( $_COOKIE ) as $eburete_cookie ) {
	if ( str_starts_with( $eburete_cookie, 'wordpress_logged_in_' ) || str_starts_with( $eburete_cookie, 'wordpress_sec_' ) ) { $eburete_public = false; }
}
if ( $eburete_isolate || ( $eburete_public && get_option( 'eburete_mcp_qm_public_disabled', false ) ) ) {
	if ( ! defined( 'QM_DISABLED' ) ) { define( 'QM_DISABLED', true ); }
	if ( ! defined( 'SAVEQUERIES' ) ) { define( 'SAVEQUERIES', false ); }
}
unset( $eburete_token, $eburete_ticket, $eburete_isolate, $eburete_public, $eburete_uri, $eburete_cookie );
