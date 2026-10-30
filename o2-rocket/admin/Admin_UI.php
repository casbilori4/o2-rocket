<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Admin;

defined( 'ABSPATH' ) || exit;

final class Admin_UI {

	const MENU_SLUG    = 'o2-rocket';
	const CAPABILITY   = 'manage_options';
	const OPTION_NAME  = O2_ROCKET_OPTION;
	const OPTION_GROUP = 'o2_rocket_settings_group';
	const DEFAULT_TAB  = 'dashboard';
	const TAB_FIELD    = '_o2r_tab';
	const LOGO_PATH    = 'assets/images/logo.png';
	const FONT_PATH    = 'assets/fonts/inter-latin-wght-normal.woff2';

	// Bootstrap 5.3.8 (MIT) empacotado no plugin: o painel não depende de CDN.
	const BOOTSTRAP_HANDLE  = 'o2-rocket-bootstrap';
	const BOOTSTRAP_VERSION = '5.3.8';
	const BOOTSTRAP_PATH    = 'assets/vendor/bootstrap/';

	private string $hook_suffix = '';

	private ?array $tabs = null;

	private ?array $schema = null;

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_filter( 'admin_body_class', array( $this, 'filter_body_class' ) );
		add_filter( 'plugin_action_links_' . O2_ROCKET_BASENAME, array( $this, 'filter_action_links' ) );
		add_filter( 'option_page_capability_' . self::OPTION_GROUP, array( $this, 'get_capability' ) );
	}

	public function get_capability(): string {
		return (string) apply_filters( 'o2_rocket_capability', self::CAPABILITY );
	}

	public function register_menu(): void {
		$this->hook_suffix = (string) add_menu_page(
			__( 'O² Rocket', 'o2-rocket' ),
			__( 'O² Rocket', 'o2-rocket' ),
			$this->get_capability(),
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-performance',
			81
		);
	}

	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => $this->get_default_settings(),
				'show_in_rest'      => false,
			)
		);

		do_action( 'o2_rocket_register_settings', $this );
	}

	public function enqueue_assets( $hook_suffix ): void {
		if ( ! $this->is_plugin_screen( (string) $hook_suffix ) ) {
			return;
		}

		$base = plugins_url( self::BOOTSTRAP_PATH, O2_ROCKET_FILE );

		wp_enqueue_style( self::BOOTSTRAP_HANDLE, $base . 'bootstrap.min.css', array(), self::BOOTSTRAP_VERSION );
		wp_add_inline_style( self::BOOTSTRAP_HANDLE, $this->get_inline_css() );

		wp_enqueue_script( self::BOOTSTRAP_HANDLE, $base . 'bootstrap.bundle.min.js', array(), self::BOOTSTRAP_VERSION, true );
		wp_add_inline_script( self::BOOTSTRAP_HANDLE, $this->get_inline_js() );
	}

	public function filter_body_class( $classes ): string {
		$classes = (string) $classes;

		if ( $this->is_plugin_screen() ) {
			$classes .= ' o2r-admin';
		}

		return $classes;
	}

	public function filter_action_links( $links ): array {
		$links = (array) $links;

		if ( ! current_user_can( $this->get_capability() ) ) {
			return $links;
		}

		array_unshift(
			$links,
			sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $this->get_tab_url( self::DEFAULT_TAB ) ),
				esc_html__( 'Configurações', 'o2-rocket' )
			)
		);

		return $links;
	}

	public function get_tabs(): array {
		if ( null !== $this->tabs ) {
			return $this->tabs;
		}

		$group_general      = __( 'Geral', 'o2-rocket' );
		$group_optimization = __( 'Otimização', 'o2-rocket' );
		$group_system       = __( 'Sistema', 'o2-rocket' );

		$tabs = array(
			'dashboard'         => array(
				'title'       => __( 'Dashboard', 'o2-rocket' ),
				'group'       => $group_general,
				'description' => __( 'Visão geral do desempenho e da saúde do ambiente.', 'o2-rocket' ),
				'type'        => 'custom',
			),
			'page-cache'        => array(
				'title'       => __( 'Page Cache', 'o2-rocket' ),
				'group'       => $group_optimization,
				'description' => __( 'Cache de página em HTML estático, pré-carregamento e regras de exclusão.', 'o2-rocket' ),
				'type'        => 'settings',
			),
			'file-optimization' => array(
				'title'       => __( 'Otimização de Arquivos', 'o2-rocket' ),
				'group'       => $group_optimization,
				'description' => __( 'Minificação, combinação, adiamento e carregamento assíncrono de CSS e JavaScript.', 'o2-rocket' ),
				'type'        => 'settings',
			),
			'media'             => array(
				'title'       => __( 'Mídia', 'o2-rocket' ),
				'group'       => $group_optimization,
				'description' => __( 'Lazy load, dimensões de imagens, formatos modernos e otimização do LCP.', 'o2-rocket' ),
				'type'        => 'settings',
			),
			'database'          => array(
				'title'       => __( 'Banco de Dados', 'o2-rocket' ),
				'group'       => $group_optimization,
				'description' => __( 'Limpeza de revisões, rascunhos, transients e otimização de tabelas.', 'o2-rocket' ),
				'type'        => 'settings',
			),
			'tools'             => array(
				'title'       => __( 'Ferramentas', 'o2-rocket' ),
				'group'       => $group_system,
				'description' => __( 'Importação, exportação, restauração de padrões e diagnóstico.', 'o2-rocket' ),
				'type'        => 'custom',
			),
		);

		$this->tabs = $this->normalize_tabs( (array) apply_filters( 'o2_rocket_admin_tabs', $tabs, $this ) );

		return $this->tabs;
	}

	public function get_active_tab(): string {
		$tabs = $this->get_tabs();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navegação somente leitura.
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		if ( '' !== $requested && isset( $tabs[ $requested ] ) ) {
			return $requested;
		}

		if ( isset( $tabs[ self::DEFAULT_TAB ] ) ) {
			return self::DEFAULT_TAB;
		}

		return (string) array_key_first( $tabs );
	}

	public function get_tab_url( string $slug ): string {
		return add_query_arg(
			array(
				'page' => self::MENU_SLUG,
				'tab'  => $slug,
			),
			admin_url( 'admin.php' )
		);
	}

	// ID de "página" da Settings API de cada aba, usado em add_settings_section()/add_settings_field().
	public function get_settings_page( string $slug ): string {
		return self::MENU_SLUG . '-' . $slug;
	}

	// Formato: 'chave' => array( 'tab', 'type', 'default', 'choices', 'min', 'max', 'sanitize_callback' ).
	public function get_settings_schema(): array {
		if ( null === $this->schema ) {
			$schema       = apply_filters( 'o2_rocket_settings_schema', array() );
			$this->schema = is_array( $schema ) ? $schema : array();
		}

		return $this->schema;
	}

	public function get_default_settings(): array {
		$defaults = array();

		foreach ( $this->get_settings_schema() as $key => $field ) {
			$defaults[ $key ] = is_array( $field ) && array_key_exists( 'default', $field ) ? $field['default'] : null;
		}

		return $defaults;
	}

	// Cada aba envia só os próprios campos: mescla com o salvo e zera checkboxes desmarcados da aba enviada.
	public function sanitize_settings( $input ): array {
		$schema  = $this->get_settings_schema();
		$current = get_option( self::OPTION_NAME, array() );
		$output  = wp_parse_args( is_array( $current ) ? $current : array(), $this->get_default_settings() );
		$input   = is_array( $input ) ? $input : array();
		$tab     = isset( $input[ self::TAB_FIELD ] ) && is_scalar( $input[ self::TAB_FIELD ] ) ? sanitize_key( (string) $input[ self::TAB_FIELD ] ) : '';

		unset( $output[ self::TAB_FIELD ] );

		foreach ( $schema as $key => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$type      = isset( $field['type'] ) ? (string) $field['type'] : 'text';
			$field_tab = isset( $field['tab'] ) ? (string) $field['tab'] : '';

			if ( array_key_exists( $key, $input ) ) {
				$output[ $key ] = $this->sanitize_value( $input[ $key ], $field );
			} elseif ( '' !== $tab && $field_tab === $tab && 'bool' === $type ) {
				$output[ $key ] = false;
			} elseif ( '' !== $tab && $field_tab === $tab && 'multiselect' === $type ) {
				$output[ $key ] = array();
			}
		}

		return $output;
	}

	// Badge de status compartilhado pelas abas (success, warning, danger, neutral).
	public static function badge_html( string $label, string $tone = 'neutral' ): string {
		$tones = array(
			'success' => 'success',
			'warning' => 'warning',
			'danger'  => 'danger',
			'info'    => 'neutral',
			'neutral' => 'neutral',
		);

		return sprintf(
			'<span class="%1$s">%2$s</span>',
			esc_attr( 'o2r-badge o2r-badge--' . ( isset( $tones[ $tone ] ) ? $tones[ $tone ] : 'neutral' ) ),
			esc_html( $label )
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( $this->get_capability() ) ) {
			wp_die(
				esc_html__( 'Você não tem permissão para acessar esta página.', 'o2-rocket' ),
				'',
				array( 'response' => 403 )
			);
		}

		$tabs          = $this->get_tabs();
		$active_tab    = $this->get_active_tab();
		$current_group = null;
		?>
		<div class="wrap o2r-wrap" data-bs-theme="light">
			<header class="o2r-topbar">
				<div class="o2r-brand">
					<img
						class="o2r-brand-logo"
						src="<?php echo esc_url( plugins_url( self::LOGO_PATH, O2_ROCKET_FILE ) ); ?>"
						width="68"
						height="96"
						alt=""
					>
					<h1 class="o2r-brand-title"><?php esc_html_e( 'O² Rocket', 'o2-rocket' ); ?></h1>
					<span class="o2r-brand-divider" aria-hidden="true"></span>
					<span class="o2r-brand-tagline"><?php esc_html_e( 'Performance e cache', 'o2-rocket' ); ?></span>
				</div>
				<span class="o2r-topbar-meta">
					<?php
					/* translators: %s: versão do plugin. */
					echo esc_html( sprintf( __( 'Versão %s', 'o2-rocket' ), O2_ROCKET_VERSION ) );
					?>
				</span>
			</header>

			<div class="o2r-shell">
				<nav class="o2r-sidebar" aria-label="<?php esc_attr_e( 'Seções do O² Rocket', 'o2-rocket' ); ?>">
					<div class="o2r-nav" id="o2r-nav" role="tablist" aria-orientation="vertical">
						<?php foreach ( $tabs as $slug => $tab ) : ?>
							<?php
							if ( $tab['group'] !== $current_group ) {
								$current_group = $tab['group'];

								if ( '' !== $current_group ) {
									printf( '<div class="o2r-nav-heading" role="presentation">%s</div>', esc_html( $current_group ) );
								}
							}

							$is_active = $slug === $active_tab;
							?>
							<a
								class="<?php echo esc_attr( 'o2r-nav-link' . ( $is_active ? ' active' : '' ) ); ?>"
								id="<?php echo esc_attr( 'o2r-tab-' . $slug ); ?>"
								href="<?php echo esc_url( $this->get_tab_url( $slug ) ); ?>"
								data-bs-toggle="pill"
								data-bs-target="<?php echo esc_attr( '#o2r-pane-' . $slug ); ?>"
								data-o2r-tab="<?php echo esc_attr( $slug ); ?>"
								role="tab"
								aria-controls="<?php echo esc_attr( 'o2r-pane-' . $slug ); ?>"
								aria-selected="<?php echo esc_attr( $is_active ? 'true' : 'false' ); ?>"
								tabindex="<?php echo esc_attr( $is_active ? '0' : '-1' ); ?>"
							><?php echo esc_html( $tab['title'] ); ?></a>
						<?php endforeach; ?>
					</div>
				</nav>

				<div class="o2r-main">
					<div class="o2r-content">
						<hr class="wp-header-end">

						<?php $this->render_notices(); ?>

						<div class="tab-content" id="o2r-tab-content">
							<?php
							foreach ( $tabs as $slug => $tab ) {
								$this->render_tab_pane( $slug, $tab, $slug === $active_tab );
							}
							?>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	private function is_plugin_screen( string $hook_suffix = '' ): bool {
		if ( '' === $this->hook_suffix ) {
			return false;
		}

		if ( '' !== $hook_suffix ) {
			return $hook_suffix === $this->hook_suffix;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen instanceof \WP_Screen && $screen->id === $this->hook_suffix;
	}

	private function normalize_tabs( array $tabs ): array {
		$normalized = array();

		foreach ( $tabs as $slug => $tab ) {
			$slug = sanitize_key( (string) $slug );

			if ( '' === $slug || ! is_array( $tab ) || empty( $tab['title'] ) ) {
				continue;
			}

			$normalized[ $slug ] = array(
				'title'       => (string) $tab['title'],
				'group'       => isset( $tab['group'] ) ? (string) $tab['group'] : '',
				'description' => isset( $tab['description'] ) ? (string) $tab['description'] : '',
				'type'        => isset( $tab['type'] ) && 'settings' === $tab['type'] ? 'settings' : 'custom',
				'callback'    => isset( $tab['callback'] ) && is_callable( $tab['callback'] ) ? $tab['callback'] : null,
			);
		}

		return $normalized;
	}

	private function sanitize_value( $value, array $field ) {
		if ( isset( $field['sanitize_callback'] ) && is_callable( $field['sanitize_callback'] ) ) {
			return call_user_func( $field['sanitize_callback'], $value, $field );
		}

		$type    = isset( $field['type'] ) ? (string) $field['type'] : 'text';
		$default = array_key_exists( 'default', $field ) ? $field['default'] : null;
		$choices = isset( $field['choices'] ) && is_array( $field['choices'] ) ? array_map( 'strval', array_keys( $field['choices'] ) ) : array();

		switch ( $type ) {
			case 'bool':
				return rest_sanitize_boolean( $value );

			case 'int':
				$value = is_scalar( $value ) ? (int) $value : (int) $default;

				if ( isset( $field['min'] ) ) {
					$value = max( (int) $field['min'], $value );
				}

				if ( isset( $field['max'] ) ) {
					$value = min( (int) $field['max'], $value );
				}

				return $value;

			case 'textarea':
				return sanitize_textarea_field( is_scalar( $value ) ? (string) $value : '' );

			case 'url':
				return esc_url_raw( is_scalar( $value ) ? (string) $value : '' );

			case 'lines':
				$lines = is_array( $value ) ? $value : preg_split( '/\r\n|\r|\n/', is_scalar( $value ) ? (string) $value : '' );
				$lines = array_map( 'sanitize_text_field', array_filter( (array) $lines, 'is_scalar' ) );

				return array_values( array_unique( array_filter( $lines, 'strlen' ) ) );

			case 'select':
				$value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';

				return in_array( $value, $choices, true ) ? $value : $default;

			case 'multiselect':
				$values = array_map( 'sanitize_text_field', array_filter( (array) $value, 'is_scalar' ) );

				return array_values( array_intersect( array_unique( $values ), $choices ) );

			case 'text':
			default:
				return sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );
		}
	}

	private function render_notices(): void {
		$tones = array(
			'error'   => 'danger',
			'success' => 'success',
			'updated' => 'success',
			'warning' => 'warning',
			'info'    => 'info',
		);

		foreach ( get_settings_errors() as $notice ) {
			$tone = isset( $tones[ $notice['type'] ] ) ? $tones[ $notice['type'] ] : 'info';

			printf(
				'<div class="%1$s" role="alert"><div class="o2r-notice-message">%2$s</div><button type="button" class="o2r-notice-close" data-bs-dismiss="alert">%3$s</button></div>',
				esc_attr( 'alert fade show o2r-notice o2r-notice--' . $tone ),
				wp_kses_post( $notice['message'] ),
				esc_html__( 'Fechar', 'o2-rocket' )
			);
		}
	}

	private function render_tab_pane( string $slug, array $tab, bool $is_active ): void {
		?>
		<div
			class="<?php echo esc_attr( 'tab-pane fade' . ( $is_active ? ' show active' : '' ) ); ?>"
			id="<?php echo esc_attr( 'o2r-pane-' . $slug ); ?>"
			role="tabpanel"
			aria-labelledby="<?php echo esc_attr( 'o2r-tab-' . $slug ); ?>"
			tabindex="0"
		>
			<header class="o2r-page-header">
				<h2 class="o2r-page-title"><?php echo esc_html( $tab['title'] ); ?></h2>
				<?php if ( '' !== $tab['description'] ) : ?>
					<p class="o2r-page-description"><?php echo wp_kses_post( $tab['description'] ); ?></p>
				<?php endif; ?>
			</header>

			<?php
			$rendered = false;

			if ( null !== $tab['callback'] ) {
				call_user_func( $tab['callback'], $slug, $this );
				$rendered = true;
			}

			if ( 'settings' === $tab['type'] && $this->render_settings_form( $slug ) ) {
				$rendered = true;
			}

			if ( has_action( 'o2_rocket_admin_tab_' . $slug ) ) {
				do_action( 'o2_rocket_admin_tab_' . $slug, $this );
				$rendered = true;
			}

			if ( ! $rendered ) {
				$this->render_empty_state();
			}
			?>
		</div>
		<?php
	}

	// Campos ocultos equivalentes a settings_fields(), mas com referer apontando para a aba (retorna nela após salvar).
	private function render_settings_form( string $slug ): bool {
		global $wp_settings_sections;

		$page = $this->get_settings_page( $slug );

		if ( empty( $wp_settings_sections[ $page ] ) ) {
			return false;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" class="o2r-settings-form" data-o2r-form="<?php echo esc_attr( $slug ); ?>">
			<input type="hidden" name="option_page" value="<?php echo esc_attr( self::OPTION_GROUP ); ?>">
			<input type="hidden" name="action" value="update">
			<input type="hidden" name="<?php echo esc_attr( self::OPTION_NAME . '[' . self::TAB_FIELD . ']' ); ?>" value="<?php echo esc_attr( $slug ); ?>">
			<?php wp_nonce_field( self::OPTION_GROUP . '-options', '_wpnonce', false ); ?>
			<input type="hidden" name="_wp_http_referer" value="<?php echo esc_url( $this->get_tab_url( $slug ) ); ?>">

			<?php $this->render_settings_sections( $page ); ?>

			<div class="o2r-form-footer">
				<button type="submit" class="o2r-button o2r-button--primary">
					<?php esc_html_e( 'Salvar alterações', 'o2-rocket' ); ?>
				</button>
			</div>
		</form>
		<?php
		return true;
	}

	// Mesmo registro do do_settings_sections(), renderizado como cards.
	private function render_settings_sections( string $page ): void {
		global $wp_settings_sections, $wp_settings_fields;

		foreach ( (array) $wp_settings_sections[ $page ] as $section ) {
			$section_class = empty( $section['section_class'] ) ? '' : ' ' . $section['section_class'];
			$intro         = '';

			if ( ! empty( $section['callback'] ) && is_callable( $section['callback'] ) ) {
				ob_start();
				call_user_func( $section['callback'], $section );
				$intro = trim( (string) ob_get_clean() );
			}

			$fields = empty( $wp_settings_fields[ $page ][ $section['id'] ] ) ? array() : (array) $wp_settings_fields[ $page ][ $section['id'] ];
			?>
			<section class="<?php echo esc_attr( 'o2r-card' . $section_class ); ?>" id="<?php echo esc_attr( 'o2r-section-' . $section['id'] ); ?>">
				<?php if ( ! empty( $section['title'] ) ) : ?>
					<header class="o2r-card-header">
						<h3 class="o2r-card-title"><?php echo esc_html( $section['title'] ); ?></h3>
					</header>
				<?php endif; ?>
				<div class="o2r-card-body">
					<?php if ( '' !== $intro ) : ?>
						<div class="o2r-section-intro">
							<?php echo $intro; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- saída do callback da seção, escapada por ele (igual ao do_settings_sections()). ?>
						</div>
					<?php endif; ?>
					<?php if ( ! empty( $fields ) ) : ?>
						<div class="o2r-fields">
							<?php $this->render_settings_fields( $fields ); ?>
						</div>
					<?php endif; ?>
				</div>
			</section>
			<?php
		}
	}

	private function render_settings_fields( array $fields ): void {
		foreach ( $fields as $field ) {
			$args      = isset( $field['args'] ) ? (array) $field['args'] : array();
			$row_class = empty( $args['class'] ) ? '' : ' ' . $args['class'];
			?>
			<div class="<?php echo esc_attr( 'o2r-field' . $row_class ); ?>">
				<div class="o2r-field-label">
					<?php if ( ! empty( $args['label_for'] ) ) : ?>
						<label for="<?php echo esc_attr( $args['label_for'] ); ?>"><?php echo esc_html( $field['title'] ); ?></label>
					<?php else : ?>
						<span><?php echo esc_html( $field['title'] ); ?></span>
					<?php endif; ?>
				</div>
				<div class="o2r-field-control">
					<?php call_user_func( $field['callback'], $args ); ?>
				</div>
			</div>
			<?php
		}
	}

	private function render_empty_state(): void {
		?>
		<div class="o2r-card o2r-empty">
			<p class="o2r-empty-title"><?php esc_html_e( 'Nenhuma configuração disponível ainda.', 'o2-rocket' ); ?></p>
			<p class="o2r-empty-text"><?php esc_html_e( 'Os controles deste módulo aparecerão aqui.', 'o2-rocket' ); ?></p>
		</div>
		<?php
	}

	// Tokens de design + componentes + correções de conflito entre wp-admin e Bootstrap.
	private function get_inline_css(): string {
		$css = <<<'CSS'
@font-face {
	font-family: "O2R Inter";
	font-style: normal;
	font-weight: 100 900;
	font-display: swap;
	src: url("{{font_url}}") format("woff2-variations"), url("{{font_url}}") format("woff2");
	unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
}

body.o2r-admin {
	background: #F8FAFC;
	color: #0F172A;
	font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
	font-size: 13px;
	line-height: 1.4em;
}
body.o2r-admin #wpcontent {
	padding-left: 0;
}
body.o2r-admin #wpbody-content {
	padding-bottom: 0;
}
body.o2r-admin #wpfooter {
	display: none;
}
body.o2r-admin #wpbody-content > .update-nag {
	margin: 16px 32px;
}

