<?php
/**
 * O² Rocket — advanced-cache.php (O2_ROCKET_ADVANCED_CACHE)
 *
 * Copiado para wp-content/ pelo plugin. Não edite: é substituído a cada sincronização.
 *
 * @package O2Rocket
 */

// phpcs:disable WordPress.Security.ValidatedSanitizedInput, WordPress.WP.AlternativeFunctions, WordPress.DateTime.RestrictedFunctions

defined( 'ABSPATH' ) || exit;

define( 'O2_ROCKET_ADVANCED_CACHE', true );

( static function () {
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '';

	if ( 'GET' !== $method && 'HEAD' !== $method ) {
		return;
	}

	foreach ( array( 'WP_ADMIN', 'DOING_AJAX', 'DOING_CRON', 'XMLRPC_REQUEST', 'REST_REQUEST', 'WP_INSTALLING', 'IFRAME_REQUEST' ) as $constant ) {
		if ( defined( $constant ) ) {
			return;
		}
	}

	// Normalização de host/caminho: mantenha idêntica a O2Rocket\Cache\Page_Cache.
	$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( trim( (string) $_SERVER['HTTP_HOST'] ) ) : '';

	if ( 1 !== preg_match( '/^[a-z0-9.\-]+(?::[0-9]{1,5})?$/', $host ) ) {
		return;
	}

	$host        = str_replace( ':', '_', $host );
	$config_dir  = defined( 'O2_ROCKET_CONFIG_PATH' ) ? O2_ROCKET_CONFIG_PATH : WP_CONTENT_DIR . '/o2-rocket-config/';
	$config_file = rtrim( $config_dir, '/\\' ) . '/' . $host . '.php';

	if ( ! is_readable( $config_file ) ) {
		return;
	}

	$config = include $config_file;

	if ( ! is_array( $config ) || empty( $config['cache_path'] ) ) {
		return;
	}

	$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';

	if ( '' === $path || '/' !== $path[0] || false !== strpos( $path, '?' ) ) {
		return;
	}

	// Barra final fora do padrão dos permalinks: deixa o WordPress redirecionar (evita conteúdo duplicado).
	$home_path = isset( $config['home_path'] ) ? (string) $config['home_path'] : '/';

	if ( $path !== $home_path && ( '/' === substr( $path, -1 ) ) !== ! empty( $config['trailing_slash'] ) ) {
		return;
	}

	if ( ! empty( $config['exclude_regex'] ) && 1 === preg_match( (string) $config['exclude_regex'], $path ) ) {
		return;
	}

	$bypass_cookies = isset( $config['bypass_cookies'] ) ? (array) $config['bypass_cookies'] : array();

	foreach ( array_keys( $_COOKIE ) as $cookie ) {
		foreach ( $bypass_cookies as $prefix ) {
			if ( '' !== (string) $prefix && 0 === strpos( (string) $cookie, (string) $prefix ) ) {
				return;
			}
		}
	}

	$segments = array();

	foreach ( explode( '/', $path ) as $segment ) {
		if ( '' === $segment ) {
			continue;
		}

		if ( 1 !== preg_match( '/^[A-Za-z0-9%_~\-][A-Za-z0-9%._~\-]*$/', $segment ) ) {
			return;
		}

		$segments[] = preg_replace_callback(
			'/%[0-9a-f]{2}/i',
			static function ( $matches ) {
				return strtoupper( $matches[0] );
			},
			$segment
		);
	}

	$https = ( isset( $_SERVER['HTTPS'] ) && ( 'on' === strtolower( (string) $_SERVER['HTTPS'] ) || '1' === (string) $_SERVER['HTTPS'] ) )
		|| ( isset( $_SERVER['SERVER_PORT'] ) && '443' === (string) $_SERVER['SERVER_PORT'] );

	$file = rtrim( (string) $config['cache_path'], '/\\' ) . '/pages/' . $host . '/'
		. ( empty( $segments ) ? '' : implode( '/', $segments ) . '/' )
		. ( $https ? 'index-https.html' : 'index.html' );

	if ( ! is_file( $file ) || ! is_readable( $file ) ) {
		return;
	}

	$modified = (int) filemtime( $file );
	$lifespan = isset( $config['lifespan'] ) ? (int) $config['lifespan'] : 0;

	// Expirado: o WordPress gera de novo e sobrescreve.
	if ( $lifespan > 0 && $modified + $lifespan < time() ) {
		return;
	}

	$charset = isset( $config['charset'] ) && preg_match( '/^[A-Za-z0-9_\-]+$/', (string) $config['charset'] ) ? (string) $config['charset'] : 'UTF-8';

	header( 'Content-Type: text/html; charset=' . $charset );
	header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $modified ) . ' GMT' );
	header( 'Cache-Control: no-cache, must-revalidate' );
	header( 'X-O2-Rocket-Cache: HIT' );

	if ( isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) {
		$since = strtotime( (string) $_SERVER['HTTP_IF_MODIFIED_SINCE'] );

		if ( false !== $since && $since >= $modified ) {
			http_response_code( 304 );
			exit;
		}
	}

	if ( 'HEAD' !== $method ) {
		readfile( $file );
	}

	exit;
} )();
