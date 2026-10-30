<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Preload;

use O2Rocket\Cache\Page_Cache;
use O2Rocket\Core\Request;

use function O2Rocket\script_url;

defined( 'ABSPATH' ) || exit;

// Pré-carrega a próxima página quando o visitante para o mouse sobre um link.
// WordPress 6.8+: ajusta as speculation rules do núcleo (prefetch, "moderate"). Sem elas, um script de 2 KB faz o mesmo.
final class Link_Prefetch {

	const SETTING_ENABLED = 'preload_links';

	const SCRIPT_HANDLE = 'o2-rocket-prefetch';
	const SCRIPT_PATH   = 'assets/js/prefetch.js';

	private Page_Cache $page_cache;

	public function __construct( Page_Cache $page_cache ) {
		$this->page_cache = $page_cache;
	}

	public function register_hooks(): void {
		if ( is_admin() || ! $this->is_enabled() ) {
			return;
		}

		add_filter( 'wp_speculation_rules_configuration', array( $this, 'filter_configuration' ) );
		add_filter( 'wp_speculation_rules_href_exclude_paths', array( $this, 'filter_exclude_paths' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_fallback' ) );
	}

	public function is_enabled(): bool {
		$stored = get_option( O2_ROCKET_OPTION, array() );

		return is_array( $stored ) && ! empty( $stored[ self::SETTING_ENABLED ] );
	}

	// Null (usuário logado, permalinks simples) continua desligado, como no núcleo.
	public function filter_configuration( $config ) {
		if ( null === $config ) {
			return $config;
		}

		return array(
			'mode'      => 'prefetch',
			'eagerness' => (string) apply_filters( 'o2_rocket_prefetch_eagerness', 'moderate' ),
		);
	}

	// Caminhos que o cache não guarda também não são pré-carregados (carrinho, exclusões do usuário).
	public function filter_exclude_paths( $paths ): array {
		$paths = is_array( $paths ) ? $paths : array();

		foreach ( $this->get_excluded_patterns() as $pattern ) {
			$paths[] = $pattern;
		}

		return array_values( array_unique( $paths ) );
	}

	// Fallback: navegadores sem speculation rules (Firefox, Safari) e WordPress anterior ao 6.8.
	public function enqueue_fallback(): void {
		if ( ! Request::is_public_frontend() || is_user_logged_in() || ! get_option( 'permalink_structure' ) ) {
			return;
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			script_url( self::SCRIPT_PATH ),
			array(),
			O2_ROCKET_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'o2rPrefetch',
			array(
				'exclude' => $this->get_exclude_regex_source(),
				'delay'   => (int) apply_filters( 'o2_rocket_prefetch_hover_delay', 100 ),
				'core'    => function_exists( 'wp_get_speculation_rules' ) ? 1 : 0,
			)
		);
	}

	// Padrões no formato das speculation rules: "/caminho/*" (prefixo) ou "/*termo*" (qualquer posição).
	private function get_excluded_patterns(): array {
		$patterns = array( '/*cart*', '/*checkout*', '/*my-account*', '/*carrinho*', '/*finalizar-compra*', '/*minha-conta*', '/*add-to-cart*', '/*logout*' );
		$settings = $this->page_cache->get_settings();

		foreach ( Page_Cache::sanitize_uri_patterns( $settings[ Page_Cache::SETTING_EXCLUDED_URIS ] ) as $pattern ) {
			$patterns[] = '/' === $pattern[0] ? rtrim( $pattern, '*' ) . '*' : '/*' . trim( $pattern, '*' ) . '*';
		}

		return $patterns;
	}

	// Mesma lista para o script (RegExp do JavaScript, sem delimitadores).
	private function get_exclude_regex_source(): string {
		$parts = array( '/wp-admin/', '/wp-login\\.php', '/wp-json/', '\\.(?:xml|txt|pdf|zip|jpe?g|png|gif|webp|avif|svg|mp4|mp3)$' );

		foreach ( $this->get_excluded_patterns() as $pattern ) {
			$parts[] = str_replace( '\\*', '.*', preg_quote( ltrim( $pattern, '/' ), '/' ) );
		}

		return implode( '|', $parts );
	}
}
