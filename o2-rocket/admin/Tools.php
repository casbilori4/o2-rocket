<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Admin;

use O2Rocket\Cache\Cache_Controls;
use O2Rocket\Cache\Drop_In_Manager;
use O2Rocket\Cache\Page_Cache;
use O2Rocket\Media\Lcp_Store;
use O2Rocket\Preload\Preloader;
use O2Rocket\Server\Htaccess;
use O2Rocket\ThirdParty\Hosting;
use O2Rocket\ThirdParty\Plugins;

defined( 'ABSPATH' ) || exit;

// Aba Ferramentas: exportar/importar/restaurar configurações, ações rápidas e diagnóstico para suporte.
final class Tools {

	const EXPORT_ACTION = 'o2_rocket_export';
	const IMPORT_ACTION = 'o2_rocket_import';
	const RESET_ACTION  = 'o2_rocket_reset';
	const MAX_IMPORT    = 1048576;

	private Admin_UI $ui;

	private Page_Cache $page_cache;

	private Drop_In_Manager $drop_in;

	private Preloader $preloader;

	private Htaccess $htaccess;

	public function __construct( Admin_UI $ui, Page_Cache $page_cache, Drop_In_Manager $drop_in, Preloader $preloader, Htaccess $htaccess ) {
		$this->ui         = $ui;
		$this->page_cache = $page_cache;
		$this->drop_in    = $drop_in;
		$this->preloader  = $preloader;
		$this->htaccess   = $htaccess;
	}

