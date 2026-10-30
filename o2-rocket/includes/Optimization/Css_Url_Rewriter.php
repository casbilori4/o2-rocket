<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Optimization;

defined( 'ABSPATH' ) || exit;

// Ajustes em CSS que muda de pasta (minificado, combinado, em linha): referências relativas viram absolutas.
final class Css_Url_Rewriter {

	const URL_PATTERN    = '#url\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^)]*?))\s*\)#i';
	const IMPORT_PATTERN = '#@import\s+(["\'])([^"\']+)\1#i';

	public static function make_absolute( string $css, string $base_url ): string {
		$css = (string) preg_replace_callback(
			self::URL_PATTERN,
			static function ( $matches ) use ( $base_url ) {
				$value = '';

				foreach ( array( 1, 2, 3 ) as $group ) {
					if ( isset( $matches[ $group ] ) && '' !== $matches[ $group ] ) {
						$value = $matches[ $group ];
						break;
					}
				}

				$resolved = self::resolve( trim( $value ), $base_url );

				return $resolved === $value ? $matches[0] : 'url("' . str_replace( '"', '%22', $resolved ) . '")';
			},
			$css
		);

		return (string) preg_replace_callback(
			self::IMPORT_PATTERN,
			static function ( $matches ) use ( $base_url ) {
				return '@import "' . str_replace( '"', '%22', self::resolve( $matches[2], $base_url ) ) . '"';
			},
			$css
		);
	}

	// Caminho relativo ao arquivo de origem => caminho a partir da raiz (mesmo host) ou "//host/caminho".
	public static function resolve( string $reference, string $base_url ): string {
		if ( '' === $reference || 1 === preg_match( '#^(?:[a-z][a-z0-9+.\-]*:|//|/|\#)#i', $reference ) ) {
			return $reference;
		}

		$base = wp_parse_url( $base_url );

		if ( ! is_array( $base ) || empty( $base['host'] ) ) {
			return $reference;
		}

		$suffix   = '';
		$position = strcspn( $reference, '?#' );

		if ( $position < strlen( $reference ) ) {
			$suffix    = substr( $reference, $position );
			$reference = substr( $reference, 0, $position );
		}

		$directory = isset( $base['path'] ) ? (string) preg_replace( '#/[^/]*$#', '/', $base['path'] ) : '/';
		$segments  = array();

		foreach ( explode( '/', $directory . $reference ) as $segment ) {
			if ( '..' === $segment ) {
				array_pop( $segments );
			} elseif ( '.' !== $segment && '' !== $segment ) {
				$segments[] = $segment;
			}
		}

		$path      = '/' . implode( '/', $segments ) . ( '/' === substr( $reference, -1 ) ? '/' : '' );
		$base_host = strtolower( $base['host'] . ( isset( $base['port'] ) ? ':' . $base['port'] : '' ) );
		$home      = wp_parse_url( home_url() );
		$home_host = is_array( $home ) && ! empty( $home['host'] ) ? strtolower( $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) ) : '';

		return ( $base_host === $home_host ? '' : '//' . $base_host ) . $path . $suffix;
	}

	// @font-face sem font-display ganha "swap": o texto aparece com a fonte do sistema enquanto a fonte baixa.
	public static function add_font_display( string $css ): string {
		return (string) preg_replace_callback(
			'#@font-face\s*\{([^}]*)\}#i',
			static function ( $matches ) {
				if ( false !== stripos( $matches[1], 'font-display' ) ) {
					return $matches[0];
				}

				return '@font-face{font-display:swap;' . ltrim( $matches[1] ) . '}';
			},
			$css
		);
	}
}
