<?php

namespace AISearch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ClaudeAPI {

	private const API_URL = 'https://api.anthropic.com/v1/messages';
	private const MODEL   = 'claude-haiku-4-5-20251001';

	private string $api_key;

	public function __construct( string $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * Ask Claude to match a user query against candidate index entries.
	 * Returns array of matching IDs, or WP_Error on failure.
	 *
	 * @param string   $query      The user's natural-language query.
	 * @param object[] $candidates Rows from wp_ai_search_index.
	 * @return int[]|\WP_Error
	 */
	public function match( string $query, array $candidates ): array|\WP_Error {
		$site_name = get_option( 'ais_site_name', get_bloginfo( 'name' ) );

		$list = '';
		foreach ( $candidates as $entry ) {
			// Truncate description to keep prompt compact and within rate limits
			$desc = mb_substr( $entry->description ?? '', 0, 100 );
			$list .= sprintf( "ID:%d | %s | %s\n", $entry->id, $entry->title, $desc );
		}

		$prompt = <<<PROMPT
Είσαι βοηθός για το site "{$site_name}". Ο χρήστης έψαξε: "{$query}"

Παρακάτω είναι οι διαθέσιμες σελίδες (ID | Τίτλος | Περιγραφή):
{$list}
Επέστρεψε JSON με τα IDs των 1-3 πιο σχετικών σελίδων. Αν καμία δεν ταιριάζει επέστρεψε κενό array.
Απάντηση ΜΟΝΟ σε JSON: {"matches": [id1, id2]}
PROMPT;

		$response = wp_remote_post(
			self::API_URL,
			[
				'timeout' => 30,
				'headers' => [
					'x-api-key'         => $this->api_key,
					'anthropic-version' => '2023-06-01',
					'content-type'      => 'application/json',
				],
				'body'    => wp_json_encode( [
					'model'      => self::MODEL,
					'max_tokens' => 256,
					'messages'   => [
						[
							'role'    => 'user',
							'content' => $prompt,
						],
					],
				] ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( 200 !== $code ) {
			$message = $data['error']['message'] ?? "API error (HTTP $code)";
			return new \WP_Error( 'ais_api_error', $message );
		}

		$text = $data['content'][0]['text'] ?? '';

		// Strip markdown code fences that some models add
		$text = trim( preg_replace( '/^```(?:json)?\s*|\s*```$/m', '', trim( $text ) ) );

		// Try to decode the full response first, then fall back to extracting the JSON object
		$result = json_decode( $text, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $result ) ) {
			// DOTALL flag so . matches newlines — handles multi-line JSON objects
			if ( preg_match( '/\{.*\}/s', $text, $matches ) ) {
				$result = json_decode( $matches[0], true );
			}
		}

		if ( ! is_array( $result ) || ! isset( $result['matches'] ) || ! is_array( $result['matches'] ) ) {
			return new \WP_Error( 'ais_parse_error', 'Δεν ήταν δυνατή η ανάλυση της απάντησης.' );
		}

		return array_map( 'intval', $result['matches'] );
	}
}
