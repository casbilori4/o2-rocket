<?php
/**
 * Plugin Name:       O² Rocket
 * Description:       Cache de página, otimização de arquivos, mídia e banco de dados para PageSpeed e SEO de alto desempenho.
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Cassiano de C. Bilória
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       o2-rocket
 * Domain Path:       /languages
 *
 * @package O2Rocket
 */

namespace O2Rocket;

defined( 'ABSPATH' ) || exit;

define( 'O2_ROCKET_VERSION', '1.0.0' );
define( 'O2_ROCKET_FILE', __FILE__ );
define( 'O2_ROCKET_BASENAME', plugin_basename( __FILE__ ) );
define( 'O2_ROCKET_PATH', plugin_dir_path( __FILE__ ) );
define( 'O2_ROCKET_URL', plugin_dir_url( __FILE__ ) );
define( 'O2_ROCKET_INC_PATH', O2_ROCKET_PATH . 'includes/' );
define( 'O2_ROCKET_ADMIN_PATH', O2_ROCKET_PATH . 'admin/' );
define( 'O2_ROCKET_PUBLIC_PATH', O2_ROCKET_PATH . 'public/' );
define( 'O2_ROCKET_ASSETS_URL', O2_ROCKET_URL . 'assets/' );
define( 'O2_ROCKET_MIN_PHP', '7.4' );
define( 'O2_ROCKET_MIN_WP', '6.3' );
define( 'O2_ROCKET_OPTION', 'o2_rocket_settings' );

// Cache fora da pasta do plugin (sobrevive a atualizações). Sobrescrevível no wp-config.php.
if ( ! defined( 'O2_ROCKET_CACHE_PATH' ) ) {
	define( 'O2_ROCKET_CACHE_PATH', wp_normalize_path( WP_CONTENT_DIR . '/cache/o2-rocket/' ) );
}

if ( ! defined( 'O2_ROCKET_CACHE_URL' ) ) {
	define( 'O2_ROCKET_CACHE_URL', content_url( 'cache/o2-rocket/' ) );
}

// Configuração lida pelo advanced-cache.php. Fora do cache para não ser apagada na limpeza.
if ( ! defined( 'O2_ROCKET_CONFIG_PATH' ) ) {
	define( 'O2_ROCKET_CONFIG_PATH', wp_normalize_path( WP_CONTENT_DIR . '/o2-rocket-config/' ) );
}

// Sem tradução aqui: roda antes do init.
function get_unmet_requirements() {
	global $wp_version;

	$unmet = array();

	if ( version_compare( PHP_VERSION, O2_ROCKET_MIN_PHP, '<' ) ) {
		$unmet['php'] = array(
			'required' => O2_ROCKET_MIN_PHP,
			'current'  => PHP_VERSION,
		);
	}

	if ( version_compare( $wp_version, O2_ROCKET_MIN_WP, '<' ) ) {
		$unmet['wp'] = array(
			'required' => O2_ROCKET_MIN_WP,
			'current'  => $wp_version,
		);
	}

	return $unmet;
}

function get_requirement_messages() {
	$messages = array();

	foreach ( get_unmet_requirements() as $type => $versions ) {
		if ( 'php' === $type ) {
			$messages[] = sprintf(
				/* translators: 1: versão mínima do PHP, 2: versão atual do PHP. */
				__( 'O O² Rocket requer PHP %1$s ou superior. Versão atual: %2$s.', 'o2-rocket' ),
				$versions['required'],
				$versions['current']
			);
		} elseif ( 'wp' === $type ) {
			$messages[] = sprintf(
				/* translators: 1: versão mínima do WordPress, 2: versão atual do WordPress. */
				__( 'O O² Rocket requer WordPress %1$s ou superior. Versão atual: %2$s.', 'o2-rocket' ),
				$versions['required'],
				$versions['current']
			);
		}
	}

	return $messages;
}

function render_requirements_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	foreach ( get_requirement_messages() as $message ) {
		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'O² Rocket inativo:', 'o2-rocket' ),
			esc_html( $message )
		);
	}
}

