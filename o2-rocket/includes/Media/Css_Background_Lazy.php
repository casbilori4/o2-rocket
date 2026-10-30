<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Media;

use O2Rocket\Core\Url_Resolver;
use O2Rocket\Optimization\Asset_Minifier;
use O2Rocket\Optimization\Css_Url_Rewriter;
use O2Rocket\Optimization\Html_Pipeline;
use O2Rocket\Optimization\Html_Tag;

use function O2Rocket\atomic_write;

defined( 'ABSPATH' ) || exit;

// Lazy load de imagens de fundo definidas em CSS (arquivos locais e blocos <style>), como as seções do Elementor.
// "background: url(x)" vira "background: var(--o2r-bg-HASH, none)"; o lazyload.js define a variável no elemento
// quando ele chega perto da tela. Fundos acima da dobra (beacon de LCP) ficam definidos desde o início.
final class Css_Background_Lazy {

	const PIPELINE_PRIORITY = 38;
	const VAR_PREFIX        = '--o2r-bg-';

	// Fundos de estados (hover, foco...) só aparecem com interação: ficam como estão.
	const DYNAMIC_PSEUDO  = '#:(?:hover|focus|focus-within|focus-visible|active|checked|target|visited)\b#i';
	const PSEUDO_ELEMENTS = '#::?(?:before|after|first-line|first-letter|placeholder|marker|backdrop|selection|file-selector-button)\b#i';
	const KEYFRAME        = '#^(?:from|to|[\d.]+%)(?:\s*,\s*(?:from|to|[\d.]+%))*$#i';

	private Lazy_Load $lazy_load;

	private array $rules = array();

	private array $vars = array();

	private ?array $eager = null;

	public function __construct( Lazy_Load $lazy_load ) {
		$this->lazy_load = $lazy_load;
	}

	public function register_hooks(): void {
		if ( is_admin() || ! $this->lazy_load->is_enabled( Lazy_Load::SETTING_CSS_BG ) ) {
			return;
		}

		add_filter( Html_Pipeline::FILTER, array( $this, 'process_html' ), self::PIPELINE_PRIORITY );
	}

	public function process_html( $html ) {
		if ( ! is_string( $html ) || '' === $html || ! $this->lazy_load->can_process() ) {
			return $html;
		}

		$this->rules = array();
		$this->vars  = array();

		$protected = array();
		$html      = (string) preg_replace_callback(
			'#<(noscript|template|svg)\b.*?</\1\s*>#is',
			static function ( $matches ) use ( &$protected ) {
				$key               = '<!--o2r-bg-protected-' . count( $protected ) . '-->';
				$protected[ $key ] = $matches[0];

				return $key;
			},
			$html
		);

		$html = (string) preg_replace_callback(
			'#(<style\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>)(.*?)(</style\s*>)#is',
			function ( $matches ) {
				if ( Html_Tag::has_attribute( $matches[1], 'data-o2r-nolazy' ) ) {
					return $matches[0];
				}

				$rules = array();
				$css   = $this->rewrite_css( $matches[2], $rules );
				$this->add_rules( $rules );

				return $matches[1] . $css . $matches[3];
			},
			$html
		);

		$html = (string) preg_replace_callback( Html_Tag::opening_pattern( 'link' ), array( $this, 'replace_link' ), $html );
		$html = strtr( $html, $protected );

		return $this->print_config( $html );
	}

	public function replace_link( array $matches ): string {
		$tag = $matches[0];

		if ( ! preg_match( '/(?:^|\s)stylesheet(?:\s|$)/i', (string) Html_Tag::get_attribute( $tag, 'rel' ) ) || Html_Tag::has_attribute( $tag, 'integrity' ) ) {
			return $tag;
		}

		$href = Html_Tag::get_attribute( $tag, 'href' );

		if ( null === $href || '' === trim( $href ) ) {
			return $tag;
		}

		$new = $this->process_file( html_entity_decode( trim( $href ), ENT_QUOTES ) );

		return '' === $new ? $tag : Html_Tag::set_attribute( $tag, 'href', $new );
	}

