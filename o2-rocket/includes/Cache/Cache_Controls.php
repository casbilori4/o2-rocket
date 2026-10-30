<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Cache;

use O2Rocket\Admin\Brand;
use O2Rocket\Preload\Preloader;

defined( 'ABSPATH' ) || exit;

// "Limpar cache" e "Limpar e pré-carregar" na barra do admin, na lista de posts e no painel; aviso do resultado
// (com o andamento do pré-carregamento) na tela seguinte, no painel ou no site.
final class Cache_Controls {

	const ACTION        = 'o2_rocket_purge';
	const NOTICE_PREFIX = 'o2_rocket_notice_';

	private Page_Cache $page_cache;

	private Preloader $preloader;

	public function __construct( Page_Cache $page_cache, Preloader $preloader ) {
		$this->page_cache = $page_cache;
		$this->preloader  = $preloader;
	}

	public function register_hooks(): void {
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_menu' ), 90 );
		add_action( 'wp_enqueue_scripts', array( $this, 'add_admin_bar_style' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'add_admin_bar_style' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_action' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'wp_footer', array( $this, 'render_front_notice' ) );
		add_action( 'o2_rocket_admin_alerts', array( $this, 'render_health_alert' ) );
		add_action( 'o2_rocket_admin_alerts', array( $this, 'render_cron_alert' ) );
		add_filter( 'post_row_actions', array( $this, 'filter_row_actions' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'filter_row_actions' ), 10, 2 );
	}

	public static function get_capability(): string {
		return (string) apply_filters( 'o2_rocket_capability', 'manage_options' );
	}

	// Escopos: all (tudo), url (uma página), post (post e relacionados), preload (pré-carregar), purge_preload,
	// purge_preload_url e purge_preload_post (limpar e já pré-carregar), repair (drop-in), opcache.
	// O OPcache é do servidor inteiro: só administradores.
	public static function can_reset_opcache(): bool {
		return function_exists( 'opcache_reset' ) && filter_var( ini_get( 'opcache.enable' ), FILTER_VALIDATE_BOOLEAN ) && current_user_can( 'manage_options' );
	}

	public static function get_action_url( string $scope, array $args = array() ): string {
		$url = add_query_arg(
			array_merge(
				array(
					'action' => self::ACTION,
					'scope'  => $scope,
				),
				array_map( 'rawurlencode', $args )
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, self::ACTION . '_' . $scope );
	}

	// Mesma cor dos ícones da barra (dashicons) no painel e no site, inclusive no hover e no foco.
	public function add_admin_bar_style(): void {
		if ( ! is_admin_bar_showing() || ! current_user_can( self::get_capability() ) ) {
			return;
		}

		$item = '#wpadminbar #wp-admin-bar-o2-rocket > .ab-item';
		$css  = $item . ' .o2r-ab-icon{float:left;width:20px;height:20px;margin:6px 6px 0 0;padding:0;color:rgba(240,246,252,.6)}'
			. $item . ' .o2r-ab-icon:before{content:none}'
			. $item . ' .o2r-ab-icon svg{display:block;width:20px;height:20px}'
			. '#wpadminbar #wp-admin-bar-o2-rocket:hover > .ab-item .o2r-ab-icon,#wpadminbar #wp-admin-bar-o2-rocket.hover > .ab-item .o2r-ab-icon,' . $item . ':focus .o2r-ab-icon{color:#72aee6}'
			. '@media screen and (max-width:782px){' . $item . ' .o2r-ab-icon{width:52px;height:46px;margin:0}' . $item . ' .o2r-ab-icon svg{width:26px;height:26px;margin:10px auto 0}}';

		wp_add_inline_style( 'admin-bar', $css );
	}

	// Topo de todas as abas do painel: o cache ligado e parado é o pior estado possível (parece funcionar, mas não serve nada).
	public function render_health_alert(): void {
		$health = $this->page_cache->get_health();

		if ( '' === $health['code'] ) {
			return;
		}
		?>
		<div class="o2r-notice o2r-notice--danger o2r-health-alert" role="alert">
			<div class="o2r-notice-message">
				<p>
					<strong><?php esc_html_e( 'O cache de página NÃO está funcionando:', 'o2-rocket' ); ?></strong>
					<?php echo esc_html( $health['message'] . '.' ); ?>
				</p>
				<p>
					<?php
					if ( 'wp_cache' === $health['code'] ) {
						esc_html_e( 'Se o wp-config.php não puder ser gravado, adicione logo depois da linha <?php: define( \'WP_CACHE\', true );', 'o2-rocket' );
					} elseif ( 'conflict' === $health['code'] ) {
						esc_html_e( 'Desative o outro plugin de cache e clique em Corrigir agora para instalar o arquivo do O² Rocket.', 'o2-rocket' );
					} else {
						esc_html_e( 'O O² Rocket tenta corrigir sozinho; se continuar, confira se a pasta wp-content permite gravação.', 'o2-rocket' );
					}
					?>
				</p>
			</div>
			<a class="o2r-button o2r-button--danger" href="<?php echo esc_url( self::get_action_url( 'repair' ) ); ?>"><?php esc_html_e( 'Corrigir agora', 'o2-rocket' ); ?></a>
		</div>
		<?php
	}

	// WP-Cron parado com fila pendente: o comando de cron do servidor e a linha do wp-config.
	public function render_cron_alert(): void {
		$stall = $this->preloader->get_stall();

		if ( empty( $stall ) ) {
			return;
		}

		$command = 'wget -q -O - "' . site_url( 'wp-cron.php?doing_wp_cron' ) . '" >/dev/null 2>&1';
		?>
		<div class="o2r-notice o2r-notice--warning o2r-cron-alert" role="alert">
			<div class="o2r-notice-message">
				<p>
					<strong><?php esc_html_e( 'O pré-carregamento está parado: o WP-Cron não está rodando.', 'o2-rocket' ); ?></strong>
					<?php
					echo esc_html(
						$stall['since'] > 0
							/* translators: 1: tempo, 2: quantidade de páginas. */
							? sprintf( _n( 'A última execução foi há %1$s e %2$s página espera na fila.', 'A última execução foi há %1$s e %2$s páginas esperam na fila.', $stall['pending'], 'o2-rocket' ), human_time_diff( $stall['since'] ), number_format_i18n( $stall['pending'] ) )
							/* translators: %s: quantidade de páginas. */
							: sprintf( _n( 'Ele ainda não rodou e %s página espera na fila.', 'Ele ainda não rodou e %s páginas esperam na fila.', $stall['pending'], 'o2-rocket' ), number_format_i18n( $stall['pending'] ) )
					);
					?>
				</p>
				<p><?php esc_html_e( 'O WP-Cron só roda quando o site recebe visitas e algumas hospedagens o bloqueiam. Crie uma tarefa no cPanel (Cron Jobs → a cada 5 minutos, */5 * * * *) com o comando:', 'o2-rocket' ); ?></p>
				<p><code class="o2r-code-line"><?php echo esc_html( $command ); ?></code></p>
				<p>
					<?php
					if ( $stall['disabled'] ) {
						esc_html_e( 'O wp-config.php já tem DISABLE_WP_CRON ativo: sem essa tarefa, nada agendado roda no site.', 'o2-rocket' );
					} else {
						esc_html_e( 'Depois, adicione ao wp-config.php (antes da linha "That\'s all, stop editing!"), para as visitas não dispararem o WP-Cron de novo:', 'o2-rocket' );
						echo ' <code>define( \'DISABLE_WP_CRON\', true );</code>';
					}
					?>
				</p>
				<p class="o2r-muted"><?php esc_html_e( 'Enquanto isso, a fila avança um pouco sempre que um administrador abre o painel.', 'o2-rocket' ); ?></p>
			</div>
		</div>
		<?php
	}

	public function add_admin_bar_menu( $bar ): void {
		if ( ! $bar instanceof \WP_Admin_Bar || ! current_user_can( self::get_capability() ) || ( is_multisite() && is_network_admin() ) ) {
			return;
		}

		$bar->add_node(
			array(
				'id'    => 'o2-rocket',
				'title' => '<span class="ab-icon o2r-ab-icon" aria-hidden="true">' . Brand::icon() . '</span><span class="ab-label">' . esc_html__( 'O² Rocket', 'o2-rocket' ) . '</span>',
				'href'  => admin_url( 'admin.php?page=o2-rocket' ),
			)
		);

		$bar->add_node(
			array(
				'parent' => 'o2-rocket',
				'id'     => 'o2-rocket-purge-all',
				'title'  => esc_html__( 'Limpar todo o cache', 'o2-rocket' ),
				'href'   => self::get_action_url( 'all' ),
			)
		);

		$preload = $this->preloader->is_enabled();

		if ( $preload ) {
			$bar->add_node(
				array(
					'parent' => 'o2-rocket',
					'id'     => 'o2-rocket-purge-preload',
					'title'  => esc_html__( 'Limpar e pré-carregar', 'o2-rocket' ),
					'href'   => self::get_action_url( 'purge_preload' ),
				)
			);
		}

		$current = $this->get_current_page_url();

		if ( '' !== $current ) {
			$bar->add_node(
				array(
					'parent' => 'o2-rocket',
					'id'     => 'o2-rocket-purge-url',
					'title'  => esc_html__( 'Limpar o cache desta página', 'o2-rocket' ),
					'href'   => self::get_action_url( 'url', array( 'url' => $current ) ),
				)
			);

			if ( $preload ) {
				$bar->add_node(
					array(
						'parent' => 'o2-rocket',
						'id'     => 'o2-rocket-purge-preload-url',
						'title'  => esc_html__( 'Limpar e pré-carregar esta página', 'o2-rocket' ),
						'href'   => self::get_action_url( 'purge_preload_url', array( 'url' => $current ) ),
					)
				);
			}
		}

		$post_id = $this->get_edited_post_id();

		if ( $post_id > 0 ) {
			$bar->add_node(
				array(
					'parent' => 'o2-rocket',
					'id'     => 'o2-rocket-purge-post',
					'title'  => esc_html__( 'Limpar o cache deste conteúdo', 'o2-rocket' ),
					'href'   => self::get_action_url( 'post', array( 'post' => (string) $post_id ) ),
				)
			);
		}

		if ( $preload ) {
			$bar->add_node(
				array(
					'parent' => 'o2-rocket',
					'id'     => 'o2-rocket-preload',
					'title'  => esc_html__( 'Pré-carregar o cache', 'o2-rocket' ),
					'href'   => self::get_action_url( 'preload' ),
				)
			);
		}

		if ( self::can_reset_opcache() ) {
			$bar->add_node(
				array(
					'parent' => 'o2-rocket',
					'id'     => 'o2-rocket-opcache',
					'title'  => esc_html__( 'Limpar o OPcache', 'o2-rocket' ),
					'href'   => self::get_action_url( 'opcache' ),
				)
			);
		}

		$bar->add_node(
			array(
				'parent' => 'o2-rocket',
				'id'     => 'o2-rocket-settings',
				'title'  => esc_html__( 'Configurações', 'o2-rocket' ),
				'href'   => admin_url( 'admin.php?page=o2-rocket' ),
			)
		);
	}

	public function handle_action(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- o nonce é conferido logo abaixo, com o escopo no nome da ação.
		$scope = isset( $_GET['scope'] ) ? sanitize_key( wp_unslash( $_GET['scope'] ) ) : 'all';

		check_admin_referer( self::ACTION . '_' . $scope );

		if ( ! current_user_can( self::get_capability() ) ) {
			wp_die( esc_html__( 'Você não tem permissão para limpar o cache.', 'o2-rocket' ), '', array( 'response' => 403 ) );
		}

		$progress = false;

		switch ( $scope ) {
			case 'purge_preload':
				$deleted = $this->page_cache->purge_all();

				do_action( 'o2_rocket_manual_purge_all' );

				$progress = $this->preloader->start();
				$message  = $deleted ? __( 'Todo o cache foi limpo.', 'o2-rocket' ) : __( 'Parte do cache não pôde ser apagada (verifique as permissões da pasta wp-content/cache).', 'o2-rocket' );
				$message .= ' ' . ( $progress ? __( 'Pré-carregando agora: a home primeiro, depois o menu principal e as páginas que estavam em cache.', 'o2-rocket' ) : __( 'O pré-carregamento está desligado ou não pôde ser iniciado.', 'o2-rocket' ) );
				break;

			case 'purge_preload_url':
				$url = isset( $_GET['url'] ) ? esc_url_raw( wp_unslash( $_GET['url'] ) ) : '';

				if ( ! $this->is_own_url( $url ) || ! $this->page_cache->purge_url( $url ) ) {
					$message = __( 'Não foi possível limpar o cache desta página.', 'o2-rocket' );
					break;
				}

				$progress = $this->preloader->preload_purged( array( $url ) );
				$message  = $progress ? __( 'Cache desta página limpo; ela está sendo gerada de novo.', 'o2-rocket' ) : __( 'Cache desta página limpo.', 'o2-rocket' );
				break;

			case 'purge_preload_post':
				$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;

				$this->page_cache->purge_post( $post_id );

				$progress = 'publish' === get_post_status( $post_id ) && $this->preloader->preload_purged( array( (string) get_permalink( $post_id ) ) );
				$message  = $progress ? __( 'Cache do conteúdo e das páginas relacionadas limpo; pré-carregando agora.', 'o2-rocket' ) : __( 'Cache do conteúdo e das páginas relacionadas limpo.', 'o2-rocket' );
				break;

			case 'url':
				$url     = isset( $_GET['url'] ) ? esc_url_raw( wp_unslash( $_GET['url'] ) ) : '';
				$message = $this->is_own_url( $url ) && $this->page_cache->purge_url( $url )
					? __( 'Cache desta página limpo.', 'o2-rocket' )
					: __( 'Não foi possível limpar o cache desta página.', 'o2-rocket' );
				break;

			case 'post':
				$this->page_cache->purge_post( isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0 );
				$message = __( 'Cache do conteúdo e das páginas relacionadas limpo.', 'o2-rocket' );
				break;

			// Código PHP atualizado por FTP/deploy que o OPcache ainda não percebeu.
			case 'opcache':
				$message = self::can_reset_opcache() && opcache_reset()
					? __( 'OPcache limpo.', 'o2-rocket' )
					: __( 'O OPcache não pôde ser limpo (desligado ou restrito pela configuração do PHP).', 'o2-rocket' );
				break;

			case 'repair':
				$message = $this->page_cache->repair() && '' === $this->page_cache->get_health_problem()
					? __( 'Cache de página corrigido: o advanced-cache.php e o WP_CACHE estão no lugar.', 'o2-rocket' )
					/* translators: %s: motivo. */
					: sprintf( __( 'Ainda não foi possível corrigir: %s.', 'o2-rocket' ), $this->page_cache->get_health_problem() );
				break;

			case 'preload':
				$progress = $this->preloader->start();
				$message  = $progress
					? __( 'Pré-carregamento iniciado. As páginas serão geradas em segundo plano.', 'o2-rocket' )
					: __( 'O pré-carregamento está desligado ou não pôde ser iniciado.', 'o2-rocket' );
				break;

			default:
				$deleted = $this->page_cache->purge_all();

				// Arquivos otimizados (CSS/JS minificados, fontes...) também saem na limpeza manual.
				do_action( 'o2_rocket_manual_purge_all' );

				$message = $deleted ? __( 'Todo o cache foi limpo.', 'o2-rocket' ) : __( 'Parte do cache não pôde ser apagada (verifique as permissões da pasta wp-content/cache).', 'o2-rocket' );
				break;
		}

		self::add_notice( $message, $progress );

		$redirect = wp_get_referer();

		wp_safe_redirect( $redirect ? $redirect : admin_url( 'admin.php?page=o2-rocket' ) );
		exit;
	}

	public function filter_row_actions( $actions, $post ) {
		if ( ! is_array( $actions ) || ! $post instanceof \WP_Post || 'publish' !== $post->post_status || ! is_post_type_viewable( $post->post_type ) || ! current_user_can( self::get_capability() ) || ! $this->page_cache->is_enabled() ) {
			return $actions;
		}

		$actions['o2_rocket_purge'] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( self::get_action_url( 'post', array( 'post' => (string) $post->ID ) ) ),
			esc_html__( 'Limpar cache', 'o2-rocket' )
		);

		if ( $this->preloader->is_enabled() ) {
			$actions['o2_rocket_purge_preload'] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( self::get_action_url( 'purge_preload_post', array( 'post' => (string) $post->ID ) ) ),
				esc_html__( 'Limpar e pré-carregar', 'o2-rocket' )
			);
		}

		return $actions;
	}

	public function render_notice(): void {
		$notice = self::take_notice();

		if ( null === $notice ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p><strong>%1$s</strong> %2$s%3$s</p></div>',
			esc_html__( 'O² Rocket:', 'o2-rocket' ),
			esc_html( $notice['message'] ),
			$notice['progress'] ? ' ' . $this->get_progress_html() : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado em get_progress_html().
		);
	}

	// Ações disparadas pela barra do admin no site voltam para a página: o aviso aparece num canto da tela.
	public function render_front_notice(): void {
		if ( is_admin() || ! is_admin_bar_showing() || ! current_user_can( self::get_capability() ) ) {
			return;
		}

		$notice = self::take_notice();

		if ( null === $notice ) {
			return;
		}

		$css = '#o2r-front-notice{position:fixed;right:16px;bottom:16px;z-index:100000;max-width:min(420px,calc(100vw - 32px));box-sizing:border-box;padding:12px 40px 12px 14px;border-left:4px solid #00A32A;border-radius:4px;background:#fff;color:#1D2327;box-shadow:0 6px 24px rgba(0,0,0,.18);font:13px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}'
			. '#o2r-front-notice p{margin:0;font:inherit;color:inherit}'
			. '#o2r-front-notice button{position:absolute;top:6px;right:6px;width:28px;height:28px;padding:0;border:0;background:none;color:#50575E;font-size:18px;line-height:28px;cursor:pointer}';
		?>
		<style id="o2r-front-notice-css"><?php echo $css; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS fixo. ?></style>
		<div id="o2r-front-notice" role="status">
			<p><strong><?php esc_html_e( 'O² Rocket:', 'o2-rocket' ); ?></strong> <?php echo esc_html( $notice['message'] ); ?><?php echo $notice['progress'] ? ' ' . $this->get_progress_html() : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado em get_progress_html(). ?></p>
			<button type="button" aria-label="<?php esc_attr_e( 'Fechar', 'o2-rocket' ); ?>" onclick="this.parentNode.remove()">&times;</button>
		</div>
		<?php
	}

	public static function add_notice( string $message, bool $progress = false ): void {
		set_transient(
			self::NOTICE_PREFIX . get_current_user_id(),
			array(
				'message'  => $message,
				'progress' => $progress,
			),
			MINUTE_IN_SECONDS
		);
	}

	private static function take_notice(): ?array {
		$key    = self::NOTICE_PREFIX . get_current_user_id();
		$notice = get_transient( $key );

		if ( is_string( $notice ) ) {
			$notice = array( 'message' => $notice );
		}

		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return null;
		}

		delete_transient( $key );

		return array(
			'message'  => (string) $notice['message'],
			'progress' => ! empty( $notice['progress'] ),
		);
	}

