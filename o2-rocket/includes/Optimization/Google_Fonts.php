<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Optimization;

use function O2Rocket\atomic_write;
use function O2Rocket\direct_filesystem;

defined( 'ABSPATH' ) || exit;

// Google Fonts no próprio servidor: o CSS e os .woff2 vão para wp-content/cache/o2-rocket/fonts/{site}/ e o <link>
// passa a apontar para lá (sem conexão com fonts.googleapis.com/gstatic.com: mais rápido e sem dados para o Google).
// Sem hospedar, ao menos acrescenta display=swap às URLs do Google.
final class Google_Fonts {

	const SETTING_LOCAL        = 'fonts_google_local';
	const SETTING_DISPLAY_SWAP = 'fonts_display_swap';

	const PIPELINE_PRIORITY = 30;
	const FAIL_TRANSIENT    = 'o2_rocket_gf_fail_';
	const TIMEOUT           = 10;
	const MAX_FONTS         = 60;

	// Navegador atual: o Google só entrega woff2 (com unicode-range) para quem o suporta.
	const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

	const URL_PATTERN = '#(?:https?:)?//fonts\.googleapis\.com/css2?\?[^"\'\s<>)]+#i';

	private ?array $settings = null;

	private bool $localized = false;

	public function register_hooks(): void {
		add_action( 'o2_rocket_manual_purge_all', array( $this, 'clear' ) );
		add_filter( 'o2_rocket_font_display_swap', array( $this, 'filter_display_swap' ) );

		if ( is_admin() || ( ! $this->is_enabled( self::SETTING_LOCAL ) && ! $this->is_enabled( self::SETTING_DISPLAY_SWAP ) ) ) {
			return;
		}

		add_filter( Html_Pipeline::FILTER, array( $this, 'process_html' ), self::PIPELINE_PRIORITY );
	}

	public function get_settings(): array {
		if ( null === $this->settings ) {
			$stored         = get_option( O2_ROCKET_OPTION, array() );
			$this->settings = wp_parse_args(
				is_array( $stored ) ? $stored : array(),
				array(
					self::SETTING_LOCAL        => false,
					self::SETTING_DISPLAY_SWAP => true,
				)
			);
		}

		return $this->settings;
	}

	public function is_enabled( string $setting ): bool {
		$settings = $this->get_settings();

		return isset( $settings[ $setting ] ) && rest_sanitize_boolean( $settings[ $setting ] );
	}

	// A mesma opção decide o font-display: swap das fontes do CSS minificado.
	public function filter_display_swap( $swap ): bool {
		return (bool) $swap && $this->is_enabled( self::SETTING_DISPLAY_SWAP );
	}

	public static function get_cache_dir(): string {
		return O2_ROCKET_CACHE_PATH . 'fonts/' . get_current_blog_id() . '/google/';
	}

	public static function get_cache_url(): string {
		return set_url_scheme( O2_ROCKET_CACHE_URL . 'fonts/' . get_current_blog_id() . '/google/' );
	}

	// Só na limpeza manual: as fontes não mudam e baixá-las de novo custa tempo na próxima geração.
	public function clear(): void {
		$directory = self::get_cache_dir();

		if ( is_dir( $directory ) ) {
			direct_filesystem()->delete( $directory, true );
		}
	}

	public function process_html( $html ) {
		if ( ! is_string( $html ) || false === stripos( $html, 'fonts.googleapis.com' ) ) {
			return $html;
		}

		$this->localized = false;

		$html = (string) preg_replace_callback( Html_Tag::opening_pattern( 'link' ), array( $this, 'replace_link' ), $html );

		// @import do Google dentro de <style> (temas e construtores que não usam <link>).
		$html = (string) preg_replace_callback(
			'#(<style\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>)(.*?)(</style\s*>)#is',
			function ( $matches ) {
				if ( false === stripos( $matches[2], 'fonts.googleapis.com' ) ) {
					return $matches[0];
				}

				return $matches[1] . $this->replace_imports( $matches[2] ) . $matches[3];
			},
			$html
		);

		return $this->localized ? $this->remove_google_hints( $html ) : $html;
	}

	public function replace_link( array $matches ): string {
		$tag  = $matches[0];
		$href = Html_Tag::get_attribute( $tag, 'href' );

		if ( null === $href || ! preg_match( self::URL_PATTERN, html_entity_decode( $href, ENT_QUOTES ) ) ) {
			return $tag;
		}

		$rel = strtolower( (string) Html_Tag::get_attribute( $tag, 'rel' ) );

		if ( false !== strpos( $rel, 'preconnect' ) || false !== strpos( $rel, 'dns-prefetch' ) ) {
			return $tag;
		}

		$url   = $this->add_display_swap( html_entity_decode( trim( $href ), ENT_QUOTES ) );
		$local = $this->is_enabled( self::SETTING_LOCAL ) ? $this->get_local_url( $url ) : '';

		if ( '' !== $local ) {
			$this->localized = true;

			// preload as=style de uma fonte do Google vira preload do CSS local (mesmo efeito).
			return Html_Tag::set_attribute( $tag, 'href', $local );
		}

		return Html_Tag::set_attribute( $tag, 'href', $url );
	}

