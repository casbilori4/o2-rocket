<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Optimization;

use O2Rocket\Core\Request;

defined( 'ABSPATH' ) || exit;

// Um único buffer de saída para todas as otimizações do HTML (filtro o2_rocket_optimize_html).
// Começa depois do buffer do Page_Cache (9999): fecha antes dele, e o cache já grava o HTML otimizado.
// Prioridades das etapas: 10 minificação, 20 CSS, 30 fontes, 35 dimensões, 40 lazy load, 60 WebP, 70 atraso de JS, 80 dicas de recurso, 90 CDN.
final class Html_Pipeline {

	const FILTER   = 'o2_rocket_optimize_html';
	const PRIORITY = 10000;

	// ?o2r-nooptimize mostra a página sem nenhuma otimização (diagnóstico); também pula o cache.
	const BYPASS_PARAM = 'o2r-nooptimize';

	private string $buffer = '';

	public function register_hooks(): void {
		if ( is_admin() ) {
			return;
		}

		add_action( 'template_redirect', array( $this, 'start_buffer' ), self::PRIORITY );
	}

	public static function can_optimize(): bool {
		if ( ! Request::is_public_frontend() || is_feed() || is_embed() || is_robots() || is_trackback() || is_favicon() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- só desliga as otimizações nesta resposta.
		if ( isset( $_GET[ self::BYPASS_PARAM ] ) ) {
			return false;
		}

		if ( ( function_exists( 'amp_is_request' ) && amp_is_request() ) || ( function_exists( 'is_amp_endpoint' ) && is_amp_endpoint() ) ) {
			return false;
		}

		return (bool) apply_filters( 'o2_rocket_can_optimize_html', true );
	}

	// Visitante não logado. Etapas que mexem na execução (atraso de JS) só valem para visitantes:
	// quem está logado navega com a barra do admin e os editores visuais intactos.
	public static function is_visitor(): bool {
		return ! is_user_logged_in() || (bool) apply_filters( 'o2_rocket_optimize_for_logged_in', false );
	}

	public function start_buffer(): void {
		if ( ! has_filter( self::FILTER ) || ! self::can_optimize() ) {
			return;
		}

		$this->buffer = '';

		ob_start( array( $this, 'process_buffer' ) );
	}

	// Acumula os blocos (caso algo chame ob_flush) e otimiza o HTML inteiro no fechamento.
	public function process_buffer( $chunk, $phase = PHP_OUTPUT_HANDLER_FINAL ) {
		if ( $phase & PHP_OUTPUT_HANDLER_CLEAN ) {
			$this->buffer = '';

			return '';
		}

		$this->buffer .= (string) $chunk;

		if ( ! ( $phase & PHP_OUTPUT_HANDLER_FINAL ) ) {
			return '';
		}

		$html         = $this->buffer;
		$this->buffer = '';

		if ( ! $this->is_optimizable( $html ) ) {
			return $html;
		}

		$optimized = apply_filters( self::FILTER, $html );

		// Uma etapa com erro de regex devolve vazio/nulo: entrega o original em vez de uma página em branco.
		return is_string( $optimized ) && '' !== trim( $optimized ) ? $optimized : $html;
	}

	private function is_optimizable( string $html ): bool {
		foreach ( array( 'DONOTMINIFY', 'DONOTOPTIMIZE' ) as $constant ) {
			if ( defined( $constant ) && constant( $constant ) ) {
				return false;
			}
		}

		if ( false === stripos( $html, '<html' ) || false === stripos( $html, '</html>' ) ) {
			return false;
		}

		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'content-type:' ) && false === stripos( $header, 'text/html' ) ) {
				return false;
			}
		}

		return true;
	}
}