	// "x de y páginas" atualizado a cada 3 s pela consulta de andamento (que também adianta a fila).
	private function get_progress_html(): string {
		$progress = $this->preloader->get_progress();
		$labels   = array(
			/* translators: 1: concluídas, 2: total. */
			'progress' => __( 'Pré-carregamento: %1$s de %2$s páginas.', 'o2-rocket' ),
			/* translators: 1: concluídas, 2: total. */
			'done'     => __( 'Pré-carregamento concluído: %1$s de %2$s páginas.', 'o2-rocket' ),
			/* translators: %s: quantidade. */
			'failed'   => __( '%s com falha.', 'o2-rocket' ),
		);
		$text     = sprintf( $labels['progress'], number_format_i18n( $progress['done'] ), number_format_i18n( $progress['total'] ) );
		$script   = "(function(el){var t=el.getAttribute('data-labels');t=JSON.parse(t);var n=function(v){return Number(v).toLocaleString(document.documentElement.lang||'pt-BR');};"
			. "var tick=function(){var b=new FormData();b.append('action',el.getAttribute('data-action'));b.append('_ajax_nonce',el.getAttribute('data-nonce'));"
			. "fetch(el.getAttribute('data-url'),{method:'POST',credentials:'same-origin',body:b}).then(function(r){return r.json();}).then(function(j){if(!j||!j.success){return;}var p=j.data.progress;"
			. "el.textContent=(j.data.running?t.progress:t.done).replace('%1\$s',n(p.done)).replace('%2\$s',n(p.total))+(p.failed>0?' '+t.failed.replace('%s',n(p.failed)):'');if(j.data.running){setTimeout(tick,3000);}}).catch(function(){});};setTimeout(tick,1500);})"
			. "(document.currentScript.previousElementSibling);";

		return sprintf(
			'<span class="o2r-preload-progress" data-url="%1$s" data-action="%2$s" data-nonce="%3$s" data-labels="%4$s" aria-live="polite">%5$s</span><script>%6$s</script>',
			esc_url( admin_url( 'admin-ajax.php' ) ),
			esc_attr( Preloader::AJAX_ACTION ),
			esc_attr( wp_create_nonce( Preloader::AJAX_ACTION ) ),
			esc_attr( (string) wp_json_encode( $labels ) ),
			esc_html( $text ),
			$script
		);
	}

	// URL limpa (sem query) da página do front-end que está sendo vista.
	private function get_current_page_url(): string {
		if ( is_admin() || ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		$path = (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );

		return '' === $path ? '' : (string) preg_replace( '#^(https?://[^/]+).*$#i', '$1', home_url() ) . $path;
	}

	private function get_edited_post_id(): int {
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return 0;
		}

		$screen = get_current_screen();
		$post   = get_post();

		if ( ! $screen instanceof \WP_Screen || 'post' !== $screen->base || ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return 0;
		}

		return (int) $post->ID;
	}

	private function is_own_url( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		return is_string( $host ) && strtolower( $host ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}
}
