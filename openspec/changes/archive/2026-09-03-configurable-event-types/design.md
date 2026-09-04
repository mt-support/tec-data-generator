## Context

Currently, the generator creates events via round-robin post types (Event/Page/Post) with fixed RSVP-only ticketing. The plugin supports pre-defined scenarios ("usual" and "edge") and CLI `generate` command with limited customization.

QA needs: (1) selective event type generation (single vs recurring vs virtual), (2) ticket type control (RSVP vs paid vs none), (3) plugin dependency validation to prevent invalid configurations. See proposal.md - Why for motivation.

The Events Calendar, Events Pro, ECP, and Event Tickets plugins are already integrated into the plugin's workflow; the design reuses their existing APIs rather than inventing new ones.

## Goals / Non-Goals

**Goals:**
- Allow users to specify which event types to generate (single, recurring, virtual)
- Validate plugin availability and fail fast with clear error messages
- Support RSVP, paid, and no-ticket modes for all event types
- Maintain backward compatibility: existing `generate` and `scenario` commands unchanged
- Provide unified feature access across CLI (WP-CLI) and admin UI (AJAX)

**Non-Goals:**
- Do not modify existing `scenario` command behavior or its preset ranges
- Do not add new Composer dependencies (only use `function_exists()` / `class_exists()` for plugin detection)
- Do not build custom recurrence/virtual event engines; reuse Events Pro/ECP APIs only
- Do not support Series event creation (out of scope per user request)

## Decisions

### Decision 1: Plugin Detection via Function/Class Existence
**Approach:** Use `function_exists()` and `class_exists()` to detect plugin availability at runtime.

**Rationale:** Lightweight, no Composer dependency, and aligns with the constraint that this tool must run on a bare WordPress install with only Event Tickets and The Events Calendar guaranteed.

**Alternatives:**
- Check `is_plugin_active()`: Requires `wp_load_plugins()` and wouldn't work in CLI contexts cleanly.
- Version-based detection: Overkill; existence is sufficient for this tool.

**Functions/Classes to Check:**
- Event Tickets: `class_exists( 'Tribe__Tickets__RSVP' )` (already used in current code)
- Events Pro: `function_exists( 'tribe_events_pro_register_provider' )` or `class_exists( 'Tribe__Events__Pro__...' )`
- ECP: `function_exists( 'tec_pro_... )` or similar (requires spot-check of ECP API)
- Event Tickets Plus: `class_exists( 'Tribe__Tickets__Plus__...' )` or provider-specific check

**Design:** Create a `class PluginAvailability` with static methods: `has_event_tickets()`, `has_events_pro()`, `has_ecp()`, `has_event_tickets_plus()`. Called early in feature validation.

### Decision 2: Generator Extension via New Methods, Not Polymorphism
**Approach:** Add discrete methods to existing `Generator` class: `create_recurring_event()`, `create_virtual_event()`, `create_single_event()`. Existing `create_event_post()` becomes `create_single_event()` (or new method wraps it).

**Rationale:** Keeps the change localized to `class-generator.php`, avoids creating abstract base classes or factories for a single-use tool, and preserves existing code paths (existing tests, if any, still pass).

**Alternatives:**
- Polymorphic event creator class hierarchy: Over-engineered for QA-only tool.
- Strategy pattern with pluggable creators: Same problem.

**Design:** `Generator::generate_batch()` already loops over units (line 63-69); modify the loop to:
1. Determine event type (via `$this->event_type_for_sequence( $seq, $this->options['event_types'] )`)
2. Call corresponding `create_*_event()` method based on type
3. All methods return the same `$post_id` and follow the same tagging/meta approach

### Decision 3: Ticket Type Selection via Options Parameter
**Approach:** Add `ticket_type` key to `$options` array passed to `generate_batch()`, default to `'rsvp'`.

**Rationale:** Reuses existing options-passing pattern, minimal API surface change. No new method signatures needed; only new key in existing dict.

**Options Structure:**
```php
$options = [
    'event_types'        => ['single', 'recurring', 'virtual'], // new
    'ticket_type'        => 'rsvp', // new (rsvp|paid|none)
    'min_attendees'      => 1,
    'max_attendees'      => 20,
    'min_rsvps_per_unit' => 1,
    'max_rsvps_per_unit' => 1,
    'with_venues'        => false,
    'with_organizers'    => false,
];
```

