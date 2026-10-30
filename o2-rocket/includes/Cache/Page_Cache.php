<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Cache;

use function O2Rocket\atomic_write;
use function O2Rocket\direct_filesystem;

defined( 'ABSPATH' ) || exit;

final class Page_Cache {

	const SETTING_ENABLED       = 'page_cache_enabled';
	const SETTING_EXCLUDED_URIS = 'page_cache_excluded_uris';
	const DEFAULT_LIFESPAN      = 36000;
	const MIN_HTML_LENGTH       = 255;
	const MAX_PATTERNS          = 500;

	private Drop_In_Manager $drop_in;

	private ?array $settings = null;

	private string $cache_file = '';

	private string $captured = '';

	private array $pending_urls = array();

	private array $purged = array();

	public function __construct( Drop_In_Manager $drop_in ) {
		$this->drop_in = $drop_in;
	}

	public function register_hooks(): void {
		add_action( 'add_option_' . O2_ROCKET_OPTION, array( $this, 'on_settings_added' ), 10, 2 );
		add_action( 'update_option_' . O2_ROCKET_OPTION, array( $this, 'on_settings_updated' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'maybe_repair_dropin' ) );
		add_action( 'o2_rocket_deactivate', array( $this, 'on_deactivate' ) );
		add_action( 'o2_rocket_purge_cache', array( $this, 'purge_all' ) );

		if ( ! $this->is_enabled() ) {
			return;
		}

		add_action( 'template_redirect', array( $this, 'start_buffer' ), 9999 );

		add_action( 'pre_post_update', array( $this, 'remember_post_urls' ) );
		add_action( 'save_post', array( $this, 'on_save_post' ), 10, 2 );
		add_action( 'wp_trash_post', array( $this, 'on_trash_post' ) );
		add_action( 'comment_post', array( $this, 'on_comment_post' ), 10, 2 );
		add_action( 'transition_comment_status', array( $this, 'on_comment_status_changed' ), 10, 3 );

		add_action( 'switch_theme', array( $this, 'purge_all' ) );
		add_action( 'customize_save_after', array( $this, 'purge_all' ) );
		add_action( 'wp_update_nav_menu', array( $this, 'purge_all' ) );
		add_action( 'update_option_permalink_structure', array( $this, 'on_permalinks_changed' ) );
	}

	public function get_settings(): array {
		if ( null === $this->settings ) {
			$this->settings = $this->parse_settings( get_option( O2_ROCKET_OPTION, array() ) );
		}

		return $this->settings;
	}

	public function is_enabled(): bool {
		return rest_sanitize_boolean( $this->get_settings()[ self::SETTING_ENABLED ] );
	}

	public function start_buffer(): void {
		if ( ! $this->is_cacheable_request() ) {
			return;
		}

		$this->cache_file = $this->get_request_cache_file();
		$this->captured   = '';

		if ( '' === $this->cache_file ) {
			return;
		}

		if ( ! headers_sent() ) {
			header( 'X-O2-Rocket-Cache: MISS' );
		}

		ob_start( array( $this, 'process_buffer' ) );
	}

	// Acumula os blocos (caso algo chame ob_flush) e grava apenas no fechamento do buffer.
	public function process_buffer( $buffer, $phase = PHP_OUTPUT_HANDLER_FINAL ) {
		$buffer = (string) $buffer;

		if ( $phase & PHP_OUTPUT_HANDLER_CLEAN ) {
			return $buffer;
		}

		$this->captured .= $buffer;

		if ( $phase & PHP_OUTPUT_HANDLER_FINAL ) {
			$this->save( $this->captured );
			$this->captured = '';
		}

		return $buffer;
	}

	public function is_cacheable_request(): bool {
		if ( ! $this->is_enabled() ) {
			return false;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

		if ( 'GET' !== $method ) {
			return false;
		}

		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return false;
		}

		if ( is_user_logged_in() ) {
			return false;
		}

		if ( is_search() || is_404() || is_feed() || is_preview() || is_trackback() || is_robots() || is_favicon() || is_customize_preview() ) {
			return false;
		}

		if ( is_singular() && post_password_required() ) {
			return false;
		}

		if ( $this->is_woocommerce_private_page() ) {
			return false;
		}

		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return false;
		}

