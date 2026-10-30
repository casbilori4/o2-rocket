<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Core;

defined( 'ABSPATH' ) || exit;

final class Request {

	// Parâmetros de editores visuais: o front-end deles não pode ser alterado.
	const BUILDER_QUERY_VARS = array(
		'elementor-preview',
		'fl_builder',
		'et_fb',
		'ct_builder',
		'brizy-edit-iframe',
		'vc_editable',
		'tve',
	);

	// Front-end público: fora do painel, AJAX, cron, REST, login, Personalizador e editores visuais.
	public static function is_public_frontend(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		if ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ) {
			return false;
		}

		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			return false;
		}

		foreach ( self::BUILDER_QUERY_VARS as $query_var ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Só detecta o modo de edição, sem processar dados.
			if ( isset( $_GET[ $query_var ] ) ) {
				return false;
			}
		}

		return true;
	}
}
