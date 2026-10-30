<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Core;

use O2Rocket\Cache\Drop_In_Manager;
use O2Rocket\Media\Lcp_Store;
use O2Rocket\Preload\Preload_Queue;
use O2Rocket\Server\Htaccess;

use function O2Rocket\direct_filesystem;
use function O2Rocket\run_for_each_site;
use function O2Rocket\unschedule_cron_events;

defined( 'ABSPATH' ) || exit;

// Desinstalação limpa: nada do plugin fica no banco, no wp-config.php, no .htaccess ou em wp-content/.
final class Uninstaller {

	// Opções por site (os transients saem pelo prefixo).
	const OPTIONS = array(
		'o2_rocket_settings',
		'o2_rocket_installed_at',
		'o2_rocket_version',
		'o2_rocket_benchmark',
		'o2_rocket_preload_state',
		'o2_rocket_preload_db_version',
		'o2_rocket_htaccess_status',
		'o2_rocket_webp_quality_migrated',
	);

	// Metadados gravados nos anexos pela otimização de imagens (os arquivos .webp ficam: outros plugins usam o mesmo nome).
	const POST_META = array(
		'_o2_rocket_status',
		'_o2_rocket_original_size',
		'_o2_rocket_optimized_size',
		'_o2_rocket_error',
		'_o2_rocket_webp_quality',
	);

	public static function run(): void {
		// .htaccess e drop-in são da instalação inteira: limpos uma vez, antes das opções (eles gravam status).
		( new Htaccess() )->write( array() );
		( new Drop_In_Manager() )->disable( true );

		run_for_each_site( array( self::class, 'clean_site' ), true );

		self::delete_directory( O2_ROCKET_CACHE_PATH );
		self::delete_config_directory();

		do_action( 'o2_rocket_uninstall' );
	}

	public static function clean_site(): void {
		global $wpdb;

		unschedule_cron_events();

		( new Preload_Queue() )->drop();
		( new Lcp_Store() )->drop();

		foreach ( (array) apply_filters( 'o2_rocket_uninstall_options', self::OPTIONS ) as $option ) {
			delete_option( (string) $option );
		}

		foreach ( self::POST_META as $meta_key ) {
			delete_post_meta_by_key( $meta_key );
		}

		$like_transient = $wpdb->esc_like( '_transient_o2_rocket_' ) . '%';
		$like_timeout   = $wpdb->esc_like( '_transient_timeout_o2_rocket_' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like_transient, $like_timeout ) );

		wp_cache_flush();
	}

	private static function delete_directory( string $directory ): void {
		if ( '' !== $directory && is_dir( $directory ) ) {
			direct_filesystem()->delete( $directory, true );
		}
	}

	// Só remove a pasta se sobrar apenas o index.php.
	private static function delete_config_directory(): void {
		if ( ! is_dir( O2_ROCKET_CONFIG_PATH ) ) {
			return;
		}

		$left = array_diff( (array) scandir( O2_ROCKET_CONFIG_PATH ), array( '.', '..', 'index.php' ) );

		if ( empty( $left ) ) {
			direct_filesystem()->delete( O2_ROCKET_CONFIG_PATH, true );
		}
	}
}
