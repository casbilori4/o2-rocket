<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Admin;

use O2Rocket\Cache\Cache_Controls;
use O2Rocket\Cache\Page_Cache;
use O2Rocket\Optimization\Html_Pipeline;

defined( 'ABSPATH' ) || exit;

// Caixa "O² Rocket" no editor: nunca fazer cache desta página e desligar otimizações só nela (meta _o2_rocket_exclude).
// Fica no post, não na lista global de exclusões: salvar não limpa o cache do site inteiro, só o desta página.
final class Post_Options {

	const NONCE = 'o2_rocket_post_options';
	const FIELD = 'o2_rocket_exclude';

	private Page_Cache $page_cache;

	public function __construct( Page_Cache $page_cache ) {
		$this->page_cache = $page_cache;
	}

	public function register_hooks(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post', array( $this, 'save' ), 20, 2 );
	}

	public static function get_choices(): array {
		return array(
			'cache'        => __( 'Nunca fazer cache desta página', 'o2-rocket' ),
			'all'          => __( 'Desligar todas as otimizações', 'o2-rocket' ),
			'minify'       => __( 'Minificação de CSS e JS', 'o2-rocket' ),
			'css_delivery' => __( 'CSS não utilizado / crítico', 'o2-rocket' ),
			'defer_js'     => __( 'Adiar JavaScript (defer)', 'o2-rocket' ),
			'delay_js'     => __( 'Atrasar JavaScript', 'o2-rocket' ),
			'lazyload'     => __( 'Lazy load (imagens, iframes, fundos)', 'o2-rocket' ),
			'lcp'          => __( 'Otimização da LCP', 'o2-rocket' ),
			'lazy_render'  => __( 'Renderização sob demanda', 'o2-rocket' ),
			'cdn'          => __( 'CDN', 'o2-rocket' ),
		);
	}

	public function add_meta_box(): void {
		if ( ! current_user_can( Cache_Controls::get_capability() ) ) {
			return;
		}

		$types = get_post_types( array( 'public' => true ) );

		unset( $types['attachment'] );

		foreach ( (array) apply_filters( 'o2_rocket_post_options_types', $types ) as $type ) {
			add_meta_box( 'o2-rocket-post-options', 'O² Rocket', array( $this, 'render' ), $type, 'side', 'low' );
		}
	}

	public function render( $post ): void {
		$current = $post instanceof \WP_Post ? get_post_meta( $post->ID, Html_Pipeline::POST_META, true ) : array();
		$current = is_array( $current ) ? $current : array();

		wp_nonce_field( self::NONCE, self::NONCE );

		echo '<input type="hidden" name="' . esc_attr( self::FIELD ) . '[]" value="">';

		foreach ( self::get_choices() as $key => $label ) {
			if ( 'all' === $key ) {
				printf( '<p style="margin:12px 0 4px"><strong>%s</strong></p>', esc_html__( 'Só nesta página:', 'o2-rocket' ) );
			}

			printf(
				'<p style="margin:4px 0"><label><input type="checkbox" name="%1$s[]" value="%2$s"%3$s> %4$s</label></p>',
				esc_attr( self::FIELD ),
				esc_attr( $key ),
				checked( in_array( $key, $current, true ), true, false ),
				esc_html( 'cache' === $key ? $label : sprintf( /* translators: %s: nome da otimização. */ __( 'Desligar: %s', 'o2-rocket' ), $label ) )
			);
		}

		printf( '<p class="description">%s</p>', esc_html__( 'As otimizações desligadas aqui continuam valendo no resto do site.', 'o2-rocket' ) );
	}

	public function save( $post_id, $post ): void {
		if ( ! isset( $_POST[ self::NONCE ], $_POST[ self::FIELD ] ) || ! $post instanceof \WP_Post ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		if ( ! current_user_can( Cache_Controls::get_capability() ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		$selected = array_values( array_intersect( array_keys( self::get_choices() ), array_map( 'sanitize_key', (array) wp_unslash( $_POST[ self::FIELD ] ) ) ) );
		$previous = get_post_meta( $post->ID, Html_Pipeline::POST_META, true );

		if ( empty( $selected ) ) {
			delete_post_meta( $post->ID, Html_Pipeline::POST_META );
		} else {
			update_post_meta( $post->ID, Html_Pipeline::POST_META, $selected );
		}

		// A cópia em cache foi gerada com as opções antigas.
		if ( $selected !== $previous && 'publish' === $post->post_status ) {
			$permalink = get_permalink( $post );

			if ( $permalink ) {
				$this->page_cache->purge_url( $permalink );
			}
		}
	}
}
