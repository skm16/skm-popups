<?php
/**
 * Structural bounds for rule values that carry no schema.
 *
 * @package Popkit
 * @since   0.1.0
 */

namespace Popkit;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Bounds the shape of rule values popkit cannot validate against a schema.
 *
 * A rule whose `type` is not registered still has to be stored, because the
 * condition it belongs to may simply be deactivated right now — see
 * `docs/CLAUDE.md` -> Registry invariants. Sanitization therefore cannot
 * validate its `values` against a field schema it does not have, but it must
 * still bound what lands in the database. Without these limits a deactivated
 * extension, or a forged REST write, can persist an arbitrarily large payload
 * under a rule type nothing will ever read.
 *
 * The limits are the table in `docs/data-model.md` -> Bounds on unknown rule
 * values, each expressed as a named constant so tests reference the limit rather
 * than restating it.
 *
 * Two properties of this class are load bearing:
 *
 * - **It never truncates.** Every violation refuses the whole rule. A partially
 *   preserved targeting rule is more dangerous than a refused one, because it
 *   looks intact.
 * - **It never recurses.** The traversal is an explicit queue, so a payload
 *   nested thousands of levels deep is rejected rather than exhausting the PHP
 *   stack. Deep nesting is the obvious attack on a structural validator.
 *
 * Nothing here unserializes anything. PHP objects and resources are refused
 * outright rather than inspected.
 *
 * @since 0.1.0
 */
final class Rule_Bounds {

	/**
	 * Maximum nesting depth of a rule's values.
	 *
	 * The `values` container itself is level 1, so an array at level
	 * self::MAX_DEPTH may hold scalars but no further arrays.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	public const MAX_DEPTH = 5;

	/**
	 * Maximum number of keys or array items at any one level.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	public const MAX_ITEMS_PER_LEVEL = 50;

	/**
	 * Maximum length, in characters, of any single string.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	public const MAX_STRING_LENGTH = 2000;

	/**
	 * Maximum serialized size, in bytes, of one rule's values.
	 *
	 * Measured as the byte length of the JSON encoding, which is the form the
	 * values take over REST and in the emitted frontend config.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	public const MAX_SERIALIZED_BYTES = 8192;

	/**
	 * Maximum number of rules in one group.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	public const MAX_RULES_PER_GROUP = 50;

	/**
	 * Maximum number of groups in one rule set.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	public const MAX_GROUPS_PER_SET = 20;

	/**
	 * Error code carried by every WP_Error this class returns.
	 *
	 * One code keeps the failure greppable; the specific limit that was hit is in
	 * the error's `limit` data key.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const ERROR_CODE = 'popkit_rule_bounds';

	/**
	 * Root label used when reporting the position of an offending value.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private const PATH_ROOT = 'values';

	/**
	 * Maximum characters of an array key reproduced in a reported path.
	 *
	 * A hostile payload can use a 2,000 character key; the error message naming
	 * it should stay readable.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	private const PATH_KEY_LENGTH = 40;

	/**
	 * Not instantiable. Every member is static.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}

	/**
	 * Checks one rule's values against every structural limit.
	 *
	 * The size check runs first because it is a single encode and, once it
	 * passes, it caps how much work the traversal below can be made to do.
	 *
	 * The declared return type is `bool|WP_Error` rather than `true|WP_Error`
	 * only because the standalone `true` type is PHP 8.2; success is always
	 * boolean true.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $values Values from a rule whose type is not registered.
	 * @return bool|WP_Error True when every limit is respected, WP_Error naming
	 *                       the limit and the offending path otherwise.
	 */
	public static function check( mixed $values ): bool|WP_Error {
		$encoded = wp_json_encode( $values );

		if ( is_string( $encoded ) && strlen( $encoded ) > self::MAX_SERIALIZED_BYTES ) {
			return self::error(
				'size',
				sprintf(
					/* translators: 1: size of the submitted values in bytes, 2: maximum size in bytes. */
					__( 'This targeting rule was not saved: its settings are %1$d bytes and the limit is %2$d bytes.', 'popkit' ),
					strlen( $encoded ),
					self::MAX_SERIALIZED_BYTES
				),
				self::PATH_ROOT,
				self::MAX_SERIALIZED_BYTES
			);
		}

		$error = self::walk( $values );

		if ( $error instanceof WP_Error ) {
			return $error;
		}

		if ( ! is_string( $encoded ) ) {
			/*
			 * The traversal found nothing it recognizes as a violation, yet the
			 * payload cannot be represented as JSON. It is not going to survive
			 * the round trip through storage and the emitted config either, so it
			 * is refused rather than stored in a form nothing can read back.
			 */
			return self::error(
				'encoding',
				__( 'This targeting rule was not saved: its settings cannot be stored in a readable form.', 'popkit' ),
				self::PATH_ROOT,
				0
			);
		}

		return true;
	}

