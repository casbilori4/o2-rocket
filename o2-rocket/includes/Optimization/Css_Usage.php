<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Optimization;

defined( 'ABSPATH' ) || exit;

// Decide quais regras de CSS podem valer para uma página, de forma conservadora: uma regra só sai quando alguma classe,
// id, atributo ou tag do seletor não aparece em lugar nenhum do HTML (inclusive em data-settings e scripts em linha).
final class Css_Usage {

	const DYNAMIC_FUNCTIONS = '#:(?:is|where|has|matches|-webkit-any|-moz-any)\(#i';

	// Tags reais e "palavras" do HTML inteiro (classes adicionadas por JSON/JS em linha também contam).
	public static function page_tokens( string $html ): array {
		$tags  = array(
			'html' => true,
			'body' => true,
		);
		$words = array();

		if ( preg_match_all( '#<([a-zA-Z][a-zA-Z0-9-]*)#', $html, $matches ) ) {
			foreach ( $matches[1] as $tag ) {
				$tags[ strtolower( $tag ) ] = true;
			}
		}

		if ( preg_match_all( '#[A-Za-z_][A-Za-z0-9_-]*#', $html, $matches ) ) {
			$words = array_flip( $matches[0] );
		}

		return array(
			'tags'  => $tags,
			'words' => $words,
		);
	}

	// Tokens vindos do beacon (elementos acima da dobra): mesma forma de page_tokens().
	public static function tokens_from_lists( array $tags, array $words ): array {
		$tag_set = array(
			'html' => true,
			'body' => true,
		);

		foreach ( $tags as $tag ) {
			$tag_set[ strtolower( (string) $tag ) ] = true;
		}

		return array(
			'tags'  => $tag_set,
			'words' => array_flip( array_map( 'strval', $words ) ),
		);
	}

	// Árvore filtrada. $safelist: padrões com * comparados às classes/ids do seletor.
	public static function filter( array $nodes, array $tokens, array $safelist = array() ): array {
		$kept     = self::filter_rules( $nodes, $tokens, self::compile_safelist( $safelist ) );
		$animated = self::collect_animation_names( $kept );

		return self::filter_keyframes( $kept, $animated );
	}

	public static function selector_matches( string $selector, array $tokens, string $safelist_regex = '' ): bool {
		foreach ( Css_Parser::split_selectors( $selector ) as $part ) {
			if ( self::part_matches( trim( $part ), $tokens, $safelist_regex ) ) {
				return true;
			}
		}

		return false;
	}

	public static function compile_safelist( array $patterns ): string {
		$parts = array();

		foreach ( $patterns as $pattern ) {
			$pattern = trim( ltrim( trim( (string) $pattern ), '.#' ) );

			if ( '' !== $pattern ) {
				$parts[] = str_replace( '\*', '.*', preg_quote( $pattern, '#' ) );
			}
		}

		return empty( $parts ) ? '' : '#^(?:' . implode( '|', $parts ) . ')$#i';
	}

	private static function filter_rules( array $nodes, array $tokens, string $safelist_regex ): array {
		$kept = array();

		foreach ( $nodes as $node ) {
			if ( 'r' === $node['t'] ) {
				if ( self::selector_matches( $node['s'], $tokens, $safelist_regex ) ) {
					$kept[] = $node;
				}
			} elseif ( 'g' === $node['t'] ) {
				$children = self::filter_rules( $node['c'], $tokens, $safelist_regex );

				if ( ! empty( $children ) || 0 === stripos( $node['p'], '@layer' ) ) {
					$node['c'] = $children;
					$kept[]    = $node;
				}
			} elseif ( 'charset' !== $node['n'] ) {
				// @font-face (o navegador só baixa fontes usadas), @keyframes (filtradas depois), @import, @page...
				$kept[] = $node;
			}
		}

		return $kept;
	}