		if ( $this->has_bypass_cookie() ) {
			return false;
		}

		$path = $this->get_request_path();

		if ( '' === $path || $this->is_excluded_path( $path ) ) {
			return false;
		}

		return (bool) apply_filters( 'o2_rocket_can_cache_request', true );
	}

	// Antes de salvar: guarda as URLs antigas (troca de slug, despublicação, troca de termos).
	public function remember_post_urls( $post_id ): void {
		$post = get_post( (int) $post_id );

		if ( $post instanceof \WP_Post && $this->is_purgeable_post( $post ) && 'publish' === $post->post_status ) {
			$this->pending_urls[ $post->ID ] = $this->get_post_related_urls( $post );
		}
	}

	public function on_save_post( $post_id, $post ): void {
		$post_id = (int) $post_id;

		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$urls = isset( $this->pending_urls[ $post_id ] ) ? $this->pending_urls[ $post_id ] : array();
		unset( $this->pending_urls[ $post_id ] );

		if ( $this->is_purgeable_post( $post ) && 'publish' === $post->post_status ) {
			$urls = array_merge( $urls, $this->get_post_related_urls( $post ) );
		}

		$this->purge_urls( $urls );
	}

	public function on_trash_post( $post_id ): void {
		$post = get_post( (int) $post_id );

		if ( $post instanceof \WP_Post && $this->is_purgeable_post( $post ) && 'publish' === $post->post_status ) {
			$this->purge_urls( $this->get_post_related_urls( $post ) );
		}
	}

	public function on_comment_post( $comment_id, $approved ): void {
		if ( 1 === (int) $approved ) {
			$this->purge_comment_post( $comment_id );
		}
	}

	public function on_comment_status_changed( $new_status, $old_status, $comment ): void {
		if ( 'approved' === $new_status || 'approved' === $old_status ) {
			$this->purge_comment_post( $comment );
		}
	}

	public function purge_urls( array $urls ): void {
		foreach ( array_unique( array_filter( $urls, 'is_string' ) ) as $url ) {
			$this->purge_url( $url );
		}
	}

	// Home: só os arquivos da raiz + paginação. Demais URLs: diretório inteiro (inclui /page/N e /comment-page-N).
	public function purge_url( string $url ): bool {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}

		$host     = self::normalize_host( $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) );
		$segments = self::get_path_segments( isset( $parts['path'] ) ? $parts['path'] : '/' );

		if ( '' === $host || null === $segments ) {
			return false;
		}

		$directory = self::get_cache_dir( $host, $segments );

		if ( isset( $this->purged[ $directory ] ) ) {
			return true;
		}

		$this->purged[ $directory ] = true;

		$filesystem = direct_filesystem();

		if ( ! $filesystem->is_dir( $directory ) ) {
			return true;
		}

		if ( $segments !== self::get_path_segments( $this->get_home_path() ) ) {
			return $filesystem->delete( $directory, true );
		}

		$deleted = true;

		foreach ( array( 'index.html', 'index-https.html' ) as $file ) {
			if ( $filesystem->exists( $directory . $file ) ) {
				$deleted = $filesystem->delete( $directory . $file ) && $deleted;
			}
		}

		$pagination = $directory . $this->get_pagination_base() . '/';

		if ( $filesystem->is_dir( $pagination ) ) {
			$deleted = $filesystem->delete( $pagination, true ) && $deleted;
		}

		return $deleted;
	}

	public function purge_all(): bool {
		$host     = self::get_site_host();
		$segments = self::get_path_segments( $this->get_home_path() );

		if ( '' === $host || null === $segments ) {
			return false;
		}

		$directory    = self::get_cache_dir( $host, $segments );
		$filesystem   = direct_filesystem();
		$this->purged = array();

		$deleted = ! $filesystem->is_dir( $directory ) || $filesystem->delete( $directory, true );

		do_action( 'o2_rocket_after_purge_all', $deleted );

		return $deleted;
	}

	public function on_settings_added( $option, $value ): void {
		$this->apply_settings( $value );
	}

	public function on_settings_updated( $old_value, $value ): void {
		$this->apply_settings( $value );
	}

	public function on_permalinks_changed(): void {
		$this->sync_dropin( $this->is_enabled() );
		$this->purge_all();
	}

	public function on_deactivate(): void {
		$this->drop_in->disable();
		$this->purge_all();
	}

	// Autocorreção: ativação, atualização do plugin ou drop-in removido manualmente.
	public function maybe_repair_dropin(): void {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) || get_transient( Drop_In_Manager::RETRY_TRANSIENT ) ) {
			return;
		}

		$enabled = $this->is_enabled();

		if ( $this->drop_in->needs_sync( $enabled ) ) {
			$this->sync_dropin( $enabled );
		}
	}

	public function get_dropin_config(): array {
		$charset = (string) get_option( 'blog_charset' );

		return array(
			'version'        => O2_ROCKET_VERSION,
			'cache_path'     => O2_ROCKET_CACHE_PATH,
			'home_path'      => $this->get_home_path(),
			'trailing_slash' => '/' === substr( (string) get_option( 'permalink_structure' ), -1 ),
			'exclude_regex'  => $this->get_exclusion_regex(),
			'bypass_cookies' => $this->get_bypass_cookies(),
			'lifespan'       => $this->get_lifespan(),
			'charset'        => '' !== $charset ? $charset : 'UTF-8',
		);
	}

	// Termos nativos casam como segmento inteiro: "cart" exclui /cart/, mas não /descartaveis/.
	public function get_exclusion_regex(): string {
		$home_prefix = preg_quote( rtrim( $this->get_home_path(), '/' ), '#' );
		$parts       = array();

		$terms = (array) apply_filters(
			'o2_rocket_cache_excluded_terms',
			array( 'cart', 'checkout', 'my-account', 'carrinho', 'finalizar-compra', 'minha-conta' )
		);

		foreach ( $terms as $term ) {
			$term = trim( (string) $term, '/' );

			if ( '' !== $term ) {
				$parts[] = '(?:^|/)' . preg_quote( $term, '#' ) . '(?:/|$)';
			}
		}

		foreach ( self::sanitize_uri_patterns( $this->get_settings()[ self::SETTING_EXCLUDED_URIS ] ) as $pattern ) {
			$quoted  = str_replace( '\*', '.*', preg_quote( $pattern, '#' ) );
			$parts[] = '/' === $pattern[0] ? '^' . $home_prefix . $quoted : $quoted;
		}

		return empty( $parts ) ? '' : '#' . implode( '|', $parts ) . '#i';
	}

	public function is_excluded_path( string $path ): bool {
		$regex = $this->get_exclusion_regex();

		return '' !== $regex && 1 === preg_match( $regex, $path );
	}

	public function get_bypass_cookies(): array {
		$cookies = (array) apply_filters(
			'o2_rocket_cache_bypass_cookies',
			array(
				'wordpress_logged_in_',
				'wp-postpass_',
				'comment_author_',
				'woocommerce_items_in_cart',
				'woocommerce_cart_hash',
				'wp_woocommerce_session_',
			)
		);

		return array_values( array_filter( array_map( 'strval', $cookies ), 'strlen' ) );
	}

	// Linhas com "/" viram prefixo a partir da home; sem "/", termo em qualquer parte. "*" é curinga.
	public static function sanitize_uri_patterns( $value ): array {
		$lines    = is_array( $value ) ? $value : preg_split( '/\r\n|\r|\n/', is_scalar( $value ) ? (string) $value : '' );
		$patterns = array();

		foreach ( (array) $lines as $line ) {
			if ( ! is_scalar( $line ) ) {
				continue;
			}

			$line = trim( wp_strip_all_tags( (string) $line ) );

			if ( false !== strpos( $line, '://' ) ) {
				$path = wp_parse_url( $line, PHP_URL_PATH );
				$line = is_string( $path ) ? $path : '';
			}

			// Codifica acentos/espaços como o navegador envia na REQUEST_URI.
			$line = (string) preg_replace_callback(
				'/[^\x21-\x7E]/',
				static function ( $matches ) {
					return rawurlencode( $matches[0] );
				},
				$line
			);
			$line = self::uppercase_percent_encoding( (string) preg_replace( '/[^A-Za-z0-9\/%._~*\-]/', '', $line ) );

			// "/" ou "*" sozinhos desativariam o cache do site inteiro.
			if ( '' === trim( $line, '/*' ) ) {
				continue;
			}

			$patterns[] = $line;
		}

		return array_slice( array_values( array_unique( $patterns ) ), 0, self::MAX_PATTERNS );
	}

	// Normalização de host/caminho: mantenha idêntica ao drop-ins/advanced-cache.php.
	public static function normalize_host( string $host ): string {
		$host = strtolower( trim( $host ) );

		if ( 1 !== preg_match( '/^[a-z0-9.\-]+(?::[0-9]{1,5})?$/', $host ) ) {
			return '';
		}

		return str_replace( ':', '_', $host );
	}

	// Null = caminho inválido (traversal, caracteres fora da lista): não cacheia.
	public static function get_path_segments( string $path ): ?array {
		$segments = array();

		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment ) {
				continue;
			}

			if ( 1 !== preg_match( '/^[A-Za-z0-9%_~\-][A-Za-z0-9%._~\-]*$/', $segment ) ) {
				return null;
			}

			$segments[] = self::uppercase_percent_encoding( $segment );
		}

		return $segments;
	}

	public static function get_cache_dir( string $host, array $segments ): string {
		return O2_ROCKET_CACHE_PATH . 'pages/' . $host . '/' . ( empty( $segments ) ? '' : implode( '/', $segments ) . '/' );
	}

	// Raiz do cache deste site: pages/{host}/{caminho-da-home}/.
	public function get_site_cache_dir(): string {
		$host     = self::get_site_host();
		$segments = self::get_path_segments( $this->get_home_path() );

		return '' === $host || null === $segments ? '' : self::get_cache_dir( $host, $segments );
	}

	public static function get_site_host(): string {
		$parts = wp_parse_url( home_url() );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		return self::normalize_host( $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) );
	}

	// Permalinks com acento vêm em minúsculas (%c3%a7) e navegadores enviam maiúsculas (%C3%A7).
	private static function uppercase_percent_encoding( string $value ): string {
		return (string) preg_replace_callback(
			'/%[0-9a-f]{2}/i',
			static function ( $matches ) {
				return strtoupper( $matches[0] );
			},
			$value
		);
	}

	private function parse_settings( $value ): array {
		return wp_parse_args(
			is_array( $value ) ? $value : array(),
			array(
				self::SETTING_ENABLED       => false,
				self::SETTING_EXCLUDED_URIS => array(),
			)
		);
	}

	private function apply_settings( $value ): void {
		$this->settings = $this->parse_settings( $value );

		$synced = $this->sync_dropin( $this->is_enabled() );

		$this->purge_all();

		if ( ! $synced && function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				'o2_rocket',
				'o2_rocket_page_cache',
				sprintf(
					/* translators: %s: motivo da falha. */
					__( 'Configurações salvas, mas o Page Cache não pôde ser configurado: %s.', 'o2-rocket' ),
					$this->drop_in->get_last_error()
				),
				'error'
			);
		}
	}

	private function sync_dropin( bool $enabled ): bool {
		$synced = $enabled ? $this->drop_in->enable( $this->get_dropin_config() ) : $this->drop_in->disable();

		if ( $synced ) {
			delete_transient( Drop_In_Manager::RETRY_TRANSIENT );
		} else {
			set_transient( Drop_In_Manager::RETRY_TRANSIENT, 1, 15 * MINUTE_IN_SECONDS );
		}

		return $synced;
	}

	private function save( string $html ): void {
		if ( '' === $this->cache_file || ! $this->is_cacheable_response( $html ) ) {
			return;
		}

		if ( ! wp_mkdir_p( dirname( $this->cache_file ) ) ) {
			return;
		}

		$html .= sprintf( "\n<!-- O² Rocket Cache gerado em %s -->", wp_date( 'd/m/Y H:i:s' ) );

		atomic_write( $this->cache_file, $html );
	}

	private function is_cacheable_response( string $html ): bool {
		if ( 200 !== http_response_code() ) {
			return false;
		}

		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return false;
		}

		if ( strlen( trim( $html ) ) < self::MIN_HTML_LENGTH || false === stripos( $html, '</html>' ) ) {
			return false;
		}

		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'content-type:' ) && false === stripos( $header, 'text/html' ) ) {
				return false;
			}
		}

		return (bool) apply_filters( 'o2_rocket_can_cache_response', true, $html );
	}

	// Só aceita o host canônico da home: bloqueia envenenamento via cabeçalho Host.
	private function get_request_cache_file(): string {
		$host = self::normalize_host( isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '' );

		if ( '' === $host || self::get_site_host() !== $host ) {
			return '';
		}

		$path     = $this->get_request_path();
		$segments = '' === $path ? null : self::get_path_segments( $path );

		if ( null === $segments ) {
			return '';
		}

		return self::get_cache_dir( $host, $segments ) . ( is_ssl() ? 'index-https.html' : 'index.html' );
	}

	// Vazio = sem cache (URI inválida ou com query string).
	private function get_request_path(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validada por regex estrita em get_path_segments().
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

		if ( '' === $uri || '/' !== $uri[0] || false !== strpos( $uri, '?' ) ) {
			return '';
		}

		return $uri;
	}

	private function has_bypass_cookie(): bool {
		$prefixes = $this->get_bypass_cookies();

		foreach ( array_keys( $_COOKIE ) as $cookie ) {
			foreach ( $prefixes as $prefix ) {
				if ( 0 === strpos( (string) $cookie, $prefix ) ) {
					return true;
				}
			}
		}

		return false;
	}

	private function is_woocommerce_private_page(): bool {
		return ( function_exists( 'is_cart' ) && is_cart() )
			|| ( function_exists( 'is_checkout' ) && is_checkout() )
			|| ( function_exists( 'is_account_page' ) && is_account_page() );
	}

	private function is_purgeable_post( \WP_Post $post ): bool {
		return 'auto-draft' !== $post->post_status && is_post_type_viewable( $post->post_type );
	}

	private function get_post_related_urls( \WP_Post $post ): array {
		$urls = array( home_url( '/' ) );

		$permalink = get_permalink( $post );

		if ( $permalink ) {
			$urls[] = $permalink;
		}

		$posts_page = (int) get_option( 'page_for_posts' );

		if ( $posts_page > 0 ) {
			$urls[] = get_permalink( $posts_page );
		}

		$archive = get_post_type_archive_link( $post->post_type );

		if ( $archive ) {
			$urls[] = $archive;
		}

		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			if ( ! $taxonomy->public ) {
				continue;
			}

			$terms = get_the_terms( $post, $taxonomy->name );

			if ( ! is_array( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				$link = get_term_link( $term );

				if ( ! is_wp_error( $link ) ) {
					$urls[] = $link;
				}
			}
		}

		if ( post_type_supports( $post->post_type, 'author' ) ) {
			$urls[] = get_author_posts_url( (int) $post->post_author );
		}

		return array_values( array_unique( array_filter( (array) apply_filters( 'o2_rocket_post_purge_urls', $urls, $post ), 'is_string' ) ) );
	}

	private function purge_comment_post( $comment ): void {
		$comment = get_comment( $comment );

		if ( ! $comment instanceof \WP_Comment ) {
			return;
		}

		$post = get_post( (int) $comment->comment_post_ID );

		if ( $post instanceof \WP_Post && 'publish' === $post->post_status ) {
			$permalink = get_permalink( $post );

			if ( $permalink ) {
				$this->purge_url( $permalink );
			}
		}
	}

	private function get_home_path(): string {
		$path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		return is_string( $path ) && '' !== $path ? $path : '/';
	}

	private function get_pagination_base(): string {
		global $wp_rewrite;

		return $wp_rewrite instanceof \WP_Rewrite && '' !== $wp_rewrite->pagination_base ? $wp_rewrite->pagination_base : 'page';
	}

	private function get_lifespan(): int {
		return max( 0, (int) apply_filters( 'o2_rocket_cache_lifespan', self::DEFAULT_LIFESPAN ) );
	}
}
