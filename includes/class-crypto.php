<?php

namespace AISearch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AES-256-CBC encryption for the Anthropic API key.
 * The encryption key is derived from WordPress's own AUTH_KEY + AUTH_SALT,
 * so the ciphertext is tied to this specific WordPress installation.
 */
class Crypto {

	private const CIPHER = 'AES-256-CBC';
	private const IV_LEN = 16;

	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}

		$key       = self::derive_key();
		$iv        = random_bytes( self::IV_LEN );
		$encrypted = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $encrypted ) {
			return '';
		}

		return base64_encode( $iv . $encrypted );
	}

	public static function decrypt( string $ciphertext ): string {
		if ( '' === $ciphertext ) {
			return '';
		}

		$raw = base64_decode( $ciphertext, true );

		if ( false === $raw || strlen( $raw ) <= self::IV_LEN ) {
			return '';
		}

		$key       = self::derive_key();
		$iv        = substr( $raw, 0, self::IV_LEN );
		$encrypted = substr( $raw, self::IV_LEN );
		$decrypted = openssl_decrypt( $encrypted, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		return false !== $decrypted ? $decrypted : '';
	}

	/** 32-byte key derived from WordPress's secret keys. */
	private static function derive_key(): string {
		$secret = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' )
		        . ( defined( 'AUTH_SALT' ) ? AUTH_SALT : '' );

		return hash( 'sha256', $secret, true );
	}
}
