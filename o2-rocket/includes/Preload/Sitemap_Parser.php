<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Preload;

defined( 'ABSPATH' ) || exit;

// Lê sitemaps XML (índice e lista de URLs) sem depender de SimpleXML/DOM.
final class Sitemap_Parser {

	const MAX_BYTES = 52428800;

	// Sitemaps do WordPress, Yoast, Rank Math, SEOPress, All in One SEO e The SEO Framework.
	public static function discover(): array {
		$sitemaps = array();

		if ( self::is_yoast_enabled() && class_exists( 'WPSEO_Sitemaps_Router' ) ) {
			$sitemaps[] = \WPSEO_Sitemaps_Router::get_base_url( 'sitemap_index.xml' );
		} elseif ( class_exists( 'RankMath\Helper' ) && \RankMath\Helper::is_module_active( 'sitemap' ) && class_exists( 'RankMath\Sitemap\Router' ) ) {
			$sitemaps[] = \RankMath\Sitemap\Router::get_base_url( 'sitemap_index.xml' );
		} elseif ( function_exists( 'seopress_get_toggle_option' ) && 1 === (int) seopress_get_toggle_option( 'xml-sitemap' ) ) {
			$sitemaps[] = home_url( '/sitemaps.xml' );
		} elseif ( defined( 'AIOSEO_VERSION' ) && function_exists( 'aioseo' ) && ! empty( aioseo()->options->sitemap->general->enable ) ) {
			$sitemaps[] = trailingslashit( home_url() ) . apply_filters( 'aioseo_sitemap_filename', 'sitemap' ) . '.xml'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		} elseif ( function_exists( 'the_seo_framework' ) && method_exists( the_seo_framework(), 'get_sitemap_xml_url' ) && method_exists( the_seo_framework(), 'can_run_sitemap' ) && the_seo_framework()->can_run_sitemap() ) {
			$sitemaps[] = the_seo_framework()->get_sitemap_xml_url();
		} elseif ( function_exists( 'get_sitemap_url' ) ) {
			$core = get_sitemap_url( 'index' );

			if ( is_string( $core ) && '' !== $core ) {
				$sitemaps[] = $core;
			}
		}

		return array_values( array_unique( array_filter( (array) apply_filters( 'o2_rocket_preload_sitemaps', $sitemaps ), 'is_string' ) ) );
	}

	// Baixa o sitemap pelo próprio site. Retorna [ 'urls' => [], 'sitemaps' => [] ] ou WP_Error.
	public static function fetch( string $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 30,
				'redirection'         => 3,
				'sslverify'           => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				'user-agent'          => Preloader::USER_AGENT . '/' . O2_ROCKET_VERSION,
				'limit_response_size' => self::MAX_BYTES,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			/* translators: 1: URL do sitemap, 2: código HTTP. */
			return new \WP_Error( 'o2r_sitemap_status', sprintf( __( 'o sitemap %1$s respondeu com HTTP %2$d', 'o2-rocket' ), $url, $code ) );
		}

		$body = (string) wp_remote_retrieve_body( $response );

		// Sitemap .xml.gz servido como arquivo (sem Content-Encoding).
		if ( "\x1f\x8b" === substr( $body, 0, 2 ) && function_exists( 'gzdecode' ) ) {
			$decoded = gzdecode( $body );
			$body    = is_string( $decoded ) ? $decoded : '';
		}

		return self::parse( $body );
	}

	public static function parse( string $xml ): array {
		$result = array(
			'urls'     => array(),
			'sitemaps' => array(),
		);

		if ( '' === $xml ) {
			return $result;
		}

		$is_index = 1 === preg_match( '#<(?:[a-z0-9]+:)?sitemapindex[\s>]#i', $xml );
		$key      = $is_index ? 'sitemaps' : 'urls';
		$block    = $is_index ? 'sitemap' : 'url';

		if ( ! preg_match_all( '#<(?:[a-z0-9]+:)?' . $block . '\b[^>]*>(.*?)</(?:[a-z0-9]+:)?' . $block . '\s*>#is', $xml, $blocks ) ) {
			return $result;
		}

		foreach ( $blocks[1] as $content ) {
			if ( ! preg_match( '#<(?:[a-z0-9]+:)?loc\b[^>]*>\s*(?:<!\[CDATA\[)?\s*(.*?)\s*(?:\]\]>)?\s*</(?:[a-z0-9]+:)?loc\s*>#is', $content, $loc ) ) {
				continue;
			}

			$url = trim( html_entity_decode( $loc[1], ENT_QUOTES | ENT_XML1, 'UTF-8' ) );

			if ( '' !== $url && 1 === preg_match( '#^https?://#i', $url ) && false !== filter_var( $url, FILTER_VALIDATE_URL ) ) {
				$result[ $key ][] = $url;
			}
		}

		$result[ $key ] = array_values( array_unique( $result[ $key ] ) );

		return $result;
	}

	private static function is_yoast_enabled(): bool {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			return false;
		}

		$options = get_option( 'wpseo', array() );

		return is_array( $options ) && ! empty( $options['enable_xml_sitemap'] );
	}
}
