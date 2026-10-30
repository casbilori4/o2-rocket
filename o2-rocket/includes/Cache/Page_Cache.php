<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Cache;

use O2Rocket\Optimization\Html_Pipeline;

use function O2Rocket\atomic_write;
use function O2Rocket\direct_filesystem;

defined( 'ABSPATH' ) || exit;

final class Page_Cache {

	const HOME_OPTION = 'o2_rocket_last_home';

	const SETTING_ENABLED              = 'page_cache_enabled';
	const SETTING_EXCLUDED_URIS        = 'page_cache_excluded_uris';
	const SETTING_LIFESPAN             = 'page_cache_lifespan';
	const SETTING_LIFESPAN_UNIT        = 'page_cache_lifespan_unit';
	const SETTING_GZIP                 = 'page_cache_gzip';
	const SETTING_MOBILE               = 'page_cache_mobile';
	const SETTING_REJECTED_COOKIES     = 'page_cache_rejected_cookies';
	const SETTING_REJECTED_USER_AGENTS = 'page_cache_rejected_user_agents';
	const SETTING_QUERY_STRINGS        = 'page_cache_query_strings';
	const SETTING_IGNORED_PARAMS       = 'page_cache_ignored_params';
	const SETTING_ALWAYS_PURGE         = 'page_cache_always_purge';

	const DEFAULT_LIFESPAN      = 10;
	const DEFAULT_LIFESPAN_UNIT = 'hours';
	const LIFESPAN_UNITS        = array(
		'minutes' => 60,
		'hours'   => 3600,
		'days'    => 86400,
	);

	const PURGE_EXPIRED_HOOK     = 'o2_rocket_purge_expired';
	const PURGE_EXPIRED_SCHEDULE = 'o2_rocket_purge_expired_interval';
	const PURGE_EXPIRED_SECONDS  = 20;
	const PURGE_EXPIRED_FILES    = 50000;

	const MIN_HTML_LENGTH = 255;
	const MAX_PATTERNS    = 500;
	const GZIP_LEVEL      = 6;

	// Parâmetros de campanha/rastreamento que não mudam o conteúdo (lista dynamic-lists.json publicada pela WP Media, GPL-2.0-or-later).
	const IGNORED_PARAMETERS = array(
		'utm_source', 'utm_medium', 'utm_campaign', 'utm_expid', 'utm_term', 'utm_content', 'utm_id', 'utm_source_platform',
		'utm_creative_format', 'utm_marketing_tactic', 'mtm_source', 'mtm_medium', 'mtm_campaign', 'mtm_keyword', 'mtm_cid',
		'mtm_content', 'pk_source', 'pk_medium', 'pk_campaign', 'pk_keyword', 'pk_cid', 'pk_content', 'fb_action_ids',
		'fb_action_types', 'fb_source', 'fbclid', 'campaignid', 'adgroupid', 'adid', 'gclid', 'age-verified', 'ao_noptimize',
		'usqp', 'cn-reloaded', '_ga', 'sscid', 'gclsrc', '_gl', 'mc_cid', 'mc_eid', '_bta_tid', '_bta_c', 'trk_contact',
		'trk_msg', 'trk_module', 'trk_sid', 'gdfms', 'gdftrk', 'gdffi', '_ke', '_kx', 'redirect_log_mongo_id',
		'redirect_mongo_id', 'sb_referer_host', 'mkwid', 'pcrid', 'ef_id', 's_kwcid', 'msclkid', 'dm_i', 'epik', 'pp',
		'gbraid', 'wbraid', 'ssp_iabi', 'ssp_iaba', 'gad', 'vgo_ee', 'gad_source', 'gad_campaignid', 'srsltid', 'gadid',
		'fbadid',
	);

	// Mudanças que aparecem em todas as páginas: limpam o cache do site inteiro.
	const PURGE_ALL_OPTIONS = array(
		'sidebars_widgets',
		'category_base',
		'tag_base',
		'blog_public',
		'blogname',
		'blogdescription',
		'show_on_front',
		'page_on_front',
		'page_for_posts',
		'posts_per_page',
		'date_format',
		'time_format',
		'WPLANG',
		'site_icon',
	);

	private Drop_In_Manager $drop_in;

	private ?array $settings = null;

	private ?array $config = null;

	private ?string $exclusion_regex = null;

	private string $cache_file = '';

	private string $captured = '';

	private array $pending_urls = array();

	private array $pending_terms = array();

	private array $purged = array();

	public function __construct( Drop_In_Manager $drop_in ) {
		$this->drop_in = $drop_in;
	}

	public function register_hooks(): void {
		add_action( 'add_option_' . O2_ROCKET_OPTION, array( $this, 'on_settings_added' ), 10, 2 );
		add_action( 'update_option_' . O2_ROCKET_OPTION, array( $this, 'on_settings_updated' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'maybe_handle_domain_change' ), 5 );
		add_action( 'admin_init', array( $this, 'maybe_repair_dropin' ) );
		add_filter( 'pre_update_option_' . O2_ROCKET_OPTION, array( $this, 'maybe_repair_on_save' ), 10, 2 );
		add_action( 'o2_rocket_deactivate', array( $this, 'on_deactivate' ) );
		add_action( 'o2_rocket_purge_cache', array( $this, 'purge_all' ) );
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedule' ) );
		add_action( 'init', array( $this, 'schedule_purge_expired' ) );
		add_action( self::PURGE_EXPIRED_HOOK, array( $this, 'purge_expired' ) );

		if ( $this->is_enabled() ) {
			add_action( 'template_redirect', array( $this, 'start_buffer' ), 9999 );
			$this->register_purge_events();
		} else {
			// Sem o cache do plugin, os eventos ainda limpam caches externos (Cloudflare, Varnish, hospedagem).
			add_action( 'init', array( $this, 'maybe_register_purge_events' ), 0 );
		}
	}

