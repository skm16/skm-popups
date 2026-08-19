=== PopKit ===
Contributors: seankyleandmanley
Tags: popup, modal, accessibility, banner, newsletter
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accessible, lightweight popups. Native dialogs, full keyboard and screen reader support, cache-safe targeting, and no jQuery.

== Description ==

PopKit builds popups out of the browser's own `<dialog>` element and the block
editor, rather than out of a framework and a modal library. The result is a
frontend bundle of about 6 KB gzipped, a keyboard and screen reader experience
that behaves the way the platform behaves, and targeting that stays correct
behind a page cache.

= Accessible by construction, not by audit =

Every popup renders a visible close button, in every layout and theme, with no
setting that removes it. That is a deliberate constraint rather than an
oversight: an overlay click carries no accessible name, is unreachable by
keyboard, and is undiscoverable by assistive technology, so it is offered as an
*additional* way to dismiss a popup and never as the only one.

* Modals are the native `<dialog>` element opened with `showModal()`, so Escape
  closes, focus is contained, and the page behind is inert — all from the
  platform rather than from JavaScript that has to be maintained.
* Focus returns to whatever opened the popup. When nothing did — a page-load
  trigger, say — it returns to wherever it was.
* A form field is never focused on open. Focus lands on the dialog or its
  heading, so a screen reader announces what appeared before what to type.
* The popup is named by its own heading — by reference when PopKit anchored
  that heading itself, and by the heading's text when you gave it an HTML
  Anchor, so a matching anchor elsewhere on the page can never name the popup
  with somebody else's words. With no heading, the editor warns, because a
  dialog announced as "dialog" and nothing else tells a visitor that something
  happened and nothing about what.
* Banners are a labelled `region` landmark, not a dialog. Announcing a
  non-modal strip as a dialog is a lie about whether the page behind it is
  usable.
* `prefers-reduced-motion: reduce` makes animation instant, not merely faster.
* Shipped themes meet WCAG 2.2 AA contrast in both layouts, and the close
  button keeps a 44x44 hit area everywhere.

= Cache-safe by design =

A popup plugin that decides what to show by inspecting the visitor cannot work
behind a page cache: the first visitor's popup gets cached and served to
everybody. PopKit splits the decision instead. The server decides only what the
*URL* implies and emits byte-identical HTML for every visitor; anything that
depends on the person — login state, device width, referrer, campaign
parameters, visit history — is decided in the browser.

Nothing visitor-varying is ever printed into a cached page. There is no
server-rendered timestamp, no login flag, no user ID.

= Lean =

* `dist/frontend.js` — 6.25 KB gzipped, against an enforced 8 KB budget
* `dist/frontend.css` — 1.63 KB gzipped, against an enforced 4 KB budget

Those are build-time assertions, not aspirations: `npm run size` fails the build
if either is exceeded. No jQuery, no framework, no external requests.

Nothing is enqueued on a page where no popup survives targeting — no script, no
stylesheet, no configuration JSON, no markup.

= Targeting =

Rules are grouped. Rules inside a group must all match; any one group matching
is enough. Twelve conditions ship:

* **Content** — post type, specific posts, taxonomy term, front page, 404 page,
  page template, URL path
* **Visitor** — device width, login state, referrer, campaign parameter, visit
  history

URL matching uses a closed four-mode language — exact, prefix, contains, glob —
rather than accepting a regular expression. Neither PCRE nor a JavaScript engine
offers a dependable per-match time bound, so a plugin that compiled an author's
pattern could not honestly promise that matching a URL terminates.

= Layouts and appearance =

Two layouts. A **modal** is a native dialog, centred or near the top. A
**notification bar** is a fixed strip that leaves the page behind it usable, and
it can sit at the top of the window, at the bottom, or as a **lower third** —
the broadcast-style band two thirds of the way down.

Four themes ship — light, dark, bordered, and inherit, which follows the active
site theme's own palette. Any of them can be adjusted per popup: background,
text, link and border colours, plus border width, corner rounding, font and text
size.