	// Reescreve os fundos e anota (em $rules) seletor => [ variável => URL ].
	public function rewrite_css( string $css, array &$rules ): string {
		if ( false === stripos( $css, 'url(' ) || false === stripos( $css, 'background' ) ) {
			return $css;
		}

		$result = preg_replace_callback(
			'#([^{}]+)\{([^{}]*)\}#',
			function ( $matches ) use ( &$rules ) {
				$head     = $matches[1];
				$cut      = strrpos( $head, ';' );
				$prefix   = false === $cut ? '' : substr( $head, 0, $cut + 1 );
				$selector = trim( (string) preg_replace( '#/\*.*?\*/#s', '', false === $cut ? $head : substr( $head, $cut + 1 ) ) );
				$body     = $matches[2];

				if ( '' === $selector || '@' === $selector[0] || 1 === preg_match( self::KEYFRAME, $selector ) || false === stripos( $body, 'url(' ) || 1 === preg_match( self::DYNAMIC_PSEUDO, $selector ) ) {
					return $matches[0];
				}

				$targets = $this->get_observed_selectors( $selector );

				if ( empty( $targets ) ) {
					return $matches[0];
				}

				$vars     = array();
				$new_body = (string) preg_replace_callback(
					'#(^|;)(\s*background(?:-image)?\s*:)([^;]*)#i',
					function ( $declaration ) use ( &$vars ) {
						return $declaration[1] . $declaration[2] . preg_replace_callback(
							Css_Url_Rewriter::URL_PATTERN,
							function ( $url ) use ( &$vars ) {
								$value = trim( '' !== $url[1] ? $url[1] : ( isset( $url[2] ) && '' !== $url[2] ? $url[2] : ( isset( $url[3] ) ? $url[3] : '' ) ) );

								if ( '' === $value || 0 === stripos( $value, 'data:' ) || $this->is_excluded( $value ) ) {
									return $url[0];
								}

								$name          = self::VAR_PREFIX . substr( md5( $value ), 0, 10 );
								$vars[ $name ] = $value;

								return 'var(' . $name . ',none)';
							},
							$declaration[3]
						);
					},
					$body
				);

				if ( empty( $vars ) ) {
					return $matches[0];
				}

				foreach ( $targets as $target ) {
					$rules[ $target ] = array_merge( isset( $rules[ $target ] ) ? $rules[ $target ] : array(), $vars );
				}

				return $prefix . substr( $head, strlen( $prefix ) ) . '{' . $new_body . '}';
			},
			$css
		);

		return is_string( $result ) ? $result : $css;
	}

	// Seletores a observar: sem pseudo-elementos (a variável definida no elemento vale para ::before/::after).
	public function get_observed_selectors( string $selector ): array {
		$targets = array();

		foreach ( self::split_selectors( $selector ) as $part ) {
			$part = trim( (string) preg_replace( self::PSEUDO_ELEMENTS, '', $part ) );

			if ( '' !== $part ) {
				$targets[] = $part;
			}
		}

		return array_values( array_unique( $targets ) );
	}

	// Vírgulas fora de parênteses e colchetes (":is(a, b)" fica inteiro).
	public static function split_selectors( string $selector ): array {
		$parts = array();
		$depth = 0;
		$start = 0;
		$len   = strlen( $selector );

		for ( $i = 0; $i < $len; $i++ ) {
			$char = $selector[ $i ];

			if ( '(' === $char || '[' === $char ) {
				++$depth;
			} elseif ( ( ')' === $char || ']' === $char ) && $depth > 0 ) {
				--$depth;
			} elseif ( ',' === $char && 0 === $depth ) {
				$parts[] = substr( $selector, $start, $i - $start );
				$start   = $i + 1;
			}
		}

		$parts[] = substr( $selector, $start );

		return $parts;
	}

