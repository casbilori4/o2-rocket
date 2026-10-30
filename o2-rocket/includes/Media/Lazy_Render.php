<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Media;

use O2Rocket\Optimization\Html_Pipeline;
use O2Rocket\Optimization\Html_Tag;

defined( 'ABSPATH' ) || exit;

// Renderização sob demanda: blocos bem abaixo da dobra ganham content-visibility:auto, e o navegador só monta o layout
// deles quando se aproximam da tela. Quem escolhe os blocos é o beacon (posição real em celular e computador), com a
// altura medida em contain-intrinsic-size para a barra de rolagem não pular.
final class Lazy_Render {

	const SETTING = 'media_lazy_render';

	const PIPELINE_PRIORITY = 95;

	// Contêineres candidatos, até 5 níveis abaixo do <body> (o conteúdo dos temas de blocos fica no 4º).
	const TAGS      = array( 'div', 'section', 'article', 'main', 'footer', 'header', 'aside' );
	const MAX_DEPTH = 4;
	const MAX_TAGS  = 200;
	const MAX_SAVED = 60;

	// Distância mínima (px) abaixo da primeira tela.
	const THRESHOLD = 1800;

	const MEASURE_ATTRIBUTE = 'data-o2r-lr';
	const APPLY_ATTRIBUTE   = 'data-o2r-lrc';

	private Lcp_Optimizer $lcp;

	public function __construct( Lcp_Optimizer $lcp ) {
		$this->lcp = $lcp;
	}

	public function register_hooks(): void {
		if ( is_admin() || ! self::is_enabled() ) {
			return;
		}

		add_filter( 'o2_rocket_beacon_config', array( $this, 'add_beacon_config' ) );
		add_filter( Html_Pipeline::FILTER, array( $this, 'process_html' ), self::PIPELINE_PRIORITY );
	}

	public static function is_enabled(): bool {
		$stored = get_option( O2_ROCKET_OPTION, array() );

		return is_array( $stored ) && ! empty( $stored[ self::SETTING ] );
	}

	public function add_beacon_config( $config ): array {
		$config       = is_array( $config ) ? $config : array();
		$config['lr'] = max( 600, (int) apply_filters( 'o2_rocket_lazy_render_threshold', self::THRESHOLD ) );

		return $config;
	}

	public function process_html( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}

		$heights = $this->get_measured_heights();

		// Sem medição completa: marca os candidatos para o beacon. Com medição: aplica só nos escolhidos.
		if ( null === $heights ) {
			return $this->mark( $html, array() );
		}

		if ( empty( $heights ) ) {
			return $html;
		}

		$html = $this->mark( $html, $heights );

