<?php

namespace AISearch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Search {

	private ClaudeAPI $claude;
	private string $table;

	// Phase 1: no pre-filtering — send top 50 by priority to Claude
	private const CANDIDATE_LIMIT = 50;

	public function __construct( string $api_key ) {
		global $wpdb;
		$this->claude = new ClaudeAPI( $api_key );
		$this->table  = $wpdb->prefix . 'ai_search_index';
	}

	/**
	 * Find the best matching pages for a natural-language query.
	 * Returns array of result arrays, or WP_Error on failure.
	 *
	 * @return array[]|\WP_Error
	 */
	public function find( string $query ): array|\WP_Error {
		global $wpdb;

		$candidates = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, post_id, title, url, description, category, service_type
			 FROM {$this->table}
			 ORDER BY priority DESC
			 LIMIT %d",
			self::CANDIDATE_LIMIT
		) );

		if ( empty( $candidates ) ) {
			return [];
		}

		$matched_ids = $this->claude->match( $query, $candidates );

		if ( is_wp_error( $matched_ids ) ) {
			return $matched_ids;
		}

		if ( empty( $matched_ids ) ) {
			return [];
		}

		// Build lookup and preserve order Claude returned
		$by_id = [];
		foreach ( $candidates as $c ) {
			$by_id[ (int) $c->id ] = $c;
		}

		$results = [];
		foreach ( $matched_ids as $id ) {
			if ( ! isset( $by_id[ $id ] ) ) {
				continue;
			}
			$entry     = $by_id[ $id ];
			$results[] = [
				'index_id'     => (int) $entry->id,
				'title'        => $entry->title,
				'url'          => $entry->url,
				'description'  => $entry->description,
				'category'     => $entry->category,
				'service_type' => $entry->service_type,
			];
		}

		return $results;
	}
}
