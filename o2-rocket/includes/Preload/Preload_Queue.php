<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Preload;

defined( 'ABSPATH' ) || exit;

// Fila do pré-carregamento numa tabela própria por site: aguenta dezenas de milhares de URLs sem inchar wp_options.
// Menor prioridade sai primeiro (0 = home e a página pedida).
final class Preload_Queue {

	const DB_VERSION        = '2';
	const DB_VERSION_OPTION = 'o2_rocket_preload_db_version';

	const STATUS_PENDING   = 'pending';
	const STATUS_RUNNING   = 'in-progress';
	const STATUS_COMPLETED = 'completed';
	const STATUS_FAILED    = 'failed';

	const PRIORITY_NORMAL = 5;

	const INSERT_CHUNK = 100;

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'o2_rocket_preload';
	}

	public function is_installed(): bool {
		return self::DB_VERSION === get_option( self::DB_VERSION_OPTION );
	}

	public function install(): bool {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		// url_hash é a chave única: VARCHAR(2000) não cabe num índice único em todos os MySQL.
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				url varchar(2000) NOT NULL default '',
				url_hash char(32) NOT NULL default '',
				status varchar(20) NOT NULL default 'pending',
				attempts tinyint(3) unsigned NOT NULL default 0,
				priority tinyint(3) unsigned NOT NULL default 5,
				modified datetime NOT NULL default '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				UNIQUE KEY url_hash (url_hash),
				KEY status_modified (status,modified),
				KEY status_priority (status,priority)
			) {$collate};"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		if ( $exists ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
		}

		return $exists;
	}

	public function drop(): void {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

		delete_option( self::DB_VERSION_OPTION );
	}

	// Insere as novas e devolve as existentes ao status pedido (sem duplicar).
	// Pendente que já esperava fica no mesmo lugar e com a melhor das duas prioridades.
	public function add( array $urls, string $status = self::STATUS_PENDING, int $priority = self::PRIORITY_NORMAL ): int {
		global $wpdb;

		if ( ! $this->is_installed() ) {
			return 0;
		}

		$table    = self::table();
		$now      = current_time( 'mysql', true );
		$priority = max( 0, min( 255, $priority ) );
		$added    = 0;
		$map      = array();

		foreach ( $urls as $url ) {
			if ( is_string( $url ) && '' !== $url && strlen( $url ) <= 2000 ) {
				$map[ md5( $url ) ] = $url;
			}
		}

		foreach ( array_chunk( $map, self::INSERT_CHUNK, true ) as $chunk ) {
			$hashes       = array_keys( $chunk );
			$placeholders = implode( ', ', array_fill( 0, count( $hashes ), '%s' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$existing = (array) $wpdb->get_col( $wpdb->prepare( "SELECT url_hash FROM {$table} WHERE url_hash IN ({$placeholders})", $hashes ) );
			$existing = array_flip( $existing );

			if ( ! empty( $existing ) ) {
				$update       = array_keys( $existing );
				$placeholders = implode( ', ', array_fill( 0, count( $update ), '%s' ) );

				// Linha em andamento também volta: a página pode ter sido limpa depois da visita (finish() não a marca como
				// concluída). O status vai por último: no MySQL as atribuições seguintes já enxergariam o valor novo.
				if ( self::STATUS_PENDING === $status ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET priority = CASE WHEN status = %s AND priority < %d THEN priority ELSE %d END, modified = CASE WHEN status = %s THEN modified ELSE %s END, attempts = CASE WHEN status = %s THEN attempts ELSE 0 END, status = %s WHERE url_hash IN ({$placeholders})", array_merge( array( self::STATUS_PENDING, $priority, $priority, self::STATUS_PENDING, $now, self::STATUS_PENDING, self::STATUS_PENDING ), $update ) ) );
				} else {
					// Em andamento continua com quem a visita; já concluída fica com a data antiga (o progresso do lote
					// atual conta só o que mudou nele).
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET attempts = 0, modified = %s, status = %s WHERE status <> %s AND status <> %s AND url_hash IN ({$placeholders})", array_merge( array( $now, $status, self::STATUS_RUNNING, $status ), $update ) ) );
				}
			}

			foreach ( $chunk as $hash => $url ) {
				if ( isset( $existing[ $hash ] ) ) {
					continue;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$inserted = $wpdb->insert(
					$table,
					array(
						'url'      => $url,
						'url_hash' => $hash,
						'status'   => $status,
						'attempts' => 0,
						'priority' => $priority,
						'modified' => $now,
					),
					array( '%s', '%s', '%s', '%d', '%d', '%s' )
				);

				$added += $inserted ? 1 : 0;
			}
		}

		return $added;
	}

	// Na ordem da fila: prioridade e depois chegada. $max_priority limita às urgentes.
	public function get_by_status( string $status, int $limit, int $max_priority = 255 ): array {
		global $wpdb;

		if ( ! $this->is_installed() ) {
			return array();
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, url, attempts, priority FROM {$table} WHERE status = %s AND priority <= %d ORDER BY priority ASC, modified ASC, id ASC LIMIT %d", $status, $max_priority, max( 1, $limit ) ) );
	}

	// Linhas "em andamento" há mais de N segundos (requisição que não voltou).
	public function get_stuck( int $seconds, int $limit ): array {
		global $wpdb;

		if ( ! $this->is_installed() ) {
			return array();
		}

		$table      = self::table();
		$limit_date = gmdate( 'Y-m-d H:i:s', time() - $seconds );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, url, attempts FROM {$table} WHERE status = %s AND modified < %s LIMIT %d", self::STATUS_RUNNING, $limit_date, max( 1, $limit ) ) );
	}

	public function set_status( int $id, string $status, ?int $attempts = null ): void {
		global $wpdb;

		$data   = array(
			'status'   => $status,
			'modified' => current_time( 'mysql', true ),
		);
		$format = array( '%s', '%s' );

		if ( null !== $attempts ) {
			$data['attempts'] = max( 0, min( 255, $attempts ) );
			$format[]         = '%d';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( self::table(), $data, array( 'id' => $id ), $format, array( '%d' ) );
	}

	// Fim do processamento: só vale se ninguém devolveu a linha para a fila enquanto a página era visitada.
	public function finish( int $id, string $status, int $attempts ): void {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET attempts = %d, modified = %s, status = %s WHERE id = %d AND status = %s", max( 0, min( 255, $attempts ) ), current_time( 'mysql', true ), $status, $id, self::STATUS_RUNNING ) );
	}

	public function set_status_by_url( string $url, string $status ): void {
		global $wpdb;

		if ( ! $this->is_installed() ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			self::table(),
			array(
				'status'   => $status,
				'attempts' => 0,
				'modified' => current_time( 'mysql', true ),
			),
			array( 'url_hash' => md5( $url ) ),
			array( '%s', '%d', '%s' ),
			array( '%s' )
		);
	}

	public function delete( int $id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
	}

	// Recomeço completo: tudo volta a pendente (inclusive a que está sendo visitada, ver finish()).
	public function reset_all(): void {
		global $wpdb;

		if ( ! $this->is_installed() ) {
			return;
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, attempts = 0, priority = %d, modified = %s", self::STATUS_PENDING, self::PRIORITY_NORMAL, current_time( 'mysql', true ) ) );
	}

	public function clear(): void {
		global $wpdb;

		if ( ! $this->is_installed() ) {
			return;
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$table}" );
	}

	public function count_by_status(): array {
		return $this->count( '' );
	}

	// Andamento do lote atual: o que ainda espera mais o que foi concluído (ou falhou) desde $since (GMT).
	public function count_since( string $since ): array {
		return $this->count( $since );
	}

	private function count( string $since ): array {
		global $wpdb;

		$counts = array(
			self::STATUS_PENDING   => 0,
			self::STATUS_RUNNING   => 0,
			self::STATUS_COMPLETED => 0,
			self::STATUS_FAILED    => 0,
		);

		if ( ! $this->is_installed() ) {
			return $counts;
		}

		$table = self::table();
		$where = '' === $since ? '' : $wpdb->prepare( ' WHERE status = %s OR status = %s OR modified >= %s', self::STATUS_PENDING, self::STATUS_RUNNING, $since );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table}{$where} GROUP BY status" ) as $row ) {
			if ( isset( $counts[ $row->status ] ) ) {
				$counts[ $row->status ] = (int) $row->total;
			}
		}

		return $counts;
	}

	public function has_pending(): bool {
		return ! empty( $this->get_by_status( self::STATUS_PENDING, 1 ) );
	}

	public function has_running(): bool {
		return ! empty( $this->get_by_status( self::STATUS_RUNNING, 1 ) );
	}
}
