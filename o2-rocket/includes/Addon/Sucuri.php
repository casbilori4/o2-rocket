<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Addon;

defined( 'ABSPATH' ) || exit;

// Firewall da Sucuri: limpa o cache do WAF junto com o do O² Rocket (API "clear_cache", tudo ou por arquivo).
final class Sucuri {

	const SETTING_ENABLED = 'sucuri_enabled';
	const SETTING_KEY     = 'sucuri_api_key';

	const API = 'https://waf.sucuri.net/api?v2';

	public function register_hooks(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		add_action( 'o2_rocket_after_purge_all', array( $this, 'purge_all' ) );
		add_action( 'o2_rocket_before_purge_url', array( $this, 'purge_urls' ) );
	}

	public function get_credentials(): ?array {
		$stored = get_option( O2_ROCKET_OPTION, array() );
		$key    = is_array( $stored ) && isset( $stored[ self::SETTING_KEY ] ) ? (string) $stored[ self::SETTING_KEY ] : '';

		// Formato da Sucuri: 32 caracteres hexadecimais / 32 caracteres hexadecimais.
		if ( ! preg_match( '#^([a-f0-9]{32})/([a-f0-9]{32})$#i', $key, $match ) ) {
			return null;
		}

		return array(
			'k' => $match[1],
			's' => $match[2],
		);
	}

	public function is_enabled(): bool {
		$stored = get_option( O2_ROCKET_OPTION, array() );

		return is_array( $stored ) && ! empty( $stored[ self::SETTING_ENABLED ] ) && null !== $this->get_credentials();
	}

	public function purge_all(): void {
		$this->request( array() );
	}

	public function purge_urls( $urls ): void {
		foreach ( array_slice( array_filter( (array) $urls, 'is_string' ), 0, 50 ) as $url ) {
			$path = wp_parse_url( $url, PHP_URL_PATH );

			if ( is_string( $path ) && '' !== $path ) {
				$this->request( array( 'file' => $path ) );
			}
		}
	}

	private function request( array $extra ): void {
		$credentials = $this->get_credentials();

		if ( null === $credentials ) {
			return;
		}

		wp_remote_get(
			add_query_arg( array_merge( array( 'a' => 'clear_cache' ), $credentials, $extra ), self::API ),
			array(
				'blocking' => false,
				'timeout'  => 3,
			)
		);
	}

	public static function sanitize_key_value( $value ): string {
		return substr( (string) preg_replace( '/[^a-fA-F0-9\/]/', '', is_scalar( $value ) ? trim( (string) $value ) : '' ), 0, 65 );
	}
}
