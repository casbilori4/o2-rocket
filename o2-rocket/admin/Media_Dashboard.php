<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Admin;

use O2Rocket\Media\Bulk_Optimizer;
use O2Rocket\Media\Image_Optimizer;

defined( 'ABSPATH' ) || exit;

final class Media_Dashboard {

	const TAB = 'media';

	// Cores do gráfico de rosca (validadas: separação CVD 16,2 e contraste >= 3:1); sempre acompanhadas de rótulo.
	const COLOR_PENDING   = '#1E293B';
	const COLOR_OPTIMIZED = '#1188CC';
	const COLOR_ERROR     = '#D6246E';

	public function register_hooks(): void {
		add_filter( 'o2_rocket_admin_tabs', array( $this, 'filter_tabs' ) );
	}

	// O callback da aba renderiza antes do formulário de configurações.
	public function filter_tabs( $tabs ) {
		if ( is_array( $tabs ) && isset( $tabs[ self::TAB ] ) && is_array( $tabs[ self::TAB ] ) ) {
			$tabs[ self::TAB ]['callback'] = array( $this, 'render' );
		}

		return $tabs;
	}

	public function render(): void {
		$stats = Bulk_Optimizer::get_optimization_stats();
		?>
		<div class="o2r-media-dashboard">
			<?php
			$this->render_overview( $stats );
			$this->render_bulk_action( $stats );
			?>
		</div>
		<?php
	}

	private function render_overview( array $stats ): void {
		$original_ratio  = $stats['original_bytes'] > 0 ? 1 : 0;
		$optimized_ratio = $stats['original_bytes'] > 0 ? $stats['optimized_bytes'] / $stats['original_bytes'] : 0;
		?>
		<section class="o2r-card o2r-dark-card">
			<header class="o2r-dark-header">
				<h3 class="o2r-dark-title"><?php esc_html_e( 'Otimização em massa', 'o2-rocket' ); ?></h3>
				<span class="o2r-dark-meta">
					<?php
					$quality = ( new Image_Optimizer() )->get_quality();
					/* translators: %d: qualidade do WebP para JPEG. */
					echo esc_html( $quality < 100 ? sprintf( __( 'WebP · JPEG com qualidade %d · PNG sem perda', 'o2-rocket' ), $quality ) : __( 'WebP sem perda · qualidade 100%', 'o2-rocket' ) );
					?>
				</span>
			</header>

			<div class="o2r-overview">
				<div class="o2r-overview-data">
					<div class="o2r-overview-figure">
						<span class="o2r-overview-number"><?php echo esc_html( number_format_i18n( $stats['optimized'] ) ); ?></span>
						<span class="o2r-overview-caption"><?php esc_html_e( 'imagens originais otimizadas', 'o2-rocket' ); ?></span>
					</div>

					<div class="o2r-size-bars">
						<?php
						$this->render_size_bar( __( 'Tam. original', 'o2-rocket' ), $stats['original_mb'], $original_ratio, 'original' );
						$this->render_size_bar( __( 'Tam. otimizado', 'o2-rocket' ), $stats['optimized_mb'], $optimized_ratio, 'optimized' );
						?>
					</div>

					<div class="o2r-overview-figure">
						<span class="o2r-overview-number o2r-overview-number--accent"><?php echo esc_html( $this->format_percent( $stats['saved_percent'] ) ); ?></span>
						<span class="o2r-overview-caption"><?php esc_html_e( 'espaço economizado', 'o2-rocket' ); ?></span>
					</div>
				</div>

				<div class="o2r-overview-chart">
					<?php $this->render_donut( $stats ); ?>
					<ul class="o2r-bubble-legend">
						<?php
						$this->render_bubble( self::COLOR_PENDING, __( 'Não otimizada', 'o2-rocket' ), $stats['pending'] );
						$this->render_bubble( self::COLOR_OPTIMIZED, __( 'Otimizada', 'o2-rocket' ), $stats['optimized'] );
						$this->render_bubble( self::COLOR_ERROR, __( 'Erro', 'o2-rocket' ), $stats['errors'] );
						?>
					</ul>
				</div>
			</div>
		</section>
		<?php
	}

