<?php
/**
 * Unit tests for the static capability map declared by Popkit\Capabilities.
 *
 * These run without WordPress, against the stubs bootstrap. Nothing here calls a
 * WordPress function: the subject is the constant table of capability names that
 * `register_post_type()` will later be handed.
 *
 * Why a dedicated test for a plain array:
 *
 * `get_post_type_capabilities()` merges the plugin's `capabilities` array over its
 * own generated defaults and never reports a problem. Both ways of getting the
 * table wrong therefore fail in silence.
 *
 * - A typo'd **key** (`edit_published_post` for `edit_published_posts`) is simply
 *   not a key WordPress looks for. Core substitutes its own default, the bogus key
 *   rides along on the `cap` object where nothing ever reads it, and the map looks
 *   complete to a human reviewer.
 * - A typo'd **value** (`edit_published_popkit_popup`, singular) produces a
 *   capability name that activation never grants to any role, so `edit_post`
 *   resolves to a capability nobody holds and the author is locked out of their own
 *   published popup.
 *
 * Neither shows up in a draft-only CRUD test, so the map is asserted here
 * key-for-key and value-for-value, and the lifecycle consequences are asserted in
 * tests/php/integration/test-capabilities.php.
 *
 * @package Popkit
 */

use PHPUnit\Framework\TestCase;
use Popkit\Capabilities;

/*
 * Load the class directly. The unit suite has no autoloader guarantee, and
 * Capabilities must be loadable without WordPress present for this file to be a
 * genuine unit test. Guarded so a bootstrap that does register an autoloader does
 * not end up loading the file twice.
 */
if ( ! class_exists( Capabilities::class ) ) {
	require_once dirname( __DIR__, 3 ) . '/includes/class-capabilities.php';
}

/**
 * Asserts the shape and contents of the popkit capability map.
 */
final class Test_Popkit_Capability_Map extends TestCase {

	/**
	 * The capability map exactly as declared in docs/CLAUDE.md -> Capabilities.
	 *
	 * That code block is the authority. docs/build-plan.md refers to the same
	 * table in prose as "all sixteen primitives"; the literal array declares
	 * fourteen entries, and WordPress adds a fifteenth (`read` => `read`) of its
	 * own when `map_meta_cap` is true. The array is code and the sentence is
	 * prose, so the array wins. If the map is ever genuinely meant to carry
	 * sixteen entries, this constant is the place that has to say so first.
	 *
	 * @var array<string, string>
	 */
	private const EXPECTED_MAP = array(
		// Meta capabilities — resolved per object by map_meta_cap().
		'edit_post'              => 'edit_popkit_popup',
		'read_post'              => 'read_popkit_popup',
		'delete_post'            => 'delete_popkit_popup',

		// Primitive capabilities used outside map_meta_cap().
		'edit_posts'             => 'edit_popkit_popups',
		'edit_others_posts'      => 'edit_others_popkit_popups',
		'publish_posts'          => 'publish_popkit_popups',
		'read_private_posts'     => 'read_private_popkit_popups',
		'delete_posts'           => 'delete_popkit_popups',
		'create_posts'           => 'edit_popkit_popups',

		// Primitive capabilities used *within* map_meta_cap(). Omitting any of
		// these five is the classic CPT capability bug.
		'edit_private_posts'     => 'edit_private_popkit_popups',
		'edit_published_posts'   => 'edit_published_popkit_popups',
		'delete_private_posts'   => 'delete_private_popkit_popups',
		'delete_published_posts' => 'delete_published_popkit_popups',
		'delete_others_posts'    => 'delete_others_popkit_popups',
	);

	/**
	 * The only entry the map may carry beyond EXPECTED_MAP.
	 *
	 * WordPress injects `read => read` itself for any post type registered with
	 * `map_meta_cap => true`, so declaring it explicitly is redundant but not
	 * wrong. Anything else in the map is a typo'd key: it will be ignored by
	 * WordPress and silently replaced with a generated default.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED_EXTRA = array(
		'read' => 'read',
	);

	/**
	 * The keys whose omission is invisible until a popup leaves draft status.
	 *
	 * @var string[]
	 */
	private const LIFECYCLE_KEYS = array(
		'edit_private_posts',
		'edit_published_posts',
		'delete_private_posts',
		'delete_published_posts',
		'delete_others_posts',
	);

