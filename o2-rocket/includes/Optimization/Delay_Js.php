<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Optimization;

use O2Rocket\Core\Url_Resolver;

use function O2Rocket\inline_script;

defined( 'ABSPATH' ) || exit;

// Atrasa a execução dos scripts até a primeira interação do visitante (mouse, toque, teclado, rolagem da roda).
// Os <script> viram type="o2rocket/delayed" (src em data-o2r-src) e um carregador em linha os executa em ordem depois.
final class Delay_Js {

	const SETTING_ENABLED    = 'file_delay_js';
	const SETTING_AUTO       = 'file_delay_js_auto_exclusions';
	const SETTING_SERVICES   = 'file_delay_js_services';
	const SETTING_SAFE_MODE  = 'file_delay_js_safe_mode';
	const SETTING_TIMEOUT    = 'file_delay_js_timeout';
	const SETTING_EXCLUSIONS = 'file_delay_js_exclusions';

	const DELAYED_TYPE      = 'o2rocket/delayed';
	const PIPELINE_PRIORITY = 70;
	const DATA_FILE         = 'data/delay-js-exclusions.php';
	const LOADER_FILE       = 'assets/js/delay-js';
	const ELEMENTOR_FILE    = 'assets/js/elementor-animations';
	const AOS_FILE          = 'assets/js/aos-visible';
	const REGEX_CHUNK       = 40;

	const SCRIPT_REGEX = '#<script\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>(.*?)</script\s*>#is';

	// Arquivo do jQuery (e do Migrate), com ou sem versão no nome.
	const JQUERY_FILE = '#/jquery(?:-migrate)?(?:[.-]\d+(?:\.\d+)*)?(?:\.slim)?(?:\.min)?\.js(?:$|\?)#i';

	// Código que usa o jQuery: jQuery(...), jQuery.fn, $(document), $(function, $('seletor').
	const USES_JQUERY = '#\bjQuery\b|\$\s*\(\s*(?:document|window|function|[\'"])#';

	// Modo seguro: jQuery, scripts em linha do WordPress e todos os arquivos do site rodam normalmente; só terceiros esperam.
	const SAFE_MODE_PATTERNS = array(
		'\/jquery(-migrate)?-?([0-9.]+)?(\.min|\.slim|\.slim\.min)?\.js',
		'js-(before|after|extra)',
		'(?:/wp-content/|/wp-includes/)',
	);

	// Scripts do próprio O² Rocket que precisam rodar já (lazy load, medição de LCP, prefetch).
	const OWN_PATTERNS = array(
		'o2-rocket-lazyload',
		'o2-rocket-prefetch',
		'o2rPrefetch',
		'o2r-beacon',
		'o2rBeacon',
		'data-o2r-nodelay',
		'data-no-delay',
		'data-cfasync="false"',
	);

	private ?array $settings = null;

	private ?array $regexes = null;

	private ?array $data = null;

	private int $delayed = 0;

	private bool $keep_jquery = false;

	// Arquivos locais já lidos nesta requisição: caminho => usa o jQuery.
	private array $file_uses_jquery = array();

	public function register_hooks(): void {
		if ( is_admin() || ! $this->is_enabled() ) {
			return;
		}

		add_filter( Html_Pipeline::FILTER, array( $this, 'delay' ), self::PIPELINE_PRIORITY );
	}

	public function get_settings(): array {
		if ( null === $this->settings ) {
			$stored         = get_option( O2_ROCKET_OPTION, array() );
			$this->settings = wp_parse_args(
				is_array( $stored ) ? $stored : array(),
				array(
					self::SETTING_ENABLED    => false,
					self::SETTING_AUTO       => true,
					self::SETTING_SERVICES   => array(),
					self::SETTING_SAFE_MODE  => false,
					self::SETTING_TIMEOUT    => 0,
					self::SETTING_EXCLUSIONS => array(),
				)
			);
		}

		return $this->settings;
	}

	public function is_enabled(): bool {
		return rest_sanitize_boolean( $this->get_settings()[ self::SETTING_ENABLED ] );
	}

