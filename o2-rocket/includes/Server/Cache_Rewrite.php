<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Server;

use O2Rocket\Cache\Page_Cache;

defined( 'ABSPATH' ) || exit;

// Entrega o cache de página direto pelo Apache/LiteSpeed (mod_rewrite), sem carregar o PHP. Mesmas regras do drop-in:
// só GET/HEAD sem query string, sem cookies/User-Agents que impedem o cache e só a versão para computador.
// Qualquer dúvida (arquivo ausente, host diferente, caractere fora da lista) cai no drop-in, que decide normalmente.
final class Cache_Rewrite {

	const SETTING = 'server_serve_cache';

	// Mesma detecção do wp_is_mobile() (Cache_Engine::is_mobile()).
	const MOBILE_REGEX = 'Mobile|Android|Silk/|Kindle|BlackBerry|Opera\ Mini|Opera\ Mobi';

	// Segmento de caminho aceito pelo Cache_Engine::get_path_segments() (sem %: o mod_rewrite vê o caminho decodificado).
	const SEGMENT = '[A-Za-z0-9_~-][A-Za-z0-9._~-]*';

	private Page_Cache $page_cache;

	private Htaccess $htaccess;

	public function __construct( Page_Cache $page_cache, Htaccess $htaccess ) {
		$this->page_cache = $page_cache;
		$this->htaccess   = $htaccess;
	}

	public function register_hooks(): void {
		add_filter( 'o2_rocket_htaccess_rules', array( $this, 'add_rules' ), 5, 2 );
		// A barra final dos permalinks muda as regras.
		add_action( 'update_option_permalink_structure', array( $this->htaccess, 'sync' ), 30 );
	}

	public function is_enabled(): bool {
		$stored = get_option( O2_ROCKET_OPTION, array() );

		return is_array( $stored ) && ! empty( $stored[ self::SETTING ] ) && $this->page_cache->is_enabled() && ! is_multisite() && Htaccess::is_supported();
	}

	public function add_rules( $rules ): array {
		$rules = is_array( $rules ) ? $rules : array();

		if ( ! $this->is_enabled() ) {
			return $rules;
		}

		$serve = $this->build_rules();

		return empty( $serve ) ? $rules : array_merge( $serve, $rules );
	}

	public function build_rules(): array {
		$config    = $this->page_cache->get_dropin_config();
		$host      = Page_Cache::get_site_host();
		$home_url  = wp_parse_url( home_url() );
		$home_path = untrailingslashit( Page_Cache::get_site_home_path() );
		$cache_fs  = wp_normalize_path( (string) $config['cache_path'] );
		$content   = trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );

		// O cache precisa estar dentro do wp-content (para virar URL) e a URL dentro da pasta do .htaccess.
		if ( '' === $host || ! is_array( $home_url ) || empty( $home_url['host'] ) || 0 !== strpos( trailingslashit( $cache_fs ), $content ) ) {
			return array();
		}

		$cache_url = (string) wp_parse_url( content_url( substr( trailingslashit( $cache_fs ), strlen( $content ) ) ), PHP_URL_PATH );
		$cache_url = '/' . trim( $cache_url, '/' ) . '/pages/';

		if ( 0 !== strpos( $cache_url, $home_path . '/' ) || 1 !== preg_match( '#^[A-Za-z0-9/._~-]+$#', $cache_url ) ) {
			return array();
		}

		$relative    = preg_quote( ltrim( substr( $cache_url, strlen( $home_path ) ), '/' ), '#' );
		$http_host   = $home_url['host'] . ( isset( $home_url['port'] ) ? ':' . $home_url['port'] : '' );
		$file_prefix = trailingslashit( $cache_fs ) . 'pages/' . $host;
		$url_prefix  = $cache_url . $host;
		$home_regex  = $this->quote( $home_path );
		$segment     = self::SEGMENT;
		$cookies     = $this->alternation( (array) $config['bypass_cookies'] );
		$agents      = $this->alternation( (array) $config['bypass_user_agents'] );
		$suffix      = '%1/index%{ENV:O2R_SSL}.html' . ( ! empty( $config['gzip'] ) ? '%{ENV:O2R_ENC}' : '' );
		$charset     = preg_match( '/^[A-Za-z0-9_-]+$/', (string) $config['charset'] ) ? (string) $config['charset'] : 'UTF-8';

