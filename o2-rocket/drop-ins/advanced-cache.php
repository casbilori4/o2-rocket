<?php
/**
 * O² Rocket — advanced-cache.php (O2_ROCKET_ADVANCED_CACHE)
 *
 * Copiado para wp-content/ pelo plugin. Não edite: é substituído a cada sincronização.
 *
 * @package O2Rocket
 */

defined( 'ABSPATH' ) || exit;

define( 'O2_ROCKET_ADVANCED_CACHE', true );

// Sem o plugin (pasta apagada ou renomeada), o WordPress carrega normalmente.
( static function ( $engine ) {
	if ( ! is_file( $engine ) || ! is_readable( $engine ) ) {
		return;
	}

	require_once $engine;

	if ( class_exists( 'O2Rocket\Cache\Cache_Engine', false ) ) {
		\O2Rocket\Cache\Cache_Engine::serve( defined( 'O2_ROCKET_CONFIG_PATH' ) ? O2_ROCKET_CONFIG_PATH : WP_CONTENT_DIR . '/o2-rocket-config/' );
	}
} )( '{{O2_ROCKET_ENGINE}}' );