	private static function part_matches( string $part, array $tokens, string $safelist_regex ): bool {
		if ( '' === $part ) {
			return false;
		}

		// :is()/:where()/:has() aceitam listas de seletores: na dúvida, mantém.
		if ( preg_match( self::DYNAMIC_FUNCTIONS, $part ) ) {
			return true;
		}

		// :not(...) só restringe: ignorar o conteúdo nunca faz uma regra usada sumir.
		$part = (string) preg_replace( '#:not\((?:[^()]|\([^()]*\))*\)#i', '', $part );

		$classes = array();
		$ids     = array();
		$attrs   = array();

		if ( preg_match_all( '#\.((?:\\\\.|[_a-zA-Z0-9-]|[^\x00-\x7F])+)#', $part, $matches ) ) {
			$classes = $matches[1];
		}

		if ( preg_match_all( '/#((?:\\\\.|[_a-zA-Z0-9-]|[^\x00-\x7F])+)/', $part, $matches ) ) {
			$ids = $matches[1];
		}

		if ( preg_match_all( '#\[\s*([a-zA-Z_:][-a-zA-Z0-9_:.]*)#', $part, $matches ) ) {
			$attrs = $matches[1];
		}

		// Classe ou id na lista de segurança (adicionados por JavaScript externo): mantém.
		if ( '' !== $safelist_regex ) {
			foreach ( array_merge( $classes, $ids ) as $name ) {
				if ( preg_match( $safelist_regex, self::unescape( $name ) ) ) {
					return true;
				}
			}
		}

		foreach ( array_merge( $classes, $ids ) as $name ) {
			foreach ( self::words_of( self::unescape( $name ) ) as $word ) {
				if ( ! isset( $tokens['words'][ $word ] ) ) {
					return false;
				}
			}
		}

		foreach ( $attrs as $attr ) {
			$attr = strtolower( $attr );

			if ( ! isset( $tokens['words'][ $attr ] ) && ! in_array( $attr, array( 'class', 'id', 'style', 'href', 'src', 'type', 'title', 'lang', 'dir', 'role', 'name', 'rel', 'target', 'value' ), true ) ) {
				return false;
			}
		}

		// Tags: o que sobra sem classes, ids, atributos e pseudos, em cada parte composta.
		$bare = (string) preg_replace( array( '#\[[^\]]*\]#', '#::?[a-zA-Z-]+(?:\((?:[^()]|\([^()]*\))*\))?#', '#[.\#](?:\\\\.|[_a-zA-Z0-9-]|[^\x00-\x7F])+#' ), ' ', $part );

		if ( preg_match_all( '#(?:^|[\s>+~(])([a-zA-Z][a-zA-Z0-9-]*)#', $bare, $matches ) ) {
			foreach ( $matches[1] as $tag ) {
				if ( ! isset( $tokens['tags'][ strtolower( $tag ) ] ) ) {
					return false;
				}
			}
		}

		return true;
	}

	// ".md\:flex" => "md:flex"; ".\31 0" => "10".
	private static function unescape( string $name ): string {
		if ( false === strpos( $name, '\\' ) ) {
			return $name;
		}

		$name = (string) preg_replace_callback(
			'#\\\\([0-9a-fA-F]{1,6})\s?#',
			static function ( $matches ) {
				return function_exists( 'mb_chr' ) ? (string) mb_chr( (int) hexdec( $matches[1] ), 'UTF-8' ) : chr( (int) hexdec( $matches[1] ) % 256 );
			},
			$name
		);

		return (string) preg_replace( '#\\\\(.)#', '$1', $name );
	}

	// Mesmo corte de palavras usado em page_tokens(): "md:flex" precisa de "md" e "flex" no HTML.
	private static function words_of( string $name ): array {
		return preg_match_all( '#[A-Za-z_][A-Za-z0-9_-]*#', $name, $matches ) ? $matches[0] : array( $name );
	}

	private static function collect_animation_names( array $nodes ): array {
		$names = array();

		foreach ( $nodes as $node ) {
			if ( 'r' === $node['t'] && false !== stripos( $node['b'], 'animation' ) && preg_match_all( '#animation(?:-name)?\s*:\s*([^;}]+)#i', $node['b'], $matches ) ) {
				foreach ( $matches[1] as $value ) {
					foreach ( self::words_of( $value ) as $word ) {
						$names[ $word ] = true;
					}
				}
			} elseif ( 'g' === $node['t'] ) {
				$names += self::collect_animation_names( $node['c'] );
			}
		}

		return $names;
	}

	private static function filter_keyframes( array $nodes, array $animated ): array {
		$result = array();

		foreach ( $nodes as $node ) {
			if ( 'a' === $node['t'] && in_array( $node['n'], array( 'keyframes', '-webkit-keyframes', '-moz-keyframes', '-o-keyframes' ), true ) ) {
				$name = preg_match( '#^@[a-z-]*keyframes\s+["\']?([^\s"\'{]+)#i', $node['x'], $match ) ? $match[1] : '';

				if ( '' === $name || ! isset( $animated[ $name ] ) ) {
					continue;
				}
			} elseif ( 'g' === $node['t'] ) {
				$node['c'] = self::filter_keyframes( $node['c'], $animated );
			}

			$result[] = $node;
		}

		return $result;
	}
}