	public function delay( $html ) {
		if ( ! is_string( $html ) || '' === $html || ! Html_Pipeline::is_visitor() || ! (bool) apply_filters( 'o2_rocket_delay_js_enabled', true ) ) {
			return $html;
		}

		$this->delayed = 0;

		// <noscript>, <template>, <textarea> e SVG ficam de fora (scripts lá dentro não rodam como os da página).
		$protected = array();
		$html      = (string) preg_replace_callback(
			'#<(noscript|template|textarea|xmp|svg)\b.*?</\1\s*>#is',
			static function ( $matches ) use ( &$protected ) {
				$key               = '<!--o2r-protected-' . count( $protected ) . '-->';
				$protected[ $key ] = $matches[0];

				return $key;
			},
			$html
		);

		$this->keep_jquery = $this->needs_jquery_now( $html );

		$result = preg_replace_callback( self::SCRIPT_REGEX, array( $this, 'replace_script' ), $html );

		if ( ! is_string( $result ) ) {
			return strtr( $html, $protected );
		}

		$result = strtr( $result, $protected );

		if ( 0 === $this->delayed ) {
			return $result;
		}

		return $this->insert_loader( $result );
	}

	public function replace_script( array $matches ): string {
		$element = $matches[0];
		$tag     = '<script' . $matches[1] . '>';
		$content = $matches[2];
		$type    = strtolower( trim( (string) Html_Tag::get_attribute( $tag, 'type' ) ) );
		$src     = Html_Tag::get_attribute( $tag, 'src' );

		if ( '' !== $type && ! in_array( $type, Html_Tag::JS_TYPES, true ) ) {
			return $element;
		}

		if ( ( null === $src || '' === trim( $src ) ) && '' === trim( $content ) ) {
			return $element;
		}

		if ( $this->is_excluded( $element ) || ( $this->keep_jquery && null !== $src && 1 === preg_match( self::JQUERY_FILE, $src ) ) ) {
			return $element;
		}

		$new = Html_Tag::remove_attribute( $tag, 'type' );

		if ( '' !== $type ) {
			$new = Html_Tag::set_attribute( $new, 'data-o2r-type', $type );
		}

		if ( null !== $src ) {
			$new = Html_Tag::rename_attribute( $new, 'src', 'data-o2r-src' );
		}

		$new = (string) preg_replace( '#^<script\b#i', '<script type="' . self::DELAYED_TYPE . '"', $new, 1 );

		++$this->delayed;

		return $new . $content . '</script>';
	}

	// Script que roda na hora (excluído do atraso, módulo ES ou atributo onclick/onchange...) que chama o jQuery: o jQuery
	// também precisa rodar na hora, senão "jQuery is not defined" e o menu, o slider... param. Arquivos locais são lidos
	// (módulos com os imports). Filtro o2_rocket_delay_js_keep_jquery.
	private function needs_jquery_now( string $html ): bool {
		$needs = 1 === preg_match( '#\son[a-z]+\s*=\s*(?:"[^"]*\bjQuery\b[^"]*"|\'[^\']*\bjQuery\b[^\']*\')#i', $html );

		if ( ! $needs && preg_match_all( self::SCRIPT_REGEX, $html, $scripts, PREG_SET_ORDER ) ) {
			foreach ( $scripts as $script ) {
				$tag    = '<script' . $script[1] . '>';
				$type   = strtolower( trim( (string) Html_Tag::get_attribute( $tag, 'type' ) ) );
				$src    = trim( (string) Html_Tag::get_attribute( $tag, 'src' ) );
				$module = 'module' === $type;

				// Módulos nunca são atrasados; os demais só contam se ficarem fora do atraso.
				if ( ( ! $module && '' !== $type && ! in_array( $type, Html_Tag::JS_TYPES, true ) ) || ( '' !== $src && 1 === preg_match( self::JQUERY_FILE, $src ) ) || ( ! $module && ! $this->is_excluded( $script[0] ) ) ) {
					continue;
				}

				if ( 1 === preg_match( self::USES_JQUERY, $script[2] ) || ( '' !== $src && $this->local_file_uses_jquery( html_entity_decode( $src ), $module, 0 ) ) ) {
					$needs = true;
					break;
				}
			}
		}

		return (bool) apply_filters( 'o2_rocket_delay_js_keep_jquery', $needs, $html );
	}

	// Lê o arquivo local (até 512 KB) e, em módulos, os imports relativos (até 4 níveis).
	private function local_file_uses_jquery( string $url, bool $module, int $depth ): bool {
		$path = Url_Resolver::is_local( $url ) ? Url_Resolver::to_path( $url ) : '';

		if ( '' === $path || ! is_file( $path ) || $depth > 4 ) {
			return false;
		}

		if ( isset( $this->file_uses_jquery[ $path ] ) ) {
			return $this->file_uses_jquery[ $path ];
		}

		$this->file_uses_jquery[ $path ] = false;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- arquivo local do site.
		$code = (string) file_get_contents( $path, false, null, 0, 512 * KB_IN_BYTES );
		$uses = 1 === preg_match( self::USES_JQUERY, $code );

		if ( ! $uses && $module && preg_match_all( '#\bimport\s*(?:[^"\'();]*?\bfrom\s*)?["\'](\.{1,2}/[^"\']+)["\']#', $code, $imports ) ) {
			$base = substr( $url, 0, (int) strrpos( strtok( $url, '?#' ), '/' ) + 1 );

			foreach ( array_unique( $imports[1] ) as $import ) {
				if ( $this->local_file_uses_jquery( \WP_Http::make_absolute_url( $import, $base ), true, $depth + 1 ) ) {
					$uses = true;
					break;
				}
			}
		}

		$this->file_uses_jquery[ $path ] = $uses;

		return $uses;
	}

