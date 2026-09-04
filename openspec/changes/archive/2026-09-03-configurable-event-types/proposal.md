## Why

The current event generator creates events via simple round-robin (Event/Page/Post) with fixed RSVP-only ticketing. QA needs fine-grained control over event generation to test realistic data scenarios: recurring events, virtual events, different ticket types, and the ability to generate events without tickets or attendees. This extends the tool from "one-size-fits-all scenarios" to a flexible event factory that mirrors actual Event Tickets/Events Pro/ECP capabilities.

## What Changes

- **Event Type Selection**: Users can now choose which event types to generate (Single, Recurring*, Virtual*) instead of round-robin. Default: random mix if not specified.
- **Recurring Events**: Generator can create recurring events via Events Pro/ECP plugin's recurrence API.
- **Virtual Events**: Generator can create virtual events with virtual metadata (via Events Pro/ECP plugin).
- **Event Configuration UI**: Admin page and CLI expanded with granular options:
  - Event quantity
  - Event types (checkboxes/multiselect)
  - Event features (RSVP tickets, Paid tickets, no tickets)
  - Attendee range per ticket
- **Plugin Requirements Clarity**: Admin UI and CLI help text explicitly state which features require Events Pro/ECP or Event Tickets Plus.
- **Backward Compatibility**: Existing `scenario` and `generate` commands remain unchanged; new options are opt-in via new commands or new flags.

*Recurring and Virtual events require Events Pro and/or ECP plugins to be active.

## Capabilities

### New Capabilities

- `event-generator/type-selection`: Users can specify which event types (single, recurring, virtual) to generate, with validation that dependent plugins are active.
- `event-generator/recurring-events`: Generator creates recurring events with customizable recurrence patterns via Events Pro/ECP API.
- `event-generator/virtual-events`: Generator creates virtual events with virtual meeting URLs and platform info (ECP plugin required).
- `event-generator/configurable-tickets`: Granular control over ticket generation: RSVP, paid (via Event Tickets), or no tickets per event.
- `event-generator/plugin-dependency-validation`: Runtime checks that required plugins (Events Pro, ECP, Event Tickets Plus) are active before allowing corresponding features.

### Modified Capabilities

- `event-generator/generate-base`: **Note**: The existing `generate` command behavior remains unchanged (V1 RSVP only, round-robin events). New event type/recurrence/virtual features are opt-in via new command options or CLI flags, not applied retroactively to `generate`.

## Impact

**Code**:
- `includes/class-generator.php`: Add methods for recurring/virtual event creation, event-type selection logic, plugin availability checks.
- `includes/class-cli.php`: Add new flags to `generate` command (e.g., `--event-types=single,recurring,virtual`, `--ticket-type=rsvp|paid|none`), or new subcommand if preferred.
- `includes/class-ajax.php`: Corresponding AJAX handlers for admin UI.
- `views/admin-page.php`: New form section for event type/ticket type selection.
- `assets/admin.js`: Client-side validation and UI state management for feature availability based on plugin checks.

**Dependencies**:
- Runtime checks for Events Pro, ECP, Event Tickets Plus (no new Composer dependencies — check via `function_exists()` / `class_exists()`).
- Uses existing Events Calendar repository ORM for event creation (already in use for single events).

**APIs**:
- New generator methods will follow existing patterns: batch creation, run-ID tagging, production code paths only.
- No breaking changes to existing `generate`, `scenario`, or add-* commands.
