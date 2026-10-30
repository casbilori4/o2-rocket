<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Admin;

use O2Rocket\Cache\Page_Cache;
use O2Rocket\Media\Image_Dimensions;
use O2Rocket\Media\Image_Optimizer;
use O2Rocket\Media\Lazy_Load;
use O2Rocket\Media\Lazy_Render;
use O2Rocket\Media\Lcp_Optimizer;
use O2Rocket\Media\Resource_Hints;
use O2Rocket\Optimization\Asset_Minifier;
use O2Rocket\Optimization\Css_Delivery;
use O2Rocket\Optimization\Delay_Js;
use O2Rocket\Optimization\File_Optimizer;
use O2Rocket\Optimization\Google_Fonts;
use O2Rocket\Optimization\Heartbeat;
use O2Rocket\Preload\Link_Prefetch;
use O2Rocket\Preload\Preloader;
use O2Rocket\Server\Htaccess;

defined( 'ABSPATH' ) || exit;

// Perfis prontos no Dashboard (Seguro, Equilibrado, Turbo): cada um define o mesmo conjunto de chaves, ligadas ou
// desligadas. Aplicar guarda a configuração anterior para o "Desfazer", limpa o cache e já começa o pré-carregamento.
final class Profiles {

	const APPLY_ACTION  = 'o2_rocket_apply_profile';
	const UNDO_ACTION   = 'o2_rocket_undo_profile';
	const BACKUP_OPTION = 'o2_rocket_settings_backup';
	const NOTICE_PREFIX = 'o2_rocket_profile_notice_';
	const RECOMMENDED   = 'balanced';

	private Admin_UI $ui;

	private Page_Cache $page_cache;

	private Preloader $preloader;

	public function __construct( Admin_UI $ui, Page_Cache $page_cache, Preloader $preloader ) {
		$this->ui         = $ui;
		$this->page_cache = $page_cache;
		$this->preloader  = $preloader;
	}

	public function register_hooks(): void {
		add_action( 'o2_rocket_admin_tab_dashboard', array( $this, 'render' ), 5 );
		add_action( 'admin_post_' . self::APPLY_ACTION, array( $this, 'handle_apply' ) );
		add_action( 'admin_post_' . self::UNDO_ACTION, array( $this, 'handle_undo' ) );
	}

	// Chaves que os três perfis definem. O resto (exclusões, CDN, integrações, banco, tempo de vida...) fica como está.
	public static function get_keys(): array {
		return array_keys( self::get_definitions()['turbo']['settings'] );
	}

