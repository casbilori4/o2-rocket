<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Media;

use O2Rocket\Optimization\Html_Pipeline;

defined( 'ABSPATH' ) || exit;

final class Image_Optimizer {

	const SETTING_AUTO  = 'media_webp_auto';
	const SETTING_SERVE = 'media_webp_serve';

	const META_STATUS    = '_o2_rocket_status';
	const META_ORIGINAL  = '_o2_rocket_original_size';
	const META_OPTIMIZED = '_o2_rocket_optimized_size';
	const META_ERROR     = '_o2_rocket_error';

	const STATUS_OPTIMIZED         = 'optimized';
	const STATUS_ALREADY_OPTIMIZED = 'already_optimized';
	const STATUS_ERROR             = 'error';

	const MIME_TYPES  = array( 'image/jpeg', 'image/png' );
	const WEBP_SUFFIX = '.webp';

	private ?array $settings = null;

	private array $webp_exists = array();

	public function register_hooks(): void {
		add_action( 'delete_attachment', array( $this, 'delete_webp_files' ) );

		if ( $this->is_enabled( self::SETTING_AUTO ) ) {
			add_filter( 'wp_generate_attachment_metadata', array( $this, 'on_generate_metadata' ), 20, 2 );
		}

		if ( ! is_admin() && $this->is_enabled( self::SETTING_SERVE ) ) {
			add_filter( Html_Pipeline::FILTER, array( $this, 'filter_html' ), 60 );
		}
	}

	// Checagem sem carregar as classes de editor (usada pelo painel).
	public static function server_supports_lossless(): bool {
		return function_exists( 'imagewebp' ) && defined( 'IMG_WEBP_LOSSLESS' );
	}

	public function get_settings(): array {
		if ( null === $this->settings ) {
			$stored         = get_option( O2_ROCKET_OPTION, array() );
			$this->settings = wp_parse_args(
				is_array( $stored ) ? $stored : array(),
				array(
					self::SETTING_AUTO  => false,
					self::SETTING_SERVE => false,
				)
			);
		}

		return $this->settings;
	}

	public function is_enabled( string $setting ): bool {
		$settings = $this->get_settings();

		return isset( $settings[ $setting ] ) && rest_sanitize_boolean( $settings[ $setting ] );
	}

	public function on_generate_metadata( $metadata, $attachment_id ) {
		if ( is_array( $metadata ) ) {
			$this->optimize( (int) $attachment_id, $metadata );
		}

		return $metadata;
	}

	// Converte o arquivo principal e todos os tamanhos; um WebP só é mantido se ficar menor que o original.
	public function optimize( int $attachment_id, ?array $metadata = null ): array {
		$result = array(
			'id'        => $attachment_id,
			'status'    => self::STATUS_ERROR,
			'original'  => 0,
			'optimized' => 0,
			'webp'      => 0,
			'error'     => '',
		);

		if ( ! in_array( get_post_mime_type( $attachment_id ), self::MIME_TYPES, true ) ) {
			$result['error'] = __( 'formato não suportado (apenas JPEG e PNG)', 'o2-rocket' );

			return $this->save_result( $result );
		}

		$files = $this->get_attachment_files( $attachment_id, $metadata );

		if ( empty( $files ) ) {
			$result['error'] = __( 'arquivo da imagem não encontrado no servidor', 'o2-rocket' );

			return $this->save_result( $result );
		}

		wp_raise_memory_limit( 'image' );

		foreach ( $files as $index => $file ) {
			$converted = $this->convert_file( $file );

			if ( is_wp_error( $converted ) ) {
				// Falha no arquivo principal invalida o anexo; nos tamanhos, o original segue valendo.
				if ( 0 === $index ) {
					$result['error'] = $converted->get_error_message();

					return $this->save_result( $result );
				}

				$size = (int) filesize( $file );

				$result['original']  += $size;
				$result['optimized'] += $size;
				continue;
			}

			$result['original']  += $converted['original'];
			$result['optimized'] += $converted['optimized'];
			$result['webp']      += $converted['kept'] ? 1 : 0;
		}

		$result['status'] = $result['webp'] > 0 ? self::STATUS_OPTIMIZED : self::STATUS_ALREADY_OPTIMIZED;

		return $this->save_result( $result );
	}

	// Arquivo servido (índice 0, pode ser o -scaled) + tamanhos intermediários; o original não escalonado não é servido.
	public function get_attachment_files( int $attachment_id, ?array $metadata = null ): array {
		$main = get_attached_file( $attachment_id );

		if ( ! is_string( $main ) || '' === $main || ! is_file( $main ) ) {
			return array();
		}

		$metadata  = null === $metadata ? wp_get_attachment_metadata( $attachment_id ) : $metadata;
		$directory = trailingslashit( dirname( $main ) );
		$files     = array( wp_normalize_path( $main ) );

		if ( is_array( $metadata ) && ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size ) {
				if ( empty( $size['file'] ) || ! is_string( $size['file'] ) || ! in_array( $size['mime-type'] ?? '', self::MIME_TYPES, true ) ) {
					continue;
				}

				$path = wp_normalize_path( $directory . wp_basename( $size['file'] ) );

				if ( is_file( $path ) && ! in_array( $path, $files, true ) ) {
					$files[] = $path;
				}
			}
		}

