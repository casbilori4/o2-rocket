<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Admin;

use O2Rocket\Addon\Cloudflare;
use O2Rocket\Addon\Sucuri;
use O2Rocket\Addon\Varnish;
use O2Rocket\Cache\Cache_Controls;
use O2Rocket\Cache\Drop_In_Manager;
use O2Rocket\Cache\Page_Cache;
use O2Rocket\Database\Cleaner;
use O2Rocket\Media\Image_Dimensions;
use O2Rocket\Media\Image_Optimizer;
use O2Rocket\Media\Lazy_Load;
use O2Rocket\Media\Lazy_Render;
use O2Rocket\Media\Lcp_Optimizer;
use O2Rocket\Media\Resource_Hints;
use O2Rocket\Optimization\Asset_Minifier;
use O2Rocket\Optimization\Cdn;
use O2Rocket\Optimization\Css_Delivery;
use O2Rocket\Optimization\Delay_Js;
use O2Rocket\Optimization\File_Optimizer;
use O2Rocket\Optimization\Google_Fonts;
use O2Rocket\Optimization\Heartbeat;
use O2Rocket\Preload\Link_Prefetch;
use O2Rocket\Preload\Preloader;
use O2Rocket\Server\Cache_Rewrite;
use O2Rocket\Server\Htaccess;
use O2Rocket\ThirdParty\Hosting;

defined( 'ABSPATH' ) || exit;

final class Settings {

	const TAB_PAGE_CACHE        = 'page-cache';
	const TAB_FILE_OPTIMIZATION = 'file-optimization';
	const TAB_MEDIA             = 'media';
	const TAB_DATABASE          = 'database';
	const TAB_HEARTBEAT         = 'heartbeat';
	const TAB_CDN               = 'cdn';
	const TAB_ADDONS            = 'addons';

	private Drop_In_Manager $drop_in;

	private Preloader $preloader;

	private Htaccess $htaccess;

	private ?array $values = null;

	private ?array $defaults = null;

	private ?Delay_Js $delay_js = null;

	public function __construct( Drop_In_Manager $drop_in, Preloader $preloader, Htaccess $htaccess ) {
		$this->drop_in   = $drop_in;
		$this->preloader = $preloader;
		$this->htaccess  = $htaccess;
	}

	public function register_hooks(): void {
		add_filter( 'o2_rocket_settings_schema', array( $this, 'filter_schema' ) );
		add_action( 'o2_rocket_register_settings', array( $this, 'register_fields' ) );
	}

	public function filter_schema( $schema ): array {
		$schema = is_array( $schema ) ? $schema : array();

		$schema[ Page_Cache::SETTING_ENABLED ] = array(
			'tab'     => self::TAB_PAGE_CACHE,
			'type'    => 'bool',
			'default' => false,
		);

		$schema[ Page_Cache::SETTING_LIFESPAN ] = array(
			'tab'     => self::TAB_PAGE_CACHE,
			'type'    => 'int',
			'default' => Page_Cache::DEFAULT_LIFESPAN,
			'min'     => 0,
			'max'     => 9999,
		);

		$schema[ Page_Cache::SETTING_LIFESPAN_UNIT ] = array(
			'tab'     => self::TAB_PAGE_CACHE,
			'type'    => 'select',
			'default' => Page_Cache::DEFAULT_LIFESPAN_UNIT,
			'choices' => array(
				'minutes' => __( 'minutos', 'o2-rocket' ),
				'hours'   => __( 'horas', 'o2-rocket' ),
				'days'    => __( 'dias', 'o2-rocket' ),
			),
		);

		$schema[ Page_Cache::SETTING_MOBILE ] = array(
			'tab'     => self::TAB_PAGE_CACHE,
			'type'    => 'bool',
			'default' => false,
		);

		$schema[ Page_Cache::SETTING_GZIP ] = array(
			'tab'     => self::TAB_PAGE_CACHE,
			'type'    => 'bool',
			'default' => true,
		);

		$schema[ Page_Cache::SETTING_EXCLUDED_URIS ] = array(
			'tab'               => self::TAB_PAGE_CACHE,
			'type'              => 'lines',
			'default'           => array(),
			'sanitize_callback' => array( Page_Cache::class, 'sanitize_uri_patterns' ),
		);

		$schema[ Page_Cache::SETTING_REJECTED_COOKIES ] = array(
			'tab'               => self::TAB_PAGE_CACHE,
			'type'              => 'lines',
			'default'           => array(),
			'sanitize_callback' => array( Page_Cache::class, 'sanitize_cookie_names' ),
		);

		$schema[ Page_Cache::SETTING_REJECTED_USER_AGENTS ] = array(
			'tab'               => self::TAB_PAGE_CACHE,
			'type'              => 'lines',
			'default'           => array(),
			'sanitize_callback' => array( Page_Cache::class, 'sanitize_user_agents' ),
		);

		$schema[ Page_Cache::SETTING_QUERY_STRINGS ] = array(
			'tab'               => self::TAB_PAGE_CACHE,
			'type'              => 'lines',
			'default'           => array(),
			'sanitize_callback' => array( Page_Cache::class, 'sanitize_query_params' ),
		);

		$schema[ Page_Cache::SETTING_IGNORED_PARAMS ] = array(
			'tab'               => self::TAB_PAGE_CACHE,
			'type'              => 'lines',
			'default'           => array(),
			'sanitize_callback' => array( Page_Cache::class, 'sanitize_query_params' ),
		);

		$schema[ Page_Cache::SETTING_ALWAYS_PURGE ] = array(
			'tab'               => self::TAB_PAGE_CACHE,
			'type'              => 'lines',
			'default'           => array(),
			'sanitize_callback' => array( Page_Cache::class, 'sanitize_purge_urls' ),
		);

		$schema[ Preloader::SETTING_ENABLED ] = array(
			'tab'     => self::TAB_PAGE_CACHE,
			'type'    => 'bool',
			'default' => false,
		);

		$schema[ Link_Prefetch::SETTING_ENABLED ] = array(
			'tab'     => self::TAB_PAGE_CACHE,
			'type'    => 'bool',
			'default' => false,
		);

		$schema[ Preloader::SETTING_EXCLUDED ] = array(
			'tab'               => self::TAB_PAGE_CACHE,
			'type'              => 'lines',
			'default'           => array(),
			'sanitize_callback' => array( Page_Cache::class, 'sanitize_uri_patterns' ),
		);

		$schema[ Preloader::SETTING_SITEMAPS ] = array(
			'tab'               => self::TAB_PAGE_CACHE,
			'type'              => 'lines',
			'default'           => array(),
			'sanitize_callback' => array( Page_Cache::class, 'sanitize_purge_urls' ),
		);

		$schema[ Preloader::SETTING_AFTER_PURGE ] = array(
			'tab'     => self::TAB_PAGE_CACHE,
			'type'    => 'bool',
			'default' => true,
		);

		$schema[ Htaccess::SETTING_GZIP ] = array(
			'tab'     => self::TAB_PAGE_CACHE,
			'type'    => 'bool',
			'default' => true,
		);

		$schema[ Htaccess::SETTING_BROWSER_CACHE ] = array(
			'tab'     => self::TAB_PAGE_CACHE,
			'type'    => 'bool',
			'default' => true,
		);

		$schema[ Cache_Rewrite::SETTING ] = array(
			'tab'     => self::TAB_PAGE_CACHE,
			'type'    => 'bool',
			'default' => false,
		);

		$schema[ File_Optimizer::SETTING_REMOVE_QUERY_STRINGS ] = array(
			'tab'     => self::TAB_FILE_OPTIMIZATION,
			'type'    => 'bool',
			'default' => false,
		);

		$schema[ File_Optimizer::SETTING_DEFER_JS ] = array(
			'tab'     => self::TAB_FILE_OPTIMIZATION,
			'type'    => 'bool',
			'default' => false,
		);

		$schema[ File_Optimizer::SETTING_DEFER_JS_EXCLUDED ] = array(
			'tab'               => self::TAB_FILE_OPTIMIZATION,
			'type'              => 'lines',
			'default'           => array(),
			'sanitize_callback' => array( self::class, 'sanitize_file_patterns' ),
		);

		$schema[ Asset_Minifier::SETTING_CSS ] = array(
			'tab'     => self::TAB_FILE_OPTIMIZATION,
			'type'    => 'bool',
			'default' => false,
		);

		$schema[ Asset_Minifier::SETTING_CSS_EXCLUDED ] = array(
			'tab'               => self::TAB_FILE_OPTIMIZATION,
			'type'              => 'lines',
			'default'           => array(),
			'sanitize_callback' => array( self::class, 'sanitize_file_patterns' ),
		);

		$schema[ Css_Delivery::SETTING_MODE ] = array(
			'tab'               => self::TAB_FILE_OPTIMIZATION,
			'type'              => 'select',
			'default'           => 'off',
			'choices'           => self::get_css_mode_choices(),
			'sanitize_callback' => array( Css_Delivery::class, 'sanitize_mode' ),
		);

		$schema[ Css_Delivery::SETTING_FALLBACK ] = array(
			'tab'     => self::TAB_FILE_OPTIMIZATION,
			'type'    => 'bool',
			'default' => true,
		);

		$schema[ Css_Delivery::SETTING_SAFELIST ] = array(
			'tab'               => self::TAB_FILE_OPTIMIZATION,
			'type'              => 'lines',
			'default'           => array(),
			'sanitize_callback' => array( self::class, 'sanitize_file_patterns' ),
		);

		$schema[ Css_Delivery::SETTING_EXCLUDED ] = array(
			'tab'               => self::TAB_FILE_OPTIMIZATION,
			'type'              => 'lines',
			'default'           => array(),
			'sanitize_callback' => array( self::class, 'sanitize_file_patterns' ),
		);

		$schema[ Css_Delivery::SETTING_CRITICAL ] = array(
			'tab'               => self::TAB_FILE_OPTIMIZATION,
			'type'              => 'textarea',
			'default'           => '',
			'sanitize_callback' => array( Css_Delivery::class, 'sanitize_css' ),
		);

		$schema[ Asset_Minifier::SETTING_JS ] = array(
			'tab'     => self::TAB_FILE_OPTIMIZATION,
			'type'    => 'bool',
			'default' => false,
		);

		$schema[ Asset_Minifier::SETTING_JS_EXCLUDED ] = array(
			'tab'               => self::TAB_FILE_OPTIMIZATION,
			'type'              => 'lines',
			'default'           => array(),
			'sanitize_callback' => array( self::class, 'sanitize_file_patterns' ),
		);

		$schema[ Delay_Js::SETTING_ENABLED ] = array(
			'tab'     => self::TAB_FILE_OPTIMIZATION,
			'type'    => 'bool',
			'default' => false,
		);

		$schema[ Delay_Js::SETTING_AUTO ] = array(
			'tab'     => self::TAB_FILE_OPTIMIZATION,
			'type'    => 'bool',
			'default' => true,
		);

		$schema[ Delay_Js::SETTING_SERVICES ] = array(
			'tab'     => self::TAB_FILE_OPTIMIZATION,
			'type'    => 'multiselect',
			'default' => array(),
			'choices' => $this->get_delay_js()->get_service_choices(),
		);

		$schema[ Delay_Js::SETTING_SAFE_MODE ] = array(
			'tab'     => self::TAB_FILE_OPTIMIZATION,
			'type'    => 'bool',
			'default' => false,
		);

		$schema[ Delay_Js::SETTING_TIMEOUT ] = array(
			'tab'     => self::TAB_FILE_OPTIMIZATION,
			'type'    => 'int',
			'default' => 0,
			'min'     => 0,
			'max'     => 60,
		);

		$schema[ Delay_Js::SETTING_EXCLUSIONS ] = array(
			'tab'               => self::TAB_FILE_OPTIMIZATION,
			'type'              => 'lines',
			'default'           => array(),
			'sanitize_callback' => array( Delay_Js::class, 'sanitize_patterns' ),
		);

		$schema[ File_Optimizer::SETTING_REMOVE_EMOJIS ] = array(
			'tab'     => self::TAB_FILE_OPTIMIZATION,
			'type'    => 'bool',
			'default' => false,
		);

		$schema[ File_Optimizer::SETTING_REMOVE_JQUERY_MIGRATE ] = array(
			'tab'     => self::TAB_FILE_OPTIMIZATION,
			'type'    => 'bool',
			'default' => false,
		);

		$schema[ Lazy_Load::SETTING_IMAGES ] = array(
			'tab'     => self::TAB_MEDIA,
			'type'    => 'bool',
			'default' => false,
		);

		$schema[ Lazy_Load::SETTING_IFRAMES ] = array(
			'tab'     => self::TAB_MEDIA,
			'type'    => 'bool',
			'default' => false,
		);

		$schema[ Lazy_Load::SETTING_YOUTUBE ] = array(
			'tab'     => self::TAB_MEDIA,
			'type'    => 'bool',
			'default' => false,
		);

		$schema[ Lazy_Load::SETTING_CSS_BG ] = array(
			'tab'     => self::TAB_MEDIA,
			'type'    => 'bool',
			'default' => false,
		);

		$schema[ Lazy_Load::SETTING_EXCLUDED ] = array(
			'tab'               => self::TAB_MEDIA,
			'type'              => 'lines',
			'default'           => array(),
			'sanitize_callback' => array( self::class, 'sanitize_file_patterns' ),
		);

		$schema[ Image_Dimensions::SETTING_ENABLED ] = array(
			'tab'     => self::TAB_MEDIA,
			'type'    => 'bool',
			'default' => false,
		);

		$schema[ Lcp_Optimizer::SETTING_ENABLED ] = array(
			'tab'     => self::TAB_MEDIA,
			'type'    => 'bool',
			'default' => false,
		);

		$schema[ Lazy_Render::SETTING ] = array(
			'tab'     => self::TAB_MEDIA,
			'type'    => 'bool',
			'default' => false,
		);

		$schema[ Image_Optimizer::SETTING_QUALITY ] = array(
			'tab'     => self::TAB_MEDIA,
			'type'    => 'int',
			'default' => Image_Optimizer::DEFAULT_QUALITY,
			'min'     => 50,
			'max'     => 100,
		);

		$schema[ Image_Optimizer::SETTING_AUTO ] = array(
			'tab'     => self::TAB_MEDIA,
			'type'    => 'bool',
			'default' => false,
		);

		$schema[ Image_Optimizer::SETTING_SERVE ] = array(
			'tab'     => self::TAB_MEDIA,
			'type'    => 'bool',
			'default' => false,
		);

		$schema[ Google_Fonts::SETTING_LOCAL ] = array(
			'tab'     => self::TAB_MEDIA,
			'type'    => 'bool',
			'default' => false,
		);

		$schema[ Google_Fonts::SETTING_DISPLAY_SWAP ] = array(
			'tab'     => self::TAB_MEDIA,
			'type'    => 'bool',
			'default' => true,
		);

		$schema[ Resource_Hints::SETTING_FONTS_PRELOAD ] = array(
			'tab'               => self::TAB_MEDIA,
			'type'              => 'lines',
			'default'           => array(),
			'sanitize_callback' => array( Resource_Hints::class, 'sanitize_font_urls' ),
		);

		$schema[ Resource_Hints::SETTING_FONTS_AUTO ] = array(
			'tab'     => self::TAB_MEDIA,
			'type'    => 'bool',
			'default' => false,
		);

		$schema[ Resource_Hints::SETTING_PRECONNECT ] = array(
			'tab'               => self::TAB_MEDIA,
			'type'              => 'lines',
			'default'           => array(),
			'sanitize_callback' => array( Resource_Hints::class, 'sanitize_domains' ),
		);

		$schema[ Resource_Hints::SETTING_PRECONNECT_AUTO ] = array(
			'tab'     => self::TAB_MEDIA,
			'type'    => 'bool',
			'default' => false,
		);

		foreach ( Cleaner::ITEMS as $item ) {
			$schema[ $item ] = array(
				'tab'     => self::TAB_DATABASE,
				'type'    => 'bool',
				'default' => false,
			);
		}

		$schema[ Cleaner::SETTING_REVISIONS_KEEP ] = array(
			'tab'     => self::TAB_DATABASE,
			'type'    => 'int',
			'default' => 0,
			'min'     => 0,
			'max'     => 100,
		);

		$schema[ Cleaner::SETTING_SCHEDULE ] = array(
			'tab'     => self::TAB_DATABASE,
			'type'    => 'bool',
			'default' => false,
		);

		$schema[ Cleaner::SETTING_FREQUENCY ] = array(
			'tab'     => self::TAB_DATABASE,
			'type'    => 'select',
			'default' => 'weekly',
			'choices' => self::get_frequency_choices(),
		);

		$schema[ Heartbeat::SETTING_CONTROL ] = array(
			'tab'     => self::TAB_HEARTBEAT,
			'type'    => 'bool',
			'default' => false,
		);

		foreach ( array( Heartbeat::SETTING_SITE => 'disable', Heartbeat::SETTING_ADMIN => 'reduce', Heartbeat::SETTING_EDITOR => 'reduce' ) as $key => $default ) {
			$schema[ $key ] = array(
				'tab'     => self::TAB_HEARTBEAT,
				'type'    => 'select',
				'default' => $default,
				'choices' => self::get_heartbeat_choices(),
			);
		}

		$schema[ Cdn::SETTING_ENABLED ] = array(
			'tab'     => self::TAB_CDN,
			'type'    => 'bool',
			'default' => false,
		);

		$schema[ Cdn::SETTING_URLS ] = array(
			'tab'               => self::TAB_CDN,
			'type'              => 'lines',
			'default'           => array(),
			'sanitize_callback' => array( Cdn::class, 'sanitize_urls' ),
		);

		$schema[ Cdn::SETTING_EXCLUDED ] = array(
			'tab'     => self::TAB_CDN,
			'type'    => 'lines',
			'default' => array(),
		);

		$schema[ Cloudflare::SETTING_ENABLED ] = array(
			'tab'     => self::TAB_ADDONS,
			'type'    => 'bool',
			'default' => false,
		);

		$schema[ Cloudflare::SETTING_TOKEN ] = array(
			'tab'               => self::TAB_ADDONS,
			'type'              => 'text',
			'default'           => '',
			'secret'            => true,
			'sanitize_callback' => array( Cloudflare::class, 'sanitize_token' ),
		);

		$schema[ Cloudflare::SETTING_ZONE ] = array(
			'tab'               => self::TAB_ADDONS,
			'type'              => 'text',
			'default'           => '',
			'sanitize_callback' => array( Cloudflare::class, 'sanitize_zone' ),
		);

		$schema[ Varnish::SETTING_ENABLED ] = array(
			'tab'     => self::TAB_ADDONS,
			'type'    => 'bool',
			'default' => false,
		);

		$schema[ Varnish::SETTING_HOST ] = array(
			'tab'               => self::TAB_ADDONS,
			'type'              => 'text',
			'default'           => '',
			'sanitize_callback' => array( Varnish::class, 'sanitize_host' ),
		);

		$schema[ Sucuri::SETTING_ENABLED ] = array(
			'tab'     => self::TAB_ADDONS,
			'type'    => 'bool',
			'default' => false,
		);

		$schema[ Sucuri::SETTING_KEY ] = array(
			'tab'               => self::TAB_ADDONS,
			'type'              => 'text',
			'default'           => '',
			'secret'            => true,
			'sanitize_callback' => array( Sucuri::class, 'sanitize_key_value' ),
		);

		return $schema;
	}