	public function is_excluded( string $element ): bool {
		foreach ( $this->get_exclusion_regexes() as $regex ) {
			if ( 1 === preg_match( $regex, $element ) ) {
				return true;
			}
		}

		return false;
	}

	// Itens da lista de serviços (Google Analytics, AdSense, reCAPTCHA...) para o painel.
	public function get_service_choices(): array {
		$choices = array();

		foreach ( $this->get_data()['scripts'] as $id => $item ) {
			$choices[ (string) $id ] = $item['category'] . ': ' . $item['title'];
		}

		asort( $choices );

		return $choices;
	}

	// Plugins e tema ativos que têm exclusões conhecidas (mostrados no painel).
	public function get_active_items(): array {
		$active_plugins = $this->get_active_plugins();
		$themes         = $this->get_active_themes();
		$items          = array();

		foreach ( $this->get_data()['plugins'] as $item ) {
			if ( in_array( $item['condition'], $active_plugins, true ) ) {
				$items[] = $item;
			}
		}

		foreach ( $this->get_data()['themes'] as $item ) {
			if ( in_array( strtolower( $item['condition'] ), $themes, true ) ) {
				$items[] = $item;
			}
		}

		return $items;
	}

	public static function sanitize_patterns( $value ): array {
		$lines    = is_array( $value ) ? $value : preg_split( '/\r\n|\r|\n/', is_scalar( $value ) ? (string) $value : '' );
		$patterns = array();

		foreach ( (array) $lines as $line ) {
			$line = is_scalar( $line ) ? trim( wp_strip_all_tags( (string) $line ) ) : '';

			if ( strlen( $line ) >= 3 ) {
				$patterns[] = substr( $line, 0, 300 );
			}
		}

		return array_slice( array_values( array_unique( $patterns ) ), 0, 500 );
	}

