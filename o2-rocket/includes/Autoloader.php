<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket;

defined( 'ABSPATH' ) || exit;

final class Autoloader {

	const NS_SEPARATOR = '\\';

	private array $prefixes = array();

	private array $class_map = array();

	private array $missing = array();

	private bool $registered = false;

	public function __construct( string $prefix = '', string $base_dir = '' ) {
		if ( '' !== $prefix && '' !== $base_dir ) {
			$this->add_namespace( $prefix, $base_dir );
		}
	}

	public function add_namespace( string $prefix, string $base_dir, bool $prepend = false ): self {
		$prefix   = trim( $prefix, self::NS_SEPARATOR ) . self::NS_SEPARATOR;
		$base_dir = trailingslashit( wp_normalize_path( $base_dir ) );

		if ( ! isset( $this->prefixes[ $prefix ] ) ) {
			$this->prefixes[ $prefix ] = array();
		}

		if ( in_array( $base_dir, $this->prefixes[ $prefix ], true ) ) {
			return $this;
		}

		if ( $prepend ) {
			array_unshift( $this->prefixes[ $prefix ], $base_dir );
		} else {
			$this->prefixes[ $prefix ][] = $base_dir;
		}

		$this->sort_prefixes();
		$this->missing = array();

		return $this;
	}

	public function add_class_map( array $class_map ): self {
		foreach ( $class_map as $class => $file ) {
			if ( ! is_string( $class ) || ! is_string( $file ) ) {
				continue;
			}

			$class = ltrim( $class, self::NS_SEPARATOR );

			if ( ! $this->is_valid_class_name( $class ) ) {
				continue;
			}

			$this->class_map[ $class ] = wp_normalize_path( $file );
			unset( $this->missing[ $class ] );
		}

		return $this;
	}

	public function register( bool $prepend = false ): void {
		if ( $this->registered ) {
			return;
		}

		spl_autoload_register( array( $this, 'load_class' ), true, $prepend );
		$this->registered = true;
	}

	public function unregister(): void {
		if ( ! $this->registered ) {
			return;
		}

		spl_autoload_unregister( array( $this, 'load_class' ) );
		$this->registered = false;
	}

	public function is_registered(): bool {
		return $this->registered;
	}

	public function get_prefixes(): array {
		return $this->prefixes;
	}

	// PSR-4: nunca lança exceção, apenas retorna false para outros autoloaders atuarem.
	public function load_class( string $class ): bool {
		$class = ltrim( $class, self::NS_SEPARATOR );

		if ( isset( $this->missing[ $class ] ) ) {
			return false;
		}

		$file = $this->find_file( $class );

		if ( null === $file ) {
			$this->missing[ $class ] = true;
			return false;
		}

		require_once $file;

		return true;
	}

	public function find_file( string $class ): ?string {
		$class = ltrim( $class, self::NS_SEPARATOR );

		if ( isset( $this->class_map[ $class ] ) ) {
			return is_readable( $this->class_map[ $class ] ) ? $this->class_map[ $class ] : null;
		}

		if ( ! $this->is_valid_class_name( $class ) ) {
			return null;
		}

		foreach ( $this->prefixes as $prefix => $base_dirs ) {
			if ( 0 !== strpos( $class, $prefix ) ) {
				continue;
			}

			$relative_class = substr( $class, strlen( $prefix ) );

			if ( false === $relative_class || '' === $relative_class ) {
				continue;
			}

			foreach ( $base_dirs as $base_dir ) {
				foreach ( $this->get_candidate_paths( $relative_class ) as $relative_path ) {
					$file = $base_dir . $relative_path;

					if ( is_readable( $file ) ) {
						return $file;
					}
				}
			}
		}

		return null;
	}

	// Cache\Page_Cache => Cache/Page_Cache.php (PSR-4) ou Cache/class-page-cache.php (WPCS).
	private function get_candidate_paths( string $relative_class ): array {
		$segments   = explode( self::NS_SEPARATOR, $relative_class );
		$class_name = array_pop( $segments );
		$directory  = empty( $segments ) ? '' : implode( '/', $segments ) . '/';

		return array(
			$directory . $class_name . '.php',
			$directory . 'class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php',
		);
	}

	// Aceita apenas identificadores PHP válidos: bloqueia path traversal.
	private function is_valid_class_name( string $class ): bool {
		return 1 === preg_match( '/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/', $class );
	}

	// Prefixo mais longo primeiro: O2Rocket\Admin\ antes de O2Rocket\.
	private function sort_prefixes(): void {
		uksort(
			$this->prefixes,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
	}
}
