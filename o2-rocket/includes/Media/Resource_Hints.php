<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Media;

use O2Rocket\Core\Url_Resolver;
use O2Rocket\Optimization\Html_Pipeline;
use O2Rocket\Optimization\Html_Tag;

defined( 'ABSPATH' ) || exit;

// Dicas de recurso no <head>: preconnect para domínios externos e preload das fontes usadas no topo da página.
// Listas manuais e/ou automáticas (o beacon local informa as fontes e domínios pedidos antes da LCP).
final class Resource_Hints {

	const SETTING_FONTS_PRELOAD   = 'fonts_preload';
	const SETTING_FONTS_AUTO      = 'fonts_preload_auto';
	const SETTING_PRECONNECT      = 'preconnect_domains';
	const SETTING_PRECONNECT_AUTO = 'preconnect_auto';

	const PIPELINE_PRIORITY = 85;
	const MAX_FONTS         = 4;
	const MAX_ORIGINS       = 5;

	const FONT_TYPES = array(
		'woff2' => 'font/woff2',
		'woff'  => 'font/woff',
		'ttf'   => 'font/ttf',
		'otf'   => 'font/otf',
	);

	private Lcp_Optimizer $lcp;

	private ?array $settings = null;

	public function __construct( Lcp_Optimizer $lcp ) {
		$this->lcp = $lcp;
	}

	public function register_hooks(): void {
		if ( is_admin() ) {
			return;
		}

		$settings = $this->get_settings();

		if ( empty( $settings[ self::SETTING_FONTS_PRELOAD ] ) && empty( $settings[ self::SETTING_PRECONNECT ] ) && ! $settings[ self::SETTING_FONTS_AUTO ] && ! $settings[ self::SETTING_PRECONNECT_AUTO ] ) {
			return;
		}

		add_filter( Html_Pipeline::FILTER, array( $this, 'process_html' ), self::PIPELINE_PRIORITY );
	}

	public function get_settings(): array {
		if ( null === $this->settings ) {
			$stored = get_option( O2_ROCKET_OPTION, array() );
			$stored = wp_parse_args(
				is_array( $stored ) ? $stored : array(),
				array(
					self::SETTING_FONTS_PRELOAD   => array(),
					self::SETTING_FONTS_AUTO      => false,
					self::SETTING_PRECONNECT      => array(),
					self::SETTING_PRECONNECT_AUTO => false,
				)
			);

			$stored[ self::SETTING_FONTS_AUTO ]      = rest_sanitize_boolean( $stored[ self::SETTING_FONTS_AUTO ] );
			$stored[ self::SETTING_PRECONNECT_AUTO ] = rest_sanitize_boolean( $stored[ self::SETTING_PRECONNECT_AUTO ] );

			$this->settings = $stored;
		}

		return $this->settings;
	}

	public function process_html( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}

		$settings = $this->get_settings();
		$fonts    = self::sanitize_font_urls( $settings[ self::SETTING_FONTS_PRELOAD ] );
		$origins  = array();
		$cors     = array();

		foreach ( self::sanitize_domains( $settings[ self::SETTING_PRECONNECT ] ) as $origin ) {
			$origins[]       = $origin;
			$cors[ $origin ] = false !== strpos( $origin, 'font' );
		}

		if ( $settings[ self::SETTING_FONTS_AUTO ] || $settings[ self::SETTING_PRECONNECT_AUTO ] ) {
			foreach ( $this->lcp->get_measurements() as $measurement ) {
				$extras = isset( $measurement['extras'] ) && is_array( $measurement['extras'] ) ? $measurement['extras'] : array();

				if ( $settings[ self::SETTING_FONTS_AUTO ] && ! empty( $extras['fonts'] ) ) {
					$fonts = array_merge( $fonts, array_filter( (array) $extras['fonts'], 'is_string' ) );
				}

				if ( $settings[ self::SETTING_PRECONNECT_AUTO ] && ! empty( $extras['origins'] ) ) {
					foreach ( (array) $extras['origins'] as $origin ) {
						if ( is_string( $origin ) ) {
							$origins[]       = $origin;
							$cors[ $origin ] = ! empty( $cors[ $origin ] ) || in_array( $origin, (array) ( isset( $extras['cors'] ) ? $extras['cors'] : array() ), true );
						}
					}
				}
			}
		}

		$tags = $this->build_preconnects( $html, array_values( array_unique( $origins ) ), $cors ) . $this->build_font_preloads( $html, array_values( array_unique( $fonts ) ) );

		if ( '' === $tags ) {
			return $html;
		}

		// O mais cedo possível: depois do <meta charset> (que precisa vir primeiro) ou da abertura do <head>.
		if ( preg_match( '#<meta\s[^>]*charset\s*=[^>]*>#i', $html, $match, PREG_OFFSET_CAPTURE ) || preg_match( '#<head\b[^>]*>#i', $html, $match, PREG_OFFSET_CAPTURE ) ) {
			return substr_replace( $html, $tags, $match[0][1] + strlen( $match[0][0] ), 0 );
		}

