<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ai_search_index" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ai_search_log" );

delete_option( 'ais_anthropic_api_key' );
delete_option( 'ais_site_name' );
delete_option( 'ais_placeholder' );
delete_option( 'ais_fallback_message' );
delete_option( 'ais_post_types' );

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ai_search_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_ai_search_%'" );
