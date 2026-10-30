<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\ThirdParty;

defined( 'ABSPATH' ) || exit;

// Hospedagens gerenciadas: detecta o ambiente, limpa o cache do servidor junto com o do plugin, liga o Varnish onde
// ele existe e desliga o cache de página do plugin onde a hospedagem já tem um no servidor (as otimizações continuam).
final class Hosting {

	// Hospedagens com cache de página no servidor: o drop-in do plugin só duplicaria o trabalho.
	const SERVER_PAGE_CACHE = array( 'wpengine', 'kinsta', 'pressable', 'wordpresscom', 'savvii', 'spinupwp', 'pressidium', 'flywheel', 'presslabs' );

	// Hospedagens com Varnish na frente do site: a limpeza vai para ele sozinha.
	const VARNISH = array( 'cloudways', 'o2switch', 'wpxcloud', 'dreampress', 'flywheel', 'wpserveur', 'onecom', 'proisp' );

	const NAMES = array(
		'wpengine'     => 'WP Engine',
		'kinsta'       => 'Kinsta',
		'pressable'    => 'Pressable',
		'wordpresscom' => 'WordPress.com',
		'savvii'       => 'Savvii',
		'spinupwp'     => 'SpinupWP',
		'pressidium'   => 'Pressidium',
		'godaddy'      => 'GoDaddy (Managed WordPress)',
		'cloudways'    => 'Cloudways',
		'o2switch'     => 'o2switch',
		'wpxcloud'     => 'WPX Cloud',
		'dreampress'   => 'DreamPress',
		'litespeed'    => 'LiteSpeed',
		'siteground'   => 'SiteGround',
		'onecom'       => 'One.com',
		'proisp'       => 'PRO ISP',
		'flywheel'     => 'Flywheel',
		'wpserveur'    => 'WP Serveur',
		'presslabs'    => 'Presslabs',
		'pagely'       => 'Pagely',
	);

	private ?string $host = null;

	public function register_hooks(): void {
		$host = $this->get_host();

		// Nginx Helper (cache FastCGI/Redis do Nginx) funciona em qualquer hospedagem.
		if ( defined( 'NGINX_HELPER_BASENAME' ) ) {
			add_action( 'o2_rocket_after_purge_all', array( $this, 'purge_nginx_helper' ) );
			add_action( 'o2_rocket_before_purge_url', array( $this, 'purge_nginx_helper_url' ) );
		}

		if ( '' === $host ) {
			return;
		}

		if ( in_array( $host, self::SERVER_PAGE_CACHE, true ) && (bool) apply_filters( 'o2_rocket_respect_host_cache', true, $host ) ) {
			add_filter( 'o2_rocket_page_cache_allowed', '__return_false' );
		}

		if ( in_array( $host, self::VARNISH, true ) && $this->has_active_varnish( $host ) ) {
			add_filter( 'o2_rocket_varnish_enabled', '__return_true' );
			add_filter( 'o2_rocket_varnish_hosts', array( $this, 'filter_varnish_hosts' ) );
			add_filter( 'o2_rocket_varnish_headers', array( $this, 'filter_varnish_headers' ) );
		}

		add_action( 'o2_rocket_after_purge_all', array( $this, 'purge_all' ) );
		add_action( 'o2_rocket_before_purge_url', array( $this, 'purge_url' ) );
	}