		return $files;
	}

	public function delete_webp_files( $attachment_id ): void {
		foreach ( $this->get_attachment_files( (int) $attachment_id ) as $file ) {
			if ( is_file( $file . self::WEBP_SUFFIX ) ) {
				wp_delete_file( $file . self::WEBP_SUFFIX );
			}
		}
	}

	// Etapa do pipeline de HTML (o pipeline já confere front-end público e resposta HTML).
	public function filter_html( $html ) {
		if ( ! is_string( $html ) || '' === $html || ! (bool) apply_filters( 'o2_rocket_webp_rewrite', true ) ) {
			return $html;
		}

		return $this->replace_urls( $html );
	}

	// Troca URLs de uploads .jpg/.jpeg/.png (src, srcset, data-src, url() do CSS) pela versão .webp quando ela existe.
	public function replace_urls( string $html ): string {
		$uploads = wp_get_upload_dir();

		if ( ! empty( $uploads['error'] ) || empty( $uploads['baseurl'] ) || empty( $uploads['basedir'] ) ) {
			return $html;
		}

		$base_url = (string) preg_replace( '#^https?:#i', '', untrailingslashit( $uploads['baseurl'] ) );
		$base_dir = trailingslashit( wp_normalize_path( $uploads['basedir'] ) );

		// (http:|https:)?//host/.../uploads/ + caminho sem aspas, espaços, parênteses, "?" ou "#" + extensão, seguido de um delimitador.
		$pattern = '#(?:https?:)?' . preg_quote( $base_url, '#' ) . '/([^\s"\'()<>?\#]+?\.(?:jpe?g|png))(?=[\s"\'()<>?\#,]|&quot;|&\#039;|$)#i';

		return (string) preg_replace_callback(
			$pattern,
			function ( array $matches ) use ( $base_dir ) {
				$relative = rawurldecode( $matches[1] );

				if ( false !== strpos( $relative, '..' ) || false !== strpos( $relative, "\0" ) ) {
					return $matches[0];
				}

				$webp = $base_dir . ltrim( $relative, '/' ) . self::WEBP_SUFFIX;

				if ( ! isset( $this->webp_exists[ $webp ] ) ) {
					$this->webp_exists[ $webp ] = is_file( $webp );
				}

				return $this->webp_exists[ $webp ] ? $matches[0] . self::WEBP_SUFFIX : $matches[0];
			},
			$html
		);
	}

	// Retorna tamanhos em bytes; o WebP é apagado quando não fica menor que o original.
	private function convert_file( string $file ) {
		$original = (int) filesize( $file );
		$webp     = $file . self::WEBP_SUFFIX;
		$editor   = $this->get_editor( $file );

		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		$editor->maybe_exif_rotate();

		$saved = $editor->save_webp_lossless( $webp );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		clearstatcache( true, $webp );

		$size = is_file( $webp ) ? (int) filesize( $webp ) : 0;

		if ( $size < 1 || $size >= $original ) {
			if ( is_file( $webp ) ) {
				wp_delete_file( $webp );
			}

			return array(
				'original'  => $original,
				'optimized' => $original,
				'kept'      => false,
			);
		}

		return array(
			'original'  => $original,
			'optimized' => $size,
			'kept'      => true,
		);
	}

	// Usa a fábrica do WordPress (wp_get_image_editor) restrita ao editor lossless.
	private function get_editor( string $file ) {
		require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
		require_once ABSPATH . WPINC . '/class-wp-image-editor-gd.php';

		if ( ! WebP_Editor_GD::supports_lossless() ) {
			return new \WP_Error( 'o2r_webp_unsupported', __( 'o GD deste servidor não gera WebP lossless (requer PHP 8.1+ com suporte a WebP)', 'o2-rocket' ) );
		}

		$only_lossless = static function () {
			return array( WebP_Editor_GD::class );
		};

		add_filter( 'wp_image_editors', $only_lossless, PHP_INT_MAX );
		$editor = wp_get_image_editor( $file );
		remove_filter( 'wp_image_editors', $only_lossless, PHP_INT_MAX );

		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		if ( ! $editor instanceof WebP_Editor_GD ) {
			return new \WP_Error( 'o2r_webp_editor', __( 'editor de imagem indisponível', 'o2-rocket' ) );
		}

		return $editor;
	}

	private function save_result( array $result ): array {
		$id = (int) $result['id'];

		update_post_meta( $id, self::META_STATUS, $result['status'] );
		update_post_meta( $id, self::META_ORIGINAL, (int) $result['original'] );
		update_post_meta( $id, self::META_OPTIMIZED, (int) $result['optimized'] );

		if ( '' !== $result['error'] ) {
			update_post_meta( $id, self::META_ERROR, sanitize_text_field( $result['error'] ) );
		} else {
			delete_post_meta( $id, self::META_ERROR );
		}

		return $result;
	}
}
