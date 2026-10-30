<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Addon;

use O2Rocket\Cache\Cache_Controls;

defined( 'ABSPATH' ) || exit;

// Cloudflare pela API oficial (token com permissão "Zone > Cache Purge" e, para o modo de desenvolvimento, "Zone Settings > Edit"):
// limpa o cache da Cloudflare junto com o do O² Rocket (tudo ou só as URLs alteradas, em lote).
final class Cloudflare {

	const SETTING_ENABLED = 'cloudflare_enabled';
	const SETTING_TOKEN   = 'cloudflare_token';
	const SETTING_ZONE    = 'cloudflare_zone_id';

	const API          = 'https://api.cloudflare.com/client/v4/';
	const ACTION       = 'o2_rocket_cloudflare';
	const QUEUE_OPTION = 'o2_rocket_cloudflare_queue';
	const QUEUE_HOOK   = 'o2_rocket_cloudflare_purge_urls';
	const BATCH        = 30;

	public function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_action' ) );
		add_action( self::QUEUE_HOOK, array( $this, 'purge_queue' ) );

		if ( ! $this->is_enabled() ) {
			return;
		}

		add_action( 'o2_rocket_after_purge_all', array( $this, 'purge_everything_async' ) );
		add_action( 'o2_rocket_before_purge_url', array( $this, 'queue_urls' ) );
	}

	public function get_settings(): array {
		$stored = get_option( O2_ROCKET_OPTION, array() );

		return wp_parse_args(
			is_array( $stored ) ? $stored : array(),
			array(
				self::SETTING_ENABLED => false,
				self::SETTING_TOKEN   => '',
				self::SETTING_ZONE    => '',
			)
		);
	}

	public function is_enabled(): bool {
		$settings = $this->get_settings();

		return rest_sanitize_boolean( $settings[ self::SETTING_ENABLED ] ) && '' !== $settings[ self::SETTING_TOKEN ] && '' !== $settings[ self::SETTING_ZONE ];
	}

	// Sem bloquear quem salvou o post ou as configurações.
	public function purge_everything_async(): void {
		$this->request( 'POST', 'zones/' . rawurlencode( $this->get_settings()[ self::SETTING_ZONE ] ) . '/purge_cache', array( 'purge_everything' => true ), false );
		delete_option( self::QUEUE_OPTION );
	}

	// URLs acumuladas e enviadas juntas alguns segundos depois (a API aceita 30 por chamada).
	public function queue_urls( $urls ): void {
		$urls = is_array( $urls ) ? $urls : array( $urls );

		if ( empty( $urls ) ) {
			return;
		}

		$queue = get_option( self::QUEUE_OPTION, array() );
		$queue = array_values( array_unique( array_merge( is_array( $queue ) ? $queue : array(), array_filter( $urls, 'is_string' ) ) ) );

		update_option( self::QUEUE_OPTION, array_slice( $queue, 0, 3000 ), false );

		if ( ! wp_next_scheduled( self::QUEUE_HOOK ) ) {
			wp_schedule_single_event( time() + 15, self::QUEUE_HOOK );
		}
	}

	public function purge_queue(): void {
		$queue = get_option( self::QUEUE_OPTION, array() );

		delete_option( self::QUEUE_OPTION );

		if ( ! $this->is_enabled() || ! is_array( $queue ) || empty( $queue ) ) {
			return;
		}

		foreach ( array_chunk( $queue, self::BATCH ) as $files ) {
			$this->request( 'POST', 'zones/' . rawurlencode( $this->get_settings()[ self::SETTING_ZONE ] ) . '/purge_cache', array( 'files' => array_values( $files ) ), true );
		}
	}

	public function handle_action(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- conferido logo abaixo.
		$task = isset( $_GET['task'] ) ? sanitize_key( wp_unslash( $_GET['task'] ) ) : '';

		check_admin_referer( self::ACTION . '_' . $task );

		if ( ! current_user_can( Cache_Controls::get_capability() ) ) {
			wp_die( esc_html__( 'Permissão negada.', 'o2-rocket' ), '', array( 'response' => 403 ) );
		}

		$zone = rawurlencode( $this->get_settings()[ self::SETTING_ZONE ] );

		switch ( $task ) {
			case 'purge':
				$result  = $this->request( 'POST', 'zones/' . $zone . '/purge_cache', array( 'purge_everything' => true ), true );
				$message = is_wp_error( $result ) ? $result->get_error_message() : __( 'Cache da Cloudflare limpo.', 'o2-rocket' );
				break;

			case 'devmode_on':
			case 'devmode_off':
				$result  = $this->request( 'PATCH', 'zones/' . $zone . '/settings/development_mode', array( 'value' => 'devmode_on' === $task ? 'on' : 'off' ), true );
				$message = is_wp_error( $result ) ? $result->get_error_message() : ( 'devmode_on' === $task ? __( 'Modo de desenvolvimento da Cloudflare ligado por 3 horas.', 'o2-rocket' ) : __( 'Modo de desenvolvimento da Cloudflare desligado.', 'o2-rocket' ) );
				break;

			default:
				$result  = $this->request( 'GET', 'zones/' . $zone, array(), true );
				$message = is_wp_error( $result )
					? $result->get_error_message()
					/* translators: %s: nome da zona na Cloudflare. */
					: sprintf( __( 'Conexão com a Cloudflare funcionando (zona %s).', 'o2-rocket' ), isset( $result['result']['name'] ) ? (string) $result['result']['name'] : '' );
				break;
		}

		Cache_Controls::add_notice( 'Cloudflare: ' . $message );
		wp_safe_redirect( (string) wp_get_referer() ?: admin_url( 'admin.php?page=o2-rocket' ) );
		exit;
	}

	public static function get_action_url( string $task ): string {
		return wp_nonce_url( add_query_arg( array( 'action' => self::ACTION, 'task' => $task ), admin_url( 'admin-post.php' ) ), self::ACTION . '_' . $task );
	}

	public static function sanitize_token( $value ): string {
		return substr( (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', is_scalar( $value ) ? (string) $value : '' ), 0, 200 );
	}

	public static function sanitize_zone( $value ): string {
		return substr( (string) preg_replace( '/[^a-f0-9]/', '', strtolower( is_scalar( $value ) ? (string) $value : '' ) ), 0, 64 );
	}

	// Resposta decodificada ou WP_Error com a mensagem da Cloudflare.
	private function request( string $method, string $path, array $body, bool $blocking ) {
		$args = array(
			'method'   => $method,
			'timeout'  => $blocking ? 15 : 3,
			'blocking' => $blocking,
			'headers'  => array(
				'Authorization' => 'Bearer ' . $this->get_settings()[ self::SETTING_TOKEN ],
				'Content-Type'  => 'application/json',
			),
		);

		if ( ! empty( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::API . $path, $args );

		if ( is_wp_error( $response ) || ! $blocking ) {
			return $response;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || empty( $data['success'] ) ) {
			$error = is_array( $data ) && ! empty( $data['errors'][0]['message'] ) ? (string) $data['errors'][0]['message'] : sprintf( 'HTTP %d', (int) wp_remote_retrieve_response_code( $response ) );

			return new \WP_Error( 'o2r_cloudflare', $error );
		}

		return $data;
	}
}
