<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Optimization;

use O2Rocket\Core\Request;

defined( 'ABSPATH' ) || exit;

final class File_Optimizer {

	const SETTING_REMOVE_QUERY_STRINGS = 'file_remove_query_strings';
	const SETTING_DEFER_JS             = 'file_defer_js';

	const HOOK_PRIORITY = 999;

	// Handles do jQuery (núcleo, alias e migrate): nunca recebem defer.
	const DEFER_EXCLUDED_HANDLES = array(
		'jquery',
		'jquery-core',
		'jquery-migrate',
	);

	// Arquivos de jQuery pelo nome, para temas que trocam o handle ou usam CDN (jquery.min.js, jquery-3.7.1.min.js, jquery.slim.js...).
	const DEFER_EXCLUDED_SRC_PATTERNS = array(
		'#/wp-includes/js/jquery/jquery(?:\.min)?\.js$#i',
		'#/wp-includes/js/jquery/jquery-migrate(?:\.min)?\.js$#i',
		'#(?:^|/)jquery(?:[.-]\d+(?:\.\d+)*)?(?:\.slim)?(?:\.min)?\.js$#i',
		'#(?:^|/)jquery-migrate(?:[.-]\d+(?:\.\d+)*)?(?:\.min)?\.js$#i',
	);

	private ?array $settings = null;

	private ?array $dependents = null;

	private int $dependents_registered = -1;

	private array $deferrable = array();

	public function register_hooks(): void {
		if ( is_admin() ) {
			return;
		}

		if ( $this->is_enabled( self::SETTING_REMOVE_QUERY_STRINGS ) ) {
			add_filter( 'style_loader_src', array( $this, 'filter_style_src' ), self::HOOK_PRIORITY, 2 );
			add_filter( 'script_loader_src', array( $this, 'filter_script_src' ), self::HOOK_PRIORITY, 2 );
		}

		if ( $this->is_enabled( self::SETTING_DEFER_JS ) ) {
			add_filter( 'script_loader_tag', array( $this, 'filter_script_tag' ), self::HOOK_PRIORITY, 2 );
		}
	}

	public function get_settings(): array {
		if ( null === $this->settings ) {
			$stored         = get_option( O2_ROCKET_OPTION, array() );
			$this->settings = wp_parse_args(
				is_array( $stored ) ? $stored : array(),
				array(
					self::SETTING_REMOVE_QUERY_STRINGS => false,
					self::SETTING_DEFER_JS             => false,
				)
			);
		}

		return $this->settings;
	}

	public function is_enabled( string $setting ): bool {
		$settings = $this->get_settings();

		return isset( $settings[ $setting ] ) && rest_sanitize_boolean( $settings[ $setting ] );
	}

	public function filter_style_src( $src, $handle = '' ) {
		return $this->remove_version( $src, (string) $handle, wp_styles() );
	}

	public function filter_script_src( $src, $handle = '' ) {
		return $this->remove_version( $src, (string) $handle, wp_scripts() );
	}

	// O $tag pode trazer scripts inline (before/after/traduções): só a tag com src recebe defer.
	public function filter_script_tag( $tag, $handle = '' ) {
		if ( ! is_string( $tag ) || '' === $tag || ! $this->can_optimize() ) {
			return $tag;
		}

		if ( ! $this->is_deferrable( (string) $handle ) ) {
			return $tag;
		}

		return (string) preg_replace_callback(
			'#<script\b[^>]*\ssrc\s*=[^>]*>#i',
			array( $this, 'add_defer_attribute' ),
			$tag,
			1
		);
	}

	public function can_optimize(): bool {
		return Request::is_public_frontend() && (bool) apply_filters( 'o2_rocket_can_optimize_files', true );
	}

	// Remove só o "ver" que o próprio WordPress acrescentou; o resto da URL fica byte a byte igual.
	public function remove_version( $src, string $handle, \WP_Dependencies $dependencies ) {
		if ( ! is_string( $src ) || false === strpos( $src, '?' ) || '' === $handle || ! $this->can_optimize() ) {
			return $src;
		}

		$expected = $this->get_appended_version( $handle, $dependencies );

		if ( null === $expected ) {
			return $src;
		}

		$fragment      = '';
		$fragment_from = strpos( $src, '#' );

		if ( false !== $fragment_from ) {
			$fragment = substr( $src, $fragment_from );
			$src      = substr( $src, 0, $fragment_from );
		}

		$query_from = strpos( $src, '?' );

		if ( false === $query_from ) {
			return $src . $fragment;
		}

		$base  = substr( $src, 0, $query_from );
		$pairs = array_values( array_filter( explode( '&', substr( $src, $query_from + 1 ) ), 'strlen' ) );

		// O WordPress acrescenta o ver por último: procura de trás para frente e remove só essa ocorrência.
		for ( $index = count( $pairs ) - 1; $index >= 0; $index-- ) {
			$parts = explode( '=', $pairs[ $index ], 2 );

			if ( 'ver' === strtolower( rawurldecode( $parts[0] ) ) && isset( $parts[1] ) && rawurldecode( $parts[1] ) === $expected ) {
				array_splice( $pairs, $index, 1 );

				return $base . ( empty( $pairs ) ? '' : '?' . implode( '&', $pairs ) ) . $fragment;
			}
		}

		return $src . $fragment;
	}