**Design:** After event creation, ticket attachment logic (`generate_batch()` line 80+) checks `$this->options['ticket_type']`:
- `'rsvp'`: Existing path (call `$this->add_rsvp_tickets()`)
- `'paid'`: Existing `add_paid_tickets()` method (already in code)
- `'none'`: Skip ticket attachment entirely

### Decision 4: Event Type Selection via Sequence-Based Distribution
**Approach:** If `event_types` option is provided, `event_type_for_sequence()` deterministically maps sequence offset to event type, ensuring reproducible distribution across chunks.

**Rationale:** Allows chunked runs to maintain consistent type distribution; same sequence always maps to the same type regardless of chunk size. Works for both CLI and chunked AJAX runs.

**Example Logic:**
```php
$types = $this->options['event_types'] ?? ['single']; // default single
$index = $seq % count( $types );
return $types[ $index ];
```
Guarantees that `seq=0→types[0]`, `seq=1→types[1]`, `seq=2→types[0]` (if 2 types), etc.

**Alternatives:**
- Randomize per-unit: Loses reproducibility across chunks (annoying for debugging).
- Pre-shuffle the list: Adds complexity; sequence-based is simpler and adequate.

### Decision 5: Validation Early, Before Any Event Creation
**Approach:** In `generate_batch()`, before the loop starts (line 58), validate all options: check if requested event types are supported, check if ticket type is supported. Throw exception if validation fails; return empty result.

**Rationale:** Fail fast, no partial data, clear error message to user.

**Validation Logic:**
```php
foreach ( $this->options['event_types'] ?? ['single'] as $type ) {
    if ( 'recurring' === $type && ! PluginAvailability::has_events_pro_or_ecp() ) {
        throw new Exception( 'Recurring events require Events Pro or ECP plugin.' );
    }
    if ( 'virtual' === $type && ! PluginAvailability::has_ecp() ) {
        throw new Exception( 'Virtual events require ECP plugin.' );
    }
}
// Similar for ticket_type
```

### Decision 6: CLI and Admin UI Changes are Parallel, Not Shared
**Approach:** CLI (`class-cli.php`) adds new flags (e.g., `--event-types=single,recurring --ticket-type=rsvp`). Admin UI (`class-admin.php`, `views/admin-page.php`) adds new form fields. Both route to same `Generator` and `Cleanup` classes, but orchestration differs.

**Rationale:** CLI runs synchronously (WP-CLI has no timeout); admin UI runs chunked via AJAX or Scenario_Job for background execution. Keeping orchestration separate avoids tight coupling. Both call same underlying engine.

**CLI Changes:**
- `generate`: Add `--event-types` (comma-separated), `--ticket-type` (rsvp|paid|none) flags
- Help text includes plugin requirements
- Example: `wp tec-data-generator generate --count=100 --event-types=single,recurring --ticket-type=rsvp`

**Admin UI Changes:**
- Add checkboxes for "Single", "Recurring", "Virtual" under new section "Event Types"
- Disable checkboxes if required plugin is not active; add tooltip explaining requirement
- Add radio buttons or dropdown for "Ticket Type" (RSVP, Paid, None); disable as needed
- Pass selections via AJAX to same `Generator::generate_batch()` call

### Decision 7: Event Type Metadata Tagging
**Approach:** Every event created includes `_tec_data_generator_event_type = 'single'|'recurring'|'virtual'` in post meta, in addition to existing `_tec_data_generator_generated = 1` marker.

**Rationale:** Allows future filtering/reporting by event type, and helps `Cleanup` logic verify it only deletes what it created. Low overhead.

**Example Meta Keys:**
```
_tec_data_generator_generated = 1       (existing)
_tec_data_generator_run_id = 'run_...' (existing)
_tec_data_generator_event_type = 'recurring' (new)
```

### Decision 8: Recurring Event Creation via Events Pro/ECP Recurrence API
**Approach:** Call `tribe_events()->set_args( [..., 'recurrence_rules' => [...]] )->create()` (existing Events Calendar ORM), passing recurrence data per Events Pro API.

**Rationale:** Reuses existing production code path (same as single event creation, already in `create_event_post()`), avoids custom recurrence logic, compatible with existing attendee/ticket attachment.

**Risks:**
- Exact API may differ between Events Pro and ECP; may need conditional branches or unified wrapper.
- Requires spike to confirm API shape (see Open Questions).

### Decision 9: Virtual Event Creation via ECP Virtual Event Meta
**Approach:** After event creation via `tribe_events()->create()`, attach virtual event metadata via `update_post_meta()`: platform, meeting URL, etc.

**Rationale:** ECP likely stores virtual info in post meta (common pattern). No special creation API needed; metadata can be added post-hoc. Simpler than trying to pass it to create().

