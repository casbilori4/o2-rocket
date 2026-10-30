<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Admin;

use O2Rocket\Cache\Cache_Engine;
use O2Rocket\Cache\Drop_In_Manager;
use O2Rocket\Cache\Page_Cache;

defined( 'ABSPATH' ) || exit;

final class Dashboard {

	const BENCHMARK_OPTION = 'o2_rocket_benchmark';
	const BENCHMARK_ACTION = 'o2_rocket_benchmark';
	const STATS_TRANSIENT  = 'o2_rocket_cache_stats';
	const RECOMMENDED_PHP  = '8.2';
	const BENCHMARK_RUNS   = 3;
	const MAX_SCAN_FILES   = 100000;

	// Cores de status fixas (validadas); rótulos em texto acompanham sempre.
	const COLOR_GOOD     = '#0CA30C';
	const COLOR_WARNING  = '#FAB219';
	const COLOR_CRITICAL = '#D03B3B';
	const COLOR_NEUTRAL  = '#94A3B8';

	private Page_Cache $page_cache;

	private Drop_In_Manager $drop_in;

	public function __construct( Page_Cache $page_cache, Drop_In_Manager $drop_in ) {
		$this->page_cache = $page_cache;
		$this->drop_in    = $drop_in;
	}

	public function register_hooks(): void {
		add_action( 'o2_rocket_admin_tab_dashboard', array( $this, 'render' ) );
		add_action( 'wp_ajax_' . self::BENCHMARK_ACTION, array( $this, 'ajax_benchmark' ) );
		add_action( 'o2_rocket_after_purge_all', array( $this, 'flush_stats' ) );
	}

	public function render(): void {
		$checks    = $this->get_environment_checks();
		$benchmark = $this->get_benchmark();
		$score     = $this->get_score( $checks, $benchmark );
		$stats     = $this->get_cache_stats();
		?>
		<div class="o2r-dashboard">
			<div class="o2r-grid o2r-grid--2">
				<?php
				$this->render_score_card( $score );
				$this->render_benchmark_card( $benchmark );
				?>
			</div>

			<?php $this->render_kpis( $stats, $checks ); ?>

			<div class="o2r-grid o2r-grid--2">
				<?php
				$this->render_breakdown_card( $score );
				$this->render_health_card( $checks );
				?>
			</div>
		</div>
		<?php
	}

