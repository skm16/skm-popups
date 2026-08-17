<?php
/**
 * Immutable description of one registered trigger.
 *
 * @package Popkit
 * @since   0.1.0
 */

namespace Popkit;

defined( 'ABSPATH' ) || exit;

/**
 * Value object describing one trigger: what opens a popup once it is eligible.
 *
 * A trigger is constructed once during `popkit_register_triggers`, handed to
 * {@see \Popkit\Triggers::register()}, and never mutated. As with
 * {@see \Popkit\Condition}, every constraint is enforced in the constructor and
 * fails loudly, because registration is developer-facing and a malformed
 * registration is a bug to be seen, not degraded around.
 *
 * ## No context, deliberately
 *
 * A trigger carries no {@see \Popkit\Context}. Every trigger is armed in the
 * browser by definition — a page load delay, a scroll depth, a click, a deep
 * link — and none of them may influence the cached markup. There is no
 * server-side trigger and none is planned, so making the context configurable
 * would only create a way to express something the pipeline cannot honour.
 *
 * Triggers are also armed last, after the whole eligibility pipeline has passed
 * (see docs/CLAUDE.md -> Architecture invariants). Nothing binds to the DOM for
 * a popup that could never show, so a trigger's schema describes arming
 * parameters only.
 *
 * ## Fields
 *
 * `$fields` uses exactly the field schema documented on {@see \Popkit\Condition}
 * — same permitted types, same controls, same requirement that `label` and
 * `control` be present. Validation is delegated to
 * {@see \Popkit\Condition::validate_field_schemas()} rather than reimplemented
 * here: one vocabulary, one implementation, no drift. docs/CLAUDE.md prefers
 * composition over inheritance, so there is no shared base class to hold it.
 *
 * ```php
 * new Trigger(
 *     'scroll_depth',
 *     __( 'Scroll depth', 'popkit' ),
 *     array(
 *         'percent' => array(
 *             'type'    => 'integer',
 *             'default' => 50,
 *             'label'   => __( 'Percent scrolled', 'popkit' ),
 *             'control' => 'range',
 *             'min'     => 1,
 *             'max'     => 100,
 *         ),
 *     )
 * );
 * ```
 *
 * This class holds the schema only. The runtime half — arming, first-fire-wins,
 * and the teardown function every `setup()` must return — lives in the frontend
 * bundle and is unrelated to this registration.
 *
 * @since 0.1.0
 */
final class Trigger {

	/**
	 * Builds and validates a trigger.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key    Unique registry key, lowercase snake_case. Stored
	 *                       verbatim as a trigger config's `type`.
	 * @param string $label  Translated, human-readable name for the editor.
	 * @param array  $fields Field name => field schema, in the shape documented
	 *                       on {@see \Popkit\Condition}. May be empty for a
	 *                       trigger that takes no configuration, such as
	 *                       `deep_link`.
	 *
	 * @throws \InvalidArgumentException When any argument violates the contract
	 *                                   documented on this class.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly array $fields
	) {
		if ( ! Condition::is_valid_key( $key ) ) {
			throw new \InvalidArgumentException(
				esc_html(
					sprintf(
						/* translators: 1: the rejected trigger key, 2: maximum key length. */
						__( 'A popkit trigger key must be lowercase snake_case: it starts with a letter, contains only a-z, 0-9 and single underscores, does not end in an underscore, and is at most %2$d characters long. Received `%1$s`.', 'popkit' ),
						$key,
						Condition::MAX_KEY_LENGTH
					)
				)
			);
		}

		if ( '' === trim( $label ) ) {
			throw new \InvalidArgumentException(
				esc_html(
					sprintf(
						/* translators: %s: trigger key. */
						__( 'The popkit trigger `%s` must declare a non-empty label. The editor has no other way to name it, and an unlabelled control is not accessible.', 'popkit' ),
						$key
					)
				)
			);
		}

		Condition::validate_field_schemas( $key, $fields );
	}

	/**
	 * Returns the JSON-serializable shape consumed by REST and the editor.
	 *
	 * There is no `context` key and no `group` key: see the class docblock for
	 * why a trigger has neither. The editor lists triggers in one panel.
	 *
	 * `fields` is returned as a PHP array, so a trigger with no fields yields an
	 * empty array that `wp_json_encode()` renders as `[]` rather than `{}`. A
	 * caller needing a JSON object for an empty map casts it at encode time.
	 *
	 * Nothing is escaped here. This is data, not output.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Registry schema for one trigger.
	 */
	public function to_schema(): array {
		return array(
			'key'    => $this->key,
			'label'  => $this->label,
			'fields' => $this->fields,
		);
	}
}
