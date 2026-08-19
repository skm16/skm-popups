<?php
/**
 * The classic editor's authoring surface for popups.
 *
 * @package Popkit
 * @since   0.1.0
 */

namespace Popkit;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and saves the five popup panels as classic-editor meta boxes.
 *
 * Mounted only when {@see Editor_Mode::uses_block_editor()} says no, so this and
 * {@see Editor} are never both live. Two surfaces writing the same post meta is
 * how a popup ends up with settings that depend on which screen saved last.
 *
 * ## Sanitization is not reimplemented here
 *
 * This class turns `$_POST` into the shapes `docs/data-model.md` describes and
 * then calls `update_post_meta()`. That is the whole of its responsibility. Each
 * `_popkit_*` key is registered with a `sanitize_callback` in {@see Meta}, and
 * WordPress runs it on every write regardless of who called — so the classic
 * screen gets bounds checking, enum validation, unknown-key rejection and the
 * `Rule_Bounds` refusals for free, and cannot drift from what REST enforces.
 *
 * The one thing that does have to happen here is type coercion, because a form
 * posts strings and the sanitizer validates types. {@see Classic_Fields::coerce()}
 * does that against the registry schema and nothing else — it never decides
 * whether a value is *allowed*.
 *
 * ## Why every condition's fields are rendered for every rule
 *
 * A rule's controls depend on which condition it names, and the author changes
 * that with a select. Rather than build markup in JavaScript from a schema —
 * which would mean a second control map, in a second language, that has to be
 * kept in step with `Classic_Fields` — each rule renders the field set for every
 * registered condition and hides all but the chosen one. The save path reads
 * only the fields belonging to the selected type.
 *
 * The cost is markup for conditions the author is not using. The benefit is that
 * a condition registered by a third party works on this screen with no
 * JavaScript at all, which is the registry invariant `docs/CLAUDE.md` requires.
 *
 * ## Fails closed
 *
 * A save with no nonce, a bad nonce, an autosave, a revision, a bulk edit, or a
 * user without the capability leaves the meta exactly as it was. It never
 * partially writes: an absent panel means "this screen did not render that
 * panel", not "the author cleared it".
 *
 * @since 0.1.0
 */
final class Classic_Editor {

	/**
	 * Nonce action, and the name of the field carrying it.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const NONCE = 'popkit_classic_meta';

	/**
	 * Handle for the repeater script and its stylesheet.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const HANDLE = 'popkit-classic';

	/**
	 * Not instantiable. Every member is static.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}

	/**
	 * Hooks the meta boxes, their assets and the save handler.
	 *
	 * Array callables, for the reason given on {@see Rest_Schema::init()}.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes_' . Post_Type::POST_TYPE, array( self::class, 'register_meta_boxes' ) );
		add_action( 'save_post_' . Post_Type::POST_TYPE, array( self::class, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
	}

	/**
	 * Registers the three meta boxes.
	 *
	 * Targeting gets the wide column because a rule is a row of controls and a
	 * group is a list of rules. Behaviour and Appearance are narrower by nature.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function register_meta_boxes(): void {
		add_meta_box(
			'popkit-targeting',
			__( 'PopKit — Targeting', 'popkit' ),
			array( self::class, 'render_targeting' ),
			Post_Type::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'popkit-behaviour',
			__( 'PopKit — Triggers, schedule and frequency', 'popkit' ),
			array( self::class, 'render_behaviour' ),
			Post_Type::POST_TYPE,
			'normal',
			'default'
		);

		add_meta_box(
			'popkit-appearance',
			__( 'PopKit — Appearance', 'popkit' ),
			array( self::class, 'render_appearance' ),
			Post_Type::POST_TYPE,
			'side',
			'default'
		);
	}

	/**
	 * Enqueues the repeater script on the popup editing screens only.
	 *
	 * @since 0.1.0
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen instanceof \WP_Screen || Post_Type::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style( self::HANDLE, POPKIT_URL . 'dist/classic.css', array(), POPKIT_VERSION );
		wp_enqueue_script( self::HANDLE, POPKIT_URL . 'dist/classic.js', array(), POPKIT_VERSION, true );
	}

	/**
	 * Renders the targeting meta box.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_Post $post Popup being edited.
	 * @return void
	 */
	public static function render_targeting( $post ): void {
		wp_nonce_field( self::NONCE, self::NONCE );

		$stored = get_post_meta( $post->ID, Meta::CONDITIONS, true );
		$groups = is_array( $stored ) && isset( $stored['groups'] ) && is_array( $stored['groups'] )
			? $stored['groups']
			: array();

		echo '<div class="popkit-repeater" data-popkit-groups>';
		echo '<p class="description">';
		echo esc_html__( 'Rules inside a group must all match. Any one group matching is enough. A popup with no rules shows everywhere.', 'popkit' );
		echo '</p>';

		foreach ( array_values( $groups ) as $index => $group ) {
			self::render_group( (int) $index, is_array( $group ) ? $group : array() );
		}

		echo '</div>';

		echo '<p><button type="button" class="button" data-popkit-add-group>';
		echo esc_html__( 'Add group', 'popkit' );
		echo '</button></p>';

		// Templates the repeater script clones. Rendered once, never submitted:
		// a <template>'s contents are inert and its controls are not form fields.
		echo '<template data-popkit-template="group">';
		self::render_group( 0, array(), true );
		echo '</template>';

		echo '<template data-popkit-template="rule">';
		self::render_rule( 0, 0, array(), true );
		echo '</template>';
	}

