<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Cache;

use function O2Rocket\atomic_write;
use function O2Rocket\direct_filesystem;
use function O2Rocket\invalidate_opcache;

defined( 'ABSPATH' ) || exit;

final class Drop_In_Manager {

	const MARKER          = 'O2_ROCKET_ADVANCED_CACHE';
	const WP_CACHE_LINE   = "define( 'WP_CACHE', true ); // O2_ROCKET_WP_CACHE";
	const DISABLED_PREFIX = '// O2_ROCKET_DISABLED ';
	const ERROR_TRANSIENT = 'o2_rocket_dropin_error';
	const RETRY_TRANSIENT = 'o2_rocket_dropin_retry';

	const STATE_OK       = 'ok';
	const STATE_MISSING  = 'missing';
	const STATE_OUTDATED = 'outdated';
	const STATE_CONFLICT = 'conflict';

	public function get_dropin_path(): string {
		return wp_normalize_path( WP_CONTENT_DIR . '/advanced-cache.php' );
	}

	public function get_template_path(): string {
		return O2_ROCKET_PATH . 'drop-ins/advanced-cache.php';
	}

	public function get_config_file(): string {
		$host = Page_Cache::get_site_host();

		return '' === $host ? '' : O2_ROCKET_CONFIG_PATH . $host . '.php';
	}

	public function get_dropin_state(): string {
		$target = $this->get_dropin_path();

		if ( ! file_exists( $target ) ) {
			return self::STATE_MISSING;
		}

		$contents = direct_filesystem()->get_contents( $target );

		if ( ! is_string( $contents ) || false === strpos( $contents, self::MARKER ) ) {
			return self::STATE_CONFLICT;
		}

		$template = $this->get_template_path();

		return is_readable( $template ) && md5_file( $template ) === md5( $contents ) ? self::STATE_OK : self::STATE_OUTDATED;
	}

	public function is_wp_cache_enabled(): bool {
		return defined( 'WP_CACHE' ) && WP_CACHE;
	}

	public function read_config(): ?array {
		$file = $this->get_config_file();

		if ( '' === $file || ! is_readable( $file ) ) {
			return null;
		}

		$config = include $file;

		return is_array( $config ) ? $config : null;
	}

	// Checagem barata, segura para rodar em todo admin_init.
	public function needs_sync( bool $enabled ): bool {
		$state = $this->get_dropin_state();

		if ( ! $enabled ) {
			return self::STATE_OK === $state || self::STATE_OUTDATED === $state;
		}

		if ( self::STATE_CONFLICT === $state ) {
			return false;
		}

		if ( self::STATE_OK !== $state || ! $this->is_wp_cache_enabled() ) {
			return true;
		}

		$config = $this->read_config();

		return null === $config || ! isset( $config['version'] ) || O2_ROCKET_VERSION !== $config['version'];
	}

	public function enable( array $config ): bool {
		$errors = array();

		if ( ! $this->write_config( $config ) ) {
			$errors[] = sprintf(
				/* translators: %s: diretório de configuração. */
				__( 'não foi possível gravar a configuração em %s', 'o2-rocket' ),
				O2_ROCKET_CONFIG_PATH
			);
		}

		$state = $this->get_dropin_state();

		if ( self::STATE_CONFLICT === $state ) {
			$errors[] = __( 'o arquivo wp-content/advanced-cache.php pertence a outro plugin de cache', 'o2-rocket' );
		} elseif ( self::STATE_OK !== $state && ! $this->install_dropin() ) {
			$errors[] = __( 'não foi possível copiar o advanced-cache.php para wp-content/', 'o2-rocket' );
		}

		// WP_CACHE só é ligado com o nosso drop-in no lugar.
		if ( empty( $errors ) && ! $this->is_wp_cache_enabled() && ! $this->set_wp_cache( true ) ) {
			$errors[] = __( 'não foi possível definir WP_CACHE como true no wp-config.php', 'o2-rocket' );
		}

		return $this->finish( $errors );
	}

	public function disable(): bool {
		$errors = array();
		$state  = $this->get_dropin_state();

		if ( ( self::STATE_OK === $state || self::STATE_OUTDATED === $state ) && ! $this->remove_dropin() ) {
			$errors[] = __( 'não foi possível remover o wp-content/advanced-cache.php', 'o2-rocket' );
		}

		if ( ! $this->set_wp_cache( false ) ) {
			$errors[] = __( 'não foi possível remover WP_CACHE do wp-config.php', 'o2-rocket' );
		}

		$this->delete_config();

		return $this->finish( $errors );
	}

	public function get_status(): array {
		return array(
			'dropin'   => $this->get_dropin_state(),
			'loaded'   => defined( 'O2_ROCKET_ADVANCED_CACHE' ),
			'wp_cache' => $this->is_wp_cache_enabled(),
			'config'   => null !== $this->read_config(),
			'error'    => $this->get_last_error(),
		);
	}