	public static function get_css_mode_choices(): array {
		return array(
			'off'      => __( 'Desligado', 'o2-rocket' ),
			'unused'   => __( 'Remover CSS não utilizado (recomendado)', 'o2-rocket' ),
			'critical' => __( 'CSS crítico + carregar o restante sem bloquear', 'o2-rocket' ),
		);
	}

	public static function get_frequency_choices(): array {
		return array(
			'daily'   => __( 'Diariamente', 'o2-rocket' ),
			'weekly'  => __( 'Semanalmente', 'o2-rocket' ),
			'monthly' => __( 'Mensalmente', 'o2-rocket' ),
		);
	}

	public static function get_heartbeat_choices(): array {
		return array(
			'default' => __( 'Padrão do WordPress', 'o2-rocket' ),
			'reduce'  => __( 'Reduzir para a cada 2 minutos', 'o2-rocket' ),
			'disable' => __( 'Desativar', 'o2-rocket' ),
		);
	}

	public function register_fields( Admin_UI $ui ): void {
		$this->register_page_cache_fields( $ui );
		$this->register_file_optimization_fields( $ui );
		$this->register_media_fields( $ui );
		$this->register_font_fields( $ui );
		$this->register_database_fields( $ui );
		$this->register_heartbeat_fields( $ui );
		$this->register_cdn_fields( $ui );
		$this->register_addon_fields( $ui );
	}

