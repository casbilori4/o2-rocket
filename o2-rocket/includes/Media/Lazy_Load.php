<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Media;

use O2Rocket\Optimization\Html_Pipeline;
use O2Rocket\Optimization\Html_Tag;

use function O2Rocket\script_url;

defined( 'ABSPATH' ) || exit;

// Lazy load no HTML da página inteira (cabeçalho, rodapé, widgets, construtores): imagens, <picture>, iframes,
// fundos em style="" e miniatura no lugar do player do YouTube. As imagens acima da dobra (medidas pelo beacon de LCP,
// ou as primeiras da página enquanto não há medição) carregam de imediato.
final class Lazy_Load {

	const SETTING_IMAGES   = 'media_lazyload_images';
	const SETTING_IFRAMES  = 'media_lazyload_iframes';
	const SETTING_YOUTUBE  = 'media_lazyload_youtube';
	const SETTING_CSS_BG   = 'media_lazyload_css_bg';
	const SETTING_EXCLUDED = 'media_lazyload_excluded';

	const CSS_CLASS     = 'o2-lazyload';
	const BG_CLASS      = 'o2-lazyload-bg';
	const YOUTUBE_CLASS = 'o2r-youtube';
	const SCRIPT_HANDLE = 'o2-rocket-lazyload';
	const SCRIPT_PATH   = 'assets/js/lazyload.js';

	const PIPELINE_PRIORITY = 40;
	const DEFAULT_EAGER     = 2;

	// Alternativas: (1) bloco que nunca é alterado; (2) <picture>…</picture>; (3) atributos de <img>; (4) atributos e (5)
	// conteúdo de <iframe>…</iframe>; (6) qualquer tag com style="" (fundos). Valores entre aspas não fecham a tag.
	const TAG_PATTERN = '#<(noscript|script|style|textarea|template|svg)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>.*?</\1\s*>|<picture\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>(.*?)</picture\s*>|<img\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>|<iframe\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>(.*?)</iframe\s*>|<[a-z][a-z0-9-]*\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*\sstyle\s*=\s*(?:"[^"]*url\([^"]*"|\'[^\']*url\([^\']*\')(?:[^>"\']|"[^"]*"|\'[^\']*\')*>#is';

	// Classes que indicam outro lazy load ou exclusão explícita.
	const SKIP_CLASSES = array( 'o2-lazyload', 'skip-lazy', 'no-lazyload', 'no-lazy', 'lazyload', 'lazyloaded', 'o2r-no-lazy' );

	// Atributos que indicam exclusão explícita ou outro lazy load já aplicado.
	const SKIP_ATTRIBUTES = array( 'data-no-lazy', 'data-skip-lazy', 'data-src', 'data-lazy-src', 'data-o2r-src', 'srcdoc' );

	const YOUTUBE_PATTERN = '#^(?:https?:)?//(?:www\.)?(?:youtube(?:-nocookie)?\.com/embed/|youtu\.be/)([A-Za-z0-9_-]{11})(?:[?&\#]|$)#i';

	private ?array $settings = null;

	private int $images_seen = 0;

	private int $backgrounds_seen = 0;

	private int $background_vars = 0;

	private bool $has_youtube = false;

	private ?array $eager_urls = null;

	private bool $eager_loaded = false;

	public function register_hooks(): void {
		if ( is_admin() || ! $this->is_active() ) {
			return;
		}

		add_filter( Html_Pipeline::FILTER, array( $this, 'process_html' ), self::PIPELINE_PRIORITY );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_script' ) );
		add_action( 'wp_head', array( $this, 'print_styles' ), 99 );
	}

	public function get_settings(): array {
		if ( null === $this->settings ) {
			$stored         = get_option( O2_ROCKET_OPTION, array() );
			$this->settings = wp_parse_args(
				is_array( $stored ) ? $stored : array(),
				array(
					self::SETTING_IMAGES   => false,
					self::SETTING_IFRAMES  => false,
					self::SETTING_YOUTUBE  => false,
					self::SETTING_CSS_BG   => false,
					self::SETTING_EXCLUDED => array(),
				)
			);
		}

		return $this->settings;
	}

