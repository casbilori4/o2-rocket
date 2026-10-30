<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Addon;

defined( 'ABSPATH' ) || exit;

// Varnish (ou outro proxy reverso que aceite PURGE, como nas hospedagens Cloudways, DreamPress, Savvii):
// requisição PURGE para cada URL limpa e PURGE com regex ".*" na limpeza total.
final class Varnish {

	const SETTING_ENABLED = 'varnish_enabled';
	const SETTING_HOST    = 'varnish_host';

	public function register_hooks(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		add_action( 'o2_rocket_after_purge_all', array( $this, 'purge_all' ) );
		add_action( 'o2_rocket_before_purge_url', array( $this, 'purge_urls' ) );
	}

	public function get_settings(): array {
		$stored = get_option( O2_ROCKET_OPTION, array() );

		return wp_parse_args(
			is_array( $stored ) ? $stored : array(),
			array(
				self::SETTING_ENABLED => false,
				self::SETTING_HOST    => '',
			)
		);
	}

	// Hospedagens com Varnish ligam isto pelo filtro (ThirdParty\Hosting).
	public function is_enabled(): bool {
		return (bool) apply_filters( 'o2_rocket_varnish_enabled', rest_sanitize_boolean( $this->get_settings()[ self::SETTING_ENABLED ] ) );
	}

	public function purge_all(): void {
		$this->purge( home_url( '/' ), true );
	}

	public function purge_urls( $urls ): void {
		foreach ( array_slice( array_filter( (array) $urls, 'is_string' ), 0, 200 ) as $url ) {
			$this->purge( $url, false );
		}
	}

	public function purge( string $url, bool $regex ): void {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return;
		}

		$path    = isset( $parts['path'] ) ? $parts['path'] : '/';
		$host    = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$targets = $this->get_targets( $host );
		$scheme  = (string) apply_filters( 'o2_rocket_varnish_scheme', 'http' );

		foreach ( $targets as $target ) {
			wp_remote_request(
				$scheme . '://' . $target . $path . ( $regex ? '.*' : '' ),
				array(
					'method'      => 'PURGE',
					'blocking'    => false,
					'timeout'     => 2,
					'redirection' => 0,
					'sslverify'   => false,
					'headers'     => (array) apply_filters(
						'o2_rocket_varnish_headers',
						array(
							'Host'           => $host,
							'X-Purge-Method' => $regex ? 'regex' : 'default',
						)
					),
				)
			);
		}
	}

	// IP/host do Varnish configurado (ou o próprio domínio), mais os do filtro.
	private function get_targets( string $site_host ): array {
		$custom  = trim( (string) $this->get_settings()[ self::SETTING_HOST ] );
		$targets = '' !== $custom ? array( $custom ) : array( $site_host );

		return array_values( array_unique( array_filter( (array) apply_filters( 'o2_rocket_varnish_hosts', $targets ) ) ) );
	}

	public static function sanitize_host( $value ): string {
		return substr( (string) preg_replace( '/[^A-Za-z0-9.\-:\[\]]/', '', is_scalar( $value ) ? trim( (string) $value ) : '' ), 0, 100 );
	}
}