	public function maybe_register_purge_events(): void {
		if ( has_action( 'o2_rocket_before_purge_url' ) ) {
			$this->register_purge_events();
		}
	}

	private function register_purge_events(): void {
		add_action( 'pre_post_update', array( $this, 'remember_post_urls' ) );
		add_action( 'save_post', array( $this, 'on_save_post' ), 10, 2 );
		add_action( 'wp_trash_post', array( $this, 'on_trash_post' ) );
		add_action( 'before_delete_post', array( $this, 'on_trash_post' ) );
		add_action( 'comment_post', array( $this, 'on_comment_post' ), 10, 2 );
		add_action( 'transition_comment_status', array( $this, 'on_comment_status_changed' ), 10, 3 );

		add_action( 'edit_terms', array( $this, 'remember_term_urls' ), 10, 2 );
		add_action( 'edited_term', array( $this, 'on_term_saved' ), 10, 3 );
		add_action( 'pre_delete_term', array( $this, 'on_term_deleted' ), 10, 2 );
		add_action( 'profile_update', array( $this, 'on_user_changed' ) );
		add_action( 'delete_user', array( $this, 'on_user_changed' ) );

		add_action( 'switch_theme', array( $this, 'purge_all' ) );
		add_action( 'customize_save_after', array( $this, 'purge_all' ) );
		add_action( 'wp_update_nav_menu', array( $this, 'purge_all' ) );
		add_action( 'activated_plugin', array( $this, 'purge_all' ) );
		add_action( 'deactivated_plugin', array( $this, 'purge_all' ) );
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrade' ), 10, 2 );
		add_action( 'update_option_theme_mods_' . get_option( 'stylesheet' ), array( $this, 'purge_all' ) );
		add_action( 'update_option_permalink_structure', array( $this, 'on_permalinks_changed' ) );
		add_action( 'update_option_home', array( $this, 'on_permalinks_changed' ) );
		add_filter( 'widget_update_callback', array( $this, 'on_widget_updated' ) );

		foreach ( self::PURGE_ALL_OPTIONS as $option ) {
			add_action( 'update_option_' . $option, array( $this, 'purge_all' ) );
		}
	}

	public function get_settings(): array {
		if ( null === $this->settings ) {
			$this->settings = $this->parse_settings( get_option( O2_ROCKET_OPTION, array() ) );
		}

		return $this->settings;
	}

	// Hospedagens com cache de página próprio no servidor desligam o do plugin (ThirdParty\Hosting).
	public function is_enabled(): bool {
		return rest_sanitize_boolean( $this->get_settings()[ self::SETTING_ENABLED ] ) && (bool) apply_filters( 'o2_rocket_page_cache_allowed', true );
	}

	public function is_mobile_cache_enabled(): bool {
		return rest_sanitize_boolean( $this->get_settings()[ self::SETTING_MOBILE ] );
	}

	public function is_gzip_enabled(): bool {
		return rest_sanitize_boolean( $this->get_settings()[ self::SETTING_GZIP ] ) && function_exists( 'gzencode' );
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
			header( Cache_Engine::HEADER . ': MISS' );
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

		// "Nunca fazer cache desta página" no editor.
		if ( in_array( 'cache', Html_Pipeline::get_post_exclusions(), true ) ) {
			return false;
		}

		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return false;
		}

		$target = $this->resolve_request();

		if ( null === $target || ! $target['generate'] ) {
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

		if ( ! empty( $urls ) ) {
			$urls = array_merge( $urls, $this->get_always_purge_urls() );
		}

		$this->purge_urls( $urls );
	}

