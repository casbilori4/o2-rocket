<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Server;

use function O2Rocket\atomic_write;
use function O2Rocket\direct_filesystem;

defined( 'ABSPATH' ) || exit;

// Regras de compressão e cache do navegador no .htaccess (Apache e LiteSpeed), num bloco próprio no topo do arquivo.
// Nginx e IIS não leem .htaccess: o painel mostra o trecho equivalente para copiar.
final class Htaccess {

	const SETTING_GZIP          = 'server_gzip';
	const SETTING_BROWSER_CACHE = 'server_browser_cache';

	const MARKER          = 'O2 Rocket';
	const STATUS_OPTION   = 'o2_rocket_htaccess_status';
	const CHECK_TRANSIENT = 'o2_rocket_htaccess_checked';

	const COMPRESSIBLE_TYPES = array(
		'text/html',
		'text/plain',
		'text/css',
		'text/xml',
		'text/javascript',
		'application/javascript',
		'application/x-javascript',
		'application/json',
		'application/ld+json',
		'application/manifest+json',
		'application/xml',
		'application/xhtml+xml',
		'application/rss+xml',
		'application/atom+xml',
		'application/vnd.ms-fontobject',
		'font/ttf',
		'font/otf',
		'image/svg+xml',
		'image/x-icon',
		'image/vnd.microsoft.icon',
	);

	public function register_hooks(): void {
		add_action( 'add_option_' . O2_ROCKET_OPTION, array( $this, 'sync' ), 30 );
		add_action( 'update_option_' . O2_ROCKET_OPTION, array( $this, 'sync' ), 30 );
		add_action( 'admin_init', array( $this, 'maybe_sync' ) );
		add_action( 'o2_rocket_deactivate', array( $this, 'remove' ) );
	}

	public static function get_server(): string {
		global $is_nginx, $is_IIS, $is_iis7;

		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';

		if ( false !== strpos( $software, 'litespeed' ) ) {
			return 'litespeed';
		}

		if ( ! empty( $is_nginx ) || false !== strpos( $software, 'nginx' ) || false !== strpos( $software, 'openresty' ) ) {
			return 'nginx';
		}

		if ( ! empty( $is_IIS ) || ! empty( $is_iis7 ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
			return 'iis';
		}

		if ( false !== strpos( $software, 'apache' ) ) {
			return 'apache';
		}

		return '' === $software ? 'unknown' : 'other';
	}

	public static function is_supported(): bool {
		return in_array( self::get_server(), array( 'apache', 'litespeed' ), true );
	}

	// Multisite: um .htaccess para a rede inteira, gerenciado pelo site principal.
	public static function can_manage(): bool {
		return self::is_supported() && ( ! is_multisite() || is_main_site() );
	}

	public function get_file(): string {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		return wp_normalize_path( get_home_path() . '.htaccess' );
	}

	public function get_settings(): array {
		$stored = get_option( O2_ROCKET_OPTION, array() );

		return wp_parse_args(
			is_array( $stored ) ? $stored : array(),
			array(
				self::SETTING_GZIP          => true,
				self::SETTING_BROWSER_CACHE => true,
			)
		);
	}

	public function get_rules(): array {
		$settings = $this->get_settings();
		$lines    = array();

		if ( rest_sanitize_boolean( $settings[ self::SETTING_GZIP ] ) ) {
			$lines = array_merge( $lines, $this->get_compression_rules() );
		}

		if ( rest_sanitize_boolean( $settings[ self::SETTING_BROWSER_CACHE ] ) ) {
			$lines = array_merge( $lines, $this->get_browser_cache_rules() );
		}

		return (array) apply_filters( 'o2_rocket_htaccess_rules', $lines, $settings );
	}

	// Revalida no máximo a cada hora (o .htaccess pode ter sido reescrito por outro plugin ou à mão).
	public function maybe_sync(): void {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) || ! self::can_manage() || get_transient( self::CHECK_TRANSIENT ) ) {
			return;
		}

		set_transient( self::CHECK_TRANSIENT, 1, HOUR_IN_SECONDS );

		$expected = $this->build_block( $this->get_rules() );
		$file     = $this->get_file();
		$contents = is_readable( $file ) ? (string) direct_filesystem()->get_contents( $file ) : '';

