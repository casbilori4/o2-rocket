<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Media;

defined( 'ABSPATH' ) || exit;

// O WP_Image_Editor_GD do núcleo limita a qualidade a 1-100 (lossy) para origens JPEG/PNG; aqui o WebP sai em lossless real.
final class WebP_Editor_GD extends \WP_Image_Editor_GD {

	public static function supports_lossless(): bool {
		return function_exists( 'imagewebp' ) && defined( 'IMG_WEBP_LOSSLESS' );
	}

	public function save_webp_lossless( string $destination ) {
		if ( ! self::supports_lossless() ) {
			return new \WP_Error( 'o2r_webp_unsupported', __( 'o GD deste servidor não gera WebP lossless (requer PHP 8.1+ com suporte a WebP)', 'o2-rocket' ) );
		}

		if ( ! $this->image ) {
			return new \WP_Error( 'o2r_webp_not_loaded', __( 'a imagem não foi carregada', 'o2-rocket' ) );
		}

		// Paleta (PNG-8) vira truecolor e o canal alfa é preservado.
		if ( ! imageistruecolor( $this->image ) ) {
			imagepalettetotruecolor( $this->image );
		}

		imagealphablending( $this->image, false );
		imagesavealpha( $this->image, true );

		if ( ! $this->make_image( $destination, 'imagewebp', array( $this->image, $destination, IMG_WEBP_LOSSLESS ) ) ) {
			return new \WP_Error( 'o2r_webp_save_failed', __( 'não foi possível gravar o arquivo WebP', 'o2-rocket' ) );
		}

		// Mesmas permissões que o núcleo aplica às imagens geradas.
		$stat = stat( dirname( $destination ) );

		if ( is_array( $stat ) ) {
			chmod( $destination, $stat['mode'] & 0000666 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		}

		return true;
	}
}
