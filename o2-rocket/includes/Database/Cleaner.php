<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Database;

defined( 'ABSPATH' ) || exit;

// Limpeza do banco: revisões, rascunhos automáticos, lixeira, spam, transients, metadados órfãos e tabelas.
// Trabalha em lotes com orçamento de tempo (sites grandes continuam na próxima chamada/execução agendada).
final class Cleaner {

	const SETTING_REVISIONS          = 'database_revisions';
	const SETTING_REVISIONS_KEEP     = 'database_revisions_keep';
	const SETTING_AUTO_DRAFTS        = 'database_auto_drafts';
	const SETTING_TRASHED_POSTS      = 'database_trashed_posts';
	const SETTING_SPAM_COMMENTS      = 'database_spam_comments';
	const SETTING_TRASHED_COMMENTS   = 'database_trashed_comments';
	const SETTING_EXPIRED_TRANSIENTS = 'database_expired_transients';
	const SETTING_ALL_TRANSIENTS     = 'database_all_transients';
	const SETTING_ORPHAN_META        = 'database_orphan_meta';
	const SETTING_OPTIMIZE_TABLES    = 'database_optimize_tables';
	const SETTING_SCHEDULE           = 'database_schedule';
	const SETTING_FREQUENCY          = 'database_frequency';

	const CRON_HOOK     = 'o2_rocket_database_cleanup';
	const CONTINUE_HOOK = 'o2_rocket_database_cleanup_continue';
	const AJAX_ACTION   = 'o2_rocket_database_cleanup';
	const LAST_OPTION   = 'o2_rocket_database_last';
	const BATCH         = 200;
	const TIME_BUDGET   = 20;

	const FREQUENCIES = array( 'daily', 'weekly', 'monthly' );

	// Ordem de execução: o que gera órfãos (posts, comentários) antes da limpeza de órfãos e da otimização.
	const ITEMS = array(
		self::SETTING_REVISIONS,
		self::SETTING_AUTO_DRAFTS,
		self::SETTING_TRASHED_POSTS,
		self::SETTING_SPAM_COMMENTS,
		self::SETTING_TRASHED_COMMENTS,
		self::SETTING_EXPIRED_TRANSIENTS,
		self::SETTING_ALL_TRANSIENTS,
		self::SETTING_ORPHAN_META,
		self::SETTING_OPTIMIZE_TABLES,
	);

