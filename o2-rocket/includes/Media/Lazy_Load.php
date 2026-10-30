<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Media;

use O2Rocket\Core\Request;

defined( 'ABSPATH' ) || exit;

final class Lazy_Load {

	const SETTING_IMAGES  = 'media_lazyload_images';
	const SETTING_IFRAMES = 'media_lazyload_iframes';

	const CSS_CLASS       = 'o2-lazyload';
	const SCRIPT_HANDLE   = 'o2-rocket-lazyload';
	const SCRIPT_PATH     = 'assets/js/lazyload.js';
	const FILTER_PRIORITY = 99;

	// Alternativas: (1) bloco que nunca é alterado; (2) atributos de <img>; (3) atributos e (4) conteúdo de <iframe>…</iframe>.
	// Valores entre aspas duplas, simples ou sem aspas; ">" dentro de aspas não fecha a tag. Iframe sem </iframe> não casa.
	const TAG_PATTERN = '#<(noscript|script|style|textarea|template)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>.*?</\1\s*>|<img\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>|<iframe\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>(.*?)</iframe\s*>#is';

	// Classes que indicam outro lazy load ou exclusão explícita.
	const SKIP_CLASSES = array( 'o2-lazyload', 'skip-lazy', 'no-lazyload', 'no-lazy', 'lazyload', 'lazyloaded' );

	// Atributos que indicam exclusão explícita ou outro lazy load já aplicado.
	const SKIP_ATTRIBUTES = array( 'data-no-lazy', 'data-skip-lazy', 'data-src', 'data-lazy-src', 'srcdoc' );

	private ?array $settings = null;

	private int $images_seen = 0;

	private array $posts_with_first_image = array();