	// Espelha WP_Scripts::do_item() e WP_Styles::_css_href(): null = sem ver; vazio/false = versão do WordPress.
	public function get_appended_version( string $handle, \WP_Dependencies $dependencies ): ?string {
		if ( ! isset( $dependencies->registered[ $handle ] ) ) {
			return null;
		}

		// Args legados ("handle?x=y" no enqueue) já foram concatenados ao ver em versões antigas do WP: não mexe.
		if ( isset( $dependencies->args[ $handle ] ) && '' !== (string) $dependencies->args[ $handle ] ) {
			return null;
		}

		$ver = $dependencies->registered[ $handle ]->ver;

		if ( empty( $ver ) && null !== $ver && is_string( $dependencies->default_version ) ) {
			$version = $dependencies->default_version;
		} elseif ( is_scalar( $ver ) ) {
			$version = (string) $ver;
		} else {
			return null;
		}

		return '' === $version ? null : $version;
	}

	// Defer só se: não for jQuery/excluído, não tiver inline (before/after/traduções) e todos os dependentes também puderem ser adiados.
	// A última regra propaga a exclusão às dependências: se um script síncrono depende de X, X continua síncrono.
	public function is_deferrable( string $handle ): bool {
		if ( '' === $handle ) {
			return false;
		}

		$this->refresh_dependents_map();

		if ( isset( $this->deferrable[ $handle ] ) ) {
			return $this->deferrable[ $handle ];
		}

		// Marca antes de recursar: ciclos de dependência resultam em "não adiar".
		$this->deferrable[ $handle ] = false;

		$scripts = wp_scripts();

		if ( ! isset( $scripts->registered[ $handle ] ) || ! $this->is_self_deferrable( $handle, $scripts ) ) {
			return false;
		}

		foreach ( $this->get_dependents( $handle ) as $dependent ) {
			if ( ! $this->is_deferrable( $dependent ) ) {
				return false;
			}
		}

		$this->deferrable[ $handle ] = true;

		return true;
	}

	public function is_excluded_from_defer( string $handle, string $src ): bool {
		$handles = (array) apply_filters( 'o2_rocket_defer_js_excluded_handles', self::DEFER_EXCLUDED_HANDLES );

		if ( in_array( $handle, array_map( 'strval', $handles ), true ) ) {
			return true;
		}

		if ( '' === $src ) {
			return false;
		}

		$path     = wp_parse_url( $src, PHP_URL_PATH );
		$path     = is_string( $path ) ? $path : $src;
		$patterns = (array) apply_filters( 'o2_rocket_defer_js_excluded_patterns', self::DEFER_EXCLUDED_SRC_PATTERNS );

		foreach ( $patterns as $pattern ) {
			if ( is_string( $pattern ) && '' !== $pattern && 1 === preg_match( $pattern, $path ) ) {
				return true;
			}
		}

		return false;
	}

	private function is_self_deferrable( string $handle, \WP_Scripts $scripts ): bool {
		$item = $scripts->registered[ $handle ];
		$src  = is_string( $item->src ) ? $item->src : '';

		if ( $this->is_excluded_from_defer( $handle, $src ) ) {
			return false;
		}

		foreach ( array( 'before', 'after' ) as $position ) {
			$inline = $scripts->get_data( $handle, $position );

			if ( is_array( $inline ) ? array_filter( $inline ) : ! empty( $inline ) ) {
				return false;
			}
		}

		if ( ! empty( $item->textdomain ) || ! empty( $scripts->get_data( $handle, 'conditional' ) ) ) {
			return false;
		}

		return (bool) apply_filters( 'o2_rocket_defer_js_script', true, $handle, $src );
	}

	// Mapa reverso "handle => quem depende dele"; refeito (junto com o cache de decisões) se novos scripts forem registrados.
	private function refresh_dependents_map(): void {
		$scripts = wp_scripts();
		$count   = count( $scripts->registered );

		if ( null !== $this->dependents && $count === $this->dependents_registered ) {
			return;
		}

		$this->dependents            = array();
		$this->dependents_registered = $count;
		$this->deferrable            = array();

		foreach ( $scripts->registered as $name => $item ) {
			foreach ( (array) $item->deps as $dependency ) {
				$this->dependents[ $dependency ][] = (string) $name;
			}
		}
	}

	private function get_dependents( string $handle ): array {
		return isset( $this->dependents[ $handle ] ) ? $this->dependents[ $handle ] : array();
	}

	private function add_defer_attribute( array $matches ): string {
		$tag = $matches[0];

		// Já adiado/assíncrono, módulo (adiado por padrão) ou marcado para não otimizar.
		if ( 1 === preg_match( '#\s(?:defer|async|data-no-optimize|data-no-defer)(?:\s|=|>|/)#i', $tag ) ) {
			return $tag;
		}

		if ( 1 === preg_match( '#\stype\s*=\s*(["\']?)([^"\'\s>]+)\1#i', $tag, $type ) ) {
			$mime = strtolower( $type[2] );

			if ( ! in_array( $mime, array( 'text/javascript', 'application/javascript', 'text/ecmascript', 'application/ecmascript' ), true ) ) {
				return $tag;
			}
		}

		return (string) preg_replace( '#^<script\b#i', '<script defer="defer"', $tag, 1 );
	}
}
