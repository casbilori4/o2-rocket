<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Preload;

use O2Rocket\Cache\Page_Cache;

defined( 'ABSPATH' ) || exit;

// Gera o cache antes do visitante: lê os sitemaps, enfileira as URLs e visita cada uma pelo próprio servidor.
// Uma requisição por vez e com orçamento de tempo por execução do WP-Cron: não sobrecarrega o servidor.
final class Preloader {

	const SETTING_ENABLED     = 'preload_enabled';
	const SETTING_EXCLUDED    = 'preload_excluded_uris';
	const SETTING_SITEMAPS    = 'preload_sitemaps';
	const SETTING_AFTER_PURGE = 'preload_after_purge';

	const CRON_HOOK      = 'o2_rocket_preload';
	const CRON_SCHEDULE  = 'o2_rocket_every_minute';
	const STATE_OPTION   = 'o2_rocket_preload_state';
	const LOCK_TRANSIENT = 'o2_rocket_preload_lock';
	const AJAX_ACTION    = 'o2_rocket_preload_status';

	const USER_AGENT        = 'O2Rocket-Preload';
	const MOBILE_USER_AGENT = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

	const TIME_BUDGET     = 20;
	const AJAX_BUDGET     = 8;
	const REQUEST_TIMEOUT = 25;
	const MAX_ATTEMPTS    = 3;
	const STUCK_SECONDS   = 300;
	const MAX_URLS        = 50000;
	const MAX_SITEMAPS    = 500;
	const FALLBACK_POSTS  = 1000;
	const FALLBACK_TERMS  = 200;
	const DELAY_MS        = 250;
	const BATCH           = 10;

	// Extensões que não são páginas HTML.
	const SKIP_EXTENSIONS = '#\.(?:xml|xsl|txt|json|pdf|zip|gz|rar|7z|docx?|xlsx?|pptx?|csv|jpe?g|png|gif|webp|avif|svg|ico|bmp|tiff?|mp[34]|m4a|wav|ogg|webm|mov|avi|css|js|woff2?|ttf|otf|eot)$#i';

	private Page_Cache $page_cache;

	private Preload_Queue $queue;

	private string $excluded_key = '';

	private string $excluded_regex = '';

	public function __construct( Page_Cache $page_cache, Preload_Queue $queue ) {
		$this->page_cache = $page_cache;
		$this->queue      = $queue;
	}