	/**
	 * Renders one targeting group.
	 *
	 * @since 0.1.0
	 *
	 * @param int                  $index    Group index.
	 * @param array<string, mixed> $group    Stored group.
	 * @param bool                 $template Whether this is the clone template.
	 * @return void
	 */
	private static function render_group( int $index, array $group, bool $template = false ): void {
		$rules = isset( $group['rules'] ) && is_array( $group['rules'] ) ? array_values( $group['rules'] ) : array();
		$token = $template ? '__GROUP__' : (string) $index;

		echo '<fieldset class="popkit-group" data-popkit-group="' . esc_attr( $token ) . '">';
		echo '<legend>' . esc_html__( 'Group', 'popkit' ) . '</legend>';
		echo '<div data-popkit-rules>';

		foreach ( $rules as $rule_index => $rule ) {
			self::render_rule( $index, (int) $rule_index, is_array( $rule ) ? $rule : array(), $template );
		}

		echo '</div>';
		echo '<p>';
		echo '<button type="button" class="button" data-popkit-add-rule>' . esc_html__( 'Add rule', 'popkit' ) . '</button> ';
		echo '<button type="button" class="button-link-delete" data-popkit-remove-group>' . esc_html__( 'Remove group', 'popkit' ) . '</button>';
		echo '</p>';
		echo '</fieldset>';
	}

