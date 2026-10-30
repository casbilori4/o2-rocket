<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Optimization;

defined( 'ABSPATH' ) || exit;

// CDN: troca o domínio das URLs de arquivos estáticos (wp-content, wp-includes, uploads) pelo da CDN, no HTML todo
// (src, srcset, href, url() do CSS em linha, data-*). Vários endereços: um por tipo de arquivo ou divididos entre si.
final class Cdn {

	const SETTING_ENABLED  = 'cdn_enabled';
	const SETTING_URLS     = 'cdn_urls';
	const SETTING_EXCLUDED = 'cdn_excluded';

	const PIPELINE_PRIORITY = 90;

	const ZONES = array(
		'all'    => array(),
		'images' => array( 'jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp', 'tif', 'tiff' ),
		'css'    => array( 'css' ),
		'js'     => array( 'js', 'mjs' ),
		'fonts'  => array( 'woff', 'woff2', 'ttf', 'otf', 'eot' ),
		'media'  => array( 'mp4', 'webm', 'ogg', 'ogv', 'mp3', 'm4a', 'wav', 'pdf' ),
	);

	// Nunca vão para a CDN: código executado no servidor e documentos.
	const SKIP_EXTENSIONS = array( 'php', 'html', 'htm', 'xml', 'json', 'txt', 'cfm', 'asp', 'aspx' );

	private ?array $settings = null;

	private ?array $cdn_urls = null;

	public function register_hooks(): void {
		add_filter( 'o2_rocket_htaccess_rules', array( $this, 'add_cors_rules' ) );

		if ( is_admin() || ! $this->is_enabled() ) {
			return;
		}

		add_filter( Html_Pipeline::FILTER, array( $this, 'process_html' ), self::PIPELINE_PRIORITY );
		// Cópias minificadas são refeitas a cada limpeza total (inclusive ao salvar as configurações).
		add_filter( 'o2_rocket_minified_css', array( $this, 'process_css' ), 20 );
	}

	public function get_settings(): array {
		if ( null === $this->settings ) {
			$stored         = get_option( O2_ROCKET_OPTION, array() );
			$this->settings = wp_parse_args(
				is_array( $stored ) ? $stored : array(),
				array(
					self::SETTING_ENABLED  => false,
					self::SETTING_URLS     => array(),
					self::SETTING_EXCLUDED => array(),
				)
			);
		}

		return $this->settings;
	}

	public function is_enabled(): bool {
		return rest_sanitize_boolean( $this->get_settings()[ self::SETTING_ENABLED ] ) && ! empty( $this->get_cdn_urls() );
	}

	// [ [ 'url' => 'https://cdn.x.com', 'zone' => 'all' ], ... ].
	public function get_cdn_urls(): array {
		if ( null !== $this->cdn_urls ) {
			return $this->cdn_urls;
		}

		$this->cdn_urls = array();

		foreach ( self::sanitize_urls( $this->get_settings()[ self::SETTING_URLS ] ) as $line ) {
			$parts = preg_split( '/\s+/', $line );

			$this->cdn_urls[] = array(
				'url'  => untrailingslashit( $parts[0] ),
				'zone' => isset( $parts[1] ) && isset( self::ZONES[ $parts[1] ] ) ? $parts[1] : 'all',
			);
		}

		return $this->cdn_urls;
	}