.o2r-wrap[data-bs-theme] {
	--o2r-brand: #1188CC;
	--o2r-brand-hover: #0F79B5;
	--o2r-brand-active: #0D6A9F;
	--o2r-brand-ink: #0A6699;
	--o2r-brand-50: #EEF7FC;
	--o2r-brand-100: #DCEEF8;
	--o2r-brand-ring: rgba(17, 136, 204, .2);
	--o2r-bg: #F8FAFC;
	--o2r-surface: #FFFFFF;
	--o2r-subtle: #F1F5F9;
	--o2r-border: #E2E8F0;
	--o2r-border-strong: #CBD5E1;
	--o2r-text: #0F172A;
	--o2r-muted: #64748B;
	--o2r-placeholder: #94A3B8;
	--o2r-radius: 6px;
	--o2r-radius-sm: 4px;
	--o2r-shadow: 0 1px 2px 0 rgba(0, 0, 0, .05);
	--o2r-font: "O2R Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", sans-serif;
	--o2r-mono: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
	--bs-body-color: #0F172A;
	--bs-body-bg: #FFFFFF;
	--bs-body-font-family: var(--o2r-font);
	--bs-body-font-size: 15px;
	--bs-border-color: #E2E8F0;
	--bs-border-radius: 4px;
	--bs-secondary-color: #64748B;
	--bs-primary: #1188CC;
	--bs-primary-rgb: 17, 136, 204;
	--bs-link-color: #1188CC;
	--bs-link-color-rgb: 17, 136, 204;
	--bs-link-hover-color: #0F79B5;
	--bs-link-hover-color-rgb: 15, 121, 181;
	margin: 0;
	color: var(--o2r-text);
	font-family: var(--o2r-font);
	font-size: 15px;
	line-height: 1.55;
	-webkit-font-smoothing: antialiased;
	-moz-osx-font-smoothing: grayscale;
}
.o2r-wrap p {
	margin: 0;
	font-size: inherit;
}
.o2r-wrap a {
	color: var(--o2r-brand);
}
.o2r-wrap .wp-header-end {
	display: none;
}
.o2r-wrap .tab-pane:focus {
	outline: none;
}
.o2r-wrap h1,
.o2r-wrap h2,
.o2r-wrap h3 {
	font-family: var(--o2r-font);
}

