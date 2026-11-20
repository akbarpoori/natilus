<?php
/**
 * Natilus — security layer.
 *
 * Dedicated compose capability + at-rest encryption for the provider API
 * key. AES-256-CBC via OpenSSL when available (marker "v1:"), key derived
 * from wp_salt('auth'); a key-hash XOR stream cipher fallback otherwise
 * (marker "v2:"). Legacy plaintext values pass through decrypt() untouched
 * so existing installations keep working until the admin_init migration
 * re-encrypts them.
 *
 * @package ai-content-publishing-agent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KIC_Security {

	/**
	 * Singleton instance.
	 *
	 * @var KIC_Security|null
	 */
	private static $instance = null;

	/**
	 * Lazy singleton bootstrap.
	 *
	 * @return KIC_Security
	 */
	public static function init() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Access the singleton (lazily bootstrapped).
	 *
	 * @return KIC_Security
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::init();
		}
		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	private function __construct() {
		add_action( 'admin_init', array( $this, 'maybe_migrate_api_key' ) );
	}

	/* --------------------------------------------------------------------- */
	/* Encryption                                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * Encrypt a secret for storage. Never returns plaintext.
	 *
	 * @param string $plain Secret value.
	 * @return string Cipher text prefixed "v1:" (openssl) or "v2:" (fallback).
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;

		if ( '' === $plain ) {
			return '';
		}

		$key = hash( 'sha256', wp_salt( 'auth' ), true );

		if ( function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_get_cipher_methods' ) ) {
			$methods = array_map( 'strtolower', openssl_get_cipher_methods() );
			if ( in_array( 'aes-256-cbc', $methods, true ) ) {
				$iv     = random_bytes( 16 );
				$cipher = openssl_encrypt( $plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
				if ( false !== $cipher && '' !== $cipher ) {
					return 'v1:' . base64_encode( $iv . $cipher );
				}
			}
		}

		/*
		 * Fallback: XOR stream cipher keyed with the salt hash. Weaker than
		 * AES, but keeps plaintext out of the database when OpenSSL is absent.
		 */
		$out   = '';
		$len   = strlen( $plain );
		$klen  = strlen( $key );
		$index = 0;
		for ( $index = 0; $index < $len; $index++ ) {
			$out .= $plain[ $index ] ^ $key[ ( $index % $klen ) ];
		}

		return 'v2:' . base64_encode( $out );
	}

	/**
	 * Decrypt a stored secret. Understands "v1:"/"v2:" markers; values with
	 * no marker are legacy plaintext and returned as-is.
	 *
	 * @param string $cipher Stored value.
	 * @return string Plain secret (empty string when undecryptable).
	 */
	public static function decrypt( $cipher ) {
		$cipher = (string) $cipher;

		if ( '' === $cipher ) {
			return '';
		}

		$key = hash( 'sha256', wp_salt( 'auth' ), true );

		if ( 0 === strpos( $cipher, 'v1:' ) ) {
			$raw = base64_decode( substr( $cipher, 3 ), true );
			if ( false === $raw || strlen( $raw ) < 32 ) {
				return '';
			}
			if ( ! function_exists( 'openssl_decrypt' ) ) {
				return '';
			}
			$plain = openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
			return ( false === $plain || null === $plain ) ? '' : (string) $plain;
		}

		if ( 0 === strpos( $cipher, 'v2:' ) ) {
			$raw = base64_decode( substr( $cipher, 3 ), true );
			if ( false === $raw || '' === $raw ) {
				return '';
			}
			$out   = '';
			$len   = strlen( $raw );
			$klen  = strlen( $key );
			$index = 0;
			for ( $index = 0; $index < $len; $index++ ) {
				$out .= $raw[ $index ] ^ $key[ ( $index % $klen ) ];
			}
			return $out;
		}

		// Legacy plaintext (stored before encryption existed): pass through.
		return $cipher;
	}

	/* --------------------------------------------------------------------- */
	/* Capability                                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * The compose capability (filterable).
	 *
	 * @return string
	 */
	public static function capability() {
		return apply_filters( 'kic_capability', 'kubit_ai_compose' );
	}

	/**
	 * Whether the current user may compose AI content.
	 *
	 * @return bool
	 */
	public static function user_can_compose() {
		return current_user_can( self::capability() );
	}

	/* --------------------------------------------------------------------- */
	/* Migration                                                              */
	/* --------------------------------------------------------------------- */

	/**
	 * admin_init: re-encrypt legacy plaintext API keys once.
	 *
	 * @return void
	 */
	public function maybe_migrate_api_key() {
		$settings = get_option( 'kic_settings', array() );
		if ( ! is_array( $settings ) || empty( $settings['api_key'] ) || ! is_string( $settings['api_key'] ) ) {
			return;
		}

		$stored = $settings['api_key'];
		if ( 0 === strpos( $stored, 'v1:' ) || 0 === strpos( $stored, 'v2:' ) ) {
			return;
		}

		$settings['api_key'] = self::encrypt( $stored );
		update_option( 'kic_settings', $settings, false );
	}
}
