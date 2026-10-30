<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Cache;

use O2Rocket\Preload\Preloader;

defined( 'ABSPATH' ) || exit;

// "Limpar cache" na barra do admin, na lista de posts e no painel; aviso do resultado na tela seguinte.
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
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_action' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_filter( 'post_row_actions', array( $this, 'filter_row_actions' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'filter_row_actions' ), 10, 2 );
	}

	public static function get_capability(): string {
		return (string) apply_filters( 'o2_rocket_capability', 'manage_options' );
	}

	// Escopos: all (tudo), url (uma página), post (post e relacionados), preload (pré-carregar).
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

	public function add_admin_bar_menu( $bar ): void {
		if ( ! $bar instanceof \WP_Admin_Bar || ! current_user_can( self::get_capability() ) || ( is_multisite() && is_network_admin() ) ) {
			return;
		}

		$bar->add_node(
			array(
				'id'    => 'o2-rocket',
				'title' => '<span class="ab-icon dashicons-performance" aria-hidden="true" style="top:2px"></span><span class="ab-label">' . esc_html__( 'O² Rocket', 'o2-rocket' ) . '</span>',
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

		if ( $this->preloader->is_enabled() ) {
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

		switch ( $scope ) {
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

			case 'preload':
				$message = $this->preloader->start()
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

		self::add_notice( $message );

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

		return $actions;
	}

	public function render_notice(): void {
		$key     = self::NOTICE_PREFIX . get_current_user_id();
		$message = get_transient( $key );

		if ( ! is_string( $message ) || '' === $message ) {
			return;
		}

		delete_transient( $key );

		printf( '<div class="notice notice-success is-dismissible"><p><strong>%1$s</strong> %2$s</p></div>', esc_html__( 'O² Rocket:', 'o2-rocket' ), esc_html( $message ) );
	}

	public static function add_notice( string $message ): void {
		set_transient( self::NOTICE_PREFIX . get_current_user_id(), $message, MINUTE_IN_SECONDS );
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