.o2r-topbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 24px;
	height: 96px;
	padding: 0 32px;
	background: #1188CC;
	color: #FFFFFF;
}
.o2r-brand {
	display: flex;
	align-items: center;
	gap: 16px;
	min-width: 0;
}
.o2r-brand-logo {
	display: block;
	flex-shrink: 0;
	width: auto;
	height: 64px;
}
.o2r-wrap .o2r-brand-title {
	margin: 0;
	padding: 0;
	color: #FFFFFF;
	font-size: 26px;
	font-weight: 700;
	line-height: 32px;
	letter-spacing: -.02em;
	white-space: nowrap;
}
.o2r-brand-divider {
	width: 1px;
	height: 28px;
	margin: 0 4px;
	background: rgba(255, 255, 255, .35);
}
.o2r-brand-tagline {
	color: rgba(255, 255, 255, .88);
	font-size: 16px;
	font-weight: 500;
	white-space: nowrap;
}
.o2r-topbar-meta {
	padding: 7px 12px;
	background: rgba(255, 255, 255, .14);
	border: 1px solid rgba(255, 255, 255, .24);
	border-radius: var(--o2r-radius-sm);
	color: #FFFFFF;
	font-size: 13px;
	font-weight: 600;
	white-space: nowrap;
}

.o2r-shell {
	display: grid;
	grid-template-columns: 264px minmax(0, 1fr);
	min-height: calc(100vh - var(--wp-admin--admin-bar--height, 32px) - 96px);
}
.o2r-sidebar {
	min-width: 0;
	padding: 28px 16px;
	background: var(--o2r-surface);
	border-right: 1px solid var(--o2r-border);
}
.o2r-nav {
	position: sticky;
	top: calc(var(--wp-admin--admin-bar--height, 32px) + 28px);
	display: flex;
	flex-direction: column;
	gap: 4px;
}
.o2r-nav-heading {
	margin: 28px 0 8px;
	padding: 0 14px;
	color: var(--o2r-muted);
	font-size: 12px;
	font-weight: 600;
	line-height: 16px;
	letter-spacing: .07em;
	text-transform: uppercase;
}
.o2r-nav-heading:first-child {
	margin-top: 0;
}
.o2r-wrap .o2r-nav-link {
	display: block;
	padding: 10px 14px;
	border-radius: var(--o2r-radius-sm);
	color: var(--o2r-muted);
	font-size: 15px;
	font-weight: 500;
	line-height: 22px;
	text-decoration: none;
	transition: background-color .12s ease, color .12s ease, box-shadow .12s ease;
}
.o2r-wrap .o2r-nav-link:hover {
	background: var(--o2r-subtle);
	color: var(--o2r-text);
}
.o2r-wrap .o2r-nav-link.active {
	background: rgba(17, 136, 204, .1);
	color: var(--o2r-brand-ink);
	font-weight: 600;
}
.o2r-wrap .o2r-nav-link:focus {
	outline: none;
}
.o2r-wrap .o2r-nav-link:focus-visible {
	outline: 2px solid var(--o2r-brand);
	outline-offset: -2px;
}