	public function register_hooks(): void {
		add_action( 'o2_rocket_admin_tab_tools', array( $this, 'render' ) );
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( $this, 'handle_export' ) );
		add_action( 'admin_post_' . self::IMPORT_ACTION, array( $this, 'handle_import' ) );
		add_action( 'admin_post_' . self::RESET_ACTION, array( $this, 'handle_reset' ) );
	}

	public function render(): void {
		?>
		<div class="o2r-grid o2r-grid--2">
			<section class="o2r-card">
				<header class="o2r-card-header">
					<h3 class="o2r-card-title"><?php esc_html_e( 'Exportar configurações', 'o2-rocket' ); ?></h3>
					<p class="o2r-card-description"><?php esc_html_e( 'Arquivo .json para backup ou para copiar a configuração para outro site.', 'o2-rocket' ); ?></p>
				</header>
				<div class="o2r-card-body">
					<p class="o2r-muted"><?php esc_html_e( 'Chaves de API (Cloudflare, Sucuri) não vão no arquivo.', 'o2-rocket' ); ?></p>
					<div class="o2r-actions o2r-actions--spaced">
						<a class="o2r-button o2r-button--primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::EXPORT_ACTION ), self::EXPORT_ACTION ) ); ?>"><?php esc_html_e( 'Baixar arquivo', 'o2-rocket' ); ?></a>
					</div>
				</div>
			</section>

			<section class="o2r-card">
				<header class="o2r-card-header">
					<h3 class="o2r-card-title"><?php esc_html_e( 'Importar configurações', 'o2-rocket' ); ?></h3>
					<p class="o2r-card-description"><?php esc_html_e( 'Substitui todas as configurações atuais pelas do arquivo exportado.', 'o2-rocket' ); ?></p>
				</header>
				<div class="o2r-card-body">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="o2r-import">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::IMPORT_ACTION ); ?>">
						<?php wp_nonce_field( self::IMPORT_ACTION ); ?>
						<input class="form-control" type="file" name="o2r_import" accept=".json,application/json" required>
						<div class="o2r-actions o2r-actions--spaced">
							<button type="submit" class="o2r-button"><?php esc_html_e( 'Importar', 'o2-rocket' ); ?></button>
						</div>
					</form>
				</div>
			</section>
		</div>

		<div class="o2r-grid o2r-grid--2">
			<section class="o2r-card">
				<header class="o2r-card-header">
					<h3 class="o2r-card-title"><?php esc_html_e( 'Ações rápidas', 'o2-rocket' ); ?></h3>
					<p class="o2r-card-description"><?php esc_html_e( 'As mesmas da barra do admin.', 'o2-rocket' ); ?></p>
				</header>
				<div class="o2r-card-body">
					<div class="o2r-actions">
						<a class="o2r-button" href="<?php echo esc_url( Cache_Controls::get_action_url( 'all' ) ); ?>"><?php esc_html_e( 'Limpar todo o cache', 'o2-rocket' ); ?></a>
						<?php if ( $this->preloader->is_enabled() ) : ?>
							<a class="o2r-button" href="<?php echo esc_url( Cache_Controls::get_action_url( 'preload' ) ); ?>"><?php esc_html_e( 'Pré-carregar o cache', 'o2-rocket' ); ?></a>
						<?php endif; ?>
					</div>
				</div>
			</section>

			<section class="o2r-card">
				<header class="o2r-card-header">
					<h3 class="o2r-card-title"><?php esc_html_e( 'Restaurar padrões', 'o2-rocket' ); ?></h3>
					<p class="o2r-card-description"><?php esc_html_e( 'Volta todas as opções ao estado de instalação (cache e otimizações desligados).', 'o2-rocket' ); ?></p>
				</header>
				<div class="o2r-card-body">
					<div class="o2r-actions">
						<a
							class="o2r-button"
							href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::RESET_ACTION ), self::RESET_ACTION ) ); ?>"
							onclick="return window.confirm(<?php echo esc_attr( wp_json_encode( __( 'Restaurar todas as configurações do O² Rocket para o padrão?', 'o2-rocket' ) ) ); ?>);"
						><?php esc_html_e( 'Restaurar padrões', 'o2-rocket' ); ?></a>
					</div>
				</div>
			</section>
		</div>

		<?php $rows = $this->get_diagnostics(); ?>
		<section class="o2r-card">
			<header class="o2r-card-header">
				<h3 class="o2r-card-title"><?php esc_html_e( 'Diagnóstico', 'o2-rocket' ); ?></h3>
				<p class="o2r-card-description"><?php esc_html_e( 'Situação do ambiente e dos módulos. Copie o texto abaixo ao pedir suporte.', 'o2-rocket' ); ?></p>
			</header>
			<div class="o2r-list o2r-list--flush">
				<?php foreach ( $rows as $row ) : ?>
					<div class="o2r-list-row">
						<div class="o2r-list-text">
							<div class="o2r-list-title"><?php echo esc_html( $row[0] ); ?></div>
							<?php if ( ! empty( $row[3] ) ) : ?>
								<div class="o2r-list-meta"><?php echo esc_html( $row[3] ); ?></div>
							<?php endif; ?>
						</div>
						<?php echo Admin_UI::badge_html( $row[1], $row[2] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado em badge_html(). ?>
					</div>
				<?php endforeach; ?>
			</div>
			<div class="o2r-card-body">
				<textarea class="form-control font-monospace" rows="8" readonly><?php echo esc_textarea( $this->get_diagnostics_text( $rows ) ); ?></textarea>
			</div>
		</section>
		<?php
	}

	public function handle_export(): void {
		check_admin_referer( self::EXPORT_ACTION );
		$this->check_capability();

		$settings = get_option( O2_ROCKET_OPTION, array() );
		$settings = is_array( $settings ) ? array_diff_key( $settings, array_flip( $this->ui->get_secret_keys() ) ) : array();

		$payload = array(
			'plugin'   => 'o2-rocket',
			'version'  => O2_ROCKET_VERSION,
			'exported' => gmdate( 'c' ),
			'site'     => home_url( '/' ),
			'settings' => $settings,
		);

		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="o2-rocket-' . sanitize_file_name( $host ) . '-' . gmdate( 'Y-m-d' ) . '.json"' );

		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- download de JSON.
		exit;
	}

	public function handle_import(): void {
		check_admin_referer( self::IMPORT_ACTION );
		$this->check_capability();

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- arquivo enviado, validado abaixo.
		$file  = isset( $_FILES['o2r_import'] ) && is_array( $_FILES['o2r_import'] ) ? $_FILES['o2r_import'] : array();
		$error = '';

		if ( empty( $file['tmp_name'] ) || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			$error = __( 'Nenhum arquivo recebido.', 'o2-rocket' );
		} elseif ( (int) $file['size'] > self::MAX_IMPORT ) {
			$error = __( 'O arquivo é grande demais para ser uma exportação do O² Rocket.', 'o2-rocket' );
		}

		$payload = '' === $error ? json_decode( (string) file_get_contents( $file['tmp_name'] ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( '' === $error && ( ! is_array( $payload ) || ! isset( $payload['plugin'], $payload['settings'] ) || 'o2-rocket' !== $payload['plugin'] || ! is_array( $payload['settings'] ) ) ) {
			$error = __( 'Arquivo inválido: use um .json exportado pelo O² Rocket.', 'o2-rocket' );
		}

		if ( '' !== $error ) {
			Cache_Controls::add_notice( $error );
			$this->redirect();
		}

		// Segredos não vêm no arquivo: os atuais são mantidos.
		$current  = get_option( O2_ROCKET_OPTION, array() );
		$secrets  = is_array( $current ) ? array_intersect_key( $current, array_flip( $this->ui->get_secret_keys() ) ) : array();
		$settings = $this->ui->build_settings( array_merge( $payload['settings'], $secrets ) );

		update_option( O2_ROCKET_OPTION, $settings );

		Cache_Controls::add_notice( __( 'Configurações importadas. O cache foi limpo para valer com as novas opções.', 'o2-rocket' ) );
		$this->redirect();
	}

	public function handle_reset(): void {
		check_admin_referer( self::RESET_ACTION );
		$this->check_capability();

		update_option( O2_ROCKET_OPTION, $this->ui->build_settings( array() ) );

		Cache_Controls::add_notice( __( 'Configurações restauradas para o padrão.', 'o2-rocket' ) );
		$this->redirect();
	}

	// [ título, valor, tom, detalhe ].
	public function get_diagnostics(): array {
		global $wpdb;

		$dropin_state = $this->drop_in->get_dropin_state();
		$htaccess     = $this->htaccess->get_status();
		$preload      = $this->preloader->get_status();
		$cache_ok     = is_dir( O2_ROCKET_CACHE_PATH ) && wp_is_writable( O2_ROCKET_CACHE_PATH );
		$config_ok    = is_dir( O2_ROCKET_CONFIG_PATH ) ? wp_is_writable( O2_ROCKET_CONFIG_PATH ) : wp_is_writable( dirname( O2_ROCKET_CONFIG_PATH ) );
		$server       = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
		$db_engine    = ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) ? 'SQLite' : 'MySQL/MariaDB ' . $wpdb->db_version();
		$cron         = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$theme        = wp_get_theme();
		$hosting      = new Hosting();
		$overlapping  = ( new Plugins( $this->page_cache ) )->get_active_overlapping();

		$rows = array(
			array( 'O² Rocket', O2_ROCKET_VERSION, 'neutral', '' ),
			array( 'WordPress', get_bloginfo( 'version' ) . ( is_multisite() ? ' (multisite)' : '' ), 'neutral', '' ),
			array( 'PHP', PHP_VERSION, version_compare( PHP_VERSION, '8.1', '>=' ) ? 'success' : 'warning', '' ),
			array( __( 'Banco de dados', 'o2-rocket' ), $db_engine, 'neutral', '' ),
			array( __( 'Servidor web', 'o2-rocket' ), '' !== $server ? $server : __( 'não informado', 'o2-rocket' ), 'neutral', Htaccess::is_supported() ? __( 'Lê .htaccess', 'o2-rocket' ) : __( 'Não lê .htaccess', 'o2-rocket' ) ),
			array( __( 'Tema', 'o2-rocket' ), $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ), 'neutral', '' ),
			array( __( 'Hospedagem', 'o2-rocket' ), '' !== $hosting->get_host() ? $hosting->get_host_name() : __( 'não identificada', 'o2-rocket' ), 'neutral', $hosting->has_server_page_cache() ? __( 'Cache de página no servidor', 'o2-rocket' ) : '' ),
			array( __( 'Plugins com a mesma função', 'o2-rocket' ), empty( $overlapping ) ? __( 'Nenhum', 'o2-rocket' ) : implode( ', ', $overlapping ), empty( $overlapping ) ? 'success' : 'warning', '' ),
			array( __( 'Page Cache', 'o2-rocket' ), $this->page_cache->is_enabled() ? __( 'Ligado', 'o2-rocket' ) : __( 'Desligado', 'o2-rocket' ), $this->page_cache->is_enabled() ? 'success' : 'neutral', '' ),
			array( __( 'Drop-in advanced-cache.php', 'o2-rocket' ), $this->get_dropin_label( $dropin_state ), Drop_In_Manager::STATE_OK === $dropin_state ? 'success' : ( $this->page_cache->is_enabled() ? 'danger' : 'neutral' ), $this->drop_in->get_dropin_path() ),
			array( 'WP_CACHE', $this->drop_in->is_wp_cache_enabled() ? 'true' : 'false', $this->drop_in->is_wp_cache_enabled() ? 'success' : 'neutral', '' ),
			array( __( 'Pasta de cache', 'o2-rocket' ), $cache_ok ? __( 'Gravável', 'o2-rocket' ) : __( 'Sem permissão', 'o2-rocket' ), $cache_ok ? 'success' : 'danger', O2_ROCKET_CACHE_PATH ),
			array( __( 'Pasta de configuração', 'o2-rocket' ), $config_ok ? __( 'Gravável', 'o2-rocket' ) : __( 'Sem permissão', 'o2-rocket' ), $config_ok ? 'success' : 'danger', O2_ROCKET_CONFIG_PATH ),
			array( '.htaccess', '' !== $htaccess['error'] ? $htaccess['error'] : ( $htaccess['written'] ? __( 'Regras gravadas', 'o2-rocket' ) : __( 'Sem regras', 'o2-rocket' ) ), '' !== $htaccess['error'] ? 'warning' : 'neutral', '' ),
			array( 'WP-Cron', $cron ? 'DISABLE_WP_CRON' : __( 'Ativo', 'o2-rocket' ), $cron ? 'warning' : 'success', $cron ? __( 'Garanta um cron real chamando wp-cron.php.', 'o2-rocket' ) : '' ),
			array( __( 'Pré-carregamento', 'o2-rocket' ), $preload['enabled'] ? sprintf( '%d/%d', (int) $preload['counts']['completed'], (int) $preload['total'] ) : __( 'Desligado', 'o2-rocket' ), $preload['enabled'] && '' !== $preload['error'] ? 'warning' : 'neutral', (string) $preload['error'] ),
			array( __( 'Medições de LCP', 'o2-rocket' ), number_format_i18n( ( new Lcp_Store() )->count() ), 'neutral', '' ),
			array( 'OPcache', extension_loaded( 'Zend OPcache' ) && filter_var( ini_get( 'opcache.enable' ), FILTER_VALIDATE_BOOLEAN ) ? __( 'Ativo', 'o2-rocket' ) : __( 'Inativo', 'o2-rocket' ), 'neutral', '' ),
			array( __( 'Limite de memória', 'o2-rocket' ), (string) ini_get( 'memory_limit' ), 'neutral', 'WP_MEMORY_LIMIT: ' . ( defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : '-' ) ),
			array( __( 'Plugins ativos', 'o2-rocket' ), (string) count( (array) get_option( 'active_plugins', array() ) ), 'neutral', '' ),
		);

		return (array) apply_filters( 'o2_rocket_diagnostics', $rows );
	}

	private function get_dropin_label( string $state ): string {
		$labels = array(
			Drop_In_Manager::STATE_OK       => __( 'Instalado', 'o2-rocket' ),
			Drop_In_Manager::STATE_OUTDATED => __( 'Desatualizado', 'o2-rocket' ),
			Drop_In_Manager::STATE_MISSING  => __( 'Ausente', 'o2-rocket' ),
			Drop_In_Manager::STATE_CONFLICT => __( 'De outro plugin', 'o2-rocket' ),
		);

		return isset( $labels[ $state ] ) ? $labels[ $state ] : $state;
	}

	private function get_diagnostics_text( array $rows ): string {
		$lines = array( 'O² Rocket — ' . home_url( '/' ) . ' — ' . gmdate( 'Y-m-d H:i' ) . ' UTC' );

		foreach ( $rows as $row ) {
			$lines[] = $row[0] . ': ' . $row[1] . ( ! empty( $row[3] ) ? ' (' . $row[3] . ')' : '' );
		}

		$settings = get_option( O2_ROCKET_OPTION, array() );

		if ( is_array( $settings ) ) {
			$enabled = array();

			foreach ( array_diff_key( $settings, array_flip( $this->ui->get_secret_keys() ) ) as $key => $value ) {
				if ( true === $value ) {
					$enabled[] = $key;
				}
			}

			$lines[] = __( 'Opções ligadas', 'o2-rocket' ) . ': ' . implode( ', ', $enabled );
		}

		return implode( "\n", $lines );
	}

	private function check_capability(): void {
		if ( ! current_user_can( $this->ui->get_capability() ) ) {
			wp_die( esc_html__( 'Você não tem permissão para alterar o O² Rocket.', 'o2-rocket' ), '', array( 'response' => 403 ) );
		}
	}

	private function redirect(): void {
		wp_safe_redirect( $this->ui->get_tab_url( 'tools' ) );
		exit;
	}
}