		return false === strpos( $html, self::APPLY_ATTRIBUTE . '="' ) ? $html : $this->add_style( $html, $heights );
	}

	// [ hash => [ 'mobile' => altura, 'desktop' => altura ] ]; null = ainda falta medir algum dispositivo.
	public function get_measured_heights(): ?array {
		if ( ! $this->lcp->has_complete_measurements() ) {
			return null;
		}

		$measurements = $this->lcp->get_measurements();
		$common       = null;

		foreach ( $measurements as $measurement ) {
			// Medição de antes de a opção ser ligada: nada a aplicar até medir de novo.
			if ( ! isset( $measurement['extras']['lr'] ) || ! is_array( $measurement['extras']['lr'] ) ) {
				return array();
			}

			$hashes = array_keys( $measurement['extras']['lr'] );
			$common = null === $common ? $hashes : array_intersect( $common, $hashes );
		}

		$heights = array();

		// O bloco precisa estar longe da dobra em todos os dispositivos que recebem esta página.
		foreach ( (array) $common as $hash ) {
			foreach ( $measurements as $device => $measurement ) {
				$heights[ $hash ][ $device ] = (int) $measurement['extras']['lr'][ $hash ];
			}
		}

		return $heights;
	}

	public static function sanitize( $value ): array {
		$clean = array();

		foreach ( array_slice( is_array( $value ) ? $value : array(), 0, self::MAX_SAVED ) as $item ) {
			if ( is_array( $item ) && isset( $item[0], $item[1] ) && is_string( $item[0] ) && 1 === preg_match( '/^[a-f0-9]{12}$/', $item[0] ) ) {
				$clean[ $item[0] ] = max( 1, min( 50000, (int) $item[1] ) );
			}
		}

		return $clean;
	}

	// Percorre as tags do <body>; marca os contêineres candidatos (medição) ou só os escolhidos (aplicação).
	private function mark( string $html, array $heights ): string {
		$body = stripos( $html, '<body' );

		if ( false === $body ) {
			return $html;
		}

		$pattern = '#<!--.*?-->|<(script|style|template|textarea|noscript|svg|iframe)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>.*?</\1\s*>|<(/?)([a-zA-Z][a-zA-Z0-9-]*)\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>#is';

		if ( ! preg_match_all( $pattern, $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE, $body ) ) {
			return $html;
		}

		$stack   = array();
		$seen    = array();
		$changes = array();
		$count   = 0;
		$exclude = $this->get_exclusions();

		foreach ( $matches as $match ) {
			if ( ! isset( $match[3] ) || '' === $match[3][0] ) {
				continue;
			}

			$name = strtolower( $match[3][0] );

			if ( ! in_array( $name, self::TAGS, true ) ) {
				continue;
			}

			if ( '/' === $match[2][0] ) {
				$position = array_search( $name, array_reverse( $stack, true ), true );

				if ( false !== $position ) {
					$stack = array_slice( $stack, 0, (int) $position );
				}

				continue;
			}

			$depth   = count( $stack );
			$stack[] = $name;

			if ( $depth > self::MAX_DEPTH || $count >= self::MAX_TAGS ) {
				continue;
			}

			$tag = $match[0][0];

			if ( false !== stripos( $tag, self::MEASURE_ATTRIBUTE ) || $this->is_excluded( $tag, $exclude ) ) {
				continue;
			}

			// Só nome, id e classes: as etapas anteriores podem mexer em outros atributos entre uma geração e outra.
			$signature          = $name . '|' . Html_Tag::get_attribute( $tag, 'id' ) . '|' . Html_Tag::get_attribute( $tag, 'class' );
			$seen[ $signature ] = isset( $seen[ $signature ] ) ? $seen[ $signature ] + 1 : 0;
			$hash               = substr( md5( $signature . '|' . $seen[ $signature ] ), 0, 12 );
			++$count;

			if ( empty( $heights ) ) {
				$changes[ $match[0][1] ] = array( $tag, $this->add_attribute( $tag, $name, self::MEASURE_ATTRIBUTE, $hash ) );
			} elseif ( isset( $heights[ $hash ] ) ) {
				$changes[ $match[0][1] ] = array( $tag, $this->add_attribute( $tag, $name, self::APPLY_ATTRIBUTE, $hash ) );
			}
		}

		// De trás para frente: as posições anteriores continuam válidas.
		krsort( $changes );

		foreach ( $changes as $offset => $change ) {
			$html = substr_replace( $html, $change[1], (int) $offset, strlen( $change[0] ) );
		}

		return $html;
	}

	private function add_attribute( string $tag, string $name, string $attribute, string $hash ): string {
		return (string) preg_replace( '#^<' . preg_quote( $name, '#' ) . '\b#i', '$0 ' . $attribute . '="' . $hash . '"', $tag, 1 );
	}

	private function add_style( string $html, array $heights ): string {
		$rules  = '[' . self::APPLY_ATTRIBUTE . ']{content-visibility:auto;contain-intrinsic-size:auto 500px}';
		$single = array();
		$media  = array(
			'mobile'  => array(),
			'desktop' => array(),
		);

		foreach ( $heights as $hash => $devices ) {
			$selector = '[' . self::APPLY_ATTRIBUTE . '="' . $hash . '"]';

			// Um dispositivo só (cache separado para celular): sem media query.
			if ( 1 === count( $devices ) ) {
				$single[] = $selector . '{contain-intrinsic-size:auto ' . (int) reset( $devices ) . 'px}';
				continue;
			}

			foreach ( $devices as $device => $height ) {
				if ( isset( $media[ $device ] ) ) {
					$media[ $device ][] = $selector . '{contain-intrinsic-size:auto ' . (int) $height . 'px}';
				}
			}
		}

		$rules .= implode( '', $single );

		// Mesmo limite do beacon: menos de 768 px de largura conta como celular.
		if ( ! empty( $media['mobile'] ) ) {
			$rules .= '@media (max-width:767px){' . implode( '', $media['mobile'] ) . '}';
		}

		if ( ! empty( $media['desktop'] ) ) {
			$rules .= '@media (min-width:768px){' . implode( '', $media['desktop'] ) . '}';
		}

		$style = '<style id="o2r-lazy-render">' . $rules . '</style>';

		if ( false !== strpos( $html, 'id="o2r-lazy-render"' ) ) {
			return $html;
		}

		$position = stripos( $html, '</head>' );

		return false === $position ? $html : substr_replace( $html, $style, $position, 0 );
	}

	private function get_exclusions(): array {
		return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'o2_rocket_lazy_render_exclusions', array( 'data-o2r-no-lazy-render', 'elementor-location-popup', 'elementor-popup', 'modal', 'popup', 'cookie', 'sticky' ) ) ), 'strlen' ) );
	}

	private function is_excluded( string $tag, array $exclusions ): bool {
		foreach ( $exclusions as $exclusion ) {
			if ( false !== stripos( $tag, $exclusion ) ) {
				return true;
			}
		}

		return false;
	}
}
