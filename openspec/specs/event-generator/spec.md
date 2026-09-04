# Event Generator Specification

## Purpose

The event generator creates configurable test events (single, recurring, virtual) with selectable ticket types (RSVP, paid, none), validating plugin dependencies before generation so QA can reproduce realistic and edge-case data shapes.

## Requirements

### Requirement: Event Type Selection
The generator SHALL accept a list of event types and create events matching the specified types. If no type is specified, the generator SHALL create a random mix of available types.

#### Scenario: User specifies single event type
- **WHEN** user specifies `--event-types=single` or selects "Single" in the admin UI
- **THEN** generator creates only single (non-recurring, non-virtual) events

#### Scenario: User specifies multiple event types
- **WHEN** user specifies `--event-types=single,recurring,virtual`
- **THEN** generator creates events distributed randomly across all three types

#### Scenario: No event type specified
- **WHEN** user does not specify event types
- **THEN** generator creates a random mix of available types matching the generated quantity

### Requirement: Event Type Availability
The system SHALL only allow event types whose required plugins are active. Recurring and Virtual types require Events Pro or ECP; Single events require only The Events Calendar.

#### Scenario: Recurring events with Events Pro active
- **WHEN** Events Pro plugin is active and user selects `--event-types=recurring`
- **THEN** generator successfully creates recurring events

#### Scenario: Recurring events without Events Pro
- **WHEN** Events Pro/ECP plugins are not active and user attempts `--event-types=recurring`
- **THEN** generator SHALL return an error: "Recurring events require Events Pro or ECP plugin to be active"

#### Scenario: Virtual events without ECP
- **WHEN** ECP plugin is not active and user attempts `--event-types=virtual`
- **THEN** generator SHALL return an error: "Virtual events require ECP (Events Calendar Pro) plugin to be active"

### Requirement: Event Type Tracking
Every generated event SHALL be tagged with its event type (single, recurring, or virtual) in addition to the existing `_tec_data_generator_generated` marker.

#### Scenario: Event type metadata stored
- **WHEN** event is created with type "recurring"
- **THEN** event post meta includes `_tec_data_generator_event_type = 'recurring'`

#### Scenario: Cleanup includes type metadata
- **WHEN** cleanup deletes generated events
- **THEN** cleanup finds events by the combination of `_tec_data_generator_generated` marker and type meta, ensuring correct scope

### Requirement: CLI and Admin UI Support
Both WP-CLI (`generate` command) and the admin UI form SHALL expose event type selection.

#### Scenario: CLI event type selection
- **WHEN** user runs `wp tec-data-generator generate --count=100 --event-types=single,recurring`
- **THEN** generator creates 100 events distributed across single and recurring types

#### Scenario: Admin UI event type checkboxes
- **WHEN** user opens Tools > RSVP Load Generator and checks "Recurring" and "Virtual" under "Event Types"
- **THEN** the form submission includes selected types; if Events Pro/ECP is not active, the checkbox is disabled with a note explaining the requirement

### Requirement: Recurring Event Creation
The generator SHALL create recurring events using the Events Pro or ECP recurrence API when the `recurring` event type is requested.

#### Scenario: Create daily recurring event
- **WHEN** user requests recurring event type and generator creates a unit with daily recurrence
- **THEN** event is created with recurrence pattern: daily, 10-30 occurrences

#### Scenario: Create weekly recurring event
- **WHEN** user requests recurring event type with weekly pattern
- **THEN** event is created with recurrence pattern: weekly on specified day, 4-12 occurrences

#### Scenario: Create monthly recurring event
- **WHEN** user requests recurring event type with monthly pattern
- **THEN** event is created with recurrence pattern: monthly, 2-6 occurrences

### Requirement: Recurring Event Metadata
Recurring events SHALL store recurrence pattern details in post meta for verification and cleanup purposes.

#### Scenario: Recurring pattern stored in meta
- **WHEN** recurring event is created
- **THEN** event includes `_tec_recurring_meta` or equivalent Events Pro/ECP recurrence metadata, plus `_tec_data_generator_event_type = 'recurring'`

