<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Admin;

use O2Rocket\Cache\Drop_In_Manager;
use O2Rocket\Cache\Page_Cache;
use O2Rocket\Media\Image_Optimizer;
use O2Rocket\Media\Lazy_Load;
use O2Rocket\Optimization\File_Optimizer;

defined( 'ABSPATH' ) || exit;

final class Settings {

	const TAB_PAGE_CACHE        = 'page-cache';
	const TAB_FILE_OPTIMIZATION = 'file-optimization';
	const TAB_MEDIA             = 'media';

	private Drop_In_Manager $drop_in;

	private ?array $values = null;

	public function __construct( Drop_In_Manager $drop_in ) {
		$this->drop_in = $drop_in;
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

		$schema[ Page_Cache::SETTING_EXCLUDED_URIS ] = array(
			'tab'               => self::TAB_PAGE_CACHE,
			'type'              => 'lines',
			'default'           => array(),
			'sanitize_callback' => array( Page_Cache::class, 'sanitize_uri_patterns' ),
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
		<?php
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
			<?php esc_html_e( 'Carrinho, checkout e minha conta (cart, checkout, my-account, carrinho, finalizar-compra, minha-conta), buscas, 404, páginas com senha e visitantes logados ou com itens no carrinho já ficam fora do cache automaticamente.', 'o2-rocket' ); ?>
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

	private function get_value( string $key, $default ) {
		if ( null === $this->values ) {
			$stored       = get_option( Admin_UI::OPTION_NAME, array() );
			$this->values = is_array( $stored ) ? $stored : array();
		}

		return array_key_exists( $key, $this->values ) ? $this->values[ $key ] : $default;
	}
}
