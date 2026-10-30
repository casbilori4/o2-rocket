<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Cache;

defined( 'ABSPATH' ) || exit;

// PHP puro: roda no advanced-cache.php, antes de o WordPress carregar (nenhuma função do WP disponível).
// O Page_Cache usa os mesmos métodos para gravar: quem serve e quem grava calculam o mesmo arquivo.
final class Cache_Engine {

	const HEADER = 'X-O2-Rocket-Cache';

	// Mesmos sinais do wp_is_mobile() do núcleo (sem o filtro, que ainda não existe aqui).
	const MOBILE_TOKENS = array( 'Mobile', 'Android', 'Silk/', 'Kindle', 'BlackBerry', 'Opera Mini', 'Opera Mobi' );

	const MAX_CONFIG_DEPTH = 10;

	// Chamado pelo drop-in. Só retorna se não houver cache para servir.
	public static function serve( string $config_dir ): void {
		// WP-CLI e cron do sistema carregam o WordPress pela linha de comando: nunca recebem HTML do cache.
		if ( 'cli' === PHP_SAPI || defined( 'WP_CLI' ) ) {
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '';

		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return;
		}

		foreach ( array( 'WP_ADMIN', 'DOING_AJAX', 'DOING_CRON', 'XMLRPC_REQUEST', 'REST_REQUEST', 'WP_INSTALLING', 'IFRAME_REQUEST', 'DONOTCACHEPAGE' ) as $constant ) {
			if ( defined( $constant ) && constant( $constant ) ) {
				return;
			}
		}

		$host = self::normalize_host( isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : '' );
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';

		if ( '' === $host || '' === $uri || '/' !== $uri[0] ) {
			return;
		}

		$config = self::find_config( $config_dir, $host, self::split_uri( $uri )[0] );

		if ( null === $config ) {
			return;
		}

		$target = self::resolve( $config, $_SERVER, $_COOKIE );

		if ( null === $target ) {
			return;
		}

		$file = $target['file'];

		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			return;
		}

		$modified = (int) filemtime( $file );
		$lifespan = isset( $config['lifespan'] ) ? (int) $config['lifespan'] : 0;

		// Expirado: o WordPress gera de novo e sobrescreve.
		if ( $lifespan > 0 && $modified + $lifespan < time() ) {
			return;
		}

		$gzip_enabled = ! empty( $config['gzip'] );
		$encoding     = '';
		$body         = $file;

		// Brotli (se o PHP gerou a cópia .br) antes de GZIP; o motor de compressão do PHP ligado anula os dois.
		if ( $gzip_enabled && self::can_send_gzip() ) {
			if ( self::accepts_encoding( $_SERVER, 'br' ) && is_file( $file . '.br' ) && is_readable( $file . '.br' ) ) {
				$encoding = 'br';
				$body     = $file . '.br';
			} elseif ( self::accepts_gzip( $_SERVER ) && is_file( $file . '.gz' ) && is_readable( $file . '.gz' ) ) {
				$encoding = 'gzip';
				$body     = $file . '.gz';
			}
		}

		$charset = isset( $config['charset'] ) && preg_match( '/^[A-Za-z0-9_\-]+$/', (string) $config['charset'] ) ? (string) $config['charset'] : 'UTF-8';
		$vary    = array();

		if ( $gzip_enabled ) {
			$vary[] = 'Accept-Encoding';
		}

		if ( ! empty( $config['mobile'] ) ) {
			$vary[] = 'User-Agent';
		}

