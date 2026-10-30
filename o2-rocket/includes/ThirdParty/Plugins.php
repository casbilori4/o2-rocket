<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\ThirdParty;

use O2Rocket\Cache\Page_Cache;

defined( 'ABSPATH' ) || exit;

// Compatibilidade com plugins: limpezas que o WordPress não avisa (estoque, templates de construtores, CSS regenerado),
// traduções, Varnish HTTP Purge e aviso de plugins que fazem o mesmo trabalho.
final class Plugins {

	// Plugins que também fazem cache de página ou otimizam os mesmos arquivos: o nome mostrado vem do próprio plugin.
	const OVERLAPPING = array(
		'w3-total-cache/w3-total-cache.php',
		'wp-super-cache/wp-cache.php',
		'litespeed-cache/litespeed-cache.php',
		'wp-fastest-cache/wpFastestCache.php',
		'cache-enabler/cache-enabler.php',
		'comet-cache/comet-cache.php',
		'hummingbird-performance/wp-hummingbird.php',
		'breeze/breeze.php',
		'swift-performance-lite/performance.php',
		'swift-performance/performance.php',
		'sg-cachepress/sg-cachepress.php',
		'nitropack/main.php',
		'autoptimize/autoptimize.php',
		'wp-optimize/wp-optimize.php',
		'perfmatters/perfmatters.php',
		'flying-scripts/flying-scripts.php',
		'wp-meteor/wp-meteor.php',
		'a3-lazy-load/a3-lazy-load.php',
	);

	// Opções de temas/construtores que mudam o HTML de todas as páginas.
	const PURGE_ALL_OPTIONS = array(
		'elementor_active_kit',
		'_elementor_global_css',
		'elementor_custom_icon_sets_config',
		'et_divi',
		'fusion_options',
		'avada_theme_options',
		'astra-settings',
		'generate_settings',
		'flatsome_options',
		'oceanwp_options',
	);

	// Tipos de post usados como partes do layout (cabeçalho, rodapé, blocos globais).
	const LAYOUT_POST_TYPES = array( 'elementor_library', 'et_pb_layout', 'fl-builder-template', 'fusion_template', 'fusion_element', 'wp_template_part', 'wp_template', 'wp_global_styles', 'wp_navigation', 'wp_block' );

	private Page_Cache $page_cache;

	public function __construct( Page_Cache $page_cache ) {
		$this->page_cache = $page_cache;
	}

