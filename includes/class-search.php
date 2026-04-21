<?php

namespace AISearch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Search {

	private ClaudeAPI $claude;
	private string $table;

	private const CANDIDATE_LIMIT = 15;
	private const CACHE_TTL       = DAY_IN_SECONDS;

	private const STOP_WORDS = [
		'και', 'για', 'με', 'στο', 'στη', 'στον', 'στα', 'από', 'του',
		'της', 'τον', 'την', 'τας', 'τα', 'το', 'η', 'ο', 'οι', 'που',
		'πως', 'ότι', 'αλλά', 'ή', 'αν', 'ενώ', 'ως', 'κατά', 'μετά',
		'the', 'and', 'for', 'with', 'this', 'that',
	];

	public function __construct( string $api_key ) {
		global $wpdb;
		$this->claude = new ClaudeAPI( $api_key );
		$this->table  = $wpdb->prefix . 'ai_search_index';
	}

	/**
	 * Find the best matching pages for a natural-language query.
	 * Phase 2: SQL pre-filtering by keywords/title before sending to Claude.
	 * Results cached per query for 24h.
	 *
	 * @return array[]|\WP_Error
	 */
	public function find( string $query ): array|\WP_Error {
		$cache_key = 'ais_q_' . md5( $query );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$candidates = $this->get_candidates( $query );

		if ( empty( $candidates ) ) {
			return [];
		}

		$matched_ids = $this->claude->match( $query, $candidates );

		if ( is_wp_error( $matched_ids ) ) {
			return $matched_ids;
		}

		if ( empty( $matched_ids ) ) {
			set_transient( $cache_key, [], self::CACHE_TTL );
			return [];
		}

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

		set_transient( $cache_key, $results, self::CACHE_TTL );

		return $results;
	}

	/**
	 * Phase 2: SQL pre-filter — search keywords + title with LIKE before Claude.
	 * Falls back to top-N by priority when no keyword matches are found.
	 */
	private function get_candidates( string $query ): array {
		global $wpdb;

		$words = preg_split( '/\s+/', mb_strtolower( trim( $query ) ) );
		$words = array_values( array_unique( array_filter(
			$words,
			fn( $w ) => mb_strlen( $w ) > 2 && ! in_array( $w, self::STOP_WORDS, true )
		) ) );

		if ( ! empty( $words ) ) {
			$conditions = [];
			$params     = [];

			foreach ( $words as $word ) {
				$like          = '%' . $wpdb->esc_like( $word ) . '%';
				$conditions[]  = '(keywords LIKE %s OR title LIKE %s)';
				$params[]      = $like;
				$params[]      = $like;
			}

			$where    = implode( ' OR ', $conditions );
			$params[] = self::CANDIDATE_LIMIT;

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$candidates = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, post_id, title, url, description, category, service_type
				 FROM {$this->table}
				 WHERE {$where}
				 ORDER BY priority DESC
				 LIMIT %d",
				...$params
			) );

			if ( ! empty( $candidates ) ) {
				return $candidates;
			}
		}

		// Fallback: no keyword match — send top N by priority
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT id, post_id, title, url, description, category, service_type
			 FROM {$this->table}
			 ORDER BY priority DESC
			 LIMIT %d",
			self::CANDIDATE_LIMIT
		) );
	}
}
