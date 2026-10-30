<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Media;

defined( 'ABSPATH' ) || exit;

final class Bulk_Optimizer {

	const AJAX_ACTION = 'o2_rocket_bulk_optimize';
	const BATCH_SIZE  = 2;
	const MAX_BATCH   = 3;
	const TIME_LIMIT  = 300;

	private Image_Optimizer $optimizer;

	public function __construct( Image_Optimizer $optimizer ) {
		$this->optimizer = $optimizer;
	}

	public function register_hooks(): void {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_optimize' ) );
	}

	// Processa um lote curto por requisição; o JS repete até "remaining" chegar a zero.
	public function ajax_optimize(): void {
		check_ajax_referer( self::AJAX_ACTION );

		if ( ! current_user_can( (string) apply_filters( 'o2_rocket_capability', 'manage_options' ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Permissão negada.', 'o2-rocket' ) ), 403 );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( self::TIME_LIMIT );
		}

		$batch   = max( 1, min( self::MAX_BATCH, (int) apply_filters( 'o2_rocket_bulk_batch_size', self::BATCH_SIZE ) ) );
		$results = array();

		foreach ( self::get_pending_ids( $batch ) as $attachment_id ) {
			$result    = $this->optimizer->optimize( $attachment_id );
			$results[] = array(
				'id'     => $result['id'],
				'status' => $result['status'],
				'saved'  => max( 0, $result['original'] - $result['optimized'] ),
				'error'  => $result['error'],
			);
		}

		$remaining = self::count_pending();

		// Fila concluída: o HTML em cache precisa passar a apontar para os .webp.
		if ( 0 === $remaining && ! empty( $results ) ) {
			do_action( 'o2_rocket_purge_cache' );
		}

		wp_send_json_success(
			array(
				'processed' => count( $results ),
				'results'   => $results,
				'remaining' => $remaining,
				'stats'     => self::get_optimization_stats(),
			)
		);
	}

	// Anexos JPEG/PNG ainda sem status de otimização (erros não voltam à fila sozinhos).
	public static function get_pending_ids( int $limit ): array {
		global $wpdb;

		$sql = self::pending_query( 'p.ID' ) . ' ORDER BY p.ID DESC LIMIT %d';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- consulta montada só com placeholders.
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, array_merge( self::pending_args(), array( max( 1, $limit ) ) ) ) );

		return array_map( 'intval', (array) $ids );
	}

	public static function count_pending(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- consulta montada só com placeholders.
		return (int) $wpdb->get_var( $wpdb->prepare( self::pending_query( 'COUNT(*)' ), self::pending_args() ) );
	}

	// Totais da biblioteca: imagens, otimizadas, com erro, tamanhos em MB e economia percentual.
	public static function get_optimization_stats(): array {
		global $wpdb;

		$mime_placeholders = implode( ', ', array_fill( 0, count( Image_Optimizer::MIME_TYPES ), '%s' ) );

		$sql = "SELECT
				COUNT(DISTINCT p.ID) AS total,
				SUM(CASE WHEN s.meta_value IN (%s, %s) THEN 1 ELSE 0 END) AS optimized,
				SUM(CASE WHEN s.meta_value = %s THEN 1 ELSE 0 END) AS errors,
				SUM(CASE WHEN s.meta_value IN (%s, %s) THEN CAST(o.meta_value AS UNSIGNED) ELSE 0 END) AS original_bytes,
				SUM(CASE WHEN s.meta_value IN (%s, %s) THEN CAST(z.meta_value AS UNSIGNED) ELSE 0 END) AS optimized_bytes
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
			LEFT JOIN {$wpdb->postmeta} o ON o.post_id = p.ID AND o.meta_key = %s
			LEFT JOIN {$wpdb->postmeta} z ON z.post_id = p.ID AND z.meta_key = %s
			WHERE p.post_type = 'attachment' AND p.post_mime_type IN ({$mime_placeholders})";

		$done = array( Image_Optimizer::STATUS_OPTIMIZED, Image_Optimizer::STATUS_ALREADY_OPTIMIZED );
		$args = array_merge(
			$done,
			array( Image_Optimizer::STATUS_ERROR ),
			$done,
			$done,
			array( Image_Optimizer::META_STATUS, Image_Optimizer::META_ORIGINAL, Image_Optimizer::META_OPTIMIZED ),
			Image_Optimizer::MIME_TYPES
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- consulta montada só com placeholders.
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $args ), ARRAY_A );

		$total           = (int) ( $row['total'] ?? 0 );
		$optimized       = (int) ( $row['optimized'] ?? 0 );
		$errors          = (int) ( $row['errors'] ?? 0 );
		$original_bytes  = (int) ( $row['original_bytes'] ?? 0 );
		$optimized_bytes = (int) ( $row['optimized_bytes'] ?? 0 );
		$saved_bytes     = max( 0, $original_bytes - $optimized_bytes );

		return array(
			'total'             => $total,
			'optimized'         => $optimized,
			'errors'            => $errors,
			'pending'           => max( 0, $total - $optimized - $errors ),
			'original_bytes'    => $original_bytes,
			'optimized_bytes'   => $optimized_bytes,
			'original_mb'       => round( $original_bytes / MB_IN_BYTES, 2 ),
			'optimized_mb'      => round( $optimized_bytes / MB_IN_BYTES, 2 ),
			'saved_percent'     => $original_bytes > 0 ? round( $saved_bytes * 100 / $original_bytes, 1 ) : 0.0,
			'optimized_percent' => $total > 0 ? round( $optimized * 100 / $total, 1 ) : 0.0,
			'processed_percent' => $total > 0 ? round( ( $optimized + $errors ) * 100 / $total, 1 ) : 0.0,
		);
	}

	private static function pending_query( string $select ): string {
		global $wpdb;

		$mime_placeholders = implode( ', ', array_fill( 0, count( Image_Optimizer::MIME_TYPES ), '%s' ) );

		return "SELECT {$select} FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
			WHERE p.post_type = 'attachment' AND p.post_mime_type IN ({$mime_placeholders}) AND s.meta_id IS NULL";
	}

	private static function pending_args(): array {
		return array_merge( array( Image_Optimizer::META_STATUS ), Image_Optimizer::MIME_TYPES );
	}
}