	/**
	 * Renders one rule, with a field set for every registered condition.
	 *
	 * @since 0.1.0
	 *
	 * @param int                  $group    Group index.
	 * @param int                  $index    Rule index within the group.
	 * @param array<string, mixed> $rule     Stored rule.
	 * @param bool                 $template Whether this is the clone template.
	 * @return void
	 */
	private static function render_rule( int $group, int $index, array $rule, bool $template = false ): void {
		$group_token = $template ? '__GROUP__' : (string) $group;
		$rule_token  = $template ? '__RULE__' : (string) $index;
		$base        = 'popkit_conditions[groups][' . $group_token . '][rules][' . $rule_token . ']';
		$id_base     = 'popkit-c-' . $group_token . '-' . $rule_token;
		$type        = isset( $rule['type'] ) ? (string) $rule['type'] : '';
		$values      = isset( $rule['values'] ) && is_array( $rule['values'] ) ? $rule['values'] : array();

		echo '<div class="popkit-rule" data-popkit-rule="' . esc_attr( $rule_token ) . '">';

		echo '<p class="popkit-field">';
		echo '<label class="popkit-field__label" for="' . esc_attr( $id_base ) . '-type">' . esc_html__( 'Condition', 'popkit' ) . '</label>';
		echo '<select id="' . esc_attr( $id_base ) . '-type" name="' . esc_attr( $base . '[type]' ) . '" data-popkit-rule-type>';
		echo '<option value="">' . esc_html__( '— Select —', 'popkit' ) . '</option>';

		foreach ( popkit_conditions()->all() as $key => $condition ) {
			$schema = $condition->to_schema();

			echo '<option value="' . esc_attr( (string) $key ) . '"' . selected( $type, (string) $key, false ) . '>';
			echo esc_html( (string) ( $schema['label'] ?? $key ) );
			echo '</option>';
		}

		/*
		 * A stored rule whose condition is no longer registered keeps its type in
		 * the select, so saving this screen cannot silently retype it to nothing.
		 * The block editor makes the same promise by showing such a rule read-only.
		 */
		$unavailable = ( '' !== $type && ! popkit_conditions()->is_registered( $type ) );

		if ( $unavailable ) {
			echo '<option value="' . esc_attr( $type ) . '" selected>';
			/* translators: %s: stored condition key whose plugin is not active. */
			echo esc_html( sprintf( __( '%s (unavailable)', 'popkit' ), $type ) );
			echo '</option>';
		}

		echo '</select>';
		echo '</p>';

		if ( $unavailable ) {
			/*
			 * Carry the stored values through the round trip. Nothing renders them,
			 * because there is no schema to render from, so this hidden field is the
			 * only thing standing between a deactivated extension and an author
			 * silently deleting its targeting by pressing Update.
			 */
			echo '<input type="hidden" name="' . esc_attr( $base . '[preserved]' ) . '"';
			echo ' value="' . esc_attr( (string) wp_json_encode( $values ) ) . '" />';

			echo '<p class="popkit-rule__unavailable notice notice-warning inline"><em>';
			echo esc_html__( 'The plugin providing this condition is not active, so this rule cannot be evaluated and the popup will not display. Its settings are kept, and reactivating the plugin restores it exactly.', 'popkit' );
			echo '</em></p>';
		}

		Classic_Fields::render(
			$base . '[negate]',
			$id_base . '-negate',
			array(
				'type'    => 'boolean',
				'control' => 'toggle',
				'label'   => __( 'Invert this rule (match when it does not apply)', 'popkit' ),
			),
			! empty( $rule['negate'] )
		);

		foreach ( popkit_conditions()->all() as $key => $condition ) {
			$schema = $condition->to_schema();
			$fields = isset( $schema['fields'] ) && is_array( $schema['fields'] ) ? $schema['fields'] : array();

			if ( array() === $fields ) {
				continue;
			}

			$active = ( (string) $key === $type );

			echo '<div class="popkit-rule__values" data-popkit-values="' . esc_attr( (string) $key ) . '"' . ( $active ? '' : ' hidden' ) . '>';

			foreach ( $fields as $field => $field_schema ) {
				Classic_Fields::render(
					$base . '[values][' . $field . ']',
					$id_base . '-' . $key . '-' . $field,
					(array) $field_schema,
					$active && array_key_exists( $field, $values ) ? $values[ $field ] : null
				);
			}

			echo '</div>';
		}

		echo '<p><button type="button" class="button-link-delete" data-popkit-remove-rule>';
		echo esc_html__( 'Remove rule', 'popkit' );
		echo '</button></p>';
		echo '</div>';
	}

	/**
	 * Renders the triggers, schedule and frequency meta box.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_Post $post Popup being edited.
	 * @return void
	 */
	public static function render_behaviour( $post ): void {
		self::render_triggers( $post );
		self::render_schedule( $post );
		self::render_frequency( $post );
	}