	public function register_hooks(): void {
		add_action( 'admin_notices', array( $this, 'show_conflicts' ) );
		add_filter( 'o2_rocket_cache_query_params', array( $this, 'add_query_params' ) );
		add_filter( 'o2_rocket_post_purge_urls', array( $this, 'add_translation_urls' ), 10, 2 );
		add_filter( 'o2_rocket_preload_sitemaps', array( $this, 'add_sitemaps' ) );

		// Estoque e variações mudam a página do produto sem passar pelo save_post.
		add_action( 'woocommerce_product_set_stock', array( $this, 'purge_product' ) );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'purge_product' ) );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'purge_product_id' ) );
		add_action( 'woocommerce_variation_set_stock_status', array( $this, 'purge_product_id' ) );
		add_action( 'woocommerce_save_product_variation', array( $this, 'purge_product_id' ) );

		foreach ( array( 'woocommerce_cart_page_id', 'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id', 'woocommerce_default_customer_address' ) as $option ) {
			add_action( 'update_option_' . $option, array( $this, 'on_layout_changed' ) );
		}

		// Construtores: CSS regenerado, templates globais e opções do tema.
		add_action( 'elementor/core/files/clear_cache', array( $this, 'on_layout_changed' ) );
		add_action( 'fl_builder_cache_cleared', array( $this, 'on_layout_changed' ) );
		add_action( 'et_core_page_resource_auto_clear', array( $this, 'on_layout_changed' ) );
		add_action( 'fusion_cache_reset_after', array( $this, 'on_layout_changed' ) );
		add_action( 'save_post', array( $this, 'on_layout_post_saved' ), 10, 2 );

		foreach ( self::PURGE_ALL_OPTIONS as $option ) {
			add_action( 'update_option_' . $option, array( $this, 'on_layout_changed' ) );
		}

		// Varnish HTTP Purge (Proxy Cache Purge): limpa o Varnish dele junto.
		if ( class_exists( 'VarnishPurger' ) ) {
			add_action( 'o2_rocket_after_purge_all', array( $this, 'purge_varnish_http_purge' ) );
			add_action( 'o2_rocket_before_purge_url', array( $this, 'purge_varnish_http_purge_url' ) );
		}
	}

	// Uma limpeza por requisição basta (o construtor costuma disparar vários destes eventos juntos).
	public function on_layout_changed(): void {
		if ( ! did_action( 'o2_rocket_after_purge_all' ) ) {
			$this->page_cache->purge_all();
		}
	}

	public function on_layout_post_saved( $post_id, $post ): void {
		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) || ! in_array( $post->post_status, array( 'publish', 'trash' ), true ) ) {
			return;
		}

		if ( in_array( $post->post_type, (array) apply_filters( 'o2_rocket_layout_post_types', self::LAYOUT_POST_TYPES ), true ) ) {
			$this->on_layout_changed();
		}
	}

	public function purge_product( $product ): void {
		if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			$id = method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
			$this->purge_product_id( $id );
		}
	}

	public function purge_product_id( $product_id ): void {
		$product_id = (int) $product_id;
		$parent     = (int) wp_get_post_parent_id( $product_id );

		$this->page_cache->purge_post( $parent > 0 ? $parent : $product_id );
	}

	public function add_query_params( $params ): array {
		$params = (array) $params;

		// Geolocalização do WooCommerce com cache: o "?v=" identifica o país.
		if ( class_exists( 'WooCommerce' ) && 'geolocation_ajax' === get_option( 'woocommerce_default_customer_address' ) ) {
			$params[] = 'v';
		}

		if ( defined( 'ICL_SITEPRESS_VERSION' ) || function_exists( 'pll_languages_list' ) ) {
			$params[] = 'lang';
		}

		return $params;
	}

	// Traduções do post e a home de cada idioma (WPML, Polylang, TranslatePress).
	public function add_translation_urls( $urls, $post ): array {
		$urls = (array) $urls;

		if ( ! $post instanceof \WP_Post ) {
			return $urls;
		}

		if ( function_exists( 'pll_get_post_translations' ) && function_exists( 'pll_home_url' ) && function_exists( 'pll_languages_list' ) ) {
			foreach ( (array) pll_get_post_translations( $post->ID ) as $translation ) {
				$urls[] = (string) get_permalink( (int) $translation );
			}

			foreach ( (array) pll_languages_list() as $language ) {
				$urls[] = (string) pll_home_url( (string) $language );
			}
		} elseif ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			$type         = 'post_' . $post->post_type;
			$trid         = apply_filters( 'wpml_element_trid', null, $post->ID, $type );
			$translations = $trid ? (array) apply_filters( 'wpml_get_element_translations', null, $trid, $type ) : array();

			foreach ( $translations as $translation ) {
				if ( is_object( $translation ) && ! empty( $translation->element_id ) ) {
					$urls[] = (string) get_permalink( (int) $translation->element_id );
				}
			}

			foreach ( array_keys( (array) apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ) ) as $code ) {
				$urls[] = (string) apply_filters( 'wpml_permalink', home_url( '/' ), $code, true );
			}
			// phpcs:enable
		} elseif ( class_exists( 'TRP_Translate_Press' ) && method_exists( 'TRP_Translate_Press', 'get_trp_instance' ) ) {
			$trp       = \TRP_Translate_Press::get_trp_instance();
			$converter = is_object( $trp ) && method_exists( $trp, 'get_component' ) ? $trp->get_component( 'url_converter' ) : null;
			$settings  = get_option( 'trp_settings', array() );
			$languages = is_array( $settings ) && ! empty( $settings['publish-languages'] ) ? (array) $settings['publish-languages'] : array();

			if ( is_object( $converter ) && method_exists( $converter, 'get_url_for_language' ) ) {
				foreach ( $urls as $url ) {
					foreach ( $languages as $language ) {
						$urls[] = (string) $converter->get_url_for_language( (string) $language, $url, '' );
					}
				}
			}
		}

		return array_values( array_unique( array_filter( $urls ) ) );
	}

	public function add_sitemaps( $sitemaps ): array {
		$sitemaps = (array) $sitemaps;

		// Sitemap do Jetpack (módulo "sitemaps" ligado).
		if ( class_exists( 'Jetpack' ) && method_exists( 'Jetpack', 'is_module_active' ) && \Jetpack::is_module_active( 'sitemaps' ) && function_exists( 'jp_sitemap_uri' ) ) {
			$sitemaps[] = (string) jp_sitemap_uri();
		}

		return $sitemaps;
	}

	public function purge_varnish_http_purge(): void {
		$purger = $this->get_varnish_purger();

		if ( null !== $purger ) {
			$this->call_purger( $purger, home_url( '/?vhp-regex' ) );
		}
	}

	public function purge_varnish_http_purge_url( $url ): void {
		$purger = $this->get_varnish_purger();

		if ( null !== $purger ) {
			$this->call_purger( $purger, (string) $url );
		}
	}

	public function show_conflicts(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ( false === strpos( (string) $screen->id, 'o2-rocket' ) && 'plugins' !== $screen->id ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$names = $this->get_active_overlapping();

		if ( empty( $names ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>O² Rocket:</strong> %s</p><p>%s</p></div>',
			esc_html__( 'estes plugins também fazem cache ou otimizam os mesmos arquivos. Usar dois ao mesmo tempo costuma deixar o site mais lento ou quebrar o layout; desative um deles ou desligue as funções repetidas:', 'o2-rocket' ),
			esc_html( implode( ', ', $names ) )
		);
	}

	// Nome de cada plugin ativo da lista, lido do cabeçalho dele.
	public function get_active_overlapping(): array {
		$active = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		$found = array_intersect( (array) apply_filters( 'o2_rocket_overlapping_plugins', self::OVERLAPPING ), $active );

		if ( empty( $found ) ) {
			return array();
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();
		$names   = array();

		foreach ( $found as $basename ) {
			$names[] = ! empty( $plugins[ $basename ]['Name'] ) ? wp_strip_all_tags( (string) $plugins[ $basename ]['Name'] ) : dirname( $basename );
		}

		return array_values( array_unique( $names ) );
	}

	private function get_varnish_purger(): ?object {
		global $purger;

		return class_exists( 'VarnishPurger' ) && is_object( $purger ) ? $purger : null;
	}

	private function call_purger( object $purger, string $url ): void {
		if ( method_exists( $purger, 'purge_url' ) ) {
			$purger->purge_url( $url );
		} elseif ( method_exists( $purger, 'purgeUrl' ) ) {
			$purger->purgeUrl( $url );
		}
	}
}