	public function on_trash_post( $post_id ): void {
		$post = get_post( (int) $post_id );

		if ( $post instanceof \WP_Post && $this->is_purgeable_post( $post ) && 'publish' === $post->post_status ) {
			$this->purge_urls( array_merge( $this->get_post_related_urls( $post ), $this->get_always_purge_urls() ) );
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

	// Antes de editar um termo: guarda a URL antiga (troca de slug ou de pai).
	public function remember_term_urls( $term_id, $taxonomy ): void {
		$this->pending_terms[ (int) $term_id ] = $this->get_term_related_urls( (int) $term_id, (string) $taxonomy );
	}

	public function on_term_saved( $term_id, $tt_id, $taxonomy ): void {
		$term_id = (int) $term_id;
		$urls    = isset( $this->pending_terms[ $term_id ] ) ? $this->pending_terms[ $term_id ] : array();
		unset( $this->pending_terms[ $term_id ] );

		$this->purge_urls( array_merge( $urls, $this->get_term_related_urls( $term_id, (string) $taxonomy ) ) );
	}

	public function on_term_deleted( $term_id, $taxonomy ): void {
		$this->purge_urls( $this->get_term_related_urls( (int) $term_id, (string) $taxonomy ) );
	}

	public function on_user_changed( $user_id ): void {
		$this->purge_url( get_author_posts_url( (int) $user_id ) );
	}

	public function on_widget_updated( $instance ) {
		$this->purge_all();

		return $instance;
	}

	// Atualização de núcleo, tema ou plugin muda o HTML (versões de CSS/JS, marcação).
	public function on_upgrade( $upgrader, $options ): void {
		if ( is_array( $options ) && isset( $options['action'] ) && 'update' === $options['action'] ) {
			$this->purge_all();
		}
	}

	// Post, arquivos, termos e páginas relacionadas (botão "Limpar cache" da lista de posts).
	public function purge_post( int $post_id ): void {
		$post = get_post( $post_id );

		if ( $post instanceof \WP_Post && $this->is_purgeable_post( $post ) && 'publish' === $post->post_status ) {
			$this->purge_urls( array_merge( $this->get_post_related_urls( $post ), $this->get_always_purge_urls() ) );
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

		$host     = Cache_Engine::normalize_host( $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) );
		$segments = Cache_Engine::get_path_segments( isset( $parts['path'] ) ? $parts['path'] : '/' );

		if ( '' === $host || null === $segments ) {
			return false;
		}

		$directory = self::get_cache_dir( $host, $segments );

		if ( isset( $this->purged[ $directory ] ) ) {
			return true;
		}

		$this->purged[ $directory ] = true;

		// CDN, Cloudflare, Varnish: avisados de toda URL limpa, tenha ou não cópia local.
		do_action( 'o2_rocket_before_purge_url', $url );

		if ( ! is_dir( $directory ) ) {
			return true;
		}

		$site_dir = $this->get_site_cache_dir();
		$is_home  = $directory === $site_dir;
		$collect  = '' !== $site_dir && 0 === strpos( $directory, $site_dir ) && has_action( 'o2_rocket_after_purge_urls' );
		$found    = array();
		$deleted  = $is_home ? $this->delete_home_files( $directory, $found, $collect ) : $this->delete_tree( $directory, '', $found, $collect );

		if ( $collect && ! empty( $found ) ) {
			$base = trim( substr( $directory, strlen( $site_dir ) ), '/' );
			$urls = array();

			foreach ( array_keys( $found ) as $relative ) {
				$urls[] = $this->relative_dir_to_url( trim( $base . '/' . $relative, '/' ) );
			}

			do_action( 'o2_rocket_after_purge_urls', array_values( array_unique( $urls ) ) );
		}

		return $deleted;
	}

	// O 2º argumento da ação são as URLs que tinham página em cache (só quando alguém usa a lista: pré-carregamento).
	public function purge_all(): bool {
		$directory = $this->get_site_cache_dir();

		if ( '' === $directory ) {
			return false;
		}

		$this->purged = array();
		$this->config = null;
		$found        = array();
		$deleted      = true;
		$collect      = (bool) has_action( 'o2_rocket_after_purge_urls' );

		if ( is_dir( $directory ) ) {
			$protected = $this->get_protected_subdirectories();
			$deleted   = empty( $protected ) ? $this->delete_tree( $directory, '', $found, $collect ) : $this->delete_tree_except( $directory, $protected, $found, $collect );
		}

		$urls = array();

		foreach ( array_keys( $found ) as $relative ) {
			$urls[] = $this->relative_dir_to_url( (string) $relative );
		}

		do_action( 'o2_rocket_after_purge_all', $deleted, array_values( array_unique( $urls ) ) );

		return $deleted;
	}

	// Apaga as páginas que passaram do tempo de vida, em lotes curtos (retoma na próxima execução).
	public function purge_expired(): array {
		$lifespan  = $this->get_lifespan();
		$directory = $this->get_site_cache_dir();

		if ( $lifespan < 1 || '' === $directory || ! is_dir( $directory ) ) {
			return array();
		}

		$protected = $this->get_protected_subdirectories();
		$limit     = time() - $lifespan;
		$started   = microtime( true );
		$scanned   = 0;
		$found     = array();

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);

			foreach ( $iterator as $item ) {
				if ( ++$scanned > self::PURGE_EXPIRED_FILES || microtime( true ) - $started > self::PURGE_EXPIRED_SECONDS ) {
					break;
				}

				$path     = wp_normalize_path( $item->getPathname() );
				$relative = trim( substr( wp_normalize_path( dirname( $path ) ), strlen( untrailingslashit( $directory ) ) ), '/' );

				if ( $this->is_protected_relative( $relative, $protected ) ) {
					continue;
				}

				if ( $item->isDir() ) {
					// Diretório vazio sobra depois de apagar as páginas.
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
					@rmdir( $path );
					continue;
				}

				$name = $item->getFilename();

				if ( ! Cache_Engine::is_cache_filename( $name ) ) {
					continue;
				}

				$is_gzip = '.gz' === substr( $name, -3 ) || '.br' === substr( $name, -3 );
				$html    = $is_gzip ? substr( $path, 0, -3 ) : $path;

				// Cópia comprimida sem o .html correspondente nunca é servida: apaga.
				if ( $is_gzip ? ( ! is_file( $html ) || (int) filemtime( $html ) < $limit ) : (int) $item->getMTime() < $limit ) {
					wp_delete_file( $path );

					if ( ! $is_gzip ) {
						$found[ $relative ] = true;
					}
				}
			}
		} catch ( \UnexpectedValueException $exception ) {
			unset( $exception );
		}

		$urls = array();

		foreach ( array_keys( $found ) as $relative ) {
			$urls[] = $this->relative_dir_to_url( $relative );
		}

		$urls = array_values( array_unique( $urls ) );

		if ( ! empty( $urls ) ) {
			do_action( 'o2_rocket_after_purge_expired', $urls );
		}

		return $urls;
	}

