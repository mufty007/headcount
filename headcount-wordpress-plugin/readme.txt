=== Headcount Events ===
Contributors: Muhammad Tomasiewicz
Tags: events, calendar, rsvp, attendance, headcount
Requires at least: 5.0
Tested up to: 6.4
Stable tag: 2.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Display and manage events from your Headcount event management system.

== Description ==

Headcount Events plugin allows you to display published events from your Headcount event management system on your WordPress website using simple shortcodes.

**Features:**

* Display events in list or grid layout
* Single event display with full details
* Calendar view of events
* Filter by category and limit
* Responsive design
* RSVP button integration
* Caching for better performance

**Available Shortcodes:**

* `[headcount_events]` - Display all upcoming events (events only; not programs)
* `[headcount_events limit="5"]` - Limit number of events
* `[headcount_events category="worship"]` - Filter by category
* `[headcount_event id="123"]` - Display specific event
* `[headcount_calendar]` - Display events calendar (includes program sessions when the calendar feed is available)
* `[headcount_programs]` - Display published programs (requires Published + Show on Public Site in Headcount)
* `[headcount_facilities]` - Display active bookable facilities (`limit`, `layout="grid"`)
* `[headcount_facility id="3"]` or `[headcount_facility slug="hall-a"]` - Single facility detail
* `[headcount_facility_calendar facility="hall-a"]` - Facility schedule (approved bookings + IMCA event blocks)
* `[headcount_showcase]` - Tabs for events, programs, and optionally facilities (`facilities_limit="6"`)
* `[headcount_facilities_loop]` - Custom facility cards (field shortcodes inside)
* `[headcount_facility_title]`, `[headcount_facility_location]`, `[headcount_facility_price]`, `[headcount_facility_guest_book_link]`, `[headcount_facility_member_book_link]`, and more
* `[headcount_events_loop]` - Repeat custom markup per upcoming event (field shortcodes inside)
* `[headcount_event_title]`, `[headcount_event_date]`, `[headcount_event_time]`, `[headcount_event_location]`, `[headcount_event_link]`, and more — compose your own event cards
* **Elementor:** Headcount Events Loop widget + Headcount Event dynamic tags (when Elementor is active)

**Programs visibility:** In Headcount, open the program → ensure status is **Published** and **Show on Public Site** is enabled on the Schedule step. Draft programs or programs hidden from the public site do not appear in `[headcount_programs]` or the showcase Programs tab.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/headcount`
2. Activate the plugin through the 'Plugins' screen
3. Go to Settings → Headcount
4. Enter your API endpoint URL and API key
5. Use shortcodes in your pages and posts

== Frequently Asked Questions ==

= Where do I get the API key? =

Generate an API key from your Headcount admin panel under Settings → WordPress Integration.

= How do I display events? =

Use the shortcode `[headcount_events]` on any page or post.

= How do I display programs? =

Use `[headcount_programs]` or `[headcount_showcase]` for a tabbed events/programs layout. Programs must be published in Headcount with “Show on Public Site” enabled.

= How do I display facilities? =

Use `[headcount_facilities]` for a grid, `[headcount_facility slug="your-slug"]` for one facility, or `[headcount_showcase facilities_limit="6"]` to add a Facilities tab. Set the Member portal base URL (and optional facility booking URL) in plugin settings so book links work.

= Can I customize the styling? =

Yes! The plugin includes CSS classes you can override in your theme.

== Changelog ==

= 2.1.0 =
* Field shortcodes for event title, date, time, location, image, link, and more
* `[headcount_events_loop]` for custom layouts (upcoming published events only)
* Elementor Headcount Events Loop widget and Headcount Event dynamic tags

= 1.0.0 =
* Initial release
* Events list and grid layouts
* Single event display
* Calendar view
* Shortcode support