.o2r-main {
	min-width: 0;
	padding: 40px 48px 64px;
}
.o2r-content {
	max-width: 1200px;
}
.o2r-page-header {
	margin-bottom: 32px;
}
.o2r-wrap .o2r-page-title {
	margin: 0 0 6px;
	padding: 0;
	color: var(--o2r-text);
	font-size: 30px;
	font-weight: 700;
	line-height: 38px;
	letter-spacing: -.02em;
}
.o2r-page-description {
	max-width: 760px;
	color: var(--o2r-muted);
	font-size: 16px;
}

.o2r-card {
	margin-bottom: 24px;
	background: var(--o2r-surface);
	border: 1px solid var(--o2r-border);
	border-radius: var(--o2r-radius);
	box-shadow: var(--o2r-shadow);
}
.o2r-card-header {
	padding: 22px 28px;
	border-bottom: 1px solid var(--o2r-border);
}
.o2r-card-header--split {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
}
.o2r-wrap .o2r-card-title {
	margin: 0;
	padding: 0;
	color: var(--o2r-text);
	font-size: 18px;
	font-weight: 600;
	line-height: 26px;
	letter-spacing: -.01em;
}
.o2r-card-description {
	margin-top: 4px;
	color: var(--o2r-muted);
	font-size: 14px;
}
.o2r-card-body {
	padding: 28px;
}
.o2r-card-foot {
	margin-top: 24px;
	padding-top: 16px;
	border-top: 1px solid var(--o2r-border);
	color: var(--o2r-muted);
	font-size: 13px;
}
.o2r-section-intro + .o2r-fields {
	margin-top: 24px;
	padding-top: 24px;
	border-top: 1px solid var(--o2r-border);
}
.o2r-section-intro > * + * {
	margin-top: 20px;
}
.o2r-wrap .o2r-section-intro > :last-child {
	margin-bottom: 0;
}

.o2r-grid {
	display: grid;
	gap: 24px;
	margin-bottom: 24px;
}
.o2r-grid--2 {
	grid-template-columns: repeat(2, minmax(0, 1fr));
}
.o2r-grid > .o2r-card {
	margin-bottom: 0;
}

.o2r-field {
	display: grid;
	grid-template-columns: 260px minmax(0, 1fr);
	gap: 12px 40px;
	padding: 24px 0;
	border-top: 1px solid var(--o2r-border);
}
.o2r-field:first-child {
	padding-top: 0;
	border-top: 0;
}
.o2r-field:last-child {
	padding-bottom: 0;
}
.o2r-field-label {
	padding-top: 2px;
	color: var(--o2r-text);
	font-size: 15px;
	font-weight: 600;
	line-height: 22px;
}
.o2r-field-label label {
	cursor: default;
	vertical-align: baseline;
}
.o2r-field-control {
	min-width: 0;
}

