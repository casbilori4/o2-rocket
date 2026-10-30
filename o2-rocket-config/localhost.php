<?php
defined( 'ABSPATH' ) || exit;

return array (
  'version' => '1.0.0',
  'cache_path' => 'C:/wamp64/www/mtrix.com.br/wp-content/cache/o2-rocket/',
  'home_path' => '/mtrix.com.br/',
  'trailing_slash' => true,
  'exclude_regex' => '#(?:^|/)cart(?:/|$)|(?:^|/)checkout(?:/|$)|(?:^|/)my\\-account(?:/|$)|(?:^|/)carrinho(?:/|$)|(?:^|/)finalizar\\-compra(?:/|$)|(?:^|/)minha\\-conta(?:/|$)#i',
  'bypass_cookies' => 
  array (
    0 => 'wordpress_logged_in_',
    1 => 'wp-postpass_',
    2 => 'comment_author_',
    3 => 'woocommerce_items_in_cart',
    4 => 'woocommerce_cart_hash',
    5 => 'wp_woocommerce_session_',
  ),
  'lifespan' => 36000,
  'charset' => 'UTF-8',
);