		header( 'Content-Type: text/html; charset=' . $charset );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $modified ) . ' GMT' );
		header( 'Cache-Control: no-cache, must-revalidate' );
		header( self::HEADER . ': HIT' );

		if ( ! empty( $vary ) ) {
			header( 'Vary: ' . implode( ', ', $vary ) );
		}

		if ( isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) {
			$since = strtotime( (string) $_SERVER['HTTP_IF_MODIFIED_SINCE'] );

			if ( false !== $since && $since >= $modified ) {
				http_response_code( 304 );
				exit;
			}
		}

		if ( '' !== $encoding ) {
			header( 'Content-Encoding: ' . $encoding );
		}

		header( 'Content-Length: ' . (int) filesize( $body ) );

		if ( 'HEAD' !== $method ) {
			readfile( $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		}

		exit;
	}

	// Arquivo de cache da requisição. Null = não usa cache (exclusão, cookie, parâmetro desconhecido...).
	// "generate" = falso quando dá para servir, mas não para gravar (ex.: só parâmetros de marketing).
	public static function resolve( array $config, array $server, array $cookies ): ?array {
		$host = self::normalize_host( isset( $server['HTTP_HOST'] ) ? (string) $server['HTTP_HOST'] : '' );
		$uri  = isset( $server['REQUEST_URI'] ) ? (string) $server['REQUEST_URI'] : '';

		if ( '' === $host || '' === $uri || '/' !== $uri[0] || empty( $config['cache_path'] ) ) {
			return null;
		}

		list( $path, $query, $has_query ) = self::split_uri( $uri );

		$path      = self::uppercase_percent_encoding( $path );
		$home_path = self::normalize_home_path( isset( $config['home_path'] ) ? (string) $config['home_path'] : '/' );

		if ( 0 !== strpos( $path . ( '/' === substr( $path, -1 ) ? '' : '/' ), $home_path ) ) {
			return null;
		}

		// Barra final fora do padrão dos permalinks: deixa o WordPress redirecionar (evita conteúdo duplicado).
		if ( $path !== $home_path && ( '/' === substr( $path, -1 ) ) !== ! empty( $config['trailing_slash'] ) ) {
			return null;
		}

		if ( ! empty( $config['exclude_regex'] ) && 1 === preg_match( (string) $config['exclude_regex'], $path ) ) {
			return null;
		}

		if ( self::matches_any( array_keys( $cookies ), isset( $config['bypass_cookies'] ) ? (array) $config['bypass_cookies'] : array(), false ) ) {
			return null;
		}

		$user_agent = isset( $server['HTTP_USER_AGENT'] ) ? (string) $server['HTTP_USER_AGENT'] : '';

		if ( '' !== $user_agent && self::matches_any( array( $user_agent ), isset( $config['bypass_user_agents'] ) ? (array) $config['bypass_user_agents'] : array(), true ) ) {
			return null;
		}

		$parsed = self::parse_query(
			$query,
			isset( $config['ignored_params'] ) ? (array) $config['ignored_params'] : array(),
			isset( $config['cache_query_params'] ) ? (array) $config['cache_query_params'] : array()
		);

		if ( null === $parsed ) {
			return null;
		}

		$segments = self::get_path_segments( $path );

		if ( null === $segments ) {
			return null;
		}

		$directory = self::get_cache_dir( (string) $config['cache_path'], $host, $segments );
		$mobile    = ! empty( $config['mobile'] ) && self::is_mobile( $server );
		$filename  = self::get_filename( $mobile, self::is_https( $server ), $parsed['key'] );

		return array(
			'file'      => $directory . $filename,
			'directory' => $directory,
			'mobile'    => $mobile,
			// "?" vazio ou com parâmetro ignorado: serve a URL limpa, mas nunca grava a partir dela.
			'generate'  => ! $parsed['ignored'] && ( ! $has_query || '' !== $parsed['key'] ),
		);
	}

	// Escolhe a configuração do site pelo maior prefixo do caminho (multisite em subpastas, sites em subpasta).
	public static function find_config( string $config_dir, string $host, string $path ): ?array {
		$config_dir = rtrim( $config_dir, '/\\' ) . '/';
		$candidates = array( '/' );
		$prefix     = '/';

		foreach ( array_slice( array_values( array_filter( explode( '/', $path ), 'strlen' ) ), 0, self::MAX_CONFIG_DEPTH ) as $segment ) {
			$prefix      .= $segment . '/';
			$candidates[] = $prefix;
		}

		foreach ( array_reverse( $candidates ) as $home_path ) {
			$file = $config_dir . self::config_basename( $host, $home_path );

			if ( ! is_file( $file ) || ! is_readable( $file ) ) {
				continue;
			}

			$config = include $file;

			if ( is_array( $config ) && ! empty( $config['cache_path'] ) && isset( $config['home_path'] ) && self::normalize_home_path( (string) $config['home_path'] ) === self::normalize_home_path( $home_path ) ) {
				return $config;
			}
		}

		return null;
	}

	// "{host}.php" para sites na raiz; "{host}-{hash}.php" para sites em subpasta.
	public static function config_basename( string $host, string $home_path ): string {
		$home_path = self::normalize_home_path( $home_path );

		return $host . ( '/' === $home_path ? '' : '-' . substr( md5( $home_path ), 0, 12 ) ) . '.php';
	}

	public static function normalize_home_path( string $home_path ): string {
		$home_path = '/' . trim( $home_path, '/' ) . '/';

		return '//' === $home_path ? '/' : self::uppercase_percent_encoding( $home_path );
	}

	public static function normalize_host( string $host ): string {
		$host = strtolower( trim( $host ) );

		if ( 1 !== preg_match( '/^[a-z0-9.\-]+(?::[0-9]{1,5})?$/', $host ) ) {
			return '';
		}

		return str_replace( ':', '_', $host );
	}

	// Null = caminho inválido (traversal, caracteres fora da lista): não cacheia.
	public static function get_path_segments( string $path ): ?array {
		$segments = array();

		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment ) {
				continue;
			}

			if ( 1 !== preg_match( '/^[A-Za-z0-9%_~\-][A-Za-z0-9%._~\-]*$/', $segment ) ) {
				return null;
			}

			$segments[] = self::uppercase_percent_encoding( $segment );
		}

		return $segments;
	}

	public static function get_cache_dir( string $cache_path, string $host, array $segments ): string {
		return rtrim( $cache_path, '/\\' ) . '/pages/' . $host . '/' . ( empty( $segments ) ? '' : implode( '/', $segments ) . '/' );
	}

	// index[-mobile][-https][-q{hash}].html
	public static function get_filename( bool $mobile, bool $https, string $query_key = '' ): string {
		return 'index' . ( $mobile ? '-mobile' : '' ) . ( $https ? '-https' : '' ) . ( '' !== $query_key ? '-q' . $query_key : '' ) . '.html';
	}

	// Arquivos de página (e cópias .gz/.br) que o plugin grava num diretório de cache.
	public static function is_cache_filename( string $name ): bool {
		return 1 === preg_match( '/^index(?:-mobile)?(?:-https)?(?:-q[0-9a-f]{12})?\.html(?:\.gz|\.br)?$/', $name );
	}

	// Permalinks com acento vêm em minúsculas (%c3%a7) e navegadores enviam maiúsculas (%C3%A7).
	public static function uppercase_percent_encoding( string $value ): string {
		return (string) preg_replace_callback(
			'/%[0-9a-f]{2}/i',
			static function ( $matches ) {
				return strtoupper( $matches[0] );
			},
			$value
		);
	}

	// [ caminho, query, tinha "?" ]. O fragmento (#) nunca chega ao servidor.
	public static function split_uri( string $uri ): array {
		$position = strpos( $uri, '?' );

		if ( false === $position ) {
			return array( $uri, '', false );
		}

		return array( substr( $uri, 0, $position ), (string) substr( $uri, $position + 1 ), true );
	}

	// Null = parâmetro desconhecido (pula o cache). "key" identifica os parâmetros com cache próprio.
	public static function parse_query( string $query, array $ignored, array $cacheable ): ?array {
		$result = array(
			'key'     => '',
			'ignored' => false,
		);

		if ( '' === $query ) {
			return $result;
		}

		$ignored   = array_flip( array_map( 'strtolower', array_map( 'strval', $ignored ) ) );
		$cacheable = array_flip( array_map( 'strtolower', array_map( 'strval', $cacheable ) ) );
		$kept      = array();

		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}

			$parts = explode( '=', $pair, 2 );
			$name  = strtolower( rawurldecode( str_replace( '+', ' ', $parts[0] ) ) );

			if ( '' === $name ) {
				continue;
			}

			if ( isset( $ignored[ $name ] ) ) {
				$result['ignored'] = true;
				continue;
			}

			if ( ! isset( $cacheable[ $name ] ) ) {
				return null;
			}

			$kept[] = $name . '=' . ( isset( $parts[1] ) ? rawurldecode( str_replace( '+', ' ', $parts[1] ) ) : '' );
		}

		if ( ! empty( $kept ) ) {
			sort( $kept, SORT_STRING );
			$result['key'] = substr( md5( implode( '&', $kept ) ), 0, 12 );
		}

		return $result;
	}

	public static function is_mobile( array $server ): bool {
		if ( isset( $server['HTTP_SEC_CH_UA_MOBILE'] ) ) {
			return '?1' === $server['HTTP_SEC_CH_UA_MOBILE'];
		}

		$user_agent = isset( $server['HTTP_USER_AGENT'] ) ? (string) $server['HTTP_USER_AGENT'] : '';

		if ( '' === $user_agent ) {
			return false;
		}

		foreach ( self::MOBILE_TOKENS as $token ) {
			if ( false !== strpos( $user_agent, $token ) ) {
				return true;
			}
		}

		return false;
	}

	// Mesma regra do is_ssl() do WordPress.
	public static function is_https( array $server ): bool {
		if ( isset( $server['HTTPS'] ) && ( 'on' === strtolower( (string) $server['HTTPS'] ) || '1' === (string) $server['HTTPS'] ) ) {
			return true;
		}

		return isset( $server['SERVER_PORT'] ) && '443' === (string) $server['SERVER_PORT'];
	}

	public static function accepts_gzip( array $server ): bool {
		return self::accepts_encoding( $server, 'gzip' ) || self::accepts_encoding( $server, 'x-gzip' );
	}

	// Respeita "gzip;q=0" (recusa explícita).
	public static function accepts_encoding( array $server, string $encoding ): bool {
		$header = isset( $server['HTTP_ACCEPT_ENCODING'] ) ? strtolower( (string) $server['HTTP_ACCEPT_ENCODING'] ) : '';

		foreach ( explode( ',', $header ) as $token ) {
			$parts = array_map( 'trim', explode( ';', $token ) );

			if ( $encoding !== $parts[0] ) {
				continue;
			}

			foreach ( array_slice( $parts, 1 ) as $parameter ) {
				if ( 1 === preg_match( '/^q\s*=\s*0(?:\.0{0,3})?$/', $parameter ) ) {
					return false;
				}
			}

			return true;
		}

		return false;
	}

	// Compressão do próprio PHP ligada comprimiria de novo o .gz.
	public static function can_send_gzip(): bool {
		if ( headers_sent() || filter_var( ini_get( 'zlib.output_compression' ), FILTER_VALIDATE_BOOLEAN ) ) {
			return false;
		}

		$handlers = function_exists( 'ob_list_handlers' ) ? ob_list_handlers() : array();

		return ! in_array( 'ob_gzhandler', $handlers, true ) && ! in_array( 'zlib output compression', $handlers, true );
	}

	// Trecho em qualquer posição; vazio nunca casa.
	public static function matches_any( array $haystacks, array $needles, bool $case_insensitive ): bool {
		foreach ( $needles as $needle ) {
			$needle = (string) $needle;

			if ( '' === $needle ) {
				continue;
			}

			foreach ( $haystacks as $haystack ) {
				$haystack = (string) $haystack;

				if ( $case_insensitive ? false !== stripos( $haystack, $needle ) : false !== strpos( $haystack, $needle ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