// O2Rocket\Admin\ => admin/, O2Rocket\Frontend\ => public/ ("Public" é reservado no PHP 7.4),
// O2Rocket\Vendor\* => vendor/ (bibliotecas de terceiros com namespace trocado), O2Rocket\ => includes/.
function autoloader() {
	static $autoloader = null;

	if ( null === $autoloader ) {
		require_once O2_ROCKET_INC_PATH . 'Autoloader.php';

		$autoloader = new Autoloader();
		$autoloader
			->add_namespace( __NAMESPACE__ . '\\Admin', O2_ROCKET_ADMIN_PATH )
			->add_namespace( __NAMESPACE__ . '\\Frontend', O2_ROCKET_PUBLIC_PATH )
			->add_namespace( __NAMESPACE__ . '\\Vendor\\Minify', O2_ROCKET_PATH . 'vendor/matthiasmullie/minify/src/' )
			->add_namespace( __NAMESPACE__ . '\\Vendor\\PathConverter', O2_ROCKET_PATH . 'vendor/matthiasmullie/path-converter/src/' )
			->add_namespace( __NAMESPACE__, O2_ROCKET_INC_PATH );
		$autoloader->register();
	}

	return $autoloader;
}

// Método "direct": cache não pode depender de credenciais FTP.
function direct_filesystem() {
	static $filesystem = null;

	if ( null === $filesystem ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

		if ( ! defined( 'FS_CHMOD_DIR' ) ) {
			define( 'FS_CHMOD_DIR', ( fileperms( ABSPATH ) & 0777 | 0755 ) );
		}

		if ( ! defined( 'FS_CHMOD_FILE' ) ) {
			define( 'FS_CHMOD_FILE', ( fileperms( ABSPATH . 'index.php' ) & 0777 | 0644 ) );
		}

		$filesystem = new \WP_Filesystem_Direct( null );
	}

	return $filesystem;
}