	/**
	 * Renders the trigger checkboxes and their fields.
	 *
	 * Triggers are a fixed, small vocabulary, so each registered trigger gets a
	 * checkbox rather than a repeater. That is not a simplification of the data
	 * model — a popup stores a list of trigger configs — it is a recognition that
	 * the same trigger twice on one popup has no meaning.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_Post $post Popup being edited.
	 * @return void
	 */
	private static function render_triggers( $post ): void {
		$stored = get_post_meta( $post->ID, Meta::TRIGGERS, true );
		$stored = is_array( $stored ) ? $stored : array();
		$by_key = array();

		foreach ( $stored as $trigger ) {
			if ( is_array( $trigger ) && isset( $trigger['type'] ) ) {
				$by_key[ (string) $trigger['type'] ] = isset( $trigger['values'] ) && is_array( $trigger['values'] )
					? $trigger['values']
					: array();
			}
		}

		echo '<h3>' . esc_html__( 'Triggers', 'popkit' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'The first trigger to fire opens the popup; the rest are torn down.', 'popkit' ) . '</p>';

		foreach ( popkit_triggers()->all() as $key => $trigger ) {
			$schema = $trigger->to_schema();
			$fields = isset( $schema['fields'] ) && is_array( $schema['fields'] ) ? $schema['fields'] : array();
			$on     = array_key_exists( (string) $key, $by_key );
			$id     = 'popkit-t-' . $key;

			echo '<fieldset class="popkit-trigger">';
			echo '<legend class="screen-reader-text">' . esc_html( (string) ( $schema['label'] ?? $key ) ) . '</legend>';

			echo '<p class="popkit-field">';
			echo '<label for="' . esc_attr( $id ) . '">';
			echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="popkit_triggers[' . esc_attr( (string) $key ) . '][enabled]" value="1" ' . checked( $on, true, false ) . ' /> ';
			echo esc_html( (string) ( $schema['label'] ?? $key ) );
			echo '</label>';
			echo '</p>';

			foreach ( $fields as $field => $field_schema ) {
				Classic_Fields::render(
					'popkit_triggers[' . $key . '][values][' . $field . ']',
					$id . '-' . $field,
					(array) $field_schema,
					$on && array_key_exists( $field, $by_key[ (string) $key ] ) ? $by_key[ (string) $key ][ $field ] : null
				);
			}

			echo '</fieldset>';
		}
	}

	/**
	 * Renders the schedule fields.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_Post $post Popup being edited.
	 * @return void
	 */
	private static function render_schedule( $post ): void {
		$schedule = get_post_meta( $post->ID, Meta::SCHEDULE, true );
		$schedule = is_array( $schedule ) ? $schedule : Meta::default_schedule();

		echo '<h3>' . esc_html__( 'Schedule', 'popkit' ) . '</h3>';

		Classic_Fields::render(
			'popkit_schedule[enabled]',
			'popkit-schedule-enabled',
			array(
				'type'    => 'boolean',
				'control' => 'toggle',
				'label'   => __( 'Only show between a start and end date', 'popkit' ),
			),
			! empty( $schedule['enabled'] )
		);

		Classic_Fields::render(
			'popkit_schedule[start]',
			'popkit-schedule-start',
			array(
				'type'    => 'string',
				'control' => 'date-time',
				'label'   => __( 'Start', 'popkit' ),
			),
			$schedule['start'] ?? ''
		);

		Classic_Fields::render(
			'popkit_schedule[end]',
			'popkit-schedule-end',
			array(
				'type'    => 'string',
				'control' => 'date-time',
				'label'   => __( 'End', 'popkit' ),
			),
			$schedule['end'] ?? ''
		);

		Classic_Fields::render(
			'popkit_schedule[timezone]',
			'popkit-schedule-timezone',
			array(
				'type'    => 'enum',
				'enum'    => Meta::SCHEDULE_TIMEZONES,
				'control' => 'select',
				'label'   => __( 'Interpret those times in', 'popkit' ),
			),
			$schedule['timezone'] ?? 'site'
		);
	}

