# TEC Data Generator

A standalone QA tool that generates bulk test data — **Events, Venues, Organizers, and legacy V1 RSVP
tickets with attendees** — so migrations like `rsvp-to-tc` in Event Tickets can be
stress-tested at scale (thousands of records at once) before shipping broadly.

This is **not** part of Event Tickets. It's a separate, throwaway plugin meant to be shared with QA, run
once (or a few times) against a disposable test site, and removed.

## TL;DR

```bash
# Drop this folder into wp-content/plugins/, activate it, then:
wp tec-data-generator scenario --type=usual     # realistic day-to-day QA data, some orphaned
wp tec-data-generator scenario --type=edge      # the edge case: much larger, some orphaned
wp tec-data-generator migrate                   # migrate it to Tickets Commerce (runs in the background)
wp tec-data-generator revert                    # ...or put it back to V1 to migrate again
wp tec-data-generator cleanup                   # remove everything this tool created
```

No setup beyond having Event Tickets + The Events Calendar active — no Composer, no build step, no manual
config. See "Recommended first run" below before scaling up to thousands of tickets. Every command below is
documented in full under [Commands](#commands).

## Requirements

- WordPress with **Event Tickets** and **The Events Calendar** active (both are read directly — no version
  checks beyond class/function existence).
- Nothing else. No Composer install, no build step — just drop the folder into `wp-content/plugins/` and
  activate it like any other plugin. Background scenario runs use Action Scheduler, which Event Tickets
  already bundles — there's nothing extra to install for that either.

## What it generates

For each unit:

1. One post — an Event (`tribe_events`, created via The Events Calendar's own repository so it has valid
   dates), a Page, or a Post — round-robined so the total splits evenly across all three types.
2. One or more legacy RSVP tickets (`tribe_rsvp_tickets`) on that post (`generate` always creates exactly 1;
   `scenario` creates a random number per unit within its preset range), each created through the same
   production code path (`Tribe__Tickets__RSVP::ticket_add()`) a real user going through the classic editor
   metabox would hit — not a raw `wp_insert_post()`. Capacity is randomized (20–200).
3. A random number of attendees (`tribe_rsvp_attendees`) per ticket within the configured min/max range,
   capped at the ticket's capacity, spread across a random number of `_tribe_rsvp_order` groups — so a single
   ticket can produce several distinct "orders" once migrated, not just one. ~90% are marked "going".

Everything created is tagged with `_tec_data_generator_generated = 1` (plus a run ID). **Nothing untagged is ever
touched** — cleanup only ever deletes what this tool created.

### Orphaned RSVPs (the edge case)

`scenario` also force-deletes a random percentage of the generated container posts (Events/Pages/Posts) after
generation, while leaving their RSVP ticket(s) and attendees in place — this is the "event was deleted but its
RSVP data wasn't" edge case, the main reason this tool exists. The orphaned tickets/attendees stay tagged, so
`cleanup` still finds and removes them afterward even though their parent post is gone.

It also filters `tribe_tickets_post_types` at runtime so RSVP tickets can attach to Events, Pages, *and*
Posts (the Event Tickets default only enables Events + Pages). This filter goes away the moment the plugin is
deactivated — it doesn't rewrite the site's stored option. Note: the runtime filter only covers generated
data and backend checks — the block editor reads the *stored* ticket-enabled post types directly, so to add
tickets *manually* to a Post (or a Page, if disabled) in the editor, enable that post type under
Event Tickets > Settings > Ticket-enabled post types first.

### Realistic Events, Venues, and Organizers (optional)

Pass `--with-venues`/`--with-organizers` to `generate` or `scenario` (or check the matching boxes on the
admin page) to attach a randomly-picked, freshly generated Venue/Organizer to each Event unit, and to use
varied, realistic-looking titles/descriptions instead of `"Loadgen Event {n}"`. Ported from
[`tribe-ext-test-data-generator`](https://github.com/mt-support/tribe-ext-test-data-generator), rewritten
against this plugin's own built-in name/content pools instead of that repo's Faker dependency.

## Commands

WP-CLI is recommended for anything beyond a few hundred tickets — it has no request-timeout ceiling, unlike
the chunked admin page (see [Usage: Admin page](#usage-admin-page-no-clissh-access) below).

### `generate`

Creates containers (Events/Pages/Posts, round-robined across all three) with tickets and attendees attached,
in one pass — the original all-in-one command.

```bash
wp tec-data-generator generate --count=5000                              # the default count
wp tec-data-generator generate --count=200 --min-attendees=5 --max-attendees=50
```

Flags: `--count` (default 5000), `--min-attendees` (default 1), `--max-attendees` (default 20),
`--batch-size` (default 100 — how many units are generated per internal progress tick; does not change the
total, just how often it logs).

#### Event types and ticket types

`generate` accepts two extra opt-in flags (defaults preserve the old behavior exactly):

```bash
# Only single events with RSVP tickets (the default — same as omitting both flags)
wp tec-data-generator generate --count=100 --event-types=single --ticket-type=rsvp

# Mixed single + recurring events, no tickets at all
wp tec-data-generator generate --count=100 --event-types=single,recurring --ticket-type=none

# Virtual events with paid tickets (via the event's configured provider)
wp tec-data-generator generate --count=50 --event-types=virtual --ticket-type=paid
```

- `--event-types`: comma-separated list of `single`, `recurring`, `virtual` (default `single`). Applies to
  Event units only — Page/Post units are unaffected. Event units cycle deterministically through the listed
  types, so chunked runs keep a stable distribution. Every Event is tagged with
  `_tec_data_generator_event_type` (`single`/`recurring`/`virtual`) alongside the usual generated marker.
- `--ticket-type`: `rsvp` (default), `paid`, or `none`. `paid` reuses the `add-tickets` path (whatever
  provider the event uses); `none` skips ticket creation entirely (and `min/max-attendees` are then ignored).

Plugin requirements (validated before anything is created — a bad combination errors out with no partial
data left behind):

| Option | Requires |
|--------|----------|
| `--event-types=recurring` | Events Pro or ECP |
| `--event-types=virtual` | ECP (Events Calendar Pro) |
| `--ticket-type=rsvp` / `--ticket-type=paid` | Event Tickets |

The admin page's **Generate** form exposes the same two options (checkboxes + radio buttons); unavailable
options are disabled with a note naming the missing plugin.

### `scenario`

Pre-defined QA scenarios — the tool's main purpose. Both include a percentage of orphaned RSVPs (see
[Orphaned RSVPs](#orphaned-rsvps-the-edge-case) above), and both resolve a concrete unit count/orphan rate via
`wp_rand()` within the preset range, printed before generation starts — every run of the same `--type`
produces a different concrete size, by design.

```bash
wp tec-data-generator scenario --type=usual
wp tec-data-generator scenario --type=edge
```

Flags: `--type` (required, `usual` or `edge`), plus the same `--min-attendees`/`--max-attendees` (defaults
1/20) and `--batch-size` (default 100) as `generate`. Presets (`includes/class-data.php`):

| type    | units          | RSVP tickets/unit | orphan rate |
|---------|----------------|--------------------|-------------|
| `usual` | 25–250         | 1–3                | 5%–20%      |
| `edge`  | 7,000–11,000   | 1–9                | 5%–20%      |

### `generate-events`

Creates containers only (Events or Pages), with no tickets attached. Use this when `generate`'s coupling of
containers + tickets isn't what you want.

```bash
wp tec-data-generator generate-events --count=50 --container=event --editor=block  # block-editor markup
wp tec-data-generator generate-events --count=20 --container=page                  # classic editor (default)
```

Flags: `--count` (default 100), `--container` (`event` default, or `page`), `--editor` (see
[Container editor](#container-editor-classic--block) below), `--event-types` (see above), `--batch-size`
(default 100), `--with-venues`/`--with-organizers`. Always creates containers with `ticket_type=none`.

### `generate-tickets`

Creates tickets only — either on fresh containers, or attached to an event/page that already exists.

```bash
# Fresh containers: 10 events, 1-3 RSVP tickets each
wp tec-data-generator generate-tickets --count=10 --container=event --min-tickets=1 --max-tickets=3

# Attach to an existing event/page instead of creating containers
wp tec-data-generator generate-tickets --event-id=123 --quantity=5 --ticket-type=rsvp
```

Flags: without `--event-id`, creates `--count` fresh containers (default 10, `--container`/`--editor`/
`--event-types` as above) with `--min-tickets`/`--max-tickets` per container (defaults 1/1) and
`--min-attendees`/`--max-attendees` per ticket (defaults 1/20); with `--event-id`, attaches `--quantity`
tickets (default 5) to that existing post instead. `--ticket-type` is `rsvp` (default) or `paid`.

#### Container editor (`classic` / `block`)

`generate-events`, `generate-tickets` (new containers only), and the matching admin sections all expose an
editor radio. `classic` (default) writes plain `post_content` exactly as before; `block` wraps the same
words in real Gutenberg paragraph/heading markup so the container opens in the block editor. Every container
is tagged with `_tec_data_generator_editor` (`classic`/`block`) alongside the usual generated marker, and
cleanup finds both. Ticket creation is unaffected — tickets always go through the production `ticket_add()`
path regardless of editor.

### `add-rsvp`, `add-tickets`, `add-attendees`

Ported from [`tec-ext-et-data-generator`](https://github.com/mt-support/tec-ext-et-data-generator). Unlike
`generate`/`scenario`/`generate-tickets`, these target an event/ticket that **already exists** on the site —
generated by this tool or not:

```bash
wp tec-data-generator add-rsvp <event_id> --quantity=5
wp tec-data-generator add-tickets <event_id> --quantity=5      # paid tickets, via whatever provider the event uses
wp tec-data-generator add-attendees <ticket_id> --quantity=10
```

`add-tickets` creates **paid** tickets (Tickets Commerce/PayPal/etc.), not RSVP — this is the one place in
this plugin that isn't V1-RSVP-shaped, by design. It returns "0 tickets added" if the event's provider is
RSVP (paid tickets don't apply there).

### `cleanup`

Deletes everything this tool has ever generated (or just one run), leaf-to-root (attendees → tickets →
container posts), force-deleted rather than trashed.

```bash
wp tec-data-generator cleanup                                        # everything, every run
wp tec-data-generator cleanup --run=run_20260715_153000_ab12cd       # just one run
```

Flags: `--run` (optional, scopes cleanup to one run — see the `run_...` ID logged at the start of
`generate`/`scenario`), `--batch-size` (default 200).

### `migrate` / `revert`

```bash
wp tec-data-generator migrate     # V1 RSVP -> Tickets Commerce
wp tec-data-generator revert      # ...and back to V1, so the same data can be migrated again
```

Both just *schedule* the migration's full run — the actual batch processing happens in the background via
Shepherd/Action Scheduler, the same as clicking "Run"/"Rollback" on the core (hidden) Migrations admin page.
The command returns as soon as scheduling succeeds; it doesn't wait for the migration to finish:

```bash
wp tec migrations executions rsvp-to-tc    # poll status instead
```

If either command errors, it's almost always because the migration isn't in a state that allows that
operation yet — see "Migration controls" below.

## Usage: Admin page (no CLI/SSH access)

Go to **Tools → TEC Data Generator**. The page has these sections:

- **Scenario (recommended)** — pick **Usual** or **Edge case** from the dropdown and click **Generate
  scenario**. Both run entirely in the background via Action Scheduler (the same mechanism the migration
  controls below already use): the button schedules the job and returns immediately, resolving a concrete unit
  count and orphan rate via `wp_rand()` just like the CLI's `scenario` command. **You can close the tab or
  navigate away as soon as it starts** — generation continues server-side, and reopening the page resumes the
  progress bar automatically. When it finishes, a notice reports exactly what was created (units, RSVP
  tickets, attendees, orphaned count) or, if something went wrong, what failed.
- **Add to existing content** — attach RSVP tickets, paid tickets, or attendees to an event/ticket that
  already exists (generated by this tool or not), via the `add-rsvp` / `add-tickets` / `add-attendees` AJAX
  actions.
- **Generate Events (containers only, no tickets)** — total count, container radio (Event/Page), editor radio
  (Classic/Block), venues/organizers, event types, and a **Generate events** button, chunked via AJAX with a
  progress bar.
- **Generate Tickets (with attendees)** — total containers (or an existing event/page ID + quantity to skip
  creating containers), container/ticket-type/editor radios, tickets-per-container and attendees-per-ticket
  ranges, event types, and a **Generate tickets** button, chunked via AJAX with a progress bar.
- **Cleanup** — the red **Cleanup all generated data** button lives here, at the end, right before Migration
  controls. Deletes everything this tool ever generated across all runs (events, pages, posts, venues,
  organizers, RSVP and paid tickets including Tickets Commerce ones, attendees, classic and block) — only
  tagged posts are touched.
- **Migration controls** — shows the `rsvp-to-tc` migration's current status, and **Run migration** /
  **Revert to V1** buttons. Both are disabled when the migration isn't in a state that allows that operation
  (e.g. "Run" is disabled while a migration is already running; "Revert to V1" only enables once a migration
  has actually completed). Clicking either schedules the full run/rollback in the background and polls status
  every few seconds until it settles.

Generate/Scenario/Cleanup all lock each other while one is running, so you can't accidentally kick off two
overlapping runs from the same tab. Only one scenario can run at a time site-wide — starting a second one
while another is still in progress is rejected with a message naming the run already in flight.

## Packaging a distributable ZIP

Since this plugin isn't shipped through the WordPress.org repo, use `package.sh` to build a clean ZIP for
sharing with QA — it excludes dev-only files (dotfiles/dotfolders, `docs/`, `openspec/`, `*.md`, `package.sh`
itself) so none of this repo's tooling leaks into the file QA installs:

```bash
./package.sh
```

This creates `tec-data-generator-<version>.zip` (version read from the `TEC_DATA_GENERATOR_VERSION` constant
in `tec-data-generator.php`) one directory above the plugin folder, ready to drop into `wp-content/plugins/`
on a QA site and activate as-is — no Composer install or build step needed on the receiving end.

## Recommended first run

Before generating 5000+ on a shared QA site, verify the tool and the migration agree on a small batch first:

```bash
wp tec-data-generator generate --count=30
# spot-check a couple of generated tickets/attendees in wp-admin (Attendees report, single ticket page)
wp tec-data-generator migrate
# wait for it to finish (watch status on the admin page, or `wp tec migrations executions rsvp-to-tc`),
# confirm the 30 tickets migrated cleanly, then:
wp tec-data-generator cleanup
```

Once that's clean, scale up to the real load-test size, and use `wp tec-data-generator revert` between test runs
to put the same data back into V1 shape without regenerating it.

## Example commands (copy-paste)

Common QA needs, end to end:

```bash
# "I just want to sanity-check the migration works at all."
wp tec-data-generator generate --count=30
wp tec-data-generator migrate
wp tec-data-generator cleanup

# "I want realistic day-to-day data, migrate it, then reset and try again."
wp tec-data-generator scenario --type=usual
wp tec-data-generator migrate
# ...inspect results, then either:
wp tec-data-generator revert          # put it back to V1 and migrate again, or
wp tec-data-generator cleanup         # tear it all down and start fresh

# "I need to stress-test the migration at the scale that broke it in the field."
wp tec-data-generator scenario --type=edge --batch-size=250
wp tec-data-generator migrate
# this can take a while both to generate and to migrate — check status with:
wp tec migrations executions rsvp-to-tc

# "I want a scenario, but with heavier attendee counts than the preset default."
wp tec-data-generator scenario --type=usual --min-attendees=10 --max-attendees=100

# "I have several runs on this site and only want to remove one of them."
wp tec-data-generator cleanup --run=run_20260715_153000_ab12cd

# "Something's wrong and I want a completely clean slate before trying again."
wp tec-data-generator cleanup
```

## Safety notes

- This tool wraps generation in WordPress's own bulk-import guards (`wp_defer_term_counting`,
  `wp_suspend_cache_invalidation`, etc.) to keep large runs performant, flushing the object cache at the end
  of each internal chunk.
- Cleanup deletes leaf-to-root (attendees → tickets → container posts) and force-deletes
  (`wp_delete_post( $id, true )`) rather than trashing, so re-running `cleanup` fully resets the site.
- Do not install this on a production site. It has no safeguards against being pointed at real content
  beyond the fact that it only ever touches posts it tagged itself.
- The Run/Revert controls schedule the *entire* migration (every batch), not just this tool's generated
  data — on a site with other legacy RSVP data lying around, running the migration will migrate that too.
  This tool doesn't scope the migration operation itself, only the test data it generates.
- `scenario --type=edge` can create a *lot* of posts (up to 11,000 units × up to 9 RSVP tickets each × up to
  20 attendees per ticket). Expect a long-running command and a sizable database — make sure you're on
  disposable QA infrastructure, not a shared or resource-constrained box, before running it.