.o2r-muted {
	color: var(--o2r-muted);
}
.o2r-mono {
	font-family: var(--o2r-mono);
	font-size: 13px;
}
.o2r-wrap code {
	padding: 2px 6px;
	background: var(--o2r-subtle);
	border: 1px solid var(--o2r-border);
	border-radius: var(--o2r-radius-sm);
	color: var(--o2r-text);
	font-family: var(--o2r-mono);
	font-size: 12.5px;
}

.o2r-list {
	background: var(--o2r-surface);
	border: 1px solid var(--o2r-border);
	border-radius: var(--o2r-radius);
}
.o2r-list--flush {
	border: 0;
	border-radius: 0;
	background: transparent;
}
.o2r-list-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
	padding: 14px 18px;
}
.o2r-list--flush .o2r-list-row {
	padding: 16px 28px;
}
.o2r-list-row + .o2r-list-row {
	border-top: 1px solid var(--o2r-border);
}
.o2r-list-text {
	min-width: 0;
}
.o2r-list-title {
	color: var(--o2r-text);
	font-weight: 500;
}
.o2r-list-meta {
	margin-top: 2px;
	color: var(--o2r-muted);
	font-size: 13.5px;
	overflow-wrap: anywhere;
}

.o2r-badge {
	display: inline-flex;
	flex-shrink: 0;
	align-items: center;
	height: 26px;
	padding: 0 10px;
	background: var(--o2r-subtle);
	border: 1px solid var(--o2r-border);
	border-radius: var(--o2r-radius-sm);
	color: var(--o2r-muted);
	font-size: 13px;
	font-weight: 600;
	line-height: 1;
	white-space: nowrap;
}
.o2r-badge--success {
	background: #F0FDF4;
	border-color: #BBF7D0;
	color: #15803D;
}
.o2r-badge--warning {
	background: #FFFBEB;
	border-color: #FDE68A;
	color: #B45309;
}
.o2r-badge--danger {
	background: #FEF2F2;
	border-color: #FECACA;
	color: #B91C1C;
}

.o2r-wrap .o2r-notice {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 16px;
	margin: 0 0 24px;
	padding: 14px 18px;
	background: var(--o2r-surface);
	border: 1px solid var(--o2r-border);
	border-radius: var(--o2r-radius);
	color: var(--o2r-text);
	font-size: 15px;
}
.o2r-wrap .o2r-notice--success {
	background: #F0FDF4;
	border-color: #BBF7D0;
	color: #166534;
}
.o2r-wrap .o2r-notice--warning {
	background: #FFFBEB;
	border-color: #FDE68A;
	color: #92400E;
}
.o2r-wrap .o2r-notice--danger {
	background: #FEF2F2;
	border-color: #FECACA;
	color: #991B1B;
}
.o2r-notice-message {
	min-width: 0;
}
.o2r-notice-message p + p {
	margin-top: 6px;
}
.o2r-notice-close {
	flex-shrink: 0;
	padding: 0;
	background: none;
	border: 0;
	color: inherit;
	font-size: 14px;
	font-weight: 600;
	cursor: pointer;
	opacity: .75;
}
.o2r-notice-close:hover,
.o2r-notice-close:focus-visible {
	opacity: 1;
	text-decoration: underline;
}

.o2r-wrap .o2r-button {
	display: inline-flex;
	flex-shrink: 0;
	align-items: center;
	justify-content: center;
	height: 40px;
	padding: 0 18px;
	background: var(--o2r-surface);
	border: 1px solid var(--o2r-border-strong);
	border-radius: var(--o2r-radius-sm);
	color: var(--o2r-text);
	font-family: var(--o2r-font);
	font-size: 14px;
	font-weight: 600;
	line-height: 1;
	text-decoration: none;
	white-space: nowrap;
	cursor: pointer;
	transition: background-color .12s ease, border-color .12s ease;
}
.o2r-wrap .o2r-button:hover {
	background: var(--o2r-bg);
}
.o2r-wrap .o2r-button:focus-visible {
	outline: none;
	border-color: var(--o2r-brand);
	box-shadow: 0 0 0 3px var(--o2r-brand-ring);
}
.o2r-wrap .o2r-button:disabled {
	cursor: progress;
	opacity: .6;
}
.o2r-wrap .o2r-button--primary {
	background: var(--o2r-brand);
	border-color: var(--o2r-brand);
	color: #FFFFFF;
}
.o2r-wrap .o2r-button--primary:hover {
	background: var(--o2r-brand-hover);
	border-color: var(--o2r-brand-hover);
}
.o2r-wrap .o2r-button--primary:active {
	background: var(--o2r-brand-active);
	border-color: var(--o2r-brand-active);
}

.o2r-form-footer {
	position: sticky;
	bottom: 0;
	z-index: 5;
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	padding: 20px 0;
	background: var(--o2r-bg);
	border-top: 1px solid var(--o2r-border);
}

.o2r-empty {
	padding: 64px 28px;
	text-align: center;
}
.o2r-empty-title {
	color: var(--o2r-text);
	font-size: 16px;
	font-weight: 600;
}
.o2r-empty-text {
	margin-top: 6px;
	color: var(--o2r-muted);
	font-size: 14px;
}

.o2r-gauge {
	display: flex;
	flex-direction: column;
	align-items: center;
	padding-top: 32px;
}
.o2r-gauge-svg {
	display: block;
	width: 100%;
	max-width: 360px;
	height: auto;
	overflow: visible;
}
.o2r-gauge-track {
	stroke: var(--o2r-brand-100);
}
.o2r-gauge-fill {
	stroke: var(--o2r-brand);
}
.o2r-gauge-tick {
	stroke: var(--o2r-border-strong);
	stroke-width: 1.5;
}
.o2r-gauge-label {
	fill: var(--o2r-muted);
	font-family: var(--o2r-font);
	font-size: 11px;
	font-weight: 500;
}
.o2r-gauge-needle {
	stroke: var(--o2r-text);
	stroke-width: 3;
	stroke-linecap: round;
}
.o2r-gauge-hub {
	fill: var(--o2r-text);
	stroke: var(--o2r-surface);
	stroke-width: 3;
}
.o2r-gauge-readout {
	display: flex;
	align-items: baseline;
	gap: 4px;
	margin-top: 12px;
}
.o2r-gauge-value {
	color: var(--o2r-text);
	font-size: 64px;
	font-weight: 700;
	line-height: 1;
	letter-spacing: -.04em;
}
.o2r-gauge-max {
	color: var(--o2r-muted);
	font-size: 22px;
	font-weight: 600;
}
.o2r-gauge-rating {
	margin-top: 14px;
}

.o2r-legend {
	display: flex;
	flex-wrap: wrap;
	gap: 10px 20px;
	margin: 20px 0 0;
	padding: 0;
	list-style: none;
}
.o2r-legend--center {
	justify-content: center;
	margin-top: 24px;
}
.o2r-legend-item {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	margin: 0;
	color: var(--o2r-muted);
	font-size: 13.5px;
}
.o2r-legend-item strong {
	color: var(--o2r-text);
	font-weight: 600;
}
.o2r-legend-key {
	width: 10px;
	height: 10px;
	border-radius: 2px;
}