	// Linha: "https://cdn.exemplo.com" ou "https://img.exemplo.com images". O domínio sem esquema ganha https.
	public static function sanitize_urls( $value ): array {
		$lines = is_array( $value ) ? $value : preg_split( '/\r\n|\r|\n/', is_scalar( $value ) ? (string) $value : '' );
		$clean = array();

		foreach ( (array) $lines as $line ) {
			$parts = preg_split( '/\s+/', trim( is_scalar( $line ) ? wp_strip_all_tags( (string) $line ) : '' ) );
			$url   = isset( $parts[0] ) ? (string) $parts[0] : '';

			if ( '' === $url ) {
				continue;
			}

			if ( 0 === strpos( $url, '//' ) ) {
				$url = 'https:' . $url;
			} elseif ( ! preg_match( '#^https?://#i', $url ) ) {
				$url = 'https://' . $url;
			}

			$url  = untrailingslashit( esc_url_raw( $url, array( 'http', 'https' ) ) );
			$zone = isset( $parts[1] ) && isset( self::ZONES[ strtolower( $parts[1] ) ] ) ? strtolower( $parts[1] ) : 'all';

			if ( '' !== $url && false !== wp_parse_url( $url, PHP_URL_HOST ) ) {
				$clean[] = $url . ( 'all' === $zone ? '' : ' ' . $zone );
			}
		}

		return array_slice( array_values( array_unique( $clean ) ), 0, 20 );
	}

	public function process_html( $html ) {
		if ( ! is_string( $html ) || '' === $html || ! (bool) apply_filters( 'o2_rocket_cdn_enabled', true ) ) {
			return $html;
		}

		// Ficam de fora: JavaScript em linha (URLs montadas em código, exceto o mapa de fundos do lazy load) e módulos ES,
		// que de outro domínio só carregam se a CDN mandar CORS (o mapa de importação aponta para o próprio site).
		$protected = array();
		$protect   = static function ( $matches ) use ( &$protected ) {
			$tag = $matches[0];

			if ( 0 === stripos( $tag, '<script' ) ) {
				$opening = substr( $tag, 0, (int) strpos( $tag, '>' ) + 1 );
				$module  = preg_match( '#\s(?:data-o2r-)?type\s*=\s*["\']?module\b#i', $opening );

				if ( ! $module && ( false !== strpos( $opening, 'id="o2r-lazy-bg"' ) || preg_match( '#\s(?:data-o2r-)?src\s*=#i', $opening ) ) ) {
					return $tag;
				}
			}

			$key               = '<!--o2r-cdn-' . count( $protected ) . '-->';
			$protected[ $key ] = $tag;

			return $key;
		};

		$original = $html;
		$html     = preg_replace_callback( '#<script\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>.*?</script\s*>#is', $protect, $html );
		$html     = is_string( $html ) ? preg_replace_callback(
			'#<link\b[^>]*>#i',
			static function ( $matches ) use ( $protect ) {
				return preg_match( '#\srel\s*=\s*["\']?modulepreload\b#i', $matches[0] ) ? $protect( $matches ) : $matches[0];
			},
			$html
		) : null;

		if ( ! is_string( $html ) ) {
			return $original;
		}

		$html = strtr( $this->rewrite_urls( $html ), $protected );

		return $this->add_preconnect( $html );
	}

	public function process_css( $css ) {
		return is_string( $css ) && '' !== $css && (bool) apply_filters( 'o2_rocket_cdn_enabled', true ) ? $this->rewrite_urls( $css ) : $css;
	}

	private function rewrite_urls( string $text ): string {
		$host  = $this->get_home_host();
		$paths = implode( '|', array_map( static function ( $path ) {
			return preg_quote( ltrim( $path, '/' ), '#' );
		}, $this->get_allowed_paths() ) );

		// Absolutas (http://, https://, //) do host do site ou relativas à raiz, seguidas de um delimitador.
		$pattern = '#(?<=["\'(\s,=])((?:https?:)?//' . preg_quote( $host, '#' ) . ')?/((?:' . $paths . ')[^"\'\s,()<>]*?\.([a-z0-9]{2,5}))(?=[?\#"\'\s,()<>]|&quot;|&\#039;)#i';

		$result = preg_replace_callback(
			$pattern,
			function ( $matches ) {
				$extension = strtolower( $matches[3] );
				$path      = '/' . $matches[2];

				if ( in_array( $extension, self::SKIP_EXTENSIONS, true ) || $this->is_excluded( $path ) ) {
					return $matches[0];
				}

				$cdn = $this->pick_cdn( $path, $extension );

				return '' === $cdn ? $matches[0] : $cdn . $path;
			},
			$text
		);

		return is_string( $result ) ? $result : $text;
	}