	public function register_hooks(): void {
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedule' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_cron' ) );
		add_action( 'admin_init', array( $this, 'maybe_install' ) );
		add_action( 'add_option_' . O2_ROCKET_OPTION, array( $this, 'on_settings_added' ), 20, 2 );
		add_action( 'update_option_' . O2_ROCKET_OPTION, array( $this, 'on_settings_updated' ), 20, 2 );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_status' ) );

		if ( ! $this->is_enabled() ) {
			return;
		}

		add_action( 'o2_rocket_after_purge_all', array( $this, 'start' ) );
		add_action( 'o2_rocket_after_purge_urls', array( $this, 'enqueue_urls' ) );
		add_action( 'o2_rocket_after_purge_expired', array( $this, 'enqueue_urls' ) );
		add_action( 'o2_rocket_cache_saved', array( $this, 'on_cache_saved' ) );
	}

	// Lido a cada chamada: as configurações podem ter mudado nesta mesma requisição.
	public function get_settings(): array {
		$stored = get_option( O2_ROCKET_OPTION, array() );

		return wp_parse_args(
			is_array( $stored ) ? $stored : array(),
			array(
				self::SETTING_ENABLED  => false,
				self::SETTING_EXCLUDED => array(),
				self::SETTING_SITEMAPS => array(),
			)
		);
	}

	public function is_enabled(): bool {
		$settings = $this->get_settings();

		return rest_sanitize_boolean( $settings[ self::SETTING_ENABLED ] ) && ! empty( $settings[ Page_Cache::SETTING_ENABLED ] );
	}

	public function add_cron_schedule( $schedules ) {
		$schedules = is_array( $schedules ) ? $schedules : array();

		if ( ! isset( $schedules[ self::CRON_SCHEDULE ] ) ) {
			$schedules[ self::CRON_SCHEDULE ] = array(
				'interval' => MINUTE_IN_SECONDS,
				'display'  => __( 'O² Rocket: a cada minuto', 'o2-rocket' ),
			);
		}

		return $schedules;
	}

	public function maybe_install(): void {
		if ( $this->is_enabled() && ! $this->queue->is_installed() ) {
			$this->queue->install();
		}
	}

	public function on_settings_added( $option, $value ): void {
		$this->on_settings_updated( array(), $value );
	}

	public function on_settings_updated( $old_value, $value ): void {
		if ( ! $this->is_enabled() ) {
			$this->stop();
			return;
		}

		// O Page_Cache já limpou tudo (o2_rocket_after_purge_all); aqui só garante o início com o pré-carregamento recém-ligado.
		$was_enabled = is_array( $old_value ) && ! empty( $old_value[ self::SETTING_ENABLED ] ) && ! empty( $old_value[ Page_Cache::SETTING_ENABLED ] );

		if ( ! $was_enabled ) {
			$this->start();
		}
	}

	// Pré-carregamento completo: todas as URLs voltam a pendentes e os sitemaps são lidos de novo.
	public function start(): bool {
		if ( ! $this->is_enabled() ) {
			return false;
		}

		if ( ! $this->queue->is_installed() && ! $this->queue->install() ) {
			$this->update_state( array( 'error' => __( 'não foi possível criar a tabela da fila de pré-carregamento no banco de dados', 'o2-rocket' ) ) );

			return false;
		}

		$this->queue->reset_all();
		$this->queue->add( array( home_url( '/' ) ) );

		$sitemaps = $this->get_sitemaps();

		$this->update_state(
			array(
				'sitemaps' => $sitemaps,
				'seen'     => array(),
				'fallback' => empty( $sitemaps ),
				'total'    => 0,
				'started'  => time(),
				'error'    => '',
			)
		);

		$this->schedule();

		return true;
	}

	public function stop(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		delete_transient( self::LOCK_TRANSIENT );
	}

	public function enqueue_urls( $urls ): void {
		if ( ! $this->is_enabled() || ! $this->queue->is_installed() ) {
			return;
		}

		$urls = array_values( array_filter( array_map( array( $this, 'normalize_url' ), (array) $urls ) ) );

		if ( ! empty( $urls ) ) {
			$this->queue->add( $urls );
			$this->schedule();
		}
	}

	// Página gerada por uma visita: entra na fila como concluída (o pré-carregamento aprende URLs fora do sitemap).
	public function on_cache_saved( $url ): void {
		$url = $this->normalize_url( (string) $url );

		if ( '' === $url || ! $this->queue->is_installed() ) {
			return;
		}

		$this->queue->add( array( $url ), $this->is_url_fresh( $url ) ? Preload_Queue::STATUS_COMPLETED : Preload_Queue::STATUS_PENDING );
	}

	public function run_cron(): void {
		$this->run( self::TIME_BUDGET );
	}

	public function run( int $budget ): void {
		if ( ! $this->is_enabled() || ! $this->queue->is_installed() ) {
			$this->stop();
			return;
		}

		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return;
		}

		set_transient( self::LOCK_TRANSIENT, time(), $budget + MINUTE_IN_SECONDS );

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( $budget + 60 );
		}

		$deadline = microtime( true ) + $budget;

		$this->read_sitemaps( $deadline );
		$this->recover_stuck();

		while ( microtime( true ) < $deadline ) {
			$rows = $this->queue->get_by_status( Preload_Queue::STATUS_PENDING, (int) apply_filters( 'o2_rocket_preload_batch_size', self::BATCH ) );

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				if ( microtime( true ) >= $deadline ) {
					break 2;
				}

				$this->process_row( $row );
			}
		}

		$this->update_state( array( 'last_run' => time() ) );
		delete_transient( self::LOCK_TRANSIENT );

		$state = $this->get_state();

		if ( empty( $state['sitemaps'] ) && empty( $state['fallback'] ) && ! $this->queue->has_pending() && empty( $this->queue->get_by_status( Preload_Queue::STATUS_RUNNING, 1 ) ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	// Polling do painel: devolve o progresso e adianta a fila enquanto a aba está aberta (WP-Cron depende de visitas).
	public function ajax_status(): void {
		check_ajax_referer( self::AJAX_ACTION );

		if ( ! current_user_can( (string) apply_filters( 'o2_rocket_capability', 'manage_options' ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Permissão negada.', 'o2-rocket' ) ), 403 );
		}

		if ( $this->is_enabled() && $this->has_work() ) {
			$this->run( self::AJAX_BUDGET );
		}

		wp_send_json_success( $this->get_status() );
	}

	public function get_status(): array {
		$state  = $this->get_state();
		$counts = $this->queue->count_by_status();

		return array(
			'enabled'   => $this->is_enabled(),
			'installed' => $this->queue->is_installed(),
			'counts'    => $counts,
			'total'     => array_sum( $counts ),
			'sitemaps'  => count( (array) $state['sitemaps'] ),
			'running'   => $this->has_work(),
			'last_run'  => (int) $state['last_run'],
			'started'   => (int) $state['started'],
			'error'     => (string) $state['error'],
		);
	}

	public function has_work(): bool {
		$state = $this->get_state();

		return ! empty( $state['sitemaps'] ) || ! empty( $state['fallback'] ) || $this->queue->has_pending() || ! empty( $this->queue->get_by_status( Preload_Queue::STATUS_RUNNING, 1 ) );
	}

	public function get_sitemaps(): array {
		$settings = $this->get_settings();
		$extra    = array();

		foreach ( (array) $settings[ self::SETTING_SITEMAPS ] as $line ) {
			$line = trim( (string) $line );

			if ( '' !== $line ) {
				$extra[] = 0 === strpos( $line, 'http' ) ? $line : home_url( '/' . ltrim( $line, '/' ) );
			}
		}

		return array_values( array_unique( array_merge( Sitemap_Parser::discover(), $extra ) ) );
	}

	// URL do próprio site, sem query/fragmento, fora das exclusões do cache e do pré-carregamento. Vazio = ignorar.
	public function normalize_url( $url ): string {
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		$home  = wp_parse_url( home_url( '/' ) );

		if ( ! is_array( $parts ) || ! is_array( $home ) || empty( $parts['host'] ) || ! empty( $parts['query'] ) ) {
			return '';
		}

		$same_host = strtolower( $parts['host'] ) === strtolower( (string) $home['host'] ) && ( isset( $parts['port'] ) ? (int) $parts['port'] : 0 ) === ( isset( $home['port'] ) ? (int) $home['port'] : 0 );
		$path      = isset( $parts['path'] ) ? (string) $parts['path'] : '/';

		if ( ! $same_host || 1 === preg_match( self::SKIP_EXTENSIONS, $path ) ) {
			return '';
		}

		$home_path = isset( $home['path'] ) ? (string) $home['path'] : '/';

		if ( 0 !== strpos( trailingslashit( $path ), $home_path ) || $this->page_cache->is_excluded_path( $path ) || $this->is_preload_excluded( $path ) ) {
			return '';
		}

		// Mesmo esquema da home: o arquivo -https certo e sem redirecionamento.
		return set_url_scheme( $url, (string) $home['scheme'] );
	}

	public function is_preload_excluded( string $path ): bool {
		$patterns = $this->get_settings()[ self::SETTING_EXCLUDED ];
		$key      = md5( wp_json_encode( $patterns ) );

		if ( $key !== $this->excluded_key ) {
			$this->excluded_key   = $key;
			$this->excluded_regex = Page_Cache::build_uri_regex( Page_Cache::sanitize_uri_patterns( $patterns ) );
		}

		return '' !== $this->excluded_regex && 1 === preg_match( $this->excluded_regex, $path );
	}

	private function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), self::CRON_SCHEDULE, self::CRON_HOOK );
		}
	}

	private function read_sitemaps( float $deadline ): void {
		$state = $this->get_state();

		while ( ! empty( $state['sitemaps'] ) && microtime( true ) < $deadline && $state['total'] < self::MAX_URLS ) {
			$sitemap = (string) array_shift( $state['sitemaps'] );
			$hash    = md5( $sitemap );

			if ( isset( $state['seen'][ $hash ] ) || count( $state['seen'] ) >= self::MAX_SITEMAPS ) {
				continue;
			}

			$state['seen'][ $hash ] = true;
			$parsed                 = Sitemap_Parser::fetch( $sitemap );

			if ( is_wp_error( $parsed ) ) {
				$state['error'] = $parsed->get_error_message();
				$this->update_state( $state );
				continue;
			}

			foreach ( $parsed['sitemaps'] as $child ) {
				if ( $this->is_same_host( $child ) ) {
					$state['sitemaps'][] = $child;
				}
			}

			$urls = array_slice( array_values( array_filter( array_map( array( $this, 'normalize_url' ), $parsed['urls'] ) ) ), 0, self::MAX_URLS - $state['total'] );

			if ( ! empty( $urls ) ) {
				$this->queue->add( $urls );
				$state['total'] += count( $urls );
			}

			$this->update_state( $state );
		}

		// Sem sitemap (ou todos falharam): monta a lista a partir do banco.
		if ( empty( $state['sitemaps'] ) && ( ! empty( $state['fallback'] ) || ( 0 === $state['total'] && ! empty( $state['seen'] ) ) ) ) {
			$this->queue->add( $this->get_fallback_urls() );
			$state['fallback'] = false;
			$state['seen']     = array();
			$this->update_state( $state );
		}
	}

	private function get_fallback_urls(): array {
		$urls       = array( home_url( '/' ) );
		$post_types = array_values( array_filter( get_post_types( array( 'public' => true ) ), 'is_post_type_viewable' ) );

		$posts = get_posts(
			array(
				'post_type'              => array_diff( $post_types, array( 'attachment' ) ),
				'post_status'            => 'publish',
				'numberposts'            => (int) apply_filters( 'o2_rocket_preload_fallback_posts', self::FALLBACK_POSTS ),
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'has_password'           => false,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => false,
			)
		);

		foreach ( $posts as $post_id ) {
			$urls[] = get_permalink( (int) $post_id );
		}

		$terms = get_terms(
			array(
				'taxonomy'   => array_values( get_taxonomies( array( 'public' => true ) ) ),
				'hide_empty' => true,
				'number'     => self::FALLBACK_TERMS,
				'orderby'    => 'count',
				'order'      => 'DESC',
			)
		);

		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$link = get_term_link( $term );

				if ( ! is_wp_error( $link ) ) {
					$urls[] = $link;
				}
			}
		}

		return array_values( array_filter( array_map( array( $this, 'normalize_url' ), array_filter( $urls, 'is_string' ) ) ) );
	}

	// Requisição que não voltou (timeout, servidor reiniciado): tenta de novo até o limite.
	private function recover_stuck(): void {
		foreach ( $this->queue->get_stuck( self::STUCK_SECONDS, 50 ) as $row ) {
			$attempts = (int) $row->attempts + 1;

			$this->queue->set_status( (int) $row->id, $attempts >= self::MAX_ATTEMPTS ? Preload_Queue::STATUS_FAILED : Preload_Queue::STATUS_PENDING, $attempts );
		}
	}

	private function process_row( $row ): void {
		$id  = (int) $row->id;
		$url = $this->normalize_url( (string) $row->url );

		if ( '' === $url ) {
			$this->queue->delete( $id );
			return;
		}

		$this->queue->set_status( $id, Preload_Queue::STATUS_RUNNING );

		$failed = false;

		foreach ( $this->get_variants() as $mobile ) {
			if ( $this->is_variant_fresh( $url, $mobile ) ) {
				continue;
			}

			$result = $this->request( $url, $mobile );

			// Redireciona ou não existe mais: não é uma página para cachear.
			if ( 'redirect' === $result || 'gone' === $result ) {
				$this->queue->delete( $id );
				return;
			}

			$failed = $failed || 'error' === $result;

			usleep( max( 0, (int) apply_filters( 'o2_rocket_preload_delay_ms', self::DELAY_MS ) ) * 1000 );
		}

		if ( ! $failed ) {
			$this->queue->set_status( $id, Preload_Queue::STATUS_COMPLETED, 0 );
			return;
		}

		$attempts = (int) $row->attempts + 1;

		$this->queue->set_status( $id, $attempts >= self::MAX_ATTEMPTS ? Preload_Queue::STATUS_FAILED : Preload_Queue::STATUS_PENDING, $attempts );
	}

	private function request( string $url, bool $mobile ): string {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => self::REQUEST_TIMEOUT,
				'redirection' => 0,
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				'user-agent'  => $mobile ? self::MOBILE_USER_AGENT . ' ' . self::USER_AGENT . '/' . O2_ROCKET_VERSION : self::USER_AGENT . '/' . O2_ROCKET_VERSION . '; ' . home_url( '/' ),
				'headers'     => array( 'Accept' => 'text/html,application/xhtml+xml' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->update_state(
				array(
					/* translators: %s: mensagem de erro. */
					'error' => sprintf( __( 'o servidor não conseguiu acessar o próprio site (%s)', 'o2-rocket' ), $response->get_error_message() ),
				)
			);

			return 'error';
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code >= 300 && $code < 400 ) {
			return 'redirect';
		}

		if ( 404 === $code || 410 === $code ) {
			return 'gone';
		}

		return 200 === $code ? 'ok' : 'error';
	}

	private function get_variants(): array {
		return $this->page_cache->is_mobile_cache_enabled() ? array( false, true ) : array( false );
	}

	private function is_url_fresh( string $url ): bool {
		foreach ( $this->get_variants() as $mobile ) {
			if ( ! $this->is_variant_fresh( $url, $mobile ) ) {
				return false;
			}
		}

		return true;
	}

	private function is_variant_fresh( string $url, bool $mobile ): bool {
		$file = $this->page_cache->get_url_cache_file( $url, $mobile );

		if ( '' === $file || ! is_file( $file ) ) {
			return false;
		}

		$lifespan = $this->page_cache->get_lifespan();

		return $lifespan < 1 || (int) filemtime( $file ) + $lifespan > time();
	}

	private function is_same_host( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		return is_string( $host ) && strtolower( $host ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	private function get_state(): array {
		$state = get_option( self::STATE_OPTION, array() );

		return wp_parse_args(
			is_array( $state ) ? $state : array(),
			array(
				'sitemaps' => array(),
				'seen'     => array(),
				'fallback' => false,
				'total'    => 0,
				'started'  => 0,
				'last_run' => 0,
				'error'    => '',
			)
		);
	}

	private function update_state( array $changes ): void {
		update_option( self::STATE_OPTION, array_merge( $this->get_state(), $changes ), false );
	}
}
