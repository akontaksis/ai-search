<?php

namespace AISearch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Indexer {

	private string $table;
	private ?ClaudeAPI $claude;

	public function __construct( string $api_key = '' ) {
		global $wpdb;
		$this->table  = $wpdb->prefix . 'ai_search_index';
		$this->claude = $api_key ? new ClaudeAPI( $api_key ) : null;
	}

	/**
	 * Re-index all posts of the configured post types.
	 * Returns the number of posts indexed.
	 */
	public function index_all(): int {
		$types = array_filter(
			array_map( 'trim', explode( ',', get_option( 'ais_post_types', 'page' ) ) )
		);

		if ( empty( $types ) ) {
			$types = [ 'page' ];
		}

		$posts = get_posts( [
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'all',
		] );

		$count = 0;
		foreach ( $posts as $post ) {
			$this->index_post( $post );
			$count++;
		}

		return $count;
	}

	/**
	 * AI-enhance the next un-enhanced index entry (category IS NULL).
	 * Returns ['enhanced' => title, 'remaining' => int] or ['remaining' => 0] when done.
	 */
	public function enhance_next(): array|\WP_Error {
		if ( ! $this->claude ) {
			return new \WP_Error( 'ais_no_api', 'API key απαιτείται για AI Enhancement.' );
		}

		global $wpdb;

		$entry = $wpdb->get_row(
			"SELECT id, post_id, title, description FROM {$this->table} WHERE category IS NULL LIMIT 1"
		);

		if ( ! $entry ) {
			return [ 'remaining' => 0, 'enhanced' => null ];
		}

		$content = '';
		if ( $entry->post_id ) {
			$post = get_post( (int) $entry->post_id );
			if ( $post ) {
				$content = $this->clean_content( $post );
			}
		}

		if ( empty( $content ) ) {
			$content = $entry->description ?? $entry->title;
		}

		$ai = $this->claude->enhance( $entry->title, $content );

		if ( is_wp_error( $ai ) ) {
			// Mark entry as processed even on error (with category = 'Άλλο') to avoid infinite loop
			$wpdb->update(
				$this->table,
				[ 'category' => 'Άλλο' ],
				[ 'id' => (int) $entry->id ],
				[ '%s' ],
				[ '%d' ]
			);
			return $ai;
		}

		$keywords = is_array( $ai['keywords'] ?? null )
			? implode( ', ', $ai['keywords'] )
			: ( $ai['keywords'] ?? '' );

		$wpdb->update(
			$this->table,
			[
				'description'  => sanitize_textarea_field( $ai['description'] ?? '' ),
				'keywords'     => sanitize_text_field( $keywords ),
				'category'     => sanitize_text_field( $ai['category'] ?? 'Άλλο' ),
				'service_type' => sanitize_text_field( $ai['service_type'] ?? 'info' ),
			],
			[ 'id' => (int) $entry->id ],
			[ '%s', '%s', '%s', '%s' ],
			[ '%d' ]
		);

		// Invalidate cached searches so they pick up the new data
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ais_q_%'" );

		$remaining = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->table} WHERE category IS NULL"
		);

		return [
			'remaining' => $remaining,
			'enhanced'  => $entry->title,
		];
	}

	/**
	 * Index (or re-index) a single post — basic, no AI.
	 */
	public function index_post( \WP_Post $post ): void {
		global $wpdb;

		$content = $this->clean_content( $post );

		$data = [
			'post_id'      => $post->ID,
			'title'        => $post->post_title,
			'url'          => get_permalink( $post->ID ),
			'description'  => $this->get_description( $post, $content ),
			'keywords'     => $this->extract_keywords( $post->post_title, $content ),
			'category'     => null,
			'service_type' => null,
			'last_indexed' => current_time( 'mysql' ),
		];

		$existing_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$this->table} WHERE post_id = %d",
			$post->ID
		) );

		$formats = [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ];

		if ( $existing_id ) {
			$wpdb->update( $this->table, $data, [ 'id' => $existing_id ], $formats, [ '%d' ] );
		} else {
			$wpdb->insert( $this->table, $data, $formats );
		}
	}

	/**
	 * Clean post content: strips Elementor data, shortcodes, HTML.
	 */
	private function clean_content( \WP_Post $post ): string {
		// Elementor stores its real content in post meta as JSON
		$elementor = get_post_meta( $post->ID, '_elementor_data', true );
		if ( ! empty( $elementor ) ) {
			$decoded = json_decode( $elementor, true );
			if ( is_array( $decoded ) ) {
				$texts = [];
				$this->walk_elementor( $decoded, $texts );
				if ( ! empty( $texts ) ) {
					return preg_replace( '/\s+/', ' ', trim( implode( ' ', $texts ) ) );
				}
			}
		}

		$content = strip_shortcodes( $post->post_content );
		$content = wp_strip_all_tags( $content );
		return preg_replace( '/\s+/', ' ', trim( $content ) );
	}

	/** Recursively extract text nodes from Elementor's element tree. */
	private function walk_elementor( array $elements, array &$texts ): void {
		foreach ( $elements as $el ) {
			$widget = $el['widgetType'] ?? '';
			$settings = $el['settings'] ?? [];

			if ( in_array( $widget, [ 'text-editor', 'editor' ], true ) ) {
				$texts[] = wp_strip_all_tags( $settings['editor'] ?? '' );
			} elseif ( $widget === 'heading' ) {
				$texts[] = wp_strip_all_tags( $settings['title'] ?? '' );
			} elseif ( $widget === 'text' ) {
				$texts[] = wp_strip_all_tags( $settings['text'] ?? '' );
			}

			if ( ! empty( $el['elements'] ) ) {
				$this->walk_elementor( $el['elements'], $texts );
			}
		}
	}

	private function get_description( \WP_Post $post, string $clean_content ): string {
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
		if ( ! empty( $clean_content ) ) {
			$words = explode( ' ', $clean_content );
			return implode( ' ', array_slice( $words, 0, 30 ) );
		}

		return $post->post_title;
	}

	private function extract_keywords( string $title, string $content ): string {
		$stop_words = [
			'και', 'για', 'με', 'στο', 'στη', 'στον', 'στα', 'από', 'του',
			'της', 'τον', 'την', 'τας', 'τα', 'το', 'η', 'ο', 'οι', 'που',
			'πως', 'ότι', 'αλλά', 'ή', 'αν', 'ενώ', 'ως', 'κατά', 'μετά',
		];

		$all_text = mb_strtolower( $title . ' ' . $content );
		$words    = preg_split( '/[\s,.\-\/]+/', $all_text );
		$words    = array_filter(
			$words,
			fn( $w ) => mb_strlen( $w ) > 3 && ! in_array( $w, $stop_words, true )
		);

		$unique = array_unique( array_values( $words ) );
		return implode( ', ', array_slice( $unique, 0, 20 ) );
	}
}