.o2r-speedup {
	display: flex;
	align-items: baseline;
	flex-wrap: wrap;
	gap: 10px;
	margin-bottom: 28px;
}
.o2r-speedup-value {
	color: var(--o2r-brand-ink);
	font-size: 48px;
	font-weight: 700;
	line-height: 1;
	letter-spacing: -.03em;
}
.o2r-speedup-label {
	color: var(--o2r-muted);
	font-size: 16px;
	font-weight: 500;
}
.o2r-bars {
	display: grid;
	gap: 18px;
}
.o2r-bar-row {
	display: grid;
	grid-template-columns: 124px minmax(0, 1fr);
	align-items: center;
	gap: 16px;
}
.o2r-bar-label {
	color: var(--o2r-text);
	font-size: 14px;
	font-weight: 500;
}
.o2r-bar-plot {
	display: flex;
	align-items: center;
	gap: 12px;
	min-height: 36px;
	border-left: 1px solid var(--o2r-border-strong);
}
.o2r-bar {
	display: block;
	flex-shrink: 0;
	width: calc((100% - 96px) * var(--o2r-ratio, 0));
	min-width: 4px;
	height: 24px;
	background: var(--o2r-brand);
	border-radius: 0 4px 4px 0;
	transition: filter .12s ease;
}
.o2r-bar:hover {
	filter: brightness(1.08);
}
.o2r-bar--muted {
	background: var(--o2r-border-strong);
}
.o2r-bar-value {
	color: var(--o2r-text);
	font-size: 14px;
	font-weight: 600;
	white-space: nowrap;
}
.o2r-chart-empty {
	padding: 40px 0 16px;
	text-align: center;
}
.o2r-mini-stats {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	margin-top: 28px;
	border: 1px solid var(--o2r-border);
	border-radius: var(--o2r-radius);
	background: var(--o2r-brand-50);
}
.o2r-mini-stat {
	padding: 18px 20px;
}
.o2r-mini-stat + .o2r-mini-stat {
	border-left: 1px solid var(--o2r-border);
}
.o2r-mini-stat-label {
	color: var(--o2r-muted);
	font-size: 13px;
	font-weight: 500;
}
.o2r-mini-stat-value {
	margin-top: 4px;
	color: var(--o2r-text);
	font-size: 20px;
	font-weight: 700;
	line-height: 28px;
	letter-spacing: -.01em;
}

.o2r-kpis {
	display: grid;
	grid-template-columns: repeat(4, minmax(0, 1fr));
	gap: 24px;
	margin-bottom: 24px;
}
.o2r-kpi {
	padding: 22px 24px 24px;
	background: var(--o2r-surface);
	border: 1px solid var(--o2r-border);
	border-top: 3px solid var(--o2r-brand);
	border-radius: var(--o2r-radius);
	box-shadow: var(--o2r-shadow);
}
.o2r-kpi-label {
	color: var(--o2r-muted);
	font-size: 14px;
	font-weight: 500;
}
.o2r-kpi-value {
	margin-top: 8px;
	color: var(--o2r-text);
	font-size: 32px;
	font-weight: 700;
	line-height: 38px;
	letter-spacing: -.02em;
}
.o2r-kpi-meta {
	margin-top: 6px;
	color: var(--o2r-muted);
	font-size: 13px;
}

.o2r-meter-row {
	padding: 18px 28px;
}
.o2r-meter-row + .o2r-meter-row {
	border-top: 1px solid var(--o2r-border);
}
.o2r-meter-head {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	gap: 16px;
	margin-bottom: 10px;
}
.o2r-meter-label {
	color: var(--o2r-text);
	font-weight: 500;
}
.o2r-meter-points {
	color: var(--o2r-text);
	font-size: 14px;
	font-weight: 600;
	font-variant-numeric: tabular-nums;
}
.o2r-meter {
	display: block;
	height: 8px;
	overflow: hidden;
	background: var(--o2r-brand-100);
	border-radius: 4px;
}
.o2r-meter-fill {
	display: block;
	width: calc(100% * var(--o2r-ratio, 0));
	height: 100%;
	background: var(--o2r-brand);
}
.o2r-meter-hint {
	margin-top: 8px;
	color: var(--o2r-muted);
	font-size: 13.5px;
}

.o2r-health-summary {
	border-bottom: 1px solid var(--o2r-border);
}
.o2r-stack {
	display: flex;
	gap: 2px;
	height: 12px;
	overflow: hidden;
	border-radius: 4px;
}
.o2r-stack-segment {
	display: block;
	flex-basis: 0;
	min-width: 4px;
	height: 100%;
}

.o2r-wrap .form-control,
.o2r-wrap .form-select {
	width: 100%;
	max-width: 100%;
	min-height: 44px;
	padding: 11px 14px;
	background-color: var(--o2r-surface);
	border: 1px solid var(--o2r-border-strong);
	border-radius: var(--o2r-radius-sm);
	box-shadow: none;
	color: var(--o2r-text);
	font-family: var(--o2r-font);
	font-size: 15px;
	line-height: 22px;
	transition: border-color .12s ease, box-shadow .12s ease;
}
.o2r-wrap textarea.form-control {
	min-height: 150px;
	resize: vertical;
}
.o2r-wrap .form-control.font-monospace {
	font-family: var(--o2r-mono);
	font-size: 13.5px;
}
.o2r-wrap .form-select {
	padding-right: 40px;
	background: var(--o2r-surface) var(--bs-form-select-bg-img) no-repeat right 14px center / 16px 12px;
}
.o2r-wrap .form-control::placeholder {
	color: var(--o2r-placeholder);
	opacity: 1;
}
.o2r-wrap .form-control:focus,
.o2r-wrap .form-select:focus {
	outline: none;
	border-color: var(--o2r-brand);
	box-shadow: 0 0 0 3px var(--o2r-brand-ring);
}
.o2r-wrap .form-text {
	margin-top: 8px;
	color: var(--o2r-muted);
	font-size: 13.5px;
	line-height: 20px;
}

.o2r-wrap .form-check {
	min-height: 24px;
	margin: 0;
}
.o2r-wrap .form-check-label {
	color: var(--o2r-text);
	font-size: 15px;
	line-height: 24px;
}
.o2r-wrap .form-check-input {
	width: 18px;
	height: 18px;
	min-width: 18px;
	margin-top: 3px;
	margin-right: 0;
	background-color: var(--o2r-surface);
	background-repeat: no-repeat;
	background-position: center;
	background-size: contain;
	border: 1px solid var(--o2r-border-strong);
	border-radius: var(--o2r-radius-sm);
	box-shadow: none;
}
/* O wp-admin redefine "background" em input:checked (0,2,1): repetição, posição e tamanho precisam ser reafirmados. */
.o2r-wrap .form-check-input:checked {
	background-color: var(--o2r-brand);
	background-image: var(--bs-form-check-bg-image);
	background-repeat: no-repeat;
	background-position: center;
	background-size: contain;
	border-color: var(--o2r-brand);
}
.o2r-wrap .form-check-input:checked::before {
	content: none;
}
.o2r-wrap .form-check-input:focus {
	outline: none;
	border-color: var(--o2r-brand);
	box-shadow: 0 0 0 3px var(--o2r-brand-ring);
}
.o2r-wrap .form-switch {
	padding-left: 58px;
}
.o2r-wrap .form-switch .form-check-input {
	--bs-form-switch-bg: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='-4 -4 8 8'%3e%3crect x='-3' y='-3' width='6' height='6' rx='1' fill='%23fff'/%3e%3c/svg%3e");
	width: 44px;
	height: 24px;
	margin-top: 0;
	margin-left: -58px;
	background-color: var(--o2r-border-strong);
	background-image: var(--bs-form-switch-bg);
	background-position: left center;
	border-color: var(--o2r-border-strong);
	border-radius: var(--o2r-radius-sm);
	transition: background-position .15s ease, background-color .15s ease, border-color .15s ease;
}
.o2r-wrap .form-switch .form-check-input:focus {
	--bs-form-switch-bg: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='-4 -4 8 8'%3e%3crect x='-3' y='-3' width='6' height='6' rx='1' fill='%23fff'/%3e%3c/svg%3e");
}
.o2r-wrap .form-switch .form-check-input:checked {
	--bs-form-switch-bg: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='-4 -4 8 8'%3e%3crect x='-3' y='-3' width='6' height='6' rx='1' fill='%23fff'/%3e%3c/svg%3e");
	background-color: var(--o2r-brand);
	background-image: var(--bs-form-switch-bg);
	background-repeat: no-repeat;
	background-position: right center;
	background-size: contain;
	border-color: var(--o2r-brand);
}