	// URL do CSS local (baixando CSS e fontes na primeira vez); vazio se o download falhar.
	public function get_local_url( string $url ): string {
		$url  = $this->normalize_url( $url );
		$hash = substr( md5( $url ), 0, 16 );
		$name = 'css-' . $hash . '.css';
		$file = self::get_cache_dir() . $name;

		if ( is_file( $file ) ) {
			return self::get_cache_url() . $name;
		}

		// Falha recente (sem internet, bloqueio de saída): não tenta a cada página por uma hora.
		if ( get_transient( self::FAIL_TRANSIENT . $hash ) ) {
			return '';
		}

		$css = $this->download( $url );

		if ( '' === $css || false === stripos( $css, '@font-face' ) || ! wp_mkdir_p( self::get_cache_dir() . 'files/' ) ) {
			set_transient( self::FAIL_TRANSIENT . $hash, 1, HOUR_IN_SECONDS );

			return '';
		}

		$local_css = $this->localize_fonts( $css );

		if ( '' === $local_css || ! atomic_write( $file, $local_css ) ) {
			set_transient( self::FAIL_TRANSIENT . $hash, 1, HOUR_IN_SECONDS );

			return '';
		}

		return self::get_cache_url() . $name;
	}

	private function replace_imports( string $css ): string {
		return (string) preg_replace_callback(
			'#@import\s+(?:url\(\s*)?(["\']?)((?:https?:)?//fonts\.googleapis\.com/css2?\?[^"\')\s]+)\1\s*\)?\s*([^;]*);#i',
			function ( $matches ) {
				$url   = $this->add_display_swap( html_entity_decode( $matches[2], ENT_QUOTES ) );
				$local = $this->is_enabled( self::SETTING_LOCAL ) ? $this->get_local_url( $url ) : '';

				if ( '' !== $local ) {
					$this->localized = true;
					$url             = $local;
				}

				return '@import url("' . $url . '")' . ( '' !== trim( $matches[3] ) ? ' ' . trim( $matches[3] ) : '' ) . ';';
			},
			$css
		);
	}

	// Baixa cada fonte do CSS e troca a URL pela local (mesmo nome do arquivo no gstatic, nada é recomprimido).
	private function localize_fonts( string $css ): string {
		if ( ! preg_match_all( '#url\(\s*(["\']?)(https://fonts\.gstatic\.com/[^"\')\s]+)\1\s*\)#i', $css, $matches ) ) {
			return '';
		}

		$replacements = array();

		foreach ( array_slice( array_unique( $matches[2] ), 0, self::MAX_FONTS ) as $font_url ) {
			$path = (string) wp_parse_url( $font_url, PHP_URL_PATH );
			$ext  = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

			if ( ! in_array( $ext, array( 'woff2', 'woff', 'ttf', 'otf', 'eot', 'svg' ), true ) ) {
				continue;
			}

			$name = substr( md5( $font_url ), 0, 16 ) . '.' . $ext;
			$file = self::get_cache_dir() . 'files/' . $name;

			if ( ! is_file( $file ) ) {
				$body = $this->download( $font_url );

				if ( '' === $body || ! atomic_write( $file, $body ) ) {
					return '';
				}
			}

			$replacements[ $font_url ] = self::get_cache_url() . 'files/' . $name;
		}

		if ( empty( $replacements ) ) {
			return '';
		}

		return strtr( $css, $replacements );
	}

	private function download( string $url ): string {
		$response = wp_safe_remote_get(
			set_url_scheme( $url, 'https' ),
			array(
				'timeout'    => self::TIMEOUT,
				'user-agent' => self::USER_AGENT,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	private function add_display_swap( string $url ): string {
		if ( ! $this->is_enabled( self::SETTING_DISPLAY_SWAP ) || preg_match( '#[?&]display=#i', $url ) ) {
			return $url;
		}

		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . 'display=swap';
	}

	// Esquema fixo e entidades resolvidas: a mesma fonte em páginas diferentes vira um arquivo só.
	private function normalize_url( string $url ): string {
		$url = html_entity_decode( trim( $url ), ENT_QUOTES );

		return 0 === strpos( $url, '//' ) ? 'https:' . $url : (string) preg_replace( '#^http:#i', 'https:', $url );
	}

	// Com tudo local, preconnect/dns-prefetch para os domínios do Google só gastariam conexões.
	private function remove_google_hints( string $html ): string {
		return (string) preg_replace_callback(
			Html_Tag::opening_pattern( 'link' ),
			static function ( $matches ) {
				$rel  = strtolower( (string) Html_Tag::get_attribute( $matches[0], 'rel' ) );
				$href = strtolower( (string) Html_Tag::get_attribute( $matches[0], 'href' ) );

				if ( ( false !== strpos( $rel, 'preconnect' ) || false !== strpos( $rel, 'dns-prefetch' ) ) && preg_match( '#fonts\.(?:googleapis|gstatic)\.com#', $href ) ) {
					return '';
				}

				return $matches[0];
			},
			$html
		);
	}
}