	/**
	 * Checks how many rules one group holds.
	 *
	 * @since 0.1.0
	 *
	 * @param int $count Number of rules in the group.
	 * @return bool|WP_Error True when within the limit, WP_Error otherwise.
	 */
	public static function check_rule_count( int $count ): bool|WP_Error {
		if ( $count <= self::MAX_RULES_PER_GROUP ) {
			return true;
		}

		return self::error(
			'rules_per_group',
			sprintf(
				/* translators: 1: number of rules submitted, 2: maximum rules allowed in one group. */
				__( 'This group was not saved: it holds %1$d rules and the limit is %2$d.', 'popkit' ),
				$count,
				self::MAX_RULES_PER_GROUP
			),
			'groups[].rules',
			self::MAX_RULES_PER_GROUP
		);
	}

	/**
	 * Checks how many groups one rule set holds.
	 *
	 * @since 0.1.0
	 *
	 * @param int $count Number of groups in the set.
	 * @return bool|WP_Error True when within the limit, WP_Error otherwise.
	 */
	public static function check_group_count( int $count ): bool|WP_Error {
		if ( $count <= self::MAX_GROUPS_PER_SET ) {
			return true;
		}

		return self::error(
			'groups_per_set',
			sprintf(
				/* translators: 1: number of groups submitted, 2: maximum groups allowed. */
				__( 'These targeting rules were not saved: they hold %1$d groups and the limit is %2$d.', 'popkit' ),
				$count,
				self::MAX_GROUPS_PER_SET
			),
			'groups',
			self::MAX_GROUPS_PER_SET
		);
	}

	/**
	 * Walks the payload breadth first, refusing the first violation found.
	 *
	 * The queue is explicit and the depth limit is enforced before children are
	 * enqueued, so nesting cannot exhaust the PHP stack and a self-referential
	 * array terminates at self::MAX_DEPTH rather than looping.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $values Values to inspect.
	 * @return WP_Error|null WP_Error describing the first violation, null when clean.
	 */
	private static function walk( mixed $values ): ?WP_Error {
		$queue = array(
			array(
				'value' => $values,
				'depth' => 0,
				'path'  => self::PATH_ROOT,
			),
		);

		$index = 0;
		$total = 1;

		while ( $index < $total ) {
			$node = $queue[ $index ];
			++$index;

			$value = $node['value'];
			$path  = $node['path'];

			if ( is_array( $value ) ) {
				$level = $node['depth'] + 1;

				if ( $level > self::MAX_DEPTH ) {
					return self::error(
						'depth',
						sprintf(
							/* translators: 1: position of the offending value, for example values.audience.tags, 2: maximum nesting depth. */
							__( 'This targeting rule was not saved: the setting at %1$s is nested more than %2$d levels deep.', 'popkit' ),
							$path,
							self::MAX_DEPTH
						),
						$path,
						self::MAX_DEPTH
					);
				}

				$count = count( $value );

				if ( $count > self::MAX_ITEMS_PER_LEVEL ) {
					return self::error(
						'items',
						sprintf(
							/* translators: 1: position of the offending value, 2: number of items found, 3: maximum items allowed. */
							__( 'This targeting rule was not saved: %1$s holds %2$d items and the limit is %3$d.', 'popkit' ),
							$path,
							$count,
							self::MAX_ITEMS_PER_LEVEL
						),
						$path,
						self::MAX_ITEMS_PER_LEVEL
					);
				}

				foreach ( $value as $key => $child ) {
					$queue[] = array(
						'value' => $child,
						'depth' => $level,
						'path'  => self::child_path( $path, $key ),
					);

					++$total;
				}

				continue;
			}

			$error = self::check_scalar( $value, $path );

			if ( $error instanceof WP_Error ) {
				return $error;
			}
		}

		return null;
	}

