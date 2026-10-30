<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Optimization;

defined( 'ABSPATH' ) || exit;

// Analisador de CSS em árvore (sem dependências): regras, at-rules com blocos (@media, @supports, @layer, @container,
// @document) e at-rules "atômicas" (@font-face, @keyframes, @page, @import, @charset...). Strings e comentários respeitados.
// Nó: [ 't' => 'r', 's' => seletor, 'b' => declarações ] | [ 't' => 'g', 'p' => prelúdio, 'c' => filhos ] | [ 't' => 'a', 'n' => nome, 'x' => texto ].
final class Css_Parser {

	// At-rules cujos blocos contêm regras (o resto é guardado como texto).
	const GROUP_RULES = array( 'media', 'supports', 'layer', 'container', 'document', '-moz-document', 'scope', 'starting-style' );

	public static function parse( string $css ): array {
		$css    = self::strip_comments( $css );
		$offset = 0;

		return self::parse_block( $css, $offset, strlen( $css ) );
	}

	public static function serialize( array $nodes ): string {
		$css = '';

		foreach ( $nodes as $node ) {
			if ( 'r' === $node['t'] ) {
				$css .= $node['s'] . '{' . $node['b'] . '}';
			} elseif ( 'g' === $node['t'] ) {
				$inner = self::serialize( $node['c'] );

				// Bloco vazio some (@layer sem conteúdo é declaração de ordem: fica).
				if ( '' !== $inner || 0 === stripos( $node['p'], '@layer' ) ) {
					$css .= $node['p'] . '{' . $inner . '}';
				}
			} else {
				$css .= $node['x'];
			}
		}

		return $css;
	}

	// Vírgulas fora de parênteses e colchetes (":is(a, b)" fica inteiro).
	public static function split_selectors( string $selector ): array {
		$parts = array();
		$depth = 0;
		$start = 0;
		$len   = strlen( $selector );

		for ( $i = 0; $i < $len; $i++ ) {
			$char = $selector[ $i ];

			if ( '(' === $char || '[' === $char ) {
				++$depth;
			} elseif ( ( ')' === $char || ']' === $char ) && $depth > 0 ) {
				--$depth;
			} elseif ( ',' === $char && 0 === $depth ) {
				$parts[] = substr( $selector, $start, $i - $start );
				$start   = $i + 1;
			}
		}

		$parts[] = substr( $selector, $start );

		return $parts;
	}

	// Remove comentários fora de strings.
	public static function strip_comments( string $css ): string {
		if ( false === strpos( $css, '/*' ) ) {
			return $css;
		}

		$out    = '';
		$length = strlen( $css );
		$i      = 0;

		while ( $i < $length ) {
			$char = $css[ $i ];

			if ( '"' === $char || "'" === $char ) {
				$end  = self::string_end( $css, $i, $length );
				$out .= substr( $css, $i, $end - $i );
				$i    = $end;
				continue;
			}

			if ( '/' === $char && $i + 1 < $length && '*' === $css[ $i + 1 ] ) {
				$close = strpos( $css, '*/', $i + 2 );
				$i     = false === $close ? $length : $close + 2;
				continue;
			}

			$out .= $char;
			++$i;
		}

		return $out;
	}

	private static function parse_block( string $css, int &$i, int $length ): array {
		$nodes = array();

		while ( $i < $length ) {
			// Pula espaços e ";" soltos.
			while ( $i < $length && ( ctype_space( $css[ $i ] ) || ';' === $css[ $i ] ) ) {
				++$i;
			}

			if ( $i >= $length ) {
				break;
			}

			if ( '}' === $css[ $i ] ) {
				++$i;
				break;
			}

			$start = $i;
			$end   = self::find_any( $css, $i, $length, '{;}' );

			// Statement sem bloco (@import, @charset, @layer a, b;) ou lixo até ";".
			if ( $end >= $length || ';' === $css[ $end ] ) {
				$text = trim( substr( $css, $start, $end - $start ) );
				$i    = min( $length, $end + 1 );

				if ( '' !== $text && '@' === $text[0] ) {
					$nodes[] = array(
						't' => 'a',
						'n' => self::at_name( $text ),
						'x' => $text . ';',
					);
				}

				continue;
			}

			// "}" antes de "{": fecha o bloco atual.
			if ( '}' === $css[ $end ] ) {
				$i = $end + 1;
				break;
			}

			$prelude = trim( substr( $css, $start, $end - $start ) );
			$i       = $end + 1;

			if ( '' !== $prelude && '@' === $prelude[0] ) {
				$name = self::at_name( $prelude );

				if ( in_array( $name, self::GROUP_RULES, true ) ) {
					$nodes[] = array(
						't' => 'g',
						'p' => $prelude,
						'c' => self::parse_block( $css, $i, $length ),
					);
					continue;
				}

				// Bloco guardado inteiro (@font-face, @keyframes, @page, @property, @counter-style...).
				$close   = self::matching_brace( $css, $end, $length );
				$nodes[] = array(
					't' => 'a',
					'n' => $name,
					'x' => $prelude . substr( $css, $end, $close - $end + 1 ),
				);
				$i       = $close + 1;
				continue;
			}

			// Regra comum: declarações até o "}" correspondente (CSS aninhado moderno fica dentro do corpo, sem análise).
			$close   = self::matching_brace( $css, $end, $length );
			$nodes[] = array(
				't' => 'r',
				's' => $prelude,
				'b' => trim( substr( $css, $end + 1, $close - $end - 1 ) ),
			);
			$i       = $close + 1;
		}

		return $nodes;
	}

	private static function at_name( string $text ): string {
		return preg_match( '/^@([a-z0-9-]+)/i', $text, $match ) ? strtolower( $match[1] ) : '';
	}

	// Primeira ocorrência de um dos caracteres fora de strings e parênteses.
	private static function find_any( string $css, int $i, int $length, string $chars ): int {
		$depth = 0;

		while ( $i < $length ) {
			$char = $css[ $i ];

			if ( '"' === $char || "'" === $char ) {
				$i = self::string_end( $css, $i, $length );
				continue;
			}

			if ( '(' === $char ) {
				++$depth;
			} elseif ( ')' === $char && $depth > 0 ) {
				--$depth;
			} elseif ( 0 === $depth && false !== strpos( $chars, $char ) ) {
				return $i;
			}

			++$i;
		}

		return $length;
	}

	// Posição do "}" que fecha o "{" em $open.
	private static function matching_brace( string $css, int $open, int $length ): int {
		$depth = 0;
		$i     = $open;

		while ( $i < $length ) {
			$char = $css[ $i ];

			if ( '"' === $char || "'" === $char ) {
				$i = self::string_end( $css, $i, $length );
				continue;
			}

			if ( '{' === $char ) {
				++$depth;
			} elseif ( '}' === $char ) {
				--$depth;

				if ( 0 === $depth ) {
					return $i;
				}
			}

			++$i;
		}

		return $length - 1;
	}

	// Posição logo depois do fim da string que começa em $i (aspas escapadas respeitadas).
	private static function string_end( string $css, int $i, int $length ): int {
		$quote = $css[ $i ];
		++$i;

		while ( $i < $length ) {
			if ( '\\' === $css[ $i ] ) {
				$i += 2;
				continue;
			}

			if ( $quote === $css[ $i ] || "\n" === $css[ $i ] ) {
				return $i + 1;
			}

			++$i;
		}

		return $length;
	}
}