	// Identificador da hospedagem ('' quando não reconhecida). Mesmos sinais usados pelas próprias hospedagens.
	public function get_host(): string {
		if ( null !== $this->host ) {
			return $this->host;
		}

		$server = function ( string $key ): string {
			return isset( $_SERVER[ $key ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) : '';
		};

		$brand = strtolower( $server( 'GROUPONE_BRAND_NAME' ) );

		if ( 'one.com' === $brand ) {
			$host = 'onecom';
		} elseif ( 'proisp.no' === $brand ) {
			$host = 'proisp';
		} elseif ( class_exists( 'WpeCommon' ) && function_exists( 'wpe_param' ) ) {
			$host = 'wpengine';
		} elseif ( '' !== $server( 'KINSTA_CACHE_ZONE' ) ) {
			$host = 'kinsta';
		} elseif ( defined( 'IS_PRESSABLE' ) && IS_PRESSABLE ) {
			$host = 'pressable';
		} elseif ( defined( 'WPCOMSH_VERSION' ) ) {
			$host = 'wordpresscom';
		} elseif ( getenv( 'SPINUPWP_CACHE_PATH' ) ) {
			$host = 'spinupwp';
		} elseif ( defined( '\Savvii\CacheFlusherPlugin::NAME_FLUSH_NOW' ) ) {
			$host = 'savvii';
		} elseif ( defined( 'WP_NINUKIS_WP_NAME' ) || class_exists( 'NinukisCaching' ) ) {
			$host = 'pressidium';
		} elseif ( false !== strpos( $server( 'DOCUMENT_ROOT' ), '.cloudwaysapps.com/' ) || '' !== $server( 'cw_allowed_ip' ) ) {
			$host = 'cloudways';
		} elseif ( defined( 'O2SWITCH_VARNISH_PURGE_KEY' ) ) {
			$host = 'o2switch';
		} elseif ( '' !== $server( 'HTTP_WPXCLOUD' ) ) {
			$host = 'wpxcloud';
		} elseif ( '' !== $server( 'DH_USER' ) && 0 === strpos( sanitize_key( $server( 'DH_USER' ) ), 'wp_' ) && 0 === strpos( (string) gethostname(), 'dp-' ) ) {
			$host = 'dreampress';
		} elseif ( class_exists( 'FlywheelNginxCompat' ) ) {
			$host = 'flywheel';
		} elseif ( defined( 'PL_INSTANCE_REF' ) && class_exists( '\Presslabs\Cache\CacheHandler' ) ) {
			$host = 'presslabs';
		} elseif ( defined( 'DB_HOST' ) && false !== strpos( (string) DB_HOST, '.wpserveur.net' ) ) {
			$host = 'wpserveur';
		} elseif ( class_exists( 'PagelyCachePurge' ) ) {
			$host = 'pagely';
		} elseif ( class_exists( '\WPaaS\Plugin' ) ) {
			$host = 'godaddy';
		} elseif ( function_exists( 'sg_cachepress_purge_cache' ) || defined( 'SG_OPTIMIZER_VERSION' ) ) {
			$host = 'siteground';
		} elseif ( '' !== $server( 'X-LSCACHE' ) || false !== stripos( $server( 'SERVER_SOFTWARE' ), 'litespeed' ) ) {
			$host = 'litespeed';
		} else {
			$host = '';
		}

		$this->host = (string) apply_filters( 'o2_rocket_hosting', $host );

		return $this->host;
	}

	public function get_host_name(): string {
		$host = $this->get_host();

		return isset( self::NAMES[ $host ] ) ? self::NAMES[ $host ] : $host;
	}

	public function has_server_page_cache(): bool {
		return in_array( $this->get_host(), self::SERVER_PAGE_CACHE, true );
	}

	// One.com e PRO ISP: o Varnish só existe com o plugin da própria hospedagem ligado.
	private function has_active_varnish( string $host ): bool {
		if ( in_array( $host, array( 'onecom', 'proisp' ), true ) ) {
			return defined( 'vcaching' ) && rest_sanitize_boolean( get_option( 'varnish_caching_enable' ) );
		}

		return true;
	}

	public function filter_varnish_hosts( $hosts ): array {
		$hosts = (array) $hosts;

		if ( 'cloudways' === $this->get_host() ) {
			$hosts = array( '127.0.0.1:8080' );
		} elseif ( in_array( $this->get_host(), array( 'wpxcloud', 'flywheel' ), true ) ) {
			$hosts = array( '127.0.0.1' );
		}

		return $hosts;
	}

	// o2switch exige a chave de limpeza e o regex em cabeçalho próprio.
	public function filter_varnish_headers( $headers ): array {
		$headers = (array) $headers;

		if ( 'o2switch' === $this->get_host() && defined( 'O2SWITCH_VARNISH_PURGE_KEY' ) ) {
			$headers['X-VC-Purge-Key'] = (string) constant( 'O2SWITCH_VARNISH_PURGE_KEY' );

			if ( isset( $headers['X-Purge-Method'] ) && 'regex' === $headers['X-Purge-Method'] ) {
				$headers['X-Purge-Regex'] = '.*';
				unset( $headers['X-Purge-Method'] );
			}
		}

		return $headers;
	}

	public function purge_all(): void {
		switch ( $this->get_host() ) {
			case 'wpengine':
				if ( method_exists( 'WpeCommon', 'purge_memcached' ) ) {
					\WpeCommon::purge_memcached();
				}

				if ( method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) {
					\WpeCommon::purge_varnish_cache();
				}
				break;

			case 'kinsta':
				global $kinsta_cache;

				if ( is_object( $kinsta_cache ) && ! empty( $kinsta_cache->kinsta_cache_purge ) && method_exists( $kinsta_cache->kinsta_cache_purge, 'purge_complete_caches' ) ) {
					$kinsta_cache->kinsta_cache_purge->purge_complete_caches();
				}
				break;

			case 'pressable':
			case 'wordpresscom':
				wp_cache_flush();
				break;

			case 'spinupwp':
				if ( function_exists( 'spinupwp_purge_site' ) ) {
					spinupwp_purge_site();
				}
				break;

			case 'savvii':
				do_action( 'warpdrive_domain_flush' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				break;

			case 'pressidium':
				if ( class_exists( 'NinukisCaching' ) && method_exists( 'NinukisCaching', 'get_instance' ) ) {
					\NinukisCaching::get_instance()->purgeAllCaches();
				}
				break;

			case 'godaddy':
				$this->godaddy_request( 'BAN', home_url( '/' ) );
				break;

			case 'siteground':
				if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
					sg_cachepress_purge_cache();
				} elseif ( method_exists( '\SiteGround_Optimizer\Supercacher\Supercacher', 'purge_cache' ) ) {
					\SiteGround_Optimizer\Supercacher\Supercacher::purge_cache();
				}
				break;

			case 'presslabs':
				$handler = new \Presslabs\Cache\CacheHandler();

				if ( method_exists( $handler, 'purge_cache' ) ) {
					$handler->purge_cache( 'all' );
				}
				break;

			case 'pagely':
				$purger = new \PagelyCachePurge();

				if ( method_exists( $purger, 'purgeAll' ) ) {
					$purger->purgeAll();
				}
				break;

			case 'litespeed':
				// O servidor LiteSpeed limpa o próprio cache ao ver este cabeçalho na resposta.
				if ( ! headers_sent() ) {
					header( 'X-LiteSpeed-Purge: *' );
				}
				break;
		}
	}

	public function purge_url( $url ): void {
		$url = (string) $url;

		switch ( $this->get_host() ) {
			case 'spinupwp':
				if ( function_exists( 'spinupwp_purge_url' ) ) {
					spinupwp_purge_url( $url );
				}
				break;

			case 'kinsta':
				// Endpoint de limpeza por URL da Kinsta: a própria URL + /kinsta-clear-cache/.
				wp_safe_remote_get(
					trailingslashit( $url ) . 'kinsta-clear-cache/',
					array(
						'blocking' => false,
						'timeout'  => 2,
					)
				);
				break;

			case 'godaddy':
				$this->godaddy_request( 'PURGE', $url );
				break;

			case 'presslabs':
				$handler = new \Presslabs\Cache\CacheHandler();

				if ( method_exists( $handler, 'invalidate_url' ) ) {
					$handler->invalidate_url( $url, true );
				}
				break;

			case 'litespeed':
				$path = wp_parse_url( $url, PHP_URL_PATH );

				if ( ! headers_sent() && is_string( $path ) ) {
					header( 'X-LiteSpeed-Purge: ' . $path, false );
				}
				break;
		}
	}

	public function purge_nginx_helper(): void {
		if ( has_action( 'rt_nginx_helper_purge_all' ) ) {
			do_action( 'rt_nginx_helper_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		}
	}

	public function purge_nginx_helper_url( $url ): void {
		global $nginx_purger;

		if ( is_object( $nginx_purger ) && method_exists( $nginx_purger, 'purge_url' ) ) {
			$nginx_purger->purge_url( (string) $url );
		}
	}

	// API Varnish do Managed WordPress da GoDaddy (constantes definidas pela própria hospedagem).
	private function godaddy_request( string $method, string $url ): void {
		if ( ! class_exists( '\WPaaS\Plugin' ) || ! method_exists( '\WPaaS\Plugin', 'vip' ) ) {
			return;
		}

		$vip  = (string) \WPaaS\Plugin::vip();
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		if ( '' === $vip ) {
			return;
		}

		wp_remote_request(
			'http://' . $vip . ( 'BAN' === $method ? '/' : $path ),
			array(
				'method'   => $method,
				'blocking' => false,
				'timeout'  => 2,
				'headers'  => array( 'Host' => (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
			)
		);
	}
}