Colours are hex values and everything else is a named step rather than a
measurement — *thin*, *rounded*, *large*. That is deliberate. Nothing you choose
is ever handed to the browser as CSS to be parsed, and what a step looks like
stays in the stylesheet, so a future release can retune it without rewriting
popups you have already published.

The shipped themes are measured against WCAG AA contrast. A colour pair you
choose yourself is not, so check it before publishing.

= Triggers =

Page load with an optional delay, scroll depth, clicking a CSS selector, and
deep links (`?popkit=slug` or `#popkit-slug`).

= Extending it =

Registering a condition is a PHP-only act. Declare its fields and the controls
they use, and the editor renders working UI for it with no JavaScript build step
and no changes to this plugin. A condition whose plugin is later deactivated is
not deleted: the editor shows its stored settings read-only, explains that the
popup will not display while the rule cannot be evaluated, and preserves the
values so reactivating restores the popup exactly.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/popkit`, or install it through
   **Plugins > Add New**.
2. Activate it through the **Plugins** screen.
3. Go to **Popups > Add Popup**, write the content as blocks, and configure
   targeting, triggers, schedule, frequency and appearance in the sidebar.

**Running Classic Editor?** Then so are your popups. PopKit follows whichever
editor your site already uses and renders the same five panels either way — as
sidebar panels in the block editor, or as meta boxes in the classic one. It
registers no editor filter of its own, so your configuration is not just
honoured, it is untouched.

Requires WordPress 6.5 and PHP 8.1. Both are checked on activation, and the
plugin refuses to load with an explanatory notice rather than a fatal error if
either is unmet.

= Cache configuration =

One URL must not be cached: `/wp-json/popkit/v1/context`. It is the only
response that varies by visitor, and PopKit sends `Cache-Control: no-store,
private` and `Vary: Cookie` on it. Most caches honour that. Some host-level
configurations cache REST responses regardless, and there the exclusion has to
be added by hand.

Everything else PopKit serves is safe to cache and is identical for every
visitor.

* **WP Rocket** — the path is excluded automatically by its REST handling; no
  change needed unless "Cache REST API" has been forced on.
* **LiteSpeed Cache** — add `/wp-json/popkit/v1/context` to
  *Cache > Excludes > Do Not Cache URIs*.
* **W3 Total Cache** — add the path under *Page Cache > Never cache the
  following pages*.
* **WP Super Cache** — REST responses are not page-cached by default; no change
  needed.
* **Cloudflare** — the default rules do not cache `/wp-json/`. If a Cache Rule
  has been added that does, exclude this path from it.
* **Nginx FastCGI cache / Varnish** — most WordPress recipes already bypass
  `/wp-json/`. Confirm yours does.

If the endpoint is cached, popups using login state, or a schedule in the
visitor's timezone, will behave as though every visitor were the first one the
cache saw. Popups that use neither never call the endpoint at all.

== Frequently Asked Questions ==

= Does it work with a page cache? =

Yes, and it is built for it. The server never makes a rendering decision that
depends on the visitor, so the HTML it emits is identical for everybody and safe
to cache. See the cache configuration notes above for the one endpoint that must
be excluded.

= Why is there no option to hide the close button? =

Because there is no accessible way to offer one. A popup with no visible close
button can only be dismissed by an overlay click or the Escape key — neither of
which has an accessible name, is reachable by keyboard in the case of the
overlay, or is discoverable by a screen reader user. Both remain available as
additional ways to dismiss a popup.

= Can I use a regular expression for URL targeting? =

No, and this is deliberate. Neither PCRE nor a JavaScript engine offers a
dependable per-match wall-clock bound, so a plugin that compiled an author's
pattern could not honestly claim that matching a URL terminates. The four modes
provided — exact, prefix, contains, glob — cover the cases regular expressions
were being used for, and match in linear time.

= Does it load anything on pages with no popup? =

No. If no popup survives targeting, PopKit contributes nothing to the response:
no script tag, no stylesheet, no inline configuration, no markup.

= Does it work with Classic Editor? =

Yes, and it does not fight it. Popups open in whichever editor your site uses.
Targeting, triggers, schedule, frequency and appearance appear as meta boxes on
the classic screen and as sidebar panels in the block editor, built from the same
definitions, so a condition added by another plugin shows up on both.

Exactly one of the two interfaces is ever active, so a popup's settings can never
depend on which screen saved it last.

To override your site's choice for popups only:

`add_filter( 'popkit_use_block_editor', '__return_true' );`  — always block editor
`add_filter( 'popkit_use_block_editor', '__return_false' );` — always classic editor

Leave the filter alone and PopKit adds no editor filters at all.

= Is there a limit to how many popups I can have? =

You can author as many as you like. A single pageview considers **100 published
popups**, the oldest 100 by publish order, and that limit is applied by the
database before any targeting is evaluated — so on a site with more than 100
published popups, the ones past that point cannot appear on any page, whatever
their targeting says. The most recently published are the ones that lose.

PopKit does not let that happen quietly: once a site crosses the limit, a warning
appears on the Popups screens saying how many popups are dark and what to do
about it. Unpublishing or trashing popups you no longer need brings the rest back
immediately.

Drafted, scheduled and trashed popups do not count — only published ones.

Developers: the candidate query runs with `suppress_filters` false, so
`pre_get_posts` can raise the bound. A filter that does must keep the query to
published posts and keep a total order with no ties, or the emitted HTML stops
being identical for every visitor and the plugin is no longer cache-safe.

= What happens to my popups if I uninstall it? =

They are left alone. Uninstalling removes nothing by default — popups are
authored content, and deleting a site's content because a plugin was removed is
not a decision a plugin should make silently.

To opt in to full removal, go to **Popups → Settings** and tick *Delete all
PopKit data when the plugin is deleted* before deleting the plugin. That screen
needs the `manage_options` capability: it authorizes permanently deleting every
popup on the site, so someone who can write popups cannot arm it on their own.

= Does it send anything to an external service? =

No. PopKit makes no external requests of any kind — no analytics, no fonts, no
CDN, no phone-home, no update check beyond WordPress's own.

== Privacy ==

PopKit collects nothing, stores nothing about visitors on the server, and
contacts no external service.

= The context endpoint =

One REST route exists for visitor-dependent data:
`GET /wp-json/popkit/v1/context`. It is called only when a popup on the page
actually needs it, and only for the fields that popup needs.

**What it returns.** At most two values, and only those the client asked for:

* `time` — the server's current time in epoch milliseconds. Requested when a
  popup has a schedule, so the browser can correct a wrong device clock.
* `user.state` — the string `"logged_in"` or `"logged_out"`. Requested when a
  popup targets login state.

**What it does not return.** No user ID, no username, no display name, no email
address, no role, no capability list, no IP address, no page identity. The
response is a timestamp and a boolean.

**How login state is determined.** The route reads WordPress's logged-in cookie
and validates it, solely to derive that boolean. It does not resolve a user
object, does not log the value, and retains nothing. A site owner reading their
access logs sees a request with no identifying content, and a cache
misconfigured to store the response would leak a timestamp and a yes/no rather
than anything about a person.

**What it writes.** Nothing. The route is read-only: no option, no post meta, no
transient, no user meta, no database write of any kind.

= What is stored in the visitor's browser =

Frequency capping needs to remember that a popup was shown. Depending on the
frequency mode a popup uses, PopKit writes one key per popup — `popkit:seen:{id}`
— to:

* `sessionStorage` — for "once per session", cleared when the tab closes.
* `localStorage` — for "once every so many days" and "once, ever".

The stored value holds two things: the time the popup was last opened, and
whether the visitor completed its purpose rather than merely closing it. No
identifier is generated, no cookie is set, and nothing is transmitted anywhere.
A visitor clearing site data resets it.

Popups using the "every time" frequency mode read and write nothing at all.

= What is stored on the site =

Popups are a custom post type, with their configuration in post meta. That is
authored content and is treated as such: uninstalling the plugin leaves it in
place unless *Delete all PopKit data when the plugin is deleted* has been ticked
under **Popups → Settings**.

PopKit records nothing about who saw a popup, when, or whether they interacted
with it. There is no analytics table and no event log.

== Accessibility ==

PopKit is built to the WordPress accessibility-ready guidelines, and its
accessibility behaviour is covered by automated tests rather than asserted here.

= Criteria met =

* **Keyboard navigation** — every control is reachable and operable by keyboard.
  Modals contain focus while open, Escape closes them, and focus returns to the
  element that opened the popup.
* **Controls** — the close control is a real `<button>` with a text accessible
  name, not a `<span>`, an icon font, or a bare multiplication sign. Its hit
  area is at least 44x44 CSS pixels in every theme and both layouts.
* **Skip links and landmarks** — the banner layout is a labelled `region`
  landmark. It is not announced as a dialog, because the page behind it stays
  usable.
* **Headings** — the popup's accessible name comes from its own heading,
  falling back to the post title. A heading PopKit anchored is referenced with
  `aria-labelledby`; a heading you anchored yourself is named by its text
  instead, because an anchor PopKit did not create may also exist on the page
  behind the popup, and the page's copy would win. The editor warns when a popup
  would be announced only as "Popup".
* **Contrast** — shipped themes meet WCAG 2.2 AA in both layouts: 4.5:1 for body
  text and 3:1 for interface boundaries. Contrast is verified by measuring
  colours as a browser actually renders them, not by reading the stylesheet.
* **Images of text** — none are used.
* **Media** — PopKit ships no audio or video and adds no autoplaying media.
* **Reduced motion** — `prefers-reduced-motion: reduce` makes transitions
  instant rather than shortened.
* **Forced colors** — the focus indicator is an `outline`, which forced-colors
  mode preserves. The unthemed default uses the user agent's own `Canvas` and
  `CanvasText`, which follow the platform's own settings.

= Known limitations =

Popup *content* is authored with blocks, and PopKit cannot make an inaccessible
block accessible. An image without alternative text inside a popup is still an
image without alternative text. The editor warns about the popup-level problems
it can see — a missing accessible name, a schedule that has already expired, a
targeting set that can never match, and a rule whose condition is unavailable —
and the block editor's own checks cover the content.

== Screenshots ==

1. The popup editor. Targeting, triggers, schedule, frequency and appearance in
   the document sidebar, with every control generated from the condition
   registry rather than hardcoded.
2. Editor warnings. A stored rule whose condition is not available on the site,
   shown read-only with its saved settings preserved, alongside the warnings for
   a missing accessible name and an expired schedule.
3. The light theme, modal layout.
4. The dark theme, modal layout.

== Changelog ==

= 0.2.0 =
* Added a classic-editor interface. Targeting, triggers, schedule, frequency and
  appearance now render as meta boxes, built from the same definitions as the
  block editor sidebar. Previously a site running Classic Editor got a content
  field and none of the settings, with nothing on screen to say why.
* PopKit now follows whichever editor your site already uses, and registers no
  editor filter of its own. Override it for popups alone with
  `add_filter( 'popkit_use_block_editor', '__return_true' );` or `'__return_false'`.
* Added per-popup appearance overrides: background, text, link and border
  colours, plus border width, corner rounding, font and text size.
* Added a **lower third** position for the notification bar layout.
* Added **Popups > Settings**, holding the opt-in for deleting PopKit's data
  when the plugin is deleted. The setting existed but had no interface.
* A popup heading carrying your own HTML Anchor is now announced by its own
  text. Previously the popup borrowed the accessible name of any element on the
  page that happened to share that anchor.
* The 100-popup limit on a single pageview is now stated in the admin and in
  this readme, instead of silently dropping the most recently published popups.

= 0.1.0 =
* Initial release.

== Upgrade Notice ==

= 0.2.0 =
Fixes a blank settings screen on sites running Classic Editor, and corrects a
popup that could take its announced name from unrelated page content. Adds
appearance customisation and a lower-third notification bar.

= 0.1.0 =
Initial release.