		if ( $this->extract_block( $contents ) !== $expected ) {
			$this->sync();
		}
	}

	public function sync(): bool {
		if ( ! self::can_manage() ) {
			return true;
		}

		return $this->write( $this->get_rules() );
	}

	public function remove(): bool {
		if ( ! self::can_manage() ) {
			return true;
		}

		return $this->write( array() );
	}

	public function get_status(): array {
		$status = get_option( self::STATUS_OPTION, array() );

		return wp_parse_args(
			is_array( $status ) ? $status : array(),
			array(
				'written' => false,
				'error'   => '',
				'time'    => 0,
			)
		);
	}

	// Bloco "# BEGIN O2 Rocket ... # END O2 Rocket" no topo; vazio remove o bloco.
	public function write( array $lines ): bool {
		$file       = $this->get_file();
		$filesystem = direct_filesystem();
		$exists     = file_exists( $file );
		$contents   = $exists ? $filesystem->get_contents( $file ) : '';

		if ( ! is_string( $contents ) ) {
			return $this->finish( false, __( 'não foi possível ler o arquivo .htaccess', 'o2-rocket' ) );
		}

		$clean = $this->strip_block( $contents );
		$block = $this->build_block( $lines );
		$new   = '' === $block ? $clean : $block . "\n\n" . ltrim( $clean );

		if ( $new === $contents ) {
			return $this->finish( '' !== $block, '' );
		}

		// Sem regras e sem arquivo: nada a fazer.
		if ( ! $exists && '' === trim( $new ) ) {
			return $this->finish( false, '' );
		}

		// O bloco do WordPress precisa continuar intacto.
		if ( substr_count( $contents, '# BEGIN WordPress' ) !== substr_count( $new, '# BEGIN WordPress' ) ) {
			return $this->finish( false, __( 'o .htaccess tem um formato inesperado e não foi alterado', 'o2-rocket' ) );
		}

		$writable = $exists ? wp_is_writable( $file ) : wp_is_writable( dirname( $file ) );

		if ( ! $writable || ! atomic_write( $file, $new ) ) {
			/* translators: %s: caminho do arquivo .htaccess. */
			return $this->finish( false, sprintf( __( 'sem permissão de escrita em %s', 'o2-rocket' ), $file ) );
		}

		return $this->finish( '' !== $block, '' );
	}

	public function get_block_text(): string {
		return $this->build_block( $this->get_rules() );
	}

	// Mesmo efeito para Nginx: vai dentro do bloco server {} (o Nginx não lê .htaccess).
	public static function get_nginx_snippet(): string {
		$types = implode( ' ', array_diff( self::COMPRESSIBLE_TYPES, array( 'text/html' ) ) );

		return implode(
			"\n",
			array(
				'# O² Rocket — compressão',
				'gzip on;',
				'gzip_vary on;',
				'gzip_comp_level 6;',
				'gzip_min_length 256;',
				'gzip_proxied any;',
				'gzip_types ' . $types . ';',
				'',
				'# O² Rocket — cache do navegador',
				'location ~* \.(?:css|js|mjs)$ {',
				'	expires 1y;',
				'	add_header Cache-Control "public";',
				'	try_files $uri /index.php?$args;',
				'}',
				// CORS nas fontes: necessário quando servidas por CDN, inofensivo sem ela.
				'location ~* \.(?:woff2?|ttf|otf|eot)$ {',
				'	expires 4M;',
				'	add_header Cache-Control "public";',
				'	add_header Access-Control-Allow-Origin "*";',
				'	try_files $uri /index.php?$args;',
				'}',
				'location ~* \.(?:jpe?g|png|gif|webp|avif|svg|ico|mp4|webm)$ {',
				'	expires 4M;',
				'	add_header Cache-Control "public";',
				'	try_files $uri /index.php?$args;',
				'}',
			)
		);
	}

	private function get_compression_rules(): array {
		$types = implode( ' ', self::COMPRESSIBLE_TYPES );

		// Respostas que já saem comprimidas (páginas .gz do cache) não são comprimidas de novo pelo mod_deflate/mod_brotli.
		// "no-gzip" para quem aceita Brotli: sem isso, o DEFLATE global do servidor ganha do Brotli.
		return array(
			'# Compressão (Brotli quando disponível, senão GZIP)',
			'<IfModule mod_brotli.c>',
			'	<IfModule mod_setenvif.c>',
			'		SetEnvIfNoCase Accept-Encoding "\bbr\b" no-gzip',
			'	</IfModule>',
			'	AddOutputFilterByType BROTLI_COMPRESS ' . $types,
			'</IfModule>',
			'<IfModule mod_deflate.c>',
			'	<IfModule mod_filter.c>',
			'		AddOutputFilterByType DEFLATE ' . $types,
			'	</IfModule>',
			'</IfModule>',
		);
	}

	private function get_browser_cache_rules(): array {
		$rules = array(
			'# Cache do navegador para arquivos estáticos (HTML sempre revalidado)',
			'<IfModule mod_mime.c>',
			'	AddType image/avif avif',
			'	AddType image/webp webp',
			'	AddType font/woff woff',
			'	AddType font/woff2 woff2',
			'	AddType application/manifest+json webmanifest',
			'</IfModule>',
			'<IfModule mod_expires.c>',
			'	ExpiresActive On',
		);

		$expires = array(
			'access plus 0 seconds' => array( 'text/html', 'text/xml', 'application/xml', 'application/json', 'application/ld+json' ),
			'access plus 1 hour'    => array( 'application/rss+xml', 'application/atom+xml' ),
			'access plus 1 week'    => array( 'image/x-icon', 'image/vnd.microsoft.icon', 'application/manifest+json' ),
			'access plus 4 months'  => array( 'image/gif', 'image/png', 'image/jpeg', 'image/webp', 'image/avif', 'image/svg+xml', 'video/mp4', 'video/webm', 'video/ogg', 'audio/ogg', 'audio/mpeg' ),
			'access plus 1 year'    => array( 'text/css', 'text/javascript', 'application/javascript', 'application/x-javascript', 'font/ttf', 'font/otf', 'font/woff', 'font/woff2', 'application/font-woff', 'application/font-woff2', 'application/vnd.ms-fontobject' ),
		);

		foreach ( $expires as $period => $types ) {
			foreach ( $types as $type ) {
				$rules[] = sprintf( '	ExpiresByType %s "%s"', $type, $period );
			}
		}

		$rules[] = '</IfModule>';
		$rules[] = '<IfModule mod_headers.c>';
		$rules[] = '	<FilesMatch "\.(?:css|js|mjs|woff2?|ttf|otf|eot|jpe?g|png|gif|webp|avif|svg|ico|mp4|webm)$">';
		$rules[] = '		Header unset Pragma';
		$rules[] = '		Header append Cache-Control "public"';
		$rules[] = '	</FilesMatch>';
		$rules[] = '</IfModule>';

		return $rules;
	}

	private function build_block( array $lines ): string {
		$lines = array_values( array_filter( array_map( 'strval', $lines ), 'strlen' ) );

		if ( empty( $lines ) ) {
			return '';
		}

		return implode(
			"\n",
			array_merge(
				array( '# BEGIN ' . self::MARKER, '# Gerado pelo O² Rocket: não edite, este bloco é refeito ao salvar as configurações.' ),
				$lines,
				array( '# END ' . self::MARKER )
			)
		);
	}

	private function extract_block( string $contents ): string {
		$pattern = '/^# BEGIN ' . preg_quote( self::MARKER, '/' ) . '\R.*?^# END ' . preg_quote( self::MARKER, '/' ) . '$/ms';

		return 1 === preg_match( $pattern, $contents, $matches ) ? str_replace( "\r\n", "\n", $matches[0] ) : '';
	}

	private function strip_block( string $contents ): string {
		$pattern = '/^# BEGIN ' . preg_quote( self::MARKER, '/' ) . '\R.*?^# END ' . preg_quote( self::MARKER, '/' ) . '\R*/ms';

		return (string) preg_replace( $pattern, '', $contents );
	}

	private function finish( bool $written, string $error ): bool {
		update_option(
			self::STATUS_OPTION,
			array(
				'written' => $written,
				'error'   => $error,
				'time'    => time(),
			),
			false
		);

		return '' === $error;
	}
}