	/**
	 * The distinct primitive capability names a role may be granted.
	 *
	 * @var string[]
	 */
	private const EXPECTED_PRIMITIVES = array(
		'edit_popkit_popups',
		'edit_others_popkit_popups',
		'publish_popkit_popups',
		'read_private_popkit_popups',
		'delete_popkit_popups',
		'edit_private_popkit_popups',
		'edit_published_popkit_popups',
		'delete_private_popkit_popups',
		'delete_published_popkit_popups',
		'delete_others_popkit_popups',
	);

	/**
	 * Meta capability names. These are resolved per object and must never be
	 * granted to a role — a role holding `edit_popkit_popup` would satisfy the
	 * meta check for every popup regardless of author or status.
	 *
	 * @var string[]
	 */
	private const META_CAPABILITY_NAMES = array(
		'edit_popkit_popup',
		'read_popkit_popup',
		'delete_popkit_popup',
	);

	/**
	 * Core's own post capabilities. A value drawn from this list means the map
	 * fell back to core's `post` capabilities, which would hand every author of
	 * ordinary posts control over popups.
	 *
	 * @var string[]
	 */
	private const CORE_POST_CAPABILITIES = array(
		'edit_post',
		'read_post',
		'delete_post',
		'edit_posts',
		'edit_others_posts',
		'publish_posts',
		'read_private_posts',
		'delete_posts',
		'edit_private_posts',
		'edit_published_posts',
		'delete_private_posts',
		'delete_published_posts',
		'delete_others_posts',
		'create_posts',
	);

	/**
	 * Every documented key is present.
	 *
	 * @return void
	 */
	public function test_map_declares_every_documented_key() {
		$map = $this->capability_map();

		foreach ( array_keys( self::EXPECTED_MAP ) as $key ) {
			$this->assertArrayHasKey(
				$key,
				$map,
				sprintf(
					'The capability map is missing "%s". WordPress will silently substitute its own default for the missing key, so nothing will warn you.',
					$key
				)
			);
		}
	}

	/**
	 * Every documented key maps to exactly the documented capability name.
	 *
	 * @return void
	 */
	public function test_map_values_match_the_constitution_exactly() {
		$map = $this->capability_map();

		foreach ( self::EXPECTED_MAP as $key => $expected ) {
			$this->assertArrayHasKey( $key, $map, sprintf( 'Missing capability map key "%s".', $key ) );
			$this->assertSame(
				$expected,
				$map[ $key ],
				sprintf(
					'Capability map key "%s" must resolve to "%s". A misspelled value produces a capability name that activation never grants, locking authors out with no error anywhere.',
					$key,
					$expected
				)
			);
		}
	}

	/**
	 * The five keys consulted inside map_meta_cap() are declared.
	 *
	 * Called out separately from the full-map assertion because these are the
	 * ones whose absence is invisible to draft-only test coverage.
	 *
	 * @return void
	 */
	public function test_map_declares_the_keys_used_inside_map_meta_cap() {
		$map = $this->capability_map();

		foreach ( self::LIFECYCLE_KEYS as $key ) {
			$this->assertArrayHasKey(
				$key,
				$map,
				sprintf(
					'"%s" is consulted by map_meta_cap() once a popup leaves draft status. Omit it and an editor creates a popup, publishes it, and permanently loses access to it.',
					$key
				)
			);
		}
	}

	/**
	 * The map carries no key beyond the documented set.
	 *
	 * An unrecognized key is dead weight: WordPress does not read it, generates
	 * its own default for whatever was intended, and reports nothing.
	 *
	 * @return void
	 */
	public function test_map_contains_no_undocumented_keys() {
		$map    = $this->capability_map();
		$extras = array_diff_key( $map, self::EXPECTED_MAP );

		foreach ( $extras as $key => $value ) {
			$this->assertArrayHasKey(
				$key,
				self::ALLOWED_EXTRA,
				sprintf(
					'Unrecognized capability map key "%s". WordPress reads a fixed set of keys; anything else is a typo that is quietly ignored.',
					$key
				)
			);
			$this->assertSame( self::ALLOWED_EXTRA[ $key ], $value, sprintf( 'Unexpected value for "%s".', $key ) );
		}
	}

	/**
	 * Every value is a non-empty string.
	 *
	 * @return void
	 */
	public function test_every_capability_value_is_a_non_empty_string() {
		$map = $this->capability_map();

		$this->assertNotEmpty( $map, 'The capability map is empty. capability_type alone grants nobody anything.' );

		foreach ( $map as $key => $value ) {
			$this->assertIsString( $value, sprintf( 'Capability "%s" must map to a string.', $key ) );
			$this->assertNotSame( '', trim( $value ), sprintf( 'Capability "%s" maps to an empty name.', $key ) );
		}
	}