.o2r-dark-card {
	overflow: hidden;
}
.o2r-dark-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
	padding: 18px 28px;
	background: #1E293B;
	color: #FFFFFF;
}
.o2r-wrap .o2r-dark-title {
	margin: 0;
	padding: 0;
	color: #FFFFFF;
	font-size: 17px;
	font-weight: 600;
	line-height: 24px;
	letter-spacing: -.01em;
}
.o2r-dark-meta {
	color: rgba(255, 255, 255, .72);
	font-size: 13px;
	font-weight: 500;
	white-space: nowrap;
}

.o2r-overview {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
}
.o2r-overview-data {
	display: flex;
	flex-direction: column;
	justify-content: center;
	gap: 28px;
	padding: 36px;
}
.o2r-overview-chart {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: center;
	gap: 32px 40px;
	padding: 36px;
	border-left: 1px solid var(--o2r-border);
}
.o2r-overview-figure {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 6px 14px;
}
.o2r-overview-number {
	color: var(--o2r-text);
	font-size: 56px;
	font-weight: 700;
	line-height: 1;
	letter-spacing: -.04em;
}
.o2r-overview-number--accent {
	color: var(--o2r-brand);
}
.o2r-overview-caption {
	color: var(--o2r-muted);
	font-size: 16px;
	font-weight: 500;
}
.o2r-size-bars {
	display: grid;
	gap: 14px;
}
.o2r-size-bar-row {
	display: grid;
	grid-template-columns: 112px minmax(0, 1fr) 92px;
	align-items: center;
	gap: 14px;
}
.o2r-size-bar-label {
	color: var(--o2r-text);
	font-size: 14px;
	font-weight: 500;
}
.o2r-size-bar-track {
	display: block;
	height: 12px;
	overflow: hidden;
	background: var(--o2r-subtle);
	border-radius: 4px;
}
.o2r-size-bar-fill {
	display: block;
	width: calc(100% * var(--o2r-ratio, 0));
	height: 100%;
	border-radius: 0 4px 4px 0;
}
.o2r-size-bar-fill--original {
	background: #334155;
}
.o2r-size-bar-fill--optimized {
	background: #7CC4EB;
}
.o2r-size-bar-value {
	color: var(--o2r-text);
	font-size: 14px;
	font-weight: 600;
	text-align: right;
	white-space: nowrap;
	font-variant-numeric: tabular-nums;
}
.o2r-donut {
	position: relative;
	flex-shrink: 0;
	width: 200px;
	height: 200px;
}
.o2r-donut-svg {
	display: block;
	width: 100%;
	height: 100%;
}
.o2r-donut-track {
	stroke: var(--o2r-subtle);
}
.o2r-donut-center {
	position: absolute;
	inset: 0;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
}
.o2r-donut-value {
	color: var(--o2r-text);
	font-size: 38px;
	font-weight: 700;
	line-height: 1;
	letter-spacing: -.03em;
}
.o2r-donut-caption {
	margin-top: 6px;
	color: var(--o2r-muted);
	font-size: 13px;
	font-weight: 500;
}
.o2r-bubble-legend {
	display: grid;
	gap: 14px;
	margin: 0;
	padding: 0;
	list-style: none;
}
.o2r-bubble-item {
	display: grid;
	grid-template-columns: 14px auto auto;
	align-items: center;
	justify-content: start;
	gap: 10px;
	margin: 0;
}
.o2r-bubble {
	width: 14px;
	height: 14px;
	border-radius: 50%;
}
.o2r-bubble-label {
	color: var(--o2r-text);
	font-size: 14px;
	font-weight: 500;
}
.o2r-bubble-count {
	color: var(--o2r-muted);
	font-size: 14px;
	font-weight: 600;
	font-variant-numeric: tabular-nums;
}
.o2r-bulk-intro {
	max-width: 860px;
}
.o2r-bulk-progress {
	display: flex;
	align-items: center;
	gap: 16px;
	margin-top: 24px;
}
.o2r-bulk-track {
	flex: 1;
	height: 14px;
	overflow: hidden;
	background: var(--o2r-brand-100);
	border-radius: 4px;
}
.o2r-bulk-fill {
	display: block;
	height: 100%;
	background: var(--o2r-brand);
	transition: width .4s ease;
}
.o2r-bulk-percent {
	min-width: 56px;
	color: var(--o2r-text);
	font-size: 17px;
	font-weight: 700;
	text-align: right;
	font-variant-numeric: tabular-nums;
}
.o2r-bulk-footer {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
	margin-top: 20px;
}
.o2r-bulk-status {
	color: var(--o2r-muted);
	font-size: 14px;
}
.o2r-actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 12px 16px;
}
.o2r-section-intro .o2r-preload > * + * {
	margin-top: 16px;
}
.o2r-preload .o2r-bulk-progress {
	margin-top: 0;
}
.o2r-preload .o2r-card-foot {
	margin-top: 0;
	padding-top: 0;
	border-top: 0;
}
.o2r-checkbox-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
	gap: 8px 24px;
}
.o2r-checkbox-grid .form-check-label {
	font-size: 14px;
}
.o2r-inline-fields {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px 12px;
}
.o2r-wrap .o2r-input-number {
	width: 120px;
}
.o2r-wrap .o2r-input-unit {
	width: auto;
	min-width: 140px;
}
.o2r-wrap textarea[readonly] {
	background: var(--o2r-subtle);
}
.o2r-media-dashboard {
	margin-bottom: 8px;
}