function invalidate_opcache( $file ) {
	if ( ! function_exists( 'opcache_invalidate' ) ) {
		return;
	}

	$restrict = (string) ini_get( 'opcache.restrict_api' );
	$script   = isset( $_SERVER['SCRIPT_FILENAME'] ) ? wp_normalize_path( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_FILENAME'] ) ) ) : '';

	if ( '' !== $restrict && 0 !== strpos( $script, wp_normalize_path( $restrict ) ) ) {
		return;
	}

	opcache_invalidate( $file, true );
}

// Grava em arquivo temporário e renomeia: leitores nunca veem um arquivo pela metade.
function atomic_write( $file, $contents, $mode = false ) {
	$filesystem = direct_filesystem();

	if ( false === $mode ) {
		$mode = file_exists( $file ) ? ( fileperms( $file ) & 0777 ) : FS_CHMOD_FILE;
	}

	$written = false;

	if ( wp_is_writable( dirname( $file ) ) ) {
		$temp = $file . '.' . str_replace( '.', '', uniqid( '', true ) ) . '.tmp';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged
		$written = $filesystem->put_contents( $temp, $contents, $mode ) && @rename( $temp, $file );

		if ( ! $written && $filesystem->exists( $temp ) ) {
			$filesystem->delete( $temp );
		}
	}

	// Fallback: diretório sem escrita ou rename bloqueado (Windows).
	if ( ! $written && file_exists( $file ) && wp_is_writable( $file ) ) {
		$written = $filesystem->put_contents( $file, $contents, $mode );
	}

	if ( $written && '.php' === substr( $file, -4 ) ) {
		invalidate_opcache( $file );
	}

	return $written;
}

// URL de um JS do plugin: a versão .min.js, exceto com SCRIPT_DEBUG (depuração).
function script_url( $path ) {
	$min = preg_replace( '/\.js$/', '.min.js', $path );

	if ( ( ! defined( 'SCRIPT_DEBUG' ) || ! SCRIPT_DEBUG ) && is_readable( O2_ROCKET_PATH . $min ) ) {
		$path = $min;
	}

	return plugins_url( $path, O2_ROCKET_FILE );
}

// Conteúdo de um JS do plugin para ir em linha na página (.min.js quando existe; "</script" escapado).
function inline_script( $base ) {
	foreach ( array( '.min.js', '.js' ) as $suffix ) {
		$file = O2_ROCKET_PATH . $base . $suffix;

		if ( is_readable( $file ) ) {
			return str_replace( '</script', '<\/script', trim( (string) file_get_contents( $file ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
	}

	return '';
}

// Sem "Deny from all": o servidor web servirá o HTML em cache direto desta pasta.
function prepare_cache_directory() {
	if ( ! wp_mkdir_p( O2_ROCKET_CACHE_PATH ) ) {
		return false;
	}

	$filesystem = direct_filesystem();
	$index_file = O2_ROCKET_CACHE_PATH . 'index.php';

	if ( ! $filesystem->exists( $index_file ) ) {
		$filesystem->put_contents( $index_file, "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
	}

	return wp_is_writable( O2_ROCKET_CACHE_PATH );
}

function run_for_each_site( $callback, $network_wide ) {
	if ( ! is_multisite() || ! $network_wide ) {
		call_user_func( $callback );
		return;
	}

	$site_ids = get_sites(
		array(
			'fields'                 => 'ids',
			'number'                 => 0,
			'update_site_meta_cache' => false,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		call_user_func( $callback );
		restore_current_blog();
	}
}

function activate( $network_wide = false ) {
	$messages = get_requirement_messages();

	if ( ! empty( $messages ) ) {
		deactivate_plugins( O2_ROCKET_BASENAME );

		wp_die(
			esc_html( implode( ' ', $messages ) ),
			esc_html__( 'Erro na ativação do O² Rocket', 'o2-rocket' ),
			array(
				'response'  => 200,
				'back_link' => true,
			)
		);
	}

	run_for_each_site( __NAMESPACE__ . '\\activate_site', (bool) $network_wide );

	prepare_cache_directory();

	do_action( 'o2_rocket_activate', (bool) $network_wide );
}

function activate_site() {
	if ( false === get_option( 'o2_rocket_installed_at' ) ) {
		add_option( 'o2_rocket_installed_at', time(), '', false );
	}

	update_option( 'o2_rocket_version', O2_ROCKET_VERSION, true );
}

function deactivate( $network_wide = false ) {
	run_for_each_site( __NAMESPACE__ . '\\unschedule_cron_events', (bool) $network_wide );

	// Módulos de cache removem aqui o advanced-cache.php, o WP_CACHE e as regras do .htaccess.
	do_action( 'o2_rocket_deactivate', (bool) $network_wide );
}

// Remove qualquer evento do WP-Cron com prefixo o2_rocket_.
function unschedule_cron_events() {
	$crons = _get_cron_array();

	if ( empty( $crons ) || ! is_array( $crons ) ) {
		return;
	}

	$hooks = array();

	foreach ( $crons as $events ) {
		if ( ! is_array( $events ) ) {
			continue;
		}

		foreach ( array_keys( $events ) as $hook ) {
			if ( 0 === strpos( (string) $hook, 'o2_rocket_' ) ) {
				$hooks[ $hook ] = true;
			}
		}
	}

	foreach ( array_keys( $hooks ) as $hook ) {
		wp_unschedule_hook( $hook );
	}
}

register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );

if ( ! empty( get_unmet_requirements() ) ) {
	add_action( 'admin_notices', __NAMESPACE__ . '\\render_requirements_notice' );
	add_action( 'network_admin_notices', __NAMESPACE__ . '\\render_requirements_notice' );
	return;
}

autoloader();

function load_textdomain() {
	load_plugin_textdomain( 'o2-rocket', false, dirname( O2_ROCKET_BASENAME ) . '/languages' );
}
add_action( 'init', __NAMESPACE__ . '\\load_textdomain' );

function bootstrap() {
	$drop_in    = new Cache\Drop_In_Manager();
	$page_cache = new Cache\Page_Cache( $drop_in );
	$preloader  = new Preload\Preloader( $page_cache, new Preload\Preload_Queue() );
	$htaccess   = new Server\Htaccess();

	// Ordem importa nos hooks de update_option: cache (10), pré-carregamento (20), .htaccess (30).
	$page_cache->register_hooks();
	$preloader->register_hooks();
	$htaccess->register_hooks();

	( new Preload\Link_Prefetch( $page_cache ) )->register_hooks();
	( new Cache\Cache_Controls( $page_cache, $preloader ) )->register_hooks();
	( new Optimization\Html_Pipeline() )->register_hooks();
	( new Optimization\Asset_Minifier() )->register_hooks();
	( new Optimization\Delay_Js() )->register_hooks();
	( new Optimization\File_Optimizer() )->register_hooks();

	$lazy_load = new Media\Lazy_Load();
	$lazy_load->register_hooks();

	( new Media\Css_Background_Lazy( $lazy_load ) )->register_hooks();
	( new Media\Image_Dimensions() )->register_hooks();
	( new Media\Lcp_Optimizer( new Media\Lcp_Store(), $page_cache ) )->register_hooks();

	$image_optimizer = new Media\Image_Optimizer();
	$image_optimizer->register_hooks();

	if ( is_admin() ) {
		( new Admin\Admin_UI() )->register_hooks();
		( new Admin\Settings( $drop_in, $preloader, $htaccess ) )->register_hooks();
		( new Admin\Dashboard( $page_cache, $drop_in ) )->register_hooks();
		( new Admin\Media_Dashboard() )->register_hooks();
		( new Media\Bulk_Optimizer( $image_optimizer ) )->register_hooks();
	}

	do_action( 'o2_rocket_loaded' );
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap' );