	public function ajax_benchmark(): void {
		check_ajax_referer( self::BENCHMARK_ACTION );

		if ( ! current_user_can( (string) apply_filters( 'o2_rocket_capability', 'manage_options' ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Permissão negada.', 'o2-rocket' ) ), 403 );
		}

		$result = $this->run_benchmark();

		update_option( self::BENCHMARK_OPTION, $result, false );

		if ( '' !== $result['error'] ) {
			wp_send_json_error( array( 'message' => $result['error'] ) );
		}

		wp_send_json_success( $result );
	}

	public function flush_stats(): void {
		delete_transient( self::STATS_TRANSIENT );
	}

	public function get_benchmark(): array {
		$stored = get_option( self::BENCHMARK_OPTION, array() );

		return wp_parse_args(
			is_array( $stored ) ? $stored : array(),
			array(
				'uncached_ms' => 0,
				'cached_ms'   => 0,
				'cache_hit'   => false,
				'measured_at' => 0,
				'error'       => '',
			)
		);
	}

	// Nota 0-100 a partir de itens verificáveis; cada item explica o que falta.
	public function get_score( array $checks, array $benchmark ): array {
		$cache_ready = $this->page_cache->is_enabled()
			&& Drop_In_Manager::STATE_OK === $this->drop_in->get_dropin_state()
			&& $this->drop_in->is_wp_cache_enabled();

		$measured  = $benchmark['measured_at'] > 0 && '' === $benchmark['error'] && $benchmark['cached_ms'] > 0;
		$cached_ms = (int) $benchmark['cached_ms'];

		if ( ! $measured ) {
			$response_points = 0;
		} elseif ( $cached_ms <= 100 ) {
			$response_points = 20;
		} elseif ( $cached_ms <= 200 ) {
			$response_points = 15;
		} elseif ( $cached_ms <= 400 ) {
			$response_points = 10;
		} elseif ( $cached_ms <= 800 ) {
			$response_points = 5;
		} else {
			$response_points = 0;
		}

		$php_points = 'success' === $checks['php']['status'] ? 10 : ( version_compare( PHP_VERSION, '8.0', '>=' ) ? 5 : 0 );

		$items = array(
			array(
				'label'  => __( 'Page Cache servindo HTML estático', 'o2-rocket' ),
				'hint'   => $cache_ready ? __( 'Drop-in e WP_CACHE ativos.', 'o2-rocket' ) : __( 'Ative o Page Cache na aba Page Cache.', 'o2-rocket' ),
				'earned' => $cache_ready ? 35 : 0,
				'max'    => 35,
			),
			array(
				'label'  => __( 'Tempo de resposta da página inicial', 'o2-rocket' ),
				'hint'   => $measured
					/* translators: %s: tempo em milissegundos. */
					? sprintf( __( '%s ms com cache (ideal: até 100 ms).', 'o2-rocket' ), number_format_i18n( $cached_ms ) )
					: __( 'Ainda não medido.', 'o2-rocket' ),
				'earned' => $response_points,
				'max'    => 20,
			),
			array(
				'label'  => __( 'OPcache', 'o2-rocket' ),
				'hint'   => 'success' === $checks['opcache']['status'] ? __( 'PHP compilado em memória.', 'o2-rocket' ) : __( 'Ative o OPcache no PHP do servidor.', 'o2-rocket' ),
				'earned' => 'success' === $checks['opcache']['status'] ? 10 : 0,
				'max'    => 10,
			),
			array(
				'label'  => __( 'Versão do PHP', 'o2-rocket' ),
				'hint'   => 10 === $php_points
					? __( 'Versão moderna.', 'o2-rocket' )
					/* translators: %s: versão recomendada do PHP. */
					: sprintf( __( 'Atualize para PHP %s ou superior.', 'o2-rocket' ), self::RECOMMENDED_PHP ),
				'earned' => $php_points,
				'max'    => 10,
			),
			array(
				'label'  => __( 'Cache de objetos persistente', 'o2-rocket' ),
				'hint'   => 'success' === $checks['object_cache']['status'] ? __( 'Consultas repetidas saem da memória.', 'o2-rocket' ) : __( 'Instale Redis ou Memcached.', 'o2-rocket' ),
				'earned' => 'success' === $checks['object_cache']['status'] ? 10 : 0,
				'max'    => 10,
			),
			array(
				'label'  => __( 'HTTPS', 'o2-rocket' ),
				'hint'   => 'success' === $checks['https']['status'] ? __( 'Conexão segura com HTTP/2.', 'o2-rocket' ) : __( 'Instale um certificado SSL.', 'o2-rocket' ),
				'earned' => 'success' === $checks['https']['status'] ? 10 : 0,
				'max'    => 10,
			),
			array(
				'label'  => __( 'Links permanentes amigáveis', 'o2-rocket' ),
				'hint'   => 'success' === $checks['permalinks']['status'] ? __( 'Estrutura compatível com o cache.', 'o2-rocket' ) : __( 'Defina uma estrutura em Configurações > Links permanentes.', 'o2-rocket' ),
				'earned' => 'success' === $checks['permalinks']['status'] ? 5 : 0,
				'max'    => 5,
			),
		);

		$items = (array) apply_filters( 'o2_rocket_score_items', $items, $checks, $benchmark );
		$max   = (int) array_sum( array_column( $items, 'max' ) );
		$value = $max > 0 ? (int) round( array_sum( array_column( $items, 'earned' ) ) * 100 / $max ) : 0;
		$value = max( 0, min( 100, $value ) );

		if ( $value >= 90 ) {
			$rating = array( __( 'Excelente', 'o2-rocket' ), 'success' );
		} elseif ( $value >= 50 ) {
			$rating = array( __( 'Pode melhorar', 'o2-rocket' ), 'warning' );
		} else {
			$rating = array( __( 'Crítico', 'o2-rocket' ), 'danger' );
		}

		return array(
			'value'  => $value,
			'rating' => $rating,
			'items'  => $items,
		);
	}

	public function get_cache_stats(): array {
		$stats = get_transient( self::STATS_TRANSIENT );

		if ( is_array( $stats ) ) {
			return $stats;
		}

		$stats     = array(
			'pages' => 0,
			'bytes' => 0,
		);
		$directory = $this->page_cache->get_site_cache_dir();

		if ( '' !== $directory && is_dir( $directory ) ) {
			try {
				$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ) );
				$scanned  = 0;

				foreach ( $iterator as $file ) {
					if ( ++$scanned > self::MAX_SCAN_FILES ) {
						break;
					}

					if ( ! $file->isFile() || ! Cache_Engine::is_cache_filename( $file->getFilename() ) ) {
						continue;
					}

					// Cópias .gz entram no tamanho, mas não contam como página.
					if ( '.html' === substr( $file->getFilename(), -5 ) ) {
						++$stats['pages'];
					}

					$stats['bytes'] += (int) $file->getSize();
				}
			} catch ( \UnexpectedValueException $exception ) {
				unset( $exception );
			}
		}

		set_transient( self::STATS_TRANSIENT, $stats, 5 * MINUTE_IN_SECONDS );

		return $stats;
	}