	private function register_cdn_fields( Admin_UI $ui ): void {
		$page = $ui->get_settings_page( self::TAB_CDN );

		add_settings_section(
			'o2r_cdn',
			__( 'CDN', 'o2-rocket' ),
			array( $this, 'render_cdn_section' ),
			$page
		);

		add_settings_field(
			Cdn::SETTING_ENABLED,
			__( 'Ativar a CDN', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_cdn',
			array(
				'label_for'   => 'o2r-cdn-enabled',
				'key'         => Cdn::SETTING_ENABLED,
				'label'       => __( 'Trocar o endereço dos arquivos estáticos pelo da CDN', 'o2-rocket' ),
				'description' => __( 'Imagens, CSS, JavaScript, fontes e vídeos de wp-content, wp-includes e uploads, em todo o HTML (src, srcset, CSS em linha, atributos data-*). O HTML continua vindo do seu servidor.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Cdn::SETTING_URLS,
			__( 'Endereços da CDN', 'o2-rocket' ),
			array( $this, 'render_textarea' ),
			$page,
			'o2r_cdn',
			array(
				'label_for'   => 'o2r-cdn-urls',
				'key'         => Cdn::SETTING_URLS,
				'rows'        => 4,
				'placeholder' => "https://cdn.exemplo.com\nhttps://img.exemplo.com images",
				'description' => __( 'Um por linha. Depois do endereço, opcionalmente, o tipo de arquivo que ele atende: images, css, js, fonts ou media (sem tipo = todos). Vários endereços do mesmo tipo dividem os arquivos entre si, sempre o mesmo arquivo no mesmo endereço.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Cdn::SETTING_EXCLUDED,
			__( 'Arquivos fora da CDN', 'o2-rocket' ),
			array( $this, 'render_textarea' ),
			$page,
			'o2r_cdn',
			array(
				'label_for'   => 'o2r-cdn-excluded',
				'key'         => Cdn::SETTING_EXCLUDED,
				'rows'        => 4,
				'placeholder' => "/wp-content/plugins/meu-plugin/\n.pdf",
				'description' => __( 'Trechos do caminho, um por linha. Qualquer arquivo cujo caminho contenha um deles continua no seu domínio.', 'o2-rocket' ),
			)
		);
	}

	private function register_addon_fields( Admin_UI $ui ): void {
		$page = $ui->get_settings_page( self::TAB_ADDONS );

		add_settings_section(
			'o2r_cloudflare',
			__( 'Cloudflare', 'o2-rocket' ),
			array( $this, 'render_cloudflare_section' ),
			$page
		);

		add_settings_field(
			Cloudflare::SETTING_ENABLED,
			__( 'Integração com a Cloudflare', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_cloudflare',
			array(
				'label_for'   => 'o2r-cloudflare-enabled',
				'key'         => Cloudflare::SETTING_ENABLED,
				'label'       => __( 'Limpar o cache da Cloudflare junto com o do O² Rocket', 'o2-rocket' ),
				'description' => __( 'Limpeza total quando todo o cache é limpo; só as URLs alteradas quando um post, termo ou comentário muda (em lotes, alguns segundos depois).', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Cloudflare::SETTING_TOKEN,
			__( 'Token da API', 'o2-rocket' ),
			array( $this, 'render_text' ),
			$page,
			'o2r_cloudflare',
			array(
				'label_for'   => 'o2r-cloudflare-token',
				'key'         => Cloudflare::SETTING_TOKEN,
				'input_type'  => 'password',
				'description' => __( 'Crie em Cloudflare → Meu perfil → Tokens de API, com as permissões "Zona → Limpar cache" e, para o modo de desenvolvimento, "Zona → Configurações da zona → Editar". O token não entra na exportação das configurações.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Cloudflare::SETTING_ZONE,
			__( 'ID da zona', 'o2-rocket' ),
			array( $this, 'render_text' ),
			$page,
			'o2r_cloudflare',
			array(
				'label_for'   => 'o2r-cloudflare-zone',
				'key'         => Cloudflare::SETTING_ZONE,
				'description' => __( 'Na Cloudflare, página "Visão geral" do domínio, coluna da direita (32 caracteres).', 'o2-rocket' ),
			)
		);

		add_settings_section(
			'o2r_varnish',
			__( 'Varnish', 'o2-rocket' ),
			array( $this, 'render_varnish_section' ),
			$page
		);

		add_settings_field(
			Varnish::SETTING_ENABLED,
			__( 'Limpar o Varnish', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_varnish',
			array(
				'label_for'   => 'o2r-varnish-enabled',
				'key'         => Varnish::SETTING_ENABLED,
				'label'       => __( 'Enviar PURGE ao Varnish sempre que o cache for limpo', 'o2-rocket' ),
				'description' => __( 'Ligue só se o servidor tem Varnish (ou outro proxy que aceite PURGE) na frente do site. Hospedagens conhecidas são detectadas sozinhas.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Varnish::SETTING_HOST,
			__( 'Endereço do Varnish', 'o2-rocket' ),
			array( $this, 'render_text' ),
			$page,
			'o2r_varnish',
			array(
				'label_for'   => 'o2r-varnish-host',
				'key'         => Varnish::SETTING_HOST,
				'placeholder' => '127.0.0.1:6081',
				'description' => __( 'Opcional. Vazio = o próprio domínio do site.', 'o2-rocket' ),
			)
		);

		add_settings_section(
			'o2r_sucuri',
			__( 'Sucuri', 'o2-rocket' ),
			null,
			$page
		);

		add_settings_field(
			Sucuri::SETTING_ENABLED,
			__( 'Firewall da Sucuri', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_sucuri',
			array(
				'label_for' => 'o2r-sucuri-enabled',
				'key'       => Sucuri::SETTING_ENABLED,
				'label'     => __( 'Limpar o cache do firewall da Sucuri junto com o do O² Rocket', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Sucuri::SETTING_KEY,
			__( 'Chave da API do firewall', 'o2-rocket' ),
			array( $this, 'render_text' ),
			$page,
			'o2r_sucuri',
			array(
				'label_for'   => 'o2r-sucuri-key',
				'key'         => Sucuri::SETTING_KEY,
				'input_type'  => 'password',
				'description' => __( 'No painel da Sucuri: Firewall → Settings → API. Formato: chave/segredo. Não entra na exportação das configurações.', 'o2-rocket' ),
			)
		);
	}

	private function register_media_fields( Admin_UI $ui ): void {
		$page = $ui->get_settings_page( self::TAB_MEDIA );

		add_settings_section(
			'o2r_lazyload',
			__( 'Lazy Load', 'o2-rocket' ),
			array( $this, 'render_media_section' ),
			$page
		);

		add_settings_field(
			Lazy_Load::SETTING_IMAGES,
			__( 'Ativar Lazy Load para Imagens', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_lazyload',
			array(
				'label_for'   => 'o2r-lazyload-images',
				'key'         => Lazy_Load::SETTING_IMAGES,
				'label'       => __( 'Carregar imagens só quando estiverem perto de aparecer na tela', 'o2-rocket' ),
				'description' => __( 'Vale para a página inteira: conteúdo, cabeçalho, rodapé, widgets e construtores como o Elementor (inclusive <picture>). As imagens acima da dobra medidas pela otimização da LCP, as duas primeiras da página enquanto não há medição e as que o WordPress marca como prioritárias carregam de imediato.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Lazy_Load::SETTING_CSS_BG,
			__( 'Lazy Load para imagens de fundo', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_lazyload',
			array(
				'label_for'   => 'o2r-lazyload-css-bg',
				'key'         => Lazy_Load::SETTING_CSS_BG,
				'label'       => __( 'Carregar fundos (background-image) só quando a seção chega perto da tela', 'o2-rocket' ),
				'description' => __( 'Fundos em style="" e em CSS (arquivos locais e blocos <style>), como os das seções e colunas do Elementor. Fundos de :hover ficam como estão; o primeiro fundo da página e os acima da dobra medidos carregam de imediato.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Lazy_Load::SETTING_IFRAMES,
			__( 'Ativar Lazy Load para Iframes/Vídeos', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_lazyload',
			array(
				'label_for'   => 'o2r-lazyload-iframes',
				'key'         => Lazy_Load::SETTING_IFRAMES,
				'label'       => __( 'Carregar vídeos do YouTube/Vimeo, mapas e outros iframes da página sob demanda', 'o2-rocket' ),
				'description' => __( 'Iframes que apontam para o painel do WordPress e os que estão dentro de <noscript> não são alterados.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Lazy_Load::SETTING_YOUTUBE,
			__( 'Miniatura no lugar do YouTube', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_lazyload',
			array(
				'label_for'   => 'o2r-lazyload-youtube',
				'key'         => Lazy_Load::SETTING_YOUTUBE,
				'label'       => __( 'Mostrar a capa do vídeo com um botão de play e carregar o player só no clique', 'o2-rocket' ),
				'description' => __( 'Cada player do YouTube traz mais de 500 KB de JavaScript. A capa vem de i.ytimg.com (servidor do YouTube) e o vídeo começa sozinho ao clicar.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Lazy_Load::SETTING_EXCLUDED,
			__( 'Nunca aplicar lazy load em', 'o2-rocket' ),
			array( $this, 'render_textarea' ),
			$page,
			'o2r_lazyload',
			array(
				'label_for'   => 'o2r-lazyload-excluded',
				'key'         => Lazy_Load::SETTING_EXCLUDED,
				'rows'        => 3,
				'placeholder' => "logo.png\nslider-principal\ngravatar.com",
				'description' => __( 'Trecho do nome do arquivo, de uma classe ou de qualquer atributo da tag, um por linha. Também é possível marcar a tag com a classe skip-lazy ou o atributo data-no-lazy.', 'o2-rocket' ),
			)
		);

		add_settings_section(
			'o2r_lcp',
			__( 'Imagem principal e layout', 'o2-rocket' ),
			array( $this, 'render_lcp_section' ),
			$page
		);

		add_settings_field(
			Lcp_Optimizer::SETTING_ENABLED,
			__( 'Otimizar a imagem LCP', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_lcp',
			array(
				'label_for'   => 'o2r-lcp',
				'key'         => Lcp_Optimizer::SETTING_ENABLED,
				'label'       => __( 'Medir a imagem principal de cada página e carregá-la primeiro', 'o2-rocket' ),
				'description' => __( 'Um script de 2 KB no navegador do primeiro visitante descobre a imagem LCP e as imagens acima da dobra (no celular e no computador). A partir daí a página sai com preload e fetchpriority="high" nessa imagem e sem lazy load acima da dobra. Tudo no seu servidor, sem serviço externo.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Lazy_Render::SETTING,
			__( 'Renderização sob demanda', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_lcp',
			array(
				'label_for'   => 'o2r-lazy-render',
				'key'         => Lazy_Render::SETTING,
				'label'       => __( 'Só montar o layout dos blocos bem abaixo da dobra quando o visitante rolar até eles', 'o2-rocket' ),
				'description' => __( 'O mesmo script mede a posição das seções, rodapés e blocos da página no celular e no computador; os que ficam a mais de 1800 px da primeira tela nos dois ganham content-visibility:auto com a altura medida (a barra de rolagem não pula). Blocos com elementos fixos ou sticky, popups, avisos de cookies e margens negativas ficam de fora. Para excluir um bloco, adicione o atributo data-o2r-no-lazy-render.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Image_Dimensions::SETTING_ENABLED,
			__( 'Dimensões das imagens', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_lcp',
			array(
				'label_for'   => 'o2r-image-dimensions',
				'key'         => Image_Dimensions::SETTING_ENABLED,
				'label'       => __( 'Acrescentar width e height às imagens que não têm', 'o2-rocket' ),
				'description' => __( 'O navegador reserva o espaço da imagem antes de ela chegar e o conteúdo não "pula" (CLS). Vale para imagens e SVGs do próprio site.', 'o2-rocket' ),
			)
		);

		add_settings_section(
			'o2r_webp',
			__( 'WebP', 'o2-rocket' ),
			array( $this, 'render_webp_section' ),
			$page
		);

		add_settings_field(
			Image_Optimizer::SETTING_AUTO,
			__( 'Converter novas imagens', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_webp',
			array(
				'label_for'   => 'o2r-webp-auto',
				'key'         => Image_Optimizer::SETTING_AUTO,
				'label'       => __( 'Gerar a versão WebP ao enviar uma imagem para a biblioteca', 'o2-rocket' ),
				'description' => __( 'Converte o arquivo enviado e todos os tamanhos gerados pelo WordPress. O envio fica alguns segundos mais lento em imagens grandes.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Image_Optimizer::SETTING_QUALITY,
			__( 'Qualidade do WebP para JPEG', 'o2-rocket' ),
			array( $this, 'render_number' ),
			$page,
			'o2r_webp',
			array(
				'label_for'   => 'o2r-webp-quality',
				'key'         => Image_Optimizer::SETTING_QUALITY,
				'min'         => 50,
				'max'         => 100,
				'suffix'      => '%',
				'description' => __( '82 (padrão) fica visualmente igual ao JPEG e 25 a 35% menor. 100 = sem perda (raramente menor que o JPEG). PNG é sempre convertido sem perda. Ao mudar, os JPEGs voltam para a fila da otimização em massa.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Image_Optimizer::SETTING_SERVE,
			__( 'Exibir WebP no site', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_webp',
			array(
				'label_for'   => 'o2r-webp-serve',
				'key'         => Image_Optimizer::SETTING_SERVE,
				'label'       => __( 'Trocar .jpg e .png por .webp no HTML sempre que existir uma versão WebP menor', 'o2-rocket' ),
				'description' => __( 'Vale para todo o HTML da página: conteúdo, templates do tema, srcset e imagens de fundo em CSS inline. Todos os navegadores atuais exibem WebP.', 'o2-rocket' ),
			)
		);
	}

	private function register_font_fields( Admin_UI $ui ): void {
		$page = $ui->get_settings_page( self::TAB_MEDIA );

		add_settings_section(
			'o2r_fonts',
			__( 'Fontes e conexões externas', 'o2-rocket' ),
			array( $this, 'render_fonts_section' ),
			$page
		);

		add_settings_field(
			Google_Fonts::SETTING_LOCAL,
			__( 'Hospedar o Google Fonts no site', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_fonts',
			array(
				'label_for'   => 'o2r-fonts-local',
				'key'         => Google_Fonts::SETTING_LOCAL,
				'label'       => __( 'Baixar o CSS e as fontes do Google e servi-los do próprio servidor', 'o2-rocket' ),
				'description' => __( 'Elimina duas conexões externas (fonts.googleapis.com e fonts.gstatic.com) e não envia o IP dos visitantes ao Google (LGPD/GDPR). Os arquivos ficam em wp-content/cache/o2-rocket/fonts/; se o download falhar, a fonte continua vindo do Google.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Google_Fonts::SETTING_DISPLAY_SWAP,
			__( 'Texto visível durante o carregamento', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_fonts',
			array(
				'label_for'   => 'o2r-fonts-swap',
				'key'         => Google_Fonts::SETTING_DISPLAY_SWAP,
				'label'       => __( 'Usar font-display: swap no Google Fonts e nos CSS minificados', 'o2-rocket' ),
				'description' => __( 'O texto aparece na hora com a fonte do sistema e troca quando a fonte chega (recomendado pelo PageSpeed).', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Resource_Hints::SETTING_FONTS_AUTO,
			__( 'Pré-carregar fontes automaticamente', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_fonts',
			array(
				'label_for'   => 'o2r-fonts-auto',
				'key'         => Resource_Hints::SETTING_FONTS_AUTO,
				'label'       => __( 'Preload das fontes usadas no topo de cada página', 'o2-rocket' ),
				'description' => __( 'O mesmo beacon local da otimização da LCP informa as fontes pedidas antes do maior elemento aparecer (até 4 por página).', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Resource_Hints::SETTING_FONTS_PRELOAD,
			__( 'Fontes para pré-carregar', 'o2-rocket' ),
			array( $this, 'render_textarea' ),
			$page,
			'o2r_fonts',
			array(
				'label_for'   => 'o2r-fonts-preload',
				'key'         => Resource_Hints::SETTING_FONTS_PRELOAD,
				'rows'        => 3,
				'placeholder' => "/wp-content/themes/meu-tema/fonts/titulos.woff2",
				'description' => __( 'Opcional. URL ou caminho de arquivos .woff2/.woff/.ttf/.otf, um por linha, pré-carregados em todas as páginas.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Resource_Hints::SETTING_PRECONNECT_AUTO,
			__( 'Preconnect automático', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_fonts',
			array(
				'label_for'   => 'o2r-preconnect-auto',
				'key'         => Resource_Hints::SETTING_PRECONNECT_AUTO,
				'label'       => __( 'Abrir antes a conexão com os domínios externos usados no topo da página', 'o2-rocket' ),
				'description' => __( 'Com base no beacon local: até 5 domínios por página (CDNs, fontes, imagens externas). Scripts atrasados não contam.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Resource_Hints::SETTING_PRECONNECT,
			__( 'Domínios para preconnect', 'o2-rocket' ),
			array( $this, 'render_textarea' ),
			$page,
			'o2r_fonts',
			array(
				'label_for'   => 'o2r-preconnect',
				'key'         => Resource_Hints::SETTING_PRECONNECT,
				'rows'        => 3,
				'placeholder' => "https://cdn.exemplo.com\nhttps://fonts.gstatic.com",
				'description' => __( 'Opcional. Um domínio por linha, em todas as páginas (inclui dns-prefetch para navegadores antigos).', 'o2-rocket' ),
			)
		);
	}

	private function register_database_fields( Admin_UI $ui ): void {
		$page  = $ui->get_settings_page( self::TAB_DATABASE );
		$items = array(
			Cleaner::SETTING_REVISIONS          => array( __( 'Revisões', 'o2-rocket' ), __( 'Versões antigas salvas a cada edição de posts e páginas.', 'o2-rocket' ) ),
			Cleaner::SETTING_AUTO_DRAFTS        => array( __( 'Rascunhos automáticos', 'o2-rocket' ), __( 'Criados ao abrir o editor e nunca publicados.', 'o2-rocket' ) ),
			Cleaner::SETTING_TRASHED_POSTS      => array( __( 'Posts na lixeira', 'o2-rocket' ), __( 'Exclui definitivamente (não dá para restaurar).', 'o2-rocket' ) ),
			Cleaner::SETTING_SPAM_COMMENTS      => array( __( 'Comentários de spam', 'o2-rocket' ), '' ),
			Cleaner::SETTING_TRASHED_COMMENTS   => array( __( 'Comentários na lixeira', 'o2-rocket' ), '' ),
			Cleaner::SETTING_EXPIRED_TRANSIENTS => array( __( 'Transients expirados', 'o2-rocket' ), __( 'Dados temporários vencidos. Seguro.', 'o2-rocket' ) ),
			Cleaner::SETTING_ALL_TRANSIENTS     => array( __( 'Todos os transients', 'o2-rocket' ), __( 'Inclui os válidos: plugins recriam quando precisam, mas a primeira visita depois pode ficar mais lenta.', 'o2-rocket' ) ),
			Cleaner::SETTING_ORPHAN_META        => array( __( 'Metadados órfãos', 'o2-rocket' ), __( 'Metadados de posts, comentários e termos que não existem mais.', 'o2-rocket' ) ),
			Cleaner::SETTING_OPTIMIZE_TABLES    => array( __( 'Otimizar tabelas', 'o2-rocket' ), Cleaner::is_sqlite() ? __( 'SQLite: compacta o arquivo do banco (VACUUM).', 'o2-rocket' ) : __( 'Recupera o espaço livre das tabelas do site (OPTIMIZE TABLE).', 'o2-rocket' ) ),
		);

		add_settings_section(
			'o2r_database',
			__( 'Limpeza', 'o2-rocket' ),
			array( $this, 'render_database_section' ),
			$page
		);

		foreach ( $items as $key => $item ) {
			add_settings_field(
				$key,
				$item[0],
				array( $this, 'render_toggle' ),
				$page,
				'o2r_database',
				array(
					'label_for'   => 'o2r-' . str_replace( '_', '-', $key ),
					'key'         => $key,
					'label'       => __( 'Incluir na limpeza', 'o2-rocket' ),
					'description' => $item[1],
				)
			);

			if ( Cleaner::SETTING_REVISIONS === $key ) {
				add_settings_field(
					Cleaner::SETTING_REVISIONS_KEEP,
					__( 'Revisões a manter por post', 'o2-rocket' ),
					array( $this, 'render_number' ),
					$page,
					'o2r_database',
					array(
						'label_for'   => 'o2r-database-revisions-keep',
						'key'         => Cleaner::SETTING_REVISIONS_KEEP,
						'min'         => 0,
						'max'         => 100,
						'suffix'      => __( 'mais recentes', 'o2-rocket' ),
						'description' => __( '0 apaga todas. Com 3, cada post continua com as 3 últimas versões para desfazer alterações.', 'o2-rocket' ),
					)
				);
			}
		}

		add_settings_section(
			'o2r_database_schedule',
			__( 'Agendamento', 'o2-rocket' ),
			null,
			$page
		);

		add_settings_field(
			Cleaner::SETTING_SCHEDULE,
			__( 'Limpeza automática', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_database_schedule',
			array(
				'label_for'   => 'o2r-database-schedule',
				'key'         => Cleaner::SETTING_SCHEDULE,
				'label'       => __( 'Limpar os itens marcados de tempos em tempos', 'o2-rocket' ),
				'description' => __( 'Roda de madrugada pelo WP-Cron, em lotes curtos.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Cleaner::SETTING_FREQUENCY,
			__( 'Frequência', 'o2-rocket' ),
			array( $this, 'render_select' ),
			$page,
			'o2r_database_schedule',
			array(
				'label_for' => 'o2r-database-frequency',
				'key'       => Cleaner::SETTING_FREQUENCY,
				'choices'   => self::get_frequency_choices(),
				'default'   => 'weekly',
			)
		);
	}

	private function register_heartbeat_fields( Admin_UI $ui ): void {
		$page = $ui->get_settings_page( self::TAB_HEARTBEAT );

		add_settings_section(
			'o2r_heartbeat',
			__( 'Heartbeat API', 'o2-rocket' ),
			array( $this, 'render_heartbeat_section' ),
			$page
		);

		add_settings_field(
			Heartbeat::SETTING_CONTROL,
			__( 'Controlar a Heartbeat', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_heartbeat',
			array(
				'label_for' => 'o2r-heartbeat-control',
				'key'       => Heartbeat::SETTING_CONTROL,
				'label'     => __( 'Aplicar as regras abaixo', 'o2-rocket' ),
			)
		);

		$places = array(
			Heartbeat::SETTING_SITE   => array( __( 'No site', 'o2-rocket' ), __( 'Visitantes logados navegando no site. Quase nenhum tema precisa.', 'o2-rocket' ) ),
			Heartbeat::SETTING_ADMIN  => array( __( 'No painel', 'o2-rocket' ), __( 'Telas do painel fora do editor (avisos de sessão expirada, painéis em tempo real).', 'o2-rocket' ) ),
			Heartbeat::SETTING_EDITOR => array( __( 'No editor', 'o2-rocket' ), __( 'Salvamento automático e aviso de "outra pessoa está editando". Desativar aqui desliga os dois.', 'o2-rocket' ) ),
		);

		foreach ( $places as $key => $place ) {
			add_settings_field(
				$key,
				$place[0],
				array( $this, 'render_select' ),
				$page,
				'o2r_heartbeat',
				array(
					'label_for'   => 'o2r-' . str_replace( '_', '-', $key ),
					'key'         => $key,
					'choices'     => self::get_heartbeat_choices(),
					'description' => $place[1],
				)
			);
		}
	}

	private function register_file_optimization_fields( Admin_UI $ui ): void {
		$page = $ui->get_settings_page( self::TAB_FILE_OPTIMIZATION );

		add_settings_section(
			'o2r_file_delivery',
			__( 'Geral', 'o2-rocket' ),
			array( $this, 'render_file_optimization_section' ),
			$page
		);

		add_settings_field(
			File_Optimizer::SETTING_REMOVE_QUERY_STRINGS,
			__( 'Remover Query Strings', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_file_delivery',
			array(
				'label_for'   => 'o2r-remove-query-strings',
				'key'         => File_Optimizer::SETTING_REMOVE_QUERY_STRINGS,
				'label'       => __( 'Remover o ?ver= que o WordPress acrescenta aos arquivos CSS e JS', 'o2-rocket' ),
				'description' => __( 'Só remove o parâmetro de versão gerado pelo WordPress. Outros parâmetros (como os do Google Fonts) e versões escritas pelo próprio plugin ou tema na URL são mantidos. Após atualizar temas e plugins, limpe o cache do navegador ou da CDN.', 'o2-rocket' ),
			)
		);

		add_settings_section(
			'o2r_file_css',
			__( 'CSS', 'o2-rocket' ),
			null,
			$page
		);

		add_settings_field(
			Asset_Minifier::SETTING_CSS,
			__( 'Minificar CSS', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_file_css',
			array(
				'label_for'   => 'o2r-minify-css',
				'key'         => Asset_Minifier::SETTING_CSS,
				'label'       => __( 'Remover espaços e comentários dos arquivos CSS do site', 'o2-rocket' ),
				'description' => __( 'Gera cópias menores em wp-content/cache/o2-rocket/min/ (os originais não mudam). Caminhos de imagens e fontes são corrigidos e as fontes ganham font-display: swap. Arquivos de outros domínios ficam como estão.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Asset_Minifier::SETTING_CSS_EXCLUDED,
			__( 'CSS que não deve ser minificado', 'o2-rocket' ),
			array( $this, 'render_textarea' ),
			$page,
			'o2r_file_css',
			array(
				'label_for'   => 'o2r-minify-css-excluded',
				'key'         => Asset_Minifier::SETTING_CSS_EXCLUDED,
				'rows'        => 3,
				'placeholder' => "/wp-content/plugins/meu-plugin/estilo.css\nmeu-tema/assets/css/",
				'description' => __( 'Trecho da URL do arquivo, um por linha.', 'o2-rocket' ),
			)
		);

		add_settings_section(
			'o2r_css_delivery',
			__( 'Entrega do CSS', 'o2-rocket' ),
			array( $this, 'render_css_delivery_section' ),
			$page
		);

		add_settings_field(
			Css_Delivery::SETTING_MODE,
			__( 'Otimizar a entrega do CSS', 'o2-rocket' ),
			array( $this, 'render_select' ),
			$page,
			'o2r_css_delivery',
			array(
				'label_for'   => 'o2r-css-mode',
				'key'         => Css_Delivery::SETTING_MODE,
				'choices'     => self::get_css_mode_choices(),
				'description' => __( 'Remover CSS não utilizado: cada página leva em linha só as regras que podem valer para ela (o resto sai do carregamento). CSS crítico: só o CSS do topo da página vai em linha (medido pelo beacon local; sem medição, estimado pelo início da página) e as folhas de estilo carregam sem bloquear. Tudo calculado no seu servidor, sem serviço externo.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Css_Delivery::SETTING_FALLBACK,
			__( 'Rede de segurança', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_css_delivery',
			array(
				'label_for'   => 'o2r-css-fallback',
				'key'         => Css_Delivery::SETTING_FALLBACK,
				'label'       => __( 'Carregar o CSS completo na primeira interação do visitante', 'o2-rocket' ),
				'description' => __( 'Só no modo "Remover CSS não utilizado". Estilos de menus, abas e janelas abertos por JavaScript chegam antes do clique terminar. Não afeta a nota do PageSpeed.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Css_Delivery::SETTING_SAFELIST,
			__( 'Seletores que nunca saem', 'o2-rocket' ),
			array( $this, 'render_textarea' ),
			$page,
			'o2r_css_delivery',
			array(
				'label_for'   => 'o2r-css-safelist',
				'key'         => Css_Delivery::SETTING_SAFELIST,
				'rows'        => 3,
				'placeholder' => ".minha-classe-js\nmeu-slider-*\n#janela-oferta",
				'description' => __( 'Classes ou ids (aceita *) acrescentados por JavaScript, um por linha. Estados comuns (active, open, show, is-*, has-*, menu, modal, slider...) já são mantidos.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Css_Delivery::SETTING_EXCLUDED,
			__( 'CSS que fica como está', 'o2-rocket' ),
			array( $this, 'render_textarea' ),
			$page,
			'o2r_css_delivery',
			array(
				'label_for'   => 'o2r-css-excluded',
				'key'         => Css_Delivery::SETTING_EXCLUDED,
				'rows'        => 3,
				'placeholder' => "/wp-content/plugins/meu-plugin/estilo.css\nid-do-bloco-style",
				'description' => __( 'Trecho da URL da folha de estilo ou do id/conteúdo de um bloco <style>, um por linha.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Css_Delivery::SETTING_CRITICAL,
			__( 'CSS crítico de reserva', 'o2-rocket' ),
			array( $this, 'render_textarea' ),
			$page,
			'o2r_css_delivery',
			array(
				'label_for'   => 'o2r-css-critical',
				'key'         => Css_Delivery::SETTING_CRITICAL,
				'rows'        => 4,
				'placeholder' => 'header{...}',
				'description' => __( 'Opcional, modo CSS crítico: usado nas páginas ainda sem medição do beacon, no lugar da estimativa automática.', 'o2-rocket' ),
			)
		);

		add_settings_section(
			'o2r_file_js',
			__( 'JavaScript', 'o2-rocket' ),
			null,
			$page
		);

		add_settings_field(
			Asset_Minifier::SETTING_JS,
			__( 'Minificar JavaScript', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_file_js',
			array(
				'label_for'   => 'o2r-minify-js',
				'key'         => Asset_Minifier::SETTING_JS,
				'label'       => __( 'Remover espaços e comentários dos arquivos JavaScript do site', 'o2-rocket' ),
				'description' => __( 'Arquivos que já vêm minificados (.min.js), módulos ES e scripts de outros domínios ficam como estão. Se algo parar de funcionar, exclua o arquivo abaixo.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Asset_Minifier::SETTING_JS_EXCLUDED,
			__( 'JavaScript que não deve ser minificado', 'o2-rocket' ),
			array( $this, 'render_textarea' ),
			$page,
			'o2r_file_js',
			array(
				'label_for'   => 'o2r-minify-js-excluded',
				'key'         => Asset_Minifier::SETTING_JS_EXCLUDED,
				'rows'        => 3,
				'placeholder' => '/wp-content/plugins/meu-plugin/script.js',
				'description' => __( 'Trecho da URL do arquivo, um por linha.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			File_Optimizer::SETTING_DEFER_JS,
			__( 'Carregar JavaScript de forma adiada (Defer)', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_file_js',
			array(
				'label_for'   => 'o2r-defer-js',
				'key'         => File_Optimizer::SETTING_DEFER_JS,
				'label'       => __( 'Executar os scripts só depois que o HTML for lido', 'o2-rocket' ),
				'description' => __( 'O jQuery nunca é adiado, esteja ele no WordPress ou em uma CDN. Scripts com código inline anexado, e todos os scripts dos quais eles dependem, também continuam síncronos.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			File_Optimizer::SETTING_DEFER_JS_EXCLUDED,
			__( 'JavaScript que não deve ser adiado', 'o2-rocket' ),
			array( $this, 'render_textarea' ),
			$page,
			'o2r_file_js',
			array(
				'label_for'   => 'o2r-defer-js-excluded',
				'key'         => File_Optimizer::SETTING_DEFER_JS_EXCLUDED,
				'rows'        => 3,
				'placeholder' => "meu-plugin/js/mapa.js\nhandle-do-script",
				'description' => __( 'Trecho da URL ou handle do script, um por linha. Os scripts dos quais ele depende também deixam de ser adiados.', 'o2-rocket' ),
			)
		);

		add_settings_section(
			'o2r_delay_js',
			__( 'Atrasar a execução do JavaScript', 'o2-rocket' ),
			array( $this, 'render_delay_js_section' ),
			$page
		);

		add_settings_field(
			Delay_Js::SETTING_ENABLED,
			__( 'Atrasar JavaScript', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_delay_js',
			array(
				'label_for'   => 'o2r-delay-js',
				'key'         => Delay_Js::SETTING_ENABLED,
				'label'       => __( 'Executar os scripts só na primeira interação do visitante', 'o2-rocket' ),
				'description' => __( 'Mouse, toque, teclado ou roda do mouse disparam os scripts, na ordem original. Um clique feito antes disso é repetido quando eles terminam. Visitantes logados não são afetados.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Delay_Js::SETTING_AUTO,
			__( 'Exclusões conhecidas', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_delay_js',
			array(
				'label_for'   => 'o2r-delay-js-auto',
				'key'         => Delay_Js::SETTING_AUTO,
				'label'       => __( 'Aplicar as exclusões conhecidas para os plugins e o tema ativos', 'o2-rocket' ),
				'description' => __( 'Lista com mais de 400 plugins e temas (sliders, galerias, menus, formulários) cujos scripts precisam rodar sem esperar a interação.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Delay_Js::SETTING_SERVICES,
			__( 'Serviços externos que não esperam', 'o2-rocket' ),
			array( $this, 'render_checkboxes' ),
			$page,
			'o2r_delay_js',
			array(
				'key'         => Delay_Js::SETTING_SERVICES,
				'choices'     => $this->get_delay_js()->get_service_choices(),
				'description' => __( 'Marque os serviços que precisam rodar já no carregamento (ex.: Google Analytics para contar visitas sem interação).', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Delay_Js::SETTING_SAFE_MODE,
			__( 'Modo seguro', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_delay_js',
			array(
				'label_for'   => 'o2r-delay-js-safe',
				'key'         => Delay_Js::SETTING_SAFE_MODE,
				'label'       => __( 'Atrasar só scripts de terceiros', 'o2-rocket' ),
				'description' => __( 'jQuery, scripts em linha do WordPress e arquivos de wp-content e wp-includes rodam normalmente. Ganho menor, mas resolve quase todo conflito.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Delay_Js::SETTING_TIMEOUT,
			__( 'Executar sem interação depois de', 'o2-rocket' ),
			array( $this, 'render_number' ),
			$page,
			'o2r_delay_js',
			array(
				'label_for'   => 'o2r-delay-js-timeout',
				'key'         => Delay_Js::SETTING_TIMEOUT,
				'min'         => 0,
				'max'         => 60,
				'suffix'      => __( 'segundos', 'o2-rocket' ),
				'description' => __( '0 = só com interação (melhor nota no PageSpeed). Um valor como 8 garante que análises e chats rodem mesmo para quem só lê a página.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Delay_Js::SETTING_EXCLUSIONS,
			__( 'Scripts que não devem esperar', 'o2-rocket' ),
			array( $this, 'render_textarea' ),
			$page,
			'o2r_delay_js',
			array(
				'label_for'   => 'o2r-delay-js-exclusions',
				'key'         => Delay_Js::SETTING_EXCLUSIONS,
				'rows'        => 4,
				'placeholder' => "/wp-content/plugins/meu-slider/\nmeuSliderInit\n/jquery-?[0-9.]*(.min|.slim|.slim.min)?.js",
				'description' => __( 'Trecho da URL do arquivo ou do código do script em linha, um por linha (aceita expressões regulares).', 'o2-rocket' ),
			)
		);

		add_settings_section(
			'o2r_wp_cleanup',
			__( 'Limpeza do WordPress', 'o2-rocket' ),
			null,
			$page
		);

		add_settings_field(
			File_Optimizer::SETTING_REMOVE_EMOJIS,
			__( 'Desativar emojis do WordPress', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_wp_cleanup',
			array(
				'label_for'   => 'o2r-remove-emojis',
				'key'         => File_Optimizer::SETTING_REMOVE_EMOJIS,
				'label'       => __( 'Remover o script e os estilos de emojis', 'o2-rocket' ),
				'description' => __( 'Os navegadores atuais mostram emojis sozinhos. Remove uma requisição e um script em linha de todas as páginas.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			File_Optimizer::SETTING_REMOVE_JQUERY_MIGRATE,
			__( 'Remover jQuery Migrate', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_wp_cleanup',
			array(
				'label_for'   => 'o2r-remove-jquery-migrate',
				'key'         => File_Optimizer::SETTING_REMOVE_JQUERY_MIGRATE,
				'label'       => __( 'Não carregar o jQuery Migrate no site', 'o2-rocket' ),
				'description' => __( 'Só é necessário para scripts antigos que usam funções removidas do jQuery. O painel continua com ele. Teste o site após ativar.', 'o2-rocket' ),
			)
		);
	}

	private function register_page_cache_fields( Admin_UI $ui ): void {
		$page = $ui->get_settings_page( self::TAB_PAGE_CACHE );

		add_settings_section(
			'o2r_page_cache',
			__( 'Cache de página', 'o2-rocket' ),
			array( $this, 'render_page_cache_section' ),
			$page
		);

		add_settings_field(
			Page_Cache::SETTING_ENABLED,
			__( 'Habilitar Page Caching', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_page_cache',
			array(
				'label_for'   => 'o2r-page-cache-enabled',
				'key'         => Page_Cache::SETTING_ENABLED,
				'label'       => __( 'Servir páginas em HTML estático para visitantes não logados', 'o2-rocket' ),
				'description' => __( 'Instala o drop-in advanced-cache.php em wp-content/ e define WP_CACHE como true no wp-config.php.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Page_Cache::SETTING_LIFESPAN,
			__( 'Tempo de vida do cache', 'o2-rocket' ),
			array( $this, 'render_lifespan' ),
			$page,
			'o2r_page_cache',
			array(
				'label_for'   => 'o2r-page-cache-lifespan',
				'key'         => Page_Cache::SETTING_LIFESPAN,
				'unit_key'    => Page_Cache::SETTING_LIFESPAN_UNIT,
				'description' => __( 'Depois desse tempo a página é gerada de novo na próxima visita (ou pelo pré-carregamento). Uma tarefa agendada apaga as páginas vencidas. Use 0 para nunca expirar: o cache continua sendo limpo ao editar conteúdo.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Page_Cache::SETTING_MOBILE,
			__( 'Cache separado para celulares', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_page_cache',
			array(
				'label_for'   => 'o2r-page-cache-mobile',
				'key'         => Page_Cache::SETTING_MOBILE,
				'label'       => __( 'Gravar uma versão das páginas para celulares e tablets', 'o2-rocket' ),
				'description' => __( 'Ligue só se o tema ou algum plugin gera HTML diferente no celular (wp_is_mobile). Temas responsivos, como o Elementor, não precisam.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Page_Cache::SETTING_GZIP,
			__( 'Páginas pré-comprimidas (GZIP)', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_page_cache',
			array(
				'label_for'   => 'o2r-page-cache-gzip',
				'key'         => Page_Cache::SETTING_GZIP,
				'label'       => __( 'Gravar uma cópia .gz de cada página e entregá-la já comprimida', 'o2-rocket' ),
				'description' => __( 'O servidor não precisa comprimir a página a cada visita. Navegadores que não aceitam GZIP recebem o HTML normal.', 'o2-rocket' ),
			)
		);

		add_settings_section(
			'o2r_preload',
			__( 'Pré-carregamento', 'o2-rocket' ),
			array( $this, 'render_preload_section' ),
			$page
		);

		add_settings_field(
			Preloader::SETTING_ENABLED,
			__( 'Pré-carregar o cache', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_preload',
			array(
				'label_for'   => 'o2r-preload-enabled',
				'key'         => Preloader::SETTING_ENABLED,
				'label'       => __( 'Gerar o cache de todas as páginas do sitemap antes da primeira visita', 'o2-rocket' ),
				'description' => __( 'Usa o sitemap do WordPress, do Yoast, do Rank Math, do SEOPress, do All in One SEO ou do The SEO Framework (sem sitemap, lista os conteúdos publicados). Uma página por vez: começa na hora e continua em lotes a cada minuto pelo WP-Cron.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Preloader::SETTING_AFTER_PURGE,
			__( 'Pré-carregar depois de limpar', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_preload',
			array(
				'label_for'   => 'o2r-preload-after-purge',
				'key'         => Preloader::SETTING_AFTER_PURGE,
				'requires'    => 'o2r-preload-enabled',
				'label'       => __( 'Pré-carregar automaticamente depois de limpar', 'o2-rocket' ),
				'description' => __( 'Toda limpeza (manual, ao salvar um post, página ou produto, ao trocar opções, ao aplicar um perfil ou por expiração) devolve as páginas limpas para a frente da fila: a home primeiro, depois o menu principal e as páginas que acabaram de ser limpas. Só funciona com o pré-carregamento ligado.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Link_Prefetch::SETTING_ENABLED,
			__( 'Pré-carregar links', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_preload',
			array(
				'label_for'   => 'o2r-preload-links',
				'key'         => Link_Prefetch::SETTING_ENABLED,
				'label'       => __( 'Baixar a próxima página quando o visitante para o mouse sobre um link', 'o2-rocket' ),
				'description' => __( 'No WordPress 6.8+ usa as speculation rules do núcleo (prefetch ao passar o mouse); nos outros casos, um script de 2 KB. Respeita o modo de economia de dados e conexões 2G.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Preloader::SETTING_EXCLUDED,
			__( 'URLs fora do pré-carregamento', 'o2-rocket' ),
			array( $this, 'render_textarea' ),
			$page,
			'o2r_preload',
			array(
				'label_for'   => 'o2r-preload-excluded',
				'key'         => Preloader::SETTING_EXCLUDED,
				'rows'        => 4,
				'placeholder' => "/tag/\n/arquivo/*",
				'description' => __( 'Mesmo formato das URIs excluídas. Essas páginas continuam em cache quando visitadas; só não são geradas com antecedência.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Preloader::SETTING_SITEMAPS,
			__( 'Sitemaps adicionais', 'o2-rocket' ),
			array( $this, 'render_textarea' ),
			$page,
			'o2r_preload',
			array(
				'label_for'   => 'o2r-preload-sitemaps',
				'key'         => Preloader::SETTING_SITEMAPS,
				'rows'        => 3,
				'placeholder' => home_url( '/sitemap-personalizado.xml' ),
				'description' => __( 'Opcional. Uma URL por linha, somadas às detectadas automaticamente.', 'o2-rocket' ),
			)
		);

		add_settings_section(
			'o2r_page_cache_exclusions',
			__( 'Exclusões', 'o2-rocket' ),
			array( $this, 'render_exclusions_section' ),
			$page
		);

		add_settings_field(
			Page_Cache::SETTING_EXCLUDED_URIS,
			__( 'URIs Excluídas', 'o2-rocket' ),
			array( $this, 'render_textarea' ),
			$page,
			'o2r_page_cache_exclusions',
			array(
				'label_for'   => 'o2r-page-cache-excluded-uris',
				'key'         => Page_Cache::SETTING_EXCLUDED_URIS,
				'rows'        => 8,
				'placeholder' => "/pagina-dinamica/\n/produto/*/avaliacoes/\npromocao-relampago",
				'description' => __( 'Uma por linha. Com "/" no início, compara a partir do início do caminho; sem "/", procura o termo em qualquer parte da URI. Use * como curinga.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Page_Cache::SETTING_REJECTED_COOKIES,
			__( 'Cookies que impedem o cache', 'o2-rocket' ),
			array( $this, 'render_textarea' ),
			$page,
			'o2r_page_cache_exclusions',
			array(
				'label_for'   => 'o2r-page-cache-rejected-cookies',
				'key'         => Page_Cache::SETTING_REJECTED_COOKIES,
				'rows'        => 4,
				'placeholder' => "meu_plugin_sessao\nmoeda_escolhida",
				'description' => __( 'Nome completo ou parte do nome, um por linha. Visitantes com um desses cookies nunca recebem nem geram página em cache.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Page_Cache::SETTING_REJECTED_USER_AGENTS,
			__( 'Navegadores e robôs sem cache', 'o2-rocket' ),
			array( $this, 'render_textarea' ),
			$page,
			'o2r_page_cache_exclusions',
			array(
				'label_for'   => 'o2r-page-cache-rejected-ua',
				'key'         => Page_Cache::SETTING_REJECTED_USER_AGENTS,
				'rows'        => 3,
				'placeholder' => 'facebookexternalhit',
				'description' => __( 'Trecho do User-Agent, um por linha (sem diferenciar maiúsculas). Essas visitas sempre passam pelo WordPress.', 'o2-rocket' ),
			)
		);

		add_settings_section(
			'o2r_page_cache_params',
			__( 'Parâmetros de URL', 'o2-rocket' ),
			array( $this, 'render_params_section' ),
			$page
		);

		add_settings_field(
			Page_Cache::SETTING_QUERY_STRINGS,
			__( 'Parâmetros com cache próprio', 'o2-rocket' ),
			array( $this, 'render_textarea' ),
			$page,
			'o2r_page_cache_params',
			array(
				'label_for'   => 'o2r-page-cache-query-strings',
				'key'         => Page_Cache::SETTING_QUERY_STRINGS,
				'rows'        => 3,
				'placeholder' => "lang\ncor",
				'description' => __( 'Um nome por linha. URLs com esses parâmetros ganham uma cópia em cache para cada valor (ex.: ?cor=azul e ?cor=verde). Qualquer outro parâmetro desconhecido faz a página passar pelo WordPress.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Page_Cache::SETTING_IGNORED_PARAMS,
			__( 'Parâmetros ignorados (extras)', 'o2-rocket' ),
			array( $this, 'render_textarea' ),
			$page,
			'o2r_page_cache_params',
			array(
				'label_for'   => 'o2r-page-cache-ignored-params',
				'key'         => Page_Cache::SETTING_IGNORED_PARAMS,
				'rows'        => 3,
				'placeholder' => "ref\ncupom_parceiro",
				'description' => sprintf(
					/* translators: %d: quantidade de parâmetros reconhecidos. */
					__( 'Somados aos %d parâmetros de marketing já reconhecidos (utm_*, gclid, fbclid, msclkid, mc_cid, _ga...). A URL com eles recebe o cache da página sem os parâmetros, mas nunca gera cache.', 'o2-rocket' ),
					count( Page_Cache::IGNORED_PARAMETERS )
				),
			)
		);

		add_settings_field(
			Page_Cache::SETTING_ALWAYS_PURGE,
			__( 'Sempre limpar estas URLs', 'o2-rocket' ),
			array( $this, 'render_textarea' ),
			$page,
			'o2r_page_cache_params',
			array(
				'label_for'   => 'o2r-page-cache-always-purge',
				'key'         => Page_Cache::SETTING_ALWAYS_PURGE,
				'rows'        => 3,
				'placeholder' => "/ofertas/\n/novidades/",
				'description' => __( 'URL completa ou caminho, um por linha. Limpas junto sempre que qualquer post ou página é publicado, atualizado ou removido (ex.: páginas que listam os últimos posts).', 'o2-rocket' ),
			)
		);

		add_settings_section(
			'o2r_server',
			__( 'Servidor', 'o2-rocket' ),
			array( $this, 'render_server_section' ),
			$page
		);

		add_settings_field(
			Htaccess::SETTING_GZIP,
			__( 'Compressão GZIP/Brotli', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_server',
			array(
				'label_for'   => 'o2r-server-gzip',
				'key'         => Htaccess::SETTING_GZIP,
				'label'       => __( 'Comprimir HTML, CSS, JavaScript, JSON, SVG e fontes no servidor', 'o2-rocket' ),
				'description' => __( 'Regras de mod_deflate/mod_brotli no .htaccess (Apache e LiteSpeed). Usa Brotli quando o servidor tem o módulo.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Htaccess::SETTING_BROWSER_CACHE,
			__( 'Cache do navegador', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_server',
			array(
				'label_for'   => 'o2r-server-browser-cache',
				'key'         => Htaccess::SETTING_BROWSER_CACHE,
				'label'       => __( 'Mandar o navegador guardar imagens, fontes, CSS e JS', 'o2-rocket' ),
				'description' => __( 'CSS, JS e fontes por 1 ano; imagens e vídeos por 4 meses; HTML sempre revalidado. Regras de mod_expires no .htaccess.', 'o2-rocket' ),
			)
		);

		add_settings_field(
			Cache_Rewrite::SETTING,
			__( 'Entregar o cache pelo servidor', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_server',
			array(
				'label_for'   => 'o2r-server-serve-cache',
				'key'         => Cache_Rewrite::SETTING,
				'label'       => __( 'Servir as páginas em cache direto pelo Apache/LiteSpeed, sem carregar o PHP', 'o2-rocket' ),
				'description' => __( 'Regras de mod_rewrite no .htaccess com as mesmas exclusões do cache (cookies, User-Agents, query strings). Ainda mais rápido que o drop-in, mas uma página vencida continua sendo entregue até a limpeza automática de páginas expiradas passar (de hora em hora, ou no intervalo do tempo de vida, se for menor). Só em site único; no multisite e no Nginx o drop-in continua entregando o cache.', 'o2-rocket' ),
			)
		);
	}

	public function render_page_cache_section(): void {
		?>
		<p class="o2r-muted">
			<?php esc_html_e( 'Gera uma cópia em HTML de cada página visitada e a entrega antes de o WordPress carregar, sem PHP nem banco de dados.', 'o2-rocket' ); ?>
		</p>
		<?php
		$hosting = new Hosting();

		if ( $hosting->has_server_page_cache() && ! (bool) apply_filters( 'o2_rocket_page_cache_allowed', true ) ) {
			?>
			<div class="o2r-notice o2r-notice--warning" role="status">
				<div class="o2r-notice-message">
					<p>
						<?php
						/* translators: %s: nome da hospedagem. */
						echo esc_html( sprintf( __( 'A hospedagem %s já faz cache de página no servidor: o do O² Rocket fica desligado para não duplicar o trabalho, e cada limpeza é repassada para o cache da hospedagem. As demais otimizações continuam valendo.', 'o2-rocket' ), $hosting->get_host_name() ) );
						?>
					</p>
				</div>
			</div>
			<?php
			return;
		}

		if ( ! rest_sanitize_boolean( $this->get_value( Page_Cache::SETTING_ENABLED, false ) ) ) {
			return;
		}

		$status = $this->drop_in->get_status();

		$dropin_states = array(
			Drop_In_Manager::STATE_OK       => array( __( 'Instalado', 'o2-rocket' ), 'success' ),
			Drop_In_Manager::STATE_OUTDATED => array( __( 'Desatualizado', 'o2-rocket' ), 'warning' ),
			Drop_In_Manager::STATE_MISSING  => array( __( 'Ausente', 'o2-rocket' ), 'danger' ),
			Drop_In_Manager::STATE_CONFLICT => array( __( 'Pertence a outro plugin', 'o2-rocket' ), 'danger' ),
		);

		$dropin = isset( $dropin_states[ $status['dropin'] ] ) ? $dropin_states[ $status['dropin'] ] : $dropin_states[ Drop_In_Manager::STATE_MISSING ];

		$rows = array(
			array( __( 'Drop-in advanced-cache.php', 'o2-rocket' ), $dropin[0], $dropin[1] ),
			array(
				__( 'Constante WP_CACHE', 'o2-rocket' ),
				$status['wp_cache'] ? __( 'Ativa', 'o2-rocket' ) : __( 'Inativa', 'o2-rocket' ),
				$status['wp_cache'] ? 'success' : 'danger',
			),
			array(
				__( 'Configuração do drop-in', 'o2-rocket' ),
				$status['config'] ? __( 'Gerada', 'o2-rocket' ) : __( 'Ausente', 'o2-rocket' ),
				$status['config'] ? 'success' : 'danger',
			),
		);
		?>
		<div class="o2r-list">
			<?php foreach ( $rows as $row ) : ?>
				<div class="o2r-list-row">
					<span class="o2r-list-title"><?php echo esc_html( $row[0] ); ?></span>
					<?php echo Admin_UI::badge_html( $row[1], $row[2] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado em badge_html(). ?>
				</div>
			<?php endforeach; ?>
		</div>

		<?php
		// O erro guardado só vale enquanto o problema existir de fato.
		$broken = Drop_In_Manager::STATE_OK !== $status['dropin'] || ! $status['wp_cache'] || ! $status['config'];
		?>
		<?php if ( '' !== $status['error'] && $broken ) : ?>
			<div class="o2r-notice o2r-notice--warning" role="alert">
				<div class="o2r-notice-message">
					<p>
						<?php
						/* translators: %s: motivo da falha. */
						echo esc_html( sprintf( __( 'A ativação não foi concluída: %s.', 'o2-rocket' ), $status['error'] ) );
						?>
					</p>
					<p>
						<?php esc_html_e( 'Se o wp-config.php não for gravável, adicione manualmente logo após a linha <?php:', 'o2-rocket' ); ?>
						<code>define( 'WP_CACHE', true );</code>
					</p>
				</div>
			</div>
		<?php endif; ?>

		<div class="o2r-actions">
			<a class="o2r-button" href="<?php echo esc_url( Cache_Controls::get_action_url( 'all' ) ); ?>"><?php esc_html_e( 'Limpar todo o cache', 'o2-rocket' ); ?></a>
			<?php if ( $this->preloader->is_enabled() ) : ?>
				<a class="o2r-button" href="<?php echo esc_url( Cache_Controls::get_action_url( 'purge_preload' ) ); ?>"><?php esc_html_e( 'Limpar e pré-carregar', 'o2-rocket' ); ?></a>
			<?php endif; ?>
			<span class="o2r-muted"><?php esc_html_e( 'Também disponível na barra do admin, em qualquer página do site.', 'o2-rocket' ); ?></span>
		</div>
		<?php
	}

	public function render_preload_section(): void {
		?>
		<p class="o2r-muted">
			<?php esc_html_e( 'O primeiro visitante de cada página não precisa esperar o WordPress montar o HTML: o próprio servidor visita as páginas e deixa o cache pronto.', 'o2-rocket' ); ?>
		</p>
		<?php
		if ( ! $this->preloader->is_enabled() ) {
			return;
		}

		$status  = $this->preloader->get_status();
		$counts  = $status['counts'];
		$total   = max( 0, (int) $status['progress']['total'] );
		$done    = (int) $status['progress']['done'];
		$percent = $total > 0 ? min( 100, round( $done * 100 / $total, 1 ) ) : 100;
		?>
		<div
			class="o2r-preload"
			data-o2r-preload
			data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			data-action="<?php echo esc_attr( Preloader::AJAX_ACTION ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( Preloader::AJAX_ACTION ) ); ?>"
			data-running="<?php echo esc_attr( $status['running'] ? '1' : '0' ); ?>"
			data-label-progress="<?php /* translators: 1: concluídas, 2: total. */ esc_attr_e( 'Pré-carregando: %1$s de %2$s páginas.', 'o2-rocket' ); ?>"
			data-label-done="<?php esc_attr_e( 'Pré-carregamento concluído.', 'o2-rocket' ); ?>"
			data-label-error="<?php esc_attr_e( 'Não foi possível consultar o andamento.', 'o2-rocket' ); ?>"
		>
			<div class="o2r-bulk-progress">
				<div class="o2r-bulk-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) round( $percent ) ); ?>">
					<span class="o2r-bulk-fill" data-o2r-preload-fill style="<?php echo esc_attr( 'width:' . $percent . '%' ); ?>"></span>
				</div>
				<span class="o2r-bulk-percent" data-o2r-preload-percent><?php echo esc_html( number_format_i18n( $percent, $percent > 0 && $percent < 10 ? 1 : 0 ) . '%' ); ?></span>
			</div>
			<div class="o2r-list">
				<?php
				$rows = array(
					'pending'     => array( __( 'Na fila', 'o2-rocket' ), 'neutral' ),
					'in-progress' => array( __( 'Em andamento', 'o2-rocket' ), 'warning' ),
					'completed'   => array( __( 'Concluídas', 'o2-rocket' ), 'success' ),
					'failed'      => array( __( 'Com falha', 'o2-rocket' ), 'danger' ),
				);
				?>
				<?php foreach ( $rows as $key => $row ) : ?>
					<div class="o2r-list-row">
						<span class="o2r-list-title"><?php echo esc_html( $row[0] ); ?></span>
						<span data-o2r-preload-count="<?php echo esc_attr( $key ); ?>"><?php echo Admin_UI::badge_html( number_format_i18n( (int) $counts[ $key ] ), $row[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado em badge_html(). ?></span>
					</div>
				<?php endforeach; ?>
			</div>
			<p class="o2r-card-foot" data-o2r-preload-status>
				<?php
				if ( $status['running'] ) {
					/* translators: 1: concluídas, 2: total. */
					echo esc_html( sprintf( __( 'Pré-carregando: %1$s de %2$s páginas.', 'o2-rocket' ), number_format_i18n( $done ), number_format_i18n( $total ) ) ) . ' ';
				}

				if ( $status['last_run'] > 0 ) {
					/* translators: %s: tempo decorrido. */
					echo esc_html( sprintf( __( 'Última execução há %s.', 'o2-rocket' ), human_time_diff( (int) $status['last_run'] ) ) );
				} else {
					esc_html_e( 'Aguardando a primeira execução do WP-Cron.', 'o2-rocket' );
				}

				if ( '' !== $status['error'] ) {
					echo ' ' . esc_html( sprintf( /* translators: %s: erro. */ __( 'Último aviso: %s.', 'o2-rocket' ), $status['error'] ) );
				}
				?>
			</p>
			<?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
				<p class="o2r-muted"><?php esc_html_e( 'DISABLE_WP_CRON está ativo: o pré-carregamento só anda se houver um cron real chamando wp-cron.php (ou com esta tela aberta).', 'o2-rocket' ); ?></p>
			<?php endif; ?>
			<div class="o2r-actions">
				<a class="o2r-button o2r-button--primary" href="<?php echo esc_url( Cache_Controls::get_action_url( 'purge_preload' ) ); ?>"><?php esc_html_e( 'Limpar e pré-carregar', 'o2-rocket' ); ?></a>
				<a class="o2r-button" href="<?php echo esc_url( Cache_Controls::get_action_url( 'preload' ) ); ?>"><?php esc_html_e( 'Pré-carregar tudo agora', 'o2-rocket' ); ?></a>
				<span class="o2r-muted"><?php esc_html_e( 'Com esta tela aberta, a fila também avança sem depender de visitas.', 'o2-rocket' ); ?></span>
			</div>
		</div>
		<?php
	}

	public function render_params_section(): void {
		?>
		<p class="o2r-muted">
			<?php esc_html_e( 'Links de campanhas (Google Ads, Facebook, e-mail marketing) trazem parâmetros como ?utm_source=. Eles recebem a página em cache normalmente, sem gerar cópias duplicadas.', 'o2-rocket' ); ?>
		</p>
		<?php
	}

	public function render_server_section(): void {
		$server = Htaccess::get_server();
		$names  = array(
			'apache'    => 'Apache',
			'litespeed' => 'LiteSpeed',
			'nginx'     => 'Nginx',
			'iis'       => 'IIS',
		);
		$raw    = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
		$label  = isset( $names[ $server ] ) ? $names[ $server ] : ( '' !== $raw ? $raw : __( 'não identificado', 'o2-rocket' ) );
		?>
		<p class="o2r-muted">
			<?php
			/* translators: %s: nome do servidor web. */
			echo esc_html( sprintf( __( 'Servidor detectado: %s.', 'o2-rocket' ), $label ) );
			?>
		</p>
		<?php
		if ( Htaccess::is_supported() && ! Htaccess::can_manage() ) {
			printf( '<p class="o2r-muted">%s</p>', esc_html__( 'Multisite: as regras do .htaccess são gerenciadas pelas configurações do site principal da rede.', 'o2-rocket' ) );
			return;
		}

		if ( Htaccess::is_supported() ) {
			$status = $this->htaccess->get_status();

			if ( '' !== $status['error'] ) {
				$block = $this->htaccess->get_block_text();
				?>
				<div class="o2r-notice o2r-notice--warning" role="alert">
					<div class="o2r-notice-message">
						<p>
							<?php
							/* translators: %s: motivo. */
							echo esc_html( sprintf( __( 'As regras não foram gravadas: %s.', 'o2-rocket' ), $status['error'] ) );
							?>
						</p>
						<?php if ( '' !== $block ) : ?>
							<p><?php esc_html_e( 'Copie o bloco abaixo para o início do arquivo .htaccess da raiz do site:', 'o2-rocket' ); ?></p>
						<?php endif; ?>
					</div>
				</div>
				<?php if ( '' !== $block ) : ?>
					<textarea class="form-control font-monospace" rows="10" readonly><?php echo esc_textarea( $block ); ?></textarea>
				<?php endif; ?>
				<?php
			} elseif ( $status['written'] ) {
				echo Admin_UI::badge_html( __( 'Regras gravadas no .htaccess', 'o2-rocket' ), 'success' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado em badge_html().
			}

			return;
		}

		if ( 'nginx' === $server ) {
			?>
			<p class="o2r-muted"><?php esc_html_e( 'O Nginx não lê o .htaccess, então nada é gravado. Para o mesmo efeito, peça à hospedagem para incluir este trecho no bloco server {} do site:', 'o2-rocket' ); ?></p>
			<textarea class="form-control font-monospace" rows="12" readonly><?php echo esc_textarea( Htaccess::get_nginx_snippet() ); ?></textarea>
			<?php
			return;
		}

		printf( '<p class="o2r-muted">%s</p>', esc_html__( 'Este servidor não usa .htaccess: as opções abaixo não têm efeito aqui. O cache de página funciona normalmente.', 'o2-rocket' ) );
	}

	public function render_lifespan( array $args ): void {
		$key      = isset( $args['key'] ) ? (string) $args['key'] : '';
		$unit_key = isset( $args['unit_key'] ) ? (string) $args['unit_key'] : '';
		$id       = isset( $args['label_for'] ) ? (string) $args['label_for'] : 'o2r-' . $key;
		$units    = array(
			'minutes' => __( 'minutos', 'o2-rocket' ),
			'hours'   => __( 'horas', 'o2-rocket' ),
			'days'    => __( 'dias', 'o2-rocket' ),
		);
		$unit     = (string) $this->get_value( $unit_key, Page_Cache::DEFAULT_LIFESPAN_UNIT );
		?>
		<div class="o2r-inline-fields">
			<input
				class="form-control o2r-input-number"
				type="number"
				min="0"
				max="9999"
				step="1"
				id="<?php echo esc_attr( $id ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( $key ) ); ?>"
				value="<?php echo esc_attr( (string) absint( $this->get_value( $key, Page_Cache::DEFAULT_LIFESPAN ) ) ); ?>"
			>
			<select class="form-select o2r-input-unit" name="<?php echo esc_attr( $this->get_field_name( $unit_key ) ); ?>" aria-label="<?php esc_attr_e( 'Unidade', 'o2-rocket' ); ?>">
				<?php foreach ( $units as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $unit, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php
		$this->render_description( $args );
	}

	public function render_select( array $args ): void {
		$key     = isset( $args['key'] ) ? (string) $args['key'] : '';
		$id      = isset( $args['label_for'] ) ? (string) $args['label_for'] : 'o2r-' . $key;
		$choices = isset( $args['choices'] ) && is_array( $args['choices'] ) ? $args['choices'] : array();
		$current = (string) $this->get_value( $key, isset( $args['default'] ) ? $args['default'] : '' );
		?>
		<select class="form-select" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $this->get_field_name( $key ) ); ?>">
			<?php foreach ( $choices as $value => $label ) : ?>
				<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $current, (string) $value ); ?>><?php echo esc_html( (string) $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
		$this->render_description( $args );
	}

	public function render_number( array $args ): void {
		$key = isset( $args['key'] ) ? (string) $args['key'] : '';
		$id  = isset( $args['label_for'] ) ? (string) $args['label_for'] : 'o2r-' . $key;
		?>
		<div class="o2r-inline-fields">
			<input
				class="form-control o2r-input-number"
				type="number"
				id="<?php echo esc_attr( $id ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( $key ) ); ?>"
				value="<?php echo esc_attr( (string) (int) $this->get_value( $key, isset( $args['default'] ) ? $args['default'] : 0 ) ); ?>"
				<?php echo isset( $args['min'] ) ? 'min="' . esc_attr( (string) (int) $args['min'] ) . '"' : ''; ?>
				<?php echo isset( $args['max'] ) ? 'max="' . esc_attr( (string) (int) $args['max'] ) . '"' : ''; ?>
				step="1"
			>
			<?php if ( ! empty( $args['suffix'] ) ) : ?>
				<span class="o2r-muted"><?php echo esc_html( (string) $args['suffix'] ); ?></span>
			<?php endif; ?>
		</div>
		<?php
		$this->render_description( $args );
	}

	public function render_file_optimization_section(): void {
		?>
		<p class="o2r-muted">
			<?php esc_html_e( 'Ajustes aplicados apenas no front-end do site. O painel, a tela de login, o Personalizador e os editores visuais não são alterados.', 'o2-rocket' ); ?>
		</p>
		<?php
	}

	public function render_css_delivery_section(): void {
		?>
		<p class="o2r-muted">
			<?php esc_html_e( 'Folhas de estilo bloqueiam a renderização: a página só aparece depois de baixar todas. Temas e construtores carregam muito CSS que a página não usa.', 'o2-rocket' ); ?>
		</p>
		<?php
	}

	public function render_delay_js_section(): void {
		?>
		<p class="o2r-muted">
			<?php esc_html_e( 'O maior ganho no PageSpeed: nenhum script de análise, chat, anúncio ou efeito disputa o carregamento da página. O conteúdo aparece igual; os scripts rodam assim que o visitante interage.', 'o2-rocket' ); ?>
		</p>
		<?php
		$items = $this->get_delay_js()->get_active_items();

		if ( empty( $items ) ) {
			return;
		}
		?>
		<p class="o2r-muted">
			<?php
			/* translators: %s: lista de plugins/temas. */
			echo esc_html( sprintf( __( 'Exclusões conhecidas para o que está ativo neste site: %s.', 'o2-rocket' ), implode( ', ', array_unique( array_column( $items, 'title' ) ) ) ) );
			?>
		</p>
		<?php
	}

	public function render_checkboxes( array $args ): void {
		$key     = isset( $args['key'] ) ? (string) $args['key'] : '';
		$choices = isset( $args['choices'] ) && is_array( $args['choices'] ) ? $args['choices'] : array();
		$current = array_map( 'strval', (array) $this->get_value( $key, array() ) );
		?>
		<div class="o2r-checkbox-grid">
			<?php foreach ( $choices as $value => $label ) : ?>
				<?php $id = 'o2r-' . $key . '-' . sanitize_key( (string) $value ); ?>
				<div class="form-check">
					<input
						class="form-check-input"
						type="checkbox"
						id="<?php echo esc_attr( $id ); ?>"
						name="<?php echo esc_attr( $this->get_field_name( $key ) . '[]' ); ?>"
						value="<?php echo esc_attr( (string) $value ); ?>"
						<?php checked( in_array( (string) $value, $current, true ) ); ?>
					>
					<label class="form-check-label" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( (string) $label ); ?></label>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		$this->render_description( $args );
	}

	// Trechos de URL ou handles, um por linha.
	public static function sanitize_file_patterns( $value ): array {
		$lines    = is_array( $value ) ? $value : preg_split( '/\r\n|\r|\n/', is_scalar( $value ) ? (string) $value : '' );
		$patterns = array();

		foreach ( (array) $lines as $line ) {
			$line = is_scalar( $line ) ? trim( wp_strip_all_tags( (string) $line ) ) : '';
			$line = (string) preg_replace( '/[^\x21-\x7E]/', '', $line );

			if ( strlen( $line ) >= 3 ) {
				$patterns[] = substr( $line, 0, 300 );
			}
		}

		return array_slice( array_values( array_unique( $patterns ) ), 0, 500 );
	}

	private function get_delay_js(): Delay_Js {
		if ( null === $this->delay_js ) {
			$this->delay_js = new Delay_Js();
		}

		return $this->delay_js;
	}

	public function render_media_section(): void {
		?>
		<p class="o2r-muted">
			<?php esc_html_e( 'Usa a API nativa IntersectionObserver do navegador (script de cerca de 3 KB, sem jQuery). Sem JavaScript, uma cópia original de cada imagem e iframe é exibida via <noscript>.', 'o2-rocket' ); ?>
		</p>
		<?php
	}

	public function render_fonts_section(): void {
		?>
		<p class="o2r-muted">
			<?php esc_html_e( 'Fontes atrasam o texto (FOUT/FOIT) e cada domínio externo custa uma conexão nova antes do primeiro byte.', 'o2-rocket' ); ?>
		</p>
		<?php
	}

	public function render_cdn_section(): void {
		?>
		<p class="o2r-muted">
			<?php esc_html_e( 'Use o endereço que a sua CDN (Bunny, KeyCDN, CloudFront, StackPath etc.) criou apontando para este site. Com a Cloudflare na frente do domínio inteiro não é preciso: use a aba Integrações.', 'o2-rocket' ); ?>
		</p>
		<?php
	}

	public function render_cloudflare_section(): void {
		?>
		<p class="o2r-muted">
			<?php esc_html_e( 'Pela API oficial da Cloudflare, com um token só deste site (sem e-mail nem chave global).', 'o2-rocket' ); ?>
		</p>
		<?php
		$cloudflare = new Cloudflare();

		if ( ! $cloudflare->is_enabled() ) {
			return;
		}
		?>
		<div class="o2r-actions">
			<a class="o2r-button" href="<?php echo esc_url( Cloudflare::get_action_url( 'test' ) ); ?>"><?php esc_html_e( 'Testar a conexão', 'o2-rocket' ); ?></a>
			<a class="o2r-button" href="<?php echo esc_url( Cloudflare::get_action_url( 'purge' ) ); ?>"><?php esc_html_e( 'Limpar o cache da Cloudflare', 'o2-rocket' ); ?></a>
			<a class="o2r-button" href="<?php echo esc_url( Cloudflare::get_action_url( 'devmode_on' ) ); ?>"><?php esc_html_e( 'Ligar o modo de desenvolvimento', 'o2-rocket' ); ?></a>
			<a class="o2r-button" href="<?php echo esc_url( Cloudflare::get_action_url( 'devmode_off' ) ); ?>"><?php esc_html_e( 'Desligar o modo de desenvolvimento', 'o2-rocket' ); ?></a>
		</div>
		<?php
	}

	public function render_varnish_section(): void {
		$hosting = new Hosting();

		if ( (bool) apply_filters( 'o2_rocket_varnish_enabled', false ) ) {
			?>
			<p class="o2r-muted">
				<?php
				/* translators: %s: nome da hospedagem. */
				echo esc_html( sprintf( __( 'Varnish da hospedagem %s detectado: a limpeza já é automática.', 'o2-rocket' ), $hosting->get_host_name() ) );
				?>
			</p>
			<?php
		}
	}

	public function render_heartbeat_section(): void {
		?>
		<p class="o2r-muted">
			<?php esc_html_e( 'Com o painel aberto, o WordPress chama o admin-ajax.php a cada 15 a 60 segundos. Em hospedagens compartilhadas isso consome CPU à toa.', 'o2-rocket' ); ?>
		</p>
		<?php
	}

	public function render_database_section(): void {
		$cleaner = new Cleaner();
		$counts  = $cleaner->count_all();
		$last    = get_option( Cleaner::LAST_OPTION, array() );
		$labels  = array(
			Cleaner::SETTING_REVISIONS          => __( 'Revisões', 'o2-rocket' ),
			Cleaner::SETTING_AUTO_DRAFTS        => __( 'Rascunhos automáticos', 'o2-rocket' ),
			Cleaner::SETTING_TRASHED_POSTS      => __( 'Posts na lixeira', 'o2-rocket' ),
			Cleaner::SETTING_SPAM_COMMENTS      => __( 'Comentários de spam', 'o2-rocket' ),
			Cleaner::SETTING_TRASHED_COMMENTS   => __( 'Comentários na lixeira', 'o2-rocket' ),
			Cleaner::SETTING_EXPIRED_TRANSIENTS => __( 'Transients expirados', 'o2-rocket' ),
			Cleaner::SETTING_ALL_TRANSIENTS     => __( 'Transients (todos)', 'o2-rocket' ),
			Cleaner::SETTING_ORPHAN_META        => __( 'Metadados órfãos', 'o2-rocket' ),
			Cleaner::SETTING_OPTIMIZE_TABLES    => Cleaner::is_sqlite() ? __( 'Banco a compactar', 'o2-rocket' ) : __( 'Tabelas com espaço livre', 'o2-rocket' ),
		);
		?>
		<p class="o2r-muted">
			<?php esc_html_e( 'Faça um backup antes da primeira limpeza. Itens marcados abaixo entram na limpeza manual e na automática.', 'o2-rocket' ); ?>
		</p>
		<div
			class="o2r-db"
			data-o2r-db
			data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			data-action="<?php echo esc_attr( Cleaner::AJAX_ACTION ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( Cleaner::AJAX_ACTION ) ); ?>"
			data-label-running="<?php esc_attr_e( 'Limpando…', 'o2-rocket' ); ?>"
			data-label-done="<?php /* translators: %s: quantidade de itens. */ esc_attr_e( 'Concluído: %s itens removidos.', 'o2-rocket' ); ?>"
			data-label-error="<?php esc_attr_e( 'A limpeza foi interrompida. Tente novamente.', 'o2-rocket' ); ?>"
		>
			<div class="o2r-list">
				<?php foreach ( $labels as $key => $label ) : ?>
					<div class="o2r-list-row">
						<span class="o2r-list-title"><?php echo esc_html( $label ); ?></span>
						<span data-o2r-db-count="<?php echo esc_attr( $key ); ?>"><?php echo Admin_UI::badge_html( number_format_i18n( (int) $counts[ $key ] ), $counts[ $key ] > 0 ? 'warning' : 'success' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado em badge_html(). ?></span>
					</div>
				<?php endforeach; ?>
			</div>
			<div class="o2r-actions">
				<button type="button" class="o2r-button" data-o2r-db-run><?php esc_html_e( 'Limpar agora', 'o2-rocket' ); ?></button>
				<span class="o2r-muted" data-o2r-db-status>
					<?php
					if ( is_array( $last ) && ! empty( $last['time'] ) ) {
						/* translators: 1: tempo decorrido, 2: itens removidos. */
						echo esc_html( sprintf( __( 'Última limpeza há %1$s (%2$s itens).', 'o2-rocket' ), human_time_diff( (int) $last['time'] ), number_format_i18n( (int) $last['deleted'] ) ) );
					} else {
						esc_html_e( 'Usa os itens marcados e salvos abaixo.', 'o2-rocket' );
					}
					?>
				</span>
			</div>
		</div>
		<?php
	}

	public function render_lcp_section(): void {
		?>
		<p class="o2r-muted">
			<?php esc_html_e( 'A LCP (Largest Contentful Paint) é o tempo até a maior imagem ou bloco de texto aparecer, uma das métricas do PageSpeed e do Core Web Vitals.', 'o2-rocket' ); ?>
		</p>
		<?php
	}

	public function render_webp_section(): void {
		?>
		<p class="o2r-muted">
			<?php esc_html_e( 'Os arquivos WebP ficam ao lado dos originais (imagem.jpg.webp). Os originais nunca são alterados nem apagados.', 'o2-rocket' ); ?>
		</p>
		<?php
	}

	public function render_exclusions_section(): void {
		?>
		<p class="o2r-muted">
			<?php esc_html_e( 'Carrinho, checkout e minha conta (cart, checkout, my-account, carrinho, finalizar-compra, minha-conta), buscas, 404, páginas com senha, URLs com parâmetros desconhecidos e visitantes logados, com comentário pendente ou com itens no carrinho já ficam fora do cache automaticamente.', 'o2-rocket' ); ?>
		</p>
		<?php
	}

	public function render_toggle( array $args ): void {
		$key = isset( $args['key'] ) ? (string) $args['key'] : '';
		$id  = isset( $args['label_for'] ) ? (string) $args['label_for'] : 'o2r-' . $key;
		?>
		<div class="form-check form-switch">
			<input
				class="form-check-input"
				type="checkbox"
				role="switch"
				id="<?php echo esc_attr( $id ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( $key ) ); ?>"
				value="1"
				<?php checked( rest_sanitize_boolean( $this->get_value( $key, false ) ) ); ?>
			>
			<?php if ( ! empty( $args['label'] ) ) : ?>
				<label class="form-check-label" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $args['label'] ); ?></label>
			<?php endif; ?>
		</div>
		<?php
		$this->render_description( $args );
	}

	public function render_textarea( array $args ): void {
		$key   = isset( $args['key'] ) ? (string) $args['key'] : '';
		$id    = isset( $args['label_for'] ) ? (string) $args['label_for'] : 'o2r-' . $key;
		$value = $this->get_value( $key, array() );
		$text  = is_array( $value ) ? implode( "\n", array_map( 'strval', $value ) ) : (string) $value;
		?>
		<textarea
			class="form-control font-monospace"
			id="<?php echo esc_attr( $id ); ?>"
			name="<?php echo esc_attr( $this->get_field_name( $key ) ); ?>"
			rows="<?php echo esc_attr( isset( $args['rows'] ) ? (string) absint( $args['rows'] ) : '6' ); ?>"
			placeholder="<?php echo esc_attr( isset( $args['placeholder'] ) ? (string) $args['placeholder'] : '' ); ?>"
			spellcheck="false"
			autocomplete="off"
		><?php echo esc_textarea( $text ); ?></textarea>
		<?php
		$this->render_description( $args );
	}

	public function render_text( array $args ): void {
		$key   = isset( $args['key'] ) ? (string) $args['key'] : '';
		$id    = isset( $args['label_for'] ) ? (string) $args['label_for'] : 'o2r-' . $key;
		$value = $this->get_value( $key, '' );
		?>
		<input
			class="form-control font-monospace"
			type="<?php echo esc_attr( isset( $args['input_type'] ) && 'password' === $args['input_type'] ? 'password' : 'text' ); ?>"
			id="<?php echo esc_attr( $id ); ?>"
			name="<?php echo esc_attr( $this->get_field_name( $key ) ); ?>"
			value="<?php echo esc_attr( is_scalar( $value ) ? (string) $value : '' ); ?>"
			placeholder="<?php echo esc_attr( isset( $args['placeholder'] ) ? (string) $args['placeholder'] : '' ); ?>"
			spellcheck="false"
			autocomplete="off"
		>
		<?php
		$this->render_description( $args );
	}

	private function render_description( array $args ): void {
		if ( ! empty( $args['description'] ) ) {
			printf( '<div class="form-text">%s</div>', esc_html( $args['description'] ) );
		}
	}

	private function get_field_name( string $key ): string {
		return Admin_UI::OPTION_NAME . '[' . $key . ']';
	}

	// Valor salvo; sem valor salvo, o padrão do schema (o $default só vale para chaves fora do schema).
	private function get_value( string $key, $default ) {
		if ( null === $this->values ) {
			$stored       = get_option( Admin_UI::OPTION_NAME, array() );
			$this->values = is_array( $stored ) ? $stored : array();
		}

		if ( array_key_exists( $key, $this->values ) ) {
			return $this->values[ $key ];
		}

		if ( null === $this->defaults ) {
			$this->defaults = array();

			foreach ( (array) apply_filters( 'o2_rocket_settings_schema', array() ) as $name => $field ) {
				if ( is_array( $field ) && array_key_exists( 'default', $field ) ) {
					$this->defaults[ $name ] = $field['default'];
				}
			}
		}

		return array_key_exists( $key, $this->defaults ) ? $this->defaults[ $key ] : $default;
	}
}