	/**
	 * Checks a single non-array value.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed  $value Value to inspect.
	 * @param string $path  Position of the value, for the error message.
	 * @return WP_Error|null WP_Error when the value is out of bounds, null when clean.
	 */
	private static function check_scalar( mixed $value, string $path ): ?WP_Error {
		if ( is_bool( $value ) || is_int( $value ) || null === $value ) {
			return null;
		}

		if ( is_float( $value ) ) {
			if ( is_finite( $value ) ) {
				return null;
			}

			return self::error(
				'number',
				sprintf(
					/* translators: %s: position of the offending value, for example values.audience.score. */
					__( 'This targeting rule was not saved: the number at %s is not a finite value.', 'popkit' ),
					$path
				),
				$path,
				0
			);
		}

		if ( is_string( $value ) ) {
			return self::check_string( $value, $path );
		}

		return self::error(
			'type',
			sprintf(
				/* translators: 1: position of the offending value, 2: the unsupported PHP type found, for example stdClass. */
				__( 'This targeting rule was not saved: the setting at %1$s is a %2$s. Rule settings may only hold text, numbers, true or false, nothing, and lists of those.', 'popkit' ),
				$path,
				get_debug_type( $value )
			),
			$path,
			0
		);
	}

	/**
	 * Checks a single string value.
	 *
	 * Invalid UTF-8 is refused rather than repaired. Repairing it would change
	 * the stored bytes, which breaks the guarantee that a reactivated condition
	 * sees byte-identical values, and leaving it alone risks truncation at the
	 * first bad byte when the database or the JSON encoder meets it.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value Value to inspect.
	 * @param string $path  Position of the value, for the error message.
	 * @return WP_Error|null WP_Error when the string is out of bounds, null when clean.
	 */
	private static function check_string( string $value, string $path ): ?WP_Error {
		$length = self::text_length( $value );

		if ( $length > self::MAX_STRING_LENGTH ) {
			return self::error(
				'string_length',
				sprintf(
					/* translators: 1: position of the offending value, 2: length found in characters, 3: maximum length in characters. */
					__( 'This targeting rule was not saved: the text at %1$s is %2$d characters long and the limit is %3$d.', 'popkit' ),
					$path,
					$length,
					self::MAX_STRING_LENGTH
				),
				$path,
				self::MAX_STRING_LENGTH
			);
		}

		// Skipped rather than approximated where mbstring is absent; core does not polyfill this one.
		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $value, 'UTF-8' ) ) {
			return self::error(
				'encoding',
				sprintf(
					/* translators: %s: position of the offending value. */
					__( 'This targeting rule was not saved: the text at %s is not valid UTF-8.', 'popkit' ),
					$path
				),
				$path,
				0
			);
		}

		return null;
	}

	/**
	 * Builds the reported path of a child value.
	 *
	 * @since 0.1.0
	 *
	 * @param string     $prefix Path of the containing value.
	 * @param int|string $key    Array key or index of the child.
	 * @return string Dotted path, for example `values.audience.tags[2]`.
	 */
	private static function child_path( string $prefix, int|string $key ): string {
		if ( is_int( $key ) ) {
			return $prefix . '[' . $key . ']';
		}

		$label = sanitize_text_field( $key );

		if ( '' === $label ) {
			$label = '?';
		}

		if ( self::text_length( $label ) > self::PATH_KEY_LENGTH ) {
			$label = self::text_slice( $label, self::PATH_KEY_LENGTH ) . '...';
		}

		return $prefix . '.' . $label;
	}

	/**
	 * Counts the characters in a string.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value Value to measure.
	 * @return int Length in characters, or in bytes where mbstring is unavailable.
	 */
	private static function text_length( string $value ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $value, 'UTF-8' );
		}

		return strlen( $value );
	}

	/**
	 * Takes the leading characters of a string without splitting one in half.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value  Value to shorten.
	 * @param int    $length Number of characters to keep.
	 * @return string
	 */
	private static function text_slice( string $value, int $length ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $length, 'UTF-8' );
		}

		return substr( $value, 0, $length );
	}

	/**
	 * Builds the WP_Error returned for a violated limit.
	 *
	 * The message is editor facing and translatable; the data array is what code
	 * and tests should branch on.
	 *
	 * @since 0.1.0
	 *
	 * @param string $limit   Machine name of the limit that was hit.
	 * @param string $message Editor facing explanation.
	 * @param string $path    Position of the offending value.
	 * @param int    $max     The limit's value, or 0 where it is not a number.
	 * @return WP_Error
	 */
	private static function error( string $limit, string $message, string $path, int $max ): WP_Error {
		return new WP_Error(
			self::ERROR_CODE,
			$message,
			array(
				'limit'  => $limit,
				'path'   => $path,
				'max'    => $max,
				'status' => 400,
			)
		);
	}
}