	/**
	 * No value is one of core's post capabilities.
	 *
	 * @return void
	 */
	public function test_no_capability_falls_back_to_a_core_post_capability() {
		$map = $this->capability_map();

		foreach ( $map as $key => $value ) {
			if ( 'read' === $key ) {
				// WordPress maps `read` to the core `read` capability by design.
				continue;
			}

			$this->assertNotContains(
				$value,
				self::CORE_POST_CAPABILITIES,
				sprintf(
					'Capability map key "%s" resolves to core capability "%s". Popups would then be governed by whoever can edit ordinary posts.',
					$key,
					$value
				)
			);

			$this->assertStringContainsString(
				'popkit_popup',
				$value,
				sprintf( 'Capability map key "%s" resolves to "%s", which is not a popkit capability name.', $key, $value )
			);
		}
	}

	/**
	 * Meta capability names are singular, primitives are plural.
	 *
	 * `capability_type => array( 'popkit_popup', 'popkit_popups' )` means the
	 * singular base builds meta capabilities and the plural base builds
	 * primitives. Crossing the two produces names that resolve but never match.
	 *
	 * @return void
	 */
	public function test_meta_capabilities_use_the_singular_base_and_primitives_the_plural_base() {
		$map = $this->capability_map();

		foreach ( self::META_CAPABILITY_NAMES as $meta_name ) {
			$this->assertContains( $meta_name, $map, sprintf( 'Meta capability "%s" is absent from the map.', $meta_name ) );
		}

		foreach ( $map as $key => $value ) {
			if ( 'read' === $key ) {
				continue;
			}

			if ( in_array( $key, array( 'edit_post', 'read_post', 'delete_post' ), true ) ) {
				$this->assertStringEndsWith(
					'popkit_popup',
					$value,
					sprintf( 'Meta capability "%s" must use the singular base "popkit_popup".', $key )
				);
				continue;
			}

			$this->assertStringEndsWith(
				'popkit_popups',
				$value,
				sprintf( 'Primitive capability "%s" must use the plural base "popkit_popups".', $key )
			);
		}
	}

	/**
	 * `create_posts` is deliberately aliased to `edit_posts`.
	 *
	 * Matching core's default. Splitting it would let a role edit existing popups
	 * but not create them, a distinction the plugin does not need.
	 *
	 * @return void
	 */
	public function test_create_posts_is_aliased_to_edit_posts() {
		$map = $this->capability_map();

		$this->assertSame(
			$map['edit_posts'],
			$map['create_posts'],
			'create_posts maps to edit_popkit_popups on purpose. A separate primitive here would need its own role assignment and its own row in the lifecycle matrix.'
		);
	}

	/**
	 * Asserts primitives() lists every primitive the roles need.
	 *
	 * @return void
	 */
	public function test_primitives_covers_every_primitive_capability() {
		$primitives = $this->primitives();

		foreach ( self::EXPECTED_PRIMITIVES as $capability ) {
			$this->assertContains(
				$capability,
				$primitives,
				sprintf( 'Primitive "%s" is never granted to a role, so any meta capability that resolves to it denies everyone.', $capability )
			);
		}
	}

	/**
	 * Asserts primitives() lists nothing but primitives.
	 *
	 * @return void
	 */
	public function test_primitives_contains_no_meta_capability_names() {
		$primitives = $this->primitives();

		foreach ( self::META_CAPABILITY_NAMES as $meta_name ) {
			$this->assertNotContains(
				$meta_name,
				$primitives,
				sprintf(
					'"%s" is a meta capability. Granting it to a role would satisfy the per-object check for every popup regardless of author or status, which is exactly what map_meta_cap() exists to prevent.',
					$meta_name
				)
			);
		}

		foreach ( array( 'edit_post', 'read_post', 'delete_post' ) as $core_meta_name ) {
			$this->assertNotContains(
				$core_meta_name,
				$primitives,
				sprintf( '"%s" is a core meta capability name and must never be granted to a role.', $core_meta_name )
			);
		}
	}

