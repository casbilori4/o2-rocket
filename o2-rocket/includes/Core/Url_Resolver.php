<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Core;

defined( 'ABSPATH' ) || exit;

// URL de arquivo do próprio site <=> caminho no disco. Respeita wp-content, plugins e uploads fora do padrão
// e WordPress em subpasta. Nunca resolve para fora das pastas do WordPress (sem "..").
final class Url_Resolver {

	private static ?array $bases = null;

	// Caminho absoluto do arquivo; vazio se a URL for externa, inválida ou o arquivo não existir.
	public static function to_path( string $url ): string {
		$parts = self::parse( $url );

		if ( null === $parts ) {
			return '';
		}

		foreach ( self::get_bases() as $base ) {
			if ( ! in_array( $parts['host'], $base['hosts'], true ) || 0 !== strpos( $parts['path'], $base['path'] ) ) {
				continue;
			}

			$relative = substr( $parts['path'], strlen( $base['path'] ) );

			if ( false !== strpos( '/' . $relative . '/', '/../' ) || false !== strpos( $relative, "\0" ) ) {
				return '';
			}

			$file = $base['dir'] . ltrim( $relative, '/' );
			$real = realpath( $file );

			if ( false === $real || ! is_file( $real ) ) {
				continue;
			}

			$real = wp_normalize_path( $real );

			// Links simbólicos podem apontar para fora: aceita só o que ficar dentro de uma das pastas conhecidas.
			foreach ( self::get_bases() as $allowed ) {
				if ( 0 === strpos( $real, $allowed['real'] ) ) {
					return $real;
				}
			}

			return '';
		}

		return '';
	}

	public static function is_local( string $url ): bool {
		$parts = self::parse( $url );

		return null !== $parts && in_array( $parts['host'], self::get_hosts(), true );
	}

	// "//host/x" e "/x" viram URLs absolutas com o esquema e o host do site.
	public static function to_absolute( string $url ): string {
		$url = trim( html_entity_decode( $url, ENT_QUOTES ) );

		if ( 0 === strpos( $url, '//' ) ) {
			return ( is_ssl() ? 'https:' : 'http:' ) . $url;
		}

		if ( '' !== $url && '/' === $url[0] ) {
			return (string) preg_replace( '#^(https?://[^/]+).*$#i', '$1', home_url() ) . $url;
		}

		return $url;
	}

	// Caminho de um arquivo dentro das pastas do WordPress => URL pública; vazio se estiver fora delas.
	public static function to_url( string $path ): string {
		$path = wp_normalize_path( $path );

		foreach ( self::get_bases() as $base ) {
			if ( 0 === strpos( $path, $base['dir'] ) ) {
				return $base['url'] . implode( '/', array_map( 'rawurlencode', explode( '/', substr( $path, strlen( $base['dir'] ) ) ) ) );
			}
		}

		return '';
	}

	// Limpa o cache das bases (testes ou troca de site no multisite).
	public static function reset(): void {
		self::$bases = null;
	}

	private static function parse( string $url ): ?array {
		$url = self::to_absolute( $url );

		if ( '' === $url || 1 !== preg_match( '#^https?://#i', $url ) ) {
			return null;
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return null;
		}

		return array(
			'host' => strtolower( $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) ),
			'path' => rawurldecode( isset( $parts['path'] ) ? $parts['path'] : '/' ),
		);
	}

	private static function get_hosts(): array {
		$hosts = array();

		foreach ( self::get_bases() as $base ) {
			$hosts = array_merge( $hosts, $base['hosts'] );
		}

		return array_values( array_unique( $hosts ) );
	}

	// Pares URL => pasta, do caminho mais longo para o mais curto.
	private static function get_bases(): array {
		if ( null !== self::$bases ) {
			return self::$bases;
		}

		$pairs   = array(
			array( content_url( '/' ), WP_CONTENT_DIR ),
			array( plugins_url( '/' ), WP_PLUGIN_DIR ),
			array( includes_url( '/' ), ABSPATH . WPINC ),
			array( site_url( '/' ), ABSPATH ),
		);
		$uploads = wp_get_upload_dir();

		if ( empty( $uploads['error'] ) && ! empty( $uploads['baseurl'] ) && ! empty( $uploads['basedir'] ) ) {
			$pairs[] = array( $uploads['baseurl'], $uploads['basedir'] );
		}

		$home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) . ( wp_parse_url( home_url(), PHP_URL_PORT ) ? ':' . wp_parse_url( home_url(), PHP_URL_PORT ) : '' ) );
		$bases     = array();

		foreach ( $pairs as $pair ) {
			$parts = wp_parse_url( $pair[0] );

			if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
				continue;
			}

			$host = strtolower( $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) );
			$dir  = trailingslashit( wp_normalize_path( $pair[1] ) );
			$real = realpath( $dir );

			$bases[] = array(
				'hosts' => array_values( array_unique( array( $host, $home_host ) ) ),
				'path'  => trailingslashit( rawurldecode( isset( $parts['path'] ) ? $parts['path'] : '/' ) ),
				'url'   => trailingslashit( $pair[0] ),
				'dir'   => $dir,
				'real'  => false === $real ? $dir : trailingslashit( wp_normalize_path( $real ) ),
			);
		}

		usort(
			$bases,
			static function ( $a, $b ) {
				return strlen( $b['path'] ) - strlen( $a['path'] );
			}
		);

		self::$bases = $bases;

		return $bases;
	}
}
