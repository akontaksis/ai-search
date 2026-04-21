<?php
/**
 * Plugin Name: AI Search
 * Description: AI-powered natural language search για WordPress sites.
 * Version:     1.0.0
 * Author:      Athanasios Kontaksis
 * Text Domain: ai-search
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

namespace AISearch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AIS_VERSION', '1.0.0' );
define( 'AIS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once AIS_PLUGIN_DIR . 'includes/class-crypto.php';
require_once AIS_PLUGIN_DIR . 'includes/class-indexer.php';
require_once AIS_PLUGIN_DIR . 'includes/class-claude-api.php';
require_once AIS_PLUGIN_DIR . 'includes/class-search.php';
require_once AIS_PLUGIN_DIR . 'includes/class-admin.php';

register_activation_hook( __FILE__, __NAMESPACE__ . '\activate' );
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\deactivate' );

function activate(): void {
	create_tables();
	flush_rewrite_rules();
}

function deactivate(): void {
	flush_rewrite_rules();
}

function create_tables(): void {
	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();

	$table_index = $wpdb->prefix . 'ai_search_index';
	$sql_index   = "CREATE TABLE $table_index (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  post_id bigint(20) NULL,
  title varchar(255) NOT NULL,
  url varchar(500) NOT NULL,
  description text,
  keywords text,
  category varchar(100),
  service_type varchar(50),
  is_external tinyint(1) DEFAULT 0,
  priority int DEFAULT 5,
  last_indexed datetime,
  PRIMARY KEY  (id),
  KEY idx_keywords (keywords(100)),
  KEY idx_category (category)
) $charset_collate;";

	$table_log = $wpdb->prefix . 'ai_search_log';
	$sql_log   = "CREATE TABLE $table_log (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  query varchar(500),
  matched_index_id bigint(20) NULL,
  cache_hit tinyint(1) DEFAULT 0,
  response_time_ms int,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_query (query(100)),
  KEY idx_date (created_at)
) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql_index );
	dbDelta( $sql_log );
}

add_action( 'init', __NAMESPACE__ . '\boot' );

function boot(): void {
	add_shortcode( 'ai_search', __NAMESPACE__ . '\render_shortcode' );
	add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_frontend_assets' );
	add_action( 'wp_ajax_ais_search', __NAMESPACE__ . '\handle_search' );
	add_action( 'wp_ajax_nopriv_ais_search', __NAMESPACE__ . '\handle_search' );
}

function enqueue_frontend_assets(): void {
	wp_enqueue_style(
		'ais-search',
		AIS_PLUGIN_URL . 'assets/css/search.css',
		[],
		AIS_VERSION
	);
	wp_enqueue_script(
		'ais-search',
		AIS_PLUGIN_URL . 'assets/js/search.js',
		[],
		AIS_VERSION,
		true
	);
	wp_localize_script( 'ais-search', 'aisData', [
		'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
		'nonce'       => wp_create_nonce( 'ais_search_nonce' ),
		'placeholder' => get_option( 'ais_placeholder', 'Τι ψάχνετε;' ),
		'fallback'    => get_option( 'ais_fallback_message', 'Δεν βρέθηκε σχετική υπηρεσία.' ),
	] );
}

function render_shortcode(): string {
	ob_start();
	include AIS_PLUGIN_DIR . 'templates/search-form.php';
	return ob_get_clean();
}

function handle_search(): void {
	check_ajax_referer( 'ais_search_nonce', 'nonce' );

	$query = sanitize_text_field( wp_unslash( $_POST['query'] ?? '' ) );

	if ( empty( $query ) ) {
		wp_send_json_error( 'Παρακαλώ εισάγετε ερώτημα.' );
	}

	$api_key_stored = get_option( 'ais_anthropic_api_key', '' );

	if ( empty( $api_key_stored ) ) {
		wp_send_json_error( 'Το API key δεν έχει ρυθμιστεί.' );
	}

	$api_key = Crypto::decrypt( $api_key_stored );

	if ( empty( $api_key ) ) {
		wp_send_json_error( 'Δεν ήταν δυνατή η ανάκτηση του API key. Ορίστε το ξανά στις ρυθμίσεις.' );
	}

	$start   = microtime( true );
	$search  = new Search( $api_key );
	$results = $search->find( $query );
	$elapsed = (int) ( ( microtime( true ) - $start ) * 1000 );

	log_query( $query, $results, $elapsed );

	if ( is_wp_error( $results ) ) {
		wp_send_json_error( $results->get_error_message() );
	}

	wp_send_json_success( $results );
}

function log_query( string $query, $results, int $elapsed_ms ): void {
	global $wpdb;

	$matched_id = null;
	if ( is_array( $results ) && ! empty( $results ) && isset( $results[0]['index_id'] ) ) {
		$matched_id = (int) $results[0]['index_id'];
	}

	$wpdb->insert(
		$wpdb->prefix . 'ai_search_log',
		[
			'query'            => $query,
			'matched_index_id' => $matched_id,
			'cache_hit'        => 0,
			'response_time_ms' => $elapsed_ms,
			'created_at'       => current_time( 'mysql' ),
		]
	);
}

if ( is_admin() ) {
	new Admin();
}