	public function add_cron_schedule( $schedules ) {
		$schedules = is_array( $schedules ) ? $schedules : array();

		$schedules[ self::PURGE_EXPIRED_SCHEDULE ] = array(
			'interval' => $this->get_purge_interval(),
			'display'  => __( 'O² Rocket: limpeza de páginas expiradas', 'o2-rocket' ),
		);

		return $schedules;
	}

	public function schedule_purge_expired(): void {
		$scheduled = wp_next_scheduled( self::PURGE_EXPIRED_HOOK );

		if ( ! $this->is_enabled() || $this->get_lifespan() < 1 ) {
			if ( $scheduled ) {
				wp_clear_scheduled_hook( self::PURGE_EXPIRED_HOOK );
			}

			return;
		}

		if ( ! $scheduled ) {
			wp_schedule_event( time() + $this->get_purge_interval(), self::PURGE_EXPIRED_SCHEDULE, self::PURGE_EXPIRED_HOOK );
		}
	}

	public function on_settings_added( $option, $value ): void {
		$this->apply_settings( $value );
	}

	public function on_settings_updated( $old_value, $value ): void {
		$this->apply_settings( $value );
	}

	public function on_permalinks_changed(): void {
		$this->config = null;
		$this->sync_dropin( $this->is_enabled() );
		$this->purge_all();
	}

	public function on_deactivate( $network_wide = false ): void {
		$network_wide = (bool) $network_wide;

		$this->drop_in->disable( $network_wide );

		if ( $network_wide ) {
			direct_filesystem()->delete( O2_ROCKET_CACHE_PATH . 'pages/', true );
			return;
		}

		$this->purge_all();
	}

	// Endereço do site mudou (migração, search-replace no banco): o cache e a configuração do drop-in do endereço
	// antigo saem, e o do novo começa limpo. Em site único só; no multisite cada site cuida do próprio diretório.
	public function maybe_handle_domain_change(): void {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$current = home_url( '/' );
		$last    = get_option( self::HOME_OPTION, '' );

		if ( $last === $current ) {
			return;
		}

		update_option( self::HOME_OPTION, $current, true );

		$parts = is_string( $last ) && '' !== $last ? wp_parse_url( $last ) : false;

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return;
		}