	/**
	 * Asserts primitives() carries nothing unexpected.
	 *
	 * @return void
	 */
	public function test_primitives_contains_only_expected_names() {
		$primitives = $this->primitives();

		$this->assertNotEmpty( $primitives, 'primitives() returned nothing; activation would grant no capabilities at all.' );

		$permitted = array_merge( self::EXPECTED_PRIMITIVES, array( 'read' ) );

		foreach ( $primitives as $capability ) {
			$this->assertIsString( $capability, 'Every primitive must be a capability name.' );
			$this->assertNotSame( '', trim( (string) $capability ), 'Primitive capability names must not be empty.' );
			$this->assertContains(
				$capability,
				$permitted,
				sprintf( 'Unexpected primitive "%s". Every granted capability must appear in the documented map.', $capability )
			);
		}
	}

	/**
	 * Asserts primitives() and the map agree.
	 *
	 * Two sources of truth that disagree is the failure mode this catches: a
	 * capability the post type demands but activation never grants.
	 *
	 * @return void
	 */
	public function test_primitives_matches_the_primitive_values_in_the_map() {
		$map        = $this->capability_map();
		$primitives = $this->primitives();

		$from_map = array();

		foreach ( $map as $key => $value ) {
			if ( 'read' === $key || in_array( $key, array( 'edit_post', 'read_post', 'delete_post' ), true ) ) {
				continue;
			}

			$from_map[ $value ] = $value;
		}

		$from_map = array_values( $from_map );
		sort( $from_map );

		$granted = array_values( array_diff( $primitives, array( 'read' ) ) );
		sort( $granted );

		$this->assertSame(
			$from_map,
			$granted,
			'Every primitive named in the capability map must be granted by activation, and nothing else may be. A capability the post type requires but no role holds denies the operation with no error.'
		);
	}

	/**
	 * Asserts primitives() contains no duplicates.
	 *
	 * @return void
	 */
	public function test_primitives_contains_no_duplicates() {
		$primitives = $this->primitives();

		$this->assertSame(
			array_values( array_unique( $primitives ) ),
			array_values( $primitives ),
			'primitives() must be a distinct set. Duplicates suggest the list is assembled from the map without deduplicating create_posts against edit_posts.'
		);
	}

	/**
	 * Returns the plugin's capability map.
	 *
	 * The accessor name is resolved rather than hard coded so this file does not
	 * have to be edited in lockstep with a rename. Accepts either the bare map or
	 * the post type argument array that wraps it under a `capabilities` key.
	 *
	 * @return array<string, string>
	 */
	private function capability_map() {
		$map = $this->call_capabilities_method(
			array( 'for_post_type', 'capability_map', 'map', 'get_map', 'capabilities' )
		);

		$this->assertIsArray( $map, 'The capability map accessor must return an array.' );

		if ( isset( $map['capabilities'] ) && is_array( $map['capabilities'] ) ) {
			$map = $map['capabilities'];
		}

		return $map;
	}

	/**
	 * Returns the primitive capabilities activation grants to roles.
	 *
	 * @return string[]
	 */
	private function primitives() {
		$primitives = $this->call_capabilities_method(
			array( 'primitives', 'all_primitives', 'get_primitives' )
		);

		$this->assertIsArray( $primitives, 'primitives() must return an array.' );

		/*
		 * Accept the two shapes a capability list is plausibly returned in: a
		 * plain list of names, or a name => true map of the kind WP_Role stores.
		 */
		if ( array() !== $primitives && array() === array_filter( $primitives, static fn( $value ) => ! is_bool( $value ) ) ) {
			$primitives = array_keys( $primitives );
		}

		// Accept a role => capabilities structure by flattening it.
		$flat = array();

		foreach ( $primitives as $value ) {
			if ( is_array( $value ) ) {
				$flat = array_merge( $flat, array_values( $value ) );
				continue;
			}

			$flat[] = $value;
		}

		return array_values( $flat );
	}

	/**
	 * Calls the first static method on Popkit\Capabilities that exists.
	 *
	 * @param string[] $candidates Method names, most likely first.
	 * @return mixed
	 */
	private function call_capabilities_method( array $candidates ) {
		foreach ( $candidates as $method ) {
			if ( method_exists( Capabilities::class, $method ) ) {
				return call_user_func( array( Capabilities::class, $method ) );
			}
		}

		$this->fail(
			sprintf(
				'Popkit\Capabilities exposes none of the expected accessors: %s. Update this list if the accessor was renamed.',
				implode( ', ', $candidates )
			)
		);
	}
}