	/**
	 * Renders the frequency fields.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_Post $post Popup being edited.
	 * @return void
	 */
	private static function render_frequency( $post ): void {
		$frequency = get_post_meta( $post->ID, Meta::FREQUENCY, true );
		$frequency = is_array( $frequency ) ? $frequency : Meta::default_frequency();

		echo '<h3>' . esc_html__( 'Frequency', 'popkit' ) . '</h3>';

		Classic_Fields::render(
			'popkit_frequency[mode]',
			'popkit-frequency-mode',
			array(
				'type'    => 'enum',
				'enum'    => Meta::FREQUENCY_MODES,
				'control' => 'select',
				'label'   => __( 'How often a visitor may see this popup', 'popkit' ),
			),
			$frequency['mode'] ?? 'always'
		);

		Classic_Fields::render(
			'popkit_frequency[days]',
			'popkit-frequency-days',
			array(
				'type'    => 'integer',
				'control' => 'number',
				'min'     => 1,
				'label'   => __( 'Days between showings, when the mode above uses them', 'popkit' ),
			),
			$frequency['days'] ?? 7
		);

		Classic_Fields::render(
			'popkit_frequency[on_convert]',
			'popkit-frequency-on-convert',
			array(
				'type'    => 'enum',
				'enum'    => Meta::FREQUENCY_ON_CONVERT,
				'control' => 'select',
				'label'   => __( 'After the visitor completes the popup', 'popkit' ),
			),
			$frequency['on_convert'] ?? 'suppress_forever'
		);
	}

	/**
	 * Renders the appearance meta box.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_Post $post Popup being edited.
	 * @return void
	 */
	public static function render_appearance( $post ): void {
		$display = get_post_meta( $post->ID, Meta::DISPLAY, true );
		$display = is_array( $display ) ? $display : Meta::default_display();
		$layout  = isset( $display['layout'] ) ? (string) $display['layout'] : 'modal';
		$layout  = in_array( $layout, Meta::DISPLAY_LAYOUTS, true ) ? $layout : 'modal';

		$controls = array(
			'layout'    => array(
				'enum'  => Meta::DISPLAY_LAYOUTS,
				'label' => __( 'Layout', 'popkit' ),
			),
			'position'  => array(
				'enum'  => Meta::DISPLAY_POSITIONS[ $layout ],
				'label' => __( 'Position', 'popkit' ),
			),
			'theme'     => array(
				'enum'  => Meta::DISPLAY_THEMES,
				'label' => __( 'Theme', 'popkit' ),
			),
			'size'      => array(
				'enum'  => Meta::DISPLAY_SIZES,
				'label' => __( 'Size', 'popkit' ),
			),
			'animation' => array(
				'enum'  => Meta::DISPLAY_ANIMATIONS,
				'label' => __( 'Animation', 'popkit' ),
			),
		);

		foreach ( $controls as $field => $spec ) {
			Classic_Fields::render(
				'popkit_display[' . $field . ']',
				'popkit-display-' . $field,
				array(
					'type'    => 'enum',
					'enum'    => $spec['enum'],
					'control' => 'select',
					'label'   => $spec['label'],
				),
				$display[ $field ] ?? null
			);
		}

		foreach ( array(
			'overlay'                => __( 'Dim the page behind a modal', 'popkit' ),
			'close_on_overlay_click' => __( 'Clicking the dimmed area closes the popup', 'popkit' ),
		) as $field => $label ) {
			Classic_Fields::render(
				'popkit_display[' . $field . ']',
				'popkit-display-' . str_replace( '_', '-', $field ),
				array(
					'type'    => 'boolean',
					'control' => 'toggle',
					'label'   => $label,
				),
				! empty( $display[ $field ] )
			);
		}

		echo '<p class="description">';
		echo esc_html__( 'The close button is always rendered and cannot be turned off. An overlay click and the Escape key are additional ways to dismiss a popup, never the only ones.', 'popkit' );
		echo '</p>';
	}