		$host     = Cache_Engine::normalize_host( $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) );
		$path     = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		$segments = Cache_Engine::get_path_segments( $path );

		if ( '' === $host || null === $segments ) {
			return;
		}

		$old_dir    = self::get_cache_dir( $host, $segments );
		$old_config = O2_ROCKET_CONFIG_PATH . Cache_Engine::config_basename( $host, $path );

		if ( ! is_multisite() && $old_dir !== $this->get_site_cache_dir() && is_dir( $old_dir ) ) {
			direct_filesystem()->delete( $old_dir, true );
		}

		if ( $old_config !== $this->drop_in->get_config_file() && is_file( $old_config ) ) {
			direct_filesystem()->delete( $old_config );
		}

		$this->config = null;
		$this->sync_dropin( $this->is_enabled() );
		$this->purge_all();

		/* translators: 1: endereço antigo, 2: endereço novo. */
		Cache_Controls::add_notice( sprintf( __( 'O endereço do site mudou de %1$s para %2$s: o cache antigo foi apagado.', 'o2-rocket' ), $last, $current ) );
	}

	// Estado do cache de página conferido no disco a cada chamada (não pelo último erro guardado):
	// code '' = funcionando ou desligado; conflict, missing, outdated, wp_cache ou config = parado.
	public function get_health(): array {
		$health = array(
			'code'    => '',
			'message' => '',
		);

		if ( ! $this->is_enabled() ) {
			return $health;
		}

		$state    = $this->drop_in->get_dropin_state();
		$problems = array(
			'conflict' => __( 'o arquivo wp-content/advanced-cache.php pertence a outro plugin de cache', 'o2-rocket' ),
			'missing'  => __( 'o arquivo wp-content/advanced-cache.php não está instalado', 'o2-rocket' ),
			'outdated' => __( 'o arquivo wp-content/advanced-cache.php está desatualizado', 'o2-rocket' ),
			'wp_cache' => __( 'a constante WP_CACHE não está ligada no wp-config.php', 'o2-rocket' ),
			'config'   => __( 'falta a configuração do cache em wp-content/o2-rocket-config/', 'o2-rocket' ),
		);

		if ( Drop_In_Manager::STATE_CONFLICT === $state ) {
			$code = 'conflict';
		} elseif ( Drop_In_Manager::STATE_MISSING === $state ) {
			$code = 'missing';
		} elseif ( Drop_In_Manager::STATE_OUTDATED === $state ) {
			$code = 'outdated';
		} elseif ( ! $this->drop_in->is_wp_cache_enabled() ) {
			$code = 'wp_cache';
		} elseif ( null === $this->drop_in->read_config() ) {
			$code = 'config';
		} else {
			return $health;
		}

		$error = $this->drop_in->get_last_error();

		// O último erro só acrescenta algo quando diz outra coisa (ex.: sem permissão de escrita).
		$extra = '' !== $error && false === strpos( $error, $problems[ $code ] ) ? ' (' . $error . ')' : '';

		return array(
			'code'    => $code,
			'message' => $problems[ $code ] . $extra,
		);
	}

	public function get_health_problem(): string {
		return $this->get_health()['message'];
	}

	// "Corrigir agora" e salvamento sem mudanças: tenta de novo sem esperar o intervalo entre tentativas.
	public function repair(): bool {
		delete_transient( Drop_In_Manager::RETRY_TRANSIENT );
		$this->config = null;

		return $this->sync_dropin( $this->is_enabled() );
	}

	// Salvar as configurações sem mudar nada não dispara o update_option: se o cache estiver parado, tenta aqui.
	public function maybe_repair_on_save( $value, $old_value ) {
		if ( $value === $old_value && '' !== $this->get_health_problem() ) {
			$this->repair();
		}

		return $value;
	}

	// Autocorreção: ativação, atualização do plugin ou drop-in removido manualmente. No painel do plugin, sempre confere.
	public function maybe_repair_dropin(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- só identifica a tela.
		$own_screen = isset( $_GET['page'] ) && 'o2-rocket' === sanitize_key( wp_unslash( $_GET['page'] ) );

		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) || ( ! $own_screen && get_transient( Drop_In_Manager::RETRY_TRANSIENT ) ) ) {
			return;
		}

		$enabled = $this->is_enabled();

		if ( $this->drop_in->needs_sync( $enabled ) ) {
			$this->sync_dropin( $enabled );
		}
	}

	public function get_dropin_config(): array {
		if ( null !== $this->config ) {
			return $this->config;
		}

		$charset  = (string) get_option( 'blog_charset' );
		$settings = $this->get_settings();

		$this->config = array(
			'version'            => O2_ROCKET_VERSION,
			'cache_path'         => O2_ROCKET_CACHE_PATH,
			'home_path'          => Cache_Engine::normalize_home_path( self::get_site_home_path() ),
			'trailing_slash'     => '/' === substr( (string) get_option( 'permalink_structure' ), -1 ),
			'exclude_regex'      => $this->get_exclusion_regex(),
			'bypass_cookies'     => $this->get_bypass_cookies(),
			'bypass_user_agents' => self::sanitize_user_agents( $settings[ self::SETTING_REJECTED_USER_AGENTS ] ),
			'ignored_params'     => $this->get_ignored_parameters(),
			'cache_query_params' => array_values( array_unique( (array) apply_filters( 'o2_rocket_cache_query_params', self::sanitize_query_params( $settings[ self::SETTING_QUERY_STRINGS ] ) ) ) ),
			'lifespan'           => $this->get_lifespan(),
			'gzip'               => $this->is_gzip_enabled(),
			'mobile'             => $this->is_mobile_cache_enabled(),
			'charset'            => '' !== $charset ? $charset : 'UTF-8',
		);

		return $this->config;
	}

	// Termos nativos casam como segmento inteiro: "cart" exclui /cart/, mas não /descartaveis/.
	public function get_exclusion_regex(): string {
		if ( null !== $this->exclusion_regex ) {
			return $this->exclusion_regex;
		}

		$parts = array();

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

		$custom = self::build_uri_regex( self::sanitize_uri_patterns( $this->get_settings()[ self::SETTING_EXCLUDED_URIS ] ), false );

		if ( '' !== $custom ) {
			$parts[] = $custom;
		}

		$this->exclusion_regex = empty( $parts ) ? '' : '#' . implode( '|', $parts ) . '#i';

		return $this->exclusion_regex;
	}

	public function is_excluded_path( string $path ): bool {
		$regex = $this->get_exclusion_regex();

		return '' !== $regex && 1 === preg_match( $regex, $path );
	}

	// Padrões já sanitizados => regex: "/" no início ancora na home; "*" é curinga. Sem delimitadores se $wrap = false.
	public static function build_uri_regex( array $patterns, bool $wrap = true ): string {
		$home_prefix = preg_quote( rtrim( self::get_site_home_path(), '/' ), '#' );
		$parts       = array();

		foreach ( $patterns as $pattern ) {
			$pattern = (string) $pattern;

			if ( '' === $pattern ) {
				continue;
			}

			$quoted  = str_replace( '\*', '.*', preg_quote( $pattern, '#' ) );
			$parts[] = '/' === $pattern[0] ? '^' . $home_prefix . $quoted : $quoted;
		}

		if ( empty( $parts ) ) {
			return '';
		}

		return $wrap ? '#' . implode( '|', $parts ) . '#i' : implode( '|', $parts );
	}

	// Trechos de nome de cookie: qualquer cookie que contenha um deles pula o cache.
	public function get_bypass_cookies(): array {
		$cookies = (array) apply_filters(
			'o2_rocket_cache_bypass_cookies',
			array_merge(
				array(
					'wordpress_logged_in_',
					'wp-postpass_',
					'comment_author_',
					'woocommerce_items_in_cart',
					'woocommerce_cart_hash',
					'wp_woocommerce_session_',
				),
				self::sanitize_cookie_names( $this->get_settings()[ self::SETTING_REJECTED_COOKIES ] )
			)
		);

		return array_values( array_unique( array_filter( array_map( 'strval', $cookies ), 'strlen' ) ) );
	}

	public function get_ignored_parameters(): array {
		$params = (array) apply_filters(
			'o2_rocket_cache_ignored_parameters',
			array_merge( self::IGNORED_PARAMETERS, self::sanitize_query_params( $this->get_settings()[ self::SETTING_IGNORED_PARAMS ] ) )
		);

		return array_values( array_unique( array_filter( array_map( 'strtolower', array_map( 'strval', $params ) ), 'strlen' ) ) );
	}

	// URLs da opção "Sempre limpar": caminhos relativos viram URLs da home.
	public function get_always_purge_urls(): array {
		$urls = array();

		foreach ( (array) $this->get_settings()[ self::SETTING_ALWAYS_PURGE ] as $line ) {
			$line = trim( (string) $line );

			if ( '' === $line ) {
				continue;
			}

			$urls[] = 0 === strpos( $line, 'http' ) ? $line : home_url( '/' . ltrim( $line, '/' ) );
		}

		return $urls;
	}

	// Segundos; 0 = nunca expira.
	public function get_lifespan(): int {
		$settings = $this->get_settings();
		$unit     = (string) $settings[ self::SETTING_LIFESPAN_UNIT ];
		$seconds  = max( 0, (int) $settings[ self::SETTING_LIFESPAN ] ) * ( isset( self::LIFESPAN_UNITS[ $unit ] ) ? self::LIFESPAN_UNITS[ $unit ] : HOUR_IN_SECONDS );

		return max( 0, (int) apply_filters( 'o2_rocket_cache_lifespan', $seconds ) );
	}

	// Linhas com "/" viram prefixo a partir da home; sem "/", termo em qualquer parte. "*" é curinga.
	public static function sanitize_uri_patterns( $value ): array {
		$patterns = array();

		foreach ( self::to_lines( $value ) as $line ) {
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
			$line = Cache_Engine::uppercase_percent_encoding( (string) preg_replace( '/[^A-Za-z0-9\/%._~*\-]/', '', $line ) );

			// "/" ou "*" sozinhos desativariam o cache do site inteiro.
			if ( '' === trim( $line, '/*' ) ) {
				continue;
			}

			$patterns[] = $line;
		}

		return array_slice( array_values( array_unique( $patterns ) ), 0, self::MAX_PATTERNS );
	}

	public static function sanitize_cookie_names( $value ): array {
		$names = array();

		foreach ( self::to_lines( $value ) as $line ) {
			$line = (string) preg_replace( '/[^A-Za-z0-9_\-.%]/', '', $line );

			if ( strlen( $line ) >= 2 ) {
				$names[] = $line;
			}
		}

		return array_slice( array_values( array_unique( $names ) ), 0, self::MAX_PATTERNS );
	}

	public static function sanitize_user_agents( $value ): array {
		$agents = array();

		foreach ( self::to_lines( $value ) as $line ) {
			$line = substr( (string) preg_replace( '/[^\x20-\x7E]/', '', $line ), 0, 200 );

			if ( strlen( trim( $line ) ) >= 2 ) {
				$agents[] = trim( $line );
			}
		}

		return array_slice( array_values( array_unique( $agents ) ), 0, self::MAX_PATTERNS );
	}

	public static function sanitize_query_params( $value ): array {
		$params = array();

		foreach ( self::to_lines( $value ) as $line ) {
			$line = strtolower( (string) preg_replace( '/[^A-Za-z0-9_\-.\[\]]/', '', $line ) );

			if ( '' !== $line ) {
				$params[] = $line;
			}
		}

		return array_slice( array_values( array_unique( $params ) ), 0, self::MAX_PATTERNS );
	}

	public static function sanitize_purge_urls( $value ): array {
		$urls = array();

		foreach ( self::to_lines( $value ) as $line ) {
			$line = 0 === strpos( $line, 'http' ) ? esc_url_raw( $line ) : '/' . ltrim( (string) preg_replace( '/[^A-Za-z0-9\/%._~\-]/', '', $line ), '/' );

			if ( '' !== $line && '/' !== $line ) {
				$urls[] = $line;
			}
		}

		return array_slice( array_values( array_unique( $urls ) ), 0, self::MAX_PATTERNS );
	}

	public static function sanitize_lifespan_unit( $value ): string {
		$value = is_scalar( $value ) ? (string) $value : '';

		return isset( self::LIFESPAN_UNITS[ $value ] ) ? $value : self::DEFAULT_LIFESPAN_UNIT;
	}

	public static function normalize_host( string $host ): string {
		return Cache_Engine::normalize_host( $host );
	}

	public static function get_path_segments( string $path ): ?array {
		return Cache_Engine::get_path_segments( $path );
	}

	public static function get_cache_dir( string $host, array $segments ): string {
		return Cache_Engine::get_cache_dir( O2_ROCKET_CACHE_PATH, $host, $segments );
	}

	// Raiz do cache deste site: pages/{host}/{caminho-da-home}/.
	public function get_site_cache_dir(): string {
		$host     = self::get_site_host();
		$segments = Cache_Engine::get_path_segments( self::get_site_home_path() );

		return '' === $host || null === $segments ? '' : self::get_cache_dir( $host, $segments );
	}

	public static function get_site_host(): string {
		$parts = wp_parse_url( home_url() );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		return Cache_Engine::normalize_host( $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) );
	}

	public static function get_site_home_path(): string {
		$path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		return is_string( $path ) && '' !== $path ? $path : '/';
	}

	// Arquivo que o drop-in serviria para a URL (desktop, sem query). Usado pelo pré-carregamento.
	public function get_url_cache_file( string $url, bool $mobile = false ): string {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! empty( $parts['query'] ) ) {
			return '';
		}

		$host     = Cache_Engine::normalize_host( $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) );
		$segments = Cache_Engine::get_path_segments( isset( $parts['path'] ) ? $parts['path'] : '/' );

		if ( '' === $host || null === $segments ) {
			return '';
		}

		$https = isset( $parts['scheme'] ) && 'https' === strtolower( $parts['scheme'] );

		return self::get_cache_dir( $host, $segments ) . Cache_Engine::get_filename( $mobile && $this->is_mobile_cache_enabled(), $https );
	}

	private static function to_lines( $value ): array {
		$lines = is_array( $value ) ? $value : preg_split( '/\r\n|\r|\n/', is_scalar( $value ) ? (string) $value : '' );
		$clean = array();

		foreach ( (array) $lines as $line ) {
			if ( is_scalar( $line ) ) {
				$line = trim( wp_strip_all_tags( (string) $line ) );

				if ( '' !== $line ) {
					$clean[] = $line;
				}
			}
		}

		return $clean;
	}

	private function parse_settings( $value ): array {
		return wp_parse_args(
			is_array( $value ) ? $value : array(),
			array(
				self::SETTING_ENABLED              => false,
				self::SETTING_EXCLUDED_URIS        => array(),
				self::SETTING_LIFESPAN             => self::DEFAULT_LIFESPAN,
				self::SETTING_LIFESPAN_UNIT        => self::DEFAULT_LIFESPAN_UNIT,
				self::SETTING_GZIP                 => true,
				self::SETTING_MOBILE               => false,
				self::SETTING_REJECTED_COOKIES     => array(),
				self::SETTING_REJECTED_USER_AGENTS => array(),
				self::SETTING_QUERY_STRINGS        => array(),
				self::SETTING_IGNORED_PARAMS       => array(),
				self::SETTING_ALWAYS_PURGE         => array(),
			)
		);
	}

	private function apply_settings( $value ): void {
		$this->settings        = $this->parse_settings( $value );
		$this->config          = null;
		$this->exclusion_regex = null;

		$synced = $this->sync_dropin( $this->is_enabled() );

		$this->purge_all();

		// O intervalo depende do tempo de vida: reagenda no próximo init.
		wp_clear_scheduled_hook( self::PURGE_EXPIRED_HOOK );

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

		// As cópias comprimidas vão primeiro: quando o .html aparece, elas já são da mesma versão.
		if ( $this->is_gzip_enabled() ) {
			$level = max( 1, min( 9, (int) apply_filters( 'o2_rocket_gzip_level', self::GZIP_LEVEL ) ) );
			$gzip  = gzencode( $html, $level );

			if ( is_string( $gzip ) ) {
				atomic_write( $this->cache_file . '.gz', $gzip );
			}

			// Extensão brotli do PHP (rara): cópia .br, ~15% menor que o GZIP.
			if ( function_exists( 'brotli_compress' ) ) {
				$brotli = brotli_compress( $html, 11, defined( 'BROTLI_TEXT' ) ? BROTLI_TEXT : 1 );

				if ( is_string( $brotli ) ) {
					atomic_write( $this->cache_file . '.br', $brotli );
				}
			}
		}

		if ( atomic_write( $this->cache_file, $html ) ) {
			do_action( 'o2_rocket_cache_saved', $this->get_current_url(), $this->cache_file );
		}
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

	private function resolve_request(): ?array {
		// Só aceita o host canônico da home: bloqueia envenenamento via cabeçalho Host.
		$host = Cache_Engine::normalize_host( isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '' );

		if ( '' === $host || self::get_site_host() !== $host ) {
			return null;
		}

		// Valores crus de propósito: são os mesmos que o drop-in lê; o Cache_Engine valida cada parte.
		return Cache_Engine::resolve( $this->get_dropin_config(), wp_unslash( $_SERVER ), wp_unslash( $_COOKIE ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	private function get_request_cache_file(): string {
		$target = $this->resolve_request();

		return null === $target ? '' : $target['file'];
	}

	// URL limpa da página gravada; vazio para variantes com query (o pré-carregamento só conhece URLs limpas).
	private function get_current_url(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- já validada por resolve_request().
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';

		if ( false !== strpos( $uri, '?' ) ) {
			return '';
		}

		return (string) preg_replace( '#^(https?://[^/]+).*$#i', '$1', home_url() ) . $uri;
	}

	// Na home do site principal de um multisite em subpastas, as pastas dos outros sites ficam de fora.
	private function get_protected_subdirectories(): array {
		if ( ! is_multisite() || is_subdomain_install() || '/' !== self::get_site_home_path() ) {
			return array();
		}

		$current   = get_site();
		$protected = array();

		if ( ! $current instanceof \WP_Site ) {
			return array();
		}

		// O domínio do site no multisite inclui a porta (ex.: localhost:8080), igual ao das outras subpastas.
		foreach ( get_sites( array( 'number' => 0, 'domain' => $current->domain, 'site__not_in' => array( (int) $current->blog_id ) ) ) as $site ) {
			$segments = Cache_Engine::get_path_segments( (string) $site->path );

			if ( ! empty( $segments ) ) {
				$protected[ $segments[0] ] = true;
			}
		}

		return $protected;
	}

	private function is_protected_relative( string $relative, array $protected ): bool {
		if ( empty( $protected ) || '' === $relative ) {
			return false;
		}

		$first = explode( '/', $relative )[0];

		return isset( $protected[ $first ] );
	}

	// Apaga só os arquivos de página da raiz (todas as variantes) e a paginação da home.
	private function delete_home_files( string $directory, array &$found, bool $collect ): bool {
		$deleted = true;

		foreach ( (array) scandir( $directory ) as $name ) {
			if ( Cache_Engine::is_cache_filename( (string) $name ) ) {
				$deleted = wp_delete_file_from_directory( $directory . $name, $directory ) && $deleted;

				if ( '.html' === substr( (string) $name, -5 ) ) {
					$found[''] = true;
				}
			}
		}

		$pagination = $directory . $this->get_pagination_base() . '/';

		if ( is_dir( $pagination ) ) {
			$deleted = $this->delete_tree( $pagination, $this->get_pagination_base(), $found, $collect ) && $deleted;
		}

		return $deleted;
	}

	// Apaga a árvore e anota (em $found) os diretórios relativos que tinham páginas.
	private function delete_tree( string $directory, string $relative, array &$found, bool $collect ): bool {
		if ( $collect ) {
			$this->collect_pages( $directory, $relative, $found );
		}

		return direct_filesystem()->delete( $directory, true );
	}

	private function delete_tree_except( string $directory, array $protected, array &$found, bool $collect ): bool {
		$deleted = true;

		foreach ( (array) scandir( $directory ) as $name ) {
			$name = (string) $name;

			if ( '.' === $name || '..' === $name || isset( $protected[ $name ] ) ) {
				continue;
			}

			$path = $directory . $name;

			if ( is_dir( $path ) ) {
				$deleted = $this->delete_tree( trailingslashit( $path ), $name, $found, $collect ) && $deleted;
			} elseif ( Cache_Engine::is_cache_filename( $name ) ) {
				$deleted = wp_delete_file_from_directory( $path, $directory ) && $deleted;

				if ( '.html' === substr( $name, -5 ) ) {
					$found[''] = true;
				}
			}
		}

		return $deleted;
	}

	private function collect_pages( string $directory, string $relative, array &$found ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}

		try {
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ) );
			$scanned  = 0;
			$base     = untrailingslashit( wp_normalize_path( $directory ) );

			foreach ( $iterator as $item ) {
				if ( ++$scanned > self::PURGE_EXPIRED_FILES ) {
					break;
				}

				if ( $item->isFile() && '.html' === substr( $item->getFilename(), -5 ) && Cache_Engine::is_cache_filename( $item->getFilename() ) ) {
					$sub = trim( substr( wp_normalize_path( $item->getPath() ), strlen( $base ) ), '/' );

					$found[ trim( $relative . '/' . $sub, '/' ) ] = true;
				}
			}
		} catch ( \UnexpectedValueException $exception ) {
			unset( $exception );
		}
	}

	// Diretório relativo à raiz do cache do site (ex.: "blog/post") => URL pública.
	private function relative_dir_to_url( string $relative ): string {
		if ( '' === $relative ) {
			return home_url( '/' );
		}

		$trailing = '/' === substr( (string) get_option( 'permalink_structure' ), -1 );

		return home_url( '/' . $relative . ( $trailing ? '/' : '' ) );
	}

	private function get_purge_interval(): int {
		$lifespan = $this->get_lifespan();

		return $lifespan > 0 && $lifespan < HOUR_IN_SECONDS ? max( 5 * MINUTE_IN_SECONDS, $lifespan ) : HOUR_IN_SECONDS;
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

		// Páginas-mãe listam ou linkam as filhas.
		foreach ( get_post_ancestors( $post ) as $ancestor ) {
			$urls[] = get_permalink( $ancestor );
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
				$urls = array_merge( $urls, $this->get_term_related_urls( (int) $term->term_id, $taxonomy->name ) );
			}
		}

		if ( post_type_supports( $post->post_type, 'author' ) ) {
			$urls[] = get_author_posts_url( (int) $post->post_author );
		}

		// Arquivos por data e links "anterior/próximo" dos posts vizinhos.
		if ( 'post' === $post->post_type ) {
			$time   = strtotime( $post->post_date );
			$urls[] = get_year_link( (int) gmdate( 'Y', $time ) );
			$urls[] = get_month_link( (int) gmdate( 'Y', $time ), (int) gmdate( 'm', $time ) );
			$urls[] = get_day_link( (int) gmdate( 'Y', $time ), (int) gmdate( 'm', $time ), (int) gmdate( 'd', $time ) );

			foreach ( $this->get_adjacent_posts( $post ) as $adjacent ) {
				$urls[] = get_permalink( $adjacent );
			}
		}

		return array_values( array_unique( array_filter( (array) apply_filters( 'o2_rocket_post_purge_urls', $urls, $post ), 'is_string' ) ) );
	}

	private function get_adjacent_posts( \WP_Post $post ): array {
		if ( 'publish' !== $post->post_status ) {
			return array();
		}

		$global_post     = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- get_adjacent_post() lê o post global.

		$adjacent = array_filter( array( get_adjacent_post( false, '', true ), get_adjacent_post( false, '', false ) ) );

		$GLOBALS['post'] = $global_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		return array_values(
			array_filter(
				$adjacent,
				static function ( $item ) {
					return $item instanceof \WP_Post;
				}
			)
		);
	}

	private function get_term_related_urls( int $term_id, string $taxonomy ): array {
		$taxonomy_object = get_taxonomy( $taxonomy );

		if ( ! $taxonomy_object || ! $taxonomy_object->public ) {
			return array();
		}

		$urls = array();

		foreach ( array_merge( array( $term_id ), get_ancestors( $term_id, $taxonomy, 'taxonomy' ) ) as $id ) {
			$link = get_term_link( (int) $id, $taxonomy );

			if ( ! is_wp_error( $link ) ) {
				$urls[] = $link;
			}
		}

		return $urls;
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

	private function get_pagination_base(): string {
		global $wp_rewrite;

		return $wp_rewrite instanceof \WP_Rewrite && '' !== $wp_rewrite->pagination_base ? $wp_rewrite->pagination_base : 'page';
	}
}