		return $html;
	}

	// Linha = domínio ou URL; vira a origem "https://host". O próprio site fica de fora.
	public static function sanitize_domains( $value ): array {
		$lines   = is_array( $value ) ? $value : preg_split( '/\r\n|\r|\n/', is_scalar( $value ) ? (string) $value : '' );
		$origins = array();

		foreach ( (array) $lines as $line ) {
			$line = is_scalar( $line ) ? trim( wp_strip_all_tags( (string) $line ) ) : '';

			if ( '' === $line ) {
				continue;
			}

			if ( 0 === strpos( $line, '//' ) ) {
				$line = 'https:' . $line;
			} elseif ( ! preg_match( '#^https?://#i', $line ) ) {
				$line = 'https://' . $line;
			}

			$parts = wp_parse_url( $line );

			if ( is_array( $parts ) && ! empty( $parts['host'] ) && preg_match( '#^[a-z0-9.\-]+$#i', $parts['host'] ) ) {
				$origins[] = strtolower( $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) );
			}
		}

		return array_slice( array_values( array_unique( $origins ) ), 0, 20 );
	}

	public static function sanitize_font_urls( $value ): array {
		$lines = is_array( $value ) ? $value : preg_split( '/\r\n|\r|\n/', is_scalar( $value ) ? (string) $value : '' );
		$urls  = array();

		foreach ( (array) $lines as $line ) {
			$line = is_scalar( $line ) ? trim( wp_strip_all_tags( (string) $line ) ) : '';

			if ( '' === $line ) {
				continue;
			}

			// Caminho relativo à raiz do site também vale.
			$url = '/' === $line[0] && '/' !== substr( $line, 1, 1 ) ? home_url( $line ) : $line;
			$url = esc_url_raw( $url, array( 'http', 'https' ) );

			if ( '' !== $url && preg_match( '#\.(?:woff2?|ttf|otf)(?:[?\#]|$)#i', $url ) ) {
				$urls[] = $url;
			}
		}

		return array_slice( array_values( array_unique( $urls ) ), 0, 20 );
	}

	private function build_preconnects( string $html, array $origins, array $cors ): string {
		$own  = strtolower( (string) preg_replace( '#^(https?://[^/]+).*$#i', '$1', home_url() ) );
		$tags = '';
		$used = 0;

		foreach ( $origins as $origin ) {
			$origin = strtolower( $origin );

			if ( $used >= self::MAX_ORIGINS || $origin === $own || Url_Resolver::is_local( $origin . '/' ) || $this->has_hint( $html, 'preconnect', $origin ) ) {
				continue;
			}

			// Domínios do Google Fonts hospedados localmente não são mais usados.
			if ( preg_match( '#//fonts\.(?:googleapis|gstatic)\.com$#', $origin ) && false === stripos( $html, preg_replace( '#^https?:#', '', $origin ) ) ) {
				continue;
			}

			$tags .= '<link rel="preconnect" href="' . esc_url( $origin ) . '"' . ( ! empty( $cors[ $origin ] ) ? ' crossorigin' : '' ) . '>';
			$tags .= '<link rel="dns-prefetch" href="' . esc_url( $origin ) . '">';
			++$used;
		}

		return $tags;
	}

	private function build_font_preloads( string $html, array $fonts ): string {
		$tags = '';
		$used = 0;

		foreach ( $fonts as $font ) {
			$path = (string) wp_parse_url( $font, PHP_URL_PATH );
			$ext  = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

			if ( $used >= self::MAX_FONTS || ! isset( self::FONT_TYPES[ $ext ] ) || $this->has_hint( $html, 'preload', $font ) ) {
				continue;
			}

			$tags .= '<link rel="preload" as="font" type="' . esc_attr( self::FONT_TYPES[ $ext ] ) . '" href="' . esc_url( $font ) . '" crossorigin>';
			++$used;
		}

		return $tags;
	}

	// Já existe um <link rel="..."> com esse destino (do tema ou de outro plugin)?
	private function has_hint( string $html, string $rel, string $href ): bool {
		if ( false === stripos( $html, preg_replace( '#^https?:#i', '', $href ) ) ) {
			return false;
		}

		if ( ! preg_match_all( Html_Tag::opening_pattern( 'link' ), $html, $matches ) ) {
			return false;
		}

		foreach ( $matches[0] as $tag ) {
			$tag_rel  = strtolower( (string) Html_Tag::get_attribute( $tag, 'rel' ) );
			$tag_href = html_entity_decode( (string) Html_Tag::get_attribute( $tag, 'href' ), ENT_QUOTES );

			if ( false !== strpos( $tag_rel, $rel ) && rtrim( preg_replace( '#^https?:#i', '', $tag_href ), '/' ) === rtrim( preg_replace( '#^https?:#i', '', $href ), '/' ) ) {
				return true;
			}
		}

		return false;
	}
}
