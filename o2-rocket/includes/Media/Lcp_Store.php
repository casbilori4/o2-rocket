<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Media;

defined( 'ABSPATH' ) || exit;

// Medições do beacon por URL e dispositivo: imagem LCP e imagens acima da dobra (tabela própria por site).
final class Lcp_Store {

	const DB_VERSION        = '1';
	const DB_VERSION_OPTION = 'o2_rocket_lcp_db_version';

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'o2_rocket_lcp';
	}

	public function is_installed(): bool {
		return self::DB_VERSION === get_option( self::DB_VERSION_OPTION );
	}

	public function install(): bool {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				url_hash char(32) NOT NULL default '',
				url varchar(2000) NOT NULL default '',
				device varchar(10) NOT NULL default 'desktop',
				lcp longtext NOT NULL,
				above_fold longtext NOT NULL,
				created datetime NOT NULL default '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				UNIQUE KEY url_hash (url_hash)
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

	public static function key( string $url, string $device ): string {
		return md5( untrailingslashit( $url ) . '|' . $device );
	}

	// [ dispositivo => [ 'lcp' => array, 'above_fold' => array ] ] para a URL.
	public function get( string $url ): array {
		global $wpdb;

		static $memo = array();

		if ( ! $this->is_installed() ) {
			return array();
		}

		if ( isset( $memo[ $url ] ) ) {
			return $memo[ $url ];
		}

		$table  = self::table();
		$hashes = array( self::key( $url, 'desktop' ), self::key( $url, 'mobile' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT device, lcp, above_fold FROM {$table} WHERE url_hash IN (%s, %s)", $hashes[0], $hashes[1] ) );
		$result = array();

		foreach ( $rows as $row ) {
			$lcp   = json_decode( (string) $row->lcp, true );
			$above = json_decode( (string) $row->above_fold, true );

			$result[ (string) $row->device ] = array(
				'lcp'        => is_array( $lcp ) ? $lcp : array(),
				'above_fold' => is_array( $above ) ? $above : array(),
			);
		}

		$memo[ $url ] = $result;

		return $result;
	}

	public function add( string $url, string $device, array $lcp, array $above_fold ): bool {
		global $wpdb;

		if ( ! $this->is_installed() && ! $this->install() ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return (bool) $wpdb->insert(
			self::table(),
			array(
				'url_hash'   => self::key( $url, $device ),
				'url'        => untrailingslashit( $url ),
				'device'     => $device,
				'lcp'        => (string) wp_json_encode( $lcp, JSON_UNESCAPED_SLASHES ),
				'above_fold' => (string) wp_json_encode( array_values( $above_fold ), JSON_UNESCAPED_SLASHES ),
				'created'    => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	public function exists( string $url, string $device ): bool {
		global $wpdb;

		if ( ! $this->is_installed() ) {
			return false;
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE url_hash = %s", self::key( $url, $device ) ) );
	}

	public function delete_urls( array $urls ): void {
		global $wpdb;

		if ( ! $this->is_installed() || empty( $urls ) ) {
			return;
		}

		$hashes = array();

		foreach ( $urls as $url ) {
			$hashes[] = self::key( (string) $url, 'desktop' );
			$hashes[] = self::key( (string) $url, 'mobile' );
		}

		$table        = self::table();
		$placeholders = implode( ', ', array_fill( 0, count( $hashes ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE url_hash IN ({$placeholders})", $hashes ) );
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

	public function count(): int {
		global $wpdb;

		if ( ! $this->is_installed() ) {
			return 0;
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}
}
