# Claude Code Instructions: TEC Data Generator

## Skills

The user can takes advantage of generate/delete data using skills located [skills](./skills/)

## What this plugin is

A standalone QA-only tool, not part of Event Tickets. It exists solely to generate (and clean up) bulk
test data — Events, Venues, Organizers, Event Series, legacy V1 RSVP tickets + attendees — so QA can stress-test
Event Tickets migrations at scale (thousands of tickets/attendees) and test series functionality. See `README.md` for usage.

It also consolidates generation logic ported from two sibling extensions —
[`tribe-ext-test-data-generator`](https://github.com/mt-support/tribe-ext-test-data-generator) (Events/Venues/
Organizers) and [`tec-ext-et-data-generator`](https://github.com/mt-support/tec-ext-et-data-generator)
(RSVP/paid Tickets/Attendees) — so QA has one tool instead of three. See
`docs/specs/2026-09-03-external-generator-consolidation-design.md` for what was and wasn't ported, and why.

It is meant to be shared with QA as a folder/zip and eventually deleted once the migration has been proven at
scale — it is not a long-lived product feature. Treat requests to "productionize" it, add build tooling, or
add it to the main plugin's Composer/npm setup as out of scope unless the user explicitly asks for that.

## Hard constraints

- **No Composer or npm dependency**. This must run by being dropped into `wp-content/plugins/` on a bare QA
  site with nothing but Event Tickets + The Events Calendar active — no `vendor/autoload.php`, no build step.
  In particular, never add `fakerphp/faker` (it's `require-dev`-only in Event Tickets' own `composer.json`
  and isn't available on a real site) — use `includes/class-data.php`'s built-in name/email pools instead.
- **No Faker, ever, including when porting from the sibling repos above.** Both of them depend on
  `fzaninotto/faker`; every piece of their randomness that got ported was rewritten against built-in pools in
  `class-data.php` instead (see the spec doc). Don't reintroduce Faker while extending this further.
- **Always create data through production code paths**, not raw `wp_insert_post()` where a real API exists.
  RSVP tickets go through `tribe( 'tickets.rsvp' )->ticket_add( $post_id, $data )`
  (`Tribe__Tickets__Tickets::ticket_add()` → `Tribe__Tickets__RSVP::save_ticket()`), and Events go through
  `tribe_events()->set_args( [...] )->create()` (The Events Calendar's repository ORM) — this is what makes
  the generated data indistinguishable from real user-created data, which is the entire point of the tool.
  Attendees have no such wrapper API; `wp_insert_post()` + the meta keys documented in `README.md` is correct
  there (see `../event-tickets/tests/_support/Commerce/Attendee_Maker.php` for the reference shape).
- **`generate`/`scenario`'s default output is legacy V1 shape only** — never make them create Tickets
  Commerce / RSVP V2 tickets. The deliberate exceptions are the paid-ticket paths, which create paid tickets
  via whatever provider an existing event already uses: the standalone `add-tickets` command
  (`Generator::add_paid_tickets()`), ported from `tec-ext-et-data-generator`, plus `generate-tickets
  --ticket-type=paid` and the legacy `generate --ticket-type=paid` flag, which both reuse that same path —
  confined to those paths; don't let paid creation leak anywhere else, and don't "fix" it by making it
  V1-only, that's its whole point.
- **RSVP ticket/attendee creation must produce V1 shape whether the site's RSVP feature is currently
  running in V1 or V2 (Tickets Commerce-backed) mode.** Event Tickets 5.x resolves
  `Tribe__Tickets__RSVP::save_ticket()`/`create_attendee_for_ticket()` through container-bound repositories
  (`tickets.ticket-repository.rsvp` / `tickets.attendee-repository.rsvp`) rather than a hard-coded post
  type — on a site that's already run the `rsvp-to-tc` migration (RSVP V2 active), those bindings point at
  Tickets Commerce repositories, so calling `tribe( 'tickets.rsvp' )->ticket_add()`/`create_attendee_for_ticket()`
  directly would silently create `tec_tc_ticket` posts instead of `tribe_rsvp_tickets`/`tribe_rsvp_attendees` —
  invisible to `Cleanup::count_by_type()`/`count_all()`, which only know the V1 post types. `Generator`'s
  `with_v1_rsvp_repositories()` wraps every such call to temporarily force the V1 repository classes (restoring
  whatever was bound before), and every current/future call site that touches `tribe( 'tickets.rsvp' )` for
  ticket or attendee creation must go through it. Verify against a site that has actually completed the
  `rsvp-to-tc` migration (RSVP V2 active), not just a fresh V1 install — the divergence is invisible on V1.
- **Don't call `tribe( 'tickets.rsvp' )->create_attendee_for_ticket()` for the standalone `add-attendees`
  path** (fixed 2026-09-04, was fatal on Event Tickets 5.30) — it forwards its `$ticket` argument straight
  into `Tribe__Tickets__Attendees::create_attendee( $ticket, $data )`, which only accepts a
  `Tribe__Tickets__Ticket_Object|int`; a `WP_Post` fails `is_numeric()` and falls through to
  `$ticket->get_provider()`, a fatal `\Error` — not an `\Exception`, so a `catch ( \Exception $e )` around it
  never catches it and the CLI/AJAX request just dies. `Generator::add_attendees()`'s RSVP branch instead
  calls the private `create_single_attendee()` writer directly (the same `wp_insert_post()` path
  `generate_batch()` uses), sidestepping the ET API entirely.
- **The `container`/`editor` generation options are presentation-only.** `container` (`mixed`/`event`/`page`)
  only restricts which container post types `generate_batch()` creates (note: `generate-events` only accepts
  `event`, not `page`, since Events have their own post type); `editor` (`classic`/`block`) only switches
  container `post_content` between plain text and Gutenberg markup (tagged via `Data::EDITOR_META_KEY`).
  Neither may branch ticket creation — tickets always go through the production `ticket_add()` path
  regardless of editor. Don't invent a "block ticket" storage shape.
- **Every post/ticket/attendee created must be tagged** with `TEC\DataGenerator\Data::GENERATED_META_KEY` (and a
  run ID). `Cleanup` relies entirely on this meta to decide what it's allowed to delete — never add a deletion
  path that queries by anything else (e.g. post title prefix, date range), since that risks deleting real site
  content on a QA site that isn't empty.
- **Orphaning (deleting a container post while leaving its RSVP ticket/attendees behind) lives in
  `Cleanup::orphan_posts()`, not `Generator`** — it's deletion logic, and `Cleanup` is where that lives per
  the rule above. It only ever force-deletes the exact post IDs it's handed (re-checking each still carries
  `GENERATED_META_KEY` first) — never a query — since `class-cli.php`'s `scenario` command already knows
  which post IDs it just generated in that run.
- **Both trigger surfaces (WP-CLI and the admin/AJAX page) must share `Generator`/`Cleanup`/`Migration`.**
  Don't duplicate creation, deletion, or migration-scheduling logic between `class-cli.php` and
  `class-ajax.php` — both call into the same `includes/class-*.php` engine classes, and that includes the
  split surfaces: `generate-events`/`generate-tickets` (CLI) and the Generate Events/Generate Tickets sections
  (admin) are thin wrappers over `generate_batch()`/`add_*_tickets()` with different option presets. The one intentional
  exception is the thin orchestration loop itself (resolve `wp_rand()` values from `Data::SCENARIOS`, loop
  chunks, orphan at the end): `class-cli.php`'s `scenario()` runs it synchronously in-process (WP-CLI has no
  timeout and doesn't need persistence), while `class-scenario-job.php` runs the same shape as a
  self-rescheduling Action Scheduler job for the admin page. Both still call only `Generator`/`Cleanup` to
  actually touch the database — that duplication is fine, don't "fix" it by making one drive the other.
- **Scenario runs over HTTP go through `Scenario_Job`, a real background job, not a chunked AJAX loop.**
  Clicking "Generate scenario" on the admin page calls `as_enqueue_async_action()` (raw Action Scheduler,
  bundled by Event Tickets/TEC — guard with `function_exists( 'as_enqueue_async_action' )`, don't add it as a
  Composer dependency) and returns immediately; the actual chunks run via `Scenario_Job::process_chunk()`,
  which reschedules itself until done, then orphans and marks the job `completed`/`failed`. State lives in the
  single WP option `Scenario_Job::OPTION_KEY` (not a transient — an "edge" run can take a long time, and a
  transient could expire mid-run). This is why "edge" scenarios are no longer CLI-only: the browser tab can be
  closed the moment the job is scheduled, and the admin page's polling resumes automatically on reload.
- **Chunk everything reachable over HTTP.** The admin page's AJAX handlers must never attempt too many units
  per request (timeout risk). WP-CLI has no such ceiling and is the recommended path for the full 5000+ run.
  `Scenario_Job::CHUNK_SIZE` is 25 (fixed 2026-09-04, down from 100): on MAMP, a 100-unit chunk (up to 300
  tickets × 20 attendees) reliably exceeded the request timeout when `start()`'s synchronous first-chunk call
  ran over HTTP. The request being killed mid-`generate_batch()` left that chunk's rows in the DB without
  `done` ever being persisted (that only happens after the whole chunk returns), so the next run/retry
  regenerated the same offset — observed as roughly double tickets with half missing attendees. Don't raise
  `CHUNK_SIZE` back up without also solving that persistence gap. `process_chunk()` already has
  `set_time_limit( 0 )`, which doesn't help here since the constraint is the web server's/browser's request
  timeout, not PHP's own script timeout.
- **`process_chunk()` re-reads the live job option before persisting or rescheduling** (fixed 2026-09-04) —
  `handle_cancel_scenario()` only deletes the option and unschedules *pending* Action Scheduler actions; it
  can't stop a chunk already executing. Without the re-read, that in-flight chunk's stale in-memory `$job`
  would get saved after cancel, resurrecting `status: running` and rescheduling another chunk, so a cancelled
  scenario kept running to completion. The guard compares `status` and `run_id` against the freshly-fetched
  option and bails (no save, no reschedule) if either no longer matches — in both the success path and the
  `catch ( \Throwable $e )` path. Any future edit to `process_chunk()` that adds another `self::save( $job )`
  call must re-fetch and re-check first, or this race comes back.
- **Never call `migrations()->schedule()` directly from a button/command without the same status guard the
  core Migrations UI uses first** (`Migration::can_run()`/`can_revert()` in `class-migration.php`, mirroring
  `Utilities\Migration_UI::show_run()`/`show_rollback()` in the vendored `stellarwp/migrations` package).
  `schedule()` itself doesn't enforce these preconditions — it'll happily (re)schedule regardless of current
  status, which either produces confusing double-runs or throws when `from_batch > to_batch`.
- **`migrations()->schedule( $migration, $operation )` defaults to scheduling ONLY batch 1** (`to_batch`
  defaults to `from_batch`, not the total batch count) — always pass an explicit `$to_batch` computed from
  `$migration->get_total_batches( $batch_size, $operation )` when the intent is a full run, exactly as
  `class-migration.php`'s `schedule_full()` does. This is easy to get wrong by copying the trait's default
  signature without reading its body.
- **Run/revert only schedule** — the actual migration work happens later, in the background, via
  Shepherd/Action Scheduler. Don't add code that blocks waiting for a migration to finish; report "scheduled"
  and let the admin page's status polling (or a follow-up CLI/status check) reflect progress.

## Using this tool (for agents)

If you're asked to actually *run* this tool against a WP install (not just develop it), the CLI is the
primary interface — WP-CLI has no timeout ceiling, unlike the admin/AJAX page. Typical flow:

```bash
# 1a. Generate plain test data. Defaults to 5000 units split evenly across Event/Page/Post,
#     1 RSVP ticket per unit.
wp tec-data-generator generate --count=30 --min-attendees=1 --max-attendees=20

# 1b. ...or generate one of the two pre-defined QA scenarios instead — this is the tool's main
#     purpose (see "the major point of the plugin" framing in README.md): realistic day-to-day
#     load, or an extreme-scale edge case, BOTH including a percentage of orphaned RSVPs (the
#     Event/Page/Post force-deleted while its RSVP ticket + attendees are left behind).
wp tec-data-generator scenario --type=usual   # 25-250 units, 1-3 RSVP tickets/unit, 5%-20% orphaned
wp tec-data-generator scenario --type=edge    # 7k-11k units, 1-9 RSVP tickets/unit, 5%-20% orphaned
# Both `generate` and `scenario` log a run ID (e.g. run_20260715_153000_ab12cd) — save it if you
# want to scope cleanup later. `scenario` also resolves+logs the concrete unit count and orphan
# rate it picked via wp_rand() within the type's preset range (see Data::SCENARIOS) BEFORE
# generating, so don't assume a fixed size — read the logged numbers.

# 1c. ...or generate just one side: event/page containers with no tickets, or tickets
#     (on fresh containers, or attached to an existing event/page) — the admin page's
#     Generate Events / Generate Tickets sections are thin wrappers over the same engine.
wp tec-data-generator generate-events --count=30 --editor=block
wp tec-data-generator generate-tickets --count=10 --container=event --ticket-type=rsvp
wp tec-data-generator generate-tickets --event-id=123 --quantity=5

# 1d. ...or generate event series: grouped events with different venues/organizers linked together.
wp tec-data-generator generate-series --count=5 --events-per-series=3 --with-venues --with-organizers
wp tec-data-generator generate-series --count=10 --events-per-series=2 --ticket-type=paid

# 2. Migrate it (schedules the full rsvp-to-tc run in the background via Shepherd/Action Scheduler —
#    this returns immediately, it does NOT wait for the migration to finish).
wp tec-data-generator migrate
# Poll status rather than assuming completion:
wp tec migrations executions rsvp-to-tc

# 3. Revert (puts the same data back into V1 shape so it can be migrated again without regenerating):
wp tec-data-generator revert

# 4. Remove everything this tool created (all runs, or just one):
wp tec-data-generator cleanup
wp tec-data-generator cleanup --run=run_20260715_153000_ab12cd
```

Don't call `migrate`/`revert` in a loop expecting synchronous completion — they only schedule. If either
errors, it's a status-guard rejection (see `Migration::can_run()`/`can_revert()`), not a crash — read the
error message, it names the actual blocking state.

No prerequisites beyond Event Tickets + The Events Calendar being active: this plugin's bootstrap
(`tec-data-generator.php`) already filters `tribe_tickets_post_types` at runtime so tickets can attach to
Events/Pages/Posts. Don't add a separate "enable ticketable post types" step — it's already handled.

**Reference implementations already exist — don't reinvent RSVP creation.** Event Tickets' own test suite has
working V1 *and* V2 RSVP creation code that goes through the same production APIs this plugin uses:
- V1: `../event-tickets/tests/_support/Commerce/RSVP/Ticket_Maker.php`,
  `.../Commerce/RSVP/Attendee_Maker.php`, and — closest to this plugin's own approach —
  `.../Commerce/RSVP_To_TC_Migration/Production_Ticket_Maker.php` (`create_production_rsvp_ticket()`).
- V2 (Tickets Commerce RSVP): `.../Commerce/RSVP/V2/Ticket_Maker.php`, `.../Commerce/RSVP/V2/Attendee_Maker.php`,
  and `Production_Ticket_Maker::create_production_tc_rsvp_ticket()`.

If a future task here needs V2/post-migration data shapes (this plugin currently only creates V1 — see Hard
constraints), copy/adapt from those traits rather than working out the meta shape from scratch. They're
test-only code (not autoloadable from this plugin without Composer), so treat them as a reference to port
logic from, not a dependency to require.

## Structure

```
tec-data-generator.php       # bootstrap: dependency check, hooks, WP-CLI registration
includes/class-data.php      # marker meta constants + built-in name/email generation
includes/class-generator.php # creation logic: generate_batch() units, generate_series(), plus add_rsvp_tickets()/add_paid_tickets()/add_attendees() add-ons
includes/class-cleanup.php   # the one place deletion logic lives
includes/class-migration.php # wraps stellarwp/migrations to run/revert rsvp-to-tc, with status guards
includes/class-scenario-job.php # background (Action Scheduler) scenario job for the admin page
includes/class-cli.php       # `wp tec-data-generator generate|scenario|generate-events|generate-tickets|generate-series|add-rsvp|add-tickets|add-attendees|cleanup|migrate|revert`
includes/class-admin.php     # Tools > TEC Data Generator page (also enqueues tribe-common-admin for TEC CSS vars, guarded)
includes/class-ajax.php      # chunked generate/generate-events/generate-tickets/generate-series/cleanup AJAX + scenario schedule/status + migration run/revert/status
views/admin-page.php         # admin page markup (Scenario, Add-to-existing, Generate Events, Generate Tickets, Generate Series, Cleanup, Migration sections)
assets/admin.js, admin.css   # vanilla JS: chunk loops (generate/events/tickets/series/cleanup), status polling (scenario/migration), no build step; CSS uses TEC admin variables with fallbacks
```

## Verifying changes

There's no automated test suite for this plugin (it's not integrated into Event Tickets' Codeception setup,
and isn't expected to be). Verify changes manually against a real WordPress install:

```bash
wp tec-data-generator generate --count=10   # small smoke test after any Generator change
wp tec-data-generator generate-events --count=10 --editor=block  # smoke test after any container/editor change
wp tec-data-generator generate-tickets --count=5 --container=page # smoke test after any ticket-attach change
wp tec-data-generator generate-series --count=2 --events-per-series=3 --with-venues # smoke test after any series change
wp tec-data-generator scenario --type=usual # smoke test after any scenario/orphaning change (small by design)
wp tec-data-generator migrate               # smoke test after any Migration change; check status settles to "completed"
wp tec-data-generator revert                # confirm revert puts status back to a runnable state
wp tec-data-generator cleanup               # confirm cleanup removes exactly what was created, orphans included
```

For `scenario` specifically, also spot-check that the orphan rate actually took effect: after it logs
"Orphaned N container posts", pick one of the still-existing RSVP tickets/attendees tagged with this run's ID
and confirm its `_tribe_rsvp_for_event`/`_tribe_rsvp_event` post ID no longer resolves to a post (`get_post()`
returns `null`) — that's the orphaned state the migration needs to handle. Never test `--type=edge` as your
first smoke test after a change; it can create ~100k+ posts — verify against `usual` first.

After any `class-scenario-job.php` change, also smoke-test the admin page's background path specifically (the
CLI `scenario` command doesn't exercise `Scenario_Job` at all): click "Generate scenario" (Usual) on
Tools → TEC Data Generator, confirm the progress bar advances without you doing anything (Action Scheduler
processes it as you browse elsewhere in wp-admin), then reload the page mid-run and confirm it resumes
polling and shows the same in-progress state rather than looking idle.

If you don't have a live WP/Lando environment available in your session, say so explicitly rather than
claiming the change works — `php -l` only catches syntax errors, not whether the WordPress/Event
Tickets/The Events Calendar APIs were called correctly.

After any change touching RSVP ticket/attendee creation (`create_rsvp_ticket()`, `create_adhoc_rsvp_ticket()`,
`add_attendees()`'s RSVP branch, or `with_v1_rsvp_repositories()` itself), smoke-test on **both** an RSVP V1
site and one that has completed the `rsvp-to-tc` migration (RSVP V2 active) — check the admin page's
"Currently generated" Ticket count actually increments after `add-rsvp`/`generate-tickets` on the V2 site.
The V1-vs-V2 divergence is invisible if you only ever test against a fresh V1 install.

## Coding style

Follow standard WordPress PHP coding conventions (tabs for indentation, snake_case, Yoda-optional, visibility
declared on all methods/properties) — this plugin doesn't need to follow Event Tickets' stricter
`TEC\...`-namespaced, `tec_events_`-prefixed conventions from `../event-tickets/.claude/CLAUDE.md`, since it's
a separate, non-shipping tool, but keep it simple and readable rather than inventing new patterns.

## Commit

These rules are mandatory when generating a commit message or PR description.

### Strict Constraints

- Subject must be under 72 characters, use imperative mood, and end without a period.
- Scope is required.
- NEVER add "Co-authored-by" on commits or PRs.
- Only use these types: feat, fix, docs, style, refactor, perf, test, build, ci, chore, revert.
- If the change is non-trivial, include a body with bullet points explaining: Why it was made, What changed, and the business/user impact.
- Ask the operator if the agent can commit, don't commit without explicit authorization.

### PR Requirements

- Include Ticket ID, concise Description, Breaking Changes (if any), and Review Focus.
- Explain technical concepts in simple terms that junior developers can understand.
- NEVER run `git commit`, `git amend`, `git push`, or create a PR unless the user explicitly asked you to.