	/**
	 * Writes the submitted panels to post meta.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $post_id Popup being saved.
	 * @param \WP_Post $post    Popup being saved.
	 * @return void
	 */
	public static function save( $post_id, $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// `save_post_{$post_type}` already guarantees this, but the method is
		// public and therefore callable directly. Everything else in this plugin
		// re-checks rather than trusting how it was reached.
		if ( ! $post instanceof \WP_Post || Post_Type::POST_TYPE !== $post->post_type ) {
			return;
		}

		// Absent nonce means this save came from somewhere that never rendered the
		// panels — a quick edit, a bulk edit, wp_update_post() in another plugin.
		// Writing defaults here would wipe an author's targeting on a bulk status
		// change, so the only safe answer is to leave the meta alone.
		$nonce = isset( $_POST[ self::NONCE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		/*
		 * Each panel arrives as a nested array, so there is no single scalar to
		 * hand a sanitizer — and sanitizing the leaves here would be the wrong
		 * place for it anyway. Every value below reaches `update_post_meta()`,
		 * where the `sanitize_callback` registered by Meta validates shape, types,
		 * enums and bounds and refuses anything it does not recognise. Escaping a
		 * value here as well would corrupt legitimate input (a URL path holding an
		 * ampersand, say) before the one real validator ever saw it.
		 *
		 * The nonce is verified above; the sniff cannot see across the early
		 * returns that enforce it.
		 */
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified above; validated by the registered meta sanitizer.
		if ( isset( $_POST['popkit_conditions'] ) ) {
			update_post_meta( $post_id, Meta::CONDITIONS, self::read_conditions( wp_unslash( $_POST['popkit_conditions'] ) ) );
		}

		if ( isset( $_POST['popkit_triggers'] ) ) {
			update_post_meta( $post_id, Meta::TRIGGERS, self::read_triggers( wp_unslash( $_POST['popkit_triggers'] ) ) );
		}

		if ( isset( $_POST['popkit_schedule'] ) ) {
			update_post_meta( $post_id, Meta::SCHEDULE, self::read_schedule( wp_unslash( $_POST['popkit_schedule'] ) ) );
		}

		if ( isset( $_POST['popkit_frequency'] ) ) {
			update_post_meta( $post_id, Meta::FREQUENCY, self::read_frequency( wp_unslash( $_POST['popkit_frequency'] ) ) );
		}

		if ( isset( $_POST['popkit_display'] ) ) {
			update_post_meta( $post_id, Meta::DISPLAY, self::read_display( wp_unslash( $_POST['popkit_display'] ) ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	/**
	 * Builds the conditions meta value from submitted input.
	 *
	 * A rule naming no condition is dropped rather than stored empty: an empty
	 * type is what a freshly added row looks like before the author chooses, and
	 * storing it would leave a rule that can never be evaluated.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $raw Raw `popkit_conditions` input.
	 * @return array<string, mixed> Conditions meta value.
	 */
	private static function read_conditions( $raw ): array {
		$groups = array();

		foreach ( (array) ( is_array( $raw ) ? ( $raw['groups'] ?? array() ) : array() ) as $group ) {
			$rules = array();

			foreach ( (array) ( is_array( $group ) ? ( $group['rules'] ?? array() ) : array() ) as $rule ) {
				if ( ! is_array( $rule ) ) {
					continue;
				}

				$type = isset( $rule['type'] ) ? (string) $rule['type'] : '';

				if ( '' === $type ) {
					continue;
				}

				/*
				 * A rule whose condition is not registered keeps its stored values
				 * untouched. There is no schema to coerce against, and guessing
				 * types would rewrite an extension's data while its plugin happens
				 * to be off — the exact loss the read-only treatment exists to stop.
				 */
				$condition = popkit_conditions()->get( $type );
				$submitted = isset( $rule['values'] ) && is_array( $rule['values'] ) ? $rule['values'] : array();

				if ( null === $condition ) {
					$rules[] = array(
						'type'   => $type,
						'negate' => ! empty( $rule['negate'] ),
						'values' => self::preserved_values( $rule ),
					);

					continue;
				}

				$rules[] = array(
					'type'   => $type,
					'negate' => ! empty( $rule['negate'] ),
					'values' => Classic_Fields::coerce( $submitted, (array) ( $condition->to_schema()['fields'] ?? array() ) ),
				);
			}

			if ( array() !== $rules ) {
				$groups[] = array( 'rules' => $rules );
			}
		}

		return array( 'groups' => $groups );
	}

	/**
	 * Recovers the stored values of a rule whose condition is not registered.
	 *
	 * Such a rule renders no controls — there is no schema to render from — so
	 * without this its values would be absent from the submission and a single
	 * save would delete an extension's targeting while its plugin happened to be
	 * deactivated. `docs/CLAUDE.md` requires the opposite: the values survive so
	 * that reactivating restores the popup exactly.
	 *
	 * They travel through the form as JSON in a hidden field, so a rule that is
	 * reordered or moved between groups keeps its own data rather than inheriting
	 * whatever now sits at its old index.
	 *
	 * The decoded value is not trusted and does not need to be. It goes to
	 * `update_post_meta()` like everything else here, where the registered
	 * sanitizer and {@see Rule_Bounds} apply their depth, size and shape limits.
	 * Anything unparseable becomes an empty rule rather than a fatal error.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $rule Submitted rule.
	 * @return array<string, mixed> Values to store.
	 */
	private static function preserved_values( array $rule ): array {
		if ( ! isset( $rule['preserved'] ) || ! is_string( $rule['preserved'] ) || '' === $rule['preserved'] ) {
			return array();
		}

		$decoded = json_decode( $rule['preserved'], true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Builds the triggers meta value from submitted input.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $raw Raw `popkit_triggers` input.
	 * @return array<int, array<string, mixed>> Triggers meta value.
	 */
	private static function read_triggers( $raw ): array {
		$triggers = array();

		foreach ( popkit_triggers()->all() as $key => $trigger ) {
			$submitted = is_array( $raw ) && isset( $raw[ $key ] ) && is_array( $raw[ $key ] ) ? $raw[ $key ] : array();

			if ( empty( $submitted['enabled'] ) ) {
				continue;
			}

			$fields = $trigger->to_schema()['fields'] ?? array();
			$values = isset( $submitted['values'] ) && is_array( $submitted['values'] ) ? $submitted['values'] : array();

			$triggers[] = array(
				'type'   => (string) $key,
				'values' => Classic_Fields::coerce( $values, (array) $fields ),
			);
		}

		return $triggers;
	}

	/**
	 * Builds the schedule meta value from submitted input.
	 *
	 * An empty datetime field is stored as null rather than as an empty string.
	 * `docs/data-model.md` gives null the meaning "no bound", and an empty string
	 * is a value that happens to parse to nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $raw Raw `popkit_schedule` input.
	 * @return array<string, mixed> Schedule meta value.
	 */
	private static function read_schedule( $raw ): array {
		$raw      = is_array( $raw ) ? $raw : array();
		$schedule = Meta::default_schedule();

		$schedule['enabled']  = ! empty( $raw['enabled'] );
		$schedule['timezone'] = isset( $raw['timezone'] ) ? (string) $raw['timezone'] : 'site';
		$schedule['start']    = isset( $raw['start'] ) && '' !== $raw['start'] ? (string) $raw['start'] : null;
		$schedule['end']      = isset( $raw['end'] ) && '' !== $raw['end'] ? (string) $raw['end'] : null;

		return $schedule;
	}

	/**
	 * Builds the frequency meta value from submitted input.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $raw Raw `popkit_frequency` input.
	 * @return array<string, mixed> Frequency meta value.
	 */
	private static function read_frequency( $raw ): array {
		$raw       = is_array( $raw ) ? $raw : array();
		$frequency = Meta::default_frequency();

		$frequency['mode']       = isset( $raw['mode'] ) ? (string) $raw['mode'] : $frequency['mode'];
		$frequency['days']       = isset( $raw['days'] ) ? (int) $raw['days'] : $frequency['days'];
		$frequency['on_convert'] = isset( $raw['on_convert'] ) ? (string) $raw['on_convert'] : $frequency['on_convert'];

		return $frequency;
	}

	/**
	 * Builds the display meta value from submitted input.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $raw Raw `popkit_display` input.
	 * @return array<string, mixed> Display meta value.
	 */
	private static function read_display( $raw ): array {
		$raw     = is_array( $raw ) ? $raw : array();
		$display = Meta::default_display();

		foreach ( array( 'layout', 'position', 'theme', 'size', 'animation' ) as $field ) {
			if ( isset( $raw[ $field ] ) && '' !== $raw[ $field ] ) {
				$display[ $field ] = (string) $raw[ $field ];
			}
		}

		foreach ( array( 'overlay', 'close_on_overlay_click' ) as $field ) {
			$display[ $field ] = ! empty( $raw[ $field ] );
		}

		return $display;
	}
}
