<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Optimization;

use O2Rocket\Core\Url_Resolver;
use O2Rocket\Media\Lcp_Optimizer;

use function O2Rocket\atomic_write;
use function O2Rocket\inline_script;

defined( 'ABSPATH' ) || exit;

// Entrega do CSS gerada no próprio servidor (sem serviço externo):
// - "unused": o CSS que a página usa vai em linha e as folhas de estilo saem do carregamento; a cópia completa
//   entra na primeira interação (rede de segurança para classes adicionadas por JavaScript).
// - "critical": só o CSS dos elementos acima da dobra vai em linha (medido pelo beacon local; sem medição, o início
//   do <body>) e as folhas de estilo carregam sem bloquear a renderização.
final class Css_Delivery {

	const SETTING_MODE     = 'css_delivery_mode';
	const SETTING_SAFELIST = 'css_safelist';
	const SETTING_EXCLUDED = 'css_delivery_excluded';
	const SETTING_FALLBACK = 'css_unused_fallback';
	const SETTING_CRITICAL = 'css_critical_manual';

	const MODES             = array( 'off', 'unused', 'critical' );
	const PIPELINE_PRIORITY = 20;
	const LOADER_FILE       = 'assets/js/css-loader';
	const MAX_FILE_BYTES    = 3145728;

	// Classes que scripts costumam acrescentar depois (menus, sliders, modais, animações, estados).
	const DEFAULT_SAFELIST = array(
		'is-*', 'has-*', '*active*', '*open*', '*show*', '*toggle*', '*visible*', '*hidden*', '*current*', '*selected*',
		'*expanded*', '*collaps*', '*sticky*', '*fixed*', '*scroll*', '*loaded*', '*loading*', 'animated', '*animat*',
		'*fade*', '*slide*', '*zoom*', '*bounce*', '*swiper*', '*slick*', 'owl-*', '*splide*', '*glide*', '*flickity*',
		'*lightbox*', 'mfp-*', '*fancybox*', 'pswp*', '*modal*', '*popup*', '*dropdown*', '*menu*', 'e--*', 'e-n-*',
		'elementor-motion-effects*', 'select2*', 'no-js', 'js', 'ready', 'in-view', '*focus*', '*hover*', 'wc-*',
		'woocommerce-*', 'cmplz-*', '*cookie*', '*consent*',
	);

	// Blocos <style> que mudam a cada página/visita (das listas GPL publicadas pela WP Media): ficam como estão.
	const INLINE_EXCLUDED = array(
		'divi-style-parent-inline-inline-css', 'gsf-custom-css', 'extra-style-inline-inline-css', 'woodmart-inline-css-inline-css',
		'woodmart_shortcodes-custom-css', 'rs-plugin-settings-inline-css', 'divi-style-inline-inline-css', 'tcb-post-list-dynamic-style',
		'n2-ss-', 'wpcf7-', 'siteorigin-panels-layouts-footer', 'xstore-inline-css-inline-css', 'assets.reviews.io', 'ezoicCSS', 'stk-',
		'umsMapStyles_', 'trx_addons-inline-styles-inline-css', 'greyd-custom-styles-footer', '#wpv-expandable-', '.custom-content-',
		'#thb-', '.et_pb_text_dap_', '#gdlr-core-shape-divider', '#ultib3-', '.uvc-wrap-', '.jet-listing-dynamic-post-', '.vcex_',
		'.adsslot_', '.jnews_', '.cp-info-bar.content-', '#stockie-custom-', '#ohio-custom-', '#wpfMainWrapper', '#penci_', '#penci-',
		'.wpbs_s', '#apcore_', '#apress_', '#zolo_', '.thegem-', '#thegem-', '.qwery_inline_', '.irs-bar', '.cmplz-hidden',
		'#sqbquizouter', '.flo-header--', '.trx_addons_inline_', '.wpp-cardview-compact', '.tiered-pricing-plain-text',
		'.vc_cta3_content-container', '.vc_btn-gradient-btn-', '.et_pb_de_mach_title_', '.et_pb_dmach_section_', '.ti-widget',
		'o2r-bg-eager', 'o2r-youtube-css', 'o2r-used-css', 'o2r-critical-css', 'data-o2r-nolazy',
	);

