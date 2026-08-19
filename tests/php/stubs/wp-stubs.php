<?php
/**
 * WordPress function stubs for the popkit unit suite.
 *
 * The unit suite deliberately runs without WordPress, so the handful of core
 * functions that pure-logic classes touch are declared here instead. Behavior
 * is faithful enough that a logic test proves something real — sanitizers
 * actually sanitize, escapers actually escape — but these are stubs, not
 * reimplementations. Anything whose correctness depends on core's exact
 * behavior belongs in the integration suite.
 *
 * Every declaration is guarded, so loading this file inside a real WordPress
 * process is a no-op rather than a fatal redeclaration.
 *
 * @package Popkit
 */

declare( strict_types = 1 );

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Escape for use in HTML text.
	 *
	 * Core decodes existing entities before re-encoding; passing double_encode
	 * as false is the closest single-call equivalent.
	 *
	 * @param string $text Text to escape.
	 * @return string
	 */
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8', false );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * Escape for use in an HTML attribute.
	 *
	 * @param string $text Text to escape.
	 * @return string
	 */
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8', false );
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Retrieve a translated string.
	 *
	 * No translation happens without WordPress; the original string is returned.
	 *
	 * @param string $text   Text to translate.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( '_x' ) ) {
	/**
	 * Retrieve a translated string with gettext context.
	 *
	 * @param string $text    Text to translate.
	 * @param string $context Context information for translators.
	 * @param string $domain  Text domain.
	 * @return string
	 */
	function _x( string $text, string $context, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Retrieve a translated string, escaped for HTML text.
	 *
	 * @param string $text   Text to translate.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function esc_html__( string $text, string $domain = 'default' ): string {
		return esc_html( __( $text, $domain ) );
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	/**
	 * Retrieve a translated string, escaped for an HTML attribute.
	 *
	 * @param string $text   Text to translate.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function esc_attr__( string $text, string $domain = 'default' ): string {
		return esc_attr( __( $text, $domain ) );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Sanitize a string from user input or the database.
	 *
	 * Mirrors core's `_sanitize_text_fields( $str, false )`: null bytes and tags
	 * are removed, a `<` that opens no tag becomes an entity, whitespace runs
	 * collapse to a single space, percent-encoded octets are stripped, and the
	 * result is trimmed. Non-scalars return an empty string instead of raising
	 * the notice core would.
	 *
	 * @param mixed $str Value to sanitize.
	 * @return string
	 */
	function sanitize_text_field( mixed $str ): string {
		if ( ! is_scalar( $str ) ) {
			return '';
		}

		$filtered = str_replace( "\0", '', (string) $str );

		if ( str_contains( $filtered, '<' ) ) {
			$filtered = (string) preg_replace( '#<(?![a-zA-Z/!?])#', '&lt;', $filtered );
			$filtered = strip_tags( $filtered );
		}

		$filtered = (string) preg_replace( '/[\r\n\t ]+/', ' ', $filtered );
		$filtered = trim( $filtered );

		$found = false;

		while ( preg_match( '/%[a-f0-9]{2}/i', $filtered, $match ) ) {
			$filtered = str_replace( $match[0], '', $filtered );
			$found    = true;
		}

		if ( $found ) {
			$filtered = trim( (string) preg_replace( '/ +/', ' ', $filtered ) );
		}

		return $filtered;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Sanitize a string key: lowercase alphanumerics, underscores and dashes.
	 *
	 * @param mixed $key Key to sanitize.
	 * @return string
	 */
	function sanitize_key( mixed $key ): string {
		if ( ! is_scalar( $key ) ) {
			return '';
		}

		return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * Convert a value to a non-negative integer.
	 *
	 * @param mixed $maybeint Value to convert.
	 * @return int
	 */
	function absint( mixed $maybeint ): int {
		return abs( (int) $maybeint );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Encode a value as JSON.
	 *
	 * Core additionally coerces invalid UTF-8 before encoding; this stub relies
	 * on json_encode() returning false for it, which is enough for logic tests.
	 *
	 * @param mixed $data    Value to encode.
	 * @param int   $options Bitmask of JSON encode options.
	 * @param int   $depth   Maximum recursion depth.
	 * @return string|false
	 */
	function wp_json_encode( mixed $data, int $options = 0, int $depth = 512 ): string|false {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * Remove slashes from a value, recursing into arrays and objects.
	 *
	 * @param mixed $value Value to unslash.
	 * @return mixed
	 */
	function wp_unslash( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			return array_map( wp_unslash( ... ), $value );
		}

		if ( is_object( $value ) ) {
			foreach ( get_object_vars( $value ) as $property => $property_value ) {
				$value->$property = wp_unslash( $property_value );
			}

			return $value;
		}

		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Determine whether a value is a WP_Error.
	 *
	 * WP_Error is not stubbed, so without WordPress this is always false. The
	 * class_exists() guard keeps the check honest if a test defines its own.
	 *
	 * @param mixed $thing Value to check.
	 * @return bool
	 */
	function is_wp_error( mixed $thing ): bool {
		return is_object( $thing ) && class_exists( 'WP_Error', false ) && $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Fire an action hook.
	 *
	 * Deliberately a no-op. There is no hook system without WordPress, and a
	 * plugin file that dispatches an action during construction — includes/
	 * functions.php builds each registry then fires its registration hook — must
	 * still be loadable here. Unit tests populate the registries by calling
	 * register() directly rather than by hooking, so nothing is lost.
	 *
	 * @param string $hook_name Name of the action.
	 * @param mixed  ...$args   Arguments passed to the callbacks.
	 * @return void
	 */
	function do_action( $hook_name, ...$args ) {}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	/**
	 * Merge user-defined arguments into defaults.
	 *
	 * @param mixed $args     Value to merge. Array, object, or query string.
	 * @param mixed $defaults Defaults to merge into.
	 * @return array
	 */
	function wp_parse_args( mixed $args, mixed $defaults = array() ): array {
		if ( is_object( $args ) ) {
			$parsed = get_object_vars( $args );
		} elseif ( is_array( $args ) ) {
			$parsed = $args;
		} else {
			$parsed = array();
			parse_str( (string) $args, $parsed );
		}

		if ( is_array( $defaults ) && array() !== $defaults ) {
			return array_merge( $defaults, $parsed );
		}

		return $parsed;
	}
}

if ( ! class_exists( 'WP_Error', false ) ) {
	/**
	 * Stand-in for core's error container.
	 *
	 * This is a class rather than a function, which is the one exception to the
	 * "functions only" shape of this file. It has to be here: several pure-logic
	 * classes report refusals by returning a WP_Error, so without it the unit
	 * suite cannot exercise a single rejection path.
	 *
	 * Only the accessors those classes and their tests touch are implemented, and
	 * they follow core's contract exactly — an empty `$code` means "the first
	 * error registered", and `get_error_data()` returns null when nothing was
	 * stored. Anything relying on core's richer behavior (merging, removal,
	 * WP_Error passed through filters) belongs in the integration suite.
	 *
	 * Parameters are deliberately untyped, matching core, so that a caller in a
	 * `strict_types` file and one without behave the same way against it.
	 */
	class WP_Error {

		/**
		 * Messages, keyed by error code.
		 *
		 * @var array<string, string[]>
		 */
		public $errors = array();

		/**
		 * The most recent data value, keyed by error code.
		 *
		 * @var array<string, mixed>
		 */
		public $error_data = array();

		/**
		 * Register an initial error, when one is supplied.
		 *
		 * @param string|int $code    Error code.
		 * @param string     $message Error message.
		 * @param mixed      $data    Optional. Error data.
		 */
		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( '' === $code ) {
				return;
			}

			$this->add( $code, $message, $data );
		}

		/**
		 * Add an error message to the container.
		 *
		 * @param string|int $code    Error code.
		 * @param string     $message Error message.
		 * @param mixed      $data    Optional. Error data.
		 * @return void
		 */
		public function add( $code, $message = '', $data = '' ) {
			$this->errors[ $code ][] = $message;

			if ( '' !== $data ) {
				$this->error_data[ $code ] = $data;
			}
		}

		/**
		 * Retrieve every registered error code.
		 *
		 * @return array<int, string|int>
		 */
		public function get_error_codes() {
			return array_keys( $this->errors );
		}

		/**
		 * Retrieve the first registered error code.
		 *
		 * @return string|int Empty string when no error is registered.
		 */
		public function get_error_code() {
			$codes = $this->get_error_codes();

			return $codes ? $codes[0] : '';
		}

		/**
		 * Retrieve every message registered against a code.
		 *
		 * @param string|int $code Optional. Error code. Defaults to the first code.
		 * @return string[]
		 */
		public function get_error_messages( $code = '' ) {
			if ( '' === $code ) {
				$all = array();

				foreach ( $this->errors as $messages ) {
					$all = array_merge( $all, $messages );
				}

				return $all;
			}

			return $this->errors[ $code ] ?? array();
		}

		/**
		 * Retrieve the first message registered against a code.
		 *
		 * @param string|int $code Optional. Error code. Defaults to the first code.
		 * @return string Empty string when the code carries no message.
		 */
		public function get_error_message( $code = '' ) {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}

			$messages = $this->get_error_messages( $code );

			return $messages ? $messages[0] : '';
		}

		/**
		 * Retrieve the data stored against a code.
		 *
		 * @param string|int $code Optional. Error code. Defaults to the first code.
		 * @return mixed Null when the code carries no data.
		 */
		public function get_error_data( $code = '' ) {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}

			return $this->error_data[ $code ] ?? null;
		}

		/**
		 * Whether any error is registered.
		 *
		 * @return bool
		 */
		public function has_errors() {
			return array() !== $this->errors;
		}
	}
}