		$lines = array(
			'# Cache de página entregue pelo servidor, sem PHP (o drop-in continua cuidando do resto)',
			'<IfModule mod_rewrite.c>',
			'<IfModule mod_headers.c>',
			'	<IfModule mod_mime.c>',
			'		<FilesMatch "^index(?:-https)?\.html\.gz$">',
			'			RemoveType .gz',
			'			RemoveEncoding .gz',
			'			ForceType text/html',
			'		</FilesMatch>',
			'	</IfModule>',
			'	<FilesMatch "^index(?:-https)?\.html(?:\.gz)?$">',
			'		AddDefaultCharset ' . $charset,
			'	</FilesMatch>',
			'	RewriteEngine On',
			// Segunda passada: o arquivo encontrado ganha os cabeçalhos do drop-in.
			'	RewriteRule ^' . $relative . '.+\.html\.gz$ - [E=no-gzip:1,E=no-brotli:1,E=O2R_GZ:1,E=O2R_HIT:1,L]',
			'	RewriteRule ^' . $relative . '.+\.html$ - [E=O2R_HIT:1,L]',
			'	Header set Content-Encoding gzip env=O2R_GZ',
			'	Header set Cache-Control "no-cache, must-revalidate" env=O2R_HIT',
			'	Header set X-O2-Rocket-Cache "HIT (servidor)" env=O2R_HIT',
			'	Header append Vary Accept-Encoding env=O2R_HIT',
			'	RewriteCond %{HTTPS} =on [OR]',
			'	RewriteCond %{SERVER_PORT} =443',
			'	RewriteRule .* - [E=O2R_SSL:-https]',
		);

		if ( ! empty( $config['gzip'] ) ) {
			$lines[] = '	RewriteCond %{HTTP:Accept-Encoding} gzip [NC]';
			$lines[] = '	RewriteRule .* - [E=O2R_ENC:.gz]';
		}

		$lines[] = '	RewriteCond %{REQUEST_METHOD} ^(?:GET|HEAD)$';
		$lines[] = '	RewriteCond %{QUERY_STRING} =""';

		if ( '' !== $cookies ) {
			$lines[] = '	RewriteCond %{HTTP:Cookie} "!(?:' . $cookies . ')" [NC]';
		}

		if ( '' !== $agents ) {
			$lines[] = '	RewriteCond %{HTTP_USER_AGENT} "!(?:' . $agents . ')" [NC]';
		}

		// Celulares com cache separado ficam com o drop-in (arquivo index-mobile).
		if ( ! empty( $config['mobile'] ) ) {
			$lines[] = '	RewriteCond %{HTTP_USER_AGENT} "!(?:' . self::MOBILE_REGEX . ')" [NC]';
		}

		$lines[] = '	RewriteCond %{HTTP_HOST} ^' . $this->quote( strtolower( $http_host ) ) . '$ [NC]';

		// Barra final fora do padrão dos permalinks: o WordPress redireciona.
		$lines[] = ! empty( $config['trailing_slash'] )
			? '	RewriteCond %{REQUEST_URI} /$'
			: '	RewriteCond %{REQUEST_URI} !^' . $home_regex . '(?:/' . $segment . ')+/$';

		$lines[] = '	RewriteCond %{REQUEST_URI} ^(' . $home_regex . '(?:/' . $segment . ')*)/?$';
		$lines[] = '	RewriteCond "' . $file_prefix . $suffix . '" -f';
		$lines[] = '	RewriteRule .* "' . $url_prefix . $suffix . '" [L]';
		$lines[] = '</IfModule>';
		$lines[] = '</IfModule>';

		return $lines;
	}

	// Trechos literais (nome de cookie, User-Agent) como alternativas de regex dentro de aspas.
	private function alternation( array $values ): string {
		$parts = array();

		foreach ( $values as $value ) {
			$value = str_replace( '"', '', trim( (string) $value ) );

			if ( '' !== $value ) {
				$parts[] = str_replace( ' ', '\ ', preg_quote( $value, '#' ) );
			}
		}

		return implode( '|', $parts );
	}

	private function quote( string $value ): string {
		return str_replace( ' ', '\ ', preg_quote( $value, '#' ) );
	}
}