	public function is_enabled( string $setting ): bool {
		$settings = $this->get_settings();

		return isset( $settings[ $setting ] ) && rest_sanitize_boolean( $settings[ $setting ] );
	}

	public function is_active(): bool {
		return $this->is_enabled( self::SETTING_IMAGES ) || $this->is_enabled( self::SETTING_IFRAMES ) || $this->is_enabled( self::SETTING_YOUTUBE ) || $this->is_enabled( self::SETTING_CSS_BG );
	}

	public function can_process(): bool {
		return Html_Pipeline::can_optimize() && (bool) apply_filters( 'o2_rocket_lazyload_enabled', true );
	}

	public function enqueue_script(): void {
		if ( ! $this->can_process() ) {
			return;
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			script_url( self::SCRIPT_PATH ),
			array(),
			O2_ROCKET_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	// Sem JavaScript o placeholder some e a cópia em <noscript> aparece; o player do YouTube ganha capa e botão.
	public function print_styles(): void {
		if ( ! $this->can_process() ) {
			return;
		}

		printf( '<noscript><style>.%s{display:none!important}</style></noscript>' . "\n", esc_attr( self::CSS_CLASS ) );

		if ( $this->is_enabled( self::SETTING_YOUTUBE ) ) {
			echo '<style id="o2r-youtube-css">.o2r-youtube{position:relative;display:block;width:100%;max-width:100%;aspect-ratio:16/9;background:#000;cursor:pointer;overflow:hidden}.o2r-youtube img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;margin:0}.o2r-youtube-play{position:absolute;top:50%;left:50%;width:68px;height:48px;margin:-24px 0 0 -34px;padding:0;border:0;border-radius:14px;background:rgba(23,23,23,.8);cursor:pointer;transition:background .2s}.o2r-youtube:hover .o2r-youtube-play,.o2r-youtube-play:focus-visible{background:#f00}.o2r-youtube-play:before{content:"";position:absolute;top:50%;left:55%;transform:translate(-50%,-50%);border-style:solid;border-width:11px 0 11px 19px;border-color:transparent transparent transparent #fff}.wp-has-aspect-ratio .o2r-youtube{position:absolute;top:0;left:0;height:100%;aspect-ratio:auto}</style>' . "\n";
		}
	}

	public function process_html( $html ) {
		if ( ! is_string( $html ) || '' === $html || ! $this->can_process() ) {
			return $html;
		}

		$this->images_seen      = 0;
		$this->backgrounds_seen = 0;

		// Só o <body>: no <head> não há mídia, e o "primeira imagem da página" conta a partir dele.
		$body_start = stripos( $html, '<body' );

		if ( false === $body_start ) {
			return $html;
		}

		$head = substr( $html, 0, $body_start );
		$body = $this->process( substr( $html, $body_start ) );

		return $head . $body;
	}

	public function process( string $html ): string {
		$images     = $this->is_enabled( self::SETTING_IMAGES );
		$iframes    = $this->is_enabled( self::SETTING_IFRAMES );
		$youtube    = $this->is_enabled( self::SETTING_YOUTUBE );
		$background = $this->is_enabled( self::SETTING_CSS_BG );

		$result = preg_replace_callback(
			self::TAG_PATTERN,
			function ( array $matches ) use ( $images, $iframes, $youtube, $background ) {
				// Grupos finais sem correspondência não vêm no array (alternativa de fundos não tem grupos).
				if ( isset( $matches[1] ) && '' !== $matches[1] ) {
					return $matches[0];
				}

				$element = $matches[0];

				if ( 0 === stripos( $element, '<picture' ) ) {
					return $images ? $this->lazyload_picture( $element ) : $element;
				}

				if ( 0 === stripos( $element, '<img' ) ) {
					// <img style="background:url()"> também cai aqui: imagem tem prioridade.
					return $images ? $this->lazyload_image( $element, true ) : $element;
				}

				if ( 0 === stripos( $element, '<iframe' ) ) {
					return $this->lazyload_iframe( $element, isset( $matches[4] ) ? $matches[4] : '', isset( $matches[5] ) ? $matches[5] : '', $iframes, $youtube );
				}

				return $background ? $this->lazyload_background( $element ) : $element;
			},
			$html
		);

		return is_string( $result ) ? $result : $html;
	}

	// Lazy load de um <img> (tag de abertura completa). $noscript = acrescenta a cópia original em <noscript>.
	public function lazyload_image( string $tag, bool $noscript ): string {
		$src = Html_Tag::get_attribute( $tag, 'src' );

		if ( null === $src || '' === trim( $src ) || 0 === stripos( ltrim( $src ), 'data:' ) ) {
			return $tag;
		}

		++$this->images_seen;

		if ( $this->is_eager( $tag, $this->images_seen ) || $this->should_skip( $tag, 'img' ) ) {
			return $tag;
		}

		$lazy = Html_Tag::rename_attribute( $tag, 'src', 'data-src' );

		if ( Html_Tag::has_attribute( $lazy, 'srcset' ) ) {
			$lazy = Html_Tag::rename_attribute( $lazy, 'srcset', 'data-srcset' );
		}

		$placeholder = $this->get_placeholder( Html_Tag::get_attribute( $tag, 'width' ), Html_Tag::get_attribute( $tag, 'height' ) );
		$lazy        = Html_Tag::add_class( Html_Tag::set_attribute( $lazy, 'src', $placeholder ), self::CSS_CLASS );

		return $noscript ? $lazy . '<noscript>' . $tag . '</noscript>' : $lazy;
	}

	// <picture>: o <img> e os <source> esperam juntos; a cópia sem JavaScript vai inteira para o <noscript>.
	private function lazyload_picture( string $element ): string {
		$lazy_image = false;

		$lazy = (string) preg_replace_callback(
			Html_Tag::opening_pattern( 'img' ),
			function ( $matches ) use ( &$lazy_image ) {
				$result     = $this->lazyload_image( $matches[0], false );
				$lazy_image = $lazy_image || $result !== $matches[0];

				return $result;
			},
			$element,
			1
		);

		if ( ! $lazy_image ) {
			return $element;
		}

		$lazy = (string) preg_replace_callback(
			Html_Tag::opening_pattern( 'source' ),
			static function ( $matches ) {
				return Html_Tag::rename_attribute( $matches[0], 'srcset', 'data-srcset' );
			},
			$lazy
		);

		return $lazy . '<noscript>' . $element . '</noscript>';
	}

	private function lazyload_iframe( string $element, string $attributes, string $inner, bool $lazy, bool $youtube ): string {
		$tag = '<iframe' . $attributes . '>';
		$src = Html_Tag::get_attribute( $tag, 'src' );

		if ( null === $src || '' === trim( $src ) || $this->should_skip( $tag, 'iframe' ) ) {
			return $element;
		}

		$src = html_entity_decode( trim( $src ), ENT_QUOTES );

		if ( 0 === stripos( $src, 'data:' ) || 0 === stripos( $src, 'about:' ) || 0 === stripos( $src, 'javascript:' ) || $this->is_admin_url( $src ) ) {
			return $element;
		}

		if ( $youtube && 1 === preg_match( self::YOUTUBE_PATTERN, $src, $video ) ) {
			return $this->youtube_facade( $element, $tag, $src, $video[1] );
		}

		if ( ! $lazy ) {
			return $element;
		}

		// Sem src o iframe fica no about:blank inicial: trocar depois não cria entrada no histórico.
		$lazy_tag = Html_Tag::add_class( Html_Tag::rename_attribute( $tag, 'src', 'data-src' ), self::CSS_CLASS );

		return $lazy_tag . $inner . '</iframe><noscript>' . $element . '</noscript>';
	}

	// Capa do vídeo + botão no lugar do player (centenas de KB de JavaScript a menos); o clique cria o iframe com autoplay.
	private function youtube_facade( string $element, string $tag, string $src, string $video_id ): string {
		$this->has_youtube = true;

		$autoplay = $src . ( false === strpos( $src, '?' ) ? '?' : '&' ) . 'autoplay=1';
		$iframe   = Html_Tag::set_attribute( $tag, 'src', $autoplay );
		$iframe   = Html_Tag::set_attribute( $iframe, 'allow', trim( html_entity_decode( (string) Html_Tag::get_attribute( $tag, 'allow' ), ENT_QUOTES ) . '; autoplay', '; ' ) );
		$title    = html_entity_decode( (string) Html_Tag::get_attribute( $tag, 'title' ), ENT_QUOTES );
		$width    = (int) Html_Tag::get_attribute( $tag, 'width' );
		$height   = (int) Html_Tag::get_attribute( $tag, 'height' );
		$style    = $width > 0 && $height > 0 ? 'aspect-ratio:' . $width . '/' . $height . ( $width < 2000 ? ';max-width:' . $width . 'px' : '' ) : '';
		$thumb    = (string) apply_filters( 'o2_rocket_youtube_thumbnail', 'https://i.ytimg.com/vi/' . $video_id . '/hqdefault.jpg', $video_id );
		$label    = '' !== $title ? sprintf( /* translators: %s: título do vídeo. */ __( 'Reproduzir vídeo: %s', 'o2-rocket' ), $title ) : __( 'Reproduzir vídeo', 'o2-rocket' );

		return sprintf(
			'<div class="%1$s" data-o2r-iframe="%2$s"%3$s><img src="%4$s" alt="%5$s" width="480" height="360" loading="lazy" decoding="async"><button type="button" class="o2r-youtube-play" aria-label="%6$s"></button></div><noscript>%7$s</noscript>',
			esc_attr( self::YOUTUBE_CLASS ),
			esc_attr( $iframe . '</iframe>' ),
			'' !== $style ? ' style="' . esc_attr( $style ) . '"' : '',
			esc_url( $thumb ),
			esc_attr( $title ),
			esc_attr( $label ),
			$element
		);
	}

	// style="background: url(x)" vira style="background: var(--o2r-bg-N, none)" e a URL espera em data-o2r-bg.
	private function lazyload_background( string $tag ): string {
		$style = Html_Tag::get_attribute( $tag, 'style' );

		if ( null === $style || false === stripos( $style, 'url(' ) || $this->should_skip( $tag, 'background' ) ) {
			return $tag;
		}

		$decoded = html_entity_decode( $style, ENT_QUOTES );
		$urls    = array();
		$eager   = false;

		++$this->backgrounds_seen;

		$rewritten = (string) preg_replace_callback(
			'#url\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^)]*?))\s*\)#i',
			function ( $matches ) use ( &$urls, &$eager ) {
				$url = trim( '' !== $matches[1] ? $matches[1] : ( isset( $matches[2] ) && '' !== $matches[2] ? $matches[2] : ( isset( $matches[3] ) ? $matches[3] : '' ) ) );

				if ( '' === $url || 0 === stripos( $url, 'data:' ) ) {
					return $matches[0];
				}

				if ( $this->is_eager_url( $url ) ) {
					$eager = true;

					return $matches[0];
				}

				$name          = '--o2r-bg-' . ( ++$this->background_vars );
				$urls[ $name ] = $url;

				return 'var(' . $name . ',none)';
			},
			$decoded
		);

		// Sem medição de LCP, o primeiro fundo da página (geralmente o banner do topo) carrega de imediato.
		if ( empty( $urls ) || $eager || ( null === $this->get_eager_urls() && $this->backgrounds_seen <= 1 ) ) {
			return $tag;
		}

		$tag = Html_Tag::set_attribute( $tag, 'style', $rewritten );
		$tag = Html_Tag::set_attribute( $tag, 'data-o2r-bg', (string) wp_json_encode( $urls, JSON_UNESCAPED_SLASHES ) );

		return Html_Tag::add_class( $tag, self::BG_CLASS );
	}

	// Primeiras N imagens (sem medição) ou imagens que o beacon viu acima da dobra.
	private function is_eager( string $tag, int $position ): bool {
		$eager = $this->get_eager_urls();

		if ( null === $eager ) {
			return $position <= max( 0, (int) apply_filters( 'o2_rocket_lazyload_eager_images', self::DEFAULT_EAGER ) );
		}

		foreach ( $this->get_tag_urls( $tag ) as $url ) {
			if ( $this->is_eager_url( $url ) ) {
				return true;
			}
		}

		return false;
	}

	private function is_eager_url( string $url ): bool {
		$eager = $this->get_eager_urls();

		return null !== $eager && isset( $eager[ self::normalize_url( $url ) ] );
	}

	// Caminhos das imagens acima da dobra (null = ainda sem medição para esta página).
	private function get_eager_urls(): ?array {
		if ( ! $this->eager_loaded ) {
			$this->eager_loaded = true;

			$urls = apply_filters( 'o2_rocket_lazyload_eager_urls', null );

			if ( is_array( $urls ) ) {
				$this->eager_urls = array_flip( array_map( array( self::class, 'normalize_url' ), array_filter( $urls, 'is_string' ) ) );
			}
		}

		return $this->eager_urls;
	}

	// src e todos os candidatos do srcset.
	private function get_tag_urls( string $tag ): array {
		$urls = array( html_entity_decode( (string) Html_Tag::get_attribute( $tag, 'src' ), ENT_QUOTES ) );

		foreach ( explode( ',', html_entity_decode( (string) Html_Tag::get_attribute( $tag, 'srcset' ), ENT_QUOTES ) ) as $candidate ) {
			$candidate = trim( (string) preg_replace( '/\s+\S+$/', '', trim( $candidate ) ) );

			if ( '' !== $candidate ) {
				$urls[] = $candidate;
			}
		}

		return $urls;
	}

	// Caminho sem host, query e sufixo .webp (a etapa de WebP roda depois do lazy load).
	public static function normalize_url( string $url ): string {
		$path = wp_parse_url( html_entity_decode( trim( $url ), ENT_QUOTES ), PHP_URL_PATH );
		$path = is_string( $path ) ? rawurldecode( $path ) : '';

		return strtolower( (string) preg_replace( '#\.(jpe?g|png|gif)\.(?:webp|avif)$#i', '.$1', $path ) );
	}

	private function should_skip( string $tag, string $type ): bool {
		foreach ( self::SKIP_ATTRIBUTES as $attribute ) {
			if ( Html_Tag::has_attribute( $tag, $attribute ) ) {
				return true;
			}
		}

		foreach ( self::SKIP_CLASSES as $class ) {
			if ( Html_Tag::has_class( $tag, $class ) ) {
				return true;
			}
		}

		// Prioridade definida pelo WordPress ou pelo tema (candidata a LCP).
		if ( 'img' === $type && ( 'eager' === strtolower( trim( (string) Html_Tag::get_attribute( $tag, 'loading' ) ) ) || 'high' === strtolower( trim( (string) Html_Tag::get_attribute( $tag, 'fetchpriority' ) ) ) ) ) {
			return true;
		}

		foreach ( (array) $this->get_settings()[ self::SETTING_EXCLUDED ] as $pattern ) {
			$pattern = trim( (string) $pattern );

			if ( '' !== $pattern && false !== stripos( $tag, $pattern ) ) {
				return true;
			}
		}

		return (bool) apply_filters( 'o2_rocket_lazyload_skip', false, $tag, $type );
	}

	// SVG transparente com a mesma proporção da imagem: reserva o espaço e evita CLS.
	private function get_placeholder( ?string $width, ?string $height ): string {
		$width  = null !== $width && ctype_digit( trim( $width ) ) ? (int) $width : 0;
		$height = null !== $height && ctype_digit( trim( $height ) ) ? (int) $height : 0;

		if ( $width < 1 || $height < 1 ) {
			$width  = 1;
			$height = 1;
		}

		return sprintf( "data:image/svg+xml,%%3Csvg%%20xmlns='http://www.w3.org/2000/svg'%%20viewBox='0%%200%%20%d%%20%d'%%3E%%3C/svg%%3E", $width, $height );
	}

	private function is_admin_url( string $src ): bool {
		$admin_path = wp_parse_url( admin_url(), PHP_URL_PATH );
		$src_path   = wp_parse_url( $src, PHP_URL_PATH );

		return is_string( $admin_path ) && is_string( $src_path ) && 0 === strpos( $src_path, $admin_path );
	}
}
