# External Generator Consolidation — Design Spec

Date: 2026-09-03
Status: Approved by user, pending implementation plan.

## Purpose

Consolidate the generation functionality of two sibling extensions into this plugin, so QA has a single
tool instead of three:

- [`mt-support/tribe-ext-test-data-generator`](https://github.com/mt-support/tribe-ext-test-data-generator) —
  generates The Events Calendar Events/Venues/Organizers, using Faker.
- [`mt-support/tec-ext-et-data-generator`](https://github.com/mt-support/tec-ext-et-data-generator) —
  generates Event Tickets RSVPs/paid Tickets/Attendees on an arbitrary existing event, using Faker.

Both were cloned and read in full (see chat history for per-file notes); their generation logic (not their
scaffold/boilerplate — Assets/Hooks/Page/Plugin_Register/PUE/Template classes are the standard `tribe-ext`
extension skeleton and are not relevant here) is what gets ported.

## Charter update

This plugin's purpose was "generate legacy V1 RSVP pre-migration data only." That stays true for `generate`
and `scenario` (unchanged, no behavior change to their default output). Two additions expand the charter:

1. Optional, richer Event/Venue/Organizer content for `generate`/`scenario`, still entirely V1-RSVP-compatible
   (Venues/Organizers are TEC content, not RSVP/ticket content — no conflict with "V1 shape only").
2. A new, separate command family (`add-rsvp`, `add-tickets`, `add-attendees`) that attaches
   RSVP/paid-tickets/attendees to *any* existing event or ticket, ported from `tec-ext-et-data-generator`.
   `add-tickets` creates **paid** tickets (Tickets Commerce/PayPal/whichever provider the event already uses)
   — this is a deliberate, explicitly-scoped exception to "legacy V1 shape only," confined to this one new
   command. `generate`/`scenario` never produce paid tickets.

`CLAUDE.md`'s "Legacy V1 shape only" hard constraint gets reworded to describe this split precisely, so a
future agent doesn't mistake the new command for scope creep or "fix" it by making it V1-only.

## No Faker, no Composer

Both source repos require `fzaninotto/faker`. This plugin's hard constraint forbids any Composer dependency.
Every piece of Faker-driven randomness gets ported to a built-in equivalent in `class-data.php`, following the
existing `random_person()` pattern. One partial exception: the source repo's venue address list
(`Venue::generate_venue_address()`) is already a **fixed**, hardcoded switch-case of real city/street
combinations — not Faker output — so that data structure ports verbatim, no replacement needed.

## Components

### 1. `includes/class-data.php` — new built-in content pools

- `const EVENT_TITLE_VERBS`, `EVENT_TITLE_TOPICS` (small word lists) → `random_event_title(): string`
  combines them (e.g. "Discussing Modern Web Design") for variety, replacing the current fixed
  `"Loadgen Event {n}"` pattern used by `Generator::create_event_post()`. Keep a numeric suffix
  (e.g. `" (#{$seq})"`) appended for QA traceability — this is additive to the title, not a replacement of it.
- `random_event_description( string $organizer_name, string $venue_name, string $venue_city ): string` — a
  short templated paragraph reusing the existing name pool for flavor text, mirroring the shape of
  `Event::generate_event_description()` in the source but without Faker's `realText()`.
- `const ORGANIZER_COMPANY_SUFFIXES` (e.g. "& Co", "Productions", "Events Group") →
  `random_organizer(): array{name:string,phone:string,website:string,email:string,bio:string}` — name built
  from the existing `FIRST_NAMES`/`LAST_NAMES` pools + a suffix.
- `const VENUE_NAME_SUFFIXES` (Hall, Room, Cafe, Arena) and `const VENUE_ADDRESSES` (ported verbatim from the
  source's fixed NYC/Chicago/LA/Beverly Hills/San Francisco address list) →
  `random_venue(): array{name:string,address:string,city:string,state:string,country:string,phone:string,website:string,description:string}`.
- `random_phone(): string` — pattern-based, e.g. `sprintf( '(%03d) 555-%04d', wp_rand( 200, 999 ), wp_rand( 0, 9999 ) )`.

### 2. `includes/class-generator.php` — Venues/Organizers + richer Events, plus new add-on methods

**Venues/Organizers (woven into the existing unit-based flow):**

- `create_venue( int $seq ): int` — `tribe_venues()->set_args( [...] )->create()`, using `Data::random_venue()`,
  tagged via the existing `tag_generated( $id, 'venue' )`.
- `create_organizer( int $seq ): int` — `tribe_organizers()->set_args( [...] )->create()`, using
  `Data::random_organizer()`, tagged via `tag_generated( $id, 'organizer' )`.
- New `generate_batch()` options: `with_venues` (bool), `with_organizers` (bool). When either is true, the
  first call to `generate_batch()` in a run lazily creates a small pool (5 of whichever is enabled) if the run
  hasn't already created one (track via an instance property, since `Generator` is already instantiated fresh
  per run/`$run_id`). Each generated `tribe_events` unit then gets a `wp_rand()`-picked venue/organizer ID
  attached via the `'venue'`/`'organizer'` keys already accepted by `tribe_events()->set_args()`. Page/Post
  units are unaffected (no venue/organizer concept for those post types).
- `create_event_post()` switches from the literal `"Loadgen Event {$seq}"` title and static post_content to
  `Data::random_event_title()` / `Data::random_event_description()` (passing the attached organizer/venue name
  and venue city when present, else generic placeholders — mirroring the source's fallback text).

**Standalone add-ons (new methods, same class — they still need the shared `tag_generated()` tagging, per the
existing "one place creation logic lives" rule; they do NOT go through `generate_batch()`'s unit loop since
they operate on an arbitrary existing event/ticket, not a freshly created one):**

- `add_rsvp_tickets( int $event_id, int $quantity, array $options = [] ): int[]` — loops `$quantity` times
  calling the same `tribe( 'tickets.rsvp' )->ticket_add()` pattern `create_rsvp_ticket()` already uses,
  parameterized by `$options['capacity']`/`$options['stock']`/`$options['unlimited_capacity']` (mirrors
  `RSVP::add_rsvp()` in the source repo). Returns the created ticket IDs.
- `add_paid_tickets( int $event_id, int $quantity, array $options = [] ): int[]` — resolves
  `Tribe__Tickets__Tickets::get_event_ticket_provider( $event_id )`, bails (returns `[]`) if that resolves to
  the RSVP provider (mirrors the source's guard — this command is for paid tickets specifically), then calls
  `$provider->ticket_add( $event_id, $data )` per ticket, parameterized by
  `$options['capacity']`/`$options['stock']`/`$options['unlimited_capacity']`/`$options['shared_capacity']`.
  Ticket type/price randomization ports from `Ticket::get_random_ticket_type()`/`get_random_ticket_price()`
  (static lists + `wp_rand()`, no Faker involved there already). Returns the created ticket IDs.
- `add_attendees( int $ticket_id, int $quantity ): int[]` — **branches by ticket post type**, verified against
  the actual `event-tickets` source rather than assumed:
  - RSVP tickets (`post_type === 'tribe_rsvp_tickets'`): call
    `tribe( 'tickets.rsvp' )->create_attendee_for_ticket( get_post( $ticket_id ), $data )` directly.
    `Tribe__Tickets__RSVP` does **not** override the base `create_attendee()` method, so the generic
    `tribe( 'tickets.attendees' )->create_attendee()` dispatcher (`event-tickets/src/Tribe/Attendees.php:1096`)
    would route an RSVP ticket through `Tribe__Tickets__Tickets::create_attendee()`'s generic ORM-repository
    path (`event-tickets/src/Tribe/Tickets.php:4613`, → `Tribe__Tickets__Attendee_Repository::create_attendee_for_ticket()`)
    instead of `Tribe__Tickets__RSVP::create_attendee_for_ticket()` (`event-tickets/src/Tribe/RSVP.php:861`) —
    two different methods with the same name on different classes. Calling the RSVP one directly is the only
    way to guarantee the exact V1 meta shape (`_tribe_rsvp_order`, etc.) this migration-testing tool depends
    on; `$data` needs only `full_name`/`email` (required fields, validated internally) and the method returns
    an `int` attendee ID directly (not a `WP_Post`).
  - Non-RSVP (paid) tickets: use the generic `tribe( 'tickets.attendees' )->create_attendee( $ticket_id, $data )`
    dispatcher — appropriate here since it resolves and delegates to whatever provider actually owns the
    ticket. Returns a `WP_Post|false`; take `->ID` on success.

  Attendee names come from `Data::random_person()`. This does **not** replace
  `Generator::create_single_attendee()`'s existing raw `wp_insert_post()` path used by `generate`/`scenario` —
  that stays as-is; `add_attendees()` is only for the new standalone command.

### 3. `includes/class-cli.php` — new commands

```
wp tec-data-generator add-rsvp <event_id> [--quantity=<n>] [--capacity=<n>] [--stock=<n>] [--unlimited-capacity]
wp tec-data-generator add-tickets <event_id> [--quantity=<n>] [--capacity=<n>] [--stock=<n>] [--unlimited-capacity] [--shared-capacity]
wp tec-data-generator add-attendees <ticket_id> [--quantity=<n>]
```

Each is a thin wrapper: validate the target ID exists and is the right post type, call the matching
`Generator` method in `--batch-size`-sized chunks (same chunking pattern as `generate`), report progress via
`WP_CLI::log()`, and log a run ID so `cleanup --run=...` can scope to just what was added. Existing `generate`
and `scenario` commands gain `--with-venues`/`--with-organizers` flags.

### 4. Admin page — new "Add to existing content" section + venue/organizer checkboxes

- Scenario and Generate (advanced) sections each gain two checkboxes: "Attach Venues" / "Attach Organizers".
- New section below Migration controls: three small forms (Event ID + quantity → Add RSVP tickets; Event ID +
  quantity → Add paid tickets; Ticket ID + quantity → Add attendees), each with its own button, spinner, and
  notice, following the exact pattern already established for Generate/Cleanup (chunked AJAX, since these
  quantities are expected to be modest — tens to low hundreds — not scenario-scale; no background-job
  treatment needed here, but chunk them anyway per the "chunk everything reachable over HTTP" constraint).
  Three new `Ajax` actions (`add_rsvp_batch`, `add_tickets_batch`, `add_attendees_batch`), same chunked
  request/response shape as `handle_generate()`.

### 5. `includes/class-cleanup.php` — coverage for new post types

- Add `tribe_venues` and `tribe_organizers` to `POST_TYPES_IN_DELETE_ORDER` (appended after the existing
  container types — no strict ordering requirement between them and Events, since WP doesn't cascade-delete
  across this relationship either direction).
- Paid tickets' actual post type varies by provider (Tickets Commerce, PayPal legacy, WooCommerce, etc.) and
  isn't something we can enumerate exhaustively up front. Add one final catch-all pass to `cleanup_batch()`/
  `count_all()`/`count_by_type()`: after processing the known `POST_TYPES_IN_DELETE_ORDER` list, query
  `post_type => 'any'` filtered only by the marker meta, to catch any tagged post type not in the explicit
  list (this also future-proofs against providers not enumerated today).
- `count_by_type()`'s label map gains `tribe_venues => 'venue'`, `tribe_organizers => 'organizer'`, and a
  generic `'other'` bucket for whatever the catch-all pass finds.

## Data flow

`generate`/`scenario` (unchanged trigger surfaces) → `Generator::generate_batch()` (extended) → optionally
`create_venue()`/`create_organizer()` once per run → `create_event_post()` (richer content, optional
venue/organizer attachment) → existing ticket/attendee creation, unchanged.

`add-rsvp`/`add-tickets`/`add-attendees` (new trigger surfaces, CLI + admin) → new `Generator` methods,
operating directly on a caller-supplied event/ticket ID → same `tag_generated()` tagging → same `Cleanup`
(extended) finds and removes them later.

## Error handling

- New CLI commands validate their target ID up front (`get_post( $event_id )` exists and is the expected
  type) and `WP_CLI::error()` immediately if not, before doing any work — mirrors the existing
  `--eventid`/`--ticketid` required-argument checks in the source repo, translated to this plugin's
  `WP_CLI::error()` style instead of the source's `die()`/bare `\Exception`.
- `add_paid_tickets()` returns `[]` (not an error) when the event's provider is RSVP — matches the source
  repo's own guard (paid tickets genuinely don't apply there); the CLI command reports "0 tickets added
  (event's ticket provider is RSVP, not a paid provider)" rather than failing.
- AJAX add-on handlers follow the existing `verify_request()`/`wp_send_json_error()` pattern.

## Testing

No automated suite (per existing project convention). Manual smoke tests to add to `CLAUDE.md`:

```bash
wp tec-data-generator generate --count=10 --with-venues --with-organizers   # spot-check attached venue/organizer
wp tec-data-generator add-rsvp <event_id> --quantity=3
wp tec-data-generator add-tickets <event_id> --quantity=3                    # confirm paid ticket, not RSVP
wp tec-data-generator add-attendees <ticket_id> --quantity=5
wp tec-data-generator cleanup                                                # confirm venues/organizers/paid tickets all removed
```

## Explicitly out of scope (from the source repos, not being ported)

- Virtual events, recurring events (TEC PRO dependency), event categories/tags, media-library image
  attachment, and the `--fast-occurrences-insert` raw-SQL optimization from `tribe-ext-test-data-generator`.
  These add real complexity (a new optional hard dependency on TEC PRO for recurrence, direct `$wpdb` writes
  bypassing the ORM) disproportionate to this migration-testing tool's purpose. Can be revisited later if a
  concrete QA need arises.
- The source repos' own admin settings pages / PUE update-checker / standalone plugin scaffolding — not
  applicable, this plugin already has its own admin page and isn't distributed via PUE.