	public static function get_definitions(): array {
		$safe = array(
			Page_Cache::SETTING_ENABLED           => true,
			Page_Cache::SETTING_GZIP              => true,
			Htaccess::SETTING_GZIP                => true,
			Htaccess::SETTING_BROWSER_CACHE       => true,
			Preloader::SETTING_ENABLED            => true,
			Preloader::SETTING_AFTER_PURGE        => true,
			File_Optimizer::SETTING_REMOVE_EMOJIS => true,
			Google_Fonts::SETTING_DISPLAY_SWAP    => true,
			Delay_Js::SETTING_AUTO                => true,
		);

		$balanced_on = array(
			Link_Prefetch::SETTING_ENABLED,
			Asset_Minifier::SETTING_CSS,
			Asset_Minifier::SETTING_JS,
			File_Optimizer::SETTING_DEFER_JS,
			Lazy_Load::SETTING_IMAGES,
			Lazy_Load::SETTING_IFRAMES,
			Lazy_Load::SETTING_YOUTUBE,
			Image_Dimensions::SETTING_ENABLED,
			Lcp_Optimizer::SETTING_ENABLED,
			Image_Optimizer::SETTING_AUTO,
			Image_Optimizer::SETTING_SERVE,
			Google_Fonts::SETTING_LOCAL,
			Resource_Hints::SETTING_FONTS_AUTO,
			Resource_Hints::SETTING_PRECONNECT_AUTO,
			Heartbeat::SETTING_CONTROL,
		);

		$turbo_on = array(
			Delay_Js::SETTING_ENABLED,
			Lazy_Load::SETTING_CSS_BG,
			Lazy_Render::SETTING,
			File_Optimizer::SETTING_REMOVE_JQUERY_MIGRATE,
		);

		$off = array_fill_keys( array_merge( $balanced_on, $turbo_on ), false );

		$safe     = array_merge( $safe, $off, array( Css_Delivery::SETTING_MODE => 'off' ) );
		$balanced = array_merge( $safe, array_fill_keys( $balanced_on, true ) );
		$turbo    = array_merge( $balanced, array_fill_keys( $turbo_on, true ), array( Css_Delivery::SETTING_MODE => 'unused' ) );

		return array(
			'safe'     => array(
				'name'        => __( 'Seguro', 'o2-rocket' ),
				'summary'     => __( 'Cache de página e compressão, sem mexer em CSS nem em JavaScript.', 'o2-rocket' ),
				'icon'        => 'shield',
				'gain'        => 2,
				'gain_label'  => __( 'Moderado', 'o2-rocket' ),
				'risk'        => 1,
				'risk_label'  => __( 'Risco mínimo', 'o2-rocket' ),
				'features'    => array(
					__( 'Cache de página com cópia GZIP', 'o2-rocket' ),
					__( 'Compressão e cache do navegador no servidor', 'o2-rocket' ),
					__( 'Pré-carregamento do cache, também depois de limpar', 'o2-rocket' ),
					__( 'Sem os emojis do WordPress', 'o2-rocket' ),
					__( 'Texto visível enquanto as fontes carregam', 'o2-rocket' ),
				),
				'note'        => __( 'Pode ser ligado em qualquer site: o HTML, o CSS e o JavaScript chegam ao navegador como antes.', 'o2-rocket' ),
				'settings'    => $safe,
			),
			'balanced' => array(
				'name'        => __( 'Equilibrado', 'o2-rocket' ),
				'summary'     => __( 'Tudo do Seguro e as otimizações que raramente causam problema.', 'o2-rocket' ),
				'icon'        => 'gauge',
				'gain'        => 4,
				'gain_label'  => __( 'Alto', 'o2-rocket' ),
				'risk'        => 2,
				'risk_label'  => __( 'Risco baixo', 'o2-rocket' ),
				'features'    => array(
					__( 'Tudo do Seguro', 'o2-rocket' ),
					__( 'CSS e JavaScript minificados, JavaScript com defer', 'o2-rocket' ),
					__( 'Lazy load de imagens, iframes e YouTube', 'o2-rocket' ),
					__( 'Dimensões das imagens e imagem LCP priorizada', 'o2-rocket' ),
					__( 'WebP automático', 'o2-rocket' ),
					__( 'Google Fonts local, preload de fontes e preconnect', 'o2-rocket' ),
					__( 'Links pré-carregados ao passar o mouse e Heartbeat controlado', 'o2-rocket' ),
				),
				'note'        => __( 'Serve para a maioria dos sites. Depois de aplicar, abra as páginas principais e confira.', 'o2-rocket' ),
				'settings'    => $balanced,
			),
			'turbo'    => array(
				'name'        => __( 'Turbo', 'o2-rocket' ),
				'summary'     => __( 'Tudo do Equilibrado e as otimizações mais agressivas.', 'o2-rocket' ),
				'icon'        => 'bolt',
				'gain'        => 5,
				'gain_label'  => __( 'Máximo', 'o2-rocket' ),
				'risk'        => 4,
				'risk_label'  => __( 'Exige teste', 'o2-rocket' ),
				'features'    => array(
					__( 'Tudo do Equilibrado', 'o2-rocket' ),
					__( 'JavaScript atrasado até a primeira interação', 'o2-rocket' ),
					__( 'CSS não utilizado removido', 'o2-rocket' ),
					__( 'Lazy load de fundos do CSS', 'o2-rocket' ),
					__( 'Renderização sob demanda', 'o2-rocket' ),
					__( 'Sem o jQuery Migrate', 'o2-rocket' ),
				),
				'note'        => __( 'Confira menus, sliders, formulários e pop-ups. Se algo falhar, use Desfazer.', 'o2-rocket' ),
				'settings'    => $turbo,
			),
		);
	}

	// Perfil cujas chaves batem com as salvas (valor salvo ou, sem valor, o padrão do schema); '' = personalizada.
	public function get_active_profile(): string {
		$current = $this->get_current_settings();

		foreach ( self::get_definitions() as $id => $profile ) {
			$matches = true;

			foreach ( $profile['settings'] as $key => $value ) {
				$saved = array_key_exists( $key, $current ) ? $current[ $key ] : null;

				if ( is_bool( $value ) ? rest_sanitize_boolean( $saved ) !== $value : (string) $saved !== (string) $value ) {
					$matches = false;
					break;
				}
			}

			if ( $matches ) {
				return $id;
			}
		}

		return '';
	}