	private function render_size_bar( string $label, float $megabytes, float $ratio, string $variant ): void {
		/* translators: %s: tamanho em megabytes. */
		$value = sprintf( __( '%s MB', 'o2-rocket' ), number_format_i18n( $megabytes, 2 ) );
		?>
		<div class="o2r-size-bar-row">
			<span class="o2r-size-bar-label"><?php echo esc_html( $label ); ?></span>
			<span class="o2r-size-bar-track" role="img" aria-label="<?php echo esc_attr( $label . ': ' . $value ); ?>">
				<span class="<?php echo esc_attr( 'o2r-size-bar-fill o2r-size-bar-fill--' . $variant ); ?>" style="<?php echo esc_attr( '--o2r-ratio:' . number_format( max( 0, min( 1, $ratio ) ), 4, '.', '' ) ); ?>"></span>
			</span>
			<span class="o2r-size-bar-value"><?php echo esc_html( $value ); ?></span>
		</div>
		<?php
	}

	// Rosca em SVG puro: circunferência 100 (r = 15,9155), segmentos por stroke-dasharray, início às 12h.
	private function render_donut( array $stats ): void {
		$total    = max( 0, (int) $stats['total'] );
		$segments = array(
			array( (int) $stats['optimized'], self::COLOR_OPTIMIZED ),
			array( (int) $stats['errors'], self::COLOR_ERROR ),
			array( (int) $stats['pending'], self::COLOR_PENDING ),
		);
		$visible  = count( array_filter( array_column( $segments, 0 ) ) );
		$gap      = $visible > 1 ? 0.8 : 0;
		$offset   = 0.0;

		/* translators: 1: percentual otimizado, 2: total de imagens. */
		$label = sprintf( __( '%1$s das %2$s imagens otimizadas', 'o2-rocket' ), $this->format_percent( $stats['optimized_percent'] ), number_format_i18n( $total ) );
		?>
		<div class="o2r-donut">
			<svg class="o2r-donut-svg" viewBox="0 0 42 42" role="img" aria-label="<?php echo esc_attr( $label ); ?>">
				<circle class="o2r-donut-track" cx="21" cy="21" r="15.9155" fill="none" stroke-width="6"/>
				<?php foreach ( $segments as $segment ) : ?>
					<?php
					if ( $total < 1 || $segment[0] < 1 ) {
						continue;
					}

					$length = $segment[0] * 100 / $total;
					$drawn  = max( 0.01, $length - $gap );
					?>
					<circle
						cx="21"
						cy="21"
						r="15.9155"
						fill="none"
						stroke="<?php echo esc_attr( $segment[1] ); ?>"
						stroke-width="6"
						stroke-dasharray="<?php echo esc_attr( number_format( $drawn, 3, '.', '' ) . ' ' . number_format( 100 - $drawn, 3, '.', '' ) ); ?>"
						stroke-dashoffset="<?php echo esc_attr( number_format( 25 - $offset, 3, '.', '' ) ); ?>"
					/>
					<?php $offset += $length; ?>
				<?php endforeach; ?>
			</svg>
			<div class="o2r-donut-center">
				<span class="o2r-donut-value"><?php echo esc_html( $this->format_percent( $stats['optimized_percent'] ) ); ?></span>
				<span class="o2r-donut-caption"><?php esc_html_e( 'otimizadas', 'o2-rocket' ); ?></span>
			</div>
		</div>
		<?php
	}

	private function render_bubble( string $color, string $label, int $count ): void {
		?>
		<li class="o2r-bubble-item">
			<span class="o2r-bubble" style="<?php echo esc_attr( 'background:' . $color ); ?>"></span>
			<span class="o2r-bubble-label"><?php echo esc_html( $label ); ?></span>
			<strong class="o2r-bubble-count"><?php echo esc_html( number_format_i18n( $count ) ); ?></strong>
		</li>
		<?php
	}

