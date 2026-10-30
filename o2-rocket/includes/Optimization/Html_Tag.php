<?php
/**
 * @package O2Rocket
 */

namespace O2Rocket\Optimization;

defined( 'ABSPATH' ) || exit;

// Leitura e edição de atributos de uma tag HTML de abertura, respeitando aspas
// (um "src=" dentro do valor de outro atributo nunca é confundido com o atributo src).
final class Html_Tag {

	// Tipos que o navegador executa como JavaScript clássico ("module" fica de fora de propósito).
	const JS_TYPES = array(
		'text/javascript',
		'application/javascript',
		'application/x-javascript',
		'application/ecmascript',
		'application/x-ecmascript',
		'text/ecmascript',
		'text/x-javascript',
		'text/x-ecmascript',
		'text/jscript',
		'text/livescript',
		'text/javascript1.0',
		'text/javascript1.1',
		'text/javascript1.2',
		'text/javascript1.3',
		'text/javascript1.4',
		'text/javascript1.5',
	);

	const ATTRIBUTE_PATTERN = '#([^\s"\'>/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?#';

	// Regex de uma tag de abertura que não termina num ">" dentro de aspas.
	public static function opening_pattern( string $name ): string {
		return '#<' . preg_quote( $name, '#' ) . '\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>#i';
	}

	// Atributos com nome em minúsculas: [ nome => [ valor bruto|null, início, tamanho ] ] (o primeiro de cada nome vale).
	public static function parse( string $tag ): array {
		if ( 1 !== preg_match( '#^<[a-zA-Z][a-zA-Z0-9:-]*#', $tag, $name ) ) {
			return array();
		}

		$offset = strlen( $name[0] );
		$end    = strrpos( $tag, '>' );
		$body   = substr( $tag, $offset, false === $end ? null : $end - $offset );

		if ( ! preg_match_all( self::ATTRIBUTE_PATTERN, (string) $body, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return array();
		}

		$attributes = array();

		foreach ( $matches as $match ) {
			$key = strtolower( $match[1][0] );

			if ( isset( $attributes[ $key ] ) ) {
				continue;
			}

			$value = null;

			foreach ( array( 2, 3, 4 ) as $group ) {
				if ( isset( $match[ $group ] ) && -1 !== $match[ $group ][1] ) {
					$value = $match[ $group ][0];
					break;
				}
			}

			$attributes[ $key ] = array( $value, $offset + $match[0][1], strlen( $match[0][0] ) );
		}

		return $attributes;
	}

	// Valor bruto (com entidades HTML); null se ausente. Atributo sem valor devolve "".
	public static function get_attribute( string $tag, string $name ): ?string {
		$attributes = self::parse( $tag );
		$name       = strtolower( $name );

		if ( ! isset( $attributes[ $name ] ) ) {
			return null;
		}

		return null === $attributes[ $name ][0] ? '' : $attributes[ $name ][0];
	}

	public static function has_attribute( string $tag, string $name ): bool {
		return array_key_exists( strtolower( $name ), self::parse( $tag ) );
	}

	// Troca o valor (ou acrescenta o atributo logo depois do nome da tag). $value é texto puro: é escapado aqui.
	public static function set_attribute( string $tag, string $name, string $value ): string {
		$attributes = self::parse( $tag );
		$key        = strtolower( $name );
		$pair       = $name . '="' . esc_attr( $value ) . '"';

		if ( isset( $attributes[ $key ] ) ) {
			return substr_replace( $tag, $pair, $attributes[ $key ][1], $attributes[ $key ][2] );
		}

		return (string) preg_replace( '#^(<[a-zA-Z][a-zA-Z0-9:-]*)#', '$1 ' . str_replace( array( '\\', '$' ), array( '\\\\', '\\$' ), $pair ), $tag, 1 );
	}

	public static function remove_attribute( string $tag, string $name ): string {
		$attributes = self::parse( $tag );
		$key        = strtolower( $name );

		if ( ! isset( $attributes[ $key ] ) ) {
			return $tag;
		}

		$start  = $attributes[ $key ][1];
		$length = $attributes[ $key ][2];

		// Leva junto o espaço antes do atributo.
		while ( $start > 0 && ctype_space( $tag[ $start - 1 ] ) ) {
			--$start;
			++$length;
		}

		return substr_replace( $tag, '', $start, $length );
	}

	// Renomeia mantendo o valor exatamente como estava.
	public static function rename_attribute( string $tag, string $from, string $to ): string {
		$attributes = self::parse( $tag );
		$key        = strtolower( $from );

		if ( ! isset( $attributes[ $key ] ) ) {
			return $tag;
		}

		$original = substr( $tag, $attributes[ $key ][1], $attributes[ $key ][2] );
		$renamed  = $to . substr( $original, strlen( $from ) );

		return substr_replace( $tag, $renamed, $attributes[ $key ][1], $attributes[ $key ][2] );
	}

	public static function has_class( string $tag, string $class ): bool {
		$classes = preg_split( '/\s+/', strtolower( (string) self::get_attribute( $tag, 'class' ) ) );

		return in_array( strtolower( $class ), (array) $classes, true );
	}

	public static function add_class( string $tag, string $class ): string {
		$current = self::get_attribute( $tag, 'class' );

		if ( null === $current ) {
			return self::set_attribute( $tag, 'class', $class );
		}

		if ( self::has_class( $tag, $class ) ) {
			return $tag;
		}

		return self::set_attribute( $tag, 'class', trim( html_entity_decode( $current, ENT_QUOTES ) . ' ' . $class ) );
	}
}
