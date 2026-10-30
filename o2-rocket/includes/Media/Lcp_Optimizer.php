<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Media;

use O2Rocket\Cache\Cache_Engine;
use O2Rocket\Cache\Page_Cache;
use O2Rocket\Core\Url_Resolver;
use O2Rocket\Optimization\Html_Pipeline;
use O2Rocket\Optimization\Html_Tag;

use function O2Rocket\inline_script;

defined( 'ABSPATH' ) || exit;

// Otimização da imagem principal (LCP) medida no navegador dos visitantes, sem serviço externo:
// 1) páginas sem medição levam um beacon de ~2 KB que informa a imagem LCP e as imagens acima da dobra;
// 2) com a medição, a página ganha <link rel="preload" fetchpriority="high"> da LCP, fetchpriority="high" no <img>
//    e essas imagens saem do lazy load. O cache da URL é limpo assim que a medição chega.
final class Lcp_Optimizer {

	const SETTING_ENABLED = 'media_lcp_optimization';

	const AJAX_ACTION       = 'o2_rocket_beacon';
	const PIPELINE_PRIORITY = 80;
	const BEACON_FILE       = 'assets/js/beacon';
	const RATE_TRANSIENT    = 'o2_rocket_beacon_rate';
	const RATE_LIMIT        = 120;
	const MAX_PAYLOAD       = 20000;
	const MAX_ABOVE         = 20;

	const IMAGE_TYPES = array( 'img', 'img-srcset', 'picture' );

	private Lcp_Store $store;

	private Page_Cache $page_cache;

	private bool $saving = false;

	private ?array $measurements = null;

	public function __construct( Lcp_Store $store, Page_Cache $page_cache ) {
		$this->store      = $store;
		$this->page_cache = $page_cache;
	}

	public function register_hooks(): void {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_beacon' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'handle_beacon' ) );
		add_action( 'admin_init', array( $this, 'maybe_install' ) );
		add_action( 'o2_rocket_after_purge_all', array( $this, 'clear' ) );
		add_action( 'o2_rocket_after_purge_urls', array( $this, 'on_purge_urls' ) );

		if ( is_admin() || ! $this->is_enabled() ) {
			return;
		}

		add_filter( 'o2_rocket_lazyload_eager_urls', array( $this, 'filter_eager_urls' ) );
		add_filter( Html_Pipeline::FILTER, array( $this, 'optimize' ), self::PIPELINE_PRIORITY );
	}

	public function is_enabled(): bool {
		$stored = get_option( O2_ROCKET_OPTION, array() );

		return is_array( $stored ) && ! empty( $stored[ self::SETTING_ENABLED ] );
	}

	public function maybe_install(): void {
		if ( $this->is_enabled() && ! $this->store->is_installed() ) {
			$this->store->install();
		}
	}

	// O layout pode ter mudado (tema, menus, configurações): mede de novo.
	public function clear(): void {
		$this->store->clear();
	}

	// Conteúdo da página mudou: a imagem principal pode ser outra. A limpeza pedida pelo próprio beacon não conta.
	public function on_purge_urls( $urls ): void {
		if ( ! $this->saving && is_array( $urls ) ) {
			$this->store->delete_urls( array_filter( $urls, 'is_string' ) );
		}
	}

	public function filter_eager_urls( $urls ) {
		$measurements = $this->get_measurements();

		if ( empty( $measurements ) || ! Html_Pipeline::can_optimize() ) {
			return $urls;
		}

		$eager = is_array( $urls ) ? $urls : array();

		foreach ( $measurements as $measurement ) {
			$eager = array_merge( $eager, $measurement['above_fold'] );

			if ( ! empty( $measurement['lcp']['src'] ) ) {
				$eager[] = (string) $measurement['lcp']['src'];
			}

			if ( ! empty( $measurement['lcp']['current'] ) ) {
				$eager[] = (string) $measurement['lcp']['current'];
			}
		}

		return array_values( array_unique( array_filter( $eager, 'is_string' ) ) );
	}

	public function optimize( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}

		$measurements = $this->get_measurements();
		$mode         = $this->get_mode();

		// Sem cache separado para celular, a mesma página serve os dois: mede até ter os dois dispositivos.
		if ( empty( $measurements ) || ( 'auto' === $mode && count( $measurements ) < 2 ) ) {
			$html = $this->maybe_add_beacon( $html );
		}