	// Arquivos de fonte vindos da CDN precisam de CORS liberado na origem.
	public function add_cors_rules( $rules ) {
		$rules  = is_array( $rules ) ? $rules : array();
		$stored = get_option( O2_ROCKET_OPTION, array() );

		// Lido na hora: as regras são gravadas no mesmo salvamento que liga a CDN.
		if ( ! is_array( $stored ) || empty( $stored[ self::SETTING_ENABLED ] ) || empty( $stored[ self::SETTING_URLS ] ) ) {
			return $rules;
		}

		$rules[] = '# Fontes servidas pela CDN (CORS)';
		$rules[] = '<IfModule mod_headers.c>';
		$rules[] = '	<FilesMatch "\.(?:eot|otf|tt[cf]|woff2?|svg)$">';
		$rules[] = '		Header set Access-Control-Allow-Origin "*"';
		$rules[] = '	</FilesMatch>';
		$rules[] = '</IfModule>';

		return $rules;
	}

	// Mesmo arquivo sempre no mesmo endereço (crc32 do caminho): o cache do navegador não se divide.
	private function pick_cdn( string $path, string $extension ): string {
		$candidates = array();

		foreach ( $this->get_cdn_urls() as $cdn ) {
			if ( 'all' !== $cdn['zone'] && in_array( $extension, self::ZONES[ $cdn['zone'] ], true ) ) {
				$candidates[] = $cdn['url'];
			}
		}

		// Sem endereço específico para o tipo: os "all".
		if ( empty( $candidates ) ) {
			foreach ( $this->get_cdn_urls() as $cdn ) {
				if ( 'all' === $cdn['zone'] ) {
					$candidates[] = $cdn['url'];
				}
			}
		}

		if ( empty( $candidates ) ) {
			return '';
		}

		return $candidates[ abs( crc32( $path ) ) % count( $candidates ) ];
	}

	private function is_excluded( string $path ): bool {
		foreach ( (array) $this->get_settings()[ self::SETTING_EXCLUDED ] as $pattern ) {
			$pattern = trim( (string) $pattern );

			if ( '' !== $pattern && false !== stripos( $path, $pattern ) ) {
				return true;
			}
		}

		return (bool) apply_filters( 'o2_rocket_cdn_exclude', false, $path );
	}

	// Pastas de arquivos estáticos (relativas à raiz do domínio).
	private function get_allowed_paths(): array {
		$paths   = array(
			(string) wp_parse_url( content_url( '/' ), PHP_URL_PATH ),
			(string) wp_parse_url( includes_url( '/' ), PHP_URL_PATH ),
		);
		$uploads = wp_get_upload_dir();

		if ( empty( $uploads['error'] ) && ! empty( $uploads['baseurl'] ) ) {
			$paths[] = trailingslashit( (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH ) );
		}

		return array_values( array_unique( array_filter( (array) apply_filters( 'o2_rocket_cdn_paths', $paths ) ) ) );
	}

	private function get_home_host(): string {
		$parts = wp_parse_url( home_url() );

		return is_array( $parts ) && ! empty( $parts['host'] ) ? $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) : '';
	}

	private function add_preconnect( string $html ): string {
		$tags = '';

		foreach ( array_unique( array_column( $this->get_cdn_urls(), 'url' ) ) as $url ) {
			if ( false === strpos( $html, 'rel="preconnect" href="' . $url . '"' ) ) {
				$tags .= '<link rel="preconnect" href="' . esc_url( $url ) . '" crossorigin>';
			}
		}

		if ( '' === $tags || ! preg_match( '#<meta\s[^>]*charset\s*=[^>]*>#i', $html, $match, PREG_OFFSET_CAPTURE ) && ! preg_match( '#<head\b[^>]*>#i', $html, $match, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}

		return substr_replace( $html, $tags, $match[0][1] + strlen( $match[0][0] ), 0 );
	}
}
