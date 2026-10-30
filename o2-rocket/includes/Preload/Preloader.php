<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Preload;

use O2Rocket\Cache\Page_Cache;

defined( 'ABSPATH' ) || exit;

// Gera o cache antes do visitante: lê os sitemaps, enfileira as URLs e visita cada uma pelo próprio servidor.
// Uma requisição por vez e com orçamento de tempo por execução do WP-Cron: não sobrecarrega o servidor.
// Fila com prioridade (home e página pedida → menu principal → recém-limpas → resto) e início imediato por uma
// requisição não bloqueante, sem esperar o próximo WP-Cron.
final class Preloader {

	const SETTING_ENABLED     = 'preload_enabled';
	const SETTING_EXCLUDED    = 'preload_excluded_uris';
	const SETTING_SITEMAPS    = 'preload_sitemaps';
	const SETTING_AFTER_PURGE = 'preload_after_purge';

	const CRON_HOOK        = 'o2_rocket_preload';
	const CRON_SCHEDULE    = 'o2_rocket_every_minute';
	const STATE_OPTION     = 'o2_rocket_preload_state';
	const LOCK_OPTION      = 'o2_rocket_preload_lock';
	const AJAX_ACTION      = 'o2_rocket_preload_status';
	const KICK_ACTION      = 'o2_rocket_preload_kick';
	const KICK_TRANSIENT   = 'o2_rocket_preload_kick';
	const REKICK_TRANSIENT = 'o2_rocket_preload_rekick';
	const ADMIN_TRANSIENT  = 'o2_rocket_preload_admin_tick';

	const PRIORITY_NOW    = 0;
	const PRIORITY_MENU   = 1;
	const PRIORITY_PURGED = 2;

	const USER_AGENT        = 'O2Rocket-Preload';
	const MOBILE_USER_AGENT = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

	const TIME_BUDGET     = 20;
	const AJAX_BUDGET     = 8;
	const ADMIN_BUDGET    = 6;
	const REQUEST_TIMEOUT = 25;
	const MAX_ATTEMPTS    = 3;
	const STUCK_SECONDS   = 90;
	const STALL_SECONDS   = 600;
	const KICK_TIMEOUT_MS = 500;
	const MAX_MENU_URLS   = 30;
	const MAX_PURGED_URLS = 1000;
	const MAX_URLS        = 50000;
	const MAX_SITEMAPS    = 500;
	const FALLBACK_POSTS  = 1000;
	const FALLBACK_TERMS  = 200;
	const DELAY_MS        = 250;
	const BATCH           = 10;

	// Locais de menu que costumam ser o menu principal, na ordem de preferência.
	const MENU_LOCATIONS = array( 'primary', 'main', 'main-menu', 'main_menu', 'primary-menu', 'primary_menu', 'menu-1', 'header', 'header-menu', 'header_menu', 'top', 'top-menu' );

	// Extensões que não são páginas HTML.
	const SKIP_EXTENSIONS = '#\.(?:xml|xsl|txt|json|pdf|zip|gz|rar|7z|docx?|xlsx?|pptx?|csv|jpe?g|png|gif|webp|avif|svg|ico|bmp|tiff?|mp[34]|m4a|wav|ogg|webm|mov|avi|css|js|woff2?|ttf|otf|eot)$#i';

	private Page_Cache $page_cache;

	private Preload_Queue $queue;

	private string $excluded_key = '';

	private string $excluded_regex = '';

	// URLs limpas nesta requisição (todas as limpezas, para "Limpar e pré-carregar").
	private array $purged = array();

	private bool $started = false;

	private int $kick_budget = 0;

	private bool $kick_cron = false;

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
		add_action( 'wp_ajax_' . self::KICK_ACTION, array( $this, 'handle_kick' ) );
		add_action( 'wp_ajax_nopriv_' . self::KICK_ACTION, array( $this, 'handle_kick' ) );

		if ( ! $this->is_enabled() ) {
			return;
		}

