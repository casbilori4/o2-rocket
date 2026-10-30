<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Optimization;

defined( 'ABSPATH' ) || exit;

// Controle da Heartbeat API (requisições periódicas ao admin-ajax.php) no site, no painel e no editor.
final class Heartbeat {

	const SETTING_CONTROL = 'heartbeat_control';
	const SETTING_SITE    = 'heartbeat_site';
	const SETTING_ADMIN   = 'heartbeat_admin';
	const SETTING_EDITOR  = 'heartbeat_editor';

	const BEHAVIORS        = array( 'default', 'reduce', 'disable' );
	const REDUCED_INTERVAL = 120;

	private ?array $settings = null;

	public function register_hooks(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		add_filter( 'heartbeat_settings', array( $this, 'filter_settings' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_disable' ), 99 );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_disable' ), 99 );
	}

	public function get_settings(): array {
		if ( null === $this->settings ) {
			$stored         = get_option( O2_ROCKET_OPTION, array() );
			$this->settings = wp_parse_args(
				is_array( $stored ) ? $stored : array(),
				array(
					self::SETTING_CONTROL => false,
					self::SETTING_SITE    => 'disable',
					self::SETTING_ADMIN   => 'reduce',
					self::SETTING_EDITOR  => 'reduce',
				)
			);
		}

		return $this->settings;
	}

	public function is_enabled(): bool {
		return rest_sanitize_boolean( $this->get_settings()[ self::SETTING_CONTROL ] );
	}

	// Comportamento no lugar atual: site, editor de posts (salvamento automático, bloqueio de edição) ou resto do painel.
	public function get_behavior(): string {
		$settings = $this->get_settings();

		if ( ! is_admin() ) {
			$key = self::SETTING_SITE;
		} elseif ( isset( $GLOBALS['pagenow'] ) && in_array( $GLOBALS['pagenow'], array( 'post.php', 'post-new.php' ), true ) ) {
			$key = self::SETTING_EDITOR;
		} else {
			$key = self::SETTING_ADMIN;
		}

		$behavior = (string) $settings[ $key ];

		return in_array( $behavior, self::BEHAVIORS, true ) ? $behavior : 'default';
	}

	public function filter_settings( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();

		if ( 'reduce' === $this->get_behavior() ) {
			$settings['interval'] = self::REDUCED_INTERVAL;
		}

		return $settings;
	}

	public function maybe_disable(): void {
		if ( 'disable' === $this->get_behavior() ) {
			wp_deregister_script( 'heartbeat' );
		}
	}
}