	private Lcp_Optimizer $lcp;

	private ?array $settings = null;

	public function __construct( Lcp_Optimizer $lcp ) {
		$this->lcp = $lcp;
	}

	public function register_hooks(): void {
		if ( is_admin() || 'off' === $this->get_mode() ) {
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
					self::SETTING_MODE     => 'off',
					self::SETTING_SAFELIST => array(),
					self::SETTING_EXCLUDED => array(),
					self::SETTING_FALLBACK => true,
					self::SETTING_CRITICAL => '',
				)
			);
		}

		return $this->settings;
	}

	public function get_mode(): string {
		$mode = (string) $this->get_settings()[ self::SETTING_MODE ];

		return in_array( $mode, self::MODES, true ) ? $mode : 'off';
	}

	public function process_html( $html ) {
		if ( ! is_string( $html ) || '' === $html || ! Html_Pipeline::is_visitor() || ! (bool) apply_filters( 'o2_rocket_css_delivery_enabled', true ) ) {
			return $html;
		}

		$head_end = stripos( $html, '</head>' );

		if ( false === $head_end ) {
			return $html;
		}

		return 'critical' === $this->get_mode() ? $this->critical( $html ) : $this->remove_unused( $html );
	}

	// CSS usado em linha; folhas de estilo e <style> processados saem (voltam na primeira interação, se a rede de segurança estiver ligada).
	private function remove_unused( string $html ): string {
		$sources = $this->collect_sources( $html, true );

		if ( empty( $sources ) ) {
			return $html;
		}

		$tokens   = Css_Usage::page_tokens( $html );
		$safelist = array_merge( self::DEFAULT_SAFELIST, $this->get_list( self::SETTING_SAFELIST ) );
		$used     = '';

		foreach ( $sources as $source ) {
			$used .= $this->wrap_media( Css_Parser::serialize( Css_Usage::filter( $source['tree'], $tokens, $safelist ) ), $source['media'] );
		}

		$fallback = rest_sanitize_boolean( $this->get_settings()[ self::SETTING_FALLBACK ] );
		$style    = '<style id="o2r-used-css">' . self::escape_style( $used ) . '</style>';
		$removed  = '';

		foreach ( $sources as $source ) {
			$removed .= $source['html'];
		}

		// Rede de segurança: o CSS completo, em <template>, entra no <head> na primeira interação (ou sem JavaScript, via <noscript>).
		if ( $fallback ) {
			$style .= '<template id="o2r-full-css">' . $removed . '</template>'
				. '<noscript>' . $this->noscript_links( $sources ) . '</noscript>'
				. '<script id="o2r-css-loader" data-o2r-nodelay>' . inline_script( self::LOADER_FILE ) . '</script>';
		}

		return $this->replace_sources( $html, $sources, $style );
	}

	// CSS crítico em linha; <link> passam a carregar sem bloquear (media="print" + onload) com cópia em <noscript>.
	private function critical( string $html ): string {
		$sources = $this->collect_sources( $html, false );

		if ( empty( $sources ) ) {
			return $html;
		}

		$tokens   = $this->get_critical_tokens( $html );
		$critical = '';

		if ( null === $tokens && '' !== trim( (string) $this->get_settings()[ self::SETTING_CRITICAL ] ) ) {
			$critical = (string) $this->get_settings()[ self::SETTING_CRITICAL ];
		} else {
			$tokens = null === $tokens ? $this->estimate_tokens( $html ) : $tokens;

			foreach ( $sources as $source ) {
				$critical .= $this->wrap_media( Css_Parser::serialize( Css_Usage::filter( $source['tree'], $tokens, array() ) ), $source['media'] );
			}
		}

		$replacements = array();

		foreach ( $sources as $index => $source ) {
			$replacements[ $index ] = $this->async_link( $source['html'] );
		}

		$style = '<style id="o2r-critical-css">' . self::escape_style( $critical ) . '</style>';

		return $this->replace_sources( $html, $sources, $style, $replacements );
	}

	// Folhas de estilo locais (e, no modo "unused", blocos <style>) na ordem do documento.
	private function collect_sources( string $html, bool $with_inline ): array {
		$sources = array();
		$pattern = $with_inline
			? '#<(noscript|template|svg)\b.*?</\1\s*>|<style\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>.*?</style\s*>|<link\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>#is'
			: '#<(noscript|template|svg)\b.*?</\1\s*>|<link\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>#is';

		if ( ! preg_match_all( $pattern, $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return array();
		}

		foreach ( $matches as $match ) {
			if ( isset( $match[1] ) && -1 !== $match[1][1] && '' !== $match[1][0] ) {
				continue;
			}

			$element = $match[0][0];
			$source  = 0 === stripos( $element, '<style' ) ? $this->inline_source( $element ) : $this->link_source( $element );

			if ( null !== $source ) {
				$source['html']   = $element;
				$source['offset'] = $match[0][1];
				$sources[]        = $source;
			}
		}

		return $sources;
	}

	private function link_source( string $tag ): ?array {
		$rel   = strtolower( (string) Html_Tag::get_attribute( $tag, 'rel' ) );
		$media = strtolower( trim( html_entity_decode( (string) Html_Tag::get_attribute( $tag, 'media' ), ENT_QUOTES ) ) );
		$href  = Html_Tag::get_attribute( $tag, 'href' );

		if ( ! preg_match( '/(?:^|\s)stylesheet(?:\s|$)/', $rel ) || false !== strpos( $rel, 'alternate' ) || null === $href || Html_Tag::has_attribute( $tag, 'disabled' ) || Html_Tag::has_attribute( $tag, 'data-o2r-nocss' ) ) {
			return null;
		}

		if ( 'print' === $media || $this->is_excluded( $tag ) ) {
			return null;
		}

		$url  = html_entity_decode( trim( $href ), ENT_QUOTES );
		$tree = $this->get_file_tree( $url );

		return null === $tree ? null : array(
			'tree'  => $tree,
			'media' => $media,
		);
	}

	private function inline_source( string $element ): ?array {
		if ( ! preg_match( '#^(<style\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>)(.*?)</style\s*>$#is', $element, $parts ) ) {
			return null;
		}

		$type = strtolower( trim( (string) Html_Tag::get_attribute( $parts[1], 'type' ) ) );

		if ( ( '' !== $type && 'text/css' !== $type ) || '' === trim( $parts[2] ) || $this->is_excluded( $element ) ) {
			return null;
		}

		$media = strtolower( trim( html_entity_decode( (string) Html_Tag::get_attribute( $parts[1], 'media' ), ENT_QUOTES ) ) );

		if ( 'print' === $media ) {
			return null;
		}

		return array(
			'tree'  => Css_Parser::parse( Css_Url_Rewriter::make_absolute( $parts[2], home_url( '/' ) ) ),
			'media' => $media,
		);
	}

	// Árvore do arquivo local, guardada em disco por versão do arquivo (a análise roda uma vez por arquivo).
	private function get_file_tree( string $url ): ?array {
		$file = Url_Resolver::to_path( $url );

		if ( '' === $file || '.css' !== strtolower( substr( $file, -4 ) ) || (int) filesize( $file ) > self::MAX_FILE_BYTES ) {
			return null;
		}

		$key   = substr( md5( $file . '|' . filemtime( $file ) . '|' . filesize( $file ) . '|' . O2_ROCKET_VERSION ), 0, 16 );
		$cache = Asset_Minifier::get_cache_dir() . 'tree-' . $key . '.php';

		if ( is_readable( $cache ) ) {
			$tree = include $cache;

			if ( is_array( $tree ) ) {
				return $tree;
			}
		}

		$css = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( '' === trim( $css ) ) {
			return null;
		}

		$tree = Css_Parser::parse( Css_Url_Rewriter::make_absolute( $css, Url_Resolver::to_absolute( $url ) ) );

		// var_export gera PHP literal: o OPcache guarda a árvore pronta, mais rápido que unserialize/json_decode.
		if ( wp_mkdir_p( Asset_Minifier::get_cache_dir() ) ) {
			atomic_write( $cache, "<?php\ndefined( 'ABSPATH' ) || exit;\n\nreturn " . var_export( $tree, true ) . ";\n" ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		}

		return $tree;
	}

	// Medição do beacon para esta página (tokens dos elementos acima da dobra); null se ainda não há.
	private function get_critical_tokens( string $html ): ?array {
		$tags  = array();
		$words = array();

		foreach ( $this->lcp->get_measurements() as $measurement ) {
			if ( ! empty( $measurement['extras']['tokens']['tags'] ) ) {
				$tags  = array_merge( $tags, (array) $measurement['extras']['tokens']['tags'] );
				$words = array_merge( $words, (array) $measurement['extras']['tokens']['words'] );
			}
		}

		return empty( $tags ) ? null : Css_Usage::tokens_from_lists( $tags, $words );
	}

	// Sem medição: o <head> e o começo do <body> (cabeçalho e primeira seção) como estimativa do que fica acima da dobra.
	private function estimate_tokens( string $html ): array {
		$body_start = (int) stripos( $html, '<body' );
		$body       = substr( $html, $body_start );
		$length     = max( 20000, min( 80000, (int) ( strlen( $body ) * 0.3 ) ) );

		// Sem scripts: classes citadas no JavaScript não estão na tela.
		$visible = (string) preg_replace( '#<script\b.*?</script\s*>#is', '', substr( $html, 0, $body_start ) . substr( $body, 0, $length ) );

		return Css_Usage::page_tokens( $visible );
	}

	private function replace_sources( string $html, array $sources, string $insert, array $replacements = array() ): string {
		// De trás para frente: os offsets das fontes anteriores continuam válidos.
		for ( $index = count( $sources ) - 1; $index >= 0; $index-- ) {
			$source      = $sources[ $index ];
			$replacement = isset( $replacements[ $index ] ) ? $replacements[ $index ] : '';

			if ( 0 === $index ) {
				$replacement = $insert . $replacement;
			}

			$html = substr_replace( $html, $replacement, $source['offset'], strlen( $source['html'] ) );
		}

		return $html;
	}

	private function async_link( string $tag ): string {
		$media = Html_Tag::get_attribute( $tag, 'media' );
		$new   = Html_Tag::set_attribute( $tag, 'media', 'print' );
		$new   = Html_Tag::set_attribute( $new, 'data-o2r-media', null === $media || '' === trim( $media ) ? 'all' : html_entity_decode( $media, ENT_QUOTES ) );
		$new   = Html_Tag::set_attribute( $new, 'onload', 'this.onload=null;this.media=this.dataset.o2rMedia' );

		return $new . '<noscript>' . $tag . '</noscript>';
	}

	private function noscript_links( array $sources ): string {
		$links = '';

		foreach ( $sources as $source ) {
			if ( 0 === stripos( $source['html'], '<link' ) ) {
				$links .= $source['html'];
			}
		}

		return $links;
	}

	private function wrap_media( string $css, string $media ): string {
		if ( '' === trim( $css ) ) {
			return '';
		}

		return '' === $media || 'all' === $media ? $css : '@media ' . $media . '{' . $css . '}';
	}

	private function is_excluded( string $element ): bool {
		foreach ( array_merge( self::INLINE_EXCLUDED, $this->get_list( self::SETTING_EXCLUDED ) ) as $pattern ) {
			if ( '' !== $pattern && false !== stripos( $element, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	private function get_list( string $key ): array {
		$value = $this->get_settings()[ $key ];

		return array_values( array_filter( array_map( 'trim', array_map( 'strval', is_array( $value ) ? $value : array() ) ), 'strlen' ) );
	}

	// Dentro de <style> não há escape de HTML: só não pode aparecer "</style".
	private static function escape_style( string $css ): string {
		return str_ireplace( '</style', '<\/style', $css );
	}

	public static function sanitize_mode( $value ): string {
		$value = is_scalar( $value ) ? (string) $value : 'off';

		return in_array( $value, self::MODES, true ) ? $value : 'off';
	}

	public static function sanitize_css( $value ): string {
		$css = is_scalar( $value ) ? (string) $value : '';

		return trim( str_ireplace( '</style', '', wp_strip_all_tags( $css ) ) );
	}
}
