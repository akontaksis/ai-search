<?php

namespace AISearch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Indexer {

	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'ai_search_index';
	}

	/**
	 * Re-index all published pages.
	 * Returns the number of pages indexed.
	 */
	public function index_all(): int {
		$pages = get_posts( [
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'all',
		] );

		$count = 0;
		foreach ( $pages as $page ) {
			$this->index_post( $page );
			$count++;
		}

		return $count;
	}

	/**
	 * Index (or re-index) a single post.
	 */
	public function index_post( \WP_Post $post ): void {
		global $wpdb;

		$data = [
			'post_id'      => $post->ID,
			'title'        => $post->post_title,
			'url'          => get_permalink( $post->ID ),
			'description'  => $this->get_description( $post ),
			'keywords'     => $this->extract_keywords( $post ),
			'last_indexed' => current_time( 'mysql' ),
		];

		$existing_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$this->table} WHERE post_id = %d",
			$post->ID
		) );

		if ( $existing_id ) {
			$wpdb->update( $this->table, $data, [ 'id' => $existing_id ] );
		} else {
			$wpdb->insert( $this->table, $data );
		}
	}

	private function get_description( \WP_Post $post ): string {
		// 1. Excerpt
		if ( ! empty( $post->post_excerpt ) ) {
			return wp_strip_all_tags( $post->post_excerpt );
		}

		// 2. Yoast meta description
		$yoast = get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
		if ( ! empty( $yoast ) ) {
			return $yoast;
		}

		// 3. First 30 words from cleaned content
		$content = strip_shortcodes( $post->post_content );
		$content = wp_strip_all_tags( $content );
		$content = preg_replace( '/\s+/', ' ', trim( $content ) );

		if ( ! empty( $content ) ) {
			$words = explode( ' ', $content );
			return implode( ' ', array_slice( $words, 0, 30 ) );
		}

		// 4. Fallback: title only
		return $post->post_title;
	}

	private function extract_keywords( \WP_Post $post ): string {
		$title   = mb_strtolower( $post->post_title );
		$content = strip_shortcodes( $post->post_content );
		$content = wp_strip_all_tags( $content );
		$content = mb_strtolower( preg_replace( '/\s+/', ' ', $content ) );

		$stop_words = [
			'και', 'για', 'με', 'στο', 'στη', 'στον', 'στα', 'από', 'του',
			'της', 'τον', 'την', 'τας', 'τα', 'το', 'η', 'ο', 'οι', 'που',
			'πως', 'ότι', 'αλλά', 'ή', 'αν', 'ενώ', 'ως', 'κατά', 'μετά',
		];

		$all_text = $title . ' ' . $content;
		$words    = preg_split( '/[\s,.\-\/]+/', $all_text );
		$words    = array_filter(
			$words,
			fn( $w ) => mb_strlen( $w ) > 3 && ! in_array( $w, $stop_words, true )
		);

		$unique = array_unique( array_values( $words ) );
		return implode( ', ', array_slice( $unique, 0, 20 ) );
	}
}