	public function register_hooks(): void {
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );
		add_action( 'init', array( $this, 'schedule' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled' ) );
		add_action( self::CONTINUE_HOOK, array( $this, 'run_scheduled' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_cleanup' ) );
		add_action( 'update_option_' . O2_ROCKET_OPTION, array( $this, 'reschedule' ) );
	}

	public function get_settings(): array {
		$stored   = get_option( O2_ROCKET_OPTION, array() );
		$defaults = array_fill_keys( self::ITEMS, false );

		$defaults[ self::SETTING_REVISIONS_KEEP ] = 0;
		$defaults[ self::SETTING_SCHEDULE ]       = false;
		$defaults[ self::SETTING_FREQUENCY ]      = 'weekly';

		return wp_parse_args( is_array( $stored ) ? $stored : array(), $defaults );
	}

	public function get_enabled_items(): array {
		$settings = $this->get_settings();

		return array_values(
			array_filter(
				self::ITEMS,
				static function ( $item ) use ( $settings ) {
					return rest_sanitize_boolean( $settings[ $item ] );
				}
			)
		);
	}

	public function add_cron_schedules( $schedules ) {
		$schedules = is_array( $schedules ) ? $schedules : array();

		if ( ! isset( $schedules['monthly'] ) ) {
			$schedules['monthly'] = array(
				'interval' => 30 * DAY_IN_SECONDS,
				'display'  => __( 'Uma vez por mês', 'o2-rocket' ),
			);
		}

		return $schedules;
	}

	public function schedule(): void {
		$settings  = $this->get_settings();
		$enabled   = rest_sanitize_boolean( $settings[ self::SETTING_SCHEDULE ] ) && ! empty( $this->get_enabled_items() );
		$frequency = in_array( $settings[ self::SETTING_FREQUENCY ], self::FREQUENCIES, true ) ? $settings[ self::SETTING_FREQUENCY ] : 'weekly';
		$event     = wp_get_scheduled_event( self::CRON_HOOK );

		if ( ! $enabled ) {
			if ( $event ) {
				wp_clear_scheduled_hook( self::CRON_HOOK );
			}

			return;
		}

		if ( $event && $event->schedule !== $frequency ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			$event = false;
		}

		// Madrugada (horário do site) da próxima ocorrência: menos visitas competindo com a limpeza.
		if ( ! $event ) {
			$next = strtotime( 'tomorrow 03:15', current_time( 'timestamp' ) ) - (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
			wp_schedule_event( $next > time() ? $next : time() + HOUR_IN_SECONDS, $frequency, self::CRON_HOOK );
		}
	}

	public function reschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public function run_scheduled(): void {
		$result = $this->run( $this->get_enabled_items(), self::TIME_BUDGET );

		// Sobrou trabalho: continua em um minuto, sem esperar a próxima semana.
		if ( ! $result['done'] && ! wp_next_scheduled( self::CONTINUE_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CONTINUE_HOOK );
		}
	}

	public function ajax_cleanup(): void {
		check_ajax_referer( self::AJAX_ACTION );

		if ( ! current_user_can( (string) apply_filters( 'o2_rocket_capability', 'manage_options' ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Permissão negada.', 'o2-rocket' ) ), 403 );
		}

		$items = $this->get_enabled_items();

		if ( empty( $items ) ) {
			wp_send_json_error( array( 'message' => __( 'Marque ao menos um item e salve as alterações.', 'o2-rocket' ) ) );
		}

		$result = $this->run( $items, 15 );

		wp_send_json_success(
			array(
				'done'    => $result['done'],
				'deleted' => array_sum( $result['counts'] ),
				'counts'  => $this->count_all(),
			)
		);
	}

	// Executa os itens até acabar o tempo. done = false quando ainda há o que apagar.
	public function run( array $items, int $budget ): array {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( $budget + 60 );
		}

		$deadline = microtime( true ) + $budget;
		$items    = array_values( array_intersect( self::ITEMS, $items ) );
		$counts   = array();
		$done     = true;

		foreach ( $items as $index => $item ) {
			$counts[ $item ] = 0;

			do {
				$deleted          = $this->clean( $item );
				$counts[ $item ] += $deleted;
			} while ( $deleted >= self::BATCH && microtime( true ) < $deadline && self::SETTING_OPTIMIZE_TABLES !== $item );

			// Tempo esgotado no meio de um item ou antes dos próximos: a próxima chamada continua.
			if ( ( $deleted >= self::BATCH && self::SETTING_OPTIMIZE_TABLES !== $item ) || ( microtime( true ) >= $deadline && $index < count( $items ) - 1 ) ) {
				$done = false;
				break;
			}
		}

		update_option(
			self::LAST_OPTION,
			array(
				'time'    => time(),
				'deleted' => array_sum( $counts ),
			),
			false
		);

		return array(
			'counts' => $counts,
			'done'   => $done,
		);
	}

	// Itens a apagar em cada categoria (tela do painel).
	public function count_all(): array {
		$counts = array();

		foreach ( self::ITEMS as $item ) {
			$counts[ $item ] = $this->count( $item );
		}

		return $counts;
	}

	public function count( string $item ): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		switch ( $item ) {
			case self::SETTING_REVISIONS:
				return count( $this->get_revision_ids( PHP_INT_MAX ) );

			case self::SETTING_AUTO_DRAFTS:
				return (int) $wpdb->get_var( "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" );

			case self::SETTING_TRASHED_POSTS:
				return (int) $wpdb->get_var( "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_status = 'trash'" );

			case self::SETTING_SPAM_COMMENTS:
				return (int) $wpdb->get_var( "SELECT COUNT(comment_ID) FROM {$wpdb->comments} WHERE comment_approved = 'spam'" );

			case self::SETTING_TRASHED_COMMENTS:
				return (int) $wpdb->get_var( "SELECT COUNT(comment_ID) FROM {$wpdb->comments} WHERE comment_approved IN ('trash', 'post-trashed')" );

			case self::SETTING_EXPIRED_TRANSIENTS:
				return count( $this->get_expired_transients( PHP_INT_MAX ) );

			case self::SETTING_ALL_TRANSIENTS:
				return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(option_id) FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_' ) . '%', $wpdb->esc_like( '_site_transient_' ) . '%' ) );

			case self::SETTING_ORPHAN_META:
				return (int) $wpdb->get_var( "SELECT COUNT(pm.meta_id) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL" )
					+ (int) $wpdb->get_var( "SELECT COUNT(cm.meta_id) FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL" )
					+ (int) $wpdb->get_var( "SELECT COUNT(tm.meta_id) FROM {$wpdb->termmeta} tm LEFT JOIN {$wpdb->terms} t ON t.term_id = tm.term_id WHERE t.term_id IS NULL" );

			case self::SETTING_OPTIMIZE_TABLES:
				return count( $this->get_fragmented_tables() );
		}
		// phpcs:enable

		return 0;
	}

	private function clean( string $item ): int {
		global $wpdb;

		$deleted = 0;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		switch ( $item ) {
			case self::SETTING_REVISIONS:
				foreach ( $this->get_revision_ids( self::BATCH ) as $id ) {
					$deleted += wp_delete_post_revision( $id ) instanceof \WP_Post ? 1 : 0;
				}
				break;

			case self::SETTING_AUTO_DRAFTS:
			case self::SETTING_TRASHED_POSTS:
				$status = self::SETTING_AUTO_DRAFTS === $item ? 'auto-draft' : 'trash';
				$ids    = (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = %s LIMIT %d", $status, self::BATCH ) );

				foreach ( $ids as $id ) {
					$deleted += wp_delete_post( (int) $id, true ) instanceof \WP_Post ? 1 : 0;
				}
				break;

			case self::SETTING_SPAM_COMMENTS:
			case self::SETTING_TRASHED_COMMENTS:
				$where = self::SETTING_SPAM_COMMENTS === $item ? "comment_approved = 'spam'" : "comment_approved IN ('trash', 'post-trashed')";
				$ids   = (array) $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE {$where} LIMIT %d", self::BATCH ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

				foreach ( $ids as $id ) {
					$deleted += wp_delete_comment( (int) $id, true ) ? 1 : 0;
				}
				break;

			case self::SETTING_EXPIRED_TRANSIENTS:
				foreach ( $this->get_expired_transients( self::BATCH ) as $transient ) {
					$deleted += 0 === strpos( $transient, '_site_transient_timeout_' )
						? (int) delete_site_transient( substr( $transient, strlen( '_site_transient_timeout_' ) ) )
						: (int) delete_transient( substr( $transient, strlen( '_transient_timeout_' ) ) );
				}
				break;

			case self::SETTING_ALL_TRANSIENTS:
				$names = (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s LIMIT %d", $wpdb->esc_like( '_transient_' ) . '%', $wpdb->esc_like( '_site_transient_' ) . '%', self::BATCH ) );

				foreach ( $names as $name ) {
					if ( 0 === strpos( $name, '_site_transient_timeout_' ) || 0 === strpos( $name, '_transient_timeout_' ) ) {
						$deleted += (int) delete_option( $name );
					} elseif ( 0 === strpos( $name, '_site_transient_' ) ) {
						$deleted += (int) delete_site_transient( substr( $name, strlen( '_site_transient_' ) ) );
					} else {
						$deleted += (int) delete_transient( substr( $name, strlen( '_transient_' ) ) );
					}
				}
				break;

			case self::SETTING_ORPHAN_META:
				$deleted += $this->delete_orphans( $wpdb->postmeta, 'post_id', $wpdb->posts, 'ID' );
				$deleted += $this->delete_orphans( $wpdb->commentmeta, 'comment_id', $wpdb->comments, 'comment_ID' );
				$deleted += $this->delete_orphans( $wpdb->termmeta, 'term_id', $wpdb->terms, 'term_id' );
				break;

			case self::SETTING_OPTIMIZE_TABLES:
				$deleted = $this->optimize_tables();
				break;
		}
		// phpcs:enable

		return $deleted;
	}

	// Revisões além das N mais recentes de cada post (N = 0 apaga todas).
	private function get_revision_ids( int $limit ): array {
		global $wpdb;

		$keep = max( 0, (int) $this->get_settings()[ self::SETTING_REVISIONS_KEEP ] );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( 0 === $keep ) {
			$sql = "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision'" . ( PHP_INT_MAX === $limit ? '' : $wpdb->prepare( ' LIMIT %d', $limit ) );

			return array_map( 'intval', (array) $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$parents = (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_parent FROM {$wpdb->posts} WHERE post_type = 'revision' GROUP BY post_parent HAVING COUNT(ID) > %d", $keep ) );
		// phpcs:enable

		$ids = array();

		foreach ( $parents as $parent ) {
			$revisions = wp_get_post_revisions(
				(int) $parent,
				array(
					'fields'  => 'ids',
					'orderby' => 'date ID',
					'order'   => 'DESC',
				)
			);

			$ids = array_merge( $ids, array_slice( array_map( 'intval', (array) $revisions ), $keep ) );

			if ( count( $ids ) >= $limit ) {
				break;
			}
		}

		return array_slice( $ids, 0, $limit );
	}

	// Nomes "_transient_timeout_x" / "_site_transient_timeout_x" já vencidos.
	private function get_expired_transients( int $limit ): array {
		global $wpdb;

		$sql = $wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE ( option_name LIKE %s OR option_name LIKE %s ) AND option_value < %d",
			$wpdb->esc_like( '_transient_timeout_' ) . '%',
			$wpdb->esc_like( '_site_transient_timeout_' ) . '%',
			time()
		) . ( PHP_INT_MAX === $limit ? '' : $wpdb->prepare( ' LIMIT %d', $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (array) $wpdb->get_col( $sql );
	}

	private function delete_orphans( string $meta_table, string $meta_column, string $parent_table, string $parent_column ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT m.meta_id FROM {$meta_table} m LEFT JOIN {$parent_table} p ON p.{$parent_column} = m.{$meta_column} WHERE p.{$parent_column} IS NULL LIMIT %d", self::BATCH ) );

		if ( empty( $ids ) ) {
			return 0;
		}

		$ids          = array_map( 'intval', $ids );
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$meta_table} WHERE meta_id IN ({$placeholders})", $ids ) );
	}

	// MySQL/MariaDB: tabelas do site com espaço livre (MyISAM/Aria; InnoDB só se o desperdício passar de 10 MB).
	// SQLite: VACUUM compacta o arquivo do banco.
	private function get_fragmented_tables(): array {
		global $wpdb;

		if ( self::is_sqlite() ) {
			return array( 'sqlite' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows   = (array) $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
		$tables = array();

		foreach ( $rows as $row ) {
			$free   = isset( $row->Data_free ) ? (int) $row->Data_free : 0; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$engine = isset( $row->Engine ) ? strtolower( (string) $row->Engine ) : ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

			if ( $free > 0 && ( 'innodb' !== $engine || $free > 10 * MB_IN_BYTES ) ) {
				$tables[] = (string) $row->Name; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}
		}

		return $tables;
	}

	private function optimize_tables(): int {
		global $wpdb;

		$optimized = 0;

		foreach ( $this->get_fragmented_tables() as $table ) {
			if ( 'sqlite' === $table ) {
				$optimized += self::vacuum_sqlite() ? 1 : 0;
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$optimized += false !== $wpdb->query( 'OPTIMIZE TABLE `' . esc_sql( $table ) . '`' ) ? 1 : 0;
		}

		return $optimized;
	}

	// O tradutor MySQL→SQLite não conhece VACUUM: vai direto pelo PDO do plugin de integração.
	private static function vacuum_sqlite(): bool {
		global $wpdb;

		$dbh = isset( $wpdb->dbh ) ? $wpdb->dbh : null;
		$pdo = null;

		if ( is_object( $dbh ) && method_exists( $dbh, 'get_connection' ) ) {
			$connection = $dbh->get_connection();
			$pdo        = is_object( $connection ) && method_exists( $connection, 'get_pdo' ) ? $connection->get_pdo() : null;
		} elseif ( is_object( $dbh ) && method_exists( $dbh, 'get_pdo' ) ) {
			$pdo = $dbh->get_pdo();
		}

		if ( ! $pdo instanceof \PDO ) {
			return false;
		}

		try {
			return false !== $pdo->exec( 'VACUUM' );
		} catch ( \Throwable $exception ) {
			return false;
		}
	}

	public static function is_sqlite(): bool {
		return ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) || ( defined( 'DATABASE_TYPE' ) && 'sqlite' === DATABASE_TYPE );
	}
}