	private function render_bulk_action( array $stats ): void {
		$pending   = (int) $stats['pending'];
		$supported = Image_Optimizer::server_supports_lossless() || Image_Optimizer::server_supports_lossy();

		if ( ! $supported ) {
			$status = __( 'Este servidor não gera WebP: o PHP precisa ter o GD ou o Imagick com suporte a WebP.', 'o2-rocket' );
		} elseif ( $pending > 0 ) {
			/* translators: %s: quantidade de imagens. */
			$status = sprintf( _n( '%s imagem aguardando otimização.', '%s imagens aguardando otimização.', $pending, 'o2-rocket' ), number_format_i18n( $pending ) );
		} else {
			$status = __( 'Toda a biblioteca já foi processada.', 'o2-rocket' );
		}

		if ( $stats['errors'] > 0 ) {
			/* translators: %s: quantidade de imagens com erro. */
			$status .= ' ' . sprintf( _n( '%s imagem com erro.', '%s imagens com erro.', (int) $stats['errors'], 'o2-rocket' ), number_format_i18n( (int) $stats['errors'] ) );
		}

		/* translators: %s: quantidade de imagens. */
		$button = $pending > 0 ? sprintf( _n( 'Otimizar %s imagem', 'Otimizar %s imagens', $pending, 'o2-rocket' ), number_format_i18n( $pending ) ) : __( 'Biblioteca otimizada', 'o2-rocket' );
		?>
		<section
			class="o2r-card o2r-dark-card"
			data-o2r-bulk
			data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			data-action="<?php echo esc_attr( Bulk_Optimizer::AJAX_ACTION ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( Bulk_Optimizer::AJAX_ACTION ) ); ?>"
			data-total="<?php echo esc_attr( (string) (int) $stats['total'] ); ?>"
			data-processed="<?php echo esc_attr( (string) ( (int) $stats['optimized'] + (int) $stats['errors'] ) ); ?>"
			data-label-running="<?php esc_attr_e( 'Otimizando…', 'o2-rocket' ); ?>"
			data-label-progress="<?php /* translators: 1: imagens processadas, 2: total de imagens. */ esc_attr_e( '%1$s de %2$s imagens processadas', 'o2-rocket' ); ?>"
			data-label-done="<?php esc_attr_e( 'Concluído. Atualizando os números…', 'o2-rocket' ); ?>"
			data-label-error="<?php esc_attr_e( 'A otimização foi interrompida. Tente novamente.', 'o2-rocket' ); ?>"
		>
			<header class="o2r-dark-header">
				<h3 class="o2r-dark-title"><?php esc_html_e( 'Otimize sua biblioteca', 'o2-rocket' ); ?></h3>
			</header>
			<div class="o2r-card-body">
				<p class="o2r-muted o2r-bulk-intro">
					<?php esc_html_e( 'Cada imagem e todos os seus tamanhos ganham uma versão WebP: JPEGs com a qualidade escolhida em Mídia (visualmente igual, 25 a 35% menor) e PNGs sem perda. Quando o WebP não fica menor que o original, ele é descartado e o original continua sendo usado. Os originais nunca são alterados.', 'o2-rocket' ); ?>
				</p>

				<div class="o2r-bulk-progress">
					<div class="o2r-bulk-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) round( $stats['processed_percent'] ) ); ?>" aria-label="<?php esc_attr_e( 'Progresso da otimização da biblioteca', 'o2-rocket' ); ?>">
						<span class="o2r-bulk-fill" data-o2r-bulk-fill style="<?php echo esc_attr( 'width:' . number_format( (float) $stats['processed_percent'], 1, '.', '' ) . '%' ); ?>"></span>
					</div>
					<span class="o2r-bulk-percent" data-o2r-bulk-percent><?php echo esc_html( $this->format_percent( $stats['processed_percent'] ) ); ?></span>
				</div>

				<div class="o2r-bulk-footer">
					<p class="o2r-bulk-status" data-o2r-bulk-status><?php echo esc_html( $status ); ?></p>
					<button type="button" class="o2r-button o2r-button--primary" data-o2r-bulk-run <?php disabled( ! $supported || $pending < 1 ); ?>>
						<?php echo esc_html( $button ); ?>
					</button>
				</div>
			</div>
		</section>
		<?php
	}

	private function format_percent( $value ): string {
		$value = (float) $value;

		return number_format_i18n( $value, $value > 0 && $value < 10 && floor( $value ) !== $value ? 1 : 0 ) . '%';
	}
}