@media (max-width: 1279px) {
	.o2r-grid--2 {
		grid-template-columns: minmax(0, 1fr);
	}
	.o2r-overview {
		grid-template-columns: minmax(0, 1fr);
	}
	.o2r-overview-chart {
		border-top: 1px solid var(--o2r-border);
		border-left: 0;
	}
	.o2r-kpis {
		grid-template-columns: repeat(2, minmax(0, 1fr));
	}
}
@media (max-width: 960px) {
	.o2r-shell {
		grid-template-columns: minmax(0, 1fr);
		grid-template-rows: auto 1fr;
	}
	.o2r-sidebar {
		padding: 10px 16px;
		border-right: 0;
		border-bottom: 1px solid var(--o2r-border);
		overflow-x: auto;
	}
	.o2r-nav {
		position: static;
		flex-direction: row;
	}
	.o2r-nav-heading {
		display: none;
	}
	.o2r-wrap .o2r-nav-link {
		white-space: nowrap;
	}
	.o2r-main {
		padding: 28px 16px 48px;
	}
}
@media (max-width: 782px) {
	.o2r-topbar {
		height: 76px;
		padding: 0 16px;
	}
	.o2r-brand-logo {
		height: 48px;
	}
	.o2r-wrap .o2r-brand-title {
		font-size: 21px;
	}
	.o2r-brand-divider,
	.o2r-brand-tagline {
		display: none;
	}
	.o2r-wrap .o2r-page-title {
		font-size: 24px;
		line-height: 32px;
	}
	.o2r-field {
		grid-template-columns: minmax(0, 1fr);
	}
	.o2r-kpis {
		grid-template-columns: minmax(0, 1fr);
	}
	.o2r-card-header,
	.o2r-card-body {
		padding-right: 20px;
		padding-left: 20px;
	}
	.o2r-list--flush .o2r-list-row,
	.o2r-meter-row {
		padding-right: 20px;
		padding-left: 20px;
	}
	.o2r-list-row {
		flex-wrap: wrap;
	}
	.o2r-bar-row {
		grid-template-columns: minmax(0, 1fr);
		gap: 6px;
	}
	.o2r-overview-data,
	.o2r-overview-chart {
		padding: 24px 20px;
	}
	.o2r-overview-number {
		font-size: 44px;
	}
	.o2r-size-bar-row {
		grid-template-columns: minmax(0, 1fr) auto;
	}
	.o2r-size-bar-track {
		grid-column: 1 / -1;
		grid-row: 2;
	}
	.o2r-dark-header {
		padding: 16px 20px;
	}
}
CSS;

		return str_replace( '{{font_url}}', esc_url_raw( plugins_url( self::FONT_PATH, O2_ROCKET_FILE ) ), $css );
	}

	// Abas na URL, aviso de alterações não salvas e medição de tempo de resposta do Dashboard.
	private function get_inline_js(): string {
		return <<<'JS'
(() => {
	const nav = document.getElementById('o2r-nav');

	if (nav) {
		nav.addEventListener('shown.bs.tab', (event) => {
			const slug = event.target.getAttribute('data-o2r-tab');

			if (!slug) {
				return;
			}

			const url = new URL(window.location.href);
			url.searchParams.set('tab', slug);
			url.searchParams.delete('settings-updated');
			window.history.replaceState(null, '', url.toString());
		});
	}

	const forms = Array.from(document.querySelectorAll('.o2r-settings-form'));
	let submitting = null;

	forms.forEach((form) => {
		const markDirty = () => {
			form.dataset.o2rDirty = '1';
		};

		form.addEventListener('input', markDirty);
		form.addEventListener('change', markDirty);
		form.addEventListener('submit', () => {
			submitting = form;
		});
	});

	window.addEventListener('beforeunload', (event) => {
		const dirty = forms.some((form) => form !== submitting && form.dataset.o2rDirty === '1');

		if (dirty) {
			event.preventDefault();
			event.returnValue = '';
		}
	});

	const post = (element) => {
		const body = new FormData();
		body.append('action', element.dataset.action);
		body.append('_ajax_nonce', element.dataset.nonce);

		return fetch(element.dataset.ajaxUrl, { method: 'POST', credentials: 'same-origin', body })
			.then((response) => response.json())
			.then((json) => {
				if (json && json.success) {
					return json.data;
				}

				throw new Error(json && json.data && json.data.message ? json.data.message : element.dataset.labelError);
			});
	};

	const benchmark = document.querySelector('[data-o2r-benchmark]');

	if (benchmark) {
		const button = benchmark.querySelector('[data-o2r-benchmark-run]');
		const status = benchmark.querySelector('[data-o2r-benchmark-status]');

		const run = () => {
			button.disabled = true;
			status.textContent = benchmark.dataset.labelRunning;

			post(benchmark)
				.then(() => window.location.reload())
				.catch((error) => {
					button.disabled = false;
					status.textContent = error && error.message ? error.message : benchmark.dataset.labelError;
				});
		};

		button.addEventListener('click', run);

		if (benchmark.dataset.autorun === '1') {
			run();
		}
	}

	// Andamento do pré-carregamento: consulta a cada 5 s enquanto houver fila (a consulta também adianta a fila).
	const preload = document.querySelector('[data-o2r-preload]');

	if (preload && preload.dataset.running === '1') {
		const fill = preload.querySelector('[data-o2r-preload-fill]');
		const percent = preload.querySelector('[data-o2r-preload-percent]');
		const status = preload.querySelector('[data-o2r-preload-status]');
		const locale = document.documentElement.lang || 'pt-BR';

		const render = (data) => {
			const done = data.counts.completed + data.counts.failed;
			const value = data.total > 0 ? Math.min(100, (done / data.total) * 100) : 100;
			const shown = value > 0 && value < 10 ? Math.round(value * 10) / 10 : Math.round(value);

			fill.style.width = value.toFixed(1) + '%';
			fill.parentElement.setAttribute('aria-valuenow', String(Math.round(value)));
			percent.textContent = shown.toLocaleString(locale, { maximumFractionDigits: 1 }) + '%';

			Object.keys(data.counts).forEach((key) => {
				const badge = preload.querySelector('[data-o2r-preload-count="' + key + '"] .o2r-badge');

				if (badge) {
					badge.textContent = data.counts[key].toLocaleString(locale);
				}
			});

			status.textContent = data.running
				? preload.dataset.labelProgress.replace('%1$s', done.toLocaleString(locale)).replace('%2$s', data.total.toLocaleString(locale))
				: preload.dataset.labelDone;
		};

		const poll = () => {
			if (document.hidden) {
				window.setTimeout(poll, 5000);
				return;
			}

			post(preload)
				.then((data) => {
					render(data);

					if (data.running) {
						window.setTimeout(poll, 5000);
					}
				})
				.catch(() => {
					status.textContent = preload.dataset.labelError;
				});
		};

		window.setTimeout(poll, 1500);
	}

	// Fila da otimização em massa: um lote curto por requisição até não restar nada pendente.
	const bulk = document.querySelector('[data-o2r-bulk]');

	if (bulk) {
		const button = bulk.querySelector('[data-o2r-bulk-run]');
		const fill = bulk.querySelector('[data-o2r-bulk-fill]');
		const percent = bulk.querySelector('[data-o2r-bulk-percent]');
		const status = bulk.querySelector('[data-o2r-bulk-status]');
		const total = parseInt(bulk.dataset.total, 10) || 0;
		const locale = document.documentElement.lang || 'pt-BR';
		let processed = parseInt(bulk.dataset.processed, 10) || 0;
		let running = false;
		let idleLabel = button.textContent;

		const render = () => {
			const value = total > 0 ? Math.min(100, (processed / total) * 100) : 100;
			const shown = value > 0 && value < 10 ? Math.round(value * 10) / 10 : Math.round(value);

			fill.style.width = value.toFixed(1) + '%';
			fill.parentElement.setAttribute('aria-valuenow', String(Math.round(value)));
			percent.textContent = shown.toLocaleString(locale, { maximumFractionDigits: 1 }) + '%';
			status.textContent = bulk.dataset.labelProgress
				.replace('%1$s', processed.toLocaleString(locale))
				.replace('%2$s', total.toLocaleString(locale));
		};

		const stop = (message) => {
			running = false;
			button.disabled = false;
			button.textContent = idleLabel;
			status.textContent = message;
		};

		const step = () => {
			post(bulk)
				.then((data) => {
					processed = data.stats.optimized + data.stats.errors;
					render();

					// Sem progresso no lote, encerra para não entrar em laço.
					if (data.remaining > 0 && data.processed > 0) {
						step();
						return;
					}

					running = false;
					status.textContent = bulk.dataset.labelDone;
					window.setTimeout(() => window.location.reload(), 1200);
				})
				.catch((error) => stop(error && error.message ? error.message : bulk.dataset.labelError));
		};

		button.addEventListener('click', () => {
			if (running) {
				return;
			}

			running = true;
			idleLabel = button.textContent;
			button.disabled = true;
			button.textContent = bulk.dataset.labelRunning;
			render();
			step();
		});

		window.addEventListener('beforeunload', (event) => {
			if (running) {
				event.preventDefault();
				event.returnValue = '';
			}
		});
	}
})();
JS;
	}
}