	// Cópia reescrita do arquivo (com o mapa de seletores ao lado); vazio se o arquivo não tem fundos.
	private function process_file( string $url ): string {
		$file = Url_Resolver::to_path( $url );

		if ( '' === $file || '.css' !== strtolower( substr( $file, -4 ) ) ) {
			return '';
		}

		$key  = substr( md5( $file . '|' . filemtime( $file ) . '|' . filesize( $file ) . '|' . O2_ROCKET_VERSION ), 0, 12 );
		$slug = substr( sanitize_file_name( (string) preg_replace( '#(?:\.min)?\.css$#i', '', wp_basename( $file ) ) ), 0, 50 );
		$name = ( '' !== $slug ? $slug : 'css' ) . '-bg-' . $key;
		$dir  = Asset_Minifier::get_cache_dir();
		$map  = $dir . $name . '.json';

		if ( is_file( $map ) ) {
			$data = json_decode( (string) file_get_contents( $map ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			if ( is_array( $data ) && isset( $data['rules'] ) && is_array( $data['rules'] ) ) {
				if ( empty( $data['rules'] ) ) {
					return '';
				}

				if ( is_file( $dir . $name . '.css' ) ) {
					$this->add_rules( $data['rules'] );

					return Asset_Minifier::get_cache_url() . $name . '.css';
				}
			}
		}

		$css = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( '' === trim( $css ) || ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		$rules = array();
		$new   = $this->rewrite_css( Css_Url_Rewriter::make_absolute( $css, Url_Resolver::to_absolute( $url ) ), $rules );

		// O mapa vazio também é gravado: o arquivo não é lido de novo a cada página.
		if ( ! atomic_write( $map, (string) wp_json_encode( array( 'rules' => $rules ) ) ) ) {
			return '';
		}

		if ( empty( $rules ) || ! atomic_write( $dir . $name . '.css', $new ) ) {
			return '';
		}

		$this->add_rules( $rules );

		return Asset_Minifier::get_cache_url() . $name . '.css';
	}

	private function add_rules( array $rules ): void {
		foreach ( $rules as $selector => $vars ) {
			if ( ! is_string( $selector ) || ! is_array( $vars ) ) {
				continue;
			}

			$this->rules[ $selector ] = array_merge( isset( $this->rules[ $selector ] ) ? $this->rules[ $selector ] : array(), $vars );

			foreach ( $vars as $name => $url ) {
				$this->vars[ (string) $name ] = (string) $url;
			}
		}
	}

	// Fundos acima da dobra: variável definida no :root desde o início. Os demais: mapa para o lazyload.js.
	private function print_config( string $html ): string {
		if ( empty( $this->rules ) ) {
			return $html;
		}

		$eager = array();

		foreach ( $this->vars as $name => $url ) {
			if ( $this->is_eager( $url ) ) {
				$eager[ $name ] = $url;
			}
		}

		$list = array();

		foreach ( $this->rules as $selector => $vars ) {
			$vars = array_diff_key( $vars, $eager );

			if ( ! empty( $vars ) ) {
				$list[] = array(
					's' => $selector,
					'v' => $vars,
				);
			}
		}

		if ( ! empty( $eager ) ) {
			$declarations = '';

			// Dentro de <style> não há entidades HTML: aspas e sinais de tag viram %XX na própria URL.
			foreach ( $eager as $name => $url ) {
				$declarations .= $name . ':url("' . str_replace( array( '"', '<', '>', '\\' ), array( '%22', '%3C', '%3E', '%5C' ), $url ) . '");';
			}

			$style    = '<style id="o2r-bg-eager">:root{' . $declarations . '}</style>';
			$position = stripos( $html, '</head>' );
			$html     = false === $position ? $style . $html : substr_replace( $html, $style, $position, 0 );
		}

		if ( empty( $list ) ) {
			return $html;
		}

		$script   = '<script id="o2r-lazy-bg" data-o2r-nodelay>window.o2rLazyBg=' . wp_json_encode( $list, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ) . ';</script>';
		$position = strripos( $html, '</body>' );

		return false === $position ? $html . $script : substr_replace( $html, $script, $position, 0 );
	}

	private function is_eager( string $url ): bool {
		if ( null === $this->eager ) {
			$urls        = apply_filters( 'o2_rocket_lazyload_eager_urls', null );
			$this->eager = is_array( $urls ) ? array_flip( array_map( array( Lazy_Load::class, 'normalize_url' ), array_filter( $urls, 'is_string' ) ) ) : array();
		}

		return isset( $this->eager[ Lazy_Load::normalize_url( $url ) ] );
	}

	private function is_excluded( string $url ): bool {
		foreach ( (array) $this->lazy_load->get_settings()[ Lazy_Load::SETTING_EXCLUDED ] as $pattern ) {
			$pattern = trim( (string) $pattern );

			if ( '' !== $pattern && false !== stripos( $url, $pattern ) ) {
				return true;
			}
		}

		return false;
	}
}
