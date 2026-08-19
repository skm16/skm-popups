<?php
/**
 * Integration tests for the bound on how many popups a pageview considers.
 *
 * {@see Popkit\Frontend::MAX_POPUPS} is a SQL `LIMIT`, applied by the database
 * before {@see Popkit\Rule_Evaluator} judges a single rule. That ordering is the
 * whole point of this file: the cap selects *candidates*, not winners, so on a
 * site holding more published popups than the cap there are popups which cannot
 * appear on any URL however their targeting is written.
 *
 * The constant had no test at all before this file. Its docblock claimed the cap
 * only bit when that many popups "matched one page", which is not what the code
 * does and is not a limit the plugin has;
 * {@see Test_Popkit_Popup_Limit::test_a_popup_past_the_cap_cannot_match_even_when_nothing_above_it_does()}
 * is that claim written as an executable test, and it is why the docblock was
 * rewritten rather than softened.
 *
 * Truncating is defensible here — a popup that is not emitted cannot show, so it
 * fails in the safe direction — but only because it is announced. The rest of
 * the file holds the announcement: the notice appears for someone who can act on
 * it, on the screens where they can, and nowhere else.
 *
 * @package Popkit
 */

use Popkit\Capabilities;
use Popkit\Frontend;
use Popkit\Meta;
use Popkit\Post_Type;

/**
 * Integration coverage for the popup cap and the notice that discloses it.
 */
final class Test_Popkit_Popup_Limit extends WP_UnitTestCase {

	/**
	 * Grants the popkit capabilities the way activation does.
	 *
	 * `capability_type => 'popkit_popup'` gives nobody anything on its own, and
	 * the test suite does not run the activation hook. Calling the real assigner
	 * rather than adding the capability by hand is deliberate: it makes these
	 * tests fail if the notice ever checks a capability activation does not
	 * actually grant, which is the interesting way for this gate to be wrong.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		Capabilities::assign();
	}

	/**
	 * Resets the front-end's per-request memo between tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		Frontend::reset();
		Capabilities::remove();

		parent::tear_down();
	}

	/**
	 * The candidate set stops at the cap, and drops the newest popups.
	 *
	 * Pins both halves: how many survive, and *which* ones. Ascending ID order
	 * means the popups that lose are the most recently published, which is the
	 * fact the admin notice has to be able to state.
	 *
	 * @return void
	 */
	public function test_the_candidate_set_is_capped_and_the_newest_popups_lose() {
		$ids = $this->publish_popups( Frontend::MAX_POPUPS + 1 );

		$this->go_to( home_url( '/' ) );

		$matched = wp_list_pluck( Frontend::matched_popups(), 'ID' );

		$this->assertCount(
			Frontend::MAX_POPUPS,
			$matched,
			'The query considers at most MAX_POPUPS popups.'
		);
		$this->assertNotContains(
			end( $ids ),
			$matched,
			'The most recently published popup is the one dropped by an ascending-ID cap.'
		);
		$this->assertContains(
			$ids[0],
			$matched,
			'The oldest popup is kept.'
		);
	}

	/**
	 * A popup past the cap is invisible even when nothing above it matches.
	 *
	 * The reported defect, executable. A hundred popups target a URL that is not
	 * being visited, and one newer popup targets the URL that is. Nothing above
	 * the newer popup can display, so an author would reasonably expect it to —
	 * and it cannot, because the cap was applied before any of those rules were
	 * read.
	 *
	 * @return void
	 */
	public function test_a_popup_past_the_cap_cannot_match_even_when_nothing_above_it_does() {
		$this->publish_popups( Frontend::MAX_POPUPS, '/no-match/' );
		$wanted = $this->publish_popups( 1, '/' );

		$this->go_to( home_url( '/' ) );

		$matched = wp_list_pluck( Frontend::matched_popups(), 'ID' );

		$this->assertNotContains(
			$wanted[0],
			$matched,
			'A popup below the cap cannot be reached, whatever its rules say.'
		);
		$this->assertSame(
			array(),
			$matched,
			'And the hundred above it correctly match nothing, so the page shows no popup at all.'
		);
	}

