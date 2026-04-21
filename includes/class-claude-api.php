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
	 * Match a user query against candidate index entries.
	 * Returns array of matching IDs, or WP_Error on failure.
	 *
	 * @param string   $query
	 * @param object[] $candidates
	 * @return int[]|\WP_Error
	 */
	public function match( string $query, array $candidates ): array|\WP_Error {
		$site_name = get_option( 'ais_site_name', get_bloginfo( 'name' ) );

		$list = '';
		foreach ( $candidates as $entry ) {
			$desc  = mb_substr( $entry->description ?? '', 0, 100 );
			$list .= sprintf( "ID:%d | %s | %s\n", $entry->id, $entry->title, $desc );
		}

		$prompt = <<<PROMPT
Είσαι βοηθός για το site "{$site_name}". Ο χρήστης έψαξε: "{$query}"

Παρακάτω είναι οι διαθέσιμες σελίδες (ID | Τίτλος | Περιγραφή):
{$list}
Επέστρεψε JSON με τα IDs των 1-3 πιο σχετικών σελίδων. Αν καμία δεν ταιριάζει επέστρεψε κενό array.
Απάντηση ΜΟΝΟ σε JSON: {"matches": [id1, id2]}
PROMPT;

		return $this->call( $prompt, 256 );
	}

	/**
	 * AI-enhance a single index entry: generate description, keywords, category, service_type.
	 * Returns associative array or WP_Error.
	 *
	 * @return array|\WP_Error
	 */
	public function enhance( string $title, string $content ): array|\WP_Error {
		$site_name       = get_option( 'ais_site_name', get_bloginfo( 'name' ) );
		$content_preview = mb_substr( $content, 0, 800 );

		$prompt = <<<PROMPT
Αυτό είναι περιεχόμενο από το site "{$site_name}".
Τίτλος: {$title}
Περιεχόμενο: {$content_preview}

Επέστρεψε JSON με τα παρακάτω πεδία:
{
  "description": "1-2 προτάσεις που εξηγούν τι αφορά αυτή η σελίδα με απλά λόγια",
  "keywords": ["λέξη1", "λέξη2"],
  "category": "μία από: Παιδεία, Οικονομικά, Τεχνικά, Κοινωνικά, Υγεία, Αθλητισμός, Πολιτισμός, Περιβάλλον, Διοίκηση, Άλλο",
  "service_type": "μία από: info, action, contact, payment"
}

Για τα keywords βάλε 8-12 λέξεις που θα έψαχνε ένας πολίτης για να βρει αυτή τη σελίδα.
Απάντηση ΜΟΝΟ σε JSON.
PROMPT;

		$result = $this->call( $prompt, 512 );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// $result here is the raw parsed array from the response
		if ( ! isset( $result['description'] ) ) {
			return new \WP_Error( 'ais_enhance_error', 'Μη έγκυρη απάντηση από το AI.' );
		}

		return $result;
	}

	/**
	 * Shared HTTP call to the Anthropic API.
	 * For match() it returns int[], for enhance() it returns the raw decoded array.
	 *
	 * @return mixed|\WP_Error
	 */
	private function call( string $prompt, int $max_tokens ): mixed {
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
					'max_tokens' => $max_tokens,
					'messages'   => [
						[ 'role' => 'user', 'content' => $prompt ],
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
			return new \WP_Error( 'ais_api_error', $data['error']['message'] ?? "API error (HTTP $code)" );
		}

		$text = $data['content'][0]['text'] ?? '';
		$text = trim( preg_replace( '/^```(?:json)?\s*|\s*```$/m', '', trim( $text ) ) );

		$result = json_decode( $text, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $result ) ) {
			if ( preg_match( '/\{.*\}/s', $text, $m ) ) {
				$result = json_decode( $m[0], true );
			}
		}

		if ( ! is_array( $result ) ) {
			return new \WP_Error( 'ais_parse_error', 'Δεν ήταν δυνατή η ανάλυση της απάντησης.' );
		}

		// match() response: extract matches array
		if ( isset( $result['matches'] ) ) {
			return array_map( 'intval', (array) $result['matches'] );
		}

		// enhance() response: return full array
		return $result;
	}
}
