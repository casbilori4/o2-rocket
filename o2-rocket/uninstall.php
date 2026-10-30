<?php
/**
 * Desinstalação do O² Rocket: remove opções, tabelas, tarefas agendadas, drop-in, linha do wp-config.php,
 * regras do .htaccess e o cache. Os arquivos .webp ao lado das imagens são mantidos.
 *
 * @package O2Rocket
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Carrega constantes, funções e o autoloader sem iniciar o plugin (o plugins_loaded já passou).
require_once __DIR__ . '/o2-rocket.php';

if ( class_exists( 'O2Rocket\Core\Uninstaller' ) ) {
	O2Rocket\Core\Uninstaller::run();
}