**Risks:**
- Exact meta keys depend on ECP schema (requires spike).

### Decision 10: Backward Compatibility via Option Defaults
**Approach:** If `event_types` or `ticket_type` options are not provided, generator defaults to existing behavior: `event_types = ['single']`, `ticket_type = 'rsvp'`.

**Rationale:** Existing `generate` and `scenario` commands continue to work unchanged. New options are explicit and opt-in.

## Risks / Trade-offs

### [Risk] Plugin API Differences Between Events Pro and ECP
**Mitigation:** Conduct a spike to confirm recurrence and virtual event API shapes for both plugins. If they differ significantly, create a thin adapter layer (`RecurrenceHandler`, `VirtualEventHandler`) to abstract the differences. Document which Events Pro and ECP versions are tested.

### [Risk] Recurrence Over AJAX Can Timeout
**Mitigation:** For background generation (admin UI via Scenario_Job), chunk size is already capped at ~100 units. For recurrence, ensure chunk logic works correctly by testing with recurring events in the admin UI smoke test (see CLAUDE.md for verification steps).

### [Risk] Virtual Event Meeting URLs Must Be Unique
**Mitigation:** Use `wp_generate_uuid4()` or similar to generate unique URL slugs per event. Store in post meta to prevent collisions. Test that `cleanup` correctly removes virtual events and their metadata.

### [Risk] Plugin Deactivation During Mid-Run
**Mitigation:** Admin UI (AJAX/Scenario_Job) gracefully skips unavailable event types mid-run and logs a warning. CLI (`wp-cli.php`) validates upfront, so no mid-run surprise (single synchronous run). Test scenario: start edge-case generation, deactivate Events Pro mid-run, confirm run completes with warning.

### [Risk] Complexity Explosion in Admin UI
**Mitigation:** Keep UI minimal: one "Event Types" checkbox group, one "Ticket Type" radio button group. No nested dependencies; disable entire feature if plugin is missing (not per-option graying).

## Migration Plan

### Deployment Steps
1. **Phase 1 (CLI):** Add `--event-types` and `--ticket-type` flags to existing `wp tec-data-generator generate` command. No UI changes. Test with WP-CLI smoke tests (see CLAUDE.md).
2. **Phase 2 (Admin UI):** Add event type and ticket type form fields to admin page. Initially, forms only support the new options; existing "Scenario" section unchanged.
3. **Phase 3 (Validation & Docs):** Add `PluginAvailability` checks, update help text and admin page docs to explain plugin requirements.

### Rollback Strategy
- Changes are additive (new options); old CLI calls continue to work with defaults.
- Admin UI form fields are new; existing data (counts table, scenario section) unaffected.
- No database migrations or data removal; cleanup logic unchanged.
- If a new option causes issues, users simply omit the flag/checkbox; defaults restore old behavior.

### Testing Checklist
- CLI: `wp tec-data-generator generate --count=10 --event-types=single,recurring --ticket-type=rsvp` (requires Events Pro or ECP active)
- Admin UI: Generate scenario with event type and ticket type selections
- Plugin checks: Verify error messages when required plugins are missing
- Metadata: Confirm `_tec_data_generator_event_type` is set on all created events
- Cleanup: Verify cleanup removes events of all types correctly
- Mid-run degradation: Start background run, deactivate Events Pro, confirm run completes with warning

## Open Questions

1. **Exact API shape for Events Pro vs ECP recurrence:** Are recurrence rules passed the same way to `tribe_events()->set_args()`? Do they use the same meta keys? (Spike required: inspect Events Pro and ECP plugin code or test against live instances.)

2. **ECP virtual event metadata keys:** What post meta keys does ECP use to store platform and meeting URL? (Spike required: check ECP plugin docs or inspect event posts created via ECP UI.)

3. **Event Tickets Plus legacy support:** Does this plugin need to support Event Tickets Plus provider (legacy), or only modern Tickets Commerce? Current code mentions it but isn't clear if it's tested. (Clarify with QA/product owner.)

4. **Recurring event occurrence limit:** Current design caps occurrences at 1-30 per event. Is this sufficient for QA testing, or do we need larger ranges for stress testing? (Low-priority; can adjust later if needed.)

5. **Hybrid event UI:** Should the admin UI offer a separate "Hybrid" option (virtual + venue), or assume users will select both checkboxes? (UX decision; assume separate option if yes, clarify with product owner.)

These questions are safe to answer later without changing the approach, specs, or task list.
