<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Admin;

use O2Rocket\Cache\Cache_Controls;
use O2Rocket\Cache\Drop_In_Manager;
use O2Rocket\Cache\Page_Cache;
use O2Rocket\Media\Image_Optimizer;
use O2Rocket\Media\Lazy_Load;
use O2Rocket\Optimization\File_Optimizer;
use O2Rocket\Preload\Link_Prefetch;
use O2Rocket\Preload\Preloader;
use O2Rocket\Server\Htaccess;

defined( 'ABSPATH' ) || exit;

final class Settings {

	const TAB_PAGE_CACHE        = 'page-cache';
	const TAB_FILE_OPTIMIZATION = 'file-optimization';
	const TAB_MEDIA             = 'media';

	private Drop_In_Manager $drop_in;

	private Preloader $preloader;

	private Htaccess $htaccess;

	private ?array $values = null;

	private ?array $defaults = null;

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

		return $schema;
	}

	public function register_fields( Admin_UI $ui ): void {
		$this->register_page_cache_fields( $ui );
		$this->register_file_optimization_fields( $ui );
		$this->register_media_fields( $ui );
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
				'description' => __( 'Vale para as imagens do conteúdo dos posts e as imagens destacadas. A primeira imagem da página, a primeira imagem do conteúdo do post e as imagens que o WordPress marca como prioritárias (LCP) carregam sempre de imediato.', 'o2-rocket' ),
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
				'label'       => __( 'Carregar vídeos do YouTube/Vimeo, mapas e outros iframes do conteúdo sob demanda', 'o2-rocket' ),
				'description' => __( 'Iframes que apontam para o painel do WordPress e os que estão dentro de <noscript> não são alterados.', 'o2-rocket' ),
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
				'label'       => __( 'Gerar a versão WebP lossless ao enviar uma imagem para a biblioteca', 'o2-rocket' ),
				'description' => __( 'Converte o arquivo enviado e todos os tamanhos gerados pelo WordPress. O envio fica alguns segundos mais lento em imagens grandes.', 'o2-rocket' ),
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

	private function register_file_optimization_fields( Admin_UI $ui ): void {
		$page = $ui->get_settings_page( self::TAB_FILE_OPTIMIZATION );

		add_settings_section(
			'o2r_file_delivery',
			__( 'Entrega de CSS e JavaScript', 'o2-rocket' ),
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

		add_settings_field(
			File_Optimizer::SETTING_DEFER_JS,
			__( 'Carregar JavaScript de forma adiada (Defer)', 'o2-rocket' ),
			array( $this, 'render_toggle' ),
			$page,
			'o2r_file_delivery',
			array(
				'label_for'   => 'o2r-defer-js',
				'key'         => File_Optimizer::SETTING_DEFER_JS,
				'label'       => __( 'Executar os scripts só depois que o HTML for lido', 'o2-rocket' ),
				'description' => __( 'O jQuery nunca é adiado, esteja ele no WordPress ou em uma CDN. Scripts com código inline anexado, e todos os scripts dos quais eles dependem, também continuam síncronos.', 'o2-rocket' ),
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
				'description' => __( 'Usa o sitemap do WordPress, do Yoast, do Rank Math, do SEOPress, do All in One SEO ou do The SEO Framework (sem sitemap, lista os conteúdos publicados). Uma página por vez, em lotes a cada minuto pelo WP-Cron. Páginas limpas ou vencidas voltam para a fila.', 'o2-rocket' ),
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
	}

	public function render_page_cache_section(): void {
		?>
		<p class="o2r-muted">
			<?php esc_html_e( 'Gera uma cópia em HTML de cada página visitada e a entrega antes de o WordPress carregar, sem PHP nem banco de dados.', 'o2-rocket' ); ?>
		</p>
		<?php
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

		<?php if ( '' !== $status['error'] ) : ?>
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
		$total   = max( 0, (int) $status['total'] );
		$done    = (int) $counts['completed'] + (int) $counts['failed'];
		$percent = $total > 0 ? min( 100, round( $done * 100 / $total, 1 ) ) : 0;
		?>
		<div
			class="o2r-preload"
			data-o2r-preload
			data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			data-action="<?php echo esc_attr( Preloader::AJAX_ACTION ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( Preloader::AJAX_ACTION ) ); ?>"
			data-running="<?php echo esc_attr( $status['running'] ? '1' : '0' ); ?>"
			data-label-progress="<?php /* translators: 1: concluídas, 2: total. */ esc_attr_e( '%1$s de %2$s URLs processadas', 'o2-rocket' ); ?>"
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

	public function render_media_section(): void {
		?>
		<p class="o2r-muted">
			<?php esc_html_e( 'Usa a API nativa IntersectionObserver do navegador (script com menos de 2 KB, sem jQuery). Sem JavaScript, uma cópia original de cada mídia é exibida via <noscript>.', 'o2-rocket' ); ?>
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