		add_action( 'admin_init', array( $this, 'maybe_tick_from_admin' ) );
		add_action( 'o2_rocket_after_purge_all', array( $this, 'on_purge_all' ), 10, 2 );
		add_action( 'o2_rocket_after_purge_urls', array( $this, 'on_purge_urls' ) );
		add_action( 'o2_rocket_after_purge_expired', array( $this, 'on_purge_urls' ) );
		add_action( 'o2_rocket_cache_saved', array( $this, 'on_cache_saved' ) );
	}

	// Lido a cada chamada: as configurações podem ter mudado nesta mesma requisição.
	public function get_settings(): array {
		$stored = get_option( O2_ROCKET_OPTION, array() );

		return wp_parse_args(
			is_array( $stored ) ? $stored : array(),
			array(
				self::SETTING_ENABLED     => false,
				self::SETTING_EXCLUDED    => array(),
				self::SETTING_SITEMAPS    => array(),
				self::SETTING_AFTER_PURGE => true,
			)
		);
	}

	public function is_enabled(): bool {
		$settings = $this->get_settings();

		return rest_sanitize_boolean( $settings[ self::SETTING_ENABLED ] ) && ! empty( $settings[ Page_Cache::SETTING_ENABLED ] );
	}

	public function is_after_purge_enabled(): bool {
		return $this->is_enabled() && rest_sanitize_boolean( $this->get_settings()[ self::SETTING_AFTER_PURGE ] );
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

	// Pré-carregamento completo: todas as URLs voltam a pendentes e os sitemaps são lidos de novo. Na frente da fila:
	// a home e $targets, depois o menu principal e as páginas que acabaram de ser limpas. Começa na hora.
	public function start( array $targets = array() ): bool {
		if ( ! $this->is_enabled() ) {
			return false;
		}

		if ( ! $this->queue->is_installed() && ! $this->queue->install() ) {
			$this->update_state( array( 'error' => __( 'não foi possível criar a tabela da fila de pré-carregamento no banco de dados', 'o2-rocket' ) ) );

			return false;
		}

		// Várias limpezas totais na mesma requisição (salvar opções + perfil, por exemplo): recomeça uma vez só.
		if ( ! $this->started ) {
			$this->started = true;
			$this->queue->reset_all();

			$sitemaps = $this->get_sitemaps();

			$this->update_state(
				array(
					'sitemaps' => $sitemaps,
					'seen'     => array(),
					'fallback' => empty( $sitemaps ),
					'total'    => 0,
					'started'  => time(),
					'batch'    => time(),
					'error'    => '',
				)
			);

			$this->queue->add( $this->get_menu_urls(), Preload_Queue::STATUS_PENDING, self::PRIORITY_MENU );
		}

		$this->enqueue_urls( array_merge( array( home_url( '/' ) ), $targets ), self::PRIORITY_NOW );
		$this->enqueue_urls( $this->purged, self::PRIORITY_PURGED );

		return true;
	}

	// Depois de "Limpar e pré-carregar" de uma página ou conteúdo: a página pedida e tudo que foi limpo junto.
	public function preload_purged( array $targets ): bool {
		if ( ! $this->is_enabled() || ( ! $this->queue->is_installed() && ! $this->queue->install() ) ) {
			return false;
		}

		$this->enqueue_urls( $targets, self::PRIORITY_NOW );
		$this->enqueue_urls( $this->purged, self::PRIORITY_PURGED );

		return true;
	}

	public function stop(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		$this->release_lock();
	}

	public function on_purge_all( $deleted = true, $urls = array() ): void {
		$this->remember_purged( $urls );

		if ( $this->is_after_purge_enabled() ) {
			$this->start();
		}
	}

	public function on_purge_urls( $urls ): void {
		$this->remember_purged( $urls );

		if ( $this->is_after_purge_enabled() ) {
			$this->enqueue_urls( $urls );
		}
	}

	// Home sempre na frente; o processamento começa no fim desta requisição.
	public function enqueue_urls( $urls, int $priority = self::PRIORITY_PURGED ): void {
		if ( ! $this->is_enabled() || ! $this->queue->is_installed() ) {
			return;
		}

		$urls = array_values( array_unique( array_filter( array_map( array( $this, 'normalize_url' ), (array) $urls ) ) ) );

		if ( empty( $urls ) ) {
			return;
		}

		// Fila parada: começa um lote novo (o progresso "x de y" conta a partir daqui).
		if ( ! $this->started && ! $this->queue->has_pending() && ! $this->queue->has_running() ) {
			$this->update_state( array( 'batch' => time() ) );
		}

		$home = $this->normalize_url( home_url( '/' ) );

		if ( in_array( $home, $urls, true ) ) {
			$this->queue->add( array( $home ), Preload_Queue::STATUS_PENDING, self::PRIORITY_NOW );
		}

		$this->queue->add( $urls, Preload_Queue::STATUS_PENDING, $priority );
		$this->schedule();
		$this->kick();
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
		$this->update_state( array( 'cron_run' => time() ) );
		$this->run( self::TIME_BUDGET );
	}

	public function run( int $budget ): void {
		if ( ! $this->is_enabled() || ! $this->queue->is_installed() ) {
			$this->stop();
			return;
		}

		if ( ! $this->acquire_lock( $budget + MINUTE_IN_SECONDS ) ) {
			return;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( $budget + 60 );
		}

		$deadline = microtime( true ) + $budget;

		// As urgentes (home, página pedida, menu, recém-limpas) não esperam a leitura dos sitemaps.
		$this->recover_stuck();
		$this->process_queue( $deadline, self::PRIORITY_PURGED );
		$this->read_sitemaps( $deadline );
		$this->process_queue( $deadline, 255 );

		$this->update_state( array( 'last_run' => time() ) );
		$this->release_lock();

		if ( ! $this->has_work() ) {
			delete_transient( self::REKICK_TRANSIENT );
			wp_clear_scheduled_hook( self::CRON_HOOK );
			return;
		}

		// Alguém pediu para começar enquanto esta execução rodava (e ela pode ter acabado o tempo antes): segue já.
		$rekick = (int) get_transient( self::REKICK_TRANSIENT );

		if ( $rekick > 0 ) {
			delete_transient( self::REKICK_TRANSIENT );
			$this->kick( $rekick );
			$this->send_kick();
		}
	}

	// Começa a fila no fim desta requisição (uma vez só, por mais limpezas que aconteçam nela).
	public function kick( int $budget = self::TIME_BUDGET, bool $cron = true ): void {
		if ( 0 === $this->kick_budget ) {
			add_action( 'shutdown', array( $this, 'send_kick' ), 20 );
		}

		$this->kick_budget = max( $this->kick_budget, $budget );
		$this->kick_cron   = $this->kick_cron || $cron;
	}

	// Requisição não bloqueante para o admin-ajax com uma chave de uso único, mais o spawn_cron como reserva
	// (alguns servidores bloqueiam um dos dois). A trava atômica impede que os dois rodem juntos.
	public function send_kick(): void {
		if ( $this->kick_budget < 1 || ! $this->is_enabled() || ! $this->queue->is_installed() || ! $this->queue->has_pending() ) {
			return;
		}

		// Já tem uma execução viva: ela pega as novas na frente e, ao terminar, dispara outra se ainda houver fila.
		if ( $this->is_locked() ) {
			set_transient( self::REKICK_TRANSIENT, max( $this->kick_budget, (int) get_transient( self::REKICK_TRANSIENT ) ), 5 * MINUTE_IN_SECONDS );
			$this->kick_budget = 0;
			$this->kick_cron   = false;

			return;
		}

		$key = wp_generate_password( 32, false );

		set_transient(
			self::KICK_TRANSIENT,
			array(
				'hash'   => wp_hash( $key ),
				'budget' => $this->kick_budget,
			),
			5 * MINUTE_IN_SECONDS
		);

		// A biblioteca de HTTP espera no mínimo 1 s mesmo sem bloquear: com o cURL, meio segundo basta para entregar o
		// pedido no próprio servidor e a tela de quem clicou não fica esperando.
		$short = static function ( $handle ): void {
			if ( defined( 'CURLOPT_TIMEOUT_MS' ) && function_exists( 'curl_setopt' ) ) {
				curl_setopt( $handle, CURLOPT_NOSIGNAL, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
				curl_setopt( $handle, CURLOPT_TIMEOUT_MS, self::KICK_TIMEOUT_MS ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
			}
		};

		add_action( 'http_api_curl', $short );

		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				'body'      => array(
					'action' => self::KICK_ACTION,
					'key'    => $key,
				),
			)
		);

		if ( $this->kick_cron && ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) && function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		remove_action( 'http_api_curl', $short );

		$this->kick_budget = 0;
		$this->kick_cron   = false;
	}

	public function handle_kick(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- requisição do próprio servidor, sem sessão: a proteção é a chave de uso único.
		$key  = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		$kick = get_transient( self::KICK_TRANSIENT );

		if ( '' === $key || ! is_array( $kick ) || empty( $kick['hash'] ) || ! hash_equals( (string) $kick['hash'], wp_hash( $key ) ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}

		delete_transient( self::KICK_TRANSIENT );

		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}

		$this->run( max( 1, min( self::TIME_BUDGET, (int) $kick['budget'] ) ) );

		wp_die( '', '', array( 'response' => 200 ) );
	}

	// WP-Cron depende de visitas (e às vezes está quebrado): toda tela do painel aberta por um administrador adianta
	// um lote pequeno em segundo plano, no máximo uma vez por minuto.
	public function maybe_tick_from_admin(): void {
		if ( wp_doing_ajax() || wp_doing_cron() || get_transient( self::ADMIN_TRANSIENT ) || ! current_user_can( (string) apply_filters( 'o2_rocket_capability', 'manage_options' ) ) ) {
			return;
		}

		set_transient( self::ADMIN_TRANSIENT, 1, MINUTE_IN_SECONDS );

		// O próprio núcleo já chama o WP-Cron nesta tela: aqui vai só o pedido direto.
		if ( $this->queue->is_installed() && $this->queue->has_pending() ) {
			$this->kick( self::ADMIN_BUDGET, false );
		}
	}

	// Fila pendente e o WP-Cron sem rodar há mais de 10 minutos. Vazio = tudo certo.
	public function get_stall(): array {
		if ( ! $this->is_enabled() || ! $this->queue->is_installed() ) {
			return array();
		}

		$state = $this->get_state();
		$since = max( (int) $state['cron_run'], (int) $state['batch'] );

		if ( time() - $since < self::STALL_SECONDS || ! $this->queue->has_pending() ) {
			return array();
		}

		return array(
			'since'    => (int) $state['cron_run'],
			'pending'  => (int) $this->queue->count_by_status()[ Preload_Queue::STATUS_PENDING ],
			'disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
		);
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
			'progress'  => $this->get_progress(),
			'sitemaps'  => count( (array) $state['sitemaps'] ),
			'running'   => $this->has_work(),
			'last_run'  => (int) $state['last_run'],
			'started'   => (int) $state['started'],
			'error'     => (string) $state['error'],
		);
	}

	// "x de y" do lote atual: só o que entrou na fila desde a última vez que ela estava vazia.
	public function get_progress(): array {
		$batch  = (int) $this->get_state()['batch'];
		$counts = $this->queue->count_since( gmdate( 'Y-m-d H:i:s', max( 0, $batch ) ) );
		$done   = $counts[ Preload_Queue::STATUS_COMPLETED ] + $counts[ Preload_Queue::STATUS_FAILED ];

		return array(
			'done'   => $done,
			'total'  => $done + $counts[ Preload_Queue::STATUS_PENDING ] + $counts[ Preload_Queue::STATUS_RUNNING ],
			'failed' => $counts[ Preload_Queue::STATUS_FAILED ],
		);
	}

	public function has_work(): bool {
		$state = $this->get_state();

		return ! empty( $state['sitemaps'] ) || ! empty( $state['fallback'] ) || $this->queue->has_pending() || $this->queue->has_running();
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

	private function process_queue( float $deadline, int $max_priority ): void {
		while ( microtime( true ) < $deadline ) {
			$rows = $this->queue->get_by_status( Preload_Queue::STATUS_PENDING, (int) apply_filters( 'o2_rocket_preload_batch_size', self::BATCH ), $max_priority );

			if ( empty( $rows ) ) {
				return;
			}

			foreach ( $rows as $row ) {
				if ( microtime( true ) >= $deadline ) {
					return;
				}

				$this->process_row( $row );

				// Uma mais urgente que chegou no meio do lote (post salvo, outra limpeza) passa na frente.
				if ( (int) $row->priority > 0 && ! empty( $this->queue->get_by_status( Preload_Queue::STATUS_PENDING, 1, min( $max_priority, (int) $row->priority - 1 ) ) ) ) {
					continue 2;
				}
			}
		}
	}

	private function remember_purged( $urls ): void {
		foreach ( (array) $urls as $url ) {
			if ( is_string( $url ) && count( $this->purged ) < self::MAX_PURGED_URLS ) {
				$this->purged[] = $url;
			}
		}
	}

	// Menu principal do tema: local de menu clássico (ou o primeiro menu com itens) ou a navegação do cabeçalho
	// de um tema de blocos. Filtro o2_rocket_preload_priority_urls.
	private function get_menu_urls(): array {
		$urls      = array();
		$locations = get_nav_menu_locations();
		$menu_id   = 0;

		foreach ( self::MENU_LOCATIONS as $location ) {
			if ( ! empty( $locations[ $location ] ) ) {
				$menu_id = (int) $locations[ $location ];
				break;
			}
		}

		if ( 0 === $menu_id ) {
			$assigned = array_values( array_filter( array_map( 'intval', (array) $locations ) ) );
			$menus    = empty( $assigned ) ? wp_get_nav_menus( array( 'number' => 1 ) ) : array();
			$menu_id  = ! empty( $assigned ) ? $assigned[0] : ( ! empty( $menus ) && $menus[0] instanceof \WP_Term ? (int) $menus[0]->term_id : 0 );
		}

		if ( $menu_id > 0 ) {
			foreach ( (array) wp_get_nav_menu_items( $menu_id ) as $item ) {
				if ( is_object( $item ) && ! empty( $item->url ) ) {
					$urls[] = (string) $item->url;
				}
			}
		} elseif ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			$urls = $this->get_block_menu_urls();
		}

		// Link relativo à raiz do domínio (/contato/) vira absoluto.
		$origin = (string) preg_replace( '#^(https?://[^/]+).*$#i', '$1', home_url() );

		foreach ( $urls as $index => $url ) {
			if ( is_string( $url ) && 1 === preg_match( '#^/(?!/)#', $url ) ) {
				$urls[ $index ] = $origin . $url;
			}
		}

		$urls = array_map( array( $this, 'normalize_url' ), (array) apply_filters( 'o2_rocket_preload_priority_urls', $urls ) );

		return array_slice( array_values( array_unique( array_filter( $urls ) ) ), 0, self::MAX_MENU_URLS );
	}

	private function get_block_menu_urls(): array {
		$template   = function_exists( 'get_block_template' ) ? get_block_template( get_stylesheet() . '//header', 'wp_template_part' ) : null;
		$navigation = $template && ! empty( $template->content ) ? $this->find_block( parse_blocks( $template->content ), 'core/navigation' ) : null;

		if ( null === $navigation ) {
			return array();
		}

		$blocks = (array) $navigation['innerBlocks'];

		// Sem itens em linha: a navegação indicada pelo ref ou, como o núcleo faz, a mais recente.
		if ( empty( $blocks ) ) {
			$ref  = isset( $navigation['attrs']['ref'] ) ? (int) $navigation['attrs']['ref'] : 0;
			$post = $ref > 0 ? get_post( $ref ) : null;

			if ( ! $post instanceof \WP_Post ) {
				$posts = get_posts(
					array(
						'post_type'   => 'wp_navigation',
						'post_status' => 'publish',
						'numberposts' => 1,
						'orderby'     => 'date',
						'order'       => 'DESC',
					)
				);
				$post  = ! empty( $posts ) ? $posts[0] : null;
			}

			$blocks = $post instanceof \WP_Post && 'wp_navigation' === $post->post_type ? parse_blocks( $post->post_content ) : array( array( 'blockName' => 'core/page-list' ) );
		}

		$urls = array();
		$this->collect_link_urls( $blocks, $urls );

		return $urls;
	}

	// Procura também dentro dos padrões (o cabeçalho de muitos temas é só um core/pattern).
	private function find_block( array $blocks, string $name, int $depth = 0 ): ?array {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || $depth > 10 ) {
				continue;
			}

			if ( isset( $block['blockName'] ) && $name === $block['blockName'] ) {
				return wp_parse_args( $block, array( 'attrs' => array(), 'innerBlocks' => array() ) );
			}

			$inner = empty( $block['innerBlocks'] ) ? array() : (array) $block['innerBlocks'];

			if ( isset( $block['blockName'] ) && 'core/pattern' === $block['blockName'] && ! empty( $block['attrs']['slug'] ) && class_exists( '\WP_Block_Patterns_Registry' ) ) {
				$pattern = \WP_Block_Patterns_Registry::get_instance()->get_registered( (string) $block['attrs']['slug'] );
				$inner   = ! empty( $pattern['content'] ) ? parse_blocks( (string) $pattern['content'] ) : $inner;
			}

			$found = empty( $inner ) ? null : $this->find_block( $inner, $name, $depth + 1 );

			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	private function collect_link_urls( array $blocks, array &$urls ): void {
		foreach ( $blocks as $block ) {
			$name = is_array( $block ) && isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

			if ( 'core/home-link' === $name ) {
				$urls[] = home_url( '/' );
			} elseif ( 'core/page-list' === $name ) {
				// Lista automática de páginas: as do primeiro nível, na ordem do menu.
				foreach ( get_pages( array( 'parent' => 0, 'sort_column' => 'menu_order,post_title', 'number' => self::MAX_MENU_URLS ) ) as $page ) {
					$urls[] = (string) get_permalink( $page );
				}
			} elseif ( ! empty( $block['attrs']['url'] ) && is_string( $block['attrs']['url'] ) ) {
				$urls[] = $block['attrs']['url'];
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->collect_link_urls( (array) $block['innerBlocks'], $urls );
			}
		}
	}

	// INSERT IGNORE na wp_options: só um processo cria a linha (com transient, o cron e o início imediato
	// podiam rodar juntos). Trava vencida (processo interrompido) é tomada por um só, pelo valor antigo.
	private function acquire_lock( int $ttl ): bool {
		global $wpdb;

		$now = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK_OPTION, (string) ( $now + $ttl ) ) );

		if ( 1 === (int) $wpdb->rows_affected ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) );

		if ( null === $current || (int) $current > $now ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) ( $now + $ttl ), self::LOCK_OPTION, (string) $current ) );

		return 1 === (int) $wpdb->rows_affected;
	}

	private function release_lock(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) );
	}

	public function is_locked(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) ) > time();
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

	// Requisição que não voltou (timeout, processo interrompido): tenta de novo até o limite. Quem chega aqui tem a
	// trava, então nenhuma outra execução está viva; 90 s cobrem uma página no celular e no computador (2 × 25 s).
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
			$this->queue->finish( $id, Preload_Queue::STATUS_COMPLETED, 0 );
			return;
		}

		$attempts = (int) $row->attempts + 1;

		$this->queue->finish( $id, $attempts >= self::MAX_ATTEMPTS ? Preload_Queue::STATUS_FAILED : Preload_Queue::STATUS_PENDING, $attempts );
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
				'batch'    => 0,
				'last_run' => 0,
				'cron_run' => 0,
				'error'    => '',
			)
		);
	}

	private function update_state( array $changes ): void {
		update_option( self::STATE_OPTION, array_merge( $this->get_state(), $changes ), false );
	}
}
