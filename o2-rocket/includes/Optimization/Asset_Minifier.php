<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Optimization;

use O2Rocket\Core\Url_Resolver;
use O2Rocket\Vendor\Minify\CSS as Css_Minifier;
use O2Rocket\Vendor\Minify\JS as Js_Minifier;

use function O2Rocket\atomic_write;
use function O2Rocket\direct_filesystem;

defined( 'ABSPATH' ) || exit;

// Minifica os CSS e JS locais da página e troca as URLs pelas cópias em wp-content/cache/o2-rocket/min/{site}/.
// O nome da cópia leva um hash do arquivo original: editar o tema ou atualizar um plugin gera uma URL nova.
final class Asset_Minifier {

	const SETTING_CSS          = 'file_minify_css';
	const SETTING_CSS_EXCLUDED = 'file_minify_css_excluded';
	const SETTING_JS           = 'file_minify_js';
	const SETTING_JS_EXCLUDED  = 'file_minify_js_excluded';

	const PIPELINE_PRIORITY = 10;
	const MAX_BYTES         = 2097152;

	// Tags que nunca são tocadas: comentários (inclusive condicionais do IE).
	const COMMENT_PATTERN = '#<!--.*?-->#s';

	private ?array $settings = null;

	private array $resolved = array();

	public function register_hooks(): void {
		add_action( 'o2_rocket_after_purge_all', array( $this, 'clear' ) );
		add_action( 'o2_rocket_manual_purge_all', array( $this, 'clear' ) );

		if ( is_admin() || ( ! $this->is_enabled( self::SETTING_CSS ) && ! $this->is_enabled( self::SETTING_JS ) ) ) {
			return;
		}

		add_filter( Html_Pipeline::FILTER, array( $this, 'optimize' ), self::PIPELINE_PRIORITY );
	}

	public function get_settings(): array {
		if ( null === $this->settings ) {
			$stored         = get_option( O2_ROCKET_OPTION, array() );
			$this->settings = wp_parse_args(
				is_array( $stored ) ? $stored : array(),
				array(
					self::SETTING_CSS          => false,
					self::SETTING_CSS_EXCLUDED => array(),
					self::SETTING_JS           => false,
					self::SETTING_JS_EXCLUDED  => array(),
				)
			);
		}

		return $this->settings;
	}

	public function is_enabled( string $setting ): bool {
		$settings = $this->get_settings();

		return isset( $settings[ $setting ] ) && rest_sanitize_boolean( $settings[ $setting ] );
	}

	public static function get_cache_dir(): string {
		return O2_ROCKET_CACHE_PATH . 'min/' . get_current_blog_id() . '/';
	}

	public static function get_cache_url(): string {
		return set_url_scheme( O2_ROCKET_CACHE_URL . 'min/' . get_current_blog_id() . '/' );
	}

	// Todas as páginas foram limpas junto: nenhuma página em cache aponta mais para as cópias antigas.
	public function clear(): void {
		$directory = self::get_cache_dir();

		if ( is_dir( $directory ) ) {
			direct_filesystem()->delete( $directory, true );
		}
	}

	public function optimize( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}

		$comments = array();
		$html     = $this->hide_comments( $html, $comments );

		if ( $this->is_enabled( self::SETTING_CSS ) ) {
			$html = (string) preg_replace_callback( Html_Tag::opening_pattern( 'link' ), array( $this, 'replace_link' ), $html );
		}

		if ( $this->is_enabled( self::SETTING_JS ) ) {
			$html = (string) preg_replace_callback( Html_Tag::opening_pattern( 'script' ), array( $this, 'replace_script' ), $html );
		}