### Requirement: Randomized Recurrence Patterns
Generated recurring events SHALL use randomized but realistic recurrence patterns: daily, weekly, or monthly, with randomized occurrence counts.

#### Scenario: Recurrence pattern randomization
- **WHEN** generator creates multiple recurring events in a single batch
- **THEN** each event is assigned a random recurrence pattern (daily, weekly, or monthly) and random occurrence count (1-30), producing varied test data

#### Scenario: Recurrence within realistic bounds
- **WHEN** recurring event is created
- **THEN** recurrence pattern has 1-30 occurrences (not infinite) to keep generated data manageable for testing

### Requirement: Recurring Events Support Tickets
Recurring events created SHALL support RSVP and paid ticket attachment, same as single events.

#### Scenario: RSVP on recurring event
- **WHEN** user specifies `--event-types=recurring --ticket-type=rsvp`
- **THEN** each recurring event occurrence SHALL have RSVP tickets attached (via existing ticket creation logic)

#### Scenario: Paid tickets on recurring event
- **WHEN** user specifies `--event-types=recurring --ticket-type=paid`
- **THEN** each recurring event occurrence SHALL have paid tickets attached (requires Event Tickets Plus or Tickets Commerce provider)

### Requirement: Recurrence Support Validation
The generator SHALL validate that Events Pro or ECP is active before allowing recurring event creation.

#### Scenario: Validation on recurring creation attempt
- **WHEN** user specifies `--event-types=recurring` and Events Pro/ECP is not active
- **THEN** generator returns error: "Recurring events require Events Pro or ECP plugin to be active"

### Requirement: Virtual Event Creation
The generator SHALL create virtual events with platform and meeting URL metadata when the `virtual` event type is requested, using ECP plugin APIs.

#### Scenario: Create virtual event with Zoom
- **WHEN** user requests virtual event type and generator creates a unit with Zoom as the platform
- **THEN** event is created with virtual metadata: platform="zoom", meeting_url="https://zoom.us/j/{randomId}"

#### Scenario: Create virtual event with Google Meet
- **WHEN** generator creates virtual event with Google Meet
- **THEN** event is created with virtual metadata: platform="google_meet", meeting_url="https://meet.google.com/xyz-abc-def"

#### Scenario: Create virtual event with Teams
- **WHEN** generator creates virtual event with Microsoft Teams
- **THEN** event is created with virtual metadata: platform="teams", meeting_url="https://teams.microsoft.com/l/meetup-join/{id}"

### Requirement: Randomized Virtual Platforms
Virtual events SHALL be distributed across multiple virtual meeting platforms (Zoom, Google Meet, Microsoft Teams) to simulate realistic test data.

#### Scenario: Platform distribution
- **WHEN** generator creates multiple virtual events in a single batch
- **THEN** platforms are randomly distributed across Zoom, Google Meet, and Teams

#### Scenario: Unique meeting URLs
- **WHEN** virtual events are created
- **THEN** each event is assigned a unique, randomized meeting URL matching its platform

### Requirement: Virtual Event Metadata
Virtual events SHALL store platform and meeting information in post meta for cleanup and verification.

#### Scenario: Virtual metadata stored
- **WHEN** virtual event is created
- **THEN** event includes ECP virtual event metadata (post meta keys for platform, meeting URL, etc.) plus `_tec_data_generator_event_type = 'virtual'`

### Requirement: Virtual Events Support Tickets
Virtual events created SHALL support RSVP and paid ticket attachment, same as single and recurring events.

#### Scenario: RSVP on virtual event
- **WHEN** user specifies `--event-types=virtual --ticket-type=rsvp`
- **THEN** each virtual event SHALL have RSVP tickets attached

#### Scenario: Paid tickets on virtual event
- **WHEN** user specifies `--event-types=virtual --ticket-type=paid`
- **THEN** each virtual event SHALL have paid tickets attached