		return empty( $measurements ) ? $html : $this->apply_hints( $html, $measurements );
	}

	public function handle_beacon(): void {
		if ( ! $this->is_enabled() ) {
			wp_send_json_error( null, 403 );
		}

		$count = (int) get_transient( self::RATE_TRANSIENT );

		if ( $count >= self::RATE_LIMIT ) {
			wp_send_json_error( null, 429 );
		}

		set_transient( self::RATE_TRANSIENT, $count + 1, 10 * MINUTE_IN_SECONDS );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- páginas em cache não têm nonce válido; a autenticidade vem da assinatura HMAC da URL.
		$url    = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$mode   = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		$key    = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		$device = isset( $_POST['device'] ) && 'mobile' === sanitize_key( wp_unslash( $_POST['device'] ) ) ? 'mobile' : 'desktop';
		$raw    = isset( $_POST['data'] ) ? (string) wp_unslash( $_POST['data'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON validado campo a campo abaixo.
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $url || ! in_array( $mode, array( 'auto', 'mobile', 'desktop' ), true ) || ! hash_equals( self::sign( $url, $mode ), $key ) || strlen( $raw ) > self::MAX_PAYLOAD ) {
			wp_send_json_error( null, 400 );
		}

		$device = 'auto' === $mode ? $device : $mode;

		if ( $this->store->exists( $url, $device ) ) {
			wp_send_json_success( array( 'stored' => false ) );
		}

		$data  = json_decode( $raw, true );
		$lcp   = is_array( $data ) && isset( $data['lcp'] ) && is_array( $data['lcp'] ) ? $this->sanitize_lcp( $data['lcp'] ) : array( 'type' => 'none' );
		$above = is_array( $data ) && isset( $data['above'] ) && is_array( $data['above'] ) ? $this->sanitize_urls( $data['above'] ) : array();

		if ( ! $this->is_plausible( $url, $device, $lcp ) ) {
			wp_send_json_error( null, 422 );
		}

		if ( ! $this->store->add( $url, $device, $lcp, $above ) ) {
			wp_send_json_error( null, 500 );
		}

		// A próxima geração da página já sai com o preload e sem lazy load acima da dobra.
		$this->saving = true;
		$this->page_cache->purge_url( $url );
		$this->saving = false;

		wp_send_json_success( array( 'stored' => true ) );
	}

	public static function sign( string $url, string $mode ): string {
		return substr( hash_hmac( 'sha256', 'o2r-beacon|' . untrailingslashit( $url ) . '|' . $mode, wp_salt( 'nonce' ) ), 0, 32 );
	}

	// Medições que valem para esta resposta: o dispositivo da variante (cache separado) ou todos.
	private function get_measurements(): array {
		if ( null !== $this->measurements ) {
			return $this->measurements;
		}

		$this->measurements = array();
		$url                = $this->get_page_url();

		if ( '' === $url ) {
			return $this->measurements;
		}

		$rows = $this->store->get( $url );
		$mode = $this->get_mode();

		$this->measurements = 'auto' === $mode ? $rows : ( isset( $rows[ $mode ] ) ? array( $mode => $rows[ $mode ] ) : array() );

		return $this->measurements;
	}

	private function get_mode(): string {
		if ( ! $this->page_cache->is_mobile_cache_enabled() ) {
			return 'auto';
		}

		return Cache_Engine::is_mobile( wp_unslash( $_SERVER ) ) ? 'mobile' : 'desktop'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	// URL limpa da página atual (host da home + caminho), só para GET.
	private function get_page_url(): string {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path   = (string) wp_parse_url( $uri, PHP_URL_PATH );

		if ( 'GET' !== $method || '' === $path || '/' !== $path[0] ) {
			return '';
		}

		return (string) preg_replace( '#^(https?://[^/]+).*$#i', '$1', home_url() ) . $path;
	}

	private function maybe_add_beacon( string $html ): string {
		$url = $this->get_page_url();

		if ( '' === $url || ! Html_Pipeline::is_visitor() || 200 !== http_response_code() || is_404() || is_search() || ! (bool) apply_filters( 'o2_rocket_lcp_beacon', true, $url ) ) {
			return $html;
		}

		$mode   = $this->get_mode();
		$config = array(
			'ajax' => admin_url( 'admin-ajax.php' ),
			'url'  => untrailingslashit( $url ),
			'mode' => $mode,
			'key'  => self::sign( $url, $mode ),
		);

		$script   = '<script id="o2r-beacon" data-o2r-nodelay>window.o2rBeacon=' . wp_json_encode( $config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ) . ';' . inline_script( self::BEACON_FILE ) . '</script>';
		$position = strripos( $html, '</body>' );

		return false === $position ? $html . $script : substr_replace( $html, $script, $position, 0 );
	}

	private function apply_hints( string $html, array $measurements ): string {
		$candidates = array();

		foreach ( $measurements as $measurement ) {
			$lcp = $measurement['lcp'];

			if ( ! empty( $lcp['src'] ) && ( in_array( $lcp['type'], self::IMAGE_TYPES, true ) || 'bg-img' === $lcp['type'] ) ) {
				$candidates[ Lazy_Load::normalize_url( (string) $lcp['src'] ) ] = $lcp;
			}
		}

		if ( empty( $candidates ) ) {
			return $html;
		}

		$preload = '';

		foreach ( $candidates as $lcp ) {
			$tag = in_array( $lcp['type'], self::IMAGE_TYPES, true ) ? $this->find_image_tag( $html, $lcp ) : '';

			if ( '' !== $tag ) {
				$html = str_replace( $tag, $this->prioritize( $tag ), $html );
			}

			// Preload só quando todos os dispositivos concordam (senão um deles baixaria uma imagem que não usa).
			if ( 1 === count( $candidates ) && 'picture' !== $lcp['type'] ) {
				$preload = $this->build_preload( $lcp, $tag );
			}
		}

		if ( '' === $preload || false !== strpos( $html, 'data-o2r-lcp-preload' ) ) {
			return $html;
		}

		// Logo depois do <title> (ou no início do <head>): o navegador descobre a imagem antes do CSS.
		if ( preg_match( '#</title\s*>#i', $html, $match, PREG_OFFSET_CAPTURE ) ) {
			return substr_replace( $html, $preload, $match[0][1] + strlen( $match[0][0] ), 0 );
		}

		if ( preg_match( '#<head\b[^>]*>#i', $html, $match, PREG_OFFSET_CAPTURE ) ) {
			return substr_replace( $html, $preload, $match[0][1] + strlen( $match[0][0] ), 0 );
		}

		return $html;
	}

	// Primeiro <img> cujo src ou srcset é a imagem LCP medida (a etapa de WebP já pode ter trocado a extensão).
	private function find_image_tag( string $html, array $lcp ): string {
		$wanted = array_filter(
			array(
				Lazy_Load::normalize_url( (string) $lcp['src'] ),
				isset( $lcp['current'] ) ? Lazy_Load::normalize_url( (string) $lcp['current'] ) : '',
			)
		);

		if ( ! preg_match_all( Html_Tag::opening_pattern( 'img' ), $html, $matches ) ) {
			return '';
		}

		foreach ( $matches[0] as $tag ) {
			$urls = array( (string) Html_Tag::get_attribute( $tag, 'src' ) );

			foreach ( explode( ',', html_entity_decode( (string) Html_Tag::get_attribute( $tag, 'srcset' ), ENT_QUOTES ) ) as $candidate ) {
				$urls[] = trim( (string) preg_replace( '/\s+\S+$/', '', trim( $candidate ) ) );
			}

			foreach ( $urls as $url ) {
				if ( '' !== $url && in_array( Lazy_Load::normalize_url( $url ), $wanted, true ) ) {
					return $tag;
				}
			}
		}

		return '';
	}

	private function prioritize( string $tag ): string {
		if ( Html_Tag::has_class( $tag, Lazy_Load::CSS_CLASS ) ) {
			return $tag;
		}

		$tag = Html_Tag::set_attribute( $tag, 'fetchpriority', 'high' );

		if ( 'lazy' === strtolower( (string) Html_Tag::get_attribute( $tag, 'loading' ) ) ) {
			$tag = Html_Tag::remove_attribute( $tag, 'loading' );
		}

		return $tag;
	}

	private function build_preload( array $lcp, string $tag ): string {
		$attributes = array(
			'rel'                  => 'preload',
			'as'                   => 'image',
			'fetchpriority'        => 'high',
			'data-o2r-lcp-preload' => '',
		);

		if ( '' !== $tag && ! Html_Tag::has_class( $tag, Lazy_Load::CSS_CLASS ) ) {
			$attributes['href'] = html_entity_decode( (string) Html_Tag::get_attribute( $tag, 'src' ), ENT_QUOTES );
			$srcset             = Html_Tag::get_attribute( $tag, 'srcset' );

			if ( null !== $srcset && '' !== trim( $srcset ) ) {
				$attributes['imagesrcset'] = html_entity_decode( $srcset, ENT_QUOTES );
				$attributes['imagesizes']  = html_entity_decode( (string) Html_Tag::get_attribute( $tag, 'sizes' ), ENT_QUOTES );
			}
		} elseif ( 'bg-img' === $lcp['type'] ) {
			$attributes['href'] = (string) $lcp['src'];
		}

		if ( empty( $attributes['href'] ) || 0 === stripos( $attributes['href'], 'data:' ) ) {
			return '';
		}

		$html = '<link';

		foreach ( $attributes as $name => $value ) {
			$html .= '' === $value && 'data-o2r-lcp-preload' === $name ? ' ' . $name : ' ' . $name . '="' . esc_attr( $value ) . '"';
		}

		return $html . '>';
	}

	private function sanitize_lcp( array $lcp ): array {
		$type = isset( $lcp['type'] ) && is_string( $lcp['type'] ) ? sanitize_key( $lcp['type'] ) : 'none';

		if ( ! in_array( $type, array_merge( self::IMAGE_TYPES, array( 'bg-img', 'text', 'none' ) ), true ) ) {
			$type = 'none';
		}

		$clean = array( 'type' => $type );

		foreach ( array( 'src', 'current' ) as $key ) {
			$url = isset( $lcp[ $key ] ) && is_string( $lcp[ $key ] ) ? self::sanitize_url( $lcp[ $key ] ) : '';

			if ( '' !== $url ) {
				$clean[ $key ] = $url;
			}
		}

		if ( 'img-srcset' === $type ) {
			$clean['srcset'] = isset( $lcp['srcset'] ) && is_string( $lcp['srcset'] ) ? substr( sanitize_text_field( $lcp['srcset'] ), 0, 4000 ) : '';
			$clean['sizes']  = isset( $lcp['sizes'] ) && is_string( $lcp['sizes'] ) ? substr( sanitize_text_field( $lcp['sizes'] ), 0, 500 ) : '';
		}

		if ( empty( $clean['src'] ) && 'text' !== $type ) {
			$clean['type'] = 'none';
		}

		return $clean;
	}

	private function sanitize_urls( array $urls ): array {
		$clean = array();

		foreach ( array_slice( $urls, 0, self::MAX_ABOVE ) as $url ) {
			$url = is_string( $url ) ? self::sanitize_url( $url ) : '';

			if ( '' !== $url ) {
				$clean[] = $url;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	private static function sanitize_url( string $url ): string {
		$url = trim( $url );

		if ( strlen( $url ) > 2048 || ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}

		return esc_url_raw( $url, array( 'http', 'https' ) );
	}

	// A página precisa ser deste site e, se já houver cópia em cache, a imagem informada precisa estar nela.
	private function is_plausible( string $url, string $device, array $lcp ): bool {
		if ( ! Url_Resolver::is_local( $url ) ) {
			return false;
		}

		if ( ! in_array( $lcp['type'], self::IMAGE_TYPES, true ) || empty( $lcp['src'] ) ) {
			return true;
		}

		$file = $this->page_cache->get_url_cache_file( $url . ( '/' === substr( (string) get_option( 'permalink_structure' ), -1 ) ? '/' : '' ), 'mobile' === $device );

		if ( '' === $file || ! is_file( $file ) ) {
			return true;
		}

		$html = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$path = (string) wp_parse_url( (string) $lcp['src'], PHP_URL_PATH );
		$base = (string) preg_replace( '#\.(?:webp|avif)$#i', '', $path );

		return '' === $base || false !== strpos( $html, $base ) || false !== strpos( $html, str_replace( '/', '\/', $base ) );
	}
}