		return strtr( $html, $comments );
	}

	public function replace_link( array $matches ): string {
		$tag = $matches[0];
		$rel = strtolower( (string) Html_Tag::get_attribute( $tag, 'rel' ) );
		$as  = strtolower( (string) Html_Tag::get_attribute( $tag, 'as' ) );

		// Folhas de estilo e o preload delas (a mesma URL nova nos dois).
		if ( ! preg_match( '/(?:^|\s)stylesheet(?:\s|$)/', $rel ) && ! ( preg_match( '/(?:^|\s)preload(?:\s|$)/', $rel ) && 'style' === $as ) ) {
			return $tag;
		}

		return $this->replace_url_attribute( $tag, 'href', 'css' );
	}

	public function replace_script( array $matches ): string {
		$tag  = $matches[0];
		$type = strtolower( trim( (string) Html_Tag::get_attribute( $tag, 'type' ) ) );

		if ( ! Html_Tag::has_attribute( $tag, 'src' ) ) {
			return $tag;
		}

		// Módulos ES importam caminhos relativos: mudar a pasta do arquivo quebraria os imports.
		if ( '' !== $type && ! in_array( $type, Html_Tag::JS_TYPES, true ) ) {
			return $tag;
		}

		return $this->replace_url_attribute( $tag, 'src', 'js' );
	}

	// URL minificada para um arquivo local; vazio se não der para minificar (externo, excluído, erro).
	public function get_minified_url( string $url, string $type ): string {
		$key = $type . '|' . $url;

		if ( isset( $this->resolved[ $key ] ) ) {
			return $this->resolved[ $key ];
		}

		$this->resolved[ $key ] = $this->build( $url, $type );

		return $this->resolved[ $key ];
	}

	private function replace_url_attribute( string $tag, string $attribute, string $type ): string {
		if ( Html_Tag::has_attribute( $tag, 'integrity' ) || Html_Tag::has_attribute( $tag, 'data-no-minify' ) || Html_Tag::has_attribute( $tag, 'data-o2r-minified' ) ) {
			return $tag;
		}

		$url = Html_Tag::get_attribute( $tag, $attribute );

		if ( null === $url || '' === trim( $url ) ) {
			return $tag;
		}

		$minified = $this->get_minified_url( html_entity_decode( trim( $url ), ENT_QUOTES ), $type );

		if ( '' === $minified ) {
			return $tag;
		}

		$tag = Html_Tag::set_attribute( $tag, $attribute, $minified );

		return (string) preg_replace( '#^<(link|script)\b#i', '<$1 data-o2r-minified', $tag, 1 );
	}

	private function build( string $url, string $type ): string {
		$extension = 'css' === $type ? 'css' : 'js';
		$path      = strtok( $url, '?#' );

		if ( ! is_string( $path ) || ! preg_match( '#\.' . $extension . '$#i', $path ) || $this->is_excluded( $url, $type ) ) {
			return '';
		}

		// Arquivos .min.js já passaram por um minificador melhor (terser/uglify): minificar de novo só traz risco.
		if ( 'js' === $type && preg_match( '#[.-]min\.js$#i', $path ) && ! apply_filters( 'o2_rocket_minify_min_js', false, $url ) ) {
			return '';
		}

		$file = Url_Resolver::to_path( $url );

		if ( '' === $file || 0 === strpos( $file, wp_normalize_path( self::get_cache_dir() ) ) ) {
			return '';
		}

		$size = (int) filesize( $file );

		if ( $size < 1 || $size > self::MAX_BYTES ) {
			return '';
		}

		$hash   = substr( md5( $file . '|' . filemtime( $file ) . '|' . $size . '|' . O2_ROCKET_VERSION ), 0, 12 );
		$slug   = sanitize_file_name( preg_replace( '#\.(?:min\.)?' . $extension . '$#i', '', wp_basename( $file ) ) );
		$name   = ( '' !== $slug ? substr( $slug, 0, 60 ) : $extension ) . '-' . $hash . '.' . $extension;
		$target = self::get_cache_dir() . $name;

		if ( is_file( $target ) ) {
			return self::get_cache_url() . $name;
		}

		$source = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( '' === trim( $source ) ) {
			return '';
		}

		// No CSS a comparação é com as URLs já absolutas (a cópia muda de pasta e precisa delas de qualquer jeito).
		if ( 'css' === $type ) {
			$source = Css_Url_Rewriter::make_absolute( $source, Url_Resolver::to_absolute( $url ) );
		}

		try {
			$minified = 'css' === $type ? $this->minify_css( $source ) : $this->minify_js( $source );
		} catch ( \Throwable $exception ) {
			return '';
		}

		// Sem ganho (ou resultado suspeito): fica o original.
		if ( '' === trim( $minified ) || strlen( $minified ) >= strlen( $source ) ) {
			return '';
		}

		if ( ! wp_mkdir_p( self::get_cache_dir() ) || ! atomic_write( $target, $minified ) ) {
			return '';
		}

		return self::get_cache_url() . $name;
	}

	private function minify_css( string $css ): string {
		$minifier = new Css_Minifier();
		$minifier->add( $css );

		$minified = $minifier->minify();

		if ( (bool) apply_filters( 'o2_rocket_font_display_swap', true ) ) {
			$minified = Css_Url_Rewriter::add_font_display( $minified );
		}

		// Outros módulos ajustam a cópia (ex.: imagens .jpg/.png trocadas pelo .webp).
		return (string) apply_filters( 'o2_rocket_minified_css', $minified );
	}

	private function minify_js( string $source ): string {
		$minifier = new Js_Minifier();
		$minifier->add( $source );

		// O ";" final evita que dois arquivos colados no mesmo lugar se misturem.
		return rtrim( $minifier->minify(), ';' ) . ';';
	}

	private function is_excluded( string $url, string $type ): bool {
		$settings = $this->get_settings();
		$patterns = 'css' === $type ? $settings[ self::SETTING_CSS_EXCLUDED ] : $settings[ self::SETTING_JS_EXCLUDED ];
		$patterns = (array) apply_filters( 'o2_rocket_minify_excluded', is_array( $patterns ) ? $patterns : array(), $type );

		foreach ( $patterns as $pattern ) {
			$pattern = trim( (string) $pattern );

			if ( '' !== $pattern && false !== stripos( $url, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	private function hide_comments( string $html, array &$comments ): string {
		return (string) preg_replace_callback(
			self::COMMENT_PATTERN,
			static function ( $matches ) use ( &$comments ) {
				$key              = '<!--o2r-comment-' . count( $comments ) . '-->';
				$comments[ $key ] = $matches[0];

				return $key;
			},
			$html
		);
	}
}