	public function get_last_error(): string {
		return (string) get_transient( self::ERROR_TRANSIENT );
	}

	private function install_dropin(): bool {
		$contents = direct_filesystem()->get_contents( $this->get_template_path() );

		if ( ! is_string( $contents ) || false === strpos( $contents, self::MARKER ) ) {
			return false;
		}

		return atomic_write( $this->get_dropin_path(), $contents ) && self::STATE_OK === $this->get_dropin_state();
	}

	private function remove_dropin(): bool {
		$target = $this->get_dropin_path();

		if ( ! direct_filesystem()->delete( $target ) ) {
			return false;
		}

		invalidate_opcache( $target );

		return true;
	}

	private function write_config( array $config ): bool {
		$file = $this->get_config_file();

		if ( '' === $file || ! wp_mkdir_p( O2_ROCKET_CONFIG_PATH ) ) {
			return false;
		}

		$filesystem = direct_filesystem();
		$index      = O2_ROCKET_CONFIG_PATH . 'index.php';

		if ( ! $filesystem->exists( $index ) ) {
			$filesystem->put_contents( $index, "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
		}

		// var_export gera um array PHP literal: sem risco de injeção a partir das configurações.
		$contents = "<?php\ndefined( 'ABSPATH' ) || exit;\n\nreturn " . var_export( $config, true ) . ";\n"; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export

		return atomic_write( $file, $contents );
	}

	private function delete_config(): void {
		$file = $this->get_config_file();

		if ( '' !== $file && file_exists( $file ) ) {
			direct_filesystem()->delete( $file );
			invalidate_opcache( $file );
		}
	}

	// Só toca em linhas marcadas pelo plugin: a nossa definição e as que comentamos.
	private function set_wp_cache( bool $enable ): bool {
		if ( ! apply_filters( 'o2_rocket_manage_wp_config', true ) ) {
			return ! $enable || $this->is_wp_cache_enabled();
		}

		$file = $this->find_wp_config();

		if ( '' === $file ) {
			return false;
		}

		$original = direct_filesystem()->get_contents( $file );

		if ( ! is_string( $original ) || '' === $original ) {
			return false;
		}

		$contents = $enable ? $this->add_wp_cache_define( $original ) : $this->remove_wp_cache_define( $original );

		if ( $contents === $original ) {
			return true;
		}

		if ( '' === $contents || ! $this->is_valid_wp_config( $contents ) ) {
			return false;
		}

		return atomic_write( $file, $contents );
	}

	private function add_wp_cache_define( string $contents ): string {
		if ( false !== strpos( $contents, self::WP_CACHE_LINE ) ) {
			return $contents;
		}

		if ( 1 === preg_match( '/^\s*define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,\s*true\s*\)\s*;/mi', $contents ) ) {
			return $contents;
		}

		// Comenta definições existentes (ex.: false) para evitar "constant already defined".
		$contents = (string) preg_replace(
			'/^([ \t]*)(define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,.*)$/mi',
			'$1' . self::DISABLED_PREFIX . '$2',
			$contents
		);

		$position = strpos( $contents, '<?php' );

		if ( false === $position ) {
			return '';
		}

		// Reaproveita a quebra de linha original para a remoção devolver o arquivo byte a byte.
		$next = substr( $contents, $position + 5, 1 );

		if ( "\n" === $next || "\r" === $next ) {
			$insert = '<?php' . ( "\r" === $next ? "\r\n" : "\n" ) . self::WP_CACHE_LINE;
		} else {
			$insert = "<?php\n" . self::WP_CACHE_LINE . "\n";
		}

		return substr_replace( $contents, $insert, $position, 5 );
	}

	private function remove_wp_cache_define( string $contents ): string {
		$contents = str_replace(
			array( self::WP_CACHE_LINE . "\r\n", self::WP_CACHE_LINE . "\n", self::WP_CACHE_LINE ),
			'',
			$contents
		);

		return (string) preg_replace( '/^([ \t]*)' . preg_quote( self::DISABLED_PREFIX, '/' ) . '/m', '$1', $contents );
	}

	private function is_valid_wp_config( string $contents ): bool {
		return false !== strpos( $contents, '<?php' ) && false !== strpos( $contents, 'wp-settings.php' );
	}

	// Mesma regra de localização do wp-load.php.
	private function find_wp_config(): string {
		$file = '';

		if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
			$file = ABSPATH . 'wp-config.php';
		} elseif ( file_exists( dirname( ABSPATH ) . '/wp-config.php' ) && ! file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ) {
			$file = dirname( ABSPATH ) . '/wp-config.php';
		}

		return (string) apply_filters( 'o2_rocket_wp_config_path', $file );
	}

	private function finish( array $errors ): bool {
		if ( empty( $errors ) ) {
			delete_transient( self::ERROR_TRANSIENT );
			return true;
		}

		set_transient( self::ERROR_TRANSIENT, implode( '; ', $errors ), DAY_IN_SECONDS );

		return false;
	}
}
