<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Admin;

defined( 'ABSPATH' ) || exit;

// Marca em SVG (assets/images): lida uma vez por requisição e devolvida em linha com fill="currentColor",
// para herdar a cor do lugar onde aparece.
final class Brand {

	const PATH = 'assets/images/';

	private static array $files = array();

	public static function logo( array $attributes = array() ): string {
		return self::inline( 'logo', $attributes );
	}

	public static function icon( array $attributes = array() ): string {
		return self::inline( 'icon', $attributes );
	}

	public static function oxigenweb( array $attributes = array() ): string {
		return self::inline( 'oxigenweb', $attributes );
	}

	// Ícone do menu lateral: o WordPress desenha 20 px de largura e o svg-painter troca o fill da raiz pela cor do
	// esquema de cores, então o desenho vai centralizado num quadrado e com fill="black".
	public static function menu_icon(): string {
		$svg = self::read( 'icon' );

		if ( '' === $svg ) {
			return 'dashicons-performance';
		}

		$svg = self::set_root_attributes( self::square_viewbox( $svg ), array( 'fill' => 'black' ) );

		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	private static function inline( string $name, array $attributes ): string {
		$svg = self::read( $name );

		if ( '' === $svg ) {
			return '';
		}

		return self::set_root_attributes(
			$svg,
			array_merge(
				array(
					'fill'        => 'currentColor',
					'aria-hidden' => 'true',
					'focusable'   => 'false',
				),
				$attributes
			)
		);
	}

	private static function read( string $name ): string {
		if ( ! array_key_exists( $name, self::$files ) ) {
			$file = O2_ROCKET_PATH . self::PATH . $name . '.svg';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- arquivo do próprio plugin.
			$svg = is_readable( $file ) ? (string) file_get_contents( $file ) : '';

			// Só o elemento <svg>: sem declaração XML, comentários nem espaços nas pontas.
			$svg                  = (string) preg_replace( array( '#<\?xml.*?\?>#s', '#<!--.*?-->#s' ), '', $svg );
			self::$files[ $name ] = 0 === strpos( trim( $svg ), '<svg' ) ? trim( $svg ) : '';
		}

		return self::$files[ $name ];
	}

	private static function set_root_attributes( string $svg, array $attributes ): string {
		$end  = (int) strpos( $svg, '>' );
		$root = substr( $svg, 0, $end );

		foreach ( $attributes as $name => $value ) {
			$pair = $name . '="' . esc_attr( (string) $value ) . '"';
			$root = preg_match( '#\s' . preg_quote( $name, '#' ) . '="[^"]*"#', $root )
				? (string) preg_replace( '#\s' . preg_quote( $name, '#' ) . '="[^"]*"#', ' ' . $pair, $root, 1 )
				: $root . ' ' . $pair;
		}

		return $root . substr( $svg, $end );
	}

	private static function square_viewbox( string $svg ): string {
		if ( ! preg_match( '#viewBox="\s*([-\d.]+)[\s,]+([-\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)\s*"#', $svg, $match ) ) {
			return $svg;
		}

		list( , $x, $y, $width, $height ) = array_map( 'floatval', $match );

		$side = max( $width, $height );
		$box  = sprintf( '%s %s %s %s', self::number( $x - ( $side - $width ) / 2 ), self::number( $y - ( $side - $height ) / 2 ), self::number( $side ), self::number( $side ) );

		return str_replace( $match[0], 'viewBox="' . $box . '"', $svg );
	}

	private static function number( float $value ): string {
		return rtrim( rtrim( sprintf( '%.2F', $value ), '0' ), '.' );
	}
}