	public function handle_apply(): void {
		$id = isset( $_POST['profile'] ) ? sanitize_key( wp_unslash( $_POST['profile'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- conferido logo abaixo.

		check_admin_referer( self::APPLY_ACTION . '_' . $id );
		$this->check_permission();

		$definitions = self::get_definitions();

		if ( ! isset( $definitions[ $id ] ) ) {
			wp_die( esc_html__( 'Perfil desconhecido.', 'o2-rocket' ), '', array( 'response' => 400 ) );
		}

		$stored = get_option( O2_ROCKET_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		update_option( self::BACKUP_OPTION, $stored, false );

		$this->save( $this->ui->build_settings( array_merge( $this->get_current_settings(), $definitions[ $id ]['settings'] ) ), $stored );

		set_transient(
			self::NOTICE_PREFIX . get_current_user_id(),
			array(
				'type'    => 'applied',
				/* translators: %s: nome do perfil. */
				'message' => sprintf( __( 'Perfil %s aplicado e cache limpo. Abra o site numa janela anônima e confira as páginas principais.', 'o2-rocket' ), $definitions[ $id ]['name'] ),
			),
			15 * MINUTE_IN_SECONDS
		);

		$this->redirect();
	}

	public function handle_undo(): void {
		check_admin_referer( self::UNDO_ACTION );
		$this->check_permission();

		$backup = get_option( self::BACKUP_OPTION, null );
		$stored = get_option( O2_ROCKET_OPTION, array() );

		if ( is_array( $backup ) ) {
			$this->save( $backup, is_array( $stored ) ? $stored : array() );
			delete_option( self::BACKUP_OPTION );
			$message = __( 'Configuração anterior restaurada e cache limpo.', 'o2-rocket' );
		} else {
			$message = __( 'Não há configuração anterior para restaurar.', 'o2-rocket' );
		}

		set_transient(
			self::NOTICE_PREFIX . get_current_user_id(),
			array(
				'type'    => 'restored',
				'message' => $message,
			),
			15 * MINUTE_IN_SECONDS
		);

		$this->redirect();
	}

	public function render(): void {
		$definitions = self::get_definitions();
		$active      = $this->get_active_profile();
		$notice      = get_transient( self::NOTICE_PREFIX . get_current_user_id() );
		$can_undo    = is_array( get_option( self::BACKUP_OPTION, null ) );

		// O aviso com "Desfazer" fica pelos 15 minutos do transient (sobrevive a recarregar a página); o da restauração sai na hora.
		if ( is_array( $notice ) && 'applied' !== $notice['type'] ) {
			delete_transient( self::NOTICE_PREFIX . get_current_user_id() );
		}
		?>
		<?php if ( is_array( $notice ) && ! empty( $notice['message'] ) ) : ?>
			<div class="o2r-notice o2r-notice--success o2r-profile-notice" role="status">
				<div class="o2r-notice-message"><p><?php echo esc_html( (string) $notice['message'] ); ?></p></div>
				<?php if ( 'applied' === $notice['type'] && $can_undo ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::UNDO_ACTION ); ?>">
						<?php wp_nonce_field( self::UNDO_ACTION ); ?>
						<button type="submit" class="o2r-button" data-o2r-busy="<?php esc_attr_e( 'Desfazendo…', 'o2-rocket' ); ?>"><?php esc_html_e( 'Desfazer', 'o2-rocket' ); ?></button>
					</form>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<section class="o2r-card o2r-profiles">
			<header class="o2r-card-header o2r-card-header--split">
				<div>
					<h3 class="o2r-card-title"><?php esc_html_e( 'Perfis de otimização', 'o2-rocket' ); ?></h3>
					<p class="o2r-card-description"><?php esc_html_e( 'Um clique liga o conjunto de opções de cada perfil, limpa o cache e já começa o pré-carregamento.', 'o2-rocket' ); ?></p>
				</div>
				<span class="o2r-profiles-current">
					<?php
					/* translators: %s: nome do perfil ou "Personalizada". */
					echo esc_html( sprintf( __( 'Configuração atual: %s', 'o2-rocket' ), '' !== $active ? $definitions[ $active ]['name'] : __( 'Personalizada', 'o2-rocket' ) ) );
					?>
				</span>
			</header>
			<div class="o2r-card-body">
				<div class="o2r-profiles-grid">
					<?php foreach ( $definitions as $id => $profile ) : ?>
						<?php $this->render_profile( $id, $profile, $id === $active ); ?>
					<?php endforeach; ?>
				</div>
				<p class="o2r-profiles-foot"><?php esc_html_e( 'Os perfis não mudam exclusões, CDN, integrações, limpeza do banco de dados, Heartbeat por local, tempo de vida do cache, cache para celular nem a entrega do cache pelo servidor.', 'o2-rocket' ); ?></p>
			</div>
		</section>
		<?php
	}

	private function render_profile( string $id, array $profile, bool $in_use ): void {
		$classes = 'o2r-profile' . ( $in_use ? ' is-active' : '' ) . ( self::RECOMMENDED === $id ? ' is-recommended' : '' );
		?>
		<article class="<?php echo esc_attr( $classes ); ?>">
			<div class="o2r-profile-top">
				<span class="o2r-profile-icon"><?php echo self::icon( $profile['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG fixo. ?></span>
				<div class="o2r-profile-badges">
					<?php if ( self::RECOMMENDED === $id ) : ?>
						<span class="o2r-profile-badge o2r-profile-badge--recommended"><?php esc_html_e( 'Recomendado', 'o2-rocket' ); ?></span>
					<?php endif; ?>
					<?php if ( $in_use ) : ?>
						<span class="o2r-profile-badge o2r-profile-badge--active"><?php esc_html_e( 'Em uso', 'o2-rocket' ); ?></span>
					<?php endif; ?>
				</div>
			</div>
			<h4 class="o2r-profile-name"><?php echo esc_html( $profile['name'] ); ?></h4>
			<p class="o2r-profile-summary"><?php echo esc_html( $profile['summary'] ); ?></p>
			<div class="o2r-profile-scales">
				<?php
				$this->render_scale( __( 'Ganho', 'o2-rocket' ), (int) $profile['gain'], (string) $profile['gain_label'], 'gain' );
				$this->render_scale( __( 'Risco', 'o2-rocket' ), (int) $profile['risk'], (string) $profile['risk_label'], 'risk' );
				?>
			</div>
			<ul class="o2r-profile-features">
				<?php foreach ( $profile['features'] as $feature ) : ?>
					<li><?php echo esc_html( $feature ); ?></li>
				<?php endforeach; ?>
			</ul>
			<p class="o2r-profile-note"><?php echo esc_html( $profile['note'] ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="o2r-profile-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::APPLY_ACTION ); ?>">
				<input type="hidden" name="profile" value="<?php echo esc_attr( $id ); ?>">
				<?php wp_nonce_field( self::APPLY_ACTION . '_' . $id ); ?>
				<button
					type="submit"
					class="<?php echo esc_attr( 'o2r-button' . ( $in_use ? '' : ' o2r-button--primary' ) ); ?>"
					data-o2r-busy="<?php esc_attr_e( 'Aplicando…', 'o2-rocket' ); ?>"
				>
					<?php
					/* translators: %s: nome do perfil. */
					echo esc_html( sprintf( __( 'Aplicar %s', 'o2-rocket' ), $profile['name'] ) );
					?>
				</button>
			</form>
		</article>
		<?php
	}

	private function render_scale( string $label, int $value, string $text, string $type ): void {
		/* translators: 1: Ganho ou Risco, 2: valor de 1 a 5, 3: etiqueta. */
		$aria = sprintf( __( '%1$s: %2$d de 5 (%3$s)', 'o2-rocket' ), $label, $value, $text );
		?>
		<div class="<?php echo esc_attr( 'o2r-scale o2r-scale--' . $type ); ?>">
			<span class="o2r-scale-label"><?php echo esc_html( $label ); ?></span>
			<span class="o2r-scale-dots" role="img" aria-label="<?php echo esc_attr( $aria ); ?>">
				<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
					<span class="<?php echo esc_attr( 'o2r-scale-dot' . ( $i <= $value ? ' is-on' : '' ) ); ?>"></span>
				<?php endfor; ?>
			</span>
			<span class="o2r-scale-text"><?php echo esc_html( $text ); ?></span>
		</div>
		<?php
	}

	private static function icon( string $name ): string {
		$paths = array(
			'shield' => '<path d="M12 3l7 3v5c0 4.6-3 8.4-7 10-4-1.6-7-5.4-7-10V6l7-3z"/><path d="M9 12l2 2 4-4"/>',
			'gauge'  => '<path d="M4.5 17a8.5 8.5 0 1 1 15 0"/><path d="M12 14l4-5"/><circle cx="12" cy="14" r="1.2"/>',
			'bolt'   => '<path d="M13 2L4.5 13.5H11L10 22l8.5-11.5H12L13 2z"/>',
		);

		return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ( isset( $paths[ $name ] ) ? $paths[ $name ] : '' ) . '</svg>';
	}

	// Configuração salva completada pelos padrões do schema (o que o painel mostra hoje).
	private function get_current_settings(): array {
		$stored = get_option( O2_ROCKET_OPTION, array() );

		return array_merge( $this->ui->get_default_settings(), is_array( $stored ) ? $stored : array() );
	}

	// Salvar dispara os ganchos de sempre (drop-in, .htaccess, limpeza); sem mudança, a limpeza é feita aqui.
	private function save( array $settings, array $previous ): void {
		update_option( O2_ROCKET_OPTION, $settings );

		if ( $settings === $previous ) {
			$this->page_cache->purge_all();
		}

		if ( $this->preloader->is_enabled() ) {
			$this->preloader->start();
		}
	}

	private function check_permission(): void {
		if ( ! current_user_can( $this->ui->get_capability() ) ) {
			wp_die( esc_html__( 'Você não tem permissão para alterar as configurações.', 'o2-rocket' ), '', array( 'response' => 403 ) );
		}
	}

	private function redirect(): void {
		wp_safe_redirect( $this->ui->get_tab_url( 'dashboard' ) );
		exit;
	}
}