	/**
	 * The notice fires on a popup screen once the site is over the cap.
	 *
	 * @return void
	 */
	public function test_the_notice_reports_the_count_on_a_popup_screen() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->publish_popups( Frontend::MAX_POPUPS + 2 );

		$notice = $this->notice_on_screen( 'edit-' . Post_Type::POST_TYPE, Post_Type::POST_TYPE );

		$this->assertStringContainsString(
			(string) ( Frontend::MAX_POPUPS + 2 ),
			$notice,
			'The notice states how many published popups the site actually has.'
		);
		$this->assertStringContainsString(
			'notice-warning',
			$notice,
			'It is a warning, not an informational note: popups are silently dark.'
		);
		$this->assertStringNotContainsString(
			'is-dismissible',
			$notice,
			'The condition persists while the count holds, so the notice must not be dismissible.'
		);
	}

	/**
	 * Exactly at the cap is not over it.
	 *
	 * The off-by-one that would otherwise warn every site that legitimately
	 * reaches the boundary with nothing wrong.
	 *
	 * @return void
	 */
	public function test_the_notice_is_silent_at_exactly_the_cap() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->publish_popups( Frontend::MAX_POPUPS );

		$this->assertSame(
			'',
			$this->notice_on_screen( 'edit-' . Post_Type::POST_TYPE, Post_Type::POST_TYPE ),
			'A site holding exactly MAX_POPUPS popups loses none of them.'
		);
	}

	/**
	 * The notice stays on popkit's own screens and needs the capability.
	 *
	 * A warning about popups on somebody else's edit screen is noise, and a
	 * warning shown to a user who cannot unpublish a popup is worse than noise.
	 *
	 * @return void
	 */
	public function test_the_notice_is_scoped_to_popup_screens_and_to_editors_of_popups() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$this->publish_popups( Frontend::MAX_POPUPS + 1 );

		$this->assertSame(
			'',
			$this->notice_on_screen( 'edit-page', 'page' ),
			"The notice must not appear on another post type's screen."
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame(
			'',
			$this->notice_on_screen( 'edit-' . Post_Type::POST_TYPE, Post_Type::POST_TYPE ),
			'A user who cannot edit popups is not told to unpublish some.'
		);
	}

	/**
	 * The notice is actually wired to a hook.
	 *
	 * Every other test here calls the callback directly, so all of them would
	 * pass on a class nothing ever attaches — the failure mode
	 * `tests/php/integration/test-plugin-boot.php` was written about.
	 *
	 * @return void
	 */
	public function test_the_notice_is_attached_to_admin_notices() {
		$this->assertNotFalse(
			has_action( 'admin_notices', array( Post_Type::class, 'render_popup_limit_notice' ) ),
			'Post_Type::init() must attach the notice, or it can never reach a screen.'
		);
	}

	/**
	 * Publishes popups targeting a URL path.
	 *
	 * @param int    $count How many to publish.
	 * @param string $path  URL path to target, or an empty string for no rules.
	 * @return int[] Post IDs, in creation order.
	 */
	private function publish_popups( $count, $path = '' ) {
		$ids = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$id = self::factory()->post->create(
				array(
					'post_type'   => Post_Type::POST_TYPE,
					'post_status' => 'publish',
					'post_title'  => 'Limit fixture ' . $i,
				)
			);

			if ( '' !== $path ) {
				update_post_meta(
					$id,
					Meta::CONDITIONS,
					array(
						'groups' => array(
							array(
								'rules' => array(
									array(
										'type'   => 'url_path',
										'negate' => false,
										'values' => array(
											'match' => 'exact',
											'value' => $path,
										),
									),
								),
							),
						),
					)
				);
			}

			$ids[] = $id;
		}

		return $ids;
	}

	/**
	 * Captures the notice's output on a given admin screen.
	 *
	 * @param string $screen_id Screen id to set.
	 * @param string $post_type Post type the screen belongs to.
	 * @return string Rendered notice, or an empty string.
	 */
	private function notice_on_screen( $screen_id, $post_type ) {
		set_current_screen( $screen_id );

		$screen = get_current_screen();

		if ( $screen instanceof WP_Screen ) {
			$screen->post_type = $post_type;
		}

		ob_start();
		Post_Type::render_popup_limit_notice();

		return trim( (string) ob_get_clean() );
	}
}