	public function get_environment_checks(): array {
		$php_ok       = version_compare( PHP_VERSION, self::RECOMMENDED_PHP, '>=' );
		$wp_cache     = $this->drop_in->is_wp_cache_enabled();
		$cache_exists = is_dir( O2_ROCKET_CACHE_PATH );
		$cache_ok     = $cache_exists && wp_is_writable( O2_ROCKET_CACHE_PATH );
		$permalinks   = '' !== (string) get_option( 'permalink_structure' );
		$https        = 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME );
		$object_cache = wp_using_ext_object_cache();
		$opcache      = extension_loaded( 'Zend OPcache' ) && filter_var( ini_get( 'opcache.enable' ), FILTER_VALIDATE_BOOLEAN );

		if ( $cache_ok ) {
			$cache_value = __( 'Gravável', 'o2-rocket' );
		} elseif ( $cache_exists ) {
			$cache_value = __( 'Sem permissão de escrita', 'o2-rocket' );
		} else {
			$cache_value = __( 'Não encontrado', 'o2-rocket' );
		}

		$checks = array(
			'php'          => array(
				'label'       => __( 'Versão do PHP', 'o2-rocket' ),
				'value'       => PHP_VERSION,
				'status'      => $php_ok ? 'success' : 'warning',
				'description' => $php_ok
					? __( 'Versão moderna, com bom desempenho de execução.', 'o2-rocket' )
					/* translators: %s: versão recomendada do PHP. */
					: sprintf( __( 'Recomendado PHP %s ou superior para melhor desempenho.', 'o2-rocket' ), self::RECOMMENDED_PHP ),
			),
			'wordpress'    => array(
				'label'       => __( 'Versão do WordPress', 'o2-rocket' ),
				'value'       => get_bloginfo( 'version' ),
				'status'      => 'info',
				'description' => __( 'Versão instalada do núcleo do WordPress.', 'o2-rocket' ),
			),
			'wp_cache'     => array(
				'label'       => __( 'Constante WP_CACHE', 'o2-rocket' ),
				'value'       => $wp_cache ? __( 'Ativa', 'o2-rocket' ) : __( 'Inativa', 'o2-rocket' ),
				'status'      => $wp_cache ? 'success' : 'warning',
				'description' => __( 'Necessária para servir o cache de página antes de o WordPress carregar.', 'o2-rocket' ),
			),
			'cache_dir'    => array(
				'label'       => __( 'Diretório de cache', 'o2-rocket' ),
				'value'       => $cache_value,
				'status'      => $cache_ok ? 'success' : 'danger',
				'description' => O2_ROCKET_CACHE_PATH,
			),
			'permalinks'   => array(
				'label'       => __( 'Links permanentes', 'o2-rocket' ),
				'value'       => $permalinks ? __( 'Amigáveis', 'o2-rocket' ) : __( 'Padrão', 'o2-rocket' ),
				'status'      => $permalinks ? 'success' : 'warning',
				'description' => __( 'O cache de página exige links permanentes amigáveis.', 'o2-rocket' ),
			),
			'https'        => array(
				'label'       => __( 'HTTPS', 'o2-rocket' ),
				'value'       => $https ? __( 'Ativo', 'o2-rocket' ) : __( 'Inativo', 'o2-rocket' ),
				'status'      => $https ? 'success' : 'warning',
				'description' => __( 'Habilita HTTP/2 e HTTP/3 e é fator de ranqueamento no Google.', 'o2-rocket' ),
			),
			'object_cache' => array(
				'label'       => __( 'Cache de objetos persistente', 'o2-rocket' ),
				'value'       => $object_cache ? __( 'Ativo', 'o2-rocket' ) : __( 'Não detectado', 'o2-rocket' ),
				'status'      => $object_cache ? 'success' : 'info',
				'description' => __( 'Redis ou Memcached reduzem consultas repetidas ao banco de dados.', 'o2-rocket' ),
			),
			'opcache'      => array(
				'label'       => __( 'OPcache', 'o2-rocket' ),
				'value'       => $opcache ? __( 'Ativo', 'o2-rocket' ) : __( 'Inativo', 'o2-rocket' ),
				'status'      => $opcache ? 'success' : 'warning',
				'description' => __( 'Mantém o código PHP compilado em memória entre requisições.', 'o2-rocket' ),
			),
		);