	public function register_hooks(): void {
		if ( is_admin() ) {
			return;
		}

		$images  = $this->is_enabled( self::SETTING_IMAGES );
		$iframes = $this->is_enabled( self::SETTING_IFRAMES );

		if ( ! $images && ! $iframes ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_script' ) );
		add_action( 'wp_head', array( $this, 'print_noscript_style' ), 99 );
		add_filter( 'the_content', array( $this, 'filter_content' ), self::FILTER_PRIORITY );

		if ( $images ) {
			add_filter( 'post_thumbnail_html', array( $this, 'filter_thumbnail' ), self::FILTER_PRIORITY );
		}
	}

	public function get_settings(): array {
		if ( null === $this->settings ) {
			$stored         = get_option( O2_ROCKET_OPTION, array() );
			$this->settings = wp_parse_args(
				is_array( $stored ) ? $stored : array(),
				array(
					self::SETTING_IMAGES  => false,
					self::SETTING_IFRAMES => false,
				)
			);
		}

		return $this->settings;
	}

	public function is_enabled( string $setting ): bool {
		$settings = $this->get_settings();

		return isset( $settings[ $setting ] ) && rest_sanitize_boolean( $settings[ $setting ] );
	}

	// wp_enqueue_scripts roda dentro do wp_head: sem a exigência de estar no <body>.
	public function enqueue_script(): void {
		if ( ! $this->can_process( false ) ) {
			return;
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( self::SCRIPT_PATH, O2_ROCKET_FILE ),
			array(),
			O2_ROCKET_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	// Sem JavaScript, o placeholder some e a cópia em <noscript> aparece.
	public function print_noscript_style(): void {
		if ( $this->can_process( false ) ) {
			printf( '<noscript><style>.%s{display:none!important}</style></noscript>' . "\n", esc_attr( self::CSS_CLASS ) );
		}
	}

	public function filter_content( $content ) {
		if ( ! is_string( $content ) || '' === $content || ! $this->can_process() ) {
			return $content;
		}

		// "Primeira imagem do post": conteúdo do post principal da página singular (com ou sem loop no tema).
		$post_id = (int) get_the_ID();
		$is_main = $post_id > 0 && is_singular() && is_main_query() && get_queried_object_id() === $post_id;

		return $this->process( $content, $is_main ? $post_id : 0 );
	}

	public function filter_thumbnail( $html ) {
		if ( ! is_string( $html ) || '' === $html || ! $this->can_process() ) {
			return $html;
		}

		return $this->process( $html, 0 );
	}

	public function can_process( bool $require_body = true ): bool {
		if ( ! Request::is_public_frontend() || is_feed() || is_embed() ) {
			return false;
		}

		// Conteúdo filtrado dentro do <head> (ex.: plugins de SEO) não é página renderizada.
		if ( $require_body && ( ! did_action( 'wp_head' ) || doing_action( 'wp_head' ) ) ) {
			return false;
		}

		return (bool) apply_filters( 'o2_rocket_lazyload_enabled', true );
	}

	public function process( string $html, int $main_post_id = 0 ): string {
		$images  = $this->is_enabled( self::SETTING_IMAGES );
		$iframes = $this->is_enabled( self::SETTING_IFRAMES );

		return (string) preg_replace_callback(
			self::TAG_PATTERN,
			function ( array $matches ) use ( $images, $iframes, $main_post_id ) {
				if ( '' !== $matches[1] ) {
					return $matches[0];
				}

				if ( 0 === stripos( $matches[0], '<img' ) ) {
					return $images ? $this->lazyload_image( $matches[0], $matches[2], $main_post_id ) : $matches[0];
				}

				return $iframes ? $this->lazyload_iframe( $matches[0], $matches[3], $matches[4] ) : $matches[0];
			},
			$html
		);
	}

	private function lazyload_image( string $tag, string $attributes, int $main_post_id ): string {
		$src = $this->get_attribute( $attributes, 'src' );

		if ( null === $src || '' === trim( $src ) || 0 === stripos( ltrim( $src ), 'data:' ) ) {
			return $tag;
		}

		++$this->images_seen;

		// Bypass de LCP: primeiras imagens da página e primeira imagem do conteúdo do post principal.
		$eager_images = max( 0, (int) apply_filters( 'o2_rocket_lazyload_eager_images', 1 ) );
		$is_first     = $this->images_seen <= $eager_images;

		if ( $main_post_id > 0 && ! isset( $this->posts_with_first_image[ $main_post_id ] ) ) {
			$this->posts_with_first_image[ $main_post_id ] = true;
			$is_first                                      = true;
		}

		if ( $is_first || $this->should_skip( $attributes, 'img' ) ) {
			return $tag;
		}

		$lazy = $this->rename_attribute( $attributes, 'src', 'data-src' );

		if ( null !== $this->get_attribute( $lazy, 'srcset' ) ) {
			$lazy = $this->rename_attribute( $lazy, 'srcset', 'data-srcset' );
		}

		$placeholder = $this->get_placeholder( $this->get_attribute( $attributes, 'width' ), $this->get_attribute( $attributes, 'height' ) );
		$lazy        = ' src="' . esc_attr( $placeholder ) . '"' . $this->add_class( $lazy );

		return '<img' . $lazy . '><noscript>' . $tag . '</noscript>';
	}

	private function lazyload_iframe( string $element, string $attributes, string $inner ): string {
		$src = $this->get_attribute( $attributes, 'src' );

		if ( null === $src || '' === trim( $src ) || $this->should_skip( $attributes, 'iframe' ) ) {
			return $element;
		}

		$src = html_entity_decode( trim( $src ), ENT_QUOTES );

		if ( 0 === stripos( $src, 'data:' ) || 0 === stripos( $src, 'about:' ) || 0 === stripos( $src, 'javascript:' ) || $this->is_admin_url( $src ) ) {
			return $element;
		}

		// Sem src o iframe fica no about:blank inicial: trocar depois não cria entrada no histórico.
		$lazy = $this->add_class( $this->rename_attribute( $attributes, 'src', 'data-src' ) );

		return '<iframe' . $lazy . '>' . $inner . '</iframe><noscript>' . $element . '</noscript>';
	}

	private function should_skip( string $attributes, string $tag ): bool {
		foreach ( self::SKIP_ATTRIBUTES as $attribute ) {
			if ( null !== $this->get_attribute( $attributes, $attribute ) || $this->has_boolean_attribute( $attributes, $attribute ) ) {
				return true;
			}
		}

		$classes = preg_split( '/\s+/', strtolower( (string) $this->get_attribute( $attributes, 'class' ) ) );

		if ( array_intersect( self::SKIP_CLASSES, (array) $classes ) ) {
			return true;
		}

		// Prioridade definida pelo WordPress ou pelo tema (candidata a LCP).
		if ( 'eager' === strtolower( trim( (string) $this->get_attribute( $attributes, 'loading' ) ) ) ) {
			return true;
		}

		if ( 'high' === strtolower( trim( (string) $this->get_attribute( $attributes, 'fetchpriority' ) ) ) ) {
			return true;
		}

		return (bool) apply_filters( 'o2_rocket_lazyload_skip', false, $attributes, $tag );
	}

	// Valor bruto (ainda com entidades HTML) de um atributo; null se ausente ou sem valor.
	private function get_attribute( string $attributes, string $name ): ?string {
		$pattern = '#(?:^|\s)' . preg_quote( $name, '#' ) . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))#i';

		if ( 1 !== preg_match( $pattern, $attributes, $matches ) ) {
			return null;
		}

		if ( isset( $matches[3] ) && '' !== $matches[3] ) {
			return $matches[3];
		}

		return isset( $matches[2] ) && '' !== $matches[2] ? $matches[2] : $matches[1];
	}

	private function has_boolean_attribute( string $attributes, string $name ): bool {
		return 1 === preg_match( '#(?:^|\s)' . preg_quote( $name, '#' ) . '(?=\s|/|$)#i', $attributes );
	}

	// Troca apenas o nome do atributo exato ("src" não casa com "data-src", que vem depois de "-").
	private function rename_attribute( string $attributes, string $from, string $to ): string {
		return (string) preg_replace( '#(^|\s)' . preg_quote( $from, '#' ) . '(\s*=)#i', '${1}' . $to . '${2}', $attributes, 1 );
	}

	private function add_class( string $attributes ): string {
		$pattern = '#(^|\s)class\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))#i';

		if ( 1 !== preg_match( $pattern, $attributes, $matches, PREG_OFFSET_CAPTURE ) ) {
			return ' class="' . self::CSS_CLASS . '"' . $attributes;
		}

		$current = '';

		foreach ( array( 4, 3, 2 ) as $group ) {
			if ( isset( $matches[ $group ] ) && -1 !== $matches[ $group ][1] && '' !== $matches[ $group ][0] ) {
				$current = $matches[ $group ][0];
				break;
			}
		}

		$replacement = $matches[1][0] . 'class="' . esc_attr( trim( html_entity_decode( $current, ENT_QUOTES ) . ' ' . self::CSS_CLASS ) ) . '"';

		return substr_replace( $attributes, $replacement, $matches[0][1], strlen( $matches[0][0] ) );
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