### Requirement: Virtual Event Validation
The generator SHALL validate that ECP plugin is active before allowing virtual event creation.

#### Scenario: Validation on virtual creation attempt
- **WHEN** user specifies `--event-types=virtual` and ECP plugin is not active
- **THEN** generator returns error: "Virtual events require ECP (Events Calendar Pro) plugin to be active"

### Requirement: Hybrid Events (Optional)
The system MAY create hybrid events (both in-person and virtual) by combining virtual metadata with event location/venue information, pending ECP plugin capability.

#### Scenario: Hybrid event creation
- **WHEN** user specifies `--event-types=virtual --with-venues`
- **THEN** generator creates virtual events with both meeting URLs (virtual) and venue/location information (in-person component), if ECP supports hybrid events

### Requirement: Ticket Type Selection
The generator SHALL accept a `ticket_type` parameter that specifies which type of tickets to attach: `rsvp`, `paid`, or `none`. Default is `rsvp` for backward compatibility.

#### Scenario: Generate events with RSVP tickets
- **WHEN** user specifies `--ticket-type=rsvp` or selects "RSVP" in admin UI
- **THEN** each generated event SHALL have 1+ RSVP tickets attached (existing behavior)

#### Scenario: Generate events with paid tickets
- **WHEN** user specifies `--ticket-type=paid`
- **THEN** each generated event SHALL have paid tickets via the event's configured provider (Tickets Commerce, PayPal, etc., requires Event Tickets Plus or provider plugin)

#### Scenario: Generate events with no tickets
- **WHEN** user specifies `--ticket-type=none`
- **THEN** each generated event is created without any RSVP or paid tickets

#### Scenario: Default ticket type (RSVP)
- **WHEN** user does not specify ticket type
- **THEN** generator defaults to `ticket_type=rsvp` and creates events with RSVP tickets

### Requirement: Ticket Configuration Validation
The system SHALL validate that required plugins are available for the requested ticket type before generating events.

#### Scenario: Paid tickets require Event Tickets
- **WHEN** user specifies `--ticket-type=paid` and Event Tickets plugin is not active
- **THEN** generator returns error: "Paid tickets require Event Tickets plugin to be active"

#### Scenario: RSVP tickets validation
- **WHEN** user specifies `--ticket-type=rsvp` and Event Tickets plugin is not active
- **THEN** generator returns error: "RSVP tickets require Event Tickets plugin to be active"

### Requirement: Attendee Range Configuration
For events with RSVP or paid tickets, the generator SHALL respect `min_attendees` and `max_attendees` parameters.

#### Scenario: Attendees on RSVP tickets
- **WHEN** user specifies `--min-attendees=5 --max-attendees=50 --ticket-type=rsvp`
- **THEN** each RSVP ticket is created with 5-50 attendees (randomly distributed within that range)

#### Scenario: Attendees on paid tickets
- **WHEN** user specifies `--min-attendees=1 --max-attendees=100 --ticket-type=paid`
- **THEN** each paid ticket is created with 1-100 attendees

#### Scenario: Attendee range ignored for no-ticket events
- **WHEN** user specifies `--ticket-type=none`
- **THEN** `min_attendees` and `max_attendees` parameters are ignored

### Requirement: Multiple Tickets Per Event
The generator SHALL support creating multiple tickets per event (via existing `min_rsvps_per_unit`/`max_rsvps_per_unit` configuration).

#### Scenario: Multiple RSVP tickets per event
- **WHEN** user specifies `--min-rsvps=2 --max-rsvps=5 --ticket-type=rsvp`
- **THEN** each event is created with 2-5 RSVP tickets, each with independent attendee counts

#### Scenario: Multiple paid tickets per event
- **WHEN** user specifies `--min-rsvps=1 --max-rsvps=3 --ticket-type=paid`
- **THEN** each event is created with 1-3 paid tickets

### Requirement: Ticket Type Applies to All Event Types
Ticket type selection SHALL apply uniformly to single, recurring, and virtual events.