		$checks = (array) apply_filters( 'o2_rocket_environment_checks', $checks );

		foreach ( $checks as $key => $check ) {
			$checks[ $key ] = wp_parse_args(
				(array) $check,
				array(
					'label'       => '',
					'value'       => '',
					'status'      => 'info',
					'description' => '',
				)
			);
		}

		return $checks;
	}

	// Mediana de N requisições por loopback: sem cache (query string força bypass) e com cache.
	private function run_benchmark(): array {
		$url  = home_url( '/' );
		$args = array(
			'timeout'     => 20,
			'redirection' => 0,
			'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
			'headers'     => array( 'Cache-Control' => 'no-cache' ),
			'user-agent'  => 'O2Rocket-Benchmark/' . O2_ROCKET_VERSION,
		);

		$uncached = array();
		$cached   = array();
		$hits     = 0;

		for ( $i = 0; $i < self::BENCHMARK_RUNS; $i++ ) {
			$sample = $this->timed_request( add_query_arg( 'o2r-benchmark', wp_generate_password( 8, false ), $url ), $args );

			if ( is_wp_error( $sample ) ) {
				return $this->benchmark_error( $sample );
			}

			$uncached[] = $sample['ms'];
		}

		// Aquece: garante que a medição com cache não pegue a primeira geração.
		$this->timed_request( $url, $args );

		for ( $i = 0; $i < self::BENCHMARK_RUNS; $i++ ) {
			$sample = $this->timed_request( $url, $args );

			if ( is_wp_error( $sample ) ) {
				return $this->benchmark_error( $sample );
			}

			$cached[] = $sample['ms'];
			$hits    += 'HIT' === $sample['cache'] ? 1 : 0;
		}

		return array(
			'uncached_ms' => $this->median( $uncached ),
			'cached_ms'   => $this->median( $cached ),
			'cache_hit'   => $hits === self::BENCHMARK_RUNS,
			'measured_at' => time(),
			'error'       => '',
		);
	}

	private function timed_request( string $url, array $args ) {
		$start    = microtime( true );
		$response = wp_remote_get( $url, $args );
		$elapsed  = ( microtime( true ) - $start ) * 1000;

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			/* translators: %d: código HTTP. */
			return new \WP_Error( 'o2r_benchmark_status', sprintf( __( 'a página inicial respondeu com HTTP %d', 'o2-rocket' ), $code ) );
		}

		$header = wp_remote_retrieve_header( $response, 'x-o2-rocket-cache' );

		return array(
			'ms'    => $elapsed,
			'cache' => strtoupper( is_array( $header ) ? (string) reset( $header ) : (string) $header ),
		);
	}

	private function benchmark_error( \WP_Error $error ): array {
		return array(
			'uncached_ms' => 0,
			'cached_ms'   => 0,
			'cache_hit'   => false,
			'measured_at' => time(),
			/* translators: %s: mensagem de erro. */
			'error'       => sprintf( __( 'Não foi possível medir: %s.', 'o2-rocket' ), $error->get_error_message() ),
		);
	}

	private function median( array $values ): int {
		sort( $values );

		$count  = count( $values );
		$middle = intdiv( $count, 2 );

		if ( 0 === $count ) {
			return 0;
		}

		return (int) round( 0 === $count % 2 ? ( $values[ $middle - 1 ] + $values[ $middle ] ) / 2 : $values[ $middle ] );
	}

	private function render_score_card( array $score ): void {
		?>
		<section class="o2r-card o2r-score-card">
			<header class="o2r-card-header">
				<h3 class="o2r-card-title"><?php esc_html_e( 'Nota de performance', 'o2-rocket' ); ?></h3>
				<p class="o2r-card-description"><?php esc_html_e( 'Calculada a partir do Page Cache, do servidor e do tempo de resposta medido.', 'o2-rocket' ); ?></p>
			</header>
			<div class="o2r-card-body o2r-gauge">
				<?php $this->render_gauge( (int) $score['value'] ); ?>
				<div class="o2r-gauge-readout">
					<span class="o2r-gauge-value"><?php echo esc_html( (string) $score['value'] ); ?></span>
					<span class="o2r-gauge-max">/100</span>
				</div>
				<div class="o2r-gauge-rating">
					<?php echo Admin_UI::badge_html( $score['rating'][0], $score['rating'][1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado em badge_html(). ?>
				</div>
				<ul class="o2r-legend o2r-legend--center">
					<?php
					$this->render_legend_item( self::COLOR_CRITICAL, __( '0–49 Crítico', 'o2-rocket' ) );
					$this->render_legend_item( self::COLOR_WARNING, __( '50–89 Pode melhorar', 'o2-rocket' ) );
					$this->render_legend_item( self::COLOR_GOOD, __( '90–100 Excelente', 'o2-rocket' ) );
					?>
				</ul>
			</div>
		</section>
		<?php
	}

	// Velocímetro em SVG: faixas de status, trilho azul claro, arco azul até a nota e ponteiro.
	private function render_gauge( int $value ): void {
		$zones = array(
			array( 0, 49.5, self::COLOR_CRITICAL ),
			array( 50.5, 89.5, self::COLOR_WARNING ),
			array( 90.5, 100, self::COLOR_GOOD ),
		);

		$needle = $this->gauge_point( 74, $value );
		/* translators: %d: nota de 0 a 100. */
		$label = sprintf( __( 'Nota de performance: %d de 100', 'o2-rocket' ), $value );
		?>
		<svg class="o2r-gauge-svg" viewBox="0 0 240 142" role="img" aria-label="<?php echo esc_attr( $label ); ?>">
			<?php foreach ( $zones as $zone ) : ?>
				<path d="<?php echo esc_attr( $this->gauge_arc( 112, $zone[0], $zone[1] ) ); ?>" fill="none" stroke="<?php echo esc_attr( $zone[2] ); ?>" stroke-width="4"/>
			<?php endforeach; ?>

			<path d="<?php echo esc_attr( $this->gauge_arc( 94, 0, 100 ) ); ?>" fill="none" class="o2r-gauge-track" stroke-width="18"/>

			<?php if ( $value > 0 ) : ?>
				<path d="<?php echo esc_attr( $this->gauge_arc( 94, 0, $value ) ); ?>" fill="none" class="o2r-gauge-fill" stroke-width="18"/>
			<?php endif; ?>

			<?php for ( $tick = 10; $tick <= 90; $tick += 10 ) : ?>
				<?php
				$from = $this->gauge_point( 50 === $tick ? 62 : 66, $tick );
				$to   = $this->gauge_point( 72, $tick );
				?>
				<line x1="<?php echo esc_attr( $from[0] ); ?>" y1="<?php echo esc_attr( $from[1] ); ?>" x2="<?php echo esc_attr( $to[0] ); ?>" y2="<?php echo esc_attr( $to[1] ); ?>" class="o2r-gauge-tick"/>
			<?php endfor; ?>

			<?php $middle = $this->gauge_point( 52, 50 ); ?>
			<text x="<?php echo esc_attr( $middle[0] ); ?>" y="<?php echo esc_attr( (string) ( (float) $middle[1] + 4 ) ); ?>" text-anchor="middle" class="o2r-gauge-label">50</text>
			<text x="26" y="141" text-anchor="middle" class="o2r-gauge-label">0</text>
			<text x="214" y="141" text-anchor="middle" class="o2r-gauge-label">100</text>

			<line x1="120" y1="120" x2="<?php echo esc_attr( $needle[0] ); ?>" y2="<?php echo esc_attr( $needle[1] ); ?>" class="o2r-gauge-needle"/>
			<circle cx="120" cy="120" r="8" class="o2r-gauge-hub"/>
		</svg>
		<?php
	}

	private function gauge_point( float $radius, float $value ): array {
		$angle = M_PI * ( 1 - max( 0, min( 100, $value ) ) / 100 );

		return array(
			number_format( 120 + $radius * cos( $angle ), 2, '.', '' ),
			number_format( 120 - $radius * sin( $angle ), 2, '.', '' ),
		);
	}

	private function gauge_arc( float $radius, float $from, float $to ): string {
		$start = $this->gauge_point( $radius, $from );
		$end   = $this->gauge_point( $radius, $to );

		return sprintf( 'M %1$s %2$s A %3$s %3$s 0 0 1 %4$s %5$s', $start[0], $start[1], $radius, $end[0], $end[1] );
	}

	private function render_benchmark_card( array $benchmark ): void {
		$measured = $benchmark['measured_at'] > 0 && '' === $benchmark['error'] && $benchmark['uncached_ms'] > 0;
		$autorun  = 0 === (int) $benchmark['measured_at'];

		if ( $measured ) {
			/* translators: %s: tempo decorrido, ex.: "3 minutos". */
			$status = sprintf( __( 'Medido há %s · mediana de 3 requisições por cenário', 'o2-rocket' ), human_time_diff( (int) $benchmark['measured_at'] ) );
		} elseif ( '' !== $benchmark['error'] ) {
			$status = $benchmark['error'];
		} else {
			$status = __( 'Medindo o tempo de resposta…', 'o2-rocket' );
		}
		?>
		<section
			class="o2r-card o2r-benchmark-card"
			data-o2r-benchmark
			data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			data-action="<?php echo esc_attr( self::BENCHMARK_ACTION ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( self::BENCHMARK_ACTION ) ); ?>"
			data-autorun="<?php echo esc_attr( $autorun ? '1' : '0' ); ?>"
			data-label-running="<?php esc_attr_e( 'Medindo o tempo de resposta…', 'o2-rocket' ); ?>"
			data-label-error="<?php esc_attr_e( 'Não foi possível medir agora.', 'o2-rocket' ); ?>"
		>
			<header class="o2r-card-header o2r-card-header--split">
				<div>
					<h3 class="o2r-card-title"><?php esc_html_e( 'Tempo de resposta', 'o2-rocket' ); ?></h3>
					<p class="o2r-card-description"><?php esc_html_e( 'Página inicial carregada sem cache e com o O² Rocket.', 'o2-rocket' ); ?></p>
				</div>
				<button type="button" class="o2r-button" data-o2r-benchmark-run>
					<?php echo esc_html( $measured ? __( 'Medir novamente', 'o2-rocket' ) : __( 'Medir agora', 'o2-rocket' ) ); ?>
				</button>
			</header>
			<div class="o2r-card-body">
				<?php if ( $measured ) : ?>
					<?php $this->render_benchmark_chart( $benchmark ); ?>
				<?php else : ?>
					<div class="o2r-chart-empty">
						<p class="o2r-empty-title"><?php esc_html_e( 'Sem medição ainda', 'o2-rocket' ); ?></p>
						<p class="o2r-empty-text"><?php esc_html_e( 'A medição faz 7 requisições à página inicial e leva poucos segundos.', 'o2-rocket' ); ?></p>
					</div>
				<?php endif; ?>
				<p class="o2r-card-foot" data-o2r-benchmark-status><?php echo esc_html( $status ); ?></p>
			</div>
		</section>
		<?php
	}

	// Barras horizontais em escala linear: a diferença de tamanho é o dado.
	private function render_benchmark_chart( array $benchmark ): void {
		$uncached = max( 1, (int) $benchmark['uncached_ms'] );
		$cached   = max( 1, (int) $benchmark['cached_ms'] );
		$scale    = max( $uncached, $cached );
		$speedup  = $uncached / $cached;
		$rows     = array(
			array( __( 'Sem cache', 'o2-rocket' ), $uncached, 'o2r-bar--muted' ),
			array( __( 'Com O² Rocket', 'o2-rocket' ), $cached, '' ),
		);
		?>
		<div class="o2r-speedup">
			<?php if ( $benchmark['cache_hit'] && $speedup >= 1.1 ) : ?>
				<span class="o2r-speedup-value"><?php echo esc_html( number_format_i18n( $speedup, $speedup >= 10 ? 0 : 1 ) . '×' ); ?></span>
				<span class="o2r-speedup-label"><?php esc_html_e( 'mais rápido com cache', 'o2-rocket' ); ?></span>
			<?php else : ?>
				<span class="o2r-speedup-label"><?php esc_html_e( 'O cache não respondeu nesta medição. Confira se o Page Cache está ativo.', 'o2-rocket' ); ?></span>
			<?php endif; ?>
		</div>
		<div class="o2r-bars">
			<?php foreach ( $rows as $row ) : ?>
				<?php
				/* translators: %s: tempo em milissegundos. */
				$value = sprintf( __( '%s ms', 'o2-rocket' ), number_format_i18n( $row[1] ) );
				?>
				<div class="o2r-bar-row">
					<div class="o2r-bar-label"><?php echo esc_html( $row[0] ); ?></div>
					<div class="o2r-bar-plot">
						<span
							class="<?php echo esc_attr( trim( 'o2r-bar ' . $row[2] ) ); ?>"
							style="<?php echo esc_attr( '--o2r-ratio:' . number_format( $row[1] / $scale, 4, '.', '' ) ); ?>"
							title="<?php echo esc_attr( $row[0] . ': ' . $value ); ?>"
						></span>
						<span class="o2r-bar-value"><?php echo esc_html( $value ); ?></span>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<?php if ( $benchmark['cache_hit'] && $uncached > $cached ) : ?>
			<?php
			$saved_ms = $uncached - $cached;

			// 1.000 visitas × ms economizados ÷ 60.000 ms por minuto.
			$saved_minutes = $saved_ms / MINUTE_IN_SECONDS;
			?>
			<div class="o2r-mini-stats">
				<div class="o2r-mini-stat">
					<div class="o2r-mini-stat-label"><?php esc_html_e( 'Economia por visita', 'o2-rocket' ); ?></div>
					<div class="o2r-mini-stat-value">
						<?php
						/* translators: %s: tempo em milissegundos. */
						echo esc_html( sprintf( __( '%s ms', 'o2-rocket' ), number_format_i18n( $saved_ms ) ) );
						?>
					</div>
				</div>
				<div class="o2r-mini-stat">
					<div class="o2r-mini-stat-label"><?php esc_html_e( 'Servidor poupado a cada 1.000 visitas', 'o2-rocket' ); ?></div>
					<div class="o2r-mini-stat-value">
						<?php
						/* translators: %s: minutos. */
						echo esc_html( sprintf( __( '%s min', 'o2-rocket' ), number_format_i18n( $saved_minutes, 1 ) ) );
						?>
					</div>
				</div>
			</div>
		<?php endif; ?>
		<?php
	}

	private function render_kpis( array $stats, array $checks ): void {
		$approved = count( array_filter( array_column( $checks, 'status' ), static function ( $status ) {
			return 'success' === $status;
		} ) );

		$cache_state = $this->drop_in->get_dropin_state();
		$active      = $this->page_cache->is_enabled() && Drop_In_Manager::STATE_OK === $cache_state && $this->drop_in->is_wp_cache_enabled();

		$tiles = array(
			array(
				'label' => __( 'Páginas em cache', 'o2-rocket' ),
				'value' => number_format_i18n( (int) $stats['pages'] ),
				'meta'  => __( 'arquivos HTML prontos para servir', 'o2-rocket' ),
			),
			array(
				'label' => __( 'Tamanho do cache', 'o2-rocket' ),
				'value' => (int) $stats['bytes'] > 0 ? size_format( (int) $stats['bytes'], 1 ) : '0 KB',
				'meta'  => __( 'em wp-content/cache/o2-rocket', 'o2-rocket' ),
			),
			array(
				'label' => __( 'Page Cache', 'o2-rocket' ),
				'value' => $active ? __( 'Ativo', 'o2-rocket' ) : __( 'Inativo', 'o2-rocket' ),
				'meta'  => $active ? __( 'drop-in e WP_CACHE em ordem', 'o2-rocket' ) : __( 'ative na aba Page Cache', 'o2-rocket' ),
			),
			array(
				'label' => __( 'Verificações aprovadas', 'o2-rocket' ),
				'value' => $approved . '/' . count( $checks ),
				'meta'  => __( 'requisitos do servidor', 'o2-rocket' ),
			),
		);
		?>
		<div class="o2r-kpis">
			<?php foreach ( $tiles as $tile ) : ?>
				<div class="o2r-kpi">
					<div class="o2r-kpi-label"><?php echo esc_html( $tile['label'] ); ?></div>
					<div class="o2r-kpi-value"><?php echo esc_html( $tile['value'] ); ?></div>
					<div class="o2r-kpi-meta"><?php echo esc_html( $tile['meta'] ); ?></div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private function render_breakdown_card( array $score ): void {
		?>
		<section class="o2r-card">
			<header class="o2r-card-header">
				<h3 class="o2r-card-title"><?php esc_html_e( 'Composição da nota', 'o2-rocket' ); ?></h3>
				<p class="o2r-card-description"><?php esc_html_e( 'Pontos obtidos em cada item e o que fazer para subir.', 'o2-rocket' ); ?></p>
			</header>
			<div class="o2r-meters">
				<?php foreach ( $score['items'] as $item ) : ?>
					<?php
					$max    = max( 1, (int) $item['max'] );
					$earned = max( 0, min( $max, (int) $item['earned'] ) );
					?>
					<div class="o2r-meter-row">
						<div class="o2r-meter-head">
							<span class="o2r-meter-label"><?php echo esc_html( (string) $item['label'] ); ?></span>
							<span class="o2r-meter-points"><?php echo esc_html( $earned . '/' . $max ); ?></span>
						</div>
						<div
							class="o2r-meter"
							role="meter"
							aria-valuemin="0"
							aria-valuemax="<?php echo esc_attr( (string) $max ); ?>"
							aria-valuenow="<?php echo esc_attr( (string) $earned ); ?>"
							aria-label="<?php echo esc_attr( (string) $item['label'] ); ?>"
						>
							<span class="o2r-meter-fill" style="<?php echo esc_attr( '--o2r-ratio:' . number_format( $earned / $max, 4, '.', '' ) ); ?>"></span>
						</div>
						<div class="o2r-meter-hint"><?php echo esc_html( (string) $item['hint'] ); ?></div>
					</div>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	private function render_health_card( array $checks ): void {
		$groups = array(
			'success' => array( __( 'Aprovadas', 'o2-rocket' ), self::COLOR_GOOD, 0 ),
			'info'    => array( __( 'Informativas', 'o2-rocket' ), self::COLOR_NEUTRAL, 0 ),
			'warning' => array( __( 'Atenção', 'o2-rocket' ), self::COLOR_WARNING, 0 ),
			'danger'  => array( __( 'Críticas', 'o2-rocket' ), self::COLOR_CRITICAL, 0 ),
		);

		foreach ( $checks as $check ) {
			$status = isset( $groups[ $check['status'] ] ) ? $check['status'] : 'info';
			++$groups[ $status ][2];
		}

		$total = max( 1, count( $checks ) );
		?>
		<section class="o2r-card">
			<header class="o2r-card-header">
				<h3 class="o2r-card-title"><?php esc_html_e( 'Saúde do ambiente', 'o2-rocket' ); ?></h3>
				<p class="o2r-card-description"><?php esc_html_e( 'Requisitos e recursos do servidor que influenciam o desempenho.', 'o2-rocket' ); ?></p>
			</header>
			<div class="o2r-card-body o2r-health-summary">
				<div class="o2r-stack" role="img" aria-label="<?php esc_attr_e( 'Distribuição das verificações por status', 'o2-rocket' ); ?>">
					<?php foreach ( $groups as $group ) : ?>
						<?php if ( $group[2] > 0 ) : ?>
							<span
								class="o2r-stack-segment"
								style="<?php echo esc_attr( 'flex-grow:' . $group[2] . ';background:' . $group[1] ); ?>"
								title="<?php echo esc_attr( $group[0] . ': ' . $group[2] . '/' . $total ); ?>"
							></span>
						<?php endif; ?>
					<?php endforeach; ?>
				</div>
				<ul class="o2r-legend">
					<?php foreach ( $groups as $group ) : ?>
						<?php $this->render_legend_item( $group[1], $group[0], (string) $group[2] ); ?>
					<?php endforeach; ?>
				</ul>
			</div>
			<div class="o2r-list o2r-list--flush">
				<?php foreach ( $checks as $check ) : ?>
					<div class="o2r-list-row">
						<div class="o2r-list-text">
							<div class="o2r-list-title"><?php echo esc_html( $check['label'] ); ?></div>
							<div class="o2r-list-meta"><?php echo esc_html( $check['description'] ); ?></div>
						</div>
						<?php echo Admin_UI::badge_html( (string) $check['value'], (string) $check['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado em badge_html(). ?>
					</div>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	private function render_legend_item( string $color, string $label, string $count = '' ): void {
		?>
		<li class="o2r-legend-item">
			<span class="o2r-legend-key" style="<?php echo esc_attr( 'background:' . $color ); ?>"></span>
			<span><?php echo esc_html( $label ); ?></span>
			<?php if ( '' !== $count ) : ?>
				<strong><?php echo esc_html( $count ); ?></strong>
			<?php endif; ?>
		</li>
		<?php
	}
}
