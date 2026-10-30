<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Media;

use O2Rocket\Core\Url_Resolver;
use O2Rocket\Optimization\Html_Pipeline;
use O2Rocket\Optimization\Html_Tag;

defined( 'ABSPATH' ) || exit;

// Acrescenta width/height às imagens locais que não têm: o navegador reserva o espaço e a página não "pula" (CLS).
final class Image_Dimensions {

	const SETTING_ENABLED = 'media_image_dimensions';

	const PIPELINE_PRIORITY = 35;
	const MAX_SVG_BYTES     = 524288;

	private array $sizes = array();

	public function register_hooks(): void {
		if ( is_admin() || ! $this->is_enabled() ) {
			return;
		}

		add_filter( Html_Pipeline::FILTER, array( $this, 'process_html' ), self::PIPELINE_PRIORITY );
	}

	public function is_enabled(): bool {
		$stored = get_option( O2_ROCKET_OPTION, array() );

		return is_array( $stored ) && ! empty( $stored[ self::SETTING_ENABLED ] );
	}

	public function process_html( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}

		$result = preg_replace_callback(
			'#<(noscript|script|style|textarea|template|svg)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>.*?</\1\s*>|<img\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>#is',
			function ( $matches ) {
				return isset( $matches[1] ) && '' !== $matches[1] ? $matches[0] : $this->add_dimensions( $matches[0] );
			},
			$html
		);

		return is_string( $result ) ? $result : $html;
	}

	public function add_dimensions( string $tag ): string {
		$width  = trim( (string) Html_Tag::get_attribute( $tag, 'width' ) );
		$height = trim( (string) Html_Tag::get_attribute( $tag, 'height' ) );

		if ( ctype_digit( $width ) && ctype_digit( $height ) && (int) $width > 0 && (int) $height > 0 ) {
			return $tag;
		}

		$src = Html_Tag::get_attribute( $tag, 'src' );

		if ( null === $src || '' === trim( $src ) || 0 === stripos( ltrim( $src ), 'data:' ) || Html_Tag::has_attribute( $tag, 'data-no-dimensions' ) ) {
			return $tag;
		}

		$size = $this->get_size( html_entity_decode( trim( $src ), ENT_QUOTES ) );

		if ( null === $size ) {
			return $tag;
		}

		list( $real_width, $real_height ) = $size;

		// Só uma das medidas no HTML: a outra sai da proporção real.
		if ( ctype_digit( $width ) && (int) $width > 0 ) {
			$real_height = (int) round( (int) $width * $real_height / $real_width );
			$real_width  = (int) $width;
		} elseif ( ctype_digit( $height ) && (int) $height > 0 ) {
			$real_width  = (int) round( (int) $height * $real_width / $real_height );
			$real_height = (int) $height;
		}

		if ( $real_width < 1 || $real_height < 1 ) {
			return $tag;
		}

		$tag = Html_Tag::set_attribute( $tag, 'width', (string) $real_width );

		return Html_Tag::set_attribute( $tag, 'height', (string) $real_height );
	}

	// [ largura, altura ] de uma imagem do próprio site; null se externa, inexistente ou sem medidas.
	public function get_size( string $url ): ?array {
		if ( array_key_exists( $url, $this->sizes ) ) {
			return $this->sizes[ $url ];
		}

		$this->sizes[ $url ] = null;

		$file = Url_Resolver::to_path( $url );

		if ( '' === $file ) {
			return null;
		}

		if ( '.svg' === strtolower( substr( $file, -4 ) ) ) {
			$this->sizes[ $url ] = $this->get_svg_size( $file );

			return $this->sizes[ $url ];
		}

		$info = function_exists( 'wp_getimagesize' ) ? wp_getimagesize( $file ) : @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( is_array( $info ) && ! empty( $info[0] ) && ! empty( $info[1] ) ) {
			$this->sizes[ $url ] = array( (int) $info[0], (int) $info[1] );
		}

		return $this->sizes[ $url ];
	}

	// width/height em px (ou sem unidade) no <svg>; senão, o viewBox.
	private function get_svg_size( string $file ): ?array {
		if ( (int) filesize( $file ) > self::MAX_SVG_BYTES ) {
			return null;
		}

		$svg = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( ! preg_match( '#<svg\b[^>]*>#is', $svg, $match ) ) {
			return null;
		}

		$width  = Html_Tag::get_attribute( $match[0], 'width' );
		$height = Html_Tag::get_attribute( $match[0], 'height' );

		if ( null !== $width && null !== $height && preg_match( '#^\s*([\d.]+)\s*(?:px)?\s*$#', $width, $w ) && preg_match( '#^\s*([\d.]+)\s*(?:px)?\s*$#', $height, $h ) && (float) $w[1] > 0 && (float) $h[1] > 0 ) {
			return array( (int) round( (float) $w[1] ), (int) round( (float) $h[1] ) );
		}

		$viewbox = (string) Html_Tag::get_attribute( $match[0], 'viewBox' );
		$parts   = preg_split( '#[\s,]+#', trim( $viewbox ) );

		if ( is_array( $parts ) && 4 === count( $parts ) && (float) $parts[2] > 0 && (float) $parts[3] > 0 ) {
			return array( (int) round( (float) $parts[2] ), (int) round( (float) $parts[3] ) );
		}

		return null;
	}
}