#### Scenario: Recurring events with paid tickets
- **WHEN** user specifies `--event-types=recurring --ticket-type=paid`
- **THEN** each recurring event occurrence has paid tickets attached

#### Scenario: Virtual events with no tickets
- **WHEN** user specifies `--event-types=virtual --ticket-type=none`
- **THEN** virtual events are created without tickets

### Requirement: Plugin Availability Detection
The generator SHALL detect and report the availability of dependent plugins: Event Tickets, Events Pro, ECP, Event Tickets Plus.

#### Scenario: Detect Event Tickets active
- **WHEN** Event Tickets plugin is active
- **THEN** RSVP and paid ticket features are available

#### Scenario: Detect Events Pro active
- **WHEN** Events Pro plugin is active
- **THEN** recurring event feature is available

#### Scenario: Detect ECP active
- **WHEN** ECP (Events Calendar Pro) plugin is active
- **THEN** virtual event and recurring event features are available

#### Scenario: Detect Event Tickets Plus active
- **WHEN** Event Tickets Plus plugin is active
- **THEN** advanced paid ticket types (legacy Event Tickets Plus) are supported

### Requirement: Feature Validation Before Generation
The generator SHALL validate that all requested features have their required plugins active before starting event generation. If validation fails, it SHALL return an error without generating any events.

#### Scenario: Recurring events validation fails
- **WHEN** user requests `--event-types=recurring` and neither Events Pro nor ECP is active
- **THEN** generator returns error before creating any events: "Recurring events require Events Pro or ECP plugin to be active"

#### Scenario: Virtual events validation fails
- **WHEN** user requests `--event-types=virtual` and ECP is not active
- **THEN** generator returns error before creating any events: "Virtual events require ECP (Events Calendar Pro) plugin to be active"

#### Scenario: Validation passes for available features
- **WHEN** user requests `--event-types=single --ticket-type=rsvp` and Event Tickets is active
- **THEN** validation passes and generation proceeds

### Requirement: Admin UI Feature Availability
The admin page SHALL dynamically enable/disable feature options based on plugin availability.

#### Scenario: Recurring checkbox disabled without Events Pro
- **WHEN** user opens Tools > RSVP Load Generator and Events Pro/ECP is not active
- **THEN** "Recurring" event type checkbox is disabled with tooltip: "Requires Events Pro or ECP plugin"

#### Scenario: Virtual checkbox disabled without ECP
- **WHEN** user opens Tools > RSVP Load Generator and ECP is not active
- **THEN** "Virtual" event type checkbox is disabled with tooltip: "Requires ECP plugin"

#### Scenario: Paid tickets disabled without Event Tickets
- **WHEN** user opens Tools > RSVP Load Generator and Event Tickets is not active
- **THEN** "Paid" ticket type option is disabled with tooltip: "Requires Event Tickets plugin"

### Requirement: Help Text on Missing Dependencies
Both CLI and admin UI SHALL provide clear messaging about which plugins are required for each feature.

#### Scenario: CLI help output includes requirements
- **WHEN** user runs `wp tec-data-generator generate --help`
- **THEN** help text includes: "Recurring events require Events Pro or ECP. Virtual events require ECP. Paid tickets require Event Tickets Plus."

#### Scenario: Admin page shows plugin requirement hints
- **WHEN** user hovers over or views disabled feature checkboxes
- **THEN** tooltips or help text clearly state which plugin(s) are required

### Requirement: Graceful Degradation
If a requested feature's plugin becomes unavailable during a chunked generation run, the system SHALL gracefully skip that feature (not crash) and log a warning.

#### Scenario: Plugin deactivated mid-run
- **WHEN** user starts background scenario generation, and Events Pro is deactivated before all chunks complete
- **THEN** generator logs warning for each chunk attempting recurring events, skips those events, and completes other event types successfully

#### Scenario: All chunks complete despite missing plugin
- **WHEN** recurring event type is unavailable mid-run
- **THEN** generator completes run, reporting events created without recurrence and a warning in logs/UI: "Recurring events were skipped due to missing Events Pro/ECP plugin"
