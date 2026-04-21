<?php
/**
 * Plugin Name: AI Search
 * Description: AI-powered natural language search για WordPress sites.
 * Version:     1.1.0
 * Author:      Athanasios Kontaksis
 * Text Domain: ai-search
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

namespace AISearch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AIS_VERSION', '1.1.0' );
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
	setup_fulltext();
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

/**
 * Add FULLTEXT index on title + keywords for existing installs.
 * Safe to run multiple times — checks if index already exists.
 */
function setup_fulltext(): void {
	global $wpdb;
	$table = $wpdb->prefix . 'ai_search_index';

	$exists = $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM information_schema.STATISTICS
		 WHERE table_schema = %s AND table_name = %s AND index_name = 'ft_search'",
		DB_NAME,
		$table
	) );

	if ( ! $exists ) {
		$wpdb->query( "ALTER TABLE {$table} ADD FULLTEXT INDEX ft_search (title, keywords)" );
	}
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\load_textdomain' );
add_action( 'plugins_loaded', __NAMESPACE__ . '\maybe_upgrade' );

function load_textdomain(): void {
	load_plugin_textdomain( 'ai-search', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

function maybe_upgrade(): void {
	// Run once per DB version — adds FULLTEXT index to existing installs
	if ( get_option( 'ais_db_version' ) !== '1.1' ) {
		setup_fulltext();
		update_option( 'ais_db_version', '1.1' );
	}
}

add_action( 'init', __NAMESPACE__ . '\boot' );

function boot(): void {
	add_shortcode( 'ai_search', __NAMESPACE__ . '\render_shortcode' );
	add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_frontend_assets' );
	add_action( 'wp_ajax_ais_search', __NAMESPACE__ . '\handle_search' );
	add_action( 'wp_ajax_nopriv_ais_search', __NAMESPACE__ . '\handle_search' );
	add_action( 'wp_ajax_ais_enhance_next', __NAMESPACE__ . '\handle_enhance_next' );
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

	if ( is_rate_limited() ) {
		wp_send_json_error( 'Πολλές αναζητήσεις. Δοκιμάστε ξανά σε λίγο.' );
		return;
	}

	$query = sanitize_text_field( wp_unslash( $_POST['query'] ?? '' ) );

	if ( empty( $query ) || mb_strlen( $query ) > 300 ) {
		wp_send_json_error( 'Παρακαλώ εισάγετε έγκυρο ερώτημα (έως 300 χαρακτήρες).' );
		return;
	}

	$api_key_stored = get_option( 'ais_anthropic_api_key', '' );

	if ( empty( $api_key_stored ) ) {
		wp_send_json_error( 'Το API key δεν έχει ρυθμιστεί.' );
		return;
	}

	$api_key = Crypto::decrypt( $api_key_stored );

	if ( empty( $api_key ) ) {
		wp_send_json_error( 'Δεν ήταν δυνατή η ανάκτηση του API key. Ορίστε το ξανά στις ρυθμίσεις.' );
		return;
	}

	$start   = microtime( true );
	$search  = new Search( $api_key );
	$results = $search->find( $query );
	$elapsed = (int) ( ( microtime( true ) - $start ) * 1000 );

	log_query( $query, $results, $elapsed );

	if ( is_wp_error( $results ) ) {
		wp_send_json_error( $results->get_error_message() );
		return;
	}

	wp_send_json_success( $results );
}

function is_rate_limited(): bool {
	$ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
	$key   = 'ais_rate_' . md5( $ip );
	$count = (int) get_transient( $key );

	if ( $count >= 10 ) {
		return true;
	}

	set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
	return false;
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
		],
		[ '%s', '%d', '%d', '%d', '%s' ]
	);
}

function handle_enhance_next(): void {
	check_ajax_referer( 'ais_enhance_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Δεν έχετε δικαίωμα.' );
		return;
	}

	$api_key = Crypto::decrypt( get_option( 'ais_anthropic_api_key', '' ) );

	if ( empty( $api_key ) ) {
		wp_send_json_error( 'Ορίστε πρώτα το API key.' );
		return;
	}

	$indexer = new Indexer( $api_key );
	$result  = $indexer->enhance_next();

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( $result->get_error_message() );
		return;
	}

	wp_send_json_success( $result );
}

if ( is_admin() ) {
	new Admin();
}