	private function get_exclusion_regexes(): array {
		if ( null !== $this->regexes ) {
			return $this->regexes;
		}

		$settings = $this->get_settings();
		$data     = $this->get_data();
		$patterns = array_merge( $data['defaults'], self::OWN_PATTERNS );

		if ( rest_sanitize_boolean( $settings[ self::SETTING_AUTO ] ) ) {
			foreach ( $this->get_active_items() as $item ) {
				$patterns = array_merge( $patterns, $item['exclusions'] );
			}
		}

		foreach ( (array) $settings[ self::SETTING_SERVICES ] as $id ) {
			if ( isset( $data['scripts'][ $id ] ) ) {
				$patterns = array_merge( $patterns, $data['scripts'][ $id ]['exclusions'] );
			}
		}

		if ( rest_sanitize_boolean( $settings[ self::SETTING_SAFE_MODE ] ) ) {
			$patterns = array_merge( $patterns, self::SAFE_MODE_PATTERNS );
		}

		$patterns = array_merge( $patterns, self::sanitize_patterns( $settings[ self::SETTING_EXCLUSIONS ] ) );
		$patterns = (array) apply_filters( 'o2_rocket_delay_js_exclusions', array_values( array_unique( $patterns ) ) );
		$compiled = array();

		foreach ( $patterns as $pattern ) {
			$pattern = (string) $pattern;

			if ( '' === trim( $pattern ) ) {
				continue;
			}

			// Regex válida ou, se não for, texto literal ("#" é o delimitador).
			$regex      = str_replace( '#', '\#', str_replace( '\#', '#', $pattern ) );
			$compiled[] = false === @preg_match( '#' . $regex . '#i', '' ) ? preg_quote( $pattern, '#' ) : $regex; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$this->regexes = array();

		foreach ( array_chunk( $compiled, self::REGEX_CHUNK ) as $chunk ) {
			$this->regexes[] = '#(?:' . implode( ')|(?:', $chunk ) . ')#i';
		}

		return $this->regexes;
	}

	private function get_data(): array {
		if ( null === $this->data ) {
			$data       = include O2_ROCKET_PATH . self::DATA_FILE;
			$this->data = wp_parse_args(
				is_array( $data ) ? $data : array(),
				array(
					'defaults' => array(),
					'plugins'  => array(),
					'themes'   => array(),
					'scripts'  => array(),
				)
			);
		}

		return $this->data;
	}

	private function get_active_plugins(): array {
		$plugins = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$plugins = array_merge( $plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		return array_values( array_map( 'strval', $plugins ) );
	}

	private function get_active_themes(): array {
		$theme = wp_get_theme();

		return array_values( array_unique( array_map( 'strtolower', array( get_template(), get_stylesheet(), (string) $theme->get( 'Name' ) ) ) ) );
	}

	// Sliders e animações que o tema esconde até o JavaScript iniciar ficariam em branco até a primeira interação (e o
	// PageSpeed acusa NO_LCP): o primeiro slide e os elementos do AOS aparecem já. Quando a biblioteca inicia, a classe
	// dela (swiper-initialized, slick-initialized, owl-loaded, aos-init) desliga estas regras e ela assume. O :not(#...)
	// dá peso de id aos seletores para vencer regras do tema como ".page-home .swiper-banner .swiper-slide{opacity:0}".
	private function get_fallback_css( string $html ): string {
		$id    = ':not(#o2r-delay-fallback)';
		$shown = '{opacity:1!important;visibility:visible!important}';
		$rules = array();

		if ( false !== stripos( $html, 'swiper' ) ) {
			$swiper  = ':is(.swiper,.swiper-container):not(.swiper-initialized):not(.swiper-container-initialized)';
			$rules[] = $swiper . $id . ',' . $swiper . ' .swiper-wrapper' . $id . $shown;
			$rules[] = $swiper . ' .swiper-slide:first-child' . $id . '{opacity:1!important;visibility:visible!important;transform:none!important}';
			// Regra à parte (navegador sem :has ignora só ela): enquanto o Swiper inicia, ele marca "initialized" antes
			// de marcar o slide ativo; sem isto o tema esconde o slide nesse intervalo e ele pisca.
			$rules[] = ':is(.swiper,.swiper-container):not(:has(.swiper-slide-active)) .swiper-slide:first-child' . $id . '{opacity:1!important;visibility:visible!important}';
		}

		if ( false !== stripos( $html, 'slick' ) ) {
			$slick   = ':is([data-slick],.slick-slider):not(.slick-initialized)';
			$rules[] = $slick . $id . ',' . $slick . '>:first-child' . $id . $shown;
			$rules[] = $slick . '>:not(:first-child)' . $id . '{display:none!important}';
		}

		if ( false !== stripos( $html, 'owl-carousel' ) ) {
			$owl     = '.owl-carousel:not(.owl-loaded)';
			$rules[] = $owl . $id . '{display:block!important;opacity:1!important;visibility:visible!important}';
			$rules[] = $owl . '>:not(:first-child)' . $id . '{display:none!important}';
		}

		if ( false !== stripos( $html, 'data-aos' ) ) {
			$rules[] = '[data-aos]:not(.aos-init)' . $id . '{opacity:1!important;transform:none!important}';
		}

		return implode( '', (array) apply_filters( 'o2_rocket_delay_js_fallback_css', $rules, $html ) );
	}

	private function insert_loader( string $html ): string {
		$timeout = max( 0, min( 60, (int) $this->get_settings()[ self::SETTING_TIMEOUT ] ) );
		$script  = '<script id="o2r-delay-loader" data-o2r-nodelay>window.o2rDelay=' . wp_json_encode( array( 'timeout' => $timeout ) ) . ';' . inline_script( self::LOADER_FILE ) . '</script>';

		// Animações de entrada do Elementor esperariam o JS: as visíveis na tela aparecem já.
		if ( false !== strpos( $html, 'elementor-invisible' ) ) {
			$script .= '<script id="o2r-elementor-animations" data-o2r-nodelay>' . inline_script( self::ELEMENTOR_FILE ) . '</script>';
		}

		$css = $this->get_fallback_css( $html );

		if ( '' !== $css ) {
			$script .= '<style id="o2r-delay-fallback">' . $css . '</style>';
		}

		// AOS: o que já está na tela fica marcado como animado, para não sumir e reaparecer quando o AOS iniciar.
		if ( false !== stripos( $html, 'data-aos' ) ) {
			$script .= '<script id="o2r-aos-visible" data-o2r-nodelay>' . inline_script( self::AOS_FILE ) . '</script>';
		}

		$position = stripos( $html, '</head>' );

		if ( false === $position ) {
			return $html . $script;
		}

		return substr_replace( $html, $script, $position, 0 );
	}
}
