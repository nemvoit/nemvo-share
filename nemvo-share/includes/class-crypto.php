<?php
/**
 * Encryption helpers (libsodium, with WordPress's bundled sodium_compat as fallback).
 *
 * @package NemvoShare
 */

namespace NemvoShare;

defined( 'ABSPATH' ) || exit;

/**
 * Symmetric encryption for notes, links, passwords and file keys.
 *
 * The site key comes from the NEMVO_SHARE_KEY constant when wp-config.php defines it,
 * otherwise from a random key stored in the database on activation. Decryption tries
 * both, so adding the constant later keeps existing shares readable.
 */
class Crypto {

	const KEY_OPTION = 'nemvo_share_key';
	const PREFIX     = 'v1.';

	/**
	 * Characters for links: letters and digits, so links survive email clients and chat apps.
	 */
	const TOKEN_ALPHABET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

	/**
	 * Characters for generated passwords: no 0/O, 1/l/I, so they can be read out loud.
	 */
	const PASSWORD_ALPHABET = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

	/**
	 * Loads sodium_compat when the sodium extension is missing.
	 */
	public static function load() {
		if ( ! function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' ) && is_readable( ABSPATH . WPINC . '/sodium_compat/autoload.php' ) ) {
			require_once ABSPATH . WPINC . '/sodium_compat/autoload.php';
		}
	}

	/**
	 * Whether the fast native extension is available.
	 *
	 * @return bool
	 */
	public static function native() {
		return extension_loaded( 'sodium' );
	}

	/**
	 * Creates the stored key if there is none.
	 */
	public static function ensure_key() {
		if ( ! get_option( self::KEY_OPTION ) ) {
			add_option( self::KEY_OPTION, base64_encode( random_bytes( 32 ) ), '', false ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary key stored as text.
		}
	}

	/**
	 * Whether the key comes from wp-config.php.
	 *
	 * @return bool
	 */
	public static function key_in_config() {
		return defined( 'NEMVO_SHARE_KEY' ) && '' !== (string) NEMVO_SHARE_KEY;
	}

	/**
	 * Usable keys, the one used for new data first.
	 *
	 * @return string[] 32-byte binary keys.
	 */
	private static function keys() {
		$keys = array();
		if ( self::key_in_config() ) {
			$keys[] = hash( 'sha256', 'nemvo-share|' . NEMVO_SHARE_KEY, true );
		}
		self::ensure_key();
		$stored = base64_decode( (string) get_option( self::KEY_OPTION ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary key stored as text.
		if ( is_string( $stored ) && 32 === strlen( $stored ) ) {
			$keys[] = $stored;
		}
		return $keys;
	}

	/**
	 * Encrypts a string.
	 *
	 * @param string $plain   Plain text.
	 * @param string $context What it is (bound to the ciphertext, so values can't be swapped).
	 * @return string Printable ciphertext, or '' for an empty input.
	 */
	public static function encrypt( $plain, $context ) {
		if ( '' === (string) $plain ) {
			return '';
		}
		self::load();
		$keys   = self::keys();
		$nonce  = random_bytes( 24 );
		$cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( (string) $plain, 'nemvo-share:' . $context, $nonce, $keys[0] );
		return self::PREFIX . self::b64( $nonce . $cipher );
	}

	/**
	 * Decrypts a string from encrypt().
	 *
	 * @param string $value   Ciphertext.
	 * @param string $context The context used to encrypt it.
	 * @return string|null Plain text, '' for an empty input, null when it can't be decrypted.
	 */
	public static function decrypt( $value, $context ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		if ( 0 !== strpos( $value, self::PREFIX ) ) {
			return null;
		}
		$raw = self::unb64( substr( $value, strlen( self::PREFIX ) ) );
		if ( null === $raw || strlen( $raw ) < 40 ) {
			return null;
		}
		self::load();
		$nonce  = substr( $raw, 0, 24 );
		$cipher = substr( $raw, 24 );
		foreach ( self::keys() as $key ) {
			try {
				$plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( $cipher, 'nemvo-share:' . $context, $nonce, $key );
			} catch ( \Exception $e ) {
				$plain = false;
			}
			if ( false !== $plain ) {
				return $plain;
			}
		}
		return null;
	}

	/**
	 * A random string from an alphabet, without modulo bias.
	 *
	 * @param int    $length   Length.
	 * @param string $alphabet Allowed characters.
	 * @return string
	 */
	public static function random_string( $length, $alphabet ) {
		$max = strlen( $alphabet ) - 1;
		$out = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$out .= $alphabet[ random_int( 0, $max ) ];
		}
		return $out;
	}

	/**
	 * A new secret link token (24 characters, about 143 bits).
	 *
	 * @return string
	 */
	public static function token() {
		return self::random_string( 24, self::TOKEN_ALPHABET );
	}

	/**
	 * A new password such as "Kp7w-M3qa-xT9e-Vh4c" (about 92 bits).
	 *
	 * @return string
	 */
	public static function password() {
		return implode( '-', str_split( self::random_string( 16, self::PASSWORD_ALPHABET ), 4 ) );
	}

	/**
	 * Lookup hash of a token. Links are found by this hash, so the database alone
	 * never needs the link itself.
	 *
	 * @param string $token Token.
	 * @return string 64 hex characters.
	 */
	public static function token_hash( $token ) {
		return hash( 'sha256', 'nemvo-share-token|' . $token );
	}

	/**
	 * URL-safe base64 without padding.
	 *
	 * @param string $bin Binary string.
	 * @return string
	 */
	public static function b64( $bin ) {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encoding ciphertext.
	}

	/**
	 * Decodes b64().
	 *
	 * @param string $text Encoded string.
	 * @return string|null
	 */
	public static function unb64( $text ) {
		$bin = base64_decode( strtr( (string) $text, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding ciphertext.
		return false === $bin ? null : $bin;
	}
}
